-- migrations/0306.pgsql.sql (ADR-0041, issue #700): the source mapping table and the external-source
-- columns of consumption_events and consumption_event_lines.
--
-- Covers the constraints and foreign-key actions the migration adds. The migration creates no
-- function or trigger. Processing, locking and resolution are PHP and are covered by tests/Pgsql
-- (ConsumptionEvent*Test.php).

SELECT plan(43);

INSERT INTO users (username, password) VALUES ('c32 a', 'fixture'), ('c32 b', 'fixture');
INSERT INTO locations (name) VALUES ('C32 location');
INSERT INTO quantity_units (name) VALUES ('C32 tablet');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
SELECT 'C32 product', l.id, q.id, q.id, q.id, q.id
FROM locations l CROSS JOIN quantity_units q WHERE l.name = 'C32 location' AND q.name = 'C32 tablet';

CREATE FUNCTION pg_temp.u(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM users WHERE username = 'c32 ' || n $$;
CREATE FUNCTION pg_temp.prod() RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM products WHERE name = 'C32 product' $$;
CREATE FUNCTION pg_temp.loc() RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM locations WHERE name = 'C32 location' $$;

SELECT has_table('consumption_mappings');
SELECT has_column('consumption_events', 'source_updated_at');
SELECT has_column('consumption_events', 'linked_transaction_id');
SELECT has_column('consumption_event_lines', 'used_date');

-- Mappings ---------------------------------------------------------------------------------------

INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
VALUES (pg_temp.u('a'), 'healthkit', 'hk:med:1', 'product', pg_temp.prod(), 'single', now());
SELECT pass('a product mapping with a single-location rule is accepted');

SELECT is((SELECT quantity_factor FROM consumption_mappings WHERE medication_ref = 'hk:med:1'), 1::double precision, 'the quantity factor defaults to 1');
SELECT is((SELECT unit_labels FROM consumption_mappings WHERE medication_ref = 'hk:med:1'), '{}'::text[], 'a mapping starts with no confirmed unit label');

SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'hk:med:1', 'product', pg_temp.prod(), 'single', now())$$, '23505', NULL, 'a mapping is unique per user, source system and medication');
SELECT lives_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('b'), 'healthkit', 'hk:med:1', 'product', pg_temp.prod(), 'single', now())$$, 'another user has their own mapping for the same reference');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'manual', 'x', 'product', pg_temp.prod(), 'single', now())$$, '23514', NULL, 'manual is reserved and cannot be a mapping source');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'Health Kit', 'x', 'product', pg_temp.prod(), 'single', now())$$, '23514', NULL, 'a source system must be a lowercase token');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'has space', 'product', pg_temp.prod(), 'single', now())$$, '23514', NULL, 'a medication reference follows the identity pattern');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'no-product', 'product', 'single', now())$$, '23514', NULL, 'a product mapping names a product');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'recipe-with-product', 'recipe', pg_temp.prod(), 'single', now())$$, '23514', NULL, 'a recipe mapping names no product');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'bad-target', 'lot', pg_temp.prod(), 'single', now())$$, '23514', NULL, 'a target type is recipe or product');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'fixed-no-loc', 'product', pg_temp.prod(), 'fixed', now())$$, '23514', NULL, 'a fixed rule names its location');
SELECT throws_ok(format($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, location_id, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'single-with-loc', 'product', pg_temp.prod(), 'single', %s, now())$$, pg_temp.loc()), '23514', NULL, 'a single rule names no location');
SELECT lives_ok(format($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, location_id, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'fixed-ok', 'product', pg_temp.prod(), 'fixed', %s, now())$$, pg_temp.loc()), 'a fixed rule with a location is accepted');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'bad-mode', 'product', pg_temp.prod(), 'nearest', now())$$, '23514', NULL, 'a location mode is fixed, single or explicit');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, quantity_factor, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'zero-factor', 'product', pg_temp.prod(), 0, 'single', now())$$, '23514', NULL, 'a zero quantity factor is refused');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, default_quantity, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'zero-default', 'product', pg_temp.prod(), 0, 'single', now())$$, '23514', NULL, 'a zero default quantity is refused');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, unit_labels, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'blank-label', 'product', pg_temp.prod(), ARRAY['tablet', ''], 'single', now())$$, '23514', NULL, 'a blank unit label is refused');
SELECT throws_ok($$INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, unit_labels, location_mode, effective_from)
	VALUES (pg_temp.u('a'), 'healthkit', 'null-label', 'product', pg_temp.prod(), ARRAY['tablet', NULL], 'single', now())$$, '23514', NULL, 'a null unit label is refused');

-- A recipe target survives the recipe's deletion with no target, and a product target goes with its product.
INSERT INTO consumption_recipes (owner_user_id, name) VALUES (pg_temp.u('a'), 'C32 recipe');
INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, recipe_id, location_mode, effective_from)
SELECT pg_temp.u('a'), 'healthkit', 'hk:recipe', 'recipe', id, 'explicit', now() FROM consumption_recipes WHERE name = 'C32 recipe';
DELETE FROM consumption_recipes WHERE name = 'C32 recipe';
SELECT is((SELECT recipe_id FROM consumption_mappings WHERE medication_ref = 'hk:recipe'), NULL, 'a deleted recipe leaves its mapping in place with no target');

