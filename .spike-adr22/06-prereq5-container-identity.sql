-- Acceptance prerequisite 5: two opened containers, one measured, retain separate state
-- and correct totals. A multi-unit entry is refused or split before attaching a remainder.

\echo '--- two opened containers of product 1, separate state: one measured, one not ---'
SELECT stock_id, amount, open, opened_amount, opened_qu_id
	FROM stock WHERE stock_id IN ('p1-open-measured', 'p1-open-unmeasured') ORDER BY stock_id;

\echo '--- both remain their own row after the measurement — no shared state, no merge ---'
SELECT COUNT(*) AS still_two_rows FROM stock WHERE stock_id IN ('p1-open-measured', 'p1-open-unmeasured');

\echo ''
\echo '--- the OpenProduct() in-place case: open = 1, amount = 3 (three unlabelled jugs) ---'
SELECT stock_id, amount, open FROM stock WHERE stock_id = 'p1-multiunit';

\echo '--- attaching a measurement directly is REFUSED — amount is 3, not 1 (decision 8) ---'
UPDATE stock SET opened_amount = 0.5, opened_qu_id = 2, opened_measured_at = now()
	WHERE stock_id = 'p1-multiunit';

\echo ''
\echo '--- split first, mirroring OpenProduct()''s else-branch (services/StockService.php:1637-1654): a 2-unit rest entry, this row drops to amount = 1 ---'
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, open, opened_date, location_id)
	SELECT product_id, 2, best_before_date, purchased_date, 'p1-multiunit-rest', price, 0, NULL, location_id
	FROM stock WHERE stock_id = 'p1-multiunit';
UPDATE stock SET amount = 1 WHERE stock_id = 'p1-multiunit';

\echo '--- now the remainder attaches, because the entry holds exactly one unit ---'
UPDATE stock SET opened_amount = 0.5, opened_qu_id = 2, opened_measured_at = now()
	WHERE stock_id = 'p1-multiunit';

\echo '--- final state: one measured container (amount = 1) and its unopened, unmeasured rest (amount = 2) ---'
SELECT stock_id, amount, open, opened_amount, opened_qu_id
	FROM stock WHERE stock_id IN ('p1-multiunit', 'p1-multiunit-rest') ORDER BY stock_id;
