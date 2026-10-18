-- migrations/0314.pgsql.sql (issue #775, ADR-0046): stock_booking_idempotency_keys.
--
-- The table's own rules: one row per (user, operation, key), the five operations, the label
-- key grammar, a SHA-256 fingerprint, a nullable transaction id, and the indexes replay and
-- expiry read by. The reservation, replay, conflict and race behaviour is PHP and is covered by
-- tests/Pgsql/StockBookingIdempotencyTest.php.
--
-- The whole file runs in one transaction and rolls back.

BEGIN;

SELECT plan(16);

SELECT has_table('stock_booking_idempotency_keys', 'the key table exists');
SELECT col_is_pk('stock_booking_idempotency_keys', ARRAY['user_id', 'operation', 'idempotency_key'], 'a key is unique per user and operation');
SELECT col_is_null('stock_booking_idempotency_keys', 'transaction_id', 'the transaction id is NULL while the booking runs');
SELECT col_not_null('stock_booking_idempotency_keys', 'expires_at', 'every key expires');
SELECT has_index('stock_booking_idempotency_keys', 'stock_booking_idempotency_keys_expires_at_idx', ARRAY['expires_at'], 'expiry is indexed');
SELECT has_index('stock_log', 'stock_log_transaction_id_idx', ARRAY['transaction_id'], 'replay and undo read stock_log by an indexed transaction id');

INSERT INTO users (id, username, password) VALUES (93700, 'k37 user', 'fixture'), (93701, 'k37 other', 'fixture');

SELECT lives_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'purchase', 'k37-key-0001', repeat('a', 64), now() + interval '7 days')$$, 'a well-formed key is stored');
SELECT throws_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'purchase', 'k37-key-0001', repeat('b', 64), now() + interval '7 days')$$, '23505', NULL, 'the same user, operation and key cannot be stored twice');
SELECT lives_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'consume', 'k37-key-0001', repeat('a', 64), now() + interval '7 days')$$, 'the same key on another operation is a separate key');
SELECT lives_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93701, 'purchase', 'k37-key-0001', repeat('a', 64), now() + interval '7 days')$$, 'and so is the same key for another user');
SELECT throws_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'undo', 'k37-key-0002', repeat('a', 64), now())$$, '23514', NULL, 'only the five bookings take a key');
SELECT throws_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'purchase', 'short', repeat('a', 64), now())$$, '23514', NULL, 'a key under eight characters is refused');
SELECT throws_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'purchase', repeat('x', 129), repeat('a', 64), now())$$, '23514', NULL, 'a key over 128 characters is refused');
SELECT throws_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'purchase', 'bad key/1', repeat('a', 64), now())$$, '23514', NULL, 'a character outside [A-Za-z0-9._:-] is refused');
SELECT throws_ok($$INSERT INTO stock_booking_idempotency_keys (user_id, operation, idempotency_key, request_fingerprint, expires_at)
	VALUES (93700, 'purchase', 'k37-key-0003', 'not-a-digest', now())$$, '23514', NULL, 'a fingerprint is a SHA-256 hex digest');

DELETE FROM users WHERE id = 93700;
SELECT is((SELECT count(*) FROM stock_booking_idempotency_keys WHERE user_id = 93700), 0::BIGINT, 'a deleted user takes their keys with them');

SELECT * FROM finish();
ROLLBACK;
