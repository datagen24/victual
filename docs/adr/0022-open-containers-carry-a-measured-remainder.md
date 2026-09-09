# ADR-0022: An opened container's remaining contents are measured on the stock entry

- **Status:** Proposed
- **Decider:** datagen24 (maintainer). Acceptance follows the [ADR lifecycle](README.md).
- **Recorded:** 2026-09-09.
- **Referenced by:** [28 — Open container measurement](../plans/28-open-container-measurement.md),
  which owns implementation, and
  [29 — Working container replenishment](../plans/29-working-container-replenishment.md),
  which consumes decision 4's entry-scoped tare for the vessel pattern. Interacts with [07 — Nested products](../plans/07-nested-products.md)
  but does not depend on or settle its [question 6](https://github.com/datagen24/victual/issues/82).

## Context

Per-unit stock labelling requires container units such as cans, jugs or bags.
[`StockService::AddProduct()`](../../services/StockService.php) creates one stock entry per
unit when `$stockLabelType = 2`, selected through `products.default_stock_label_type`.
Using fluid ounces as the stock unit would create one label per ounce in that mode.

A product has one `qu_id_stock`, and each `stock` entry records its amount in that unit;
see the [baseline tables](../../db/pgsql/baseline/01_tables.sql). Counting jugs therefore
does not record how much remains inside an opened jug. The required state is three sealed
bags of flour and one open bag holding 1.2 kg, while retaining the count of four bags.

Existing tare handling uses `products.enable_tare_weight_handling` and `products.tare_weight`.
Its three paths in [StockService](../../services/StockService.php) operate on product totals:

| Path | Arithmetic |
|---|---|
| `AddProduct()` | `$amount - $productDetails->stock_amount - $product->tare_weight` |
| `ConsumeProduct()` | `abs($amount - $productDetails->stock_amount - $product->tare_weight)` |
| `InventoryProduct()` | Compares `$newAmount` with `$productDetails->stock_amount + $containerWeight` |

This supports a single refillable container, with three limits:

1. There is one tare per product, although a glass canister and a paper sack have different tares.
2. Weighing one container subtracts the stock amount of every entry of that product,
   including sealed containers beside it.
3. Tare is expressed in the stock unit, so weight measurements require a weight stock unit.
4. A tare-enabled product can be neither opened nor transferred. Both are explicit refusals,
   not gaps: `OpenProduct()` throws "Opening tare weight handling enabled products is not
   supported", and `TransferProduct()` throws "Transferring tare weight enabled products is
   not yet possible" ahead of unreachable tare arithmetic.

Limit 4 decides the shape of the mechanism rather than merely constraining it. Tare handling
models one product as one vessel and nothing else, so it cannot express backstock feeding a
working container: the bags cannot be transferred into the bin, and the bin cannot be opened.
Any design where a vessel is replenished from stock held elsewhere needs tare somewhere other
than on the product.

These are source-derived limits, not measured results. Acceptance prerequisite 1 requires
an executable demonstration with a negative control.

## Decision (proposed)

### 1. The remaining contents of an opened unit are recorded on the stock entry

An opened container's `stock` row carries its measured remainder, measurement unit and
measurement timestamp. These describe one physical container rather than the product.

### 2. `stock.amount` keeps its present meaning and is not redefined

`amount` remains a count of the product's stock units. An opened jug remains one jug until
it is finished; its measured contents are recorded separately. Replacing that count with a
fraction would change existing `/stock`, `stock_current` and
[MQTT publication](../plans/18-mqtt-state-publication.md) semantics, contrary to
[ADR-0005](0005-wire-contract-is-the-invariant.md).

### 3. The fraction of a unit is derived through the conversions that already exist

Use per-product `quantity_unit_conversions` and the transitive
[`quantity_unit_conversions_resolved`](../../db/pgsql/baseline/03_views_group2.sql) view.
Given `1 bag = 5 lb`, a measured remainder of 1.2 lb is 0.24 bags.

No net-contents column or density model is added. A per-product conversion such as
`1 gallon jug = 8.6 lb` also permits measuring a volume container by weight.

Reject a measurement whose unit cannot be converted to the product's stock unit. If a
stored measurement later becomes unconvertible, report its derived fraction as unavailable;
never assume a factor. Deleting a required conversion must either be refused or leave
those fractions unavailable.

This does not change the existing child-to-parent fallback in
[`stock_current`](../../db/pgsql/baseline/04_views_l1a.sql),
`COALESCE(qucr.factor, 1.0)`. The new measured fraction must not use that fallback.

### 4. Tare belongs to the measurement, not to the product

For a gross-weight measurement, record the container weight with the measurement so each
container can have its own tare.

Per-entry tare serves two verbs, and both are in scope for this decision:

- **A remainder beside `amount`**, for an opened purchased container that is partly used.
  This is decisions 1 and 8.
- **An entry-scoped tared correction**, for a vessel whose `amount` *is* its contents — a
  decanted bin of flour. No remainder column applies; what is needed is to set one entry's
  amount from a gross weight.
  [`EditStockEntry()`](../../services/StockService.php) already takes a stock row id and an
  amount and performs no tare arithmetic, which is where that belongs.

The two patterns differ in what they store and agree on what they need. Recording tare on the
entry is the single primitive that unblocks both, and it is what limit 4 makes unavoidable:
product-scoped tare cannot participate in a transfer at all.

### 5. A measured entry is never compacted

Exclude measured entries from `CompactStockEntries()`. The
[`stock_splits`](../../db/pgsql/baseline/03_views_group3.sql) view already excludes per-unit
entries with an `x` stock-id prefix and entries carrying userfields. Measurement adds a
third exclusion; decision 8 separately handles rows that already contain several units.

### 6. Counting and measuring are per-product, not a mode

Measurement is optional product configuration. A product may be counted without measuring
its opened units; neither measurement nor a global measurement mode is required.

### 7. The product-level tare fields stay on the wire and stop being the mechanism

New measurement work uses per-entry state. The existing `enable_tare_weight_handling` and
`tare_weight` fields remain on `/objects/products`; their retirement and compatibility
consequences require the answer to question 1 before acceptance.

### 8. A measurement describes exactly one container, and an entry carrying one holds one unit

A measured entry requires `open = 1` and `amount = 1`. `OpenProduct()` can mark an entire
multi-unit row open without changing its amount, so `open = 1` alone does not identify one
container. For example, three jugs bought without per-unit labels can remain one opened row.

Split a multi-unit entry before measuring one container. `OpenProduct()` already splits
rows when opening fewer units than they hold. The migration must specify whether existing
opened multi-unit rows are split during migration or remain unmeasurable until split.

`OpenProduct()` currently refuses products with tare handling enabled. Supporting opened,
measured containers therefore requires changes to that path as well as the new columns.

### 9. A measurement survives undo, so it is not stored only on `stock`

`UndoBooking()` reconstructs a fully consumed entry from `stock_log`. Persist the remainder,
unit, tare and timestamp in the ledger so undo can restore them after the `stock` row has
been deleted. Edit and split paths must preserve the association with one container and
must not duplicate a measurement across split rows.

Undoing an opening must clear the measurement along with `open` and `opened_date`, keeping
the state consistent with decision 8. These requirements apply regardless of whether
remeasurement itself books a consumption (question 2).

## Consequences

Container counts and remaining contents can be reported separately. A pooled parent can
also report measured contents rather than treating every child container as full; whether
that is an additional aggregate is a question in plan 28.

Plan 28 shares changes to `stock_current` with plan 07's proposed recursive product
aggregation. The [plans index](../plans/README.md) schedules them in wave 4. Coordinate the
view changes and fixtures if both proceed; measurement does not require plan 07's taxonomy
question to be resolved.

New stock response fields require compatibility verification under ADR-0005. Their planned
wave 4 delivery precedes [plan 14's response snapshot](../plans/14-contract-and-regression-scaffolding.md)
([issue 83](https://github.com/datagen24/victual/issues/83)) in wave 5. Later delivery would
also require updating that snapshot contract.

Implementation includes the stock and ledger schema, tare-aware service arithmetic,
opening, undo, edit and split paths, compaction exclusion, the measurement UI and stock
views. Fixtures must cover sealed and measured containers together, conversion failures
and undo. Retiring the existing tare mechanism adds migration and compatibility work.

## Options considered

- **Make `stock.amount` fractional on open.** Rejected because it changes existing response
  semantics and replaces the original measurement with a fraction dependent on a conversion
  factor that may later be corrected.
- **Add net contents to `products`.** Rejected because per-product quantity-unit conversions
  already record the relationship; a second value could disagree with them.
- **Move the existing tare fields to entries.** Rejected because moving columns does not
  fix the product-total arithmetic or separate container counts from contents, and the
  fields are already exposed on the API.
- **Make an opened unit a separate product.** Rejected because opening changes the state
  of a container, not the product being purchased, and would duplicate catalogue entries.

## Acceptance prerequisites

1. **Coexistence, against PostgreSQL.** Three sealed units and one opened, measured unit of
   one product produce the correct total. A negative control demonstrates the existing tare
   path computing it incorrectly, testing the source-derived limits in Context.
2. **Volume measured by weight.** A per-product conversion supplies the factor without a
   separate density model.
3. **Compaction.** A measured entry is skipped while an unmeasured split entry is merged.
4. **Wire compatibility.** Confirm additive responses against the response snapshot and
   reconcile the result with plan 14 piece 2's freeze date.
5. **Container identity.** Two opened containers, one measured, retain separate state and
   correct totals. A multi-unit entry is refused or split before attaching a remainder.
6. **Undo.** Measure an entry, consume it fully, then undo: remainder, unit, tare and
   timestamp are restored. Undoing an opening leaves a legal state.
7. **Conversion failure.** Reject an unconvertible measurement at entry. Deleting a
   conversion required by stored measurements leaves their fractions unavailable, never
   reinterpreted through an assumed factor.
8. **Legacy tare decision.** Record the answer to question 1, including the wire consequence
   of retaining or retiring `products.tare_weight` and `enable_tare_weight_handling`.

## Open questions

Responses below are review recommendations dated 2026-09-09, not maintainer decisions or
completed acceptance prerequisites.

1. **Are the product-level tare fields retired?** Their removal changes `/objects/products`;
   retaining two mechanisms also requires a coexistence policy. Acceptance prerequisite 8
   requires this answer.

   > **Note, 2026-09-09.** Context limit 4 narrows this. The two mechanisms are already
   > mutually exclusive per product in code — a tare-enabled product cannot be opened, so it
   > can never carry a per-entry measurement — so no coexistence policy has to be invented;
   > one exists by refusal. Limit 4 also shows that product-scoped tare cannot participate in
   > a transfer, which per-entry tare can. That makes this a question about superseding a
   > narrower mechanism with a wider one, not about two peers sharing a domain. Removing the
   > fields remains a wire change under
   > [ADR-0005](0005-wire-contract-is-the-invariant.md) and is still unanswered.

2. **Does a measurement book a consumption?** A new measurement may differ from the previous
   remainder. Should that difference be a consumption, an inventory correction or a separate
   recorded measurement?

   > **Response:** Recommend a reversible measurement record with before/after state, rather
   > than an inferred consumption or a silent update. A lower reading does not establish why
   > contents changed, and decision 2 keeps the container count unchanged. Decision 9 requires
   > durable state for undo; [ADR-0012](0012-observations-are-proposals.md) also distinguishes
   > deterministic measurements from probabilistic proposals. Plan 28 still needs to specify
   > the ledger representation, permission and treatment of existing consumption bookings.

3. **How is refusal surfaced?** Decision 3 requires refusal of unconvertible input and an
   unavailable derived fraction when conversion is impossible. The API and UI need a
   representation for each.

   > **Response:** Recommend HTTP 400 with the existing `error_message` envelope for an
   > unconvertible write, leaving stored state unchanged. On reads, retain the original
   > measurement and return an explicit null derived fraction with a machine-readable reason.
   > The UI should show “Conversion unavailable”, not zero or an estimated full container.
   > Plan 28 must specify the additive fields and distinguish no measurement from a missing
   > conversion; the existing error envelope is defined by
   > [`BaseApiController::GenericErrorResponse()`](../../controllers/Api/BaseApiController.php).

4. **Per-entry tare, or net measurements only?** Decision 4 already requires per-measurement
   tare for gross weights; net measurements need no container subtraction.

   > **Response:** Support both, as decision 4 and plan 28's nullable `opened_tare` already
   > propose. Recommend defining `opened_amount` as net contents in `opened_qu_id`; for gross
   > input, subtract tare in that same unit before storing it. This leaves one derivation
   > formula for both input paths. The input contract must make gross versus net explicit
   > so a client cannot subtract tare twice.

5. **Does an opened, measured unit still satisfy a minimum stock amount?**

   > **Response:** Recommend retaining `treat_opened_as_out_of_stock`: when enabled, exclude
   > opened units; otherwise count their unchanged stock amounts. The
   > [`products_missing` view](../../db/pgsql/baseline/05_views_l2.sql) already applies this
   > rule, and the [product schema](../../db/pgsql/baseline/01_tables.sql) defaults the flag
   > to 1. A fractional contents-based minimum would need a separately defined threshold and
   > consumer contract; recording a remainder should not silently change replenishment.

6. **Which products are measured in practice?** This needs catalogue and usage evidence:
   which products are weighed, whether readings are gross or net, and whether a suitable
   stock-unit conversion exists. Product configuration and UI defaults remain open.
