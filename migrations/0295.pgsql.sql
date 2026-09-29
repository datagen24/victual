-- Issue #552 (#487 remediation), maintainer decision D4, modelled on ADR-0029
-- (docs/adr/0029-stock-locations-reference-existing-locations.md) and the migration that
-- implemented it (migrations/0288.pgsql.sql): add the foreign keys the baseline never gave
-- any of products' six upstream reference columns - location_id, qu_id_purchase,
-- qu_id_stock, qu_id_consume, qu_id_price, product_group_id.
--
-- NO REPAIR STEP, BY MAINTAINER DECISION. An earlier version of this migration added a
-- dangling-reference repair (set to NULL, reported with RAISE NOTICE) ahead of the three
-- NULLABLE columns' constraints, on the premise that a migration might run over a database
-- that already has rows with deleted references. The maintainer corrected that premise: this
-- fork's migration system is one-time and runs once, in order, on every installation,
-- starting from a schema that has never had a dangling reference in it, because nothing
-- before this migration could create one - no foreign key existed here to violate, and no
-- application code path deletes a location, quantity unit or product group out from under a
-- product without already refusing (GenericEntityApiController::DeleteObject(), issue
-- #515/PR #551's generic 23503 handling covers the other five reference classes this tree
-- already enforces). A migration never runs against a populated installation's *existing*
-- rows the way an in-place repair implies; the only route by which a dangling reference could
-- reach these columns at all is `bin/victual-db-import` copying one in from an external
-- source into an otherwise-empty, freshly migrated target. That is an import-time validation
-- question, answered separately by
-- services/Database/DatabaseImporter.php's AssertProductReferences() (issue #552, following
-- ADR-0029's own import precedent), not a schema-migration repair question. So this migration
-- adds plain foreign keys and nothing else, on all six columns alike.
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
