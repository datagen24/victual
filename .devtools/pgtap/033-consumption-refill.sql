-- migrations/0307.pgsql.sql (ADR-0042, issue #701): refill settings, the fill history, explicit
-- reorder dates, orders and notice acknowledgements for a private consumption recipe.
--
-- Covers the constraints, the cascades and the six trigger functions the migration adds: the fill
-- history is immutable apart from one void, a newer fill or a void ends the explicit date it
-- supersedes and never revives one, an explicit date is not earlier than its fill, one live date and
-- one open order exist per recipe, and a closed order stays closed. The estimate, the status, the
-- notices and the rights are PHP and are covered by tests/Pgsql (ConsumptionRefill*Test.php).

SELECT plan(84);

INSERT INTO users (username, password) VALUES ('c33 owner', 'fixture'), ('c33 member', 'fixture');

CREATE FUNCTION pg_temp.u(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM users WHERE username = 'c33 ' || n $$;
CREATE FUNCTION pg_temp.r(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM consumption_recipes WHERE name = 'c33 ' || n $$;

INSERT INTO consumption_recipes (owner_user_id, name) SELECT u.id, 'c33 ' || n FROM users u CROSS JOIN unnest(ARRAY['settings', 'fills', 'dates', 'orders', 'other', 'acks', 'cascade']) AS n WHERE u.username = 'c33 owner';

CREATE TEMP TABLE c33_ledger AS SELECT count(*) AS n FROM stock_log;

SELECT has_table('consumption_refill_settings');
SELECT has_table('consumption_refill_fills');
SELECT has_table('consumption_refill_dates');
SELECT has_table('consumption_refill_orders');
SELECT has_table('consumption_refill_acks');
SELECT has_trigger('consumption_refill_fills', 'consumption_refill_fill_immutable');
SELECT has_trigger('consumption_refill_fills', 'consumption_refill_fill_supersedes_dates');
SELECT has_trigger('consumption_refill_fills', 'consumption_refill_void_ends_dates');
SELECT has_trigger('consumption_refill_dates', 'consumption_refill_date_not_before_fill');
SELECT has_trigger('consumption_refill_dates', 'consumption_refill_date_ends_once');
SELECT has_trigger('consumption_refill_orders', 'consumption_refill_order_closed_final');

-- Settings: the rule vocabulary and its ranges --------------------------------------------------

SELECT lives_ok(format($$INSERT INTO consumption_refill_settings (recipe_id, rule_kind, rule_parameter, warning_lead_days) VALUES (%s, 'days_before_end', 0, 0)$$, pg_temp.r('settings')),
	'days_before_end 0 with a lead of 0 is accepted');
SELECT throws_ok(format($$INSERT INTO consumption_refill_settings (recipe_id) VALUES (%s)$$, pg_temp.r('settings')), '23505', NULL, 'a recipe has one settings row');
SELECT lives_ok(format($$UPDATE consumption_refill_settings SET rule_kind = 'days_before_end', rule_parameter = 730 WHERE recipe_id = %s$$, pg_temp.r('settings')), 'days_before_end 730 is accepted');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_parameter = 731 WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'days_before_end 731 is refused');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_parameter = -1 WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'a negative days_before_end is refused');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_kind = 'fixed_interval', rule_parameter = 0 WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'fixed_interval 0 is refused');
SELECT lives_ok(format($$UPDATE consumption_refill_settings SET rule_kind = 'fixed_interval', rule_parameter = 730 WHERE recipe_id = %s$$, pg_temp.r('settings')), 'fixed_interval 730 is accepted');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_kind = 'fraction_elapsed', rule_parameter = 0 WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'fraction_elapsed 0 percent is refused');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_kind = 'fraction_elapsed', rule_parameter = 100 WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'fraction_elapsed 100 percent is refused');
SELECT lives_ok(format($$UPDATE consumption_refill_settings SET rule_kind = 'fraction_elapsed', rule_parameter = 99 WHERE recipe_id = %s$$, pg_temp.r('settings')), 'fraction_elapsed 99 percent is accepted');
SELECT lives_ok(format($$UPDATE consumption_refill_settings SET rule_parameter = 1 WHERE recipe_id = %s$$, pg_temp.r('settings')), 'fraction_elapsed 1 percent is accepted');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_kind = 'percent' WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'an unknown rule kind is refused');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_parameter = NULL WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'a rule kind needs its parameter');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET rule_kind = NULL WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'a rule parameter needs its kind');
SELECT lives_ok(format($$UPDATE consumption_refill_settings SET rule_kind = NULL, rule_parameter = NULL, warning_lead_days = 60 WHERE recipe_id = %s$$, pg_temp.r('settings')),
	'a lead override without a rule is accepted, and 60 is the largest lead');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET warning_lead_days = 61 WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'a lead of 61 is refused');
