-- Working container replenishment: a minimum on the (product, location) pair, so "keep 5 lb
-- of flour in the kitchen bin" is expressible separately from "keep 20 lb of flour" — and
-- the tare that lets a vessel (a bin, a spice jar) be weighed directly. See
-- docs/plans/landed/29-working-container-replenishment.md and issue #131.
--
-- WHY A FOURTH MINIMUM TABLE RATHER THAN A COLUMN. Most products have no location minimum
-- and most locations are not a bin anybody refills, so a column on `products` or `locations`
-- cannot express the pair - the same reasoning migrations/0268.pgsql.sql gives for
-- `product_groups_missing`. `product_location_min_stock` is keyed on (product_id,
-- location_id) with a UNIQUE constraint on the pair, so a product may have a minimum at
-- several locations (flour in the pantry bin and flour in a second kitchen tin) and a
-- location may hold minimums for several products.
--
-- THE RULE THAT MUST NOT BE GOT WRONG. A location minimum produces a refill prompt, never a
-- shopping list entry - that is the entire point of this plan, and getting it backwards is
-- the failure case the plan exists to avoid. product_location_missing is therefore a
-- separate view, exactly as product_groups_missing is separate from stock_missing_products:
-- nothing here is unioned into that view, and StockService::AddMissingProductsToShoppingList()
-- is not touched by this migration at all.
--
-- WHY THE TARE IS ON THE LOCATION AND NOT THE STOCK ENTRY. Decided 2026-09-14 under
-- ADR-0022 question 1, decision 4. A bin or a spice jar is a place stock passes through, not
-- a container stock arrived in: every refill runs through StockService::TransferProduct(),
-- which mints a new stock row at the destination, and CompactStockEntries() merges rows - a
-- tare on the entry would have to be copied on every refill and survive compaction, where a
-- tare on the location is set once and outlives every row that passes through it. Both
-- columns are nullable: NULL means "not a vessel", which is what every existing location
-- means today, so this migration changes no behaviour on upgrade.
--
-- `tare_qu_id` carries a FOREIGN KEY, unlike `parent_location_id` on this same table
-- (migrations/0273.pgsql.sql): it is ordinary lookup data with no self-referential-tree
-- argument against a constraint, the same reasoning migrations/0274.pgsql.php gives for
-- `storage_class_id`. The unit is the location's own, since a location holds no stock unit
-- to borrow (a location is not stocked in anything); conversion to the stocked product's own
-- unit goes through the global `quantity_unit_conversions_resolved` view under ADR-0022
-- decision 3's rule - refused, never assumed, when no conversion path exists. That refusal
-- lives in StockService::WeighLocation(), not in a CHECK, for the reason ADR-0022's spike
-- found for the sibling plan: convertibility depends on the recursive conversions view, which
-- cannot be re-derived per row inside a database constraint.
--
-- WHY THIS DOES NOT TOUCH TransferProduct()'s TARE REFUSAL DIRECTLY IN THIS FILE. The refusal
-- StockService::TransferProduct() throws for tare-enabled products (ADR-0022 context limit 4)
-- is retired by this plan in the service layer, not by a schema change - see that method.
-- Plan 28 (concurrent, sibling branch) owns the same retirement for OpenProduct() and the
-- product-total arithmetic in AddProduct()/ConsumeProduct()/InventoryProduct(); this plan
-- owns TransferProduct()'s refusal and the entry-scoped tared correction that weighing a
-- vessel needs, per the division recorded in issue #131.
--
-- THE ONE-TAP REFILL COLUMNS ON products. Three columns following the shape
-- products.quick_consume_amount/default_consume_location_id already have, which
-- public/viewjs/consume.js already reads for the "quick consume" button:
-- `quick_refill_amount` (how much a tap moves), `default_refill_location_id_from` (where
-- backstock normally lives) and `default_refill_location_id_to` (which vessel a tap refills
-- by default). A refill is `StockService::TransferProduct()` with these as its preset amount
-- and locations - nothing new is booked, matching how quick consume is ConsumeProduct() with
-- a preset amount. All three are nullable/defaulted so every existing product is unaffected;
-- the one-tap button only renders where a product has configured them (or, for a refill
-- prompt raised by a shortfall row, the shortfall's own location stands in for the
-- destination - see StockController::Overview()).
--
-- uihelper_stock_current_overview IS WIDENED IN PLACE, THE SAME WAY migrations/0267.pgsql.sql
-- widened stock_edited_entries: it is a shared view that already exists below the SQLite
-- freeze (db/pgsql/baseline/05_views_l3.sql), so CREATE OR REPLACE VIEW adds the three new
-- columns the stock overview needs to render the one-tap button without changing anything
-- else about the view's rows. The baseline itself is deliberately not edited, for the reason
-- migrations/0261.pgsql.sql gives: it is the state SQLite reaches after migrations
-- 0001-0255, and a fresh PostgreSQL database loads it and then runs 0256 onwards.
--
-- product_location_min_stock IS NAMED IN migratedifftest.php's ENGINE_EXCLUSIVE_TABLES,
-- with no @engine-exclusive marker: the marker is asked for only below the SQLite freeze,
-- where a lone engine-specific file could be a missing counterpart rather than the only file
-- that could exist (db/pgsql/README.md). Above the freeze, a wholly new table has no SQLite
-- side to compare against at all and that phase's own table-set comparison would otherwise
-- report it missing - measured while writing this migration, matching migrations/0274.pgsql.php's
-- storage_classes precedent exactly. `locations.tare_weight`/`tare_qu_id` and the columns on
-- `products` need no such entry: they are new columns on tables the phase already compares by
-- their shared column intersection, so a PostgreSQL-only column on a shared table is already
-- invisible to it, the same as storage_class_id was.

