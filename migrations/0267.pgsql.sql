-- An edit of a stock entry moves products_average_price only when that entry happens to
-- carry an origin booking of its own. An entry produced by a partial open carries none,
-- so the same correction is silently ignored. This migration records where a split entry
-- came from and teaches stock_edited_entries to follow it.
--
-- THE MECHANISM. stock_edited_entries (migrations/0230.sql, unchanged since) pairs each
-- "stock-edit-new" row with an origin row -- purchase, positive inventory-correction or
-- self-production -- carrying THE SAME stock_id:
--
--     FROM stock_log sl_add
--     JOIN stock_log sl_edit
--       ON sl_add.stock_id = sl_edit.stock_id
--       AND sl_edit.transaction_type = 'stock-edit-new'
--
-- products_average_price then has exactly two branches: origin rows whose stock_id is NOT
-- in that view, and the newest "stock-edit-new" for stock_ids that ARE. An entry with no
-- origin row of its own falls into neither, so its edit contributes nothing and nothing is
-- excluded on its behalf.
--
-- Entries with no origin row are ordinary. StockService::OpenProduct() splits an entry
-- when the amount being opened is smaller than the entry: the remainder becomes a new
-- `stock` row with a fresh stock_id and no stock_log row at all, while the "product-opened"
-- booking keeps the original stock_id. Nothing in the ledger then ties the remainder to the
-- purchase it came out of.
--
-- Concretely: buy 500 at 1.74, open 100 (splitting off 400), correct that 400 to 350, and
-- the average still weights the product at the full 500. Do the same correction on an entry
-- that was never split and the weight becomes 450.
--
-- THE DECISION. A correction to an entry is reflected in the average. That is not a new
-- policy -- it is the only thing stock_edited_entries has ever been for, and its own header
-- says so ("Returns stock_id's which have been edited manually"). The behaviour being
-- replaced is not the defensible alternative ("the average reflects what was purchased,
-- not later corrections") either: it reflects some corrections and not others, and which
-- ones depends on whether an unrelated open happened to split the row. Nobody chose that.
--
-- THE LINK. stock_entry_origins maps a split entry's stock_id to the stock_id that carries
-- its origin booking. The origin is stored rather than the immediate parent -- a remainder
-- can be split again, and the chain is real (a simulated year produced four generations on
-- one product) -- so the view stays a join instead of becoming a recursion, and no rewrite
-- of stock_ids can turn it into a cycle. OpenProduct() writes the row; CompactStockEntries()
-- maintains it when it rewrites stock_ids to merge entries back together.
--
-- Not a stock_log column, because GET /api/stock/bookings/{id} and
-- GET /api/stock/transactions/{id} return those rows whole and ADR-0005 makes the JSON on
-- the wire the invariant. Not a `stock` column, because `stock` rows are deleted when an
-- entry is consumed away while its bookings stay in the ledger the views read, so the link
-- would vanish while the rows that need it remain. A table of its own is on no endpoint:
-- GenericEntityApiController allows only the entities named in the OpenAPI ExposedEntity
-- enum, and this is not one.
--
-- NOT BACKFILLED, because the information does not exist. A remainder created before this
-- migration has no recorded parent and no way to derive one -- its stock_id first appears
-- in the ledger as a consume or a product-opened, and several purchases of one product can
-- be equally plausible ancestors. Those entries keep behaving as they do today; splits made
-- from here on are corrected.
--
-- THE OTHER CHANGE, stated rather than smuggled. The view no longer reconstructs the origin
-- amount as "the newest edit's amount plus everything consumed from that stock_id before
-- it". It accumulates instead: the origin booking's amount, less what each edit removed
-- (its "stock-edit-old" amount minus its "stock-edit-new" amount). The two agree wherever
-- the reconstruction's assumption holds -- one entry, one origin, consumes that stay
-- consumed -- which is every case the differential fixtures contain and every case that
-- does not involve a split. They differ in two places:
--
--   1. Splits, which is the defect above. The reconstruction cannot express them at all,
--      because the units sitting in the other half of a split entry were never consumed and
--      never edited, so no amount of arithmetic over one stock_id can find them.
--   2. A consume that is undone after an edit. UndoBooking() clears the consume's `undone`
--      flag and puts the amount back as a separate `stock` row, but the reconstruction
--      reads `undone` at query time and so drops a consume that was real when the edit was
--      made: purchase 500, consume 50, edit 450 to 400, undo the consume, and it answers
--      400 for an origin that is 500 less the 50 the edit removed. The accumulation answers
--      450. This is the same defect seen from the other side -- reconstructing a total from
--      a running sum rather than accumulating what actually changed it -- and fixing one
--      without the other would mean keeping the arithmetic that produces it.
--
-- An edit whose "stock-edit-old" partner cannot be found contributes no correction rather
-- than an invented one. EditStockEntry() has always written the pair inside one
-- transaction, so the partner is found by correlation id where there is one and by the
-- nearest preceding "stock-edit-old" on the same stock_id where there is not -- which is
-- what an imported database predating the correlation id column has.

CREATE TABLE stock_entry_origins (
	id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	-- The split-off entry. Unique: an entry is split from exactly one other entry, and the
	-- view relies on that to map a stock_id to a single origin.
	stock_id TEXT NOT NULL UNIQUE,
	-- The stock_id whose stock_log rows carry the purchase, inventory correction or
	-- self-production this entry's units arrived by. Never equal to stock_id.
	origin_stock_id TEXT NOT NULL,
	row_created_timestamp TIMESTAMP DEFAULT date_trunc('second', LOCALTIMESTAMP),
	CHECK (stock_id <> origin_stock_id)
);

-- The view joins on both columns: stock_id to resolve a booking's group, origin_stock_id to
-- collect a group's members.
CREATE INDEX ix_stock_entry_origins_origin ON stock_entry_origins (origin_stock_id);

-- CREATE OR REPLACE rather than DROP, deliberately. products_average_price,
-- products_price_history and products_last_purchased all select from this view, and
-- PostgreSQL refuses to drop a view another view depends on -- 0261.pgsql.sql had to drop
-- and rebuild two of them for exactly that reason. Replacing in place is allowed while the
-- output columns keep their names, types and order, which these do: text, integer, double
-- precision. So the three dependents are untouched and inherit the fix.
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
WITH resolved AS (
	SELECT
		sl.id,
		sl.stock_id,
		sl.amount,
		sl.transaction_type,
		sl.correlation_id,
		COALESCE(seo.origin_stock_id, sl.stock_id) AS origin_stock_id
	FROM stock_log sl
	LEFT JOIN stock_entry_origins seo
		ON seo.stock_id = sl.stock_id
),
origins AS (
	-- What the group started with. SUM rather than the single row upstream assumed:
	-- CompactStockEntries() merges entries that are equal in every attribute, rewriting
	-- their stock_ids to one, so a stock_id can carry more than one purchase.
	SELECT
		r.origin_stock_id,
		SUM(r.amount) AS origin_amount
	FROM resolved r
	WHERE r.transaction_type IN ('purchase', 'inventory-correction', 'self-production')
		AND r.amount > 0
	GROUP BY r.origin_stock_id
),
corrections AS (
	-- What each edit removed from it, as a signed amount: negative when the edit reduced
	-- the entry, positive when it raised it.
	SELECT
		sl_new.origin_stock_id,
		sl_new.id,
		sl_new.amount - COALESCE(sl_old.amount, sl_new.amount) AS correction
	FROM resolved sl_new
	LEFT JOIN LATERAL (
		SELECT sl_prev.amount
		FROM resolved sl_prev
		WHERE sl_prev.transaction_type = 'stock-edit-old'
			AND sl_prev.stock_id = sl_new.stock_id
			AND sl_prev.id < sl_new.id
		-- The correlated partner where there is one; the nearest preceding
		-- "stock-edit-old" on the same entry otherwise. EditStockEntry() writes the pair
		-- adjacently inside one transaction, so the two agree except where concurrent
		-- edits of one entry interleaved their ids -- which is where the correlation id
		-- is the only thing that can tell them apart.
		ORDER BY (sl_prev.correlation_id IS NOT DISTINCT FROM sl_new.correlation_id) DESC, sl_prev.id DESC
		LIMIT 1
	) sl_old ON TRUE
	WHERE sl_new.transaction_type = 'stock-edit-new'
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

-- Rebuild both caches, because the API reads those rather than these views:
-- uihelper_product_details and uihelper_stock_current_overview select from
-- cache__products_average_price and cache__products_last_purchased, which are only ever
-- written by the stock_log triggers. Without this, a product affected by the change above
-- keeps reporting the old value until its next booking, which looks exactly like the fix
-- not working. products_last_purchased is included because its price comes from
-- products_price_history, whose row set is decided by this view.
INSERT INTO cache__products_average_price (product_id, price)
SELECT product_id, price
FROM products_average_price
ON CONFLICT (product_id) DO UPDATE SET
	price = EXCLUDED.price;

INSERT INTO cache__products_last_purchased
	(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
FROM products_last_purchased
ON CONFLICT (product_id) DO UPDATE SET
	amount = EXCLUDED.amount,
	best_before_date = EXCLUDED.best_before_date,
	purchased_date = EXCLUDED.purchased_date,
	price = EXCLUDED.price,
	location_id = EXCLUDED.location_id,
	shopping_location_id = EXCLUDED.shopping_location_id;
