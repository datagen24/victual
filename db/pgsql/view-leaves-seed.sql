-- The BATTERIES_VIEW, CALENDAR_VIEW and EQUIPMENT_VIEW read leaves, each nested under the
-- permission it reads for. Issue #521 (M21, #487 remediation); migrations/0299.pgsql.sql
-- records the maintainer decision and why the leaves are children of their parents with no
-- user_permissions backfill.
--
-- WHY THIS IS A FILE AS WELL AS migrations/0299.pgsql.sql, which is where these rows first
-- shipped. bin/victual-db-import truncates permission_hierarchy with RESTART IDENTITY
-- CASCADE and copies the source's rows in. The SQLite line is frozen at 0265, so no source
-- database can carry a row 0299 added, and after an import the three leaves are gone. The
-- battery, calendar and equipment pages and APIs check them (User::CheckPermission(), see
-- tests/Pgsql/ViewPermissionGatingTest.php), and ADMIN reaches a leaf only through
-- permission_tree, so every user including ADMIN would be refused those pages. The importer
-- re-applies db/pgsql/roles-seed.sql and db/pgsql/prices-seed.sql for the same reason, and
-- neither carries these rows. This is the same kind of file for 0299, applied right after
-- them.
--
-- The statements are 0299's, unchanged. Every one is idempotent: on an installed or
-- upgraded database 0299 already made the rows and this file adds nothing, and the next
-- import applies it again. No role_permissions or user_permissions row is added here,
-- because 0299 added none: every holder of BATTERIES, CALENDAR or EQUIPMENT, by role or
-- directly, resolves the leaf through the hierarchy.
--
-- .devtools/pgsql/import-tests.php asserts the restoration, including the parent of each
-- leaf and what ADMIN, the Adult role and the Child role resolve.

INSERT INTO permission_hierarchy (name, parent)
SELECT 'BATTERIES_VIEW', id FROM permission_hierarchy WHERE name = 'BATTERIES'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'BATTERIES_VIEW');

INSERT INTO permission_hierarchy (name, parent)
SELECT 'CALENDAR_VIEW', id FROM permission_hierarchy WHERE name = 'CALENDAR'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'CALENDAR_VIEW');

INSERT INTO permission_hierarchy (name, parent)
SELECT 'EQUIPMENT_VIEW', id FROM permission_hierarchy WHERE name = 'EQUIPMENT'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'EQUIPMENT_VIEW');
