-- Acceptance prerequisite 7: reject an unconvertible measurement at entry. Deleting a
-- conversion required by stored measurements leaves their fractions unavailable, never
-- reinterpreted through an assumed factor.

\echo '--- 7a: measuring product 1 (Baking flour) in Fluid Ounce — no conversion path exists ---'
\echo 'This is the check the write path runs before ever issuing the INSERT/UPDATE:'
SELECT * FROM quantity_unit_conversions_resolved
	WHERE product_id = 1 AND from_qu_id = 4 AND to_qu_id = 1; -- Fluid Ounce -> Bag

\echo 'Zero rows: nothing to convert with. Decision 3 refuses the write on exactly this result —'
\echo 'the coherence constraint added in 01-stock-measurement.pgsql.sql only enforces decision 8'
\echo '(one container, one unit); convertibility is the write path''s own job, not the database''s,'
\echo 'because the DB cannot express "product 1 has no path from Fluid Ounce" as a CHECK without'
\echo 're-deriving the recursive view per row. That division of labour is this spike''s own finding,'
\echo 'not stated explicitly in the ADR text — see RESULTS.md.'

\echo ''
\echo '--- 7b: the stored fraction before deleting the conversion it depends on ---'
SELECT s.stock_id, s.opened_amount, s.opened_amount * qucr.factor AS fraction_of_stock_unit
FROM stock s
JOIN quantity_unit_conversions_resolved qucr
	ON qucr.product_id = s.product_id AND qucr.from_qu_id = s.opened_qu_id AND qucr.to_qu_id = 1
WHERE s.stock_id = 'p1-open-measured';

\echo '--- delete the product-specific conversion (1 bag = 5 lb) that measurement depends on ---'
DELETE FROM quantity_unit_conversions WHERE product_id = 1 AND from_qu_id = 2 AND to_qu_id = 1;

\echo '--- the raw measurement is untouched ---'
SELECT stock_id, opened_amount, opened_qu_id FROM stock WHERE stock_id = 'p1-open-measured';

\echo '--- but the derived fraction is now UNAVAILABLE — the join finds nothing, never an assumed 1.0 factor ---'
SELECT s.stock_id, s.opened_amount, qucr.factor AS conversion_factor, s.opened_amount * qucr.factor AS fraction_of_stock_unit
FROM stock s
LEFT JOIN quantity_unit_conversions_resolved qucr
	ON qucr.product_id = s.product_id AND qucr.from_qu_id = s.opened_qu_id AND qucr.to_qu_id = 1
WHERE s.stock_id = 'p1-open-measured';
