<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LabelsApiController extends BaseApiController
{
	public function Resolve(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response, $args)
		{
			return $this->ApiResponse($response, $this->Identity()->Resolve($args['code'], User::HasPermissions(User::PERMISSION_STOCK_VIEW)));
		});
	}

	public function LocationContext(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->HandleApiCall($response, function () use ($response, $args)
		{
			$context = $this->Identity()->LocationContext((int)$args['locationId']);
			return $context === null ? $this->GenericErrorResponse($response, 'Location not found', 404)
				: $this->ApiResponse($response, $context);
		});
	}

	private function Identity(): LabelIdentityService
	{
		return new LabelIdentityService(DatabaseService::GetInstance()->GetDbConnectionRaw());
	}
}
