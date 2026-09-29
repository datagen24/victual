-- migrations/0295.pgsql.sql (issue #558, #487 remediation): trg_cascade_product_removal
-- (migrations/0279.pgsql.sql) is an AFTER DELETE ON products trigger, so its own
-- "DELETE FROM stock WHERE product_id = OLD.id" runs after the products row is already
-- gone. retire_stock_entry_labels (migrations/0283.pgsql.php), fired BEFORE DELETE ON stock
-- by that DELETE, looks the product's name up with
-- "(SELECT p.name FROM products p WHERE p.id = OLD.product_id)" - a lookup that finds nothing
-- once the product row has already been deleted, so it used to snapshot product_name: null.
--
-- This file exercises the fix directly: deleting a product with a labelled stock entry now
-- retires that label's snapshot with the product's own name, exactly as
-- 016-label-retirement-family.sql already proves for a direct "DELETE FROM stock" (where the
-- product row is still present when retire_stock_entry_labels runs). A second case covers two
-- labelled stock entries for the same product, so the fix's UPDATE ... FROM stock join is
-- shown to retire each one with its own best_before_date/amount, not only the first.

SELECT plan(5);

INSERT INTO locations (name) VALUES ('Spike22 location');
INSERT INTO quantity_units (name) VALUES ('Spike22 qu');

-- Single stock entry, labelled.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike22 product', (SELECT id FROM locations WHERE name = 'Spike22 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike22 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike22 qu')
);
INSERT INTO stock (product_id, amount, stock_id, best_before_date) VALUES (
	(SELECT id FROM products WHERE name = 'Spike22 product'), 3, 'spike22-stock-a', '2027-02-01'
);
INSERT INTO labels (uid, kind, target_id) VALUES (
	'F' || upper(substr(md5(random()::text), 1, 12)), 'stock_entry',
	(SELECT id FROM stock WHERE stock_id = 'spike22-stock-a')
);

DELETE FROM products WHERE name = 'Spike22 product';

SELECT ok(
	NOT EXISTS(SELECT 1 FROM stock WHERE stock_id = 'spike22-stock-a'),
	'Deleting the product still cascades to its stock row'
);
SELECT ok(
	(SELECT retired_at IS NOT NULL AND target_id IS NULL
		FROM labels WHERE retirement_snapshot ->> 'product_name' = 'Spike22 product'),
	'Deleting a product retires its stock entry label, clears its target'
);
SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels WHERE retirement_snapshot ->> 'product_name' = 'Spike22 product'),
	jsonb_build_object(
		'product_name', 'Spike22 product',
		'best_before_date', '2027-02-01',
		'amount', 3
	),
	'The retired snapshot carries the deleted product''s own name, not null (issue #558) - the regression this migration fixes'
);

-- Two labelled stock entries for one product, to show the UPDATE ... FROM join retires each
-- with its own values rather than only the first row it touches.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike22 product2', (SELECT id FROM locations WHERE name = 'Spike22 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike22 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike22 qu')
);
INSERT INTO stock (product_id, amount, stock_id, best_before_date) VALUES
	((SELECT id FROM products WHERE name = 'Spike22 product2'), 2, 'spike22-stock-b', '2027-03-01'),
	((SELECT id FROM products WHERE name = 'Spike22 product2'), 5, 'spike22-stock-c', '2027-04-01');
INSERT INTO labels (uid, kind, target_id) VALUES
	('1' || upper(substr(md5(random()::text), 1, 12)), 'stock_entry', (SELECT id FROM stock WHERE stock_id = 'spike22-stock-b')),
	('2' || upper(substr(md5(random()::text), 1, 12)), 'stock_entry', (SELECT id FROM stock WHERE stock_id = 'spike22-stock-c'));

DELETE FROM products WHERE name = 'Spike22 product2';

SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels
		WHERE retirement_snapshot ->> 'product_name' = 'Spike22 product2' AND retirement_snapshot ->> 'best_before_date' = '2027-03-01'),
	jsonb_build_object('product_name', 'Spike22 product2', 'best_before_date', '2027-03-01', 'amount', 2),
	'The first of two labelled stock entries keeps its own best_before_date and amount'
);
SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels
		WHERE retirement_snapshot ->> 'product_name' = 'Spike22 product2' AND retirement_snapshot ->> 'best_before_date' = '2027-04-01'),
	jsonb_build_object('product_name', 'Spike22 product2', 'best_before_date', '2027-04-01', 'amount', 5),
	'The second keeps its own values too - the cascade retires every stock entry, not only one'
);
