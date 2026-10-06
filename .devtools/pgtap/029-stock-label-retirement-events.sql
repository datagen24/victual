-- migrations/0303.pgsql.sql (ADR-0037, issue #612): every retirement of a stock-entry label
-- writes one stock_label_retirements event, and only a retirement whose transaction names a
-- live whole-row consumption booking of the deleted row and its amount is recorded as a
-- `consumption` an undo can claim. Everything else is `unproven`, closed at creation.
--
-- Covered here: the table's CHECKs and unique indexes, the history guard, the trigger for each
-- retirement path (whole-row consumption with and without context, a stale, forged, zero or
-- malformed context, an undone or mismatched booking, product deletion, a direct label update,
-- a non-stock label), the legacy backfill and its rerun, the R1/R2/R6 invariants over the
-- whole database, the R5 catalogue rule, and stock_amounts_equal().
--
-- The revival itself is application code (StockLabelRevivalService) and is
-- tests/Pgsql/StockLabelRevivalTest.php's subject, together with R7 and the undo examples.
-- The booking a real ConsumeProduct() writes is reproduced here with a plain INSERT, followed
-- by the two set_config() calls StockLabelRevivalService::SetRetirementContext() makes. Those
-- are transaction-local in the application; psql commits every statement here on its own, so
-- this file sets them for the session instead, and clears them at the end.

SELECT plan(48);

-- Fixtures ------------------------------------------------------------------------------

INSERT INTO locations (name) VALUES ('Spike29 location');
INSERT INTO quantity_units (name) VALUES ('Spike29 qu');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock)
SELECT 'Spike29 product', l.id, q.id, q.id FROM locations l, quantity_units q
WHERE l.name = 'Spike29 location' AND q.name = 'Spike29 qu';

CREATE TEMP TABLE spike29_rows (name TEXT PRIMARY KEY, stock_id BIGINT, uid TEXT, booking_id BIGINT);

-- One labelled stock row of amount 3 per case.
CREATE FUNCTION pg_temp.spike29_row(p_name TEXT) RETURNS BIGINT LANGUAGE plpgsql AS $$
DECLARE
	v_row BIGINT;
	v_uid TEXT := '9' || upper(substr(md5(random()::text), 1, 12));
BEGIN
	v_uid := translate(v_uid, 'ILOU', '1105');
	INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id)
	SELECT p.id, 3, 'spike29-' || p_name, '2999-12-31', '2026-01-01', p.location_id FROM products p WHERE p.name = 'Spike29 product'
	RETURNING id INTO v_row;
	INSERT INTO labels (uid, kind, target_id) VALUES (v_uid, 'stock_entry', v_row);
	INSERT INTO spike29_rows (name, stock_id, uid) VALUES (p_name, v_row, v_uid);
	RETURN v_row;
END
$$;

-- The booking ConsumeProduct() writes before it deletes a row whole.
CREATE FUNCTION pg_temp.spike29_booking(p_name TEXT, p_amount DOUBLE PRECISION DEFAULT -3, p_undone SMALLINT DEFAULT 0) RETURNS BIGINT LANGUAGE plpgsql AS $$
DECLARE
	v_booking BIGINT;
BEGIN
	INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, stock_row_id, transaction_type, transaction_id, user_id, undone, location_id)
	SELECT s.product_id, p_amount, s.best_before_date, s.purchased_date, s.stock_id, s.id, 'consume', 'spike29-' || p_name, 1, p_undone, s.location_id
	FROM stock s JOIN spike29_rows r ON r.stock_id = s.id WHERE r.name = p_name
	RETURNING id INTO v_booking;
	UPDATE spike29_rows SET booking_id = v_booking WHERE name = p_name;
	RETURN v_booking;
END
$$;

CREATE FUNCTION pg_temp.spike29_event(p_name TEXT) RETURNS stock_label_retirements LANGUAGE sql AS $$
	SELECT e.* FROM stock_label_retirements e JOIN spike29_rows r ON r.uid = e.label_uid WHERE r.name = p_name ORDER BY e.id DESC LIMIT 1
$$;

CREATE FUNCTION pg_temp.spike29_delete(p_name TEXT) RETURNS void LANGUAGE sql AS $$
	DELETE FROM stock WHERE id = (SELECT stock_id FROM spike29_rows WHERE name = p_name)
$$;

-- Catalogue: R5 and the indexes ---------------------------------------------------------------

