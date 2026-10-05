-- migrations/0302.pgsql.sql: stock_edited_entries rewritten so that its cost is linear in
-- stock_log. 0267's definition joined two aggregates of a materialised CTE, PostgreSQL
-- estimated one of them at one row, and the nested loop it chose scanned the whole
-- ledger once per origin group. That happened on every stock_log insert, update and
-- delete (the price-cache triggers) and on every product details read.
--
-- Two questions, on one fixture of a few thousand stock_log rows:
--
-- A. Same rows. The new definition is compared, as a multiset, with 0267's text kept here
--    verbatim as a temporary view. The fixture covers every branch of that text: split
--    remainders, edits on the origin and on a remainder, repeated edits, interleaved
--    concurrent edits that only the correlation id can pair, edits with no correlation id,
--    undone edits, undone origin bookings, merged entries with two purchases, and a stock-edit
--    with no stock-edit-old before it.
--
-- B. No quadratic plan. Each query is run under EXPLAIN (ANALYZE, FORMAT JSON), and every
--    plan node's rows produced plus rows it discarded by a filter or join filter are summed
--    and multiplied by the node's loops. That counts the work the 0267 plan did, which a
--    plain sum of Actual Rows does not: its inner node returned 0 rows per loop and
--    discarded the whole tuplestore each time. The bound is a multiple of the stock_log row
--    count, so a linear plan passes at any size and a quadratic one fails at this size.
--    The same measure applied to 0267's text must exceed the bound, or the bound proves
--    nothing about this fixture.
--
--    Measured 2026-10-05 on PostgreSQL 16.15 with 3,507 rows: 7.3 units per row for the
--    view (bound 20), 22 for the average-price refresh (bound 100), 70 for the
--    last-purchased refresh (bound 200; it expands the view nine times), and 1,107 for
--    0267's text on its own. With 0267's text installed as stock_edited_entries the three
--    bounds fail and every other assertion passes.
--
-- The cache triggers are disabled while the fixture loads (each insert would otherwise
-- refresh two caches through the view under test) and nothing is ANALYZEd, which is the
-- state a database is in right after pg_restore or bin/victual-db-import. The whole file
-- runs in one transaction and rolls back, so no later file sees the fixture or the
-- disabled triggers.

BEGIN;

SELECT plan(9);

ALTER TABLE stock_log DISABLE TRIGGER stock_log_ins, DISABLE TRIGGER stock_log_upd, DISABLE TRIGGER stock_log_del;

INSERT INTO locations (name) VALUES ('Spike28 location');
INSERT INTO quantity_units (name) VALUES ('Spike28 qu');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock)
SELECT 'Spike28 product ' || p,
	(SELECT id FROM locations WHERE name = 'Spike28 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike28 qu'),
	(SELECT id FROM quantity_units WHERE name = 'Spike28 qu')
FROM generate_series(1, 20) p;

CREATE TEMP TABLE spike28_products AS
SELECT row_number() OVER (ORDER BY id) - 1 AS n, id FROM products WHERE name LIKE 'Spike28 product %';

-- 1,000 origin entries. Row ids follow the insertion order below because stock_log.id is an
-- identity column, so "nearest preceding" and "newest" mean what they say.
CREATE TEMP TABLE spike28_origins AS
SELECT
	i,
	(SELECT id FROM spike28_products WHERE n = i % 20) AS product_id,
	's28-' || i AS stock_id,
	's28-' || i || '-r' AS remainder_id
FROM generate_series(1, 1000) i;

-- Purchases. Every 17th is undone, so its group has edits but no origin booking and must not
-- appear. Every 13th entry was merged from two purchases, so its origin amount is a sum.
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id)
SELECT product_id, 1 + i % 5, DATE '2026-06-01' + i % 300, DATE '2025-01-01' + i % 365, stock_id,
	CASE WHEN i % 29 = 0 THEN 'inventory-correction' WHEN i % 31 = 0 THEN 'self-production' ELSE 'purchase' END,
	1.0 + (i % 7) * 0.5, CASE WHEN i % 17 = 0 THEN 1 ELSE 0 END, 9000
FROM spike28_origins;

INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id)
SELECT product_id, 2, DATE '2026-06-01' + i % 300, DATE '2025-01-01' + i % 365, stock_id, 'purchase', 2.25, 0, 9000
FROM spike28_origins
WHERE i % 13 = 0;

-- Every 4th entry is split by a partial open; the remainder has no booking of its own.
INSERT INTO stock_entry_origins (stock_id, origin_stock_id)
SELECT remainder_id, stock_id FROM spike28_origins WHERE i % 4 = 0;

INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, undone, user_id, opened_date)
SELECT product_id, 1, stock_id, 'product-opened', 0, 9000, DATE '2025-06-01'
FROM spike28_origins
WHERE i % 4 = 0;

