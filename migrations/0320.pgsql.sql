-- ADR-0057 piece 1 (issue 1111): the permission catalog of plan 42, seeded into
-- permission_hierarchy, with the before-and-after check ADR-0057 decision 2 requires.
-- docs/plans/42-permission-catalog.md is the source of every row below; its "Implementation"
-- section lists the pieces that follow this one.
--
-- WHAT THIS ADDS. 180 resource leaves (SYSTEM_INFO_READ among them), 9 action leaves and the
-- LABELS_ADMIN bundle, each under the permission that gates the operation today. Holding a
-- node holds every descendant (permission_tree), so every account that holds today's broad
-- permission resolves the new leaves under it the moment the rows exist, and nothing in the
-- application checks a new leaf yet: piece 2 moves the checks.
--
-- Not seeded here: GROUPS_CREATE, GROUPS_READ, GROUPS_UPDATE, GROUPS_DELETE,
-- GROUP_MEMBERS_UPDATE and SERVICE_ACCOUNTS_MANAGE. Plan 42 lists them so that one migration
-- seeds the catalog, but the groups and service accounts they would gate do not exist.
-- Seeded now, each would be a grantable row that controls nothing, shown in the permission
-- editor beside rows that do. They arrive with the migration that creates the groups and
-- service-account tables, which runs this same check through the functions below.
--
-- WHAT ELSE IT WRITES. Three things plan 42's "What the upgrade does" asks for:
--   - USERFIELDS_READ and USERENTITIES_READ granted to every existing user and every
--     existing role, because today's code checks nothing on those reads (ADR-0057 decision
--     3, plan 42 answer 4). Both sit under ADMIN so that holding ADMIN still means every leaf.
--   - HOUSEHOLD_MANAGER, a new built-in role: the Adult role's grants plus MASTER_DATA_EDIT,
--     plus the two open reads, which new roles carry. If a role already holds its code or its
--     name, the migration leaves that role alone and seeds no grants (a NOTICE says so).
--   - Nothing else. No grant row is written for any other leaf, for the reason
--     migrations/0308.pgsql.php gives: a backfill would grant a leaf to an account that does
--     not hold its parent.
--
-- THE CHECK (ADR-0057 decision 2). Before the tree changes, permission_snapshot_record()
-- returns the resolved set of every user, role and API key as one JSONB value (a key carries
-- its owner's set; ADR-0044's per-key subsets are not built). After the seed, in this same
-- transaction,
-- permission_upgrade_assert() resolves them again and compares operation by operation:
--   - a permission that existed before is compared with itself;
--   - a new leaf is compared with the permission that gated its operation before the leaf
--     existed: its nearest pre-existing ancestor, unless permission_catalog_gates() names a
--     different gate (TASKS_DELETE was MASTER_DATA_EDIT's) or none (the three reads today's
--     code leaves open to everyone).
-- Any difference that permission_catalog_intentional_changes() does not list raises SQLSTATE
-- VP057 with every principal and leaf, and the migration rolls back. The list holds plan 42's
-- two entries:
--   (a) TASKS_DELETE moves from MASTER_DATA_EDIT to TASKS (answer 1): holders of TASKS without
--       MASTER_DATA_EDIT gain it, and holders of MASTER_DATA_EDIT without TASKS lose it. The
--       loss is the other half of the move, the same both-ways change ADR-0043 decision 7
--       recorded for create and edit. No seeded role is in the losing half; ADMIN holds both.
--       CHORES_DELETE stays under MASTER_DATA_EDIT.
--   (b) SYSTEM_INFO_READ (answer 3): every principal without ADMIN stops holding the read of
--       /api/system/info's PHP, database and kernel fields. The code half of (b) is its own
--       change; this list is what lets the tree half pass.
--
-- A FRESH INSTALL runs this migration too (every migration above the baseline does), after
-- InitialDataSeeder has created the bootstrap administrator, so the check runs on that
-- account. THE LEGACY IMPORTER runs the same check after an import:
-- DatabaseImporter::ReplayPermissionSeeds() records the copied users' and keys' sets before it
-- replays the seed files and calls permission_catalog_seed(), and asserts afterwards with
-- the gates of the older leaves those files restore.
--
-- .devtools/pgtap/042-permission-catalog.sql tests the tree, the seed, the check and a refusal;
-- tests/Pgsql/PermissionCatalogMigrationTest.php tests the roles and an upgrade end to end.

