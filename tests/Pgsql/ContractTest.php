<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\BatteriesApiController;
use Victual\Controllers\Api\CalendarApiController;
use Victual\Controllers\Api\ChoresApiController;
use Victual\Controllers\Api\FilesApiController;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Api\PrintApiController;
use Victual\Controllers\Api\RecipesApiController;
use Victual\Controllers\Api\RolesApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Controllers\Api\SystemApiController;
use Victual\Controllers\Api\TasksApiController;
use Victual\Controllers\Api\UsersApiController;
use Victual\Tests\Support\JsonShape;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\RouteInventory;

/**
 * Plan 14 piece 2 (issue #83): the response-contract snapshot ADR-0025 decision 4 says is
 * written in tier 1 from the start. Piece 2b (growing the read surface before freezing it)
 * is already done - plans 28, 31 and 19 piece 2 all landed - so this is the freeze itself.
 *
 * What this class does, roughly in order:
 *  1. testRouteTableMatchesSpecification(): the live Slim route table against
 *     victual.openapi.json, both directions, over every operation - no fixtures needed.
 *  2. testSystemConfigRetainsFeatureFlagStock(): R1, the one regression check the issue
 *     names by number.
 *  3. A chain of testCreates*()/test*Operations() methods (#[Depends] in the order they
 *     run) builds one fixture graph as Admin (user 9000, ADMIN role) by calling the real
 *     write endpoints - so the writes are exercised too, not only the reads that follow -
 *     and records every call's JSON key set and scalar type into self::$adminSnapshot as
 *     it goes.
 *  4. testGenericEntitySweepAsAdmin(): every readable ExposedEntity's GET /objects/{entity}
 *     (and /{objectId}, /userfields/{entity}/{objectId} for entities the graph populated),
 *     also recorded into self::$adminSnapshot.
 *  5. testAdminSnapshotMatchesGolden(): the finished Admin snapshot compared against
 *     tests/Pgsql/snapshots/contract-admin.json.
 *  6. testRestrictedSweepMatchesGolden(): the exact same read-only sweep run again as the
 *     CHILD role, against tests/Pgsql/snapshots/contract-restricted.json.
 *  7. testRestrictedMatchesAdminMinusRedactedFields(): the two snapshots compared to each
 *     other - restricted equals Admin minus exactly the fields permission_fields redacts
 *     for CHILD, cross-checked against the live permission_fields table rather than a
 *     hand-maintained list, and no reachable route answers CHILD anything other than 200
 *     or 403.
 *  8. testSensitiveFieldVocabularyIsClassified(): every price/cost/value/amount_paid-shaped
 *     key recorded in the Admin snapshot, and every OpenAPI schema property matching the
 *     same vocabulary, carries either an x-visibility annotation or a permission_fields
 *     row - the completeness leg, which step 7 is structurally blind to because an
 *     unclassified field looks identical for both identities.
 *
 * What is deliberately not called: the operations behind the label pairing/worker/
 * renderer/template-admin routes (LabelWorkerApiController, LabelPrintersApiController,
 * LabelRenderApiController, LabelTemplatesApiController, plus LabelsApiController's own
 * print/context/resolve operations) and the thirteen label-subsystem ExposedEntity rows
 * they back (label_workers, label_drivers, label_worker_capabilities, label_printers,
 * label_printer_status, print_jobs, print_attempts, print_evidence, label_templates,
 * label_template_versions, label_assets, label_media_profiles, label_render_requests).
 * They need real device credentials, paired worker crypto material or a rendered artifact
 * this harness has no way to manufacture - the same reason plan 25/27's physical print
 * verification runs on a real printer rather than in any phase here. Step 1's route/spec
 * parity still covers their wire shape at the path level. StockApiController's external
 * barcode lookup is excluded for a different reason - it calls out to a configured
 * plugin/network endpoint, which is exactly the SSRF-shaped surface sweep finding S14
 * already flags, and a contract test is not the place to give it a target.
 *
 * The "engine vs engine" leg plan 14 originally described is not attempted: ADR-0008
 * retired SQLite as a runtime engine, so there is only one engine left to boot the
 * application on. The differential harness's SQLite side, kept alive by that record's
 * option C precisely until this piece lands, is what used to stand in for it.
 *
 * The restricted identity is the existing CHILD role (db/pgsql/roles-seed.sql) rather
 * than a bespoke fixture: CHILD already holds exactly the shape plan 14 piece 2 asks for
 * - STOCK_VIEW, STOCK_CONSUME, STOCK_OPEN and the other domain *_VIEW leaves, never bare
 * STOCK or STOCK_PURCHASE - so it cannot inherit STOCK_PRICES_VIEW (which hangs under
 * STOCK_PURCHASE) the way a parent-holder would, and the restricted leg would prove
 * nothing. Inventing a second fixture that states the identical grant would only be able
 * to duplicate CHILD's own shape under a different name.
 *
 * Regenerating a golden file: CONTRACT_REGEN=1 .devtools/pgsql/run-tests.sh contract
 * writes tests/Pgsql/snapshots/contract-{admin,restricted}.json from what this run
 * actually produced instead of comparing against them, so a deliberate contract change
 * updates them by running the suite rather than by hand-editing JSON.
 */
class ContractTest extends PgsqlSchemaTestCase
{
	/** ExposedEntity rows the label subsystem owns - excluded from live invocation, see class docblock. */
	private const LABEL_EXCLUDED_ENTITIES = [
		'label_workers', 'label_drivers', 'label_worker_capabilities', 'label_printers',
		'label_printer_status', 'print_jobs', 'print_attempts', 'print_evidence',
		'label_templates', 'label_template_versions', 'label_assets', 'label_media_profiles',
		'label_render_requests',
	];

	/** Field names, matched case-insensitively as whole path segments, that Leg 8 must find classified. */
	private const SENSITIVE_FIELD_PATTERN = '/(^|_)(price|cost|value|amount_paid)($|_|s$)/i';

	/** Sensitive-vocabulary matches that are not a redaction gap - see testSensitiveFieldVocabularyIsClassified(). */
	private const SENSITIVE_FIELD_ALLOWED_EXCEPTIONS = [
		// "value" alone, not price/cost-shaped, on non-monetary settings/config surfaces.
		'system/config' => ['value'],
		'user/settings/{settingKey}' => ['value'],
		'user/settings' => ['value'],
		'userfields/{entity}/{objectId}' => ['value'],
		'objects/userfields' => ['value'],
		'objects/userfields/{objectId}' => ['value'],
	];

