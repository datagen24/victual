<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\RecipesController;
use Victual\Controllers\StockController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The Blade page controllers behind the stock, master data, shopping list, recipe and
 * meal plan routes (StockController, RecipesController), called the way
 * MealPlanRedactionTest calls RecipesController::MealPlan: directly, with a PSR-7
 * request, asserting on the HTML that comes back.
 *
 * Every page is rendered twice - once against a database holding nothing but the
 * migration seed, and once against a fixture set built through the real write paths -
 * and the assertions are on the difference between the two. A page that answered 200
 * with an empty body would pass a bare status check on both renders, so each populated
 * assertion is paired with the same needle checked against the empty render
 * (assertAppears() below). Which rows a page must *not* list is asserted just as
 * explicitly: a filter that lists everything is indistinguishable from a working one
 * unless something is excluded.
 *
 * Price redaction follows .devtools/pgsql/price-visibility-tests.php, which already
 * fixes which pages carry which price figure for which identity: the shopping list, the
 * stock overview and the stock entries page, a currency span present only for a caller
 * resolving to STOCK_PRICES_VIEW, and the stock overview's computed value (price times
 * amount) present in the page source only for that caller. The restricted identity is
 * the seeded CHILD role, which holds STOCK_VIEW and SHOPPINGLIST_VIEW and cannot inherit
 * STOCK_PRICES_VIEW.
 *
 * There is no isolation between test methods (see the harness brief), so the fixtures
 * are built once by testFixturesAreCreated() and every later method declares #[Depends]
 * on it. Each method also sets the identity it needs before rendering, because the
 * refusal and redaction methods leave user_roles pointing somewhere else.
 */
class StockPagesTest extends PgsqlSchemaTestCase
{
	/** Fixture ids, all in one block so nothing collides with the migration seed. */
	private const LOCATION_PARENT = 9600;
	private const LOCATION_CHILD = 9601;
	private const LOCATION_INACTIVE = 9602;
	private const GROUP_PARENT = 9600;
	private const GROUP_CHILD = 9601;
	private const GROUP_INACTIVE = 9602;
	private const STORE = 9600;
	private const STORE_INACTIVE = 9601;
	private const QU = 9600;
	private const QU_INACTIVE = 9601;
	private const PRODUCT_STOCKED = 9600;
	private const PRODUCT_BARE = 9601;
	private const PRODUCT_INACTIVE = 9602;
	private const PRODUCT_DRAINED = 9603;
	private const PRODUCT_OVERDUE = 9604;
	private const PRODUCT_ARCHIVED = 9605;
	private const RECIPE_FULFILLED = 9600;
	private const RECIPE_EMPTY = 9601;
	private const RECIPE_SHORT = 9602;
	private const RECIPE_NESTING = 9603;
	private const SHOPPING_LIST = 9600;
	private const MEALPLAN_SECTION = 9600;

	/** Fixture names, used as the needles the page assertions are built from. */
	private const NAME_LOCATION_PARENT = 'StockPages Pantry';
	private const NAME_LOCATION_CHILD = 'StockPages Shelf';
	private const NAME_LOCATION_INACTIVE = 'StockPages Attic';
	private const NAME_GROUP_PARENT = 'StockPages Group';
	private const NAME_GROUP_CHILD = 'StockPages Subgroup';
	private const NAME_GROUP_INACTIVE = 'StockPages Retired Group';
	private const NAME_STORE = 'StockPages Store';
	private const NAME_STORE_INACTIVE = 'StockPages Closed Store';
	private const NAME_QU = 'StockPages Bushel';
	private const NAME_QU_INACTIVE = 'StockPages Retired Unit';
	private const NAME_PRODUCT_STOCKED = 'StockPages Stocked Product';
	private const NAME_PRODUCT_BARE = 'StockPages Bare Product';
	private const NAME_PRODUCT_INACTIVE = 'StockPages Retired Product';
	private const NAME_PRODUCT_DRAINED = 'StockPages Drained Product';
	private const NAME_PRODUCT_OVERDUE = 'StockPages Overdue Product';
	private const NAME_PRODUCT_ARCHIVED = 'StockPages Archived Product';
	private const NAME_RECIPE_FULFILLED = 'StockPages Feast';
	private const NAME_RECIPE_EMPTY = 'StockPages Blank Recipe';
	private const NAME_RECIPE_SHORT = 'StockPages Short Recipe';
	private const NAME_RECIPE_NESTING = 'StockPages Nested Feast';
	private const NAME_SHOPPING_LIST = 'StockPages List';
	private const NAME_MEALPLAN_SECTION = 'StockPages Brunch';
	private const NAME_BARCODE = 'STOCKPAGES9600';
	private const NAME_PRINTER = 'StockPages Printer';
	private const NAME_NOTE_DEFAULT_LIST = 'StockPages note on the default list';
	private const NAME_NOTE_SECOND_LIST = 'StockPages note on the second list';

	/**
	 * Dates are pinned rather than derived from the clock wherever the page does not
	 * itself select on "today", because an unpinned due date has already flipped a
	 * fixture's meaning with the calendar day in this repository.
	 */
	private const PURCHASED_DATE = '2026-01-01';
	private const DUE_DATE_FUTURE = '2099-12-31';
	private const DUE_DATE_PAST = '2020-01-01';
	/** Inside a thirty-six month journal window and outside the six month default one. */
	private const ARCHIVED_BOOKING_TIMESTAMP = '2025-01-15 12:00:00';

	private static PDO $db;
	private static StockController $stock;
	private static RecipesController $recipes;
	private static int $stockEntryId = 0;
	private static int $drainedEntryId = 0;
	private static int $barcodeId = 0;
	private static int $quConversionId = 0;
	private static int $shoppingListItemId = 0;
	private static int $recipePosId = 0;