SELECT has_table('stock_label_retirements');
SELECT is(
	(SELECT array_agg(confrelid::regclass::text) FROM pg_constraint WHERE conrelid = 'stock_label_retirements'::regclass AND contype = 'f'),
	ARRAY['labels'],
	'R5: the only foreign key names labels, never stock, stock_log, products or another imported table'
);
SELECT ok(EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'stock_label_retirements' AND indexname = 'stock_label_retirements_booking' AND indexdef LIKE 'CREATE UNIQUE%'), 'One event per (import_epoch, booking_id)');
SELECT ok(EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'stock_label_retirements' AND indexname = 'stock_label_retirements_one_pending' AND indexdef LIKE 'CREATE UNIQUE%'), 'At most one pending event per label');
SELECT ok(EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'print_jobs' AND indexname = 'print_jobs_label_uid_id'), 'print_jobs (label_uid, id) serves the trigger and the claim predicate');
SELECT has_trigger('labels', 'record_stock_label_retirement');
SELECT has_trigger('stock_label_retirements', 'guard_stock_label_retirement_history');

-- stock_amounts_equal(): StockService::CompareAmounts()'s tolerance --------------------------

SELECT ok(stock_amounts_equal(0.1 + 0.2, 0.3), 'Float residue is equal within the tolerance');
SELECT ok(NOT stock_amounts_equal(1, 1.001), 'A real difference is not');
SELECT ok(stock_amounts_equal(1e12, 1e12 + 0.5), 'The tolerance scales with the amounts');

-- Whole-row consumption with context: a claimable consumption event ---------------------------

SELECT pg_temp.spike29_row('proven');
WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid) SELECT o.id, 9290, (SELECT uid FROM spike29_rows WHERE name = 'proven') FROM o;
SELECT pg_temp.spike29_booking('proven');
SELECT set_config('victual.retiring_booking_id', (SELECT booking_id::text FROM spike29_rows WHERE name = 'proven'), false),
	set_config('victual.label_revival_window_seconds', '2592000', false);
SELECT pg_temp.spike29_delete('proven');

SELECT is((pg_temp.spike29_event('proven')).cause, 'consumption', 'Whole-row consumption with context: cause consumption');
SELECT is((pg_temp.spike29_event('proven')).booking_id, (SELECT booking_id FROM spike29_rows WHERE name = 'proven'), 'The event names the booking');
SELECT is((pg_temp.spike29_event('proven')).product_id, (SELECT id FROM products WHERE name = 'Spike29 product')::BIGINT, 'and its product');
SELECT is((pg_temp.spike29_event('proven')).amount, 3::DOUBLE PRECISION, 'and its amount');
SELECT is((pg_temp.spike29_event('proven')).import_epoch, label_current_import_epoch(), 'and the current import epoch');
SELECT is((pg_temp.spike29_event('proven')).revivable_until - (pg_temp.spike29_event('proven')).retired_at, interval '30 days', 'The deadline is the window after the retirement');
SELECT is((pg_temp.spike29_event('proven')).jobs_through_id, (SELECT max(id) FROM print_jobs WHERE printer_id = 9290)::BIGINT, 'jobs_through_id is the highest job of the label at retirement');
SELECT ok((pg_temp.spike29_event('proven')).outcome IS NULL, 'The event is pending');
SELECT is((pg_temp.spike29_event('proven')).snapshot, (SELECT retirement_snapshot FROM labels WHERE uid = (SELECT uid FROM spike29_rows WHERE name = 'proven')), 'R6: it copies the snapshot');

-- Every other retirement is unproven, closed at creation ------------------------------------

-- No context at all.
SELECT pg_temp.spike29_row('no context');
SELECT pg_temp.spike29_booking('no context');
SELECT set_config('victual.retiring_booking_id', '', false), set_config('victual.label_revival_window_seconds', '', false);
SELECT pg_temp.spike29_delete('no context');
SELECT is(ARRAY[(pg_temp.spike29_event('no context')).cause, (pg_temp.spike29_event('no context')).outcome, (pg_temp.spike29_event('no context')).reason],
	ARRAY['unproven', 'declined', 'unproven'], 'No context: unproven and closed');

-- A stale context: the booking of another row.
SELECT pg_temp.spike29_row('stale');
SELECT set_config('victual.retiring_booking_id', (SELECT booking_id::text FROM spike29_rows WHERE name = 'no context'), false),
	set_config('victual.label_revival_window_seconds', '2592000', false);
SELECT pg_temp.spike29_delete('stale');
SELECT is((pg_temp.spike29_event('stale')).cause, 'unproven', 'A booking that names another row proves nothing');

-- A forged context: no such booking.
SELECT pg_temp.spike29_row('forged');
SELECT set_config('victual.retiring_booking_id', '987654321', false), set_config('victual.label_revival_window_seconds', '2592000', false);
SELECT pg_temp.spike29_delete('forged');
SELECT is((pg_temp.spike29_event('forged')).cause, 'unproven', 'A booking that does not exist proves nothing');

