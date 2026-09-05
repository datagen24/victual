<?php

namespace Victual\Controllers\Users;

use Victual\Services\DatabaseService;
use LessQL\Result;

/**
 * Permission helper for the currently authenticated user (VICTUAL_USER_ID):
 * defines all known permission names as PERMISSION_* constants and offers
 * static checks against the resolved permissions in the database.
 */
class User
{
	const PERMISSION_ADMIN = 'ADMIN';
	const PERMISSION_STOCK_VIEW = 'STOCK_VIEW';
	const PERMISSION_SHOPPINGLIST_VIEW = 'SHOPPINGLIST_VIEW';
	const PERMISSION_CHORES_VIEW = 'CHORES_VIEW';
	const PERMISSION_TASKS_VIEW = 'TASKS_VIEW';
	const PERMISSION_RECIPES_VIEW = 'RECIPES_VIEW';
	const PERMISSION_MEALPLAN_VIEW = 'MEALPLAN_VIEW';

	const PERMISSION_BATTERIES = 'BATTERIES';
	const PERMISSION_BATTERIES_TRACK_CHARGE_CYCLE = 'BATTERIES_TRACK_CHARGE_CYCLE';
	const PERMISSION_BATTERIES_UNDO_CHARGE_CYCLE = 'BATTERIES_UNDO_CHARGE_CYCLE';
	const PERMISSION_CALENDAR = 'CALENDAR';
	const PERMISSION_CHORES = 'CHORES';
	const PERMISSION_CHORE_TRACK_EXECUTION = 'CHORE_TRACK_EXECUTION';
	const PERMISSION_CHORE_UNDO_EXECUTION = 'CHORE_UNDO_EXECUTION';
	const PERMISSION_EQUIPMENT = 'EQUIPMENT';
	const PERMISSION_MASTER_DATA_EDIT = 'MASTER_DATA_EDIT';
	const PERMISSION_RECIPES = 'RECIPES';
	const PERMISSION_RECIPES_MEALPLAN = 'RECIPES_MEALPLAN';
	const PERMISSION_SHOPPINGLIST = 'SHOPPINGLIST';
	const PERMISSION_SHOPPINGLIST_ITEMS_ADD = 'SHOPPINGLIST_ITEMS_ADD';
	const PERMISSION_SHOPPINGLIST_ITEMS_DELETE = 'SHOPPINGLIST_ITEMS_DELETE';
	const PERMISSION_STOCK = 'STOCK';
	const PERMISSION_STOCK_CONSUME = 'STOCK_CONSUME';
	const PERMISSION_STOCK_EDIT = 'STOCK_EDIT';
	const PERMISSION_STOCK_INVENTORY = 'STOCK_INVENTORY';
	const PERMISSION_STOCK_OPEN = 'STOCK_OPEN';
	const PERMISSION_STOCK_PURCHASE = 'STOCK_PURCHASE';
	const PERMISSION_STOCK_TRANSFER = 'STOCK_TRANSFER';
	const PERMISSION_TASKS = 'TASKS';
	const PERMISSION_TASKS_MARK_COMPLETED = 'TASKS_MARK_COMPLETED';
	const PERMISSION_TASKS_UNDO_EXECUTION = 'TASKS_UNDO_EXECUTION';
	const PERMISSION_USERS = 'USERS';
	const PERMISSION_USERS_CREATE = 'USERS_CREATE';
	const PERMISSION_USERS_EDIT = 'USERS_EDIT';
	const PERMISSION_USERS_EDIT_SELF = 'USERS_EDIT_SELF';
	const PERMISSION_USERS_READ = 'USERS_READ';

	/**
	 * Grabs the shared database connection.
	 */
	public function __construct()
	{
		$this->DB = DatabaseService::GetInstance()->GetDbConnection();
	}

	/** @var \LessQL\Database Fluent database connection */
	protected $DB;

	/**
	 * Static convenience wrapper around GetPermissionList().
	 *
	 * @return Result The current user's rows from the uihelper_user_permissions view
	 */
	public static function PermissionList()
	{
		$user = new self();
		return $user->GetPermissionList();
	}

	/**
	 * Asserts that the current user has the given permission.
	 *
	 * @param \Psr\Http\Message\ServerRequestInterface $request The current request (needed to construct the exception)
	 * @param string $permission One of the PERMISSION_* constants
	 * @throws PermissionMissingException When the permission is not granted
	 */
	public static function CheckPermission($request, string $permission): void
	{
		$user = new self();
		if (!$user->HasPermission($permission))
		{
			throw new PermissionMissingException($request, $permission);
		}
	}

	/**
	 * Returns the current user's permission rows from the uihelper_user_permissions
	 * view (used e.g. to expose permissions to the templates).
	 *
	 * @return Result
	 */
	public function GetPermissionList()
	{
		return $this->DB->uihelper_user_permissions()->where('user_id', VICTUAL_USER_ID);
	}

