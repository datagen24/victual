# Backup and restore

There is no `bin/victual-*` backup command. What follows is derived from how Victual stores
its state — [PostgreSQL as the sole datastore](../getting-started.md#postgresql), with file
storage as either `BYTEA` rows in the same database or a separate filesystem path — rather
than transcribed from an existing operational runbook, because none exists in this
repository as of this writing. Test a restore against a disposable database before relying
on this for a real household's data.

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

```
php bin/victual-migrate                          # create the schema (skip if pg_restore recreates it)
pg_restore -h <DB_HOST> -p <DB_PORT> -U <DB_USER> -d <DB_NAME> victual.dump
```

Restore the storage directory (if used) to the same path `FILE_STORAGE` names, then run
`php bin/victual-migrate` again — it is idempotent, and confirms the restored schema matches
what this version of Victual expects rather than leaving that to be discovered on first
request. An application that finds the schema out of date refuses to serve rather than
guessing, which is the same refusal [Updating and migrations](updating-migrations.md)
describes for an ordinary upgrade.

## What this does not cover

Point-in-time recovery, replication, and continuous backup are properties of how you run
PostgreSQL itself (streaming replication, WAL archiving, a managed provider's own backup
service) rather than anything Victual configures or is aware of. Choose those the way you
would for any other PostgreSQL-backed application.
