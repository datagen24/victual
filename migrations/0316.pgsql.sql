-- Issue #774, ADR-0045 (Proposed) option B, part 2: the price readers.
--
-- WHAT THIS CHANGES. P2's meanings take effect: a price of 0 is a known zero, NULL is unknown,
-- and a 0 that migration 0315 or an import marked (legacy_zero_price = 1) is unknown too.
--
--   stock_current gains value_incomplete: 1 when any of the product's own stock rows that value
--   sums has an unknown price. value itself keeps its number, the sum of the known prices
--   (option B, open question 7). A parent's flag reads its own stock only, as its value does
--   (open question 1). uihelper_stock_current_overview carries the column, and its two literal
--   branches gain the matching 0.
--
--   The accepted differences D1 to D4 (ADR-0045 gate 7; recorded there, ADR-0005 unchanged):
--   D1 products_price_history and products_average_price count a known 0 and skip an unknown
--      price, instead of skipping every price that is not above 0.
--   D2 products_last_purchased.price is NULL, not 0, for a product with no known purchase price;
--      products_current_price falls back from an unknown next-use price to the last known price
--      (open question 2) and is NULL when there is none.
--   D3 recipes_pos_resolved.costs is NULL for a position whose product has no known price.
--   D4 recipes_resolved.prices_incomplete is 1 only for a position whose cost is unknown, not
--      for one that costs exactly 0. A recipe with no resolved position stays 1, as before.
--
--   Both price caches are rebuilt from the rewritten views. Nothing is written to stock or
--   stock_log. The permission_fields rows for the two flags (gate 5) are db/pgsql/prices-seed.sql's
--   and are applied here too, because migration 0282 applied that file once.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

-- Each view below is its latest definition with only the change named above.

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
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision * COALESCE(qucr.factor::double precision, CASE WHEN pr.parent_product_id = pr.sub_product_id THEN 1.0::double precision ELSE 0.0::double precision END)), 0) AS amount_measured,
	-- The parent's own stock only, like value (open question 1)
	CASE WHEN EXISTS (SELECT 1 FROM stock WHERE product_id = pr.parent_product_id AND (price IS NULL OR legacy_zero_price = 1)) THEN 1 ELSE 0 END AS value_incomplete
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
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision), 0) AS amount_measured,
	MAX(CASE WHEN s.price IS NULL OR s.legacy_zero_price = 1 THEN 1 ELSE 0 END) AS value_incomplete
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

CREATE OR REPLACE VIEW uihelper_stock_current_overview AS
SELECT
	p.id,
	sc.amount_opened AS amount_opened,
	p.tare_weight AS tare_weight,
	p.enable_tare_weight_handling AS enable_tare_weight_handling,
	sc.amount AS amount,
	sc.value as value,
	sc.product_id AS product_id,
	COALESCE(sc.best_before_date, '2888-12-31') AS best_before_date,
	CASE WHEN EXISTS(SELECT id FROM stock_missing_products WHERE id = sc.product_id) THEN 1 ELSE 0 END AS product_missing,
	p.name AS product_name,
	pg.name AS product_group_name,
	sl.name AS default_store_name,
	CASE WHEN EXISTS(SELECT * FROM shopping_list WHERE shopping_list.product_id = sc.product_id) THEN 1 ELSE 0 END AS on_shopping_list,
	qu_stock.name AS qu_stock_name,
	qu_stock.name_plural AS qu_stock_name_plural,
	qu_purchase.name AS qu_purchase_name,
	qu_purchase.name_plural AS qu_purchase_name_plural,
	qu_consume.name AS qu_consume_name,
	qu_consume.name_plural AS qu_consume_name_plural,
	qu_price.name AS qu_price_name,
	qu_price.name_plural AS qu_price_name_plural,
	sc.is_aggregated_amount,
	sc.amount_opened_aggregated,
	sc.amount_aggregated,
	p.calories AS product_calories,
	sc.amount * p.calories AS calories,
	sc.amount_aggregated * p.calories AS calories_aggregated,
	p.quick_consume_amount,
	p.quick_consume_amount / p.qu_factor_consume_to_stock AS quick_consume_amount_qu_consume,
	p.quick_open_amount,
	p.quick_open_amount / p.qu_factor_consume_to_stock AS quick_open_amount_qu_consume,
	p.due_type,
	plp.purchased_date AS last_purchased,
	plp.price AS last_price,
	pap.price as average_price,
	p.min_stock_amount,
	pbcs.barcodes AS product_barcodes,
	p.description AS product_description,
	l.name AS product_default_location_name,
	p_parent.id AS parent_product_id,
	p_parent.name AS parent_product_name,
	p.picture_file_name AS product_picture_file_name,
	p.no_own_stock AS product_no_own_stock,
	p.qu_factor_purchase_to_stock AS product_qu_factor_purchase_to_stock,
	p.qu_factor_price_to_stock AS product_qu_factor_price_to_stock,
	sc.is_in_stock_or_below_min_stock,
	p.disable_open,
	p3.quick_refill_amount,
	p3.default_refill_location_id_from,
	p3.default_refill_location_id_to,
	sc.value_incomplete
