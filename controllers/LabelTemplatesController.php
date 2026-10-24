<?php

namespace Victual\Controllers;

use Victual\Controllers\Users\User;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelValidationException;
use Victual\Services\Labels\PrinterConfigurationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * The label designer's two pages.
 *
 * `ADMIN`, per the maintainer's answer to plan 27 question 2: templates and printers are
 * administration and printing is not.
 *
 * These render the shell and hand the document to the browser; every write goes back through
 * the API, which validates it. The editor is deliberately not trusted to have produced a
 * valid document - it can only produce elements it knows how to draw, and what "valid" means
 * is `TemplateDocument`'s, not Fabric's.
 */
class LabelTemplatesController extends BaseController
{
	/** The template list, each row with the version that prints and how many versions exist. */
	public function TemplatesList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_ADMIN);

		$db = DatabaseService::GetInstance()->GetDbConnectionRaw();

		return $this->RenderPage($response, 'labeltemplates', [
			'templates' => $db->query('SELECT t.*, v.version AS default_version,
				(SELECT COUNT(*) FROM label_template_versions x WHERE x.template_id = t.id) AS version_count
				FROM label_templates t
				LEFT JOIN label_template_versions v ON v.id = t.default_version_id
				ORDER BY t.name')->fetchAll(\PDO::FETCH_ASSOC)
		]);
	}

	/** The designer for one template, with the printers, assets and versions it offers; 404 for an unknown id. */
	public function TemplateEditor(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_ADMIN);

		$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$statement = $db->prepare('SELECT * FROM label_templates WHERE id = ?');
		$statement->execute([(int)$args['templateId']]);
		$template = $statement->fetch(\PDO::FETCH_ASSOC);

		if (!$template)
		{
			throw new HttpNotFoundException($request);
		}

		// The printers are here so a preview can name one: a preview renders against a real
		// media profile, which is derived from a printer's resolved combination. A design
		// previewed against nothing would be a picture of a label rather than of this label.
		$printers = $db->query('SELECT id, name, is_default FROM label_printers WHERE active = 1 ORDER BY is_default DESC, name')->fetchAll(\PDO::FETCH_ASSOC);

		// Issue #1034: centring uses the printable width across the roll of the printer the
		// preview names, because the canvas width is typed by hand and can exceed it (a 58.9 mm
		// canvas on 62red, which prints 58.42 mm). The width is the resolved combination's
		// `printable_width_um`, which MediaProfileService::Document() copies into the profile
		// unchanged. Resolve() only reads; ResolvePrinter() would derive and store a profile,
		// and a page view writes nothing. A printer that does not resolve gets null, and the
		// designer centres on the canvas.
		$configuration = new PrinterConfigurationService($db);
		foreach ($printers as &$printer)
		{
			try
			{
				$printer['printable_width_um'] = (int)$configuration->Resolve((int)$printer['id'])['combination']['printable_width_um'];
			}
			catch (LabelValidationException)
			{
				$printer['printable_width_um'] = null;
			}
		}
		unset($printer);
		$assets = $db->query("SELECT id, name, asset_kind, font_family FROM label_assets ORDER BY asset_kind, name")->fetchAll(\PDO::FETCH_ASSOC);

		// The published versions, newest first, so the designer can say which one prints: a
		// plain print and a live preview use the default, which publishing does not always move.
		$statement = $db->prepare('SELECT id, version FROM label_template_versions WHERE template_id = ? ORDER BY version DESC');
		$statement->execute([(int)$template['id']]);
		$versions = array_map(static fn (array $row) => ['id' => (int)$row['id'], 'version' => (int)$row['version']], $statement->fetchAll(\PDO::FETCH_ASSOC));

		return $this->RenderPage($response, 'labeltemplateeditor', [
			'template' => $template,
			'printers' => $printers,
			'assets' => $assets,
			'versions' => $versions
		]);
	}
}
