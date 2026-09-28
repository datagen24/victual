-- ADR-0033 ("Stock rows are merged only by a maintenance routine, and only when they never
-- expire"), decision 3. Narrows stock_splits - the candidate-group view CompactStockEntries()
-- reads - so that a row carrying a real due date, or a live stock_entry label, can never be
-- named as a merge candidate, by any caller, including the explicit maintenance command this
-- ADR moves compaction into. The three exclusions migrations/0275.pgsql.sql already enforced
-- (stock_id prefixed "x", a userfield value, a measured remainder) are unchanged; two are added:
--
-- 1. best_before_date IS NULL OR best_before_date = '2999-12-31' - the "never expires"
--    sentinel StockService::AddProduct() and TransferProduct() already write for a freezer
--    transfer with no configured thaw date. A row with any other, real due date is excluded
--    from the view outright, so it can never appear in a candidate group - not "grouped
--    apart", not merely "not automatically merged", but never a candidate at all, at any
--    caller. best_before_date is already one of the GROUP BY columns below, so every row a
--    surviving group contains shares this same never-expiring value; the WHERE clause only
--    decides which rows are visible to be grouped in the first place.
-- 2. NOT EXISTS(... labels ... kind = 'stock_entry' ... retired_at IS NULL) - issue #491 (H2):
--    a live label (migrations/0269.pgsql.sql's labels table, generalised to stock entries by
--    migrations/0283.pgsql.php) names one physical row by its id. CompactStockEntries()
--    deletes every row but the one it keeps, and the retire_stock_entry_labels trigger
--    (migrations/0283.pgsql.php) retires a label whose row is deleted out from under it - so a
--    labelled row must never be a merge candidate, not even when every other grouping column
--    matches. Excluding it from this view is what makes that true regardless of which caller
--    runs the merge; the label check has to be a live subquery here (not a cached column) since
--    a label can be issued or retired between one call and the next.
--
-- ADR-0033's own decision 3 keeps a second layer beyond this view: within the product lock,
-- StockService::CompactStockEntries() takes SELECT ... FOR UPDATE on the candidate rows in
-- ascending id order, then re-reads this same view before writing, so a label issued while
-- compaction waited for its locks is seen before anything is deleted. This view is what makes
-- that re-read see the label at all; the row lock is what makes the label commit-or-lose the
-- race honestly (LabelIdentityService::Issue() itself locks the row before inserting the label,
-- so it blocks on - and then loses to - the row already having been deleted).
--
-- Every other column, join and grouping key is byte-identical to the CREATE OR REPLACE VIEW
-- stock_splits in migrations/0275.pgsql.sql (itself byte-identical to
-- db/pgsql/baseline/03_views_group3.sql on this view).
CREATE OR REPLACE VIEW stock_splits AS

/*
	Helper view which shows splitted stock rows which could be compacted

	Stock entries with a stock_id starting with "x", those with userfields, those carrying a
	measured remainder (opened_amount IS NOT NULL), those with a real (non-sentinel) due date,
	and those carrying a live stock_entry label shouldn't be compacted
*/

SELECT
	s.product_id,
	SUM(s.amount) AS total_amount,
	MIN(s.stock_id) AS stock_id_to_keep,
	MAX(s.id) AS id_to_keep,
	string_agg(s.id::text, ',') AS id_group,
	string_agg(s.stock_id::text, ',') AS stock_id_group,
	MIN(s.id) AS id -- Dummy
FROM stock s
WHERE s.stock_id NOT LIKE 'x%'
	AND s.opened_amount IS NULL
	AND (s.best_before_date IS NULL OR s.best_before_date = '2999-12-31')
	AND NOT EXISTS(
		SELECT 1 FROM userfield_values
		WHERE object_id = s.stock_id
			AND field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
			AND COALESCE(value, '') != ''
		)
	AND NOT EXISTS(
		SELECT 1 FROM labels
		WHERE kind = 'stock_entry'
			AND target_id = s.id
			AND retired_at IS NULL
		)
GROUP BY s.product_id, s.best_before_date, s.purchased_date, s.price, s.open, s.opened_date, s.location_id, s.shopping_location_id, COALESCE(s.note, '')
HAVING COUNT(*) > 1;