-- One consume per entry, so the ledger is not all purchases.
INSERT INTO stock_log (product_id, amount, used_date, stock_id, transaction_type, undone, user_id)
SELECT product_id, -1, DATE '2025-07-01', stock_id, 'consume', 0, 9000
FROM spike28_origins;

-- Edits. Every 3rd entry is edited once with a correlated pair, on the remainder when there is
-- one and the entry is even, otherwise on the origin. Every 11th of those edits is undone.
INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, undone, user_id, correlation_id, price)
SELECT product_id, 3, CASE WHEN i % 8 = 0 THEN remainder_id ELSE stock_id END, t.transaction_type, 0, 9000, 's28-c' || i, 1.5
FROM spike28_origins
CROSS JOIN (VALUES (1, 'stock-edit-old')) t(ord, transaction_type)
WHERE i % 3 = 0
ORDER BY i;

INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, undone, user_id, correlation_id, price)
SELECT product_id, 2 + i % 3, CASE WHEN i % 8 = 0 THEN remainder_id ELSE stock_id END, 'stock-edit-new', CASE WHEN i % 11 = 0 THEN 1 ELSE 0 END, 9000, 's28-c' || i, 1.75
FROM spike28_origins
WHERE i % 3 = 0
ORDER BY i;

-- Every 7th entry is edited a second time, so the newest edit and a summed correction matter.
-- Every 14th does it without a correlation id, so the pairing falls back to the nearest
-- preceding stock-edit-old; for half of those the old row also has none, which NULL-safe
-- matching must still pair.
INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, undone, user_id, correlation_id)
SELECT product_id, 4, stock_id, 'stock-edit-old', 0, 9000, CASE WHEN i % 28 = 0 THEN NULL ELSE 's28-d' || i END
FROM spike28_origins
WHERE i % 7 = 0
ORDER BY i;

INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, undone, user_id, correlation_id)
SELECT product_id, 5, stock_id, 'stock-edit-new', 0, 9000, CASE WHEN i % 14 = 0 THEN NULL ELSE 's28-d' || i END
FROM spike28_origins
WHERE i % 7 = 0
ORDER BY i;

-- Every 19th entry has two concurrent edits whose ids interleave: old(e), old(f), new(e),
-- new(f). Nearest-preceding alone would pair new(e) with old(f); the correlation id must win.
INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, undone, user_id, correlation_id)
SELECT o.product_id, v.amount, o.stock_id, v.transaction_type, 0, 9000, 's28-' || v.corr || o.i
FROM spike28_origins o
CROSS JOIN (VALUES
	(1, 'stock-edit-old', 'e', 10),
	(2, 'stock-edit-old', 'f', 20),
	(3, 'stock-edit-new', 'e', 11),
	(4, 'stock-edit-new', 'f', 23)
) v(ord, transaction_type, corr, amount)
WHERE o.i % 19 = 0
ORDER BY o.i, v.ord;

-- Every 23rd entry has a stock-edit-new with no stock-edit-old before it, so its correction is
-- zero by the COALESCE.
INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, undone, user_id, correlation_id)
SELECT product_id, 6, stock_id, 'stock-edit-new', 0, 9000, 's28-g' || i
FROM spike28_origins
WHERE i % 23 = 0 AND i % 3 <> 0 AND i % 7 <> 0 AND i % 19 <> 0;

-- 0267's definition, verbatim, as the reference.
CREATE TEMP VIEW stock_edited_entries_0267 AS
WITH resolved AS (
	SELECT
		sl.id,
		sl.stock_id,
		sl.amount,
		sl.transaction_type,
		sl.correlation_id,
		sl.undone,
		COALESCE(seo.origin_stock_id, sl.stock_id) AS origin_stock_id
	FROM stock_log sl
	LEFT JOIN stock_entry_origins seo
		ON seo.stock_id = sl.stock_id
),
origins AS (
	SELECT
		r.origin_stock_id,
		SUM(r.amount) AS origin_amount
	FROM resolved r
	WHERE r.undone = 0
		AND r.transaction_type IN ('purchase', 'inventory-correction', 'self-production')
		AND r.amount > 0
	GROUP BY r.origin_stock_id
),
corrections AS (
	SELECT
		sl_new.origin_stock_id,
		sl_new.id,
		sl_new.amount - COALESCE(sl_old.amount, sl_new.amount) AS correction
	FROM resolved sl_new
	LEFT JOIN LATERAL (
		SELECT sl_prev.amount
		FROM stock_log sl_prev
		WHERE sl_prev.transaction_type = 'stock-edit-old'
			AND sl_prev.stock_id = sl_new.stock_id
			AND sl_prev.id < sl_new.id
		ORDER BY (sl_prev.correlation_id IS NOT DISTINCT FROM sl_new.correlation_id) DESC, sl_prev.id DESC
		LIMIT 1
	) sl_old ON TRUE
	WHERE sl_new.transaction_type = 'stock-edit-new'
		AND sl_new.undone = 0
),
corrected_origins AS (
	SELECT
		o.origin_stock_id,
		MAX(c.id) AS stock_log_id_of_newest_edited_entry,
		o.origin_amount + SUM(c.correction) AS edited_origin_amount
	FROM origins o
	JOIN corrections c
		ON c.origin_stock_id = o.origin_stock_id
	GROUP BY o.origin_stock_id, o.origin_amount
)
SELECT DISTINCT
	r.stock_id,
	co.stock_log_id_of_newest_edited_entry,
	co.edited_origin_amount
