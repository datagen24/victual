-- migrations/0308.pgsql.sql (issues #849, #853, #866, #868; 2026-10-12 audit remediation).
--
-- #868: quantity_unit_conversions.factor is positive and finite by constraint, and
--       trg_cascade_change_qu_id_stock refuses a resolved factor that is not.
-- #849: a unit change rescales a consumption mapping whose qu_id is NULL (the product's stock
--       unit) and the amounts of the product's consumption event lines, and leaves a mapping
--       with its own unit alone.
-- #853: stock_log_UPD and stock_log_DEL rebuild each affected product's price caches once per
--       statement, not once per row, with the same cache contents.
-- #866: stock_lineage_backfill returns and writes what 0304's definition did. The 0304 text is
--       kept below as a temporary function and run on the same fixture.
--
-- The whole file runs in one transaction and rolls back: it drops a constraint, and the
-- fixture should not reach later files.

BEGIN;

SELECT plan(37);

SET LOCAL track_functions = 'all';

INSERT INTO locations (name) VALUES ('Audit34 location');
INSERT INTO quantity_units (name) VALUES ('Audit34 tablet'), ('Audit34 box'), ('Audit34 other');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
SELECT n, (SELECT id FROM locations WHERE name = 'Audit34 location'), g.id, g.id, g.id, g.id
FROM (VALUES ('Audit34 conv'), ('Audit34 map'), ('Audit34 other map'), ('Audit34 neg'),
	('Audit34 c1'), ('Audit34 c2'), ('Audit34 c3'), ('Audit34 c4'), ('Audit34 c5'), ('Audit34 c6'),
	('Audit34 c7'), ('Audit34 c8'), ('Audit34 bf1'), ('Audit34 bf2')) v(n)
CROSS JOIN (SELECT id FROM quantity_units WHERE name = 'Audit34 tablet') g;

