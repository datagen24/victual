-- Issue #650, ADR-0027 decision 2: migration 0301 (services/Database/TimestampMigration.php)
-- turns every legacy TIMESTAMP column into TIMESTAMPTZ, reading each stored wall clock in the
-- configured zone by ADR-0028's rule: a repeated hour is the earlier instant, a skipped hour is
-- refused. victual_local_to_instant() is that rule in SQL. These cases are the DST fixtures
-- issue #650 names, plus the property that makes the function necessary at all: PostgreSQL's
-- own `AT TIME ZONE` reads the New York repeated hour as the *later* instant.
--
-- Run with the session in UTC, the zone the suite's databases are migrated in. Each case names
-- its zone explicitly, so nothing here depends on the session's.

SELECT plan(28);

SET TIME ZONE 'UTC';

-- ---------------------------------------------------------------------------------------
-- The conversion function, strict (stored values and API writes) and lenient (times a view
-- derives).
-- ---------------------------------------------------------------------------------------

SELECT is(victual_local_to_instant('2026-10-04 14:30:00', 'America/New_York', true),
	'2026-10-04 18:30:00+00'::timestamptz, 'an ordinary wall clock in America/New_York');

SELECT is(victual_local_to_instant('2026-11-01 01:30:00', 'America/New_York', true),
	'2026-11-01 05:30:00+00'::timestamptz, 'the repeated hour names the earlier instant (01:30 EDT)');

SELECT is(('2026-11-01 01:30:00'::timestamp AT TIME ZONE 'America/New_York'),
	'2026-11-01 06:30:00+00'::timestamptz,
	'control: PostgreSQL''s own AT TIME ZONE takes the later instant, which is why the function exists');

SELECT is(victual_local_to_instant('2026-03-08 02:30:00', 'America/New_York', true),
	NULL::timestamptz, 'a skipped wall clock is refused (NULL) in strict mode');

SELECT is(victual_local_to_instant('2026-03-08 02:30:00', 'America/New_York', false),
	'2026-03-08 07:30:00+00'::timestamptz, 'a skipped wall clock moves forward by the gap in lenient mode');

SELECT is(victual_local_to_instant('2026-03-08 01:59:59', 'America/New_York', true),
	'2026-03-08 06:59:59+00'::timestamptz, 'the last second before the gap is ordinary');

SELECT is(victual_local_to_instant('2026-03-08 03:00:00', 'America/New_York', true),
	'2026-03-08 07:00:00+00'::timestamptz, 'the first second after the gap is ordinary');

-- Australia/Lord_Howe moves by thirty minutes, so "subtract one hour" would be wrong twice over.
SELECT is(victual_local_to_instant('2026-04-05 01:45:00', 'Australia/Lord_Howe', true),
	'2026-04-04 14:45:00+00'::timestamptz, 'a non-hour repeated half hour names the earlier instant');

SELECT is(('2026-04-05 01:45:00'::timestamp AT TIME ZONE 'Australia/Lord_Howe'),
	'2026-04-04 15:15:00+00'::timestamptz, 'control: PostgreSQL takes the later instant there too');

SELECT is(victual_local_to_instant('2026-10-04 02:15:00', 'Australia/Lord_Howe', true),
	NULL::timestamptz, 'a non-hour skipped half hour is refused');

SELECT is(victual_local_to_instant('2026-09-06 00:00:00', 'America/Santiago', true),
	NULL::timestamptz, 'a zone whose clock jumps at midnight skips midnight itself');

SELECT is(victual_local_to_instant('2026-10-04 14:30:00.123456', 'Asia/Kathmandu', true),
	'2026-10-04 08:45:00.123456+00'::timestamptz, 'a fractional-hour offset, microseconds kept');

SELECT is(victual_local_to_instant('2026-03-08 02:30:00', 'UTC', true),
	'2026-03-08 02:30:00+00'::timestamptz, 'UTC skips nothing: the conversion is the identity');

SELECT is(victual_local_to_instant(NULL, 'America/New_York', true), NULL::timestamptz, 'NULL stays NULL');

SELECT is(victual_local_to_instant('9999-12-31 23:59:59.999999', 'UTC', true),
	'9999-12-31 23:59:59.999999+00'::timestamptz, 'the last representable wire instant');

SELECT is(victual_local_to_instant('0001-01-01 00:00:00', 'UTC', true),
	'0001-01-01 00:00:00+00'::timestamptz, 'the first representable wire instant');