SELECT throws_ok(format($$UPDATE consumption_refill_settings SET warning_lead_days = -1 WHERE recipe_id = %s$$, pg_temp.r('settings')), '23514', NULL, 'a negative lead is refused');

-- Fills: ranges and immutability -----------------------------------------------------------------

SELECT lives_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (%s, '2026-01-01', 90)$$, pg_temp.r('fills')), 'a 90-day fill is accepted');
SELECT lives_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on) VALUES (%s, '2026-02-01')$$, pg_temp.r('fills')), 'a fill with no supplied days is accepted');
SELECT throws_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (%s, '2026-02-02', 0)$$, pg_temp.r('fills')), '23514', NULL, 'supplied_days 0 is refused');
SELECT throws_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (%s, '2026-02-02', 731)$$, pg_temp.r('fills')), '23514', NULL, 'supplied_days 731 is refused');
SELECT lives_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (%s, '2026-02-03', 730)$$, pg_temp.r('fills')), 'supplied_days 730 is accepted');
SELECT throws_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (%s, NULL, 30)$$, pg_temp.r('fills')), '23502', NULL, 'a fill needs its date; the database supplies none');
SELECT throws_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days, note) VALUES (%s, '2026-02-04', 30, '  ')$$, pg_temp.r('fills')), '23514', NULL, 'a blank note is refused');
SELECT throws_ok(format($$INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days, voided_at) VALUES (%s, '2026-02-05', 30, now())$$, pg_temp.r('fills')), '23514', NULL, 'a void needs its reason');
SELECT throws_ok(format($$UPDATE consumption_refill_fills SET filled_on = '2026-01-02' WHERE recipe_id = %s AND filled_on = '2026-01-01'$$, pg_temp.r('fills')), '23514', NULL, 'a fill date cannot be edited');
SELECT throws_ok(format($$UPDATE consumption_refill_fills SET supplied_days = 30 WHERE recipe_id = %s AND filled_on = '2026-01-01'$$, pg_temp.r('fills')), '23514', NULL, 'the supplied days cannot be edited');
SELECT throws_ok(format($$UPDATE consumption_refill_fills SET note = 'changed' WHERE recipe_id = %s AND filled_on = '2026-01-01'$$, pg_temp.r('fills')), '23514', NULL, 'the note cannot be edited');
SELECT lives_ok(format($$UPDATE consumption_refill_fills SET voided_at = now(), void_reason = 'entered the wrong date' WHERE recipe_id = %s AND filled_on = '2026-01-01'$$, pg_temp.r('fills')), 'a fill can be voided with a reason');
SELECT throws_ok(format($$UPDATE consumption_refill_fills SET voided_at = NULL, void_reason = NULL WHERE recipe_id = %s AND filled_on = '2026-01-01'$$, pg_temp.r('fills')), '23514', NULL, 'a voided fill cannot be revived');
SELECT throws_ok(format($$UPDATE consumption_refill_fills SET void_reason = 'another reason' WHERE recipe_id = %s AND filled_on = '2026-01-01'$$, pg_temp.r('fills')), '23514', NULL, 'a void reason is not rewritten');
SELECT is((SELECT count(*)::int FROM consumption_refill_fills WHERE recipe_id = pg_temp.r('fills')), 3, 'the voided fill is still history');

-- Explicit dates: one live row, never earlier than the fill, ended by a newer fill or a void -------

INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (pg_temp.r('dates'), DATE '2026-03-01', 90);
INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on)
SELECT pg_temp.r('dates'), id, DATE '2026-05-15' FROM consumption_refill_fills WHERE recipe_id = pg_temp.r('dates') AND filled_on = '2026-03-01';
SELECT pass('an explicit date for the current fill is accepted');

