<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\BaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #546: a measured open container (migration 0275: `opened_amount` set, which
 * requires `open = 1 AND amount = 1` under `stock_measurement_coherence_check`) that was
 * fully consumed leaves only history - its CONSUME booking in `stock_log`, mirroring the
 * measurement columns (ADR-0022 decision 9) so UndoBooking()'s consume branch can rebuild
 * the deleted `stock` row later.
 *
 * MergeProducts() now refuses (commit 791389623f, "Part of #546") before it would rescale
 * such a live, undone=0, measured ledger row by a non-1 factor - so a merge can no longer
 * hand UndoBooking() a rescaled measured consume booking. But `trg_cascade_change_qu_id_stock*`
 * applies the very same amount rescale to `stock_log` on a single product's own qu_id_stock
 * change, and that path is not guarded the same way (not reproduced by this issue, and
 * fixing a trigger needs a migration - out of scope for this change per the #487
 * remediation's migration freeze until #580 merges). Whatever produces it, a measured
 * consume booking whose amount is not exactly -1 must never reach UndoBooking()'s rebuild
 * INSERT: that INSERT would carry `opened_amount` set and `amount` != 1, and the database's
 * own `stock_measurement_coherence_check` throws a raw SQLSTATE 23514 rather than a
 * truthful application-level refusal.
 *
 * This class reproduces that shape directly, by corrupting a live measured consume
 * booking's own `amount` after it is genuinely created through ConsumeProduct() (rather
 * than by driving the unfixed trigger, which the issue says was not reproduced) - the
 * defect is in UndoBooking()'s rebuild, not in how such a row comes to exist, and the
 * fix must hold regardless of which of the two paths produces it.
 */
class UndoMeasuredContainerCoherenceTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static int $pantry;
	private static int $gram;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Issue #533: BaseService::GetInstance() caches one instance per class for the
		// whole PHPUnit process; other classes earlier in this same "stockcoverage" phase
		// already constructed StockService against their own (by now dropped) schemas.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'undomeasured-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Undo Measured Pantry']);
		self::$pantry = (int)$location->fetchColumn();

		$unit = self::$db->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
		$unit->execute(['Undo Measured Gram', 'Undo Measured Grams']);
		self::$gram = (int)$unit->fetchColumn();
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

	private function expectStatus(callable $work, int $expected, string $message): array
	{
		try
		{
			$response = $work();
			$actual = $response->getStatusCode();
			$body = (string)$response->getBody();
		}
		catch (HttpException $exception)
		{
			$actual = $exception->getCode();
			$body = $exception->getMessage();
		}

		self::assertSame($expected, $actual, "$message: expected $expected, got $actual ($body)");
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : ['error_message' => $body];
	}

	private static function insertProduct(string $name): int
	{
		$statement = self::$db->prepare(
			'INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
		);
		$statement->execute([$name, self::$pantry, self::$gram, self::$gram, self::$gram, self::$gram]);

		return (int)$statement->fetchColumn();
	}

	/** Every column of every `stock` and `stock_log` row for one product, in id order, as JSON. */
	private static function productLedger(int $productId): string
	{
		$stock = self::$db->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY id');
		$stock->execute([$productId]);
		$stockLog = self::$db->prepare('SELECT * FROM stock_log WHERE product_id = ? ORDER BY id');
		$stockLog->execute([$productId]);

		return json_encode([
			'stock' => $stock->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => $stockLog->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	/**
	 * The scenario #546 describes: a single measured open container is fully consumed,
	 * then its live consume booking is rescaled the way a factor-2 unit conversion would
	 * (whether via MergeProducts() before commit 791389623f, or via the still-unguarded
	 * trg_cascade_change_qu_id_stock* path) - turning its logged amount from -1 to -2
	 * while opened_amount/opened_qu_id still mirror the container's own measurement.
	 * Undoing it must never let the database's own stock_measurement_coherence_check
	 * (migrations/0275.pgsql.sql) throw a raw SQLSTATE 23514: it must refuse truthfully,
	 * leaving the ledger exactly as it found it.
	 */
	public function testUndoOfARescaledMeasuredConsumeBookingRefusesRatherThanViolatingCoherence(): void
	{
		$productId = self::insertProduct('Undo Measured Rescaled Consume');

		$stock = StockService::GetInstance();
		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$pantry);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		$stock->OpenProduct($productId, 1);
		$stock->MeasureStockEntry($stockRowId, ['amount' => 0.5, 'qu_id' => self::$gram]);
		$stock->ConsumeProduct($productId, 1, false, StockService::TRANSACTION_TYPE_CONSUME);

		self::assertSame([], self::$db->query('SELECT * FROM stock WHERE product_id = ' . $productId)->fetchAll(), 'Given: the fully consumed container leaves no live stock row');

		$consumeLog = self::$db->query(
			'SELECT id, amount, opened_amount FROM stock_log WHERE product_id = ' . $productId . " AND transaction_type = 'consume' AND undone = 0"
		)->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($consumeLog, 'Given: the live consume booking exists');
		self::assertSame(-1.0, (float)$consumeLog['amount'], 'Given: an ordinary whole-container consume logs amount -1');
		self::assertEqualsWithDelta(0.5, (float)$consumeLog['opened_amount'], 1e-9, 'Given: the booking mirrors the container\'s own measurement');

		// A ledger rescale (factor 2) turning -1 into -2, exactly as #546 describes -
		// simulated directly rather than through the unfixed trigger, since the defect
		// under test is in UndoBooking()'s rebuild, not in how the rescale itself happens.
		self::$db->exec('UPDATE stock_log SET amount = -2 WHERE id = ' . (int)$consumeLog['id']);

		$before = self::productLedger($productId);

		$body = $this->expectStatus(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$consumeLog['id']]),
			400,
			'Undoing a rescaled measured consume booking must be refused, not crash with a raw constraint violation'
		);

		$message = (string)($body['error_message'] ?? json_encode($body));
		self::assertStringNotContainsStringIgnoringCase('sqlstate', $message, 'Then: this is not a raw database exception message');
		self::assertStringNotContainsStringIgnoringCase('23514', $message, 'Then: the raw constraint name/code never reaches the caller');
		// The application must recognise and explain this specific refusal itself, rather
		// than let the write reach the database and rely on BaseApiController's own generic
		// PDOException sanitiser (WithoutDriverText(), added by #576 for a different
		// concern - malformed API inputs, not ledger-rescale coherence) to produce a
		// truthful message by accident: that sanitiser's fallback text is the same generic
        // string for any wrong-shaped value at all ("The database rejected this request -
		// check that every value it carries suits the field it is for"), which does not
		// tell the caller their measured container specifically could not be restored.
		self::assertStringContainsString(
			'measured container',
			$message,
			'Then: the refusal names the actual reason (an inconsistent measured-container amount), not a generic database rejection'
		);
		self::assertSame($before, self::productLedger($productId), 'Then: the refusal leaves stock and stock_log exactly as it found them');
	}

	/**
	 * The unrescaled case still works exactly as before: an ordinary whole-container
	 * consume of a measured entry rebuilds amount 1, opened_amount and all, and does not
	 * regress into refusing a booking this fix has no reason to touch.
	 */
	public function testUndoOfAnOrdinaryMeasuredConsumeBookingStillRestoresTheContainer(): void
	{
		$productId = self::insertProduct('Undo Measured Ordinary Consume');

		$stock = StockService::GetInstance();
		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$pantry);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		$stock->OpenProduct($productId, 1);
		$stock->MeasureStockEntry($stockRowId, ['amount' => 0.5, 'qu_id' => self::$gram]);
		$stock->ConsumeProduct($productId, 1, false, StockService::TRANSACTION_TYPE_CONSUME);

		$consumeLogId = (int)self::$db->query(
			'SELECT id FROM stock_log WHERE product_id = ' . $productId . " AND transaction_type = 'consume' AND undone = 0"
		)->fetchColumn();

		$response = self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $consumeLogId]);
		self::assertSame(204, $response->getStatusCode(), 'The ordinary (unrescaled) undo still succeeds');

		$rebuilt = self::$db->query('SELECT amount, open, opened_amount, opened_qu_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($rebuilt, 'The container is rebuilt in `stock`');
		self::assertSame(1.0, (float)$rebuilt['amount'], 'Rebuilt amount is exactly 1, satisfying stock_measurement_coherence_check');
		self::assertSame(1, (int)$rebuilt['open'], 'Rebuilt row is open, satisfying stock_measurement_coherence_check');
		self::assertEqualsWithDelta(0.5, (float)$rebuilt['opened_amount'], 1e-9, 'The measured remainder is restored');
		self::assertSame(self::$gram, (int)$rebuilt['opened_qu_id'], 'The measurement unit is restored');
	}
}
