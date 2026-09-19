-- The measured remainder of an opened container: three sealed bags of flour and one open
-- bag holding 1.2 kg becomes a state `stock` can hold. See
-- docs/plans/landed/28-open-container-measurement.md and docs/adr/0022-open-containers-carry-a-measured-remainder.md
-- (Accepted 2026-09-14, all eight prerequisites met against real PostgreSQL 16.13 in
-- .spike-adr22/, PR #152). This file mirrors that spike's 01-stock-measurement.pgsql.sql,
-- widened to cover the full column set the affected views actually project and the two
-- rewritten views verification asks for, rather than the spike's cut-down copies.
--
-- SCHEMA (decisions 1, 3, 9). Four columns on `stock` carry the raw measurement; the same
-- four on `stock_log` mirror it into the ledger, because UndoBooking()'s consume branch
-- rebuilds a fully-consumed `stock` row from `stock_log` alone
-- (services/StockService.php:2200-2213 before this plan's changes) and a measurement that
-- lived only on `stock` would be lost silently the moment that row was deleted. No column
-- is added to `products` — decision 3 derives the fraction through the per-product
-- `quantity_unit_conversions` rows the pooled-parent roll-up already needs, deliberately not
-- a second net-contents figure that could disagree with them.
--
-- opened_amount and opened_qu_id are a pair: `opened_amount` is always NET contents, in
-- `opened_qu_id`. `opened_tare` is independently nullable — ADR-0022 decision 4, decided
-- 2026-09-14: a device posts a gross reading and the server subtracts the container's own
-- tare before storing, so `opened_tare` records what was subtracted (for the record and for
-- undo) and stays NULL for a measurement that was already net. This is the write path's
-- arithmetic (services/StockService.php), not a generated column, because deriving it from
-- a stored gross figure would require storing gross too and reintroduce exactly the
-- "does a client subtract twice" ambiguity decision 4 closes by naming the input `gross`
-- explicitly.
--
-- THE COHERENCE CONSTRAINT (decision 8). A measurement describes exactly one container:
-- `open = 1 AND amount = 1`. `OpenProduct()` can mark a whole multi-unit row open in place
-- without changing its amount (services/StockService.php, the `else` branch's counterpart),
-- so `open = 1` alone never identifies one container — three jugs bought without per-unit
-- labels can be one row with `open = 1, amount = 3`. The spike's prerequisite 5 run
-- demonstrated the constraint refusing exactly that shape and a split leaving a
-- `1`-unit entry a measurement can attach to
-- (.spike-adr22/RESULTS.md#prerequisite-5-container-identity). `opened_tare` is
-- deliberately outside this CHECK — a net measurement carries no tare and is still a
-- complete, legal measurement.
--
-- CONVERTIBILITY IS NOT THIS CONSTRAINT (decision 3, spike prerequisite 7's own finding).
-- Coherence — one container, one unit — is a fact about the row itself and can be a
-- database CHECK. Whether `opened_qu_id` actually converts to the product's `qu_id_stock`
-- depends on the recursive `quantity_unit_conversions_resolved` view, which cannot be
-- re-derived per row inside a CHECK without turning every stock write into a recursive
-- query the planner cannot index. That refusal is the write path's own job in
-- StockService, checked with a `SELECT` against `cache__quantity_unit_conversions_resolved`
-- before the row is ever written — see AddProduct/OpenProduct/MeasureStockEntry. A
-- measurement whose conversion is later deleted is not caught by anything here either: its
-- raw `opened_amount`/`opened_qu_id` survive untouched and only the *derived* fraction a
-- reader computes goes to NULL, which is `stock_current.amount_measured` below, not a
-- stored column that could go stale.
--
-- PRE-EXISTING open=1, amount>1 ROWS (decision 8's migration question, and the plan's own
-- "the migration has to choose, and say so"). Chosen: leave them as they are. They pass the
-- new CHECK unchanged (opened_amount/opened_qu_id are NULL on every such row, which the
-- first disjunct of the constraint permits regardless of amount), and they simply cannot
-- accept a measurement until a person splits them — the same split `OpenProduct()` already
-- performs when opening covers less than a whole entry. Auto-splitting them here was
-- rejected: it would mint new stock_ids for existing containers with no explicit user
-- action behind it, on every one of what could be years of purchase history, to serve a
-- feature that has not been configured for a single product yet (open question 5 — which
-- products are measured is still unanswered). A household that wants to measure one of
-- these containers opens the entry again for the remaining amount via the existing
-- partial-open path, which performs the split.
--
-- THE THIRD stock_splits EXCLUSION (decision 5). `stock_splits`
-- (db/pgsql/baseline/03_views_group3.sql:73-99) already excludes per-unit labelled entries
-- (`stock_id LIKE 'x%'`) and entries carrying userfield values; a measured entry is a third
-- kind of row `CompactStockEntries()` must never merge, because merging two containers into
-- one would either duplicate a remainder across a merged amount or silently drop it. The
-- userfield_values join is kept exactly as it is — the spike's own copy of this view left
-- it out because prerequisite 3 was not testing it, not because this plan changes it.
--
-- stock_current (decision 2, and this plan's open question 3, left open on purpose).
-- `amount`, `amount_aggregated`, `amount_opened` and `amount_opened_aggregated` are
-- UNCHANGED — an opened, measured jug still counts as one whole jug in every one of them,
-- exactly as decision 2 requires ("stock.amount keeps its present meaning"). Whether the
-- pooled parent's total should instead CONSUME the measurement is this plan's question 3,
-- and it is explicitly not decided by ADR-0022 or by this migration — "folding it in gives
-- one honest number and changes what an existing column means... This is the line where
-- this plan and 07 edit the same code" (plan 28). 07 was retired 2026-09-14 before this
-- plan reached implementation (ADR-0023), so there is no second plan left to coordinate
-- that rewrite with, but the question itself is still unanswered on its own terms, and an
-- implementation session is not the maintainer. So this migration takes the conservative
-- half of the plan's own text — "kept separate ... so that no present column changes
-- meaning" — and adds one new column, `amount_measured`, beside the existing four, recorded
-- as this plan's answer to question 3 in its own Open Questions section rather than
-- silently decided here.
--
-- `amount_measured` sums, per product, the derived NET fraction of every measured entry in
-- the product's own stock unit — strictly converted (no `COALESCE(..., 1.0)` fallback; an
-- unconvertible measurement contributes NULL, which SUM() ignores, matching decision 3).
-- For the parent-rollup branch it is then scaled by the SAME pre-existing sub-to-parent
-- `qucr.factor` (with its existing, untouched `COALESCE(..., 1.0)` fallback per this plan's
-- open question 2 — the roll-up's own fallback is pre-existing behaviour this plan does not
-- touch) that `amount_aggregated` already applies to `s.amount`, so the two figures scale
-- the same way into the parent's unit. A caller wanting "the honest total" computes it
-- itself: `amount_aggregated` minus the count of measured entries (each counted as a whole
-- unit inside it) plus `amount_measured`.
--
-- No ENGINE_EXCLUSIVE_TABLES entry and no @engine-exclusive marker, for the reason
-- migrations/0267.pgsql.sql and 0268.pgsql.sql both give at length: migratedifftest.php
-- enumerates BASE TABLE only and compares the intersection of the two engines' columns, so
-- new PostgreSQL-only columns on the shared `stock`/`stock_log` tables are already invisible
-- to it, and a view redefinition (stock_splits, stock_current) is not a table at all. The
-- marker is asked for only below the SQLite freeze, where a lone engine-specific file could
-- be a missing counterpart rather than the only file that could exist; above it, per
-- db/pgsql/README.md, this stops being about engine exclusivity altogether.
--
-- THE TARE RETIREMENT (ADR-0022 decisions 4 and 7, decided 2026-09-14 by the maintainer;
-- ADR-0022 open question 1). `enable_tare_weight_handling` and `tare_weight` stay on
-- `/objects/products` at their current values — no column is dropped, no response shape
-- changes — but their arithmetic goes. Three PHP branches (services/StockService.php,
-- AddProduct/ConsumeProduct/InventoryProduct) that read `$product->tare_weight` against a
-- product's *whole* stock total are removed in the same change that adds this schema,
-- because the spike's negative control demonstrated exactly the defect Context describes
-- against real rows: three sealed 5 lb bags plus a canister weighed at a gross 1.4 lb made
-- the existing formula compute 18.8 lb "consumed" against the 3.8 lb the canister actually
-- gave up (.spike-adr22/RESULTS.md#prerequisite-1). `OpenProduct()`'s tare-enabled refusal
-- is removed for the same reason plan 28 owns it: decision 8 needs `OpenProduct()` able to
-- open (and now measure) a tare-configured product's containers directly, since the
-- per-entry mechanism below supersedes what per-product tare was standing in for.
-- `TransferProduct()`'s own refusal is explicitly NOT touched here — ADR-0022 decision 4
-- gives the vessel's tare to `locations`, not to the stock entry, and that refusal's removal
-- belongs to plan 29 (`locations.tare_weight`/`tare_qu_id`, migration 0276), which needs
-- transfers to work for bin refills. Enabling the flag from here on is refused by the
-- product write path at 400 (controllers/Api/GenericEntityApiController.php), naming the
-- location tare that replaced it; that refusal has no schema of its own to add — `products`
-- carries no CHECK against its own flag, matching the ADR's own framing of this as a
-- write-path business rule rather than a data-integrity constraint, and it must still be
-- possible to persist a *pre-existing* enabled product unchanged (no forced downgrade on
-- next save). The rescale trigger `trg_cascade_change_qu_id_stock2`
-- (db/pgsql/baseline/06_triggers_a.sql:542-555) loses only its `NEW.tare_weight := ...`
-- line below — its other three rescales (quick_consume_amount, quick_open_amount, calories)
-- are unrelated to tare and stay exactly as they are. The two fields leave the OpenAPI
-- contract at plan 14 piece 2's freeze per decision 7, not here.
--
-- The baseline in db/pgsql/baseline/ is deliberately not edited, for the reason
-- migrations/0261.pgsql.sql gives: it is the state SQLite reaches after migrations
-- 0001-0255, and a fresh PostgreSQL database loads it and then runs 0256 onwards.

ALTER TABLE stock ADD COLUMN opened_amount DOUBLE PRECISION;
ALTER TABLE stock ADD COLUMN opened_qu_id INTEGER;
ALTER TABLE stock ADD COLUMN opened_tare DOUBLE PRECISION;
ALTER TABLE stock ADD COLUMN opened_measured_at TIMESTAMP;

ALTER TABLE stock ADD CONSTRAINT stock_measurement_coherence_check CHECK (
	(opened_amount IS NULL AND opened_qu_id IS NULL)
	OR (opened_amount IS NOT NULL AND opened_qu_id IS NOT NULL AND open = 1 AND amount = 1)
);

ALTER TABLE stock_log ADD COLUMN opened_amount DOUBLE PRECISION;
ALTER TABLE stock_log ADD COLUMN opened_qu_id INTEGER;
ALTER TABLE stock_log ADD COLUMN opened_tare DOUBLE PRECISION;
ALTER TABLE stock_log ADD COLUMN opened_measured_at TIMESTAMP;

-- stock_next_use's `s.*` freezes the exact column list of `stock` at CREATE VIEW time - a
-- PostgreSQL view is not "SELECT * re-evaluated on every query," so the four new columns
-- above are invisible through it until it is recreated. This matters because
-- GetProductStockEntries()/GetProductStockEntriesForLocation() read this view, and LessQL's
-- Row::update() issues its UPDATE against the object it was fetched through: without this,
-- OpenProduct()'s and ConsumeProduct()'s own writes of opened_amount/opened_qu_id/opened_tare/
-- opened_measured_at onto a row fetched from stock_next_use fail outright with "column ...
-- does not exist" - caught by .devtools/pgsql/open-container-measurement-tests.php case 4,
-- which is what surfaced this. The body is otherwise byte-identical to
-- db/pgsql/baseline/03_views_group3.sql.
CREATE OR REPLACE VIEW stock_next_use AS
SELECT
	(CAST(ROW_NUMBER() OVER(PARTITION BY s.product_id ORDER BY CASE WHEN COALESCE(p.default_consume_location_id, -1) = s.location_id THEN 0 ELSE 1 END ASC, s.open DESC, s.best_before_date ASC, s.purchased_date ASC) AS INTEGER)) * -1 AS priority,
	s.*
FROM stock s
JOIN products p
	ON p.id = s.product_id
ORDER BY CASE WHEN COALESCE(p.default_consume_location_id, -1) = s.location_id THEN 0 ELSE 1 END ASC, s.open DESC, s.best_before_date ASC, s.purchased_date ASC;

CREATE OR REPLACE VIEW stock_splits AS

/*
	Helper view which shows splitted stock rows which could be compacted

	Stock entries with a stock_id starting with "x", those with userfields, and those
	carrying a measured remainder (opened_amount IS NOT NULL) shouldn't be compacted
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
	AND NOT EXISTS(
		SELECT 1 FROM userfield_values
		WHERE object_id = s.stock_id
			AND field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
			AND COALESCE(value, '') != ''
		)
GROUP BY s.product_id, s.best_before_date, s.purchased_date, s.price, s.open, s.opened_date, s.location_id, s.shopping_location_id, COALESCE(s.note, '')
HAVING COUNT(*) > 1;

CREATE OR REPLACE VIEW stock_current AS
SELECT
	pr.parent_product_id AS product_id,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id), 0) AS amount,
	SUM(s.amount * COALESCE(qucr.factor::double precision, 1.0::double precision)) AS amount_aggregated,
	COALESCE(CAST(ROUND(CAST((SELECT SUM(COALESCE(price,0) * amount) FROM stock WHERE product_id = pr.parent_product_id) AS numeric), 2) AS double precision), 0) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id AND open = 1), 0) AS amount_opened,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id IN (SELECT sub_product_id FROM products_resolved WHERE parent_product_id = pr.parent_product_id) AND open = 1), 0) * COALESCE(MIN(qucr.factor::double precision), 1.0) AS amount_opened_aggregated,
	CASE WHEN COUNT(p_sub.parent_product_id) > 0  THEN 1 ELSE 0 END AS is_aggregated_amount,
	MAX(p_parent.due_type) AS due_type,
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision * COALESCE(qucr.factor::double precision, 1.0::double precision)), 0) AS amount_measured
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_parent
	ON pr.parent_product_id = p_parent.id
	AND p_parent.active = 1
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON pr.sub_product_id = qucr.product_id
	AND p_sub.qu_id_stock = qucr.from_qu_id
	AND p_parent.qu_id_stock = qucr.to_qu_id
LEFT JOIN cache__quantity_unit_conversions_resolved qucr_measure
	ON s.product_id = qucr_measure.product_id
	AND s.opened_qu_id = qucr_measure.from_qu_id
	AND p_sub.qu_id_stock = qucr_measure.to_qu_id
GROUP BY pr.parent_product_id
HAVING SUM(s.amount) > 0

UNION

-- This is the same as above but sub products not rolled up (no QU conversion and column is_aggregated_amount = 0 here)
SELECT
	pr.sub_product_id AS product_id,
	SUM(s.amount) AS amount,
	SUM(s.amount) AS amount_aggregated,
	CAST(ROUND(CAST(SUM(COALESCE(s.price, 0) * s.amount) AS numeric), 2) AS double precision) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened_aggregated,
	0 AS is_aggregated_amount,
	MAX(p_sub.due_type) AS due_type,
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision), 0) AS amount_measured
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
LEFT JOIN cache__quantity_unit_conversions_resolved qucr_measure
	ON s.product_id = qucr_measure.product_id
	AND s.opened_qu_id = qucr_measure.from_qu_id
	AND p_sub.qu_id_stock = qucr_measure.to_qu_id
WHERE pr.parent_product_id != pr.sub_product_id
GROUP BY pr.sub_product_id
HAVING SUM(s.amount) > 0;

-- uihelper_stock_entries lists `stock`'s columns explicitly rather than `s.*` (it has to -
-- see db/pgsql/baseline/04_views_l1b.sql's own note on why a bare join can't express this
-- view at all), so it inherited none of stock_next_use's "s.* picks up new columns
-- automatically" problem above - it never had that possibility, and needed its own explicit
-- extension instead. This is the page views/stockentries.blade.php itself reads
-- (StockController::Stockentries()), so without it every stock entry list in the UI would
-- show a measured container as merely "Opened", with no error and nothing to notice -
-- caught by .devtools/frontend/open-container-measurement.js, not by anything in
-- .devtools/pgsql/, which is the browser probe's whole reason for existing per the plan's
-- own verification list. The four columns are appended at the very end of the SELECT list,
-- after every existing column - PostgreSQL's CREATE OR REPLACE VIEW refuses to change an
-- existing output column's name or ordinal position (verified: inserting them between
-- stock's block and products_view's, which is where they sit conceptually, fails with
-- "cannot change name of view column ... to ..." the moment it would renumber "id:1"
-- onward), so appending at the end is not a style choice, it is the only place a
-- CREATE OR REPLACE can legally put them.
CREATE OR REPLACE VIEW uihelper_stock_entries AS
SELECT
	s.id,
	s.product_id,
	s.amount,
	s.best_before_date,
	s.purchased_date,
	s.stock_id,
	s.price,
	s.open,
	s.opened_date,
	s.row_created_timestamp,
	s.location_id,
	s.shopping_location_id,
	s.note,
	p.id AS "id:1",
	p.name,
	p.description,
	p.product_group_id,
	p.active,
	p.location_id AS "location_id:1",
	p.shopping_location_id AS "shopping_location_id:1",
	p.qu_id_purchase,
	p.qu_id_stock,
	p.min_stock_amount,
	p.default_best_before_days,
	p.default_best_before_days_after_open,
	p.default_best_before_days_after_freezing,
	p.default_best_before_days_after_thawing,
	p.picture_file_name,
	p.enable_tare_weight_handling,
	p.tare_weight,
	p.not_check_stock_fulfillment_for_recipes,
	p.parent_product_id,
	p.calories,
	p.cumulate_min_stock_amount_of_sub_products,
	p.due_type,
	p.quick_consume_amount,
	p.hide_on_stock_overview,
	p.default_stock_label_type,
	p.should_not_be_frozen,
	p.treat_opened_as_out_of_stock,
	p.no_own_stock,
	p.default_consume_location_id,
	p.move_on_open,
	p.row_created_timestamp AS "row_created_timestamp:1",
	p.qu_id_consume,
	p.auto_reprint_stock_label,
	p.quick_open_amount,
	p.qu_id_price,
	p.disable_open,
	p.default_purchase_price_type,
	p.has_sub_products,
	p.qu_factor_purchase_to_stock,
	p.qu_factor_consume_to_stock,
	p.qu_factor_price_to_stock,
	s.opened_amount,
	s.opened_qu_id,
	s.opened_tare,
	s.opened_measured_at
FROM stock s
JOIN products_view p
	ON s.product_id = p.id;

-- stock_next_use's own INSTEAD OF triggers (db/pgsql/baseline/06_triggers_b.sql) are what
-- make the view above writable at all - PostgreSQL will not auto-update a view whose FROM
-- list joins two relations, and `information_schema.views.is_updatable` says so for this
-- one. What is easy to miss, because Postgres does NOT refuse the write: an
-- `UPDATE stock_next_use SET opened_amount = ...` against the *unmodified* trigger below
-- reports "UPDATE 1" and changes nothing, because the function's own SET list names every
-- column of `stock` explicitly and simply has never heard of the four new ones - there is no
-- NEW.* passthrough for it to have picked them up automatically. This is exactly the failure
-- mode OpenProduct()'s and ConsumeProduct()'s writes onto a row fetched from
-- GetProductStockEntries() (which reads this view) would hit silently, discarding a
-- measurement with no error and no other symptom than the column staying NULL - caught by
-- .devtools/pgsql/open-container-measurement-tests.php case 4 once the fatal error the
-- unmodified view (above) produced first was fixed, which is what made this second, quieter
-- defect visible at all. Both the INSERT and UPDATE triggers are extended; DELETE needs
-- nothing since it identifies the row by id alone.
CREATE OR REPLACE FUNCTION trg_stock_next_use_INS() RETURNS TRIGGER AS $$
BEGIN
	INSERT INTO stock
		(product_id, amount, best_before_date, purchased_date, stock_id,
		price, open, opened_date, location_id, shopping_location_id, note,
		opened_amount, opened_qu_id, opened_tare, opened_measured_at)
	VALUES
		(NEW.product_id, NEW.amount, NEW.best_before_date, NEW.purchased_date, NEW.stock_id,
		NEW.price, NEW.open, NEW.opened_date, NEW.location_id, NEW.shopping_location_id, NEW.note,
		NEW.opened_amount, NEW.opened_qu_id, NEW.opened_tare, NEW.opened_measured_at);

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trg_stock_next_use_UPD() RETURNS TRIGGER AS $$
BEGIN
	UPDATE stock
	SET product_id = NEW.product_id,
		amount = NEW.amount,
		best_before_date = NEW.best_before_date,
		purchased_date = NEW.purchased_date,
		stock_id = NEW.stock_id,
		price = NEW.price,
		open = NEW.open,
		opened_date = NEW.opened_date,
		location_id = NEW.location_id,
		shopping_location_id = NEW.shopping_location_id,
		note = NEW.note,
		opened_amount = NEW.opened_amount,
		opened_qu_id = NEW.opened_qu_id,
		opened_tare = NEW.opened_tare,
		opened_measured_at = NEW.opened_measured_at
	WHERE id = NEW.id;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- The rescale trigger loses only its tare line; quick_consume_amount, quick_open_amount and
-- calories are untouched and unrelated to this plan.
CREATE OR REPLACE FUNCTION trg_cascade_change_qu_id_stock2() RETURNS TRIGGER AS $$
DECLARE
	v_factor DOUBLE PRECISION;
BEGIN
	v_factor := COALESCE((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0);

	NEW.quick_consume_amount := NEW.quick_consume_amount * v_factor;
	NEW.quick_open_amount := NEW.quick_open_amount * v_factor;
	NEW.calories := NEW.calories / v_factor;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
