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

## Running the checker directly

```sh
php .devtools/pgtap/check-pgtap-coverage.php
```

Reads this file's table and every migration above the baseline, and fails naming any
function or trigger a migration creates that the table above does not list by name.
