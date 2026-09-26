<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Victual\Services\Database\PostgresDialect;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #487 findings H6 (#495) and M17 (#517): the migration runner's atomicity and its
 * behaviour under the restricted application role deploy/postgres/roles.sql grants. Also
 * covers the follow-up findings from Opus's validation of the first version of this fix
 * (PR #526): catching \Exception rather than \Throwable left a \TypeError open a
 * transaction and, through DatabaseDialect::WithMigrationLock()'s own cleanup, able to mask
 * itself and leak the migration lock; and EMERGENCY/DOALWAYS ids, having no version row of
 * their own, could have a swallowed database error reach commit() as a silent no-op.
 *
 * Both original findings are about DatabaseMigrationService running DDL it should not, or
 * failing to undo DDL it already ran, so both live in one file against the real runner
 * methods - DatabaseMigrationService::ExecutePhpMigrationWhenNeeded() and
 * ::EnsureMigrationsTable() - reached by ReflectionMethod the way the audit's own probes did.
 *
 * Does not extend PgsqlSchemaTestCase's usual pattern of one fully migrated schema per
 * class: both findings are about what happens *before* or *during* a migration run against
 * a schema that is deliberately not (yet) in the state a normal test fixture would be, so
 * each test builds a disposable minimal schema of its own - CREATE SCHEMA, do the probe,
 * DROP SCHEMA - the way the H6/M17 probes in issue #487 did. Boot() is reused for the
 * idempotent application bootstrap (config-dist.php, the VICTUAL_* constants LocalizationService
 * and DatabaseService need); MigrateDatabase() is deliberately never called here.
 */
class MigrationRunnerAtomicityTest extends PgsqlSchemaTestCase
{
	/** An id no real migration file uses, for the \TypeError probes below. */
	private const PROBE_MIGRATION_ID = 900001;

	private static ?PDO $Pdo = null;

	public static function setUpBeforeClass(): void
	{
		static::Boot();

		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

		DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		self::$Pdo = $pdo;
	}

	public static function tearDownAfterClass(): void
	{
		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, null);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		self::$Pdo = null;
	}

	/**
	 * H6: invoking the real 0274 PHP migration against a schema where
	 * locations.storage_class_id already exists - the fault issue #487 reproduced - throws
	 * on the ALTER, on the first attempt and on an unmodified retry, without ever leaving
	 * the storage_classes table its CREATE half made behind, and without ever recording
	 * version 274. Before the fix, the first attempt's CREATE TABLE survived the ALTER's
	 * failure (each ran as its own auto-committed statement), so the retry failed on a
	 * second, different error - "storage_classes already exists" - instead of the original
	 * conflict.
	 */
	public function testFailedPhpMigrationRollsBackAndAnUnmodifiedRetryFailsTheSameWayNotDifferently(): void
	{
		$schema = $this->CreateProbeSchema();

		try
		{
			self::$Pdo->exec('CREATE TABLE migrations (migration integer NOT NULL PRIMARY KEY)');

			// Fault injection: the column 0274 means to add is already there, exactly as
			// issue #487's H6 reproduction set it up. Both attempts below fail on the ALTER,
			// before 0274 ever reaches the LocalizationService/quantity_units read its happy
			// path depends on (see the sibling retry-succeeds test for that), so this fixture
			// needs nothing beyond migrations and locations.
			self::$Pdo->exec('CREATE TABLE locations (id integer, storage_class_id integer)');

			$service = DatabaseMigrationService::GetInstance();
			$method = new ReflectionMethod($service, 'ExecutePhpMigrationWhenNeeded');
			$method->setAccessible(true);
			$phpFile = VICTUAL_ROOT_PATH . '/migrations/0274.pgsql.php';

			$errorCodes = [];

			for ($attempt = 0; $attempt < 2; $attempt++)
			{
				$counter = 0;

				try
				{
					$method->invokeArgs($service, [274, $phpFile, &$counter]);
					$this->fail('attempt ' . $attempt . ' should have failed: ALTER TABLE locations ADD COLUMN storage_class_id conflicts with the pre-existing column');
				}
				catch (\PDOException $ex)
				{
					$errorCodes[] = $ex->getCode();
					$this->assertSame(0, $counter, 'a failed migration must not increment the applied-migration counter');
				}
			}

			// 42701 is duplicate_column. Both attempts failing on the same code is the
			// atomicity claim itself: a second, different code (42P07, duplicate_table) would
			// mean the first attempt's CREATE TABLE survived into the second attempt.
			$this->assertSame(['42701', '42701'], $errorCodes, 'both attempts should fail on the same conflict, not a second one caused by an orphaned CREATE TABLE');

			$this->assertNull(
				self::$Pdo->query("SELECT to_regclass('storage_classes')")->fetchColumn(),
				'the CREATE TABLE half of the failed migration must not survive its failing second half'
			);
			$this->assertSame(
				0,
				(int)self::$Pdo->query('SELECT count(*) FROM migrations')->fetchColumn(),
				'version 274 must not be recorded when the migration body failed'
			);
		}
		finally
		{
			$this->DropProbeSchema($schema);
		}
	}

	/**
	 * H6, the recovery half: once the real conflict is resolved (the operator drops the
	 * column that should not have been there), a retry of the same migration applies
	 * cleanly and is recorded - the "and retry tests prove recovery" half of the required
	 * outcome, not just "fails without a mess".
	 */
	public function testPhpMigrationRetrySucceedsOnceTheUnderlyingConflictIsResolved(): void
	{
		$schema = $this->CreateProbeSchema();

		try
		{
			self::$Pdo->exec('CREATE TABLE migrations (migration integer NOT NULL PRIMARY KEY)');
			self::$Pdo->exec('CREATE TABLE locations (id integer, storage_class_id integer)');

			// Unlike the sibling test, this one needs the retry to actually finish: once the
			// ALTER succeeds, 0274's happy path builds a LocalizationService, which
			// best-effort-reads quantity_units and silently swallows a missing-table failure
			// at the PHP level (LocalizationService::LoadLocalizations()) - but that failed
			// SELECT still aborts the *Postgres* transaction this fix wraps the migration in,
			// which the seeding inserts after it would inherit as SQLSTATE 25P02. An empty
			// table is enough; this is scaffolding the retry does not touch, not a second
			// thing under test.
			self::$Pdo->exec('CREATE TABLE quantity_units (id integer, active integer, name text, name_plural text, plural_forms text)');

			$service = DatabaseMigrationService::GetInstance();
			$method = new ReflectionMethod($service, 'ExecutePhpMigrationWhenNeeded');
			$method->setAccessible(true);
			$phpFile = VICTUAL_ROOT_PATH . '/migrations/0274.pgsql.php';

			$counter = 0;

			try
			{
				$method->invokeArgs($service, [274, $phpFile, &$counter]);
				$this->fail('the first attempt should fail while the conflicting column is present');
			}
			catch (\PDOException $ex)
			{
				$this->assertSame('42701', $ex->getCode());
			}

			// The operator's actual fix: remove the column that conflicted, the same way
			// they would drop whatever a partial deploy left behind before retrying - except
			// here there is nothing else to clean up, which is the point.
			self::$Pdo->exec('ALTER TABLE locations DROP COLUMN storage_class_id');

			$counter = 0;
			$method->invokeArgs($service, [274, $phpFile, &$counter]);

			$this->assertSame(1, $counter, 'the retry should apply exactly one migration');
			$this->assertSame(
				['274'],
				self::$Pdo->query('SELECT migration::text FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN),
				'version 274 should be recorded once the retry succeeds'
			);
			$this->assertSame(
				5,
				(int)self::$Pdo->query('SELECT count(*) FROM storage_classes')->fetchColumn(),
				'the five seeded storage classes should exist after a successful run'
			);
			$this->assertNotNull(
				self::$Pdo->query("SELECT to_regclass('storage_classes')")->fetchColumn(),
				'storage_classes should exist after a successful run'
			);
		}
		finally
		{
			$this->DropProbeSchema($schema);
		}
	}

	/**
	 * M17: EnsureMigrationsTable() must not attempt any DDL when the "migrations" table
	 * already exists, so a role with USAGE/SELECT but no CREATE - deploy/postgres/roles.sql's
	 * victual_app, held by the exact code path SystemController::Root() reaches under
	 * MIGRATE_ON_ROOT_REQUEST - can pass through it. Before the fix, a bare
	 * "CREATE TABLE IF NOT EXISTS" failed 42501 regardless of whether the table was already
	 * there, because PostgreSQL checks CREATE on the schema before it checks existence.
	 */
	public function testEnsureMigrationsTableIsANoOpUnderARestrictedRoleWhenTheTableAlreadyExists(): void
	{
		$schema = $this->CreateProbeSchema();
		$role = $schema . '_reader';
		$roleCreated = false;

		try
		{
			self::$Pdo->exec('CREATE TABLE migrations (migration integer NOT NULL PRIMARY KEY)');
			self::$Pdo->exec('INSERT INTO migrations (migration) VALUES (255)');

			self::$Pdo->exec('CREATE ROLE ' . $role);
			$roleCreated = true;
			self::$Pdo->exec('GRANT USAGE ON SCHEMA ' . $schema . ' TO ' . $role);
			self::$Pdo->exec('GRANT SELECT ON ALL TABLES IN SCHEMA ' . $schema . ' TO ' . $role);

			$dialect = DatabaseService::GetInstance()->GetDialect();
			$method = new ReflectionMethod(DatabaseMigrationService::class, 'EnsureMigrationsTable');
			$method->setAccessible(true);

			self::$Pdo->exec('SET ROLE ' . $role);

			try
			{
				$method->invoke(DatabaseMigrationService::GetInstance(), $dialect);
			}
			finally
			{
				self::$Pdo->exec('RESET ROLE');
			}

			// Unchanged state: the row that was already there is still exactly what is
			// there - no DDL means nothing could have been created, dropped or altered.
			$this->assertSame(
				['255'],
				self::$Pdo->query('SELECT migration::text FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN),
				'the pre-existing table and its row must be untouched by a no-op check'
			);
		}
		finally
		{
			self::$Pdo->exec('RESET ROLE');

			if ($roleCreated)
			{
				self::$Pdo->exec('DROP OWNED BY ' . $role);
				self::$Pdo->exec('DROP ROLE ' . $role);
			}

			$this->DropProbeSchema($schema);
		}
	}

	/**
	 * M17, the boundary the fix must preserve: a restricted role still cannot bootstrap a
	 * missing "migrations" table. Reading before creating must not turn into "assume the
	 * table exists" - DDL stays under the migrate role for a genuinely unmigrated database,
	 * refused with insufficient_privilege and no partial table left behind.
	 */
	public function testEnsureMigrationsTableStillRefusesToCreateUnderARestrictedRoleWhenTheTableIsMissing(): void
	{
		$schema = $this->CreateProbeSchema();
		$role = $schema . '_reader';
		$roleCreated = false;

		try
		{
			self::$Pdo->exec('CREATE ROLE ' . $role);
			$roleCreated = true;
			self::$Pdo->exec('GRANT USAGE ON SCHEMA ' . $schema . ' TO ' . $role);

			$dialect = DatabaseService::GetInstance()->GetDialect();
			$method = new ReflectionMethod(DatabaseMigrationService::class, 'EnsureMigrationsTable');
			$method->setAccessible(true);

			self::$Pdo->exec('SET ROLE ' . $role);

			$thrown = null;

			try
			{
				$method->invoke(DatabaseMigrationService::GetInstance(), $dialect);
			}
			catch (\PDOException $ex)
			{
				$thrown = $ex;
			}
			finally
			{
				self::$Pdo->exec('RESET ROLE');
			}

			$this->assertNotNull($thrown, 'a role with no CREATE must not be able to bootstrap a missing migrations table');
			$this->assertSame('42501', $thrown->getCode(), 'insufficient_privilege, not some other failure');
			$this->assertNull(
				self::$Pdo->query("SELECT to_regclass('migrations')")->fetchColumn(),
				'a refused create must leave no partial table behind'
			);
		}
		finally
		{
			self::$Pdo->exec('RESET ROLE');

			if ($roleCreated)
			{
				self::$Pdo->exec('DROP OWNED BY ' . $role);
				self::$Pdo->exec('DROP ROLE ' . $role);
			}

			$this->DropProbeSchema($schema);
		}
	}

	/**
	 * Opus validation follow-up on H6: a PHP migration that throws something other than
	 * \Exception - a \TypeError here, with no InTransaction() of its own - must still roll
	 * back and must still release the real migration lock DatabaseDialect::WithMigrationLock()
	 * takes. Before this fix (catching \Exception, not \Throwable), the \TypeError left the
	 * transaction open and aborted; WithMigrationLock()'s own pg_advisory_unlock() then ran
	 * inside that aborted transaction, failed with SQLSTATE 25P02, masked the \TypeError with
	 * its own exception, and left the lock held on this connection.
	 */
	public function testTypeErrorFromAPlainPhpMigrationLeavesNoOpenTransactionAndAFreeMigrationLock(): void
	{
		$this->AssertMigrationFailureLeavesNoTransactionAndAFreeLock(<<<'PHP'
<?php
use Victual\Services\DatabaseService;

$db = DatabaseService::GetInstance()->GetDbConnectionRaw();

try
{
	$db->exec('SELECT * FROM migration_runner_probe_missing_table');
}
catch (\Throwable $ignored)
{
	// Deliberately swallowed, the way LocalizationService::LoadLocalizations() tolerates an
	// unmigrated database - the transaction is left aborted regardless of whether PHP
	// noticed.
}

throw new \TypeError('probe: plain migration TypeError after a swallowed database error');
PHP);
	}

	/**
	 * Opus validation follow-up on H6: the same proof as above, but for a migration shaped
	 * like 0266/0282/0283 - all of its work inside DatabaseService::InTransaction(). Confirms
	 * the nested InTransaction() call does not change the outcome: it joins the outer
	 * transaction ExecutePhpMigrationWhenNeeded() opens rather than starting its own, so the
	 * \TypeError is still caught by the one \Throwable handler that matters.
	 */
	public function testTypeErrorFromAnInTransactionWrappedPhpMigrationLeavesNoOpenTransactionAndAFreeMigrationLock(): void
	{
		$this->AssertMigrationFailureLeavesNoTransactionAndAFreeLock(<<<'PHP'
<?php
use Victual\Services\DatabaseService;

DatabaseService::GetInstance()->InTransaction(function ()
{
	$db = DatabaseService::GetInstance()->GetDbConnectionRaw();

	try
	{
		$db->exec('SELECT * FROM migration_runner_probe_missing_table');
	}
	catch (\Throwable $ignored)
	{
	}

	throw new \TypeError('probe: InTransaction-wrapped migration TypeError after a swallowed database error');
});
PHP);
	}

	/**
	 * Opus validation follow-up on H6, part (a): the always-run EMERGENCY/DOALWAYS ids get no
	 * version row, so nothing would otherwise notice a database error their own code
	 * swallowed - PostgreSQL turns a COMMIT of an aborted transaction into a rollback without
	 * raising, so ExecutePhpMigrationWhenNeeded() would report success while every write the
	 * run made was silently discarded. The SELECT 1 canary added for these ids must turn that
	 * into a visible failure instead, with the run's own writes rolled back rather than kept.
	 */
	public function testAlwaysRunMigrationSwallowingADatabaseErrorFailsVisiblyInsteadOfSilentlyCommitting(): void
	{
		$schema = $this->CreateProbeSchema();
		$probeFile = tempnam(sys_get_temp_dir(), 'migrun_probe_');
		file_put_contents($probeFile, <<<'PHP'
<?php
use Victual\Services\DatabaseService;

$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
$db->exec('CREATE TABLE migration_runner_probe_visible_writes (id integer)');

try
{
	$db->exec('SELECT * FROM migration_runner_probe_missing_table');
}
catch (\Throwable $ignored)
{
	// Deliberately swallowed - the always-run 8888 fixup has no version row of its own to
	// fail on in its place, which is exactly what this probe is checking for.
}
PHP);

		try
		{
			self::$Pdo->exec('CREATE TABLE migrations (migration integer NOT NULL PRIMARY KEY)');

			$service = DatabaseMigrationService::GetInstance();
			$method = new ReflectionMethod($service, 'ExecutePhpMigrationWhenNeeded');
			$method->setAccessible(true);

			$counter = 0;
			$thrown = null;

			try
			{
				$method->invokeArgs($service, [DatabaseMigrationService::DOALWAYS_MIGRATION_ID, $probeFile, &$counter]);
			}
			catch (\PDOException $ex)
			{
				$thrown = $ex;
			}

			$this->assertNotNull($thrown, 'a swallowed database error must still surface as a failure, not a silent successful commit');
			$this->assertSame('25P02', $thrown->getCode(), 'the aborted-transaction canary should be what raises it');
			$this->assertNull(
				self::$Pdo->query("SELECT to_regclass('migration_runner_probe_visible_writes')")->fetchColumn(),
				'the writes this run made before swallowing the error must not survive - PostgreSQL would otherwise silently roll back a COMMIT of an aborted transaction while this method reported success'
			);
		}
		finally
		{
			unlink($probeFile);

			if (self::$Pdo->inTransaction())
			{
				self::$Pdo->rollback();
			}

			$this->DropProbeSchema($schema);
		}
	}

	/**
	 * Opus validation follow-up on M17, part (c): SystemController::Root()'s
	 * MIGRATE_ON_ROOT_REQUEST fallback, and bin/victual-migrate run as any pod's normal
	 * startup step, call MigrateDatabase() under whichever role the caller's connection
	 * holds. On a database the migrate role has already fully brought up to date, the serving
	 * container's own role - victual_app, USAGE/SELECT/INSERT/UPDATE/DELETE only, no CREATE -
	 * must be able to run the exact same call and see it succeed as a no-op rather than fail
	 * on DDL it never needed. Builds its own throwaway database and the two roles
	 * deploy/postgres/roles.sql defines, the way CredentialSplitTest does, and drives
	 * bin/victual-migrate in subprocesses under each role - the only way to be a different
	 * database role, since the connection settings are constants fixed once per process.
	 */
	public function testMigrateAsTheAppRoleOnAnAlreadyMigratedDatabaseSucceedsWithoutChangingTheSchema(): void
	{
		$database = 'victual_migrun_approle_' . bin2hex(random_bytes(4));
		$dataPath = sys_get_temp_dir() . '/' . $database;
		mkdir($dataPath, 0700, true);
		$migratePassword = 'migrun-migrate-' . bin2hex(random_bytes(4));
		$appPassword = 'migrun-app-' . bin2hex(random_bytes(4));

		$admin = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);

		// victual_migrate/victual_app are cluster-wide roles. CredentialSplitTest uses the
		// same names and drops them in its own tearDownAfterClass(), which - per phpunit.xml's
		// file order in the credentialsplit testsuite - runs before this class's tests do;
		// this is the same precondition check that class makes of itself.
		$existing = $admin->query("SELECT string_agg(rolname, ', ') FROM pg_roles WHERE rolname IN ('victual_migrate', 'victual_app')")->fetchColumn();

		if ($existing)
		{
			@rmdir($dataPath);
			$this->markTestSkipped("the role(s) {$existing} already exist; refusing to reset them");

			return;
		}

		$admin->exec('CREATE DATABASE ' . $database);
		$rolesCreated = false;

		try
		{
			$rolesEnv = getenv();
			$rolesEnv['PGDATABASE'] = $database;

			$rolesProcess = proc_open(
				[
					'psql', '--no-psqlrc', '--quiet',
					'-v', 'ON_ERROR_STOP=1',
					'-v', 'db=' . $database,
					'-v', 'migrate_password=' . $migratePassword,
					'-v', 'app_password=' . $appPassword,
					'-f', VICTUAL_ROOT_PATH . '/deploy/postgres/roles.sql',
				],
				[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes,
				null,
				$rolesEnv
			);
			$this->assertIsResource($rolesProcess, 'psql could not be started');
			$rolesOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
			$this->assertSame(0, proc_close($rolesProcess), "deploy/postgres/roles.sql failed:\n" . $rolesOutput);
			$rolesCreated = true;

			// Fully migrated, as the migrate role - what the deploy's initContainer leaves a
			// pod in before the app container ever starts.
			[$migrateExit, $migrateOutput] = $this->RunMigrateAsRole($database, 'victual_migrate', $migratePassword, $dataPath . '/migrate');
			$this->assertSame(0, $migrateExit, "the migrate role could not migrate:\n" . $migrateOutput);

			$before = $this->FingerprintDatabase($database);

			// The scenario this test exists for.
			[$appExit, $appOutput] = $this->RunMigrateAsRole($database, 'victual_app', $appPassword, $dataPath . '/app');
			$this->assertSame(0, $appExit, "the app role could not run MigrateDatabase() on an already-migrated database:\n" . $appOutput);

			$this->assertSame($before, $this->FingerprintDatabase($database), 'no DDL should have run: the table list and the recorded migrations must be unchanged');
		}
		finally
		{
			$admin->exec('DROP DATABASE IF EXISTS ' . $database . ' WITH (FORCE)');

			foreach (['migrate', 'app'] as $sub)
			{
				@unlink($dataPath . '/' . $sub . '/config.php');
				@rmdir($dataPath . '/' . $sub);
			}

			@rmdir($dataPath);

			foreach ($rolesCreated ? ['victual_app', 'victual_migrate'] : [] as $role)
			{
				try
				{
					$admin->exec('DROP ROLE IF EXISTS ' . $role);
				}
				catch (\PDOException $ex)
				{
				}
			}
		}
	}

	/**
	 * Runs the given probe migration source through the real advisory lock
	 * (DatabaseDialect::WithMigrationLock()) wrapping a reflected call to
	 * ExecutePhpMigrationWhenNeeded(), then asserts the failure surfaced as a \TypeError
	 * rather than being masked, that no transaction is left open on this connection, and that
	 * a second, independent connection can immediately take the migration lock - proving
	 * WithMigrationLock()'s own pg_advisory_unlock() ran successfully rather than failing
	 * against a still-aborted transaction.
	 */
	private function AssertMigrationFailureLeavesNoTransactionAndAFreeLock(string $probeSource): void
	{
		$schema = $this->CreateProbeSchema();
		$probeFile = tempnam(sys_get_temp_dir(), 'migrun_probe_');
		file_put_contents($probeFile, $probeSource);

		try
		{
			self::$Pdo->exec('CREATE TABLE migrations (migration integer NOT NULL PRIMARY KEY)');

			$service = DatabaseMigrationService::GetInstance();
			$method = new ReflectionMethod($service, 'ExecutePhpMigrationWhenNeeded');
			$method->setAccessible(true);
			$dialect = DatabaseService::GetInstance()->GetDialect();

			$thrown = null;

			try
			{
				$dialect->WithMigrationLock(function () use ($service, $method, $probeFile)
				{
					$counter = 0;
					$method->invokeArgs($service, [self::PROBE_MIGRATION_ID, $probeFile, &$counter]);
				});
			}
			catch (\Throwable $ex)
			{
				$thrown = $ex;
			}

			$this->assertInstanceOf(\TypeError::class, $thrown, 'the real failure must surface, not be masked by a later error while releasing the lock');
			$this->assertFalse(self::$Pdo->inTransaction(), 'no open transaction should remain after the failure');

			$peer = new PDO(
				'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
				getenv('PGUSER'),
				getenv('PGPASSWORD'),
				[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
			);

			$gotLock = false;

			try
			{
				$gotLock = (bool)$peer->query('SELECT pg_try_advisory_lock(' . PostgresDialect::MIGRATION_ADVISORY_LOCK_KEY . ')')->fetchColumn();
				$this->assertTrue($gotLock, 'the migration lock should have been released, not left held by the failed run');
			}
			finally
			{
				if ($gotLock)
				{
					$peer->exec('SELECT pg_advisory_unlock(' . PostgresDialect::MIGRATION_ADVISORY_LOCK_KEY . ')');
				}
			}
		}
		finally
		{
			unlink($probeFile);

			// Whatever the assertions above found, leave the shared connection usable for
			// whatever test runs next: a run against the unfixed code can leave it mid an
			// aborted transaction with the lock still held.
			if (self::$Pdo->inTransaction())
			{
				self::$Pdo->rollback();
			}

			try
			{
				self::$Pdo->exec('SELECT pg_advisory_unlock(' . PostgresDialect::MIGRATION_ADVISORY_LOCK_KEY . ')');
			}
			catch (\Throwable $ignored)
			{
			}

			$this->DropProbeSchema($schema);
		}
	}

	/** Runs bin/victual-migrate against $database as $role, in its own data path. */
	private function RunMigrateAsRole(string $database, string $role, string $password, string $dataPath): array
	{
		mkdir($dataPath, 0700, true);

		$env = getenv();
		$env['VICTUAL_DB_DRIVER'] = 'pgsql';
		$env['VICTUAL_DB_HOST'] = (string)getenv('PGHOST');
		$env['VICTUAL_DB_PORT'] = (string)getenv('PGPORT');
		$env['VICTUAL_DB_NAME'] = $database;
		$env['VICTUAL_DB_USER'] = $role;
		$env['VICTUAL_DB_PASSWORD'] = $password;
		$env['VICTUAL_DATAPATH'] = $dataPath;

		$process = proc_open(
			[PHP_BINARY, VICTUAL_ROOT_PATH . '/bin/victual-migrate', '--quiet'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$this->assertIsResource($process, 'bin/victual-migrate could not be started');

		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

		return [proc_close($process), $output];
	}

	/**
	 * The set of table names and the migration bookkeeping in $database, for asserting a run
	 * changed nothing. Connects as the suite's own superuser-equivalent PGUSER, never as
	 * either role under test.
	 */
	private function FingerprintDatabase(string $database): array
	{
		$pdo = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . $database,
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);

		return [
			'tables' => $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN),
			'maxMigration' => $pdo->query('SELECT MAX(migration) FROM migrations')->fetchColumn(),
			'migrationCount' => (int)$pdo->query('SELECT count(*) FROM migrations')->fetchColumn(),
		];
	}

	/**
	 * A fresh schema, its own random suffix so parallel-looking runs within this class
	 * cannot collide, with search_path pointed at it - the same shape the H6/M17 probes in
	 * issue #487 used, and deliberately not the fully migrated schema
	 * PgsqlSchemaTestCase::setUpBeforeClass() builds: these tests are about states a real
	 * migration run passes through, not the end state.
	 */
	private function CreateProbeSchema(): string
	{
		$schema = 'migrun_' . bin2hex(random_bytes(8));
		self::$Pdo->exec('CREATE SCHEMA ' . $schema);
		self::$Pdo->exec('SET search_path TO ' . $schema . ', public');

		return $schema;
	}

	private function DropProbeSchema(string $schema): void
	{
		self::$Pdo->exec('SET search_path TO public');
		self::$Pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
	}
}
