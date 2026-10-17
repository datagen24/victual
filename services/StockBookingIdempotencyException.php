<?php

namespace Victual\Services;

/** A typed booking-key refusal, mapped to its documented HTTP status. */
class StockBookingIdempotencyException extends \RuntimeException
{
	public function __construct(public readonly string $ErrorCode, string $message, public readonly int $Status = 409)
	{
		parent::__construct($message);
	}
}
