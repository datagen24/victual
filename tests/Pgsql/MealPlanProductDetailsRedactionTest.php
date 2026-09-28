<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\RecipesController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #590: for a meal-plan entry that names a product, RecipesController::MealPlan()
 * (around lines 70/81) called StockService::GetProductDetails() and json_encode()'d the
 * result straight into the page's 'productDetails' event field - the entity's price and
 * stock-value fields (last_price, avg_price, current_price, stock_value) reached a caller
 * without price visibility (GUEST, CHILD), the same class of defect as #512/#573, but on
 * the API's own product_details channel: GET /api/stock/products/{id}
 * (StockApiController::ProductDetails) already redacts this same GetProductDetails() shape
 * through FieldPolicy::RedactRow('product_details', ...); this page was a second channel
 * FieldPolicy never reached.
 *
 * Modeled on MealPlanRedactionTest.php (this page's existing redaction test, for
 * recipes_resolved/#176) - same direct-controller render, same live permission_fields
 * table rather than a hand-maintained field list, same CHILD-vs-ADMIN identities. The
 * gated fields are read live so a household that widens the policy widens what this
 * asserts, exactly as ContractTest's redaction leg and MealPlanRedactionTest already do.
 */
class MealPlanProductDetailsRedactionTest extends PgsqlSchemaTestCase
{
	private const LOCATION = 9800;
	private const PRODUCT = 9800;
	private const PRICE = 7.77;

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

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'mealplan-productdetails-caller', 'fixture')");

		self::$db->exec('INSERT INTO locations(id, name) VALUES (' . self::LOCATION . ", 'MealPlanProductDetails Pantry')");

		// Quantity unit 2 is 'Piece', seeded by the migrations - the same assumption
		// StockEntryFormPriceTest.php and StockPagesTest.php make for their own fixture
		// products.
		self::$db->exec('INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ('
			. self::PRODUCT . ", 'MealPlanProductDetails Product', " . self::LOCATION . ', 2, 2)');

		// A stock row gives GetProductDetails() a real stock_value (stock_current.value,
		// via products_resolved's self-mapping for a product with no parent) and a
		// current_price (products_current_price reads stock_next_use, which is built
		// straight from `stock`, not stock_log).
		self::$db->exec(
			'INSERT INTO stock(product_id, amount, purchased_date, stock_id, price, open, location_id) VALUES ('
			. self::PRODUCT . ", 1, CURRENT_DATE, 'mealplan-productdetails-fixture', " . self::PRICE . ', 0, ' . self::LOCATION . ')'
		);

		// last_price/avg_price are cache__products_last_purchased/cache__products_average_price,
		// which a stock_log 'purchase' trigger normally rebuilds. Writing the cache rows
		// directly - rather than replaying that trigger pipeline through
		// StockService::AddProduct() - avoids BaseService::GetInstance()'s per-class
		// singleton caching binding StockService to another test class's (by then torn
		// down) schema, the same reasoning StockEntryFormPriceTest.php gives for its own
		// direct `stock` insert.
		self::$db->exec(
			'INSERT INTO cache__products_last_purchased(product_id, amount, purchased_date, price, location_id) VALUES ('
			. self::PRODUCT . ', 1, CURRENT_DATE, ' . self::PRICE . ', ' . self::LOCATION . ')'
		);
		self::$db->exec('INSERT INTO cache__products_average_price(product_id, price) VALUES (' . self::PRODUCT . ', ' . self::PRICE . ')');

		// The meal plan entry this test's assertions are about: a product entry (not a
		// recipe) for today, inside MealPlan()'s default +/-6 day window.
		self::$db->exec(
			'INSERT INTO meal_plan(day, type, product_id, product_amount, product_qu_id) VALUES (CURRENT_DATE, '
			. "'product', " . self::PRODUCT . ', 1, 2)'
		);
	}

	private static function grant(string $roleCode): void
	{
		$stmt = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$stmt->execute([$roleCode]);
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . (int)$stmt->fetchColumn() . ')');
	}

	/** @return string[] The product_details fields permission_fields gates behind a permission. */
	private static function gatedFields(): array
	{
		$fields = self::$db->query("SELECT field FROM permission_fields WHERE entity = 'product_details' AND field <> '*'")->fetchAll(PDO::FETCH_COLUMN);
		self::assertNotEmpty($fields, 'permission_fields gates something on product_details, or this test asserts nothing');

		return $fields;
	}

	/**
	 * Renders GET /mealplan and returns the fixture meal-plan entry's decoded
	 * productDetails - taken from the served HTML's embedded event data, the way
	 * mealplan.js's JSON.parse(event.productDetails) reads it, not from the controller's
	 * internals.
	 *
	 * @return array<string, mixed>
	 */
	private static function renderedProductDetails(): array
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/mealplan');
		$response = self::$controller->MealPlan($request, new Response(), []);

		self::assertSame(200, $response->getStatusCode(), 'GET /mealplan renders');

		$html = (string)$response->getBody();
		self::assertSame(1, preg_match('/Victual\.FullcalendarEventSources = (.*);\R/', $html, $matches), 'The page embeds Victual.FullcalendarEventSources');

		$eventSources = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($eventSources);

		$events = $eventSources[0] ?? [];
		self::assertNotEmpty($events, 'The fixture meal plan entry produces a fullcalendar event');

		$productEvent = null;
		foreach ($events as $event)
		{
			if (($event['productDetails'] ?? 'null') !== 'null')
			{
				$productEvent = $event;
				break;
			}
		}
		self::assertNotNull($productEvent, 'One event carries the fixture product entry\'s productDetails');

		$productDetails = json_decode($productEvent['productDetails'], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($productDetails, 'productDetails decodes to an object/array, not null, for a product meal-plan entry');

		return $productDetails;
	}

	public function testMayViewPricesSeesTheGatedFieldsWithTheirValues(): void
	{
		self::grant('ADMIN');
		$productDetails = self::renderedProductDetails();

		self::assertArrayHasKey('product', $productDetails, 'An ungated field survives');

		foreach (self::gatedFields() as $field)
		{
			self::assertArrayHasKey($field, $productDetails, "ADMIN holds STOCK_PRICES_VIEW, so product_details.$field reaches the page");
		}

		self::assertSame(self::PRICE, $productDetails['last_price'], 'last_price carries the fixture value');
		self::assertSame(self::PRICE, $productDetails['avg_price'], 'avg_price carries the fixture value');
		self::assertSame(self::PRICE, $productDetails['current_price'], 'current_price carries the fixture value');
		self::assertSame(self::PRICE, $productDetails['stock_value'], 'stock_value carries the fixture value (1 unit at the fixture price)');
	}

	public function testMayNotViewPricesDoesNotReceiveTheGatedFields(): void
	{
		self::grant('CHILD');
		$productDetails = self::renderedProductDetails();

		self::assertArrayHasKey('product', $productDetails, 'CHILD still gets the entry - only the gated fields are removed');

		foreach (self::gatedFields() as $field)
		{
			self::assertArrayNotHasKey($field, $productDetails, "CHILD lacks STOCK_PRICES_VIEW, so product_details.$field must not be serialised into the page");
		}
	}
}
