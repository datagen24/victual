# 28. Measuring what is left in an opened container

**Goal:** Three sealed bags of flour and one open bag holding 1.2 kg is a state the system
can hold. An opened unit's remaining contents are measured — by weight or by volume,
whichever the person can actually take — without giving up counting the units themselves.
**Depends on:** [ADR-0022](../adr/0022-open-containers-carry-a-measured-remainder.md),
**Proposed**. Nothing else blocks it.
**Interacts with:** [07](07-nested-products.md), which rewrites the same aggregation in
`stock_current`, and with the container decision recorded below that came out of the same
review. Scheduled into wave 4 beside 07 for that reason.
**Consumed by:** nothing yet. [22](22-medication-tracking.md) is the obvious later
customer — a part-used bottle is the same shape of problem — but that plan is unscheduled
and this one does not wait for it.
**Status:** draft for review.

## Why this exists as its own plan

It arrived out of the [07 question 6](https://github.com/datagen24/victual/issues/82)
review, and it is not part of 07. 07 asks what `parent_product_id` means. This asks where
"how much is left" lives. They met because answering the first forced a stock unit of
*pieces*, and pieces are what make the second question unanswerable today.

The container decision that came out of that review is recorded here because nothing else
owns it yet: **per-unit labelling requires a stock unit of pieces, one stock unit per
product means one product per container size, and a pooled parent supplies the combined
total.** An 8 oz can, a 12 oz can, a 16 oz bottle and a 2 L bottle of one soda are four
products under one parent, not one product measured in fluid ounces. That is a consequence
of `default_stock_label_type` rather than a preference, and 07's plan should be read against
it once question 6 is answered.

## Today

`stock` is an amount, a product and no unit (`db/pgsql/baseline/01_tables.sql:316-333`).
The unit comes from `products.qu_id_stock`, one per product. `open` is a flag with an
`opened_date` beside it, and that is the whole of what the system knows about a container
that has been started.

The one existing mechanism for measuring contents is tare weight handling —
`products.enable_tare_weight_handling` and `products.tare_weight`. It is used in three
places, and every one of them computes against the product's *total* stock rather than a
single entry:

```php
// AddProduct, StockService.php:241
$amount = $amount - $productDetails->stock_amount - $productDetails->product->tare_weight;

// ConsumeProduct, StockService.php:573
$amount = abs($amount - $productDetails->stock_amount - $productDetails->product->tare_weight);

// InventoryProduct, StockService.php:1450
if ($newAmount == $productDetails->stock_amount + $containerWeight)
```

So the feature is exact where a product has exactly one container — the refillable canister
it was designed for — and silently wrong the moment a second sealed unit exists beside the
open one, because the sealed unit's amount is inside `$productDetails->stock_amount` and
gets subtracted along with the tare. There is also one tare per product, and it is
denominated in the stock unit, so it only functions where the stock unit is already a
weight.

**The three limits are source reads, not measurements.** ADR-0022's acceptance prerequisite
1 is what turns them into a demonstrated result, with a negative control. Nothing below
should be implemented on the strength of this section alone.

## Proposed change

### Schema

Additive columns on `stock`:

| Column | Purpose |
|---|---|
| `opened_amount DOUBLE PRECISION` | measured contents remaining, in `opened_qu_id` |
| `opened_qu_id INTEGER` | the unit it was measured in |
| `opened_tare DOUBLE PRECISION` | container weight, where the measurement was gross |
| `opened_measured_at TIMESTAMP` | when, so a stale figure is visible as stale |

A check constraint keeps the group coherent: a measurement exists only where `open = 1`
**and `amount = 1`**, and `opened_amount` and `opened_qu_id` are present or absent together.
`opened_tare` is independently nullable — a net measurement is a legitimate input, per
ADR-0022 open question 4. Its review response recommends storing net contents in
`opened_amount` and subtracting tare in `opened_qu_id` only when the input is explicitly
marked gross; the API contract must settle this before implementation.

**`amount = 1` is the load-bearing half.** A `stock` row is not inherently one container.
`OpenProduct` marks a whole entry open in place when the requested amount covers it, leaving
`amount` alone (`services/StockService.php:1628-1632`), so three jugs bought without per-unit
labelling are one row that becomes `open = 1, amount = 3`. One remainder on that row names no
particular jug. Measuring an entry holding more than one unit therefore splits it first —
the operation `OpenProduct`'s else branch already performs when opening part of an entry
(`:1636`).

**Rows already in that state are a migration case, not a hypothesis.** Any entry that is
`open = 1` with `amount > 1` when 0277 runs either gets split by the migration or stays
unmeasurable until someone splits it. The migration has to choose, and say so in its own
comment.

**The columns above are not the whole schema.** They are what `stock` carries; the ledger
needs the measurement too, for the reason in *Undo, edit and split* below.

No column is added to `products`. The net contents of a unit is already stated by the
per-product row in `quantity_unit_conversions`, which the pooled-parent roll-up needs
anyway.

### Derivation

The fraction of a stock unit remaining is `opened_amount` converted into `qu_id_stock`
through `quantity_unit_conversions_resolved`, which already closes conversions transitively
and lets a per-product factor override a global one
(`db/pgsql/baseline/03_views_group2.sql:70`).

**A missing conversion refuses; it does not approximate.** Per ADR-0022 decision 3, a
measurement in a unit that does not convert to the stock unit is refused when it is recorded,
and a fraction that cannot be derived is reported as unavailable rather than computed from an
assumed factor. Deleting a conversion that recorded measurements depend on is refused, or
renders those fractions unavailable — never a silent reinterpretation. A warning is not an
alternative to this: a warning does not make kilograms convertible to bags.

**This is deliberately not the same rule as the parent roll-up's.** `stock_current` resolves
a missing child-to-parent factor as `COALESCE(qucr.factor, 1.0)`
(`db/pgsql/baseline/04_views_l1a.sql:50`), so a missing "1 can = 12 fl oz" already makes a
pooled parent add cans to bottles as though they were the same size. That is pre-existing
behaviour, it is not this plan's to change, and the measured fraction does not inherit it.
Whether the roll-up should also become strict is a separate question against a shipped
contract, noted in open question 2.

### Views

`stock_current` gains a figure for measured contents, kept separate from `amount` and from
the existing `amount_opened` so that no present column changes meaning. Whether the pooled
parent's aggregate consumes the measurement, or whether that is a second column beside the
optimistic total, is open question 3 — and is the point at which this plan and 07 touch the
same lines.

### API

Additive fields on `/stock/entry` and on the stock reads, permitted under
[ADR-0005](../adr/0005-wire-contract-is-the-invariant.md). **This wants to land before
[14](14-contract-and-regression-scaffolding.md) piece 2**
([issue 83](https://github.com/datagen24/victual/issues/83)) freezes the response contract
in wave 5; afterwards the same change is an amendment to a frozen contract rather than an
addition to an open one.

### UI

The open action grows an optional measurement: an amount, a unit drawn from the product's
own conversions, and a tare field shown only where the product is configured for gross
weights. The stock entry row shows what is left and how long ago it was measured. Re-measuring
is the same control used again, which is the common case — a bag of flour is measured many
times over its life.

### Undo, edit and split

`UndoBooking` does not restore a `stock` row, it **rebuilds** one from `stock_log`: the
consume branch calls `$this->DB->stock()->createRow([...])` populated entirely from the log
row (`services/StockService.php:2200-2213`). Its own comment states the pattern —
*"The open flag itself is not logged, so it is derived from the logged opened date."* So a
measurement held only on `stock` is lost the moment an entry is fully consumed and the
consumption is undone, and it is lost silently.

The measurement is therefore durable in the ledger as well, recorded where the undo path can
read it back alongside `opened_date`. Three paths need working through, not just the first:

- **Undoing a consumption** restores the remainder, its unit, its tare and its timestamp.
- **Undoing an opening** clears `open` and `opened_date`
  (`services/StockService.php:2280-2290`), which would strand a measurement on a closed
  entry. ADR-0022 decision 9 requires that undo to clear the measurement: an unopened
  container has no remainder.
- **Editing and splitting an entry** must carry or drop the measurement deliberately rather
  than duplicating it across both halves, which would double the derived contents.

This is required however ADR-0022 open question 2 is settled. Whether re-measuring books a
consumption or updates state silently, undoing a consumption still has to restore what was
measured.

### Service paths

`AddProduct`, `ConsumeProduct` and `InventoryProduct` are where the single-container
assumption actually lives, and they are the bulk of the work rather than the schema.
`CompactStockEntries()` must skip measured entries; `stock_splits` already excludes
per-unit and userfield-bearing entries and is the right place to add the third exclusion
(`db/pgsql/baseline/03_views_group3.sql:78-79`).

### Migration

One PostgreSQL-only migration, claimed in [RESERVATIONS.md](../../migrations/RESERVATIONS.md).
The SQLite line is frozen at 0265, so this is a lone `.pgsql.sql` file. Per the
lowest-free-slot rule the table has now applied nine times, the number moves down if a
plan holding a lower reservation has still not written a file when this one does.

## Interaction with 07

Both plans rewrite `stock_current`'s aggregation: 07 makes `products_resolved` recursive so
the roll-up walks a tree, this plan makes the roll-up account for units that are partly
gone. Done separately, the second change has to re-derive the first, and the view is the
one plan 08's Executed section already describes as the most-touched surface in the stock
subsystem.

Done together, one rewrite carries both, and the fixtures are shared — a parent with
container-sized children, some sealed and some measured, is the case that exercises
everything either plan claims.

**This plan does not depend on question 6's answer.** Whether the taxonomy lives in
`product_groups` or in `parent_product_id` changes what sits above a product; measurement
sits below it, on the entry.

## Verification

`.devtools/pgsql/difftest.php` over the new and touched views, and a suite phase in the
shape [08](08-nested-locations.md)'s `locations` phase took — PostgreSQL-only for the same
structural reason, since the view phase seeds through the importer's common-column logic
and every new column would arrive NULL. The cases that matter:

- three sealed units and one measured open unit of one product, total correct
- the same case against the present tare path, wrong, as the negative control
- **two opened containers of one product, one measured and one not**, with the measurement
  attached to exactly one of them and both totals correct
- **an entry with `open = 1, amount = 3`** — the state `OpenProduct` produces today — shown
  to be refused or split rather than accepted with one remainder, and the migration's chosen
  handling of pre-existing such rows exercised
- **an undo round trip**: measure, consume the entry fully, undo the consumption, and find
  the remainder, unit, tare and timestamp restored
- **undoing an opening on a measured entry**, leaving a state the constraint permits
- **a split of a measured entry**, with the measurement carried or dropped once, never twice
- **a measurement in an unconvertible unit**, refused at entry rather than stored
- **a conversion deleted after measurements exist**, leaving fractions unavailable rather
  than reinterpreted
- a volume container measured by weight, resolved through a per-product conversion
- a measured entry left alone by `CompactStockEntries()`, with an unmeasured split entry
  merged in the same run as the control

A browser probe for the open dialog, in the shape of `.devtools/frontend/nested-locations.js`
and invoked by the `frontend-security` job rather than merely placed beside it — plan 08's
Executed section records that distinction being missed.

## Open questions

1. **Does a measurement book a consumption?** ADR-0022 open question 2. Specify how a
   changed remainder is recorded without changing the container count.

   > **Response:** Review recommendation, 2026-09-09: record a reversible measurement with
   > before/after state rather than infer consumption from the difference or update silently.
   > See [ADR-0022's responses](../adr/0022-open-containers-carry-a-measured-remainder.md#open-questions).
   > The ledger representation, permission and interaction with existing consumption bookings
   > remain to be designed; this is not a maintainer decision.

2. **Should the parent roll-up become strict too?** Settled for this plan: a measurement
   that cannot be converted is refused, and an underivable fraction is unavailable. What is
   open is the *pooled parent's* `COALESCE(qucr.factor, 1.0)`, which is shipped behaviour on
   a live contract — making it strict is a change to what `/stock` returns for a
   misconfigured catalogue, so it needs its own argument and probably its own record. This
   plan neither changes it nor copies it.
3. **Does the pooled parent's total consume the measurement, or sit beside it?** Folding it
   in gives one honest number and changes what an existing column means. Keeping it beside
   preserves the present column and asks every consumer to choose. This is the line where
   this plan and 07 edit the same code.
4. **Is the product-level tare pair retired?** ADR-0022 open question 1 and its acceptance
   prerequisite 8. Two mechanisms for one job is a cost; removing fields from
   `/objects/products` is a contract change.
5. **Which products are measured?** Configuration, not schema — but it decides whether the
   UI cost falls on a handful of staples or on most of the catalogue.

## Effort

Medium, and dominated by the service paths rather than the schema. The migration is an
afternoon; the tare-aware arithmetic in three methods, the undo, edit and split paths, the
compaction exclusion, the view change and the fixtures for a product holding sealed and
measured units at once are the rest. The ledger work in *Undo, edit and split* is the part
most likely to be underestimated — it reaches into booking history rather than one table. Sharing wave 4's `stock_current` rewrite with 07 is what keeps it from being counted
twice.
