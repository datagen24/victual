<?php

namespace Victual\Tests\Support;

use PDO;
use PHPUnit\Framework\Assert;

/**
 * Reads ADR-0036 lineage state (migration 0304) for tests: the lots each stock row holds, the
 * allocations of a booking, and the invariant check.
 */
final class StockLineage
{
	/**
	 * The contributions of a product's rows, as [row id => [lot booking id, or 'pool' => amount]].
	 */
	public static function Lots(PDO $db, int $productId): array
	{
		$statement = $db->prepare('SELECT rl.stock_row_id, rl.lot_id, rl.amount FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id
			WHERE s.product_id = ? ORDER BY rl.stock_row_id, rl.lot_id NULLS FIRST');
		$statement->execute([$productId]);

		$lots = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$lots[(int)$row['stock_row_id']][$row['lot_id'] === null ? 'pool' : (int)$row['lot_id']] = (float)$row['amount'];
		}

		return $lots;
	}

	/** A booking's allocations, as [lot booking id, or 'pool' => signed amount]. */
	public static function Allocations(PDO $db, int $bookingId): array
	{
		$statement = $db->prepare('SELECT lot_id, amount FROM stock_booking_lots WHERE booking_id = ? ORDER BY lot_id NULLS FIRST');
		$statement->execute([$bookingId]);

		$allocations = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$allocations[$row['lot_id'] === null ? 'pool' : (int)$row['lot_id']] = (float)$row['amount'];
		}

		return $allocations;
	}

	/** Asserts invariants I1 to I3 (stock_lineage_violations()) hold for a product. */
	public static function AssertHolds(PDO $db, int $productId, string $message = ''): void
	{
		$statement = $db->prepare('SELECT * FROM stock_lineage_violations(?)');
		$statement->execute([$productId]);
		Assert::assertSame([], $statement->fetchAll(PDO::FETCH_ASSOC), trim('ADR-0036 invariants I1 to I3 hold ' . $message));
	}
}
