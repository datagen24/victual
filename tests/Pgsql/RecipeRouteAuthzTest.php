<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\RecipesService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #532 (part of #487 remediation): the recipe consume and add-missing-to-shoppinglist
 * routes acted on recipes the caller could not read, and consuming a recipe that produces
 * stock (self-production) needed only STOCK_CONSUME - never STOCK_PURCHASE, the permission
 * that gates every other stock addition, including the identical booking reachable through
 * POST /api/stock/products/{id}/add.
 *
 * Maintainer decision, 2026-09-26 (recorded on #532):
 * - A recipe consumption that produces stock, including through a meal-plan shadow's
 *   original recipe, also requires STOCK_PURCHASE.
 * - A consumption-only recipe needs RECIPES_VIEW + STOCK_CONSUME.
 * - The add-missing-to-shoppinglist route needs RECIPES_VIEW + SHOPPINGLIST_ITEMS_ADD.
 * - RECIPES_VIEW is checked before the recipe is inspected at all, so a caller without it
 *   cannot learn whether a recipe exists or what it produces.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php): the
 * authentication middleware define()s the acting user, and PHP cannot redefine a constant.
 * Fixture setup (products, recipes, initial stock) runs in-process instead, under
 * PgsqlSchemaTestCase's default VICTUAL_USER_ID (9000), which is never the identity any
 * assertion below is actually about.
 */
class RecipeRouteAuthzTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockService $stock;

	/** Pinned so a purchase/self-production booking's price is a known, irrelevant number. */
	private const INGREDIENT_PRICE = 2.0;
	private const PURCHASED = '2026-04-01';
	private const BEST_BEFORE = '2035-06-30';

	/** Pinned meal plan day for the shadow-recipe case. */
	private const MEAL_PLAN_DAY = '2026-05-11';

	/** @var array<string,string> plaintext API keys by role name */
	private static array $keys = [];

	private static int $locationId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$stock = StockService::GetInstance();

		self::$locationId = self::insertRow('locations', ['name' => 'Authz Location']);

		// One user per grant combination under test, each holding EXACTLY the permissions
		// its name says - never more, so a 403 below is never actually explained by an
		// extra grant this fixture happened to hand out.
		$users = [
			'consume-only' => ['id' => 9600, 'permissions' => ['STOCK_CONSUME']],
			'view-consume' => ['id' => 9601, 'permissions' => ['RECIPES_VIEW', 'STOCK_CONSUME']],
			'view-consume-purchase' => ['id' => 9602, 'permissions' => ['RECIPES_VIEW', 'STOCK_CONSUME', 'STOCK_PURCHASE']],
			'shoppinglist-only' => ['id' => 9603, 'permissions' => ['SHOPPINGLIST_ITEMS_ADD']],
			'view-shoppinglist' => ['id' => 9604, 'permissions' => ['RECIPES_VIEW', 'SHOPPINGLIST_ITEMS_ADD']],
		];

		$insertUser = self::$db->prepare('INSERT INTO users(id, username, password) VALUES (?, ?, ?)');
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');

		foreach ($users as $name => $user)
		{
			$insertUser->execute([$user['id'], 'authz-' . $name, 'fixture']);
			foreach ($user['permissions'] as $permission)
			{
				$grant->execute([$user['id'], $permission]);
			}
			self::$keys[$name] = self::issueKey($user['id']);
		}
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	/** Inserts a key row the way ApiKeyService::CreateApiKey() stores one, and returns its plaintext. */
	private static function issueKey(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/**
	 * $body is passed through as the JSON request body when not null (and, per
	 * request-subprocess-helper.php, gives the request a Content-Type: application/json
	 * header along with it). ConsumeRecipe never parses a body, so its callers below pass
	 * null; AddNotFulfilledProductsToShoppingList calls GetParsedAndFilteredRequestBody()
	 * unconditionally, which 400s ("Bad Content-Type") without that header even when the
	 * caller wants no fields from the body - so its callers pass [] instead.
	 *
	 * @return array{status: int, body: mixed}
	 */
	private static function send(string $method, string $path, string $apiKey, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $apiKey]];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$output = stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$response = json_decode($output, true);
		if (isset($response['body']) && is_string($response['body']))
		{
			$decoded = json_decode($response['body'], true);
			if (is_array($decoded))
			{
				$response['body'] = $decoded;
			}
		}

		return $response;
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	/**
	 * A recipes INSERT cannot set desired_servings: trg_recipes_desired_servings_default
	 * overwrites it with base_servings (db/pgsql/baseline/06_triggers_c.sql). Inserted, then
	 * changed, the same way a person changing "desired servings" on an existing recipe would.
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

	private static function addIngredient(int $recipeId, int $productId, float $amount): int
	{
		return self::insertRow('recipes_pos', [
			'recipe_id' => $recipeId,
			'product_id' => $productId,
			'amount' => $amount,
			'qu_id' => 2,
		]);
	}

	/** Books stock at the pinned price and date, through the real purchase path. */
	private static function stockUp(int $productId, float $amount): void
	{
		self::$stock->AddProduct($productId, $amount, self::BEST_BEFORE, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED, self::INGREDIENT_PRICE);
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
		$statement = self::$db->prepare('SELECT product_id, amount, transaction_type FROM stock_log WHERE id > ? ORDER BY id');
		$statement->execute([$afterId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function shoppingListCountFor(int $productId): int
	{
		$statement = self::$db->prepare('SELECT COUNT(*) FROM shopping_list WHERE product_id = ?');
		$statement->execute([$productId]);

		return (int)$statement->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// POST /api/recipes/{id}/consume
	// ------------------------------------------------------------------------------

	public function testConsumeOnlyCannotConsumeAProducingRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz Ingredient A');
		$outputId = self::insertProduct('Authz Output A');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Producing Recipe A', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['consume-only']);

		self::assertSame(403, $response['status'], 'STOCK_CONSUME alone must not read or consume the recipe');
		self::assertSame(5.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written');
	}

	public function testViewAndConsumeCannotConsumeAProducingRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz Ingredient B');
		$outputId = self::insertProduct('Authz Output B');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Producing Recipe B', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['view-consume']);

		self::assertSame(403, $response['status'], 'RECIPES_VIEW + STOCK_CONSUME is not enough for a recipe that produces stock');
		self::assertSame(5.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written');
	}

	public function testViewAndConsumeCanConsumeAConsumptionOnlyRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz Ingredient C');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Consumption Only Recipe');
		self::addIngredient($recipeId, $ingredientId, 2);

		$watermark = self::highestStockLogId();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['view-consume']);

		self::assertSame(204, $response['status'], 'RECIPES_VIEW + STOCK_CONSUME is enough for a recipe that produces nothing: ' . json_encode($response['body']));
		self::assertSame(3.0, self::stockAmount($ingredientId), 'The ingredient was consumed');

		$booked = self::stockLogSince($watermark);
		self::assertCount(1, $booked, 'Exactly one booking was written');
		self::assertSame($ingredientId, (int)$booked[0]['product_id']);
		self::assertSame(-2.0, (float)$booked[0]['amount']);
		self::assertSame(StockService::TRANSACTION_TYPE_CONSUME, $booked[0]['transaction_type']);
	}

	public function testViewConsumeAndPurchaseCanConsumeAProducingRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz Ingredient D');
		$outputId = self::insertProduct('Authz Output D');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Producing Recipe D', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['view-consume-purchase']);

		self::assertSame(204, $response['status'], 'RECIPES_VIEW + STOCK_CONSUME + STOCK_PURCHASE may consume a producing recipe: ' . json_encode($response['body']));
		self::assertSame(4.0, self::stockAmount($ingredientId), 'The ingredient was consumed');
		self::assertSame(1.0, self::stockAmount($outputId), 'The output was self-produced');

		$booked = self::stockLogSince($watermark);
		self::assertCount(2, $booked, 'One consume row and one self-production row were written');
		$types = array_column($booked, 'transaction_type');
		sort($types);
		self::assertSame([StockService::TRANSACTION_TYPE_CONSUME, StockService::TRANSACTION_TYPE_SELF_PRODUCTION], $types);
	}

	public function testMealPlanShadowOfAProducingRecipeNeedsStockPurchase(): void
	{
		$ingredientId = self::insertProduct('Authz Ingredient E');
		$outputId = self::insertProduct('Authz Output E');
		self::stockUp($ingredientId, 10);

		$originalId = self::insertRecipe('Authz Meal Plan Original E', ['product_id' => $outputId]);
		self::addIngredient($originalId, $ingredientId, 1);

		$mealPlanId = self::insertRow('meal_plan', [
			'day' => self::MEAL_PLAN_DAY,
			'type' => 'recipe',
			'recipe_id' => $originalId,
			'recipe_servings' => 2,
		]);

		$statement = self::$db->prepare('SELECT id FROM recipes WHERE name = ? AND type = ?');
		$statement->execute([self::MEAL_PLAN_DAY . '#' . $mealPlanId, RecipesService::RECIPE_TYPE_MEALPLAN_SHADOW]);
		$shadowId = (int)$statement->fetchColumn();
		self::assertNotSame(0, $shadowId, 'The meal plan trigger creates the shadow recipe this case consumes');

		$watermark = self::highestStockLogId();
		$refused = self::send('POST', '/api/recipes/' . $shadowId . '/consume', self::$keys['view-consume']);

		self::assertSame(403, $refused['status'], 'The shadow\'s original recipe produces stock, so STOCK_PURCHASE is required too');
		self::assertSame(10.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');

		$allowed = self::send('POST', '/api/recipes/' . $shadowId . '/consume', self::$keys['view-consume-purchase']);

		self::assertSame(204, $allowed['status'], 'STOCK_PURCHASE lets the shadow consume through to its original recipe\'s output: ' . json_encode($allowed['body']));
		self::assertSame(8.0, self::stockAmount($ingredientId), 'Two servings\' worth of the ingredient were consumed');
		self::assertSame(2.0, self::stockAmount($outputId), 'The meal plan entry\'s servings were produced, not the shadow\'s own');

		$booked = self::stockLogSince($watermark);
		self::assertCount(2, $booked, 'One consume row and one self-production row were written');
	}

	/**
	 * The caller here holds STOCK_CONSUME - the one permission the unfixed route checked -
	 * but not RECIPES_VIEW, so this is the scenario the leak actually happened in: on
	 * unfixed code, consuming the existing (producing) recipe succeeds (204, and books
	 * self-production) while consuming a made-up id fails ("Recipe does not exist", 400) -
	 * two different outcomes that between them disclose both that the recipe exists and
	 * that it produces something. A zero-grant caller would not show this, because it
	 * would already be refused for lacking STOCK_CONSUME regardless of RECIPES_VIEW.
	 */
	public function testConsumeNeitherExistenceNorOutputLeaksWithoutRecipesView(): void
	{
		$outputId = self::insertProduct('Authz Output F');
		$recipeId = self::insertRecipe('Authz Existing Recipe F', ['product_id' => $outputId]);

		$watermark = self::highestStockLogId();
		$existing = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['consume-only']);
		$missing = self::send('POST', '/api/recipes/987654321/consume', self::$keys['consume-only']);

		self::assertSame(403, $existing['status'], 'No RECIPES_VIEW: refused before the recipe is inspected, whatever it produces');
		self::assertSame(403, $missing['status'], 'A non-existent recipe id is refused identically');
		self::assertSame($existing['status'], $missing['status'], 'An existing and a missing recipe id are refused the same way');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced on refusal');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');
	}

	// ------------------------------------------------------------------------------
	// POST /api/recipes/{id}/add-not-fulfilled-products-to-shoppinglist
	// ------------------------------------------------------------------------------

	public function testShoppingListItemsAddOnlyCannotUseTheRoute(): void
	{
		$productId = self::insertProduct('Authz Shopping Product A');
		$recipeId = self::insertRecipe('Authz Shopping Recipe A');
		self::addIngredient($recipeId, $productId, 3);

		$response = self::send('POST', '/api/recipes/' . $recipeId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);

		self::assertSame(403, $response['status'], 'SHOPPINGLIST_ITEMS_ADD alone must not read the recipe');
		self::assertSame(0, self::shoppingListCountFor($productId), 'No shopping_list row was created');
	}

	public function testViewAndShoppingListItemsAddCanUseTheRoute(): void
	{
		$productId = self::insertProduct('Authz Shopping Product B');
		$recipeId = self::insertRecipe('Authz Shopping Recipe B');
		self::addIngredient($recipeId, $productId, 3);

		$response = self::send('POST', '/api/recipes/' . $recipeId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['view-shoppinglist'], []);

		self::assertSame(204, $response['status'], 'RECIPES_VIEW + SHOPPINGLIST_ITEMS_ADD is enough: ' . json_encode($response['body']));
		self::assertSame(1, self::shoppingListCountFor($productId), 'The missing ingredient was put on the shopping list');
	}

	/**
	 * As with the consume case above, the caller holds SHOPPINGLIST_ITEMS_ADD - the one
	 * permission the unfixed route checked - but not RECIPES_VIEW: on unfixed code this
	 * succeeds (204, and puts the ingredient on the shopping list) for the existing recipe
	 * while a made-up id fails, disclosing the existing recipe's existence and ingredients.
	 */
	public function testAddMissingNeitherExistenceNorContentLeaksWithoutRecipesView(): void
	{
		$productId = self::insertProduct('Authz Shopping Product C');
		$recipeId = self::insertRecipe('Authz Shopping Recipe C');
		self::addIngredient($recipeId, $productId, 3);

		$existing = self::send('POST', '/api/recipes/' . $recipeId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);
		$missing = self::send('POST', '/api/recipes/987654321/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);

		self::assertSame(403, $existing['status'], 'No RECIPES_VIEW: refused before the recipe is inspected');
		self::assertSame(403, $missing['status'], 'A non-existent recipe id is refused identically');
		self::assertSame(0, self::shoppingListCountFor($productId), 'No shopping_list row was created on refusal');
	}
}
