# Updating and migrations

This fork tracks no release schedule; there is no `update.sh`-style release updater like
upstream grocy's. Pull the code, check `config-dist.php` for settings you have not yet set
(anything unset falls back to the default shown there), and run the migration command.
Migrations are meant to work between releases, not between arbitrary commits, so pulling a
tag rather than a mid-development commit is the safer habit — see
[Getting started](../getting-started.md#updating). A tag is placed only on a commit that
was verified working (`v0.1.0-MVP`, 2026-09-19, is the first); the images built from it
carry the same string as their tag and as `GET /api/system/info` reports.

## Running a migration

```
php bin/victual-migrate
```

Brings the schema up to date and exits. On PostgreSQL it serializes concurrent runs on a
session-level advisory lock, so two pods starting together are safe — the second waits
for the first to finish rather than racing it. An application that finds the schema
out of date refuses to serve rather than guessing at what to do.

The advisory lock lives on the connection that took it, so this command needs a direct
connection to PostgreSQL or a session-mode pool slot. Behind a transaction-mode pooler
(the kind many managed Postgres providers default to), the unlock can land on a different
backend than the one that took it and leak permanently. Point `bin/victual-migrate`
specifically at a connection that does not multiplex transactions across backends if your
database sits behind one.

For an installation with no deployment init step to run this automatically,
`MIGRATE_ON_ROOT_REQUEST` ([Configuration](../configuration.md#database-migrations)) lets a
request to `/` perform the migration instead. It is off by default, since a deployment
normally runs this as its own init step (a Job or an initContainer ahead of the serving
pods) rather than leaving it to whoever loads the page first.

## Rolling back a Helm release

`helm rollback` is a recovery only for a release that added no migration. After an
upgrade that migrated the database, rolling back does not return the instance to the
older version. Migrations only move forward, and the Helm chart has no step that undoes
one.

On kind on 2026-10-08, a rollback from a release at migration 304 to one at migration 288
behaved as follows ([ADR-0038, "Prerequisite 4 on
kind"](../../adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md#prerequisite-4-on-kind)):

- The older `migrate` initContainer exited 0 and logged `Schema is up to date at migration
  304`. Its own migrations stop at 288, so it neither ran nor refused anything. Expect no
  error from it.
- The older pod never became ready. Its code does not match the schema, so it answers
  every request, including the readiness probe's `GET /login`, with 503 and `Victual cannot
  serve requests: the database schema does not match this code.`
- Under the default rolling update, the newer pod kept running and serving: clients got
  200 throughout. `helm rollback --wait` timed out with `context deadline exceeded`, and
  Helm recorded the rollback revision as `failed`.
- The rollback restored the older revision's ConfigMap under the newer pod. The newer pod
  keeps the configuration it started with, but if it restarts it starts with the older
  values. On kind, that would have turned MQTT off.
- A rollback runs no `post-upgrade` hook, so the chart does not run
  `bin/victual-publish-state` ([Home Assistant and
  MQTT](home-assistant-mqtt.md#keeping-the-broker-in-sync)).

To recover, roll forward to the upgraded revision. On kind, `helm rollback victual 3
--wait`, where revision 3 was the upgrade, exited 0. List the revisions with
`helm history victual`.

If the newer pod is already gone, for example because it was evicted or the Deployment
does not use a rolling update, the instance answers 503 until you do one of the
following:

- Deploy the newer version again, with `helm rollback` to the upgraded revision or
  `helm upgrade` with its chart.
- Restore the `pg_dump` backup taken before the upgrade into an empty database and run
  the older version against it ([Backup and restore](backup-restore.md)). Bookings made
  after the upgrade are not in that backup.

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

## Repairing stock location references

Migration 0288 refuses stock whose non-null `location_id` does not exist. Its error
reports the count and up to ten stock, product, and location identifiers. It does not
delete stock or assign a replacement location. List every affected row before choosing
an explicit repair:

```sql
SELECT s.id, s.product_id, s.location_id
FROM stock s LEFT JOIN locations l ON l.id = s.location_id
WHERE s.location_id IS NOT NULL AND l.id IS NULL
ORDER BY s.id;
```

Back up the database and stop application writes before repairing records. Determine the
physical location of each affected stock entry, then explicitly correct its reference
or restore the missing location from reliable records. Keep quantities unchanged.
Run the query again and rerun `php bin/victual-migrate` after resolving every result.
Null stock locations are permitted; historical `stock_log` references are not constrained.

The migration blocks writes while it validates references and creates the index and
constraint. Schedule a quiet maintenance window. Its lock timeout is five seconds and
its statement timeout is sixty seconds; these limits apply per lock and statement, not
to the entire migration. A timeout rolls back the migration and its version record.
Inspect active transactions before retrying; do not assume `NOT VALID` would release a
lock before the migration transaction commits.

After migration, deleting a location with stock returns a readable refusal. Move or
consume the stock before deleting the location. Undo refuses any restoration to a deleted
non-null historical location and rolls back the complete undo, including correlated
bookings. It does not substitute the product's current default location.

## Migration 0301: timestamps become instants

Migration 0301 converts every stored timestamp from a wall clock in the server's configured
time zone to an instant (`TIMESTAMPTZ`), and from then on the API sends every instant as
RFC 3339 in UTC, `2026-10-04T18:30:00.000000Z`
([ADR-0027](../../adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
decision 2). Calendar dates such as best-before dates are not converted. Clients, Home
Assistant templates and scripts that read a timestamp as text see UTC after the upgrade.

The configured time zone is PHP's `date.timezone`, which the container images set to `UTC`.
Every stored wall clock is read in the zone in force when the migration runs. A server whose
zone was changed after rows were written converts the older rows at the new zone's offset,
and nothing in the data can detect that. On a server that has always run in UTC the
conversion changes no instant.

### Before upgrading

1. Take a backup with `pg_dump` ([Backup and restore](backup-restore.md)) and test that it
   restores. The conversion cannot be undone in place: turning a `TIMESTAMPTZ` back into a
   wall clock loses the instant it names.
2. Run the preflight with the configuration the migration will use:

   ```
   php bin/victual-timestamp-preflight
   ```

   It changes nothing. It prints the zone, how many values each column holds, how many fall
   in an hour that repeats when daylight saving ends, and anything the migration would
   refuse. It exits `0` when the migration would convert everything and `2` when it would
   refuse; `--json` prints the same report as JSON.

The migration refuses, and changes nothing, when a stored value is a wall clock the zone
skipped when daylight saving began, an infinity, or a year outside 1 to 9999. Correct those
rows from your own records (a skipped wall clock cannot be guessed at), then run the
preflight again. A value in a repeated hour becomes the earlier of its two instants, the
same rule a new write without an offset follows.

A refusal undoes migration 0301 only. Each migration commits on its own, so the migrations
before 0301 in the same run stay applied. The 2026-10-06 upgrade rehearsal measured this
([evidence](../../../.devtools/pgsql/upgrade-rehearsal/EVIDENCE.md)): an upgrade from
0.2.0-MVP that 0301 refused left the database at migration 300, with every timestamp
unconverted. The 0.2.0-MVP application does not serve that schema, because its migrations
do not match. Correct the rows and run `bin/victual-migrate` again to continue from 0301,
or restore the backup to go back.

### Running it

Stop the application and the label workers, run `php bin/victual-migrate`, then start the
new version. The application refuses to serve a schema its code does not match, so an old
application cannot run against the converted schema and a new one cannot run against the
old schema. Run `bin/victual-publish-state` afterwards so Home Assistant's retained states
carry the new rendering.

The migration rewrites every table with a timestamp column under an exclusive lock, one
table at a time inside one transaction, and recreates every view. A refusal or a failure
rolls the whole migration back. Its lock timeout is thirty seconds.

### Recovery

Do not try to reverse the migration by altering the columns back to `TIMESTAMP`. To return
to the previous version, stop the application, restore the backup taken before the upgrade
into an empty database, and run the previous version against it. Bookings made after the
upgrade are not in that backup, so prefer correcting forward when the problem is a single
row.

## Migration 0303: stock-entry label retirement events

Migration 0303 adds the table that lets an undo bring back a stock-entry label
([ADR-0037](../../adr/0037-an-undo-of-a-whole-row-consumption-revives-the-stock-entry-label-it-retired.md)).
From then on every retirement of a stock-entry label writes one row to
`stock_label_retirements`. The migration also writes one closed `legacy` row for each
stock-entry label that is already retired. Those labels are never restored by an undo; a
person prints a new label for them, as before. The migration checks that every retired
stock-entry label has exactly one row and rolls back if not. It needs no preflight and adds
an index to `print_jobs`.

Run it the usual way: the migration first, then the new version of the application and the
label workers. An older application refuses to serve the migrated schema ("the database is
ahead of the code"). No old and new version therefore serve together. An old version cannot
claim a print job that the new version keeps from printing after its label comes back. For the same reason, returning to the previous
version means restoring the backup taken before the upgrade; there is no down migration.

A consumption booked before the upgrade retired its label without naming its booking, so an
undo after the upgrade leaves that label retired. Only consumptions booked by the new version
can bring their label back.

## Migration 0304: booking lineage

Migration 0304 records which booking each unit of stock came from
([ADR-0036](../../adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)).
It adds two tables and fills them from the existing ledger. It does not change `stock`,
`stock_log`, `stock_entry_origins` or `labels`, and no API response changes.

### What the migration does with existing history

The migration groups stock rows and bookings into families: a `stock_id` plus the split
entries `stock_entry_origins` links to it. It then classifies each family by arithmetic.

| Family | Shape | Result |
|---|---|---|
| Exact | One live purchase, and the ledger and the rows agree. | Every row and booking is attributed to that purchase. |
| Exact by arithmetic | Two or more live purchases merged into one row, nothing else. | The row holds each purchase's amount. |
| Unknown | Anything else, such as a merged row that was later consumed or edited. | The row's quantity is unattributed. |
| Nothing | Nothing live and no rows. | Nothing is written. |

Nothing is guessed. In an unknown family, consuming, opening, transferring, editing and
weighing work as before, and so does undoing those new bookings. The purchases of an
unknown family can no longer be undone, and the refusal says so. Older consumptions and
edits in such a family keep the undo rules they had.

The migration refuses an amount that is not a finite number (`NaN` or infinity) in `stock`
or `stock_log`, naming the row or booking, and checks the result before it commits. Either
failure rolls the whole migration back, tables included; correct the named row and run the
migration again. A second run writes nothing. ADR-0036's feasibility spike measured about
4 seconds per 100,000 bookings on a laptop.

Run it the usual way: the migration first, then the new version of the application. An older
application refuses to serve the migrated schema, so the two versions never book together.
There is no down migration; to return to the previous version, restore the backup taken
before the upgrade.

### What changes after the upgrade

- An undo finds a booking's units by what it booked, not by which row they are on. It now
  accepts undos it used to refuse, for example the earlier of two merged purchases, or an
  opening or edit of a row that was merged since. It still refuses an undo when a later
  booking took units from the same purchase.
- `bin/victual-compact-stock` no longer rewrites `stock_id` values in `stock`, `stock_log`
  or `stock_entry_origins`. The kept row keeps its own `stock_id`. Rows split off by a
  transfer or an opening are merged by the same rules as any other row.
- The database role that runs `bin/victual-compact-stock` needs a different grant list;
  the command's header lists it. The application role in
  [`deploy/postgres/roles.sql`](../../../deploy/postgres/roles.sql) already has it.
- `bin/victual-db-import` rebuilds the lineage from the imported ledger with the same rules,
  and refuses the import if the result does not check out.

### Merging is not scheduled

The deployment in [`deploy/`](../../../deploy/README.md) does not run
`bin/victual-compact-stock` on a schedule, and this release does not add one. Stock rows are
merged only when you run the command yourself. Nothing depends on merging: unmerged rows are
the normal state, and the research behind ADR-0033 and ADR-0036 found no measurement that
shows merging is needed.

