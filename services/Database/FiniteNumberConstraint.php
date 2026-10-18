<?php

namespace Victual\Services\Database;

/**
 * Identifies a violation of one of the `<table>_<column>_finite` CHECK constraints (migration
 * 0313, issue #916), which refuse NaN and ±Infinity in a numeric column, and names the column
 * for the refusal a client is shown.
 */
final class FiniteNumberConstraint
{
	/** The column a finite-number constraint protects, or null for any other exception. */
	public static function ViolatedColumn(\PDOException $exception): ?string
	{
		if (($exception->errorInfo[0] ?? $exception->getCode()) !== '23514')
		{
			return null;
		}

		// PDO exposes no structured constraint name, so it is read from the driver's
		// diagnostic, quoted exactly as PostgreSQL quotes it
		$diagnostic = $exception->errorInfo[2] ?? $exception->getMessage();
		if (preg_match('/relation "([a-z0-9_]+)" violates check constraint "([a-z0-9_]+)_finite"/', $diagnostic, $match) !== 1
			|| !str_starts_with($match[2], $match[1] . '_'))
		{
			return null;
		}

		return substr($match[2], strlen($match[1]) + 1);
	}

	/** The refusal a client is shown for a non-finite value in $column. */
	public static function Message(string $column): string
	{
		return 'The ' . str_replace('_', ' ', $column) . ' must be a finite number';
	}
}