-- The catalog: every leaf this migration adds, with its parent. The order is plan 42's.
CREATE OR REPLACE FUNCTION permission_catalog_leaves()
RETURNS TABLE (name TEXT, parent TEXT, ordinal INTEGER)
LANGUAGE sql IMMUTABLE AS $fn$
	SELECT v.name, v.parent, v.ordinal
	FROM (VALUES
		(1, 'LABELS_ADMIN', 'ADMIN'),
		-- Stock master data
		(2, 'PRODUCTS_CREATE', 'MASTER_DATA_ADD'),
		(3, 'PRODUCTS_READ', 'STOCK_VIEW'),
		(4, 'PRODUCTS_UPDATE', 'MASTER_DATA_EDIT'),
		(5, 'PRODUCTS_DELETE', 'MASTER_DATA_EDIT'),
		(6, 'PRODUCT_BARCODES_CREATE', 'MASTER_DATA_ADD'),
		(7, 'PRODUCT_BARCODES_READ', 'STOCK_VIEW'),
		(8, 'PRODUCT_BARCODES_UPDATE', 'MASTER_DATA_EDIT'),
		(9, 'PRODUCT_BARCODES_DELETE', 'MASTER_DATA_EDIT'),
		(10, 'LOCATIONS_CREATE', 'MASTER_DATA_ADD'),
		(11, 'LOCATIONS_READ', 'STOCK_VIEW'),
		(12, 'LOCATIONS_UPDATE', 'MASTER_DATA_EDIT'),
		(13, 'LOCATIONS_DELETE', 'MASTER_DATA_EDIT'),
		(14, 'PRODUCT_GROUPS_CREATE', 'MASTER_DATA_ADD'),
		(15, 'PRODUCT_GROUPS_READ', 'STOCK_VIEW'),
		(16, 'PRODUCT_GROUPS_UPDATE', 'MASTER_DATA_EDIT'),
		(17, 'PRODUCT_GROUPS_DELETE', 'MASTER_DATA_EDIT'),
		(18, 'QUANTITY_UNITS_CREATE', 'MASTER_DATA_ADD'),
		(19, 'QUANTITY_UNITS_READ', 'STOCK_VIEW'),
		(20, 'QUANTITY_UNITS_UPDATE', 'MASTER_DATA_EDIT'),
		(21, 'QUANTITY_UNITS_DELETE', 'MASTER_DATA_EDIT'),
		(22, 'QUANTITY_UNIT_CONVERSIONS_CREATE', 'MASTER_DATA_EDIT'),
		(23, 'QUANTITY_UNIT_CONVERSIONS_READ', 'STOCK_VIEW'),
		(24, 'QUANTITY_UNIT_CONVERSIONS_UPDATE', 'MASTER_DATA_EDIT'),
		(25, 'QUANTITY_UNIT_CONVERSIONS_DELETE', 'MASTER_DATA_EDIT'),
		(26, 'STORES_CREATE', 'MASTER_DATA_ADD'),
		(27, 'STORES_READ', 'STOCK_VIEW'),
		(28, 'STORES_UPDATE', 'MASTER_DATA_EDIT'),
		(29, 'STORES_DELETE', 'MASTER_DATA_EDIT'),
		(30, 'STORAGE_CLASSES_CREATE', 'MASTER_DATA_EDIT'),
		(31, 'STORAGE_CLASSES_READ', 'STOCK_VIEW'),
		(32, 'STORAGE_CLASSES_UPDATE', 'MASTER_DATA_EDIT'),
		(33, 'STORAGE_CLASSES_DELETE', 'MASTER_DATA_EDIT'),
		(34, 'PRODUCT_SUBSTITUTIONS_CREATE', 'MASTER_DATA_EDIT'),
		(35, 'PRODUCT_SUBSTITUTIONS_READ', 'STOCK_VIEW'),
		(36, 'PRODUCT_SUBSTITUTIONS_UPDATE', 'MASTER_DATA_EDIT'),
		(37, 'PRODUCT_SUBSTITUTIONS_DELETE', 'MASTER_DATA_EDIT'),
		(38, 'PRODUCT_MINIMUM_STOCK_CREATE', 'MASTER_DATA_EDIT'),
		(39, 'PRODUCT_MINIMUM_STOCK_READ', 'STOCK_VIEW'),
		(40, 'PRODUCT_MINIMUM_STOCK_UPDATE', 'MASTER_DATA_EDIT'),
		(41, 'PRODUCT_MINIMUM_STOCK_DELETE', 'MASTER_DATA_EDIT'),
		(42, 'MQTT_PRODUCT_ENTITIES_CREATE', 'MASTER_DATA_EDIT'),
		(43, 'MQTT_PRODUCT_ENTITIES_READ', 'STOCK_VIEW'),
		(44, 'MQTT_PRODUCT_ENTITIES_UPDATE', 'MASTER_DATA_EDIT'),
		(45, 'MQTT_PRODUCT_ENTITIES_DELETE', 'MASTER_DATA_EDIT'),
		-- Stock
		(46, 'STOCK_ENTRIES_READ', 'STOCK_VIEW'),
		(47, 'STOCK_ENTRIES_UPDATE', 'STOCK_EDIT'),
		(48, 'STOCK_JOURNAL_READ', 'STOCK_VIEW'),
		-- Shopping
		(49, 'SHOPPING_LISTS_CREATE', 'SHOPPINGLIST_ITEMS_ADD'),
		(50, 'SHOPPING_LISTS_READ', 'SHOPPINGLIST_VIEW'),
		(51, 'SHOPPING_LISTS_UPDATE', 'SHOPPINGLIST_ITEMS_ADD'),
		(52, 'SHOPPING_LISTS_DELETE', 'SHOPPINGLIST_ITEMS_DELETE'),
		(53, 'SHOPPING_LIST_ITEMS_CREATE', 'SHOPPINGLIST_ITEMS_ADD'),
		(54, 'SHOPPING_LIST_ITEMS_READ', 'SHOPPINGLIST_VIEW'),
		(55, 'SHOPPING_LIST_ITEMS_UPDATE', 'SHOPPINGLIST_ITEMS_ADD'),
		(56, 'SHOPPING_LIST_ITEMS_DELETE', 'SHOPPINGLIST_ITEMS_DELETE'),
		-- Recipes and meal plan
		(57, 'RECIPES_CREATE', 'RECIPES'),
		(58, 'RECIPES_READ', 'RECIPES_VIEW'),
		(59, 'RECIPES_UPDATE', 'RECIPES'),
		(60, 'RECIPES_DELETE', 'RECIPES'),
		(61, 'RECIPE_INGREDIENTS_CREATE', 'RECIPES'),
		(62, 'RECIPE_INGREDIENTS_READ', 'RECIPES_VIEW'),
		(63, 'RECIPE_INGREDIENTS_UPDATE', 'RECIPES'),
		(64, 'RECIPE_INGREDIENTS_DELETE', 'RECIPES'),
		(65, 'RECIPE_NESTINGS_CREATE', 'RECIPES'),
		(66, 'RECIPE_NESTINGS_READ', 'RECIPES_VIEW'),
		(67, 'RECIPE_NESTINGS_UPDATE', 'RECIPES'),
		(68, 'RECIPE_NESTINGS_DELETE', 'RECIPES'),
		(69, 'MEAL_PLAN_ENTRIES_CREATE', 'RECIPES_MEALPLAN'),
		(70, 'MEAL_PLAN_ENTRIES_READ', 'MEALPLAN_VIEW'),
		(71, 'MEAL_PLAN_ENTRIES_UPDATE', 'RECIPES_MEALPLAN'),
		(72, 'MEAL_PLAN_ENTRIES_DELETE', 'RECIPES_MEALPLAN'),
		(73, 'MEAL_PLAN_SECTIONS_CREATE', 'MASTER_DATA_EDIT'),
		(74, 'MEAL_PLAN_SECTIONS_READ', 'MEALPLAN_VIEW'),
		(75, 'MEAL_PLAN_SECTIONS_UPDATE', 'MASTER_DATA_EDIT'),
		(76, 'MEAL_PLAN_SECTIONS_DELETE', 'MASTER_DATA_EDIT'),
		-- Chores, tasks, batteries and equipment
		(77, 'CHORES_CREATE', 'CHORES'),
		(78, 'CHORES_READ', 'CHORES_VIEW'),
		(79, 'CHORES_UPDATE', 'CHORES'),
		(80, 'CHORES_DELETE', 'MASTER_DATA_EDIT'),
		(81, 'CHORE_LOG_READ', 'CHORES_VIEW'),
		(82, 'TASKS_CREATE', 'TASKS'),
		(83, 'TASKS_READ', 'TASKS_VIEW'),
		(84, 'TASKS_UPDATE', 'TASKS'),
		(85, 'TASKS_DELETE', 'TASKS'),
		(86, 'TASK_CATEGORIES_CREATE', 'MASTER_DATA_EDIT'),
		(87, 'TASK_CATEGORIES_READ', 'TASKS_VIEW'),
		(88, 'TASK_CATEGORIES_UPDATE', 'MASTER_DATA_EDIT'),
		(89, 'TASK_CATEGORIES_DELETE', 'MASTER_DATA_EDIT'),
		(90, 'BATTERIES_CREATE', 'MASTER_DATA_EDIT'),
		(91, 'BATTERIES_READ', 'BATTERIES_VIEW'),
		(92, 'BATTERIES_UPDATE', 'MASTER_DATA_EDIT'),
		(93, 'BATTERIES_DELETE', 'MASTER_DATA_EDIT'),
		(94, 'BATTERY_CHARGE_CYCLES_READ', 'BATTERIES_VIEW'),
		(95, 'EQUIPMENT_CREATE', 'EQUIPMENT'),
		(96, 'EQUIPMENT_READ', 'EQUIPMENT_VIEW'),
		(97, 'EQUIPMENT_UPDATE', 'EQUIPMENT'),
		(98, 'EQUIPMENT_DELETE', 'EQUIPMENT'),
		-- User fields, custom entities and files
		(99, 'USERFIELDS_CREATE', 'ADMIN'),
		(100, 'USERFIELDS_READ', 'ADMIN'),
		(101, 'USERFIELDS_UPDATE', 'ADMIN'),
		(102, 'USERFIELDS_DELETE', 'ADMIN'),
		(103, 'USERENTITIES_CREATE', 'ADMIN'),
		(104, 'USERENTITIES_READ', 'ADMIN'),
		(105, 'USERENTITIES_UPDATE', 'ADMIN'),
		(106, 'USERENTITIES_DELETE', 'ADMIN'),
		(107, 'USEROBJECTS_CREATE', 'MASTER_DATA_EDIT'),
		(108, 'USEROBJECTS_READ', 'MASTER_DATA_EDIT'),
		(109, 'USEROBJECTS_UPDATE', 'MASTER_DATA_EDIT'),
		(110, 'USEROBJECTS_DELETE', 'MASTER_DATA_EDIT'),
		(111, 'USERFIELD_VALUES_UPDATE', 'MASTER_DATA_EDIT'),
		(112, 'PRODUCT_PICTURES_CREATE', 'MASTER_DATA_EDIT'),
		(113, 'PRODUCT_PICTURES_READ', 'STOCK_VIEW'),
		(114, 'PRODUCT_PICTURES_DELETE', 'MASTER_DATA_EDIT'),
		(115, 'RECIPE_PICTURES_CREATE', 'RECIPES'),
		(116, 'RECIPE_PICTURES_READ', 'RECIPES_VIEW'),
		(117, 'RECIPE_PICTURES_DELETE', 'RECIPES'),
		(118, 'EQUIPMENT_MANUALS_CREATE', 'EQUIPMENT'),
		(119, 'EQUIPMENT_MANUALS_READ', 'EQUIPMENT_VIEW'),
		(120, 'EQUIPMENT_MANUALS_DELETE', 'EQUIPMENT'),
		(121, 'USERFILES_CREATE', 'MASTER_DATA_EDIT'),
		(122, 'USERFILES_DELETE', 'MASTER_DATA_EDIT'),
		(123, 'USER_PICTURES_CREATE', 'USERS_EDIT'),
		(124, 'USER_PICTURES_READ', 'USERS_READ'),
		(125, 'USER_PICTURES_DELETE', 'USERS_EDIT'),
		-- Label subsystem
		(126, 'LABEL_PRINTERS_CREATE', 'LABELS_ADMIN'),
		(127, 'LABEL_PRINTERS_READ', 'LABELS_ADMIN'),
		(128, 'LABEL_PRINTERS_UPDATE', 'LABELS_ADMIN'),
		(129, 'LABEL_PRINTERS_DELETE', 'LABELS_ADMIN'),
		(130, 'LABEL_WORKERS_CREATE', 'LABELS_ADMIN'),
		(131, 'LABEL_WORKERS_READ', 'LABELS_ADMIN'),
		(132, 'LABEL_WORKERS_UPDATE', 'LABELS_ADMIN'),
		(133, 'LABEL_DRIVERS_READ', 'LABELS_ADMIN'),
		(134, 'LABEL_WORKER_CAPABILITIES_READ', 'LABELS_ADMIN'),
		(135, 'LABEL_PRINTER_STATUS_READ', 'LABELS_ADMIN'),
		(136, 'LABEL_TEMPLATES_CREATE', 'LABELS_ADMIN'),
		(137, 'LABEL_TEMPLATES_READ', 'LABELS_ADMIN'),
		(138, 'LABEL_TEMPLATES_UPDATE', 'LABELS_ADMIN'),
		(139, 'LABEL_TEMPLATE_VERSIONS_READ', 'LABELS_ADMIN'),
		(140, 'LABEL_ASSETS_CREATE', 'LABELS_ADMIN'),
		(141, 'LABEL_ASSETS_READ', 'LABELS_ADMIN'),
		(142, 'LABEL_MEDIA_PROFILES_READ', 'LABELS_ADMIN'),
		(143, 'LABEL_RENDER_REQUESTS_READ', 'LABELS_ADMIN'),
		(144, 'PRINT_JOBS_READ', 'LABELS_ADMIN'),
		(145, 'PRINT_ATTEMPTS_READ', 'LABELS_ADMIN'),
		(146, 'PRINT_EVIDENCE_READ', 'LABELS_ADMIN'),
		-- Users, roles, keys and settings
		(147, 'USER_ACCOUNTS_CREATE', 'USERS_CREATE'),
		(148, 'USER_ACCOUNTS_READ', 'USERS_READ'),
		(149, 'USER_ACCOUNTS_UPDATE', 'USERS_EDIT'),
		(150, 'USER_ACCOUNTS_DELETE', 'USERS_EDIT'),
		(151, 'USER_PERMISSIONS_READ', 'USERS_READ'),
		(152, 'USER_PERMISSIONS_UPDATE', 'USERS_EDIT'),
		(153, 'PERMISSIONS_READ', 'USERS_READ'),
		(154, 'ROLES_CREATE', 'USERS_EDIT'),
		(155, 'ROLES_READ', 'USERS_READ'),
		(156, 'ROLES_UPDATE', 'USERS_EDIT'),
		(157, 'ROLES_DELETE', 'USERS_EDIT'),
		(158, 'ROLE_PERMISSIONS_READ', 'USERS_READ'),
		(159, 'ROLE_PERMISSIONS_UPDATE', 'USERS_EDIT'),
		(160, 'USER_ROLES_READ', 'USERS_READ'),
		(161, 'USER_ROLES_UPDATE', 'USERS_EDIT'),
		(162, 'API_KEYS_READ', 'ADMIN'),
		(163, 'API_KEYS_DELETE', 'ADMIN'),
		-- Medication consumption
		(164, 'CONSUMPTION_PRESETS_CREATE', 'STOCK_CONSUME'),
		(165, 'CONSUMPTION_PRESETS_READ', 'STOCK_VIEW'),
		(166, 'CONSUMPTION_PRESETS_UPDATE', 'STOCK_VIEW'),
		(167, 'CONSUMPTION_PRESETS_DELETE', 'STOCK_VIEW'),
		(168, 'CONSUMPTION_SHARES_CREATE', 'STOCK_VIEW'),
		(169, 'CONSUMPTION_SHARES_READ', 'STOCK_VIEW'),
		(170, 'CONSUMPTION_SHARES_UPDATE', 'STOCK_VIEW'),
		(171, 'CONSUMPTION_SHARES_DELETE', 'STOCK_VIEW'),
		(172, 'CONSUMPTION_REFILLS_CREATE', 'STOCK_VIEW'),
		(173, 'CONSUMPTION_REFILLS_READ', 'STOCK_VIEW'),
		(174, 'CONSUMPTION_REFILLS_UPDATE', 'STOCK_VIEW'),
		(175, 'CONSUMPTION_EVENTS_READ', 'STOCK_VIEW'),
		(176, 'CONSUMPTION_EVENTS_UPDATE', 'STOCK_CONSUME'),
		(177, 'CONSUMPTION_EVENTS_DELETE', 'STOCK_CONSUME'),
		(178, 'CONSUMPTION_MAPPINGS_READ', 'STOCK_VIEW'),
		(179, 'CONSUMPTION_MAPPINGS_UPDATE', 'STOCK_CONSUME'),
		(180, 'CONSUMPTION_MAPPINGS_DELETE', 'STOCK_CONSUME'),
		-- Calendar and system routes
		(181, 'SYSTEM_INFO_READ', 'ADMIN'),
		-- Domain actions
		(182, 'STOCK_BOOKINGS_UNDO', 'STOCK_EDIT'),
		(183, 'PRODUCTS_MERGE', 'MASTER_DATA_EDIT'),
		(184, 'SHOPPING_LISTS_PRINT', 'SHOPPINGLIST'),
		(185, 'CHORES_ASSIGN', 'CHORES'),
		(186, 'CHORES_MERGE', 'MASTER_DATA_EDIT'),
		(187, 'LABELS_PRINT', 'MASTER_DATA_EDIT'),
		(188, 'LABEL_JOBS_REPRINT', 'MASTER_DATA_EDIT'),
		(189, 'LABEL_JOBS_CANCEL', 'MASTER_DATA_EDIT'),
		(190, 'LABEL_ARTIFACTS_PROMOTE', 'MASTER_DATA_EDIT')
	) AS v(ordinal, name, parent)
