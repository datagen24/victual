# ADR-0043: Creating shared master data is a right of its own, placed below MASTER_DATA_EDIT

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The maintainer has not yet decided that the feature is needed or in
  which release it ships.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-10, against `master` at `0639a23d`. Design work only: it changes no
  code, reserves no migration number and amends no accepted record.
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
uses the working name `MASTER_DATA_CREATE`; the name is an open question. Because the tree
resolves downward, every account that holds `MASTER_DATA_EDIT` or `ADMIN` holds it from the
moment the row exists, with no backfill. No other account holds it until someone grants it.

### 2. It gates create only, on a stated set of entities

`POST /api/objects/{entity}` accepts either the new permission or `MASTER_DATA_EDIT` for these
entities: `products`, `product_barcodes`, `locations`, `quantity_units`, `product_groups`,
`shopping_locations`. Everything else keeps its current gate:

- Edit and delete of every entity stay with `MASTER_DATA_EDIT`.
- `quantity_unit_conversions` stays with `MASTER_DATA_EDIT` for create too. A conversion is
  not an addition to the household's reference data; it changes the arithmetic of a product
  that already exists, and there is no owner to scope it to.
- Userfield values and definitions, label subsystem writes, and merges keep their gates.
- `external-lookup?add=true` accepts the new permission in place of `MASTER_DATA_EDIT`, in
  addition to its existing `STOCK_VIEW` check. It creates a product through the same service
  as the generic route.

Chores and tasks do not use the new permission; see decision 7. Batteries and task categories
are open question 2.

### 3. No second write path, no new endpoint

Creation stays on the generic route, so `AddObject`'s product handling (the tare-enable
refusal, the parent stock-unit default, body sanitising, server-owned column stripping) applies
unchanged. Clients that can already call `POST /api/objects/{entity}` gain nothing to learn
except a new 403 name.

### 4. No role gains it by this record

The seeded Admin role holds `ADMIN`, so it resolves the permission through the tree. Adult,
Child and Guest do not get it. Giving it to Adult is a separate edit of `roles-seed.sql` plus a
migration for installations that already seeded the role, and it is open question 1.

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
the author of existing rows, and a migration, and it is out of scope here.

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
- Migration compatibility: one PostgreSQL-only migration inserts the `permission_hierarchy`
  row. Installations upgrading keep every existing grant, and `MASTER_DATA_EDIT` and `ADMIN`
  holders resolve the new permission with no update to `user_permissions`. The migration
  number is claimed in `migrations/RESERVATIONS.md` when the work starts, not now.
- Also changed at implementation: the contract snapshots that list `permission_hierarchy`, the
  `permission-<NAME>` classes that hide "new" buttons in the views, the translations, the
  [permission reference](../manual/operator/roles-permissions.md#permission-reference), and the
  permission count in AGENTS.md (it says 37; `controllers/Users/User.php` already declares 40).
- Native clients gain create without a new route. A client still has to handle 403.
- Not decided here: when this ships. The record assigns no release.

## Acceptance prerequisites

1. The maintainer decides the feature is needed and in which release it ships.
2. Open questions 1 to 3 are answered.
3. Tests, each named in the accepting pull request:
   - an account holding only the new permission can create each entity in the set and gets 403
     on edit, delete, conversion create, and every entity outside the set;
   - a `MASTER_DATA_EDIT`-only account and an `ADMIN` account pass the same creates after the
     migration with no change to their grants;
   - `CheckMayGrant` refuses a grant of the permission by an account that does not hold it;
   - an upgrade rehearsal shows no existing account gains or loses an effective permission
     except by holding `MASTER_DATA_EDIT` or `ADMIN`.
4. The contract snapshots and `check-migrations.php` pass with the migration.

## Open questions

1. **Does the Adult role hold the permission?** If yes, every household that uses the seeded
   Adult role lets all adults add reference rows after upgrade, and the migration has to edit
   the existing role row because seeding does not. If no, adults get it only by explicit grant.
   *Lean: no.* Adding it to a seeded role is a separate decision from creating the permission.
2. **Batteries and task categories.** Chores and tasks are decided in decision 7. Batteries
   and task categories still use `MASTER_DATA_EDIT`. The maintainer wants to revisit the battery
   module together with equipment, so the record leaves batteries alone. Task categories
   follow tasks if the maintainer wants adults to add one. *Lean: batteries unchanged; task
   categories under `TASKS`.*
2a. **Edit is not limited to the creator.** The maintainer said adults edit what they created
   and that any adult edits what is assigned to a child. The record applies "any adult edits
   any" (decision 7) because there is no creator column. Say if an adult must not edit a
   chore or task that another adult created and that is not assigned to a child; that needs a
   `created_by_user_id` column, no backfill is possible for existing rows, and it is a
   larger change than this record.
3. **One permission or one per entity?** *Lean: one.*
4. **Name.** `MASTER_DATA_CREATE` follows the existing `STOCK_*` suffix style. `MASTER_DATA_ADD`
   matches `SHOPPINGLIST_ITEMS_ADD`. *Lean: `MASTER_DATA_ADD`, to match the nearest sibling.*
