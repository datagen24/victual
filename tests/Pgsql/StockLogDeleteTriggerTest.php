<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #588 (#487 remediation): trg_stock_log_DEL
 * (db/pgsql/baseline/06_triggers_b.sql:145-159) cleared the price caches by the deleted
 * stock_log ROW's own id (`OLD.id`), not the `product_id` that row belonged to. Both id
 * sequences start at 1, so a deleted ledger row whose id happens to coincide with some
 * OTHER product's id wiped out that unrelated product's cached average price and
 * last-purchase data instead of the row's own product's.
 *
 * migrations/0292.pgsql.sql redefines the trigger function to filter by `OLD.product_id`.
 * This class drives the trigger directly with SQL - what is under test is the trigger's own
 * behaviour, not any particular caller of it - and engineers the exact id coincidence the
 * bug depends on: productB's own id is used, unmodified, as the explicit id of the
 * stock_log row that belongs to productA and gets deleted.
 */
class StockLogDeleteTriggerTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO quantity_units (id, name, name_plural) VALUES (9610, 'DelTrigQU', 'DelTrigQUs')");
		self::$db->exec("INSERT INTO locations (id, name) VALUES (9610, 'DelTrigShelf')");

		// productB's id (9611) is deliberately reused below as the explicit id of a
		// stock_log row belonging to productA - the exact coincidence the buggy trigger
		// mistook for a product id.
		self::$db->exec('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) '
			. "VALUES (9610, 'DelTrigProductA', 9610, 9610, 9610)");
		self::$db->exec('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) '
			. "VALUES (9611, 'DelTrigProductB', 9610, 9610, 9610)");
	}

	/** @return array<string, mixed>|null */
	private static function averagePriceCacheRow(int $productId): ?array
	{
		$statement = self::$db->prepare('SELECT product_id, price FROM cache__products_average_price WHERE product_id = ?');
		$statement->execute([$productId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	/** @return array<string, mixed>|null */
	private static function lastPurchasedCacheRow(int $productId): ?array
	{
		$statement = self::$db->prepare('SELECT product_id, amount, price FROM cache__products_last_purchased WHERE product_id = ?');
		$statement->execute([$productId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	/**
	 * Deleting a ledger row must leave every OTHER product's cache untouched and must not
	 * corrupt the deleted row's own product's cache by using the wrong key.
	 *
	 * With the pre-fix trigger (`OLD.id`), deleting stock_log row 9611 (productA's booking,
	 * given that explicit id) matches `product_id = 9611`, which is productB's id - so
	 * productB's cache rows are wiped even though nothing about productB changed. With the
	 * fix (`OLD.product_id`), the same delete instead clears productA's own cache rows
	 * (its only booking is gone) and leaves productB's cache exactly as it was.
	 */
	public function testDeletingLedgerRowClearsOnlyItsOwnProductsCache(): void
	{
		// productB's own booking, ordinary auto-assigned id, price 5.00.
		self::$db->exec("INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) "
			. "VALUES (9611, 2, '2035-01-01', '2026-01-01', 'deltrig-stock-b', 'purchase', 5.00, 0, 9000)");

		// productA's booking, EXPLICIT id 9611 - the same value as productB's own id. This
		// is the coincidence the bug depends on: the row belongs to productA
		// (product_id = 9610) but its own primary key equals productB's id.
		self::$db->exec("INSERT INTO stock_log (id, product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) "
			. "VALUES (9611, 9610, 3, '2035-01-01', '2026-01-01', 'deltrig-stock-a', 'purchase', 7.50, 0, 9000)");

		// Sanity: the INSERT trigger (trg_stock_log_INS, unaffected by this issue) built a
		// cache row for both products already, keyed correctly by product_id in both cases.
		$productAAveragePriceBefore = self::averagePriceCacheRow(9610);
		$productALastPurchasedBefore = self::lastPurchasedCacheRow(9610);
		$productBAveragePriceBefore = self::averagePriceCacheRow(9611);
		$productBLastPurchasedBefore = self::lastPurchasedCacheRow(9611);

		self::assertNotNull($productAAveragePriceBefore, 'productA should have an average-price cache row before the delete');
		self::assertNotNull($productALastPurchasedBefore, 'productA should have a last-purchased cache row before the delete');
		self::assertNotNull($productBAveragePriceBefore, 'productB should have an average-price cache row before the delete');
		self::assertNotNull($productBLastPurchasedBefore, 'productB should have a last-purchased cache row before the delete');
		self::assertSame(5.0, $productBAveragePriceBefore['price']);

		// Delete the ledger row the way a real deletion identifies one: by its own primary
		// key. This is productA's only booking, filed under stock_log.id = 9611.
		self::$db->exec('DELETE FROM stock_log WHERE id = 9611 AND product_id = 9610');

		// productA's own cache rows must reflect the deletion of its only booking: no
		// booking remains, so products_average_price/products_last_purchased return no
		// row for productA any more, and the fixed trigger removes the stale cache rows
		// rather than leaving them (or, worse, leaving them attached to the wrong product).
		self::assertNull(self::averagePriceCacheRow(9610), 'productA average-price cache row should be gone once its only booking is deleted');
		self::assertNull(self::lastPurchasedCacheRow(9610), 'productA last-purchased cache row should be gone once its only booking is deleted');

		// productB never had anything deleted - its cache rows must be byte-for-byte
		// unchanged. Under the pre-fix trigger, this is exactly what broke: OLD.id (9611)
		// matched productB's id, and productB's cache rows were wiped instead.
		self::assertSame($productBAveragePriceBefore, self::averagePriceCacheRow(9611), 'productB average-price cache row must be unaffected by deleting an unrelated product\'s ledger row');
		self::assertSame($productBLastPurchasedBefore, self::lastPurchasedCacheRow(9611), 'productB last-purchased cache row must be unaffected by deleting an unrelated product\'s ledger row');
	}
}
