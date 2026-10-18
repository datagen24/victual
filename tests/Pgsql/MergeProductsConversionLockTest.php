<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PDOException;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #930: StockService::MergeProducts() checks that the kept and removed products' effective
 * conversions agree (issue #901), inside a savepoint it rolls back, and then merges. A conversion
 * written by another connection between the check and the merge was not seen by the check, so a
 * conflict it introduced was not refused. The merge now blocks every conversion write until it
 * commits (DatabaseDialect::LockQuantityUnitConversionWrites()).
 *
 * Two schedules, each on two connections. In the first, a conversion that makes the merge a
 * conflict is written but not yet committed when the merge starts: the merge must wait for it,
 * then refuse. Before the fix the merge did not see the uncommitted row and went through. In the
 * second, a merge's transaction is still open: a conversion write, here to a global row, must
 * wait for it.
 */
class MergeProductsConversionLockTest extends PgsqlSchemaTestCase
{
	private const USER = 9420;
	private const WAIT_SECONDS = 15;

	private static PDO $db;
	private static ?PDO $other = null;
	private static int $location;

	/** A second connection, the recipe user and a location. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		// Issue #533: reset the per-class service singletons built against an earlier schema.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$other = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		self::$other->exec('SET search_path TO ' . self::Schema() . ', public');

		self::$db->exec('INSERT INTO users (id, username, password) VALUES (' . self::USER . ", 'mpcl-user', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER . ", id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME')");
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('MPCL shelf') RETURNING id")->fetchColumn();
	}

	/** Closes the second connection. */
	public static function tearDownAfterClass(): void
	{
		self::$other = null;
		parent::tearDownAfterClass();
	}

	/** A quantity unit with a unique name; returns its id. */
	private static function unit(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
		$statement->execute([$name . ' ' . bin2hex(random_bytes(3)), $name . 's ' . bin2hex(random_bytes(3))]);

		return (int)$statement->fetchColumn();
	}

	/** A product stocked, bought and priced in $stockUnit; returns its id. */
	private static function product(string $name, int $stockUnit): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name . ' ' . bin2hex(random_bytes(3)), self::$location, $stockUnit, $stockUnit, $stockUnit, $stockUnit]);

		return (int)$statement->fetchColumn();
	}

	/** Inserts a conversion on $pdo; a global one when $productId is null. */
	private static function conversion(PDO $pdo, int $from, int $to, float $factor, ?int $productId): void
	{
		$pdo->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)')->execute([$from, $to, $factor, $productId]);
	}

	/**
	 * Issue #901's example without its conflict: the removed product converts a box to 30 tablets,
	 * the kept product has no box conversion and gains that one, and a consumption recipe line
	 * holds the removed product in boxes. On its own this merge goes through.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int} kept product, removed product, box, tablet
	 */
	private static function fixture(): array
	{
		$box = self::unit('MPCL box');
		$tablet = self::unit('MPCL tablet');
		$keep = self::product('MPCL keep', $tablet);
		$remove = self::product('MPCL remove', $tablet);
		self::conversion(self::$db, $box, $tablet, 30, $remove);
		StockService::GetInstance()->AddProduct($keep, 100, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		StockService::GetInstance()->AddProduct($remove, 60, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		ConsumptionRecipeService::GetInstance()->CreateRecipe('MPCL recipe ' . bin2hex(random_bytes(3)), null, [['product_id' => $remove, 'amount' => 1, 'qu_id' => $box]], self::USER);

		return [$keep, $remove, $box, $tablet];
	}

	/** Starts the merge in its own process and returns [process, pipes, backend pid] once it has been released. */
	private function startMerge(int $keep, int $remove): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$spec = ['keep' => $keep, 'remove' => $remove, 'lock_timeout_ms' => self::WAIT_SECONDS * 2000];
		$process = proc_open([PHP_BINARY, __DIR__ . '/merge-products-subprocess-helper.php', base64_encode(json_encode($spec))],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

		$ready = (string)fgets($pipes[1]);
		self::assertStringStartsWith('ready ', $ready, 'the helper connected');
		fwrite($pipes[0], "go\n");
		fclose($pipes[0]);

		return [$process, $pipes, (int)substr($ready, 6)];
	}

	/** Waits for the merge process and returns its JSON result. */
	private function finishMerge(array $handle): array
	{
		[$process, $pipes] = $handle;
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$decoded = json_decode($output, true);
		self::assertIsArray($decoded, "the helper printed no JSON. stdout: $output stderr: $errors");

		return $decoded;
	}

	/**
	 * Whether backend $pid is waiting for a lock on quantity_unit_conversions itself, polling
	 * until it is or until the merge has finished.
	 */
	private function waitsOnConversionsTable(int $pid): bool
	{
		$deadline = microtime(true) + self::WAIT_SECONDS;
		while (microtime(true) < $deadline)
		{
			$waiting = self::$other->query("SELECT count(*) FROM pg_locks WHERE pid = $pid AND NOT granted AND locktype = 'relation' AND relation = 'quantity_unit_conversions'::regclass")->fetchColumn();
			if ((int)$waiting > 0)
			{
				return true;
			}
			if ((int)self::$other->query("SELECT count(*) FROM pg_stat_activity WHERE pid = $pid")->fetchColumn() === 0)
			{
				return false;
			}
			usleep(20000);
		}

		return false;
	}

	/**
	 * A conversion that makes the merge a conflict (the kept product's own box is 25 tablets) is
	 * written but not committed when the merge starts. The merge waits for it and then refuses,
	 * with both products and the removed product's stock unchanged.
	 */
	public function testAMergeWaitsForAnUncommittedConversionAndThenRefusesTheConflictItMakes(): void
	{
		[$keep, $remove, $box, $tablet] = self::fixture();

		self::$other->beginTransaction();
		self::conversion(self::$other, $box, $tablet, 25, $keep);

		$merge = $this->startMerge($keep, $remove);
		try
		{
			$waited = $this->waitsOnConversionsTable($merge[2]);
		}
		finally
		{
			self::$other->commit();
		}
		$result = $this->finishMerge($merge);

		self::assertTrue($waited, 'the merge waited for the conversion write to finish: ' . json_encode($result));
		self::assertFalse($result['ok'], 'and then refused the conflict it read');
		self::assertStringContainsString('Cannot merge', $result['message']);
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM products WHERE id = $remove")->fetchColumn(), 'the removed product still exists');
		self::assertEqualsWithDelta(60.0, (float)self::$db->query("SELECT sum(amount) FROM stock WHERE product_id = $remove")->fetchColumn(), 1e-9, 'with its stock');
	}

	/**
	 * While a merge's transaction is open, a conversion write on another connection, here an
	 * edit of a global row, waits for it rather than landing between the merge's check and its
	 * writes.
	 */
	public function testAConversionWriteWaitsForAnOpenMerge(): void
	{
		[$keep, $remove] = self::fixture();
		$from = self::unit('MPCL pack');
		$to = self::unit('MPCL piece');
		self::conversion(self::$db, $from, $to, 4, null);

		self::$db->beginTransaction();
		try
		{
			StockService::GetInstance()->MergeProducts($keep, $remove);

			self::$other->exec("SET lock_timeout = '300ms'");
			try
			{
				self::$other->prepare('UPDATE quantity_unit_conversions SET factor = 5 WHERE product_id IS NULL AND from_qu_id = ? AND to_qu_id = ?')->execute([$from, $to]);
				self::fail('the conversion write went through while the merge was open');
			}
			catch (PDOException $ex)
			{
				self::assertSame('55P03', (string)$ex->getCode(), 'the write timed out waiting for the merge: ' . $ex->getMessage());
			}
			finally
			{
				self::$other->exec('RESET lock_timeout');
			}
		}
		finally
		{
			self::$db->rollBack();
		}
	}
}
