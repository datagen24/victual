# ADR-0002: PostgreSQL loads a squashed baseline, not a replayed migration history

- **Status:** **Accepted**, with [ADR-0001](0001-postgresql-alongside-sqlite.md).
- **Decider:** datagen24 (maintainer), retrospectively — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30, retrospectively.
- **Referenced by:** [10](../plans/landed/10-cold-start-statelessness.md) (its boot check and its
  Q7 dialect column both reason about migration numbers),
  [14](../plans/landed/14-contract-and-regression-scaffolding.md).

## Context

Grocy's migrations 0001–0255 are a decade of SQLite history: columns added and dropped,
tables renamed, a placeholder location seeded in `0006.sql` and deleted in `0021.sql`.
Replaying that against PostgreSQL would mean porting 255 migrations, most of which
describe states no PostgreSQL database would ever usefully occupy.

## Decision

PostgreSQL installations load `db/pgsql/baseline/` — 37 tables, 11 indexes, the views, the
triggers as PL/pgSQL — plus `InitialDataSeeder`, once. `DatabaseMigrationService` then
records migrations 1–255 as applied and continues from 0256.

The baseline reaches the state SQLite reaches after 0001–0255, schema **and rows**. Both
halves, or the database is not usable — see [ADR-0003](0003-seed-data-in-php.md).

## Consequences

- The two engines are equivalent in end state and different in history. This is what makes
  [ADR-0004](0004-engine-specific-migrations.md) necessary.
- **Some ids are historical accidents, reproduced deliberately.** The gap at id 1 in
  `locations` is load-bearing: `migrations/8888.php` inserts a location with the literal id
  1 when `FEATURE_FLAG_STOCK_LOCATION_TRACKING` is off, and would collide if PostgreSQL had
  numbered "Fridge" from 1. The baseline reproduces the accident on purpose.
- `migratedifftest.php` exists to hold this decision honest: it migrates on each engine,
  touches nothing afterwards, and compares every table. It is the phase that catches a
  baseline which has drifted from the history it stands in for.
