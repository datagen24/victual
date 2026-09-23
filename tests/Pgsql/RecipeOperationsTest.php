<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\DatabaseService;
use Victual\Services\RecipesService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * RecipesService's three operations that are not plain CRUD: putting a recipe's shortfall
 * on the shopping list, consuming a recipe out of stock, and the week-recipe name the meal
 * plan triggers agree with.
 *
 * Both write paths are asserted through the rows they leave behind rather than through
 * their return value, because neither returns anything: what
 * AddNotFulfilledProductsToShoppingList() means is a set of `shopping_list` rows, and what
 * ConsumeRecipe() means is a set of `stock_log` and `stock` rows. Every case here therefore
 * reads the table afterwards, and the cases about what must *not* be ordered or consumed
 * assert the absence of a row rather than a count.
 *
 * Every date and every price in the fixture graph is pinned. ConsumeRecipe() books the
 * produced amount at recipes_resolved.costs_per_serving, which is computed from the
 * current price of the ingredients, so an unpinned purchase price would make the booked
 * price depend on the run.
 */
class RecipeOperationsTest extends PgsqlSchemaTestCase
{
	/** Pinned: the purchase date of every fixture stock entry. */
	private const PURCHASED = '2026-04-01';

	/** Pinned far enough out that no fixture entry is ever due during a run. */
	private const BEST_BEFORE = '2035-06-30';

	/** Pinned: the meal plan day whose shadow recipe the meal-plan branch is asserted on. */
	private const MEAL_PLAN_DAY = '2026-04-13';

	/** Pinned purchase price of every ingredient, so costs_per_serving is a known number. */
	private const INGREDIENT_PRICE = 3.0;

	private static PDO $db;
	private static RecipesService $recipes;
	private static StockService $stock;

