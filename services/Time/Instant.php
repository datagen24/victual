<?php

namespace Victual\Services\Time;

/**
 * The one definition of how this API reads and writes an instant.
 *
 * ADR-0027 decision 2 (revised 2026-10-04) stores every timestamp as TIMESTAMPTZ and sends
 * it as RFC 3339 in UTC with exactly six fractional digits: `2026-10-04T18:30:00.000000Z`.
 * The fixed width is part of the contract, because it is what makes text order equal
 * chronological order for a client that compares the strings (open question 2).
 *
 * Reading a wall clock - a value with no offset - is the hard half, and neither engine's
 * default is the rule this project decided:
 *
 * - PostgreSQL's `ts AT TIME ZONE zone` takes the *later* of the two instants in the hour
 *   that repeats when daylight saving ends, and moves a time the zone skipped forward
 *   without a word. `2026-11-01 01:30:00` on America/New_York becomes 06:30Z.
 * - PHP takes the earlier instant on America/New_York but the later one on
 *   Australia/Lord_Howe, whose clocks fall back by thirty minutes, and also moves a skipped
 *   time forward.
 *
 * ADR-0028's answer is the earlier instant for a repeated time and a refusal for a skipped
 * one. FromWallClock() decides both explicitly rather than inheriting either engine's
 * choice, and migration 0301's `victual_local_to_instant()` is the same algorithm in SQL:
 * every offset the zone uses within a day either side is a candidate, a candidate is valid
 * when the zone really is at that offset at the resulting instant, and the earliest valid
 * candidate wins. No valid candidate means the zone skipped that wall clock.
 */
final class Instant
{
	/** The rendering every instant takes on the wire. */
	const WIRE_FORMAT = 'Y-m-d\\TH:i:s.u\\Z';

	/**
	 * The wire rendering as a pattern, documented as the `pattern` of every instant property
	 * in victual.openapi.json; a test asserts the two are the same string.
	 */
	const WIRE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z$';

	/**
	 * What PostgreSQL sends for a TIMESTAMPTZ value with DateStyle ISO: the session zone's
	 * wall clock, up to six fractional digits, and an offset of hours with optional minutes
	 * and seconds (seconds only for local mean time, before standard zones existed).
	 */
	const DATABASE_PATTERN = '/^(\\d{4}-\\d{2}-\\d{2}) (\\d{2}:\\d{2}:\\d{2})(?:\\.(\\d{1,6}))?([+-])(\\d{2})(?::(\\d{2}))?(?::(\\d{2}))?$/D';

	/**
	 * Sentinel instants. The legacy schema wrote `2999-12-31 23:59:59` as "never" in the
	 * configured zone; as an instant it is fixed in UTC, so the wire value is the same on
	 * every server and a client can test for it as text.
	 */
	const NEVER = '2999-12-31T23:59:59.000000Z';

	private const SEARCH_WINDOW_SECONDS = 26 * 3600;

	/**
	 * An instant in the wire rendering.
	 */
	public static function ToWire(\DateTimeInterface $instant): string
	{
		return \DateTimeImmutable::createFromInterface($instant)
			->setTimezone(new \DateTimeZone('UTC'))
			->format(self::WIRE_FORMAT);
	}

	/**
	 * The current instant in the wire rendering, to the second.
	 *
	 * Whole seconds, because that is what every legacy writer stored - `date()` and the
	 * `date_trunc('second', ...)` column defaults - and what the column defaults still
	 * store. A microsecond "now" would make two bookings in the same second compare
	 * unequal where they used to compare equal, for no gain anyone asked for.
	 */
	public static function Now(): string
	{
		return self::ToWire(new \DateTimeImmutable('@' . time()));
	}

	/**
	 * A wire-rendered instant `$seconds` from now, to the second.
	 */
	public static function FromNow(int $seconds): string
	{
		return self::ToWire(new \DateTimeImmutable('@' . (time() + $seconds)));
	}

	/**
	 * The configured server zone - PHP's default zone, which PostgresDialect also gives
	 * the database session.
	 */
	public static function ServerZone(): \DateTimeZone
	{
		return new \DateTimeZone(date_default_timezone_get());
	}

	/**
	 * Whether a string is an instant in the wire rendering.
	 */
	public static function IsWire($value): bool
	{
		return is_string($value) && preg_match('/' . self::WIRE_PATTERN . '/D', $value) === 1;
	}

	/**
	 * A TIMESTAMPTZ value as PostgreSQL rendered it, in the wire rendering; null when the
	 * string is not that rendering. `infinity`, BC dates and years above 9999 are not, and
	 * nothing in this schema stores them.
	 */
	public static function FromDatabase(string $value): ?string
	{
		if (preg_match(self::DATABASE_PATTERN, $value, $m) !== 1)
		{
			return null;
		}

		$micro = str_pad($m[3] ?? '', 6, '0');
		$offset = ((int)$m[5]) * 3600 + ((int)($m[6] ?? 0)) * 60 + ((int)($m[7] ?? 0));
		if ($m[4] === '-')
		{
			$offset = -$offset;
		}

		$naive = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $m[1] . ' ' . $m[2] . '.' . $micro, new \DateTimeZone('UTC'));
		if ($naive === false)
		{
			return null;
		}

