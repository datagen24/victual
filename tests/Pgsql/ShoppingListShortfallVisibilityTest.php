<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Controllers\StockController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #933, ADR-0050 (maintainer decisions 2026-10-18): on the shopping-list pages a
 * SHOPPINGLIST_VIEW-only caller keeps product barcodes, which belong to the shopping-list
 * workflow, but no longer gets stock shortfalls: the count of products below their minimum,
 * the per-row marker and the shortfall filter need STOCK_VIEW, and so does
 * POST /api/stock/shoppinglist/add-missing-products, whose additions are the shortfall set.
 *
 * Rendered as ShoppingListUserfieldVisibilityTest renders it: the controller called directly,
 * asserting on the HTML. Every denial is paired with the same check on a STOCK_VIEW render, so
 * a fixture that never reaches the page cannot pass it.
 */
class ShoppingListShortfallVisibilityTest extends PgsqlSchemaTestCase
{
	private const LOCATION = 9930;
	private const PRODUCT = 9930;
	private const ITEM = 9930;
	private const BARCODE = 'slsv-barcode-9930';
	private const SHORTFALL_MESSAGE = 'below defined min. stock amount';

	private static PDO $db;
	private static StockController $stock;
	private static StockApiController $stockApi;

	/** A product with a minimum it is below (no stock), on list 1, with a barcode. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockController($container);
		self::$stockApi = new StockApiController($container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'slsv-caller', 'fixture')");
		self::$db->exec('INSERT INTO locations(id, name) VALUES (' . self::LOCATION . ", 'SLSV Pantry')");
		// Quantity unit 2 is 'Piece', seeded by the migrations (as in StockPagesTest).
		self::$db->exec('INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock, min_stock_amount) VALUES ('
			. self::PRODUCT . ", 'SLSV Product', " . self::LOCATION . ', 2, 2, 5)');
		self::$db->exec('INSERT INTO product_barcodes(product_id, barcode) VALUES (' . self::PRODUCT . ", '" . self::BARCODE . "')");
		self::$db->exec('INSERT INTO shopping_list(id, product_id, amount, shopping_list_id) VALUES (' . self::ITEM . ', ' . self::PRODUCT . ', 1, 1)');
	}

	/** Replaces the fixture caller's grants with exactly $names. */
	private static function assumeGrants(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	/** GET /shoppinglist as the fixture caller; returns the HTML. */
	private static function renderShoppingList(): string
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/shoppinglist');
		$response = self::$stock->ShoppingList($request, new Response(), []);
		self::assertSame(200, $response->getStatusCode(), 'the shopping list renders');

		return (string)$response->getBody();
	}

	/** The fixture item's table row, from its <tr> to the next one. */
	private static function itemRow(string $html): string
	{
		$start = strpos($html, 'id="shoppinglistitem-' . self::ITEM . '-row"');
		self::assertNotFalse($start, 'the listed item renders');

		return substr($html, $start, strpos($html, '</tr>', $start) - $start);
	}

	/** @return array<string, bool> which shortfall facts the page shows */
	private static function shortfalls(string $html): array
	{
		return [
			'count' => str_contains($html, self::SHORTFALL_MESSAGE),
			'filter' => str_contains($html, 'value="belowminstockamount"'),
			'add action' => str_contains($html, 'id="add-products-below-min-stock-amount"'),
			'row marker' => str_contains(self::itemRow($html), 'belowminstockamount'),
		];
	}

	/** Runs a controller action; returns its status, or the refusal's. */
	private static function actionStatus(callable $action): int
	{
		try
		{
			return $action()->getStatusCode();
		}
		catch (HttpException $ex)
		{
			return $ex->getCode();
		}
	}

	/** A SHOPPINGLIST_VIEW-only caller gets no shortfall fact on the list page, and still gets the barcode. */
	public function testAShoppingListOnlyCallerGetsNoShortfallButKeepsTheBarcode(): void
	{
		self::assumeGrants(['SHOPPINGLIST_VIEW']);
		$html = self::renderShoppingList();

		self::assertSame(['count' => false, 'filter' => false, 'add action' => false, 'row marker' => false], self::shortfalls($html));
		self::assertStringContainsString(self::BARCODE, $html, 'the barcode stays: it is part of the shopping-list workflow');
	}

	/** With STOCK_VIEW the same page shows every shortfall fact and the barcode. */
	public function testACallerWithStockViewGetsTheShortfalls(): void
	{
		self::assumeGrants(['SHOPPINGLIST_VIEW', 'STOCK_VIEW']);
		$html = self::renderShoppingList();

		self::assertSame(['count' => true, 'filter' => true, 'add action' => true, 'row marker' => true], self::shortfalls($html));
		self::assertStringContainsString(self::BARCODE, $html);
	}

	/** The item picker keeps active products' barcodes as search data for a SHOPPINGLIST_VIEW-only caller. */
	public function testTheItemPickerKeepsBarcodesForAShoppingListOnlyCaller(): void
	{
		self::assumeGrants(['SHOPPINGLIST_VIEW']);
		$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/shoppinglistitem/new');
		$response = self::$stock->ShoppingListItemEditForm($request, new Response(), ['itemId' => 'new']);

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString(self::BARCODE, (string)$response->getBody(), 'the picker can find the product by its barcode');
	}

	/** Adding the products below their minimum needs STOCK_VIEW as well as SHOPPINGLIST_ITEMS_ADD. */
	public function testAddingTheMissingProductsNeedsStockView(): void
	{
		$add = function ()
		{
			$request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/stock/shoppinglist/add-missing-products')
				->withParsedBody(['list_id' => 1])->withHeader('Content-Type', 'application/json');

			return self::$stockApi->AddMissingProductsToShoppingList($request, new Response(), []);
		};

		self::assumeGrants(['SHOPPINGLIST_ITEMS_ADD']);
		self::assertSame(403, self::actionStatus($add), 'without STOCK_VIEW the shortfall set is not added');

		self::assumeGrants(['SHOPPINGLIST_ITEMS_ADD', 'STOCK_VIEW']);
		self::assertSame(204, self::actionStatus($add), 'with it the missing products are added');
	}
}