$fn$;

-- The permission that gated each new leaf's operation before the leaf existed, where it is not
-- the leaf's nearest pre-existing ancestor. JSON null means no permission: every
-- authenticated caller could perform the operation.
CREATE OR REPLACE FUNCTION permission_catalog_gates()
RETURNS JSONB
LANGUAGE sql IMMUTABLE AS $fn$
	SELECT '{
		"TASKS_DELETE": "MASTER_DATA_EDIT",
		"USERFIELDS_READ": null,
		"USERENTITIES_READ": null,
		"SYSTEM_INFO_READ": null
	}'::JSONB
$fn$;

-- Plan 42's intentional-change list: each entry is a leaf and the direction it may change in.
CREATE OR REPLACE FUNCTION permission_catalog_intentional_changes()
RETURNS JSONB
LANGUAGE sql IMMUTABLE AS $fn$
	SELECT '[
		{"permission": "TASKS_DELETE", "change": "gained", "reason": "plan 42 answer 1: task delete moves to TASKS"},
		{"permission": "TASKS_DELETE", "change": "lost", "reason": "plan 42 answer 1: task delete leaves MASTER_DATA_EDIT"},
		{"permission": "SYSTEM_INFO_READ", "change": "lost", "reason": "plan 42 answer 3: system info environment fields need ADMIN"}
	]'::JSONB
