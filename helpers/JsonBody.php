<?php

namespace Victual\Helpers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Victual\Controllers\Api\EResponseNotEncodable;

/**
 * The checked JSON encode for the response writes that cannot go through
 * BaseApiController::ApiResponse(): middleware refusals, a non-API JSON route, and values a
 * controller hands to a page script.
 *
 * It follows ApiResponse()'s rules (issue #912, decision D2 as amended 2026-10-21): encode
 * with JSON_THROW_ON_ERROR, log the cause, and never write an empty body or pass on
 * json_encode()'s `false`. Encode() throws EResponseNotEncodable, which ExceptionController
 * answers as a 500. WriteRefusal() is for a refusal that already has its status, as
 * GenericErrorResponse() is: the status is kept and the body is the fixed literal. Issue #1047.
 */
class JsonBody
{
	/** The body of an error answer whose own message could not be JSON-encoded. */
	public const FIXED_ERROR_BODY = '{"error_message":"The error message could not be encoded as JSON"}';

	/**
	 * Returns $data as JSON, or throws EResponseNotEncodable with the cause logged.
	 *
	 * The bytes on success are those json_encode() with no flags produces, since
	 * JSON_THROW_ON_ERROR changes no output.
	 *
	 * @throws EResponseNotEncodable
	 */
	public static function Encode($data, ?LoggerInterface $logger = null): string
	{
		try
		{
			return json_encode($data, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $ex)
		{
			self::LogFailure($logger, 'A response body could not be encoded as JSON: ', $ex, $data);

			throw new EResponseNotEncodable(EResponseNotEncodable::CLIENT_MESSAGE, 500, $ex);
		}
	}

	/**
	 * Writes $body JSON-encoded to $response and returns it, status and headers unchanged.
	 *
	 * When $body has no JSON form the cause is logged and FIXED_ERROR_BODY is written in its
	 * place, so a 400 or 403 refusal stays a 400 or 403 rather than becoming an empty body or
	 * a 500.
	 */
	public static function WriteRefusal(Response $response, array $body, ?LoggerInterface $logger = null): Response
	{
		try
		{
			$encoded = json_encode($body, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $ex)
		{
			self::LogFailure($logger, 'A refusal body could not be encoded as JSON, the fixed literal was sent: ', $ex, $body);
			$encoded = self::FIXED_ERROR_BODY;
		}

		$response->getBody()->write($encoded);
		return $response;
	}

	/**
	 * Records the cause with the context ApiResponse() records: the JSON error code and the
	 * type of the value, never the value itself.
	 */
	private static function LogFailure(?LoggerInterface $logger, string $message, \JsonException $ex, $data): void
	{
		($logger ?? new StderrLogger())->error($message . $ex->getMessage(), [
			'json_error_code' => $ex->getCode(),
			'body_type' => get_debug_type($data)
		]);
	}
}
