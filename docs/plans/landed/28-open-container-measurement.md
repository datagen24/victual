# 28. Measuring what is left in an opened container

**Goal:** Three sealed bags of flour and one open bag holding 1.2 kg is a state the system
can hold. An opened unit's remaining contents are measured — by weight or by volume,
whichever the person can actually take — without giving up counting the units themselves.

**Depends on:** [ADR-0022](../../adr/0022-open-containers-carry-a-measured-remainder.md),
**Accepted** 2026-09-14. Nothing else blocks it.

**Interacts with:** [29](29-working-container-replenishment.md), which takes the location
half of ADR-0022 decision 4's tare where this plan takes the entry half, and shares nothing
else. It also interacts with [07](../retired/07-nested-products.md), which rewrites the same
aggregation in `stock_current`, and with the container decision recorded below that came out
of the same review. Scheduled into wave 4 beside 07 for that reason.

**Consumed by:** nothing yet. [22](../22-medication-tracking.md) is the obvious later
customer — a part-used bottle is the same shape of problem — but that plan is unscheduled
and this one does not wait for it.

**Status:** landed in wave 4; see [Executed](#executed).

## Relationship to plan 07

It arrived out of the [07 question 6](https://github.com/datagen24/victual/issues/82)
review, and it is not part of 07. 07 asks what `parent_product_id` means. This asks where
"how much is left" lives. They met because answering the first forced a stock unit of
*pieces*, and pieces are what make the second question unanswerable today.

The container decision that came out of that review is recorded here because nothing else
owns it yet: **per-unit labelling requires a stock unit of pieces, one stock unit per
product means one product per container size, and a pooled parent supplies the combined
total.**

An 8 oz can, a 12 oz can, a 16 oz bottle and a 2 L bottle of one soda are four
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
it was designed for. It is silently wrong the moment a second sealed unit exists beside the
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

**The check constraint requires `amount = 1` so a measurement always names one container.** A
`stock` row is not inherently one container. `OpenProduct` marks a whole entry open in place
when the requested amount covers it, leaving `amount` alone
(`services/StockService.php:1628-1632`), so three jugs bought without per-unit labelling are
one row that becomes `open = 1, amount = 3`. One remainder on that row names no
particular jug. Measuring an entry holding more than one unit therefore splits it first —
the operation `OpenProduct`'s else branch already performs when opening part of an entry
(`:1636`).

**Rows already in that state are a migration case, not a hypothesis.** Any entry that is
`open = 1` with `amount > 1` when 0275 runs either gets split by the migration or stays
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
[ADR-0005](../../adr/0005-wire-contract-is-the-invariant.md). **This wants to land before
[14](14-contract-and-regression-scaffolding.md) piece 2**
([issue 83](https://github.com/datagen24/victual/issues/83)) freezes the response contract
in wave 5; afterwards the same change is an amendment to a frozen contract rather than an
addition to an open one.

### UI

**The common actions are one tap.** A measurement is taken with a scale in one hand, often by
someone who will not navigate a form to record it. `products.quick_consume_amount` and
`products.default_consume_location_id` already establish the shape — a preset on the product
turning an action into a button — and measurement follows it rather than inventing one.
[29](29-working-container-replenishment.md) states this requirement in full; it applies here
unchanged.


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

One PostgreSQL-only migration, claimed in [RESERVATIONS.md](../../../migrations/RESERVATIONS.md).
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
   > See [ADR-0022's responses](../../adr/0022-open-containers-carry-a-measured-remainder.md#open-questions).
   > The ledger representation, permission and interaction with existing consumption bookings
   > remain to be designed; this is not a maintainer decision.
   >
   > **Implemented 2026-09-14, following the review recommendation exactly rather than
   > deciding it independently.** `StockService::MeasureStockEntry()` writes a correlated
   > `stock-measured-old`/`stock-measured-new` pair — the same before/after shape
   > `EditStockEntry()` already uses for the rest of an entry — carrying only the four
   > measurement columns, never `amount`. It is not a consumption: `stock.amount`, the
   > container count, is untouched, and the two new transaction types are their own kind, not
   > a reuse of `consume` or `stock-edit-*`. Permission is `STOCK_EDIT`, the same permission
   > `EditStockEntry()` requires, since a measurement is a fact about an entry a person is
   > already trusted to edit; no new permission constant was added. Undo restores exactly
   > what a `stock-measured-old` row recorded (see *Undo, edit and split* and
   > `.devtools/pgsql/open-container-measurement-tests.php` case 5), and never touches
   > `amount`, `price`, dates, location or note — those are `EditStockEntry()`'s job, not
   > this one's. This settles the question for this plan's own implementation; it is not a
   > maintainer ratification of the design.

2. **Should the parent roll-up become strict too?** Settled for this plan: a measurement
   that cannot be converted is refused, and an underivable fraction is unavailable. What is
   open is the *pooled parent's* `COALESCE(qucr.factor, 1.0)`, which is shipped behaviour on
   a live contract — making it strict is a change to what `/stock` returns for a
   misconfigured catalogue, so it needs its own argument and probably its own record. This
   plan neither changes it nor copies it.

   > **Response, 2026-09-14:** Confirmed unchanged. `stock_current`'s existing
   > `amount_aggregated`/`amount_opened_aggregated` columns still use
   > `COALESCE(qucr.factor, 1.0)` exactly as before this migration. The new
   > `amount_measured` column (question 3) chains two conversions for its parent-rollup
   > branch — a *strict* one from `opened_qu_id` to the sub-product's own stock unit
   > (decision 3, no fallback, contributes `NULL` rather than an assumed factor when it
   > cannot resolve) and then the *same pre-existing* `COALESCE(qucr.factor, 1.0)` leg to
   > roll that into the parent's unit. So the roll-up's own fallback is neither made strict
   > nor copied into new code that didn't have it before; it is reused unchanged, once, at
   > the one point (sub-to-parent) it already applied. This question is still open in the
   > sense the plan's own text states — nothing here is an argument for or against making
   > the roll-up strict, only a record that this plan touched it in exactly one place and
   > left its behaviour as it found it.

3. **Does the pooled parent's total consume the measurement, or sit beside it?** Folding it
   in gives one honest number and changes what an existing column means. Keeping it beside
   preserves the present column and asks every consumer to choose. This is the line where
   this plan and 07 edit the same code.

   > **Response, 2026-09-14 (implementation decision, not a maintainer ruling):** Beside,
   > not folded in — the conservative half of the plan's own text ("kept separate ... so
   > that no present column changes meaning"), taken because this question was never
   > actually decided and an implementation session is not the one to decide it. 07 was
   > retired the same day ADR-0023 was accepted, before this plan reached implementation, so
   > there is no second plan left to coordinate the "same line" with either.
   > `migrations/0275.pgsql.sql` adds `stock_current.amount_measured`: for a product's own
   > (non-rolled-up) entries, the summed derived fraction of its measured entries in its own
   > stock unit; for the pooled-parent branch, the same figure scaled by the existing
   > sub-to-parent factor (question 2). `amount`, `amount_aggregated`, `amount_opened` and
   > `amount_opened_aggregated` are byte-for-byte unchanged — verified by
   > `.devtools/pgsql/difftest.php`, which now excludes only the one new column from its
   > SQLite/PostgreSQL comparison of this view, nothing else. A caller wanting the "one
   > honest number" folding in would have produced is left to the caller to compute:
   > `amount_aggregated` minus one whole unit per measured entry (each already counted as 1
   > inside it) plus `amount_measured`.

4. **Is the product-level tare pair retired?** ADR-0022 open question 1 and its acceptance
   prerequisite 8. Two mechanisms for one job is a cost; removing fields from
   `/objects/products` is a contract change.

   > **Decided 2026-09-14 (maintainer), in ADR-0022 decisions 4 and 7.** Retired as a
   > mechanism, kept on the wire at zero until plan 14 piece 2's freeze. This plan's per-entry
   > tare covers the opened purchased container; a refilled vessel's tare is its location's,
   > under [29](29-working-container-replenishment.md). Enabling the product flag answers 400.
   >
   > **Implemented 2026-09-14.** The three arithmetic branches (`AddProduct()`,
   > `ConsumeProduct()`, `InventoryProduct()`), `OpenProduct()`'s tare-enabled refusal, and
   > `trg_cascade_change_qu_id_stock2`'s tare-rescale line are all removed.
   > `TransferProduct()`'s own refusal is deliberately untouched — plan 29 owns it, per the
   > ownership split this session was given. `GenericEntityApiController::RefuseTareEnable()`
   > answers 400 on the `0 -> 1` transition only, so a product already carrying the flag can
   > still be saved unchanged; `enable_tare_weight_handling` and `tare_weight` stay on
   > `/objects/products` and `/objects/products_without_userfields` (OpenAPI schemas
   > annotated `deprecated: true` with the reason, not removed). `AddProduct()`'s
   > `$addExactAmount` parameter — only ever meaningful for the removed tare arithmetic, and
   > never set by any API caller — was deleted outright rather than left dead;
   > `ConsumeProduct()`'s `$consumeExactAmount`/`exact_amount` wire field was kept, since it
   > is an accepted request field a real client could already be sending, and documented as
   > a no-op instead.

5. **Which products are measured?** Configuration, not schema — but it decides whether the
   UI cost falls on a handful of staples or on most of the catalogue.

   > **Response, 2026-09-14 (implementation decision, not a maintainer ruling):** No
   > product-level "is this product measured" flag was added. The measurement control
   > (`.stock-measure-button`, `views/stockentries.blade.php`) is offered on any stock entry
   > that coherence already permits — `round(amount, 2) == 1` and opening is not disabled —
   > which is universal rather than curated: every single-unit entry in the catalogue can be
   > measured, not only a chosen set of staples. This sidesteps the question rather than
   > answering it: nothing here identifies which products a household actually wants to
   > weigh, so every eligible entry pays the same UI cost (a second icon) whether or not
   > anyone ever measures it. A configuration surface — a product setting, a default unit, a
   > gross/net default — remains open and unbuilt; this plan's own quick-action language
   > ("a preset on the product turning an action into a button") is not implemented.

## Effort

Medium, and dominated by the service paths rather than the schema. The migration is an
afternoon; the tare-aware arithmetic in three methods, the undo, edit and split paths, the
compaction exclusion, the view change and the fixtures for a product holding sealed and
measured units at once are the rest. The ledger work in *Undo, edit and split* is the part
most likely to be underestimated — it reaches into booking history rather than one table. Sharing wave 4's `stock_current` rewrite with 07 is what keeps it from being counted
twice.

## Executed

Landed as `migrations/0275.pgsql.sql`:

- **Schema.** `stock`/`stock_log` gain the four `opened_*` columns, `stock`'s coherence
  `CHECK` (decision 8), `stock_splits`' third exclusion clause (decision 5),
  `stock_current.amount_measured` (question 3's response), and `stock_next_use` and
  `uihelper_stock_entries` rebuilt to project the new columns.
  `trg_cascade_change_qu_id_stock2` loses its tare-rescale line (its other three rescales
  are untouched).
- **Service layer.** `StockService::OpenProduct()` gains an optional `$measurement`
  parameter and loses its tare-enabled refusal (decision 8; `TransferProduct()`'s own
  refusal is untouched, per the ownership split this session was given — plan 29 owns it); a
  new `MeasureStockEntry()` re-measures an already-open single-unit entry (question 1's
  response). `AddProduct()`, `ConsumeProduct()` and `InventoryProduct()` lose their
  product-total tare arithmetic (decision 7). `UndoBooking()` mirrors the four columns
  through its `consume` branch, clears them on `product-opened` undo, and restores them on
  the two new `stock-measured-*` transaction types.
  `GenericEntityApiController::RefuseTareEnable()` answers 400 on the
  `enable_tare_weight_handling` `0 -> 1` transition.
- **API.** `POST /stock/entry/{id}/measure` and an optional `measurement` object on
  `POST /stock/products/{id}/open` are new; `victual.openapi.json` documents both, the four
  new `StockEntry`/`StockLogEntry` fields, `stock_amount_measured`, and marks the two legacy
  tare fields `deprecated: true` with the reason.
- **UI.** `views/stockentries.blade.php` gains a `.stock-measure-button` (mode `open` or
  `remeasure`) and a shared modal (`#stock-measurement-modal`).
  `views/stockoverview.blade.php`'s quick-consume and quick-open buttons drop their
  now-meaningless tare special cases, per ADR-0022 decision 7's removal of the gross-reading
  arithmetic those cases existed for. `views/productform.blade.php`'s tare checkbox can no
  longer be newly checked.

The design above shipped as written, including every answered question. This section covers
what the plan's own text does not say, plus three defects the plan could not have
anticipated because they are about porting mechanics, not about the feature.

**A real PostgreSQL 16.13 run found three defects the design review could not.** All three
were caught by actually running `.devtools/pgsql/run-tests.sh` and the new browser probe
against a live database and a live demo instance during this session, not by inspection.
They are recorded here because a future above-freeze migration touching `stock`/`stock_log`
will hit the same three traps if it does not know to check for them.

1. **`stock_next_use`'s `s.*` is frozen at `CREATE VIEW` time.** PostgreSQL does not
   re-expand `SELECT s.*` on every query the way SQLite does; a view defined with it in the
   baseline (long before this migration) does not gain the four new `stock` columns
   until the view itself is `CREATE OR REPLACE`d. Without that, `GetProductStockEntries()`
   (which reads this view) failed outright — `SQLSTATE[42703]: column "opened_amount" ...
   does not exist`, since LessQL's `Row::update()` targets the object it was fetched
   through.
2. **Its own `INSTEAD OF INSERT`/`UPDATE` triggers are worse: they do not fail, they
   silently drop the columns.** `trg_stock_next_use_INS`/`_UPD`
   (`db/pgsql/baseline/06_triggers_b.sql`) redirect writes to `stock` by naming every column
   explicitly (`NEW.amount`, `NEW.open`, ...) rather than `NEW.*`, because that is what an
   `INSTEAD OF` trigger emulating SQLite's own updatable-view triggers does. Once the view
   itself carried the four new columns (fix 1), an `UPDATE stock_next_use SET opened_amount
   = ...` reported `UPDATE 1` — success — while leaving the underlying `stock` row's
   `opened_amount` untouched, because the trigger function had never heard of the column.
   This is the sharper defect of the two: no error, no symptom, a measurement silently
   discarded. Both triggers now name the four columns explicitly, the same way they already
   name every other one.
3. **`uihelper_stock_entries` (`db/pgsql/baseline/04_views_l1b.sql`) lists `stock`'s columns
   by name, not `s.*`, so it never had defect 1's failure mode — and needed its own explicit
   fix instead**, or the page `views/stockentries.blade.php` actually reads
   (`StockController::Stockentries()`) would show every measured container as merely
   "Opened", forever, with nothing to notice. `CREATE OR REPLACE VIEW` also refuses to
   change an *existing* output column's name or ordinal position, so the four new columns
   had to be appended after the view's very last column (`p.qu_factor_price_to_stock`), not
   inserted where they sit conceptually (after `s.note`, before `products_view`'s block).
   This was verified empirically: the middle position fails with `cannot change name of
   view column "id:1" to "opened_amount"`, since every column after the insertion point
   would have been renumbered.

**`.devtools/pgsql/difftest.php` needed a mirror-image accommodation, and a second surprise
on top of it.** Above the freeze, PostgreSQL-only columns are excluded from the SQLite
comparison the same way `uihelper_user_permissions.via_roles` already was. But
`stock_next_use` and `uihelper_stock_entries` are *both* `SELECT s.*, ...`-shaped on the
SQLite side too (the latter only because PostgreSQL cannot otherwise express the original's
duplicate-column-name behaviour; see that view's own porting note), and unlike PostgreSQL,
SQLite re-expands `*` on every query.

`.devtools/pgsql/fixtures/00_base.sql` adds the four columns to SQLite's `stock`/`stock_log`
too. This is not to run this plan's feature on SQLite, which nothing asks for. It is because
the rollback phase drives `StockService` against SQLite directly and now writes these
columns on every booking unconditionally (decision 9 mirrors them whether or not a given row
is measured). That is the same accommodation `stock_entry_origins` already has, and for the
same stated reason: keep the engine-awareness in the test tooling, never in `StockService`.

Once that fixture existed, SQLite's own copies of these two views picked the four columns up
automatically where PostgreSQL's needed an explicit rewrite. So `difftest.php` strips them
from *both* sides for these two views, not only from PostgreSQL's as the first version of
this change did. That version passed by coincidence against a database that had not yet
gained the fixture columns, then failed once it did.

**`AddProduct()` lost a dead parameter; `ConsumeProduct()` kept one as a no-op.**
`$addExactAmount` only ever had an effect inside the removed tare branch, and no API
caller — not even `AddProduct`'s own controller — ever set it to anything but `false`, so it
was deleted outright rather than left as a parameter with no path to `true`.
`ConsumeProduct()`'s `$consumeExactAmount`/the `exact_amount` wire field is different: it is
an accepted request field a real client could already be sending, so it stays accepted and
is documented as retired-and-inert rather than removed, which would be a wire-contract
change this plan does not need to make.

**A Blade compiler quirk, found the same way.** Two directly-adjacent `@endif@endif` tokens
in `views/stockentries.blade.php` compiled only the first one, leaving the second as literal
text in the output HTML and producing a real `ViewException` ("unexpected token
'endforeach'") the moment the page was actually rendered. That mismatch was invisible to a
raw `@if`/`@endif` count (which matched) and invisible to `php -l` (Blade is not PHP). Fixed
by separating consecutive directives with whitespace, the same spacing every other
multi-`@if` line in this file already uses.

**Verification**, against real PostgreSQL 16.13 and a real demo instance, 2026-09-14:

| Check | Result |
|---|---|
| `.devtools/pgsql/run-tests.sh openmeasure` | `EVERY OPEN CONTAINER MEASUREMENT ANSWERED AS EXPECTED (29 assertions)`, `SUITE PASSED` — coexistence and the retired-mechanism case, container identity, the `open=1,amount>1` refusal and split, the undo round trip, undoing a measured opening, a split carrying the measurement once, an unconvertible unit refused, a conversion deleted out from under a measurement, volume-by-weight, and compaction skipping a measured entry beside a merging unmeasured one |
| `.devtools/pgsql/run-tests.sh views` | `ALL VIEWS IDENTICAL` across all five seed groups, `SUITE PASSED` — `stock_current`, `stock_next_use`, `stock_splits` and `uihelper_stock_entries` all compare equal once the additive columns are excluded on both engines |
| `.devtools/pgsql/run-tests.sh triggers` | `TRIGGER BEHAVIOUR IDENTICAL`, `SUITE PASSED` |
| `.devtools/pgsql/run-tests.sh rollback` | `EVERY FAILED OPERATION ROLLED BACK` on both engines, `SUITE PASSED` — this is what caught the SQLite fixture gap for `ConsumeProduct()`'s now-unconditional column writes |
| `.devtools/pgsql/run-tests.sh schema`, `rbac`, `filter`, `average`, `import` | All `SUITE PASSED`; run to confirm the migration count, the product write path, and the shared `stock`/`stock_log` columns disturbed nothing outside this plan's own surface |
| `node open-container-measurement.js http://127.0.0.1:8085` (real Chromium, real demo instance) | `OPEN CONTAINER MEASUREMENT BROWSER CHECKS PASSED` — open-and-measure (net), re-measure, gross-plus-tare, the tare field's visibility following the checkbox, and the row summary text built via `.text()` alone with a payload-named quantity unit that never became markup |

`run-tests.sh all` was not run in full (the phases above cover every one this plan's surface
touches); `files`, `mqtt`, `richtext`, `chores`, `errors` and `groupminstock` were not
re-run, having no plausible interaction with this plan's changes.

**What is not built.** No product-level "is this measured" configuration was built (question
5). The quick-action preset language in the plan's own UI section ("a preset on the product
turning an action into a button") is not implemented either. The scale button is offered
universally wherever coherence permits it, at the cost of one icon on every eligible row
rather than a curated subset. `views/stockentries.blade.php`'s open-and-measure modal always
reloads the page after a save rather than patching the row in place the way
`RefreshStockEntryRow()` already does for a plain open or a consume; a re-measure does patch
in place.

This session's sandbox had no PHP 8.5 (`composer.json`'s pinned requirement), so the browser
probe above was run against a locally patched `REQUIRED_PHP_VERSION` that was reverted
before this change was committed. CI's `frontend-security` job runs the real gate on PHP
8.5, and is where this probe gets its first run against the version this fork actually
requires.
