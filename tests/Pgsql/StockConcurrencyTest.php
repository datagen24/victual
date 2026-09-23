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
use Victual\Services\DatabaseService;
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
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_PURCHASE', 'STOCK_CONSUME', 'STOCK_EDIT', 'STOCK_OPEN')");

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
	 * A location configured as a weighable vessel (tare_weight = 0, tare_qu_id = the
	 * fixture stock unit), for WeighLocation() scenarios. Zero tare and a gross reading
	 * that just needs to be >= 0 keeps the arithmetic irrelevant to what these tests
	 * assert - they exercise the "who is stocked here" recheck, which runs before any
	 * unit conversion.
	 */
	private static function insertVesselLocation(): int
	{
		$statement = self::$db->prepare('INSERT INTO locations (name, tare_weight, tare_qu_id) VALUES (?, 0, 2) RETURNING id');
		$statement->execute(['Concurrency Vessel ' . bin2hex(random_bytes(4))]);

		return (int)$statement->fetchColumn();
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

	/**
	 * Starts compact-stock-subprocess-helper.php, which calls
	 * StockService::CompactStockEntries($productId) directly with no ambient lock or
	 * transaction already open - the one call shape none of StockService's own HTTP-driven
	 * callers produce (see that helper's own comment). Not started via startSubprocess():
	 * there is no request spec, no API key, and the result carries no response body.
	 *
	 * @return array{0: resource, 1: array}
	 */
	private static function startCompactSubprocess(int $productId): array
	{
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
			[PHP_BINARY, __DIR__ . '/compact-stock-subprocess-helper.php', (string)$productId],
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

	// ------------------------------------------------------------------------------
	// 4. The re-checks the lock exists to make honest: each of these throws only
	// because it re-read fresh state after the lock rather than trusting what was read
	// before it. Same two-connection pattern as above; each scenario's own comment names
	// the exact services/StockService.php line its refusal reaches.
	// ------------------------------------------------------------------------------

	/**
	 * EditStockEntry() fetches the row once before opening its transaction (to learn the
	 * product id to lock), then re-fetches it under the lock and refuses if it is gone
	 * (StockService.php:799) rather than trusting the first, pre-lock read. Connection B
	 * removes the entry outright while the edit is queued behind B's lock.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testEditStockEntryFindsTheRowGoneAfterQueuingBehindALock(): void
	{
		$productId = self::insertProduct('Concurrency Edit Row Vanished');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 1,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());

		$stockRow = self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($stockRow, 'setup: the purchase created a stock row');
		$entryId = (int)$stockRow['id'];

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		$subprocess = self::startSubprocess('PUT', '/api/stock/entry/' . $entryId, ['amount' => 1, 'open' => false, 'purchased_date' => self::PAST_DATE]);

		self::waitForAdvisoryWaiter();

		$connB->prepare('DELETE FROM stock WHERE id = ?')->execute([$entryId]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The edit is refused once, under the lock, it finds the row gone: ' . $result['body']);
	}

	/**
	 * MeasureStockEntry() re-fetches its row under the lock the same way EditStockEntry()
	 * does, and refuses if it is gone (StockService.php:933). Connection B removes the
	 * entry outright while the measurement is queued behind B's lock.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testMeasureStockEntryFindsTheRowGoneAfterQueuingBehindALock(): void
	{
		$productId = self::insertProduct('Concurrency Measure Row Vanished');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 1,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());

		$stockRow = self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($stockRow, 'setup: the purchase created a stock row');
		$entryId = (int)$stockRow['id'];

		$openResponse = self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $openResponse->getStatusCode(), 'setup: opening the one unit must succeed: ' . (string)$openResponse->getBody());

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		$subprocess = self::startSubprocess('POST', '/api/stock/entry/' . $entryId . '/measure', ['amount' => 0.5, 'qu_id' => 2]);

		self::waitForAdvisoryWaiter();

		$connB->prepare('DELETE FROM stock WHERE id = ?')->execute([$entryId]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The measurement is refused once, under the lock, it finds the row gone: ' . $result['body']);
	}

	/**
	 * WeighLocation() re-checks "who is stocked here" under the lock (StockService.php's
	 * WeighLocation, the "No product is stocked at this location" branch, :2523), because
	 * the product id it locked came from an unlocked read taken before the transaction
	 * opened. Connection B empties the vessel entirely while the weighing is queued
	 * behind B's lock on the product that used to be there.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testWeighLocationFindsNoProductLeftAfterQueuingBehindALock(): void
	{
		$vesselId = self::insertVesselLocation();
		$productId = self::insertProduct('Concurrency Weigh Emptied');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 3,
			'location_id' => $vesselId,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		$subprocess = self::startSubprocess('POST', '/api/stock/locations/' . $vesselId . '/weigh', ['gross_amount' => 5]);

		self::waitForAdvisoryWaiter();

		$connB->prepare('DELETE FROM stock WHERE product_id = ? AND location_id = ?')->execute([$productId, $vesselId]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The weighing is refused once, under the lock, the vessel is found empty: ' . $result['body']);
	}

	/**
	 * The other half of WeighLocation()'s recheck: "more than one product is stocked
	 * here" (:2527). Connection B stocks a second, different product at the same vessel
	 * while the weighing is queued behind B's lock on the first product.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testWeighLocationFindsASecondProductAfterQueuingBehindALock(): void
	{
		$vesselId = self::insertVesselLocation();
		$productId = self::insertProduct('Concurrency Weigh Crowded');
		$otherProductId = self::insertProduct('Concurrency Weigh Crowded Other');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 3,
			'location_id' => $vesselId,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		$subprocess = self::startSubprocess('POST', '/api/stock/locations/' . $vesselId . '/weigh', ['gross_amount' => 5]);

		self::waitForAdvisoryWaiter();

		$connB->prepare(
			'INSERT INTO stock (product_id, amount, stock_id, location_id, best_before_date, purchased_date) VALUES (?, 1, ?, ?, ?, ?)'
		)->execute([$otherProductId, 'concurrency-weigh-crowded-' . bin2hex(random_bytes(4)), $vesselId, self::FAR_FUTURE_DATE, self::PAST_DATE]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The weighing is refused once, under the lock, a second product is found stocked there: ' . $result['body']);
	}

	/**
	 * UndoBooking() re-fetches its own booking row under the lock and refuses if it is
	 * already undone (StockService.php:2625) - the second occurrence of "Booking does not
	 * exist or was already undone", reached only through the post-lock re-read (the first,
	 * at :2614/:2625's sibling before the lock, is what an ordinary already-undone booking
	 * hits without any race). Connection B undoes the booking itself - marking it undone
	 * and removing its stock entry, exactly what a genuine concurrent undo of the same
	 * booking would do - while this undo is queued behind B's lock.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testUndoFindsTheBookingAlreadyUndoneAfterQueuingBehindALock(): void
	{
		$productId = self::insertProduct('Concurrency Double Undo');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 2,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());
		$purchaseBooking = json_decode((string)$purchaseResponse->getBody(), true)[0];
		$bookingId = (int)$purchaseBooking['id'];
		$stockId = $purchaseBooking['stock_id'];

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		$subprocess = self::startSubprocess('POST', '/api/stock/bookings/' . $bookingId . '/undo');

		self::waitForAdvisoryWaiter();

		$connB->prepare("UPDATE stock_log SET undone = 1, undone_timestamp = now() WHERE id = ?")->execute([$bookingId]);
		$connB->prepare('DELETE FROM stock WHERE stock_id = ?')->execute([$stockId]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The second undo is refused once, under the lock, it sees the booking already undone: ' . $result['body']);
	}

	/**
	 * DatabaseService::LockProductStock() refuses outright, rather than silently taking a
	 * lock that would protect nothing, when no transaction is open on the connection - see
	 * that method's own comment (DatabaseService.php:333). Called directly, no subprocess
	 * or second connection needed: this is a precondition check, not a race.
	 */
	// ------------------------------------------------------------------------------
	// 5. PR #471 follow-up (CodeRabbit review of the issue #458 fix): three more
	// read-then-write windows the initial fix left open, each with its own two-connection
	// or queue-then-release scenario.
	// ------------------------------------------------------------------------------

	/**
	 * CompactStockEntries() used to read stock_splits (total_amount, stock_id_group,
	 * id_group) before locking the product, then write that stale total under the lock.
	 * A booking that committed while compaction waited would be silently overwritten -
	 * stock.amount set back to a total that no longer includes what the booking consumed.
	 *
	 * Driven through compact-stock-subprocess-helper.php rather than an HTTP endpoint:
	 * every one of StockService's own callers (AddProduct, EditStockEntry, WeighLocation)
	 * already locks the product before calling CompactStockEntries(), so the stale read
	 * this fix closes cannot go stale when reached through any of them - the ambient lock
	 * already protects it, which is also why this scenario cannot be demonstrated failing
	 * through EditStockEntry or any other existing endpoint (verified: driving it that way
	 * passes identically with the fix reverted). CompactStockEntries() is public with a
	 * $productId = null sweep-every-product form, so it is called directly here exactly as
	 * a maintenance sweep or any future caller without its own lock would.
	 *
	 * Two identical-attribute stock rows are inserted directly (bypassing AddProduct's own
	 * auto-compaction, so they sit uncompacted exactly as a real split would).
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testCompactStockEntriesReflectsAConcurrentConsumeRatherThanAStaleTotal(): void
	{
		$productId = self::insertProduct('Concurrency Compact Stale Total');

		$stockIdA = 'concurrency-compact-a-' . bin2hex(random_bytes(4));
		$stockIdB = 'concurrency-compact-b-' . bin2hex(random_bytes(4));

		// Identical on every stock_splits GROUP BY column (product, dates, price, open
		// state, location, shopping location, note) so the two rows are one compactable
		// group, differing only in amount and stock_id.
		$insertStock = self::$db->prepare(
			'INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id) VALUES (?, ?, ?, ?, ?, ?)'
		);
		$insertStock->execute([$productId, 3, $stockIdA, self::FAR_FUTURE_DATE, self::PAST_DATE, self::$locationId]);
		$insertStock->execute([$productId, 5, $stockIdB, self::FAR_FUTURE_DATE, self::PAST_DATE, self::$locationId]);

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		$subprocess = self::startCompactSubprocess($productId);

		self::waitForAdvisoryWaiter();

		// Connection B's competing booking: consumes 2 of entry B's 5 units while
		// compaction is queued behind B's lock.
		$connB->prepare('UPDATE stock SET amount = amount - 2 WHERE stock_id = ?')->execute([$stockIdB]);
		$connB->prepare(
			"INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, used_date, stock_id, transaction_type, price, user_id) VALUES (?, -2, ?, ?, current_date, ?, 'consume', 0, 9600)"
		)->execute([$productId, self::FAR_FUTURE_DATE, self::PAST_DATE, $stockIdB]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(200, $result['status'], 'Compaction succeeds: ' . ($result['error_message'] ?? $result['body'] ?? ''));
		self::assertSame(6.0, self::stockAmount($productId), 'Compaction summed the entries as they stood after B\'s consume (3 + 3), not the stale pre-lock total (3 + 5 = 8)');

		$rowCount = (int)self::$db->query('SELECT COUNT(*) FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		self::assertSame(1, $rowCount, 'The two entries compacted into one, as stock_splits still grouped them after B\'s amount-only change');
	}

	/**
	 * ConsumeProduct() and OpenProduct() with $allowSubproductSubstitution can read and
	 * write a sub product's own stock rows while only the parent's product id was locked,
	 * so a direct consume of the sub product raced them. SubstitutionLockSet() now locks
	 * the parent and every sub product together, upfront, before either reads anything.
	 *
	 * Connection B stands in for a direct consume of the sub product - the plain,
	 * non-substituting booking that would normally just lock the sub product's own id.
	 * The parent's substituting consume has to wait for that same lock as part of its
	 * upfront ascending pair, so it sees B's committed consume rather than a stale total,
	 * and refuses rather than reading past what B left.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testSubstitutingConsumeIsSerialisedAgainstADirectConsumeOfTheSubProduct(): void
	{
		$parentId = self::insertProduct('Concurrency Substitution Parent');
		$subId = self::insertProduct('Concurrency Substitution Child');
		self::$db->prepare('UPDATE products SET parent_product_id = ? WHERE id = ?')->execute([$parentId, $subId]);

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 2,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $subId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchasing the sub product must succeed: ' . (string)$purchaseResponse->getBody());

		$subStockRow = self::$db->query('SELECT stock_id FROM stock WHERE product_id = ' . $subId)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($subStockRow, 'setup: the purchase created a stock row for the sub product');

		$connB = self::secondConnection();
		$connB->beginTransaction();
		// Locks only the sub product - exactly what a plain, non-substituting
		// ConsumeProduct($subId) would lock on its own.
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $subId]);

		$subprocess = self::startSubprocess('POST', '/api/stock/products/' . $parentId . '/consume', [
			'amount' => 2,
			'allow_subproduct_substitution' => true,
		]);

		self::waitForAdvisoryWaiter();

		// Connection B's competing booking: a direct consume of one unit of the sub
		// product, committed while the parent's substituting consume is queued behind B's
		// lock on that same sub product.
		$connB->prepare('UPDATE stock SET amount = amount - 1 WHERE stock_id = ?')->execute([$subStockRow['stock_id']]);
		$connB->prepare(
			"INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, used_date, stock_id, transaction_type, price, user_id) VALUES (?, -1, ?, ?, current_date, ?, 'consume', 0, 9600)"
		)->execute([$subId, self::FAR_FUTURE_DATE, self::PAST_DATE, $subStockRow['stock_id']]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'The substituting consume is refused once, under the lock, only one unit is left to substitute: ' . $result['body']);
		self::assertSame(1.0, self::stockAmount($subId), 'Only connection B\'s one unit was consumed - the refused substituting consume drew nothing');
	}

	/**
	 * The other half of the substitution locking fix: OpenProduct()'s move_on_open
	 * transfer of a sub product entry, and any other caller that also locks a parent plus
	 * its sub products upfront (ascending), can only ever queue behind each other -  never
	 * deadlock - because both acquire the same lock set in the same order. Connection B
	 * holds that whole ascending pair (mimicking another substituting booking of the same
	 * family already in flight) while the open is queued, then releases it.
	 *
	 * PostgreSQL reports a real deadlock as SQLSTATE 40P01; its absence from the response
	 * (a clean 200) and the entry actually having moved is the assertion that no lock
	 * order inversion occurred.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testOpenProductMoveOnOpenCompletesAfterQueuingBehindTheSubstitutionLockSet(): void
	{
		$targetLocationId = self::insertVesselLocation();
		$parentId = self::insertProduct('Concurrency Substitution Move Parent');
		$subId = self::insertProduct('Concurrency Substitution Move Child');
		self::$db->prepare('UPDATE products SET parent_product_id = ? WHERE id = ?')->execute([$parentId, $subId]);
		self::$db->prepare('UPDATE products SET move_on_open = 1, default_consume_location_id = ? WHERE id = ?')->execute([$targetLocationId, $parentId]);

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 1,
			'location_id' => self::$locationId,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $subId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchasing the sub product must succeed: ' . (string)$purchaseResponse->getBody());
		$subStockRow = self::$db->query('SELECT stock_id FROM stock WHERE product_id = ' . $subId)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($subStockRow, 'setup: the purchase created a stock row for the sub product');

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$lockSet = [$parentId, $subId];
		sort($lockSet);
		foreach ($lockSet as $lockedId)
		{
			$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $lockedId]);
		}

		$subprocess = self::startSubprocess('POST', '/api/stock/products/' . $parentId . '/open', [
			'amount' => 1,
			'stock_entry_id' => $subStockRow['stock_id'],
			'allow_subproduct_substitution' => true,
		]);

		self::waitForAdvisoryWaiter();

		// Nothing else to do: releasing the ascending pair is the whole point, exactly as
		// in the recipe lock-ordering test above.
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(200, $result['status'], 'The open (and its move_on_open transfer) completes once the queued lock set is released, rather than deadlocking: ' . $result['body']);
		self::assertStringNotContainsString('40P01', $result['body'] . $result['stderr'], 'no PostgreSQL deadlock was detected on either side');

		$movedRow = self::$db->query('SELECT location_id, open FROM stock WHERE stock_id = \'' . $subStockRow['stock_id'] . '\'')->fetch(PDO::FETCH_ASSOC);
		self::assertSame($targetLocationId, (int)$movedRow['location_id'], 'The sub product entry moved to the parent\'s default consume location');
		self::assertSame(1, (int)$movedRow['open'], 'and was opened');
	}

	/**
	 * ConsumeRecipe() used to compute each ingredient's capped consume amount from
	 * recipes_pos_resolved read before the lock. A concurrent consume that shrinks an
	 * ingredient's stock while the recipe waits on the lock left the cap based on a stock
	 * level that no longer existed: the recipe would then ask ConsumeProduct() for more
	 * than was actually left, and ConsumeProduct()'s own (already lock-protected) amount
	 * check would refuse it - failing the *whole* recipe consume over one ingredient whose
	 * shortfall the recipe's own capping was supposed to absorb.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testConsumeRecipeCapsToStockLeftAfterAConcurrentConsumeRatherThanFailing(): void
	{
		$ingredientId = self::insertProduct('Concurrency Recipe Cap Ingredient');

		$purchaseResponse = self::$stock->AddProduct(self::request('POST', [
			'amount' => 3,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::PAST_DATE,
		]), new Response(), ['productId' => $ingredientId]);
		self::assertSame(200, $purchaseResponse->getStatusCode(), 'setup: purchase must succeed: ' . (string)$purchaseResponse->getBody());
		$stockRow = self::$db->query('SELECT stock_id FROM stock WHERE product_id = ' . $ingredientId)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($stockRow, 'setup: the purchase created a stock row');

		$recipeStatement = self::$db->prepare('INSERT INTO recipes (name) VALUES (?) RETURNING id');
		$recipeStatement->execute(['Concurrency Recipe Cap']);
		$recipeId = (int)$recipeStatement->fetchColumn();
		// Wants 2, but connection B below leaves only 1 in stock before the recipe's own
		// lock-protected read runs.
		self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount) VALUES (?, ?, 2)')->execute([$recipeId, $ingredientId]);

		$connB = self::secondConnection();
		$connB->beginTransaction();
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $ingredientId]);

		$subprocess = self::startSubprocess('POST', '/api/recipes/' . $recipeId . '/consume');

		self::waitForAdvisoryWaiter();

		// Connection B's competing booking: consumes 2 of the 3 units, leaving 1 - less
		// than the recipe's uncapped want of 2 - while the recipe consume is queued.
		$connB->prepare('UPDATE stock SET amount = amount - 2 WHERE stock_id = ?')->execute([$stockRow['stock_id']]);
		$connB->prepare(
			"INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, used_date, stock_id, transaction_type, price, user_id) VALUES (?, -2, ?, ?, current_date, ?, 'consume', 0, 9600)"
		)->execute([$ingredientId, self::FAR_FUTURE_DATE, self::PAST_DATE, $stockRow['stock_id']]);
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(204, $result['status'], 'The recipe succeeds, capped to the one unit actually left, rather than failing over asking for the pre-lock amount of two: ' . $result['body']);
		self::assertSame(0.0, self::stockAmount($ingredientId), 'The recipe consumed exactly the one remaining unit - not two, and not zero');
	}

	public function testLockProductStockOutsideATransactionThrowsLogicException(): void
	{
		self::assertFalse(self::$db->inTransaction(), 'precondition: no transaction is open on the schema connection');

		$productId = self::insertProduct('Concurrency Lock Guard');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('LockProductStock() requires a transaction already open');

		DatabaseService::GetInstance()->LockProductStock($productId);
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
