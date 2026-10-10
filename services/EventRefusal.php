<?php

namespace Victual\Services;

/**
 * Thrown inside the booking transaction of ConsumptionEventService to roll it back and name the
 * `needs_review` reason that the failure record then writes (ADR-0041 rule 5). It is not an error
 * response: the request that raised it stores successfully and answers 200 or 201 with that state.
 */
class EventRefusal extends \RuntimeException
{
	/**
	 * @param array<string, mixed> $context candidate_location_ids, or replaces
	 * @param string|null $privateMessage the refusal text of a stock error, shown only to the event's user
	 */
	public function __construct(public readonly string $reason, public readonly array $context = [], public readonly ?string $privateMessage = null, ?string $detail = null)
	{
		parent::__construct($detail ?? $reason);
	}
}
