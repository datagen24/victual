# Updating and migrations

This fork tracks no release schedule; there is no `update.sh`-style release updater like
upstream grocy's. Pull the code, check `config-dist.php` for settings you have not yet set
(anything unset falls back to the default shown there), and run the migration command.
Migrations are meant to work between releases, not between arbitrary commits, so pulling a
tag rather than a mid-development commit is the safer habit — see
[Getting started](../getting-started.md#updating).

## Running a migration

```
php bin/victual-migrate
```

Brings the schema up to date and exits. On PostgreSQL it serializes concurrent runs on a
session-level advisory lock, so two pods starting together are safe — the second simply
waits for the first to finish rather than racing it. An application that finds the schema
out of date refuses to serve rather than guessing at what to do.

The advisory lock lives on the connection that took it, so this command needs a direct
connection to PostgreSQL or a session-mode pool slot — behind a transaction-mode pooler
(the kind many managed Postgres providers default to), the unlock can land on a different
backend than the one that took it and leak permanently. Point `bin/victual-migrate`
specifically at a connection that does not multiplex transactions across backends if your
database sits behind one.

For an installation with no deployment init step to run this automatically,
`MIGRATE_ON_ROOT_REQUEST` ([Configuration](../configuration.md#database-migrations)) lets a
request to `/` perform the migration instead — off by default, since a deployment normally
runs this as its own init step (a Job or an initContainer ahead of the serving pods) rather
than leaving it to whoever loads the page first.

## Moving from SQLite

See [Getting started](../getting-started.md#moving-an-existing-sqlite-installation-across)
for `bin/victual-db-import` — this only applies once, when bringing an existing grocy or
pre-retirement Victual SQLite database onto PostgreSQL for the first time. There is no
ongoing SQLite runtime mode to migrate between; PostgreSQL is the only engine going forward.

## Warming the view cache

`bin/victual-warm-cache` precompiles Blade templates, the route cache and the HTML
sanitizer's definition cache into `VIEWCACHE_PATH`
([Configuration](../configuration.md#view-cache)). A container image runs this at build
time and mounts the result read-only; a checkout install does not need to run it at all —
the cache builds itself lazily on first use if you do not.
