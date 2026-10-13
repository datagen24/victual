-- 2026-10-12 audit remediation: unit-change ledger (issues #849, #853, #866, #868).
--
-- WHAT THIS CHANGES.
--   - #868: quantity_unit_conversions.factor gets CHECK (factor > 0 AND factor < 'Infinity').
--     trg_cascade_change_qu_id_stock multiplies ledger amounts by the resolved factor and divides
--     prices by it, so a negative factor flipped the sign of every stock_log amount and price of a
--     product with history and no current stock, silently (stock_amount_non_negative_check guards
--     `stock` only). Migrations 0298 and 0300 patch only the read side. The trigger now also
--     refuses a resolved factor that is not positive and finite, which covers a factor the
--     resolved view derives rather than reads from a row.
--   - #849: the same trigger rescales consumption_mappings.quantity_factor for the product's
--     mappings with qu_id NULL (NULL means the product's stock unit, so the mapping's
--     amount = quantity * quantity_factor * conversion changes meaning with the unit) and
--     consumption_event_lines.amount (abs(stock_log.amount) at booking, so a stock-unit amount
--     like stock_log.amount). A mapping with its own qu_id converts at booking time and is left
--     alone, as are consumption_recipe_lines, which carry their own unit. default_quantity is
--     left as the person entered it: it is the quantity the person confirms, and only
--     quantity_factor turns it into stock units.
--   - #853: stock_log_UPD and stock_log_DEL become statement-level triggers over transition
--     tables. The row-level triggers rebuilt the price caches once per row and each rebuild reads
--     stock_edited_entries over the whole ledger (0302 measured about 137 ms at 19,416 rows), so
--     the unit change and MergeProducts, which UPDATE every ledger row of a product in one
--     statement, cost rows * ledger. Each distinct product of the statement is rebuilt once, with
--     the same function and so the same cache contents.
--   - #866: stock_lineage_backfill resolves a booking's lot by joining one row per (product, root)
--     instead of a correlated subquery over an unindexed materialised CTE (bookings * lots).
--     Output is unchanged.
--
-- PREFLIGHT. The constraint is added only if no conversion row violates it; otherwise the
-- migration is refused with a report and repairs nothing (ADR-0029).
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

-- ADR-0029: bounded blocking, validated atomically with the migration record.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE quantity_unit_conversions IN SHARE ROW EXCLUSIVE MODE;

DO $$
DECLARE
    bad_count bigint;
    sample text;
BEGIN
    SELECT count(*) INTO bad_count
    FROM quantity_unit_conversions
    WHERE NOT (factor > 0 AND factor < 'Infinity');

    IF bad_count > 0 THEN
        SELECT string_agg(format('(%s, %s => %s, product %s, factor %s)', id, from_qu_id, to_qu_id, COALESCE(product_id::text, 'none'), factor), ', ' ORDER BY id)
        INTO sample FROM (
            SELECT id, from_qu_id, to_qu_id, product_id, factor
            FROM quantity_unit_conversions
            WHERE NOT (factor > 0 AND factor < 'Infinity')
            ORDER BY id LIMIT 10
        ) bad;
        RAISE EXCEPTION 'Migration refused: % quantity unit conversions have a factor that is not positive and finite. Sample (conversion id, units, product, factor): %. Choose an explicit repair and rerun migration. No conversion has been changed.', bad_count, sample
            USING HINT = 'List all: SELECT id, from_qu_id, to_qu_id, product_id, factor FROM quantity_unit_conversions WHERE NOT (factor > 0 AND factor < ''Infinity'') ORDER BY id;';
    END IF;
END $$;

ALTER TABLE quantity_unit_conversions
    ADD CONSTRAINT quantity_unit_conversions_factor_positive CHECK (factor > 0 AND factor < 'Infinity');

-- trg_cascade_change_qu_id_stock, as 0304 left it, plus the factor guard and the rescale of
-- consumption_mappings and consumption_event_lines. The trigger declaration
-- (db/pgsql/baseline/06_triggers_a.sql) is unchanged.
CREATE OR REPLACE FUNCTION trg_cascade_change_qu_id_stock() RETURNS TRIGGER AS $$
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
$$ LANGUAGE plpgsql;

-- Issue #853: one rebuild per affected product per statement. Both functions keep their names
-- and the triggers keep theirs (baseline/06_triggers_b.sql, 0292); only the firing level and the
-- transition tables change. A statement that moves rows between products (MergeProducts) names
-- both the old and the new product in its transition tables.
DROP TRIGGER IF EXISTS stock_log_UPD ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_UPD() RETURNS TRIGGER AS $$
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
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_UPD AFTER UPDATE ON stock_log
REFERENCING OLD TABLE AS stock_log_old_rows NEW TABLE AS stock_log_new_rows
FOR EACH STATEMENT EXECUTE FUNCTION trg_stock_log_UPD();

DROP TRIGGER IF EXISTS stock_log_DEL ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_DEL() RETURNS TRIGGER AS $$
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
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_DEL AFTER DELETE ON stock_log
REFERENCING OLD TABLE AS stock_log_old_rows
FOR EACH STATEMENT EXECUTE FUNCTION trg_stock_log_DEL();

-- Issue #866: stock_lineage_backfill as 0304 defined it, except that `lot_of` holds one lot per
-- (product, root) and ins_bookings joins it. The correlated subquery it replaces scanned `lots`
-- once per booking. Only family class E reads a non-addition booking's lot, and an E family has
-- exactly one live addition, so one row per (product, root) is the subquery's one row; the
-- subquery would have raised on a second.
CREATE OR REPLACE FUNCTION stock_lineage_backfill(p_product INTEGER DEFAULT NULL)
RETURNS TABLE (family_class TEXT, families BIGINT, stock_rows BIGINT)
LANGUAGE plpgsql AS $$
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
