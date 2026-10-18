-- migrations/0318.pgsql.sql (issue #952, maintainer decision 2026-10-18 option A): a quantity unit
-- conversion write is refused, with SQLSTATE VQ952, when its own factor or a factor the resolver
-- evaluates through it falls outside 2^-1022..2^1022, so that the factor and its inverse are both
-- normal doubles. The API's message for the refusal is PHP, in tests/Pgsql/ConversionChainRangeTest.php.
--
-- The whole file runs in one transaction and rolls back. Each refusal runs in its own savepoint
-- (throws_ok does that), so the cases do not abort one another.

BEGIN;

SELECT plan(12);

INSERT INTO quantity_units (id, name) SELECT g, 'P40 unit ' || g FROM generate_series(94001, 94012) g;

-- The rule itself, at and just past both ends
SELECT ok(quantity_unit_conversion_factor_in_range(power(2::double precision, -1022)), '2^-1022 is in range');
SELECT ok(quantity_unit_conversion_factor_in_range(power(2::double precision, 1022)), '2^1022 is in range');
SELECT ok(NOT quantity_unit_conversion_factor_in_range(power(2::double precision, -1023)), '2^-1023 (subnormal) is not');
SELECT ok(NOT quantity_unit_conversion_factor_in_range(power(2::double precision, 1023)), '2^1023 (subnormal inverse) is not');

-- A written factor outside the range is refused before its inverse is computed
SELECT throws_ok($$INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94001, 94002, '5e-324')$$,
	'VQ952', NULL, 'insert: a subnormal factor, whose inverse overflows, is refused with VQ952 rather than a raw 22003');
SELECT throws_ok($$INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94001, 94002, power(2::double precision, 1023))$$,
	'VQ952', NULL, 'insert: a factor whose inverse is subnormal is refused');

-- Issue #952's example: B→C at 1e10, then A→B at 1e300 overflows the resolver
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94004, 94005, 1e10);
SELECT throws_ok($$INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94003, 94004, 1e300)$$,
	'VQ952', NULL, 'insert: a chain that overflows is refused');
SELECT lives_ok($$INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94003, 94004, 1e290)$$,
	'insert: the same chain at 1e300 is accepted');

-- An edit is checked the same way
SELECT throws_ok($$UPDATE quantity_unit_conversions SET factor = 1e300 WHERE from_qu_id = 94003 AND to_qu_id = 94004$$,
	'VQ952', NULL, 'update: an edit that makes the chain overflow is refused');

-- The reciprocal: a chain that underflows to a subnormal, which PostgreSQL itself would store
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94007, 94008, 1e-10);
SELECT throws_ok($$INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94006, 94007, 1e-300)$$,
	'VQ952', NULL, 'insert: a chain that underflows to a subnormal is refused');

-- A delete can let a longer path win: with A→C direct, the resolver prunes the A→B→C path, so a
-- subnormal A→B→C is never resolved; deleting A→C makes it the one resolved, which is refused
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94009, 94011, 1);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94010, 94011, power(2::double precision, -600));
SELECT lives_ok($$INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (94009, 94010, power(2::double precision, -500))$$,
	'insert: A→B is accepted while the direct A→C conversion prunes the A→B→C path');
SELECT throws_ok($$DELETE FROM quantity_unit_conversions WHERE from_qu_id = 94009 AND to_qu_id = 94011$$,
	'VQ952', NULL, 'delete: removing A→C, which would make the subnormal A→B→C path the resolved one, is refused');

SELECT * FROM finish();
ROLLBACK;
