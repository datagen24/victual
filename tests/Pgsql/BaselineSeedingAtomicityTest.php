<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #557: DatabaseMigrationService::ApplyBaselineSchemaWhenNeeded() (lines 446-470)
 * wraps loading the baseline schema, InitialDataSeeder::Seed() and recording the baseline
 * migration rows in a hand-rolled transaction that only rolls back on
 * `catch (\Exception $ex)`. A PHP \Error - \TypeError, \ValueError, a plain \Error - is not
 * an \Exception, so it skips the rollback and escapes with the transaction still open on
 * the raw PDO connection. During #537's validation, a leaked stdClass reached
 * InitialDataSeeder.php:113 this way, the \Error escaped this catch, and the run stalled
 * for over 600s with the transaction left open.
 *
 * Commit 9fa11873 already fixed the identical pattern for PHP migrations
 * (ExecutePhpMigrationWhenNeeded, covered by MigrationRunnerAtomicityTest); this issue is
 * the two sites that pattern skipped. This file covers the baseline schema/seeding site;
 * FlagGeneratedAdminPasswordForChange() (lines 535-551) has the identical fix and is not
 * separately regression-tested here per the one-test-per-issue scope of this change.
 *
 * A third site with the identical `catch (\Exception)` gap,
 * ExecuteSqlMigrationWhenNeeded() (the SQL migration runner every plain .sql migration
 * goes through), was found in review after this file's first version and is covered below
 * too: #557's invariant - a migration transaction rolls back on any \Throwable, not only an
 * \Exception - applies to every migration transaction, and this is the one other site that
 * still opened one by hand rather than going through commit 9fa11873's fixed pattern.
 *
 * Follows MigrationRunnerAtomicityTest's approach: its own PDO connection (not
 * PgsqlSchemaTestCase's usual fully-migrated-schema-per-class pattern), a disposable probe
 * schema per test, and the real private method reached by ReflectionMethod. Fault
 * injection is a thin PDO subclass that throws a \TypeError from prepare() the moment the
 * seeder's own INSERT reaches quantity_units - a real \Error surfacing partway through the
 * real seeding step, not a reimplementation of it.
 */
class BaselineSeedingAtomicityTest extends PgsqlSchemaTestCase
{
	private static ?FaultInjectingPdo $Pdo = null;

	public static function setUpBeforeClass(): void
	{
		static::Boot();

		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new FaultInjectingPdo($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

		DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		self::$Pdo = $pdo;

		// Issue #533 - see MigrationRunnerAtomicityTest's identical call for why this is
		// necessary once this class's own connection is installed in place of the shared one.
		self::ResetSchemaBoundState();
	}

	public static function tearDownAfterClass(): void
	{
		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, null);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		self::$Pdo = null;
	}

	/**
	 * The regression: a \TypeError raised while InitialDataSeeder::Seed() is inserting the
	 * default quantity units - after the baseline DDL has run and the admin user row has
	 * already been inserted, both inside the same still-open transaction - must roll back
	 * everything ApplyBaselineSchemaWhenNeeded() did: the baseline schema itself (DDL is
	 * transactional in PostgreSQL) and the users row seeded just before the fault, with no
	 * migration rows recorded and no open transaction left on the connection.
	 */
	public function testATypeErrorDuringSeedingRollsBackTheWholeBaselineAndLeavesNoOpenTransaction(): void
	{
		$schema = $this->CreateProbeSchema();

		try
		{
			self::$Pdo->exec('CREATE TABLE migrations (migration INTEGER NOT NULL PRIMARY KEY)');

			$service = DatabaseMigrationService::GetInstance();
			$method = new ReflectionMethod($service, 'ApplyBaselineSchemaWhenNeeded');
			// A ReflectionMethod needs no setAccessible(): the call has had no effect since PHP 8.1, and 8.5 deprecates making it.
			$dialect = DatabaseService::GetInstance()->GetDialect();

			// Fires the moment the real seeder's own SeedQuantityUnits() reaches its first
			// INSERT - after SeedAdminUser() has already written the admin user row inside
			// this same transaction, matching the production report of a fault surfacing
			// partway through seeding, not before it starts.
			self::$Pdo->ThrowOnSqlContaining = 'INSERT INTO "quantity_units"';

			$thrown = null;

			try
			{
				$method->invokeArgs($service, [$dialect, true]);
				$this->fail('the fault-injected \TypeError during seeding should have propagated');
			}
			catch (\TypeError $ex)
			{
				$thrown = $ex;
			}

			$this->assertNotNull($thrown, 'the real \TypeError must surface, not be swallowed');

			$this->assertFalse(
				self::$Pdo->inTransaction(),
				'no open transaction should remain after a \TypeError during seeding - before the fix, catch (\Exception) does not catch \TypeError and the rollback never runs'
			);

			$this->assertNull(
				self::$Pdo->query("SELECT to_regclass('users')")->fetchColumn(),
				'the baseline schema (DDL, transactional in PostgreSQL) must not survive - "users" would still exist, holding the admin row seeded just before the fault, if the transaction was never rolled back'
			);

			$this->assertSame(
				0,
				(int)self::$Pdo->query('SELECT count(*) FROM migrations')->fetchColumn(),
				'no baseline migration id may be recorded when seeding failed partway through'
			);
		}
		finally
		{
			self::$Pdo->ThrowOnSqlContaining = null;

			// Whatever the assertions above found, leave the shared connection usable for
			// whatever runs next in this process: a run against the unfixed code leaves it
			// mid an open (not aborted - the \TypeError never reached PostgreSQL) transaction.
			if (self::$Pdo->inTransaction())
			{
				self::$Pdo->rollback();
			}

			$this->DropProbeSchema($schema);
		}
	}

