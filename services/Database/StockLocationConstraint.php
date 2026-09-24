<?php

namespace Victual\Services\Database;

/** Identifies the one constraint whose failure has a stock-location explanation. */
final class StockLocationConstraint
{
	public const DELETE_MESSAGE = 'Location has stock; move or consume it before deleting the location';

	public static function IsViolation(\PDOException $exception): bool
	{
		// PDO exposes SQLSTATE but no structured constraint-name field. Match the exact
		// quoted identifier in the driver's diagnostic, never a generic 23503 alone.
		return ($exception->errorInfo[0] ?? $exception->getCode()) === '23503'
			&& str_contains($exception->errorInfo[2] ?? $exception->getMessage(), '"stock_location_id_fkey"');
	}
}
