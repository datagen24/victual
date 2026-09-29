-- Issue #552 (#487 remediation), maintainer decision D4, modelled on ADR-0029
-- (docs/adr/0029-stock-locations-reference-existing-locations.md) and the migration that
-- implemented it (migrations/0288.pgsql.sql): add the foreign keys the baseline never gave
-- three of products' six upstream reference columns.
--
-- SCOPE. Issue #552 names six columns: location_id, qu_id_purchase, qu_id_stock,
-- qu_id_consume, qu_id_price, product_group_id. Three of them - product_group_id,
-- qu_id_consume, qu_id_price - are NULLABLE, and this migration gives all three a repair
-- rule and a foreign key below.
--
-- The other three - location_id, qu_id_purchase, qu_id_stock - are NOT NULL
-- (db/pgsql/baseline/01_tables.sql). ADR-0029's own precedent covers only a NULLABLE column
-- (stock.location_id): its repair rule ("preserve the nullable column", decision 2) has
-- nothing to say about a column that cannot be set to NULL, and no other ADR or migration in
-- this tree repairs a dangling NOT NULL reference - the closest cases (quantity_unit_
-- conversions.from_qu_id/to_qu_id, stock.product_id, chores_log.chore_id, and every other
-- NOT NULL upstream reference column) carry no FOREIGN KEY at all, so none of them had to
-- answer this question either. Inventing a fallback location or quantity unit for a product
-- whose original one was deleted picks a physical placement or a unit of measure nobody
-- chose, which is exactly the kind of repair ADR-0029 itself refused to invent for stock rows
-- ("Neither migration nor import changes quantities, assigns locations, or deletes stock to
-- satisfy the constraint", decision 7). So those three columns are left unconstrained here,
-- per FIXER_RULES.md's instruction to stop and report rather than invent a repair rule - see
-- the PR body's "Open questions" for the options.
--
-- REPAIR RULE for the three NULLABLE columns: a dangling reference is set to NULL before the
-- constraint is added, and the count and a bounded sample are reported with RAISE NOTICE so an
-- operator upgrading a populated installation can see exactly what changed. Unlike 0288 (which
-- aborts the migration on any dangling stock.location_id and asks for a manual repair), a NULL
-- product_group_id/qu_id_consume/qu_id_price is already a valid, meaningful value here - the
-- product simply has no group, or falls back to its qu_id_stock for consumption/pricing - so
-- there is a real repair, not merely a refusal, to make automatically.
--
-- Same bounded-blocking transaction shape as 0288: lock_timeout so a busy table refuses fast
-- rather than queuing indefinitely, statement_timeout as an outer bound, and the affected
-- tables locked SHARE ROW EXCLUSIVE before the scan so nothing can introduce a fresh dangling
-- reference between the repair and the constraint.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE products, product_groups, quantity_units IN SHARE ROW EXCLUSIVE MODE;

DO $$
DECLARE
	group_count bigint;
	consume_count bigint;
	price_count bigint;
	sample text;
BEGIN
	SELECT count(*) INTO group_count
	FROM products p LEFT JOIN product_groups g ON g.id = p.product_group_id
	WHERE p.product_group_id IS NOT NULL AND g.id IS NULL;

	IF group_count > 0 THEN
		SELECT string_agg(format('(%s, %s)', id, product_group_id), ', ' ORDER BY id)
		INTO sample FROM (
			SELECT p.id, p.product_group_id
			FROM products p LEFT JOIN product_groups g ON g.id = p.product_group_id
			WHERE p.product_group_id IS NOT NULL AND g.id IS NULL
			ORDER BY p.id LIMIT 10
		) dangling;
		RAISE NOTICE 'Repairing % product(s) with a dangling product_group_id (set to NULL). Sample (product id, product_group_id): %', group_count, sample;
	END IF;

	UPDATE products p SET product_group_id = NULL
	WHERE p.product_group_id IS NOT NULL
		AND NOT EXISTS (SELECT 1 FROM product_groups g WHERE g.id = p.product_group_id);

	SELECT count(*) INTO consume_count
	FROM products p LEFT JOIN quantity_units u ON u.id = p.qu_id_consume
	WHERE p.qu_id_consume IS NOT NULL AND u.id IS NULL;

	IF consume_count > 0 THEN
		SELECT string_agg(format('(%s, %s)', id, qu_id_consume), ', ' ORDER BY id)
		INTO sample FROM (
			SELECT p.id, p.qu_id_consume
			FROM products p LEFT JOIN quantity_units u ON u.id = p.qu_id_consume
			WHERE p.qu_id_consume IS NOT NULL AND u.id IS NULL
			ORDER BY p.id LIMIT 10
		) dangling;
		RAISE NOTICE 'Repairing % product(s) with a dangling qu_id_consume (set to NULL). Sample (product id, qu_id_consume): %', consume_count, sample;
	END IF;

	UPDATE products p SET qu_id_consume = NULL
	WHERE p.qu_id_consume IS NOT NULL
		AND NOT EXISTS (SELECT 1 FROM quantity_units u WHERE u.id = p.qu_id_consume);

	SELECT count(*) INTO price_count
	FROM products p LEFT JOIN quantity_units u ON u.id = p.qu_id_price
	WHERE p.qu_id_price IS NOT NULL AND u.id IS NULL;

	IF price_count > 0 THEN
		SELECT string_agg(format('(%s, %s)', id, qu_id_price), ', ' ORDER BY id)
		INTO sample FROM (
			SELECT p.id, p.qu_id_price
			FROM products p LEFT JOIN quantity_units u ON u.id = p.qu_id_price
			WHERE p.qu_id_price IS NOT NULL AND u.id IS NULL
			ORDER BY p.id LIMIT 10
		) dangling;
		RAISE NOTICE 'Repairing % product(s) with a dangling qu_id_price (set to NULL). Sample (product id, qu_id_price): %', price_count, sample;
	END IF;

	UPDATE products p SET qu_id_price = NULL
	WHERE p.qu_id_price IS NOT NULL
		AND NOT EXISTS (SELECT 1 FROM quantity_units u WHERE u.id = p.qu_id_price);
END $$;

CREATE INDEX products_product_group_id_idx ON products (product_group_id);
CREATE INDEX products_qu_id_consume_idx ON products (qu_id_consume);
CREATE INDEX products_qu_id_price_idx ON products (qu_id_price);

-- ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE, exactly as 0288's
-- stock_location_id_fkey: no cascade, no automatic repair on a future delete. A future
-- violation reaches GenericEntityApiController::DeleteObject()'s generic 23503 handling
-- (issue #515/PR #551) and answers the documented 400, same as every other enforced foreign
-- key in this tree (see tests/Pgsql/ReferenceRefusalTest.php).
ALTER TABLE products ADD CONSTRAINT products_product_group_id_fkey
	FOREIGN KEY (product_group_id) REFERENCES product_groups (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

ALTER TABLE products ADD CONSTRAINT products_qu_id_consume_fkey
	FOREIGN KEY (qu_id_consume) REFERENCES quantity_units (id)
	ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;

ALTER TABLE products ADD CONSTRAINT products_qu_id_price_fkey
	FOREIGN KEY (qu_id_price) REFERENCES quantity_units (id)
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
