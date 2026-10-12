<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\LabelIdentityService;
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
 *   ExceptionController as a 500. Five of the ten (victual.openapi.json's requestBody.required
 *   is false for each: the four /stock/shoppinglist/* operations other than add-product/
 *   remove-product, plus chores/executions/calculate-next-assignments) now default the body
 *   to [] so the field's own documented default applies; the other five
 *   (requestBody.required: true) now refuse the same way AddProduct()/ConsumeProduct()
 *   already refused a null body, with 400 rather than a crash.
 *
 * Round 2 added three more defects the same shape of probing found:
 *
 * - **A malformed-but-present body was indistinguishable from an absent one.**
 *   GetParsedAndFilteredRequestBody() used to return null for a body Slim's own parser could
 *   not read - truncated JSON, the literal "null", a bare scalar, a JSON array - exactly as
 *   it does for a body that was never sent, so "?? []" applied an optional route's *defaults*
 *   to a request that actually named different values it just could not parse (a truncated
 *   "clear" body naming a different list_id emptied the *default* list instead), and a
 *   required route's RequireRequestBody() could not refuse it either since the check is only
 *   "=== null". The method now reads the raw body itself and reports three distinct results:
 *   empty (null, absence - the only shape a caller may default), a JSON object (the parsed
 *   array), or anything else (always a 400, whatever the route's requestBody.required says).
 * - **allow_subproduct_substitution had the same misread as spoiled.** ConsumeProduct() and
 *   OpenProduct() both read it as the raw request value; a string "false" is truthy in PHP,
 *   so it silently allowed substitution the caller meant to refuse. Fixed the same way, with
 *   WireBooleans::RequireBoolean().
 * - **done_only (ClearShoppingList) and skipped (TrackChoreExecution) read a malformed value
 *   as false via filter_var(...FILTER_VALIDATE_BOOLEAN).** For done_only that is destructive.
 *   not merely wrong: false means "clear the whole list", so a value filter_var() cannot read
 *   was cleared as if the caller had explicitly asked for it. The UI sends a real boolean for
 *   both (public/viewjs/shoppinglist.js, choretracking.js, choresoverview.js), so both now go
 *   through RequireBoolean() too.
 *
 * Also fixed: PUT /api/user/settings/{settingKey} (requestBody.required: true) read
 * $requestBody['value'] straight off a null body and stored NULL as the setting; it now
 * refuses an absent body with RequireRequestBody(), like the other required-body routes.
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
			'STOCK_PURCHASE',
			'STOCK_TRANSFER',
			'STOCK_INVENTORY',
			'STOCK_EDIT',
			'STOCK_OPEN',
			'STOCK_VIEW',
			'TASKS_VIEW',
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

		return self::Send($spec);
	}

	/**
	 * Like Request(), but for a body that is deliberately not a well-formed JSON object:
	 * truncated JSON, the literal "null", a bare scalar, or a JSON array - sent through
	 * request-subprocess-helper.php's "rawBody" key, verbatim, with no json_encode() of its
	 * own (round 2 of issue #498/#487 H9: a malformed body must be refused, never treated as
	 * an absent one). $rawBody of null sends no body at all, the same "absent" case Request()
	 * sends when its own $body is null - the one way to also test $setContentType = false,
	 * for the converse round-2 finding: an absent body must apply an optional route's
	 * defaults even when the caller also omitted Content-Type.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function RawRequest(string $method, string $path, ?string $rawBody, bool $setContentType = true): array
	{
		$headers = ['VICTUAL-API-KEY' => self::$apiKey];

		if ($setContentType)
		{
			$headers['Content-Type'] = 'application/json';
		}

		$spec = [
			'method' => $method,
			'path' => $path,
			'headers' => $headers,
		];

		if ($rawBody !== null)
		{
			$spec['rawBody'] = $rawBody;
		}

		return self::Send($spec);
	}

	/**
	 * Runs $spec through request-subprocess-helper.php and returns its decoded answer.
	 *
	 * Asserts, for every response this class observes regardless of status, that no server
	 * file path reached the wire - the blanket form of the H9 path-disclosure claim ("several
	 * responses expose /app/... call sites"), rather than one assertion per refusal case.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function Send(array $spec): array
	{
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

		$requestLabel = ($spec['method'] ?? '?') . ' ' . ($spec['path'] ?? '?');

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for $requestLabel. stdout: $output\nstderr: $errors");

		// json_encode() escapes "/" as "\/" by default, so the raw response body never
		// contains the literal substring "/app/" even when it names that path - checking the
		// raw bytes would silently never fire. Decoded and re-encoded with
		// JSON_UNESCAPED_SLASHES first, so the check is against what the path actually says
		// rather than how JSON happens to spell a slash.
		$decodedForPathCheck = json_decode((string)$result['body'], true);
		$searchable = $decodedForPathCheck === null ? (string)$result['body'] : json_encode($decodedForPathCheck, JSON_UNESCAPED_SLASHES);
		self::assertStringNotContainsString('/app/', $searchable,
			"$requestLabel: no server file path may reach a response body ({$result['body']})");

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

	// --- A present-but-unparseable body is always refused, never treated as absent ---

	/**
	 * Round 2's own reproduction: a truncated body naming a *different* list_id used to be
	 * read as null by GetParsedAndFilteredRequestBody(), exactly like a genuinely absent one,
	 * so "?? []" applied list_id's default (1) - clearing the caller's own list's neighbour
	 * rather than the list actually named, and rather than refusing the unreadable request.
	 */
	public function testTruncatedBodyOnClearShoppingListIsRefusedAndClearsNeitherList(): void
	{
		$otherListId = self::insertRow('shopping_lists', ['name' => 'H9 Round2 Other List']);
		$defaultRowId = self::insertRow('shopping_list', ['note' => 'H9 round2 default list item', 'shopping_list_id' => 1, 'done' => 0]);
		$otherRowId = self::insertRow('shopping_list', ['note' => 'H9 round2 other list item', 'shopping_list_id' => $otherListId, 'done' => 0]);

		$result = self::RawRequest('POST', '/api/stock/shoppinglist/clear', '{"list_id":' . $otherListId . ',"done_only":true');

		self::AssertRefusedAsError400($result, 'POST /stock/shoppinglist/clear with truncated JSON');

		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE id IN (?, ?)');
		$statement->execute([$defaultRowId, $otherRowId]);
		self::assertSame(2, (int)$statement->fetchColumn(), 'a refused request must not have cleared either list - not the one it named and not the default it could not fall back to');
	}

	public function testJsonNullBodyOnClearShoppingListIsRefusedAndLeavesTheListUntouched(): void
	{
		$rowId = self::insertRow('shopping_list', ['note' => 'H9 round2 null body item', 'shopping_list_id' => 1, 'done' => 0]);

		$result = self::RawRequest('POST', '/api/stock/shoppinglist/clear', 'null');

		self::AssertRefusedAsError400($result, 'POST /stock/shoppinglist/clear with a JSON null body');

		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE id = ?');
		$statement->execute([$rowId]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'a JSON null body is present, not absent, and must not default to clearing the list');
	}

	public function testScalarBodyOnClearShoppingListIsRefusedAndLeavesTheListUntouched(): void
	{
		$rowId = self::insertRow('shopping_list', ['note' => 'H9 round2 scalar body item', 'shopping_list_id' => 1, 'done' => 0]);

		$result = self::RawRequest('POST', '/api/stock/shoppinglist/clear', '"clear everything"');

		self::AssertRefusedAsError400($result, 'POST /stock/shoppinglist/clear with a scalar JSON body');

		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE id = ?');
		$statement->execute([$rowId]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'a scalar JSON body is present, not absent, and must not default to clearing the list');
	}

	public function testArrayBodyOnClearShoppingListIsRefusedAndLeavesTheListUntouched(): void
	{
		$rowId = self::insertRow('shopping_list', ['note' => 'H9 round2 array body item', 'shopping_list_id' => 1, 'done' => 0]);

		$result = self::RawRequest('POST', '/api/stock/shoppinglist/clear', '[1,2,3]');

		self::AssertRefusedAsError400($result, 'POST /stock/shoppinglist/clear with a JSON array body');

		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE id = ?');
		$statement->execute([$rowId]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'a JSON array body is present, not absent, and must not default to clearing the list');
	}

	/**
	 * The same four malformed shapes, on a requestBody.required: true route this time, in a
	 * single test (RequireRequestBody() and the new shape guard are the same one check
	 * either way, so this exercises the one code path with all four bad inputs rather than
	 * repeating identical assertions in four methods).
	 */
	public function testMalformedBodiesOnARequiredBodyRouteAreRefusedWithoutCreatingARow(): void
	{
		foreach (['{"product_id":1,' => 'truncated JSON', 'null' => 'a JSON null body', '"x"' => 'a scalar JSON body', '[1]' => 'a JSON array body'] as $rawBody => $label)
		{
			$before = self::shoppingListCount();

			$result = self::RawRequest('POST', '/api/stock/shoppinglist/add-product', $rawBody);

			self::AssertRefusedAsError400($result, "POST /stock/shoppinglist/add-product with $label");
			self::assertSame($before, self::shoppingListCount(), "a refused request ($label) must not have added a row");
		}
	}

	/**
	 * The RequestedTimestamp()-based routes read the body through a differently-shaped call
	 * (GetParsedAndFilteredRequestBody() runs before HandleApiCall()'s closure even starts,
	 * see TrackChoreExecution()), so a malformed body here is refused before HandleApiCall()
	 * ever runs - still a clean 400, because the refusal is a Slim HttpException and
	 * ExceptionController maps its own status regardless of which wrapper was, or was not,
	 * involved.
	 */
	public function testMalformedBodyOnChoreExecuteIsRefusedWithoutLoggingAnExecution(): void
	{
		$choreId = self::insertRow('chores', ['name' => 'H9 Round2 Malformed Execute Chore', 'period_type' => 'manually']);

		$result = self::RawRequest('POST', '/api/chores/' . $choreId . '/execute', '{"tracked_time":');

		self::AssertRefusedAsError400($result, 'POST /chores/{id}/execute with truncated JSON');

		$statement = self::$db->prepare('SELECT count(*) FROM chores_log WHERE chore_id = ?');
		$statement->execute([$choreId]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'a refused request must not have logged an execution');
	}

	// --- The converse: absence is content-type-independent too ---

	/**
	 * Round 2's other finding: a truly absent body used to be refused with 400 "Bad
	 * Content-Type" when the caller also omitted the header, on a route #498 says should
	 * apply its defaults to an absent body - the Content-Type check ran before the emptiness
	 * check could establish that there was no body to have a type at all.
	 */
	public function testAbsentBodyWithNoContentTypeOnAnOptionalBodyRouteAppliesTheDefaultList(): void
	{
		$locationId = self::insertLocation('H9 Round2 No Content Type Location');
		$productId = self::insertProduct('H9 Round2 No Content Type Product', $locationId, 5);

		$result = self::RawRequest('POST', '/api/stock/shoppinglist/add-missing-products', null, false);

		self::assertSame(204, $result['status'], "an absent body with no Content-Type must still apply the documented default, not 400 'Bad Content-Type': {$result['body']}");
		self::assertTrue(self::shoppingListHasProduct($productId), 'the default list must have had the missing product added to it');
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

	/**
	 * Round 2's own reproduction: is_numeric("-1abc") is false, so the first revision's guard
	 * (is_numeric($v) && intval($v) < 0) let it straight through to intval(), which still
	 * reads -1 out of the numeric prefix and reached PostgreSQL as a literal negative LIMIT.
	 */
	public function testLimitWithTrailingGarbageIsRefused(): void
	{
		$result = self::Request('GET', '/api/objects/products?limit=-1abc');

		self::AssertRefusedAsError400($result, 'GET /objects/products?limit=-1abc');
	}

	public function testOffsetWithTrailingGarbageIsRefused(): void
	{
		$result = self::Request('GET', '/api/objects/products?offset=-3x');

		self::AssertRefusedAsError400($result, 'GET /objects/products?offset=-3x');
	}

	/**
	 * Round 2's other query-shape finding: QueryData()'s is_array($query['query']) check is
	 * about the top-level shape of "query" itself, not each element inside it, so a nested
	 * array item ("?query[0][]=name=x" parses to $query['query'] = [0 => ['name=x']]) passed
	 * that check and reached FilterData()'s preg_match($pattern, $q, ...) with $q itself an
	 * array, which is a TypeError - is_string($q) now refuses it before preg_match() is
	 * called. Exercised on /api/tasks rather than /api/objects/products, matching the
	 * coordinator's own reproduction of this specific case.
	 */
	public function testNestedQueryArrayItemIsRefused(): void
	{
		$result = self::Request('GET', '/api/tasks?query%5B0%5D%5B%5D=name%3Dx');

		self::AssertRefusedAsError400($result, 'GET /tasks?query[0][]=name=x (a nested array item)');
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

	// ================================================================================
	// Group D: allow_subproduct_substitution on POST .../consume and POST .../open
	// ================================================================================

	/**
	 * A parent product with no stock of its own and a child (parent_product_id = parent)
	 * that does, both in the same quantity unit so no quantity_unit_conversions row is
	 * needed - the minimal fixture the substitution branch in
	 * StockService::GetProductStockEntries() (joined through products_resolved) actually
	 * needs.
	 *
	 * @return array{0: int, 1: int, 2: int} [parentId, childId, childStockRowId]
	 */
	private static function insertSubstitutionFixture(string $label): array
	{
		$locationId = self::insertLocation("H9 $label Location");
		$parentId = self::insertProduct("H9 $label Parent", $locationId);
		$childId = self::insertRow('products', [
			'name' => "H9 $label Child",
			'location_id' => $locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'parent_product_id' => $parentId,
		]);
		$childStockRowId = self::insertStock($childId, $locationId, 3, date('Y-m-d', strtotime('+30 days')));

		return [$parentId, $childId, $childStockRowId];
	}

	/**
	 * The same misread "spoiled" had: the string "false" is truthy in PHP, so it silently
	 * allowed substitution from the child even though the caller meant to refuse it - a
	 * parent with no stock of its own that should refuse with "not enough stock" instead
	 * consumed the child's (issue #498/#487 H9 round 2).
	 */
	public function testConsumeWithStringFalseSubstitutionIsRefusedWithoutConsumingStock(): void
	{
		[$parentId, $childId, $childStockRowId] = self::insertSubstitutionFixture('Consume String Substitution');

		$result = self::Request('POST', '/api/stock/products/' . $parentId . '/consume', ['amount' => 1, 'allow_subproduct_substitution' => 'false']);

		self::AssertRefusedAsError400($result, 'POST .../consume with allow_subproduct_substitution:"false"');
		self::assertSame(3.0, self::stockAmount($childStockRowId), 'a refused consume must not have moved the child\'s stock');
		self::assertSame(0, self::stockLogCountFor($childId), 'a refused consume must not have written a ledger row for the child');
		self::assertSame(0, self::stockLogCountFor($parentId), 'a refused consume must not have written a ledger row for the parent either');
	}

	/** Positive control: a real boolean true still allows substitution exactly as before. */
	public function testConsumeWithARealBooleanSubstitutionStillSubstitutes(): void
	{
		[$parentId, $childId, $childStockRowId] = self::insertSubstitutionFixture('Consume Real Substitution');

		$result = self::Request('POST', '/api/stock/products/' . $parentId . '/consume', ['amount' => 1, 'allow_subproduct_substitution' => true]);

		self::assertSame(200, $result['status'], "a real boolean allow_subproduct_substitution:true must still be accepted and substitute: {$result['body']}");
		self::assertSame(2.0, self::stockAmount($childStockRowId), 'a real true must still consume from the child');
	}

	public function testOpenWithStringFalseSubstitutionIsRefusedWithoutOpeningStock(): void
	{
		[$parentId, $childId, $childStockRowId] = self::insertSubstitutionFixture('Open String Substitution');

		$result = self::Request('POST', '/api/stock/products/' . $parentId . '/open', ['amount' => 1, 'allow_subproduct_substitution' => 'false']);

		self::AssertRefusedAsError400($result, 'POST .../open with allow_subproduct_substitution:"false"');

		$statement = self::$db->prepare('SELECT open FROM stock WHERE id = ?');
		$statement->execute([$childStockRowId]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'a refused open must not have opened the child\'s stock');
	}

	/** Positive control: a real boolean true still opens the child's stock exactly as before. */
	public function testOpenWithARealBooleanSubstitutionStillOpens(): void
	{
		[$parentId, $childId, $childStockRowId] = self::insertSubstitutionFixture('Open Real Substitution');

		$result = self::Request('POST', '/api/stock/products/' . $parentId . '/open', ['amount' => 1, 'allow_subproduct_substitution' => true]);

		self::assertSame(200, $result['status'], "a real boolean allow_subproduct_substitution:true must still be accepted and open the child's stock: {$result['body']}");

		$statement = self::$db->prepare('SELECT open FROM stock WHERE id = ?');
		$statement->execute([$childStockRowId]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'a real true must still open the child\'s stock');
	}

	// ================================================================================
	// Group E: done_only (ClearShoppingList) and skipped (TrackChoreExecution)
	// ================================================================================

	/**
	 * done_only:false means "clear the whole list" (StockService::ClearShoppingList()), so
	 * filter_var(...FILTER_VALIDATE_BOOLEAN) reading a malformed value as false was
	 * destructive, not merely wrong: a caller who meant "just the done ones" and sent a
	 * value filter_var() cannot read would have had the whole list cleared instead (issue
	 * #498/#487 H9 round 2).
	 */
	public function testClearShoppingListWithGarbageDoneOnlyIsRefusedAndLeavesTheListUntouched(): void
	{
		$rowId = self::insertRow('shopping_list', ['note' => 'H9 round2 done_only garbage item', 'shopping_list_id' => 1, 'done' => 0]);

		$result = self::Request('POST', '/api/stock/shoppinglist/clear', ['done_only' => 'garbage']);

		self::AssertRefusedAsError400($result, 'POST /stock/shoppinglist/clear with done_only:"garbage"');

		$statement = self::$db->prepare('SELECT count(*) FROM shopping_list WHERE id = ?');
		$statement->execute([$rowId]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'a malformed done_only must not silently mean "clear everything"');
	}

	public function testChoreExecuteWithGarbageSkippedIsRefusedWithoutLoggingAnExecution(): void
	{
		$choreId = self::insertRow('chores', ['name' => 'H9 Round2 Garbage Skipped Chore', 'period_type' => 'manually']);

		$result = self::Request('POST', '/api/chores/' . $choreId . '/execute', ['skipped' => 'garbage']);

		self::AssertRefusedAsError400($result, 'POST /chores/{id}/execute with skipped:"garbage"');

		$statement = self::$db->prepare('SELECT count(*) FROM chores_log WHERE chore_id = ?');
		$statement->execute([$choreId]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'a refused request must not have logged an execution');
	}

	// ================================================================================
	// Group F: PUT /api/user/settings/{settingKey}
	// ================================================================================

	/**
	 * UsersApiController::SetUserSetting() used to read $requestBody['value'] straight off a
	 * null body (a PHP warning, not a crash) and store the resulting NULL as the setting's
	 * new value, although victual.openapi.json documents this route's requestBody as
	 * required: true (issue #498/#487 H9 round 2).
	 */
	public function testSetUserSettingWithNoBodyIsRefusedAndLeavesTheSettingUnchanged(): void
	{
		self::$db->exec("DELETE FROM user_settings WHERE user_id = " . self::USER_ID . " AND key = 'h9_round2_setting'");
		self::$db->exec("INSERT INTO user_settings (user_id, key, value) VALUES (" . self::USER_ID . ", 'h9_round2_setting', 'original')");

		$result = self::Request('PUT', '/api/user/settings/h9_round2_setting');

		self::AssertRefusedAsError400($result, 'PUT /user/settings/{key} with no body');

		$statement = self::$db->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key = 'h9_round2_setting'");
		$statement->execute([self::USER_ID]);
		self::assertSame('original', $statement->fetchColumn(), 'a refused request must not have overwritten the setting with NULL');
	}

	// ================================================================================
	// Group G: required fields of the stock-booking request bodies (issue #804, #796)
	// ================================================================================

	/**
	 * Every inline request schema below omitted its object-level `required` list although
	 * the controller refuses the field's absence with 400, so `{}` validated and generated
	 * clients typed the field optional. The document half asserts the list and what it
	 * means to a JSON Schema validator; the runtime half asserts the 400 text and that no
	 * stock, ledger or shopping-list row was written. Defaulted fields (best_before_date,
	 * price, location_id, stock_entry_id, spoiled, list_id, ...) are never required.
	 *
	 * Each entry: path, required list, a minimal valid body, the exact refusal text per
	 * required field, and one body of optional fields only.
	 *
	 * @return array<string, array{0: string, 1: list<string>, 2: array, 3: array<string, string>, 4: array}>
	 */
	public static function requiredFieldRoutes(): array
	{
		$amount = ['amount' => 'An amount is required'];
		$routes = [];

		foreach (['{productId}' => '/stock/products/{productId}', '{barcode}' => '/stock/products/by-barcode/{barcode}'] as $base)
		{
			$routes[$base . '/add'] = [$base . '/add', ['amount'], ['amount' => 1], $amount, ['price' => 1]];
			$routes[$base . '/consume'] = [$base . '/consume', ['amount'], ['amount' => 1], $amount, ['spoiled' => false]];
			$routes[$base . '/open'] = [$base . '/open', ['amount'], ['amount' => 1], $amount, ['allow_subproduct_substitution' => false]];
			$routes[$base . '/transfer'] = [$base . '/transfer', ['amount', 'location_id_from', 'location_id_to'], ['amount' => 1, 'location_id_from' => 1, 'location_id_to' => 2], $amount + [
				'location_id_from' => 'A transfer from location is required',
				'location_id_to' => 'A transfer to location is required',
			], ['stock_entry_id' => 1]];
			// The runtime text really does say "An new amount": asserted verbatim so a fix
			// to the typo is a deliberate, visible change.
			$routes[$base . '/inventory'] = [$base . '/inventory', ['new_amount'], ['new_amount' => 1], ['new_amount' => 'An new amount is required'], ['price' => 1]];
		}

		foreach (['/stock/locations/{locationId}/weigh', '/stock/locations/by-label/{code}/weigh'] as $path)
		{
			$routes[$path] = [$path, ['gross_amount'], ['gross_amount' => 1], ['gross_amount' => 'A gross_amount is required'], ['gross_qu_id' => 2]];
		}

		foreach (['/stock/shoppinglist/add-product', '/stock/shoppinglist/remove-product'] as $path)
		{
			$routes[$path] = [$path, ['product_id'], ['product_id' => 1], ['product_id' => 'No product id was supplied'], ['product_amount' => 1]];
		}

		return $routes;
	}

	private static function requestSchemaFor(string $path): object
	{
		$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);

		return $document->paths->{$path}->post->requestBody->content->{'application/json'}->schema;
	}

	private static function validatesAgainstRequestSchema(string $path, array $body): bool
	{
		// Fresh decode per validation, as assertCandidateAgreesWithServer() does: Opis is
		// not guaranteed to leave a reused schema untouched. JSON_FORCE_OBJECT makes an
		// empty body the object {} rather than the array [].
		$data = json_decode(json_encode($body, JSON_FORCE_OBJECT));

		return (new \Opis\JsonSchema\Validator())->validate($data, self::requestSchemaFor($path))->isValid();
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('requiredFieldRoutes')]
	public function testRequestSchemaDocumentsTheRequiredFields(string $path, array $required, array $minimal, array $messages, array $optionalOnly): void
	{
		self::assertSame($required, self::requestSchemaFor($path)->required ?? null, "$path: the request schema's `required` list");

		self::assertFalse(self::validatesAgainstRequestSchema($path, []), "$path: {} must not validate");
		self::assertFalse(self::validatesAgainstRequestSchema($path, $optionalOnly), "$path: a body of optional fields only must not validate");
		self::assertTrue(self::validatesAgainstRequestSchema($path, $minimal), "$path: the minimal body must validate");

		foreach ($required as $field)
		{
			$without = $minimal;
			unset($without[$field]);
			self::assertFalse(self::validatesAgainstRequestSchema($path, $without), "$path: the minimal body without $field must not validate");
		}
	}

	/** @return array{0: int, 1: int, 2: int} stock, stock_log and shopping_list row counts */
	private static function bookingCounts(): array
	{
		return array_map(
			fn(string $table) => (int)self::$db->query("SELECT count(*) FROM $table")->fetchColumn(),
			['stock', 'stock_log', 'shopping_list']
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('requiredFieldRoutes')]
	public function testOmittingARequiredFieldIsRefusedWithTheDocumentedMessageAndBooksNothing(string $path, array $required, array $minimal, array $messages, array $optionalOnly): void
	{
		$locationId = self::insertLocation('804 Required Fields Location ' . md5($path));
		$productId = self::insertProduct('804 Required Fields Product ' . md5($path), $locationId);
		self::insertStock($productId, $locationId, 3, date('Y-m-d', strtotime('+30 days')));
		$barcode = '804-' . md5($path);
		self::insertRow('product_barcodes', ['product_id' => $productId, 'barcode' => $barcode]);

		$code = 'vctl:0000000000000';
		if (str_contains($path, '/by-label/'))
		{
			$epoch = (int)self::$db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
			self::$db->beginTransaction();
			$code = 'vctl:' . (new LabelIdentityService(self::$db))->IssueLocation($locationId, $epoch);
			self::$db->commit();
		}

		$url = '/api' . str_replace(['{productId}', '{barcode}', '{locationId}', '{code}'], [(string)$productId, $barcode, (string)$locationId, $code], $path);

		// Transfer's valid-looking location ids must not matter: the refusal precedes any lookup.
		$cases = ['{} (empty object)' => []];
		$cases['optional fields only'] = $optionalOnly;
		foreach ($required as $field)
		{
			$without = $minimal;
			unset($without[$field]);
			$cases["minimal body without $field"] = $without;
		}

		foreach ($cases as $label => $body)
		{
			$missing = array_values(array_filter($required, fn(string $field) => !array_key_exists($field, $body)));
			$expected = $messages[$missing[0]];

			$before = self::bookingCounts();
			$result = self::RawRequest('POST', $url, json_encode($body, JSON_FORCE_OBJECT));
			$decoded = self::AssertRefusedAsError400($result, "POST $path with $label");

			self::assertSame($expected, $decoded['error_message'], "POST $path with $label: the controller's refusal text");
			self::assertSame($before, self::bookingCounts(), "POST $path with $label: a refusal must write no stock, stock_log or shopping_list row");
		}
	}

	// ================================================================================
	// Group H: request-body schemas of eight more operations (issue #804)
	//
	// Each operation's requestBody schema in victual.openapi.json used to omit the fields the
	// runtime refuses without (POST /users/{id}/permissions even named the field
	// `permissions_id` where the controller reads `permission_id`). The document half pins
	// each schema with Opis; the runtime half (here for the user and stock operations, in
	// LabelApiTest for the label ones) proves the refusal the schema now promises is the
	// one the route gives, and that nothing was written first.
	// ================================================================================

	private const TARGET_USER_ID = 9801;

	/**
	 * Validates a JSON text against the requestBody schema of one operation, read off the
	 * document by path and method. The body is passed as JSON text so that `{}` stays an
	 * object rather than becoming the empty PHP array.
	 *
	 * @return string|null null when valid; otherwise "path: keyword" of the first deepest failure
	 */
	private static function ValidateRequestBody(string $path, string $method, string $json): ?string
	{
		static $document = null;
		$document ??= json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);

		$schema = $document->paths->{$path}->{$method}->requestBody->content->{'application/json'}->schema;

		$validator = new \Opis\JsonSchema\Validator();
		$validator->parser()->setOption('allowDefaults', false);
		$result = $validator->validate(json_decode($json, false, flags: JSON_THROW_ON_ERROR), json_decode(json_encode($schema), false));

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
	 * Sends a body as JSON text built from objects, so that an empty one reaches the route as
	 * `{}`: Send() round-trips its spec through an associative decode, which turns an empty
	 * object into the empty array and the route would see `[]` (a different refusal).
	 */
	private static function JsonObjectRequest(string $method, string $path, array $body): array
	{
		return self::RawRequest($method, $path, json_encode((object)$body));
	}

	private static function requestRequiredList(string $path, string $method): array
	{
		$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, flags: JSON_THROW_ON_ERROR);

		return $document['paths'][$path][$method]['requestBody']['content']['application/json']['schema']['required'] ?? [];
	}

	public static function requestSchemaProvider(): array
	{
		$hex = str_repeat('a', 64);

		return [
			'POST user permission' => ['/users/{userId}/permissions', 'post', ['permission_id'], ['permission_id' => 1], []],
			'PUT user permissions' => ['/users/{userId}/permissions', 'put', ['permissions'], ['permissions' => []], []],
			'POST labels pair' => ['/labels/pair', 'post', ['material'], ['material' => $hex], []],
			'POST labels rotate' => ['/labels/credentials/rotate', 'post', ['material', 'rotation_request_id'], ['material' => $hex, 'rotation_request_id' => $hex], []],
			'POST attempt result' => ['/labels/attempts/{attemptId}/result', 'post', ['outcome', 'detail'], ['outcome' => 'printed', 'detail' => new \stdClass()], []],
			'POST product open' => ['/stock/products/{productId}/open', 'post', [], ['amount' => 1], []],
			'POST entry measure' => ['/stock/entry/{entryId}/measure', 'post', ['amount', 'qu_id'], ['amount' => 1, 'qu_id' => 2], []],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('requestSchemaProvider')]
	public function testRequestSchemaRequiredListAndMinimalBody(string $path, string $method, array $required, array $minimal): void
	{
		self::assertSame($required, self::requestRequiredList($path, $method), "$method $path: the documented required list");

		if ($required === [])
		{
			return;
		}

		self::assertNotNull(self::ValidateRequestBody($path, $method, '{}'), "$method $path: {} must fail the schema");
		self::assertNull(self::ValidateRequestBody($path, $method, json_encode((object)$minimal)), "$method $path: the minimal body must pass");

		foreach (array_keys($minimal) as $dropped)
		{
			$without = $minimal;
			unset($without[$dropped]);
			self::assertNotNull(self::ValidateRequestBody($path, $method, json_encode((object)$without)), "$method $path: the minimal body without $dropped must fail");
		}
	}

	public function testPostUserPermissionDocumentsPermissionIdAndNotPermissionsId(): void
	{
		self::assertNotNull(self::ValidateRequestBody('/users/{userId}/permissions', 'post', '{"permissions_id": 1}'),
			'the old, never-working name must not satisfy the schema');
		self::assertNull(self::ValidateRequestBody('/users/{userId}/permissions', 'post', '{"permission_id": 1}'));
		self::assertNotNull(self::ValidateRequestBody('/users/{userId}/permissions', 'post', '{"permission_id": "1"}'), 'the documented type is integer');
	}

	public function testPutUserPermissionsDocumentsAnEmptyListAsValidAndRefusesNonIdLists(): void
	{
		self::assertNull(self::ValidateRequestBody('/users/{userId}/permissions', 'put', '{"permissions": []}'), 'an empty list is accepted by the runtime and by the schema');
		self::assertNull(self::ValidateRequestBody('/users/{userId}/permissions', 'put', '{"permissions": [1, 2]}'));

		foreach (['{"permissions": 1}', '{"permissions": [0]}', '{"permissions": [-1]}', '{"permissions": ["1"]}'] as $body)
		{
			self::assertNotNull(self::ValidateRequestBody('/users/{userId}/permissions', 'put', $body), "$body must fail the schema");
		}
	}

	public function testOpenMeasurementDocumentsAmountQuIdAndTareForAGrossReading(): void
	{
		$validate = static fn (string $measurement): ?string => self::ValidateRequestBody('/stock/products/{productId}/open', 'post', '{"amount": 1, "measurement": ' . $measurement . '}');

		self::assertNotNull($validate('{}'), 'a present measurement needs amount and qu_id');
		self::assertNotNull($validate('{"amount": 1}'), 'qu_id is required');
		self::assertNotNull($validate('{"qu_id": 2}'), 'amount is required');
		self::assertNull($validate('{"amount": 1, "qu_id": 2}'), 'a net reading needs no tare');
		self::assertNull($validate('{"amount": 1, "qu_id": 2, "gross": false}'), 'gross false needs no tare');
		self::assertNotNull($validate('{"amount": 1, "qu_id": 2, "gross": true}'), 'gross true needs tare');
		self::assertNull($validate('{"amount": 1, "qu_id": 2, "gross": true, "tare": 0.1}'));
		self::assertNull(self::ValidateRequestBody('/stock/products/{productId}/open', 'post', '{"amount": 1}'), 'measurement itself stays optional');
	}

	/** The by-barcode open reads the same measurement object (OpenProductByBarcode delegates to OpenProduct), so documents it identically. */
	public function testByBarcodeOpenDocumentsTheSameMeasurementAsOpen(): void
	{
		$path = '/stock/products/by-barcode/{barcode}/open';
		$validate = static fn (string $measurement): ?string => self::ValidateRequestBody($path, 'post', '{"amount": 1, "measurement": ' . $measurement . '}');

		self::assertNotNull($validate('{}'));
		self::assertNotNull($validate('{"amount": 1}'));
		self::assertNull($validate('{"amount": 1, "qu_id": 2}'));
		self::assertNotNull($validate('{"amount": 1, "qu_id": 2, "gross": true}'));
		self::assertNull($validate('{"amount": 1, "qu_id": 2, "gross": true, "tare": 0.1}'));

		$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame(
			$document['paths']['/stock/products/{productId}/open']['post']['requestBody']['content']['application/json']['schema']['properties']['measurement'],
			$document['paths'][$path]['post']['requestBody']['content']['application/json']['schema']['properties']['measurement'],
			'the two open operations document one measurement object'
		);
	}

	public function testMeasureDocumentsTareForAGrossReading(): void
	{
		$validate = static fn (string $body): ?string => self::ValidateRequestBody('/stock/entry/{entryId}/measure', 'post', $body);

		self::assertNull($validate('{"amount": 1, "qu_id": 2}'));
		self::assertNull($validate('{"amount": 1, "qu_id": 2, "gross": false}'));
		self::assertNotNull($validate('{"amount": 1, "qu_id": 2, "gross": true}'), 'gross true needs tare');
		self::assertNull($validate('{"amount": 1, "qu_id": 2, "gross": true, "tare": 0.1}'));
	}

	// --- runtime half: users ------------------------------------------------------

	/** Gives the caller USERS_EDIT (idempotent) and (re)creates a target user holding two known permissions. */
	private static function prepareUserPermissionFixture(): array
	{
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . ", id FROM permission_hierarchy WHERE name = 'USERS_EDIT' ON CONFLICT DO NOTHING");
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = ' . self::TARGET_USER_ID);
		self::$db->exec('DELETE FROM users WHERE id = ' . self::TARGET_USER_ID);
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::TARGET_USER_ID . ", 'api-input-shapes-target', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::TARGET_USER_ID . ", id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'TASKS_VIEW')");

		return self::targetPermissions();
	}

	private static function targetPermissions(): array
	{
		return self::$db->query('SELECT permission_id FROM user_permissions WHERE user_id = ' . self::TARGET_USER_ID . ' ORDER BY permission_id')->fetchAll(PDO::FETCH_COLUMN);
	}

	public function testPostUserPermissionWithoutPermissionIdIsRefusedAndAssignsNothing(): void
	{
		$before = self::prepareUserPermissionFixture();
		self::assertCount(2, $before);

		foreach ([[], ['permissions_id' => 1], ['permission_id' => null]] as $body)
		{
			$result = self::JsonObjectRequest('POST', '/api/users/' . self::TARGET_USER_ID . '/permissions', $body);

			$decoded = self::AssertRefusedAsError400($result, 'POST /users/{id}/permissions ' . json_encode($body));
			self::assertSame('permission_id is required', $decoded['error_message']);
			self::assertEquals($before, self::targetPermissions(), 'a refused request must not have assigned a permission');
		}
	}

	public function testPutUserPermissionsWithoutAnIdListIsRefusedAndKeepsTheAssignments(): void
	{
		$before = self::prepareUserPermissionFixture();

		$cases = [
			'no permissions key' => [[], 'permissions must be an array of integer ids'],
			'null' => [['permissions' => null], 'permissions must be an array of integer ids'],
			'not a list' => [['permissions' => 1], 'permissions must be an array of integer ids'],
			'non-positive id' => [['permissions' => [0]], 'permissions must contain positive integer ids'],
			'string id' => [['permissions' => ['1']], 'permissions must contain positive integer ids'],
		];

		foreach ($cases as $label => [$body, $message])
		{
			$decoded = self::AssertRefusedAsError400(self::JsonObjectRequest('PUT', '/api/users/' . self::TARGET_USER_ID . '/permissions', $body), "PUT /users/{id}/permissions ($label)");
			self::assertSame($message, $decoded['error_message'], $label);
			self::assertEquals($before, self::targetPermissions(), "$label: a refused request must leave the assignments as they were");
		}
	}

	/** The empty list is valid at runtime, which is why the schema must not forbid it. */
	public function testPutUserPermissionsWithAnEmptyListRemovesEveryAssignment(): void
	{
		self::prepareUserPermissionFixture();

		$result = self::Request('PUT', '/api/users/' . self::TARGET_USER_ID . '/permissions', ['permissions' => []]);

		self::assertSame(204, $result['status'], $result['body']);
		self::assertSame([], self::targetPermissions());
	}

	// --- runtime half: open measurement -------------------------------------------

	/** @return array{0: int, 1: string} the product id and a digest of every row the open could have written */
	private static function openMeasurementFixture(string $name): array
	{
		$locationId = self::insertLocation($name . ' location');
		$productId = self::insertProduct($name, $locationId);
		self::insertStock($productId, $locationId, 2, '2099-12-31');

		return [$productId, self::bookingDigest($productId)];
	}

	private static function bookingDigest(int $productId): string
	{
		return (string)self::$db->query("SELECT md5(coalesce((SELECT string_agg(row_to_json(s)::text, ',' ORDER BY s.id) FROM stock s WHERE s.product_id = $productId), '')"
			. " || '|' || coalesce((SELECT string_agg(row_to_json(l)::text, ',' ORDER BY l.id) FROM stock_log l WHERE l.product_id = $productId), ''))")->fetchColumn();
	}

	public function testOpenWithAnIncompleteMeasurementIsRefusedAndWritesNothing(): void
	{
		[$productId, $digest] = self::openMeasurementFixture('Open Measurement Required');
		$entryId = 'api-input-shapes-' . $productId;

		$cases = [
			'empty measurement' => [[], 'A measurement requires a positive amount'],
			'no qu_id' => [['amount' => 1], 'A measurement requires a quantity unit'],
			'no amount' => [['qu_id' => 2], 'A measurement requires a positive amount'],
			'gross without tare' => [['amount' => 1, 'qu_id' => 2, 'gross' => true], 'A gross measurement requires a tare weight'],
		];

		foreach ($cases as $label => [$measurement, $message])
		{
			$body = ['amount' => 1, 'stock_entry_id' => $entryId, 'measurement' => (object)$measurement];
			$decoded = self::AssertRefusedAsError400(self::JsonObjectRequest('POST', '/api/stock/products/' . $productId . '/open', $body), "POST .../open measurement ($label)");

			self::assertSame($message, $decoded['error_message'], $label);
			self::assertSame($digest, self::bookingDigest($productId), "$label: a refused open must write neither stock nor stock_log");
		}
	}

	public function testMeasureOfAGrossReadingWithoutTareIsRefusedAndWritesNothing(): void
	{
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . ", id FROM permission_hierarchy WHERE name = 'STOCK_EDIT' ON CONFLICT DO NOTHING");
		[$productId] = self::openMeasurementFixture('Measure Gross Without Tare');
		self::$db->exec("UPDATE stock SET amount = 1, open = 1, opened_date = CURRENT_DATE WHERE product_id = $productId");
		$digest = self::bookingDigest($productId);
		$stockId = (int)self::$db->query("SELECT id FROM stock WHERE product_id = $productId")->fetchColumn();

		$decoded = self::AssertRefusedAsError400(
			self::JsonObjectRequest('POST', '/api/stock/entry/' . $stockId . '/measure', ['amount' => 1, 'qu_id' => 2, 'gross' => true]),
			'POST .../measure gross without tare'
		);

		self::assertSame('A gross measurement requires a tare weight', $decoded['error_message']);
		self::assertSame($digest, self::bookingDigest($productId), 'a refused measurement must write neither stock nor stock_log');
	}

	/** Positive control: the documented complete gross measurement opens the entry, so the refusals above came from the measurement checks. */
	public function testOpenWithACompleteGrossMeasurementOpensTheEntry(): void
	{
		[$productId, $digest] = self::openMeasurementFixture('Open Measurement Complete');

		$result = self::Request('POST', '/api/stock/products/' . $productId . '/open',
			['amount' => 1, 'stock_entry_id' => 'api-input-shapes-' . $productId, 'measurement' => ['amount' => 1.5, 'qu_id' => 2, 'gross' => true, 'tare' => 0.5]]);

		self::assertSame(200, $result['status'], $result['body']);
		self::assertNotSame($digest, self::bookingDigest($productId), 'the complete request books the opening');
	}
}
