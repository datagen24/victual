<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\TasksService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/tasks endpoints: current task list, completing and undoing tasks.
 */
class TasksApiController extends BaseApiController
{
	/**
	 * GET /api/tasks - returns the current tasks (TasksService::GetCurrent), filterable
	 * via the generic query/limit/offset/order query parameters (200).
	 */
	public function Current(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_VIEW);
		return $this->FilteredApiResponse($request, $response, TasksService::GetInstance()->GetCurrent(), $request->getQueryParams());
	}

	/**
	 * POST /api/tasks/{taskId}/complete - marks the given task as completed.
	 * The optional body field done_time (ISO datetime) defaults to now.
	 * Requires the TASKS_MARK_COMPLETED permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function MarkTaskAsCompleted(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_MARK_COMPLETED);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $requestBody, $response)
		{
			$doneTime = date('Y-m-d H:i:s');

			if (array_key_exists('done_time', $requestBody) && IsIsoDateTime($requestBody['done_time']))
			{
				$doneTime = $requestBody['done_time'];
			}

			TasksService::GetInstance()->MarkTaskAsCompleted($args['taskId'], $doneTime);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/tasks/{taskId}/undo - marks the given task as not completed again.
	 * Requires the TASKS_UNDO_EXECUTION permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function UndoTask(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_UNDO_EXECUTION);

		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			TasksService::GetInstance()->UndoTask($args['taskId']);
			return $this->EmptyApiResponse($response);
		});
	}
}
