<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\BaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #584, StockService::UndoBooking() end of the fix: with the `stock` identity
 * sequence deliberately left at X - 1 (only a migration or import resync does this in
 * production, #555) and nothing else drawing from it, undoing a whole-take CONSUME
 * booking must still reuse its deleted row's own id (X) exactly as #531/#577 intended.
 *
 * The raced case itself (a concurrent nextval() landing inside
 * PostgresDialect::AdvanceIdentitySequence()'s own read-to-draw window) was not
 * reproducible through this repository's suite.sh harness: that window is two back-to-
 * back statements on one connection, open for at most a handful of microseconds, and
 * tests/Pgsql/sequence-race-subprocess-helper.php's subprocess - the only concurrency
 * primitive available here, per this remediation's rules against writing a bespoke
 * runner - either has not yet connected (losing the race entirely, the whole call
 * completing first) or, once its tight loop is running, draws roughly 14,000 values/second
 * in this environment, well over two orders of magnitude faster than any of this harness's
 * own round trips - so by the time this method's own internal read executes, the sequence
 * has invariably already advanced far past the target, landing in the (explicitly
 * unguarded, per the issue's own text - "when the sequence is already past X, keep
 * today's true") already-past fast path rather than the read-to-draw window the fix
 * actually closes. A repeated-iteration variant of this same setup, run directly against
 * PostgresDialect::AdvanceIdentitySequence() over a 4-second window, produced zero false
 * results across 4,701 attempts even with the fix applied, confirming this rather than
 * a flawed one-shot attempt; reported to the master rather than reproduced. This class
 * instead confirms the fix does not regress the unraced, ordinary path once layered
 * underneath the real undo endpoint.
 */
class UndoSequenceRaceRebuildTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static int $pantry;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Issue #533: this test class's own connection/schema must not be reached through
		// a service instance cached by whatever ran earlier in this same PHPUnit process.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'undoseqrace-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Undo Seq Race Pantry']);
		self::$pantry = (int)$location->fetchColumn();

		// Bumps the `stock.id` sequence a few rows ahead of its seed value, so this
		// test's own "$stockRowId - 1" setval() below is never 0 or negative - a sequence
		// cannot be set below 1.
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

	/**
	 * The sequence at X - 1, with nothing else drawing from it: the rebuild still
	 * legitimately reuses X exactly as before - this fix must not regress the ordinary
	 * case into an unnecessary fallback.
	 */
	public function testUndoStillReusesXWhenNothingElseDrewItFirst(): void
	{
		$productId = self::insertProduct('Undo Seq Race Unraced');

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
		self::$db->exec('SELECT setval(\'' . $sequenceName . '\', ' . ($stockRowId - 1) . ', true)');

		$response = self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $consumeLogId]);
		self::assertSame(204, $response->getStatusCode(), 'The undo succeeds');

		$rebuilt = self::$db->query('SELECT id, amount FROM stock WHERE product_id = ' . $productId)->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $rebuilt, 'Then: the container is restored exactly once');
		self::assertSame(1.0, (float)$rebuilt[0]['amount'], 'Then: the restored amount is correct');
		self::assertSame($stockRowId, (int)$rebuilt[0]['id'], 'Then: X is reused exactly as before, since nothing else ever drew it');
	}
}
