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
 * 2. ChoresService::TrackChore() validation. Two cases named by #506 were not checked at
 *    all: an inactive chore (chores.active = 0) could still be tracked - "active" was only
 *    ever consulted as a list filter (ChoresController's "chores" queries already restrict to
 *    "active = 1"), never by the tracking path itself - and a chore configured to consume a
 *    product on execution but left with a NULL product_amount reached
 *    StockService::ConsumeProduct()'s non-nullable `float $amount` parameter with null, which
 *    PHP coerces to 0.0 rather than refusing: the request silently "succeeded", consuming
 *    nothing, instead of being rejected as the misconfiguration it is. Both are now refused
 *    with an \Exception (400 over HTTP, via BaseApiController::HandleApiCall()'s generic
 *    catch), before any write - including the chores_log insert - so a refusal leaves
 *    chores_log and stock byte-for-byte unchanged.
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
		$choreId = self::insertChore('Weekly Undone Schedule', [
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_WEEKLY,
			'period_interval' => 1,
			'period_config' => 'monday,tuesday,wednesday,thursday,friday,saturday,sunday',
			'start_date' => '2026-01-05 08:00:00',
		]);

		$beforeTracking = self::nextEstimatedExecutionTime($choreId);
		self::assertNotNull($beforeTracking, 'A weekly chore with a start_date has a next_estimated_execution_time before any tracking');

		$executionId = self::$chores->TrackChore($choreId, '2026-09-21 09:00:00');

		$afterTracking = self::nextEstimatedExecutionTime($choreId);
		self::assertNotSame($beforeTracking, $afterTracking, 'Tracking a live execution does advance the schedule');

		self::$chores->UndoChoreExecution($executionId);

		$afterUndo = self::nextEstimatedExecutionTime($choreId);
		self::assertSame($beforeTracking, $afterUndo, 'Undoing the only execution must restore the schedule exactly as if it never happened - an undone row must not still drive chores_current\'s weekly branch');
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
