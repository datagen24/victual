# 0.4.1 hotfix to 0.4.2 candidate database rehearsal

Run date: 2026-10-15. Source tag `v0.4.1-hotfix1` is
`b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; candidate HEAD is
`80b09d12d39cbfae1d26d2ae7e1cbe514e692987`.

This ran in the disposable `victual_upgrade042_audit` database on the existing
`victual-api042-pg` test container, PostgreSQL 16.15
(`localhost/victual-pg:pgtap`). Both source and candidate used
`localhost/victual:dev` with PHP 8.5.10. `git diff --name-status` between the
SHAs returned no changes under `migrations/`, `db/pgsql/baseline/`,
`composer.lock`, or `Dockerfile`; this is a no-op schema upgrade.

The old tag was exported with `git archive <source-sha> -o old.tar` and
extracted to `old/`. Its own `bin/victual-migrate` initialized the database at
migration 312. `data/populate.php`, loaded under that old source tree, inserted
`Upgrade audit oats`, booked three units through old `StockService::AddProduct`,
and inserted `Upgrade audit chore` with three executions 24 and 25 hours apart.
Before the candidate run, counts were one product, one stock entry, one
stock-log row and three chore-log rows. The initial database also had the
standard seeded user, location and quantity units.

The candidate `bin/victual-migrate` ran twice and exited 0 both times. Each
reported `Schema is up to date at migration 312.` Schema-only and data-only
`pg_dump` files were taken before the first candidate run, after it, and after
the second run. Normalization removed only the two random `\restrict` and
`\unrestrict` guard lines that `pg_dump` writes anew on each invocation. It
excluded no application table, column or row. The normalized files were
byte-identical at all three points:

| Dump | SHA-256 at all three points |
|---|---|
| Schema | `c7957dfefff22f9893eb57850886dde541a67e33324204cf857afaf873ddbb17` |
| Data | `673e6dd70914381baab96dad7e286e7659a3d10c962ae59623bf6b4b7f2af1c9` |

The candidate service read returned
`{"product":"Upgrade audit oats","stock_amount":3,"chore":"Upgrade audit chore","tracked_count":3,"average_execution_frequency_hours":24.5}`.

The evidence files are `before-{schema,data}.sql`,
`after1-{schema,data}.sql`, `after2-{schema,data}.sql`, and their
`.normalized.sql` counterparts in this directory. `data/populate.php` and
`data/read-candidate.php` are the exact fixture and read probes. The database
was dropped after capture; the scratch evidence remains for review.

Representative commands (the database-specific values are in `data/config.php`):

```sh
git archive b5532aafd7b91306f7e98dd3361ba82d1ebbe5da -o /tmp/victual-042-upgrade-audit-20261015/old.tar
tar -xf /tmp/victual-042-upgrade-audit-20261015/old.tar -C /tmp/victual-042-upgrade-audit-20261015/old
docker exec victual-api042-pg createdb -U victual victual_upgrade042_audit
docker run --rm --network victual-api042 -v /private/tmp/victual-042-upgrade-audit-20261015/old:/app -v /app/packages -v /private/tmp/victual-042-upgrade-audit-20261015/data:/audit-data -w /app -e VICTUAL_DATAPATH=/audit-data -e PGHOST=victual-api042-pg -e PGPORT=5432 -e PGUSER=victual -e PGPASSWORD=victual -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=UpgradeAuditOnly-1234 localhost/victual:dev php bin/victual-migrate
docker run --rm --network victual-api042 -v /private/tmp/victual-042-upgrade-audit-20261015/old:/app -v /app/packages -v /private/tmp/victual-042-upgrade-audit-20261015/data:/audit-data -w /app -e VICTUAL_DATAPATH=/audit-data -e PGHOST=victual-api042-pg -e PGPORT=5432 -e PGUSER=victual -e PGPASSWORD=victual localhost/victual:dev php /audit-data/populate.php
docker run --rm --network victual-api042 -v /Users/speterson/src/grocy:/app -v /app/packages -v /private/tmp/victual-042-upgrade-audit-20261015/data:/audit-data -w /app -e VICTUAL_DATAPATH=/audit-data -e PGHOST=victual-api042-pg -e PGPORT=5432 -e PGUSER=victual -e PGPASSWORD=victual localhost/victual:dev php bin/victual-migrate
docker run --rm --network victual-api042 -v /Users/speterson/src/grocy:/app -v /app/packages -v /private/tmp/victual-042-upgrade-audit-20261015/data:/audit-data -w /app -e VICTUAL_DATAPATH=/audit-data -e PGHOST=victual-api042-pg -e PGPORT=5432 -e PGUSER=victual -e PGPASSWORD=victual localhost/victual:dev php /audit-data/read-candidate.php
```

For each `before`, `after1` and `after2` capture, `pg_dump` ran in a
`localhost/victual-pg:pgtap` sidecar on `victual-api042`, with
`PGHOST=victual-api042-pg`, and wrote to `/audit/<capture>-schema.sql` using
`--schema-only --no-owner --no-privileges`, and to
`/audit/<capture>-data.sql` using
`--data-only --inserts --column-inserts --no-owner --no-privileges`.

This does not prove a production Nix image rollout, real household data
compatibility, failure refusal, backup restoration or recovery. The data-only
dump warned that some tables have circular foreign keys and may need a full
dump or disabled triggers to restore. The old-to-candidate schema was already
at migration 312, so the candidate did not apply an outstanding migration.
