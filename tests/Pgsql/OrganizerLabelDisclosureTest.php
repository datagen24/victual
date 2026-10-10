<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\ConsumptionRefillService;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\FieldCatalogue;
use Victual\Services\Labels\LabelCaptureService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\LabelValidationException;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Plan 22 issue #699: the labels an organizer household already prints (location, product and
 * stock entry) say nothing about a private consumption recipe, its note, its events or any refill
 * date, whatever the stock under them has been through.
 *
 * Every read below is made with the permission an ordinary stock reader holds (STOCK_VIEW) and
 * nothing else. The surfaces covered are the ones a label has: the capture a print or a live
 * preview takes, the sample capture a preview invents, the print job and its outbox event, the
 * render request, the scan (`LabelIdentityService::Resolve()`), the context read, and the
 * snapshot a retired label keeps. The scan and context routes are also read over HTTP in
 * OrganizerApiTest.
 */
class OrganizerLabelDisclosureTest extends PgsqlSchemaTestCase
{
	private const KINDS = ['location', 'product', 'stock_entry'];

	private static PDO $db;
	private static int $printer;
	/** @var array<string,int> template id by kind */
	private static array $template = [];
	private static int $organizer;
	private static int $product;
	private static int $stockRow;
	private static int $recipeId;
	private static string $recipeName;
	private static string $recipeNote;
	private static string $refillNote;
	private static string $requestId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9000, 'organizer-label-caller', 'fixture') ON CONFLICT DO NOTHING");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('organizer-label-worker', 'declared') RETURNING id")->fetchColumn();
		$definition = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
		self::tx(static fn () => (new DriverRegistryService(self::$db))->Register($worker, [$definition]));
		self::$printer = self::tx(static fn () => (new PrinterConfigurationService(self::$db))->Save([
			'name' => 'Organizer fixture printer', 'worker_id' => $worker, 'driver_id' => 'brother.ql', 'driver_schema_version' => '1.0',
			'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp', 'model' => 'QL-820NWBc',
			'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]));

		$templates = new LabelTemplateService(self::$db);
		foreach (self::KINDS as $kind)
		{
			$id = (int)self::tx(static fn () => $templates->Create("Organizer $kind label", null, $kind, 9000))['id'];
			$draft = $templates->GetDraft($id);
			$document = ['schema_version' => 1, 'entity_kind' => $kind,
				'canvas' => ['width_mm' => 58.9, 'height_mm' => 30.0, 'max_height_mm' => null, 'margins_mm' => ['top' => 2.0, 'right' => 2.0, 'bottom' => 2.0, 'left' => 2.0]],
				'elements' => [['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6, 'ec_level' => 'M', 'quiet_zone_modules' => 4, 'source' => 'label.payload', 'color' => 'black']]];
			self::tx(static fn () => $templates->SaveDraft($id, $document, $draft['revision_token'], 9000));
			self::tx(static fn () => $templates->Publish($id, 9000));
			self::$template[$kind] = $id;
		}

		self::$organizer = (int)self::$db->query("INSERT INTO locations (name, description) VALUES ('Label organizer A', 'Monday to Sunday') RETURNING id")->fetchColumn();
		$cabinet = (int)self::$db->query("INSERT INTO locations (name) VALUES ('Label cabinet') RETURNING id")->fetchColumn();
		$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('label tablet') RETURNING id")->fetchColumn();
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['Label pills 5 mg', $cabinet, $unit, $unit, $unit, $unit]);
		self::$product = (int)$statement->fetchColumn();

		$stock = StockService::GetInstance();
		$stock->AddProduct(self::$product, 20, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $cabinet);
		$stock->TransferProduct(self::$product, 10, $cabinet, self::$organizer);
		self::$stockRow = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . self::$product . ' AND location_id = ' . self::$organizer)->fetchColumn();

		self::$recipeName = 'Private recipe name ' . bin2hex(random_bytes(4));
		self::$recipeNote = 'Private recipe note ' . bin2hex(random_bytes(4));
		self::$requestId = 'organizer-label-' . bin2hex(random_bytes(4));
		$recipes = ConsumptionRecipeService::GetInstance();
		self::$recipeId = $recipes->CreateRecipe(self::$recipeName, self::$recipeNote, [['product_id' => self::$product, 'amount' => 2, 'qu_id' => $unit]]);
		$recipes->Consume(self::$recipeId, self::$requestId, self::$organizer);

		// ADR-0042: refill records of the same recipe, so the absence of a refill date or note from a label is a
		// finding about the label and not about an empty table.
		self::$refillNote = 'Private refill note ' . bin2hex(random_bytes(4));
		$refill = ConsumptionRefillService::GetInstance();
		$refill->RecordFill(self::$recipeId, ['filled_on' => '2026-01-01', 'supplied_days' => 90, 'note' => self::$refillNote], '2026-03-01');
		$refill->SetSettings(self::$recipeId, ['rule' => ['kind' => 'fixed_interval', 'parameter' => 70], 'explicit_reorder_date' => '2026-03-12'], '2026-03-01');
		$refill->RecordOrder(self::$recipeId, ['ordered_on' => '2026-03-12'], '2026-03-12');
		$refill->Acknowledge(self::$recipeId . ':approaching:2026-03-12');
	}

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

	/** What an ordinary stock reader may do: read stock, and nothing else. */
	private static function reader(): \Closure
	{
		return static fn (string $permission): bool => $permission === 'STOCK_VIEW';
	}

	private static function target(string $kind): int
	{
		return match ($kind)
		{
			'location' => self::$organizer,
			'product' => self::$product,
			'stock_entry' => self::$stockRow,
		};
	}

	/** Every fragment that would show a private recipe, its note, an event or a refill date. */
	private static function assertNoPrivateData(string $json, string $where): void
	{
		foreach ([self::$recipeName, self::$recipeNote, self::$requestId, self::$refillNote] as $private)
		{
			self::assertStringNotContainsString($private, $json, "$where carries private text");
		}
		self::assertDoesNotMatchRegularExpression('/consumption_(recipe|event)|refill|prescription|"recipe_id":\s*[1-9]/i', $json, "$where carries a private table, column or refill field");
	}

	private static function jobDocuments(int $jobId): string
	{
		$parts = [];
		foreach ([
			'SELECT row_to_json(j) FROM print_jobs j WHERE j.id = ?',
			'SELECT row_to_json(o) FROM outbox o WHERE o.id = (SELECT outbox_id FROM print_jobs WHERE id = ?)',
			'SELECT row_to_json(c) FROM label_captures c WHERE c.id = (SELECT capture_id FROM print_jobs WHERE id = ?)',
			'SELECT row_to_json(r) FROM label_render_requests r WHERE r.id = (SELECT render_request_id FROM print_jobs WHERE id = ?)',
			'SELECT row_to_json(l) FROM labels l WHERE l.uid = (SELECT label_uid FROM print_jobs WHERE id = ?)',
		] as $sql)
		{
			$statement = self::$db->prepare($sql);
			$statement->execute([$jobId]);
			$parts[] = (string)$statement->fetchColumn();
		}

		return implode("\n", $parts);
	}

	/** Positive control: the strings the other tests look for are stored, so their absence means something. */
	public function testThePrivateStringsAreStoredInTheRecipeTables(): void
	{
		$stored = (string)self::$db->query('SELECT row_to_json(r) FROM consumption_recipes r WHERE r.id = ' . self::$recipeId)->fetchColumn()
			. (string)self::$db->query('SELECT string_agg(row_to_json(e)::text, \'\') FROM consumption_events e WHERE e.recipe_id = ' . self::$recipeId)->fetchColumn();
		self::assertStringContainsString(self::$recipeName, $stored);
		self::assertStringContainsString(self::$recipeNote, $stored);
		self::assertStringContainsString(self::$requestId, $stored);

		$refill = (string)self::$db->query('SELECT string_agg(row_to_json(f)::text, \'\') FROM consumption_refill_fills f WHERE f.recipe_id = ' . self::$recipeId)->fetchColumn()
			. (string)self::$db->query('SELECT string_agg(row_to_json(d)::text, \'\') FROM consumption_refill_dates d WHERE d.recipe_id = ' . self::$recipeId)->fetchColumn()
			. (string)self::$db->query('SELECT string_agg(row_to_json(o)::text, \'\') FROM consumption_refill_orders o WHERE o.recipe_id = ' . self::$recipeId)->fetchColumn();
		self::assertStringContainsString(self::$refillNote, $refill);
		self::assertStringContainsString('2026-03-12', $refill, 'the explicit reorder date and the order date are stored');
	}

	public function testPrintingEachKindTakesAReadersCaptureAndQueuesNothingPrivate(): void
	{
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();

		foreach (self::KINDS as $kind)
		{
			$job = self::tx(fn () => (new LabelOperationsService(self::$db, self::reader(), 9000))
				->IssueLocation($kind, self::target($kind), $epoch, self::$printer, self::$template[$kind], null, 'en', 'UTC'));
			self::assertSame('issue', $job['operation'], $kind);

			$documents = self::jobDocuments((int)$job['id']);
			self::assertStringContainsString('"' . $kind . '"', $documents, "the $kind job names its kind");
			self::assertNoPrivateData($documents, "the $kind print job, outbox event, capture, render request and label row");
		}
	}

	public function testEveryCataloguedFieldOfEachKindIsCapturedWithoutPrivateData(): void
	{
		$capture = new LabelCaptureService(self::$db, self::reader());

		foreach (self::KINDS as $kind)
		{
			$fields = array_keys(FieldCatalogue::For($kind));
			$live = self::tx(fn () => $capture->Capture($kind, self::target($kind), null, $fields, 'en', 'UTC', 9000));
			self::assertSame($fields, array_keys($live['captured_fields']), "$kind: a live capture holds exactly the catalogued fields");
			self::assertNoPrivateData(json_encode($live), "the live $kind capture");

			$sample = self::tx(fn () => $capture->CaptureSample($kind, $fields, 'en', 'UTC', 9000));
			self::assertNull($sample['target_id'], "$kind: a sample preview names no target");
			self::assertNoPrivateData(json_encode($sample), "the sample $kind capture");
		}

		self::assertSame('Label organizer A', self::tx(fn () => $capture->Capture('location', self::$organizer, null, ['location.name'], 'en', 'UTC', 9000))['captured_fields']['location.name'],
			'the organizer label still names the organizer');
		self::assertSame('8', rtrim(rtrim(self::tx(fn () => $capture->Capture('stock_entry', self::$stockRow, null, ['stock_entry.amount'], 'en', 'UTC', 9000))['captured_fields']['stock_entry.amount'], '0'), '.'),
			'the stock entry label shows what the organizer holds after the recipe consumption');
	}

	public function testACallerWithoutStockViewCannotCaptureAnyKind(): void
	{
		$nobody = new LabelCaptureService(self::$db, static fn (string $permission): bool => false);

		foreach (self::KINDS as $kind)
		{
			$field = array_key_first(FieldCatalogue::For($kind));
			try
			{
				self::tx(fn () => $nobody->Capture($kind, self::target($kind), null, [$field], 'en', 'UTC', 9000));
				self::fail("$kind: a caller without STOCK_VIEW captured $field");
			}
			catch (LabelValidationException $error)
			{
				self::assertSame('forbidden', $error->errorCode, $kind);
				self::assertNoPrivateData($error->getMessage(), "the $kind refusal");
			}
		}
	}

	public function testScansAndContextReadsOfEachKindShowOnlyTheStockRecord(): void
	{
		$identity = new LabelIdentityService(self::$db);
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();
		$mayRead = static fn (string $kind): bool => in_array($kind, ['location', 'product', 'stock_entry'], true);

		foreach (self::KINDS as $kind)
		{
			$uid = self::tx(fn () => $identity->Issue($kind, self::target($kind), $epoch));
			$scan = $identity->Resolve($uid, $mayRead);
			self::assertSame('resolved', $scan['status'], $kind);
			self::assertSame(['id', 'name', 'path'], array_keys($scan['target']), "$kind: a scan names the target and its path, nothing else");
			self::assertNoPrivateData(json_encode($scan), "the $kind scan");

			$context = $identity->Context($kind, self::target($kind));
			self::assertSame(['id', 'name', 'import_epoch'], array_keys($context), "$kind: the context read");
			self::assertNoPrivateData(json_encode($context), "the $kind context read");
		}
	}

	public function testALabelRetiredByEmptyingItsRowKeepsNoPrivateSnapshot(): void
	{
		$identity = new LabelIdentityService(self::$db);
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();
		$uid = self::tx(fn () => $identity->Issue('stock_entry', self::$stockRow, $epoch));

		// The remaining 8 tablets in the organizer: the recipe takes the row whole, which deletes it.
		$recipes = ConsumptionRecipeService::GetInstance();
		$recipes->UpdateRecipe(self::$recipeId, ['lines' => [['product_id' => self::$product, 'amount' => 8, 'qu_id' => (int)self::$db->query('SELECT qu_id_stock FROM products WHERE id = ' . self::$product)->fetchColumn()]]]);
		$recipes->Consume(self::$recipeId, self::$requestId . '-empty', self::$organizer);
		self::assertSame(0.0, (float)self::$db->query('SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = ' . self::$product . ' AND location_id = ' . self::$organizer)->fetchColumn());

		$scan = $identity->Resolve($uid, static fn (string $kind): bool => true);
		self::assertSame('retired', $scan['status'], 'the label of an emptied row is retired, not deleted');
		self::assertNoPrivateData(json_encode($scan), 'the retired stock entry scan');
		self::assertNoPrivateData((string)self::$db->query("SELECT retirement_snapshot FROM labels WHERE uid = " . self::$db->quote($uid))->fetchColumn(), 'the retirement snapshot');
	}
}
