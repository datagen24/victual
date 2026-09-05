<?php

namespace Victual\Controllers;

use Victual\Services\TasksService;
use Victual\Services\UserfieldsService;
use Victual\Services\UsersService;
use Victual\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for the task views (task list, task/category edit
 * forms, category list and settings). Current task retrieval is delegated
 * to TasksService.
 */
class TasksController extends BaseController
{
	/**
	 * Serves the tasks list view (route GET /tasks); flags each task as
	 * overdue/duetoday/duesoon based on its due date and the user's
	 * "due soon" days setting.
	 *
	 * Query parameter include_done (presence only) also lists completed tasks.
	 */
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_VIEW);
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(VICTUAL_USER_ID)['tasks_due_soon_days'];

		if (isset($request->getQueryParams()['include_done']))
		{
			$tasks = $this->DB->tasks()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$tasks = TasksService::GetInstance()->GetCurrent();
		}

		foreach ($tasks as $task)
		{
			if (empty($task->due_date))
			{
				$task->due_type = '';
			}
			elseif ($task->due_date < date('Y-m-d 23:59:59', strtotime('-1 days')))
			{
				$task->due_type = 'overdue';
			}
			elseif ($task->due_date <= date('Y-m-d 23:59:59'))
			{
				$task->due_type = 'duetoday';
			}
			elseif ($nextXDays > 0 && $task->due_date <= date('Y-m-d 23:59:59', strtotime('+' . $nextXDays . ' days')))
			{
				$task->due_type = 'duesoon';
			}
		}

		return $this->RenderPage($response, 'tasks', [
			'tasks' => $tasks,
			'nextXDays' => $nextXDays,
			'taskCategories' => $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('tasks'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('tasks')
		]);
	}

	/**
	 * Serves the task category master data list view (route GET /taskcategories).
	 *
	 * Query parameter include_disabled (presence only) also lists inactive categories.
	 */
	public function TaskCategoriesList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_VIEW);
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$categories = $this->DB->task_categories()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$categories = $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'taskcategories', [
			'taskCategories' => $categories,
			'userfields' => UserfieldsService::GetInstance()->GetFields('task_categories'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('task_categories')
		]);
	}

	/**
	 * Serves the task category create/edit form (route GET /taskcategory/{categoryId}).
	 *
	 * @param array $args Route arguments; categoryId is either a category id or the literal 'new' for create mode
	 */
	public function TaskCategoryEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_VIEW);
		if ($args['categoryId'] == 'new')
		{
			return $this->RenderPage($response, 'taskcategoryform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('task_categories')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'taskcategoryform', [
				'category' => $this->DB->task_categories($args['categoryId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('task_categories')
			]);
		}
	}

	/**
	 * Serves the task create/edit form (route GET /task/{taskId}).
	 *
	 * @param array $args Route arguments; taskId is either a task id or the literal 'new' for create mode
	 */
	public function TaskEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_VIEW);
		if ($args['taskId'] == 'new')
		{
			return $this->RenderPage($response, 'taskform', [
				'mode' => 'create',
				'taskCategories' => $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'users' => $this->DB->users()->orderBy('username'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('tasks')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'taskform', [
				'task' => $this->DB->tasks($args['taskId']),
				'mode' => 'edit',
				'taskCategories' => $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'users' => $this->DB->users()->orderBy('username'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('tasks')
			]);
		}
	}

	/**
	 * Serves the tasks settings view (route GET /taskssettings).
	 */
	public function TasksSettings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_VIEW);
		return $this->RenderPage($response, 'taskssettings');
	}
}
