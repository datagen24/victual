<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #498 (audit finding H9, #487): malformed or absent API
 * inputs answered 500 instead of the documented 400, several of those 500s disclosed the
 * server's own file paths, and a request body's boolean fields were misread.
 *
 * Three independent defects, one class:
 *
 * - **Ten routes 500ed on an absent body.** Each reads its request body with
 *   BaseApiController::GetParsedAndFilteredRequestBody(), which returns null when none was
 *   sent (Slim's body parser gives null for an empty payload, Content-Type notwithstanding).
 *   Passing that null into array_key_exists() or into RequestedTimestamp()'s non-nullable
 *   `array $requestBody` parameter is a \TypeError - a sibling of \Exception under
 *   \Throwable, not caught by HandleApiCall()'s catch clauses - so it escaped uncaught to
 *   ExceptionController as a 500. Six of the ten (victual.openapi.json's requestBody.required
 *   is false for each) now default the body to [] so the field's own documented default
 *   applies; four (requestBody.required: true) now refuse the same way
 *   AddProduct()/ConsumeProduct() already refused a null body, with 400 rather than a crash.
 * - **The path disclosure was in error_message, not error_details.** A PHP TypeError raised
 *   for a bad argument names the call site verbatim - "..., called in
 *   /app/controllers/Api/BaseApiController.php on line 421" - and that text is the
 *   exception's own getMessage(), which reaches error_message on every response regardless
 *   of display_errors/dev mode. request-subprocess-helper.php runs
 *   addErrorMiddleware(false, false, false) - "production mode" per its own header comment -
 *   so error_details (gated on displayErrorDetails) can never appear in these tests; every
 *   case here proves the leak was never limited to a dev-only debug block.
 *   BaseApiController::WithoutDriverText() now strips that suffix before a message reaches
 *   GenericErrorResponse() or ExceptionController's own 500 branch. LogException() still logs
 *   the exception's real file/line as structured context (app.php passes logErrorDetails=true
 *   unconditionally), so the operator record is unaffected - only the wire copy loses it.
 * - **The same three malformed-query shapes and the spoiled misread.** GenericEntityApiController::GetObjects()
 *   and every other caller of BaseApiController::FilteredApiResponse() call QueryData()
 *   without a HandleApiCall() wrapper, so a client-supplied "query" that is not an array, an
 *   "order" that is not a string, or a negative "limit"/"offset" reached a typed sink
 *   (FilterData()'s `array $query` parameter, explode()'s string parameter, or PostgreSQL's
 *   own "LIMIT/OFFSET must not be negative") as an uncaught TypeError/PDOException. QueryData()
 *   now validates all three shapes itself, throwing the same Slim HttpException(..., 400) its
 *   other query refusals already use - which ExceptionController maps to 400 whether or not a
 *   HandleApiCall() wrapper is present. "spoiled" on POST .../consume was read as
 *   `$requestBody['spoiled']` verbatim: null reached StockService::ConsumeProduct()'s
 *   non-nullable `bool $spoiled` parameter as the same class of TypeError as above, and the
 *   string "false" reached it as a truthy PHP value, so the booking succeeded and stored
 *   `spoiled:true`. It now goes through WireBooleans::RequireBoolean() (#530), the fork's one
 *   boolean reader, which refuses null and the word strings "true"/"false" with 400 rather
 *   than silently mean the wrong thing - see that class's own docblock.
 *
 * Every case runs at HTTP level through tests/Pgsql/request-subprocess-helper.php (the
 * ReferenceRefusalTest.php/StockEntryEditContractTest.php harness), because the defect is
 * about what an *uncaught* exception does once it reaches Slim's real error middleware and
 * ExceptionController - a direct controller call would just let it escape the test method
 * instead of exercising that pipeline. This is also the same harness the H9 audit's own
 * `api.php` reproduction used (down to running "in production mode"), so a case that failed
 * against it before this fix is the same failure the audit observed, not a reproduction of a
 * reproduction.
 *
 * What the existing `genericquery` phase (GenericQueryTest.php) already owns is not repeated:
 * that file calls GenericEntityApiController::GetObjects() directly and covers every
 * *correctly-shaped* query/limit/offset/order case exhaustively, including
 * testANonNumericLimitIsTreatedAsZero() - a non-numeric "limit" (e.g. "all") is deliberately
 * still read as 0 via intval() and is not refused, so this file's limit/offset guard is
 * scoped to *negative* values only and must not, and does not, disturb that case.
 */
class ApiInputShapesTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9800;

	private static PDO $db;
	private static string $apiKey;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::USER_ID . ", 'api-input-shapes-caller', 'fixture')");

		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . ', id FROM permission_hierarchy WHERE name = ?');
		foreach ([
			'SHOPPINGLIST_ITEMS_ADD',
			'SHOPPINGLIST_ITEMS_DELETE',
			'CHORES',
			'CHORE_TRACK_EXECUTION',
			'BATTERIES_TRACK_CHARGE_CYCLE',
			'TASKS_MARK_COMPLETED',
			'STOCK_CONSUME',
			'STOCK_VIEW',
		] as $permission)
		{
			$statement->execute([$permission]);
		}

		self::$apiKey = bin2hex(random_bytes(25));
		$keyStatement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$keyStatement->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			self::USER_ID,
			ApiKeyService::API_KEY_TYPE_DEFAULT,
		]);
	}

	// ------------------------------------------------------------------------------
	// HTTP helper
	// ------------------------------------------------------------------------------

	/**
	 * One request through the real middleware stack. Content-Type is always sent as
	 * application/json, whether or not $body is given - the H9 empty-body cases are
	 * specifically "a JSON request with no payload", not "a request the server cannot tell
	 * is JSON at all" (BaseApiController::GetParsedAndFilteredRequestBody()'s own, separate,
	 * "Bad Content-Type" refusal), and matches the audit's own api.php reproduction exactly.
	 *
	 * Asserts, for every response this class observes regardless of status, that no server
	 * file path reached the wire - the blanket form of the H9 path-disclosure claim ("several
	 * responses expose /app/... call sites"), rather than one assertion per refusal case.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function Request(string $method, string $path, ?array $body = null): array
	{
		$spec = [
			'method' => $method,
			'path' => $path,
			'headers' => ['VICTUAL-API-KEY' => self::$apiKey, 'Content-Type' => 'application/json'],
		];

		if ($body !== null)
		{
			$spec['body'] = $body;
		}

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
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		// json_encode() escapes "/" as "\/" by default, so the raw response body never
		// contains the literal substring "/app/" even when it names that path - checking the
		// raw bytes would silently never fire. Decoded and re-encoded with
		// JSON_UNESCAPED_SLASHES first, so the check is against what the path actually says
		// rather than how JSON happens to spell a slash.
		$decodedForPathCheck = json_decode((string)$result['body'], true);
		$searchable = $decodedForPathCheck === null ? (string)$result['body'] : json_encode($decodedForPathCheck, JSON_UNESCAPED_SLASHES);
		self::assertStringNotContainsString('/app/', $searchable,
			"$method $path: no server file path may reach a response body ({$result['body']})");

		return $result;
	}

	// ------------------------------------------------------------------------------
	// Error400 shape (Opis), the documented error semantics
	// ------------------------------------------------------------------------------

	/**
	 * Validates $body against components/schemas/Error400, the way
	 * LabelResolveSchemaTest::ValidateAgainstResolveSchema validates a row against a named
	 * schema - except this one is a $ref under components/schemas rather than inline on a
	 * path, so it is read off the document by name.
	 *
	 * @return string|null null when $body validates; otherwise "path: keyword" for the first
	 *         (deepest) failure.
	 */
	private static function ValidateAgainstError400Schema(array $body): ?string
	{
		static $schema = null;
		if ($schema === null)
		{
			$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);
			$schema = $document->components->schemas->Error400;
		}

		$validator = new \Opis\JsonSchema\Validator();
		$validator->parser()->setOption('allowDefaults', false);

		$result = $validator->validate(json_decode(json_encode($body), false), json_decode(json_encode($schema), false));

		if ($result->isValid())
		{
			return null;
		}

		$error = $result->error();
		while ($error->subErrors())
		{
			$error = $error->subErrors()[0];
		}

		return (implode('/', $error->data()->fullPath()) ?: '<root>') . ': ' . $error->keyword();
	}

	/**
	 * Asserts $result is a 400 in the documented Error400 shape: a real, non-empty
	 * error_message, valid against the spec's own schema for it.
	 *
	 * @return array The decoded body, for a caller that wants to check anything more specific
	 */
	private static function AssertRefusedAsError400(array $result, string $context): array
	{
		self::assertSame(400, $result['status'], "$context: expected 400, got {$result['status']} (body: {$result['body']})");

		$body = json_decode((string)$result['body'], true);
		self::assertIsArray($body, "$context: response body must be JSON");
		self::assertArrayHasKey('error_message', $body, "$context: must carry the documented error_message");
		self::assertIsString($body['error_message'], "$context: error_message must be a string");
		self::assertNotSame('', $body['error_message'], "$context: error_message must not be empty");

		$failure = self::ValidateAgainstError400Schema($body);
		self::assertNull($failure, "$context: body must validate against the Error400 schema ($failure): {$result['body']}");

		return $body;
	}

	// ------------------------------------------------------------------------------
	// Fixture helpers
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertLocation(string $name): int
	{
		return self::insertRow('locations', ['name' => $name]);
	}

	/** due_type is a products column (01_tables.sql:205; 1 = "best before", 2 = "expiration"), not a stock column. */
	private static function insertProduct(string $name, int $locationId, float $minStockAmount = 0, int $dueType = 1): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => $locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'min_stock_amount' => $minStockAmount,
			'due_type' => $dueType,
		]);
	}

	private static function insertStock(int $productId, int $locationId, float $amount, string $bestBeforeDate): int
	{
		return self::insertRow('stock', [
			'product_id' => $productId,
			'amount' => $amount,
			'stock_id' => 'api-input-shapes-' . $productId,
			'best_before_date' => $bestBeforeDate,
			'purchased_date' => date('Y-m-d'),
			'location_id' => $locationId,
		]);
	}

	private static function shoppingListCount(): int
	{
		return (int)self::$db->query('SELECT count(*) FROM shopping_list')->fetchColumn();
	}

	private static function shoppingListHasProduct(int $productId, int $listId = 1): bool
	{
		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE product_id = ? AND shopping_list_id = ?');
		$statement->execute([$productId, $listId]);

		return (int)$statement->fetchColumn() > 0;
	}

	// ================================================================================
	// Group A: the ten empty-body routes (#487 H9's own list)
	// ================================================================================

	// --- requestBody.required: false -> an absent body applies the documented defaults ---

	public function testAddMissingProductsToShoppingListWithNoBodyAppliesTheDefaultList(): void
	{
		$locationId = self::insertLocation('H9 Missing Location');
		$productId = self::insertProduct('H9 Missing Product', $locationId, 5);

		$result = self::Request('POST', '/api/stock/shoppinglist/add-missing-products');

		self::assertSame(204, $result['status'], "empty body must apply the documented default list, not 500: {$result['body']}");
		self::assertTrue(self::shoppingListHasProduct($productId), 'the missing product must have been added to the default list (id 1)');
	}

	public function testAddOverdueProductsToShoppingListWithNoBodyAppliesTheDefaultList(): void
	{
		$locationId = self::insertLocation('H9 Overdue Location');
		$productId = self::insertProduct('H9 Overdue Product', $locationId);
		self::insertStock($productId, $locationId, 2, date('Y-m-d', strtotime('-5 days')));

		$result = self::Request('POST', '/api/stock/shoppinglist/add-overdue-products');

		self::assertSame(204, $result['status'], "empty body must apply the documented default list, not 500: {$result['body']}");
		self::assertTrue(self::shoppingListHasProduct($productId), 'the overdue product must have been added to the default list (id 1)');
	}

	public function testAddExpiredProductsToShoppingListWithNoBodyAppliesTheDefaultList(): void
	{
		$locationId = self::insertLocation('H9 Expired Location');
		$productId = self::insertProduct('H9 Expired Product', $locationId, 0, 2);
		self::insertStock($productId, $locationId, 2, date('Y-m-d', strtotime('-5 days')));

		$result = self::Request('POST', '/api/stock/shoppinglist/add-expired-products');

		self::assertSame(204, $result['status'], "empty body must apply the documented default list, not 500: {$result['body']}");
		self::assertTrue(self::shoppingListHasProduct($productId), 'the expired product must have been added to the default list (id 1)');
	}

	public function testClearShoppingListWithNoBodyClearsTheDefaultList(): void
	{
		$openId = self::insertRow('shopping_list', ['note' => 'H9 clear open item', 'shopping_list_id' => 1, 'done' => 0]);
		$doneId = self::insertRow('shopping_list', ['note' => 'H9 clear done item', 'shopping_list_id' => 1, 'done' => 1]);

		$result = self::Request('POST', '/api/stock/shoppinglist/clear');

		self::assertSame(204, $result['status'], "empty body must apply the documented default list (done_only=false), not 500: {$result['body']}");

		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE id IN (?, ?)');
		$statement->execute([$openId, $doneId]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'both rows of the default list must have been cleared, done and not done alike');
	}

	public function testCalculateNextAssignmentsWithNoBodyAppliesToAllChores(): void
	{
		$choreId = self::insertRow('chores', [
			'name' => 'H9 Assignment Chore',
			'period_type' => 'manually',
			'assignment_type' => 'random',
			'assignment_config' => (string)self::USER_ID,
		]);

		$before = self::$db->prepare('SELECT next_execution_assigned_to_user_id FROM chores WHERE id = ?');
		$before->execute([$choreId]);
		self::assertNull($before->fetchColumn(), 'the fixture must start unassigned');

		$result = self::Request('POST', '/api/chores/executions/calculate-next-assignments');

		self::assertSame(204, $result['status'], "empty body must recalculate every chore (chore_id omitted), not 500: {$result['body']}");

		$after = self::$db->prepare('SELECT next_execution_assigned_to_user_id FROM chores WHERE id = ?');
		$after->execute([$choreId]);
		self::assertSame(self::USER_ID, (int)$after->fetchColumn(), 'the one assignable user must now be assigned');
	}

	// --- requestBody.required: true -> an absent body is refused, not defaulted ---

	public function testAddProductToShoppingListWithNoBodyIsRefusedWithoutCreatingARow(): void
	{
		$before = self::shoppingListCount();

		$result = self::Request('POST', '/api/stock/shoppinglist/add-product');

		self::AssertRefusedAsError400($result, 'POST /stock/shoppinglist/add-product with no body');
		self::assertSame($before, self::shoppingListCount(), 'a refused request must not have added a row');
	}

	public function testRemoveProductFromShoppingListWithNoBodyIsRefusedWithoutDeletingARow(): void
	{
		$locationId = self::insertLocation('H9 Remove Location');
		$productId = self::insertProduct('H9 Remove Product', $locationId);
		$rowId = self::insertRow('shopping_list', ['product_id' => $productId, 'shopping_list_id' => 1, 'amount' => 1]);

		$result = self::Request('POST', '/api/stock/shoppinglist/remove-product');

		self::AssertRefusedAsError400($result, 'POST /stock/shoppinglist/remove-product with no body');

		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE id = ?');
		$statement->execute([$rowId]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'a refused request must not have removed the row');
	}

	public function testChoreExecuteWithNoBodyIsRefusedWithoutLoggingAnExecution(): void
	{
		$choreId = self::insertRow('chores', ['name' => 'H9 Execute Chore', 'period_type' => 'manually']);

		$result = self::Request('POST', '/api/chores/' . $choreId . '/execute');

		self::AssertRefusedAsError400($result, 'POST /chores/{id}/execute with no body');

		$statement = self::$db->prepare('SELECT count(*) FROM chores_log WHERE chore_id = ?');
		$statement->execute([$choreId]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'a refused request must not have logged an execution');
	}

	public function testBatteryChargeWithNoBodyIsRefusedWithoutLoggingACharge(): void
	{
		$batteryId = self::insertRow('batteries', ['name' => 'H9 Charge Battery']);

		$result = self::Request('POST', '/api/batteries/' . $batteryId . '/charge');

		self::AssertRefusedAsError400($result, 'POST /batteries/{id}/charge with no body');

		$statement = self::$db->prepare('SELECT count(*) FROM battery_charge_cycles WHERE battery_id = ?');
		$statement->execute([$batteryId]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'a refused request must not have logged a charge cycle');
	}

	public function testTaskCompleteWithNoBodyIsRefusedWithoutCompletingTheTask(): void
	{
		$taskId = self::insertRow('tasks', ['name' => 'H9 Complete Task']);

		$result = self::Request('POST', '/api/tasks/' . $taskId . '/complete');

		self::AssertRefusedAsError400($result, 'POST /tasks/{id}/complete with no body');

		$statement = self::$db->prepare('SELECT done, done_timestamp FROM tasks WHERE id = ?');
		$statement->execute([$taskId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		self::assertSame(0, (int)$row['done'], 'a refused request must not have marked the task done');
		self::assertNull($row['done_timestamp'], 'a refused request must not have recorded a completion time');
	}

	// ================================================================================
	// Group B: malformed query shapes on GET /api/objects/{entity}
	// ================================================================================

	public function testScalarQueryParameterIsRefused(): void
	{
		$result = self::Request('GET', '/api/objects/products?query=abc');

		self::AssertRefusedAsError400($result, 'GET /objects/products?query=abc (scalar "query")');
	}

	public function testArrayOrderParameterIsRefused(): void
	{
		$result = self::Request('GET', '/api/objects/products?order%5B%5D=name');

		self::AssertRefusedAsError400($result, 'GET /objects/products?order[]=name (array "order")');
	}

	public function testNegativeLimitIsRefused(): void
	{
		$result = self::Request('GET', '/api/objects/products?limit=-1');

		self::AssertRefusedAsError400($result, 'GET /objects/products?limit=-1');
	}

	public function testNegativeOffsetIsRefused(): void
	{
		$result = self::Request('GET', '/api/objects/products?offset=-1');

		self::AssertRefusedAsError400($result, 'GET /objects/products?offset=-1');
	}

	/**
	 * Negative control: the shape guards above must not disturb an ordinary, validly-shaped
	 * query - including a non-numeric "limit", which GenericQueryTest::testANonNumericLimitIsTreatedAsZero()
	 * already pins as "read as 0", not refused.
	 */
	public function testOrdinaryAndNonNumericLimitsStillWorkAfterTheShapeGuards(): void
	{
		$ordinary = self::Request('GET', '/api/objects/products?limit=1');
		self::assertSame(200, $ordinary['status'], 'a plain, validly-shaped limit must still be served');
		self::assertIsArray(json_decode((string)$ordinary['body'], true), 'the listing must still decode as JSON');

		$nonNumeric = self::Request('GET', '/api/objects/products?limit=all');
		self::assertSame(200, $nonNumeric['status'], 'a non-numeric limit is intval()\'d to 0, not refused - it must not become a 500 or a 400');
		self::assertSame([], json_decode((string)$nonNumeric['body'], true), 'a limit of "all" reads as 0, which is an empty page');
	}

	// ================================================================================
	// Group C: the "spoiled" boolean on POST /stock/products/{id}/consume
	// ================================================================================

	private static function stockAmount(int $stockRowId): float
	{
		$statement = self::$db->prepare('SELECT amount FROM stock WHERE id = ?');
		$statement->execute([$stockRowId]);

		return (float)$statement->fetchColumn();
	}

	private static function stockLogCountFor(int $productId): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM stock_log WHERE product_id = ?');
		$statement->execute([$productId]);

		return (int)$statement->fetchColumn();
	}

	public function testConsumeWithNullSpoiledIsRefusedWithoutConsumingStock(): void
	{
		$locationId = self::insertLocation('H9 Spoiled Null Location');
		$productId = self::insertProduct('H9 Spoiled Null Product', $locationId);
		$stockRowId = self::insertStock($productId, $locationId, 3, date('Y-m-d', strtotime('+30 days')));

		$result = self::Request('POST', '/api/stock/products/' . $productId . '/consume', ['amount' => 1, 'spoiled' => null]);

		self::AssertRefusedAsError400($result, 'POST .../consume with spoiled:null');
		self::assertSame(3.0, self::stockAmount($stockRowId), 'a refused consume must not have moved any stock');
		self::assertSame(0, self::stockLogCountFor($productId), 'a refused consume must not have written a ledger row');
	}

	public function testConsumeWithStringFalseSpoiledIsRefusedWithoutConsumingStock(): void
	{
		$locationId = self::insertLocation('H9 Spoiled String Location');
		$productId = self::insertProduct('H9 Spoiled String Product', $locationId);
		$stockRowId = self::insertStock($productId, $locationId, 3, date('Y-m-d', strtotime('+30 days')));

		$result = self::Request('POST', '/api/stock/products/' . $productId . '/consume', ['amount' => 1, 'spoiled' => 'false']);

		self::AssertRefusedAsError400($result, 'POST .../consume with spoiled:"false"');
		self::assertSame(3.0, self::stockAmount($stockRowId), 'a refused consume must not have moved any stock - the word string "false" is not a documented boolean and must not be read as one');
		self::assertSame(0, self::stockLogCountFor($productId), 'a refused consume must not have written a ledger row');
	}

	/**
	 * Positive control: a real JSON boolean still works exactly as before, in both
	 * directions, and the ledger stores the flag it was actually given.
	 */
	public function testConsumeWithARealBooleanSpoiledStoresTheGivenFlag(): void
	{
		$locationId = self::insertLocation('H9 Spoiled Boolean Location');

		$spoiledProductId = self::insertProduct('H9 Spoiled True Product', $locationId);
		self::insertStock($spoiledProductId, $locationId, 5, date('Y-m-d', strtotime('+30 days')));

		$spoiledResult = self::Request('POST', '/api/stock/products/' . $spoiledProductId . '/consume', ['amount' => 1, 'spoiled' => true]);
		self::assertSame(200, $spoiledResult['status'], "a real boolean spoiled:true must still be accepted: {$spoiledResult['body']}");

		$freshProductId = self::insertProduct('H9 Spoiled False Product', $locationId);
		self::insertStock($freshProductId, $locationId, 5, date('Y-m-d', strtotime('+30 days')));

		$freshResult = self::Request('POST', '/api/stock/products/' . $freshProductId . '/consume', ['amount' => 1, 'spoiled' => false]);
		self::assertSame(200, $freshResult['status'], "a real boolean spoiled:false must still be accepted: {$freshResult['body']}");

		$statement = self::$db->prepare('SELECT spoiled FROM stock_log WHERE product_id = ? ORDER BY id DESC LIMIT 1');

		$statement->execute([$spoiledProductId]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'spoiled:true must be stored as the integer 1');

		$statement->execute([$freshProductId]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'spoiled:false must be stored as the integer 0');
	}
}
