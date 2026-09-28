-- migrations/0292.pgsql.sql (issue #588, #487 remediation): trg_stock_log_UPD and
-- trg_stock_log_DEL now share rebuild_stock_log_cache_for_product(), which recomputes
-- what products_average_price/products_last_purchased return for one product id and
-- either replaces the cache__products_average_price/cache__products_last_purchased row
-- or removes it, rather than the old shapes each trigger had: UPD only ever upserted
-- (never removing a stale row once the view stopped returning anything for a product -
-- the case StockService::UndoBooking() hits undoing a product's only purchase), and DEL
-- (round 1 of this same migration) filtered by OLD.id instead of OLD.product_id, then
-- later only ever deleted (never rebuilding from a booking that was still there).
--
-- Four scenarios, one per cache-affecting way a stock_log row changes:
-- A. UPDATE that leaves the view with nothing for a product (undo) - the cache row must
--    be removed, not left stale (trg_stock_log_UPD, stock_log_UPD).
-- B. UPDATE that moves a row to a different product_id (the shape
--    StockService::MergeProducts() uses in bulk) - both the new and the old product's
--    caches must end up correct (trg_stock_log_UPD's OLD.product_id branch).
-- C. DELETE that leaves another booking of the same product still there - the cache must
--    be rebuilt to match the survivor, not emptied (trg_stock_log_DEL, stock_log_DEL,
--    rebuild_stock_log_cache_for_product's re-INSERT branch).
-- D. DELETE with the original issue #588 id coincidence (a stock_log row's own id equal
--    to an unrelated product's id) - that other product's cache must be untouched
--    (trg_stock_log_DEL keyed on OLD.product_id, not OLD.id).

SELECT plan(8);

INSERT INTO locations (name) VALUES ('Spike19 location');
INSERT INTO quantity_units (name) VALUES ('Spike19 qu');

-- A: undo a product's only purchase - the view returns nothing afterwards, so the cache
-- must be removed entirely.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike19 productA', (SELECT id FROM locations WHERE name = 'Spike19 location'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'));
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) VALUES (
	(SELECT id FROM products WHERE name = 'Spike19 productA'), 2, '2035-01-01', '2026-01-01', 'spike19-stock-a', 'purchase', 9.0, 0, 9000
);
UPDATE stock_log SET undone = 1, undone_timestamp = LOCALTIMESTAMP
	WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productA');
SELECT ok(
	(SELECT count(*) FROM cache__products_average_price WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productA')) = 0,
	'Undoing a product''s only purchase removes its average-price cache row (trg_stock_log_UPD, rebuild_stock_log_cache_for_product)'
);
SELECT ok(
	(SELECT count(*) FROM cache__products_last_purchased WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productA')) = 0,
	'Undoing a product''s only purchase removes its last-purchased cache row (trg_stock_log_UPD, stock_log_UPD)'
);

-- B: an UPDATE that moves a booking to a different product_id, the shape
-- StockService::MergeProducts() uses. The new product's cache must reflect the moved
-- booking, and the old product's cache - now backed by nothing - must be gone.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike19 productB-old', (SELECT id FROM locations WHERE name = 'Spike19 location'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'));
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike19 productB-new', (SELECT id FROM locations WHERE name = 'Spike19 location'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'));
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) VALUES (
	(SELECT id FROM products WHERE name = 'Spike19 productB-old'), 1, '2035-01-01', '2026-01-01', 'spike19-stock-b', 'purchase', 4.25, 0, 9000
);
UPDATE stock_log SET product_id = (SELECT id FROM products WHERE name = 'Spike19 productB-new')
	WHERE stock_id = 'spike19-stock-b';
SELECT is(
	(SELECT price FROM cache__products_average_price WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productB-new')),
	4.25::double precision,
	'Moving a booking to another product_id rebuilds the NEW product''s average-price cache (trg_stock_log_UPD)'
);
SELECT ok(
	(SELECT count(*) FROM cache__products_average_price WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productB-old')) = 0,
	'...and rebuilds the OLD product''s cache too, leaving it with no row once nothing backs it any more (trg_stock_log_UPD''s OLD.product_id branch)'
);
SELECT ok(
	(SELECT count(*) FROM cache__products_last_purchased WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productB-old')) = 0,
	'...for both cache tables (stock_log_UPD)'
);

-- C: deleting one of two bookings of the same product must rebuild the cache to match
-- the surviving booking, not empty it out.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike19 productC', (SELECT id FROM locations WHERE name = 'Spike19 location'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'));
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) VALUES (
	(SELECT id FROM products WHERE name = 'Spike19 productC'), 1, '2035-01-01', '2026-01-01', 'spike19-stock-c1', 'purchase', 4.00, 0, 9000
);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) VALUES (
	(SELECT id FROM products WHERE name = 'Spike19 productC'), 1, '2035-01-01', '2026-01-02', 'spike19-stock-c2', 'purchase', 6.00, 0, 9000
);
DELETE FROM stock_log WHERE stock_id = 'spike19-stock-c1';
SELECT is(
	(SELECT price FROM cache__products_average_price WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productC')),
	6.00::double precision,
	'Deleting one of two bookings rebuilds the cache from the surviving one, not left empty and not the average of both (trg_stock_log_DEL, rebuild_stock_log_cache_for_product)'
);

-- D: issue #588's own coincidence - a stock_log row's own id equal to an UNRELATED
-- product's id. productE is the one deleted (its only booking, filed under that
-- coinciding id); productF's cache must be completely untouched by it.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike19 productE', (SELECT id FROM locations WHERE name = 'Spike19 location'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'));
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike19 productF', (SELECT id FROM locations WHERE name = 'Spike19 location'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike19 qu'));
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) VALUES (
	(SELECT id FROM products WHERE name = 'Spike19 productF'), 2, '2035-01-01', '2026-01-01', 'spike19-stock-f', 'purchase', 8.00, 0, 9000
);
INSERT INTO stock_log (id, product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, undone, user_id) VALUES (
	(SELECT id FROM products WHERE name = 'Spike19 productF'), (SELECT id FROM products WHERE name = 'Spike19 productE'), 1, '2035-01-01', '2026-01-01', 'spike19-stock-e', 'purchase', 2.00, 0, 9000
);
DELETE FROM stock_log WHERE stock_id = 'spike19-stock-e';
SELECT ok(
	(SELECT count(*) FROM cache__products_average_price WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productE')) = 0,
	'The deleted booking''s own product (productE) has no cache row left (trg_stock_log_DEL keyed on OLD.product_id)'
);
SELECT is(
	(SELECT price FROM cache__products_average_price WHERE product_id = (SELECT id FROM products WHERE name = 'Spike19 productF')),
	8.00::double precision,
	'productF''s cache is untouched by deleting an unrelated booking whose own id happens to equal productF''s id (stock_log_DEL)'
);

SELECT * FROM finish();
