<?php

declare(strict_types=1);

namespace Victual\Services\Database;

/** Decimal text that parses back to the same PHP binary64 value in PostgreSQL. */
final class RoundTripFloat
{
	public static function Format(float $value): string
	{
		return str_replace(localeconv()['decimal_point'], '.', sprintf('%.17g', $value));
	}
}
