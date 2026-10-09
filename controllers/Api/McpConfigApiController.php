<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\McpConfigService;
use Victual\Services\WireBooleans;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The MCP sidecar's configuration, served to the sidecar and edited by an administrator
 * (ADR-0039 decisions 3 and 4).
 */
class McpConfigApiController extends BaseApiController
{
	/**
	 * GET /api/mcp/config - the names of the enabled tools. Needs no permission beyond a valid
	 * credential, a read-only MCP key included: the answer is a list of tool names, the sidecar
	 * asks with the key the request forwards, and nothing in it is household data.
	 */
	public function GetConfig(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response)
		{
			return $this->ApiResponse($response, ['enabled_tools' => McpConfigService::GetInstance()->GetEnabledTools()]);
		});
	}

	/**
	 * PUT /api/mcp/config - body {"tools": {"consume_product": true, ...}}. Sets the named
	 * tools and leaves the others as they are. ADMIN only (403 otherwise). An unknown tool
	 * name, a value that is not a boolean, or a body of another shape is a 400 and changes
	 * nothing. Answers the new enabled list, in the shape GET answers.
	 *
	 * An API key's request needs no CSRF token: BaseAuthMiddleware's cross-origin refusal
	 * applies to session-authenticated writes only, and a read-only key's PUT is refused with
	 * 403 before this method runs.
	 */
	public function SetConfig(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_ADMIN);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($request, $requestBody, $response)
		{
			$requestBody = $this->RequireRequestBody($requestBody);

			if (!isset($requestBody['tools']) || !is_array($requestBody['tools']) || $requestBody['tools'] === [] || array_is_list($requestBody['tools']))
			{
				throw new EInvalidApiQuery('tools must be an object of tool name to true or false');
			}

			$switches = [];
			foreach ($requestBody['tools'] as $tool => $enabled)
			{
				$switches[(string)$tool] = WireBooleans::RequireBoolean($enabled, 'switch for ' . $tool);
			}

			$service = McpConfigService::GetInstance();
			try
			{
				$this->InRequestTransaction($request, function () use ($service, $switches)
				{
					$service->SetTools($switches, (int)VICTUAL_USER_ID);
				});
			}
			catch (\InvalidArgumentException $ex)
			{
				throw new EInvalidApiQuery($ex->getMessage());
			}

			return $this->ApiResponse($response, ['enabled_tools' => $service->GetEnabledTools()]);
		});
	}
}
