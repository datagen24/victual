<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\ApiKeyService;
use Victual\Services\Database\InitialDataSeeder;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The first administrator on a fresh installation, and what an account that has to change
 * its password may do before it has - through the whole middleware stack, in production
 * mode.
 *
 * Until this, a fresh database was seeded with admin/admin (migration 0027's credential,
 * publicly known), and the forced password change (sweep finding S12, migration 0265)
 * covered rendered pages only. So whoever reached a new deployment before its operator
 * could log in and use the entire API - create a second administrator, mint API keys -
 * and none of it was undone by the password change the pages then forced. CodeRabbit
 * raised it in review of PR #211.
 *
 * What should be true now:
 *
 *   - the seeded administrator's password is VICTUAL_BOOTSTRAP_ADMIN_PASSWORD when the
 *     operator set it, and otherwise a generated one, reported once and flagged for a
 *     forced change - never "admin";
 *   - logging in with a generated password does not lift the flag (login only raises it);
 *   - a flagged account gets 403 on every API route except the three the change-password
 *     page needs, by session and by API key alike, and the page redirect is unchanged;
 *   - changing the password through the allowlisted route lifts all of it.
 *
 * Every request is its own process (tests/Pgsql/bootstrap-subprocess-helper.php) because
 * the authentication middleware defines the user's constants and PHP cannot redefine them.
 * run-tests.sh sets VICTUAL_BOOTSTRAP_ADMIN_PASSWORD for the whole suite, which is what
 * the schema this class migrates was seeded with.
 */
class BootstrapAdminTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	private const FLAGGED = 9400;
	private const CLEAR = 9401;
	private const OTHER = 9402;
	private const DEFAULT_LOGIN = 9403;
	private const CHANGES = 9404;

	private static string $flaggedApiKey;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$insert = self::$db->prepare('INSERT INTO users(id, username, password, must_change_password) VALUES (?, ?, ?, ?)');
		$insert->execute([self::FLAGGED, 'bootstrap-flagged', password_hash('flagged-password-1', PASSWORD_ARGON2ID), 1]);
		$insert->execute([self::CLEAR, 'bootstrap-clear', password_hash('clear-password-1', PASSWORD_ARGON2ID), 0]);
		$insert->execute([self::OTHER, 'bootstrap-other', password_hash('other-password-1', PASSWORD_ARGON2ID), 0]);
		$insert->execute([self::DEFAULT_LOGIN, 'bootstrap-default', password_hash('admin', PASSWORD_ARGON2ID), 0]);
		$insert->execute([self::CHANGES, 'bootstrap-changes', password_hash('before-change-1', PASSWORD_ARGON2ID), 1]);

		$role = self::$db->prepare('INSERT INTO user_roles(user_id, role_id) SELECT ?, id FROM roles WHERE code = ?');
		foreach ([self::FLAGGED, self::CLEAR, self::OTHER, self::DEFAULT_LOGIN, self::CHANGES] as $userId)
		{
			$role->execute([$userId, 'ADMIN']);
		}

		self::$db->exec("INSERT INTO sessions(session_key, user_id, expires) VALUES
			('bootstrap-flagged', " . self::FLAGGED . ", now() + interval '1 day'),
			('bootstrap-clear', " . self::CLEAR . ", now() + interval '1 day'),
			('bootstrap-changes', " . self::CHANGES . ", now() + interval '1 day')");

		self::$flaggedApiKey = ApiKeyService::GetInstance()->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, 'bootstrap test', null, null, self::FLAGGED);
	}

	/**
	 * One request through the helper, against $schema (default: this class's).
	 *
	 * @return array{status: int, location: string, body: string}
	 */
	private static function request(array $spec, ?string $schema = null): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => $schema ?? self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/bootstrap-subprocess-helper.php', base64_encode(json_encode($spec))],
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

	private static function flag(int $userId, ?string $schema = null): int
	{
		$stmt = self::$db->prepare('SELECT must_change_password FROM ' . ($schema ?? self::Schema()) . '.users WHERE id = ?');
		$stmt->execute([$userId]);

		return (int)$stmt->fetchColumn();
	}

	public function testOperatorSuppliedPasswordSeedsTheAdministratorWithoutAForcedChange(): void
	{
		$supplied = getenv(InitialDataSeeder::BOOTSTRAP_PASSWORD_ENV);
		self::assertNotFalse($supplied, 'run-tests.sh sets ' . InitialDataSeeder::BOOTSTRAP_PASSWORD_ENV . ' for the suite; this case tests that it was used');

		$admin = self::$db->query("SELECT password, must_change_password FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);

		self::assertTrue(password_verify($supplied, $admin['password']), 'the administrator has the password the operator supplied');
		self::assertFalse(password_verify('admin', $admin['password']), 'and not the publicly known one');
		self::assertSame(0, (int)$admin['must_change_password'], 'a password the operator chose is not one somebody else read in a log');
	}

	public function testWithoutAnOperatorPasswordOneIsGeneratedReportedOnceAndMustBeChanged(): void
	{
		$schema = 'phpunit_bootstrap_' . bin2hex(random_bytes(6));
		$supplied = getenv(InitialDataSeeder::BOOTSTRAP_PASSWORD_ENV);
		$reports = [];
		$capture = function (string $username, string $password) use (&$reports)
		{
			$reports[] = [$username, $password];
		};

		$pdo = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$pdo->exec('CREATE SCHEMA ' . $schema);
		$pdo->exec('SET search_path TO ' . $schema . ', public');
		DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

		try
		{
			putenv(InitialDataSeeder::BOOTSTRAP_PASSWORD_ENV);
			self::pointDatabaseServiceAt($pdo);

			DatabaseMigrationService::GetInstance()->MigrateDatabase(true, $capture);
			// A second run finds the baseline loaded and seeds nothing - so reports nothing
			DatabaseMigrationService::GetInstance()->MigrateDatabase(true, $capture);
		}
		finally
		{
			if ($supplied !== false)
			{
				putenv(InitialDataSeeder::BOOTSTRAP_PASSWORD_ENV . '=' . $supplied);
			}

			self::pointDatabaseServiceAt(self::$db);
		}

		try
		{
			self::assertCount(1, $reports, 'reported exactly once, by the run that seeded');
			[$username, $generated] = $reports[0];
			self::assertSame('admin', $username);
			self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $generated, '96 random bits, hex so it survives a copy from a terminal');

			$admin = $pdo->query("SELECT id, password, must_change_password FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
			self::assertTrue(password_verify($generated, $admin['password']), 'the reported password is the one stored');
			self::assertFalse(password_verify('admin', $admin['password']), 'no fixed credential is left on this path');
			self::assertSame(1, (int)$admin['must_change_password'], 'a password that has been printed to a log must be changed');

			// Full stack: log in with it. The flag survives the login (login only raises it),
			// and the session that login created is refused by the API.
			$login = self::request(['method' => 'POST', 'path' => '/login', 'form' => ['username' => 'admin', 'password' => $generated]], $schema);
			self::assertSame(302, $login['status']);
			self::assertSame('http://localhost/', $login['location'], 'the generated password logs in');
			self::assertSame(1, self::flag((int)$admin['id'], $schema), 'logging in with the generated password does not lift the forced change');

			$session = $pdo->query('SELECT session_key FROM sessions WHERE user_id = ' . (int)$admin['id'])->fetchColumn();
			self::assertNotFalse($session, 'the login created a session');
			$api = self::request(['method' => 'POST', 'path' => '/api/users', 'cookie' => $session, 'json' => ['username' => 'planted', 'password' => 'planted-password-1']], $schema);
			self::assertSame(403, $api['status'], 'the session cannot create a second administrator before the password is changed');
			self::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'planted'")->fetchColumn());

			$refused = self::request(['method' => 'POST', 'path' => '/login', 'form' => ['username' => 'admin', 'password' => 'admin']], $schema);
			self::assertSame('http://localhost/login?invalid=true', $refused['location'], 'admin/admin does not log into a fresh installation');
		}
		finally
		{
			$pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
		}
	}

	private static function pointDatabaseServiceAt(PDO $pdo): void
	{
		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);
	}

	public function testLoggingInWithTheDefaultPasswordRaisesTheFlag(): void
	{
		self::assertSame(0, self::flag(self::DEFAULT_LOGIN));

		$login = self::request(['method' => 'POST', 'path' => '/login', 'form' => ['username' => 'bootstrap-default', 'password' => 'admin']]);

		self::assertSame('http://localhost/', $login['location']);
		self::assertSame(1, self::flag(self::DEFAULT_LOGIN), 'an installation still on admin/admin is caught at its next login, with no migration needed');
	}

	/**
	 * @return array<string, array{string, string, ?array}>
	 */
	public static function refusedApiRequests(): array
	{
		return [
			'a read of household data' => ['GET', '/api/objects/products', null],
			'creating a second administrator' => ['POST', '/api/users', ['username' => 'planted-by-flagged', 'password' => 'planted-password-1']],
			'editing somebody else\'s account' => ['PUT', '/api/users/' . self::OTHER, ['username' => 'bootstrap-other', 'password' => 'taken-over-1']],
			'granting permissions' => ['PUT', '/api/users/' . self::OTHER . '/permissions', ['permissions' => []]],
			'the settings bag the flag used to live in' => ['DELETE', '/api/user/settings/must_change_password', null],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedApiRequests')]
	public function testFlaggedSessionIsRefusedByTheApi(string $method, string $path, ?array $json): void
	{
		$answer = self::request(array_filter(['method' => $method, 'path' => $path, 'cookie' => 'bootstrap-flagged', 'json' => $json], fn($v) => $v !== null));

		self::assertSame(403, $answer['status'], "$method $path");
		self::assertStringContainsString('must change its password', $answer['body']);
		self::assertSame(0, (int)self::$db->query("SELECT COUNT(*) FROM users WHERE username = 'planted-by-flagged'")->fetchColumn());
	}

	public function testFlaggedApiKeyIsRefusedTheSameWay(): void
	{
		$answer = self::request(['method' => 'GET', 'path' => '/api/objects/products', 'apikey' => self::$flaggedApiKey]);

		self::assertSame(403, $answer['status'], 'a key minted by a flagged account is no more the operator\'s than the session that minted it');
	}

	public function testWhatTheChangePasswordPageNeedsStillAnswers(): void
	{
		$whoAmI = self::request(['method' => 'GET', 'path' => '/api/user', 'cookie' => 'bootstrap-flagged']);
		self::assertSame(200, $whoAmI['status']);
		self::assertSame(self::FLAGGED, (int)json_decode($whoAmI['body'], true)[0]['id']);

		self::assertSame(200, self::request(['method' => 'GET', 'path' => '/api/system/db-changed-time', 'cookie' => 'bootstrap-flagged'])['status'], 'polled by every rendered page');
		self::assertSame(200, self::request(['method' => 'GET', 'path' => '/api/user', 'apikey' => self::$flaggedApiKey])['status']);

		$page = self::request(['method' => 'GET', 'path' => '/stockoverview', 'cookie' => 'bootstrap-flagged']);
		self::assertSame(302, $page['status']);
		self::assertSame('http://localhost/user/' . self::FLAGGED . '?changepw=true', $page['location'], 'rendered pages still redirect to the form');
	}

	public function testAnUnflaggedAccountIsUnaffected(): void
	{
		self::assertSame(200, self::request(['method' => 'GET', 'path' => '/api/objects/products', 'cookie' => 'bootstrap-clear'])['status']);
	}

	public function testChangingThePasswordThroughTheAllowedRouteLiftsTheRestriction(): void
	{
		self::assertSame(403, self::request(['method' => 'GET', 'path' => '/api/objects/products', 'cookie' => 'bootstrap-changes'])['status']);

		$change = self::request(['method' => 'PUT', 'path' => '/api/users/' . self::CHANGES, 'cookie' => 'bootstrap-changes', 'json' => [
			'username' => 'bootstrap-changes',
			'password' => 'after-change-1',
			'current_password' => 'before-change-1',
		]]);

		self::assertSame(204, $change['status'], $change['body']);
		self::assertSame(0, self::flag(self::CHANGES));
		self::assertSame(200, self::request(['method' => 'GET', 'path' => '/api/objects/products', 'cookie' => 'bootstrap-changes'])['status']);
	}
}
