<?php

namespace Victual\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Victual\Controllers\Users\User;

/**
 * The consumption inbox page (route GET /consumptioninbox): where a person resolves external consumption
 * events that were not booked, or that may duplicate a manual consumption (ADR-0041 rules 8 and 9). The
 * page is a shell: the server renders no event data, and every row is fetched from /api/consumption/events
 * with the user's session, so the owner-only rule of ADR-0041 rule 11 is enforced in one place.
 */
class ConsumptionInboxController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->RenderPage($response, 'consumptioninbox');
	}
}
