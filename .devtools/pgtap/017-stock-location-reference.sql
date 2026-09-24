-- ADR-0029, against the fully migrated application schema.
SELECT plan(13);
INSERT INTO locations(id, name) VALUES (99001, 'FK source'), (99002, 'FK unused');
INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock)
VALUES (99001, 'FK product', 99001, 2, 2);
SELECT lives_ok($$INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(99001, 1, 'fk-valid', 99001)$$, 'valid stock insert');
SELECT throws_ok($$INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(99001, 1, 'fk-invalid', 99999)$$, '23503', NULL, 'invalid stock insert');
SELECT lives_ok($$UPDATE stock SET location_id=99002 WHERE stock_id='fk-valid'$$, 'valid stock update');
UPDATE stock SET location_id=99001 WHERE stock_id='fk-valid';
SELECT throws_ok($$UPDATE stock SET location_id=99999 WHERE stock_id='fk-valid'$$, '23503', NULL, 'invalid stock update');
SELECT throws_ok('DELETE FROM locations WHERE id=99001', '23503', NULL, 'referenced location delete');
SELECT throws_ok('UPDATE locations SET id=99999 WHERE id=99001', '23503', NULL, 'referenced location key update');
SELECT lives_ok($$UPDATE stock SET location_id=NULL WHERE stock_id='fk-valid'$$, 'nullable update');
SELECT lives_ok($$INSERT INTO stock_log(product_id, amount, stock_id, location_id, transaction_type, user_id) VALUES(99001, 1, 'fk-history', 99999, 'purchase', 1)$$, 'unconstrained history');
SELECT lives_ok('DELETE FROM locations WHERE id=99002', 'unused location deletion');
SELECT ok((SELECT convalidated AND NOT condeferrable AND confdeltype='r' AND confupdtype='r' FROM pg_constraint WHERE conrelid='stock'::regclass AND conname='stock_location_id_fkey'), 'validated immediate restrictive foreign key');
ALTER TABLE stock DISABLE TRIGGER USER;
SELECT lives_ok($$INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(99001, 1, 'fk-null', NULL)$$, 'import preserves null');
SELECT throws_ok($$INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES(99001, 1, 'fk-import-invalid', 99999)$$, '23503', NULL, 'import still enforces foreign key');
ALTER TABLE stock ENABLE TRIGGER USER;
SELECT has_index(current_schema()::name, 'stock'::name, 'stock_location_id_idx'::name, 'reference index exists');
SELECT * FROM finish();
