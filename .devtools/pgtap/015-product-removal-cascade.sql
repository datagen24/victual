-- migrations/0279.pgsql.sql (plan 31, issue 125): trg_cascade_product_removal is a
-- CREATE OR REPLACE of the pre-existing AFTER DELETE ON products function
-- (db/pgsql/baseline/06_triggers_a.sql) - the trigger binding itself is untouched, only
-- the function body gains two DELETEs for product_substitutions, on both
-- from_product_id and to_product_id, since either side of a directed edge losing its
-- product invalidates the row. This file exercises that addition directly, plus one
-- pre-existing cascade (stock) as a sanity check that CREATE OR REPLACE did not drop
-- any of the function's other DELETEs, and a negative control that an edge untouched by
-- the deleted product survives.

SELECT plan(4);

INSERT INTO locations (name) VALUES ('Spike15 location');
INSERT INTO quantity_units (name) VALUES ('Spike15 qu');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike15 A', (SELECT id FROM locations WHERE name = 'Spike15 location'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu')),
	('Spike15 B', (SELECT id FROM locations WHERE name = 'Spike15 location'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu')),
	('Spike15 C', (SELECT id FROM locations WHERE name = 'Spike15 location'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu')),
	('Spike15 D', (SELECT id FROM locations WHERE name = 'Spike15 location'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike15 qu'));

INSERT INTO stock (product_id, amount, stock_id) VALUES
	((SELECT id FROM products WHERE name = 'Spike15 A'), 1, 'spike15-a');

-- A -> B (A is the "from" side) and C -> A (A is the "to" side), plus an edge that does
-- not touch A at all (C -> D), which the cascade must leave alone.
INSERT INTO product_substitutions (from_product_id, to_product_id) VALUES
	((SELECT id FROM products WHERE name = 'Spike15 A'), (SELECT id FROM products WHERE name = 'Spike15 B')),
	((SELECT id FROM products WHERE name = 'Spike15 C'), (SELECT id FROM products WHERE name = 'Spike15 A')),
	((SELECT id FROM products WHERE name = 'Spike15 C'), (SELECT id FROM products WHERE name = 'Spike15 D'));

DELETE FROM products WHERE name = 'Spike15 A';

SELECT ok(
	NOT EXISTS(SELECT 1 FROM stock WHERE stock_id = 'spike15-a'),
	'Deleting a product still cascades to its stock rows (a pre-existing DELETE the function already had)'
);
SELECT ok(
	NOT EXISTS(
		SELECT 1 FROM product_substitutions ps
		JOIN products b ON b.name = 'Spike15 B'
		WHERE ps.to_product_id = b.id
	),
	'The edge where the deleted product was the "from" side is gone'
);
SELECT ok(
	NOT EXISTS(
		SELECT 1 FROM product_substitutions ps
		JOIN products c ON c.name = 'Spike15 C'
		WHERE ps.from_product_id = c.id
			AND ps.to_product_id NOT IN (SELECT id FROM products WHERE name = 'Spike15 D')
	),
	'The edge where the deleted product was the "to" side is gone too'
);
SELECT ok(
	EXISTS(
		SELECT 1 FROM product_substitutions ps
		JOIN products c ON c.name = 'Spike15 C'
		JOIN products d ON d.name = 'Spike15 D'
		WHERE ps.from_product_id = c.id AND ps.to_product_id = d.id
	),
	'An edge that never named the deleted product survives untouched'
);

SELECT * FROM finish();