SELECT throws_ok(format($$INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on)
	SELECT recipe_id, id, DATE '2026-05-16' FROM consumption_refill_fills WHERE recipe_id = %s AND filled_on = '2026-03-01'$$, pg_temp.r('dates')),
	'23505', NULL, 'a recipe has one live explicit date');
SELECT throws_ok(format($$INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on, ended_at, ended_reason)
	SELECT recipe_id, id, DATE '2026-02-28', now(), 'cleared' FROM consumption_refill_fills WHERE recipe_id = %s AND filled_on = '2026-03-01'$$, pg_temp.r('dates')),
	'23514', NULL, 'an explicit date earlier than its fill is refused');
SELECT lives_ok(format($$INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on, ended_at, ended_reason)
	SELECT recipe_id, id, DATE '2026-03-01', now(), 'cleared' FROM consumption_refill_fills WHERE recipe_id = %s AND filled_on = '2026-03-01'$$, pg_temp.r('dates')),
	'an explicit date on the fill date itself is accepted');
SELECT throws_ok(format($$INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on)
	SELECT %s, id, DATE '2026-06-01' FROM consumption_refill_fills WHERE recipe_id = %s LIMIT 1$$, pg_temp.r('other'), pg_temp.r('dates')),
	'23503', NULL, 'an explicit date cannot name the fill of another recipe');
SELECT throws_ok(format($$UPDATE consumption_refill_dates SET reorder_on = DATE '2026-06-01' WHERE recipe_id = %s AND ended_at IS NULL$$, pg_temp.r('dates')),
	'23514', NULL, 'an explicit date cannot be edited');
SELECT throws_ok(format($$UPDATE consumption_refill_dates SET ended_at = now() WHERE recipe_id = %s AND ended_at IS NULL$$, pg_temp.r('dates')),
	'23514', NULL, 'an end needs its reason');
SELECT throws_ok(format($$UPDATE consumption_refill_dates SET ended_reason = 'sometime' WHERE recipe_id = %s AND ended_at IS NULL$$, pg_temp.r('dates')),
	'23514', NULL, 'an end reason needs its instant');

-- A backdated fill is not newer: the live date stays.
INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (pg_temp.r('dates'), DATE '2026-02-01', 30);
SELECT is((SELECT count(*)::int FROM consumption_refill_dates WHERE recipe_id = pg_temp.r('dates') AND ended_at IS NULL), 1, 'a fill dated before the date''s fill leaves the explicit date live');

-- A newer fill ends it, with the reason.
INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (pg_temp.r('dates'), DATE '2026-05-20', 90);
SELECT is((SELECT ended_reason FROM consumption_refill_dates WHERE recipe_id = pg_temp.r('dates') AND reorder_on = '2026-05-15'), 'superseded', 'a newer fill ends the explicit date as superseded');
SELECT isnt((SELECT ended_at FROM consumption_refill_dates WHERE recipe_id = pg_temp.r('dates') AND reorder_on = '2026-05-15'), NULL, 'and records when');

-- Voiding the newer fill does not bring it back.
UPDATE consumption_refill_fills SET voided_at = now(), void_reason = 'wrong fill' WHERE recipe_id = pg_temp.r('dates') AND filled_on = '2026-05-20';
SELECT is((SELECT count(*)::int FROM consumption_refill_dates WHERE recipe_id = pg_temp.r('dates') AND ended_at IS NULL), 0, 'voiding the newer fill revives no explicit date');
SELECT throws_ok(format($$UPDATE consumption_refill_dates SET ended_at = NULL, ended_reason = NULL WHERE recipe_id = %s AND reorder_on = '2026-05-15'$$, pg_temp.r('dates')),
	'23514', NULL, 'an ended explicit date cannot be revived by a write either');

