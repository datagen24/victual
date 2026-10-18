-- migrations/0316.pgsql.sql (issue #774, ADR-0045 option B): the rewritten price readers.
--
-- stock_current's value_incomplete; the accepted differences D1 (price history and average
-- count a known 0 and skip an unknown price), D2 (no known purchase price is NULL; an unknown
-- next-use price falls back to the last known one), D3 (an unknown price is an unknown recipe
-- position cost) and D4 (prices_incomplete marks an unknown cost, not a zero one). The routes are
-- covered by tests/Pgsql/StockPriceSemanticsTest.php.
--
-- The whole file runs in one transaction and rolls back.

BEGIN;

SELECT plan(14);

INSERT INTO locations (id, name) VALUES (93900, 'P39 location');
INSERT INTO quantity_units (id, name) VALUES (93900, 'P39 unit');
INSERT INTO users (id, username, password) VALUES (93900, 'p39 user', 'fixture');
-- 93901 every entry unknown; 93902 mixed; 93903 a known 0 and a marked 0 and 4; 93904 unknown next entry, known later one
INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
SELECT g, 'P39 product ' || g, 93900, 93900, 93900, 93900, 93900 FROM generate_series(93901, 93904) g;

CREATE FUNCTION pg_temp.book(p INTEGER, sid TEXT, price DOUBLE PRECISION, day DATE, marked SMALLINT DEFAULT 0) RETURNS VOID AS $$
BEGIN
	INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id, legacy_zero_price)
	VALUES (p, 1, '2999-12-31', day, sid, price, 0, 93900, marked);
	INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, user_id, transaction_id, legacy_zero_price)
	VALUES (p, 1, '2999-12-31', day, sid, 'purchase', price, 93900, 93900, sid, marked);
END;
$$ LANGUAGE plpgsql;

SELECT pg_temp.book(93901, 'p39-a', NULL, '2026-09-01');
SELECT pg_temp.book(93902, 'p39-b1', 2, '2026-09-01');
SELECT pg_temp.book(93902, 'p39-b2', NULL, '2026-09-02');
SELECT pg_temp.book(93903, 'p39-e1', 0, '2026-09-01');
SELECT pg_temp.book(93903, 'p39-e2', 0, '2026-09-02', 1::SMALLINT);
SELECT pg_temp.book(93903, 'p39-e3', 4, '2026-09-03');
SELECT pg_temp.book(93904, 'p39-f1', NULL, '2026-09-01');
SELECT pg_temp.book(93904, 'p39-f2', 5, '2026-09-02');

-- stock_current
SELECT results_eq($$SELECT value, value_incomplete FROM stock_current WHERE product_id = 93901$$, $$VALUES (0::DOUBLE PRECISION, 1)$$, 'every entry unknown: value 0, incomplete');
SELECT results_eq($$SELECT value, value_incomplete FROM stock_current WHERE product_id = 93902$$, $$VALUES (2::DOUBLE PRECISION, 1)$$, 'mixed: the known subtotal, incomplete');
SELECT results_eq($$SELECT value, value_incomplete FROM stock_current WHERE product_id = 93903$$, $$VALUES (4::DOUBLE PRECISION, 1)$$, 'a marked 0 is unknown, a known 0 is not');
SELECT is((SELECT value_incomplete FROM uihelper_stock_current_overview WHERE product_id = 93902), 1, 'the overview carries the flag');
SELECT is((SELECT value_incomplete FROM uihelper_stock_current_overview WHERE product_id = (SELECT MIN(id) FROM products WHERE id NOT IN (SELECT product_id FROM stock) AND active = 1 AND hide_on_stock_overview = 0)), 0, 'and a product with no stock is complete');

-- D1
SELECT bag_eq($$SELECT price FROM products_price_history WHERE product_id = 93903$$, $$VALUES (0::DOUBLE PRECISION), (4::DOUBLE PRECISION)$$, 'D1: the history keeps a known 0 and skips the marked 0');
SELECT is((SELECT price FROM products_average_price WHERE product_id = 93903), 2::DOUBLE PRECISION, 'D1: the average of the known 0 and 4');
SELECT is((SELECT count(*) FROM products_average_price WHERE product_id = 93901), 0::BIGINT, 'D1: no known price, no average');

-- D2
SELECT is((SELECT price FROM products_last_purchased WHERE product_id = 93901), NULL::DOUBLE PRECISION, 'D2: no known purchase price is NULL, not 0');
SELECT lives_ok($$SELECT reconcile_stock_log_cache()$$, 'the caches rebuild from the rewritten views');
SELECT is((SELECT price FROM products_current_price WHERE product_id = 93904), 5::DOUBLE PRECISION, 'D2: an unknown next-use price falls back to the last known one');
SELECT is((SELECT price FROM products_current_price WHERE product_id = 93901), NULL::DOUBLE PRECISION, 'D2: and is NULL with none to fall back to');

-- D3 and D4
INSERT INTO recipes (id, name, base_servings, desired_servings) VALUES (93910, 'P39 free', 1, 1), (93911, 'P39 unknown', 1, 1);
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (93911, 93901, 1, 93900);
UPDATE stock SET price = 0 WHERE product_id = 93904;
UPDATE stock_log SET price = 0 WHERE product_id = 93904;
SELECT reconcile_stock_log_cache();
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (93910, 93904, 1, 93900);
SELECT results_eq($$SELECT recipe_id, costs IS NULL FROM recipes_pos_resolved WHERE recipe_id IN (93910, 93911) ORDER BY recipe_id$$,
	$$VALUES (93910, false), (93911, true)$$, 'D3: an unknown price is an unknown cost, a free one is 0');
SELECT results_eq($$SELECT recipe_id, prices_incomplete FROM recipes_resolved WHERE recipe_id IN (93910, 93911) ORDER BY recipe_id$$,
	$$VALUES (93910, 0), (93911, 1)$$, 'D4: only the unknown cost makes the prices incomplete');

SELECT * FROM finish();
ROLLBACK;
