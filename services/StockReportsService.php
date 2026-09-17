<?php

namespace Victual\Services;

/**
 * Stock spending reports, aggregated directly from the products price history via raw
 * SQL - moved here from StockReportsController (plan 15-C2) so that every raw-SQL site
 * outside the dialect boundary lives in a service, matching every other controller in
 * the codebase. No SQL change: the queries below are byte-identical to the ones the
 * controller used to run, including COLLATE NOCASE, which looks engine-specific and is
 * not - PostgreSQL's baseline defines a matching NOCASE collation for exactly this
 * cross-engine pattern (hazard 15 in db/pgsql/README.md).
 */
class StockReportsService extends BaseService
{
	const GROUP_BY_PRODUCT = 'product';
	const GROUP_BY_PRODUCTGROUP = 'productgroup';
	const GROUP_BY_STORE = 'store';

	/**
	 * Sums amount * price from the price history, excluding self-production bookings.
	 *
	 * @param string|null $startDate ISO date, inclusive lower bound of the range. Ignored
	 * unless $endDate is also a valid ISO date, in which case the default below applies.
	 * @param string|null $endDate ISO date, inclusive upper bound of the range.
	 * @param string $groupBy One of 'product', 'productgroup' or 'store'; anything else
	 * is treated as 'product'.
	 * @param string|int|null $productGroup A product group id, 'ungrouped' or 'all';
	 * only applied when $groupBy is 'product'.
	 * @return object[] Rows of {id, name, total}, plus {group_id, group_name} when
	 * grouped by product.
	 */
	public function GetSpendings($startDate, $endDate, string $groupBy = self::GROUP_BY_PRODUCT, $productGroup = null): array
	{
		$where = "pph.transaction_type != 'self-production'";

		// Everything which would otherwise be interpolated into the SQL below is bound
		// as a positional parameter instead - the date boundaries especially, because
		// SQLite's DATE(x, 'start of month') has no PostgreSQL equivalent and date
		// arithmetic must not leak into the query (see DatabaseDialect). $where is
		// embedded exactly once in each of the queries below, so the order in which
		// the values are appended here is the order the placeholders appear in.
		$whereParams = [];

		if ($startDate !== null && $endDate !== null && IsIsoDate($startDate) && IsIsoDate($endDate))
		{
			$where .= ' AND pph.purchased_date BETWEEN ? AND ?';
			$whereParams[] = $startDate;
			$whereParams[] = $endDate;
		}
		else
		{
			// Default to this month
			$where .= ' AND pph.purchased_date >= ?';
			$whereParams[] = date('Y-m-01');
		}

		if (!in_array($groupBy, [self::GROUP_BY_PRODUCT, self::GROUP_BY_PRODUCTGROUP, self::GROUP_BY_STORE]))
		{
			$groupBy = self::GROUP_BY_PRODUCT;
		}

		if ($groupBy === self::GROUP_BY_PRODUCT)
		{
			if ($productGroup === 'ungrouped')
			{
				$where .= ' AND pg.id IS NULL';
			}
			elseif ($productGroup !== null && $productGroup !== 'all' && filter_var($productGroup, FILTER_VALIDATE_INT) !== false)
			{
				$where .= ' AND pg.id = ?';
				$whereParams[] = intval($productGroup);
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
		elseif ($groupBy === self::GROUP_BY_PRODUCTGROUP)
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
		else // GROUP_BY_STORE
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

		return DatabaseService::GetInstance()->ExecuteDbQuery($sql, $whereParams)->fetchAll(\PDO::FETCH_OBJ);
	}
}
