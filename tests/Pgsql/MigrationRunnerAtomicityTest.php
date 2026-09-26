<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #487 findings H6 (#495) and M17 (#517): the migration runner's atomicity and its
 * behaviour under the restricted application role deploy/postgres/roles.sql grants.
 *
 * Both findings are about DatabaseMigrationService running DDL it should not, or failing to
 * undo DDL it already ran, so both live in one file against the real runner methods -
 * DatabaseMigrationService::ExecutePhpMigrationWhenNeeded() and ::EnsureMigrationsTable() -
 * reached by ReflectionMethod the way the audit's own probes did.
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
			// issue #487's H6 reproduction set it up.
			self::$Pdo->exec('CREATE TABLE locations (id integer, storage_class_id integer)');

			// 0274 unconditionally builds a LocalizationService, which best-effort-reads
			// quantity_units for the separate quantity-unit translation set and silently
			// swallows a missing-table failure (LocalizationService::LoadLocalizations()) -
			// but that failed SELECT still aborts the *Postgres* transaction this fix wraps
			// the migration in, which every statement after it would inherit. An empty table
			// is enough: this is scaffolding the fault being tested does not touch, not a
			// second thing under test.
			self::$Pdo->exec('CREATE TABLE quantity_units (id integer, active integer, name text, name_plural text, plural_forms text)');

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

			// See the sibling test for why this is here: 0274's happy path builds a
			// LocalizationService, which best-effort-reads quantity_units and would
			// otherwise abort the wrapping transaction on a missing table before ever
			// reaching the statement that is actually under test.
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
