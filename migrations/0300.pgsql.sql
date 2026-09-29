-- Issue #629 (#487 remediation): the recipe cost and calorie views count an unconvertible
-- substituted sub product 1:1.
--
-- Found while validating PR #628 (issue #622), which fixed the same defect class for
-- stock_current.amount_aggregated (migrations/0298.pgsql.sql). Maintainer decision D4
-- (issue #553) already settled the intended behaviour: an unconvertible sub product, or
-- one whose only resolved conversion has a non-positive factor, contributes nothing and
-- is never counted 1:1. #621's StockService::SubstitutionAwareProductIdWhereClause()
-- applies that rule on the consume side by never admitting such a product as a
-- substitution candidate at all - it is excluded from the `product_id IN (...)` branch,
-- so Consume()/GetProductStockEntries() fall through to the parent's own stock or another,
-- genuinely convertible, sub product.
--
-- ROOT CAUSE. recipes_pos_resolved (db/pgsql/baseline/05_views_l3.sql) computes a recipe
-- position's `costs` and `calories` against `p_effective`, the substituted product
-- (`COALESCE(pcs.product_id_effective, rp.product_id)`), converting between the parent's
-- own quantity unit and p_effective's stock unit via
-- `COALESCE(qucr.factor::double precision, 1.0::double precision)`. `product_id_effective`
-- itself comes from products_current_substitutions (db/pgsql/baseline/05_views_l2.sql),
-- which - when a recipe's parent ingredient is not itself in stock - picks "the next sub
-- product to use" purely by stock_next_use's consume-priority ordering (open first, then
-- first due, then first in first out), with no regard to whether that sub product's stock
-- unit converts to the parent's at all. A sub product that wins that ordering but has no
-- resolved conversion (or only a non-positive one) from the parent's unit is still chosen
-- as product_id_effective, and recipes_pos_resolved's own COALESCE then falls back to
-- 1.0 for it, pricing and costing an unconvertible substitution as if the units already
-- matched - the same "no conversion means 1:1" mistake, on the recipe side.
--
-- Both options the issue posed reduce to the same code change here: making the
-- unconvertible sub product "contribute nothing" to a recipe's cost/calories can only be
-- done by never letting it become product_id_effective in the first place, because
-- product_id_effective is also what selects the product recipes_pos_resolved reads
-- `calories` and `costs` from - there is no separate "was this substitution valid" flag to
-- zero out downstream. So this migration applies option (a): a sub product is only ever
-- selected as product_id_effective when a resolved, POSITIVE quantity-unit conversion
-- exists from the parent's own stock unit to its own stock unit -
-- cache__quantity_unit_conversions_resolved, joined in the exact same direction
-- SubstitutionAwareProductIdWhereClause() uses to admit a substitution candidate on the
-- consume side (services/StockService.php ~2049-2085): `product_id` = the candidate,
-- `from_qu_id` = the parent's own qu_id_stock, `to_qu_id` = the candidate's own
-- qu_id_stock. An excluded candidate is simply removed from the ORDER BY ... LIMIT 1
-- subquery's result set, so the next genuinely convertible sub product wins instead (by
-- the same consume-priority ordering), or, if none exists, the subquery returns no row and
-- recipes_pos_resolved's own `COALESCE(pcs.product_id_effective, rp.product_id)` falls back
-- to the parent product itself - exactly mirroring what Consume() would do: an
-- unconvertible candidate is never admitted, so consumption (and, now, recipe costing)
-- falls through to the parent's own stock or a convertible sub product, never to the
-- unconvertible one.
--
-- WHY THIS ALSO FIXES recipes_pos_resolved's OWN COALESCE(qucr.factor, 1.0) WITHOUT
-- TOUCHING IT. recipes_pos_resolved's qucr join (lines ~210-213/277-280 of
-- db/pgsql/baseline/05_views_l3.sql) resolves the exact same triple this migration now
-- requires products_current_substitutions to have already resolved before choosing a
-- candidate: `qucr.product_id = product_id_effective`, `qucr.from_qu_id` = the parent's own
-- qu_id_stock (rp.product_id's own `p.qu_id_stock`, when a substitution occurred) and
-- `qucr.to_qu_id = p_effective.qu_id_stock`. cache__quantity_unit_conversions_resolved is
-- deterministic, so if this migration's own subquery found a resolved, positive factor for
-- that triple in order to pick the candidate, recipes_pos_resolved's later, separate join
-- on the identical triple is guaranteed to resolve to the same value - its
-- COALESCE(qucr.factor, 1.0) fallback therefore never fires for a genuine substitution
-- (`rp.product_id != p_effective.id`) after this fix; it only ever fires for the
-- self-substitution case (`product_id_effective` falls back to `rp.product_id`), where the
-- surrounding CASE already forces the factor to an explicit 1.0 regardless. No separate
-- edit to recipes_pos_resolved is needed, or would do anything observable.
--
-- FULFILMENT ALREADY AGREES. recipes_pos_resolved's `stock_amount`, `need_fulfilled`,
-- `missing_amount` and `need_fulfilled_with_shopping_list` columns read
-- `stock_current.amount_aggregated` for the recipe position's own `rp.product_id` (the
-- parent), not for `product_id_effective` - and migrations/0298.pgsql.sql (PR #628) already
-- made that aggregate exclude an unconvertible sub product's stock from the parent's
-- rollup. Fulfilment was therefore already consistent with D4 before this migration; this
-- migration brings cost and calories into agreement with it, rather than the other way
-- round.
--
-- WHY THIS IS SAFE FOR THE DIFFERENTIAL SUITE. products_current_substitutions is
-- SQLite-line and differential-tested by .devtools/pgsql/view-tests/02_products_and_pricing.sql's
-- "@views ... products_current_substitutions" fixture (see migrations/0279.pgsql.sql's own
-- header note on this). That fixture's only parent/sub-product pair (products 700/701)
-- share the same qu_id_stock (2, "Piece"), so the conversion this migration's new join
-- requires is the stock-unit-to-itself identity row
-- cache__quantity_unit_conversions_resolved already carries for every product at factor
-- 1.0 ("Priority 2" in quantity_unit_conversions_resolved's own recursive CTE,
-- db/pgsql/baseline/03_views_group2.sql) - it resolves regardless of this change, so the
-- fixture's output is unaffected and the SQLite side (which this migration does not and
-- cannot touch; SQLite is frozen at migrations 0265 per ADR-0008) keeps agreeing with it.
--
-- `AND CAST(x_qucr.factor AS NUMERIC) > 0` mirrors SubstitutionAwareProductIdWhereClause()'s
-- own cast: cache__quantity_unit_conversions_resolved.factor is declared TEXT
-- (db/pgsql/baseline/01_tables.sql), so a plain `> 0` does not type-check.

