-- Preview of migrations/0277.pgsql.sql (claimed, unwritten — see migrations/RESERVATIONS.md)
-- against ADR-0022 decisions 1, 2, 3, 5, 8 and 9 and plan 28's proposed schema. Scoped to
-- what acceptance prerequisites 1, 2, 3, 5, 6 and 7 ask for. Plan 28's UI, API and full
-- service-path rewrite are NOT reproduced here — they are not what those prerequisites
-- test, and this spike reproduces only the SQL a service method would issue, not the PHP
-- itself. Do not read this file as the migration; read it as the schema and view changes
-- those prerequisites are about.

-- Decision 1: the remaining contents of an opened unit are recorded on the stock entry.
-- Decision 3: the fraction is derived through quantity_unit_conversions_resolved — no
-- column here stores a fraction, only the raw measurement.
ALTER TABLE stock ADD COLUMN opened_amount DOUBLE PRECISION;
ALTER TABLE stock ADD COLUMN opened_qu_id INTEGER;
ALTER TABLE stock ADD COLUMN opened_tare DOUBLE PRECISION;
ALTER TABLE stock ADD COLUMN opened_measured_at TIMESTAMP;

-- Decision 8: a measurement describes exactly one container. It exists only where
-- open = 1 AND amount = 1, and opened_amount/opened_qu_id are present or absent together.
-- opened_tare is independently nullable per plan 28 (a net measurement is legitimate).
ALTER TABLE stock ADD CONSTRAINT stock_measurement_coherence_check CHECK (
	(opened_amount IS NULL AND opened_qu_id IS NULL)
	OR (opened_amount IS NOT NULL AND opened_qu_id IS NOT NULL AND open = 1 AND amount = 1)
);

-- Decision 9: a measurement survives undo, so it cannot live only on `stock` —
-- UndoBooking() rebuilds a fully consumed entry from stock_log
-- (services/StockService.php:2200-2213), and a measurement not mirrored there is lost
-- silently the moment that row is deleted.
ALTER TABLE stock_log ADD COLUMN opened_amount DOUBLE PRECISION;
ALTER TABLE stock_log ADD COLUMN opened_qu_id INTEGER;
ALTER TABLE stock_log ADD COLUMN opened_tare DOUBLE PRECISION;
ALTER TABLE stock_log ADD COLUMN opened_measured_at TIMESTAMP;

-- Decision 5: a measured entry is never compacted. stock_splits (source:
-- db/pgsql/baseline/03_views_group3.sql:73-99) already excludes per-unit (stock_id LIKE
-- 'x%') and userfield-bearing entries; this is the third exclusion. The userfield_values
-- join is dropped here — not what this prerequisite tests, see RESULTS.md.
CREATE VIEW stock_splits AS
SELECT
	s.product_id,
	SUM(s.amount) AS total_amount,
	MIN(s.stock_id) AS stock_id_to_keep,
	MAX(s.id) AS id_to_keep,
	string_agg(s.id::text, ',') AS id_group,
	string_agg(s.stock_id::text, ',') AS stock_id_group,
	MIN(s.id) AS id -- Dummy
FROM stock s
WHERE s.stock_id NOT LIKE 'x%'
	AND s.opened_amount IS NULL
GROUP BY s.product_id, s.best_before_date, s.purchased_date, s.price, s.open, s.opened_date, s.location_id, s.shopping_location_id, COALESCE(s.note, '')
HAVING COUNT(*) > 1;
