# ADR-0022: An opened container's remaining contents are measured on the stock entry

- **Status:** Proposed
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-09.
- **Referenced by:** [28](../plans/28-open-container-measurement.md), which owns the work.
  Interacts with [07](../plans/07-nested-products.md) and its
  [question 6](https://github.com/datagen24/victual/issues/82), which is unanswered at the
  time of writing; this record does not depend on that answer and does not supply it.

## Context

**The fork counts pieces.** A stock unit of "fluid ounce" makes per-unit stock labels
mint one label per ounce, so anything that is labelled has to be held in the container it
comes in: cans, jugs, bags. `AddProduct` writes one stock entry per unit when
`$stockLabelType = 2` (`services/StockService.php:204`, loop at `:294`), and
`products.default_stock_label_type` makes that a per-product default. Counting is
therefore not a preference here; it is what the label subsystem requires of anything it
labels.

**But a product has exactly one stock unit.** `products.qu_id_stock` is a single column,
and `stock` has no unit column of its own — an entry is an amount and a product
(`db/pgsql/baseline/01_tables.sql:316-333`). So the stock unit answers two different
questions with one value: *how many are on the shelf* and *how much is inside*. While it is
"jug", the second question has no answer at all.

**Upstream's answer is tare weight handling, and it assumes one container.**
`products.enable_tare_weight_handling` and `products.tare_weight` implement "weigh the
container, subtract the tare, that is what is inside". Reading the three paths that use it:

| Path | Line | Arithmetic |
|---|---|---|
| `AddProduct` | `StockService.php:241` | `$amount - $productDetails->stock_amount - $product->tare_weight` |
| `ConsumeProduct` | `StockService.php:573` | `abs($amount - $productDetails->stock_amount - $product->tare_weight)` |
| `InventoryProduct` | `StockService.php:1450` | compares `$newAmount` against `$productDetails->stock_amount + $containerWeight` |

Three structural limits follow, and they are limits of the design rather than defects in
it:

1. **One tare per product.** A glass canister and a paper sack of the same sugar have
   different tares. There is one column.
2. **The arithmetic is scoped to the product's total, not to an entry.** Every path above
   subtracts `$productDetails->stock_amount` — the sum across every entry of that product.
   Weighing one open bag while two sealed bags sit beside it subtracts all three. The
   feature is correct only where exactly one container of the product exists, which is the
   refillable-canister case it was built for.
3. **Tare is denominated in the stock unit**, so the mechanism works only where the stock
   unit is already a weight.

**So the two things cannot coexist.** A product can be counted in pieces or measured by
weight, never both, and the choice is made once for every container of that product. "Three
sealed bags of flour and one open bag holding 1.2 kg" is not expressible.

## Decision (proposed)

### 1. The remaining contents of an opened unit are recorded on the stock entry

A `stock` row for an opened unit carries a measured remainder: an amount, the quantity unit
it was measured in, and when it was measured. This is a property of *that container*, not
of the product, because it is a fact about one physical object and changes when someone
pours from it.

### 2. `stock.amount` keeps its present meaning and is not redefined

An entry's `amount` continues to count units of the product's stock unit. An opened jug is
still one jug on the shelf until it is finished. The measurement is an additional field
beside it, never a rewriting of it.

This is deliberate and it is the reason this record is compatible with
[ADR-0005](0005-wire-contract-is-the-invariant.md). Folding a fraction into `amount` would
change what `/stock`, `stock_current`, and [18](../plans/18-mqtt-state-publication.md)'s
published state *mean* without changing their shape, which is the harder kind of contract
break to detect. Additive fields are visible in a response snapshot; changed semantics are
not.

### 3. The fraction of a unit is derived through the conversions that already exist

`quantity_unit_conversions` carries per-product factors (`product_id` is nullable —
`db/pgsql/baseline/01_tables.sql:223-230`) and `quantity_unit_conversions_resolved` closes
them transitively (`db/pgsql/baseline/03_views_group2.sql:70`). A product that records "1
bag = 5 lb" has already stated its net contents, so 1.2 lb remaining is 0.24 of a bag by
the data that is there for other reasons.

**No net-contents column is added, and no density concept is introduced.** Measuring a
volume container by weight is expressible as an ordinary per-product conversion — "1 gallon
jug = 8.6 lb" is that jug's density, written in the units the person actually has. The
constraint this imposes is stated rather than hidden: the measurement unit must be
convertible to the product's stock unit, and the system will not invent a mass-to-volume
factor it was not given.

### 4. Tare belongs to the measurement, not to the product

Where a measurement is a gross weight, the container weight is recorded with that
measurement. Decision 1 makes this possible; limit 1 above makes it necessary.

### 5. A measured entry is never compacted

`CompactStockEntries()` merges split entries (`services/StockService.php:2493`) and
`stock_splits` already excludes two classes from merging — per-unit entries by their `x`
stock-id prefix, and entries carrying userfields (`db/pgsql/baseline/03_views_group3.sql:78-79`).
A measured remainder is per-container state of exactly that kind and joins the exclusion.

### 6. Counting and measuring are per-product, not a mode

Whether a product's opened units are measured is product configuration. Soda is counted and
never measured; flour is both counted and measured; a sack of rice may be measured and
barely counted. Nothing here makes measurement mandatory or global.

### 7. The product-level tare fields stay on the wire and stop being the mechanism

`enable_tare_weight_handling` and `tare_weight` are returned by `/objects/products` today.
Removing them is a wire change with its own argument to make, so this record does not make
it: new work uses the per-entry measurement, and whether the product-level pair is retired
is open question 1 below rather than a consequence of accepting this.

## Consequences

**The stock unit stops carrying two meanings.** Counting pieces becomes safe rather than
lossy, which is what makes it available as the answer to the container question at all.
Without this record, choosing pieces means permanently not knowing that a jug is half empty.

**A pooled parent's total can be honest.** Where a parent product aggregates container-sized
children, `stock_current` converts each child's stock unit into the parent's through
`cache__quantity_unit_conversions_resolved` and assumes every unit is full — a half-finished
2 L bottle still reports its full volume. A measured remainder is what lets that total
reflect the shelf.

**It collides with [07](../plans/07-nested-products.md) in one view.** Making the roll-up
read measurements rewrites `stock_current`'s aggregation, and 07's recursive
`products_resolved` rewrites the same aggregation. Sequencing them apart means touching
that view twice and re-reasoning the second change against the first, which is why
[28](../plans/28-open-container-measurement.md) is scheduled into wave 4 beside the nesting
work rather than after it.

**The wire additions are additive but time-boxed.** New fields on `/stock` and
`/stock/entry` are permitted under ADR-0005, but
[14](../plans/14-contract-and-regression-scaffolding.md) piece 2
([issue 83](https://github.com/datagen24/victual/issues/83)) freezes the response contract
in wave 5. Landing after that freeze makes an additive change into a contract amendment.

**A conversion that is missing fails quietly, in two places now.** `stock_current`'s roll-up
falls back to `COALESCE(qucr.factor, 1.0)` (`db/pgsql/baseline/04_views_l1a.sql:50`), so a
child without a conversion to its parent is summed as though one can equalled one bottle.
The derived fraction in decision 3 reads the same table and inherits the same failure mode.
This is one data-discipline problem, not two, and plan 28 owns naming what enforces it.

**Costs.** One migration, the three tare-aware service paths, the open dialog, at least one
view, and the fixtures for a product holding sealed and measured units at once. This is not
a schema-only change: the arithmetic in `AddProduct`, `ConsumeProduct` and
`InventoryProduct` is where the single-container assumption actually lives.

## Options considered

**Make `stock.amount` fractional on open.** Pour eight ounces from a gallon and the entry
reads 0.9375. Rejected: it changes the meaning of every consumer of `amount` without
changing any response shape, and it discards the measurement — the fact recorded is "1.2 kg
remains", and a fraction is a lossy rendering of it against a factor that may later be
corrected.

**Add a net-contents column to `products`.** Rejected as redundant: the per-product
quantity unit conversion states the same thing, is already required for the pooled roll-up,
and cannot drift out of agreement with itself.

**Fix the existing tare fields to be per-entry.** Rejected as a rename of the problem. The
single-container assumption is in the arithmetic, not in the column, and the existing
fields are on the wire; moving them is a contract change that buys a worse version of
decision 1.

**Model an opened unit as a separate product.** Rejected: it multiplies the catalogue by
the thing this fork is trying to keep small, and an opened bag is not a different thing to
buy.

## Acceptance prerequisites

1. **The coexistence case, against PostgreSQL.** One product holding three sealed units and
   one opened unit with a measured remainder, with the product total correct — accompanied
   by a negative control showing the present tare path computing it wrongly, so the record
   demonstrates the limit it claims rather than asserting it. The limits in *Context* are
   source reads, not measurements, and this prerequisite is what converts them.
2. **A volume container measured by weight**, showing that a per-product conversion supplies
   the factor and that no density concept was required.
3. **Compaction demonstrated to skip a measured entry**, with a control confirming an
   unmeasured split entry is still merged.
4. **The wire additions confirmed additive** against the response snapshot, and the result
   reconciled with 14 piece 2's freeze date.
5. **A decision recorded on open question 1** — whether `products.tare_weight` and
   `enable_tare_weight_handling` are retired — including its wire consequence, since
   accepting this record without settling it leaves two mechanisms for one job.

## Open questions

1. **Are the product-level tare fields retired?** Decision 7 keeps them deliberately. They
   are on `/objects/products`, so retirement is an ADR-0005 question, and leaving two
   mechanisms in place is its own cost.
2. **Does a measurement book a consumption?** Measuring 1.2 kg where the system believed
   1.6 kg remained is either an inventory correction with a `stock_log` booking, or a
   silent state update. The first is consistent with how every other amount change is
   recorded here; the second is what people will expect from putting a jar on a scale.
3. **Per-entry tare, or net measurements only?** Decision 4 records a container weight with
   the measurement. Asking for a net figure instead is simpler and pushes the subtraction
   onto the person holding the scale.
4. **Does an opened, measured unit still satisfy a minimum stock amount?**
   `products.treat_opened_as_out_of_stock` defaults to 1, which answers this today by
   ignoring what is left. A measured remainder makes a better answer possible and does not
   by itself choose one.
5. **Which products are measured in practice?** Configuration rather than schema, but the
   answer decides whether this feature is used by a handful of staples or by most of the
   catalogue, and therefore how much the UI cost matters.