FROM (
	-- Plan 28's migration 0275 (below this one in the merged tree) widened stock_current by
	-- one column, appending amount_measured after due_type. The `SELECT *` branch below picks
	-- that up automatically, but the two branches that stand in for a product with no stock
	-- row spell every column out as a literal and did not, before this fix - a UNION whose
	-- branches disagree on column count, found here while merging plan 28's landed PR into
	-- this branch (`each UNION query must have the same number of columns`, PostgreSQL
	-- 16.13). The extra `0` restores the count; a product with no stock has nothing measured
	-- either.
	SELECT *, 1 AS is_in_stock_or_below_min_stock
	FROM stock_current
	WHERE best_before_date IS NOT NULL
	UNION
	SELECT m.id, 0, 0, 0, NULL::date, 0, 0, 0, p.due_type, 0, 0, 1 AS is_in_stock_or_below_min_stock
	FROM stock_missing_products m
	JOIN products p
		ON m.id = p.id
	WHERE m.id NOT IN (SELECT product_id FROM stock_current)
	UNION
	SELECT p2.id, 0, 0, 0, NULL::date, 0, 0, 0, p2.due_type, 0, 0, 0 AS is_in_stock_or_below_min_stock
	FROM products p2
	WHERE active = 1
		AND p2.id NOT IN (SELECT product_id FROM stock_current UNION SELECT id FROM stock_missing_products)
	) sc
JOIN products_view p
	ON sc.product_id = p.id
JOIN locations l
	ON p.location_id = l.id
JOIN quantity_units qu_stock
	ON p.qu_id_stock = qu_stock.id
JOIN quantity_units qu_purchase
	ON p.qu_id_purchase = qu_purchase.id
JOIN quantity_units qu_consume
	ON p.qu_id_consume = qu_consume.id
JOIN quantity_units qu_price
	ON p.qu_id_price = qu_price.id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN shopping_locations sl
	ON p.shopping_location_id = sl.id
LEFT JOIN cache__products_last_purchased plp
	ON sc.product_id = plp.product_id
LEFT JOIN cache__products_average_price pap
	ON sc.product_id = pap.product_id
LEFT JOIN product_barcodes_comma_separated pbcs
	ON sc.product_id = pbcs.product_id
LEFT JOIN products p_parent
	ON p.parent_product_id = p_parent.id
-- Plain, not products_view: only the three new columns are wanted here, and products_view's
-- own `p.*` cannot supply them yet (see the comment above this view).
JOIN products p3
	ON p3.id = p.id
WHERE p.hide_on_stock_overview = 0;

