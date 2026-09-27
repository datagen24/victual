<?php

namespace Victual\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Victual\Controllers\Api\BaseApiController;
use Victual\Services\BaseService;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Services\FieldPolicy;
use Victual\Services\LocalizationService;
use Victual\Services\Storage\FileStorage;
use Victual\Services\UsersService;

/**
 * Base class for tier 1 (ADR-0025): a PostgreSQL schema of its own per test class,
 * migrated the way the application migrates one, with DatabaseService's singleton
 * connection pointed at it by reflection.
 *
 * The schema lives inside whatever database PHPUNIT_DB_NAME names - a database
 * run-tests.sh creates empty for the whole phpunit phase, the way it creates one per
 * differential phase, except this one is never itself migrated: migrating happens per
 * class, into a schema, so several test classes can share one throwaway database
 * without their tables colliding. The injection is the same one
 * .devtools/labels/test-support.php does for the label suites
 * (label_test_<random>, search_path, ReflectionProperty on DbConnectionRaw), extended to
 * run the real migration path (DatabaseMigrationService::MigrateDatabase(), the same
 * method bin/victual-migrate calls) rather than a handful of hand-picked files.
 */
abstract class PgsqlSchemaTestCase extends TestCase
{
	private static ?PDO $SchemaConnection = null;
	private static string $SchemaName = '';

