<?php

namespace Victual\Services\Database;

use Victual\Services\Time\Instant;

/**
 * Comparing the same data as it comes back from two different engines.
 *
 * PDO does not hand back the same PHP types from SQLite and PostgreSQL for equivalent
 * columns: NUMERIC arrives as a string from one and a float from the other, BOOLEAN as
 * an int from one and a bool from the other, and floating point arithmetic differs in
 * the last bit. Comparing raw rows therefore reports differences that do not exist,
 * which is worse than reporting none — a suite that cries wolf gets ignored.
 *
 * This lives in services/ rather than in the .devtools scripts because more than one
 * caller needs it: the differential test suite compares view output with it, and
 * DatabaseImporter's post-import verification compares the rows it just copied. Two
 * copies of a normalisation rule is two chances to disagree about what "equal" means.
 */
class ValueComparison
{
	/**
	 * How many decimal places are kept when comparing numbers.
	 *
	 * Six is enough to catch a real logic difference and loose enough to absorb the
	 * last-bit disagreement in a computed average price, which is the one known,
	 * accepted difference between the engines.
	 */
	const COMPARISON_PRECISION = 6;

	/**
	 * A single value reduced to the form both engines agree on: null stays null,
	 * booleans become 0/1, anything numeric becomes a rounded float, everything else
	 * becomes a string.
	 */
	public static function Normalise($value)
	{
		if ($value === null)
		{
			return null;
		}

		if (is_bool($value))
		{
			return $value ? 1 : 0;
		}

		if (is_numeric($value))
		{
			return round((float)$value, self::COMPARISON_PRECISION);
		}

		return (string)$value;
	}

	/**
	 * A whole row reduced to one comparable string.
	 *
	 * @param array $row Column name => value, as PDO::FETCH_ASSOC returns it
	 */
	public static function NormaliseRow(array $row): string
	{
		return json_encode(array_map([self::class, 'Normalise'], $row));
	}

	/**
	 * Which of JSON's types a value will be encoded as.
	 *
	 * Deliberately coarser than gettype(): JSON has one number type, so int versus
	 * float is not a difference a client can observe (json_encode(6.0) emits 6) and is
	 * not reported as one.
	 */
	public static function JsonTypeOf($value): string
	{
		if ($value === null)
		{
			return 'null';
		}

		if (is_bool($value))
		{
			return 'boolean';
		}

		if (is_int($value) || is_float($value))
		{
			return 'number';
		}

		return 'string';
	}

	/**
	 * Whether two values would reach a client identically on the wire.
	 *
	 * Callers use this *after* Normalise() has already found the values equal, so it is
	 * only ever asked about differences a client could still see.
	 *
	 * Two things it must catch, and they need different rules:
	 *
	 * - **A different JSON type.** A view returning the string "1.0" on one engine where
	 *   the other returns the number 1 is numerically equal and a different thing on the
	 *   wire — the case migrations/0256.sqlite.sql exists to fix.
	 * - **Two strings that differ in bytes.** "2.50" and "2.5" are both strings and both
	 *   normalise to 2.5, because Normalise() rounds anything is_numeric(). Comparing
	 *   only the type class would let that through, and a client reading the field as
	 *   text would see the difference.
	 *
	 * What must NOT be reported is two numbers differing in the last bit, which is the
	 * accepted engine difference db/pgsql/README.md documents and which Normalise()'s
	 * rounding already absorbs. So the relaxation applies to numbers only: strings are
	 * still compared by what they encode to, exactly as before this method existed.
	 */
	public static function ComparableTypes($a, $b): bool
	{
		if (self::JsonTypeOf($a) !== self::JsonTypeOf($b))
		{
			return false;
		}

		if (self::JsonTypeOf($a) === 'string')
		{
			return json_encode($a) === json_encode($b);
		}

		return true;
	}

	/**
	 * A timestamp as the instant it names, for comparing a TIMESTAMPTZ column on PostgreSQL
	 * with the frozen SQLite line's wall clock for the same value (ADR-0027 open question 3).
	 *
	 * - PostgreSQL's rendering (`2026-10-04 14:30:00-04`) and the wire rendering are
	 *   instants already.
	 * - A full wall clock (`2026-10-04 14:30:00`) is read in $sourceZone, the zone the
	 *   SQLite side's wall clocks are in, which the caller names explicitly. The lenient
	 *   reading is the one the views use for a time they derive: a repeated hour is the
	 *   earlier instant, a skipped one moves forward.
	 * - Anything else - a bare date, a value with no recognisable shape - is returned
	 *   unchanged, so it can only ever equal an identical string. That is what keeps a DATE
	 *   that was turned into an instant, or a malformed value, from comparing equal.
	 *
	 * Microseconds are kept on both sides, so a lost fraction is a difference.
	 */
	public static function NormaliseInstant($value, \DateTimeZone $sourceZone)
	{
		if (!is_string($value))
		{
			return $value;
		}

		$fromDatabase = Instant::FromDatabase($value);
		if ($fromDatabase !== null)
		{
			return $fromDatabase;
		}

		$parsed = Instant::Parse($value);
		if ($parsed !== null)
		{
			return Instant::ToWire($parsed);
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $value) === 1)
		{
			$instant = Instant::FromWallClock($value, $sourceZone, false);
			return $instant === null ? $value : Instant::ToWire($instant);
		}

		return $value;
	}

	/**
	 * The result columns a PostgreSQL statement types TIMESTAMPTZ and `timestamp without
	 * time zone`, by name, from the driver's column metadata. The second list should be empty
	 * after migration 0301; a caller treats anything in it as a failure, because a wall clock
	 * on the PostgreSQL side has no zone to compare by.
	 *
	 * @return array{instants: string[], wallClocks: string[]}
	 */
	public static function TimestampColumnsOf(\PDOStatement $statement): array
	{
		$columns = ['instants' => [], 'wallClocks' => []];
		for ($i = 0; $i < $statement->columnCount(); $i++)
		{
			$meta = $statement->getColumnMeta($i);
			if (($meta['native_type'] ?? null) === 'timestamptz')
			{
				$columns['instants'][] = $meta['name'];
			}
			elseif (($meta['native_type'] ?? null) === 'timestamp')
			{
				$columns['wallClocks'][] = $meta['name'];
			}
		}

		return $columns;
	}
}
