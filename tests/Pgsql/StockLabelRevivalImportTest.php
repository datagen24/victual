<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\Labels\StockLabelRevivalService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0037 section 10 and acceptance prerequisite 7: retirement events are label history. The
 * importer neither copies nor clears them, its epoch bump is what makes every pending event
 * unmatchable, and it refuses a target that holds a revived (live) label.
 */
class StockLabelRevivalImportTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static int $location;
	private array $files = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'label-revival-import', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$location = (int)self::$db->query("INSERT INTO locations(name) VALUES ('Label revival import shelf') RETURNING id")->fetchColumn();
	}

	protected function tearDown(): void
	{
		foreach ($this->files as $file)
		{
			@unlink($file);
		}
		$this->files = [];
	}

	private function importer(): DatabaseImporter
	{
		$file = tempnam(sys_get_temp_dir(), 'label-revival-import-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX . '.db', $file);
		$source = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		return new DatabaseImporter($source, self::$db, DatabaseService::GetInstance()->GetDialect(), static function ($message) {});
	}

	/** @return array{0: int, 1: string, 2: int} row, uid, booking */
	private static function consumedLabelledRow(): array
	{
		$product = (int)self::$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Import revival " . bin2hex(random_bytes(3)) . "', " . self::$location . ', 2, 2) RETURNING id')->fetchColumn();
		StockService::GetInstance()->AddProduct($product, 2, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		$row = (int)self::$db->query("SELECT max(id) FROM stock WHERE product_id = $product")->fetchColumn();
		self::$db->beginTransaction();
		$uid = (new LabelIdentityService(self::$db))->Issue('stock_entry', $row, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn());
		self::$db->commit();
		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct($product, 2, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);
		$booking = (int)self::$db->query('SELECT id FROM stock_log WHERE transaction_id = ' . self::$db->quote($transactionId))->fetchColumn();
		return [$row, $uid, $booking];
	}

	public function testTheTableIsLabelHistoryTheImporterNeverCopiesOrClears(): void
	{
		self::assertContains('stock_label_retirements', DatabaseImporter::NOT_COPIED_TABLES);
	}

	public function testAnImportRefusesARevivedLabel(): void
	{
		[$row, $uid, $booking] = self::consumedLabelledRow();
		StockService::GetInstance()->UndoBooking($booking);
		self::assertNull(self::$db->query('SELECT retired_at FROM labels WHERE uid = ' . self::$db->quote($uid))->fetchColumn());
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();

		try
		{
			$this->importer()->Import(true);
			self::fail('The import must refuse a live label');
		}
		catch (\RuntimeException $refusal)
		{
			self::assertStringContainsString('live label', $refusal->getMessage());
		}

		self::assertSame($epoch, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn());
		// Retired deliberately, as the refusal asks, for the next case.
		self::$db->exec("DELETE FROM stock WHERE id = $row");
	}

	public function testAnImportKeepsEveryEventAndNoPendingEventMatchesAfterIt(): void
	{
		[, $uid, $booking] = self::consumedLabelledRow();
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM labels WHERE retired_at IS NULL')->fetchColumn(), 'Precondition: nothing live');
		$events = self::$db->query('SELECT * FROM stock_label_retirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$pending = (new StockLabelRevivalService(self::$db))->PendingFor([$booking]);
		self::assertCount(1, $pending, 'Precondition: the event is pending in the current epoch');
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();

		$this->importer()->Import(true);

		self::assertSame($epoch + 1, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn());
		self::assertSame($events, self::$db->query('SELECT * FROM stock_label_retirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'Every event survives the import unchanged, pending ones included');
		self::assertSame([], (new StockLabelRevivalService(self::$db))->PendingFor([$booking]),
			'A pending event of the earlier epoch never matches a booking id the import may reuse');
		self::assertNotNull(self::$db->query('SELECT retired_at FROM labels WHERE uid = ' . self::$db->quote($uid))->fetchColumn());
	}
}
