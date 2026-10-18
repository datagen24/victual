-- migrations/0315.pgsql.sql (issue #774, ADR-0045 gate 1 option (a)): legacy_zero_price.
--
-- The marker's constraints, mark_legacy_zero_prices() under both policies, stock_next_use and
-- its INSTEAD OF triggers carrying the marker, and stock_splits keeping a marked 0 apart from a
-- known 0. The StockService write paths that copy it are PHP, in
-- tests/Pgsql/LegacyZeroPricePropagationTest.php; the import in ImportLegacyZeroPricesTest.php.
--
-- The whole file runs in one transaction and rolls back.

BEGIN;

SELECT plan(21);

SELECT col_not_null('stock', 'legacy_zero_price', 'stock.legacy_zero_price is NOT NULL');
SELECT col_default_is('stock', 'legacy_zero_price', 0, 'and defaults to 0, a known price');
SELECT col_not_null('stock_log', 'legacy_zero_price', 'stock_log.legacy_zero_price is NOT NULL');
SELECT col_default_is('stock_log', 'legacy_zero_price', 0, 'and defaults to 0');

INSERT INTO locations (id, name) VALUES (93800, 'L38 location');
INSERT INTO quantity_units (id, name) VALUES (93800, 'L38 unit');
INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES (93800, 'L38 product', 93800, 93800, 93800);
INSERT INTO users (id, username, password) VALUES (93800, 'l38 user', 'fixture');
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id)
VALUES (93800, 1, '2999-12-31', '2026-09-01', 'l38-zero-a', 0, 0, 93800),
	(93800, 1, '2999-12-31', '2026-09-01', 'l38-zero-b', 0, 0, 93800),
	(93800, 1, '2999-12-31', '2026-09-01', 'l38-priced', 2, 0, 93800),
	(93800, 1, '2999-12-31', '2026-09-01', 'l38-null', NULL, 0, 93800);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, user_id, transaction_id)
VALUES (93800, 1, '2999-12-31', '2026-09-01', 'l38-zero-a', 'purchase', 0, 93800, 93800, 'l38-tx');

SELECT throws_ok($$UPDATE stock SET legacy_zero_price = 1 WHERE stock_id = 'l38-priced'$$, '23514', NULL, 'a priced row cannot be marked');
SELECT throws_ok($$UPDATE stock SET legacy_zero_price = 1 WHERE stock_id = 'l38-null'$$, '23514', NULL, 'nor an unpriced one');
SELECT throws_ok($$UPDATE stock SET legacy_zero_price = 2 WHERE stock_id = 'l38-zero-a'$$, '23514', NULL, 'the marker is 0 or 1');
SELECT throws_ok($$UPDATE stock_log SET legacy_zero_price = 1, price = 3 WHERE stock_id = 'l38-zero-a'$$, '23514', NULL, 'stock_log carries the same rule');

-- 'known' marks nothing and counts the zeros it leaves known
SELECT is((SELECT stock_rows FROM mark_legacy_zero_prices('import', 'known')), (SELECT count(*)::INTEGER FROM stock WHERE price = 0), 'known counts every zero');
SELECT is((SELECT count(*) FROM stock WHERE legacy_zero_price = 1 AND product_id = 93800), 0::BIGINT, 'and marks none');
SELECT is((SELECT policy FROM price_interpretation_reports ORDER BY id DESC LIMIT 1), 'known', 'and records the policy');

-- 'unknown' marks every zero, and only zeros
SELECT lives_ok($$SELECT mark_legacy_zero_prices('import', 'unknown')$$, 'unknown runs');
SELECT bag_eq($$SELECT stock_id, legacy_zero_price FROM stock WHERE product_id = 93800$$,
	$$VALUES ('l38-zero-a', 1::SMALLINT), ('l38-zero-b', 1::SMALLINT), ('l38-priced', 0::SMALLINT), ('l38-null', 0::SMALLINT)$$,
	'every 0 is marked, nothing else');
SELECT is((SELECT legacy_zero_price FROM stock_log WHERE transaction_id = 'l38-tx'), 1::SMALLINT, 'in stock_log too');
SELECT is((SELECT price FROM stock WHERE stock_id = 'l38-zero-a'), 0::DOUBLE PRECISION, 'without changing the stored price');
SELECT throws_ok($$SELECT mark_legacy_zero_prices('import', 'free')$$, 'P0001', NULL, 'an unknown policy is refused');

-- An upgrade that finds no zero writes no report
DELETE FROM price_interpretation_reports;
UPDATE stock SET legacy_zero_price = 0;
UPDATE stock_log SET legacy_zero_price = 0;
UPDATE stock SET price = NULL WHERE price = 0;
UPDATE stock_log SET price = NULL WHERE price = 0;
SELECT lives_ok($$SELECT mark_legacy_zero_prices('upgrade', 'unknown')$$, 'an upgrade with no zero runs');
SELECT is((SELECT count(*) FROM price_interpretation_reports), 0::BIGINT, 'and records nothing');

-- stock_next_use carries the marker, and its UPDATE trigger writes it back
UPDATE stock SET price = 0, legacy_zero_price = 1 WHERE stock_id IN ('l38-zero-a', 'l38-zero-b');
SELECT is((SELECT legacy_zero_price FROM stock_next_use WHERE stock_id = 'l38-zero-a'), 1::SMALLINT, 'stock_next_use projects the marker');
UPDATE stock_next_use SET legacy_zero_price = 0 WHERE stock_id = 'l38-zero-b';
SELECT is((SELECT legacy_zero_price FROM stock WHERE stock_id = 'l38-zero-b'), 0::SMALLINT, 'and its UPDATE trigger writes it through');

-- stock_splits groups a marked 0 apart from a known 0
SELECT is((SELECT count(*) FROM stock_splits WHERE product_id = 93800), 0::BIGINT, 'a marked 0 and a known 0 are not a compaction group');

SELECT * FROM finish();
ROLLBACK;