CREATE FUNCTION pg_temp.p(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM products WHERE name = 'Audit34 ' || n $$;
CREATE FUNCTION pg_temp.qu(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM quantity_units WHERE name = 'Audit34 ' || n $$;
CREATE FUNCTION pg_temp.book(product TEXT, type TEXT, amount DOUBLE PRECISION, tag TEXT, undone SMALLINT DEFAULT 0,
	price DOUBLE PRECISION DEFAULT 1, purchased DATE DEFAULT '2026-10-01') RETURNS INTEGER LANGUAGE sql AS $$
	INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, transaction_id, undone, user_id)
	VALUES (pg_temp.p(product), amount, '2999-12-31', purchased, tag, type, price, (SELECT id FROM locations WHERE name = 'Audit34 location'), 'audit34-' || tag || '-' || nextval('stock_log_id_seq'), undone, 1)
	RETURNING id
$$;
CREATE FUNCTION pg_temp.row(product TEXT, amount DOUBLE PRECISION, tag TEXT) RETURNS INTEGER LANGUAGE sql AS $$
	INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
	VALUES (pg_temp.p(product), amount, '2999-12-31', '2026-10-01', tag, 1, (SELECT id FROM locations WHERE name = 'Audit34 location'))
	RETURNING id
$$;
CREATE FUNCTION pg_temp.rebuilds() RETURNS BIGINT LANGUAGE sql AS $$
	SELECT COALESCE(pg_stat_get_xact_function_calls('rebuild_stock_log_cache_for_product(integer)'::regprocedure), 0)
$$;

-- ------------------------------------------------------------------------------------
-- #868: the factor constraint
-- ------------------------------------------------------------------------------------

SELECT throws_ok(format($$ INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (%s, %s, -2, %s) $$,
	pg_temp.qu('tablet'), pg_temp.qu('other'), pg_temp.p('conv')), '23514', NULL, 'a negative factor is refused');
SELECT throws_ok(format($$ INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (%s, %s, 0, %s) $$,
	pg_temp.qu('tablet'), pg_temp.qu('other'), pg_temp.p('conv')), '23514', NULL, 'a zero factor is refused');
SELECT throws_ok(format($$ INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (%s, %s, 'Infinity', %s) $$,
	pg_temp.qu('tablet'), pg_temp.qu('other'), pg_temp.p('conv')), '23514', NULL, 'an infinite factor is refused');
SELECT throws_ok(format($$ INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (%s, %s, 'NaN', %s) $$,
	pg_temp.qu('tablet'), pg_temp.qu('other'), pg_temp.p('conv')), '23514', NULL, 'a NaN factor is refused');
SELECT is((SELECT count(*) FROM quantity_unit_conversions WHERE product_id = pg_temp.p('conv')), 0::BIGINT,
	'the refused inserts left no conversion row, inverse included');
SELECT lives_ok(format($$ INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (%s, %s, 4, %s) $$,
	pg_temp.qu('tablet'), pg_temp.qu('other'), pg_temp.p('conv')), 'a positive finite factor is accepted');
SELECT throws_ok(format($$ UPDATE quantity_unit_conversions SET factor = -1 WHERE product_id = %s AND factor = 4 $$, pg_temp.p('conv')),
	'23514', NULL, 'an update to a negative factor is refused');
SELECT is((SELECT factor FROM quantity_unit_conversions WHERE product_id = pg_temp.p('conv') AND from_qu_id = pg_temp.qu('tablet')),
	4::DOUBLE PRECISION, '...and the row keeps its factor');

-- ------------------------------------------------------------------------------------
-- #849 and #853: a unit change on a product with mappings, event lines and ledger rows
-- ------------------------------------------------------------------------------------

INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (pg_temp.qu('tablet'), pg_temp.qu('box'), 0.25, pg_temp.p('map'));

INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, qu_id, quantity_factor, default_quantity, location_mode, effective_from)
VALUES (1, 'audit34', 'null-unit', 'product', pg_temp.p('map'), NULL, 1, 1, 'single', now()),
	(1, 'audit34', 'own-unit', 'product', pg_temp.p('map'), pg_temp.qu('other'), 1, 2, 'single', now()),
	(1, 'audit34', 'other-product', 'product', pg_temp.p('other map'), NULL, 1, 1, 'single', now());
INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
VALUES (1, 'audit34', 'event-1', 'received', now());
INSERT INTO consumption_event_lines (event_id, product_id, amount)
VALUES ((SELECT id FROM consumption_events WHERE source_event_id = 'event-1'), pg_temp.p('map'), 3),
	((SELECT id FROM consumption_events WHERE source_event_id = 'event-1'), pg_temp.p('map'), 1),
	((SELECT id FROM consumption_events WHERE source_event_id = 'event-1'), pg_temp.p('other map'), 5);

SELECT pg_temp.book('map', 'purchase', 8, 'a34m1');
SELECT pg_temp.book('map', 'consume', -2, 'a34m1');
SELECT pg_temp.book('map', 'purchase', 4, 'a34m2');
SELECT pg_temp.book('map', 'consume', -1, 'a34m2');

CREATE TEMP TABLE a34_before AS SELECT pg_temp.rebuilds() AS n;
UPDATE products SET qu_id_stock = pg_temp.qu('box') WHERE id = pg_temp.p('map');

SELECT is((SELECT quantity_factor FROM consumption_mappings WHERE medication_ref = 'null-unit'), 0.25::DOUBLE PRECISION,
	'a mapping in the product''s stock unit (qu_id NULL) is rescaled by the unit change');
SELECT is((SELECT default_quantity FROM consumption_mappings WHERE medication_ref = 'null-unit'), 1::DOUBLE PRECISION,
	'...its default quantity stays as the person entered it');
SELECT results_eq($$ SELECT quantity_factor, default_quantity FROM consumption_mappings WHERE medication_ref = 'own-unit' $$,
	$$ VALUES (1::DOUBLE PRECISION, 2::DOUBLE PRECISION) $$, 'a mapping with its own unit is not rescaled');
