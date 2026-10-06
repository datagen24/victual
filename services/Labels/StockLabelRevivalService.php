<?php

namespace Victual\Services\Labels;

use Victual\Services\StockService;

/**
 * ADR-0037: an undo of the whole-row consumption that retired a stock-entry label revives that
 * label, and no other, on the row the undo rebuilds under its original id.
 *
 * The retirement half is SQL (migrations/0303.pgsql.sql): a trigger on `labels` writes one
 * `stock_label_retirements` event per retirement, and accepts a consumption as the cause only
 * when SetRetirementContext() named a booking that proves it. This class is the undo half. It
 * runs inside the undo's transaction, so a refused or rolled-back undo leaves no trace of it.
 *
 * Lock order (ADR-0037 section 7): the product advisory lock (StockService), then the import
 * lock (StockService takes it through LockImport() once per outermost undo, before any location
 * lock), then the label row, then the event row. Retirement takes the label row before it
 * writes the event, so both orders agree.
 */
class StockLabelRevivalService
{
	public const CONTEXT_BOOKING = 'victual.retiring_booking_id';
	public const CONTEXT_WINDOW = 'victual.label_revival_window_seconds';

	/** What Revive() closed an event as, besides a reason from DECLINE_REASONS. */
	public const REVIVED = 'revived';

	/** The reasons a pending consumption event can be declined for, in the order they are checked. */
	public const DECLINE_REASONS = ['expired', 'label_live', 'mismatch', 'id_changed', 'target_labelled'];

	public function __construct(private \PDO $db)
	{
	}

	/**
	 * Called by StockService::ConsumeProduct() after it saved a whole-row booking and before it
	 * deletes the row. One statement, transaction-local, so a later statement in the same
	 * transaction that deletes a different row finds a context the trigger refuses.
	 */
	public static function SetRetirementContext(\PDO $db, int $bookingId, int $windowSeconds): void
	{
		if (!self::Applies($db))
		{
			return;
		}
		$statement = $db->prepare('SELECT set_config(?, ?, true), set_config(?, ?, true)');
		$statement->execute([self::CONTEXT_BOOKING, (string)$bookingId, self::CONTEXT_WINDOW, (string)$windowSeconds]);
	}

	/**
	 * The pending consumption events of the current import epoch for the given bookings.
	 *
	 * One indexed read (stock_label_retirements_booking). An event of an earlier epoch never
	 * matches, so an import between the retirement and the undo leaves the label retired.
	 *
	 * @param int[] $bookingIds
	 * @return array<int, int> booking id => event id
	 */
	public function PendingFor(array $bookingIds): array
	{
		if (count($bookingIds) === 0 || !self::Applies($this->db))
		{
			return [];
		}
		$statement = $this->db->prepare('SELECT booking_id, id FROM stock_label_retirements
			WHERE import_epoch = label_current_import_epoch() AND outcome IS NULL AND booking_id = ANY(?::BIGINT[])');
		$statement->execute(['{' . implode(',', array_map('intval', $bookingIds)) . '}']);
		$pending = [];
		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$pending[(int)$row['booking_id']] = (int)$row['id'];
		}
		return $pending;
	}

