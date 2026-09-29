-- migrations/0296.pgsql.sql (issue #516, M16, #487 remediation, maintainer decision D2):
-- retiring a label now cancels its queued, unclaimed print jobs and leaves a claimed job
-- (current_attempt_id set), an already-cancelled job or one already in a terminal outcome
-- untouched. Every retirement trigger calls the new cancel_queued_label_jobs() after it
-- retires a label. This file exercises it against all six single-row retirement triggers
-- (retire_location_labels, retire_product_labels, retire_stock_entry_labels,
-- retire_recipe_labels, retire_chore_labels, retire_battery_labels - each the same
-- UPDATE ... RETURNING uid INTO v_uid; IF v_uid IS NOT NULL THEN PERFORM
-- cancel_queued_label_jobs(v_uid); END IF shape) and trg_cascade_product_removal (a product
-- delete cascading to more than one labelled stock entry), plus the terminal-state cases
-- cancellation must leave alone: a job already claimed, a job already cancelled (idempotent -
-- its original reason survives), and a job already dead-lettered.
--
-- The concurrent case D2 also names - a job claimed by one connection while a second
-- concurrently retires its label must not be cancelled by that retirement - needs two real
-- connections contending for the same row lock, which a single-connection pgTAP script
-- cannot drive; that is tests/Pgsql/LabelRetirementCancelsClaimedJobRaceTest.php instead,
-- along with the analogous race for a reprint or revised print racing a retirement
-- (LabelOperationsService::AssertLabelLive()'s and RevisedPrint()'s own `FOR SHARE` lock).

SELECT plan(14);

INSERT INTO locations (name) VALUES ('Spike23 location');
INSERT INTO quantity_units (name) VALUES ('Spike23 qu');
INSERT INTO label_workers (name, configuration_mode) VALUES ('Spike23 worker', 'declared');

-- Case 1: a product's own label, with one queued job and one already-claimed job.
-- Retiring the label cancels the queued job and dead-letters its outbox row, leaves the
-- claimed job (and its outbox row) exactly as it was, and still retires the label itself.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike23 product1', (SELECT id FROM locations WHERE name = 'Spike23 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike23 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike23 qu')
);
INSERT INTO labels (uid, kind, target_id) VALUES (
	'A' || upper(substr(md5(random()::text), 1, 12)), 'product',
	(SELECT id FROM products WHERE name = 'Spike23 product1')
);

WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9001, (SELECT uid FROM labels WHERE kind = 'product' AND target_id = (SELECT id FROM products WHERE name = 'Spike23 product1'))
FROM o;

WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9002, (SELECT uid FROM labels WHERE kind = 'product' AND target_id = (SELECT id FROM products WHERE name = 'Spike23 product1'))
FROM o;

INSERT INTO print_attempts (outbox_id, job_id, attempt_number, worker_id, lease_expires_at, lease_hard_deadline, acknowledged_on)
SELECT j.outbox_id, j.id, 1, (SELECT id FROM label_workers WHERE name = 'Spike23 worker'),
	CURRENT_TIMESTAMP + interval '1 minute', CURRENT_TIMESTAMP + interval '5 minutes', 'report'
FROM print_jobs j WHERE j.printer_id = 9002;

-- The row lock discipline cancel_queued_label_jobs() relies on: this UPDATE is what a real
-- Claim() call does last (PrintAttemptService.php Claim(), line ~99), and is standing in for
-- it here without the rest of Claim()'s dispatch fixture - see this file's own header comment.
UPDATE print_jobs SET current_attempt_id = (SELECT id FROM print_attempts WHERE job_id = print_jobs.id), attempts_made = 1
WHERE printer_id = 9002;

DELETE FROM products WHERE name = 'Spike23 product1';

SELECT ok(
	(SELECT cancelled_at IS NOT NULL AND cancelled_reason = 'label retired' FROM print_jobs WHERE printer_id = 9001),
	'A queued job for a retired product label is cancelled (retire_product_labels)'
);
SELECT ok(
	(SELECT dead_lettered_at IS NOT NULL AND delivered_at IS NULL
		FROM outbox WHERE id = (SELECT outbox_id FROM print_jobs WHERE printer_id = 9001)),
	'The cancelled job''s outbox row is dead-lettered'
);
SELECT ok(
	(SELECT cancelled_at IS NULL AND current_attempt_id IS NOT NULL FROM print_jobs WHERE printer_id = 9002),
	'A job already claimed when its label retires is left untouched (D2: leave running jobs untouched)'
);
SELECT ok(
	(SELECT dead_lettered_at IS NULL AND delivered_at IS NULL
		FROM outbox WHERE id = (SELECT outbox_id FROM print_jobs WHERE printer_id = 9002)),
	'The claimed job''s outbox row is untouched too'
);
SELECT ok(
	(SELECT retired_at IS NOT NULL FROM labels WHERE kind = 'product' AND retirement_snapshot ->> 'name' = 'Spike23 product1'),
	'The label itself is still retired even though one of its jobs could not be cancelled'
);

