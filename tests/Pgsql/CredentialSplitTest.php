<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Plan 20 verification 8 (issue #133): deploy/postgres/roles.sql really does split the
 * database credential in two, and the application still works on the restricted half.
 *
 * This is the database half of that check - the pod half is the boot test in the nix
 * workflow, which needs the images - and it exists because the first attempt at the
 * restricted role found something reading could not: PostgresDialect::OnConnected() ran
 * CREATE TABLE IF NOT EXISTS on every connection, PostgreSQL checks CREATE on the schema
 * before it checks whether the table is already there, and so a role with no DDL rights
 * could not connect at all. Nothing in the suite noticed because every phase connects as a
 * superuser.
 *
 * It does not extend PgsqlSchemaTestCase. That class puts each test into a schema of its
 * own inside one shared database, and the script under test grants on the `public` schema
 * of a database it is pointed at - so this class makes a database of its own, runs the
 * real script with psql, and drives bin/victual-migrate and a probe as the two roles in
 * subprocesses, which is also the only way to be a second role (the connection settings
 * are constants, defined once per process).
 */
class CredentialSplitTest extends TestCase
{
	private const MIGRATE_ROLE = 'victual_migrate';
	private const APP_ROLE = 'victual_app';
	private const MIGRATE_PASSWORD = 'credential-split-migrate';
	private const APP_PASSWORD = 'credential-split-app';

	private static PDO $admin;
	private static string $database;
	private static string $dataPath;

	/** Whether this class created the roles, and so may drop them: never someone else's. */
	private static bool $createdRoles = false;

	public static function setUpBeforeClass(): void
	{
		self::$database = 'victual_credsplit_' . bin2hex(random_bytes(4));

		// An empty data directory of its own. The suite's has a config.php naming the
		// suite's role and database, which the application would load and obey.
		self::$dataPath = sys_get_temp_dir() . '/' . self::$database;
		mkdir(self::$dataPath, 0700, true);

		self::$admin = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);

		// Roles are cluster-wide and the script resets their attributes and passwords, so on a
		// server that already has either one this test would be rewriting somebody's roles.
		// The suite's servers are throwaways; one that is not is refused rather than used.
		$existing = self::$admin->query("SELECT string_agg(rolname, ', ') FROM pg_roles WHERE rolname IN ('" . self::MIGRATE_ROLE . "', '" . self::APP_ROLE . "')")->fetchColumn();

		if ($existing)
		{
			@rmdir(self::$dataPath);
			self::fail("the server already has the role(s) {$existing}; refusing to reset them. Run this against a disposable PostgreSQL (see run-tests.sh)");
		}

		self::$admin->exec('CREATE DATABASE ' . self::$database);
		self::$createdRoles = true;

		// The script under test, run the way an operator runs it: psql, as a role that can
		// create roles, with the passwords as variables. Twice, because "safe to run again"
		// is a claim in its header and this is the cheapest place to hold it to that.
		self::runRolesScript();
		self::runRolesScript();
	}

	public static function tearDownAfterClass(): void
	{
		// The roles are cluster-wide and this database is the only thing here that referenced
		// them, so they go with it - on a developer's own server they would otherwise outlive
		// every run. A role that something else still depends on refuses to drop, which is
		// somebody else's deployment and none of this test's business.
		self::$admin->exec('DROP DATABASE IF EXISTS ' . self::$database . ' WITH (FORCE)');
		@rmdir(self::$dataPath);

		foreach (self::$createdRoles ? [self::APP_ROLE, self::MIGRATE_ROLE] : [] as $role)
		{
			try
			{
				self::$admin->exec('DROP ROLE IF EXISTS ' . $role);
			}
			catch (\PDOException $ex)
			{
			}
		}
	}

	public function testTheMigrateRoleCanMigrateAnEmptyDatabaseAndTheAppRoleCannot(): void
	{
		// The role that cannot migrate first, against a database nobody has migrated. If it
		// could, "the credential split is real" would be false in the way that matters: the
		// serving container's role is the one an attacker in php-fpm gets.
		[$appExit, $appOutput] = $this->runProcess([PHP_BINARY, VICTUAL_ROOT_PATH . '/bin/victual-migrate', '--quiet'], self::APP_ROLE, self::APP_PASSWORD);
		$this->assertNotSame(0, $appExit, "the app role migrated a database:\n" . $appOutput);

		[$migrateExit, $migrateOutput] = $this->runProcess([PHP_BINARY, VICTUAL_ROOT_PATH . '/bin/victual-migrate', '--quiet'], self::MIGRATE_ROLE, self::MIGRATE_PASSWORD);
		$this->assertSame(0, $migrateExit, "the migrate role could not migrate:\n" . $migrateOutput);

		// And a second run is a no-op that still succeeds, which is what the initContainer
		// does on every pod start after the first.
		[$again, $againOutput] = $this->runProcess([PHP_BINARY, VICTUAL_ROOT_PATH . '/bin/victual-migrate', '--quiet'], self::MIGRATE_ROLE, self::MIGRATE_PASSWORD);
		$this->assertSame(0, $again, "a second migrate run failed:\n" . $againOutput);
	}

	#[Depends('testTheMigrateRoleCanMigrateAnEmptyDatabaseAndTheAppRoleCannot')]
	public function testTheAppRoleConnectsAndWritesRowsButCannotChangeStructure(): void
	{
		// A table the migrate role creates after the script ran: what a later migration
		// looks like to the app role, and what ALTER DEFAULT PRIVILEGES is for.
		$this->asMigrateRole('CREATE TABLE credential_split_later (id serial PRIMARY KEY, note text)');

		$report = $this->probeAsAppRole();

		$this->assertNull($report['connect_error'], 'the app role could not connect: ' . ($report['connect_error'] ?? ''));
		$this->assertSame(self::APP_ROLE, $report['connected_as']);

		foreach (['select_view', 'insert', 'update', 'delete', 'sequence', 'later_table_insert', 'later_table_select'] as $allowed)
		{
			$this->assertNull($report['attempts'][$allowed], "the app role was refused {$allowed}: SQLSTATE " . var_export($report['attempts'][$allowed], true));
		}

		// 42501 is insufficient_privilege. Any other code would mean the statement failed
		// for a reason unrelated to the role - a typo in this test - and would pass a plain
		// "was refused" assertion while proving nothing.
		foreach (['create_table', 'alter_table', 'drop_table', 'drop_view', 'truncate', 'create_trigger', 'create_schema'] as $refused)
		{
			$this->assertSame('42501', $report['attempts'][$refused], "{$refused} should be refused with 42501 for the app role, got " . var_export($report['attempts'][$refused], true));
		}
	}

	#[Depends('testTheAppRoleConnectsAndWritesRowsButCannotChangeStructure')]
	public function testNothingWasLeftBehindByTheRestrictedRoleAndTheMigrateRoleStillOwnsTheSchema(): void
	{
		// pg_tables is per-database, so this has to ask through a connection to the throwaway
		// one; the admin connection is to the suite's shared database.
		$pdo = $this->connectToDatabase();
		$owners = $pdo->query("SELECT DISTINCT tableowner FROM pg_tables WHERE schemaname = 'public'")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertSame([self::MIGRATE_ROLE], $owners, 'every table in the schema should belong to the migrate role');

		$leftovers = $pdo->query("SELECT relname FROM pg_class WHERE relname LIKE 'credential_split_probe%'")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertSame([], $leftovers, 'a structural change by the app role took effect');

		$this->assertSame(
			0,
			(int)$pdo->query("SELECT count(*) FROM locations WHERE name LIKE 'credential-split-probe%'")->fetchColumn(),
			'the probe left rows behind'
		);
	}

	private function probeAsAppRole(): array
	{
		[$exit, $output] = $this->runProcess([PHP_BINARY, __DIR__ . '/credential-split-helper.php'], self::APP_ROLE, self::APP_PASSWORD);
		$this->assertSame(0, $exit, "the probe process failed:\n" . $output);

		$decoded = json_decode($output, true);
		$this->assertIsArray($decoded, "the probe printed something other than JSON:\n" . $output);

		return $decoded;
	}

	private function asMigrateRole(string $sql): void
	{
		$pdo = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . self::$database,
			self::MIGRATE_ROLE,
			self::MIGRATE_PASSWORD,
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$pdo->exec($sql);
	}

	private function connectToDatabase(): PDO
	{
		return new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . self::$database,
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
	}

	private static function runRolesScript(): void
	{
		$env = self::environment(getenv('PGUSER'), getenv('PGPASSWORD'));
		$env['PGDATABASE'] = self::$database;

		$process = proc_open(
			[
				'psql', '--no-psqlrc', '--quiet',
				'-v', 'ON_ERROR_STOP=1',
				'-v', 'db=' . self::$database,
				'-v', 'migrate_password=' . self::MIGRATE_PASSWORD,
				'-v', 'app_password=' . self::APP_PASSWORD,
				'-f', VICTUAL_ROOT_PATH . '/deploy/postgres/roles.sql',
			],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		self::assertIsResource($process, 'psql could not be started - the suite needs the PostgreSQL client tools, as run-tests.sh itself does');

		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		self::assertSame(0, proc_close($process), "deploy/postgres/roles.sql failed:\n" . $output);
	}

	/** @return array{0:int,1:string} exit status, and stdout and stderr together */
	private function runProcess(array $command, string $role, string $password): array
	{
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::environment($role, $password, true));
		$this->assertIsResource($process);

		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

		return [proc_close($process), $output];
	}

	/**
	 * The environment for a child process. $_SERVER carries non-scalar entries (argv among
	 * them) that proc_open's env argument refuses, so it is built from getenv() instead.
	 * With $application set, the child is pointed at the throwaway database as the given
	 * role through the VICTUAL_DB_* settings config-dist.php reads - no config.php, which
	 * is how the serving containers are configured too.
	 */
	private static function environment(string $user, string $password, bool $application = false): array
	{
		$env = getenv();

		if ($application)
		{
			$env['VICTUAL_DB_DRIVER'] = 'pgsql';
			$env['VICTUAL_DB_HOST'] = (string)getenv('PGHOST');
			$env['VICTUAL_DB_PORT'] = (string)getenv('PGPORT');
			$env['VICTUAL_DB_NAME'] = self::$database;
			$env['VICTUAL_DB_USER'] = $user;
			$env['VICTUAL_DB_PASSWORD'] = $password;
			$env['VICTUAL_DATAPATH'] = self::$dataPath;
		}
		else
		{
			$env['PGUSER'] = $user;
			$env['PGPASSWORD'] = $password;
		}

		return $env;
	}
}
