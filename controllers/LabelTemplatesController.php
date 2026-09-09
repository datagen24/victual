<?php

namespace Victual\Controllers;

use Victual\Controllers\Users\User;
use Victual\Services\DatabaseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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

	public function TemplateEditor(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_ADMIN);

		$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$statement = $db->prepare('SELECT * FROM label_templates WHERE id = ?');
		$statement->execute([(int)$args['templateId']]);
		$template = $statement->fetch(\PDO::FETCH_ASSOC);

		if (!$template)
		{
			return $this->GenericErrorResponse($response, 'Label template not found', 404);
		}

		// The printers are here so a preview can name one: a preview renders against a real
		// media profile, which is derived from a printer's resolved combination. A design
		// previewed against nothing would be a picture of a label rather than of this label.
		$printers = $db->query('SELECT id, name, is_default FROM label_printers WHERE active = 1 ORDER BY is_default DESC, name')->fetchAll(\PDO::FETCH_ASSOC);
		$assets = $db->query("SELECT id, name, asset_kind, font_family FROM label_assets ORDER BY asset_kind, name")->fetchAll(\PDO::FETCH_ASSOC);

		return $this->RenderPage($response, 'labeltemplateeditor', [
			'template' => $template,
			'printers' => $printers,
			'assets' => $assets
		]);
	}
}
