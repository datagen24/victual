<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PDOException;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #774, ADR-0045 gate 1 option (a): stock.legacy_zero_price and
 * stock_log.legacy_zero_price mark a stored price of 0 that is read as unknown. Every write
 * that builds a row from another row's price must copy the marker with it, because a copied 0
 * without its marker would silently become a known zero (the CHECK only catches the opposite
 * mistake). One test per write path ADR-0045 lists, each booking from a marked row, plus
 * compaction, which must never merge a marked 0 with a known 0.
 */
class LegacyZeroPricePropagationTest extends PgsqlSchemaTestCase
{
	private const NEVER_EXPIRES = '2999-12-31';

	private static PDO $db;
	private static int $locationA;
	private static int $locationB;
	private static int $unit;

	/** An ADMIN caller, two locations and one unit every product uses for every purpose. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'legacyzero-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Legacy zero A']);
		self::$locationA = (int)$location->fetchColumn();
		$location->execute(['Legacy zero B']);
		self::$locationB = (int)$location->fetchColumn();
		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name, name_plural) VALUES ('legacyzero-unit', 'legacyzero-units') RETURNING id")->fetchColumn();
	}

	private static function product(): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['legacyzero product ' . uniqid(), self::$locationA, self::$unit, self::$unit, self::$unit, self::$unit]);

		return (int)$statement->fetchColumn();
	}

	/** Purchases $amount priced 0 and marks the entry and its booking as an unknown price, as migration 0315 does. */
	private static function markedPurchase(int $productId, float $amount, string $bestBefore = self::NEVER_EXPIRES): int
	{
		$transactionId = null;
		StockService::GetInstance()->AddProduct($productId, $amount, $bestBefore, StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0, self::$locationA, null, $transactionId);
		self::$db->prepare('UPDATE stock_log SET legacy_zero_price = 1 WHERE transaction_id = ?')->execute([$transactionId]);
		$statement = self::$db->prepare('UPDATE stock SET legacy_zero_price = 1 WHERE stock_id IN (SELECT stock_id FROM stock_log WHERE transaction_id = ?) RETURNING id');
		$statement->execute([$transactionId]);

		return (int)$statement->fetchColumn();
	}

