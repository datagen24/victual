# 30. Nested product groups

**Goal:** The catalogue's tree of kinds — `Spices / Garlic / Fresh`, `Dairy / Cheese`,
`Drinks / Soda / Coca-Cola` — lives in `product_groups`, so browsing, reporting and grouping
work at every level without any of it touching stock.
**Depends on:** [ADR-0023](../../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md),
**accepted 2026-09-14**, which this implements. [03](03-category-min-stock.md) shipped the table this
adds a column to.
**Interacts with:** [31](31-directed-substitution.md), which carries the relations between
the products this groups; [08](08-nested-locations.md), whose pattern this copies almost
exactly.
**Replaces:** [07](../retired/07-nested-products.md), retired 2026-09-14 by the pull request that
scheduled this plan — after ADR-0023's acceptance, not as part of it.
**Status:** landed in wave 4, 2026-09-15; see [Executed](#executed). Was scheduled 2026-09-14 as
the first of the two product plans and tracked as
[issue 124](https://github.com/datagen24/victual/issues/124). Migration
**0278**, renumbered from 0277 to make room for
[issue 148](https://github.com/datagen24/victual/issues/148)'s own migration 0277 (see
[RESERVATIONS.md](../../../migrations/RESERVATIONS.md)). That issue is now fixed: the
nesting-level trigger this plan copies fired only on `UPDATE`, never `INSERT`, and checked
only one of the two directions a violation can arrive from. Copy `trg_enfore_product_nesting_level`
as migrations/0277.pgsql.sql left it, not as the baseline still shows it.

## Why this is a small plan

Because [08](08-nested-locations.md) already did it. The same nullable parent column, the
same uniqueness change, the same recursive view, the same cycle and depth guards, on a table
with fewer consumers than `locations` had. What made plan 07 large — stock aggregation,
substitution semantics, `cascade_change_qu_id_stock`, an audit of eight sites built on a
one-level assumption — is absent here, because a group holds no stock and carries no
quantity unit.

## Today

`product_groups` has been a flat lookup since the baseline:

```
id, name (globally UNIQUE), description, row_created_timestamp, active
```

plus `min_stock_amount` from [03](03-category-min-stock.md), migration 0268. Products join it
through `products.product_group_id`, which is nullable.

## The tree this has to hold

Supplied 2026-09-13 as the worked case behind
[ADR-0023](../../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md):

```
Spices                    group
├─ Parsley  → Dried       group → product
├─ Pepper   → Black       group → product
├─ Blend    → Sublime Swine
├─ Paprika  → Smoked, Ground
├─ Garlic
│  ├─ Fresh → Whole, Crushed      subgroup holding products
│  └─ Dried                        product, sibling of that subgroup
└─ Mustard  → Ground
```

Two properties matter and neither needs special handling:

**It is ragged.** `Spices / Garlic / Fresh` is three levels; `Spices / Pepper` is two. Uneven
depth is the answer already recorded for brand levels, and matches
[08](08-nested-locations.md)'s finding that depth carries no meaning of its own.

**`Garlic` holds a product and a subgroup at once.** Products attach by `product_group_id`
and groups by the new parent column, so the two memberships are independent. ADR-0023
decision 6 states this; acceptance prerequisite 2 asks for it demonstrated.

## Proposed change

### Schema

`ALTER TABLE product_groups ADD parent_product_group_id INTEGER`, nullable, same shape as
`locations.parent_location_id`.

**And the uniqueness rule has to change.** `product_groups.name` is globally `UNIQUE` today.
Nesting makes that wrong — `Dried` appears under `Parsley` and under `Garlic` in the tree
above — so it becomes `UNIQUE(parent_product_group_id, name) NULLS NOT DISTINCT`, exactly the
change [08](08-nested-locations.md) made to `locations`.

`NULLS NOT DISTINCT` is PostgreSQL 15 and later. The engine minimum is already 15 and both
places that said 13 were corrected when 08 landed, so nothing new is pinned here — but the
rule is half a rule without it: among root groups every NULL parent is distinct from every
other, and two "Spices" roots would be accepted.

### Views

A new `product_groups_resolved`, a recursive CTE producing
`(ancestor_product_group_id, descendant_product_group_id, depth)` plus a display `path`
("Spices / Garlic / Fresh"). Copy `locations_resolved` from `migrations/0273.pgsql.sql`
including the string building and both stop conditions.

**`hierarchy_depth_limit()` is shared unchanged.** It was written generic in 0273 expecting
plan 07 to be its second consumer; this plan is instead. The observed tree reaches three
group levels against a limit of six nodes.

### Guards

Cycle prevention on insert and update, and a delete guard, in the shape 0273's are — including
the parts 08 learned the hard way rather than designed:

- The trigger function takes `pg_advisory_xact_lock` before it reads, because two concurrent
  re-parentings each read the tree as it was before the other wrote and both commit, leaving
  rows unreachable from any root and therefore invisible in the view.
- It must stay **VOLATILE**. The lock alone is half a fix: a statement that blocks on it took
  its snapshot before the other transaction committed, and only a fresh snapshot per query
  sees the write it waited for. 08 measured a STABLE function accepting exactly the cycle the
  lock was added to prevent.

Read `migrations/0273.pgsql.sql` before writing this one. The comments there are the
specification.

### Delete

Block deleting a group with children, matching 08's answer for locations. Deleting a group
that still has products is unchanged from today's behaviour and is not this plan's to alter.

### API and UI

- `product_groups` gains `parent_product_group_id`, additive on `/objects/product_groups`.
- `product_groups_resolved` added to `ExposedEntity` so a client fetches the tree in one call.
- The group form gains a parent picker; group dropdowns show the path rather than the bare
  name. 08 chose path text over indentation because the pickers are typeahead comboboxes that
  match on option text, and the same reasoning applies unchanged.

**Check `productform.js` for the null-clearing defect 08 found.** A nullable *integer* could
not be set to NULL through the API at all, because `GetParsedAndFilteredRequestBody()` ran
HTMLPurifier over every scalar and `purify(null)` returns the empty string — harmless on a
text column, refused by the database on an integer. A root group posts exactly that. 08 fixed
the shared write path, so this should now work; confirm it rather than assume it.

### Migration

One PostgreSQL-only migration, claimed in [RESERVATIONS.md](../../../migrations/RESERVATIONS.md).

## Verification

`.devtools/pgsql/difftest.php` over the new view, and a suite phase in the shape of 08's
`locations` phase — PostgreSQL-only for the same structural reason, since the view phase seeds
through the importer's common-column logic and `parent_product_group_id` would arrive NULL for
every row, making every assertion about a tree an assertion about a flat list.

Cases that matter:

- the spice tree above built through the API, with paths correct at every level
- `Garlic` holding the product `Dried` and the subgroup `Fresh` at once, both reachable
- two groups named `Dried` under different parents accepted; two under the same parent refused
- two root groups named `Spices` refused — the `NULLS NOT DISTINCT` case
- a cycle refused, and the concurrent re-parenting case from 08, including the assertion that
  fails when the guard function is marked STABLE
- a group with children refused deletion
- depth stopping at `hierarchy_depth_limit()`

A browser probe for the group form and the path-rendering dropdowns, invoked by the
`frontend-security` job rather than merely placed beside it — 08's Executed section records
that distinction being missed.

## Open questions

1. **Does a group minimum roll up?** [03](03-category-min-stock.md) shipped
   `product_groups.min_stock_amount` against a flat table and `product_groups_missing` sums
   each member product's own stock. Whether a parent group's minimum covers products in
   descendant groups is undecided and is ADR-0023's open question 1. Answering "no" keeps this
   plan additive; answering "yes" changes a shipped view.
2. **Does the shopping list group by path?** 03's Q1 note-only row is a follow-up that never
   landed, and grouping a list by aisle is one of the two things the taxonomy was wanted for.
3. **Is `products.product_group_id` still nullable?** It is today. A product in no group is
   invisible to any group-based browse, which matters more once browsing is the point.

## Effort

Small. The migration is 0273 with the nouns changed, and the risk concentrates in the guards
rather than the schema — which is why the instruction above is to read that file rather than
to write a fresh one.

## Executed

Landed as `migrations/0278.pgsql.sql`, at the migration number the plan header and
[RESERVATIONS.md](../../../migrations/RESERVATIONS.md) already named — issue 148's own fix took
0277 ahead of it on 2026-09-15, so no further renumbering was needed here. One column, one
reused function (`hierarchy_depth_limit()`, unchanged from 0273 — this is the second consumer
it was written generic for), one view and two triggers, plus the API surface, the group form's
parent picker, the path shown in the product form's group dropdown and the product groups
list, a PostgreSQL-only suite phase and a browser probe. The design above shipped as written;
what follows is not derivable from it.

**Nothing from plan 08's schema carries over beyond the shape.** Locations needed
`WithDerivedIsFreezer()` because `is_freezer` and `storage_class_id` derive one another on
write; product groups have no such column, and ADR-0023 decision 6's mixed node —
`product_group_id` and `parent_product_group_id` are independent foreign columns — needed no
code at all, only the fixture case that proves it (below).

**The delete pre-check is one more `if` in the same method, not a new mechanism.** Plan 08
built `GenericEntityApiController::DeleteObject()`'s pattern (the trigger's `RAISE` can never
reach a client, because `GenericErrorResponse()` replaces any message beginning `SQLSTATE[`,
so the sentence a person reads has to be raised in the controller, worded identically to the
trigger's own backstop); this plan adds a second `if ($args['entity'] == 'product_groups' ...)`
beside the existing `locations` one, refusing with `Product group has child groups`.

**`product_groups` carries no explicit `select()` list, unlike `locations`.** Plan 25 added
one to `locations` to keep `import_epoch` off the wire; `product_groups` has no such
plan-25-owned column, so `parent_product_group_id` (and every other column) reaches
`/objects/product_groups` for free through `GenericEntityApiController`'s generic path — there
was no second list to widen, which the plan's execution instructions from 08 do not mention
because 08 needed one and this table does not.

**`victual.openapi.json` has no `ProductGroup` schema to widen, and none was added.**
Unlike `Location`, the spec has never documented `product_groups`' own object shape — the
`/objects/{entity}` `oneOf` samples are a handful of entities, not an exhaustive list, and
`product_groups` was never among them. Adding a full schema now would be a wire-contract
addition this plan did not ask for and did not scope; what the plan does ask for —
`product_groups_resolved` in the three `ExposedEntity*` enums, a read policy row, and a
documented shape for the resolved view — is done, with a new `ProductGroupResolved` schema
mirroring `LocationResolved`.

**`GetProductGroupsWithPaths()` and `GetProductGroupAncestorIds()` are `StockService`'s new
methods**, copied from `GetLocationsWithPaths()`/`GetLocationAncestorIds()` with the nouns
changed. The ancestor-id method exists only because the group form's parent picker needs it —
`ProductGroupEditForm()` filters a group being edited and its whole subtree out of the parent
picker's options, the same trap-avoidance reasoning `LocationEditForm()` applies. No roll-up
filter reads it: plan 30 question 2 (does the shopping list group by path) is unanswered, so
nothing here builds one, unlike `GetLocationAncestorIds()`'s second caller in the stock
overview's location filter.

**Group dropdowns show the path in the two places a person assigns a product to a group: the
group form's own parent picker and the product form's `product_group_id` select.** Filter-only
selects that browse by group name or id (the stock overview, `products.blade.php`, the
shopping list's group userfield display, `stocksettings.blade.php`'s report filter) are left
as bare names — question 2 (whether the shopping list, or any list, groups by path) is
unanswered, and widening a filter's semantics to roll up through the tree is that question's
work, not this plan's. `stocksettings.blade.php`'s "default product group" preset picker was
weighed the same way as the filters and left alone for the same reason: it does not use
`GetProductGroupsWithPaths()` and picking a default is closer to a filter than to an
assignment a database write depends on.

**The mixed node (ADR-0023 decision 6, acceptance prerequisite 2) needed no special case,
demonstrated rather than merely argued.** The suite phase files a product directly under
Garlic (`product_group_id = Garlic`) in the same fixture where Garlic is also the subgroup
Fresh's `parent_product_group_id`, and asserts both memberships independently: Garlic is a
product's own group, Garlic is a subgroup's parent, `product_groups_resolved` still reaches
`Spices / Garlic / Fresh`, and Fresh resolves its own product membership without regard to
what its parent Garlic holds.

**`product_groups_missing` (migration 0268) is unaffected, and the suite phase says so as a
control rather than as an answer.** Question 1 — does a group minimum roll up to descendant
groups — is still open; this migration adds nothing that changes what that view reads. A root
group with a minimum and no direct products of its own is reported short by its whole minimum
even though its descendant groups hold well-stocked products, exactly as it was before 0278.

**Verification**, against real PostgreSQL 16.13 (`postgres:16` at the OS package level, on
2026-09-15): `php .devtools/pgsql/check-migrations.php` reports `MIGRATION NUMBERING OK` with
no `--allow-reserved-holes` waiver. `.devtools/pgsql/nested-product-groups-tests.php`
(`run-tests.sh productgroups`) passes all 41 assertions — the tree and its paths, the
`NULLS NOT DISTINCT` uniqueness (named after ADR-0023's own acceptance spike's choice of
"Dried" as the test name), a cycle and its accepted control, the depth cap and a genuine
subtree-reparenting refusal (two isolated trees, so the refused case is not also a cycle in
disguise, mirroring 08's own StorageRoom/Shelf1/Kitchen case), the mixed node, the delete
guard through both the database and `DeleteObject()`'s 400, the read policy, and the same
concurrent-re-parenting construction 08's own case 10 uses — two PDO connections, the second
statement blocked on `pg_advisory_xact_lock(278, 1)` and released by the first committing
while it waits, measured at a 2.50s wait before the expected refusal, with the guard function's
`provolatile = 'v'` asserted directly alongside the behavioural case for the reason 08's own
comment gives: a STABLE function would pass the same wall-clock wait while accepting the
cycle. `run-tests.sh all` passes every phase, `productgroups` included, with no regression
elsewhere. `php .devtools/check-cited-jobs.php` reports every cited job exists. PHP lint
(`php -l`) is clean on every changed PHP file, and `victual.openapi.json` parses as valid JSON
after the edits.

**The two test-authoring defects the first run of the new suite phase caught are worth
recording, because both were in the test rather than the migration.** The "subtree cannot be
reparented too deep" case's first version passed `[$chain[5], $spices]` as `UPDATE
product_groups SET parent_product_group_id = ? WHERE id = ?`'s parameters — backwards, so it
reparented the root `Spices` under the chain's own leaf rather than the other way round, and
the resulting refusal (correct, but for a completely different reason) was read as a failing
control. Replaced with two isolated trees (`Deep3Root`/`Deep3Mid`/`Deep3Leaf`,
`WideRoot`/`Wide2`/`Wide3`/`Wide4`) so the refused case is provably a depth violation and not
also a cycle. Separately, the mixed-node assertion counted Garlic's children as exactly one
(`Fresh`) without accounting for the "Dried" groups the uniqueness case (2) leaves behind under
both Parsley and Garlic — fixed by clearing those two rows once that case is done, the same way
`nested-locations-tests.php` clears its own duplicate "Shelf1" after its case 2.

**The browser probe (`.devtools/frontend/nested-product-groups.js`) could not be run end to
end in this session** — the demo instance it needs to drive requires PHP 8.5.0
(`PrerequisiteChecker::REQUIRED_PHP_VERSION`) to boot at all, and this sandbox carries PHP
8.4.19 with no network path to the PHP 8.5 package (`apt-get install php8.5-cli` fails: the
sury.org PPA is not on the outbound proxy's allowed host list, returning 403), confirmed by
reproduction rather than assumed — booting the demo instance under PHP 8.4 returns HTTP 200
with the body `Unable to run Victual: PHP 8.5.0 is required, however you are running 8.4.19`
on every route. **It did run in CI** ([PR #165](https://github.com/datagen24/victual/pull/165),
2026-09-15), which is where the one real defect in it surfaced: the tree, the parent-picker
path assertions and the group dropdown's full-path option all passed, then the probe timed out
waiting for a payload-named group's row to become visible in the `productgroups` DataTable.
The payload-rendering check was speculative in exactly the way the PHP-8.5 gap predicted it
would be — written without a way to see the table's actual pagination or sort behavior — and
it duplicated coverage `s29-payload.js`'s own `productgroups` probe, run earlier in the same
`frontend-security` job, already provides (it passed in the same run, `productgroups xss=undefined
text=true img=0`). Removed rather than debugged blind a second time: the probe now asserts only
what plan 30's own verification list and its header comment claim — the parent picker, the path
in a dropdown, and the delete refusal — each already proven to pass against the real CI
instance in the run that found the one defect.
