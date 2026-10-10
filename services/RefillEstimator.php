<?php

namespace Victual\Services;

/**
 * The reorder estimate and the status of a prescription's refill (ADR-0042 sections 2 to 4).
 *
 * Pure: it reads no table, no clock and no zone. The caller passes the current fill, the rule and the
 * explicit date it found, and the client's calendar date ("as of"). Every date is a calendar date
 * written YYYY-MM-DD and every step is integer day arithmetic done in UTC, so no daylight-saving
 * change and no database session zone can move a result by a day. A fraction is an integer percent
 * (`floor(supplied_days * P / 100)` in integers), never a float: 100 days at 0.58 gives 57 as a
 * float, and 58 here.
 *
 * Wording (ADR-0015): an estimate is a recorded fact and a calculation from it. Nothing here says
 * an insurer or a pharmacy will allow a refill on the date, and nothing tells a person what to take.
 */
final class RefillEstimator
{
	public const SOURCE_EXPLICIT = 'explicit';
	public const SOURCE_FALLBACK = 'fallback';
	public const SOURCE_RULE_PREFIX = 'rule:';

	public const RULE_DAYS_BEFORE_END = 'days_before_end';
	public const RULE_FIXED_INTERVAL = 'fixed_interval';
	public const RULE_FRACTION_ELAPSED = 'fraction_elapsed';
	public const RULE_KINDS = [self::RULE_DAYS_BEFORE_END, self::RULE_FIXED_INTERVAL, self::RULE_FRACTION_ELAPSED];

	public const REASON_NO_FILL = 'no_fill';
	public const REASON_INVALID_RULE = 'invalid_rule';
	public const REASON_INVALID_SUPPLY = 'invalid_supply';
	public const REASON_RESULT_NOT_AFTER_FILL = 'result_not_after_fill';

	public const STATUS_OK = 'ok';
	public const STATUS_APPROACHING = 'approaching';
	public const STATUS_DUE = 'due';
	public const STATUS_ORDERED = 'ordered';
	public const STATUS_UNKNOWN = 'unknown';

	/** The general approximation: the last fill date plus the supplied days, less these days. */
	public const FALLBACK_DAYS_BEFORE_END = 14;
	public const DEFAULT_LEAD_DAYS = 7;
	public const MIN_LEAD_DAYS = 0;
	public const MAX_LEAD_DAYS = 60;
	public const MIN_SUPPLIED_DAYS = 1;
	public const MAX_SUPPLIED_DAYS = 730;

	/** The inclusive parameter range of each rule kind: N, D and P of ADR-0042 section 2. */
	private const RULE_RANGES = [
		self::RULE_DAYS_BEFORE_END => [0, 730],
		self::RULE_FIXED_INTERVAL => [1, 730],
		self::RULE_FRACTION_ELAPSED => [1, 99],
	];

	/**
	 * A strict calendar date, or null. `2026-02-30` is null: PHP would roll it over to March, and a
	 * rolled-over date is an invented one.
	 */
	public static function ParseDate(mixed $value): ?\DateTimeImmutable
	{
		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1)
		{
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

		return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
	}

	/** Calendar arithmetic: `$days` may be negative. The result is a YYYY-MM-DD string. */
	public static function AddDays(string $date, int $days): string
	{
		$parsed = self::ParseDate($date);
		if ($parsed === null)
		{
			throw new \InvalidArgumentException('Not a calendar date: ' . $date);
		}

		return $parsed->modify(($days < 0 ? '-' : '+') . abs($days) . ' days')->format('Y-m-d');
	}

	/** Whole days from `$from` to `$to` (negative when `$to` is earlier). */
	public static function DaysBetween(string $from, string $to): int
	{
		$start = self::ParseDate($from);
		$end = self::ParseDate($to);
		if ($start === null || $end === null)
		{
			throw new \InvalidArgumentException('Not a calendar date: ' . ($start === null ? $from : $to));
		}

		// Both are midnight UTC, so the difference is a whole number of days.
		return intdiv($end->getTimestamp() - $start->getTimestamp(), 86400);
	}

	public static function ValidSuppliedDays(mixed $value): bool
	{
		return is_int($value) && $value >= self::MIN_SUPPLIED_DAYS && $value <= self::MAX_SUPPLIED_DAYS;
	}

	public static function ValidLeadDays(mixed $value): bool
	{
		return is_int($value) && $value >= self::MIN_LEAD_DAYS && $value <= self::MAX_LEAD_DAYS;
	}

	/** True when `$kind` is a rule kind and the integer `$parameter` is inside that kind's range. */
	public static function ValidRule(mixed $kind, mixed $parameter): bool
	{
		if (!is_string($kind) || !isset(self::RULE_RANGES[$kind]) || !is_int($parameter))
		{
			return false;
		}

		[$minimum, $maximum] = self::RULE_RANGES[$kind];

		return $parameter >= $minimum && $parameter <= $maximum;
	}

