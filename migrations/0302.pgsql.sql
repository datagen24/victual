-- stock_edited_entries without a join between two aggregated CTEs, so its cost is linear in
-- stock_log instead of quadratic.
--
-- WHAT WAS SLOW. migrations/0267.pgsql.sql defines the view as a CTE `resolved` (every
-- stock_log row with its origin stock_id) referenced four times, two aggregates over it
-- (`origins`, `corrections`), and a join of the two on origin_stock_id. PostgreSQL
-- materialises `resolved` and has no statistics for a CTE's columns, so it estimates the
-- `origins` aggregate at one row and joins it to a filtered scan of `resolved` with a
-- nested loop: one full scan of the tuplestore per origin group. With 20,000 stock_log
-- rows that is 20,000 scans of 20,000 rows for every evaluation of the view.
--
-- The view has no product_id, so a `WHERE product_id = X` on a consumer never reaches it:
-- every reader pays for the whole ledger however few rows its own product has. Its readers
-- are products_average_price, products_last_purchased (directly and through
-- products_price_history) and stock_average_product_shelf_life. That puts it on three hot
-- paths:
--   - trg_stock_log_INS (db/pgsql/baseline/06_triggers_b.sql) refreshes both price caches
--     from the first two views after every stock_log insert, which is every booking;
--   - trg_stock_log_UPD and trg_stock_log_DEL do the same through
--     rebuild_stock_log_cache_for_product() (migrations/0292.pgsql.sql), so every undo,
--     edit and booking delete;
--   - uihelper_product_details reads stock_average_product_shelf_life, so every product
--     details read, which StockService also does inside most bookings.
-- reconcile_stock_log_cache() runs the rebuild once per product with stock_log rows, so
-- its cost is the per-product cost times the number of products.
--
-- Measured 2026-10-05 on PostgreSQL 16.15, ledgers grown by
-- .devtools/pgsql/ledger-generator.php (80 products, StockService bookings) on master at
-- 46a35e34: rebuilding one product's caches took 1.05 s at 2,907 stock_log rows under the
-- old definition, with fresh statistics, and the same for a product with 7 rows as for one
-- with 108; 40 s at 19,416 rows. The pull request that adds this migration records the
-- full curve.
--
-- WHAT CHANGED. One pass over stock_log, partitioned by origin, and no join between derived
-- relations:
--   - `bookings` is stock_log with each live "stock-edit-new" row's correction attached.
--     The correction's LATERAL lookup of the matching "stock-edit-old" row runs only in
--     the UNION ALL branch that holds those rows, so the planner costs it for the edit rows
--     alone. Attached to every row, the lookup was skipped at run time by a one-time
--     filter but still costed per row, which inflated every consumer's estimate.
--   - `keyed` computes, with window aggregates over each origin group, the group's origin
--     amount, newest edit and summed correction, and carries them on every row.
--   - The output keeps the rows of groups that have both, one per stock_id.
-- The only joins are stock_log to stock_entry_origins on a column with real statistics
-- and the LATERAL index lookup on ix_stock_log_performance1 (stock_id, transaction_type,
-- amount). No join depends on an estimate of a CTE or an aggregate, so a database with no
-- statistics yet (after pg_restore, bin/victual-db-import or a bulk load) gets the same
-- plan shape as an analysed one.
--
-- Window aggregates rather than GROUP BY and an array of each group's stock_ids, which
-- performs the same: PostgreSQL estimates ten elements for an UNNEST, so that form was
-- estimated at 115,510 rows on a ledger where it returns 779, and the consumers' plans
-- followed the overestimate. Two consequences were measured on 11,667 rows. The
-- last-purchased refresh crossed jit_above_cost and paid about 100 ms of JIT compilation
-- per call. And the generic plan plpgsql caches for rebuild_stock_log_cache_for_product()
-- after five calls evaluated the view eagerly even for a product with no rows left, so
-- deleting a product, which fires the delete trigger once per booking, took 4 s for 43
-- bookings. This form is estimated at 1,167 rows and its generic plan stays lazy.
--
-- The partition key uses COLLATE "C". The database's default collation is deterministic,
-- so "C" forms exactly the same groups; it only skips locale-aware comparison, which made
-- the sort about 3.5 times slower. The output columns keep their collation, which CREATE OR
-- REPLACE VIEW requires.
--
-- JIT OFF FOR THE TWO TRIGGER-SIDE FUNCTIONS. Linear is still nine passes over the ledger
-- for products_last_purchased, which reads this view six times directly and three times
-- through products_price_history, and its estimated cost passes jit_above_cost at roughly
-- 17,000 stock_log rows. From there every booking would compile the query before running
-- it once: at 19,416 rows the refresh took 279 ms with JIT and 137 ms without, 126 ms of it
-- compilation. trg_stock_log_INS() and rebuild_stock_log_cache_for_product() run these
-- queries once per stock_log row, so JIT never pays for itself there; both are set to run
-- with jit off. Nothing else changes, and their bodies are untouched. CREATE OR REPLACE
-- FUNCTION replaces a function's SET clauses, so a later redefinition of either one drops
-- this setting unless it repeats it; .devtools/pgtap/028 asserts it.
--
-- SAME RESULTS. The rows are the same as 0267's: one per stock_id in a group that has at
-- least one origin booking (undone = 0, purchase / inventory-correction / self-production,
-- amount > 0) and at least one live "stock-edit-new" row. Each row carries the group's
-- newest edit and its origin amount plus the summed corrections. .devtools/pgtap/028
-- compares this definition with 0267's text row for row.
--
-- One qualification applies to products_average_price, which divides two SUMs of double
-- precision products. A different plan feeds those SUMs rows in a different order, and the
-- last bit of the quotient can change (measured: at most 5e-16 relative). 0267's view
-- already behaved this way: disabling merge joins and explicit sorts changes 38 of 80 of its
-- averages on the same ledger. Every other column of every consumer is identical.
--
-- The price caches are not rebuilt here. Their contents do not depend on which definition
-- produced them beyond that last bit, and rebuilding them would cost an upgrade one
-- reconcile pass for nothing.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per
-- ADR-0008's retirement.

