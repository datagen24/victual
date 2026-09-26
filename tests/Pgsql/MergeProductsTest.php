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
		$statement = self::$db->prepare('SELECT amount, price, location_id, open, opened_amount, opened_qu_id FROM stock WHERE product_id = ? ORDER BY id');
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

	private static function productParent(int $productId): ?int
	{
		$statement = self::$db->prepare('SELECT parent_product_id FROM products WHERE id = ?');
		$statement->execute([$productId]);
		$value = $statement->fetchColumn();

		return $value === null ? null : (int)$value;
	}

	/** @return array<string, mixed> */
	private static function choreRow(int $choreId): array
	{
		$statement = self::$db->prepare('SELECT product_id, product_amount FROM chores WHERE id = ?');
		$statement->execute([$choreId]);

		return $statement->fetch(PDO::FETCH_ASSOC);
	}

	/** @return array<int, array<string, mixed>> */
	private static function unitConversionRows(int $productId, int $fromQuId, int $toQuId): array
	{
		$statement = self::$db->prepare('SELECT factor FROM quantity_unit_conversions WHERE product_id = ? AND from_qu_id = ? AND to_qu_id = ?');
		$statement->execute([$productId, $fromQuId, $toQuId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function cacheRowCount(string $table, int $productId): int
	{
		$statement = self::$db->prepare("SELECT COUNT(*) FROM $table WHERE product_id = ?");
		$statement->execute([$productId]);

		return (int)$statement->fetchColumn();
	}

	/**
	 * Calls MergeProducts(), asserting it throws, and returns the caught exception's message
	 * for the caller to inspect further (e.g. that it is this method's own clean refusal, not
	 * a raw database error). The assertion runs OUTSIDE the try/catch and the catch is
	 * deliberately not "catch (\Exception)": PHPUnit's own assertion-failure exception is
	 * itself an \Exception, so a self::fail() called from inside a try/catch(\Exception) block
	 * would silently swallow itself - exactly the shape of bug this helper exists to avoid.
	 */
	private function expectMergeRefused(int $productIdToKeep, int $productIdToRemove, string $message): string
	{
		$caught = null;

		try
		{
			StockService::GetInstance()->MergeProducts($productIdToKeep, $productIdToRemove);
		}
		catch (\Throwable $exception)
		{
			$caught = $exception;
		}

		self::assertNotNull($caught, $message);

		return $caught->getMessage();
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

	// ------------------------------------------------------------------------------
	// Opus validation of PR #540: quantity_unit_conversions duplicates
	// ------------------------------------------------------------------------------

	public function testMergeDropsTheRemovedProductsAutoCreatedUnitConversionInsteadOfConflictingWithTheKeptProducts(): void
	{
		$box = self::insertRow('quantity_units', ['name' => 'Merge Auto Box', 'name_plural' => 'Merge Auto Boxes']);

		// Given: both products purchase in a box unit but stock in kilograms, with no global
		// conversion between the two units - products_default_qu_conversions_INS therefore
		// auto-creates the identical 1:1 box -> kg pair (and its auto-maintained inverse) on
		// EACH product independently.
		$keep = self::insertProduct('Merge Auto Conv Keep', [
			'qu_id_purchase' => $box,
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		$remove = self::insertProduct('Merge Auto Conv Remove', [
			'qu_id_purchase' => $box,
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);

		self::assertCount(1, self::unitConversionRows($keep, $box, self::$ids['kilogram']), 'Given: the kept product already auto-owns a box->kg conversion');
		self::assertCount(1, self::unitConversionRows($remove, $box, self::$ids['kilogram']), 'Given: the removed product auto-owns the identical pair');

		// When: the products (same stock unit, so factor 1) are merged.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the merge completes instead of raising "QU conversion already exists"
		// (qu_conversions_custom_constraint_UPD, db/pgsql/baseline/06_triggers_a.sql), and
		// exactly one box->kg row survives for the kept product - not two, not zero.
		self::assertFalse(self::productExists($remove), 'Then: the merge completes despite the auto-created conversion collision');
		self::assertCount(1, self::unitConversionRows($keep, $box, self::$ids['kilogram']), 'Then: exactly one box->kg conversion survives for the kept product');
		self::assertSame([], self::unitConversionRows($remove, $box, self::$ids['kilogram']), 'Then: nothing remains attributed to the removed product id');
	}

	public function testMergeKeepsTheKeptProductsOwnUnitConversionFactorOnConflict(): void
	{
		$box = self::insertRow('quantity_units', ['name' => 'Merge Conflict Box', 'name_plural' => 'Merge Conflict Boxes']);
		$keep = self::insertProduct('Merge Conv Winner Keep');
		$remove = self::insertProduct('Merge Conv Winner Remove');

		// Given: both products separately define a custom box conversion for the same pair,
		// with different factors.
		self::insertRow('quantity_unit_conversions', ['from_qu_id' => $box, 'to_qu_id' => 2, 'factor' => 5, 'product_id' => $keep]);
		self::insertRow('quantity_unit_conversions', ['from_qu_id' => $box, 'to_qu_id' => 2, 'factor' => 9, 'product_id' => $remove]);

		// When: the products are merged.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the kept product's own factor (5) survives; the removed product's conflicting
		// row (factor 9) is dropped rather than overwriting it - the same dedupe-then-move
		// rule product_substitutions already uses.
		$survivors = self::unitConversionRows($keep, $box, 2);
		self::assertCount(1, $survivors, 'Then: exactly one box conversion survives for the kept product');
		self::assertEqualsWithDelta(5.0, (float)$survivors[0]['factor'], 1e-9, 'Then: the kept product\'s own factor wins, not the removed product\'s');
	}

	// ------------------------------------------------------------------------------
	// Opus validation of PR #540: chores
	// ------------------------------------------------------------------------------

	public function testMergeRepointsChoresAndConvertsProductAmountByTheUnitFactor(): void
	{
		$keep = self::insertProduct('Merge Chore Kg Product', [
			'qu_id_purchase' => self::$ids['kilogram'],
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		$remove = self::insertProduct('Merge Chore Gram Product', [
			'qu_id_purchase' => self::$ids['gram'],
			'qu_id_stock' => self::$ids['gram'],
			'qu_id_consume' => self::$ids['gram'],
			'qu_id_price' => self::$ids['gram'],
		]);
		self::insertRow('quantity_unit_conversions', ['from_qu_id' => self::$ids['gram'], 'to_qu_id' => self::$ids['kilogram'], 'factor' => 0.001, 'product_id' => $remove]);

		// Given: a chore that consumes 500 g of the removed product on execution.
		$chore = self::insertRow('chores', [
			'name' => 'Merge Chore ' . uniqid(),
			'period_type' => 'manually',
			'consume_product_on_execution' => 1,
			'product_id' => $remove,
			'product_amount' => 500,
		]);

		// When: the gram product is merged into the kilogram product.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the chore now points at the kept product, with its amount converted the same
		// way trg_cascade_change_qu_id_stock converts it for a single product's own unit
		// change - left unrepointed, the chore's next execution would throw "Product does not
		// exist or is inactive".
		$after = self::choreRow($chore);
		self::assertSame($keep, (int)$after['product_id'], 'Then: the chore is repointed to the kept product');
		self::assertEqualsWithDelta(0.5, (float)$after['product_amount'], 1e-9, 'Then: product_amount is converted by the g->kg factor');
	}

	// ------------------------------------------------------------------------------
	// Opus validation of PR #540: products.parent_product_id
	// ------------------------------------------------------------------------------

	public function testMergeRepointsTheRemovedProductsChildProductsToTheKeptProduct(): void
	{
		$keep = self::insertProduct('Merge Parent Keep');
		$remove = self::insertProduct('Merge Parent Remove');
		$child1 = self::insertProduct('Merge Parent Child 1', ['parent_product_id' => $remove]);
		$child2 = self::insertProduct('Merge Parent Child 2', ['parent_product_id' => $remove]);

		// When: the products are merged (neither has a parent of its own).
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: both of the removed product's children now point at the kept product instead
		// of a deleted row.
		self::assertSame($keep, self::productParent($child1), 'Then: the first child is repointed to the kept product');
		self::assertSame($keep, self::productParent($child2), 'Then: the second child is repointed to the kept product');
	}

	public function testMergeRefusesWhenRepointingWouldGiveTheKeptProductBothAParentAndChildren(): void
	{
		$grandparent = self::insertProduct('Merge Nesting Grandparent');
		$keep = self::insertProduct('Merge Nesting Keep', ['parent_product_id' => $grandparent]);
		$remove = self::insertProduct('Merge Nesting Remove');
		$child = self::insertProduct('Merge Nesting Child', ['parent_product_id' => $remove]);

		// Given: the kept product already has an unrelated parent, and the removed product has
		// a child of its own - repointing that child to the kept product would give the kept
		// product both a parent and a child at once, a three-level chain.
		// enfore_product_nesting_level (migrations/0277.pgsql.sql) DOES refuse this shape too
		// (a row cannot be given a parent that itself already has one) - the guard exists to
		// refuse before any row is touched with a clear, merge-specific message, not because
		// the trigger would otherwise let it through. Without the guard, the trigger's own
		// generic "Unsupported product nesting level detected" also contains the word
		// "nesting", so asserting on that alone would not actually prove the guard ran -
		// asserting the guard's own "Cannot merge" wording, and the absence of a SQLSTATE
		// marker, is what tells them apart.
		self::assertSame($grandparent, self::productParent($keep));
		self::assertSame($remove, self::productParent($child));

		// When: the products are merged.
		$message = $this->expectMergeRefused($keep, $remove, 'Expected the merge to be refused: it would give the kept product both a parent and a child');

		// Then: the refusal is this method's own clean message, not the generic trigger error
		// that would otherwise surface mid-transaction, and nothing changed - both products,
		// and the child's parent, are exactly as they were.
		self::assertStringContainsString('Cannot merge', $message, 'Then: this is the guard\'s own message, not the trigger\'s generic one');
		self::assertStringNotContainsStringIgnoringCase('sqlstate', $message, 'Then: this is not a raw database exception message');
		self::assertTrue(self::productExists($remove), 'Then: the removed product still exists');
		self::assertSame($remove, self::productParent($child), 'Then: the child\'s parent is unchanged');
		self::assertSame($grandparent, self::productParent($keep), 'Then: the kept product\'s own parent is unchanged');
	}

	public function testMergeClearsTheKeptProductsParentWhenItWasTheRemovedProductAndRepointsSiblings(): void
	{
		$remove = self::insertProduct('Merge Nesting Root Remove');
		$keep = self::insertProduct('Merge Nesting Root Keep', ['parent_product_id' => $remove]);
		$sibling = self::insertProduct('Merge Nesting Root Sibling', ['parent_product_id' => $remove]);

		// Given: the kept product is itself one of the removed product's children, and the
		// removed product has another child (a sibling of the kept product). This must NOT be
		// refused by the nesting guard above: the kept product's own parent is cleared by this
		// same merge, leaving room for it to become the sibling's new parent.
		self::assertSame($remove, self::productParent($keep));

		// When: the products are merged.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the kept product is now a root (its parent, the removed product, is gone), and
		// the sibling is repointed to the kept product rather than left dangling.
		self::assertNull(self::productParent($keep), 'Then: the kept product\'s parent is cleared, not left pointing at the deleted removed product');
		self::assertSame($keep, self::productParent($sibling), 'Then: the sibling is repointed to the kept product');
	}

	// ------------------------------------------------------------------------------
	// Opus validation of PR #540: refusing rather than silently corrupting
	// ------------------------------------------------------------------------------

	public function testMergeRefusesWhenStockUnitsDifferWithNoConversion(): void
	{
		$keep = self::insertProduct('Merge No Conversion Keep', [
			'qu_id_purchase' => self::$ids['kilogram'],
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		$remove = self::insertProduct('Merge No Conversion Remove', [
			'qu_id_purchase' => self::$ids['gram'],
			'qu_id_stock' => self::$ids['gram'],
			'qu_id_consume' => self::$ids['gram'],
			'qu_id_price' => self::$ids['gram'],
		]);
		// Deliberately no quantity_unit_conversions row between gram and kilogram.

		StockService::GetInstance()->AddProduct($remove, 500, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$ids['pantry']);
		$given = self::stockRows($remove);
		self::assertCount(1, $given, 'Given: the fixture purchase books one stock row');

		// When: merging is attempted with no conversion path between the two stock units.
		$message = $this->expectMergeRefused($keep, $remove, 'Expected the merge to be refused: the stock units differ and no conversion exists');

		// Then: the refusal names the actual reason, and nothing changed - falling back to a
		// factor of 1 (as this method used to) would instead have silently turned 500 g into
		// "500 kg".
		self::assertStringContainsString('unit conversion', $message, 'Then: the refusal explains why, rather than surfacing a raw database error');
		self::assertTrue(self::productExists($remove), 'Then: the removed product still exists');
		self::assertSame($given, self::stockRows($remove), 'Then: the removed product\'s stock is untouched');
		self::assertSame([], self::stockRows($keep), 'Then: nothing was moved to the kept product');
	}

	public function testMergeRefusesWhenARemovedMeasuredOpenContainerWouldBeRescaled(): void
	{
		$keep = self::insertProduct('Merge Measured Kg Product', [
			'qu_id_purchase' => self::$ids['kilogram'],
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		$remove = self::insertProduct('Merge Measured Gram Product', [
			'qu_id_purchase' => self::$ids['gram'],
			'qu_id_stock' => self::$ids['gram'],
			'qu_id_consume' => self::$ids['gram'],
			'qu_id_price' => self::$ids['gram'],
		]);
		self::insertRow('quantity_unit_conversions', ['from_qu_id' => self::$ids['gram'], 'to_qu_id' => self::$ids['kilogram'], 'factor' => 0.001, 'product_id' => $remove]);

		// Given: a single opened, measured container of the removed product (open = 1,
		// amount = 1, a real opened_amount/opened_qu_id pair - migrations/0275.pgsql.sql's
		// stock_measurement_coherence_check).
		$stock = StockService::GetInstance();
		$stock->AddProduct($remove, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$ids['pantry']);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $remove)->fetchColumn();
		$stock->OpenProduct($remove, 1);
		$stock->MeasureStockEntry($stockRowId, ['amount' => 0.5, 'qu_id' => self::$ids['gram']]);

		$given = self::stockRows($remove);
		self::assertCount(1, $given, 'Given: fixture sanity check');
		self::assertSame(1, (int)$given[0]['open'], 'Given: the container is open');
		self::assertEqualsWithDelta(1.0, (float)$given[0]['amount'], 1e-9, 'Given: a coherent single container (amount = 1)');
		self::assertEqualsWithDelta(0.5, (float)$given[0]['opened_amount'], 1e-9, 'Given: the container has been measured, 0.5 g remaining');

		// When: merging would rescale amount by a non-1 factor (0.001).
		$message = $this->expectMergeRefused($keep, $remove, 'Expected the merge to be refused: rescaling a measured container\'s amount away from 1 violates its coherence CHECK');

		// Then: the refusal is this method's own clean message, not a raw 23514 check
		// violation surfacing from the database - and nothing changed.
		self::assertStringContainsString('measured open container', $message, 'Then: the refusal explains why, rather than surfacing a raw database error');
		self::assertStringNotContainsStringIgnoringCase('sqlstate', $message, 'Then: this is not a raw PDO/database exception message');
		self::assertTrue(self::productExists($remove), 'Then: the removed product still exists');
		self::assertSame($given, self::stockRows($remove), 'Then: the measured entry is untouched');
	}

	// ------------------------------------------------------------------------------
	// Opus validation of PR #540: stale cache rows (non-blocking)
	// ------------------------------------------------------------------------------

	public function testMergeDeletesTheRemovedProductsStaleAverageAndLastPurchasedCacheRows(): void
	{
		$keep = self::insertProduct('Merge Cache Keep');
		$remove = self::insertProduct('Merge Cache Remove');

		// Given: a purchase populates both caches for the removed product via stock_log_INS.
		StockService::GetInstance()->AddProduct($remove, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 3.0, self::$ids['pantry']);
		self::assertSame(1, self::cacheRowCount('cache__products_average_price', $remove), 'Given: the purchase populates the average-price cache');
		self::assertSame(1, self::cacheRowCount('cache__products_last_purchased', $remove), 'Given: the purchase populates the last-purchased cache');

		// When: the products are merged.
		StockService::GetInstance()->MergeProducts($keep, $remove);

		// Then: the removed product's rows in both caches are gone, not left stale under a
		// product id that no longer exists.
		self::assertSame(0, self::cacheRowCount('cache__products_average_price', $remove), 'Then: the stale average-price cache row is deleted');
		self::assertSame(0, self::cacheRowCount('cache__products_last_purchased', $remove), 'Then: the stale last-purchased cache row is deleted');
	}
}
