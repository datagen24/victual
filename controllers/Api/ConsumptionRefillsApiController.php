<?php

namespace Victual\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Victual\Controllers\Users\User;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionRefillService;

/**
 * Refill history, reorder estimates, orders and notices for a private consumption recipe (ADR-0042).
 *
 * Every route needs STOCK_VIEW before the service is reached. Past that, access is the recipe's own
 * (ADR-0040): a recipe the caller holds no share on answers 404, exactly as one that does not exist,
 * and a write needs the `edit` right (403 otherwise). No route here is a stock operation, so none
 * needs STOCK_CONSUME or STOCK_EDIT, and none writes the stock ledger.
 *
 * Every read that depends on today takes `as_of=YYYY-MM-DD`, the client's local date. Without it the
 * server uses the UTC date and says so in `as_of_source`. A write that records a date requires the
 * date in its body and never defaults it. Every write answers with the recipe's refill state.
 *
 * Refill state is not published through MQTT, Influx, a webhook or the calendar feed, and nothing
 * here sends a notification: a client reads the notices and delivers its own (ADR-0042 section 6).
 */
class ConsumptionRefillsApiController extends BaseApiController
{
	private function Service(): ConsumptionRefillService
	{
		return ConsumptionRefillService::GetInstance();
	}

	/** Runs a handler in a request transaction and turns a ConsumptionException into its status. */
	private function Run(Request $request, Response $response, callable $work): Response
	{
		return $this->HandleApiCall($response, function () use ($request, $response, $work)
		{
			try
			{
				return $this->InRequestTransaction($request, $work);
			}
			catch (ConsumptionException $exception)
			{
				$response = $response->withStatus($exception->status);
				return $this->ApiResponse($response, ['error_message' => self::WithoutDriverText($exception->getMessage()), 'error' => $exception->errorCode]);
			}
		});
	}

	private static function Read(Request $request): void
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
	}

	/**
	 * The JSON object of the request body, as sent. GetParsedAndFilteredRequestBody() is not used: it
	 * turns a number into a string, and this contract is strict about types (supplied_days and a lead are
	 * integers). Every value is validated by the service, and the free text (a note, a void reason) is
	 * stored as sent and shown as text.
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

		if (!is_array($body) || (count($body) > 0 && array_is_list($body)))
		{
			throw new ConsumptionException(400, 'invalid_request', 'The request body must be a JSON object');
		}

		return $body;
	}

	/** The as_of parameter as sent: null only when it is absent, so a malformed one (an array, an empty value) is refused. */
	private static function AsOf(Request $request): mixed
	{
		$query = $request->getQueryParams();

		return array_key_exists('as_of', $query) ? $query['as_of'] : null;
	}

	/** A path id as an integer; one that is not digits or does not fit PostgreSQL's integer names nothing, so it is 0 and answers 404. */
	private static function Id(array $args, string $name): int
	{
		$value = (string)($args[$name] ?? '');

		return ctype_digit($value) && strlen($value) <= 10 && (int)$value <= 2147483647 ? (int)$value : 0;
	}

	private static function RecipeId(array $args): int
	{
		return self::Id($args, 'recipeId');
	}

	// --- One recipe --------------------------------------------------------------------------

	public function GetRefill(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->GetRefill(self::RecipeId($args), self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	public function SetSettings(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response,
			$this->Service()->SetSettings(self::RecipeId($args), $this->Body($request), self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	public function RecordFill(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response->withStatus(201),
			$this->Service()->RecordFill(self::RecipeId($args), $this->Body($request), self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	public function VoidFill(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, function () use ($request, $response, $args)
		{
			$body = $this->Body($request);
			if (array_diff(array_keys($body), ['reason']) !== [])
			{
				throw new ConsumptionException(422, 'unknown_field', 'Unknown field: ' . (string)array_values(array_diff(array_keys($body), ['reason']))[0]);
			}

			return $this->ApiResponse($response, $this->Service()->VoidFill(self::RecipeId($args), self::Id($args, 'fillId'), $body['reason'] ?? null, self::AsOf($request), (int)VICTUAL_USER_ID));
		});
	}

	public function RecordOrder(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response->withStatus(201),
			$this->Service()->RecordOrder(self::RecipeId($args), $this->Body($request), self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	public function ReceiveOrder(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response,
			$this->Service()->ReceiveOrder(self::RecipeId($args), self::Id($args, 'orderId'), $this->Body($request), self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	public function CancelOrder(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response,
			$this->Service()->CancelOrder(self::RecipeId($args), self::Id($args, 'orderId'), self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	// --- Every recipe the caller can read ----------------------------------------------------

	public function ListRefills(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->ListRefills(self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	public function ListNotices(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->Notices(self::AsOf($request), (int)VICTUAL_USER_ID)));
	}

	public function AcknowledgeNotice(Request $request, Response $response, array $args)
	{
		self::Read($request);

		return $this->Run($request, $response, function () use ($request, $response)
		{
			$body = $this->Body($request);
			if (array_diff(array_keys($body), ['notice_key']) !== [])
			{
				throw new ConsumptionException(422, 'unknown_field', 'Unknown field: ' . (string)array_values(array_diff(array_keys($body), ['notice_key']))[0]);
			}

			return $this->ApiResponse($response, $this->Service()->Acknowledge($body['notice_key'] ?? null, (int)VICTUAL_USER_ID));
		});
	}
}
