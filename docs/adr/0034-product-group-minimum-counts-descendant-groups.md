# ADR-0034: A product group's minimum stock counts its descendant groups

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-26, from the maintainer's decision in the issue
  [487](https://github.com/datagen24/victual/issues/487) remediation session the same day.
- **Relationship:** Answers [ADR-0023](0023-taxonomy-is-groups-packaging-is-parent-product.md)
  open question 1, restated as [plan 30](../plans/landed/30-nested-product-groups.md) open
  question 1. Neither ADR-0023's four decisions nor their 2026-09-14 acceptance are reopened
  or changed by this record.
- **Referenced by:** [issue 508](https://github.com/datagen24/victual/issues/508) (M8);
  `migrations/0268.pgsql.sql`'s `product_groups_missing` view; `migrations/0278.pgsql.sql`'s
  `product_groups_resolved` view, which supplies the mechanism.

## Context

`product_groups_missing` (`migrations/0268.pgsql.sql:64-97`, [plan 03](../plans/landed/03-category-min-stock.md))
reports a group as short by joining it only to its direct member products: the inner
`member` subquery groups by `p.product_group_id`, and the outer join condition is
`member.product_group_id = pg.id` (`migrations/0268.pgsql.sql:90-91`). A product filed in a
subgroup contributes nothing to an ancestor group's shortfall.

[Plan 30](../plans/landed/30-nested-product-groups.md) added group nesting and, alongside
it, `product_groups_resolved` (`migrations/0278.pgsql.sql:67-124`): a closure view of
`(ancestor_product_group_id, descendant_product_group_id, depth, path)` pairs, including
every group paired with itself at depth 0 (`migrations/0278.pgsql.sql:94-100`) — the
mechanism a roll-up join would use. Plan 30's own Executed section states plainly that
`product_groups_missing` "is unaffected" by migration 0278 and that "Question 1 — does a
group minimum roll up to descendant groups — is still open"
(`docs/plans/landed/30-nested-product-groups.md:257-261`). ADR-0023's open question 1
restates the same question and defers its answer to plan 30
(`docs/adr/0023-taxonomy-is-groups-packaging-is-parent-product.md:289-292`).

Issue #508 (M8), validated against master `edd7f91e3e654f8a06442810335efdebe4599c14` on
2026-09-26, reproduces today's behavior directly: a parent group with
`min_stock_amount = 1` has a child group under it, and a product filed in the child group
holds 5 units. `product_groups_missing` still reports the parent short by its whole
minimum. Reproduce with `SELECT * FROM product_groups_missing WHERE id = <parent group id>`
after inserting the tree above and purchasing 5 units of the child's product.

The issue's required outcome is to decide the semantics and disclose them, and to "not
present recursive aggregation as already accepted."
`docs/manual/using-victual/stock.md:66-67` already does exactly that: "groups can nest, and
a group's own minimum stock amount rolls up from its members' shortfalls" is written as
current behavior. It is not true today. Since nothing described below is built, it will not
become true merely by this decision either. The sentence needs correcting independently of
this record's outcome, and this record does not perform that correction.

Plan 03 recorded, when the view was direct-members-only, that amounts are "summed in each
member's own stock quantity unit, with no conversion, … a stated limitation rather than a
defect to fix later" (`docs/plans/landed/03-category-min-stock.md:63-68`). A group of two
litres of milk and three pieces of cheese is not meaningfully "five" of anything, and
giving a group a declared unit was explicitly left open. This is a property of today's flat
view, independent of any roll-up decision.

`product_groups_missing` is read-only and additive: plan 03 chose "do not auto-add" for its
shopping-list question (`docs/plans/landed/03-category-min-stock.md:125-129`), so nothing
subtracts stock or writes a booking from this view today.
`controllers/StockController.php:273` joins it
(`JOIN product_groups_missing pgm ON p.product_group_id = pgm.id`) to widen the stock
overview, so a short group's own products are rendered for the "click the group name to
filter" action plan 03 designed (`docs/plans/landed/03-category-min-stock.md:82-107`). This
join is also direct-membership-only today, and it would need to change with the view to
keep that action working for a group short only through a descendant.

## Decision

The maintainer decided: **stock of products in all descendant groups counts toward an
ancestor group's minimum.** The view's member join changes from a direct
`member.product_group_id = pg.id` comparison to one that reaches every group in `pg`'s
subtree through `product_groups_resolved` (`ancestor_product_group_id = pg.id`), so a
group's reported shortfall continues to include its own direct members — the self-pair at
depth 0 — as well as every descendant's.

## Consequences

- **For the view:** this is a migration. `product_groups_missing`'s `member` join is
  rewritten to join through `product_groups_resolved` rather than directly on
  `product_group_id`. No other column of the view changes.
- **For the UI:** `controllers/StockController.php:273`'s widening join needs the same
  change. Without it, a group reported short through a descendant's shortfall is named on
  the overview (per plan 03's design), but the products a shopper would need to see stay
  filtered out by `is_in_stock_or_below_min_stock` and are never rendered. That breaks the
  "click the group name, see its products" action from plan 03, for exactly the case this
  decision adds.
- **For the Manual:** `docs/manual/using-victual/stock.md:66-67`'s existing sentence
  becomes accurate only once this decision is implemented; it is inaccurate today
  regardless of this decision (see Context), and correcting or annotating it is separate
  work this record does not perform.
- **A product counted toward both a child's and a parent's minimum.** With roll-up, one
  product's stock can be the entire reason both its own direct group and every ancestor
  group report a shortfall. Each row stays independently true: plan 03 Q2 already
  established that a group's shortfall and a product's own shortfall coexist without
  cancelling. Nothing consumes these rows automatically (plan 03 Q1: "do not auto-add"), so
  this is a display duplication today, not a double deduction from stock. Whether a future
  shopping-list integration reading this view must deduplicate a product appearing under
  more than one short group is not decided here.
- **Mixed units across member products.** Today's view already sums each member's stock in
  its own unit with no conversion, for members directly in one group (Context, plan 03).
  Roll-up does not introduce a new unit hazard; it widens the existing one across more
  products, and is expected to be more visible in a real tree. The observed three-level
  spice tree from ADR-0023 is `Spices / Garlic / Fresh`. A `Spices` root given its own
  minimum would sum ground spice in grams, whole cloves in pieces, and any liquid extract in
  millilitres, as though they were one quantity.

## Options considered

**A. Direct members only (status quo).** What issue #508 confirms is running today. Rejected
by the maintainer's decision above; recorded because it is what the accepting pull request
replaces.

**B. Roll-up through `product_groups_resolved`.** The decision. Not a competing design so
much as reuse: plan 30 already built this closure view for group-tree navigation, and it
supplies exactly the ancestor/descendant join a roll-up needs without a second recursive
structure.

## Open questions

1. **A product counted toward both a child's and a parent's minimum** (Consequences). No
   decision is needed today because nothing auto-adds from this view, but it must be
   resolved before any shopping-list automation reads `product_groups_missing` (plan 03
   Q1's deferred note-only follow-up, plan 30 Q2).
2. **Mixed units across member products** (Consequences). Unresolved before this decision
   and unresolved after it. Giving a group a declared unit, or converting members into one,
   remains a schema question no plan has opened.

## Acceptance prerequisites

1. The updated join is demonstrated against a fixture with a three-level tree (mirroring
   ADR-0023's `Spices / Garlic / Fresh` shape): a root minimum is satisfied by a leaf
   descendant's stock, and a group's own direct members still count when it has no
   descendants.
2. `controllers/StockController.php:273` is updated in the same change, with a regression
   showing the overview renders a descendant's products when only an ancestor group is
   reported short.
3. `docs/manual/using-victual/stock.md:66-67` is reconciled with whatever this decision's
   implementation actually ships — corrected to describe roll-up accurately if it ships with
   this change, or corrected to state that roll-up is proposed rather than current if
   implementation is deferred past this record's acceptance.
