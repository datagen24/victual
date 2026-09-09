# Execution plan: plan 08, deeply nested locations (issue 81)

This is the work breakdown for [docs/plans/08-nested-locations.md](../../docs/plans/08-nested-locations.md)
as scoped by [issue 81](https://github.com/datagen24/victual/issues/81). It assigns pieces to
subagents, fixes the shared contracts between them, and names the checks each piece has to
pass. It decides nothing the plan and the issue did not already decide; where execution
needs a decision that neither made, the item is listed under **Decisions the maintainer
owns** with a recommendation, and the pieces below proceed on that recommendation until
told otherwise.

It lives under `.agents/` rather than `docs/plans/` because
[docs/documentation.md](../../docs/documentation.md) keeps agent assignments and execution
checklists out of research plans. Delivery status stays where it always is: the plans
README table and plan 08's **Executed** section, both written by piece E.

## Inputs, in reading order

1. [AGENTS.md](../../AGENTS.md), [docs/constitution.md](../../docs/constitution.md).
2. [Plan 08](../../docs/plans/08-nested-locations.md) with its five answered questions, and
   issue 81's **Decided** list, which restates them. Restated here so no piece re-opens them:
   `UNIQUE(parent_location_id, name)` with `NULLS NOT DISTINCT` (Q1); deleting a location
   that has children is refused (Q2); `is_freezer` is literal, and the form defaults the
   checkbox from the parent when creating a child (Q3); filters roll up through the tree,
   the location content sheet does not (Q4); depth cap 6, shared with plan 07, and depth
   carries no meaning (Q5).
3. [ADR-0005](../../docs/adr/0005-wire-contract-is-the-invariant.md): additive only.
   [ADR-0008](../../docs/adr/0008-postgresql-only-runtime-engine.md): the migration is
   PostgreSQL-only, no `.sqlite.sql`. Two code facts that act like rules: `/objects/*`
   writes reach the row with no service in between, so the tree's invariants have to hold
   in the database; and `EntityReadPolicy::Check()` refuses any entity without a policy
   row, so a new read entity needs one (plan 19 piece 1). ADR-0009 and ADR-0018 say
   related things and are both Proposed, so neither is cited as authority here.
4. [migrations/RESERVATIONS.md](../../migrations/RESERVATIONS.md): the number is claimed
   there before the file is written, and the branch merges with no hole below it.
5. The precedent to copy: plan 03 landed as one pull request carrying
   `migrations/0268.pgsql.sql`, the read entity, the UI, a PostgreSQL-only suite phase
   (`.devtools/pgsql/group-min-stock-tests.php`) and a browser probe
   (`.devtools/frontend/group-min-stock.js`) wired into the `frontend-security` job. Its
   **Executed** section in [plan 03](../../docs/plans/03-category-min-stock.md) is the
   model for piece E.

## Decisions the maintainer owns

Each has a recommendation; the pieces assume it.

1. **Migration number: 0273, not 0276.** The reservations table's own rule, applied
   seven times so far, is that the number about to have a file behind it takes the lowest
   free slot and unwritten drafts move up. 0269–0272 are on disk (plans 25 and 27, merged
   2026-09-08). 0273 is plan 23's and 0274–0275 are plan 22's, both unscheduled drafts. So
   plan 08 takes 0273 and 23/22 move to 0274 and 0275–0276, keeping 23 before 22. Piece A
   makes that edit in the table, in plans 22 and 23's bodies, and in the plans README,
   exactly as plan 03's Executed section records doing. Nothing claimed and unwritten
   sits below 0273, so the branch passes `check-migrations.php` without the waiver.
   Re-check the table at every resync: this recommendation has already moved once, from
   0271, while the plan was being written.
2. **PostgreSQL minimum becomes 15.** `NULLS NOT DISTINCT` is PostgreSQL 15+.
   `db/pgsql/README.md` says "Target: PostgreSQL 13+ (tested on 17)"; CI runs `postgres:16`
   and `deploy/README.md` documents 16. Issue 81 already says the fork can require 15+.
   Piece A changes the README's target line and says why in the migration's comment.
   Nothing else in the tree pins 13.
3. **The depth cap is one SQL function, `hierarchy_depth_limit()`, returning 6.** Plan 08
   Q5 says "share one constant with 07". The enforcement has to be in the database because
   `/objects/locations` writes go through `GenericEntityApiController` straight to the row
   with no service in between, so a PHP constant would be a second copy the API never
   consults. Plan 07's product trigger uses the same function when it is written. No PHP
   mirror is added.
4. **Refusing a delete needs a PHP pre-check as well as the trigger.**
   `BaseApiController::GenericErrorResponse()` replaces any message beginning `SQLSTATE[`
   before it reaches a client, deliberately, so a trigger's `RAISE EXCEPTION` text is never
   the "clear message" Q2 asks for. Piece B answers 400 with a stable English message from
   `DeleteObject` when the entity is `locations` and children exist; the trigger stays as
   the backstop for every other write path.
5. **Option text is the path, not an indent.** Issue 81 allows either. The pickers are
   bootstrap-combobox typeaheads that match on option text; "Kitchen / Pantry / Top shelf"
   makes every level typeable and disambiguates two "Top shelf" rows, which indentation by
   non-breaking spaces does not. The locations list page shows both the name and the path.

## Contracts shared between pieces

These are fixed here so pieces B, C and D can be written against them before piece A merges.

**Column.** `locations.parent_location_id INTEGER NULL`. No foreign key, matching
`products.parent_product_id` and the rest of the schema.

**Constraint.** The baseline declares `name TEXT NOT NULL UNIQUE`, so the existing
constraint is `locations_name_key`; piece A confirms the name against `pg_constraint`
before dropping it. `locations` already carries `import_epoch` from
`migrations/0269.pgsql.sql` (plan 25) and a `retire_location_labels` `BEFORE DELETE`
trigger; the new column and triggers sit beside them. Replacement: `locations_parent_name_key UNIQUE NULLS NOT DISTINCT
(parent_location_id, name)`. Also an index on `parent_location_id` for the recursive view.

**View `locations_resolved`.** One row per (ancestor, descendant) pair including each
location paired with itself at depth 0:

| Column | Meaning |
|---|---|
| `id` | dummy, `1 AS id`, as every other resolved view does (db/pgsql/README hazard 10) |
| `ancestor_location_id` | |
| `descendant_location_id` | |
| `depth` | distance from ancestor to descendant; 0 on the self row |
| `path` | the descendant's display path from its root, names joined by ` / ` |

A location's level (distance from its root) is `MAX(depth)` grouped by descendant, so
no `level` column is added; piece C derives it in the query that feeds the pickers. The
recursive term carries an id path and stops at `hierarchy_depth_limit()`, so the view
terminates even on data the trigger never saw. Copy the string building from
`quantity_unit_conversions_resolved` (`db/pgsql/baseline/03_views_group2.sql`) and the
self-row shape from `recipes_nestings_resolved`. Inactive locations are included; the
pickers filter on `active` themselves as they do today.

**Triggers on `locations`.**

- `BEFORE INSERT OR UPDATE OF parent_location_id`: refuse `parent_location_id = id`;
  refuse a parent that is a descendant of the row (update only; a new row has no
  descendants); refuse when the parent's level plus one plus the height of the row's own
  subtree exceeds `hierarchy_depth_limit()`, so re-parenting a subtree under a deep node is
  caught, not just adding a leaf. Messages follow the recipe guard's style:
  `Recursive nested location detected`, `Location nesting depth limit exceeded`.
- `BEFORE DELETE`: refuse when any row has this id as parent: `Location has child locations`.
  It is a second `BEFORE DELETE` trigger on the table beside `retire_location_labels`;
  PostgreSQL fires them in name order and a raise in either aborts the statement, so the
  order does not matter, but name it to sort first anyway (`guard_location_children`).
  Deleting a childless location that holds stock is not changed by this work; today no
  trigger or foreign key stops it, and piece E records that as pre-existing behavior.

**API.** `locations` gains `parent_location_id` in the `Location` schema of
`victual.openapi.json`. `GenericEntityApiController::GetObject()` and `GetObjects()`
project an explicit column list for `locations` (added by plan 25 to keep `import_epoch`
off the wire), so the new column has to be added to both lists or it never appears on
`/objects/locations` at all. `locations_resolved` joins `ExposedEntity`, `ExposedEntityNoEdit`
and `ExposedEntityNoDelete` with a `LocationResolved` schema, and gets
`'locations_resolved' => User::PERMISSION_STOCK_VIEW` in `EntityReadPolicy::PERMISSIONS`,
which fails closed on an unmapped entity. No other response shape changes.

**Locations for rendering.** One method supplies every dropdown and list:
`StockService::GetLocationsWithPaths(bool $activeOnly)` returning the `locations` columns
plus `path` and `level`, in tree pre-order with siblings ordered case-insensitively by name.
Piece C owns it; every template that renders locations reads `->path` for display and keeps
`->id` as the value.

**Roll-up for filters.** The stock overview's hidden location cell currently holds
`xx{name}xx` per stocked location and the filter option's value is the name. Both move to
ids, and the cell also lists every ancestor of each stocked location, so selecting
"Basement" matches a product stocked at "Basement / StorageRoom / UprightFreezer / Door".
The stock entries page filter does the same through a `data-location-ancestors`
attribute. The location content sheet groups by exact `location_id` and is untouched
apart from showing the path as each section's heading (Q4).

**Fixture tree.** Every check uses the layout from plan 08 Q5, with `is_freezer = 1` on
both `UprightFreezer` and `Door`:

```
Basement / StorageRoom / Rack1          / Shelf3
Basement / StorageRoom / UprightFreezer / Door
Main     / Kitchen     / SinkLeftCab    / Shelf1
```

It cannot go into `.devtools/pgsql/fixtures/00_base.sql`, which the SQLite side of the
differential suite also loads and which has no `parent_location_id`; each phase builds it
itself, as `group-min-stock-tests.php` builds its groups.

## Pieces

Dependency order: A first. B, C and D1 in parallel once A's migration file exists on the
branch. D2 after C. E last. One feature branch, one pull request, following plan 03.

### A. Schema, view, triggers, reservations

Files: `migrations/0273.pgsql.sql` (new), `migrations/RESERVATIONS.md`, `db/pgsql/README.md`
(the target line), `docs/plans/22-medication-tracking.md` and
`docs/plans/23-storage-classes.md` (migration numbers only), `docs/plans/README.md` (the
same numbers in the status rows for 22, 23 and 08).

1. Claim 0273 for plan 08 in the reservations table, move 23 to 0274 and 22 to 0275–0276,
   and add a paragraph in the table's running history naming this as the eighth move and
   the rule that decided it. Run `php .devtools/pgsql/check-migrations.php` without
   `--allow-reserved-holes` and quote the result in the commit message.
2. Write the migration with a comment block of the kind 0267 and 0268 carry: why the
   constraint is spelled with `NULLS NOT DISTINCT` and what that does to the minimum
   version; why the view stops at the depth function; why the delete guard is on children
   only; why the baseline is not edited (0268's last paragraph applies verbatim); why no
   `ENGINE_EXCLUSIVE_TABLES` entry is needed (a column on a shared table and a new view are
   invisible to `migratedifftest.php`, as 0268 explains).
3. The function `hierarchy_depth_limit()` is created here, `IMMUTABLE`, with a comment
   saying plan 07 uses it next.
4. Prove it locally against `postgres:16`: apply the migration, insert the fixture tree,
   run the refusal cases in **D1** by hand, and paste the exact error messages into the
   commit message so piece B can match them.

Do not touch the baseline, `db/pgsql/README.md` beyond the target line, or any file piece B
or C owns.

### B. API surface

Files: `victual.openapi.json`, `controllers/Users/EntityReadPolicy.php`,
`controllers/Api/GenericEntityApiController.php`.

1. Add `parent_location_id` (integer, nullable) to the `Location` schema, and to the two
   explicit `select()` lists for `locations` in `GetObject()` and `GetObjects()`; leave
   `import_epoch` out of them. The schema is already missing `is_freezer` and `active`;
   leave that alone and note it for piece E, because fixing it is a contract change of
   its own.
2. Add `LocationResolved` with the five view columns, add `locations_resolved` to the three
   enums named above, and the read policy row. `GenericEntityApiController` reads the
   enums from the spec, so nothing else registers the entity.
3. In `DeleteObject`, before `$row->delete()`, when `$args['entity'] === 'locations'` and a
   row with that `parent_location_id` exists, return
   `GenericErrorResponse($response, 'Location has child locations', 400)`. Keep it to
   that one entity; the trigger covers everything else.
4. Regenerate nothing: the spec is hand-edited in this repository. Run
   `php .devtools/pgsql/check-runtime-sql.php` and the PHP syntax check the `lint` job runs.

### C. UI

Files: `services/StockService.php` (the new method), `controllers/StockController.php`,
`views/components/locationpicker.blade.php`, `views/consume.blade.php`,
`views/transfer.blade.php`, `views/productform.blade.php` (both location selects),
`views/stockoverview.blade.php`, `views/stockentries.blade.php`, `views/locations.blade.php`,
`views/locationform.blade.php`, `views/locationcontentsheet.blade.php`,
`public/viewjs/locationform.js`, `public/viewjs/stockoverview.js`,
`public/viewjs/stockentries.js`, `public/viewjs/components/locationpicker.js`,
`localization/strings.pot` for new strings.

1. `GetLocationsWithPaths()` as specified above, and every `$this->DB->locations()` call
   in `StockController` that feeds a template switches to it. Ordering is done in PHP
   after the query, since `COLLATE NOCASE` belongs in the PHP (README hazard 15) and a
   pre-order walk is simpler there than in SQL.
2. Every `<option>` for a location shows `path`. The shared partial gains
   `data-level` and `data-is-freezer` on each option; `transfer.blade.php` already carries
   `data-is-freezer` and keeps it.
3. Location form: a parent picker (a plain select, values are ids, text is the path,
   empty option for a root) that in edit mode omits the location itself and its
   descendants, computed from the resolved view server-side. In create mode, changing the
   parent sets the `is_freezer` checkbox from the chosen option's `data-is-freezer`; in
   edit mode it does not touch the checkbox. The form posts `parent_location_id` as a
   number or null through the existing `objects/locations` save path.
4. Locations list: a path column, and a parent column or the name indented by `data-level`.
   Deleting a parent shows the API's 400 message through the shared delete helper from
   plan 12; confirm that helper surfaces the message rather than a generic failure, and
   fix it there if it does not. The list and the form now also carry plan 25's label
   print action (`labelPrinters`, `Victual.LabelPrinting.Wire`, buttons keyed by
   `data-location-name`); keep those as they are, with the bare name, since what a label
   says is plan 06's question and not this one's.
5. Stock overview: filter option value becomes the id; the hidden cell lists
   `xx{id}xx` for each stocked location and each of its ancestors, built from the resolved
   view in `StockController::Overview()`. Update the comment in `stockoverview.js` that
   describes the cell. Stock entries: the same, through `data-location-ancestors`.
6. `locationpicker.js` prefill-by-name uses `:contains()` on option text, which now matches
   any path containing the string. No template passes `prefillByName` today (the
   stock entry form passes `prefillById`; purchase and inventory pass neither), so leave
   the code path and add a comment saying it matches paths and callers should prefer the id.
7. Every DOM string built in this piece follows AGENTS.md: `$(document).find()` for
   selectors from the DOM, nodes built with `.text()`, nothing concatenated into
   `.html()`. `.devtools/frontend/s29-payload.js` runs on the pull request and will catch
   a miss on the locations pages if piece D2 seeds a location whose name is a payload,
   which it does.

Keep the `stock_current_locations` and `stock_current_location_content` views out of this
piece; nothing here changes their meaning (issue 81, **Unchanged**). Before handing over,
run `.devtools/frontend/location-labels.js` and `location-print.js` locally: they drive
the locations pages and may match on the name text that now shares a row with the path.

### D1. PostgreSQL suite phase

Files: `.devtools/pgsql/nested-locations-tests.php` (new), `.devtools/pgsql/run-tests.sh`
(a `locations` target, included in `all`, and the "fifteenth" paragraph in the header,
matching the style of the fourteen before it). The `suite` job runs `run-tests.sh` with no
argument, so no workflow edit is needed.

Model on `group-min-stock-tests.php`: PostgreSQL only, builds its own data, every case
that could pass for the wrong reason has a control. Cases, each with its expected result:

1. The fixture tree resolves to the expected pairs: 10 self rows, and `Door`'s ancestors
   are `UprightFreezer` (1), `StorageRoom` (2), `Basement` (3); its `path` is the full
   string. Levels derived from `MAX(depth)` are 0, 1, 2, 3 down the chain.
2. Uniqueness: `Shelf1` under `Rack1` is accepted while `Shelf3` already exists there
   (control); a second `Shelf3` under `Rack1` is refused; a second root named `Basement`
   is refused (this is the `NULLS NOT DISTINCT` assertion; without it the insert would
   succeed).
3. Cycles: `parent_location_id = id` is refused; setting `Basement`'s parent to `Door` is
   refused with `Recursive nested location detected`; setting `Rack1`'s parent to
   `Kitchen` is accepted (control).
4. Depth: a chain of six is accepted and the seventh is refused; re-parenting `StorageRoom`
   (height 2) under `Shelf1` (level 3) is refused, under `Kitchen` (level 1) accepted.
5. Delete: `StorageRoom` refused with `Location has child locations`; `Shelf3` accepted;
   through `GenericEntityApiController::DeleteObject` the refusal is a 400 whose body
   carries that message and no `SQLSTATE` text.
6. `stock_current_locations` still answers exactly: a purchase into `Door` appears at
   `Door` only; joining through `locations_resolved` from `Basement` finds it.
7. `/objects/locations` for a fixture row returns the key set it returned before this
   change plus `parent_location_id`, and `/objects/locations_resolved` is readable by a
   user holding `STOCK_VIEW` and refused for one without it, creating both users the way
   `rbac-tests.php` does, through `UsersService::CreateUser()` with and without a role
   that grants the read.
8. `is_freezer` is literal (Q3, Q5): `StockService::TransferProduct()` from `Shelf3` into
   `Door` applies `default_best_before_days_after_freezing`; into a `Door` whose flag is
   cleared it does not (control). Nothing reads the flag from an ancestor.
9. `GetCurrentStockLocationContent()` groups by exact location: stock at `Door` is not
   reported under `Basement` (Q4).

Run the whole suite, not only the new phase, because the `migrate` phase applies every
migration on disk and the `import` phase migrates a fresh target: both have to be green
with 0273 present.

### D2. Browser probe

Files: `.devtools/frontend/nested-locations.js` (new), `.github/workflows/tests.yml` (a
step in `frontend-security` after the group minimum stock step and before the label
steps, against the demo instance on port 8085, not the labels instance on 8087),
`.devtools/frontend/README.md`.

Model on `group-min-stock.js`: a per-run token in every name, records created through the
form and read back through the API. Assertions, each of which is invisible to D1:

1. Creating the fixture tree through `/location/new` with the parent picker; the created
   rows carry the expected `parent_location_id`.
2. Creating `Door` under `UprightFreezer` pre-ticks `is_freezer` on the form before save;
   creating `Shelf3` under `Rack1` does not.
3. The purchase page's location picker lists the path for `Door`, and a purchase made
   there lands at `Door`'s id.
4. The stock overview location filter set to `Basement` shows the product purchased into
   `Door`; set to `Main` it does not.
5. Deleting `StorageRoom` from the locations list shows the refusal message and the row
   is still there.
6. A location named with the S29 payload string is created as a child and every page in
   3–5 renders without executing it.

Confirm the probe fails when piece C's controller widening is reverted, as plan 03 did
for its probe, and say so in the commit message.

### E. Records

Files: `docs/plans/08-nested-locations.md` (**Executed** section), `docs/plans/README.md`
(status row for 08, wave 4 row, and the migration renumbering from piece A),
`docs/plans/07-nested-products.md` (one sentence: the depth function exists), `docs/data-model.md`
(the view in the inventory), `docs/usage.md` if it describes locations.

The Executed section records, with dates: what shipped and under which number; the
renumbering; the PostgreSQL minimum change; the delete pre-check and why the trigger
alone was not enough; the stale `Location` schema fields left alone; pre-existing
deletion of a childless location with stock; the choice of path text over indentation;
and the verification results by phase and job name. Names of CI jobs cited must exist,
because `php .devtools/check-cited-jobs.php` runs on every pull request.

## Out of scope, and where it went

- Stock roll-up in `stock_current_locations`: issue 81 keeps its meaning.
- Inherited `is_freezer`: Q3 says literal; plan 23 will derive the flag from a storage
  class later and already cites 08's answer.
- The "current location" scanning session: plan 06 defers it to after 08.
- Demo data nesting: the generator's four locations stay flat; the probes build their own
  tree. Nesting the demo data would move row counts the frontend baseline harness records.
- Fixing `Location`'s missing `is_freezer` and `active` in the OpenAPI spec: noted in E.
- The path on a printed location label and in `/labels/locations/{locationId}/context`:
  plan 06 says it wants the path once 08 lands, and it is plan 06's change to make.

## Definition of done

The `lint`, `suite`, `frontend-security`, `images`, `flake` and `php-security` jobs are
green on the pull request;
`run-tests.sh all` passes locally against `postgres:16` including the new `locations`
phase; `check-migrations.php` passes without the waiver; plan 08's status row reads landed and
its Executed section is written.
