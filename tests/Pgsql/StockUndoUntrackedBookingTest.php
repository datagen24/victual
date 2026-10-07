<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\StockLineage;

/**
 * ADR-0036 section 7 rule 1: a booking with no allocations is untracked and its undo takes the
 * legacy rules unchanged. After migration 0304 such bookings are the consumptions, openings,
 * transfers and edits of an unknown family, written before lineage was recorded.
 *
 * Every booking the current writers make is tracked, so each case here makes one by a real
 * flow and then deletes its allocations - the state the backfill leaves an unknown family's
 * non-addition bookings in. Contributions are left in place, so the undo runs on a consistent
 * row and reaches the legacy branch. Each case asserts the legacy outcome and that I1 to I3
 * hold afterwards: the reconciliation after a legacy undo turns rows it changed into a pool
 * rather than leaving them inconsistent.
 *
 * The same branches are what the differential harness's SQLite side runs, where the lineage
 * tables do not exist.
 */
class StockUndoUntrackedBookingTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockService $stock;
	private static int $a;
	private static int $b;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'untracked-undo', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$stock = StockService::GetInstance();
		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Untracked A']);
		self::$a = (int)$location->fetchColumn();
		$location->execute(['Untracked B']);
		self::$b = (int)$location->fetchColumn();
	}

	private static function product(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute(['Untracked ' . $name, self::$a]);
		return (int)$statement->fetchColumn();
	}

	private static function buy(int $product, float $amount, string $due = '2999-12-31'): int
	{
		$transaction = null;
		self::$stock->AddProduct($product, $amount, $due, StockService::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, self::$a, null, $transaction);
		return self::bookings($transaction)[0];
	}

	private static function bookings(string $transactionId): array
	{
		return array_map('intval', self::$db->query("SELECT id FROM stock_log WHERE transaction_id = '$transactionId' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
	}

	/** Deletes the allocations of the given bookings: they become untracked. */
	private static function forget(int ...$bookings): void
	{
		self::$db->exec('DELETE FROM stock_booking_lots WHERE booking_id IN (' . implode(',', $bookings) . ')');
	}

	private static function rows(int $product): array
	{
		return self::$db->query("SELECT id, amount, location_id, open FROM stock WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function amounts(int $product): array
	{
		return array_map(fn($row) => (float)$row['amount'], self::rows($product));
	}

	private static function refused(int $product, callable $work, string $expected): void
	{
		$before = json_encode([self::rows($product), self::$db->query("SELECT * FROM stock_log WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)]);
		try
		{
			$work();
			self::fail("must be refused: $expected");
		}
		catch (\PHPUnit\Framework\AssertionFailedError $failure)
		{
			throw $failure;
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString($expected, $exception->getMessage());
		}
		self::assertSame($before, json_encode([self::rows($product), self::$db->query("SELECT * FROM stock_log WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)]));
	}

	// --- additions -------------------------------------------------------------------------

	public function testAnUntrackedPurchaseUndoSubtractsFromItsStockIdAtItsLocation(): void
	{
		$p = self::product('purchase single row');
		$first = self::buy($p, 2);
		$second = self::buy($p, 3);
		self::$stock->CompactStockEntries($p);
		// The legacy rule matches the merged row by the later purchase's stock_id, which the
		// survivor (MAX(id)) keeps.
		self::forget($second);
		self::$stock->UndoBooking($second);
		self::assertSame([2.0], self::amounts($p), 'The legacy rule subtracts the booking from the single matching row');
		StockLineage::AssertHolds(self::$db, $p);
		self::assertSame(0, (int)self::$db->query("SELECT undone FROM stock_log WHERE id = $first")->fetchColumn());
	}

	public function testAnUntrackedPurchaseUndoRefusesALaterBookingOnItsStockId(): void
	{
		$p = self::product('purchase dependent');
		$purchase = self::buy($p, 5);
		$consume = self::bookings(self::$stock->ConsumeProduct($p, 1, false, StockService::TRANSACTION_TYPE_CONSUME))[0];
		self::forget($purchase, $consume);
		self::refused($p, fn() => self::$stock->UndoBooking($purchase), 'subsequent dependent bookings');
	}

	public function testAnUntrackedPurchaseUndoRefusesWhenItsRowsHoldLessOrAreGoneOrAmbiguous(): void
	{
		$p = self::product('purchase less');
		$purchase = self::buy($p, 3);
		self::forget($purchase);
		self::$db->exec("UPDATE stock SET amount = 1 WHERE product_id = $p");
		self::refused($p, fn() => self::$stock->UndoBooking($purchase), 'holds less than this booking added');

		$p = self::product('purchase gone');
		$purchase = self::buy($p, 3);
		self::forget($purchase);
		self::$db->exec("DELETE FROM stock WHERE product_id = $p");
		self::refused($p, fn() => self::$stock->UndoBooking($purchase), 'does not exist or was already undone');

		$p = self::product('purchase ambiguous');
		$purchase = self::buy($p, 3);
		self::forget($purchase);
		self::$db->exec("INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
			SELECT product_id, 1, best_before_date, purchased_date, stock_id, price, location_id FROM stock WHERE product_id = $p");
		self::refused($p, fn() => self::$stock->UndoBooking($purchase), 'split across multiple rows');
	}

	// --- transfers -------------------------------------------------------------------------

	public function testAnUntrackedSplitTransferUndoReturnsTheUnitsToItsSourceRow(): void
	{
		$p = self::product('transfer split');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$stock->UndoTransaction($transfer);
		self::assertSame([5.0], self::amounts($p));
		StockLineage::AssertHolds(self::$db, $p);
	}

	public function testAnUntrackedSplitTransferUndoKeepsWhatElseIsAtTheDestinationAndRebuildsAMissingSource(): void
	{
		$p = self::product('transfer partial destination');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("UPDATE stock SET amount = amount + 1 WHERE product_id = $p AND location_id = " . self::$b);
		self::$db->exec("DELETE FROM stock WHERE product_id = $p AND location_id = " . self::$a);
		self::$stock->UndoTransaction($transfer);
		$rows = self::rows($p);
		self::assertSame([[1.0, self::$b], [2.0, self::$a]], array_map(fn($row) => [(float)$row['amount'], (int)$row['location_id']], $rows),
			'The destination keeps its extra unit and the source is rebuilt with the transferred two');
		StockLineage::AssertHolds(self::$db, $p);
	}

	public function testAnUntrackedTransferUndoRefusesAMissingOrShortDestination(): void
	{
		$p = self::product('transfer gone');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("DELETE FROM stock WHERE product_id = $p AND location_id = " . self::$b);
		self::refused($p, fn() => self::$stock->UndoTransaction($transfer), 'destination stock entry no longer exists');

		$p = self::product('transfer short');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("UPDATE stock SET amount = 1 WHERE product_id = $p AND location_id = " . self::$b);
		self::refused($p, fn() => self::$stock->UndoTransaction($transfer), 'holds less than this booking added');
	}

	public function testAnUntrackedTransferRecordedWithoutRowIdsMatchesByStockIdAndLocation(): void
	{
		$p = self::product('transfer no row ids');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("UPDATE stock_log SET stock_row_id = NULL WHERE transaction_id = '$transfer'");
		self::$stock->UndoTransaction($transfer);
		self::assertSame([5.0], self::amounts($p));
		StockLineage::AssertHolds(self::$db, $p);

		$p = self::product('transfer no row ids, ambiguous');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("UPDATE stock_log SET stock_row_id = NULL WHERE transaction_id = '$transfer'");
		self::$db->exec("INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
			SELECT product_id, 1, best_before_date, purchased_date, stock_id, price, location_id FROM stock WHERE product_id = $p AND location_id = " . self::$b);
		self::refused($p, fn() => self::$stock->UndoTransaction($transfer), 'more than one stock entry sharing this lot');

		$p = self::product('transfer no row ids, gone');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("UPDATE stock_log SET stock_row_id = NULL WHERE transaction_id = '$transfer'");
		self::$db->exec("DELETE FROM stock WHERE product_id = $p AND location_id = " . self::$b);
		self::refused($p, fn() => self::$stock->UndoTransaction($transfer), 'does not exist or was already undone');

		$p = self::product('transfer no row ids, ambiguous source');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("UPDATE stock_log SET stock_row_id = NULL WHERE transaction_id = '$transfer'");
		self::$db->exec("INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
			SELECT product_id, 1, best_before_date, purchased_date, stock_id, price, location_id FROM stock WHERE product_id = $p AND location_id = " . self::$a);
		self::refused($p, fn() => self::$stock->UndoTransaction($transfer), 'its source holds more than one stock entry');
	}

	public function testAnUntrackedWholeRowTransferWhoseRowChangedFallsBackToSubtracting(): void
	{
		$p = self::product('transfer whole changed');
		self::buy($p, 2);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		self::forget(...self::bookings($transfer));
		self::$db->exec("UPDATE stock SET amount = 3 WHERE product_id = $p");
		self::$stock->UndoTransaction($transfer);
		self::assertSame([[1.0, self::$b], [2.0, self::$a]], array_map(fn($row) => [(float)$row['amount'], (int)$row['location_id']], self::rows($p)));
		StockLineage::AssertHolds(self::$db, $p);
	}

	// --- openings and edits ----------------------------------------------------------------

	public function testAnUntrackedOpeningUndoReopensItsRowById(): void
	{
		$p = self::product('open');
		self::buy($p, 2);
		$open = self::bookings(self::$stock->OpenProduct($p, 2))[0];
		self::forget($open);
		self::$stock->UndoBooking($open);
		self::assertSame(0, (int)self::rows($p)[0]['open']);
		StockLineage::AssertHolds(self::$db, $p);

		$p = self::product('open changed');
		self::buy($p, 2);
		$open = self::bookings(self::$stock->OpenProduct($p, 2))[0];
		self::forget($open);
		self::$db->exec("UPDATE stock SET amount = 3 WHERE product_id = $p");
		self::refused($p, fn() => self::$stock->UndoBooking($open), 'no longer exists in that state');
	}

	public function testAnUntrackedOpeningRecordedWithoutARowIdMatchesByItsColumns(): void
	{
		$p = self::product('open no row id');
		self::buy($p, 2);
		$open = self::bookings(self::$stock->OpenProduct($p, 2))[0];
		self::forget($open);
		self::$db->exec("UPDATE stock_log SET stock_row_id = NULL WHERE id = $open");
		self::$stock->UndoBooking($open);
		self::assertSame(0, (int)self::rows($p)[0]['open']);

		$p = self::product('open no row id, none');
		self::buy($p, 2);
		$open = self::bookings(self::$stock->OpenProduct($p, 2))[0];
		self::forget($open);
		self::$db->exec("UPDATE stock_log SET stock_row_id = NULL WHERE id = $open");
		self::$db->exec("UPDATE stock SET amount = 3 WHERE product_id = $p");
		self::refused($p, fn() => self::$stock->UndoBooking($open), 'no longer exists in that state');

		$p = self::product('open no row id, two');
		self::buy($p, 2);
		$open = self::bookings(self::$stock->OpenProduct($p, 2))[0];
		self::forget($open);
		self::$db->exec("UPDATE stock_log SET stock_row_id = NULL WHERE id = $open");
		self::$db->exec("INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, open, opened_date)
			SELECT product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, open, opened_date FROM stock WHERE product_id = $p");
		self::refused($p, fn() => self::$stock->UndoBooking($open), 'more than one stock entry matches');
	}

	public function testAnUntrackedEditUndoRestoresTheRowInPlaceOrRefusesAChangedRow(): void
	{
		$p = self::product('edit');
		self::buy($p, 4, '2030-01-01');
		$row = (int)self::rows($p)[0]['id'];
		$keep = StockService::KeepStoredValue();
		$edit = self::$stock->EditStockEntry($row, 3, '2031-01-01', $keep, $keep, $keep, true, $keep);
		self::forget(...self::bookings($edit));
		self::$stock->UndoTransaction($edit);
		$restored = self::$db->query("SELECT amount, best_before_date, open FROM stock WHERE id = $row")->fetch(PDO::FETCH_ASSOC);
		self::assertSame([4.0, '2030-01-01', 0], [(float)$restored['amount'], $restored['best_before_date'], (int)$restored['open']]);
		StockLineage::AssertHolds(self::$db, $p);

		$p = self::product('edit changed');
		self::buy($p, 4);
		$row = (int)self::rows($p)[0]['id'];
		$edit = self::$stock->EditStockEntry($row, 3, $keep, $keep, $keep, $keep, $keep, $keep);
		self::forget(...self::bookings($edit));
		self::$db->exec("UPDATE stock SET amount = 5 WHERE id = $row");
		self::refused($p, fn() => self::$stock->UndoTransaction($edit), 'has changed since this edit');

		$p = self::product('edit gone');
		self::buy($p, 4);
		$row = (int)self::rows($p)[0]['id'];
		$edit = self::$stock->EditStockEntry($row, 3, $keep, $keep, $keep, $keep, $keep, $keep);
		self::forget(...self::bookings($edit));
		self::$db->exec("DELETE FROM stock WHERE id = $row");
		self::refused($p, fn() => self::$stock->UndoTransaction($edit), 'no longer exists in its edited state');
	}

	// --- tracked-path refusals for half-missing pairs ----------------------------------------

	public function testTrackedHalvesOfAPairRefuseWhenTheirOtherHalfIsMissingOrLive(): void
	{
		$p = self::product('pair halves');
		self::buy($p, 5);
		$transfer = self::$stock->TransferProduct($p, 2, self::$a, self::$b);
		[$from, $to] = self::bookings($transfer);
		self::refused($p, fn() => self::$stock->UndoBooking($from, true), 'undo the transfer or edit it belongs to');

		self::$db->exec("DELETE FROM stock_log WHERE id = $from");
		self::refused($p, fn() => self::$stock->UndoBooking($to), 'its transfer has no source booking');

		$p = self::product('edit half');
		self::buy($p, 4);
		$row = (int)self::rows($p)[0]['id'];
		$keep = StockService::KeepStoredValue();
		[$old, $new] = self::bookings(self::$stock->EditStockEntry($row, 3, $keep, $keep, $keep, $keep, $keep, $keep));
		self::$db->exec("DELETE FROM stock_log WHERE id = $old");
		self::refused($p, fn() => self::$stock->UndoBooking($new), 'its edit has no snapshot');

		$p = self::product('open gone');
		self::buy($p, 2);
		$open = self::bookings(self::$stock->OpenProduct($p, 2))[0];
		self::$db->exec("DELETE FROM stock WHERE product_id = $p");
		self::refused($p, fn() => self::$stock->UndoBooking($open), 'no longer exists in that state');
	}
}
