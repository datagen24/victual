<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PDOException;
use Victual\Services\ApiKeyService;
use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * Issue #923: migration 0312's chores_consume_product_on_execution_check, CHECK
 * (consume_product_on_execution IN (0, 1)). Maintainer decision 2026-10-13: constrain the
 * flag to 0 and 1, keep the column's nullability as it is (NOT NULL, which the constraint
 * leaves alone), and make the upgrade preflight report incompatible rows and refuse without
 * rewriting their values.
 *
 * The preflight half follows StockAmountMigrationTest: an app upgrade runs the migration over
 * live data, so the test drops the constraint and the migrations row, seeds offenders, and
 * calls MigrateDatabase(). The legacy-import half is ImportChoreConsumeFlagTest.
 */
class ChoreConsumeFlagCheckTest extends PgsqlSchemaTestCase
{
	private const CONSTRAINT = 'chores_consume_product_on_execution_check';
	private const USER_ID = 9230;

	private static PDO $db;
	private static string $apiKey;

	/** Creates an ADMIN user with an API key for the HTTP tests. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec('INSERT INTO users(id, username, password) VALUES (' . self::USER_ID . ", 'chore-flag-user', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . " , id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), self::USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT]);
	}

	/** Runs the pending migrations so each test starts from a fully migrated schema. */
	protected function setUp(): void
	{
		// Every test starts from, and the preflight tests end in, a fully migrated schema.
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
	}

	/** Returns whether the consume_product_on_execution check constraint exists on chores. */
	private static function constraintExists(): bool
	{
		return (bool)self::$db->query("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='chores'::regclass AND conname='" . self::CONSTRAINT . "')")->fetchColumn();
	}

	/**
	 * Inserts a manually scheduled chore with the given consume_product_on_execution value and
	 * returns its id.
	 */
	private static function chore(string $name, int $flag): int
	{
		$statement = self::$db->prepare("INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES (?, 'manually', ?) RETURNING id");
		$statement->execute([$name, $flag]);
		return (int)$statement->fetchColumn();
	}

	/** Returns the chore's consume_product_on_execution value. */
	private static function flagOf(int $id): int
	{
		return (int)self::$db->query('SELECT consume_product_on_execution FROM chores WHERE id=' . $id)->fetchColumn();
	}

	/**
	 * Executes the statement and returns the SQLSTATE of the PDOException it raises, or
	 * "accepted" when it succeeds.
	 */
	private static function sqlState(string $sql): string
	{
		try { self::$db->exec($sql); }
		catch (PDOException $ex) { return (string)$ex->getCode(); }
		return 'accepted';
	}

	/** Proves that the constraint is validated and that migration 312 is recorded. */
	public function testConstraintExistsAndIsValidated(): void
	{
		self::assertTrue((bool)self::$db->query("SELECT convalidated FROM pg_constraint WHERE conrelid='chores'::regclass AND conname='" . self::CONSTRAINT . "'")->fetchColumn());
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM migrations WHERE migration=312')->fetchColumn());
	}

	/** Proves that 0 and 1 can be inserted and updated into each other. */
	public function testZeroAndOneAreAccepted(): void
	{
		$zero = self::chore('flag zero', 0);
		$one = self::chore('flag one', 1);
		self::assertSame(0, self::flagOf($zero));
		self::assertSame(1, self::flagOf($one));

		self::$db->exec('UPDATE chores SET consume_product_on_execution=1 WHERE id=' . $zero);
		self::$db->exec('UPDATE chores SET consume_product_on_execution=0 WHERE id=' . $one);
		self::assertSame(1, self::flagOf($zero));
		self::assertSame(0, self::flagOf($one));
	}

	/**
	 * Proves that 2, -1 and 255 are refused with SQLSTATE 23514 on both insert and update, and
	 * the existing row keeps its value.
	 */
	public function testOtherValuesAreRefusedOnInsertAndUpdateAndRowsAreUnchanged(): void
	{
		$id = self::chore('flag kept', 1);
		foreach ([2, -1, 255] as $value)
		{
			self::assertSame('23514', self::sqlState("INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('flag bad $value', 'manually', $value)"));
			self::assertSame('23514', self::sqlState("UPDATE chores SET consume_product_on_execution=$value WHERE id=$id"));
		}
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM chores WHERE name LIKE 'flag bad %'")->fetchColumn());
		self::assertSame(1, self::flagOf($id));
	}

	/**
	 * Proves that NULL is still refused by the column's NOT NULL (SQLSTATE 23502) rather than
	 * passing the check constraint.
	 */
	public function testNullabilityIsUnchanged(): void
	{
		// The constraint alone would let a NULL through (NULL IN (0, 1) is not false); the
		// column's own NOT NULL, which migration 0312 leaves alone, is what refuses it.
		self::assertSame('23502', self::sqlState("INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('flag null', 'manually', NULL)"));
		self::assertSame('NO', self::$db->query("SELECT is_nullable FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='chores' AND column_name='consume_product_on_execution'")->fetchColumn());
	}

