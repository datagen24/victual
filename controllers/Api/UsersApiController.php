<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Middleware\Auth\SessionCookie;
use Victual\Services\ApiKeyService;
use Victual\Services\UsersService;
use Victual\Services\RolesService;
use Victual\Services\DatabaseService;
use Victual\Services\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

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
	 * $mustChangePassword is the one exception to the permission gate, and only to it: a
	 * flagged account may lack USERS_EDIT_SELF entirely - that is what the flag can mean -
	 * so this route cannot make the permission a precondition for the one write that
	 * resolves the flag (issue #514). What the flag does NOT do is widen what that write
	 * may touch: RefuseChangesBeyondThePassword() below applies to every flagged self-edit,
	 * whether or not USERS_EDIT_SELF happens to also be held (validator round 2) - ADMIN,
	 * ADULT and CHILD all grant it, so a flagged account holding one of those roles is
	 * restricted exactly like a flagged account holding nothing.
	 *
	 * Changing one's own password additionally requires the current one, in the body field
	 * current_password (or current_password_base64). Sweep finding S6: without it, a
	 * borrowed session or an unlocked browser is enough to take an account over
	 * permanently, and the person it belongs to finds out when they cannot log in.
	 *
	 * Returns 204 on success, a 403 when a flagged account's request touches more than the
	 * password, or a 400 error response.
	 */
	public function EditUser(Request $request, Response $response, array $args)
	{
		$isSelf = $args['userId'] == VICTUAL_USER_ID;
		$targetUserId = (int)$args['userId'];
		$mustChangePassword = $isSelf && UsersService::GetInstance()->MustChangePassword($targetUserId);
		// Only the permission check itself is conditional on lacking USERS_EDIT_SELF - see
		// the docblock above. Also decides which of Mutate()/InTransaction() wraps the
		// write below: Mutate()'s recheck exists to close a race against a permission grant,
		// and an account that never held the permission has none to race.
		$forcedRotationOnly = $mustChangePassword && !User::HasPermissions(User::PERMISSION_USERS_EDIT_SELF);

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

		// The session, if any, that authenticated this very request. Read off the cookie
		// and re-validated here rather than trusted on its presence alone: a dead session
		// cookie can still ride along on a request DefaultAuthMiddleware actually
		// authenticated by API key (a browser tab that logged out elsewhere, or a client
		// that sends both credential types), and IsValidSession() is what tells a live
		// session apart from a stale value that happens to still be there (validator round
		// 2 - the previous comment here claimed presence alone was enough, which is false in
		// exactly that case). Passed through to UsersService::EditUser() so a password
		// change can keep this one session alive while revoking every other, and so it
		// knows whether there is a browser session at all to mint a replacement for when
		// the account was flagged (issue #513).
		$rawSessionCookie = $request->getCookieParams()[SessionService::SESSION_COOKIE_NAME] ?? null;
		$actingSessionKey = ($rawSessionCookie !== null && SessionService::GetInstance()->IsValidSession($rawSessionCookie))
			? $rawSessionCookie
			: null;

		return $this->HandleApiCall($response, function () use ($isSelf, $mustChangePassword, $forcedRotationOnly, $requestBody, $response, $request, $targetUserId, $actingSessionKey)
		{
			if ($requestBody === null)
			{
				throw new EInvalidApiQuery('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			$requestBody = self::WithDecodedPassword($requestBody, 'password');
			$requestBody = self::WithDecodedPassword($requestBody, 'current_password');

			// Everything below that does not depend on the stored password hash runs ahead
			// of CheckCurrentPassword() - username's presence, every field's type, whether a
			// change is even being attempted, and (for a flagged account) whether anything
			// but the password is being changed - so a malformed or out-of-scope request is
			// refused identically whatever the submitted current password happens to be.
			// Validator round 2 found the previous order let a bundled rename answer
			// differently depending on whether the guessed password was right (issue #514):
			// "must only change the password" for a correct guess, "did not match" for a
			// wrong one - an oracle for the password itself, from a field the password check
			// has nothing to do with.
			$username = self::RequiredField($requestBody, 'username');

			// Round 3: an array (or other non-string, non-null) value for a field
			// UsersService::EditUser()/CheckCurrentPassword() type-hints as ?string reached
			// a TypeError - an \Error, which HandleApiCall() deliberately does not catch -
			// instead of the ordinary 400 every other malformed field gets. Because that
			// crash happened only in $write, after a correct current_password had already
			// cleared the throttle counter inside CheckCurrentPassword(), whether the crash
			// happened at all told an attacker whether their guess was right, and did it for
			// free (the throttle counter was already cleared by the time it happened).
			foreach (['first_name', 'last_name', 'password', 'current_password', 'picture_file_name'] as $stringField)
			{
				self::RequireNullableString($requestBody, $stringField);
			}

			// Fetched once, here, and reused below for the omitted-picture_file_name write
			// value and (when flagged) the field-scope check, rather than queried twice.
			$stored = $this->DB->users($targetUserId);

			// An account that has to change its password reaches this route through
			// BaseAuthMiddleware's allowlist for that purpose alone. So it must actually change
			// it: otherwise the one route left open is a way to rename the account - "admin"
			// to something the operator does not know - without the change it exists for.
			// And to something else: re-saving the printed or default password would clear
			// the flag and leave that password in place. Found by CodeRabbit on PR #213.
			if ($mustChangePassword)
			{
				if (empty($requestBody['password'] ?? null))
				{
					throw new EInvalidApiQuery('This account must change its password: send the new password and current_password');
				}

				if ($requestBody['password'] === ($requestBody['current_password'] ?? null))
				{
					throw new EInvalidApiQuery('The new password must differ from the current one');
				}

				// Keyed on the flag itself, not on lacking USERS_EDIT_SELF - see this
				// method's docblock. Checked here, ahead of the password, for the oracle
				// reason above, and answered the way a missing permission is (403): this is
				// an authorization refusal, not a data one - the credential is never
				// authorized to touch these fields while flagged, regardless of whether the
				// password offered with the attempt is correct.
				self::RefuseChangesBeyondThePassword($request, $stored, $username, $requestBody);
			}

			if ($isSelf && !empty($requestBody['password'] ?? null))
			{
				// Throttled on every self password change now, not only the forced-rotation
				// bypass (issue #514's remaining subclaim): the validator found a wrong
				// guess free of cost on the ordinarily-permitted path, the same gap the
				// bypass had before it was throttled at all. A wrong guess here and a wrong
				// login password now share one per-username counter.
				UsersService::GetInstance()->CheckCurrentPassword($targetUserId, $requestBody['current_password'] ?? null, true);
			}

			// An omitted picture_file_name keeps whatever is already stored, rather than
			// silently nulling it out: the field-scope check above already reads an absent
			// key as "no attempted change" (userform.js omits it unless a picture is being
			// uploaded or deleted), but the write itself used to fall back to null the same
			// way an omitted first_name or last_name does, erasing a stored picture on every
			// ordinary form save that did not touch it - not only a flagged one, since this
			// is the same code either way (validator round 3). first_name/last_name are not
			// given the same treatment: unlike the picture, the form always submits both,
			// even blank ("" rather than omitted), so there is no real "omitted" case for
			// them to preserve, and an explicit null still nulls out the picture as before.
			$pictureFileName = array_key_exists('picture_file_name', $requestBody)
				? $requestBody['picture_file_name']
				: $stored?->picture_file_name;

			// Set only inside $write, and read only after the transaction wrapping it has
			// committed - see the comment where it is read, below.
			$newSessionKey = null;

			$write = function () use ($isSelf, $username, $requestBody, $pictureFileName, $response, $request, $targetUserId, $actingSessionKey, &$newSessionKey)
			{
				if (!$isSelf) User::CheckMayAdminister($request, $targetUserId);

				$newSessionKey = UsersService::GetInstance()->EditUser(
					$targetUserId,
					$username,
					$requestBody['first_name'] ?? null,
					$requestBody['last_name'] ?? null,
					$requestBody['password'] ?? null,
					$pictureFileName,
					$actingSessionKey,
					$isSelf
				);

				return $this->EmptyApiResponse($response);
			};

			$result = $forcedRotationOnly
				// Not RolesService::Mutate(): that serializes a permission check with the
				// grants that could change its answer, and there is no grant to race here -
				// this path is authorized by the must_change_password flag and the current
				// password alone, and never by USERS_EDIT_SELF.
				? DatabaseService::GetInstance()->InTransaction($write)
				: RolesService::GetInstance()->Mutate($request, ($isSelf ? User::PERMISSION_USERS_EDIT_SELF : User::PERMISSION_USERS_EDIT), $write);

			// Only after the transaction has committed - InTransaction()/Mutate() call
			// PDO::commit() after $write returns and before either of them returns to here,
			// so reaching this line at all means the write is durable. Setting the cookie
			// from inside $write, before commit, named a session a later failure could still
			// undo: a deferred constraint or trigger fails only at COMMIT, PostgreSQL then
			// rolls back the whole transaction automatically, and the validator's probe
			// showed exactly that - a 400 with the password, flag and sessions all intact,
			// but a Set-Cookie already sent for a session that was never actually created,
			// logging the browser out of an account whose credential never changed
			// (validator round 3).
			if ($newSessionKey !== null)
			{
				SessionCookie::Set($newSessionKey);
			}

			return $result;
		});
	}

	/**
	 * Refuses (400) when $field is present in the body and is not a string: without this,
	 * an array (or other non-string, non-null) value for a field UsersService::EditUser()
	 * or CheckCurrentPassword() type-hints as ?string reached a TypeError instead of the
	 * ordinary 400 every other malformed field gets - see EditUser()'s call site for why
	 * that crash was also an oracle (validator round 3).
	 *
	 * @throws EInvalidApiQuery
	 */
	private static function RequireNullableString(array $requestBody, string $field): void
	{
		if (isset($requestBody[$field]) && !is_string($requestBody[$field]))
		{
			throw new EInvalidApiQuery($field . ' must be a string');
		}
	}

	/**
	 * Refuses (leaving every stored field untouched) a flagged account's self-edit that
	 * asks to change anything but the password: $username - already validated required and
	 * passed in rather than re-read, since the caller needs it before this to close the
	 * oracle below - must equal what is stored, and so must first_name/last_name once an
	 * empty string is treated as equal to NULL **on both sides of the comparison**: a
	 * stored '' - which the create form, any edit-form save and
	 * ReverseProxyAuthenticator's auto-provisioning can all produce - must match a
	 * submitted "" exactly as a stored NULL does, or an account with '' names could never
	 * resubmit its own unchanged values at all (validator round 3 - the round 2 fix
	 * normalized only the submitted side, so a stored '' matched nothing, including an
	 * identical resubmitted ""). picture_file_name is compared only when the body actually
	 * names it: the form omits the field entirely unless a picture is being uploaded or
	 * deleted, so an absent key means no attempted change, never an attempt to null out an
	 * existing picture (validator round 2).
	 *
	 * $stored is nullable and read once by the caller (also used there to keep an omitted
	 * picture_file_name unchanged) rather than fetched again here; a null $stored means
	 * $userId does not exist, and every comparison below then refuses, which is correct -
	 * EditUser() throws its own "User does not exist" once the caller proceeds regardless.
	 *
	 * Applies whether or not the caller holds USERS_EDIT_SELF - see EditUser()'s docblock -
	 * and is answered as a 403 (HttpForbiddenException) rather than the usual 400: this is
	 * a refusal of what the credential may do while flagged, the same class of answer a
	 * missing permission gets, not a data-validation failure.
	 *
	 * @throws HttpForbiddenException When the body asks to change anything but the password
	 */
	private static function RefuseChangesBeyondThePassword(Request $request, $stored, string $username, array $requestBody): void
	{
		$blankAsNull = fn($value) => $value === '' ? null : $value;

		$pictureFileNameChanged = array_key_exists('picture_file_name', $requestBody)
			&& $requestBody['picture_file_name'] !== $stored?->picture_file_name;

		if ($username !== $stored?->username
			|| $blankAsNull($requestBody['first_name'] ?? null) !== $blankAsNull($stored?->first_name)
			|| $blankAsNull($requestBody['last_name'] ?? null) !== $blankAsNull($stored?->last_name)
			|| $pictureFileNameChanged)
		{
			throw new HttpForbiddenException($request, 'This account may only change its password until the required password change is made');
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
