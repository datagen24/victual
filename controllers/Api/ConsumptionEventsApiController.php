<?php

namespace Victual\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\ConsumptionEventService;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionMappingService;
use Victual\Services\DatabaseService;

/**
 * External consumption events and the mappings that let them book (ADR-0041).
 *
 * Every route needs STOCK_VIEW before the service is reached, and every write STOCK_CONSUME. Identity
 * is (the authenticated user, source_system, source_event_id): the user is never read from the request,
 * and the API key is passed on for audit only.
 *
 * The event routes deliberately do not use InRequestTransaction(). The service runs a receipt, a
 * booking and, after a rollback, a failure record as separate top-level transactions, and a transaction
 * opened here would merge them (ADR-0041 rule 5). It refuses to run inside one.
 */
class ConsumptionEventsApiController extends BaseApiController
{
	/** What this server implements. A name is added here when its behavior ships, never before. */
	public const CONTRACT_VERSION = 1;
	public const FEATURES = ['events', 'mappings', 'batch', 'bulk_resolve', 'manual_consume', 'deletion_reasons', 'not_logged', 'default_quantity', 'unit_labels', 'replaces', 'refill', 'refill_notices'];

	private function Events(): ConsumptionEventService
	{
		return ConsumptionEventService::GetInstance();
	}

	private function Mappings(): ConsumptionMappingService
	{
		return ConsumptionMappingService::GetInstance();
	}

