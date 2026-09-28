-- Issues #543 and #546 (#487 remediation): trg_cascade_change_qu_id_stock (the BEFORE
-- UPDATE trigger that fires when a product's qu_id_stock changes, db/pgsql/baseline/06_triggers_a.sql)
-- rescales every per-product amount stored in the product's stock unit by the resolved
-- conversion factor - chores.product_amount, meal_plan.product_amount, recipes_pos.amount,
-- shopping_list.amount, and stock/stock_log's own amount and price. Two gaps in that
-- rescale, both found while validating PR #540 (issue #503, M3) for the #487 remediation:
--
-- #543. product_location_min_stock.min_stock_amount (migrations/0276.pgsql.sql, plan 29)
-- is stored in the product's stock unit exactly like the four columns above, but that
-- table arrived after this trigger function was written and it was never extended. A
-- location minimum of 500 g on a product later changed to kg keeps reading 500 - now 500
-- kg - while product_location_missing's shortfall compares it against genuinely converted
-- stock. Fixed the same way the other four tables are handled: one more UPDATE, in the
-- same transaction, by the same v_factor.
--
-- #546. MergeProducts() (services/StockService.php, commit 791389623f, "Part of #546")
-- already refuses before writing when the removed product has a measured open container -
-- live in `stock`, or only a live (undone = 0) consume booking left in `stock_log` after a
-- full consumption deleted the `stock` row (ConsumeProduct() mirrors opened_amount/
-- opened_qu_id onto that booking precisely so UndoBooking() can rebuild the row later,
-- migrations/0275.pgsql.sql) - and the resolved factor is not 1. Rescaling either row by
-- anything other than 1 would violate stock_measurement_coherence_check (migrations/0275.pgsql.sql,
-- "opened_amount IS NOT NULL ... AND amount = 1") outright: a live `stock` row hits that
-- CHECK the instant this trigger's own `UPDATE stock SET amount = amount * v_factor ...`
-- runs, and a `stock_log` consume booking survives the rescale unchecked (stock_log carries
-- no such CHECK) only to blow up later when UndoBooking() tries to rebuild it (PR #598 put
-- a clean refusal on that rebuild path as a safety net, but its own notes name this
-- trigger's rescale as the sibling cause it does not fix). This trigger had no equivalent
-- guard at all - not reproduced by the issue, and left explicitly open in both #598's PR
-- body and commit 791389623f's comment, pending this migration.
--
-- The fix mirrors MergeProducts()'s own guard exactly, in the same place the "conversion
-- must exist" check already lives: refuse the qu_id_stock change itself, before any row is
-- touched, whenever the resolved factor is not 1 and the product has a measured open
-- container (live in `stock`) or a live, undoable measured consume booking (`stock_log`,
-- undone = 0). A factor of exactly 1 never reaches the CHECK regardless of what it
-- multiplies, so that case is (and remains) unaffected.

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

	-- Issue #546: refuse before touching anything, mirroring MergeProducts()'s own guard
	-- (services/StockService.php, commit 791389623f) against the same
	-- stock_measurement_coherence_check. A factor of 1 leaves every amount unchanged and
	-- can never violate it, so only a genuine rescale is refused.
	IF v_factor != 1.0
		AND (
			EXISTS(SELECT 1 FROM stock WHERE product_id = NEW.id AND opened_amount IS NOT NULL)
			OR EXISTS(SELECT 1 FROM stock_log WHERE product_id = NEW.id AND undone = 0 AND opened_amount IS NOT NULL)
		)
	THEN
		RAISE EXCEPTION 'qu_id_stock cannot be changed by a non-1 conversion factor while this product has a measured open container (live, or a live undoable consume booking)';
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

	-- Issue #543: product_location_min_stock.min_stock_amount (migrations/0276.pgsql.sql)
	-- is stored in the product's stock unit exactly like the amounts above, and belongs in
	-- the same transaction as the rest of this rescale.
	UPDATE product_location_min_stock
	SET min_stock_amount = min_stock_amount * v_factor
	WHERE product_id = NEW.id;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
