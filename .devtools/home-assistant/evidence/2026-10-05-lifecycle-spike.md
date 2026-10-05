# Lifecycle coordination spike

Run 2026-10-05 for [plan 35](../../../docs/plans/35-home-assistant-target.md)'s
"Lifecycle spike", with [`../lifecycle-spike/spike.sh`](../lifecycle-spike/spike.sh). This is
container-level evidence from podman on a developer Mac. It is not Supervisor evidence.

## Environment

| Item | Value |
|---|---|
| Source | Branch `claude/opus5_ha-addon-pilot-66ee2a`, master `46a35e34` plus plan 35 documents; application code unchanged from master |
| PostgreSQL | 16.15 (`postgres:16`), on tmpfs, roles from `deploy/postgres/roles.sql` run in the target database |
| Old side | `ghcr.io/datagen24/victual-{app,web,migrate}:0.2.0-MVP`, arm64, pulled anonymously; schema at migration 0288 |
| New side | This tree in `localhost/victual:dev-ha654` (root `Dockerfile`, `dev` target), served with `php -S`; schema at migration 0301 |
| Serving check | `/login` status through `SchemaVersionMiddleware`, and `pg_locks` read as `victual_app` |

The migration lock appears in `pg_locks` as `locktype advisory, classid 0,
objid 1986947956, objsubid 1`. `victual_app` can read that row.

## Results

| Case | Result | Observation |
|---|---|---|
| S1 Empty database | Partial | Serving answered 503 before migration and 200 after. The 503 said "the database could not be queried", SQLSTATE 42501, not "nothing migrated". |
| S2 Older schema | Pass | At 0288 the new code answered 503 "schema does not match this code"; after the upgrade to 0301 it answered 200. |
| S3 Newer schema | Pass for serving; migrator does not refuse | Serving answered 503 naming migration 9990 against code at 301. `bin/victual-migrate` exited 0 with "Schema is up to date at migration 9990". |
| S4 Unreadable | Partial | A wrong application password and a stopped PostgreSQL both gave 503 "the database could not be queried". The message is the same as S1's empty database. |
| S5 Lock held | Pass for the lock check; the middleware alone does not cover the lock | With the schema current and `victual_migrate` holding the lock, `/login` answered 200 while `pg_locks` showed the lock granted. |
| S6 Running during upgrade | Pass, `/login` only | 20,000 `stock_log` rows over 10 products. The 0.2.0 serving pod answered 200 to 13 requests until the first new migration committed, then 503 to all 3,506 requests during the 809-second upgrade: no 500, slowest response 0.37 s. |
| S7 Failed migration | Pass | A copy of `migrations/` with `9900.pgsql.sql` creating a table then dividing by zero: the migrator exited 1 with SQLSTATE 22012, the table was rolled back, and serving code that includes 9900 answered 503 "schema does not match". |
| S8 Concurrent migrators | Pass | Two migrators on an empty database: `pg_locks` showed one granted and one waiting, both exited 0, and the database held 300 migration rows and one user, as after a single run. |
| Interrupted upgrade | Finding | Killing the migrator's container did not end its PostgreSQL session, as described in "An interrupted upgrade keeps running in PostgreSQL". |

### S1 and S4: an empty database reads as a privilege error

`PostgresDialect::OnConnected` (`services/Database/PostgresDialect.php:121-193`) creates
`system_db_changed_time` with `CREATE TABLE IF NOT EXISTS` when the table is missing.
`victual_app` cannot create tables, so on an empty database every connection fails with
SQLSTATE 42501 before `SchemaVersionMiddleware` reaches the `migrations` table. The
middleware then reports "This is not a migration problem: the schema version could not be
read at all". With the application identity alone, the serving check cannot tell an empty
database from a wrong password or a stopped server.

### An interrupted upgrade keeps running in PostgreSQL

The first attempt at S6 loaded 2,000,000 `stock_log` rows for a single product. Migration
0292 (`migrations/0292.pgsql.sql`) rebuilds the price caches one product at a time through
the `products_average_price` and `products_last_purchased` views, so with every row on one
product it ran for minutes in a single transaction. Migrations 0289–0291 had committed.

At 13:13 UTC the migrator's container was killed. Its PostgreSQL backend kept running 0292
and kept holding the migration advisory lock, because the server does not notice a vanished
client until it next writes to the connection. At 13:47:40 the backend had been in that
transaction for 46 minutes; it was then ended with `pg_terminate_backend`. The schema stayed
at 0291, and the old serving side answered 503 "Applied but unknown to this code: 289-291"
throughout.

A lock-based serving check therefore waits for as long as an orphaned migration runs. A
Supervisor stop of the main add-on also stops its container, so the same behaviour is
expected there, and a restarted main add-on would wait on the lock behind its predecessor's
session. Neither was measured on a Supervisor. The 2,000,000-row, one-product fixture is
not realistic household data. The S6 runs below measured 0292 on spread-out data.

### S6 and the cost of migration 0292

S6 was run three times. With 200,000 rows over 1,000 products and with 20,000 rows over
200 products, 0292 had not finished after 20 and 10 minutes, and the runs were stopped. The
completed run used 20,000 rows over 10 products with `SPIKE_DEBUG=1` and
`SPIKE_EXPLAIN_ANALYZE=1`. The upgrade from 0288 to 0301 took 809 seconds, of which 0292 took
801 seconds: about 80 seconds per product, in two statements.

`auto_explain` with actual row counts shows the cause. Both `products_average_price` and
`products_last_purchased` reach `stock_edited_entries`, whose `resolved` CTE
(`migrations/0267.pgsql.sql`, around line 164) is planned as a nested loop over itself:

```
GroupAggregate (rows=1) (actual rows=20000 loops=1)
  -> CTE Scan on resolved r_1 (actual rows=20000 loops=1)
Nested Loop, Join Filter: (r_1.origin_stock_id = sl_new.origin_stock_id)
  -> CTE Scan on resolved sl_new (actual rows=0 loops=20000)
       Rows Removed by Filter: 20000
```

The planner estimates one row for the aggregate and compares 20,000 rows with 20,000 rows on
every call, whatever the product. The cost grows with the square of the whole ledger, so it
is not an artefact of how the fixture spreads rows over products. Migration 0292 calls the
two views once per product. The repair is [migration 0302](../../../migrations/0302.pgsql.sql),
outside plan 35. It does not shorten this upgrade, because 0292 runs before it. S6's request loop
covered `/login` only, so it shows the middleware's response during the upgrade, not reads of
tables the migration had locked.

### Harness errors corrected during the run

- `deploy/postgres/roles.sql` must run while connected to the target database. The first
  run connected to `postgres`, so `victual_migrate` could not create tables. Fixed in the
  harness.
- The first S7 mounted the failing migration as a single file into the read-only source
  mount, which made podman create an empty `migrations/9900.pgsql.sql` in the working tree.
  The next migrator runs then failed with `ValueError: PDO::exec(): Argument #1 ($statement)
  must not be empty` (`services/DatabaseService.php:109`, exit 255), including the first
  S8. The file was removed, S7 now mounts a copied directory, and S7 and S8 were rerun; the
  table rows above are from the rerun. An empty migration file ends the migrator with an
  uncaught `ValueError` instead of a reported failure.