	private function Run(Request $request, Response $response, callable $work): Response
	{
		return $this->HandleApiCall($response, function () use ($request, $response, $work)
		{
			try
			{
				$result = $work();

				if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true))
				{
					DatabaseService::GetInstance()->MarkDbChanged();
				}

				return $result;
			}
			catch (ConsumptionException $exception)
			{
				$response = $response->withStatus($exception->status);

				return $this->ApiResponse($response, ['error_message' => self::WithoutDriverText($exception->getMessage()), 'error' => $exception->errorCode]);
			}
		});
	}

	/**
	 * The JSON object of the request body, as sent. GetParsedAndFilteredRequestBody() is not used: it runs
	 * every top-level scalar through HTMLPurifier, which turns a number into a string, and this contract is
	 * strict about types (a quantity is a number, a location id an integer). Every value is validated against
	 * its documented pattern by the service, and the only free text, unit_label, is shown as text.
	 */
	private function Body(Request $request): array
	{
		$body = $request->getParsedBody();

		if ($body === null && (string)$request->getBody() === '')
		{
			return [];
		}

		if (self::MediaTypeOf($request) !== 'application/json')
		{
			throw new ConsumptionException(400, 'invalid_request', 'The Content-Type is application/json');
		}

		if (!is_array($body) || (count($body) > 0 && array_is_list($body) && !isset($body['events'])))
		{
			throw new ConsumptionException(400, 'invalid_request', 'The request body must be a JSON object');
		}

		return $body;
	}

	private static function ActingKeyId(): ?int
	{
		$key = ApiKeyService::GetInstance()->GetActingApiKey();

		return $key === null ? null : (int)$key->id;
	}

	private static function Read(Request $request): void
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
	}

	private static function Write(Request $request): void
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		User::CheckPermission($request, User::PERMISSION_STOCK_CONSUME);
	}

	// --- Events ------------------------------------------------------------------------------

	public function PutEvent(Request $request, Response $response, array $args)
	{
		self::Write($request);
		return $this->Run($request, $response, function () use ($request, $response, $args)
		{
			$body = $this->Body($request);
			$result = $this->Events()->Submit((int)VICTUAL_USER_ID, (string)$args['source_system'], (string)$args['source_event_id'], $body, self::ActingKeyId());

			return $this->ApiResponse($response->withStatus($result['created'] ? 201 : 200), $result['event']);
		});
	}

	public function GetEvent(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Events()->Get((int)VICTUAL_USER_ID, (string)$args['source_system'], (string)$args['source_event_id'])));
	}

	/** The reason is a query parameter or a field of a JSON body; a client that cannot say sends neither. */
	public function DeleteEvent(Request $request, Response $response, array $args)
	{
		self::Write($request);
		return $this->Run($request, $response, function () use ($request, $response, $args)
		{
			$body = $request->getBody()->getSize() ? $this->Body($request) : [];
			$reason = $request->getQueryParams()['reason'] ?? $body['reason'] ?? null;

			if ($reason !== null && !is_string($reason))
			{
				throw new ConsumptionException(400, 'invalid_request', 'reason is a string');
			}

			return $this->ApiResponse($response, $this->Events()->Delete((int)VICTUAL_USER_ID, (string)$args['source_system'], (string)$args['source_event_id'], $reason));
		});
	}

	public function ResolveEvent(Request $request, Response $response, array $args)
	{
		self::Write($request);
		return $this->Run($request, $response, function () use ($request, $response, $args)
		{
			$body = $this->Body($request);
			$transaction = $body['transaction_id'] ?? null;

			return $this->ApiResponse($response, $this->Events()->Resolve((int)VICTUAL_USER_ID, (string)$args['source_system'], (string)$args['source_event_id'],
				is_string($body['action'] ?? null) ? $body['action'] : '', is_string($transaction) ? $transaction : null));
		});
	}

	public function ListEvents(Request $request, Response $response, array $args)
	{
		self::Read($request);
		$query = $request->getQueryParams();

		return $this->Run($request, $response, function () use ($response, $query)
		{
			$states = isset($query['state']) && is_string($query['state']) && $query['state'] !== '' ? explode(',', $query['state']) : [];

			return $this->ApiResponse($response, $this->Events()->ListEvents((int)VICTUAL_USER_ID, $states,
				isset($query['since']) && is_string($query['since']) ? $query['since'] : null, isset($query['limit']) ? (int)$query['limit'] : 100));
		});
	}

	public function BatchEvents(Request $request, Response $response, array $args)
	{
		self::Write($request);
		return $this->Run($request, $response, function () use ($request, $response)
		{
			$body = $this->Body($request);
			if (!is_array($body['events'] ?? null))
			{
				throw new ConsumptionException(400, 'invalid_request', 'events is a list');
			}

			return $this->ApiResponse($response, $this->Events()->Batch((int)VICTUAL_USER_ID, array_values($body['events']), self::ActingKeyId()));
		});
	}

	public function BulkResolve(Request $request, Response $response, array $args)
	{
		self::Write($request);
		return $this->Run($request, $response, function () use ($request, $response)
		{
			$body = $this->Body($request);
			return $this->ApiResponse($response, $this->Events()->BulkResolve((int)VICTUAL_USER_ID, is_string($body['action'] ?? null) ? $body['action'] : '',
				is_array($body['events'] ?? null) ? $body['events'] : null, is_array($body['filter'] ?? null) ? $body['filter'] : null));
		});
	}

	// --- Mappings ----------------------------------------------------------------------------

	public function PutMapping(Request $request, Response $response, array $args)
	{
		self::Write($request);
		return $this->Run($request, $response, function () use ($request, $response, $args)
		{
			$body = $this->Body($request);
			$result = $this->Mappings()->Put((int)VICTUAL_USER_ID, (string)$args['source_system'], (string)$args['medication_ref'], $body);

			return $this->ApiResponse($response->withStatus($result['created'] ? 201 : 200), $result['mapping']);
		});
	}

	public function GetMapping(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Mappings()->Get((int)VICTUAL_USER_ID, (string)$args['source_system'], (string)$args['medication_ref'])));
	}

	public function ListMappings(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Mappings()->ListMappings((int)VICTUAL_USER_ID)));
	}

	public function DeleteMapping(Request $request, Response $response, array $args)
	{
		self::Write($request);

		return $this->Run($request, $response, function () use ($response, $args)
		{
			$this->Mappings()->Delete((int)VICTUAL_USER_ID, (string)$args['source_system'], (string)$args['medication_ref']);

			return $this->EmptyApiResponse($response);
		});
	}

	// --- Capabilities ------------------------------------------------------------------------

	public function Capabilities(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->ApiResponse($response, ['contract_version' => self::CONTRACT_VERSION, 'features' => self::FEATURES]);
	}
}
