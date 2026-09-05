<?php

namespace Victual\Services;

use LessQL\Result;

/**
 * User account management and per-user settings. Settings are cached per request; the
 * cache is shared with the SQL-side victual_user_setting() helper on SQLite, which resolves
 * settings through GetUserSetting().
 */
class UsersService extends BaseService
{
	/**
	 * The permission ids VICTUAL_DEFAULT_PERMISSIONS names.
	 *
	 * Read as ids rather than names because that is what a grant is checked against - see
	 * User::CheckMayGrant(), which resolves them through the hierarchy. A configured name
	 * that matches no permission simply contributes nothing, which is the same thing it
	 * did before this method existed.
	 *
	 * @return int[]
	 */
	public function GetDefaultPermissionIds(): array
	{
		$ids = [];

		foreach ($this->DB->permission_hierarchy()->where('name', VICTUAL_DEFAULT_PERMISSIONS)->fetchAll() as $perm)
		{
			$ids[] = (int)$perm->id;
		}

		return $ids;
	}

	/**
	 * Verifies the given plaintext password against the user's stored hash.
	 *
	 * @throws \Victual\Controllers\Users\PermissionMissingException Never - see below
	 * @throws \Exception When the password does not match, so that the answer is the same
	 *                    400 as any other refused edit rather than a 403, which would say
	 *                    "you are allowed, but" about a credential check
	 */
	public function CheckCurrentPassword(int $userId, ?string $currentPassword): void
	{
		$user = $this->DB->users($userId);

		if ($user === null)
		{
			throw new \Exception('User does not exist');
		}

		if ($currentPassword === null || $currentPassword === '' || !password_verify($currentPassword, $user->password))
		{
			throw new \Exception('The current password is required to change the password, and did not match');
		}
	}

	/**
	 * Creates a user (password hashed with Argon2id) and grants the permission set
	 * configured as VICTUAL_DEFAULT_PERMISSIONS.
	 *
	 * That set is empty by default. It used to be ['ADMIN'], which made every user this
	 * method creates an administrator - including the ones the reverse proxy backend
	 * creates on first sight of a username, where there is no creator to bound the grant
	 * against at all. Sweep finding S5. Callers who do have a creator check the grant
	 * against them first (User::CheckMayGrant).
	 *
	 * @return \LessQL\Row The new users row
	 */
	public function CreateUser(string $username, ?string $firstName, ?string $lastName, string $password, ?string $pictureFileName = null)
	{
		return DatabaseService::GetInstance()->InTransaction(function () use ($username, $firstName, $lastName, $password, $pictureFileName)
		{
			$newUserRow = $this->DB->users()->createRow([
				'username' => $username,
				'first_name' => $firstName,
				'last_name' => $lastName,
				'password' => password_hash($password, PASSWORD_ARGON2ID),
				'picture_file_name' => $pictureFileName
			]);
			$newUserRow = $newUserRow->save();
			$permList = [];

			foreach ($this->GetDefaultPermissionIds() as $permissionId)
			{
				$permList[] = [
					'user_id' => $newUserRow->id,
					'permission_id' => $permissionId
				];
			}

			if (!empty($permList))
			{
				$this->DB->user_permissions()->insert($permList);
			}

			foreach (RolesService::GetInstance()->GetDefaultRoleIds() as $roleId)
			{
				$this->DB->user_roles()->insert(['user_id' => $newUserRow->id, 'role_id' => $roleId]);
			}

			return $newUserRow;
		});
	}

	/**
	 * The password migration 0027 gives the account it creates.
	 */
	const SEEDED_DEFAULT_PASSWORD = 'admin';

	/**
	 * Records whether the password just used to log in is the seeded default, so that
	 * MustChangePassword() can answer without hashing anything.
	 *
	 * A stored flag rather than a check, because checking means running password_verify()
	 * against the seeded password on every request - an Argon2id verification, which is
	 * expensive by design. Only the login path ever sees a plaintext password, so that is
	 * where the question is answered.
	 *
	 * It is a column on `users` (migration 0265) and not a user setting. It was a setting
	 * until review of PR #68 pointed out what that means: a setting is a bag its owner can
	 * empty, and `DELETE /api/user/settings/must_change_password` lifted the restriction
	 * without changing any password. Authentication state does not go somewhere its subject
	 * can reach.
	 *
	 * Written only when the answer changes, so an ordinary login is still a read.
	 */
	public function RecordPasswordUsedAtLogin(int $userId, string $plaintextPassword): void
	{
		$mustChange = ($plaintextPassword === self::SEEDED_DEFAULT_PASSWORD) ? 1 : 0;
		$user = $this->DB->users($userId);

		if ($user !== null && (int)$user->must_change_password !== $mustChange)
		{
			$user->update(['must_change_password' => $mustChange]);
		}
	}

	/**
	 * Whether this account is still on the seeded admin/admin password and should be
	 * sent to change it before it is allowed to do anything else.
	 */
	public function MustChangePassword($userId): bool
	{
		$user = $this->DB->users($userId);

		return $user !== null && (int)$user->must_change_password === 1;
	}

