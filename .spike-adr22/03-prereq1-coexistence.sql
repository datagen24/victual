-- Acceptance prerequisite 1: three sealed units and one opened, measured unit of one
-- product produce the correct total. A negative control demonstrates the existing tare
-- path computing it incorrectly.

\echo '--- new mechanism: product 1 (Baking flour), all four stock rows ---'
SELECT stock_id, amount, open, opened_amount, opened_qu_id
FROM stock WHERE product_id = 1 AND (stock_id LIKE 'p1-sealed%' OR stock_id = 'p1-open-measured')
ORDER BY stock_id;

\echo '--- decision 2: the bag count is unchanged by opening one — still 4 bags ---'
SELECT SUM(amount) AS total_bags FROM stock WHERE product_id = 1
	AND stock_id IN ('p1-sealed-1', 'p1-sealed-2', 'p1-sealed-3', 'p1-open-measured');

\echo '--- decision 3: the open bag''s measured fraction, derived through the existing conversions machinery (1 bag = 5 lb, so 1.2 lb = 0.24 bag) ---'
SELECT
	s.stock_id,
	s.opened_amount,
	qucr.to_qu_id AS stock_qu_id,
	s.opened_amount * qucr.factor AS fraction_of_stock_unit
FROM stock s
JOIN quantity_unit_conversions_resolved qucr
	ON qucr.product_id = s.product_id
	AND qucr.from_qu_id = s.opened_qu_id
	AND qucr.to_qu_id = 1 -- Bag, the product's qu_id_stock
WHERE s.stock_id = 'p1-open-measured';

\echo ''
\echo '--- negative control: product 3 (Flour, weighed) — the EXISTING tare mechanism ---'
\echo 'Setup: three sealed 5 lb bags plus one canister the mechanism itself never reduces'
\echo '(it tracks the whole-product total, not this row alone), all still recorded amount = 5.'
SELECT stock_id, amount, open FROM stock WHERE product_id = 3 ORDER BY stock_id;

\echo '--- Context''s own product total (stock_amount, as GetProductDetails() would report it) ---'
SELECT SUM(amount) AS stock_amount FROM stock WHERE product_id = 3;

\echo '--- someone weighs the open canister: gross reading 1.4 lb (1.2 lb flour + 0.2 lb tare) ---'
\echo 'ConsumeProduct(), services/StockService.php:573: abs($amount - $productDetails->stock_amount - $product->tare_weight)'
SELECT
	abs(1.4 - (SELECT SUM(amount) FROM stock WHERE product_id = 3) - p.tare_weight) AS existing_mechanism_says_consumed,
	(SELECT amount FROM stock WHERE stock_id = 'flour-tare-4') - 1.2 AS actually_consumed_from_this_one_canister -- it held 5 lb (its recorded amount) before this partial use; net remaining is 1.2 lb
FROM products p WHERE p.id = 3;
