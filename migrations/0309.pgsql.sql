-- Audit 2026-10-12 on the consumption events (issues #864 and #867).
--
-- WHAT THIS DOES.
--   - consumption_event_lines.location_id becomes ON DELETE SET NULL (#867). A line is history, "as
--     booked", like stock_log.location_id, which has no foreign key at all: the default NO ACTION made
--     the first line that named a location refuse its deletion even when nothing was stocked there. The
--     sibling consumption_events.requested_location_id (0306) already clears the same way. The
--     constraint is found by what it is, not by its name.
--   - A trigger on consumption_events that refuses one stock transaction used as the booking of one
--     event and the link of another (#864). The two partial unique indexes (0305, 0306) each cover one
--     column, so the database accepted a transaction counted for two doses. ADR-0041 rule 9 permits
--     exactly one such pair: the caller's own `manual` event, itself linked to nothing, holds the
--     transaction and an event of the same user is linked to it, which is what linking is for. An event
--     whose transaction_id equals its own linked_transaction_id is refused too. Anything else is a unique violation
--     (23505), the SQLSTATE the two indexes raise, so the service maps all three the same way. A
--     per-transaction advisory lock makes the check see a concurrent writer's committed row.
--   - An index on stock_log (transaction_id). Nothing indexed it: every read of one transaction's bookings
--     (UndoTransaction, the live-booking count, EffectiveState) scanned the ledger, and the event list
--     now derives each candidate event's state from it in SQL, which would have scanned once per event.
--
-- UPGRADE. The trigger only judges later writes, but a row that already breaks it would make the next
-- update of that row fail, so the migration first lists such pairs and stops. Nothing is repaired.
--
-- RERUNNABLE. The foreign key is replaced only while it is not already SET NULL, the function is
-- CREATE OR REPLACE and the trigger is dropped before it is created.

DO $$
DECLARE
	offender_count BIGINT;
	sample TEXT;
BEGIN
	WITH offenders AS (
		SELECT b.id AS linking_id, b.source_system AS linking_system, b.source_event_id AS linking_event, a.id AS holder_id
		FROM consumption_events b
		JOIN consumption_events a ON a.transaction_id = b.linked_transaction_id
		WHERE a.id = b.id
			OR NOT (a.source_system = 'manual' AND a.user_id = b.user_id AND a.linked_transaction_id IS NULL)
	)
	SELECT (SELECT count(*) FROM offenders),
		(SELECT string_agg(format('(event %s %s/%s, holder event %s)', linking_id, linking_system, linking_event, holder_id), ', ' ORDER BY linking_id)
			FROM (SELECT * FROM offenders ORDER BY linking_id LIMIT 10) first_ten)
	INTO offender_count, sample;

	IF offender_count > 0 THEN
		RAISE EXCEPTION 'Migration 0309 stopped: % consumption events are linked to a stock transaction that is also an event''s own booking, which ADR-0041 rule 9 allows only for the same user''s own unlinked manual event. Sample (linking event, holder): %. Resolve these events, then run the migration again. Nothing has been changed.', offender_count, sample;
	END IF;
END
$$;

DO $$
DECLARE
	fk RECORD;
BEGIN
	SELECT c.conname, c.confdeltype INTO fk
	FROM pg_constraint c
	WHERE c.conrelid = 'consumption_event_lines'::regclass
		AND c.contype = 'f'
		AND c.confrelid = 'locations'::regclass
		AND c.conkey = ARRAY[(SELECT a.attnum FROM pg_attribute a WHERE a.attrelid = 'consumption_event_lines'::regclass AND a.attname = 'location_id')];

	IF NOT FOUND THEN
		RAISE EXCEPTION 'consumption_event_lines.location_id has no foreign key to locations to replace';
	END IF;

	IF fk.confdeltype <> 'n' THEN
		EXECUTE format('ALTER TABLE consumption_event_lines DROP CONSTRAINT %I', fk.conname);
		ALTER TABLE consumption_event_lines ADD CONSTRAINT consumption_event_lines_location_id_fkey
			FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL;
	END IF;
END
$$;

CREATE INDEX IF NOT EXISTS ix_stock_log_transaction_id ON stock_log (transaction_id);

CREATE OR REPLACE FUNCTION trg_consumption_event_transaction_roles() RETURNS TRIGGER AS $$
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
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS consumption_event_transaction_roles ON consumption_events;
CREATE TRIGGER consumption_event_transaction_roles BEFORE INSERT OR UPDATE OF transaction_id, linked_transaction_id, user_id, source_system ON consumption_events
FOR EACH ROW EXECUTE FUNCTION trg_consumption_event_transaction_roles();