	/** @var array<string, string> Page label => the HTML it rendered before any fixture existed. */
	private static array $emptyPages = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockController($container);
		self::$recipes = new RecipesController($container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'stockpages-caller', 'fixture')");
	}

	// --- Identity -----------------------------------------------------------------

	private static function roleId(string $code): int
	{
		$statement = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$statement->execute([$code]);

		return (int)$statement->fetchColumn();
	}

	/** Moves the one fixture caller to hold exactly the given seeded role and nothing else. */
	private static function assumeRole(string $code): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . self::roleId($code) . ')');
	}

	/** Moves the one fixture caller to hold exactly the given direct permission grants. */
	private static function assumeGrants(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	// --- Rendering ----------------------------------------------------------------

	private static function request(array $query = [])
	{
		return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/page')->withQueryParams($query);
	}

	private static function render(object $controller, string $method, array $args = [], array $query = []): string
	{
		$response = $controller->$method(self::request($query), new Response(), $args);
		self::assertSame(200, $response->getStatusCode(), "$method renders");

		return (string)$response->getBody();
	}

	/**
	 * Renders a page that is known to dereference a variable its own controller does not
	 * pass, capturing the resulting PHP diagnostics instead of letting PHPUnit's handler
	 * turn them into a failed run (phpunit.xml sets failOnWarning). The captured messages
	 * are returned so the defect tests below can pin what is actually emitted rather than
	 * quietly swallowing it.
	 *
	 * The mask is what keeps this from swallowing more than the caller meant to pin. It
	 * defaults to E_WARNING, which is all the cases that merely have to get a create form
	 * rendered are ignoring. The two cases that pin the diagnostics as a defect pass E_ALL
	 * and assert the whole captured list, which is the only way to be sure nothing was
	 * swallowed unnamed: PHP hands a diagnostic outside the mask to its own internal
	 * handler rather than to the handler that was installed before this one, so what a
	 * mask leaves out does not reach PHPUnit and failOnWarning never sees it. Measured on
	 * this tree: masking this helper to E_DEPRECATED alone leaves the create form's
	 * warnings failing nothing.
	 *
	 * @param int $mask The diagnostics to capture; anything else is PHP's own to report.
	 * @return array{0: string, 1: string[]}
	 */
	private static function renderCapturingDiagnostics(object $controller, string $method, array $args = [], array $query = [], int $mask = E_WARNING): array
	{
		$diagnostics = [];
		set_error_handler(function (int $severity, string $message) use (&$diagnostics)
		{
			$diagnostics[] = $message;

			return true;
		}, $mask);

		try
		{
			$response = $controller->$method(self::request($query), new Response(), $args);
		}
		finally
		{
			restore_error_handler();
		}

		self::assertSame(200, $response->getStatusCode(), "$method renders despite the diagnostics");

		return [(string)$response->getBody(), $diagnostics];
	}

	private static function stockPage(string $method, array $args = [], array $query = []): string
	{
		return self::render(self::$stock, $method, $args, $query);
	}

	private static function recipesPage(string $method, array $args = [], array $query = []): string
	{
		return self::render(self::$recipes, $method, $args, $query);
	}

	/**
	 * Calling a controller directly bypasses Slim's error middleware, so a refusal
	 * arrives as a thrown HttpException rather than a response (harness brief 3a).
	 */
	private static function expectStatus(callable $work, int $expected, string $message): void
	{
		try
		{
			$actual = $work()->getStatusCode();
		}
		catch (HttpException $exception)
		{
			$actual = $exception->getCode();
		}

		self::assertSame($expected, $actual, "$message: expected $expected, got $actual");
	}

	/**
	 * Asserts a needle is on the populated page AND was absent from the same page's
	 * empty-database render. The second half is the negative control: without it, a
	 * page that happens to print every product name everywhere would satisfy the first.
	 */
	private static function assertAppears(string $label, string $html, string $needle, string $why): void
	{
		self::assertArrayHasKey($label, self::$emptyPages, "the empty render of '$label' was captured");
		self::assertStringNotContainsString($needle, self::$emptyPages[$label], "'$needle' was on $label before the fixture existed, so its presence afterwards proves nothing");
		self::assertStringContainsString($needle, $html, $why);
	}

	// --- Phase 1: every page renders against the migration seed alone ---------------

	/**
	 * The empty-fixture half of every page assertion below. Captured in one method
	 * because it has to run while the database still holds only what
	 * InitialDataSeeder::Seed() wrote - once testFixturesAreCreated() has run there is
	 * no way back to that state within the class.
	 */
	public function testEveryPageRendersOnAnEmptyDatabase(): void
	{
		self::assumeRole('ADMIN');

		$stockPages = [
			'consume' => ['Consume', [], []],
			'inventory' => ['Inventory', [], []],
			'journal' => ['Journal', [], []],
			'journal-36-months' => ['Journal', [], ['months' => '36']],
			'journal-bad-months' => ['Journal', [], ['months' => 'not-a-number']],
			'locationcontentsheet' => ['LocationContentSheet', [], []],
			'locationcontentsheet-all' => ['LocationContentSheet', [], ['include_out_of_stock' => '']],
			'location-new' => ['LocationEditForm', ['locationId' => 'new'], []],
			'locationlabels' => ['LocationLabels', [], []],
			'locations' => ['LocationsList', [], []],
			'locations-disabled' => ['LocationsList', [], ['include_disabled' => '']],
			'overview' => ['Overview', [], []],
			'productgroup-new' => ['ProductGroupEditForm', ['productGroupId' => 'new'], []],
			'productgroups' => ['ProductGroupsList', [], []],
			'productgroups-disabled' => ['ProductGroupsList', [], ['include_disabled' => '']],
			'products' => ['ProductsList', [], []],
			'products-disabled' => ['ProductsList', [], ['include_disabled' => '']],
			'products-in-stock' => ['ProductsList', [], ['filter' => 'only_in_stock']],
			'products-out-of-stock' => ['ProductsList', [], ['filter' => 'only_out_of_stock']],
			'products-unknown-filter' => ['ProductsList', [], ['filter' => 'nonsense']],
			'purchase' => ['Purchase', [], []],
			// quantity unit 2 is "Piece" from the migration seed; the form needs either a
			// product or a unit to name in its heading (see testCreateFormsThatWarn...).
			'quconversion-new' => ['QuantityUnitConversionEditForm', ['quConversionId' => 'new'], ['qu-unit' => '2']],
			'quantityunitpluraltesting' => ['QuantityUnitPluralFormTesting', [], []],
			'quantityunits' => ['QuantityUnitsList', [], []],
			'quantityunits-disabled' => ['QuantityUnitsList', [], ['include_disabled' => '']],
			'shoppinglist' => ['ShoppingList', [], []],
			'shoppinglist-new' => ['ShoppingListEditForm', ['listId' => 'new'], []],
			'shoppinglistitem-new' => ['ShoppingListItemEditForm', ['itemId' => 'new'], []],
			'shoppinglistsettings' => ['ShoppingListSettings', [], []],
			'shoppinglocation-new' => ['ShoppingLocationEditForm', ['shoppingLocationId' => 'new'], []],
			'shoppinglocations' => ['ShoppingLocationsList', [], []],
			'shoppinglocations-disabled' => ['ShoppingLocationsList', [], ['include_disabled' => '']],
			'stocksettings' => ['StockSettings', [], []],
			'stockentries' => ['Stockentries', [], []],
			'transfer' => ['Transfer', [], []],
			'journalsummary' => ['JournalSummary', [], []],
			'quconversionsresolved' => ['QuantityUnitConversionsResolved', [], []],
		];

		foreach ($stockPages as $label => [$method, $args, $query])
		{
			self::$emptyPages[$label] = self::stockPage($method, $args, $query);
		}

		$recipesPages = [
			'mealplan' => ['MealPlan', [], []],
			'recipes' => ['Overview', [], []],
			'recipessettings' => ['RecipesSettings', [], []],
			'mealplansection-new' => ['MealPlanSectionEditForm', ['sectionId' => 'new'], []],
			'mealplansections' => ['MealPlanSectionsList', [], []],
		];

		foreach ($recipesPages as $label => [$method, $args, $query])
		{
			self::$emptyPages[$label] = self::recipesPage($method, $args, $query);
		}

		self::assertCount(count($stockPages) + count($recipesPages), self::$emptyPages);
	}

	/**
	 * A fresh install has no recipes at all, which is the one case
	 * RecipesController::Overview() documents as leaving $selectedRecipe null - the
	 * guard the whole method is built around. Asserted here rather than after the
	 * fixtures, because afterwards a recipe is always preselected.
	 */
	#[Depends('testEveryPageRendersOnAnEmptyDatabase')]
	public function testRecipesOverviewWithoutAnyRecipeRendersAnEmptyPage(): void
	{
		$html = self::$emptyPages['recipes'];

		self::assertStringNotContainsString('StockPages', $html, 'no recipe exists yet, so no fixture name can be on the page');
		self::assertStringContainsString('</html>', $html, 'the page is a complete document even with nothing to select');
	}

	// --- Phase 2: the fixtures ------------------------------------------------------

	/**
	 * Builds the fixture set. Stock is booked through StockService rather than inserted,
	 * so the amounts, values and due dates the pages read are the ones a household's own
	 * purchases would leave behind.
	 */
	#[Depends('testEveryPageRendersOnAnEmptyDatabase')]
	public function testFixturesAreCreated(): void
	{
		self::assumeRole('ADMIN');
		$db = self::$db;

		$db->exec('INSERT INTO locations(id, name) VALUES (' . self::LOCATION_PARENT . ", '" . self::NAME_LOCATION_PARENT . "')");
		$db->exec('INSERT INTO locations(id, name, parent_location_id) VALUES (' . self::LOCATION_CHILD . ", '" . self::NAME_LOCATION_CHILD . "', " . self::LOCATION_PARENT . ')');
		$db->exec('INSERT INTO locations(id, name, active) VALUES (' . self::LOCATION_INACTIVE . ", '" . self::NAME_LOCATION_INACTIVE . "', 0)");

		$db->exec('INSERT INTO product_groups(id, name) VALUES (' . self::GROUP_PARENT . ", '" . self::NAME_GROUP_PARENT . "')");
		$db->exec('INSERT INTO product_groups(id, name, parent_product_group_id) VALUES (' . self::GROUP_CHILD . ", '" . self::NAME_GROUP_CHILD . "', " . self::GROUP_PARENT . ')');
		$db->exec('INSERT INTO product_groups(id, name, active) VALUES (' . self::GROUP_INACTIVE . ", '" . self::NAME_GROUP_INACTIVE . "', 0)");

		$db->exec('INSERT INTO shopping_locations(id, name) VALUES (' . self::STORE . ", '" . self::NAME_STORE . "')");
		$db->exec('INSERT INTO shopping_locations(id, name, active) VALUES (' . self::STORE_INACTIVE . ", '" . self::NAME_STORE_INACTIVE . "', 0)");

		$db->exec('INSERT INTO quantity_units(id, name, name_plural) VALUES (' . self::QU . ", '" . self::NAME_QU . "', '" . self::NAME_QU . "s')");
		$db->exec('INSERT INTO quantity_units(id, name, name_plural, active) VALUES (' . self::QU_INACTIVE . ", '" . self::NAME_QU_INACTIVE . "', '" . self::NAME_QU_INACTIVE . "s', 0)");

		// Quantity unit 2 is "Piece" from the migration seed.
		$product = 'INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock, product_group_id, shopping_location_id, min_stock_amount, active) VALUES (%d, %s, %d, 2, 2, %d, %d, %s, %d)';
		$db->exec(sprintf($product, self::PRODUCT_STOCKED, "'" . self::NAME_PRODUCT_STOCKED . "'", self::LOCATION_PARENT, self::GROUP_PARENT, self::STORE, '0', 1));
		$db->exec(sprintf($product, self::PRODUCT_BARE, "'" . self::NAME_PRODUCT_BARE . "'", self::LOCATION_PARENT, self::GROUP_CHILD, self::STORE, '0', 1));
		$db->exec(sprintf($product, self::PRODUCT_INACTIVE, "'" . self::NAME_PRODUCT_INACTIVE . "'", self::LOCATION_PARENT, self::GROUP_PARENT, self::STORE, '0', 0));
		$db->exec(sprintf($product, self::PRODUCT_DRAINED, "'" . self::NAME_PRODUCT_DRAINED . "'", self::LOCATION_PARENT, self::GROUP_PARENT, self::STORE, '0', 1));
		$db->exec(sprintf($product, self::PRODUCT_OVERDUE, "'" . self::NAME_PRODUCT_OVERDUE . "'", self::LOCATION_CHILD, self::GROUP_PARENT, self::STORE, '0', 1));
		// Created active because StockService refuses to book against an inactive product,
		// then retired below once its booking exists: a page listing it afterwards can only
		// be listing its stock journal rows, since every product picker on these pages is
		// filtered to active products.
		$db->exec(sprintf($product, self::PRODUCT_ARCHIVED, "'" . self::NAME_PRODUCT_ARCHIVED . "'", self::LOCATION_PARENT, self::GROUP_PARENT, self::STORE, '0', 1));

		$stockService = StockService::GetInstance();

		// The same amount and price the price-visibility phase books, so the stock value
		// the overview computes (10 * 3.50 = 35) is the figure that phase already pins.
		$transactionId = null;
		$stockService->AddProduct(self::PRODUCT_STOCKED, 10, self::DUE_DATE_FUTURE, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED_DATE, 3.50, self::LOCATION_PARENT, self::STORE, $transactionId);
		self::$stockEntryId = (int)$db->query('SELECT id FROM stock WHERE product_id = ' . self::PRODUCT_STOCKED . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
		self::assertGreaterThan(0, self::$stockEntryId, 'the fixture purchase left a stock entry behind');

		// Already due when the fixture is built, which is what the overview's "overdue"
		// bucket and the entries page's due colouring branch on.
		$stockService->AddProduct(self::PRODUCT_OVERDUE, 4, self::DUE_DATE_PAST, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED_DATE, 1.25, self::LOCATION_CHILD, self::STORE);

		// Purchased and then wholly consumed: a product that has a stock journal but no
		// stock, which is the boundary every "only in stock" filter has to get right.
		$stockService->AddProduct(self::PRODUCT_DRAINED, 2, self::DUE_DATE_FUTURE, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED_DATE, 0.99, self::LOCATION_PARENT, self::STORE);
		self::$drainedEntryId = (int)$db->query('SELECT id FROM stock WHERE product_id = ' . self::PRODUCT_DRAINED . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
		$stockService->ConsumeProduct(self::PRODUCT_DRAINED, 2, false, StockService::TRANSACTION_TYPE_CONSUME);

		// Bookings older than the journal's six month default window and inside its widest
		// one, on a product left with no stock at all so it appears on no page except the
		// two journals.
		$stockService->AddProduct(self::PRODUCT_ARCHIVED, 1, self::DUE_DATE_FUTURE, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED_DATE, 2.00, self::LOCATION_PARENT, self::STORE);
		$stockService->ConsumeProduct(self::PRODUCT_ARCHIVED, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		$db->exec("UPDATE stock_log SET row_created_timestamp = TIMESTAMP '" . self::ARCHIVED_BOOKING_TIMESTAMP . "' WHERE product_id = " . self::PRODUCT_ARCHIVED);
		$db->exec('UPDATE products SET active = 0 WHERE id = ' . self::PRODUCT_ARCHIVED);

		$db->exec('INSERT INTO product_barcodes(id, product_id, barcode, last_price) VALUES (9600, ' . self::PRODUCT_STOCKED . ", '" . self::NAME_BARCODE . "', 2.75)");
		self::$barcodeId = 9600;

		// Quantity unit 3 is "Pack" from the migration seed; one conversion belongs to the
		// stocked product and one is product independent, which is the split
		// QuantityUnitConversionsResolved() renders one side of at a time.
		$db->exec('INSERT INTO quantity_unit_conversions(id, from_qu_id, to_qu_id, factor, product_id) VALUES (9600, 2, 3, 6, ' . self::PRODUCT_STOCKED . ')');
		$db->exec('INSERT INTO quantity_unit_conversions(id, from_qu_id, to_qu_id, factor, product_id) VALUES (9601, 2, ' . self::QU . ', 12, NULL)');
		self::$quConversionId = 9600;

		$db->exec('INSERT INTO shopping_lists(id, name) VALUES (' . self::SHOPPING_LIST . ", '" . self::NAME_SHOPPING_LIST . "')");
		// Each item carries a note of its own, so "is this item on the page" has an answer
		// that does not also match the page's product picker - every active product's name
		// is in that picker whichever list is being displayed.
		$db->exec('INSERT INTO shopping_list(id, product_id, amount, shopping_list_id, note) VALUES (9600, ' . self::PRODUCT_STOCKED . ", 5, 1, '" . self::NAME_NOTE_DEFAULT_LIST . "')");
		$db->exec('INSERT INTO shopping_list(id, product_id, amount, shopping_list_id, note) VALUES (9601, ' . self::PRODUCT_BARE . ', 3, ' . self::SHOPPING_LIST . ", '" . self::NAME_NOTE_SECOND_LIST . "')");
		self::$shoppingListItemId = 9600;

		$db->exec('INSERT INTO recipes(id, name, base_servings, desired_servings) VALUES (' . self::RECIPE_FULFILLED . ", '" . self::NAME_RECIPE_FULFILLED . "', 1, 1)");
		$db->exec('INSERT INTO recipes(id, name, base_servings, desired_servings) VALUES (' . self::RECIPE_EMPTY . ", '" . self::NAME_RECIPE_EMPTY . "', 1, 1)");
		$db->exec('INSERT INTO recipes(id, name, base_servings, desired_servings) VALUES (' . self::RECIPE_SHORT . ", '" . self::NAME_RECIPE_SHORT . "', 1, 1)");
		$db->exec('INSERT INTO recipes(id, name, base_servings, desired_servings) VALUES (' . self::RECIPE_NESTING . ", '" . self::NAME_RECIPE_NESTING . "', 1, 1)");

		// Fulfilled (one of ten in stock), unfulfillable (the product has never had any)
		// and a recipe with no positions at all - the three fulfillment states the recipe
		// pages have to render without falling over.
		$db->exec('INSERT INTO recipes_pos(id, recipe_id, product_id, amount, qu_id) VALUES (9600, ' . self::RECIPE_FULFILLED . ', ' . self::PRODUCT_STOCKED . ', 1, 2)');
		$db->exec('INSERT INTO recipes_pos(id, recipe_id, product_id, amount, qu_id) VALUES (9601, ' . self::RECIPE_SHORT . ', ' . self::PRODUCT_BARE . ', 7, 2)');
		self::$recipePosId = 9600;

		$db->exec('INSERT INTO recipes_nestings(recipe_id, includes_recipe_id, servings) VALUES (' . self::RECIPE_NESTING . ', ' . self::RECIPE_FULFILLED . ', 1)');

		$db->exec('INSERT INTO meal_plan_sections(id, name, sort_number) VALUES (' . self::MEALPLAN_SECTION . ", '" . self::NAME_MEALPLAN_SECTION . "', 5)");
		$db->exec('INSERT INTO meal_plan(day, type, recipe_id, recipe_servings, section_id) VALUES (CURRENT_DATE, ' . "'recipe', " . self::RECIPE_FULFILLED . ', 1, ' . self::MEALPLAN_SECTION . ')');
		// A product entry as well as a recipe entry: the meal plan resolves product details
		// for the first kind and not the second, and a page that only ever saw recipe
		// entries would never take that path.
		$db->exec('INSERT INTO meal_plan(day, type, product_id, product_amount, product_qu_id, section_id) VALUES (CURRENT_DATE, ' . "'product', " . self::PRODUCT_STOCKED . ', 2, 2, ' . self::MEALPLAN_SECTION . ')');

		// A configured label printer, for the feature-flag-on subprocess below. Inserted
		// directly rather than through PrinterConfigurationService because the pages under
		// test read nothing from it but name, active and is_default.
		$db->exec("INSERT INTO label_workers(id, name, configuration_mode) VALUES (9600, 'StockPages Worker', 'declared')");
		$db->exec("INSERT INTO label_drivers(id, driver_id, schema_version, contract_version, connection_types, discriminator_properties, combination_binding, settings_schemas, capability_document, registered_by_worker_id) VALUES (9600, 'stockpages-driver', '1', 1, '[]'::jsonb, '{}'::jsonb, '{}'::jsonb, '{}'::jsonb, '{}'::jsonb, 9600)");
		$db->exec("INSERT INTO label_printers(id, name, active, is_default, worker_id, driver_id, driver_schema_version, connection, connection_type, model, settings, settings_validated_at) VALUES (9600, '" . self::NAME_PRINTER . "', 1, 0, 9600, 'stockpages-driver', '1', 'tcp://localhost:9100', 'network', 'fixture', '{}'::jsonb, CURRENT_TIMESTAMP)");

		self::assertSame(6, (int)$db->query("SELECT count(*) FROM products WHERE name LIKE 'StockPages %'")->fetchColumn(), 'six fixture products exist');
		self::assertSame(4, (int)$db->query("SELECT count(*) FROM recipes WHERE name LIKE 'StockPages %'")->fetchColumn(), 'four fixture recipes exist');
	}

	// --- Phase 3: stock transaction pages ------------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testConsumeOffersOnlyProductsThatCurrentlyHaveStock(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('Consume');

		self::assertAppears('consume', $html, self::NAME_PRODUCT_STOCKED, 'the consume page offers a product that is in stock');
		self::assertStringNotContainsString(self::NAME_PRODUCT_BARE, $html, 'a product that never had stock cannot be consumed');
		self::assertStringNotContainsString(self::NAME_PRODUCT_DRAINED, $html, 'a product whose stock was fully consumed cannot be consumed again');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $html, 'an inactive product is not offered');
	}

	#[Depends('testFixturesAreCreated')]
	public function testInventoryOffersEveryActiveProductWithOwnStock(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('Inventory');

		self::assertAppears('inventory', $html, self::NAME_PRODUCT_BARE, 'stocktaking has to reach a product that has no stock - that is what it is for');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'a stocked product is offered too');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $html, 'an inactive product is not offered for stocktaking');
	}

	#[Depends('testFixturesAreCreated')]
	public function testPurchaseOffersActiveProductsAndActiveStoresOnly(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('Purchase');

		self::assertAppears('purchase', $html, self::NAME_PRODUCT_BARE, 'anything active can be purchased, stocked or not');
		self::assertAppears('purchase', $html, self::NAME_STORE, 'the store picker offers the active store');
		self::assertStringNotContainsString(self::NAME_STORE_INACTIVE, $html, 'an inactive store is not offered');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $html, 'an inactive product is not offered');
	}

	#[Depends('testFixturesAreCreated')]
	public function testTransferOffersOnlyStockedProductsAndActiveLocations(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('Transfer');

		self::assertAppears('transfer', $html, self::NAME_PRODUCT_STOCKED, 'only something in stock can be moved');
		self::assertStringNotContainsString(self::NAME_PRODUCT_BARE, $html, 'a product with no stock has nothing to transfer');
		self::assertStringNotContainsString(self::NAME_LOCATION_INACTIVE, $html, 'an inactive location is not a transfer target');
	}

	// --- Phase 3: stock overview and entries ---------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testOverviewListsStockedProductsAndHidesOnesWithNoStock(): void
	{
		self::assumeRole('ADMIN');
		self::$db->exec("DELETE FROM user_settings WHERE user_id = 9000 AND key = 'stock_overview_show_all_out_of_stock_products'");

		$html = self::stockPage('Overview');

		self::assertAppears('overview', $html, self::NAME_PRODUCT_STOCKED, 'a stocked product is on the overview');
		self::assertStringContainsString(self::NAME_PRODUCT_OVERDUE, $html, 'an overdue product is still in stock and still listed');
		self::assertStringNotContainsString(self::NAME_PRODUCT_DRAINED, $html, 'with the default setting a product at zero stock and no minimum is left off');
	}

	/**
	 * stock_overview_show_all_out_of_stock_products is the one page setting on these
	 * controllers that can be flipped from a test without redefining a constant, and the
	 * branch it selects is the difference between "what is in stock" and "everything".
	 */
	#[Depends('testFixturesAreCreated')]
	public function testOverviewShowsEverythingWhenTheUserSettingIsOn(): void
	{
		self::assumeRole('ADMIN');
		self::$db->exec("INSERT INTO user_settings(user_id, key, value) VALUES (9000, 'stock_overview_show_all_out_of_stock_products', '1')");

		try
		{
			$html = self::stockPage('Overview');

			self::assertStringContainsString(self::NAME_PRODUCT_DRAINED, $html, 'with the setting on, a product at zero stock is listed');
			self::assertStringContainsString(self::NAME_PRODUCT_BARE, $html, 'with the setting on, a product that never had stock is listed');
			self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $html, 'the setting widens the stock filter, not the active filter');
		}
		finally
		{
			self::$db->exec("DELETE FROM user_settings WHERE user_id = 9000 AND key = 'stock_overview_show_all_out_of_stock_products'");
		}
	}

	#[Depends('testFixturesAreCreated')]
	public function testStockEntriesListsOneRowPerEntryIncludingAnOverdueOne(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('Stockentries');

		self::assertAppears('stockentries', $html, self::NAME_PRODUCT_STOCKED, 'the entries page lists the purchased entry');
		self::assertStringContainsString(self::NAME_PRODUCT_OVERDUE, $html, 'an entry whose due date has passed is still an entry');
		self::assertStringNotContainsString(self::NAME_PRODUCT_ARCHIVED, $html, 'a product whose every entry was consumed has nothing to list');
	}

	#[Depends('testFixturesAreCreated')]
	public function testLocationContentSheetListsStockPerLocationAndOptionallyEmptyProducts(): void
	{
		self::assumeRole('ADMIN');

		$stocked = self::stockPage('LocationContentSheet');
		self::assertAppears('locationcontentsheet', $stocked, self::NAME_PRODUCT_STOCKED, 'the sheet lists what is actually at a location');
		self::assertStringNotContainsString(self::NAME_PRODUCT_BARE, $stocked, 'by default a product with no stock is not on the sheet');

		$all = self::stockPage('LocationContentSheet', [], ['include_out_of_stock' => '']);
		self::assertAppears('locationcontentsheet-all', $all, self::NAME_PRODUCT_BARE, 'include_out_of_stock adds the products with nothing at any location');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $all, 'and keeps the ones that do have stock');
	}

	// --- Phase 3: journal -----------------------------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testJournalWindowDefaultsToSixMonthsAndWidensOnRequest(): void
	{
		self::assumeRole('ADMIN');

		$recent = self::stockPage('Journal');
		self::assertAppears('journal', $recent, self::NAME_PRODUCT_STOCKED, 'a booking made now is inside the default window');
		self::assertStringNotContainsString(self::NAME_PRODUCT_ARCHIVED, $recent, 'a booking older than six months is outside the default window');

		$wide = self::stockPage('Journal', [], ['months' => '36']);
		self::assertAppears('journal-36-months', $wide, self::NAME_PRODUCT_ARCHIVED, 'months=36 reaches the older booking');
	}

	/**
	 * months is documented as an int; anything else has to fall back to the six month
	 * default rather than reach the query, which is what FILTER_VALIDATE_INT is for.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testJournalIgnoresANonNumericMonthsParameter(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('Journal', [], ['months' => 'not-a-number']);

		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'the recent booking is listed, so the default window was used');
		self::assertStringNotContainsString(self::NAME_PRODUCT_ARCHIVED, $html, 'the window was not widened by an unparseable value');
	}

	#[Depends('testFixturesAreCreated')]
	public function testJournalProductFilterNarrowsToOneProduct(): void
	{
		self::assumeRole('ADMIN');

		$mine = self::stockPage('Journal', [], ['months' => '36', 'product' => (string)self::PRODUCT_ARCHIVED]);
		self::assertStringContainsString(self::NAME_PRODUCT_ARCHIVED, $mine, 'the filtered product is listed');

		$other = self::stockPage('Journal', [], ['months' => '36', 'product' => (string)self::PRODUCT_STOCKED]);
		self::assertStringNotContainsString(self::NAME_PRODUCT_ARCHIVED, $other, 'filtering by a different product removes it');
	}

	#[Depends('testFixturesAreCreated')]
	public function testJournalSummaryAggregatesAndFilters(): void
	{
		self::assumeRole('ADMIN');

		$all = self::stockPage('JournalSummary');
		self::assertAppears('journalsummary', $all, self::NAME_PRODUCT_ARCHIVED, 'the summary is not windowed by date, so the old booking is in it');

		$byProduct = self::stockPage('JournalSummary', [], ['product_id' => (string)self::PRODUCT_STOCKED]);
		self::assertStringNotContainsString(self::NAME_PRODUCT_ARCHIVED, $byProduct, 'the product filter excludes every other product');

		$purchases = self::stockPage('JournalSummary', [], ['transaction_type' => StockService::TRANSACTION_TYPE_PURCHASE]);
		self::assertStringContainsString(self::NAME_PRODUCT_ARCHIVED, $purchases, 'the archived product was purchased, so it is in the purchase summary');

		$corrections = self::stockPage('JournalSummary', [], ['transaction_type' => StockService::TRANSACTION_TYPE_INVENTORY_CORRECTION]);
		self::assertStringNotContainsString(self::NAME_PRODUCT_ARCHIVED, $corrections, 'and not in a summary of a transaction type it never had');

		$byUser = self::stockPage('JournalSummary', [], ['user_id' => '9000']);
		self::assertStringContainsString(self::NAME_PRODUCT_ARCHIVED, $byUser, 'the fixture caller booked it, so filtering by that user keeps it');

		$byOtherUser = self::stockPage('JournalSummary', [], ['user_id' => '1']);
		self::assertStringNotContainsString(self::NAME_PRODUCT_ARCHIVED, $byOtherUser, 'the seeded admin booked nothing, so filtering by it drops everything');
	}

	// --- Phase 3: master data lists --------------------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testLocationsListHidesInactiveLocationsUnlessAsked(): void
	{
		self::assumeRole('ADMIN');

		$active = self::stockPage('LocationsList');
		self::assertAppears('locations', $active, self::NAME_LOCATION_PARENT, 'an active location is listed');
		self::assertStringNotContainsString(self::NAME_LOCATION_INACTIVE, $active, 'an inactive location is hidden by default');

		$all = self::stockPage('LocationsList', [], ['include_disabled' => '']);
		self::assertAppears('locations-disabled', $all, self::NAME_LOCATION_INACTIVE, 'include_disabled lists it');
	}

	#[Depends('testFixturesAreCreated')]
	public function testProductGroupsListHidesInactiveGroupsUnlessAsked(): void
	{
		self::assumeRole('ADMIN');

		$active = self::stockPage('ProductGroupsList');
		self::assertAppears('productgroups', $active, self::NAME_GROUP_CHILD, 'an active group is listed');
		self::assertStringNotContainsString(self::NAME_GROUP_INACTIVE, $active, 'an inactive group is hidden by default');

		$all = self::stockPage('ProductGroupsList', [], ['include_disabled' => '']);
		self::assertAppears('productgroups-disabled', $all, self::NAME_GROUP_INACTIVE, 'include_disabled lists it');
	}

	#[Depends('testFixturesAreCreated')]
	public function testQuantityUnitsListHidesInactiveUnitsUnlessAsked(): void
	{
		self::assumeRole('ADMIN');

		$active = self::stockPage('QuantityUnitsList');
		self::assertAppears('quantityunits', $active, self::NAME_QU, 'an active unit is listed');
		self::assertStringNotContainsString(self::NAME_QU_INACTIVE, $active, 'an inactive unit is hidden by default');

		$all = self::stockPage('QuantityUnitsList', [], ['include_disabled' => '']);
		self::assertAppears('quantityunits-disabled', $all, self::NAME_QU_INACTIVE, 'include_disabled lists it');
	}

	#[Depends('testFixturesAreCreated')]
	public function testStoresListHidesInactiveStoresUnlessAsked(): void
	{
		self::assumeRole('ADMIN');

		$active = self::stockPage('ShoppingLocationsList');
		self::assertAppears('shoppinglocations', $active, self::NAME_STORE, 'an active store is listed');
		self::assertStringNotContainsString(self::NAME_STORE_INACTIVE, $active, 'an inactive store is hidden by default');

		$all = self::stockPage('ShoppingLocationsList', [], ['include_disabled' => '']);
		self::assertAppears('shoppinglocations-disabled', $all, self::NAME_STORE_INACTIVE, 'include_disabled lists it');
	}

	#[Depends('testFixturesAreCreated')]
	public function testProductsListHonoursTheDisabledAndStockFilters(): void
	{
		self::assumeRole('ADMIN');

		$active = self::stockPage('ProductsList');
		self::assertAppears('products', $active, self::NAME_PRODUCT_BARE, 'an active product is listed');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $active, 'an inactive product is hidden by default');

		$all = self::stockPage('ProductsList', [], ['include_disabled' => '']);
		self::assertAppears('products-disabled', $all, self::NAME_PRODUCT_INACTIVE, 'include_disabled lists it');

		$inStock = self::stockPage('ProductsList', [], ['filter' => 'only_in_stock']);
		self::assertAppears('products-in-stock', $inStock, self::NAME_PRODUCT_STOCKED, 'only_in_stock keeps a stocked product');
		self::assertStringNotContainsString(self::NAME_PRODUCT_BARE, $inStock, 'only_in_stock drops a product with no stock');

		$outOfStock = self::stockPage('ProductsList', [], ['filter' => 'only_out_of_stock']);
		self::assertAppears('products-out-of-stock', $outOfStock, self::NAME_PRODUCT_BARE, 'only_out_of_stock keeps a product with no stock');
		self::assertStringNotContainsString(self::NAME_PRODUCT_STOCKED, $outOfStock, 'only_out_of_stock drops a stocked product');
	}

	/**
	 * filter is documented as taking two values. Anything else is neither of them, and
	 * the page has to fall back to listing everything rather than to listing nothing.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testProductsListIgnoresAnUnknownFilterValue(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('ProductsList', [], ['filter' => 'nonsense']);

		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'an unknown filter does not remove stocked products');
		self::assertStringContainsString(self::NAME_PRODUCT_BARE, $html, 'an unknown filter does not remove unstocked products either');
	}

	// --- Phase 3: edit forms ---------------------------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testLocationEditFormOffersEveryParentExceptItselfAndItsSubtree(): void
	{
		self::assumeRole('ADMIN');

		$create = self::stockPage('LocationEditForm', ['locationId' => 'new']);
		self::assertAppears('location-new', $create, self::NAME_LOCATION_PARENT, 'the create form offers the new fixture location as a parent');
		self::assertStringContainsString(self::NAME_LOCATION_INACTIVE, $create, 'an inactive location is still offered, so an existing parent is never silently dropped');

		$edit = self::stockPage('LocationEditForm', ['locationId' => (string)self::LOCATION_PARENT]);
		self::assertStringContainsString(self::NAME_LOCATION_INACTIVE, $edit, 'unrelated locations stay on offer');
		self::assertStringNotContainsString(self::NAME_LOCATION_CHILD, $edit, 'a location cannot be moved under its own child');
	}

	#[Depends('testFixturesAreCreated')]
	public function testProductGroupEditFormOffersEveryParentExceptItselfAndItsSubtree(): void
	{
		self::assumeRole('ADMIN');

		$create = self::stockPage('ProductGroupEditForm', ['productGroupId' => 'new']);
		self::assertAppears('productgroup-new', $create, self::NAME_GROUP_PARENT, 'the create form offers the fixture group as a parent');

		$edit = self::stockPage('ProductGroupEditForm', ['productGroupId' => (string)self::GROUP_PARENT]);
		self::assertStringContainsString(self::NAME_GROUP_INACTIVE, $edit, 'unrelated groups stay on offer');
		self::assertStringNotContainsString(self::NAME_GROUP_CHILD, $edit, 'a group cannot be moved under its own child');
	}

	#[Depends('testFixturesAreCreated')]
	public function testProductEditFormRendersCreateAndEditModes(): void
	{
		self::assumeRole('ADMIN');

		// The create form is rendered through the capturing helper because it currently
		// emits PHP warnings; testCreateFormsDereferenceVariablesTheyWereNotGiven() below
		// is where that is pinned as a defect.
		[$create] = self::renderCapturingDiagnostics(self::$stock, 'ProductEditForm', ['productId' => 'new']);
		self::assertStringContainsString(self::NAME_LOCATION_PARENT, $create, 'the create form offers the fixture location');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $create, 'an inactive product is not offered as a parent product');

		$edit = self::stockPage('ProductEditForm', ['productId' => (string)self::PRODUCT_STOCKED]);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $edit, 'the edit form carries the product being edited');
		self::assertStringContainsString(self::NAME_BARCODE, $edit, 'and its barcode');
		self::assertStringContainsString(self::NAME_PRODUCT_BARE, $edit, 'another root product is offered as a possible parent');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $edit, 'an inactive product is not');
	}

	#[Depends('testFixturesAreCreated')]
	public function testProductBarcodeEditFormRendersBothModesAndThePreselectedProduct(): void
	{
		self::assumeRole('ADMIN');

		$create = self::stockPage('ProductBarcodesEditForm', ['productBarcodeId' => 'new'], ['product' => (string)self::PRODUCT_STOCKED]);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $create, 'the product query parameter preselects the product');
		self::assertStringNotContainsString(self::NAME_PRODUCT_BARE, $create, 'and only that product - the form is per product');
		self::assertStringNotContainsString(self::NAME_BARCODE, $create, 'a new barcode is not prefilled with an existing one');

		$edit = self::stockPage('ProductBarcodesEditForm', ['productBarcodeId' => (string)self::$barcodeId], ['product' => (string)self::PRODUCT_STOCKED]);
		self::assertStringContainsString(self::NAME_BARCODE, $edit, 'the edit form carries the barcode being edited');
	}

	#[Depends('testFixturesAreCreated')]
	public function testProductSubstitutionFormNeedsAnExistingProduct(): void
	{
		self::assumeRole('ADMIN');

		$html = self::stockPage('ProductSubstitutionEditForm', [], ['product' => (string)self::PRODUCT_STOCKED]);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'the form names the product the edge is created for');
		self::assertStringContainsString(self::NAME_PRODUCT_BARE, $html, 'and offers another active product as the other side');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $html, 'an inactive product is not offered as a substitute');

		$other = self::stockPage('ProductSubstitutionEditForm', [], ['product' => (string)self::PRODUCT_STOCKED, 'direction' => 'other']);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $other, 'the other direction renders the same product');

		self::expectStatus(
			fn () => self::stockPage('ProductSubstitutionEditForm'),
			404,
			'the documented required product parameter is absent'
		);
		self::expectStatus(
			fn () => self::stockPage('ProductSubstitutionEditForm', [], ['product' => '987654']),
			404,
			'the product parameter names a product that does not exist'
		);
	}

	#[Depends('testFixturesAreCreated')]
	public function testQuantityUnitEditFormRendersBothModes(): void
	{
		self::assumeRole('ADMIN');

		[$create] = self::renderCapturingDiagnostics(self::$stock, 'QuantityUnitEditForm', ['quantityunitId' => 'new']);
		self::assertStringContainsString('</html>', $create, 'the create form is a complete page');
		self::assertStringNotContainsString(self::NAME_QU, $create, 'nothing is being edited, so no unit is named');

		$edit = self::stockPage('QuantityUnitEditForm', ['quantityunitId' => (string)self::QU]);
		self::assertStringContainsString(self::NAME_QU, $edit, 'the edit form carries the unit being edited');
	}

	#[Depends('testFixturesAreCreated')]
	public function testQuantityUnitConversionEditFormRendersBothModesAndItsPreselections(): void
	{
		self::assumeRole('ADMIN');

		$create = self::stockPage('QuantityUnitConversionEditForm', ['quConversionId' => 'new'], ['product' => (string)self::PRODUCT_STOCKED, 'qu-unit' => (string)self::QU]);
		self::assertAppears('quconversion-new', $create, self::NAME_PRODUCT_STOCKED, 'the product query parameter preselects the product');
		self::assertStringContainsString(self::NAME_QU, $create, 'the qu-unit query parameter preselects the unit');

		// Both edit-mode calls carry one of the two heading parameters, because the form
		// names either the product it overrides or the unit it defaults for and every link
		// to it in the templates supplies one - see the note in the hand-back about the
		// missing guard when neither is given.
		$edit = self::stockPage('QuantityUnitConversionEditForm', ['quConversionId' => (string)self::$quConversionId], ['product' => (string)self::PRODUCT_STOCKED]);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $edit, 'the edit form names the product the override belongs to');

		$forUnit = self::stockPage('QuantityUnitConversionEditForm', ['quConversionId' => (string)self::$quConversionId], ['qu-unit' => (string)self::QU]);
		self::assertStringContainsString(self::NAME_QU, $forUnit, 'without a product the form names the quantity unit instead');
		self::assertStringNotContainsString(self::NAME_PRODUCT_STOCKED, $forUnit, 'and names no product at all');
	}

	#[Depends('testFixturesAreCreated')]
	public function testQuantityUnitConversionsResolvedSplitsProductAndGlobalConversions(): void
	{
		self::assumeRole('ADMIN');

		$global = self::stockPage('QuantityUnitConversionsResolved');
		self::assertAppears('quconversionsresolved', $global, self::NAME_QU, 'a product independent conversion into the fixture unit is listed');
		self::assertStringNotContainsString(self::NAME_PRODUCT_STOCKED, $global, 'no product is named when none was asked for');

		$perProduct = self::stockPage('QuantityUnitConversionsResolved', [], ['product' => (string)self::PRODUCT_STOCKED]);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $perProduct, 'the page names the product its conversions belong to');
	}

	#[Depends('testFixturesAreCreated')]
	public function testStoreEditFormRendersBothModes(): void
	{
		self::assumeRole('ADMIN');

		$create = self::stockPage('ShoppingLocationEditForm', ['shoppingLocationId' => 'new']);
		self::assertStringNotContainsString(self::NAME_STORE, $create, 'the create form prefills nothing from an existing store');

		$edit = self::stockPage('ShoppingLocationEditForm', ['shoppingLocationId' => (string)self::STORE]);
		self::assertAppears('shoppinglocation-new', $edit, self::NAME_STORE, 'the edit form carries the store being edited');
	}

	#[Depends('testFixturesAreCreated')]
	public function testStockEntryEditFormCarriesTheEntryBeingEdited(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('StockEntryEditForm', ['entryId' => (string)self::$stockEntryId]);

		// The form identifies the entry through the three values its JavaScript saves
		// against; the product's own name is only rendered by the label widget, which is
		// off unless VICTUAL_FEATURE_FLAG_LABELS is set.
		self::assertStringContainsString('Victual.EditObjectRowId = ' . self::$stockEntryId . ';', $html, 'the form is bound to the entry that was asked for');
		self::assertStringContainsString('Victual.EditObjectProductId = ' . self::PRODUCT_STOCKED . ';', $html, 'and to the product that entry holds');
		self::assertStringNotContainsString('Victual.EditObjectRowId = ' . self::$drainedEntryId . ';', $html, 'and not to some other entry');
		self::assertAppears('stockentries', $html, self::NAME_STORE, 'the store picker offers the fixture store');
		self::assertStringNotContainsString(self::NAME_LOCATION_INACTIVE, $html, 'an inactive location is not offered as the entry location');
	}

	// --- Phase 3: shopping list -------------------------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testShoppingListShowsTheSelectedListOnly(): void
	{
		self::assumeRole('ADMIN');

		$default = self::stockPage('ShoppingList');
		self::assertAppears('shoppinglist', $default, self::NAME_NOTE_DEFAULT_LIST, 'list 1 is the default, so its item is the one displayed');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $default, 'and that item names its product');
		self::assertStringNotContainsString(self::NAME_NOTE_SECOND_LIST, $default, 'an item on another list is not on this one');

		$second = self::stockPage('ShoppingList', [], ['list' => (string)self::SHOPPING_LIST]);
		self::assertStringContainsString(self::NAME_SHOPPING_LIST, $second, 'the requested list is named on the page');
		self::assertStringContainsString(self::NAME_NOTE_SECOND_LIST, $second, 'and its own item is listed');
		self::assertStringNotContainsString(self::NAME_NOTE_DEFAULT_LIST, $second, "while list 1's item is not");
	}

	#[Depends('testFixturesAreCreated')]
	public function testShoppingListEditFormRendersBothModes(): void
	{
		self::assumeRole('ADMIN');

		$create = self::stockPage('ShoppingListEditForm', ['listId' => 'new']);
		self::assertStringNotContainsString(self::NAME_SHOPPING_LIST, $create, 'the create form prefills nothing from an existing list');

		$edit = self::stockPage('ShoppingListEditForm', ['listId' => (string)self::SHOPPING_LIST]);
		self::assertAppears('shoppinglist-new', $edit, self::NAME_SHOPPING_LIST, 'the edit form carries the list being edited');
	}

	#[Depends('testFixturesAreCreated')]
	public function testShoppingListItemEditFormRendersBothModes(): void
	{
		self::assumeRole('ADMIN');

		$create = self::stockPage('ShoppingListItemEditForm', ['itemId' => 'new']);
		self::assertAppears('shoppinglistitem-new', $create, self::NAME_SHOPPING_LIST, 'the create form offers every list');

		$edit = self::stockPage('ShoppingListItemEditForm', ['itemId' => (string)self::$shoppingListItemId]);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $edit, 'the edit form carries the item being edited');
	}

	#[Depends('testFixturesAreCreated')]
	public function testShoppingListSettingsListsEveryList(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('ShoppingListSettings');

		self::assertAppears('shoppinglistsettings', $html, self::NAME_SHOPPING_LIST, 'the settings page lists the new list');
	}

	// --- Phase 3: remaining simple stock pages ----------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testStockSettingsOffersActiveMasterDataOnly(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('StockSettings');

		self::assertAppears('stocksettings', $html, self::NAME_LOCATION_PARENT, 'the default location picker offers the fixture location');
		self::assertStringContainsString(self::NAME_GROUP_PARENT, $html, 'and the default product group picker the fixture group');
		self::assertStringNotContainsString(self::NAME_LOCATION_INACTIVE, $html, 'an inactive location is not a default');
		self::assertStringNotContainsString(self::NAME_QU_INACTIVE, $html, 'an inactive quantity unit is not a default');
	}

	#[Depends('testFixturesAreCreated')]
	public function testQuantityUnitPluralTestingOffersActiveUnitsOnly(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('QuantityUnitPluralFormTesting');

		self::assertAppears('quantityunitpluraltesting', $html, self::NAME_QU, 'the active fixture unit can be tested');
		self::assertStringNotContainsString(self::NAME_QU_INACTIVE, $html, 'an inactive unit is not offered');
	}

	#[Depends('testFixturesAreCreated')]
	public function testLocationLabelsRenders(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('LocationLabels');

		self::assertStringContainsString('</html>', $html, 'the stateless scanner page is a complete document');
		self::assertStringNotContainsString(self::NAME_LOCATION_PARENT, $html, 'it is stateless: it reads no location rows to render');
	}

	#[Depends('testFixturesAreCreated')]
	public function testStockEntryLabelPageNamesTheProductTheEntryHolds(): void
	{
		self::assumeRole('ADMIN');
		$html = self::stockPage('StockEntryGrocycodeLabel', ['entryId' => (string)self::$stockEntryId]);

		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'the printable label names the product');
	}

	#[Depends('testFixturesAreCreated')]
	public function testGrocycodeImagesAreServedAsPng(): void
	{
		self::assumeRole('ADMIN');

		$product = self::$stock->ProductGrocycodeImage(self::request(), new Response(), ['productId' => (string)self::PRODUCT_STOCKED]);
		self::assertSame('image/png', $product->getHeaderLine('Content-Type'), 'a product Grocycode is a PNG');
		self::assertNotSame('', (string)$product->getBody(), 'and it has a body');

		$entry = self::$stock->StockEntryGrocycodeImage(self::request(), new Response(), ['entryId' => (string)self::$stockEntryId]);
		self::assertSame('image/png', $entry->getHeaderLine('Content-Type'), 'a stock entry Grocycode is a PNG');

		$recipe = self::$recipes->RecipeGrocycodeImage(self::request(), new Response(), ['recipeId' => (string)self::RECIPE_FULFILLED]);
		self::assertSame('image/png', $recipe->getHeaderLine('Content-Type'), 'a recipe Grocycode is a PNG');

		// download=1 serves the same image as an attachment instead of inline.
		$download = self::$stock->ProductGrocycodeImage(self::request(['download' => '1']), new Response(), ['productId' => (string)self::PRODUCT_STOCKED]);
		self::assertSame('application/octet-stream', $download->getHeaderLine('Content-Type'), 'the download variant is an attachment');
	}

	// --- Phase 4: recipe and meal plan pages ------------------------------------------

	#[Depends('testFixturesAreCreated')]
	public function testRecipesOverviewPreselectsTheFirstRecipeAndRendersItsPositions(): void
	{
		self::assumeRole('ADMIN');
		$html = self::recipesPage('Overview');

		self::assertAppears('recipes', $html, self::NAME_RECIPE_FULFILLED, 'the recipe list carries the fixture recipe');
		self::assertStringContainsString(self::NAME_RECIPE_EMPTY, $html, 'a recipe with no ingredients is still listed');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'the preselected recipe names its ingredient');
	}

	#[Depends('testFixturesAreCreated')]
	public function testRecipesOverviewSelectsTheRequestedRecipe(): void
	{
		self::assumeRole('ADMIN');

		$short = self::recipesPage('Overview', [], ['recipe' => (string)self::RECIPE_SHORT]);
		self::assertStringContainsString(self::NAME_PRODUCT_BARE, $short, 'the selected recipe names the ingredient it cannot fulfil');

		$empty = self::recipesPage('Overview', [], ['recipe' => (string)self::RECIPE_EMPTY]);
		self::assertStringContainsString(self::NAME_RECIPE_EMPTY, $empty, 'a recipe with no positions still renders');
	}

	/**
	 * A recipe including another one is the branch that resolves sub recipe positions
	 * against the parent's own resolved amounts.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testRecipesOverviewResolvesASubRecipesPositions(): void
	{
		self::assumeRole('ADMIN');
		$html = self::recipesPage('Overview', [], ['recipe' => (string)self::RECIPE_NESTING]);

		self::assertStringContainsString(self::NAME_RECIPE_FULFILLED, $html, 'the included recipe is named');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'and its ingredient is resolved into the parent');
	}

	/**
	 * The documented second case for the null guard: the recipe parameter names an id
	 * that does not exist. The page has to render with nothing selected rather than
	 * dereference null.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testRecipesOverviewWithAnUnknownRecipeIdRendersWithNothingSelected(): void
	{
		self::assumeRole('ADMIN');
		$html = self::recipesPage('Overview', [], ['recipe' => '987654']);

		self::assertStringContainsString(self::NAME_RECIPE_FULFILLED, $html, 'the recipe list is still rendered');
		self::assertStringContainsString('</html>', $html, 'the page is complete');
	}

	#[Depends('testFixturesAreCreated')]
	public function testRecipeEditFormRendersBothModes(): void
	{
		self::assumeRole('ADMIN');

		$edit = self::recipesPage('RecipeEditForm', ['recipeId' => (string)self::RECIPE_FULFILLED]);
		self::assertStringContainsString(self::NAME_RECIPE_FULFILLED, $edit, 'the edit form carries the recipe being edited');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $edit, 'and its existing ingredient');
		self::assertStringContainsString(self::NAME_RECIPE_EMPTY, $edit, 'other recipes are on offer to nest into this one');

		$empty = self::recipesPage('RecipeEditForm', ['recipeId' => (string)self::RECIPE_EMPTY]);
		self::assertStringContainsString(self::NAME_RECIPE_EMPTY, $empty, 'a recipe with no ingredients still renders its form');
	}

	#[Depends('testFixturesAreCreated')]
	public function testRecipePositionEditFormRendersBothModes(): void
	{
		self::assumeRole('ADMIN');

		$create = self::recipesPage('RecipePosEditForm', ['recipeId' => (string)self::RECIPE_FULFILLED, 'recipePosId' => 'new']);
		self::assertStringContainsString(self::NAME_RECIPE_FULFILLED, $create, 'the create form names the recipe the ingredient is added to');
		self::assertStringNotContainsString(self::NAME_PRODUCT_INACTIVE, $create, 'an inactive product is not offered as a new ingredient');

		$edit = self::recipesPage('RecipePosEditForm', ['recipeId' => (string)self::RECIPE_FULFILLED, 'recipePosId' => (string)self::$recipePosId]);
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $edit, 'the edit form carries the ingredient being edited');
	}

	#[Depends('testFixturesAreCreated')]
	public function testRecipesSettingsRenders(): void
	{
		self::assumeRole('ADMIN');
		$html = self::recipesPage('RecipesSettings');

		self::assertStringContainsString('</html>', $html, 'the settings page is a complete document');
		self::assertStringNotContainsString(self::NAME_RECIPE_FULFILLED, $html, 'it carries settings, not recipe rows');
	}

	#[Depends('testFixturesAreCreated')]
	public function testMealPlanListsTheEntriesInsideTheRequestedWindow(): void
	{
		self::assumeRole('ADMIN');
		$html = self::recipesPage('MealPlan');

		self::assertAppears('mealplan', $html, self::NAME_RECIPE_FULFILLED, "the entry on today's plan is in the default window");
		self::assertStringContainsString(self::NAME_MEALPLAN_SECTION, $html, 'and the section it was filed under');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $html, 'a product entry resolves the product it plans');
	}

	/**
	 * start and days are documented as moving and sizing the window. A window a year
	 * away from the only entry has to come back without it - otherwise neither parameter
	 * is doing anything.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testMealPlanWindowMovesWithStartAndDays(): void
	{
		self::assumeRole('ADMIN');

		$farAway = self::recipesPage('MealPlan', [], ['start' => '2031-06-15', 'days' => '3']);
		self::assertStringNotContainsString('"title":"' . self::NAME_RECIPE_FULFILLED . '"', $farAway, 'no meal plan entry falls in a window five years out');

		$wide = self::recipesPage('MealPlan', [], ['start' => date('Y-m-d'), 'days' => '30']);
		self::assertStringContainsString('"title":"' . self::NAME_RECIPE_FULFILLED . '"', $wide, 'a thirty day window around today contains the entry');

		// Neither parameter is trusted: a non-ISO start and a non-integer days both fall
		// back to today and six days, which is the window the entry is in.
		$junk = self::recipesPage('MealPlan', [], ['start' => 'not-a-date', 'days' => 'not-a-number']);
		self::assertStringContainsString('"title":"' . self::NAME_RECIPE_FULFILLED . '"', $junk, 'unparseable parameters fall back to the default window');
	}

	#[Depends('testFixturesAreCreated')]
	public function testMealPlanSectionPagesRenderTheFixtureSection(): void
	{
		self::assumeRole('ADMIN');

		$list = self::recipesPage('MealPlanSectionsList');
		self::assertAppears('mealplansections', $list, self::NAME_MEALPLAN_SECTION, 'the sections list carries the fixture section');

		$create = self::recipesPage('MealPlanSectionEditForm', ['sectionId' => 'new']);
		self::assertStringNotContainsString(self::NAME_MEALPLAN_SECTION, $create, 'the create form prefills nothing from an existing section');

		$edit = self::recipesPage('MealPlanSectionEditForm', ['sectionId' => (string)self::MEALPLAN_SECTION]);
		self::assertAppears('mealplansection-new', $edit, self::NAME_MEALPLAN_SECTION, 'the edit form carries the section being edited');
	}

	// --- Phase 5: the feature flag branches, which need their own process ------------------

	/**
	 * Runs the helper in a fresh process against this class's own schema, which is the only
	 * way to reach a different value of a VICTUAL_* constant - they cannot be redefined and
	 * PHPUnit runs the whole class in one process (harness brief section 4).
	 *
	 * @return array{pages: array<int, array{method: string, status: int, hits: array<string, bool>}>}
	 */
	private static function subprocess(array $spec): array
	{
		// $_SERVER['argv'] is an array and proc_open's env has to be scalars only.
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'STOCKPAGES_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/stockpages-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the page helper printed no JSON. stdout: $output\nstderr: $errors");

		return $result;
	}

	/** The pages that offer a configured label printer, and the argument each needs. */
	private static function labelPrinterPages(): array
	{
		return [
			['controller' => 'stock', 'method' => 'Journal', 'args' => []],
			['controller' => 'stock', 'method' => 'LocationEditForm', 'args' => ['locationId' => (string)self::LOCATION_PARENT]],
			['controller' => 'stock', 'method' => 'LocationsList', 'args' => []],
			['controller' => 'stock', 'method' => 'Overview', 'args' => []],
			['controller' => 'stock', 'method' => 'ProductEditForm', 'args' => ['productId' => (string)self::PRODUCT_STOCKED]],
			['controller' => 'stock', 'method' => 'StockEntryEditForm', 'args' => ['entryId' => (string)self::$stockEntryId]],
			['controller' => 'stock', 'method' => 'Stockentries', 'args' => []],
			['controller' => 'recipes', 'method' => 'Overview', 'args' => []],
			['controller' => 'recipes', 'method' => 'RecipeEditForm', 'args' => ['recipeId' => (string)self::RECIPE_FULFILLED]],
		];
	}

	/**
	 * VICTUAL_FEATURE_FLAG_LABELS off is the default the rest of this class renders under,
	 * and every page that can print a label reads the printer list only when it is on. Both
	 * branches are asserted here: the flag-off render (in this process) offers no printer,
	 * the flag-on render (in a child process, since the flag is a constant) offers the
	 * configured one.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testConfiguredLabelPrintersAppearOnlyWhenTheLabelsFlagIsOn(): void
	{
		self::assumeRole('ADMIN');

		self::assertFalse(VICTUAL_FEATURE_FLAG_LABELS, 'this process renders with the flag off, which is the default');
		foreach (self::labelPrinterPages() as $page)
		{
			$controller = $page['controller'] === 'stock' ? self::$stock : self::$recipes;
			$html = self::render($controller, $page['method'], $page['args']);
			self::assertStringNotContainsString(self::NAME_PRINTER, $html, $page['controller'] . '::' . $page['method'] . '() offers no printer while the flag is off');
		}

		$result = self::subprocess([
			'labels' => true,
			'pages' => self::labelPrinterPages(),
			'needles' => [self::NAME_PRINTER],
		]);

		self::assertCount(count(self::labelPrinterPages()), $result['pages'], 'the child rendered every page');
		foreach ($result['pages'] as $page)
		{
			self::assertSame(200, $page['status'], $page['method'] . '() renders with the labels flag on');
			self::assertTrue($page['hits'][self::NAME_PRINTER], $page['method'] . '() offers the configured printer when the flag is on');
		}
	}

	/**
	 * StockController's constructor asks StockService for the configured external barcode
	 * lookup plugin and exposes the name to every view it renders. An installation that
	 * names no plugin makes that call throw, and the documented behaviour is an empty
	 * plugin name rather than a failed page - every stock page depends on the constructor
	 * surviving it.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testStockPagesStillRenderWhenNoBarcodeLookupPluginIsConfigured(): void
	{
		self::assumeRole('ADMIN');

		$result = self::subprocess([
			'barcodePlugin' => '',
			'pages' => [
				['controller' => 'stock', 'method' => 'Purchase', 'args' => []],
				['controller' => 'stock', 'method' => 'ProductsList', 'args' => []],
			],
			'needles' => [self::NAME_PRODUCT_STOCKED],
		]);

		foreach ($result['pages'] as $page)
		{
			self::assertSame(200, $page['status'], $page['method'] . '() renders with no lookup plugin configured');
			self::assertTrue($page['hits'][self::NAME_PRODUCT_STOCKED], $page['method'] . '() still carries its own data');
		}
	}

	// --- Defects found while covering these pages ----------------------------------------

	/**
	 * DEFECT. GET /product/new and GET /quantityunit/new are the create routes
	 * (StockController::ProductEditForm() and ::QuantityUnitEditForm() both branch on the
	 * literal 'new' and render with mode 'create'), and both templates dereference
	 * variables the create branch does not pass:
	 *
	 *   views/productform.blade.php:807   $productBarcodeUserfields, in the barcode table's
	 *                                     @include of components.userfields_thead
	 *   views/productform.blade.php:966   $product->id, in the Grocycode "Download" link
	 *   views/productform.blade.php:1084  $product->picture_file_name, in the file label
	 *   views/quantityunitform.blade.php:131  $quantityUnit->id, in the "Add conversion" link
	 *
	 * The blocks are only hidden with a d-none class (productform.blade.php:946 for the
	 * Grocycode row, :1082 for the picture label), not skipped with @if($mode == 'edit')
	 * the way the same templates guard their other edit-only blocks - the grocycode image
	 * at :959 is inside such a guard and raises nothing - so they are still evaluated.
	 *
	 * The product form's first diagnostic is a second, separate defect on the same page:
	 * the create branch passes the raw product_barcodes rows as 'barcodes'
	 * (controllers/StockController.php:393, and :418 for the edit branch), while
	 * views/components/productpicker.blade.php:71 expects the comma separated view's
	 * 'barcodes' column and calls strtolower() on the null it gets instead. It is captured
	 * and asserted here rather than left to leak, because a pinned list that omitted it
	 * would pass whether the deprecation was there or not.
	 *
	 * Correct behaviour is for the create branch to render no diagnostic at all. This test
	 * pins what happens today rather than asserting the fix, because application code is
	 * out of scope for this work; it is marked incomplete so the run says so out loud
	 * instead of looking like a passing assertion about warnings being fine.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testCreateFormsDereferenceVariablesTheyWereNotGiven(): void
	{
		self::assumeRole('ADMIN');

		[$productForm, $productDiagnostics] = self::renderCapturingDiagnostics(self::$stock, 'ProductEditForm', ['productId' => 'new'], [], E_ALL);
		self::assertStringContainsString('</html>', $productForm, 'the page is still served');
		self::assertSame(
			[
				'strtolower(): Passing null to parameter #1 ($string) of type string is deprecated',
				'Undefined variable $productBarcodeUserfields',
				'Undefined variable $product',
				'Attempt to read property "id" on null',
				'Undefined variable $product',
				'Attempt to read property "picture_file_name" on null'
			],
			$productDiagnostics,
			'DEFECT: GET /product/new emits these six diagnostics and no others'
		);
		self::assertStringContainsString('/product//grocycode?download=true', $productForm, 'DEFECT: and renders the broken link that follows from the null id');

		[$unitForm, $unitDiagnostics] = self::renderCapturingDiagnostics(self::$stock, 'QuantityUnitEditForm', ['quantityunitId' => 'new'], [], E_ALL);
		self::assertStringContainsString('</html>', $unitForm, 'the page is still served');
		self::assertSame(
			['Undefined variable $quantityUnit', 'Attempt to read property "id" on null'],
			$unitDiagnostics,
			'DEFECT: GET /quantityunit/new reads the unit it has not been given, and nothing else warns'
		);
		self::assertStringContainsString('/quantityunitconversion/new?embedded&amp;qu-unit="', $unitForm, 'DEFECT: and renders the link with no unit in it');

		self::markTestIncomplete('Create forms emit PHP diagnostics: ' . implode('; ', array_unique(array_merge($productDiagnostics, $unitDiagnostics))));
	}

	/**
	 * DEFECT. GET /recipe/new is linked from the recipes page
	 * (views/recipes.blade.php:61) and RecipesController::RecipeEditForm() documents
	 * recipeId as "either a recipe id or the literal 'new' for create mode" and computes
	 * mode from exactly that. It nonetheless passes the literal through to
	 * $this->DB->recipes($recipeId) first (controllers/RecipesController.php:234), which
	 * on PostgreSQL is SELECT * FROM recipes WHERE id = 'new' - SQLSTATE 22P02. So the
	 * create form is unreachable and the link 500s.
	 *
	 * StockController::ProductEditForm() shows the shape the correct behaviour has: it
	 * branches on 'new' before touching the database. Pinned rather than fixed, and
	 * marked incomplete so the run reports it.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testRecipeCreateFormCannotBeReached(): void
	{
		self::assumeRole('ADMIN');

		try
		{
			self::recipesPage('RecipeEditForm', ['recipeId' => 'new']);
			self::fail('GET /recipe/new rendered - the defect below has been fixed, so this test should be replaced by the positive assertion');
		}
		catch (\PDOException $exception)
		{
			self::assertStringContainsString('invalid input syntax for type integer', $exception->getMessage(), 'the literal create marker reaches the query as an id');
		}

		self::markTestIncomplete('GET /recipe/new raises a PDOException instead of rendering the create form');
	}

	// --- Phase 6: price redaction ------------------------------------------------------

	/**
	 * The three pages .devtools/pgsql/price-visibility-tests.php pins, asserted the same
	 * way it does: a currency span is emitted only for a caller who resolves to
	 * STOCK_PRICES_VIEW, and the stock overview's computed value (10 * 3.50) is in the
	 * page source only for that caller. Hiding a cell with a CSS class is not redaction -
	 * the figure would still be in the source for anyone who reads it.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testPricesReachACallerHoldingStockPricesView(): void
	{
		self::assumeRole('ADMIN');

		$shoppingList = self::stockPage('ShoppingList');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $shoppingList, 'the shopping list page rendered the fixture item');
		self::assertStringContainsString('locale-number-currency', $shoppingList, 'the shopping list page emits a currency span');

		$overview = self::stockPage('Overview');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $overview, 'the stock overview page rendered the fixture product');
		self::assertStringContainsString('locale-number-currency', $overview, 'the stock overview page emits a currency span');
		self::assertStringContainsString('>35<', $overview, 'the stock overview page carries the computed stock value');

		$entries = self::stockPage('Stockentries');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $entries, 'the stock entries page rendered the fixture product');
		self::assertStringContainsString('locale-number-currency', $entries, 'the stock entries page emits a currency span');
	}

	#[Depends('testFixturesAreCreated')]
	public function testPricesAreWithheldFromTheSeededChildRole(): void
	{
		self::assumeRole('CHILD');

		$shoppingList = self::stockPage('ShoppingList');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $shoppingList, 'a Child still sees the shopping list itself - only the prices go');
		self::assertStringNotContainsString('locale-number-currency', $shoppingList, 'the shopping list page emits no currency span for a Child');

		$overview = self::stockPage('Overview');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $overview, 'a Child still sees what is in stock');
		self::assertStringNotContainsString('locale-number-currency', $overview, 'the stock overview page emits no currency span for a Child');
		self::assertStringNotContainsString('>35<', $overview, 'the computed stock value is not in the page source for a Child');

		$entries = self::stockPage('Stockentries');
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, $entries, 'a Child still sees the entries');
		self::assertStringNotContainsString('locale-number-currency', $entries, 'the stock entries page emits no currency span for a Child');
	}

	/**
	 * The meal plan serialises recipes_resolved into the page for mealplan.js, so the
	 * cost fields leave the server verbatim unless FieldPolicy removes them first. The
	 * gated field list is read from permission_fields rather than hand-maintained, the
	 * way MealPlanRedactionTest does it.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testMealPlanRecipeCostsAreRedactedForTheChildRole(): void
	{
		$gated = self::$db->query("SELECT field FROM permission_fields WHERE entity = 'recipes_resolved' AND field <> '*'")->fetchAll(PDO::FETCH_COLUMN);
		self::assertNotEmpty($gated, 'permission_fields gates something on recipes_resolved, or this asserts nothing');

		self::assumeRole('ADMIN');
		$allowed = self::mealPlanRecipesResolved();
		self::assertNotEmpty($allowed, 'the fixture meal plan entry resolves to recipes_resolved rows');
		foreach ($allowed as $row)
		{
			foreach ($gated as $field)
			{
				self::assertArrayHasKey($field, $row, "an Admin holds STOCK_PRICES_VIEW, so recipes_resolved.$field reaches the page");
			}
		}

		self::assumeRole('CHILD');
		$restricted = self::mealPlanRecipesResolved();
		self::assertNotEmpty($restricted, 'a Child still gets the rows - only the gated fields are removed');
		foreach ($restricted as $row)
		{
			self::assertArrayHasKey('recipe_id', $row, 'an ungated field survives redaction');
			foreach ($gated as $field)
			{
				self::assertArrayNotHasKey($field, $row, "a Child lacks STOCK_PRICES_VIEW, so recipes_resolved.$field must not be serialised into the page");
			}
		}
	}

	/** @return array<int, array<string, mixed>> The rows the meal plan page hands mealplan.js. */
	private static function mealPlanRecipesResolved(): array
	{
		$html = self::recipesPage('MealPlan');
		self::assertSame(1, preg_match('/Victual\.RecipesResolved = (.*);\R/', $html, $matches), 'the page embeds Victual.RecipesResolved');

		$rows = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($rows);

		return $rows;
	}

	// --- Phase 7: permission refusal ------------------------------------------------------

	/**
	 * Every StockController page except the four shopping list ones requires STOCK_VIEW.
	 * A caller holding only the recipe leaves reaches none of them.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testStockPagesRefuseACallerWithoutStockView(): void
	{
		self::assumeGrants(['RECIPES_VIEW', 'MEALPLAN_VIEW']);

		$pages = [
			'Consume' => [],
			'Inventory' => [],
			'Journal' => [],
			'JournalSummary' => [],
			'LocationContentSheet' => [],
			'LocationEditForm' => ['locationId' => 'new'],
			'LocationLabels' => [],
			'LocationsList' => [],
			'Overview' => [],
			'ProductBarcodesEditForm' => ['productBarcodeId' => 'new'],
			'ProductEditForm' => ['productId' => 'new'],
			'ProductGrocycodeImage' => ['productId' => '1'],
			'ProductGroupEditForm' => ['productGroupId' => 'new'],
			'ProductGroupsList' => [],
			'ProductSubstitutionEditForm' => [],
			'ProductsList' => [],
			'Purchase' => [],
			'QuantityUnitConversionEditForm' => ['quConversionId' => 'new'],
			'QuantityUnitConversionsResolved' => [],
			'QuantityUnitEditForm' => ['quantityunitId' => 'new'],
			'QuantityUnitPluralFormTesting' => [],
			'QuantityUnitsList' => [],
			'ShoppingLocationEditForm' => ['shoppingLocationId' => 'new'],
			'ShoppingLocationsList' => [],
			'StockEntryEditForm' => ['entryId' => '1'],
			'StockEntryGrocycodeImage' => ['entryId' => '1'],
			'StockEntryGrocycodeLabel' => ['entryId' => '1'],
			'StockSettings' => [],
			'Stockentries' => [],
			'Transfer' => [],
		];

		foreach ($pages as $method => $args)
		{
			self::expectStatus(
				fn () => self::stockPage($method, $args),
				403,
				"StockController::$method() without STOCK_VIEW"
			);
		}
	}

	#[Depends('testFixturesAreCreated')]
	public function testShoppingListPagesRefuseACallerWithoutShoppinglistView(): void
	{
		self::assumeGrants(['STOCK_VIEW']);

		$pages = [
			'ShoppingList' => [],
			'ShoppingListEditForm' => ['listId' => 'new'],
			'ShoppingListItemEditForm' => ['itemId' => 'new'],
			'ShoppingListSettings' => [],
		];

		foreach ($pages as $method => $args)
		{
			self::expectStatus(
				fn () => self::stockPage($method, $args),
				403,
				"StockController::$method() without SHOPPINGLIST_VIEW"
			);
		}

		// The same grant still reaches a stock page, so the refusals above are about the
		// missing leaf and not about the caller having lost everything.
		self::assertStringContainsString(self::NAME_PRODUCT_STOCKED, self::stockPage('ProductsList'), 'STOCK_VIEW alone still reaches the products list');
	}

	#[Depends('testFixturesAreCreated')]
	public function testRecipePagesRefuseACallerWithoutTheirLeaf(): void
	{
		self::assumeGrants(['STOCK_VIEW', 'SHOPPINGLIST_VIEW']);

		$recipePages = [
			'Overview' => [],
			'RecipeEditForm' => ['recipeId' => 'new'],
			'RecipeGrocycodeImage' => ['recipeId' => '1'],
			'RecipePosEditForm' => ['recipeId' => '1', 'recipePosId' => 'new'],
			'RecipesSettings' => [],
		];

		foreach ($recipePages as $method => $args)
		{
			self::expectStatus(
				fn () => self::recipesPage($method, $args),
				403,
				"RecipesController::$method() without RECIPES_VIEW"
			);
		}

		$mealPlanPages = [
			'MealPlan' => [],
			'MealPlanSectionEditForm' => ['sectionId' => 'new'],
			'MealPlanSectionsList' => [],
		];

		foreach ($mealPlanPages as $method => $args)
		{
			self::expectStatus(
				fn () => self::recipesPage($method, $args),
				403,
				"RecipesController::$method() without MEALPLAN_VIEW"
			);
		}
	}

	/**
	 * RECIPES_VIEW and MEALPLAN_VIEW are separate leaves, and the seeded GUEST role holds
	 * both while holding no stock leaf beyond STOCK_VIEW - so the recipe pages open for it
	 * and the shopping list pages do not. A refusal test that only ever refuses proves
	 * nothing about which leaf was consulted.
	 */
	#[Depends('testFixturesAreCreated')]
	public function testGuestReachesTheRecipePagesButNotTheShoppingList(): void
	{
		self::assumeRole('GUEST');

		self::assertStringContainsString(self::NAME_RECIPE_FULFILLED, self::recipesPage('Overview'), 'a Guest holds RECIPES_VIEW');
		self::assertStringContainsString(self::NAME_RECIPE_FULFILLED, self::recipesPage('MealPlan'), 'a Guest holds MEALPLAN_VIEW');
		self::expectStatus(fn () => self::stockPage('ShoppingList'), 403, 'a Guest holds no shopping list leaf');
	}
}
