# 42. The permission catalog and household roles

Write down every permission leaf the accepted
[ADR-0057](../adr/0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md)
introduces, its parent in today's tree, its read prerequisites and the operations each
resource does not support. Then define the household roles as exact leaf lists. This is the
input ADR-0057 acceptance prerequisites 2 and 3 ask for.

Status: accepted catalog, 2026-11-27. ADR-0057 was accepted on 2026-11-27 in
[PR 1146](https://github.com/datagen24/victual/pull/1146), which named this plan as the
evidence for prerequisites 2 and 3. This plan now records the accepted catalog and household
roles. The maintainer answered all six open questions on 2026-11-27, which approved the role
lists in [Household roles](#household-roles). This document is design input: it adds no
migration, seed or code change, and acceptance does not choose the release that implements
the catalog.

## Problem and outcome

Issue 1111 asks that every feature expose separate create, read, update and delete
permissions per resource, with domain actions mapped alongside. ADR-0057 fixes the shape:

- one catalog of leaves named `<RESOURCE>_CREATE`, `_READ`, `_UPDATE`, `_DELETE`, plus named
  action leaves (decision 1);
- each new leaf placed under the permission that gates the operation today, so the upgrade
  changes nobody's effective access (decision 2);
- a `*_READ` leaf for every readable resource, with read prerequisites stated per write leaf
  (decision 3).

The record does not list the leaves. This plan does. The outcome is a catalog an
implementation can seed in one migration, and role lists a maintainer can approve.

## Inputs

- **Inventory:** `.devtools/issue1111/INVENTORY.md`, written against `42883e0e`
  (2026-10-25). Refreshed for this plan on 2026-11-27 against `master` at `e013eade`, as
  [Inventory refresh](#inventory-refresh) records. Its row numbers are cited as `inv. N`.
- **Tree shape:** `permission_hierarchy` has one nullable `parent` per row
  (`db/pgsql/baseline/01_tables.sql`). Each leaf therefore has exactly one parent. An
  action that today needs two permissions checks two leaves; it does not get two parents.
- **Resolution:** holding a node holds every descendant (`user_permissions_resolved`).

## Inventory refresh

Run on 2026-11-27 at `e013eade`, from the repository root:

```bash
python3 .devtools/issue1111/extract-route-permissions.py > /tmp/routes-new.tsv
```

The output was compared with the committed `routes-raw.tsv` after removing line numbers. It
showed no difference: 270 routes with the same handlers and permission tokens. `git log
42883e0e..e013eade -- routes.php controllers services middleware` lists no commits, and
`controllers/Users/User.php` still declares 41 `PERMISSION_*` constants. The inventory's
facts are current; only its Method row's tree and date change, and the accepting pull
request refreshes them again against the commit it names, as prerequisite 1 requires.

## Naming rules

- A leaf is `<RESOURCE>_<OPERATION>`, with the resource in the plural and spelled out
  (`QUANTITY_UNIT_CONVERSIONS_UPDATE`, not an abbreviation).
- An existing permission keeps its name and its position. No existing name is reused for a
  different meaning, because names are stored in grants, roles and API responses.
- Where an existing leaf already is exactly one resource's operation, it is that leaf and no
  duplicate is added. `CALENDAR_VIEW` is the calendar's read; `STOCK_PRICES_VIEW` stays the
  price field gate.
- Existing permissions that today gate several resources become **bundles**: parents of the
  new leaves, assignable and shown as "all of these" (ADR-0057 decision 2).

## Catalog

Each table lists one resource per row. "Parent" is the node the leaf sits under, which is
the permission that gates the operation today. "n/a" means the operation is not supported
and gets no leaf. Read prerequisites are listed under each table.

### Stock master data

| Resource (inv.) | Create (parent) | Read (parent) | Update (parent) | Delete (parent) |
|---|---|---|---|---|
| Products (1) | `PRODUCTS_CREATE` (`MASTER_DATA_ADD`) | `PRODUCTS_READ` (`STOCK_VIEW`) | `PRODUCTS_UPDATE` (`MASTER_DATA_EDIT`) | `PRODUCTS_DELETE` (`MASTER_DATA_EDIT`) |
| Product barcodes (2) | `PRODUCT_BARCODES_CREATE` (`MASTER_DATA_ADD`) | `PRODUCT_BARCODES_READ` (`STOCK_VIEW`) | `PRODUCT_BARCODES_UPDATE` (`MASTER_DATA_EDIT`) | `PRODUCT_BARCODES_DELETE` (`MASTER_DATA_EDIT`) |
| Locations (3) | `LOCATIONS_CREATE` (`MASTER_DATA_ADD`) | `LOCATIONS_READ` (`STOCK_VIEW`) | `LOCATIONS_UPDATE` (`MASTER_DATA_EDIT`) | `LOCATIONS_DELETE` (`MASTER_DATA_EDIT`) |
| Product groups (4) | `PRODUCT_GROUPS_CREATE` (`MASTER_DATA_ADD`) | `PRODUCT_GROUPS_READ` (`STOCK_VIEW`) | `PRODUCT_GROUPS_UPDATE` (`MASTER_DATA_EDIT`) | `PRODUCT_GROUPS_DELETE` (`MASTER_DATA_EDIT`) |
| Quantity units (5) | `QUANTITY_UNITS_CREATE` (`MASTER_DATA_ADD`) | `QUANTITY_UNITS_READ` (`STOCK_VIEW`) | `QUANTITY_UNITS_UPDATE` (`MASTER_DATA_EDIT`) | `QUANTITY_UNITS_DELETE` (`MASTER_DATA_EDIT`) |
| Quantity unit conversions (6) | `QUANTITY_UNIT_CONVERSIONS_CREATE` (`MASTER_DATA_EDIT`) | `QUANTITY_UNIT_CONVERSIONS_READ` (`STOCK_VIEW`) | `QUANTITY_UNIT_CONVERSIONS_UPDATE` (`MASTER_DATA_EDIT`) | `QUANTITY_UNIT_CONVERSIONS_DELETE` (`MASTER_DATA_EDIT`) |
| Stores (7) | `STORES_CREATE` (`MASTER_DATA_ADD`) | `STORES_READ` (`STOCK_VIEW`) | `STORES_UPDATE` (`MASTER_DATA_EDIT`) | `STORES_DELETE` (`MASTER_DATA_EDIT`) |
| Storage classes (8) | `STORAGE_CLASSES_CREATE` (`MASTER_DATA_EDIT`) | `STORAGE_CLASSES_READ` (`STOCK_VIEW`) | `STORAGE_CLASSES_UPDATE` (`MASTER_DATA_EDIT`) | `STORAGE_CLASSES_DELETE` (`MASTER_DATA_EDIT`) |
| Product substitutions (9) | `PRODUCT_SUBSTITUTIONS_CREATE` (`MASTER_DATA_EDIT`) | `PRODUCT_SUBSTITUTIONS_READ` (`STOCK_VIEW`) | `PRODUCT_SUBSTITUTIONS_UPDATE` (`MASTER_DATA_EDIT`) | `PRODUCT_SUBSTITUTIONS_DELETE` (`MASTER_DATA_EDIT`) |
| Minimum stock per location (10) | `PRODUCT_MINIMUM_STOCK_CREATE` (`MASTER_DATA_EDIT`) | `PRODUCT_MINIMUM_STOCK_READ` (`STOCK_VIEW`) | `PRODUCT_MINIMUM_STOCK_UPDATE` (`MASTER_DATA_EDIT`) | `PRODUCT_MINIMUM_STOCK_DELETE` (`MASTER_DATA_EDIT`) |
| MQTT product entities (11) | `MQTT_PRODUCT_ENTITIES_CREATE` (`MASTER_DATA_EDIT`) | `MQTT_PRODUCT_ENTITIES_READ` (`STOCK_VIEW`) | `MQTT_PRODUCT_ENTITIES_UPDATE` (`MASTER_DATA_EDIT`) | `MQTT_PRODUCT_ENTITIES_DELETE` (`MASTER_DATA_EDIT`) |

Quantity unit conversions keep create under `MASTER_DATA_EDIT`, not `MASTER_DATA_ADD`, as the
Accepted ADR-0043 decision 2 records (inv. 5.6).

Read prerequisites, from the form templates' lookup data (`views/*form.blade.php`):

| Write leaf | Needs |
|---|---|
| `PRODUCTS_CREATE`, `PRODUCTS_UPDATE` | `LOCATIONS_READ`, `PRODUCT_GROUPS_READ`, `STORES_READ`, `QUANTITY_UNITS_READ`, `PRODUCTS_READ` (parent product), `PRODUCT_BARCODES_READ` |
| `PRODUCT_BARCODES_CREATE`, `_UPDATE` | `PRODUCTS_READ`, `QUANTITY_UNITS_READ`, `STORES_READ` |
| `LOCATIONS_CREATE`, `_UPDATE` | `QUANTITY_UNITS_READ` |
| `QUANTITY_UNITS_CREATE`, `_UPDATE` | `QUANTITY_UNITS_READ` |
| `QUANTITY_UNIT_CONVERSIONS_CREATE`, `_UPDATE` | `QUANTITY_UNITS_READ`, `PRODUCTS_READ` |
| Every other master-data write | its own resource's `_READ` |

Every form above also reads userfield definitions, which stay readable by every user
(open question 4).

### Stock

| Resource (inv.) | Create | Read (parent) | Update (parent) | Delete |
|---|---|---|---|---|
| Stock entries (12) | n/a: rows appear only through bookings | `STOCK_ENTRIES_READ` (`STOCK_VIEW`) | `STOCK_ENTRIES_UPDATE` (`STOCK_EDIT`) | n/a: rows leave through bookings |
| Stock journal (13) | n/a: written by bookings | `STOCK_JOURNAL_READ` (`STOCK_VIEW`) | n/a | n/a: history is never deleted; bookings are undone |

Price fields on either resource stay gated by `STOCK_PRICES_VIEW` through `FieldPolicy`.

### Shopping

| Resource (inv.) | Create (parent) | Read (parent) | Update (parent) | Delete (parent) |
|---|---|---|---|---|
| Shopping lists (14) | `SHOPPING_LISTS_CREATE` (`SHOPPINGLIST_ITEMS_ADD`) | `SHOPPING_LISTS_READ` (`SHOPPINGLIST_VIEW`) | `SHOPPING_LISTS_UPDATE` (`SHOPPINGLIST_ITEMS_ADD`) | `SHOPPING_LISTS_DELETE` (`SHOPPINGLIST_ITEMS_DELETE`) |
| Shopping list items (15) | `SHOPPING_LIST_ITEMS_CREATE` (`SHOPPINGLIST_ITEMS_ADD`) | `SHOPPING_LIST_ITEMS_READ` (`SHOPPINGLIST_VIEW`) | `SHOPPING_LIST_ITEMS_UPDATE` (`SHOPPINGLIST_ITEMS_ADD`) | `SHOPPING_LIST_ITEMS_DELETE` (`SHOPPINGLIST_ITEMS_DELETE`) |

The list header gets leaves of its own, but they sit under the same parents as items, so
nothing changes on upgrade (inv. 5.7). Shortfall columns still need `STOCK_VIEW`'s reads
(ADR-0050). `SHOPPING_LIST_ITEMS_CREATE` and `_UPDATE` need `PRODUCTS_READ`,
`QUANTITY_UNITS_READ` and `PRODUCT_BARCODES_READ`.

### Recipes and meal plan

| Resource (inv.) | Create (parent) | Read (parent) | Update (parent) | Delete (parent) |
|---|---|---|---|---|
| Recipes (16) | `RECIPES_CREATE` (`RECIPES`) | `RECIPES_READ` (`RECIPES_VIEW`) | `RECIPES_UPDATE` (`RECIPES`) | `RECIPES_DELETE` (`RECIPES`) |
| Recipe ingredients (17) | `RECIPE_INGREDIENTS_CREATE` (`RECIPES`) | `RECIPE_INGREDIENTS_READ` (`RECIPES_VIEW`) | `RECIPE_INGREDIENTS_UPDATE` (`RECIPES`) | `RECIPE_INGREDIENTS_DELETE` (`RECIPES`) |
| Recipe nestings (18) | `RECIPE_NESTINGS_CREATE` (`RECIPES`) | `RECIPE_NESTINGS_READ` (`RECIPES_VIEW`) | `RECIPE_NESTINGS_UPDATE` (`RECIPES`) | `RECIPE_NESTINGS_DELETE` (`RECIPES`) |
| Meal plan entries (19) | `MEAL_PLAN_ENTRIES_CREATE` (`RECIPES_MEALPLAN`) | `MEAL_PLAN_ENTRIES_READ` (`MEALPLAN_VIEW`) | `MEAL_PLAN_ENTRIES_UPDATE` (`RECIPES_MEALPLAN`) | `MEAL_PLAN_ENTRIES_DELETE` (`RECIPES_MEALPLAN`) |
| Meal plan sections (20) | `MEAL_PLAN_SECTIONS_CREATE` (`MASTER_DATA_EDIT`) | `MEAL_PLAN_SECTIONS_READ` (`MEALPLAN_VIEW`) | `MEAL_PLAN_SECTIONS_UPDATE` (`MASTER_DATA_EDIT`) | `MEAL_PLAN_SECTIONS_DELETE` (`MASTER_DATA_EDIT`) |

`RECIPES_READ` is a new leaf beside the existing `RECIPES_VIEW`, which becomes the bundle of
the three recipe reads. `MEALPLAN_VIEW` likewise becomes the bundle of the two meal plan
reads. Recipe writes need `PRODUCTS_READ`, `QUANTITY_UNITS_READ` and `RECIPES_READ`. Meal
plan entry writes need `RECIPES_READ`, `PRODUCTS_READ`, `QUANTITY_UNITS_READ` and
`MEAL_PLAN_SECTIONS_READ`.

### Chores, tasks, batteries and equipment

| Resource (inv.) | Create (parent) | Read (parent) | Update (parent) | Delete (parent) |
|---|---|---|---|---|
| Chores (21) | `CHORES_CREATE` (`CHORES`) | `CHORES_READ` (`CHORES_VIEW`) | `CHORES_UPDATE` (`CHORES`) | `CHORES_DELETE` (`MASTER_DATA_EDIT`) |
| Chore log (22) | n/a: written by tracking | `CHORE_LOG_READ` (`CHORES_VIEW`) | n/a | n/a: executions are undone |
| Tasks (23) | `TASKS_CREATE` (`TASKS`) | `TASKS_READ` (`TASKS_VIEW`) | `TASKS_UPDATE` (`TASKS`) | `TASKS_DELETE` (`TASKS`) |
| Task categories (24) | `TASK_CATEGORIES_CREATE` (`MASTER_DATA_EDIT`) | `TASK_CATEGORIES_READ` (`TASKS_VIEW`) | `TASK_CATEGORIES_UPDATE` (`MASTER_DATA_EDIT`) | `TASK_CATEGORIES_DELETE` (`MASTER_DATA_EDIT`) |
| Batteries (25) | `BATTERIES_CREATE` (`MASTER_DATA_EDIT`) | `BATTERIES_READ` (`BATTERIES_VIEW`) | `BATTERIES_UPDATE` (`MASTER_DATA_EDIT`) | `BATTERIES_DELETE` (`MASTER_DATA_EDIT`) |
| Battery charge cycles (26) | n/a: written by charging | `BATTERY_CHARGE_CYCLES_READ` (`BATTERIES_VIEW`) | n/a | n/a: charges are undone |
| Equipment (27) | `EQUIPMENT_CREATE` (`EQUIPMENT`) | `EQUIPMENT_READ` (`EQUIPMENT_VIEW`) | `EQUIPMENT_UPDATE` (`EQUIPMENT`) | `EQUIPMENT_DELETE` (`EQUIPMENT`) |

Task delete moves from `MASTER_DATA_EDIT`, today's gate (inv. 5.3), to `TASKS` (answer 1). That
is an intentional change: every holder of `TASKS` gains task delete. Chore delete stays under
`MASTER_DATA_EDIT`, because deleting a chore also deletes its execution history through the
`trg_cascade_chore_removal` trigger. A chore that should stop is disabled instead: `chores.active`
is a column the chore form already edits, so disabling is `CHORES_UPDATE` and keeps the history.
Chore writes need `PRODUCTS_READ` and `USER_ACCOUNTS_READ` (assignment);
task writes need `TASK_CATEGORIES_READ` and `USER_ACCOUNTS_READ`; equipment writes need
`EQUIPMENT_READ`.

### User fields, custom entities and files

| Resource (inv.) | Create (parent) | Read (parent) | Update (parent) | Delete (parent) |
|---|---|---|---|---|
| Userfield definitions (28) | `USERFIELDS_CREATE` (`ADMIN`) | `USERFIELDS_READ` (none; open question 4) | `USERFIELDS_UPDATE` (`ADMIN`) | `USERFIELDS_DELETE` (`ADMIN`) |
| Custom entity definitions (29) | `USERENTITIES_CREATE` (`ADMIN`) | `USERENTITIES_READ` (none; open question 4) | `USERENTITIES_UPDATE` (`ADMIN`) | `USERENTITIES_DELETE` (`ADMIN`) |
| Custom entity rows (30) | `USEROBJECTS_CREATE` (`MASTER_DATA_EDIT`) | `USEROBJECTS_READ` (`MASTER_DATA_EDIT`) | `USEROBJECTS_UPDATE` (`MASTER_DATA_EDIT`) | `USEROBJECTS_DELETE` (`MASTER_DATA_EDIT`) |
| Userfield values (31) | n/a: values are set, not created | the owning resource's `_READ` | `USERFIELD_VALUES_UPDATE` (`MASTER_DATA_EDIT`) | n/a |
| Product pictures (32) | `PRODUCT_PICTURES_CREATE` (`MASTER_DATA_EDIT`) | `PRODUCT_PICTURES_READ` (`STOCK_VIEW`) | n/a: replace is delete then upload | `PRODUCT_PICTURES_DELETE` (`MASTER_DATA_EDIT`) |
| Recipe pictures (33) | `RECIPE_PICTURES_CREATE` (`RECIPES`) | `RECIPE_PICTURES_READ` (`RECIPES_VIEW`) | n/a | `RECIPE_PICTURES_DELETE` (`RECIPES`) |
| Equipment manuals (34) | `EQUIPMENT_MANUALS_CREATE` (`EQUIPMENT`) | `EQUIPMENT_MANUALS_READ` (`EQUIPMENT_VIEW`) | n/a | `EQUIPMENT_MANUALS_DELETE` (`EQUIPMENT`) |
| Userfield files (35) | `USERFILES_CREATE` (`MASTER_DATA_EDIT`) | governed by `UserfileOwnerPolicy` (ADR-0048) | n/a | `USERFILES_DELETE` (`MASTER_DATA_EDIT`) |
| User pictures (36) | `USER_PICTURES_CREATE` (`USERS_EDIT`) | `USER_PICTURES_READ` (`USERS_READ`) | n/a | `USER_PICTURES_DELETE` (`USERS_EDIT`) |

- **Definition writes** need both `MASTER_DATA_EDIT` and `ADMIN` today. Because `ADMIN` holds
  everything, the leaf sits under `ADMIN` and nobody below it gains or loses anything.
- **Definition reads** are open to every authenticated caller today on purpose (ADR-0035
  amendment, issue 869). The leaves exist, the upgrade grants them to every user and role,
  and new roles carry them (open question 4).
- **A user's own picture** stays governed by `USERS_EDIT_SELF`, the self rule it has today;
  the leaves above are for other users' pictures.
- **Userfield files** keep ADR-0048's owner rule: a reader must be admitted by every record
  that references the file. No read leaf replaces it.

### Label subsystem

Label management, monitoring and the job tables are `ADMIN` today (inv. 3.7). A catalog with
a leaf per label table under `ADMIN` would add 21 leaves nobody below `ADMIN` can be given in
practice. This plan proposes one new bundle, `LABELS_ADMIN`, under `ADMIN`, holding them
(open question 2).

| Resource (inv.) | Create | Read | Update | Delete |
|---|---|---|---|---|
| Label printers (37) | `LABEL_PRINTERS_CREATE` | `LABEL_PRINTERS_READ` | `LABEL_PRINTERS_UPDATE` | `LABEL_PRINTERS_DELETE` |
| Label workers (38) | `LABEL_WORKERS_CREATE` | `LABEL_WORKERS_READ` | `LABEL_WORKERS_UPDATE` | n/a: credentials are revoked |
| Label drivers (39) | n/a | `LABEL_DRIVERS_READ` | n/a | n/a |
| Worker capabilities (40) | n/a | `LABEL_WORKER_CAPABILITIES_READ` | n/a | n/a |
| Printer status (41) | n/a: the worker writes it | `LABEL_PRINTER_STATUS_READ` | n/a | n/a |
| Label templates (42) | `LABEL_TEMPLATES_CREATE` | `LABEL_TEMPLATES_READ` | `LABEL_TEMPLATES_UPDATE` | n/a: archived |
| Template versions (43) | n/a: publishing creates one | `LABEL_TEMPLATE_VERSIONS_READ` | n/a | n/a |
| Label assets (44) | `LABEL_ASSETS_CREATE` | `LABEL_ASSETS_READ` | n/a | n/a |
| Media profiles (45) | n/a | `LABEL_MEDIA_PROFILES_READ` | n/a | n/a |
| Render requests (46) | n/a: created by preview and print | `LABEL_RENDER_REQUESTS_READ` | n/a | n/a |
| Print jobs (47) | n/a: created by the print actions | `PRINT_JOBS_READ` | n/a | n/a: cancel is an action |
| Print attempts (48) | n/a: the worker writes it | `PRINT_ATTEMPTS_READ` | n/a | n/a |
| Print evidence (49) | n/a: the worker writes it | `PRINT_EVIDENCE_READ` | n/a | n/a |

Every leaf in this table has the parent `LABELS_ADMIN`. Template and printer writes need
`LABEL_MEDIA_PROFILES_READ`, `LABEL_DRIVERS_READ` and `LABEL_ASSETS_READ`.

### Users, roles, keys and settings

`USERS_READ`, `USERS_EDIT` and `USERS_CREATE` already exist as a chain
(`USERS_CREATE` > `USERS_EDIT` > `USERS_READ`) and gate several resources each. Reusing
`USERS_CREATE` and `USERS_READ` as the user resource's leaves would keep their children, so
they could not be granted alone. The user resource's leaves are named `USER_ACCOUNTS_*`, and
the existing three become bundles.

| Resource (inv.) | Create (parent) | Read (parent) | Update (parent) | Delete (parent) |
|---|---|---|---|---|
| Users (50) | `USER_ACCOUNTS_CREATE` (`USERS_CREATE`) | `USER_ACCOUNTS_READ` (`USERS_READ`) | `USER_ACCOUNTS_UPDATE` (`USERS_EDIT`) | `USER_ACCOUNTS_DELETE` (`USERS_EDIT`) |
| Direct grants (51) | n/a: set by update | `USER_PERMISSIONS_READ` (`USERS_READ`) | `USER_PERMISSIONS_UPDATE` (`USERS_EDIT`) | n/a: removed by update |
| Permission tree (52) | n/a | `PERMISSIONS_READ` (`USERS_READ`) | n/a | n/a |
| Roles (53) | `ROLES_CREATE` (`USERS_EDIT`) | `ROLES_READ` (`USERS_READ`) | `ROLES_UPDATE` (`USERS_EDIT`) | `ROLES_DELETE` (`USERS_EDIT`) |
| Role grants (54) | n/a: set by update | `ROLE_PERMISSIONS_READ` (`USERS_READ`) | `ROLE_PERMISSIONS_UPDATE` (`USERS_EDIT`) | n/a |
| Role assignments (55) | n/a: set by update | `USER_ROLES_READ` (`USERS_READ`) | `USER_ROLES_UPDATE` (`USERS_EDIT`) | n/a |
| API keys (56) | own key: no leaf (self) | own keys: self; all keys: `API_KEYS_READ` (`ADMIN`) | n/a: rotation creates a successor | own key: self; any key: `API_KEYS_DELETE` (`ADMIN`) |
| User settings (57) | n/a | self only | self only | self only |

- Every write here keeps ADR-0014's checks on top of the leaf: `MayAdminister` on the target
  user and `CheckMayGrant`, `CheckMayAssign` or `CheckMayManage` on what the write would
  confer. A leaf decides who may ask; the subset rule decides whether this request may
  succeed.
- `USERS_EDIT_SELF` stays the self-service leaf for a user's own record and picture.
- Groups, user kinds, service accounts and service packages (ADR-0057 decisions 4 and 7)
  add leaves of their own: `GROUPS_CREATE`, `_READ`, `_UPDATE`, `_DELETE` and
  `GROUP_MEMBERS_UPDATE` under the same parents as roles, and `SERVICE_ACCOUNTS_MANAGE`
  under `ADMIN` as ADR-0057 answer 1 set. These resources do not exist yet, so the
  inventory has no rows for them; they are listed so the migration seeds them with the
  rest.

### Medication consumption

These features combine a global permission with per-record rights held in
`consumption_recipe_shares` (inv. 5.17). The leaves below are the global half; the record
rights (`read`, `consume`, `edit`, `undo`, `share`, owner) stay as they are and are not
catalog leaves.

| Resource (inv.) | Create (parent) | Read (parent) | Update (parent) | Delete (parent) |
|---|---|---|---|---|
| Consumption presets (58) | `CONSUMPTION_PRESETS_CREATE` (`STOCK_CONSUME`) | `CONSUMPTION_PRESETS_READ` (`STOCK_VIEW`) | `CONSUMPTION_PRESETS_UPDATE` (`STOCK_VIEW`) | `CONSUMPTION_PRESETS_DELETE` (`STOCK_VIEW`) |
| Preset shares (59) | `CONSUMPTION_SHARES_CREATE` (`STOCK_VIEW`) | `CONSUMPTION_SHARES_READ` (`STOCK_VIEW`) | `CONSUMPTION_SHARES_UPDATE` (`STOCK_VIEW`) | `CONSUMPTION_SHARES_DELETE` (`STOCK_VIEW`) |
| Refill ledger (60) | `CONSUMPTION_REFILLS_CREATE` (`STOCK_VIEW`) | `CONSUMPTION_REFILLS_READ` (`STOCK_VIEW`) | `CONSUMPTION_REFILLS_UPDATE` (`STOCK_VIEW`) | n/a: void and cancel are actions |
| Consumption events (61) | n/a: upsert | `CONSUMPTION_EVENTS_READ` (`STOCK_VIEW`) | `CONSUMPTION_EVENTS_UPDATE` (`STOCK_CONSUME`) | `CONSUMPTION_EVENTS_DELETE` (`STOCK_CONSUME`) |
| Consumption mappings (62) | n/a: upsert | `CONSUMPTION_MAPPINGS_READ` (`STOCK_VIEW`) | `CONSUMPTION_MAPPINGS_UPDATE` (`STOCK_CONSUME`) | `CONSUMPTION_MAPPINGS_DELETE` (`STOCK_CONSUME`) |

Update and delete of a preset sit under `STOCK_VIEW` because that is the only global gate
today; the record right `edit` or ownership is what actually limits them. Every write here
also needs `CONSUMPTION_PRESETS_READ`.

### Calendar and system routes

| Resource (inv.) | Read |
|---|---|
| Calendar (63) | `CALENDAR_VIEW`, unchanged, plus each event source's read leaf |
| System configuration, time, change time, translations, API description | no leaf: part of the self-service exemption (answer 3) |
| System information (`/api/system/info`) | Victual version, migration number and capability fields: no leaf. PHP, database engine and kernel fields: `SYSTEM_INFO_READ` (`ADMIN`), absent without it (answer 3) |

## Domain actions

Existing action leaves keep their names and meaning. New action leaves are added where an
action today has no leaf of its own.

| Action | Leaf | Parent | Also checks |
|---|---|---|---|
| Purchase | `STOCK_PURCHASE` (existing) | `STOCK` | `STOCK_ENTRIES_READ` to return the booking |
| Consume | `STOCK_CONSUME` (existing) | `STOCK` | `STOCK_ENTRIES_READ` |
| Open | `STOCK_OPEN` (existing) | `STOCK` | `STOCK_ENTRIES_READ` |
| Transfer | `STOCK_TRANSFER` (existing) | `STOCK` | `STOCK_ENTRIES_READ` |
| Inventory | `STOCK_INVENTORY` (existing) | `STOCK` | `STOCK_ENTRIES_READ` |
| Undo a booking or transaction | `STOCK_BOOKINGS_UNDO` (new) | `STOCK_EDIT` | none, as today |
| Merge products | `PRODUCTS_MERGE` (new) | `MASTER_DATA_EDIT` | `STOCK_ENTRIES_UPDATE`, because today's merge needs `STOCK_EDIT` as well (inv. 5.12) |
| External lookup that creates a product | the lookup reads `PRODUCTS_READ`; `add=true` checks `PRODUCTS_CREATE` | | |
| Add missing, overdue or expired products to a list | `SHOPPING_LIST_ITEMS_CREATE` | | `STOCK_ENTRIES_READ` for missing (ADR-0050) |
| Clear a list, remove a product | `SHOPPING_LIST_ITEMS_DELETE` | | |
| Print a shopping list | `SHOPPING_LISTS_PRINT` (new) | `SHOPPINGLIST` | `SHOPPING_LIST_ITEMS_READ` |
| Recipe: consume | `STOCK_CONSUME` | | `RECIPES_READ`; `STOCK_PURCHASE` when the recipe produces a product |
| Recipe: add missing to list | `SHOPPING_LIST_ITEMS_CREATE` | | `RECIPES_READ` |
| Chore: track | `CHORE_TRACK_EXECUTION` (existing) | `CHORES` | `STOCK_CONSUME` when the chore consumes |
| Chore: undo | `CHORE_UNDO_EXECUTION` (existing) | `CHORES` | `STOCK_BOOKINGS_UNDO` when it booked stock |
| Chore: recalculate assignments | `CHORES_ASSIGN` (new) | `CHORES` | |
| Merge chores | `CHORES_MERGE` (new) | `MASTER_DATA_EDIT` | |
| Task: complete, undo | `TASKS_MARK_COMPLETED`, `TASKS_UNDO_EXECUTION` (existing) | `TASKS` | |
| Battery: charge, undo | `BATTERIES_TRACK_CHARGE_CYCLE`, `BATTERIES_UNDO_CHARGE_CYCLE` (existing) | `BATTERIES` | `BATTERIES_READ` for the full row |
| Calendar sharing link | `CALENDAR_VIEW` | | unchanged |
| Print a label, revised print, print a subtree | `LABELS_PRINT` (new) | `MASTER_DATA_EDIT` | the kind's read leaf, and the capture's per-field checks |
| Reprint a job | `LABEL_JOBS_REPRINT` (new) | `MASTER_DATA_EDIT` | |
| Cancel a job | `LABEL_JOBS_CANCEL` (new) | `MASTER_DATA_EDIT` | |
| Promote an artifact | `LABEL_ARTIFACTS_PROMOTE` (new) | `MASTER_DATA_EDIT` | |
| Authorize an attempt, preview, pair a worker | `LABELS_ADMIN` leaves above | | |
| Consumption: consume, undo, share, refill actions | the existing global leaves | | the record right, unchanged |

Machine routes for the label worker and renderer, and the iCal secret, stay credential-typed
and outside the catalog (inv. 4.5); ADR-0057 decision 7's service packages cover them.

## What the upgrade does

- **Seeds** every new leaf and bundle above in one migration, under the parents given.
- **Grants the open reads.** `USERFIELDS_READ` and `USERENTITIES_READ` are granted to every
  existing user and every existing role, because today's code checks nothing on them
  (ADR-0057 decision 3).
- **Checks itself.** The migration compares every account's, role's and credential's
  resolved set before and after (ADR-0057 decision 2). With the placements above the only
  expected difference is the added leaves themselves: each account gains the new leaves under
  nodes it already holds. The intentional-change list has one entry, from answer 1: every
  account and role holding `TASKS`, directly or through a role, gains task delete. The seeded
  `ADULT` is the main case. Answer 3 adds a second: every account without `ADMIN` stops
  receiving the `php_version`, `sqlite_version`, `database_engine` and `os` fields of
  `/api/system/info`.
- **Partly supersedes ADR-0043 decision 7.** That decision, Accepted, keeps chore and task
  delete with `MASTER_DATA_EDIT`. ADR-0057 names the task half of that bullet in its own
  supersession; the chore half stands.
- **Moves code to leaves.** Every check named in the inventory is rewritten against the
  leaf, and a check against a bundle becomes a defect (ADR-0057 decision 2).

Counts, from the tables above on 2026-11-27: 180 new resource leaves (including
`SYSTEM_INFO_READ`), 9 new action leaves,
`LABELS_ADMIN`, and 6 group and service leaves. All 41 existing permissions remain; 23 of
them, besides `ADMIN`, gain children and act as bundles. The resource count is reproducible
with the command in [Verification](#verification).

## Household roles

Five roles. The three seeded today (`ADULT`, `CHILD`, `GUEST`, inv. 2.2) keep their codes and
their effective access; they are restated as leaves, and the restatement must match their
current closure exactly. `HOUSEHOLD_MANAGER` is new. Administrator is the `ADMIN` role.

Every role below also holds the self-service rights every user has: their own profile,
settings and API keys, `USERS_EDIT_SELF`, and the userfield and custom entity definition
reads.

### Administrator (`ADMIN`, existing)

Holds `ADMIN`, which is every leaf.

Workflows: everything, including users, roles, groups, service accounts, label printers and
workers, userfield and custom entity definitions, and break-glass recovery.

### Household manager (`HOUSEHOLD_MANAGER`, new)

Runs the household's data without administering accounts or infrastructure.

| Holds | Which means |
|---|---|
| `STOCK`, `SHOPPINGLIST`, `RECIPES`, `CHORES`, `TASKS`, `BATTERIES`, `EQUIPMENT`, `CALENDAR` | every booking, list, recipe, meal plan, chore, task, battery and equipment operation, with their reads |
| `MASTER_DATA_EDIT` | create, update and delete of all master data; merges; label printing, reprint and cancel |
| `USERS_READ` | can see users, roles and groups, to assign chores and tasks |

Not held: `USERS_CREATE`, `USERS_EDIT`, `LABELS_ADMIN`, userfield and custom entity definition
writes, all keys, service accounts.

Workflows: maintaining products, locations and stores after a shop; cleaning up duplicates
with merge; printing labels for new stock; deleting retired chores; planning meals.

### Adult (`ADULT`, existing)

Day-to-day use of every feature, without changing shared master data.

| Holds | Which means |
|---|---|
| `STOCK`, `SHOPPINGLIST`, `RECIPES`, `CHORES`, `TASKS`, `BATTERIES`, `EQUIPMENT`, `CALENDAR` | as for the household manager |
| `USERS_READ`, `USERS_EDIT_SELF` | as today |

Not held: `MASTER_DATA_EDIT`, `MASTER_DATA_ADD`, `USERS_CREATE`, `USERS_EDIT`, `ADMIN`.

This is today's closure (35 permissions) with one intentional change: an adult can now
delete tasks, as well as create and edit them (answer 1). An adult creates, edits and disables
chores but does not delete them, because a delete erases the chore's history. An adult still cannot
create a product, location or store (answer 5).

Workflows: buying and consuming, keeping the shopping list, cooking, tracking chores and
tasks, charging batteries.

### Child (`CHILD`, existing)

Uses food and does chores; sees no prices.

| Holds | Which means |
|---|---|
| `STOCK_VIEW` | every stock and master data read, no prices |
| `STOCK_CONSUME`, `STOCK_OPEN` | eat and open things |
| `SHOPPINGLIST_VIEW`, `SHOPPINGLIST_ITEMS_ADD` | read lists, add to them |
| `CHORES_VIEW`, `CHORE_TRACK_EXECUTION` | see and tick off chores |
| `TASKS_VIEW`, `TASKS_MARK_COMPLETED` | see and complete tasks |
| `RECIPES_VIEW`, `MEALPLAN_VIEW` | read recipes and the meal plan |
| `CALENDAR` | the calendar, filtered to what the other reads allow |
| `USERS_EDIT_SELF` | own profile |

This is today's closure (14 permissions) unchanged. Note that `SHOPPINGLIST_ITEMS_ADD` is a
parent in the new tree, so a child also holds `SHOPPING_LISTS_CREATE` and
`SHOPPING_LISTS_UPDATE`: a child can create and rename a list header today, through the same
check.

Workflows: taking a snack and marking it consumed, adding milk to the list, ticking off a
chore.

### Guest (`GUEST`, existing)

Looks, changes nothing.

| Holds | Which means |
|---|---|
| `STOCK_VIEW`, `RECIPES_VIEW`, `MEALPLAN_VIEW` | what is in the house, recipes and the meal plan |

This is today's closure (3 permissions) unchanged.

Workflows: a visitor checking whether there is milk, or what is for dinner.

### Custom roles

Any leaf or bundle above can be put in a custom role, under ADR-0014's subset rule. The role
editor shows each leaf's read prerequisites and offers to add them (ADR-0057 decision 3).

## Verification

- **Catalog completeness:** every inventory row 1–63 has a row in [Catalog](#catalog), and
  every supported (resource, operation) pair in inventory section 6 has a leaf or a stated
  reason. Count the new resource leaves with:

  ```bash
  sed -n '/^## Catalog/,/^## Domain actions/p' docs/plans/42-permission-catalog.md \
    | grep -oE '`[A-Z_]+_(CREATE|READ|UPDATE|DELETE)`' | sort -u | wc -l
  ```

  On 2026-11-27 it printed 185. Five of those names are not new resource leaves:
  `USERS_CREATE`, `USERS_READ` and `SHOPPINGLIST_ITEMS_DELETE` already exist, and
  `GROUPS_CREATE` and `GROUP_MEMBERS_UPDATE` are group leaves. That leaves 180.
- **No upgrade change:** in the implementing pull request, the migration's before-and-after
  comparison passes with an empty intentional-change list on the demo dataset and on a fresh
  install (ADR-0057 decision 2).
- **Role equality:** the seeded `ADULT`, `CHILD` and `GUEST` resolve to the same operations
  before and after, checked by that same comparison.
- **Allowed and refused:** a probe per role exercises one allowed and one refused request for
  each workflow above, on web, API, generic object routes, MCP tools and file routes.

## Open questions

1. **Should chore and task delete move under `CHORES` and `TASKS`?** Today an adult can
   create and edit a chore or task but not delete it, because delete falls to
   `MASTER_DATA_EDIT` (inv. 5.3). Moving `CHORES_DELETE` and `TASKS_DELETE` would give every
   adult delete. That is an intentional change and goes on the upgrade's list with the
   accounts it affects. Proposed: move them, because a person who can create a chore expects
   to delete it.

   > **Response:** datagen24, 2026-11-27: "lets move them, Adult should be able to delete
   > tasks, i am not sure of the reasoning for not giving them delete". Recorded: both move.
   > ADR-0043 decision 7 kept delete with `MASTER_DATA_EDIT` without a stated reason, so
   > ADR-0057 supersedes that bullet. A deleted chore takes its execution history with it.
   >
   > Follow-up, datagen24, 2026-11-27: "on CHORES_DELETE, well this creates a conundrum, i
   > chore has history. i think we need to be able to disable so the history does not go
   > away. Delete should remain with Master Data controls as it is rather distructive".
   > Recorded: `CHORES_DELETE` stays under `MASTER_DATA_EDIT`; `TASKS_DELETE` moves under
   > `TASKS`. Disabling already exists: `chores.active` is on the chore form, so an adult
   > disables a chore with `CHORES_UPDATE` and its history stays.
2. **Is `LABELS_ADMIN` the right container for the label management leaves?** It adds one
   bundle so that label administration can be delegated without `ADMIN`. The alternative is
   21 leaves directly under `ADMIN`. Proposed: `LABELS_ADMIN`.

   > **Response:** datagen24, 2026-11-27: use the bundle.
3. **Do the system routes need a read leaf?** `/api/system/info`, `/time`, `/db-changed-time`,
   `/config`, `/localization-strings` and `/api/openapi/specification` check no permission
   today (inv. 5.14). ADR-0057 decision 3 exempts only the caller's own profile, settings and
   capabilities. Proposed: treat them as part of the capabilities exemption, because the
   front end needs them before it knows what the user may do, and state that in ADR-0057.

   Read 2026-11-27 at `e013eade`, for the maintainer's question on the risks. All six need an
   authenticated caller: `BaseAuthMiddleware` exempts only the root page, login, pairing and
   the credential-typed label routes. What each returns:

   - `/api/system/config`: an allow-list (`SystemApiController::EXPOSED_SETTINGS`: mode,
     currency, energy unit, locale, calendar and meal plan week settings, entry page, base
     path and URL, URL rewriting), every feature flag, the effective upload limit and the
     country. The front end needs it on every page. Low risk.
   - `/api/system/time`, `/api/system/db-changed-time`: server time and the last-change
     timestamp. Low risk.
   - `/api/system/localization-strings`: the translation catalogue. No household data.
   - `/api/openapi/specification`: the API description, which is public in the repository.
   - `/api/system/info` (`ApplicationService::GetSystemInfo`): the Victual version and
     migration number, the PHP version, the database engine and version, and the full
     `php_uname()` (kernel name, release, build string and architecture). Under the
     exemption every account, including Guest and Child, and every restricted key or OAuth
     token could read the exact PHP, database and kernel versions. That is reconnaissance
     data for anyone holding any credential.
   - `POST /api/system/log-missing-localization` writes to the translation template only when
     `VICTUAL_MODE` is `dev`; in any other mode it does nothing.

   Revised proposal: exempt `config`, `time`, `db-changed-time`, `localization-strings`,
   `openapi/specification` and `log-missing-localization`. Give `/api/system/info` a leaf,
   `SYSTEM_INFO_READ` under `ADMIN`, for the PHP, database and kernel fields. The Victual version,
   migration number and capability fields stay readable by everyone, and the other fields are
   absent for callers without the leaf, as price fields are (ADR-0005's documented field
   redaction). That is a wire change for non-administrators and goes on the intentional-change
   list.

   > **Response:** datagen24, 2026-11-27: "Yes, gate the system info fields behind admin, that
   > is to much info to expose to an untrusted party". Recorded: the revised proposal stands.
   > `SYSTEM_INFO_READ` under `ADMIN` gates the PHP, database and kernel fields; the other
   > routes join the self-service exemption, which ADR-0057 decision 3 now lists.
4. **Do userfield and custom entity definitions stay readable by everyone?** The leaves exist
   and the upgrade grants them to everyone, which keeps today's behaviour. The alternative is
   to grant them only to roles whose forms show userfields, which is every role above.
   Proposed: grant them to every role and keep them in the self-service set.

   > **Response:** datagen24, 2026-11-27: no risk seen in this. Marking some fields as
   > sensitive, visible only to some roles or to the system, may need a backlog item later;
   > there is no explicit use case yet. Recorded: definitions stay readable by everyone, and
   > per-field sensitivity is a possible follow-up, unscheduled.
5. **Does the adult role stay without product creation?** Today an adult cannot add a new
   product, location or store, because `MASTER_DATA_ADD` is not in `ADULT` (inv. 2.2). Adding
   `MASTER_DATA_ADD` to `ADULT` would be an intentional change. Proposed: leave `ADULT` as it
   is and use `HOUSEHOLD_MANAGER` for those who maintain master data.

   > **Response:** datagen24, 2026-11-27: yes, that is what the household manager is for.
6. **Is the household manager the right new role?** It is today's adult plus
   `MASTER_DATA_EDIT`. Proposed as written; the maintainer may rename it or move label
   printing out of it.

   > **Response:** datagen24, 2026-11-27: the household manager works well. Recorded as
   > written, label printing included.

## Implementation

ADR-0057 is implemented in pieces, one pull request each, against
[issue 1111](https://github.com/datagen24/victual/issues/1111). Piece 1 changes the tree and
no code check. Pieces 3 to 9 move the checks the inventory lists, one area at a time. Once the
leaves exist, a check against a bundle is a defect, because it ignores a leaf granted alone
(ADR-0057 decision 2). Until its piece lands, an area's checks stay on today's bundles, so
existing accounts keep working.

### Piece 1: the seed and its self-check

Migration 0320 (`migrations/0320.pgsql.sql`) does the following:

- It seeds the 180 resource leaves, the 9 action leaves and `LABELS_ADMIN` under the parents
  in [Catalog](#catalog) and [Domain actions](#domain-actions).
- It grants `USERFIELDS_READ` and `USERENTITIES_READ` to every existing user and role.
- It seeds `HOUSEHOLD_MANAGER` with the Adult role's grants plus `MASTER_DATA_EDIT`.
- It runs ADR-0057 decision 2's comparison over every user, role and API key, in the
  migration's own transaction.

The comparison treats each new leaf as gated by its nearest pre-existing ancestor, or by the
gate `permission_catalog_gates()` names. Any difference that
`permission_catalog_intentional_changes()` does not list raises SQLSTATE VP057 and names each
principal and leaf. That list holds answer 1 and answer 3:

- `TASKS_DELETE` moves from `MASTER_DATA_EDIT` to `TASKS`, gained and lost.
- `SYSTEM_INFO_READ` is lost by every principal without `ADMIN`.

A fresh install runs the migration after `InitialDataSeeder` has created the bootstrap
administrator, so the comparison covers that account. `DatabaseImporter` records the
imported users' and keys' sets before it replays the seed files. It then calls
`permission_catalog_seed()` and runs the same assertion, in one transaction.

The group and service leaves (`GROUPS_*`, `GROUP_MEMBERS_UPDATE`, `SERVICE_ACCOUNTS_MANAGE`)
are not seeded in piece 1. They gate resources that do not exist yet, so they would be
grantable rows that control nothing. They arrive in pieces 11 and 12 and use the same
comparison functions.

### Later pieces

| Piece | Scope | Inventory | Depends on |
|---|---|---|---|
| 2 | A `PERMISSION_*` constant for each new leaf. A test that fails when a check names a bundle, with an allow-list of today's bundle checks that each later piece shrinks. A role's open reads granted to every new user at creation, so that piece 3's definition-read checks refuse nobody who could read before. | 2.1, 5 | 1 |
| 3 | The generic object routes (`GenericEntityApiController` and `GenericEntityController`): entity and operation to leaf. This includes the code half of change (a), where task delete checks `TASKS_DELETE`, and the definition reads, which check `USERFIELDS_READ` and `USERENTITIES_READ`. | 3.1–3.6, 5.3, 5.6, App. A | 2 |
| 4 | Stock and shopping API and pages: bookings check `STOCK_ENTRIES_READ`, undo checks `STOCK_BOOKINGS_UNDO`, merge checks `PRODUCTS_MERGE` and `STOCK_ENTRIES_UPDATE`, list print checks `SHOPPING_LISTS_PRINT`, and the external lookup checks `PRODUCTS_READ` and `PRODUCTS_CREATE`. | 3.2, 3.3, 4.1, 5.7, 5.12 | 2 |
| 5 | Recipes, meal plan, chores, tasks, batteries, equipment and calendar API and pages, with `CHORES_ASSIGN` and `CHORES_MERGE`. | 3.4, 3.5, 4.2 | 2 |
| 6 | File routes: product and recipe pictures, equipment manuals, user pictures (`FilesApiController`'s read map). `UserfileOwnerPolicy` stays the reader rule for userfield files (ADR-0048). | 3.6 rows 32–36 | 2 |
| 7 | Labels: the `LABELS_ADMIN` leaves, plus `LABELS_PRINT`, `LABEL_JOBS_REPRINT`, `LABEL_JOBS_CANCEL` and `LABEL_ARTIFACTS_PROMOTE`. Machine routes stay credential-typed (inv. 4.5). | 3.7, 4.4 | 2 |
| 8 | Users, roles, keys and medication consumption: the `USER_ACCOUNTS_*`, `ROLES_*`, `USER_ROLES_*` and `API_KEYS_*` leaves under ADR-0014's subset checks, and the consumption globals beside the record rights. | 3.8, 4.3, 4.4 | 2 |
| 9 | MCP tools: each tool's check moves to its leaf, through the same ceiling as user keys (ADR-0044). | 4.4, 4.5 | 3–8 |
| 10 | `/api/system/info` reads `SYSTEM_INFO_READ` in place of `ADMIN`. This applies once the change gating the environment fields behind `ADMIN` (answer 3's code half) has landed. | 5.14 | 2 |
| 11 | Groups (decision 4): tables, the group source in `user_permissions_resolved`, `GROUPS_*` and `GROUP_MEMBERS_UPDATE` seeded through the piece 1 comparison, and membership previews. | none yet | 2 |
| 12 | Service accounts and API role packages (decision 7): `SERVICE_ACCOUNTS_MANAGE`, immutable package versions, and the move of each special-purpose key, with the same comparison. | 4.5 | 11 |
| 13 | The permission UI: leaves grouped by feature and resource, plain-language labels in `localization/permissions.pot` for the 190 new names, bundles shown as "all of these", read prerequisites offered and never added silently (decision 3), and "which leaves, and why" (decision 6). | none | 2, 11 |
| 14 | The allowed-and-refused probe from [Verification](#verification), per role and workflow, on web, API, generic object routes, MCP and file routes. | all | 3–9 |

### Found while building piece 1

- Seven consumption writes sit under `STOCK_VIEW` (preset update and delete, the four share
  leaves except read, and refill create and update), because `STOCK_VIEW` is their only global
  gate today and the record rights do the limiting. Guest and Child therefore resolve them,
  which matches what those roles pass today. If a Guest should hold no write leaf at all, the
  fix is a different parent, which is an intentional change for the list.
- The permission editor lists every row of `permission_hierarchy`, so the 190 new names appear
  there after piece 1 under their constant names, untranslated, until piece 13 adds labels.
