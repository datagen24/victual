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
			$method->setAccessible(true);
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

	#[\ReturnTypeWillChange]
	public function prepare($query, $options = [])
	{
		if ($this->ThrowOnSqlContaining !== null && str_contains($query, $this->ThrowOnSqlContaining))
		{
			throw new \TypeError('fault-injected for BaselineSeedingAtomicityTest: ' . $this->ThrowOnSqlContaining);
		}

		return parent::prepare($query, $options);
	}
}
