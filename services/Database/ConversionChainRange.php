<?php

namespace Victual\Services\Database;

/**
 * Identifies the refusal migration 0318 raises (SQLSTATE VQ952, issue #952) when a quantity unit
 * conversion write would make a written or resolved factor, or its inverse, anything but a
 * positive, finite, normal double, and gives the text a client is shown for it.
 */
final class ConversionChainRange
{
	/** The SQLSTATE refresh_quantity_unit_conversion_cache() and the conversion triggers raise. */
	public const SQLSTATE = 'VQ952';

	/**
	 * The refusal's client-facing text, read from a driver message that begins with
	 * SQLSTATE[VQ952]. BaseApiController::WithoutDriverText() calls this for every such
	 * message, whichever path rethrew it, in place of the generic database rejection.
	 */
	public static function FromDriverText(string $driverText): string
	{
		// The trigger's MESSAGE is the client text. PDO's text prefixes it with "ERROR:  " and
		// may follow it with a CONTEXT block naming the trigger.
		if (preg_match('/ERROR:\s+(This quantity unit conversion would make [^\n]+)/', $driverText, $match) !== 1)
		{
			return 'This quantity unit conversion would make a conversion factor too large or too small to use';
		}

		return $match[1];
	}
}