$fn$;

-- Every principal's resolved permission set. A principal with no permission still has one
-- row, with permission_name NULL, so a principal is never missing from a comparison.
-- Users read user_permissions_resolved, the view every permission check reads. A role is
-- its grants expanded through permission_tree. An API key carries its owner's set.
CREATE OR REPLACE FUNCTION permission_principal_sets()
RETURNS TABLE (principal_kind TEXT, principal_id INTEGER, principal_label TEXT, permission_name TEXT)
LANGUAGE sql STABLE AS $fn$
	SELECT 'user', u.id, u.username, upr.permission_name
	FROM users u LEFT JOIN user_permissions_resolved upr ON upr.user_id = u.id
	UNION ALL
	SELECT 'role', r.id, r.code, rs.name
	FROM roles r
	LEFT JOIN (
		SELECT DISTINCT rp.role_id, pt.name
		FROM role_permissions rp JOIN permission_tree pt ON pt.id = rp.permission_id
	) rs ON rs.role_id = r.id
	UNION ALL
	SELECT 'api_key', k.id, 'key ' || k.id || ' (' || k.key_type || ') of ' || COALESCE(u.username, 'missing user ' || k.user_id), upr.permission_name
	FROM api_keys k
	LEFT JOIN users u ON u.id = k.user_id
	LEFT JOIN user_permissions_resolved upr ON upr.user_id = k.user_id
