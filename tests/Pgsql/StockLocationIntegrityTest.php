<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\Database\StockLocationConstraint;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class StockLocationIntegrityTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static GenericEntityApiController $objects;
	private static StockApiController $api;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$objects = new GenericEntityApiController($container);
		self::$api = new StockApiController($container);
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'location-test', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name='ADMIN'");
	}

	protected function setUp(): void
	{
		self::$db->exec('TRUNCATE stock, stock_log, products, locations CASCADE');
		self::$db->exec("INSERT INTO locations(id, name) VALUES (501, 'Original'), (502, 'Current')");
		self::$db->exec("INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (501, 'Fixture', 502, 2, 2, 2, 2)");
	}

	private static function request(string $method = 'POST', ?array $body = null)
	{
		return (new ServerRequestFactory())->createServerRequest($method, '/api')->withParsedBody($body)->withHeader('Content-Type', 'application/json');
	}

	private static function insertStock(?int $location, float $amount = 2): int
	{
		$stmt = self::$db->prepare("INSERT INTO stock(product_id, amount, stock_id, location_id, best_before_date) VALUES (501, ?, 'entry', ?, '2035-01-01') RETURNING id");
		$stmt->execute([$amount, $location]);
		return (int)$stmt->fetchColumn();
	}

	private static function log(string $type, float $amount, ?int $location, ?int $rowId = null, ?string $correlation = null): int
	{
		$stmt = self::$db->prepare("INSERT INTO stock_log(product_id, amount, stock_id, location_id, transaction_type, user_id, best_before_date, purchased_date, stock_row_id, correlation_id, transaction_id) VALUES (501, ?, 'entry', ?, ?, 9000, '2035-01-01', '2026-01-01', ?, ?, 'undo-group') RETURNING id");
		$stmt->execute([$amount, $location, $type, $rowId, $correlation]);
		return (int)$stmt->fetchColumn();
	}

	private static function state(): array
	{
		$result = [];
		foreach (['stock', 'stock_log', 'outbox', 'labels'] as $table)
		{
			$result[$table] = self::$db->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
		}
		return $result;
	}

	private static function assertError(Response $response, string $message): void
	{
		self::assertSame(400, $response->getStatusCode());
		self::assertSame(['error_message' => $message], json_decode((string)$response->getBody(), true));
	}

	public function testLocationDeletionRefusesEvenZeroAmountStockAndAllowsUnusedLocation(): void
	{
		self::insertStock(501, 0);
		$before = self::state();
		self::assertError(self::$objects->DeleteObject(self::request('DELETE'), new Response(), ['entity' => 'locations', 'objectId' => 501]), StockLocationConstraint::DELETE_MESSAGE);
		self::assertSame($before, self::state());

		// 502 is fixture product 501's own location_id (see setUp()), so migrations/
		// 0295.pgsql.sql's products_location_id_fkey (issue #552) refuses it too, through the
		// same generic reference-refusal path ReferenceRefusalTest.php and
		// ProductReferenceIntegrityTest.php already cover for the other five product foreign
		// keys - not asserted again here. A location genuinely unused by anything - no stock,
		// no product - still deletes normally.
		self::$db->exec("INSERT INTO locations(id, name) VALUES (503, 'Unused')");
		self::assertSame(204, self::$objects->DeleteObject(self::request('DELETE'), new Response(), ['entity' => 'locations', 'objectId' => 503])->getStatusCode());
	}

	public function testForeignKeyAndNullableDefaultBehavior(): void
	{
		$id = self::insertStock(null);
		self::assertSame(502, (int)self::$db->query('SELECT location_id FROM stock WHERE id=' . $id)->fetchColumn());
		self::$db->exec('UPDATE stock SET location_id=NULL WHERE id=' . $id);
		self::assertNull(self::$db->query('SELECT location_id FROM stock WHERE id=' . $id)->fetchColumn());
		foreach (["INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(501, 1, 'bad', 999)", 'UPDATE stock SET location_id=999 WHERE id=' . $id] as $sql)
		{
			try { self::$db->exec($sql); self::fail('Foreign key must refuse invalid stock'); }
			catch (\PDOException $ex) { self::assertTrue(StockLocationConstraint::IsViolation($ex)); }
		}
		self::$db->exec('UPDATE locations SET active=0 WHERE id=501');
		self::insertStock(501);
		try { self::$db->exec('DELETE FROM locations WHERE id=501'); self::fail('Referenced deletion must fail'); }
		catch (\PDOException $ex) { self::assertTrue(StockLocationConstraint::IsViolation($ex)); }
	}

	/**
	 * Before migrations/0295.pgsql.sql (issue #552), this test deleted location 502
	 * directly to construct a product whose own default location had gone stale, then
	 * asserted the runtime "Location does not exist" refusal StockService::AddProduct()
	 * raises for exactly that case. products_location_id_fkey closes that path at the
	 * schema level instead: product 501 names location 502 as its own location_id
	 * (setUp()), so the direct DELETE this test used to rely on is now itself refused -
	 * proved once here rather than repeated, since ProductReferenceIntegrityTest.php
	 * already covers the same foreign key through the application's own delete route.
	 * The location is therefore never actually gone, so the runtime check this test used
	 * to exercise never fires: booking against product 501's default location succeeds.
	 */
	public function testDeletingAProductsOwnLocationIsRefusedSoItsDefaultNeverGoesStale(): void
	{
		$before = self::state();
		try
		{
			self::$db->exec('DELETE FROM locations WHERE id=502');
			self::fail('Deleting a location a product names as its own location_id must be refused');
		}
		catch (\PDOException $ex)
		{
			self::assertSame('23503', $ex->errorInfo[0] ?? $ex->getCode());
			self::assertStringContainsString('"products_location_id_fkey"', $ex->errorInfo[2] ?? $ex->getMessage());
		}
		self::assertSame($before, self::state());

		$response = self::$api->AddProduct(self::request('POST', ['amount' => 2, 'best_before_date' => '2035-01-01']), new Response(), ['productId' => 501]);
		self::assertLessThan(300, $response->getStatusCode(), 'The location was never actually deleted, so the booking must succeed: ' . (string)$response->getBody());
		self::assertSame(
			[['location_id' => 502, 'amount' => '2']],
			self::$db->query("SELECT location_id, amount::text FROM stock WHERE best_before_date='2035-01-01'")->fetchAll(PDO::FETCH_ASSOC)
		);
	}

	public static function restoringTypes(): array
	{
		return [['consume', -2], ['inventory-correction', -2], ['transfer_from', -2], ['stock-edit-old', 2]];
	}

	#[DataProvider('restoringTypes')]
	public function testDeletedHistoricalLocationRefusesWholeCorrelatedUndo(string $type, float $amount): void
	{
		$rowId = in_array($type, ['transfer_from', 'stock-edit-old'], true) ? self::insertStock(502) : null;
		if ($rowId !== null)
		{
			$stmt = self::$db->prepare("INSERT INTO labels(uid, kind, target_id) VALUES (?, 'stock_entry', ?)");
			$stmt->execute(['A' . str_pad((string)$rowId, 12, '0', STR_PAD_LEFT), $rowId]);
		}
		$oldId = self::log($type, $amount, 501, $rowId, 'pair');
		// This newer member is reversed before the failing restore. Assert it rolls back.
		self::log($type === 'transfer_from' ? 'transfer_to' : 'stock-edit-new', 2, 502, $rowId, 'pair');
		self::$db->exec('DELETE FROM locations WHERE id=501');
		$before = self::state();
		self::assertError(self::$api->UndoBooking(self::request(), new Response(), ['bookingId' => $oldId]), 'Cannot undo booking: original location no longer exists');
		self::assertSame($before, self::state());
	}

	public function testTransactionUndoRollsBackEarlierSuccessfulRestore(): void
	{
		self::log('consume', -2, 501);
		self::log('consume', -3, 502);
		self::$db->exec('DELETE FROM locations WHERE id=501');
		$before = self::state();
		self::assertError(self::$api->UndoTransaction(self::request(), new Response(), ['transactionId' => 'undo-group']), 'Cannot undo booking: original location no longer exists');
		self::assertSame($before, self::state());
	}

	public function testTransferUndoRestoresOriginalLocationInsteadOfProductDefault(): void
	{
		$rowId = self::insertStock(502);
		$id = self::log('transfer_from', -2, 501, $rowId, 'transfer');
		self::log('transfer_to', 2, 502, $rowId, 'transfer');
		StockService::GetInstance()->UndoBooking($id);
		self::assertSame([['location_id' => 501, 'amount' => '2']], self::$db->query('SELECT location_id, amount::text FROM stock')->fetchAll(PDO::FETCH_ASSOC));
		self::assertSame(2, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE undone=1')->fetchColumn());
	}

	public function testNullAndInactiveHistoricalLocationsRemainRestorable(): void
	{
		$id = self::log('consume', -2, 501);
		self::$db->exec('UPDATE locations SET active=0 WHERE id=501');
		StockService::GetInstance()->UndoBooking($id);
		self::assertSame(501, (int)self::$db->query('SELECT location_id FROM stock')->fetchColumn());
		$id = self::log('consume', -1, 502);
		self::$db->exec('UPDATE stock_log SET location_id=NULL WHERE id=' . $id);
		StockService::GetInstance()->UndoBooking($id);
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM stock WHERE location_id=502')->fetchColumn());
	}

	public function testNonRestoringUndoDoesNotRequireHistoricalLocation(): void
	{
		$id = self::log('stock-measured-new', 0, 501);
		self::$db->exec('DELETE FROM locations WHERE id=501');
		StockService::GetInstance()->UndoBooking($id);
		self::assertSame(1, (int)self::$db->query('SELECT undone FROM stock_log WHERE id=' . $id)->fetchColumn());
	}

	public function testConstraintDetectionDoesNotRelabelOtherDatabaseErrors(): void
	{
		foreach ([['23503', '"other_fkey"'], ['23505', '"stock_location_id_fkey"'], ['23503', '"stock_location_id_fkey_suffix"']] as [$code, $message])
		{
			$exception = new \PDOException($message);
			$exception->errorInfo = [$code, null, $message];
			self::assertFalse(StockLocationConstraint::IsViolation($exception));
		}
	}
}