	/**
	 * Whether the current user has the given permission (resolved, i.e. including
	 * permissions inherited via ADMIN/parent permissions).
	 *
	 * @param string $permission One of the PERMISSION_* constants
	 */
	public function HasPermission(string $permission): bool
	{
		return $this->GetPermissions()->where('permission_name', $permission)->fetch() !== null;
	}

	/**
	 * Whether the current user has ALL of the given permissions.
	 *
	 * @param string ...$permissions PERMISSION_* constants
	 * @return bool
	 */
	public static function HasPermissions(string ...$permissions)
	{
		$user = new self();

		foreach ($permissions as $permission)
		{
			if (!$user->HasPermission($permission))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The resolved permission names held by the given user.
	 *
	 * Resolved, so a parent permission is counted along with everything it implies -
	 * USERS_CREATE resolves to USERS_EDIT and USERS_READ, and ADMIN to all thirty. That
	 * is what makes a set comparison over these the right question to ask about who may
	 * administer whom.
	 *
	 * @return string[]
	 */
	public static function ResolvedPermissionNames(int $userId): array
	{
		$user = new self();
		$names = [];

		foreach ($user->DB->user_permissions_resolved()->where('user_id', $userId) as $row)
		{
			$names[] = $row->permission_name;
		}

		return array_values(array_unique($names));
	}

	/**
	 * Whether the current user may administer the given one: edit them, delete them,
	 * change what they hold.
	 *
	 * The rule is that the target's resolved permissions are a subset of the caller's.
	 * Without it, USERS_EDIT let anybody who had it rewrite any administrator's password
	 * and log in as them - and it did not even need USERS_EDIT, because USERS_CREATE
	 * resolves to it (sweep finding S6). "May A administer B" has an answer today, from
	 * two views that already exist, and it is this one; it does not wait on the role model
	 * plan 19 designs, which widens those views without changing what a comparison over
	 * them means.
	 *
	 * A user holding nothing is administrable by anyone with the permission to act, since
	 * the empty set is a subset of everything. That is the intended reading.
	 */
	public static function MayAdminister(int $targetUserId): bool
	{
		if ($targetUserId === (int)VICTUAL_USER_ID)
		{
			return true;
		}

		$targetPermissions = self::ResolvedPermissionNames($targetUserId);
		$callerPermissions = self::ResolvedPermissionNames((int)VICTUAL_USER_ID);

		return empty(array_diff($targetPermissions, $callerPermissions));
	}

	/**
	 * Asserts that the current user may administer the given one.
	 *
	 * @throws PermissionMissingException When they may not
	 */
	public static function CheckMayAdminister($request, int $targetUserId): void
	{
		if (!self::MayAdminister($targetUserId))
		{
			throw new PermissionMissingException($request, 'a permission the target user holds');
		}
	}

	/**
	 * The permission names that granting the given permission ids would actually confer,
	 * following the hierarchy - so granting USERS_CREATE confers USERS_EDIT and
	 * USERS_READ too.
	 *
	 * @param int[] $permissionIds
	 * @return string[]
	 */
	public static function PermissionNamesGrantedBy(array $permissionIds): array
	{
		if (empty($permissionIds))
		{
			return [];
		}

		$user = new self();
		$names = [];

		foreach ($user->DB->permission_tree()->where('id', $permissionIds) as $row)
		{
			$names[] = $row->name;
		}

		return array_values(array_unique($names));
	}

	/**
	 * Asserts that every given id is a real permission, and that the current user holds
	 * everything granting them would confer.
	 *
	 * The existence half is sweep finding S27: the permissions endpoints took an id
	 * straight from the request body into an insert, so an id naming no permission was
	 * stored and silently granted nothing - a grant that looks like it worked and did not.
	 * The subset half is S5's second sentence, "never grant a permission the creating user
	 * lacks", and it is computed over the closure rather than over the ids so that granting
	 * a parent cannot smuggle in a child the caller does not hold.
	 *
	 * @param int[] $permissionIds
	 * @throws \Victual\Controllers\Api\EInvalidApiQuery When an id names no permission
	 * @throws PermissionMissingException When the caller does not hold what it would confer
	 */
	public static function CheckMayGrant($request, array $permissionIds): void
	{
		$user = new self();
		$known = [];

		foreach ($user->DB->permission_hierarchy() as $row)
		{
			$known[(string)$row->id] = true;
		}

		foreach ($permissionIds as $permissionId)
		{
			if (!array_key_exists((string)$permissionId, $known))
			{
				throw new \Victual\Controllers\Api\EInvalidApiQuery('No permission with id "' . $permissionId . '" exists');
			}
		}

		$wouldConfer = self::PermissionNamesGrantedBy($permissionIds);
		$callerHolds = self::ResolvedPermissionNames((int)VICTUAL_USER_ID);
		$missing = array_diff($wouldConfer, $callerHolds);

		if (!empty($missing))
		{
			throw new PermissionMissingException($request, implode(', ', $missing));
		}
	}

	/**
	 * Returns the current user's resolved permissions (user_permissions_resolved view).
	 */
	protected function GetPermissions(): Result
	{
		return $this->DB->user_permissions_resolved()->where('user_id', VICTUAL_USER_ID);
	}
}
