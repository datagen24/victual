<?php

namespace Victual\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Victual\Controllers\Users\User;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionRecipeService;

/**
 * Private consumption recipes (ADR-0040) and the manual consumption events they create (ADR-0041).
 *
 * Every route needs STOCK_VIEW before the service is reached, so a caller with no grants gets 403
 * (RbacTest). Past that, access is the share and ownership rules inside ConsumptionRecipeService:
 * a recipe the caller holds no share on answers 404, exactly as one that does not exist.
 */
class ConsumptionRecipesApiController extends BaseApiController
{
	private function Service(): ConsumptionRecipeService
	{
		return ConsumptionRecipeService::GetInstance();
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

	private static function RecipeId(array $args): int
	{
		return (int)$args['recipeId'];
	}

	/** @return array the JSON object of the request body, or an empty array when there is none */
	private function Body(Request $request): array
	{
		return $this->GetParsedAndFilteredRequestBody($request) ?? [];
	}

	private static function RightsFrom(array $body): array
	{
		$rights = [];
		foreach (['consume', 'edit', 'undo', 'share'] as $right)
		{
			$rights[$right] = isset($body[$right]) && ($body[$right] === true || $body[$right] === 1 || $body[$right] === '1' || $body[$right] === 'true');
		}

		return $rights;
	}

	public function ListRecipes(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->ListRecipes((int)VICTUAL_USER_ID)));
	}

	public function CreateRecipe(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		User::CheckPermission($request, User::PERMISSION_STOCK_CONSUME);
		$body = $this->Body($request);

		return $this->Run($request, $response, function () use ($response, $body)
		{
			$id = $this->Service()->CreateRecipe((string)($body['name'] ?? ''), isset($body['note']) ? (string)$body['note'] : null, (array)($body['lines'] ?? []), (int)VICTUAL_USER_ID);

			return $this->ApiResponse($response, ['created_object_id' => $id]);
		});
	}

	public function GetRecipe(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->GetRecipe(self::RecipeId($args), (int)VICTUAL_USER_ID)));
	}

	public function UpdateRecipe(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$body = $this->Body($request);

		return $this->Run($request, $response, function () use ($response, $args, $body)
		{
			$this->Service()->UpdateRecipe(self::RecipeId($args), array_intersect_key($body, array_flip(['name', 'note', 'lines'])), (int)VICTUAL_USER_ID);

			return $this->EmptyApiResponse($response);
		});
	}

	public function DeleteRecipe(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->Run($request, $response, function () use ($response, $args)
		{
			$this->Service()->DeleteRecipe(self::RecipeId($args), (int)VICTUAL_USER_ID);

			return $this->EmptyApiResponse($response);
		});
	}

	public function ConsumeRecipe(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		User::CheckPermission($request, User::PERMISSION_STOCK_CONSUME);
		$body = $this->Body($request);

		return $this->Run($request, $response, function () use ($response, $args, $body)
		{
			$event = $this->Service()->Consume(self::RecipeId($args), isset($body['request_id']) ? (string)$body['request_id'] : null,
				isset($body['location_id']) ? (int)$body['location_id'] : null, isset($body['occurred_at']) ? (string)$body['occurred_at'] : null, (int)VICTUAL_USER_ID);

			return $this->ApiResponse($response->withStatus($event['replayed'] ? 200 : 201), $event);
		});
	}

	public function ListEvents(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->ListEvents(self::RecipeId($args), (int)VICTUAL_USER_ID)));
	}

	public function UndoEvent(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->UndoConsumption(self::RecipeId($args), (int)$args['eventId'], (int)VICTUAL_USER_ID)));
	}

	public function ListShares(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->Run($request, $response, fn() => $this->ApiResponse($response, $this->Service()->ListShares(self::RecipeId($args), (int)VICTUAL_USER_ID)));
	}

	/** Adds a share for `user_id` or `username` in the body. */
	public function AddShare(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$body = $this->Body($request);

		return $this->Run($request, $response, function () use ($response, $args, $body)
		{
			if (isset($body['user_id']) && is_numeric($body['user_id']))
			{
				$target = (int)$body['user_id'];
			}
			elseif (isset($body['username']) && is_string($body['username']) && $body['username'] !== '')
			{
				$target = $body['username'];
			}
			else
			{
				throw new ConsumptionException(422, 'invalid_user', 'A user_id or a username is required');
			}

			$this->Service()->SetShare(self::RecipeId($args), $target, self::RightsFrom($body), (int)VICTUAL_USER_ID);

			return $this->EmptyApiResponse($response);
		});
	}

	public function SetShareRights(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$body = $this->Body($request);

		return $this->Run($request, $response, function () use ($response, $args, $body)
		{
			$this->Service()->SetShare(self::RecipeId($args), (int)$args['userId'], self::RightsFrom($body), (int)VICTUAL_USER_ID);

			return $this->EmptyApiResponse($response);
		});
	}

	public function RemoveShare(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->Run($request, $response, function () use ($response, $args)
		{
			$this->Service()->RemoveShare(self::RecipeId($args), (int)$args['userId'], (int)VICTUAL_USER_ID);

			return $this->EmptyApiResponse($response);
		});
	}

	public function TransferOwnership(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$body = $this->Body($request);

		return $this->Run($request, $response, function () use ($response, $args, $body)
		{
			if (!isset($body['user_id']) || !is_numeric($body['user_id']))
			{
				throw new ConsumptionException(422, 'invalid_user', 'A user_id is required');
			}

			$this->Service()->TransferOwnership(self::RecipeId($args), (int)$body['user_id'], (int)VICTUAL_USER_ID);

			return $this->EmptyApiResponse($response);
		});
	}
}
