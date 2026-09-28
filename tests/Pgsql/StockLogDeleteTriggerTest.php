<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #588 (#487 remediation): trg_stock_log_DEL
 * (db/pgsql/baseline/06_triggers_b.sql:145-159) cleared the price caches by the deleted
 * stock_log ROW's own id (`OLD.id`), not the `product_id` that row belonged to. Both id
 * sequences start at 1, so a deleted ledger row whose id happens to coincide with some
 * OTHER product's id wiped out that unrelated product's cached average price and
 * last-purchase data instead of the row's own product's.
 *
 * Round 2: a validator found the fix was incomplete. `trg_stock_log_UPD` has a related but
 * different bug - reachable from the UI, not just an id coincidence - and never removes a
 * cache row once the view it reads stops returning anything for a product (only ever
 * upserts). `StockService::UndoBooking()` undoing a product's only purchase hits exactly
 * this: `undone` flips to 1, `products_average_price`/`products_last_purchased` then return
 * no row for that product, but the stale cache row from before the undo stays forever.
 *
 * migrations/0292.pgsql.sql (same migration number both rounds) now redefines
 * `trg_stock_log_UPD` and `trg_stock_log_DEL` to share one rebuild
 * (`rebuild_stock_log_cache_for_product`): recompute what the two views return for a
 * product id and either replace the cache row or remove it, rather than only ever upserting
 * or only ever deleting.
 */
class StockLogDeleteTriggerTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Issue #533 (see MergeProductsTest's own setUpBeforeClass): this file runs last in
		// the "stockcoverage" testsuite phase, after other classes have already constructed
		// StockService against their own (by now dropped) schemas. testUndoOnlyPurchase...()
		// below drives StockService directly, so the cached instance has to be dropped first.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$db->exec("INSERT INTO quantity_units (id, name, name_plural) VALUES (9610, 'DelTrigQU', 'DelTrigQUs')");
		self::$db->exec("INSERT INTO locations (id, name) VALUES (9610, 'DelTrigShelf')");

		// productB's id (9611) is deliberately reused below as the explicit id of a
		// stock_log row belonging to productA - the exact coincidence the buggy trigger
		// mistook for a product id.
		self::insertProduct(9610, 'DelTrigProductA');
		self::insertProduct(9611, 'DelTrigProductB');

		// productC (9612): undone via the real StockService::UndoBooking() path.
		self::insertProduct(9612, 'DelTrigProductC');

		// productD (9613): two independent bookings, one of them deleted directly.
		self::insertProduct(9613, 'DelTrigProductD');

		// productE (9614, deleted via the product-delete cascade) and productF (9615, kept)
		// - the same id coincidence as A/B, but reached by deleting a PRODUCT rather than a
		// ledger row directly, per the issue's own example
		// (trg_cascade_product_removal -> `DELETE FROM stock_log WHERE product_id = OLD.id`).
		self::insertProduct(9614, 'DelTrigProductE');
		self::insertProduct(9615, 'DelTrigProductF');

		// productG (9616, corrupted with a missing cache row) and productH (9617, corrupted
		// with a stale one): reconcile_stock_log_cache()'s own regression test.
		self::insertProduct(9616, 'DelTrigProductG');
		self::insertProduct(9617, 'DelTrigProductH');
	}

	private static function insertProduct(int $id, string $name): void
	{
		self::$db->exec('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) '
			. "VALUES ($id, '$name', 9610, 9610, 9610, 9610, 9610)");
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
		$statement = self::$db->prepare('SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id '
			. 'FROM cache__products_last_purchased WHERE product_id = ?');
		$statement->execute([$productId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	/** What products_average_price itself currently computes for a product - the source of truth the cache must agree with. @return array<string, mixed>|null */
	private static function averagePriceViewRow(int $productId): ?array
	{
		$statement = self::$db->prepare('SELECT product_id, price FROM products_average_price WHERE product_id = ?');
		$statement->execute([$productId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	/** @return array<string, mixed>|null */
	private static function lastPurchasedViewRow(int $productId): ?array
	{
		$statement = self::$db->prepare('SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id '
			. 'FROM products_last_purchased WHERE product_id = ?');
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
	 * fix (`OLD.product_id`), the same delete instead rebuilds productA's own cache rows
	 * (its only booking is gone, so they are removed) and leaves productB's cache exactly
	 * as it was - compared here as full rows, not just a spot-checked column.
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
		// unchanged (every column, not just price). Under the pre-fix trigger, this is
		// exactly what broke: OLD.id (9611) matched productB's id, and productB's cache
		// rows were wiped instead.
		self::assertSame($productBAveragePriceBefore, self::averagePriceCacheRow(9611), 'productB average-price cache row must be unaffected by deleting an unrelated product\'s ledger row');
		self::assertSame($productBLastPurchasedBefore, self::lastPurchasedCacheRow(9611), 'productB last-purchased cache row must be unaffected by deleting an unrelated product\'s ledger row');
	}

	/**
	 * The bug Opus's validator found in round 2: undoing a product's only purchase through
	 * the real `StockService::UndoBooking()` path (which `undone = 1` UPDATEs the row,
	 * firing `trg_stock_log_UPD`) must leave no stale cache row behind once
	 * `products_average_price`/`products_last_purchased` return nothing for that product.
	 */
	public function testUndoOnlyPurchaseRemovesCacheRows(): void
	{
		$stock = StockService::GetInstance();

		$transactionId = null;
		$stock->AddProduct(9612, 3, '2035-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 9.0, 9610, null, $transactionId);

		$statement = self::$db->prepare("SELECT id FROM stock_log WHERE product_id = 9612 AND transaction_type = 'purchase' ORDER BY id DESC LIMIT 1");
		$statement->execute();
		$bookingId = (int)$statement->fetchColumn();
		self::assertGreaterThan(0, $bookingId, 'the purchase above should have produced a stock_log row');

		// Sanity: the purchase built cache rows the way trg_stock_log_INS always has.
		self::assertNotNull(self::averagePriceCacheRow(9612), 'productC should have an average-price cache row after its only purchase');
		self::assertNotNull(self::lastPurchasedCacheRow(9612), 'productC should have a last-purchased cache row after its only purchase');
		self::assertSame(9.0, self::averagePriceCacheRow(9612)['price']);

		$stock->UndoBooking($bookingId);

		// The view itself now returns nothing for productC - its only booking is undone.
		self::assertNull(self::averagePriceViewRow(9612), 'products_average_price should return no row for productC once its only booking is undone');
		self::assertNull(self::lastPurchasedViewRow(9612), 'products_last_purchased should return no row for productC once its only booking is undone');

		// The cache must agree: no stale row left over from before the undo.
		self::assertNull(self::averagePriceCacheRow(9612), 'productC average-price cache row must be removed once its only booking is undone');
		self::assertNull(self::lastPurchasedCacheRow(9612), 'productC last-purchased cache row must be removed once its only booking is undone');

		// And a reader that LEFT JOINs the cache (uihelper_product_details, which
		// StockService::GetProductDetails() exposes as avg_price/last_price) must show no
		// price rather than the undone booking's stale 9.0.
		$details = $stock->GetProductDetails(9612);
		self::assertNull($details['avg_price'], 'GetProductDetails()[\'avg_price\'] must be null once the only purchase is undone, not the stale 9.0');
		self::assertNull($details['last_price'], 'GetProductDetails()[\'last_price\'] must be null once the only purchase is undone, not the stale 9.0');
	}

	/**
	 * Deleting one of two bookings must leave the cache holding exactly what the view
	 * computes from the remaining one - not empty (a blanket DELETE by product_id, as
	 * round 1 alone left behind) and not the deleted booking's own stale values.
	 */
	public function testDeletingOneOfTwoBookingsRebuildsCacheFromTheOtherBooking(): void
	{
		self::$db->exec("INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) "
			. "VALUES (9613, 1, '2035-01-01', '2026-01-01', 'deltrig-stock-d1', 'purchase', 4.00, 0, 9000)");
		self::$db->exec("INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) "
			. "VALUES (9613, 1, '2035-01-01', '2026-01-02', 'deltrig-stock-d2', 'purchase', 6.00, 0, 9000)");

		$statement = self::$db->prepare("SELECT id FROM stock_log WHERE product_id = 9613 AND stock_id = 'deltrig-stock-d1'");
		$statement->execute();
		$firstBookingId = (int)$statement->fetchColumn();
		self::assertGreaterThan(0, $firstBookingId);

		self::$db->exec('DELETE FROM stock_log WHERE id = ' . $firstBookingId);

		// The second booking (price 6.00) is still there - the cache must reflect exactly
		// what the views now compute from it, not be empty and not still show 4.00/5.00
		// (the average of both).
		self::assertSame(self::averagePriceViewRow(9613), self::averagePriceCacheRow(9613), 'average-price cache must equal the view after one of two bookings is deleted');
		self::assertSame(self::lastPurchasedViewRow(9613), self::lastPurchasedCacheRow(9613), 'last-purchased cache must equal the view after one of two bookings is deleted');
		self::assertSame(6.0, self::averagePriceCacheRow(9613)['price']);
	}

	/**
	 * The issue's own example: ledger rows deleted in bulk by the product-delete cascade
	 * (trg_cascade_product_removal), with the same id coincidence as the first test - this
	 * time reached by deleting a product rather than a ledger row directly.
	 */
	public function testDeletingProductCascadeWithCoincidingIdsLeavesTheOtherProductsCacheUntouched(): void
	{
		// productF's own booking, ordinary auto-assigned id.
		self::$db->exec("INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) "
			. "VALUES (9615, 2, '2035-01-01', '2026-01-01', 'deltrig-stock-f', 'purchase', 8.00, 0, 9000)");

		// productE's booking, EXPLICIT id 9615 - productF's own id. productE is the one
		// about to be deleted; this is the id coincidence the cascade delete below hits.
		self::$db->exec("INSERT INTO stock_log (id, product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) "
			. "VALUES (9615, 9614, 1, '2035-01-01', '2026-01-01', 'deltrig-stock-e', 'purchase', 2.00, 0, 9000)");

		$productFAveragePriceBefore = self::averagePriceCacheRow(9615);
		$productFLastPurchasedBefore = self::lastPurchasedCacheRow(9615);
		self::assertNotNull($productFAveragePriceBefore);
		self::assertNotNull($productFLastPurchasedBefore);
		self::assertNotNull(self::averagePriceCacheRow(9614), 'productE should have a cache row before it is deleted');

		// Delete the product itself - trg_cascade_product_removal deletes its stock_log
		// rows (product_id = 9614), each firing trg_stock_log_DEL with OLD.id = 9615 for
		// the row above.
		self::$db->exec('DELETE FROM products WHERE id = 9614');

		self::assertNull(self::averagePriceCacheRow(9614), 'the deleted productE should have no cache row left');
		self::assertNull(self::lastPurchasedCacheRow(9614), 'the deleted productE should have no cache row left');

		self::assertSame($productFAveragePriceBefore, self::averagePriceCacheRow(9615), 'productF average-price cache row must be unaffected by deleting an unrelated product with a coinciding ledger-row id');
		self::assertSame($productFLastPurchasedBefore, self::lastPurchasedCacheRow(9615), 'productF last-purchased cache row must be unaffected by deleting an unrelated product with a coinciding ledger-row id');
	}

	/**
	 * CodeRabbit finding 4124417575 (Major, data integrity): fixing the triggers only
	 * repairs FUTURE writes. An install that already ran under the old triggers can have
	 * corruption on disk right now - a stale cache row for a product the views no longer
	 * return anything for, and a missing cache row for a product the views still do.
	 * migrations/0292.pgsql.sql also calls reconcile_stock_log_cache() once, reusing
	 * rebuild_stock_log_cache_for_product() rather than a second copy of the view logic.
	 *
	 * This deliberately builds both kinds of corruption directly against the cache
	 * tables - they have no trigger of their own, so writing to them bypasses the (already
	 * fixed) stock_log triggers entirely, reproducing exactly what the OLD triggers left
	 * behind on an upgraded install without needing to un-fix anything - then calls
	 * reconcile_stock_log_cache() the same way migrations/0292.pgsql.sql does, and asserts
	 * both caches end up equal to what their views compute.
	 */
	public function testReconcileRepairsHistoricCacheCorruption(): void
	{
		// productG: a real booking (the view has a price for it), but no cache row -
		// the "missing row" corruption.
		self::$db->exec("INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) "
			. "VALUES (9616, 1, '2035-01-01', '2026-01-01', 'deltrig-stock-g', 'purchase', 12.00, 0, 9000)");
		self::$db->exec('DELETE FROM cache__products_average_price WHERE product_id = 9616');
		self::$db->exec('DELETE FROM cache__products_last_purchased WHERE product_id = 9616');

		self::assertNotNull(self::averagePriceViewRow(9616), 'sanity: the view has a price for productG');
		self::assertNull(self::averagePriceCacheRow(9616), 'sanity: the cache was deliberately corrupted to have no row for productG');

		// productH: no stock_log rows at all (the view returns nothing for it), but a
		// cache row exists anyway - the "stale row" corruption.
		self::$db->exec("INSERT INTO cache__products_average_price (product_id, price) VALUES (9617, 999.99)");
		self::$db->exec("INSERT INTO cache__products_last_purchased (product_id, amount, price) VALUES (9617, 5, 999.99)");

		self::assertNull(self::averagePriceViewRow(9617), 'sanity: the view has nothing for productH (no bookings)');
		self::assertNotNull(self::averagePriceCacheRow(9617), 'sanity: the cache was deliberately corrupted to have a stale row for productH');

		self::$db->exec('SELECT reconcile_stock_log_cache()');

		self::assertSame(self::averagePriceViewRow(9616), self::averagePriceCacheRow(9616), 'reconcile_stock_log_cache() rebuilds the missing average-price cache row for productG to match the view');
		self::assertSame(self::lastPurchasedViewRow(9616), self::lastPurchasedCacheRow(9616), 'reconcile_stock_log_cache() rebuilds the missing last-purchased cache row for productG to match the view');

		self::assertNull(self::averagePriceCacheRow(9617), 'reconcile_stock_log_cache() removes the stale average-price cache row for productH');
		self::assertNull(self::lastPurchasedCacheRow(9617), 'reconcile_stock_log_cache() removes the stale last-purchased cache row for productH');
	}
}
