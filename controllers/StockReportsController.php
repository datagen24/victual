<?php

namespace Victual\Controllers;

use Victual\Services\DatabaseService;
use Victual\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for stock reports; currently only the spendings
 * report, aggregated directly from the products price history via raw SQL.
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
		$where = "pph.transaction_type != 'self-production'";

		// Everything which would otherwise be interpolated into the SQL below is bound
		// as a positional parameter instead - the date boundaries especially, because
		// SQLite's DATE(x, 'start of month') has no PostgreSQL equivalent and date
		// arithmetic must not leak into the query (see DatabaseDialect). $where is
		// embedded exactly once in each of the queries below, so the order in which
		// the values are appended here is the order the placeholders appear in.
		$whereParams = [];

		if (isset($request->getQueryParams()['start_date']) && isset($request->getQueryParams()['end_date']) && IsIsoDate($request->getQueryParams()['start_date']) && IsIsoDate($request->getQueryParams()['end_date']))
		{
			$where .= ' AND pph.purchased_date BETWEEN ? AND ?';
			$whereParams[] = $request->getQueryParams()['start_date'];
			$whereParams[] = $request->getQueryParams()['end_date'];
		}
		else
		{
			// Default to this month
			$where .= ' AND pph.purchased_date >= ?';
			$whereParams[] = date('Y-m-01');
		}

		$groupBy = 'product';
		if (isset($request->getQueryParams()['group-by']) && in_array($request->getQueryParams()['group-by'], ['product', 'productgroup', 'store']))
		{
			$groupBy = $request->getQueryParams()['group-by'];
		}

		if ($groupBy == 'product')
		{
			if (isset($request->getQueryParams()['product-group']))
			{
				if ($request->getQueryParams()['product-group'] == 'ungrouped')
				{
					$where .= ' AND pg.id IS NULL';
				}
				elseif ($request->getQueryParams()['product-group'] != 'all' && filter_var($request->getQueryParams()['product-group'], FILTER_VALIDATE_INT) !== false)
				{
					$where .= ' AND pg.id = ?';
					$whereParams[] = intval($request->getQueryParams()['product-group']);
				}
			}

			$sql = "
			SELECT
				p.id AS id,
				p.name AS name,
				pg.id AS group_id,
				pg.name AS group_name,
				SUM(pph.amount * pph.price) AS total
			FROM products_price_history pph
			JOIN products p
				ON pph.product_id = p.id
			LEFT JOIN product_groups pg
				ON p.product_group_id = pg.id
			WHERE $where
			GROUP BY p.id, p.name, pg.id, pg.name
			ORDER BY p.name COLLATE NOCASE
			";
		}
		elseif ($groupBy == 'productgroup')
		{
			$sql = "
			SELECT
				pg.id AS id,
				pg.name AS name,
				SUM(pph.amount * pph.price) AS total
			FROM products_price_history pph
			JOIN products p
				ON pph.product_id = p.id
			LEFT JOIN product_groups pg
				ON p.product_group_id = pg.id
			WHERE $where
			GROUP BY pg.id, pg.name
			ORDER BY pg.name COLLATE NOCASE
			";
		}
		elseif ($groupBy == 'store')
		{
			$sql = "
			SELECT
				sl.id AS id,
				sl.name AS name,
				SUM(pph.amount * pph.price) AS total
			FROM products_price_history pph
			JOIN products p
				ON pph.product_id = p.id
			LEFT JOIN shopping_locations sl
				ON pph.shopping_location_id = sl.id
			WHERE $where
			GROUP BY sl.id, sl.name
			ORDER BY sl.NAME COLLATE NOCASE
			";
		}

		return $this->RenderPage($response, 'stockreportspendings', [
			'metrics' => DatabaseService::GetInstance()->ExecuteDbQuery($sql, $whereParams)->fetchAll(\PDO::FETCH_OBJ),
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'selectedGroup' => isset($request->getQueryParams()['product-group']) ? $request->getQueryParams()['product-group'] : null,
			'groupBy' => $groupBy
		]);
	}
}
