-- migrations/0320.pgsql.sql (issue #1111, ADR-0057 piece 1): the permission catalog of plan 42,
-- the household manager role, the open definition reads, and the before-and-after check of
-- ADR-0057 decision 2.
--
-- Part 1 reads the tree a fresh install leaves. Part 2 rehearses the upgrade: it removes what
-- 0320 added, plants accounts, roles and keys that hold today's permissions, and runs the
-- migration's own three steps (record, seed, assert). Part 3 plants changes the intentional-change
-- list does not name and checks that the assertion refuses them.
--
-- The whole file runs in one transaction and rolls back. Each refusal runs in its own savepoint
-- (throws_ok does that), so the cases do not abort one another.

BEGIN;

SELECT plan(50);

-- Part 1: the tree --------------------------------------------------------------------------------

SELECT is((SELECT COUNT(*)::INTEGER FROM permission_catalog_leaves()), 190,
	'catalog: 180 resource leaves, 9 action leaves and LABELS_ADMIN');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_catalog_leaves() WHERE name ~ '_(CREATE|READ|UPDATE|DELETE)$'), 180,
	'catalog: 180 resource leaves, as plan 42 counts them');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_hierarchy), 231,
	'tree: the 41 existing permissions plus the 190 new rows');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_catalog_leaves() c
	LEFT JOIN permission_hierarchy ph ON ph.name = c.name
	LEFT JOIN permission_hierarchy pp ON pp.id = ph.parent
	WHERE pp.name IS DISTINCT FROM c.parent), 0,
	'tree: every catalog leaf sits under the parent the catalog gives it');
SELECT is((SELECT COUNT(*)::INTEGER FROM (SELECT name FROM permission_catalog_leaves() GROUP BY name HAVING COUNT(*) > 1) d), 0,
	'catalog: no leaf is listed twice');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_tree WHERE id = (SELECT id FROM permission_hierarchy WHERE name = 'ADMIN')), 231,
	'tree: ADMIN resolves every row, so holding ADMIN is still holding every leaf');

CREATE FUNCTION pg_temp.parent_of(leaf TEXT) RETURNS TEXT LANGUAGE sql AS
$$ SELECT pp.name FROM permission_hierarchy ph JOIN permission_hierarchy pp ON pp.id = ph.parent WHERE ph.name = leaf $$;

SELECT is(pg_temp.parent_of('TASKS_DELETE'), 'TASKS', 'tree: task delete moves under TASKS (answer 1)');
SELECT is(pg_temp.parent_of('CHORES_DELETE'), 'MASTER_DATA_EDIT', 'tree: chore delete stays under MASTER_DATA_EDIT (answer 1 follow-up)');
SELECT is(pg_temp.parent_of('SYSTEM_INFO_READ'), 'ADMIN', 'tree: SYSTEM_INFO_READ under ADMIN (answer 3)');
SELECT is(pg_temp.parent_of('LABELS_ADMIN'), 'ADMIN', 'tree: LABELS_ADMIN under ADMIN (answer 2)');
SELECT is(pg_temp.parent_of('PRINT_JOBS_READ'), 'LABELS_ADMIN', 'tree: label management leaves under LABELS_ADMIN');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_catalog_leaves() WHERE parent = 'LABELS_ADMIN'), 21, 'tree: LABELS_ADMIN holds 21 leaves');
SELECT is(pg_temp.parent_of('PRODUCTS_CREATE'), 'MASTER_DATA_ADD', 'tree: master data create under MASTER_DATA_ADD');
SELECT is(pg_temp.parent_of('QUANTITY_UNIT_CONVERSIONS_CREATE'), 'MASTER_DATA_EDIT', 'tree: conversion create stays under MASTER_DATA_EDIT (ADR-0043 decision 2)');
SELECT is(pg_temp.parent_of('USERFIELDS_READ'), 'ADMIN', 'tree: the open definition reads sit under ADMIN');
SELECT is(pg_temp.parent_of('SHOPPING_LISTS_CREATE'), 'SHOPPINGLIST_ITEMS_ADD', 'tree: list header create under today''s item gate');
SELECT is(pg_temp.parent_of('STOCK_BOOKINGS_UNDO'), 'STOCK_EDIT', 'tree: the undo action under STOCK_EDIT');
SELECT is(pg_temp.parent_of('MASTER_DATA_ADD'), 'MASTER_DATA_EDIT', 'tree: existing permissions keep their parents');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_hierarchy WHERE name IN
	('GROUPS_CREATE', 'GROUPS_READ', 'GROUPS_UPDATE', 'GROUPS_DELETE', 'GROUP_MEMBERS_UPDATE', 'SERVICE_ACCOUNTS_MANAGE')), 0,
	'tree: group and service leaves wait for the resources they gate');

