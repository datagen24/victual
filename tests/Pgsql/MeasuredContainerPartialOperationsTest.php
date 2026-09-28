<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #502 (#487 M2): "Partial measured-container operations lose measurements or
 * violate constraints".
 *
 * A measured open container (migration 0275: `opened_amount` set, which requires
 * `open = 1 AND amount = 1` under `stock_measurement_coherence_check`) can only ever
 * hold a measurement at amount = 1 (ADR-0022 decision 8). A partial consume or
 * transfer of such an entry - one that takes less than its whole amount - leaves a
 * remainder row at an amount other than 1, so no split of a measured entry can carry
 * its measurement forward on either resulting row.
 *
 * Before this fix:
 *  - ConsumeProduct()'s split (partial-take) branch silently cleared the remaining
 *    row's opened_* columns and never mirrored them onto the stock_log row it wrote,
 *    so the measurement was simply gone - not recoverable by undoing the booking, and
 *    the reduced row and the consumed remainder (once restored by an undo) came back
 *    as two ordinary unmeasured rows.
 *  - TransferProduct()'s split branch left the reduced row's opened_* columns
 *    untouched while shrinking its amount below 1, which the database's own
 *    stock_measurement_coherence_check (migrations/0275.pgsql.sql) rejects outright
 *    with a raw SQLSTATE 23514 instead of a truthful application-level refusal.
 *
 * Both paths now refuse before any row is touched - preserving the ADR-0022 contract
 * (measured means exactly one whole container) rather than guessing how to split a
 * measurement between two rows, matching the pattern #598's UndoBooking() fix and
 * EditStockEntry()'s own documented behaviour already establish elsewhere in this
 * class for the same "cannot represent this split coherently" shape.
 *
 * A whole-container (non-partial) consume or transfer of a measured entry is
 * unaffected and still succeeds - covered here as a control alongside
 * UndoMeasuredContainerCoherenceTest.php's own whole-consume coverage.
 */
class MeasuredContainerPartialOperationsTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static int $locationA;
	private static int $locationB;
	private static int $gram;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'measuredpartial-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Measured Partial A']);
		self::$locationA = (int)$location->fetchColumn();
		$location->execute(['Measured Partial B']);
		self::$locationB = (int)$location->fetchColumn();

		$unit = self::$db->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
		$unit->execute(['Measured Partial Gram', 'Measured Partial Grams']);
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

	private static function insertProduct(string $name, int $locationId): int
	{
		$statement = self::$db->prepare(
			'INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
		);
		$statement->execute([$name, $locationId, self::$gram, self::$gram, self::$gram, self::$gram]);

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

	/** Measures a freshly opened, single-unit stock entry and returns its `stock.id`. */
	private static function buyOpenAndMeasure(int $productId, float $measuredAmount): int
	{
		$stock = StockService::GetInstance();
		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$locationA);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		$stock->OpenProduct($productId, 1);
		$stock->MeasureStockEntry($stockRowId, ['amount' => $measuredAmount, 'qu_id' => self::$gram]);

		return $stockRowId;
	}

	/**
	 * The issue's own repro: measure a one-unit container at 0.6, consume 0.5. Before
	 * this fix the split branch silently cleared the measurement and left the reduced
	 * row - and, once its own booking was undone, a second unmeasured row - with no
	 * trace of the 0.6 measurement anywhere.
	 */
	public function testPartialConsumeOfAMeasuredContainerRefusesRatherThanLosingTheMeasurement(): void
	{
		$productId = self::insertProduct('M2 Partial Consume', self::$locationA);
		self::buyOpenAndMeasure($productId, 0.6);

		$before = self::productLedger($productId);

		$body = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 0.5]), new Response(), ['productId' => $productId]),
			400,
			'Partially consuming a measured container must be refused, not silently drop the measurement'
		);

		self::assertStringContainsString(
			'measured container',
			(string)($body['error_message'] ?? json_encode($body)),
			'Then: the refusal names the actual reason'
		);
		self::assertSame($before, self::productLedger($productId), 'Then: the refusal leaves stock and stock_log exactly as it found them');

		$row = self::$db->query('SELECT amount, open, opened_amount, opened_qu_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$row['amount'], 'Then: the container still holds its original whole amount');
		self::assertSame(1, (int)$row['open'], 'Then: the container is still open');
		self::assertEqualsWithDelta(0.6, (float)$row['opened_amount'], 1e-9, 'Then: the measurement itself is untouched');
		self::assertSame(self::$gram, (int)$row['opened_qu_id'], 'Then: the measurement unit is untouched');
	}

	/**
	 * The issue's own repro: measure a one-unit container at 0.6, then attempt to
	 * transfer 0.5 of it. Before this fix the split branch left the reduced row's
	 * measurement columns untouched while shrinking its amount below 1, hitting
	 * stock_measurement_coherence_check with a raw SQLSTATE 23514.
	 */
	public function testPartialTransferOfAMeasuredContainerRefusesRatherThanViolatingCoherence(): void
	{
		$productId = self::insertProduct('M2 Partial Transfer', self::$locationA);
		self::buyOpenAndMeasure($productId, 0.6);

		$before = self::productLedger($productId);

		$body = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', [
				'amount' => 0.5,
				'location_id_from' => self::$locationA,
				'location_id_to' => self::$locationB,
			]), new Response(), ['productId' => $productId]),
			400,
			'Partially transferring a measured container must be refused, not hit the raw coherence CHECK'
		);

		$message = (string)($body['error_message'] ?? json_encode($body));
		self::assertStringNotContainsStringIgnoringCase('sqlstate', $message, 'Then: this is not a raw database exception message');
		self::assertStringNotContainsStringIgnoringCase('23514', $message, 'Then: the raw constraint name/code never reaches the caller');
		self::assertStringContainsString('measured container', $message, 'Then: the refusal names the actual reason');
		self::assertSame($before, self::productLedger($productId), 'Then: the refusal leaves stock and stock_log exactly as it found them');

		$row = self::$db->query('SELECT amount, location_id, opened_amount, opened_qu_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$row['amount'], 'Then: the container still holds its original whole amount');
		self::assertSame(self::$locationA, (int)$row['location_id'], 'Then: the container never left the source location');
		self::assertEqualsWithDelta(0.6, (float)$row['opened_amount'], 1e-9, 'Then: the measurement itself is untouched');
	}

	/**
	 * Control: a whole-container consume of a measured entry is unaffected by this fix
	 * and still succeeds, deleting the row and mirroring its measurement onto the
	 * booking (already covered for undo by UndoMeasuredContainerCoherenceTest.php).
	 */
	public function testWholeContainerConsumeOfAMeasuredContainerStillSucceeds(): void
	{
		$productId = self::insertProduct('M2 Whole Consume', self::$locationA);
		self::buyOpenAndMeasure($productId, 0.6);

		$response = self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $response->getStatusCode(), 'A whole-container consume of a measured entry still succeeds');

		self::assertSame([], self::$db->query('SELECT * FROM stock WHERE product_id = ' . $productId)->fetchAll(), 'The container is fully consumed');

		$log = self::$db->query(
			"SELECT amount, opened_amount, opened_qu_id FROM stock_log WHERE product_id = $productId AND transaction_type = 'consume' AND undone = 0"
		)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(-1.0, (float)$log['amount'], 'The booking logs the whole amount taken');
		self::assertEqualsWithDelta(0.6, (float)$log['opened_amount'], 1e-9, 'The booking mirrors the measurement for a later undo');
	}

	/**
	 * Control: a whole-row transfer of a measured entry is unaffected by this fix and
	 * still succeeds, relocating the row in place with its measurement intact.
	 */
	public function testWholeContainerTransferOfAMeasuredContainerStillSucceeds(): void
	{
		$productId = self::insertProduct('M2 Whole Transfer', self::$locationA);
		$stockRowId = self::buyOpenAndMeasure($productId, 0.6);

		$response = self::$stock->TransferProduct(self::request('POST', [
			'amount' => 1,
			'location_id_from' => self::$locationA,
			'location_id_to' => self::$locationB,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $response->getStatusCode(), 'A whole-container transfer of a measured entry still succeeds');

		$row = self::$db->query('SELECT id, amount, location_id, opened_amount, opened_qu_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame($stockRowId, (int)$row['id'], 'The same physical row is relocated, not replaced');
		self::assertSame(1.0, (float)$row['amount'], 'The whole amount moves with it');
		self::assertSame(self::$locationB, (int)$row['location_id'], 'The row now sits at the destination location');
		self::assertEqualsWithDelta(0.6, (float)$row['opened_amount'], 1e-9, 'The measurement survives the whole-row transfer');
	}
}
