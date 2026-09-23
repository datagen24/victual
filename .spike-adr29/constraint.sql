-- Isolated proposal experiment, not an application migration.
BEGIN;
CREATE EXTENSION IF NOT EXISTS pgtap;
CREATE SCHEMA adr29;
SET LOCAL search_path = adr29, public;
CREATE TABLE locations (id integer PRIMARY KEY);
CREATE TABLE stock (id integer PRIMARY KEY, location_id integer);
CREATE TABLE stock_log (id integer PRIMARY KEY, location_id integer);
INSERT INTO locations VALUES (1), (2);
INSERT INTO stock VALUES (1, 1), (2, NULL);
ALTER TABLE stock ADD CONSTRAINT stock_location_id_fkey
  FOREIGN KEY (location_id) REFERENCES locations(id)
  ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;
SELECT plan(12);
SELECT lives_ok('INSERT INTO stock VALUES (3, 2)', 'existing location permits insert');
SELECT throws_ok('INSERT INTO stock VALUES (4, 99)', '23503', NULL, 'dangling insert refused');
SELECT throws_ok('UPDATE stock SET location_id = 99 WHERE id = 1', '23503', NULL, 'dangling update refused');
SELECT throws_ok('DELETE FROM locations WHERE id = 1', '23503', NULL, 'referenced delete refused');
SELECT throws_ok('UPDATE locations SET id = 99 WHERE id = 1', '23503', NULL, 'referenced key update refused');
SELECT lives_ok('UPDATE stock SET location_id = NULL WHERE id = 1', 'null update remains valid');
SELECT lives_ok('INSERT INTO stock VALUES (5, NULL)', 'null insert remains valid');
SELECT lives_ok('INSERT INTO stock_log VALUES (1, 99)', 'history permits missing location');
SELECT lives_ok('DELETE FROM locations WHERE id = 1', 'unused location deletion succeeds');
SELECT ok((SELECT convalidated FROM pg_constraint WHERE conrelid = 'stock'::regclass AND conname = 'stock_location_id_fkey'), 'constraint validated');
ALTER TABLE stock DISABLE TRIGGER USER;
SELECT throws_ok('INSERT INTO stock VALUES (6, 99)', '23503', NULL, 'import trigger suppression retains foreign key');
SELECT lives_ok('INSERT INTO stock VALUES (7, 2)', 'import trigger suppression permits valid reference');
SELECT * FROM finish();
ROLLBACK;
