-- Issue #622 (#487 remediation): stock_current.amount_aggregated counted an unconvertible
-- sub product 1:1.
--
-- This is #553's own defect (fixed on the write side by PR #621's
-- StockService::SubstitutionAwareProductIdWhereClause()) on the read side: every column
-- below rolls a sub product's stock into its parent by multiplying that row's own amount
-- by `COALESCE(qucr.factor::double precision, 1.0::double precision)` - the LEFT JOIN
-- against cache__quantity_unit_conversions_resolved falls back to a factor of 1.0 whenever
-- no resolved conversion exists between the sub product's stock unit and the parent's own,
-- exactly the "no conversion means 1:1" fallback #553 already found wrong for Consume,
-- Open and GetProductStockEntries/GetProductStockLocations. A can-unit sub product with no
-- can->parent conversion is counted as if one can equalled one parent unit; the same
-- fallback also lets a resolved but NEGATIVE factor (nothing puts a CHECK on
-- quantity_unit_conversions.factor - see StockService.php's own comment on
-- SubstitutionAwareProductIdWhereClause()) subtract stock that was never there.
--
-- Maintainer decision D4 (issue #553) already settled the intended behaviour: an
-- unconvertible sub product, or one whose only resolved conversion has a non-positive
-- factor, contributes nothing to the parent's rollup. It is excluded, never counted 1:1.
-- This migration applies that same rule to every column in stock_current's first UNION
-- branch (the parent-rollup branch) built from the same qucr join:
--
--   - amount_aggregated (this issue's own report)
--   - amount_opened_aggregated (the per-row conversion migrations/0289.pgsql.sql already
--     fixed for issue #501 - it shares the same COALESCE(..., 1.0) fallback and the same
--     defect)
--   - amount_measured (the opened-container measurement, which multiplies by this same
--     qucr.factor term as a second factor alongside its own qucr_measure conversion)
--
-- The recipe cost/calorie views in db/pgsql/baseline/05_views_l3.sql also fall back to
-- IFNULL/COALESCE(qucr.factor, 1.0): their qucr join resolves `p_effective`, the recipe
-- position's substituted product, via `products_current_substitutions` (joined on
-- `rp.product_id = pcs.parent_product_id`), which is itself built directly on
-- products_resolved (db/pgsql/baseline/05_views_l2.sql) - the same parent/sub-product
-- hierarchy this issue and #553 are both about, not a different mechanism. Same defect
-- class; tracked separately in #629, out of scope for this migration. stock_missing_products
-- (db/pgsql/baseline/05_views_l2.sql) reads amount_aggregated/amount_opened_aggregated
-- from stock_current rather than joining qucr itself, so it inherits this fix for free.
--
-- The fix distinguishes two cases per row, the same distinction
-- SubstitutionAwareProductIdWhereClause() draws between its `product_id IN (...)` branch
-- (substitution candidates, admitted only on a resolved, positive factor) and its
-- `OR product_id = $productId` branch (the parent's own stock, unconditional):
--
--   1. The row IS the parent's own contribution (products_resolved's self-row, present for
--      every product with no parent of its own: `parent_product_id = sub_product_id`).
--      Here p_sub and p_parent are the same product, and
--      cache__quantity_unit_conversions_resolved already carries a stock->stock identity
--      row at factor 1.0 for every product ("Priority 2" in
--      quantity_unit_conversions_resolved's own recursive CTE,
--      db/pgsql/baseline/03_views_group2.sql), so qucr already resolves this case to 1.0 on
--      its own. The CASE branch's 1.0 here is a safety net rather than the only source of
--      that value - it keeps the parent's own stock from silently dropping out of its own
--      aggregate if that identity row were ever missing, rather than relying solely on the
--      cache always being populated.
--   2. The row is a genuine sub product (`parent_product_id != sub_product_id`). Here a
--      missing or non-positive qucr.factor means "unconvertible" per D4, and the fallback
--      is 0 - the row's stock is excluded from the aggregate, contributing nothing, rather
--      than being treated as though it were already in the parent's unit.
--
-- `AND CAST(qucr.factor AS NUMERIC) > 0` added to the qucr join condition makes a
-- resolved-but-non-positive factor behave identically to "no resolved conversion at all"
-- (qucr.factor reads NULL either way), so a single CASE distinguishes only the two cases
-- above rather than needing a third. qucr.factor is declared TEXT on both engines
-- (db/pgsql/baseline/01_tables.sql, migrations/0225.sql), so - exactly as
-- SubstitutionAwareProductIdWhereClause()'s own comment explains - an explicit
-- CAST(... AS NUMERIC) is needed for `> 0` to even type-check.
--
-- Every other column, join and branch (including the second UNION branch, which is a sub
-- product's own unaggregated entry and never joins qucr for its own amount at all) is
-- byte-identical to migrations/0289.pgsql.sql's stock_current. stock_current has been
-- PostgreSQL-only since migrations/0275.pgsql.sql (ADR-0008/0289's own header note): SQLite
-- is frozen at DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID = 0265, so there is no
-- matching SQLite file to write, and the differential view-test fixtures
-- (.devtools/pgsql/view-tests/01_stock_basics.sql) exercise no parent/sub-product
-- conversion scenario for stock_current, so this migration changes nothing that phase
-- compares between engines.

CREATE OR REPLACE VIEW stock_current AS
SELECT
	pr.parent_product_id AS product_id,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id), 0) AS amount,
	SUM(s.amount * COALESCE(qucr.factor::double precision, CASE WHEN pr.parent_product_id = pr.sub_product_id THEN 1.0::double precision ELSE 0.0::double precision END)) AS amount_aggregated,
	COALESCE(CAST(ROUND(CAST((SELECT SUM(COALESCE(price,0) * amount) FROM stock WHERE product_id = pr.parent_product_id) AS numeric), 2) AS double precision), 0) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id AND open = 1), 0) AS amount_opened,
	-- Fix for issue #622: an unconvertible sub product (or one whose only resolved
	-- conversion has a non-positive factor) contributes 0, not its raw amount, to the
	-- opened aggregate - same rule as amount_aggregated above, per D4 (issue #553).
	COALESCE(SUM(CASE WHEN s.open = 1 THEN s.amount * COALESCE(qucr.factor::double precision, CASE WHEN pr.parent_product_id = pr.sub_product_id THEN 1.0::double precision ELSE 0.0::double precision END) ELSE 0 END), 0) AS amount_opened_aggregated,
	CASE WHEN COUNT(p_sub.parent_product_id) > 0  THEN 1 ELSE 0 END AS is_aggregated_amount,
	MAX(p_parent.due_type) AS due_type,
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision * COALESCE(qucr.factor::double precision, CASE WHEN pr.parent_product_id = pr.sub_product_id THEN 1.0::double precision ELSE 0.0::double precision END)), 0) AS amount_measured
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_parent
	ON pr.parent_product_id = p_parent.id
	AND p_parent.active = 1
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON pr.sub_product_id = qucr.product_id
	AND p_sub.qu_id_stock = qucr.from_qu_id
	AND p_parent.qu_id_stock = qucr.to_qu_id
	-- Fix for issue #622: a resolved but non-positive factor must exclude the sub product
	-- exactly like no resolved conversion at all (D4, issue #553), not be divided/multiplied
	-- into the aggregate. qucr.factor is TEXT, so CAST(... AS NUMERIC) is needed to compare
	-- it at all - same cast StockService::SubstitutionAwareProductIdWhereClause() uses.
	AND CAST(qucr.factor AS NUMERIC) > 0
LEFT JOIN cache__quantity_unit_conversions_resolved qucr_measure
	ON s.product_id = qucr_measure.product_id
	AND s.opened_qu_id = qucr_measure.from_qu_id
	AND p_sub.qu_id_stock = qucr_measure.to_qu_id
GROUP BY pr.parent_product_id
HAVING SUM(s.amount) > 0

UNION

-- This is the same as above but sub products not rolled up (no QU conversion and column is_aggregated_amount = 0 here)
SELECT
	pr.sub_product_id AS product_id,
	SUM(s.amount) AS amount,
	SUM(s.amount) AS amount_aggregated,
	CAST(ROUND(CAST(SUM(COALESCE(s.price, 0) * s.amount) AS numeric), 2) AS double precision) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened_aggregated,
	0 AS is_aggregated_amount,
	MAX(p_sub.due_type) AS due_type,
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision), 0) AS amount_measured
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
LEFT JOIN cache__quantity_unit_conversions_resolved qucr_measure
	ON s.product_id = qucr_measure.product_id
	AND s.opened_qu_id = qucr_measure.from_qu_id
	AND p_sub.qu_id_stock = qucr_measure.to_qu_id
WHERE pr.parent_product_id != pr.sub_product_id
GROUP BY pr.sub_product_id
HAVING SUM(s.amount) > 0;
