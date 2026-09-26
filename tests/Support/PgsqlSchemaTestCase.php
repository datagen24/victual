<?php

namespace Victual\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Services\LocalizationService;

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

		// Reset process-global singleton instance caches so a new test class does not
		// reach a previous test class's dropped schema through cached service instances
		BaseService::ResetInstancesForTest();
		LocalizationService::ResetInstancesForTest();

		self::$SchemaName = 'phpunit_' . bin2hex(random_bytes(8));

		$pdo = self::Connect();
		$pdo->exec('CREATE SCHEMA ' . self::$SchemaName);
		$pdo->exec('SET search_path TO ' . self::$SchemaName . ', public');

		// DatabaseService::GetDbConnectionRaw() is bypassed entirely (the reflection below
		// hands it a connection instead of letting it call CreateConnection()), so what
		// CreateConnection()'s caller normally gets for free - OnConnected()'s session
		// time zone and the changed-time bootstrap table - has to be asked for here.
		DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		self::$SchemaConnection = $pdo;

		DatabaseMigrationService::GetInstance()->MigrateDatabase();
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$SchemaConnection !== null)
		{
			self::$SchemaConnection->exec('DROP SCHEMA ' . self::$SchemaName . ' CASCADE');
		}

		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, null);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		self::$SchemaConnection = null;
		self::$SchemaName = '';
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