SELECT is((SELECT quantity_factor FROM consumption_mappings WHERE medication_ref = 'other-product'), 1::DOUBLE PRECISION,
	'a NULL-unit mapping of another product is not rescaled');
SELECT results_eq($$ SELECT amount FROM consumption_event_lines WHERE product_id = (SELECT id FROM products WHERE name = 'Audit34 map') ORDER BY id $$,
	$$ VALUES (0.75::DOUBLE PRECISION), (0.25) $$, 'the product''s event lines are rescaled, as stock_log.amount is');
SELECT is((SELECT amount FROM consumption_event_lines WHERE product_id = pg_temp.p('other map')), 5::DOUBLE PRECISION,
	'another product''s event line is not');
SELECT results_eq($$ SELECT amount FROM stock_log WHERE product_id = (SELECT id FROM products WHERE name = 'Audit34 map') ORDER BY id $$,
	$$ VALUES (2::DOUBLE PRECISION), (-0.5), (1), (-0.25) $$, 'the ledger is rescaled by the same factor');
SELECT is(pg_temp.rebuilds() - (SELECT n FROM a34_before), 1::BIGINT,
	'a unit change on a product with four ledger rows rebuilds its price caches once');

-- ------------------------------------------------------------------------------------
-- #868: the trigger refuses a resolved factor that is not positive and finite
-- ------------------------------------------------------------------------------------

ALTER TABLE quantity_unit_conversions DROP CONSTRAINT quantity_unit_conversions_factor_positive;
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (pg_temp.qu('tablet'), pg_temp.qu('box'), -2, pg_temp.p('neg'));
SELECT pg_temp.book('neg', 'purchase', 6, 'a34n1', 0::SMALLINT, 3);
INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, qu_id, quantity_factor, default_quantity, location_mode, effective_from)
VALUES (1, 'audit34', 'neg-null-unit', 'product', pg_temp.p('neg'), NULL, 1, 1, 'single', now());

SELECT throws_ok(format($$ UPDATE products SET qu_id_stock = %s WHERE id = %s $$, pg_temp.qu('box'), pg_temp.p('neg')),
	'P0001', 'qu_id_stock cannot be changed: the resolved conversion factor is not a positive, finite number',
	'a unit change through a non-positive resolved factor is refused');
SELECT results_eq(format($$ SELECT amount, price FROM stock_log WHERE product_id = %s $$, pg_temp.p('neg')),
	$$ VALUES (6::DOUBLE PRECISION, 3::DOUBLE PRECISION) $$, '...and the ledger is unchanged');
SELECT is((SELECT quantity_factor FROM consumption_mappings WHERE medication_ref = 'neg-null-unit'), 1::DOUBLE PRECISION,
	'...and so is the mapping');

-- ------------------------------------------------------------------------------------
-- #853: the price caches after a bulk update or delete
-- ------------------------------------------------------------------------------------

