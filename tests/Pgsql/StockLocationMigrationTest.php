<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class StockLocationMigrationTest extends PgsqlSchemaTestCase
{
	protected function setUp(): void
	{
		$db = self::Pdo();
		$db->exec('ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_location_id_fkey; DROP INDEX IF EXISTS stock_location_id_idx; DELETE FROM migrations WHERE migration=288; TRUNCATE stock, stock_log, products, locations CASCADE');
		$db->exec("INSERT INTO locations(id, name) VALUES(501, 'Migration'); INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES(501, 'Migration', 501, 2, 2)");
	}

	public function testDirtyMigrationReportsWithoutChangingInventoryAndCanBeRetried(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO stock(product_id, amount, stock_id, location_id) SELECT 501, 2, 'dirty-' || n, 999 FROM generate_series(1, 15) n");
		$before = $db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			self::fail('Dirty migration must refuse');
		}
		catch (\PDOException $ex)
		{
			self::assertStringContainsString('15 stock rows reference missing locations', $ex->getMessage());
			self::assertStringContainsString('Choose an explicit repair and rerun migration', $ex->getMessage());
			self::assertStringContainsString('SELECT s.id, s.product_id, s.location_id', $ex->getMessage());
			self::assertSame(10, substr_count($ex->getMessage(), ', 501, 999)'));
		}
		self::assertFalse($db->inTransaction());
		self::assertSame($before, $db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=288')->fetchColumn());
		self::assertFalse($db->query("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='stock'::regclass AND conname='stock_location_id_fkey')")->fetchColumn());
		$db->exec('UPDATE stock SET location_id=501');
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame(15, (int)$db->query('SELECT count(*) FROM stock')->fetchColumn());
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=288')->fetchColumn());
		self::assertTrue($db->query("SELECT convalidated FROM pg_constraint WHERE conrelid='stock'::regclass AND conname='stock_location_id_fkey'")->fetchColumn());
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=288')->fetchColumn());
	}

	public function testMigrationLockTimeoutRollsBackWithoutRecordingVersion(): void
	{
		$db = self::Pdo();
		$peer = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'), getenv('PGUSER'), getenv('PGPASSWORD'));
		$peer->exec('SET search_path TO ' . self::Schema() . ', public');
		$peer->beginTransaction();
		$peer->exec("UPDATE locations SET name='Uncommitted' WHERE id=501");
		try
		{
			try { DatabaseMigrationService::GetInstance()->MigrateDatabase(); self::fail('Locked migration must time out'); }
			catch (\PDOException $ex) { self::assertSame('55P03', $ex->getCode()); }
			self::assertFalse($db->inTransaction());
			self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=288')->fetchColumn());
			self::assertSame('Migration', $db->query('SELECT name FROM locations WHERE id=501')->fetchColumn());
		}
		finally { $peer->rollBack(); }
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=288')->fetchColumn());
	}
}
