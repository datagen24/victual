<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ChoresService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #506 (#487 remediation, M6 - the two pieces not already covered by
 * ChoreExecutionStockUndoTest.php's chore-undo/stock-composition half, PR #597):
 *
 * 1. The weekly schedule's "undone" filtering. chores_current's 'weekly' branch used to pick
 *    the chore's most recent chores_log row with no `undone` filter, unlike every other
 *    period_type branch (and unlike the outer LEFT JOIN's own `l.undone = 0`, which feeds
 *    MAX(l.tracked_time) everywhere else) - an undone execution still drove the weekly
 *    schedule. This was already fixed on master by migrations/0289.pgsql.sql (issue #487
 *    WS-15, PR #542), which added "AND undone = 0" to that subquery. This class adds the
 *    regression coverage #506 asks for; it does not change behaviour (it already passes on
 *    master).
 *
 *    The scenario needs two executions, an older live one and a newer undone one - not a
 *    single tracked-then-undone execution. With only one, MAX(l.tracked_time) over the outer
 *    LEFT JOIN's own `l.undone = 0` is NULL once that execution is undone, so chores_current
 *    takes the "no prior execution" / start_date branch and the weekly branch's subquery -
 *    the thing this test is meant to guard - never runs at all. A round-2 validator confirmed
 *    a single-execution version of this test kept passing even against a chores_current with
 *    the "AND undone = 0" fix removed. With an older live execution present, MAX(l.tracked_time)
 *    is not NULL, the weekly branch's own subquery runs, and it alone determines whether the
 *    undone newer execution still drives the schedule.
 *
 * 2. ChoresService::TrackChore() validation. Two cases named by #506 were not checked at
 *    all: an inactive chore (chores.active = 0) could still be tracked - "active" was only
 *    ever consulted as a list filter (ChoresController's "chores" queries already restrict to
 *    "active = 1"), never by the tracking path itself - and a chore configured to consume a
 *    product on execution but left with a NULL product_amount reached
 *    StockService::ConsumeProduct()'s non-nullable `float $amount` parameter with null. That
 *    is a PHP TypeError, not a silent success: uncaught by BaseApiController::HandleApiCall()'s
 *    generic \Exception catch (which does not catch \TypeError), it 500ed and the transaction
 *    rolled back, so nothing was ever written or consumed either way - but a misconfigured
 *    chore getting an opaque 500 is still the wrong refusal. Both cases are now refused with
 *    a plain \Exception (400 over HTTP, via that same generic catch), checked before any write
 *    - including the chores_log insert - and before this method's own STOCK_CONSUME
 *    permission check (issue #604/#606): a caller who lacks STOCK_CONSUME and tracks an
 *    invalid chore now gets 400 rather than 403, and nothing is written either way.
 *
 * Follows ChoreExecutionStockUndoTest.php's own pattern: call ChoresService/StockService
 * directly, and assert on chores_log/stock/stock_log rows rather than only a thrown
 * exception's message.
 */
class ChoreScheduleUndoneAndValidationTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static ChoresService $chores;
	private static StockService $stock;
	private static int $location;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'chore-schedule-validation-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$chores = ChoresService::GetInstance();
		self::$stock = StockService::GetInstance();

		self::$location = self::insertRow('locations', ['name' => 'Chore Schedule Validation Location']);
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirroring ChoreExecutionStockUndoTest.php's own)
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

	private static function nextEstimatedExecutionTime(int $choreId): ?string
	{
		$statement = self::$db->prepare('SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = ?');
		$statement->execute([$choreId]);

		$value = $statement->fetchColumn();
		return $value === false ? null : $value;
	}

	// ------------------------------------------------------------------------------
	// 1. Weekly schedule: an undone execution must not advance next_estimated_execution_time.
	// ------------------------------------------------------------------------------

	public function testAnUndoneWeeklyExecutionDoesNotAdvanceTheNextEstimatedExecutionTime(): void
	{
		// period_config names every weekday so the 'weekly' branch always has a candidate day
		// to pick regardless of what day the test runs on.
		//
		// Two executions, deliberately - an older one that stays live, and a newer one that
		// gets undone - not a single tracked-then-undone execution: see the class docblock.
		// With only the older execution live, MAX(l.tracked_time) over the outer LEFT JOIN's
		// `l.undone = 0` is the older execution's own tracked_time, so chores_current takes
		// the 'weekly' branch and its own subquery runs; whether that subquery still counts
		// the newer, undone execution is exactly what this test needs to discriminate.
		$choreId = self::insertChore('Weekly Undone Schedule', [
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_WEEKLY,
			'period_interval' => 1,
			'period_config' => 'monday,tuesday,wednesday,thursday,friday,saturday,sunday',
			'start_date' => '2026-01-05 08:00:00',
		]);

		$olderExecutionId = self::$chores->TrackChore($choreId, '2026-09-11 09:00:00');
		$expectedFromOlderOnly = self::nextEstimatedExecutionTime($choreId);
		self::assertNotNull($expectedFromOlderOnly, 'The older, live execution alone already drives a schedule');

		$newerExecutionId = self::$chores->TrackChore($choreId, '2026-09-22 09:00:00');
		$fromNewerLive = self::nextEstimatedExecutionTime($choreId);
		self::assertNotSame($expectedFromOlderOnly, $fromNewerLive, 'The newer, live execution now drives the schedule instead');

		self::$chores->UndoChoreExecution($newerExecutionId);

		$afterUndoingNewer = self::nextEstimatedExecutionTime($choreId);
		self::assertSame(
			$expectedFromOlderOnly,
			$afterUndoingNewer,
			'Undoing the newer execution must restore the schedule to what the older, still-live execution alone produces - the weekly branch\'s own subquery must not still count the undone execution just because it is more recent'
		);
		self::assertNotSame($fromNewerLive, $afterUndoingNewer, 'Sanity: the undone execution\'s own (later) schedule must not still be the answer');
	}

	// ------------------------------------------------------------------------------
	// 2. TrackChore() refuses an inactive chore: 400 (via a plain \Exception), no write.
	// ------------------------------------------------------------------------------

	public function testTrackingAnInactiveChoreIsRefusedAndWritesNothing(): void
	{
		$choreId = self::insertChore('Inactive Chore', [
			'active' => 0,
		]);

		$before = self::ledger();

		try
		{
			self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
			self::fail('Tracking an inactive chore must be refused');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('inactive', $exception->getMessage());
		}

		self::assertSame($before, self::ledger(), 'The refusal leaves chores_log, stock and stock_log byte-for-byte unchanged');
	}

	// ------------------------------------------------------------------------------
	// 3. TrackChore() refuses a stock-consuming chore with no product_amount configured:
	//    400, no write, and - crucially - no stock consumed either (the pre-fix behaviour
	//    let PHP coerce the missing amount to 0.0 and silently "succeed").
	// ------------------------------------------------------------------------------

	public function testTrackingAChoreThatConsumesAProductWithNoAmountConfiguredIsRefusedAndConsumesNothing(): void
	{
		$product = self::insertProduct('Chore Null Amount Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Null Amount Chore', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => null,
		]);

		$before = self::ledger();

		try
		{
			self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
			self::fail('Tracking a stock-consuming chore with no product_amount must be refused');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('product_amount', $exception->getMessage());
		}

		self::assertSame(5.0, self::stockAmount($product), 'Nothing was consumed - not even the misconfigured amount coerced to 0');
		self::assertSame($before, self::ledger(), 'The refusal leaves chores_log, stock and stock_log byte-for-byte unchanged');
	}

	// ------------------------------------------------------------------------------
	// 4. A chore that consumes a product with product_amount configured, but the chore
	//    itself inactive, is still refused on the inactive check - not silently allowed
	//    because it also has a valid amount.
	// ------------------------------------------------------------------------------

	public function testAnInactiveChoreWithAValidAmountIsStillRefused(): void
	{
		$product = self::insertProduct('Chore Inactive With Amount Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Inactive Chore With Amount', [
			'active' => 0,
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$before = self::ledger();

		try
		{
			self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
			self::fail('An inactive chore must be refused regardless of its consumption configuration');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('inactive', $exception->getMessage());
		}

		self::assertSame(5.0, self::stockAmount($product));
		self::assertSame($before, self::ledger(), 'The refusal leaves chores_log, stock and stock_log byte-for-byte unchanged');
	}
}
