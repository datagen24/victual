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
| `retire_location_labels` | function + trigger | 0269 | `010-locations-trigger-family.sql` |
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
| `trg_cascade_product_removal` | function | 0279 | `015-product-removal-cascade.sql` |
| `retire_product_labels` | function + trigger | 0283 | `016-label-retirement-family.sql` |
| `retire_stock_entry_labels` | function + trigger | 0283 | `016-label-retirement-family.sql` |
| `retire_recipe_labels` | function + trigger | 0283 | `016-label-retirement-family.sql` |
| `retire_chore_labels` | function + trigger | 0283 | `016-label-retirement-family.sql` |
| `retire_battery_labels` | function + trigger | 0283 | `016-label-retirement-family.sql` |
| `stock_current` (opened aggregate, mixed conversion factors) | view | 0289 | `018-audit-view-corrections.sql` |
| `uihelper_stock_journal` (deleted-location history) | view | 0289 | `018-audit-view-corrections.sql` |
| `chores_current` (yearly leap-day anchor, weekly undone filter) | view | 0289 | `018-audit-view-corrections.sql` |
| `rebuild_stock_log_cache_for_product` | function | 0292 | `019-stock-log-cache-rebuild.sql` |
| `reconcile_stock_log_cache` | function | 0292 | `019-stock-log-cache-rebuild.sql` |
| `trg_stock_log_UPD` (trigger `stock_log_UPD`) | function + trigger | 0292 | `019-stock-log-cache-rebuild.sql` |
| `trg_stock_log_DEL` (trigger `stock_log_DEL`) | function + trigger | 0292 | `019-stock-log-cache-rebuild.sql` |
| `product_groups_missing` (member join rolled up through `product_groups_resolved`) | view | 0293 | `020-product-group-rollup.sql` |

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

## Running the checker directly

```sh
php .devtools/pgtap/check-pgtap-coverage.php
```

Reads this file's table and every migration above the baseline, and fails naming any
function or trigger a migration creates that the table above does not list by name.