-- A zero window.
SELECT pg_temp.spike29_row('zero window');
SELECT pg_temp.spike29_booking('zero window');
SELECT set_config('victual.retiring_booking_id', (SELECT booking_id::text FROM spike29_rows WHERE name = 'zero window'), false),
	set_config('victual.label_revival_window_seconds', '0', false);
SELECT pg_temp.spike29_delete('zero window');
SELECT is((pg_temp.spike29_event('zero window')).cause, 'unproven', 'A zero window records nothing claimable');

-- A malformed context never aborts the consumption.
SELECT pg_temp.spike29_row('malformed');
SELECT pg_temp.spike29_booking('malformed');
SELECT set_config('victual.retiring_booking_id', 'abc', false), set_config('victual.label_revival_window_seconds', '2592000', false);
SELECT lives_ok($$SELECT pg_temp.spike29_delete('malformed')$$, 'A malformed context does not abort the delete');
SELECT is((pg_temp.spike29_event('malformed')).cause, 'unproven', 'and records unproven');

-- A booking whose amount differs from the deleted row's.
SELECT pg_temp.spike29_row('short');
SELECT pg_temp.spike29_booking('short', -2);
SELECT set_config('victual.retiring_booking_id', (SELECT booking_id::text FROM spike29_rows WHERE name = 'short'), false),
	set_config('victual.label_revival_window_seconds', '2592000', false);
SELECT pg_temp.spike29_delete('short');
SELECT is((pg_temp.spike29_event('short')).cause, 'unproven', 'A booking of another amount proves nothing');

-- An undone booking.
SELECT pg_temp.spike29_row('undone');
SELECT pg_temp.spike29_booking('undone', -3, 1::SMALLINT);
SELECT set_config('victual.retiring_booking_id', (SELECT booking_id::text FROM spike29_rows WHERE name = 'undone'), false),
	set_config('victual.label_revival_window_seconds', '2592000', false);
SELECT pg_temp.spike29_delete('undone');
SELECT is((pg_temp.spike29_event('undone')).cause, 'unproven', 'An undone booking proves nothing');

-- A direct label update, the shape every retire_* function writes.
SELECT pg_temp.spike29_row('direct');
UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL, retirement_snapshot = '{"id": 0, "amount": 3}'
WHERE uid = (SELECT uid FROM spike29_rows WHERE name = 'direct');
SELECT is((pg_temp.spike29_event('direct')).cause, 'unproven', 'A direct label update records unproven');

-- Product deletion (trg_cascade_product_removal), with a valid-looking context still set.
SELECT pg_temp.spike29_row('product deletion');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock)
SELECT 'Spike29 doomed product', location_id, qu_id_purchase, qu_id_stock FROM products WHERE name = 'Spike29 product';
UPDATE stock SET product_id = (SELECT id FROM products WHERE name = 'Spike29 doomed product')
WHERE id = (SELECT stock_id FROM spike29_rows WHERE name = 'product deletion');
SELECT set_config('victual.retiring_booking_id', (SELECT booking_id::text FROM spike29_rows WHERE name = 'proven'), false),
	set_config('victual.label_revival_window_seconds', '2592000', false);
DELETE FROM products WHERE name = 'Spike29 doomed product';
SELECT is((pg_temp.spike29_event('product deletion')).cause, 'unproven', 'Product deletion records unproven');

-- A label of another kind writes no event.
INSERT INTO labels (uid, kind, target_id) SELECT '9ZZZZZZZZZZZZ', 'location', id FROM locations WHERE name = 'Spike29 location';
INSERT INTO locations (name) VALUES ('Spike29 doomed location');
UPDATE labels SET target_id = (SELECT id FROM locations WHERE name = 'Spike29 doomed location') WHERE uid = '9ZZZZZZZZZZZZ';
DELETE FROM locations WHERE name = 'Spike29 doomed location';
SELECT is((SELECT count(*) FROM stock_label_retirements WHERE label_uid = '9ZZZZZZZZZZZZ'), 0::BIGINT, 'A location label retirement writes no event');

-- CHECKs and unique indexes ------------------------------------------------------------------

SELECT throws_ok($$INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch) VALUES ((SELECT uid FROM spike29_rows WHERE name = 'proven'), now(), '{}', 'consumption', 0)$$,
	'23514', NULL, 'A consumption event needs its booking');
SELECT throws_ok($$INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch) VALUES ((SELECT uid FROM spike29_rows WHERE name = 'direct'), now(), '{}', 'unproven', 0)$$,
	'23514', NULL, 'An unproven event is closed at creation');
SELECT throws_ok($$INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, outcome, reason, closed_at) VALUES ((SELECT uid FROM spike29_rows WHERE name = 'direct'), now(), '{}', 'legacy', 'declined', 'unproven', now())$$,
	'23514', NULL, 'A legacy event carries reason legacy');
