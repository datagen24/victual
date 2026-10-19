--
-- PostgreSQL database dump
--

\restrict YWkCifrgZ10RPx2NGjHS07ST7FVadCdFoZLGejjFGaOQLT4cWVq9Lvd9KzKRjJa

-- Dumped from database version 16.15 (Debian 16.15-1.pgdg13+2)
-- Dumped by pg_dump version 16.15 (Debian 16.15-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: nocase; Type: COLLATION; Schema: public; Owner: -
--

CREATE COLLATION public.nocase (provider = icu, deterministic = false, locale = 'und-u-ks-level2');


--
-- Name: backfill_legacy_stock_label_retirements(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.backfill_legacy_stock_label_retirements() RETURNS bigint
    LANGUAGE sql
    AS $$
	WITH inserted AS (
		INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, outcome, reason, closed_at)
		SELECT l.uid, l.retired_at, l.retirement_snapshot, 'legacy', 'declined', 'legacy', l.retired_at
		FROM labels l
		WHERE l.kind = 'stock_entry' AND l.retired_at IS NOT NULL
			AND NOT EXISTS (SELECT 1 FROM stock_label_retirements e WHERE e.label_uid = l.uid)
		RETURNING 1)
	SELECT count(*) FROM inserted
$$;


--
-- Name: cancel_queued_label_jobs(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.cancel_queued_label_jobs(p_label_uid text) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_outbox_id INTEGER;
BEGIN
	FOR v_outbox_id IN
		UPDATE print_jobs
		SET cancelled_at = CURRENT_TIMESTAMP, cancelled_reason = 'label retired'
		WHERE label_uid = p_label_uid
			AND cancelled_at IS NULL
			AND outcome IS NULL
			AND current_attempt_id IS NULL
		RETURNING outbox_id
	LOOP
		UPDATE outbox SET dead_lettered_at = CURRENT_TIMESTAMP, last_error = 'Cancelled: label retired'
		WHERE id = v_outbox_id AND dead_lettered_at IS NULL AND delivered_at IS NULL;
	END LOOP;
END
$$;


--
-- Name: guard_stock_label_retirement_history(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.guard_stock_label_retirement_history() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF TG_OP = 'DELETE' THEN
		RAISE EXCEPTION 'stock_label_retirements is history; event % cannot be deleted', OLD.id;
	END IF;
	IF OLD.outcome IS NOT NULL THEN
		RAISE EXCEPTION 'stock_label_retirements event % is already closed', OLD.id;
	END IF;
	IF NEW.outcome IS NULL
		OR (NEW.id, NEW.label_uid, NEW.retired_at, NEW.snapshot, NEW.cause, NEW.import_epoch, NEW.booking_id,
			NEW.product_id, NEW.amount, NEW.jobs_through_id, NEW.revivable_until)
		IS DISTINCT FROM (OLD.id, OLD.label_uid, OLD.retired_at, OLD.snapshot, OLD.cause, OLD.import_epoch, OLD.booking_id,
			OLD.product_id, OLD.amount, OLD.jobs_through_id, OLD.revivable_until) THEN
		RAISE EXCEPTION 'stock_label_retirements event % may only be closed, not otherwise changed', OLD.id;
	END IF;
	RETURN NEW;
END
$$;


--
-- Name: hierarchy_depth_limit(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.hierarchy_depth_limit() RETURNS integer
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT 6 $$;


--
-- Name: label_current_import_epoch(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.label_current_import_epoch() RETURNS bigint
    LANGUAGE sql STABLE
    AS $$ SELECT epoch FROM label_import_state WHERE id = 1 $$;


--
-- Name: rebuild_stock_log_cache_for_product(integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.rebuild_stock_log_cache_for_product(target_product_id integer) RETURNS void
    LANGUAGE plpgsql
    SET jit TO 'off'
    AS $$
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
$$;


--
-- Name: reconcile_stock_log_cache(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.reconcile_stock_log_cache() RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
	affected_product_id INTEGER;
BEGIN
	-- Every product_id either cache table currently has a row for, plus every product_id
	-- stock_log has a row for (a product whose cache is entirely missing a row, never
	-- written at all by some already-fixed path, is exactly the "missing row" corruption
	-- this reconciliation also has to repair) - rebuilt one at a time through the same
	-- helper the triggers use, which itself replaces or removes each cache row to match
	-- what the views currently compute.
	FOR affected_product_id IN
		SELECT product_id FROM cache__products_average_price
		UNION
		SELECT product_id FROM cache__products_last_purchased
		UNION
		SELECT product_id FROM stock_log
	LOOP
		PERFORM rebuild_stock_log_cache_for_product(affected_product_id);
	END LOOP;

	-- Belt and braces beyond the loop above: a cache row for a product_id that is not in
	-- `products` at all (the deleted-product orphan this same issue's DEL fix already stops
	-- happening going forward) is removed outright, whether or not that id still has any
	-- stock_log row of its own to have driven the loop above.
	DELETE FROM cache__products_average_price
	WHERE product_id NOT IN (SELECT id FROM products);

	DELETE FROM cache__products_last_purchased
	WHERE product_id NOT IN (SELECT id FROM products);
END;
$$;


--
-- Name: record_stock_label_retirement(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.record_stock_label_retirement() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE
	v_row BIGINT := (NEW.retirement_snapshot ->> 'id')::BIGINT;
	v_amount DOUBLE PRECISION := (NEW.retirement_snapshot ->> 'amount')::DOUBLE PRECISION;
	v_booking_text TEXT := current_setting('victual.retiring_booking_id', true);
	v_window_text TEXT := current_setting('victual.label_revival_window_seconds', true);
	v_booking BIGINT;
	v_window BIGINT;
	v_product BIGINT;
	v_booked DOUBLE PRECISION;
	v_through BIGINT;
BEGIN
	-- A malformed setting is treated exactly like a missing one: unproven, never an error that
	-- would abort the consumption it describes.
	IF v_booking_text ~ '^[0-9]{1,18}$' AND v_window_text ~ '^[0-9]{1,9}$' THEN
		v_booking := v_booking_text::BIGINT;
		v_window := v_window_text::BIGINT;
	END IF;

	IF v_booking IS NOT NULL AND v_window > 0 AND v_row IS NOT NULL AND v_amount IS NOT NULL THEN
		SELECT l.product_id, -l.amount INTO v_product, v_booked
		FROM stock_log l
		WHERE l.id = v_booking AND l.stock_row_id = v_row AND l.undone = 0 AND l.amount < 0
			AND l.transaction_type IN ('consume', 'inventory-correction');
		IF FOUND AND stock_amounts_equal(v_booked, v_amount) THEN
			SELECT COALESCE(MAX(j.id), 0) INTO v_through FROM print_jobs j WHERE j.label_uid = NEW.uid;
			INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch, booking_id,
				product_id, amount, jobs_through_id, revivable_until)
			VALUES (NEW.uid, NEW.retired_at, NEW.retirement_snapshot, 'consumption', label_current_import_epoch(), v_booking,
				v_product, v_booked, v_through, NEW.retired_at + make_interval(secs => v_window));
			RETURN NULL;
		END IF;
	END IF;

	INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch, outcome, reason, closed_at)
	VALUES (NEW.uid, NEW.retired_at, NEW.retirement_snapshot, 'unproven', label_current_import_epoch(), 'declined', 'unproven',
		NEW.retired_at);
	RETURN NULL;
END
$_$;


--
-- Name: retire_battery_labels(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.retire_battery_labels() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'battery' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;


--
-- Name: retire_chore_labels(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.retire_chore_labels() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'chore' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;


--
-- Name: retire_location_labels(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.retire_location_labels() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'location' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;


--
-- Name: retire_product_labels(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.retire_product_labels() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'product' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;


--
-- Name: retire_recipe_labels(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.retire_recipe_labels() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'recipe' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;


--
-- Name: retire_stock_entry_labels(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.retire_stock_entry_labels() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object(
			'id', OLD.id,
			'product_name', (SELECT p.name FROM products p WHERE p.id = OLD.product_id),
			'best_before_date', OLD.best_before_date,
			'amount', OLD.amount)
	WHERE kind = 'stock_entry' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;


--
-- Name: stock_amounts_equal(double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.stock_amounts_equal(a double precision, b double precision) RETURNS boolean
    LANGUAGE sql IMMUTABLE STRICT
    AS $$
	SELECT abs(a - b) <= GREATEST(1e-9, 1e-12 * GREATEST(abs(a), abs(b)))
$$;


--
-- Name: stock_label_retirements_incomplete(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.stock_label_retirements_incomplete() RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
	SELECT count(*)
	FROM labels l
	WHERE l.kind = 'stock_entry' AND l.retired_at IS NOT NULL
		AND (SELECT count(*) FROM stock_label_retirements e WHERE e.label_uid = l.uid) <> 1
$$;


--
-- Name: stock_lineage_backfill(integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.stock_lineage_backfill(p_product integer DEFAULT NULL::integer) RETURNS TABLE(family_class text, families bigint, stock_rows bigint)
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_bad TEXT;
BEGIN
	SELECT 'stock row ' || s.id INTO v_bad FROM stock s
	WHERE (p_product IS NULL OR s.product_id = p_product) AND s.amount IN ('NaN', 'Infinity', '-Infinity') LIMIT 1;
	IF v_bad IS NULL THEN
		SELECT 'booking ' || l.id INTO v_bad FROM stock_log l
		WHERE (p_product IS NULL OR l.product_id = p_product) AND l.amount IN ('NaN', 'Infinity', '-Infinity') LIMIT 1;
	END IF;
	IF v_bad IS NOT NULL THEN
		RAISE EXCEPTION 'ADR-0036 backfill: % has a non-finite amount and cannot be attributed', v_bad;
	END IF;

	RETURN QUERY
	WITH fam AS MATERIALIZED (
		SELECT * FROM stock_lineage_families(p_product)
	), lots AS MATERIALIZED (
		SELECT DISTINCT f.product_id, f.root, f.class, l.id AS lot_id, l.amount AS lot_amount
		FROM fam f JOIN stock_log l ON l.stock_id = f.stock_id AND l.product_id = f.product_id
		WHERE f.class IN ('E', 'X') AND l.undone = 0 AND stock_log_is_addition(l.transaction_type, l.amount)
	), lot_of AS (
		SELECT lo.product_id, lo.root, min(lo.lot_id) AS lot_id FROM lots lo GROUP BY lo.product_id, lo.root
	), ins_rows AS (
		INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
		SELECT s.id, lo.lot_id, CASE WHEN f.class = 'E' THEN s.amount ELSE lo.lot_amount END, 'derived'
		FROM stock s
		JOIN fam f ON f.stock_id = s.stock_id AND f.product_id = s.product_id
		JOIN lots lo ON lo.product_id = f.product_id AND lo.root = f.root
		WHERE s.amount > 0
		RETURNING 1
	), ins_pool AS (
		INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
		SELECT s.id, NULL, s.amount, 'unknown'
		FROM stock s JOIN fam f ON f.stock_id = s.stock_id AND f.product_id = s.product_id
		WHERE f.class = 'U' AND s.amount > 0
		RETURNING 1
	), ins_bookings AS (
		INSERT INTO stock_booking_lots (booking_id, lot_id, amount, basis)
		SELECT l.id,
			CASE WHEN stock_log_is_addition(l.transaction_type, l.amount) THEN l.id ELSE o.lot_id END,
			l.amount,
			CASE WHEN f.class IN ('E', 'X') THEN 'derived' ELSE 'unknown' END
		FROM stock_log l JOIN fam f ON f.stock_id = l.stock_id AND f.product_id = l.product_id
		LEFT JOIN lot_of o ON o.product_id = f.product_id AND o.root = f.root
		WHERE l.amount <> 0 AND f.class <> 'N'
			AND (f.class = 'E' OR stock_log_is_addition(l.transaction_type, l.amount))
		RETURNING 1
	), counts AS (
		SELECT (SELECT count(*) FROM ins_rows) + (SELECT count(*) FROM ins_pool) + (SELECT count(*) FROM ins_bookings) AS n
	)
	SELECT f.class, count(DISTINCT (f.product_id, f.root)),
		(SELECT count(*) FROM stock s JOIN fam g ON g.stock_id = s.stock_id AND g.product_id = s.product_id WHERE g.class = f.class)
	FROM fam f CROSS JOIN counts
	GROUP BY f.class, counts.n;
END
$$;


--
-- Name: stock_lineage_families(integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.stock_lineage_families(p_product integer DEFAULT NULL::integer) RETURNS TABLE(product_id integer, stock_id text, root text, class text)
    LANGUAGE sql STABLE
    AS $$
	WITH ids AS (
		SELECT s.product_id, s.stock_id FROM stock s WHERE p_product IS NULL OR s.product_id = p_product
		UNION
		SELECT l.product_id, l.stock_id FROM stock_log l
		WHERE (p_product IS NULL OR l.product_id = p_product) AND l.stock_id IS NOT NULL
	), fam AS (
		SELECT i.product_id, i.stock_id, COALESCE(o.origin_stock_id, i.stock_id) AS root
		FROM ids i LEFT JOIN stock_entry_origins o ON o.stock_id = i.stock_id
	), tracked AS (
		SELECT DISTINCT f.product_id, f.root
		FROM fam f
		WHERE EXISTS (SELECT 1 FROM stock s JOIN stock_row_lots rl ON rl.stock_row_id = s.id
				WHERE s.product_id = f.product_id AND s.stock_id = f.stock_id)
			OR EXISTS (SELECT 1 FROM stock_log l JOIN stock_booking_lots bl ON bl.booking_id = l.id
				WHERE l.product_id = f.product_id AND l.stock_id = f.stock_id)
	), live AS (
		SELECT f.product_id, f.root, l.amount, stock_log_is_addition(l.transaction_type, l.amount) AS is_add,
			stock_log_lineage_delta(l.transaction_type, l.amount) AS delta
		FROM stock_log l JOIN fam f ON f.stock_id = l.stock_id AND f.product_id = l.product_id
		WHERE l.undone = 0
	), b AS (
		SELECT live.product_id, live.root,
			count(*) FILTER (WHERE is_add) AS n_add,
			COALESCE(sum(amount) FILTER (WHERE is_add), 0) AS sum_add,
			count(*) FILTER (WHERE NOT is_add) AS n_other,
			COALESCE(sum(delta), 0) AS delta_sum
		FROM live GROUP BY live.product_id, live.root
	), r AS (
		SELECT f.product_id, f.root, count(s.id) AS n_rows, COALESCE(sum(s.amount), 0) AS rows_sum
		FROM fam f LEFT JOIN stock s ON s.stock_id = f.stock_id AND s.product_id = f.product_id
		GROUP BY f.product_id, f.root
	), stat AS (
		SELECT r.product_id, r.root,
			CASE
				WHEN COALESCE(b.n_add, 0) = 0 AND r.n_rows = 0 THEN 'N'
				WHEN COALESCE(b.n_add, 0) = 1 AND stock_amounts_equal(COALESCE(b.delta_sum, 0), r.rows_sum) THEN 'E'
				WHEN COALESCE(b.n_add, 0) >= 2 AND COALESCE(b.n_other, 0) = 0 AND r.n_rows = 1
					AND stock_amounts_equal(b.sum_add, r.rows_sum) THEN 'X'
				ELSE 'U' END AS class
		FROM r LEFT JOIN b ON b.product_id = r.product_id AND b.root = r.root
	)
	SELECT f.product_id, f.stock_id, f.root, st.class
	FROM fam f
	JOIN stat st ON st.product_id = f.product_id AND st.root = f.root
	WHERE NOT EXISTS (SELECT 1 FROM tracked t WHERE t.product_id = f.product_id AND t.root = f.root)
$$;


--
-- Name: stock_lineage_violations(integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.stock_lineage_violations(p_product integer DEFAULT NULL::integer) RETURNS TABLE(invariant text, subject_id integer, expected double precision, actual double precision)
    LANGUAGE sql STABLE
    AS $$
	SELECT 'I1', s.id, s.amount, COALESCE(sum(rl.amount), 0)
	FROM stock s LEFT JOIN stock_row_lots rl ON rl.stock_row_id = s.id
	WHERE (p_product IS NULL OR s.product_id = p_product)
	GROUP BY s.id, s.amount
	HAVING (s.amount > 0 OR count(rl.id) > 0) AND NOT COALESCE(stock_amounts_equal(s.amount, COALESCE(sum(rl.amount), 0)), false)
	UNION ALL
	SELECT 'I2', l.id, l.amount, sum(bl.amount)
	FROM stock_log l JOIN stock_booking_lots bl ON bl.booking_id = l.id
	WHERE p_product IS NULL OR l.product_id = p_product
	GROUP BY l.id, l.amount
	HAVING NOT COALESCE(stock_amounts_equal(l.amount, sum(bl.amount)), false)
	UNION ALL
	SELECT 'I3', n.lot_id, n.net, COALESCE(h.held, 0)
	FROM (
		SELECT bl.lot_id, sum(stock_log_lineage_delta(l.transaction_type, bl.amount)) AS net
		FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id
		JOIN stock_log lot ON lot.id = bl.lot_id
		WHERE l.undone = 0 AND bl.basis <> 'unknown' AND (p_product IS NULL OR lot.product_id = p_product)
			AND NOT EXISTS (SELECT 1 FROM stock_booking_lots u WHERE u.lot_id = bl.lot_id AND u.basis = 'unknown')
		GROUP BY bl.lot_id
	) n
	LEFT JOIN (
		SELECT rl.lot_id, sum(rl.amount) AS held FROM stock_row_lots rl WHERE rl.lot_id IS NOT NULL GROUP BY rl.lot_id
	) h ON h.lot_id = n.lot_id
	WHERE NOT COALESCE(stock_amounts_equal(n.net, COALESCE(h.held, 0)), false)
$$;


--
-- Name: stock_log_is_addition(text, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.stock_log_is_addition(p_type text, p_amount double precision) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    AS $$
	SELECT p_type IN ('purchase', 'self-production') OR (p_type = 'inventory-correction' AND p_amount > 0)
$$;


--
-- Name: stock_log_lineage_delta(text, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.stock_log_lineage_delta(p_type text, p_amount double precision) RETURNS double precision
    LANGUAGE sql IMMUTABLE
    AS $$
	SELECT CASE p_type
		WHEN 'stock-edit-old' THEN -p_amount
		WHEN 'product-opened' THEN 0
		WHEN 'stock-measured-new' THEN 0
		WHEN 'stock-measured-old' THEN 0
		ELSE p_amount END
$$;


--
-- Name: trg_cascade_battery_removal(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_cascade_battery_removal() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM battery_charge_cycles
	WHERE battery_id = OLD.id;

	-- userfield_values.object_id is TEXT; OLD.id is the (integer) battery id, so it needs
	-- an explicit cast - PostgreSQL has no implicit text/integer comparison.
	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id IN (SELECT id FROM userfields WHERE entity = 'batteries');

	RETURN NULL;
END;
$$;


--
-- Name: trg_cascade_change_qu_id_stock(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_cascade_change_qu_id_stock() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_factor DOUBLE PRECISION;
BEGIN
	-- All amounts anywhere are related to the products stock QU,
	-- so apply the appropriate unit conversion to all amounts everywhere on change
	-- (and enforce that such a conversion need to exist when the product was once added to stock)

	-- (the "AND NEW.qu_id_stock != OLD.qu_id_stock" from the original stock_log subquery is
	-- dropped here - it is guaranteed true already by this trigger's WHEN clause)
	IF NOT EXISTS(
			SELECT 1
			FROM quantity_unit_conversions_resolved
			WHERE product_id = NEW.id
				AND from_qu_id = OLD.qu_id_stock
				AND to_qu_id = NEW.qu_id_stock
		)
		AND EXISTS(
			SELECT 1
			FROM stock_log
			WHERE product_id = NEW.id
		)
	THEN
		RAISE EXCEPTION 'qu_id_stock can only be changed when a corresponding QU conversion (old QU => new QU) exists when the product was once added to stock';
	END IF;

	v_factor := COALESCE((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0);

	-- Issue #868: quantity_unit_conversions_factor_positive refuses such a row, but the resolved
	-- view can also derive a factor, and a non-positive one would flip the sign of every ledger
	-- amount and price below. NaN fails the second comparison.
	IF NOT (v_factor > 0 AND v_factor < 'Infinity'::DOUBLE PRECISION) THEN
		RAISE EXCEPTION 'qu_id_stock cannot be changed: the resolved conversion factor is not a positive, finite number';
	END IF;

	-- Issue #546: refuse before touching anything, mirroring MergeProducts()'s own guard
	-- (services/StockService.php) against the same stock_measurement_coherence_check. A
	-- factor of 1 leaves every amount unchanged and can never violate it, so only a genuine
	-- rescale is refused. `stock` only, not `stock_log` - see this file's header comment
	-- for why checking the ledger here would lock the product's unit permanently.
	IF v_factor != 1.0
		AND EXISTS(SELECT 1 FROM stock WHERE product_id = NEW.id AND opened_amount IS NOT NULL)
	THEN
		RAISE EXCEPTION 'qu_id_stock cannot be changed by a non-1 conversion factor while this product has a measured open container';
	END IF;

	UPDATE chores
	SET product_amount = product_amount * v_factor
	WHERE product_id = NEW.id;

	UPDATE meal_plan
	SET product_amount = product_amount * v_factor
	WHERE type = 'product'
		AND product_id = NEW.id;

	UPDATE recipes_pos
	SET amount = amount * v_factor
	WHERE product_id = NEW.id;

	UPDATE shopping_list
	SET amount = amount * v_factor
	WHERE product_id = NEW.id
		AND product_id IS NOT NULL;

	UPDATE stock
	SET amount = amount * v_factor,
		price = price / v_factor
	WHERE product_id = NEW.id;

	UPDATE stock_log
	SET amount = amount * v_factor,
		price = price / v_factor
	WHERE product_id = NEW.id;

	-- ADR-0036 section 3: contributions and allocations are amounts in the stock unit too, and
	-- I1 to I3 compare them with stock.amount and stock_log.amount, so they rescale by the same
	-- factor in the same transaction.
	UPDATE stock_row_lots rl
	SET amount = rl.amount * v_factor
	FROM stock s
	WHERE s.id = rl.stock_row_id
		AND s.product_id = NEW.id;

	UPDATE stock_booking_lots bl
	SET amount = bl.amount * v_factor
	FROM stock_log l
	WHERE l.id = bl.booking_id
		AND l.product_id = NEW.id;

	-- Issue #849: a consumption mapping with qu_id NULL is expressed in the product's stock
	-- unit, so its quantity_factor rescales like any other stock-unit amount. A mapping with its
	-- own qu_id is converted to the stock unit when an event books, and stays as it is.
	UPDATE consumption_mappings
	SET quantity_factor = quantity_factor * v_factor
	WHERE product_id = NEW.id
		AND qu_id IS NULL;

	-- An event line records abs(stock_log.amount) at booking, in the stock unit of that time.
	UPDATE consumption_event_lines
	SET amount = amount * v_factor
	WHERE product_id = NEW.id;

	-- Issue #543: product_location_min_stock.min_stock_amount (migrations/0276.pgsql.sql)
	-- is stored in the product's stock unit exactly like the amounts above, and belongs in
	-- the same transaction as the rest of this rescale. A different table than the row
	-- this trigger is mid-UPDATE on, so it needs its own UPDATE (unlike products.min_stock_amount
	-- just below).
	UPDATE product_location_min_stock
	SET min_stock_amount = min_stock_amount * v_factor
	WHERE product_id = NEW.id;

	-- Issue #543: products.min_stock_amount is the same class of miss - stored in the
	-- product's own stock unit, never rescaled. This is the row already being updated, so
	-- it is set directly on NEW rather than through a second UPDATE, exactly the way
	-- trg_cascade_change_qu_id_stock2 (migrations/0275.pgsql.sql) already rescales
	-- quick_consume_amount and quick_open_amount on this same row.
	NEW.min_stock_amount := NEW.min_stock_amount * v_factor;

	RETURN NEW;
END;
$$;


--
-- Name: trg_cascade_change_qu_id_stock2(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_cascade_change_qu_id_stock2() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_factor DOUBLE PRECISION;
BEGIN
	v_factor := COALESCE((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0);

	NEW.quick_consume_amount := NEW.quick_consume_amount * v_factor;
	NEW.quick_open_amount := NEW.quick_open_amount * v_factor;
	NEW.calories := NEW.calories / v_factor;

	RETURN NEW;
END;
$$;


--
-- Name: trg_cascade_chore_removal(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_cascade_chore_removal() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM chores_log
	WHERE chore_id = OLD.id;

	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id IN (SELECT id FROM userfields WHERE entity = 'chores');

	RETURN NULL;
END;
$$;


--
-- Name: trg_cascade_product_removal(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_cascade_product_removal() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	r RECORD;
BEGIN
	PERFORM 1 FROM stock WHERE product_id = OLD.id ORDER BY id FOR UPDATE;

	FOR r IN
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
			AND s.product_id = OLD.id
		RETURNING labels.uid
	LOOP
		PERFORM cancel_queued_label_jobs(r.uid);
	END LOOP;

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
$$;


--
-- Name: trg_cascade_userfield_removal(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_cascade_userfield_removal() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id = OLD.id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_consumption_event_transaction_roles(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_event_transaction_roles() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.linked_transaction_id IS NOT NULL THEN
		PERFORM pg_advisory_xact_lock(hashtextextended('consumption_event_transaction:' || NEW.linked_transaction_id, 0));

		IF NEW.transaction_id = NEW.linked_transaction_id THEN
			RAISE EXCEPTION 'A consumption event cannot be linked to its own booking transaction'
				USING ERRCODE = '23505';
		END IF;

		IF EXISTS (SELECT 1 FROM consumption_events o
			WHERE o.transaction_id = NEW.linked_transaction_id AND o.id <> NEW.id
				AND NOT (o.source_system = 'manual' AND o.user_id = NEW.user_id AND o.linked_transaction_id IS NULL)) THEN
			RAISE EXCEPTION 'The transaction is already the booking of another consumption event'
				USING ERRCODE = '23505';
		END IF;
	END IF;

	IF NEW.transaction_id IS NOT NULL THEN
		PERFORM pg_advisory_xact_lock(hashtextextended('consumption_event_transaction:' || NEW.transaction_id, 0));

		IF EXISTS (SELECT 1 FROM consumption_events o
			WHERE o.linked_transaction_id = NEW.transaction_id AND o.id <> NEW.id
				AND NOT (NEW.source_system = 'manual' AND NEW.user_id = o.user_id AND NEW.linked_transaction_id IS NULL)) THEN
			RAISE EXCEPTION 'The transaction is already linked to another consumption event'
				USING ERRCODE = '23505';
		END IF;
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_consumption_owner_not_sharee(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_owner_not_sharee() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS (SELECT 1 FROM consumption_recipe_shares WHERE recipe_id = NEW.id AND user_id = NEW.owner_user_id) THEN
		RAISE EXCEPTION 'The new owner of a consumption recipe must not hold a share on it'
			USING ERRCODE = '23514';
	END IF;
	RETURN NEW;
END;
$$;


--
-- Name: trg_consumption_refill_date_ends_once(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_refill_date_ends_once() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.recipe_id IS DISTINCT FROM OLD.recipe_id
		OR NEW.fill_id IS DISTINCT FROM OLD.fill_id
		OR NEW.reorder_on IS DISTINCT FROM OLD.reorder_on
		OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
		RAISE EXCEPTION 'An explicit reorder date cannot be edited; end it and enter a new one'
			USING ERRCODE = '23514';
	END IF;

	IF OLD.ended_at IS NOT NULL AND (NEW.ended_at IS DISTINCT FROM OLD.ended_at OR NEW.ended_reason IS DISTINCT FROM OLD.ended_reason) THEN
		RAISE EXCEPTION 'An ended explicit reorder date cannot be revived or changed'
			USING ERRCODE = '23514';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_consumption_refill_date_not_before_fill(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_refill_date_not_before_fill() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	fill_date DATE;
	fill_voided TIMESTAMPTZ;
BEGIN
	SELECT filled_on, voided_at INTO fill_date, fill_voided FROM consumption_refill_fills WHERE id = NEW.fill_id;

	IF NEW.reorder_on < fill_date THEN
		RAISE EXCEPTION 'An explicit reorder date cannot be earlier than the fill it was entered for'
			USING ERRCODE = '23514';
	END IF;

	IF NEW.ended_at IS NULL AND fill_voided IS NOT NULL THEN
		RAISE EXCEPTION 'An explicit reorder date cannot be live on a voided fill'
			USING ERRCODE = '23514';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_consumption_refill_fill_immutable(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_refill_fill_immutable() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.recipe_id IS DISTINCT FROM OLD.recipe_id
		OR NEW.filled_on IS DISTINCT FROM OLD.filled_on
		OR NEW.supplied_days IS DISTINCT FROM OLD.supplied_days
		OR NEW.note IS DISTINCT FROM OLD.note
		OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
		RAISE EXCEPTION 'A refill fill is history and cannot be edited; void it and record a new one'
			USING ERRCODE = '23514';
	END IF;

	IF OLD.voided_at IS NOT NULL AND (NEW.voided_at IS DISTINCT FROM OLD.voided_at OR NEW.void_reason IS DISTINCT FROM OLD.void_reason) THEN
		RAISE EXCEPTION 'A voided refill fill cannot be changed or revived'
			USING ERRCODE = '23514';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_consumption_refill_fill_supersedes_dates(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_refill_fill_supersedes_dates() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	UPDATE consumption_refill_dates d
	SET ended_at = now(), ended_reason = 'superseded'
	FROM consumption_refill_fills older
	WHERE d.recipe_id = NEW.recipe_id
		AND d.ended_at IS NULL
		AND older.id = d.fill_id
		AND (older.filled_on, older.id) < (NEW.filled_on, NEW.id);
	RETURN NULL;
END;
$$;


--
-- Name: trg_consumption_refill_order_closed_final(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_refill_order_closed_final() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.recipe_id IS DISTINCT FROM OLD.recipe_id
		OR NEW.ordered_on IS DISTINCT FROM OLD.ordered_on
		OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
		RAISE EXCEPTION 'A refill order cannot be edited; cancel it and record a new one'
			USING ERRCODE = '23514';
	END IF;

	IF OLD.state <> 'open' AND (NEW.state IS DISTINCT FROM OLD.state
		OR NEW.received_fill_id IS DISTINCT FROM OLD.received_fill_id
		OR NEW.closed_at IS DISTINCT FROM OLD.closed_at) THEN
		RAISE EXCEPTION 'A closed refill order cannot be reopened or changed'
			USING ERRCODE = '23514';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_consumption_refill_void_ends_dates(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_refill_void_ends_dates() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	UPDATE consumption_refill_dates
	SET ended_at = now(), ended_reason = 'fill_voided'
	WHERE fill_id = NEW.id AND ended_at IS NULL;
	RETURN NULL;
END;
$$;


--
-- Name: trg_consumption_share_not_owner(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_consumption_share_not_owner() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS (SELECT 1 FROM consumption_recipes WHERE id = NEW.recipe_id AND owner_user_id = NEW.user_id) THEN
		RAISE EXCEPTION 'A share cannot name the owner of the consumption recipe'
			USING ERRCODE = '23514';
	END IF;
	RETURN NEW;
END;
$$;


--
-- Name: trg_create_internal_recipe(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_create_internal_recipe() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Create a recipe per day
	DELETE FROM recipes
	WHERE name = CAST(NEW.day AS TEXT)
		AND type = 'mealplan-day';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT), 'mealplan-day');

	-- Create a recipe per week
	DELETE FROM recipes
	WHERE name = victual_mealplan_week_name(NEW.day)
		AND type = 'mealplan-week';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), victual_mealplan_week_name(NEW.day), 'mealplan-week');

	-- Delete all current nestings entries for the day and week recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day')
		OR recipe_id IN (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week');

	-- Add all recipes for this day as included recipes in the day-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all recipes for this week as included recipes in the week-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all products for this day as ingredients in the day-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Add all products for this week as ingredients in the week-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Create a shadow recipe per meal plan recipe
	INSERT INTO recipes
		(id, name, type)
	SELECT victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT) || '#' || CAST(id AS TEXT), 'mealplan-shadow'
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) || '#' || CAST(meal_plan.id AS TEXT) AND type = 'mealplan-shadow'), recipe_id, recipe_servings
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	-- Enforce "when empty then null" for certain columns
	UPDATE meal_plan
	SET recipe_id = NULL
	WHERE id = NEW.id
		AND recipe_id IS NULL;

	UPDATE meal_plan
	SET product_id = NULL
	WHERE id = NEW.id
		AND product_id IS NULL;

	UPDATE meal_plan
	SET product_qu_id = NULL
	WHERE id = NEW.id
		AND product_qu_id IS NULL;

	RETURN NULL;
END;
$$;


--
-- Name: trg_default_qu_id_consume(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_default_qu_id_consume() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF COALESCE(NEW.qu_id_consume, 0) = 0 THEN
		NEW.qu_id_consume := NEW.qu_id_stock;
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_default_qu_id_price(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_default_qu_id_price() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF COALESCE(NEW.qu_id_price, 0) = 0 THEN
		NEW.qu_id_price := NEW.qu_id_purchase;
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_default_qu_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_default_qu_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF COALESCE(NEW.qu_id, 0) = 0 THEN
		NEW.qu_id := (SELECT qu_id_stock FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_default_qu_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_default_qu_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF COALESCE(NEW.qu_id, 0) = 0 THEN
		NEW.qu_id := (SELECT qu_id_stock FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_default_start_date_when_empty_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_default_start_date_when_empty_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.start_date IS NULL THEN
		NEW.start_date := date_trunc('second', CURRENT_TIMESTAMP);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_default_start_date_when_empty_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_default_start_date_when_empty_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.start_date IS NULL THEN
		NEW.start_date := date_trunc('second', CURRENT_TIMESTAMP);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_enforce_min_stock_amount_for_cumulated_childs_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_enforce_min_stock_amount_for_cumulated_childs_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- When a parent product has cumulate_min_stock_amount_of_sub_products enabled,
	-- the child should not have any min_stock_amount
	UPDATE products
	SET min_stock_amount = 0
	WHERE id IN (
			SELECT p_child.id
			FROM products p_parent
			JOIN products p_child ON p_child.parent_product_id = p_parent.id
			WHERE p_parent.id = NEW.id
				AND COALESCE(p_parent.cumulate_min_stock_amount_of_sub_products, 0) = 1
			)
		AND min_stock_amount > 0;

	RETURN NULL;
END;
$$;


--
-- Name: trg_enforce_min_stock_amount_for_cumulated_childs_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_enforce_min_stock_amount_for_cumulated_childs_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- When a parent product has cumulate_min_stock_amount_of_sub_products enabled,
	-- the child should not have any min_stock_amount
	UPDATE products
	SET min_stock_amount = 0
	WHERE id IN (
			SELECT p_child.id
			FROM products p_parent
			JOIN products p_child ON p_child.parent_product_id = p_parent.id
			WHERE p_parent.id = NEW.id
				AND COALESCE(p_parent.cumulate_min_stock_amount_of_sub_products, 0) = 1
			)
		AND min_stock_amount > 0;

	RETURN NULL;
END;
$$;


--
-- Name: trg_enforce_parent_product_id_null_when_empty_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_enforce_parent_product_id_null_when_empty_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.parent_product_id IS NULL THEN
		NEW.parent_product_id := NULL;
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_enforce_parent_product_id_null_when_empty_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_enforce_parent_product_id_null_when_empty_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.parent_product_id IS NULL THEN
		NEW.parent_product_id := NULL;
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_enfore_product_nesting_level(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_enfore_product_nesting_level() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Currently only 1 level is supported: a product may not both have a parent and be one.
	IF NEW.parent_product_id IS NOT NULL THEN
		-- NEW would arrive as a grandchild: the product it names as its parent is itself
		-- already nested under something else.
		IF EXISTS(
			SELECT 1
			FROM products
			WHERE id = NEW.parent_product_id
				AND parent_product_id IS NOT NULL
		) THEN
			RAISE EXCEPTION 'Unsupported product nesting level detected (currently only 1 level is supported)';
		END IF;

		-- NEW would become a middle node: something already treats NEW itself as its parent.
		IF EXISTS(
			SELECT 1
			FROM products p
			WHERE p.parent_product_id = NEW.id
		) THEN
			RAISE EXCEPTION 'Unsupported product nesting level detected (currently only 1 level is supported)';
		END IF;
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_locations_check_parent(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_locations_check_parent() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	parent_level INTEGER;
	subtree_height INTEGER;
BEGIN
	PERFORM pg_advisory_xact_lock(273, 1);

	IF NEW.parent_location_id IS NULL THEN
		RETURN NEW;
	END IF;

	IF NEW.parent_location_id = NEW.id THEN
		RAISE EXCEPTION 'Recursive nested location detected';
	END IF;

	IF EXISTS (
		SELECT 1
		FROM locations_resolved lr
		WHERE lr.ancestor_location_id = NEW.id
			AND lr.descendant_location_id = NEW.parent_location_id
	) THEN
		RAISE EXCEPTION 'Recursive nested location detected';
	END IF;

	-- NULL when the parent id answers to no row. There is no foreign key here, by design, so
	-- that stays possible; treating the missing parent as a root is the conservative reading
	-- and still bounds the depth.
	SELECT COALESCE(MAX(lr.depth), 0) INTO parent_level
	FROM locations_resolved lr
	WHERE lr.descendant_location_id = NEW.parent_location_id;

	SELECT COALESCE(MAX(lr.depth), 0) INTO subtree_height
	FROM locations_resolved lr
	WHERE lr.ancestor_location_id = NEW.id;

	IF parent_level + 1 + subtree_height > hierarchy_depth_limit() - 1 THEN
		RAISE EXCEPTION 'Location nesting depth limit exceeded';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_locations_guard_children(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_locations_guard_children() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	PERFORM pg_advisory_xact_lock(273, 1);

	IF EXISTS (SELECT 1 FROM locations WHERE parent_location_id = OLD.id) THEN
		RAISE EXCEPTION 'Location has child locations';
	END IF;

	RETURN OLD;
END;
$$;


--
-- Name: trg_prevent_adding_barcodes_for_not_existing_products(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_adding_barcodes_for_not_existing_products() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NOT EXISTS(
		SELECT 1
		FROM products p
		WHERE id = NEW.product_id
	) THEN
		RAISE EXCEPTION 'product_id doesn''t reference a existing product';
	END IF;

	RETURN NULL;
END;
$$;


--
-- Name: trg_prevent_adding_no_own_stock_products_to_stock(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_adding_no_own_stock_products_to_stock() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS(
		SELECT 1
		FROM products p
		WHERE id = NEW.product_id
			AND no_own_stock = 1
	) THEN
		RAISE EXCEPTION 'no_own_stock = 1 products can''t be added to stock';
	END IF;

	RETURN NULL;
END;
$$;


--
-- Name: trg_prevent_empty_userfields_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_empty_userfields_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM userfield_values
	WHERE id = NEW.id
		AND COALESCE(value, '') = '';

	RETURN NULL;
END;
$$;


--
-- Name: trg_prevent_empty_userfields_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_empty_userfields_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM userfield_values
	WHERE id = NEW.id
		AND COALESCE(value, '') = '';

	RETURN NULL;
END;
$$;


--
-- Name: trg_prevent_infinite_nested_recipes_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_infinite_nested_recipes_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings_resolved rnr
		WHERE NEW.recipe_id = rnr.includes_recipe_id
			AND NEW.includes_recipe_id = rnr.recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_prevent_infinite_nested_recipes_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_infinite_nested_recipes_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings_resolved rnr
		WHERE NEW.recipe_id = rnr.includes_recipe_id
			AND NEW.includes_recipe_id = rnr.recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_prevent_internal_meal_plan_section_removal(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_internal_meal_plan_section_removal() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM meal_plan_sections
		WHERE id = OLD.id
			AND id = -1
	) THEN
		RAISE EXCEPTION 'This is an internally used/required default section and therefore can''t be deleted';
	END IF;

	RETURN OLD;
END;
$$;


--
-- Name: trg_prevent_self_nested_recipes_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_self_nested_recipes_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings
		WHERE NEW.recipe_id = NEW.includes_recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_prevent_self_nested_recipes_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_prevent_self_nested_recipes_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings
		WHERE NEW.recipe_id = NEW.includes_recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_product_groups_check_parent(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_product_groups_check_parent() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	parent_level INTEGER;
	subtree_height INTEGER;
BEGIN
	PERFORM pg_advisory_xact_lock(278, 1);

	IF NEW.parent_product_group_id IS NULL THEN
		RETURN NEW;
	END IF;

	IF NEW.parent_product_group_id = NEW.id THEN
		RAISE EXCEPTION 'Recursive nested product group detected';
	END IF;

	IF EXISTS (
		SELECT 1
		FROM product_groups_resolved pgr
		WHERE pgr.ancestor_product_group_id = NEW.id
			AND pgr.descendant_product_group_id = NEW.parent_product_group_id
	) THEN
		RAISE EXCEPTION 'Recursive nested product group detected';
	END IF;

	SELECT COALESCE(MAX(pgr.depth), 0) INTO parent_level
	FROM product_groups_resolved pgr
	WHERE pgr.descendant_product_group_id = NEW.parent_product_group_id;

	SELECT COALESCE(MAX(pgr.depth), 0) INTO subtree_height
	FROM product_groups_resolved pgr
	WHERE pgr.ancestor_product_group_id = NEW.id;

	IF parent_level + 1 + subtree_height > hierarchy_depth_limit() - 1 THEN
		RAISE EXCEPTION 'Product group nesting depth limit exceeded';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_product_groups_guard_children(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_product_groups_guard_children() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	PERFORM pg_advisory_xact_lock(278, 1);

	IF EXISTS (SELECT 1 FROM product_groups WHERE parent_product_group_id = OLD.id) THEN
		RAISE EXCEPTION 'Product group has child groups';
	END IF;

	RETURN OLD;
END;
$$;


--
-- Name: trg_products_default_qu_conversions_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_products_default_qu_conversions_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Create product specific 1:1 conversions when QU stock != QU purchase/consume/price
	-- and when no default QU conversion apply

	-- with qu_id_stock != qu_id_purchase
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_purchase, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_purchase
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_purchase);

	-- with qu_id_stock != qu_id_consume
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_consume, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_consume
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_consume);

	-- with qu_id_stock != qu_id_price
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_price, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_price
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_price);

	RETURN NULL;
END;
$$;


--
-- Name: trg_products_default_qu_conversions_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_products_default_qu_conversions_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Create product specific 1:1 conversions when QU stock != QU purchase/consume/price
	-- and when no default QU conversion apply

	-- with qu_id_stock != qu_id_purchase
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_purchase, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_purchase
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_purchase);

	-- with qu_id_stock != qu_id_consume
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_consume, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_consume
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_consume);

	-- with qu_id_stock != qu_id_price
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_price, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_price
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_price);

	RETURN NULL;
END;
$$;


--
-- Name: trg_products_delete(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_products_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE product_id = OLD.id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_products_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_products_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_products_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_products_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_qu_conversions_custom_constraint_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_qu_conversions_custom_constraint_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Necessary because unique constraints don't include NULL values in SQLite
	IF EXISTS(
		SELECT 1
		FROM quantity_unit_conversions
		WHERE from_qu_id = NEW.from_qu_id
			AND to_qu_id = NEW.to_qu_id
			AND COALESCE(product_id, 0) = COALESCE(NEW.product_id, 0)
	) THEN
		RAISE EXCEPTION 'QU conversion already exists';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_qu_conversions_custom_constraint_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_qu_conversions_custom_constraint_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- This contains practically the same logic as trg_qu_conversions_custom_constraint_INS.
	-- Necessary because unique constraints don't include NULL values in SQLite
	IF EXISTS(
		SELECT 1
		FROM quantity_unit_conversions
		WHERE from_qu_id = NEW.from_qu_id
			AND to_qu_id = NEW.to_qu_id
			AND COALESCE(product_id, 0) = COALESCE(NEW.product_id, 0)
			AND id != NEW.id
	) THEN
		RAISE EXCEPTION 'QU conversion already exists';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_quantity_unit_conversions_del(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_quantity_unit_conversions_del() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF current_setting('victual.in_quc_del', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('victual.in_quc_del', '1', true);

	-- Delete the inverse QU conversion
	DELETE FROM quantity_unit_conversions
	WHERE from_qu_id = OLD.to_qu_id
		AND to_qu_id = OLD.from_qu_id
		AND COALESCE(product_id, -1) = COALESCE(OLD.product_id, -1);

	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || OLD.to_qu_id::text || '/%'
		OR path LIKE '%/' || OLD.from_qu_id::text || '/%';

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || OLD.to_qu_id::text || '/%'
		OR path LIKE '%/' || OLD.from_qu_id::text || '/%';

	PERFORM set_config('victual.in_quc_del', '0', true);
	RETURN NULL;
END;
$$;


--
-- Name: trg_quantity_unit_conversions_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_quantity_unit_conversions_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF current_setting('victual.in_quc_ins', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('victual.in_quc_ins', '1', true);

	-- Create the inverse QU conversion (see note above: plain INSERT is equivalent to the
	-- original "INSERT OR REPLACE", there being no real unique constraint to trigger on)
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	VALUES
		(NEW.to_qu_id, NEW.from_qu_id, 1 / COALESCE(NEW.factor, 1), NEW.product_id);

	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	PERFORM set_config('victual.in_quc_ins', '0', true);
	RETURN NULL;
END;
$$;


--
-- Name: trg_quantity_unit_conversions_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_quantity_unit_conversions_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF current_setting('victual.in_quc_upd', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('victual.in_quc_upd', '1', true);

	-- Update the inverse QU conversion
	UPDATE quantity_unit_conversions
	SET factor = 1 / COALESCE(NEW.factor, 1),
		from_qu_id = NEW.to_qu_id,
		to_qu_id = NEW.from_qu_id
	WHERE from_qu_id = OLD.to_qu_id
		AND to_qu_id = OLD.from_qu_id
		AND COALESCE(product_id, -1) = COALESCE(NEW.product_id, -1);

	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	PERFORM set_config('victual.in_quc_upd', '0', true);
	RETURN NULL;
END;
$$;


--
-- Name: trg_recipes_desired_servings_default(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_recipes_desired_servings_default() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	NEW.desired_servings := NEW.base_servings;

	RETURN NEW;
END;
$$;


--
-- Name: trg_recipes_pos_qu_id_default(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_recipes_pos_qu_id_default() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.qu_id IS NULL THEN
		NEW.qu_id := (SELECT qu_id_stock FROM products WHERE id = NEW.product_id);
	END IF;

	IF NOT EXISTS (
		SELECT 1
		FROM quantity_unit_conversions_resolved qucr
		WHERE qucr.product_id = NEW.product_id
			AND qucr.to_qu_id = NEW.qu_id
	) AND COALESCE(NEW.only_check_single_unit_in_stock, 0) <> 1 THEN
		RAISE EXCEPTION 'Provided qu_id doesn''t have a related conversion for that product';
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_remove_conversions(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_remove_conversions() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM quantity_unit_conversions
	WHERE from_qu_id = OLD.id
		OR to_qu_id = OLD.id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_remove_internal_recipe(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_remove_internal_recipe() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Create a recipe per day
	DELETE FROM recipes
	WHERE name = CAST(OLD.day AS TEXT)
		AND type = 'mealplan-day';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), CAST(OLD.day AS TEXT), 'mealplan-day');

	-- Create a recipe per week
	DELETE FROM recipes
	WHERE name = victual_mealplan_week_name(OLD.day)
		AND type = 'mealplan-week';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), victual_mealplan_week_name(OLD.day), 'mealplan-week');

	-- Delete all current nestings entries for the day and week recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (SELECT id FROM recipes WHERE name = CAST(OLD.day AS TEXT) AND type = 'mealplan-day')
		OR recipe_id IN (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(OLD.day) AND type = 'mealplan-week');

	-- Add all recipes for this day as included recipes in the day-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(OLD.day AS TEXT) AND type = 'mealplan-day'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE day = OLD.day
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all recipes for this week as included recipes in the week-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(OLD.day) AND type = 'mealplan-week'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(OLD.day)
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all products for this day as ingredients in the day-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = CAST(OLD.day AS TEXT) AND type = 'mealplan-day'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE day = OLD.day
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Add all products for this week as ingredients in the week-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(OLD.day) AND type = 'mealplan-week'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(OLD.day)
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Remove shadow recipes per meal plan recipe
	DELETE FROM recipes
	WHERE type = 'mealplan-shadow'
		AND name NOT IN (SELECT CAST(day AS TEXT) || '#' || CAST(id AS TEXT) FROM meal_plan WHERE type = 'recipe');

	RETURN NULL;
END;
$$;


--
-- Name: trg_remove_items_from_deleted_shopping_list(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_remove_items_from_deleted_shopping_list() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM shopping_list WHERE shopping_list_id = OLD.id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_remove_recipe_from_meal_plans(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_remove_recipe_from_meal_plans() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM meal_plan
	WHERE recipe_id = OLD.id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_set_products_default_location_if_empty_stock(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_set_products_default_location_if_empty_stock() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.location_id IS NULL THEN
		NEW.location_id := (SELECT location_id FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_set_products_default_location_if_empty_stock_log(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_set_products_default_location_if_empty_stock_log() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.location_id IS NULL THEN
		NEW.location_id := (SELECT location_id FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_shopping_list_defaults_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_shopping_list_defaults_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.qu_id IS NULL THEN
		NEW.qu_id := (SELECT qu_id_purchase FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_shopping_list_defaults_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_shopping_list_defaults_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	IF NEW.qu_id IS NULL THEN
		NEW.qu_id := (SELECT qu_id_purchase FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$;


--
-- Name: trg_stock_log_del(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_stock_log_del() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_product_id INTEGER;
BEGIN
	FOR v_product_id IN
		SELECT DISTINCT o.product_id FROM stock_log_old_rows o ORDER BY 1
	LOOP
		PERFORM rebuild_stock_log_cache_for_product(v_product_id);
	END LOOP;

	RETURN NULL;
END;
$$;


--
-- Name: trg_stock_log_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_stock_log_ins() RETURNS trigger
    LANGUAGE plpgsql
    SET jit TO 'off'
    AS $$
BEGIN
	-- Update products_average_price cache
	INSERT INTO cache__products_average_price
		(product_id, price)
	SELECT product_id, price
	FROM products_average_price
	WHERE product_id = NEW.product_id
	ON CONFLICT (product_id) DO UPDATE SET
		price = EXCLUDED.price;

	-- Update products_last_purchased cache
	INSERT INTO cache__products_last_purchased
		(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
	SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
	FROM products_last_purchased
	WHERE product_id = NEW.product_id
	ON CONFLICT (product_id) DO UPDATE SET
		amount = EXCLUDED.amount,
		best_before_date = EXCLUDED.best_before_date,
		purchased_date = EXCLUDED.purchased_date,
		price = EXCLUDED.price,
		location_id = EXCLUDED.location_id,
		shopping_location_id = EXCLUDED.shopping_location_id;

	RETURN NULL;
END;
$$;


--
-- Name: trg_stock_log_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_stock_log_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
	v_product_id INTEGER;
BEGIN
	FOR v_product_id IN
		SELECT n.product_id FROM stock_log_new_rows n
		UNION
		SELECT o.product_id FROM stock_log_old_rows o
		ORDER BY 1
	LOOP
		PERFORM rebuild_stock_log_cache_for_product(v_product_id);
	END LOOP;

	RETURN NULL;
END;
$$;


--
-- Name: trg_stock_next_use_del(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_stock_next_use_del() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	DELETE FROM stock
	WHERE id = OLD.id;

	RETURN OLD;
END;
$$;


--
-- Name: trg_stock_next_use_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_stock_next_use_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: trg_stock_next_use_upd(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_stock_next_use_upd() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


--
-- Name: trg_update_internal_recipe(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_update_internal_recipe() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Recursion guard - see the file header's "Recursion note". Without this, the tail
	-- "enforce empty then null" UPDATE below would re-fire this same trigger on the row it
	-- just updated, which would re-run the tail UPDATE again, forever (its WHERE clause
	-- keeps matching once a column is NULL).
	IF pg_trigger_depth() > 1 THEN
		RETURN NULL;
	END IF;

	-- Create a recipe per day
	DELETE FROM recipes
	WHERE name = CAST(NEW.day AS TEXT)
		AND type = 'mealplan-day';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT), 'mealplan-day');

	-- Create a recipe per week
	DELETE FROM recipes
	WHERE name = victual_mealplan_week_name(NEW.day)
		AND type = 'mealplan-week';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), victual_mealplan_week_name(NEW.day), 'mealplan-week');

	-- Delete all current nestings entries for the day and week recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day')
		OR recipe_id IN (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week');

	-- Add all recipes for this day as included recipes in the day-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all recipes for this week as included recipes in the week-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all products for this day as ingredients in the day-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Add all products for this week as ingredients in the week-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Create a shadow recipe per meal plan recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (
		SELECT id FROM recipes
		WHERE name IN (
			SELECT CAST(NEW.day AS TEXT) || '#' || CAST(NEW.id AS TEXT)
			FROM meal_plan
			WHERE day = NEW.day
		)
		AND type = 'mealplan-shadow'
	);

	DELETE FROM recipes
	WHERE type = 'mealplan-shadow'
		AND name = CAST(NEW.day AS TEXT) || '#' || CAST(NEW.id AS TEXT);

	INSERT INTO recipes
		(id, name, type)
	SELECT victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT) || '#' || CAST(id AS TEXT), 'mealplan-shadow'
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) || '#' || CAST(meal_plan.id AS TEXT) AND type = 'mealplan-shadow'), recipe_id, recipe_servings
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	-- Enforce "when empty then null" for certain columns
	UPDATE meal_plan
	SET recipe_id = NULL
	WHERE id = NEW.id
		AND recipe_id IS NULL;

	UPDATE meal_plan
	SET product_id = NULL
	WHERE id = NEW.id
		AND product_id IS NULL;

	UPDATE meal_plan
	SET product_qu_id = NULL
	WHERE id = NEW.id
		AND product_qu_id IS NULL;

	RETURN NULL;
END;
$$;


--
-- Name: trg_userfield_values_special_handling_ins(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_userfield_values_special_handling_ins() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
	-- Recursion guard - see the file header's "Recursion note". Without this, the INSERT
	-- below would re-fire this same trigger on the row it just inserted, and that nested
	-- invocation's unconditional DELETE would immediately remove the row again.
	IF pg_trigger_depth() > 1 THEN
		RETURN NULL;
	END IF;

	-- Entity stock:
	-- object_id is the transaction_id on insert -> replace it by the corresponding stock_id
	INSERT INTO userfield_values
		(field_id, object_id, value)
	SELECT uv.field_id, sl.stock_id, uv.value
	FROM userfield_values uv
	JOIN stock_log sl
		ON uv.object_id = sl.transaction_id
		AND sl.transaction_type IN ('purchase', 'inventory-correction', 'stock-edit-new')
	WHERE uv.field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
		AND uv.field_id = NEW.field_id
		AND uv.object_id = NEW.object_id
	ON CONFLICT (field_id, object_id) DO UPDATE SET value = EXCLUDED.value;

	DELETE FROM userfield_values
	WHERE field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
		AND field_id = NEW.field_id
		AND object_id = NEW.object_id;

	RETURN NULL;
END;
$$;


--
-- Name: victual_local_to_instant(timestamp without time zone, text, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.victual_local_to_instant(wall timestamp without time zone, zone text, strict boolean) RETURNS timestamp with time zone
    LANGUAGE plpgsql STABLE PARALLEL SAFE
    AS $$
DECLARE
	naive timestamptz := wall AT TIME ZONE 'UTC';
	off_before interval;
	off interval;
	candidate timestamptz;
	best timestamptz := NULL;
BEGIN
	IF wall IS NULL THEN
		RETURN NULL;
	END IF;
	IF NOT isfinite(wall) THEN
		RAISE EXCEPTION 'victual_local_to_instant: % is not a finite wall clock', wall;
	END IF;

	off_before := ((naive - interval '26 hours') AT TIME ZONE zone) - ((naive - interval '26 hours') AT TIME ZONE 'UTC');

	FOREACH off IN ARRAY ARRAY[
		off_before,
		(naive AT TIME ZONE zone) - (naive AT TIME ZONE 'UTC'),
		((naive + interval '26 hours') AT TIME ZONE zone) - ((naive + interval '26 hours') AT TIME ZONE 'UTC')
	]
	LOOP
		candidate := naive - off;
		IF (candidate AT TIME ZONE zone) = wall AND (best IS NULL OR candidate < best) THEN
			best := candidate;
		END IF;
	END LOOP;

	IF best IS NOT NULL OR strict THEN
		RETURN best;
	END IF;

	RETURN naive - off_before;
END
$$;


--
-- Name: victual_mealplan_week_name(date); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.victual_mealplan_week_name(d date) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$
	SELECT LTRIM(EXTRACT(YEAR FROM d)::integer::text || '-' || LPAD(victual_sqlite_percent_w(d)::text, 2, '0'), '0');
$$;


--
-- Name: victual_next_internal_recipe_id(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.victual_next_internal_recipe_id() RETURNS integer
    LANGUAGE sql
    AS $$
	SELECT COALESCE(
		(SELECT MIN(id) - 1 FROM recipes),
		nextval(pg_get_serial_sequence('recipes', 'id'))::integer
	);
$$;


--
-- Name: victual_sqlite_percent_w(date); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.victual_sqlite_percent_w(d date) RETURNS integer
    LANGUAGE sql IMMUTABLE
    AS $$
	SELECT CASE
		WHEN d < fm.first_monday THEN 0
		ELSE (d - fm.first_monday) / 7 + 1
	END
	FROM (
		SELECT jan1 + ((8 - EXTRACT(ISODOW FROM jan1)::integer) % 7) AS first_monday
		FROM (SELECT make_date(EXTRACT(YEAR FROM d)::integer, 1, 1) AS jan1) j
	) fm;
$$;


--
-- Name: victual_user_setting(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.victual_user_setting(setting_key text) RETURNS text
    LANGUAGE sql STABLE
    AS $$
	SELECT COALESCE(
		(
			SELECT us.value
			FROM user_settings us
			WHERE us.user_id = COALESCE(NULLIF(current_setting('victual.user_id', true), '')::INTEGER, 1)
				AND us.key = setting_key
		),
		(
			SELECT usd.value
			FROM user_settings_defaults usd
			WHERE usd.key = setting_key
		)
	);
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: api_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_keys (
    id integer NOT NULL,
    api_key text NOT NULL,
    user_id integer NOT NULL,
    expires timestamp with time zone,
    last_used timestamp with time zone,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    key_type text DEFAULT 'default'::text NOT NULL,
    description text,
    key_hint text,
    rotated_from_id integer,
    read_only smallint DEFAULT 0 NOT NULL,
    CONSTRAINT api_keys_read_only_check CHECK ((read_only = ANY (ARRAY[0, 1])))
);


--
-- Name: api_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.api_keys ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.api_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: batteries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.batteries (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    used_in text,
    charge_interval_days integer DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    active smallint DEFAULT 1 NOT NULL,
    import_epoch bigint DEFAULT public.label_current_import_epoch() NOT NULL,
    CONSTRAINT batteries_active_check CHECK ((active = ANY (ARRAY[0, 1])))
);


--
-- Name: battery_charge_cycles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.battery_charge_cycles (
    id integer NOT NULL,
    battery_id integer NOT NULL,
    tracked_time timestamp with time zone,
    undone smallint DEFAULT 0 NOT NULL,
    undone_timestamp timestamp with time zone,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    CONSTRAINT battery_charge_cycles_undone_check CHECK ((undone = ANY (ARRAY[0, 1])))
);


--
-- Name: batteries_current; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.batteries_current AS
 SELECT b.id,
    b.id AS battery_id,
    max(l.tracked_time) AS last_tracked_time,
        CASE
            WHEN (b.charge_interval_days = 0) THEN '2999-12-31 23:59:59+00'::timestamp with time zone
            ELSE (max(l.tracked_time) + (((b.charge_interval_days)::text || ' day'::text))::interval)
        END AS next_estimated_charge_time
   FROM (public.batteries b
     LEFT JOIN public.battery_charge_cycles l ON (((b.id = l.battery_id) AND (l.undone = 0))))
  WHERE (b.active = 1)
  GROUP BY b.id, b.charge_interval_days;


--
-- Name: batteries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.batteries ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.batteries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: battery_charge_cycles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.battery_charge_cycles ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.battery_charge_cycles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: cache__products_average_price; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache__products_average_price (
    id integer NOT NULL,
    product_id integer,
    price double precision
);


--
-- Name: cache__products_average_price_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.cache__products_average_price ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.cache__products_average_price_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: cache__products_last_purchased; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache__products_last_purchased (
    id integer NOT NULL,
    product_id integer,
    amount double precision,
    best_before_date date,
    purchased_date date,
    price double precision,
    location_id integer,
    shopping_location_id integer
);


--
-- Name: cache__products_last_purchased_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.cache__products_last_purchased ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.cache__products_last_purchased_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: cache__quantity_unit_conversions_resolved; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache__quantity_unit_conversions_resolved (
    id integer NOT NULL,
    product_id integer,
    from_qu_id integer,
    from_qu_name text,
    from_qu_name_plural text,
    to_qu_id integer,
    to_qu_name text,
    to_qu_name_plural text,
    factor text,
    path text
);


--
-- Name: cache__quantity_unit_conversions_resolved_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.cache__quantity_unit_conversions_resolved ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.cache__quantity_unit_conversions_resolved_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: chores; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.chores (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    period_type text NOT NULL,
    period_days integer,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    period_config text,
    track_date_only smallint DEFAULT 0,
    rollover smallint DEFAULT 0,
    assignment_type text,
    assignment_config text,
    next_execution_assigned_to_user_id integer,
    consume_product_on_execution smallint DEFAULT 0 NOT NULL,
    product_id integer,
    product_amount double precision,
    period_interval integer DEFAULT 1 NOT NULL,
    active smallint DEFAULT 1 NOT NULL,
    start_date timestamp with time zone,
    rescheduled_date timestamp with time zone,
    rescheduled_next_execution_assigned_to_user_id integer,
    import_epoch bigint DEFAULT public.label_current_import_epoch() NOT NULL,
    CONSTRAINT chores_active_check CHECK ((active = ANY (ARRAY[0, 1]))),
    CONSTRAINT chores_consume_product_on_execution_check CHECK ((consume_product_on_execution = ANY (ARRAY[0, 1]))),
    CONSTRAINT chores_period_interval_check CHECK ((period_interval > 0))
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id integer NOT NULL,
    username text NOT NULL,
    first_name text,
    last_name text,
    password text NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    picture_file_name text,
    must_change_password smallint DEFAULT 0 NOT NULL,
    CONSTRAINT users_must_change_password_check CHECK ((must_change_password = ANY (ARRAY[0, 1])))
);


--
-- Name: chores_assigned_users_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.chores_assigned_users_resolved AS
 SELECT c.id AS chore_id,
    u.id AS user_id
   FROM (public.chores c
     JOIN public.users u ON ((((','::text || c.assignment_config) || ','::text) ~~ (('%,'::text || (u.id)::text) || ',%'::text))))
  WHERE (c.active = 1);


--
-- Name: chores_current; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.chores_current AS
SELECT
    NULL::integer AS id,
    NULL::integer AS chore_id,
    NULL::text AS chore_name,
    NULL::timestamp with time zone AS last_tracked_time,
    NULL::timestamp with time zone AS next_estimated_execution_time,
    NULL::smallint AS track_date_only,
    NULL::integer AS next_execution_assigned_to_user_id,
    NULL::integer AS is_rescheduled,
    NULL::integer AS is_reassigned;


--
-- Name: chores_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.chores_log (
    id integer NOT NULL,
    chore_id integer NOT NULL,
    tracked_time timestamp with time zone,
    done_by_user_id integer,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    undone smallint DEFAULT 0 NOT NULL,
    undone_timestamp timestamp with time zone,
    skipped smallint DEFAULT 0 NOT NULL,
    scheduled_execution_time timestamp with time zone,
    stock_transaction_id text,
    prior_rescheduled_date timestamp with time zone,
    prior_rescheduled_next_execution_assigned_to_user_id integer,
    CONSTRAINT chores_log_skipped_check CHECK ((skipped = ANY (ARRAY[0, 1]))),
    CONSTRAINT chores_log_undone_check CHECK ((undone = ANY (ARRAY[0, 1])))
);


--
-- Name: chores_execution_timeline; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.chores_execution_timeline AS
 SELECT chore_id,
    tracked_time,
    ( SELECT chores_log.tracked_time
           FROM public.chores_log
          WHERE ((chores_log.chore_id = cl.chore_id) AND (chores_log.undone = 0) AND (chores_log.tracked_time < cl.tracked_time))
          ORDER BY chores_log.tracked_time DESC
         LIMIT 1) AS tracked_time_before,
    (trunc((EXTRACT(epoch FROM ((tracked_time)::timestamp without time zone - (( SELECT chores_log.tracked_time
           FROM public.chores_log
          WHERE ((chores_log.chore_id = cl.chore_id) AND (chores_log.undone = 0) AND (chores_log.tracked_time < cl.tracked_time))
          ORDER BY chores_log.tracked_time DESC
         LIMIT 1))::timestamp without time zone)) / 3600.0)))::integer AS frequency_hours
   FROM public.chores_log cl
  WHERE (undone = 0);


--
-- Name: chores_execution_average_frequency; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.chores_execution_average_frequency AS
 SELECT chore_id,
    (avg(frequency_hours))::double precision AS average_frequency_hours
   FROM public.chores_execution_timeline cet
  GROUP BY chore_id;


--
-- Name: chores_execution_users_statistics; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.chores_execution_users_statistics AS
 SELECT c.id,
    c.id AS chore_id,
    caur.user_id,
    (( SELECT count(1) AS count
           FROM public.chores_log
          WHERE ((chores_log.chore_id = c.id) AND (chores_log.done_by_user_id = caur.user_id) AND (chores_log.undone = 0))))::integer AS execution_count
   FROM (public.chores c
     JOIN public.chores_assigned_users_resolved caur ON ((c.id = caur.chore_id)))
  GROUP BY c.id, caur.user_id;


--
-- Name: chores_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.chores ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.chores_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: chores_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.chores_log ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.chores_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_event_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_event_lines (
    id integer NOT NULL,
    event_id integer NOT NULL,
    product_id integer NOT NULL,
    amount double precision NOT NULL,
    location_id integer,
    stock_log_id integer,
    used_date date,
    CONSTRAINT consumption_event_lines_amount_positive CHECK (((amount > (0)::double precision) AND (amount < 'Infinity'::double precision)))
);


--
-- Name: consumption_event_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_event_lines ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_event_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_events (
    id integer NOT NULL,
    user_id integer NOT NULL,
    source_system text NOT NULL,
    source_event_id text NOT NULL,
    recipe_id integer,
    state text NOT NULL,
    reason text,
    transaction_id text,
    revision integer DEFAULT 1 NOT NULL,
    payload_hash text,
    occurred_at timestamp with time zone NOT NULL,
    received_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    mapping_id integer,
    medication_ref text,
    submitted_status text,
    quantity double precision,
    unit_label text,
    requested_location_id integer,
    source_updated_at timestamp with time zone,
    occurred_date date,
    api_key_id integer,
    replaces_event_id integer,
    voided_at timestamp with time zone,
    source_removed_at timestamp with time zone,
    source_removed_reason text,
    candidate_location_ids integer[],
    stock_error_message text,
    linked_transaction_id text,
    CONSTRAINT consumption_events_booked_has_transaction CHECK (((state <> 'booked'::text) OR (transaction_id IS NOT NULL))),
    CONSTRAINT consumption_events_linked_pair CHECK (((state = 'linked'::text) = (linked_transaction_id IS NOT NULL))),
    CONSTRAINT consumption_events_manual_has_no_source_fields CHECK (((source_system <> 'manual'::text) OR ((mapping_id IS NULL) AND (medication_ref IS NULL) AND (submitted_status IS NULL) AND (source_updated_at IS NULL) AND (replaces_event_id IS NULL) AND (api_key_id IS NULL)))),
    CONSTRAINT consumption_events_medication_ref_form CHECK (((medication_ref IS NULL) OR (medication_ref ~ '^[A-Za-z0-9._:-]{1,128}$'::text))),
    CONSTRAINT consumption_events_not_self_replacing CHECK (((replaces_event_id IS NULL) OR (replaces_event_id <> id))),
    CONSTRAINT consumption_events_quantity_positive CHECK (((quantity IS NULL) OR ((quantity > (0)::double precision) AND (quantity < 'Infinity'::double precision)))),
    CONSTRAINT consumption_events_reason_known CHECK (((reason IS NULL) OR (reason = ANY (ARRAY['ambiguous_location'::text, 'insufficient_stock'::text, 'unit_unconfirmed'::text, 'quantity_missing'::text, 'recipe_unavailable'::text, 'partially_undone'::text, 'changed_after_undo'::text, 'undo_refused'::text, 'invalid_mapping'::text, 'source_deleted'::text, 'stock_error'::text])))),
    CONSTRAINT consumption_events_reason_only_in_review CHECK (((reason IS NULL) = (state <> 'needs_review'::text))),
    CONSTRAINT consumption_events_removed_pair CHECK (((source_removed_at IS NULL) = (source_removed_reason IS NULL))),
    CONSTRAINT consumption_events_removed_reason_known CHECK (((source_removed_reason IS NULL) OR (source_removed_reason = ANY (ARRAY['entered_in_error'::text, 'history_cleared'::text, 'medication_archived'::text, 'access_revoked'::text, 'unknown'::text])))),
    CONSTRAINT consumption_events_revision_positive CHECK ((revision > 0)),
    CONSTRAINT consumption_events_source_event_id_form CHECK ((source_event_id ~ '^[A-Za-z0-9._:-]{1,128}$'::text)),
    CONSTRAINT consumption_events_source_system_form CHECK ((source_system ~ '^[a-z0-9][a-z0-9._-]{0,31}$'::text)),
    CONSTRAINT consumption_events_state_known CHECK ((state = ANY (ARRAY['received'::text, 'needs_mapping'::text, 'needs_review'::text, 'booked'::text, 'undone'::text, 'voided'::text, 'linked'::text, 'dismissed'::text]))),
    CONSTRAINT consumption_events_submitted_status_known CHECK (((submitted_status IS NULL) OR (submitted_status = ANY (ARRAY['taken'::text, 'not_logged'::text, 'skipped'::text, 'unanswered'::text, 'scheduled'::text])))),
    CONSTRAINT consumption_events_voided_has_time CHECK (((state <> 'voided'::text) OR (voided_at IS NOT NULL)))
);


--
-- Name: consumption_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_events ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_mappings (
    id integer NOT NULL,
    user_id integer NOT NULL,
    source_system text NOT NULL,
    medication_ref text NOT NULL,
    target_type text NOT NULL,
    recipe_id integer,
    product_id integer,
    qu_id integer,
    quantity_factor double precision DEFAULT 1 NOT NULL,
    unit_labels text[] DEFAULT '{}'::text[] NOT NULL,
    default_quantity double precision,
    location_mode text NOT NULL,
    location_id integer,
    effective_from timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT consumption_mappings_default_quantity_positive CHECK (((default_quantity IS NULL) OR ((default_quantity > (0)::double precision) AND (default_quantity < 'Infinity'::double precision)))),
    CONSTRAINT consumption_mappings_factor_positive CHECK (((quantity_factor > (0)::double precision) AND (quantity_factor < 'Infinity'::double precision))),
    CONSTRAINT consumption_mappings_fixed_names_location CHECK (((location_mode = 'fixed'::text) = (location_id IS NOT NULL))),
    CONSTRAINT consumption_mappings_location_mode_known CHECK ((location_mode = ANY (ARRAY['fixed'::text, 'single'::text, 'explicit'::text]))),
    CONSTRAINT consumption_mappings_manual_reserved CHECK ((source_system <> 'manual'::text)),
    CONSTRAINT consumption_mappings_medication_ref_form CHECK ((medication_ref ~ '^[A-Za-z0-9._:-]{1,128}$'::text)),
    CONSTRAINT consumption_mappings_source_system_form CHECK ((source_system ~ '^[a-z0-9][a-z0-9._-]{0,31}$'::text)),
    CONSTRAINT consumption_mappings_target_known CHECK ((target_type = ANY (ARRAY['recipe'::text, 'product'::text]))),
    CONSTRAINT consumption_mappings_target_shape CHECK ((((target_type = 'recipe'::text) AND (product_id IS NULL) AND (qu_id IS NULL)) OR ((target_type = 'product'::text) AND (product_id IS NOT NULL) AND (recipe_id IS NULL)))),
    CONSTRAINT consumption_mappings_unit_labels_clean CHECK (((array_position(unit_labels, ''::text) IS NULL) AND (array_position(unit_labels, NULL::text) IS NULL) AND (cardinality(unit_labels) <= 50)))
);


--
-- Name: consumption_mappings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_mappings ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_mappings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_recipe_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_recipe_lines (
    id integer NOT NULL,
    recipe_id integer NOT NULL,
    "position" integer NOT NULL,
    product_id integer NOT NULL,
    amount double precision NOT NULL,
    qu_id integer NOT NULL,
    CONSTRAINT consumption_recipe_lines_amount_positive CHECK (((amount > (0)::double precision) AND (amount < 'Infinity'::double precision))),
    CONSTRAINT consumption_recipe_lines_position_positive CHECK (("position" > 0))
);


--
-- Name: consumption_recipe_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_recipe_lines ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_recipe_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_recipe_shares; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_recipe_shares (
    recipe_id integer NOT NULL,
    user_id integer NOT NULL,
    can_consume smallint DEFAULT 0 NOT NULL,
    can_edit smallint DEFAULT 0 NOT NULL,
    can_undo smallint DEFAULT 0 NOT NULL,
    can_share smallint DEFAULT 0 NOT NULL,
    granted_by_user_id integer,
    granted_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT consumption_recipe_shares_can_consume_check CHECK ((can_consume = ANY (ARRAY[0, 1]))),
    CONSTRAINT consumption_recipe_shares_can_edit_check CHECK ((can_edit = ANY (ARRAY[0, 1]))),
    CONSTRAINT consumption_recipe_shares_can_share_check CHECK ((can_share = ANY (ARRAY[0, 1]))),
    CONSTRAINT consumption_recipe_shares_can_undo_check CHECK ((can_undo = ANY (ARRAY[0, 1])))
);


--
-- Name: consumption_recipes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_recipes (
    id integer NOT NULL,
    owner_user_id integer NOT NULL,
    name text NOT NULL,
    note text,
    row_created_timestamp timestamp with time zone DEFAULT now() NOT NULL,
    row_updated_timestamp timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT consumption_recipes_name_not_blank CHECK ((length(btrim(name)) > 0))
);


--
-- Name: consumption_recipes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_recipes ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_recipes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_refill_acks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_refill_acks (
    user_id integer NOT NULL,
    recipe_id integer NOT NULL,
    kind text NOT NULL,
    reorder_date date NOT NULL,
    acknowledged_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT consumption_refill_acks_kind_known CHECK ((kind = ANY (ARRAY['approaching'::text, 'due'::text])))
);


--
-- Name: consumption_refill_dates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_refill_dates (
    id integer NOT NULL,
    recipe_id integer NOT NULL,
    fill_id integer NOT NULL,
    reorder_on date NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    ended_at timestamp with time zone,
    ended_reason text,
    CONSTRAINT consumption_refill_dates_end_pair CHECK (((ended_at IS NULL) = (ended_reason IS NULL))),
    CONSTRAINT consumption_refill_dates_ended_reason_known CHECK (((ended_reason IS NULL) OR (ended_reason = ANY (ARRAY['superseded'::text, 'fill_voided'::text, 'replaced'::text, 'cleared'::text]))))
);


--
-- Name: consumption_refill_dates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_refill_dates ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_refill_dates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_refill_fills; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_refill_fills (
    id integer NOT NULL,
    recipe_id integer NOT NULL,
    filled_on date NOT NULL,
    supplied_days integer,
    note text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    voided_at timestamp with time zone,
    void_reason text,
    CONSTRAINT consumption_refill_fills_note_length CHECK (((note IS NULL) OR ((length(btrim(note)) > 0) AND (length(note) <= 2000)))),
    CONSTRAINT consumption_refill_fills_supplied_days_range CHECK (((supplied_days IS NULL) OR ((supplied_days >= 1) AND (supplied_days <= 730)))),
    CONSTRAINT consumption_refill_fills_void_pair CHECK (((voided_at IS NULL) = (void_reason IS NULL))),
    CONSTRAINT consumption_refill_fills_void_reason_not_blank CHECK (((void_reason IS NULL) OR (length(btrim(void_reason)) > 0)))
);


--
-- Name: consumption_refill_fills_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_refill_fills ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_refill_fills_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_refill_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_refill_orders (
    id integer NOT NULL,
    recipe_id integer NOT NULL,
    ordered_on date NOT NULL,
    state text DEFAULT 'open'::text NOT NULL,
    received_fill_id integer,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    closed_at timestamp with time zone,
    CONSTRAINT consumption_refill_orders_closed_pair CHECK (((state = 'open'::text) = (closed_at IS NULL))),
    CONSTRAINT consumption_refill_orders_received_has_fill CHECK (((state = 'received'::text) = (received_fill_id IS NOT NULL))),
    CONSTRAINT consumption_refill_orders_state_known CHECK ((state = ANY (ARRAY['open'::text, 'received'::text, 'cancelled'::text])))
);


--
-- Name: consumption_refill_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.consumption_refill_orders ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.consumption_refill_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: consumption_refill_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consumption_refill_settings (
    recipe_id integer NOT NULL,
    rule_kind text,
    rule_parameter integer,
    warning_lead_days integer,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT consumption_refill_settings_rule_kind_known CHECK (((rule_kind IS NULL) OR (rule_kind = ANY (ARRAY['days_before_end'::text, 'fixed_interval'::text, 'fraction_elapsed'::text])))),
    CONSTRAINT consumption_refill_settings_rule_pair CHECK (((rule_kind IS NULL) = (rule_parameter IS NULL))),
    CONSTRAINT consumption_refill_settings_rule_parameter_range CHECK (((rule_kind IS NULL) OR ((rule_kind = 'days_before_end'::text) AND ((rule_parameter >= 0) AND (rule_parameter <= 730))) OR ((rule_kind = 'fixed_interval'::text) AND ((rule_parameter >= 1) AND (rule_parameter <= 730))) OR ((rule_kind = 'fraction_elapsed'::text) AND ((rule_parameter >= 1) AND (rule_parameter <= 99))))),
    CONSTRAINT consumption_refill_settings_warning_lead_range CHECK (((warning_lead_days IS NULL) OR ((warning_lead_days >= 0) AND (warning_lead_days <= 60))))
);


--
-- Name: equipment; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.equipment (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    instruction_manual_file_name text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: equipment_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.equipment ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.equipment_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.files (
    id integer NOT NULL,
    file_group text NOT NULL,
    name text NOT NULL,
    mime_type text,
    size_bytes integer NOT NULL,
    is_derivative smallint DEFAULT 0 NOT NULL,
    content bytea NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    CONSTRAINT files_is_derivative_check CHECK ((is_derivative = ANY (ARRAY[0, 1])))
);


--
-- Name: files_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.files ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_artifacts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_artifacts (
    id integer NOT NULL,
    render_request_id integer NOT NULL,
    form text NOT NULL,
    byte_digest text NOT NULL,
    byte_length bigint NOT NULL,
    mime_type text NOT NULL,
    width_px integer NOT NULL,
    height_px integer NOT NULL,
    dpi_x integer NOT NULL,
    dpi_y integer NOT NULL,
    color_mode text NOT NULL,
    profile_id integer NOT NULL,
    template_version_id integer,
    capture_id integer NOT NULL,
    renderer_id text NOT NULL,
    renderer_version text NOT NULL,
    manifest jsonb NOT NULL,
    manifest_digest text NOT NULL,
    file_group text NOT NULL,
    file_name text NOT NULL,
    retention_class text DEFAULT 'preview'::text NOT NULL,
    collected_at timestamp with time zone,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT label_artifacts_byte_digest_check CHECK ((byte_digest ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT label_artifacts_byte_length_check CHECK ((byte_length > 0)),
    CONSTRAINT label_artifacts_dpi_x_check CHECK ((dpi_x > 0)),
    CONSTRAINT label_artifacts_dpi_y_check CHECK ((dpi_y > 0)),
    CONSTRAINT label_artifacts_height_px_check CHECK ((height_px > 0)),
    CONSTRAINT label_artifacts_manifest_digest_check CHECK ((manifest_digest ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT label_artifacts_retention_class_check CHECK ((retention_class = ANY (ARRAY['preview'::text, 'retained'::text]))),
    CONSTRAINT label_artifacts_width_px_check CHECK ((width_px > 0))
);


--
-- Name: label_artifacts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_artifacts ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_artifacts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_assets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_assets (
    id integer NOT NULL,
    name text NOT NULL,
    asset_kind text NOT NULL,
    mime_type text NOT NULL,
    byte_length bigint NOT NULL,
    content_digest text NOT NULL,
    file_group text NOT NULL,
    file_name text NOT NULL,
    width_px integer,
    height_px integer,
    font_family text,
    font_style text,
    licence text NOT NULL,
    licence_notice text,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT label_assets_asset_kind_check CHECK ((asset_kind = ANY (ARRAY['font'::text, 'image'::text]))),
    CONSTRAINT label_assets_byte_length_check CHECK ((byte_length > 0)),
    CONSTRAINT label_assets_check CHECK ((((asset_kind = 'image'::text) AND (width_px IS NOT NULL) AND (height_px IS NOT NULL)) OR ((asset_kind = 'font'::text) AND (font_family IS NOT NULL)))),
    CONSTRAINT label_assets_content_digest_check CHECK ((content_digest ~ '^[0-9a-f]{64}$'::text))
);


--
-- Name: label_assets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_assets ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_assets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_captures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_captures (
    id integer NOT NULL,
    entity_kind text NOT NULL,
    target_id bigint,
    label_uid text,
    captured_fields jsonb NOT NULL,
    locale text NOT NULL,
    timezone text NOT NULL,
    is_sample smallint DEFAULT 0 NOT NULL,
    digest text NOT NULL,
    captured_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    captured_by_user_id integer,
    CONSTRAINT label_captures_check CHECK ((((is_sample = 1) AND (target_id IS NULL) AND (label_uid IS NULL)) OR ((is_sample = 0) AND (target_id IS NOT NULL)))),
    CONSTRAINT label_captures_digest_check CHECK ((digest ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT label_captures_entity_kind_check CHECK ((entity_kind = ANY (ARRAY['location'::text, 'product'::text, 'stock_entry'::text, 'recipe'::text, 'chore'::text, 'battery'::text]))),
    CONSTRAINT label_captures_is_sample_check CHECK ((is_sample = ANY (ARRAY[0, 1])))
);


--
-- Name: label_captures_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_captures ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_captures_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_drivers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_drivers (
    id integer NOT NULL,
    driver_id text NOT NULL,
    schema_version text NOT NULL,
    contract_version integer NOT NULL,
    connection_types jsonb NOT NULL,
    discriminator_properties jsonb NOT NULL,
    combination_binding jsonb NOT NULL,
    settings_schemas jsonb NOT NULL,
    capability_document jsonb NOT NULL,
    registered_by_worker_id integer NOT NULL,
    CONSTRAINT label_drivers_contract_version_check CHECK ((contract_version = 1))
);


--
-- Name: label_drivers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_drivers ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_drivers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_idempotency_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_idempotency_keys (
    id integer NOT NULL,
    principal_user_id integer NOT NULL,
    operation text NOT NULL,
    idempotency_key text NOT NULL,
    request_fingerprint text NOT NULL,
    resource_kind text,
    resource_id integer,
    response jsonb,
    expires_at timestamp with time zone NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT label_idempotency_keys_request_fingerprint_check CHECK ((request_fingerprint ~ '^[0-9a-f]{64}$'::text))
);


--
-- Name: label_idempotency_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_idempotency_keys ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_idempotency_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_import_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_import_state (
    id integer NOT NULL,
    epoch bigint NOT NULL,
    CONSTRAINT label_import_state_epoch_check CHECK ((epoch >= 0)),
    CONSTRAINT label_import_state_id_check CHECK ((id = 1))
);


--
-- Name: label_media_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_media_profiles (
    id integer NOT NULL,
    profile_key text NOT NULL,
    version integer NOT NULL,
    driver_id text NOT NULL,
    driver_schema_version text NOT NULL,
    model text NOT NULL,
    media text NOT NULL,
    connection_type text NOT NULL,
    color_mode text NOT NULL,
    dpi_x integer NOT NULL,
    dpi_y integer NOT NULL,
    raster_width_px integer NOT NULL,
    document jsonb NOT NULL,
    document_digest text NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT label_media_profiles_document_digest_check CHECK ((document_digest ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT label_media_profiles_dpi_x_check CHECK ((dpi_x > 0)),
    CONSTRAINT label_media_profiles_dpi_y_check CHECK ((dpi_y > 0)),
    CONSTRAINT label_media_profiles_raster_width_px_check CHECK ((raster_width_px > 0)),
    CONSTRAINT label_media_profiles_version_check CHECK ((version >= 1))
);


--
-- Name: label_media_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_media_profiles ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_media_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_printer_status; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_printer_status (
    printer_id integer NOT NULL,
    reported_by_worker_id integer NOT NULL,
    last_seen_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    reported_media jsonb,
    device_state text,
    error_state jsonb,
    warning_state jsonb,
    raw_report jsonb NOT NULL
);


--
-- Name: label_printers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_printers (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    active smallint DEFAULT 1 NOT NULL,
    is_default smallint DEFAULT 0 NOT NULL,
    worker_id integer NOT NULL,
    driver_id text NOT NULL,
    driver_schema_version text NOT NULL,
    connection text NOT NULL,
    connection_type text NOT NULL,
    model text NOT NULL,
    settings jsonb NOT NULL,
    settings_validated_at timestamp with time zone NOT NULL,
    CONSTRAINT label_printers_active_check CHECK ((active = ANY (ARRAY[0, 1]))),
    CONSTRAINT label_printers_is_default_check CHECK ((is_default = ANY (ARRAY[0, 1])))
);


--
-- Name: label_printers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_printers ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_printers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_render_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_render_requests (
    id integer NOT NULL,
    purpose text NOT NULL,
    template_version_id integer,
    draft_document jsonb,
    draft_template_id integer,
    profile_id integer NOT NULL,
    capture_id integer NOT NULL,
    state text DEFAULT 'pending'::text NOT NULL,
    generation integer DEFAULT 0 NOT NULL,
    generation_token text,
    lease_expires_at timestamp with time zone,
    attempts integer DEFAULT 0 NOT NULL,
    error_code text,
    error_element text,
    error_detail text,
    artifact_id integer,
    expires_at timestamp with time zone,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_by_user_id integer,
    CONSTRAINT label_render_requests_attempts_check CHECK ((attempts >= 0)),
    CONSTRAINT label_render_requests_check CHECK ((((purpose = 'preview_draft'::text) AND (draft_document IS NOT NULL) AND (draft_template_id IS NOT NULL) AND (template_version_id IS NULL)) OR ((purpose <> 'preview_draft'::text) AND (draft_document IS NULL) AND (template_version_id IS NOT NULL)))),
    CONSTRAINT label_render_requests_generation_check CHECK ((generation >= 0)),
    CONSTRAINT label_render_requests_purpose_check CHECK ((purpose = ANY (ARRAY['production'::text, 'preview_live'::text, 'preview_sample'::text, 'preview_draft'::text]))),
    CONSTRAINT label_render_requests_state_check CHECK ((state = ANY (ARRAY['pending'::text, 'rendering'::text, 'ready'::text, 'invalid'::text, 'failed'::text])))
);


--
-- Name: label_render_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_render_requests ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_render_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_template_drafts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_template_drafts (
    template_id integer NOT NULL,
    document jsonb NOT NULL,
    revision_token text NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_by_user_id integer
);


--
-- Name: label_template_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_template_versions (
    id integer NOT NULL,
    template_id integer NOT NULL,
    version integer NOT NULL,
    document jsonb NOT NULL,
    document_digest text NOT NULL,
    asset_ids jsonb NOT NULL,
    required_capabilities jsonb NOT NULL,
    published_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    published_by_user_id integer,
    CONSTRAINT label_template_versions_document_digest_check CHECK ((document_digest ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT label_template_versions_version_check CHECK ((version >= 1))
);


--
-- Name: label_template_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_template_versions ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_template_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_templates (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    entity_kind text NOT NULL,
    default_version_id integer,
    archived_at timestamp with time zone,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT label_templates_entity_kind_check CHECK ((entity_kind = ANY (ARRAY['location'::text, 'product'::text, 'stock_entry'::text, 'recipe'::text, 'chore'::text, 'battery'::text])))
);


--
-- Name: label_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_templates ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_worker_capabilities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_worker_capabilities (
    id integer NOT NULL,
    worker_id integer NOT NULL,
    driver_id text NOT NULL,
    schema_version text NOT NULL,
    artifact_forms jsonb NOT NULL,
    profile_contract_versions jsonb NOT NULL,
    last_registered_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: label_worker_capabilities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_worker_capabilities ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_worker_capabilities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_worker_credentials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_worker_credentials (
    api_key_id integer NOT NULL,
    worker_id integer NOT NULL,
    session_id integer,
    issued_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    consumed_at timestamp with time zone,
    rotation_request_id text,
    rotation_material_hash text,
    successor_api_key_id integer
);


--
-- Name: label_worker_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_worker_sessions (
    id integer NOT NULL,
    worker_id integer NOT NULL,
    created_by_user_id integer NOT NULL,
    pairing_material_hash text,
    material_expires_at timestamp with time zone,
    material_consumed_at timestamp with time zone,
    started_at timestamp with time zone,
    expires_at timestamp with time zone,
    revoked_at timestamp with time zone,
    revoked_reason text,
    CONSTRAINT label_worker_sessions_revoked_reason_check CHECK ((revoked_reason = ANY (ARRAY['admin'::text, 'credential_reuse'::text, 'worker_deactivated'::text])))
);


--
-- Name: label_worker_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_worker_sessions ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_worker_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: label_workers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.label_workers (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    configuration_mode text NOT NULL,
    active smallint DEFAULT 1 NOT NULL,
    last_registered_at timestamp with time zone,
    requires_repairing smallint DEFAULT 0 NOT NULL,
    CONSTRAINT label_workers_active_check CHECK ((active = ANY (ARRAY[0, 1]))),
    CONSTRAINT label_workers_configuration_mode_check CHECK ((configuration_mode = ANY (ARRAY['declared'::text, 'paired'::text]))),
    CONSTRAINT label_workers_requires_repairing_check CHECK ((requires_repairing = ANY (ARRAY[0, 1])))
);


--
-- Name: label_workers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.label_workers ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.label_workers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: labels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.labels (
    uid text NOT NULL,
    kind text NOT NULL,
    target_id bigint,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    retired_at timestamp with time zone,
    retirement_snapshot jsonb,
    CONSTRAINT labels_check CHECK ((((retired_at IS NULL) AND (target_id IS NOT NULL) AND (retirement_snapshot IS NULL)) OR ((retired_at IS NOT NULL) AND (target_id IS NULL) AND (retirement_snapshot IS NOT NULL)))),
    CONSTRAINT labels_kind_check CHECK ((kind = ANY (ARRAY['location'::text, 'product'::text, 'stock_entry'::text, 'recipe'::text, 'chore'::text, 'battery'::text]))),
    CONSTRAINT labels_uid_check CHECK ((uid ~ '^[0-9A-F][0-9A-HJKMNP-TV-Z]{12}$'::text))
);


--
-- Name: locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locations (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    is_freezer smallint DEFAULT 0 NOT NULL,
    active smallint DEFAULT 1 NOT NULL,
    import_epoch bigint DEFAULT public.label_current_import_epoch() NOT NULL,
    parent_location_id integer,
    storage_class_id integer,
    tare_weight double precision,
    tare_qu_id integer,
    CONSTRAINT locations_active_check CHECK ((active = ANY (ARRAY[0, 1])))
);


--
-- Name: locations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.locations ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.locations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: locations_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.locations_resolved AS
 WITH RECURSIVE tree(id, level, path, id_path) AS (
         SELECT l.id,
            0 AS level,
            l.name AS path,
            (('/'::text || (l.id)::text) || '/'::text) AS id_path
           FROM public.locations l
          WHERE ((l.parent_location_id IS NULL) OR (NOT (EXISTS ( SELECT 1
                   FROM public.locations p_1
                  WHERE (p_1.id = l.parent_location_id)))))
        UNION ALL
         SELECT c.id,
            (t_1.level + 1),
            ((t_1.path || ' / '::text) || c.name),
            ((t_1.id_path || (c.id)::text) || '/'::text)
           FROM (tree t_1
             JOIN public.locations c ON ((c.parent_location_id = t_1.id)))
          WHERE (((t_1.level + 1) < public.hierarchy_depth_limit()) AND (t_1.id_path !~~ (('%/'::text || (c.id)::text) || '/%'::text)))
        ), pairs(ancestor_location_id, descendant_location_id, depth) AS (
         SELECT t_1.id AS ancestor_location_id,
            t_1.id AS descendant_location_id,
            0 AS depth
           FROM tree t_1
        UNION ALL
         SELECT l.parent_location_id,
            p_1.descendant_location_id,
            (p_1.depth + 1)
           FROM ((pairs p_1
             JOIN public.locations l ON ((l.id = p_1.ancestor_location_id)))
             JOIN public.locations a ON ((a.id = l.parent_location_id)))
          WHERE ((p_1.depth + 1) < public.hierarchy_depth_limit())
        )
 SELECT p.ancestor_location_id,
    p.descendant_location_id,
    p.depth,
    t.path,
    1 AS id
   FROM (pairs p
     JOIN tree t ON ((t.id = p.descendant_location_id)));


--
-- Name: login_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_attempts (
    id integer NOT NULL,
    username text NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: login_attempts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.login_attempts ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.login_attempts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: meal_plan; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meal_plan (
    id integer NOT NULL,
    day date NOT NULL,
    type text DEFAULT 'recipe'::text,
    recipe_id integer,
    recipe_servings double precision DEFAULT 1,
    note text,
    product_id integer,
    product_amount double precision DEFAULT 0,
    product_qu_id integer,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    done smallint DEFAULT 0 NOT NULL,
    section_id integer DEFAULT '-1'::integer NOT NULL,
    CONSTRAINT meal_plan_done_check CHECK ((done = ANY (ARRAY[0, 1])))
);


--
-- Name: meal_plan_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.meal_plan ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.meal_plan_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recipes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recipes (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    picture_file_name text,
    base_servings double precision DEFAULT 1,
    desired_servings double precision DEFAULT 1,
    not_check_shoppinglist smallint DEFAULT 0 NOT NULL,
    type text DEFAULT 'normal'::text,
    product_id integer,
    import_epoch bigint DEFAULT public.label_current_import_epoch() NOT NULL,
    default_shopping_list_id integer
);


--
-- Name: meal_plan_internal_recipe_relation; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.meal_plan_internal_recipe_relation AS
 SELECT mp.day,
    r.id AS recipe_id
   FROM (public.meal_plan mp
     JOIN public.recipes r ON (((r.name = (mp.day)::text) AND (r.type = 'mealplan-day'::text))))
UNION
 SELECT mp.day,
    r.id AS recipe_id
   FROM (public.meal_plan mp
     JOIN public.recipes r ON (((r.name = ltrim(((((EXTRACT(year FROM mp.day))::integer)::text || '-'::text) || lpad((public.victual_sqlite_percent_w(mp.day))::text, 2, '0'::text)), '0'::text)) AND (r.type = 'mealplan-week'::text))))
UNION
 SELECT mp.day,
    r.id AS recipe_id
   FROM (public.meal_plan mp
     JOIN public.recipes r ON (((r.name = (((mp.day)::text || '#'::text) || (mp.id)::text)) AND (r.type = 'mealplan-shadow'::text))));


--
-- Name: meal_plan_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meal_plan_sections (
    id integer NOT NULL,
    name text NOT NULL,
    sort_number integer,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    time_info text
);


--
-- Name: meal_plan_sections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.meal_plan_sections ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.meal_plan_sections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    migration integer NOT NULL,
    execution_time_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: mqtt_product_entities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mqtt_product_entities (
    id integer NOT NULL,
    product_id integer NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: mqtt_product_entities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.mqtt_product_entities ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.mqtt_product_entities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: mqtt_published_entities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mqtt_published_entities (
    id integer NOT NULL,
    object_id text NOT NULL,
    payload_hash text NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: mqtt_published_entities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.mqtt_published_entities ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.mqtt_published_entities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: outbox; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.outbox (
    id integer NOT NULL,
    event_type text NOT NULL,
    payload text NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    delivered_at timestamp with time zone,
    dead_lettered_at timestamp with time zone,
    attempts integer DEFAULT 0 NOT NULL,
    last_error text
);


--
-- Name: outbox_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.outbox ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.outbox_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: permission_fields; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permission_fields (
    id smallint NOT NULL,
    permission_name text NOT NULL,
    entity text NOT NULL,
    field text NOT NULL
);


--
-- Name: permission_fields_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.permission_fields ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.permission_fields_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: permission_hierarchy; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permission_hierarchy (
    id integer NOT NULL,
    name text NOT NULL,
    parent integer
);


--
-- Name: permission_hierarchy_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.permission_hierarchy ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.permission_hierarchy_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: permission_tree; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.permission_tree AS
 WITH RECURSIVE perm AS (
         SELECT permission_hierarchy.id AS root,
            permission_hierarchy.id AS child,
            permission_hierarchy.name,
            permission_hierarchy.parent
           FROM public.permission_hierarchy
        UNION
         SELECT perm_1.root,
            ph.id,
            ph.name,
            ph.id
           FROM public.permission_hierarchy ph,
            perm perm_1
          WHERE (ph.parent = perm_1.child)
        )
 SELECT root AS id,
    name
   FROM perm;


--
-- Name: print_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.print_attempts (
    id integer NOT NULL,
    outbox_id integer NOT NULL,
    job_id integer NOT NULL,
    attempt_number integer NOT NULL,
    worker_id integer NOT NULL,
    claimed_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    lease_expires_at timestamp with time zone NOT NULL,
    lease_hard_deadline timestamp with time zone NOT NULL,
    heartbeats integer DEFAULT 0 NOT NULL,
    bytes_sent_at timestamp with time zone,
    device_reported_at timestamp with time zone,
    ended_at timestamp with time zone,
    outcome text,
    acknowledged_on text NOT NULL,
    error_text text,
    reported_outcome text,
    reported_at timestamp with time zone,
    reported_detail jsonb,
    superseded_at timestamp with time zone,
    CONSTRAINT print_attempts_acknowledged_on_check CHECK ((acknowledged_on = ANY (ARRAY['report'::text, 'send'::text]))),
    CONSTRAINT print_attempts_attempt_number_check CHECK ((attempt_number > 0)),
    CONSTRAINT print_attempts_check CHECK ((lease_expires_at <= lease_hard_deadline)),
    CONSTRAINT print_attempts_heartbeats_check CHECK ((heartbeats >= 0)),
    CONSTRAINT print_attempts_outcome_check CHECK ((outcome = ANY (ARRAY['printed'::text, 'sent'::text, 'failed'::text, 'blocked'::text, 'uncertain'::text]))),
    CONSTRAINT print_attempts_reported_outcome_check CHECK ((reported_outcome = ANY (ARRAY['printed'::text, 'failed'::text])))
);


--
-- Name: print_attempts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.print_attempts ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.print_attempts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: print_evidence; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.print_evidence (
    id integer NOT NULL,
    attempt_id integer NOT NULL,
    submission_id text NOT NULL,
    evidence_type text NOT NULL,
    source text NOT NULL,
    observed_at timestamp with time zone NOT NULL,
    received_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    decoded_uid text,
    printer_status jsonb,
    image_ref text,
    confidence double precision,
    detail jsonb NOT NULL,
    CONSTRAINT print_evidence_confidence_check CHECK (((confidence >= (0)::double precision) AND (confidence <= (1)::double precision))),
    CONSTRAINT print_evidence_image_ref_check CHECK ((image_ref !~ '://'::text))
);


--
-- Name: print_evidence_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.print_evidence ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.print_evidence_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: print_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.print_jobs (
    id integer NOT NULL,
    outbox_id integer NOT NULL,
    printer_id integer NOT NULL,
    label_uid text NOT NULL,
    attempts_authorized integer DEFAULT 1 NOT NULL,
    attempts_made integer DEFAULT 0 NOT NULL,
    current_attempt_id integer,
    outcome text,
    outcome_at timestamp with time zone,
    operation text DEFAULT 'issue'::text NOT NULL,
    artifact_id integer,
    render_request_id integer,
    capture_id integer,
    source_job_id integer,
    idempotency_key_id integer,
    cancelled_at timestamp with time zone,
    cancelled_reason text,
    CONSTRAINT print_jobs_attempts_authorized_check CHECK ((attempts_authorized > 0)),
    CONSTRAINT print_jobs_attempts_made_check CHECK ((attempts_made >= 0)),
    CONSTRAINT print_jobs_cancellation CHECK (((cancelled_at IS NULL) = (cancelled_reason IS NULL))),
    CONSTRAINT print_jobs_check CHECK ((attempts_made <= attempts_authorized)),
    CONSTRAINT print_jobs_operation_check CHECK ((operation = ANY (ARRAY['issue'::text, 'reprint'::text, 'revised_print'::text, 'promote_preview'::text]))),
    CONSTRAINT print_jobs_outcome_check CHECK ((outcome = ANY (ARRAY['printed'::text, 'sent'::text, 'dead_lettered'::text])))
);


--
-- Name: print_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.print_jobs ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.print_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: product_barcodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_barcodes (
    id integer NOT NULL,
    product_id integer NOT NULL,
    barcode text NOT NULL,
    qu_id integer,
    amount double precision,
    shopping_location_id integer,
    last_price double precision,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    note text
);


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    product_group_id integer,
    active smallint DEFAULT 1 NOT NULL,
    location_id integer NOT NULL,
    shopping_location_id integer,
    qu_id_purchase integer NOT NULL,
    qu_id_stock integer NOT NULL,
    min_stock_amount double precision DEFAULT 0 NOT NULL,
    default_best_before_days integer DEFAULT 0 NOT NULL,
    default_best_before_days_after_open integer DEFAULT 0 NOT NULL,
    default_best_before_days_after_freezing integer DEFAULT 0 NOT NULL,
    default_best_before_days_after_thawing integer DEFAULT 0 NOT NULL,
    picture_file_name text,
    enable_tare_weight_handling smallint DEFAULT 0 NOT NULL,
    tare_weight double precision DEFAULT 0 NOT NULL,
    not_check_stock_fulfillment_for_recipes smallint DEFAULT 0,
    parent_product_id integer,
    calories double precision,
    cumulate_min_stock_amount_of_sub_products smallint DEFAULT 0,
    due_type smallint DEFAULT 1 NOT NULL,
    quick_consume_amount double precision DEFAULT 1 NOT NULL,
    hide_on_stock_overview smallint DEFAULT 0 NOT NULL,
    default_stock_label_type integer DEFAULT 0 NOT NULL,
    should_not_be_frozen smallint DEFAULT 0 NOT NULL,
    treat_opened_as_out_of_stock smallint DEFAULT 1 NOT NULL,
    no_own_stock smallint DEFAULT 0 NOT NULL,
    default_consume_location_id integer,
    move_on_open smallint DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    qu_id_consume integer,
    auto_reprint_stock_label smallint DEFAULT 0 NOT NULL,
    quick_open_amount double precision DEFAULT 1 NOT NULL,
    qu_id_price integer,
    disable_open smallint DEFAULT 0 NOT NULL,
    default_purchase_price_type smallint DEFAULT 1 NOT NULL,
    quick_refill_amount double precision DEFAULT 1 NOT NULL,
    default_refill_location_id_from integer,
    default_refill_location_id_to integer,
    import_epoch bigint DEFAULT public.label_current_import_epoch() NOT NULL,
    default_shopping_list_id integer,
    CONSTRAINT products_active_check CHECK ((active = ANY (ARRAY[0, 1]))),
    CONSTRAINT products_auto_reprint_stock_label_check CHECK ((auto_reprint_stock_label = ANY (ARRAY[0, 1]))),
    CONSTRAINT products_default_purchase_price_type_check CHECK ((default_purchase_price_type = ANY (ARRAY[1, 2, 3]))),
    CONSTRAINT products_disable_open_check CHECK ((disable_open = ANY (ARRAY[0, 1]))),
    CONSTRAINT products_due_type_check CHECK ((due_type = ANY (ARRAY[1, 2]))),
    CONSTRAINT products_hide_on_stock_overview_check CHECK ((hide_on_stock_overview = ANY (ARRAY[0, 1]))),
    CONSTRAINT products_move_on_open_check CHECK ((move_on_open = ANY (ARRAY[0, 1]))),
    CONSTRAINT products_no_own_stock_check CHECK ((no_own_stock = ANY (ARRAY[0, 1]))),
    CONSTRAINT products_should_not_be_frozen_check CHECK ((should_not_be_frozen = ANY (ARRAY[0, 1]))),
    CONSTRAINT products_treat_opened_as_out_of_stock_check CHECK ((treat_opened_as_out_of_stock = ANY (ARRAY[0, 1])))
);


--
-- Name: product_barcodes_comma_separated; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.product_barcodes_comma_separated AS
 SELECT min(pb.id) AS id,
    pb.product_id,
    string_agg(pb.barcode, ','::text) AS barcodes
   FROM (public.product_barcodes pb
     JOIN public.products p ON ((pb.product_id = p.id)))
  WHERE (p.active = 1)
  GROUP BY pb.product_id;


--
-- Name: product_barcodes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.product_barcodes ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.product_barcodes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: product_barcodes_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.product_barcodes_view AS
 SELECT pb.id,
    pb.product_id,
    pb.barcode,
    pb.qu_id,
    pb.amount,
    pb.shopping_location_id,
    pb.last_price,
    pb.note
   FROM public.product_barcodes pb
UNION ALL
 SELECT p.id,
    p.id AS product_id,
    ('grcy:p:'::text || (p.id)::text) AS barcode,
    p.qu_id_stock AS qu_id,
    NULL::double precision AS amount,
    NULL::integer AS shopping_location_id,
    NULL::double precision AS last_price,
    NULL::text AS note
   FROM public.products p;


--
-- Name: product_groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_groups (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    active smallint DEFAULT 1 NOT NULL,
    min_stock_amount double precision DEFAULT 0 NOT NULL,
    parent_product_group_id integer,
    CONSTRAINT product_groups_active_check CHECK ((active = ANY (ARRAY[0, 1])))
);


--
-- Name: product_groups_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.product_groups ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.product_groups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: product_groups_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.product_groups_resolved AS
 WITH RECURSIVE tree(id, level, path, id_path) AS (
         SELECT pg.id,
            0 AS level,
            pg.name AS path,
            (('/'::text || (pg.id)::text) || '/'::text) AS id_path
           FROM public.product_groups pg
          WHERE ((pg.parent_product_group_id IS NULL) OR (NOT (EXISTS ( SELECT 1
                   FROM public.product_groups p_1
                  WHERE (p_1.id = pg.parent_product_group_id)))))
        UNION ALL
         SELECT c.id,
            (t_1.level + 1),
            ((t_1.path || ' / '::text) || c.name),
            ((t_1.id_path || (c.id)::text) || '/'::text)
           FROM (tree t_1
             JOIN public.product_groups c ON ((c.parent_product_group_id = t_1.id)))
          WHERE (((t_1.level + 1) < public.hierarchy_depth_limit()) AND (t_1.id_path !~~ (('%/'::text || (c.id)::text) || '/%'::text)))
        ), pairs(ancestor_product_group_id, descendant_product_group_id, depth) AS (
         SELECT t_1.id AS ancestor_product_group_id,
            t_1.id AS descendant_product_group_id,
            0 AS depth
           FROM tree t_1
        UNION ALL
         SELECT pg.parent_product_group_id,
            p_1.descendant_product_group_id,
            (p_1.depth + 1)
           FROM ((pairs p_1
             JOIN public.product_groups pg ON ((pg.id = p_1.ancestor_product_group_id)))
             JOIN public.product_groups a ON ((a.id = pg.parent_product_group_id)))
          WHERE ((p_1.depth + 1) < public.hierarchy_depth_limit())
        )
 SELECT p.ancestor_product_group_id,
    p.descendant_product_group_id,
    p.depth,
    t.path,
    1 AS id
   FROM (pairs p
     JOIN tree t ON ((t.id = p.descendant_product_group_id)));


--
-- Name: stock; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock (
    id integer NOT NULL,
    product_id integer NOT NULL,
    amount double precision NOT NULL,
    best_before_date date,
    purchased_date date DEFAULT CURRENT_DATE,
    stock_id text NOT NULL,
    price double precision,
    open smallint DEFAULT 0 NOT NULL,
    opened_date date,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    location_id integer,
    shopping_location_id integer,
    note text,
    opened_amount double precision,
    opened_qu_id integer,
    opened_tare double precision,
    opened_measured_at timestamp with time zone,
    import_epoch bigint DEFAULT public.label_current_import_epoch() NOT NULL,
    CONSTRAINT stock_amount_non_negative_check CHECK ((amount >= (0)::double precision)),
    CONSTRAINT stock_measurement_coherence_check CHECK ((((opened_amount IS NULL) AND (opened_qu_id IS NULL)) OR ((opened_amount IS NOT NULL) AND (opened_qu_id IS NOT NULL) AND (open = 1) AND (amount = (1)::double precision)))),
    CONSTRAINT stock_open_check CHECK ((open = ANY (ARRAY[0, 1])))
);


--
-- Name: product_groups_missing; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.product_groups_missing AS
 SELECT id,
    name,
    min_stock_amount,
    amount_missing
   FROM ( SELECT pg.id,
            pg.name,
            pg.min_stock_amount,
            (pg.min_stock_amount - COALESCE(sum(member.effective_amount), (0)::double precision)) AS amount_missing
           FROM (public.product_groups pg
             LEFT JOIN ( SELECT p.id,
                    resolved.ancestor_product_group_id AS product_group_id,
                    (COALESCE(sum(s.amount), (0)::double precision) -
                        CASE
                            WHEN (p.treat_opened_as_out_of_stock = 1) THEN COALESCE(sum(
                            CASE
                                WHEN (s.open = 1) THEN s.amount
                                ELSE (0)::double precision
                            END), (0)::double precision)
                            ELSE (0)::double precision
                        END) AS effective_amount
                   FROM ((public.products p
                     JOIN public.product_groups_resolved resolved ON ((resolved.descendant_product_group_id = p.product_group_id)))
                     LEFT JOIN public.stock s ON ((s.product_id = p.id)))
                  WHERE (COALESCE((p.active)::integer, 0) = 1)
                  GROUP BY p.id, resolved.ancestor_product_group_id, p.treat_opened_as_out_of_stock) member ON ((member.product_group_id = pg.id)))
          WHERE ((pg.min_stock_amount <> (0)::double precision) AND (COALESCE((pg.active)::integer, 0) = 1))
          GROUP BY pg.id, pg.name, pg.min_stock_amount) x
  WHERE (amount_missing > (0)::double precision);


--
-- Name: product_location_min_stock; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_location_min_stock (
    id integer NOT NULL,
    product_id integer NOT NULL,
    location_id integer NOT NULL,
    min_stock_amount double precision DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: product_location_min_stock_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.product_location_min_stock ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.product_location_min_stock_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: product_location_missing; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.product_location_missing AS
 SELECT id,
    product_id,
    product_name,
    location_id,
    location_name,
    min_stock_amount,
    amount_missing,
    quick_refill_amount,
    default_refill_location_id_from
   FROM ( SELECT pl.id,
            pl.product_id,
            p.name AS product_name,
            pl.location_id,
            l.name AS location_name,
            pl.min_stock_amount,
            (pl.min_stock_amount - COALESCE(loc.effective_amount, (0)::double precision)) AS amount_missing,
            p.quick_refill_amount,
            p.default_refill_location_id_from
           FROM (((public.product_location_min_stock pl
             JOIN public.products p ON ((p.id = pl.product_id)))
             JOIN public.locations l ON ((l.id = pl.location_id)))
             LEFT JOIN ( SELECT s.product_id,
                    s.location_id,
                    (COALESCE(sum(s.amount), (0)::double precision) -
                        CASE
                            WHEN (p2.treat_opened_as_out_of_stock = 1) THEN COALESCE(sum(
                            CASE
                                WHEN (s.open = 1) THEN s.amount
                                ELSE (0)::double precision
                            END), (0)::double precision)
                            ELSE (0)::double precision
                        END) AS effective_amount
                   FROM (public.stock s
                     JOIN public.products p2 ON ((p2.id = s.product_id)))
                  GROUP BY s.product_id, s.location_id, p2.treat_opened_as_out_of_stock) loc ON (((loc.product_id = pl.product_id) AND (loc.location_id = pl.location_id))))
          WHERE ((pl.min_stock_amount <> (0)::double precision) AND (COALESCE((p.active)::integer, 0) = 1) AND (COALESCE((l.active)::integer, 0) = 1))) x
  WHERE (amount_missing > (0)::double precision);


--
-- Name: quantity_unit_conversions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quantity_unit_conversions (
    id integer NOT NULL,
    from_qu_id integer NOT NULL,
    to_qu_id integer NOT NULL,
    factor double precision NOT NULL,
    product_id integer,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    CONSTRAINT quantity_unit_conversions_factor_positive CHECK (((factor > (0)::double precision) AND (factor < 'Infinity'::double precision)))
);


--
-- Name: quantity_units; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quantity_units (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    name_plural text,
    plural_forms text,
    active smallint DEFAULT 1 NOT NULL,
    CONSTRAINT quantity_units_active_check CHECK ((active = ANY (ARRAY[0, 1])))
);


--
-- Name: quantity_units_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.quantity_units_resolved AS
 SELECT '-1'::integer AS id,
    qu.id AS qu_id,
    quc.to_qu_id AS related_qu_id,
    quc.factor
   FROM (public.quantity_units qu
     JOIN public.quantity_unit_conversions quc ON (((qu.id = quc.from_qu_id) AND (quc.product_id IS NULL))));


--
-- Name: product_qu_relations; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.product_qu_relations AS
 SELECT '-1'::integer AS id,
    p.id AS product_id,
    p.qu_id_stock AS qu_id
   FROM public.products p
UNION
 SELECT '-1'::integer AS id,
    p.id AS product_id,
    p.qu_id_purchase AS qu_id
   FROM public.products p
UNION
 SELECT '-1'::integer AS id,
    quc.product_id,
    quc.to_qu_id AS qu_id
   FROM public.quantity_unit_conversions quc
  WHERE (quc.product_id IS NOT NULL)
UNION
 SELECT '-1'::integer AS id,
    p.id AS product_id,
    qur2.qu_id
   FROM (((public.products p
     JOIN public.quantity_unit_conversions quc ON ((((p.qu_id_stock = quc.from_qu_id) OR (p.qu_id_purchase = quc.from_qu_id)) AND (p.id = quc.product_id))))
     JOIN public.quantity_units_resolved qur1 ON ((quc.to_qu_id = qur1.qu_id)))
     JOIN public.quantity_units_resolved qur2 ON ((qur1.related_qu_id = qur2.qu_id)));


--
-- Name: product_substitutions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_substitutions (
    id integer NOT NULL,
    from_product_id integer NOT NULL,
    to_product_id integer NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    CONSTRAINT product_substitutions_no_self_edge CHECK ((from_product_id <> to_product_id))
);


--
-- Name: product_substitutions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.product_substitutions ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.product_substitutions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: products_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_resolved AS
 SELECT
        CASE
            WHEN (parent_product_id IS NULL) THEN id
            ELSE parent_product_id
        END AS parent_product_id,
    id AS sub_product_id
   FROM public.products p
  WHERE (active = 1);


--
-- Name: stock_current; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_current AS
 SELECT pr.parent_product_id AS product_id,
    COALESCE(( SELECT sum(stock.amount) AS sum
           FROM public.stock
          WHERE (stock.product_id = pr.parent_product_id)), (0)::double precision) AS amount,
    sum((s.amount * COALESCE((qucr.factor)::double precision,
        CASE
            WHEN (pr.parent_product_id = pr.sub_product_id) THEN (1.0)::double precision
            ELSE (0.0)::double precision
        END))) AS amount_aggregated,
    COALESCE((round((( SELECT sum((COALESCE(stock.price, (0)::double precision) * stock.amount)) AS sum
           FROM public.stock
          WHERE (stock.product_id = pr.parent_product_id)))::numeric, 2))::double precision, (0)::double precision) AS value,
    min(s.best_before_date) AS best_before_date,
    COALESCE(( SELECT sum(stock.amount) AS sum
           FROM public.stock
          WHERE ((stock.product_id = pr.parent_product_id) AND (stock.open = 1))), (0)::double precision) AS amount_opened,
    COALESCE(sum(
        CASE
            WHEN (s.open = 1) THEN (s.amount * COALESCE((qucr.factor)::double precision,
            CASE
                WHEN (pr.parent_product_id = pr.sub_product_id) THEN (1.0)::double precision
                ELSE (0.0)::double precision
            END))
            ELSE (0)::double precision
        END), (0)::double precision) AS amount_opened_aggregated,
        CASE
            WHEN (count(p_sub.parent_product_id) > 0) THEN 1
            ELSE 0
        END AS is_aggregated_amount,
    max(p_parent.due_type) AS due_type,
    COALESCE(sum(((s.opened_amount * (qucr_measure.factor)::double precision) * COALESCE((qucr.factor)::double precision,
        CASE
            WHEN (pr.parent_product_id = pr.sub_product_id) THEN (1.0)::double precision
            ELSE (0.0)::double precision
        END))), (0)::double precision) AS amount_measured
   FROM (((((public.products_resolved pr
     JOIN public.stock s ON ((pr.sub_product_id = s.product_id)))
     JOIN public.products p_parent ON (((pr.parent_product_id = p_parent.id) AND (p_parent.active = 1))))
     JOIN public.products p_sub ON (((pr.sub_product_id = p_sub.id) AND (p_sub.active = 1))))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved qucr ON (((pr.sub_product_id = qucr.product_id) AND (p_sub.qu_id_stock = qucr.from_qu_id) AND (p_parent.qu_id_stock = qucr.to_qu_id) AND ((qucr.factor)::numeric > (0)::numeric))))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved qucr_measure ON (((s.product_id = qucr_measure.product_id) AND (s.opened_qu_id = qucr_measure.from_qu_id) AND (p_sub.qu_id_stock = qucr_measure.to_qu_id))))
  GROUP BY pr.parent_product_id
 HAVING (sum(s.amount) > (0)::double precision)
UNION
 SELECT pr.sub_product_id AS product_id,
    sum(s.amount) AS amount,
    sum(s.amount) AS amount_aggregated,
    (round((sum((COALESCE(s.price, (0)::double precision) * s.amount)))::numeric, 2))::double precision AS value,
    min(s.best_before_date) AS best_before_date,
    COALESCE(( SELECT sum(stock.amount) AS sum
           FROM public.stock
          WHERE ((stock.product_id = pr.sub_product_id) AND (stock.open = 1))), (0)::double precision) AS amount_opened,
    COALESCE(( SELECT sum(stock.amount) AS sum
           FROM public.stock
          WHERE ((stock.product_id = pr.sub_product_id) AND (stock.open = 1))), (0)::double precision) AS amount_opened_aggregated,
    0 AS is_aggregated_amount,
    max(p_sub.due_type) AS due_type,
    COALESCE(sum((s.opened_amount * (qucr_measure.factor)::double precision)), (0)::double precision) AS amount_measured
   FROM (((public.products_resolved pr
     JOIN public.stock s ON ((pr.sub_product_id = s.product_id)))
     JOIN public.products p_sub ON (((pr.sub_product_id = p_sub.id) AND (p_sub.active = 1))))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved qucr_measure ON (((s.product_id = qucr_measure.product_id) AND (s.opened_qu_id = qucr_measure.from_qu_id) AND (p_sub.qu_id_stock = qucr_measure.to_qu_id))))
  WHERE (pr.parent_product_id <> pr.sub_product_id)
  GROUP BY pr.sub_product_id
 HAVING (sum(s.amount) > (0)::double precision);


--
-- Name: product_substitutions_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.product_substitutions_resolved AS
 WITH edges(from_product_id, to_product_id, direction) AS (
         SELECT ps.from_product_id,
            ps.to_product_id,
            'directed'::text AS direction
           FROM public.product_substitutions ps
          WHERE (NOT (EXISTS ( SELECT 1
                   FROM public.products_resolved pr
                  WHERE ((pr.sub_product_id = ps.from_product_id) AND (pr.parent_product_id = ps.to_product_id)))))
        UNION
         SELECT pr.sub_product_id AS from_product_id,
            pr.parent_product_id AS to_product_id,
            'shared_parent'::text AS direction
           FROM public.products_resolved pr
          WHERE (pr.sub_product_id <> pr.parent_product_id)
        )
 SELECT 1 AS id,
    e.to_product_id,
    e.from_product_id,
    e.direction,
    COALESCE(sc.amount, (0)::double precision) AS from_product_amount_in_stock,
    sc.best_before_date AS from_product_best_before_date
   FROM (edges e
     LEFT JOIN public.stock_current sc ON ((sc.product_id = e.from_product_id)));


--
-- Name: stock_entry_origins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_entry_origins (
    id integer NOT NULL,
    stock_id text NOT NULL,
    origin_stock_id text NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    CONSTRAINT stock_entry_origins_check CHECK ((stock_id <> origin_stock_id))
);


--
-- Name: stock_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_log (
    id integer NOT NULL,
    product_id integer NOT NULL,
    amount double precision NOT NULL,
    best_before_date date,
    purchased_date date,
    used_date date,
    spoiled integer DEFAULT 0 NOT NULL,
    stock_id text NOT NULL,
    transaction_type text NOT NULL,
    price double precision,
    undone smallint DEFAULT 0 NOT NULL,
    undone_timestamp timestamp with time zone,
    opened_date date,
    location_id integer,
    recipe_id integer,
    correlation_id text,
    transaction_id text,
    stock_row_id integer,
    shopping_location_id integer,
    user_id integer NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    note text,
    opened_amount double precision,
    opened_qu_id integer,
    opened_tare double precision,
    opened_measured_at timestamp with time zone,
    CONSTRAINT stock_log_undone_check CHECK ((undone = ANY (ARRAY[0, 1])))
);


--
-- Name: stock_edited_entries; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_edited_entries AS
 WITH bookings AS (
         SELECT sl.id,
            sl.stock_id,
            sl.amount,
            sl.transaction_type,
            sl.undone,
            NULL::double precision AS correction
           FROM public.stock_log sl
          WHERE (NOT ((sl.transaction_type = 'stock-edit-new'::text) AND (sl.undone = 0)))
        UNION ALL
         SELECT sl.id,
            sl.stock_id,
            sl.amount,
            sl.transaction_type,
            sl.undone,
            (sl.amount - COALESCE(sl_old.amount, sl.amount))
           FROM (public.stock_log sl
             LEFT JOIN LATERAL ( SELECT sl_prev.amount
                   FROM public.stock_log sl_prev
                  WHERE ((sl_prev.transaction_type = 'stock-edit-old'::text) AND (sl_prev.stock_id = sl.stock_id) AND (sl_prev.id < sl.id))
                  ORDER BY (NOT (sl_prev.correlation_id IS DISTINCT FROM sl.correlation_id)) DESC, sl_prev.id DESC
                 LIMIT 1) sl_old ON (true))
          WHERE ((sl.transaction_type = 'stock-edit-new'::text) AND (sl.undone = 0))
        ), keyed AS (
         SELECT b.stock_id,
            sum(b.amount) FILTER (WHERE ((b.undone = 0) AND (b.transaction_type = ANY (ARRAY['purchase'::text, 'inventory-correction'::text, 'self-production'::text])) AND (b.amount > (0)::double precision))) OVER origin_group AS origin_amount,
            max(b.id) FILTER (WHERE ((b.transaction_type = 'stock-edit-new'::text) AND (b.undone = 0))) OVER origin_group AS stock_log_id_of_newest_edited_entry,
            sum(b.correction) OVER origin_group AS correction
           FROM (bookings b
             LEFT JOIN public.stock_entry_origins seo ON ((seo.stock_id = b.stock_id)))
          WINDOW origin_group AS (PARTITION BY (COALESCE(seo.origin_stock_id, b.stock_id) COLLATE "C"))
        )
 SELECT DISTINCT stock_id,
    stock_log_id_of_newest_edited_entry,
    (origin_amount + correction) AS edited_origin_amount
   FROM keyed k
  WHERE ((origin_amount IS NOT NULL) AND (stock_log_id_of_newest_edited_entry IS NOT NULL));


--
-- Name: products_average_price; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_average_price AS
 SELECT 1 AS id,
    product_id,
    (sum((COALESCE(edited_origin_amount, amount) * price)) / sum(COALESCE(edited_origin_amount, amount))) AS price
   FROM ( SELECT sl_1.id,
            sl_1.product_id,
            sl_1.amount,
            sl_1.best_before_date,
            sl_1.purchased_date,
            sl_1.used_date,
            sl_1.spoiled,
            sl_1.stock_id,
            sl_1.transaction_type,
            sl_1.price,
            sl_1.undone,
            sl_1.undone_timestamp,
            sl_1.opened_date,
            sl_1.location_id,
            sl_1.recipe_id,
            sl_1.correlation_id,
            sl_1.transaction_id,
            sl_1.stock_row_id,
            sl_1.shopping_location_id,
            sl_1.user_id,
            sl_1.row_created_timestamp,
            sl_1.note,
                CASE
                    WHEN (sl_1.transaction_type = 'stock-edit-new'::text) THEN see.edited_origin_amount
                    ELSE NULL::double precision
                END AS edited_origin_amount
           FROM (public.stock_log sl_1
             LEFT JOIN public.stock_edited_entries see ON ((sl_1.stock_id = see.stock_id)))) sl
  WHERE ((undone = 0) AND (((transaction_type = ANY (ARRAY['purchase'::text, 'inventory-correction'::text, 'self-production'::text])) AND (NOT (stock_id IN ( SELECT stock_edited_entries.stock_id
           FROM public.stock_edited_entries)))) OR ((transaction_type = 'stock-edit-new'::text) AND (id IN ( SELECT stock_edited_entries.stock_log_id_of_newest_edited_entry
           FROM public.stock_edited_entries)))) AND (COALESCE(price, (0)::double precision) > (0)::double precision) AND (COALESCE(amount, (0)::double precision) > (0)::double precision))
  GROUP BY product_id;


--
-- Name: stock_next_use; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_next_use AS
 SELECT ((row_number() OVER (PARTITION BY s.product_id ORDER BY
        CASE
            WHEN (COALESCE(p.default_consume_location_id, '-1'::integer) = s.location_id) THEN 0
            ELSE 1
        END, s.open DESC, s.best_before_date, s.purchased_date))::integer * '-1'::integer) AS priority,
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
    s.opened_amount,
    s.opened_qu_id,
    s.opened_tare,
    s.opened_measured_at
   FROM (public.stock s
     JOIN public.products p ON ((p.id = s.product_id)))
  ORDER BY
        CASE
            WHEN (COALESCE(p.default_consume_location_id, '-1'::integer) = s.location_id) THEN 0
            ELSE 1
        END, s.open DESC, s.best_before_date, s.purchased_date;


--
-- Name: products_current_price; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_current_price AS
 SELECT '-1'::integer AS id,
    p.id AS product_id,
    COALESCE(snu.price, plp.price) AS price
   FROM ((public.products p
     LEFT JOIN ( SELECT DISTINCT ON (stock_next_use.product_id) stock_next_use.product_id,
            stock_next_use.price
           FROM public.stock_next_use
          ORDER BY stock_next_use.product_id, stock_next_use.priority DESC, stock_next_use.open DESC, stock_next_use.best_before_date, stock_next_use.purchased_date) snu ON ((p.id = snu.product_id)))
     LEFT JOIN public.cache__products_last_purchased plp ON ((p.id = plp.product_id)));


--
-- Name: products_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_view AS
 SELECT p.id,
    p.name,
    p.description,
    p.product_group_id,
    p.active,
    p.location_id,
    p.shopping_location_id,
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
    p.row_created_timestamp,
    p.qu_id_consume,
    p.auto_reprint_stock_label,
    p.quick_open_amount,
    p.qu_id_price,
    p.disable_open,
    p.default_purchase_price_type,
        CASE
            WHEN (EXISTS ( SELECT 1
               FROM public.products
              WHERE (products.parent_product_id = p.id))) THEN 1
            ELSE 0
        END AS has_sub_products,
    COALESCE((quc_purchase.factor)::double precision, (1.0)::double precision) AS qu_factor_purchase_to_stock,
    COALESCE((quc_consume.factor)::double precision, (1.0)::double precision) AS qu_factor_consume_to_stock,
    COALESCE((quc_price.factor)::double precision, (1.0)::double precision) AS qu_factor_price_to_stock
   FROM (((public.products p
     LEFT JOIN public.cache__quantity_unit_conversions_resolved quc_purchase ON (((p.id = quc_purchase.product_id) AND (p.qu_id_purchase = quc_purchase.from_qu_id) AND (p.qu_id_stock = quc_purchase.to_qu_id))))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved quc_consume ON (((p.id = quc_consume.product_id) AND (p.qu_id_consume = quc_consume.from_qu_id) AND (p.qu_id_stock = quc_consume.to_qu_id))))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved quc_price ON (((p.id = quc_price.product_id) AND (p.qu_id_price = quc_price.from_qu_id) AND (p.qu_id_stock = quc_price.to_qu_id))));


--
-- Name: products_current_substitutions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_current_substitutions AS
 SELECT '-1'::integer AS "-1",
    p_sub.id AS parent_product_id,
        CASE
            WHEN (p_sub.has_sub_products = 1) THEN
            CASE
                WHEN (COALESCE(sc.amount, (0)::double precision) = (0)::double precision) THEN ( SELECT x_snu.product_id
                   FROM (((public.products_resolved x_pr
                     JOIN public.stock_next_use x_snu ON ((x_pr.sub_product_id = x_snu.product_id)))
                     JOIN public.products x_p_sub ON ((x_p_sub.id = x_pr.sub_product_id)))
                     JOIN public.cache__quantity_unit_conversions_resolved x_qucr ON (((x_qucr.product_id = x_pr.sub_product_id) AND (x_qucr.from_qu_id = p_sub.qu_id_stock) AND (x_qucr.to_qu_id = x_p_sub.qu_id_stock) AND ((x_qucr.factor)::numeric > (0)::numeric))))
                  WHERE ((x_pr.parent_product_id = p_sub.id) AND (x_pr.parent_product_id <> x_pr.sub_product_id))
                  ORDER BY x_snu.priority DESC, x_snu.open DESC, x_snu.best_before_date, x_snu.purchased_date
                 LIMIT 1)
                ELSE p_sub.id
            END
            ELSE NULL::integer
        END AS product_id_effective
   FROM (((public.products_view p
     JOIN public.products_resolved pr ON ((p.id = pr.parent_product_id)))
     JOIN public.products_view p_sub ON ((pr.sub_product_id = p_sub.id)))
     JOIN public.stock_current sc ON ((p_sub.id = sc.product_id)))
  WHERE (p_sub.has_sub_products = 1);


--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.products ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: products_price_history; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_price_history AS
 SELECT product_id AS id,
    product_id,
    price,
    COALESCE(edited_origin_amount, amount) AS amount,
    purchased_date,
    shopping_location_id,
    transaction_type,
    id AS stock_log_id
   FROM ( SELECT sl_1.id,
            sl_1.product_id,
            sl_1.amount,
            sl_1.best_before_date,
            sl_1.purchased_date,
            sl_1.used_date,
            sl_1.spoiled,
            sl_1.stock_id,
            sl_1.transaction_type,
            sl_1.price,
            sl_1.undone,
            sl_1.undone_timestamp,
            sl_1.opened_date,
            sl_1.location_id,
            sl_1.recipe_id,
            sl_1.correlation_id,
            sl_1.transaction_id,
            sl_1.stock_row_id,
            sl_1.shopping_location_id,
            sl_1.user_id,
            sl_1.row_created_timestamp,
            sl_1.note,
                CASE
                    WHEN (sl_1.transaction_type = 'stock-edit-new'::text) THEN see.edited_origin_amount
                    ELSE NULL::double precision
                END AS edited_origin_amount
           FROM (public.stock_log sl_1
             LEFT JOIN public.stock_edited_entries see ON ((sl_1.stock_id = see.stock_id)))) sl
  WHERE ((undone = 0) AND (((transaction_type = ANY (ARRAY['purchase'::text, 'inventory-correction'::text, 'self-production'::text])) AND (NOT (stock_id IN ( SELECT stock_edited_entries.stock_id
           FROM public.stock_edited_entries)))) OR ((transaction_type = 'stock-edit-new'::text) AND (id IN ( SELECT stock_edited_entries.stock_log_id_of_newest_edited_entry
           FROM public.stock_edited_entries)))) AND (COALESCE(price, (0)::double precision) > (0)::double precision) AND (COALESCE(amount, (0)::double precision) > (0)::double precision));


--
-- Name: products_last_purchased; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_last_purchased AS
 SELECT 1 AS id,
    sl.product_id,
    sl.amount,
    sl.best_before_date,
    sl.purchased_date,
    sl.location_id,
    sl.shopping_location_id,
    COALESCE(( SELECT products_price_history.price
           FROM public.products_price_history
          WHERE (products_price_history.product_id = sl.product_id)
          ORDER BY products_price_history.purchased_date DESC, products_price_history.stock_log_id DESC
         LIMIT 1), (0)::double precision) AS price
   FROM (public.stock_log sl
     JOIN ( SELECT sl1.product_id,
            max(sl1.id) AS stock_log_id_of_last_purchase
           FROM (public.stock_log sl1
             JOIN ( SELECT sl2.product_id,
                    max(sl2.purchased_date) AS last_purchased_date
                   FROM public.stock_log sl2
                  WHERE ((sl2.undone = 0) AND (((sl2.transaction_type = ANY (ARRAY['purchase'::text, 'inventory-correction'::text, 'self-production'::text])) AND (NOT (sl2.stock_id IN ( SELECT stock_edited_entries.stock_id
                           FROM public.stock_edited_entries)))) OR ((sl2.transaction_type = 'stock-edit-new'::text) AND (sl2.stock_id IN ( SELECT stock_edited_entries.stock_id
                           FROM public.stock_edited_entries)) AND (sl2.id IN ( SELECT stock_edited_entries.stock_log_id_of_newest_edited_entry
                           FROM public.stock_edited_entries)))))
                  GROUP BY sl2.product_id) x2 ON (((sl1.product_id = x2.product_id) AND (sl1.purchased_date = x2.last_purchased_date))))
          WHERE ((sl1.undone = 0) AND (((sl1.transaction_type = ANY (ARRAY['purchase'::text, 'inventory-correction'::text, 'self-production'::text])) AND (NOT (sl1.stock_id IN ( SELECT stock_edited_entries.stock_id
                   FROM public.stock_edited_entries)))) OR ((sl1.transaction_type = 'stock-edit-new'::text) AND (sl1.stock_id IN ( SELECT stock_edited_entries.stock_id
                   FROM public.stock_edited_entries)) AND (sl1.id IN ( SELECT stock_edited_entries.stock_log_id_of_newest_edited_entry
                   FROM public.stock_edited_entries)))))
          GROUP BY sl1.product_id) x ON (((sl.product_id = x.product_id) AND (sl.id = x.stock_log_id_of_last_purchase))));


--
-- Name: stock_missing_products; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_missing_products AS
 SELECT id,
    name,
    amount_missing,
    is_partly_in_stock
   FROM ( SELECT p.id,
            max(p.name) AS name,
            ((p.min_stock_amount - COALESCE(sum(s.amount), (0)::double precision)) +
                CASE
                    WHEN (p.treat_opened_as_out_of_stock = 1) THEN COALESCE(sum(s.amount_opened), (0)::double precision)
                    ELSE (0)::double precision
                END) AS amount_missing,
                CASE
                    WHEN (COALESCE(sum(s.amount), (0)::double precision) > (0)::double precision) THEN 1
                    ELSE 0
                END AS is_partly_in_stock
           FROM (public.products_view p
             LEFT JOIN public.stock_current s ON ((p.id = s.product_id)))
          WHERE ((p.min_stock_amount <> (0)::double precision) AND (p.cumulate_min_stock_amount_of_sub_products = 0) AND (p.has_sub_products = 0) AND (p.parent_product_id IS NULL) AND (COALESCE((p.active)::integer, 0) = 1))
          GROUP BY p.id, p.min_stock_amount, p.treat_opened_as_out_of_stock
        UNION
         SELECT p.id,
            max(p.name) AS name,
            ((sum(sub_p.min_stock_amount) - COALESCE(sum(s.amount_aggregated), (0)::double precision)) +
                CASE
                    WHEN (p.treat_opened_as_out_of_stock = 1) THEN COALESCE(sum(s.amount_opened_aggregated), (0)::double precision)
                    ELSE (0)::double precision
                END) AS amount_missing,
                CASE
                    WHEN (COALESCE(sum(s.amount), (0)::double precision) > (0)::double precision) THEN 1
                    ELSE 0
                END AS is_partly_in_stock
           FROM (((public.products_view p
             JOIN public.products_resolved pr ON ((p.id = pr.parent_product_id)))
             JOIN public.products sub_p ON ((pr.sub_product_id = sub_p.id)))
             LEFT JOIN public.stock_current s ON ((pr.sub_product_id = s.product_id)))
          WHERE ((sub_p.min_stock_amount <> (0)::double precision) AND (p.cumulate_min_stock_amount_of_sub_products = 1) AND (COALESCE((p.active)::integer, 0) = 1))
          GROUP BY p.id, p.treat_opened_as_out_of_stock
        UNION
         SELECT sub_p.id,
            max(sub_p.name) AS name,
            ((sum(sub_p.min_stock_amount) - COALESCE(sum(s.amount_aggregated), (0)::double precision)) +
                CASE
                    WHEN (p.treat_opened_as_out_of_stock = 1) THEN COALESCE(sum(s.amount_opened_aggregated), (0)::double precision)
                    ELSE (0)::double precision
                END) AS amount_missing,
                CASE
                    WHEN (COALESCE(sum(s.amount), (0)::double precision) > (0)::double precision) THEN 1
                    ELSE 0
                END AS is_partly_in_stock
           FROM (((public.products p
             JOIN public.products_resolved pr ON ((p.id = pr.parent_product_id)))
             JOIN public.products sub_p ON ((pr.sub_product_id = sub_p.id)))
             LEFT JOIN public.stock_current s ON ((pr.sub_product_id = s.product_id)))
          WHERE ((sub_p.min_stock_amount <> (0)::double precision) AND (p.cumulate_min_stock_amount_of_sub_products = 0) AND (COALESCE((p.active)::integer, 0) = 1))
          GROUP BY sub_p.id, p.treat_opened_as_out_of_stock) x
  WHERE (amount_missing > (0)::double precision);


--
-- Name: products_volatile_status; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.products_volatile_status AS
 SELECT '-1'::integer AS id,
    p.id AS product_id,
    p.name AS product_name,
        CASE
            WHEN ((EXTRACT(epoch FROM ((sc.best_before_date)::timestamp without time zone - date_trunc('second'::text, LOCALTIMESTAMP))) / 86400.0) < (0)::numeric) THEN
            CASE
                WHEN (p.due_type = 1) THEN 'overdue'::text
                ELSE 'expired'::text
            END
            ELSE
            CASE
                WHEN ((EXTRACT(epoch FROM ((sc.best_before_date)::timestamp without time zone - date_trunc('second'::text, LOCALTIMESTAMP))) / 86400.0) < ((public.victual_user_setting('stock_due_soon_days'::text))::integer)::numeric) THEN 'due_soon'::text
                ELSE 'ok'::text
            END
        END AS current_due_status,
        CASE
            WHEN (smp.id IS NOT NULL) THEN 1
            ELSE 0
        END AS is_currently_below_min_stock_amount
   FROM ((public.products p
     LEFT JOIN public.stock_current sc ON ((p.id = sc.product_id)))
     LEFT JOIN public.stock_missing_products smp ON ((p.id = smp.id)));


--
-- Name: quantity_unit_conversions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.quantity_unit_conversions ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.quantity_unit_conversions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: quantity_unit_conversions_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.quantity_unit_conversions_resolved AS
 WITH RECURSIVE default_conversions(from_qu_id, to_qu_id, factor) AS (
         SELECT quantity_unit_conversions.from_qu_id,
            quantity_unit_conversions.to_qu_id,
            quantity_unit_conversions.factor
           FROM public.quantity_unit_conversions
          WHERE (quantity_unit_conversions.product_id IS NULL)
        ), default_closure(depth, from_qu_id, to_qu_id, factor, path) AS (
         SELECT 1 AS depth,
            default_conversions.from_qu_id,
            default_conversions.to_qu_id,
            default_conversions.factor,
            (((('/'::text || (default_conversions.from_qu_id)::text) || '/'::text) || (default_conversions.to_qu_id)::text) || '/'::text) AS "?column?"
           FROM default_conversions
        UNION
         SELECT (c_1.depth + 1),
            c_1.from_qu_id,
            s.to_qu_id,
            (c_1.factor * s.factor),
            ((c_1.path || (s.to_qu_id)::text) || '/'::text)
           FROM (default_closure c_1
             JOIN default_conversions s ON ((c_1.to_qu_id = s.from_qu_id)))
          WHERE ((c_1.path !~~ (('%/'::text || (s.to_qu_id)::text) || '/%'::text)) AND (NOT (EXISTS ( SELECT 1
                   FROM default_conversions ci
                  WHERE ((ci.from_qu_id = c_1.from_qu_id) AND (ci.to_qu_id = s.to_qu_id))))))
        ), default_closure_distinct(from_qu_id, to_qu_id, factor, path) AS (
         SELECT DISTINCT ON (default_closure.from_qu_id, default_closure.to_qu_id) default_closure.from_qu_id,
            default_closure.to_qu_id,
            default_closure.factor,
            default_closure.path
           FROM default_closure
          ORDER BY default_closure.from_qu_id, default_closure.to_qu_id, default_closure.depth
        ), product_conversions(product_id, from_qu_id, to_qu_id, factor) AS (
         SELECT quantity_unit_conversions.product_id,
            quantity_unit_conversions.from_qu_id,
            quantity_unit_conversions.to_qu_id,
            quantity_unit_conversions.factor
           FROM public.quantity_unit_conversions
          WHERE (quantity_unit_conversions.product_id IS NOT NULL)
        UNION
         SELECT products.id,
            products.qu_id_stock,
            products.qu_id_stock,
            (1.0)::double precision AS float8
           FROM public.products
        ), product_closure(depth, product_id, from_qu_id, to_qu_id, factor, path) AS (
         SELECT 1 AS depth,
            product_conversions.product_id,
            product_conversions.from_qu_id,
            product_conversions.to_qu_id,
            product_conversions.factor,
            (((('/'::text || (product_conversions.from_qu_id)::text) || '/'::text) || (product_conversions.to_qu_id)::text) || '/'::text) AS "?column?"
           FROM product_conversions
        UNION
         SELECT (c_1.depth + 1),
            c_1.product_id,
            c_1.from_qu_id,
            s.to_qu_id,
            (c_1.factor * s.factor),
            ((c_1.path || (s.to_qu_id)::text) || '/'::text)
           FROM (product_closure c_1
             JOIN product_conversions s ON (((c_1.product_id = s.product_id) AND (c_1.to_qu_id = s.from_qu_id))))
          WHERE ((c_1.path !~~ (('%/'::text || (s.to_qu_id)::text) || '/%'::text)) AND (NOT (EXISTS ( SELECT 1
                   FROM product_conversions ci
                  WHERE ((ci.product_id = c_1.product_id) AND (ci.from_qu_id = c_1.from_qu_id) AND (ci.to_qu_id = s.to_qu_id))))))
        ), product_closure_distinct(product_id, from_qu_id, to_qu_id, factor, path) AS (
         SELECT DISTINCT ON (product_closure.product_id, product_closure.from_qu_id, product_closure.to_qu_id) product_closure.product_id,
            product_closure.from_qu_id,
            product_closure.to_qu_id,
            product_closure.factor,
            product_closure.path
           FROM product_closure
          ORDER BY product_closure.product_id, product_closure.from_qu_id, product_closure.to_qu_id, product_closure.depth
        ), product_reachable(product_id, from_qu_id, to_qu_id, factor, path) AS (
         SELECT product_closure_distinct.product_id,
            product_closure_distinct.from_qu_id,
            product_closure_distinct.to_qu_id,
            product_closure_distinct.factor,
            product_closure_distinct.path
           FROM product_closure_distinct
        UNION
         SELECT cd.product_id,
            dcd.from_qu_id,
            dcd.to_qu_id,
            dcd.factor,
            (((('/'::text || (dcd.from_qu_id)::text) || '/'::text) || (dcd.to_qu_id)::text) || '/'::text)
           FROM (product_closure_distinct cd
             JOIN default_closure_distinct dcd ON (((cd.to_qu_id = dcd.from_qu_id) OR (cd.to_qu_id = dcd.to_qu_id))))
          WHERE (NOT (EXISTS ( SELECT 1
                   FROM product_closure_distinct ci
                  WHERE ((ci.product_id = cd.product_id) AND (ci.from_qu_id = dcd.from_qu_id) AND (ci.to_qu_id = dcd.to_qu_id)))))
        ), product_reachable_distinct(product_id, from_qu_id, to_qu_id, factor, path) AS (
         SELECT DISTINCT product_reachable.product_id,
            product_reachable.from_qu_id,
            product_reachable.to_qu_id,
            product_reachable.factor,
            product_reachable.path
           FROM product_reachable
        ), closure_final(depth, product_id, from_qu_id, to_qu_id, factor, path) AS (
         SELECT 1 AS depth,
            product_reachable_distinct.product_id,
            product_reachable_distinct.from_qu_id,
            product_reachable_distinct.to_qu_id,
            product_reachable_distinct.factor,
            product_reachable_distinct.path
           FROM product_reachable_distinct
        UNION
         SELECT (c_1.depth + 1),
            c_1.product_id,
            c_1.from_qu_id,
            s.to_qu_id,
            (c_1.factor * s.factor),
            ((c_1.path || (s.to_qu_id)::text) || '/'::text)
           FROM (closure_final c_1
             JOIN product_reachable_distinct s ON (((c_1.product_id = s.product_id) AND (c_1.to_qu_id = s.from_qu_id))))
          WHERE ((c_1.path !~~ (('%/'::text || (s.to_qu_id)::text) || '/%'::text)) AND (NOT (EXISTS ( SELECT 1
                   FROM product_reachable_distinct ci
                  WHERE ((ci.product_id = c_1.product_id) AND (ci.from_qu_id = c_1.from_qu_id) AND (ci.to_qu_id = s.to_qu_id))))))
        )
 SELECT DISTINCT ON (c.product_id, c.from_qu_id, c.to_qu_id) '-1'::integer AS id,
    c.product_id,
    c.from_qu_id,
    qu_from.name AS from_qu_name,
    qu_from.name_plural AS from_qu_name_plural,
    c.to_qu_id,
    qu_to.name AS to_qu_name,
    qu_to.name_plural AS to_qu_name_plural,
    c.factor,
    c.path
   FROM ((closure_final c
     JOIN public.quantity_units qu_from ON ((c.from_qu_id = qu_from.id)))
     JOIN public.quantity_units qu_to ON ((c.to_qu_id = qu_to.id)))
  ORDER BY c.product_id, c.from_qu_id, c.to_qu_id, c.depth;


--
-- Name: quantity_units_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.quantity_units ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.quantity_units_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recipes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.recipes ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.recipes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recipes_nestings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recipes_nestings (
    id integer NOT NULL,
    recipe_id integer NOT NULL,
    includes_recipe_id integer NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    servings double precision DEFAULT 1
);


--
-- Name: recipes_nestings_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.recipes_nestings_resolved AS
 WITH RECURSIVE r1(recipe_id, includes_recipe_id, includes_servings, level) AS (
         SELECT recipes.id AS recipe_id,
            recipes.id AS includes_recipe_id,
            (1)::double precision AS includes_servings,
            0 AS level
           FROM public.recipes
        UNION ALL
         SELECT rn.recipe_id,
            r1_1.includes_recipe_id,
            (rn.servings * r1_1.includes_servings) AS includes_servings,
            (r1_1.level + 1) AS level
           FROM public.recipes_nestings rn,
            r1 r1_1
          WHERE (rn.includes_recipe_id = r1_1.recipe_id)
        )
 SELECT recipe_id,
    includes_recipe_id,
    includes_servings,
    level,
    1 AS id
   FROM r1;


--
-- Name: recipes_pos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recipes_pos (
    id integer NOT NULL,
    recipe_id integer NOT NULL,
    product_id integer NOT NULL,
    amount double precision DEFAULT 0 NOT NULL,
    note text,
    qu_id integer,
    only_check_single_unit_in_stock smallint DEFAULT 0 NOT NULL,
    ingredient_group text,
    not_check_stock_fulfillment smallint DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    variable_amount text,
    price_factor double precision DEFAULT 1 NOT NULL,
    round_up smallint DEFAULT 0 NOT NULL,
    CONSTRAINT recipes_pos_round_up_check CHECK ((round_up = ANY (ARRAY[0, 1])))
);


--
-- Name: shopping_list; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shopping_list (
    id integer NOT NULL,
    product_id integer,
    note text,
    amount double precision DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    shopping_list_id integer DEFAULT 1,
    done integer DEFAULT 0,
    qu_id integer
);


--
-- Name: recipes_pos_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.recipes_pos_resolved AS
 SELECT r.id AS recipe_id,
    rp.id AS recipe_pos_id,
    rp.product_id,
        CASE
            WHEN (rp.round_up = 1) THEN ceil(
            CASE
                WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
            END)
            ELSE
            CASE
                WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
            END
        END AS recipe_amount,
    COALESCE(sc.amount_aggregated, (0)::double precision) AS stock_amount,
        CASE
            WHEN (COALESCE(sc.amount_aggregated, (0)::double precision) >=
            CASE
                WHEN (rp.only_check_single_unit_in_stock = 1) THEN (0.00000001)::double precision
                ELSE
                CASE
                    WHEN (rp.round_up = 1) THEN ceil(
                    CASE
                        WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                        ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                    END)
                    ELSE
                    CASE
                        WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                        ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                    END
                END
            END) THEN 1
            ELSE 0
        END AS need_fulfilled,
        CASE
            WHEN ((COALESCE(sc.amount_aggregated, (0)::double precision) -
            CASE
                WHEN (rp.only_check_single_unit_in_stock = 1) THEN (0.00000001)::double precision
                ELSE
                CASE
                    WHEN (rp.round_up = 1) THEN ceil(
                    CASE
                        WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                        ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                    END)
                    ELSE
                    CASE
                        WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                        ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                    END
                END
            END) < (0)::double precision) THEN abs((COALESCE(sc.amount_aggregated, (0)::double precision) -
            CASE
                WHEN (rp.round_up = 1) THEN ceil(
                CASE
                    WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                    ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                END)
                ELSE
                CASE
                    WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                    ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                END
            END))
            ELSE (0)::double precision
        END AS missing_amount,
    COALESCE(sl.amount, (0)::double precision) AS amount_on_shopping_list,
        CASE
            WHEN (round(((COALESCE(sc.amount_aggregated, (0)::double precision) +
            CASE
                WHEN (r.not_check_shoppinglist = 1) THEN (0)::double precision
                ELSE COALESCE(sl.amount, (0)::double precision)
            END))::numeric, 2) >= round((
            CASE
                WHEN (rp.only_check_single_unit_in_stock = 1) THEN (0.00000001)::double precision
                ELSE
                CASE
                    WHEN (rp.round_up = 1) THEN ceil(
                    CASE
                        WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                        ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                    END)
                    ELSE
                    CASE
                        WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                        ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
                    END
                END
            END)::numeric, 2)) THEN 1
            ELSE 0
        END AS need_fulfilled_with_shopping_list,
    rp.qu_id,
    (((((((((r.desired_servings * (1.0)::double precision) / r.base_servings) * (1.0)::double precision) *
        CASE
            WHEN (rp.only_check_single_unit_in_stock = 1) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1)::double precision
        END) * ((rnr.includes_servings * (1.0)::double precision) /
        CASE
            WHEN (rnr.recipe_id <> rnr.includes_recipe_id) THEN (rnrr.base_servings * (1.0)::double precision)
            ELSE (1)::double precision
        END)) * rp.amount) * COALESCE(pcp.price, (0)::double precision)) * rp.price_factor) *
        CASE
            WHEN (rp.product_id <> p_effective.id) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1.0)::double precision
        END) AS costs,
        CASE
            WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN 0
            ELSE 1
        END AS is_nested_recipe_pos,
    rp.ingredient_group,
    pg.name AS product_group,
    rp.id,
    r.type AS recipe_type,
    rnr.includes_recipe_id AS child_recipe_id,
    rp.note,
    rp.variable_amount AS recipe_variable_amount,
    rp.only_check_single_unit_in_stock,
    ((((((rp.amount *
        CASE
            WHEN (rp.only_check_single_unit_in_stock = 1) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1)::double precision
        END) / r.base_servings) * (1.0)::double precision) * ((rnr.includes_servings * (1.0)::double precision) /
        CASE
            WHEN (rnr.recipe_id <> rnr.includes_recipe_id) THEN (rnrr.base_servings * (1.0)::double precision)
            ELSE (1)::double precision
        END)) * COALESCE(p_effective.calories, (0)::double precision)) *
        CASE
            WHEN (rp.product_id <> p_effective.id) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1.0)::double precision
        END) AS calories,
    p.active AS product_active,
        CASE pvs.current_due_status
            WHEN 'ok'::text THEN 0
            WHEN 'due_soon'::text THEN 1
            WHEN 'overdue'::text THEN 10
            WHEN 'expired'::text THEN 20
            ELSE NULL::integer
        END AS due_score,
    COALESCE(pcs.product_id_effective, rp.product_id) AS product_id_effective,
    p.name AS product_name
   FROM ((((((((((((public.recipes r
     JOIN public.recipes_nestings_resolved rnr ON ((r.id = rnr.recipe_id)))
     JOIN public.recipes rnrr ON ((rnr.includes_recipe_id = rnrr.id)))
     JOIN public.recipes_pos rp ON ((rnr.includes_recipe_id = rp.recipe_id)))
     JOIN public.products p ON ((rp.product_id = p.id)))
     JOIN public.products_volatile_status pvs ON ((rp.product_id = pvs.product_id)))
     LEFT JOIN public.product_groups pg ON ((p.product_group_id = pg.id)))
     LEFT JOIN ( SELECT shopping_list.product_id,
            sum(shopping_list.amount) AS amount
           FROM public.shopping_list
          GROUP BY shopping_list.product_id) sl ON ((rp.product_id = sl.product_id)))
     LEFT JOIN public.stock_current sc ON ((rp.product_id = sc.product_id)))
     LEFT JOIN public.products_current_substitutions pcs ON ((rp.product_id = pcs.parent_product_id)))
     LEFT JOIN public.products_current_price pcp ON ((COALESCE(pcs.product_id_effective, rp.product_id) = pcp.product_id)))
     LEFT JOIN public.products p_effective ON ((COALESCE(pcs.product_id_effective, rp.product_id) = p_effective.id)))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved qucr ON (((COALESCE(pcs.product_id_effective, rp.product_id) = qucr.product_id) AND (
        CASE
            WHEN (rp.product_id <> p_effective.id) THEN p.qu_id_stock
            ELSE rp.qu_id
        END = qucr.from_qu_id) AND (COALESCE(p_effective.qu_id_stock, p.qu_id_stock) = qucr.to_qu_id))))
  WHERE (rp.not_check_stock_fulfillment = 0)
UNION
 SELECT r.id AS recipe_id,
    rp.id AS recipe_pos_id,
    rp.product_id,
        CASE
            WHEN (rp.round_up = 1) THEN ceil(
            CASE
                WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
            END)
            ELSE
            CASE
                WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN (rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision)))
                ELSE ((rp.amount * ((r.desired_servings * (1.0)::double precision) / (r.base_servings * (1.0)::double precision))) * ((rnr.includes_servings * (1.0)::double precision) / (rnrr.base_servings * (1.0)::double precision)))
            END
        END AS recipe_amount,
    COALESCE(sc.amount_aggregated, (0)::double precision) AS stock_amount,
    1 AS need_fulfilled,
    0 AS missing_amount,
    COALESCE(sl.amount, (0)::double precision) AS amount_on_shopping_list,
    1 AS need_fulfilled_with_shopping_list,
    rp.qu_id,
    (((((((((r.desired_servings * (1.0)::double precision) / r.base_servings) * (1.0)::double precision) *
        CASE
            WHEN (rp.only_check_single_unit_in_stock = 1) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1)::double precision
        END) * ((rnr.includes_servings * (1.0)::double precision) /
        CASE
            WHEN (rnr.recipe_id <> rnr.includes_recipe_id) THEN (rnrr.base_servings * (1.0)::double precision)
            ELSE (1)::double precision
        END)) * rp.amount) * COALESCE(pcp.price, (0)::double precision)) * rp.price_factor) *
        CASE
            WHEN (rp.product_id <> p_effective.id) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1.0)::double precision
        END) AS costs,
        CASE
            WHEN (rnr.recipe_id = rnr.includes_recipe_id) THEN 0
            ELSE 1
        END AS is_nested_recipe_pos,
    rp.ingredient_group,
    pg.name AS product_group,
    rp.id,
    r.type AS recipe_type,
    rnr.includes_recipe_id AS child_recipe_id,
    rp.note,
    rp.variable_amount AS recipe_variable_amount,
    rp.only_check_single_unit_in_stock,
    ((((((rp.amount *
        CASE
            WHEN (rp.only_check_single_unit_in_stock = 1) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1)::double precision
        END) / r.base_servings) * (1.0)::double precision) * ((rnr.includes_servings * (1.0)::double precision) /
        CASE
            WHEN (rnr.recipe_id <> rnr.includes_recipe_id) THEN (rnrr.base_servings * (1.0)::double precision)
            ELSE (1)::double precision
        END)) * COALESCE(p_effective.calories, (0)::double precision)) *
        CASE
            WHEN (rp.product_id <> p_effective.id) THEN COALESCE((qucr.factor)::double precision, (1.0)::double precision)
            ELSE (1.0)::double precision
        END) AS calories,
    p.active AS product_active,
        CASE pvs.current_due_status
            WHEN 'ok'::text THEN 0
            WHEN 'due_soon'::text THEN 1
            WHEN 'overdue'::text THEN 10
            WHEN 'expired'::text THEN 20
            ELSE NULL::integer
        END AS due_score,
    COALESCE(pcs.product_id_effective, rp.product_id) AS product_id_effective,
    p.name AS product_name
   FROM ((((((((((((public.recipes r
     JOIN public.recipes_nestings_resolved rnr ON ((r.id = rnr.recipe_id)))
     JOIN public.recipes rnrr ON ((rnr.includes_recipe_id = rnrr.id)))
     JOIN public.recipes_pos rp ON ((rnr.includes_recipe_id = rp.recipe_id)))
     JOIN public.products p ON ((rp.product_id = p.id)))
     JOIN public.products_volatile_status pvs ON ((rp.product_id = pvs.product_id)))
     LEFT JOIN public.product_groups pg ON ((p.product_group_id = pg.id)))
     LEFT JOIN ( SELECT shopping_list.product_id,
            sum(shopping_list.amount) AS amount
           FROM public.shopping_list
          GROUP BY shopping_list.product_id) sl ON ((rp.product_id = sl.product_id)))
     LEFT JOIN public.stock_current sc ON ((rp.product_id = sc.product_id)))
     LEFT JOIN public.products_current_substitutions pcs ON ((rp.product_id = pcs.parent_product_id)))
     LEFT JOIN public.products_current_price pcp ON ((COALESCE(pcs.product_id_effective, rp.product_id) = pcp.product_id)))
     LEFT JOIN public.products p_effective ON ((COALESCE(pcs.product_id_effective, rp.product_id) = p_effective.id)))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved qucr ON (((COALESCE(pcs.product_id_effective, rp.product_id) = qucr.product_id) AND (
        CASE
            WHEN (rp.product_id <> p_effective.id) THEN p.qu_id_stock
            ELSE rp.qu_id
        END = qucr.from_qu_id) AND (COALESCE(p_effective.qu_id_stock, p.qu_id_stock) = qucr.to_qu_id))))
  WHERE (rp.not_check_stock_fulfillment = 1);


--
-- Name: recipes_missing_product_counts; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.recipes_missing_product_counts AS
 SELECT recipe_id,
    (count(*))::integer AS missing_products_count
   FROM public.recipes_pos_resolved
  WHERE (need_fulfilled = 0)
  GROUP BY recipe_id;


--
-- Name: recipes_nestings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.recipes_nestings ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.recipes_nestings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recipes_pos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.recipes_pos ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.recipes_pos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recipes_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.recipes_resolved AS
SELECT
    NULL::integer AS id,
    NULL::integer AS recipe_id,
    NULL::integer AS need_fulfilled,
    NULL::integer AS need_fulfilled_with_shopping_list,
    NULL::integer AS missing_products_count,
    NULL::double precision AS costs,
    NULL::double precision AS costs_per_serving,
    NULL::double precision AS calories,
    NULL::integer AS due_score,
    NULL::text AS product_names_comma_separated,
    NULL::integer AS prices_incomplete;


--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_permissions (
    role_id integer NOT NULL,
    permission_id integer NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id integer NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    description text,
    builtin smallint DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT roles_builtin_check CHECK ((builtin = ANY (ARRAY[0, 1])))
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.roles ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id integer NOT NULL,
    session_key text NOT NULL,
    user_id integer NOT NULL,
    expires timestamp with time zone,
    last_used timestamp with time zone,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.sessions ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: shopping_list_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.shopping_list ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.shopping_list_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: shopping_lists; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shopping_lists (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    shopping_location_id integer
);


--
-- Name: shopping_lists_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.shopping_lists ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.shopping_lists_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: shopping_lists_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.shopping_lists_view AS
 SELECT id,
    name,
    description,
    row_created_timestamp,
    (COALESCE(( SELECT count(*) AS count
           FROM public.shopping_list
          WHERE (shopping_list.shopping_list_id = sl.id)), (0)::bigint))::integer AS item_count
   FROM public.shopping_lists sl;


--
-- Name: shopping_locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shopping_locations (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    active smallint DEFAULT 1 NOT NULL,
    CONSTRAINT shopping_locations_active_check CHECK ((active = ANY (ARRAY[0, 1])))
);


--
-- Name: shopping_locations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.shopping_locations ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.shopping_locations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: stock_average_product_shelf_life; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_average_product_shelf_life AS
 SELECT p.id,
        CASE
            WHEN (count(x.product_id) = 0) THEN ('-1'::integer)::double precision
            ELSE avg(x.shelf_life_days)
        END AS average_shelf_life_days
   FROM (public.products p
     LEFT JOIN ( SELECT sl_p.product_id,
            ((EXTRACT(epoch FROM ((sl_p.best_before_date)::timestamp without time zone - (sl_p.purchased_date)::timestamp without time zone)) / 86400.0))::double precision AS shelf_life_days
           FROM public.stock_log sl_p
          WHERE ((sl_p.undone = 0) AND (((sl_p.transaction_type = ANY (ARRAY['purchase'::text, 'inventory-correction'::text, 'self-production'::text])) AND (NOT (sl_p.stock_id IN ( SELECT stock_edited_entries.stock_id
                   FROM public.stock_edited_entries)))) OR ((sl_p.transaction_type = 'stock-edit-new'::text) AND (sl_p.stock_id IN ( SELECT stock_edited_entries.stock_id
                   FROM public.stock_edited_entries)))))) x ON ((p.id = x.product_id)))
  GROUP BY p.id;


--
-- Name: stock_booking_lots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_booking_lots (
    id integer NOT NULL,
    booking_id integer NOT NULL,
    lot_id integer,
    amount double precision NOT NULL,
    basis text NOT NULL,
    CONSTRAINT stock_booking_lots_amount_check CHECK (((amount <> (0)::double precision) AND (amount > '-Infinity'::double precision) AND (amount < 'Infinity'::double precision))),
    CONSTRAINT stock_booking_lots_basis_check CHECK ((basis = ANY (ARRAY['recorded'::text, 'derived'::text, 'unknown'::text]))),
    CONSTRAINT stock_booking_lots_pool_recorded CHECK (((lot_id IS NOT NULL) OR (basis = 'recorded'::text)))
);


--
-- Name: stock_booking_lots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.stock_booking_lots ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.stock_booking_lots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: stock_current_location_content; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_current_location_content AS
 SELECT location_id,
    product_id,
    sum(amount) AS amount,
    (round((sum((COALESCE(price, (0)::double precision) * amount)))::numeric, 2))::double precision AS value,
    min(best_before_date) AS best_before_date,
    COALESCE(( SELECT sum(stock.amount) AS sum
           FROM public.stock
          WHERE ((stock.product_id = sp.product_id) AND (stock.location_id = sp.location_id) AND (stock.open = 1))), (0)::double precision) AS amount_opened
   FROM ( SELECT COALESCE(s.location_id, p.location_id) AS location_id,
            s.product_id,
            s.amount,
            s.price,
            s.best_before_date
           FROM (public.stock s
             JOIN public.products p ON (((s.product_id = p.id) AND (p.active = 1))))) sp
  GROUP BY location_id, product_id;


--
-- Name: stock_current_locations; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_current_locations AS
 SELECT 1 AS id,
    s.product_id,
    sum(s.amount) AS amount,
    s.location_id,
    l.name AS location_name,
    l.is_freezer AS location_is_freezer
   FROM (public.stock s
     JOIN public.locations l ON ((s.location_id = l.id)))
  GROUP BY s.product_id, s.location_id, l.name, l.is_freezer;


--
-- Name: stock_entry_origins_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.stock_entry_origins ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.stock_entry_origins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: stock_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.stock ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.stock_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: stock_label_retirements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_label_retirements (
    id bigint NOT NULL,
    label_uid text NOT NULL,
    retired_at timestamp with time zone NOT NULL,
    snapshot jsonb NOT NULL,
    cause text NOT NULL,
    import_epoch bigint,
    booking_id bigint,
    product_id bigint,
    amount double precision,
    jobs_through_id bigint,
    revivable_until timestamp with time zone,
    outcome text,
    reason text,
    closed_at timestamp with time zone,
    revived_target_id bigint,
    CONSTRAINT stock_label_retirements_booking_iff_consumption CHECK (((cause = 'consumption'::text) = (booking_id IS NOT NULL))),
    CONSTRAINT stock_label_retirements_cause_check CHECK ((cause = ANY (ARRAY['consumption'::text, 'unproven'::text, 'legacy'::text]))),
    CONSTRAINT stock_label_retirements_closed_iff_outcome CHECK (((outcome IS NULL) = (closed_at IS NULL))),
    CONSTRAINT stock_label_retirements_consumption_complete CHECK (((cause <> 'consumption'::text) OR ((product_id IS NOT NULL) AND (amount IS NOT NULL) AND (import_epoch IS NOT NULL) AND (jobs_through_id IS NOT NULL) AND (revivable_until IS NOT NULL)))),
    CONSTRAINT stock_label_retirements_consumption_reason CHECK (((cause <> 'consumption'::text) OR (reason IS NULL) OR (reason <> ALL (ARRAY['unproven'::text, 'legacy'::text])))),
    CONSTRAINT stock_label_retirements_declined_iff_reason CHECK ((COALESCE((outcome = 'declined'::text), false) = (reason IS NOT NULL))),
    CONSTRAINT stock_label_retirements_epoch_unless_legacy CHECK (((cause = 'legacy'::text) OR (import_epoch IS NOT NULL))),
    CONSTRAINT stock_label_retirements_outcome_check CHECK ((outcome = ANY (ARRAY['revived'::text, 'declined'::text]))),
    CONSTRAINT stock_label_retirements_reason_check CHECK ((reason = ANY (ARRAY['expired'::text, 'label_live'::text, 'mismatch'::text, 'id_changed'::text, 'target_labelled'::text, 'unproven'::text, 'legacy'::text]))),
    CONSTRAINT stock_label_retirements_revived_iff_target CHECK ((COALESCE((outcome = 'revived'::text), false) = (revived_target_id IS NOT NULL))),
    CONSTRAINT stock_label_retirements_unprovable_closed CHECK (((cause = 'consumption'::text) OR COALESCE(((outcome = 'declined'::text) AND (reason = cause)), false)))
);


--
-- Name: stock_label_retirements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.stock_label_retirements ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.stock_label_retirements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: stock_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.stock_log ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.stock_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: stock_row_lots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_row_lots (
    id integer NOT NULL,
    stock_row_id integer NOT NULL,
    lot_id integer,
    amount double precision NOT NULL,
    basis text NOT NULL,
    CONSTRAINT stock_row_lots_amount_check CHECK (((amount > (0)::double precision) AND (amount < 'Infinity'::double precision))),
    CONSTRAINT stock_row_lots_basis_check CHECK ((basis = ANY (ARRAY['recorded'::text, 'derived'::text, 'unknown'::text]))),
    CONSTRAINT stock_row_lots_pool_iff_unknown CHECK (((lot_id IS NULL) = (basis = 'unknown'::text)))
);


--
-- Name: stock_row_lots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.stock_row_lots ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.stock_row_lots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: userfield_values; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.userfield_values (
    id integer NOT NULL,
    field_id integer NOT NULL,
    object_id text NOT NULL,
    value text NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: userfields; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.userfields (
    id integer NOT NULL,
    entity text NOT NULL,
    name text NOT NULL,
    caption text NOT NULL,
    type text NOT NULL,
    show_as_column_in_tables smallint DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    config text,
    sort_number integer,
    input_required smallint DEFAULT 0 NOT NULL,
    default_value text,
    CONSTRAINT userfields_input_required_check CHECK ((input_required = ANY (ARRAY[0, 1])))
);


--
-- Name: stock_splits; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.stock_splits AS
 SELECT product_id,
    sum(amount) AS total_amount,
    min(stock_id) AS stock_id_to_keep,
    max(id) AS id_to_keep,
    string_agg((id)::text, ','::text) AS id_group,
    string_agg(stock_id, ','::text) AS stock_id_group,
    min(id) AS id
   FROM public.stock s
  WHERE ((stock_id !~~ 'x%'::text) AND (opened_amount IS NULL) AND ((best_before_date IS NULL) OR (best_before_date = '2999-12-31'::date)) AND (NOT (EXISTS ( SELECT 1
           FROM public.userfield_values
          WHERE ((userfield_values.object_id = s.stock_id) AND (userfield_values.field_id IN ( SELECT userfields.id
                   FROM public.userfields
                  WHERE (userfields.entity = 'stock'::text))) AND (COALESCE(userfield_values.value, ''::text) <> ''::text))))) AND (NOT (EXISTS ( SELECT 1
           FROM public.labels
          WHERE ((labels.kind = 'stock_entry'::text) AND (labels.target_id = s.id) AND (labels.retired_at IS NULL))))))
  GROUP BY product_id, best_before_date, purchased_date, price, open, opened_date, location_id, shopping_location_id, COALESCE(note, ''::text)
 HAVING (count(*) > 1);


--
-- Name: storage_classes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.storage_classes (
    id integer NOT NULL,
    name text NOT NULL,
    min_temp_c numeric,
    max_temp_c numeric,
    treats_as_freezer smallint DEFAULT 0 NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    active smallint DEFAULT 1 NOT NULL,
    CONSTRAINT storage_classes_active_check CHECK ((active = ANY (ARRAY[0, 1]))),
    CONSTRAINT storage_classes_treats_as_freezer_check CHECK ((treats_as_freezer = ANY (ARRAY[0, 1])))
);


--
-- Name: storage_classes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.storage_classes ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.storage_classes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: system_db_changed_time; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_db_changed_time (
    id integer NOT NULL,
    changed_time timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: task_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.task_categories (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    active smallint DEFAULT 1 NOT NULL,
    CONSTRAINT task_categories_active_check CHECK ((active = ANY (ARRAY[0, 1])))
);


--
-- Name: task_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.task_categories ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.task_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tasks (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    due_date date,
    done smallint DEFAULT 0 NOT NULL,
    done_timestamp timestamp with time zone,
    category_id integer,
    assigned_to_user_id integer,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    CONSTRAINT tasks_done_check CHECK ((done = ANY (ARRAY[0, 1])))
);


--
-- Name: tasks_current; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.tasks_current AS
 SELECT id,
    name,
    description,
    due_date,
    done,
    done_timestamp,
    category_id,
    assigned_to_user_id,
    row_created_timestamp
   FROM public.tasks
  WHERE (done = 0);


--
-- Name: tasks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.tasks ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.tasks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: uihelper_product_details; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.uihelper_product_details AS
 SELECT p.id,
    plp.purchased_date AS last_purchased_date,
    plp.price AS last_purchased_price,
    plp.shopping_location_id AS last_purchased_shopping_location_id,
    pap.price AS average_price,
    sl.average_shelf_life_days,
    pcp.price AS current_price,
    last_used.used_date AS last_used_date,
    next_due.best_before_date AS next_due_date,
        CASE
            WHEN ((consume_count.amount IS NULL) OR (consume_count.amount = (0)::double precision)) THEN (0)::double precision
            ELSE COALESCE(((spoil_count.amount * (100.0)::double precision) / consume_count.amount), (0)::double precision)
        END AS spoil_rate,
    COALESCE((quc_purchase2stock.factor)::double precision, (1.0)::double precision) AS qu_factor_purchase_to_stock,
    COALESCE((quc_price2stock.factor)::double precision, (1.0)::double precision) AS qu_factor_price_to_stock,
        CASE
            WHEN (EXISTS ( SELECT 1
               FROM public.products px
              WHERE (px.parent_product_id = p.id))) THEN 1
            ELSE 0
        END AS has_childs
   FROM ((((((((((public.products p
     LEFT JOIN public.cache__products_last_purchased plp ON ((p.id = plp.product_id)))
     LEFT JOIN public.cache__products_average_price pap ON ((p.id = pap.product_id)))
     LEFT JOIN public.stock_average_product_shelf_life sl ON ((p.id = sl.id)))
     LEFT JOIN public.products_current_price pcp ON ((p.id = pcp.product_id)))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved quc_purchase2stock ON (((p.id = quc_purchase2stock.product_id) AND (p.qu_id_purchase = quc_purchase2stock.from_qu_id) AND (p.qu_id_stock = quc_purchase2stock.to_qu_id))))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved quc_price2stock ON (((p.id = quc_price2stock.product_id) AND (p.qu_id_price = quc_price2stock.from_qu_id) AND (p.qu_id_stock = quc_price2stock.to_qu_id))))
     LEFT JOIN ( SELECT stock_log.product_id,
            max(stock_log.used_date) AS used_date
           FROM public.stock_log
          WHERE ((stock_log.transaction_type = 'consume'::text) AND (stock_log.undone = 0))
          GROUP BY stock_log.product_id) last_used ON ((p.id = last_used.product_id)))
     LEFT JOIN ( SELECT stock.product_id,
            min(stock.best_before_date) AS best_before_date
           FROM public.stock
          GROUP BY stock.product_id) next_due ON ((p.id = next_due.product_id)))
     LEFT JOIN ( SELECT stock_log.product_id,
            sum(stock_log.amount) AS amount
           FROM public.stock_log
          WHERE ((stock_log.transaction_type = 'consume'::text) AND (stock_log.undone = 0))
          GROUP BY stock_log.product_id) consume_count ON ((p.id = consume_count.product_id)))
     LEFT JOIN ( SELECT stock_log.product_id,
            sum(stock_log.amount) AS amount
           FROM public.stock_log
          WHERE ((stock_log.transaction_type = 'consume'::text) AND (stock_log.undone = 0) AND (stock_log.spoiled = 1))
          GROUP BY stock_log.product_id) spoil_count ON ((p.id = spoil_count.product_id)));


--
-- Name: uihelper_shopping_list; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.uihelper_shopping_list AS
 SELECT sl.id,
    sl.product_id,
    sl.note,
    sl.amount,
    sl.row_created_timestamp,
    sl.shopping_list_id,
    sl.done,
    sl.qu_id,
    p.name AS product_name,
    (plp.price * COALESCE((quc.factor)::double precision, (1.0)::double precision)) AS last_price_unit,
    (plp.price * sl.amount) AS last_price_total,
    plp.price,
    st.name AS default_shopping_location_name,
    qu.name AS qu_name,
    qu.name_plural AS qu_name_plural,
    pg.id AS product_group_id,
    pg.name AS product_group_name,
    pbcs.barcodes AS product_barcodes
   FROM (((((((public.shopping_list sl
     LEFT JOIN public.products p ON ((sl.product_id = p.id)))
     LEFT JOIN public.cache__products_last_purchased plp ON ((sl.product_id = plp.product_id)))
     LEFT JOIN public.shopping_locations st ON ((p.shopping_location_id = st.id)))
     LEFT JOIN public.quantity_units qu ON ((sl.qu_id = qu.id)))
     LEFT JOIN public.product_groups pg ON ((p.product_group_id = pg.id)))
     LEFT JOIN public.cache__quantity_unit_conversions_resolved quc ON (((p.id = quc.product_id) AND (p.qu_id_stock = quc.to_qu_id) AND (sl.qu_id = quc.from_qu_id))))
     LEFT JOIN public.product_barcodes_comma_separated pbcs ON ((sl.product_id = pbcs.product_id)));


--
-- Name: uihelper_stock_current_overview; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.uihelper_stock_current_overview AS
 SELECT p.id,
    sc.amount_opened,
    p.tare_weight,
    p.enable_tare_weight_handling,
    sc.amount,
    sc.value,
    sc.product_id,
    COALESCE(sc.best_before_date, '2888-12-31'::date) AS best_before_date,
        CASE
            WHEN (EXISTS ( SELECT stock_missing_products.id
               FROM public.stock_missing_products
              WHERE (stock_missing_products.id = sc.product_id))) THEN 1
            ELSE 0
        END AS product_missing,
    p.name AS product_name,
    pg.name AS product_group_name,
    sl.name AS default_store_name,
        CASE
            WHEN (EXISTS ( SELECT shopping_list.id,
                shopping_list.product_id,
                shopping_list.note,
                shopping_list.amount,
                shopping_list.row_created_timestamp,
                shopping_list.shopping_list_id,
                shopping_list.done,
                shopping_list.qu_id
               FROM public.shopping_list
              WHERE (shopping_list.product_id = sc.product_id))) THEN 1
            ELSE 0
        END AS on_shopping_list,
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
    (sc.amount * p.calories) AS calories,
    (sc.amount_aggregated * p.calories) AS calories_aggregated,
    p.quick_consume_amount,
    (p.quick_consume_amount / p.qu_factor_consume_to_stock) AS quick_consume_amount_qu_consume,
    p.quick_open_amount,
    (p.quick_open_amount / p.qu_factor_consume_to_stock) AS quick_open_amount_qu_consume,
    p.due_type,
    plp.purchased_date AS last_purchased,
    plp.price AS last_price,
    pap.price AS average_price,
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
   FROM (((((((((((((( SELECT stock_current.product_id,
            stock_current.amount,
            stock_current.amount_aggregated,
            stock_current.value,
            stock_current.best_before_date,
            stock_current.amount_opened,
            stock_current.amount_opened_aggregated,
            stock_current.is_aggregated_amount,
            stock_current.due_type,
            stock_current.amount_measured,
            1 AS is_in_stock_or_below_min_stock
           FROM public.stock_current
          WHERE (stock_current.best_before_date IS NOT NULL)
        UNION
         SELECT m.id,
            0,
            0,
            0,
            NULL::date AS date,
            0,
            0,
            0,
            p_1.due_type,
            0,
            1 AS is_in_stock_or_below_min_stock
           FROM (public.stock_missing_products m
             JOIN public.products p_1 ON ((m.id = p_1.id)))
          WHERE (NOT (m.id IN ( SELECT stock_current.product_id
                   FROM public.stock_current)))
        UNION
         SELECT p2.id,
            0,
            0,
            0,
            NULL::date AS date,
            0,
            0,
            0,
            p2.due_type,
            0,
            0 AS is_in_stock_or_below_min_stock
           FROM public.products p2
          WHERE ((p2.active = 1) AND (NOT (p2.id IN ( SELECT stock_current.product_id
                   FROM public.stock_current
                UNION
                 SELECT stock_missing_products.id
                   FROM public.stock_missing_products))))) sc
     JOIN public.products_view p ON ((sc.product_id = p.id)))
     JOIN public.locations l ON ((p.location_id = l.id)))
     JOIN public.quantity_units qu_stock ON ((p.qu_id_stock = qu_stock.id)))
     JOIN public.quantity_units qu_purchase ON ((p.qu_id_purchase = qu_purchase.id)))
     JOIN public.quantity_units qu_consume ON ((p.qu_id_consume = qu_consume.id)))
     JOIN public.quantity_units qu_price ON ((p.qu_id_price = qu_price.id)))
     LEFT JOIN public.product_groups pg ON ((p.product_group_id = pg.id)))
     LEFT JOIN public.shopping_locations sl ON ((p.shopping_location_id = sl.id)))
     LEFT JOIN public.cache__products_last_purchased plp ON ((sc.product_id = plp.product_id)))
     LEFT JOIN public.cache__products_average_price pap ON ((sc.product_id = pap.product_id)))
     LEFT JOIN public.product_barcodes_comma_separated pbcs ON ((sc.product_id = pbcs.product_id)))
     LEFT JOIN public.products p_parent ON ((p.parent_product_id = p_parent.id)))
     JOIN public.products p3 ON ((p3.id = p.id)))
  WHERE (p.hide_on_stock_overview = 0);


--
-- Name: uihelper_stock_entries; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.uihelper_stock_entries AS
 SELECT s.id,
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
   FROM (public.stock s
     JOIN public.products_view p ON ((s.product_id = p.id)));


--
-- Name: users_dto; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.users_dto AS
 SELECT id,
    username,
    first_name,
    last_name,
    row_created_timestamp,
        CASE
            WHEN ((COALESCE(first_name, ''::text) = ''::text) AND (COALESCE(last_name, ''::text) <> ''::text)) THEN last_name
            WHEN ((COALESCE(last_name, ''::text) = ''::text) AND (COALESCE(first_name, ''::text) <> ''::text)) THEN first_name
            WHEN ((COALESCE(last_name, ''::text) <> ''::text) AND (COALESCE(first_name, ''::text) <> ''::text)) THEN ((first_name || ' '::text) || last_name)
            ELSE username
        END AS display_name,
    picture_file_name
   FROM public.users;


--
-- Name: uihelper_stock_journal; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.uihelper_stock_journal AS
 SELECT sl.id,
    sl.row_created_timestamp,
    sl.correlation_id,
    sl.undone,
    sl.undone_timestamp,
    sl.transaction_type,
    sl.spoiled,
    sl.amount,
    sl.location_id,
    l.name AS location_name,
    p.name AS product_name,
    qu.name AS qu_name,
    qu.name_plural AS qu_name_plural,
    u.display_name AS user_display_name,
    p.id AS product_id,
    sl.note,
    sl.stock_id
   FROM ((((public.stock_log sl
     LEFT JOIN public.users_dto u ON ((sl.user_id = u.id)))
     JOIN public.products p ON ((sl.product_id = p.id)))
     LEFT JOIN public.locations l ON ((sl.location_id = l.id)))
     JOIN public.quantity_units qu ON ((p.qu_id_stock = qu.id)));


--
-- Name: uihelper_stock_journal_summary; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.uihelper_stock_journal_summary AS
 SELECT sl.user_id AS id,
    sl.user_id,
    u.display_name AS user_display_name,
    p.name AS product_name,
    sl.product_id,
    sl.transaction_type,
    qu.name AS qu_name,
    qu.name_plural AS qu_name_plural,
    sum(sl.amount) AS amount
   FROM (((public.stock_log sl
     JOIN public.users_dto u ON ((sl.user_id = u.id)))
     JOIN public.products p ON ((sl.product_id = p.id)))
     JOIN public.quantity_units qu ON ((p.qu_id_stock = qu.id)))
  WHERE (sl.undone = 0)
  GROUP BY sl.user_id, sl.product_id, sl.transaction_type, u.display_name, p.name, qu.name, qu.name_plural;


--
-- Name: user_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_permissions (
    id integer NOT NULL,
    permission_id integer NOT NULL,
    user_id integer NOT NULL
);


--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_roles (
    user_id integer NOT NULL,
    role_id integer NOT NULL
);


--
-- Name: user_permissions_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.user_permissions_resolved AS
 SELECT u.id,
    u.id AS user_id,
    pt.name AS permission_name
   FROM (public.permission_tree pt
     CROSS JOIN public.users u)
  WHERE (pt.id IN ( SELECT user_permissions.permission_id
           FROM public.user_permissions
          WHERE (user_permissions.user_id = u.id)
        UNION
         SELECT rp.permission_id
           FROM (public.user_roles ur
             JOIN public.role_permissions rp ON ((rp.role_id = ur.role_id)))
          WHERE (ur.user_id = u.id)));


--
-- Name: uihelper_user_permissions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.uihelper_user_permissions AS
 SELECT ph.id,
    u.id AS user_id,
    ph.name AS permission_name,
    ph.id AS permission_id,
        CASE
            WHEN (EXISTS ( SELECT 1
               FROM public.user_permissions_resolved pc
              WHERE ((pc.user_id = u.id) AND (pc.permission_name = ph.name)))) THEN 1
            ELSE 0
        END AS has_permission,
    ph.parent,
    ( SELECT string_agg(inherited.code, ','::text ORDER BY inherited.code) AS string_agg
           FROM ( SELECT DISTINCT r.code
                   FROM (((public.user_roles ur
                     JOIN public.roles r ON ((r.id = ur.role_id)))
                     JOIN public.role_permissions rp ON ((rp.role_id = r.id)))
                     JOIN public.permission_tree pt ON ((pt.id = rp.permission_id)))
                  WHERE ((ur.user_id = u.id) AND (pt.name = ph.name))) inherited) AS via_roles
   FROM (public.users u
     CROSS JOIN public.permission_hierarchy ph);


--
-- Name: user_permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.user_permissions ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.user_permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: user_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_settings (
    id integer NOT NULL,
    user_id integer NOT NULL,
    key text NOT NULL,
    value text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP),
    row_updated_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: user_settings_defaults; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_settings_defaults (
    key text NOT NULL,
    value text
);


--
-- Name: user_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.user_settings ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.user_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: userentities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.userentities (
    id integer NOT NULL,
    name text NOT NULL,
    caption text NOT NULL,
    description text,
    show_in_sidebar_menu smallint DEFAULT 1 NOT NULL,
    icon_css_class text,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: userentities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.userentities ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.userentities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: userfield_values_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.userfield_values ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.userfield_values_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: userfield_values_resolved; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.userfield_values_resolved AS
 SELECT u.id,
    u.entity,
    u.name,
    u.caption,
    u.type,
    u.show_as_column_in_tables,
    u.row_created_timestamp,
    u.config,
    uv.object_id,
    uv.value
   FROM (public.userfields u
     JOIN public.userfield_values uv ON ((u.id = uv.field_id)))
UNION
 SELECT u.id,
    'userobjects'::text AS entity,
    u.name,
    u.caption,
    u.type,
    u.show_as_column_in_tables,
    u.row_created_timestamp,
    u.config,
    uv.object_id,
    uv.value
   FROM (public.userfields u
     JOIN public.userfield_values uv ON ((u.id = uv.field_id)))
  WHERE (u.entity ~~ 'userentity-%'::text);


--
-- Name: userfields_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.userfields ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.userfields_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: userobjects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.userobjects (
    id integer NOT NULL,
    userentity_id integer NOT NULL,
    row_created_timestamp timestamp with time zone DEFAULT date_trunc('second'::text, CURRENT_TIMESTAMP)
);


--
-- Name: userobjects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.userobjects ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.userobjects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.users ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: api_keys api_keys_api_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_api_key_key UNIQUE (api_key);


--
-- Name: api_keys api_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_pkey PRIMARY KEY (id);


--
-- Name: batteries batteries_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batteries
    ADD CONSTRAINT batteries_name_key UNIQUE (name);


--
-- Name: batteries batteries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.batteries
    ADD CONSTRAINT batteries_pkey PRIMARY KEY (id);


--
-- Name: battery_charge_cycles battery_charge_cycles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.battery_charge_cycles
    ADD CONSTRAINT battery_charge_cycles_pkey PRIMARY KEY (id);


--
-- Name: cache__products_average_price cache__products_average_price_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache__products_average_price
    ADD CONSTRAINT cache__products_average_price_pkey PRIMARY KEY (id);


--
-- Name: cache__products_average_price cache__products_average_price_product_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache__products_average_price
    ADD CONSTRAINT cache__products_average_price_product_id_key UNIQUE (product_id);


--
-- Name: cache__products_last_purchased cache__products_last_purchased_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache__products_last_purchased
    ADD CONSTRAINT cache__products_last_purchased_pkey PRIMARY KEY (id);


--
-- Name: cache__products_last_purchased cache__products_last_purchased_product_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache__products_last_purchased
    ADD CONSTRAINT cache__products_last_purchased_product_id_key UNIQUE (product_id);


--
-- Name: cache__quantity_unit_conversions_resolved cache__quantity_unit_conversions_resolved_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache__quantity_unit_conversions_resolved
    ADD CONSTRAINT cache__quantity_unit_conversions_resolved_pkey PRIMARY KEY (id);


--
-- Name: chores_log chores_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chores_log
    ADD CONSTRAINT chores_log_pkey PRIMARY KEY (id);


--
-- Name: chores chores_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chores
    ADD CONSTRAINT chores_name_key UNIQUE (name);


--
-- Name: chores chores_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.chores
    ADD CONSTRAINT chores_pkey PRIMARY KEY (id);


--
-- Name: consumption_event_lines consumption_event_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_event_lines
    ADD CONSTRAINT consumption_event_lines_pkey PRIMARY KEY (id);


--
-- Name: consumption_events consumption_events_identity_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_identity_key UNIQUE (user_id, source_system, source_event_id);


--
-- Name: consumption_events consumption_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_pkey PRIMARY KEY (id);


--
-- Name: consumption_mappings consumption_mappings_identity_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_mappings
    ADD CONSTRAINT consumption_mappings_identity_key UNIQUE (user_id, source_system, medication_ref);


--
-- Name: consumption_mappings consumption_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_mappings
    ADD CONSTRAINT consumption_mappings_pkey PRIMARY KEY (id);


--
-- Name: consumption_recipe_lines consumption_recipe_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_lines
    ADD CONSTRAINT consumption_recipe_lines_pkey PRIMARY KEY (id);


--
-- Name: consumption_recipe_lines consumption_recipe_lines_recipe_position_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_lines
    ADD CONSTRAINT consumption_recipe_lines_recipe_position_key UNIQUE (recipe_id, "position");


--
-- Name: consumption_recipe_shares consumption_recipe_shares_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_shares
    ADD CONSTRAINT consumption_recipe_shares_pkey PRIMARY KEY (recipe_id, user_id);


--
-- Name: consumption_recipes consumption_recipes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipes
    ADD CONSTRAINT consumption_recipes_pkey PRIMARY KEY (id);


--
-- Name: consumption_refill_acks consumption_refill_acks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_acks
    ADD CONSTRAINT consumption_refill_acks_pkey PRIMARY KEY (user_id, recipe_id, kind, reorder_date);


--
-- Name: consumption_refill_dates consumption_refill_dates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_dates
    ADD CONSTRAINT consumption_refill_dates_pkey PRIMARY KEY (id);


--
-- Name: consumption_refill_fills consumption_refill_fills_id_recipe_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_fills
    ADD CONSTRAINT consumption_refill_fills_id_recipe_key UNIQUE (id, recipe_id);


--
-- Name: consumption_refill_fills consumption_refill_fills_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_fills
    ADD CONSTRAINT consumption_refill_fills_pkey PRIMARY KEY (id);


--
-- Name: consumption_refill_orders consumption_refill_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_orders
    ADD CONSTRAINT consumption_refill_orders_pkey PRIMARY KEY (id);


--
-- Name: consumption_refill_settings consumption_refill_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_settings
    ADD CONSTRAINT consumption_refill_settings_pkey PRIMARY KEY (recipe_id);


--
-- Name: equipment equipment_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.equipment
    ADD CONSTRAINT equipment_name_key UNIQUE (name);


--
-- Name: equipment equipment_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.equipment
    ADD CONSTRAINT equipment_pkey PRIMARY KEY (id);


--
-- Name: files files_file_group_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.files
    ADD CONSTRAINT files_file_group_name_key UNIQUE (file_group, name);


--
-- Name: files files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.files
    ADD CONSTRAINT files_pkey PRIMARY KEY (id);


--
-- Name: label_artifacts label_artifacts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_artifacts
    ADD CONSTRAINT label_artifacts_pkey PRIMARY KEY (id);


--
-- Name: label_assets label_assets_content_digest_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_assets
    ADD CONSTRAINT label_assets_content_digest_key UNIQUE (content_digest);


--
-- Name: label_assets label_assets_file_group_file_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_assets
    ADD CONSTRAINT label_assets_file_group_file_name_key UNIQUE (file_group, file_name);


--
-- Name: label_assets label_assets_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_assets
    ADD CONSTRAINT label_assets_name_key UNIQUE (name);


--
-- Name: label_assets label_assets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_assets
    ADD CONSTRAINT label_assets_pkey PRIMARY KEY (id);


--
-- Name: label_captures label_captures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_captures
    ADD CONSTRAINT label_captures_pkey PRIMARY KEY (id);


--
-- Name: label_drivers label_drivers_driver_id_schema_version_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_drivers
    ADD CONSTRAINT label_drivers_driver_id_schema_version_key UNIQUE (driver_id, schema_version);


--
-- Name: label_drivers label_drivers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_drivers
    ADD CONSTRAINT label_drivers_pkey PRIMARY KEY (id);


--
-- Name: label_idempotency_keys label_idempotency_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_idempotency_keys
    ADD CONSTRAINT label_idempotency_keys_pkey PRIMARY KEY (id);


--
-- Name: label_idempotency_keys label_idempotency_keys_principal_user_id_operation_idempote_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_idempotency_keys
    ADD CONSTRAINT label_idempotency_keys_principal_user_id_operation_idempote_key UNIQUE (principal_user_id, operation, idempotency_key);


--
-- Name: label_import_state label_import_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_import_state
    ADD CONSTRAINT label_import_state_pkey PRIMARY KEY (id);


--
-- Name: label_media_profiles label_media_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_media_profiles
    ADD CONSTRAINT label_media_profiles_pkey PRIMARY KEY (id);


--
-- Name: label_media_profiles label_media_profiles_profile_key_version_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_media_profiles
    ADD CONSTRAINT label_media_profiles_profile_key_version_key UNIQUE (profile_key, version);


--
-- Name: label_printer_status label_printer_status_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_printer_status
    ADD CONSTRAINT label_printer_status_pkey PRIMARY KEY (printer_id);


--
-- Name: label_printers label_printers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_printers
    ADD CONSTRAINT label_printers_pkey PRIMARY KEY (id);


--
-- Name: label_render_requests label_render_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_render_requests
    ADD CONSTRAINT label_render_requests_pkey PRIMARY KEY (id);


--
-- Name: label_template_drafts label_template_drafts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_template_drafts
    ADD CONSTRAINT label_template_drafts_pkey PRIMARY KEY (template_id);


--
-- Name: label_template_versions label_template_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_template_versions
    ADD CONSTRAINT label_template_versions_pkey PRIMARY KEY (id);


--
-- Name: label_template_versions label_template_versions_template_id_version_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_template_versions
    ADD CONSTRAINT label_template_versions_template_id_version_key UNIQUE (template_id, version);


--
-- Name: label_templates label_templates_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_templates
    ADD CONSTRAINT label_templates_name_key UNIQUE (name);


--
-- Name: label_templates label_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_templates
    ADD CONSTRAINT label_templates_pkey PRIMARY KEY (id);


--
-- Name: label_worker_capabilities label_worker_capabilities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_capabilities
    ADD CONSTRAINT label_worker_capabilities_pkey PRIMARY KEY (id);


--
-- Name: label_worker_capabilities label_worker_capabilities_worker_id_driver_id_schema_versio_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_capabilities
    ADD CONSTRAINT label_worker_capabilities_worker_id_driver_id_schema_versio_key UNIQUE (worker_id, driver_id, schema_version);


--
-- Name: label_worker_credentials label_worker_credentials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_credentials
    ADD CONSTRAINT label_worker_credentials_pkey PRIMARY KEY (api_key_id);


--
-- Name: label_worker_credentials label_worker_credentials_worker_id_rotation_request_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_credentials
    ADD CONSTRAINT label_worker_credentials_worker_id_rotation_request_id_key UNIQUE (worker_id, rotation_request_id);


--
-- Name: label_worker_sessions label_worker_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_sessions
    ADD CONSTRAINT label_worker_sessions_pkey PRIMARY KEY (id);


--
-- Name: label_workers label_workers_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_workers
    ADD CONSTRAINT label_workers_name_key UNIQUE (name);


--
-- Name: label_workers label_workers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_workers
    ADD CONSTRAINT label_workers_pkey PRIMARY KEY (id);


--
-- Name: labels labels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.labels
    ADD CONSTRAINT labels_pkey PRIMARY KEY (uid);


--
-- Name: locations locations_parent_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locations
    ADD CONSTRAINT locations_parent_name_key UNIQUE NULLS NOT DISTINCT (parent_location_id, name);


--
-- Name: locations locations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locations
    ADD CONSTRAINT locations_pkey PRIMARY KEY (id);


--
-- Name: login_attempts login_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_attempts
    ADD CONSTRAINT login_attempts_pkey PRIMARY KEY (id);


--
-- Name: meal_plan meal_plan_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meal_plan
    ADD CONSTRAINT meal_plan_pkey PRIMARY KEY (id);


--
-- Name: meal_plan_sections meal_plan_sections_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meal_plan_sections
    ADD CONSTRAINT meal_plan_sections_name_key UNIQUE (name);


--
-- Name: meal_plan_sections meal_plan_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meal_plan_sections
    ADD CONSTRAINT meal_plan_sections_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (migration);


--
-- Name: mqtt_product_entities mqtt_product_entities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mqtt_product_entities
    ADD CONSTRAINT mqtt_product_entities_pkey PRIMARY KEY (id);


--
-- Name: mqtt_product_entities mqtt_product_entities_product_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mqtt_product_entities
    ADD CONSTRAINT mqtt_product_entities_product_id_key UNIQUE (product_id);


--
-- Name: mqtt_published_entities mqtt_published_entities_object_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mqtt_published_entities
    ADD CONSTRAINT mqtt_published_entities_object_id_key UNIQUE (object_id);


--
-- Name: mqtt_published_entities mqtt_published_entities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mqtt_published_entities
    ADD CONSTRAINT mqtt_published_entities_pkey PRIMARY KEY (id);


--
-- Name: outbox outbox_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.outbox
    ADD CONSTRAINT outbox_pkey PRIMARY KEY (id);


--
-- Name: permission_fields permission_fields_permission_name_entity_field_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_fields
    ADD CONSTRAINT permission_fields_permission_name_entity_field_key UNIQUE (permission_name, entity, field);


--
-- Name: permission_fields permission_fields_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_fields
    ADD CONSTRAINT permission_fields_pkey PRIMARY KEY (id);


--
-- Name: permission_hierarchy permission_hierarchy_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_hierarchy
    ADD CONSTRAINT permission_hierarchy_name_key UNIQUE (name);


--
-- Name: permission_hierarchy permission_hierarchy_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_hierarchy
    ADD CONSTRAINT permission_hierarchy_pkey PRIMARY KEY (id);


--
-- Name: print_attempts print_attempts_outbox_id_attempt_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_attempts
    ADD CONSTRAINT print_attempts_outbox_id_attempt_number_key UNIQUE (outbox_id, attempt_number);


--
-- Name: print_attempts print_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_attempts
    ADD CONSTRAINT print_attempts_pkey PRIMARY KEY (id);


--
-- Name: print_evidence print_evidence_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_evidence
    ADD CONSTRAINT print_evidence_pkey PRIMARY KEY (id);


--
-- Name: print_evidence print_evidence_source_submission_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_evidence
    ADD CONSTRAINT print_evidence_source_submission_id_key UNIQUE (source, submission_id);


--
-- Name: print_jobs print_jobs_outbox_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_outbox_id_key UNIQUE (outbox_id);


--
-- Name: print_jobs print_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_pkey PRIMARY KEY (id);


--
-- Name: product_barcodes product_barcodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_barcodes
    ADD CONSTRAINT product_barcodes_pkey PRIMARY KEY (id);


--
-- Name: product_groups product_groups_parent_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_groups
    ADD CONSTRAINT product_groups_parent_name_key UNIQUE NULLS NOT DISTINCT (parent_product_group_id, name);


--
-- Name: product_groups product_groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_groups
    ADD CONSTRAINT product_groups_pkey PRIMARY KEY (id);


--
-- Name: product_location_min_stock product_location_min_stock_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_location_min_stock
    ADD CONSTRAINT product_location_min_stock_pkey PRIMARY KEY (id);


--
-- Name: product_location_min_stock product_location_min_stock_product_id_location_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_location_min_stock
    ADD CONSTRAINT product_location_min_stock_product_id_location_id_key UNIQUE (product_id, location_id);


--
-- Name: product_substitutions product_substitutions_pair_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_substitutions
    ADD CONSTRAINT product_substitutions_pair_key UNIQUE (from_product_id, to_product_id);


--
-- Name: product_substitutions product_substitutions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_substitutions
    ADD CONSTRAINT product_substitutions_pkey PRIMARY KEY (id);


--
-- Name: products products_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_name_key UNIQUE (name);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: quantity_unit_conversions quantity_unit_conversions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quantity_unit_conversions
    ADD CONSTRAINT quantity_unit_conversions_pkey PRIMARY KEY (id);


--
-- Name: quantity_units quantity_units_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quantity_units
    ADD CONSTRAINT quantity_units_name_key UNIQUE (name);


--
-- Name: quantity_units quantity_units_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quantity_units
    ADD CONSTRAINT quantity_units_pkey PRIMARY KEY (id);


--
-- Name: recipes_nestings recipes_nestings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recipes_nestings
    ADD CONSTRAINT recipes_nestings_pkey PRIMARY KEY (id);


--
-- Name: recipes_nestings recipes_nestings_recipe_id_includes_recipe_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recipes_nestings
    ADD CONSTRAINT recipes_nestings_recipe_id_includes_recipe_id_key UNIQUE (recipe_id, includes_recipe_id);


--
-- Name: recipes recipes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recipes
    ADD CONSTRAINT recipes_pkey PRIMARY KEY (id);


--
-- Name: recipes_pos recipes_pos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recipes_pos
    ADD CONSTRAINT recipes_pos_pkey PRIMARY KEY (id);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: roles roles_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_code_key UNIQUE (code);


--
-- Name: roles roles_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_key UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_session_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_session_key_key UNIQUE (session_key);


--
-- Name: shopping_list shopping_list_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shopping_list
    ADD CONSTRAINT shopping_list_pkey PRIMARY KEY (id);


--
-- Name: shopping_lists shopping_lists_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shopping_lists
    ADD CONSTRAINT shopping_lists_name_key UNIQUE (name);


--
-- Name: shopping_lists shopping_lists_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shopping_lists
    ADD CONSTRAINT shopping_lists_pkey PRIMARY KEY (id);


--
-- Name: shopping_locations shopping_locations_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shopping_locations
    ADD CONSTRAINT shopping_locations_name_key UNIQUE (name);


--
-- Name: shopping_locations shopping_locations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shopping_locations
    ADD CONSTRAINT shopping_locations_pkey PRIMARY KEY (id);


--
-- Name: stock_booking_lots stock_booking_lots_booking_lot; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_booking_lots
    ADD CONSTRAINT stock_booking_lots_booking_lot UNIQUE NULLS NOT DISTINCT (booking_id, lot_id);


--
-- Name: stock_booking_lots stock_booking_lots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_booking_lots
    ADD CONSTRAINT stock_booking_lots_pkey PRIMARY KEY (id);


--
-- Name: stock_entry_origins stock_entry_origins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_entry_origins
    ADD CONSTRAINT stock_entry_origins_pkey PRIMARY KEY (id);


--
-- Name: stock_entry_origins stock_entry_origins_stock_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_entry_origins
    ADD CONSTRAINT stock_entry_origins_stock_id_key UNIQUE (stock_id);


--
-- Name: stock_label_retirements stock_label_retirements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_label_retirements
    ADD CONSTRAINT stock_label_retirements_pkey PRIMARY KEY (id);


--
-- Name: stock_log stock_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_log
    ADD CONSTRAINT stock_log_pkey PRIMARY KEY (id);


--
-- Name: stock stock_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock
    ADD CONSTRAINT stock_pkey PRIMARY KEY (id);


--
-- Name: stock_row_lots stock_row_lots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_row_lots
    ADD CONSTRAINT stock_row_lots_pkey PRIMARY KEY (id);


--
-- Name: stock_row_lots stock_row_lots_row_lot; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_row_lots
    ADD CONSTRAINT stock_row_lots_row_lot UNIQUE NULLS NOT DISTINCT (stock_row_id, lot_id);


--
-- Name: storage_classes storage_classes_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.storage_classes
    ADD CONSTRAINT storage_classes_name_key UNIQUE (name);


--
-- Name: storage_classes storage_classes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.storage_classes
    ADD CONSTRAINT storage_classes_pkey PRIMARY KEY (id);


--
-- Name: system_db_changed_time system_db_changed_time_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_db_changed_time
    ADD CONSTRAINT system_db_changed_time_pkey PRIMARY KEY (id);


--
-- Name: task_categories task_categories_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_categories
    ADD CONSTRAINT task_categories_name_key UNIQUE (name);


--
-- Name: task_categories task_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_categories
    ADD CONSTRAINT task_categories_pkey PRIMARY KEY (id);


--
-- Name: tasks tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_pkey PRIMARY KEY (id);


--
-- Name: user_permissions user_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_pkey PRIMARY KEY (id);


--
-- Name: user_permissions user_permissions_user_id_permission_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_user_id_permission_id_key UNIQUE (user_id, permission_id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (user_id, role_id);


--
-- Name: user_settings_defaults user_settings_defaults_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_settings_defaults
    ADD CONSTRAINT user_settings_defaults_pkey PRIMARY KEY (key);


--
-- Name: user_settings user_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_settings
    ADD CONSTRAINT user_settings_pkey PRIMARY KEY (id);


--
-- Name: user_settings user_settings_user_id_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_settings
    ADD CONSTRAINT user_settings_user_id_key_key UNIQUE (user_id, key);


--
-- Name: userentities userentities_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.userentities
    ADD CONSTRAINT userentities_name_key UNIQUE (name);


--
-- Name: userentities userentities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.userentities
    ADD CONSTRAINT userentities_pkey PRIMARY KEY (id);


--
-- Name: userfield_values userfield_values_field_id_object_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.userfield_values
    ADD CONSTRAINT userfield_values_field_id_object_id_key UNIQUE (field_id, object_id);


--
-- Name: userfield_values userfield_values_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.userfield_values
    ADD CONSTRAINT userfield_values_pkey PRIMARY KEY (id);


--
-- Name: userfields userfields_entity_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.userfields
    ADD CONSTRAINT userfields_entity_name_key UNIQUE (entity, name);


--
-- Name: userfields userfields_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.userfields
    ADD CONSTRAINT userfields_pkey PRIMARY KEY (id);


--
-- Name: userobjects userobjects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.userobjects
    ADD CONSTRAINT userobjects_pkey PRIMARY KEY (id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: ix_batteries_performance1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_batteries_performance1 ON public.batteries USING btree (id, active);


--
-- Name: ix_cache__quantity_unit_conversions_resolved_performance1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_cache__quantity_unit_conversions_resolved_performance1 ON public.cache__quantity_unit_conversions_resolved USING btree (product_id, from_qu_id, to_qu_id);


--
-- Name: ix_chores_log_performance1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_chores_log_performance1 ON public.chores_log USING btree (chore_id, undone, tracked_time);


--
-- Name: ix_chores_performance1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_chores_performance1 ON public.chores USING btree (id, active);


--
-- Name: ix_consumption_event_lines_event_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_event_lines_event_id ON public.consumption_event_lines USING btree (event_id);


--
-- Name: ix_consumption_event_lines_stock_log_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_event_lines_stock_log_id ON public.consumption_event_lines USING btree (stock_log_id);


--
-- Name: ix_consumption_events_mapping_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_events_mapping_id ON public.consumption_events USING btree (mapping_id);


--
-- Name: ix_consumption_events_recipe_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_events_recipe_id ON public.consumption_events USING btree (recipe_id);


--
-- Name: ix_consumption_events_replaces_event_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_events_replaces_event_id ON public.consumption_events USING btree (replaces_event_id);


--
-- Name: ix_consumption_events_user_medication; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_events_user_medication ON public.consumption_events USING btree (user_id, source_system, medication_ref, state, occurred_at, id);


--
-- Name: ix_consumption_events_user_occurred_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_events_user_occurred_at ON public.consumption_events USING btree (user_id, occurred_at);


--
-- Name: ix_consumption_events_user_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_events_user_state ON public.consumption_events USING btree (user_id, state);


--
-- Name: ix_consumption_mappings_product_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_mappings_product_id ON public.consumption_mappings USING btree (product_id);


--
-- Name: ix_consumption_mappings_recipe_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_mappings_recipe_id ON public.consumption_mappings USING btree (recipe_id);


--
-- Name: ix_consumption_recipe_lines_product_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_recipe_lines_product_id ON public.consumption_recipe_lines USING btree (product_id);


--
-- Name: ix_consumption_recipe_shares_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_recipe_shares_user_id ON public.consumption_recipe_shares USING btree (user_id);


--
-- Name: ix_consumption_recipes_owner_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_recipes_owner_user_id ON public.consumption_recipes USING btree (owner_user_id);


--
-- Name: ix_consumption_refill_acks_recipe_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_refill_acks_recipe_id ON public.consumption_refill_acks USING btree (recipe_id);


--
-- Name: ix_consumption_refill_dates_fill_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_refill_dates_fill_id ON public.consumption_refill_dates USING btree (fill_id);


--
-- Name: ix_consumption_refill_fills_current; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_refill_fills_current ON public.consumption_refill_fills USING btree (recipe_id, filled_on DESC, id DESC) WHERE (voided_at IS NULL);


--
-- Name: ix_consumption_refill_orders_received_fill_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_refill_orders_received_fill_id ON public.consumption_refill_orders USING btree (received_fill_id) WHERE (received_fill_id IS NOT NULL);


--
-- Name: ix_consumption_refill_orders_recipe_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_consumption_refill_orders_recipe_id ON public.consumption_refill_orders USING btree (recipe_id, ordered_on DESC, id DESC);


--
-- Name: ix_product_barcodes; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ix_product_barcodes ON public.product_barcodes USING btree (barcode);


--
-- Name: ix_products_performance1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_products_performance1 ON public.products USING btree (parent_product_id);


--
-- Name: ix_products_performance2; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_products_performance2 ON public.products USING btree ((
CASE
    WHEN (parent_product_id IS NULL) THEN id
    ELSE parent_product_id
END), active);


--
-- Name: ix_recipes; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_recipes ON public.recipes USING btree (name, type);


--
-- Name: ix_stock_booking_lots_lot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_stock_booking_lots_lot ON public.stock_booking_lots USING btree (lot_id, booking_id);


--
-- Name: ix_stock_entry_origins_origin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_stock_entry_origins_origin ON public.stock_entry_origins USING btree (origin_stock_id);


--
-- Name: ix_stock_log_performance1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_stock_log_performance1 ON public.stock_log USING btree (stock_id, transaction_type, amount);


--
-- Name: ix_stock_log_performance2; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_stock_log_performance2 ON public.stock_log USING btree (product_id, best_before_date, purchased_date, transaction_type, stock_id, undone);


--
-- Name: ix_stock_log_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_stock_log_transaction_id ON public.stock_log USING btree (transaction_id);


--
-- Name: ix_stock_performance1; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_stock_performance1 ON public.stock USING btree (product_id, open, best_before_date, amount);


--
-- Name: ix_stock_row_lots_lot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_stock_row_lots_lot ON public.stock_row_lots USING btree (lot_id);


--
-- Name: label_artifacts_bytes; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_artifacts_bytes ON public.label_artifacts USING btree (file_group, file_name);


--
-- Name: label_artifacts_collectable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_artifacts_collectable ON public.label_artifacts USING btree (retention_class, row_created_timestamp) WHERE (collected_at IS NULL);


--
-- Name: label_artifacts_request; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_artifacts_request ON public.label_artifacts USING btree (render_request_id);


--
-- Name: label_captures_uid; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_captures_uid ON public.label_captures USING btree (label_uid) WHERE (label_uid IS NOT NULL);


--
-- Name: label_idempotency_keys_expiring; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_idempotency_keys_expiring ON public.label_idempotency_keys USING btree (expires_at);


--
-- Name: label_media_profiles_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_media_profiles_key ON public.label_media_profiles USING btree (profile_key, version DESC);


--
-- Name: label_printers_one_default; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX label_printers_one_default ON public.label_printers USING btree (is_default) WHERE (is_default = 1);


--
-- Name: label_render_requests_expiring; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_render_requests_expiring ON public.label_render_requests USING btree (expires_at) WHERE (expires_at IS NOT NULL);


--
-- Name: label_render_requests_queued; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_render_requests_queued ON public.label_render_requests USING btree (id) WHERE (state = ANY (ARRAY['pending'::text, 'rendering'::text]));


--
-- Name: label_template_versions_template; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX label_template_versions_template ON public.label_template_versions USING btree (template_id, version DESC);


--
-- Name: label_worker_pairing_material; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX label_worker_pairing_material ON public.label_worker_sessions USING btree (pairing_material_hash) WHERE (pairing_material_hash IS NOT NULL);


--
-- Name: labels_one_live_per_target; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX labels_one_live_per_target ON public.labels USING btree (kind, target_id) WHERE (retired_at IS NULL);


--
-- Name: login_attempts_username; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_attempts_username ON public.login_attempts USING btree (username, row_created_timestamp);


--
-- Name: outbox_undelivered; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX outbox_undelivered ON public.outbox USING btree (delivered_at, dead_lettered_at, id);


--
-- Name: print_jobs_claimable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX print_jobs_claimable ON public.print_jobs USING btree (printer_id, outbox_id) WHERE ((outcome IS NULL) AND (cancelled_at IS NULL) AND (artifact_id IS NOT NULL));


--
-- Name: print_jobs_label_uid_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX print_jobs_label_uid_id ON public.print_jobs USING btree (label_uid, id);


--
-- Name: products_location_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_location_id_idx ON public.products USING btree (location_id);


--
-- Name: products_product_group_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_product_group_id_idx ON public.products USING btree (product_group_id);


--
-- Name: products_qu_id_consume_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_qu_id_consume_idx ON public.products USING btree (qu_id_consume);


--
-- Name: products_qu_id_price_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_qu_id_price_idx ON public.products USING btree (qu_id_price);


--
-- Name: products_qu_id_purchase_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_qu_id_purchase_idx ON public.products USING btree (qu_id_purchase);


--
-- Name: products_qu_id_stock_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_qu_id_stock_idx ON public.products USING btree (qu_id_stock);


--
-- Name: stock_label_retirements_booking; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX stock_label_retirements_booking ON public.stock_label_retirements USING btree (import_epoch, booking_id) WHERE (booking_id IS NOT NULL);


--
-- Name: stock_label_retirements_label; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stock_label_retirements_label ON public.stock_label_retirements USING btree (label_uid);


--
-- Name: stock_label_retirements_one_pending; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX stock_label_retirements_one_pending ON public.stock_label_retirements USING btree (label_uid) WHERE (outcome IS NULL);


--
-- Name: stock_location_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stock_location_id_idx ON public.stock USING btree (location_id);


--
-- Name: ux_consumption_events_linked_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_consumption_events_linked_transaction_id ON public.consumption_events USING btree (linked_transaction_id) WHERE (linked_transaction_id IS NOT NULL);


--
-- Name: ux_consumption_events_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_consumption_events_transaction_id ON public.consumption_events USING btree (transaction_id) WHERE (transaction_id IS NOT NULL);


--
-- Name: ux_consumption_refill_dates_live; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_consumption_refill_dates_live ON public.consumption_refill_dates USING btree (recipe_id) WHERE (ended_at IS NULL);


--
-- Name: ux_consumption_refill_orders_open; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_consumption_refill_orders_open ON public.consumption_refill_orders USING btree (recipe_id) WHERE (state = 'open'::text);


--
-- Name: chores_current _RETURN; Type: RULE; Schema: public; Owner: -
--

CREATE OR REPLACE VIEW public.chores_current AS
 SELECT chore_id AS id,
    chore_id,
    chore_name,
    last_tracked_time,
        CASE
            WHEN ((rollover = 1) AND (date_trunc('second'::text, CURRENT_TIMESTAMP) > next_estimated_execution_time)) THEN
            CASE
                WHEN (COALESCE((track_date_only)::integer, 0) = 1) THEN public.victual_local_to_instant(((to_char(CURRENT_TIMESTAMP, 'YYYY-MM-DD'::text) || ' 23:59:59'::text))::timestamp without time zone, current_setting('TimeZone'::text), false)
                ELSE public.victual_local_to_instant((((to_char(CURRENT_TIMESTAMP, 'YYYY-MM-DD'::text) || ' '::text) || to_char(next_estimated_execution_time, 'HH24:MI:SS'::text)))::timestamp without time zone, current_setting('TimeZone'::text), false)
            END
            ELSE
            CASE
                WHEN (COALESCE((track_date_only)::integer, 0) = 1) THEN public.victual_local_to_instant(((to_char(next_estimated_execution_time, 'YYYY-MM-DD'::text) || ' 23:59:59'::text))::timestamp without time zone, current_setting('TimeZone'::text), false)
                ELSE next_estimated_execution_time
            END
        END AS next_estimated_execution_time,
    track_date_only,
    next_execution_assigned_to_user_id,
        CASE
            WHEN (rescheduled_date IS NOT NULL) THEN 1
            ELSE 0
        END AS is_rescheduled,
        CASE
            WHEN (rescheduled_next_execution_assigned_to_user_id IS NOT NULL) THEN 1
            ELSE 0
        END AS is_reassigned
   FROM ( SELECT h.id AS chore_id,
            h.name AS chore_name,
            max(l.tracked_time) AS last_tracked_time,
                CASE
                    WHEN (h.rescheduled_date IS NOT NULL) THEN h.rescheduled_date
                    ELSE
                    CASE
                        WHEN ((max(l.tracked_time) IS NULL) AND (h.period_type <> 'manually'::text)) THEN h.start_date
                        ELSE
                        CASE h.period_type
                            WHEN 'manually'::text THEN NULL::timestamp with time zone
                            WHEN 'hourly'::text THEN (max(l.tracked_time) + (((h.period_interval)::text || ' hour'::text))::interval)
                            WHEN 'daily'::text THEN public.victual_local_to_instant((((to_char((max(l.tracked_time) + (((h.period_interval)::text || ' days'::text))::interval), 'YYYY-MM-DD'::text) || ' '::text) || to_char(h.start_date, 'HH24:MI:SS'::text)))::timestamp without time zone, current_setting('TimeZone'::text), false)
                            WHEN 'weekly'::text THEN ( SELECT weekly_candidates.next
                               FROM ( SELECT (s.step1 + ((((((wd.day_num - (EXTRACT(dow FROM s.step1))::integer) + 7) % 7))::text || ' days'::text))::interval) AS next
                                       FROM ( SELECT (( SELECT chores_log.tracked_time
      FROM public.chores_log
     WHERE ((chores_log.chore_id = h.id) AND (chores_log.undone = 0))
     ORDER BY chores_log.tracked_time DESC
    LIMIT 1) + ((((1 + ((h.period_interval - 1) * 7)))::text || ' days'::text))::interval) AS step1) s,
                                        ( VALUES ('sunday'::text,0), ('monday'::text,1), ('tuesday'::text,2), ('wednesday'::text,3), ('thursday'::text,4), ('friday'::text,5), ('saturday'::text,6)) wd(day_name, day_num)
                                      WHERE (POSITION((wd.day_name) IN (h.period_config)) > 0)) weekly_candidates
                              ORDER BY weekly_candidates.next
                             LIMIT 1)
                            WHEN 'monthly'::text THEN ((date_trunc('month'::text, max(l.tracked_time)) + (((h.period_interval)::text || ' month'::text))::interval) + ((((h.period_days - 1))::text || ' day'::text))::interval)
                            WHEN 'yearly'::text THEN public.victual_local_to_instant(((to_char((h.start_date + (((((EXTRACT(year FROM (max(l.tracked_time) + (((h.period_interval)::text || ' years'::text))::interval)))::integer - (EXTRACT(year FROM h.start_date))::integer))::text || ' years'::text))::interval), 'YYYY-MM-DD'::text) || to_char((max(l.tracked_time) + (((h.period_interval)::text || ' years'::text))::interval), ' HH24:MI:SS'::text)))::timestamp without time zone, current_setting('TimeZone'::text), false)
                            WHEN 'adaptive'::text THEN (max(l.tracked_time) + (((COALESCE(( SELECT chores_execution_average_frequency.average_frequency_hours
                               FROM public.chores_execution_average_frequency
                              WHERE (chores_execution_average_frequency.chore_id = h.id)), (0)::double precision))::text || ' hour'::text))::interval)
                            ELSE NULL::timestamp with time zone
                        END
                    END
                END AS next_estimated_execution_time,
            h.track_date_only,
            h.rollover,
            h.next_execution_assigned_to_user_id,
            h.rescheduled_date,
            h.rescheduled_next_execution_assigned_to_user_id
           FROM (public.chores h
             LEFT JOIN public.chores_log l ON (((h.id = l.chore_id) AND (l.undone = 0))))
          WHERE (h.active = 1)
          GROUP BY h.id, h.name, h.period_days) x;


--
-- Name: recipes_resolved _RETURN; Type: RULE; Schema: public; Owner: -
--

CREATE OR REPLACE VIEW public.recipes_resolved AS
 SELECT 1 AS id,
    r.id AS recipe_id,
    COALESCE(min(rpr.need_fulfilled), 1) AS need_fulfilled,
    COALESCE(min(rpr.need_fulfilled_with_shopping_list), 1) AS need_fulfilled_with_shopping_list,
    COALESCE(rmpc.missing_products_count, 0) AS missing_products_count,
    COALESCE(sum(rpr.costs), (0)::double precision) AS costs,
    COALESCE((sum(rpr.costs) /
        CASE
            WHEN (COALESCE(r.desired_servings, (0)::double precision) = (0)::double precision) THEN (1)::double precision
            ELSE r.desired_servings
        END), (0)::double precision) AS costs_per_serving,
    COALESCE(sum(rpr.calories), (0)::double precision) AS calories,
    COALESCE((sum(rpr.due_score))::integer, 0) AS due_score,
    string_agg(rpr.product_name, ','::text) AS product_names_comma_separated,
        CASE
            WHEN (min(COALESCE(rpr.costs, (0)::double precision)) = (0)::double precision) THEN 1
            ELSE 0
        END AS prices_incomplete
   FROM ((public.recipes r
     LEFT JOIN public.recipes_pos_resolved rpr ON ((r.id = rpr.recipe_id)))
     LEFT JOIN public.recipes_missing_product_counts rmpc ON ((r.id = rmpc.recipe_id)))
  GROUP BY r.id, rmpc.missing_products_count;


--
-- Name: batteries cascade_battery_removal; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cascade_battery_removal AFTER DELETE ON public.batteries FOR EACH ROW EXECUTE FUNCTION public.trg_cascade_battery_removal();


--
-- Name: products cascade_change_qu_id_stock; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cascade_change_qu_id_stock BEFORE UPDATE ON public.products FOR EACH ROW WHEN ((new.qu_id_stock <> old.qu_id_stock)) EXECUTE FUNCTION public.trg_cascade_change_qu_id_stock();


--
-- Name: products cascade_change_qu_id_stock2; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cascade_change_qu_id_stock2 BEFORE UPDATE ON public.products FOR EACH ROW WHEN ((new.qu_id_stock <> old.qu_id_stock)) EXECUTE FUNCTION public.trg_cascade_change_qu_id_stock2();


--
-- Name: chores cascade_chore_removal; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cascade_chore_removal AFTER DELETE ON public.chores FOR EACH ROW EXECUTE FUNCTION public.trg_cascade_chore_removal();


--
-- Name: products cascade_product_removal; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cascade_product_removal AFTER DELETE ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_cascade_product_removal();


--
-- Name: userfields cascade_userfield_removal; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER cascade_userfield_removal AFTER DELETE ON public.userfields FOR EACH ROW EXECUTE FUNCTION public.trg_cascade_userfield_removal();


--
-- Name: locations check_location_parent; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER check_location_parent BEFORE INSERT OR UPDATE OF parent_location_id ON public.locations FOR EACH ROW EXECUTE FUNCTION public.trg_locations_check_parent();


--
-- Name: product_groups check_product_group_parent; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER check_product_group_parent BEFORE INSERT OR UPDATE OF parent_product_group_id ON public.product_groups FOR EACH ROW EXECUTE FUNCTION public.trg_product_groups_check_parent();


--
-- Name: consumption_events consumption_event_transaction_roles; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_event_transaction_roles BEFORE INSERT OR UPDATE OF transaction_id, linked_transaction_id, user_id, source_system ON public.consumption_events FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_event_transaction_roles();


--
-- Name: consumption_recipes consumption_owner_not_sharee; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_owner_not_sharee BEFORE UPDATE OF owner_user_id ON public.consumption_recipes FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_owner_not_sharee();


--
-- Name: consumption_refill_dates consumption_refill_date_ends_once; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_refill_date_ends_once BEFORE UPDATE ON public.consumption_refill_dates FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_refill_date_ends_once();


--
-- Name: consumption_refill_dates consumption_refill_date_not_before_fill; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_refill_date_not_before_fill BEFORE INSERT ON public.consumption_refill_dates FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_refill_date_not_before_fill();


--
-- Name: consumption_refill_fills consumption_refill_fill_immutable; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_refill_fill_immutable BEFORE UPDATE ON public.consumption_refill_fills FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_refill_fill_immutable();


--
-- Name: consumption_refill_fills consumption_refill_fill_supersedes_dates; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_refill_fill_supersedes_dates AFTER INSERT ON public.consumption_refill_fills FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_refill_fill_supersedes_dates();


--
-- Name: consumption_refill_orders consumption_refill_order_closed_final; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_refill_order_closed_final BEFORE UPDATE ON public.consumption_refill_orders FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_refill_order_closed_final();


--
-- Name: consumption_refill_fills consumption_refill_void_ends_dates; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_refill_void_ends_dates AFTER UPDATE OF voided_at ON public.consumption_refill_fills FOR EACH ROW WHEN (((old.voided_at IS NULL) AND (new.voided_at IS NOT NULL))) EXECUTE FUNCTION public.trg_consumption_refill_void_ends_dates();


--
-- Name: consumption_recipe_shares consumption_share_not_owner; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER consumption_share_not_owner BEFORE INSERT OR UPDATE OF recipe_id, user_id ON public.consumption_recipe_shares FOR EACH ROW EXECUTE FUNCTION public.trg_consumption_share_not_owner();


--
-- Name: meal_plan create_internal_recipe; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER create_internal_recipe AFTER INSERT ON public.meal_plan FOR EACH ROW EXECUTE FUNCTION public.trg_create_internal_recipe();


--
-- Name: products default_qu_id_consume; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER default_qu_id_consume BEFORE INSERT ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_default_qu_id_consume();


--
-- Name: products default_qu_id_price; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER default_qu_id_price BEFORE INSERT ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_default_qu_id_price();


--
-- Name: product_barcodes default_qu_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER default_qu_ins BEFORE INSERT ON public.product_barcodes FOR EACH ROW EXECUTE FUNCTION public.trg_default_qu_ins();


--
-- Name: product_barcodes default_qu_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER default_qu_upd BEFORE UPDATE ON public.product_barcodes FOR EACH ROW EXECUTE FUNCTION public.trg_default_qu_upd();


--
-- Name: chores default_start_date_when_empty_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER default_start_date_when_empty_ins BEFORE INSERT ON public.chores FOR EACH ROW EXECUTE FUNCTION public.trg_default_start_date_when_empty_ins();


--
-- Name: chores default_start_date_when_empty_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER default_start_date_when_empty_upd BEFORE UPDATE ON public.chores FOR EACH ROW EXECUTE FUNCTION public.trg_default_start_date_when_empty_upd();


--
-- Name: products enforce_min_stock_amount_for_cumulated_childs_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER enforce_min_stock_amount_for_cumulated_childs_ins AFTER INSERT ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_enforce_min_stock_amount_for_cumulated_childs_ins();


--
-- Name: products enforce_min_stock_amount_for_cumulated_childs_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER enforce_min_stock_amount_for_cumulated_childs_upd AFTER UPDATE ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_enforce_min_stock_amount_for_cumulated_childs_upd();


--
-- Name: products enforce_parent_product_id_null_when_empty_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER enforce_parent_product_id_null_when_empty_ins BEFORE INSERT ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_enforce_parent_product_id_null_when_empty_ins();


--
-- Name: products enforce_parent_product_id_null_when_empty_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER enforce_parent_product_id_null_when_empty_upd BEFORE UPDATE ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_enforce_parent_product_id_null_when_empty_upd();


--
-- Name: products enfore_product_nesting_level; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER enfore_product_nesting_level BEFORE INSERT OR UPDATE ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_enfore_product_nesting_level();


--
-- Name: locations guard_location_children; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER guard_location_children BEFORE DELETE ON public.locations FOR EACH ROW EXECUTE FUNCTION public.trg_locations_guard_children();


--
-- Name: product_groups guard_product_group_children; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER guard_product_group_children BEFORE DELETE ON public.product_groups FOR EACH ROW EXECUTE FUNCTION public.trg_product_groups_guard_children();


--
-- Name: stock_label_retirements guard_stock_label_retirement_history; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER guard_stock_label_retirement_history BEFORE DELETE OR UPDATE ON public.stock_label_retirements FOR EACH ROW EXECUTE FUNCTION public.guard_stock_label_retirement_history();


--
-- Name: product_barcodes prevent_adding_barcodes_for_not_existing_products; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_adding_barcodes_for_not_existing_products AFTER INSERT ON public.product_barcodes FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_adding_barcodes_for_not_existing_products();


--
-- Name: stock prevent_adding_no_own_stock_products_to_stock; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_adding_no_own_stock_products_to_stock AFTER INSERT ON public.stock FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_adding_no_own_stock_products_to_stock();


--
-- Name: userfield_values prevent_empty_userfields_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_empty_userfields_ins AFTER INSERT ON public.userfield_values FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_empty_userfields_ins();


--
-- Name: userfield_values prevent_empty_userfields_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_empty_userfields_upd AFTER UPDATE ON public.userfield_values FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_empty_userfields_upd();


--
-- Name: recipes_nestings prevent_infinite_nested_recipes_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_infinite_nested_recipes_ins BEFORE INSERT ON public.recipes_nestings FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_infinite_nested_recipes_ins();


--
-- Name: recipes_nestings prevent_infinite_nested_recipes_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_infinite_nested_recipes_upd BEFORE UPDATE ON public.recipes_nestings FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_infinite_nested_recipes_upd();


--
-- Name: meal_plan_sections prevent_internal_meal_plan_section_removal; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_internal_meal_plan_section_removal BEFORE DELETE ON public.meal_plan_sections FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_internal_meal_plan_section_removal();


--
-- Name: recipes_nestings prevent_self_nested_recipes_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_self_nested_recipes_ins BEFORE INSERT ON public.recipes_nestings FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_self_nested_recipes_ins();


--
-- Name: recipes_nestings prevent_self_nested_recipes_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER prevent_self_nested_recipes_upd BEFORE UPDATE ON public.recipes_nestings FOR EACH ROW EXECUTE FUNCTION public.trg_prevent_self_nested_recipes_upd();


--
-- Name: products products_default_qu_conversions_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER products_default_qu_conversions_ins AFTER INSERT ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_products_default_qu_conversions_ins();


--
-- Name: products products_default_qu_conversions_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER products_default_qu_conversions_upd AFTER UPDATE ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_products_default_qu_conversions_upd();


--
-- Name: products products_delete; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER products_delete AFTER DELETE ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_products_delete();


--
-- Name: products products_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER products_ins AFTER INSERT ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_products_ins();


--
-- Name: products products_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER products_upd AFTER UPDATE ON public.products FOR EACH ROW EXECUTE FUNCTION public.trg_products_upd();


--
-- Name: quantity_unit_conversions qu_conversions_custom_constraint_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER qu_conversions_custom_constraint_ins BEFORE INSERT ON public.quantity_unit_conversions FOR EACH ROW EXECUTE FUNCTION public.trg_qu_conversions_custom_constraint_ins();


--
-- Name: quantity_unit_conversions qu_conversions_custom_constraint_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER qu_conversions_custom_constraint_upd BEFORE UPDATE ON public.quantity_unit_conversions FOR EACH ROW EXECUTE FUNCTION public.trg_qu_conversions_custom_constraint_upd();


--
-- Name: quantity_unit_conversions quantity_unit_conversions_del; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER quantity_unit_conversions_del AFTER DELETE ON public.quantity_unit_conversions FOR EACH ROW EXECUTE FUNCTION public.trg_quantity_unit_conversions_del();


--
-- Name: quantity_unit_conversions quantity_unit_conversions_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER quantity_unit_conversions_ins AFTER INSERT ON public.quantity_unit_conversions FOR EACH ROW EXECUTE FUNCTION public.trg_quantity_unit_conversions_ins();


--
-- Name: quantity_unit_conversions quantity_unit_conversions_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER quantity_unit_conversions_upd AFTER UPDATE ON public.quantity_unit_conversions FOR EACH ROW EXECUTE FUNCTION public.trg_quantity_unit_conversions_upd();


--
-- Name: recipes recipes_desired_servings_default; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER recipes_desired_servings_default BEFORE INSERT ON public.recipes FOR EACH ROW EXECUTE FUNCTION public.trg_recipes_desired_servings_default();


--
-- Name: recipes_pos recipes_pos_qu_id_default; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER recipes_pos_qu_id_default BEFORE INSERT ON public.recipes_pos FOR EACH ROW EXECUTE FUNCTION public.trg_recipes_pos_qu_id_default();


--
-- Name: labels record_stock_label_retirement; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER record_stock_label_retirement AFTER UPDATE OF retired_at ON public.labels FOR EACH ROW WHEN (((old.retired_at IS NULL) AND (new.retired_at IS NOT NULL) AND (new.kind = 'stock_entry'::text))) EXECUTE FUNCTION public.record_stock_label_retirement();


--
-- Name: quantity_units remove_conversions; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER remove_conversions AFTER DELETE ON public.quantity_units FOR EACH ROW EXECUTE FUNCTION public.trg_remove_conversions();


--
-- Name: meal_plan remove_internal_recipe; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER remove_internal_recipe AFTER DELETE ON public.meal_plan FOR EACH ROW EXECUTE FUNCTION public.trg_remove_internal_recipe();


--
-- Name: shopping_lists remove_items_from_deleted_shopping_list; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER remove_items_from_deleted_shopping_list AFTER DELETE ON public.shopping_lists FOR EACH ROW EXECUTE FUNCTION public.trg_remove_items_from_deleted_shopping_list();


--
-- Name: recipes remove_recipe_from_meal_plans; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER remove_recipe_from_meal_plans AFTER DELETE ON public.recipes FOR EACH ROW EXECUTE FUNCTION public.trg_remove_recipe_from_meal_plans();


--
-- Name: batteries retire_battery_labels; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER retire_battery_labels BEFORE DELETE ON public.batteries FOR EACH ROW EXECUTE FUNCTION public.retire_battery_labels();


--
-- Name: chores retire_chore_labels; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER retire_chore_labels BEFORE DELETE ON public.chores FOR EACH ROW EXECUTE FUNCTION public.retire_chore_labels();


--
-- Name: locations retire_location_labels; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER retire_location_labels BEFORE DELETE ON public.locations FOR EACH ROW EXECUTE FUNCTION public.retire_location_labels();


--
-- Name: products retire_product_labels; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER retire_product_labels BEFORE DELETE ON public.products FOR EACH ROW EXECUTE FUNCTION public.retire_product_labels();


--
-- Name: recipes retire_recipe_labels; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER retire_recipe_labels BEFORE DELETE ON public.recipes FOR EACH ROW EXECUTE FUNCTION public.retire_recipe_labels();


--
-- Name: stock retire_stock_entry_labels; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER retire_stock_entry_labels BEFORE DELETE ON public.stock FOR EACH ROW EXECUTE FUNCTION public.retire_stock_entry_labels();


--
-- Name: stock set_products_default_location_if_empty_stock; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER set_products_default_location_if_empty_stock BEFORE INSERT ON public.stock FOR EACH ROW EXECUTE FUNCTION public.trg_set_products_default_location_if_empty_stock();


--
-- Name: stock_log set_products_default_location_if_empty_stock_log; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER set_products_default_location_if_empty_stock_log BEFORE INSERT ON public.stock_log FOR EACH ROW EXECUTE FUNCTION public.trg_set_products_default_location_if_empty_stock_log();


--
-- Name: shopping_list shopping_list_defaults_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER shopping_list_defaults_ins BEFORE INSERT ON public.shopping_list FOR EACH ROW EXECUTE FUNCTION public.trg_shopping_list_defaults_ins();


--
-- Name: shopping_list shopping_list_defaults_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER shopping_list_defaults_upd BEFORE UPDATE ON public.shopping_list FOR EACH ROW EXECUTE FUNCTION public.trg_shopping_list_defaults_upd();


--
-- Name: stock_log stock_log_del; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER stock_log_del AFTER DELETE ON public.stock_log REFERENCING OLD TABLE AS stock_log_old_rows FOR EACH STATEMENT EXECUTE FUNCTION public.trg_stock_log_del();


--
-- Name: stock_log stock_log_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER stock_log_ins AFTER INSERT ON public.stock_log FOR EACH ROW EXECUTE FUNCTION public.trg_stock_log_ins();


--
-- Name: stock_log stock_log_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER stock_log_upd AFTER UPDATE ON public.stock_log REFERENCING OLD TABLE AS stock_log_old_rows NEW TABLE AS stock_log_new_rows FOR EACH STATEMENT EXECUTE FUNCTION public.trg_stock_log_upd();


--
-- Name: stock_next_use stock_next_use_del; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER stock_next_use_del INSTEAD OF DELETE ON public.stock_next_use FOR EACH ROW EXECUTE FUNCTION public.trg_stock_next_use_del();


--
-- Name: stock_next_use stock_next_use_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER stock_next_use_ins INSTEAD OF INSERT ON public.stock_next_use FOR EACH ROW EXECUTE FUNCTION public.trg_stock_next_use_ins();


--
-- Name: stock_next_use stock_next_use_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER stock_next_use_upd INSTEAD OF UPDATE ON public.stock_next_use FOR EACH ROW EXECUTE FUNCTION public.trg_stock_next_use_upd();


--
-- Name: meal_plan update_internal_recipe; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_internal_recipe AFTER UPDATE ON public.meal_plan FOR EACH ROW EXECUTE FUNCTION public.trg_update_internal_recipe();


--
-- Name: userfield_values userfield_values_special_handling_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER userfield_values_special_handling_ins AFTER INSERT ON public.userfield_values FOR EACH ROW EXECUTE FUNCTION public.trg_userfield_values_special_handling_ins();


--
-- Name: api_keys api_keys_rotated_from_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_rotated_from_id_fkey FOREIGN KEY (rotated_from_id) REFERENCES public.api_keys(id) ON DELETE SET NULL;


--
-- Name: consumption_event_lines consumption_event_lines_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_event_lines
    ADD CONSTRAINT consumption_event_lines_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.consumption_events(id) ON DELETE CASCADE;


--
-- Name: consumption_event_lines consumption_event_lines_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_event_lines
    ADD CONSTRAINT consumption_event_lines_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.locations(id) ON DELETE SET NULL;


--
-- Name: consumption_event_lines consumption_event_lines_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_event_lines
    ADD CONSTRAINT consumption_event_lines_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: consumption_event_lines consumption_event_lines_stock_log_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_event_lines
    ADD CONSTRAINT consumption_event_lines_stock_log_id_fkey FOREIGN KEY (stock_log_id) REFERENCES public.stock_log(id) ON DELETE SET NULL;


--
-- Name: consumption_events consumption_events_api_key_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_api_key_id_fkey FOREIGN KEY (api_key_id) REFERENCES public.api_keys(id) ON DELETE SET NULL;


--
-- Name: consumption_events consumption_events_mapping_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_mapping_id_fkey FOREIGN KEY (mapping_id) REFERENCES public.consumption_mappings(id) ON DELETE SET NULL;


--
-- Name: consumption_events consumption_events_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE SET NULL;


--
-- Name: consumption_events consumption_events_replaces_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_replaces_event_id_fkey FOREIGN KEY (replaces_event_id) REFERENCES public.consumption_events(id) ON DELETE SET NULL;


--
-- Name: consumption_events consumption_events_requested_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_requested_location_id_fkey FOREIGN KEY (requested_location_id) REFERENCES public.locations(id) ON DELETE SET NULL;


--
-- Name: consumption_events consumption_events_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_events
    ADD CONSTRAINT consumption_events_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: consumption_mappings consumption_mappings_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_mappings
    ADD CONSTRAINT consumption_mappings_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.locations(id);


--
-- Name: consumption_mappings consumption_mappings_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_mappings
    ADD CONSTRAINT consumption_mappings_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: consumption_mappings consumption_mappings_qu_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_mappings
    ADD CONSTRAINT consumption_mappings_qu_id_fkey FOREIGN KEY (qu_id) REFERENCES public.quantity_units(id);


--
-- Name: consumption_mappings consumption_mappings_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_mappings
    ADD CONSTRAINT consumption_mappings_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE SET NULL;


--
-- Name: consumption_mappings consumption_mappings_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_mappings
    ADD CONSTRAINT consumption_mappings_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: consumption_recipe_lines consumption_recipe_lines_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_lines
    ADD CONSTRAINT consumption_recipe_lines_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: consumption_recipe_lines consumption_recipe_lines_qu_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_lines
    ADD CONSTRAINT consumption_recipe_lines_qu_id_fkey FOREIGN KEY (qu_id) REFERENCES public.quantity_units(id);


--
-- Name: consumption_recipe_lines consumption_recipe_lines_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_lines
    ADD CONSTRAINT consumption_recipe_lines_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE CASCADE;


--
-- Name: consumption_recipe_shares consumption_recipe_shares_granted_by_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_shares
    ADD CONSTRAINT consumption_recipe_shares_granted_by_user_id_fkey FOREIGN KEY (granted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: consumption_recipe_shares consumption_recipe_shares_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_shares
    ADD CONSTRAINT consumption_recipe_shares_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE CASCADE;


--
-- Name: consumption_recipe_shares consumption_recipe_shares_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipe_shares
    ADD CONSTRAINT consumption_recipe_shares_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: consumption_recipes consumption_recipes_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_recipes
    ADD CONSTRAINT consumption_recipes_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: consumption_refill_acks consumption_refill_acks_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_acks
    ADD CONSTRAINT consumption_refill_acks_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE CASCADE;


--
-- Name: consumption_refill_acks consumption_refill_acks_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_acks
    ADD CONSTRAINT consumption_refill_acks_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: consumption_refill_dates consumption_refill_dates_fill_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_dates
    ADD CONSTRAINT consumption_refill_dates_fill_fkey FOREIGN KEY (fill_id, recipe_id) REFERENCES public.consumption_refill_fills(id, recipe_id) ON DELETE CASCADE;


--
-- Name: consumption_refill_dates consumption_refill_dates_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_dates
    ADD CONSTRAINT consumption_refill_dates_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE CASCADE;


--
-- Name: consumption_refill_fills consumption_refill_fills_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_fills
    ADD CONSTRAINT consumption_refill_fills_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE CASCADE;


--
-- Name: consumption_refill_orders consumption_refill_orders_received_fill_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_orders
    ADD CONSTRAINT consumption_refill_orders_received_fill_fkey FOREIGN KEY (received_fill_id, recipe_id) REFERENCES public.consumption_refill_fills(id, recipe_id);


--
-- Name: consumption_refill_orders consumption_refill_orders_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_orders
    ADD CONSTRAINT consumption_refill_orders_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE CASCADE;


--
-- Name: consumption_refill_settings consumption_refill_settings_recipe_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consumption_refill_settings
    ADD CONSTRAINT consumption_refill_settings_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES public.consumption_recipes(id) ON DELETE CASCADE;


--
-- Name: label_artifacts label_artifacts_capture_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_artifacts
    ADD CONSTRAINT label_artifacts_capture_id_fkey FOREIGN KEY (capture_id) REFERENCES public.label_captures(id);


--
-- Name: label_artifacts label_artifacts_profile_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_artifacts
    ADD CONSTRAINT label_artifacts_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES public.label_media_profiles(id);


--
-- Name: label_artifacts label_artifacts_render_request_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_artifacts
    ADD CONSTRAINT label_artifacts_render_request_id_fkey FOREIGN KEY (render_request_id) REFERENCES public.label_render_requests(id);


--
-- Name: label_artifacts label_artifacts_template_version_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_artifacts
    ADD CONSTRAINT label_artifacts_template_version_id_fkey FOREIGN KEY (template_version_id) REFERENCES public.label_template_versions(id);


--
-- Name: label_drivers label_drivers_registered_by_worker_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_drivers
    ADD CONSTRAINT label_drivers_registered_by_worker_id_fkey FOREIGN KEY (registered_by_worker_id) REFERENCES public.label_workers(id);


--
-- Name: label_media_profiles label_media_profiles_driver_id_driver_schema_version_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_media_profiles
    ADD CONSTRAINT label_media_profiles_driver_id_driver_schema_version_fkey FOREIGN KEY (driver_id, driver_schema_version) REFERENCES public.label_drivers(driver_id, schema_version);


--
-- Name: label_printer_status label_printer_status_printer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_printer_status
    ADD CONSTRAINT label_printer_status_printer_id_fkey FOREIGN KEY (printer_id) REFERENCES public.label_printers(id);


--
-- Name: label_printer_status label_printer_status_reported_by_worker_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_printer_status
    ADD CONSTRAINT label_printer_status_reported_by_worker_id_fkey FOREIGN KEY (reported_by_worker_id) REFERENCES public.label_workers(id);


--
-- Name: label_printers label_printers_driver_id_driver_schema_version_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_printers
    ADD CONSTRAINT label_printers_driver_id_driver_schema_version_fkey FOREIGN KEY (driver_id, driver_schema_version) REFERENCES public.label_drivers(driver_id, schema_version);


--
-- Name: label_printers label_printers_worker_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_printers
    ADD CONSTRAINT label_printers_worker_id_fkey FOREIGN KEY (worker_id) REFERENCES public.label_workers(id);


--
-- Name: label_render_requests label_render_requests_artifact; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_render_requests
    ADD CONSTRAINT label_render_requests_artifact FOREIGN KEY (artifact_id) REFERENCES public.label_artifacts(id);


--
-- Name: label_render_requests label_render_requests_capture_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_render_requests
    ADD CONSTRAINT label_render_requests_capture_id_fkey FOREIGN KEY (capture_id) REFERENCES public.label_captures(id);


--
-- Name: label_render_requests label_render_requests_draft_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_render_requests
    ADD CONSTRAINT label_render_requests_draft_template_id_fkey FOREIGN KEY (draft_template_id) REFERENCES public.label_templates(id);


--
-- Name: label_render_requests label_render_requests_profile_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_render_requests
    ADD CONSTRAINT label_render_requests_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES public.label_media_profiles(id);


--
-- Name: label_render_requests label_render_requests_template_version_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_render_requests
    ADD CONSTRAINT label_render_requests_template_version_id_fkey FOREIGN KEY (template_version_id) REFERENCES public.label_template_versions(id);


--
-- Name: label_template_drafts label_template_drafts_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_template_drafts
    ADD CONSTRAINT label_template_drafts_template_id_fkey FOREIGN KEY (template_id) REFERENCES public.label_templates(id) ON DELETE CASCADE;


--
-- Name: label_template_versions label_template_versions_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_template_versions
    ADD CONSTRAINT label_template_versions_template_id_fkey FOREIGN KEY (template_id) REFERENCES public.label_templates(id);


--
-- Name: label_templates label_templates_default_version; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_templates
    ADD CONSTRAINT label_templates_default_version FOREIGN KEY (default_version_id) REFERENCES public.label_template_versions(id);


--
-- Name: label_worker_capabilities label_worker_capabilities_driver_id_schema_version_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_capabilities
    ADD CONSTRAINT label_worker_capabilities_driver_id_schema_version_fkey FOREIGN KEY (driver_id, schema_version) REFERENCES public.label_drivers(driver_id, schema_version);


--
-- Name: label_worker_capabilities label_worker_capabilities_worker_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_capabilities
    ADD CONSTRAINT label_worker_capabilities_worker_id_fkey FOREIGN KEY (worker_id) REFERENCES public.label_workers(id);


--
-- Name: label_worker_credentials label_worker_credentials_api_key_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_credentials
    ADD CONSTRAINT label_worker_credentials_api_key_id_fkey FOREIGN KEY (api_key_id) REFERENCES public.api_keys(id);


--
-- Name: label_worker_credentials label_worker_credentials_session_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_credentials
    ADD CONSTRAINT label_worker_credentials_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.label_worker_sessions(id);


--
-- Name: label_worker_credentials label_worker_credentials_successor_api_key_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_credentials
    ADD CONSTRAINT label_worker_credentials_successor_api_key_id_fkey FOREIGN KEY (successor_api_key_id) REFERENCES public.api_keys(id);


--
-- Name: label_worker_credentials label_worker_credentials_worker_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_credentials
    ADD CONSTRAINT label_worker_credentials_worker_id_fkey FOREIGN KEY (worker_id) REFERENCES public.label_workers(id);


--
-- Name: label_worker_sessions label_worker_sessions_worker_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.label_worker_sessions
    ADD CONSTRAINT label_worker_sessions_worker_id_fkey FOREIGN KEY (worker_id) REFERENCES public.label_workers(id);


--
-- Name: locations locations_storage_class_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locations
    ADD CONSTRAINT locations_storage_class_id_fkey FOREIGN KEY (storage_class_id) REFERENCES public.storage_classes(id);


--
-- Name: locations locations_tare_qu_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locations
    ADD CONSTRAINT locations_tare_qu_id_fkey FOREIGN KEY (tare_qu_id) REFERENCES public.quantity_units(id);


--
-- Name: permission_fields permission_fields_permission_name_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_fields
    ADD CONSTRAINT permission_fields_permission_name_fkey FOREIGN KEY (permission_name) REFERENCES public.permission_hierarchy(name);


--
-- Name: print_attempts print_attempts_job_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_attempts
    ADD CONSTRAINT print_attempts_job_id_fkey FOREIGN KEY (job_id) REFERENCES public.print_jobs(id);


--
-- Name: print_attempts print_attempts_outbox_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_attempts
    ADD CONSTRAINT print_attempts_outbox_id_fkey FOREIGN KEY (outbox_id) REFERENCES public.outbox(id);


--
-- Name: print_attempts print_attempts_worker_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_attempts
    ADD CONSTRAINT print_attempts_worker_id_fkey FOREIGN KEY (worker_id) REFERENCES public.label_workers(id);


--
-- Name: print_evidence print_evidence_attempt_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_evidence
    ADD CONSTRAINT print_evidence_attempt_id_fkey FOREIGN KEY (attempt_id) REFERENCES public.print_attempts(id);


--
-- Name: print_jobs print_jobs_artifact_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_artifact_id_fkey FOREIGN KEY (artifact_id) REFERENCES public.label_artifacts(id);


--
-- Name: print_jobs print_jobs_capture_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_capture_id_fkey FOREIGN KEY (capture_id) REFERENCES public.label_captures(id);


--
-- Name: print_jobs print_jobs_current_attempt_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_current_attempt_id_fkey FOREIGN KEY (current_attempt_id) REFERENCES public.print_attempts(id);


--
-- Name: print_jobs print_jobs_idempotency_key_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_idempotency_key_id_fkey FOREIGN KEY (idempotency_key_id) REFERENCES public.label_idempotency_keys(id);


--
-- Name: print_jobs print_jobs_outbox_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_outbox_id_fkey FOREIGN KEY (outbox_id) REFERENCES public.outbox(id);


--
-- Name: print_jobs print_jobs_render_request_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_render_request_id_fkey FOREIGN KEY (render_request_id) REFERENCES public.label_render_requests(id);


--
-- Name: print_jobs print_jobs_source_job_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.print_jobs
    ADD CONSTRAINT print_jobs_source_job_id_fkey FOREIGN KEY (source_job_id) REFERENCES public.print_jobs(id);


--
-- Name: product_location_min_stock product_location_min_stock_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_location_min_stock
    ADD CONSTRAINT product_location_min_stock_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.locations(id);


--
-- Name: product_location_min_stock product_location_min_stock_product_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_location_min_stock
    ADD CONSTRAINT product_location_min_stock_product_id_fkey FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: products products_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.locations(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: products products_product_group_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_product_group_id_fkey FOREIGN KEY (product_group_id) REFERENCES public.product_groups(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: products products_qu_id_consume_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_qu_id_consume_fkey FOREIGN KEY (qu_id_consume) REFERENCES public.quantity_units(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: products products_qu_id_price_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_qu_id_price_fkey FOREIGN KEY (qu_id_price) REFERENCES public.quantity_units(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: products products_qu_id_purchase_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_qu_id_purchase_fkey FOREIGN KEY (qu_id_purchase) REFERENCES public.quantity_units(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: products products_qu_id_stock_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_qu_id_stock_fkey FOREIGN KEY (qu_id_stock) REFERENCES public.quantity_units(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: role_permissions role_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permission_hierarchy(id);


--
-- Name: role_permissions role_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: stock_booking_lots stock_booking_lots_booking_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_booking_lots
    ADD CONSTRAINT stock_booking_lots_booking_id_fkey FOREIGN KEY (booking_id) REFERENCES public.stock_log(id) ON DELETE CASCADE;


--
-- Name: stock_booking_lots stock_booking_lots_lot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_booking_lots
    ADD CONSTRAINT stock_booking_lots_lot_id_fkey FOREIGN KEY (lot_id) REFERENCES public.stock_log(id) ON DELETE CASCADE;


--
-- Name: stock_label_retirements stock_label_retirements_label_uid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_label_retirements
    ADD CONSTRAINT stock_label_retirements_label_uid_fkey FOREIGN KEY (label_uid) REFERENCES public.labels(uid);


--
-- Name: stock stock_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock
    ADD CONSTRAINT stock_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.locations(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: stock_row_lots stock_row_lots_lot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_row_lots
    ADD CONSTRAINT stock_row_lots_lot_id_fkey FOREIGN KEY (lot_id) REFERENCES public.stock_log(id) ON DELETE CASCADE;


--
-- Name: stock_row_lots stock_row_lots_stock_row_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_row_lots
    ADD CONSTRAINT stock_row_lots_stock_row_id_fkey FOREIGN KEY (stock_row_id) REFERENCES public.stock(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict YWkCifrgZ10RPx2NGjHS07ST7FVadCdFoZLGejjFGaOQLT4cWVq9Lvd9KzKRjJa

