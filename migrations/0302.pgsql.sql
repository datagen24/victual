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
-- 46a35e34: rebuilding one product's caches took 1.07 s at 2,905 stock_log rows under the
-- old definition, with fresh statistics, and the same for a product with 7 rows as for one
-- with 106. The pull request that adds this migration records the full curve.
--
-- WHAT CHANGED. One pass over stock_log, grouped by origin, and no join between derived
-- relations:
--   - `bookings` is stock_log with each live "stock-edit-new" row's correction attached.
--     The correction's LATERAL lookup of the matching "stock-edit-old" row runs only in
--     the UNION ALL branch that holds those rows, so the planner costs it for the edit rows
--     alone. Attached to every row, the lookup was skipped at run time by a one-time
--     filter but still costed per row, and that estimate pushed the consumers above
--     jit_above_cost: about 95 ms of JIT compilation per call at 2,905 rows.
--   - `groups` aggregates `bookings` by origin with FILTER clauses: the origin amount, the
--     newest edit, the summed correction, and the group's stock_ids as an array.
--   - The output unnests each corrected group's stock_ids.
-- The only joins are stock_log to stock_entry_origins on a column with real statistics
-- and the LATERAL index lookup on ix_stock_log_performance1 (stock_id, transaction_type,
-- amount). No join depends on an estimate of a CTE or an aggregate, so a database with no
-- statistics yet (after pg_restore, bin/victual-db-import or a bulk load) gets the same
-- plan shape as an analysed one.
--
-- Grouping and the array use COLLATE "C". The database's default collation is
-- deterministic, so "C" forms exactly the same groups and distinct values; it only skips
-- locale-aware comparison, which made the sort about 3.5 times slower. The output column
-- is cast back to the default collation so the view's column definitions are unchanged,
-- which CREATE OR REPLACE VIEW requires.
--
-- SAME RESULTS. The rows are the same as 0267's: one per stock_id in a group that has at
-- least one origin booking (undone = 0, purchase / inventory-correction / self-production,
-- amount > 0) and at least one live "stock-edit-new" row. Each row carries the group's
-- newest edit and its origin amount plus the summed corrections. stock_id is NOT NULL in
-- stock_log, so no group has a NULL key, and a stock_id belongs to exactly one group, so
-- the unnested rows are distinct without a DISTINCT. .devtools/pgtap/028 compares this
-- definition with 0267's text row for row.
--
-- One qualification applies to products_average_price, which divides two SUMs of double
-- precision products. A different plan feeds those SUMs rows in a different order, and the
-- last bit of the quotient can change (measured: at most 5e-16 relative). 0267's view
-- already behaved this way: switching off merge joins alone changes 38 of 80 averages on the
-- same ledger. Every other column of every consumer is identical.
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
groups AS (
	SELECT
		-- What the group started with. SUM rather than a single row: CompactStockEntries()
		-- merges entries that are equal in every attribute, rewriting their stock_ids to
		-- one, so a stock_id can carry more than one purchase.
		SUM(b.amount) FILTER (
			WHERE b.undone = 0
				AND b.transaction_type IN ('purchase', 'inventory-correction', 'self-production')
				AND b.amount > 0
		) AS origin_amount,
		MAX(b.id) FILTER (WHERE b.transaction_type = 'stock-edit-new' AND b.undone = 0) AS stock_log_id_of_newest_edited_entry,
		SUM(b.correction) AS correction,
		ARRAY_AGG(DISTINCT b.stock_id COLLATE "C") AS stock_ids
	FROM bookings b
	LEFT JOIN stock_entry_origins seo
		ON seo.stock_id = b.stock_id
	GROUP BY COALESCE(seo.origin_stock_id, b.stock_id) COLLATE "C"
)
SELECT
	s.stock_id COLLATE "default" AS stock_id,
	g.stock_log_id_of_newest_edited_entry,
	g.origin_amount + g.correction AS edited_origin_amount
FROM groups g
CROSS JOIN LATERAL UNNEST(g.stock_ids) AS s(stock_id)
WHERE g.origin_amount IS NOT NULL
	AND g.stock_log_id_of_newest_edited_entry IS NOT NULL;