$fn$;

-- Records the "before" side and returns it as one JSONB value: the principals, what each
-- holds, and the names the tree has. kinds limits the principals recorded; the importer
-- passes users and keys only, because the roles a legacy import leaves behind are the
-- target's, emptied by the import, and not the source's.
--
-- No temporary table holds the snapshot. deploy/postgres/roles.sql revokes ALL on the
-- database from PUBLIC, TEMPORARY included, so the roles migrations run as cannot create
-- one (the rule migration 0304 states). The caller keeps the value and hands it to
-- permission_upgrade_assert(); both run inside one transaction, so a refusal still rolls
-- back the whole change.
CREATE OR REPLACE FUNCTION permission_snapshot_record(kinds TEXT[] DEFAULT ARRAY['user', 'role', 'api_key'])
RETURNS JSONB
LANGUAGE sql STABLE AS $fn$
	WITH s AS (SELECT * FROM permission_principal_sets() WHERE principal_kind = ANY (kinds))
	SELECT jsonb_build_object(
		'principals', COALESCE((
			SELECT jsonb_agg(DISTINCT jsonb_build_object('kind', s.principal_kind, 'id', s.principal_id, 'label', s.principal_label))
			FROM s), '[]'::JSONB),
		'held', COALESCE((
			SELECT jsonb_agg(DISTINCT jsonb_build_object('kind', s.principal_kind, 'id', s.principal_id, 'name', s.permission_name))
			FROM s WHERE s.permission_name IS NOT NULL), '[]'::JSONB),
		'names', COALESCE((SELECT jsonb_agg(ph.name ORDER BY ph.name) FROM permission_hierarchy ph), '[]'::JSONB)
	)