	public static function setUpBeforeClass(): void
	{
		static::Boot();

		self::$SchemaName = 'phpunit_' . bin2hex(random_bytes(8));

		try
		{
			$pdo = self::Connect();
			$pdo->exec('CREATE SCHEMA ' . self::$SchemaName);

			// Recorded as soon as the schema exists, rather than after everything below
			// succeeds, so that any failure from here on - OnConnected(), the reflection
			// swap, a reset, or the migration itself - still finds a schema to drop in the
			// catch block: TearDownSchema() only attempts the DROP when this is non-null.
			self::$SchemaConnection = $pdo;

			$pdo->exec('SET search_path TO ' . self::$SchemaName . ', public');

			// DatabaseService::GetDbConnectionRaw() is bypassed entirely (the reflection below
			// hands it a connection instead of letting it call CreateConnection()), so what
			// CreateConnection()'s caller normally gets for free - OnConnected()'s session
			// time zone and the changed-time bootstrap table - has to be asked for here.
			DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

			(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
			(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

			// Only now, with the new connection already installed on DatabaseService: issue
			// #533. A class sharing this PHPUnit process with whatever ran before it (see
			// the class docblock) must not reach that class's dropped schema through a
			// cached service instance, or through a static data cache no cache-clear of
			// BaseService::$Instances can reach because it is declared directly on the
			// class rather than on the instance. Resetting before the connection above was
			// ready would leave a window where the reset itself could construct a service
			// against the connection this class is replacing.
			self::ResetSchemaBoundState();

			DatabaseMigrationService::GetInstance()->MigrateDatabase();
		}
		catch (\Throwable $ex)
		{
			// A setup that fails partway must not leave the *next* class attached to this
			// one's schema: PHPUnit does not reliably call tearDownAfterClass() when
			// setUpBeforeClass() itself throws, so the same cleanup tearDownAfterClass()
			// would have done runs here explicitly before the failure propagates. Guarded
			// so that a second failure in cleanup - the DROP itself failing on a connection
			// $ex may already have broken - cannot replace $ex, the failure a caller
			// actually needs to see, with a less informative one about teardown.
			try
			{
				self::TearDownSchema();
			}
			catch (\Throwable $tearDownEx)
			{
				error_log('Victual: cleanup after a failed PgsqlSchemaTestCase::setUpBeforeClass() itself failed: ' . $tearDownEx->getMessage());
			}

			throw $ex;
		}
	}

	public static function tearDownAfterClass(): void
	{
		self::TearDownSchema();
	}

	/**
	 * Drops this class's schema, if it got far enough to have one, and detaches
	 * DatabaseService from it - unconditionally, via finally, so a schema that fails to
	 * drop still does not leave a stale connection behind for the next class's
	 * setUpBeforeClass() to find. Shared by tearDownAfterClass() and setUpBeforeClass()'s
	 * own catch block, which needs exactly the same cleanup a normal teardown does.
	 */
	private static function TearDownSchema(): void
	{
		try
		{
			if (self::$SchemaConnection !== null)
			{
				self::$SchemaConnection->exec('DROP SCHEMA ' . self::$SchemaName . ' CASCADE');
			}
		}
		finally
		{
			(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, null);
			(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

			self::$SchemaConnection = null;
			self::$SchemaName = '';
		}
	}

	/**
	 * Clears every process-global cache that holds state bound to a schema or connection,
	 * so a new test class does not inherit anything left over from whichever class ran
	 * before it in this PHPUnit process (see the class docblock). Issue #533.
	 *
	 * Every entry recreates rather than repoints: BaseService::ResetInstancesForTest()
	 * drops cached service instances from the array entirely, so the next GetInstance()
	 * call constructs a fresh one against the connection just installed above, rather than
	 * some caller reaching into an existing instance's own fields - its other instance
	 * state may also belong to the old schema, and reaching in field-by-field for every
	 * field that might matter is the workaround this method centralizes away from (see the
	 * history of tests/Pgsql/ComposedOperationAtomicityTest.php's own setUpBeforeClass()).
	 *
	 * Three of these are not BaseService subclasses, so clearing $Instances above cannot
	 * reach them either: BaseApiController is a controller, DatabaseService is what the
	 * instances above are themselves constructed from, and FileStorage is an unrelated
	 * static singleton one class outside the BaseService hierarchy with the same shape of
	 * hazard (see FileStorage::ResetInstanceForTest()).
	 *
	 * Protected rather than private: MigrationRunnerAtomicityTest overrides
	 * setUpBeforeClass() entirely (it builds its own disposable schemas per test method
	 * rather than one migrated schema per class - see that class's docblock) and so never
	 * calls this method's caller. It calls this directly, itself, once its own connection
	 * is installed.
	 */
	protected static function ResetSchemaBoundState(): void
	{
		BaseService::ResetInstancesForTest();

		// Static caches declared directly on a BaseService subclass rather than on the
		// instance BaseService::$Instances caches - clearing that array does not touch
		// these; a freshly constructed instance would still read the stale array. Named
		// ResetCachesForTest() rather than ResetInstancesForTest() precisely so a call
		// through one of these class names cannot be misread as also clearing
		// BaseService::$Instances - it does not, and the call above already did.
		LocalizationService::ResetInstancesForTest();
		UsersService::ResetCachesForTest();
		FieldPolicy::ResetCachesForTest();
		DatabaseMigrationService::ResetCachesForTest();

		// Outside the BaseService hierarchy entirely.
		BaseApiController::ResetColumnTypeCacheForTest();
		FileStorage::ResetInstanceForTest();

		// The dirty-data flag, the before-outermost-commit listeners, and the cached
		// dialect (whose PostgresDialect implementation holds its own pending-change flag
		// as instance state, only cleared by recreating the dialect object that owns it).
		DatabaseService::ResetForTest();
	}

	protected static function Schema(): string
	{
		return self::$SchemaName;
	}

	protected static function Pdo(): PDO
	{
		return self::$SchemaConnection;
	}

	/**
	 * Boots the application configuration exactly once per process - idempotent so that
	 * every test class sharing the main PHPUnit process can call it from its own
	 * setUpBeforeClass() without a "constant already defined" fatal on the second one.
	 *
	 * A scenario that needs a VICTUAL_* constant this does not set (VICTUAL_DEFAULT_ROLES,
	 * a caller identity other than 9000) runs in a fresh process instead - see
	 * tests/Pgsql/rbac-subprocess-helper.php - and defines it before calling this.
	 */
	protected static function Boot(): void
	{
		if (defined('VICTUAL_IS_EMBEDDED_INSTALL'))
		{
			return;
		}

		define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
		define('VICTUAL_IS_EMBEDDED_INSTALL', false);
		define('VICTUAL_LOCALE', 'en');
		define('VICTUAL_AUTHENTICATED', true);

		if (!defined('VICTUAL_USER_ID'))
		{
			define('VICTUAL_USER_ID', 9000);
		}

		define('VICTUAL_USER_USERNAME', 'phpunit-caller');

		if (!defined('VICTUAL_USER_PICTURE_FILE_NAME'))
		{
			define('VICTUAL_USER_PICTURE_FILE_NAME', null);
		}

		require_once VICTUAL_DATAPATH . '/config.php';
		require_once VICTUAL_ROOT_PATH . '/config-dist.php';
	}

	private static function Connect(): PDO
	{
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');

		return new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	}
}
