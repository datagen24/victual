# ADR-0004: Engine-specific migrations are marked, and migration numbers are never compared across engines

- **Status:** **Accepted**, following from [ADR-0002](0002-squashed-baseline.md).
- **Decider:** datagen24 (maintainer), retrospectively — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30, retrospectively.
- **Referenced by:** [10](../plans/landed/10-cold-start-statelessness.md) Q6 and Q7,
  `DatabaseMigrationService::GetLatestMigrationNumber`, `DatabaseImporter`,
  `.devtools/pgsql/check-migrations.php`.

## Context

Once PostgreSQL stopped replaying history, the two engines could legitimately sit at
different migration numbers while both being fully migrated. `0256.sqlite.sql` is the first
real case — a SQLite-only type defect that PostgreSQL never had. A missing counterpart and
a deliberate omission look identical in a directory listing.

## Decision

Every migration from 0256 on leaves both engines correct: a portable `0256.sql`, or a pair
of `0256.sqlite.sql` and `0256.pgsql.sql`, where the engine-specific file wins.

Three rules enforce it:

1. A lone engine-specific file must carry `@engine-exclusive` and say why the other engine
   is already correct — in the file, not in the docs.
2. An engine-specific file shadowing a portable one of the same number must carry
   `@overrides-generic`.
3. A migration whose name does not parse, or whose suffix is not a real driver, **aborts
   the run** rather than being skipped in silence. `0256.sqlight.sql` used to be a file that
   ran nowhere and told nobody.

**Nothing compares one engine's migration number to the other's.**
`GetLatestMigrationNumber()` takes a dialect for this reason.

## Consequences

- The `migrations` table is per-engine by design. It is excluded from `trigdifftest.php`'s
  table comparison and is not copied by `DatabaseImporter` — a target carrying the source's
  numbers would skip a future migration of its own believing it had already run.
- `DatabaseImporter` checks each side against the latest migration for *its own* engine.
- Guards 1 and 2 reason about the repository; neither can answer what a running database
  actually ran. That gap is [plan 10](../plans/landed/10-cold-start-statelessness.md) Q7's
  `dialect` column, which is deliberately diagnostic and must never become load-bearing —
  a database migrated before the column existed cannot supply it.
