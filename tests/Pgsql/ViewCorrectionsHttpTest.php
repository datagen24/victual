<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * HTTP-level regressions for issue #487 remediation (PR #542): migrations/0289.pgsql.sql's
 * three view corrections, exercised the way a real request reaches them rather than only
 * through direct SQL. .devtools/pgtap/018-audit-view-corrections.sql covers the views'
 * own arithmetic in detail; this class covers the two symptoms issue #487 reports at the
 * request boundary - a thrown request and a rendered page - which need the whole
 * middleware/controller/Blade stack to reproduce, not only the view itself.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php), the way
 * tests/Pgsql/WireContractTest.php's does: the authentication middleware define()s the
 * acting user's constants, and PHP cannot redefine a constant a second time in the same
 * process.
 */
class ViewCorrectionsHttpTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static string $apiKey;
	private const SESSION_KEY = 'view-corrections-session';
	private const USER_ID = 9700;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec('INSERT INTO users(id, username, password) VALUES '
			. '(' . self::USER_ID . ", 'view-corrections-user', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. 'SELECT ' . self::USER_ID . " , id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			self::USER_ID,
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);

		self::$db->exec('INSERT INTO sessions(session_key, user_id, expires) VALUES '
			. "('" . self::SESSION_KEY . "', " . self::USER_ID . ", now() + interval '1 day')");
	}

	/**
	 * One request through the whole middleware stack, in its own process. $options takes
	 * 'headers' and/or 'cookie' (a bare session key - request-subprocess-helper.php builds
	 * the cookie params from it directly, it is not an HTTP `Cookie:` header string).
	 *
	 * @return array{status: int, body: string}
	 */
	private static function send(string $method, string $path, array $options = []): array
	{
		$spec = ['method' => $method, 'path' => $path];

		foreach (['headers', 'cookie', 'body'] as $key)
		{
			if (isset($options[$key]))
			{
				$spec[$key] = $options[$key];
			}
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
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
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

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/**
	 * #497 (audit H8): GET /api/chores/{id} for a chore anchored 29 February used to throw
	 * SQLSTATE 22008 out of chores_current for every non-leap target year - a 400 ("The
	 * database rejected this request...") with no workaround for the caller, per this PR's
	 * own before-fix run. Fixed by migrations/0289.pgsql.sql; this is the response a caller
	 * actually receives, end to end through ChoresApiController::ChoreDetails() and
	 * ChoresService::GetChoreDetails().
	 */
	public function testLeapDayYearlyChoreDetailsReturnNextEstimatedExecutionOn28February(): void
	{
		self::$db->exec("INSERT INTO chores (name, period_type, period_interval, period_days, start_date, active) "
			. "VALUES ('WS15 leap chore', 'yearly', 1, 1, '2024-02-29 12:00:00', 1)");
		$choreId = (int)self::$db->query("SELECT id FROM chores WHERE name = 'WS15 leap chore'")->fetchColumn();
		self::$db->exec('INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) '
			. "VALUES ($choreId, '2024-02-29 12:00:00', " . self::USER_ID . ', 0)');

		$response = self::send('GET', "/api/chores/$choreId", ['headers' => ['VICTUAL-API-KEY' => self::$apiKey]]);

		self::assertSame(200, $response['status'],
			"GET /api/chores/$choreId answered {$response['status']} instead of 200: {$response['body']}");

		$decoded = json_decode($response['body'], true);
		self::assertIsArray($decoded, "GET /api/chores/$choreId did not answer JSON: {$response['body']}");
		self::assertArrayHasKey('next_estimated_execution_time', $decoded);
		self::assertStringStartsWith('2025-02-28', (string)$decoded['next_estimated_execution_time'],
			'a 29 February anchor is due 28 February the following (non-leap) year, not a 500');
	}

	/**
	 * #505 (audit M5): the stock journal page (StockController::Journal(), the only reader
	 * of uihelper_stock_journal - it is not an ExposedEntity, and
	 * WireContractTest::testTheDeadJournalSchemasStayDeleted pins
	 * GET /api/objects/uihelper_stock_journal at 400, unaffected by this change) must
	 * render a row whose location was since deleted, rather than silently dropping it.
	 * views/stockjournal.blade.php already falls back to the row's own (now NULL)
	 * location_name whenever the location is not among the household's current ones, so no
	 * Blade or PHP change was needed for this to render without error.
	 */
	public function testStockJournalPageRendersARowWhoseLocationWasDeleted(): void
	{
		self::$db->exec("INSERT INTO quantity_units (name) VALUES ('WS15 qu')");
		self::$db->exec("INSERT INTO locations (name) VALUES ('WS15 deleted location')");
		self::$db->exec('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES '
			. "('WS15 journal product', (SELECT id FROM locations WHERE name = 'WS15 deleted location'), "
			. "(SELECT id FROM quantity_units WHERE name = 'WS15 qu'), (SELECT id FROM quantity_units WHERE name = 'WS15 qu'))");
		// note is asserted on below rather than the product name: StockController::Journal()
		// also passes every active product into the page for the unrelated product-*filter*
		// dropdown, so the product's name reaches the response regardless of whether the
		// journal table itself includes the row - a distinctive note text does not, and
		// views/stockjournal.blade.php renders it only inside the journal row loop.
		self::$db->exec('INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, location_id, user_id, note) VALUES '
			. "((SELECT id FROM products WHERE name = 'WS15 journal product'), 1, 'ws15-journal', 'purchase', "
			. '(SELECT id FROM locations WHERE name = \'WS15 deleted location\'), ' . self::USER_ID . ", 'ws15-audit505-marker')");
		self::$db->exec("DELETE FROM locations WHERE name = 'WS15 deleted location'");

		$response = self::send('GET', '/stockjournal', ['cookie' => self::SESSION_KEY]);

		self::assertSame(200, $response['status'],
			"GET /stockjournal answered {$response['status']} instead of 200: {$response['body']}");
		self::assertStringContainsString('ws15-audit505-marker', $response['body'],
			'the journal row for the deleted location is actually rendered, not just the product name elsewhere on the page');
		self::assertStringNotContainsStringIgnoringCase('fatal error', $response['body'],
			'a NULL location_name does not crash the page');
		self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $response['body'],
			'no driver error reached the page');
	}
}
