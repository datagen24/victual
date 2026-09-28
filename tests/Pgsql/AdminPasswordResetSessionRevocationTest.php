<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #571: UsersApiController::EditUser() reads the request's session cookie as "the
 * acting session" - the one session a password change spares from
 * SessionService::RemoveOtherSessions() - whenever that cookie's session belongs to the
 * *target* user, regardless of who is actually making the request. Under DISABLE_AUTH or
 * reverse-proxy authentication, every request acts as the same configured identity no
 * matter which cookie the browser happens to carry, so an administrator resetting user
 * X's password from a browser that still holds X's own live session cookie had that
 * session spared - even though the request is the administrator's edit of somebody else,
 * not X editing themself. Policy (UsersService::EditUser()'s own docblock) is that a
 * password change "revokes every other session of this account", and an administrator's
 * reset is not "this account" acting at all.
 *
 * Expected fix: accept the cookie as the acting session only when the actor is editing
 * their own account ($isSelf), so this scenario revokes every one of the target's
 * sessions like any other administrator-initiated reset does.
 *
 * Reuses AuthStackTest's request harness (authstack-subprocess-helper.php) because this
 * needs the same thing that harness exists for: VICTUAL_DISABLE_AUTH read out of the
 * environment before config-dist.php's Setting() calls freeze the constants, and the
 * default-user fixup app.php performs ahead of any authentication middleware.
 */
class AdminPasswordResetSessionRevocationTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	private const TARGET_USER_ID = 9710;
	private const TARGET_USERNAME = 'a571-target';
	private const TARGET_PASSWORD = 'a571 original password';
	private const NEW_PASSWORD = 'a571 administrator-reset password';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$statement = self::$db->prepare('INSERT INTO users (id, username, password) VALUES (?, ?, ?)');
		$statement->execute([self::TARGET_USER_ID, self::TARGET_USERNAME, password_hash(self::TARGET_PASSWORD, PASSWORD_ARGON2ID)]);
	}

	/** One request through the whole middleware stack, in a process of its own. */
	private static function send(string $method, string $path, array $options = []): array
	{
		$spec = ['method' => $method, 'path' => $path];

		foreach (['headers', 'cookie', 'body', 'server', 'authority'] as $key)
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

		foreach ($options['settings'] ?? [] as $name => $value)
		{
			$env['VICTUAL_' . $name] = $value;
		}

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/authstack-subprocess-helper.php', base64_encode(json_encode($spec))],
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

	/** Creates a sessions row directly and returns its key. */
	private static function issueSession(int $userId, string $expiryOffset = '+30 days'): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO sessions (session_key, user_id, expires) VALUES (?, ?, ?)');
		$statement->execute([$key, $userId, date('Y-m-d H:i:s', strtotime($expiryOffset))]);

		return $key;
	}

	private static function sessionCountFor(int $userId): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM sessions WHERE user_id = ?');
		$statement->execute([$userId]);

		return (int)$statement->fetchColumn();
	}

	/**
	 * The scenario issue #571 describes verbatim: DISABLE_AUTH is on (every request acts
	 * as the seeded administrator, id 1 - see the class docblock), and the request that
	 * resets a different user's password happens to carry that *other* user's own,
	 * still-live session cookie. Every session belonging to the target has to be gone
	 * afterwards, the spared one included - an administrator's reset of somebody else's
	 * account is not that account editing itself, whatever cookie rode along.
	 */
	public function testAdminPasswordResetUnderDisableAuthRevokesEveryTargetSessionEvenWhenItsCookieRideAlong(): void
	{
		$carriedCookie = self::issueSession(self::TARGET_USER_ID);
		self::assertSame(1, self::sessionCountFor(self::TARGET_USER_ID), 'precondition: the target has exactly the one live session under test');

		$response = self::send('PUT', '/api/users/' . self::TARGET_USER_ID, [
			'cookie' => $carriedCookie,
			'body' => ['username' => self::TARGET_USERNAME, 'password' => self::NEW_PASSWORD],
			'settings' => ['DISABLE_AUTH' => 'true']
		]);

		self::assertSame(204, $response['status'], $response['body']);

		self::assertSame(
			0,
			self::sessionCountFor(self::TARGET_USER_ID),
			'every session of the target account must be revoked by an administrator password reset, including one whose cookie rode along on the resetting request'
		);

		$statement = self::$db->prepare('SELECT password FROM users WHERE id = ?');
		$statement->execute([self::TARGET_USER_ID]);
		$hash = $statement->fetchColumn();

		self::assertTrue(password_verify(self::NEW_PASSWORD, $hash), 'the password itself must actually have been reset');
		self::assertFalse(password_verify(self::TARGET_PASSWORD, $hash), 'and the original password must no longer work');
	}
}