-- Other files here commit users of their own after the migration ran, so only the bootstrap
-- administrator is known to have existed then; part 2 checks every user after its own seed.
SELECT is((SELECT COUNT(*)::INTEGER FROM user_permissions up JOIN permission_hierarchy p ON p.id = up.permission_id
	WHERE up.user_id = 1 AND p.name IN ('USERFIELDS_READ', 'USERENTITIES_READ')), 2,
	'open reads: the bootstrap administrator holds both definition reads directly');
SELECT is((SELECT COUNT(*)::INTEGER FROM roles r WHERE (SELECT COUNT(*) FROM role_permissions rp JOIN permission_hierarchy p ON p.id = rp.permission_id
	WHERE rp.role_id = r.id AND p.name IN ('USERFIELDS_READ', 'USERENTITIES_READ')) <> 2), 0,
	'open reads: every role holds USERFIELDS_READ and USERENTITIES_READ');
SELECT set_eq(
	$$ SELECT p.name FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permission_hierarchy p ON p.id = rp.permission_id WHERE r.code = 'HOUSEHOLD_MANAGER' $$,
	ARRAY['STOCK', 'SHOPPINGLIST', 'RECIPES', 'RECIPES_MEALPLAN', 'CHORES', 'TASKS', 'BATTERIES', 'EQUIPMENT', 'CALENDAR', 'USERS_READ', 'USERS_EDIT_SELF', 'MASTER_DATA_EDIT', 'USERFIELDS_READ', 'USERENTITIES_READ'],
	'household manager: the Adult grants plus MASTER_DATA_EDIT and the open reads');
SELECT is((SELECT builtin FROM roles WHERE code = 'HOUSEHOLD_MANAGER')::INTEGER, 1, 'household manager: a built-in role');

-- Part 2: the upgrade rehearsal --------------------------------------------------------------------

-- Put the database back where 0319 left it.
DELETE FROM role_permissions WHERE permission_id IN (SELECT ph.id FROM permission_hierarchy ph JOIN permission_catalog_leaves() c ON c.name = ph.name);
DELETE FROM user_permissions WHERE permission_id IN (SELECT ph.id FROM permission_hierarchy ph JOIN permission_catalog_leaves() c ON c.name = ph.name);
DELETE FROM roles WHERE code = 'HOUSEHOLD_MANAGER';
DELETE FROM permission_hierarchy WHERE name IN (SELECT name FROM permission_catalog_leaves() WHERE name <> 'LABELS_ADMIN');
DELETE FROM permission_hierarchy WHERE name = 'LABELS_ADMIN';
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_hierarchy), 41, 'rehearsal: the tree is back to its 41 permissions');

-- 9701 Adult, 9702 Child, 9703 Guest, 9704 direct MASTER_DATA_EDIT without TASKS,
-- 9705 direct TASKS only, 9706 no grants, 9707 ADMIN role; keys on 9701 and 9704; a custom role
-- holding MASTER_DATA_EDIT alone.
INSERT INTO users (id, username, password) VALUES
	(9701, 'p42-adult', 'x'), (9702, 'p42-child', 'x'), (9703, 'p42-guest', 'x'), (9704, 'p42-mde', 'x'),
	(9705, 'p42-tasks', 'x'), (9706, 'p42-none', 'x'), (9707, 'p42-admin', 'x');
INSERT INTO user_roles (user_id, role_id) SELECT v.u, r.id FROM (VALUES (9701, 'ADULT'), (9702, 'CHILD'), (9703, 'GUEST'), (9707, 'ADMIN')) v(u, code) JOIN roles r ON r.code = v.code;
INSERT INTO user_permissions (user_id, permission_id) SELECT v.u, p.id FROM (VALUES (9704, 'MASTER_DATA_EDIT'), (9705, 'TASKS')) v(u, name) JOIN permission_hierarchy p ON p.name = v.name;
INSERT INTO api_keys (id, api_key, user_id) VALUES (9701, 'p42-key-adult', 9701), (9704, 'p42-key-mde', 9704);
INSERT INTO roles (code, name) VALUES ('P42_MDE_ONLY', 'p42 master data only');
INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permission_hierarchy p WHERE r.code = 'P42_MDE_ONLY' AND p.name = 'MASTER_DATA_EDIT';

