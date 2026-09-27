<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\Database\PostgresDialect;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #494/H5 (part of #487): RecipesService::ConsumeRecipe() folds the recipe's produced
 * product into the same ascending DatabaseService::LockProductsStock() call its ingredient
 * locks already use (services/RecipesService.php, the "$lockSet[] = (int)$outputProductId;"
 * line), rather than leaving it to be locked separately - and later - by
 * StockService::AddProduct() once the ingredient locks are already held.
 *
 * The hazard this guards against: a "swap-role" pair of recipes - recipe A consumes product
 * High and produces product Low; recipe B consumes Low and produces High - run concurrently.
 * Recipe B's own natural lock order (its ingredient, Low, is also the lower id) already
 * happens to be ascending, so it is not the side sensitive to this fix; recipe A is, because
 * its ingredient (High) has the higher id and its produced product (Low) the lower one.
 * Without the fix, recipe A locks High (ingredient) and then, separately, Low (output) -
 * descending order - which can invert against any other locker of the same two keys that
 * takes them ascending, such as recipe B, and deadlock: A holds High and wants Low; B holds
 * Low and wants High.
 *
 * Rather than racing two live processes against PostgreSQL's deadlock detector - whose choice
 * of victim between two blocked backends this suite does not pin down, matching this
 * testsuite's own StockConcurrencyTest.php::testConsumeRecipeLocksANestedIngredientAscendingRatherThanInvertingTheOrder()
 * and its documented preference for reading pg_locks directly "not inferred from whether a
 * deadlock happened to occur" - this test proves the order directly. Connection B holds only
 * the *output* product's lock, standing in for recipe B already having consumed its own
 * ingredient (the same product). The real recipe A consume is then started and queues behind
 * that lock; while it is queued, pg_locks is asked whether recipe A's own backend has already
 * been granted the *ingredient* product's lock. With the fix it has not - the ascending call
 * tries the lower-id output first and blocks there before ever touching the ingredient. With
 * the fix's lock-set line deleted, it has - recipe A has already locked and consumed its
 * ingredient before ever trying its output - which is exactly the order inversion that can
 * deadlock against recipe B above.
 *
 * A separate, self-contained file rather than an addition to StockConcurrencyTest.php per this
 * fix's reservation; its handful of private helpers below (secondConnection(),
 * waitForAdvisoryWaiter(), startSubprocess(), finishSubprocess()) are copies of that class's
 * own, not a shared base - StockConcurrencyTest.php is not edited by this change.
 */
class RecipeSelfProductionLockOrderTest extends PgsqlSchemaTestCase
{
	private const FAR_FUTURE_DATE = '2035-06-30';
	private const PAST_DATE = '2020-01-15';

