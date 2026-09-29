-- Issue #629 (#487 remediation): migrations/0300.pgsql.sql redefines
-- products_current_substitutions so a sub product is only ever chosen as
-- product_id_effective when a resolved, POSITIVE quantity-unit conversion exists from the
-- parent's own stock unit to the candidate's own stock unit -
-- cache__quantity_unit_conversions_resolved, joined the same way and in the same
-- direction StockService::SubstitutionAwareProductIdWhereClause() admits a substitution
-- candidate on the consume side (maintainer decision D4, issue #553). Before this
-- migration, the "next sub product to use" subquery ordered candidates purely by
-- stock_next_use's consume priority, with no regard to unit convertibility, and
-- recipes_pos_resolved's own `COALESCE(qucr.factor, 1.0)` fallback then priced and costed
-- an unconvertible candidate 1:1.
--
-- Two parents, one for each of D4's two exclusion cases:
--
--   - "M629 No-Conversion Parent" has two sub products: one with a resolved, positive
--     conversion from the parent's stock unit to its own ("M629 Convertible Sub"), and one
--     with NO resolved conversion at all ("M629 No-Conversion Sub"). The no-conversion sub
--     has the earlier best_before_date, so stock_next_use's own priority ordering prefers
--     it over the convertible one - which is exactly what makes the pre-fix behaviour wrong
--     rather than accidentally right.
--   - "M629 Negative-Factor Parent" has a single sub product ("M629 Negative-Factor Sub")
--     whose only resolved conversion has a NEGATIVE factor - reachable because nothing puts
--     a CHECK on quantity_unit_conversions.factor (see
--     StockService::SubstitutionAwareProductIdWhereClause()'s own comment,
--     services/StockService.php ~2074-2080). This parent is otherwise the only sub
--     product available, so it also tests the "no convertible candidate exists at all"
--     path: product_id_effective must fall back to the parent itself.
--
-- Both parents are never themselves in stock, which is what makes
-- products_current_substitutions look at their sub products' stock_next_use rows at all
-- (db/pgsql/baseline/05_views_l2.sql: "Parent product itself is currently not in stock =>
-- use the next sub product").
--
-- Each parent gets its own one-ingredient recipe so recipes_pos_resolved's costs/calories
-- and stock_amount/need_fulfilled/missing_amount can be asserted directly - the same
-- columns the recipe page and /api/objects/recipes_pos_resolved read.

SELECT plan(11);

INSERT INTO locations (name) VALUES ('M629 location');

INSERT INTO quantity_units (name, name_plural) VALUES ('M629 Parent Unit', 'M629 Parent Units');
INSERT INTO quantity_units (name, name_plural) VALUES ('M629 Convertible Unit', 'M629 Convertible Units');
INSERT INTO quantity_units (name, name_plural) VALUES ('M629 No-Conversion Unit', 'M629 No-Conversion Units');
INSERT INTO quantity_units (name, name_plural) VALUES ('M629 Negative-Factor Unit', 'M629 Negative-Factor Units');

-- ----------------------------------------------------------------------------------------
-- Case 1: a convertible sub product competes with one that has no resolved conversion at
-- all, and the unconvertible one would otherwise win stock_next_use's own priority order.
-- ----------------------------------------------------------------------------------------

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
VALUES (
	'M629 No-Conversion Parent',
	(SELECT id FROM locations WHERE name = 'M629 location'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit')
);

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, parent_product_id, calories)
VALUES (
	'M629 Convertible Sub',
	(SELECT id FROM locations WHERE name = 'M629 location'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Convertible Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Convertible Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Convertible Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Convertible Unit'),
	(SELECT id FROM products WHERE name = 'M629 No-Conversion Parent'),
	40
);

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, parent_product_id, calories)
VALUES (
	'M629 No-Conversion Sub',
	(SELECT id FROM locations WHERE name = 'M629 location'),
	(SELECT id FROM quantity_units WHERE name = 'M629 No-Conversion Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 No-Conversion Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 No-Conversion Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 No-Conversion Unit'),
	(SELECT id FROM products WHERE name = 'M629 No-Conversion Parent'),
	1000
);

-- One M629 Parent Unit converts to two M629 Convertible Units (product-specific, positive).
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Convertible Unit'),
	2,
	(SELECT id FROM products WHERE name = 'M629 Convertible Sub')
);
-- Deliberately no quantity_unit_conversions row at all from Parent Unit to No-Conversion
-- Unit for "M629 No-Conversion Sub" - that is the whole point of this case.

-- The no-conversion sub's best_before_date is earlier, so stock_next_use ("first due
-- first") prefers it over the convertible sub before this migration's fix is applied.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, price)
VALUES (
	(SELECT id FROM products WHERE name = 'M629 No-Conversion Sub'),
	3, '2030-01-01', '2026-01-01', 'm629-no-conversion-sub', (SELECT id FROM locations WHERE name = 'M629 location'), 999
);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, price)
VALUES (
	(SELECT id FROM products WHERE name = 'M629 Convertible Sub'),
	5, '2030-06-01', '2026-01-01', 'm629-convertible-sub', (SELECT id FROM locations WHERE name = 'M629 location'), 4
);

