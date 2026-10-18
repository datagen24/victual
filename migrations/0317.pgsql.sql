-- Issue #990, ADR-0045 (Proposed) option B: the stock entries page reads a marked 0 as unknown.
--
-- uihelper_stock_entries is what views/stockentries.blade.php lists (StockController::Stockentries()).
-- It is not on the API, so FieldPolicy's mapping of a marked price to null (migration 0316's
-- part 2) never reached it, and the page showed a 0 that migration 0315 or an import marked as
-- never entered (legacy_zero_price = 1) as a known price of 0. The view now answers NULL for
-- that price, the same reading the API gives. Only the price expression changes; the column
-- keeps its name, position and type, so CREATE OR REPLACE is enough.
--
-- Nothing is written to stock or stock_log.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

-- Migration 0275's definition with only the price expression changed.
CREATE OR REPLACE VIEW uihelper_stock_entries AS
SELECT
	s.id,
	s.product_id,
	s.amount,
	s.best_before_date,
	s.purchased_date,
	s.stock_id,
	CASE WHEN s.legacy_zero_price = 1 THEN NULL ELSE s.price END AS price,
	s.open,
	s.opened_date,
	s.row_created_timestamp,
	s.location_id,
	s.shopping_location_id,
	s.note,
	p.id AS "id:1",
	p.name,
	p.description,
	p.product_group_id,
	p.active,
	p.location_id AS "location_id:1",
	p.shopping_location_id AS "shopping_location_id:1",
	p.qu_id_purchase,
	p.qu_id_stock,
	p.min_stock_amount,
	p.default_best_before_days,
	p.default_best_before_days_after_open,
	p.default_best_before_days_after_freezing,
	p.default_best_before_days_after_thawing,
	p.picture_file_name,
	p.enable_tare_weight_handling,
	p.tare_weight,
	p.not_check_stock_fulfillment_for_recipes,
	p.parent_product_id,
	p.calories,
	p.cumulate_min_stock_amount_of_sub_products,
	p.due_type,
	p.quick_consume_amount,
	p.hide_on_stock_overview,
	p.default_stock_label_type,
	p.should_not_be_frozen,
	p.treat_opened_as_out_of_stock,
	p.no_own_stock,
	p.default_consume_location_id,
	p.move_on_open,
	p.row_created_timestamp AS "row_created_timestamp:1",
	p.qu_id_consume,
	p.auto_reprint_stock_label,
	p.quick_open_amount,
	p.qu_id_price,
	p.disable_open,
	p.default_purchase_price_type,
	p.has_sub_products,
	p.qu_factor_purchase_to_stock,
	p.qu_factor_consume_to_stock,
	p.qu_factor_price_to_stock,
	s.opened_amount,
	s.opened_qu_id,
	s.opened_tare,
	s.opened_measured_at
FROM stock s
JOIN products_view p
	ON s.product_id = p.id;
