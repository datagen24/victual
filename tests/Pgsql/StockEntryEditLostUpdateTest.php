<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\Database\PostgresDialect;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * A lost update found in review of issues #519/#524's partial-update fix for
 * PUT /api/stock/entry/{entryId}: an omitted field used to take its default from a read
 * StockApiController::EditStockEntry() (the HTTP handler) made before StockService's own
 * lock on the product, while StockService::EditStockEntry() writes it after taking that
 * lock and re-reading the row. A booking that opened the entry, moved it to another
 * location and stamped its opened date - committing while the edit's request was already
 * in flight, queued behind the lock - was silently reverted the instant the queued partial
 * edit ran, because the edit persisted the values the controller read before any of that
 * happened rather than the ones the service's own locked re-read would have seen.
 *
 * The fix moves "what does an omitted field keep" into StockService::EditStockEntry()
 * itself, resolved against the row it re-reads under LockProductStock() - see
 * StockService::KEEP_STORED_VALUE. This is the two-connection harness
 * StockConcurrencyTest.php uses for the same class of defect (issue #458): connection B
 * takes the advisory lock first and holds it while the subprocess under test queues behind
 * it; only once the subprocess is confirmed to be waiting does B commit the competing
 * change and release the lock, so the subprocess resumes with B's write already visible.
 *
 * Kept in its own file (StockConcurrencyTest.php is a large shared file other agents were
 * also working near the end of) and appended as the last <file> of the stockconcurrency
 * testsuite, per this session's assignment.
 */
class StockEntryEditLostUpdateTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static string $apiKey = '';
	private static int $locationAId;
	private static int $locationBId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9800, 'stock-edit-lost-update', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. "SELECT 9800, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, 9800, now() + interval '30 days', ?)");
		$stmt->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);

		self::$locationAId = (int)self::$db->query("INSERT INTO locations (name) VALUES ('Lost Update A') RETURNING id")->fetchColumn();
		self::$locationBId = (int)self::$db->query("INSERT INTO locations (name) VALUES ('Lost Update B') RETURNING id")->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Helpers - mirroring StockConcurrencyTest.php's own (private to that class, so
	// duplicated here rather than shared; see that file's class docblock for the pattern).
	// ------------------------------------------------------------------------------

	private static function insertProductAndStock(): array
	{
		$productStatement = self::$db->prepare(
			'INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (?, ?, 2, 2) RETURNING id'
		);
		$productStatement->execute(['Lost Update Product ' . bin2hex(random_bytes(4)), self::$locationAId]);
		$productId = (int)$productStatement->fetchColumn();

		$stockStatement = self::$db->prepare(
			'INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, open, location_id) '
			. "VALUES (?, 2, '2030-06-01', '2026-01-15', ?, 0, ?) RETURNING id"
		);
		$stockStatement->execute([$productId, 'lost-update-' . bin2hex(random_bytes(4)), self::$locationAId]);
		$stockId = (int)$stockStatement->fetchColumn();

		return [$productId, $stockId];
	}

	private static function stockRow(int $entryId): array
	{
		$statement = self::$db->prepare('SELECT * FROM stock WHERE id = ?');
		$statement->execute([$entryId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($row, "stock row $entryId must exist");

		return $row;
	}

	/** A second, independent PDO connection into the same test schema ("connection B"). */
	private static function secondConnection(): PDO
	{
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');

		return $pdo;
	}

	/**
	 * Blocks until some backend is waiting, specifically, on the stock booking advisory
	 * lock for $productId - the same readiness check StockConcurrencyTest.php uses, scoped
	 * to one product so a concurrent test's waiter cannot be mistaken for this one's.
	 */
	private static function waitForAdvisoryWaiter(int $productId, float $timeoutSeconds = 10.0): void
	{
		$waiterCheck = self::$db->prepare(
			'SELECT pid FROM pg_locks WHERE locktype = \'advisory\' AND NOT granted AND classid = ? AND objid = ? LIMIT 1'
		);
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$waiterCheck->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);
			if ($waiterCheck->fetchColumn() !== false)
			{
				return;
			}
			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail("Timed out waiting for a backend to block on the stock booking advisory lock for product $productId");
	}

	/** Starts request-subprocess-helper.php without waiting for it to finish. @return array{0: resource, 1: array} */
	private static function startSubprocess(string $method, string $path, ?array $body = null): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey], 'body' => $body],
			fn ($value) => $value !== null
		);
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

		return [$process, $pipes];
	}

	/** @return array{status: int, body: string} */
	private static function finishSubprocess(array $processAndPipes): array
	{
		[$process, $pipes] = $processAndPipes;
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");

		return $result;
	}

	// ------------------------------------------------------------------------------
	// The scenario
	// ------------------------------------------------------------------------------

	/**
	 * Before the fix: the partial PUT (amount only) reverts open, opened_date and
	 * location_id to what they were before connection B's booking committed, because the
	 * controller read those three fields, unlocked, before the subprocess ever queued
	 * behind B's lock. Run against a working copy with StockApiController::EditStockEntry()
	 * reading its defaults from StockService::GetStockEntry() again (the shape this fix
	 * replaced) to see this test fail: the response is 200, but stockRow()['open'] is '0'
	 * and ['location_id'] is locationAId - connection B's committed open=1/location move
	 * is gone.
	 */
	public function testAConcurrentBookingSurvivesAPartialEditQueuedBehindItsLock(): void
	{
		[$productId, $entryId] = self::insertProductAndStock();

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		// Queued behind B: a partial edit naming only amount, so open, location_id and
		// opened_date all come from whatever EditStockEntry() decides "the entry's current
		// value" means when it actually gets to write.
		$subprocess = self::startSubprocess('PUT', "/api/stock/entry/$entryId", ['amount' => 5]);

		self::waitForAdvisoryWaiter($productId);

		// Connection B's competing booking: opens the entry, stamps its opened date, and
		// moves it to another location - three fields the queued partial edit never
		// mentions - then commits, releasing the lock the subprocess is queued behind.
		$connB->prepare('UPDATE stock SET open = 1, opened_date = ?, location_id = ? WHERE id = ?')
			->execute(['2026-02-01', self::$locationBId, $entryId]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(200, $result['status'], "the partial edit must still succeed: {$result['body']}");

		$after = self::stockRow($entryId);
		self::assertSame(5.0, (float)$after['amount'], 'the edit\'s own field is applied');
		self::assertSame(1, (int)$after['open'], "connection B's concurrent open must survive - not be reverted to closed");
		self::assertSame('2026-02-01', $after['opened_date'], "connection B's concurrent opened date must survive");
		self::assertSame(self::$locationBId, (int)$after['location_id'], "connection B's concurrent location move must survive - not be reverted to A");
	}
}