CREATE OR REPLACE VIEW products_price_history AS
SELECT
	sl.product_id AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.price,
	COALESCE(sl.edited_origin_amount, sl.amount) AS amount,
	sl.purchased_date,
	sl.shopping_location_id,
	sl.transaction_type,
	sl.id AS stock_log_id
FROM (
	SELECT sl.*, CASE WHEN sl.transaction_type = 'stock-edit-new' THEN see.edited_origin_amount END AS edited_origin_amount
	FROM stock_log sl
	LEFT JOIN stock_edited_entries see
		ON sl.stock_id = see.stock_id
) sl
WHERE sl.undone = 0
	AND (
		(sl.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries)) -- Unedited origin entries
		OR (sl.transaction_type = 'stock-edit-new' AND sl.id IN (SELECT stock_log_id_of_newest_edited_entry FROM stock_edited_entries)) -- Edited origin entries => take the newest "stock-edit-new" one
	)
	AND sl.price IS NOT NULL
	AND sl.legacy_zero_price = 0
	AND COALESCE(sl.amount, 0) > 0;

CREATE OR REPLACE VIEW products_last_purchased AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.amount,
	sl.best_before_date,
	sl.purchased_date,
	sl.location_id,
	sl.shopping_location_id,
	(SELECT price FROM products_price_history WHERE product_id = sl.product_id ORDER BY purchased_date DESC, stock_log_id DESC LIMIT 1) AS price
FROM stock_log sl
JOIN (
	/*
		This subquery gets the ID of the stock_log row (per product) which referes to the last purchase transaction,
		while taking undone and edited transactions into account
	*/
	SELECT
		sl1.product_id,
		MAX(sl1.id) stock_log_id_of_last_purchase
	FROM stock_log sl1
	JOIN (
		/*
			This subquery finds the last purchased date per product,
			there can be multiple purchase transactions per day, therefore a JOIN by purchased_date
			for the outer query on this and then take MAX id of stock_log (of that day)
		*/
		SELECT
			sl2.product_id,
			MAX(sl2.purchased_date) AS last_purchased_date
		FROM stock_log sl2
		WHERE sl2.undone = 0
			AND (
				(sl2.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl2.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries))
				OR (sl2.transaction_type = 'stock-edit-new' AND sl2.stock_id IN (SELECT stock_id FROM stock_edited_entries) AND sl2.id IN (SELECT stock_log_id_of_newest_edited_entry FROM stock_edited_entries))
			)
		GROUP BY sl2.product_id
	) x2
		ON sl1.product_id = x2.product_id
		AND sl1.purchased_date = x2.last_purchased_date
	WHERE sl1.undone = 0
		AND (
			(sl1.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl1.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries))
			OR (sl1.transaction_type = 'stock-edit-new' AND sl1.stock_id IN (SELECT stock_id FROM stock_edited_entries) AND sl1.id IN (SELECT stock_log_id_of_newest_edited_entry FROM stock_edited_entries))
		)
	GROUP BY sl1.product_id
) x
	ON sl.product_id = x.product_id
	AND sl.id = x.stock_log_id_of_last_purchase;

CREATE OR REPLACE VIEW products_average_price AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	SUM(COALESCE(sl.edited_origin_amount, sl.amount) * sl.price) / SUM(COALESCE(sl.edited_origin_amount, sl.amount)) as price
FROM (
	SELECT sl.*, CASE WHEN sl.transaction_type = 'stock-edit-new' THEN see.edited_origin_amount END AS edited_origin_amount
	FROM stock_log sl
	LEFT JOIN stock_edited_entries see
		ON sl.stock_id = see.stock_id
) sl
WHERE sl.undone = 0
	AND (
		(sl.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries)) -- Unedited origin entries
		OR (sl.transaction_type = 'stock-edit-new' AND sl.id IN (SELECT stock_log_id_of_newest_edited_entry FROM stock_edited_entries)) -- Edited origin entries => take the newest "stock-edit-new" one
	)
	AND sl.price IS NOT NULL
	AND sl.legacy_zero_price = 0
	AND COALESCE(sl.amount, 0) > 0