	/** Fixture ids, filled by testCreatesFixtures() and read by every method after it. */
	private static array $ids = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'recipeoperations-caller', 'fixture')");

		self::$recipes = RecipesService::GetInstance();
		self::$stock = StockService::GetInstance();
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name, array $columns = []): int
	{
		return self::insertRow('products', array_merge([
			'name' => $name,
			'location_id' => self::$ids['pantry'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		], $columns));
	}

	/**
	 * A recipes INSERT cannot set desired_servings: trg_recipes_desired_servings_default
	 * overwrites it with base_servings on the way in (db/pgsql/baseline/06_triggers_c.sql:476).
	 * A recipe whose desired servings differ from its base servings is made the way a
	 * person makes one - inserted, then changed.
	 */
	private static function insertRecipe(string $name, array $columns = []): int
	{
		$columns = array_merge(['name' => $name, 'base_servings' => 1, 'desired_servings' => 1], $columns);
		$desired = $columns['desired_servings'];
		unset($columns['desired_servings']);

		$recipeId = self::insertRow('recipes', $columns);

		$statement = self::$db->prepare('UPDATE recipes SET desired_servings = ? WHERE id = ?');
		$statement->execute([$desired, $recipeId]);

		return $recipeId;
	}

	private static function addIngredient(int $recipeId, int $productId, float $amount, array $columns = []): int
	{
		return self::insertRow('recipes_pos', array_merge([
			'recipe_id' => $recipeId,
			'product_id' => $productId,
			'amount' => $amount,
			'qu_id' => 2,
		], $columns));
	}

	/** Books stock at the pinned price and date, through the real purchase path. */
	private static function stockUp(int $productId, float $amount): void
	{
		self::$stock->AddProduct($productId, $amount, self::BEST_BEFORE, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED, self::INGREDIENT_PRICE);
	}

	/** The shopping list rows for one product: amount and unit, which is what "ordered" means. */
	private static function shoppingListFor(int $productId): array
	{
		$statement = self::$db->prepare('SELECT amount, qu_id FROM shopping_list WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (float)$statement->fetchColumn();
	}

	private static function highestStockLogId(): int
	{
		return (int)self::$db->query('SELECT COALESCE(MAX(id), 0) FROM stock_log')->fetchColumn();
	}

	/** Every stock_log row written after $afterId, which is how one booking is isolated. */
	private static function stockLogSince(int $afterId): array
	{
		$statement = self::$db->prepare('SELECT product_id, amount, transaction_type, transaction_id, price, note FROM stock_log WHERE id > ? ORDER BY id');
		$statement->execute([$afterId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function costsPerServing(int $recipeId): float
	{
		$statement = self::$db->prepare('SELECT costs_per_serving FROM recipes_resolved WHERE recipe_id = ?');
		$statement->execute([$recipeId]);

		return (float)$statement->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Fixture graph
	// ------------------------------------------------------------------------------

	public function testCreatesFixtures(): void
	{
		self::$ids['pantry'] = self::insertRow('locations', ['name' => 'Recipe Pantry']);
		self::$ids['bottle'] = self::insertRow('quantity_units', ['name' => 'Recipe Bottle', 'name_plural' => 'Recipe Bottles']);

		// Flour's purchase unit differs from its stock unit, which is what makes "the
		// shortfall is ordered in the purchase unit" an assertion rather than a tautology.
		self::$ids['flour'] = self::insertProduct('Recipe Flour', ['qu_id_purchase' => 3]);
		self::$ids['sugar'] = self::insertProduct('Recipe Sugar');
		self::$ids['salt'] = self::insertProduct('Recipe Salt');
		self::$ids['yeast'] = self::insertProduct('Recipe Yeast');
		self::$ids['butter'] = self::insertProduct('Recipe Butter');
		self::$ids['oil'] = self::insertProduct('Recipe Oil', ['qu_id_purchase' => 3]);
		self::$ids['vinegar'] = self::insertProduct('Recipe Vinegar', ['qu_id_purchase' => 3]);

		// One bottle of oil is four of its stock unit. Vinegar deliberately gets no such
		// row, so the two "only check single unit in stock" cases differ in exactly that.
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['bottle'],
			'to_qu_id' => 2,
			'factor' => 4,
			'product_id' => self::$ids['oil'],
		]);

		self::stockUp(self::$ids['sugar'], 3);
		self::stockUp(self::$ids['salt'], 1);

		// Salt and butter are already partly ordered. Salt's recipe checks the list and
		// butter's does not, which is the whole difference between the two cases below.
		self::insertRow('shopping_list', ['product_id' => self::$ids['salt'], 'amount' => 1, 'qu_id' => 2]);
		self::insertRow('shopping_list', ['product_id' => self::$ids['butter'], 'amount' => 2, 'qu_id' => 2]);

		self::$ids['shopping_recipe'] = self::insertRecipe('Recipe Shopping');
		self::addIngredient(self::$ids['shopping_recipe'], self::$ids['flour'], 5);
		self::addIngredient(self::$ids['shopping_recipe'], self::$ids['sugar'], 2);
		self::addIngredient(self::$ids['shopping_recipe'], self::$ids['salt'], 4);
		self::addIngredient(self::$ids['shopping_recipe'], self::$ids['yeast'], 2);

		self::$ids['no_check_recipe'] = self::insertRecipe('Recipe Without Shopping Check', ['not_check_shoppinglist' => 1]);
		self::addIngredient(self::$ids['no_check_recipe'], self::$ids['butter'], 6);

		self::$ids['converted_recipe'] = self::insertRecipe('Recipe Single Unit Converted');
		self::addIngredient(self::$ids['converted_recipe'], self::$ids['oil'], 3, [
			'qu_id' => self::$ids['bottle'],
			'only_check_single_unit_in_stock' => 1,
		]);

		self::$ids['unconverted_recipe'] = self::insertRecipe('Recipe Single Unit Unconverted');
		self::addIngredient(self::$ids['unconverted_recipe'], self::$ids['vinegar'], 2, [
			'qu_id' => self::$ids['bottle'],
			'only_check_single_unit_in_stock' => 1,
		]);

		self::assertSame(3.0, self::stockAmount(self::$ids['sugar']), 'Sugar starts above what its recipe needs');
		self::assertSame(1.0, self::stockAmount(self::$ids['salt']), 'Salt starts below what its recipe needs');
	}

	// ------------------------------------------------------------------------------
	// AddNotFulfilledProductsToShoppingList()
	// ------------------------------------------------------------------------------

	/**
	 * One call covering the four things the method has to get right at once, because they
	 * are four ingredients of one recipe and running them separately would not show that
	 * the same pass decides all four: order the shortfall, order it in the purchase unit,
	 * leave a fulfilled ingredient alone, accumulate onto an existing row instead of
	 * duplicating it, and skip an excluded product.
	 */
	public function testOrdersTheShortfallOfEveryUnfulfilledIngredient(): void
	{
		self::$recipes->AddNotFulfilledProductsToShoppingList(self::$ids['shopping_recipe'], [self::$ids['yeast']]);

		self::assertSame(
			[['amount' => 5.0, 'qu_id' => 3]],
			array_map(fn ($row) => ['amount' => (float)$row['amount'], 'qu_id' => (int)$row['qu_id']], self::shoppingListFor(self::$ids['flour'])),
			'The whole of an ingredient with no stock is ordered, in the product\'s purchase unit'
		);

		self::assertSame([], self::shoppingListFor(self::$ids['sugar']),
			'An ingredient the stock already covers is not ordered at all');

		self::assertSame(
			[['amount' => 3.0, 'qu_id' => 2]],
			array_map(fn ($row) => ['amount' => (float)$row['amount'], 'qu_id' => (int)$row['qu_id']], self::shoppingListFor(self::$ids['salt'])),
			'The existing row is raised to cover the need, not joined by a second row'
		);

		self::assertSame([], self::shoppingListFor(self::$ids['yeast']),
			'An excluded product id is skipped even though it is short');
	}

	/**
	 * The amount already on the list is subtracted from what is ordered, so calling the
	 * method twice for the same recipe does not order the shortfall twice. That is the
	 * documented meaning of "the ordered amount is the missing amount minus what is
	 * already on the list", and it is the boundary case of the same arithmetic: the second
	 * pass computes a shortfall of zero.
	 */
	public function testCallingItAgainOrdersNothingFurther(): void
	{
		$before = self::$db->query('SELECT id, product_id, amount FROM shopping_list ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		self::$recipes->AddNotFulfilledProductsToShoppingList(self::$ids['shopping_recipe'], [self::$ids['yeast']]);

		self::assertSame($before, self::$db->query('SELECT id, product_id, amount FROM shopping_list ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'A recipe whose shortfall is already on the list orders nothing more');
	}

	/**
	 * not_check_shoppinglist means "order the shortfall regardless of what is already on
	 * the list". Butter is 2 short of nothing in stock with 2 already ordered, so a recipe
	 * that checked the list would order 4; this one orders the full 6 on top of the 2.
	 */
	public function testNotCheckShoppinglistOrdersTheFullShortfall(): void
	{
		self::$recipes->AddNotFulfilledProductsToShoppingList(self::$ids['no_check_recipe']);

		self::assertSame(
			[['amount' => 8.0, 'qu_id' => 2]],
			array_map(fn ($row) => ['amount' => (float)$row['amount'], 'qu_id' => (int)$row['qu_id']], self::shoppingListFor(self::$ids['butter'])),
			'The 2 already on the list were not subtracted, so the row holds 2 + 6'
		);
	}

	/**
	 * "Only check if any amount is in stock" means the ingredient's own unit is free to be
	 * anything, so the amount has to be converted into the product's stock unit before it
	 * can be ordered. Three bottles of oil at four stock units per bottle is twelve.
	 */
	public function testOnlyCheckSingleUnitInStockAppliesTheConversion(): void
	{
		self::$recipes->AddNotFulfilledProductsToShoppingList(self::$ids['converted_recipe']);

		self::assertSame(
			[['amount' => 12.0, 'qu_id' => 3]],
			array_map(fn ($row) => ['amount' => (float)$row['amount'], 'qu_id' => (int)$row['qu_id']], self::shoppingListFor(self::$ids['oil'])),
			'3 bottles at a factor of 4 is ordered as 12, in the purchase unit'
		);
	}

	/**
	 * The negative control for the case above, differing from it only in the conversion
	 * row: with no conversion to apply, the position's amount and the position's own unit
	 * are taken as they stand rather than being silently treated as stock units.
	 */
	public function testOnlyCheckSingleUnitInStockFallsBackToThePositionsOwnUnit(): void
	{
		self::$recipes->AddNotFulfilledProductsToShoppingList(self::$ids['unconverted_recipe']);

		self::assertSame(
			[['amount' => 2.0, 'qu_id' => self::$ids['bottle']]],
			array_map(fn ($row) => ['amount' => (float)$row['amount'], 'qu_id' => (int)$row['qu_id']], self::shoppingListFor(self::$ids['vinegar'])),
			'Without a conversion the order keeps the position\'s unit instead of the purchase unit'
		);
	}

	// ------------------------------------------------------------------------------
	// ConsumeRecipe()
	// ------------------------------------------------------------------------------

	public function testCreatesConsumeFixtures(): void
	{
		self::$ids['rice'] = self::insertProduct('Recipe Rice');
		self::$ids['beans'] = self::insertProduct('Recipe Beans');
		self::$ids['spice'] = self::insertProduct('Recipe Spice');
		self::$ids['absent'] = self::insertProduct('Recipe Absent');
		self::$ids['loaf'] = self::insertProduct('Recipe Loaf');
		self::$ids['cake'] = self::insertProduct('Recipe Cake');

		self::stockUp(self::$ids['rice'], 50);
		self::stockUp(self::$ids['beans'], 1);
		self::stockUp(self::$ids['spice'], 3);

		self::$ids['consume_recipe'] = self::insertRecipe('Recipe To Consume');
		self::addIngredient(self::$ids['consume_recipe'], self::$ids['rice'], 2);
		self::addIngredient(self::$ids['consume_recipe'], self::$ids['beans'], 4);
		self::addIngredient(self::$ids['consume_recipe'], self::$ids['spice'], 1, ['only_check_single_unit_in_stock' => 1]);
		self::addIngredient(self::$ids['consume_recipe'], self::$ids['absent'], 1);

		self::assertSame(1.0, self::stockAmount(self::$ids['beans']), 'Beans are deliberately short of what the recipe asks for');
	}

	/**
	 * The four arms of the consumption loop in one booking, for the same reason as the
	 * shopping case: they are four ingredients of one recipe and the claim is about what
	 * one pass does with all of them. The transaction id is the assertion that matters
	 * most - the docblock promises "one stock transaction", and a caller undoing the
	 * consumption has nothing else to undo it by.
	 */
	public function testConsumesEveryIngredientInOneTransaction(): void
	{
		$watermark = self::highestStockLogId();

		self::$recipes->ConsumeRecipe(self::$ids['consume_recipe']);

		$booked = self::stockLogSince($watermark);
		$byProduct = [];

		foreach ($booked as $row)
		{
			$byProduct[(int)$row['product_id']] = (float)$row['amount'];
		}

		self::assertSame(-2.0, $byProduct[self::$ids['rice']] ?? null, 'A fully stocked ingredient is consumed in full');
		self::assertSame(-1.0, $byProduct[self::$ids['beans']] ?? null, 'A partly stocked ingredient consumes what there is rather than failing');
		self::assertArrayNotHasKey(self::$ids['spice'], $byProduct, '"Only check single unit in stock" ingredients are not consumed');
		self::assertArrayNotHasKey(self::$ids['absent'], $byProduct, 'An ingredient with no stock has nothing to consume');

		$transactionIds = array_unique(array_column($booked, 'transaction_id'));
		self::assertCount(1, $transactionIds, 'Every ingredient is consumed under one transaction id');

		self::assertSame(48.0, self::stockAmount(self::$ids['rice']));
		self::assertSame(0.0, self::stockAmount(self::$ids['beans']));
		self::assertSame(3.0, self::stockAmount(self::$ids['spice']), 'The skipped ingredient\'s stock is untouched');
	}

	/**
	 * A recipe that "produces product" books the produced amount back in as
	 * self-production, at the price the resolved view puts on one serving. The price is
	 * compared against the view rather than against a number written down here: the view
	 * is where costs_per_serving is defined, and restating its arithmetic in the test would
	 * only assert that two copies of it agree.
	 */
	public function testAProducingRecipeBooksSelfProductionAtTheResolvedCostPerServing(): void
	{
		$recipeId = self::insertRecipe('Recipe That Produces', [
			'product_id' => self::$ids['loaf'],
			'base_servings' => 1,
			'desired_servings' => 2,
		]);
		self::addIngredient($recipeId, self::$ids['rice'], 1);

		$watermark = self::highestStockLogId();
		self::$recipes->ConsumeRecipe($recipeId);

		$produced = array_values(array_filter(
			self::stockLogSince($watermark),
			fn ($row) => (int)$row['product_id'] === self::$ids['loaf']
		));

		self::assertCount(1, $produced, 'The produced product is booked in exactly once');
		self::assertSame(StockService::TRANSACTION_TYPE_SELF_PRODUCTION, $produced[0]['transaction_type']);
		self::assertSame(2.0, (float)$produced[0]['amount'], 'The produced amount is the recipe\'s desired servings');
		self::assertSame(self::costsPerServing($recipeId), (float)$produced[0]['price'],
			'The produced stock is valued at the recipe\'s resolved cost per serving');
		self::assertSame(2.0, self::stockAmount(self::$ids['loaf']), 'And it is in stock afterwards');
	}

	/**
	 * DEFECT (services/RecipesService.php:147). The AddProduct() call passes twelve
	 * arguments to an eleven parameter method
	 * (services/StockService.php:221): the recipe name intended as the booking's note lands
	 * one place to the left of where $note is, so the note is stored as the boolean true -
	 * "1" on the wire and in the ledger - and $recipe->name is dropped on the floor. A
	 * household reading its stock journal sees "1" where the recipe that produced the entry
	 * should be. Pinned rather than corrected, since application code is out of scope here.
	 */
	public function testTheSelfProductionNoteIsBookedAsTheRecipeName(): void
	{
		$recipeId = self::insertRecipe('Recipe Named For Its Note', [
			'product_id' => self::$ids['loaf'],
			'base_servings' => 1,
			'desired_servings' => 1,
		]);
		self::addIngredient($recipeId, self::$ids['rice'], 1);

		$watermark = self::highestStockLogId();
		self::$recipes->ConsumeRecipe($recipeId);

		$produced = array_values(array_filter(
			self::stockLogSince($watermark),
			fn ($row) => (int)$row['product_id'] === self::$ids['loaf']
		));

		self::assertSame('Recipe Named For Its Note', $produced[0]['note'],
			'The recipe name is stored in the stock_log note field when self-producing a product');
	}

	/**
	 * A meal plan entry is fulfilled through a shadow recipe whose own product_id is null
	 * and whose own desired_servings is 1, so consuming it has to look through to the
	 * recipe the entry actually names and to the servings the entry actually asked for.
	 * Using the shadow's own two values would book the wrong product, or none, and the
	 * wrong amount.
	 */
	public function testConsumingAMealPlanShadowUsesTheOriginalRecipeAndTheEntrysServings(): void
	{
		$originalId = self::insertRecipe('Recipe Behind The Meal Plan', ['product_id' => self::$ids['cake']]);
		self::addIngredient($originalId, self::$ids['rice'], 1);

		$mealPlanId = self::insertRow('meal_plan', [
			'day' => self::MEAL_PLAN_DAY,
			'type' => 'recipe',
			'recipe_id' => $originalId,
			'recipe_servings' => 3,
		]);

		$statement = self::$db->prepare('SELECT id FROM recipes WHERE name = ? AND type = ?');
		$statement->execute([self::MEAL_PLAN_DAY . '#' . $mealPlanId, RecipesService::RECIPE_TYPE_MEALPLAN_SHADOW]);
		$shadowId = (int)$statement->fetchColumn();

		// Internal recipes are minted with ids below every real one, so "found" is "not the
		// zero a missing row decays to" rather than "positive".
		self::assertNotSame(0, $shadowId, 'The meal plan triggers create the shadow recipe this case consumes');

		$watermark = self::highestStockLogId();
		self::$recipes->ConsumeRecipe($shadowId);

		$booked = self::stockLogSince($watermark);
		$rice = array_values(array_filter($booked, fn ($row) => (int)$row['product_id'] === self::$ids['rice']));
		$cake = array_values(array_filter($booked, fn ($row) => (int)$row['product_id'] === self::$ids['cake']));

		self::assertCount(1, $rice);
		self::assertSame(-3.0, (float)$rice[0]['amount'],
			'The shadow nests the original at the entry\'s servings, so three servings of rice are consumed');

		self::assertCount(1, $cake, 'The original recipe\'s "produces product" is what is booked in');
		self::assertSame(3.0, (float)$cake[0]['amount'], 'At the meal plan entry\'s servings, not the shadow\'s own');
		self::assertSame(StockService::TRANSACTION_TYPE_SELF_PRODUCTION, $cake[0]['transaction_type']);
	}

	/** A recipe with no "produces product" consumes and books nothing back in. */
	public function testARecipeWithoutAProducedProductBooksNothingBack(): void
	{
		$recipeId = self::insertRecipe('Recipe Producing Nothing');
		self::addIngredient($recipeId, self::$ids['rice'], 1);

		$watermark = self::highestStockLogId();
		self::$recipes->ConsumeRecipe($recipeId);

		$types = array_unique(array_column(self::stockLogSince($watermark), 'transaction_type'));

		self::assertSame([StockService::TRANSACTION_TYPE_CONSUME], array_values($types),
			'Only consumptions are booked when the recipe produces nothing');
	}

	public function testConsumingAnUnknownRecipeThrowsAndBooksNothing(): void
	{
		$before = self::$db->query('SELECT id FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		try
		{
			self::$recipes->ConsumeRecipe(987654);
			self::fail('Consuming a recipe that does not exist must throw');
		}
		catch (\Exception $exception)
		{
			self::assertSame('Recipe does not exist', $exception->getMessage());
		}

		self::assertSame($before, self::$db->query('SELECT id FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'The refusal left the ledger exactly as it was');
	}

	// ------------------------------------------------------------------------------
	// CopyRecipe()
	// ------------------------------------------------------------------------------

	public function testCopyingAnUnknownRecipeThrowsAndCreatesNothing(): void
	{
		$before = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();

		try
		{
			self::$recipes->CopyRecipe(987654);
			self::fail('Copying a recipe that does not exist must throw');
		}
		catch (\Exception $exception)
		{
			self::assertSame('Recipe does not exist', $exception->getMessage());
		}

		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn(),
			'The refusal created no recipe');
	}

	// ------------------------------------------------------------------------------
	// GetMealPlanWeekRecipeName()
	// ------------------------------------------------------------------------------

	/**
	 * The name this produces is the name the meal plan triggers write, and anything looking
	 * a week recipe up matches on it byte for byte - so the assertion that matters is not
	 * "it returns 2026-00" but "it returns what the database's own
	 * victual_mealplan_week_name() returns". Both are asserted: the literal, so a change of
	 * mind about the format is visible, and the agreement, so the two halves cannot drift
	 * apart silently.
	 *
	 * 2026-01-02 is the case the method exists for. The year's first Monday is 2026-01-05,
	 * so the two days before it are week 00 - neither PHP's ISO "W" (which would call them
	 * week 01 of the previous year) nor PostgreSQL's to_char('WW') agrees.
	 */
	public function testADayBeforeTheYearsFirstMondayIsWeekZero(): void
	{
		self::assertSame('2026-00', RecipesService::GetMealPlanWeekRecipeName('2026-01-02'));
		self::assertSame(self::databaseWeekName('2026-01-02'), RecipesService::GetMealPlanWeekRecipeName('2026-01-02'),
			'The PHP and SQL spellings of the week name have to agree byte for byte');
	}

	public function testTheYearsFirstMondayIsWeekOne(): void
	{
		self::assertSame('2026-01', RecipesService::GetMealPlanWeekRecipeName('2026-01-05'));
		self::assertSame(self::databaseWeekName('2026-01-05'), RecipesService::GetMealPlanWeekRecipeName('2026-01-05'));
	}

	/** A year that itself begins on a Monday has no week 00 at all. */
	public function testAYearBeginningOnAMondayHasNoWeekZero(): void
	{
		self::assertSame('2024-01', RecipesService::GetMealPlanWeekRecipeName('2024-01-01'));
		self::assertSame(self::databaseWeekName('2024-01-01'), RecipesService::GetMealPlanWeekRecipeName('2024-01-01'));
	}

	/** The upper end, where the two-digit padding and the year boundary both matter. */
	public function testTheLastDaysOfAYearKeepThatYearsNumber(): void
	{
		self::assertSame('2026-52', RecipesService::GetMealPlanWeekRecipeName('2026-12-31'));
		self::assertSame(self::databaseWeekName('2026-12-31'), RecipesService::GetMealPlanWeekRecipeName('2026-12-31'));
	}

	/** The name the meal plan trigger actually wrote for the pinned fixture day. */
	public function testTheNameMatchesTheWeekRecipeTheTriggersCreated(): void
	{
		$statement = self::$db->prepare('SELECT COUNT(*) FROM recipes WHERE name = ? AND type = ?');
		$statement->execute([RecipesService::GetMealPlanWeekRecipeName(self::MEAL_PLAN_DAY), RecipesService::RECIPE_TYPE_MEALPLAN_WEEK]);

		self::assertSame(1, (int)$statement->fetchColumn(),
			'A lookup by this name finds the week recipe the meal plan insert created');
	}

	private static function databaseWeekName(string $day): string
	{
		$statement = self::$db->prepare('SELECT victual_mealplan_week_name(?::date)');
		$statement->execute([$day]);

		return (string)$statement->fetchColumn();
	}
}
