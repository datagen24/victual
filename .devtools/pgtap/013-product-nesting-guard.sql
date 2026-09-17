-- migrations/0277.pgsql.sql (issue #148): trg_enfore_product_nesting_level (function
-- name and typo both preserved from upstream) fires on INSERT now as well as UPDATE,
-- and checks both directions of a three-level chain - the direction the original,
-- UPDATE-only, one-way check could never see. Given Protein -> Beef -> a cut: refusing
-- only "NEW already has children" (the original predicate) never catches a fresh INSERT
-- of the cut with parent_product_id = Beef, because the cut has no children of its own
-- to inspect. Both directions are exercised below, each with its own untouched fixture
-- so one scenario's mutation cannot leak into another's starting state, plus a plain
-- one-level parent (the shape everything else in the tree already uses) staying legal
-- on both INSERT and UPDATE.

SELECT plan(6);

INSERT INTO locations (name) VALUES ('Spike13 location');
INSERT INTO quantity_units (name) VALUES ('Spike13 qu');

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike13 RootA', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu')),
	('Spike13 ChildA1', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu')),
	('Spike13 Beef', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu')),
	('Spike13 Protein2', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu')),
	('Spike13 Beef2', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu')),
	('Spike13 Protein3', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu')),
	('Spike13 Beef3', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu')),
	('Spike13 Cut3', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'));

-- One level of nesting is exactly what the schema supports, and it is legal both ways.
SELECT lives_ok(
	format('UPDATE products SET parent_product_id = (SELECT id FROM products WHERE name = %L) WHERE name = %L',
		'Spike13 RootA', 'Spike13 ChildA1'),
	'ChildA1 -> RootA, a plain one-level parent, is accepted on UPDATE'
);
SELECT lives_ok(
	format('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, parent_product_id) VALUES (%L, (SELECT id FROM locations WHERE name = %L), (SELECT id FROM quantity_units WHERE name = %L), (SELECT id FROM quantity_units WHERE name = %L), (SELECT id FROM products WHERE name = %L))',
		'Spike13 ChildA2', 'Spike13 location', 'Spike13 qu', 'Spike13 qu', 'Spike13 RootA'),
	'A second product parented directly to the same root is accepted the same way on INSERT'
);

-- The direction the original, UPDATE-only check already caught: Beef has just been
-- given a child (legal - Beef has no parent yet), so Beef itself may not now be given
-- one too.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, parent_product_id) VALUES
	('Spike13 Cut', (SELECT id FROM locations WHERE name = 'Spike13 location'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike13 qu'), (SELECT id FROM products WHERE name = 'Spike13 Beef'));
SELECT throws_ok(
	format('UPDATE products SET parent_product_id = (SELECT id FROM products WHERE name = %L) WHERE name = %L',
		'Spike13 RootA', 'Spike13 Beef'),
	'Unsupported product nesting level detected (currently only 1 level is supported)',
	'Beef cannot be given a parent while something already treats Beef as its own parent'
);

-- The direction the issue actually found, via INSERT: Beef2 is already nested under
-- Protein2, so a fresh row naming Beef2 as its own parent would arrive as a grandchild -
-- never caught pre-0277 because the original predicate only inspected the row's own
-- children, and INSERT was not even guarded.
UPDATE products SET parent_product_id = (SELECT id FROM products WHERE name = 'Spike13 Protein2')
	WHERE name = 'Spike13 Beef2';
SELECT throws_ok(
	format('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, parent_product_id) VALUES (%L, (SELECT id FROM locations WHERE name = %L), (SELECT id FROM quantity_units WHERE name = %L), (SELECT id FROM quantity_units WHERE name = %L), (SELECT id FROM products WHERE name = %L))',
		'Spike13 NewCut', 'Spike13 location', 'Spike13 qu', 'Spike13 qu', 'Spike13 Beef2'),
	'Unsupported product nesting level detected (currently only 1 level is supported)',
	'INSERTing a row parented to something that is itself already nested is refused'
);

-- The same direction reached via UPDATE instead of INSERT: Cut3 exists parentless, Beef3
-- is already nested under Protein3, and re-parenting Cut3 onto Beef3 hits the identical
-- guard.
UPDATE products SET parent_product_id = (SELECT id FROM products WHERE name = 'Spike13 Protein3')
	WHERE name = 'Spike13 Beef3';
SELECT throws_ok(
	format('UPDATE products SET parent_product_id = (SELECT id FROM products WHERE name = %L) WHERE name = %L',
		'Spike13 Beef3', 'Spike13 Cut3'),
	'Unsupported product nesting level detected (currently only 1 level is supported)',
	'UPDATEing an existing row onto a parent that is itself already nested is refused too'
);

-- A write that never sets parent_product_id at all does not run the guard's body.
SELECT lives_ok(
	format('UPDATE products SET description = %L WHERE name = %L', 'Spike13 description', 'Spike13 Cut3'),
	'A write that leaves parent_product_id untouched does not run the guard'
);

SELECT * FROM finish();
