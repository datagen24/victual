<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Plan 05 parts A and C (issue #85): migrations/0286.pgsql.sql's three nullable columns -
 * shopping_lists.shopping_location_id, products.default_shopping_list_id,
 * recipes.default_shopping_list_id - round-tripped through the real generic-entity
 * endpoints, plus the negative case the migration's own header is explicit about:
 * products_view and shopping_lists_view are deliberately left un-reissued (see migration
 * 0276's precedent for products_view), so this class also asserts the columns are absent
 * from those two views rather than assuming a future contributor will re-check the
 * comment before "fixing" it with a CREATE OR REPLACE VIEW that would actually fail.
 */
class ShoppingListStoresTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static GenericEntityApiController $generic;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$generic = new GenericEntityApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'shopliststores-caller', 'fixture')");
		$stmt = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$stmt->execute(['ADMIN']);
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . (int)$stmt->fetchColumn() . ')');
	}

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');
		return $body === null ? $request : $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
	}

	private static function createId(string $entity, array $body): int
	{
		$response = self::$generic->AddObject(self::request('POST', $body), new Response(), ['entity' => $entity]);
		$decoded = json_decode((string)$response->getBody(), true);
		return (int)$decoded['created_object_id'];
	}

	private static function getObject(string $entity, int $id): array
	{
		$response = self::$generic->GetObject(self::request(), new Response(), ['entity' => $entity, 'objectId' => $id]);
		return json_decode((string)$response->getBody(), true);
	}

	public function testShoppingListCarriesItsStore(): void
	{
		$storeId = self::createId('shopping_locations', ['name' => 'Contract Grocer']);

		$listWithStore = self::createId('shopping_lists', ['name' => 'Weekly at the grocer', 'shopping_location_id' => $storeId]);
		self::assertSame($storeId, self::getObject('shopping_lists', $listWithStore)['shopping_location_id'], 'A shopping list created with a store keeps it');

		$listWithoutStore = self::createId('shopping_lists', ['name' => 'General purpose list']);
		self::assertNull(self::getObject('shopping_lists', $listWithoutStore)['shopping_location_id'], 'Omitting the store leaves the column null - existing lists stay general purpose');
	}

	#[Depends('testShoppingListCarriesItsStore')]
	public function testProductDefaultsOntoAList(): void
	{
		$listId = self::createId('shopping_lists', ['name' => 'The list a product defaults onto']);
		$locationId = self::createId('locations', ['name' => 'Contract Pantry']);

		$productWithDefault = self::createId('products', [
			'name' => 'Contract Product With Default List',
			'location_id' => $locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'default_shopping_list_id' => $listId,
		]);
		self::assertSame($listId, self::getObject('products', $productWithDefault)['default_shopping_list_id'], 'A product created with a default list keeps it');

		$productWithoutDefault = self::createId('products', [
			'name' => 'Contract Product Without Default List',
			'location_id' => $locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
		]);
		self::assertNull(self::getObject('products', $productWithoutDefault)['default_shopping_list_id'], 'Omitting the default list falls back to the pre-existing behaviour (null)');
	}

	#[Depends('testShoppingListCarriesItsStore')]
	public function testRecipeDefaultsOntoAList(): void
	{
		$listId = self::createId('shopping_lists', ['name' => 'The list a recipe defaults onto']);

		$recipeWithDefault = self::createId('recipes', ['name' => 'Contract Recipe With Default List', 'default_shopping_list_id' => $listId]);
		self::assertSame($listId, self::getObject('recipes', $recipeWithDefault)['default_shopping_list_id'], 'A recipe created with a default list keeps it');

		$recipeWithoutDefault = self::createId('recipes', ['name' => 'Contract Recipe Without Default List']);
		self::assertNull(self::getObject('recipes', $recipeWithoutDefault)['default_shopping_list_id'], 'Omitting the default list falls back to the pre-existing behaviour (null)');
	}

	/**
	 * The migration's own header explains why products_view/shopping_lists_view are not
	 * re-issued: both flatten `p.*`/`sl.*` at CREATE VIEW time, and inserting a new column
	 * there would push every already-frozen computed column (has_sub_products,
	 * qu_factor_*_to_stock; item_count) one position later, which PostgreSQL's
	 * CREATE OR REPLACE VIEW refuses - migration 0276 hit this for real for
	 * products_view/quick_refill_amount. This asserts the two halves of that tradeoff
	 * together: the views still work at all, and they still do not carry the new columns,
	 * so a "the view is just missing a column, let me fix that" edit gets caught by this
	 * test rather than by a failed migration in review.
	 */
	#[Depends('testProductDefaultsOntoAList')]
	public function testViewsBuiltOverTheAlteredTablesStillWorkButDoNotCarryTheNewColumns(): void
	{
		// information_schema, not a SELECT * fetch: the assertion must hold regardless of
		// whether either view happens to have rows when this runs.
		$columnsOf = function (string $view): array
		{
			$stmt = self::$db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
			$stmt->execute([self::Schema(), $view]);
			return $stmt->fetchAll(PDO::FETCH_COLUMN);
		};

		self::$db->query('SELECT * FROM products_view LIMIT 1')->fetch();
		$productColumns = $columnsOf('products_view');
		self::assertNotEmpty($productColumns, 'products_view still exists and is queryable after the migration');
		self::assertNotContains('default_shopping_list_id', $productColumns, 'products_view is deliberately not re-issued (migration 0276\'s precedent) - the new column reaches callers only through the products table itself');

		self::$db->query('SELECT * FROM shopping_lists_view LIMIT 1')->fetch();
		$shoppingListColumns = $columnsOf('shopping_lists_view');
		self::assertNotEmpty($shoppingListColumns, 'shopping_lists_view still exists and is queryable after the migration');
		self::assertNotContains('shopping_location_id', $shoppingListColumns, 'shopping_lists_view is deliberately not re-issued for the same reason - re-creating it would push item_count one position later, which CREATE OR REPLACE VIEW refuses');
	}
}
