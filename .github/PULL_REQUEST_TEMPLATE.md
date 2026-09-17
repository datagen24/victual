## What and why

<!-- What changed and what problem it solves. Link the plan or review item it comes from
     (docs/plans/*.md, docs/architecture-review.md) if there is one. -->

## Compatibility

<!-- Answer each line, or write "none" if nothing here applies. -->

- **Schema change?** State the migration number and claim it in `migrations/RESERVATIONS.md`.
  Migrations above 0265 are PostgreSQL-only: the SQLite line is frozen there under
  ADR-0008, and `.devtools/pgsql/check-migrations.php` refuses a `NNNN.sqlite.sql` above it.
  Within 0256-0265 the two-engine rules still apply — a portable `NNNN.sql`, a per engine
  pair, or an engine-exclusive file carrying `@engine-exclusive` (or `@overrides-generic`,
  if it shadows a portable file of the same number). See `db/pgsql/README.md`.
- **Changes an existing API response?** Say which endpoint and which field or status code.
  New entities and fields are additive and just need a note; anything that changes an
  existing response shape is called out explicitly rather than slipped in.
- **Needs a manual step on upgrade?** Config, auth, data migration, anything that does not
  survive a plain pull and restart.

## Verification

<!-- What was actually run, on a booted instance against a real database. "It loads
     cleanly" and "lint passes" are not verification. -->

- [ ] Exercised the changed paths on a running instance
- [ ] `.devtools/pgsql/run-tests.sh`, where the change touches SQL. The suite still
      compares both engines over the schema as it stood at the SQLite freeze; a new
      PostgreSQL-only view or trigger has no counterpart to be compared against, so say
      what you checked it against instead
- [ ] Result sets compared before and after, where the change rewrites a query
- [ ] `SUITE_COVERAGE=1 .devtools/pgsql/run-tests.sh` reported: total unchanged or higher,
      and every touched file at 75% or above, or no lower than before (the floor is 75%,
      target 85+; see [issue 192](https://github.com/datagen24/victual/issues/192))

## Notes for review

<!-- Decisions that could reasonably have gone the other way, and anything deliberately
     left undone. Optional. -->