ALTER TABLE locations ADD COLUMN tare_weight DOUBLE PRECISION;
ALTER TABLE locations ADD COLUMN tare_qu_id INTEGER REFERENCES quantity_units(id);

ALTER TABLE products ADD COLUMN quick_refill_amount DOUBLE PRECISION NOT NULL DEFAULT 1;
ALTER TABLE products ADD COLUMN default_refill_location_id_from INTEGER;
ALTER TABLE products ADD COLUMN default_refill_location_id_to INTEGER;

CREATE TABLE product_location_min_stock (
	id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	product_id INTEGER NOT NULL REFERENCES products(id),
	location_id INTEGER NOT NULL REFERENCES locations(id),
	min_stock_amount DOUBLE PRECISION NOT NULL DEFAULT 0,
	row_created_timestamp TIMESTAMP DEFAULT date_trunc('second', LOCALTIMESTAMP),

	UNIQUE(product_id, location_id)
);

-- The shortfall report, in the shape migrations/0268.pgsql.sql's product_groups_missing
-- took: a derived table computing amount_missing per (product, location) pair, filtered down
-- to the rows actually short. Unlike the group view there is exactly one "member" per row -
-- the pair names both the product and the location directly - so there is no double-counting
-- trap to guard against; what this view still has to get right is the same opened-stock
-- discount, applied per product via treat_opened_as_out_of_stock, and excluding an inactive
-- product or an inactive location the same way an inactive group is excluded: in the join,
-- not as a bare outer WHERE, so that a location or product that was deactivated after the
-- minimum was set disappears from the shortfall list instead of reporting a stale amount.
--
-- Read from `stock` directly rather than from `stock_current_locations`: that view already
-- sums by (product_id, location_id), which is the right grouping, but it has no opened-stock
-- discount and is not worth adding one to for a single caller.
-- quick_refill_amount and default_refill_location_id_from ride along so the stock overview
-- can render the one-tap refill button straight from this one call, rather than joining
-- /objects/products per row it lists - the refill destination is this row's own location_id,
-- not the product's default_refill_location_id_to, per the plan's "surfaced where the
-- shortfall is reported" (public/viewjs/stockoverview.js renders no button when either is
-- unset, since there is nothing to preset the action with).
CREATE VIEW product_location_missing AS
SELECT *
FROM (
	SELECT
		pl.id,
		pl.product_id,
		p.name AS product_name,
		pl.location_id,
		l.name AS location_name,
		pl.min_stock_amount,
		pl.min_stock_amount - COALESCE(loc.effective_amount, 0) AS amount_missing,
		p.quick_refill_amount,
		p.default_refill_location_id_from
	FROM product_location_min_stock pl
	JOIN products p
		ON p.id = pl.product_id
	JOIN locations l
		ON l.id = pl.location_id
	LEFT JOIN (
		SELECT
			s.product_id,
			s.location_id,
			COALESCE(SUM(s.amount), 0)
				- CASE WHEN p2.treat_opened_as_out_of_stock = 1
					THEN COALESCE(SUM(CASE WHEN s.open = 1 THEN s.amount ELSE 0 END), 0)
					ELSE 0 END AS effective_amount
		FROM stock s
		JOIN products p2
			ON p2.id = s.product_id
		GROUP BY s.product_id, s.location_id, p2.treat_opened_as_out_of_stock
	) loc
		ON loc.product_id = pl.product_id
		AND loc.location_id = pl.location_id
	WHERE pl.min_stock_amount != 0
		AND COALESCE(p.active, 0) = 1
		AND COALESCE(l.active, 0) = 1
) x
WHERE x.amount_missing > 0;

