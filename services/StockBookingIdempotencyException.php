<?php

namespace Victual\Services;

/** A typed booking-key refusal, mapped to its documented HTTP status. */
class StockBookingIdempotencyException extends \RuntimeException
{
	/**
	 * @param string $ErrorCode The stable code the 4xx body carries (idempotency_conflict, idempotency_in_progress, invalid_value)
	 * @param int $Status The HTTP status: 409, or 400 for a malformed key
	 */
	public function __construct(public readonly string $ErrorCode, string $message, public readonly int $Status = 409)
	{
		parent::__construct($message);
	}
}
