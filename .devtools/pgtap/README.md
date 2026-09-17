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

## What is not covered yet

ADR-0025 spike 4 answered one question - does pgTAP reach this fork's PL/pgSQL as
written, `RAISE` messages included, and what a test file for this codebase looks like -
with one trigger family, the smallest complete one on disk. Sixteen further function and
trigger names, across five migrations, already exist and have no pgTAP file yet, so they
have no row in the table above and `check-pgtap-coverage.php` fails naming all sixteen
if run against this tree today:

- `label_current_import_epoch` (migration 0269)
- `trg_stock_next_use_INS`, `trg_stock_next_use_UPD`, `trg_cascade_change_qu_id_stock2` (migration 0275)
- `trg_enfore_product_nesting_level` / trigger `enfore_product_nesting_level` (migration 0277)
- `trg_product_groups_check_parent` / trigger `check_product_group_parent`, `trg_product_groups_guard_children` / trigger `guard_product_group_children` (migration 0278)
- `trg_cascade_product_removal` (migration 0279)
- `retire_product_labels`, `retire_stock_entry_labels`, `retire_recipe_labels`, `retire_chore_labels`, `retire_battery_labels` - each function + its trigger (migration 0283, the label-retirement family for the other four kinds, the same shape as `retire_location_labels` above)

Porting the rest is a piece of the same work issue 192 tracks for tier 1 - it is not
this spike's job, and `check-pgtap-coverage.php` is **not yet wired into CI** for
exactly that reason: turning it on before the list is complete would fail every build on
functions that predate pgTAP entirely, which is the "threshold nobody chose" the
coverage floor's own README warns against. Wiring it in is a follow-up once the
remaining phases are migrated, the same ratchet-then-gate shape issue 192 already uses
for the PHP coverage floor.

## Running the checker directly

```sh
php .devtools/pgtap/check-pgtap-coverage.php
```

Reads this file's table and every migration above the baseline, and fails naming any
function or trigger a migration creates that the table above does not list by name.
