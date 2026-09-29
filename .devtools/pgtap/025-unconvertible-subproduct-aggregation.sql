-- Issue #622 (#487 remediation), against the fully migrated application schema:
-- migrations/0298.pgsql.sql recreates stock_current so that a sub product with no
-- resolved conversion to its parent's own stock unit - or one whose only resolved
-- conversion has a non-positive factor - contributes nothing to amount_aggregated,
-- amount_opened_aggregated and amount_measured, instead of being counted 1:1 through the
-- COALESCE(qucr.factor, 1.0) fallback those three columns shared. This is #553's
-- maintainer decision D4 (already applied to Consume/Open/GetProductStockEntries by PR
-- #621) applied to the read side stock_current itself computes.
--
-- Each block below builds one parent with a mix of sub products - one genuinely
-- convertible, one with no resolved conversion at all, and (in the first block) one with a
-- resolved but negative factor - and asserts the aggregate the fixed view now returns. A
-- negative control proves the ordinary single-conversion case, and the parent's own stock
-- (products_resolved's self-row, which has no cache entry to resolve either and must keep
-- falling back to factor 1), are both unaffected.

SELECT plan(5);

-- ---------------------------------------------------------------------------------------
-- #622: amount_aggregated must exclude an unconvertible sub product and a sub product
-- whose only resolved conversion has a negative factor, while still counting the parent's
-- own stock (self-row, factor 1) and a genuinely convertible sub product (factor 2) in
-- full. Reproduces this issue's own report: before the fix, the unconvertible child (5)
-- and the negative-factor child (4) were both counted 1:1, giving 1 + 4 + 5 + 4 = 14
-- instead of the correct 1 + 4 = 5.
-- ---------------------------------------------------------------------------------------

INSERT INTO quantity_units (name) VALUES
	('Issue622 parent qu'), ('Issue622 convertible child qu'), ('Issue622 unconvertible child qu'), ('Issue622 negative-factor child qu');
INSERT INTO locations (name) VALUES ('Issue622 location');

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622 parent', (SELECT id FROM locations WHERE name = 'Issue622 location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 parent qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 parent qu'));
INSERT INTO products (name, parent_product_id, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622 convertible child', (SELECT id FROM products WHERE name = 'Issue622 parent'),
		(SELECT id FROM locations WHERE name = 'Issue622 location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 convertible child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 convertible child qu')),
	('Issue622 unconvertible child', (SELECT id FROM products WHERE name = 'Issue622 parent'),
		(SELECT id FROM locations WHERE name = 'Issue622 location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 unconvertible child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 unconvertible child qu')),
	('Issue622 negative-factor child', (SELECT id FROM products WHERE name = 'Issue622 parent'),
		(SELECT id FROM locations WHERE name = 'Issue622 location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 negative-factor child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622 negative-factor child qu'));

-- Written directly into the cache table stock_current joins against, the same way
-- 018-audit-view-corrections.sql does - what is under test is the view's arithmetic once a
-- (or no) resolved factor is known, not the cache's own population path.
INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor) VALUES
	((SELECT id FROM products WHERE name = 'Issue622 convertible child'), (SELECT id FROM quantity_units WHERE name = 'Issue622 convertible child qu'), (SELECT id FROM quantity_units WHERE name = 'Issue622 parent qu'), '2'),
	((SELECT id FROM products WHERE name = 'Issue622 negative-factor child'), (SELECT id FROM quantity_units WHERE name = 'Issue622 negative-factor child qu'), (SELECT id FROM quantity_units WHERE name = 'Issue622 parent qu'), '-3');
-- Deliberately no row at all for 'Issue622 unconvertible child' -> parent qu: genuinely
-- unconvertible, not merely at factor 1.

INSERT INTO stock (product_id, amount, stock_id, location_id) VALUES
	((SELECT id FROM products WHERE name = 'Issue622 parent'), 1, 'issue622-parent', (SELECT id FROM locations WHERE name = 'Issue622 location')),
	((SELECT id FROM products WHERE name = 'Issue622 convertible child'), 2, 'issue622-convertible', (SELECT id FROM locations WHERE name = 'Issue622 location')),
	((SELECT id FROM products WHERE name = 'Issue622 unconvertible child'), 5, 'issue622-unconvertible', (SELECT id FROM locations WHERE name = 'Issue622 location')),
	((SELECT id FROM products WHERE name = 'Issue622 negative-factor child'), 4, 'issue622-negative', (SELECT id FROM locations WHERE name = 'Issue622 location'));

