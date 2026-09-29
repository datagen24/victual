-- migrations/0294.pgsql.sql (issues #543 and #546, #487 remediation):
-- trg_cascade_change_qu_id_stock (db/pgsql/baseline/06_triggers_a.sql) fires BEFORE
-- UPDATE on `products` whenever qu_id_stock changes and rescales every per-product
-- amount stored in that unit by the resolved conversion factor.
--
-- #543: product_location_min_stock.min_stock_amount (migrations/0276.pgsql.sql) and
-- products.min_stock_amount (db/pgsql/baseline/01_tables.sql) are two more such amounts,
-- but the trigger never rescaled either before this migration - a minimum silently kept
-- its old numeral in the new unit, and product_location_missing's shortfall compared it
-- against genuinely converted stock.
--
-- #546: MergeProducts() (services/StockService.php, commit 791389623f) refuses a merge
-- that would rescale a measured open container live in `stock` by a factor other than 1,
-- since stock_measurement_coherence_check (migrations/0275.pgsql.sql) requires amount = 1
-- on any row carrying a measurement. This trigger applies the identical rescale on a
-- single product's own qu_id_stock change and had no equivalent guard.
--
-- ROUND 2 (Opus validator finding on PR #618): the guard covers `stock` only, not
-- `stock_log`. A live (undone = 0), measured consume booking left over from a fully
-- consumed container is permanent history that nothing else ever clears, so refusing on
-- it (as an earlier round of this migration did) locks the product's stock unit forever
-- with nothing left to consume, weigh, or otherwise resolve. That booking's own undo is
-- already refused truthfully by UndoBooking()'s own CONSUME-branch guard (PR #598) if and
-- when it is ever undone - which is where that protection belongs, not here on every
-- future unit change regardless of whether undo is ever attempted.

SELECT plan(7);

INSERT INTO locations (name) VALUES ('Spike21 location');
INSERT INTO quantity_units (name) VALUES ('Spike21 gram'), ('Spike21 kilogram');

-- ------------------------------------------------------------------------------------
-- Issue #543: product_location_min_stock and products.min_stock_amount rescale
-- ------------------------------------------------------------------------------------

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, min_stock_amount)
VALUES (
	'Spike21 min stock product',
	(SELECT id FROM locations WHERE name = 'Spike21 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	200
);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 kilogram'),
	0.001,
	(SELECT id FROM products WHERE name = 'Spike21 min stock product')
);
INSERT INTO product_location_min_stock (product_id, location_id, min_stock_amount)
VALUES (
	(SELECT id FROM products WHERE name = 'Spike21 min stock product'),
	(SELECT id FROM locations WHERE name = 'Spike21 location'),
	500
);
-- 300 g of real stock, so the shortfall view reports a genuine, non-zero amount_missing
-- both before and after the unit change.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id)
VALUES (
	(SELECT id FROM products WHERE name = 'Spike21 min stock product'),
	300, '2030-01-01', '2026-09-01', 'spike21-min-stock', (SELECT id FROM locations WHERE name = 'Spike21 location')
);

UPDATE products SET qu_id_stock = (SELECT id FROM quantity_units WHERE name = 'Spike21 kilogram')
WHERE name = 'Spike21 min stock product';

SELECT is(
	(SELECT min_stock_amount FROM product_location_min_stock
		WHERE product_id = (SELECT id FROM products WHERE name = 'Spike21 min stock product')),
	0.5::double precision,
	'Changing qu_id_stock rescales product_location_min_stock.min_stock_amount by the conversion factor (issue #543)'
);
SELECT is(
	(SELECT amount_missing FROM product_location_missing
		WHERE product_id = (SELECT id FROM products WHERE name = 'Spike21 min stock product')),
	0.2::double precision,
	'...and product_location_missing reports the correctly converted shortfall (0.5 kg minimum - 0.3 kg stock = 0.2 kg)'
);
SELECT is(
	(SELECT min_stock_amount FROM products WHERE name = 'Spike21 min stock product'),
	0.2::double precision,
	'...and the product''s own min_stock_amount is rescaled the same way (200 * 0.001), the same class of miss as product_location_min_stock (issue #543)'
);

