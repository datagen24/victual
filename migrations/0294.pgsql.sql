-- Issues #543 and #546 (#487 remediation): trg_cascade_change_qu_id_stock (the BEFORE
-- UPDATE trigger that fires when a product's qu_id_stock changes, db/pgsql/baseline/06_triggers_a.sql)
-- rescales every per-product amount stored in the product's stock unit by the resolved
-- conversion factor - chores.product_amount, meal_plan.product_amount, recipes_pos.amount,
-- shopping_list.amount, and stock/stock_log's own amount and price. Three gaps in that
-- rescale, all found while validating PR #540 (issue #503, M3) for the #487 remediation:
--
-- #543. product_location_min_stock.min_stock_amount (migrations/0276.pgsql.sql, plan 29)
-- and products.min_stock_amount (db/pgsql/baseline/01_tables.sql, present since the
-- baseline) are both stored in the product's stock unit exactly like the four columns
-- above, but neither was ever rescaled here: product_location_min_stock arrived after this
-- trigger function was written, and products.min_stock_amount was simply missed. A minimum
-- of 500 g on a product later changed to kg keeps reading 500 - now 500 kg - while the
-- shortfall views compare it against genuinely converted stock. products.min_stock_amount
-- is fixed the same way trg_cascade_change_qu_id_stock2 (migrations/0275.pgsql.sql) already
-- rescales quick_consume_amount/quick_open_amount on the very same row: `NEW.min_stock_amount
-- := NEW.min_stock_amount * v_factor` rather than a second UPDATE against the row this
-- trigger is already mid-UPDATE on. product_location_min_stock is a different table, so it
-- still needs its own UPDATE, in the same transaction, by the same v_factor.
--
-- #546. MergeProducts() (services/StockService.php, commit 791389623f, "Part of #546")
-- refuses before writing when the removed product has a measured open container - live in
-- `stock` - and the resolved factor is not 1, since rescaling amount away from 1 would
-- violate stock_measurement_coherence_check (migrations/0275.pgsql.sql, "opened_amount IS
-- NOT NULL ... AND amount = 1"). This trigger applies the identical rescale on a single
-- product's own qu_id_stock change and had no equivalent guard at all - not reproduced by
-- the issue, and left explicitly open in both #598's PR body and commit 791389623f's
-- comment, pending this migration.
--
-- THE GUARD COVERS `stock` ONLY, NOT `stock_log`. An earlier round of this migration also
-- refused when a live (undone = 0), measured consume booking sat in `stock_log` with no
-- corresponding `stock` row - the shape ConsumeProduct() leaves behind when a whole
-- measured container is taken (migrations/0275.pgsql.sql). That over-refused: such a
-- booking is permanent history once its container is fully consumed (it is never undone
-- again by anything else, and some rows - e.g. a stock-splitting INSERT's own "subsequent
-- dependent bookings" - can never be undone at all), so the earlier guard locked the
-- product's stock unit forever with nothing left to consume, weigh, or otherwise clear. The
-- ledger case is already covered by a truthful refusal exactly where it actually matters:
-- UndoBooking()'s own rebuild (PR #598, "a rescaled measured consume undo is refused
-- cleanly"), which fires only if and when that specific booking is ever undone - not on
-- every future unit change regardless of whether undo is ever attempted. Refusing here too
-- would duplicate that protection while adding a permanent lock #598 does not have.
--
-- A CLEAR MESSAGE ALSO REACHES THE API, NOT JUST THIS BACKSTOP. This RAISE EXCEPTION
-- reaches the wire as raw SQLSTATE text, which BaseApiController::WithoutDriverText()
-- (issue #498/#487 H9) replaces with a generic "database rejected this request" message -
-- true but not actionable. controllers/Api/GenericEntityApiController.php's EditObject()
-- now runs the same check (RefuseMeasuredContainerQuIdStockChange()) before the write for
-- the entity's own write path, answering 400 with a specific, actionable message. This
-- trigger stays the authoritative backstop for every other write path (imports, direct SQL,
-- future callers).

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
