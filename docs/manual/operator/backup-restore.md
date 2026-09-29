# Backup and restore

Back up PostgreSQL and, when `FILE_STORAGE` is `filesystem`, the uploaded files.
Victual has no `bin/victual-*` backup command. Test a restore against a disposable database
before relying on it for a household's data.

## What to back up

- **Always: the PostgreSQL database.** Every household fact — stock, bookings, recipes,
  users, label identities, and (with `FILE_STORAGE` `database`) every uploaded file — lives
  there. An ordinary `pg_dump` captures all of it in one consistent snapshot:

  ```
  pg_dump -Fc -h <DB_HOST> -p <DB_PORT> -U <DB_USER> <DB_NAME> > victual.dump
  ```

  This is the reason [Configuration](../configuration.md#file-storage) recommends
  `FILE_STORAGE` `database` for a deployment that wants one backup stream rather than two
  that can disagree.

- **Only with `FILE_STORAGE` `filesystem`: the storage directory.** Uploaded files sit
  outside the database, below `<data path>/storage`. Back this up alongside the database
  dump, taken close enough in time that a file referenced by a row in one is not missing
  from the other — a filesystem snapshot taken atomically with the `pg_dump`, or a brief
  write-pause around both, avoids that race. This directory does not exist at all when
  `FILE_STORAGE` is `database`.

- **Not part of application state:** `VIEWCACHE_PATH`'s contents (compiled templates and
  caches — safe to delete and rebuild at any time) and `data/config.php` itself, which is
  configuration you set rather than data the application produced. Keep a copy of the
  latter for convenience, not because losing it loses household data.

## Restoring

Stop the application, and stop the label workers with it. A worker never writes to the
database directly: it claims jobs and reports outcomes over Victual's HTTP API, and the
application performs the writes. Stopping the application is what stops the writes, and
stopping the workers is what stops them retrying against one that has gone away.
Create an empty target database and configure Victual's `DB_*` settings to point to it.
Do not run the migrator before restoring: the custom-format dump contains the schema, so
pre-created tables cause "relation already exists" errors.

```
pg_restore --exit-on-error -h <DB_HOST> -p <DB_PORT> -U <DB_USER> -d <DB_NAME> victual.dump
```

Restore the storage directory (if used) to `<data path>/storage`, then apply any migrations
required by the installed version:

```
php bin/victual-migrate
```

Resume the application and workers only after the restore and migrations succeed.
An application that finds the schema out of date refuses to serve rather than
guessing, which is the same refusal [Updating and migrations](updating-migrations.md)
describes for an ordinary upgrade.

## What this does not cover

Point-in-time recovery, replication, and continuous backup are properties of how you run
PostgreSQL itself (streaming replication, WAL archiving, a managed provider's own backup
service) rather than anything Victual configures or is aware of. Choose those the way you
would for any other PostgreSQL-backed application.

## SQLite source reference errors

`bin/victual-db-import` checks stock location references, and every product reference
(location, purchase unit, stock unit, consume unit, price unit, product group), in the
SQLite source before replacing target data. Each error names affected row, product, and
reference identifiers and includes a query listing every invalid reference.

A dangling product reference answers with every affected column at once, not one at a
time. Repair every column the error names before retrying, rather than discovering the
next one only on the next run. Back up the source, choose an explicit repair for each
reference, and retry. `--force` does not bypass either check. Null stock locations and
deleted locations in booking history remain valid input.

A stored `0` in a product's consume unit or price unit is read as "unset", the same as
NULL. Upstream Grocy has always treated `0` that way for these two columns (its own
migrations 0210 and 0219). A legacy source storing `0` there imports normally, with both
columns landing NULL in the target rather than being refused as a dangling reference. No
other product reference column carries this meaning - a `0` or dangling value anywhere else
is refused.

## SQLite source stock amount errors

`bin/victual-db-import` also checks for a negative `stock.amount` in the SQLite source
before replacing target data, since the target schema refuses one outright
(`stock_amount_non_negative_check`; see [issue #492](https://github.com/datagen24/victual/issues/492)).

A residue within the same small tolerance of zero the application already treats as zero
elsewhere (a value such as `-2.7e-17` that float arithmetic can leave behind) is imported
as exactly `0`, not refused. It is close enough to zero that it is zero, the same rule the
application's own stock comparisons apply. A stock amount of exactly zero, whether it
started that way or was translated from a residue, is always valid input.

Anything more negative than that - a genuine negative amount, such as `-1` - is refused
rather than imported. The error names the affected row count and includes a query listing
every such row (id, product, stock entry, and amount). Back up the source, decide what
each affected row should actually hold, apply that repair to the source, and retry.
`--force` does not bypass this check, and it never clamps a genuine negative value to zero
on your behalf - only an explicit repair changes what is imported.

Preflight and copying read one source snapshot. A copy failure rolls back target
truncation and copied rows. The CLI migrates the target schema before importing; an
import refusal does not roll back that earlier schema migration.