SELECT pg_temp.book('c1', 'purchase', 2, 'a34c1a', 0::SMALLINT, 1.5, '2026-09-01');
SELECT pg_temp.book('c1', 'purchase', 3, 'a34c1b', 0::SMALLINT, 2.5, '2026-09-05');
SELECT pg_temp.book('c1', 'purchase', 1, 'a34c1c', 0::SMALLINT, 4, '2026-09-09');
SELECT pg_temp.book('c2', 'purchase', 5, 'a34c2a', 0::SMALLINT, 7, '2026-09-02');
SELECT pg_temp.book('c2', 'purchase', 5, 'a34c2b', 0::SMALLINT, 9, '2026-09-07');
SELECT pg_temp.book('c3', 'purchase', 1, 'a34c3a', 0::SMALLINT, 2, '2026-08-01');
SELECT pg_temp.book('c3', 'purchase', 1, 'a34c3b', 0::SMALLINT, 3, '2026-08-02');
SELECT pg_temp.book('c3', 'purchase', 1, 'a34c3c', 0::SMALLINT, 5, '2026-08-03');
SELECT pg_temp.book('c4', 'purchase', 2, 'a34c4a', 0::SMALLINT, 11, '2026-08-04');
SELECT pg_temp.book('c5', 'purchase', 1, 'a34c5a', 0::SMALLINT, 1, '2026-07-01');
SELECT pg_temp.book('c5', 'purchase', 1, 'a34c5b', 0::SMALLINT, 2, '2026-07-02');
SELECT pg_temp.book('c5', 'purchase', 1, 'a34c5c', 0::SMALLINT, 3, '2026-07-03');
SELECT pg_temp.book('c5', 'purchase', 1, 'a34c5d', 0::SMALLINT, 4, '2026-07-04');
SELECT pg_temp.book('c5', 'purchase', 1, 'a34c5e', 0::SMALLINT, 5, '2026-07-05');
SELECT pg_temp.book('c6', 'purchase', 1, 'a34c6a', 0::SMALLINT, 6, '2026-07-06');
SELECT pg_temp.book('c7', 'purchase', 1, 'a34c7a', 0::SMALLINT, 2, '2026-06-01');
SELECT pg_temp.book('c7', 'purchase', 1, 'a34c7b', 0::SMALLINT, 4, '2026-06-02');
SELECT pg_temp.book('c7', 'purchase', 1, 'a34c7c', 0::SMALLINT, 6, '2026-06-03');
SELECT pg_temp.book('c7', 'purchase', 1, 'a34c7d', 0::SMALLINT, 8, '2026-06-04');
SELECT pg_temp.book('c8', 'purchase', 1, 'a34c8a', 0::SMALLINT, 20, '2026-06-05');

-- Whether a product's rows in both caches equal what the two views return for it.
CREATE FUNCTION pg_temp.cache_diff(VARIADIC names TEXT[]) RETURNS BIGINT LANGUAGE sql AS $$
	WITH ids AS (SELECT id FROM products WHERE name = ANY (SELECT 'Audit34 ' || n FROM unnest(names) n)),
	a AS (SELECT product_id, price FROM cache__products_average_price WHERE product_id IN (SELECT id FROM ids)),
	av AS (SELECT product_id, price FROM products_average_price WHERE product_id IN (SELECT id FROM ids)),
	l AS (SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
		FROM cache__products_last_purchased WHERE product_id IN (SELECT id FROM ids)),
	lv AS (SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
		FROM products_last_purchased WHERE product_id IN (SELECT id FROM ids))
	SELECT (SELECT count(*) FROM (SELECT * FROM a EXCEPT SELECT * FROM av) x)
		+ (SELECT count(*) FROM (SELECT * FROM av EXCEPT SELECT * FROM a) x)
		+ (SELECT count(*) FROM (SELECT * FROM l EXCEPT SELECT * FROM lv) x)
		+ (SELECT count(*) FROM (SELECT * FROM lv EXCEPT SELECT * FROM l) x)
$$;

SELECT cmp_ok((SELECT count(*) FROM cache__products_average_price WHERE product_id IN (pg_temp.p('c1'), pg_temp.p('c2'), pg_temp.p('c3'), pg_temp.p('c5'))),
	'=', 4::BIGINT, 'fixture: the four products have an average price cache row');

-- A five-row UPDATE over two products.
UPDATE a34_before SET n = pg_temp.rebuilds();
UPDATE stock_log SET price = price + 10 WHERE product_id IN (pg_temp.p('c1'), pg_temp.p('c2'));
SELECT is(pg_temp.rebuilds() - (SELECT n FROM a34_before), 2::BIGINT, 'a five-row update over two products rebuilds two caches');
SELECT is(pg_temp.cache_diff('c1', 'c2'), 0::BIGINT, '...and both agree with the views');