CREATE OR REPLACE VIEW stock_edited_entries AS
/*
	Returns stock_id's whose origin booking has been corrected by a manual edit.

	The edit need not be on the stock_id that carries the origin booking: an entry split
	off by a partial open has a stock_id of its own and no booking of its own, and
	stock_entry_origins is what ties it back. One row per stock_id in the group, all
	carrying the group's answer, because that is the shape the three consumers want -- they
	exclude origin rows by "stock_id IN this view", pick the surviving booking by
	"id IN stock_log_id_of_newest_edited_entry", and read the amount through a join on
	stock_id.
*/
WITH bookings AS (
	SELECT
		sl.id,
		sl.stock_id,
		sl.amount,
		sl.transaction_type,
		sl.undone,
		NULL::DOUBLE PRECISION AS correction
	FROM stock_log sl
	WHERE NOT (sl.transaction_type = 'stock-edit-new' AND sl.undone = 0)
	UNION ALL
	-- What each live edit removed from its entry, as a signed amount: negative when the
	-- edit reduced the entry, positive when it raised it.
	SELECT
		sl.id,
		sl.stock_id,
		sl.amount,
		sl.transaction_type,
		sl.undone,
		sl.amount - COALESCE(sl_old.amount, sl.amount)
	FROM stock_log sl
	LEFT JOIN LATERAL (
		-- The correlated partner where there is one; the nearest preceding
		-- "stock-edit-old" on the same entry otherwise. EditStockEntry() writes the pair
		-- adjacently inside one transaction, so the two agree except where concurrent
		-- edits of one entry interleaved their ids -- which is where the correlation id
		-- is the only thing that can tell them apart.
		SELECT sl_prev.amount
		FROM stock_log sl_prev
		WHERE sl_prev.transaction_type = 'stock-edit-old'
			AND sl_prev.stock_id = sl.stock_id
			AND sl_prev.id < sl.id
		ORDER BY (sl_prev.correlation_id IS NOT DISTINCT FROM sl.correlation_id) DESC, sl_prev.id DESC
		LIMIT 1
	) sl_old ON TRUE
	WHERE sl.transaction_type = 'stock-edit-new'
		AND sl.undone = 0
),
keyed AS (
	SELECT
		b.stock_id,
		-- What the group started with. SUM rather than a single row: CompactStockEntries()
		-- merges entries that are equal in every attribute, rewriting their stock_ids to
		-- one, so a stock_id can carry more than one purchase.
		SUM(b.amount) FILTER (
			WHERE b.undone = 0
				AND b.transaction_type IN ('purchase', 'inventory-correction', 'self-production')
				AND b.amount > 0
		) OVER origin_group AS origin_amount,
		MAX(b.id) FILTER (WHERE b.transaction_type = 'stock-edit-new' AND b.undone = 0) OVER origin_group AS stock_log_id_of_newest_edited_entry,
		SUM(b.correction) OVER origin_group AS correction
	FROM bookings b
	LEFT JOIN stock_entry_origins seo
		ON seo.stock_id = b.stock_id
	WINDOW origin_group AS (PARTITION BY COALESCE(seo.origin_stock_id, b.stock_id) COLLATE "C")
)
SELECT DISTINCT
	k.stock_id,
	k.stock_log_id_of_newest_edited_entry,
	k.origin_amount + k.correction AS edited_origin_amount
FROM keyed k
WHERE k.origin_amount IS NOT NULL
	AND k.stock_log_id_of_newest_edited_entry IS NOT NULL;

ALTER FUNCTION trg_stock_log_INS() SET jit = off;
ALTER FUNCTION rebuild_stock_log_cache_for_product(INTEGER) SET jit = off;
