<?php

namespace Victual\Services;

/**
 * Booking-level stock lineage (ADR-0036, migration 0304).
 *
 * A lot is the units one addition booking introduced; its identity is that booking's
 * stock_log.id. stock_row_lots holds the contributions (how much of each lot a stock row holds
 * now) and stock_booking_lots the allocations (how much of each lot a booking added, removed or
 * moved). A lot of NULL is a row's unattributed pool: quantity whose lot is unknown.
 *
 * Every method here assumes the caller holds the product's stock advisory lock
 * (DatabaseService::LockProductStock()) and an open transaction, the way every StockService
 * writer already does. None of them opens, commits or rolls back a transaction.
 *
 * Amounts compare under ADR-0032's tolerance (StockService::CompareAmounts()); a contribution
 * left within tolerance of zero is deleted.
 *
 * The lineage tables are PostgreSQL-only (migration 0304). The one SQLite connection this class
 * can meet is the differential harness's comparison side (DatabaseDialect::SQLITE_TOOLING_ENV),
 * whose migration line is frozen at 0265; there every method does nothing and every writer
 * behaves as it did before, the way StockLabelRevivalService::Applies() handles migration 0303.
 */
class StockLineageService extends BaseService
{
	public const BASIS_RECORDED = 'recorded';
	public const BASIS_DERIVED = 'derived';
	public const BASIS_UNKNOWN = 'unknown';

	private function Pdo(): \PDO
	{
		return DatabaseService::GetInstance()->GetDbConnectionRaw();
	}

	/** False on the differential harness's SQLite side, which has no lineage tables. */
	public function Applies(): bool
	{
		return $this->Pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';
	}

	private function Query(string $sql, array $parameters = []): \PDOStatement
	{
		$statement = $this->Pdo()->prepare($sql);
		$statement->execute($parameters);
		return $statement;
	}

	/**
	 * The contributions of one row, pool first, then ascending lot id: the FIFO order of
	 * ADR-0036 section 4.
	 *
	 * @return array<int, array{0: int|null, 1: float, 2: string}> [lot, amount, basis] triples
	 */
	public function RowLots(int $stockRowId): array
	{
		if (!$this->Applies())
		{
			return [];
		}

		return array_map(
			static fn(array $row) => [$row['lot_id'] === null ? null : (int)$row['lot_id'], (float)$row['amount'], $row['basis']],
			$this->Query('SELECT lot_id, amount, basis FROM stock_row_lots WHERE stock_row_id = ? ORDER BY lot_id NULLS FIRST', [$stockRowId])
				->fetchAll(\PDO::FETCH_ASSOC)
		);
	}

	/** The sum of a row's contributions. */
	public function RowLotTotal(int $stockRowId): float
	{
		if (!$this->Applies())
		{
			return 0.0;
		}

		return (float)$this->Query('SELECT COALESCE(sum(amount), 0) FROM stock_row_lots WHERE stock_row_id = ?', [$stockRowId])->fetchColumn();
	}

	/**
	 * Adds an amount of one lot to a row, summing with what the row already holds of that lot.
	 * The pool always carries the `unknown` basis; a lot keeps the basis it already has on the
	 * row when it is already there.
	 */
	public function AddLot(int $stockRowId, ?int $lotId, float $amount, string $basis = self::BASIS_RECORDED): void
	{
		if (!$this->Applies())
		{
			return;
		}

		if (StockService::CompareAmounts($amount, 0) <= 0)
		{
			return;
		}

		$this->Query('INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis) VALUES (?, ?, ?, ?)
			ON CONFLICT (stock_row_id, lot_id) DO UPDATE SET amount = stock_row_lots.amount + EXCLUDED.amount',
			[$stockRowId, $lotId, $amount, $lotId === null ? self::BASIS_UNKNOWN : $basis]);
	}

	/** Replaces a row's contributions with $lots ([lot, amount, basis] triples). */
	public function SetLots(int $stockRowId, array $lots): void
	{
		if (!$this->Applies())
		{
			return;
		}

		$this->Query('DELETE FROM stock_row_lots WHERE stock_row_id = ?', [$stockRowId]);
		foreach ($lots as [$lot, $amount, $basis])
		{
			$this->AddLot($stockRowId, $lot, $amount, $basis);
		}
	}

	/** Removes an amount of one lot from a row; the contribution goes when nothing is left. */
	public function RemoveLot(int $stockRowId, ?int $lotId, float $amount): void
	{
		if (!$this->Applies())
		{
			return;
		}

		$current = (float)$this->Query('SELECT amount FROM stock_row_lots WHERE stock_row_id = ? AND lot_id IS NOT DISTINCT FROM ?',
			[$stockRowId, $lotId])->fetchColumn();

		if (StockService::CompareAmounts($current, $amount) <= 0)
		{
			$this->Query('DELETE FROM stock_row_lots WHERE stock_row_id = ? AND lot_id IS NOT DISTINCT FROM ?', [$stockRowId, $lotId]);
		}
		else
		{
			$this->Query('UPDATE stock_row_lots SET amount = amount - ? WHERE stock_row_id = ? AND lot_id IS NOT DISTINCT FROM ?',
				[$amount, $stockRowId, $lotId]);
		}
	}

