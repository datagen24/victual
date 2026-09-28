\set ON_ERROR_STOP on
BEGIN;
CREATE EXTENSION IF NOT EXISTS pgtap;
\if :candidate
\ir rollup.sql
\endif
SELECT plan(12);
INSERT INTO product_groups (id, name, min_stock_amount, parent_product_group_id) VALUES
 (97001, 'ADR0034 root', 3, NULL),
 (97002, 'ADR0034 child', 10, 97001),
 (97003, 'ADR0034 leaf', 0, 97002),
 (97004, 'ADR0034 empty', 7, NULL),
 (97005, 'ADR0034 unrelated', 2, NULL);
INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock,
 product_group_id, active, treat_opened_as_out_of_stock) VALUES
 (97001, 'ADR0034 child product', 2, 2, 2, 97002, 1, 0),
 (97002, 'ADR0034 direct product', 2, 2, 2, 97001, 1, 0),
 (97003, 'ADR0034 leaf product', 2, 2, 2, 97003, 1, 1),
 (97004, 'ADR0034 inactive product', 2, 2, 2, 97003, 0, 0);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, open) VALUES
 (97001, 5, '2027-06-01', '2026-09-27', 'adr34-child', 2, 0);
SELECT is((SELECT count(*)::integer FROM product_groups_missing WHERE id = 97001), 0,
 'parent minimum 3 is satisfied by five child units');
SELECT is((SELECT amount_missing::numeric FROM product_groups_missing WHERE id = 97002), 5::numeric,
 'child minimum 10 is short by five');
UPDATE product_groups SET min_stock_amount = 10 WHERE id = 97001;
UPDATE product_groups SET min_stock_amount = 2 WHERE id = 97002;
SELECT is((SELECT amount_missing::numeric FROM product_groups_missing WHERE id = 97001), 5::numeric,
 'parent minimum 10 is short by five');
SELECT is((SELECT count(*)::integer FROM product_groups_missing WHERE id = 97002), 0,
 'child minimum 2 is satisfied independently');
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, open) VALUES
 (97002, 1, '2027-06-01', '2026-09-27', 'adr34-direct', 2, 0),
 (97003, 2, '2027-06-01', '2026-09-27', 'adr34-leaf', 2, 0),
 (97003, 4, '2027-06-01', '2026-09-27', 'adr34-open', 2, 1),
 (97004, 100, '2027-06-01', '2026-09-27', 'adr34-inactive', 2, 0);
SELECT is((SELECT amount_missing::numeric FROM product_groups_missing WHERE id = 97001), 2::numeric,
 'three-level tree counts direct and descendant stock once, excludes inactive and opened stock');
SELECT is((SELECT amount_missing::numeric FROM product_groups_missing WHERE id = 97004), 7::numeric,
 'empty group is short by its entire minimum');
SELECT is((SELECT amount_missing::numeric FROM product_groups_missing WHERE id = 97005), 2::numeric,
 'unrelated branch receives no stock');
UPDATE products SET parent_product_id = 97002 WHERE id = 97003;
SELECT is((SELECT amount_missing::numeric FROM product_groups_missing WHERE id = 97001), 2::numeric,
 'packaging parentage does not double-count physical stock');
UPDATE products SET treat_opened_as_out_of_stock = 0 WHERE id = 97003;
SELECT is((SELECT count(*)::integer FROM product_groups_missing WHERE id = 97001), 0,
 'opened stock contributes when exclusion is disabled');
UPDATE products SET treat_opened_as_out_of_stock = 1 WHERE id = 97003;
UPDATE product_groups SET min_stock_amount = 100 WHERE id = 97002;
SELECT is((SELECT amount_missing::numeric FROM product_groups_missing WHERE id = 97001), 2::numeric,
 'child minimum does not enter ancestor calculation');
UPDATE product_groups SET active = 0 WHERE id = 97005;
SELECT is((SELECT count(*)::integer FROM product_groups_missing WHERE id = 97005), 0,
 'inactive group does not report its own shortfall');
UPDATE product_groups SET min_stock_amount = 0 WHERE id = 97004;
SELECT is((SELECT count(*)::integer FROM product_groups_missing WHERE id = 97004), 0,
 'zero-minimum group is omitted');
SELECT * FROM finish();
ROLLBACK;
