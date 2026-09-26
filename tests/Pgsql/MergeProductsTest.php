<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #503 (audit finding M3): StockService::MergeProducts() multiplied a moved stock/
 * stock_log row's amount by the removed product's stock-unit conversion factor but left its
 * per-unit price untouched, so a merge across units silently destroyed the row's monetary
 * value - 500 g at 0.01/g (value 5) became 0.5 "kg" still priced at 0.01/kg (value 0.005). A
 * product with a product_location_min_stock row also could not be merged at all:
 * migrations/0276.pgsql.sql gives that table a real FOREIGN KEY to products, unlike every
 * other table MergeProducts() touches, and nothing repointed its rows before the final
 * DELETE, so the statement failed with a foreign-key violation.
 *
 * Both fixes live entirely inside MergeProducts(), so this class drives StockService
 * directly rather than through StockApiController: the controller's id-validation refusals
 * (not-an-id, unknown id, self-merge) are already covered by
 * StockCoverageTest::testMergeRefusesUnusableProductPairs(), which this class must not edit
 * (see scratchpad/FIXER_RULES.md - other agents' tests sit near that file's end).
 */
class MergeProductsTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	/** Fixture ids shared by every test: a pantry location and a gram/kilogram unit pair. */
	private static array $ids = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'mergeproducts-caller', 'fixture')");

		// Issue #533: BaseService::GetInstance() caches one instance per class for the whole
		// PHPUnit process. StockCoverageTest, which runs before this class in the same
		// "stockcoverage" testsuite phase, already constructed StockService against its own
		// (by now dropped) schema. Left alone, StockService::GetInstance() below returns that
		// stale instance and every query fails with "relation ... does not exist" against
		// this class's own schema.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$ids['pantry'] = self::insertRow('locations', ['name' => 'Merge Pantry']);
		self::$ids['gram'] = self::insertRow('quantity_units', ['name' => 'Merge Gram', 'name_plural' => 'Merge Grams']);
		self::$ids['kilogram'] = self::insertRow('quantity_units', ['name' => 'Merge Kilogram', 'name_plural' => 'Merge Kilograms']);
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function insertProduct(string $name, array $columns = []): int
	{
		$columns = array_merge([
			'name' => $name,
			'location_id' => self::$ids['pantry'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		], $columns);

		return self::insertRow('products', $columns);
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function productExists(int $productId): bool
	{
		$statement = self::$db->prepare('SELECT 1 FROM products WHERE id = ?');
		$statement->execute([$productId]);

		return $statement->fetchColumn() !== false;
	}

	/** @return array<int, array<string, mixed>> */
	private static function stockRows(int $productId): array
	{
		$statement = self::$db->prepare('SELECT amount, price, location_id FROM stock WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	/** @return array<int, array<string, mixed>> */
	private static function ledgerRows(int $productId): array
	{
		$statement = self::$db->prepare("SELECT amount, price, transaction_type FROM stock_log WHERE product_id = ? ORDER BY id");
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function minStockRow(int $productId, int $locationId): ?array
	{
		$statement = self::$db->prepare('SELECT min_stock_amount FROM product_location_min_stock WHERE product_id = ? AND location_id = ?');
		$statement->execute([$productId, $locationId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	// ------------------------------------------------------------------------------
	// M3, first symptom: unit price is not rescaled
	// ------------------------------------------------------------------------------

	public function testMergeRescalesStockAndLedgerPricesByTheInverseUnitFactorSoValueIsPreserved(): void
	{
		$keep = self::insertProduct('Merge Kg Product', [
			'qu_id_purchase' => self::$ids['kilogram'],
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		$remove = self::insertProduct('Merge Gram Product', [
			'qu_id_purchase' => self::$ids['gram'],
			'qu_id_stock' => self::$ids['gram'],
			'qu_id_consume' => self::$ids['gram'],
			'qu_id_price' => self::$ids['gram'],
		]);
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['gram'],
			'to_qu_id' => self::$ids['kilogram'],
			'factor' => 0.001,
			'product_id' => $remove,
		]);

		// Given: 500 g purchased at 0.01/g, worth 5, booked as both a stock row and a
		// stock_log purchase entry.
		StockService::GetInstance()->AddProduct($remove, 500, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$ids['pantry']);

		$givenStock = self::stockRows($remove);
		self::assertCount(1, $givenStock, 'Given: the fixture purchase books exactly one stock row');
		self::assertEqualsWithDelta(5.0, $givenStock[0]['amount'] * $givenStock[0]['price'], 1e-9, 'Given: 500 g at 0.01/g is worth 5');

		// When: the gram product is merged into the kilogram product.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the row moved, amount converted g -> kg (500 * 0.001), price rescaled by the
		// inverse factor (0.01 / 0.001) so amount * price - the monetary value - is unchanged.
		$afterStock = self::stockRows($keep);
		self::assertCount(1, $afterStock, 'Then: the removed product\'s one stock row moved to the kept product');
		self::assertEqualsWithDelta(0.5, $afterStock[0]['amount'], 1e-9, 'Then: amount is converted by the g->kg factor');
		self::assertEqualsWithDelta(10.0, $afterStock[0]['price'], 1e-9, 'Then: price is rescaled by the inverse factor to price-per-kg');
		self::assertEqualsWithDelta(5.0, $afterStock[0]['amount'] * $afterStock[0]['price'], 1e-9, 'Then: the row\'s monetary value survives the unit change (issue #503, M3)');

		$afterLedger = self::ledgerRows($keep);
		self::assertCount(1, $afterLedger, 'Then: the purchase booking itself also moved to the kept product\'s ledger');
		self::assertEqualsWithDelta(0.5, $afterLedger[0]['amount'], 1e-9, 'Then: stock_log.amount is rescaled the same way as stock.amount');
		self::assertEqualsWithDelta(10.0, $afterLedger[0]['price'], 1e-9, 'Then: stock_log.price is rescaled the same way as stock.price');

		self::assertSame([], self::stockRows($remove), 'Then: nothing remains attributed to the removed product id');
		self::assertFalse(self::productExists($remove), 'Then: the removed product row itself is gone');
	}

	// ------------------------------------------------------------------------------
	// M3, second symptom: product_location_min_stock's foreign key
	// ------------------------------------------------------------------------------

	public function testMergeConvertsLocationMinimumsByTheUnitFactorWhenUnitsDiffer(): void
	{
		$keep = self::insertProduct('Merge Min Kg Product', [
			'qu_id_purchase' => self::$ids['kilogram'],
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		$remove = self::insertProduct('Merge Min Gram Product', [
			'qu_id_purchase' => self::$ids['gram'],
			'qu_id_stock' => self::$ids['gram'],
			'qu_id_consume' => self::$ids['gram'],
			'qu_id_price' => self::$ids['gram'],
		]);
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['gram'],
			'to_qu_id' => self::$ids['kilogram'],
			'factor' => 0.001,
			'product_id' => $remove,
		]);
		$location = self::insertRow('locations', ['name' => 'Merge Min Location']);

		// Given: the removed (gram) product has a 2000 g minimum at this location, and the
		// final DELETE FROM products would otherwise violate product_location_min_stock's
		// foreign key before any conversion is even considered.
		self::insertRow('product_location_min_stock', ['product_id' => $remove, 'location_id' => $location, 'min_stock_amount' => 2000]);

		// When: the gram product is merged into the kilogram product.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the merge completes (no foreign-key violation) and the minimum is repointed
		// to the kept product, converted by the same g->kg factor as every other amount.
		self::assertFalse(self::productExists($remove), 'Then: the merge completes instead of failing on product_location_min_stock\'s foreign key (issue #503, M3\'s second symptom)');

		$repointed = self::minStockRow($keep, $location);
		self::assertNotNull($repointed, 'Then: the removed product\'s minimum is repointed to the kept product');
		self::assertEqualsWithDelta(2.0, (float)$repointed['min_stock_amount'], 1e-9, 'Then: min_stock_amount is converted by the g->kg factor (2000 * 0.001)');

		self::assertNull(self::minStockRow($remove, $location), 'Then: nothing remains attributed to the removed product id');
	}

	public function testMergeKeepsTheKeptProductsOwnLocationMinimumOnConflictAndDropsTheRemovedRow(): void
	{
		$keep = self::insertProduct('Merge Min Conflict Keep');
		$remove = self::insertProduct('Merge Min Conflict Remove');
		$sharedLocation = self::insertRow('locations', ['name' => 'Merge Min Conflict Shared Location']);
		$onlyOnRemoveLocation = self::insertRow('locations', ['name' => 'Merge Min Conflict Remove-Only Location']);

		// Given: both products already have their own minimum at the same location, and the
		// removed product also has a minimum nowhere else defined. Same stock unit (factor 1)
		// on both sides, so this isolates the conflict rule from the unit-conversion arithmetic
		// already covered above.
		self::insertRow('product_location_min_stock', ['product_id' => $keep, 'location_id' => $sharedLocation, 'min_stock_amount' => 3]);
		self::insertRow('product_location_min_stock', ['product_id' => $remove, 'location_id' => $sharedLocation, 'min_stock_amount' => 9]);
		self::insertRow('product_location_min_stock', ['product_id' => $remove, 'location_id' => $onlyOnRemoveLocation, 'min_stock_amount' => 4]);

		// When: the products are merged.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the kept product's own minimum at the shared location survives unchanged -
		// the removed product's conflicting row is dropped, not overwritten or duplicated -
		// while the removed product's only-on-remove minimum is repointed as usual.
		$sharedAfter = self::minStockRow($keep, $sharedLocation);
		self::assertNotNull($sharedAfter, 'Then: the kept product still has a minimum at the shared location');
		self::assertEqualsWithDelta(3.0, (float)$sharedAfter['min_stock_amount'], 1e-9, 'Then: the kept product\'s own minimum wins - it is not overwritten by the removed product\'s conflicting row');

		self::assertNull(self::minStockRow($remove, $sharedLocation), 'Then: the removed product\'s conflicting row at the shared location is dropped, not duplicated');

		$repointed = self::minStockRow($keep, $onlyOnRemoveLocation);
		self::assertNotNull($repointed, 'Then: the removed product\'s only-on-remove minimum is still repointed to the kept product');
		self::assertEqualsWithDelta(4.0, (float)$repointed['min_stock_amount'], 1e-9, 'Then: with no unit conversion in play, the amount is unchanged (factor 1)');
	}
}
