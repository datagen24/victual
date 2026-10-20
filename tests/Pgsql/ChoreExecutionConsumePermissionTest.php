<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\ChoresService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * Issue #604 (#487 remediation, the same composite-permission class as #532/#591): POST
 * /api/chores/{choreId}/execute (ChoresService::TrackChore()/TrackChoreExecution()) checked
 * only CHORE_TRACK_EXECUTION, even when the chore has consume_product_on_execution set and
 * the call therefore also consumes stock through StockService::ConsumeProduct() - the same
 * booking POST /api/stock/products/{id}/consume requires STOCK_CONSUME for
 * (StockApiController::ConsumeProduct()). A user who may track chores but may not consume
 * stock could still reduce stock by executing a chore that consumes a product.
 *
 * Maintainer-shaped fix (mirroring #597's UndoChoreExecution()/STOCK_EDIT precedent and
 * #532's RecipesService::ConsumeRecipe()/STOCK_PURCHASE precedent): TrackChore() now checks
 * STOCK_CONSUME, inside its transaction and before any write (including the chores_log insert
 * itself), whenever the chore is actually about to consume a product. A chore with
 * consume_product_on_execution = 0, or no product_id, needs only CHORE_TRACK_EXECUTION,
 * unchanged.
 *
 * Follows ChoreExecutionStockUndoTest.php's own pattern for this same service: call
 * ChoresService/StockService directly for the row-level, direct-service-call case and
 * request-subprocess-helper.php (the same harness) for the HTTP-level authorization cases.
 * Every case asserts on `chores_log`/`stock`/`stock_log` rows, not only a status or a return
 * value, and a refusal is checked to leave every row byte-for-byte unchanged.
 */
class ChoreExecutionConsumePermissionTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static ChoresService $chores;
	private static StockService $stock;
	private static int $location;

	/** @var array<string,string> plaintext API keys by role name */
	private static array $keys = [];

	/**
	 * Creates a location, the ambient caller 9000 with no permissions, and three API users:
	 * CHORE_TRACK_EXECUTION only, that plus STOCK_CONSUME, and STOCK_CONSUME only.
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$chores = ChoresService::GetInstance();
		self::$stock = StockService::GetInstance();

		self::$location = self::insertRow('locations', ['name' => 'Chore Consume Permission Location']);

		// This class's own ambient caller (VICTUAL_USER_ID 9000, from PgsqlSchemaTestCase) is
		// inserted here with no permissions at all - the fixture the fail-closed
		// direct-service-call case below depends on. Unlike RecipeRouteAuthzTest's own
		// equivalent fixture (which never needs a `users` row for 9000 at all, because
		// RecipesService::ConsumeRecipe() never looks the acting user up), ChoresService::
		// TrackChore() requires $doneBy - which defaults to VICTUAL_USER_ID - to name an
		// existing row before it ever reaches the STOCK_CONSUME check, so the row must exist.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'chore-consume-direct-caller', 'fixture')");

		$users = [
			// The one permission the unfixed route checked - the scenario the leak actually
			// happens in.
			'track-only' => ['id' => 9700, 'permissions' => ['CHORE_TRACK_EXECUTION']],
			'track-consume' => ['id' => 9701, 'permissions' => ['CHORE_TRACK_EXECUTION', 'STOCK_CONSUME']],
			// Holds STOCK_CONSUME but never CHORE_TRACK_EXECUTION, so it must never reach the
			// chore at all - isolating that the STOCK_CONSUME clause is additive, not a
			// substitute for the base permission.
			'consume-only' => ['id' => 9702, 'permissions' => ['STOCK_CONSUME']],
		];

		$insertUser = self::$db->prepare('INSERT INTO users(id, username, password) VALUES (?, ?, ?)');
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');

		foreach ($users as $name => $user)
		{
			$insertUser->execute([$user['id'], 'chore-consume-' . $name, 'fixture']);
			foreach ($user['permissions'] as $permission)
			{
				$grant->execute([$user['id'], $permission]);
			}
			self::$keys[$name] = self::issueKey($user['id']);
		}
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirroring ChoreExecutionStockUndoTest.php's own)
	// ------------------------------------------------------------------------------

	/** Inserts a default-type API key valid for 30 days for the user and returns the plain key. */
	private static function issueKey(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/** Inserts one row into $table from the column map and returns its id. */
	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	/**
	 * Inserts a product in the fixture location with quantity unit 2 for purchase, stock, consume
	 * and price, and returns its id.
	 */
	private static function insertProduct(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$location,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	/**
	 * Inserts a manually-scheduled chore with the given name, overridden or extended by $columns,
	 * and returns its id.
	 */
	private static function insertChore(string $name, array $columns = []): int
	{
		return self::insertRow('chores', array_merge([
			'name' => $name,
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
		], $columns));
	}

	/** Purchases $amount of the product with a 2035-06-30 due date. */
	private static function stockUp(int $productId, float $amount): void
	{
		self::$stock->AddProduct($productId, $amount, '2035-06-30', StockService::TRANSACTION_TYPE_PURCHASE, '2026-04-01', 1.0);
	}

	/** The total amount in stock for the product. */
	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (float)$statement->fetchColumn();
	}

	/** The number of chores_log rows for the chore. */
	private static function choresLogCountForChore(int $choreId): int
	{
		$statement = self::$db->prepare('SELECT COUNT(*) FROM chores_log WHERE chore_id = ?');
		$statement->execute([$choreId]);

		return (int)$statement->fetchColumn();
	}

	/** The highest stock_log id, or 0 when the table is empty. */
	private static function highestStockLogId(): int
	{
		return (int)self::$db->query('SELECT COALESCE(MAX(id), 0) FROM stock_log')->fetchColumn();
	}

	/**
	 * The product_id, amount and transaction_type of the stock_log rows with an id above $afterId,
	 * in id order.
	 */
	private static function stockLogSince(int $afterId): array
	{
		$statement = self::$db->prepare('SELECT product_id, amount, transaction_type FROM stock_log WHERE id > ? ORDER BY id');
		$statement->execute([$afterId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * One request through request-subprocess-helper.php's whole middleware stack - copies
	 * ChoreExecutionStockUndoTest::requestThroughHttp()'s own shape.
	 *
	 * @return array{status: int, body: mixed, stderr: string}
	 */
	private static function send(string $method, string $path, string $apiKey, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $apiKey]];
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

		$finished = Subprocess::Completed(Subprocess::run([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], $env));
		$output = $finished['stdout'];
		$errors = $finished['stderr'];

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		if (isset($result['body']) && is_string($result['body']))
		{
			$decoded = json_decode($result['body'], true);
			if (is_array($decoded))
			{
				$result['body'] = $decoded;
			}
		}
		$result['stderr'] = $errors;

		return $result;
	}

	// ------------------------------------------------------------------------------
	// A chore that consumes a product: CHORE_TRACK_EXECUTION alone is not enough
	// ------------------------------------------------------------------------------

	/**
	 * A user with only CHORE_TRACK_EXECUTION is refused 403 when executing a chore that consumes a
	 * product, and no chores_log or stock_log row is written and the stock is untouched.
	 */
	public function testTrackOnlyCannotExecuteAConsumingChore(): void
	{
		$product = self::insertProduct('Chore Consume Permission Product A');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Consume Permission Chore A', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$logCountBefore = self::choresLogCountForChore($choreId);
		$watermark = self::highestStockLogId();

		$response = self::send('POST', '/api/chores/' . $choreId . '/execute', self::$keys['track-only'], []);

		self::assertSame(403, $response['status'], 'CHORE_TRACK_EXECUTION alone must not execute a chore that consumes stock: ' . json_encode($response['body']));
		self::assertSame($logCountBefore, self::choresLogCountForChore($choreId), 'No chores_log row was written on refusal');
		self::assertSame(5.0, self::stockAmount($product), 'The stock is untouched');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');
	}

	/**
	 * A user with only STOCK_CONSUME is refused 403 when executing a chore, and nothing is logged
	 * or consumed.
	 */
	public function testStockConsumeAloneCannotExecuteAChore(): void
	{
		$product = self::insertProduct('Chore Consume Permission Product B');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Consume Permission Chore B', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$logCountBefore = self::choresLogCountForChore($choreId);
		$watermark = self::highestStockLogId();

		$response = self::send('POST', '/api/chores/' . $choreId . '/execute', self::$keys['consume-only'], []);

		self::assertSame(403, $response['status'], 'STOCK_CONSUME alone must not track the chore at all: ' . json_encode($response['body']));
		self::assertSame($logCountBefore, self::choresLogCountForChore($choreId), 'No chores_log row was written on refusal');
		self::assertSame(5.0, self::stockAmount($product), 'The stock is untouched');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');
	}

	/**
	 * A user with CHORE_TRACK_EXECUTION and STOCK_CONSUME can execute a consuming chore: one
	 * chores_log row is written and the product is consumed with one stock_log booking.
	 */
	public function testTrackExecutionAndStockConsumeCanExecuteAConsumingChore(): void
	{
		$product = self::insertProduct('Chore Consume Permission Product C');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Consume Permission Chore C', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$watermark = self::highestStockLogId();
		$response = self::send('POST', '/api/chores/' . $choreId . '/execute', self::$keys['track-consume'], []);

		self::assertSame(200, $response['status'], 'CHORE_TRACK_EXECUTION + STOCK_CONSUME may execute a consuming chore: ' . json_encode($response['body']));
		self::assertSame(1, self::choresLogCountForChore($choreId), 'One chores_log row was written');
		self::assertSame(3.0, self::stockAmount($product), 'The product was consumed');

		$booked = self::stockLogSince($watermark);
		self::assertCount(1, $booked, 'Exactly one stock_log row was written');
		self::assertSame($product, (int)$booked[0]['product_id']);
		self::assertSame(-2.0, (float)$booked[0]['amount']);
		self::assertSame(StockService::TRANSACTION_TYPE_CONSUME, $booked[0]['transaction_type']);

		$statement = self::$db->prepare('SELECT stock_transaction_id FROM chores_log WHERE chore_id = ?');
		$statement->execute([$choreId]);
		self::assertNotEmpty($statement->fetchColumn(), 'TrackChore() still records the stock_transaction_id link (issue #506/D5)');
	}

	// ------------------------------------------------------------------------------
	// A chore that consumes nothing still needs only CHORE_TRACK_EXECUTION
	// ------------------------------------------------------------------------------

	/**
	 * A user with only CHORE_TRACK_EXECUTION can execute a chore that consumes nothing, and one
	 * chores_log row is written.
	 */
	public function testTrackOnlyCanExecuteAChoreThatConsumesNothing(): void
	{
		$choreId = self::insertChore('Chore Consume Permission Chore D');

		$response = self::send('POST', '/api/chores/' . $choreId . '/execute', self::$keys['track-only'], []);

		self::assertSame(200, $response['status'], 'CHORE_TRACK_EXECUTION alone is enough for a chore that consumes nothing: ' . json_encode($response['body']));
		self::assertSame(1, self::choresLogCountForChore($choreId), 'One chores_log row was written');
	}

	/**
	 * A chore with consume_product_on_execution set but no product consumes nothing, so
	 * CHORE_TRACK_EXECUTION alone is enough to execute it.
	 */
	public function testTrackOnlyCanExecuteAChoreConfiguredToConsumeButWithNoProduct(): void
	{
		// consume_product_on_execution = 1 with no product_id: TrackChore()'s own guard
		// (!empty($chore->product_id)) already treats this as "consumes nothing" for the
		// booking itself, and the new permission check follows the same condition.
		$choreId = self::insertChore('Chore Consume Permission Chore E', [
			'consume_product_on_execution' => 1,
		]);

		$response = self::send('POST', '/api/chores/' . $choreId . '/execute', self::$keys['track-only'], []);

		self::assertSame(200, $response['status'], 'consume_product_on_execution with no product_id needs only CHORE_TRACK_EXECUTION: ' . json_encode($response['body']));
		self::assertSame(1, self::choresLogCountForChore($choreId), 'One chores_log row was written');
	}

	// ------------------------------------------------------------------------------
	// Direct service call with no request: fails closed, exactly like RecipesService::
	// ConsumeRecipe()'s STOCK_PURCHASE check and ChoresService::UndoChoreExecution()'s
	// STOCK_EDIT check.
	// ------------------------------------------------------------------------------

	/**
	 * ChoresService::TrackChore() called directly by a caller without STOCK_CONSUME throws an
	 * exception naming STOCK_CONSUME for a consuming chore, and logs and consumes nothing.
	 */
	public function testDirectServiceCallWithNoRequestFailsClosedForAConsumingChore(): void
	{
		$product = self::insertProduct('Chore Consume Permission Direct Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Consume Permission Direct Chore', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$logCountBefore = self::choresLogCountForChore($choreId);
		$watermark = self::highestStockLogId();

		// self::fail() is deliberately not called inside this try block - see
		// RecipeRouteAuthzTest::testDirectServiceCallWithNoRequestFailsClosedForAProducingRecipe()'s
		// own comment for why: PHPUnit's own assertion failure is itself an \Exception, and
		// would be swallowed by the catch below.
		$exceptionThrown = null;
		try
		{
			self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		}
		catch (\Exception $exception)
		{
			$exceptionThrown = $exception;
		}

		self::assertNotNull($exceptionThrown, 'A direct caller with no request and no STOCK_CONSUME must not silently succeed');
		self::assertStringContainsString('STOCK_CONSUME', $exceptionThrown->getMessage());
		self::assertSame($logCountBefore, self::choresLogCountForChore($choreId), 'No chores_log row was written on refusal');
		self::assertSame(5.0, self::stockAmount($product), 'Nothing was consumed on refusal');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written on refusal');
	}

	// The positive control - the same shape, with the ambient user (already inserted, with no
	// permissions, in setUpBeforeClass) now granted STOCK_CONSUME - succeeds. CHORE_TRACK_
	// EXECUTION is never checked by the service layer itself, only by the controller, so it is
	// not granted here. Every other test in this class uses an HTTP-level user instead so as
	// not to disturb the no-permissions fixture the negative case above depends on; this grant
	// runs last (declaration order), after that case has already run.
	/**
	 * With STOCK_CONSUME granted to the ambient caller, ChoresService::TrackChore() called directly
	 * executes the consuming chore and consumes the product.
	 */
	public function testDirectServiceCallWithStockConsumeGrantedSucceeds(): void
	{
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'STOCK_CONSUME' ON CONFLICT DO NOTHING");

		$product = self::insertProduct('Chore Consume Permission Direct Product Control');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Consume Permission Direct Chore Control', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');

		self::assertGreaterThan(0, $executionId);
		self::assertSame(3.0, self::stockAmount($product), 'The product was consumed once STOCK_CONSUME is granted');
	}
}
