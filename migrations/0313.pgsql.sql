-- Issue #916 (2026-10-13 bug bash; maintainer decisions D4 and D7, production gate R2): every
-- double precision and numeric column of a base table holds a finite number or NULL.
--
-- WHAT THIS CHANGES.
--   Each column listed below gets CHECK (<column> > '-Infinity' AND <column> < 'Infinity'),
--   named <table>_<column>_finite. PostgreSQL stores the strings 'NaN', 'Infinity' and
--   '-Infinity' (and 'inf', and an out-of-range double the request filter turns into "INF")
--   as special values, and a row holding one can no longer be serialised by any read that
--   returns it (issue #912). A write of one now fails with SQLSTATE 23514, which
--   FiniteNumberConstraint turns into a 400 naming the column.
--
--   The predicate is the one the eight columns that already refuse non-finite values use
--   (consumption_*, quantity_unit_conversions.factor, stock_booking_lots, stock_row_lots).
--   PostgreSQL orders NaN above every other value, Infinity included, so `< 'Infinity'`
--   refuses NaN too. NULL passes, and each column's nullability is unchanged. For the two
--   numeric columns the 'Infinity' literal is numeric's own (PostgreSQL 14 and later).
--
--   The two cache__ tables are included. They are filled from views over stock_log, and
--   PostgreSQL raises an error on float overflow and division by zero rather than returning
--   Infinity, so a refresh over finite rows cannot produce a value these constraints refuse.
--
-- PREFLIGHT. The constraints are added only when no listed column holds NaN or an infinity
--   anywhere; otherwise the migration is refused with one report covering every offending
--   column, and nothing is repaired (ADR-0029). Each constraint is added validated, never
--   NOT VALID, and no row is updated or deleted here.
--
-- tests/Pgsql/FiniteNumericColumnsTest.php asserts that every double precision, real and
-- numeric column of a base table carries a constraint of this form, so a column added later
-- without one fails there. DatabaseImporter::FINITE_NUMBER_COLUMNS is the same list for the
-- legacy import's own refusal.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

-- ADR-0029: bounded blocking, validated atomically with the migration record. ADD CONSTRAINT
-- needs ACCESS EXCLUSIVE on each table anyway, so every lock is taken up front, in one
-- statement and one order, instead of escalating from a weaker lock after the check.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE
    cache__products_average_price, cache__products_last_purchased, chores, locations, meal_plan,
    print_evidence, product_barcodes, product_groups, product_location_min_stock, products,
    recipes, recipes_nestings, recipes_pos, shopping_list, stock, stock_label_retirements,
    stock_log, storage_classes
IN ACCESS EXCLUSIVE MODE;

-- One block for the preflight and the constraints, with the column list inline: the migrate
-- role of a credential-split deployment cannot create a temporary table.
DO $$
DECLARE
    col record;
    key_column text;
    column_count bigint;
    total bigint := 0;
    report text := '';
    hints text := '';
    sample text;
    columns_query constant text := $q$SELECT table_name, column_name FROM (VALUES
        ('cache__products_average_price', 'price'),
        ('cache__products_last_purchased', 'amount'),
        ('cache__products_last_purchased', 'price'),
        ('chores', 'product_amount'),
        ('locations', 'tare_weight'),
        ('meal_plan', 'product_amount'),
        ('meal_plan', 'recipe_servings'),
        ('print_evidence', 'confidence'),
        ('product_barcodes', 'amount'),
        ('product_barcodes', 'last_price'),
        ('product_groups', 'min_stock_amount'),
        ('product_location_min_stock', 'min_stock_amount'),
        ('products', 'calories'),
        ('products', 'min_stock_amount'),
        ('products', 'quick_consume_amount'),
        ('products', 'quick_open_amount'),
        ('products', 'quick_refill_amount'),
        ('products', 'tare_weight'),
        ('recipes', 'base_servings'),
        ('recipes', 'desired_servings'),
        ('recipes_nestings', 'servings'),
        ('recipes_pos', 'amount'),
        ('recipes_pos', 'price_factor'),
        ('shopping_list', 'amount'),
        ('stock', 'amount'),
        ('stock', 'opened_amount'),
        ('stock', 'opened_tare'),
        ('stock', 'price'),
        ('stock_label_retirements', 'amount'),
        ('stock_log', 'amount'),
        ('stock_log', 'opened_amount'),
        ('stock_log', 'opened_tare'),
        ('stock_log', 'price'),
        ('storage_classes', 'max_temp_c'),
        ('storage_classes', 'min_temp_c')
    ) AS c(table_name, column_name) ORDER BY table_name, column_name$q$;
BEGIN
    FOR col IN EXECUTE columns_query LOOP
        EXECUTE format('SELECT count(*) FROM %I WHERE NOT (%I > ''-Infinity'' AND %I < ''Infinity'')',
            col.table_name, col.column_name, col.column_name) INTO column_count;

        IF column_count > 0 THEN
            -- The sample names each row by its primary key, whatever that column is called
            -- (the cache tables are keyed by product_id)
            SELECT a.attname INTO key_column
            FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
            WHERE i.indrelid = col.table_name::regclass AND i.indisprimary;

            EXECUTE format('SELECT string_agg(format(''(%%s, %%s)'', k, v), '', '') FROM (SELECT %I::text AS k, %I::text AS v FROM %I WHERE NOT (%I > ''-Infinity'' AND %I < ''Infinity'') ORDER BY %I LIMIT 10) bad',
                key_column, col.column_name, col.table_name, col.column_name, col.column_name, key_column) INTO sample;

            total := total + column_count;
            report := report || format(' %s.%s: %s row(s), sample (%s, value): %s.', col.table_name, col.column_name, column_count, key_column, sample);
            hints := hints || format(' SELECT %I, %I FROM %I WHERE NOT (%I > ''-Infinity'' AND %I < ''Infinity'') ORDER BY %I;',
                key_column, col.column_name, col.table_name, col.column_name, col.column_name, key_column);
        END IF;
    END LOOP;

    IF total > 0 THEN
        RAISE EXCEPTION 'Migration refused: % value(s) are NaN or infinite, which no numeric column may hold any more.% Choose an explicit repair and rerun migration. No row has been changed.', total, report
            USING HINT = 'List all:' || hints;
    END IF;

    FOR col IN EXECUTE columns_query LOOP
        EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (%I > ''-Infinity'' AND %I < ''Infinity'')',
            col.table_name, col.table_name || '_' || col.column_name || '_finite', col.column_name, col.column_name);
    END LOOP;
END $$;
