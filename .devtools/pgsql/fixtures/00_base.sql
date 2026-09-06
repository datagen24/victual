-- The shared starting state for every test in the suite.
--
-- Applied to a freshly migrated SQLite database (bin/victual-migrate) to produce the
-- pristine database that trigdifftest.php copies before each script and that the view
-- seeds build on. Kept deliberately small and readable: every row here exists because
-- something in trigger-tests/ or view-tests/ refers to it, and a fixture you cannot
-- read is a fixture you cannot trust when a test fails.
--
-- This is NOT the demo data generator. DemoDataGeneratorService only runs on SQLite,
-- which would make the PostgreSQL side of a differential test unreachable, and its
-- output is randomised, which is the opposite of what a regression fixture needs.
--
-- One trap when writing a seed: every tool here splits statements on a semicolon at end
-- of line, so a comment whose last character is ";" ends the statement above it. Keep
-- semicolons out of the ends of comment lines.
--
-- What a migrated database already provides, and this file therefore does not repeat:
--   locations            id 2 "Fridge"           (migrations/0006.sql)
--   quantity_units       id 2 "Piece", 3 "Pack"  (migrations/0006.sql)
--   shopping_lists       id 1 "Shopping list"
--   users                id 1 "admin"
--   meal_plan_sections   id -1, the internal section
--
-- Note the gap that catches people: there is no location with id 1 on a default
-- install. migrations/8888.php creates one only when FEATURE_FLAG_STOCK_LOCATION_TRACKING
-- is off, and config-dist.php defaults it on. The trigger tests were written against a
-- database that had one, so it is created here.

-- The one piece of schema in this file, and it is here because SQLite can no longer
-- receive it any other way. stock_entry_origins arrives in migrations/0267.pgsql.sql, above
-- DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, so bin/victual-migrate never creates
-- it on this side. The rollback phase runs StockService against SQLite and drives
-- OpenProduct(), which writes a row here whenever a partial open splits an entry -- so
-- without the table that phase would fail on a missing table instead of on the failure it
-- injects, and would still report a pass, which is precisely the miss rollback-tests.php
-- says it is most likely to develop. Creating it in the fixture keeps the accommodation in
-- the tooling that deliberately runs a frozen engine rather than putting an engine test
-- inside StockService. PostgreSQL gets its own definition from the migration, never from
-- here: this file is applied to SQLite only.
CREATE TABLE stock_entry_origins (
	id INTEGER PRIMARY KEY,
	stock_id TEXT NOT NULL UNIQUE,
	origin_stock_id TEXT NOT NULL,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime')),
	CHECK (stock_id <> origin_stock_id)
);
CREATE INDEX ix_stock_entry_origins_origin ON stock_entry_origins (origin_stock_id);

-- Locations. id 1 is the one the trigger tests assume; id 3 is a freezer, so that
-- anything keyed on is_freezer has both cases to look at.
INSERT INTO locations (id, name, description, is_freezer) VALUES (1, 'Pantry', 'Base fixture location', 0);
INSERT INTO locations (id, name, description, is_freezer) VALUES (3, 'Freezer', 'Base fixture freezer', 1);

-- Stores. stock.shopping_location_id 1 is referenced by the purchase in trigger test 01.
INSERT INTO shopping_locations (id, name, description) VALUES (1, 'Base Store', 'Base fixture store');
INSERT INTO shopping_locations (id, name, description) VALUES (2, 'Other Store', 'Second store, for filtering');

-- Equipment, which nothing else in the suite refers to yet: richtext-tests.php plants a
-- payload in every column of BaseApiController::HTML_RENDERED_COLUMNS and fails loudly when
-- one of the five tables is empty, because a phase that quietly tested four of five columns
-- is the shape of miss this fixture's own suite exists to catch.
INSERT INTO equipment (id, name, description) VALUES (1, 'Base Equipment', 'Base fixture equipment');

-- A product group, so product_groups-joined views have a non-null case and a null one.
INSERT INTO product_groups (id, name, description) VALUES (1, 'Base Group', 'Base fixture group');

-- Products 3 to 6 are referenced by name-and-number throughout trigger-tests/.
-- Product 3 carries stock and appears in recipes and the meal plan; 4 and 5 sit on a
-- shopping list; 6 is the one whose quantity unit gets changed under stock.
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (3, 'Base Product Three', 'Carries stock', 1, 1, 2, 2, 0, 0);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (4, 'Base Product Four', 'On a shopping list', 1, 1, 2, 2, 0, 0);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (5, 'Base Product Five', 'On a shopping list', NULL, 1, 2, 2, 0, 0);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
VALUES (6, 'Base Product Six', 'Quantity unit gets changed', NULL, 1, 2, 2, 0, 0);

-- Recipes 2 and 3 are put on the meal plan by trigger test 08.
INSERT INTO recipes (id, name, description, base_servings, desired_servings)
VALUES (2, 'Base Recipe Two', 'Base fixture recipe', 2, 2);
INSERT INTO recipes (id, name, description, base_servings, desired_servings)
VALUES (3, 'Base Recipe Three', 'Base fixture recipe', 4, 4);
INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (2, 3, 1, 2);

-- A visible meal plan section. The migrated database only has the internal id -1, and
-- the meal plan trigger tests insert against section_id 1.
INSERT INTO meal_plan_sections (id, name, sort_number) VALUES (1, 'Base Section', 1);

-- Battery 2 and chore 3 are deleted by trigger test 07 to exercise their cascades.
INSERT INTO batteries (id, name, description, used_in) VALUES (2, 'Base Battery', 'Base fixture battery', 'Base fixture device');
INSERT INTO chores (id, name, description, period_type, period_days) VALUES (3, 'Base Chore', 'Base fixture chore', 'manually', 1);
