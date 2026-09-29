-- Issue #521 (M21, #487 remediation): six controllers served GET routes with no permission
-- check at all (BatteriesController's seven page routes, BatteriesApiController's
-- Current/BatteryDetails, CalendarController::Overview, CalendarApiController's
-- Ical/IcalSharingLink, GenericEntityController's userentities/userfields/userobjects pages,
-- EquipmentController's Overview/EditForm) - any authenticated session, holding no permissions
-- at all, could read every battery, the whole calendar feed, every equipment row and every
-- custom entity/field definition. See tests/Pgsql/ViewPermissionGatingTest.php for the
-- regression coverage and the controllers themselves for the added User::CheckPermission()
-- calls this migration's three new leaves are for.
--
-- Maintainer decision: add dedicated _VIEW leaves for BATTERIES, CALENDAR and EQUIPMENT,
-- following exactly how STOCK_VIEW (migrations/0266.pgsql.php via db/pgsql/roles-seed.sql) and
-- STOCK_PRICES_VIEW (migrations/0281.pgsql.sql) were modelled - but the STOCK_PRICES_VIEW
-- shape, not the STOCK_VIEW one, is the right template here, for a reason that matters for the
-- upgrade path.
--
-- STOCK_VIEW's own migration (0266) backfilled the new leaf into *every* user's
-- user_permissions unconditionally, because at that point there was no fine-grained stock
-- permission at all yet - nobody's read access depended on holding STOCK, so "preserve what
-- every user could already do" meant "grant it to everyone". That is not this situation:
-- BATTERIES, CALENDAR and EQUIPMENT already exist as real, already-granted permissions
-- (db/pgsql/roles-seed.sql grants BATTERIES/EQUIPMENT/CALENDAR to the Adult role, and any
-- household may have granted them to individual users too) - the bug fixed here is only that
-- the *_VIEW leaf did not exist to gate the page/API reads on, not that the permission model
-- itself was missing. So the correct preservation rule is "a user who already holds the
-- parent permission keeps read access; a user who does not, loses it" - which is exactly what
-- 0281 already worked out for STOCK_PRICES_VIEW under STOCK_PURCHASE: nest the new leaf as a
-- *child* of the parent in permission_hierarchy, and permission_tree's recursive CTE
-- (migrations/0110.sql) resolves every existing holder of the parent - via a role grant in
-- role_permissions or a direct grant in user_permissions, ADMIN included - down to the new
-- leaf the moment the row exists. No user_permissions backfill INSERT is needed, or wanted:
-- inserting one would grant the leaf to a user who does not hold the parent, which is
-- precisely the access this migration is meant to remove for the deliberate reason maintainer
-- decision states: "users who hold no relevant permission lose access, and that loss is the
-- fix".
INSERT INTO permission_hierarchy (name, parent)
SELECT 'BATTERIES_VIEW', id FROM permission_hierarchy WHERE name = 'BATTERIES'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'BATTERIES_VIEW');

INSERT INTO permission_hierarchy (name, parent)
SELECT 'CALENDAR_VIEW', id FROM permission_hierarchy WHERE name = 'CALENDAR'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'CALENDAR_VIEW');

INSERT INTO permission_hierarchy (name, parent)
SELECT 'EQUIPMENT_VIEW', id FROM permission_hierarchy WHERE name = 'EQUIPMENT'
AND NOT EXISTS (SELECT 1 FROM permission_hierarchy WHERE name = 'EQUIPMENT_VIEW');
