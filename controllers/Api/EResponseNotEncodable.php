<?php

namespace Victual\Controllers\Api;

/**
 * Thrown by BaseApiController::ApiResponse() when the body it was handed has no JSON form
 * (INF, NAN, invalid UTF-8, a resource, a recursion, a depth overrun).
 *
 * It is a server fault, not a caller mistake: the data came out of this application, so the
 * answer is a 500. It is its own type rather than a \RuntimeException because
 * HandleApiCall()'s generic \Exception catch answers 400 to everything it does not know.
 * The message is fixed and carries nothing of the body; the cause goes to the operator's log.
 * Issue #912, decision D2.
 */
class EResponseNotEncodable extends \RuntimeException
{
	/** The text the client sees as error_message. */
	public const CLIENT_MESSAGE = 'The response could not be encoded as JSON';
}
