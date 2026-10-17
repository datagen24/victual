-- Optional HTTP booking keys. The reservation and booking commit in one transaction.
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
