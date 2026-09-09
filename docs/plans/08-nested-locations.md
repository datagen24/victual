# 08. Deeply nested locations

**Goal:** Locations form a tree — floor / room / cabinet / shelf — rather than a flat list.
**Depends on:** [12](12-frontend-shared-core.md) and [14](14-contract-and-regression-scaffolding.md),
per the README. Worth doing before [07](07-nested-products.md), which needs the same
recursive pattern against far more call sites.
**Status:** landed in wave 4; see [Executed](#executed).

## Today

`locations` is flat, and has been since `migrations/0002.sql`:

```
id, name (unique), description, row_created_timestamp, is_freezer, active
```

Stock rows carry a single `location_id`. `stock_current_locations` and
`stock_current_location_content` group by it directly. There is no notion of containment,
so "what is in the kitchen" can only be answered if every shelf is literally named
"Kitchen — …".

## Proposed change

### Schema

`ALTER TABLE locations ADD parent_location_id INTEGER` — the same shape as
`products.parent_product_id`, which keeps the two hierarchies conceptually identical and
lets 07 reuse whatever is built here.

One wrinkle: `locations.name` is `UNIQUE` today. In a tree, two different rooms each having
a "Top shelf" is entirely reasonable. Options in Q1.

### Views

New `locations_resolved` as a recursive CTE producing `(ancestor_location_id,
descendant_location_id, depth)`, plus a `path` string for display ("Kitchen / Pantry /
Top shelf"). `WITH RECURSIVE` works on both engines; `quantity_unit_conversions_resolved`
already ships a `path` column built this way, so there is a pattern to copy including the
string building.

`stock_current_locations` and `stock_current_location_content` stay as they are — they
answer "what is stored at exactly this location", which remains a valid question. Roll-up
becomes a separate concern, joined through `locations_resolved` where wanted, rather than
a change in meaning of an existing view. That keeps this additive.

### Triggers

Cycle prevention on insert and update, same as the recipe nesting guards. Deleting a
parent needs a decision — Q2.

### API

- `locations` gains `parent_location_id`. It **is** in `ExposedEntity`, so
  `/objects/locations` responses gain a field. Additive, and consistent with how upstream
  has added columns before.
- `locations_resolved` added to `ExposedEntity` so clients can fetch the tree in one call
  rather than walking parents.

**Client impact: one additive field and one new entity, plus the same semantic widening
[07](07-nested-products.md) has.** A client that renders a location name now renders a
node in a tree; nothing forces it to notice. Milder than 07's, because a location is
displayed far more often than it is aggregated over — but a client that builds a location
picker from a flat list will show a flat list of names that are no longer unique in
meaning.

### UI

Location dropdowns are the visible work: they appear on purchase, consume, transfer,
inventory, the product form and stock entry. They should show the path rather than the
bare name, or indent by depth. That is a shared partial, so it is one change in several
templates rather than several changes.

## Interaction with `is_freezer`

`is_freezer` is per location today. In a tree, a freezer compartment inside a freezer
inside a kitchen raises the question of whether the flag inherits. Victual uses it for
freezing/thawing due date handling, so getting it wrong changes due dates. See Q3.

## Open questions

1. **Drop the `UNIQUE` on `locations.name`?** A tree makes duplicate leaf names normal.
   Options: drop it entirely; replace with `UNIQUE(parent_location_id, name)`, which allows
   "Top shelf" in two rooms but not twice in one; or keep it and make users disambiguate.
   I lean to `UNIQUE(parent_location_id, name)` — note that in PostgreSQL, NULLs are
   distinct by default, so several root locations could share a name unless
   `NULLS NOT DISTINCT` is used, which is PostgreSQL 15+.

   > **Response:** `UNIQUE(parent_location_id, name)` with `NULLS NOT DISTINCT`
   > (this fork can require 15+); on SQLite the equivalent is a unique
   > **expression** index on `(IFNULL(parent_location_id, -1), name)`. That makes
   > this the first migration pair where the two engines need genuinely different
   > DDL for the same rule — a good, small test of the per-engine migration
   > convention.
2. **Deleting a parent that has children and stock.** Reparent children to the deleted
   node's parent, block the delete, or cascade? Blocking is safest and easiest to explain.

   > **Response:** Block. Reparenting silently rewrites history; cascade deletes
   > stock's location. Blocking with a clear message is honest.
3. **Does `is_freezer` inherit from an ancestor?** If yes, the effective value comes from
   `locations_resolved` and due date logic must use that rather than the row's own flag.
   If no, the user ticks it on each compartment. Inheriting is friendlier but touches due
   date behaviour, which is stock-correctness territory.

   > **Response:** Don't inherit, v1 — keep the flag literal, and get 90% of the
   > friendliness by defaulting the checkbox from the parent when creating a child
   > location. Inheritance can be revisited if the explicit flag proves annoying.
4. **Should stock roll up by default anywhere in the UI?** For example, should the stock
   overview's location filter for "Kitchen" include everything beneath it? I would say yes
   for filtering, no for the location content report, but this is a taste call.

   > **Response:** Agreed with the lean: roll up for filtering, not for the location
   > content report.
5. **Depth cap?** Same question as 07 Q3. Floor/room/cabinet/shelf is four, so any cap
   should be comfortably above that.

   > **Response:** Share one constant with 07; something like 6 clears
   > floor/room/cabinet/shelf with headroom.
   >
   > Confirmed against the real layout, which is `Floor / Room / SubSpace / Shelf`:
   >
   > ```
   > Basement / StorageRoom / Rack1           / Shelf3
   > Basement / StorageRoom / UprightFreezer  / Door
   > Main     / Kitchen     / SinkLeftCab     / Shelf1
   > ```
   >
   > Four levels, so a cap of 6 stands. Two things this makes concrete: the third
   > level is a *container*, not a room, so `UprightFreezer` and `SinkLeftCab` sit at
   > the same depth — depth carries no fixed meaning and nothing may key off it. And
   > `Basement/StorageRoom/UprightFreezer/Door` is the `is_freezer` case from Q3 in
   > real data: the freezer is level 3 and the thing stock actually points at is
   > level 4, so with the "don't inherit" answer the `Door` row must have the flag
   > ticked itself. That is exactly what the default-from-parent behaviour in Q3 is
   > there to make painless, and it belongs in the fixtures.

## Effort

Medium. The schema and view are small and well understood; the UI dropdowns and the
`is_freezer` decision are the bulk. Two focused sessions, and it de-risks 07.

## Executed

Landed as `migrations/0273.pgsql.sql` — one column, one function, one view, two triggers —
plus the API surface, the UI across fourteen templates, a PostgreSQL-only suite phase and a
browser probe. The design above shipped as written and all five answers were honoured.
Eleven things are worth recording because they are not derivable from it.

**The migration number moved once more, and this was the eighth move of the same three
numbers.** The plan was scoped against a table that had 0273 for [23](23-storage-classes.md)
and 0274–0275 for [22](22-medication-tracking.md); 0273 went to this plan, so 23 is now 0274
and 22 is 0275–0276. The rule is the one the reservations table has applied seven times
before: the number about to have a *file* behind it takes the lowest free slot and unwritten
drafts move up. Both plans' bodies and the status table moved with it, and the table's running
history records the move. Plan 23 is the one to re-read after this — it adds
`locations.storage_class_id` to the same table, and will now do so on top of
`parent_location_id`.

**The PostgreSQL minimum is 15, and two places said 13, not one.** `NULLS NOT DISTINCT` is
PostgreSQL 15 and later, and without it the uniqueness rule holds everywhere except among
root locations, where each NULL parent is distinct from every other — so two "Basement"
roots would be accepted and the rule would be silently half a rule. The execution plan
expected only `db/pgsql/README.md`'s target line to say 13; `config-dist.php`'s own comment
said it too, and both were changed. CI runs `postgres:16` and `deploy/README.md` documents
16, so nothing else was pinned.

**No index on `parent_location_id`, contrary to the execution plan's contract.** The unique
constraint's index already has that column leading, so it serves the recursive view's
"children of this id" lookup; a second index would be the same access path twice on a table
with a few dozen rows. The migration's comment says so.

**A trigger alone could not answer question 2, and the answer needed a third place.**
`BaseApiController::GenericErrorResponse()` replaces any message beginning `SQLSTATE[` before
it is rendered — deliberately, so a driver's text cannot leak — so a trigger's `RAISE` can
never *be* the clear message the question asks for. The refusal is therefore raised in
`GenericEntityApiController::DeleteObject()` as a 400, with the trigger as the backstop for
every other write path and both worded identically. And that was still not enough: the shared
delete helper from [12](12-frontend-shared-core.md) sent every failure to
`Victual.Api.DefaultErrorHandler`, which says "A server error occured" and hides the server's
own words behind "Click to show technical details". A new `ShowApiError` shows the server's
message for a **4xx** and leaves everything else on the old path — the split is what keeps the
technical-details dialog reachable for the S29 probe that drives it, and running that probe is
how the need for the split was found.

**A nullable column could not be nulled through the API at all.**
`BaseApiController::GetParsedAndFilteredRequestBody()` ran HTMLPurifier over every scalar in
the body, and `purify(null)` returns the empty string. On a text column that passed, because
every reader treats `""` and NULL alike — `public/viewjs/productform.js` has been sending
`picture_file_name: null` to clear a picture on that basis. On a nullable **integer** it does
not pass: the insert is refused by the database, with a message the client is deliberately
not shown. A root location posts exactly that, so the loop now skips null. This is a
pre-existing defect the location form only surfaced; it is a change to a write path shared by
every entity, and it is recorded here rather than buried because of that.

**`stockjournal.blade.php` was not on the execution plan's list and had to change.** Its
location filter compares the selected option's *text* against the location column, which was
exact while names were globally unique and stops being exact the moment they are unique only
among siblings. Both sides became the path, so the mechanism is unchanged. The journal does
not roll up; nothing in question 4 asks it to.

**The `Location` schema is still missing `is_freezer` and `active`.** `/objects/locations`
has always returned them and the OpenAPI schema has never listed them. Adding them is a
contract change of its own with its own argument to make, so this work added
`parent_location_id` and left the two gaps exactly where it found them. The suite phase
asserts the whole key set rather than just the new key, so the gap is now written down in a
test as well as here.

**Deleting a childless location that still holds stock is unchanged.** Nothing stops it
today — no trigger, no foreign key — and `guard_location_children` refuses on children only.
Making that a second refusal would be a behaviour change this plan did not ask for and issue
81 lists under **Unchanged**.

**Question 1's SQLite half is moot and was not built.** The answer named a unique
*expression* index on `(IFNULL(parent_location_id, -1), name)` as the SQLite equivalent, and
called this "the first migration pair where the two engines need genuinely different DDL".
[ADR-0008](../adr/0008-postgresql-only-runtime-engine.md)'s retirement landed between that
answer and this work: the SQLite line is frozen at 0265 and `check-migrations.php` refuses a
`.sqlite.sql` above it, so there is no pair to write and no second dialect to test the
convention with. `0273.pgsql.sql` is a lone file.

**Option text is the path, not an indent.** Issue 81 allowed either. The pickers are
bootstrap-combobox typeaheads that match on option text, so "Kitchen / Pantry / Top shelf"
is typeable at every level and tells two "Top shelf" rows apart, which indentation by
non-breaking spaces does not. `data-level` is on every option anyway for a caller that wants
the shape. One consequence is recorded in `locationpicker.js`: prefill-by-name uses
`:contains()`, which now matches any path containing the string. No template passes
`prefillByName` today, so the code path is left as it is with a comment saying new callers
should prefer the id.

**Verification.** `run-tests.sh locations` is the fifteenth suite phase, PostgreSQL-only for
the same structural reason [03](03-category-min-stock.md)'s is: the view phase seeds SQLite
and copies across through the importer's common-column logic, so `parent_location_id` would
arrive NULL for every row and every assertion about a tree would be an assertion about a flat
list. It makes its own tree and asserts 39 things, every refusal matched on its message and
paired with a control that has to still be accepted. `.devtools/frontend/nested-locations.js`
— invoked by the `frontend-security` job, not merely placed beside the other probes — builds
the tree through the form, checks the freezer default in both directions, buys into a leaf and
filters by its root. It was confirmed to fail when the ancestor list is reverted out of the
overview's hidden cell.

Results, against `postgres:16` (16.13) on 2026-09-09:

| Check | Result |
|---|---|
| `php .devtools/pgsql/check-migrations.php` | `MIGRATION NUMBERING OK`, no `--allow-reserved-holes` |
| `run-tests.sh locations` | `EVERY NESTED LOCATION ANSWERED AS EXPECTED (39 assertions)` |
| `run-tests.sh all` | every phase green except `files`, which fails the same three cases on `origin/master` in this container: they expect a mode 000 directory to be unreadable and the suite runs as root |
| `.devtools/frontend/nested-locations.js` | `NESTED LOCATION BROWSER CHECKS PASSED` |
| `.devtools/frontend/s29-payload.js` | `27/27 probes clean` |
| `.devtools/frontend/group-min-stock.js`, `roles.js`, `forced-failure.js`, `two-pickers.js`, `location-labels.js` | pass, demo instance |
| `.devtools/frontend/location-print.js`, `label-printers.js`, `label-designer.js` | pass, labels instance |
| `.devtools/frontend/routes-smoke.js` | 79 routes, 0 non-200 |
| `php .devtools/check-cited-jobs.php` | every cited job exists |
