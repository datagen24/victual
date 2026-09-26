# ADR-0032: Stock amounts compare within one tolerance

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-26, from the maintainer's decision in the issue
  [487](https://github.com/datagen24/victual/issues/487) remediation session the same day.
- **Referenced by:** [issue 492](https://github.com/datagen24/victual/issues/492) (H3),
  [issue 470](https://github.com/datagen24/victual/issues/470); extends the `round($x, 2)`
  convention [PR #469](https://github.com/datagen24/victual/pull/469) established for the
  purchase-undo branch to the rest of `services/StockService.php`.

## Context

`stock.amount` is `DOUBLE PRECISION` (`db/pgsql/baseline/01_tables.sql`), and amounts reach
it through repeated addition, subtraction, and SQL `SUM()`
(`StockService::CompactStockEntries()`). Two independent comparison defects follow from
treating that column as if float subtraction landed on exact decimal values, both
reproduced against master `edd7f91e3e654f8a06442810335efdebe4599c14` on 2026-09-26.

**An exact `>=` comparison leaves float residue.** `ConsumeProduct()`'s per-entry loop
decides whether to take a candidate stock entry whole or split it with
`if ($amount >= $stockEntry->amount)` (`services/StockService.php:657`).

Consider a product split across a 0.1 lot and a 0.2 lot, with 0.3 consumed. The first
iteration takes the 0.1 entry whole (`0.3 >= 0.1`) and computes the remainder as
`0.3 - 0.1`. IEEE 754 double arithmetic renders that remainder as `0.19999999999999998`
rather than `0.2`. The second iteration compares this value against the 0.2 entry:
`0.19999999999999998 >= 0.2` is false, so the code takes the "split" branch instead of
"take whole". It writes `$stockEntry->amount - $amount` — `2.7755575615629e-17` — onto the
surviving row instead of deleting it (issue #492).

Reproduce by purchasing 0.1 and 0.2 of a product at the same location and due date, then
consuming 0.3 through `POST /api/stock/products/{id}/consume`.
`SELECT amount FROM stock WHERE product_id = …` shows the residue row rather than an empty
table. The identical exact-comparison pattern recurs at `services/StockService.php:2083`
(`OpenProduct()`) and `services/StockService.php:2438` (`TransferProduct()`). Both are
exposed to the same defect shape, even though only `ConsumeProduct()` was in the audit's
reproduction.

**A second exact-equality site: `UndoBooking()`'s `TRANSACTION_TYPE_TRANSFER_TO` branch**
computes `$newAmount = $stockRow->amount - $logRow->amount` and deletes the row only
`if ($newAmount == 0)` (`services/StockService.php:2894`), an exact test on a value that
reaches this branch through the same float subtraction. Issue #470 names this the shape
[PR #469](https://github.com/datagen24/victual/pull/469) (merged, closed #457) already fixed
in the purchase-undo branch: undoing a transfer can leave "a near-zero phantom stock row" at
the destination. [PR #531](https://github.com/datagen24/victual/pull/531) (state at
2026-09-26: **draft, unmerged**) proposes rounding before comparing in this branch too,
"matching #469's convention" per its own description, but has not merged — the exact `== 0`
test is what runs on master today.

**PR #469's own fix established the pattern this record generalizes.** Rather than an exact
comparison, it rounds to two decimal places before testing for a boundary, in the
purchase/self-production/positive-inventory-correction branch of `UndoBooking()`
(`services/StockService.php:2822`, `$roundedNewAmount = round($newAmount, 2);`, guarded by
`< 0` at `:2824` and `== 0` at `:2832`; the single-row update path at `:2841-2843` writes
back the unrounded `$newAmount`, not the rounded copy). The same `round($x, 2)` convention —
used to decide a boundary, not to change what is stored — recurs at:

| Line | Function | What it decides |
|---|---|---|
| `services/StockService.php:634` | `ConsumeProduct()` | `round($amount, 2) > round($productStockAmount, 2)` — availability |
| `services/StockService.php:829` | `EditStockEntry()` | `round($amount, 2) == 1.0` — ADR-0022 coherence |
| `services/StockService.php:960` | `MeasureStockEntry()` | `round($stockRow->amount, 2) != 1.0` — coherence |
| `services/StockService.php:2021` | `TransferProduct()` | `round($amount, 2) != 1.0` — coherence |
| `services/StockService.php:2027` | `TransferProduct()` | `round($targetEntry->amount, 2) < 1.0` — coherence |
| `services/StockService.php:2822` | `UndoBooking()` | zero/negative test (PR #469) |

Two further `round()` calls in this file round a display quantity already taken from a
decided ledger amount and do not test a boundary: `services/StockService.php:2263` and
`:2295`, both in `GetShoppinglistInPrintableStrings()`, round a quantity for a thermal
printer label. `services/StockService.php:103`
(`AddMissingProductsToShoppingList()`) rounds a missing amount before writing a
`shopping_list` quantity — a different table from the stock ledger. Both are named here
because the sweep behind this record covers every `round($x, 2)` call in the file, not
because either decides a zero test or an availability comparison.

`round($x, 2)` treats every value within 0.005 of a boundary as being at that boundary. For
a product stocked in kilograms that is 4.999 g read as an exact zero; for a product stocked
in individual pieces it can misjudge a genuine fractional remainder as exactly one whole
unit.

**A third, independent gap: nothing refuses a negative amount everywhere.**
`AddProduct()` (`services/StockService.php:239`) and `ConsumeProduct()`
(`services/StockService.php:574`) already refuse `$amount <= 0`. `EditStockEntry()`
(`services/StockService.php:791`), `OpenProduct()` (`services/StockService.php:1955`) and
`TransferProduct()` (`services/StockService.php:2345`) accept whatever `float $amount` a
caller supplies with no lower-bound check anywhere in their bodies. Issue #492 demonstrates
the gap concretely: a `PUT` to a stock entry with `amount: -5` succeeds.

**A related but distinct finding is out of scope here.** Issue #490 (H1) is about consuming
or transferring more than a scoped candidate set actually holds, which silently books less
than requested. That is a defect in which rows are considered, not in how two amounts are
compared once chosen. This record's tracking issue set references #490, but the decision
below does not address it.

## Decision

The maintainer decided:

1. **One named tolerance constant, on the order of 1e-9 of the stock unit, is used for
   every zero test and every availability comparison.** It replaces the `round($x, 2)`
   calls listed in Context that decide a boundary (`:634`, `:829`, `:960`, `:2021`,
   `:2027`, `:2822`) and the exact comparisons at `:657`, `:2083`, `:2438` and `:2894`. The
   printer-quantity and shopping-list `round()` calls (`:103`, `:2263`, `:2295`) are
   unaffected — see decision 3.
2. **Stored amounts stay unrounded.** The tolerance decides whether a comparison treats two
   amounts as equal or a remainder as zero; it never becomes the value written to `stock`.
   `services/StockService.php:2822`'s existing pattern already keeps this distinction —
   compare a rounded copy, write the unrounded `$newAmount` — and this decision generalizes
   the comparison side of that pattern, not the write side.
3. **Display decimals are presentation only and never decide ledger validity.** The two
   printer-quantity roundings and the shopping-list amount-to-add rounding format a value
   already decided by the ledger; none of the three may be read as, or replaced by, a
   zero or availability test.
4. **Negative amounts are refused.** At minimum, `EditStockEntry()` gains the check
   `AddProduct()` already has, adjusted to `< 0` rather than `<= 0`: decision open question 1
   below means a legitimate zero write (a vessel weighed empty through `WeighLocation()`,
   which calls `EditStockEntry()`) must still succeed. The accepting pull request must state
   whether `InventoryProduct()`, `OpenProduct()` and `TransferProduct()` carry the same gap —
   this record's reproduction did not exercise a negative write through them, so it does not
   assert that they do.

**Rejected:**

- **Keep `round(x, 2)` everywhere.** Uniform, but wrong in both directions demonstrated
  above: it loses a real remainder under 0.005 of the stock unit, and it cannot distinguish
  "genuinely zero" from "a few grams" for a large-unit product.
- **`NUMERIC` storage with a fixed scale.** Would remove float noise at the source rather
  than tolerate it at comparison time, but is a schema change with wire-serialization work
  of its own (the column is read as a JSON number on the wire today). Rejected for this
  record; remains a possible later record.

## Consequences

- The residue and phantom-row shapes in #492 and #470 stop occurring at the sites listed in
  decision 1, without changing what a successful write stores.
- **Tightening the coherence checks (`:829`, `:960`, `:2021`, `:2027`) from a 0.005 window to
  roughly 1e-9 narrows what counts as "equal to 1.0" or "equal to zero".** A value that
  `round(x, 2)` today accepts as coherent — for example an amount of `0.998` surviving a
  quantity-unit conversion whose factor is not exact — could be refused under the tighter
  tolerance where it previously passed. The regression suite accepting this record should
  include a case at the old 0.005 boundary to show whether this is a real behavior change or
  only a theoretical one.
- **The negative-amount refusal is a breaking change** for any caller relying on today's
  silent acceptance of a negative edit through `EditStockEntry()` — the point of the
  decision rather than a side effect.
- Zero-amount rows remain permitted; see Open questions.

## Open questions

1. **Zero-amount rows.** `WeighLocation()` writes `amount = 0` for a vessel whose gross
   reading equals its tare weight (`services/StockService.php:2672`'s call into
   `EditStockEntry()` with `$newAmount = 0`) — confirmed current behavior, and identified in
   issue #487's "Corrections to the audit" item 3: "current `WeighLocation` successfully
   writes amount 0. A positive-stock CHECK requires coordinated delete-on-zero behavior,
   history/label handling and tests." Whether "no row may hold 0" becomes its own rule, and
   if so how an emptied vessel is represented instead, is not decided here. This record
   fixes how amounts compare; it does not ban a stored zero.
2. **Which entry points beyond `EditStockEntry()` need the negative refusal.** Named in
   decision 4: `InventoryProduct()`, `OpenProduct()` and `TransferProduct()` were not
   individually confirmed to accept a negative write in this record's reproduction.

## Acceptance prerequisites

1. The decider confirms the tolerance's order of magnitude (1e-9) against the
   coherence-tightening consequence above.
2. Regression tests reproducing #492 (consuming 0.3 from 0.1 + 0.2 leaves no residue row)
   and #470 (undoing a transfer leaves no phantom row at the destination) pass against the
   new constant, on real PostgreSQL per [ADR-0025](0025-three-test-tiers.md).
3. Every site named in decision 1 is updated, and the accepting pull request states that the
   presentation-only sites (`:103`, `:2263`, `:2295`) were left unchanged on purpose.
4. `EditStockEntry()` refuses a negative amount while continuing to accept zero; the
   accepting pull request states the outcome of auditing `InventoryProduct()`,
   `OpenProduct()` and `TransferProduct()` for the same gap (open question 2).
