-- Plan 05 parts A and C (issue #85): a shopping list can name its store, and a product or
-- recipe can name the list it defaults onto. Three nullable columns, no defaults, no
-- constraints -- an existing list stays general purpose and an existing product/recipe
-- keeps landing on whatever list it already did. Part B (per-store product-group ordering)
-- is its own migration, later, per the plan's own text: it waits on real use of A+C to
-- answer whether ordering is worth building at all.
--
-- No foreign keys, matching every other *_id column on these three tables
-- (products.shopping_location_id, stock.shopping_location_id, recipes.product_id, ...):
-- the fork declares none of them, so referential integrity for these stays exactly where
-- it already is for their siblings -- nowhere.
--
-- products_view and shopping_lists_view are deliberately NOT re-issued to surface these
-- columns. Both are `SELECT p.*/sl.*, <computed columns> FROM ...`, and PostgreSQL
-- flattens `*` into an explicit column list at CREATE VIEW time, not on every read -- so a
-- verbatim CREATE OR REPLACE VIEW would insert the new column where `*` sits, pushing every
-- column after it (has_sub_products/qu_factor_* on products_view; item_count on
-- shopping_lists_view) one position later, which CREATE OR REPLACE VIEW refuses ("cannot
-- change name of view column ... to ..."). Migration 0276 hit and documented this exact
-- failure for products_view when it needed quick_refill_amount visible through
-- uihelper_stock_current_overview, and its fix -- join straight to the base table instead
-- of through products_view's frozen `p.*` -- is the pattern anything reading
-- shopping_location_id or default_shopping_list_id through a view built over these tables
-- should follow, not a re-issued view.
--
-- The generic entity API is unaffected by any of this: GenericEntityApiController reads
-- `products`, `recipes` and `shopping_lists` directly (LessQL magic method named after the
-- entity), never through products_view/shopping_lists_view, so all three columns are
-- additive on GET /api/objects/{products,recipes,shopping_lists} the moment this migration
-- runs -- see plan 05's own note on why "additive" and "no client impact" are not the same
-- statement.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per
-- ADR-0008's retirement -- no SQLite counterpart, no @engine-exclusive marker (db/pgsql/README.md:
-- above the freeze this stops being about engine exclusivity at all).

ALTER TABLE shopping_lists ADD COLUMN shopping_location_id INTEGER;
ALTER TABLE products ADD COLUMN default_shopping_list_id INTEGER;
ALTER TABLE recipes ADD COLUMN default_shopping_list_id INTEGER;
