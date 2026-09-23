<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\RecipesApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\ApiKeyService;
use Victual\Services\Database\PostgresDialect;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #458: every stock booking path locks the product it books, so a concurrent
 * booking or undo of the same product cannot interleave with the read-then-write
 * sequence that decides what to write. This is the two-connection harness the issue asked
 * for - none of the differential or coverage suites drive two live database connections
 * against the same booking at once.
 *
 * The pattern (issue's "deterministic pattern", not a sleep-based race): a second raw PDO
 * connection ("B") takes StockService's own advisory lock on the product first and holds
 * it inside an open transaction; the booking under test is then started in a real
 * subprocess (request-subprocess-helper.php, the same harness StockCoverageTest's
 * subprocess scenarios use), so it runs the real HTTP/middleware/controller stack and
 * blocks on the same lock; the test polls pg_stat_activity - a readiness check, not a
 * timing assumption - until that subprocess's backend is actually waiting on an advisory
 * lock; only then does B perform the competing write and commit, releasing the lock the
 * subprocess was queued behind. The subprocess then runs under the lock, sees B's
 * committed state, and is asserted against.
 */
class StockConcurrencyTest extends PgsqlSchemaTestCase
{
	private const FAR_FUTURE_DATE = '2035-06-30';
	private const PAST_DATE = '2020-01-15';

	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static RecipesApiController $recipes;
	private static string $apiKey = '';
	private static int $locationId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);
		self::$recipes = new RecipesApiController(self::$container);

		// VICTUAL_USER_ID (the identity direct controller calls act as, per
		// PgsqlSchemaTestCase::Boot()) defaults to 9000, so that is the id granted here -
		// matching StockCoverageTest's own fixture user.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'stockconcurrency-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_PURCHASE', 'STOCK_CONSUME', 'STOCK_EDIT')");

		$statement = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$statement->execute(['Concurrency Pantry']);
		self::$locationId = (int)$statement->fetchColumn();

		// The subprocess-driven scenarios call through the real HTTP stack, which
		// authenticates by API key rather than by the direct-call fixture user above.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9601, 'stockconcurrency-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9601, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9601, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');

		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	private static function insertProduct(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute([$name, self::$locationId]);

		return (int)$statement->fetchColumn();
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (float)$statement->fetchColumn();
	}

	/**
	 * A second, independent PDO connection into the same test schema - "connection B" in
	 * the issue's harness description. A distinct connection from self::$db (which drives
	 * the direct-call assertions and setup) and from whatever the subprocess opens, so all
	 * three can hold locks and transactions of their own at once.
	 */
	private static function secondConnection(): PDO
	{
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');

		return $pdo;
	}

	/**
	 * Blocks the calling PHP process (not the database) until some other backend is
	 * actually waiting on an advisory lock, or fails the test after $timeoutSeconds. This
	 * is the "bounded poll of pg_stat_activity for a waiting backend" the issue asks for -
	 * a readiness check driven by the database's own view of who is blocked, not a fixed
	 * sleep guessed to be long enough.
	 */
	private static function waitForAdvisoryWaiter(float $timeoutSeconds = 10.0): void
	{
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$count = (int)self::$db->query(
				"SELECT count(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND wait_event = 'advisory' AND pid <> pg_backend_pid()"
			)->fetchColumn();

			if ($count > 0)
			{
				return;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail('Timed out waiting for a backend to block on the stock booking advisory lock');
	}

	/**
	 * Starts request-subprocess-helper.php (the same harness StockCoverageTest's
	 * subprocess scenarios use) without waiting for it to finish, so the calling test can
	 * do other things - here, wait for it to block on a lock and then release that lock -
	 * while it runs. finishSubprocess() collects the result afterwards.
	 *
	 * @return array{0: resource, 1: array} The proc_open() process handle and its pipes
	 */
	private static function startSubprocess(string $method, string $path, ?array $body = null): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey], 'body' => $body],
			fn ($value) => $value !== null
		);

		// $_SERVER carries non-scalar entries (argv among them) that proc_open's env
		// conversion cannot stringify - the same filter StockCoverageTest::send() uses.
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

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;

		return $result;
	}

	// ------------------------------------------------------------------------------
	// 1. Undo vs consume (services/StockService.php UndoBooking())
	// ------------------------------------------------------------------------------

	/**
	 * Reproduces the interleaving issue #458 describes for UndoBooking(): the "any
	 * subsequent booking depends on this" guard used to run before any lock or
	 * transaction opened, so a consume landing between that check and the purchase
	 * branch's delete() booked against a stock entry the undo then removed.
	 *
	 * Connection B stands in for the concurrent consume: it takes the same advisory lock
	 * UndoBooking() itself now takes, so the undo subprocess started afterwards queues
	 * behind it exactly as it would behind a real in-flight consume transaction. B then
	 * performs the consume's writes directly (this harness has one PDO per process, so a
	 * second connection cannot drive StockService's own singleton - see the class
	 * comment) and commits, and only then does the queued undo proceed.
	 *
	 * Before the fix (guard read before any lock, checked against
	 * services/StockService.php:2559 on master), the undo already has its own
	 * (unlocked) view of "no subsequent booking" by the time it reaches this point and
	 * proceeds to delete the stock entry regardless of what connection B just committed -
	 * this test's assertion fails because the response is 204 and the consume booking
	 * this test asserts survives is instead orphaned against a deleted entry. Run
	 * `run-suite.sh <wt> <sfx> stockcoverage` (stockconcurrency uses the same phase) with
	 * PostgresDialect::LockProductStock() reverted to a no-op to see it fail with:
	 * "Failed asserting that 400 matches expected 204" is not what happens - the undo
	 * instead SUCCEEDS (204) where this test expects a refusal, i.e. the assertion
	 * `self::assertSame(400, ...)` fails with the actual value 204.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testUndoIsRefusedWhenAConcurrentConsumeCommitsWhileItWaitsOnTheLock(): void
	{
		$productId = self::insertProduct('Concurrency Undo Vs Consume');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 4,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());
		$purchaseBooking = json_decode((string)$purchaseResponse->getBody(), true)[0];
		$bookingId = (int)$purchaseBooking['id'];

		$stockRow = self::$db->query('SELECT id, stock_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($stockRow, 'setup: the purchase created a stock row');

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		$subprocess = self::startSubprocess('POST', '/api/stock/bookings/' . $bookingId . '/undo');

		self::waitForAdvisoryWaiter();

		// Connection B's competing booking: a one-unit consume of the same entry,
		// committed while the undo above is queued behind B's lock.
		$connB->prepare(
			"INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, used_date, stock_id, transaction_type, price, user_id) "
			. "VALUES (?, -1, ?, ?, current_date, ?, 'consume', 0, 9600)"
		)->execute([$productId, self::FAR_FUTURE_DATE, self::PAST_DATE, $stockRow['stock_id']]);
		$connB->prepare('UPDATE stock SET amount = amount - 1 WHERE id = ?')->execute([$stockRow['id']]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The undo is refused once, under the lock, it sees the committed consume: ' . $result['body']);

		$undoneFlag = self::$db->prepare('SELECT undone FROM stock_log WHERE id = ?');
		$undoneFlag->execute([$bookingId]);
		self::assertSame(0, (int)$undoneFlag->fetchColumn(), 'The purchase booking is still live - the refused undo touched nothing');
		self::assertSame(3.0, self::stockAmount($productId), 'and the only change to stock is the one unit connection B consumed');
	}

	// ------------------------------------------------------------------------------
	// 2. Consume vs consume of the last units (services/StockService.php ConsumeProduct())
	// ------------------------------------------------------------------------------

	/**
	 * Reproduces the interleaving issue #458 describes for ConsumeProduct(): the
	 * aggregated-amount check used to run against a read taken before any lock or
	 * transaction opened, so two concurrent consumes of the same (small) stock could both
	 * read enough to satisfy their own request and both proceed.
	 *
	 * Connection B stands in for the first consume, which takes all two units and commits
	 * while the subprocess's consume of the same two units is queued behind B's lock.
	 * Before the fix, the subprocess's amount check ran unlocked against the two units
	 * that were about to be consumed out from under it and it would succeed (200) rather
	 * than being refused - this test's `self::assertSame(400, ...)` would fail with the
	 * actual value 200, and stock_log would carry two consume bookings for a product that
	 * was only ever stocked twice.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testSecondConsumeOfTheLastUnitsIsRefusedOnceTheFirstHasCommitted(): void
	{
		$productId = self::insertProduct('Concurrency Consume Vs Consume');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 2,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());

		$stockRow = self::$db->query('SELECT id, stock_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($stockRow, 'setup: the purchase created a stock row');

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		// The subprocess tries to consume everything that was in stock at the time this
		// test set up its fixture - exactly what connection B is about to take first.
		$subprocess = self::startSubprocess('POST', '/api/stock/products/' . $productId . '/consume', ['amount' => 2]);

		self::waitForAdvisoryWaiter();

		// Connection B's competing booking: the first consume, taking the whole entry.
		$connB->prepare(
			"INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, used_date, stock_id, transaction_type, price, user_id) "
			. "VALUES (?, -2, ?, ?, current_date, ?, 'consume', 0, 9600)"
		)->execute([$productId, self::FAR_FUTURE_DATE, self::PAST_DATE, $stockRow['stock_id']]);
		$connB->prepare('DELETE FROM stock WHERE id = ?')->execute([$stockRow['id']]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The second consume is refused once, under the lock, it sees nothing left to take: ' . $result['body']);

		$totalConsumed = self::$db->prepare("SELECT COALESCE(SUM(amount), 0) FROM stock_log WHERE product_id = ? AND transaction_type = 'consume'");
		$totalConsumed->execute([$productId]);
		self::assertSame(-2.0, (float)$totalConsumed->fetchColumn(), 'Only connection B\'s consume booked - the refused one wrote nothing');
		self::assertSame(0.0, self::stockAmount($productId), 'and stock never goes negative or is double-deleted');
	}

	// ------------------------------------------------------------------------------
	// 3. Lock ordering for a multi-product path (RecipesService::ConsumeRecipe())
	// ------------------------------------------------------------------------------

	/**
	 * ConsumeRecipe() consumes every ingredient product in one transaction and, per issue
	 * #458, locks all of them upfront in ascending product id order specifically so that
	 * two overlapping multi-product bookings cannot each hold a lock the other needs and
	 * deadlock. Two real concurrent recipe consumes reliably contending for the same two
	 * products is not a deterministic setup (it depends on OS scheduling actually
	 * interleaving them at the right instant), so this test isolates the property that
	 * actually matters: a recipe consume that reaches its second ingredient's lock while
	 * another booking already holds it waits for that lock and then completes correctly,
	 * rather than deadlocking or writing a partial result.
	 *
	 * Connection B holds the *second* (higher id) ingredient's lock before the recipe
	 * consume starts. If ConsumeRecipe() locked ingredients in whatever order
	 * recipes_pos happens to list them (here, deliberately the descending id order) rather
	 * than sorting first, it would acquire the lower id product's lock, then block on the
	 * higher id one exactly as it does here - so this alone would not distinguish sorted
	 * from unsorted locking. What it does prove deterministically is the second half of
	 * the deadlock argument: once a recipe consume is queued behind a lock taken by
	 * another connection, releasing that lock lets it complete correctly rather than
	 * wedging, which is the failure mode a real deadlock (whichever order produced it)
	 * would show as a Postgres "deadlock detected" error surfacing as a 500 instead of
	 * this test's expected 200.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testRecipeConsumeCompletesAfterQueuingBehindAnIngredientLock(): void
	{
		$productLow = self::insertProduct('Concurrency Recipe Ingredient A');
		$productHigh = self::insertProduct('Concurrency Recipe Ingredient B');
		self::assertLessThan($productHigh, $productLow, 'setup: ids are in the expected ascending order');

		foreach ([$productLow, $productHigh] as $productId)
		{
			$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
				'amount' => 5,
				'best_before_date' => self::FAR_FUTURE_DATE,
				'purchased_date' => self::PAST_DATE,
			]), new Response(), ['productId' => $productId]);
			self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());
		}

		$recipeStatement = self::$db->prepare('INSERT INTO recipes (name) VALUES (?) RETURNING id');
		$recipeStatement->execute(['Concurrency Recipe']);
		$recipeId = (int)$recipeStatement->fetchColumn();

		// Listed in descending product id order on purpose - see the method comment.
		$positionStatement = self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount) VALUES (?, ?, 1)');
		$positionStatement->execute([$recipeId, $productHigh]);
		$positionStatement->execute([$recipeId, $productLow]);

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productHigh]);

		$subprocess = self::startSubprocess('POST', '/api/recipes/' . $recipeId . '/consume');

		self::waitForAdvisoryWaiter();

		// Nothing else to do: releasing the lock is the whole point. Committing an empty
		// transaction of B's own is enough to give the queued consume the lock back.
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(204, $result['status'], 'The recipe consume completes once its queued ingredient lock is released, rather than deadlocking: ' . $result['body']);
		self::assertSame(4.0, self::stockAmount($productLow), 'Both ingredients were consumed once each');
		self::assertSame(4.0, self::stockAmount($productHigh), 'Both ingredients were consumed once each');
	}

	/**
	 * Creates the API key request-subprocess-helper.php authenticates the concurrency
	 * scenarios above with, and proves the subprocess path itself works before any test
	 * relies on it to demonstrate a lock interaction.
	 */
	public function testCreatesTheSubprocessApiKey(): void
	{
		$probe = self::finishSubprocess(self::startSubprocess('GET', '/api/stock'));
		self::assertSame(200, $probe['status'], 'The subprocess identity can read stock: ' . $probe['body']);
	}
}
