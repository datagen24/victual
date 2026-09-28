-- Issue #588 (#487 remediation): trg_stock_log_DEL clears the price caches by the deleted
-- stock_log ROW's own id (OLD.id), not the product_id that row belonged to
-- (db/pgsql/baseline/06_triggers_b.sql:145-159, ported faithfully from upstream's
-- migrations/0226.sql:66-75 with a reviewer note that this "looks like a bug"). Both id
-- sequences start at 1, so whenever a deleted ledger row's own id happens to coincide with
-- some OTHER product's id, that unrelated product's cache__products_average_price and
-- cache__products_last_purchased rows are wiped out instead of the one the deletion actually
-- concerns. The porting rule (reproduce upstream exactly, bug included) no longer applies now
-- that the fork fixes defects instead of carrying them forward.
--
-- Ledger rows are deleted in bulk when a product itself is deleted
-- (trg_cascade_product_removal, db/pgsql/baseline/06_triggers_a.sql, runs
-- `DELETE FROM stock_log WHERE product_id = OLD.id` for the removed product). With the id
-- confusion fixed, that cascade now also clears the removed product's own cache rows as a
-- side effect - previously left orphaned, since neither trg_products_DELETE nor this trigger
-- touched them (StockService::MergeProducts() remains the only place that deletes them
-- explicitly, for the same reason, when a product is merged rather than solely deleted).
--
-- trg_stock_log_INS and trg_stock_log_UPD (same file, lines 49-126) were checked for the same
-- id/product_id confusion and do not have it: both already filter and select by NEW.product_id
-- throughout. Only the DELETE trigger is changed here.
DROP TRIGGER IF EXISTS stock_log_DEL ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_DEL() RETURNS TRIGGER AS $$
BEGIN
	-- Update products_average_price cache
	DELETE FROM cache__products_average_price
	WHERE product_id = OLD.product_id;

	-- Update products_last_purchased cache
	DELETE FROM cache__products_last_purchased
	WHERE product_id = OLD.product_id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_DEL AFTER DELETE ON stock_log
FOR EACH ROW EXECUTE FUNCTION trg_stock_log_DEL();
