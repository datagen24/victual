<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #553 (maintainer decision D4, discovered validating PR #550 for the #487
 * remediation): a sub product whose stock unit has no resolved conversion to the parent's
 * own stock unit (no row in cache__quantity_unit_conversions_resolved) was substituted 1:1
 * instead of being excluded. Both StockService::SumStockEntriesInProductUnit()'s availability
 * check (`$conversion != null ? ($stockEntry->amount / $conversion->factor) :
 * $stockEntry->amount`) and ConsumeProduct()'s/OpenProduct()'s own substitution loops (the
 * same ternary, applied to $amount instead) fell back to treating the unconvertible amount as
 * already being in the parent's own unit whenever the conversion lookup returned null -
 * indistinguishable, in that fallback, from a deliberate 1.0 factor. Reproduced on master
 * `253fe3aa`: consuming 1 parent unit with the only sub product stock being a can with no
 * can->parent conversion took the whole can as if it were 1 parent unit.
 *
 * Fixed at the single point both of those callers draw their candidate set from -
 * StockService::GetProductStockEntries() - which now excludes a sub product from the
 * substitution IN (...) clause entirely unless cache__quantity_unit_conversions_resolved
 * already resolves its own stock unit from the parent's. An excluded sub product is never
 * touched: not summed by the availability check, not iterated by the consume/open loop, and
 * its own stock row is asserted unchanged in every case below.
 *
 * Every case is Given/When/Then on the rows a caller can query afterwards, through the real
 * service (no HTTP transport), matching StockScopedAvailabilityTest.php's own convention.
 */
class SubProductUnitConvertibilityTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockService $stock;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// See StockScopedAvailabilityTest::setUpBeforeClass() - BaseService::$Instances is a
		// process-lifetime cache keyed by class, not by schema, so a sibling PgsqlSchemaTestCase
		// class that already constructed StockService would otherwise hand this class back a
		// singleton still bound to a schema its own tearDownAfterClass() already dropped
		// (issue #533).
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$db = self::Pdo();
		self::$stock = StockService::GetInstance();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9100, 'sub-product-unit-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT 9100, id FROM permission_hierarchy WHERE name = 'ADMIN'");
	}

	// ---- fixture helpers (mirrors StockScopedAvailabilityTest.php) -------------------

	private static function location(string $name): int
	{
		$stmt = self::$db->prepare('INSERT INTO locations(name) VALUES (?) RETURNING id');
		$stmt->execute([$name]);
		return (int)$stmt->fetchColumn();
	}

	private static function product(string $name, int $locationId, int $quId, ?int $parentProductId = null): int
	{
		$stmt = self::$db->prepare('INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, parent_product_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
		$stmt->execute([$name, $locationId, $quId, $quId, $quId, $quId, $parentProductId]);
		return (int)$stmt->fetchColumn();
	}

	private static function quantityUnit(string $name): int
	{
		$stmt = self::$db->prepare('INSERT INTO quantity_units(name, name_plural) VALUES (?, ?) RETURNING id');
		$stmt->execute([$name, $name]);
		return (int)$stmt->fetchColumn();
	}

	/** One-directional; trg_quantity_unit_conversions_INS mints the inverse row and cache entries for both directions. */
	private static function conversion(int $productId, int $fromQuId, int $toQuId, float $factor): void
	{
		$stmt = self::$db->prepare('INSERT INTO quantity_unit_conversions(product_id, from_qu_id, to_qu_id, factor) VALUES (?, ?, ?, ?)');
		$stmt->execute([$productId, $fromQuId, $toQuId, $factor]);
	}

	/** @return array{id: int, stock_id: string} */
	private static function stockRow(int $productId, float $amount, ?int $locationId, string $bestBeforeDate = '2035-01-01'): array
	{
		$stockId = 'entry-' . bin2hex(random_bytes(6));
		$stmt = self::$db->prepare('INSERT INTO stock(product_id, amount, stock_id, location_id, best_before_date) VALUES (?, ?, ?, ?, ?) RETURNING id');
		$stmt->execute([$productId, $amount, $stockId, $locationId, $bestBeforeDate]);
		return ['id' => (int)$stmt->fetchColumn(), 'stock_id' => $stockId];
	}

	private static function stockSnapshot(int $productId): array
	{
		$stmt = self::$db->prepare('SELECT stock_id, amount, open FROM stock WHERE product_id = ? ORDER BY id');
		$stmt->execute([$productId]);
		return array_map(function (array $row) {
			$row['amount'] = round((float)$row['amount'], 6);
			$row['open'] = (int)$row['open'];
			return $row;
		}, $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	// ---- Consume: an unconvertible sub product is excluded, never counted 1:1 -------

	public function testConsumeRefusesWhenTheOnlySubProductStockIsUnconvertibleAndLeavesItUnchanged(): void
	{
		// Given: a parent with no stock of its own, and a can-unit sub product holding 1
		// can, with no quantity_unit_conversions row between the can and the parent's own
		// unit at all - genuinely unconvertible, not merely at factor 1.
		$locationA = self::location('Unconvertible Only Location');
		$canUnit = self::quantityUnit('Unconvertible Only Can');
		$parentId = self::product('Unconvertible Only Parent', $locationA, 2);
		$childId = self::product('Unconvertible Only Child', $locationA, $canUnit, $parentId);
		self::stockRow($childId, 1, $locationA);
		$before = self::stockSnapshot($childId);

		// When: consuming 1 parent unit with substitution allowed. Before the fix, the
		// unconvertible can was counted 1:1 and this succeeded, taking the whole can as if
		// it were 1 parent unit.
		$tx = null;
		try
		{
			self::$stock->ConsumeProduct($parentId, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx, true);
			self::fail('Consuming against an unconvertible sub product must be refused, not treated as 1:1 stock');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)', $ex->getMessage());
		}

		// Then: the unconvertible child's stock row is completely untouched.
		self::assertSame($before, self::stockSnapshot($childId));
	}

	public function testConsumeExcludesAnUnconvertibleSubProductAndStillFulfilsFromAConvertibleOne(): void
	{
		// Given: two sub products of different units - one (can) has no conversion to the
		// parent's unit, the other (box, factor 2) does. Both hold stock.
		$locationA = self::location('Mixed Convertibility Location');
		$canUnit = self::quantityUnit('Mixed Convertibility Can');
		$boxUnit = self::quantityUnit('Mixed Convertibility Box');
		$parentId = self::product('Mixed Convertibility Parent', $locationA, 2);
		$unconvertibleChildId = self::product('Mixed Convertibility Can Child', $locationA, $canUnit, $parentId);
		$convertibleChildId = self::product('Mixed Convertibility Box Child', $locationA, $boxUnit, $parentId);
		self::conversion($convertibleChildId, 2, $boxUnit, 2.0);
		self::stockRow($unconvertibleChildId, 5, $locationA, '2030-01-01');
		self::stockRow($convertibleChildId, 2, $locationA, '2030-02-02');
		$unconvertibleBefore = self::stockSnapshot($unconvertibleChildId);

		// When: consuming exactly 1 parent unit (= 2 boxes) with substitution allowed - the
		// unconvertible can stock must never be counted, so this can only be fulfilled from
		// the 2 boxes.
		$tx = null;
		self::$stock->ConsumeProduct($parentId, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx, true);

		// Then: the box entry is fully consumed, and the unconvertible can entry is
		// completely untouched - it was never a candidate at all.
		self::assertSame([], self::stockSnapshot($convertibleChildId));
		self::assertSame($unconvertibleBefore, self::stockSnapshot($unconvertibleChildId));
	}

	// ---- Open: the same exclusion rule applies -----------------------------------------

	public function testOpenRefusesWhenTheOnlySubProductStockIsUnconvertibleAndLeavesItUnchanged(): void
	{
		$locationA = self::location('Unconvertible Open Location');
		$canUnit = self::quantityUnit('Unconvertible Open Can');
		$parentId = self::product('Unconvertible Open Parent', $locationA, 2);
		$childId = self::product('Unconvertible Open Child', $locationA, $canUnit, $parentId);
		self::stockRow($childId, 1, $locationA);
		$before = self::stockSnapshot($childId);

		$tx = null;
		try
		{
			self::$stock->OpenProduct($parentId, 1, 'default', $tx, true);
			self::fail('Opening against an unconvertible sub product must be refused, not treated as 1:1 stock');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be opened cannot be > current unopened stock amount', $ex->getMessage());
		}

		self::assertSame($before, self::stockSnapshot($childId));
	}

	public function testOpenExcludesAnUnconvertibleSubProductAndStillFulfilsFromAConvertibleOne(): void
	{
		$locationA = self::location('Mixed Convertibility Open Location');
		$canUnit = self::quantityUnit('Mixed Convertibility Open Can');
		$boxUnit = self::quantityUnit('Mixed Convertibility Open Box');
		$parentId = self::product('Mixed Convertibility Open Parent', $locationA, 2);
		$unconvertibleChildId = self::product('Mixed Convertibility Open Can Child', $locationA, $canUnit, $parentId);
		$convertibleChildId = self::product('Mixed Convertibility Open Box Child', $locationA, $boxUnit, $parentId);
		self::conversion($convertibleChildId, 2, $boxUnit, 2.0);
		self::stockRow($unconvertibleChildId, 5, $locationA, '2030-01-01');
		$boxEntry = self::stockRow($convertibleChildId, 2, $locationA, '2030-02-02');
		$unconvertibleBefore = self::stockSnapshot($unconvertibleChildId);

		$tx = null;
		self::$stock->OpenProduct($parentId, 1, 'default', $tx, true);

		$boxRows = self::stockSnapshot($convertibleChildId);
		self::assertCount(1, $boxRows);
		self::assertSame($boxEntry['stock_id'], $boxRows[0]['stock_id']);
		self::assertSame(1, $boxRows[0]['open']);
		self::assertSame(2.0, $boxRows[0]['amount']);

		self::assertSame($unconvertibleBefore, self::stockSnapshot($unconvertibleChildId));
	}

	// ---- GetProductStockLocations: the same exclusion rule applies --------------------

	public function testStockLocationsExcludesALocationHoldingOnlyAnUnconvertibleSubProduct(): void
	{
		// Given: location A holds the parent's own stock; location B holds only the
		// unconvertible can child's stock and nothing else substitutable there.
		$locationA = self::location('Stock Locations Convertibility A');
		$locationB = self::location('Stock Locations Convertibility B');
		$canUnit = self::quantityUnit('Stock Locations Convertibility Can');
		$parentId = self::product('Stock Locations Convertibility Parent', $locationA, 2);
		$childId = self::product('Stock Locations Convertibility Child', $locationA, $canUnit, $parentId);
		self::stockRow($parentId, 1, $locationA);
		self::stockRow($childId, 5, $locationB);

		// When: listing this product's stock locations with substitution allowed.
		$locationIds = array_map(fn($row) => (int)$row->location_id, iterator_to_array(self::$stock->GetProductStockLocations($parentId, true)));

		// Then: location A (the parent's own stock) is offered; location B, which holds only
		// the unconvertible child's stock, is not - it would otherwise show a location whose
		// real (converted) maximum is 0.
		self::assertContains($locationA, $locationIds);
		self::assertNotContains($locationB, $locationIds);
	}
}
