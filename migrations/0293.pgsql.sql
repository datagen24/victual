-- Issue #508 (M8, #487 remediation), ADR-0034: a product group's minimum stock now counts
-- its descendant groups, not only its direct members.
--
-- THE DEFECT ADR-0034 DESCRIBES. migrations/0268.pgsql.sql's product_groups_missing joined
-- its member subquery to the outer group directly on product_group_id (member.product_group_id
-- = pg.id). A product filed in a subgroup contributed nothing to an ancestor group's
-- shortfall: a parent with a minimum of 1 still reported short by 1 even while a child group
-- held 5 units, because nothing in the query ever looked past the product's own group.
--
-- THE FIX. The member join now goes through product_groups_resolved
-- (migrations/0278.pgsql.sql), the closure view plan 30 built for exactly this kind of
-- ancestor/descendant reach: (ancestor_product_group_id, descendant_product_group_id, depth,
-- path), including every group paired with itself at depth 0. Matching a product's own
-- product_group_id against resolved.descendant_product_group_id and reading
-- resolved.ancestor_product_group_id as the group the product's stock counts toward means a
-- product still counts toward its own direct group (the depth-0 self-pair) and now also
-- toward every ancestor above it. This is rollup.sql (.devtools/adr0034/), the ADR's proven
-- acceptance experiment, applied here as the real migration rather than left as a disposable
-- CREATE OR REPLACE VIEW run inside a rolled-back transaction.
--
-- WHAT DOES NOT CHANGE. The view's four columns (id, name, min_stock_amount, amount_missing)
-- are untouched, so nothing that reads it - controllers/StockController.php's overview join,
-- controllers/Users/EntityReadPolicy.php's permission entry, the /objects/product_groups_missing
-- API - needs to change. Opened-stock exclusion (treat_opened_as_out_of_stock), the LEFT JOIN
-- that keeps an all-inactive or empty group from vanishing instead of reporting short by its
-- whole minimum, the outer active-group and amount_missing > 0 filters, and reading the stock
-- ledger rather than stock_current (migrations/0268.pgsql.sql's double-counting hazard) all
-- carry over unchanged from the direct-membership view; only the member join's reach changes.
--
-- GROUP ACTIVITY DURING TRAVERSAL. ADR-0034's Decision is explicit: active products under
-- inactive subgroups still contribute to active ancestors, and an inactive intermediate group
-- does not stop traversal. product_groups_resolved itself does not filter on product_groups.active
-- (migrations/0278.pgsql.sql), so the ancestor/descendant pairs it hands back already include
-- every group in the subtree regardless of activity; only the product's own COALESCE(p.active,
-- 0) = 1 filter and the outer group's COALESCE(pg.active, 0) = 1 filter (controlling whether
-- THAT group reports its own shortfall) apply. Nothing here adds a group-activity filter to
-- the join itself - doing so would be the mistake ADR-0034 rules out.
--
-- A PRODUCT COUNTED TOWARD BOTH A CHILD'S AND A PARENT'S MINIMUM. ADR-0034's Consequences
-- names this as expected, not a defect: one product's stock can be the entire reason both its
-- own direct group and every ancestor group report a shortfall. Nothing consumes these rows
-- automatically (plan 03's "do not auto-add" is unchanged by this migration), so this is
-- display duplication, not a double deduction from stock.
--
-- See docs/adr/0034-product-group-minimum-counts-descendant-groups.md and
-- .devtools/adr0034/README.md for the acceptance evidence (sixteen pgTAP checks pass against
-- rollup.sql; nine of the same checks fail against the view this migration replaces).

CREATE OR REPLACE VIEW product_groups_missing AS
SELECT *
FROM (
	SELECT
		pg.id,
		pg.name,
		pg.min_stock_amount,
		pg.min_stock_amount - COALESCE(SUM(member.effective_amount), 0) AS amount_missing
	FROM product_groups pg

	-- LEFT, and the member filter lives in here rather than in the outer WHERE. A group with
	-- no active members anywhere in its subtree - or no subtree at all - joins to nothing, the
	-- SUM is NULL, the COALESCE makes it 0, and the group is short by its whole minimum.
	LEFT JOIN (
		SELECT
			p.id,
			resolved.ancestor_product_group_id AS product_group_id,
			COALESCE(SUM(s.amount), 0)
				- CASE WHEN p.treat_opened_as_out_of_stock = 1
					THEN COALESCE(SUM(CASE WHEN s.open = 1 THEN s.amount ELSE 0 END), 0)
					ELSE 0 END AS effective_amount
		FROM products p
		JOIN product_groups_resolved resolved
			ON resolved.descendant_product_group_id = p.product_group_id
		LEFT JOIN stock s
			ON s.product_id = p.id
		WHERE COALESCE(p.active, 0) = 1
		GROUP BY p.id, resolved.ancestor_product_group_id, p.treat_opened_as_out_of_stock
	) member
		ON member.product_group_id = pg.id

	WHERE pg.min_stock_amount != 0
		AND COALESCE(pg.active, 0) = 1
	GROUP BY pg.id, pg.name, pg.min_stock_amount
) x
WHERE x.amount_missing > 0;
