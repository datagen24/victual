<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\BaseService;
use Victual\Services\DatabaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\StockRowStealingPdo;

/**
 * Issue #584, round 2 (Opus validator on PR #598): PostgresDialect::AdvanceIdentitySequence()'s
 * own fix closes the narrow window between its own plain read and its own nextval()
 * draw, but a wider window remains open the whole time StockService::UndoBooking()'s
 * CONSUME branch runs, between its "does row X still exist" check
 * (`$this->DB->stock()->where('id = :1', $stockRowId)->fetch()`) and the eventual
 * explicit-id INSERT that reuses X. An entirely different connection can insert and
 * commit a real row under X in that window - the sequence never has to move for this,
 * since a real INSERT does not have to draw from it at all once its own caller resolved
 * its id some other way - so #584's own read-to-draw fix cannot see it: the sequence read
 * later finds itself already past X (X was, after all, actually taken - just not by this
 * call), takes the unconditional "keep today's true" fast path, and a plain explicit-id
 * INSERT would collide outright with the real row the stranger already committed.
 *
 * tests/Support/StockRowStealingPdo.php forces this window deterministically: it
 * recognises the existence check by its exact SQL shape and bound parameter and,
 * synchronously right after that check executes (finding nothing, exactly as it would
 * without any race at all) but before UndoBooking() ever acts on that answer, has a
 * second, genuinely separate connection insert and commit a real stranger's row under
 * the target id - no timing involved. No production code is touched or branches on a
 * test-only condition.
 */
class UndoStolenRowInsertTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static int $pantry;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'undostolenrow-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Undo Stolen Row Pantry']);
		self::$pantry = (int)$location->fetchColumn();

		// Bumps the `stock.id` sequence a few rows ahead of its seed value, so this
		// test's own "$stockRowId - 1" setval() below is never 0 or negative - a
		// sequence cannot be set below 1.
		$sequenceName = self::$db->query("SELECT pg_get_serial_sequence('stock', 'id')")->fetchColumn();
		for ($i = 0; $i < 5; $i++)
		{
			self::$db->query('SELECT nextval(\'' . $sequenceName . '\')');
		}
	}

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
		$statement = self::$db->prepare(
			'INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id'
		);
		$statement->execute([$name, self::$pantry]);

		return (int)$statement->fetchColumn();
	}

	public function testUndoFallsBackToAFreshIdWhenAStrangerCommitsARealRowUnderXFirst(): void
	{
		$bystanderProductId = self::insertProduct('Undo Stolen Row Bystander');

		// Every DB access from here on - including LessQL's own $this->DB, rebuilt lazily
		// the first time any service touches it - runs through this one connection, so
		// the swap happens before any service call at all (BaseService::__construct()
		// binds $this->DB to DatabaseService::GetDbConnection() once, at first
		// construction).
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');

		$productId = self::insertProduct('Undo Stolen Row Product');

		$stock = StockService::GetInstance();
		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$pantry);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		$stock->ConsumeProduct($productId, 1, false, StockService::TRANSACTION_TYPE_CONSUME);

		self::assertSame([], self::$db->query('SELECT * FROM stock WHERE product_id = ' . $productId)->fetchAll(), 'Given: the fully consumed row leaves no live stock row');

		$consumeLogId = (int)self::$db->query(
			"SELECT id FROM stock_log WHERE product_id = $productId AND transaction_type = 'consume' AND undone = 0"
		)->fetchColumn();
		self::assertGreaterThan(0, $consumeLogId, 'Given: the live consume booking exists');

		$sequenceName = self::$db->query("SELECT pg_get_serial_sequence('stock', 'id')")->fetchColumn();

		// Issue #555/#584's own precondition: only a migration or import resync leaves
		// the sequence at or below a booking's own recorded stock_row_id - simulated
		// directly rather than by actually running one. Necessary here too: the
		// stranger's own ordinary (no explicit id) INSERT below needs the sequence to
		// hand it X naturally.
		self::$db->exec('SELECT setval(\'' . $sequenceName . '\', ' . ($stockRowId - 1) . ', true)');

		// The stranger's own row: a real, distinct product entirely, describing an
		// ordinary purchase with no relationship to $productId's own booking - only its
		// id (forced to X by the steal, via the stealing statement's own explicit-id
		// INSERT) matters here.
		$victimRow = [
			'product_id' => $bystanderProductId,
			'amount' => 3.0,
			'stock_id' => bin2hex(random_bytes(8)),
			'purchased_date' => '2026-09-01',
			'location_id' => self::$pantry,
		];

		$stealingPdo = new StockRowStealingPdo(
			$dsn,
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
			self::$db,
			$stockRowId,
			$victimRow
		);
		$stealingPdo->exec('SET search_path TO ' . self::Schema() . ', public');

		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);
		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $stealingPdo);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		$stockController = new StockApiController($container);

		$response = $stockController->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $consumeLogId]);

		// Sanity: the steal actually fired and the stranger's row is really there under X.
		$strangerRow = self::$db->query('SELECT id, product_id, amount FROM stock WHERE id = ' . $stockRowId)->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($strangerRow, 'Given: the stranger\'s row was inserted and committed under X');
		self::assertSame($bystanderProductId, (int)$strangerRow['product_id'], 'Given: the stranger\'s row is really the bystander\'s, not the undo\'s own');

		self::assertSame(204, $response->getStatusCode(), 'The undo succeeds - falling back to a fresh id - rather than refusing on a primary key collision with the stranger\'s row');

		// The stranger's row is completely untouched.
		self::assertEqualsWithDelta(3.0, (float)$strangerRow['amount'], 1e-9, 'Then: the stranger\'s own row amount is exactly what it inserted');

		$rebuilt = self::$db->query('SELECT id, amount FROM stock WHERE product_id = ' . $productId)->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $rebuilt, 'Then: the undo\'s own container is restored exactly once');
		self::assertNotSame($stockRowId, (int)$rebuilt[0]['id'], 'Then: the rebuild does not collide with or overwrite the stranger\'s row - it lands under a fresh id instead');
		self::assertSame(1.0, (float)$rebuilt[0]['amount'], 'Then: the restored amount is correct');

		$log = self::$db->query('SELECT undone, stock_row_id FROM stock_log WHERE id = ' . $consumeLogId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1, (int)$log['undone'], 'Then: the booking itself is marked undone');
		self::assertSame($stockRowId, (int)$log['stock_row_id'], 'Then: the booking\'s own historical record of the row it took from is unchanged');
	}
}
