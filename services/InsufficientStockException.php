<?php

namespace Victual\Services;

/**
 * StockService::AssertScopedStockAvailable() refuses a consumption the scope cannot cover.
 *
 * A type of its own because ConsumeProduct() refuses nine different causes with a plain
 * \Exception and code 0, and a caller that needs to treat a shortfall differently from a measured
 * container refusal would otherwise match the message (ADR-0041, Consequences).
 */
class InsufficientStockException extends \RuntimeException
{
	public function __construct(public readonly int $productId, public readonly float $requested, public readonly float $available, public readonly ?int $locationId)
	{
		parent::__construct('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)');
	}
}
