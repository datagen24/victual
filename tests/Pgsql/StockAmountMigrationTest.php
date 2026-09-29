<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Migration 0297's preflight (issue #492, maintainer correction 2026-09-29: "Import vs
 * upgrade" in this repository's fixer rules). DatabaseImporter's own AssertStockAmounts()
 * only ever sees a blank, freshly migrated database; an app upgrade runs this migration
 * against an existing installation's live data, which can already hold a negative
 * stock.amount row - issue #492's own defect is exactly how one could get there before this
 * fix existed. Modeled on StockLocationMigrationTest.php, which covers migration 0288's own
 * ADR-0029 preflight for the analogous dangling-location case.
 */
class StockAmountMigrationTest extends PgsqlSchemaTestCase
{
	protected function setUp(): void
	{
		$db = self::Pdo();
		$db->exec('ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_amount_non_negative_check; '
			. 'DELETE FROM migrations WHERE migration>=297; '
			. 'TRUNCATE stock, stock_log, products, locations CASCADE');
		$db->exec("INSERT INTO locations(id, name) VALUES(601, 'Amount migration'); "
			. "INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES(601, 'Amount migration', 601, 2, 2)");
	}

	public function testWithinToleranceResidueUpgradesToZeroAndRecords(): void
	{
		$db = self::Pdo();
		$stockId = (int)$db->query("INSERT INTO stock(product_id, amount, stock_id, location_id) "
			. "VALUES(601, -3e-17, 'residue-601', 601) RETURNING id")->fetchColumn();

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(0.0, (float)$db->query('SELECT amount FROM stock WHERE id=' . $stockId)->fetchColumn());
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=297')->fetchColumn());
		self::assertTrue((bool)$db->query("SELECT convalidated FROM pg_constraint WHERE conrelid='stock'::regclass AND conname='stock_amount_non_negative_check'")->fetchColumn());
	}

	public function testNegativeAmountRefusesUpgradeWithoutChangingInventoryAndCanBeRetried(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO stock(product_id, amount, stock_id, location_id) SELECT 601, -1, 'dirty-' || n, 601 FROM generate_series(1, 15) n");
		$before = $db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			self::fail('Dirty migration must refuse');
		}
		catch (\PDOException $ex)
		{
			self::assertStringContainsString('15 stock rows hold a negative amount', $ex->getMessage());
			self::assertStringContainsString('Choose an explicit repair and rerun migration', $ex->getMessage());
			self::assertStringContainsString('SELECT id, product_id, stock_id, amount FROM stock WHERE amount < -1e-9', $ex->getMessage());
			self::assertSame(10, substr_count($ex->getMessage(), ', 601, dirty-'));
		}

		self::assertFalse($db->inTransaction());
		self::assertSame($before, $db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=297')->fetchColumn());
		self::assertFalse((bool)$db->query("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='stock'::regclass AND conname='stock_amount_non_negative_check')")->fetchColumn());

		$db->exec('UPDATE stock SET amount=1 WHERE amount < 0');
		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(15, (int)$db->query('SELECT count(*) FROM stock')->fetchColumn());
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=297')->fetchColumn());
		self::assertTrue((bool)$db->query("SELECT convalidated FROM pg_constraint WHERE conrelid='stock'::regclass AND conname='stock_amount_non_negative_check'")->fetchColumn());

		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=297')->fetchColumn());
	}
}