	/**
	 * Field names the vocabulary regex matches but that are not themselves a monetary
	 * amount, wherever they appear - verified against services/StockService.php and the
	 * cost-formula migrations (0087 onward) rather than assumed from the name:
	 *  - qu_id_price: the quantity_units foreign key prices are quoted in, an id.
	 *  - default_purchase_price_type: a smallint mode flag (StockService::$product), not
	 *    an amount.
	 *  - quantity_unit_price: the resolved quantity_units row named by qu_id_price
	 *    (StockService.php:1415/1446) - an object (name, plural forms), not a number.
	 *  - qu_conversion_factor_price_to_stock: a unit-conversion ratio.
	 *  - price_factor (recipes_pos): a dimensionless multiplier (REAL, migration 0087)
	 *    a cost formula applies to an actual price elsewhere - not an amount on its own,
	 *    and not gated by db/pgsql/prices-seed.sql, which redacts recipes_pos_resolved's
	 *    computed costs instead.
	 *  - stock_auto_decimal_separator_prices: a display-formatting preference (which
	 *    decimal separator to render prices with), not a value.
	 */
	private const SENSITIVE_FIELD_NAME_EXCEPTIONS = [
		'qu_id_price', 'default_purchase_price_type', 'quantity_unit_price',
		'qu_conversion_factor_price_to_stock', 'price_factor', 'stock_auto_decimal_separator_prices',
		// A userfield's own configured value, of whatever type the field definition
		// says (text, number, date, link, checkbox) - not a monetary amount, and the
		// "_value$" tail of the vocabulary regex is what catches it.
		'default_value',
	];


	private static PDO $db;
	private static \DI\Container $container;
	private static array $ControllerCache = [];

	/** @var array<string, array{status:int, shape:mixed}> */
	private static array $adminSnapshot = [];
	/** @var array<string, array{status:int, shape:mixed}> */
	private static array $restrictedSnapshot = [];
	/** @var array<string, mixed> Decoded Admin response bodies, keyed the same as $adminSnapshot - kept for the redaction/vocabulary legs, which need real values, not just shapes. */
	private static array $adminBodies = [];
	/** @var array<string, mixed> */
	private static array $restrictedBodies = [];