	/**
	 * Deletes the users row (only that row - related rows like sessions or
	 * permissions are not cleaned up here).
	 */
	public function DeleteUser($userId)
	{
		$row = $this->DB->users($userId);
		$row->delete();
	}

	/**
	 * Updates a user's profile; the password is only changed (re-hashed with Argon2id)
	 * when a non-empty one is given.
	 *
	 * @throws \Exception When the user does not exist
	 */
	public function EditUser(int $userId, string $username, ?string $firstName, ?string $lastName, ?string $password, ?string $pictureFileName = null)
	{
		if (!$this->UserExists($userId))
		{
			throw new \Exception('User does not exist');
		}

		$user = $this->DB->users($userId);

		if ($password == null || empty($password))
		{
			$user->update([
				'username' => $username,
				'first_name' => $firstName,
				'last_name' => $lastName,
				'picture_file_name' => $pictureFileName
			]);
		}
		else
		{
			$user->update([
				'username' => $username,
				'first_name' => $firstName,
				'last_name' => $lastName,
				'password' => password_hash($password, PASSWORD_ARGON2ID),
				'picture_file_name' => $pictureFileName,
				// Whatever it is now, it is not the seeded default any more - unless somebody
				// deliberately set it back to that, which the next login will notice. Written
				// in the same update as the password so the two cannot come apart.
				'must_change_password' => 0
			]);
		}
	}

	/** @var array<int|string, array<string, mixed>> Per-request settings cache: [user id => [key => value]] */
	private static $UserSettingsCache = [];

	/**
	 * One setting value for the user, falling back to the $VICTUAL_DEFAULT_USER_SETTINGS
	 * default and finally null. Cached per request.
	 *
	 * @param int $userId
	 * @param string $settingKey
	 * @return mixed
	 */
	public function GetUserSetting($userId, $settingKey)
	{
		if (!array_key_exists($userId, self::$UserSettingsCache))
		{
			self::$UserSettingsCache[$userId] = [];
		}

		if (array_key_exists($settingKey, self::$UserSettingsCache[$userId]))
		{
			return self::$UserSettingsCache[$userId][$settingKey];
		}

		$value = null;
		$settingRow = $this->DB->user_settings()->where('user_id = :1 AND key = :2', $userId, $settingKey)->fetch();
		if ($settingRow !== null)
		{
			$value = $settingRow->value;
		}
		else
		{
			// Use the configured default values for a missing setting, otherwise return NULL
			global $VICTUAL_DEFAULT_USER_SETTINGS;
			if (array_key_exists($settingKey, $VICTUAL_DEFAULT_USER_SETTINGS))
			{
				$value = $VICTUAL_DEFAULT_USER_SETTINGS[$settingKey];
			}
		}

		self::$UserSettingsCache[$userId][$settingKey] = $value;
		return $value;
	}

	/**
	 * All settings of the user as [key => value], with $VICTUAL_DEFAULT_USER_SETTINGS
	 * filling in every key the user has not overridden.
	 *
	 * @param int $userId
	 * @return array<string, mixed>
	 */
	public function GetUserSettings($userId)
	{
		$settings = [];
		$settingRows = $this->DB->user_settings()->where('user_id = :1', $userId)->fetchAll();
		foreach ($settingRows as $settingRow)
		{
			$settings[$settingRow->key] = $settingRow->value;
		}

		// Use the configured default values for all missing settings
		global $VICTUAL_DEFAULT_USER_SETTINGS;
		return array_merge($VICTUAL_DEFAULT_USER_SETTINGS, $settings);
	}

	/**
	 * All users via the users_dto view, i.e. without password hashes and with
	 * display_name precomputed.
	 */
	public function GetUsersAsDto(): Result
	{
		return $this->DB->users_dto();
	}

	/**
	 * Inserts or updates one setting for the user and refreshes the request cache.
	 */
	public function SetUserSetting($userId, $settingKey, $settingValue)
	{
		if (!array_key_exists($userId, self::$UserSettingsCache))
		{
			self::$UserSettingsCache[$userId] = [];
		}
		self::$UserSettingsCache[$userId][$settingKey] = $settingValue;

		$settingRow = $this->DB->user_settings()->where('user_id = :1 AND key = :2', $userId, $settingKey)->fetch();
		if ($settingRow !== null)
		{
			$settingRow->update([
				'value' => $settingValue,
				'row_updated_timestamp' => date('Y-m-d H:i:s')
			]);
		}
		else
		{
			$settingRow = $this->DB->user_settings()->createRow([
				'user_id' => $userId,
				'key' => $settingKey,
				'value' => $settingValue
			]);
			$settingRow->save();
		}
	}

	/**
	 * Removes one stored setting for the user (reverting it to the configured default)
	 * and evicts it from the request cache.
	 */
	public function DeleteUserSetting($userId, $settingKey)
	{
		if (!array_key_exists($userId, self::$UserSettingsCache))
		{
			self::$UserSettingsCache[$userId] = [];
		}
		unset(self::$UserSettingsCache[$userId][$settingKey]);

		$this->DB->user_settings()->where('user_id = :1 AND key = :2', $userId, $settingKey)->delete();
	}

	private function UserExists($userId)
	{
		$userRow = $this->DB->users()->where('id = :1', $userId)->fetch();
		return $userRow !== null;
	}
}
