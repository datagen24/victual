<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Password rotation, end to end: what happens to other sessions and API keys when a
 * password changes (issue #513), and the narrow path a flagged
 * (`must_change_password`) account uses to resolve that flag (issue #514).
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php) because
 * the authentication middleware define()s the acting user's constants and PHP cannot
 * redefine them - the same reason BootstrapAdminTest, which this class sits beside in
 * the `bootstrapadmin` testsuite, uses a process per request.
 *
 * What should be true now:
 *
 *   - changing a password revokes every other session of that account, whether the
 *     change was made by the account itself or by an administrator (M13);
 *   - an ordinary (unflagged) self-service change spares the session that made it; a
 *     flagged account's own session is not spared either - every session on a flagged
 *     account, the acting one included, was opened under the credential the rotation
 *     exists to get away from, so a fresh session replaces it instead;
 *   - API keys are a separate credential and are never revoked by a password change;
 *   - a flagged account may change its own password regardless of USERS_EDIT_SELF, with
 *     the correct current password, and nothing else - whether or not it happens to also
 *     hold that permission (M14);
 *   - every current-password check on this route is throttled the same way a wrong login
 *     password is, and a request's own shape (username present, nothing beyond the
 *     password touched while flagged) is refused before the password is even checked, so
 *     neither can be used as an oracle for whether the guess was right;
 *   - a flagged account's own edit page renders regardless of permission, so the redirect
 *     BaseAuthMiddleware sends it on does not trap it behind a second refusal;
 *   - every other write a flagged account was already refused stays refused.
 */
class PasswordRotationTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	private const ADMIN_ACTOR = 9700;
	private const RESET_TARGET = 9701;
	private const SELF_ROTATE_UNFLAGGED = 9702;
	private const SELF_ROTATE_NO_SESSION = 9703;
	private const FORCED_ZERO_GRANT = 9704;
	private const THROTTLE_ZERO_GRANT = 9705;
	private const FIELD_SCOPE_ZERO_GRANT = 9706;
	private const WALL_ZERO_GRANT = 9707;
	private const SELF_ROTATE_FLAGGED = 9708;
	private const FLAGGED_ADMIN_ESCAPE = 9709;
	private const ORACLE_UNFLAGGED = 9710;
	private const PAGE_ZERO_GRANT = 9711;

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

		// Unflagged: the ordinary self password change, which still spares the acting
		// session - validator round 2 asked for this contrast explicitly.
		self::createUser(self::SELF_ROTATE_UNFLAGGED, 'rotation-self-unflagged', 'self-unflagged-pw-1');
		self::grantAdmin(self::SELF_ROTATE_UNFLAGGED);
		self::createSession('rotation-unflagged-acting-session', self::SELF_ROTATE_UNFLAGGED);
		self::createSession('rotation-unflagged-other-session', self::SELF_ROTATE_UNFLAGGED);

		// Flagged, and holds USERS_EDIT_SELF (ADMIN) - the acting session is NOT spared
		// here, unlike the unflagged fixture above: it was opened under the very
		// credential this rotation exists to get away from.
		self::createUser(self::SELF_ROTATE_FLAGGED, 'rotation-self-flagged', 'self-flagged-pw-1', mustChangePassword: true);
		self::grantAdmin(self::SELF_ROTATE_FLAGGED);
		self::createSession('rotation-flagged-acting-session', self::SELF_ROTATE_FLAGGED);
		self::createSession('rotation-flagged-other-session', self::SELF_ROTATE_FLAGGED);

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

		// Flagged and ADMIN both - the escape validator round 2 found: a role that grants
		// USERS_EDIT_SELF must not exempt a flagged account from the password-only rule
		// or the throttle.
		self::createUser(self::FLAGGED_ADMIN_ESCAPE, 'rotation-flagged-admin-escape', 'flagged-admin-pw-1', mustChangePassword: true);
		self::grantAdmin(self::FLAGGED_ADMIN_ESCAPE);
		self::createSession('rotation-flagged-admin-session', self::FLAGGED_ADMIN_ESCAPE);

		// Unflagged and ADMIN: the current-password oracle and the now-universal throttle
		// are both about this ordinary, fully-permitted shape, not the bypass.
		self::createUser(self::ORACLE_UNFLAGGED, 'rotation-oracle-unflagged', 'oracle-unflagged-pw-1');
		self::grantAdmin(self::ORACLE_UNFLAGGED);
		self::createSession('rotation-oracle-session', self::ORACLE_UNFLAGGED);

		self::createUser(self::PAGE_ZERO_GRANT, 'rotation-page-zero-grant', 'page-zero-grant-pw-1', mustChangePassword: true);
		self::createSession('rotation-page-session', self::PAGE_ZERO_GRANT);
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
	 * @param array<string, string> $settingOverrides VICTUAL_* overrides (e.g. the
	 *                                                 throttle limit), passed as
	 *                                                 environment variables because that
	 *                                                 is what Setting() consults
	 * @return array{status: int, body: string}
	 */
	private static function request(array $spec, array $settingOverrides = []): array
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

		foreach ($settingOverrides as $name => $value)
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

	/** The one remaining session key for a user expected to hold exactly one. */
	private static function soleSessionKey(int $userId): string
	{
		$statement = self::$db->prepare('SELECT session_key FROM sessions WHERE user_id = ?');
		$statement->execute([$userId]);
		$keys = $statement->fetchAll(PDO::FETCH_COLUMN, 0);

		self::assertCount(1, $keys, "expected exactly one session for user $userId, found: " . json_encode($keys));

		return $keys[0];
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
	 * Given an ordinary (unflagged) self password change made from a session, when it
	 * succeeds, then every other session of the account is gone, the session that made
	 * the change is still valid, and an API key of that account - a separate credential
	 * - is untouched (issue #513).
	 */
	public function testUnflaggedSelfPasswordChangeRevokesOtherSessionsButKeepsTheActingOne(): void
	{
		$apiKey = self::issueApiKey(self::SELF_ROTATE_UNFLAGGED);
		$keysBefore = self::apiKeyCount(self::SELF_ROTATE_UNFLAGGED);

		$change = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::SELF_ROTATE_UNFLAGGED,
			'cookie' => 'rotation-unflagged-acting-session',
			'body' => [
				'username' => 'rotation-self-unflagged',
				'password' => 'self-unflagged-pw-2',
				'current_password' => 'self-unflagged-pw-1',
			],
		]);

		self::assertSame(204, $change['status'], $change['body']);
		self::assertTrue(password_verify('self-unflagged-pw-2', self::storedPasswordHash(self::SELF_ROTATE_UNFLAGGED)), 'the new password was stored');

		self::assertTrue(self::sessionExists('rotation-unflagged-acting-session'), 'the session that made the change is not logged out by its own request');
		self::assertFalse(self::sessionExists('rotation-unflagged-other-session'), 'every other session of the account is revoked');

		self::assertSame($keysBefore, self::apiKeyCount(self::SELF_ROTATE_UNFLAGGED), 'API keys are a separate credential and are not touched');
		$keyStillWorks = self::request(['method' => 'GET', 'path' => '/api/user', 'headers' => ['VICTUAL-API-KEY' => $apiKey]]);
		self::assertSame(200, $keyStillWorks['status'], 'the key itself still authenticates: ' . $keyStillWorks['body']);

		$stillLoggedIn = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'rotation-unflagged-acting-session']);
		self::assertSame(200, $stillLoggedIn['status'], 'the acting session is still valid after the change: ' . $stillLoggedIn['body']);

		$loggedOut = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'rotation-unflagged-other-session']);
		self::assertSame(401, $loggedOut['status'], 'the other session no longer authenticates');
	}

	/**
	 * Given a FLAGGED self password change made from a session - even one held by an
	 * account with USERS_EDIT_SELF (ADMIN here) - when it succeeds, then the acting
	 * session is not spared either: every session on the account was opened under the
	 * credential this rotation exists to get away from, so all of them are revoked and a
	 * fresh session replaces the one that authenticated this request (validator round 2
	 * on issue #513 - the round 1 fix always spared the acting session, which left a
	 * bootstrap-credential session with full authority after its own rotation).
	 */
	public function testFlaggedSelfPasswordChangeRotatesTheActingSessionInstead(): void
	{
		self::assertTrue(self::sessionExists('rotation-flagged-acting-session'));
		self::assertTrue(self::sessionExists('rotation-flagged-other-session'));

		$change = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::SELF_ROTATE_FLAGGED,
			'cookie' => 'rotation-flagged-acting-session',
			'body' => [
				'username' => 'rotation-self-flagged',
				'password' => 'self-flagged-pw-2',
				'current_password' => 'self-flagged-pw-1',
			],
		]);

		self::assertSame(204, $change['status'], $change['body']);
		self::assertSame(0, self::flag(self::SELF_ROTATE_FLAGGED));
		self::assertTrue(password_verify('self-flagged-pw-2', self::storedPasswordHash(self::SELF_ROTATE_FLAGGED)));

		self::assertFalse(self::sessionExists('rotation-flagged-acting-session'), 'the acting session was opened while flagged and does not survive its own rotation');
		self::assertFalse(self::sessionExists('rotation-flagged-other-session'), 'and neither does any other session of the account');

		$oldCookieRefused = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'rotation-flagged-acting-session']);
		self::assertSame(401, $oldCookieRefused['status'], 'the old session key answers 401 afterwards');

		// Exactly one session remains: the fresh one minted in the same response, the way
		// a new login would. It authenticates like any other session.
		$newSessionKey = self::soleSessionKey(self::SELF_ROTATE_FLAGGED);
		self::assertNotSame('rotation-flagged-acting-session', $newSessionKey);
		$newCookieWorks = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => $newSessionKey]);
		self::assertSame(200, $newCookieWorks['status'], 'the freshly minted session authenticates: ' . $newCookieWorks['body']);
	}

	/**
	 * Given a self password change authenticated by something other than a session
	 * cookie (an API key, here), when it succeeds, then there is no session to except
	 * and no session to replace - every existing session of the account is revoked and
	 * none is minted - the direct M13 reproduction, where two sessions were created
	 * independently of the request that changed the password and both remained valid.
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
		self::assertSame(0, self::sessionCountFor(self::SELF_ROTATE_NO_SESSION), 'nothing was minted either - there was no browser session to replace');
	}

	private static function sessionCountFor(int $userId): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM sessions WHERE user_id = ?');
		$statement->execute([$userId]);

		return (int)$statement->fetchColumn();
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
	 * succeeds and the flag clears, although USERS_EDIT_SELF was never granted, and the
	 * acting session is rotated (revoked and replaced) rather than kept, since it too
	 * was opened while the account was flagged.
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

		// The account is no longer held to the allowlist, exactly as an ordinarily
		// permitted account is once it resolves the flag - but the session that resolved
		// it does not survive to prove that: it was opened while flagged, so it is
		// rotated like any other flagged account's (issue #513, validator round 2).
		$oldCookieRefused = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'rotation-forced-zero-grant-session']);
		self::assertSame(401, $oldCookieRefused['status'], 'the old session key answers 401 afterwards');

		$newSessionKey = self::soleSessionKey(self::FORCED_ZERO_GRANT);
		$newCookieWorks = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => $newSessionKey]);
		self::assertSame(200, $newCookieWorks['status'], $newCookieWorks['body']);
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
	 * write is refused with a 403 (not the usual 400 - this is an authorization refusal,
	 * answered the way a missing permission is) and every stored field, including the
	 * password, is unchanged. A resubmission shaped exactly like userform.js's own body -
	 * blank names as empty strings, no picture_file_name key at all - then succeeds,
	 * showing the refusal was about the attempted extra change and not about the
	 * account being unable to use the bypass, or about the form's own idioms for "no
	 * change here" (validator round 2: the first version's strict comparison refused
	 * exactly this shape whenever a name was NULL or a picture already existed).
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

		self::assertSame(403, $renameAttempt['status'], $renameAttempt['body']);
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

		self::assertSame(403, $firstNameAttempt['status'], $firstNameAttempt['body']);
		self::assertSame($originalHash, self::storedPasswordHash(self::FIELD_SCOPE_ZERO_GRANT));

		// Shaped exactly like userform.js's own submission for "just change the password":
		// first_name/last_name serialize as "" (the stored values are NULL, never set),
		// and picture_file_name is omitted entirely rather than sent as null.
		$formShaped = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::FIELD_SCOPE_ZERO_GRANT,
			'cookie' => 'rotation-field-scope-session',
			'body' => [
				'username' => 'rotation-field-scope-zero-grant',
				'first_name' => '',
				'last_name' => '',
				'password' => 'field-scope-pw-2',
				'current_password' => 'field-scope-pw-1',
			],
		]);

		self::assertSame(204, $formShaped['status'], $formShaped['body']);
		self::assertSame(0, self::flag(self::FIELD_SCOPE_ZERO_GRANT));
		self::assertTrue(password_verify('field-scope-pw-2', self::storedPasswordHash(self::FIELD_SCOPE_ZERO_GRANT)));
	}

	/**
	 * Given a flagged account that DOES hold USERS_EDIT_SELF (ADMIN here, but ADULT and
	 * CHILD grant it too), when it bundles a rename with its forced password change, or
	 * guesses the current password wrong repeatedly, then it is refused and throttled
	 * exactly like a zero-grant flagged account - the password-only rule and the
	 * throttle are keyed on must_change_password, not on lacking the permission
	 * (validator round 2: previously ForcedRotationOnly() gated both on the missing
	 * permission, so a flagged account holding USERS_EDIT_SELF escaped both controls
	 * entirely).
	 */
	public function testFlaggedAccountWithUsersEditSelfIsStillRestrictedAndThrottled(): void
	{
		$originalHash = self::storedPasswordHash(self::FLAGGED_ADMIN_ESCAPE);

		$bundledRename = self::request([
			'method' => 'PUT',
			'path' => '/api/users/' . self::FLAGGED_ADMIN_ESCAPE,
			'cookie' => 'rotation-flagged-admin-session',
			'body' => [
				'username' => 'rotation-flagged-admin-escape',
				'first_name' => 'Escaped',
				'password' => 'flagged-admin-pw-2',
				'current_password' => 'flagged-admin-pw-1',
			],
		]);

		self::assertSame(403, $bundledRename['status'], 'holding USERS_EDIT_SELF does not exempt a flagged account from the password-only rule: ' . $bundledRename['body']);
		self::assertSame($originalHash, self::storedPasswordHash(self::FLAGGED_ADMIN_ESCAPE));
		self::assertSame(1, self::flag(self::FLAGGED_ADMIN_ESCAPE));

		$username = 'rotation-flagged-admin-escape';
		$settings = ['LOGIN_THROTTLE_MAX_ATTEMPTS' => '2'];

		try
		{
			foreach (['wrong-1', 'wrong-2'] as $i => $wrongPassword)
			{
				$attempt = self::request([
					'method' => 'PUT',
					'path' => '/api/users/' . self::FLAGGED_ADMIN_ESCAPE,
					'cookie' => 'rotation-flagged-admin-session',
					'body' => [
						'username' => $username,
						'password' => 'flagged-admin-pw-2',
						'current_password' => $wrongPassword,
					],
				], $settings);

				self::assertSame(400, $attempt['status']);
				self::assertSame($i + 1, self::loginAttemptCount($username), 'holding USERS_EDIT_SELF does not exempt a flagged account from the throttle either');
			}

			$lockedOut = self::request([
				'method' => 'PUT',
				'path' => '/api/users/' . self::FLAGGED_ADMIN_ESCAPE,
				'cookie' => 'rotation-flagged-admin-session',
				'body' => [
					'username' => $username,
					'password' => 'flagged-admin-pw-2',
					'current_password' => 'flagged-admin-pw-1',
				],
			], $settings);

			self::assertSame(400, $lockedOut['status'], 'the correct password is refused while locked out, permission notwithstanding: ' . $lockedOut['body']);
			self::assertSame($originalHash, self::storedPasswordHash(self::FLAGGED_ADMIN_ESCAPE));
			self::assertSame(1, self::flag(self::FLAGGED_ADMIN_ESCAPE));
		}
		finally
		{
			self::$db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
		}
	}

	/**
	 * Given an ordinary (unflagged), fully-permitted self-edit, when username is
	 * omitted, then the answer is identical whether the current password submitted
	 * alongside it is right or wrong - "username is required" either way, and neither
	 * counts toward the throttle, since CheckCurrentPassword() never runs for a request
	 * refused on shape alone. A subsequent well-formed request with a wrong current
	 * password IS counted, showing the throttle now covers this ordinary path and not
	 * only the forced-rotation bypass (issue #514's remaining subclaim; validator round
	 * 2 demonstrated the oracle with exactly the omitted-username shape).
	 */
	public function testOracleClosedForUsernameAndThrottleAppliesToOrdinarySelfChange(): void
	{
		$username = 'rotation-oracle-unflagged';

		try
		{
			$wrongGuessNoUsername = self::request([
				'method' => 'PUT',
				'path' => '/api/users/' . self::ORACLE_UNFLAGGED,
				'cookie' => 'rotation-oracle-session',
				'body' => [
					'password' => 'oracle-unflagged-pw-2',
					'current_password' => 'not-the-password',
				],
			]);

			$rightGuessNoUsername = self::request([
				'method' => 'PUT',
				'path' => '/api/users/' . self::ORACLE_UNFLAGGED,
				'cookie' => 'rotation-oracle-session',
				'body' => [
					'password' => 'oracle-unflagged-pw-2',
					'current_password' => 'oracle-unflagged-pw-1',
				],
			]);

			self::assertSame(400, $wrongGuessNoUsername['status']);
			self::assertSame(400, $rightGuessNoUsername['status']);
			self::assertSame($wrongGuessNoUsername['status'], $rightGuessNoUsername['status'], 'the status does not distinguish a right guess from a wrong one');
			self::assertSame($wrongGuessNoUsername['body'], $rightGuessNoUsername['body'], 'nor does the body - both are refused on the missing username alone');
			self::assertStringContainsString('username', $wrongGuessNoUsername['body']);
			self::assertSame(0, self::loginAttemptCount($username), 'a request refused on shape never reaches the password check, so neither guess was counted');
			self::assertTrue(password_verify('oracle-unflagged-pw-1', self::storedPasswordHash(self::ORACLE_UNFLAGGED)), 'and neither changed the stored password');

			$wellFormedWrongGuess = self::request([
				'method' => 'PUT',
				'path' => '/api/users/' . self::ORACLE_UNFLAGGED,
				'cookie' => 'rotation-oracle-session',
				'body' => [
					'username' => $username,
					'password' => 'oracle-unflagged-pw-2',
					'current_password' => 'not-the-password',
				],
			]);

			self::assertSame(400, $wellFormedWrongGuess['status']);
			self::assertSame(1, self::loginAttemptCount($username), 'a well-formed request DOES cost the throttle on a wrong guess, on this ordinary path too');
		}
		finally
		{
			self::$db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
		}
	}

	/**
	 * Given a flagged account with no permissions at all, when it requests its own edit
	 * page (the page BaseAuthMiddleware's redirect sends it to, to resolve the flag),
	 * then the page renders rather than answering 403 - refusing it would trap the
	 * account behind a redirect with no route off it at all (issue #514, validator
	 * round 2).
	 */
	public function testOwnEditPageRendersForAZeroGrantFlaggedAccount(): void
	{
		$page = self::request(['method' => 'GET', 'path' => '/user/' . self::PAGE_ZERO_GRANT, 'cookie' => 'rotation-page-session']);

		self::assertSame(200, $page['status']);
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
