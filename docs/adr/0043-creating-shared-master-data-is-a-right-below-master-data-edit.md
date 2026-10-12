# ADR-0043: Creating shared master data is a right of its own, placed below MASTER_DATA_EDIT

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The maintainer has not yet decided that the feature is needed or in
  which release it ships.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-10, against `master` at `0639a23d`. Design work only: it changes no
  code, reserves no migration number and amends no accepted record. Revised 2026-10-11
  against `master` at `d4960138` to add source evidence (section "Evidence added
  2026-10-11"); the decision is unchanged. The revision also adds open question 5, adds
  it to acceptance prerequisite 2, and adds a test to prerequisite 3. Revised 2026-10-12
  against `master` at `9bd1df91` to record the maintainer's release boundaries: the response
  under acceptance prerequisite 1 and under open questions 1, 2, 2a and 5, decision 2's
  lookup gate, and the consequences for conversion creation and issue 742.
- **Referenced by:** [issue 742](https://github.com/datagen24/victual/issues/742) and its
  evidence, [`.devtools/issue742/RESULTS.md`](../../.devtools/issue742/RESULTS.md).
- **Relationship:** compatible with accepted
  [ADR-0014](0014-administering-a-user-is-a-subset-question.md) and
  [ADR-0018](0018-role-grants-and-domain-reads.md); amends neither. Relies on
  [ADR-0006](0006-authenticated-issues-in-scope.md): a permission gap reachable by a signed-in
  household member is a finding.

## Context

Every write to shared master data needs `MASTER_DATA_EDIT`. `GenericEntityApiController`
gives shopping lists, recipes, the meal plan and equipment their own permissions and sends every
other entity through `MASTER_DATA_EDIT` for create, edit and delete alike
(`AddObject`, `EditObject`, `DeleteObject`). The right is one flat grant. A household that wants
a member to add a product, a location or a store has to give that member the right to edit and
delete every product, conversion, location, store, chore, task and battery as well.

Measured 2026-10-10 on `master` at `0639a23d`, through the whole middleware stack with the
probe in `.devtools/issue742/` (reproduce with `.devtools/issue742/run.sh`):

- The seeded Child and Adult roles get 403 `MASTER_DATA_EDIT` on creating a product, a
  conversion, a location, a quantity unit, a barcode, a store, a product group, a task, a chore
  and a battery. Adult holds `TASKS`, `CHORES` and `BATTERIES`, and those do not help.
- `ADMIN` is not needed. An account holding only `MASTER_DATA_EDIT` passed every one of those
  requests, and edit and delete of a product.
- `GET /api/stock/barcodes/external-lookup/{barcode}?add=true` creates a product and needs
  `STOCK_VIEW` and `MASTER_DATA_EDIT`.

Read from the source, not exercised:

- `MASTER_DATA_EDIT` has one parent, `ADMIN` (`services/Database/InitialDataSeeder.php`, line 71).
  `permission_tree` (`db/pgsql/baseline/03_views_group1.sql`, line 92) resolves a permission to
  itself and its descendants, so an account holding a permission holds every permission below
  it.
- `products` has no owner or creator column. No route can say whose row a product is.
- Creating a product fires `products_default_qu_conversions_INS`
  (`db/pgsql/baseline/06_triggers_a.sql`), which inserts a conversion of factor 1 for a
  purchase, consume or price unit that differs from the stock unit. A create therefore writes
  conversion rows as a side effect.
- A quantity unit conversion on an existing product changes the amount every later booking of
  that product uses, whoever created the product.

The decision is how to separate "add a row" from "change or remove what the household already
shares" without widening any role.

## Decision (proposed)

### 1. One new permission, a child of `MASTER_DATA_EDIT`

Add a permission to `permission_hierarchy` whose parent is `MASTER_DATA_EDIT`. This record
uses `MASTER_DATA_ADD`, selected by the maintainer on 2026-10-11. Because the tree
resolves downward, every account that holds `MASTER_DATA_EDIT` or `ADMIN` holds it from the
moment the row exists, with no backfill. No other account holds it until someone grants it.

### 2. It gates create only, on a stated set of entities

`POST /api/objects/{entity}` accepts either the new permission or `MASTER_DATA_EDIT` for these
entities: `products`, `product_barcodes`, `locations`, `quantity_units`, `product_groups`,
`shopping_locations`. Everything else keeps its current gate:

- Edit and delete of every entity stay with `MASTER_DATA_EDIT`.
- `quantity_unit_conversions` stays with `MASTER_DATA_EDIT` for create too. A conversion is
  not an addition to the household's reference data; it changes the arithmetic of a product
  that already exists, and there is no owner to scope it to. The maintainer confirmed on
  2026-10-12 that conversion creation stays with `MASTER_DATA_EDIT`. An account that holds
  `MASTER_DATA_ADD` and not `MASTER_DATA_EDIT` can therefore create a product and gets 403 on
  `POST /api/objects/quantity_unit_conversions`.
- Userfield values and definitions, label subsystem writes, and merges keep their gates.
- `GET /api/stock/barcodes/external-lookup/{barcode}` checks `STOCK_VIEW` alone for a lookup
  that creates nothing. With `add=true` it also requires `MASTER_DATA_ADD`, which every
  `MASTER_DATA_EDIT` and `ADMIN` holder resolves through the tree. The `MASTER_DATA_EDIT`
  check that today runs before `add` is read therefore moves after it. The route creates a
  product through the same service as the generic route. A newly created product may receive
  its fetched picture, but that operation must not overwrite an existing shared picture.
  The maintainer decided the lookup gate on 2026-10-12 (open question 5).

Chores and tasks do not use the new permission; see decision 7. Batteries and task categories
keep `MASTER_DATA_EDIT` for create, edit and delete in this release (open question 2).

### 3. No second write path, no new endpoint

Creation stays on the generic route, so `AddObject`'s product handling (the tare-enable
refusal, the parent stock-unit default, body sanitising, server-owned column stripping) applies
unchanged. Clients that can already call `POST /api/objects/{entity}` gain nothing to learn
except a new 403 name.

### 4. No role gains it by this record

The seeded Admin role holds `ADMIN`, so it resolves the permission through the tree. Adult,
Child and Guest do not get it. The maintainer confirmed explicit grants on 2026-10-11
(open question 1).
The existing role editor must expose the permission for an explicit role grant.

### 5. It cannot widen anyone

Granting it, directly or through a role, requires `USERS_EDIT` and `User::CheckMayGrant()`,
which compares the closure of the grant with what the granter holds (ADR-0014, ADR-0018). An
account that holds it resolves to a strict subset of `MASTER_DATA_EDIT`'s closure, so
`MayAdminister` treats it as administrable by any account holding it, and by every holder of
`MASTER_DATA_EDIT`. There is no deny grant, so removing a role cannot take it from an account
that holds it directly.

### 6. Creation is not ownership

The record adds no owner or creator column. A created row is shared and indistinguishable from
the rest, and its author gains no right to edit or delete it. Recipe ownership
([ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md)) grants no right
over a product either. An owner-scoped edit would need a column, a backfill that cannot name
the author of existing rows, and a migration. That design is not decided here.

On 2026-10-11, the maintainer identified ownership markers
as a direction for medication imports and finer permissions. No ownership-based right
is authorized by that direction alone. On 2026-10-12, the maintainer deferred ownership
columns and creator-specific rights (open question 2a).

### 7. Chores and tasks are created and edited under their own domain permissions

The maintainer decided on 2026-10-10 that an adult creates chores, a child is assigned and
completes chores but cannot create them, and tasks are for adults and are created by an adult.
`AddObject` and `EditObject` therefore check `CHORES` for the `chores` entity and `TASKS` for
the `tasks` entity, as they already do `SHOPPINGLIST_ITEMS_ADD` for shopping lists and
`EQUIPMENT` for equipment. No new permission is involved.

- The seeded Adult role holds `CHORES` and `TASKS`, so adults gain create and edit. The seeded
  Child role holds `CHORES_VIEW`, `CHORE_TRACK_EXECUTION`, `TASKS_VIEW` and
  `TASKS_MARK_COMPLETED`, none of which carries `CHORES` or `TASKS`, so children stay unable to
  create. This follows from the seeded roles in `db/pgsql/roles-seed.sql`; the probe did not
  exercise the changed gate.
- Edit is open to every holder of the same permission. The maintainer decided on 2026-10-10
  that adults edit the chores and tasks they create, and that any adult edits a chore or task
  assigned to a child. Neither `chores` nor `tasks` has a creator column, so the record
  implements both statements as one rule: an account holding `CHORES` edits any chore, and an
  account holding `TASKS` edits any task, whoever created it and whoever it is assigned to.
  Limiting edit to the creator would need a new column and could not attribute existing rows
  (decision 6); see open question 2a.
- Delete stays with `MASTER_DATA_EDIT` (and so `ADMIN`) for chores and tasks. Merging chores
  stays with `MASTER_DATA_EDIT`.
- The gate change is a behaviour change for every account that holds `CHORES` or `TASKS`,
  directly or through a role, and not only for Adult. That is the intent, but it is a widening
  of what those two permissions mean, so the accepting pull request states it and the upgrade
  rehearsal lists the accounts affected.

## Options considered

**A. Keep `MASTER_DATA_EDIT` as the only right and document setup.** No code, no migration, no
role change. It is the current state, and the manual page
[Roles and permissions](../manual/operator/roles-permissions.md) describes it. It leaves a
household with the choice of giving a member everything or nothing.

**B. This record.** One additive permission below `MASTER_DATA_EDIT`. One migration,
a gate change in `GenericEntityApiController` and `StockApiController`, documentation, tests.

**C. A purpose-built creation endpoint under `STOCK_CONSUME`.** Rejected. Child holds
`STOCK_CONSUME`, so every existing consumer would gain product creation on upgrade, which
ADR-0018 rules out, and the endpoint would be a second write path to `products`.

**D. A leaf below `STOCK`, `STOCK_CONSUME` or `STOCK_PURCHASE`.** Rejected for the same reason:
the tree would hand the permission to every existing holder of the parent on upgrade.

**E. One permission per entity.** Rejected for now. It gives the finest control and adds six
or more rows to the permission list, the role editor and the documentation for a distinction
no household has asked for. Open question 3 keeps it available.

**F. Owner-scoped edit and delete.** Rejected for now; see decision 6.

## Consequences

- Adults can create and edit chores and tasks; children cannot create either (decision 7).
- A household can let a member add a product, a location or a store without letting that member
  edit or delete anything. The member cannot fix a mistake in a row they added. A misspelled
  product name, or a duplicate, stays until a `MASTER_DATA_EDIT` holder changes it.
- A holder can fill shared lists with rows every account sees. The right is a grant
  to a person the household trusts to add rows, not a quota.
- Adding a product still writes factor-1 conversion rows through the trigger. A holder of this
  right cannot correct their factor, because conversion edits stay with `MASTER_DATA_EDIT`.
  A product created with a purchase, consume or price unit that differs from the stock unit
  therefore needs a `MASTER_DATA_EDIT` holder to set the factor. Creating a product whose
  units are all the same needs none.
- Conversion creation stays with `MASTER_DATA_EDIT`, so an account that holds only
  `MASTER_DATA_ADD` can create a product and cannot create a conversion. A medication
  consumption mapping that names a unit other than the stock unit needs a conversion with the
  real factor, or it answers 422 `invalid_mapping` (`.devtools/issue742/RESULTS.md`, the
  medication setup steps). Such a mapping therefore still needs a `MASTER_DATA_EDIT` holder.
  This record narrows the setup restriction reported in issue 742 and does not remove it.
- Clients must handle a 403 on `POST /api/objects/quantity_unit_conversions`. The native
  setup flow must handle conversion creation being unavailable to an add-only account
  (maintainer response, 2026-10-12).
- Migration compatibility: one PostgreSQL-only migration inserts the `permission_hierarchy`
  row. Installations upgrading keep every existing grant, and `MASTER_DATA_EDIT` and `ADMIN`
  holders resolve the new permission with no update to `user_permissions`. The migration
  number is claimed in `migrations/RESERVATIONS.md` when the work starts, not now. A fresh
  install and a legacy import need more than the migration; see "Permission rows on each
  install path" below.
- Also changed at implementation: the contract snapshots that list `permission_hierarchy`, the
  `permission-<NAME>` classes that hide "new" buttons in the views, the translations, the
  [permission reference](../manual/operator/roles-permissions.md#permission-reference), and the
  permission count in AGENTS.md (it says 37; `controllers/Users/User.php` already declares 40).
- Native clients gain create without a new route. A client still has to handle 403.
- The maintainer decided on 2026-10-12 that the feature ships in v0.5.0, with the boundaries
  recorded under acceptance prerequisite 1.

## Evidence added 2026-10-11

Read from source on `master` at `d4960138`; nothing here was exercised. The decision above is
unchanged.

### Permission rows on each install path

The `*_VIEW` leaves are not in `InitialDataSeeder::PERMISSION_HIERARCHY`
(`services/Database/InitialDataSeeder.php`, 41-72). That constant holds the 30 rows of
migration 0110 in that migration's order, so each permission keeps its id, and it ends at
`MASTER_DATA_EDIT`. The leaves come from later files:

- `db/pgsql/roles-seed.sql` creates `STOCK_VIEW`, `SHOPPINGLIST_VIEW`, `CHORES_VIEW`,
  `TASKS_VIEW`, `RECIPES_VIEW` and `MEALPLAN_VIEW` (lines 1-6) and the four built-in roles
  (lines 14-24).
  Migration 0266 runs it (`migrations/0266.pgsql.php`, line 10).
- `STOCK_PRICES_VIEW` comes from migration 0281 and from `db/pgsql/prices-seed.sql`.
- `BATTERIES_VIEW`, `CALENDAR_VIEW` and `EQUIPMENT_VIEW` come from migration 0299 alone.

The three install paths take these sources in different combinations:

| Path | How `permission_hierarchy` is filled |
|---|---|
| Fresh install | `DatabaseMigrationService::ApplyBaselineSchemaWhenNeeded` loads `db/pgsql/baseline/`, runs the seeder, and records migrations 1 to 255 as applied (lines 452-481; `BASELINE_MIGRATION_ID` at line 45). Migrations 256 onward then run, so 0266, 0281 and 0299 insert their rows. |
| Upgrade | The method returns at once when any migration is recorded (line 452). Only migrations the installation has not yet run are applied. |
| Legacy import | `bin/victual-db-import` migrates the schema without seeding (`bin/victual-db-import`, line 120), then the import truncates `permission_hierarchy` with its foreign-key dependants. `DatabaseImporter` replays `roles-seed.sql` and `prices-seed.sql` afterwards (lines 853 and 866) and nothing else. |

An implementation of the new right has to change two places so that all three paths agree:

1. A new PostgreSQL-only migration inserts the row with the existence guard the other
   migrations use. This is the only change that reaches both a fresh install, which runs
   migrations after 255, and an upgrade. Adding the row to `PERMISSION_HIERARCHY` would not
   reach an upgrade, and the constant's docblock ties its order to the ids that migration 0110
   gave (lines 31-33), so a fresh install would diverge from an upgraded one.
2. The same idempotent insert goes into a seed file the importer replays, as
   `prices-seed.sql` did for 0281 (its header explains why), or into a new file the importer
   also replays. Without it, a legacy import deletes the row. The leaves of migration 0299
   were in no file the importer replays. That gap was reproduced: the test commit `fcc49e2c`
   fails the import phase with 12 failures, and commit `489231d3` fixes it with
   `db/pgsql/view-leaves-seed.sql`, replayed after `prices-seed.sql` (both on branch
   `claude/opus5_import-restores-view-leaves-f3eb`, separate from this record). An
   implementation of the new right must likewise add its row to a seed the importer replays.

The upgrade rehearsal in the acceptance prerequisites then needs a counterpart for the other
two paths: a fresh install, an upgraded installation and an imported database resolve the
permission for the same accounts.

### Where external lookup stores a picture

`GET /api/stock/barcodes/external-lookup/{barcode}?add=true` can store a product picture.
`StockService::ExternalBarcodeLookup` (lines 1368-1553) fetches the plugin's `__image_url`
(1387-1481), names the file after the plugin's `__barcode` and the extension (1472) and, after the product commits,
calls `FileStorage::Write('productpictures', ...)` (1539). `Write` replaces a file of the same
name (`services/Storage/FileStorage.php`, 103-109).

Uploading a product picture through `PUT /api/files/productpictures/{fileName}` needs
`MASTER_DATA_EDIT` (`FilesApiController::GROUP_WRITE_PERMISSIONS`, line 43; checked at line
342) and uses `FileStorage::Create`, which refuses an existing name (line 348).

If the route accepts the new right as decision 2 states, an account that holds the new right
and not `MASTER_DATA_EDIT` can cause a `productpictures` file to be written, and replaced if
one of that name exists, which the upload route does not allow it to do. The caller does not
choose the bytes; they come from the configured lookup source, under the extension allow-list
and the outbound host policy. The same call is open to `MASTER_DATA_EDIT` holders today. Open
question 5 asks whether that is acceptable.

### Inventory and a separate finding

[`.devtools/issue742/RESULTS.md`](../../.devtools/issue742/RESULTS.md), section "Static
completion, 2026-10-11", lists the gate of each route the first probe could not resolve, the
minimum permissions to set up a medication today, and three findings.

Finding F1 there is separate from this record. `POST /api/stock/products/{keep}/merge/{remove}`
checks `STOCK_EDIT` and ends in `DELETE FROM products`, so an account that holds `STOCK`, such
as the seeded Adult role, can delete a product through merge. Decision 2 leaves merges on
their current gates, which keeps that behavior. This record does not decide whether merge
should move to `MASTER_DATA_EDIT`; that needs its own decision.

## Acceptance prerequisites

1. The maintainer decides the feature is needed and in which release it ships.

   > **Response:** datagen24, 2026-10-12: Needed. It ships in v0.5.0.

   > **Response:** datagen24, 2026-10-12: Ship it in v0.5.0, with these boundaries: Keep
   > explicit grants of MASTER_DATA_ADD; do not add it automatically to Adult. Preserve
   > MASTER_DATA_EDIT for conversion creation, existing shared-data edits and deletion. Allow
   > lookup without creation under STOCK_VIEW; creation additionally requires
   > MASTER_DATA_ADD or its parent permission. Keep batteries unchanged. Keep task-category
   > permissions unchanged for this release too. Preserve the already approved shared editing
   > of chores and tasks. Defer ownership columns and creator-specific rights. Ensure the
   > native setup flow handles conversion creation being unavailable to an add-only account.
   > ADR-0043 does not completely remove the setup restriction reported in #742.

   Each boundary is recorded under the matching open question below: explicit grants under
   question 1, batteries and task categories under question 2, shared editing and ownership
   under question 2a, and the lookup gate under question 5. Conversion creation and the
   native setup flow are in decision 2 and the Consequences.

2. Open questions 1 to 3 and 5 are answered.
3. Tests, each named in the accepting pull request:
   - an account holding only the new permission can create each entity in the set and gets 403
     on edit, delete, conversion create, and every entity outside the set;
   - a `MASTER_DATA_EDIT`-only account and an `ADMIN` account pass the same creates after the
     migration with no change to their grants;
   - `CheckMayGrant` refuses a grant of the permission by an account that does not hold it;
   - an upgrade rehearsal shows no existing account gains or loses an effective permission
     except by holding `MASTER_DATA_EDIT` or `ADMIN`;
   - a create-only external lookup can attach a new picture but cannot overwrite an
     existing shared picture, including when the fetched filename collides;
   - a fresh install and a legacy import hold the permission row with the same parent as an
     upgraded installation (see "Permission rows on each install path");
   - an account that holds `STOCK_VIEW` and neither `MASTER_DATA_ADD` nor `MASTER_DATA_EDIT`
     can call `GET /api/stock/barcodes/external-lookup/{barcode}` without `add` and gets a
     result, and gets 403 with `add=true`, which creates nothing;
   - product creation, through `POST /api/objects/products` and through
     `external-lookup?add=true`, requires `MASTER_DATA_ADD` or a permission above it, and an
     account that holds only `MASTER_DATA_ADD` gets 403 on
     `POST /api/objects/quantity_unit_conversions`.
4. The contract snapshots and `check-migrations.php` pass with the migration.

## Open questions

1. **Does the Adult role hold the permission?** If yes, every household that uses the seeded
   Adult role lets all adults add reference rows after upgrade, and the migration has to edit
   the existing role row because seeding does not. If no, adults get it only by explicit grant.
   *Lean: no.* Adding it to a seeded role is a separate decision from creating the permission.

   > **Response:** datagen24, 2026-10-11: Explicit grant. Do not add the permission to
   > the seeded Adult role automatically. Role creation and editing UI are required.

   Role creation and editing already exist in `UsersController::RoleEditForm`,
   `RolesApiController` and `views/roleform.blade.php`. The new permission must be
   available through that existing permission picker.

   > **Response:** datagen24, 2026-10-12: Keep explicit grants of MASTER_DATA_ADD; do not
   > add it automatically to Adult.

2. **Batteries and task categories.** Chores and tasks are decided in decision 7. Batteries
   and task categories still use `MASTER_DATA_EDIT`. The maintainer wants to revisit the battery
   module together with equipment, so the record leaves batteries alone. Task categories
   follow tasks if the maintainer wants adults to add one. *Lean: batteries unchanged; task
   categories under `TASKS`.*

   > **Response:** datagen24, 2026-10-12: Keep batteries unchanged. Keep task-category
   > permissions unchanged for this release too.

   The lean on task categories was not adopted. In v0.5.0, batteries and task categories
   keep `MASTER_DATA_EDIT` for create, edit and delete, and neither accepts `MASTER_DATA_ADD`.

2a. **Edit is not limited to the creator.** The maintainer said adults edit what they created
   and that any adult edits what is assigned to a child. The record applies "any adult edits
   any" (decision 7) because there is no creator column. Say if an adult must not edit a
   chore or task that another adult created and that is not assigned to a child; that needs a
   `created_by_user_id` column, no backfill is possible for existing rows, and it is a
   larger change than this record.

   > **Response:** datagen24, 2026-10-11: Use the shared edit rule for now. Ownership
   > markers are a direction to investigate for medication imports, where finer
   > permissions may be needed.

   This confirms shared editing of chores and tasks. It does not decide ownership
   columns or creator-edit rights for shared products.

   > **Response:** datagen24, 2026-10-12: Preserve the already approved shared editing of
   > chores and tasks. Defer ownership columns and creator-specific rights.

   Decision 7's shared edit rule stands for v0.5.0. Ownership columns and creator-specific
   rights are deferred; this record adds neither.
3. **One permission or one per entity?**

   > **Response:** datagen24, 2026-10-11: One `MASTER_DATA_ADD` permission for the
   > listed entity types. This does not create a permission per product or other row.

4. **Name.** `MASTER_DATA_CREATE` follows the existing `STOCK_*` suffix style. `MASTER_DATA_ADD`
   matches `SHOPPINGLIST_ITEMS_ADD`. *Lean: `MASTER_DATA_ADD`, to match the nearest sibling.*

   > **Response:** datagen24, 2026-10-11: Use `MASTER_DATA_ADD` as the permission name.

5. **Does `external-lookup?add=true` store a picture for a holder of the new right?** The
   route writes a `productpictures` file, and replaces one of the same name, while uploading
   to that group needs `MASTER_DATA_EDIT` (see "Where external lookup stores a picture").
   The options are to keep the picture step, to skip it for an account that lacks
   `MASTER_DATA_EDIT`, or to refuse `add=true` to such an account. The controller also checks
   `MASTER_DATA_EDIT` before it reads `add` (`StockApiController.php`, 853-859), so a lookup
   without `add` needs it today. If decision 2 means `add=true` only, the check moves after
   `add` is read; if it means the whole lookup route, it does not. The record does not
   decide either point.

   > **Response:** datagen24, 2026-10-11: Allow the newly created product to receive
   > its fetched picture. Prevent this operation from overwriting an existing shared
   > picture; it grants no general picture-editing right.

   The implementation must test that a name collision leaves the existing picture
   unchanged.

   > **Response:** datagen24, 2026-10-12: Allow lookup without creation under STOCK_VIEW;
   > creation additionally requires MASTER_DATA_ADD or its parent permission.

   This decides lookup access without `add=true`, which the 2026-10-11 response left open.
   A lookup without `add` needs `STOCK_VIEW` only. With `add=true`, the route also requires
   `MASTER_DATA_ADD`, which `MASTER_DATA_EDIT` and `ADMIN` holders resolve through the tree.
   The permission check therefore moves after the controller reads `add`
   (`controllers/Api/StockApiController.php`, where on `master` at `9bd1df91` both checks run
   at lines 872-873, before `add` is read at 878). Decision 2 states the resulting gate.
