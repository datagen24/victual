<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Password rotation, end to end: what happens to other sessions and API keys when a
 * password changes (issue #513), and the narrow path a flagged
 * (`must_change_password`) account without USERS_EDIT_SELF uses to resolve that flag
 * (issue #514).
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php) because
 * the authentication middleware define()s the acting user's constants and PHP cannot
 * redefine them - the same reason BootstrapAdminTest, which this class sits beside in
 * the `bootstrapadmin` testsuite, uses a process per request.
 *
 * What should be true now:
 *
 *   - changing a password revokes every other session of that account, whether the
 *     change was made by the account itself or by an administrator, but never the
 *     session that made a self-service change (M13);
 *   - API keys are a separate credential and are never revoked by a password change;
 *   - a flagged account without USERS_EDIT_SELF may still change only its own password,
 *     with the correct current password, and nothing else (M14);
 *   - a wrong current password on that path is throttled the same way a wrong login
 *     password is, and every refusal leaves the stored password, the flag and existing
 *     sessions untouched;
 *   - every other write a flagged account was already refused stays refused.
 */
class PasswordRotationTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	private const ADMIN_ACTOR = 9700;
	private const RESET_TARGET = 9701;
	private const SELF_ROTATE_KEPT = 9702;
	private const SELF_ROTATE_NO_SESSION = 9703;
	private const FORCED_ZERO_GRANT = 9704;
	private const THROTTLE_ZERO_GRANT = 9705;
	private const FIELD_SCOPE_ZERO_GRANT = 9706;
	private const WALL_ZERO_GRANT = 9707;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::createUser(self::ADMIN_ACTOR, 'rotation-admin-actor', 'admin-actor-pw-1');
		self::grantAdmin(self::ADMIN_ACTOR);
		self::createSession('rotation-admin-session', self::ADMIN_ACTOR);

		self::createUser(self::RESET_TARGET, 'rotation-reset-target', 'reset-target-pw-1');
		self::createSession('rotation-target-session-a', self::RESET_TARGET);
		self::createSession('rotation-target-session-b', self::RESET_TARGET);

		// Flagged, but holds USERS_EDIT_SELF (a Child-style role would do; ADMIN is simplest
		// and does not matter for what this fixture tests) - the ordinary path, not the
		// bypass, so what is under test here is session revocation alone.
		self::createUser(self::SELF_ROTATE_KEPT, 'rotation-self-kept', 'self-kept-pw-1', mustChangePassword: true);
		self::grantAdmin(self::SELF_ROTATE_KEPT);
		self::createSession('rotation-kept-acting-session', self::SELF_ROTATE_KEPT);
		self::createSession('rotation-kept-other-session', self::SELF_ROTATE_KEPT);

		self::createUser(self::SELF_ROTATE_NO_SESSION, 'rotation-self-no-session', 'no-session-pw-1', mustChangePassword: true);
		self::grantAdmin(self::SELF_ROTATE_NO_SESSION);
		self::createSession('rotation-no-session-a', self::SELF_ROTATE_NO_SESSION);
		self::createSession('rotation-no-session-b', self::SELF_ROTATE_NO_SESSION);

		// Zero grants - no role, no direct permission - which is the exact shape M14
		// describes: flagged, and unable to reach USERS_EDIT_SELF at all.
		self::createUser(self::FORCED_ZERO_GRANT, 'rotation-forced-zero-grant', 'forced-zero-grant-pw-1', mustChangePassword: true);
		self::createSession('rotation-forced-zero-grant-session', self::FORCED_ZERO_GRANT);

		self::createUser(self::THROTTLE_ZERO_GRANT, 'rotation-throttle-zero-grant', 'throttle-zero-grant-pw-1', mustChangePassword: true);
		self::createSession('rotation-throttle-session', self::THROTTLE_ZERO_GRANT);

		self::createUser(self::FIELD_SCOPE_ZERO_GRANT, 'rotation-field-scope-zero-grant', 'field-scope-pw-1', mustChangePassword: true);
		self::createSession('rotation-field-scope-session', self::FIELD_SCOPE_ZERO_GRANT);

		self::createUser(self::WALL_ZERO_GRANT, 'rotation-wall-zero-grant', 'wall-zero-grant-pw-1', mustChangePassword: true);
		self::createSession('rotation-wall-session', self::WALL_ZERO_GRANT);
	}

	private static function createUser(int $id, string $username, string $password, bool $mustChangePassword = false): void
	{
		$statement = self::$db->prepare('INSERT INTO users(id, username, password, must_change_password) VALUES (?, ?, ?, ?)');
		$statement->execute([$id, $username, password_hash($password, PASSWORD_ARGON2ID), $mustChangePassword ? 1 : 0]);
	}

	private static function grantAdmin(int $userId): void
	{
		$statement = self::$db->prepare('INSERT INTO user_roles(user_id, role_id) SELECT ?, id FROM roles WHERE code = ?');
		$statement->execute([$userId, 'ADMIN']);
	}

	private static function createSession(string $sessionKey, int $userId): void
	{
		$statement = self::$db->prepare("INSERT INTO sessions(session_key, user_id, expires) VALUES (?, ?, now() + interval '1 day')");
		$statement->execute([$sessionKey, $userId]);
	}

	/**
	 * Issues a regular API key by direct insert, mirroring AuthStackTest::issueKey()
	 * rather than going through ApiKeyService::GetInstance()->CreateApiKey(): that
	 * singleton is process-wide (BaseService::GetInstance()), and BootstrapAdminTest,
	 * which shares this PHPUnit process as the first file in the same testsuite, already
	 * constructed it bound to its own now-dropped schema by the time this class's tests
	 * run. StoredValueOf() alone is used from ApiKeyService - a pure hash, not an
	 * instance method - so it carries no such staleness.
	 */
	private static function issueApiKey(int $userId): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([
			ApiKeyService::StoredValueOf($key, ApiKeyService::API_KEY_TYPE_DEFAULT),
			substr($key, -4),
			$userId,
			ApiKeyService::API_KEY_TYPE_DEFAULT,
		]);

		return $key;
	}

	/**
	 * One request through the helper, against this class's schema.
	 *
	 * @param array<string, string> $throttleSettings VICTUAL_* overrides for the throttle
	 *                                                 test, passed as environment variables
	 *                                                 because that is what Setting() consults
	 * @return array{status: int, body: string}
	 */
	private static function request(array $spec, array $throttleSettings = []): array
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

		foreach ($throttleSettings as $name => $value)
		{
			$env['VICTUAL_' . $name] = $value;
		}

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
		$exit = proc_close($process);

		$decoded = json_decode($output, true);
		self::assertIsArray($decoded, "The helper printed no JSON (exit $exit). stdout: $output stderr: $errors");

		return $decoded;
	}

	private static function sessionExists(string $sessionKey): bool
	{
		$statement = self::$db->prepare('SELECT count(*) FROM sessions WHERE session_key = ?');
		$statement->execute([$sessionKey]);

		return (int)$statement->fetchColumn() > 0;
	}

	private static function storedPasswordHash(int $userId): string
	{
		$statement = self::$db->prepare('SELECT password FROM users WHERE id = ?');
		$statement->execute([$userId]);

		return (string)$statement->fetchColumn();
	}

	private static function flag(int $userId): int
	{
		$statement = self::$db->prepare('SELECT must_change_password FROM users WHERE id = ?');
		$statement->execute([$userId]);

		return (int)$statement->fetchColumn();
	}

	private static function storedUsername(int $userId): string
	{
		$statement = self::$db->prepare('SELECT username FROM users WHERE id = ?');
		$statement->execute([$userId]);

		return (string)$statement->fetchColumn();
	}

	private static function apiKeyCount(int $userId): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM api_keys WHERE user_id = ?');
		$statement->execute([$userId]);

		return (int)$statement->fetchColumn();
	}

	private static function loginAttemptCount(string $username): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM login_attempts WHERE username = ?');
		$statement->execute([$username]);

		return (int)$statement->fetchColumn();
	}

	/**
	 * Given a session-authenticated self password change, when it succeeds, then every
	 * other session of the account is gone, the session that made the change is still
	 * valid, and an API key of that account - a separate credential - is untouched
	 * (issue #513).
	 */
	public function testSelfPasswordChangeRevokesOtherSessionsButKeepsTheActingOneAndLeavesApiKeysAlone(): void
	{
		$apiKey = self::issueApiKey(self::SELF_ROTATE_KEPT);
		$keysBefore = self::apiKeyCount(self::SELF_ROTATE_KEPT);

		$change = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::SELF_ROTATE_KEPT,
			'cookie' => 'rotation-kept-acting-session',
			'body' => [
				'username' => 'rotation-self-kept',
				'password' => 'self-kept-pw-2',
				'current_password' => 'self-kept-pw-1',
			],
		]);

		self::assertSame(204, $change['status'], $change['body']);
		self::assertSame(0, self::flag(self::SELF_ROTATE_KEPT));
		self::assertTrue(password_verify('self-kept-pw-2', self::storedPasswordHash(self::SELF_ROTATE_KEPT)), 'the new password was stored');

		self::assertTrue(self::sessionExists('rotation-kept-acting-session'), 'the session that made the change is not logged out by its own request');
		self::assertFalse(self::sessionExists('rotation-kept-other-session'), 'every other session of the account is revoked');

		self::assertSame($keysBefore, self::apiKeyCount(self::SELF_ROTATE_KEPT), 'API keys are a separate credential and are not touched');
		$keyStillWorks = self::request(['method' => 'GET', 'path' => '/api/user', 'headers' => ['VICTUAL-API-KEY' => $apiKey]]);
		self::assertSame(200, $keyStillWorks['status'], 'the key itself still authenticates: ' . $keyStillWorks['body']);

		$stillLoggedIn = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'rotation-kept-acting-session']);
		self::assertSame(200, $stillLoggedIn['status'], 'the acting session is still valid after the change: ' . $stillLoggedIn['body']);

		$loggedOut = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'rotation-kept-other-session']);
		self::assertSame(401, $loggedOut['status'], 'the other session no longer authenticates');
	}

	/**
	 * Given a self password change authenticated by something other than a session
	 * cookie (an API key, here), when it succeeds, then there is no session to except
	 * and every existing session of the account is revoked - the direct M13
	 * reproduction, where two sessions were created independently of the request that
	 * changed the password and both remained valid.
	 */
	public function testSelfPasswordChangeWithNoActingSessionRevokesEveryExistingSession(): void
	{
		$apiKey = self::issueApiKey(self::SELF_ROTATE_NO_SESSION);

		self::assertTrue(self::sessionExists('rotation-no-session-a'));
		self::assertTrue(self::sessionExists('rotation-no-session-b'));

		$change = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::SELF_ROTATE_NO_SESSION,
			'headers' => ['VICTUAL-API-KEY' => $apiKey],
			'body' => [
				'username' => 'rotation-self-no-session',
				'password' => 'no-session-pw-2',
				'current_password' => 'no-session-pw-1',
			],
		]);

		self::assertSame(204, $change['status'], $change['body']);
		self::assertSame(0, self::flag(self::SELF_ROTATE_NO_SESSION));
		self::assertFalse(self::sessionExists('rotation-no-session-a'), 'neither pre-existing session performed the change, so neither is excepted');
		self::assertFalse(self::sessionExists('rotation-no-session-b'));
	}

	/**
	 * Given an administrator with USERS_EDIT resetting another user's password, when it
	 * succeeds, then every session of the target is revoked - there is no session of
	 * the target's own to except - and the administrator's own session is untouched.
	 */
	public function testAdministratorResettingAnotherUsersPasswordRevokesAllOfThatUsersSessions(): void
	{
		$change = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::RESET_TARGET,
			'cookie' => 'rotation-admin-session',
			'body' => [
				'username' => 'rotation-reset-target',
				'password' => 'reset-target-pw-2',
			],
		]);

		self::assertSame(204, $change['status'], $change['body']);
		self::assertTrue(password_verify('reset-target-pw-2', self::storedPasswordHash(self::RESET_TARGET)));

		self::assertFalse(self::sessionExists('rotation-target-session-a'));
		self::assertFalse(self::sessionExists('rotation-target-session-b'));
		self::assertTrue(self::sessionExists('rotation-admin-session'), 'the administrator\'s own session belongs to a different account and is unaffected');
	}

	/**
	 * Given a flagged account with no permissions at all - the M14 shape - when it PUTs
	 * its own id with the correct current password and a new one, then the write
	 * succeeds and the flag clears, although USERS_EDIT_SELF was never granted.
	 */
	public function testForcedRotationBypassLetsAZeroGrantFlaggedAccountChangeOnlyItsOwnPassword(): void
	{
		self::assertSame(1, self::flag(self::FORCED_ZERO_GRANT), 'the fixture starts flagged');

		$change = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::FORCED_ZERO_GRANT,
			'cookie' => 'rotation-forced-zero-grant-session',
			'body' => [
				'username' => 'rotation-forced-zero-grant',
				'password' => 'forced-zero-grant-pw-2',
				'current_password' => 'forced-zero-grant-pw-1',
			],
		]);

		self::assertSame(204, $change['status'], $change['body']);
		self::assertSame(0, self::flag(self::FORCED_ZERO_GRANT));
		self::assertTrue(password_verify('forced-zero-grant-pw-2', self::storedPasswordHash(self::FORCED_ZERO_GRANT)));

		// And the account is no longer held to the allowlist, exactly as an ordinarily
		// permitted account is once it resolves the flag
		$afterChange = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'rotation-forced-zero-grant-session']);
		self::assertSame(200, $afterChange['status']);
	}

	/**
	 * Given the same zero-grant flagged account, when it submits a wrong current
	 * password, then the write is refused with the stored password, the flag and its
	 * session unchanged, and the attempt counts toward the same per-username throttle a
	 * wrong login password would - so a fixed number of wrong guesses locks the account
	 * out of this path the same way it would lock it out of /login, and does not merely
	 * fail forever without cost.
	 */
	public function testForcedRotationBypassRefusesAWrongCurrentPasswordAndThrottlesLikeLogin(): void
	{
		$username = 'rotation-throttle-zero-grant';
		$settings = ['LOGIN_THROTTLE_MAX_ATTEMPTS' => '2'];
		$originalHash = self::storedPasswordHash(self::THROTTLE_ZERO_GRANT);

		try
		{
			foreach (['wrong-guess-1', 'wrong-guess-2'] as $i => $wrongPassword)
			{
				$attempt = self::request([
					'method' => 'PUT',
					'path' => '/api/users/' . self::THROTTLE_ZERO_GRANT,
					'cookie' => 'rotation-throttle-session',
					'body' => [
						'username' => $username,
						'password' => 'throttle-zero-grant-pw-2',
						'current_password' => $wrongPassword,
					],
				], $settings);

				self::assertSame(400, $attempt['status'], "wrong attempt " . ($i + 1));
				self::assertSame($i + 1, self::loginAttemptCount($username), 'each wrong guess is counted, the same table a wrong login password writes to');
			}

			// The limit is now reached; even the correct password is refused at the gate,
			// exactly as PasswordLogin answers a locked-out username
			$lockedOut = self::request([
				'method' => 'PUT',
				'path' => '/api/users/' . self::THROTTLE_ZERO_GRANT,
				'cookie' => 'rotation-throttle-session',
				'body' => [
					'username' => $username,
					'password' => 'throttle-zero-grant-pw-2',
					'current_password' => 'throttle-zero-grant-pw-1',
				],
			], $settings);

			self::assertSame(400, $lockedOut['status'], 'the correct current password is refused while the username is locked out: ' . $lockedOut['body']);
			self::assertSame(2, self::loginAttemptCount($username), 'a refusal at the throttle gate is not itself counted');

			self::assertSame($originalHash, self::storedPasswordHash(self::THROTTLE_ZERO_GRANT), 'no refusal changed the stored password');
			self::assertSame(1, self::flag(self::THROTTLE_ZERO_GRANT), 'no refusal lifted the flag');
			self::assertTrue(self::sessionExists('rotation-throttle-session'), 'no refusal touched the session');
		}
		finally
		{
			self::$db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
		}
	}

	/**
	 * Given the zero-grant bypass, when the request also asks to rename the account or
	 * change any other field alongside the mandated password change, then the whole
	 * write is refused - the bypass authorizes the password and nothing else - and every
	 * stored field, including the password, is unchanged. A resubmission of exactly the
	 * current values alongside the password then succeeds, showing the refusal was about
	 * the attempted extra change and not about the account being unable to use the
	 * bypass at all.
	 */
	public function testForcedRotationBypassRefusesChangesBeyondThePassword(): void
	{
		$originalHash = self::storedPasswordHash(self::FIELD_SCOPE_ZERO_GRANT);

		$renameAttempt = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::FIELD_SCOPE_ZERO_GRANT,
			'cookie' => 'rotation-field-scope-session',
			'body' => [
				'username' => 'renamed-during-forced-rotation',
				'password' => 'field-scope-pw-2',
				'current_password' => 'field-scope-pw-1',
			],
		]);

		self::assertSame(400, $renameAttempt['status']);
		self::assertSame('rotation-field-scope-zero-grant', self::storedUsername(self::FIELD_SCOPE_ZERO_GRANT), 'the rename did not take effect');
		self::assertSame($originalHash, self::storedPasswordHash(self::FIELD_SCOPE_ZERO_GRANT), 'refusing the rename also refused the password change bundled with it');
		self::assertSame(1, self::flag(self::FIELD_SCOPE_ZERO_GRANT));

		$firstNameAttempt = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::FIELD_SCOPE_ZERO_GRANT,
			'cookie' => 'rotation-field-scope-session',
			'body' => [
				'username' => 'rotation-field-scope-zero-grant',
				'first_name' => 'Sneaked In',
				'password' => 'field-scope-pw-2',
				'current_password' => 'field-scope-pw-1',
			],
		]);

		self::assertSame(400, $firstNameAttempt['status']);
		self::assertSame($originalHash, self::storedPasswordHash(self::FIELD_SCOPE_ZERO_GRANT));

		$passwordOnly = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::FIELD_SCOPE_ZERO_GRANT,
			'cookie' => 'rotation-field-scope-session',
			'body' => [
				'username' => 'rotation-field-scope-zero-grant',
				'password' => 'field-scope-pw-2',
				'current_password' => 'field-scope-pw-1',
			],
		]);

		self::assertSame(204, $passwordOnly['status'], $passwordOnly['body']);
		self::assertSame(0, self::flag(self::FIELD_SCOPE_ZERO_GRANT));
		self::assertTrue(password_verify('field-scope-pw-2', self::storedPasswordHash(self::FIELD_SCOPE_ZERO_GRANT)));
	}

	/**
	 * @return array<string, array{string, string, ?array}>
	 */
	public static function stillRefusedForAZeroGrantFlaggedAccount(): array
	{
		return [
			'a read of household data' => ['GET', '/api/objects/products', null],
			'creating a second user' => ['POST', '/api/users', ['username' => 'planted-by-zero-grant', 'password' => 'planted-pw-1']],
			'editing somebody else\'s account' => ['PUT', '/api/users/' . self::ADMIN_ACTOR, ['username' => 'rotation-admin-actor-renamed']],
			'the settings bag the flag used to live in' => ['DELETE', '/api/user/settings/must_change_password', null],
		];
	}

	/**
	 * Given the zero-grant bypass exists, every write it does not name stays refused
	 * exactly as it was before the bypass - the new authority is the password on the
	 * caller's own account and nothing wider.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('stillRefusedForAZeroGrantFlaggedAccount')]
	public function testForcedRotationBypassDoesNotWidenTheExistingWallForOtherRoutes(string $method, string $path, ?array $body): void
	{
		$answer = self::request(array_filter([
			'method' => $method,
			'path' => $path,
			'cookie' => 'rotation-wall-session',
			'body' => $body,
		], fn($v) => $v !== null));

		self::assertSame(403, $answer['status'], "$method $path: " . $answer['body']);
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM users WHERE username = 'planted-by-zero-grant'")->fetchColumn());
		self::assertSame(1, self::flag(self::WALL_ZERO_GRANT), 'still flagged - none of these refusals is the resolving write');
	}
}