SELECT is(
	(SELECT amount_aggregated FROM stock_current WHERE product_id = (SELECT id FROM products WHERE name = 'Issue622 parent')),
	5.0::double precision,
	'#622: amount_aggregated is the parent''s own stock (1) plus the convertible child converted by its own factor (2 * 2 = 4); the unconvertible child (5) and the negative-factor child (4) contribute nothing'
);

-- ---------------------------------------------------------------------------------------
-- #622: amount_opened_aggregated shares the same COALESCE(qucr.factor, 1.0) fallback
-- (fixed for a different defect, #501, by migrations/0289.pgsql.sql) and must exclude the
-- same unconvertible child from the opened aggregate.
-- ---------------------------------------------------------------------------------------

INSERT INTO quantity_units (name) VALUES ('Issue622b parent qu'), ('Issue622b convertible child qu'), ('Issue622b unconvertible child qu');
INSERT INTO locations (name) VALUES ('Issue622b location');

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622b parent', (SELECT id FROM locations WHERE name = 'Issue622b location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622b parent qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622b parent qu'));
INSERT INTO products (name, parent_product_id, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622b convertible child', (SELECT id FROM products WHERE name = 'Issue622b parent'),
		(SELECT id FROM locations WHERE name = 'Issue622b location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622b convertible child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622b convertible child qu')),
	('Issue622b unconvertible child', (SELECT id FROM products WHERE name = 'Issue622b parent'),
		(SELECT id FROM locations WHERE name = 'Issue622b location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622b unconvertible child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622b unconvertible child qu'));

INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor) VALUES
	((SELECT id FROM products WHERE name = 'Issue622b convertible child'), (SELECT id FROM quantity_units WHERE name = 'Issue622b convertible child qu'), (SELECT id FROM quantity_units WHERE name = 'Issue622b parent qu'), '3');

INSERT INTO stock (product_id, amount, stock_id, open, location_id) VALUES
	((SELECT id FROM products WHERE name = 'Issue622b convertible child'), 2, 'issue622b-convertible', 1, (SELECT id FROM locations WHERE name = 'Issue622b location')),
	((SELECT id FROM products WHERE name = 'Issue622b unconvertible child'), 5, 'issue622b-unconvertible', 1, (SELECT id FROM locations WHERE name = 'Issue622b location'));

SELECT is(
	(SELECT amount_opened_aggregated FROM stock_current WHERE product_id = (SELECT id FROM products WHERE name = 'Issue622b parent')),
	6.0::double precision,
	'#622: amount_opened_aggregated counts the convertible child''s opened amount converted by its own factor (2 * 3 = 6); the unconvertible child''s opened amount (5) contributes nothing'
);

-- ---------------------------------------------------------------------------------------
-- #622: amount_measured multiplies by this same qucr.factor term a second time (alongside
-- its own, unrelated qucr_measure conversion), and must exclude an unconvertible child's
-- measured amount too - even when that child's OWN measurement conversion (opened unit ->
-- its own stock unit) resolves perfectly fine. Before the fix this measured amount was
-- still counted in full (5 * 1 * 1.0 fallback = 5); the parent-rollup conversion, not the
-- measurement conversion, is what must exclude it.
-- ---------------------------------------------------------------------------------------

INSERT INTO quantity_units (name) VALUES ('Issue622c parent qu'), ('Issue622c unconvertible child qu'), ('Issue622c measure qu');
INSERT INTO locations (name) VALUES ('Issue622c location');

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622c parent', (SELECT id FROM locations WHERE name = 'Issue622c location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622c parent qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622c parent qu'));
INSERT INTO products (name, parent_product_id, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622c unconvertible child', (SELECT id FROM products WHERE name = 'Issue622c parent'),
		(SELECT id FROM locations WHERE name = 'Issue622c location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622c unconvertible child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622c unconvertible child qu'));

