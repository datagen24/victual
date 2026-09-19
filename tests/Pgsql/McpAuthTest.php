<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #208: the Victual half of the MCP sidecar's auth seam, through the whole middleware
 * stack in production mode (docs/mcp-interface-spec.md §4.2, and §11.3's matrix).
 *
 *   - An MCP-type key is accepted in the API key header, exactly as a regular key is.
 *   - A read-only key may GET and may not write: anything else is 403, whatever the route.
 *   - VICTUAL-API-KEY-TYPE narrows the header lookup to one type and never widens it, so a
 *     regular key sent by the sidecar (which always says `mcp`) is not recognised.
 *   - GET /api/user/capabilities tells the acting credential what it is and what it holds,
 *     and two keys whose users differ in one permission get answers differing in exactly
 *     that permission.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php): the
 * authentication middleware define()s the acting user, and PHP cannot redefine a constant.
 */
class McpAuthTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	/** @var array<string,string> plaintext keys by name */
	private static array $keys = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Two users differing in exactly one permission: both can read stock and the
		// shopping list, only the first can read recipes. Both may edit master data (which
		// the generic POST /api/objects/tasks checks), so a refused write is refused for
		// the key's flag and not for a missing permission.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9400, 'mcp-cook', 'fixture'), (9401, 'mcp-shopper', 'fixture')");
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		foreach (['STOCK_VIEW', 'SHOPPINGLIST_VIEW', 'RECIPES_VIEW', 'TASKS', 'TASKS_VIEW', 'MASTER_DATA_EDIT'] as $permission)
		{
			$grant->execute([9400, $permission]);
		}
		foreach (['STOCK_VIEW', 'SHOPPINGLIST_VIEW', 'TASKS', 'TASKS_VIEW', 'MASTER_DATA_EDIT'] as $permission)
		{
			$grant->execute([9401, $permission]);
		}

		self::$keys['regular'] = self::issueKey(9400, ApiKeyService::API_KEY_TYPE_DEFAULT, false);
		self::$keys['mcp-read-only'] = self::issueKey(9400, ApiKeyService::API_KEY_TYPE_MCP, true);
		self::$keys['mcp-writable'] = self::issueKey(9400, ApiKeyService::API_KEY_TYPE_MCP, false);
		self::$keys['mcp-shopper'] = self::issueKey(9401, ApiKeyService::API_KEY_TYPE_MCP, true);

		// A calendar sharing key is stored as issued (ApiKeyService::StoredValueOf)
		self::$keys['calendar'] = 'mcp-auth-test-calendar-key';
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, user_id, expires, key_type) VALUES (?, 9400, '2999-12-31 23:59:59', ?)");
		$stmt->execute([self::$keys['calendar'], ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL]);

		self::$db->exec("INSERT INTO sessions(session_key, user_id, expires) VALUES ('mcp-auth-session', 9400, now() + interval '1 day')");
	}

	/** Inserts a key row the way ApiKeyService::CreateApiKey() stores one, and returns its plaintext. */
	private static function issueKey(int $userId, string $type, bool $readOnly): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, ?)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, $type, $readOnly ? 1 : 0]);

		return $plaintext;
	}

	/** @return array{status: int, body: string} */
	private static function send(string $method, string $path, array $headers = [], ?array $body = null, ?string $cookie = null): array
	{
		$spec = array_filter(['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body, 'cookie' => $cookie], fn ($v) => $v !== null);
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

	private static function withKey(string $name, array $extra = []): array
	{
		return ['VICTUAL-API-KEY' => self::$keys[$name]] + $extra;
	}

	private static function capabilities(string $name, array $extra = []): array
	{
		$response = self::send('GET', '/api/user/capabilities', self::withKey($name, $extra));
		self::assertSame(200, $response['status'], $response['body']);

		return json_decode($response['body'], true);
	}

	public function testNoCredentialIsUnauthorized(): void
	{
		self::assertSame(401, self::send('GET', '/api/user/capabilities')['status']);
	}

	public function testAnMcpKeyIsAcceptedInTheHeader(): void
	{
		self::assertSame(200, self::send('GET', '/api/stock', self::withKey('mcp-read-only'))['status']);
	}

	public function testAReadOnlyKeyMayReadAndMayNotWrite(): void
	{
		self::assertSame(200, self::send('GET', '/api/tasks', self::withKey('mcp-read-only'))['status']);

		$post = self::send('POST', '/api/objects/tasks', self::withKey('mcp-read-only'), ['name' => 'Refused']);
		self::assertSame(403, $post['status']);
		self::assertStringContainsString('read-only', $post['body']);

		// The other non-safe methods, on routes the user holds the permission for - the flag
		// decides, not the route or the permission. PUT and DELETE on user settings need no
		// permission at all, so a 403 there can only be the flag. (PATCH has no route in
		// this API: routing answers it 405 before authentication runs.)
		foreach (['PUT', 'DELETE'] as $method)
		{
			self::assertSame(403, self::send($method, '/api/objects/tasks/1', self::withKey('mcp-read-only'), ['name' => 'x'])['status'], "$method on a task");
			self::assertSame(403, self::send($method, '/api/user/settings/mcp_auth_test', self::withKey('mcp-read-only'), ['value' => 'x'])['status'], "$method on a user setting");
		}

		$written = (int)self::$db->query("SELECT count(*) FROM tasks WHERE name = 'Refused'")->fetchColumn();
		self::assertSame(0, $written, 'the refused write left nothing behind');
	}

	public function testAReadOnlyKeyMayNotCallTheGetRoutesThatWrite(): void
	{
		$sharingLink = self::send('GET', '/api/calendar/ical/sharing-link', self::withKey('mcp-read-only'));
		self::assertSame(403, $sharingLink['status'], 'the sharing link creates a calendar key on first use');
		self::assertSame(403, self::send('GET', '/api/stock/barcodes/external-lookup/4006381333931?add=true', self::withKey('mcp-read-only'))['status'], 'the external lookup can create a product');
		self::assertSame(403, self::send('GET', '/api/print/shoppinglist/thermal', self::withKey('mcp-read-only'))['status'], 'the thermal print drives a printer');

		$created = (int)self::$db->query("SELECT count(*) FROM api_keys WHERE user_id = 9400 AND key_type = 'special-purpose-calendar-ical' AND api_key <> 'mcp-auth-test-calendar-key'")->fetchColumn();
		self::assertSame(0, $created, 'no calendar key was created behind the refusal');

		self::assertSame(200, self::send('GET', '/api/calendar/ical/sharing-link', self::withKey('mcp-writable'))['status'], 'a key that is not read-only still may');
	}

	public function testAWritableMcpKeyMayWrite(): void
	{
		$post = self::send('POST', '/api/objects/tasks', self::withKey('mcp-writable'), ['name' => 'Written by a writable MCP key']);
		self::assertSame(200, $post['status'], $post['body']);
	}

	public function testTheExpectedTypeHeaderNarrowsAndNeverWidens(): void
	{
		$mcp = ['VICTUAL-API-KEY-TYPE' => ApiKeyService::API_KEY_TYPE_MCP];

		self::assertSame(200, self::send('GET', '/api/stock', self::withKey('mcp-read-only', $mcp))['status'], 'an MCP key passes when MCP is expected');
		self::assertSame(401, self::send('GET', '/api/stock', self::withKey('regular', $mcp))['status'], 'a regular key does not pass when MCP is expected - the sidecar path is MCP-only');
		self::assertSame(200, self::send('GET', '/api/stock', self::withKey('regular', ['VICTUAL-API-KEY-TYPE' => 'default']))['status'], 'a regular key passes when a regular key is expected');
		self::assertSame(401, self::send('GET', '/api/stock', self::withKey('regular', ['VICTUAL-API-KEY-TYPE' => 'no-such-type']))['status'], 'an unknown type matches nothing');
		self::assertSame(401, self::send('GET', '/api/stock', self::withKey('calendar', ['VICTUAL-API-KEY-TYPE' => ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL]))['status'],
			'the header cannot be used to reach a special-purpose key type');
		self::assertSame(401, self::send('GET', '/api/stock', self::withKey('calendar'))['status'], 'nor is a calendar key accepted in the header without it');
	}

	public function testCapabilitiesDescribeTheActingKey(): void
	{
		$readOnly = self::capabilities('mcp-read-only');
		self::assertSame('mcp', $readOnly['key_type']);
		self::assertTrue($readOnly['read_only']);
		self::assertContains('RECIPES_VIEW', $readOnly['permissions']);

		$regular = self::capabilities('regular');
		self::assertSame('default', $regular['key_type']);
		self::assertFalse($regular['read_only']);

		$sorted = $readOnly['permissions'];
		sort($sorted);
		self::assertSame($sorted, $readOnly['permissions'], 'permissions are sorted');
	}

	public function testCapabilitiesForASessionHaveNoKeyType(): void
	{
		$response = self::send('GET', '/api/user/capabilities', [], null, 'mcp-auth-session');
		self::assertSame(200, $response['status'], $response['body']);
		$capabilities = json_decode($response['body'], true);

		self::assertNull($capabilities['key_type']);
		self::assertFalse($capabilities['read_only']);
	}

	public function testTwoUsersDifferingInOnePermissionDifferInExactlyThatPermission(): void
	{
		$cook = self::capabilities('mcp-read-only')['permissions'];
		$shopper = self::capabilities('mcp-shopper')['permissions'];

		self::assertSame(['RECIPES_VIEW'], array_values(array_diff($cook, $shopper)));
		self::assertSame([], array_values(array_diff($shopper, $cook)));
	}
}