	private static PDO $db;
	private static int $locationId;
	private static string $apiKey = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'lockorder-caller', 'fixture')");

		$statement = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$statement->execute(['Lock Order Pantry']);
		self::$locationId = (int)$statement->fetchColumn();

		// The subprocess-driven scenario calls through the real HTTP stack, which
		// authenticates by API key - matching StockConcurrencyTest.php's own fixture.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9601, 'lockorder-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9601, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9601, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);
	}

	// ------------------------------------------------------------------------------
	// Helpers (copies of tests/Pgsql/StockConcurrencyTest.php's own - see class docblock)
	// ------------------------------------------------------------------------------

	private static function insertProduct(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute([$name, self::$locationId]);

		return (int)$statement->fetchColumn();
	}

	/** A purchase booking written directly, the same way StockConcurrencyTest.php's own "connection B" writes competing bookings, rather than through StockService's process-cached singleton (see the class docblock on why this file avoids it). */
	private static function stockUp(int $productId, float $amount): void
	{
		$stockId = uniqid('lockorder', true);
		self::$db->prepare('INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, price) VALUES (?, ?, ?, ?, ?, 0)')
			->execute([$productId, $amount, $stockId, self::FAR_FUTURE_DATE, self::PAST_DATE]);
		self::$db->prepare("INSERT INTO stock_log (product_id, amount, stock_id, best_before_date, purchased_date, transaction_type, price, user_id) VALUES (?, ?, ?, ?, ?, 'purchase', 0, 9000)")
			->execute([$productId, $amount, $stockId, self::FAR_FUTURE_DATE, self::PAST_DATE]);
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (float)$statement->fetchColumn();
	}

	private static function secondConnection(): PDO
	{
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');

		return $pdo;
	}

	private static function waitForAdvisoryWaiter(int $productId, float $timeoutSeconds = 10.0): int
	{
		$waiterCheck = self::$db->prepare(
			'SELECT pid FROM pg_locks WHERE locktype = \'advisory\' AND NOT granted AND classid = ? AND objid = ? LIMIT 1'
		);
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$waiterCheck->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);
			$pid = $waiterCheck->fetchColumn();

			if ($pid !== false)
			{
				return (int)$pid;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail('Timed out waiting for a backend to block on the stock booking advisory lock for product ' . $productId);
	}

	/** Whether $pid currently holds a *granted* advisory stock-booking lock on $productId. */
	private static function holdsProductLock(int $pid, int $productId): bool
	{
		$statement = self::$db->prepare(
			'SELECT count(*) FROM pg_locks WHERE locktype = \'advisory\' AND granted AND pid = ? AND classid = ? AND objid = ?'
		);
		$statement->execute([$pid, PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);

		return (int)$statement->fetchColumn() > 0;
	}

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

	/** @return array{status: int, stderr: string, body?: string} */
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

	public function testSubprocessApiKeyWorks(): void
	{
		$probe = self::finishSubprocess(self::startSubprocess('GET', '/api/stock'));
		self::assertSame(200, $probe['status'], 'The subprocess identity can read stock: ' . $probe['body']);
	}

	/**
	 * See the class docblock for the full hazard this reproduces and how. Concretely: the
	 * recipe's ingredient is "High" (the higher product id, purchased with 1 unit) and its
	 * produced ("Produces product") is "Low" (the lower id) - deliberately the reverse of
	 * ascending order, which is what makes the fix (locking the output as part of the same
	 * ascending call as the ingredients) change what this test observes, and the unfixed code
	 * (ingredient locked and consumed first, output locked separately afterwards) not.
	 */
	public function testConsumeRecipeLocksItsOutputBeforeConsumingRatherThanAfter(): void
	{
		$lowId = self::insertProduct('Lock Order Output (low id)');
		$highId = self::insertProduct('Lock Order Ingredient (high id)');
		self::assertLessThan($highId, $lowId, 'setup: ids are in the expected ascending order');

		self::stockUp($highId, 1);

		$recipeStatement = self::$db->prepare('INSERT INTO recipes (name, product_id) VALUES (?, ?) RETURNING id');
		$recipeStatement->execute(['Lock Order Swap-Role Recipe A', $lowId]);
		$recipeId = (int)$recipeStatement->fetchColumn();
		self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (?, ?, 1, 2)')->execute([$recipeId, $highId]);

		$connB = self::secondConnection();
		$connB->beginTransaction();
		// Standing in for "recipe B already consumed its own ingredient, Low" - see the class
		// docblock's swap-role pair. A real second recipe consume is not used here for the
		// same reason as elsewhere in this suite: it would make the test's outcome depend on
		// which of two live backends PostgreSQL's deadlock detector happens to cancel, rather
		// than on the one fact actually under test.
		$connB->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $lowId]);

		$subprocess = self::startSubprocess('POST', '/api/recipes/' . $recipeId . '/consume');

		$waitingPid = self::waitForAdvisoryWaiter($lowId);

		self::assertFalse(self::holdsProductLock($waitingPid, $highId),
			'The recipe consume must not already hold its ingredient\'s lock while still queued for its '
			. 'output\'s - that is the order inversion (RecipesService::ConsumeRecipe() must fold the '
			. 'produced product into the same ascending LockProductsStock() call as the ingredients) that '
			. 'can deadlock against a concurrent recipe B locking the same two products the other way '
			. 'round - see this file\'s class docblock');

		// Nothing else to do: releasing the output lock is the whole point, exactly as the
		// equivalent StockConcurrencyTest.php scenarios do once their own assertion is made.
		$connB->commit();

		$result = self::finishSubprocess($subprocess);

		self::assertSame(204, $result['status'], 'Once connection B releases the output lock, the recipe consume completes rather than deadlocking: ' . $result['body']);
		self::assertSame(0.0, self::stockAmount($highId), 'The ingredient was consumed');
		self::assertSame(1.0, self::stockAmount($lowId), 'The produced product was booked in');
	}
}
