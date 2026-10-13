<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\StockController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #905 (decision F6): StockController::ShoppingList() needs only SHOPPINGLIST_VIEW but
 * handed the product and product-group userfield definitions and values to the page, which
 * renders them per listed item. Everywhere else those values need STOCK_VIEW
 * (EntityReadPolicy: products, product_groups), and a file or image value carries the stored
 * file name, which is the capability to fetch the file. A SHOPPINGLIST_VIEW-only caller must
 * therefore get neither the columns nor any value, caption or stored name; the shopping
 * list's own userfields stay under SHOPPINGLIST_VIEW.
 *
 * Rendered the way StockPagesTest renders it: the controller called directly, asserting on
 * the HTML. Each denied case is paired with the same needles on a STOCK_VIEW render, so a
 * fixture that never reaches the page cannot pass the denial.
 */
class ShoppingListUserfieldVisibilityTest extends PgsqlSchemaTestCase
{
	private const LOCATION = 9910;
	private const GROUP = 9910;
	private const PRODUCT = 9910;
	private const ITEM = 9910;

	private const PRODUCT_TEXT = 'SLUF product text value';
	private const PRODUCT_CAPTION = 'SLUF product text caption';
	private const PRODUCT_FILE_CAPTION = 'SLUF product file caption';
	private const GROUP_TEXT = 'SLUF group text value';
	private const GROUP_CAPTION = 'SLUF group text caption';
	private const GROUP_FILE_CAPTION = 'SLUF group file caption';
	private const LIST_TEXT = 'SLUF list text value';
	private const LIST_CAPTION = 'SLUF list text caption';

	private const PRODUCT_STORED_NAME = 'sluf-product-secret.pdf';
	private const PRODUCT_DISPLAY_NAME = 'sluf-product-display.pdf';
	private const GROUP_STORED_NAME = 'sluf-group-secret.pdf';
	private const GROUP_DISPLAY_NAME = 'sluf-group-display.pdf';

	private static PDO $db;
	private static StockController $stock;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockController($container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'sluf-caller', 'fixture')");
		self::$db->exec('INSERT INTO locations(id, name) VALUES (' . self::LOCATION . ", 'SLUF Pantry')");
		self::$db->exec('INSERT INTO product_groups(id, name) VALUES (' . self::GROUP . ", 'SLUF Group')");
		// Quantity unit 2 is 'Piece', seeded by the migrations (as in StockPagesTest).
		self::$db->exec('INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock, product_group_id) VALUES ('
			. self::PRODUCT . ", 'SLUF Product', " . self::LOCATION . ', 2, 2, ' . self::GROUP . ')');
		self::$db->exec('INSERT INTO shopping_list(id, product_id, amount, shopping_list_id) VALUES ('
			. self::ITEM . ', ' . self::PRODUCT . ', 1, 1)');

		self::defineField('products', 'slufproducttext', self::PRODUCT_CAPTION, 'text-single-line', self::PRODUCT, self::PRODUCT_TEXT);
		self::defineField('products', 'slufproductfile', self::PRODUCT_FILE_CAPTION, 'file', self::PRODUCT, self::storedValue(self::PRODUCT_STORED_NAME, self::PRODUCT_DISPLAY_NAME));
		self::defineField('product_groups', 'slufgrouptext', self::GROUP_CAPTION, 'text-single-line', self::GROUP, self::GROUP_TEXT);
		self::defineField('product_groups', 'slufgroupfile', self::GROUP_FILE_CAPTION, 'file', self::GROUP, self::storedValue(self::GROUP_STORED_NAME, self::GROUP_DISPLAY_NAME));
		self::defineField('shopping_list', 'slufllisttext', self::LIST_CAPTION, 'text-single-line', self::ITEM, self::LIST_TEXT);
	}

	/** The stored value of a file field: base64(stored name) . '_' . base64(display name), as userfieldsform.js writes it. */
	private static function storedValue(string $stored, string $display): string
	{
		return base64_encode($stored) . '_' . base64_encode($display);
	}

	private static function defineField(string $entity, string $name, string $caption, string $type, int $objectId, string $value): void
	{
		$statement = self::$db->prepare('INSERT INTO userfields (entity, name, caption, type, show_as_column_in_tables) VALUES (?, ?, ?, ?, 1) RETURNING id');
		$statement->execute([$entity, $name, $caption, $type]);
		$fieldId = (int)$statement->fetchColumn();

		$statement = self::$db->prepare('INSERT INTO userfield_values (field_id, object_id, value) VALUES (?, ?, ?)');
		$statement->execute([$fieldId, $objectId, $value]);
	}

	private static function assumeGrants(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	private static function renderShoppingList(): string
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/shoppinglist');
		$response = self::$stock->ShoppingList($request, new Response(), []);
		self::assertSame(200, $response->getStatusCode(), 'the shopping list renders');

		return (string)$response->getBody();
	}

	/** @return array<string, string[]> entity label => the strings that identify its userfield data on the page */
	private static function needles(): array
	{
		return [
			'product' => [
				self::PRODUCT_CAPTION,
				self::PRODUCT_TEXT,
				self::PRODUCT_FILE_CAPTION,
				self::PRODUCT_DISPLAY_NAME,
				self::storedValue(self::PRODUCT_STORED_NAME, self::PRODUCT_DISPLAY_NAME),
				base64_encode(self::PRODUCT_STORED_NAME),
			],
			'product group' => [
				self::GROUP_CAPTION,
				self::GROUP_TEXT,
				self::GROUP_FILE_CAPTION,
				self::GROUP_DISPLAY_NAME,
				self::storedValue(self::GROUP_STORED_NAME, self::GROUP_DISPLAY_NAME),
				base64_encode(self::GROUP_STORED_NAME),
			],
		];
	}

	public function testShoppingListOnlyCallerGetsNoProductOrProductGroupUserfieldData(): void
	{
		self::assumeGrants(['SHOPPINGLIST_VIEW']);
		$html = self::renderShoppingList();

		// The page itself rendered, so an empty body cannot satisfy the denials below.
		self::assertStringContainsString('SLUF Product', $html, 'the listed item is on the page');

		foreach (self::needles() as $label => $needles)
		{
			foreach ($needles as $needle)
			{
				self::assertStringNotContainsString($needle, $html, "a SHOPPINGLIST_VIEW-only caller is not given the $label userfield datum '$needle'");
			}
		}
	}

	public function testCallerWithStockViewGetsTheProductAndProductGroupUserfieldData(): void
	{
		self::assumeGrants(['SHOPPINGLIST_VIEW', 'STOCK_VIEW']);
		$html = self::renderShoppingList();

		foreach (self::needles() as $label => $needles)
		{
			foreach ($needles as $needle)
			{
				self::assertStringContainsString($needle, $html, "a caller holding STOCK_VIEW still gets the $label userfield datum '$needle'");
			}
		}
	}

	public function testTheShoppingListsOwnUserfieldsStayWithShoppinglistView(): void
	{
		self::assumeGrants(['SHOPPINGLIST_VIEW']);
		$html = self::renderShoppingList();

		self::assertStringContainsString(self::LIST_CAPTION, $html, 'the list\'s own userfield column is shown');
		self::assertStringContainsString(self::LIST_TEXT, $html, 'the list\'s own userfield value is shown');
	}
}
