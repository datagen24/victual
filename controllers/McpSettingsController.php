<?php

namespace Victual\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Victual\Controllers\Users\User;
use Victual\Services\McpConfigService;

class McpSettingsController extends BaseController
{
	/** GET /mcpsettings - the nine MCP tools with a switch each (ADR-0039 decision 4). ADMIN only. */
	public function Settings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_ADMIN);

		return $this->RenderPage($response, 'mcpsettings', [
			'tools' => McpConfigService::GetInstance()->GetToolStates(),
			'writeTools' => McpConfigService::WRITE_TOOLS
		]);
	}
}