-- ------------------------------------------------------------------------------------
-- Issue #546: refusing a rescale of a live measured open container in `stock`
-- ------------------------------------------------------------------------------------

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
VALUES (
	'Spike21 live measured product',
	(SELECT id FROM locations WHERE name = 'Spike21 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram')
);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 kilogram'),
	0.001,
	(SELECT id FROM products WHERE name = 'Spike21 live measured product')
);
-- A single opened, measured container (open = 1, amount = 1, a real opened_amount/
-- opened_qu_id pair - stock_measurement_coherence_check's own shape).
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, open, opened_amount, opened_qu_id, opened_measured_at)
VALUES (
	(SELECT id FROM products WHERE name = 'Spike21 live measured product'),
	1, '2030-01-01', '2026-09-01', 'spike21-live-measured', (SELECT id FROM locations WHERE name = 'Spike21 location'),
	1, 0.5, (SELECT id FROM quantity_units WHERE name = 'Spike21 gram'), CURRENT_TIMESTAMP
);

SELECT throws_ok(
	format('UPDATE products SET qu_id_stock = (SELECT id FROM quantity_units WHERE name = %L) WHERE name = %L',
		'Spike21 kilogram', 'Spike21 live measured product'),
	'qu_id_stock cannot be changed by a non-1 conversion factor while this product has a measured open container',
	'A qu_id_stock change is refused while a live measured container in `stock` would be rescaled by a non-1 factor (issue #546)'
);
SELECT is(
	(SELECT amount FROM stock WHERE stock_id = 'spike21-live-measured'),
	1::double precision,
	'...and the refused change leaves the measured row exactly as it was'
);

-- ------------------------------------------------------------------------------------
-- Round 2: a live, undone = 0 measured consume booking in `stock_log` - with no live
-- `stock` row for the same product - no longer blocks the change (see header comment).
-- ------------------------------------------------------------------------------------

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
VALUES (
	'Spike21 consumed measured product',
	(SELECT id FROM locations WHERE name = 'Spike21 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram')
);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 kilogram'),
	0.001,
	(SELECT id FROM products WHERE name = 'Spike21 consumed measured product')
);
-- No live `stock` row - only a live (undone = 0) consume booking mirroring the
-- container's own measurement, exactly what ConsumeProduct() leaves behind when a whole
-- measured container is taken (migrations/0275.pgsql.sql).
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, location_id, transaction_type, undone, opened_amount, opened_qu_id, user_id)
VALUES (
	(SELECT id FROM products WHERE name = 'Spike21 consumed measured product'),
	-1, '2030-01-01', '2026-09-01', 'spike21-consumed-measured', (SELECT id FROM locations WHERE name = 'Spike21 location'),
	'consume', 0, 0.5, (SELECT id FROM quantity_units WHERE name = 'Spike21 gram'), 1
);

SELECT lives_ok(
	format('UPDATE products SET qu_id_stock = (SELECT id FROM quantity_units WHERE name = %L) WHERE name = %L',
		'Spike21 kilogram', 'Spike21 consumed measured product'),
	'A qu_id_stock change succeeds when only a live, undoable measured consume booking remains in `stock_log` and no live `stock` row exists - refusing here would lock the unit forever (issue #546, round 2)'
);

-- ------------------------------------------------------------------------------------
-- Negative control: an ordinary (unmeasured) rescale still runs to completion
-- ------------------------------------------------------------------------------------

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
VALUES (
	'Spike21 unmeasured product',
	(SELECT id FROM locations WHERE name = 'Spike21 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram')
);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (
	(SELECT id FROM quantity_units WHERE name = 'Spike21 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike21 kilogram'),
	0.001,
	(SELECT id FROM products WHERE name = 'Spike21 unmeasured product')
);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id)
VALUES (
	(SELECT id FROM products WHERE name = 'Spike21 unmeasured product'),
	500, '2030-01-01', '2026-09-01', 'spike21-unmeasured', (SELECT id FROM locations WHERE name = 'Spike21 location')
);

UPDATE products SET qu_id_stock = (SELECT id FROM quantity_units WHERE name = 'Spike21 kilogram')
WHERE name = 'Spike21 unmeasured product';

SELECT is(
	(SELECT amount FROM stock WHERE stock_id = 'spike21-unmeasured'),
	0.5::double precision,
	'A product with no measured container anywhere in its stock or ledger still rescales normally (no regression from issue #546''s new guard)'
);

SELECT * FROM finish();
