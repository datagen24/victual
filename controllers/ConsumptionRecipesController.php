<?php

namespace Victual\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Victual\Controllers\Users\User;

/**
 * The consumption recipes page (route GET /consumptionrecipes). The page is a shell: every row it
 * shows is fetched through the JSON API, so the share and ownership rules of ADR-0040 are enforced
 * in one place and no private recipe is rendered on the server.
 */
class ConsumptionRecipesController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->RenderPage($response, 'consumptionrecipes');
	}
}