SELECT throws_ok($$SELECT victual_local_to_instant('infinity', 'UTC', true)$$, NULL,
	'an infinite wall clock is not converted');

-- ---------------------------------------------------------------------------------------
-- The schema migration 0301 leaves behind.
-- ---------------------------------------------------------------------------------------

SELECT is(
	(SELECT count(*)::integer FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
		WHERE c.relnamespace = current_schema()::regnamespace AND c.relkind IN ('r', 'p', 'v')
			AND a.attnum > 0 AND NOT a.attisdropped AND a.atttypid = 'timestamp without time zone'::regtype),
	0, 'no table or view column is still a wall clock');

SELECT col_type_is('stock_log', 'row_created_timestamp', 'timestamp with time zone', 'a legacy column is TIMESTAMPTZ');
SELECT col_type_is('stock_log', 'best_before_date', 'date', 'a calendar date is untouched');
SELECT col_default_is('stock_log', 'row_created_timestamp', $$date_trunc('second'::text, CURRENT_TIMESTAMP)$$,
	'the default is still truncated to the second, now as an instant');

SELECT is(
	(SELECT count(*)::integer FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
		WHERE c.relname = 'stock_next_use' AND NOT t.tgisinternal),
	3, 'the INSTEAD OF triggers on a recreated view were recreated with it');

SELECT col_type_is('batteries_current', 'next_estimated_charge_time', 'timestamp with time zone', 'batteries_current derives an instant');

INSERT INTO batteries (name, charge_interval_days, active) VALUES ('Tz650 never', 0, 1);
INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES
	((SELECT id FROM batteries WHERE name = 'Tz650 never'), '2026-10-01 10:00:00+00', 0);
SELECT is(
	(SELECT next_estimated_charge_time FROM batteries_current WHERE battery_id = (SELECT id FROM batteries WHERE name = 'Tz650 never')),
	'2999-12-31 23:59:59+00'::timestamptz, '"never" is one instant on every server');

INSERT INTO chores (name, period_type, period_interval, active) VALUES ('Tz650 no start', 'manually', 1, 1);
SELECT ok(
	(SELECT start_date BETWEEN date_trunc('second', CURRENT_TIMESTAMP) - interval '1 minute' AND CURRENT_TIMESTAMP
		AND start_date = date_trunc('second', start_date)
		FROM chores WHERE name = 'Tz650 no start'),
	'trg_default_start_date_when_empty_ins stamps the current instant, to the second');

UPDATE chores SET start_date = '2000-01-01 00:00:00+00' WHERE name = 'Tz650 no start';
UPDATE chores SET start_date = NULL WHERE name = 'Tz650 no start';
SELECT ok(
	(SELECT start_date BETWEEN date_trunc('second', CURRENT_TIMESTAMP) - interval '1 minute' AND CURRENT_TIMESTAMP
		FROM chores WHERE name = 'Tz650 no start'),
	'trg_default_start_date_when_empty_upd stamps the current instant when an update clears it');

-- ---------------------------------------------------------------------------------------
-- A schedule derived across a DST change, in the configured zone (the session zone).
-- ---------------------------------------------------------------------------------------

SET TIME ZONE 'America/New_York';

INSERT INTO chores (name, period_type, period_interval, start_date, active) VALUES
	('Tz650 daily 01:30', 'daily', 1, '2026-10-31 01:30:00-04', 1),
	('Tz650 daily 02:30', 'daily', 1, '2026-03-07 02:30:00-05', 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES
	((SELECT id FROM chores WHERE name = 'Tz650 daily 01:30'), '2026-10-31 01:30:00-04', 1, 0),
	((SELECT id FROM chores WHERE name = 'Tz650 daily 02:30'), '2026-03-07 02:30:00-05', 1, 0);

SELECT is(
	(SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = (SELECT id FROM chores WHERE name = 'Tz650 daily 01:30')),
	'2026-11-01 05:30:00+00'::timestamptz,
	'a daily chore at 01:30 falls on the repeated hour and takes the earlier instant');

SELECT is(
	(SELECT next_estimated_execution_time FROM chores_current WHERE chore_id = (SELECT id FROM chores WHERE name = 'Tz650 daily 02:30')),
	'2026-03-08 07:30:00+00'::timestamptz,
	'a daily chore at 02:30 falls in the skipped hour and moves forward to 03:30 EDT');

SET TIME ZONE 'UTC';

SELECT * FROM finish();