$fn$;

-- Every difference between a recorded "before" side and the tree as it is now, per
-- operation. For a permission that existed before, "before" is whether the principal held it.
-- For a new one, it is whether the principal held its gate: walking up from the node itself,
-- the first node that existed before is the gate, unless a node on the way has an entry in
-- gates, which then decides (JSON null: every principal held it). A new node that reaches
-- neither was held by nobody.
-- Principals created after the record (a new role) are not compared.
CREATE OR REPLACE FUNCTION permission_upgrade_differences(snapshot JSONB, gates JSONB DEFAULT '{}'::JSONB)
RETURNS TABLE (principal_kind TEXT, principal_id INTEGER, principal_label TEXT, permission_name TEXT, change TEXT)
LANGUAGE plpgsql STABLE AS $fn$
BEGIN
	IF snapshot IS NULL OR NOT (snapshot ? 'principals' AND snapshot ? 'held' AND snapshot ? 'names') THEN
		RAISE EXCEPTION 'permission_upgrade_differences: no snapshot; pass the value permission_snapshot_record() returned'
			USING ERRCODE = 'VP057';
	END IF;

	RETURN QUERY
	WITH RECURSIVE
	snap_names AS (
		SELECT n.name FROM jsonb_array_elements_text(snapshot->'names') AS n(name)
	),
	snap_principals AS (
		SELECT p.kind, p.id, p.label FROM jsonb_to_recordset(snapshot->'principals') AS p(kind TEXT, id INTEGER, label TEXT)
	),
	snap_held AS (
		SELECT h.kind, h.id, h.name FROM jsonb_to_recordset(snapshot->'held') AS h(kind TEXT, id INTEGER, name TEXT)
	),
	new_names AS (
		SELECT ph.id, ph.name, ph.parent FROM permission_hierarchy ph
		WHERE ph.name NOT IN (SELECT n.name FROM snap_names n)
	),
	-- From each new node upward, starting at the node itself, until a node that either existed
	-- before or has a gate named for it: that node decides. A leaf under a new bundle with a
	-- named gate (PRODUCTS_READ under a STOCK_VIEW an old source lacks) takes the bundle's gate.
	walk AS (
		SELECT nn.name AS leaf, nn.id AS node_id, 0 AS depth FROM new_names nn
		UNION ALL
		SELECT w.leaf, ph.parent, w.depth + 1
		FROM walk w JOIN permission_hierarchy ph ON ph.id = w.node_id
		WHERE ph.parent IS NOT NULL
		AND ph.name NOT IN (SELECT n.name FROM snap_names n)
		AND NOT COALESCE(gates ? ph.name, FALSE)
		AND w.depth < 64
	),
	deciders AS (
		SELECT DISTINCT ON (w.leaf) w.leaf, ph.name AS decider
		FROM walk w JOIN permission_hierarchy ph ON ph.id = w.node_id
		WHERE ph.name IN (SELECT n.name FROM snap_names n) OR COALESCE(gates ? ph.name, FALSE)
		ORDER BY w.leaf, w.depth
	),
	gate_of AS (
		SELECT nn.name AS leaf,
			CASE WHEN COALESCE(gates ? d.decider, FALSE) THEN gates->>d.decider ELSE d.decider END AS gate,
			COALESCE(gates ? d.decider AND jsonb_typeof(gates->d.decider) = 'null', FALSE) AS everyone
		FROM new_names nn LEFT JOIN deciders d ON d.leaf = nn.name
	),
	before_has AS (
		SELECT h.kind, h.id, h.name FROM snap_held h
		UNION
		SELECT p.kind, p.id, g.leaf FROM snap_principals p CROSS JOIN gate_of g WHERE g.everyone
		UNION
		SELECT h.kind, h.id, g.leaf FROM gate_of g JOIN snap_held h ON h.name = g.gate WHERE NOT g.everyone
	),
	after_has AS (
		SELECT s.principal_kind AS kind, s.principal_id AS id, s.permission_name AS name
		FROM permission_principal_sets() s
		JOIN snap_principals p ON p.kind = s.principal_kind AND p.id = s.principal_id
		WHERE s.permission_name IS NOT NULL
	),
	changed AS (
		(SELECT a.kind, a.id, a.name, 'gained' AS change FROM after_has a EXCEPT SELECT b.kind, b.id, b.name, 'gained' FROM before_has b)
		UNION ALL
		(SELECT b.kind, b.id, b.name, 'lost' FROM before_has b EXCEPT SELECT a.kind, a.id, a.name, 'lost' FROM after_has a)
	)
	SELECT c.kind, c.id, p.label, c.name, c.change
	FROM changed c JOIN snap_principals p ON p.kind = c.kind AND p.id = c.id
	ORDER BY c.kind, c.id, c.name, c.change;
