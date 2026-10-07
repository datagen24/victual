<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Generates tests/Pgsql/fixtures/lineage-legacy.json: stock history written by the real
 * StockService of a tree from before migration 0304 (ADR-0036), for the backfill fixture test
 * (tests/Pgsql/StockLineageBackfillTest.php, ADR-0036 acceptance prerequisite 3).
 *
 * It is not part of the suite and refuses to run against a tree that already has the lineage
 * tables, because the history it records is what an older image left behind: tag-rewriting
 * merges, no contributions, no allocations. Run it with generate.sh in this directory from a
 * checkout of such a tree; the committed fixture names the revision it came from.
 *
 * Each scenario uses its own product, so each is its own set of backfill families.
 */
class LegacyLineageFixtureGenerator extends PgsqlSchemaTestCase
{
	private const NEVER = '2999-12-31';

	private static PDO $db;
	private static StockService $stock;
	private static int $locationA;
	private static int $locationB;
	private static array $scenarios = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'lineage-fixture', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$stock = StockService::GetInstance();
		self::$locationA = self::insertRow('locations', ['name' => 'Lineage A']);
		self::$locationB = self::insertRow('locations', ['name' => 'Lineage B']);
	}

	private static function insertRow(string $table, array $columns): int
	{
		$statement = self::$db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($columns)) . ') VALUES ('
			. implode(', ', array_fill(0, count($columns), '?')) . ') RETURNING id');
		$statement->execute(array_values($columns));
		return (int)$statement->fetchColumn();
	}

	private static function product(string $scenario): int
	{
		$id = self::insertRow('products', ['name' => 'Lineage ' . $scenario, 'location_id' => self::$locationA,
			'qu_id_purchase' => 2, 'qu_id_stock' => 2, 'qu_id_consume' => 2, 'qu_id_price' => 2]);
		self::$scenarios[$scenario] = $id;
		return $id;
	}

	private static function purchase(int $product, float $amount): string
	{
		$transaction = null;
		self::$stock->AddProduct($product, $amount, self::NEVER, StockService::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, self::$locationA, null, $transaction);
		return $transaction;
	}

	private static function rowOf(int $product): int
	{
		return (int)self::$db->query('SELECT max(id) FROM stock WHERE product_id = ' . $product)->fetchColumn();
	}

	public function testGenerate(): void
	{
		self::assertFalse((bool)self::$db->query("SELECT to_regclass('stock_row_lots') IS NOT NULL")->fetchColumn(),
			'This generator records pre-ADR-0036 history and must run on a tree without migration 0304');

		$p = self::product('E_plain');
		self::purchase($p, 3);

		$p = self::product('E_consumed');
		self::purchase($p, 5);
		self::$stock->ConsumeProduct($p, 2, false, StockService::TRANSACTION_TYPE_CONSUME);

		$p = self::product('E_open_split');
		self::purchase($p, 5);
		self::$stock->OpenProduct($p, 2);

		$p = self::product('E_transfer_split');
		self::purchase($p, 5);
		self::$stock->TransferProduct($p, 2, self::$locationA, self::$locationB);

		$p = self::product('E_edit_down');
		self::purchase($p, 4);
		$row = self::rowOf($p);
		self::$stock->EditStockEntry($row, 3, self::NEVER, self::$locationA, null, 1.0, false, '2026-10-01');

		$p = self::product('E_with_undone_purchase');
		self::purchase($p, 2);
		self::$stock->UndoTransaction(self::purchase($p, 1));

		$p = self::product('X_merged');
		self::purchase($p, 3);
		self::purchase($p, 2);
		self::$stock->CompactStockEntries($p);

		$p = self::product('U_merged_consumed');
		self::purchase($p, 3);
		self::purchase($p, 2);
		self::$stock->CompactStockEntries($p);
		self::$stock->ConsumeProduct($p, 1, false, StockService::TRANSACTION_TYPE_CONSUME);

		$p = self::product('U_merged_edited');
		self::purchase($p, 3);
		self::purchase($p, 2);
		self::$stock->CompactStockEntries($p);
		$row = self::rowOf($p);
		self::$stock->EditStockEntry($row, 4, self::NEVER, self::$locationA, null, 1.0, false, '2026-10-01');

		// A split from before migration 0267 has no origin link. Deleting the link is the only
		// way to reproduce that history with a current service.
		$p = self::product('U_split_without_origin');
		self::purchase($p, 5);
		self::$stock->OpenProduct($p, 2);
		self::$db->exec('DELETE FROM stock_entry_origins WHERE stock_id IN (SELECT stock_id FROM stock WHERE product_id = ' . $p . ')');

		$p = self::product('N_undone');
		self::$stock->UndoTransaction(self::purchase($p, 2));

		$ids = implode(',', self::$scenarios);
		$dump = static fn(string $sql): array => self::$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
		$fixture = [
			'generated_against' => getenv('LINEAGE_FIXTURE_REVISION') ?: 'unknown',
			'scenarios' => self::$scenarios,
			'locations' => $dump('SELECT id, name FROM locations WHERE id IN (' . self::$locationA . ',' . self::$locationB . ') ORDER BY id'),
			'products' => $dump("SELECT id, name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price FROM products WHERE id IN ($ids) ORDER BY id"),
			'stock_log' => $dump("SELECT * FROM stock_log WHERE product_id IN ($ids) ORDER BY id"),
			'stock' => $dump("SELECT * FROM stock WHERE product_id IN ($ids) ORDER BY id"),
			'stock_entry_origins' => $dump("SELECT o.* FROM stock_entry_origins o WHERE o.stock_id IN (SELECT stock_id FROM stock WHERE product_id IN ($ids) UNION SELECT stock_id FROM stock_log WHERE product_id IN ($ids)) ORDER BY o.stock_id"),
		];
		$out = getenv('LINEAGE_FIXTURE_OUT');
		self::assertNotFalse($out, 'LINEAGE_FIXTURE_OUT names the file to write');
		file_put_contents($out, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}
}
