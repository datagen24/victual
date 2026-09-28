<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelAssetService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\LabelValidationException;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\SfntFixture;

/**
 * Issue #523 (audit finding M23): StockService::OpenProduct() shortens a stock entry's due
 * date when the product has `default_best_before_days_after_open` set, and - when the
 * product also has `auto_reprint_stock_label` set and the entry already carries a live
 * label - enqueues a reprint of that label through
 * StockService::ReviseStockEntryLabelIfLive() -> LabelOperationsService::RevisedPrint().
 *
 * The reprint call used to run before the loop's own `$stockEntry->update()` wrote the new
 * `best_before_date` (StockService.php, inside OpenProduct()'s per-entry loop). RevisedPrint()
 * captures whatever a fresh read of the row says right now
 * (LabelCaptureService::Capture()), so the print job it enqueued, and the label_captures row
 * behind it, recorded the due date the entry still had at that moment - the one the booking
 * was about to replace - not the one the booking was making current.
 *
 * The fix defers the call until after the row is actually updated. This test drives the real
 * production path - a live label issued through LabelOperationsService::IssueLocation(), a
 * booking through StockService::OpenProduct() - and reads the enqueued reprint job's own
 * `label_captures.captured_fields` to see which due date it actually recorded, using a
 * published template whose only element is bound to `stock_entry.best_before_date` (a
 * QR-only template proves nothing about this: the issue's own verification requirement).
 *
 * VICTUAL_FEATURE_FLAG_LABELS gates StockService's own reprint check and is a constant fixed
 * once per process at boot; the OpenProduct() call under test therefore runs in a process of
 * its own (label-reprint-duedate-subprocess-helper.php) with the flag defined true before
 * config.php loads, attached to the schema this class migrates and populates itself.
 * Fixture setup and every assertion run directly in this process against that same schema,
 * the way LabelServicesTest drives the label services without any HTTP layer.
 */
class LabelReprintDueDateTest extends PgsqlSchemaTestCase
{
	private const FAR_FUTURE_DATE = '2035-06-30';

