<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ChoresService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #506 (#487 remediation, M6; maintainer decision D1, 2026-09-28): undoing a chore
 * execution that consumed stock must undo that consumption too, atomically with the chore
 * undo - refusing the whole undo if the stock half cannot be reversed, and never refusing an
 * execution whose consumption cannot be linked (it undoes the chore alone, exactly as before
 * this decision).
 *
 * ChoresService::UndoChoreExecution() derives the link rather than storing it: chores_log
 * carries no transaction_id column (decision D1 rules out a migration), so
 * FindLinkedStockConsumptionTransactionId() matches a still-undone TRANSACTION_TYPE_CONSUME
 * stock_log row by (product_id, row_created_timestamp) - exact equality, not a time window,
 * because TrackChore() inserts the chores_log row and calls StockService::ConsumeProduct()
 * inside one database transaction, and every row_created_timestamp default in this schema
 * (`date_trunc('second', LOCALTIMESTAMP)`) is fixed for the whole transaction by PostgreSQL,
 * not just the statement that reads it.
 *
 * Follows ComposedOperationAtomicityTest.php's own pattern for this same service: call
 * ChoresService/StockService directly (no HTTP transport), assert on `stock`/`stock_log`/
 * `chores_log` rows rather than only a return value, and verify a refusal leaves every row
 * byte-for-byte unchanged.
 */
class ChoreExecutionStockUndoTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static ChoresService $chores;
	private static StockService $stock;
	private static int $location;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'chore-stock-undo-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$chores = ChoresService::GetInstance();
		self::$stock = StockService::GetInstance();

		self::$location = self::insertRow('locations', ['name' => 'Chore Stock Undo Location']);
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirroring ComposedOperationAtomicityTest.php's own)
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$location,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	private static function insertChore(string $name, array $columns = []): int
	{
		return self::insertRow('chores', array_merge([
			'name' => $name,
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
		], $columns));
	}

	private static function stockUp(int $productId, float $amount): void
	{
		self::$stock->AddProduct($productId, $amount, '2035-06-30', StockService::TRANSACTION_TYPE_PURCHASE, '2026-04-01', 1.0);
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (float)$statement->fetchColumn();
	}

	/** Every column of every `stock`, `stock_log` and `chores_log` row, in id order. */
	private static function ledger(): string
	{
		return json_encode([
			'stock' => self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'chores_log' => self::$db->query('SELECT * FROM chores_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	/** Only the CONSUME-type stock_log rows of a product - excludes the purchase booking stockUp() writes. */
	private static function consumeStockLogRowsForProduct(int $productId): array
	{
		$statement = self::$db->prepare("SELECT * FROM stock_log WHERE product_id = ? AND transaction_type = 'consume' ORDER BY id");
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function choreLogRow(int $executionId): array
	{
		$statement = self::$db->prepare('SELECT * FROM chores_log WHERE id = ?');
		$statement->execute([$executionId]);

		return $statement->fetch(PDO::FETCH_ASSOC);
	}

	// ------------------------------------------------------------------------------
	// 1. A chore execution that consumed stock: undoing it restores the stock and
	//    marks the consumption's own stock_log row(s) undone.
	// ------------------------------------------------------------------------------

	public function testUndoingChoreExecutionThatConsumedStockRestoresStockAndMarksBookingUndone(): void
	{
		$product = self::insertProduct('Chore Undo Consumed Stock');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Restores Stock', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');

		self::assertSame(3.0, self::stockAmount($product), 'The chore consumed 2 of the 5 units on execution');
		$bookedRows = self::consumeStockLogRowsForProduct($product);
		self::assertCount(1, $bookedRows, 'Exactly one consume booking was written');
		self::assertSame(StockService::TRANSACTION_TYPE_CONSUME, $bookedRows[0]['transaction_type']);
		self::assertSame(0, (int)$bookedRows[0]['undone'], 'The booking is live before the undo');

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(5.0, self::stockAmount($product), 'Undoing the execution restores the consumed stock');

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'The chore execution itself is marked undone');

		$rowsAfter = self::consumeStockLogRowsForProduct($product);
		self::assertCount(1, $rowsAfter, 'The undo reverses the existing booking rather than adding a new row for this whole-row case');
		self::assertSame(1, (int)$rowsAfter[0]['undone'], 'The original consume booking is now marked undone');
	}

	// ------------------------------------------------------------------------------
	// 2. A refused stock undo leaves both the chore and the stock unchanged.
	// ------------------------------------------------------------------------------

	public function testARefusedStockUndoLeavesBothTheChoreAndTheStockUnchanged(): void
	{
		$product = self::insertProduct('Chore Undo Refused Stock');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Refused', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::assertSame(3.0, self::stockAmount($product), 'The chore consumed 2 of the 5 units on execution (partial take, entry survives)');

		// A later booking against the same (surviving) stock entry - opening it, rather than
		// another consume, so it cannot itself land inside the (product_id, row_created_
		// timestamp) window FindLinkedStockConsumptionTransactionId() matches on and be
		// mistaken for a second execution's own consumption. StockService::UndoBooking()
		// refuses a booking with a "subsequent dependent booking": undoing the chore's own
		// consume first would leave this later booking referencing stock state that no
		// longer exists.
		self::$stock->OpenProduct($product, 3);
		self::assertSame(3.0, self::stockAmount($product), 'Opening the remaining entry does not itself change the on-hand amount');

		$before = self::ledger();

		try
		{
			self::$chores->UndoChoreExecution($executionId);
			self::fail('Undoing an execution whose booking has a later dependent booking must refuse');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('subsequent dependent bookings', $exception->getMessage());
		}

		self::assertSame($before, self::ledger(), 'The refusal leaves the chore log, stock and stock_log byte-for-byte unchanged');
	}

	// ------------------------------------------------------------------------------
	// 3. A chore without stock consumption still undoes.
	// ------------------------------------------------------------------------------

	public function testChoreWithoutStockConsumptionStillUndoes(): void
	{
		$choreId = self::insertChore('Chore Undo No Consumption', [
			'consume_product_on_execution' => 0,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');

		self::$chores->UndoChoreExecution($executionId);

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'A chore that never consumed anything still undoes cleanly');
	}

	// ------------------------------------------------------------------------------
	// 4. An unlinkable execution undoes the chore only, with stock unchanged - the
	//    "legacy executions" case decision D1 requires never to refuse.
	// ------------------------------------------------------------------------------

	public function testUnlinkableExecutionUndoesTheChoreOnlyAndLeavesStockUnchanged(): void
	{
		$product = self::insertProduct('Chore Undo Unlinkable Stock');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Unlinkable', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		// A consumption of the chore's own linked product, but booked outside TrackChore()'s
		// transaction (its own separate ConsumeProduct() call) - the same shape a pre-#506
		// execution's booking has, since chores_log never recorded a transaction_id to begin
		// with. FindLinkedStockConsumptionTransactionId() matches on row_created_timestamp,
		// which this deliberately does not share with the chores_log row inserted below.
		self::$stock->ConsumeProduct($product, 2, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::assertSame(3.0, self::stockAmount($product));

		// A chores_log row for the same chore, inserted directly (not through TrackChore()) so
		// its row_created_timestamp is independent of the consumption above and does not
		// coincide with it - reproducing an execution the deterministic link cannot resolve.
		$executionId = self::insertRow('chores_log', [
			'chore_id' => $choreId,
			'tracked_time' => '2026-09-28 09:00:00',
			'done_by_user_id' => 9000,
			'row_created_timestamp' => '2020-01-01 00:00:00',
		]);

		$stockLogBefore = self::consumeStockLogRowsForProduct($product);
		self::assertSame(0, (int)$stockLogBefore[0]['undone'], 'The unrelated consumption is live before the undo');

		self::$chores->UndoChoreExecution($executionId);

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'The chore execution is undone');

		self::assertSame(3.0, self::stockAmount($product), 'Stock is untouched: the consumption could not be linked to this execution');
		$stockLogAfter = self::consumeStockLogRowsForProduct($product);
		self::assertSame($stockLogBefore, $stockLogAfter, 'The unlinkable consumption itself is not modified at all');
	}
}