	/**
	 * @return array{status: int, body: string}
	 */
	private static function send(string $method, string $path, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey]];
		if ($body !== null) { $spec['body'] = $body; }

		$env = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
		]);
		$finished = Subprocess::Completed(Subprocess::run([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], $env));
		$output = $finished['stdout'];
		$errors = $finished['stderr'];

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");
		return $result;
	}

	/**
	 * Proves that the generic API answers 400 with the standard database-rejection message for
	 * an out-of-range update or create, changes nothing, leaves no transaction open, and still
	 * accepts 0.
	 */
	public function testGenericApiRefusesOtherValuesWithTheStandardRefusalAndChangesNothing(): void
	{
		$id = self::chore('flag api', 1);
		$before = self::$db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		$update = self::send('PUT', '/api/objects/chores/' . $id, ['consume_product_on_execution' => 2]);
		self::assertSame(400, $update['status'], $update['body']);
		self::assertStringContainsString('The database rejected this request - check that every value it carries suits the field it is for', $update['body']);

		$create = self::send('POST', '/api/objects/chores', ['name' => 'flag api created', 'period_type' => 'manually', 'consume_product_on_execution' => -1]);
		self::assertSame(400, $create['status'], $create['body']);
		self::assertStringContainsString('The database rejected this request', $create['body']);

		self::assertSame($before, self::$db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
		self::assertFalse(self::$db->inTransaction());

		$accepted = self::send('PUT', '/api/objects/chores/' . $id, ['consume_product_on_execution' => 0]);
		self::assertSame(204, $accepted['status'], $accepted['body']);
		self::assertSame(0, self::flagOf($id));
	}

	/**
	 * Proves that with the constraint dropped and chores holding other values, the upgrade
	 * refuses without rewriting any row, and can be retried once the rows are corrected.
	 */
	public function testOffendingRowsRefuseTheUpgradeWithoutBeingRewrittenAndCanBeRetried(): void
	{
		$db = self::$db;
		$db->exec('ALTER TABLE chores DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT . '; DELETE FROM migrations WHERE migration=312');
		$good0 = self::chore('upgrade good 0', 0);
		$good1 = self::chore('upgrade good 1', 1);
		$two = self::chore('upgrade two', 2);
		$minus = self::chore('upgrade minus one', -1);
		for ($i = 0; $i < 12; $i++) { self::chore('upgrade many ' . $i, 7); }
		$before = $db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			self::fail('The upgrade must refuse while a chore holds another value');
		}
		catch (PDOException $ex)
		{
			$message = $ex->getMessage();
			self::assertStringContainsString('14 chores have a consume_product_on_execution that is not 0 or 1', $message);
			self::assertStringContainsString('No chore has been changed', $message);
			self::assertStringContainsString('Choose an explicit repair and rerun migration', $message);
			self::assertStringContainsString('SELECT id, name, consume_product_on_execution FROM chores WHERE consume_product_on_execution NOT IN (0, 1) ORDER BY id', $message);
			self::assertStringContainsString("($two, upgrade two, 2)", $message);
			self::assertStringContainsString("($minus, upgrade minus one, -1)", $message);
			self::assertStringNotContainsString("$good0, upgrade good 0", $message);
			self::assertSame(10, substr_count($message, ', upgrade '), 'the sample is limited to ten rows');
		}

		self::assertFalse($db->inTransaction());
		self::assertSame($before, $db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'no value was rewritten');
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=312')->fetchColumn());
		self::assertFalse(self::constraintExists());
		self::assertSame(1, self::flagOf($good1));

		$db->exec('UPDATE chores SET consume_product_on_execution=1 WHERE consume_product_on_execution NOT IN (0, 1)');
		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertTrue(self::constraintExists());
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=312')->fetchColumn());
		self::assertSame(1, self::flagOf($two));

		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=312')->fetchColumn());
	}

	/**
	 * ADR-0029's lock-timeout case: ChoresService::LockChore() holds a row lock (SELECT ... FOR
	 * UPDATE) while a chore is tracked, and the migration's ACCESS EXCLUSIVE lock must give up
	 * after lock_timeout (5 s) instead of queueing behind it, leaving nothing half done.
	 */
	public function testMigrationGivesUpOnALockedChoreAndRollsBackCleanly(): void
	{
		$db = self::$db;
		$db->exec('ALTER TABLE chores DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT . '; DELETE FROM migrations WHERE migration=312');
		$id = self::chore('lock held', 1);
		$before = $db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		$other = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$other->exec('SET search_path TO ' . self::Schema() . ', public');
		$other->beginTransaction();

		try
		{
			$other->query('SELECT id FROM chores WHERE id = ' . $id . ' FOR UPDATE')->fetchAll();
			$start = microtime(true);
			try
			{
				DatabaseMigrationService::GetInstance()->MigrateDatabase();
				self::fail('The migration must give up while a chore row is locked');
			}
			catch (PDOException $ex)
			{
				self::assertSame('55P03', (string)$ex->getCode(), $ex->getMessage());
				self::assertStringContainsString('lock timeout', $ex->getMessage());
			}
			self::assertLessThan(20, microtime(true) - $start, 'the wait is bounded by lock_timeout');

			self::assertFalse($db->inTransaction());
			self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=312')->fetchColumn());
			self::assertFalse(self::constraintExists());
		}
		finally
		{
			// The schema must end up migrated for the tests after this one, whatever failed above.
			$other->rollBack();
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
		}

		self::assertSame($before, $db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
		self::assertTrue(self::constraintExists());
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=312')->fetchColumn());
	}

	/**
	 * Proves that when every chore holds 0 or 1 the upgrade adds the constraint, records
	 * migration 312 and leaves the rows unchanged.
	 */
	public function testUpgradeWithOnlyZeroAndOneAddsTheConstraintAndKeepsTheRows(): void
	{
		$db = self::$db;
		$db->exec('ALTER TABLE chores DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT . '; DELETE FROM migrations WHERE migration=312');
		self::chore('clean 0', 0);
		self::chore('clean 1', 1);
		$before = $db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertTrue(self::constraintExists());
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=312')->fetchColumn());
		self::assertSame($before, $db->query('SELECT * FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
	}
}
