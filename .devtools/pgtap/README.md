# pgTAP

Tier 2 (ADR-0025): SQL logic tested where it lives, in PostgreSQL, with
[pgTAP](https://pgtap.org/) run by `pg_prove`. pcov measures PHP lines and nothing else,
so this tier's measure is not a percentage: it is completeness, a named list of every
function and trigger the fork's migrations create, each paired with the test file that
exercises it.

```sh
.devtools/pgsql/run-tests.sh pgtap
```

against a database `run-tests.sh` migrates the way every other phase does, with the
`pgtap` extension installed into it first (`CREATE EXTENSION IF NOT EXISTS pgtap`).
Views are listed here too where they carry logic (recursive CTEs, the resolved views);
most views are already asked the differential phases' own question and are not repeated
here.

The [stock location constraint tests](017-stock-location-reference.sql) cover migration
0288's restrictive foreign key, nullable stock, unconstrained history, and import trigger
suppression against the full schema.

The [audit view-correction tests](018-audit-view-corrections.sql) cover migration 0289:
`stock_current`'s mixed-factor opened aggregate, `uihelper_stock_journal`'s deleted-location
history, and `chores_current`'s leap-day yearly anchor and undone-execution-filtered weekly
schedule (issues #501, #505, #497 and the weekly-schedule half of #506).

The [product removal label retirement tests](022-product-removal-label-retirement.sql) cover
migration 0295 (issue #558). Deleting a product whose stock entries carry a live label now
retires those labels with the product's own name, not null. `trg_cascade_product_removal`
retires them before deleting the stock rows, rather than after the product row (and
`retire_stock_entry_labels`' own product lookup) is gone. The same migration's foreign keys
on all six of `products`' upstream reference columns (`location_id`, `qu_id_purchase`,
`qu_id_stock`, `qu_id_consume`, `qu_id_price`, `product_group_id`; issue #552, D4) are covered
by `tests/Pgsql/ProductReferenceIntegrityTest.php` at the httpboot phase, the same shape
`tests/Pgsql/ReferenceRefusalTest.php` already uses for the other enforced foreign keys in
this tree, and by `DatabaseImporter::AssertProductReferences()`'s own import-time refusal,
covered by `tests/Pgsql/StockLocationImportTest.php`.

The [product group roll-up tests](020-product-group-rollup.sql) cover migration 0293
(issue #508, M8, ADR-0034): `product_groups_missing`'s member join now reaches every group
in an ancestor's subtree through `product_groups_resolved`, not only a product's own direct
group. This ports `.devtools/adr0034/fixtures.sql` and `.devtools/adr0034/rollup.sql` — the
ADR's acceptance experiment — into a permanent test rather than a disposable rolled-back
transaction.

Sixteen assertions cover a three-level tree, direct members together with descendants, an
empty group, and an unrelated branch. They also cover packaging parentage (not to be
confused with group nesting), opened-stock exclusion, and a child minimum that does not
enter its ancestor's calculation. The remaining cases cover inactive groups and products at
every level of the tree, including an inactive intermediate group whose active descendants
still count toward an active ancestor. Nine of the sixteen fail against the pre-0293
direct-membership view.

The [stock_log cache rebuild tests](019-stock-log-cache-rebuild.sql) cover migration 0292
(issue #588). `trg_stock_log_UPD` and `trg_stock_log_DEL` share
`rebuild_stock_log_cache_for_product()`, which recomputes
`cache__products_average_price`/`cache__products_last_purchased` from what the
`products_average_price`/`products_last_purchased` views currently return for one product.

That replaces two narrower shapes. UPD used to only ever upsert, leaving a stale cache row
once `StockService::UndoBooking()` undoes a product's only purchase. DEL's round-1 shape
only ever deleted, emptying the cache even when another booking of the same product was
still there. Four cases are covered: an UPDATE that empties the view for a product, an
UPDATE that moves a booking to a different `product_id`
(`StockService::MergeProducts()`'s shape), a DELETE that leaves another booking of the same
product, and a DELETE with the issue's own id/`product_id` coincidence.

CodeRabbit finding 4124417575 (Major): fixing the triggers repairs future writes only.
Migration 0292 also calls `reconcile_stock_log_cache()` once, reusing
`rebuild_stock_log_cache_for_product()` to repair corruption the old triggers already left
on an upgraded install. A fifth case in the same file covers a missing cache row and a
stale one.

## The list

Migrations 0001-0255 are SQLite-only history that PostgreSQL never runs (it loads the
squashed baseline in `db/pgsql/baseline/` instead, per
[ADR-0004](../../docs/adr/0004-engine-specific-migrations.md) and
[ADR-0008](../../docs/adr/0008-postgresql-only-runtime-engine.md)), so they are out of
scope here the same way `check-migrations.php` treats them. Everything a migration above
that baseline creates has a row below or `check-pgtap-coverage.php` fails the build.

| Name | Kind | Migration | Test file |
|---|---|---|---|
| `retire_location_labels` | function + trigger | 0269, redefined 0296 | `010-locations-trigger-family.sql`, `023-label-retirement-cancels-jobs.sql` |
| `hierarchy_depth_limit` | function | 0273 | `010-locations-trigger-family.sql` |
| `trg_locations_check_parent` (trigger `check_location_parent`) | function + trigger | 0273 | `010-locations-trigger-family.sql` |
| `trg_locations_guard_children` (trigger `guard_location_children`) | function + trigger | 0273 | `010-locations-trigger-family.sql` |
| `label_current_import_epoch` | function | 0269 | `011-label-import-epoch.sql` |
| `trg_stock_next_use_INS` | function | 0275 | `012-stock-next-use-and-rescale.sql` |
| `trg_stock_next_use_UPD` | function | 0275 | `012-stock-next-use-and-rescale.sql` |
| `trg_cascade_change_qu_id_stock2` | function | 0275 | `012-stock-next-use-and-rescale.sql` |
| `trg_enfore_product_nesting_level` (trigger `enfore_product_nesting_level`) | function + trigger | 0277 | `013-product-nesting-guard.sql` |
| `trg_product_groups_check_parent` (trigger `check_product_group_parent`) | function + trigger | 0278 | `014-product-groups-trigger-family.sql` |
| `trg_product_groups_guard_children` (trigger `guard_product_group_children`) | function + trigger | 0278 | `014-product-groups-trigger-family.sql` |
| `trg_cascade_product_removal` | function | 0279, redefined 0295, redefined again 0296 | `015-product-removal-cascade.sql`, `022-product-removal-label-retirement.sql`, `023-label-retirement-cancels-jobs.sql` |
| `retire_product_labels` | function + trigger | 0283, redefined 0296 | `016-label-retirement-family.sql`, `023-label-retirement-cancels-jobs.sql` |
| `retire_stock_entry_labels` | function + trigger | 0283, redefined 0296 | `016-label-retirement-family.sql`, `023-label-retirement-cancels-jobs.sql` |
| `retire_recipe_labels` | function + trigger | 0283, redefined 0296 | `016-label-retirement-family.sql`, `023-label-retirement-cancels-jobs.sql` |
| `retire_chore_labels` | function + trigger | 0283, redefined 0296 | `016-label-retirement-family.sql`, `023-label-retirement-cancels-jobs.sql` |
| `retire_battery_labels` | function + trigger | 0283, redefined 0296 | `016-label-retirement-family.sql`, `023-label-retirement-cancels-jobs.sql` |
| `cancel_queued_label_jobs` | function | 0296 | `023-label-retirement-cancels-jobs.sql` |
| `stock_current` (opened aggregate, mixed conversion factors) | view | 0289 | `018-audit-view-corrections.sql` |
| `uihelper_stock_journal` (deleted-location history) | view | 0289 | `018-audit-view-corrections.sql` |
| `chores_current` (yearly leap-day anchor, weekly undone filter) | view | 0289 | `018-audit-view-corrections.sql` |
| `rebuild_stock_log_cache_for_product` | function | 0292 | `019-stock-log-cache-rebuild.sql` |
| `reconcile_stock_log_cache` | function | 0292 | `019-stock-log-cache-rebuild.sql` |
| `trg_stock_log_UPD` (trigger `stock_log_UPD`) | function + trigger | 0292 | `019-stock-log-cache-rebuild.sql` |
| `trg_stock_log_DEL` (trigger `stock_log_DEL`) | function + trigger | 0292 | `019-stock-log-cache-rebuild.sql` |
| `product_groups_missing` (member join rolled up through `product_groups_resolved`) | view | 0293 | `020-product-group-rollup.sql` |
| `trg_cascade_change_qu_id_stock` | function | 0294 | `021-cascade-qu-id-stock.sql` |

## Completeness

Every function and trigger the fork's migrations create above the SQLite baseline now
has a row above, and `check-pgtap-coverage.php` passes against this tree. The sixteen
names ADR-0025 spike 4 left as future work (issue 192's ratchet-then-gate shape) are
covered by files `011` through `016`.

Files `011` through `016` were ported the same way spike 4's own file was: a fully
migrated database, no reduced fixture, and at least one assertion that fails when the
behaviour it names is reverted.

Files `012` and `013` in particular reproduce the exact defects their migrations fixed
rather than merely asserting the migration's own comment. File `012` tests an `UPDATE`
through `stock_next_use` that silently wrote nothing before migration 0275; file `013`
tests a nesting check that only ever inspected one direction before migration 0277.
`check-pgtap-coverage.php` is wired into `run-tests.sh`'s `pgtap` phase as a hard gate
(see "Running the checker directly" below) now that the list has nothing left unnamed.

File `018` covers three views migration 0289 recreates, not a function or trigger. That is
why `check-pgtap-coverage.php` does not require it: that check reads
`CREATE FUNCTION`/`CREATE TRIGGER`, not `CREATE VIEW`. It is listed here anyway, per this
file's own rule above that a view carrying logic is listed "where it carries logic". Each of
the three cases qualifies: a view whose own SQL previously produced a wrong value or an
uncatchable error for a real input, not merely a projection of other tables.

File `020` is the same shape, for migration 0293's `product_groups_missing`
(issue #508, ADR-0034): the roll-up join is logic a plain projection would not need. The
view previously produced a wrong shortfall for a real, reachable input (any nested
product-group tree) - reporting an ancestor group as short by its whole minimum while a
descendant group held enough stock to satisfy it.

The [cascade qu_id_stock tests](021-cascade-qu-id-stock.sql) cover migration 0294 (issues
#543 and #546, #487 remediation). `trg_cascade_change_qu_id_stock` now also rescales
`product_location_min_stock.min_stock_amount` and `products.min_stock_amount` by the same
conversion factor as every other per-product amount it already converts (#543). It also
refuses the qu_id_stock change outright, before any row is touched, when the resolved
factor is not 1 and the product has a live measured open container in `stock` (#546),
mirroring the refusal `StockService::MergeProducts()` already applies to the analogous
merge case (commit 791389623f).

The guard deliberately does not also check `stock_log` for a live (`undone = 0`) measured
consume booking with no live `stock` row - the shape left behind once a whole measured
container is fully consumed. An earlier round of this migration did, and that over-refused:
such a booking is permanent history nothing ever clears, so it locked the product's stock
unit forever. That booking's own undo is already refused truthfully by `UndoBooking()`'s own
guard (PR #598). That refusal fires if and when the booking is ever undone, which is where
this protection belongs.

Seven assertions cover both minimum rescales together with `product_location_missing`'s
correctly converted shortfall, and the live-`stock` refusal (with the row left untouched).
They also cover a qu_id_stock change succeeding despite a live ledger-only measured booking,
and a negative control confirming an ordinary, unmeasured product still rescales exactly as
it did before this migration.

The [label retirement job cancellation tests](023-label-retirement-cancels-jobs.sql) cover
migration 0296 (issue #516, M16, #487 remediation, maintainer decision D2). Every label
retirement trigger now calls `cancel_queued_label_jobs()` after it retires a label. A queued,
unclaimed `print_jobs` row for that label is cancelled (`cancelled_at`/`cancelled_reason`
set) and its `outbox` row is dead-lettered. The print-job monitor then shows an accurate
final state, instead of a job stuck behind a label that can never be printed again. A job
already claimed (`current_attempt_id` set) is left exactly as it was — D2's "leave running
jobs untouched". So is a job already in a terminal state (`outcome` set), or already
cancelled.

Fourteen assertions cover:

- a queued job cancelled by a direct product delete (`retire_product_labels`);
- the cancelled job's outbox row dead-lettered;
- a claimed job (`current_attempt_id` set) on the same label surviving retirement untouched;
- the claimed job's outbox row left undelivered and undead-lettered too;
- the label itself still retiring even though one of its jobs could not be cancelled;
- a job with a `printed` outcome already set surviving retirement with that outcome
  unchanged;
- a queued job cancelled by a direct `DELETE FROM stock` (`retire_stock_entry_labels`);
- a queued job cancelled by a single product delete that cascades, via
  `trg_cascade_product_removal`, to the first of two stock entries it held, each carrying
  its own labelled job;
- the second of those two jobs cancelled as well, not only the first the join touches;
- a job already cancelled (by an operator, through `LabelOperationsService::Cancel()`)
  keeping its own original `cancelled_reason` rather than having retirement overwrite it;
- a job already `dead_lettered` surviving retirement with that outcome unchanged, the same
  as the already-`printed` case above; and
- a queued job cancelled by each of the three single-row retirement triggers case 1 does not
  already cover: `retire_recipe_labels`, `retire_chore_labels`, `retire_battery_labels`.

Several more tests cover concurrent cases a single-connection pgTAP script cannot drive.
`tests/Pgsql/LabelRetirementCancelsClaimedJobRaceTest.php`: a job claimed by one connection
while a second connection concurrently retires its label must not be cancelled by that
retirement. `tests/Pgsql/LabelRetirementRacesReprintTest.php`: a reprint racing a retirement
of the same label must not commit a new queued job after that retirement's own cancellation
has already run. `LabelOperationsService::AssertLabelLive()` (called by `Reprint()`,
`PromotePreview()`, and now `RevisedPrint()` too) takes a `FOR SHARE` lock on the label's row
for exactly this reason. All are proven with two real PostgreSQL connections and a row lock,
not with timing.

Getting that lock's *order* right relative to every other lock the same call takes turned out
to need its own tests.

`tests/Pgsql/LabelRevisedPrintNeverDeadlocksWithRetirementTest.php` and `tests/Pgsql/
LabelReprintNeverDeadlocksWithRetirementTest.php` each prove a genuine PostgreSQL deadlock
(SQLSTATE 40P01) is impossible between a concurrent `RevisedPrint()`/`Reprint()` and a
retirement of the same label. `RevisedPrint()` locks the target entity row
(`LabelIdentityService::Issue()`'s own `FOR UPDATE`) before the label. `Reprint()` has no
entity row of its own; it locks the label before its source `print_jobs` row instead. Both
orders match what a retirement itself locks first.

`tests/Pgsql/LabelRevisedPrintCascadeCancelsStockEntryJobTest.php` covers the specific path
that motivated getting this right. `trg_cascade_product_removal` (migrations/0296.pgsql.sql)
retires a deleted product's stock-entry labels. It now locks every affected `stock` row
(`PERFORM ... FOR UPDATE`) before touching `labels` - the same entity-before-labels order as
every other retirement site. So a concurrent `RevisedPrint('stock_entry', ...)` can never
read a label as live and queue a job after the retirement's own cancellation has already run
past it.

`tests/Pgsql/LabelJobLifecycleTest.php::testRetiredLabelJobWithAFailedAttemptAndReauthorizationIsNeverReclaimed()`
covers a case `cancel_queued_label_jobs()` deliberately does not reach: a job attempted once,
reported failed, and re-authorized for another attempt still carries a non-null
`current_attempt_id` (pointing at the ended first attempt), so retirement's cancellation
skips it. `PrintAttemptService::Claim()`'s own retired-label exclusion is the only thing
that still stops it from being reclaimed once its label retires.

## Running the checker directly

```sh
php .devtools/pgtap/check-pgtap-coverage.php
```

Reads this file's table and every migration above the baseline, and fails naming any
function or trigger a migration creates that the table above does not list by name.