-- Events -----------------------------------------------------------------------------------------

SELECT id AS m FROM consumption_mappings WHERE user_id = pg_temp.u('a') AND medication_ref = 'hk:med:1' \gset

INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, mapping_id, medication_ref, submitted_status, quantity, unit_label)
VALUES (pg_temp.u('a'), 'healthkit', 'e1', 'needs_mapping', now(), :m, 'hk:med:1', 'taken', 1, 'tablet');
SELECT pass('an external event carries its mapping, medication, status, quantity and unit label');

SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, submitted_status)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-bad-status', 'received', now(), 'maybe')$$, '23514', NULL, 'a submitted status is one of the five source statuses');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, quantity)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-bad-qty', 'received', now(), 0)$$, '23514', NULL, 'a zero quantity is refused');
SELECT throws_ok(format($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, mapping_id)
	VALUES (pg_temp.u('a'), 'manual', 'm-mapped', 'received', now(), %s)$$, :m), '23514', NULL, 'a manual event names no mapping');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, source_updated_at)
	VALUES (pg_temp.u('a'), 'manual', 'm-supplied', 'received', now(), now())$$, '23514', NULL, 'a manual event carries no source-supplied version');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, source_removed_at)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-removed-no-reason', 'received', now(), now())$$, '23514', NULL, 'a removal time needs a removal reason');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, source_removed_at, source_removed_reason)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-removed-odd', 'received', now(), now(), 'bored')$$, '23514', NULL, 'a removal reason is one of the five documented');
SELECT lives_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, source_removed_at, source_removed_reason)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-removed-ok', 'received', now(), now(), 'access_revoked')$$, 'a documented removal reason with its time is accepted');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-voided-no-time', 'voided', now())$$, '23514', NULL, 'a voided event records when it was voided');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-linked-no-tx', 'linked', now())$$, '23514', NULL, 'a linked event names the transaction it is linked to');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, linked_transaction_id)
	VALUES (pg_temp.u('a'), 'healthkit', 'e-tx-not-linked', 'dismissed', now(), 'tx-1')$$, '23514', NULL, 'a linked transaction belongs to a linked event only');
INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, linked_transaction_id)
VALUES (pg_temp.u('a'), 'healthkit', 'e-linked-1', 'linked', now(), 'tx-1');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, linked_transaction_id)
	VALUES (pg_temp.u('b'), 'healthkit', 'e-linked-2', 'linked', now(), 'tx-1')$$, '23505', NULL, 'a transaction is linked to at most one event');
SELECT throws_ok($$UPDATE consumption_events SET replaces_event_id = id WHERE source_event_id = 'e1'$$, '23514', NULL, 'an event cannot replace itself');

-- Deleting a replaced event keeps the replacing one; deleting a mapping keeps an event that booked stock.
SELECT id AS e1 FROM consumption_events WHERE source_event_id = 'e1' \gset
INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, replaces_event_id)
VALUES (pg_temp.u('a'), 'healthkit', 'e2', 'received', now(), :e1);
DELETE FROM consumption_events WHERE id = :e1;
SELECT is((SELECT replaces_event_id FROM consumption_events WHERE source_event_id = 'e2'), NULL, 'deleting the replaced event clears the reference and keeps the other');

INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, mapping_id, transaction_id)
VALUES (pg_temp.u('a'), 'healthkit', 'e3', 'booked', now(), :m, 'tx-booked');
DELETE FROM consumption_mappings WHERE id = :m;
SELECT is((SELECT mapping_id FROM consumption_events WHERE source_event_id = 'e3'), NULL, 'deleting a mapping clears the reference and keeps the event');
SELECT is((SELECT state FROM consumption_events WHERE source_event_id = 'e3'), 'booked', 'the event that booked stock is still booked');

-- Lines ------------------------------------------------------------------------------------------

INSERT INTO consumption_event_lines (event_id, product_id, amount, used_date)
SELECT id, pg_temp.prod(), 1, DATE '2026-10-07' FROM consumption_events WHERE source_event_id = 'e3';
SELECT is((SELECT used_date FROM consumption_event_lines l JOIN consumption_events e ON e.id = l.event_id WHERE e.source_event_id = 'e3'),
	DATE '2026-10-07', 'a line keeps the date it was booked under');

-- A product target goes with its product.
INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, location_mode, effective_from)
VALUES (pg_temp.u('a'), 'healthkit', 'hk:gone', 'product', pg_temp.prod(), 'single', now());
DELETE FROM products WHERE name = 'C32 product';
SELECT is((SELECT count(*)::int FROM consumption_mappings WHERE medication_ref = 'hk:gone'), 0, 'deleting a product deletes the mappings that target it');

DELETE FROM users WHERE username IN ('c32 a', 'c32 b');
SELECT is((SELECT count(*)::int FROM consumption_mappings WHERE user_id NOT IN (SELECT id FROM users)), 0, 'deleting an account leaves no mapping behind');

SELECT * FROM finish();