-- A new live date is accepted once the old one has ended, and a void of its own fill ends it.
INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on)
SELECT pg_temp.r('dates'), id, DATE '2026-05-10' FROM consumption_refill_fills WHERE recipe_id = pg_temp.r('dates') AND filled_on = '2026-03-01';
SELECT pass('a new explicit date is accepted after the previous one ended');
UPDATE consumption_refill_fills SET voided_at = now(), void_reason = 'wrong fill' WHERE recipe_id = pg_temp.r('dates') AND filled_on = '2026-03-01';
SELECT is((SELECT ended_reason FROM consumption_refill_dates WHERE recipe_id = pg_temp.r('dates') AND reorder_on = '2026-05-10'), 'fill_voided', 'voiding the fill a date belongs to ends the date');
SELECT throws_ok(format($$INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on)
	SELECT recipe_id, id, DATE '2026-09-01' FROM consumption_refill_fills WHERE recipe_id = %s AND filled_on = '2026-03-01'$$, pg_temp.r('dates')),
	'23514', NULL, 'a live explicit date cannot be entered for a voided fill');

-- Orders -------------------------------------------------------------------------------------------

INSERT INTO consumption_refill_orders (recipe_id, ordered_on) VALUES (pg_temp.r('orders'), DATE '2026-04-01');
SELECT is((SELECT state FROM consumption_refill_orders WHERE recipe_id = pg_temp.r('orders')), 'open', 'an order starts open');
SELECT throws_ok(format($$INSERT INTO consumption_refill_orders (recipe_id, ordered_on) VALUES (%s, '2026-04-02')$$, pg_temp.r('orders')), '23505', NULL, 'a recipe has at most one open order');
SELECT throws_ok(format($$INSERT INTO consumption_refill_orders (recipe_id, ordered_on) VALUES (%s, NULL)$$, pg_temp.r('other')), '23502', NULL, 'an order needs its date; the database supplies none');
SELECT lives_ok(format($$INSERT INTO consumption_refill_orders (recipe_id, ordered_on) VALUES (%s, '2026-04-02')$$, pg_temp.r('other')), 'another recipe has its own open order');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET state = 'received' WHERE recipe_id = %s$$, pg_temp.r('orders')), '23514', NULL, 'a received order needs its fill and its close time');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET state = 'cancelled' WHERE recipe_id = %s$$, pg_temp.r('orders')), '23514', NULL, 'a cancelled order needs its close time');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET state = 'lost', closed_at = now() WHERE recipe_id = %s$$, pg_temp.r('orders')), '23514', NULL, 'an unknown order state is refused');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET ordered_on = '2026-04-03' WHERE recipe_id = %s$$, pg_temp.r('orders')), '23514', NULL, 'an order date cannot be edited');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET state = 'received', closed_at = now(), received_fill_id = (SELECT id FROM consumption_refill_fills WHERE recipe_id = %s LIMIT 1) WHERE recipe_id = %s$$, pg_temp.r('fills'), pg_temp.r('orders')),
	'23503', NULL, 'an order cannot be received with the fill of another recipe');

INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (pg_temp.r('orders'), DATE '2026-04-05', 30);
UPDATE consumption_refill_orders SET state = 'received', closed_at = now(),
	received_fill_id = (SELECT id FROM consumption_refill_fills WHERE recipe_id = pg_temp.r('orders') AND filled_on = '2026-04-05')
WHERE recipe_id = pg_temp.r('orders');
SELECT is((SELECT state FROM consumption_refill_orders WHERE recipe_id = pg_temp.r('orders')), 'received', 'an open order can be received with a fill of its recipe');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET state = 'open', closed_at = NULL, received_fill_id = NULL WHERE recipe_id = %s$$, pg_temp.r('orders')), '23514', NULL, 'a received order cannot be reopened');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET state = 'cancelled', received_fill_id = NULL WHERE recipe_id = %s$$, pg_temp.r('orders')), '23514', NULL, 'a received order cannot be cancelled');
SELECT lives_ok(format($$INSERT INTO consumption_refill_orders (recipe_id, ordered_on) VALUES (%s, '2026-07-01')$$, pg_temp.r('orders')), 'a new order is accepted once the previous one closed');

UPDATE consumption_refill_orders SET state = 'cancelled', closed_at = now() WHERE recipe_id = pg_temp.r('other');
SELECT throws_ok(format($$UPDATE consumption_refill_orders SET state = 'open', closed_at = NULL WHERE recipe_id = %s AND state = 'cancelled'$$, pg_temp.r('other')), '23514', NULL, 'a cancelled order cannot be reopened');

