-- Issue #487 remediation (WS-15), against the fully migrated application schema:
-- migrations/0289.pgsql.sql recreates stock_current, uihelper_stock_journal and
-- chores_current. Each case below reproduces the exact defect its issue describes and
-- asserts the value the corrected view now returns, plus a negative control proving the
-- ordinary (already-correct) case is unaffected.

SELECT plan(10);

-- ---------------------------------------------------------------------------------------
-- #501 (audit M1): stock_current.amount_opened_aggregated applied one conversion factor
-- (MIN(factor)) to the sum of raw amounts across every open child, instead of converting
-- each child by its own factor. Reproduced exactly as issue #487's own probe does: two
-- fully-opened children of one parent, at factor 0.25 and factor 2.
-- ---------------------------------------------------------------------------------------

INSERT INTO quantity_units (name) VALUES ('Audit501 parent qu'), ('Audit501 child qu');
INSERT INTO locations (name) VALUES ('Audit501 location');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Audit501 parent', (SELECT id FROM locations WHERE name = 'Audit501 location'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501 parent qu'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501 parent qu'));
INSERT INTO products (name, parent_product_id, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Audit501 child A', (SELECT id FROM products WHERE name = 'Audit501 parent'),
		(SELECT id FROM locations WHERE name = 'Audit501 location'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501 child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501 child qu')),
	('Audit501 child B', (SELECT id FROM products WHERE name = 'Audit501 parent'),
		(SELECT id FROM locations WHERE name = 'Audit501 location'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501 child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501 child qu'));

-- Written directly into the cache table stock_current actually joins against (the same
-- one products_INS/quantity_unit_conversions_INS keep in sync in real use), rather than
-- through quantity_unit_conversions and its recursive resolution - what is under test is
-- the view's arithmetic once a factor is known, not the cache's own population path.
INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor) VALUES
	((SELECT id FROM products WHERE name = 'Audit501 child A'), (SELECT id FROM quantity_units WHERE name = 'Audit501 child qu'), (SELECT id FROM quantity_units WHERE name = 'Audit501 parent qu'), '0.25'),
	((SELECT id FROM products WHERE name = 'Audit501 child B'), (SELECT id FROM quantity_units WHERE name = 'Audit501 child qu'), (SELECT id FROM quantity_units WHERE name = 'Audit501 parent qu'), '2');

INSERT INTO stock (product_id, amount, stock_id, open, location_id) VALUES
	((SELECT id FROM products WHERE name = 'Audit501 child A'), 1, 'audit501-a', 1, (SELECT id FROM locations WHERE name = 'Audit501 location')),
	((SELECT id FROM products WHERE name = 'Audit501 child B'), 1, 'audit501-b', 1, (SELECT id FROM locations WHERE name = 'Audit501 location'));

SELECT is(
	(SELECT amount_opened_aggregated FROM stock_current WHERE product_id = (SELECT id FROM products WHERE name = 'Audit501 parent')),
	2.25::double precision,
	'#501: mixed-factor opened aggregate sums each child converted by its own factor (0.25 + 2 = 2.25), not raw amounts times MIN(factor)'
);

-- Negative control: a single shared factor is the case the old MIN(factor) formula already
-- got right, and this fix must not change it. One child, factor 3, opened amount 2.
INSERT INTO quantity_units (name) VALUES ('Audit501b parent qu'), ('Audit501b child qu');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Audit501b parent', (SELECT id FROM locations WHERE name = 'Audit501 location'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501b parent qu'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501b parent qu'));
INSERT INTO products (name, parent_product_id, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Audit501b child', (SELECT id FROM products WHERE name = 'Audit501b parent'),
		(SELECT id FROM locations WHERE name = 'Audit501 location'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501b child qu'),
		(SELECT id FROM quantity_units WHERE name = 'Audit501b child qu'));
INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor) VALUES
	((SELECT id FROM products WHERE name = 'Audit501b child'), (SELECT id FROM quantity_units WHERE name = 'Audit501b child qu'), (SELECT id FROM quantity_units WHERE name = 'Audit501b parent qu'), '3');
INSERT INTO stock (product_id, amount, stock_id, open, location_id) VALUES
	((SELECT id FROM products WHERE name = 'Audit501b child'), 2, 'audit501b-a', 1, (SELECT id FROM locations WHERE name = 'Audit501 location'));

SELECT is(
	(SELECT amount_opened_aggregated FROM stock_current WHERE product_id = (SELECT id FROM products WHERE name = 'Audit501b parent')),
	6::double precision,
	'#501 negative control: an unmixed (single-factor) opened aggregate is unchanged - 2 * 3 = 6'
);

-- ---------------------------------------------------------------------------------------
-- #505 (audit M5): uihelper_stock_journal inner-joined locations, so deleting a location
-- hid every stock_log row that still referenced it. Reproduced as issue #487's own probe
-- does: purchase then fully consume at a location (two live stock_log rows, no stock row
-- left referencing it - ADR-0029 restricts deleting a location while a *stock* row still
-- references it, not stock_log), then delete the location.
-- ---------------------------------------------------------------------------------------

INSERT INTO quantity_units (name) VALUES ('Audit505 qu');
INSERT INTO locations (name) VALUES ('Audit505 location');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Audit505 product', (SELECT id FROM locations WHERE name = 'Audit505 location'),
		(SELECT id FROM quantity_units WHERE name = 'Audit505 qu'),
		(SELECT id FROM quantity_units WHERE name = 'Audit505 qu'));
INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, location_id, user_id) VALUES
	((SELECT id FROM products WHERE name = 'Audit505 product'), 1, 'audit505-a', 'purchase', (SELECT id FROM locations WHERE name = 'Audit505 location'), 1),
	((SELECT id FROM products WHERE name = 'Audit505 product'), -1, 'audit505-a', 'consume', (SELECT id FROM locations WHERE name = 'Audit505 location'), 1);

SELECT is(
	(SELECT count(*) FROM uihelper_stock_journal WHERE product_id = (SELECT id FROM products WHERE name = 'Audit505 product')),
	2::bigint,
	'#505: both stock_log rows are visible in the journal before the location is deleted'
);

DELETE FROM locations WHERE name = 'Audit505 location';

SELECT is(
	(SELECT count(*) FROM uihelper_stock_journal WHERE product_id = (SELECT id FROM products WHERE name = 'Audit505 product')),
	2::bigint,
	'#505: both rows stay visible after the location is deleted - history is not hidden'
);
SELECT is(
	(SELECT count(*) FROM uihelper_stock_journal WHERE product_id = (SELECT id FROM products WHERE name = 'Audit505 product') AND location_name IS NULL),
	2::bigint,
	'#505: location_name is NULL for a deleted location rather than the row disappearing'
);

-- ---------------------------------------------------------------------------------------
-- #497 (audit H8): chores_current's yearly branch built the target date by concatenating
-- text ('YYYY' from the target year, '-MM-DD' from start_date) and casting the result, so
-- a 29 February anchor reaching a non-leap target year had no valid cast. Maintainer
-- decision: due 28 February that year, clamped the way PostgreSQL's own date/interval
-- arithmetic already clamps, and 29 February again once the target year is itself a leap
-- year. tracked_time carries the exact time-of-day (12:00:00) issue #487's probe uses, to
-- confirm the branch's time-of-day handling is untouched.
-- ---------------------------------------------------------------------------------------

INSERT INTO chores (name, period_type, period_interval, period_days, start_date, active) VALUES
	('Audit497 leap', 'yearly', 1, 1, '2024-02-29 12:00:00', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES
	((SELECT id FROM chores WHERE name = 'Audit497 leap'), '2024-02-29 12:00:00', 1, 0);

SELECT is(
	(SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = (SELECT id FROM chores WHERE name = 'Audit497 leap')),
	'2025-02-28 12:00:00'::timestamp,
	'#497: a 29 February anchor is due 28 February in the following (non-leap) year, not a thrown SQLSTATE 22008'
);

-- Same anchor, a four-year interval: the target year (2028) is itself a leap year, so the
-- clamp must not have "stuck" at the 28th - re-deriving from the original start_date each
-- time is what lets 2028 see 29 February again.
INSERT INTO chores (name, period_type, period_interval, period_days, start_date, active) VALUES
	('Audit497 leap again', 'yearly', 4, 1, '2024-02-29 12:00:00', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES
	((SELECT id FROM chores WHERE name = 'Audit497 leap again'), '2024-02-29 12:00:00', 1, 0);

SELECT is(
	(SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = (SELECT id FROM chores WHERE name = 'Audit497 leap again')),
	'2028-02-29 12:00:00'::timestamp,
	'#497: the same 29 February anchor is due 29 February again once the target year is itself a leap year'
);

-- Negative control: an ordinary (non-29-February) yearly anchor must compute exactly as
-- before this migration.
INSERT INTO chores (name, period_type, period_interval, period_days, start_date, active) VALUES
	('Audit497 ordinary', 'yearly', 1, 1, '2024-03-15 08:00:00', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES
	((SELECT id FROM chores WHERE name = 'Audit497 ordinary'), '2024-03-15 08:00:00', 1, 0);

SELECT is(
	(SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = (SELECT id FROM chores WHERE name = 'Audit497 ordinary')),
	'2025-03-15 08:00:00'::timestamp,
	'#497 negative control: an ordinary (non-29-February) yearly anchor is unaffected'
);

-- ---------------------------------------------------------------------------------------
-- #506 (audit M6, weekly-schedule half only - the chore-undo/stock-composition half is a
-- separate change): the weekly branch read the chore's most recent chores_log row with no
-- undone filter, unlike every other branch. A chore last (genuinely) tracked Monday
-- 2026-01-05, with a later *undone* execution logged for 2026-02-01, must schedule its
-- next occurrence from the live 2026-01-05 row (giving Monday 2026-01-12), not from the
-- undone 2026-02-01 row (which would give Monday 2026-02-02).
-- ---------------------------------------------------------------------------------------

INSERT INTO chores (name, period_type, period_interval, period_config, active) VALUES
	('Audit506 weekly', 'weekly', 1, 'monday', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES
	((SELECT id FROM chores WHERE name = 'Audit506 weekly'), '2026-01-05 09:00:00', 1, 0),
	((SELECT id FROM chores WHERE name = 'Audit506 weekly'), '2026-02-01 09:00:00', 1, 1);

SELECT is(
	(SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = (SELECT id FROM chores WHERE name = 'Audit506 weekly')),
	'2026-01-12 09:00:00'::timestamp,
	'#506: the weekly schedule is driven by the live (undone = 0) execution, not a later undone one'
);

-- Negative control: with only a live execution on the books, the weekly schedule computes
-- the same way it always has.
INSERT INTO chores (name, period_type, period_interval, period_config, active) VALUES
	('Audit506 weekly control', 'weekly', 1, 'monday', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES
	((SELECT id FROM chores WHERE name = 'Audit506 weekly control'), '2026-01-05 09:00:00', 1, 0);

SELECT is(
	(SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = (SELECT id FROM chores WHERE name = 'Audit506 weekly control')),
	'2026-01-12 09:00:00'::timestamp,
	'#506 negative control: a live weekly execution alone still drives the schedule'
);

SELECT * FROM finish();
