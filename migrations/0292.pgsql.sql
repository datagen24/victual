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
-- confusion fixed, that cascade now also clears (or, per round 2 below, correctly rebuilds)
-- the removed product's own cache rows as a side effect - previously left orphaned, since
-- neither trg_products_DELETE nor this trigger touched them (StockService::MergeProducts()
-- remains the only place that deletes them explicitly, for the same reason, when a product
-- is merged rather than solely deleted).
--
-- Round 2 (same migration number - see migrations/RESERVATIONS.md and
-- docs/plans/22-medication-tracking.md for why this stays 0292 rather than claiming a new
-- number): a validator found that #588's third "Expected" bullet was not met yet. Fixing
-- only DEL's key (OLD.id -> OLD.product_id) is not enough, because trg_stock_log_UPD has a
-- *different* bug from the same family - reachable from the UI, not just from an id
-- coincidence. StockService::UndoBooking() (services/StockService.php:3113,
-- MarkBookingUndone()) undoing a product's only purchase sets stock_log.undone = 1, firing
-- trg_stock_log_UPD. The trigger only ever ran INSERT ... ON CONFLICT DO UPDATE for
-- NEW.product_id - it never removes a cache row - so once products_average_price/
-- products_last_purchased stop returning anything for that product (its only booking is
-- now undone), the stale cache row from before the undo is left behind forever. Every reader
-- that LEFT JOINs these cache tables (db/pgsql/baseline/05_views_l2.sql:298-300 via
-- uihelper_product_details, and 05_views_l3.sql's stock_current-adjacent
-- products_current_price at lines 123-125) keeps showing a price nothing supports any more.
--
-- Both trg_stock_log_UPD and trg_stock_log_DEL are redefined below to share one rebuild
-- (rebuild_stock_log_cache_for_product): recompute what products_average_price/
-- products_last_purchased currently return for one product id, and either replace the cache
-- row those views can ever return for it (both are already GROUP BY/aggregated to at most one
-- row per product_id) or, when neither view returns anything for it any more, leave no cache
-- row at all. DELETE-then-conditional-INSERT rather than an upsert: an upsert has nothing to
-- conflict into when the correct outcome is "no row"; a plain DELETE is idempotent when there
-- was nothing to remove, and the UNIQUE(product_id) constraint on both cache tables (see
-- db/pgsql/baseline/01_tables.sql) guarantees the following INSERT can never collide with
-- anything the DELETE just above it did not already remove.
--
-- trg_stock_log_UPD calls this for NEW.product_id unconditionally - identical in effect to
-- the baseline body for every UPDATE that leaves at least one row for that product (the
-- common case: editing amount/price/date fields of one booking), and additionally correct
-- for the case the baseline body missed (undoing the only booking). It also calls this for
-- OLD.product_id, but only when an UPDATE actually changed which product a ledger row
-- belongs to - the only caller that ever does this is StockService::MergeProducts(), which
-- moves every stock_log row of the removed product onto the kept product's id in one bulk
-- UPDATE before separately deleting the removed product's now-orphaned cache rows itself
-- (services/StockService.php:4092-4093). This trigger rebuilding OLD.product_id too is a
-- harmless, idempotent duplicate of that explicit cleanup - by the time MergeProducts()'s own
-- DELETE runs, the row is already gone (or, if the removed product happened to keep no rows
-- at all post-merge, was never recreated by this rebuild in the first place).
--
-- trg_stock_log_DEL calls this for OLD.product_id instead of unconditionally deleting: a
-- product that still has other bookings after one of them is deleted now gets its cache
-- correctly recomputed from what remains, rather than losing its cached price entirely until
-- some unrelated write happens to touch it again.
--
-- trg_stock_log_INS (same file, lines 49-76) was checked for both the id/product_id
-- confusion and the "never removes a stale row" gap and has neither: it only ever inserts a
-- brand new booking, which the view it reads is therefore guaranteed to return at least one
-- row for (that same insert) - there is no "the view now returns nothing" case reachable from
-- an INSERT the way there is from an UPDATE (undo) or DELETE. It is left as the baseline
-- upsert, unchanged.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per
-- ADR-0008's retirement.

CREATE OR REPLACE FUNCTION rebuild_stock_log_cache_for_product(target_product_id INTEGER) RETURNS VOID AS $$
BEGIN
	-- cache__products_average_price: at most one row per product_id either way (the view is
	-- GROUP BY product_id), so a DELETE followed by a conditional INSERT is exactly "make the
	-- cache agree with the view for this product", whether that means one row or none.
	DELETE FROM cache__products_average_price
	WHERE product_id = target_product_id;

	INSERT INTO cache__products_average_price
		(product_id, price)
	SELECT product_id, price
	FROM products_average_price
	WHERE product_id = target_product_id;

	-- cache__products_last_purchased: same shape, one more view.
	DELETE FROM cache__products_last_purchased
	WHERE product_id = target_product_id;

	INSERT INTO cache__products_last_purchased
		(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
	SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
	FROM products_last_purchased
	WHERE product_id = target_product_id;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS stock_log_UPD ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_UPD() RETURNS TRIGGER AS $$
BEGIN
	PERFORM rebuild_stock_log_cache_for_product(NEW.product_id);

	-- Only StockService::MergeProducts() changes stock_log.product_id via UPDATE; every
	-- other caller updates a booking's other columns in place. Rebuilding the old product's
	-- cache too keeps that case correct without a second, product_id-only trigger.
	IF NEW.product_id IS DISTINCT FROM OLD.product_id THEN
		PERFORM rebuild_stock_log_cache_for_product(OLD.product_id);
	END IF;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_UPD AFTER UPDATE ON stock_log
FOR EACH ROW EXECUTE FUNCTION trg_stock_log_UPD();

DROP TRIGGER IF EXISTS stock_log_DEL ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_DEL() RETURNS TRIGGER AS $$
BEGIN
	PERFORM rebuild_stock_log_cache_for_product(OLD.product_id);

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_DEL AFTER DELETE ON stock_log
FOR EACH ROW EXECUTE FUNCTION trg_stock_log_DEL();
