<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Migration 0295 on the upgrade path (issue #552): an existing installation can already hold
 * products whose location, quantity unit or product group was deleted, because nothing
 * refused that delete before 0295. The migration must list them and abort before adding any
 * foreign key, changing nothing, and succeed once the operator has repaired the data - the
 * same contract StockLocationMigrationTest pins for 0288.
 */
class ProductReferenceMigrationTest extends PgsqlSchemaTestCase
{
	private const COLUMNS = ['location_id', 'qu_id_purchase', 'qu_id_stock', 'qu_id_consume', 'qu_id_price', 'product_group_id'];

	protected function setUp(): void
	{
		$db = self::Pdo();
		foreach (self::COLUMNS as $column)
		{
			$db->exec('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_' . $column . '_fkey');
			$db->exec('DROP INDEX IF EXISTS products_' . $column . '_idx');
		}
		$db->exec('DELETE FROM migrations WHERE migration = 295');
		$db->exec('TRUNCATE stock, stock_log, products CASCADE');
		$db->exec('DELETE FROM quantity_unit_conversions WHERE product_id >= 600');
		$db->exec("INSERT INTO locations(id, name) VALUES(601, 'Upgrade') ON CONFLICT (id) DO NOTHING");
		$db->exec("INSERT INTO quantity_units(id, name) VALUES(601, 'Upgrade unit') ON CONFLICT (id) DO NOTHING");
	}

	public function testDanglingReferencesAbortTheUpgradeWithAReportAndChangeNothing(): void
	{
		$db = self::Pdo();
		foreach ([601 => 'Clean', 602 => 'Gone location', 603 => 'Gone unit and group'] as $id => $name)
		{
			$db->exec("INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES($id, '$name', 601, 601, 601)");
		}
		// The state an existing installation can be in: a referenced row deleted while nothing
		// refused it. Written with user triggers off so the unit-conversion triggers do not react.
		self::WithoutUserTriggers(function (PDO $db) {
			$db->exec('UPDATE products SET location_id = 9601 WHERE id = 602');
			$db->exec('UPDATE products SET qu_id_stock = 9602, product_group_id = 9603 WHERE id = 603');
		});
		$before = $db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			self::fail('An upgrade over dangling product references must refuse');
		}
		catch (\PDOException $ex)
		{
			$message = $ex->getMessage();
			self::assertStringContainsString('Migration refused: products reference rows that no longer exist', $message);
			self::assertStringContainsString('products.location_id: 1 rows reference missing locations', $message);
			self::assertStringContainsString('(602, 9601)', $message);
			self::assertStringContainsString('products.qu_id_stock: 1 rows reference missing quantity_units', $message);
			self::assertStringContainsString('products.product_group_id: 1 rows reference missing product_groups', $message);
			self::assertStringNotContainsString('products.qu_id_purchase', $message);
		}

		self::assertFalse($db->inTransaction());
		self::assertSame($before, $db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'A refused upgrade changes no product');
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration = 295')->fetchColumn());
		self::assertFalse((bool)$db->query("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid = 'products'::regclass AND conname = 'products_location_id_fkey')")->fetchColumn());

		self::WithoutUserTriggers(function (PDO $db) {
			$db->exec('UPDATE products SET location_id = 601 WHERE id = 602');
			$db->exec('UPDATE products SET qu_id_stock = 601, product_group_id = NULL WHERE id = 603');
		});
		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration = 295')->fetchColumn());
		foreach (self::COLUMNS as $column)
		{
			self::assertTrue((bool)$db->query("SELECT convalidated FROM pg_constraint WHERE conrelid = 'products'::regclass AND conname = 'products_" . $column . "_fkey'")->fetchColumn(), $column . ' is constrained after the repaired rerun');
		}
	}

	public function testUpstreamZeroMeansUnsetIsNormalisedNotRefused(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES(604, 'Upstream zero', 601, 601, 601)");
		// default_qu_id_consume/_price replace 0 on INSERT, so 0 only survives a write that
		// bypassed them - an earlier import, which copies with user triggers disabled.
		self::WithoutUserTriggers(fn(PDO $db) => $db->exec('UPDATE products SET qu_id_consume = 0, qu_id_price = 0 WHERE id = 604'));

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		$row = $db->query('SELECT qu_id_consume, qu_id_price FROM products WHERE id = 604')->fetch(PDO::FETCH_ASSOC);
		self::assertNull($row['qu_id_consume']);
		self::assertNull($row['qu_id_price']);
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration = 295')->fetchColumn());
	}

	private static function WithoutUserTriggers(callable $work): void
	{
		$db = self::Pdo();
		$db->exec('SET session_replication_role = replica');
		try
		{
			$work($db);
		}
		finally
		{
			$db->exec('SET session_replication_role = DEFAULT');
		}
	}
}