CREATE TEMPORARY TABLE before_adult AS SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9701;
CREATE TEMPORARY TABLE before_child AS SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9702;
CREATE TEMPORARY TABLE before_guest AS SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9703;

SELECT cmp_ok(permission_snapshot_record(), '>=', 12, 'rehearsal: the snapshot records every user, role and key');
SELECT lives_ok($$ SELECT permission_catalog_seed() $$, 'rehearsal: the seed runs');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_hierarchy), 231, 'rehearsal: the seed restores all 190 rows');
SELECT is((SELECT COUNT(*)::INTEGER FROM users u WHERE NOT EXISTS (
	SELECT 1 FROM user_permissions up JOIN permission_hierarchy p ON p.id = up.permission_id
	WHERE up.user_id = u.id AND p.name = 'USERFIELDS_READ')), 0,
	'rehearsal: every existing user is granted USERFIELDS_READ directly');

CREATE TEMPORARY TABLE d AS SELECT * FROM permission_upgrade_differences(permission_catalog_gates());

SELECT is((SELECT COUNT(*)::INTEGER FROM d WHERE permission_name NOT IN ('TASKS_DELETE', 'SYSTEM_INFO_READ')), 0,
	'rehearsal: the only differences are plan 42''s two intentional changes');
SELECT set_eq($$ SELECT principal_kind || ':' || principal_label FROM d WHERE permission_name = 'TASKS_DELETE' AND change = 'gained' AND principal_label NOT LIKE 'key %' $$,
	ARRAY['user:p42-adult', 'user:p42-tasks', 'role:ADULT'],
	'(a): the holders of TASKS without MASTER_DATA_EDIT gain task delete');
SELECT set_eq($$ SELECT principal_kind || ':' || principal_label FROM d WHERE permission_name = 'TASKS_DELETE' AND change = 'lost' AND principal_label NOT LIKE 'key %' $$,
	ARRAY['user:p42-mde', 'role:P42_MDE_ONLY'],
	'(a): the holders of MASTER_DATA_EDIT without TASKS lose it');
SELECT set_eq($$ SELECT principal_id || ':' || change FROM d WHERE permission_name = 'TASKS_DELETE' AND principal_kind = 'api_key' $$,
	ARRAY['9701:gained', '9704:lost'],
	'(a): a key follows its owner');
SELECT is((SELECT COUNT(*)::INTEGER FROM d WHERE change = 'gained' AND permission_name = 'SYSTEM_INFO_READ'), 0,
	'(b): nobody gains the system information fields');
SELECT ok(EXISTS (SELECT 1 FROM d WHERE permission_name = 'SYSTEM_INFO_READ' AND change = 'lost' AND principal_label = 'p42-guest')
	AND NOT EXISTS (SELECT 1 FROM d WHERE permission_name = 'SYSTEM_INFO_READ' AND principal_label IN ('p42-admin', 'ADMIN')),
	'(b): an account without ADMIN loses the system information fields; ADMIN holders keep them');
SELECT ok(NOT EXISTS (SELECT 1 FROM d WHERE principal_label IN ('p42-child', 'p42-none', 'CHILD', 'GUEST') AND permission_name <> 'SYSTEM_INFO_READ'),
	'Child, Guest and an account with no grants change in nothing but (b)');
SELECT lives_ok($$ SELECT permission_upgrade_assert(permission_catalog_gates(), permission_catalog_intentional_changes(), 'rehearsal') $$,
	'rehearsal: the assertion passes with plan 42''s list');
SELECT throws_like($$ SELECT permission_upgrade_assert(permission_catalog_gates(), '[]'::JSONB, 'rehearsal') $$,
	'%resolved permission change(s) are not on the intentional-change list%',
	'rehearsal: with an empty list the same upgrade is refused');

-- What the seeded roles resolve, through the new tree, apart from the new leaves: unchanged.
SELECT set_eq($$ SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9701 AND permission_name IN (SELECT name FROM pg_temp.permission_snapshot_names) $$,
	$$ SELECT permission_name FROM before_adult $$, 'Adult: the same existing permissions after as before');
