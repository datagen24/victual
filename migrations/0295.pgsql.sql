-- Issue #552 (#487 remediation), maintainer decision D4, modelled on ADR-0029
-- (docs/adr/0029-stock-locations-reference-existing-locations.md) and the migration that
-- implemented it (migrations/0288.pgsql.sql): add the foreign keys the baseline never gave
-- any of products' six upstream reference columns - location_id, qu_id_purchase,
-- qu_id_stock, qu_id_consume, qu_id_price, product_group_id.
--
-- NO AUTOMATIC REPAIR: ABORT WITH A REPORT, BY MAINTAINER DECISION. Two paths reach these
-- columns, and they are handled separately:
--   * Upgrading an existing Victual installation runs this migration over live data. Before
--     this migration nothing refused deleting a location, quantity unit or product group that
--     a product still named, so a product can already hold a dangling reference. The preflight
--     below lists every affected product, column and missing id, and aborts the upgrade before
--     any foreign key is added, exactly as 0288 does for stock.location_id. Nothing is changed
--     or invented; the operator repairs the data and reruns the migration.
--   * A legacy Grocy import copies into a blank database; services/Database/DatabaseImporter.php's
--     AssertProductReferences() reports and refuses there, before truncation (ADR-0029's
--     import precedent).
--
-- ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE on every one of the six, exactly as
-- 0288's stock_location_id_fkey. No cascade, no automatic repair on a future delete. A future
-- violation reaches GenericEntityApiController::DeleteObject()'s existing generic 23503
-- handling (issue #515/PR #551) and answers the documented 400, same as every other enforced
-- foreign key in this tree (see tests/Pgsql/ReferenceRefusalTest.php,
-- tests/Pgsql/ProductReferenceIntegrityTest.php).
-- Bounded blocking, as 0288 does: the index builds and FK additions take write-blocking locks.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE products, locations, quantity_units, product_groups IN SHARE ROW EXCLUSIVE MODE;

-- Upstream Grocy stores 0 in qu_id_consume and qu_id_price to mean "unset" (its migrations
-- 0210/0219 test IFNULL(x, 0) = 0), and databases imported before the importer translated it
-- (PR #624) may still carry that 0. It is upstream's own meaning, not a dangling reference, so
-- it becomes NULL here exactly as the importer now does. No other value is changed.
UPDATE products SET qu_id_consume = NULL WHERE qu_id_consume = 0;
UPDATE products SET qu_id_price = NULL WHERE qu_id_price = 0;

DO $$
DECLARE
    ref record;
    dangling_count bigint;
    sample text;
    report text := '';
    total bigint := 0;
BEGIN
    FOR ref IN SELECT * FROM (VALUES
        ('location_id', 'locations'),
        ('qu_id_purchase', 'quantity_units'),
        ('qu_id_stock', 'quantity_units'),
        ('qu_id_consume', 'quantity_units'),
        ('qu_id_price', 'quantity_units'),
        ('product_group_id', 'product_groups')
    ) AS r(col, tbl)
    LOOP
        EXECUTE format('SELECT count(*) FROM products p LEFT JOIN %I t ON t.id = p.%I WHERE p.%I IS NOT NULL AND t.id IS NULL', ref.tbl, ref.col, ref.col)
        INTO dangling_count;
        IF dangling_count > 0 THEN
            EXECUTE format('SELECT string_agg(format(''(%%s, %%s)'', id, ref_id), '', '' ORDER BY id) FROM (SELECT p.id, p.%I AS ref_id FROM products p LEFT JOIN %I t ON t.id = p.%I WHERE p.%I IS NOT NULL AND t.id IS NULL ORDER BY p.id LIMIT 10) d', ref.col, ref.tbl, ref.col, ref.col)
            INTO sample;
            report := report || format(E'\n - products.%s: %s rows reference missing %s. Sample (product id, %s): %s. List all: SELECT p.id, p.%s FROM products p LEFT JOIN %s t ON t.id = p.%s WHERE p.%s IS NOT NULL AND t.id IS NULL ORDER BY p.id;', ref.col, dangling_count, ref.tbl, ref.col, sample, ref.col, ref.tbl, ref.col, ref.col);
            total := total + dangling_count;
        END IF;
    END LOOP;

    IF total > 0 THEN
        RAISE EXCEPTION 'Migration refused: products reference rows that no longer exist.%', report
            USING HINT = 'Choose an explicit repair for each listed product and rerun migration. No product has been changed.';
    END IF;
END $$;

CREATE INDEX products_location_id_idx ON products (location_id);
CREATE INDEX products_qu_id_purchase_idx ON products (qu_id_purchase);
CREATE INDEX products_qu_id_stock_idx ON products (qu_id_stock);
CREATE INDEX products_qu_id_consume_idx ON products (qu_id_consume);
CREATE INDEX products_qu_id_price_idx ON products (qu_id_price);
CREATE INDEX products_product_group_id_idx ON products (product_group_id);

ALTER TABLE products ADD CONSTRAINT products_location_id_fkey
	FOREIGN KEY (location_id) REFERENCES locations (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

ALTER TABLE products ADD CONSTRAINT products_qu_id_purchase_fkey
	FOREIGN KEY (qu_id_purchase) REFERENCES quantity_units (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

ALTER TABLE products ADD CONSTRAINT products_qu_id_stock_fkey
	FOREIGN KEY (qu_id_stock) REFERENCES quantity_units (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

ALTER TABLE products ADD CONSTRAINT products_qu_id_consume_fkey
	FOREIGN KEY (qu_id_consume) REFERENCES quantity_units (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

ALTER TABLE products ADD CONSTRAINT products_qu_id_price_fkey
	FOREIGN KEY (qu_id_price) REFERENCES quantity_units (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

ALTER TABLE products ADD CONSTRAINT products_product_group_id_fkey
	FOREIGN KEY (product_group_id) REFERENCES product_groups (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

-- Issue #558 (#487 remediation): deleting a product retires its stock entries' labels with
-- product_name null. trg_cascade_product_removal (migrations/0279.pgsql.sql) is an AFTER
-- DELETE ON products trigger; by the time its "DELETE FROM stock WHERE product_id = OLD.id"
-- runs, the products row is already gone, so retire_stock_entry_labels' (migrations/
-- 0283.pgsql.php) own `(SELECT p.name FROM products p WHERE p.id = OLD.product_id)` lookup
-- finds nothing and snapshots NULL.
--
-- Fix: retire those labels here first, with OLD.name - which is still readable inside an
-- AFTER DELETE trigger, since OLD is the row image, not a live read of the table - before the
-- stock rows are deleted. This is the smaller of the issue's two suggested fixes: it extends
-- trg_cascade_product_removal's function body by CREATE OR REPLACE, the same mechanism 0279
-- itself used to add the product_substitutions cascade, and it leaves retire_stock_entry_
-- labels and its BEFORE DELETE ON stock trigger completely untouched, so a direct
-- `DELETE FROM stock` (with its product row still present) keeps behaving exactly as
-- .devtools/pgtap/016-label-retirement-family.sql already proves. The alternative the issue
-- names - moving retire_stock_entry_labels itself to run earlier, or off of `stock` entirely
-- - would touch a second migration's trigger for every stock deletion path, not only this
-- cascade.
--
-- The new UPDATE only retires a label that is still live (retired_at IS NULL), so when the
-- subsequent `DELETE FROM stock` fires retire_stock_entry_labels per row, that trigger's own
-- `retired_at IS NULL` guard already excludes the rows this UPDATE just retired - no double
-- retirement, no snapshot overwrite.
CREATE OR REPLACE FUNCTION trg_cascade_product_removal() RETURNS TRIGGER AS $$
BEGIN
	UPDATE labels
	SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object(
			'id', s.id,
			'product_name', OLD.name,
			'best_before_date', s.best_before_date,
			'amount', s.amount)
	FROM stock s
	WHERE labels.kind = 'stock_entry'
		AND labels.target_id = s.id
		AND labels.retired_at IS NULL
		AND s.product_id = OLD.id;

	DELETE FROM stock
	WHERE product_id = OLD.id;

	DELETE FROM stock_log
	WHERE product_id = OLD.id;

	DELETE FROM product_barcodes
	WHERE product_id = OLD.id;

	DELETE FROM quantity_unit_conversions
	WHERE product_id = OLD.id;

	DELETE FROM recipes_pos
	WHERE product_id = OLD.id;

	UPDATE recipes
	SET product_id = NULL
	WHERE product_id = OLD.id;

	DELETE FROM meal_plan
	WHERE product_id = OLD.id
		AND type = 'product';

	DELETE FROM shopping_list
	WHERE product_id = OLD.id;

	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id IN (SELECT id FROM userfields WHERE entity = 'products');

	DELETE FROM product_substitutions
	WHERE from_product_id = OLD.id
		OR to_product_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