CREATE OR REPLACE VIEW products_current_substitutions AS

/*
	When a parent product is not in stock itself,
	any sub product (the next based on the default consume rule) should be used

	This view lists all parent products and in the column "product_id_effective" either itself,
	when the corresponding parent product is currently in stock itself, or otherwise the next sub product to use
*/

SELECT
	-1 AS "-1", -- Dummy
	p_sub.id AS parent_product_id,
	CASE WHEN p_sub.has_sub_products = 1 THEN
		CASE WHEN COALESCE(sc.amount, 0) = 0 THEN -- Parent product itself is currently not in stock => use the next convertible sub product
			(
			SELECT x_snu.product_id
			FROM products_resolved x_pr
			JOIN stock_next_use x_snu
				ON x_pr.sub_product_id = x_snu.product_id
			JOIN products x_p_sub
				ON x_p_sub.id = x_pr.sub_product_id
			-- Fix for issue #629: a sub product is admitted as a candidate only when its
			-- own stock unit resolves from the parent's own stock unit through
			-- cache__quantity_unit_conversions_resolved at a positive factor - the same
			-- admissibility rule the consume side applies
			-- (StockService::SubstitutionAwareProductIdWhereClause(), maintainer decision
			-- D4, issue #553). Excluded here, never counted 1:1 downstream in
			-- recipes_pos_resolved.
			JOIN cache__quantity_unit_conversions_resolved x_qucr
				ON x_qucr.product_id = x_pr.sub_product_id
				AND x_qucr.from_qu_id = p_sub.qu_id_stock
				AND x_qucr.to_qu_id = x_p_sub.qu_id_stock
				-- qucr.factor is declared TEXT (db/pgsql/baseline/01_tables.sql), so an
				-- explicit CAST(... AS NUMERIC) is needed for `> 0` to type-check at all -
				-- same cast SubstitutionAwareProductIdWhereClause() and
				-- migrations/0298.pgsql.sql use.
				AND CAST(x_qucr.factor AS NUMERIC) > 0
			WHERE x_pr.parent_product_id = p_sub.id
				AND x_pr.parent_product_id != x_pr.sub_product_id
			ORDER BY x_snu.priority DESC, x_snu.open DESC, x_snu.best_before_date ASC, x_snu.purchased_date ASC
			LIMIT 1
			)
		ELSE -- Parent product itself is currently in stock => use it
			p_sub.id
		END
	END AS product_id_effective
FROM products_view p
JOIN products_resolved pr
	ON p.id = pr.parent_product_id
JOIN products_view p_sub
	ON pr.sub_product_id = p_sub.id
JOIN stock_current sc
	ON p_sub.id = sc.product_id
WHERE p_sub.has_sub_products = 1;