	/**
	 * Closes one pending event: revives its label on $restoredRowId, or declines with a reason.
	 *
	 * The caller is the undo of $bookingId, after it inserted $restoredRowId and while it still
	 * holds the product and import locks. A decline never throws: stock correctness comes first
	 * and the undo succeeds either way (ADR-0037 section 6). An unexpected database error is not
	 * caught, so the whole undo rolls back.
	 *
	 * @return string|null self::REVIVED, a reason from DECLINE_REASONS, or null when the event
	 *                     was already closed, or belongs to an earlier import epoch, by the time
	 *                     its row lock was granted
	 */
	public function Revive(int $eventId, int $bookingId, int $restoredRowId): ?string
	{
		$uid = $this->Query('SELECT label_uid FROM stock_label_retirements WHERE id = ?', [$eventId])->fetchColumn();
		if ($uid === false)
		{
			return null;
		}

		$label = $this->Query('SELECT retired_at, target_id FROM labels WHERE uid = ? FOR UPDATE', [$uid])->fetch(\PDO::FETCH_ASSOC);
		$event = $this->Query('SELECT * FROM stock_label_retirements WHERE id = ? FOR UPDATE', [$eventId])->fetch(\PDO::FETCH_ASSOC);
		$epoch = (int)$this->Query('SELECT label_current_import_epoch()')->fetchColumn();
		// Closed by a concurrent undo, or orphaned by an import that committed while this undo
		// waited for the import lock (ADR-0037 example C3b): not attempted, and left unchanged.
		if ($event['outcome'] !== null || (int)$event['import_epoch'] !== $epoch)
		{
			return null;
		}

		$now = $this->Clock();
		$expired = (bool)$this->Query('SELECT ?::TIMESTAMPTZ >= ?::TIMESTAMPTZ', [$now, $event['revivable_until']])->fetchColumn();
		if ($expired)
		{
			return $this->Decline($eventId, 'expired', $now);
		}

		if ($label['retired_at'] === null)
		{
			return $this->Decline($eventId, 'label_live', $now);
		}

		$snapshotRowId = (int)(json_decode($event['snapshot'], true, 512, JSON_THROW_ON_ERROR)['id'] ?? 0);
		$booking = $this->Query('SELECT product_id, stock_row_id, amount FROM stock_log WHERE id = ?', [$bookingId])->fetch(\PDO::FETCH_ASSOC);
		$row = $this->Query('SELECT product_id, amount, import_epoch FROM stock WHERE id = ?', [$restoredRowId])->fetch(\PDO::FETCH_ASSOC);
		$eventAmount = (float)$event['amount'];
		if ($booking === false || $row === false
			|| (int)$event['booking_id'] !== $bookingId
			|| (int)$booking['product_id'] !== (int)$event['product_id']
			|| $booking['stock_row_id'] === null || (int)$booking['stock_row_id'] !== $snapshotRowId
			|| StockService::CompareAmounts(-(float)$booking['amount'], $eventAmount) !== 0
			|| (int)$row['product_id'] !== (int)$event['product_id']
			|| StockService::CompareAmounts((float)$row['amount'], $eventAmount) !== 0
			|| (int)$row['import_epoch'] !== $epoch)
		{
			return $this->Decline($eventId, 'mismatch', $now);
		}

		// ADR-0033 decision 6: only the original id. A reused numeric id is no proof by itself,
		// which is why the checks above come first; a new id is no proof at all.
		if ($restoredRowId !== $snapshotRowId)
		{
			return $this->Decline($eventId, 'id_changed', $now);
		}

		if ($this->Query("SELECT 1 FROM labels WHERE kind = 'stock_entry' AND target_id = ? AND retired_at IS NULL", [$restoredRowId])->fetchColumn() !== false)
		{
			return $this->Decline($eventId, 'target_labelled', $now);
		}

		$this->Query('UPDATE labels SET retired_at = NULL, target_id = ?, retirement_snapshot = NULL WHERE uid = ?', [$restoredRowId, $uid]);
		$this->Query("UPDATE stock_label_retirements SET outcome = 'revived', closed_at = ?::TIMESTAMPTZ, revived_target_id = ? WHERE id = ?", [$now, $restoredRowId, $eventId]);
		return self::REVIVED;
	}

	/**
	 * Counts closed outcomes for the undo notice (ADR-0037 section 12a).
	 *
	 * @param array<int, string|null> $outcomes What Revive() returned for each event of one undo
	 * @return array{restored: int, retired: int}
	 */
	public static function Summarize(array $outcomes): array
	{
		$restored = 0;
		$retired = 0;
		foreach ($outcomes as $outcome)
		{
			if ($outcome === self::REVIVED)
			{
				$restored++;
			}
			elseif ($outcome !== null)
			{
				$retired++;
			}
		}
		return ['restored' => $restored, 'retired' => $retired];
	}

	/**
	 * The database clock, read after the locks are held. Protected so a test can place the
	 * evaluation exactly on the deadline, which a running clock cannot.
	 */
	protected function Clock(): string
	{
		return (string)$this->db->query('SELECT clock_timestamp()')->fetchColumn();
	}

	/**
	 * Label revival is PostgreSQL code over a PostgreSQL-only table (migration 0303). The one
	 * SQLite connection this class can meet is the differential harness's comparison side
	 * (DatabaseDialect::SQLITE_TOOLING_ENV), whose migration line is frozen at 0265 and has no
	 * event table, so there the hook does nothing and the undo behaves as it did before.
	 */
	private static function Applies(\PDO $db): bool
	{
		return $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';
	}

	private function Decline(int $eventId, string $reason, string $now): string
	{
		$this->Query("UPDATE stock_label_retirements SET outcome = 'declined', reason = ?, closed_at = ?::TIMESTAMPTZ WHERE id = ?", [$reason, $now, $eventId]);
		return $reason;
	}

	private function Query(string $sql, array $parameters = []): \PDOStatement
	{
		$statement = $this->db->prepare($sql);
		$statement->execute($parameters);
		return $statement;
	}
}
