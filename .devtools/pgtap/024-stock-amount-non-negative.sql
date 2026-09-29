-- Issue #492 (H3, #487 remediation), ADR-0032 acceptance gate 6: EditStockEntry() and the
-- other StockService entry points now refuse a negative amount in PHP (commit 2039d5947),
-- but nothing in the schema itself stopped a negative amount reaching `stock.amount` through
-- any other writer (a future application bug, a direct import path, manual SQL). Migration
-- 0297 adds a database-level backstop: `amount >= 0`. Zero remains writable - WeighLocation()
-- legitimately zeroes a vessel's stock row when its gross reading equals its tare weight
-- (issue #487 correction 3), and stock_measurement_coherence_check (migration 0275) already
-- requires exactly amount = 1 for a measured, opened row, a stricter constraint than
-- amount >= 0 the two do not conflict.
SELECT plan(6);
INSERT INTO locations(id, name) VALUES (99101, 'amount-check source');
INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock)
VALUES (99101, 'amount-check product', 99101, 2, 2);
SELECT lives_ok($$INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(99101, 1, 'amount-check-positive', 99101)$$, 'positive amount insert lives');
SELECT lives_ok($$INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(99101, 0, 'amount-check-zero', 99101)$$, 'zero amount insert lives (empty-vessel case)');
SELECT throws_ok($$INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(99101, -1, 'amount-check-negative', 99101)$$, '23514', NULL, 'negative amount insert refused');
SELECT throws_ok($$UPDATE stock SET amount = -0.0001 WHERE stock_id = 'amount-check-positive'$$, '23514', NULL, 'negative amount update refused');
SELECT ok((SELECT amount FROM stock WHERE stock_id = 'amount-check-positive') = 1, 'refused update left the row unchanged');
SELECT ok((SELECT convalidated AND NOT condeferrable FROM pg_constraint WHERE conrelid='stock'::regclass AND conname='stock_amount_non_negative_check'), 'validated immediate non-negative check');
SELECT * FROM finish();
