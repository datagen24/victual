-- Issue #774, ADR-0045 (Proposed) gate 1 option (a) and open questions 8 and 9: a per-row
-- marker for a stored price of 0 that means "not entered" rather than "free".
--
-- WHAT THIS CHANGES.
--   stock.legacy_zero_price and stock_log.legacy_zero_price, SMALLINT NOT NULL DEFAULT 0,
--   constrained to 0 or 1, and to 1 only on a row priced exactly 0. The stored price is never
--   changed: the marker says how to read it. A marked row's price is unknown; an unmarked 0 is
--   a known zero (P2).
--
--   Every row priced 0 that exists when this migration runs is marked (open question 9):
--   before this change five write paths stored 0 for a blank price, so a stored 0 cannot be
--   told from a free one. The counts are written to price_interpretation_reports, a
--   target-owned record that the importer also writes to for imported legacy zeros
--   (open question 8). Nothing else is read differently yet: the readers that test the marker
--   come with the API change in the following migration.
--
--   stock_next_use gains the column (its s.* froze stock's column list in 0275), and both of its
--   INSTEAD OF triggers carry the marker, because they name every column by hand and would
--   otherwise drop it without an error (the failure 0275 records for the opened_* columns).
--   stock_splits groups by the marker too, so compaction never merges a marked 0 with a known 0.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

-- ADR-0029: bounded blocking. The ALTERs need ACCESS EXCLUSIVE on both tables, so it is taken
-- up front, in one statement and one order.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE stock, stock_log IN ACCESS EXCLUSIVE MODE;

ALTER TABLE stock
	ADD COLUMN legacy_zero_price SMALLINT NOT NULL DEFAULT 0,
	ADD CONSTRAINT stock_legacy_zero_price_check CHECK (legacy_zero_price IN (0, 1)),
	ADD CONSTRAINT stock_legacy_zero_price_is_zero CHECK (legacy_zero_price = 0 OR price IS NOT DISTINCT FROM 0);

ALTER TABLE stock_log
	ADD COLUMN legacy_zero_price SMALLINT NOT NULL DEFAULT 0,
	ADD CONSTRAINT stock_log_legacy_zero_price_check CHECK (legacy_zero_price IN (0, 1)),
	ADD CONSTRAINT stock_log_legacy_zero_price_is_zero CHECK (legacy_zero_price = 0 OR price IS NOT DISTINCT FROM 0);

CREATE TABLE price_interpretation_reports (
	id SERIAL PRIMARY KEY,
	recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
	source TEXT NOT NULL CHECK (source IN ('upgrade', 'import')),
	policy TEXT NOT NULL CHECK (policy IN ('unknown', 'known')),
	rule TEXT NOT NULL,
	stock_rows INTEGER NOT NULL CHECK (stock_rows >= 0),
	stock_log_rows INTEGER NOT NULL CHECK (stock_log_rows >= 0)
);

-- One definition of the reading, for this upgrade and for every import after it
-- (DatabaseImporter::MarkLegacyZeroPrices()). Under 'unknown' it marks every row priced
-- exactly 0; under 'known' it marks nothing and counts the zeros left as known. Either way it
-- records the rule and the counts, and returns them. An upgrade with no zero writes no report.
-- It changes no price, and it leaves triggers to its caller: the importer runs it with them off.
CREATE FUNCTION mark_legacy_zero_prices(p_source TEXT, p_policy TEXT, OUT stock_rows INTEGER, OUT stock_log_rows INTEGER) AS $$
BEGIN
	IF p_policy = 'unknown' THEN
		UPDATE stock_log SET legacy_zero_price = 1 WHERE price = 0 AND legacy_zero_price = 0;
		UPDATE stock SET legacy_zero_price = 1 WHERE price = 0 AND legacy_zero_price = 0;
		SELECT count(*) INTO stock_rows FROM stock WHERE legacy_zero_price = 1;
		SELECT count(*) INTO stock_log_rows FROM stock_log WHERE legacy_zero_price = 1;
	ELSIF p_policy = 'known' THEN
		SELECT count(*) INTO stock_rows FROM stock WHERE price = 0 AND legacy_zero_price = 0;
		SELECT count(*) INTO stock_log_rows FROM stock_log WHERE price = 0 AND legacy_zero_price = 0;
	ELSE
		RAISE EXCEPTION 'legacy zero price policy must be unknown or known, not %', p_policy;
	END IF;

	IF p_source = 'import' OR stock_rows > 0 OR stock_log_rows > 0 THEN
		INSERT INTO price_interpretation_reports (source, policy, rule, stock_rows, stock_log_rows)
		VALUES (p_source, p_policy,
			CASE p_policy
				WHEN 'unknown' THEN 'Every price of 0 stored by an earlier release or a source database is read as unknown (legacy_zero_price = 1), because a blank price was stored as 0. Stored prices are unchanged.'
				ELSE 'Every imported price of 0 is read as a known zero (--legacy-zero-prices=known), including any that were never entered. Stored prices are unchanged.'
			END,
			stock_rows, stock_log_rows);
	END IF;
END;
$$ LANGUAGE plpgsql;

-- Marking touches only the new column. stock_log_UPD rebuilds the price caches per row, and
-- nothing those caches read depends on the marker yet, so it is held for this one call.
ALTER TABLE stock_log DISABLE TRIGGER stock_log_UPD;
SELECT mark_legacy_zero_prices('upgrade', 'unknown');
ALTER TABLE stock_log ENABLE TRIGGER stock_log_UPD;

-- migrations/0275.pgsql.sql's view with its columns written out and legacy_zero_price
-- appended. Not s.*: that would also re-expand over stock.import_epoch, which migration 0283
-- added after 0275 last froze the list, and put it on every stock-entries response.
CREATE OR REPLACE VIEW stock_next_use AS
SELECT
	(CAST(ROW_NUMBER() OVER(PARTITION BY s.product_id ORDER BY CASE WHEN COALESCE(p.default_consume_location_id, -1) = s.location_id THEN 0 ELSE 1 END ASC, s.open DESC, s.best_before_date ASC, s.purchased_date ASC) AS INTEGER)) * -1 AS priority,
	s.id, s.product_id, s.amount, s.best_before_date, s.purchased_date, s.stock_id, s.price, s.open,
	s.opened_date, s.row_created_timestamp, s.location_id, s.shopping_location_id, s.note,
	s.opened_amount, s.opened_qu_id, s.opened_tare, s.opened_measured_at,
	s.legacy_zero_price
FROM stock s
JOIN products p
	ON p.id = s.product_id
ORDER BY CASE WHEN COALESCE(p.default_consume_location_id, -1) = s.location_id THEN 0 ELSE 1 END ASC, s.open DESC, s.best_before_date ASC, s.purchased_date ASC;

-- migrations/0275.pgsql.sql's two functions plus legacy_zero_price.
CREATE OR REPLACE FUNCTION trg_stock_next_use_INS() RETURNS TRIGGER AS $$
BEGIN
	INSERT INTO stock
		(product_id, amount, best_before_date, purchased_date, stock_id,
		price, open, opened_date, location_id, shopping_location_id, note,
		opened_amount, opened_qu_id, opened_tare, opened_measured_at, legacy_zero_price)
	VALUES
		(NEW.product_id, NEW.amount, NEW.best_before_date, NEW.purchased_date, NEW.stock_id,
		NEW.price, NEW.open, NEW.opened_date, NEW.location_id, NEW.shopping_location_id, NEW.note,
		NEW.opened_amount, NEW.opened_qu_id, NEW.opened_tare, NEW.opened_measured_at, COALESCE(NEW.legacy_zero_price, 0));

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Writes an update through stock_next_use to stock, carrying legacy_zero_price with the price it describes
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
		opened_measured_at = NEW.opened_measured_at,
		legacy_zero_price = NEW.legacy_zero_price
	WHERE id = NEW.id;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- migrations/0290.pgsql.sql's stock_splits with the marker in the grouping key.
CREATE OR REPLACE VIEW stock_splits AS

/*
	Helper view which shows splitted stock rows which could be compacted

	Stock entries with a stock_id starting with "x", those with userfields, those carrying a
	measured remainder (opened_amount IS NOT NULL), those with a real (non-sentinel) due date,
	and those carrying a live stock_entry label shouldn't be compacted. A price of 0 read as
	unknown (legacy_zero_price) is never merged with a known 0.
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
	AND (s.best_before_date IS NULL OR s.best_before_date = '2999-12-31')
	AND NOT EXISTS(
		SELECT 1 FROM userfield_values
		WHERE object_id = s.stock_id
			AND field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
			AND COALESCE(value, '') != ''
		)
	AND NOT EXISTS(
		SELECT 1 FROM labels
		WHERE kind = 'stock_entry'
			AND target_id = s.id
			AND retired_at IS NULL
		)
GROUP BY s.product_id, s.best_before_date, s.purchased_date, s.price, s.legacy_zero_price, s.open, s.opened_date, s.location_id, s.shopping_location_id, COALESCE(s.note, '')
HAVING COUNT(*) > 1;
