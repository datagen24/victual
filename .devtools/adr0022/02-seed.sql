-- Seed data for ADR-0022 acceptance prerequisites 1, 2, 3, 5, 6 and 7.
-- quantity_units insertion order fixes their ids: Bag=1, Pound=2, Gallon=3, Fluid Ounce=4.

INSERT INTO quantity_units (name, name_plural) VALUES ('Bag', 'Bags');
INSERT INTO quantity_units (name, name_plural) VALUES ('Pound', 'Pounds');
INSERT INTO quantity_units (name, name_plural) VALUES ('Gallon', 'Gallons');
INSERT INTO quantity_units (name, name_plural) VALUES ('Fluid Ounce', 'Fluid Ounces');

-- product 1: "Baking flour", stock unit Bag. product-specific conversion 1 bag = 5 lb, i.e.
-- factor 0.2 lb->bag — the ADR's own worked example ("a measured remainder of 1.2 lb is
-- 0.24 bags").
INSERT INTO products (name, qu_id_stock) VALUES ('Baking flour', 1);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
	VALUES (2, 1, 0.2, 1);

-- product 2: "Milk", stock unit Gallon, measured by weight — decision 3's "1 gallon jug =
-- 8.6 lb" example. factor 1/8.6 lb->gallon.
INSERT INTO products (name, qu_id_stock) VALUES ('Milk', 3);
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id)
	VALUES (2, 3, 1.0 / 8.6, 2);

-- product 3: "Flour (weighed)", stock unit Pound — the shape the *existing* tare mechanism
-- was built for (Context's Limit 3: weight measurements require a weight stock unit).
-- enable_tare_weight_handling = 1, tare_weight = 0.2 lb (the empty bag). Used only for the
-- negative control in prerequisite 1.
INSERT INTO products (name, qu_id_stock, enable_tare_weight_handling, tare_weight)
	VALUES ('Flour (weighed)', 2, 1, 0.2);

-- Product 1: three sealed bags, distinct purchase batches (prerequisite 1's coexistence case).
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id)
	VALUES (1, 1, '2026-12-01', '2026-08-20', 'p1-sealed-1', 3.00, 0, 1);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id)
	VALUES (1, 1, '2026-12-15', '2026-08-25', 'p1-sealed-2', 3.05, 0, 1);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id)
	VALUES (1, 1, '2026-12-20', '2026-08-30', 'p1-sealed-3', 3.10, 0, 1);

-- Product 1: the opened, measured bag — 1.2 lb remaining. Prerequisite 1's fourth unit,
-- and container #1 of prerequisite 5's identity case.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id, opened_amount, opened_qu_id, opened_measured_at)
	VALUES (1, 1, '2026-12-01', '2026-09-01', 'p1-open-measured', 3.00, 1, '2026-09-10', 1, 1.2, 2, '2026-09-10 09:00:00');

-- Product 1: a second opened bag, not measured — container #2 of prerequisite 5's identity case.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id)
	VALUES (1, 1, '2026-12-05', '2026-09-02', 'p1-open-unmeasured', 3.00, 1, '2026-09-11', 1);

-- Product 1: two unmeasured entries sharing every stock_splits group-by column (prerequisite
-- 3's positive control — these two should still compact) and one measured entry sharing the
-- same columns (prerequisite 3's subject — this one must not).
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id)
	VALUES (1, 1, '2026-12-10', '2026-09-03', 'p1-split-a', 2.90, 1, '2026-09-05', 2);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id)
	VALUES (1, 1, '2026-12-10', '2026-09-03', 'p1-split-b', 2.90, 1, '2026-09-05', 2);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id, opened_amount, opened_qu_id, opened_measured_at)
	VALUES (1, 1, '2026-12-10', '2026-09-03', 'p1-split-c', 2.90, 1, '2026-09-05', 2, 0.8, 2, '2026-09-05 10:00:00');

-- Product 1: the state OpenProduct() produces today when opening covers a whole multi-unit
-- entry without a per-unit split (services/StockService.php:1609-1636) — open = 1,
-- amount = 3. Prerequisite 5's refusal/split case.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id)
	VALUES (1, 3, '2026-12-01', '2026-09-04', 'p1-multiunit', 3.00, 1, '2026-09-06', 1);

-- Product 1: a dedicated opened, measured entry for prerequisite 6's undo round trip, kept
-- out of every group above (distinct price/purchased_date/location) so compacting or
-- splitting the others never touches it.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id, opened_amount, opened_qu_id, opened_tare, opened_measured_at)
	VALUES (1, 1, '2026-11-01', '2026-09-08', 'p1-undo-1', 3.25, 1, '2026-09-10', 3, 1.2, 2, 0.05, '2026-09-10 08:00:00');

-- Product 2: a gallon jug of milk, half full, measured by weight (prerequisite 2).
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id, opened_amount, opened_qu_id, opened_measured_at)
	VALUES (2, 1, '2026-09-25', '2026-09-05', 'milk-1', 4.50, 1, '2026-09-12', 1, 4.3, 2, '2026-09-12 07:00:00');

-- Product 3: three sealed 5 lb bags plus one open canister, still recorded at its full
-- 5 lb because the existing mechanism never reduces a per-entry amount — it reads the
-- product TOTAL through $productDetails->stock_amount. Prerequisite 1's negative control.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id)
	VALUES (3, 5, '2026-12-01', '2026-08-20', 'flour-tare-1', 4.00, 0, 1);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id)
	VALUES (3, 5, '2026-12-15', '2026-08-25', 'flour-tare-2', 4.05, 0, 1);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id)
	VALUES (3, 5, '2026-12-20', '2026-08-30', 'flour-tare-3', 4.10, 0, 1);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id)
	VALUES (3, 5, '2026-12-01', '2026-09-01', 'flour-tare-4', 4.00, 1, '2026-09-10', 1);
