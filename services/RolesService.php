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

	/**
	 * All roles ordered by code.
	 */
	public function GetRoles()
	{
		return $this->DB->roles()->orderBy('code');
	}

	/**
	 * The roles the current user may hand out: every role whose permissions the caller
	 * holds in full (the same CheckMayAssign() test SetUserRoles() applies), so a picker
	 * built from this list never offers a role that the save would refuse.
	 *
	 * @param \Psr\Http\Message\ServerRequestInterface $request The current request, for the refusal CheckMayAssign() raises
	 * @return object[] Role rows ordered by code
	 */
	public function GetAssignableRoles($request): array
	{
		$roles = [];
		foreach ($this->GetRoles() as $role)
		{
			try
			{
				$this->CheckMayAssign($request, [(int)$role->id]);
				$roles[] = $role;
			}
			catch (\Victual\Controllers\Users\PermissionMissingException $e)
			{
				// Not assignable by this caller: left out of the list on purpose.
			}
		}
		return $roles;
	}

	/**
	 * Loads a role by id.
	 * 
	 * @throws EInvalidApiQuery When no such role exists
	 */
	public function RequireRole(int $id)
	{
		$role = $this->DB->roles($id);
		if ($role === null)
		{
			throw new EInvalidApiQuery('Role does not exist');
		}
		return $role;
	}

	/**
	 * The roles assigned to the given user, ordered by code.
	 */
	public function GetUserRoles(int $userId)
	{
		return $this->DB->roles()->where('id IN (SELECT role_id FROM user_roles WHERE user_id = ?)', $userId)->orderBy('code');
	}

	/**
	 * The permission ids a role grants directly.
	 */
	public function GetPermissionIds(int $roleId): array
	{
		$ids = [];
		foreach ($this->DB->role_permissions()->where('role_id', $roleId) as $row)
		{
			$ids[] = (int)$row->permission_id;
		}
		return $ids;
	}

	/**
	 * The ids of the roles named in VICTUAL_DEFAULT_ROLES, which CreateUser assigns to a new user.
	 * 
	 * @throws EInvalidApiQuery When a configured code names no role
	 */
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

	/**
	 * Reads a list of positive integer ids from a request body key.
	 * 
	 * @throws EInvalidApiQuery When the key is missing or is not a list of positive integers
	 */
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

	/**
	 * Asserts that the caller holds everything the given roles confer.
	 */
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

	/**
	 * Replaces the roles of a user with the given ones; needs USERS_EDIT and is bounded by what the caller may administer and assign.
	 */
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

	/**
	 * Replaces the permissions of a role with the given ones; needs USERS_EDIT and is bounded by what the caller may grant.
	 */
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
