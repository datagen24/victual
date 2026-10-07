-- migrations/0304.pgsql.sql (ADR-0036, issue #665): booking-level stock lineage.
--
-- Covers the SQL objects the migration creates: the two tables' constraints, the addition and
-- delta helpers, the family classification, the backfill (including its refusal of a
-- non-finite amount and its rerun), the invariant checks, and the redefined
-- trg_cascade_change_qu_id_stock rescaling both tables. The application writers, merge and
-- undo are PHP and are covered by tests/Pgsql (StockLineage*Test.php).

SELECT plan(27);

INSERT INTO locations (name) VALUES ('Lineage30 location');
INSERT INTO quantity_units (name) VALUES ('Lineage30 gram'), ('Lineage30 kilogram');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
SELECT n, (SELECT id FROM locations WHERE name = 'Lineage30 location'), g.id, g.id, g.id, g.id
FROM (VALUES ('Lineage30 E'), ('Lineage30 X'), ('Lineage30 U'), ('Lineage30 rescale'), ('Lineage30 corrupt')) v(n)
CROSS JOIN (SELECT id FROM quantity_units WHERE name = 'Lineage30 gram') g;
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES ((SELECT id FROM quantity_units WHERE name = 'Lineage30 gram'), (SELECT id FROM quantity_units WHERE name = 'Lineage30 kilogram'),
	0.001, (SELECT id FROM products WHERE name = 'Lineage30 rescale'));

CREATE FUNCTION pg_temp.p(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM products WHERE name = 'Lineage30 ' || n $$;
CREATE FUNCTION pg_temp.book(product TEXT, type TEXT, amount DOUBLE PRECISION, tag TEXT, undone SMALLINT DEFAULT 0) RETURNS INTEGER LANGUAGE sql AS $$
	INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, transaction_id, undone, user_id)
	VALUES (pg_temp.p(product), amount, '2999-12-31', '2026-10-01', tag, type, 1, (SELECT id FROM locations WHERE name = 'Lineage30 location'), 'lineage30-' || tag, undone, 1)
	RETURNING id
$$;
CREATE FUNCTION pg_temp.row(product TEXT, amount DOUBLE PRECISION, tag TEXT) RETURNS INTEGER LANGUAGE sql AS $$
	INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
	VALUES (pg_temp.p(product), amount, '2999-12-31', '2026-10-01', tag, 1, (SELECT id FROM locations WHERE name = 'Lineage30 location'))
	RETURNING id
$$;

-- ------------------------------------------------------------------------------------
-- Helpers
-- ------------------------------------------------------------------------------------

SELECT ok(stock_log_is_addition('purchase', 1) AND stock_log_is_addition('self-production', 1)
	AND stock_log_is_addition('inventory-correction', 2), 'purchase, self-production and a positive inventory correction add a lot');
SELECT ok(NOT stock_log_is_addition('inventory-correction', -2) AND NOT stock_log_is_addition('consume', -1)
	AND NOT stock_log_is_addition('stock-edit-new', 3), 'a negative correction, a consume and an edit do not (an edit''s lot is a writer''s decision)');
SELECT is(ARRAY[stock_log_lineage_delta('stock-edit-old', 4), stock_log_lineage_delta('product-opened', 2),
	stock_log_lineage_delta('stock-measured-new', 1), stock_log_lineage_delta('stock-measured-old', 1),
	stock_log_lineage_delta('consume', -2), stock_log_lineage_delta('transfer_to', 2)],
	ARRAY[-4, 0, 0, 0, -2, 2]::DOUBLE PRECISION[], 'I3 counts edit-old negated, opening and measurement as zero, the rest as written');

-- ------------------------------------------------------------------------------------
-- Families and backfill
-- ------------------------------------------------------------------------------------

-- E: purchase 5, consume 2 (row 3). X: purchases 3 and 2 on one merged row of 5 under one tag.
-- U: the same merged row after a consume of 1.
SELECT pg_temp.book('E', 'purchase', 5, 'l30e');
SELECT pg_temp.book('E', 'consume', -2, 'l30e');
SELECT pg_temp.row('E', 3, 'l30e');
SELECT pg_temp.book('X', 'purchase', 3, 'l30x');
SELECT pg_temp.book('X', 'purchase', 2, 'l30x');
SELECT pg_temp.row('X', 5, 'l30x');
SELECT pg_temp.book('U', 'purchase', 3, 'l30u');
SELECT pg_temp.book('U', 'purchase', 2, 'l30u');
SELECT pg_temp.book('U', 'consume', -1, 'l30u');
SELECT pg_temp.row('U', 4, 'l30u');

SELECT is((SELECT array_agg(class ORDER BY class) FROM stock_lineage_families(NULL) WHERE stock_id IN ('l30e', 'l30x', 'l30u')),
	ARRAY['E', 'U', 'X'], 'the three families classify as E, U and X');

SELECT is((SELECT array_agg(family_class || families ORDER BY family_class) FROM stock_lineage_backfill(pg_temp.p('E'))),
	ARRAY['E1'], 'a product-scoped backfill classifies only that product');
SELECT is((SELECT array_agg(stock_id) FROM stock_lineage_families(NULL) WHERE stock_id = 'l30e'), NULL,
	'a family with lineage data is no longer offered to the backfill');
SELECT results_eq($$ SELECT bl.amount, bl.basis FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id
		WHERE l.stock_id = 'l30e' ORDER BY l.id $$,
	$$ VALUES (5::DOUBLE PRECISION, 'derived'), (-2, 'derived') $$, 'E: the purchase and the consume are allocated to the one lot');
SELECT results_eq($$ SELECT rl.amount, rl.basis FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id WHERE s.stock_id = 'l30e' $$,
	$$ VALUES (3::DOUBLE PRECISION, 'derived') $$, 'E: the row holds its amount of the lot');

SELECT lives_ok($$ SELECT * FROM stock_lineage_backfill(NULL) $$, 'a full backfill runs');
SELECT results_eq($$ SELECT rl.amount, rl.basis FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id
		WHERE s.stock_id = 'l30x' ORDER BY rl.lot_id $$,
	$$ VALUES (3::DOUBLE PRECISION, 'derived'), (2, 'derived') $$, 'X: the merged row holds each purchase''s amount');
SELECT results_eq($$ SELECT rl.lot_id IS NULL, rl.amount, rl.basis FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id
		WHERE s.stock_id = 'l30u' $$,
	$$ VALUES (true, 4::DOUBLE PRECISION, 'unknown') $$, 'U: the row is one pool contribution');
SELECT results_eq($$ SELECT l.transaction_type, bl.basis FROM stock_log l LEFT JOIN stock_booking_lots bl ON bl.booking_id = l.id
		WHERE l.stock_id = 'l30u' ORDER BY l.id $$,
	$$ VALUES ('purchase', 'unknown'), ('purchase', 'unknown'), ('consume', NULL) $$,
	'U: the purchases are unknown lots and the consume stays untracked');
SELECT is((SELECT count(*) FROM stock_lineage_violations(NULL)), 0::BIGINT, 'I1 to I3 hold after the backfill');

CREATE TEMP TABLE lineage30_before AS
	SELECT 'r' AS t, stock_row_id AS a, lot_id AS b, amount, basis FROM stock_row_lots
	UNION ALL SELECT 'b', booking_id, lot_id, amount, basis FROM stock_booking_lots;
SELECT lives_ok($$ SELECT * FROM stock_lineage_backfill(NULL) $$, 'a second backfill runs');
SELECT bag_eq($$ SELECT 'r' AS t, stock_row_id AS a, lot_id AS b, amount, basis FROM stock_row_lots
		UNION ALL SELECT 'b', booking_id, lot_id, amount, basis FROM stock_booking_lots $$,
	$$ SELECT * FROM lineage30_before $$, '...and writes nothing');

SELECT pg_temp.book('corrupt', 'purchase', 'Infinity', 'l30c');
SELECT throws_like($$ SELECT * FROM stock_lineage_backfill(pg_temp.p('corrupt')) $$,
	'%booking % has a non-finite amount and cannot be attributed%', 'a non-finite amount is refused, never attributed');
DELETE FROM stock_log WHERE stock_id = 'l30c';

-- ------------------------------------------------------------------------------------
-- Invariant checks
-- ------------------------------------------------------------------------------------

UPDATE stock SET amount = 2.5 WHERE stock_id = 'l30e';
SELECT results_eq($$ SELECT invariant, expected, actual FROM stock_lineage_violations(pg_temp.p('E')) ORDER BY invariant $$,
	$$ VALUES ('I1', 2.5::DOUBLE PRECISION, 3::DOUBLE PRECISION) $$, 'I1 names a row whose contributions disagree with it');
UPDATE stock SET amount = 3 WHERE stock_id = 'l30e';

UPDATE stock_booking_lots SET amount = -1.5 WHERE booking_id = (SELECT id FROM stock_log WHERE stock_id = 'l30e' AND transaction_type = 'consume');
SELECT results_eq($$ SELECT invariant, expected, actual FROM stock_lineage_violations(pg_temp.p('E')) ORDER BY invariant $$,
	$$ VALUES ('I2', -2::DOUBLE PRECISION, -1.5::DOUBLE PRECISION), ('I3', 3.5, 3) $$,
	'I2 names a booking whose allocations disagree with it, and I3 the lot that no longer balances');
UPDATE stock_booking_lots SET amount = -2 WHERE booking_id = (SELECT id FROM stock_log WHERE stock_id = 'l30e' AND transaction_type = 'consume');
SELECT is((SELECT count(*) FROM stock_lineage_violations(pg_temp.p('U'))), 0::BIGINT, 'I3 skips a lot with an unknown allocation');

-- ------------------------------------------------------------------------------------
-- Constraints
-- ------------------------------------------------------------------------------------

SELECT throws_ok($$ INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
		SELECT id, NULL, 1, 'recorded' FROM stock WHERE stock_id = 'l30e' $$,
	'23514', NULL, 'a pool contribution must have the unknown basis');
SELECT throws_ok($$ INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
		SELECT s.id, l.id, 0, 'recorded' FROM stock s JOIN stock_log l ON l.stock_id = s.stock_id WHERE s.stock_id = 'l30e' LIMIT 1 $$,
	'23514', NULL, 'a contribution is positive');
SELECT throws_ok($$ INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
		SELECT s.id, rl.lot_id, 1, 'recorded' FROM stock s JOIN stock_row_lots rl ON rl.stock_row_id = s.id WHERE s.stock_id = 'l30e' $$,
	'23505', NULL, 'a row holds one contribution per lot');
SELECT throws_ok($$ INSERT INTO stock_booking_lots (booking_id, lot_id, amount, basis)
		SELECT id, NULL, -1, 'unknown' FROM stock_log WHERE stock_id = 'l30e' AND transaction_type = 'consume' $$,
	'23514', NULL, 'a draw on the pool is recorded');

-- ------------------------------------------------------------------------------------
-- Unit change rescales both tables (ADR-0036 section 3)
-- ------------------------------------------------------------------------------------

SELECT pg_temp.book('rescale', 'purchase', 500, 'l30r');
SELECT pg_temp.book('rescale', 'consume', -200, 'l30r');
SELECT pg_temp.row('rescale', 300, 'l30r');
SELECT * FROM stock_lineage_backfill(pg_temp.p('rescale'));
UPDATE products SET qu_id_stock = (SELECT id FROM quantity_units WHERE name = 'Lineage30 kilogram') WHERE name = 'Lineage30 rescale';

SELECT is((SELECT rl.amount FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id WHERE s.stock_id = 'l30r'),
	0.3::DOUBLE PRECISION, 'a stock-unit change rescales contributions');
SELECT results_eq($$ SELECT bl.amount FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id WHERE l.stock_id = 'l30r' ORDER BY l.id $$,
	$$ VALUES (0.5::DOUBLE PRECISION), (-0.2) $$, '...and allocations');
SELECT is((SELECT count(*) FROM stock_lineage_violations(pg_temp.p('rescale'))), 0::BIGINT, '...so I1 to I3 still hold');
SELECT is((SELECT rl.amount FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id WHERE s.stock_id = 'l30e'),
	3::DOUBLE PRECISION, '...and touches no other product');

SELECT * FROM finish();