		return self::ToWire($naive->modify(-$offset . ' seconds'));
	}

	/**
	 * Any instant this application handles - the wire rendering, PostgreSQL's TIMESTAMPTZ
	 * rendering, or an RFC 3339 value with an offset - as a DateTimeImmutable in UTC.
	 * Null for a value that carries no zone: a wall clock is not an instant until a zone is
	 * chosen for it, and FromWallClock() is where that choice is made.
	 */
	public static function Parse($value): ?\DateTimeImmutable
	{
		if (!is_string($value))
		{
			return null;
		}

		if (preg_match(self::DATABASE_PATTERN, $value) === 1)
		{
			$value = self::FromDatabase($value);
		}

		if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})T(\\d{2}:\\d{2}:\\d{2})(?:\\.(\\d+))?([Zz]|[+-]\\d{2}:\\d{2})$/D', $value, $m) !== 1)
		{
			return null;
		}

		$micro = substr(str_pad($m[3] ?? '', 6, '0'), 0, 6);
		$zone = strtoupper($m[4]) === 'Z' ? '+00:00' : $m[4];
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $m[1] . ' ' . $m[2] . '.' . $micro . $zone);
		$errors = \DateTimeImmutable::getLastErrors();
		if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)))
		{
			return null;
		}

		return $parsed->setTimezone(new \DateTimeZone('UTC'));
	}

	/**
	 * A value that may be an instant (any rendering Parse() reads) or a legacy wall clock
	 * in the configured zone (`Y-m-d H:i:s`, or a bare `Y-m-d` meaning its midnight, as written
	 * before migration 0301 and as still
	 * queued in a pre-upgrade outbox), read leniently. The compatibility reader for stored
	 * payloads, never for request input: a skipped wall clock is moved forward rather than
	 * refused, because the row it came from already exists.
	 */
	public static function ParseStored($value): ?\DateTimeImmutable
	{
		$parsed = self::Parse($value);
		if ($parsed !== null)
		{
			return $parsed;
		}

		if (is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $value) === 1)
		{
			$value .= ' 00:00:00';
		}

		if (is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(\\.\\d{1,6})?$/D', $value) === 1)
		{
			return self::FromWallClock($value, self::ServerZone(), false);
		}

		return null;
	}

	/**
	 * The instant a wall clock names in $zone, under ADR-0028's rule.
	 *
	 * A wall clock the zone repeated names the earlier instant. A wall clock the zone
	 * skipped is refused (null) when $strict, and otherwise read at the offset in force
	 * before the transition, which moves it forward by the size of the gap - what both
	 * engines and the browser do, and the right answer for a *derived* time such as a
	 * schedule's next occurrence, which must land somewhere.
	 *
	 * @param string $wallClock `Y-m-d H:i:s`, optionally with up to six fractional digits
	 */
	public static function FromWallClock(string $wallClock, \DateTimeZone $zone, bool $strict): ?\DateTimeImmutable
	{
		if (preg_match('/^(\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2})(?:\\.(\\d{1,6}))?$/D', $wallClock, $m) !== 1)
		{
			return null;
		}

		$utc = new \DateTimeZone('UTC');
		$naive = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $m[1] . '.' . str_pad($m[2] ?? '', 6, '0'), $utc);
		$errors = \DateTimeImmutable::getLastErrors();
		if ($naive === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)))
		{
			return null;
		}

		$before = $zone->getOffset($naive->modify('-' . self::SEARCH_WINDOW_SECONDS . ' seconds'));
		$offsets = array_unique([
			$before,
			$zone->getOffset($naive),
			$zone->getOffset($naive->modify('+' . self::SEARCH_WINDOW_SECONDS . ' seconds'))
		]);

		$valid = [];
		foreach ($offsets as $offset)
		{
			$candidate = $naive->modify(-$offset . ' seconds');
			if ($zone->getOffset($candidate) === $offset)
			{
				$valid[] = $candidate;
			}
		}

		if (!empty($valid))
		{
			usort($valid, fn($a, $b) => $a <=> $b);
			return $valid[0];
		}

		if ($strict)
		{
			return null;
		}

		return $naive->modify(-$before . ' seconds');
	}

	/**
	 * The wall clock an instant shows in $zone, as `Y-m-d H:i:s`.
	 */
	public static function WallClockIn(\DateTimeInterface $instant, \DateTimeZone $zone): string
	{
		return \DateTimeImmutable::createFromInterface($instant)->setTimezone($zone)->format('Y-m-d H:i:s');
	}

	/**
	 * The instant at which the configured zone's calendar day $date (`Y-m-d`) ends - its
	 * last whole second, `23:59:59`, as the legacy string comparisons used. Server-defined
	 * day boundaries ("due today", "overdue") stay in the configured zone (ADR-0027).
	 */
	public static function EndOfServerDay(string $date): string
	{
		return self::ToWire(self::FromWallClock($date . ' 23:59:59', self::ServerZone(), false));
	}

	/**
	 * The configured zone's calendar date for an instant, as `Y-m-d`.
	 */
	public static function ServerDateOf($value): ?string
	{
		$parsed = self::ParseStored($value);
		return $parsed === null ? null : $parsed->setTimezone(self::ServerZone())->format('Y-m-d');
	}

	/**
	 * Epoch nanoseconds for an instant, as a decimal string so that no float rounds it -
	 * InfluxDB's line protocol precision. Microsecond precision is what is stored, so the
	 * last three digits are always zero.
	 */
	public static function ToEpochNanoseconds(\DateTimeInterface $instant): string
	{
		$utc = \DateTimeImmutable::createFromInterface($instant)->setTimezone(new \DateTimeZone('UTC'));
		// format('U') floors to the earlier second and 'u' counts forward from it, so the sum
		// is right on both sides of the epoch. Appending three zeros multiplies by a thousand
		// without leaving integer range, which nanoseconds after 2262 would.
		return (string)(((int)$utc->format('U')) * 1000000 + (int)$utc->format('u')) . '000';
	}
}
