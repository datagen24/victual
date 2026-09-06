# 03. Category level minimum stock

**Goal:** Set a fallback minimum for a product group — "always have *some* milk" — instead
of having to set one on every individual product.
**Upstream:** [grocy/grocy#2616](https://github.com/grocy/grocy/issues/2616)
**Status:** landed in wave 3b; see [Executed](#executed). Decided 2026-09-04:
this plan does not wait on [07](07-nested-products.md)'s Q6. If Q6 lands on *taxonomy*,
the nullable `parent_product_group_id` column lands as an additive follow-on to this plan,
not as a change to its scope now.

## Today

Minimums are strictly per product: `products.min_stock_amount`, with
`cumulate_min_stock_amount_of_sub_products` letting a parent's minimum be satisfied by the
sum of its children.

`stock_missing_products` computes what is short. It is a `UNION` of two branches — products
that do not cumulate, and parents that do — and both filter on
`WHERE p.min_stock_amount != 0`. `StockService::AddMissingProductsToShoppingList()` reads
that view and is called after purchase, consume and product edits.

`product_groups` is a plain lookup table: `id, name, description, active`.

A group minimum would support the same requirement without creating a parent product
solely to represent a category.

## Proposed change

### Schema

`ALTER TABLE product_groups ADD min_stock_amount` — `DOUBLE PRECISION NOT NULL DEFAULT 0`
on PostgreSQL, matching how amounts are typed throughout (see `db/pgsql/README.md`
hazard 2 — this must not be `INTEGER`, since `products.min_stock_amount` demonstrably
holds fractions).

### Views

**A new view, `product_groups_missing`, and no change to `stock_missing_products`.** For
each group with `min_stock_amount != 0`, one row carrying the group, its minimum and the
missing amount — the minimum less the summed stock of its active products.

`stock_missing_products` must retain product-keyed rows because
`StockService::AddMissingProductsToShoppingList()` uses each row's product id. A group
shortfall has no single product to add, so it needs a separate view (Q1).

Sum each member product's own, non-aggregated stock. Summing rows that already include
child stock would double-count it when both parent and child belong to the group (Q3).
Q3 also settles `no_own_stock` and membership by the same rule: only a *direct member's*
own stock counts, so a child outside the group contributes nothing through its parent, and
a `no_own_stock` parent contributes nothing at all.

Inactive products are excluded, matching the existing branches' `IFNULL(p.active, 0) = 1`
(Q4) — but the exclusion belongs in the join, not in an outer `WHERE`. A group whose only
members are inactive, and a group with no members at all, are both short by their entire
minimum; an outer filter would drop the group from the result instead, which is the same
answer as "fully stocked". Group minimums and per product minimums are independent: a
product below its own minimum is short regardless of its group, and a group below its
minimum is short regardless of its members (Q2). A member's opened stock is discounted
where that member sets `treat_opened_as_out_of_stock` (Q5); an inactive group reports
nothing (Q6).

**Amounts are summed in each member's own stock quantity unit, with no conversion.** A
group minimum is therefore only meaningful for members measured comparably — two litres of
milk plus three pieces of cheese is not five of anything. This is a stated limitation
rather than a defect to fix later: giving a group a unit, or converting members into one,
is a schema question this plan does not open. The product group form says so next to the
field, because a limitation only recorded here is one nobody setting the value will read.

### API

`product_groups` is in `ExposedEntity`, so `/objects/product_groups` gains a field —
additive.

`/stock/volatile` retains its existing shape because `stock_missing_products` is
unchanged. Exposing `product_groups_missing` through `ExposedEntity` would add a new
read entity.

**Client impact:** additive `min_stock_amount` field on `product_groups`, and optionally
a new read entity. Existing fields and `/stock/volatile` remain unchanged.

### UI

A minimum field on the product group form, and some indication on the stock overview that
a group is short. Reusing the existing "below minimum stock" styling is the cheap path.

A count alone is not enough: "two groups are below their minimum" does not tell anyone what
to buy, and Q1 chose the option whose whole premise is that the user picks. So the overview
**names** the short groups and their missing amounts, and each name is an action that filters
the table to that group's products.

That action has a prerequisite the rest of this plan does not: **the products have to be on
the page.** `StockController::Overview()` lists `is_in_stock_or_below_min_stock = 1` unless
the user has turned on `stock_overview_show_all_out_of_stock_products`, and a product at zero
stock with no minimum of its own is exactly the row that flag excludes — which is the main
case a group minimum exists for. Filtering client-side cannot reveal a row that was never
rendered, so the overview's own query has to include the active members of short groups. The
same applies to the location and status filters, whose hidden cells are *empty* for a
zero-stock row: a filter left over from earlier in the session hides the row that was just
added for it. The group action therefore clears the other filters before applying itself,
the way the existing clear-filter button does.

Those added rows get no new status token and no row styling. The product is not below *its*
minimum, and saying it is would put one fact under another fact's name — the group list
above the table is what explains why the row is there.

## Open questions

1. **What does a group shortfall resolve to on the shopping list?** This is the whole
   design. Options:
   - **Do not auto-add.** Show the group as short on the overview; the user picks what to
     buy. Simplest, no API shape change, and arguably correct — the point of the feature is
     that you do not care *which* milk.
   - **Add the group's default product.** Needs a new `product_groups.default_product_id`.
     Concrete and automatable, but reintroduces "which one" in a different place.
   - **Add a note-only shopping list row.** `shopping_list.product_id` is nullable and
     `note` exists, so "Milk (any)" is already representable. Nice fit, but such a row
     cannot be checked off against stock automatically.

   I lean to the first for v1, with the third as a follow-up. It keeps the change to one
   view and one column.

   > **Response:** Option 1 for v1 — and keep group shortfalls in a **new** view
   > (`product_groups_missing` or similar) rather than a third branch of
   > `stock_missing_products`: that removes the `/stock/volatile` question entirely
   > instead of resolving it carefully. The note-only row is a good follow-up once
   > the feature has proven itself.
2. **Does a group minimum interact with per product minimums, or override them?** I
   propose independent: a product below its own minimum is short regardless of group stock,
   and a group below its minimum is short regardless of individual products. Overlap is
   possible and probably fine.

   > **Response:** Agreed, independent.
3. **Should group stock count sub products?** If a group contains a parent product,
   presumably its children's stock counts toward the group. That falls out naturally if the
   branch aggregates through `products_resolved` — and becomes a real question once
   [07](07-nested-products.md) makes that recursive.

   > **Response:** Aggregate per product, not via `stock_current`'s aggregated rows.
   > Concrete trap: if the group sum is built from rows that already aggregate
   > children into parents *and* the children are themselves in the same group, the
   > stock counts twice. Sum each product's own non-aggregated stock across the
   > group's members; that stays correct when 07 makes the tree deep.
4. **Do inactive products count?** Proposing no, matching the existing branches'
   `IFNULL(p.active, 0) = 1`.

   > **Response:** Agreed, exclude.
5. **Does a member's `treat_opened_as_out_of_stock` reduce the group's stock?** All three
   branches of `stock_missing_products` subtract opened amounts for products carrying that
   flag. The plan text says only "the summed stock of its active products", which would
   ignore it.

   > **Response:** Mirror it, per member product rather than per group. A group whose
   > members are all "opened means gone" would otherwise read as stocked while holding
   > nothing anybody would count. The flag is the product's, so the discount is applied
   > product by product inside the member sum — a group has no such setting of its own and
   > is not being given one.
6. **Does an inactive group with a minimum report a shortfall?** Q4 settles inactive
   *products* and says nothing about the group.

   > **Response:** No — `active = 1` on the group as well. An inactive group is one that has
   > been put away; reporting it as short would be asking the user to shop for a category
   > they have retired. Note this is the group's own row being filtered, which is a
   > different thing from Q4's member filter and must not be written as one: the member
   > filter belongs in the join, or a group with no active members disappears instead of
   > being short by its whole minimum.

## Scope and dependencies

Small: one column, one view, a form field, and an overview indication. Automatic
shopping-list entries are out of scope for v1.

This plan is scheduled for wave 3b and does not wait for [07](07-nested-products.md)'s Q6.
If Q6 selects taxonomy, nested product groups are an additive follow-up. That follow-up
would require a parent-group column and recursive aggregation; it does not expand this
plan's current scope.

## Executed

Landed in wave 3b as `migrations/0268.pgsql.sql` — one column, one view — plus the read
entity, the form field, the overview list, and a PostgreSQL-only suite phase. The design
above shipped as written. Four things are worth recording because they are not derivable
from it.

**The migration number moved twice while this was being written.** The plan was scoped
against a table that gave 0267 to plan 23; 0267 went to the split-entry average price fix
(PR #77) and 0268 to this, so plan 23 is now 0269 and plan 22 is 0270–0271. Those two plans'
bodies and the status table moved with it. The rule that decided the direction is worth
stating once: the number that is about to have a *file* behind it takes the lowest free slot
and unwritten drafts move up, because the alternative puts a file above a hole that nothing
is working to close and `check-migrations.php` then refuses the branch until unscheduled
plans land. The same edit also corrected both plans' "one pair"/"two pairs" wording, which
predated ADR-0008's freeze and would have had them writing `.sqlite.sql` files
`check-migrations.php` now refuses.

**The overview needed a server-side change the plan did not anticipate.** Naming the short
groups is only useful if their members are on the page, and `StockController::Overview()`
lists `is_in_stock_or_below_min_stock = 1` — which excludes a product at zero stock with no
minimum of its own, the exact member a group minimum exists to get bought. The clause was
widened with the active members of short groups, in the restrictive branch only. Two related
things fall out of it: the added rows carry no status token and no row styling, because the
product is not below *its* minimum, and the group action clears the other filters before
applying itself, because a zero-stock row has an empty hidden location cell and an empty
hidden status cell and a filter left over from earlier in the session would hide it.

**`is_partly_in_stock` was dropped.** The view has four columns — `id`, `name`,
`min_stock_amount`, `amount_missing`. `stock_missing_products` carries the flag and nothing
in this feature reads it, and a fifth column on a new public read entity is a shape to
maintain forever in exchange for nothing.

**Verification is a PostgreSQL-only phase, not a difftest seed**, and the reason is
structural rather than a preference. `difftest.php` seeds SQLite and copies the tables into
PostgreSQL through the importer's common-column logic; `product_groups.min_stock_amount`
exists on one side only, so it arrives at its `DEFAULT 0` for every row and every group is
trivially not short. A seed there would pass while asserting nothing. `run-tests.sh
groupminstock` makes its own groups and products and asserts exact shortfalls (25
assertions), and `.devtools/frontend/group-min-stock.js` — invoked by the `frontend-security`
job, not merely placed beside the other probes — enters a fractional minimum through the
form, reopens it, and clicks a short group to check the row it filters to is there. That
browser check was confirmed to fail when the controller widening is removed.
