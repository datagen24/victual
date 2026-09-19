<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The label endpoints write through the application's transaction and change tracking, not
 * around them.
 *
 * They used to take the raw PDO connection and call beginTransaction() themselves, so a
 * label write - a template saved, a print job issued - never reached the query callback
 * that advances GET /api/system/db-changed-time, and a client polling that value kept
 * showing stale data. They also threw when a request was already inside a transaction,
 * because InTransaction()'s "join the open one" rule never applied to them.
 *
 * What should be true now, through the whole middleware stack in production mode:
 *
 *   - a label write that commits advances the changed time;
 *   - a read of the same endpoint does not;
 *   - a write that is refused (a validation error, rolled back) does not.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php), because
 * the authentication middleware define()s the acting user and PHP cannot redefine it.
 */
class LabelWriteTrackingTest extends PgsqlSchemaTestCase
{
	private const LONG_AGO = '2000-01-01 00:00:00';

	private static PDO $db;

	private static string $key;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9500, 'label-admin', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9500, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$key = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9500, now() + interval '30 days', ?)");
		$stmt->execute([ApiKeyService::HashKey(self::$key), substr(self::$key, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);
	}

	/** @return array{status: int, body: string} */
	private static function send(string $method, string $path, ?array $body = null): array
	{
		$spec = array_filter(['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$key], 'body' => $body], fn ($v) => $v !== null);
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

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/** Sets the changed time far in the past, so any later advance is unambiguous. */
	private static function resetChangedTime(): void
	{
		self::$db->exec("UPDATE system_db_changed_time SET changed_time = '" . self::LONG_AGO . "' WHERE id = 1");
	}

	private static function changedTime(): string
	{
		return (string)self::$db->query('SELECT changed_time FROM system_db_changed_time WHERE id = 1')->fetchColumn();
	}

	public function testACommittedLabelWriteAdvancesTheChangedTime(): void
	{
		self::resetChangedTime();

		$response = self::send('POST', '/api/labels/templates', ['name' => 'Tracked template', 'entity_kind' => 'location']);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertGreaterThan(self::LONG_AGO, self::changedTime(), 'a template was created and the changed time did not move');
	}

	public function testReadingLabelsDoesNotAdvanceTheChangedTime(): void
	{
		self::resetChangedTime();

		$response = self::send('GET', '/api/labels/templates');

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(self::LONG_AGO, self::changedTime(), 'a read moved the changed time');
	}

	public function testARefusedLabelWriteDoesNotAdvanceTheChangedTime(): void
	{
		self::resetChangedTime();

		$before = (int)self::$db->query('SELECT count(*) FROM label_templates')->fetchColumn();
		$response = self::send('POST', '/api/labels/templates', ['name' => '   ', 'entity_kind' => 'location']);

		self::assertSame(422, $response['status'], $response['body']);
		self::assertSame($before, (int)self::$db->query('SELECT count(*) FROM label_templates')->fetchColumn(), 'a refused write left a row behind');
		self::assertSame(self::LONG_AGO, self::changedTime(), 'a rolled-back write moved the changed time');
	}
}
