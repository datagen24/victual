<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\PrintService;
use Victual\Services\StockService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/print endpoints for printing on a connected thermal printer.
 */
class PrintApiController extends BaseApiController
{
	/**
	 * GET /api/print/shoppinglist/thermal - prints the given shopping list on the
	 * configured thermal printer and returns the PrintService result (200).
	 * Query parameters: list (shopping list id, default 1) and printHeader
	 * ("true"/"false", default true). Requires the SHOPPINGLIST permission (403
	 * otherwise); other failures yield a 400 error response.
	 */
	public function PrintShoppingListThermal(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_VIEW);
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$params = $request->getQueryParams();

			$listId = 1;
			if (isset($params['list']))
			{
				$listId = $params['list'];
			}

			$printHeader = true;
			if (isset($params['printHeader']))
			{
				$printHeader = ($params['printHeader'] === 'true');
			}
			$items = StockService::GetInstance()->GetShoppinglistInPrintableStrings($listId);
			return $this->ApiResponse($response, PrintService::GetInstance()->printShoppingList($printHeader, $items));
		});
	}
}
