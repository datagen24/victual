<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\UsersService;
use Victual\Services\RolesService;
use Victual\Services\DatabaseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/users endpoints (user management and permission assignment)
 * and the /api/user endpoints (currently authenticated user and its settings).
 */
class UsersApiController extends BaseApiController
{
	/**
	 * POST /api/users/{userId}/permissions - assigns the permission given by the body
	 * field permission_id to the user. Requires the USERS_EDIT permission (403 otherwise), that
	 * the caller may administer the target user, and that the caller holds everything the
	 * grant would confer. Returns 204 on success, 400 when permission_id names no
	 * permission, or a 400 error response.
	 */
	public function AddPermission(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			return RolesService::GetInstance()->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($args, $request, $response)
			{
				$requestBody = $this->GetParsedAndFilteredRequestBody($request);

				if (!isset($requestBody['permission_id']))
				{
					throw new EInvalidApiQuery('permission_id is required');
				}

				if ($this->DB->users($args['userId']) === null)
				{
					throw new EInvalidApiQuery('User does not exist');
				}
				User::CheckMayAdminister($request, (int)$args['userId']);
				User::CheckMayGrant($request, [$requestBody['permission_id']]);

				$this->DB->user_permissions()->createRow([
					'user_id' => $args['userId'],
					'permission_id' => $requestBody['permission_id']
				])->save();
				return $this->EmptyApiResponse($response);
			});
		});
	}

	/**
	 * POST /api/users - creates a new user from the body fields username, first_name,
	 * last_name, password (alternatively password_base64, which is decoded into
	 * password) and picture_file_name. Requires the USERS_CREATE permission (403
	 * otherwise). Returns 204 on success or a 400 error response.
	 */
	public function CreateUser(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_CREATE);

		// DEFAULT_PERMISSIONS is what the new account is given, so creating a user is a
		// grant and is bounded by what the creator holds. It used to be ['ADMIN'], which
		// meant an account holding only USERS_CREATE could create an administrator and log
		// in as it - a direct escalation past the permission model. Sweep finding S5.
		User::CheckMayGrant($request, UsersService::GetInstance()->GetDefaultPermissionIds());
		RolesService::GetInstance()->CheckMayAssign($request, RolesService::GetInstance()->GetDefaultRoleIds());

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($requestBody, $response, $request)
		{
			return RolesService::GetInstance()->Mutate($request, User::PERMISSION_USERS_CREATE, function () use ($requestBody, $response, $request)
			{
				User::CheckMayGrant($request, UsersService::GetInstance()->GetDefaultPermissionIds());
				RolesService::GetInstance()->CheckMayAssign($request, RolesService::GetInstance()->GetDefaultRoleIds());
				if ($requestBody === null)
				{
					throw new EInvalidApiQuery('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$requestBody = self::WithDecodedPassword($requestBody, 'password');

				UsersService::GetInstance()->CreateUser(
					self::RequiredField($requestBody, 'username'),
					$requestBody['first_name'] ?? null,
					$requestBody['last_name'] ?? null,
					self::RequiredField($requestBody, 'password'),
					$requestBody['picture_file_name'] ?? null
				);

				return $this->EmptyApiResponse($response);
			});
		});
	}

	/**
	 * DELETE /api/users/{userId} - deletes the given user.
	 * Requires the USERS_EDIT permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function DeleteUser(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		User::CheckMayAdminister($request, (int)$args['userId']);

		return $this->HandleApiCall($response, function () use ($args, $response, $request)
		{
			return RolesService::GetInstance()->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($args, $response, $request)
			{
				User::CheckMayAdminister($request, (int)$args['userId']);
				UsersService::GetInstance()->DeleteUser($args['userId']);
				return $this->EmptyApiResponse($response);
			});
		});
	}

	/**
	 * PUT /api/users/{userId} - updates the given user with the body fields username,
	 * first_name, last_name, password (alternatively password_base64) and
	 * picture_file_name. Requires USERS_EDIT_SELF when editing the own account,
	 * USERS_EDIT otherwise (403 when missing), and in the second case that the target
	 * holds nothing the caller does not.
	 *
	 * Changing one's own password additionally requires the current one, in the body field
	 * current_password (or current_password_base64). Sweep finding S6: without it, a
	 * borrowed session or an unlocked browser is enough to take an account over
	 * permanently, and the person it belongs to finds out when they cannot log in.
	 *
	 * Returns 204 on success or a 400 error response.
	 */
	public function EditUser(Request $request, Response $response, array $args)
	{
		$isSelf = $args['userId'] == VICTUAL_USER_ID;

		if ($isSelf)
		{
			User::CheckPermission($request, User::PERMISSION_USERS_EDIT_SELF);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
			// USERS_EDIT used to be enough to rewrite an administrator's password - and
			// USERS_CREATE resolves to USERS_EDIT, so creating users was enough too.
			// Sweep finding S6.
			User::CheckMayAdminister($request, (int)$args['userId']);
		}

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $isSelf, $requestBody, $response, $request)
		{
			return RolesService::GetInstance()->Mutate($request, ($isSelf ? User::PERMISSION_USERS_EDIT_SELF : User::PERMISSION_USERS_EDIT), function () use ($args, $isSelf, $requestBody, $response, $request)
			{
				if (!$isSelf) User::CheckMayAdminister($request, (int)$args['userId']);
				if ($requestBody === null)
				{
					throw new EInvalidApiQuery('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$requestBody = self::WithDecodedPassword($requestBody, 'password');
				$requestBody = self::WithDecodedPassword($requestBody, 'current_password');

				if ($isSelf && !empty($requestBody['password'] ?? null))
				{
					UsersService::GetInstance()->CheckCurrentPassword((int)$args['userId'], $requestBody['current_password'] ?? null);
				}

				UsersService::GetInstance()->EditUser(
					$args['userId'],
					self::RequiredField($requestBody, 'username'),
					$requestBody['first_name'] ?? null,
					$requestBody['last_name'] ?? null,
					$requestBody['password'] ?? null,
					$requestBody['picture_file_name'] ?? null
				);

				return $this->EmptyApiResponse($response);
			});
		});
	}

	/**
	 * The body with a "<field>_base64" variant decoded into "<field>" and removed.
	 *
	 * The base64 form exists because a password may contain characters a client finds
	 * awkward to send; it is not a secret-keeping measure and never was.
	 */
	private static function WithDecodedPassword(array $requestBody, string $field): array
	{
		if (isset($requestBody[$field . '_base64']))
		{
			$requestBody[$field] = base64_decode($requestBody[$field . '_base64']);
		}

		unset($requestBody[$field . '_base64']);

		return $requestBody;
	}

	/**
	 * A body field that has to be there, refused as a client error when it is not.
	 *
	 * These used to be read straight out of the array and passed to a typed service
	 * parameter, so an absent one was a TypeError - which is an \Error rather than an
	 * \Exception, so it escaped HandleApiCall() and every catch before it and answered
	 * 500 with PHP's own message naming the file and line. POST /api/users with an empty
	 * body was exactly that.
	 *
	 * @throws EInvalidApiQuery
	 */
	private static function RequiredField(array $requestBody, string $field): string
	{
		if (!isset($requestBody[$field]) || !is_string($requestBody[$field]) || trim($requestBody[$field]) === '')
		{
			throw new EInvalidApiQuery($field . ' is required');
		}

		return $requestBody[$field];
	}

	/**
	 * GET /api/user/settings/{settingKey} - returns { "value": mixed } for the given
	 * setting of the current user (200) or a 400 error response.
	 */
	public function GetUserSetting(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$value = UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, $args['settingKey']);
			return $this->ApiResponse($response, ['value' => $value]);
		});
	}

	/**
	 * GET /api/user/settings - returns all settings of the current user as a key/value
	 * map (200) or a 400 error response.
	 */
	public function GetUserSettings(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response)
		{
			return $this->ApiResponse($response, UsersService::GetInstance()->GetUserSettings(VICTUAL_USER_ID));
		});
	}

	/**
	 * GET /api/users - returns all users as DTOs (without password hashes), filterable
	 * via the generic query/limit/offset/order query parameters.
	 * Requires the USERS_READ permission (403 otherwise); 400 error response on failure.
	 */
	public function GetUsers(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			return $this->FilteredApiResponse($request, $response, UsersService::GetInstance()->GetUsersAsDto(), $request->getQueryParams());
		});
	}

	/**
	 * GET /api/user - returns the currently authenticated user as a single-element
	 * DTO list (200) or a 400 error response.
	 */
	public function CurrentUser(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response)
		{
			return $this->ApiResponse($response, UsersService::GetInstance()->GetUsersAsDto()->where('id', VICTUAL_USER_ID));
		});
	}

	/** Returns the hierarchy-joined effective permission model, including role sources. */
	public function ListPermissions(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);

		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			return $this->ApiResponse(
				$response,
				$this->DB->uihelper_user_permissions()->where('user_id', $args['userId'])->orderBy('permission_id')
			);
		});
	}

	/**
	 * PUT /api/users/{userId}/permissions - replaces all permission assignments of the
	 * given user with the body field permissions (array of permission ids).
	 * Requires the USERS_EDIT permission (403 otherwise). Returns 204 on success or a
	 * 400 error response.
	 */
	public function SetPermissions(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			return RolesService::GetInstance()->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($args, $request, $response)
			{
				$requested = RolesService::Ids($request->getParsedBody(), 'permissions');
				DatabaseService::GetInstance()->InTransaction(function () use ($request, $args, $requested)
				{
					if ($this->DB->users($args['userId']) === null)
					{
						throw new EInvalidApiQuery('User does not exist');
					}
					User::CheckMayAdminister($request, (int)$args['userId']);
					User::CheckMayGrant($request, $requested);
					$this->DB->user_permissions()->where('user_id', $args['userId'])->delete();
					foreach ($requested as $id)
					{
						$this->DB->user_permissions()->createRow(['user_id' => $args['userId'], 'permission_id' => $id])->save();
					}
				});

				return $this->EmptyApiResponse($response);
			});
		});
	}

	/**
	 * PUT /api/user/settings/{settingKey} - stores the body field "value" as the given
	 * setting of the current user. Returns 204 on success or a 400 error response.
	 */
	public function SetUserSetting(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$value = UsersService::GetInstance()->SetUserSetting(VICTUAL_USER_ID, $args['settingKey'], $requestBody['value']);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * DELETE /api/user/settings/{settingKey} - deletes the given setting of the current
	 * user. Returns 204 on success or a 400 error response.
	 */
	public function DeleteUserSetting(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$value = UsersService::GetInstance()->DeleteUserSetting(VICTUAL_USER_ID, $args['settingKey']);
			return $this->EmptyApiResponse($response);
		});
	}
}
