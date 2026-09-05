<?php

namespace Victual\Services;

use Victual\Controllers\Api\EInvalidApiQuery;
use Victual\Controllers\Users\User;

/** Named permission bundles. Grant checks always use the full resolved set. */
class RolesService extends BaseService
{
	/** Serialize authorization checks with all writes that can change their answer. */
	public function Mutate($request, string $permission, callable $work)
	{
		return DatabaseService::GetInstance()->InTransaction(function () use ($request, $permission, $work)
		{
			DatabaseService::GetInstance()->GetDbConnectionRaw()->exec(
				'LOCK TABLE user_permissions, user_roles, role_permissions IN SHARE ROW EXCLUSIVE MODE');
			User::CheckPermission($request, $permission);
			return $work();
		});
	}

	public function GetRoles()
	{
		return $this->DB->roles()->orderBy('code');
	}

	public function RequireRole(int $id)
	{
		$role = $this->DB->roles($id);
		if ($role === null)
		{
			throw new EInvalidApiQuery('Role does not exist');
		}
		return $role;
	}

	public function GetUserRoles(int $userId)
	{
		return $this->DB->roles()->where('id IN (SELECT role_id FROM user_roles WHERE user_id = ?)', $userId)->orderBy('code');
	}

	public function GetPermissionIds(int $roleId): array
	{
		$ids = [];
		foreach ($this->DB->role_permissions()->where('role_id', $roleId) as $row)
		{
			$ids[] = (int)$row->permission_id;
		}
		return $ids;
	}

	public function GetDefaultRoleIds(): array
	{
		$ids = [];
		foreach (VICTUAL_DEFAULT_ROLES as $code)
		{
			$role = $this->DB->roles()->where('code', $code)->fetch();
			if ($role === null)
			{
				throw new EInvalidApiQuery('Unknown default role code');
			}
			$ids[] = (int)$role->id;
		}
		return array_values(array_unique($ids));
	}

	public static function Ids($body, string $key): array
	{
		if (!is_array($body) || !isset($body[$key]) || !is_array($body[$key]) || !array_is_list($body[$key]))
		{
			throw new EInvalidApiQuery($key . ' must be an array of integer ids');
		}
		foreach ($body[$key] as $id)
		{
			if (!is_int($id) || $id < 1)
			{
				throw new EInvalidApiQuery($key . ' must contain positive integer ids');
			}
		}
		return array_values(array_unique($body[$key]));
	}

	public function CheckMayAssign($request, array $ids): void
	{
		foreach ($ids as $id)
		{
			$this->RequireRole($id);
			User::CheckMayGrant($request, $this->GetPermissionIds($id));
		}
	}

	/** Editing or deleting a bundle administers all of its current holders. */
	public function CheckMayManage($request, int $id): void
	{
		$this->RequireRole($id);
		User::CheckMayGrant($request, $this->GetPermissionIds($id));
		foreach ($this->DB->user_roles()->where('role_id', $id) as $holder)
		{
			User::CheckMayAdminister($request, (int)$holder->user_id);
		}
	}

	public function SetUserRoles($request, int $userId, array $ids): void
	{
		$this->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($request, $userId, $ids)
		{
			if ($this->DB->users($userId) === null)
			{
				throw new EInvalidApiQuery('User does not exist');
			}
			User::CheckMayAdminister($request, $userId);
			$this->CheckMayAssign($request, $ids);
			$this->DB->user_roles()->where('user_id', $userId)->delete();
			foreach ($ids as $id)
			{
				$this->DB->user_roles()->insert(['user_id' => $userId, 'role_id' => $id]);
			}
		});
	}

	public function SetPermissions($request, int $id, array $ids): void
	{
		$this->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($request, $id, $ids)
		{
			$this->CheckMayManage($request, $id);
			User::CheckMayGrant($request, $ids);
			$this->DB->role_permissions()->where('role_id', $id)->delete();
			foreach ($ids as $permissionId)
			{
				$this->DB->role_permissions()->insert(['role_id' => $id, 'permission_id' => $permissionId]);
			}
		});
	}
}