-- Case 2: a job that already has an outcome before its label retires keeps that outcome -
-- it never becomes "cancelled", and cancel_queued_label_jobs() never touches it.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike23 product2', (SELECT id FROM locations WHERE name = 'Spike23 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike23 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike23 qu')
);
INSERT INTO labels (uid, kind, target_id) VALUES (
	'B' || upper(substr(md5(random()::text), 1, 12)), 'product',
	(SELECT id FROM products WHERE name = 'Spike23 product2')
);
WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid, outcome, outcome_at)
SELECT o.id, 9003, (SELECT uid FROM labels WHERE kind = 'product' AND target_id = (SELECT id FROM products WHERE name = 'Spike23 product2')),
	'printed', CURRENT_TIMESTAMP
FROM o;

DELETE FROM products WHERE name = 'Spike23 product2';

SELECT ok(
	(SELECT cancelled_at IS NULL AND outcome = 'printed' FROM print_jobs WHERE printer_id = 9003),
	'A job that already has an outcome before its label retires keeps that outcome, not cancelled'
);

-- Case 3: a direct DELETE FROM stock (StockService.php's own full-consumption path, not a
-- product cascade) cancels its stock entry's queued job via retire_stock_entry_labels.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike23 product3', (SELECT id FROM locations WHERE name = 'Spike23 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike23 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike23 qu')
);
INSERT INTO stock (product_id, amount, stock_id, best_before_date) VALUES (
	(SELECT id FROM products WHERE name = 'Spike23 product3'), 4, 'spike23-stock-a', '2027-05-01'
);
INSERT INTO labels (uid, kind, target_id) VALUES (
	'C' || upper(substr(md5(random()::text), 1, 12)), 'stock_entry',
	(SELECT id FROM stock WHERE stock_id = 'spike23-stock-a')
);
WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9004, (SELECT uid FROM labels WHERE kind = 'stock_entry' AND target_id = (SELECT id FROM stock WHERE stock_id = 'spike23-stock-a'))
FROM o;

DELETE FROM stock WHERE stock_id = 'spike23-stock-a';

SELECT ok(
	(SELECT cancelled_at IS NOT NULL FROM print_jobs WHERE printer_id = 9004),
	'A queued job for a stock entry label retired by a direct DELETE FROM stock is cancelled (retire_stock_entry_labels)'
);

-- Case 4: deleting a product with two labelled stock entries cascades through
-- trg_cascade_product_removal (migrations/0279.pgsql.sql, redefined 0295 by PR #624,
-- redefined again here) - both labels are retired and both queued jobs are cancelled, not
-- only the first the join touches.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike23 product4', (SELECT id FROM locations WHERE name = 'Spike23 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike23 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike23 qu')
);
INSERT INTO stock (product_id, amount, stock_id, best_before_date) VALUES
	((SELECT id FROM products WHERE name = 'Spike23 product4'), 1, 'spike23-stock-b', '2027-06-01'),
	((SELECT id FROM products WHERE name = 'Spike23 product4'), 2, 'spike23-stock-c', '2027-07-01');
INSERT INTO labels (uid, kind, target_id) VALUES
	('D' || upper(substr(md5(random()::text), 1, 12)), 'stock_entry', (SELECT id FROM stock WHERE stock_id = 'spike23-stock-b')),
	('E' || upper(substr(md5(random()::text), 1, 12)), 'stock_entry', (SELECT id FROM stock WHERE stock_id = 'spike23-stock-c'));

WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9005, (SELECT uid FROM labels WHERE kind = 'stock_entry' AND target_id = (SELECT id FROM stock WHERE stock_id = 'spike23-stock-b'))
FROM o;
WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9006, (SELECT uid FROM labels WHERE kind = 'stock_entry' AND target_id = (SELECT id FROM stock WHERE stock_id = 'spike23-stock-c'))
FROM o;

DELETE FROM products WHERE name = 'Spike23 product4';

SELECT ok(
	(SELECT cancelled_at IS NOT NULL FROM print_jobs WHERE printer_id = 9005),
	'Deleting a product cancels the queued job of the first of two labelled stock entries it cascades to (trg_cascade_product_removal)'
);
SELECT ok(
	(SELECT cancelled_at IS NOT NULL FROM print_jobs WHERE printer_id = 9006),
	'...and the second, not only the first the join touches'
);

