-- The price-visibility policy: the STOCK_PRICES_VIEW leaf and every permission_fields row
-- that names a field it gates. Plan 19 piece 2 (docs/plans/19-rbac.md), issue #84.
--
-- WHY THIS IS A FILE RATHER THAN LIVING ONLY IN migrations/0281.pgsql.sql, which is where
-- these rows first shipped. bin/victual-db-import truncates every table the source and the
-- target have in common with RESTART IDENTITY CASCADE. permission_hierarchy is one of them,
-- permission_fields.permission_name references it, and so the cascade empties
-- permission_fields on every import - leaving no redaction for anyone and PricesVisible()
-- false for every user including ADMIN, because STOCK_PRICES_VIEW itself is gone too (the
-- SQLite line is frozen at 0265, so no source database can carry a row 0281 added). The
-- importer re-applies db/pgsql/roles-seed.sql for exactly this reason and that file restores
-- piece 1's six read leaves only. This is the same file for piece 2, applied right after it.
-- Issue #176 item 1 is where the gap was found.
--
-- Every statement is idempotent, because it is applied three times over a database's life:
-- by migration 0282 (which is a no-op on an installation that already ran 0281), by
-- DatabaseImporter after each import, and again by the next import after that.
--
-- .devtools/pgsql/import-tests.php asserts the restoration by comparing permission_fields
-- before and after an import rather than against a row count written down here, so a row
-- added to this file in future is covered without that test being edited.

INSERT INTO permission_hierarchy (name, parent)
SELECT 'STOCK_PRICES_VIEW', id FROM permission_hierarchy WHERE name = 'STOCK_PURCHASE'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'STOCK_PRICES_VIEW');

-- The twenty-two rows migration 0281 seeded, carried over verbatim - that migration's own
-- comments are the record of where each came from and which of the plan's rows were
-- corrected or dropped - plus the two below that it missed.
--
-- product_barcodes.last_price and product_barcodes_view.last_price: a purchase price per
-- barcode, readable on STOCK_VIEW alone through /objects/product_barcodes,
-- /objects/product_barcodes/{id} and /objects/product_barcodes_view, and filterable on
-- through the same endpoints' query/order parameters. Both entity names are needed because
-- FieldPolicy is keyed by the name the response was built under, and the view is exposed
-- separately from the table it selects from (victual.openapi.json ExposedEntity carries
-- both). Issue #176 item 3.
INSERT INTO permission_fields (permission_name, entity, field) VALUES
	('STOCK_PRICES_VIEW', 'stock', 'price'),
	('STOCK_PRICES_VIEW', 'stock_log', 'price'),
	('STOCK_PRICES_VIEW', 'stock_current', 'value'),
	('STOCK_PRICES_VIEW', 'stock_next_use', 'price'),
	('STOCK_PRICES_VIEW', 'products_average_price', 'price'),
	('STOCK_PRICES_VIEW', 'products_last_purchased', 'price'),
	('STOCK_PRICES_VIEW', 'product_barcodes', 'last_price'),
	('STOCK_PRICES_VIEW', 'product_barcodes_view', 'last_price'),
	('STOCK_PRICES_VIEW', 'recipes_pos_resolved', 'costs'),
	('STOCK_PRICES_VIEW', 'recipes_resolved', 'costs'),
	('STOCK_PRICES_VIEW', 'recipes_resolved', 'costs_per_serving'),
	('STOCK_PRICES_VIEW', 'recipes_resolved', 'prices_incomplete'),
	('STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'value'),
	('STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'last_price'),
	('STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'average_price'),
	('STOCK_PRICES_VIEW', 'products_price_history', '*'),
	('STOCK_PRICES_VIEW', 'uihelper_shopping_list', 'last_price_unit'),
	('STOCK_PRICES_VIEW', 'uihelper_shopping_list', 'last_price_total'),
	('STOCK_PRICES_VIEW', 'uihelper_shopping_list', 'price'),
	('STOCK_PRICES_VIEW', 'product_details', 'last_price'),
	('STOCK_PRICES_VIEW', 'product_details', 'avg_price'),
	('STOCK_PRICES_VIEW', 'product_details', 'oldest_price'),
	('STOCK_PRICES_VIEW', 'product_details', 'current_price'),
	('STOCK_PRICES_VIEW', 'product_details', 'stock_value')
ON CONFLICT (permission_name, entity, field) DO NOTHING;
