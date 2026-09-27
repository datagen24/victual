# ADR-0032: Stock amounts compare within one tolerance

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-26, from the maintainer's decision in the issue
  [487](https://github.com/datagen24/victual/issues/487) remediation session the same day.
- **Referenced by:** [issue 492](https://github.com/datagen24/victual/issues/492) (H3),
  [issue 470](https://github.com/datagen24/victual/issues/470); replaces the `round($x, 2)`
  convention [PR #469](https://github.com/datagen24/victual/pull/469) used in purchase undo,
  keeping its comparison-copy and unrounded-storage pattern.

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
the destination.

[PR #531](https://github.com/datagen24/victual/pull/531), open and
unmerged at its 2026-09-27 head `e60a5d22`, introduces
`StockService::AMOUNT_TOLERANCE = 1e-9`. It applies the constant to purchase, transfer,
open and stock-edit undo and the `ConsumeProduct()` candidate guard. Its remaining
`round($x, 2)` sites await a separate change. The exact transfer-undo comparison remains
in the Context audit's master revision.

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
| `services/StockService.php:2021` | `OpenProduct()` | `round($amount, 2) != 1.0` — coherence |
| `services/StockService.php:2027` | `OpenProduct()` | `round($targetEntry->amount, 2) < 1.0` — coherence |
| `services/StockService.php:2822` | `UndoBooking()` | zero/negative test (PR #469) |

Two further `round()` calls in this file round a display quantity already taken from a
decided ledger amount and do not test a boundary: `services/StockService.php:2263` and
`:2295`, both in `GetShoppinglistInPrintableStrings()`, round a quantity for a thermal
printer label. `services/StockService.php:103`
(`AddMissingProductsToShoppingList()`) rounds a missing amount before writing a
`shopping_list` quantity — a different table from the stock ledger. Both are named here
because the sweep behind this record covers every `round()` call in the file, not
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

The maintainer chose a shared comparison tolerance and unrounded storage in the
recorded session. On 2026-09-27, after the [comparison spike](../../.spike-adr32/RESULTS.md),
the maintainer selected the combined relative tolerance and accepted its wider loss of
precision at large amounts. The record remains Proposed pending its acceptance gates.
Decisions 3, 4 and 5 and the implementation scope remain refinements requiring formal
confirmation at acceptance.

1. **Use one tolerance function for stock availability and computed remainders:**
   `tol(a, b) = max(AMOUNT_TOLERANCE, 1e-12 * max(abs(a), abs(b)))`, where the existing
   `AMOUNT_TOLERANCE = 1e-9` remains the absolute floor. For finite operands in the same
   stock unit, equality means `abs(a - b) <= tol(a, b)`. Greater-than means
   `a - b > tol(a, b)`; less-than means `b - a > tol(a, b)`.
   Reject non-finite inputs before comparison.

   Decide whether a computed remainder `a - b` is zero by comparing `a` and `b` with
   this predicate, retaining their magnitudes. Comparing only the remainder with zero
   loses the relative term's scale. When a whole-entry comparison finds equality, mark
   the transient request exhausted before processing another candidate or converting
   the remaining request back. The stored booking still records the actual entry amount.
   Direct zero tests use `tol(a, 0)`; they do not replace operand-based remainder tests.

   Aggregate availability and inventory comparisons use the requested product's stock
   unit. Per-entry comparisons use the candidate product's stock unit after conversion.
   Loop termination uses the requested product's stock unit after converting the remaining
   request back. A whole-entry booking records the entry's actual amount. A remaining
   request within tolerance terminates the loop before another row is booked, including
   when subtraction leaves a small negative remainder.

   The required inventory in `StockService` includes:

   | Function | Comparisons using the tolerance |
   |---|---|
   | `ConsumeProduct()` | Scoped availability, whole-entry selection, loop termination |
   | `OpenProduct()` | Unopened availability, whole-entry selection, loop termination |
   | `TransferProduct()` | Source availability, whole-entry selection, loop termination |
   | `InventoryProduct()` | Equality and direction of the difference from current stock |
   | `UndoBooking()` | Computed shortage and zero remainder for purchase, self-production, positive inventory correction, and transfer-to reversal |

   This inventory also covers comparisons introduced after the dated Context audit.
   Input sign checks and transaction-type classification remain exact: a negative input
   is invalid even within tolerance, and a negative log amount still identifies a consume.
   Measured-container coherence follows decision 5. Shopping-list quantities and display
   formatting remain outside the stock comparison policy.

   `RecipesService::ConsumeRecipe()` uses these predicates for its positive-stock check
   and availability clamp before calling `ConsumeProduct()`. SQL view comparisons and
   comparisons in `public/viewjs/` remain outside this decision; open question 4 records
   the unresolved scope and its consequences.
2. **Stored amounts stay unrounded.** A comparison may select a whole-entry operation or
   deletion of an exhausted row. Surviving stock amounts and ledger entries retain the
   unrounded arithmetic or actual entry amount; the tolerance is never written as a value.
   Inventory counts within tolerance of current stock are refused with the existing
   equal-count validation error and produce no correction booking.
3. **Display decimals are presentation only and never decide ledger validity.** The two
   printer-quantity roundings and the shopping-list amount-to-add rounding format a value
   already decided by the ledger; none of the three may be read as, or replaced by, a
   zero or availability test.
4. **Negative amounts are refused.** [PR #530](https://github.com/datagen24/victual/pull/530)
   implements `if ($amount < 0)` in `EditStockEntry()` at the reviewed head `894fc44e`,
   leaving zero writable. Open question 1 preserves a legitimate zero write when a vessel
   is weighed empty through `WeighLocation()`, which calls `EditStockEntry()`. The accepting pull request must state
   whether `InventoryProduct()`, `OpenProduct()` and `TransferProduct()` carry the same gap —
   this record's reproduction did not exercise a negative write through them, so it does not
   assert that they do.

5. **Measured-container coherence remains exact under ADR-0022.** The
   `stock_measurement_coherence_check` in
   [migration 0275](../../migrations/0275.pgsql.sql) requires an opened, measured row to
   hold exactly one stock unit. Application checks must agree with that constraint.
   `EditStockEntry()` retains measurement metadata only at exactly one unit;
   `MeasureStockEntry()` requires exactly one unit. `OpenProduct()` with a measurement
   requires an exact one-unit request and a candidate holding at least one unit.
   A candidate above one unit must split off exactly one measured unit even if its
   remainder falls within tolerance. A candidate below one cannot be accepted as one.
   These structural checks replace the four rounded coherence checks in Context with
   exact predicates. They take precedence over tolerant whole-entry selection, preserve
   a positive split remainder, and do not normalize stored amounts or weaken the SQL
   constraint.

**Rejected:**

- **Keep `round(x, 2)` everywhere.** Uniform, but wrong in both directions demonstrated
  above: it loses a real remainder under 0.005 of the stock unit, and it cannot distinguish
  "genuinely zero" from "a few grams" for a large-unit product.

**Not decided: exact decimal storage and arithmetic.** `NUMERIC` storage paired with
explicit decimal arithmetic in PHP, or scaled integers with a declared scale, can avoid
binary floating-point residue for representable decimal operations. Storage alone is
insufficient if PHP converts the values back to floats. These choices still need precision,
range, division and rounding rules. [Issue #487](https://github.com/datagen24/victual/issues/487)
correction 7 identifies explicit wire serialization as an option; the maintainer did not
reject it. A later record would decide the schema and arithmetic changes.

## Consequences

- Arithmetic residues within the selected tolerance are treated as exhausted. Genuine
  differences within the same tolerance are also treated as equal. The absolute floor
  accepts loss of resolution up to `1e-9`; the relative term widens that window with the
  operands' magnitude. At one billion stock units, the window is `0.001` stock units.
  The spike confirms that a requested consume leaving about `0.0005` instead books the
  entire row. The maintainer explicitly accepted this tradeoff on 2026-09-27.
- The relative term clears the spike's accumulated-drift cases at 17-digit serialization,
  but does not remove every residue. An error inherited from a much larger amount can
  outgrow the tolerance once both operands become small. Consuming a row valued at
  `999999999.9 + 0.1` in those two portions still leaves about `2.38e-8` under both
  tested policies. This known limitation remains part of the selected policy.
- The schema places no magnitude bound on `stock.amount`. Neither the
  [numeric probe](#numeric-evidence) nor the service spike establishes a safe magnitude
  or booking-count range. The service spike also shows that PHP serialization precision
  changes accumulated drift during database round trips.
- Exact measured-container coherence can reject an amount such as `1.0000000005` even
  though a stock availability comparison treats it as equal to one. Preserving that
  value with measurement metadata would violate the existing database constraint.
  Regression tests must cover both sides of one and the former `0.005` rounding window.
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

3. **Amounts beyond the absolute tolerance's useful range.** Choose whether to accept
   the documented failures, add a relative term, or pursue exact decimal arithmetic.

   > **Response:** On 2026-09-27, after comparing both policies through the stock services,
   > the maintainer chose: "Use relative tolerance; accept its wider loss of precision at
   > large amounts". The comparison presented both the wider loss and the carried
   > `2.38e-8` residue that remains under either policy. Decision 1 now specifies
   > `max(1e-9, 1e-12 * max(abs(a), abs(b)))`. Exact decimal arithmetic remains deferred.

4. **SQL and browser comparisons.** `stock_current`, `stock_missing_products`,
   `product_groups_missing`, `product_location_missing`, and `recipes_pos_resolved`
   have their own stock comparisons. The last includes a `1e-8` threshold and rounded
   comparison. Browser code in `public/viewjs/` also compares stock amounts.
   A `0.8` minimum held as `0.1 + 0.7` can report a roughly `1.11e-16` shortage.
   Whether these consumers adopt the tolerance, and how SQL and JavaScript share its
   definition with PHP, remains undecided. Acceptance of this scoped record does not
   establish consistent comparisons across the application.

## Numeric evidence

Measured 2026-09-27 with Python binary64 arithmetic against the storage and arithmetic
model inspected at master `1ee17d2c`. This standalone probe reproduces arithmetic only;
it is not a PostgreSQL or PHP integration test. Run with Python 3:

```python
import math
from decimal import Decimal

print("spacing at 2^23:", math.ulp(2**23))
amount = 999999999.9 + 0.1
print("bulk residue:", (amount - 999999999.9) - 0.1)
amount = 1000000.0
for _ in range(1000):
    amount -= 0.1
print("1000-booking error:", Decimal.from_float(amount) - Decimal("999900"))
print("minimum shortage:", 0.8 - (0.1 + 0.7))
```

The outputs are approximately `1.86e-9`, `2.38e-8`, `2.33e-8`, and `1.11e-16`,
respectively. These are counterexamples, not a bound on all booking sequences. The
randomized V-541 measurements supplied during review are not reproduced here because
this working copy does not contain their scripts or seeds.

## Acceptance prerequisites

On 2026-09-27, the maintainer selected reproducible spike evidence as the basis for
acceptance. The six gates below apply to the experimental implementation in a pinned,
disposable PostgreSQL working copy. They do not require production delivery before the
architectural decision is accepted. Production adoption must preserve the verified
behavior, add maintained regression tests, and pass the repository's coverage checks.


1. The accepting pull request confirms the selected absolute floor `1e-9`, relative
   coefficient `1e-12`, inclusive equality boundary, operand-based remainder checks,
   unit conversion rules, and exact coherence exception. It cites open question 3's
   response accepting the wider loss of precision and the known carried-residue limitation.
   The implementation evidence records the magnitudes, conversion factors and serialization
   precision exercised. Any further arithmetic-policy change requires revising this
   proposal before the separate bookkeeping acceptance.
2. Reproducible spike tests on real PostgreSQL per [ADR-0025](0025-three-test-tiers.md) cover
   consume, open, transfer, inventory correction and its undo, purchase undo,
   self-production undo, and transfer undo.
   They reproduce #492 and #470 and assert stock rows and ledger amounts after each
   operation and its applicable undo. The consume fixture must retain distinct 0.1 and
   0.2 candidate rows so compaction cannot remove the per-entry subtraction being tested.
3. Boundary tests cover zero and differences below, at, and above the selected tolerance, including
   the `1e-9` floor, large operands, and negative computed residues. They preserve a
   genuine `0.001` remainder at unit-scale operands and refuse a
   shortage outside tolerance without partial writes. Multi-entry cases assert that a
   near-zero remaining request does not create another booking. Mixed-unit substitution
   tests exercise both aggregate and per-entry comparisons after conversion. Recipe
   consumption tests exercise the availability clamp with shortages inside and outside
   tolerance. Inventory tests assert the equal-count validation error within tolerance.
   Include the bulk and repeated-booking counterexamples from Numeric evidence and state
   the expected remaining limitations under the selected policy.
4. Coherence tests exercise `EditStockEntry()`, `MeasureStockEntry()`, and measured
   `OpenProduct()` against the existing SQL constraint. Cover exactly one, values within
   `1e-9` on either side, and `0.995`, `0.998`, `1.002`, and `1.005` from the old rounding
   window. Assert metadata retention or removal, validation refusal, and exact one-unit
   splits with positive remainders as applicable. No application-approved measured write
   may fail the SQL coherence constraint.
5. The accepting pull request provides an experimental implementation audit for every comparison in
   the decision 1 inventory and the decision 5 exception. It identifies any additional
   stock comparisons and explains their classification. Printer formatting and
   shopping-list rounding remain unchanged on purpose.
6. `EditStockEntry()` refuses negative amounts, including a negative value within
   tolerance, while continuing to accept zero. The accepting pull request states the
   outcome of auditing `InventoryProduct()`, `OpenProduct()` and `TransferProduct()` for
   the same gap (open question 2). Non-finite input tests demonstrate refusal before any
   stock or ledger mutation.

These are spike-evidence gates. The accepting pull request links the pinned experiment,
its assertions and results, and the comparison audit. Production implementation, maintained
regressions, and delivery verification belong in later pull requests. Acceptance carries
only the lifecycle bookkeeping required by the ADR index.

## Acceptance evidence

The [2026-09-27 comparison spike](../../.spike-adr32/RESULTS.md) exercises absolute and
relative tolerances through real stock services on PostgreSQL. It records the combined
master and undo-dependency revisions, experimental changes, reproduction commands, and
an audit of all six gates. Both policies retain a bulk residue; the relative policy also
removes a genuine small remainder at large magnitude. Runtime serialization precision
changes the observed accumulated drift.

The maintainer selected the relative policy after reviewing this evidence; open question 3
records the response. The spike does not land the implementation. The maintainer confirmed that spike evidence
can clear acceptance, while production changes, maintained regressions, and final
implementation verification remain delivery requirements.