SELECT is(
	(SELECT product_id_effective FROM products_current_substitutions
		WHERE parent_product_id = (SELECT id FROM products WHERE name = 'M629 No-Conversion Parent')),
	(SELECT id FROM products WHERE name = 'M629 Convertible Sub'),
	'products_current_substitutions skips the sub product with no resolved conversion - even though stock_next_use''s own priority order would otherwise prefer it - and picks the convertible one instead (issue #629)'
);

INSERT INTO recipes (name, base_servings) VALUES ('M629 No-Conversion Recipe', 1);
UPDATE recipes SET desired_servings = 1 WHERE name = 'M629 No-Conversion Recipe';
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id)
VALUES (
	(SELECT id FROM recipes WHERE name = 'M629 No-Conversion Recipe'),
	(SELECT id FROM products WHERE name = 'M629 No-Conversion Parent'),
	1,
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit')
);

-- costs = 1 recipe-unit * price-per-convertible-unit (4) * factor (2 convertible units per
-- parent unit) = 8. Before the fix this read 999 (the no-conversion sub's own price, 1:1).
SELECT is(
	(SELECT costs FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 No-Conversion Recipe')),
	8.0::double precision,
	'recipes_pos_resolved.costs prices the convertible substitute at its real conversion factor, not the no-conversion sub at 1:1 (issue #629)'
);
-- calories = 1 * 40 calories/convertible-unit * factor (2) = 80. Before the fix this read
-- 1000 (the no-conversion sub's own calories, 1:1).
SELECT is(
	(SELECT calories FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 No-Conversion Recipe')),
	80.0::double precision,
	'recipes_pos_resolved.calories credits the convertible substitute at its real conversion factor, not the no-conversion sub at 1:1 (issue #629)'
);
-- Fulfilment already agreed before this migration (migrations/0298.pgsql.sql,
-- stock_current.amount_aggregated), asserted here so the same row shows costs/calories and
-- fulfilment in agreement: stock_current excludes the no-conversion sub's amount (3) from
-- the parent's aggregate and includes only the convertible sub's amount converted into the
-- parent's own unit (5 convertible units / 2 per parent unit = 2.5 parent units), so 1
-- parent unit is available and the recipe's single-unit need is fulfilled.
SELECT is(
	(SELECT stock_amount FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 No-Conversion Recipe')),
	2.5::double precision,
	'recipes_pos_resolved.stock_amount already excluded the no-conversion sub''s stock from the parent''s aggregate (migrations/0298.pgsql.sql) and agrees with the now-fixed costs/calories'
);
SELECT is(
	(SELECT need_fulfilled FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 No-Conversion Recipe')),
	1,
	'...and the recipe reads as fulfilled from that same, already-correct aggregate'
);

-- ----------------------------------------------------------------------------------------
-- Case 2: the only sub product's only resolved conversion has a NEGATIVE factor - D4's
-- other exclusion case - and no other sub product exists to fall back to, so
-- product_id_effective must fall back to the parent product itself.
-- ----------------------------------------------------------------------------------------

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, calories, active)
VALUES (
	'M629 Negative-Factor Parent',
	(SELECT id FROM locations WHERE name = 'M629 location'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	5,
	1
);

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, parent_product_id, calories)
VALUES (
	'M629 Negative-Factor Sub',
	(SELECT id FROM locations WHERE name = 'M629 location'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Negative-Factor Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Negative-Factor Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Negative-Factor Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Negative-Factor Unit'),
	(SELECT id FROM products WHERE name = 'M629 Negative-Factor Parent'),
	500
);

-- The only resolved conversion between the parent's unit and the sub's is negative -
-- reachable because quantity_unit_conversions.factor carries no CHECK constraint (see
-- StockService::SubstitutionAwareProductIdWhereClause()'s own comment).
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit'),
	(SELECT id FROM quantity_units WHERE name = 'M629 Negative-Factor Unit'),
	-1,
	(SELECT id FROM products WHERE name = 'M629 Negative-Factor Sub')
);

INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, price)
VALUES (
	(SELECT id FROM products WHERE name = 'M629 Negative-Factor Sub'),
	4, '2030-01-01', '2026-01-01', 'm629-negative-factor-sub', (SELECT id FROM locations WHERE name = 'M629 location'), 50
);

-- products_current_substitutions itself reads NULL here (the LIMIT 1 subquery finds no
-- convertible candidate at all) - it is recipes_pos_resolved's own
-- COALESCE(pcs.product_id_effective, rp.product_id) that supplies the parent fallback,
-- asserted below via costs/calories/stock_amount reading as the parent's own.
SELECT is(
	(SELECT product_id_effective FROM products_current_substitutions
		WHERE parent_product_id = (SELECT id FROM products WHERE name = 'M629 Negative-Factor Parent')),
	NULL::integer,
	'products_current_substitutions finds no convertible candidate (NULL) when its only sub product''s only resolved conversion has a non-positive factor and no other sub product exists (issue #629, D4)'
);

INSERT INTO recipes (name, base_servings) VALUES ('M629 Negative-Factor Recipe', 1);
UPDATE recipes SET desired_servings = 1 WHERE name = 'M629 Negative-Factor Recipe';
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id)
VALUES (
	(SELECT id FROM recipes WHERE name = 'M629 Negative-Factor Recipe'),
	(SELECT id FROM products WHERE name = 'M629 Negative-Factor Parent'),
	1,
	(SELECT id FROM quantity_units WHERE name = 'M629 Parent Unit')
);

-- The parent has never been purchased, so products_current_price has no row for it and
-- pcp.price is NULL - costs reads 0 (COALESCE(pcp.price, 0)), never the negative-factor
-- sub's price (50) at any factor.
SELECT is(
	(SELECT costs FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 Negative-Factor Recipe')),
	0.0::double precision,
	'recipes_pos_resolved.costs falls back to the (unpriced) parent product, never to the negative-factor sub 1:1 (issue #629, D4)'
);
-- product_id_effective = rp.product_id here, so the CASE's ELSE branch applies and
-- calories reads the parent's own calories (5) unscaled, never the sub's (500).
SELECT is(
	(SELECT calories FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 Negative-Factor Recipe')),
	5.0::double precision,
	'recipes_pos_resolved.calories falls back to the parent product''s own calories, never to the negative-factor sub 1:1 (issue #629, D4)'
);
-- stock_current already excludes the negative-factor sub's stock from the parent's
-- aggregate (migrations/0298.pgsql.sql), so the parent reads as having none of its own
-- ingredient in stock and the recipe is not fulfilled.
SELECT is(
	(SELECT stock_amount FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 Negative-Factor Recipe')),
	0.0::double precision,
	'recipes_pos_resolved.stock_amount agrees: the negative-factor sub''s stock was already excluded from the parent''s aggregate'
);
SELECT is(
	(SELECT need_fulfilled FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 Negative-Factor Recipe')),
	0,
	'...and the recipe reads as unfulfilled, in agreement with the now-corrected costs/calories'
);
SELECT is(
	(SELECT missing_amount FROM recipes_pos_resolved
		WHERE recipe_id = (SELECT id FROM recipes WHERE name = 'M629 Negative-Factor Recipe')),
	1.0::double precision,
	'...and missing_amount reports the whole ingredient amount missing, not offset by the negative-factor sub''s stock'
);

SELECT * FROM finish();