-- Every row of c3 moves to c4 (the shape MergeProducts uses).
UPDATE a34_before SET n = pg_temp.rebuilds();
UPDATE stock_log SET product_id = pg_temp.p('c4') WHERE product_id = pg_temp.p('c3');
SELECT is(pg_temp.rebuilds() - (SELECT n FROM a34_before), 2::BIGINT, 'moving three rows from one product to another rebuilds both caches once');
SELECT is(pg_temp.cache_diff('c3', 'c4'), 0::BIGINT, '...and both agree with the views');
SELECT is((SELECT count(*) FROM cache__products_average_price WHERE product_id = pg_temp.p('c3'))
	+ (SELECT count(*) FROM cache__products_last_purchased WHERE product_id = pg_temp.p('c3')), 0::BIGINT,
	'...the product left with no bookings has no cache rows');

-- Two of c7's four rows move to c8.
UPDATE a34_before SET n = pg_temp.rebuilds();
UPDATE stock_log SET product_id = pg_temp.p('c8') WHERE product_id = pg_temp.p('c7') AND price >= 6;
SELECT is(pg_temp.rebuilds() - (SELECT n FROM a34_before), 2::BIGINT, 'a partial move rebuilds both caches once');
SELECT is(pg_temp.cache_diff('c7', 'c8'), 0::BIGINT, '...and both agree with the views');

-- Four of c5's five rows are deleted, then c6's only row.
UPDATE a34_before SET n = pg_temp.rebuilds();
DELETE FROM stock_log WHERE product_id = pg_temp.p('c5') AND price >= 2;
SELECT is(pg_temp.rebuilds() - (SELECT n FROM a34_before), 1::BIGINT, 'deleting four rows of one product rebuilds its caches once');
SELECT is(pg_temp.cache_diff('c5'), 0::BIGINT, '...and they agree with the views');
SELECT is((SELECT price FROM cache__products_average_price WHERE product_id = pg_temp.p('c5')), 1::DOUBLE PRECISION,
	'...the surviving booking still has its average price cached');
DELETE FROM stock_log WHERE product_id = pg_temp.p('c6');
SELECT is((SELECT count(*) FROM cache__products_average_price WHERE product_id = pg_temp.p('c6')), 0::BIGINT,
	'deleting a product''s last booking removes its cache row');

-- ------------------------------------------------------------------------------------
-- #866: stock_lineage_backfill against 0304's definition
-- ------------------------------------------------------------------------------------

-- migrations/0304.pgsql.sql:140-196, unchanged except for its name.
CREATE FUNCTION pg_temp.backfill_0304(p_product INTEGER DEFAULT NULL)
RETURNS TABLE (family_class TEXT, families BIGINT, stock_rows BIGINT)
LANGUAGE plpgsql AS $$
DECLARE
	v_bad TEXT;