	/** @return list<array{price: ?float, legacy_zero_price: int}> every stock row of the product, in id order */
	private static function stockRows(int $productId): array
	{
		return self::$db->query("SELECT price, legacy_zero_price, location_id, amount FROM stock WHERE product_id = $productId ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
	}

	/** @return array<string, list<int>> markers of the product's bookings by transaction type */
	private static function bookingMarkers(int $productId): array
	{
		$markers = [];
		foreach (self::$db->query("SELECT transaction_type, legacy_zero_price FROM stock_log WHERE product_id = $productId ORDER BY id")->fetchAll(PDO::FETCH_NUM) as [$type, $marker])
		{
			$markers[$type][] = (int)$marker;
		}

		return $markers;
	}

	public function testAConsumeCopiesTheMarkerOntoItsBookings(): void
	{
		$product = self::product();
		self::markedPurchase($product, 3);

		StockService::GetInstance()->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		StockService::GetInstance()->ConsumeProduct($product, 2, false, StockService::TRANSACTION_TYPE_CONSUME);

		self::assertSame([1, 1], self::bookingMarkers($product)['consume'], 'a partial and a whole consume both book an unknown price');
	}

	public function testAPartialTransferCopiesTheMarkerOntoTheNewEntryAndBothBookings(): void
	{
		$product = self::product();
		self::markedPurchase($product, 3);

		StockService::GetInstance()->TransferProduct($product, 1, self::$locationA, self::$locationB);

		foreach (self::stockRows($product) as $row)
		{
			self::assertSame(1, (int)$row['legacy_zero_price'], 'both entries, the one left and the one moved, keep the marker');
		}
		$markers = self::bookingMarkers($product);
		self::assertSame([1], $markers['transfer_from']);
		self::assertSame([1], $markers['transfer_to']);
	}

	public function testAPartialOpenCopiesTheMarkerOntoTheRemainder(): void
	{
		$product = self::product();
		self::markedPurchase($product, 3);

		StockService::GetInstance()->OpenProduct($product, 1);

		self::assertCount(2, self::stockRows($product), 'the open split the entry');
		foreach (self::stockRows($product) as $row)
		{
			self::assertSame(1, (int)$row['legacy_zero_price']);
		}
		self::assertSame([1], self::bookingMarkers($product)['product-opened']);
	}

	public function testAMeasurementCopiesTheMarkerOntoBothBookings(): void
	{
		$product = self::product();
		$row = self::markedPurchase($product, 1, '2030-01-01');
		StockService::GetInstance()->OpenProduct($product, 1);

		StockService::GetInstance()->MeasureStockEntry($row, ['amount' => 0.5, 'qu_id' => self::$unit]);

		$markers = self::bookingMarkers($product);
		self::assertNotEmpty(array_merge($markers['stock-measured-old'] ?? [], $markers['stock-measured-new'] ?? []), 'the measurement booked');
		foreach (array_merge($markers['stock-measured-old'] ?? [], $markers['stock-measured-new'] ?? []) as $marker)
		{
			self::assertSame(1, $marker);
		}
	}

	public function testAnEditThatKeepsThePriceKeepsTheMarkerAndOneThatSetsZeroMakesItKnown(): void
	{
		$keep = StockService::KeepStoredValue();
		$product = self::product();
		$row = self::markedPurchase($product, 2);

		StockService::GetInstance()->EditStockEntry($row, 2, $keep, $keep, $keep, $keep, $keep, $keep, 'kept price');
		self::assertSame(1, (int)self::stockRows($product)[0]['legacy_zero_price'], 'a kept price is read as before');
		self::assertSame([1], self::bookingMarkers($product)['stock-edit-new']);

		StockService::GetInstance()->EditStockEntry($row, 2, $keep, $keep, $keep, 0, $keep, $keep, 'free after all');
		self::assertSame(0, (int)self::stockRows($product)[0]['legacy_zero_price'], 'an explicit 0 is a known zero');
		self::assertSame([1, 1], self::bookingMarkers($product)['stock-edit-old'], 'each old booking records the row as it was');
		self::assertSame([1, 0], self::bookingMarkers($product)['stock-edit-new']);
	}

	public function testUndoingAConsumeRebuildsTheEntryWithTheMarker(): void
	{
		$product = self::product();
		self::markedPurchase($product, 2);
		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct($product, 2, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);
		self::assertSame([], self::stockRows($product));

		StockService::GetInstance()->UndoTransaction($transactionId);

		self::assertSame(1, (int)self::stockRows($product)[0]['legacy_zero_price']);
	}

	public function testUndoingATransferAnOpenAndAnEditRestoresTheMarker(): void
	{
		$stock = StockService::GetInstance();
		$keep = StockService::KeepStoredValue();

		$transferred = self::product();
		self::markedPurchase($transferred, 3);
		$transfer = null;
		$stock->TransferProduct($transferred, 1, self::$locationA, self::$locationB, 'default', $transfer);
		$stock->UndoTransaction($transfer);
		self::assertSame([1], array_map('intval', array_column(self::stockRows($transferred), 'legacy_zero_price')), 'the transferred part is folded back, still unknown');

		$opened = self::product();
		self::markedPurchase($opened, 3);
		$open = null;
		$stock->OpenProduct($opened, 1, 'default', $open);
		$stock->UndoTransaction($open);
		foreach (self::stockRows($opened) as $row)
		{
			self::assertSame(1, (int)$row['legacy_zero_price']);
		}

		$edited = self::product();
		$row = self::markedPurchase($edited, 2);
		$stock->EditStockEntry($row, 2, $keep, $keep, $keep, 0, $keep, $keep, null);
		$editTransaction = self::$db->query("SELECT transaction_id FROM stock_log WHERE product_id = $edited AND transaction_type = 'stock-edit-new'")->fetchColumn();
		$stock->UndoTransaction($editTransaction);
		self::assertSame(1, (int)self::stockRows($edited)[0]['legacy_zero_price'], 'undoing the edit restores the unknown reading');
	}

	public function testCompactionNeverMergesAMarkedZeroWithAKnownZero(): void
	{
		$product = self::product();
		self::markedPurchase($product, 1);
		self::markedPurchase($product, 1);
		foreach ([1, 1] as $amount)
		{
			StockService::GetInstance()->AddProduct($product, $amount, self::NEVER_EXPIRES, StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0, self::$locationA);
		}

		StockService::GetInstance()->CompactStockEntries($product);

		$rows = self::stockRows($product);
		self::assertCount(2, $rows, 'each reading compacts on its own');
		self::assertSame([[1, 2.0], [0, 2.0]], array_map(fn ($row) => [(int)$row['legacy_zero_price'], (float)$row['amount']], $rows));
	}

	public function testOnlyARowPricedZeroCanBeMarked(): void
	{
		$product = self::product();
		$row = self::markedPurchase($product, 1);

		foreach (["UPDATE stock SET price = 1 WHERE id = $row", "UPDATE stock SET price = NULL WHERE id = $row", "UPDATE stock SET legacy_zero_price = 2 WHERE id = $row"] as $sql)
		{
			try
			{
				self::$db->exec($sql);
				self::fail("$sql must be refused");
			}
			catch (PDOException $ex)
			{
				self::assertSame('23514', (string)$ex->getCode(), $sql);
			}
		}
	}
}
