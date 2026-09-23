<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use Victual\Helpers\CanonicalJson;
use Victual\Services\Labels\ArtifactService;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\IndexedPng;
use Victual\Services\Labels\LabelAssetService;
use Victual\Services\Labels\LabelByteStore;
use Victual\Services\Labels\LabelCaptureService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\LabelValidationException;
use Victual\Services\Labels\LabelWorkerAuthorization;
use Victual\Services\Labels\LabelWorkerCredentialService;
use Victual\Services\Labels\MediaProfileService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Services\Labels\PrinterStatusService;
use Victual\Services\Labels\PrintEvidenceService;
use Victual\Services\Labels\QrMatrix;
use Victual\Services\Labels\RenderRequestService;
use Victual\Services\Labels\SettingsSchemaValidator;
use Victual\Services\Labels\TemplateDocument;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\SfntFixture;

/**
 * The label services the artifact, identity and worker-API phases in `.devtools/labels/`
 * leave uncovered: the document format's refusals, stored assets, render request
 * composition, print evidence, worker authorization and printer status.
 *
 * Those scripts own agreement with the real renderer and the happy path through issuance;
 * nothing here restates them. What is here is the other half - what each service refuses,
 * and what it leaves behind in the database when it does not.
 */
class LabelServicesTest extends PgsqlSchemaTestCase
{
	private const LABEL_UID = '0ABCDEFGH1234';

