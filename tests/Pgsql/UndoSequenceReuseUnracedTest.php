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
 * Issue #584, the unraced control case (this class does NOT exercise any race - see
 * tests/Pgsql/UndoSequenceForcedRaceTest.php and tests/Pgsql/UndoStolenRowInsertTest.php
 * for the two windows #584 actually closes, both forced deterministically through a test
 * seam rather than timed): with the `stock` identity sequence deliberately left at X - 1
 * (only a migration or import resync does this in production, #555) and nothing else
 * drawing from it or contending for it, undoing a whole-take CONSUME booking must still
 * reuse its deleted row's own id (X) exactly as #531/#577 intended - confirming the fix
 * does not regress the ordinary path once layered underneath the real undo endpoint.
 *
 * An earlier version of this class attempted the race with a genuinely separate OS
 * process (tests/Pgsql/sequence-race-subprocess-helper.php) hammering nextval() on the
 * same sequence. That subprocess could not reliably land inside
 * PostgresDialect::AdvanceIdentitySequence()'s own read-to-draw window - two back-to-back
 * statements on one connection, open for at most a handful of microseconds - either
 * because it had not yet connected (losing the race entirely) or because, once running,
 * it drew roughly 14,000 values/second in this environment, well over two orders of
 * magnitude faster than any round trip this harness could make, so the sequence was
 * invariably already past the target by the time the read executed. A repeated-iteration
 * variant of that same setup, run directly against AdvanceIdentitySequence() over a
 * 4-second window, produced zero false results across 4,701 attempts even with the fix
 * applied, confirming the harness could not discriminate the fix either way rather than
 * indicating a flawed one-shot attempt.
 */
class UndoSequenceReuseUnracedTest extends PgsqlSchemaTestCase
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