	/** @var array<string, int> Fixture ids created while building the graph. */
	private static array $ids = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'contract-caller', 'fixture')");
	}

	// ------------------------------------------------------------------------------
	// Small helpers shared by every section below.
	// ------------------------------------------------------------------------------

	private static function controller(string $class)
	{
		return self::$ControllerCache[$class] ??= new $class(self::$container);
	}

	private static function request(string $method = 'GET', $body = null, array $query = [])
	{
		$uri = 'http://localhost/api' . (empty($query) ? '' : ('?' . http_build_query($query)));
		$request = (new ServerRequestFactory())->createServerRequest($method, $uri);
		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}
		return $request;
	}

	private static function requestWithRawBody(string $method, string $bytes, string $contentType)
	{
		$stream = (new StreamFactory())->createStream($bytes);
		return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')
			->withBody($stream)
			->withHeader('Content-Type', $contentType);
	}

	private static function roleId(string $code): int
	{
		$stmt = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$stmt->execute([$code]);
		return (int)$stmt->fetchColumn();
	}

	private static function grantAdmin(): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . self::roleId('ADMIN') . ')');
	}

	private static function grantChild(): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . self::roleId('CHILD') . ')');
	}

	/**
	 * Calls a controller method, decodes the JSON response body (if any), records its
	 * status and JsonShape into $snapshot/$bodies under $key, and returns the decoded
	 * body so the caller can pull a created_object_id etc. out of it. Non-JSON bodies
	 * (the thermal print route) are recorded with a content-type marker instead of a
	 * failed json_decode.
	 */
	private static function invoke(array &$snapshot, array &$bodies, string $key, callable $call)
	{
		// Calling controller methods directly (RbacTest's own pattern) skips Slim's
		// error middleware, so a permission refusal thrown above a controller's own
		// HandleApiCall() - User::CheckPermission() called before it, the usual shape -
		// reaches here as a real PHP exception rather than a response. Recovered into a
		// response the same way RbacTest::expectStatus() does, so a route CHILD may not
		// call becomes a recorded 403 instead of an uncaught exception failing the run.
		try
		{
			$response = $call();
		}
		catch (\Slim\Exception\HttpException $ex)
		{
			$response = (new Response())->withStatus($ex->getCode());
			$response->getBody()->write(json_encode(['error_message' => $ex->getMessage()]));
		}

		$status = $response->getStatusCode();
		$rawBody = (string)$response->getBody();
		$contentType = $response->getHeaderLine('Content-Type');

		if ($rawBody === '')
		{
			$decoded = null;
			$shape = 'empty';
		}
		elseif (str_contains($contentType, 'json') || ($decoded = json_decode($rawBody, true)) !== null || $rawBody === 'null')
		{
			$decoded = json_decode($rawBody, true);
			if (json_last_error() !== JSON_ERROR_NONE)
			{
				$shape = ['__non_json__' => true, 'content_type' => $contentType];
				$decoded = null;
			}
			else
			{
				$shape = JsonShape::Of($decoded);
			}
		}
		else
		{
			$decoded = null;
			$shape = ['__non_json__' => true, 'content_type' => $contentType];
		}

		$snapshot[$key] = ['status' => $status, 'shape' => $shape];
		$bodies[$key] = $decoded;

		return $decoded;
	}

	private static function invokeAdmin(string $key, callable $call)
	{
		return self::invoke(self::$adminSnapshot, self::$adminBodies, $key, $call);
	}

	// ------------------------------------------------------------------------------
	// 1. Route table <-> OpenAPI specification parity (issue #83's own gap, plus the
	//    two-way assertion plan 14 piece 2 asks for so the next one in either direction
	//    cannot survive - the run-tests.sh README's "a route silently dropped ... is
	//    exactly what happened here by hand").
	// ------------------------------------------------------------------------------

	public function testRouteTableMatchesSpecification(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		$specOperations = [];
		foreach ($spec['paths'] as $path => $methods)
		{
			foreach (array_keys($methods) as $method)
			{
				$specOperations[] = strtoupper($method) . ' /api' . $path;
			}
		}

		$routeOperations = array_map(fn($o) => $o->Key(), RouteInventory::Api());

		sort($specOperations);
		sort($routeOperations);

		$onlyInRoutes = array_values(array_diff($routeOperations, $specOperations));
		$onlyInSpec = array_values(array_diff($specOperations, $routeOperations));

		self::assertSame([], $onlyInRoutes, 'Routes registered in routes.php but missing from victual.openapi.json: ' . implode(', ', $onlyInRoutes));
		self::assertSame([], $onlyInSpec, 'Paths documented in victual.openapi.json with no route behind them: ' . implode(', ', $onlyInSpec));

		// The extractor is a thing that can be wrong (plan 14's own lesson from the one
		// capitalised $group->Post() route): assert the count too, so a route silently
		// dropped by RouteInventory doesn't produce an empty, vacuously-passing diff.
		self::assertGreaterThan(100, count($routeOperations), 'Sanity: the /api route table looks implausibly small - RouteInventory likely failed to see most of routes.php');
	}

	// ------------------------------------------------------------------------------
	// 2. R1 - the regression check the issue names by number.
	// ------------------------------------------------------------------------------

	public function testSystemConfigRetainsFeatureFlagStock(): void
	{
		self::grantAdmin();
		$response = self::controller(SystemApiController::class)->GetConfig(self::request(), new Response(), []);
		$body = json_decode((string)$response->getBody(), true);
		self::assertSame(200, $response->getStatusCode());
		self::assertArrayHasKey('FEATURE_FLAG_STOCK', $body, '/system/config must keep reporting FEATURE_FLAG_STOCK - a client (and the "the flag is not the permission" S31 rule) depends on this key existing');
	}

	// ------------------------------------------------------------------------------
	// 3. Building the fixture graph as Admin. Each method both writes real data through
	//    the real endpoints (so the write itself is recorded) and stashes the ids the
	//    later read sweep needs.
	// ------------------------------------------------------------------------------

	#[Depends('testSystemConfigRetainsFeatureFlagStock')]
	public function testCreatesMasterData(): void
	{
		self::grantAdmin();
		$generic = self::controller(GenericEntityApiController::class);

		$createId = function (string $entity, array $body) use ($generic): int
		{
			$response = self::invokeAdmin('POST /api/objects/{entity} (' . $entity . ')', fn() => $generic->AddObject(self::request('POST', $body), new Response(), ['entity' => $entity]));
			return (int)$response['created_object_id'];
		};

		self::$ids['location'] = $createId('locations', ['name' => 'Contract Pantry']);
		self::$ids['location_2'] = $createId('locations', ['name' => 'Contract Freezer']);
		self::$ids['shopping_location'] = $createId('shopping_locations', ['name' => 'Contract Grocer']);
		self::$ids['product_group'] = $createId('product_groups', ['name' => 'Contract Group']);

		// Piece (id 2) is part of the default quantity units seed (migrations/0006.sql).
		self::$ids['product'] = $createId('products', [
			'name' => 'Contract Product',
			'location_id' => self::$ids['location'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'product_group_id' => self::$ids['product_group'],
		]);
		self::$ids['product_spare'] = $createId('products', [
			'name' => 'Contract Product Spare',
			'location_id' => self::$ids['location'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
		]);

		self::invokeAdmin('PUT /api/objects/{entity}/{objectId} (products)', fn() => $generic->EditObject(self::request('PUT', ['description' => 'Edited by the contract snapshot']), new Response(), ['entity' => 'products', 'objectId' => self::$ids['product']]));

		// A real last_price, not null, so the Admin-vs-restricted leg can actually observe
		// whether it survives redaction on every route that embeds a barcode row - not only
		// the ones that read product_barcodes as its own entity.
		self::$ids['barcode'] = $createId('product_barcodes', ['product_id' => self::$ids['product'], 'barcode' => '4006381333931', 'last_price' => 2.5]);

		self::$ids['substitution_target'] = $createId('products', [
			'name' => 'Contract Substitute',
			'location_id' => self::$ids['location'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
		]);
		$createId('product_substitutions', ['from_product_id' => self::$ids['product'], 'to_product_id' => self::$ids['substitution_target']]);

		self::assertGreaterThan(0, self::$ids['product'], 'Fixture product created');
	}

	#[Depends('testCreatesMasterData')]
	public function testStockOperations(): void
	{
		$stock = self::controller(StockApiController::class);
		$productId = self::$ids['product'];

		self::invokeAdmin('POST /api/stock/products/{productId}/add', fn() => $stock->AddProduct(self::request('POST', ['amount' => 10, 'price' => 2.5, 'best_before_date' => '2030-01-01']), new Response(), ['productId' => $productId]));
		self::invokeAdmin('GET /api/stock', fn() => $stock->CurrentStock(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/stock/volatile', fn() => $stock->CurrentVolatileStock(self::request(), new Response(), []));
		$entries = self::invokeAdmin('GET /api/stock/products/{productId}/entries', fn() => $stock->ProductStockEntries(self::request(), new Response(), ['productId' => $productId]));
		self::$ids['stock_entry'] = (int)$entries[0]['id'];
		self::invokeAdmin('GET /api/stock/entry/{entryId}', fn() => $stock->StockEntry(self::request(), new Response(), ['entryId' => self::$ids['stock_entry']]));
		self::invokeAdmin('PUT /api/stock/entry/{entryId}', fn() => $stock->EditStockEntry(self::request('PUT', ['note' => 'contract note']), new Response(), ['entryId' => self::$ids['stock_entry']]));
		self::invokeAdmin('GET /api/stock/products/{productId}', fn() => $stock->ProductDetails(self::request(), new Response(), ['productId' => $productId]));
		self::invokeAdmin('GET /api/stock/products/{productId}/locations', fn() => $stock->ProductStockLocations(self::request(), new Response(), ['productId' => $productId]));
		self::invokeAdmin('GET /api/stock/products/{productId}/price-history', fn() => $stock->ProductPriceHistory(self::request(), new Response(), ['productId' => $productId]));
		self::invokeAdmin('GET /api/stock/locations/{locationId}/entries', fn() => $stock->LocationStockEntries(self::request(), new Response(), ['locationId' => self::$ids['location']]));

		self::invokeAdmin('POST /api/stock/products/{productId}/open', fn() => $stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $productId]));
		self::invokeAdmin('POST /api/stock/products/{productId}/transfer', fn() => $stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$ids['location'], 'location_id_to' => self::$ids['location_2']]), new Response(), ['productId' => $productId]));
		self::invokeAdmin('POST /api/stock/products/{productId}/inventory', fn() => $stock->InventoryProduct(self::request('POST', ['new_amount' => 8, 'location_id' => self::$ids['location']]), new Response(), ['productId' => $productId]));
		self::invokeAdmin('POST /api/stock/products/{productId}/consume', fn() => $stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $productId]));

		self::invokeAdmin('POST /api/stock/products/{productIdToKeep}/merge/{productIdToRemove}', fn() => $stock->MergeProducts(self::request('POST'), new Response(), ['productIdToKeep' => $productId, 'productIdToRemove' => self::$ids['product_spare']]));

		// by-barcode variants, reusing the barcode created in testCreatesMasterData().
		self::invokeAdmin('GET /api/stock/products/by-barcode/{barcode}', fn() => $stock->ProductDetailsByBarcode(self::request(), new Response(), ['barcode' => '4006381333931']));
		self::invokeAdmin('POST /api/stock/products/by-barcode/{barcode}/add', fn() => $stock->AddProductByBarcode(self::request('POST', ['amount' => 2]), new Response(), ['barcode' => '4006381333931']));
		self::invokeAdmin('POST /api/stock/products/by-barcode/{barcode}/consume', fn() => $stock->ConsumeProductByBarcode(self::request('POST', ['amount' => 1]), new Response(), ['barcode' => '4006381333931']));
		self::invokeAdmin('POST /api/stock/products/by-barcode/{barcode}/inventory', fn() => $stock->InventoryProductByBarcode(self::request('POST', ['new_amount' => 5]), new Response(), ['barcode' => '4006381333931']));
		self::invokeAdmin('POST /api/stock/products/by-barcode/{barcode}/open', fn() => $stock->OpenProductByBarcode(self::request('POST', ['amount' => 1]), new Response(), ['barcode' => '4006381333931']));
		self::invokeAdmin('POST /api/stock/products/by-barcode/{barcode}/transfer', fn() => $stock->TransferProductByBarcode(self::request('POST', ['amount' => 1, 'location_id_from' => self::$ids['location'], 'location_id_to' => self::$ids['location_2']]), new Response(), ['barcode' => '4006381333931']));

		// A second, throwaway booking/transaction pair purely so the undo endpoints have
		// something to undo without disturbing the ids the rest of this class reads.
		// AddProduct answers with the stock_log rows of the transaction it just booked
		// (it delegates to StockTransactions()), not a single object.
		$undoBooking = self::invokeAdmin('POST /api/stock/products/{productId}/add (for undo)', fn() => $stock->AddProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $productId]));
		self::$ids['booking'] = (int)($undoBooking[0]['id'] ?? 0);
		self::invokeAdmin('GET /api/stock/bookings/{bookingId}', fn() => $stock->StockBooking(self::request(), new Response(), ['bookingId' => self::$ids['booking']]));
		self::invokeAdmin('POST /api/stock/bookings/{bookingId}/undo', fn() => $stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => self::$ids['booking']]));

		$undoConsume = self::invokeAdmin('POST /api/stock/products/{productId}/consume (for undo)', fn() => $stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $productId]));
		self::$ids['transaction'] = $undoConsume[0]['transaction_id'] ?? null;
		if (self::$ids['transaction'] !== null)
		{
			self::invokeAdmin('GET /api/stock/transactions/{transactionId}', fn() => $stock->StockTransactions(self::request(), new Response(), ['transactionId' => self::$ids['transaction']]));
			self::invokeAdmin('POST /api/stock/transactions/{transactionId}/undo', fn() => $stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => self::$ids['transaction']]));
		}

		// Shopping list actions.
		self::invokeAdmin('POST /api/stock/shoppinglist/add-product', fn() => $stock->AddProductToShoppingList(self::request('POST', ['product_id' => $productId, 'product_amount' => 3]), new Response(), []));
		self::invokeAdmin('GET /api/objects/{entity} (shopping_list)', fn() => self::controller(GenericEntityApiController::class)->GetObjects(self::request(), new Response(), ['entity' => 'shopping_list']));
		self::invokeAdmin('POST /api/stock/shoppinglist/add-missing-products', fn() => $stock->AddMissingProductsToShoppingList(self::request('POST', []), new Response(), []));
		self::invokeAdmin('POST /api/stock/shoppinglist/add-overdue-products', fn() => $stock->AddOverdueProductsToShoppingList(self::request('POST', []), new Response(), []));
		self::invokeAdmin('POST /api/stock/shoppinglist/add-expired-products', fn() => $stock->AddExpiredProductsToShoppingList(self::request('POST', []), new Response(), []));
		self::invokeAdmin('POST /api/stock/shoppinglist/remove-product', fn() => $stock->RemoveProductFromShoppingList(self::request('POST', ['product_id' => $productId, 'product_amount' => 1]), new Response(), []));
		self::invokeAdmin('POST /api/stock/shoppinglist/clear', fn() => $stock->ClearShoppingList(self::request('POST', []), new Response(), []));

		self::assertArrayHasKey('stock_entry', self::$ids, 'At least one stock entry exists for the rest of the suite to read');
	}

	#[Depends('testStockOperations')]
	public function testRecipesOperations(): void
	{
		$generic = self::controller(GenericEntityApiController::class);
		$recipes = self::controller(RecipesApiController::class);

		$recipeResponse = self::invokeAdmin('POST /api/objects/{entity} (recipes)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Recipe', 'desired_servings' => 1]), new Response(), ['entity' => 'recipes']));
		self::$ids['recipe'] = (int)$recipeResponse['created_object_id'];

		$posResponse = self::invokeAdmin('POST /api/objects/{entity} (recipes_pos)', fn() => $generic->AddObject(self::request('POST', [
			'recipe_id' => self::$ids['recipe'],
			'product_id' => self::$ids['product'],
			'amount' => 1,
			// Consumption below should not depend on exactly how much stock remains
			// after the stock section's own arithmetic.
			'not_check_stock_fulfillment' => 1,
		]), new Response(), ['entity' => 'recipes_pos']));
		self::$ids['recipe_pos'] = (int)$posResponse['created_object_id'];

		self::invokeAdmin('GET /api/recipes/{recipeId}/fulfillment', fn() => $recipes->GetRecipeFulfillment(self::request(), new Response(), ['recipeId' => self::$ids['recipe']]));
		self::invokeAdmin('GET /api/recipes/fulfillment', fn() => $recipes->GetRecipeFulfillment(self::request(), new Response(), []));
		self::invokeAdmin('POST /api/recipes/{recipeId}/add-not-fulfilled-products-to-shoppinglist', fn() => $recipes->AddNotFulfilledProductsToShoppingList(self::request('POST', []), new Response(), ['recipeId' => self::$ids['recipe']]));
		self::invokeAdmin('POST /api/recipes/{recipeId}/copy', fn() => $recipes->CopyRecipe(self::request('POST'), new Response(), ['recipeId' => self::$ids['recipe']]));
		self::invokeAdmin('POST /api/recipes/{recipeId}/consume', fn() => $recipes->ConsumeRecipe(self::request('POST'), new Response(), ['recipeId' => self::$ids['recipe']]));

		self::assertGreaterThan(0, self::$ids['recipe'], 'Fixture recipe created');
	}

	#[Depends('testRecipesOperations')]
	public function testChoresOperations(): void
	{
		$generic = self::controller(GenericEntityApiController::class);
		$chores = self::controller(ChoresApiController::class);

		$choreResponse = self::invokeAdmin('POST /api/objects/{entity} (chores)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Chore', 'period_type' => 'manually']), new Response(), ['entity' => 'chores']));
		self::$ids['chore'] = (int)$choreResponse['created_object_id'];
		$choreSpareResponse = self::invokeAdmin('POST /api/objects/{entity} (chores, spare)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Chore Spare', 'period_type' => 'manually']), new Response(), ['entity' => 'chores']));
		self::$ids['chore_spare'] = (int)$choreSpareResponse['created_object_id'];

		self::invokeAdmin('GET /api/chores', fn() => $chores->Current(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/chores/{choreId}', fn() => $chores->ChoreDetails(self::request(), new Response(), ['choreId' => self::$ids['chore']]));

		$execution = self::invokeAdmin('POST /api/chores/{choreId}/execute', fn() => $chores->TrackChoreExecution(self::request('POST', []), new Response(), ['choreId' => self::$ids['chore']]));
		self::$ids['chore_execution'] = (int)($execution['id'] ?? 0);

		self::invokeAdmin('POST /api/chores/executions/calculate-next-assignments', fn() => $chores->CalculateNextExecutionAssignments(self::request('POST', ['chore_id' => self::$ids['chore']]), new Response(), []));

		$secondExecution = self::invokeAdmin('POST /api/chores/{choreId}/execute (for undo)', fn() => $chores->TrackChoreExecution(self::request('POST', []), new Response(), ['choreId' => self::$ids['chore']]));
		self::invokeAdmin('POST /api/chores/executions/{executionId}/undo', fn() => $chores->UndoChoreExecution(self::request('POST'), new Response(), ['executionId' => (int)($secondExecution['id'] ?? 0)]));

		self::invokeAdmin('POST /api/chores/{choreIdToKeep}/merge/{choreIdToRemove}', fn() => $chores->MergeChores(self::request('POST'), new Response(), ['choreIdToKeep' => self::$ids['chore'], 'choreIdToRemove' => self::$ids['chore_spare']]));

		self::assertGreaterThan(0, self::$ids['chore'], 'Fixture chore created');
	}

	#[Depends('testChoresOperations')]
	public function testBatteriesOperations(): void
	{
		$generic = self::controller(GenericEntityApiController::class);
		$batteries = self::controller(BatteriesApiController::class);

		$batteryResponse = self::invokeAdmin('POST /api/objects/{entity} (batteries)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Battery']), new Response(), ['entity' => 'batteries']));
		self::$ids['battery'] = (int)$batteryResponse['created_object_id'];

		self::invokeAdmin('GET /api/batteries', fn() => $batteries->Current(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/batteries/{batteryId}', fn() => $batteries->BatteryDetails(self::request(), new Response(), ['batteryId' => self::$ids['battery']]));

		$cycle = self::invokeAdmin('POST /api/batteries/{batteryId}/charge', fn() => $batteries->TrackChargeCycle(self::request('POST', []), new Response(), ['batteryId' => self::$ids['battery']]));
		$secondCycle = self::invokeAdmin('POST /api/batteries/{batteryId}/charge (for undo)', fn() => $batteries->TrackChargeCycle(self::request('POST', []), new Response(), ['batteryId' => self::$ids['battery']]));
		self::invokeAdmin('POST /api/batteries/charge-cycles/{chargeCycleId}/undo', fn() => $batteries->UndoChargeCycle(self::request('POST'), new Response(), ['chargeCycleId' => (int)($secondCycle['id'] ?? 0)]));

		self::assertGreaterThan(0, self::$ids['battery'], 'Fixture battery created');
	}

	#[Depends('testBatteriesOperations')]
	public function testTasksOperations(): void
	{
		$generic = self::controller(GenericEntityApiController::class);
		$tasks = self::controller(TasksApiController::class);

		$categoryResponse = self::invokeAdmin('POST /api/objects/{entity} (task_categories)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Task Category']), new Response(), ['entity' => 'task_categories']));
		self::$ids['task_category'] = (int)$categoryResponse['created_object_id'];

		$taskResponse = self::invokeAdmin('POST /api/objects/{entity} (tasks)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Task', 'category_id' => self::$ids['task_category']]), new Response(), ['entity' => 'tasks']));
		self::$ids['task'] = (int)$taskResponse['created_object_id'];
		$taskSpareResponse = self::invokeAdmin('POST /api/objects/{entity} (tasks, spare)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Task Spare']), new Response(), ['entity' => 'tasks']));
		self::$ids['task_spare'] = (int)$taskSpareResponse['created_object_id'];

		self::invokeAdmin('GET /api/tasks', fn() => $tasks->Current(self::request(), new Response(), []));
		self::invokeAdmin('POST /api/tasks/{taskId}/complete', fn() => $tasks->MarkTaskAsCompleted(self::request('POST', []), new Response(), ['taskId' => self::$ids['task_spare']]));
		self::invokeAdmin('POST /api/tasks/{taskId}/undo', fn() => $tasks->UndoTask(self::request('POST'), new Response(), ['taskId' => self::$ids['task_spare']]));

		$equipmentResponse = self::invokeAdmin('POST /api/objects/{entity} (equipment)', fn() => $generic->AddObject(self::request('POST', ['name' => 'Contract Equipment']), new Response(), ['entity' => 'equipment']));
		self::$ids['equipment'] = (int)$equipmentResponse['created_object_id'];

		self::assertGreaterThan(0, self::$ids['task'], 'Fixture task created');
	}

	#[Depends('testTasksOperations')]
	public function testUsersAndRolesOperations(): void
	{
		$users = self::controller(UsersApiController::class);
		$roles = self::controller(RolesApiController::class);

		// CreateUser answers 204 with no body (unlike the generic entity controller's
		// created_object_id shape), so the new row's id is read back by the one thing
		// that is guaranteed unique - the username just written.
		self::invokeAdmin('POST /api/users', fn() => $users->CreateUser(self::request('POST', ['username' => 'contract-fixture-user', 'password' => 'fixture password']), new Response(), []));
		$stmt = self::$db->prepare('SELECT id FROM users WHERE username = ?');
		$stmt->execute(['contract-fixture-user']);
		self::$ids['user'] = (int)$stmt->fetchColumn();

		self::invokeAdmin('GET /api/users', fn() => $users->GetUsers(self::request(), new Response(), []));
		self::invokeAdmin('PUT /api/users/{userId}', fn() => $users->EditUser(self::request('PUT', ['first_name' => 'Contract']), new Response(), ['userId' => self::$ids['user']]));
		self::invokeAdmin('GET /api/users/{userId}/permissions', fn() => $users->ListPermissions(self::request(), new Response(), ['userId' => self::$ids['user']]));
		self::invokeAdmin('POST /api/users/{userId}/permissions', fn() => $users->AddPermission(self::request('POST', ['permission_id' => 1]), new Response(), ['userId' => self::$ids['user']]));

		self::invokeAdmin('GET /api/roles', fn() => $roles->ListRoles(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/roles/{roleId}/permissions', fn() => $roles->ListPermissions(self::request(), new Response(), ['roleId' => self::roleId('CHILD')]));
		self::invokeAdmin('GET /api/users/{userId}/roles', fn() => $roles->ListUserRoles(self::request(), new Response(), ['userId' => self::$ids['user']]));
		self::invokeAdmin('PUT /api/users/{userId}/roles', fn() => $roles->SetUserRoles(self::request('PUT', ['roles' => [self::roleId('GUEST')]]), new Response(), ['userId' => self::$ids['user']]));

		self::invokeAdmin('GET /api/user', fn() => $users->CurrentUser(self::request(), new Response(), []));
		self::invokeAdmin('PUT /api/user/settings/{settingKey}', fn() => $users->SetUserSetting(self::request('PUT', ['value' => 'dark']), new Response(), ['settingKey' => 'contract_test_setting']));
		self::invokeAdmin('GET /api/user/settings', fn() => $users->GetUserSettings(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/user/settings/{settingKey}', fn() => $users->GetUserSetting(self::request(), new Response(), ['settingKey' => 'contract_test_setting']));
		self::invokeAdmin('DELETE /api/user/settings/{settingKey}', fn() => $users->DeleteUserSetting(self::request('DELETE'), new Response(), ['settingKey' => 'contract_test_setting']));

		self::assertGreaterThan(0, self::$ids['user'], 'Fixture user created');
	}

	#[Depends('testUsersAndRolesOperations')]
	public function testFilesAndSystemAndCalendarOperations(): void
	{
		$files = self::controller(FilesApiController::class);
		$system = self::controller(SystemApiController::class);
		$calendar = self::controller(CalendarApiController::class);
		$print = self::controller(PrintApiController::class);

		// equipmentmanuals is openly readable (GROUP_READ_PERMISSIONS => null) and
		// accepts pdf, so this needs no extra grant beyond MASTER_DATA_EDIT (Admin holds it).
		$pdfBytes = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
		$fileName = base64_encode('contract-manual.pdf');
		self::invokeAdmin('PUT /api/files/{group}/{fileName}', fn() => $files->UploadFile(self::requestWithRawBody('PUT', $pdfBytes, 'application/pdf'), new Response(), ['group' => 'equipmentmanuals', 'fileName' => $fileName]));
		self::invokeAdmin('GET /api/files/{group}/{fileName}', fn() => $files->ServeFile(self::request(), new Response(), ['group' => 'equipmentmanuals', 'fileName' => $fileName]));
		self::invokeAdmin('DELETE /api/files/{group}/{fileName}', fn() => $files->DeleteFile(self::request('DELETE'), new Response(), ['group' => 'equipmentmanuals', 'fileName' => $fileName]));

		self::invokeAdmin('GET /api/system/info', fn() => $system->GetSystemInfo(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/system/time', fn() => $system->GetSystemTime(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/system/db-changed-time', fn() => $system->GetDbChangedTime(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/system/localization-strings', fn() => $system->GetLocalizationStrings(self::request(), new Response(), []));
		self::invokeAdmin('POST /api/system/log-missing-localization', fn() => $system->LogMissingLocalization(self::request('POST', ['key' => 'contract.missing.key']), new Response(), []));

		self::invokeAdmin('GET /api/calendar/ical', fn() => $calendar->Ical(self::request(), new Response(), []));
		self::invokeAdmin('GET /api/calendar/ical/sharing-link', fn() => $calendar->IcalSharingLink(self::request(), new Response(), []));

		self::invokeAdmin('GET /api/print/shoppinglist/thermal', fn() => $print->PrintShoppingListThermal(self::request(), new Response(), []));

		self::assertSame(200, self::$adminSnapshot['GET /api/system/info']['status'], 'System info route answers while building the fixture graph');
	}

	// ------------------------------------------------------------------------------
	// 4. The generic-entity sweep: every ExposedEntity except the label subsystem,
	//    listed and (where the fixture graph made a real row) fetched by id.
	// ------------------------------------------------------------------------------

	#[Depends('testFilesAndSystemAndCalendarOperations')]
	public function testGenericEntitySweepAsAdmin(): void
	{
		$generic = self::controller(GenericEntityApiController::class);
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		$entities = array_diff($spec['components']['schemas']['ExposedEntity']['enum'], self::LABEL_EXCLUDED_ENTITIES);

		// Maps an entity name to a known-good row id from the fixture graph, where one exists.
		$knownIds = [
			'products' => self::$ids['product'] ?? null,
			'locations' => self::$ids['location'] ?? null,
			'locations_resolved' => self::$ids['location'] ?? null,
			'product_groups' => self::$ids['product_group'] ?? null,
			'product_groups_resolved' => self::$ids['product_group'] ?? null,
			'shopping_locations' => self::$ids['shopping_location'] ?? null,
			'product_barcodes' => self::$ids['barcode'] ?? null,
			'recipes' => self::$ids['recipe'] ?? null,
			'recipes_pos' => self::$ids['recipe_pos'] ?? null,
			'recipes_pos_resolved' => self::$ids['recipe_pos'] ?? null,
			'chores' => self::$ids['chore'] ?? null,
			'batteries' => self::$ids['battery'] ?? null,
			'tasks' => self::$ids['task'] ?? null,
			'task_categories' => self::$ids['task_category'] ?? null,
			'equipment' => self::$ids['equipment'] ?? null,
			'roles' => self::roleId('CHILD'),
			'stock' => self::$ids['stock_entry'] ?? null,
			'stock_log' => null,
		];

		foreach ($entities as $entity)
		{
			self::invokeAdmin('GET /api/objects/{entity} (' . $entity . ')', fn() => $generic->GetObjects(self::request(), new Response(), ['entity' => $entity]));

			if (!empty($knownIds[$entity]))
			{
				self::invokeAdmin('GET /api/objects/{entity}/{objectId} (' . $entity . ')', fn() => $generic->GetObject(self::request(), new Response(), ['entity' => $entity, 'objectId' => $knownIds[$entity]]));
			}
		}

		self::invokeAdmin('GET /api/userfields/{entity}/{objectId}', fn() => $generic->GetUserfields(self::request(), new Response(), ['entity' => 'products', 'objectId' => self::$ids['product']]));

		self::assertGreaterThan(40, count($entities), 'Sanity: most of ExposedEntity should still be swept once the label subsystem is excluded');
	}

	// ------------------------------------------------------------------------------
	// 5/6. Compare the finished snapshots against their committed golden files.
	// ------------------------------------------------------------------------------

	private static function snapshotPath(string $name): string
	{
		return VICTUAL_ROOT_PATH . '/tests/Pgsql/snapshots/' . $name;
	}

	/** @param array<string, array{status:int, shape:mixed}> $snapshot */
	private function assertMatchesGolden(array $snapshot, string $goldenFileName): void
	{
		ksort($snapshot);
		$actual = JsonShape::Encode($snapshot);
		$path = self::snapshotPath($goldenFileName);

		if (getenv('CONTRACT_REGEN') === '1')
		{
			file_put_contents($path, $actual);
			self::assertTrue(true);
			return;
		}

		self::assertFileExists($path, "No golden file at $path yet - run CONTRACT_REGEN=1 .devtools/pgsql/run-tests.sh contract once to create it, then review the diff before committing it.");
		$expected = file_get_contents($path);
		self::assertSame($expected, $actual, "$goldenFileName no longer matches what the application returns. If this is a deliberate contract change, regenerate it with CONTRACT_REGEN=1 .devtools/pgsql/run-tests.sh contract and review the diff before committing.");
	}

	#[Depends('testGenericEntitySweepAsAdmin')]
	public function testAdminSnapshotMatchesGolden(): void
	{
		$this->assertMatchesGolden(self::$adminSnapshot, 'contract-admin.json');
	}

	#[Depends('testAdminSnapshotMatchesGolden')]
	public function testRestrictedSweepMatchesGolden(): void
	{
		self::grantChild();

		foreach (self::$adminSnapshot as $key => $adminResult)
		{
			if (!str_starts_with($key, 'GET '))
			{
				// Only reads are safe to repeat under a second identity without disturbing
				// the fixture graph every later assertion in this class depends on.
				continue;
			}

			[$method, $path] = explode(' ', $key, 2);
			$call = self::routeCallForKey($key);
			if ($call === null)
			{
				continue;
			}

			self::invoke(self::$restrictedSnapshot, self::$restrictedBodies, $key, $call);
		}

		$this->assertMatchesGolden(self::$restrictedSnapshot, 'contract-restricted.json');
	}

	/**
	 * Re-dispatches a GET operation this class already ran as Admin, from its recorded
	 * key, for the restricted sweep. Kept as one place rather than threading a second
	 * "$call" closure through every invokeAdmin() call above.
	 */
	private static function routeCallForKey(string $key): ?callable
	{
		$generic = self::controller(GenericEntityApiController::class);
		$stock = self::controller(StockApiController::class);
		$recipes = self::controller(RecipesApiController::class);
		$chores = self::controller(ChoresApiController::class);
		$batteries = self::controller(BatteriesApiController::class);
		$tasks = self::controller(TasksApiController::class);
		$users = self::controller(UsersApiController::class);
		$roles = self::controller(RolesApiController::class);
		$system = self::controller(SystemApiController::class);
		$calendar = self::controller(CalendarApiController::class);
		$print = self::controller(PrintApiController::class);
		$files = self::controller(FilesApiController::class);

		$productId = self::$ids['product'];

		return match (true)
		{
			str_starts_with($key, 'GET /api/objects/{entity} (') => (function () use ($generic, $key)
			{
				$entity = rtrim(substr($key, strpos($key, '(') + 1), ')');
				return fn() => $generic->GetObjects(self::request(), new Response(), ['entity' => $entity]);
			})(),
			str_starts_with($key, 'GET /api/objects/{entity}/{objectId} (') => (function () use ($generic, $key)
			{
				$entity = rtrim(substr($key, strpos($key, '(') + 1), ')');
				$id = self::genericSweepKnownId($entity);
				return $id === null ? null : fn() => $generic->GetObject(self::request(), new Response(), ['entity' => $entity, 'objectId' => $id]);
			})(),
			$key === 'GET /api/userfields/{entity}/{objectId}' => fn() => $generic->GetUserfields(self::request(), new Response(), ['entity' => 'products', 'objectId' => $productId]),
			$key === 'GET /api/stock' => fn() => $stock->CurrentStock(self::request(), new Response(), []),
			$key === 'GET /api/stock/volatile' => fn() => $stock->CurrentVolatileStock(self::request(), new Response(), []),
			$key === 'GET /api/stock/entry/{entryId}' => fn() => $stock->StockEntry(self::request(), new Response(), ['entryId' => self::$ids['stock_entry']]),
			$key === 'GET /api/stock/products/{productId}' => fn() => $stock->ProductDetails(self::request(), new Response(), ['productId' => $productId]),
			$key === 'GET /api/stock/products/{productId}/entries' => fn() => $stock->ProductStockEntries(self::request(), new Response(), ['productId' => $productId]),
			$key === 'GET /api/stock/products/{productId}/locations' => fn() => $stock->ProductStockLocations(self::request(), new Response(), ['productId' => $productId]),
			$key === 'GET /api/stock/products/{productId}/price-history' => fn() => $stock->ProductPriceHistory(self::request(), new Response(), ['productId' => $productId]),
			$key === 'GET /api/stock/locations/{locationId}/entries' => fn() => $stock->LocationStockEntries(self::request(), new Response(), ['locationId' => self::$ids['location']]),
			$key === 'GET /api/stock/products/by-barcode/{barcode}' => fn() => $stock->ProductDetailsByBarcode(self::request(), new Response(), ['barcode' => '4006381333931']),
			$key === 'GET /api/stock/bookings/{bookingId}' => fn() => $stock->StockBooking(self::request(), new Response(), ['bookingId' => self::$ids['booking']]),
			$key === 'GET /api/recipes/{recipeId}/fulfillment' => fn() => $recipes->GetRecipeFulfillment(self::request(), new Response(), ['recipeId' => self::$ids['recipe']]),
			$key === 'GET /api/recipes/fulfillment' => fn() => $recipes->GetRecipeFulfillment(self::request(), new Response(), []),
			$key === 'GET /api/chores' => fn() => $chores->Current(self::request(), new Response(), []),
			$key === 'GET /api/chores/{choreId}' => fn() => $chores->ChoreDetails(self::request(), new Response(), ['choreId' => self::$ids['chore']]),
			$key === 'GET /api/batteries' => fn() => $batteries->Current(self::request(), new Response(), []),
			$key === 'GET /api/batteries/{batteryId}' => fn() => $batteries->BatteryDetails(self::request(), new Response(), ['batteryId' => self::$ids['battery']]),
			$key === 'GET /api/tasks' => fn() => $tasks->Current(self::request(), new Response(), []),
			$key === 'GET /api/users' => fn() => $users->GetUsers(self::request(), new Response(), []),
			$key === 'GET /api/users/{userId}/permissions' => fn() => $users->ListPermissions(self::request(), new Response(), ['userId' => self::$ids['user']]),
			$key === 'GET /api/roles' => fn() => $roles->ListRoles(self::request(), new Response(), []),
			$key === 'GET /api/roles/{roleId}/permissions' => fn() => $roles->ListPermissions(self::request(), new Response(), ['roleId' => self::roleId('CHILD')]),
			$key === 'GET /api/users/{userId}/roles' => fn() => $roles->ListUserRoles(self::request(), new Response(), ['userId' => self::$ids['user']]),
			$key === 'GET /api/user' => fn() => $users->CurrentUser(self::request(), new Response(), []),
			$key === 'GET /api/user/settings' => fn() => $users->GetUserSettings(self::request(), new Response(), []),
			$key === 'GET /api/files/{group}/{fileName}' => null, // deleted by the Admin sweep; nothing left to serve
			$key === 'GET /api/system/info' => fn() => $system->GetSystemInfo(self::request(), new Response(), []),
			$key === 'GET /api/system/time' => fn() => $system->GetSystemTime(self::request(), new Response(), []),
			$key === 'GET /api/system/db-changed-time' => fn() => $system->GetDbChangedTime(self::request(), new Response(), []),
			$key === 'GET /api/system/config' => fn() => $system->GetConfig(self::request(), new Response(), []),
			$key === 'GET /api/system/localization-strings' => fn() => $system->GetLocalizationStrings(self::request(), new Response(), []),
			$key === 'GET /api/calendar/ical' => fn() => $calendar->Ical(self::request(), new Response(), []),
			$key === 'GET /api/calendar/ical/sharing-link' => fn() => $calendar->IcalSharingLink(self::request(), new Response(), []),
			$key === 'GET /api/print/shoppinglist/thermal' => fn() => $print->PrintShoppingListThermal(self::request(), new Response(), []),
			default => null,
		};
	}

	private static function genericSweepKnownId(string $entity): ?int
	{
		$map = [
			'products' => self::$ids['product'] ?? null,
			'locations' => self::$ids['location'] ?? null,
			'locations_resolved' => self::$ids['location'] ?? null,
			'product_groups' => self::$ids['product_group'] ?? null,
			'product_groups_resolved' => self::$ids['product_group'] ?? null,
			'shopping_locations' => self::$ids['shopping_location'] ?? null,
			'product_barcodes' => self::$ids['barcode'] ?? null,
			'recipes' => self::$ids['recipe'] ?? null,
			'recipes_pos' => self::$ids['recipe_pos'] ?? null,
			'recipes_pos_resolved' => self::$ids['recipe_pos'] ?? null,
			'chores' => self::$ids['chore'] ?? null,
			'batteries' => self::$ids['battery'] ?? null,
			'tasks' => self::$ids['task'] ?? null,
			'task_categories' => self::$ids['task_category'] ?? null,
			'equipment' => self::$ids['equipment'] ?? null,
			'roles' => self::roleId('CHILD'),
			'stock' => self::$ids['stock_entry'] ?? null,
		];

		return $map[$entity] ?? null;
	}

	// ------------------------------------------------------------------------------
	// 7. Admin vs restricted: restricted must equal Admin minus exactly the fields
	//    permission_fields redacts for CHILD, cross-checked against the live table
	//    rather than a hand-maintained list of what "should" be gated.
	// ------------------------------------------------------------------------------

	#[Depends('testRestrictedSweepMatchesGolden')]
	public function testRestrictedMatchesAdminMinusRedactedFields(): void
	{
		$observedRedactions = [];
		$unexpectedStatuses = [];

		foreach (self::$restrictedSnapshot as $key => $restrictedResult)
		{
			$adminResult = self::$adminSnapshot[$key];

			if ($adminResult['status'] !== 200)
			{
				continue; // Admin itself didn't succeed - nothing to compare.
			}

			if (!in_array($restrictedResult['status'], [200, 403], true))
			{
				$unexpectedStatuses[] = "$key: restricted got {$restrictedResult['status']}";
				continue;
			}

			if ($restrictedResult['status'] !== 200)
			{
				continue; // A legitimate whole-object gate - nothing field-level to diff.
			}

			$missing = JsonShape::MissingKeys(self::$adminBodies[$key], self::$restrictedBodies[$key]);
			foreach ($missing as $path)
			{
				$observedRedactions[JsonShape::Leaf($path)] = true;
			}
		}

		self::assertSame([], $unexpectedStatuses, "CHILD got a status other than 200/403 on a route Admin could read: " . implode('; ', $unexpectedStatuses));

		// Ground truth: permission_fields, exactly as FieldPolicy reads it - never a
		// hand-maintained list, per that class's own docblock.
		$childPermissions = array_column(self::$db->query('SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9000')->fetchAll(PDO::FETCH_ASSOC), 'permission_name');
		$rows = self::$db->query("SELECT entity, field, permission_name FROM permission_fields WHERE field != '*'")->fetchAll(PDO::FETCH_ASSOC);

		$adminFieldNames = [];
		foreach (self::$adminBodies as $body)
		{
			self::collectFieldNames($body, $adminFieldNames);
		}

		$mustBeRedacted = [];
		foreach ($rows as $row)
		{
			if (in_array($row['permission_name'], $childPermissions, true))
			{
				continue; // CHILD holds this permission - not expected to be redacted.
			}
			if (!array_key_exists($row['field'], $adminFieldNames))
			{
				continue; // This field was never reached by the fixture sweep at all.
			}
			$mustBeRedacted[$row['field']] = true;
		}

		$missingCoverage = array_keys(array_diff_key($mustBeRedacted, $observedRedactions));
		self::assertSame([], $missingCoverage, 'permission_fields names these fields as CHILD-redacted, and the fixture sweep reached them, but CHILD received them anyway: ' . implode(', ', $missingCoverage));
	}

	private static function collectFieldNames($value, array &$out): void
	{
		if (is_array($value))
		{
			foreach ($value as $k => $v)
			{
				if (!is_int($k))
				{
					$out[$k] = true;
				}
				self::collectFieldNames($v, $out);
			}
		}
	}

	// ------------------------------------------------------------------------------
	// 8. The completeness leg: every price/cost/value/amount_paid-shaped field, in the
	//    OpenAPI schemas and in the recorded Admin bodies, is classified - x-visibility
	//    or a permission_fields row - so an unclassified leak (which leg 7 cannot see,
	//    since it is identical for both identities) does not pass silently.
	// ------------------------------------------------------------------------------

	#[Depends('testRestrictedMatchesAdminMinusRedactedFields')]
	public function testSensitiveFieldVocabularyIsClassified(): void
	{
		$policedFields = array_column(self::$db->query("SELECT DISTINCT field FROM permission_fields WHERE field != '*'")->fetchAll(PDO::FETCH_ASSOC), 'field');

		$unclassified = [];

		foreach (self::$adminBodies as $key => $body)
		{
			if (self::$adminSnapshot[$key]['status'] !== 200)
			{
				continue;
			}

			$restrictedBody = self::$restrictedBodies[$key] ?? null;
			$fields = [];
			self::collectFieldNames($body, $fields);

			foreach (array_keys($fields) as $field)
			{
				if (!preg_match(self::SENSITIVE_FIELD_PATTERN, $field))
				{
					continue;
				}

				if (in_array($field, $policedFields, true))
				{
					continue; // Classified via permission_fields.
				}

				if (self::isAllowedException($key, $field))
				{
					continue;
				}

				// Still acceptable if CHILD never actually receives this field - i.e. it
				// is gated some other way (a whole-object 403) than permission_fields.
				if ($restrictedBody !== null && !in_array($field, JsonShape::MissingKeys($body, $restrictedBody) === [] ? [] : array_map([JsonShape::class, 'Leaf'], JsonShape::MissingKeys($body, $restrictedBody)), true))
				{
					if (self::fieldReachableAndVisibleToChild($body, $restrictedBody, $field))
					{
						$unclassified[] = "$key: $field";
					}
				}
			}
		}

		$unclassified = array_values(array_unique($unclassified));
		self::assertSame([], $unclassified, 'Price/cost/value-shaped fields with neither an x-visibility annotation, a permission_fields row, nor a CHILD 403 protecting them (an unclassified field: identical for every identity, so it is the leak this leg exists to catch): ' . implode(', ', $unclassified));

		self::assertSensitiveSchemaPropertiesAreClassified();
	}

	private static function isAllowedException(string $key, string $field): bool
	{
		if (in_array($field, self::SENSITIVE_FIELD_NAME_EXCEPTIONS, true))
		{
			return true;
		}

		foreach (self::SENSITIVE_FIELD_ALLOWED_EXCEPTIONS as $keyFragment => $fields)
		{
			if (str_contains($key, $keyFragment) && in_array($field, $fields, true))
			{
				return true;
			}
		}
		return false;
	}

	/** True when $field is present for Admin and CHILD alike received it (restricted body is non-null and still carries it somewhere). */
	private static function fieldReachableAndVisibleToChild($adminBody, $restrictedBody, string $field): bool
	{
		$restrictedFields = [];
		self::collectFieldNames($restrictedBody, $restrictedFields);
		return array_key_exists($field, $restrictedFields);
	}

	/** The schema half of Leg 8: every ExposedEntity schema property matching the vocabulary carries x-visibility. */
	private function assertSensitiveSchemaPropertiesAreClassified(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		$unannotated = [];

		foreach ($spec['components']['schemas'] as $schemaName => $schema)
		{
			if (!isset($schema['properties']) || !is_array($schema['properties']))
			{
				continue;
			}

			// A schema-level x-visibility (ProductPriceHistory: "every field here is a
			// price", refused wholesale by AssertWholeObjectReadable rather than
			// filtered field by field) classifies every property under it - there is
			// nothing left for a per-property annotation to add.
			if (isset($schema['x-visibility']))
			{
				continue;
			}

			foreach ($schema['properties'] as $propertyName => $property)
			{
				if (!preg_match(self::SENSITIVE_FIELD_PATTERN, $propertyName))
				{
					continue;
				}

				if (isset($property['x-visibility']))
				{
					continue;
				}

				if (in_array($propertyName, self::SENSITIVE_FIELD_NAME_EXCEPTIONS, true))
				{
					continue;
				}

				foreach (self::SENSITIVE_FIELD_ALLOWED_EXCEPTIONS as $fields)
				{
					if (in_array($propertyName, $fields, true))
					{
						continue 2;
					}
				}

				$unannotated[] = "$schemaName.$propertyName";
			}
		}

		self::assertSame([], $unannotated, 'OpenAPI schema properties matching the price/cost/value vocabulary with no x-visibility annotation: ' . implode(', ', $unannotated));
	}
}
