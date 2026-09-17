-- migrations/0275.pgsql.sql (plan 28, ADR-0022): trg_stock_next_use_INS/_UPD are the
-- INSTEAD OF trigger bodies db/pgsql/baseline/06_triggers_b.sql binds to the
-- stock_next_use view - widened here to carry the four opened_* measurement columns.
-- Before this migration the UPDATE body did not know those columns existed at all: an
-- `UPDATE stock_next_use SET opened_amount = ...` reported "UPDATE 1" and silently wrote
-- nothing, because the trigger's SET list named every column of `stock` explicitly with
-- no NEW.* passthrough. The UPDATE case below is that exact defect, reproduced against
-- the real view and the real trigger rather than asserted from the migration's own
-- comment.
--
-- trg_cascade_change_qu_id_stock2 (same migration) is the BEFORE UPDATE rescale that
-- fires when a product's qu_id_stock changes; 0275's only edit to it is removing the
-- `NEW.tare_weight := ...` line (ADR-0022 decision 4 moved container tare to
-- locations), so the negative control below - tare_weight staying put while the other
-- three columns rescale - is the regression this file exists to catch.

SELECT plan(9);

INSERT INTO locations (name) VALUES ('Spike12 location');
INSERT INTO quantity_units (name) VALUES ('Spike12 gram'), ('Spike12 kilogram');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, quick_consume_amount, quick_open_amount, calories)
VALUES (
	'Spike12 product',
	(SELECT id FROM locations WHERE name = 'Spike12 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike12 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike12 gram'),
	10, 5, 100
);

-- INSERT through the view: the coherence CHECK (migrations/0275.pgsql.sql) requires
-- open = 1 AND amount = 1 whenever a measurement is attached.
INSERT INTO stock_next_use (product_id, amount, stock_id, open, opened_amount, opened_qu_id, opened_measured_at)
VALUES (
	(SELECT id FROM products WHERE name = 'Spike12 product'),
	1, 'spike12-a', 1,
	0.6, (SELECT id FROM quantity_units WHERE name = 'Spike12 gram'), CURRENT_TIMESTAMP
);
SELECT ok(
	EXISTS(SELECT 1 FROM stock WHERE stock_id = 'spike12-a' AND opened_amount = 0.6),
	'INSERT through stock_next_use writes opened_amount onto the real stock row (trg_stock_next_use_INS)'
);

-- UPDATE through the view - the defect the migration fixed. Before it, this statement
-- reported success and changed nothing.
UPDATE stock_next_use SET opened_amount = 0.3 WHERE stock_id = 'spike12-a';
SELECT is(
	(SELECT opened_amount FROM stock WHERE stock_id = 'spike12-a'),
	0.3::double precision,
	'UPDATE through stock_next_use reaches the real opened_amount column (trg_stock_next_use_UPD)'
);

UPDATE stock_next_use SET note = 'Spike12 note' WHERE stock_id = 'spike12-a';
SELECT is(
	(SELECT note FROM stock WHERE stock_id = 'spike12-a'),
	'Spike12 note',
	'A pre-existing column (note) still updates through the same view and trigger'
);

-- trg_cascade_change_qu_id_stock2: changing qu_id_stock rescales by the conversion
-- factor between the old and new unit. A separate, stockless product - the sibling
-- trigger trg_cascade_change_qu_id_stock (pre-existing, unrelated to migration 0275)
-- rescales every *stock* row's own amount on the same event, which would collide with
-- Spike12 product's measured row above (amount must stay 1 there) and is not what this
-- file is testing.
-- tare_weight is deliberately non-zero (50): a zero starting value would leave the
-- negative control below trivially true regardless of whether the trigger still
-- rescaled it.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, quick_consume_amount, quick_open_amount, calories, tare_weight)
VALUES (
	'Spike12 rescale product',
	(SELECT id FROM locations WHERE name = 'Spike12 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike12 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike12 gram'),
	10, 5, 100, 50
);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
VALUES (
	(SELECT id FROM quantity_units WHERE name = 'Spike12 gram'),
	(SELECT id FROM quantity_units WHERE name = 'Spike12 kilogram'),
	0.001,
	(SELECT id FROM products WHERE name = 'Spike12 rescale product')
);
UPDATE products SET qu_id_stock = (SELECT id FROM quantity_units WHERE name = 'Spike12 kilogram')
WHERE name = 'Spike12 rescale product';

SELECT is(
	(SELECT quick_consume_amount FROM products WHERE name = 'Spike12 rescale product'),
	0.01::double precision,
	'Changing qu_id_stock rescales quick_consume_amount by the conversion factor'
);
SELECT is(
	(SELECT quick_open_amount FROM products WHERE name = 'Spike12 rescale product'),
	0.005::double precision,
	'...and quick_open_amount the same way'
);
SELECT is(
	(SELECT calories FROM products WHERE name = 'Spike12 rescale product'),
	100000::double precision,
	'...while calories divides by the factor instead of multiplying'
);
SELECT is(
	(SELECT tare_weight FROM products WHERE name = 'Spike12 rescale product'),
	50::double precision,
	'tare_weight is left untouched - migration 0275 dropped its rescale (container tare now lives on locations)'
);

-- Negative control on the rescale trigger itself: an UPDATE that does not touch
-- qu_id_stock never fires it, so an unrelated column write leaves the three rescaled
-- columns exactly where the rescale above left them.
UPDATE products SET description = 'Spike12 description' WHERE name = 'Spike12 rescale product';
SELECT is(
	(SELECT quick_consume_amount FROM products WHERE name = 'Spike12 rescale product'),
	0.01::double precision,
	'An UPDATE that leaves qu_id_stock alone does not re-fire the rescale'
);
SELECT is(
	(SELECT description FROM products WHERE name = 'Spike12 rescale product'),
	'Spike12 description',
	'...and still writes the column it actually targeted'
);

SELECT * FROM finish();
