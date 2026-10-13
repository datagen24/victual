-- migrations/0309.pgsql.sql (audit 2026-10-12, issues #864 and #867): the transaction-roles trigger on
-- consumption_events and the SET NULL action of consumption_event_lines.location_id.
--
-- A stock transaction is the booking of one event or the link of another, never both, except that an
-- event of the same user may link to that user's own `manual` event's transaction (ADR-0041 rule 9).
-- Linking, locking and the service's mapping of the violation are PHP and are covered by
-- tests/Pgsql/ConsumptionEventsAuditTest.php.

SELECT plan(17);

INSERT INTO users (username, password) VALUES ('c34 a', 'fixture'), ('c34 b', 'fixture');
INSERT INTO locations (name) VALUES ('C34 location');
INSERT INTO quantity_units (name) VALUES ('C34 tablet');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
SELECT 'C34 product', l.id, q.id, q.id, q.id, q.id
FROM locations l CROSS JOIN quantity_units q WHERE l.name = 'C34 location' AND q.name = 'C34 tablet';

CREATE FUNCTION pg_temp.u(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM users WHERE username = 'c34 ' || n $$;

SELECT has_trigger('consumption_events', 'consumption_event_transaction_roles', 'the trigger is installed');

INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, occurred_at)
VALUES (pg_temp.u('a'), 'healthkit', 'booked-1', 'booked', 't34-1', now());
SELECT throws_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, linked_transaction_id, occurred_at)
	VALUES (%s, 'healthkit', 'link-1', 'linked', 't34-1', now())$$, pg_temp.u('a')), '23505', NULL, 'an imported booking cannot also be a link target');

INSERT INTO consumption_events (user_id, source_system, source_event_id, state, linked_transaction_id, occurred_at)
VALUES (pg_temp.u('a'), 'healthkit', 'link-2', 'linked', 't34-2', now());
SELECT throws_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, occurred_at)
	VALUES (%s, 'healthkit', 'booked-2', 'booked', 't34-2', now())$$, pg_temp.u('a')), '23505', NULL, 'a linked transaction cannot become an imported booking');

INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, occurred_at)
VALUES (pg_temp.u('a'), 'manual', 'manual-3', 'booked', 't34-3', now());
SELECT lives_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, linked_transaction_id, occurred_at)
	VALUES (%s, 'healthkit', 'link-3', 'linked', 't34-3', now())$$, pg_temp.u('a')), 'rule 9: an event links to the same user''s own manual transaction');

INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, occurred_at)
VALUES (pg_temp.u('b'), 'manual', 'manual-4', 'booked', 't34-4', now());
SELECT throws_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, linked_transaction_id, occurred_at)
	VALUES (%s, 'healthkit', 'link-4', 'linked', 't34-4', now())$$, pg_temp.u('a')), '23505', NULL, 'another user''s manual transaction is not the caller''s own');

SELECT throws_ok(format($$UPDATE consumption_events SET linked_transaction_id = 't34-1' WHERE user_id = %s AND source_event_id = 'link-2'$$, pg_temp.u('a')),
	'23505', NULL, 'moving a link onto an imported booking is refused as well');
SELECT is((SELECT linked_transaction_id FROM consumption_events WHERE source_event_id = 'link-2'), 't34-2', 'and the refused update left the link where it was');

SELECT lives_ok(format($$UPDATE consumption_events SET state = 'dismissed', transaction_id = NULL WHERE user_id = %s AND source_event_id = 'booked-1'$$, pg_temp.u('a')),
	'an update of other columns is not judged');

-- Link also refuses a self-reference and a manual holder that is itself linked.
SELECT throws_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, linked_transaction_id, occurred_at)
	VALUES (%s, 'healthkit', 'self-5', 'linked', 't34-5', 't34-5', now())$$, pg_temp.u('a')), '23505', NULL, 'an event cannot be linked to its own booking transaction');
INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, linked_transaction_id, occurred_at)
VALUES (pg_temp.u('a'), 'manual', 'manual-6', 'linked', 't34-6', 't34-6x', now());
SELECT throws_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, linked_transaction_id, occurred_at)
	VALUES (%s, 'healthkit', 'link-6', 'linked', 't34-6', now())$$, pg_temp.u('a')), '23505', NULL, 'a manual holder that is itself linked is not a rule 9 holder');
-- The other order: an event is linked to T7 first, then a manual event that is itself linked books T7.
INSERT INTO consumption_events (user_id, source_system, source_event_id, state, linked_transaction_id, occurred_at)
VALUES (pg_temp.u('a'), 'healthkit', 'link-7', 'linked', 't34-7', now());
SELECT throws_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, linked_transaction_id, occurred_at)
	VALUES (%s, 'manual', 'manual-7', 'linked', 't34-7', 't34-7x', now())$$, pg_temp.u('a')), '23505', NULL, 'a linked manual event cannot hold a transaction another event links to');
SELECT has_index('stock_log', 'ix_stock_log_transaction_id', 'stock_log is indexed by transaction id');
SELECT has_index('stock_log', 'ix_stock_log_transaction_id', ARRAY['transaction_id'], 'on exactly that column');

-- consumption_event_lines.location_id is history: deleting the location keeps the line.
INSERT INTO locations (name) VALUES ('C34 line location');
INSERT INTO consumption_event_lines (event_id, product_id, amount, location_id)
SELECT e.id, p.id, 1, l.id FROM consumption_events e, products p, locations l
WHERE e.source_event_id = 'manual-3' AND p.name = 'C34 product' AND l.name = 'C34 line location';
SELECT fk_ok('consumption_event_lines', 'location_id', 'locations', 'id', 'the line''s location is a foreign key');
SELECT is((SELECT confdeltype::text FROM pg_constraint WHERE conrelid = 'consumption_event_lines'::regclass AND contype = 'f' AND confrelid = 'locations'::regclass), 'n', 'whose action is SET NULL');
SELECT lives_ok($$DELETE FROM locations WHERE name = 'C34 line location'$$, 'a location named only by an event line can be deleted');
SELECT is((SELECT location_id FROM consumption_event_lines WHERE event_id = (SELECT id FROM consumption_events WHERE source_event_id = 'manual-3')), NULL::int, 'and the line survives with no location');

SELECT * FROM finish();
