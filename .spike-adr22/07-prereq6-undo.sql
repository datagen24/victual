-- Acceptance prerequisite 6: measure an entry, consume it fully, then undo: remainder,
-- unit, tare and timestamp are restored. Undoing an opening leaves a legal state.

\echo '--- the measured entry before consumption ---'
SELECT stock_id, amount, open, opened_amount, opened_qu_id, opened_tare, opened_measured_at
	FROM stock WHERE stock_id = 'p1-undo-1';

\echo ''
\echo '--- ConsumeProduct() fully consumes it: a stock_log row carrying the measurement (decision 9), then the stock row is deleted ---'
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, opened_date, location_id, opened_amount, opened_qu_id, opened_tare, opened_measured_at)
	SELECT product_id, amount * -1, best_before_date, purchased_date, stock_id, 'consume', price, opened_date, location_id, opened_amount, opened_qu_id, opened_tare, opened_measured_at
	FROM stock WHERE stock_id = 'p1-undo-1'
	RETURNING id AS consume_log_id \gset
DELETE FROM stock WHERE stock_id = 'p1-undo-1';

\echo '--- the entry is gone ---'
SELECT COUNT(*) AS rows_remaining FROM stock WHERE stock_id = 'p1-undo-1';

\echo ''
\echo '--- UndoBooking() consume branch (services/StockService.php:2200-2213), extended with the four measurement columns ---'
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, opened_date, open, location_id, opened_amount, opened_qu_id, opened_tare, opened_measured_at)
	SELECT product_id, amount * -1, best_before_date, purchased_date, stock_id, price, opened_date, (opened_date IS NOT NULL)::int, location_id, opened_amount, opened_qu_id, opened_tare, opened_measured_at
	FROM stock_log WHERE id = :consume_log_id;
UPDATE stock_log SET undone = 1, undone_timestamp = now() WHERE id = :consume_log_id;

\echo '--- restored: remainder, unit, tare and timestamp all back ---'
SELECT stock_id, amount, open, opened_amount, opened_qu_id, opened_tare, opened_measured_at
	FROM stock WHERE stock_id = 'p1-undo-1';

\echo ''
\echo '--- second case: undoing the OPENING on a measured entry. The booking that opened it: ---'
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, opened_date, location_id)
	SELECT product_id, amount, best_before_date, purchased_date, stock_id, 'product-opened', price, opened_date, location_id
	FROM stock WHERE stock_id = 'p1-undo-1'
	RETURNING id AS open_log_id \gset

\echo '--- UndoBooking() PRODUCT_OPENED branch exactly as written today (services/StockService.php:2280-2290) — clears open/opened_date only ---'
\echo 'expect: REFUSED. Clearing open/opened_date alone would strand a measurement on a closed entry (decision 9), and the coherence constraint catches it outright.'
UPDATE stock SET open = 0, opened_date = NULL,
		best_before_date = (SELECT best_before_date FROM stock_log WHERE id = :open_log_id)
	WHERE stock_id = 'p1-undo-1' AND amount = (SELECT amount FROM stock_log WHERE id = :open_log_id)
		AND purchased_date = (SELECT purchased_date FROM stock_log WHERE id = :open_log_id);

\echo ''
\echo '--- the corrected undo decision 9 requires: clear the measurement columns in the same statement ---'
UPDATE stock SET open = 0, opened_date = NULL,
		opened_amount = NULL, opened_qu_id = NULL, opened_tare = NULL, opened_measured_at = NULL,
		best_before_date = (SELECT best_before_date FROM stock_log WHERE id = :open_log_id)
	WHERE stock_id = 'p1-undo-1' AND amount = (SELECT amount FROM stock_log WHERE id = :open_log_id)
		AND purchased_date = (SELECT purchased_date FROM stock_log WHERE id = :open_log_id);
UPDATE stock_log SET undone = 1, undone_timestamp = now() WHERE id = :open_log_id;

\echo '--- legal state: unopened, unmeasured entry, its own amount intact ---'
SELECT stock_id, amount, open, opened_date, opened_amount, opened_qu_id, opened_tare, opened_measured_at
	FROM stock WHERE stock_id = 'p1-undo-1';
