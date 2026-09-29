-- Issue #492 (H3, #487 remediation): "Stock edits accept negative quantities and fractional
-- consumption leaves residue". ADR-0032 decision 4 and acceptance gate 6 require
-- EditStockEntry() to refuse a negative amount, and commit 2039d5947 ("fix: apply ADR-0032
-- stock amount policy") already applied that refusal - and the matching refusal in
-- AddProduct(), ConsumeProduct(), InventoryProduct(), OpenProduct() and TransferProduct() -
-- in PHP. Nothing in the schema itself backed that up: `stock.amount` (db/pgsql/baseline/01_tables.sql)
-- carries no CHECK, so any writer that does not go through StockService - a future
-- application bug, a direct import path, a hand-written migration or maintenance script -
-- can still persist a negative row.
--
-- This adds the database-level backstop the issue asks for: `amount >= 0`. Zero stays
-- writable on purpose. Issue #487's "Corrections to the audit" item 3 records that
-- WeighLocation() legitimately zeroes a vessel's stock row when its gross reading equals
-- its tare weight (services/StockService.php's WeighLocation() call into EditStockEntry()
-- with $newAmount = 0), and ADR-0032 open question 1 leaves "no row may hold 0" undecided
-- and out of scope for this record. `stock_measurement_coherence_check` (migration 0275)
-- already requires exactly `amount = 1` for an opened, measured row - a strictly narrower
-- constraint than `amount >= 0`, so the two never conflict.
--
-- `stock_log.amount` is deliberately left unconstrained: TRANSACTION_TYPE_TRANSFER_FROM and
-- TRANSACTION_TYPE_CONSUME bookings store a negative amount by design (the ledger records
-- the signed delta a booking applies, not a resulting balance) - see the constant
-- documentation in services/StockService.php. Only `stock` (the resulting balance per row)
-- gets the non-negative constraint.
--
-- **Import vs upgrade (maintainer correction, 2026-09-29).** DatabaseImporter's own
-- AssertStockAmounts()/SourceColumnExpression() (services/Database/DatabaseImporter.php)
-- validate a *legacy import*, which lands in a blank, freshly migrated database - there is
-- no existing installation to run this migration over in that path. An *app upgrade* is the
-- other path: this migration runs against an existing installation's live data, which can
-- already hold a negative stock.amount row - issue #492's own defect is exactly how one
-- could get there before this fix existed. Adding the CHECK straight onto that data would
-- fail with a raw PostgreSQL SQLSTATE 23514 naming no row and explaining nothing, the same
-- shape of unhelpful failure the importer's own preflight was added to avoid. ADR-0029's
-- pattern - a read-only preflight that lists the offending rows and aborts with a clear
-- report before the constraint is added - is the maintainer's standing rule for exactly
-- this, followed here the way migration 0288 already follows it for a dangling stock
-- location. No repair invents a value nobody recorded; the one exception is the same one
-- the maintainer decided for the importer: a residue within ADR-0032's tolerance of zero
-- (StockService::AMOUNT_TOLERANCE = 1e-9; the literal below MUST match that constant, this
-- migration cannot reference it directly) counts as zero, because CompareAmounts() already
-- treats it as zero everywhere else at runtime and refusing an upgrade over it would be
-- refusing over a value nobody would call negative.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE stock IN SHARE ROW EXCLUSIVE MODE;

-- Zeroes only a residue within tolerance - see the docblock above for why this one case
-- is a translation rather than a refusal. Every amount at or below -1e-9 is untouched here
-- and is what the DO block below inspects and, if any remain, refuses on.
UPDATE stock SET amount = 0 WHERE amount < 0 AND amount >= -1e-9;

DO $$
DECLARE
    negative_count bigint;
    sample text;
BEGIN
    SELECT count(*) INTO negative_count FROM stock WHERE amount < -1e-9;

    IF negative_count > 0 THEN
        SELECT string_agg(format('(%s, %s, %s, %s)', id, product_id, stock_id, amount), ', ' ORDER BY id)
        INTO sample FROM (
            SELECT id, product_id, stock_id, amount
            FROM stock WHERE amount < -1e-9
            ORDER BY id LIMIT 10
        ) negative;
        RAISE EXCEPTION 'Migration refused: % stock rows hold a negative amount. Sample (stock id, product id, stock_id, amount): %. Choose an explicit repair and rerun migration. No stock has been deleted or changed beyond zeroing a residue within tolerance.', negative_count, sample
            USING HINT = 'List all rows: SELECT id, product_id, stock_id, amount FROM stock WHERE amount < -1e-9 ORDER BY id;';
    END IF;
END $$;

ALTER TABLE stock ADD CONSTRAINT stock_amount_non_negative_check CHECK (amount >= 0);