	/**
	 * Takes $amount from a row's contributions in FIFO order (ADR-0036 section 4: the pool
	 * first, then ascending lot id) and returns what it took as [lot, amount, basis] triples.
	 * The row's contributions are reduced; stock.amount is the caller's to update.
	 *
	 * @throws \LogicException When the row holds less than $amount, which EnsureTracked() rules out
	 */
	public function TakeFifo(int $stockRowId, float $amount): array
	{
		if (!$this->Applies())
		{
			return [];
		}

		$draws = [];
		$remaining = $amount;

		foreach ($this->RowLots($stockRowId) as [$lot, $held, $basis])
		{
			if (StockService::CompareAmounts($remaining, 0) == 0)
			{
				break;
			}

			$take = StockService::CompareAmounts($held, $remaining) <= 0 ? $held : $remaining;
			$draws[] = [$lot, $take, $basis];
			$this->RemoveLot($stockRowId, $lot, $take);
			$remaining = StockService::CompareAmounts($remaining, $take) == 0 ? 0.0 : $remaining - $take;
		}

		if (StockService::CompareAmounts($remaining, 0) != 0)
		{
			throw new \LogicException("Stock row $stockRowId holds less than $amount in its lineage record");
		}

		return $draws;
	}

	/** Records the allocations of one booking: each draw's amount times $sign. */
	public function Allocate(int $bookingId, array $draws, int $sign = 1): void
	{
		if (!$this->Applies())
		{
			return;
		}

		$byLot = [];
		foreach ($draws as [$lot, $amount])
		{
			$key = $lot === null ? 'pool' : (string)$lot;
			$byLot[$key] = [$lot, ($byLot[$key][1] ?? 0.0) + $amount];
		}

		foreach ($byLot as [$lot, $amount])
		{
			if (StockService::CompareAmounts($amount, 0) == 0)
			{
				continue;
			}

			$this->Query('INSERT INTO stock_booking_lots (booking_id, lot_id, amount, basis) VALUES (?, ?, ?, ?)',
				[$bookingId, $lot, $sign * $amount, self::BASIS_RECORDED]);
		}
	}

	/** An addition booking's lot: its self-allocation and the new row's one contribution. */
	public function RecordAddition(int $bookingId, int $stockRowId, float $amount): void
	{
		$this->Allocate($bookingId, [[$bookingId, $amount]]);
		$this->AddLot($stockRowId, $bookingId, $amount);
	}

	/**
	 * Makes sure a row's contributions add up to its amount before a writer uses them
	 * (ADR-0036 section 5). A row written by an older image, or by a legacy merge, is first
	 * offered to the backfill for its product, which classifies only families with no lineage
	 * data at all. A row that still does not add up becomes one pool contribution, and every
	 * lot that removes from it has its allocations set to `unknown`, so invariant I3 stays true
	 * and the lot's addition can no longer be undone. Nothing is guessed.
	 *
	 * Must run before the writer saves its own booking: the backfill reads the live ledger,
	 * and a booking not yet reflected in the row would make an exact family look inexact.
	 */
	public function EnsureTracked(int $stockRowId): void
	{
		if (!$this->Applies())
		{
			return;
		}

		$row = $this->Query('SELECT product_id, amount FROM stock WHERE id = ?', [$stockRowId])->fetch(\PDO::FETCH_ASSOC);
		if ($row === false)
		{
			return;
		}

		$amount = (float)$row['amount'];
		if (StockService::CompareAmounts($this->RowLotTotal($stockRowId), $amount) == 0)
		{
			return;
		}

		$this->Query('SELECT count(*) FROM stock_lineage_backfill(?)', [(int)$row['product_id']]);
		if (StockService::CompareAmounts($this->RowLotTotal($stockRowId), $amount) == 0)
		{
			return;
		}

		$removedLots = $this->Query('SELECT lot_id FROM stock_row_lots WHERE stock_row_id = ? AND lot_id IS NOT NULL', [$stockRowId])
			->fetchAll(\PDO::FETCH_COLUMN);
		$this->SetLots($stockRowId, [[null, $amount, self::BASIS_UNKNOWN]]);

		if (count($removedLots) > 0)
		{
			$this->Query('UPDATE stock_booking_lots SET basis = ? WHERE lot_id = ANY(?::INTEGER[])',
				[self::BASIS_UNKNOWN, '{' . implode(',', array_map('intval', $removedLots)) . '}']);
		}
	}

	/**
	 * Invariants I1 to I3 for one product (or all), as migration 0304's stock_lineage_violations()
	 * reports them. Empty when they hold.
	 */
	public function Violations(?int $productId = null): array
	{
		if (!$this->Applies())
		{
			return [];
		}

		return $this->Query('SELECT invariant, subject_id, expected, actual FROM stock_lineage_violations(?)', [$productId])
			->fetchAll(\PDO::FETCH_ASSOC);
	}
}
