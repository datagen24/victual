# 30. Nested product groups

**Goal:** The catalogue's tree of kinds — `Spices / Garlic / Fresh`, `Dairy / Cheese`,
`Drinks / Soda / Coca-Cola` — lives in `product_groups`, so browsing, reporting and grouping
work at every level without any of it touching stock.
**Depends on:** [ADR-0023](../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md),
**Proposed**, which this implements. [03](03-category-min-stock.md) shipped the table this
adds a column to.
**Interacts with:** [31](31-directed-substitution.md), which carries the relations between
the products this groups; [08](08-nested-locations.md), whose pattern this copies almost
exactly.
**Would replace:** [07](07-nested-products.md) — retired by a pull request that comes *after*
ADR-0023's acceptance, never by the acceptance itself.
**Status:** draft for review, and **not scheduled** — ADR-0023 is Proposed, so nothing here
is authorised yet. Tracked as [issue 124](https://github.com/datagen24/victual/issues/124).

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
[ADR-0023](../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md):

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

One PostgreSQL-only migration, claimed in [RESERVATIONS.md](../../migrations/RESERVATIONS.md).

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
