<?php

namespace Victual\Controllers;

use Victual\Helpers\Grocycode;
use Victual\Services\ChoresService;
use Victual\Services\UserfieldsService;
use Victual\Services\UsersService;
use Victual\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for the chore views (overview, journal, master data
 * list/edit form, execution tracking and settings). Due date calculations are
 * delegated to ChoresService.
 */
class ChoresController extends BaseController
{
	use GrocycodeTrait;

	/**
	 * Serves the chore create/edit form (route GET /chore/{choreId}).
	 *
	 * @param array $args Route arguments; choreId is either a chore id or the literal 'new' for create mode
	 */
	public function ChoreEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		$usersService = UsersService::GetInstance();
		$users = $usersService->GetUsersAsDto();

		if ($args['choreId'] == 'new')
		{
			return $this->RenderPage($response, 'choreform', [
				'periodTypes' => GetClassConstants('\Victual\Services\ChoresService', 'CHORE_PERIOD_TYPE_'),
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
				'assignmentTypes' => GetClassConstants('\Victual\Services\ChoresService', 'CHORE_ASSIGNMENT_TYPE_'),
				'users' => $users,
				'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'choreform', [
				'chore' => $this->DB->chores($args['choreId']),
				'periodTypes' => GetClassConstants('\Victual\Services\ChoresService', 'CHORE_PERIOD_TYPE_'),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
				'assignmentTypes' => GetClassConstants('\Victual\Services\ChoresService', 'CHORE_ASSIGNMENT_TYPE_'),
				'users' => $users,
				'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE')
			]);
		}
	}

	/**
	 * Serves the chore master data list view (route GET /chores).
	 *
	 * Query parameter include_disabled (presence only) also lists inactive chores.
	 */
	public function ChoresList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$chores = $this->DB->chores()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$chores = $this->DB->chores()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'chores', [
			'chores' => $chores,
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('chores')
		]);
	}

	/**
	 * Serves the chores settings view (route GET /choressettings).
	 */
	public function ChoresSettings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		return $this->RenderPage($response, 'choressettings');
	}

	/**
	 * Serves the chore execution journal view (route GET /choresjournal).
	 *
	 * Optional query parameters: months (int, how far back to list; default 12)
	 * and chore (int, filter by chore id).
	 */
	public function Journal(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		// Default 1 year
		$months = 12;
		if (isset($request->getQueryParams()['months']) && filter_var($request->getQueryParams()['months'], FILTER_VALIDATE_INT) !== false)
		{
			$months = intval($request->getQueryParams()['months']);
		}

		// The cut-off date is computed here and bound as a parameter instead of being
		// expressed in SQL: SQLite's DATE(x, '-N months') has no PostgreSQL equivalent,
		// so date arithmetic must not leak into the query (see DatabaseDialect)
		$choresLog = $this->DB->chores_log()->where('tracked_time > :1', date('Y-m-d', strtotime('-' . $months . ' months')));

		if (isset($request->getQueryParams()['chore']) && filter_var($request->getQueryParams()['chore'], FILTER_VALIDATE_INT) !== false)
		{
			$choresLog = $choresLog->where('chore_id', intval($request->getQueryParams()['chore']));
		}

		return $this->RenderPage($response, 'choresjournal', [
			'choresLog' => $choresLog->orderBy('tracked_time', 'DESC'),
			'chores' => $this->DB->chores()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $this->DB->users()->orderBy('username'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores_log'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('chores_log')
		]);
	}

	/**
	 * Serves the chores overview view (route GET /choresoverview); flags each
	 * current chore as overdue/duetoday/duesoon based on its next estimated
	 * execution time and the user's "due soon" days setting.
	 */
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(VICTUAL_USER_ID)['chores_due_soon_days'];

		$chores = $this->DB->chores()->orderBy('name', 'COLLATE NOCASE');
		$currentChores = ChoresService::GetInstance()->GetCurrent();
		foreach ($currentChores as $currentChore)
		{
			if (!empty($currentChore->next_estimated_execution_time))
			{
				if ($currentChore->next_estimated_execution_time < date('Y-m-d H:i:s'))
				{
					$currentChore->due_type = 'overdue';
				}
				elseif ($currentChore->next_estimated_execution_time <= date('Y-m-d 23:59:59'))
				{
					$currentChore->due_type = 'duetoday';
				}
				elseif ($nextXDays > 0 && $currentChore->next_estimated_execution_time <= date('Y-m-d H:i:s', strtotime('+' . $nextXDays . ' days')))
				{
					$currentChore->due_type = 'duesoon';
				}
			}
		}

		return $this->RenderPage($response, 'choresoverview', [
			'chores' => $chores,
			'currentChores' => $currentChores,
			'nextXDays' => $nextXDays,
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('chores'),
			'users' => $usersService->GetUsersAsDto()
		]);
	}

	/**
	 * Serves the chore execution tracking view (route GET /choretracking).
	 */
	public function TrackChoreExecution(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		return $this->RenderPage($response, 'choretracking', [
			'chores' => $this->DB->chores()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $this->DB->users()->orderBy('username'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores_log'),
		]);
	}

	/**
	 * Serves the Grocycode barcode PNG for a chore (route GET /chore/{choreId}/grocycode).
	 */
	public function ChoreGrocycodeImage(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CHORES_VIEW);
		$gc = new Grocycode(Grocycode::CHORE, $args['choreId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}
}
