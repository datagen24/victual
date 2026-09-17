<?php

namespace Victual\Controllers;

use Victual\Services\StockReportsService;
use Victual\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for stock reports; currently only the spendings report. The
 * aggregation itself lives in StockReportsService (plan 15-C2) - this controller only
 * parses the request and renders the view.
 */
class StockReportsController extends BaseController
{
	/**
	 * Serves the spendings report view (route GET /stockreports/spendings);
	 * sums amount * price from the price history, excluding self-production.
	 *
	 * Optional query parameters: start_date/end_date (ISO dates; default is the
	 * current month), group-by ('product', 'productgroup' or 'store'; default
	 * 'product') and product-group (a group id, 'ungrouped' or 'all'; only
	 * applied when grouping by product).
	 */
	public function Spendings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		// The whole page is a price: every metric it renders is SUM(amount * price) over
		// products_price_history, which permission_fields gates with a '*' whole-object row
		// (migration 0281, db/pgsql/prices-seed.sql) and which
		// StockApiController::ProductPriceHistory refuses outright without this permission.
		// Until issue #176 item 4 this route was reachable on STOCK_VIEW alone with only its
		// menu link hidden, so a Child could read the household's spending by typing the URL.
		User::CheckPermission($request, User::PERMISSION_STOCK_PRICES_VIEW);

		$queryParams = $request->getQueryParams();

		$groupBy = 'product';
		if (isset($queryParams['group-by']) && in_array($queryParams['group-by'], ['product', 'productgroup', 'store']))
		{
			$groupBy = $queryParams['group-by'];
		}

		$productGroup = isset($queryParams['product-group']) ? $queryParams['product-group'] : null;

		return $this->RenderPage($response, 'stockreportspendings', [
			'metrics' => StockReportsService::GetInstance()->GetSpendings(
				isset($queryParams['start_date']) ? $queryParams['start_date'] : null,
				isset($queryParams['end_date']) ? $queryParams['end_date'] : null,
				$groupBy,
				$productGroup
			),
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'selectedGroup' => $productGroup,
			'groupBy' => $groupBy
		]);
	}
}
