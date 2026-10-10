<?php

namespace Victual\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Victual\Controllers\Users\User;

/**
 * The prescription refills page (route GET /consumptionrefills): estimated reorder dates, the fill and order
 * history of each private consumption recipe, and the notices a person has not marked as seen (ADR-0042). The
 * page is a shell: the server renders no refill data, and every row is fetched from /api/refills and
 * /api/consumption/recipes/{id}/refill with the user's session, so the recipe's own access rules (ADR-0040) are
 * enforced in one place.
 */
class ConsumptionRefillsController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->RenderPage($response, 'consumptionrefills');
	}
}