END
$fn$;

-- Raises SQLSTATE VP057 when a difference from snapshot is not on the intentional list, naming
-- every principal and leaf (the first 200 lines, then a count). Returns the number of
-- intentional differences, so a caller can report them.
CREATE OR REPLACE FUNCTION permission_upgrade_assert(snapshot JSONB, gates JSONB, intentional JSONB, context TEXT)
RETURNS INTEGER
LANGUAGE plpgsql STABLE AS $fn$
DECLARE
	unexpected_count INTEGER;
	intended_count INTEGER;
	lines TEXT[];
BEGIN
	SELECT COUNT(*) FILTER (WHERE NOT r.intended), COUNT(*) FILTER (WHERE r.intended),
		array_agg(format('%s %s (%s) %s %s', r.principal_kind, r.principal_id, r.principal_label, r.change, r.permission_name)
			ORDER BY r.principal_kind, r.principal_id, r.permission_name, r.change) FILTER (WHERE NOT r.intended)
	INTO unexpected_count, intended_count, lines
	FROM (
		SELECT d.*, EXISTS (
			SELECT 1 FROM jsonb_array_elements(COALESCE(intentional, '[]'::JSONB)) e
			WHERE e->>'permission' = d.permission_name AND e->>'change' = d.change
		) AS intended
		FROM permission_upgrade_differences(snapshot, gates) d
	) r;

	IF unexpected_count > 0 THEN
		-- The report is in the message, not only in DETAIL, because PDO's exception message
		-- carries the primary message alone and bin/victual-migrate prints that.
		RAISE EXCEPTION E'%: % resolved permission change(s) are not on the intentional-change list, so nothing was changed:\n%', context, unexpected_count,
			array_to_string(lines[1:200], E'\n') || CASE WHEN unexpected_count > 200 THEN format(E'\n... and %s more', unexpected_count - 200) ELSE '' END
			USING ERRCODE = 'VP057';
	END IF;

	RETURN intended_count;
