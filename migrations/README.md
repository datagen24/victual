# Migrations

Schema and data migrations, applied in number order by
`Victual\Services\DatabaseMigrationService`. The number recorded in the `migrations` table is
a migration's identity; the file suffix only selects which file supplies that number for an
engine.

## File names

| Pattern | Meaning |
|---|---|
| `NNNN.sql` | Portable SQL. Above 0265 it runs on PostgreSQL only, the same as `NNNN.pgsql.sql`. |
| `NNNN.pgsql.sql`, `NNNN.sqlite.sql` | Engine-specific SQL. When a portable file of the same number exists, the engine-specific file replaces it for that engine. |
| `NNNN.php` | Portable PHP, executed inside `DatabaseMigrationService`. |
| `NNNN.pgsql.php` | PHP for one engine. |
| `8888.php` | `DatabaseMigrationService::DOALWAYS_MIGRATION_ID`: runs on every migration run, not once. |

A file whose name does not parse, or whose suffix does not name a real engine, stops the
run instead of being skipped. `DatabaseMigrationService::GetMigrationFiles()` enforces
that rule at runtime.

## Number ranges

- **0001–0255** are SQLite-only history. PostgreSQL does not run them. An empty
  PostgreSQL database loads the squashed schema in [db/pgsql/baseline/](../db/pgsql/README.md)
  and records those numbers instead. `DatabaseMigrationService::BASELINE_MIGRATION_ID` is
  the last number the baseline stands in for.
- **0256–0265** are a matched two-engine set under
  [ADR-0004](../docs/adr/0004-engine-specific-migrations.md). A lone engine-specific file
  carries `@engine-exclusive`, and an engine-specific file that shadows a portable one
  carries `@overrides-generic`. The differential suite still replays this range, so it is
  not rewritten.
- **0266 and above** are PostgreSQL-only under
  [ADR-0008](../docs/adr/0008-postgresql-only-runtime-engine.md).
  `DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID` is 0265, and a `.sqlite.sql` file
  above it is refused.

## Adding a migration

1. Claim the next number in [RESERVATIONS.md](RESERVATIONS.md) before writing the file.
   That file explains why the sequence above the baseline must have no gaps in a
   mergeable tree, and why a number is never reused.
2. Write `NNNN.pgsql.sql`, `NNNN.sql`, or `NNNN.pgsql.php`.
3. Add pgTAP tests for each function, trigger, or logic-bearing view the migration creates.
   [.devtools/pgtap/README.md](../.devtools/pgtap/README.md) is the completeness list.

[`.devtools/pgsql/check-migrations.php`](../.devtools/pgsql/check-migrations.php) checks
the directory against these rules. It fails when a number means different changes on
different engines, when a number is missing above the baseline, when the claims in
`RESERVATIONS.md` disagree with the files, and when a SQLite file exists above the freeze.
`.devtools/pgsql/run-tests.sh` runs it before the suite.

```sh
php .devtools/pgsql/check-migrations.php
```

## Related reading

- [db/pgsql/README.md](../db/pgsql/README.md): the baseline schema, the initial data seeder,
  and importing a SQLite database.
- [ADR-0004](../docs/adr/0004-engine-specific-migrations.md): engine-specific migration rules.
- [ADR-0008](../docs/adr/0008-postgresql-only-runtime-engine.md): SQLite as an input format.
