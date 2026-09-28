<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\RecipesController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issues #594 and #595, both found while validating #593.
 *
 * #594: /mealplan (RecipesController::MealPlan()) is gated only on MEALPLAN_VIEW, yet
 * for every product entry it embeds StockService::GetProductDetails()'s stock-state
 * fields (stock_amount*, next_due_date, location). GET /api/stock/products/{id}
 * (StockApiController::ProductDetails) gates that exact same shape behind STOCK_VIEW -
 * by refusing the whole request, not by trimming fields - so a caller who holds
 * MEALPLAN_VIEW but not STOCK_VIEW must receive none of it here either.
 * FieldPolicy's product_details rows (db/pgsql/prices-seed.sql) only ever gate price
 * fields (#590/#512/#573), never these, so this is a second, permission-level gate,
 * not a FieldPolicy one.
 *
 * #595: GetProductDetails() throws for a product that is missing or inactive
 * (StockService::ProductExists()). A single meal-plan entry naming such a product
 * must not break the whole page: the entry keeps its place in the response (with no
 * stock or price details) and every other entry in the same week still renders.
 *
 * Modeled on MealPlanProductDetailsRedactionTest.php: same direct-controller render
 * (no HTTP subprocess needed) and the same PgsqlSchemaTestCase fixture user (id 9000,
 * VICTUAL_USER_ID). Permissions are granted directly through user_permissions rather
 * than a seeded role, because no shipped role holds MEALPLAN_VIEW without STOCK_VIEW -
 * db/pgsql/roles-seed.sql grants both to every role that has either (CHILD, GUEST).
 */
class MealPlanStockStateTest extends PgsqlSchemaTestCase
{
	private const LOCATION = 9810;
	private const PRODUCT = 9810;
	private const PRODUCT_TO_DEACTIVATE = 9811;
	private const PRODUCT_INTACT = 9812;

	private static PDO $db;
	private static RecipesController $controller;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$controller = new RecipesController($container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'mealplan-stockstate-caller', 'fixture')");

		self::$db->exec('INSERT INTO locations(id, name) VALUES (' . self::LOCATION . ", 'MealPlanStockState Pantry')");

		// Quantity unit 2 is 'Piece', seeded by the migrations - the same assumption
		// MealPlanProductDetailsRedactionTest.php makes for its own fixture product.
		foreach ([self::PRODUCT, self::PRODUCT_TO_DEACTIVATE, self::PRODUCT_INTACT] as $productId)
		{
			self::$db->exec('INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ('
				. $productId . ", 'MealPlanStockState Product $productId', " . self::LOCATION . ', 2, 2)');
		}

		// The fixture entry #594's tests are about: a product entry (not a recipe) for
		// today, inside MealPlan()'s default +/-6 day window.
		self::$db->exec(
			'INSERT INTO meal_plan(day, type, product_id, product_amount, product_qu_id) VALUES (CURRENT_DATE, '
			. "'product', " . self::PRODUCT . ', 1, 2)'
		);
	}

	private static function permissionId(string $name): int
	{
		$stmt = self::$db->prepare('SELECT id FROM permission_hierarchy WHERE name = ?');
		$stmt->execute([$name]);
		$id = $stmt->fetchColumn();
		self::assertNotFalse($id, "permission_hierarchy has a $name row - or this fixture's permission name is wrong");

		return (int)$id;
	}

	/** Grants user 9000 exactly the given permissions (no role), replacing whatever it held before. */
	private static function grantOnly(string ...$permissionNames): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		foreach ($permissionNames as $name)
		{
			self::$db->exec('INSERT INTO user_permissions(user_id, permission_id) VALUES (9000, ' . self::permissionId($name) . ')');
		}
	}

	/**
	 * Renders GET /mealplan and returns the decoded fullcalendar event whose
	 * mealPlanEntry.product_id matches the given product, or null when no such event
	 * is present in the response - read from the served HTML the way mealplan.js
	 * reads it (event.mealPlanEntry / event.productDetails), not from the
	 * controller's internals.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function eventForProduct(int $productId): ?array
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/mealplan');
		$response = self::$controller->MealPlan($request, new Response(), []);

		self::assertSame(200, $response->getStatusCode(), 'GET /mealplan renders');

		$html = (string)$response->getBody();
		self::assertSame(1, preg_match('/Victual\.FullcalendarEventSources = (.*);\R/', $html, $matches), 'The page embeds Victual.FullcalendarEventSources');

		$eventSources = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($eventSources);
		$events = $eventSources[0] ?? [];

		foreach ($events as $event)
		{
			$mealPlanEntry = json_decode($event['mealPlanEntry'], true, 512, JSON_THROW_ON_ERROR);
			if (($mealPlanEntry['product_id'] ?? null) === $productId)
			{
				return $event;
			}
		}

		return null;
	}

	public function testMealPlanViewWithoutStockViewReceivesNoStockStateFields(): void
	{
		self::grantOnly('MEALPLAN_VIEW');

		$event = self::eventForProduct(self::PRODUCT);
		self::assertNotNull($event, 'The fixture entry is present in the response even though this identity lacks STOCK_VIEW');

		$productDetails = json_decode($event['productDetails'], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($productDetails, 'A page-visible product still produces a productDetails object, just a reduced one - the page keeps working');

		self::assertArrayHasKey('product', $productDetails, 'The product name/picture/calories the calendar card renders survive');
		self::assertArrayHasKey('quantity_unit_stock', $productDetails, 'The stock quantity unit the calendar card renders survives');

		foreach (['stock_amount', 'stock_value', 'stock_amount_opened', 'stock_amount_aggregated', 'stock_amount_opened_aggregated', 'stock_amount_measured', 'next_due_date', 'location', 'is_aggregated_amount'] as $field)
		{
			self::assertArrayNotHasKey($field, $productDetails, "MEALPLAN_VIEW alone must not surface product_details.$field - the API gates it behind STOCK_VIEW by refusing the whole request");
		}
	}

	public function testMealPlanViewWithStockViewStillReceivesStockStateFields(): void
	{
		self::grantOnly('MEALPLAN_VIEW', 'STOCK_VIEW');

		$event = self::eventForProduct(self::PRODUCT);
		self::assertNotNull($event);

		$productDetails = json_decode($event['productDetails'], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($productDetails);

		foreach (['stock_amount', 'stock_amount_aggregated', 'next_due_date', 'location'] as $field)
		{
			self::assertArrayHasKey($field, $productDetails, "A caller who also holds STOCK_VIEW must still receive product_details.$field");
		}
	}

	public function testDeactivatedProductEntryRendersAlongsideAnIntactEntry(): void
	{
		self::grantOnly('MEALPLAN_VIEW', 'STOCK_VIEW');

		self::$db->exec('INSERT INTO meal_plan(day, type, product_id, product_amount, product_qu_id) VALUES (CURRENT_DATE, '
			. "'product', " . self::PRODUCT_TO_DEACTIVATE . ', 1, 2)');
		self::$db->exec('INSERT INTO meal_plan(day, type, product_id, product_amount, product_qu_id) VALUES (CURRENT_DATE, '
			. "'product', " . self::PRODUCT_INTACT . ', 1, 2)');

		// Deactivated only after its meal-plan entry already exists - exactly the
		// scenario #595 describes ("a meal-plan entry for a deactivated product").
		self::$db->exec('UPDATE products SET active = 0 WHERE id = ' . self::PRODUCT_TO_DEACTIVATE);

		$deactivatedEvent = self::eventForProduct(self::PRODUCT_TO_DEACTIVATE);
		self::assertNotNull($deactivatedEvent, 'The entry naming the deactivated product is still present in the response (issue #595) - the page did not fail to render');
		self::assertSame('null', $deactivatedEvent['productDetails'] ?? 'null', 'No stock or price details are produced for a deactivated product');

		$intactEvent = self::eventForProduct(self::PRODUCT_INTACT);
		self::assertNotNull($intactEvent, 'A second entry in the same week is unaffected by the first one\'s deactivated product');

		$intactDetails = json_decode($intactEvent['productDetails'], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($intactDetails, 'The still-active product in the same week renders normally');
		self::assertArrayHasKey('stock_amount_aggregated', $intactDetails, 'This identity still holds STOCK_VIEW, so the intact entry keeps its stock-state fields');
	}
}
