<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * StockService::ConsumeProduct()'s optional trailing $usedDate (ADR-0041, acceptance
 * prerequisite 3). A late event books the date it happened on; every existing caller, which
 * passes no date, still books today.
 */
class ConsumeProductUsedDateTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static int $location;
	private static int $unit;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'used-date-caller', 'fixture') ON CONFLICT DO NOTHING");
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('Used date location') RETURNING id")->fetchColumn();
		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('Used date tablet') RETURNING id")->fetchColumn();
	}

	private static function product(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name, self::$location, self::$unit, self::$unit, self::$unit, self::$unit]);
		return (int)$statement->fetchColumn();
	}

	/** @return string[] the used_date of every booking of the transaction */
	private static function usedDates(string $transactionId): array
	{
		$statement = self::$db->prepare('SELECT used_date::text FROM stock_log WHERE transaction_id = ? ORDER BY id');
		$statement->execute([$transactionId]);
		return $statement->fetchAll(PDO::FETCH_COLUMN);
	}

	public function testACallThatPassesNoDateBooksToday(): void
	{
		$product = self::product('Used date default');
		StockService::GetInstance()->AddProduct($product, 3, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);

		self::assertSame([date('Y-m-d')], self::usedDates($transactionId));
	}

	public function testAGivenDateIsBookedOnEveryBookingOfAMultiLotConsume(): void
	{
		$product = self::product('Used date two lots');
		$service = StockService::GetInstance();
		$service->AddProduct($product, 2, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		$service->AddProduct($product, 2, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-02', null, self::$location);
		$transactionId = null;
		$service->ConsumeProduct($product, 3.5, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId, false, false, '2026-10-07');

		self::assertSame(['2026-10-07', '2026-10-07'], self::usedDates($transactionId), 'both bookings carry the given date');
		$service->UndoTransaction($transactionId);
		self::assertEquals(4.0, (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product")->fetchColumn(), 'undo restores the stock');
	}

	#[DataProvider('badDates')]
	public function testAMalformedDateIsRefusedAndBooksNothing(string $date): void
	{
		$product = self::product('Used date bad ' . md5($date));
		StockService::GetInstance()->AddProduct($product, 2, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		$transactionId = null;

		try
		{
			StockService::GetInstance()->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId, false, false, $date);
			self::fail('A malformed used date must be refused');
		}
		catch (\InvalidArgumentException $exception)
		{
			self::assertStringContainsString('used date', $exception->getMessage());
		}

		self::assertEquals(2.0, (float)self::$db->query("SELECT sum(amount) FROM stock WHERE product_id = $product")->fetchColumn(), 'nothing was consumed');
	}

	public static function badDates(): array
	{
		return [['2026-13-01'], ['2026-02-30'], ['07/10/2026'], ['2026-1-7'], ['']];
	}
}