BEGIN
	SELECT 'stock row ' || s.id INTO v_bad FROM stock s
	WHERE (p_product IS NULL OR s.product_id = p_product) AND s.amount IN ('NaN', 'Infinity', '-Infinity') LIMIT 1;
	IF v_bad IS NULL THEN
		SELECT 'booking ' || l.id INTO v_bad FROM stock_log l
		WHERE (p_product IS NULL OR l.product_id = p_product) AND l.amount IN ('NaN', 'Infinity', '-Infinity') LIMIT 1;
	END IF;
	IF v_bad IS NOT NULL THEN
		RAISE EXCEPTION 'ADR-0036 backfill: % has a non-finite amount and cannot be attributed', v_bad;
	END IF;

	RETURN QUERY
	WITH fam AS MATERIALIZED (
		SELECT * FROM stock_lineage_families(p_product)
	), lots AS MATERIALIZED (
		SELECT DISTINCT f.product_id, f.root, f.class, l.id AS lot_id, l.amount AS lot_amount
		FROM fam f JOIN stock_log l ON l.stock_id = f.stock_id AND l.product_id = f.product_id
		WHERE f.class IN ('E', 'X') AND l.undone = 0 AND stock_log_is_addition(l.transaction_type, l.amount)
	), ins_rows AS (
		INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
		SELECT s.id, lo.lot_id, CASE WHEN f.class = 'E' THEN s.amount ELSE lo.lot_amount END, 'derived'
		FROM stock s
		JOIN fam f ON f.stock_id = s.stock_id AND f.product_id = s.product_id
		JOIN lots lo ON lo.product_id = f.product_id AND lo.root = f.root
		WHERE s.amount > 0
		RETURNING 1
	), ins_pool AS (
		INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
		SELECT s.id, NULL, s.amount, 'unknown'
		FROM stock s JOIN fam f ON f.stock_id = s.stock_id AND f.product_id = s.product_id
		WHERE f.class = 'U' AND s.amount > 0
		RETURNING 1
	), ins_bookings AS (
		INSERT INTO stock_booking_lots (booking_id, lot_id, amount, basis)
		SELECT l.id,
			CASE WHEN stock_log_is_addition(l.transaction_type, l.amount) THEN l.id
				ELSE (SELECT lo.lot_id FROM lots lo WHERE lo.product_id = f.product_id AND lo.root = f.root) END,
			l.amount,
			CASE WHEN f.class IN ('E', 'X') THEN 'derived' ELSE 'unknown' END
		FROM stock_log l JOIN fam f ON f.stock_id = l.stock_id AND f.product_id = l.product_id
		WHERE l.amount <> 0 AND f.class <> 'N'
			AND (f.class = 'E' OR stock_log_is_addition(l.transaction_type, l.amount))
		RETURNING 1
	), counts AS (
		SELECT (SELECT count(*) FROM ins_rows) + (SELECT count(*) FROM ins_pool) + (SELECT count(*) FROM ins_bookings) AS n
	)
	SELECT f.class, count(DISTINCT (f.product_id, f.root)),
		(SELECT count(*) FROM stock s JOIN fam g ON g.stock_id = s.stock_id AND g.product_id = s.product_id WHERE g.class = f.class)
	FROM fam f CROSS JOIN counts
	GROUP BY f.class, counts.n;
END
$$;

-- bf1: E (purchase, consume, row), E with an undone consume, E fully consumed (no row),
-- X (two purchases, one merged row), U (a consume on a merged row), N-like family with only a
-- zero-amount booking. bf2: a second product with an E family and a U family, so the join on
-- (product, root) has to keep products apart.
SELECT pg_temp.book('bf1', 'purchase', 5, 'a34e1');
SELECT pg_temp.book('bf1', 'consume', -2, 'a34e1');
SELECT pg_temp.row('bf1', 3, 'a34e1');
SELECT pg_temp.book('bf1', 'purchase', 6, 'a34e3');
SELECT pg_temp.book('bf1', 'consume', -1, 'a34e3', 1::SMALLINT);
SELECT pg_temp.row('bf1', 6, 'a34e3');
SELECT pg_temp.book('bf1', 'purchase', 4, 'a34e2');
SELECT pg_temp.book('bf1', 'consume', -4, 'a34e2');
SELECT pg_temp.book('bf1', 'purchase', 3, 'a34x');
SELECT pg_temp.book('bf1', 'purchase', 2, 'a34x');
SELECT pg_temp.row('bf1', 5, 'a34x');
SELECT pg_temp.book('bf1', 'purchase', 3, 'a34u');
SELECT pg_temp.book('bf1', 'purchase', 2, 'a34u');
SELECT pg_temp.book('bf1', 'consume', -1, 'a34u');
SELECT pg_temp.row('bf1', 4, 'a34u');
SELECT pg_temp.book('bf2', 'purchase', 9, 'a34f1');
SELECT pg_temp.book('bf2', 'consume', -3, 'a34f1');
SELECT pg_temp.book('bf2', 'consume', -1, 'a34f1');
SELECT pg_temp.row('bf2', 5, 'a34f1');
SELECT pg_temp.book('bf2', 'purchase', 1, 'a34f2');
SELECT pg_temp.book('bf2', 'purchase', 1, 'a34f2');
SELECT pg_temp.book('bf2', 'consume', -1, 'a34f2');
SELECT pg_temp.row('bf2', 1, 'a34f2');

