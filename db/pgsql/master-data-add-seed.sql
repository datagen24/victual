-- The MASTER_DATA_ADD permission, nested under MASTER_DATA_EDIT. ADR-0043 decision 1,
-- issue 742 (docs/adr/0043-creating-shared-master-data-is-a-right-below-master-data-edit.md).
--
-- MASTER_DATA_ADD is the right to create products, product barcodes, locations, quantity
-- units, product groups and shopping locations, without the right to rewrite or delete the
-- ones that exist. It is a child of MASTER_DATA_EDIT in permission_hierarchy, so permission_tree
-- (migrations/0110.sql) resolves it for every holder of MASTER_DATA_EDIT - by role or by a
-- direct grant, ADMIN included - the moment the row exists. Nothing narrower than
-- MASTER_DATA_EDIT holds it until an administrator grants it.
--
-- WHY THERE IS NO user_permissions OR role_permissions BACKFILL. Every account that could
-- create these entities before this row existed held MASTER_DATA_EDIT, and the hierarchy
-- gives each of them MASTER_DATA_ADD. A backfill row would also grant it to an account that
-- does not hold the parent, which is the widening the permission exists to avoid. The same
-- reasoning applies to migrations/0281.pgsql.sql (STOCK_PRICES_VIEW) and
-- migrations/0299.pgsql.sql (the three read leaves). tests/Pgsql/MasterDataAddMigrationTest.php
-- holds the property: only MASTER_DATA_EDIT and ADMIN holders gain the permission, nobody
-- loses one, and no grant row is written.
--
-- WHY THIS IS A FILE. migrations/0308.pgsql.php reads it, the way 0282 reads prices-seed.sql,
-- and DatabaseImporter replays it after every legacy import: bin/victual-db-import truncates
-- permission_hierarchy and copies the source's rows in, and the SQLite line is frozen at
-- 0265, so no source database can carry a row added after it. One file keeps a fresh
-- install, an upgraded database and an imported one on the same statement.
--
-- The statement is idempotent: on a database that already has the row it adds nothing.
-- .devtools/pgsql/import-tests.php asserts the parent and what ADMIN, a MASTER_DATA_EDIT
-- holder, the Adult role and the Child role resolve after an import.

INSERT INTO permission_hierarchy (name, parent)
SELECT 'MASTER_DATA_ADD', id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'MASTER_DATA_ADD');
