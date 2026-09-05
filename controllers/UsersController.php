<?php

namespace Victual\Controllers;

use Victual\Controllers\Users\User;
use Victual\Services\UserfieldsService;
use Victual\Services\RolesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for user management views (user list, user edit form,
 * per-user permission list) and the current user's settings page; guards the
 * management views with the USERS_* permissions.
 */
class UsersController extends BaseController
{
	/**
	 * Serves the permission list view for one user (route GET /user/{userId}/permissions).
	 * Requires the USERS_READ permission.
	 */
	public function PermissionList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		$direct = [];
		foreach ($this->DB->user_permissions()->where('user_id', $args['userId']) as $grant)
		{
			$direct[] = (int)$grant->permission_id;
		}
		$assigned = [];
		foreach (RolesService::GetInstance()->GetUserRoles((int)$args['userId']) as $role)
		{
			$assigned[] = (int)$role->id;
		}
		return $this->RenderPage($response, 'userpermissions', [
			'user' => $this->DB->users($args['userId']),
			'permissionRows' => $this->DB->uihelper_user_permissions()->where('user_id', $args['userId'])->fetchAll(),
			'directIds' => $direct,
			'roles' => RolesService::GetInstance()->GetRoles()->fetchAll(),
			'assignedIds' => $assigned,
			'canEdit' => User::HasPermissions(User::PERMISSION_USERS_EDIT) && User::MayAdminister((int)$args['userId'])
		]);
	}

	/**
	 * Serves the user create/edit form (route GET /user/{userId}).
	 * Requires USERS_CREATE for creating, USERS_EDIT_SELF when editing the own
	 * user and USERS_EDIT when editing others.
	 *
	 * @param array $args Route arguments; userId is either a user id or the literal 'new' for create mode
	 */
	public function UserEditForm(Request $request, Response $response, array $args)
	{
		if ($args['userId'] == 'new')
		{
			User::CheckPermission($request, User::PERMISSION_USERS_CREATE);
			return $this->RenderPage($response, 'userform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('users')
			]);
		}
		else
		{
			if ($args['userId'] == VICTUAL_USER_ID)
			{
				User::CheckPermission($request, User::PERMISSION_USERS_EDIT_SELF);
			}
			else
			{
				User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
			}

			return $this->RenderPage($response, 'userform', [
				'user' => $this->DB->users($args['userId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('users'),
				'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('users')
			]);
		}
	}

	/**
	 * Serves the current user's settings view (route GET /usersettings);
	 * available languages are derived from the localization folder.
	 */
	public function UserSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'usersettings', [
			'languages' => array_filter(scandir(__DIR__ . '/../localization'), function ($item)
			{
				if ($item == '.' || $item == '..')
				{
					return false;
				}

				return is_dir(__DIR__ . '/../localization/' . $item);
			})
		]);
	}

	/**
	 * Serves the user management list view (route GET /users).
	 * Requires the USERS_READ permission.
	 */
	public function UsersList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		return $this->RenderPage($response, 'users', [
			'users' => $this->DB->users()->orderBy('username'),
			'rolesService' => RolesService::GetInstance(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('users'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('users')
		]);
	}

	public function RolesList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		return $this->RenderPage($response, 'roles', [
			'roles' => RolesService::GetInstance()->GetRoles(),
			'canEdit' => User::HasPermissions(User::PERMISSION_USERS_EDIT)
		]);
	}

	public function RoleEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		$id = $args['roleId'];
		$role = $id === 'new' ? null : RolesService::GetInstance()->RequireRole((int)$id);
		$rows = [];
		foreach ($this->DB->permission_hierarchy() as $permission)
		{
			$rows[] = (object)['permission_id' => $permission->id, 'permission_name' => $permission->name,
				'parent' => $permission->parent, 'via_roles' => null];
		}
		return $this->RenderPage($response, 'roleform', [
			'role' => $role,
			'permissionRows' => $rows,
			'directIds' => $role === null ? [] : RolesService::GetInstance()->GetPermissionIds((int)$id),
			'canEdit' => User::HasPermissions(User::PERMISSION_USERS_EDIT)
		]);
	}
}
