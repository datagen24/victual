-- Issue #775, ADR-0046 (Proposed): the five HTTP stock bookings accept an optional
-- Idempotency-Key header.
--
-- WHAT THIS CHANGES.
--   stock_booking_idempotency_keys holds one row per (user, operation, key): the request
--   fingerprint, the transaction id the booking produced, and when the key expires
--   (StockBookingIdempotencyService::RETENTION_SECONDS after the booking, published in
--   GET /api/system/info). The row is written in the booking's own transaction, so a key and
--   its booking commit together or not at all (decision 5); transaction_id is NULL only while
--   that transaction runs. The key grammar is the label grammar (decision 1), and the scope is
--   the acting user, never the API key (decision 3, after ADR-0041 rule 1).
--
--   stock_log.transaction_id gets a non-unique index: a replay, undo and
--   GET /api/stock/transactions/{transactionId} all select by it, and it had none.
--
--   The table is derived state for DatabaseImporter (an import replaces stock_log, so a
--   surviving key would name a transaction that no longer exists), and PostgreSQL-only in
--   .devtools/pgsql/migratedifftest.php. Nothing existing is rewritten.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

-- ADR-0029: bounded blocking. The index build takes a SHARE lock on stock_log, so bookings
-- wait for it; a large ledger may need the index built separately in a maintenance window.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

CREATE TABLE stock_booking_idempotency_keys (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    operation TEXT NOT NULL CHECK (operation IN ('purchase', 'consume', 'transfer', 'open', 'inventory')),
    idempotency_key TEXT NOT NULL CHECK (idempotency_key ~ '^[A-Za-z0-9._:-]{8,128}$'),
    request_fingerprint TEXT NOT NULL CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'),
    transaction_id TEXT,
    expires_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (user_id, operation, idempotency_key)
);
CREATE INDEX stock_booking_idempotency_keys_expires_at_idx ON stock_booking_idempotency_keys(expires_at);
CREATE INDEX stock_log_transaction_id_idx ON stock_log(transaction_id);
