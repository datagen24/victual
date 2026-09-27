<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Api\RecipesApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\UserfieldsService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Audit finding H10 / issue #499: object round trips fail and API responses disagree with
 * their documented schemas.
 *
 *   - GetObject() attaches a synthetic "userfields" key to every response it answers (see
 *     its own docblock), but WithoutServerOwnedColumns() only ever dropped "id",
 *     "row_created_timestamp" and "import_epoch" from a write body - not that one. No
 *     entity table has a "userfields" column (UserfieldsService keeps them in their own
 *     table, keyed by entity name and object id), so handing a body that still carries the
 *     key to LessQL's createRow()/update() answered "SQLSTATE[42703]: undefined column",
 *     caught by HandleApiCall() as a 400 with the edit never applied - breaking exactly the
 *     read-edit-write round trip WithoutServerOwnedColumns()'s own docblock says is
 *     supported ("a client that reads an object, edits one field and PUTs the whole thing
 *     back ... keeps working").
 *   - AddObject() answered created_object_id as whatever type PDO::lastInsertId() returns,
 *     which is always a string in PHP, although victual.openapi.json has documented the
 *     property `integer` on all three routes that carry it (POST /objects/{entity}, POST
 *     /recipes/{recipeId}/copy, POST /roles - the third already casts correctly, see
 *     RolesApiController::AddRole()). Maintainer decision 2026-09-26: the wire moves to
 *     match the document that was already right.
 *   - StockApiController::StockEntry() (GET /api/stock/entry/{entryId}) answered 200 with a
 *     JSON `null` body for an id that does not exist, rather than the 400
 *     victual.openapi.json documents for this route - no 404 is documented here, unlike the
 *     generic object endpoints. Maintainer decision 2026-09-26: the behavior moves to match
 *     the document.
 *
 * Response bodies are validated against victual.openapi.json with Opis, the way
 * WireContractTest does (WireContractTest::validateAgainstMember()), rather than only
 * against the status code or key set.
 */
class ObjectRoundTripTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static array $controllerCache = [];

	/** A stock entry id no fixture in this class's schema ever produces. */
	private const UNKNOWN_STOCK_ENTRY_ID = 987654;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'roundtrip-caller', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. "SELECT 9000, id FROM permission_hierarchy WHERE name IN ('MASTER_DATA_EDIT', 'STOCK_VIEW', 'RECIPES_VIEW', 'RECIPES')");
	}

	// ------------------------------------------------------------------------------
	// Small helpers, in the shape ContractTest/GenericWriteAtomicityTest already use.
	// ------------------------------------------------------------------------------

	private static function controller(string $class)
	{
		return self::$controllerCache[$class] ??= new $class(self::$container);
	}

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');
		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	/** victual.openapi.json, decoded once, as objects - Opis validates against objects. */
	private static function spec(): object
	{
		static $document = null;
		if ($document === null)
		{
			$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);
		}

		return $document;
	}

	/**
	 * Validates $body against a schema that carries no $ref of its own, either a named
	 * component (components/schemas/$componentOrNull) or, when $componentOrNull is null, the
	 * inline schema at paths[$path][$method].responses[$status].content['application/json'].
	 * Mirrors WireContractTest::validateAgainstMember() - allowDefaults off for the same
	 * reason - minus the union-candidate bookkeeping this file has no use for. Every schema
	 * used from this class was confirmed $ref-free (StockEntry, Product, Error400 and the two
	 * inline created_object_id response schemas), so no resolver is needed for either form.
	 */
	private static function assertMatchesSchema($body, string $what, ?string $component = null, ?string $path = null, ?string $method = null, ?string $status = null): void
	{
		$schema = $component !== null
			? self::spec()->components->schemas->$component
			: self::spec()->paths->$path->$method->responses->$status->content->{'application/json'}->schema;

		$validator = new \Opis\JsonSchema\Validator();
		$validator->parser()->setOption('allowDefaults', false);

		$result = $validator->validate(
			json_decode(json_encode($body), false),
			json_decode(json_encode($schema), false)
		);

		if ($result->isValid())
		{
			return;
		}

		$error = $result->error();
		while ($error->subErrors())
		{
			$error = $error->subErrors()[0];
		}

		self::fail("$what did not validate against its documented schema: " . (implode('/', $error->data()->fullPath()) ?: '<root>') . ': ' . $error->keyword());
	}

	// ------------------------------------------------------------------------------
	// H10-roundtrip: GET product, change name, PUT the returned object.
	// ------------------------------------------------------------------------------

	public function testReadEditWriteRoundTripSucceedsAndPersistsTheEdit(): void
	{
		$generic = self::controller(GenericEntityApiController::class);

		$location = self::insertRow('locations', ['name' => 'Round Trip Shelf']);
		$qu = self::insertRow('quantity_units', ['name' => 'Round Trip Unit']);
		$group = self::insertRow('product_groups', ['name' => 'Round Trip Group']);
		$productId = self::insertRow('products', [
			'name' => 'Round Trip Product',
			'description' => 'Seeded for the round trip test',
			'product_group_id' => $group,
			'location_id' => $location,
			'qu_id_purchase' => $qu,
			'qu_id_stock' => $qu,
		]);

		$getResponse = $generic->GetObject(self::request(), new Response(), ['entity' => 'products', 'objectId' => $productId]);
		self::assertSame(200, $getResponse->getStatusCode());
		$body = json_decode((string)$getResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

		// The precondition that makes this a real reproduction of H10, not a trivial PUT:
		// the key GetObject() attaches and WithoutServerOwnedColumns() used to leave alone.
		self::assertArrayHasKey('userfields', $body, 'precondition: GetObject() attaches "userfields" to its response - see its own docblock');
		self::assertNull($body['userfields'], 'precondition: this product has no userfield values set, so the attached key is null');

		// Not Opis-validated against Product here: a GET response with no userfields set
		// answers "userfields": null against a property Product types plain "object", and
		// several sibling columns (product_group_id, picture_file_name, ...) are nullable
		// but likewise typed as a plain non-nullable scalar - the same class of gap
		// WireContractTest::UNION_NULLABILITY_FAILURES already tracks for Product.description,
		// just not every instance of it (Opis reports one failure per row, so a fixture that
		// leaves every nullable column at its default surfaces only the first). That
		// modelling gap is unrelated to H10 and is not this test's to fix; the two explicit
		// assertions above are the precondition this test actually needs.

		$body['name'] = 'Round Trip Product (edited)';

		$putResponse = $generic->EditObject(self::request('PUT', $body), new Response(), ['entity' => 'products', 'objectId' => $productId]);

		self::assertSame(204, $putResponse->getStatusCode(), 'a read-edit-write round trip must succeed: ' . (string)$putResponse->getBody());
		self::assertSame(
			'Round Trip Product (edited)',
			$this->productName($productId),
			'the round trip must actually persist the edited field'
		);
	}

	/**
	 * The same round trip, with a real (non-null, non-empty) userfields map attached to the
	 * GET response - the "do not broadly remove non-scalar or null values" qualification in
	 * issue #499 applies to this case as much as to the null one above. Confirms the PUT
	 * neither fails on the populated map nor silently rewrites the userfield value: that
	 * value is server-owned by a different endpoint (SetUserfields(), PUT
	 * /api/userfields/{entity}/{objectId}), not by this one.
	 */
	public function testEditObjectRoundTripSurvivesAPopulatedUserfieldsMap(): void
	{
		$generic = self::controller(GenericEntityApiController::class);

		$location = self::insertRow('locations', ['name' => 'Userfield Round Trip Shelf']);
		$qu = self::insertRow('quantity_units', ['name' => 'Userfield Round Trip Unit']);
		$productId = self::insertRow('products', [
			'name' => 'Userfield Round Trip Product',
			'description' => 'Seeded for the userfields round trip test',
			'location_id' => $location,
			'qu_id_purchase' => $qu,
			'qu_id_stock' => $qu,
		]);

		self::insertRow('userfields', [
			'entity' => 'products',
			'name' => 'roundtrip_note',
			'caption' => 'Round Trip Note',
			'type' => 'text',
		]);
		UserfieldsService::GetInstance()->SetValues('products', $productId, ['roundtrip_note' => 'kept across the round trip']);

		$body = json_decode(
			(string)$generic->GetObject(self::request(), new Response(), ['entity' => 'products', 'objectId' => $productId])->getBody(),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		self::assertSame(['roundtrip_note' => 'kept across the round trip'], $body['userfields'], 'precondition: a populated, non-null userfields map');

		$body['name'] = 'Userfield Round Trip Product (edited)';
		$putResponse = $generic->EditObject(self::request('PUT', $body), new Response(), ['entity' => 'products', 'objectId' => $productId]);

		self::assertSame(204, $putResponse->getStatusCode(), 'a populated userfields map must not make the round trip fail: ' . (string)$putResponse->getBody());
		self::assertSame('Userfield Round Trip Product (edited)', $this->productName($productId));
		self::assertSame(
			['roundtrip_note' => 'kept across the round trip'],
			UserfieldsService::GetInstance()->GetValues('products', $productId),
			'the userfield value must survive untouched - it is written through SetUserfields(), not this endpoint'
		);
	}

	/**
	 * The fix drops exactly the "userfields" key, not every null or non-scalar value: a real
	 * nullable column explicitly set to null - the idiom this API already uses to clear a
	 * column (see WithoutServerOwnedColumns() callers' docblocks) - still reaches the row.
	 */
	public function testEditObjectStillAcceptsAnExplicitNullOnARealColumn(): void
	{
		$generic = self::controller(GenericEntityApiController::class);

		$location = self::insertRow('locations', ['name' => 'Null Write Shelf']);
		$qu = self::insertRow('quantity_units', ['name' => 'Null Write Unit']);
		$productId = self::insertRow('products', [
			'name' => 'Null Write Product',
			'description' => 'Not null yet',
			'location_id' => $location,
			'qu_id_purchase' => $qu,
			'qu_id_stock' => $qu,
		]);

		$putResponse = $generic->EditObject(self::request('PUT', ['description' => null]), new Response(), ['entity' => 'products', 'objectId' => $productId]);

		self::assertSame(204, $putResponse->getStatusCode(), (string)$putResponse->getBody());
		$statement = self::$db->prepare('SELECT description FROM products WHERE id = ?');
		$statement->execute([$productId]);
		self::assertNull($statement->fetchColumn(), 'an explicit null in the body must still clear a real column');
	}

	private function productName(int $productId): string
	{
		$statement = self::$db->prepare('SELECT name FROM products WHERE id = ?');
		$statement->execute([$productId]);

		return (string)$statement->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// H10-created-id: created_object_id is a JSON string, not the documented integer.
	// ------------------------------------------------------------------------------

	public function testCreatedObjectIdIsAWireIntegerForGenericEntityCreate(): void
	{
		$generic = self::controller(GenericEntityApiController::class);

		$response = $generic->AddObject(self::request('POST', ['name' => 'Created Id Location']), new Response(), ['entity' => 'locations']);
		self::assertSame(200, $response->getStatusCode());

		$rawBody = (string)$response->getBody();
		self::assertMatchesRegularExpression(
			'/"created_object_id":\d+[,}]/',
			$rawBody,
			"created_object_id must be an unquoted JSON number on the wire, got: $rawBody"
		);

		$body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
		self::assertIsInt($body['created_object_id'], 'created_object_id must decode as a PHP int, not a numeric string');

		$statement = self::$db->prepare('SELECT id FROM locations WHERE name = ?');
		$statement->execute(['Created Id Location']);
		self::assertSame((int)$statement->fetchColumn(), $body['created_object_id'], 'the reported id must be the row that was actually created');

		self::assertMatchesSchema($body, 'POST /objects/{entity} -> 200', path: '/objects/{entity}', method: 'post', status: '200');
	}

	public function testCreatedObjectIdIsAWireIntegerForRecipeCopy(): void
	{
		$sourceRecipeId = self::insertRow('recipes', ['name' => 'Recipe To Copy']);

		$response = self::controller(RecipesApiController::class)->CopyRecipe(self::request(), new Response(), ['recipeId' => $sourceRecipeId]);
		self::assertSame(200, $response->getStatusCode());

		$rawBody = (string)$response->getBody();
		self::assertMatchesRegularExpression(
			'/"created_object_id":\d+[,}]/',
			$rawBody,
			"created_object_id must be an unquoted JSON number on the wire, got: $rawBody"
		);

		$body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
		self::assertIsInt($body['created_object_id'], 'created_object_id must decode as a PHP int, not a numeric string');
		self::assertNotSame($sourceRecipeId, $body['created_object_id']);

		$statement = self::$db->prepare("SELECT count(*) FROM recipes WHERE id = ? AND name = 'Copy of Recipe To Copy'");
		$statement->execute([$body['created_object_id']]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'the reported id must be the copy that was actually created');

		self::assertMatchesSchema($body, 'POST /recipes/{recipeId}/copy -> 200', path: '/recipes/{recipeId}/copy', method: 'post', status: '200');
	}

	// ------------------------------------------------------------------------------
	// H10-missing-stock: GET /stock/entry/{entryId} answers 200 null for an unknown id
	// instead of the documented 400.
	// ------------------------------------------------------------------------------

	public function testUnknownStockEntryAnswersTheDocumented400AndAValidOneStillWorks(): void
	{
		$stockController = self::controller(StockApiController::class);

		$response = $stockController->StockEntry(self::request(), new Response(), ['entryId' => self::UNKNOWN_STOCK_ENTRY_ID]);

		self::assertSame(
			400,
			$response->getStatusCode(),
			'GET /stock/entry/{entryId} for an unknown id must answer the documented 400, not 200 with a null body'
		);
		$body = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsString($body['error_message'] ?? null, 'the 400 body must carry a non-empty error_message');
		self::assertNotSame('', $body['error_message']);
		self::assertMatchesSchema($body, 'GET /stock/entry/{entryId} -> 400', component: 'Error400');

		// Regression: a real id must still answer 200 with the entry, unaffected by the fix.
		$location = self::insertRow('locations', ['name' => 'Stock Entry Shelf']);
		$otherLocation = self::insertRow('locations', ['name' => 'Stock Entry Shopping Location']);
		$qu = self::insertRow('quantity_units', ['name' => 'Stock Entry Unit']);
		$productId = self::insertRow('products', [
			'name' => 'Stock Entry Product',
			'description' => 'Seeded for the stock entry test',
			'location_id' => $location,
			'qu_id_purchase' => $qu,
			'qu_id_stock' => $qu,
		]);
		$entryId = self::insertRow('stock', [
			'product_id' => $productId,
			'amount' => 3,
			'stock_id' => 'roundtrip-stock-entry-1',
			'location_id' => $location,
			// Documented non-nullable on StockEntry (WireContractTest::UNION_NULLABILITY_FAILURES
			// records 'stock' => ['StockEntry' => ['shopping_location_id']] as a separate, known
			// gap) - set explicitly so this assertion is not tripped by that unrelated defect.
			'shopping_location_id' => $otherLocation,
		]);

		$validResponse = $stockController->StockEntry(self::request(), new Response(), ['entryId' => $entryId]);
		self::assertSame(200, $validResponse->getStatusCode());
		$validBody = json_decode((string)$validResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame($entryId, $validBody['id']);
		self::assertSame($productId, $validBody['product_id']);

		// Not Opis-validated against StockEntry here, for the same reason
		// testReadEditWriteRoundTripSucceedsAndPersistsTheEdit() does not validate against
		// Product: StockEntry types several nullable columns (best_before_date,
		// purchased_date, price, note, ...) as a plain non-nullable scalar, the same class of
		// gap WireContractTest::UNION_NULLABILITY_FAILURES already tracks for this entity's
		// shopping_location_id - just not every instance of it. Unrelated to H10 and not this
		// test's to fix; the id/product_id assertions above are the regression this test needs.
	}
}
