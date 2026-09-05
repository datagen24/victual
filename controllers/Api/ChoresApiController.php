<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Helpers\Grocycode;
use Victual\Helpers\WebhookRunner;
use Victual\Services\ChoresService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/chores endpoints: current chore overview, chore details,
 * execution tracking/undo, next assignment calculation, label printing and merging.
 */
class ChoresApiController extends BaseApiController
{
	/**
	 * POST /api/chores/executions/calculate-next-assignments - (re)calculates who is
	 * assigned to the next execution, either for the chore given via the numeric body
	 * field chore_id or for all chores when omitted.
	 *
	 * Requires the CHORES permission (403 otherwise). It had no check at all until wave 2,
	 * so any authenticated key could rewrite every chore's assignment. CHORES rather than
	 * something finer because it is the parent of CHORE_TRACK_EXECUTION in the permission
	 * hierarchy, so this excludes exactly one population - a user granted the leaf without
	 * its parent - and carves out no new tier. All four callers in the front end fire
	 * after a write the caller has just performed, so none of them is a render refresh
	 * that a viewer could reach. Plan 11, question 2.
	 *
	 * Returns 204 on success or a 400 error response.
	 */
	public function CalculateNextExecutionAssignments(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$choreId = null;
			if (array_key_exists('chore_id', $requestBody) && !empty($requestBody['chore_id']) && is_numeric($requestBody['chore_id']))
			{
				$choreId = $requestBody['chore_id'];
			}

			if ($choreId === null)
			{
				$chores = $this->DB->chores();
				foreach ($chores as $chore)
				{
					ChoresService::GetInstance()->CalculateNextExecutionAssignment($chore->id);
				}
			}
			else
			{
				ChoresService::GetInstance()->CalculateNextExecutionAssignment($choreId);
			}

			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * GET /api/chores/{choreId} - returns details of the given chore (200)
	 * or a 400 error response (e.g. when the chore does not exist).
	 */
	public function ChoreDetails(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			return $this->ApiResponse($response, ChoresService::GetInstance()->GetChoreDetails($args['choreId']));
		});
	}

	/**
	 * GET /api/chores - returns all chores with their current execution status,
	 * filterable via the generic query/limit/offset/order query parameters (200).
	 */
	public function Current(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		return $this->FilteredApiResponse($request, $response, ChoresService::GetInstance()->GetCurrent(), $request->getQueryParams());
	}

	/**
	 * POST /api/chores/{choreId}/execute - tracks an execution of the given chore.
	 * Optional body fields: tracked_time (ISO date or datetime, defaults to now),
	 * skipped (boolean, defaults to false), done_by (user id, defaults to the current user).
	 * Requires the CHORE_TRACK_EXECUTION permission (403 otherwise).
	 * Returns the created chores_log row (200) or a 400 error response.
	 */
	public function TrackChoreExecution(Request $request, Response $response, array $args)
	{
		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		User::CheckPermission($request, User::PERMISSION_CHORE_TRACK_EXECUTION);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			$trackedTime = date('Y-m-d H:i:s');
			if (array_key_exists('tracked_time', $requestBody) && (IsIsoDateTime($requestBody['tracked_time']) || IsIsoDate($requestBody['tracked_time'])))
			{
				$trackedTime = $requestBody['tracked_time'];
			}

			$skipped = false;
			if (array_key_exists('skipped', $requestBody) && filter_var($requestBody['skipped'], FILTER_VALIDATE_BOOLEAN) !== false)
			{
				$skipped = $requestBody['skipped'];
			}

			$doneBy = VICTUAL_USER_ID;
			if (array_key_exists('done_by', $requestBody) && !empty($requestBody['done_by']))
			{
				$doneBy = $requestBody['done_by'];
			}

			if ($doneBy != VICTUAL_USER_ID)
			{
				User::CheckPermission($request, User::PERMISSION_CHORE_TRACK_EXECUTION);
			}

			$choreExecutionId = ChoresService::GetInstance()->TrackChore($args['choreId'], $trackedTime, $doneBy, $skipped);
			return $this->ApiResponse($response, $this->DB->chores_log($choreExecutionId));
		});
	}

	/**
	 * POST /api/chores/executions/{executionId}/undo - undoes a tracked chore execution.
	 * Requires the CHORE_UNDO_EXECUTION permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function UndoChoreExecution(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORE_UNDO_EXECUTION);

		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$this->ApiResponse($response, ChoresService::GetInstance()->UndoChoreExecution($args['executionId']));
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * GET /api/chores/{choreId}/printlabel - assembles the label printer webhook payload
	 * (chore name, Grocycode, details plus VICTUAL_LABEL_PRINTER_PARAMS), runs the webhook
	 * server-side when VICTUAL_LABEL_PRINTER_RUN_SERVER is enabled and returns the payload (200)
	 * or a 400 error response.
	 */
	public function ChorePrintLabel(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$choreDetails = (object)ChoresService::GetInstance()->GetChoreDetails($args['choreId']);

			$webhookData = array_merge([
				'chore' => $choreDetails->chore->name,
				'grocycode' => (string)(new Grocycode(Grocycode::CHORE, $args['choreId'])),
				'details' => $choreDetails,
			], VICTUAL_LABEL_PRINTER_PARAMS);

			if (VICTUAL_LABEL_PRINTER_RUN_SERVER)
			{
				(new WebhookRunner())->run(VICTUAL_LABEL_PRINTER_WEBHOOK, $webhookData, VICTUAL_LABEL_PRINTER_HOOK_JSON);
			}

			return $this->ApiResponse($response, $webhookData);
		});
	}

	/**
	 * POST /api/chores/{choreIdToKeep}/merge/{choreIdToRemove} - merges two chores.
	 * Requires the MASTER_DATA_EDIT permission (403 otherwise).
	 * Returns 204 on success or a 400 error response (e.g. non-integer route arguments).
	 */
	public function MergeChores(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			if (filter_var($args['choreIdToKeep'], FILTER_VALIDATE_INT) === false || filter_var($args['choreIdToRemove'], FILTER_VALIDATE_INT) === false)
			{
				throw new \Exception('Provided {choreIdToKeep} or {choreIdToRemove} is not a valid integer');
			}

			$this->ApiResponse($response, ChoresService::GetInstance()->MergeChores($args['choreIdToKeep'], $args['choreIdToRemove']));
			return $this->EmptyApiResponse($response);
		});
	}
}
