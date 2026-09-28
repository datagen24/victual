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

	/** @var array<string,string> session_key values by role name, for page routes (sendToPage()) */
	private static array $sessions = [];

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
			// No RECIPES_VIEW - the existence-leak scenario for the pair of permissions that
			// (unlike consume-only above) is enough to actually consume a producing recipe.
			'consume-purchase' => ['id' => 9605, 'permissions' => ['STOCK_CONSUME', 'STOCK_PURCHASE']],
			// RECIPES_VIEW + STOCK_PURCHASE, deliberately without STOCK_CONSUME: on a plain
			// (non-producing) recipe, GetEffectiveOutputProductId() is empty, so the consume
			// button's own STOCK_PURCHASE clause is already satisfied regardless of this grant -
			// isolating the button's separate STOCK_CONSUME requirement.
			'view-purchase' => ['id' => 9608, 'permissions' => ['RECIPES_VIEW', 'STOCK_PURCHASE']],
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

		// The built-in CHILD role (RECIPES_VIEW + STOCK_CONSUME + SHOPPINGLIST_ITEMS_ADD,
		// never STOCK_PURCHASE - db/pgsql/roles-seed.sql), granted through user_roles rather
		// than a direct user_permissions row, the way a household actually assigns it.
		$insertUser->execute([9606, 'authz-child', 'fixture']);
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) SELECT 9606, id FROM roles WHERE code = \'CHILD\'');
		self::$keys['child-role'] = self::issueKey(9606);

		// Page-render cases go through GET /recipes, which only accepts a session
		// cookie (see sendToPage()) - one session per user whose eligibility a page-render
		// case checks, inserted the way ViewCorrectionsHttpTest.php's own fixture does.
		$insertSession = self::$db->prepare("INSERT INTO sessions(session_key, user_id, expires) VALUES (?, ?, now() + interval '1 day')");
		foreach (['child-role' => 9606, 'view-consume' => 9601, 'view-consume-purchase' => 9602, 'view-purchase' => 9608] as $name => $userId)
		{
			$sessionKey = 'authz-session-' . $name;
			$insertSession->execute([$sessionKey, $userId]);
			self::$sessions[$name] = $sessionKey;
		}

		// A custom (non-built-in) role, to show the routes work from role-resolved
		// permissions generally and not only from the one built-in role or a direct grant.
		$insertUser->execute([9607, 'authz-custom-role', 'fixture']);
		$customRoleId = (int)self::$db->query(
			'INSERT INTO roles (code, name, builtin) VALUES (\'AUTHZ_CUSTOM_SHOPPER\', \'Authz Custom Shopper\', 0) RETURNING id'
		)->fetchColumn();
		self::$db->exec(
			'INSERT INTO role_permissions (role_id, permission_id) SELECT ' . $customRoleId . ', id FROM permission_hierarchy WHERE name IN (\'RECIPES_VIEW\', \'SHOPPINGLIST_ITEMS_ADD\')'
		);
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9607, ' . $customRoleId . ')');
		self::$keys['custom-shoppinglist-role'] = self::issueKey(9607);
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
	 * @param array<string,string> $extraEnv Additional environment for the subprocess - only
	 *              VICTUAL_INFLUXDB_ENABLED=true (see sendWithInfluxEnabled()) at present.
	 * @return array{status: int, body: mixed}
	 */
	private static function send(string $method, string $path, string $apiKey, ?array $body = null, array $extraEnv = []): array
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
		], $extraEnv);

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

	private static function outboxCount(): int
	{
		return (int)self::$db->query('SELECT COUNT(*) FROM outbox')->fetchColumn();
	}

	/**
	 * Starts request-subprocess-helper.php with VICTUAL_INFLUXDB_ENABLED=true, so
	 * BookingEventPublisher::RecordTransaction() actually enqueues an outbox row for whatever
	 * this call books - config-dist.php defaults INFLUXDB_ENABLED to false, and a PHP
	 * constant cannot be redefined once set, so every other request in this file (through the
	 * plain send() above) never enqueues anything, refusal or not. A copy of
	 * ComposedOperationAtomicityTest::requestWithInfluxEnabled(), kept local here rather than
	 * factored into a shared helper.
	 *
	 * @return array{status: int, body: mixed}
	 */
	private static function sendWithInfluxEnabled(string $method, string $path, string $apiKey, ?array $body = null): array
	{
		return self::send($method, $path, $apiKey, $body, ['VICTUAL_INFLUXDB_ENABLED' => 'true']);
	}

	private static function roleId(string $code): int
	{
		$statement = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$statement->execute([$code]);

		return (int)$statement->fetchColumn();
	}

	/**
	 * A page route (e.g. GET /recipes) accepts only a session cookie, never an API key -
	 * middleware/Auth/DefaultAuthMiddleware::AuthenticateRequest() consults ApiKeyAuthenticator
	 * only when $this->IsApiRoute, "An API key is a credential for the API and nothing else:
	 * it cannot open a rendered page" - so send()'s VICTUAL-API-KEY header gets an
	 * unauthenticated 302 (to the login page) here instead of a 403, and this is send()'s
	 * page-route counterpart, keyed by session_key rather than by API key, mirroring
	 * ViewCorrectionsHttpTest.php's own send()/sessions fixture.
	 *
	 * @return array{status: int, body: mixed}
	 */
	private static function sendToPage(string $method, string $path, string $sessionKey): array
	{
		$spec = ['method' => $method, 'path' => $path, 'cookie' => $sessionKey];

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
		$outboxBefore = self::outboxCount();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['consume-only']);

		self::assertSame(403, $response['status'], 'STOCK_CONSUME alone must not read or consume the recipe');
		self::assertSame(5.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written');
		self::assertSame(0, self::shoppingListCountFor($ingredientId), 'The refusal touched no shopping_list row either');
		self::assertSame($outboxBefore, self::outboxCount(), 'No outbox row was enqueued on refusal');
	}

	public function testViewAndConsumeCannotConsumeAProducingRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz Ingredient B');
		$outputId = self::insertProduct('Authz Output B');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Producing Recipe B', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();
		$outboxBefore = self::outboxCount();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['view-consume']);

		self::assertSame(403, $response['status'], 'RECIPES_VIEW + STOCK_CONSUME is not enough for a recipe that produces stock');
		self::assertSame(5.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written');
		self::assertSame(0, self::shoppingListCountFor($ingredientId), 'The refusal touched no shopping_list row either');
		self::assertSame($outboxBefore, self::outboxCount(), 'No outbox row was enqueued on refusal');
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
		$outboxBefore = self::outboxCount();
		$refused = self::send('POST', '/api/recipes/' . $shadowId . '/consume', self::$keys['view-consume']);

		self::assertSame(403, $refused['status'], 'The shadow\'s original recipe produces stock, so STOCK_PURCHASE is required too');
		self::assertSame(10.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');
		self::assertSame($outboxBefore, self::outboxCount(), 'No outbox row was enqueued on refusal');

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
		$outboxBefore = self::outboxCount();
		$existing = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['consume-only']);
		$missing = self::send('POST', '/api/recipes/987654321/consume', self::$keys['consume-only']);

		self::assertSame(403, $existing['status'], 'No RECIPES_VIEW: refused before the recipe is inspected, whatever it produces');
		self::assertSame(403, $missing['status'], 'A non-existent recipe id is refused identically');
		self::assertSame($existing['status'], $missing['status'], 'An existing and a missing recipe id are refused the same way');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced on refusal');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');
		self::assertSame($outboxBefore, self::outboxCount(), 'Neither refusal enqueued an outbox row');
	}

	// ------------------------------------------------------------------------------
	// POST /api/recipes/{id}/add-not-fulfilled-products-to-shoppinglist
	// ------------------------------------------------------------------------------

	public function testShoppingListItemsAddOnlyCannotUseTheRoute(): void
	{
		$productId = self::insertProduct('Authz Shopping Product A');
		$recipeId = self::insertRecipe('Authz Shopping Recipe A');
		self::addIngredient($recipeId, $productId, 3);

		$watermark = self::highestStockLogId();
		$outboxBefore = self::outboxCount();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);

		self::assertSame(403, $response['status'], 'SHOPPINGLIST_ITEMS_ADD alone must not read the recipe');
		self::assertSame(0, self::shoppingListCountFor($productId), 'No shopping_list row was created');
		self::assertSame(0.0, self::stockAmount($productId), 'The refusal touched no stock row');
		self::assertSame([], self::stockLogSince($watermark), 'The refusal touched no stock_log row');
		self::assertSame($outboxBefore, self::outboxCount(), 'No outbox row was enqueued on refusal');
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

		$watermark = self::highestStockLogId();
		$outboxBefore = self::outboxCount();
		$existing = self::send('POST', '/api/recipes/' . $recipeId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);
		$missing = self::send('POST', '/api/recipes/987654321/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);

		self::assertSame(403, $existing['status'], 'No RECIPES_VIEW: refused before the recipe is inspected');
		self::assertSame(403, $missing['status'], 'A non-existent recipe id is refused identically');
		self::assertSame(0, self::shoppingListCountFor($productId), 'No shopping_list row was created on refusal');
		self::assertSame(0.0, self::stockAmount($productId), 'Neither refusal touched a stock row');
		self::assertSame([], self::stockLogSince($watermark), 'Neither refusal touched a stock_log row');
		self::assertSame($outboxBefore, self::outboxCount(), 'Neither refusal enqueued an outbox row');
	}

	// ------------------------------------------------------------------------------
	// Issue #532: fail-closed when RecipesService::ConsumeRecipe() is called with no request
	// ------------------------------------------------------------------------------

	/**
	 * ConsumeRecipe($recipeId, $request = null) used to check STOCK_PURCHASE only when
	 * $request !== null, so a direct caller that simply forgot the argument (the shape every
	 * pre-#532 test and dev-tool caller already had) silently skipped the check and booked
	 * self-production anyway - failing open rather than closed. This class's own ambient
	 * caller (VICTUAL_USER_ID 9000, from PgsqlSchemaTestCase) is never inserted into `users`
	 * or granted anything here, so HasPermission() is false for every permission - the
	 * fixture for "a caller with no permissions at all", matching this case exactly.
	 */
	public function testDirectServiceCallWithNoRequestFailsClosedForAProducingRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz R1 Direct Ingredient');
		$outputId = self::insertProduct('Authz R1 Direct Output');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz R1 Direct Producing Recipe', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();

		// self::fail() is deliberately not called inside this try block: PHPUnit's own
		// assertion failure is itself a \Exception, so a self::fail() placed where
		// ConsumeRecipe() was expected to throw would be swallowed by the very
		// catch (\Exception) meant to catch ConsumeRecipe()'s refusal, turning "it didn't
		// throw" into a confusing mismatch on $exceptionThrown's message instead of a clear
		// "must throw" failure.
		$exceptionThrown = null;
		try
		{
			RecipesService::GetInstance()->ConsumeRecipe($recipeId);
		}
		catch (\Exception $exception)
		{
			$exceptionThrown = $exception;
		}

		self::assertNotNull($exceptionThrown, 'A direct caller with no request and no STOCK_PURCHASE must not silently succeed');
		self::assertStringContainsString('STOCK_PURCHASE', $exceptionThrown->getMessage());
		self::assertSame(5.0, self::stockAmount($ingredientId), 'Nothing was consumed on refusal');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced on refusal');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');
	}

	// The positive control - the same shape, with the ambient user granted STOCK_PURCHASE,
	// succeeds - cannot live in this file: VICTUAL_USER_ID is a PHP constant, fixed for the
	// whole process by PgsqlSchemaTestCase::Boot() at 9000, the same id the negative case
	// above depends on holding nothing. tests/Pgsql/RecipeOperationsTest.php and
	// ComposedOperationAtomicityTest.php are that control: both grant their own user 9000
	// ADMIN (issue #532) and both call ConsumeRecipe() directly, with no request, on
	// producing recipes, asserting the self-production booking succeeds.

	// ------------------------------------------------------------------------------
	// Issue #532: meal-plan shadow of a NON-producing recipe
	// ------------------------------------------------------------------------------

	/**
	 * The positive-shape counterpart to testMealPlanShadowOfAProducingRecipeNeedsStockPurchase()
	 * above: a shadow whose original recipe produces nothing needs only RECIPES_VIEW +
	 * STOCK_CONSUME, like any other consumption-only recipe, and books only a consume row -
	 * GetEffectiveOutputProductId()/the resolution inside ConsumeRecipe() correctly report
	 * "nothing produced" rather than treating every shadow as if STOCK_PURCHASE applied.
	 */
	public function testMealPlanShadowOfANonProducingRecipeNeedsOnlyConsume(): void
	{
		$ingredientId = self::insertProduct('Authz Ingredient G');
		self::stockUp($ingredientId, 10);

		$originalId = self::insertRecipe('Authz Meal Plan Original G');
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
		$response = self::send('POST', '/api/recipes/' . $shadowId . '/consume', self::$keys['view-consume']);

		self::assertSame(204, $response['status'], 'RECIPES_VIEW + STOCK_CONSUME is enough for a shadow whose original produces nothing: ' . json_encode($response['body']));
		self::assertSame(8.0, self::stockAmount($ingredientId), 'Two servings\' worth of the ingredient were consumed');

		$booked = self::stockLogSince($watermark);
		self::assertCount(1, $booked, 'Only the consume row was written - nothing was self-produced');
		self::assertSame(StockService::TRANSACTION_TYPE_CONSUME, $booked[0]['transaction_type']);
	}

	// ------------------------------------------------------------------------------
	// Issue #532: the built-in CHILD role, granted through user_roles
	// ------------------------------------------------------------------------------

	/**
	 * CHILD holds RECIPES_VIEW + STOCK_CONSUME but never STOCK_PURCHASE
	 * (db/pgsql/roles-seed.sql) - the maintainer's own example (issue #532's first comment)
	 * of a role that could self-produce stock through a recipe before this fix.
	 */
	public function testChildRoleCannotConsumeAProducingRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz Child Ingredient A');
		$outputId = self::insertProduct('Authz Child Output A');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Child Producing Recipe A', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();
		$outboxBefore = self::outboxCount();
		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['child-role']);

		self::assertSame(403, $response['status'], 'CHILD holds STOCK_CONSUME but not STOCK_PURCHASE, so a producing recipe is refused');
		self::assertSame(5.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written');
		self::assertSame($outboxBefore, self::outboxCount(), 'No outbox row was enqueued on refusal');
	}

	public function testChildRoleCanConsumeAConsumptionOnlyRecipe(): void
	{
		$ingredientId = self::insertProduct('Authz Child Ingredient B');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Child Consumption Only Recipe B');
		self::addIngredient($recipeId, $ingredientId, 2);

		$response = self::send('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['child-role']);

		self::assertSame(204, $response['status'], 'CHILD may consume a recipe that produces nothing: ' . json_encode($response['body']));
		self::assertSame(3.0, self::stockAmount($ingredientId), 'The ingredient was consumed');
	}

	public function testChildRoleCanAddMissingToShoppingList(): void
	{
		$productId = self::insertProduct('Authz Child Shopping Product');
		$recipeId = self::insertRecipe('Authz Child Shopping Recipe');
		self::addIngredient($recipeId, $productId, 3);

		$response = self::send('POST', '/api/recipes/' . $recipeId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['child-role'], []);

		self::assertSame(204, $response['status'], 'CHILD holds RECIPES_VIEW + SHOPPINGLIST_ITEMS_ADD: ' . json_encode($response['body']));
		self::assertSame(1, self::shoppingListCountFor($productId), 'The missing ingredient was put on the shopping list');
	}

	// ------------------------------------------------------------------------------
	// Issue #532: response BODIES, not only statuses, across recipe shapes
	// ------------------------------------------------------------------------------

	/**
	 * Comparing only the status (every case above) leaves open whether the body still
	 * distinguishes a producing recipe from a plain one, or an existing id from a missing
	 * one - a client-visible difference there would be the same kind of leak issue #532 is
	 * about, just moved from the status into the payload. STOCK_CONSUME alone never reaches
	 * RECIPES_VIEW, so all three bodies below must be indistinguishable.
	 */
	public function testConsumeResponseBodyIsIdenticalAcrossRecipeShapesForStockConsumeAlone(): void
	{
		$outputId = self::insertProduct('Authz Body Output A');
		$producingId = self::insertRecipe('Authz Body Producing Recipe A', ['product_id' => $outputId]);
		$plainId = self::insertRecipe('Authz Body Plain Recipe A');

		$producing = self::send('POST', '/api/recipes/' . $producingId . '/consume', self::$keys['consume-only']);
		$plain = self::send('POST', '/api/recipes/' . $plainId . '/consume', self::$keys['consume-only']);
		$missing = self::send('POST', '/api/recipes/987654322/consume', self::$keys['consume-only']);

		self::assertSame(403, $producing['status']);
		self::assertSame($producing['status'], $plain['status']);
		self::assertSame($producing['status'], $missing['status']);
		self::assertSame($producing['body'], $plain['body'], 'A producing and a plain recipe refuse with the identical body');
		self::assertSame($producing['body'], $missing['body'], 'An existing and a missing recipe id refuse with the identical body');
	}

	/**
	 * STOCK_CONSUME + STOCK_PURCHASE without RECIPES_VIEW: enough to consume either recipe
	 * shape if the caller could reach them, so this is the strongest version of the leak
	 * check - a caller holding everything the booking itself needs is still refused
	 * identically before the recipe is inspected at all.
	 */
	public function testConsumeResponseBodyIsIdenticalAcrossRecipeShapesForStockConsumeAndPurchase(): void
	{
		$outputId = self::insertProduct('Authz Body Output B');
		$producingId = self::insertRecipe('Authz Body Producing Recipe B', ['product_id' => $outputId]);
		$plainId = self::insertRecipe('Authz Body Plain Recipe B');

		$producing = self::send('POST', '/api/recipes/' . $producingId . '/consume', self::$keys['consume-purchase']);
		$plain = self::send('POST', '/api/recipes/' . $plainId . '/consume', self::$keys['consume-purchase']);
		$missing = self::send('POST', '/api/recipes/987654323/consume', self::$keys['consume-purchase']);

		self::assertSame(403, $producing['status']);
		self::assertSame($producing['status'], $plain['status']);
		self::assertSame($producing['status'], $missing['status']);
		self::assertSame($producing['body'], $plain['body'], 'A producing and a plain recipe refuse with the identical body');
		self::assertSame($producing['body'], $missing['body'], 'An existing and a missing recipe id refuse with the identical body');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced despite holding STOCK_PURCHASE too');
	}

	/** Same check for the add-missing route: SHOPPINGLIST_ITEMS_ADD alone, without RECIPES_VIEW. */
	public function testAddMissingResponseBodyIsIdenticalAcrossRecipeShapesForShoppingListItemsAddAlone(): void
	{
		$outputId = self::insertProduct('Authz Body Output C');
		$producingId = self::insertRecipe('Authz Body Producing Recipe C', ['product_id' => $outputId]);
		$plainId = self::insertRecipe('Authz Body Plain Recipe C');

		$producing = self::send('POST', '/api/recipes/' . $producingId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);
		$plain = self::send('POST', '/api/recipes/' . $plainId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);
		$missing = self::send('POST', '/api/recipes/987654324/add-not-fulfilled-products-to-shoppinglist', self::$keys['shoppinglist-only'], []);

		self::assertSame(403, $producing['status']);
		self::assertSame($producing['status'], $plain['status']);
		self::assertSame($producing['status'], $missing['status']);
		self::assertSame($producing['body'], $plain['body'], 'A producing and a plain recipe refuse with the identical body');
		self::assertSame($producing['body'], $missing['body'], 'An existing and a missing recipe id refuse with the identical body');
	}

	// ------------------------------------------------------------------------------
	// Issue #532: the outbox, with InfluxDB actually enabled
	// ------------------------------------------------------------------------------

	/**
	 * Every outbox assertion above runs with InfluxDB disabled (config-dist.php's default),
	 * so it only shows a refusal enqueues nothing when nothing would be enqueued either way.
	 * This exercises the actual publishing path (BookingEventPublisher::RecordTransaction()):
	 * a refusal must still enqueue nothing when it is live.
	 */
	public function testRefusedConsumeWithInfluxEnabledDoesNotEnqueueAnOutboxRow(): void
	{
		$ingredientId = self::insertProduct('Authz Influx Ingredient A');
		$outputId = self::insertProduct('Authz Influx Output A');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Influx Producing Recipe A', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();
		$outboxBefore = self::outboxCount();
		$response = self::sendWithInfluxEnabled('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['view-consume']);

		self::assertSame(403, $response['status'], 'RECIPES_VIEW + STOCK_CONSUME is not enough for a producing recipe, InfluxDB enabled or not');
		self::assertSame(5.0, self::stockAmount($ingredientId), 'The ingredient stock is untouched');
		self::assertSame(0.0, self::stockAmount($outputId), 'Nothing was self-produced');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written');
		self::assertSame(0, self::shoppingListCountFor($ingredientId), 'The refusal touched no shopping_list row either');
		self::assertSame($outboxBefore, self::outboxCount(), 'The refusal enqueued nothing even with InfluxDB enabled');
	}

	/**
	 * The control for the case above: with InfluxDB enabled, a SUCCESSFUL consume does
	 * enqueue outbox rows - one per transaction id (BookingEventPublisher's own "one event
	 * per transaction" rule), so a fully-granted producing-recipe consume enqueues two: the
	 * ingredient consumption and the self-production each get their own transaction id
	 * (RecipesService::ConsumeRecipe() only shares $transactionId across the ingredients).
	 * This is what makes the previous case's unchanged count actual evidence of a refusal
	 * that booked nothing, rather than of a publishing path that never runs in this harness.
	 */
	public function testSuccessfulConsumeWithInfluxEnabledEnqueuesOutboxRows(): void
	{
		$ingredientId = self::insertProduct('Authz Influx Ingredient B');
		$outputId = self::insertProduct('Authz Influx Output B');
		self::stockUp($ingredientId, 5);

		$recipeId = self::insertRecipe('Authz Influx Producing Recipe B', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$outboxBefore = self::outboxCount();
		$response = self::sendWithInfluxEnabled('POST', '/api/recipes/' . $recipeId . '/consume', self::$keys['view-consume-purchase']);

		self::assertSame(204, $response['status'], 'RECIPES_VIEW + STOCK_CONSUME + STOCK_PURCHASE may consume a producing recipe: ' . json_encode($response['body']));
		$newRows = self::$db->query('SELECT event_type FROM outbox ORDER BY id OFFSET ' . $outboxBefore)->fetchAll(PDO::FETCH_COLUMN);
		self::assertCount(2, $newRows, 'One outbox row per booked transaction: the ingredient consume and the self-production');
		self::assertSame(['stock.transaction_booked', 'stock.transaction_booked'], $newRows);
	}

	// ------------------------------------------------------------------------------
	// Issue #532: a custom (non-built-in) role
	// ------------------------------------------------------------------------------

	/**
	 * The routes resolve permissions, not "is this the CHILD role" - a household-defined
	 * role granting exactly RECIPES_VIEW + SHOPPINGLIST_ITEMS_ADD (assigned through
	 * user_roles, never a built-in role) must work identically to a direct grant.
	 */
	public function testCustomNonBuiltinRoleGrantingShoppingListAccessCanUseTheRoute(): void
	{
		$productId = self::insertProduct('Authz Custom Role Shopping Product');
		$recipeId = self::insertRecipe('Authz Custom Role Shopping Recipe');
		self::addIngredient($recipeId, $productId, 3);

		$response = self::send('POST', '/api/recipes/' . $recipeId . '/add-not-fulfilled-products-to-shoppinglist', self::$keys['custom-shoppinglist-role'], []);

		self::assertSame(204, $response['status'], 'A custom role resolving RECIPES_VIEW + SHOPPINGLIST_ITEMS_ADD is enough: ' . json_encode($response['body']));
		self::assertSame(1, self::shoppingListCountFor($productId), 'The missing ingredient was put on the shopping list');
	}

	// ------------------------------------------------------------------------------
	// Issue #532: UI eligibility - views/recipes.blade.php's consume button
	// ------------------------------------------------------------------------------

	/**
	 * GET /recipes?recipe=<id> renders views/recipes.blade.php's own consume button
	 * server-side - a page-render counterpart to the API-level cases above, in the manner
	 * tests/Pgsql/HouseholdPagesTest.php uses for other pages, driven over the same HTTP
	 * subprocess as the API cases. routes.php registers this page under the same auth
	 * middleware as the API, but a page route accepts only a session cookie (see
	 * sendToPage() above) - so this reuses the fixture sessions above, not the API keys.
	 */
	public function testRecipesPageOffersTheConsumeButtonOnlyWhenEligible(): void
	{
		$ingredientId = self::insertProduct('Authz Page Ingredient A');
		$outputId = self::insertProduct('Authz Page Output A');
		$recipeId = self::insertRecipe('Authz Page Producing Recipe A', ['product_id' => $outputId]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$child = self::sendToPage('GET', '/recipes?recipe=' . $recipeId, self::$sessions['child-role']);
		$viewConsume = self::sendToPage('GET', '/recipes?recipe=' . $recipeId, self::$sessions['view-consume']);
		$fullGrants = self::sendToPage('GET', '/recipes?recipe=' . $recipeId, self::$sessions['view-consume-purchase']);

		self::assertSame(200, $child['status']);
		self::assertSame(200, $viewConsume['status']);
		self::assertSame(200, $fullGrants['status']);
		self::assertStringNotContainsString('recipe-consume', $child['body'], 'CHILD lacks STOCK_PURCHASE, so the button for a producing recipe must not render');
		self::assertStringNotContainsString('recipe-consume', $viewConsume['body'], 'RECIPES_VIEW + STOCK_CONSUME alone is the same gap');
		self::assertStringContainsString('recipe-consume', $fullGrants['body'], 'RECIPES_VIEW + STOCK_CONSUME + STOCK_PURCHASE may see the button');
	}

	/**
	 * Issue #532: a typed shadow URL (/recipes?recipe=<shadowId>) used to read the
	 * shadow's own product_id, which ConsumeRecipe() never sets - so the button rendered for
	 * a caller lacking STOCK_PURCHASE on a shadow whose *original* recipe produces stock,
	 * exactly the recipe testMealPlanShadowOfAProducingRecipeNeedsStockPurchase() already
	 * shows is refused at the API level.
	 */
	public function testTypedShadowUrlDoesNotOfferConsumeWithoutStockPurchase(): void
	{
		$ingredientId = self::insertProduct('Authz Page Shadow Ingredient');
		$outputId = self::insertProduct('Authz Page Shadow Output');
		self::stockUp($ingredientId, 5);

		$originalId = self::insertRecipe('Authz Page Shadow Original', ['product_id' => $outputId]);
		self::addIngredient($originalId, $ingredientId, 1);

		$mealPlanId = self::insertRow('meal_plan', [
			'day' => self::MEAL_PLAN_DAY,
			'type' => 'recipe',
			'recipe_id' => $originalId,
			'recipe_servings' => 1,
		]);

		$statement = self::$db->prepare('SELECT id FROM recipes WHERE name = ? AND type = ?');
		$statement->execute([self::MEAL_PLAN_DAY . '#' . $mealPlanId, RecipesService::RECIPE_TYPE_MEALPLAN_SHADOW]);
		$shadowId = (int)$statement->fetchColumn();
		self::assertNotSame(0, $shadowId, 'The meal plan trigger creates the shadow recipe this case renders');

		$refused = self::sendToPage('GET', '/recipes?recipe=' . $shadowId, self::$sessions['view-consume']);
		$allowed = self::sendToPage('GET', '/recipes?recipe=' . $shadowId, self::$sessions['view-consume-purchase']);

		self::assertSame(200, $refused['status']);
		self::assertSame(200, $allowed['status']);
		self::assertStringNotContainsString('recipe-consume', $refused['body'], 'The shadow\'s own product_id is always empty, but its original recipe produces stock');
		self::assertStringContainsString('recipe-consume', $allowed['body'], 'STOCK_PURCHASE makes the button eligible, following the shadow to its original recipe');
	}

	/**
	 * The consume button ANDs STOCK_CONSUME with a STOCK_PURCHASE clause that only matters for
	 * a producing recipe - on a plain recipe GetEffectiveOutputProductId() is empty, so that
	 * clause is vacuously true and STOCK_PURCHASE alone must not be enough to show the button.
	 * view-purchase holds STOCK_PURCHASE but never STOCK_CONSUME, isolating that requirement.
	 */
	public function testRecipesPageHidesConsumeButtonWithoutStockConsumeOnAPlainRecipe(): void
	{
		$recipeId = self::insertRecipe('Authz Page Plain Recipe A');

		$response = self::sendToPage('GET', '/recipes?recipe=' . $recipeId, self::$sessions['view-purchase']);

		self::assertSame(200, $response['status']);
		self::assertStringNotContainsString('recipe-consume', $response['body'], 'RECIPES_VIEW + STOCK_PURCHASE without STOCK_CONSUME must not show the consume button, even on a plain recipe');
	}

	/**
	 * The shopping-list button's SHOPPINGLIST_ITEMS_ADD condition is independent of the
	 * consume button's - view-consume holds RECIPES_VIEW + STOCK_CONSUME (enough to see the
	 * consume button on this same plain recipe) but never SHOPPINGLIST_ITEMS_ADD, so the
	 * shopping-list button must still be absent.
	 */
	public function testRecipesPageHidesShoppingListButtonWithoutShoppingListItemsAdd(): void
	{
		$recipeId = self::insertRecipe('Authz Page Plain Recipe B');

		$response = self::sendToPage('GET', '/recipes?recipe=' . $recipeId, self::$sessions['view-consume']);

		self::assertSame(200, $response['status']);
		self::assertStringNotContainsString('recipe-shopping-list', $response['body'], 'RECIPES_VIEW without SHOPPINGLIST_ITEMS_ADD must not show the add-missing-to-shopping-list button');
	}
}
