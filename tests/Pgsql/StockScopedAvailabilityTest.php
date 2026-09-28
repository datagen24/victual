<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #487 workstream 18 (scoped availability): regressions for #490 (H1) and #493 (H4).
 *
 * ConsumeProduct(), OpenProduct() and TransferProduct() each validated a requested amount
 * against a product- or location-wide aggregate before narrowing to the actual candidate set
 * (a location, a named stock entry, or - with substitution - a mixed-unit set of sub product
 * entries). A scope narrower than that aggregate could hold less than it, so the check passed
 * while the loop below it only partially fulfilled the request and still reported success:
 * consuming 4 at a location holding 2 (of 7 product-wide) booked only -2 (H1), and opening one
 * parent unit that converts to 4 child cans opened all 7 cans held across two child entries
 * instead of 4 (H4, a second and independent bug in the substitution loop's own remainder
 * arithmetic - see testOpenMixedUnitSubstitutionOpensExactlyTheRequestedAmountAcrossTwoEntries()).
 *
 * Every case is Given/When/Then on the rows a caller can query afterwards, through the real
 * service (no HTTP transport), asserting `stock`/`stock_log` rows rather than only a return
 * value, and verifying a refusal leaves every row byte-for-byte unchanged - matching
 * StockCoverageTest.php's and the sibling #487 workstreams' own convention.
 */
class StockScopedAvailabilityTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockService $stock;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Every BaseService subclass (StockService, UsersService, ...) caches its
		// GetInstance() singleton - and the LessQL connection wrapper captured at its
		// first construction - for the life of the PHP process (BaseService::$Instances).
		// This testsuite names several PgsqlSchemaTestCase classes that all touch
		// StockService: without this reset, StockService::GetInstance() here would return
		// an earlier class's already-constructed singleton, still bound to the schema its
		// own tearDownAfterClass() already dropped ("relation stock does not exist") -
		// issue #533. Clearing the cache forces every service singleton to be
		// reconstructed against this class's own connection instead.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$db = self::Pdo();
		self::$stock = StockService::GetInstance();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'scoped-availability-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
	}

	// ---- fixture helpers -------------------------------------------------------------

	private static function location(string $name): int
	{
		$stmt = self::$db->prepare('INSERT INTO locations(name) VALUES (?) RETURNING id');
		$stmt->execute([$name]);
		return (int)$stmt->fetchColumn();
	}

	private static function product(string $name, int $locationId, int $quId = 2, ?int $parentProductId = null): int
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
	private static function stockRow(int $productId, float $amount, ?int $locationId, string $bestBeforeDate = '2035-01-01', bool $open = false): array
	{
		$stockId = 'entry-' . bin2hex(random_bytes(6));
		$stmt = self::$db->prepare('INSERT INTO stock(product_id, amount, stock_id, location_id, best_before_date, open) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$stmt->execute([$productId, $amount, $stockId, $locationId, $bestBeforeDate, $open ? 1 : 0]);
		return ['id' => (int)$stmt->fetchColumn(), 'stock_id' => $stockId];
	}

	private static function normalizeRow(array $row): array
	{
		if (array_key_exists('amount', $row) && $row['amount'] !== null)
		{
			$row['amount'] = round((float)$row['amount'], 6);
		}
		if (array_key_exists('location_id', $row) && $row['location_id'] !== null)
		{
			$row['location_id'] = (int)$row['location_id'];
		}
		if (array_key_exists('open', $row))
		{
			$row['open'] = (int)$row['open'];
		}
		if (array_key_exists('undone', $row))
		{
			$row['undone'] = (int)$row['undone'];
		}
		return $row;
	}

	private static function stockSnapshot(array $productIds): array
	{
		$placeholders = implode(',', array_fill(0, count($productIds), '?'));
		$stmt = self::$db->prepare("SELECT id, product_id, stock_id, amount, location_id, open, opened_date, best_before_date FROM stock WHERE product_id IN ($placeholders) ORDER BY id");
		$stmt->execute($productIds);
		return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	private static function ledgerSnapshot(array $productIds): array
	{
		$placeholders = implode(',', array_fill(0, count($productIds), '?'));
		$stmt = self::$db->prepare("SELECT id, product_id, stock_id, amount, transaction_type, location_id, undone FROM stock_log WHERE product_id IN ($placeholders) ORDER BY id");
		$stmt->execute($productIds);
		return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	private static function state(array $productIds): array
	{
		return ['stock' => self::stockSnapshot($productIds), 'stock_log' => self::ledgerSnapshot($productIds)];
	}

	// ---- H1 (#490): refusals scoped to a location or a named entry --------------------

	public function testConsumeRefusesWhenLocationScopeCannotFulfilAndLeavesStateUnchanged(): void
	{
		// Given: the exact H1 reproduction - location A holds 2, location B holds 5 (7 product-wide).
		$locationA = self::location('H1 Consume A');
		$locationB = self::location('H1 Consume B');
		$productId = self::product('H1 Consume Product', $locationA);
		self::stockRow($productId, 2, $locationA, '2030-01-01');
		self::stockRow($productId, 5, $locationB, '2030-01-01');
		$before = self::state([$productId]);

		// When: consuming 4, scoped to location A, which only holds 2.
		try
		{
			self::$stock->ConsumeProduct($productId, 4, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, $locationA);
			self::fail('Consuming more than the scoped location holds must be refused');
		}
		catch (\Exception $ex)
		{
			// Then: refused by the existing availability message (now checked against the
			// scoped candidate set instead of the product-wide aggregate), and nothing written.
			self::assertSame('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)', $ex->getMessage());
		}
		self::assertSame($before, self::state([$productId]));
	}

	public function testTransferRefusesWhenNamedEntryScopeCannotFulfilAndLeavesRowsUnchanged(): void
	{
		// Given: the exact H1 reproduction - both entries at location A (2 due first, 5 due later).
		$locationA = self::location('H1 Transfer A');
		$locationB = self::location('H1 Transfer B');
		$productId = self::product('H1 Transfer Product', $locationA);
		$entryTwo = self::stockRow($productId, 2, $locationA, '2030-01-01');
		self::stockRow($productId, 5, $locationA, '2030-02-02');
		$before = self::state([$productId]);

		// When: transferring 4, named to exactly the 2-unit entry.
		try
		{
			self::$stock->TransferProduct($productId, 4, $locationA, $locationB, $entryTwo['stock_id']);
			self::fail('Transferring more than the named entry holds must be refused');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be transferred cannot be > current stock amount at the source location', $ex->getMessage());
		}
		self::assertSame($before, self::state([$productId]));
	}

	public function testConsumeRefusesWhenNamedEntryHoldsZeroAndLeavesStateUnchanged(): void
	{
		// Given: a named entry holding zero - e.g. left by WeighLocation() or an
		// EditStockEntry(..., 0) correction - plus a second, larger entry of the same
		// product so the product-wide total (10) is nonzero while the named entry's own
		// scope is not: a product-wide check would wrongly allow this request against the
		// *other* entry's stock even though it names only the empty one. Coordinated with
		// PR #531, which adds a skip-continue guard for a zero-amount row inside
		// ConsumeProduct()'s own loop: without this pre-check running first, naming
		// exactly one zero-amount entry would let the loop skip its only candidate and
		// return a transaction id that booked nothing, which the API layer then reports
		// as "No transaction was found" - a confusing partial-success shape. The scoped
		// availability check refuses this atomically instead, before the loop ever runs.
		$locationA = self::location('Zero Entry Location');
		$productId = self::product('Zero Entry Product', $locationA);
		$zeroEntry = self::stockRow($productId, 0, $locationA, '2030-01-01');
		self::stockRow($productId, 10, $locationA, '2030-02-02');
		$before = self::state([$productId]);

		// When: consuming a positive amount scoped to exactly that zero-amount entry.
		try
		{
			self::$stock->ConsumeProduct($productId, 1, false, StockService::TRANSACTION_TYPE_CONSUME, $zeroEntry['stock_id']);
			self::fail('Consuming from a named zero-amount entry must be refused, not silently produce an empty transaction');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)', $ex->getMessage());
		}
		self::assertSame($before, self::state([$productId]));
	}

	// ---- Positive controls: exact-fit and partial-from-several-rows within scope ------

	public function testConsumeExactFitAcrossTwoRowsAtScopedLocationSucceeds(): void
	{
		$locationA = self::location('Exact Fit A');
		$locationB = self::location('Exact Fit B control');
		$productId = self::product('Exact Fit Product', $locationA);
		self::stockRow($productId, 2, $locationA, '2030-01-01');
		self::stockRow($productId, 3, $locationA, '2030-02-02');
		self::stockRow($productId, 100, $locationB, '2030-01-01');

		// When: consuming exactly what location A holds across its two rows (5 = 2 + 3).
		self::$stock->ConsumeProduct($productId, 5, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, $locationA);

		// Then: both rows at A are gone; the unrelated location B row is untouched.
		$rows = self::stockSnapshot([$productId]);
		self::assertCount(1, $rows);
		self::assertSame($locationB, $rows[0]['location_id']);
		self::assertSame(100.0, $rows[0]['amount']);

		$ledgerAmounts = array_map(fn($row) => $row['amount'], self::ledgerSnapshot([$productId]));
		sort($ledgerAmounts);
		self::assertSame([-3.0, -2.0], $ledgerAmounts);
	}

	public function testConsumePartialFromTheSecondOfSeveralRowsAtScopedLocationSucceeds(): void
	{
		$locationA = self::location('Partial Rows A');
		$locationB = self::location('Partial Rows B control');
		$productId = self::product('Partial Rows Product', $locationA);
		self::stockRow($productId, 2, $locationA, '2030-01-01');
		$entryFive = self::stockRow($productId, 5, $locationA, '2030-02-02');
		self::stockRow($productId, 100, $locationB, '2030-01-01');

		// When: consuming 4 at A - the whole 2-row plus 2 of the 5-row (7 available at A).
		self::$stock->ConsumeProduct($productId, 4, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, $locationA);

		$rows = self::stockSnapshot([$productId]);
		self::assertCount(2, $rows);
		$atA = array_values(array_filter($rows, fn($row) => $row['location_id'] === $locationA));
		self::assertCount(1, $atA);
		self::assertSame($entryFive['stock_id'], $atA[0]['stock_id']);
		self::assertSame(3.0, $atA[0]['amount']);
		$atB = array_values(array_filter($rows, fn($row) => $row['location_id'] === $locationB));
		self::assertSame(100.0, $atB[0]['amount']);
	}

	public function testTransferExactFitForNamedEntrySucceeds(): void
	{
		$locationA = self::location('Transfer Exact A');
		$locationB = self::location('Transfer Exact B');
		$productId = self::product('Transfer Exact Product', $locationA);
		$entryTwo = self::stockRow($productId, 2, $locationA, '2030-01-01');
		$entryFive = self::stockRow($productId, 5, $locationA, '2030-02-02');

		// When: transferring exactly what the named 2-unit entry holds.
		self::$stock->TransferProduct($productId, 2, $locationA, $locationB, $entryTwo['stock_id']);

		$byStockId = [];
		foreach (self::stockSnapshot([$productId]) as $row)
		{
			$byStockId[$row['stock_id']] = $row;
		}
		self::assertSame($locationB, $byStockId[$entryTwo['stock_id']]['location_id']);
		self::assertSame(2.0, $byStockId[$entryTwo['stock_id']]['amount']);
		self::assertSame($locationA, $byStockId[$entryFive['stock_id']]['location_id']);
		self::assertSame(5.0, $byStockId[$entryFive['stock_id']]['amount']);

		$byType = [];
		foreach (self::ledgerSnapshot([$productId]) as $row)
		{
			$byType[$row['transaction_type']] = $row;
		}
		self::assertSame(-2.0, $byType[StockService::TRANSACTION_TYPE_TRANSFER_FROM]['amount']);
		self::assertSame($locationA, $byType[StockService::TRANSACTION_TYPE_TRANSFER_FROM]['location_id']);
		self::assertSame(2.0, $byType[StockService::TRANSACTION_TYPE_TRANSFER_TO]['amount']);
		self::assertSame($locationB, $byType[StockService::TRANSACTION_TYPE_TRANSFER_TO]['location_id']);
	}

	// ---- H4 (#493): mixed-unit substitution ------------------------------------------

	public function testOpenMixedUnitSubstitutionOpensExactlyTheRequestedAmountAcrossTwoEntries(): void
	{
		// Given: the exact H4 reproduction - a parent product whose stock unit converts
		// to 4 child cans, with child stock split across two entries (2 due first, 5 due
		// later).
		$locationA = self::location('H4 Open Location');
		$canUnit = self::quantityUnit('H4 Open Can');
		$parentId = self::product('H4 Open Parent', $locationA);
		$childId = self::product('H4 Open Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 4.0);
		$entryTwo = self::stockRow($childId, 2, $locationA, '2030-01-01');
		$entryFive = self::stockRow($childId, 5, $locationA, '2030-02-02');

		// When: opening exactly one parent unit (= 4 cans) with substitution allowed.
		$tx = null;
		self::$stock->OpenProduct($parentId, 1, 'default', $tx, true);

		// Then: the 2-can entry opens fully and the 5-can entry splits into 2 opened + 3
		// still unopened - exactly 4 cans opened, not all 7. Before the fix, the
		// substitution loop never converted the remainder back out of the child's unit
		// after the first full take, so the second entry's factor was applied on top of
		// an amount already in cans and both entries opened in full.
		$rows = self::stockSnapshot([$childId]);
		self::assertCount(3, $rows);
		$openedTotal = array_sum(array_map(fn($row) => $row['open'] === 1 ? $row['amount'] : 0.0, $rows));
		$unopenedTotal = array_sum(array_map(fn($row) => $row['open'] === 0 ? $row['amount'] : 0.0, $rows));
		self::assertSame(4.0, $openedTotal);
		self::assertSame(3.0, $unopenedTotal);

		$byStockId = [];
		foreach ($rows as $row)
		{
			$byStockId[$row['stock_id']] = $row;
		}
		self::assertSame(1, $byStockId[$entryTwo['stock_id']]['open']);
		self::assertSame(2.0, $byStockId[$entryTwo['stock_id']]['amount']);
		self::assertSame(1, $byStockId[$entryFive['stock_id']]['open']);
		self::assertSame(2.0, $byStockId[$entryFive['stock_id']]['amount']);

		$ledger = self::ledgerSnapshot([$childId]);
		self::assertCount(2, $ledger);
		$ledgerAmounts = array_map(fn($row) => $row['amount'], $ledger);
		sort($ledgerAmounts);
		self::assertSame([2.0, 2.0], $ledgerAmounts);
		foreach ($ledger as $row)
		{
			self::assertSame('product-opened', $row['transaction_type']);
		}
	}

	public function testOpenMixedUnitSubstitutionRefusesWhenNamedEntryScopeIsInsufficient(): void
	{
		// Given: the same 2 + 5 (= 7) can fixture, but the request names only the 2-can
		// entry (0.5 in the parent's own unit). The product-wide converted total (1.75,
		// across both entries) is high enough to wrongly allow a 1-parent-unit request if
		// the check does not also respect this narrower scope - summing raw child amounts
		// without conversion would be wrong for the same reason (#487 correction 4), but a
		// converted *product-wide* total is equally wrong once a single named entry holds
		// less than it.
		$locationA = self::location('H4 Open Refuse Location');
		$canUnit = self::quantityUnit('H4 Open Refuse Can');
		$parentId = self::product('H4 Open Refuse Parent', $locationA);
		$childId = self::product('H4 Open Refuse Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 4.0);
		$entryTwo = self::stockRow($childId, 2, $locationA, '2030-01-01');
		self::stockRow($childId, 5, $locationA, '2030-02-02');
		$before = self::state([$childId]);

		// When: opening 1 parent unit (= 4 cans) named to exactly the 2-can entry.
		$tx = null;
		try
		{
			self::$stock->OpenProduct($parentId, 1, $entryTwo['stock_id'], $tx, true);
			self::fail('Opening more than the named entry holds (in the common unit) must be refused');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be opened cannot be > current unopened stock amount', $ex->getMessage());
		}
		self::assertSame($before, self::state([$childId]));
	}

	public function testConsumeMixedUnitSubstitutionConsumesAcrossTwoEntriesInCommonUnit(): void
	{
		$locationA = self::location('H4 Consume Location');
		$canUnit = self::quantityUnit('H4 Consume Can');
		$parentId = self::product('H4 Consume Parent', $locationA);
		$childId = self::product('H4 Consume Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 4.0);
		self::stockRow($childId, 2, $locationA, '2030-01-01');
		$entryFive = self::stockRow($childId, 5, $locationA, '2030-02-02');

		// When: consuming exactly one parent unit (= 4 cans) with substitution allowed.
		$tx = null;
		self::$stock->ConsumeProduct($parentId, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx, true);

		// Then: the 2-can entry is gone and the 5-can entry holds exactly 3 (2 + 2 = 4 taken).
		$rows = self::stockSnapshot([$childId]);
		self::assertCount(1, $rows);
		self::assertSame($entryFive['stock_id'], $rows[0]['stock_id']);
		self::assertSame(3.0, $rows[0]['amount']);

		$ledgerAmounts = array_map(fn($row) => $row['amount'], self::ledgerSnapshot([$childId]));
		sort($ledgerAmounts);
		self::assertSame([-2.0, -2.0], $ledgerAmounts);
	}

	public function testConsumeMixedUnitSubstitutionRefusesWhenNamedEntryScopeIsInsufficient(): void
	{
		// Given: the same 2 + 5 (= 7) can fixture, but the request names only the 2-can
		// entry (0.5 in the parent's own unit) - the product-wide converted total (1.75)
		// would wrongly allow a 1-parent-unit request if the check did not also respect
		// this narrower named-entry scope (#487 correction 4 extends to "a converted total
		// wider than the actual scope", not only to "an unconverted total").
		$locationA = self::location('H4 Consume Refuse Location');
		$canUnit = self::quantityUnit('H4 Consume Refuse Can');
		$parentId = self::product('H4 Consume Refuse Parent', $locationA);
		$childId = self::product('H4 Consume Refuse Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 4.0);
		$entryTwo = self::stockRow($childId, 2, $locationA, '2030-01-01');
		self::stockRow($childId, 5, $locationA, '2030-02-02');
		$before = self::state([$childId]);

		// When: consuming 1 parent unit (= 4 cans) named to exactly the 2-can entry.
		$tx = null;
		try
		{
			self::$stock->ConsumeProduct($parentId, 1, false, StockService::TRANSACTION_TYPE_CONSUME, $entryTwo['stock_id'], null, null, $tx, true);
			self::fail('Consuming more than the named entry holds (in the common unit) must be refused');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)', $ex->getMessage());
		}
		self::assertSame($before, self::state([$childId]));
	}

	public function testConsumeFractionalMixedUnitAcrossAFullEntryAndASplitPreservesAccuracy(): void
	{
		// Given: a factor of 4 (an exact binary fraction) with child entries of 1 and 3
		// cans (4 total = exactly 1.0 parent-equivalent), chosen so every intermediate
		// value below (0.75, 0.5, 0.25) is exactly representable in binary floating
		// point - isolating fractional-accuracy regression from the unrelated H3
		// float-residue defect (#487, a different workstream's).
		$locationA = self::location('Fractional Location');
		$canUnit = self::quantityUnit('Fractional Can');
		$parentId = self::product('Fractional Parent', $locationA);
		$childId = self::product('Fractional Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 4.0);
		self::stockRow($childId, 1, $locationA, '2030-01-01');
		$entryThree = self::stockRow($childId, 3, $locationA, '2030-02-02');

		// When: consuming 0.75 parent units (= 3 cans: the 1-can entry fully, then 2 of 3).
		$tx = null;
		self::$stock->ConsumeProduct($parentId, 0.75, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx, true);

		// Then: the first entry is gone and the second holds exactly 1 can (0.25
		// parent-equivalent remains) - no drift from the fractional conversion factor.
		$rows = self::stockSnapshot([$childId]);
		self::assertCount(1, $rows);
		self::assertSame($entryThree['stock_id'], $rows[0]['stock_id']);
		self::assertSame(1.0, $rows[0]['amount']);

		$ledgerAmounts = array_map(fn($row) => $row['amount'], self::ledgerSnapshot([$childId]));
		sort($ledgerAmounts);
		self::assertSame([-2.0, -1.0], $ledgerAmounts);
	}

	// ---- Additional validated gaps: location + substitution together, a mixed own/sub ----
	// ---- candidate set, an already-opened named entry, and a non-exact conversion factor ----

	public function testConsumeRefusesWhenLocationScopeCannotFulfilWithSubstitutionAllowed(): void
	{
		// Given: location A holds 2 cans (0.5 parent-equivalent) of the substitutable
		// child; location B holds 6 more of the same child (8 cans = 2.0 parent-equivalent
		// product-wide) so a product-wide check would wrongly allow the request below -
		// H1 combined with substitution, not exercised by the location-only or
		// substitution-only cases above.
		$locationA = self::location('H1 Substitution A');
		$locationB = self::location('H1 Substitution B');
		$canUnit = self::quantityUnit('H1 Substitution Can');
		$parentId = self::product('H1 Substitution Parent', $locationA);
		$childId = self::product('H1 Substitution Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 4.0);
		self::stockRow($childId, 2, $locationA, '2030-01-01');
		self::stockRow($childId, 6, $locationB, '2030-01-01');
		$before = self::state([$childId]);

		// When: consuming 1 parent unit (= 4 cans), scoped to location A and allowing
		// substitution - A alone holds only 2 cans (0.5 parent-equivalent).
		$tx = null;
		try
		{
			self::$stock->ConsumeProduct($parentId, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, $locationA, $tx, true);
			self::fail('Consuming more than the scoped location holds (in the common unit) must be refused');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)', $ex->getMessage());
		}
		self::assertSame($before, self::state([$childId]));
	}

	public function testOpenMixedOwnAndSubstitutedEntriesOpensExactlyTheRequestedAmount(): void
	{
		// Given: the parent product itself holds 1 unit directly, plus child cans (factor
		// 4) at 1 and 5 - a candidate set mixing an entry needing no conversion with ones
		// that do, in default consume order (own entry due first, then the two children).
		$locationA = self::location('Mixed Own And Sub Location');
		$canUnit = self::quantityUnit('Mixed Own And Sub Can');
		$parentId = self::product('Mixed Own And Sub Parent', $locationA);
		$childId = self::product('Mixed Own And Sub Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 4.0);
		$ownEntry = self::stockRow($parentId, 1, $locationA, '2030-01-01');
		$entryOneCan = self::stockRow($childId, 1, $locationA, '2030-02-02');
		$entryFiveCan = self::stockRow($childId, 5, $locationA, '2030-03-03');

		// When: opening 1.5 parent units (= 1 own unit + 2 cans) with substitution allowed.
		$tx = null;
		self::$stock->OpenProduct($parentId, 1.5, 'default', $tx, true);

		// Then: the own entry opens fully, the 1-can entry opens fully, and the 5-can
		// entry splits into 1 opened + 4 still unopened - exactly 1 unit and 2 cans.
		$parentRows = self::stockSnapshot([$parentId]);
		self::assertCount(1, $parentRows);
		self::assertSame($ownEntry['stock_id'], $parentRows[0]['stock_id']);
		self::assertSame(1, $parentRows[0]['open']);
		self::assertSame(1.0, $parentRows[0]['amount']);

		// Three rows: the 1-can entry (unsplit), the 5-can entry's now-opened original row
		// (amount 1, same stock_id), and the new unopened remainder row (amount 4, a stock_id
		// of its own) the split creates.
		$childRows = self::stockSnapshot([$childId]);
		self::assertCount(3, $childRows);
		$byStockId = [];
		foreach ($childRows as $row)
		{
			$byStockId[$row['stock_id']] = $row;
		}
		self::assertSame(1, $byStockId[$entryOneCan['stock_id']]['open']);
		self::assertSame(1.0, $byStockId[$entryOneCan['stock_id']]['amount']);
		self::assertSame(1, $byStockId[$entryFiveCan['stock_id']]['open']);
		self::assertSame(1.0, $byStockId[$entryFiveCan['stock_id']]['amount']);
		$remainderRow = current(array_filter($childRows, fn($row) => !in_array($row['stock_id'], [$entryOneCan['stock_id'], $entryFiveCan['stock_id']], true)));
		self::assertSame(0, $remainderRow['open']);
		self::assertSame(4.0, $remainderRow['amount']);

		$cansOpened = array_sum(array_map(fn($row) => $row['open'] === 1 ? $row['amount'] : 0.0, $childRows));
		self::assertSame(2.0, $cansOpened);
	}

	public function testOpenRefusesWhenNamedEntryIsAlreadyOpen(): void
	{
		// Given: the named entry is already open (excludeOpened = true drops it from the
		// candidate set entirely), plus a second, larger, unopened entry of the same
		// product so the product-wide unopened total (5) is nonzero and would wrongly
		// allow the request below if checked instead of the (empty) named scope.
		$locationA = self::location('Already Open Location');
		$productId = self::product('Already Open Product', $locationA);
		$openedEntry = self::stockRow($productId, 2, $locationA, '2030-01-01', true);
		self::stockRow($productId, 5, $locationA, '2030-02-02');
		$before = self::state([$productId]);

		// When: opening 1, named to exactly the already-open entry.
		$tx = null;
		try
		{
			self::$stock->OpenProduct($productId, 1, $openedEntry['stock_id'], $tx);
			self::fail('Opening a named entry that is already open must be refused, not silently produce an empty transaction');
		}
		catch (\Exception $ex)
		{
			self::assertSame('Amount to be opened cannot be > current unopened stock amount', $ex->getMessage());
		}
		self::assertSame($before, self::state([$productId]));
	}

	public function testOpenWithANonExactConversionFactorBooksAllTenEntries(): void
	{
		// Given: a factor of 10 spread across ten separate 1-can entries (10 cans = a
		// mathematically exact 1.0 parent-equivalent, but 1/10 is not exactly
		// representable in binary floating point, and summing it ten times accumulates
		// rounding error below 1.0).
		$locationA = self::location('Non Exact Factor Location');
		$canUnit = self::quantityUnit('Non Exact Factor Can');
		$parentId = self::product('Non Exact Factor Parent', $locationA);
		$childId = self::product('Non Exact Factor Child', $locationA, $canUnit, $parentId);
		self::conversion($childId, 2, $canUnit, 10.0);
		for ($i = 1; $i <= 10; $i++)
		{
			self::stockRow($childId, 1, $locationA, sprintf('2030-01-%02d', $i));
		}
		$tx = null;
		self::$stock->OpenProduct($parentId, 1, 'default', $tx, true);
		$rows = self::$db->query('SELECT amount, open FROM stock WHERE product_id = ' . $childId)->fetchAll(PDO::FETCH_ASSOC);
		$logs = self::$db->query('SELECT amount FROM stock_log WHERE product_id = ' . $childId)->fetchAll(PDO::FETCH_COLUMN);
		self::assertCount(10, $rows);
		self::assertCount(10, $logs);
		foreach ($rows as $row)
		{
			self::assertSame(1.0, (float)$row['amount']);
			self::assertSame(1, (int)$row['open']);
		}
		foreach ($logs as $amount)
		{
			self::assertSame(1.0, (float)$amount);
		}
		self::$stock->UndoTransaction($tx);
		self::assertSame(10, (int)self::$db->query('SELECT COUNT(*) FROM stock WHERE product_id = ' . $childId . ' AND open = 0 AND amount = 1')->fetchColumn());
	}
}