SELECT throws_ok($$INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch, booking_id, product_id, amount, jobs_through_id, revivable_until, outcome, closed_at)
	VALUES ((SELECT uid FROM spike29_rows WHERE name = 'direct'), now(), '{}', 'consumption', 0, 1, 1, 1, 0, now(), 'revived', now())$$,
	'23514', NULL, 'A revived event names its target');
SELECT throws_ok($$INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch, booking_id, product_id, amount, jobs_through_id, revivable_until, reason)
	VALUES ((SELECT uid FROM spike29_rows WHERE name = 'direct'), now(), '{}', 'consumption', 0, 2, 1, 1, 0, now(), 'expired')$$,
	'23514', NULL, 'A pending event has no reason');
SELECT throws_ok($$INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch, booking_id, product_id, amount, jobs_through_id, revivable_until)
	VALUES ((SELECT uid FROM spike29_rows WHERE name = 'proven'), now(), '{}', 'consumption', 0, 3, 1, 1, 0, now())$$,
	'23505', NULL, 'R2: a second pending event for one label is refused');
SELECT throws_ok($$INSERT INTO stock_label_retirements (label_uid, retired_at, snapshot, cause, import_epoch, booking_id, product_id, amount, jobs_through_id, revivable_until)
	SELECT (SELECT uid FROM spike29_rows WHERE name = 'direct'), now(), '{}', 'consumption', import_epoch, booking_id, 1, 1, 0, now() FROM stock_label_retirements WHERE booking_id = (SELECT booking_id FROM spike29_rows WHERE name = 'proven')$$,
	'23505', NULL, 'R3: (import_epoch, booking_id) is unique');

-- R4: the history guard --------------------------------------------------------------------------

SELECT throws_ok($$DELETE FROM stock_label_retirements WHERE id = (pg_temp.spike29_event('direct')).id$$,
	'P0001', NULL, 'An event is never deleted');
SELECT throws_ok($$UPDATE stock_label_retirements SET reason = 'expired' WHERE id = (pg_temp.spike29_event('direct')).id$$,
	'P0001', NULL, 'A closed event never changes');
SELECT throws_ok($$UPDATE stock_label_retirements SET booking_id = booking_id + 1, outcome = 'declined', reason = 'expired', closed_at = now() WHERE id = (pg_temp.spike29_event('proven')).id$$,
	'P0001', NULL, 'Closing an event changes nothing but its outcome columns');
SELECT lives_ok($$UPDATE stock_label_retirements SET outcome = 'declined', reason = 'expired', closed_at = now() WHERE id = (pg_temp.spike29_event('proven')).id$$,
	'A pending event closes once');

-- Legacy backfill and its rerun ---------------------------------------------------------------

-- A retirement that predates the table: inserted already retired, so no trigger fires.
INSERT INTO labels (uid, kind, retired_at, retirement_snapshot) VALUES ('9EGACYAAAAAAA', 'stock_entry', now() - interval '90 days', '{"id": 1, "amount": 1}');
SELECT is(backfill_legacy_stock_label_retirements(), 1::BIGINT, 'The backfill writes one legacy event');
SELECT is(ARRAY[e.cause, e.outcome, e.reason], ARRAY['legacy', 'declined', 'legacy'], 'closed, never revivable')
FROM stock_label_retirements e WHERE e.label_uid = '9EGACYAAAAAAA';
SELECT ok((SELECT booking_id IS NULL AND import_epoch IS NULL AND revivable_until IS NULL FROM stock_label_retirements WHERE label_uid = '9EGACYAAAAAAA'),
	'and infers no booking, epoch or deadline');
SELECT is(backfill_legacy_stock_label_retirements(), 0::BIGINT, 'A second run writes nothing');

-- Invariants over the whole database ----------------------------------------------------------

SELECT is(stock_label_retirements_incomplete(), 0::BIGINT, 'R1: every retired stock-entry label has exactly one event');
SELECT is((SELECT count(*) FROM labels l WHERE l.retired_at IS NULL
	AND EXISTS (SELECT 1 FROM stock_label_retirements e WHERE e.label_uid = l.uid AND e.outcome IS NULL)), 0::BIGINT,
	'R2: no live label has a pending event');
SELECT is((SELECT count(*) FROM labels l
	JOIN LATERAL (SELECT snapshot FROM stock_label_retirements e WHERE e.label_uid = l.uid ORDER BY e.id DESC LIMIT 1) latest ON true
	WHERE l.kind = 'stock_entry' AND l.retired_at IS NOT NULL AND latest.snapshot IS DISTINCT FROM l.retirement_snapshot), 0::BIGINT,
	'R6: every retired label''s snapshot equals its latest event''s');

SELECT set_config('victual.retiring_booking_id', '', false), set_config('victual.label_revival_window_seconds', '', false);

SELECT * FROM finish();