-- WHY THE THREE NEW products COLUMNS ARE JOINED IN SEPARATELY BELOW, RATHER THAN READ OFF
-- products_view's OWN `p.*`. products_view is `SELECT p.*, ... FROM products p ...`
-- (db/pgsql/baseline/03_views_group2.sql), and `p.*` is flattened into an explicit column
-- list at the moment a view is created - PostgreSQL does not re-resolve `*` against the
-- table's current definition on every read, only at CREATE (OR REPLACE) VIEW time. Simply
-- widening uihelper_stock_current_overview to select `p.quick_refill_amount` therefore fails
-- with `column p.quick_refill_amount does not exist` (measured against real PostgreSQL 16.13
-- while writing this migration), because products_view's own flattened list predates these
-- ALTER TABLEs.
--
-- The fix is not to re-issue products_view's definition, though that looks like the obvious
-- move: `p.*` would then re-flatten to include the three new columns, but it does so *in the
-- middle* of the column list - where `products`' own columns end - which pushes every column
-- the baseline lists after `p.*` (has_sub_products and the three qu_factor_* columns) three
-- positions later than they were. CREATE OR REPLACE VIEW refuses that: an existing output
-- column may not change name or position, only new columns may be appended after all of them.
-- Measured the same way: `cannot change name of view column "has_sub_products" to
-- "quick_refill_amount"` is what a verbatim re-creation gets.
--
-- So products_view is left untouched, and uihelper_stock_current_overview reads the three new
-- columns through a second join straight to `products`, appended after every column the
-- baseline already lists - which is exactly what CREATE OR REPLACE VIEW allows.
CREATE OR REPLACE VIEW uihelper_stock_current_overview AS
SELECT
	p.id,
	sc.amount_opened AS amount_opened,
	p.tare_weight AS tare_weight,
	p.enable_tare_weight_handling AS enable_tare_weight_handling,
	sc.amount AS amount,
	sc.value as value,
	sc.product_id AS product_id,
	COALESCE(sc.best_before_date, '2888-12-31') AS best_before_date,
	CASE WHEN EXISTS(SELECT id FROM stock_missing_products WHERE id = sc.product_id) THEN 1 ELSE 0 END AS product_missing,
	p.name AS product_name,
	pg.name AS product_group_name,
	sl.name AS default_store_name,
	CASE WHEN EXISTS(SELECT * FROM shopping_list WHERE shopping_list.product_id = sc.product_id) THEN 1 ELSE 0 END AS on_shopping_list,
	qu_stock.name AS qu_stock_name,
	qu_stock.name_plural AS qu_stock_name_plural,
	qu_purchase.name AS qu_purchase_name,
	qu_purchase.name_plural AS qu_purchase_name_plural,
	qu_consume.name AS qu_consume_name,
	qu_consume.name_plural AS qu_consume_name_plural,
	qu_price.name AS qu_price_name,
	qu_price.name_plural AS qu_price_name_plural,
	sc.is_aggregated_amount,
	sc.amount_opened_aggregated,
	sc.amount_aggregated,
	p.calories AS product_calories,
	sc.amount * p.calories AS calories,
	sc.amount_aggregated * p.calories AS calories_aggregated,
	p.quick_consume_amount,
	p.quick_consume_amount / p.qu_factor_consume_to_stock AS quick_consume_amount_qu_consume,
	p.quick_open_amount,
	p.quick_open_amount / p.qu_factor_consume_to_stock AS quick_open_amount_qu_consume,
	p.due_type,
	plp.purchased_date AS last_purchased,
	plp.price AS last_price,
	pap.price as average_price,
	p.min_stock_amount,
	pbcs.barcodes AS product_barcodes,
	p.description AS product_description,
	l.name AS product_default_location_name,
	p_parent.id AS parent_product_id,
	p_parent.name AS parent_product_name,
	p.picture_file_name AS product_picture_file_name,
	p.no_own_stock AS product_no_own_stock,
	p.qu_factor_purchase_to_stock AS product_qu_factor_purchase_to_stock,
	p.qu_factor_price_to_stock AS product_qu_factor_price_to_stock,
	sc.is_in_stock_or_below_min_stock,
	p.disable_open,
	p3.quick_refill_amount,
	p3.default_refill_location_id_from,
	p3.default_refill_location_id_to