SELECT set_eq($$ SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9702 AND permission_name IN (SELECT name FROM pg_temp.permission_snapshot_names) $$,
	$$ SELECT permission_name FROM before_child $$, 'Child: the same existing permissions after as before');
SELECT set_eq($$ SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9703 AND permission_name IN (SELECT name FROM pg_temp.permission_snapshot_names) $$,
	$$ SELECT permission_name FROM before_guest $$, 'Guest: the same existing permissions after as before');

SELECT lives_ok($$ SELECT permission_catalog_seed() $$, 'seed: a second run succeeds');
SELECT is((SELECT COUNT(*)::INTEGER FROM permission_hierarchy), 231, 'seed: a second run adds no row');

-- Part 3: refusals ---------------------------------------------------------------------------------

-- A fresh snapshot, then a change nobody listed: the guest gains STOCK_EDIT directly.
SELECT permission_snapshot_record();
INSERT INTO user_permissions (user_id, permission_id) SELECT 9703, id FROM permission_hierarchy WHERE name = 'STOCK_EDIT';
SELECT throws_ok($$ SELECT permission_upgrade_assert(permission_catalog_gates(), permission_catalog_intentional_changes(), 'refusal') $$,
	'VP057', E'refusal: 3 resolved permission change(s) are not on the intentional-change list, so nothing was changed:\nuser 9703 (p42-guest) gained STOCK_BOOKINGS_UNDO\nuser 9703 (p42-guest) gained STOCK_EDIT\nuser 9703 (p42-guest) gained STOCK_ENTRIES_UPDATE',
	'refusal: an unlisted gain raises VP057 and names the account and each leaf');
SELECT set_eq($$ SELECT principal_kind || ':' || principal_label || ':' || change || ':' || permission_name FROM permission_upgrade_differences(permission_catalog_gates()) $$,
	ARRAY['user:p42-guest:gained:STOCK_EDIT', 'user:p42-guest:gained:STOCK_ENTRIES_UPDATE', 'user:p42-guest:gained:STOCK_BOOKINGS_UNDO'],
	'refusal: the differences name the account and each leaf it gained');

-- An unlisted loss: a role grant removed.
DELETE FROM user_permissions WHERE user_id = 9703;
DELETE FROM role_permissions WHERE role_id = (SELECT id FROM roles WHERE code = 'GUEST') AND permission_id = (SELECT id FROM permission_hierarchy WHERE name = 'RECIPES_VIEW');
SELECT throws_like($$ SELECT permission_upgrade_assert(permission_catalog_gates(), permission_catalog_intentional_changes(), 'refusal') $$,
	'refusal: % resolved permission change(s) are not on the intentional-change list%',
	'refusal: an unlisted loss raises too');
SELECT ok((SELECT bool_and(change = 'lost') FROM permission_upgrade_differences(permission_catalog_gates()) WHERE principal_label IN ('GUEST', 'p42-guest')),
	'refusal: the Guest role and its holder are reported as losing');

-- The seed refuses a tree that already places a catalog name elsewhere.
UPDATE permission_hierarchy SET parent = (SELECT id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT') WHERE name = 'PRODUCTS_READ';
SELECT throws_like($$ SELECT permission_catalog_seed() $$,
	'%PRODUCTS_READ (expected under STOCK_VIEW, found under MASTER_DATA_EDIT)%',
	'seed: a catalog leaf under the wrong parent is refused');
UPDATE permission_hierarchy SET parent = (SELECT id FROM permission_hierarchy WHERE name = 'STOCK_VIEW') WHERE name = 'PRODUCTS_READ';

-- A custom role holding the name: the built-in role is not created and the custom one gains no grant.
DELETE FROM roles WHERE code = 'HOUSEHOLD_MANAGER';
INSERT INTO roles (code, name) VALUES ('P42_TAKEN', 'Household manager');
SELECT lives_ok($$ SELECT permission_catalog_seed() $$, 'seed: a taken role name does not stop the seed');
SELECT is((SELECT COUNT(*)::INTEGER FROM roles WHERE code = 'HOUSEHOLD_MANAGER'), 0, 'seed: no household manager is created beside a role of that name');
SELECT set_eq($$ SELECT p.name FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permission_hierarchy p ON p.id = rp.permission_id WHERE r.code = 'P42_TAKEN' $$,
	ARRAY['USERFIELDS_READ', 'USERENTITIES_READ'], 'seed: the custom role gets the open reads only');

SELECT * FROM finish();
ROLLBACK;