-- The child's OWN measurement conversion (measure qu -> the child's own stock qu) resolves
-- fine - only the parent-rollup conversion (child's stock qu -> parent's stock qu) is
-- missing.
INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor) VALUES
	((SELECT id FROM products WHERE name = 'Issue622c unconvertible child'), (SELECT id FROM quantity_units WHERE name = 'Issue622c measure qu'), (SELECT id FROM quantity_units WHERE name = 'Issue622c unconvertible child qu'), '1');

-- stock_measurement_coherence_check (migrations/0275.pgsql.sql) requires open = 1 AND
-- amount = 1 on any row that carries a measurement (opened_amount/opened_qu_id not null) -
-- amount is the whole (single) container; opened_amount is what was measured inside it.
INSERT INTO stock (product_id, amount, stock_id, open, opened_amount, opened_qu_id, location_id) VALUES
	((SELECT id FROM products WHERE name = 'Issue622c unconvertible child'), 1, 'issue622c-unconvertible', 1, 5, (SELECT id FROM quantity_units WHERE name = 'Issue622c measure qu'), (SELECT id FROM locations WHERE name = 'Issue622c location'));

SELECT is(
	(SELECT amount_measured FROM stock_current WHERE product_id = (SELECT id FROM products WHERE name = 'Issue622c parent')),
	0.0::double precision,
	'#622: amount_measured excludes the unconvertible child''s measured amount even though the child''s own measurement conversion resolves fine - it is the missing parent-rollup conversion that excludes it'
);

-- ---------------------------------------------------------------------------------------
-- Negative control: the ordinary case (a single sub product with a genuine, positive
-- resolved conversion, no unconvertible sibling) must aggregate exactly as before this
-- migration.
-- ---------------------------------------------------------------------------------------

INSERT INTO quantity_units (name) VALUES ('Issue622d parent qu'), ('Issue622d child qu');
INSERT INTO locations (name) VALUES ('Issue622d location');

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622d parent', (SELECT id FROM locations WHERE name = 'Issue622d location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622d parent qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622d parent qu'));
INSERT INTO products (name, parent_product_id, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622d child', (SELECT id FROM products WHERE name = 'Issue622d parent'),
		(SELECT id FROM locations WHERE name = 'Issue622d location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622d child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622d child qu'));

INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor) VALUES
	((SELECT id FROM products WHERE name = 'Issue622d child'), (SELECT id FROM quantity_units WHERE name = 'Issue622d child qu'), (SELECT id FROM quantity_units WHERE name = 'Issue622d parent qu'), '4');

INSERT INTO stock (product_id, amount, stock_id, location_id) VALUES
	((SELECT id FROM products WHERE name = 'Issue622d child'), 3, 'issue622d-child', (SELECT id FROM locations WHERE name = 'Issue622d location'));

SELECT is(
	(SELECT amount_aggregated FROM stock_current WHERE product_id = (SELECT id FROM products WHERE name = 'Issue622d parent')),
	12.0::double precision,
	'#622 negative control: a genuinely convertible sub product with no unconvertible sibling still aggregates by its own resolved factor (3 * 4 = 12), unaffected by this migration'
);

-- ---------------------------------------------------------------------------------------
-- Negative control: a product with no sub products at all (products_resolved's self-row
-- only) still reports its own stock through the second UNION branch, at factor 1 -
-- unaffected, since that branch never joins qucr for its own amount.
-- ---------------------------------------------------------------------------------------

INSERT INTO quantity_units (name) VALUES ('Issue622e qu');
INSERT INTO locations (name) VALUES ('Issue622e location');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Issue622e standalone', (SELECT id FROM locations WHERE name = 'Issue622e location'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622e qu'),
		(SELECT id FROM quantity_units WHERE name = 'Issue622e qu'));
INSERT INTO stock (product_id, amount, stock_id, location_id) VALUES
	((SELECT id FROM products WHERE name = 'Issue622e standalone'), 7, 'issue622e-standalone', (SELECT id FROM locations WHERE name = 'Issue622e location'));

SELECT is(
	(SELECT amount_aggregated FROM stock_current WHERE product_id = (SELECT id FROM products WHERE name = 'Issue622e standalone')),
	7.0::double precision,
	'#622 negative control: a standalone product with no sub products of its own reports its raw stock amount, unaffected by this migration'
);

SELECT * FROM finish();
