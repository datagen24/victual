<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\Database\PostgresDialect;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * Lock order of StockService::MergeProducts() against the chore paths (ADR-0040 rule 8: row locks
 * first, then the ascending product advisory locks). TrackChore() and UndoChoreExecution() lock the
 * chore row FOR UPDATE and then take product locks through ConsumeProduct()/UndoTransaction().
 * MergeProducts() took the product locks first and wrote `chores` afterwards, so a merge and a chore
 * execution on the removed product formed a wait cycle.
 *
 * The schedule: connection B plays a chore execution that holds the chore row FOR UPDATE and is about to
 * ask for a product lock. The merge runs in its own process and connection and cannot finish. The
 * question is what it holds while it waits. Before the fix it held both product locks, so B's next step
 * would have waited on the merge while the merge waited on B. After the fix it waits on the chore row and
 * holds no product lock.
 */
class MergeProductsChoreLockOrderTest extends PgsqlSchemaTestCase
{
	private const WAIT_SECONDS = 15;

	private static PDO $db;
	private static ?PDO $other = null;
	private static int $unit;
	private static int $location;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$other = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		self::$other->exec('SET search_path TO ' . self::Schema() . ', public');

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('MPL unit') RETURNING id")->fetchColumn();
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('MPL shelf') RETURNING id")->fetchColumn();
	}

	public static function tearDownAfterClass(): void
	{
		self::$other = null;
		parent::tearDownAfterClass();
	}

	private static function product(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name, self::$location, self::$unit, self::$unit, self::$unit, self::$unit]);

		return (int)$statement->fetchColumn();
	}

	/** @return array{0: int, 1: int, 2: int} kept product, removed product (with stock), chore consuming the removed product */
	private static function fixture(): array
	{
		$keep = self::product('MPL keep ' . bin2hex(random_bytes(3)));
		$remove = self::product('MPL remove ' . bin2hex(random_bytes(3)));
		StockService::GetInstance()->AddProduct($keep, 5, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 1.0, self::$location);
		StockService::GetInstance()->AddProduct($remove, 10, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 2.0, self::$location);
		$chore = (int)self::$db->query("INSERT INTO chores (name, period_type, consume_product_on_execution, product_id, product_amount) VALUES ('MPL chore " . bin2hex(random_bytes(3)) . "', 'manually', 1, $remove, 3) RETURNING id")->fetchColumn();

		return [$keep, $remove, $chore];
	}

	private static function state(int $keep, int $remove, int $chore): array
	{
		return [
			'products' => self::$db->query("SELECT id FROM products WHERE id IN ($keep, $remove) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
			'stock' => self::$db->query("SELECT * FROM stock WHERE product_id IN ($keep, $remove) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query("SELECT * FROM stock_log WHERE product_id IN ($keep, $remove) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
			'chore' => self::$db->query("SELECT id, product_id, product_amount FROM chores WHERE id = $chore")->fetchAll(PDO::FETCH_ASSOC),
		];
	}

	/** Starts the merge in its own process and returns [Subprocess, backend pid] once it has been released. */
	private function startMerge(int $keep, int $remove, int $lockTimeoutMs): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$spec = ['keep' => $keep, 'remove' => $remove, 'lock_timeout_ms' => $lockTimeoutMs];
		$process = Subprocess::start([PHP_BINARY, __DIR__ . '/merge-products-subprocess-helper.php', base64_encode(json_encode($spec))], $env, null, true);

		$ready = (string)$process->readLine();
		self::assertStringStartsWith('ready ', $ready, 'the helper connected');
		$process->write("go\n");
		$process->closeStdin();

		return [$process, (int)substr($ready, 6)];
	}

	private function finishMerge(array $handle): array
	{
		$finished = Subprocess::Completed($handle[0]->finish());
		$output = $finished['stdout'];
		$errors = $finished['stderr'];
		$decoded = json_decode($output, true);
		self::assertIsArray($decoded, "the helper printed no JSON. stdout: $output stderr: $errors");

		return $decoded;
	}

	private function awaitBlocked(int $pid): void
	{
		$deadline = microtime(true) + self::WAIT_SECONDS;
		while (microtime(true) < $deadline)
		{
			// Inside a transaction PostgreSQL serves pg_stat_activity from a cached snapshot, so clear it before each look.
			self::$other->query('SELECT pg_stat_clear_snapshot()');
			if (self::$other->query("SELECT count(*) FROM pg_stat_activity WHERE pid = $pid AND wait_event_type = 'Lock'")->fetchColumn() > 0)
			{
				return;
			}
			usleep(20000);
		}

		self::fail("the merge (pid $pid) was not waiting on a lock within " . self::WAIT_SECONDS . ' s');
	}

	public function testAMergeWaitingOnAChoreRowHoldsNoProductLockAndCompletesWhenItIsReleased(): void
	{
		[$keep, $remove, $chore] = self::fixture();
		$before = self::state($keep, $remove, $chore);
		$handle = null;

		try
		{
			// B: a chore execution, which holds the chore row before it asks for any product lock.
			self::$other->beginTransaction();
			self::$other->query("SELECT id FROM chores WHERE id = $chore FOR UPDATE")->fetchAll();
			$otherPid = (int)self::$other->query('SELECT pg_backend_pid()')->fetchColumn();

			$handle = $this->startMerge($keep, $remove, 30000);
			$mergePid = $handle[1];
			$this->awaitBlocked($mergePid);

			$blockers = self::$other->query("SELECT pg_blocking_pids($mergePid)")->fetchColumn();
			self::assertSame('{' . $otherPid . '}', $blockers, 'The merge is waiting on the chore execution');

			$statement = self::$other->prepare("SELECT objid FROM pg_locks WHERE pid = ? AND locktype = 'advisory' AND classid = ? AND granted ORDER BY objid");
			$statement->execute([$mergePid, PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS]);
			self::assertSame([], $statement->fetchAll(PDO::FETCH_COLUMN), 'Then: the merge holds no product lock while it waits on the chore row (ADR-0040 rule 8: rows first)');

			// B's next step. Were the merge to hold the lock, B would wait on it while it waits on B.
			$statement = self::$other->prepare('SELECT pg_try_advisory_xact_lock(?, ?)');
			$statement->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $remove]);
			self::assertTrue((bool)$statement->fetchColumn(), 'Then: the chore execution can take the product lock, so no wait cycle forms');
			self::assertSame($before, self::state($keep, $remove, $chore), 'Then: nothing has changed while the merge waits');

			self::$other->rollBack();
			$result = $this->finishMerge($handle);
			$handle = null;

			self::assertTrue($result['ok'], 'Then: once the chore row is released the merge completes: ' . ($result['message'] ?? ''));
			$after = self::state($keep, $remove, $chore);
			self::assertSame([['id' => $keep]], $after['products'], 'Then: the removed product is gone');
			self::assertSame($keep, (int)$after['chore'][0]['product_id'], 'Then: the chore was repointed to the kept product');
		}
		finally
		{
			if (self::$other->inTransaction())
			{
				self::$other->rollBack();
			}
			if ($handle !== null)
			{
				$handle[0]->abort();
			}
		}
	}

	public function testAMergeThatTimesOutOnAChoreRowChangesNothing(): void
	{
		[$keep, $remove, $chore] = self::fixture();
		$before = self::state($keep, $remove, $chore);
		$handle = null;

		try
		{
			self::$other->beginTransaction();
			self::$other->query("SELECT id FROM chores WHERE id = $chore FOR UPDATE")->fetchAll();

			$handle = $this->startMerge($keep, $remove, 300);
			$result = $this->finishMerge($handle);
			$handle = null;

			self::assertFalse($result['ok'], 'The merge cannot finish while the chore row is held');
			self::assertStringContainsString('lock timeout', strtolower($result['message']), 'It gives up on the lock timeout');
			self::assertSame($before, self::state($keep, $remove, $chore), 'Then: nothing changed');
		}
		finally
		{
			if (self::$other->inTransaction())
			{
				self::$other->rollBack();
			}
			if ($handle !== null)
			{
				$handle[0]->abort();
			}
		}

		self::assertSame($before, self::state($keep, $remove, $chore), 'Then: still nothing changed once the chore row is released');
	}
}
