<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\TestCase;
use Victual\Middleware\Auth\SessionCookie;
use Victual\Services\ApiKeyService;
use Victual\Services\SessionService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The boot gate and the authentication stack, end to end: SchemaVersionMiddleware,
 * CorsMiddleware, BaseAuthMiddleware and the four things that recognise a caller
 * (ApiKeyAuthenticator, SessionAuthenticator, ReverseProxyAuthenticator, PasswordLogin).
 *
 * Tier 1 per ADR-0025. Every request is its own process
 * (tests/Pgsql/authstack-subprocess-helper.php) because the authentication middleware
 * define()s the acting user's constants and PHP cannot redefine one - and because most of
 * what is asserted here (which settings are in force, what is in $_SERVER) is fixed for the
 * life of a process too.
 *
 * Scope note. The `schema` phase (.devtools/pgsql/schemagatetest.php) already asks
 * DatabaseMigrationService the schema-version question directly, on both engines: holes
 * below the maximum, the missing-table SQLSTATE, the demo marker, a rollback. It never
 * makes a request, so it says nothing about what a client is answered. This covers that
 * half - the status, the body, which advice each condition gets, and that the gate runs in
 * front of authentication - and does not repeat the service-level cases.
 *
 * ADR-0006 is why the refusals are asserted as carefully as the successes: the
 * authenticated boundary is a real one here, so "who was refused, and did the refusal write
 * anything" is the question rather than "did it say 401". ADR-0007 is why the session cases
 * assert that the sessions row is read on every request rather than remembered.
 */
class AuthStackTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	/** ADMIN, authenticating by a valid default-type API key. */
	private static string $adminKey;

	/** ADMIN, authenticating by a session cookie. */
	private static string $sessionKey;

	/** A key whose expiry has passed. */
	private static string $expiredKey;

	/** A key of a type the header path must not accept. */
	private static string $labelWorkerKey;

	/** A key that was issued and then deleted. */
	private static string $revokedKey;

	/** The special-purpose key the calendar feed URL carries. */
	private static string $calendarKey;

	/** A label worker's credential, for the routes that accept nothing else. */
	private static string $workerCredential;

	private const WORKER_ID = 9620;

	private const ADMIN_USER_ID = 9600;
	private const SESSION_USER_ID = 9601;
	private const PASSWORD_USER_ID = 9602;
	private const LEGACY_HASH_USER_ID = 9603;
	private const SEEDED_PASSWORD_USER_ID = 9604;
	private const PROXY_USER_ID = 9605;

	private const PASSWORD_USER = 'authstack-password';
	private const PASSWORD = 'correct horse battery staple';

	/** The origin the helper's requests are addressed to, so "same origin" has a value. */
	private const OWN_ORIGIN = 'http://localhost';
	private const ALLOWED_ORIGIN = 'https://client.example';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// VICTUAL_USER_ID is 9000 for this process; the row has to exist even though every
		// request under test runs in a child process with an identity of its own
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'authstack-caller', 'fixture')");

		// A real hash rather than the usual 'fixture' placeholder: this account is the one
		// that walks the forced-password-change path, which verifies the current password
		self::createUser(self::ADMIN_USER_ID, 'authstack-admin', password_hash(self::PASSWORD, PASSWORD_ARGON2ID));
		self::grantAdmin(self::ADMIN_USER_ID);
		self::$adminKey = self::issueKey(self::ADMIN_USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT, '+30 days');

		self::createUser(self::SESSION_USER_ID, 'authstack-session');
		self::grantAdmin(self::SESSION_USER_ID);
		self::$sessionKey = self::issueSession(self::SESSION_USER_ID, '+30 days');

		self::$expiredKey = self::issueKey(self::ADMIN_USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT, '-1 day');
		self::$labelWorkerKey = self::issueKey(self::ADMIN_USER_ID, ApiKeyService::API_KEY_TYPE_LABEL_WORKER, '+30 days');
		self::$calendarKey = self::issueKey(self::ADMIN_USER_ID, ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL, '+30 days');

		self::$revokedKey = self::issueKey(self::ADMIN_USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT, '+30 days');
		$statement = self::$db->prepare('DELETE FROM api_keys WHERE api_key = ?');
		$statement->execute([ApiKeyService::HashKey(self::$revokedKey)]);

		// An account whose password is known to this test, for the login path
		self::createUser(self::PASSWORD_USER_ID, self::PASSWORD_USER, password_hash(self::PASSWORD, PASSWORD_ARGON2ID));

		// Stored under an algorithm that is no longer the default, for the rehash-on-login
		// case. bcrypt is what upstream grocy wrote and what an in-place upgrade still holds.
		self::createUser(self::LEGACY_HASH_USER_ID, 'authstack-legacy-hash', password_hash(self::PASSWORD, PASSWORD_BCRYPT));

		// The publicly known seeded password, which logging in with must raise the flag
		self::createUser(self::SEEDED_PASSWORD_USER_ID, 'authstack-seeded', password_hash('admin', PASSWORD_ARGON2ID));

		self::issueWorkerCredential();
	}

	/**
	 * A declared label worker and the credential it prints with. Issued through the service
	 * that issues one in production rather than by hand, because the credential is a row in
	 * two tables and a key type, and a hand-made one would prove the authenticator accepts
	 * something production never mints.
	 */
	private static function issueWorkerCredential(): void
	{
		self::$db->exec('INSERT INTO label_workers (id, name, configuration_mode, active) '
			. "VALUES (" . self::WORKER_ID . ", 'authstack-worker', 'declared', 1)");

		self::$db->beginTransaction();
		$issued = (new \Victual\Services\Labels\LabelWorkerCredentialService(self::$db))
			->IssueDeclared(self::WORKER_ID, self::ADMIN_USER_ID);
		self::$db->commit();

		self::$workerCredential = $issued['credential'];
	}

	private static function createUser(int $id, string $username, string $password = 'fixture'): void
	{
		$statement = self::$db->prepare('INSERT INTO users(id, username, password) VALUES (?, ?, ?)');
		$statement->execute([$id, $username, $password]);
	}

	private static function grantAdmin(int $userId): void
	{
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) '
			. "SELECT ?, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		$statement->execute([$userId]);
	}

	/** Issues an API key of the given type and returns its plaintext. */
	private static function issueKey(int $userId, string $keyType, string $expiryOffset): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. 'VALUES (?, ?, ?, ?, ?)');
		$statement->execute([
			ApiKeyService::StoredValueOf($key, $keyType),
			substr($key, -4),
			$userId,
			date('Y-m-d H:i:s', strtotime($expiryOffset)),
			$keyType
		]);

		return $key;
	}

	/** Creates a sessions row directly and returns its key. */
	private static function issueSession(int $userId, string $expiryOffset): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO sessions (session_key, user_id, expires) VALUES (?, ?, ?)');
		$statement->execute([$key, $userId, date('Y-m-d H:i:s', strtotime($expiryOffset))]);

		return $key;
	}

	/**
	 * One request through the production middleware stack, in a process of its own.
	 *
	 * @param array $options headers, cookie, body, server (extra $_SERVER entries) and
	 *                       settings (VICTUAL_* overrides, passed as environment variables
	 *                       because that is what Setting() consults)
	 * @return array{status: int, headers: array<string, string[]>, body: string}
	 */
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

	/** The first value of a response header, or '' when it was not sent. */
	private static function header(array $response, string $name): string
	{
		foreach ($response['headers'] as $header => $values)
		{
			if (strcasecmp($header, $name) === 0)
			{
				return implode(', ', $values);
			}
		}

		return '';
	}

	private static function sessionCount(): int
	{
		return (int)self::$db->query('SELECT count(*) FROM sessions')->fetchColumn();
	}

	/** The last_used stamp of an API key row, or null when it has never been used. */
	private static function keyLastUsed(string $plaintext, string $keyType = ApiKeyService::API_KEY_TYPE_DEFAULT): ?string
	{
		$statement = self::$db->prepare('SELECT last_used FROM api_keys WHERE api_key = ?');
		$statement->execute([ApiKeyService::StoredValueOf($plaintext, $keyType)]);
		$value = $statement->fetchColumn();

		return $value === false ? null : $value;
	}

	private static function loginAttemptCount(string $username): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM login_attempts WHERE username = ?');
		$statement->execute([$username]);

		return (int)$statement->fetchColumn();
	}

	// --- SchemaVersionMiddleware -------------------------------------------------------

	/** Every migration number this engine requires, ascending. */
	private static function requiredMigrations(): array
	{
		return array_map(
			'intval',
			self::$db->query('SELECT migration FROM migrations WHERE migration >= 0 ORDER BY migration')
				->fetchAll(PDO::FETCH_COLUMN, 0)
		);
	}

	/** The "Missing from the database: ..." list out of a refusal body. */
	private static function missingSummary(string $body): string
	{
		$matches = [];
		self::assertSame(1, preg_match('/^Missing from the database: (.+)$/m', $body, $matches), $body);

		return trim($matches[1]);
	}

	/** The numbers a range summary stands for, so it can be compared with the real set. */
	private static function expandRanges(string $summary): array
	{
		$numbers = [];

		foreach (explode(',', $summary) as $part)
		{
			$bounds = array_map('intval', explode('-', trim($part), 2));
			$numbers = array_merge($numbers, range($bounds[0], $bounds[1] ?? $bounds[0]));
		}

		return $numbers;
	}

	public function testAMatchingSchemaServesTheRequest(): void
	{
		$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

		self::assertSame(200, $response['status'], $response['body']);
		$decoded = json_decode($response['body'], true);
		self::assertSame('authstack-admin', $decoded[0]['username'] ?? null, $response['body']);
	}

	/**
	 * The rollback case: a database migrated by a newer build than the code deployed
	 * against it. It looks more like health than being behind does - every migration the
	 * code knows about has run - which is why the gate has to name it as its own condition.
	 */
	public function testADatabaseMigratedBeyondTheCodeRefuses(): void
	{
		$required = self::requiredMigrations();
		$ahead = end($required) + 1;

		self::$db->exec('INSERT INTO migrations (migration) VALUES (' . $ahead . ')');

		try
		{
			$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

			self::assertSame(503, $response['status'], $response['body']);
			self::assertStringContainsString('text/plain', self::header($response, 'Content-Type'));
			self::assertStringContainsString('Applied but unknown to this code: ' . $ahead, $response['body']);
			self::assertStringContainsString('The database is ahead of the code', $response['body']);

			// The advice for the opposite direction must not be given here: running
			// migrations cannot help a database that is already past the code
			self::assertStringNotContainsString('Missing from the database', $response['body']);
		}
		finally
		{
			self::$db->exec('DELETE FROM migrations WHERE migration = ' . $ahead);
		}
	}

	/**
	 * A database behind the code, with a hole that is not at the end and one that is: the
	 * refusal names the numbers, collapses a consecutive run into a range and tells the
	 * operator to run the migration command.
	 */
	public function testADatabaseBehindTheCodeRefusesAndNamesTheMissingMigrations(): void
	{
		$required = self::requiredMigrations();
		$count = count($required);

		// Two adjacent and one apart from them, so that both halves of the range summary
		// have something to do
		$adjacent = [$required[$count - 3], $required[$count - 2]];
		$isolated = $required[$count - 6];
		$removed = array_merge([$isolated], $adjacent);

		self::$db->exec('DELETE FROM migrations WHERE migration IN (' . implode(',', $removed) . ')');

		try
		{
			$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

			self::assertSame(503, $response['status'], $response['body']);
			self::assertStringContainsString(
				'Missing from the database: ' . $isolated . ', ' . $adjacent[0] . '-' . $adjacent[1],
				$response['body']
			);
			self::assertStringContainsString('php bin/victual-migrate', $response['body']);
			self::assertStringNotContainsString('ahead of the code', $response['body']);
		}
		finally
		{
			foreach ($removed as $migration)
			{
				self::$db->exec('INSERT INTO migrations (migration) VALUES (' . $migration . ')');
			}
		}
	}

	/**
	 * The distinction the gate exists to make: a database nobody has migrated is told to
	 * run the migrations, and a database that could not be asked the question at all is
	 * explicitly told that this is not a migration problem. Answering both with the same
	 * message sends the second operator in the wrong direction.
	 */
	public function testAnUnmigratedDatabaseIsDistinguishableFromAnUnreachableOne(): void
	{
		$required = self::requiredMigrations();

		self::$db->exec('ALTER TABLE migrations RENAME TO migrations_authstack_probe');

		try
		{
			$unmigrated = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

			self::assertSame(503, $unmigrated['status'], $unmigrated['body']);
			self::assertStringContainsString('highest applied migration 0 (nothing migrated yet)', $unmigrated['body']);
			self::assertStringContainsString('php bin/victual-migrate', $unmigrated['body']);

			// Every migration there is, named rather than counted - and named as ranges, so
			// that "nothing has run here" is one line instead of 286 numbers nobody counts
			$summary = self::missingSummary($unmigrated['body']);
			self::assertMatchesRegularExpression('/\d+-\d+/', $summary, 'consecutive numbers are collapsed');
			self::assertSame($required, self::expandRanges($summary), 'and the collapsed list is exactly what is missing');
		}
		finally
		{
			self::$db->exec('ALTER TABLE migrations_authstack_probe RENAME TO migrations');
		}

		// A migrations relation that is present and cannot be read: the cheapest stand-in
		// for an unreachable server or a role without SELECT, and the same PDOException
		// shape reaching the middleware
		self::$db->exec('ALTER TABLE migrations RENAME TO migrations_authstack_probe');
		self::$db->exec('CREATE VIEW migrations AS SELECT (1 / 0) AS migration');

		try
		{
			$unreachable = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

			self::assertSame(503, $unreachable['status'], $unreachable['body']);
			self::assertStringContainsString('the database could not be queried', $unreachable['body']);
			self::assertStringContainsString('This is not a migration problem', $unreachable['body']);
			self::assertStringContainsString('SQLSTATE: 22012', $unreachable['body']);

			// The whole point of the second message: it must not tell somebody whose
			// database is down to migrate it
			self::assertStringNotContainsString('victual-migrate', $unreachable['body']);
			self::assertStringNotContainsString('Missing from the database', $unreachable['body']);
		}
		finally
		{
			self::$db->exec('DROP VIEW migrations');
			self::$db->exec('ALTER TABLE migrations_authstack_probe RENAME TO migrations');
		}
	}

	/**
	 * The gate is outside routing and authentication on purpose (app.php:122-126), so a
	 * caller with no credentials at all is told the schema is wrong rather than told to
	 * authenticate - the answer is the same for everybody and does not depend on a database
	 * that cannot be trusted to identify anybody.
	 */
	public function testTheSchemaRefusalPrecedesAuthentication(): void
	{
		$required = self::requiredMigrations();
		$ahead = end($required) + 1;

		self::$db->exec('INSERT INTO migrations (migration) VALUES (' . $ahead . ')');

		try
		{
			$anonymous = self::send('GET', '/api/user');
			self::assertSame(503, $anonymous['status'], $anonymous['body']);

			// And a route that does not exist: still the schema answer, not a 404
			$unrouted = self::send('GET', '/api/authstack-no-such-route');
			self::assertSame(503, $unrouted['status'], $unrouted['body']);
		}
		finally
		{
			self::$db->exec('DELETE FROM migrations WHERE migration = ' . $ahead);
		}
	}

	/**
	 * MIGRATE_ON_ROOT_REQUEST is the installation with nowhere to run a command from: the
	 * root route is what migrates, so the gate cannot stand in front of it. Every other
	 * route stays checked, which is what keeps the exemption from being a hole.
	 *
	 * Asserted with a database that is ahead rather than behind, so that the root request
	 * has nothing to migrate and the two cases differ only in the gate.
	 */
	public function testTheRootRouteIsExemptOnlyWhenAutoMigrationIsOn(): void
	{
		$required = self::requiredMigrations();
		$ahead = end($required) + 1;

		self::$db->exec('INSERT INTO migrations (migration) VALUES (' . $ahead . ')');

		try
		{
			$gated = self::send('GET', '/');
			self::assertSame(503, $gated['status'], 'the root route is checked by default: ' . $gated['body']);

			$exempt = self::send('GET', '/', ['settings' => ['MIGRATE_ON_ROOT_REQUEST' => 'true']]);
			self::assertNotSame(503, $exempt['status'], 'the root route is not checked when it is the migrator');

			// The exemption is for "/" alone - the API still refuses to answer
			$api = self::send('GET', '/api/user', [
				'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
				'settings' => ['MIGRATE_ON_ROOT_REQUEST' => 'true']
			]);
			self::assertSame(503, $api['status'], $api['body']);
		}
		finally
		{
			self::$db->exec('DELETE FROM migrations WHERE migration = ' . $ahead);
		}
	}

	/**
	 * A database that is behind in one place and ahead in another - a rollback onto an image
	 * that also never ran one migration. Both halves of the diagnosis are reported, because
	 * fixing one of them still leaves the database unserveable.
	 */
	public function testADatabaseWrongInBothDirectionsIsToldAboutBoth(): void
	{
		$required = self::requiredMigrations();
		$ahead = end($required) + 1;
		$hole = $required[count($required) - 2];

		self::$db->exec('INSERT INTO migrations (migration) VALUES (' . $ahead . ')');
		self::$db->exec('DELETE FROM migrations WHERE migration = ' . $hole);

		try
		{
			$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

			self::assertSame(503, $response['status'], $response['body']);
			self::assertStringContainsString('Missing from the database: ' . $hole, $response['body']);
			self::assertStringContainsString('Applied but unknown to this code: ' . $ahead, $response['body']);
		}
		finally
		{
			self::$db->exec('DELETE FROM migrations WHERE migration = ' . $ahead);
			self::$db->exec('INSERT INTO migrations (migration) VALUES (' . $hole . ')');
		}
	}

	/**
	 * The unreachable-database body is emitted before authentication, and a connection
	 * failure names the host, port and role - so the driver's own message is in the log and
	 * not in the response, except in dev mode where the error middleware shows details for
	 * the same reason.
	 */
	public function testTheDriverMessageIsOnlyInTheBodyInDevMode(): void
	{
		self::$db->exec('ALTER TABLE migrations RENAME TO migrations_authstack_probe');
		self::$db->exec('CREATE VIEW migrations AS SELECT (1 / 0) AS migration');

		try
		{
			$production = self::send('GET', '/api/user');
			self::assertSame(503, $production['status']);
			self::assertStringNotContainsString('division by zero', $production['body'],
				'the driver text is for the log, not for an unauthenticated caller');

			$development = self::send('GET', '/api/user', ['settings' => ['MODE' => 'dev']]);
			self::assertSame(503, $development['status']);
			self::assertStringContainsString('division by zero', $development['body'],
				'dev mode shows the details, as the error middleware does');
		}
		finally
		{
			self::$db->exec('DROP VIEW migrations');
			self::$db->exec('ALTER TABLE migrations_authstack_probe RENAME TO migrations');
		}
	}

	/**
	 * The root-route exemption is matched on the path, before routing, so it has to allow
	 * for an installation served under a base path - otherwise the one route that is
	 * supposed to be able to migrate the database is refused by the gate in front of it.
	 */
	public function testTheRootExemptionAllowsForABasePath(): void
	{
		$required = self::requiredMigrations();
		$ahead = end($required) + 1;

		self::$db->exec('INSERT INTO migrations (migration) VALUES (' . $ahead . ')');

		try
		{
			$settings = ['BASE_PATH' => '/victual', 'MIGRATE_ON_ROOT_REQUEST' => 'true'];

			$root = self::send('GET', '/victual/', ['settings' => $settings]);
			self::assertNotSame(503, $root['status'], 'the root route under the base path is the exempt one');

			$api = self::send('GET', '/victual/api/user', [
				'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
				'settings' => $settings
			]);
			self::assertSame(503, $api['status'], 'every other route under it is still checked');
		}
		finally
		{
			self::$db->exec('DELETE FROM migrations WHERE migration = ' . $ahead);
		}
	}

	// --- ApiKeyAuthenticator -----------------------------------------------------------

	public function testAValidApiKeyAuthenticatesAndIsStamped(): void
	{
		$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertNotNull(self::keyLastUsed(self::$adminKey), 'a key that authenticated is stamped last_used');
	}

	public function testAnExpiredApiKeyIsRefusedAndWritesNothing(): void
	{
		$sessionsBefore = self::sessionCount();

		$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$expiredKey]]);

		self::assertSame(401, $response['status'], $response['body']);
		self::assertSame(['error_message' => 'Unauthorized'], json_decode($response['body'], true));
		self::assertNull(self::keyLastUsed(self::$expiredKey), 'a refused key must not be stamped');
		self::assertSame($sessionsBefore, self::sessionCount(), 'a refusal must not create a session');
	}

	public function testAnUnknownApiKeyIsRefused(): void
	{
		$sessionsBefore = self::sessionCount();
		$keysBefore = (int)self::$db->query('SELECT count(*) FROM api_keys')->fetchColumn();

		$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => bin2hex(random_bytes(25))]]);

		self::assertSame(401, $response['status'], $response['body']);
		self::assertSame($keysBefore, (int)self::$db->query('SELECT count(*) FROM api_keys')->fetchColumn(),
			'an unknown key must not be minted by presenting it');
		self::assertSame($sessionsBefore, self::sessionCount());
	}

	/**
	 * A key of a type the header path does not accept. USER_ISSUED_KEY_TYPES is the whole
	 * of what a header key may be; a label worker's credential is a credential for the
	 * worker routes and nothing else.
	 */
	public function testAnApiKeyOfTheWrongTypeIsRefused(): void
	{
		$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$labelWorkerKey]]);

		self::assertSame(401, $response['status'], $response['body']);
		self::assertNull(self::keyLastUsed(self::$labelWorkerKey, ApiKeyService::API_KEY_TYPE_LABEL_WORKER));
	}

	public function testARevokedApiKeyIsRefused(): void
	{
		$response = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$revokedKey]]);

		self::assertSame(401, $response['status'], $response['body']);
	}

	/**
	 * Deleting a user does not delete their API keys (UsersService::DeleteUser() removes
	 * that row and nothing else), so an unexpired key can outlive the account it belongs
	 * to. It must not authenticate anybody: an orphaned credential with a user_id nothing
	 * answers to is the shape a "who is this" that falls back to a default would get wrong.
	 */
	public function testAKeyWhoseAccountIsGoneAuthenticatesNobody(): void
	{
		self::createUser(9630, 'authstack-departed');
		self::grantAdmin(9630);
		$key = self::issueKey(9630, ApiKeyService::API_KEY_TYPE_DEFAULT, '+30 days');

		$before = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => $key]]);
		self::assertSame(200, $before['status'], 'the key works while the account exists: ' . $before['body']);

		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9630');
		self::$db->exec('DELETE FROM users WHERE id = 9630');

		try
		{
			$after = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => $key]]);

			self::assertSame(401, $after['status'], $after['body']);
			self::assertSame(['error_message' => 'Unauthorized'], json_decode($after['body'], true));
			self::assertSame(
				0,
				(int)self::$db->query('SELECT count(*) FROM users WHERE id = 9630')->fetchColumn(),
				'the refusal did not recreate the account'
			);
		}
		finally
		{
			self::$db->prepare('DELETE FROM api_keys WHERE api_key = ?')->execute([ApiKeyService::HashKey($key)]);
		}
	}

	/**
	 * Sweep finding S11: a key in the query string is not a credential. Query strings reach
	 * access logs, referrer headers and browser history, which is why the header is the only
	 * place a user-issued key is read from - and why the calendar feed's key is a separate,
	 * separately-scoped type rather than the same key relaxed.
	 */
	public function testAnApiKeyInTheQueryStringDoesNotAuthenticate(): void
	{
		foreach (['api_key', 'secret', 'apikey', 'key'] as $parameter)
		{
			$response = self::send('GET', '/api/user?' . $parameter . '=' . self::$adminKey);

			self::assertSame(401, $response['status'],
				"a valid key passed as ?$parameter must not authenticate: " . $response['body']);
		}
	}

	/**
	 * The one query-string credential there is, and the negative control beside it: the
	 * calendar feed accepts its own key type on its own route, because a calendar
	 * application subscribing to an .ics URL cannot set a header - and a regular key on the
	 * same route still does not work.
	 */
	public function testTheCalendarSecretWorksOnItsOwnRouteAndOnlyWithItsOwnKeyType(): void
	{
		$feed = self::send('GET', '/api/calendar/ical?secret=' . self::$calendarKey);
		self::assertSame(200, $feed['status'], $feed['body']);
		self::assertStringContainsString('BEGIN:VCALENDAR', $feed['body']);

		$wrongType = self::send('GET', '/api/calendar/ical?secret=' . self::$adminKey);
		self::assertSame(401, $wrongType['status'], 'a default-type key is not a calendar secret');

		$wrongRoute = self::send('GET', '/api/objects/products?secret=' . self::$calendarKey);
		self::assertSame(401, $wrongRoute['status'], 'the calendar secret is scoped to the calendar route');
	}

	/**
	 * The narrowing header can only take authority away (issue #208): a value naming a type
	 * the key does not have refuses it, and a value that is not a user-issued type at all
	 * narrows to nothing rather than widening to everything.
	 */
	public function testTheExpectedKeyTypeHeaderOnlyNarrows(): void
	{
		$matching = self::send('GET', '/api/user', ['headers' => [
			'VICTUAL-API-KEY' => self::$adminKey,
			'VICTUAL-API-KEY-TYPE' => ApiKeyService::API_KEY_TYPE_DEFAULT
		]]);
		self::assertSame(200, $matching['status'], $matching['body']);

		$other = self::send('GET', '/api/user', ['headers' => [
			'VICTUAL-API-KEY' => self::$adminKey,
			'VICTUAL-API-KEY-TYPE' => ApiKeyService::API_KEY_TYPE_MCP
		]]);
		self::assertSame(401, $other['status'], 'a default key asked to be an MCP key is refused');

		$nonsense = self::send('GET', '/api/user', ['headers' => [
			'VICTUAL-API-KEY' => self::$adminKey,
			'VICTUAL-API-KEY-TYPE' => ApiKeyService::API_KEY_TYPE_LABEL_WORKER
		]]);
		self::assertSame(401, $nonsense['status'], 'a type outside the user-issued set narrows to nothing');
	}

	/**
	 * A worker route takes a worker credential and nothing else. The branch is in front of
	 * everything else in the middleware on purpose: a printing worker is not a person, and a
	 * route it owns must not be reachable by an administrator's key, by a browser session or
	 * by a development bypass that switches authentication off for everybody else.
	 */
	public function testAWorkerRouteAcceptsAWorkerCredentialAndNothingElse(): void
	{
		$claim = ['limit' => 1];

		$anonymous = self::send('POST', '/api/labels/jobs/claim', ['body' => $claim]);
		self::assertSame(401, $anonymous['status'], $anonymous['body']);
		self::assertSame(['error_message' => 'Unauthorized'], json_decode($anonymous['body'], true));

		$asAdmin = self::send('POST', '/api/labels/jobs/claim', [
			'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
			'body' => $claim
		]);
		self::assertSame(401, $asAdmin['status'], 'an administrator key is not a worker credential');

		$asSession = self::send('POST', '/api/labels/jobs/claim', ['cookie' => self::$sessionKey, 'body' => $claim]);
		self::assertSame(401, $asSession['status'], 'a browser session is not a worker credential');

		$bypassed = self::send('POST', '/api/labels/jobs/claim', [
			'body' => $claim,
			'settings' => ['DISABLE_AUTH' => 'true']
		]);
		self::assertSame(401, $bypassed['status'], 'a development bypass does not reach a worker route');

		$asWorker = self::send('POST', '/api/labels/jobs/claim', [
			'headers' => ['VICTUAL-API-KEY' => self::$workerCredential],
			'body' => $claim
		]);
		self::assertSame(200, $asWorker['status'], $asWorker['body']);
		self::assertIsArray(json_decode($asWorker['body'], true), $asWorker['body']);
	}

	/**
	 * Pairing is the exception in that table: it is how a worker that has no credential yet
	 * gets one, so it cannot itself require one. It is public rather than worker-only, and
	 * refuses on its own terms instead.
	 */
	public function testLabelPairingIsPublicRatherThanWorkerAuthenticated(): void
	{
		$response = self::send('POST', '/api/labels/pair', ['body' => ['material' => str_repeat('0', 64)]]);

		// The refusal comes from the pairing service weighing the material, not from the
		// middleware refusing to let the request reach it
		$decoded = json_decode($response['body'], true);
		self::assertSame('material', $decoded['field'] ?? null, $response['body']);
		self::assertNotSame('Unauthorized', $decoded['error_message'] ?? null, $response['body']);
	}

	/** An API key is a credential for the API and nothing else - it cannot open a page. */
	public function testAnApiKeyDoesNotOpenARenderedPage(): void
	{
		$response = self::send('GET', '/about', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);

		self::assertSame(302, $response['status']);
		self::assertStringEndsWith('/login', self::header($response, 'Location'));
	}

	// --- SessionAuthenticator and SessionCookie ----------------------------------------

	public function testAValidSessionAuthenticates(): void
	{
		$response = self::send('GET', '/api/user', ['cookie' => self::$sessionKey]);

		self::assertSame(200, $response['status'], $response['body']);
		$decoded = json_decode($response['body'], true);
		self::assertSame('authstack-session', $decoded[0]['username'] ?? null, $response['body']);
	}

	public function testAnExpiredSessionIsRefusedAndWritesNothing(): void
	{
		$expired = self::issueSession(self::SESSION_USER_ID, '-1 hour');
		$sessionsBefore = self::sessionCount();

		$response = self::send('GET', '/api/user', ['cookie' => $expired]);

		self::assertSame(401, $response['status'], $response['body']);
		self::assertSame($sessionsBefore, self::sessionCount(), 'a refused session must not be renewed or replaced');

		$statement = self::$db->prepare('SELECT last_used FROM sessions WHERE session_key = ?');
		$statement->execute([$expired]);
		self::assertNull($statement->fetchColumn(), 'a refused session must not be stamped');
	}

	public function testAnUnknownSessionKeyIsRefused(): void
	{
		$response = self::send('GET', '/api/user', ['cookie' => bin2hex(random_bytes(25))]);

		self::assertSame(401, $response['status'], $response['body']);
		self::assertSame(['error_message' => 'Unauthorized'], json_decode($response['body'], true));
	}

	public function testNoCookieAndNoKeyIsRefused(): void
	{
		$response = self::send('GET', '/api/user');

		self::assertSame(401, $response['status'], $response['body']);
	}

	/**
	 * The sessions row survives the users row it points at, the same way an API key does,
	 * and is refused for the same reason. IsValidSession() answers true for it - the row is
	 * there and unexpired - so this is the second half of the question and not a repeat of
	 * the first.
	 */
	public function testASessionWhoseAccountIsGoneAuthenticatesNobody(): void
	{
		self::createUser(9631, 'authstack-departed-session');
		self::grantAdmin(9631);
		$key = self::issueSession(9631, '+30 days');

		$before = self::send('GET', '/api/user', ['cookie' => $key]);
		self::assertSame(200, $before['status'], 'the session works while the account exists: ' . $before['body']);

		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9631');
		self::$db->exec('DELETE FROM users WHERE id = 9631');

		try
		{
			$after = self::send('GET', '/api/user', ['cookie' => $key]);

			self::assertSame(401, $after['status'], $after['body']);
			self::assertSame(
				0,
				(int)self::$db->query('SELECT count(*) FROM users WHERE id = 9631')->fetchColumn(),
				'the refusal did not recreate the account'
			);
		}
		finally
		{
			self::$db->exec('DELETE FROM sessions WHERE user_id = 9631');
		}
	}

	/**
	 * ADR-0007: no authentication state lives in process memory between requests. A session
	 * created after the code started serving is honoured on its first request, and one
	 * deleted underneath a caller stops working on the next one - both of which are only
	 * true if the row is read every time. The last_used stamp advancing is the same fact
	 * from the other side.
	 */
	public function testEverySessionDecisionIsReadFromTheDatabase(): void
	{
		$key = self::issueSession(self::SESSION_USER_ID, '+30 days');

		$first = self::send('GET', '/api/user', ['cookie' => $key]);
		self::assertSame(200, $first['status'], 'a session created just now authenticates: ' . $first['body']);

		$statement = self::$db->prepare('SELECT last_used FROM sessions WHERE session_key = ?');
		$statement->execute([$key]);
		self::assertNotNull($statement->fetchColumn(), 'the session row was read and stamped by the request');

		// Revoked in the database alone - nothing tells the application
		self::$db->prepare('DELETE FROM sessions WHERE session_key = ?')->execute([$key]);

		$second = self::send('GET', '/api/user', ['cookie' => $key]);
		self::assertSame(401, $second['status'], 'a session deleted in the database stops working immediately');
	}

	/**
	 * Logging out removes the server-side session, so the key stops working (sweep finding
	 * S19 is the other half: the cookie is expired in the browser too, which the CLI SAPI
	 * cannot observe - see the hand-back).
	 */
	public function testLogoutDeletesTheSessionRow(): void
	{
		$key = self::issueSession(self::SESSION_USER_ID, '+30 days');

		$response = self::send('POST', '/logout', ['cookie' => $key]);

		self::assertSame(302, $response['status'], $response['body']);

		$statement = self::$db->prepare('SELECT count(*) FROM sessions WHERE session_key = ?');
		$statement->execute([$key]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'the session row is gone');

		$afterwards = self::send('GET', '/api/user', ['cookie' => $key]);
		self::assertSame(401, $afterwards['status'], 'the key that was logged out no longer authenticates');
	}

	/** Logging out twice is a thing people do, and the second time is not an error. */
	public function testLogoutWithoutASessionCookieIsRefusedRatherThanFailing(): void
	{
		$response = self::send('POST', '/logout');

		self::assertSame(302, $response['status']);
		self::assertStringEndsWith('/login', self::header($response, 'Location'));
	}

	/**
	 * SessionCookie::IsHttpsRequest() decides the cookie's Secure flag and is also what
	 * BaseAuthMiddleware compares an Origin against, so its reading of X-Forwarded-Proto is
	 * worth pinning on its own. It is a pure function of $_SERVER, so it needs no request.
	 *
	 * The header is matched exactly rather than by substring: a value merely containing
	 * "https" is not a client that used HTTPS.
	 *
	 * @param array<string, string> $server
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('httpsDetectionCases')]
	public function testHttpsDetectionReadsTheForwardedSchemeExactly(array $server, bool $expected, string $why): void
	{
		$saved = $_SERVER;

		try
		{
			unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
			$_SERVER = array_merge($_SERVER, $server);

			self::assertSame($expected, SessionCookie::IsHttpsRequest(), $why);
		}
		finally
		{
			$_SERVER = $saved;
		}
	}

	public static function httpsDetectionCases(): array
	{
		return [
			'nothing set' => [[], false, 'a plain request is not HTTPS'],
			'HTTPS on' => [['HTTPS' => 'on'], true, 'the server variable the web server sets'],
			'HTTPS off' => [['HTTPS' => 'off'], false, 'IIS sets the variable to "off" rather than unsetting it'],
			'HTTPS empty' => [['HTTPS' => ''], false, 'an empty value is not HTTPS'],
			'forwarded https' => [['HTTP_X_FORWARDED_PROTO' => 'https'], true, 'the reverse proxy deployment this fork targets'],
			'forwarded http' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false, 'the proxy said the client used plain HTTP'],
			'forwarded chain' => [['HTTP_X_FORWARDED_PROTO' => 'https, http'], true, 'the first entry is the scheme the client used'],
			'forwarded chain, client on http' => [['HTTP_X_FORWARDED_PROTO' => 'http, https'], false, 'the first entry is the scheme the client used'],
			'forwarded cased' => [['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true, 'the header is not case sensitive'],
			'forwarded lookalike' => [['HTTP_X_FORWARDED_PROTO' => 'httpsx'], false, 'matched exactly, not by substring'],
			'forwarded http with HTTPS on' => [['HTTP_X_FORWARDED_PROTO' => 'http', 'HTTPS' => 'on'], true, 'the connection to this process really is TLS']
		];
	}

	// --- BaseAuthMiddleware: the shared refusal path -----------------------------------

	/**
	 * An API route and a page route get different refusals, and the API one carries a body:
	 * a bodyless 401 is something a client has to guess at, and nothing downstream of the
	 * short circuit runs to supply one.
	 */
	public function testTheRefusalDiffersBetweenApiRoutesAndPages(): void
	{
		$api = self::send('GET', '/api/objects/products');
		self::assertSame(401, $api['status']);
		self::assertSame(['error_message' => 'Unauthorized'], json_decode($api['body'], true));
		self::assertStringContainsString('application/json', self::header($api, 'Content-Type'));

		$page = self::send('GET', '/about');
		self::assertSame(302, $page['status']);
		self::assertStringEndsWith('/login', self::header($page, 'Location'));
		self::assertSame('', $page['body'], 'a redirect carries no body');
	}

	/**
	 * A refusal says nothing about whether the account exists. Asserted across the three
	 * credentials that could leak it: a key belonging to a real user but expired, a key
	 * belonging to nobody, and a session key belonging to nobody.
	 */
	public function testARefusalDoesNotRevealWhetherAnAccountExists(): void
	{
		$expired = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$expiredKey]]);
		$unknown = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => bin2hex(random_bytes(25))]]);
		$session = self::send('GET', '/api/user', ['cookie' => bin2hex(random_bytes(25))]);

		self::assertSame($expired['status'], $unknown['status']);
		self::assertSame($expired['body'], $unknown['body']);
		self::assertSame($expired['status'], $session['status']);
		self::assertSame($expired['body'], $session['body']);

		foreach ([$expired, $unknown, $session] as $response)
		{
			self::assertStringNotContainsString('authstack-admin', $response['body']);
			self::assertStringNotContainsString('authstack-session', $response['body']);
		}
	}

	/**
	 * The modes that fix a user up front. DISABLE_AUTH is the one an operator can turn on
	 * by mistake, so what it does is worth pinning: everybody becomes the default user - the
	 * account with the lowest id, normally the first administrator - with no credential at
	 * all. It is a whole-instance switch, which is why the worker routes above opt out of it.
	 */
	public function testDisablingAuthenticationMakesEveryCallerTheDefaultUser(): void
	{
		$response = self::send('GET', '/api/user', ['settings' => ['DISABLE_AUTH' => 'true']]);

		self::assertSame(200, $response['status'], $response['body']);
		$decoded = json_decode($response['body'], true);
		self::assertSame('admin', $decoded[0]['username'] ?? null, $response['body']);

		// And a page too - the bypass is not scoped to the API
		$page = self::send('GET', '/about', ['settings' => ['DISABLE_AUTH' => 'true']]);
		self::assertSame(200, $page['status']);
	}

	/**
	 * The root route is public but not anonymous-only: it chooses the entry page by what the
	 * caller may view, so it identifies them when it can and simply does not when it cannot.
	 * Both have to work without a 401, because the page a login lands on is this one.
	 */
	public function testTheRootRouteIdentifiesTheCallerWhenItCanAndServesAnyway(): void
	{
		// A caller who may not view the configured entry page, so that "who is this" has a
		// visible consequence: an identified one is sent somewhere they may go
		self::createUser(9615, 'authstack-root-restricted');
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) '
			. "SELECT 9615, id FROM permission_hierarchy WHERE name = 'RECIPES_VIEW'");
		$statement->execute();
		$restricted = self::issueSession(9615, '+30 days');

		try
		{
			$anonymous = self::send('GET', '/');
			self::assertSame(302, $anonymous['status'], 'an unidentified caller is still served');
			self::assertStringEndsWith('/stockoverview', self::header($anonymous, 'Location'),
				'nobody in particular is routed by feature flag alone');

			$identified = self::send('GET', '/', ['cookie' => $restricted]);
			self::assertSame(302, $identified['status'], $identified['body']);
			self::assertStringEndsWith('/about', self::header($identified, 'Location'),
				'the caller was identified, so the entry page was chosen by what they may view');
		}
		finally
		{
			self::$db->exec('DELETE FROM sessions WHERE user_id = 9615');
			self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9615');
			self::$db->exec('DELETE FROM users WHERE id = 9615');
		}
	}

	/**
	 * The port is part of an origin. An instance served on one port must not accept a write
	 * from a page served by another one on the same host, which is a real deployment shape
	 * (a second application on the same machine) rather than a contrived one.
	 */
	public function testThePortIsPartOfTheOriginComparison(): void
	{
		$sameHostOtherPort = self::send('POST', '/api/objects/locations', [
			'authority' => 'localhost:8080',
			'cookie' => self::$sessionKey,
			'headers' => ['Origin' => 'http://localhost'],
			'body' => ['name' => 'authstack-other-port']
		]);

		self::assertSame(403, $sameHostOtherPort['status'], $sameHostOtherPort['body']);
		self::assertSame(
			0,
			(int)self::$db->query("SELECT count(*) FROM locations WHERE name = 'authstack-other-port'")->fetchColumn()
		);

		$matching = self::send('POST', '/api/objects/locations', [
			'authority' => 'localhost:8080',
			'cookie' => self::$sessionKey,
			'headers' => ['Origin' => 'http://localhost:8080'],
			'body' => ['name' => 'authstack-same-port']
		]);

		self::assertSame(200, $matching['status'], $matching['body']);
	}

	/**
	 * Issue #208's exception list: a read-only key may GET, except for the inherited GET
	 * routes that change something. The sharing-link route creates the caller's calendar key
	 * the first time it is asked for, so it is a write wearing a GET.
	 */
	public function testAReadOnlyKeyMayNotCallAGetRouteThatWrites(): void
	{
		$key = self::issueKey(self::ADMIN_USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT, '+30 days');
		self::$db->prepare('UPDATE api_keys SET read_only = 1 WHERE api_key = ?')
			->execute([ApiKeyService::HashKey($key)]);

		$keysBefore = (int)self::$db->query('SELECT count(*) FROM api_keys')->fetchColumn();

		$response = self::send('GET', '/api/calendar/ical/sharing-link', ['headers' => ['VICTUAL-API-KEY' => $key]]);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertStringContainsString('read-only', $response['body']);
		self::assertSame(
			$keysBefore,
			(int)self::$db->query('SELECT count(*) FROM api_keys')->fetchColumn(),
			'the refusal must not have minted the calendar key the route would have created'
		);
	}

	/** The login form is public: it has to be reachable by somebody who cannot authenticate. */
	public function testTheLoginPageIsPublic(): void
	{
		$response = self::send('GET', '/login');

		self::assertSame(200, $response['status']);
	}

	/**
	 * Sweep finding S8. A write authenticated by a credential the browser attaches on its
	 * own, arriving from another origin, is a forged request; the same write with an API key
	 * is not, because a key has to be put in a header deliberately.
	 */
	public function testACrossOriginSessionWriteIsRefusedAndAnApiKeyWriteIsNot(): void
	{
		$before = (int)self::$db->query("SELECT count(*) FROM locations WHERE name = 'authstack-cross-origin'")->fetchColumn();

		$forged = self::send('POST', '/api/objects/locations', [
			'cookie' => self::$sessionKey,
			'headers' => ['Origin' => 'https://evil.example'],
			'body' => ['name' => 'authstack-cross-origin']
		]);

		self::assertSame(403, $forged['status'], $forged['body']);
		self::assertStringContainsString('Cross-origin request refused', $forged['body']);
		self::assertSame(
			$before,
			(int)self::$db->query("SELECT count(*) FROM locations WHERE name = 'authstack-cross-origin'")->fetchColumn(),
			'a refused cross-origin write must not have written'
		);

		$withKey = self::send('POST', '/api/objects/locations', [
			'headers' => ['VICTUAL-API-KEY' => self::$adminKey, 'Origin' => 'https://evil.example'],
			'body' => ['name' => 'authstack-cross-origin']
		]);

		self::assertSame(200, $withKey['status'], $withKey['body']);
		self::assertSame(
			$before + 1,
			(int)self::$db->query("SELECT count(*) FROM locations WHERE name = 'authstack-cross-origin'")->fetchColumn()
		);
	}

	/**
	 * The rule is "a present Origin that is not ours", not "an Origin". An absent one is
	 * allowed, because a script driving the API with a session cookie sends none; the opaque
	 * literal `null` is a refusal, because something sent it and it is not us (found in
	 * review of PR #68); and Referer is consulted only when Origin is missing.
	 *
	 * @param array<string, string> $headers
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('originCases')]
	public function testTheOriginRuleRefusesOnlyAnOriginThatIsNotOurs(array $headers, bool $refused, string $why): void
	{
		$name = 'authstack-origin-' . bin2hex(random_bytes(4));

		$response = self::send('POST', '/api/objects/locations', [
			'cookie' => self::$sessionKey,
			'headers' => $headers,
			'body' => ['name' => $name]
		]);

		$statement = self::$db->prepare('SELECT count(*) FROM locations WHERE name = ?');
		$statement->execute([$name]);
		$written = (int)$statement->fetchColumn();

		if ($refused)
		{
			self::assertSame(403, $response['status'], $why . ': ' . $response['body']);
			self::assertSame(0, $written, $why . ': the refusal must not have written');
		}
		else
		{
			self::assertSame(200, $response['status'], $why . ': ' . $response['body']);
			self::assertSame(1, $written, $why . ': the accepted write must have written');
		}
	}

	public static function originCases(): array
	{
		return [
			'no header at all' => [[], false, 'a command-line client sends no Origin'],
			'our own origin' => [['Origin' => self::OWN_ORIGIN], false, 'a same-origin write from the browser'],
			'another origin' => [['Origin' => 'https://evil.example'], true, 'the forgery the check exists for'],
			'the opaque null origin' => [['Origin' => 'null'], true, 'a sandboxed iframe or data: document, and not us'],
			'an unparseable origin' => [['Origin' => 'not a url'], true, 'something sent it and it is not an origin'],
			'referer from elsewhere' => [['Referer' => 'https://evil.example/page'], true, 'Referer covers a browser that sends only the older header'],
			'referer from here' => [['Referer' => self::OWN_ORIGIN . '/stockoverview'], false, 'a same-origin page'],
			'our origin with a foreign referer' => [['Origin' => self::OWN_ORIGIN, 'Referer' => 'https://evil.example/page'], false, 'Origin is read first and alone when it is there']
		];
	}

	/** A GET is never a forgery worth refusing, whatever origin it claims. */
	public function testACrossOriginReadWithASessionIsAllowed(): void
	{
		$response = self::send('GET', '/api/user', [
			'cookie' => self::$sessionKey,
			'headers' => ['Origin' => 'https://evil.example']
		]);

		self::assertSame(200, $response['status'], $response['body']);
	}

	/**
	 * Sweep finding S12's second half. An account flagged to change its password gets the
	 * API refused except for the three calls the change itself needs, and a rendered page
	 * redirected to its own edit form - so a planted second administrator cannot be created
	 * by whoever logged in with the known password first.
	 */
	public function testAnAccountThatMustChangeItsPasswordIsHeldToTheAllowlist(): void
	{
		self::$db->exec('UPDATE users SET must_change_password = 1 WHERE id = ' . self::ADMIN_USER_ID);

		try
		{
			$blocked = self::send('POST', '/api/users', [
				'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
				'body' => ['username' => 'authstack-planted', 'password' => 'whatever']
			]);

			self::assertSame(403, $blocked['status'], $blocked['body']);
			self::assertStringContainsString('must change its password', $blocked['body']);
			self::assertSame(
				0,
				(int)self::$db->query("SELECT count(*) FROM users WHERE username = 'authstack-planted'")->fetchColumn(),
				'the refusal must not have created the account'
			);

			$allowed = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);
			self::assertSame(200, $allowed['status'], '"who am I" is how a script learns the id to PUT to');

			// Polled by every rendered page, the forced one included; refusing it only fills
			// the console
			$polled = self::send('GET', '/api/system/db-changed-time', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey]]);
			self::assertSame(200, $polled['status'], $polled['body']);

			// OwnAccountOnly: the parameterised allowlist entry must name the caller, because
			// the flag is about this account's credential and editing somebody else's is not
			// how it gets resolved
			$otherAccount = self::send('PUT', '/api/users/' . self::SESSION_USER_ID, [
				'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
				'body' => ['username' => 'authstack-session']
			]);
			self::assertSame(403, $otherAccount['status'], $otherAccount['body']);

			// The one route the allowlist leaves open has to actually change the password:
			// otherwise it is a way to rename the account without the change it exists for
			$renameOnly = self::send('PUT', '/api/users/' . self::ADMIN_USER_ID, [
				'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
				'body' => ['username' => 'authstack-renamed']
			]);
			self::assertSame(400, $renameOnly['status'],
				'reached the controller, which refused a save that changes no password: ' . $renameOnly['body']);
			self::assertSame(
				'authstack-admin',
				self::$db->query('SELECT username FROM users WHERE id = ' . self::ADMIN_USER_ID)->fetchColumn(),
				'the refusal must not have renamed the account'
			);

			// And the change itself lifts the flag, which is what puts the account back in
			// possession of its own API
			$changed = self::send('PUT', '/api/users/' . self::ADMIN_USER_ID, [
				'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
				'body' => [
					'username' => 'authstack-admin',
					'password' => 'a password the operator chose',
					'current_password' => self::PASSWORD
				]
			]);
			self::assertSame(204, $changed['status'], $changed['body']);
			self::assertSame(
				0,
				(int)self::$db->query('SELECT must_change_password FROM users WHERE id = ' . self::ADMIN_USER_ID)->fetchColumn(),
				'changing the password is what clears the flag'
			);

			$nowAllowed = self::send('POST', '/api/users', [
				'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
				'body' => ['username' => 'authstack-after-change', 'password' => 'another password']
			]);
			self::assertSame(204, $nowAllowed['status'], 'the whole API is back: ' . $nowAllowed['body']);
			self::assertSame(
				1,
				(int)self::$db->query("SELECT count(*) FROM users WHERE username = 'authstack-after-change'")->fetchColumn(),
				'and the write it was refused before now lands'
			);
			self::$db->exec("DELETE FROM users WHERE username = 'authstack-after-change'");

			$page = self::send('GET', '/about', ['headers' => ['VICTUAL-API-KEY' => self::$adminKey], 'cookie' => self::$sessionKey]);
			self::assertSame(200, $page['status'], 'the session user is not the flagged one');
		}
		finally
		{
			self::$db->exec('UPDATE users SET must_change_password = 0 WHERE id = ' . self::ADMIN_USER_ID);

			// The password this test changed, put back, so that later cases keep the one the
			// fixture documents
			self::$db->prepare('UPDATE users SET password = ? WHERE id = ' . self::ADMIN_USER_ID)
				->execute([password_hash(self::PASSWORD, PASSWORD_ARGON2ID)]);
		}
	}

	/** The page half of the same rule: a redirect to the account's own form, with logout left open. */
	public function testAFlaggedAccountIsRedirectedToItsOwnEditFormOnPages(): void
	{
		self::$db->exec('UPDATE users SET must_change_password = 1 WHERE id = ' . self::SESSION_USER_ID);

		try
		{
			$page = self::send('GET', '/about', ['cookie' => self::$sessionKey]);

			self::assertSame(302, $page['status']);
			self::assertStringEndsWith('/user/' . self::SESSION_USER_ID . '?changepw=true', self::header($page, 'Location'));

			// Trapping somebody on one page with no way off it is a worse answer than the
			// problem, so logging out stays reachable
			$logout = self::send('POST', '/logout', ['cookie' => self::$sessionKey]);
			self::assertSame(302, $logout['status']);
			self::assertStringEndsWith('/', self::header($logout, 'Location'));
		}
		finally
		{
			self::$db->exec('UPDATE users SET must_change_password = 0 WHERE id = ' . self::SESSION_USER_ID);
			self::$sessionKey = self::issueSession(self::SESSION_USER_ID, '+30 days');
		}
	}

	/** A read-only key may read and may not write, wherever the route lives (issue #208). */
	public function testAReadOnlyKeyMayNotWrite(): void
	{
		$key = self::issueKey(self::ADMIN_USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT, '+30 days');
		self::$db->prepare('UPDATE api_keys SET read_only = 1 WHERE api_key = ?')
			->execute([ApiKeyService::HashKey($key)]);

		$read = self::send('GET', '/api/user', ['headers' => ['VICTUAL-API-KEY' => $key]]);
		self::assertSame(200, $read['status'], $read['body']);

		$write = self::send('POST', '/api/objects/locations', [
			'headers' => ['VICTUAL-API-KEY' => $key],
			'body' => ['name' => 'authstack-read-only']
		]);

		self::assertSame(403, $write['status'], $write['body']);
		self::assertStringContainsString('read-only', $write['body']);
		self::assertSame(
			0,
			(int)self::$db->query("SELECT count(*) FROM locations WHERE name = 'authstack-read-only'")->fetchColumn(),
			'the refusal must not have written'
		);
	}

	// --- CorsMiddleware ----------------------------------------------------------------

	/**
	 * A preflight carries no credentials by construction, so it is answered 204 whether or
	 * not the origin is allowed - authenticating it could only ever refuse a request that
	 * was asking permission rather than doing anything.
	 */
	public function testThePreflightIsAnsweredWithoutCredentials(): void
	{
		$response = self::send('OPTIONS', '/api/objects/products', [
			'headers' => ['Origin' => self::ALLOWED_ORIGIN],
			'settings' => ['CORS_ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN]
		]);

		self::assertSame(204, $response['status']);
		self::assertSame(self::ALLOWED_ORIGIN, self::header($response, 'Access-Control-Allow-Origin'));
		self::assertStringContainsString('POST', self::header($response, 'Access-Control-Allow-Methods'));
		self::assertStringContainsString('VICTUAL-API-KEY', self::header($response, 'Access-Control-Allow-Headers'));
		self::assertSame('600', self::header($response, 'Access-Control-Max-Age'));
		self::assertSame('Origin', self::header($response, 'Vary'));
	}

	/**
	 * A disallowed origin still gets its 204 and simply gets no CORS headers with it, which
	 * is what makes the browser refuse the real request. Vary stays, because the answer
	 * depends on the Origin and a cache that is not told so serves one origin's answer to
	 * another.
	 */
	public function testAPreflightFromADisallowedOriginGetsNoCorsHeaders(): void
	{
		$response = self::send('OPTIONS', '/api/objects/products', [
			'headers' => ['Origin' => 'https://evil.example'],
			'settings' => ['CORS_ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN]
		]);

		self::assertSame(204, $response['status']);
		self::assertSame('', self::header($response, 'Access-Control-Allow-Origin'));
		self::assertSame('Origin', self::header($response, 'Vary'));
	}

	public function testAnAllowedOriginGetsTheHeadersOnANormalResponse(): void
	{
		$response = self::send('GET', '/api/user', [
			'headers' => ['VICTUAL-API-KEY' => self::$adminKey, 'Origin' => self::ALLOWED_ORIGIN],
			'settings' => ['CORS_ALLOWED_ORIGINS' => 'https://other.example,' . self::ALLOWED_ORIGIN]
		]);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(self::ALLOWED_ORIGIN, self::header($response, 'Access-Control-Allow-Origin'));
	}

	/**
	 * The list is exact-match and the entries are trimmed; an empty entry is dropped rather
	 * than matching an empty Origin.
	 */
	public function testTheAllowListIsExactMatchAndTrimmed(): void
	{
		$settings = ['CORS_ALLOWED_ORIGINS' => ' https://other.example , , ' . self::ALLOWED_ORIGIN . ' '];

		$trimmed = self::send('GET', '/api/user', [
			'headers' => ['VICTUAL-API-KEY' => self::$adminKey, 'Origin' => 'https://other.example'],
			'settings' => $settings
		]);
		self::assertSame('https://other.example', self::header($trimmed, 'Access-Control-Allow-Origin'));

		// A prefix of an allowed origin is a different origin
		$prefix = self::send('GET', '/api/user', [
			'headers' => ['VICTUAL-API-KEY' => self::$adminKey, 'Origin' => self::ALLOWED_ORIGIN . '.evil.example'],
			'settings' => $settings
		]);
		self::assertSame('', self::header($prefix, 'Access-Control-Allow-Origin'));
		self::assertSame('Origin', self::header($prefix, 'Vary'));
	}

	/**
	 * Sweep finding S21: the allow-list is empty by default, so an installation that has
	 * not configured CORS sends no CORS headers at all - not the unconditional wildcard that
	 * used to sit on an API authenticating with a key.
	 */
	public function testAnUnconfiguredInstallationSendsNoCorsHeadersAtAll(): void
	{
		$response = self::send('GET', '/api/user', [
			'headers' => ['VICTUAL-API-KEY' => self::$adminKey, 'Origin' => self::ALLOWED_ORIGIN]
		]);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('', self::header($response, 'Access-Control-Allow-Origin'));
		self::assertSame('', self::header($response, 'Vary'), 'nothing varies when nothing is configured');
	}

	/**
	 * The middleware is outermost so that a 401 carries CORS headers like any other
	 * response; before that move an unauthenticated API call was answered with a bodyless,
	 * untyped 401 carrying none, which a browser reports as a CORS failure rather than as a
	 * refusal.
	 */
	public function testARefusalStillCarriesTheCorsHeaders(): void
	{
		$response = self::send('GET', '/api/user', [
			'headers' => ['Origin' => self::ALLOWED_ORIGIN],
			'settings' => ['CORS_ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN]
		]);

		self::assertSame(401, $response['status']);
		self::assertSame(self::ALLOWED_ORIGIN, self::header($response, 'Access-Control-Allow-Origin'));
	}

	/**
	 * Out here the middleware also wraps the error middleware, so a 404 and a 405 are typed
	 * and get their CORS headers like any other response - a browser shown a bare 404 with
	 * no Access-Control-Allow-Origin reports a CORS failure instead of the missing route.
	 */
	public function testAnErrorResponseCarriesTheCorsHeadersToo(): void
	{
		$settings = ['CORS_ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN];
		$headers = ['VICTUAL-API-KEY' => self::$adminKey, 'Origin' => self::ALLOWED_ORIGIN];

		$notFound = self::send('GET', '/api/authstack-no-such-route', ['headers' => $headers, 'settings' => $settings]);
		self::assertSame(404, $notFound['status'], $notFound['body']);
		self::assertSame(self::ALLOWED_ORIGIN, self::header($notFound, 'Access-Control-Allow-Origin'));
		self::assertStringContainsString('application/json', self::header($notFound, 'Content-Type'));

		$wrongMethod = self::send('DELETE', '/api/user', ['headers' => $headers, 'settings' => $settings]);
		self::assertSame(405, $wrongMethod['status'], $wrongMethod['body']);
		self::assertSame(self::ALLOWED_ORIGIN, self::header($wrongMethod, 'Access-Control-Allow-Origin'));
	}

	/** It applies to API paths only: a rendered page is same-origin by construction. */
	public function testARenderedPageNeverGetsCorsHeaders(): void
	{
		$response = self::send('GET', '/login', [
			'headers' => ['Origin' => self::ALLOWED_ORIGIN],
			'settings' => ['CORS_ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN]
		]);

		self::assertSame(200, $response['status']);
		self::assertSame('', self::header($response, 'Access-Control-Allow-Origin'));
		self::assertSame('', self::header($response, 'Vary'));
	}

	// --- PasswordLogin -----------------------------------------------------------------

	/** @return array{status: int, headers: array, body: string} */
	private static function login(string $username, string $password, array $extra = []): array
	{
		return self::send('POST', '/login', ['body' => array_merge([
			'username' => $username,
			'password' => $password
		], $extra)]);
	}

	public function testACorrectPasswordCreatesASession(): void
	{
		$own = self::$db->prepare('SELECT count(*) FROM sessions WHERE user_id = ?');
		$own->execute([self::PASSWORD_USER_ID]);
		$before = (int)$own->fetchColumn();

		$response = self::login(self::PASSWORD_USER, self::PASSWORD);

		self::assertSame(302, $response['status'], $response['body']);
		self::assertStringEndsWith('/', self::header($response, 'Location'));
		self::assertStringNotContainsString('invalid', self::header($response, 'Location'));

		$own->execute([self::PASSWORD_USER_ID]);
		self::assertSame($before + 1, (int)$own->fetchColumn(), 'the login created exactly one session, for this account');

		$statement = self::$db->prepare('SELECT session_key, expires FROM sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
		$statement->execute([self::PASSWORD_USER_ID]);
		$session = $statement->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($session, 'the session belongs to the account that logged in');

		// And it is a working credential, which is what the login was for
		$authenticated = self::send('GET', '/api/user', ['cookie' => $session['session_key']]);
		self::assertSame(200, $authenticated['status'], $authenticated['body']);
	}

	/**
	 * "Stay logged in" is a longer-lived session row, not a longer-lived cookie alone: the
	 * server remains the authority on validity, so a browser that keeps the cookie past the
	 * row's expiry is refused. The two lifetimes are 30 days by default and
	 * SESSION_STAY_LOGGED_IN_DAYS when the box was ticked.
	 */
	public function testStayingLoggedInLengthensTheSessionRowItself(): void
	{
		$expiryOfNewestSession = function (): int
		{
			$statement = self::$db->prepare('SELECT expires FROM sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
			$statement->execute([self::PASSWORD_USER_ID]);

			return strtotime($statement->fetchColumn());
		};

		self::login(self::PASSWORD_USER, self::PASSWORD);
		$ordinary = $expiryOfNewestSession();

		self::login(self::PASSWORD_USER, self::PASSWORD, ['stay_logged_in' => 'on']);
		$remembered = $expiryOfNewestSession();

		self::assertGreaterThan($ordinary, $remembered, 'the remembered session outlives the ordinary one');
		self::assertEqualsWithDelta(
			time() + 90 * 86400,
			$remembered,
			300,
			'and lasts the configured SESSION_STAY_LOGGED_IN_DAYS'
		);
	}

	public function testAWrongPasswordIsRefusedAndWritesNoSession(): void
	{
		$before = self::sessionCount();

		$response = self::login(self::PASSWORD_USER, 'not the password');

		self::assertSame(302, $response['status']);
		self::assertStringEndsWith('/login?invalid=true', self::header($response, 'Location'));
		self::assertSame($before, self::sessionCount(), 'a refused login must not create a session');
		self::assertGreaterThan(0, self::loginAttemptCount(self::PASSWORD_USER), 'the failure was counted');

		self::$db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([self::PASSWORD_USER]);
	}

	/**
	 * An unknown username is answered exactly as a wrong password is, and costs the same
	 * work (sweep finding S19's timing half - DUMMY_PASSWORD_HASH). The response is the
	 * assertion available here; the constant is what makes the timing match.
	 */
	public function testAnUnknownUsernameIsIndistinguishableFromAWrongPassword(): void
	{
		$unknown = self::login('authstack-no-such-account', 'not the password');
		$wrong = self::login(self::PASSWORD_USER, 'not the password');

		self::assertSame($wrong['status'], $unknown['status']);
		self::assertSame(self::header($wrong, 'Location'), self::header($unknown, 'Location'));
		self::assertSame($wrong['body'], $unknown['body']);

		// The attempt is counted for a username that does not exist too, so that guessing
		// at names is throttled the same way guessing at passwords is
		self::assertSame(1, self::loginAttemptCount('authstack-no-such-account'));

		self::$db->exec("DELETE FROM login_attempts WHERE username IN ('authstack-no-such-account', '" . self::PASSWORD_USER . "')");
	}

	/**
	 * The boundary below the throttle: a submission with nothing in it is refused before
	 * anything is counted, so an empty form cannot be used to lock an account out.
	 */
	public function testAnEmptySubmissionIsRefusedWithoutCountingAnAttempt(): void
	{
		foreach ([['', self::PASSWORD], [self::PASSWORD_USER, ''], ['', '']] as [$username, $password])
		{
			$response = self::login($username, $password);

			self::assertSame(302, $response['status']);
			self::assertStringEndsWith('/login?invalid=true', self::header($response, 'Location'));
		}

		self::assertSame(0, self::loginAttemptCount(self::PASSWORD_USER),
			'an empty password is not an attempt at the account');
		self::assertSame(0, self::loginAttemptCount(''));
	}

	/**
	 * ADR-0007's throttle: a username is locked out after the configured number of failures
	 * inside the window, and the lockout is answered exactly like a wrong password - telling
	 * a guesser they have hit the limit tells them the limit exists and roughly where it is.
	 *
	 * The counter is a table rather than anything held between requests, which is the point
	 * of the ADR: every attempt below is its own process, so a counter in process memory
	 * would be reset for free before each one.
	 */
	public function testTheThrottleLocksAUsernameOutAndSurvivesEveryProcess(): void
	{
		$username = 'authstack-throttled';
		self::createUser(9610, $username, password_hash(self::PASSWORD, PASSWORD_ARGON2ID));

		try
		{
			$limit = 3;
			$settings = ['LOGIN_THROTTLE_MAX_ATTEMPTS' => (string)$limit];

			for ($attempt = 0; $attempt < $limit; $attempt++)
			{
				$response = self::send('POST', '/login', [
					'body' => ['username' => $username, 'password' => 'wrong'],
					'settings' => $settings
				]);
				self::assertStringEndsWith('/login?invalid=true', self::header($response, 'Location'));
			}

			self::assertSame($limit, self::loginAttemptCount($username), 'each failure is a row, across processes');

			$sessionsBefore = self::sessionCount();
			$lockedOut = self::send('POST', '/login', [
				'body' => ['username' => $username, 'password' => self::PASSWORD],
				'settings' => $settings
			]);

			self::assertSame(302, $lockedOut['status']);
			self::assertStringEndsWith('/login?invalid=true', self::header($lockedOut, 'Location'),
				'the correct password is refused while the username is locked out');
			self::assertSame($sessionsBefore, self::sessionCount(), 'no session while locked out');
			self::assertSame($limit, self::loginAttemptCount($username),
				'a refusal at the gate is not itself counted');

			// A success clears the counter it earns back - this username's
			$allowed = self::send('POST', '/login', [
				'body' => ['username' => $username, 'password' => self::PASSWORD],
				'settings' => ['LOGIN_THROTTLE_MAX_ATTEMPTS' => '10']
			]);
			self::assertStringEndsWith('/', self::header($allowed, 'Location'), $allowed['body']);
			self::assertSame(0, self::loginAttemptCount($username), 'a proof of the password clears the counter');
		}
		finally
		{
			self::$db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
			self::$db->exec('DELETE FROM sessions WHERE user_id = 9610');
			self::$db->exec('DELETE FROM users WHERE id = 9610');
		}
	}

	/**
	 * A password stored under an algorithm that is no longer the default is rehashed on a
	 * successful login, so an in-place upgrade migrates its credentials as people use them.
	 * Asserted on what the stored value does - it still verifies the same password and no
	 * longer needs rehashing - rather than on the value itself, which is salted and differs
	 * every time.
	 */
	public function testASuccessfulLoginRehashesAnOutdatedStoredPassword(): void
	{
		$statement = self::$db->prepare('SELECT password FROM users WHERE id = ?');
		$statement->execute([self::LEGACY_HASH_USER_ID]);
		$before = $statement->fetchColumn();
		self::assertTrue(password_needs_rehash($before, PASSWORD_ARGON2ID), 'the fixture starts outdated');

		$response = self::login('authstack-legacy-hash', self::PASSWORD);
		self::assertStringEndsWith('/', self::header($response, 'Location'), $response['body']);

		$statement->execute([self::LEGACY_HASH_USER_ID]);
		$after = $statement->fetchColumn();

		self::assertNotSame($before, $after, 'the stored password was replaced');
		self::assertFalse(password_needs_rehash($after, PASSWORD_ARGON2ID), 'and is now current');
		self::assertTrue(password_verify(self::PASSWORD, $after), 'and still accepts the same password');

		// The account still logs in afterwards, which is the thing a rehash could break
		$again = self::login('authstack-legacy-hash', self::PASSWORD);
		self::assertStringEndsWith('/', self::header($again, 'Location'), $again['body']);
	}

	/**
	 * Sweep finding S12: logging in with the publicly known seeded password flags the
	 * account, and the flag is a column rather than a user setting precisely so that its
	 * subject cannot delete it.
	 */
	public function testLoggingInWithTheSeededPasswordFlagsTheAccount(): void
	{
		$statement = self::$db->prepare('SELECT must_change_password FROM users WHERE id = ?');
		$statement->execute([self::SEEDED_PASSWORD_USER_ID]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'the fixture starts unflagged');

		$response = self::login('authstack-seeded', 'admin');
		self::assertStringEndsWith('/', self::header($response, 'Location'), $response['body']);

		$statement->execute([self::SEEDED_PASSWORD_USER_ID]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'the account must change its password');

		self::$db->exec('UPDATE users SET must_change_password = 0 WHERE id = ' . self::SEEDED_PASSWORD_USER_ID);
	}

	/** Logging in prunes the sessions table, which nothing used to do (sweep finding S19). */
	public function testLoggingInRemovesExpiredSessions(): void
	{
		$stale = self::issueSession(self::PASSWORD_USER_ID, '-1 day');

		$response = self::login(self::PASSWORD_USER, self::PASSWORD);
		self::assertStringEndsWith('/', self::header($response, 'Location'), $response['body']);

		$statement = self::$db->prepare('SELECT count(*) FROM sessions WHERE session_key = ?');
		$statement->execute([$stale]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'the expired session was pruned');

		// And a live one was not
		$statement->execute([self::$sessionKey]);
		self::assertSame(1, (int)$statement->fetchColumn(), 'a valid session is left alone');
	}

	// --- ReverseProxyAuthMiddleware and ReverseProxyAuthenticator ----------------------

	/** The settings that put the reverse-proxy backend in charge. */
	private static function proxySettings(array $extra = []): array
	{
		return array_merge(['AUTH_CLASS' => 'Victual\\Middleware\\Auth\\ReverseProxyAuthMiddleware'], $extra);
	}

	/**
	 * USE_ENV mode, which the class documentation names as the one to prefer: the username
	 * comes from $_SERVER, which the web server populates and a client header cannot reach.
	 */
	public function testTheProxySuppliedUsernameAuthenticatesInEnvironmentMode(): void
	{
		self::createUser(self::PROXY_USER_ID, 'authstack-proxy');
		self::grantAdmin(self::PROXY_USER_ID);

		$response = self::send('GET', '/api/user', [
			'server' => ['REMOTE_USER' => 'authstack-proxy'],
			'settings' => self::proxySettings(['REVERSE_PROXY_AUTH_USE_ENV' => 'true'])
		]);

		self::assertSame(200, $response['status'], $response['body']);
		$decoded = json_decode($response['body'], true);
		self::assertSame('authstack-proxy', $decoded[0]['username'] ?? null, $response['body']);
	}

	/**
	 * The header is client-settable, so it is worth nothing unless the request demonstrably
	 * came from the proxy that sets it. Sweep finding S4: an unset trusted-proxy list
	 * refuses everything rather than trusting everything, because a header-mode deployment
	 * that has not named its proxy is not one whose header means anything.
	 *
	 * The request was refused, not failed, so both refusals answer with a 4xx rather than a
	 * 500 - 401 for the unconfigured list (nothing here can trust any address, so the
	 * caller has proven nothing), 403 for an address the list specifically does not include
	 * (the identity might be genuine; the origin is what is refused). Neither body may
	 * describe the deployment: no setting name, no header name, no configuration advice -
	 * the same rule SchemaVersionMiddleware::DatabaseUnavailable() applies to a connection
	 * failure.
	 */
	public function testTheProxyHeaderIsOnlyTrustedFromAnAddressTheDeploymentNames(): void
	{
		$usersBefore = (int)self::$db->query('SELECT count(*) FROM users')->fetchColumn();

		$ungated = self::send('GET', '/api/user', [
			'headers' => ['REMOTE_USER' => 'authstack-proxy'],
			'server' => ['REMOTE_ADDR' => '10.0.0.7'],
			'settings' => self::proxySettings()
		]);

		self::assertSame(401, $ungated['status'],
			'an unconfigured trusted-proxy list is refused, not endorsed and not a server fault: ' . $ungated['body']);
		self::assertBodyDescribesNoDeploymentDetail($ungated['body']);

		$fromElsewhere = self::send('GET', '/api/user', [
			'headers' => ['REMOTE_USER' => 'authstack-proxy'],
			'server' => ['REMOTE_ADDR' => '203.0.113.9'],
			'settings' => self::proxySettings(['REVERSE_PROXY_AUTH_TRUSTED_PROXIES' => '10.0.0.0/24'])
		]);

		self::assertSame(403, $fromElsewhere['status'],
			'an address outside the list is not the proxy: ' . $fromElsewhere['body']);
		self::assertBodyDescribesNoDeploymentDetail($fromElsewhere['body'], ['10.0.0.0/24', '203.0.113.9']);

		$fromTheProxy = self::send('GET', '/api/user', [
			'headers' => ['REMOTE_USER' => 'authstack-proxy'],
			'server' => ['REMOTE_ADDR' => '10.0.0.7'],
			'settings' => self::proxySettings(['REVERSE_PROXY_AUTH_TRUSTED_PROXIES' => '10.0.0.0/24'])
		]);

		self::assertSame(200, $fromTheProxy['status'], $fromTheProxy['body']);
		$decoded = json_decode($fromTheProxy['body'], true);
		self::assertSame('authstack-proxy', $decoded[0]['username'] ?? null, $fromTheProxy['body']);

		self::assertSame($usersBefore, (int)self::$db->query('SELECT count(*) FROM users')->fetchColumn(),
			'a refused proxy request must not create the account it named');
	}

	/**
	 * A missing or ambiguous header is a misconfigured proxy rather than an anonymous
	 * caller, and the authenticator says so instead of creating a user for it - with a 401,
	 * since none of the four proves who the request is, and a body that says only that,
	 * without naming the header or the environment variable it read.
	 */
	public function testAnAbsentOrAmbiguousProxyHeaderCreatesNobody(): void
	{
		$usersBefore = (int)self::$db->query('SELECT count(*) FROM users')->fetchColumn();
		$settings = self::proxySettings(['REVERSE_PROXY_AUTH_TRUSTED_PROXIES' => '10.0.0.0/24']);
		$server = ['REMOTE_ADDR' => '10.0.0.7'];

		$absent = self::send('GET', '/api/user', ['server' => $server, 'settings' => $settings]);
		self::assertSame(401, $absent['status'], $absent['body']);
		self::assertBodyDescribesNoDeploymentDetail($absent['body']);

		$empty = self::send('GET', '/api/user', [
			'headers' => ['REMOTE_USER' => ''],
			'server' => $server,
			'settings' => $settings
		]);
		self::assertSame(401, $empty['status'], $empty['body']);
		self::assertBodyDescribesNoDeploymentDetail($empty['body']);

		$missingEnv = self::send('GET', '/api/user', [
			'settings' => self::proxySettings(['REVERSE_PROXY_AUTH_USE_ENV' => 'true'])
		]);
		self::assertSame(401, $missingEnv['status'], $missingEnv['body']);
		self::assertBodyDescribesNoDeploymentDetail($missingEnv['body']);

		$emptyEnv = self::send('GET', '/api/user', [
			'server' => ['REMOTE_USER' => ''],
			'settings' => self::proxySettings(['REVERSE_PROXY_AUTH_USE_ENV' => 'true'])
		]);
		self::assertSame(401, $emptyEnv['status'], $emptyEnv['body']);
		self::assertBodyDescribesNoDeploymentDetail($emptyEnv['body']);

		self::assertSame($usersBefore, (int)self::$db->query('SELECT count(*) FROM users')->fetchColumn(),
			'none of the four refusals created an account');
	}

	/**
	 * Asserts a reverse-proxy refusal body names none of the settings or headers that
	 * describe this deployment: no REVERSE_PROXY_AUTH* setting, no REMOTE_USER, no
	 * TRUSTED_PROXIES, and none of the configured values the caller passes in (the
	 * trusted-proxy range, the refused address).
	 *
	 * @param string[] $values Configured values the body must not contain either
	 */
	private static function assertBodyDescribesNoDeploymentDetail(string $body, array $values = []): void
	{
		self::assertStringNotContainsString('REVERSE_PROXY_AUTH', $body, $body);
		self::assertStringNotContainsString('REMOTE_USER', $body, $body);
		self::assertStringNotContainsString('TRUSTED_PROXIES', $body, $body);

		foreach ($values as $value)
		{
			self::assertStringNotContainsString($value, $body, $body);
		}
	}

	/**
	 * A username the proxy has authenticated and Victual has never seen becomes a local
	 * account on first sight - with DEFAULT_PERMISSIONS and nothing else, which is sweep
	 * finding S5's reason for that setting no longer defaulting to ADMIN. There is no
	 * creator here to compare a grant against.
	 */
	public function testAnUnknownProxyUsernameIsCreatedWithNoPermissions(): void
	{
		$username = 'authstack-proxy-newcomer';
		$settings = self::proxySettings([
			'REVERSE_PROXY_AUTH_TRUSTED_PROXIES' => '10.0.0.0/24'
		]);

		try
		{
			$first = self::send('GET', '/api/user', [
				'headers' => ['REMOTE_USER' => $username],
				'server' => ['REMOTE_ADDR' => '10.0.0.7'],
				'settings' => $settings
			]);

			self::assertSame(200, $first['status'], $first['body']);

			$statement = self::$db->prepare('SELECT id FROM users WHERE username = ?');
			$statement->execute([$username]);
			$id = $statement->fetchColumn();
			self::assertNotFalse($id, 'the account was created');

			// Authenticated is not authorised: with no permissions the household data is
			// still closed
			$refused = self::send('GET', '/api/objects/products', [
				'headers' => ['REMOTE_USER' => $username],
				'server' => ['REMOTE_ADDR' => '10.0.0.7'],
				'settings' => $settings
			]);
			self::assertSame(403, $refused['status'], $refused['body']);

			// A second request reuses the account rather than creating another
			$second = self::send('GET', '/api/user', [
				'headers' => ['REMOTE_USER' => $username],
				'server' => ['REMOTE_ADDR' => '10.0.0.7'],
				'settings' => $settings
			]);
			self::assertSame(200, $second['status'], $second['body']);

			$statement = self::$db->prepare('SELECT count(*) FROM users WHERE username = ?');
			$statement->execute([$username]);
			self::assertSame(1, (int)$statement->fetchColumn(), 'exactly one account exists for the username');
		}
		finally
		{
			$statement = self::$db->prepare('DELETE FROM users WHERE username = ?');
			$statement->execute([$username]);
		}
	}

	/**
	 * API routes fall back to regular API key authentication first, for reverse proxy
	 * setups that bypass the proxy for them - so a key works with no proxy header present
	 * and with no trusted-proxy list configured.
	 */
	public function testAnApiKeyStillWorksUnderTheReverseProxyBackend(): void
	{
		$response = self::send('GET', '/api/user', [
			'headers' => ['VICTUAL-API-KEY' => self::$adminKey],
			'settings' => self::proxySettings()
		]);

		self::assertSame(200, $response['status'], $response['body']);
		$decoded = json_decode($response['body'], true);
		self::assertSame('authstack-admin', $decoded[0]['username'] ?? null, $response['body']);
	}

	/**
	 * There is no password here to change, so the forced-change rule is not applied when
	 * authentication is managed outside the application - it would trap the account on a
	 * form that cannot resolve it.
	 */
	public function testTheForcedPasswordChangeDoesNotApplyToProxyAuthentication(): void
	{
		self::$db->exec('UPDATE users SET must_change_password = 1 WHERE id = ' . self::PROXY_USER_ID);

		try
		{
			$response = self::send('GET', '/api/objects/products', [
				'server' => ['REMOTE_USER' => 'authstack-proxy'],
				'settings' => self::proxySettings(['REVERSE_PROXY_AUTH_USE_ENV' => 'true'])
			]);

			self::assertSame(200, $response['status'], $response['body']);
		}
		finally
		{
			self::$db->exec('UPDATE users SET must_change_password = 0 WHERE id = ' . self::PROXY_USER_ID);
		}
	}

	/**
	 * An authentication backend that delegates to a reverse proxy has no credentials of its
	 * own to check, so a login form posted to one is answered "invalid" rather than raising.
	 * It used to be an abstract static that three of five subclasses satisfied by throwing,
	 * which made a posted login form a 500 (plan 15-C1).
	 */
	public function testALoginFormPostedToTheProxyBackendIsAnsweredInvalid(): void
	{
		$before = self::sessionCount();

		$response = self::send('POST', '/login', [
			'body' => ['username' => self::PASSWORD_USER, 'password' => self::PASSWORD],
			'settings' => self::proxySettings()
		]);

		self::assertSame(302, $response['status'], $response['body']);
		self::assertStringEndsWith('/login?invalid=true', self::header($response, 'Location'));
		self::assertSame($before, self::sessionCount(),
			'a backend with no password to check creates no session for a correct one');
	}

	/**
	 * A proxy-supplied identity travels with the request the way a cookie does, so the
	 * Origin check treats it as ambient: a cross-origin write under proxy authentication is
	 * refused for the same reason a cross-origin write under a session cookie is.
	 */
	public function testAProxyIdentityIsAmbientForTheOriginCheck(): void
	{
		$name = 'authstack-proxy-cross-origin';

		$response = self::send('POST', '/api/objects/locations', [
			'headers' => ['Origin' => 'https://evil.example'],
			'server' => ['REMOTE_USER' => 'authstack-proxy'],
			'settings' => self::proxySettings(['REVERSE_PROXY_AUTH_USE_ENV' => 'true']),
			'body' => ['name' => $name]
		]);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertStringContainsString('Cross-origin request refused', $response['body']);

		$statement = self::$db->prepare('SELECT count(*) FROM locations WHERE name = ?');
		$statement->execute([$name]);
		self::assertSame(0, (int)$statement->fetchColumn(), 'the refusal must not have written');
	}
}