	/**
	 * The estimated reorder date, from the first rule that applies (ADR-0042 section 2), or the first
	 * failed check (section 3).
	 *
	 * @param array{filled_on: string, supplied_days: int|null}|null $fill the current fill, or null when none is unvoided
	 * @param array{kind: mixed, parameter: mixed}|null $rule the medication-specific rule, or null when the recipe has none
	 * @param string|null $explicitDate the live explicit date tied to the current fill, already validated against it
	 * @return array{reorder_date: string|null, source: string|null, reason: string|null}
	 */
	public static function Estimate(?array $fill, ?array $rule, ?string $explicitDate): array
	{
		if ($fill === null)
		{
			return self::Unknown(self::REASON_NO_FILL);
		}

		// An explicit date skips the rule, the supply and the "after the fill" checks. It was
		// refused at write time if it was earlier than its fill.
		if ($explicitDate !== null && self::ParseDate($explicitDate) !== null)
		{
			return ['reorder_date' => $explicitDate, 'source' => self::SOURCE_EXPLICIT, 'reason' => null];
		}

		$filledOn = $fill['filled_on'];
		if (self::ParseDate($filledOn) === null)
		{
			return self::Unknown(self::REASON_NO_FILL);
		}

		$kind = null;
		$parameter = null;
		if ($rule !== null)
		{
			if (!self::ValidRule($rule['kind'] ?? null, $rule['parameter'] ?? null))
			{
				return self::Unknown(self::REASON_INVALID_RULE);
			}

			$kind = $rule['kind'];
			$parameter = $rule['parameter'];
		}

		$supplied = $fill['supplied_days'] ?? null;
		if ($kind !== self::RULE_FIXED_INTERVAL && !self::ValidSuppliedDays($supplied))
		{
			return self::Unknown(self::REASON_INVALID_SUPPLY);
		}

		switch ($kind)
		{
			case self::RULE_DAYS_BEFORE_END:
				$date = self::AddDays($filledOn, $supplied - $parameter);
				$source = self::SOURCE_RULE_PREFIX . $kind;
				break;
			case self::RULE_FIXED_INTERVAL:
				$date = self::AddDays($filledOn, $parameter);
				$source = self::SOURCE_RULE_PREFIX . $kind;
				break;
			case self::RULE_FRACTION_ELAPSED:
				$date = self::AddDays($filledOn, intdiv($supplied * $parameter, 100));
				$source = self::SOURCE_RULE_PREFIX . $kind;
				break;
			default:
				$date = self::AddDays($filledOn, $supplied - self::FALLBACK_DAYS_BEFORE_END);
				$source = self::SOURCE_FALLBACK;
		}

		// No clamping: a 14-day supply with the fallback lands on the fill date. Inventing a date
		// would be a guess presented as an estimate, so the person sets a rule or a date instead.
		if (self::DaysBetween($filledOn, $date) <= 0)
		{
			return self::Unknown(self::REASON_RESULT_NOT_AFTER_FILL);
		}

		return ['reorder_date' => $date, 'source' => $source, 'reason' => null];
	}

	/**
	 * The status on `$asOf` (ADR-0042 section 4). `ordered` wins over every other state, because an
	 * open order is a request already made. The reorder date itself is `due`; the warning date,
	 * `reorder - lead`, is the first day of `approaching`.
	 *
	 * @param array{reorder_date: string|null} $estimate
	 * @return array{status: string, days_overdue: int|null, warning_date: string|null}
	 */
	public static function Status(array $estimate, int $leadDays, string $asOf, bool $hasOpenOrder): array
	{
		$reorder = $estimate['reorder_date'];
		$warning = $reorder === null ? null : self::AddDays($reorder, -$leadDays);

		if ($hasOpenOrder)
		{
			return ['status' => self::STATUS_ORDERED, 'days_overdue' => null, 'warning_date' => $warning];
		}

		if ($reorder === null)
		{
			return ['status' => self::STATUS_UNKNOWN, 'days_overdue' => null, 'warning_date' => null];
		}

		$untilReorder = self::DaysBetween($asOf, $reorder);
		if ($untilReorder <= 0)
		{
			return ['status' => self::STATUS_DUE, 'days_overdue' => -$untilReorder, 'warning_date' => $warning];
		}

		return ['status' => self::DaysBetween($asOf, $warning) <= 0 ? self::STATUS_APPROACHING : self::STATUS_OK, 'days_overdue' => null, 'warning_date' => $warning];
	}

	/** @return array{reorder_date: null, source: null, reason: string} */
	private static function Unknown(string $reason): array
	{
		return ['reorder_date' => null, 'source' => null, 'reason' => $reason];
	}
}