-- Acknowledgements ---------------------------------------------------------------------------------

INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (pg_temp.u('owner'), pg_temp.r('acks'), 'due', DATE '2026-03-18');
SELECT pass('an acknowledgement is stored with its recipe, kind and date');
SELECT throws_ok(format($$INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (%s, %s, 'due', '2026-03-18')$$, pg_temp.u('owner'), pg_temp.r('acks')), '23505', NULL, 'the same acknowledgement twice is one row');
SELECT lives_ok(format($$INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (%s, %s, 'due', '2026-03-18')$$, pg_temp.u('member'), pg_temp.r('acks')), 'another user acknowledges independently');
SELECT lives_ok(format($$INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (%s, %s, 'approaching', '2026-03-18'), (%s, %s, 'due', '2026-03-19')$$, pg_temp.u('owner'), pg_temp.r('acks'), pg_temp.u('owner'), pg_temp.r('acks')),
	'another kind, and another date, are other notices');
SELECT throws_ok(format($$INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (%s, %s, 'overdue', '2026-03-18')$$, pg_temp.u('owner'), pg_temp.r('acks')), '23514', NULL, 'an unknown notice kind is refused');
SELECT throws_ok(format($$INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (%s, 0, 'due', '2026-03-18')$$, pg_temp.u('owner')), '23503', NULL, 'an acknowledgement names an existing recipe');
DELETE FROM users WHERE id = pg_temp.u('member');
SELECT is((SELECT count(*)::int FROM consumption_refill_acks WHERE recipe_id = pg_temp.r('acks')), 3, 'deleting a user removes only that user''s acknowledgements');

-- Deleting a recipe removes everything that belongs to it -------------------------------------------

INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES (pg_temp.r('cascade'), DATE '2026-01-01', 30);
INSERT INTO consumption_refill_settings (recipe_id, warning_lead_days) VALUES (pg_temp.r('cascade'), 3);
INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on)
SELECT recipe_id, id, DATE '2026-01-20' FROM consumption_refill_fills WHERE recipe_id = pg_temp.r('cascade');
INSERT INTO consumption_refill_orders (recipe_id, ordered_on, state, closed_at, received_fill_id)
SELECT recipe_id, DATE '2026-01-25', 'received', now(), id FROM consumption_refill_fills WHERE recipe_id = pg_temp.r('cascade');
INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (pg_temp.u('owner'), pg_temp.r('cascade'), 'due', DATE '2026-01-20');
SELECT lives_ok(format($$DELETE FROM consumption_recipes WHERE id = %s$$, pg_temp.r('cascade')), 'a recipe with a received order, a date and acknowledgements can be deleted');
SELECT is((SELECT count(*)::int FROM (
		SELECT recipe_id FROM consumption_refill_settings WHERE recipe_id NOT IN (SELECT id FROM consumption_recipes)
		UNION ALL SELECT recipe_id FROM consumption_refill_fills WHERE recipe_id NOT IN (SELECT id FROM consumption_recipes)
		UNION ALL SELECT recipe_id FROM consumption_refill_dates WHERE recipe_id NOT IN (SELECT id FROM consumption_recipes)
		UNION ALL SELECT recipe_id FROM consumption_refill_orders WHERE recipe_id NOT IN (SELECT id FROM consumption_recipes)
		UNION ALL SELECT recipe_id FROM consumption_refill_acks WHERE recipe_id NOT IN (SELECT id FROM consumption_recipes)) orphans), 0,
	'no refill row outlives its recipe');

DELETE FROM users WHERE id = pg_temp.u('owner');
SELECT is((SELECT count(*)::int FROM consumption_refill_fills WHERE recipe_id IN (SELECT id FROM consumption_recipes WHERE name LIKE 'c33 %')), 0, 'deleting the owner deletes the recipes and, with them, the fill history');
SELECT is((SELECT count(*) FROM stock_log), (SELECT n FROM c33_ledger), 'nothing here wrote the stock ledger');

SELECT * FROM finish();
