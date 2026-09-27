<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Services\ApiKeyService;
use Victual\Services\BaseService;
use Victual\Services\UsersService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * GenericEntityApiController::AddObject()/EditObject() atomicity (audit finding H5, issue
 * #494 - the generic-API remainder; the service-level half of H5 is #527's
 * ComposedOperationAtomicityTest) and the storage-class freezer derivation (audit finding
 * M9, issue #509).
 *
 * H5: the row save/update and its post-save side effect
 * (StockService::AddMissingProductsToShoppingList(), driven by the
 * shopping_list_auto_add_below_min_stock_amount user setting) used to run as two
 * separately-committed statements. A misconfigured shopping list id made the second one
 * throw after the first had already committed, so a 400 response ("Shopping list does not
 * exist") still left the row - a new product, or a rename - written. See
 * GenericEntityApiController::AddObject()/EditObject()'s InRequestTransaction() wrapping for
 * the fix, and audit correction 8 for why the transaction has to sit *inside*
 * HandleApiCall()'s closure rather than around it: an outer transaction would see only the
 * 400 Response HandleApiCall() returns - a normal return, not a throw - and would commit it.
 *
 * Most cases below call the controller directly and assert against self::$db - the same
 * connection GenericEntityApiController itself writes through in this process, which shows a
 * rollback happened but not, on its own, that a *commit* would be durably visible to anyone
 * else. testAddObjectRollbackIsVisibleFromASeparateConnection() below closes that gap with a
 * real HTTP round trip from an independent process (review of this PR, cheap finding 5).
 *
 * M9: WithDerivedIsFreezer() only re-derived is_freezer when the request itself named
 * storage_class_id, so a location that already carried a freezer class accepted a PUT that
 * set is_freezer directly as long as the request never mentioned the class. And nothing kept
 * the reverse direction consistent: editing the class's own treats_as_freezer left every
 * location already carrying it untouched. A first version of that second fix
 * (PropagateFreezerFlagToLocations()) computed the value to propagate as
 * (int)(bool) of the *request's* treats_as_freezer rather than reading back what was actually
 * stored - review of this PR found that "00", " 0" and "+0" are all 0 to PostgreSQL's
 * SMALLINT cast (the same cast $row->update() applies to the class row itself) but true to
 * PHP's (bool), so the fix could itself produce the exact class/location disagreement it
 * exists to prevent. testEditingAStorageClassWithAWeirdNumericStringStaysConsistentWithItsLocations()
 * below is that regression, and PropagateFreezerFlagToLocations() now reads the class's own
 * stored value back inside the UPDATE instead of trusting the request's type.
 */
class GenericWriteAtomicityTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static GenericEntityApiController $generic;

	/** For the one HTTP-level case; a distinct id from the in-process caller above. */
	private const HTTP_USER_ID = 9701;
	private static string $apiKey = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Issue #533: BaseService::$Instances is a process-wide singleton cache keyed by
		// class name. Another test class sharing this testsuite's PHP process may already
		// have constructed a UsersService/StockService singleton whose own $this->DB (set in
		// BaseService::__construct()) is bound to that class's own schema connection -
		// already dropped by the time this class runs. Resetting the cache forces every
		// service AddObject()/EditObject() drives here (UsersService::GetUserSetting(),
		// StockService::AddMissingProductsToShoppingList()) to reconstruct against the
		// connection PgsqlSchemaTestCase::setUpBeforeClass() just installed above.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$generic = new GenericEntityApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'atomicity-caller', 'fixture')");
		self::grant(['MASTER_DATA_EDIT']);

		// The separate-connection HTTP case needs its own authenticated identity.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::HTTP_USER_ID . ", 'atomicity-http-caller', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::HTTP_USER_ID . " , id FROM permission_hierarchy WHERE name IN ('MASTER_DATA_EDIT', 'STOCK_VIEW')");
		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			self::HTTP_USER_ID,
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');

		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	/** A POST/PUT request with a parsed JSON body, for AddObject()/EditObject(). */
	private static function requestWithBody(string $method, array $body)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');
		return $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function freezerStorageClassId(): int
	{
		return (int)self::$db->query('SELECT id FROM storage_classes WHERE treats_as_freezer = 1 ORDER BY sort_order LIMIT 1')->fetchColumn();
	}

	/** Points the shopping-list-auto-add setting at a shopping list id that does not exist. */
	private static function pointAutoAddAtAMissingShoppingList(): void
	{
		UsersService::GetInstance()->SetUserSetting(9000, 'shopping_list_auto_add_below_min_stock_amount', '1');
		UsersService::GetInstance()->SetUserSetting(9000, 'shopping_list_auto_add_below_min_stock_amount_list_id', 999999);
	}

	private static function disableAutoAdd(): void
	{
		UsersService::GetInstance()->SetUserSetting(9000, 'shopping_list_auto_add_below_min_stock_amount', '0');
	}

	/**
	 * One request through request-subprocess-helper.php - a fresh PHP process, and so a
	 * fresh PDO connection to this class's schema, independent of self::$db and of the
	 * connection GenericEntityApiController writes through elsewhere in this file. Mirrors
	 * ComposedOperationAtomicityTest::requestWithInfluxEnabled() and
	 * ReferenceRefusalTest::delete(), minus the parts neither needs.
	 *
	 * @return array{status: int, body?: string, stderr: string}
	 */
	private static function httpRequest(string $method, string $path, ?array $body = null): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey], 'body' => $body],
			fn ($value) => $value !== null
		);

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
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;

		return $result;
	}

	// ------------------------------------------------------------------------------
	// H5 / issue #494 - AddObject()/EditObject() atomicity
	// ------------------------------------------------------------------------------

	/**
	 * Given the auto-add-to-shopping-list setting points at a shopping list that does not
	 * exist, when POST /api/objects/products is sent a valid new product, then the request
	 * answers 400 "Shopping list does not exist" and the product is not created - not
	 * committed and then reported as a failure.
	 */
	public function testAddObjectRollsBackTheInsertWhenThePostSaveStepFails(): void
	{
		$kitchen = self::insertRow('locations', ['name' => 'Atomicity Kitchen']);

		self::pointAutoAddAtAMissingShoppingList();

		try
		{
			$response = self::$generic->AddObject(
				self::requestWithBody('POST', [
					'name' => 'Atomicity New Product',
					'location_id' => $kitchen,
					'qu_id_purchase' => 2,
					'qu_id_stock' => 2,
				]),
				new Response(),
				['entity' => 'products']
			);

			self::assertSame(400, $response->getStatusCode(), 'The post-save failure must still answer 400');
			$body = json_decode((string)$response->getBody(), true);
			self::assertSame('Shopping list does not exist', $body['error_message']);

			$count = self::$db->prepare('SELECT count(*) FROM products WHERE name = ?');
			$count->execute(['Atomicity New Product']);
			self::assertSame(0, (int)$count->fetchColumn(), 'A 400 response must mean the product was never created (H5 / issue #494)');
		}
		finally
		{
			self::disableAutoAdd();
		}
	}

	/**
	 * The same refusal as above, proved across a real connection boundary rather than on
	 * self::$db (review of this PR, cheap finding 5): a POST through a fresh subprocess
	 * answers 400, and a second, independent subprocess - its own PHP process, its own PDO
	 * connection to this class's schema - lists no such product. Nothing this test process
	 * did can make that assertion pass by accident; only a real commit (which must not have
	 * happened) could.
	 */
	public function testAddObjectRollbackIsVisibleFromASeparateConnection(): void
	{
		$kitchen = self::insertRow('locations', ['name' => 'Atomicity Cross Process Kitchen']);

		UsersService::GetInstance()->SetUserSetting(self::HTTP_USER_ID, 'shopping_list_auto_add_below_min_stock_amount', '1');
		UsersService::GetInstance()->SetUserSetting(self::HTTP_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id', 999999);

		try
		{
			$createResult = self::httpRequest('POST', '/api/objects/products', [
				'name' => 'Atomicity Cross Process Product',
				'location_id' => $kitchen,
				'qu_id_purchase' => 2,
				'qu_id_stock' => 2,
			]);

			self::assertSame(400, $createResult['status'], "expected 400, got {$createResult['status']} (body: {$createResult['body']}, stderr: {$createResult['stderr']})");
			$createBody = json_decode((string)$createResult['body'], true);
			self::assertSame('Shopping list does not exist', $createBody['error_message']);

			// A fresh process, a fresh connection: this can only see a row that was actually
			// committed by the request above, not merely written and later rolled back within
			// that request's own session.
			$listResult = self::httpRequest('GET', '/api/objects/products');
			self::assertSame(200, $listResult['status'], "listing failed: {$listResult['body']}");
			self::assertStringNotContainsString(
				'Atomicity Cross Process Product',
				(string)$listResult['body'],
				'A separate connection must not see a product a rolled-back request tried to create (H5 / issue #494)'
			);
		}
		finally
		{
			UsersService::GetInstance()->SetUserSetting(self::HTTP_USER_ID, 'shopping_list_auto_add_below_min_stock_amount', '0');
		}
	}

	/**
	 * Given a product already exists and the auto-add setting points at a missing shopping
	 * list, when PUT /api/objects/products/{id} renames it, then the request answers 400 and
	 * the row keeps its original name - the update rolled back with the failed side effect
	 * rather than staying committed underneath a 400 response.
	 */
	public function testEditObjectRollsBackTheUpdateWhenThePostSaveStepFails(): void
	{
		$kitchen = self::insertRow('locations', ['name' => 'Atomicity Kitchen For Edit']);
		$productId = self::insertRow('products', [
			'name' => 'Atomicity Original Name',
			'location_id' => $kitchen,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
		]);

		self::pointAutoAddAtAMissingShoppingList();

		try
		{
			$response = self::$generic->EditObject(
				self::requestWithBody('PUT', ['name' => 'Atomicity Renamed If Committed']),
				new Response(),
				['entity' => 'products', 'objectId' => $productId]
			);

			self::assertSame(400, $response->getStatusCode(), 'The post-save failure must still answer 400');
			$body = json_decode((string)$response->getBody(), true);
			self::assertSame('Shopping list does not exist', $body['error_message']);

			$name = self::$db->prepare('SELECT name FROM products WHERE id = ?');
			$name->execute([$productId]);
			self::assertSame('Atomicity Original Name', $name->fetchColumn(), 'A 400 response must mean the rename was never committed (H5 / issue #494)');
		}
		finally
		{
			self::disableAutoAdd();
		}
	}

	// ------------------------------------------------------------------------------
	// M9 / issue #509 - is_freezer derivation from a persisted storage class
	// ------------------------------------------------------------------------------

	/**
	 * Given a location already carries a freezer-treating storage class, when it is PUT with
	 * {"is_freezer":0} and no storage_class_id at all, then the request succeeds (the class
	 * silently wins, exactly as it does when the same request also names the class - plan
	 * 23's Executed section, case 11) and is_freezer stays derived at 1 rather than taking
	 * the submitted value.
	 */
	public function testEditingLocationOmittingStorageClassIdStillDerivesFromThePersistedClass(): void
	{
		$freezerClass = self::freezerStorageClassId();
		$locationId = self::insertRow('locations', [
			'name' => 'M9 Already Classified Freezer',
			'storage_class_id' => $freezerClass,
			'is_freezer' => 1,
		]);

		$response = self::$generic->EditObject(
			self::requestWithBody('PUT', ['is_freezer' => 0]),
			new Response(),
			['entity' => 'locations', 'objectId' => $locationId]
		);

		self::assertSame(204, $response->getStatusCode(), 'Omitting storage_class_id is not itself a refusal');

		$row = self::$db->prepare('SELECT is_freezer, storage_class_id FROM locations WHERE id = ?');
		$row->execute([$locationId]);
		$stored = $row->fetch(PDO::FETCH_ASSOC);

		self::assertSame($freezerClass, (int)$stored['storage_class_id'], 'The class itself is untouched');
		self::assertSame(1, (int)$stored['is_freezer'], 'is_freezer must stay derived from the persisted class (M9 / issue #509), not take the submitted 0');
	}

	/**
	 * The negative control: a location with no class at all keeps is_freezer independently
	 * editable when the request omits storage_class_id too, exactly as plan 23 question 3
	 * documents. This is the behaviour WithDerivedIsFreezer()'s fix must not disturb.
	 */
	public function testEditingAnUnclassifiedLocationKeepsIsFreezerIndependentlyEditable(): void
	{
		$locationId = self::insertRow('locations', [
			'name' => 'M9 Unclassified',
			'is_freezer' => 0,
		]);

		$response = self::$generic->EditObject(
			self::requestWithBody('PUT', ['is_freezer' => 1]),
			new Response(),
			['entity' => 'locations', 'objectId' => $locationId]
		);

		self::assertSame(204, $response->getStatusCode());

		$stored = self::$db->prepare('SELECT is_freezer FROM locations WHERE id = ?');
		$stored->execute([$locationId]);
		self::assertSame(1, (int)$stored->fetchColumn(), 'With no class, ever, is_freezer stays whatever the request submits');
	}

	/**
	 * Explicitly clearing storage_class_id (rather than omitting it) hands is_freezer back
	 * to independent editing in the same request, per plan 23's Executed section ("clearing
	 * the class hands the flag back to direct editing").
	 */
	public function testClearingStorageClassIdReturnsIsFreezerToIndependentEditing(): void
	{
		$freezerClass = self::freezerStorageClassId();
		$locationId = self::insertRow('locations', [
			'name' => 'M9 Cleared Class',
			'storage_class_id' => $freezerClass,
			'is_freezer' => 1,
		]);

		$response = self::$generic->EditObject(
			self::requestWithBody('PUT', ['storage_class_id' => null, 'is_freezer' => 0]),
			new Response(),
			['entity' => 'locations', 'objectId' => $locationId]
		);

		self::assertSame(204, $response->getStatusCode());

		$row = self::$db->prepare('SELECT is_freezer, storage_class_id FROM locations WHERE id = ?');
		$row->execute([$locationId]);
		$stored = $row->fetch(PDO::FETCH_ASSOC);

		self::assertNull($stored['storage_class_id']);
		self::assertSame(0, (int)$stored['is_freezer'], 'Explicitly clearing the class takes the submitted is_freezer');
	}

	/**
	 * Given two locations carry a non-freezer storage class, when that class is edited to
	 * treats_as_freezer=1, then both locations' is_freezer flip to 1 in the same request -
	 * plan 23's derivation promise already held for a location's own edit, and M9 / issue
	 * #509 is this method's other half: the class's own edit must keep every location that
	 * already carries it consistent, in the same transaction.
	 */
	public function testEditingAStorageClassFreezerFlagUpdatesEveryLocationOfThatClass(): void
	{
		$classId = self::insertRow('storage_classes', [
			'name' => 'Atomicity Custom Class',
			'treats_as_freezer' => 0,
			'sort_order' => 999,
		]);

		$first = self::insertRow('locations', ['name' => 'M9 Class Edit First', 'storage_class_id' => $classId, 'is_freezer' => 0]);
		$second = self::insertRow('locations', ['name' => 'M9 Class Edit Second', 'storage_class_id' => $classId, 'is_freezer' => 0]);
		$unrelated = self::insertRow('locations', ['name' => 'M9 Class Edit Unrelated']);

		$response = self::$generic->EditObject(
			self::requestWithBody('PUT', ['treats_as_freezer' => 1]),
			new Response(),
			['entity' => 'storage_classes', 'objectId' => $classId]
		);

		self::assertSame(204, $response->getStatusCode());

		$classRow = self::$db->prepare('SELECT treats_as_freezer FROM storage_classes WHERE id = ?');
		$classRow->execute([$classId]);
		self::assertSame(1, (int)$classRow->fetchColumn());

		foreach ([$first, $second] as $locationId)
		{
			$isFreezer = self::$db->prepare('SELECT is_freezer FROM locations WHERE id = ?');
			$isFreezer->execute([$locationId]);
			self::assertSame(1, (int)$isFreezer->fetchColumn(), "Location $locationId of the edited class must follow it");
		}

		$unrelatedIsFreezer = self::$db->prepare('SELECT is_freezer FROM locations WHERE id = ?');
		$unrelatedIsFreezer->execute([$unrelated]);
		self::assertSame(0, (int)$unrelatedIsFreezer->fetchColumn(), 'A location with no class at all must be untouched by editing an unrelated one');
	}

	/**
	 * Given a storage class currently treats_as_freezer=1 with locations that follow it, when
	 * it is edited with treats_as_freezer sent as the string "00" - 0 to PostgreSQL's SMALLINT
	 * cast, but true to PHP's (bool) - then the class itself is stored as 0, and every
	 * location of that class must land at is_freezer=0 too. Review of this PR's first version
	 * found exactly this case producing is_freezer=1 on the locations while the class read 0:
	 * PropagateFreezerFlagToLocations() computed (int)(bool)"00" (== 1) instead of reading
	 * back what $row->update() had just stored for the class itself.
	 */
	public function testEditingAStorageClassWithAWeirdNumericStringStaysConsistentWithItsLocations(): void
	{
		$classId = self::insertRow('storage_classes', [
			'name' => 'Atomicity Weird String Class',
			'treats_as_freezer' => 1,
			'sort_order' => 997,
		]);
		$locationId = self::insertRow('locations', [
			'name' => 'M9 Weird String Location',
			'storage_class_id' => $classId,
			'is_freezer' => 1,
		]);

		$response = self::$generic->EditObject(
			self::requestWithBody('PUT', ['treats_as_freezer' => '00']),
			new Response(),
			['entity' => 'storage_classes', 'objectId' => $classId]
		);

		self::assertSame(204, $response->getStatusCode());

		$classRow = self::$db->prepare('SELECT treats_as_freezer FROM storage_classes WHERE id = ?');
		$classRow->execute([$classId]);
		$storedClassValue = (int)$classRow->fetchColumn();
		self::assertSame(0, $storedClassValue, 'PostgreSQL parses "00" as 0 for this SMALLINT column');

		$isFreezer = self::$db->prepare('SELECT is_freezer FROM locations WHERE id = ?');
		$isFreezer->execute([$locationId]);
		self::assertSame(
			$storedClassValue,
			(int)$isFreezer->fetchColumn(),
			'The location must agree with what the class actually stored, not with PHP\'s (bool) reading of the request value'
		);
	}
}