	/**
	 * The same defect, at ExecuteSqlMigrationWhenNeeded() - the runner every plain .sql
	 * migration goes through. A \TypeError raised while executing the migration's own SQL
	 * (after that SQL already applied real DDL - the migration's CREATE TABLE runs, then
	 * the follow-up INSERT into "migrations" is where the fault fires) must roll back both
	 * halves: the DDL the migration already applied and the migration id that never gets
	 * recorded, with no open transaction left behind.
	 */
	public function testATypeErrorDuringASqlMigrationRollsBackItsDdlAndLeavesNoOpenTransaction(): void
	{
		$schema = $this->CreateProbeSchema();
		$probeMigrationId = 900002;

		try
		{
			self::$Pdo->exec('CREATE TABLE migrations (migration INTEGER NOT NULL PRIMARY KEY)');

			$service = DatabaseMigrationService::GetInstance();
			$method = new ReflectionMethod($service, 'ExecuteSqlMigrationWhenNeeded');

			// Fires on the runner's own bookkeeping INSERT, once the migration's real SQL -
			// a CREATE TABLE, standing in for any plain .sql migration's DDL - has already
			// applied inside the same still-open transaction.
			self::$Pdo->ThrowOnExecContaining = 'INSERT INTO migrations (migration) VALUES (' . $probeMigrationId . ')';

			$counter = 0;
			$thrown = null;

			try
			{
				$method->invokeArgs($service, [$probeMigrationId, 'CREATE TABLE atomicity_probe_sqlmigration (id integer)', &$counter]);
				$this->fail('the fault-injected \TypeError during the SQL migration runner should have propagated');
			}
			catch (\TypeError $ex)
			{
				$thrown = $ex;
			}

			$this->assertNotNull($thrown, 'the real \TypeError must surface, not be swallowed');
			$this->assertSame(0, $counter, 'a failed migration must not increment the applied-migration counter');

			$this->assertFalse(
				self::$Pdo->inTransaction(),
				'no open transaction should remain after a \TypeError from the SQL migration runner - before the fix, catch (\Exception) does not catch \TypeError and the rollback never runs'
			);

			$this->assertNull(
				self::$Pdo->query("SELECT to_regclass('atomicity_probe_sqlmigration')")->fetchColumn(),
				'the DDL the migration already applied before the fault must not survive - it is part of the same transaction the fault aborts'
			);

			$this->assertSame(
				0,
				(int)self::$Pdo->query('SELECT count(*) FROM migrations')->fetchColumn(),
				'the migration id must not be recorded when the runner failed partway through'
			);
		}
		finally
		{
			self::$Pdo->ThrowOnExecContaining = null;

			if (self::$Pdo->inTransaction())
			{
				self::$Pdo->rollback();
			}

			$this->DropProbeSchema($schema);
		}
	}

	/**
	 * A fresh schema, its own random suffix, search_path pointed at it - the same probe
	 * shape MigrationRunnerAtomicityTest uses and for the same reason: this method runs
	 * against a database that has not been migrated yet, not the fully migrated fixture
	 * PgsqlSchemaTestCase::setUpBeforeClass() normally builds.
	 */
	private function CreateProbeSchema(): string
	{
		$schema = 'baselineseed_' . bin2hex(random_bytes(8));
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

/**
 * A test-only PDO subclass that throws a \TypeError from prepare() the moment the SQL text
 * contains a chosen marker - standing in for the production report's leaked stdClass
 * reaching a type-hinted parameter partway through InitialDataSeeder::Seed(), without
 * changing any production code to inject it.
 */
class FaultInjectingPdo extends PDO
{
	public ?string $ThrowOnSqlContaining = null;

	/** Same idea as $ThrowOnSqlContaining, for the exec() path ExecuteDbStatement() takes when called with no params - the one ExecuteSqlMigrationWhenNeeded() uses. */
	public ?string $ThrowOnExecContaining = null;

	#[\ReturnTypeWillChange]
	public function prepare($query, $options = [])
	{
		if ($this->ThrowOnSqlContaining !== null && str_contains($query, $this->ThrowOnSqlContaining))
		{
			throw new \TypeError('fault-injected for BaselineSeedingAtomicityTest: ' . $this->ThrowOnSqlContaining);
		}

		return parent::prepare($query, $options);
	}

	#[\ReturnTypeWillChange]
	public function exec($query)
	{
		if ($this->ThrowOnExecContaining !== null && str_contains($query, $this->ThrowOnExecContaining))
		{
			throw new \TypeError('fault-injected for BaselineSeedingAtomicityTest: ' . $this->ThrowOnExecContaining);
		}

		return parent::exec($query);
	}
}
