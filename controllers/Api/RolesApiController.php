<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\RolesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RolesApiController extends BaseApiController
{
	public function ListRoles(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		return $this->ApiResponse($response, RolesService::GetInstance()->GetRoles());
	}

	public function CreateRole(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			return RolesService::GetInstance()->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($request, $response)
			{
				$body = $request->getParsedBody();
				$fields = $this->Fields($body);
				if (!isset($body['code']) || !is_string($body['code']) || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $body['code']))
				{
					throw new EInvalidApiQuery('code must contain uppercase letters, digits or underscores and start with a letter');
				}
				$fields['code'] = $body['code'];
				$role = $this->DB->roles()->createRow($fields)->save();
				return $this->ApiResponse($response, ['created_object_id' => (int)$role->id]);
			});
		});
	}

	public function EditRole(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		return $this->HandleApiCall($response, function () use ($request, $response, $args)
		{
			return RolesService::GetInstance()->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($request, $response, $args)
			{
				$service = RolesService::GetInstance();
				$service->CheckMayManage($request, (int)$args['roleId']);
				$body = $request->getParsedBody();
				if (is_array($body) && (array_key_exists('code', $body) || array_key_exists('builtin', $body)))
				{
					throw new EInvalidApiQuery('code and builtin are immutable');
				}
				$service->RequireRole((int)$args['roleId'])->update($this->Fields($body));
				return $this->EmptyApiResponse($response);
			});
		});
	}

	public function DeleteRole(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		return $this->HandleApiCall($response, function () use ($request, $response, $args)
		{
			return RolesService::GetInstance()->Mutate($request, User::PERMISSION_USERS_EDIT, function () use ($request, $response, $args)
			{
				$service = RolesService::GetInstance();
				$service->CheckMayManage($request, (int)$args['roleId']);
				$role = $service->RequireRole((int)$args['roleId']);
				if ($role->builtin)
				{
					throw new EInvalidApiQuery('Built-in roles cannot be deleted');
				}
				$role->delete();
				return $this->EmptyApiResponse($response);
			});
		});
	}

	public function ListPermissions(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		return $this->HandleApiCall($response, function () use ($response, $args)
		{
			RolesService::GetInstance()->RequireRole((int)$args['roleId']);
			return $this->ApiResponse($response, $this->DB->role_permissions()->where('role_id', $args['roleId']));
		});
	}

	public function SetPermissions(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		return $this->HandleApiCall($response, function () use ($request, $response, $args)
		{
			RolesService::GetInstance()->SetPermissions($request, (int)$args['roleId'], RolesService::Ids($request->getParsedBody(), 'permissions'));
			return $this->EmptyApiResponse($response);
		});
	}

	public function ListUserRoles(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		return $this->ApiResponse($response, RolesService::GetInstance()->GetUserRoles((int)$args['userId']));
	}

	public function SetUserRoles(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		return $this->HandleApiCall($response, function () use ($request, $response, $args)
		{
			RolesService::GetInstance()->SetUserRoles($request, (int)$args['userId'], RolesService::Ids($request->getParsedBody(), 'roles'));
			return $this->EmptyApiResponse($response);
		});
	}

	private function Fields($body): array
	{
		if (!is_array($body) || !isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '')
		{
			throw new EInvalidApiQuery('name is required');
		}
		if (isset($body['description']) && !is_string($body['description']))
		{
			throw new EInvalidApiQuery('description must be a string or null');
		}
		return ['name' => trim($body['name']), 'description' => $body['description'] ?? null];
	}
}
