<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #629 (#487 remediation): recipes_pos_resolved - the view /api/objects/recipes_pos_resolved
 * and the recipe page both read - priced and costed an unconvertible substituted sub product
 * 1:1 instead of excluding it, the same defect class migrations/0298.pgsql.sql (PR #628,
 * issue #622) fixed for stock_current.amount_aggregated.
 *
 * migrations/0300.pgsql.sql redefines products_current_substitutions so a sub product is
 * only ever chosen as `product_id_effective` when a resolved, positive quantity-unit
 * conversion exists from the parent's own stock unit to its own - the same admissibility
 * rule StockService::SubstitutionAwareProductIdWhereClause() applies on the consume side
 * (maintainer decision D4, issue #553). This test builds one parent ingredient with two
 * competing sub products - one convertible, one with no resolved conversion at all - and
 * asserts that the recipe's displayed cost, calories and fulfilment all agree: the
 * convertible sub is used (at its real conversion factor), never the unconvertible one at
 * face value.
 */
class RecipeSubstitutionUnitConversionTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static array $ids = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertQuantityUnit(string $name): int
	{
		return self::insertRow('quantity_units', ['name' => $name, 'name_plural' => $name . 's']);
	}

	private static function insertStock(int $productId, float $amount, string $bestBeforeDate, float $price, string $stockId): void
	{
		self::insertRow('stock', [
			'product_id' => $productId,
			'amount' => $amount,
			'best_before_date' => $bestBeforeDate,
			'purchased_date' => '2026-01-01',
			'stock_id' => $stockId,
			'location_id' => self::$ids['location'],
			'price' => $price,
		]);
	}

	/**
	 * One recipe position joined against recipes_pos_resolved: the columns the recipe page
	 * and /api/objects/recipes_pos_resolved both read.
	 */
	private static function fetchResolvedPosition(int $recipeId): array
	{
		$statement = self::$db->prepare('
			SELECT product_id_effective, costs, calories, stock_amount, need_fulfilled, missing_amount
			FROM recipes_pos_resolved
			WHERE recipe_id = ?
		');
		$statement->execute([$recipeId]);

		return $statement->fetch(PDO::FETCH_ASSOC);
	}

	public function testCreatesFixtures(): void
	{
		self::$ids['location'] = self::insertRow('locations', ['name' => 'RecipeSub629 location']);

		self::$ids['parent_unit'] = self::insertQuantityUnit('RecipeSub629 Parent Unit');
		self::$ids['convertible_unit'] = self::insertQuantityUnit('RecipeSub629 Convertible Unit');
		self::$ids['unconvertible_unit'] = self::insertQuantityUnit('RecipeSub629 Unconvertible Unit');

		// The parent ingredient. Never purchased, so it is never in stock itself - which is
		// what makes products_current_substitutions look at its sub products at all.
		self::$ids['parent'] = self::insertRow('products', [
			'name' => 'RecipeSub629 Parent',
			'location_id' => self::$ids['location'],
			'qu_id_purchase' => self::$ids['parent_unit'],
			'qu_id_stock' => self::$ids['parent_unit'],
			'qu_id_consume' => self::$ids['parent_unit'],
			'qu_id_price' => self::$ids['parent_unit'],
		]);

		// The convertible sub product: a real, positive, product-specific conversion from
		// the parent's own unit exists (1 Parent Unit = 2 Convertible Units).
		self::$ids['convertible'] = self::insertRow('products', [
			'name' => 'RecipeSub629 Convertible Sub',
			'location_id' => self::$ids['location'],
			'qu_id_purchase' => self::$ids['convertible_unit'],
			'qu_id_stock' => self::$ids['convertible_unit'],
			'qu_id_consume' => self::$ids['convertible_unit'],
			'qu_id_price' => self::$ids['convertible_unit'],
			'parent_product_id' => self::$ids['parent'],
			'calories' => 40,
		]);
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['parent_unit'],
			'to_qu_id' => self::$ids['convertible_unit'],
			'factor' => 2,
			'product_id' => self::$ids['convertible'],
		]);

		// The unconvertible sub product: no quantity_unit_conversions row exists between
		// the parent's unit and this one at all.
		self::$ids['unconvertible'] = self::insertRow('products', [
			'name' => 'RecipeSub629 Unconvertible Sub',
			'location_id' => self::$ids['location'],
			'qu_id_purchase' => self::$ids['unconvertible_unit'],
			'qu_id_stock' => self::$ids['unconvertible_unit'],
			'qu_id_consume' => self::$ids['unconvertible_unit'],
			'qu_id_price' => self::$ids['unconvertible_unit'],
			'parent_product_id' => self::$ids['parent'],
			'calories' => 1000,
		]);

		// The unconvertible sub's best_before_date is earlier, so stock_next_use's own
		// "first due first" priority ordering prefers it over the convertible sub - which is
		// exactly what makes the pre-fix behaviour wrong rather than accidentally right.
		self::insertStock(self::$ids['unconvertible'], 3, '2030-01-01', 999, 'recipesub629-unconvertible');
		self::insertStock(self::$ids['convertible'], 5, '2030-06-01', 4, 'recipesub629-convertible');

		self::$ids['recipe'] = self::insertRow('recipes', ['name' => 'RecipeSub629 Recipe', 'base_servings' => 1]);
		self::$db->prepare('UPDATE recipes SET desired_servings = 1 WHERE id = ?')->execute([self::$ids['recipe']]);
		self::insertRow('recipes_pos', [
			'recipe_id' => self::$ids['recipe'],
			'product_id' => self::$ids['parent'],
			'amount' => 1,
			'qu_id' => self::$ids['parent_unit'],
		]);

		// A meaningful check that the fixture graph is what the tests below assume, rather
		// than only a set of INSERTs with no assertion of their own: both sub products exist,
		// both are recorded as sub products of the same parent, and the recipe has exactly
		// the one ingredient position it's meant to.
		$productNames = self::$db->query(
			'SELECT name FROM products WHERE parent_product_id = ' . self::$ids['parent'] . ' ORDER BY name'
		)->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame(
			['RecipeSub629 Convertible Sub', 'RecipeSub629 Unconvertible Sub'],
			$productNames,
			'both sub products were created as sub products of the parent'
		);

		$statement = self::$db->prepare('SELECT COUNT(*) FROM recipes_pos WHERE recipe_id = ?');
		$statement->execute([self::$ids['recipe']]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'the recipe has exactly the one ingredient position the tests below assume');
	}

	/**
	 * @depends testCreatesFixtures
	 */
	public function testConvertibleSubIsChosenOverUnconvertibleOne(): void
	{
		$row = self::fetchResolvedPosition(self::$ids['recipe']);

		self::assertSame(
			self::$ids['convertible'],
			(int)$row['product_id_effective'],
			'products_current_substitutions skips the unconvertible sub product - even though stock_next_use\'s own priority order prefers it - and picks the convertible one instead (issue #629)'
		);
	}

	/**
	 * @depends testCreatesFixtures
	 */
	public function testCostsUseTheConvertibleSubsRealFactorNotTheUnconvertibleSubAtFaceValue(): void
	{
		$row = self::fetchResolvedPosition(self::$ids['recipe']);

		// 1 recipe-unit (parent unit) * price-per-convertible-unit (4) * factor (2
		// convertible units per parent unit) = 8. Before the fix this read 999 (the
		// unconvertible sub's own price, credited 1:1).
		self::assertSame(8.0, (float)$row['costs'],
			'recipes_pos_resolved.costs prices the convertible substitute at its real conversion factor, not the unconvertible sub 1:1 (issue #629)');
	}

	/**
	 * @depends testCreatesFixtures
	 */
	public function testCaloriesUseTheConvertibleSubsRealFactorNotTheUnconvertibleSubAtFaceValue(): void
	{
		$row = self::fetchResolvedPosition(self::$ids['recipe']);

		// 1 * 40 calories/convertible-unit * factor (2) = 80. Before the fix this read 1000
		// (the unconvertible sub's own calories, credited 1:1).
		self::assertSame(80.0, (float)$row['calories'],
			'recipes_pos_resolved.calories credits the convertible substitute at its real conversion factor, not the unconvertible sub 1:1 (issue #629)');
	}

	/**
	 * @depends testCreatesFixtures
	 *
	 * stock_amount/need_fulfilled/missing_amount read stock_current.amount_aggregated for
	 * the recipe position's own product_id (the parent), never for product_id_effective, so
	 * this migration cannot have changed them - asserted here as a live equality against
	 * stock_current itself, rather than a literal, so this test does not assume whether
	 * migrations/0298.pgsql.sql (PR #628, issue #622 - the same defect class on
	 * stock_current's own rollup) has landed on top of this branch yet.
	 */
	public function testFulfilmentReadsStockCurrentUnaffectedByThisMigration(): void
	{
		$row = self::fetchResolvedPosition(self::$ids['recipe']);

		$statement = self::$db->prepare('SELECT amount_aggregated FROM stock_current WHERE product_id = ?');
		$statement->execute([self::$ids['parent']]);
		$amountAggregated = (float)$statement->fetchColumn();

		self::assertSame($amountAggregated, (float)$row['stock_amount'],
			'recipes_pos_resolved.stock_amount reads stock_current.amount_aggregated for the parent - unaffected by which sub product this migration picks as product_id_effective');
		self::assertSame($amountAggregated >= 1.0 ? 1 : 0, (int)$row['need_fulfilled'],
			'need_fulfilled agrees with that same aggregate against the recipe\'s required amount (1)');
		self::assertSame(max(1.0 - $amountAggregated, 0.0), (float)$row['missing_amount'],
			'missing_amount agrees with that same aggregate against the recipe\'s required amount (1)');
	}
}