-- Case 5: a job already cancelled (by an operator, through LabelOperationsService::Cancel())
-- before its label retires keeps its own original reason - cancel_queued_label_jobs()'s own
-- `cancelled_at IS NULL` guard excludes it, so retirement never overwrites it.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike23 product5', (SELECT id FROM locations WHERE name = 'Spike23 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike23 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike23 qu')
);
INSERT INTO labels (uid, kind, target_id) VALUES (
	'F' || upper(substr(md5(random()::text), 1, 12)), 'product',
	(SELECT id FROM products WHERE name = 'Spike23 product5')
);
WITH o AS (INSERT INTO outbox (event_type, payload, dead_lettered_at, last_error) VALUES ('label.print_requested', '{}', CURRENT_TIMESTAMP, 'Cancelled: Cancelled by an operator') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid, cancelled_at, cancelled_reason)
SELECT o.id, 9007, (SELECT uid FROM labels WHERE kind = 'product' AND target_id = (SELECT id FROM products WHERE name = 'Spike23 product5')),
	CURRENT_TIMESTAMP, 'Cancelled by an operator'
FROM o;

DELETE FROM products WHERE name = 'Spike23 product5';

SELECT is(
	(SELECT cancelled_reason FROM print_jobs WHERE printer_id = 9007),
	'Cancelled by an operator',
	'A job already cancelled before its label retires keeps its own original cancel reason, not overwritten with "label retired"'
);

-- Case 6: a job already dead-lettered (outcome set, not cancellation) before its label
-- retires keeps that outcome untouched - cancel_queued_label_jobs()'s own `outcome IS NULL`
-- guard excludes it, the same as the already-'printed' job in case 2 above.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (
	'Spike23 product6', (SELECT id FROM locations WHERE name = 'Spike23 location'),
	(SELECT id FROM quantity_units WHERE name = 'Spike23 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike23 qu')
);
INSERT INTO labels (uid, kind, target_id) VALUES (
	'0' || upper(substr(md5(random()::text), 1, 12)), 'product',
	(SELECT id FROM products WHERE name = 'Spike23 product6')
);
WITH o AS (INSERT INTO outbox (event_type, payload, dead_lettered_at, last_error) VALUES ('label.print_requested', '{}', CURRENT_TIMESTAMP, 'Printer deleted') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid, outcome, outcome_at)
SELECT o.id, 9008, (SELECT uid FROM labels WHERE kind = 'product' AND target_id = (SELECT id FROM products WHERE name = 'Spike23 product6')),
	'dead_lettered', CURRENT_TIMESTAMP
FROM o;

DELETE FROM products WHERE name = 'Spike23 product6';

SELECT ok(
	(SELECT cancelled_at IS NULL AND outcome = 'dead_lettered' FROM print_jobs WHERE printer_id = 9008),
	'A job already dead-lettered before its label retires keeps that outcome, not cancelled'
);

-- Cases 7-9: the three single-row retirement triggers case 1 does not already cover
-- (retire_recipe_labels, retire_chore_labels, retire_battery_labels) - each the identical
-- shape, one queued job per kind.
INSERT INTO recipes (name) VALUES ('Spike23 recipe');
INSERT INTO labels (uid, kind, target_id) VALUES (
	'1' || upper(substr(md5(random()::text), 1, 12)), 'recipe', (SELECT id FROM recipes WHERE name = 'Spike23 recipe')
);
WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9009, (SELECT uid FROM labels WHERE kind = 'recipe' AND target_id = (SELECT id FROM recipes WHERE name = 'Spike23 recipe'))
FROM o;

DELETE FROM recipes WHERE name = 'Spike23 recipe';

SELECT ok(
	(SELECT cancelled_at IS NOT NULL FROM print_jobs WHERE printer_id = 9009),
	'A queued job for a retired recipe label is cancelled (retire_recipe_labels)'
);

INSERT INTO chores (name, period_type) VALUES ('Spike23 chore', 'manually');
INSERT INTO labels (uid, kind, target_id) VALUES (
	'2' || upper(substr(md5(random()::text), 1, 12)), 'chore', (SELECT id FROM chores WHERE name = 'Spike23 chore')
);
WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9010, (SELECT uid FROM labels WHERE kind = 'chore' AND target_id = (SELECT id FROM chores WHERE name = 'Spike23 chore'))
FROM o;

DELETE FROM chores WHERE name = 'Spike23 chore';

SELECT ok(
	(SELECT cancelled_at IS NOT NULL FROM print_jobs WHERE printer_id = 9010),
	'A queued job for a retired chore label is cancelled (retire_chore_labels)'
);

INSERT INTO batteries (name) VALUES ('Spike23 battery');
INSERT INTO labels (uid, kind, target_id) VALUES (
	'3' || upper(substr(md5(random()::text), 1, 12)), 'battery', (SELECT id FROM batteries WHERE name = 'Spike23 battery')
);
WITH o AS (INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id)
INSERT INTO print_jobs (outbox_id, printer_id, label_uid)
SELECT o.id, 9011, (SELECT uid FROM labels WHERE kind = 'battery' AND target_id = (SELECT id FROM batteries WHERE name = 'Spike23 battery'))
FROM o;

DELETE FROM batteries WHERE name = 'Spike23 battery';

SELECT ok(
	(SELECT cancelled_at IS NOT NULL FROM print_jobs WHERE printer_id = 9011),
	'A queued job for a retired battery label is cancelled (retire_battery_labels)'
);
