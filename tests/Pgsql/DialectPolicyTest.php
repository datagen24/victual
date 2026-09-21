<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Controllers\Api\EInvalidApiQuery;
use Victual\Controllers\Users\EntityReadPolicy;
use Victual\Controllers\Users\User;
use Victual\Services\Database\DatabaseDialect;
use Victual\Services\Database\PostgresDialect;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Two small things nothing else asks: which entity a caller may read at all, and what a
 * configuration naming a retired engine is answered with.
 *
 * Both were left below the floor by a line or two each (`EntityReadPolicy` 5/7,
 * `DatabaseDialect` 22/30, `SqliteDialect` 34/47 on 2026-09-21), and in both cases the
 * uncovered lines are the refusals - which is the half worth having. ADR-0008 retired SQLite
 * as a runtime engine, and a retirement whose refusal path is untested is a retirement on
 * paper. `EntityReadPolicy`'s own comment says an entity absent from its table "throws rather
 * than reading", and fail-closed is a claim that only means something if something checks it.
 *
 * `DatabaseDialect::Create()` reads a constant, so each driver value needs its own process -
 * tests/Pgsql/dialect-subprocess-helper.php. The SQLite dialect is built there too, and only
 * through `DatabaseDialect::SQLITE_TOOLING_ENV`, which is the one way AGENTS.md permits one
 * to exist.
 */
class DialectPolicyTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'dialect-caller', 'fixture')");
	}

	private static function request()
	{
		return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api');
	}

	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000');
		self::$db->exec('DELETE FROM user_roles WHERE user_id = 9000');

		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');

		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	/**
	 * Runs the helper and returns what it printed.
	 */
	private static function helper(string $scenario, array $extraEnvironment = []): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');

		$environment = array_merge($inherited, [
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		], $extraEnvironment);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/dialect-subprocess-helper.php', $scenario],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		self::assertSame(0, $status, "the $scenario helper failed.\nstdout: $output\nstderr: $errors");

		$result = json_decode($output, true);
		self::assertIsArray($result, "the $scenario helper printed no JSON.\nstdout: $output\nstderr: $errors");

		return $result;
	}

	// ----- EntityReadPolicy ---------------------------------------------------------

	/**
	 * Covers() is what callers ask before Check(), so that an entity nobody has heard of is
	 * the 400 the generic entity routes document rather than an exception escaping
	 * HandleApiCall as a 500. The distinction is the class's own docblock.
	 */
	public function testAnEntityWithNoPolicyIsNotCovered(): void
	{
		self::assertTrue(EntityReadPolicy::Covers('products'), 'a listed entity is covered');
		self::assertTrue(EntityReadPolicy::Covers('userentity-groceries'),
			'and so is any user entity, by prefix');
		self::assertFalse(EntityReadPolicy::Covers('not_an_entity'),
			'an entity with no policy is not covered, so the caller answers 400 itself');
	}

	/**
	 * Fail-closed, asked of the whole table rather than of one example: an entity this
	 * policy does not know throws, it does not fall through to a read. The comment above
	 * the label rows calls this "fail-closed by this class's own rule".
	 */
	public function testAnEntityWithNoPolicyIsRefusedRatherThanRead(): void
	{
		self::grant([User::PERMISSION_ADMIN]);

		$this->expectException(EInvalidApiQuery::class);

		EntityReadPolicy::Check(self::request(), 'label_artifacts');
	}

	/**
	 * A user entity's rows are its owner's business and carry no entry in the table, so the
	 * policy returns before it would look for one. Asserted with the caller holding nothing
	 * at all, because a policy that checked some permission here would refuse.
	 */
	public function testAUserEntityIsCheckedWithoutAPermission(): void
	{
		self::grant([]);

		EntityReadPolicy::Check(self::request(), 'userentity-groceries');

		self::assertTrue(true, 'a user entity needs no leaf of its own');
	}

	/**
	 * The three outcomes the table actually encodes: a leaf that is required, a null that
	 * means "no leaf of its own", and the refusal when the leaf is missing.
	 */
	public function testTheTableIsAppliedLeafByLeaf(): void
	{
		self::grant([User::PERMISSION_STOCK_VIEW]);

		EntityReadPolicy::Check(self::request(), 'products');
		EntityReadPolicy::Check(self::request(), 'batteries');

		self::grant([]);

		// batteries maps to null, so it stays readable with nothing granted - that is what
		// the null means, and the control that keeps the refusal below from being vacuous.
		EntityReadPolicy::Check(self::request(), 'batteries');

		$refused = false;

		try
		{
			EntityReadPolicy::Check(self::request(), 'products');
		}
		catch (\Throwable $exception)
		{
			$refused = true;
		}

		self::assertTrue($refused, 'products needs STOCK_VIEW and is refused without it');
	}

	// ----- ADR-0008: SQLite is not a runtime engine ----------------------------------

	/**
	 * The default and only supported runtime driver. Checked in this process, where the
	 * constant is what an ordinary installation has.
	 */
	public function testThePostgresDialectIsWhatAnInstallationGets(): void
	{
		$dialect = DatabaseDialect::Create();

		self::assertInstanceOf(PostgresDialect::class, $dialect);
		self::assertSame('pgsql', $dialect->GetName());
		self::assertSame(['pgsql'], DatabaseDialect::RUNTIME_DRIVERS,
			'one runtime driver, which is what ADR-0008 decided');
		self::assertSame(['pgsql', 'sqlite'], DatabaseDialect::MIGRATION_DRIVERS,
			'and two migration drivers, because the SQLite line is frozen rather than deleted');
	}

	/**
	 * The environment variable is read on every call rather than memoized (ADR-0007 keeps
	 * process state about configuration out of this layer), and an empty value or a "0" is
	 * not a yes - otherwise a variable exported empty by a shell would turn the escape hatch
	 * on for a whole run.
	 */
	public function testTheToolingHatchIsOffUnlessItIsExplicitlyOn(): void
	{
		$original = getenv(DatabaseDialect::SQLITE_TOOLING_ENV);

		try
		{
			putenv(DatabaseDialect::SQLITE_TOOLING_ENV);
			self::assertFalse(DatabaseDialect::SqliteToolingIsPermitted(), 'unset is off');

			putenv(DatabaseDialect::SQLITE_TOOLING_ENV . '=');
			self::assertFalse(DatabaseDialect::SqliteToolingIsPermitted(), 'empty is off');

			putenv(DatabaseDialect::SQLITE_TOOLING_ENV . '=0');
			self::assertFalse(DatabaseDialect::SqliteToolingIsPermitted(), 'a zero is off');

			putenv(DatabaseDialect::SQLITE_TOOLING_ENV . '=1');
			self::assertTrue(DatabaseDialect::SqliteToolingIsPermitted(), 'and a one is on');
		}
		finally
		{
			if ($original === false)
			{
				putenv(DatabaseDialect::SQLITE_TOOLING_ENV);
			}
			else
			{
				putenv(DatabaseDialect::SQLITE_TOOLING_ENV . '=' . $original);
			}
		}
	}

	/**
	 * The retirement itself. A config.php still saying "sqlite" is refused, and the message
	 * names the way out rather than only the problem - which is the whole reason ADR-0008's
	 * retirement could land without stranding an existing installation.
	 */
	public function testASqliteConfigurationIsRefusedAndToldWhereToGo(): void
	{
		$result = self::helper('driver', [
			'VICTUAL_DB_DRIVER' => 'sqlite',
			DatabaseDialect::SQLITE_TOOLING_ENV => '',
		]);

		self::assertSame('sqlite', $result['driver']);
		self::assertFalse($result['tooling_permitted'], 'the escape hatch is off');
		self::assertFalse($result['create']['ok'], 'so no dialect is built');
		self::assertStringContainsString('ADR-0008', $result['create']['message']);
		self::assertStringContainsString('bin/victual-db-import', $result['create']['message'],
			'and the refusal names the command that moves the database across');
	}

	/**
	 * A driver value that is neither is refused too, naming what is supported. Without this
	 * the case above would be satisfied by a factory that refused everything but pgsql with
	 * the SQLite message.
	 */
	public function testAnUnsupportedDriverIsRefusedNamingWhatIsSupported(): void
	{
		$result = self::helper('driver', ['VICTUAL_DB_DRIVER' => 'mysql']);

		self::assertSame('mysql', $result['driver']);
		self::assertFalse($result['create']['ok']);
		self::assertStringContainsString('mysql', $result['create']['message']);
		self::assertStringContainsString('pgsql', $result['create']['message']);
		self::assertStringNotContainsString('ADR-0008', $result['create']['message'],
			'an unknown driver is not the retirement, and is not told about victual-db-import');
	}

	/**
	 * The one caller ADR-0008's option C keeps: the differential harness, which still builds
	 * a SQLite side and asks through the environment variable rather than a setting, so that
	 * it is not a way to run this fork.
	 */
	public function testTheDifferentialHarnessMayStillBuildASqliteDialect(): void
	{
		$result = self::helper('driver', [
			'VICTUAL_DB_DRIVER' => 'sqlite',
			DatabaseDialect::SQLITE_TOOLING_ENV => '1',
		]);

		self::assertTrue($result['tooling_permitted']);
		self::assertTrue($result['create']['ok'], 'the harness gets its dialect');
		self::assertSame('Victual\Services\Database\SqliteDialect', $result['create']['value']);
		self::assertSame('sqlite', $result['name']);
	}

	/**
	 * What that dialect answers, which is what the harness compares PostgreSQL against. The
	 * three functions it registers exist because SQLite has none of them natively, so a
	 * connection missing them makes every view that uses one fail.
	 */
	public function testTheSqliteDialectAnswersWhatTheHarnessComparesAgainst(): void
	{
		$result = self::helper('sqlite-behaviour', [
			'VICTUAL_DB_DRIVER' => 'sqlite',
			DatabaseDialect::SQLITE_TOOLING_ENV => '1',
		]);

		self::assertSame(1, $result['regexp'], 'REGEXP is backed by the mb_ereg callback');
		// Compared numerically rather than by identity: the helper's 2.0 comes back through
		// JSON, which has one number type, so a whole float arrives as an int.
		self::assertEquals(2, $result['ceiling'], 'and ceil() exists, which SQLite does not ship');
		self::assertSame('products.name REGEXP ?', $result['regexp_condition']);

		self::assertTrue($result['missing_table_threw'], 'a missing table is an error at all');
		self::assertTrue($result['missing_table_recognised'], 'and the dialect recognises it');
		self::assertFalse($result['syntax_error_recognised'],
			'a syntax error is HY000 too, and must not be read as a missing table');

		self::assertStringEndsWith('/victual.db', $result['db_file_path'],
			'an installation that is not a demo uses the plain file name');

		self::assertSame($result['expected_changed_time'], $result['set_changed_time'],
			'the changed time is the file modification time, and is rewindable');
		self::assertSame($result['set_changed_time'], $result['mark_changed_time'],
			'marking a change is a no-op on this engine, because the file stamp already is one');

		self::assertFalse($result['requires_change_tracking'],
			'so per-statement change tracking is unnecessary');
		self::assertTrue($result['supports_multi_statement_exec'],
			'and a .sql migration file may be one exec()');
	}
}