END
$fn$;

-- The seed. Idempotent, because DatabaseImporter calls it again after every legacy import,
-- when the frozen source has replaced permission_hierarchy and emptied role_permissions.
CREATE OR REPLACE FUNCTION permission_catalog_seed()
RETURNS VOID
LANGUAGE plpgsql AS $fn$
DECLARE
	problem TEXT;
BEGIN
	-- LABELS_ADMIN first (ordinal 1), because it is the one new parent; every other parent exists.
	INSERT INTO permission_hierarchy (name, parent)
	SELECT c.name, p.id
	FROM permission_catalog_leaves() c JOIN permission_hierarchy p ON p.name = c.parent
	WHERE c.ordinal = 1 AND NOT EXISTS (SELECT 1 FROM permission_hierarchy e WHERE e.name = c.name);
	INSERT INTO permission_hierarchy (name, parent)
	SELECT c.name, p.id
	FROM permission_catalog_leaves() c JOIN permission_hierarchy p ON p.name = c.parent
	WHERE c.ordinal > 1 AND NOT EXISTS (SELECT 1 FROM permission_hierarchy e WHERE e.name = c.name)
	ORDER BY c.ordinal;

	-- Every leaf must now sit exactly where the catalog puts it. A missing parent, or a row of
	-- the same name under another parent, is a tree this migration does not understand.
	SELECT string_agg(format('%s (expected under %s, found under %s)', c.name, c.parent, COALESCE(pp.name, 'nothing')), '; ' ORDER BY c.ordinal)
	INTO problem
	FROM permission_catalog_leaves() c
	LEFT JOIN permission_hierarchy ph ON ph.name = c.name
	LEFT JOIN permission_hierarchy pp ON pp.id = ph.parent
	WHERE pp.name IS DISTINCT FROM c.parent;
	IF problem IS NOT NULL THEN
		RAISE EXCEPTION 'permission_catalog_seed: the tree does not match the catalog: %', problem USING ERRCODE = 'VP057';
	END IF;

	-- The household manager (plan 42): the Adult role's grants plus MASTER_DATA_EDIT.
	INSERT INTO roles (code, name, description, builtin)
	VALUES ('HOUSEHOLD_MANAGER', 'Household manager', 'Runs the household''s data without administering accounts or infrastructure', 1)
	ON CONFLICT DO NOTHING;
	IF NOT EXISTS (SELECT 1 FROM roles WHERE code = 'HOUSEHOLD_MANAGER' AND builtin = 1) THEN
		RAISE NOTICE 'permission_catalog_seed: a role already uses the code HOUSEHOLD_MANAGER or the name "Household manager"; the built-in household manager role was not created';
	END IF;
	INSERT INTO role_permissions (role_id, permission_id)
	SELECT r.id, p.id FROM roles r CROSS JOIN permission_hierarchy p
	WHERE r.code = 'HOUSEHOLD_MANAGER' AND r.builtin = 1
	AND p.name IN ('STOCK', 'SHOPPINGLIST', 'RECIPES', 'RECIPES_MEALPLAN', 'CHORES', 'TASKS', 'BATTERIES', 'EQUIPMENT', 'CALENDAR', 'USERS_READ', 'USERS_EDIT_SELF', 'MASTER_DATA_EDIT')
	ON CONFLICT DO NOTHING;

	-- The open reads: every user and every role, the household manager included.
	INSERT INTO user_permissions (user_id, permission_id)
	SELECT u.id, p.id FROM users u CROSS JOIN permission_hierarchy p
	WHERE p.name IN ('USERFIELDS_READ', 'USERENTITIES_READ')
	AND NOT EXISTS (SELECT 1 FROM user_permissions up WHERE up.user_id = u.id AND up.permission_id = p.id);
	INSERT INTO role_permissions (role_id, permission_id)
	SELECT r.id, p.id FROM roles r CROSS JOIN permission_hierarchy p
	WHERE p.name IN ('USERFIELDS_READ', 'USERENTITIES_READ')
	ON CONFLICT DO NOTHING;
END
$fn$;

-- The upgrade itself: record, seed, compare. A refusal raises and the migration rolls back.
DO $do$
DECLARE
	before_upgrade JSONB := permission_snapshot_record();
BEGIN
	PERFORM permission_catalog_seed();
	PERFORM permission_upgrade_assert(before_upgrade, permission_catalog_gates(), permission_catalog_intentional_changes(), 'migration 0320');
END
$do$;