FROM (
	-- Plan 28's migration 0275 (below this one in the merged tree) widened stock_current by
	-- one column, appending amount_measured after due_type. The `SELECT *` branch below picks
	-- that up automatically, but the two branches that stand in for a product with no stock
	-- row spell every column out as a literal and did not, before this fix - a UNION whose
	-- branches disagree on column count, found here while merging plan 28's landed PR into
	-- this branch (`each UNION query must have the same number of columns`, PostgreSQL
	-- 16.13). The extra `0` restores the count; a product with no stock has nothing measured
	-- either.
	SELECT *, 1 AS is_in_stock_or_below_min_stock
	FROM stock_current
	WHERE best_before_date IS NOT NULL
	UNION
	SELECT m.id, 0, 0, 0, NULL::date, 0, 0, 0, p.due_type, 0, 1 AS is_in_stock_or_below_min_stock
	FROM stock_missing_products m
	JOIN products p
		ON m.id = p.id
	WHERE m.id NOT IN (SELECT product_id FROM stock_current)
	UNION
	SELECT p2.id, 0, 0, 0, NULL::date, 0, 0, 0, p2.due_type, 0, 0 AS is_in_stock_or_below_min_stock
	FROM products p2
	WHERE active = 1
		AND p2.id NOT IN (SELECT product_id FROM stock_current UNION SELECT id FROM stock_missing_products)
	) sc
JOIN products_view p
	ON sc.product_id = p.id
JOIN locations l
	ON p.location_id = l.id
JOIN quantity_units qu_stock
	ON p.qu_id_stock = qu_stock.id
JOIN quantity_units qu_purchase
	ON p.qu_id_purchase = qu_purchase.id
JOIN quantity_units qu_consume
	ON p.qu_id_consume = qu_consume.id
JOIN quantity_units qu_price
	ON p.qu_id_price = qu_price.id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN shopping_locations sl
	ON p.shopping_location_id = sl.id
LEFT JOIN cache__products_last_purchased plp
	ON sc.product_id = plp.product_id
LEFT JOIN cache__products_average_price pap
	ON sc.product_id = pap.product_id
LEFT JOIN product_barcodes_comma_separated pbcs
	ON sc.product_id = pbcs.product_id
LEFT JOIN products p_parent
	ON p.parent_product_id = p_parent.id
-- Plain, not products_view: only the three new columns are wanted here, and products_view's
-- own `p.*` cannot supply them yet (see the comment above this view).
JOIN products p3
	ON p3.id = p.id
WHERE p.hide_on_stock_overview = 0;