FROM corrected_origins co
JOIN resolved r
	ON r.origin_stock_id = co.origin_stock_id;

-- Rows produced plus rows discarded, times loops, over every node of the executed plan.
CREATE FUNCTION pg_temp.spike28_plan_work(query TEXT) RETURNS NUMERIC AS $$
DECLARE
	plan JSONB;
BEGIN
	EXECUTE 'EXPLAIN (ANALYZE, FORMAT JSON) ' || query INTO plan;

	RETURN (
		SELECT SUM(
			(COALESCE((node->>'Actual Rows')::NUMERIC, 0)
				+ COALESCE((node->>'Rows Removed by Filter')::NUMERIC, 0)
				+ COALESCE((node->>'Rows Removed by Join Filter')::NUMERIC, 0))
			* COALESCE((node->>'Actual Loops')::NUMERIC, 1))
		FROM jsonb_path_query(plan, 'strict $.**') node
		WHERE jsonb_typeof(node) = 'object'
			AND node ? 'Node Type'
	);
END;
$$ LANGUAGE plpgsql;

CREATE TEMP TABLE spike28_size AS SELECT COUNT(*)::NUMERIC AS n FROM stock_log;

-- A: the fixture reaches the branches it claims to, then the two definitions agree.
SELECT ok(
	(SELECT n FROM spike28_size) BETWEEN 2500 AND 5000,
	'The fixture holds a few thousand stock_log rows'
);

SELECT ok(
	(SELECT COUNT(*) FROM stock_edited_entries_0267 see JOIN spike28_origins o ON see.stock_id = o.remainder_id) > 0
	AND (SELECT COUNT(*) FROM stock_edited_entries_0267 see JOIN spike28_origins o ON see.stock_id = o.stock_id AND o.i % 19 = 0) > 0
	AND (SELECT COUNT(*) FROM stock_edited_entries_0267 see JOIN spike28_origins o ON see.stock_id = o.stock_id AND o.i % 13 = 0) > 0,
	'0267''s definition returns split remainders, interleaved edits and merged entries on this fixture'
);

SELECT bag_eq(
	'SELECT stock_id, stock_log_id_of_newest_edited_entry, edited_origin_amount FROM stock_edited_entries',
	'SELECT stock_id, stock_log_id_of_newest_edited_entry, edited_origin_amount FROM stock_edited_entries_0267',
	'stock_edited_entries returns exactly the rows 0267''s definition returns'
);

SELECT is(
	(SELECT edited_origin_amount FROM stock_edited_entries WHERE stock_id = 's28-38'),
	-- i = 38: purchase of 1 + 38 % 5 = 4 units, interleaved edits +1 (10 -> 11) and +3 (20 -> 23).
	8::DOUBLE PRECISION,
	'Interleaved concurrent edits are paired by correlation id, not by adjacency'
);

SELECT is(
	(SELECT COUNT(*)::INTEGER FROM stock_edited_entries WHERE stock_id IN ('s28-51', 's28-51-r')),
	-- i = 51: its only purchase is undone (51 % 17 = 0), so its edit has nothing to correct.
	0,
	'A group whose origin booking was undone is not returned'
);

-- B: no quadratic plan for the view or for the two queries the cache triggers run.
SELECT cmp_ok(
	pg_temp.spike28_plan_work('SELECT * FROM stock_edited_entries'),
	'<',
	20 * (SELECT n FROM spike28_size),
	'stock_edited_entries does work linear in stock_log'
);

SELECT cmp_ok(
	pg_temp.spike28_plan_work('SELECT product_id, price FROM products_average_price WHERE product_id = ' || (SELECT id FROM spike28_products WHERE n = 0)),
	'<',
	100 * (SELECT n FROM spike28_size),
	'The average-price cache refresh for one product does work linear in stock_log'
);

SELECT cmp_ok(
	pg_temp.spike28_plan_work('SELECT * FROM products_last_purchased WHERE product_id = ' || (SELECT id FROM spike28_products WHERE n = 0)),
	'<',
	200 * (SELECT n FROM spike28_size),
	'The last-purchased cache refresh for one product does work linear in stock_log'
);

SELECT cmp_ok(
	pg_temp.spike28_plan_work('SELECT * FROM stock_edited_entries_0267'),
	'>',
	20 * (SELECT n FROM spike28_size),
	'Negative control: the same measure exceeds the bound for 0267''s definition on this fixture'
);

SELECT * FROM finish();

ROLLBACK;
