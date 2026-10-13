-- migrations/0312.pgsql.sql (issue #923): chores.consume_product_on_execution is 0 or 1.
--
-- The constraint is a CHECK on a NOT NULL column. The constraint alone would let a NULL
-- through, so the NOT NULL is asserted too: the maintainer's decision was to leave nullability
-- as it was. The refusing preflight is a DO block in the migration file, which a pgTAP file
-- cannot run; tests/Pgsql/ChoreConsumeFlagCheckTest.php runs it over seeded offenders. What
-- this file adds is the constraint itself, and that it cannot be added over an offender.
--
-- The whole file runs in one transaction and rolls back: it drops the constraint once.

BEGIN;

SELECT plan(13);

SELECT has_check('chores', 'chores has a check constraint');
SELECT is((SELECT convalidated FROM pg_constraint WHERE conrelid = 'chores'::regclass AND conname = 'chores_consume_product_on_execution_check'),
	TRUE, 'chores_consume_product_on_execution_check exists and is validated');
SELECT col_not_null('chores', 'consume_product_on_execution', 'the column stays NOT NULL');
SELECT col_default_is('chores', 'consume_product_on_execution', 0, 'and keeps its default of 0');

SELECT lives_ok($$INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('C36 zero', 'manually', 0)$$, '0 is accepted');
SELECT lives_ok($$INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('C36 one', 'manually', 1)$$, '1 is accepted');
SELECT throws_ok($$INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('C36 two', 'manually', 2)$$, '23514', NULL, '2 is refused on insert');
SELECT throws_ok($$INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('C36 minus', 'manually', -1)$$, '23514', NULL, '-1 is refused on insert');
SELECT throws_ok($$UPDATE chores SET consume_product_on_execution = 2 WHERE name = 'C36 one'$$, '23514', NULL, '2 is refused on update');
SELECT is((SELECT consume_product_on_execution FROM chores WHERE name = 'C36 one'), 1::SMALLINT, 'and the refused update left the row as it was');
SELECT throws_ok($$INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('C36 null', 'manually', NULL)$$, '23502', NULL, 'NULL is refused by NOT NULL, not by the check');

-- The constraint cannot be added over an existing offender, and refusing leaves the row alone.
ALTER TABLE chores DROP CONSTRAINT chores_consume_product_on_execution_check;
INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('C36 offender', 'manually', 7);
SELECT throws_ok($$ALTER TABLE chores ADD CONSTRAINT chores_consume_product_on_execution_check CHECK (consume_product_on_execution IN (0, 1))$$,
	'23514', NULL, 'the constraint cannot be added while a chore holds another value');
SELECT is((SELECT consume_product_on_execution FROM chores WHERE name = 'C36 offender'), 7::SMALLINT, 'and the offender keeps its value');

SELECT * FROM finish();
ROLLBACK;