CREATE TEMP TABLE a34_old_counts AS
SELECT 'bf1' AS product, * FROM pg_temp.backfill_0304(pg_temp.p('bf1'))
UNION ALL SELECT 'bf2', * FROM pg_temp.backfill_0304(pg_temp.p('bf2'));
CREATE TEMP TABLE a34_old_rows AS
SELECT rl.stock_row_id, rl.lot_id, rl.amount, rl.basis FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id
WHERE s.product_id IN (pg_temp.p('bf1'), pg_temp.p('bf2'));
CREATE TEMP TABLE a34_old_bookings AS
SELECT bl.booking_id, bl.lot_id, bl.amount, bl.basis FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id
WHERE l.product_id IN (pg_temp.p('bf1'), pg_temp.p('bf2'));

DELETE FROM stock_row_lots WHERE stock_row_id IN (SELECT id FROM stock WHERE product_id IN (pg_temp.p('bf1'), pg_temp.p('bf2')));
DELETE FROM stock_booking_lots WHERE booking_id IN (SELECT id FROM stock_log WHERE product_id IN (pg_temp.p('bf1'), pg_temp.p('bf2')));

CREATE TEMP TABLE a34_new_counts AS
SELECT 'bf1' AS product, * FROM stock_lineage_backfill(pg_temp.p('bf1'))
UNION ALL SELECT 'bf2', * FROM stock_lineage_backfill(pg_temp.p('bf2'));

SELECT cmp_ok((SELECT count(*) FROM a34_old_rows), '>=', 6::BIGINT, 'fixture: 0304''s backfill wrote contributions');
SELECT cmp_ok((SELECT count(*) FROM a34_old_bookings), '>=', 12::BIGINT, 'fixture: 0304''s backfill wrote allocations');
SELECT is_empty($$ (SELECT * FROM a34_old_counts EXCEPT SELECT * FROM a34_new_counts)
	UNION ALL (SELECT * FROM a34_new_counts EXCEPT SELECT * FROM a34_old_counts) $$,
	'the backfill returns the same classes and counts as 0304''s');
SELECT is_empty($$ (SELECT * FROM a34_old_rows EXCEPT SELECT rl.stock_row_id, rl.lot_id, rl.amount, rl.basis FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id
		WHERE s.product_id IN (SELECT id FROM products WHERE name IN ('Audit34 bf1', 'Audit34 bf2')))
	UNION ALL (SELECT rl.stock_row_id, rl.lot_id, rl.amount, rl.basis FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id
		WHERE s.product_id IN (SELECT id FROM products WHERE name IN ('Audit34 bf1', 'Audit34 bf2')) EXCEPT SELECT * FROM a34_old_rows) $$,
	'...writes the same contributions');
SELECT is_empty($$ (SELECT * FROM a34_old_bookings EXCEPT SELECT bl.booking_id, bl.lot_id, bl.amount, bl.basis FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id
		WHERE l.product_id IN (SELECT id FROM products WHERE name IN ('Audit34 bf1', 'Audit34 bf2')))
	UNION ALL (SELECT bl.booking_id, bl.lot_id, bl.amount, bl.basis FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id
		WHERE l.product_id IN (SELECT id FROM products WHERE name IN ('Audit34 bf1', 'Audit34 bf2')) EXCEPT SELECT * FROM a34_old_bookings) $$,
	'...and the same allocations');
SELECT is((SELECT count(*) FROM stock_lineage_violations(pg_temp.p('bf1'))) + (SELECT count(*) FROM stock_lineage_violations(pg_temp.p('bf2'))),
	0::BIGINT, '...and invariants I1 to I3 hold over what it wrote');

SELECT * FROM finish();

ROLLBACK;
