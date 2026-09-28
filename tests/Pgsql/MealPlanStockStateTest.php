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
 * must not break the whole page: the entry keeps its place in the response, marked
 * `inactive` (product row still exists, just deactivated) or `missing` (product row is
 * gone - meal_plan.product_id carries no FK, db/pgsql/baseline/01_tables.sql), and
 * every other entry in the same week still renders normally. mealplan.js renders a
 * reduced card from that marker rather than hiding the entry (round 2 review of PR
 * #599): the entry must stay visible and deletable.
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
	private const PRODUCT_TO_DELETE = 9813;

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
		foreach ([self::PRODUCT, self::PRODUCT_TO_DEACTIVATE, self::PRODUCT_INTACT, self::PRODUCT_TO_DELETE] as $productId)
		{
			self::$db->exec('INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ('
				. $productId . ", 'MealPlanStockState Product $productId', " . self::LOCATION . ', 2, 2)');
		}

		// One meal-plan entry per fixture product, all for today, inside MealPlan()'s
		// default +/-6 day window - created while PRODUCT/PRODUCT_TO_DEACTIVATE/
		// PRODUCT_INTACT are still active, exactly the scenario #595 describes ("a
		// meal-plan entry for a deactivated product").
		foreach ([self::PRODUCT, self::PRODUCT_TO_DEACTIVATE, self::PRODUCT_INTACT] as $productId)
		{
			self::$db->exec('INSERT INTO meal_plan(day, type, product_id, product_amount, product_qu_id) VALUES (CURRENT_DATE, '
				. "'product', $productId, 1, 2)");
		}

		self::$db->exec('UPDATE products SET active = 0 WHERE id = ' . self::PRODUCT_TO_DEACTIVATE);

		// PRODUCT_TO_DELETE is removed - and only then given a meal-plan entry - rather
		// than the reverse order: db/pgsql/baseline/06_triggers_a.sql's
		// cascade_product_removal trigger deletes any meal_plan row naming a product on
		// that product's own removal, so deleting it after the entry already existed
		// would just remove the entry along with it, not reproduce #595's dangling
		// reference. meal_plan.product_id has no FK (db/pgsql/baseline/01_tables.sql),
		// so nothing stops the entry below from naming a product id that was already
		// gone by the time it was created - the same shape a raw import or an
		// out-of-band deletion could leave behind.
		self::$db->exec('DELETE FROM products WHERE id = ' . self::PRODUCT_TO_DELETE);

		// meal_plan's own create_internal_recipe AFTER INSERT trigger
		// (db/pgsql/baseline/06_triggers_c.sql) copies every product entry for the day
		// into recipes_pos, and recipes_pos_qu_id_default (BEFORE INSERT on recipes_pos)
		// requires a resolvable quantity-unit conversion for the named product - which
		// requires the product to still exist. That check exists to stop *new* rows
		// from referencing a product/QU pair that cannot be resolved; it is not what
		// #595 is about (a row that already exists and later loses its product), so it
		// is disabled only around this one fixture insert that intentionally recreates
		// that already-dangling state directly, then re-enabled immediately after.
		self::$db->exec('ALTER TABLE recipes_pos DISABLE TRIGGER recipes_pos_qu_id_default');
		self::$db->exec('INSERT INTO meal_plan(day, type, product_id, product_amount, product_qu_id) VALUES (CURRENT_DATE, '
			. "'product', " . self::PRODUCT_TO_DELETE . ', 1, 2)');
		self::$db->exec('ALTER TABLE recipes_pos ENABLE TRIGGER recipes_pos_qu_id_default');
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

	/** Decodes an event's productDetails field the way mealplan.js's JSON.parse(event.productDetails) does. */
	private static function productDetailsOf(array $event): mixed
	{
		return json_decode($event['productDetails'], true, 512, JSON_THROW_ON_ERROR);
	}

	public function testMealPlanViewWithoutStockViewReceivesNoStockStateFields(): void
	{
		self::grantOnly('MEALPLAN_VIEW');

		$event = self::eventForProduct(self::PRODUCT);
		self::assertNotNull($event, 'The fixture entry is present in the response even though this identity lacks STOCK_VIEW');

		$productDetails = self::productDetailsOf($event);
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

		$productDetails = self::productDetailsOf($event);
		self::assertIsArray($productDetails);

		foreach (['stock_amount', 'stock_amount_aggregated', 'next_due_date', 'location'] as $field)
		{
			self::assertArrayHasKey($field, $productDetails, "A caller who also holds STOCK_VIEW must still receive product_details.$field");
		}
	}

	public function testDeactivatedProductEntryRendersAlongsideAnIntactEntry(): void
	{
		self::grantOnly('MEALPLAN_VIEW', 'STOCK_VIEW');

		$deactivatedEvent = self::eventForProduct(self::PRODUCT_TO_DEACTIVATE);
		self::assertNotNull($deactivatedEvent, 'The entry naming the deactivated product is still present in the response (issue #595) - the page did not fail to render');

		$deactivatedDetails = self::productDetailsOf($deactivatedEvent);
		self::assertIsArray($deactivatedDetails, 'The entry carries a reduced productDetails object, not null - mealplan.js renders a card from it rather than hiding the entry');
		self::assertTrue($deactivatedDetails['inactive'] ?? false, 'The deactivated marker is set');
		self::assertSame('MealPlanStockState Product ' . self::PRODUCT_TO_DEACTIVATE, $deactivatedDetails['product']['name'] ?? null, 'The product name survives so the reduced card can still show it');

		foreach (['stock_amount', 'stock_amount_aggregated', 'stock_value', 'last_price', 'avg_price', 'current_price', 'next_due_date', 'location'] as $field)
		{
			self::assertArrayNotHasKey($field, $deactivatedDetails, "No stock or price field is produced for a deactivated product, field $field");
		}

		$intactEvent = self::eventForProduct(self::PRODUCT_INTACT);
		self::assertNotNull($intactEvent, 'A second entry in the same week is unaffected by the first one\'s deactivated product');

		$intactDetails = self::productDetailsOf($intactEvent);
		self::assertIsArray($intactDetails, 'The still-active product in the same week renders normally');
		self::assertArrayHasKey('stock_amount_aggregated', $intactDetails, 'This identity still holds STOCK_VIEW, so the intact entry keeps its stock-state fields');
	}

	public function testDeactivatedProductEntryWithoutStockViewStillGetsTheInactiveMarkerOnly(): void
	{
		// The inactive check runs before the STOCK_VIEW branch in
		// RecipesController::MealPlan() - a deactivated product must never leak stock
		// data by virtue of the caller's own permission, so this must look identical to
		// the STOCK_VIEW-holding case above (round 2 review of PR #599).
		self::grantOnly('MEALPLAN_VIEW');

		$deactivatedEvent = self::eventForProduct(self::PRODUCT_TO_DEACTIVATE);
		self::assertNotNull($deactivatedEvent, 'The entry naming the deactivated product is present even without STOCK_VIEW');

		$deactivatedDetails = self::productDetailsOf($deactivatedEvent);
		self::assertIsArray($deactivatedDetails);
		self::assertTrue($deactivatedDetails['inactive'] ?? false, 'The deactivated marker is set regardless of STOCK_VIEW');
		self::assertArrayHasKey('product', $deactivatedDetails, 'The product name still survives');
		self::assertArrayNotHasKey('stock_amount_aggregated', $deactivatedDetails, 'No stock field leaks for a deactivated product, with or without STOCK_VIEW');
	}

	public function testDeletedProductEntryGetsTheMissingMarker(): void
	{
		self::grantOnly('MEALPLAN_VIEW', 'STOCK_VIEW');

		$deletedEvent = self::eventForProduct(self::PRODUCT_TO_DELETE);
		self::assertNotNull($deletedEvent, 'The entry naming a since-deleted product is still present in the response (issue #595)');

		$deletedDetails = self::productDetailsOf($deletedEvent);
		self::assertIsArray($deletedDetails, 'A reduced productDetails object is still produced, not null');
		self::assertTrue($deletedDetails['missing'] ?? false, 'The missing marker is set');
		self::assertArrayNotHasKey('product', $deletedDetails, 'There is no product row left to name');
		self::assertArrayNotHasKey('stock_amount_aggregated', $deletedDetails, 'No stock field is produced for a product that no longer exists');
	}
}
