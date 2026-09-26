<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\UsersService;
use Victual\Services\RolesService;
use Victual\Services\DatabaseService;
use Victual\Services\SessionService;
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
	 * holds nothing the caller does not. ForcedRotationOnly() below is the one exception:
	 * a flagged account resolving its own forced change never holds USERS_EDIT_SELF - that
	 * is what the flag means - so this route cannot wait for it.
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
		$targetUserId = (int)$args['userId'];
		$forcedRotationOnly = $isSelf && self::ForcedRotationOnly($targetUserId);

		if ($isSelf)
		{
			if (!$forcedRotationOnly)
			{
				User::CheckPermission($request, User::PERMISSION_USERS_EDIT_SELF);
			}
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
			// USERS_EDIT used to be enough to rewrite an administrator's password - and
			// USERS_CREATE resolves to USERS_EDIT, so creating users was enough too.
			// Sweep finding S6.
			User::CheckMayAdminister($request, $targetUserId);
		}

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		// The session, if any, that authenticated this very request - read directly off the
		// cookie rather than trusting anything computed earlier, since DefaultAuthMiddleware
		// only ever authenticates a request by session cookie when that cookie names a live
		// session, so its presence here already means it was this request's own credential.
		// Passed through to UsersService::EditUser() so a password change can keep this one
		// session alive while revoking every other (issue #513).
		$actingSessionKey = $request->getCookieParams()[SessionService::SESSION_COOKIE_NAME] ?? null;

		return $this->HandleApiCall($response, function () use ($isSelf, $forcedRotationOnly, $requestBody, $response, $request, $targetUserId, $actingSessionKey)
		{
			if ($requestBody === null)
			{
				throw new EInvalidApiQuery('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			$requestBody = self::WithDecodedPassword($requestBody, 'password');
			$requestBody = self::WithDecodedPassword($requestBody, 'current_password');

			// An account that has to change its password reaches this route through
			// BaseAuthMiddleware's allowlist for that purpose alone. So it must actually change
			// it: otherwise the one route left open is a way to rename the account - "admin"
			// to something the operator does not know - without the change it exists for.
			// And to something else: re-saving the printed or default password would clear
			// the flag and leave that password in place. Found by CodeRabbit on PR #213.
			if ($isSelf && UsersService::GetInstance()->MustChangePassword($targetUserId))
			{
				if (empty($requestBody['password'] ?? null))
				{
					throw new EInvalidApiQuery('This account must change its password: send the new password and current_password');
				}

				if ($requestBody['password'] === ($requestBody['current_password'] ?? null))
				{
					throw new EInvalidApiQuery('The new password must differ from the current one');
				}
			}

			// Deliberately ahead of the transaction below, and not inside it: on the
			// forced-rotation bypass a wrong guess here is throttled the same way a wrong
			// login password is (LoginThrottleService), and that record has to survive even
			// though this request goes on to be refused and everything the transaction would
			// have written is rolled back with it. Everywhere else this check runs behind an
			// already-granted USERS_EDIT_SELF, which a guesser needs a valid credential to
			// hold in the first place; the bypass has no such gate (issue #514) - a
			// zero-permission flagged account reaches it on the flag and the current password
			// alone.
			if ($isSelf && !empty($requestBody['password'] ?? null))
			{
				UsersService::GetInstance()->CheckCurrentPassword($targetUserId, $requestBody['current_password'] ?? null, $forcedRotationOnly);
			}

			// The bypass above authorises exactly one write - the password - and stands in
			// for USERS_EDIT_SELF nowhere else; a flagged, ungranted account attempting to
			// rename itself or change any other field alongside the mandated password
			// change is refused with state unchanged, the same as it would be without this
			// bypass at all.
			if ($forcedRotationOnly)
			{
				self::RefuseChangesBeyondThePassword($this->DB, $targetUserId, $requestBody);
			}

			$write = function () use ($isSelf, $requestBody, $response, $request, $targetUserId, $actingSessionKey)
			{
				if (!$isSelf) User::CheckMayAdminister($request, $targetUserId);

				UsersService::GetInstance()->EditUser(
					$targetUserId,
					self::RequiredField($requestBody, 'username'),
					$requestBody['first_name'] ?? null,
					$requestBody['last_name'] ?? null,
					$requestBody['password'] ?? null,
					$requestBody['picture_file_name'] ?? null,
					$actingSessionKey
				);

				return $this->EmptyApiResponse($response);
			};

			if ($forcedRotationOnly)
			{
				// Not RolesService::Mutate(): that serializes a permission check with the
				// grants that could change its answer, and there is no grant to race here -
				// this path is authorized by the must_change_password flag and the current
				// password alone, and never by USERS_EDIT_SELF.
				return DatabaseService::GetInstance()->InTransaction($write);
			}

			return RolesService::GetInstance()->Mutate($request, ($isSelf ? User::PERMISSION_USERS_EDIT_SELF : User::PERMISSION_USERS_EDIT), $write);
		});
	}

	/**
	 * Whether $userId may reach EditUser() only through the forced-rotation bypass: it is
	 * flagged to change its password and does not hold USERS_EDIT_SELF, so the ordinary
	 * permission gate can never pass for it (issue #514). Reads VICTUAL_USER_ID's resolved
	 * permissions, so it is meaningful only when $userId is the caller - EditUser() above
	 * calls it exactly there.
	 */
	private static function ForcedRotationOnly(int $userId): bool
	{
		return UsersService::GetInstance()->MustChangePassword($userId) && !User::HasPermissions(User::PERMISSION_USERS_EDIT_SELF);
	}

	/**
	 * Refuses (leaving every stored field untouched) a forced-rotation-only write that asks
	 * to change anything but the password: username must be resubmitted unchanged (it is a
	 * required field on this endpoint regardless) and first_name/last_name/picture_file_name
	 * must match what is already stored, treating an omitted field as an attempted null-out
	 * exactly as EditUser() itself would. The account's own profile form always resubmits
	 * its current values for fields it did not change, so this refuses only an actual
	 * attempt to use the bypass for more than the rotation it exists for.
	 *
	 * @throws EInvalidApiQuery When the body asks to change anything but the password
	 */
	private static function RefuseChangesBeyondThePassword($db, int $userId, array $requestBody): void
	{
		$stored = $db->users($userId);

		if (self::RequiredField($requestBody, 'username') !== $stored->username
			|| ($requestBody['first_name'] ?? null) !== $stored->first_name
			|| ($requestBody['last_name'] ?? null) !== $stored->last_name
			|| ($requestBody['picture_file_name'] ?? null) !== $stored->picture_file_name)
		{
			throw new EInvalidApiQuery('This account may only change its password until the required password change is made');
		}
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

	/**
	 * GET /api/user/capabilities - what the acting credential may do, about itself (issue
	 * #208, docs/mcp-interface-spec.md §4.2 item 5): `{ key_type, read_only, permissions }`.
	 *
	 * `key_type` is the type of the API key that authenticated the request, or null for a
	 * session or any other credential that is not a key. `permissions` is the acting user's
	 * resolved permission names, sorted - resolved, so ADMIN brings every leaf it implies,
	 * which is what a caller asking "may I call the route that checks X" needs.
	 *
	 * No permission is required, deliberately: the answer is about the caller, and
	 * GET /api/users/{id}/permissions is USERS_READ-gated, so without this an ordinary key
	 * could not learn its own permission set. It is what lets the MCP sidecar list only the
	 * tools a key can use. A new endpoint rather than new fields on GET /api/user, per the
	 * roadmap's additive-API rule.
	 */
	public function CurrentUserCapabilities(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response)
		{
			$apiKey = ApiKeyService::GetInstance()->GetActingApiKey();
			$permissions = User::ResolvedPermissionNames((int)VICTUAL_USER_ID);
			sort($permissions);

			return $this->ApiResponse($response, [
				'key_type' => $apiKey === null ? null : (string)$apiKey->key_type,
				'read_only' => ApiKeyService::GetInstance()->ActingKeyIsReadOnly(),
				'permissions' => $permissions
			]);
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
