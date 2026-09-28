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
use Victual\Tests\Support\SequenceReadRacingPdo;

/**
 * Issue #584, forced deterministically rather than raced by timing (see
 * tests/Pgsql/UndoSequenceRaceRebuildTest.php's own docblock for why a genuinely
 * concurrent OS process could not land inside the actual race window in this
 * environment).
 *
 * tests/Support/SequenceReadRacingPdo.php is installed in place of
 * DatabaseService's own cached raw connection through the exact same
 * ReflectionProperty swap tests/Support/PgsqlSchemaTestCase.php's own
 * setUpBeforeClass() already performs. It recognises
 * PostgresDialect::AdvanceIdentitySequence()'s own plain-read statement by its exact SQL
 * text and, synchronously right after that statement executes - no timing involved at
 * all - has a second, genuinely separate connection draw one value from the very same
 * sequence, stealing the target id (X) before AdvanceIdentitySequence() ever reaches its
 * own subsequent draw. No production code is touched or branches on a test-only
 * condition: AdvanceIdentitySequence() already receives its connection as a plain \PDO
 * parameter.
 */
class UndoSequenceForcedRaceTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static int $pantry;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'undoforcedrace-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Undo Forced Race Pantry']);
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

	/**
	 * With the fix in place, undoing a whole-take CONSUME booking whose rebuild wants to
	 * reuse a deleted row's id (X) - the sequence deliberately left at X - 1 first, only a
	 * migration or import resync does this in production (#555) - must not claim X when a
	 * concurrent caller has, in the exact window between AdvanceIdentitySequence()'s own
	 * read and its own draw, already legitimately drawn it: the rebuild instead falls back
	 * to a fresh id, restoring the container correctly under it. Reverting the fix (see
	 * this test's own verbatim failure, quoted in this PR's description and in
	 * services/Database/PostgresDialect.php's own git history) makes this same call reuse
	 * X regardless - the exact defect #584 describes.
	 */
	public function testUndoDoesNotReuseAnIdAForcedConcurrentDrawAlreadyClaimed(): void
	{
		// Every DB access from here on - including LessQL's own $this->DB, rebuilt lazily
		// the first time any service touches it - runs through this one connection, so
		// the swap happens before any service call at all (BaseService::__construct()
		// binds $this->DB to DatabaseService::GetDbConnection() once, at first
		// construction, per class docblock).
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$sequenceNameForConstruction = self::$db->query("SELECT pg_get_serial_sequence('stock', 'id')")->fetchColumn();

		$racingPdo = new SequenceReadRacingPdo(
			$dsn,
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
			self::$db,
			$sequenceNameForConstruction
		);
		$racingPdo->exec('SET search_path TO ' . self::Schema() . ', public');

		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);
		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $racingPdo);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		$stockController = new StockApiController($container);

		$productStatement = self::$db->prepare(
			'INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id'
		);
		$productStatement->execute(['Undo Forced Race Product', self::$pantry]);
		$productId = (int)$productStatement->fetchColumn();

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
		// directly rather than by actually running one.
		self::$db->exec('SELECT setval(\'' . $sequenceName . '\', ' . ($stockRowId - 1) . ', true)');

		$response = $stockController->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $consumeLogId]);

		self::assertTrue($racingPdo->HasRaced(), 'Given: AdvanceIdentitySequence()\'s own read statement fired and forced the steal');
		self::assertSame(1, $racingPdo->RaceCount(), 'Given: the read statement fired exactly once for this single undo');

		self::assertSame(204, $response->getStatusCode(), 'The undo succeeds - either by legitimately reusing X or by falling back to a fresh id, never by refusing');

		$rebuilt = self::$db->query('SELECT id, amount, product_id FROM stock WHERE product_id = ' . $productId)->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $rebuilt, 'Then: the container is restored exactly once - never lost, never duplicated');
		self::assertSame(1.0, (float)$rebuilt[0]['amount'], 'Then: the restored amount is correct');
		self::assertNotSame(
			$stockRowId,
			(int)$rebuilt[0]['id'],
			'Then: the rebuild does not reuse X - a concurrent caller (forced deterministically) already, legitimately, drew it from the very same sequence in the exact window between the read and the draw (#584\'s exact defect: the unfixed code reused X here regardless)'
		);

		$log = self::$db->query('SELECT id, undone, stock_row_id FROM stock_log WHERE id = ' . $consumeLogId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1, (int)$log['undone'], 'Then: the booking itself is marked undone');
		self::assertSame($stockRowId, (int)$log['stock_row_id'], 'Then: the booking\'s own historical record of the row it took from is unchanged');
	}
}