	private static PDO $db;
	private static int $worker;
	private static int $printer;
	/** @var array<string, mixed> The immutable media profile row every render resolves against. */
	private static array $profile;
	private static int $fontAssetId;
	private static string $fontAssetName = 'Fixture Sans';
	private static int $qrTemplate;
	private static int $qrTemplateVersion;
	private static int $textTemplate;
	private static int $textTemplateVersion;
	private static int $edgeTemplateVersion;
	private static int $captureId;
	private static int $sampleCaptureId;
	private static int $monoPrinter;
	private static int $redTemplate;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'labelservices-caller', 'fixture')");
		self::$db->exec("INSERT INTO locations(id, name, description) VALUES (9001, 'Pantry', 'The tall one')");

		self::$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('labelservices-worker', 'declared') RETURNING id")->fetchColumn();
		self::tx(static fn () => (new DriverRegistryService(self::$db))->Register(self::$worker, [self::driverDefinition()]));
		self::$printer = self::tx(static fn () => (new PrinterConfigurationService(self::$db))->Save(self::printerDefinition()));

		$resolved = self::tx(static fn () => (new LabelOperationsService(self::$db))->ResolvePrinter(self::$printer));
		self::$profile = $resolved['profile'];

		// Text pins a font asset by name and there is no substitution, so the text template
		// below cannot be published until real font bytes are stored.
		$font = self::tx(static fn () => (new LabelAssetService(self::$db))->Store(
			self::$fontAssetName, 'font', 'font/ttf', self::sfnt(['Fixture Sans', 'Bold']), 'OFL-1.1', 'Fixture font'));
		self::$fontAssetId = (int)$font['id'];

		[self::$qrTemplate, self::$qrTemplateVersion] = self::publishTemplate('Fixture QR label', self::qrOnlyDocument());
		[self::$textTemplate, self::$textTemplateVersion] = self::publishTemplate('Fixture text label', self::textDocument());
		// A QR pushed hard against the right edge, for the artifact the verifier finds runs
		// off the raster.
		[, self::$edgeTemplateVersion] = self::publishTemplate('Fixture edge label', self::withQr(['x_mm' => 50.0]));

		// A second printer resolving to monochrome media, so "this template needs two
		// colours and this printer has one" is a real configuration rather than a mock.
		self::tx(static fn () => (new DriverRegistryService(self::$db))
			->Register(self::$worker, [self::driverDefinition(), self::monoDriverDefinition()]));
		self::$monoPrinter = self::tx(static fn () => (new PrinterConfigurationService(self::$db))->Save(self::monoPrinterDefinition()));

		$red = self::qrOnlyDocument();
		$red['elements'][] = array_merge(self::rectElement(), ['fill' => 'red']);
		[self::$redTemplate] = self::publishTemplate('Fixture red label', $red);

		self::$captureId = (int)self::tx(static fn () => (new LabelCaptureService(self::$db))
			->Capture('location', 9001, self::LABEL_UID, ['location.name'], 'en', 'UTC', 9000))['id'];
		self::$sampleCaptureId = (int)self::tx(static fn () => (new LabelCaptureService(self::$db))
			->CaptureSample('location', ['location.name'], 'en', 'UTC', 9000))['id'];
	}

	// --- Fixture helpers -------------------------------------------------------------------

	/** Runs $work in the caller-owned transaction every label service requires. */
	private static function tx(callable $work): mixed
	{
		self::$db->beginTransaction();
		try
		{
			$result = $work();
			self::$db->commit();
			return $result;
		}
		catch (\Throwable $error)
		{
			if (self::$db->inTransaction())
			{
				self::$db->rollBack();
			}
			throw $error;
		}
	}

	/** Asserts that $work refuses, naming both the property at fault and the reason. */
	private static function assertRefused(callable $work, string $field, string $code, string $why): void
	{
		try
		{
			$work();
		}
		catch (LabelValidationException $error)
		{
			self::assertSame($code, $error->errorCode, $why . ': wrong refusal code (' . $error->getMessage() . ')');
			self::assertSame($field, $error->field, $why . ': refusal names the wrong property (' . $error->getMessage() . ')');
			return;
		}
		self::fail($why . ': expected a refusal ' . $code . ' on ' . $field . ', nothing was refused');
	}

	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private static function printerDefinition(): array
	{
		return ['name' => 'Fixture printer', 'worker_id' => self::$worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc',
			'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red']];
	}

	/** The same driver over media that can only print black, for the two-colour refusal. */
	private static function monoDriverDefinition(): array
	{
		$definition = self::driverDefinition();
		$definition['driver_id'] = 'fixture.mono';
		$definition['settings_schemas'][0]['when'] = ['model' => 'QL-820NWBc', 'media' => '62mono'];
		$definition['settings_schemas'][0]['schema']['properties']['media']['enum'] = ['62mono'];
		$definition['settings_schemas'][0]['schema']['properties']['color_mode']['enum'] = ['monochrome'];
		$definition['capability_document']['combinations'][0]['media'] = '62mono';
		$definition['capability_document']['combinations'][0]['color_mode'] = 'monochrome';
		return $definition;
	}

	private static function monoPrinterDefinition(): array
	{
		return array_merge(self::printerDefinition(), ['name' => 'Fixture monochrome printer',
			'driver_id' => 'fixture.mono',
			'settings' => ['media' => '62mono', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'monochrome']]);
	}

	/** @return array{0: int, 1: int} the template id and the id of its first published version */
	private static function publishTemplate(string $name, array $document): array
	{
		$templates = new LabelTemplateService(self::$db);
		$template = (int)self::tx(static fn () => $templates->Create($name, null, 'location', 9000))['id'];
		$draft = $templates->GetDraft($template);
		self::tx(static fn () => $templates->SaveDraft($template, $document, $draft['revision_token'], 9000));
		return [$template, (int)self::tx(static fn () => $templates->Publish($template, 9000))['id']];
	}

	// --- Documents -------------------------------------------------------------------------

	private static function canvas(): array
	{
		return ['width_mm' => 58.9, 'height_mm' => 30.0, 'max_height_mm' => null,
			'margins_mm' => ['top' => 2.0, 'right' => 2.0, 'bottom' => 2.0, 'left' => 2.0]];
	}

	private static function qrElement(): array
	{
		return ['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
			'ec_level' => 'M', 'quiet_zone_modules' => 4, 'source' => 'label.payload', 'color' => 'black'];
	}

	private static function textElement(): array
	{
		return ['type' => 'text', 'id' => 'title', 'x_mm' => 2.0, 'y_mm' => 22.0, 'width_mm' => 40.0,
			'height_mm' => 6.0, 'field' => 'location.name', 'font_asset' => 'Fixture Sans', 'size_pt' => 10.0];
	}

	private static function qrOnlyDocument(): array
	{
		return ['schema_version' => 1, 'entity_kind' => 'location', 'canvas' => self::canvas(),
			'elements' => [self::qrElement()]];
	}

	private static function textDocument(): array
	{
		return ['schema_version' => 1, 'entity_kind' => 'location', 'canvas' => self::canvas(),
			'elements' => [self::qrElement(), self::textElement()]];
	}

	/** The QR-only document with one element property replaced, for the refusal table. */
	private static function withQr(array $overrides): array
	{
		$document = self::qrOnlyDocument();
		$document['elements'][0] = array_merge($document['elements'][0], $overrides);
		return $document;
	}

	/** The document with a second element of the caller's making appended. */
	private static function withElement(array $element): array
	{
		$document = self::qrOnlyDocument();
		$document['elements'][] = $element;
		return $document;
	}

	private static function withText(array $overrides): array
	{
		return self::withElement(array_merge(self::textElement(), $overrides));
	}

	private static function withCanvas(array $overrides): array
	{
		$document = self::qrOnlyDocument();
		$document['canvas'] = array_merge($document['canvas'], $overrides);
		return $document;
	}

	// --- Byte fixtures ---------------------------------------------------------------------

	/**
	 * A minimal but structurally conformant sfnt carrying only a `name` table.
	 *
	 * Built to the OpenType specification rather than to what the decoder happens to read,
	 * so a decoder that stopped reading real fonts would fail here too.
	 *
	 * @param array<int, string> $names nameID 1 (family) then optionally nameID 2 (subfamily)
	 */
	private static function sfnt(array $names, string $signature = "\x00\x01\x00\x00", int $platform = 3, ?int $tableOffsetOverride = null, int $declaredTables = 1): string
	{
		$records = '';
		$storage = '';
		foreach ($names as $index => $value)
		{
			$encoded = $platform === 3 ? mb_convert_encoding($value, 'UTF-16BE', 'UTF-8') : $value;
			$records .= pack('nnnnnn', $platform, $platform === 3 ? 1 : 0, 0, $index + 1, strlen($encoded), strlen($storage));
			$storage .= $encoded;
		}
		$stringOffset = 6 + count($names) * 12;
		$table = pack('nnn', 0, count($names), $stringOffset) . $records . $storage;

		$directoryOffset = 12 + 16;
		$entry = 'name' . pack('N', 0) . pack('N', $tableOffsetOverride ?? $directoryOffset) . pack('N', strlen($table));
		return $signature . pack('nnnn', $declaredTables, 16, 0, 0) . $entry . $table;
	}

	/** An sfnt whose only table is not `name`, so there is nothing to read a family from. */
	private static function sfntWithoutNameTable(): string
	{
		$entry = 'cmap' . pack('N', 0) . pack('N', 28) . pack('N', 4);
		return "\x00\x01\x00\x00" . pack('nnnn', 1, 16, 0, 0) . $entry . 'dead';
	}

	private static function pngChunk(string $type, string $data): string
	{
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	/**
	 * A colour-type-3 PNG at a chosen bit depth over a chosen palette.
	 *
	 * @param array<int, array<int, int>> $pixels Palette indices, row-major
	 * @param array<int, string> $palette `#rrggbb` entries in PLTE order
	 */
	private static function indexedPng(array $pixels, int $width, int $height, array $palette, int $bitDepth = 2): string
	{
		$plte = '';
		foreach ($palette as $colour)
		{
			$plte .= hex2bin(substr($colour, 1));
		}
		$perByte = intdiv(8, $bitDepth);
		$raw = '';
		foreach ($pixels as $row)
		{
			$line = '';
			$accumulator = 0;
			$filled = 0;
			foreach ($row as $index)
			{
				$accumulator = ($accumulator << $bitDepth) | ($index & ((1 << $bitDepth) - 1));
				$filled++;
				if ($filled === $perByte)
				{
					$line .= chr($accumulator);
					$accumulator = 0;
					$filled = 0;
				}
			}
			if ($filled > 0)
			{
				$line .= chr($accumulator << ($bitDepth * ($perByte - $filled)));
			}
			$raw .= chr(0) . $line;
		}
		return "\x89PNG\r\n\x1a\n"
			. self::pngChunk('IHDR', pack('NN', $width, $height) . chr($bitDepth) . chr(3) . chr(0) . chr(0) . chr(0))
			. self::pngChunk('PLTE', $plte)
			. self::pngChunk('IDAT', gzcompress($raw, 9))
			. self::pngChunk('IEND', '');
	}

	/** A blank artifact-sized canvas on a profile's palette, ready to have a QR painted on it. */
	private static function blankPixels(array $profile, int $height): array
	{
		$white = array_search('#ffffff', $profile['pixel_policy']['palette'], true);
		return array_fill(0, $height, array_fill(0, (int)$profile['raster_width_px'], $white));
	}

	// --- TemplateDocument: the refusals ----------------------------------------------------

	private static function imageElement(): array
	{
		return ['type' => 'image', 'id' => 'logo', 'x_mm' => 2.0, 'y_mm' => 10.0, 'width_mm' => 10.0,
			'height_mm' => 10.0, 'asset' => 'Fixture Mark', 'fit' => 'contain'];
	}

	private static function lineElement(): array
	{
		return ['type' => 'line', 'id' => 'rule', 'x1_mm' => 2.0, 'y1_mm' => 18.0, 'x2_mm' => 50.0,
			'y2_mm' => 18.0, 'stroke_mm' => 0.3];
	}

	private static function rectElement(): array
	{
		return ['type' => 'rect', 'id' => 'box', 'x_mm' => 2.0, 'y_mm' => 10.0, 'width_mm' => 20.0,
			'height_mm' => 6.0, 'stroke_mm' => 0.4, 'fill' => null];
	}

	/**
	 * Every refusal `TemplateDocument` can produce, one document each.
	 *
	 * A designer acts on the property named and the reason given, so both are asserted: a
	 * validator that refused everything with one generic code would fail this table, and
	 * testValidDocumentsOfEveryStructuralVariant() is the control that says it does not
	 * simply refuse everything.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string}>
	 */
	public static function malformedDocuments(): array
	{
		$manyElements = self::qrOnlyDocument();
		for ($index = 1; $index <= 64; $index++)
		{
			$manyElements['elements'][] = ['type' => 'rect', 'id' => 'r' . $index, 'x_mm' => 0.0, 'y_mm' => 0.0,
				'width_mm' => 1.0, 'height_mm' => 1.0, 'stroke_mm' => 0.2];
		}
		$notAnObject = self::qrOnlyDocument();
		$notAnObject['elements'][] = 'a rectangle, honest';
		$duplicate = self::qrOnlyDocument();
		$duplicate['elements'][] = self::qrElement();
		$keyed = self::qrOnlyDocument();
		$keyed['elements'] = ['first' => self::qrElement()];

		return [
			// Document frame.
			'an unknown document property' => [array_merge(self::qrOnlyDocument(), ['nonsense' => 1]), 'document.nonsense', 'unknown_property'],
			'an unknown schema version' => [array_merge(self::qrOnlyDocument(), ['schema_version' => 99]), 'schema_version', 'unsupported_version'],
			'no schema version at all' => [array_diff_key(self::qrOnlyDocument(), ['schema_version' => null]), 'schema_version', 'unsupported_version'],
			'a document for another entity kind' => [array_merge(self::qrOnlyDocument(), ['entity_kind' => 'product']), 'entity_kind', 'entity_kind_mismatch'],

			// Canvas.
			'no canvas' => [array_merge(self::qrOnlyDocument(), ['canvas' => null]), 'canvas', 'invalid_value'],
			'an unknown canvas property' => [self::withCanvas(['bogus' => 1]), 'canvas.bogus', 'unknown_property'],
			'a canvas width that is not a number' => [self::withCanvas(['width_mm' => '58.9']), 'canvas.width_mm', 'invalid_value'],
			'an infinite canvas width' => [self::withCanvas(['width_mm' => INF]), 'canvas.width_mm', 'invalid_value'],
			'a canvas width of zero' => [self::withCanvas(['width_mm' => 0.0]), 'canvas.width_mm', 'value_out_of_range'],
			'a canvas wider than the bound' => [self::withCanvas(['width_mm' => 3000.0]), 'canvas.width_mm', 'value_out_of_range'],
			'automatic height with no bound' => [self::withCanvas(['height_mm' => null, 'max_height_mm' => null]), 'canvas.max_height_mm', 'unbounded_automatic_height'],
			'a maximum height below the fixed height' => [self::withCanvas(['height_mm' => 30.0, 'max_height_mm' => 20.0]), 'canvas.max_height_mm', 'value_out_of_range'],
			'margins that are not an object' => [self::withCanvas(['margins_mm' => 4.0]), 'canvas.margins_mm', 'invalid_value'],
			'an unknown margin side' => [self::withCanvas(['margins_mm' => ['top' => 1.0, 'middle' => 1.0]]), 'canvas.margins_mm.middle', 'unknown_property'],
			'a negative margin' => [self::withCanvas(['margins_mm' => ['top' => -1.0]]), 'canvas.margins_mm.top', 'value_out_of_range'],
			'margins that leave no printable width' => [self::withCanvas(['margins_mm' => ['left' => 30.0, 'right' => 30.0]]), 'canvas.margins_mm', 'value_out_of_range'],

			// The element list.
			'no elements' => [array_merge(self::qrOnlyDocument(), ['elements' => []]), 'elements', 'invalid_value'],
			'elements keyed rather than listed' => [$keyed, 'elements', 'invalid_value'],
			'more elements than the bound' => [$manyElements, 'elements', 'value_out_of_range'],
			'an element that is not an object' => [$notAnObject, 'elements[1]', 'invalid_value'],
			'an unknown element type' => [self::withElement(['type' => 'barcode', 'id' => 'b']), 'elements[1].type', 'unsupported_element'],
			'an element id with a capital letter' => [self::withElement(array_merge(self::qrElement(), ['id' => 'Code'])), 'elements[1].id', 'invalid_value'],
			'a duplicate element id' => [$duplicate, 'elements[1].id', 'duplicate_element_id'],

			// Physical dimensions, wherever they appear.
			'a position that is not finite' => [self::withQr(['x_mm' => NAN]), 'elements.code.x_mm', 'invalid_value'],
			'a negative position' => [self::withQr(['x_mm' => -1.0]), 'elements.code.x_mm', 'value_out_of_range'],
			'a size of zero' => [self::withText(['width_mm' => 0.0]), 'elements.title.width_mm', 'value_out_of_range'],
			'an element extending past the canvas' => [self::withText(['id' => 'wide', 'x_mm' => 50.0, 'width_mm' => 20.0]), 'elements.wide.width_mm', 'element_outside_canvas'],

			// Text.
			'an unknown text property' => [self::withText(['glow' => true]), 'elements.title.glow', 'unknown_property'],
			'a text element carrying both a field and a literal' => [self::withText(['literal' => 'Pantry']), 'elements.title.field', 'invalid_value'],
			'a text element carrying neither' => [self::withText(['field' => null]), 'elements.title.field', 'invalid_value'],
			'a field outside the catalogue' => [self::withText(['field' => 'location.invented']), 'elements.title.field', 'unknown_field'],
			'an empty literal' => [self::withText(['field' => null, 'literal' => '']), 'elements.title.literal', 'value_out_of_range'],
			'a literal past the length bound' => [self::withText(['field' => null, 'literal' => str_repeat('x', 513)]), 'elements.title.literal', 'value_out_of_range'],
			'text pinning no font asset' => [self::withText(['font_asset' => '']), 'elements.title.font_asset', 'invalid_value'],
			'a type size that is not a number' => [self::withText(['size_pt' => '10']), 'elements.title.size_pt', 'invalid_value'],
			'a type size below the bound' => [self::withText(['size_pt' => 2.0]), 'elements.title.size_pt', 'value_out_of_range'],
			'a type size above the bound' => [self::withText(['size_pt' => 300.0]), 'elements.title.size_pt', 'value_out_of_range'],
			'an unknown overflow rule' => [self::withText(['overflow' => 'clip']), 'elements.title.overflow', 'invalid_value'],
			'shrink_to_fit with no floor' => [self::withText(['overflow' => 'shrink_to_fit']), 'elements.title.min_size_pt', 'value_out_of_range'],
			'shrink_to_fit with a floor above the size' => [self::withText(['overflow' => 'shrink_to_fit', 'min_size_pt' => 12.0]), 'elements.title.min_size_pt', 'value_out_of_range'],
			'a floor without shrink_to_fit' => [self::withText(['min_size_pt' => 6.0]), 'elements.title.min_size_pt', 'invalid_value'],
			'an unknown alignment' => [self::withText(['align' => 'justify']), 'elements.title.align', 'invalid_value'],
			'an unknown vertical alignment' => [self::withText(['valign' => 'centre']), 'elements.title.valign', 'invalid_value'],
			'a wrap flag that is not a boolean' => [self::withText(['wrap' => 'yes']), 'elements.title.wrap', 'invalid_value'],
			'line spacing below the bound' => [self::withText(['line_spacing' => 0.25]), 'elements.title.line_spacing', 'value_out_of_range'],
			'line spacing above the bound' => [self::withText(['line_spacing' => 5.0]), 'elements.title.line_spacing', 'value_out_of_range'],
			'an unknown colour' => [self::withText(['color' => 'blue']), 'elements.title.color', 'invalid_value'],

			// QR.
			'an unknown QR property' => [self::withQr(['glow' => 1]), 'elements.code.glow', 'unknown_property'],
			'a QR pointed somewhere else' => [self::withQr(['source' => 'https://example.invalid']), 'elements.code.source', 'invalid_value'],
			'a QR module below the bound' => [self::withQr(['module_mm' => 0.1]), 'elements.code.module_mm', 'value_out_of_range'],
			'a QR module above the bound' => [self::withQr(['module_mm' => 6.0]), 'elements.code.module_mm', 'value_out_of_range'],
			'an unknown error-correction level' => [self::withQr(['ec_level' => 'X']), 'elements.code.ec_level', 'invalid_value'],
			'a quiet zone below what the symbology requires' => [self::withQr(['quiet_zone_modules' => 2]), 'elements.code.quiet_zone_modules', 'value_out_of_range'],
			'a quiet zone above the bound' => [self::withQr(['quiet_zone_modules' => 20]), 'elements.code.quiet_zone_modules', 'value_out_of_range'],

			// Image.
			'an unknown image property' => [self::withElement(array_merge(self::imageElement(), ['glow' => 1])), 'elements.logo.glow', 'unknown_property'],
			'an image naming no asset' => [self::withElement(array_merge(self::imageElement(), ['asset' => ''])), 'elements.logo.asset', 'invalid_value'],
			'an unknown image fit' => [self::withElement(array_merge(self::imageElement(), ['fit' => 'cover'])), 'elements.logo.fit', 'invalid_value'],

			// Line.
			'an unknown line property' => [self::withElement(array_merge(self::lineElement(), ['glow' => 1])), 'elements.rule.glow', 'unknown_property'],
			'a line with no stroke' => [self::withElement(array_merge(self::lineElement(), ['stroke_mm' => 0.0])), 'elements.rule.stroke_mm', 'value_out_of_range'],
			'a line stroke above the bound' => [self::withElement(array_merge(self::lineElement(), ['stroke_mm' => 25.0])), 'elements.rule.stroke_mm', 'value_out_of_range'],

			// Rect.
			'an unknown rectangle property' => [self::withElement(array_merge(self::rectElement(), ['glow' => 1])), 'elements.box.glow', 'unknown_property'],
			'a rectangle stroke above the bound' => [self::withElement(array_merge(self::rectElement(), ['stroke_mm' => 25.0])), 'elements.box.stroke_mm', 'value_out_of_range'],
			'an unknown rectangle fill' => [self::withElement(array_merge(self::rectElement(), ['fill' => 'blue'])), 'elements.box.fill', 'invalid_value'],
			'a rectangle that paints nothing' => [self::withElement(array_merge(self::rectElement(), ['stroke_mm' => 0.0, 'fill' => null])), 'elements.box.stroke_mm', 'invalid_value'],
		];
	}

	#[DataProvider('malformedDocuments')]
	public function testMalformedDocumentIsRefusedByName(array $document, string $field, string $code): void
	{
		self::assertRefused(static fn () => TemplateDocument::Validate($document, 'location'), $field, $code,
			'A malformed template document');
	}

	/**
	 * The control for the refusal table above: one valid document per structural variant.
	 *
	 * Defaults are asserted because a caller that omitted `align` is entitled to know what
	 * it gets, and because the validator's job is to resolve them rather than to leave the
	 * renderer guessing.
	 */
	public function testValidDocumentsOfEveryStructuralVariant(): void
	{
		$document = self::qrOnlyDocument();
		$document['elements'] = [self::qrElement(), self::textElement(), self::imageElement(),
			self::lineElement(), self::rectElement()];
		$result = TemplateDocument::Validate($document, 'location');

		self::assertSame(1, $result['document']['schema_version'], 'A valid document keeps the schema version it declared');
		self::assertSame('location', $result['document']['entity_kind'], 'A valid document keeps its entity kind');
		self::assertCount(5, $result['document']['elements'], 'All five v1 element types are admitted');
		self::assertSame(['location.name'], $result['fields'], 'The fields a capture must read are derived from the text elements');
		self::assertSame(['Fixture Sans', 'Fixture Mark'], $result['asset_ids'], 'The assets to resolve are the pinned font and the named image');
		self::assertSame(['color_mode' => 'monochrome'], $result['required_capabilities'], 'A document painting no red needs no two-colour media');

		$text = $result['document']['elements'][1];
		self::assertSame('left', $text['align'], 'Text aligns left unless it says otherwise');
		self::assertSame('top', $text['valign'], 'Text sits at the top of its box unless it says otherwise');
		self::assertTrue($text['wrap'], 'Text wraps unless it says otherwise');
		self::assertSame(1.2, $text['line_spacing'], 'Line spacing defaults to 1.2');
		self::assertSame('error', $text['overflow'], 'Overflow is an error unless the document chose otherwise');
		self::assertNull($text['min_size_pt'], 'There is no shrink floor without shrink_to_fit');
		self::assertSame('black', $text['color'], 'An element paints black unless it says otherwise');
		self::assertNull($text['literal'], 'A field-driven text element carries no literal');

		$qr = $result['document']['elements'][0];
		self::assertSame('label.payload', $qr['source'], 'A QR encodes the server-supplied payload');
		self::assertSame('contain', $result['document']['elements'][2]['fit'], 'An image is contained unless it says otherwise');
	}

	/** The omitted-optional case: no `ec_level`, no `quiet_zone_modules`, no `color`. */
	public function testQrDefaultsResolveWithoutBeingStated(): void
	{
		$element = self::qrElement();
		unset($element['ec_level'], $element['quiet_zone_modules'], $element['color'], $element['source']);
		$document = self::qrOnlyDocument();
		$document['elements'] = [$element];

		$resolved = TemplateDocument::Validate($document, 'location')['document']['elements'][0];
		self::assertSame('M', $resolved['ec_level'], 'Error correction defaults to M');
		self::assertSame(4, $resolved['quiet_zone_modules'], 'The quiet zone defaults to the four modules the symbology requires');
		self::assertSame('black', $resolved['color'], 'A QR is black unless it says otherwise');
		self::assertSame('label.payload', $resolved['source'], 'A QR with no stated source still encodes the label payload');
	}

	/**
	 * The capability is derived from what the document paints, never declared - so a
	 * template that paints red cannot be admitted against a monochrome printer by claiming
	 * to be monochrome.
	 */
	public function testRedAnywhereMakesTheDocumentNeedTwoColourMedia(): void
	{
		$byText = self::qrOnlyDocument();
		$byText['elements'] = [array_merge(self::textElement(), ['color' => 'red'])];
		self::assertSame(['color_mode' => 'black_red'], TemplateDocument::Validate($byText, 'location')['required_capabilities'],
			'Red text needs two-colour media');

		$byFill = self::qrOnlyDocument();
		$byFill['elements'] = [array_merge(self::rectElement(), ['fill' => 'red'])];
		self::assertSame(['color_mode' => 'black_red'], TemplateDocument::Validate($byFill, 'location')['required_capabilities'],
			'A red fill needs two-colour media, even though the stroke is black');

		self::assertSame(['color_mode' => 'monochrome'], TemplateDocument::Validate(self::qrOnlyDocument(), 'location')['required_capabilities'],
			'Negative control: a document with no red does not ask for two-colour media');
	}

	/** The boundaries either side of the element bound, and the automatic-height variant. */
	public function testDocumentBoundaries(): void
	{
		$atTheBound = self::qrOnlyDocument();
		for ($index = 1; $index < 64; $index++)
		{
			$atTheBound['elements'][] = ['type' => 'rect', 'id' => 'r' . $index, 'x_mm' => 0.0, 'y_mm' => 0.0,
				'width_mm' => 1.0, 'height_mm' => 1.0, 'stroke_mm' => 0.2];
		}
		self::assertCount(64, TemplateDocument::Validate($atTheBound, 'location')['document']['elements'],
			'Exactly the maximum number of elements is admitted');

		$automatic = self::withCanvas(['height_mm' => null, 'max_height_mm' => 100.0]);
		$canvas = TemplateDocument::Validate($automatic, 'location')['document']['canvas'];
		self::assertNull($canvas['height_mm'], 'A canvas may leave its height automatic');
		self::assertSame(100.0, $canvas['max_height_mm'], 'An automatic height states how far it may grow');
		self::assertSame(['top' => 2.0, 'right' => 2.0, 'bottom' => 2.0, 'left' => 2.0], $canvas['margins_mm'],
			'The stated margins survive validation');

		$noMargins = self::qrOnlyDocument();
		unset($noMargins['canvas']['margins_mm']);
		self::assertSame(['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
			TemplateDocument::Validate($noMargins, 'location')['document']['canvas']['margins_mm'],
			'A canvas that states no margins has none');

		$literal = self::qrOnlyDocument();
		$literal['elements'] = [array_merge(self::textElement(), ['field' => null, 'literal' => str_repeat('x', 512),
			'overflow' => 'shrink_to_fit', 'min_size_pt' => 10.0])];
		$resolved = TemplateDocument::Validate($literal, 'location')['document']['elements'][0];
		self::assertSame(512, mb_strlen($resolved['literal']), 'A literal of exactly the maximum length is admitted');
		self::assertSame(10.0, $resolved['min_size_pt'], 'A shrink floor equal to the size is admitted');
		self::assertSame([], TemplateDocument::Validate($literal, 'location')['fields'],
			'Negative control: a literal-only document asks a capture to read nothing');
	}

	// --- LabelAssetService -----------------------------------------------------------------

	/** A palette PNG small enough to read back byte for byte. */
	private static function markBytes(): string
	{
		return self::indexedPng([[0, 1, 0, 1], [1, 0, 1, 0], [0, 0, 1, 1]], 4, 3, ['#ffffff', '#000000']);
	}

	/** A PNG header declaring dimensions no decoder should be asked to allocate. */
	private static function oversizedPng(int $width, int $height): string
	{
		return "\x89PNG\r\n\x1a\n"
			. self::pngChunk('IHDR', pack('NN', $width, $height) . chr(2) . chr(3) . chr(0) . chr(0) . chr(0))
			. self::pngChunk('PLTE', hex2bin('ffffff000000'))
			. self::pngChunk('IDAT', gzcompress(chr(0), 9))
			. self::pngChunk('IEND', '');
	}

	private static function gifBytes(): string
	{
		$image = imagecreatetruecolor(4, 3);
		ob_start();
		imagegif($image);
		return (string)ob_get_clean();
	}

	private static function assetRowCount(): int
	{
		return (int)self::$db->query('SELECT COUNT(*) FROM label_assets')->fetchColumn();
	}

	private static function assetFileCount(): int
	{
		return (int)self::$db->query("SELECT COUNT(*) FROM files WHERE file_group = 'labelassets'")->fetchColumn();
	}

	/**
	 * Storing an image, and reading back exactly what went in.
	 *
	 * The durable row is asserted as well as the return value: the decoded dimensions are
	 * recorded because they were read out of the bytes, so a row that merely repeated the
	 * upload's claim would be the defect this service exists to prevent.
	 */
	public function testImageAssetRoundTrip(): void
	{
		$bytes = self::markBytes();
		$stored = self::tx(static fn () => (new LabelAssetService(self::$db))
			->Store('Fixture Mark', 'image', 'image/png', $bytes, 'CC0-1.0', 'Public domain'));

		self::assertSame('image', $stored['asset_kind'], 'An image is stored as an image');
		self::assertSame(4, (int)$stored['width_px'], 'The width is the decoded width');
		self::assertSame(3, (int)$stored['height_px'], 'The height is the decoded height');
		self::assertSame(strlen($bytes), (int)$stored['byte_length'], 'The recorded length is the length of the bytes that arrived');
		self::assertSame(hash('sha256', $bytes), $stored['content_digest'], 'The digest is over the exact bytes');
		self::assertNull($stored['font_family'], 'An image has no font family');

		$row = self::$db->query('SELECT * FROM label_assets WHERE id = ' . (int)$stored['id'])->fetch(PDO::FETCH_ASSOC);
		self::assertSame('labelassets', $row['file_group'], 'The bytes live under the label asset group');
		// Spelled out rather than compared against NameFor(): an expectation the code under
		// test computes for itself would accept a naming scheme that had begun to leak the
		// household's own words into the file store.
		self::assertStringContainsString($stored['content_digest'], $row['file_name'], 'The storage name carries the digest');
		self::assertSame('asset-.png', str_replace($stored['content_digest'], '', $row['file_name']),
			'and beside the digest only the kind of bytes it is and the extension');

		foreach (['Fixture Mark', 'CC0-1.0', 'Public domain'] as $supplied)
		{
			self::assertStringNotContainsStringIgnoringCase($supplied, $row['file_name'],
				"The storage name repeats nothing the household supplied: $supplied");
		}

		self::assertSame('CC0-1.0', $row['licence'], 'The licence the asset is used under is recorded beside the bytes');

		self::assertSame($bytes, (new LabelAssetService(self::$db))->Bytes((int)$stored['id']),
			'The stored bytes come back byte for byte');
	}

	/**
	 * The font decode: the family and subfamily a template pins come out of the sfnt `name`
	 * table, not out of the upload's filename or its declared type.
	 */
	public function testFontFamilyIsDecodedFromTheNameTable(): void
	{
		$windows = self::tx(static fn () => (new LabelAssetService(self::$db))
			->Store('Windows Named', 'font', 'font/otf', self::sfnt(['Victual Display', 'Semibold'], 'OTTO'), 'OFL-1.1', null));
		self::assertSame('Victual Display', $windows['font_family'], 'The family is the UTF-16BE nameID 1 record');
		self::assertSame('Semibold', $windows['font_style'], 'The style is the nameID 2 record');
		self::assertSame('font/otf', $windows['mime_type'], 'An OpenType outline font keeps its own media type');
		self::assertStringEndsWith('.otf', $windows['file_name'], 'The stored file takes the extension its media type names');

		$mac = self::tx(static fn () => (new LabelAssetService(self::$db))
			->Store('Mac Named', 'font', 'font/sfnt', self::sfnt(['Victual Mono'], 'true', 1), 'OFL-1.1', null));
		self::assertSame('Victual Mono', $mac['font_family'], 'A MacRoman name record is read as it stands');
		self::assertSame('Regular', $mac['font_style'], 'A font naming no subfamily is Regular');
		self::assertStringEndsWith('.ttf', $mac['file_name'], 'font/sfnt is stored as a TrueType file');

		// A font shaped the way a shipped one is: five tables in tag order with `name` fourth,
		// padded and checksummed, and all of nameIDs 0 to 6 recorded on both platforms. The
		// fixtures above carry a single table holding nothing but the two names being read, so
		// neither walking the directory nor passing over the other eleven records is asked of
		// the decoder anywhere else.
		$shipped = SfntFixture::Shipped('Victual Shipped Sans', 'Book');
		$stored = self::tx(static fn () => (new LabelAssetService(self::$db))
			->Store('Shipped Shaped Font', 'font', 'font/ttf', $shipped, 'OFL-1.1', 'Built by tests/Support/SfntFixture.php'));
		self::assertSame('Victual Shipped Sans', $stored['font_family'],
			'The family is found in a directory where `name` is not the first table, and is nameID 1 rather than the copyright or the full name');
		self::assertSame('Book', $stored['font_style'], 'The subfamily is nameID 2 of that same table');
		self::assertSame(strlen($shipped), (int)$stored['byte_length'], 'The whole font is stored');
		self::assertSame($shipped, (new LabelAssetService(self::$db))->Bytes((int)$stored['id']),
			'A font reads back byte for byte, which is what pinning by content means');
	}

	/**
	 * Identical bytes are one asset, whatever the second upload called them - otherwise two
	 * template versions would pin different asset ids for the same glyphs.
	 */
	public function testIdenticalBytesAreOneAsset(): void
	{
		$bytes = self::sfnt(['Victual Duplicate']);
		$first = self::tx(static fn () => (new LabelAssetService(self::$db))
			->Store('Duplicate One', 'font', 'font/ttf', $bytes, 'OFL-1.1', null));

		$rowsBefore = self::assertRowsUnchanged(static fn () => (new LabelAssetService(self::$db))
			->Store('Duplicate Two', 'font', 'font/ttf', $bytes, 'OFL-1.1', null));

		self::assertSame((int)$first['id'], (int)$rowsBefore['id'], 'The same bytes return the asset already stored');
		self::assertSame('Duplicate One', $rowsBefore['name'], 'The stored name is the one the first upload gave');
	}

	/** Runs a store that must add nothing, and returns its result. */
	private static function assertRowsUnchanged(callable $work): array
	{
		$assets = self::assetRowCount();
		$files = self::assetFileCount();
		$result = self::tx($work);
		self::assertSame($assets, self::assetRowCount(), 'No second asset row was written');
		self::assertSame($files, self::assetFileCount(), 'No second copy of the bytes was written');
		return $result;
	}

	public function testBytesOfAnAssetThatDoesNotExistIsNull(): void
	{
		self::assertNull((new LabelAssetService(self::$db))->Bytes(987654),
			'Asking for the bytes of an asset that does not exist answers nothing rather than guessing');
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 *         name, kind, declared mime, bytes, refused field, refusal code
	 */
	public static function rejectedAssets(): array
	{
		return [
			'a name with characters a filename cannot carry' => ['bad/name', 'font', 'font/ttf', 'ignored', 'name', 'invalid_value'],
			'an empty name' => ['', 'font', 'font/ttf', 'ignored', 'name', 'invalid_value'],
			'no recorded licence' => ['Unlicensed', 'font', 'font/ttf', 'ignored', 'licence', 'invalid_value'],
			'no bytes at all' => ['Empty Upload', 'font', 'font/ttf', '', 'content', 'value_out_of_range'],
			'a kind that is neither a font nor an image' => ['Sticker', 'sticker', 'image/png', 'some bytes', 'asset_kind', 'invalid_value'],

			'an image media type v1 does not accept' => ['Jpeg Upload', 'image', 'image/jpeg', 'some bytes', 'mime_type', 'invalid_value'],
			'an SVG, which is a program rather than a picture' => ['Svg Upload', 'image', 'image/svg+xml', '<svg/>', 'mime_type', 'invalid_value'],
			'a declared PNG that decodes as nothing' => ['Not An Image', 'image', 'image/png', 'PNG-ish but not a PNG', 'content', 'invalid_value'],

			'a font media type v1 does not accept' => ['Woff Upload', 'font', 'font/woff2', 'some font bytes', 'mime_type', 'invalid_value'],
			'an upload too short to be a font' => ['Stub Font', 'font', 'font/ttf', 'short', 'content', 'invalid_value'],
			'a font collection rather than a font' => ['Collection', 'font', 'font/ttf', 'ttcf' . str_repeat("\x00", 40), 'content', 'invalid_value'],
		];
	}

	#[DataProvider('rejectedAssets')]
	public function testRejectedAssetStoresNothing(string $name, string $kind, string $mime, string $bytes, string $field, string $code): void
	{
		$assets = self::assetRowCount();
		$files = self::assetFileCount();

		self::assertRefused(
			static fn () => self::tx(static fn () => (new LabelAssetService(self::$db))->Store($name, $kind, $mime, $bytes, $name === 'Unlicensed' ? '   ' : 'OFL-1.1', null)),
			$field, $code, 'A rejected asset');

		self::assertSame($assets, self::assetRowCount(), 'A refused upload writes no asset row');
		self::assertSame($files, self::assetFileCount(), 'A refused upload writes no bytes');
	}

	/**
	 * The three refusals that need bytes structured a particular way: a font with no
	 * readable name table, one naming no family, and an image whose real type is not the
	 * declared one.
	 */
	public function testAssetsRefusedOnWhatTheBytesActuallyAre(): void
	{
		$assets = self::assetRowCount();
		$service = static fn () => new LabelAssetService(self::$db);

		self::assertRefused(static fn () => self::tx(static fn () => $service()
			->Store('No Name Table', 'font', 'font/ttf', self::sfntWithoutNameTable(), 'OFL-1.1', null)),
			'content', 'invalid_value', 'A font with no name table');

		self::assertRefused(static fn () => self::tx(static fn () => $service()
			->Store('Name Past The End', 'font', 'font/ttf', self::sfnt(['Truncated'], "\x00\x01\x00\x00", 3, 100000), 'OFL-1.1', null)),
			'content', 'invalid_value', 'A font whose name table lies outside the file');

		// nameID 4 is the full name; a font that carries one but no family (nameID 1) has
		// nothing a template could pin.
		self::assertRefused(static fn () => self::tx(static fn () => $service()
			->Store('No Family', 'font', 'font/ttf', self::sfntNamedOnly4(), 'OFL-1.1', null)),
			'content', 'invalid_value', 'A font that names no family');

		self::assertRefused(static fn () => self::tx(static fn () => $service()
			->Store('Mislabelled Gif', 'image', 'image/png', self::gifBytes(), 'CC0-1.0', null)),
			'mime_type', 'invalid_value', 'An image whose bytes are not the type it declared');

		self::assertRefused(static fn () => self::tx(static fn () => $service()
			->Store('Decompression Bomb', 'image', 'image/png', self::oversizedPng(5000, 5000), 'CC0-1.0', null)),
			'content', 'value_out_of_range', 'An image that decodes to more pixels than the bound');

		self::assertSame($assets, self::assetRowCount(), 'None of those refusals stored an asset');
	}

	/** An sfnt whose only name record is nameID 4, so nothing names the family. */
	private static function sfntNamedOnly4(): string
	{
		$value = mb_convert_encoding('Victual Full Name Only', 'UTF-16BE', 'UTF-8');
		$records = pack('nnnnnn', 3, 1, 0, 4, strlen($value), 0);
		$table = pack('nnn', 0, 1, 6 + 12) . $records . $value;
		$entry = 'name' . pack('N', 0) . pack('N', 28) . pack('N', strlen($table));
		return "\x00\x01\x00\x00" . pack('nnnn', 1, 16, 0, 0) . $entry . $table;
	}

	/** The compressed-byte bound, which is checked before anything is decoded. */
	public function testAssetLargerThanTheStorageBoundIsRefused(): void
	{
		$assets = self::assetRowCount();
		self::assertRefused(
			static fn () => self::tx(static fn () => (new LabelAssetService(self::$db))
				->Store('Too Large', 'image', 'image/png', str_repeat('x', LabelByteStore::MAX_BYTES + 1), 'CC0-1.0', null)),
			'content', 'value_out_of_range', 'An upload past the storage bound');
		self::assertSame($assets, self::assetRowCount(), 'An oversized upload stores nothing');
	}

	// --- RenderRequestService --------------------------------------------------------------

	private static function requests(): RenderRequestService
	{
		return new RenderRequestService(self::$db);
	}

	/** Sets the queue aside so a claim in this test can only return this test's request. */
	private static function quiesceQueue(): void
	{
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");
	}

	private static function createRequest(string $purpose, ?int $versionId = null, ?int $captureId = null, ?int $profileId = null): array
	{
		return self::tx(static fn () => self::requests()->CreateForVersion(
			$purpose, $versionId ?? self::$qrTemplateVersion, $profileId ?? (int)self::$profile['id'],
			$captureId ?? self::$captureId, 9000));
	}

	/** Creates a request and claims it, having first set the rest of the queue aside. */
	private static function claimFresh(?int $versionId = null, ?int $captureId = null, ?int $profileId = null, string $purpose = 'production'): array
	{
		self::quiesceQueue();
		$request = self::createRequest($purpose, $versionId, $captureId, $profileId);
		$input = self::tx(static fn () => self::requests()->Claim());
		self::assertIsArray($input, 'The request just created is claimable');
		self::assertSame((int)$request['id'], $input['render_request_id'], 'The claim returned the request this test created');
		return $input;
	}

	private static function requestRow(int $requestId): array
	{
		return self::$db->query('SELECT * FROM label_render_requests WHERE id = ' . $requestId)->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * A production request renders what a printed label needs and never expires; a preview
	 * request states how long it may sit unpromoted.
	 */
	public function testCreateForVersionRecordsWhatWillBeRendered(): void
	{
		$production = self::createRequest('production');
		self::assertSame('pending', $production['state'], 'A new request is waiting for a renderer');
		self::assertSame(0, (int)$production['generation'], 'A request that has never been claimed is at generation zero');
		self::assertSame(0, (int)$production['attempts'], 'A request that has never been claimed has made no attempt');
		self::assertNull($production['expires_at'], 'A production render does not expire; the label it produces is meant to be reprintable');
		self::assertSame(self::$qrTemplateVersion, (int)$production['template_version_id'], 'The request pins the published version');
		self::assertSame(self::$captureId, (int)$production['capture_id'], 'The request pins the capture');
		self::assertSame(9000, (int)$production['created_by_user_id'], 'The request records who asked for it');
		self::assertNull($production['generation_token'], 'No lease token exists before a claim');

		foreach (['preview_live', 'preview_sample'] as $purpose)
		{
			$preview = self::createRequest($purpose);
			self::assertNotNull($preview['expires_at'], "A $purpose preview expires");
			$window = (int)self::$db->query('SELECT EXTRACT(EPOCH FROM (expires_at - CURRENT_TIMESTAMP)) FROM label_render_requests WHERE id = ' . (int)$preview['id'])->fetchColumn();
			self::assertEqualsWithDelta(RenderRequestService::PREVIEW_TTL_SECONDS, $window, 120,
				"A $purpose preview expires after the stated preview lifetime");
		}
	}

	public function testUnknownRenderPurposeIsRefusedAndQueuesNothing(): void
	{
		$before = (int)self::$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn();
		self::assertRefused(static fn () => self::createRequest('preview_draft'), 'purpose', 'invalid_value',
			'A purpose that has no published version to render');
		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn(),
			'A refused purpose queues no render');
	}

	public function testGettingARequestThatDoesNotExistIsRefused(): void
	{
		self::assertRefused(static fn () => self::requests()->Get(987654), 'render_request_id', 'not_found',
			'A render request id nothing issued');
	}

	public function testGetReturnsTheStoredRequest(): void
	{
		$created = self::createRequest('production');
		self::assertSame((int)$created['id'], (int)self::requests()->Get((int)$created['id'])['id'],
			'A request reads back by its id');
	}

	public function testClaimReturnsNothingWhenTheQueueIsEmpty(): void
	{
		self::quiesceQueue();
		self::assertNull(self::tx(static fn () => self::requests()->Claim()),
			'A renderer asking an empty queue is told there is nothing, rather than being handed something');
	}

	/**
	 * The claim composes everything the renderer needs and nothing it could be pointed at:
	 * a resolved document, the captured values, the profile geometry and the QR matrix
	 * Victual computed. There is no URL anywhere in it.
	 */
	public function testClaimComposesTheRendererInputForAQrOnlyTemplate(): void
	{
		$input = self::claimFresh();

		self::assertSame('production', $input['purpose'], 'The renderer is told what the render is for');
		self::assertSame(1, $input['generation'], 'Claiming advances the generation');
		self::assertNotNull($input['generation_token'], 'A claim issues the token its result must carry');
		self::assertSame(ArtifactService::FORM, $input['expected_form'], 'The renderer is told which artifact form is expected');

		self::assertSame(self::$qrTemplate, $input['template']['template_id'], 'The input names the template');
		self::assertSame(self::$qrTemplateVersion, $input['template']['template_version_id'], 'The input names the published version');
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$input['template']['document_digest'],
			'The input carries the digest of the document being rendered');
		self::assertSame(self::qrOnlyDocument()['elements'][0]['id'], $input['document']['elements'][0]['id'],
			'The document is the published one');

		self::assertSame(self::$captureId, $input['capture']['id'], 'The input names the capture');
		self::assertSame(['location.name' => 'Pantry'], $input['capture']['fields'], 'The captured values travel with the request');
		self::assertSame('en', $input['capture']['locale'], 'The pinned locale travels with the request');
		self::assertSame('UTC', $input['capture']['timezone'], 'The pinned timezone travels with the request');

		self::assertSame((int)self::$profile['id'], $input['profile']['id'], 'The input names the media profile');
		self::assertSame(696, (int)$input['profile']['raster_width_px'], 'The raster width is the profile geometry, not the canvas');
		self::assertSame(300, (int)$input['profile']['dpi_x'], 'The horizontal resolution is stated');
		self::assertSame(300, (int)$input['profile']['dpi_y'], 'The vertical resolution is stated separately');

		self::assertSame(QrMatrix::Payload(self::LABEL_UID), $input['qr']['code']['payload'],
			'The QR carries the server-supplied payload for this label');
		self::assertGreaterThanOrEqual(21, $input['qr']['code']['size'], 'The matrix is a real QR symbol');
		self::assertSame([], $input['assets'], 'Negative control: a QR-only template resolves no assets at all');

		$row = self::requestRow($input['render_request_id']);
		self::assertSame('rendering', $row['state'], 'The claimed request is recorded as being rendered');
		self::assertSame(1, (int)$row['attempts'], 'The claim counts as an attempt');
		self::assertNotNull($row['lease_expires_at'], 'The claim takes a lease with an end');
	}

	/** A template carrying text resolves the font it pins, by id, out of stored assets. */
	public function testClaimOfATemplateCarryingTextResolvesItsFont(): void
	{
		$input = self::claimFresh(self::$textTemplateVersion);

		self::assertCount(1, $input['assets'], 'The one asset the document pins is resolved');
		self::assertSame(self::$fontAssetId, (int)$input['assets'][0]['id'], 'The asset is resolved to the stored id, not to a name the renderer must look up');
		self::assertSame('font', $input['assets'][0]['asset_kind'], 'The renderer is told it is a font');
		self::assertSame('Fixture Sans', $input['assets'][0]['font_family'], 'The decoded family travels with the asset');
		self::assertArrayNotHasKey('content', $input['assets'][0], 'The bytes are not inlined; the renderer fetches them through its own authorized route');
		self::assertSame(QrMatrix::Payload(self::LABEL_UID), $input['qr']['code']['payload'],
			'A template carrying text still gets its QR matrix computed');
	}

	/**
	 * A sample preview carries a payload that is visibly sample data, so a preview scanned
	 * off a screen cannot resolve to a real shelf.
	 */
	public function testSamplePreviewCarriesASampledPayload(): void
	{
		$input = self::claimFresh(null, self::$sampleCaptureId, null, 'preview_sample');
		self::assertSame('VCTL:SAMPLE-PREVIEW', $input['qr']['code']['payload'],
			'A capture with no label identity produces a payload that is not a uid');
		self::assertNotSame(QrMatrix::Payload(self::LABEL_UID), $input['qr']['code']['payload'],
			'Negative control: the sample payload is not the real label payload');
	}

	/**
	 * A draft preview pins the document it was asked about, so an edit landing while the
	 * render is in flight cannot change the result somebody is waiting to look at.
	 */
	public function testDraftPreviewPinsTheDocumentItWasAskedAbout(): void
	{
		self::quiesceQueue();
		$pinned = self::textDocument();
		$pinned['elements'][1]['literal'] = 'Pinned at request time';
		$pinned['elements'][1]['field'] = null;

		$request = self::tx(static fn () => self::requests()->CreateForDraft(
			self::$qrTemplate, TemplateDocument::Validate($pinned, 'location')['document'],
			(int)self::$profile['id'], self::$captureId, 9000));
		self::assertSame('preview_draft', $request['purpose'], 'A draft preview says what it is');
		self::assertNull($request['template_version_id'], 'A draft preview renders no published version');

		$input = self::tx(static fn () => self::requests()->Claim());
		self::assertSame(self::$qrTemplate, $input['template']['template_id'], 'The draft preview names the template being edited');
		self::assertNull($input['template']['template_version_id'], 'A draft has no published version to name');
		self::assertNull($input['template']['document_digest'], 'A draft is not a published digest');
		self::assertSame('Pinned at request time', $input['document']['elements'][1]['literal'],
			'The document rendered is the one that was pinned');
		self::assertSame(self::$fontAssetId, (int)$input['assets'][0]['id'],
			'A draft resolves its assets by the name the document pins');
	}

	public function testDraftReferencingAnAssetThatIsNotStoredIsRefused(): void
	{
		self::quiesceQueue();
		$document = self::textDocument();
		$document['elements'][1]['font_asset'] = 'Never Uploaded';

		self::tx(static fn () => self::requests()->CreateForDraft(
			self::$qrTemplate, TemplateDocument::Validate($document, 'location')['document'],
			(int)self::$profile['id'], self::$captureId, 9000));

		self::assertRefused(static fn () => self::tx(static fn () => self::requests()->Claim()),
			'assets', 'asset_unavailable', 'A draft pinning a font nobody uploaded');
	}

	/** An expired preview is not work; a renderer coming back later must not pick it up. */
	public function testExpiredPreviewRequestIsNotClaimed(): void
	{
		self::quiesceQueue();
		$request = self::createRequest('preview_live');
		self::$db->exec("UPDATE label_render_requests SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE id = " . (int)$request['id']);
		self::assertNull(self::tx(static fn () => self::requests()->Claim()),
			'A preview whose lifetime has run out is not handed to a renderer');
	}

	/**
	 * A renderer that died holding a lease must not be able to commit over the replacement,
	 * which is what bumping the generation buys.
	 */
	public function testExpiredLeaseReturnsToTheQueueUnderANewGeneration(): void
	{
		$input = self::claimFresh();
		self::$db->exec("UPDATE label_render_requests SET lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = " . $input['render_request_id']);

		self::assertSame(1, self::tx(static fn () => self::requests()->ReapExpired()), 'The expired lease is reaped');
		$row = self::requestRow($input['render_request_id']);
		self::assertSame('pending', $row['state'], 'The work goes back on the queue rather than being lost');
		self::assertSame(2, (int)$row['generation'], 'The generation moves past the token the dead renderer holds');
		self::assertNull($row['generation_token'], 'The dead renderer holds a token nothing will accept');
		self::assertNull($row['lease_expires_at'], 'The lease is gone');

		self::assertRefused(static fn () => self::tx(static fn () => self::requests()
			->AssertCurrent($input['render_request_id'], (string)$input['generation_token'])),
			'generation_token', 'stale_generation', 'A result from the superseded generation');
	}

	/** Retrying a render is bounded: past the bound a person looks at it rather than a loop. */
	public function testRenderLeaseExhaustionIsTerminal(): void
	{
		$input = self::claimFresh();
		self::$db->exec('UPDATE label_render_requests SET attempts = ' . RenderRequestService::MAX_ATTEMPTS
			. ", lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = " . $input['render_request_id']);

		self::assertSame(1, self::tx(static fn () => self::requests()->ReapExpired()), 'The exhausted lease is reaped');
		$row = self::requestRow($input['render_request_id']);
		self::assertSame('failed', $row['state'], 'A render that keeps losing its lease stops retrying');
		self::assertSame('RENDER_LEASE_EXHAUSTED', $row['error_code'], 'The record says why it stopped');
		self::assertStringContainsString((string)RenderRequestService::MAX_ATTEMPTS, (string)$row['error_detail'],
			'The record says how many times it tried');
	}

	public function testReapingFindsNothingWhenNoLeaseHasExpired(): void
	{
		self::quiesceQueue();
		self::assertSame(0, self::tx(static fn () => self::requests()->ReapExpired()),
			'Negative control: a queue with no expired lease reaps nothing');
	}

	/**
	 * `invalid` is a document nobody can render and names the element; `failed` is
	 * infrastructure and retries. Conflating them would make a template nobody can fix look
	 * like a service that might recover on its own.
	 */
	public function testInvalidResultNamesTheElementAndStopsRetrying(): void
	{
		$input = self::claimFresh();
		$result = self::tx(static fn () => self::requests()->RecordInvalid(
			$input['render_request_id'], (string)$input['generation_token'], 'TEXT_OVERFLOW', 'title', 'The string does not fit its box'));

		self::assertSame('invalid', $result['state'], 'A document that cannot be rendered is invalid, not failed');
		self::assertSame('TEXT_OVERFLOW', $result['error_code'], 'The code says what was wrong');
		self::assertSame('title', $result['error_element'], 'The refusal names the element a designer has to fix');
		self::assertNull($result['generation_token'], 'The lease is given back');

		self::assertNull(self::tx(static fn () => self::requests()->Claim()),
			'An invalid request is not retried; somebody has to change the document');
	}

	public function testInfrastructureFailureRetriesUntilTheBoundThenFails(): void
	{
		$input = self::claimFresh();
		$retried = self::tx(static fn () => self::requests()->RecordFailure(
			$input['render_request_id'], (string)$input['generation_token'], 'the renderer could not reach its font cache'));
		self::assertSame('pending', $retried['state'], 'An infrastructure failure goes back on the queue');
		self::assertSame('RENDER_FAILED', $retried['error_code'], 'The record says it was a failure rather than a bad document');

		$again = self::tx(static fn () => self::requests()->Claim());
		self::assertSame($input['render_request_id'], $again['render_request_id'], 'The same request is offered again');

		self::$db->exec('UPDATE label_render_requests SET attempts = ' . RenderRequestService::MAX_ATTEMPTS
			. ' WHERE id = ' . $input['render_request_id']);
		$terminal = self::tx(static fn () => self::requests()->RecordFailure(
			$again['render_request_id'], (string)$again['generation_token'], 'still could not reach it'));
		self::assertSame('failed', $terminal['state'], 'Past the bound the request stops retrying and waits for a person');
	}

	public function testResultForARequestThatDoesNotExistIsRefused(): void
	{
		self::assertRefused(static fn () => self::tx(static fn () => self::requests()->AssertCurrent(987654, 'whatever')),
			'render_request_id', 'not_found', 'A result for a request nothing issued');
	}

	public function testResultCarryingTheWrongTokenIsRefused(): void
	{
		$input = self::claimFresh();
		self::assertRefused(static fn () => self::tx(static fn () => self::requests()
			->AssertCurrent($input['render_request_id'], str_repeat('0', 32))),
			'generation_token', 'stale_generation', 'A result carrying a token this request never issued');
	}

	// --- ArtifactService -------------------------------------------------------------------

	private static function artifacts(): ArtifactService
	{
		return new ArtifactService(self::$db);
	}

	/** Millimetres to device pixels by the profile's stated rounding rule. */
	private static function pixels(float $millimetres, int $dpi): int
	{
		return (int)round($millimetres * $dpi / 25.4);
	}

	/**
	 * Paints a resolved render input as a conforming artifact.
	 *
	 * This is the renderer, as a fixture: it paints the module matrix Victual computed at
	 * the geometry the profile fixes. Nothing here is a shortcut past verification.
	 */
	private static function paint(array $input, ?int $height = null, ?array $palette = null, int $bitDepth = 2, ?callable $mutate = null): string
	{
		$profile = $input['profile'];
		$document = $input['document'];
		$width = (int)$profile['raster_width_px'];
		$palette ??= $profile['pixel_policy']['palette'];
		$height ??= self::pixels((float)($document['canvas']['height_mm'] ?? $document['canvas']['max_height_mm']), (int)$profile['dpi_y']);

		$white = (int)array_search('#ffffff', $palette, true);
		$black = array_search('#000000', $palette, true);
		$pixels = array_fill(0, $height, array_fill(0, $width, $white));

		foreach ($document['elements'] as $element)
		{
			if ($element['type'] !== 'qr' || $black === false)
			{
				continue;
			}
			$matrix = $input['qr'][$element['id']];
			$moduleX = self::pixels((float)$element['module_mm'], (int)$profile['dpi_x']);
			$moduleY = self::pixels((float)$element['module_mm'], (int)$profile['dpi_y']);
			$originX = self::pixels((float)$element['x_mm'], (int)$profile['dpi_x']) + $element['quiet_zone_modules'] * $moduleX;
			$originY = self::pixels((float)$element['y_mm'], (int)$profile['dpi_y']) + $element['quiet_zone_modules'] * $moduleY;

			for ($row = 0; $row < $matrix['size']; $row++)
			{
				for ($column = 0; $column < $matrix['size']; $column++)
				{
					if ($matrix['modules'][$row][$column] !== '1')
					{
						continue;
					}
					for ($dy = 0; $dy < $moduleY; $dy++)
					{
						for ($dx = 0; $dx < $moduleX; $dx++)
						{
							$y = $originY + $row * $moduleY + $dy;
							$x = $originX + $column * $moduleX + $dx;
							if ($y < $height && $x < $width)
							{
								$pixels[$y][$x] = (int)$black;
							}
						}
					}
				}
			}
		}

		if ($mutate !== null)
		{
			$mutate($pixels);
		}

		return self::indexedPng($pixels, $width, $height, $palette, $bitDepth);
	}

	/** Derives - or returns - the immutable profile for a media combination. */
	private static function profileFor(array $combination): array
	{
		return self::tx(static fn () => (new MediaProfileService(self::$db))->Ensure(
			['driver_id' => 'brother.ql', 'driver_schema_version' => '1.0', 'connection_type' => 'tcp'], [], $combination));
	}

	private static function dieCutProfile(): int
	{
		return (int)self::profileFor(['model' => 'QL-820NWBc', 'media' => '29die', 'color_mode' => 'monochrome',
			'resolution_x' => 300, 'resolution_y' => 300, 'printable_width_um' => 25400,
			'printable_length_um' => 25400, 'feed_direction' => 'y'])['id'];
	}

	/**
	 * Commits an artifact for the QR-only template and returns the claim input beside it.
	 *
	 * @return array{0: array, 1: array, 2: string} render input, artifact row, bytes
	 */
	private static function acceptArtifact(string $purpose = 'production', ?int $captureId = null): array
	{
		$input = self::claimFresh(null, $captureId, null, $purpose);
		$bytes = self::paint($input);
		$artifact = self::tx(static fn () => self::artifacts()->Accept(
			$input['render_request_id'], (string)$input['generation_token'], $bytes, 'fixture-renderer', '0.0.1-fixture'));
		return [$input, $artifact, $bytes];
	}

	/**
	 * The bytes are the authority, and everything in the manifest was read out of them or
	 * out of the pinned profile - never taken from what the renderer said about its output.
	 */
	public function testAcceptedArtifactIsCommittedWithAManifestReadFromTheBytes(): void
	{
		[$input, $artifact, $bytes] = self::acceptArtifact();

		self::assertSame(ArtifactService::FORM, $artifact['form'], 'The artifact is recorded under the form that was verified');
		self::assertSame(hash('sha256', $bytes), $artifact['byte_digest'], 'The digest is computed over the bytes that arrived');
		self::assertSame(strlen($bytes), (int)$artifact['byte_length'], 'The length is the length of those bytes');
		self::assertSame(696, (int)$artifact['width_px'], 'The width is the profile raster width');
		self::assertSame(354, (int)$artifact['height_px'], 'The height is the canvas height at the profile resolution');
		self::assertSame('retained', $artifact['retention_class'], 'A production render is retained so it can be reprinted exactly');

		$manifest = $artifact['manifest'];
		self::assertSame(['#ffffff', '#000000', '#ff0000'], $manifest['palette'], 'The manifest palette is the one read out of the PLTE chunk');
		self::assertSame(['id' => (int)self::$profile['id'], 'version' => (int)self::$profile['version']], $manifest['profile'],
			'The manifest pins the profile the geometry was checked against');
		self::assertSame(self::$qrTemplateVersion, $manifest['template']['template_version_id'], 'The manifest records which design it expressed');
		self::assertSame(self::$captureId, $manifest['capture']['id'], 'The manifest records which values it expressed');
		self::assertSame(['id' => 'fixture-renderer', 'version' => '0.0.1-fixture'], $manifest['renderer'], 'The manifest records who rendered it');
		self::assertSame(['form_checked' => true, 'geometry_checked' => true, 'palette_checked' => true, 'qr_checked' => true],
			$manifest['validation'], 'The manifest records which checks were actually made');
		self::assertSame(CanonicalJson::Digest($manifest), $artifact['manifest_digest'],
			'The manifest digest is over the canonical encoding of the manifest');

		$stored = self::$db->query("SELECT content FROM files WHERE file_group = 'labelartifacts' AND name = "
			. self::$db->quote($artifact['file_name']))->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($stored, 'The bytes are durably stored');
		self::assertSame($bytes, is_resource($stored['content']) ? stream_get_contents($stored['content']) : (string)$stored['content'],
			'The stored bytes are exactly the bytes that were verified');

		$row = self::requestRow($input['render_request_id']);
		self::assertSame('ready', $row['state'], 'The request is ready once its artifact is committed');
		self::assertSame((int)$artifact['id'], (int)$row['artifact_id'], 'The request names the artifact it produced');
		self::assertNull($row['generation_token'], 'The lease is given back');

		self::assertSame($bytes, self::artifacts()->Bytes((int)$artifact['id']), 'The committed bytes read back exactly');
		self::assertSame((int)$artifact['id'], (int)self::artifacts()->Get((int)$artifact['id'])['id'], 'The artifact reads back by id');
	}

	/** A committed artifact cannot be replaced by a later result carrying the same token. */
	public function testStaleResultCannotReplaceACommittedArtifact(): void
	{
		[$input, , $bytes] = self::acceptArtifact();
		self::assertRefused(static fn () => self::tx(static fn () => self::artifacts()->Accept(
			$input['render_request_id'], (string)$input['generation_token'], $bytes, 'other-renderer', '9')),
			'generation_token', 'already_committed', 'A second result for a request that already has an artifact');
	}

	public function testArtifactThatDoesNotExistIsRefused(): void
	{
		self::assertRefused(static fn () => self::artifacts()->Get(987654), 'artifact_id', 'not_found', 'An artifact id nothing produced');
		self::assertRefused(static fn () => self::tx(static fn () => self::artifacts()->Promote(987654)),
			'artifact_id', 'not_found', 'Promoting an artifact that does not exist');
	}

	/**
	 * @return array<string, array{0: string, 1: string}> a description of the bad artifact
	 *         and the refusal code the verifier must answer with
	 */
	public static function rejectedArtifacts(): array
	{
		return [
			'no bytes at all' => ['empty', 'value_out_of_range'],
			'an indexed PNG at the wrong bit depth' => ['wrong_depth', 'wrong_form'],
			'a raster narrower than the profile fixes' => ['narrow', 'geometry_mismatch'],
			'a raster longer than this endless media permits' => ['too_long', 'media_incompatible'],
			'a palette in an order the profile did not state' => ['palette_order', 'palette_mismatch'],
			'a palette carrying a colour the profile does not permit' => ['palette_extra', 'palette_mismatch'],
			'a QR symbol running off the edge of the raster' => ['qr_outside', 'qr_outside_artifact'],
			'a QR module smaller than one device pixel' => ['qr_too_small', 'qr_too_small'],
			'a QR symbol with one module painted the wrong way' => ['qr_flipped', 'qr_payload_mismatch'],
			'a profile palette with no black to draw a QR in' => ['no_black', 'palette_mismatch'],
		];
	}

	/**
	 * Everything the verifier stands between a renderer and a printer for.
	 *
	 * Each case is refused with a code naming which check refused it, and - because the
	 * commit and the byte store are one transaction - leaves no artifact row and no bytes
	 * behind.
	 */
	#[DataProvider('rejectedArtifacts')]
	public function testRejectedArtifactCommitsNothing(string $variant, string $code): void
	{
		$rows = (int)self::$db->query('SELECT COUNT(*) FROM label_artifacts')->fetchColumn();
		$files = (int)self::$db->query("SELECT COUNT(*) FROM files WHERE file_group = 'labelartifacts'")->fetchColumn();

		[$input, $bytes] = self::badArtifact($variant);
		self::assertRefused(static fn () => self::tx(static fn () => self::artifacts()->Accept(
			$input['render_request_id'], (string)$input['generation_token'], $bytes, 'fixture-renderer', '0.0.1-fixture')),
			str_starts_with($variant, 'qr_') ? 'elements.code' : 'artifact', $code,
			'An artifact the verifier must refuse');

		self::assertSame($rows, (int)self::$db->query('SELECT COUNT(*) FROM label_artifacts')->fetchColumn(),
			'A refused artifact commits no manifest');
		self::assertSame($files, (int)self::$db->query("SELECT COUNT(*) FROM files WHERE file_group = 'labelartifacts'")->fetchColumn(),
			'A refused artifact stores no bytes');
		self::assertNotSame('ready', self::requestRow($input['render_request_id'])['state'],
			'A refused artifact leaves the request unready');
	}

	/** @return array{0: array, 1: string} the render input and the bytes that must be refused */
	private static function badArtifact(string $variant): array
	{
		if ($variant === 'qr_too_small')
		{
			$profile = self::profileFor(['model' => 'QL-820NWBc', 'media' => '62coarse', 'color_mode' => 'monochrome',
				'resolution_x' => 20, 'resolution_y' => 20, 'printable_width_um' => 58928,
				'printable_length_um' => ['min' => 1000, 'max' => 100000], 'feed_direction' => 'y']);
			$input = self::claimFresh(null, null, (int)$profile['id']);
			return [$input, self::paint($input)];
		}
		if ($variant === 'no_black')
		{
			$input = self::claimFresh(null, null, self::paletteWithoutBlack());
			return [$input, self::paint($input)];
		}
		if ($variant === 'qr_outside')
		{
			$input = self::claimFresh(self::$edgeTemplateVersion);
			return [$input, self::paint($input)];
		}
		if ($variant === 'qr_flipped')
		{
			// One module of the top-left finder pattern turned white. Verification
			// re-encodes the pinned payload rather than trusting the renderer's account of
			// it, so a single wrong module is caught.
			$input = self::claimFresh();
			$module = self::pixels(0.6, 300);
			$origin = self::pixels(2.0, 300) + 4 * $module;
			return [$input, self::paint($input, null, null, 2, static function (array &$pixels) use ($module, $origin)
			{
				for ($dy = 0; $dy < $module; $dy++)
				{
					for ($dx = 0; $dx < $module; $dx++)
					{
						$pixels[$origin + $dy][$origin + $dx] = 0;
					}
				}
			})];
		}

		$input = self::claimFresh();
		$palette = $input['profile']['pixel_policy']['palette'];
		return [$input, match ($variant)
		{
			'empty' => '',
			'wrong_depth' => self::paint($input, null, null, 8),
			'narrow' => self::paint(array_replace_recursive($input, ['profile' => ['raster_width_px' => 100]])),
			'too_long' => self::paint($input, 1200),
			'palette_order' => self::paint($input, null, ['#000000', '#ffffff', '#ff0000']),
			'palette_extra' => self::paint($input, null, array_merge($palette, ['#00ff00'])),
		}];
	}

	/**
	 * A media profile whose palette carries no black.
	 *
	 * Written straight into the table because no driver combination derives one: it is the
	 * condition the QR verifier refuses rather than divides by, and a verifier that assumed
	 * black was always present would index a palette with a false.
	 */
	private static function paletteWithoutBlack(): int
	{
		$document = ['contract_version' => 1, 'dpi_x' => 300, 'dpi_y' => 300, 'color_mode' => 'monochrome',
			'raster_width_px' => 100, 'length_rules' => ['kind' => 'endless', 'min_um' => 1000, 'max_um' => 100000],
			'pixel_policy' => ['version' => 1, 'rounding' => 'nearest', 'palette' => ['#ffffff', '#00ff00']]];
		$statement = self::$db->prepare('INSERT INTO label_media_profiles(profile_key,version,driver_id,driver_schema_version,model,media,connection_type,color_mode,dpi_x,dpi_y,raster_width_px,document,document_digest)
			VALUES (?,1,?,?,?,?,?,?,?,?,?,?::jsonb,?) RETURNING id');
		$statement->execute(['no-black', 'brother.ql', '1.0', 'QL-820NWBc', '62green', 'tcp', 'monochrome', 300, 300, 100,
			json_encode($document), hash('sha256', 'no-black')]);
		return (int)$statement->fetchColumn();
	}

	/** Die-cut media fixes the artifact length exactly; there is no resize. */
	public function testDieCutMediaFixesTheArtifactLength(): void
	{
		$profile = self::dieCutProfile();

		$input = self::claimFresh(null, null, $profile);
		$exact = self::paint($input, 300);
		$artifact = self::tx(static fn () => self::artifacts()->Accept(
			$input['render_request_id'], (string)$input['generation_token'], $exact, 'fixture-renderer', '0.0.1-fixture'));
		self::assertSame(300, (int)$artifact['height_px'], 'Die-cut media accepts exactly the length it fixes');
		self::assertSame('monochrome', $artifact['color_mode'], 'The artifact records the profile colour mode');

		$short = self::claimFresh(null, null, $profile);
		self::assertRefused(static fn () => self::tx(static fn () => self::artifacts()->Accept(
			$short['render_request_id'], (string)$short['generation_token'], self::paint($short, 299), 'fixture-renderer', '0.0.1-fixture')),
			'artifact', 'geometry_mismatch', 'An artifact one pixel short of the die-cut length');
	}

	/**
	 * Promotion changes retention, and refuses an artifact whose bytes have already left -
	 * so promotion and collection cannot race into a job pointing at nothing.
	 */
	public function testPromotionAndCollectionOfPreviewArtifacts(): void
	{
		// Only this test's artifacts may be eligible, or the collector's count would depend
		// on what every earlier test happened to leave behind.
		self::$db->exec("UPDATE label_artifacts SET retention_class = 'retained' WHERE collected_at IS NULL");

		// Its own label identity, so the picture these two renders share is shared with
		// nothing else in the suite and the collector's reference count is this test's.
		$capture = (int)self::tx(static fn () => (new LabelCaptureService(self::$db))
			->Capture('location', 9001, '0PREVIEWUID01', ['location.name'], 'en', 'UTC', 9000))['id'];

		[$firstInput, $first, $bytes] = self::acceptArtifact('preview_live', $capture);
		self::assertSame('preview', $first['retention_class'], 'An unpromoted preview is not retained');

		// A second manifest over pixel-identical bytes: two records of two renders that
		// legitimately produced the same picture.
		[$secondInput, $second] = self::acceptArtifact('preview_live', $capture);
		self::assertSame($first['file_name'], $second['file_name'], 'Identical output is one set of stored bytes');
		self::assertNotSame((int)$first['id'], (int)$second['id'], 'Two renders are two manifests');

		$promoted = self::tx(static fn () => self::artifacts()->Promote((int)$second['id']));
		self::assertSame('retained', $promoted['retention_class'], 'Promotion retains the artifact');

		self::$db->exec("UPDATE label_render_requests SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE id IN ("
			. $firstInput['render_request_id'] . ', ' . $secondInput['render_request_id'] . ')');

		self::assertSame(1, self::tx(static fn () => self::artifacts()->CollectExpiredPreviews()),
			'Only the unpromoted preview is collected');
		self::assertNotNull(self::artifacts()->Get((int)$first['id'])['collected_at'], 'The collected manifest records that it was collected');
		self::assertNull(self::artifacts()->Get((int)$second['id'])['collected_at'], 'Negative control: the promoted artifact is untouched');
		self::assertSame($bytes, self::artifacts()->Bytes((int)$second['id']),
			'Collecting one manifest does not take the bytes another manifest still points at');

		self::assertRefused(static fn () => self::artifacts()->Bytes((int)$first['id']),
			'artifact_id', 'artifact_collected', 'Reading the bytes of a collected artifact');
		self::assertRefused(static fn () => self::tx(static fn () => self::artifacts()->Promote((int)$first['id'])),
			'artifact_id', 'artifact_collected', 'Promoting a collected artifact');

		// With the last live manifest gone, the bytes themselves are collected.
		self::$db->exec("UPDATE label_artifacts SET retention_class = 'preview' WHERE id = " . (int)$second['id']);
		self::assertSame(1, self::tx(static fn () => self::artifacts()->CollectExpiredPreviews()),
			'The remaining preview is collected once it is no longer retained');
		self::assertSame(0, (int)self::$db->query("SELECT COUNT(*) FROM files WHERE file_group = 'labelartifacts' AND name = "
			. self::$db->quote($first['file_name']))->fetchColumn(), 'The shared bytes go once nothing points at them');
		self::assertRefused(static fn () => self::artifacts()->Bytes((int)$second['id']),
			'artifact_id', 'artifact_collected', 'An exact reprint of collected bytes is refused rather than rerendered');
	}

	public function testCollectingFindsNothingWhenNoPreviewHasExpired(): void
	{
		self::$db->exec("UPDATE label_artifacts SET retention_class = 'retained' WHERE collected_at IS NULL");
		self::assertSame(0, self::tx(static fn () => self::artifacts()->CollectExpiredPreviews()),
			'Negative control: nothing expired, nothing collected');
	}

	// --- LabelCaptureService ---------------------------------------------------------------

	private static function captures($permissionCheck = null): LabelCaptureService
	{
		return new LabelCaptureService(self::$db, $permissionCheck);
	}

	private static function captureRowCount(): int
	{
		return (int)self::$db->query('SELECT COUNT(*) FROM label_captures')->fetchColumn();
	}

	/**
	 * A caller names an entity; what that entity is called is read here, under the
	 * permission the catalogue declares. An optional field with no value is captured empty,
	 * and a required one with no value refuses rather than printing a blank line.
	 */
	public function testCaptureReadsTheValuesRatherThanAcceptingThem(): void
	{
		self::$db->exec("INSERT INTO locations(id, name, description) VALUES (9002, 'Deep Freeze', NULL)");
		$capture = self::tx(static fn () => self::captures()->Capture(
			'location', 9002, self::LABEL_UID, ['location.name', 'location.description', 'location.id'], 'de', 'Europe/Berlin', 9000));

		self::assertSame('Deep Freeze', $capture['captured_fields']['location.name'], 'The name is read from the row, not from the request');
		self::assertSame('', $capture['captured_fields']['location.description'], 'An optional field with no value is captured empty');
		self::assertSame('9002', $capture['captured_fields']['location.id'], 'Every captured value is a string on the label');
		self::assertSame('de', $capture['locale'], 'The capture pins the locale it will be formatted in');
		self::assertSame('Europe/Berlin', $capture['timezone'], 'The capture pins a named timezone');
		self::assertSame(0, (int)$capture['is_sample'], 'A capture of a real target is not a sample');

		$stored = self::captures()->Get((int)$capture['id']);
		// assertEquals rather than assertSame: jsonb is an unordered map, and key order is
		// not part of what was captured.
		self::assertEquals($capture['captured_fields'], $stored['captured_fields'], 'The capture reads back as it was written');
		self::assertSame($capture['digest'], $stored['digest'], 'The capture digest is durable');
	}

	public function testSampleCaptureNamesNoTargetAndNoIdentity(): void
	{
		$sample = self::captures()->Get(self::$sampleCaptureId);
		self::assertSame(1, (int)$sample['is_sample'], 'A sample capture says it is one');
		self::assertNull($sample['target_id'], 'A sample capture names no target');
		self::assertNull($sample['label_uid'], 'A sample capture has no label identity, so it can never be promoted');
		self::assertNotSame('', $sample['captured_fields']['location.name'], 'A sample carries something to lay out');
	}

	public function testCaptureRefusals(): void
	{
		self::$db->exec("INSERT INTO locations(id, name, description) VALUES (9003, '', 'A shelf nobody named')");
		self::$db->exec('INSERT INTO locations(id, name, description) VALUES (9004, ' . self::$db->quote('Verbose') . ', '
			. self::$db->quote(str_repeat('x', 300)) . ')');
		$before = self::captureRowCount();

		self::assertRefused(static fn () => self::tx(static fn () => self::captures()->Capture(
			'location', 9001, self::LABEL_UID, ['location.invented'], 'en', 'UTC', 9000)),
			'fields', 'unknown_field', 'A field that is not in the catalogue');

		// The message names the permission and never the value, so an unauthorized caller
		// learns neither what was captured nor whether the target exists.
		self::assertRefused(static fn () => self::tx(static fn () => self::captures(static fn (string $permission) => false)->Capture(
			'location', 9001, self::LABEL_UID, ['location.name'], 'en', 'UTC', 9000)),
			'location.name', 'forbidden', 'A caller without the permission the field declares');

		self::assertRefused(static fn () => self::tx(static fn () => self::captures()->Capture(
			'location', 987654, self::LABEL_UID, ['location.name'], 'en', 'UTC', 9000)),
			'target_id', 'not_found', 'A target that does not exist');

		self::assertRefused(static fn () => self::tx(static fn () => self::captures()->Capture(
			'location', 9003, self::LABEL_UID, ['location.name'], 'en', 'UTC', 9000)),
			'location.name', 'field_unavailable', 'A required field with no value');

		self::assertRefused(static fn () => self::tx(static fn () => self::captures()->Capture(
			'location', 9004, self::LABEL_UID, ['location.description'], 'en', 'UTC', 9000)),
			'location.description', 'value_out_of_range', 'A value longer than the catalogue allows, which truncation would silently falsify');

		self::assertRefused(static fn () => self::tx(static fn () => self::captures()->Capture(
			'location', 9001, self::LABEL_UID, ['location.name'], 'en', '+02:00', 9000)),
			'timezone', 'invalid_value', 'An offset where a named timezone is required');

		self::assertRefused(static fn () => self::captures()->Get(987654), 'capture_id', 'not_found', 'A capture id nothing wrote');

		self::assertSame($before, self::captureRowCount(), 'None of those refusals wrote a capture');
	}

	/** A permission check that answers yes is the control for the refusal above. */
	public function testCaptureProceedsWhenThePermissionIsHeld(): void
	{
		$seen = [];
		$capture = self::tx(static function () use (&$seen)
		{
			return self::captures(static function (string $permission) use (&$seen)
			{
				$seen[] = $permission;
				return true;
			})->Capture('location', 9001, self::LABEL_UID, ['location.name'], 'en', 'UTC', 9000);
		});
		self::assertSame('Pantry', $capture['captured_fields']['location.name'], 'A permitted read captures the value');
		self::assertSame(['STOCK_VIEW'], $seen, 'The permission asked about is the one the catalogue declares for the field');
	}

	// --- LabelWorkerAuthorization and worker credentials -----------------------------------

	private static function authorization(): LabelWorkerAuthorization
	{
		return new LabelWorkerAuthorization(self::$db);
	}

	/**
	 * A claimed print attempt belonging to $workerId.
	 *
	 * @return array{0: int, 1: int} the job id and the attempt id
	 */
	private static function attempt(int $workerId): array
	{
		$outbox = (int)self::$db->query("INSERT INTO outbox(event_type, payload) VALUES ('LabelPrintRequested', '{}') RETURNING id")->fetchColumn();
		$job = (int)self::$db->query('INSERT INTO print_jobs(outbox_id, printer_id, label_uid) VALUES ('
			. $outbox . ', ' . self::$printer . ', ' . self::$db->quote(self::LABEL_UID) . ') RETURNING id')->fetchColumn();
		$attempt = (int)self::$db->query('INSERT INTO print_attempts(outbox_id, job_id, attempt_number, worker_id, lease_expires_at, lease_hard_deadline, acknowledged_on)
			VALUES (' . $outbox . ', ' . $job . ", 1, " . $workerId . ", CURRENT_TIMESTAMP + INTERVAL '5 minutes', CURRENT_TIMESTAMP + INTERVAL '10 minutes', 'report') RETURNING id")->fetchColumn();
		self::$db->exec('UPDATE print_jobs SET current_attempt_id = ' . $attempt . ' WHERE id = ' . $job);
		return [$job, $attempt];
	}

	private static function secondWorker(): int
	{
		return (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('labelservices-other-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
	}

	/**
	 * Which routes a worker may act on at all, and which of its own things it may act on.
	 *
	 * Authorization here is about ownership: a live worker on a route that needs no target,
	 * and a worker acting on an attempt or a printer that is its own.
	 */
	public function testWorkerMayActOnItsOwnAttemptAndItsOwnPrinter(): void
	{
		[, $attempt] = self::attempt(self::$worker);

		self::authorization()->Authorize('labels-claim', self::$worker);
		self::authorization()->Authorize('labels-result', self::$worker, $attempt);
		self::authorization()->Authorize('labels-status', self::$worker, self::$printer);
		self::authorization()->Authorize('labels-render-claim', self::$worker);

		self::assertTrue(true, 'An active worker is authorized on its own attempt, its own printer and the routes that name neither');
	}

	public function testPairingIsNotAWorkerOperation(): void
	{
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-pair', self::$worker),
			'route', 'forbidden', 'Pairing, which is how a worker gets a credential rather than something it does with one');
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-invented', self::$worker),
			'route', 'forbidden', 'A route name the authorization map does not carry');
	}

	public function testInactiveWorkerIsRefusedEverywhere(): void
	{
		$worker = self::secondWorker();
		self::$db->exec('UPDATE label_workers SET active = 0 WHERE id = ' . $worker);
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-claim', $worker),
			'worker', 'unauthorized', 'A deactivated worker');
	}

	public function testWorkerCannotActOnAnotherWorkersAttemptOrPrinter(): void
	{
		$other = self::secondWorker();
		[, $attempt] = self::attempt(self::$worker);

		self::assertRefused(static fn () => self::authorization()->Authorize('labels-result', $other, $attempt),
			'attempt_id', 'forbidden', 'A worker reporting on an attempt it does not hold');
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-heartbeat', self::$worker, 987654),
			'attempt_id', 'forbidden', 'A worker heartbeating an attempt that does not exist');
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-status', $other, self::$printer),
			'printer_id', 'forbidden', 'A worker reporting the status of a printer assigned to another worker');
	}

	/**
	 * Artifact bytes follow the owning job and attempt, never the digest: two jobs may
	 * legitimately point at the same bytes, and holding one of them is not a reason to be
	 * handed the other's.
	 */
	public function testArtifactBytesFollowTheLiveAttemptThatCarriesThem(): void
	{
		[, $artifact] = self::acceptArtifact();
		[$job, $attempt] = self::attempt(self::$worker);
		self::$db->exec('UPDATE print_jobs SET artifact_id = ' . (int)$artifact['id'] . ' WHERE id = ' . $job);

		self::authorization()->Authorize('labels-artifact-bytes', self::$worker, (int)$artifact['id']);

		$other = self::secondWorker();
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-artifact-bytes', $other, (int)$artifact['id']),
			'artifact_id', 'forbidden', 'A worker asking for bytes belonging to another worker\'s attempt');

		self::$db->exec("UPDATE print_attempts SET lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = " . $attempt);
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-artifact-bytes', self::$worker, (int)$artifact['id']),
			'artifact_id', 'forbidden', 'A worker whose lease on the carrying attempt has run out');

		self::$db->exec("UPDATE print_attempts SET lease_expires_at = CURRENT_TIMESTAMP + INTERVAL '5 minutes', ended_at = CURRENT_TIMESTAMP WHERE id = " . $attempt);
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-artifact-bytes', self::$worker, (int)$artifact['id']),
			'artifact_id', 'forbidden', 'A worker whose attempt has already ended');
	}

	/**
	 * ADR-0019: a rotation supersedes a credential, and traffic that was already in flight
	 * on the old one is stale rather than stolen. Only reuse under a *different* rotation
	 * request is treated as theft, and that case is covered by the worker API suite.
	 */
	public function testRotationSupersedesWithoutTreatingStaleTrafficAsTheft(): void
	{
		$credentials = new LabelWorkerCredentialService(self::$db);
		$paired = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('labelservices-paired', 'paired') RETURNING id")->fetchColumn();
		$material = self::tx(static fn () => $credentials->PairingMaterial($paired, 9000));
		$first = self::tx(static fn () => $credentials->Pair($material['material']));

		self::assertSame($paired, (int)$credentials->Authenticate($first['credential'])['worker_id'],
			'A freshly paired credential authenticates as the worker it was issued for');

		$next = self::tx(static fn () => $credentials->Rotate($first['credential'], bin2hex(random_bytes(32)), bin2hex(random_bytes(32))));
		self::assertNull($credentials->Authenticate($first['credential']), 'The superseded credential stops working on ordinary routes');
		self::assertSame($paired, (int)$credentials->Authenticate($next['credential'])['worker_id'], 'The successor works');

		self::assertSame(0, (int)self::$db->query('SELECT requires_repairing FROM label_workers WHERE id = ' . $paired)->fetchColumn(),
			'An ordinary rotation does not mark the worker as needing to be paired again');
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM label_worker_sessions WHERE worker_id = ' . $paired
			. " AND revoked_reason = 'credential_reuse'")->fetchColumn(),
			'Stale traffic on a superseded credential is not recorded as credential reuse');
	}

	public function testExpiredAndForeignCredentialsAreRefused(): void
	{
		$credentials = new LabelWorkerCredentialService(self::$db);
		$other = self::secondWorker();
		$mine = self::tx(static fn () => $credentials->IssueDeclared(self::$worker, 9000));
		$theirs = self::tx(static fn () => $credentials->IssueDeclared($other, 9000));

		self::assertSame(self::$worker, (int)$credentials->Authenticate($mine['credential'])['worker_id'],
			'A declared worker credential authenticates as its own worker');
		self::assertSame($other, (int)$credentials->Authenticate($theirs['credential'])['worker_id'],
			'Negative control: the other worker\'s credential is not this worker\'s');

		[, $attempt] = self::attempt(self::$worker);
		self::assertRefused(static fn () => self::authorization()->Authorize('labels-result', $other, $attempt),
			'attempt_id', 'forbidden', 'A credential for the wrong worker, on this worker\'s attempt');

		self::$db->exec("UPDATE api_keys SET expires = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = " . (int)$theirs['credential_id']);
		self::assertNull($credentials->Authenticate($theirs['credential']), 'An expired credential authenticates nobody');
	}

	// --- PrintEvidenceService --------------------------------------------------------------

	private static function evidence(): PrintEvidenceService
	{
		return new PrintEvidenceService(self::$db);
	}

	private static function deviceStatusEvidence(array $overrides = []): array
	{
		return array_merge(['submission_id' => bin2hex(random_bytes(8)), 'evidence_type' => 'device_status',
			'observed_at' => '2026-09-08T14:30:00+02:00', 'printer_status' => ['state' => 'idle'],
			'detail' => ['state' => 'idle']], $overrides);
	}

	/**
	 * `observed_at` is the one genuinely RFC 3339 field in the document, and it is a
	 * TIMESTAMPTZ: the instant a worker observed something is the same instant whatever
	 * offset it wrote it in.
	 */
	public function testEvidenceObservedAtKeepsTheInstantItsOffsetNames(): void
	{
		[, $attempt] = self::attempt(self::$worker);
		$submitted = '2026-09-08T14:30:00+02:00';
		$row = self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, self::deviceStatusEvidence(['observed_at' => $submitted])));

		self::assertSame($attempt, (int)$row['attempt_id'], 'The observation is filed against the attempt it was submitted for');
		self::assertSame('worker:' . self::$worker, $row['source'], 'The observation records who reported it');
		self::assertSame(['state' => 'idle'], json_decode($row['detail'], true), 'The type-specific detail is stored as submitted');

		$stored = new \DateTimeImmutable($row['observed_at']);
		self::assertSame((new \DateTimeImmutable($submitted))->getTimestamp(), $stored->getTimestamp(),
			'The stored observation is the instant the offset named');
		self::assertSame('2026-09-08T12:30:00+00:00', $stored->setTimezone(new \DateTimeZone('UTC'))->format('c'),
			'A +02:00 observation is 12:30 UTC; reading 14:30 would mean the offset had been dropped');
	}

	public function testDecodedScanEvidenceRecordsWhatWasRead(): void
	{
		[, $attempt] = self::attempt(self::$worker);
		$row = self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, self::deviceStatusEvidence([
			'evidence_type' => 'decoded_scan', 'printer_status' => null, 'decoded_uid' => self::LABEL_UID,
			'confidence' => 0.95, 'detail' => ['scanner' => 'handheld']])));

		self::assertSame(self::LABEL_UID, $row['decoded_uid'], 'The uid that was scanned off the label is recorded');
		self::assertSame(0.95, (float)$row['confidence'], 'The reported confidence is recorded');
	}

	public function testEvidenceForAnAttemptThisWorkerDoesNotHoldIsRefused(): void
	{
		[, $attempt] = self::attempt(self::$worker);
		$other = self::secondWorker();
		$before = (int)self::$db->query('SELECT COUNT(*) FROM print_evidence')->fetchColumn();

		self::assertRefused(static fn () => self::tx(static fn () => self::evidence()->Submit($other, $attempt, self::deviceStatusEvidence())),
			'attempt_id', 'forbidden', 'An observation about somebody else\'s print');
		self::assertRefused(static fn () => self::tx(static fn () => self::evidence()->Submit(self::$worker, 987654, self::deviceStatusEvidence())),
			'attempt_id', 'forbidden', 'An observation about an attempt that does not exist');

		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM print_evidence')->fetchColumn(),
			'A refused observation is not filed');
	}

	/**
	 * @return array<string, array{0: array, 1: string}> the overrides that spoil the
	 *         observation, and the field the refusal must name
	 */
	public static function rejectedEvidence(): array
	{
		return [
			'no submission id' => [['submission_id' => null], 'submission_id'],
			'an empty submission id' => [['submission_id' => ''], 'submission_id'],
			'no observation type' => [['evidence_type' => null], 'evidence_type'],
			'no observation time' => [['observed_at' => null], 'observed_at'],
			'an observation time that is not a time' => [['observed_at' => 'yesterday afternoon-ish'], 'observed_at'],
			'no detail' => [['detail' => null], 'detail'],
			'empty detail' => [['detail' => []], 'detail'],
			'an observation type the server does not understand' => [['evidence_type' => 'photograph'], 'evidence_type'],
			'an image reference, which is not implemented' => [['image_ref' => 'shelf.png'], 'image_ref'],
			'a device status with no status' => [['printer_status' => null], 'printer_status'],
			'a decoded scan with no uid' => [['evidence_type' => 'decoded_scan', 'decoded_uid' => null], 'decoded_uid'],
			'a confidence above one' => [['confidence' => 1.5], 'confidence'],
			'a confidence below zero' => [['confidence' => -0.1], 'confidence'],
			'a confidence that is not a number' => [['confidence' => 'high'], 'confidence'],
		];
	}

	#[DataProvider('rejectedEvidence')]
	public function testRejectedEvidenceFilesNothing(array $overrides, string $field): void
	{
		[, $attempt] = self::attempt(self::$worker);
		$before = (int)self::$db->query('SELECT COUNT(*) FROM print_evidence')->fetchColumn();

		self::assertRefused(
			static fn () => self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, self::deviceStatusEvidence($overrides))),
			$field, 'value_out_of_range', 'An observation that is not one');

		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM print_evidence')->fetchColumn(),
			'A refused observation is not filed');
	}

	/**
	 * A submission id is the worker's own idempotency key, so the same id carrying a
	 * different observation is a contradiction rather than a second record of the same one.
	 */
	public function testSameSubmissionIdWithADifferentObservationIsRefused(): void
	{
		[, $attempt] = self::attempt(self::$worker);
		$evidence = self::deviceStatusEvidence();
		$first = self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, $evidence));

		$changed = array_merge($evidence, ['detail' => ['state' => 'out of paper']]);
		self::assertRefused(static fn () => self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, $changed)),
			'submission_id', 'conflicting_evidence', 'A submission id reused for a different observation');

		self::assertSame(['state' => 'idle'], json_decode(
			(string)self::$db->query('SELECT detail FROM print_evidence WHERE id = ' . (int)$first['id'])->fetchColumn(), true),
			'The original observation is not overwritten by the contradicting one');
	}

	// --- PrinterStatusService --------------------------------------------------------------

	private static function statusReport(): PrinterStatusService
	{
		return new PrinterStatusService(self::$db);
	}

	/**
	 * One row per printer, replaced in place: what the last report said, and when it was
	 * last heard from.
	 */
	public function testPrinterStatusIsReportedAndThenReplacedInPlace(): void
	{
		$report = ['device_state' => 'idle', 'reported_media' => ['media' => '62red'],
			'error_state' => [], 'warning_state' => ['low_battery'], 'vendor' => ['firmware' => '1.2.3']];
		$row = self::tx(static fn () => self::statusReport()->Report(self::$worker, self::$printer, $report));

		self::assertSame(self::$printer, (int)$row['printer_id'], 'The status belongs to the printer it was reported for');
		self::assertSame(self::$worker, (int)$row['reported_by_worker_id'], 'The status records which worker saw it');
		self::assertSame('idle', $row['device_state'], 'The device state is recorded');
		self::assertSame(['media' => '62red'], json_decode($row['reported_media'], true), 'The media the device reports is recorded');
		self::assertSame(['low_battery'], json_decode($row['warning_state'], true), 'Warnings are recorded');
		self::assertEquals($report, json_decode($row['raw_report'], true), 'The whole report is kept, including anything the driver added');

		$second = self::tx(static fn () => self::statusReport()->Report(self::$worker, self::$printer,
			['device_state' => 'printing', 'error_state' => ['cover_open']]));
		self::assertSame('printing', $second['device_state'], 'A later report replaces the earlier one');
		self::assertSame(['cover_open'], json_decode($second['error_state'], true), 'The new error state replaces the old');
		self::assertNull(json_decode((string)$second['reported_media'], true), 'A report that says nothing about media leaves nothing behind');
		self::assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM label_printer_status WHERE printer_id = ' . self::$printer)->fetchColumn(),
			'A printer has one current status, not a pile of them');
	}

	public function testPrinterStatusRefusals(): void
	{
		$other = self::secondWorker();
		$before = (int)self::$db->query('SELECT COUNT(*) FROM label_printer_status')->fetchColumn();

		self::assertRefused(static fn () => self::tx(static fn () => self::statusReport()->Report(self::$worker, self::$printer, ['device_state' => ['idle']])),
			'device_state', 'value_out_of_range', 'A device state that is not a string');
		self::assertRefused(static fn () => self::tx(static fn () => self::statusReport()->Report($other, self::$printer, ['device_state' => 'idle'])),
			'printer_id', 'forbidden', 'A worker reporting on a printer it is not assigned');

		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM label_printer_status')->fetchColumn(),
			'A refused report records no status');
	}

	// --- The remaining paths -----------------------------------------------------------

	/**
	 * An sfnt declaring more tables than it carries, and one whose name table declares more
	 * records than fit. Both stop at what is really there rather than reading past it.
	 */
	public function testFontWithATruncatedTableDirectoryIsReadAsFarAsItGoes(): void
	{
		$overstated = self::tx(static fn () => (new LabelAssetService(self::$db))
			->Store('Overstated Tables', 'font', 'font/ttf', self::sfnt(['Victual Overstated'], "\x00\x01\x00\x00", 3, null, 8), 'OFL-1.1', null));
		self::assertSame('Victual Overstated', $overstated['font_family'],
			'A directory that runs out is read as far as it goes rather than past the end of the file');

		$records = self::tx(static fn () => (new LabelAssetService(self::$db))
			->Store('Overstated Records', 'font', 'font/ttf', self::sfntWithOverstatedNameCount(), 'OFL-1.1', null));
		self::assertSame('Vp', $records['font_family'],
			'A name table claiming more records than it carries still yields the family it really names');
	}

	/** A name table whose record count is one more than the records actually present. */
	private static function sfntWithOverstatedNameCount(): string
	{
		// Short enough that the storage cannot be mistaken for the second record the header
		// claims, so the loop runs out of bytes rather than out of records.
		$value = mb_convert_encoding('Vp', 'UTF-16BE', 'UTF-8');
		$table = pack('nnn', 0, 2, 6 + 12) . pack('nnnnnn', 3, 1, 0, 1, strlen($value), 0) . $value;
		$entry = 'name' . pack('N', 0) . pack('N', 28) . pack('N', strlen($table));
		return "\x00\x01\x00\x00" . pack('nnnn', 1, 16, 0, 0) . $entry . $table;
	}

	/**
	 * Verification is about the QR; the rest of the layout is the renderer's business.
	 *
	 * The manifest still records every asset the document pinned, because that is the
	 * provenance an unexplained label is explained from.
	 */
	public function testArtifactForATemplateCarryingTextIsVerifiedOnItsQrAlone(): void
	{
		$input = self::claimFresh(self::$textTemplateVersion);
		$artifact = self::tx(static fn () => self::artifacts()->Accept(
			$input['render_request_id'], (string)$input['generation_token'], self::paint($input), 'fixture-renderer', '0.0.1-fixture'));

		self::assertTrue($artifact['manifest']['validation']['qr_checked'], 'The QR was checked');
		self::assertSame([['id' => self::$fontAssetId, 'name' => self::$fontAssetName,
			'content_digest' => hash('sha256', self::sfnt(['Fixture Sans', 'Bold']))]], $artifact['manifest']['assets'],
			'The manifest pins the exact asset bytes the label was drawn with');
	}

	/**
	 * Plan 27 piece 5: rerendering is never silently substituted for missing bytes, even
	 * when the manifest does not say they were collected.
	 */
	public function testArtifactWhoseBytesHaveGoneRefusesRatherThanRerendering(): void
	{
		$capture = (int)self::tx(static fn () => (new LabelCaptureService(self::$db))
			->Capture('location', 9001, '0BYTESGONE01', ['location.name'], 'en', 'UTC', 9000))['id'];
		[, $artifact] = self::acceptArtifact('production', $capture);

		self::$db->exec("DELETE FROM files WHERE file_group = 'labelartifacts' AND name = " . self::$db->quote($artifact['file_name']));

		self::assertNull(self::artifacts()->Get((int)$artifact['id'])['collected_at'],
			'The manifest still says the artifact was never collected');
		self::assertRefused(static fn () => self::artifacts()->Bytes((int)$artifact['id']),
			'artifact_id', 'artifact_collected', 'An exact reprint whose bytes are no longer there');
	}

	/** A draft that draws an image resolves it by the name the document pins, like a font. */
	public function testDraftDrawingAnImageResolvesItByName(): void
	{
		self::tx(static fn () => (new LabelAssetService(self::$db))->Store('Draft Mark', 'image', 'image/png',
			self::indexedPng([[0, 1], [1, 0]], 2, 2, ['#ffffff', '#000000']), 'CC0-1.0', null));

		self::quiesceQueue();
		$document = self::textDocument();
		$document['elements'][] = array_merge(self::imageElement(), ['asset' => 'Draft Mark']);

		self::tx(static fn () => self::requests()->CreateForDraft(
			self::$qrTemplate, TemplateDocument::Validate($document, 'location')['document'],
			(int)self::$profile['id'], self::$captureId, 9000));

		$input = self::tx(static fn () => self::requests()->Claim());
		$names = array_column($input['assets'], 'name');
		sort($names);
		self::assertSame(['Draft Mark', 'Fixture Sans'], $names, 'Both the pinned font and the drawn image are resolved');
		self::assertSame('image', $input['assets'][array_search('Draft Mark', array_column($input['assets'], 'name'), true)]['asset_kind'],
			'The renderer is told the image is an image');
	}

	/**
	 * DEFECT: a worker reporting full certainty as the JSON integer `1` has its first
	 * submission refused as conflicting with an observation nobody else made.
	 *
	 * `services/Labels/PrintEvidenceService.php:42` compares the value read back - cast to
	 * float, because the column is DOUBLE PRECISION - against the submitted value with
	 * `!==`, so an integer that passed the 0-to-1 range check two lines above can never
	 * equal it. `json_decode('{"confidence":1}', true)` yields an integer, so this is what a
	 * worker that is certain actually sends, and `0.95` and the float `1.0` are both
	 * accepted. The correct behaviour is to accept it; this test pins what happens today,
	 * because application code is out of scope for this work.
	 */
	public function testIntegerConfidenceIsAcceptedAsARepeatedIdenticalSubmission(): void
	{
		[, $attempt] = self::attempt(self::$worker);
		$evidence = self::deviceStatusEvidence(['confidence' => 1.0]);

		self::assertSame(1.0, (float)self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, $evidence))['confidence'],
			'Full confidence written as a float is accepted');

		$evidence['confidence'] = 1;
		self::assertSame(1.0, (float)self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, $evidence))['confidence'],
			'Full confidence written as an integer is accepted as equivalent to the stored 1.0');

		$evidence['confidence'] = 0.95;
		self::assertRefused(
			static fn () => self::tx(static fn () => self::evidence()->Submit(self::$worker, $attempt, $evidence)),
			'submission_id', 'conflicting_evidence',
			'A different confidence value on the same evidence is refused as conflicting');
	}

	// --- IndexedPng: the form reader -------------------------------------------------------

	/** The PNG Paeth predictor, as the format specifies it. */
	private static function paeth(int $a, int $b, int $c): int
	{
		$p = $a + $b - $c;
		$pa = abs($p - $a);
		$pb = abs($p - $b);
		$pc = abs($p - $c);
		if ($pa <= $pb && $pa <= $pc)
		{
			return $a;
		}
		return $pb <= $pc ? $b : $c;
	}

	/**
	 * A bit-depth-2 indexed PNG whose scanlines use the given filter types.
	 *
	 * Written to the PNG specification rather than to whatever the reader happens to
	 * accept: a reader that only handled filter 0 would be correct against this fixture's
	 * own output and wrong against every other encoder's.
	 *
	 * @param array<int, array<int, int>> $pixels
	 * @param array<int, int> $filters one filter type per row
	 */
	private static function filteredIndexedPng(array $pixels, int $width, int $height, array $palette, array $filters): string
	{
		$stride = intdiv($width + 3, 4);
		$lines = [];
		foreach ($pixels as $row)
		{
			$line = array_fill(0, $stride, 0);
			foreach ($row as $x => $index)
			{
				$line[intdiv($x, 4)] |= ($index & 3) << ((3 - ($x % 4)) * 2);
			}
			$lines[] = $line;
		}

		$raw = '';
		$previous = array_fill(0, $stride, 0);
		foreach ($lines as $y => $line)
		{
			$filter = $filters[$y];
			$encoded = chr($filter);
			for ($i = 0; $i < $stride; $i++)
			{
				$a = $i > 0 ? $line[$i - 1] : 0;
				$b = $previous[$i];
				$c = $i > 0 ? $previous[$i - 1] : 0;
				$encoded .= chr(match ($filter)
				{
					0 => $line[$i],
					1 => ($line[$i] - $a) & 0xFF,
					2 => ($line[$i] - $b) & 0xFF,
					3 => ($line[$i] - intdiv($a + $b, 2)) & 0xFF,
					4 => ($line[$i] - self::paeth($a, $b, $c)) & 0xFF,
				});
			}
			$previous = $line;
			$raw .= $encoded;
		}

		$plte = '';
		foreach ($palette as $colour)
		{
			$plte .= hex2bin(substr($colour, 1));
		}
		return "\x89PNG\r\n\x1a\n"
			. self::pngChunk('IHDR', pack('NN', $width, $height) . chr(2) . chr(3) . chr(0) . chr(0) . chr(0))
			. self::pngChunk('PLTE', $plte)
			. self::pngChunk('IDAT', gzcompress($raw, 9))
			. self::pngChunk('IEND', '');
	}

	/** @return array<int, array<int, int>> a 6x5 grid of palette indices with no symmetry to hide a filter bug */
	private static function sampleGrid(): array
	{
		$pixels = [];
		for ($y = 0; $y < 5; $y++)
		{
			$row = [];
			for ($x = 0; $x < 6; $x++)
			{
				$row[] = ($x * 3 + $y * 2 + ($x === $y ? 1 : 0)) % 3;
			}
			$pixels[] = $row;
		}
		return $pixels;
	}

	/**
	 * Every PNG filter type reconstructs to the same indices.
	 *
	 * Verification reads palette *indices*, so the reconstruction has to be right: an
	 * artifact from a conformant encoder that happened to choose Paeth must decode to the
	 * same picture as the same artifact written unfiltered.
	 */
	public function testEveryPngFilterReconstructsTheSameIndices(): void
	{
		$pixels = self::sampleGrid();
		$palette = ['#ffffff', '#000000', '#ff0000'];

		foreach ([[0, 0, 0, 0, 0], [1, 1, 1, 1, 1], [2, 2, 2, 2, 2], [3, 3, 3, 3, 3], [4, 4, 4, 4, 4], [0, 1, 2, 3, 4]] as $filters)
		{
			$bytes = self::filteredIndexedPng($pixels, 6, 5, $palette, $filters);
			$facts = IndexedPng::Facts($bytes);
			self::assertSame(['width' => 6, 'height' => 5, 'bit_depth' => 2, 'color_type' => 3, 'palette' => $palette], $facts,
				'The facts are read out of the chunks: ' . implode(',', $filters));
			self::assertSame($pixels, IndexedPng::Pixels($bytes, $facts),
				'The picture is the same whichever filters the encoder chose: ' . implode(',', $filters));
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string}> bytes that are not this form, and
	 *         the part of the message that says why
	 */
	public static function malformedRasters(): array
	{
		$ihdr = pack('NN', 4, 4) . chr(2) . chr(3) . chr(0) . chr(0) . chr(0);
		$chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
		$signature = "\x89PNG\r\n\x1a\n";

		return [
			'bytes that are not a PNG at all' => ['a label, but not a picture', 'not a PNG'],
			'bytes too short to carry a signature' => ['\x89PNG', 'not a PNG'],
			'a chunk whose declared length runs past the file' => [
				$signature . pack('N', 4096) . 'IDAT' . 'four', 'truncated'],
			'a palette that is not a whole number of colours' => [
				$signature . $chunk('IHDR', $ihdr) . $chunk('PLTE', 'ffff') . $chunk('IEND', ''), 'multiple of three'],
			'an image with no header chunk' => [
				$signature . $chunk('PLTE', hex2bin('ffffff000000')) . $chunk('IEND', ''), 'no IHDR'],
			'an image with no palette' => [
				$signature . $chunk('IHDR', $ihdr) . $chunk('IEND', ''), 'carries no palette'],
		];
	}

	#[DataProvider('malformedRasters')]
	public function testRasterThatIsNotThisFormIsRefused(string $bytes, string $because): void
	{
		try
		{
			IndexedPng::Facts($bytes);
			self::fail('Expected these bytes to be refused as not being raster/png-indexed;v=1');
		}
		catch (LabelValidationException $error)
		{
			self::assertSame('invalid_artifact', $error->errorCode, 'Bytes that are not this form are refused as an invalid artifact');
			self::assertStringContainsString($because, $error->getMessage(), 'The refusal says which part of the form was missing');
		}
	}

	public function testRasterWhosePixelsCannotBeReadIsRefused(): void
	{
		$palette = ['#ffffff', '#000000'];
		$facts = ['width' => 4, 'height' => 4, 'bit_depth' => 2, 'color_type' => 3, 'palette' => $palette];
		$signature = "\x89PNG\r\n\x1a\n";
		$ihdr = pack('NN', 4, 4) . chr(2) . chr(3) . chr(0) . chr(0) . chr(0);

		$notCompressed = $signature . self::pngChunk('IHDR', $ihdr) . self::pngChunk('PLTE', hex2bin('ffffff000000'))
			. self::pngChunk('IDAT', 'not a deflate stream') . self::pngChunk('IEND', '');
		self::assertRefused(static fn () => IndexedPng::Pixels($notCompressed, $facts), 'artifact', 'invalid_artifact',
			'Image data that does not inflate');

		$twoRows = $signature . self::pngChunk('IHDR', $ihdr) . self::pngChunk('PLTE', hex2bin('ffffff000000'))
			. self::pngChunk('IDAT', gzcompress(str_repeat(chr(0) . chr(0), 2), 9)) . self::pngChunk('IEND', '');
		self::assertRefused(static fn () => IndexedPng::Pixels($twoRows, $facts), 'artifact', 'invalid_artifact',
			'An image with fewer scanlines than its header declares');

		$badFilter = $signature . self::pngChunk('IHDR', $ihdr) . self::pngChunk('PLTE', hex2bin('ffffff000000'))
			. self::pngChunk('IDAT', gzcompress(str_repeat(chr(5) . chr(0), 4), 9)) . self::pngChunk('IEND', '');
		self::assertRefused(static fn () => IndexedPng::Pixels($badFilter, $facts), 'artifact', 'invalid_artifact',
			'A scanline filtered by a type the format does not define');
	}

	// --- SettingsSchemaValidator -----------------------------------------------------------

	/**
	 * The evaluation boundary: a driver settings schema outside the supported subset is
	 * refused as unevaluable rather than half-applied, because a schema that was only
	 * partly enforced would admit settings nobody checked.
	 *
	 * @return array<string, array{0: string, 1: string}> the schema as JSON, and the path
	 *         the refusal must name
	 */
	public static function unevaluableSchemas(): array
	{
		$root = static fn (string $properties, string $extra = ''): string =>
			'{"type":"object","additionalProperties":false' . $extra . ',"properties":{' . $properties . '}}';

		return [
			'a schema that is not an object' => ['["object"]', ''],
			'a root that is not an object schema' => ['{"type":"array"}', ''],
			'a root that admits unknown properties' => ['{"type":"object","properties":{}}', ''],
			'a dialect this evaluator does not implement' => [$root('', ',"$schema":"https://example.invalid/schema"'), ''],
			'a nested object constraint' => [$root('"media":{"type":"string","required":["x"]}'), '/properties/media'],
			'required naming a property that is not declared' => [$root('"media":{"type":"string"}', ',"required":["absent"]'), ''],
			'a numeric bound that is not a number' => [$root('"dpi":{"type":"integer","minimum":"one"}'), '/properties/dpi'],
			'a negative length bound' => [$root('"media":{"type":"string","minLength":-1}'), '/properties/media'],
			'an annotation that is not a string' => [$root('"media":{"type":"string","title":7}'), '/properties/media'],
			'an empty enumeration' => [$root('"media":{"type":"string","enum":[]}'), '/properties/media'],
			'a keyword outside the supported subset' => [$root('"media":{"type":"string","oneOf":[]}'), '/properties/media/oneOf'],
			'nested properties that are not an object' => [$root('"media":{"type":"string","properties":null}'), '/properties/media'],
		];
	}

	#[DataProvider('unevaluableSchemas')]
	public function testUnevaluableSettingsSchemaIsRefused(string $json, string $path): void
	{
		self::assertRefused(static fn () => (new SettingsSchemaValidator())->AssertWithinSubset(json_decode($json)),
			$path, 'schema_unevaluable', 'A settings schema outside the evaluable subset');
	}

	/** The control: the schema a real driver ships is inside the subset. */
	public function testRealDriverSettingsSchemaIsInsideTheSubset(): void
	{
		$schema = json_decode(json_encode(self::driverDefinition()['settings_schemas'][0]['schema']));
		(new SettingsSchemaValidator())->AssertWithinSubset($schema);
		self::assertNull((new SettingsSchemaValidator())->Validate((object)self::printerDefinition()['settings'], $schema),
			'Settings that satisfy the driver schema are accepted');
	}

	// --- DriverRegistryService -------------------------------------------------------------

	private static function drivers(): DriverRegistryService
	{
		return new DriverRegistryService(self::$db);
	}

	/** The shipped driver definition with one thing about it changed. */
	private static function driverWith(callable $mutate): array
	{
		$definition = self::driverDefinition();
		$mutate($definition);
		return $definition;
	}

	/**
	 * A capability document is a contract a printer's geometry is derived from, so every way
	 * it can fail to be one is refused by name rather than registered and discovered later.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function malformedDriverDefinitions(): array
	{
		$many = self::driverDefinition();
		$many['settings_schemas'] = array_fill(0, 257, $many['settings_schemas'][0]);

		return [
			'a driver with no identity' => [self::driverWith(static function (array &$d): void { unset($d['driver_id']); }), 'driver_id'],
			'a driver with an empty schema version' => [self::driverWith(static function (array &$d): void { $d['schema_version'] = ''; }), 'schema_version'],
			'an unsupported capability contract' => [self::driverWith(static function (array &$d): void { $d['contract_version'] = 2; }), 'contract_version'],
			'a capability document listing no models' => [self::driverWith(static function (array &$d): void { $d['capability_document']['models'] = []; }), 'models'],
			'an axis with no settings binding' => [self::driverWith(static function (array &$d): void { unset($d['combination_binding']['media']); }), 'combination_binding'],
			'no discriminator schemas at all' => [self::driverWith(static function (array &$d): void { $d['settings_schemas'] = []; }), 'settings_schemas'],
			'more discriminator schemas than the bound' => [$many, 'settings_schemas'],
			'a schema that does not bind every discriminator' => [self::driverWith(static function (array &$d): void { $d['settings_schemas'][0]['when'] = ['model' => 'QL-820NWBc']; }), 'settings_schemas'],
			'a combination naming a model the driver does not list' => [self::driverWith(static function (array &$d): void { $d['capability_document']['combinations'][0]['model'] = 'QL-999'; }), 'combinations'],
			'a resolution that is not positive' => [self::driverWith(static function (array &$d): void { $d['capability_document']['combinations'][0]['resolution_x'] = 0; }), 'resolution_x'],
			'a media length that is neither fixed nor a range' => [self::driverWith(static function (array &$d): void { $d['capability_document']['combinations'][0]['printable_length_um'] = ['min' => 5, 'max' => 1]; }), 'printable_length_um'],
			'a combination that does not say where it came from' => [self::driverWith(static function (array &$d): void { unset($d['capability_document']['combinations'][0]['provenance']); }), 'provenance'],
			'an artifact form selector naming an unknown axis' => [self::driverWith(static function (array &$d): void { $d['capability_document']['artifact_forms'][0]['applies_to'][0]['nonsense'] = 1; }), 'artifact_forms'],
			'an artifact form selector matching no combination' => [self::driverWith(static function (array &$d): void { $d['capability_document']['artifact_forms'][0]['applies_to'] = [['connection_type' => 'usb']]; }), 'artifact_forms'],
			'an artifact form with no geometry' => [self::driverWith(static function (array &$d): void { unset($d['capability_document']['artifact_forms'][0]['geometry']); }), 'artifact_forms'],
			'completion evidence the contract does not define' => [self::driverWith(static function (array &$d): void { $d['capability_document']['completion_evidence'][0]['evidence'] = 'the operator seemed happy'; }), 'completion_evidence'],
			'no profile contract versions' => [self::driverWith(static function (array &$d): void { $d['profile_contract_versions'] = []; }), 'profile_contract_versions'],
			'a settings schema outside the evaluable subset' => [self::driverWith(static function (array &$d): void { $d['settings_schemas'][0]['schema']['properties']['media']['oneOf'] = []; }), '/properties/media/oneOf'],
		];
	}

	#[DataProvider('malformedDriverDefinitions')]
	public function testMalformedDriverDefinitionIsRefusedByName(array $definition, string $field): void
	{
		$code = str_starts_with($field, '/') ? 'schema_unevaluable' : 'invalid_definition';
		self::assertRefused(static fn () => self::drivers()->ValidateDefinition($definition), $field, $code,
			'A driver definition that is not a capability contract');
	}

	/** The control: the definition the fixture printer is configured against validates. */
	public function testShippedDriverDefinitionValidates(): void
	{
		self::drivers()->ValidateDefinition(self::driverDefinition());
		self::assertTrue(true, 'A real driver definition is accepted');
	}

	public function testDriverRegistrationRefusals(): void
	{
		$worker = self::secondWorker();
		$before = (int)self::$db->query('SELECT COUNT(*) FROM label_drivers')->fetchColumn();

		self::assertRefused(static fn () => self::tx(static fn () => self::drivers()->Register($worker, [
			self::driverWith(static function (array &$d): void { $d['driver_id'] = 'fixture.duplicate'; }),
			self::driverWith(static function (array &$d): void { $d['driver_id'] = 'fixture.duplicate'; }),
		])), 'drivers', 'duplicate_driver', 'One registration naming the same driver version twice');

		// A definition malformed in a way that is not a refusal but an outright type error
		// still arrives as a refusal, rather than as a 500 from inside a worker's own upload.
		self::assertRefused(static fn () => self::tx(static fn () => self::drivers()->Register($worker, [
			self::driverWith(static function (array &$d): void { $d['capability_document']['combinations'] = ['a string, not a combination']; }),
		])), 'drivers', 'invalid_definition', 'A definition whose shape breaks the validator outright');

		self::$db->exec('UPDATE label_workers SET active = 0 WHERE id = ' . $worker);
		self::assertRefused(static fn () => self::tx(static fn () => self::drivers()->Register($worker, [self::driverDefinition()])),
			'worker', 'inactive_worker', 'A deactivated worker registering drivers');

		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM label_drivers')->fetchColumn(),
			'None of those registrations wrote a driver');
	}

	// --- The shared service contract -------------------------------------------------------

	/**
	 * Transaction ownership stays with the caller, so a write called outside one is a
	 * programming error rather than a silently autocommitted row.
	 */
	public function testLabelServiceWriteOutsideATransactionIsALogicError(): void
	{
		$this->expectException(\LogicException::class);
		(new PrinterStatusService(self::$db))->Report(self::$worker, self::$printer, ['device_state' => 'idle']);
	}

	public function testMediaProfileThatDoesNotExistIsRefused(): void
	{
		self::assertRefused(static fn () => (new MediaProfileService(self::$db))->Get(987654),
			'profile_id', 'not_found', 'A media profile id nothing derived');
	}

	/** A payload no QR version can hold is refused rather than truncated onto a label. */
	public function testPayloadThatDoesNotEncodeAsAQrIsRefused(): void
	{
		self::assertRefused(static fn () => QrMatrix::For(str_repeat('VCTL:0ABCDEFGH1234 ', 400), 'H'),
			'qr', 'qr_encoding_failed', 'A payload larger than any QR symbol');
		self::assertSame('VCTL:0ABCDEFGH1234', QrMatrix::Payload('0abcdefgh1234'),
			'A label payload is the uid in the casing ADR-0011 fixes, under the fork prefix');
	}

	// --- LabelTemplateService --------------------------------------------------------------

	private static function templates(): LabelTemplateService
	{
		return new LabelTemplateService(self::$db);
	}

	/**
	 * Publishing is where a document stops being editable, so it refuses about the template
	 * rather than about the draft: a template nothing points at, one that has been archived,
	 * and one pinning a font nobody uploaded.
	 */
	public function testTemplateLifecycleRefusals(): void
	{
		self::assertRefused(static fn () => self::tx(static fn () => self::templates()->Create('   ', null, 'location', 9000)),
			'name', 'invalid_value', 'A template with no name');
		self::assertRefused(static fn () => self::templates()->GetDraft(987654),
			'template_id', 'not_found', 'Reading the draft of a template that does not exist');
		self::assertRefused(static fn () => self::tx(static fn () => self::templates()->SaveDraft(987654, self::qrOnlyDocument(), 'token', 9000)),
			'template_id', 'not_found', 'Saving the draft of a template that does not exist');
		self::assertRefused(static fn () => self::tx(static fn () => self::templates()->Publish(987654, 9000)),
			'template_id', 'not_found', 'Publishing a template that does not exist');

		$unpinnable = (int)self::tx(static fn () => self::templates()->Create('Fixture unpinnable label', null, 'location', 9000))['id'];
		$document = self::textDocument();
		$document['elements'][1]['font_asset'] = 'A Font Nobody Uploaded';
		$draft = self::templates()->GetDraft($unpinnable);
		self::tx(static fn () => self::templates()->SaveDraft($unpinnable, $document, $draft['revision_token'], 9000));
		self::assertRefused(static fn () => self::tx(static fn () => self::templates()->Publish($unpinnable, 9000)),
			'assets', 'asset_unavailable', 'Publishing a document pinning a font that is not stored');
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM label_template_versions WHERE template_id = ' . $unpinnable)->fetchColumn(),
			'A refused publication creates no version');
	}

	/**
	 * The default pointer is administrative: it decides what a new print uses and cannot
	 * touch anything that already pinned a version. Archiving stops further publication.
	 */
	public function testDefaultVersionPointerAndArchival(): void
	{
		$template = (int)self::tx(static fn () => self::templates()->Create('Fixture versioned label', null, 'location', 9000))['id'];
		$draft = self::templates()->GetDraft($template);
		self::tx(static fn () => self::templates()->SaveDraft($template, self::qrOnlyDocument(), $draft['revision_token'], 9000));
		$first = (int)self::tx(static fn () => self::templates()->Publish($template, 9000))['id'];

		$draft = self::templates()->GetDraft($template);
		self::tx(static fn () => self::templates()->SaveDraft($template, self::withQr(['ec_level' => 'H']), $draft['revision_token'], 9000));
		$second = (int)self::tx(static fn () => self::templates()->Publish($template, 9000))['id'];

		self::assertSame($first, (int)self::templates()->ResolveVersion($template, null)['id'],
			'The first published version stays the default until somebody moves it');
		self::assertSame($second, (int)self::templates()->ResolveVersion($template, $second)['id'],
			'A named version resolves to itself whatever the default says');
		self::assertSame('H', self::templates()->ResolveVersion($template, $second)['document']['elements'][0]['ec_level'],
			'The resolved version carries its own document');

		self::assertSame($second, (int)self::tx(static fn () => self::templates()->SetDefaultVersion($template, $second))['default_version_id'],
			'The default moves to the named version');
		self::assertSame($second, (int)self::templates()->ResolveVersion($template, null)['id'], 'New prints follow the moved default');

		self::assertRefused(static fn () => self::tx(static fn () => self::templates()->SetDefaultVersion($template, self::$qrTemplateVersion)),
			'version_id', 'not_found', 'Pointing a template at another template\'s version');

		self::assertNotNull(self::tx(static fn () => self::templates()->Archive($template))['archived_at'], 'Archiving records when it happened');
		self::assertRefused(static fn () => self::tx(static fn () => self::templates()->Publish($template, 9000)),
			'template_id', 'archived', 'Publishing from an archived template');
		self::assertRefused(static fn () => self::templates()->ResolveVersion($template, null),
			'template_version_id', 'unsupported_version', 'Resolving the default of an archived template');
		self::assertSame($second, (int)self::templates()->ResolveVersion($template, $second)['id'],
			'Negative control: an artifact that already pinned a version can still resolve it');
	}

	// --- PrinterConfigurationService -------------------------------------------------------

	private static function printers(): PrinterConfigurationService
	{
		return new PrinterConfigurationService(self::$db);
	}

	/**
	 * A printer is only saved once its settings have been evaluated against the driver's own
	 * schema and resolved to exactly one supported combination.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string}>
	 */
	public static function rejectedPrinters(): array
	{
		$with = static function (callable $mutate): array
		{
			$printer = ['name' => 'Rejected printer', 'worker_id' => 0, 'driver_id' => 'brother.ql',
				'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
				'model' => 'QL-820NWBc',
				'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red']];
			$mutate($printer);
			return $printer;
		};

		return [
			'a driver version nothing registered' => [$with(static function (array &$p): void { $p['driver_schema_version'] = '9.9'; }), 'driver_schema_version', 'unknown_driver_version'],
			'no name' => [$with(static function (array &$p): void { $p['name'] = '  '; }), 'name', 'value_out_of_range'],
			'no connection' => [$with(static function (array &$p): void { $p['connection'] = ''; }), 'connection', 'value_out_of_range'],
			'a worker that does not exist' => [$with(static function (array &$p): void { $p['worker_id'] = 987654; }), 'worker_id', 'value_out_of_range'],
			'settings that are not an object' => [$with(static function (array &$p): void { $p['settings'] = 'media=62red'; }), 'settings', 'value_out_of_range'],
			'a discriminator tuple no schema matches' => [$with(static function (array &$p): void { $p['settings']['media'] = '62black'; }), 'settings', 'schema_unevaluable'],
			'settings the driver schema rejects' => [$with(static function (array &$p): void { $p['settings']['resolution_x'] = 9000; }), 'resolution_x', 'value_out_of_range'],
			'a setting the driver schema does not declare' => [$with(static function (array &$p): void { $p['settings']['dither'] = 'floyd'; }), 'dither', 'unknown_property'],
		];
	}

	#[DataProvider('rejectedPrinters')]
	public function testRejectedPrinterIsNotSaved(array $printer, string $field, string $code): void
	{
		if ($printer['worker_id'] === 0)
		{
			$printer['worker_id'] = self::$worker;
		}
		$before = (int)self::$db->query('SELECT COUNT(*) FROM label_printers')->fetchColumn();

		self::assertRefused(static fn () => self::tx(static fn () => self::printers()->Save($printer)), $field, $code,
			'A printer configuration that does not resolve');
		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM label_printers')->fetchColumn(),
			'A refused configuration saves no printer');
	}

	/**
	 * Saving over an existing printer, and the two things that may not change quietly: the
	 * driver schema version, and whether the row is a flag or a number.
	 */
	public function testPrinterUpdateAndItsGuards(): void
	{
		$printer = self::printerDefinition();
		$printer['name'] = 'Second fixture printer';
		$id = self::tx(static fn () => self::printers()->Save($printer));

		$renamed = array_merge($printer, ['name' => 'Renamed printer', 'description' => 'By the back door']);
		self::assertSame($id, self::tx(static fn () => self::printers()->Save($renamed, $id)), 'An update keeps the printer id');
		self::assertSame('Renamed printer', self::$db->query('SELECT name FROM label_printers WHERE id = ' . $id)->fetchColumn(),
			'The stored row carries the new name');

		self::assertRefused(static fn () => self::tx(static fn () => self::printers()->Save($printer, 987654)),
			'printer_id', 'not_found', 'Updating a printer that does not exist');
		self::assertRefused(static fn () => self::tx(static fn () => self::printers()
			->Save(array_merge($printer, ['driver_schema_version' => '9.9']), $id)),
			'driver_schema_version', 'explicit_move_required', 'Moving a printer to another driver version by the ordinary save');
		self::assertRefused(static fn () => self::tx(static fn () => self::printers()->Save(array_merge($printer, ['active' => 2]))),
			'active', 'value_out_of_range', 'A flag that is not a flag');

		self::assertSame($id, (int)self::printers()->Resolve($id)['printer']['id'], 'An active printer resolves');
		self::$db->exec('UPDATE label_printers SET active = 0 WHERE id = ' . $id);
		self::assertRefused(static fn () => self::printers()->Resolve($id),
			'printer_id', 'inactive_printer', 'Resolving a deactivated printer');
	}

	/**
	 * A combination two completion-evidence entries both claim is ambiguous: whether a print
	 * is finished when the bytes are sent or when the device says so is not something to
	 * pick arbitrarily.
	 */
	public function testPrinterWhoseCompletionEvidenceIsAmbiguousIsRefused(): void
	{
		$definition = self::driverWith(static function (array &$d): void
		{
			$d['driver_id'] = 'fixture.ambiguous';
			$d['capability_document']['completion_evidence'][] = ['evidence' => 'transport',
				'applies_to' => [['connection_type' => 'tcp']], 'provenance' => 'advertised'];
		});
		// Registered under a worker of its own: Register() replaces a worker's whole
		// advertised set, and the fixture worker's must survive this test.
		$worker = self::secondWorker();
		self::tx(static fn () => self::drivers()->Register($worker, [$definition]));

		$printer = array_merge(self::printerDefinition(), ['name' => 'Ambiguous printer',
			'worker_id' => $worker, 'driver_id' => 'fixture.ambiguous']);
		self::assertRefused(static fn () => self::tx(static fn () => self::printers()->Save($printer)),
			'settings', 'unsupported_combination', 'A combination whose completion evidence is not unique');
	}

	// --- IdempotencyService ----------------------------------------------------------------

	/**
	 * A key is optional, bounded in shape, and never a capability: a reservation that never
	 * finished answers "in progress" rather than repeating work that could produce a second
	 * physical label.
	 */
	public function testIdempotencyKeyShapeAndUnfinishedReservations(): void
	{
		$keys = new \Victual\Services\Labels\IdempotencyService(self::$db);
		$request = ['location_id' => 9001, 'printer_id' => self::$printer];

		self::assertSame(['replay' => false, 'row' => null], self::tx(static fn () => $keys->Begin(9000, 'issue', null, $request)),
			'A caller that sent no key gets no replay and no reservation');
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM label_idempotency_keys')->fetchColumn(),
			'No key means no row to expire later');
		self::tx(static fn () => $keys->Record(9000, 'issue', null, 'print_job', 1, ['id' => 1]));
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM label_idempotency_keys')->fetchColumn(),
			'Recording against no key writes nothing');

		self::assertRefused(static fn () => self::tx(static fn () => $keys->Begin(9000, 'issue', 'short', $request)),
			'idempotency_key', 'invalid_value', 'A key too short to be one');
		self::assertRefused(static fn () => self::tx(static fn () => $keys->Begin(9000, 'issue', 'has spaces in it', $request)),
			'idempotency_key', 'invalid_value', 'A key carrying characters the shape does not allow');

		self::assertFalse(self::tx(static fn () => $keys->Begin(9000, 'issue', 'reserved-but-unfinished', $request))['replay'],
			'A new key reserves and is not a replay');
		self::assertRefused(static fn () => self::tx(static fn () => $keys->Begin(9000, 'issue', 'reserved-but-unfinished', $request)),
			'idempotency_key', 'idempotency_in_progress', 'A key whose first attempt never finished');
	}

	// --- PrintJobPayload -------------------------------------------------------------------

	private static function jobPayload(): array
	{
		[, $artifact] = self::acceptArtifact();
		return \Victual\Services\Labels\PrintJobPayload::Build(self::LABEL_UID,
			self::captures()->Get(self::$captureId), self::$printer, 'issue', $artifact,
			['template_id' => self::$qrTemplate, 'template_version_id' => self::$qrTemplateVersion, 'document_digest' => str_repeat('a', 64)],
			self::$profile);
	}

	/**
	 * A payload the drainer cannot read is dead-lettered with a reason rather than
	 * reinterpreted, so every way it can be unreadable says which part was wrong.
	 */
	public function testUnreadableJobPayloadSaysWhichPartIsWrong(): void
	{
		$valid = self::jobPayload();
		self::assertNull(\Victual\Services\Labels\PrintJobPayload::DescribeUnreadable($valid),
			'A payload this version wrote is readable');

		$cases = [
			'Unsupported label payload version' => static function (array &$p): void { $p['payload_version'] = 1; },
			'Invalid label uid' => static function (array &$p): void { $p['label_uid'] = 'not-a-uid'; },
			'Invalid label target' => static function (array &$p): void { $p['kind'] = 'spaceship'; },
			'Unknown print operation' => static function (array &$p): void { $p['operation'] = 'improvise'; },
			'Missing captured label name' => static function (array &$p): void { unset($p['captured_fields']['location.name']); },
			'Missing artifact reference' => static function (array &$p): void { unset($p['artifact']['byte_digest']); },
			'Unsupported artifact form' => static function (array &$p): void { $p['artifact']['form'] = 'raster/png;v=99'; },
		];
		foreach ($cases as $expected => $mutate)
		{
			$payload = $valid;
			$mutate($payload);
			self::assertSame($expected, \Victual\Services\Labels\PrintJobPayload::DescribeUnreadable($payload),
				'The drainer says which part of the payload it could not read');
		}
	}

	// --- FieldCatalogue --------------------------------------------------------------------

	/**
	 * Sample data is invented only for previews, and every kind that can be printed has
	 * some - a preview of a kind with no sample would show a blank label.
	 */
	public function testEveryPrintableKindHasSampleData(): void
	{
		foreach (['location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'] as $kind)
		{
			$sample = \Victual\Services\Labels\FieldCatalogue::SampleFor($kind);
			$nameField = $kind === 'stock_entry' ? 'stock_entry.product_name' : $kind . '.name';
			self::assertArrayHasKey($nameField, $sample, "A $kind preview has something to print as its name");
			self::assertSame(array_keys(\Victual\Services\Labels\FieldCatalogue::For($kind)), array_keys($sample),
				"Every $kind field a template may draw has sample data");
		}

		self::assertRefused(static fn () => \Victual\Services\Labels\FieldCatalogue::SampleFor('spaceship'),
			'entity_kind', 'unsupported_entity_kind', 'A kind with no field catalogue');
	}

	// --- LabelOperationsService ------------------------------------------------------------

	private static function operations(): LabelOperationsService
	{
		return new LabelOperationsService(self::$db, null, 9000);
	}

	/** Issues a label, renders it and attaches the artifact - the whole path a job takes. */
	private static function issueAndRender(?int $templateId = null, ?int $printerId = null): array
	{
		self::quiesceQueue();
		$job = self::tx(static fn () => self::operations()->IssueLocation(
			'location', 9001, 0, $printerId ?? self::$printer, $templateId ?? self::$qrTemplate, null, 'en', 'UTC'));

		$input = self::tx(static fn () => self::requests()->Claim());
		$artifact = self::tx(static fn () => self::artifacts()->Accept(
			$input['render_request_id'], (string)$input['generation_token'], self::paint($input), 'fixture-renderer', '0.0.1-fixture'));
		self::tx(static fn () => self::operations()->AttachArtifact((int)$artifact['render_request_id'], (int)$artifact['id']));

		return [self::$db->query('SELECT * FROM print_jobs WHERE id = ' . (int)$job['id'])->fetch(PDO::FETCH_ASSOC), $artifact];
	}

	private static function liveUid(): string
	{
		return (string)self::$db->query("SELECT uid FROM labels WHERE kind = 'location' AND target_id = 9001 AND retired_at IS NULL")->fetchColumn();
	}

	/**
	 * Issuance: the job exists immediately, is not claimable until its artifact is attached,
	 * and captures the entity's name whether or not the template prints it.
	 */
	public function testIssuedJobExistsBeforeItsArtifactDoes(): void
	{
		self::quiesceQueue();
		$job = self::tx(static fn () => self::operations()->IssueLocation(
			'location', 9001, 0, self::$printer, self::$textTemplate, null, 'en', 'UTC'));

		self::assertNull($job['artifact_id'], 'A job exists while its render is still pending');
		self::assertSame('issue', $job['operation'], 'The job records which operation created it');
		self::assertSame(self::liveUid(), $job['label_uid'], 'The job carries the label identity that was minted for the target');

		$capture = self::captures()->Get((int)$job['capture_id']);
		self::assertSame('Pantry', $capture['captured_fields']['location.name'],
			'The capture reads the name the template draws, under the permission the catalogue declares');

		$input = self::tx(static fn () => self::requests()->Claim());
		$artifact = self::tx(static fn () => self::artifacts()->Accept(
			$input['render_request_id'], (string)$input['generation_token'], self::paint($input), 'fixture-renderer', '0.0.1-fixture'));
		self::assertSame(1, self::tx(static fn () => self::operations()->AttachArtifact((int)$artifact['render_request_id'], (int)$artifact['id'])),
			'The finished artifact attaches to the job that was waiting for it');
		self::assertSame((int)$artifact['id'],
			(int)self::$db->query('SELECT artifact_id FROM print_jobs WHERE id = ' . (int)$job['id'])->fetchColumn(),
			'The job now names the bytes it will send');
		self::assertSame('retained', self::artifacts()->Get((int)$artifact['id'])['retention_class'],
			'An artifact a job points at is retained');
	}

	public function testIssuanceRefusals(): void
	{
		$before = (int)self::$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn();

		// The epoch guard: a request composed before an import and executed after it would
		// otherwise capture whatever now holds that id.
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->IssueLocation(
			'location', 9001, 7, self::$printer, self::$qrTemplate, null, 'en', 'UTC')),
			'import_epoch', 'stale_target_context', 'An issuance carrying an import epoch that has moved on');

		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->IssueLocation(
			'location', 9001, 0, self::$monoPrinter, self::$redTemplate, null, 'en', 'UTC')),
			'template_version_id', 'unsupported_combination', 'A template that paints red on a printer that cannot');

		$idle = self::secondWorker();
		$unadvertised = self::tx(static fn () => self::printers()->Save(array_merge(self::printerDefinition(),
			['name' => 'Unadvertised printer', 'worker_id' => $idle])));
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->IssueLocation(
			'location', 9001, 0, $unadvertised, self::$qrTemplate, null, 'en', 'UTC')),
			'printer_id', 'missing_capability', 'A printer whose assigned worker advertises no such driver version');

		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn(),
			'None of those refusals queued a job');
	}

	/**
	 * Two resolution paths that do not name a printer or a template outright: the default
	 * printer, and a version named without its template.
	 */
	public function testDefaultPrinterAndBareVersionResolve(): void
	{
		self::quiesceQueue();
		self::$db->exec('UPDATE label_printers SET is_default = 1 WHERE id = ' . self::$printer);
		try
		{
			$job = self::tx(static fn () => self::operations()->IssueLocation(
				'location', 9001, 0, null, null, self::$qrTemplateVersion, 'en', 'UTC'));
		}
		finally
		{
			self::$db->exec('UPDATE label_printers SET is_default = 0 WHERE id = ' . self::$printer);
		}
		self::assertSame(self::$qrTemplateVersion, (int)self::requestRow((int)$job['render_request_id'])['template_version_id'],
			'A version named without its template resolves through the version row that names it');
		self::assertSame(self::$printer, (int)$job['printer_id'],
			'A job naming no printer goes to the printer marked default rather than refusing');

		// Each scenario is built inside a transaction that is rolled back, because the
		// fixture printer and templates are shared with every other test in this class.
		self::$db->beginTransaction();
		try
		{
			self::$db->exec('UPDATE label_templates SET archived_at = CURRENT_TIMESTAMP');
			self::assertRefused(static fn () => self::operations()->IssueLocation('location', 9001, 0, self::$printer, null, null, 'en', 'UTC'),
				'template_id', 'no_template', 'Issuing a kind with no published template');
		}
		finally
		{
			self::$db->rollBack();
		}

		self::$db->beginTransaction();
		try
		{
			self::$db->exec('UPDATE label_printers SET active = 0');
			self::assertRefused(static fn () => self::operations()->ResolvePrinter(null),
				'printer_id', 'no_printer', 'Issuing with no active printer configured');
		}
		finally
		{
			self::$db->rollBack();
		}

		self::assertSame(1, (int)self::$db->query('SELECT active FROM label_printers WHERE id = ' . self::$printer)->fetchColumn(),
			'The deactivation was rolled back with the rest of that scenario');
	}

	/**
	 * An exact reprint replays stored bytes: same uid, same capture, same artifact, and no
	 * renderer involved at all.
	 */
	public function testExactReprintReplaysTheStoredArtifact(): void
	{
		[$job, $artifact] = self::issueAndRender();
		$renders = (int)self::$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn();

		$reprint = self::tx(static fn () => self::operations()->Reprint((int)$job['id'], null));
		self::assertSame('reprint', $reprint['operation'], 'The new job says it is a reprint');
		self::assertSame((int)$artifact['id'], (int)$reprint['artifact_id'], 'A reprint replays the stored bytes');
		self::assertSame($job['label_uid'], $reprint['label_uid'], 'A reprint keeps the identity');
		self::assertSame((int)$job['id'], (int)$reprint['source_job_id'], 'A reprint names the job it replays');
		self::assertSame($renders, (int)self::$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn(),
			'A reprint queues no render; the renderer does not need to exist');
	}

	public function testReprintRefusals(): void
	{
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->Reprint(987654, null)),
			'job_id', 'not_found', 'Reprinting a job that does not exist');

		[$job] = self::attempt(self::$worker);
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->Reprint($job, null)),
			'job_id', 'no_artifact', 'Reprinting a job that never had an artifact');

		// A job naming a label the mapping table does not know: the refusal is about the
		// thing, not about the bytes.
		[$printed, $artifact] = self::issueAndRender();
		[$orphan] = self::attempt(self::$worker);
		self::$db->exec('UPDATE print_jobs SET artifact_id = ' . (int)$artifact['id']
			. ", label_uid = '0ZZZZZZZZZZZZ' WHERE id = " . $orphan);
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->Reprint($orphan, null)),
			'label_uid', 'unknown_label', 'Reprinting a job whose label is not known');

		// The stored bytes were produced for two-colour media at 696 px; the monochrome
		// printer is a different profile, and there is no fallback to the nearest size.
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->Reprint((int)$printed['id'], self::$monoPrinter)),
			'printer_id', 'media_incompatible', 'Replaying stored bytes on a printer they were not rendered for');
	}

	/** A revised print keeps the uid and captures again; the earlier artifact is untouched. */
	public function testRevisedPrintKeepsTheIdentityAndCapturesAgain(): void
	{
		[$job] = self::issueAndRender();
		self::$db->exec("UPDATE locations SET name = 'Larder' WHERE id = 9001");

		self::quiesceQueue();
		$revised = self::tx(static fn () => self::operations()->RevisedPrint(
			'location', 9001, 0, self::$printer, self::$qrTemplate, null, 'en', 'UTC'));

		self::assertSame($job['label_uid'], $revised['label_uid'], 'A revised print keeps the identity');
		self::assertNotSame((int)$job['capture_id'], (int)$revised['capture_id'], 'A revised print captures again');
		self::assertSame('Larder', self::captures()->Get((int)$revised['capture_id'])['captured_fields']['location.name'],
			'A revised print carries the current value');
		self::assertSame('Pantry', self::captures()->Get((int)$job['capture_id'])['captured_fields']['location.name'],
			'Negative control: the earlier capture is untouched');

		self::$db->exec("UPDATE locations SET name = 'Pantry' WHERE id = 9001");

		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->RevisedPrint(
			'location', 9002, 0, self::$printer, self::$qrTemplate, null, 'en', 'UTC')),
			'target_id', 'no_live_label', 'A revised print of a target that has no label to revise');
	}

	/**
	 * Promoting a live preview queues the same bytes and the same captured values, after the
	 * server has rechecked everything the print path would have checked.
	 */
	public function testPromotingALivePreview(): void
	{
		self::issueAndRender();
		$uid = self::liveUid();
		$capture = (int)self::tx(static fn () => self::captures()->Capture('location', 9001, $uid, ['location.name'], 'en', 'UTC', 9000))['id'];

		[, $preview] = self::acceptArtifact('preview_live', $capture);
		self::assertSame('preview', $preview['retention_class'], 'The preview starts unpromoted');

		$job = self::tx(static fn () => self::operations()->PromotePreview((int)$preview['id'], self::$printer));
		self::assertSame('promote_preview', $job['operation'], 'The job records that it came from a preview');
		self::assertSame($uid, $job['label_uid'], 'The promoted print carries the preview\'s label identity');
		self::assertSame((int)$preview['id'], (int)$job['artifact_id'], 'The promoted print sends the bytes that were previewed');
		self::assertSame($capture, (int)$job['capture_id'], 'The promoted print carries the values that were previewed');
		self::assertSame('retained', self::artifacts()->Get((int)$preview['id'])['retention_class'],
			'Promotion retains the artifact so the print can be reprinted exactly');
	}

	public function testOnlyAnAuthoritativeLivePreviewCanBePromoted(): void
	{
		[, $production] = self::acceptArtifact('production');
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->PromotePreview((int)$production['id'], self::$printer)),
			'artifact_id', 'not_promotable', 'Promoting something that was never a preview');

		[, $sample] = self::acceptArtifact('preview_live', self::$sampleCaptureId);
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->PromotePreview((int)$sample['id'], self::$printer)),
			'artifact_id', 'not_promotable', 'Promoting a preview built from sample data');
	}

	/**
	 * A cancellation racing a claim produces one truthful outcome and not two, and a job
	 * that already has one is not rewritten as never having happened.
	 */
	public function testCancellationAndItsGuards(): void
	{
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->Cancel(987654, 'never existed')),
			'job_id', 'not_found', 'Cancelling a job that does not exist');

		[$job] = self::issueAndRender();
		$cancelled = self::tx(static fn () => self::operations()->Cancel((int)$job['id'], 'Asked for the wrong shelf'));
		self::assertNotNull($cancelled['cancelled_at'], 'The job records when it was cancelled');
		self::assertSame('Asked for the wrong shelf', $cancelled['cancelled_reason'], 'The job records why');
		self::assertNull($cancelled['outcome'], 'A cancellation is not an outcome; the label never printed');
		self::assertStringContainsString('Cancelled', (string)self::$db->query(
			'SELECT last_error FROM outbox WHERE id = ' . (int)$job['outbox_id'])->fetchColumn(),
			'The outbox entry is dead-lettered with the reason');

		$again = self::tx(static fn () => self::operations()->Cancel((int)$job['id'], 'and again'));
		self::assertSame($cancelled['cancelled_at'], $again['cancelled_at'], 'Cancelling twice does not move the record');
		self::assertSame('Asked for the wrong shelf', $again['cancelled_reason'], 'The first reason is the one that is kept');

		[$finished] = self::issueAndRender();
		self::$db->exec("UPDATE print_jobs SET outcome = 'printed', outcome_at = CURRENT_TIMESTAMP WHERE id = " . (int)$finished['id']);
		self::assertRefused(static fn () => self::tx(static fn () => self::operations()->Cancel((int)$finished['id'], 'too late')),
			'job_id', 'already_completed', 'Cancelling a job that has already printed');
	}

	// --- PrintAttemptService ---------------------------------------------------------------

	private static function attempts(): \Victual\Services\Labels\PrintAttemptService
	{
		return new \Victual\Services\Labels\PrintAttemptService(self::$db);
	}

	public function testLeaseDurationsMustBeCoherent(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new \Victual\Services\Labels\PrintAttemptService(self::$db, 300, 60);
	}

	public function testClaimLimitIsBounded(): void
	{
		self::assertRefused(static fn () => self::tx(static fn () => self::attempts()->Claim(self::$worker, 0)),
			'limit', 'value_out_of_range', 'A claim asking for no work');
		self::assertRefused(static fn () => self::tx(static fn () => self::attempts()->Claim(self::$worker, 51)),
			'limit', 'value_out_of_range', 'A claim asking for more work than the bound');
	}

	/**
	 * A job the drainer cannot make sense of is dead-lettered with the reason rather than
	 * dispatched on a guess - for a payload it cannot read, and for a printer that has gone.
	 */
	public function testJobThatCannotBeDispatchedIsDeadLetteredWithItsReason(): void
	{
		self::$db->exec("UPDATE print_jobs SET outcome = 'dead_lettered' WHERE outcome IS NULL AND cancelled_at IS NULL");

		[$unreadable] = self::issueAndRender();
		self::$db->exec("UPDATE outbox SET payload = '{\"payload_version\": 1}' WHERE id = " . (int)$unreadable['outbox_id']);
		self::assertSame([], self::tx(static fn () => self::attempts()->Claim(self::$worker)),
			'A job whose payload cannot be read is not dispatched');
		self::assertSame('dead_lettered', self::$db->query('SELECT outcome FROM print_jobs WHERE id = ' . (int)$unreadable['id'])->fetchColumn(),
			'It is dead-lettered instead');
		self::assertStringContainsString('Unsupported label payload version', (string)self::$db->query(
			'SELECT last_error FROM outbox WHERE id = ' . (int)$unreadable['outbox_id'])->fetchColumn(),
			'The outbox entry says which part could not be read');

		$printer = self::tx(static fn () => self::printers()->Save(array_merge(self::printerDefinition(), ['name' => 'Doomed printer'])));
		[$stranded] = self::issueAndRender(null, $printer);
		self::$db->exec('UPDATE label_printers SET active = 0 WHERE id = ' . $printer);
		self::assertSame([], self::tx(static fn () => self::attempts()->Claim(self::$worker)),
			'A job whose printer has been deactivated is not dispatched');
		self::assertSame('dead_lettered', self::$db->query('SELECT outcome FROM print_jobs WHERE id = ' . (int)$stranded['id'])->fetchColumn(),
			'It is dead-lettered with the printer refusal');
	}

	/**
	 * The lease a worker holds while it talks to a printer: it extends by heartbeat, belongs
	 * to one worker, and reports one outcome that cannot later be contradicted.
	 */
	public function testAttemptLeaseHeartbeatAndResult(): void
	{
		self::$db->exec("UPDATE print_jobs SET outcome = 'dead_lettered' WHERE outcome IS NULL AND cancelled_at IS NULL");
		self::issueAndRender();

		$claim = self::tx(static fn () => self::attempts()->Claim(self::$worker));
		self::assertCount(1, $claim, 'The job with an attached artifact is claimable');
		$attempt = (int)$claim[0]['attempt']['id'];

		$beat = self::tx(static fn () => self::attempts()->Heartbeat(self::$worker, $attempt));
		self::assertSame(1, (int)$beat['heartbeats'], 'A heartbeat is counted');
		self::assertGreaterThanOrEqual($claim[0]['attempt']['lease_expires_at'], $beat['lease_expires_at'],
			'A heartbeat does not shorten the lease');

		$other = self::secondWorker();
		self::assertRefused(static fn () => self::tx(static fn () => self::attempts()->Heartbeat($other, $attempt)),
			'attempt_id', 'forbidden', 'A worker heartbeating an attempt it does not hold');

		self::assertRefused(static fn () => self::tx(static fn () => self::attempts()->Result(self::$worker, $attempt, 'probably', [])),
			'outcome', 'value_out_of_range', 'A result that is neither printed nor failed');

		self::tx(static fn () => self::attempts()->Result(self::$worker, $attempt, 'printed', ['device' => 'complete']));
		self::assertRefused(static fn () => self::tx(static fn () => self::attempts()->Result(self::$worker, $attempt, 'failed', ['device' => 'jam'])),
			'result', 'conflicting_report', 'A second, different report about a print that already reported');
		self::assertSame('printed', self::$db->query('SELECT reported_outcome FROM print_attempts WHERE id = ' . $attempt)->fetchColumn(),
			'The first report is the one that stands');
	}

	// --- LabelWorkerCredentialService --------------------------------------------------------

	public function testCredentialDurationsMustBePositive(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new LabelWorkerCredentialService(self::$db, 0);
	}

	/**
	 * A worker configured to pair must pair, a worker configured by declaration must not,
	 * and a renderer credential is issued against the same identity under its own key type -
	 * which is what makes "may not claim a print attempt" a property of the credential.
	 */
	public function testCredentialsFollowTheWorkersConfigurationMode(): void
	{
		$credentials = new LabelWorkerCredentialService(self::$db);
		$paired = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('labelservices-modes', 'paired') RETURNING id")->fetchColumn();

		self::assertRefused(static fn () => self::tx(static fn () => $credentials->PairingMaterial(self::$worker, 9000)),
			'worker_id', 'wrong_mode', 'Pairing material for a worker configured by declaration');
		self::assertRefused(static fn () => self::tx(static fn () => $credentials->IssueDeclared($paired, 9000)),
			'worker_id', 'wrong_mode', 'A declared credential for a worker that must pair');

		$renderer = self::tx(static fn () => $credentials->IssueRenderer(self::$worker, 9000));
		self::assertNull($credentials->Authenticate($renderer['credential']),
			'A renderer credential is not accepted as a worker credential');
		self::assertSame(self::$worker, (int)$credentials->Authenticate($renderer['credential'], false,
			\Victual\Services\ApiKeyService::API_KEY_TYPE_LABEL_RENDERER)['worker_id'],
			'A renderer credential authenticates under its own key type');

		$inactive = self::secondWorker();
		self::$db->exec('UPDATE label_workers SET active = 0 WHERE id = ' . $inactive);
		self::assertRefused(static fn () => self::tx(static fn () => $credentials->IssueDeclared($inactive, 9000)),
			'worker_id', 'inactive_worker', 'A credential for a deactivated worker');
	}

	public function testRotationRefusals(): void
	{
		$credentials = new LabelWorkerCredentialService(self::$db);
		$declared = self::tx(static fn () => $credentials->IssueDeclared(self::$worker, 9000));

		self::assertRefused(static fn () => self::tx(static fn () => $credentials->Rotate($declared['credential'], 'not hexadecimal', bin2hex(random_bytes(32)))),
			'rotation_request_id', 'value_out_of_range', 'A rotation request id that is not 32 bytes of hexadecimal');
		self::assertRefused(static fn () => self::tx(static fn () => $credentials->Rotate($declared['credential'], bin2hex(random_bytes(32)), bin2hex(random_bytes(32)))),
			'credential', 'unauthorized', 'Rotating a declared credential, which belongs to no session');

		$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('labelservices-rotations', 'paired') RETURNING id")->fetchColumn();
		$material = self::tx(static fn () => $credentials->PairingMaterial($worker, 9000));
		$first = self::tx(static fn () => $credentials->Pair($material['material']));

		$request = bin2hex(random_bytes(32));
		$secret = bin2hex(random_bytes(32));
		$second = self::tx(static fn () => $credentials->Rotate($first['credential'], $request, $secret));

		// The successor rotating under the request id its predecessor already used: the
		// request belongs to that credential, not to this one.
		self::assertRefused(static fn () => self::tx(static fn () => $credentials->Rotate($second['credential'], $request, bin2hex(random_bytes(32)))),
			'rotation_request_id', 'replay_mismatch', 'A rotation request id that belongs to another credential');

		// A replay of a rotation whose successor has since been revoked cannot rederive it.
		self::$db->exec("UPDATE api_keys SET api_key = 'revoked:' || id::text WHERE id = " . (int)$second['credential_id']);
		self::assertRefused(static fn () => self::tx(static fn () => $credentials->Rotate($first['credential'], $request, $secret)),
			'credential', 'unauthorized', 'Replaying a rotation whose successor was revoked');
	}

	// --- The print job monitor and the byte store ------------------------------------------

	/**
	 * Authorizing another attempt is how a household says "try again" about a physical
	 * print, so it refuses once a job has an outcome: a second attempt at a job that already
	 * printed is a second label.
	 */
	public function testAnotherAttemptIsRefusedOnceAJobHasAnOutcome(): void
	{
		self::$db->exec("UPDATE print_jobs SET outcome = 'dead_lettered' WHERE outcome IS NULL AND cancelled_at IS NULL");
		[$job] = self::issueAndRender();
		$jobs = new \Victual\Services\Labels\LabelPrintJobService(self::$db);

		$claim = self::tx(static fn () => self::attempts()->Claim(self::$worker));
		$attempt = (int)$claim[0]['attempt']['id'];
		self::tx(static fn () => self::attempts()->Result(self::$worker, $attempt, 'printed', ['device' => 'complete']));

		self::assertRefused(static fn () => self::tx(static fn () => $jobs->AuthorizeAnotherAttempt((int)$job['id'], $attempt)),
			'attempt_id', 'already_completed', 'Authorizing another attempt at a job that has already printed');

		$monitored = null;
		foreach ($jobs->Monitor() as $row)
		{
			if ((int)$row['id'] === (int)$job['id'])
			{
				$monitored = $row;
			}
		}
		self::assertNotNull($monitored, 'The monitor lists the job');
		self::assertSame('reported', $monitored['state'], 'The monitor says the device reported the print');
		self::assertSame('completed', $monitored['authorization_state'], 'A completed job needs no further authorization');
		self::assertSame('ready', $monitored['render_state'], 'The monitor carries the render state beside the print state');
		self::assertSame('Fixture printer', $monitored['printer_name'], 'The monitor names the printer in words a person recognises');
	}

	/** The byte store refuses to store nothing, whatever the caller believed it had. */
	public function testByteStoreRefusesAnEmptyObject(): void
	{
		self::assertRefused(static fn () => self::tx(static fn () => (new LabelByteStore(self::$db))
			->Put(LabelByteStore::GROUP_ASSETS, 'asset-empty.png', '', 'image/png')),
			'content', 'value_out_of_range', 'Storing zero bytes');
	}

	/**
	 * A live label whose target has vanished without the retirement trigger catching it is a
	 * logic error the resolver refuses on rather than a scan that answers with a blank.
	 */
	public function testLiveLabelWithNoTargetIsALogicError(): void
	{
		$uid = '0DANGER1234AB';
		$statement = self::$db->prepare('INSERT INTO labels (uid, kind, target_id) VALUES (?, ?, ?)');
		$statement->execute([$uid, 'location', 987654]);

		$this->expectException(\RuntimeException::class);
		try
		{
			(new \Victual\Services\Labels\LabelIdentityService(self::$db))->Resolve(QrMatrix::Payload($uid), static fn (string $kind) => true);
		}
		finally
		{
			self::$db->exec('DELETE FROM labels WHERE uid = ' . self::$db->quote($uid));
		}
	}
}
