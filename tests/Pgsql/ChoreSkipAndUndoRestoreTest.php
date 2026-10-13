<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ChoresService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issues #856 and #865 (2026-10-12 audit; maintainer decisions of the same day, a deliberate
 * divergence from upstream grocy 4.6.0):
 *
 * - #856: a skipped chore execution consumes nothing. Skipping records that a scheduled
 *   execution was passed over; it is not a performance of the chore, so the linked product is
 *   not consumed, no STOCK_CONSUME is needed, and an empty stock does not refuse it.
 * - #865: undoing an execution restores the reschedule date and the reschedule assignee that
 *   tracking cleared, from chores_log.prior_rescheduled_date and
 *   chores_log.prior_rescheduled_next_execution_assigned_to_user_id (migration 0311), as a
 *   pair or not at all: only when the undone row has the highest id among the chore's
 *   non-undone executions (insertion order; tracked_time can be backdated), both reschedule
 *   fields on the chore are empty, and the saved assignee still exists.
 *
 * Calls ChoresService directly, as ChoreExecutionStockUndoTest does, and asserts on the
 * `chores`, `chores_log`, `stock` and `stock_log` rows.
 */
class ChoreSkipAndUndoRestoreTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static ChoresService $chores;
	private static StockService $stock;
	private static int $location;

	private const ASSIGNEE_A = 9811;
	private const ASSIGNEE_B = 9812;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'chore-skip-undo-caller', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::ASSIGNEE_A . ", 'chore-skip-undo-a', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::ASSIGNEE_B . ", 'chore-skip-undo-b', 'fixture')");

		self::$chores = ChoresService::GetInstance();
		self::$stock = StockService::GetInstance();

		self::$location = self::insertRow('locations', ['name' => 'Chore Skip Undo Location']);
	}

	protected function setUp(): void
	{
		// Each case grants the caller what it needs; the default is no permission at all.
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000');
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirroring ChoreExecutionStockUndoTest.php's own)
	// ------------------------------------------------------------------------------

	private static function grant(string ...$permissions): void
	{
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ? ON CONFLICT DO NOTHING');
		foreach ($permissions as $permission)
		{
			$grant->execute([$permission]);
		}
	}

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

	/** A daily chore (a chore with no schedule cannot be skipped); $columns override or extend. */
	private static function insertChore(string $name, array $columns = []): int
	{
		return self::insertRow('chores', array_merge([
			'name' => $name,
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_DAILY,
			'period_interval' => 1,
			'start_date' => '2026-01-01 08:00:00',
			'assignment_type' => ChoresService::CHORE_ASSIGNMENT_TYPE_NO_ASSIGNMENT,
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

	private static function stockLogRows(int $productId): array
	{
		$statement = self::$db->prepare('SELECT * FROM stock_log WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function choreRow(int $choreId): array
	{
		$statement = self::$db->prepare('SELECT * FROM chores WHERE id = ?');
		$statement->execute([$choreId]);

		return $statement->fetch(PDO::FETCH_ASSOC);
	}

	private static function logRow(int $executionId): array
	{
		$statement = self::$db->prepare('SELECT * FROM chores_log WHERE id = ?');
		$statement->execute([$executionId]);

		return $statement->fetch(PDO::FETCH_ASSOC);
	}

	private static function reschedule(int $choreId, string $when, int $assignee): void
	{
		$statement = self::$db->prepare('UPDATE chores SET rescheduled_date = ?, rescheduled_next_execution_assigned_to_user_id = ? WHERE id = ?');
		$statement->execute([$when, $assignee, $choreId]);
	}

	// ------------------------------------------------------------------------------
	// #856: a skipped execution consumes nothing
	// ------------------------------------------------------------------------------

	public function testSkippingAConsumingChoreLeavesStockAndLedgerUnchanged(): void
	{
		self::grant('STOCK_CONSUME');
		$product = self::insertProduct('Skip Consumes Nothing');
		self::stockUp($product, 5);
		$ledgerBefore = self::stockLogRows($product);

		$choreId = self::insertChore('Skip Consumes Nothing Chore', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000, true);

		self::assertSame(5.0, self::stockAmount($product), 'A skipped execution consumed nothing');
		self::assertSame($ledgerBefore, self::stockLogRows($product), 'No stock_log row was written for the skip');

		$log = self::logRow($executionId);
		self::assertSame(1, (int)$log['skipped']);
		self::assertNull($log['stock_transaction_id'], 'A skipped execution has no stock transaction');
	}

	public function testSkippingSucceedsWithoutStockConsumePermissionAndWithZeroStock(): void
	{
		// The caller holds no permission at all and the product has no stock: neither the
		// STOCK_CONSUME check nor the "not enough stock" refusal applies to a skip.
		$product = self::insertProduct('Skip Needs No Stock Or Permission');

		$choreId = self::insertChore('Skip Needs No Stock Or Permission Chore', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000, true);

		$log = self::logRow($executionId);
		self::assertSame(1, (int)$log['skipped']);
		self::assertNull($log['stock_transaction_id']);
		self::assertSame(0.0, self::stockAmount($product));
		self::assertSame([], self::stockLogRows($product));
	}

	public function testUndoingASkippedExecutionNeedsNoStockReversal(): void
	{
		// The caller has no STOCK_EDIT: there is no live booking to reverse, so the undo
		// must not demand the permission that reversing one needs.
		$product = self::insertProduct('Undo Skip Product');
		self::stockUp($product, 5);
		$choreId = self::insertChore('Undo Skip Chore', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);
		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000, true);

		$labelRevival = self::$chores->UndoChoreExecution($executionId);

		self::assertNull($labelRevival, 'No stock booking was reversed');
		self::assertSame(1, (int)self::logRow($executionId)['undone']);
		self::assertSame(5.0, self::stockAmount($product));
	}

	public function testExecutingAConsumingChoreStillConsumes(): void
	{
		self::grant('STOCK_CONSUME');
		$product = self::insertProduct('Execution Still Consumes');
		self::stockUp($product, 5);
		$choreId = self::insertChore('Execution Still Consumes Chore', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000, false);

		self::assertSame(3.0, self::stockAmount($product));
		$log = self::logRow($executionId);
		self::assertSame(0, (int)$log['skipped']);
		self::assertNotNull($log['stock_transaction_id']);
	}

	// ------------------------------------------------------------------------------
	// #865: undo restores what tracking cleared
	// ------------------------------------------------------------------------------

	public function testUndoRestoresTheRescheduleDateAndAssigneeAndTheNextAssignmentUsesIt(): void
	{
		$choreId = self::insertChore('Undo Restores Reschedule');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$rescheduled = self::choreRow($choreId)['rescheduled_date'];
		self::assertNotNull($rescheduled);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);

		$afterTrack = self::choreRow($choreId);
		self::assertNull($afterTrack['rescheduled_date'], 'Tracking clears the reschedule');
		self::assertNull($afterTrack['rescheduled_next_execution_assigned_to_user_id']);
		self::assertNull($afterTrack['next_execution_assigned_to_user_id']);
		$log = self::logRow($executionId);
		self::assertSame($rescheduled, $log['prior_rescheduled_date'], 'The execution recorded the date it cleared');
		self::assertSame(self::ASSIGNEE_A, (int)$log['prior_rescheduled_next_execution_assigned_to_user_id']);

		self::$chores->UndoChoreExecution($executionId);

		$afterUndo = self::choreRow($choreId);
		self::assertSame($rescheduled, $afterUndo['rescheduled_date'], 'Undo restored the reschedule date');
		self::assertSame(self::ASSIGNEE_A, (int)$afterUndo['rescheduled_next_execution_assigned_to_user_id'], 'Undo restored the reschedule assignee');
		self::assertSame(self::ASSIGNEE_A, (int)$afterUndo['next_execution_assigned_to_user_id'], 'The next assignment used the restored assignee');
		self::assertSame(1, (int)self::logRow($executionId)['undone']);
	}

	public function testARescheduleMadeAfterTrackingSurvivesTheUndo(): void
	{
		$choreId = self::insertChore('Newer Reschedule Wins');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);

		self::reschedule($choreId, '2026-12-24 18:00:00', self::ASSIGNEE_B);
		$newer = self::choreRow($choreId);

		self::$chores->UndoChoreExecution($executionId);

		$afterUndo = self::choreRow($choreId);
		self::assertSame($newer['rescheduled_date'], $afterUndo['rescheduled_date'], 'The newer reschedule date was kept');
		self::assertSame(self::ASSIGNEE_B, (int)$afterUndo['rescheduled_next_execution_assigned_to_user_id'], 'The newer assignee was kept');
		self::assertSame(self::ASSIGNEE_B, (int)$afterUndo['next_execution_assigned_to_user_id']);
	}

	public function testNothingIsRestoredWhenExactlyOneFieldIsSet(): void
	{
		// Only the assignee is set again after tracking: the saved date must not be
		// half-restored next to it.
		$choreId = self::insertChore('Pair Rule Assignee Set');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);
		self::$db->exec('UPDATE chores SET rescheduled_next_execution_assigned_to_user_id = ' . self::ASSIGNEE_B . ' WHERE id = ' . $choreId);

		self::$chores->UndoChoreExecution($executionId);

		$afterUndo = self::choreRow($choreId);
		self::assertNull($afterUndo['rescheduled_date'], 'The date was not restored next to a newer assignee');
		self::assertSame(self::ASSIGNEE_B, (int)$afterUndo['rescheduled_next_execution_assigned_to_user_id'], 'The assignee set after tracking was kept');
		self::assertSame(1, (int)self::logRow($executionId)['undone']);

		// Only the date is set again after tracking.
		$choreId = self::insertChore('Pair Rule Date Set');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);
		self::$db->exec("UPDATE chores SET rescheduled_date = '2026-12-24 18:00:00' WHERE id = " . $choreId);
		$newerDate = self::choreRow($choreId)['rescheduled_date'];

		self::$chores->UndoChoreExecution($executionId);

		$afterUndo = self::choreRow($choreId);
		self::assertSame($newerDate, $afterUndo['rescheduled_date'], 'The date set after tracking was kept');
		self::assertNull($afterUndo['rescheduled_next_execution_assigned_to_user_id'], 'The assignee was not restored next to a newer date');
	}

	public function testOrderIsInsertionOrderEvenWhenTheSecondExecutionIsBackdated(): void
	{
		// R1, track E1, R2, track E2 backdated before E1. E2 is the later execution.
		$choreId = self::insertChore('Backdated Undo Newest');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$first = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);
		self::reschedule($choreId, '2026-11-02 08:00:00', self::ASSIGNEE_B);
		$secondDate = self::choreRow($choreId)['rescheduled_date'];
		$second = self::$chores->TrackChore($choreId, '2026-09-20 09:00:00', 9000);

		self::$chores->UndoChoreExecution($second);

		$afterUndo = self::choreRow($choreId);
		self::assertSame($secondDate, $afterUndo['rescheduled_date'], 'Undoing the backdated execution restored the reschedule it cleared');
		self::assertSame(self::ASSIGNEE_B, (int)$afterUndo['rescheduled_next_execution_assigned_to_user_id']);

		// Same history on a second chore, undoing E1 first: it is not the latest, so it
		// must not resurrect R1; E2's undo then returns R2.
		$choreId = self::insertChore('Backdated Undo Older First');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$first = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);
		self::reschedule($choreId, '2026-11-02 08:00:00', self::ASSIGNEE_B);
		$secondDate = self::choreRow($choreId)['rescheduled_date'];
		$second = self::$chores->TrackChore($choreId, '2026-09-20 09:00:00', 9000);

		self::$chores->UndoChoreExecution($first);

		$afterFirstUndo = self::choreRow($choreId);
		self::assertNull($afterFirstUndo['rescheduled_date'], 'Undoing E1 while E2 is live resurrected nothing');
		self::assertNull($afterFirstUndo['rescheduled_next_execution_assigned_to_user_id']);

		self::$chores->UndoChoreExecution($second);

		$afterSecondUndo = self::choreRow($choreId);
		self::assertSame($secondDate, $afterSecondUndo['rescheduled_date']);
		self::assertSame(self::ASSIGNEE_B, (int)$afterSecondUndo['rescheduled_next_execution_assigned_to_user_id']);
	}

	public function testASavedAssigneeWhoWasDeletedRestoresNothing(): void
	{
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9813, 'chore-skip-undo-gone', 'fixture')");
		$choreId = self::insertChore('Deleted Assignee');
		self::reschedule($choreId, '2026-11-01 08:00:00', 9813);
		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);
		self::$db->exec('DELETE FROM users WHERE id = 9813');

		self::$chores->UndoChoreExecution($executionId);

		$afterUndo = self::choreRow($choreId);
		self::assertNull($afterUndo['rescheduled_date'], 'The pair is restored whole or not at all');
		self::assertNull($afterUndo['rescheduled_next_execution_assigned_to_user_id']);
		self::assertSame(1, (int)self::logRow($executionId)['undone']);
	}

	public function testUndoRefusedForLackOfStockEditChangesNothing(): void
	{
		self::grant('STOCK_CONSUME');
		$product = self::insertProduct('Undo Refused Product');
		self::stockUp($product, 5);
		$choreId = self::insertChore('Undo Refused Chore', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000');

		$choreBefore = self::choreRow($choreId);
		$logBefore = self::logRow($executionId);
		$ledgerBefore = self::stockLogRows($product);

		$thrown = null;
		try
		{
			self::$chores->UndoChoreExecution($executionId);
		}
		catch (\Exception $exception)
		{
			$thrown = $exception;
		}

		self::assertNotNull($thrown, 'The undo is refused without STOCK_EDIT');
		self::assertStringContainsString('STOCK_EDIT', $thrown->getMessage());
		self::assertSame($choreBefore, self::choreRow($choreId), 'The chore is unchanged');
		self::assertSame($logBefore, self::logRow($executionId), 'The log row is unchanged');
		self::assertSame($ledgerBefore, self::stockLogRows($product), 'The ledger is unchanged');
		self::assertSame(3.0, self::stockAmount($product));
	}

	public function testUndoingAnOlderExecutionRestoresNothing(): void
	{
		$choreId = self::insertChore('Older Execution No Restore');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$older = self::$chores->TrackChore($choreId, '2026-09-27 09:00:00', 9000);
		self::assertNotNull(self::logRow($older)['prior_rescheduled_date']);

		self::reschedule($choreId, '2026-11-02 08:00:00', self::ASSIGNEE_B);
		$newerPrior = self::choreRow($choreId)['rescheduled_date'];
		$newer = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000);

		self::$chores->UndoChoreExecution($older);

		$afterOlderUndo = self::choreRow($choreId);
		self::assertNull($afterOlderUndo['rescheduled_date'], 'The older execution is not the most recent one, so nothing came back');
		self::assertNull($afterOlderUndo['rescheduled_next_execution_assigned_to_user_id']);

		// The newer execution is now the chore's most recent live one, and its own saved
		// values return when it is undone.
		self::$chores->UndoChoreExecution($newer);

		$afterNewerUndo = self::choreRow($choreId);
		self::assertSame($newerPrior, $afterNewerUndo['rescheduled_date']);
		self::assertSame(self::ASSIGNEE_B, (int)$afterNewerUndo['rescheduled_next_execution_assigned_to_user_id']);
	}

	public function testALegacyRowWithoutSavedValuesRestoresNothing(): void
	{
		$choreId = self::insertChore('Legacy Row No Restore');
		$legacy = self::insertRow('chores_log', [
			'chore_id' => $choreId,
			'tracked_time' => '2026-09-28 09:00:00',
			'done_by_user_id' => 9000,
		]);
		$row = self::logRow($legacy);
		self::assertNull($row['prior_rescheduled_date']);
		self::assertNull($row['prior_rescheduled_next_execution_assigned_to_user_id']);

		self::$chores->UndoChoreExecution($legacy);

		$afterUndo = self::choreRow($choreId);
		self::assertNull($afterUndo['rescheduled_date']);
		self::assertNull($afterUndo['rescheduled_next_execution_assigned_to_user_id']);
		self::assertSame(1, (int)self::logRow($legacy)['undone']);
	}

	public function testUndoingASkippedExecutionRestoresTheRescheduleToo(): void
	{
		$choreId = self::insertChore('Skip Undo Restores Reschedule');
		self::reschedule($choreId, '2026-11-01 08:00:00', self::ASSIGNEE_A);
		$rescheduled = self::choreRow($choreId)['rescheduled_date'];

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00', 9000, true);
		self::assertNull(self::choreRow($choreId)['rescheduled_date']);

		self::$chores->UndoChoreExecution($executionId);

		$afterUndo = self::choreRow($choreId);
		self::assertSame($rescheduled, $afterUndo['rescheduled_date']);
		self::assertSame(self::ASSIGNEE_A, (int)$afterUndo['rescheduled_next_execution_assigned_to_user_id']);
	}
}
