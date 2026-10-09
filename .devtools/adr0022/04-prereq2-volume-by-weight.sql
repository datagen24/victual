-- Acceptance prerequisite 2: a per-product conversion supplies the factor for measuring a
-- volume container by weight, without a separate density model.

\echo '--- product 2 (Milk), stock unit Gallon, jug measured on a scale at 4.3 lb ---'
SELECT stock_id, amount, open, opened_amount, opened_qu_id FROM stock WHERE product_id = 2;

\echo '--- the per-product conversion this rests on: decision 3''s "1 gallon jug = 8.6 lb" example ---'
SELECT from_qu_id, to_qu_id, factor, product_id FROM quantity_unit_conversions WHERE product_id = 2;

\echo '--- derived fraction of a gallon remaining, through quantity_unit_conversions_resolved — no density column anywhere ---'
SELECT
	s.stock_id,
	s.opened_amount AS measured_lb,
	s.opened_amount * qucr.factor AS gallons_remaining
FROM stock s
JOIN quantity_unit_conversions_resolved qucr
	ON qucr.product_id = s.product_id
	AND qucr.from_qu_id = s.opened_qu_id
	AND qucr.to_qu_id = 3 -- Gallon, the product's qu_id_stock
WHERE s.stock_id = 'milk-1';