	private static PDO $db;
	private static int $printerId;
	private static int $templateId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'label-reprint-duedate-caller', 'fixture')");

		$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('reprint-duedate-worker', 'declared') RETURNING id")->fetchColumn();
		self::tx(static fn () => (new DriverRegistryService(self::$db))->Register($worker, [self::driverDefinition()]));

		self::$printerId = self::tx(static fn () => (new PrinterConfigurationService(self::$db))->Save([
			'name' => 'Reprint duedate printer', 'worker_id' => $worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc', 'is_default' => 1,
			'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]));

		$fontAssetName = 'Reprint Duedate Sans';
		self::tx(static fn () => (new LabelAssetService(self::$db))->Store(
			$fontAssetName, 'font', 'font/ttf', SfntFixture::Shipped('Reprint Duedate', 'Regular'), 'OFL-1.1', 'Fixture font'));

		// The issue's own verification requirement: "test a template that actually includes
		// due date. Default QR-only templates do not prove this visible symptom." This
		// template's only element is bound to stock_entry.best_before_date.
		//
		// migrations/0283.pgsql.php seeds one published, default-pointed, QR-only template
		// per label-supported entity kind - "Default stock entry label" among them - and
		// LabelOperationsService::ResolveTemplate() picks the lowest-id template for a kind
		// when a caller (StockService::ReviseStockEntryLabelIfLive(), among others) names
		// none. That seeded template is therefore the one an auto-reprint actually resolves
		// to, so this test publishes ITS due-date-bearing version and points its default at
		// it, rather than publishing a separate template of its own that ResolveTemplate()
		// would never pick.
		$templates = new LabelTemplateService(self::$db);
		self::$templateId = (int)self::$db->query("SELECT id FROM label_templates WHERE entity_kind = 'stock_entry' ORDER BY id LIMIT 1")->fetchColumn();
		self::assertGreaterThan(0, self::$templateId, 'migration 0283 seeds a default stock_entry template');

		// Both elements a live reprint's own capture needs to be checked against directly:
		// the due date (the original regression) and, for the freeze/thaw transfer coverage
		// below, the location a whole-entry transfer relocates the row to.
		$draft = $templates->GetDraft(self::$templateId);
		$document = [
			'schema_version' => 1, 'entity_kind' => 'stock_entry',
			'canvas' => ['width_mm' => 58.9, 'height_mm' => 30.0, 'max_height_mm' => null,
				'margins_mm' => ['top' => 2.0, 'right' => 2.0, 'bottom' => 2.0, 'left' => 2.0]],
			'elements' => [
				['type' => 'text', 'id' => 'due', 'x_mm' => 2.0, 'y_mm' => 4.0, 'width_mm' => 50.0,
					'height_mm' => 8.0, 'field' => 'stock_entry.best_before_date', 'font_asset' => $fontAssetName, 'size_pt' => 10.0],
				['type' => 'text', 'id' => 'loc', 'x_mm' => 2.0, 'y_mm' => 14.0, 'width_mm' => 50.0,
					'height_mm' => 8.0, 'field' => 'stock_entry.location_name', 'font_asset' => $fontAssetName, 'size_pt' => 10.0],
			],
		];
		self::tx(static fn () => $templates->SaveDraft(self::$templateId, $document, $draft['revision_token'], 9000));
		$version = self::tx(static fn () => $templates->Publish(self::$templateId, 9000));
		self::tx(static fn () => $templates->SetDefaultVersion(self::$templateId, (int)$version['id']));
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

	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private static function operations(): LabelOperationsService
	{
		return new LabelOperationsService(self::$db);
	}

	/** A fresh product, one stock entry, and a live label on it, ready to be opened. */
	private static function newLabelledStockEntry(string $namePrefix, int $daysAfterOpen): array
	{
		self::$db->exec("INSERT INTO locations(name) VALUES ('$namePrefix location')");
		$locationId = (int)self::$db->lastInsertId();

		self::$db->exec("INSERT INTO quantity_units(name, name_plural) VALUES ('$namePrefix unit', '$namePrefix units')");
		$quId = (int)self::$db->lastInsertId();

		$product = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, auto_reprint_stock_label, default_best_before_days_after_open) VALUES (?, ?, ?, ?, ?, ?, 1, ?) RETURNING id');
		$product->execute([$namePrefix . ' product', $locationId, $quId, $quId, $quId, $quId, $daysAfterOpen]);
		$productId = (int)$product->fetchColumn();

		$stock = self::$db->prepare("INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id) VALUES (?, 2, ?, ?, '2026-01-01', ?) RETURNING id");
		$stock->execute([$productId, $namePrefix . '-stock-1', self::FAR_FUTURE_DATE, $locationId]);
		$stockRowId = (int)$stock->fetchColumn();

		$job = self::tx(static fn () => self::operations()->IssueLocation(
			'stock_entry', $stockRowId, 0, self::$printerId, self::$templateId, null, 'en', 'UTC'));
		$labelUid = (string)$job['label_uid'];

		self::assertSame(
			self::FAR_FUTURE_DATE,
			self::capturedDueDate($job['capture_id']),
			'the baseline issue captured the due date the entry was created with'
		);

		return [$productId, $stockRowId, $labelUid];
	}

	/**
	 * A fresh product configured for auto-reprint on freezing, one stock entry at a
	 * non-freezer location, and a live label on it, ready to be transferred into a freezer.
	 *
	 * @return array{0: int, 1: int, 2: string, 3: int, 4: int} productId, stockRowId,
	 *         labelUid, locationIdFrom (non-freezer), locationIdTo (freezer)
	 */
	private static function newLabelledStockEntryForFreeze(string $namePrefix, int $daysAfterFreezing): array
	{
		self::$db->exec("INSERT INTO locations(name) VALUES ('$namePrefix pantry')");
		$locationIdFrom = (int)self::$db->lastInsertId();
		self::$db->exec("INSERT INTO locations(name, is_freezer) VALUES ('$namePrefix freezer', 1)");
		$locationIdTo = (int)self::$db->lastInsertId();

		self::$db->exec("INSERT INTO quantity_units(name, name_plural) VALUES ('$namePrefix unit', '$namePrefix units')");
		$quId = (int)self::$db->lastInsertId();

		$product = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, auto_reprint_stock_label, default_best_before_days_after_freezing) VALUES (?, ?, ?, ?, ?, ?, 1, ?) RETURNING id');
		$product->execute([$namePrefix . ' product', $locationIdFrom, $quId, $quId, $quId, $quId, $daysAfterFreezing]);
		$productId = (int)$product->fetchColumn();

		$stock = self::$db->prepare("INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id) VALUES (?, 2, ?, ?, '2026-01-01', ?) RETURNING id");
		$stock->execute([$productId, $namePrefix . '-stock-1', self::FAR_FUTURE_DATE, $locationIdFrom]);
		$stockRowId = (int)$stock->fetchColumn();

		$job = self::tx(static fn () => self::operations()->IssueLocation(
			'stock_entry', $stockRowId, 0, self::$printerId, self::$templateId, null, 'en', 'UTC'));
		$labelUid = (string)$job['label_uid'];

		return [$productId, $stockRowId, $labelUid, $locationIdFrom, $locationIdTo];
	}

	private static function capturedDueDate(int $captureId): ?string
	{
		$row = self::$db->prepare("SELECT captured_fields->>'stock_entry.best_before_date' FROM label_captures WHERE id = ?");
		$row->execute([$captureId]);
		$value = $row->fetchColumn();
		return $value === false ? null : $value;
	}

	/** The most recent print_jobs row for $labelUid, joined to its capture's fields. */
	private static function latestJobForLabel(string $labelUid): ?array
	{
		$statement = self::$db->prepare(
			"SELECT pj.id, pj.operation, pj.capture_id,
			        lc.captured_fields->>'stock_entry.best_before_date' AS due_date,
			        lc.captured_fields->>'stock_entry.location_name' AS location_name
			 FROM print_jobs pj JOIN label_captures lc ON lc.id = pj.capture_id
			 WHERE pj.label_uid = ? ORDER BY pj.id DESC LIMIT 1"
		);
		$statement->execute([$labelUid]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	private static function printJobCount(): int
	{
		return (int)self::$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn();
	}

	private static function stockBestBeforeDate(int $stockRowId): ?string
	{
		$statement = self::$db->prepare('SELECT best_before_date FROM stock WHERE id = ?');
		$statement->execute([$stockRowId]);
		$value = $statement->fetchColumn();
		return $value === false ? null : $value;
	}

	/** @return array{status: int, transaction_id: ?string, error_message: ?string} */
	private static function openProduct(int $productId, float $amount): array
	{
		$spec = ['schema' => self::Schema(), 'operation' => 'open', 'productId' => $productId, 'amount' => $amount];
		$env = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/label-reprint-duedate-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode($output, true);
		self::assertIsArray($result, "the open-product helper printed no JSON. stdout: $output\nstderr: $errors");
		return $result;
	}

	/** @return array{status: int, transaction_id: ?string, error_message: ?string} */
	private static function transferProduct(int $productId, float $amount, int $locationIdFrom, int $locationIdTo): array
	{
		$spec = ['schema' => self::Schema(), 'operation' => 'transfer', 'productId' => $productId,
			'amount' => $amount, 'locationIdFrom' => $locationIdFrom, 'locationIdTo' => $locationIdTo];
		$env = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/label-reprint-duedate-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode($output, true);
		self::assertIsArray($result, "the transfer-product helper printed no JSON. stdout: $output\nstderr: $errors");
		return $result;
	}

	/**
	 * The regression itself: opening a labelled entry shortens its due date, and the
	 * automatic reprint that follows has to carry the NEW due date - the one the booking is
	 * making current - not the one it is replacing.
	 */
	public function testOpeningALabelledEntryReprintsWithTheNewDueDate(): void
	{
		[$productId, $stockRowId, $labelUid] = self::newLabelledStockEntry('Reprint New', 3);

		$before = self::printJobCount();

		$response = self::openProduct($productId, 1.0);
		self::assertSame(200, $response['status'], 'Opening a product whose due date shortens on open is accepted: ' . ($response['error_message'] ?? ''));

		// Pinned rather than left to the process default (the suite otherwise runs on UTC,
		// but this computation must match StockService::OpenProduct()'s own date('Y-m-d')
		// call exactly, which runs in the subprocess helper above).
		date_default_timezone_set('UTC');
		$expectedNewDueDate = (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
		self::assertSame($expectedNewDueDate, self::stockBestBeforeDate($stockRowId), 'the booking did shorten the due date, so the reprint check really ran');
		self::assertNotSame(self::FAR_FUTURE_DATE, $expectedNewDueDate, 'the fixture due date and the new one are different dates, or this test proves nothing');

		self::assertGreaterThan($before, self::printJobCount(), 'opening a labelled entry whose due date changed enqueues a reprint job');

		$job = self::latestJobForLabel($labelUid);
		self::assertNotNull($job, 'a print job exists for this label');
		self::assertSame('revised_print', $job['operation'], 'the auto-reprint is a revised print, not a fresh issue');

		self::assertSame(
			$expectedNewDueDate,
			$job['due_date'],
			'the enqueued reprint must capture the due date the booking just wrote, not the one it replaced'
		);
	}

	/**
	 * A refused booking - here, an amount larger than what is actually in stock - must leave
	 * the ledger and the label subsystem exactly as it found them: no due date change, and no
	 * reprint job enqueued for a change that never happened.
	 */
	public function testARefusedOpenEnqueuesNoReprintAndLeavesTheDueDateUnchanged(): void
	{
		[$productId, $stockRowId, $labelUid] = self::newLabelledStockEntry('Reprint Refused', 3);

		$before = self::printJobCount();
		$jobBefore = self::latestJobForLabel($labelUid);

		$response = self::openProduct($productId, 999.0);
		self::assertSame(400, $response['status'], 'Opening more than is in stock is refused');
		self::assertStringContainsString('cannot be >', (string)$response['error_message']);

		self::assertSame(self::FAR_FUTURE_DATE, self::stockBestBeforeDate($stockRowId), 'a refused booking leaves the due date untouched');
		self::assertSame($before, self::printJobCount(), 'a refused booking enqueues no print job');
		self::assertSame($jobBefore, self::latestJobForLabel($labelUid), 'the label\'s own most recent job is unchanged by the refusal');
	}

	/**
	 * A refusal that happens deep inside ReviseStockEntryLabelIfLive() -> RevisedPrint() -
	 * here, no active printer to resolve - must roll back the WHOLE booking, not just skip
	 * the reprint. The fix moved the reprint call to run after $stockEntry->update(), inside
	 * the same InTransaction() closure as that write; a refusal at that later point has to
	 * unwind the update, the stock_log rows, and everything else the booking did, exactly as
	 * a refusal earlier in the same method already did before this fix touched anything.
	 */
	public function testARefusedReprintAfterTheUpdateRollsBackTheWholeBooking(): void
	{
		[$productId, $stockRowId, $labelUid] = self::newLabelledStockEntry('Reprint Rollback', 3);

		$beforeRow = self::$db->prepare('SELECT amount, open, best_before_date FROM stock WHERE id = ?');
		$beforeRow->execute([$stockRowId]);
		$beforeRow = $beforeRow->fetch(PDO::FETCH_ASSOC);
		$beforeLogCount = (int)self::$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn();
		$beforeJobCount = self::printJobCount();
		$beforeCaptureCount = (int)self::$db->query('SELECT COUNT(*) FROM label_captures')->fetchColumn();
		$jobBefore = self::latestJobForLabel($labelUid);

		// ResolvePrinter(null) - which ReviseStockEntryLabelIfLive() always calls with a null
		// printer id - refuses "No active printer is configured" once none is active.
		self::$db->exec('UPDATE label_printers SET active = 0');
		try
		{
			$response = self::openProduct($productId, 1.0);
		}
		finally
		{
			self::$db->exec('UPDATE label_printers SET active = 1 WHERE id = ' . self::$printerId);
		}

		self::assertSame(400, $response['status'], 'Opening with no active printer to reprint on is refused');
		self::assertStringContainsString('No active printer', (string)$response['error_message']);

		$afterRow = self::$db->prepare('SELECT amount, open, best_before_date FROM stock WHERE id = ?');
		$afterRow->execute([$stockRowId]);
		$afterRow = $afterRow->fetch(PDO::FETCH_ASSOC);
		self::assertSame($beforeRow, $afterRow, 'a refusal deep in the booking rolls back the stock row update (amount, open, due date) too');

		self::assertSame($beforeLogCount, (int)self::$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn(), 'no stock_log row survives the rollback');
		self::assertSame($beforeJobCount, self::printJobCount(), 'no print job survives the rollback');
		self::assertSame($beforeCaptureCount, (int)self::$db->query('SELECT COUNT(*) FROM label_captures')->fetchColumn(), 'no capture survives the rollback');
		self::assertSame($jobBefore, self::latestJobForLabel($labelUid), 'the label\'s own most recent job is unchanged by the rollback');
	}

	/**
	 * TransferProduct()'s freeze/thaw whole-entry fix: the reprint has to carry both the new
	 * due date the freeze wrote AND the new location the transfer relocated the row to - not
	 * whatever the row said before either write.
	 */
	public function testFreezeTransferWholeEntryReprintsWithNewDueDateAndLocation(): void
	{
		[$productId, $stockRowId, $labelUid, $locationIdFrom, $locationIdTo] =
			self::newLabelledStockEntryForFreeze('Reprint Freeze', 30);

		date_default_timezone_set('UTC');
		$expectedNewDueDate = (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');

		$before = self::printJobCount();

		// The whole entry (amount 2) moves in one transfer, taking the whole-entry branch
		// rather than the split branch.
		$response = self::transferProduct($productId, 2.0, $locationIdFrom, $locationIdTo);
		self::assertSame(200, $response['status'], 'Freezing the whole entry is accepted: ' . ($response['error_message'] ?? ''));

		$row = self::$db->prepare('SELECT best_before_date, location_id FROM stock WHERE id = ?');
		$row->execute([$stockRowId]);
		$row = $row->fetch(PDO::FETCH_ASSOC);
		self::assertSame($expectedNewDueDate, $row['best_before_date'], 'freezing did lengthen the due date, so the reprint check really ran');
		self::assertSame($locationIdTo, (int)$row['location_id'], 'the whole-entry transfer relocated the row in place');
		self::assertNotSame(self::FAR_FUTURE_DATE, $expectedNewDueDate, 'the fixture due date and the new one are different dates, or this test proves nothing');

		self::assertGreaterThan($before, self::printJobCount(), 'freezing a labelled entry whose due date and location changed enqueues a reprint');

		$job = self::latestJobForLabel($labelUid);
		self::assertNotNull($job, 'a print job exists for this label');
		self::assertSame('revised_print', $job['operation'], 'the auto-reprint is a revised print, not a fresh issue');
		self::assertSame($expectedNewDueDate, $job['due_date'], 'the reprint must capture the NEW due date the freeze wrote');

		$freezerName = self::$db->prepare('SELECT name FROM locations WHERE id = ?');
		$freezerName->execute([$locationIdTo]);
		self::assertSame((string)$freezerName->fetchColumn(), $job['location_name'], 'the reprint must capture the NEW location the transfer wrote, not the source location');
	}
}
