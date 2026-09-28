-- Issue #508 (M8, #487 remediation), ADR-0034: migrations/0293.pgsql.sql redefines
-- product_groups_missing so its member join reaches every group in an ancestor's subtree
-- through product_groups_resolved, instead of only the group a product is filed in directly.
--
-- This is the ADR's own acceptance experiment (.devtools/adr0034/rollup.sql,
-- .devtools/adr0034/fixtures.sql), ported into a permanent pgTAP file rather than left as a
-- disposable CREATE OR REPLACE VIEW run inside a rolled-back transaction. Run against the
-- fully migrated schema (0293 already applied), these are the same sixteen checks the
-- experiment's README records: nine of them fail against the pre-0293 direct-membership view
-- (`git show HEAD~1:migrations/... ` before this migration existed, or equivalently
-- `.devtools/adr0034/run.sh <db> current`, which is a negative control asserting exactly
-- nine failures against commit 956c5a07) and all sixteen pass once the roll-up join lands.

SELECT plan(16);

INSERT INTO product_groups (name, min_stock_amount, parent_product_group_id) VALUES
	('M508 root', 3, NULL);
INSERT INTO product_groups (name, min_stock_amount, parent_product_group_id) VALUES
	('M508 child', 10, (SELECT id FROM product_groups WHERE name = 'M508 root'));
INSERT INTO product_groups (name, min_stock_amount, parent_product_group_id) VALUES
	('M508 leaf', 0, (SELECT id FROM product_groups WHERE name = 'M508 child'));
INSERT INTO product_groups (name, min_stock_amount, parent_product_group_id) VALUES
	('M508 empty', 7, NULL);
INSERT INTO product_groups (name, min_stock_amount, parent_product_group_id) VALUES
	('M508 unrelated', 2, NULL);

INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, product_group_id, active, treat_opened_as_out_of_stock) VALUES
	('M508 child product', 2, 2, 2, (SELECT id FROM product_groups WHERE name = 'M508 child'), 1, 0);
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, product_group_id, active, treat_opened_as_out_of_stock) VALUES
	('M508 direct product', 2, 2, 2, (SELECT id FROM product_groups WHERE name = 'M508 root'), 1, 0);
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, product_group_id, active, treat_opened_as_out_of_stock) VALUES
	('M508 leaf product', 2, 2, 2, (SELECT id FROM product_groups WHERE name = 'M508 leaf'), 1, 1);
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, product_group_id, active, treat_opened_as_out_of_stock) VALUES
	('M508 inactive product', 2, 2, 2, (SELECT id FROM product_groups WHERE name = 'M508 leaf'), 0, 0);

INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, open) VALUES
	((SELECT id FROM products WHERE name = 'M508 child product'), 5, '2027-06-01', '2026-09-27', 'm508-child', 2, 0);

SELECT is(
	(SELECT count(*)::integer FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	0,
	'#508: parent minimum 3 is satisfied by five child units'
);
SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 child')),
	5::numeric,
	'#508: child minimum 10 is short by five'
);

UPDATE product_groups SET min_stock_amount = 10 WHERE name = 'M508 root';
UPDATE product_groups SET min_stock_amount = 2 WHERE name = 'M508 child';

SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	5::numeric,
	'#508: parent minimum 10 is short by five'
);
SELECT is(
	(SELECT count(*)::integer FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 child')),
	0,
	'#508: child minimum 2 is satisfied independently'
);

INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, open) VALUES
	((SELECT id FROM products WHERE name = 'M508 direct product'), 1, '2027-06-01', '2026-09-27', 'm508-direct', 2, 0),
	((SELECT id FROM products WHERE name = 'M508 leaf product'), 2, '2027-06-01', '2026-09-27', 'm508-leaf', 2, 0),
	((SELECT id FROM products WHERE name = 'M508 leaf product'), 4, '2027-06-01', '2026-09-27', 'm508-open', 2, 1),
	((SELECT id FROM products WHERE name = 'M508 inactive product'), 100, '2027-06-01', '2026-09-27', 'm508-inactive', 2, 0);

SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	2::numeric,
	'#508: three-level tree counts direct and descendant stock once, excludes inactive and opened stock'
);
SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 empty')),
	7::numeric,
	'#508: empty group is short by its entire minimum'
);
SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 unrelated')),
	2::numeric,
	'#508: unrelated branch receives no stock'
);

UPDATE products SET parent_product_id = (SELECT id FROM products WHERE name = 'M508 direct product')
	WHERE name = 'M508 leaf product';

SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	2::numeric,
	'#508: packaging parentage does not double-count physical stock'
);

UPDATE products SET treat_opened_as_out_of_stock = 0 WHERE name = 'M508 leaf product';

SELECT is(
	(SELECT count(*)::integer FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	0,
	'#508: opened stock contributes when exclusion is disabled'
);

UPDATE products SET treat_opened_as_out_of_stock = 1 WHERE name = 'M508 leaf product';
UPDATE product_groups SET min_stock_amount = 100 WHERE name = 'M508 child';

SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	2::numeric,
	'#508: child minimum does not enter ancestor calculation'
);

UPDATE product_groups SET active = 0 WHERE name = 'M508 unrelated';

SELECT is(
	(SELECT count(*)::integer FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 unrelated')),
	0,
	'#508: inactive group does not report its own shortfall'
);

UPDATE product_groups SET min_stock_amount = 0 WHERE name = 'M508 empty';

SELECT is(
	(SELECT count(*)::integer FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 empty')),
	0,
	'#508: zero-minimum group is omitted'
);

-- The child holds five units and its active leaf holds two effective units. Deactivating the
-- intermediate group must hide only its own shortfall, not the stock crossing it.
UPDATE product_groups SET active = 0 WHERE name = 'M508 child';

SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	2::numeric,
	'#508: active products in and beneath an inactive intermediate group still count'
);
SELECT is(
	(SELECT count(*)::integer FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 child')),
	0,
	'#508: inactive intermediate group does not report its own shortfall'
);

UPDATE products SET active = 0 WHERE name = 'M508 child product';

SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	7::numeric,
	'#508: inactive product is excluded while active leaf stock crosses the inactive intermediate'
);

UPDATE product_groups SET active = 0 WHERE name = 'M508 leaf';

SELECT is(
	(SELECT amount_missing::numeric FROM product_groups_missing WHERE id = (SELECT id FROM product_groups WHERE name = 'M508 root')),
	7::numeric,
	'#508: active leaf product still counts when both descendant groups are inactive'
);

SELECT * FROM finish();