GROUP BY sl.product_id;

CREATE OR REPLACE VIEW products_current_price AS

/*
	Current price per product,
	based on the stock entry to use next,
	or on the last price if the product is currently not in stock
*/

-- NOTE for reviewers: the SQLite original selects `price` as a bare column alongside
-- `MAX(priority)` in the same GROUP BY product_id query, relying on the documented SQLite
-- behaviour that a bare column takes its value from the row that produced the sole MIN/MAX
-- aggregate in the query (https://www.sqlite.org/lang_select.html#bare_columns_in_an_aggregate_query).
-- PostgreSQL has no equivalent, so this is rewritten as DISTINCT ON, ordered exactly like
-- the original's ORDER BY, to pick the price from the same (highest-priority) row per
-- product_id. `priority` is unique per product_id (it's a per-partition ROW_NUMBER() in
-- stock_next_use), so the additional tie-break columns can never actually be needed, but
-- are kept for fidelity with the original ORDER BY.
SELECT
	-1 AS id, -- Dummy,
	p.id AS product_id,
	COALESCE(snu.price, plp.price) AS price
FROM products p
LEFT JOIN (
	SELECT DISTINCT ON (product_id)
		product_id,
		CASE WHEN legacy_zero_price = 1 THEN NULL ELSE price END AS price
	FROM stock_next_use
	ORDER BY product_id, priority DESC, open DESC, best_before_date ASC, purchased_date ASC
	) snu
	ON p.id = snu.product_id
LEFT JOIN cache__products_last_purchased plp
	ON p.id = plp.product_id;

CREATE OR REPLACE VIEW recipes_pos_resolved AS

-- Multiplication by 1.0 to force conversion to float (REAL)

-- Resolved amount (here used multiple times):
-- CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END

-- NOTE for reviewers, two translations beyond the mechanical IFNULL -> COALESCE:
--   1. `cache__quantity_unit_conversions_resolved.factor` is TEXT upstream (see the note on
--      products_view in 03_views_group2.sql). `IFNULL(qucr.factor, 1.0)` therefore becomes
--      `COALESCE(qucr.factor::double precision, 1.0::double precision)` everywhere it
--      appears - without the cast, COALESCE(text, numeric) is a hard error in PostgreSQL
--      ("COALESCE types text and numeric cannot be matched"), not merely a type change.
--   2. `ROUND(<double expr>, 2)` (2-argument form) does not exist for double precision in
--      PostgreSQL, only round(numeric, integer) does - see the porting rules doc and the
--      identical fix already applied in stock_current/stock_current_location_content. Both
--      ROUND() calls in need_fulfilled_with_shopping_list are rewritten as
--      ROUND(CAST(<expr> AS numeric), 2); the NUMERIC result is only ever compared, never
--      projected (the projected value is the CASE's integer 1/0), so no cast back to double
--      precision is needed here.
SELECT
	r.id AS recipe_id,
	rp.id AS recipe_pos_id,
	rp.product_id AS product_id,
	CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END AS recipe_amount,
	COALESCE(sc.amount_aggregated, 0) AS stock_amount,
	CASE WHEN COALESCE(sc.amount_aggregated, 0) >= CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN 0.00000001 ELSE CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END END THEN 1 ELSE 0 END AS need_fulfilled,
	CASE WHEN COALESCE(sc.amount_aggregated, 0) - CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN 0.00000001 ELSE CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END END < 0 THEN ABS(COALESCE(sc.amount_aggregated, 0) - (CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END)) ELSE 0 END AS missing_amount,
	COALESCE(sl.amount, 0) AS amount_on_shopping_list,
	CASE WHEN ROUND(CAST(COALESCE(sc.amount_aggregated, 0) + CASE WHEN r.not_check_shoppinglist = 1 THEN 0 ELSE COALESCE(sl.amount, 0) END AS numeric), 2) >= ROUND(CAST(CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN 0.00000001 ELSE CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END END AS numeric), 2) THEN 1 ELSE 0 END AS need_fulfilled_with_shopping_list,
	rp.qu_id,
	(r.desired_servings*1.0 / r.base_servings*1.0) * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * rp.amount * pcp.price * rp.price_factor * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS costs,
	CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN 0 ELSE 1 END AS is_nested_recipe_pos,
	rp.ingredient_group,
	pg.name as product_group,
	rp.id, -- Just a dummy id column
	r.type as recipe_type,
	rnr.includes_recipe_id as child_recipe_id,
	rp.note,
	rp.variable_amount AS recipe_variable_amount,
	rp.only_check_single_unit_in_stock,
	rp.amount * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END / r.base_servings*1.0 * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * COALESCE(p_effective.calories, 0) * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS calories,
	p.active AS product_active,
	CASE pvs.current_due_status
		WHEN 'ok' THEN 0
		WHEN 'due_soon' THEN 1
		WHEN 'overdue' THEN 10
		WHEN 'expired' THEN 20
	END AS due_score,
	COALESCE(pcs.product_id_effective, rp.product_id) AS product_id_effective,
	p.name AS product_name
FROM recipes r
JOIN recipes_nestings_resolved rnr
	ON r.id = rnr.recipe_id
JOIN recipes rnrr
	ON rnr.includes_recipe_id = rnrr.id
JOIN recipes_pos rp
	ON rnr.includes_recipe_id = rp.recipe_id
JOIN products p
	ON rp.product_id = p.id
JOIN products_volatile_status pvs
	ON rp.product_id = pvs.product_id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN (
	SELECT product_id, SUM(amount) AS amount
	FROM shopping_list
	GROUP BY product_id) sl
	ON rp.product_id = sl.product_id
LEFT JOIN stock_current sc
	ON rp.product_id = sc.product_id
LEFT JOIN products_current_substitutions pcs
	ON rp.product_id = pcs.parent_product_id
LEFT JOIN products_current_price pcp
	ON COALESCE(pcs.product_id_effective, rp.product_id) = pcp.product_id
LEFT JOIN products p_effective
	ON COALESCE(pcs.product_id_effective, rp.product_id) = p_effective.id
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON COALESCE(pcs.product_id_effective, rp.product_id) = qucr.product_id
	AND CASE WHEN rp.product_id != p_effective.id THEN p.qu_id_stock ELSE rp.qu_id END = qucr.from_qu_id
	AND COALESCE(p_effective.qu_id_stock, p.qu_id_stock) = qucr.to_qu_id
WHERE rp.not_check_stock_fulfillment = 0

UNION

-- Just add all recipe positions which should not be checked against stock with fulfilled need

SELECT
	r.id AS recipe_id,
	rp.id AS recipe_pos_id,
	rp.product_id AS product_id,
	CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END AS recipe_amount,
	COALESCE(sc.amount_aggregated, 0) AS stock_amount,
	1 AS need_fulfilled,
	0 AS missing_amount,
	COALESCE(sl.amount, 0) AS amount_on_shopping_list,
	1 AS need_fulfilled_with_shopping_list,
	rp.qu_id,
	(r.desired_servings*1.0 / r.base_servings*1.0) * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * rp.amount * pcp.price * rp.price_factor * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS costs,
	CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN 0 ELSE 1 END AS is_nested_recipe_pos,
	rp.ingredient_group,
	pg.name as product_group,
	rp.id, -- Just a dummy id column
	r.type as recipe_type,
	rnr.includes_recipe_id as child_recipe_id,
	rp.note,
	rp.variable_amount AS recipe_variable_amount,
	rp.only_check_single_unit_in_stock,
	rp.amount * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END / r.base_servings*1.0 * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * COALESCE(p_effective.calories, 0) * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS calories,
	p.active AS product_active,
	CASE pvs.current_due_status
		WHEN 'ok' THEN 0
		WHEN 'due_soon' THEN 1
		WHEN 'overdue' THEN 10
		WHEN 'expired' THEN 20
	END AS due_score,
	COALESCE(pcs.product_id_effective, rp.product_id) AS product_id_effective,
	p.name AS product_name
FROM recipes r
JOIN recipes_nestings_resolved rnr
	ON r.id = rnr.recipe_id
JOIN recipes rnrr
	ON rnr.includes_recipe_id = rnrr.id
JOIN recipes_pos rp
	ON rnr.includes_recipe_id = rp.recipe_id
JOIN products p
	ON rp.product_id = p.id
JOIN products_volatile_status pvs
	ON rp.product_id = pvs.product_id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN (
	SELECT product_id, SUM(amount) AS amount
	FROM shopping_list
	GROUP BY product_id) sl
	ON rp.product_id = sl.product_id
LEFT JOIN stock_current sc
	ON rp.product_id = sc.product_id
LEFT JOIN products_current_substitutions pcs
	ON rp.product_id = pcs.parent_product_id
LEFT JOIN products_current_price pcp
	ON COALESCE(pcs.product_id_effective, rp.product_id) = pcp.product_id
LEFT JOIN products p_effective
	ON COALESCE(pcs.product_id_effective, rp.product_id) = p_effective.id
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON COALESCE(pcs.product_id_effective, rp.product_id) = qucr.product_id
	AND CASE WHEN rp.product_id != p_effective.id THEN p.qu_id_stock ELSE rp.qu_id END = qucr.from_qu_id
	AND COALESCE(p_effective.qu_id_stock, p.qu_id_stock) = qucr.to_qu_id
WHERE rp.not_check_stock_fulfillment = 1;

CREATE OR REPLACE VIEW recipes_resolved AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	r.id AS recipe_id,
	COALESCE(MIN(rpr.need_fulfilled), 1) AS need_fulfilled,
	COALESCE(MIN(rpr.need_fulfilled_with_shopping_list), 1) AS need_fulfilled_with_shopping_list,
	COALESCE(rmpc.missing_products_count, 0) AS missing_products_count,
	COALESCE(SUM(rpr.costs), 0) AS costs,
	COALESCE(SUM(rpr.costs) / CASE WHEN COALESCE(r.desired_servings, 0) = 0 THEN 1 ELSE r.desired_servings END, 0) AS costs_per_serving,
	COALESCE(SUM(rpr.calories), 0) AS calories,
	COALESCE(CAST(SUM(rpr.due_score) AS INTEGER), 0) AS due_score,
	string_agg(rpr.product_name, ',') AS product_names_comma_separated,
	CASE WHEN bool_or(rpr.costs IS NULL) THEN 1 ELSE 0 END AS prices_incomplete
FROM recipes r
LEFT JOIN recipes_pos_resolved rpr
	ON r.id = rpr.recipe_id
LEFT JOIN recipes_missing_product_counts rmpc
	ON r.id = rmpc.recipe_id
GROUP BY r.id, rmpc.missing_products_count;

-- The caches hold what the rewritten views compute (DatabaseImporter::RebuildPriceCaches() does
-- the same after an import).
DELETE FROM cache__products_average_price;
INSERT INTO cache__products_average_price (product_id, price)
SELECT product_id, price FROM products_average_price;
DELETE FROM cache__products_last_purchased;
INSERT INTO cache__products_last_purchased (product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id FROM products_last_purchased;

INSERT INTO permission_fields (permission_name, entity, field) VALUES
	('STOCK_PRICES_VIEW', 'stock_current', 'value_incomplete'),
	('STOCK_PRICES_VIEW', 'product_details', 'stock_value_incomplete'),
	('STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'value_incomplete')
ON CONFLICT DO NOTHING;
