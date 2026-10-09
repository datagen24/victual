<?php

namespace Victual\Services;

/**
 * A refusal by ConsumptionRecipeService that carries the HTTP status it answers with.
 *
 * `status` is 403 for a right or permission the caller lacks on a recipe they can see, 404
 * for a recipe, event or share they cannot see or that does not exist (ADR-0040 rule 7: the two
 * answers are the same), 409 for a state conflict or a stock refusal, and 422 for a request the
 * service cannot act on. `errorCode` is a stable token; the message is for a person.
 */
class ConsumptionException extends \RuntimeException
{
	public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
	{
		parent::__construct($message, $status);
	}
}
