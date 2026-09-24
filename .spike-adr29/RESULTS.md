# ADR-0029 prerequisite evidence

Recorded 2026-09-23 against `ec898f9c`, in `/private/tmp/victual-461`, branch
`codex/gpt6_stock-location-fk-461`. The branch adds the proposed ADR and these isolated
experiments. It changes no application PHP, migration, or production schema.

## Reproduce

Use Podman and Python 3 from the repository root. Build the disposable PostgreSQL 16
image with pgTAP from the repository's Dockerfile; the build needs registry and Debian
package access. The original experiment used `localhost/victual-pg:16-pgtap` and
PostgreSQL 16.15 on aarch64 Debian.

```sh
(
  set -eu
  podman build -f .devtools/pgtap/postgres.Dockerfile \
    -t localhost/victual-adr29:16-pgtap .
  podman run -d --name victual-461-spike \
    -e POSTGRES_HOST_AUTH_METHOD=trust localhost/victual-adr29:16-pgtap
  trap 'podman rm -f victual-461-spike >/dev/null' EXIT
  attempt=0
  until podman exec victual-461-spike \
    pg_isready -h 127.0.0.1 -U postgres -t 1 >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
      echo 'PostgreSQL did not become ready after 30 attempts' >&2
      exit 1
    fi
    sleep 1
  done
  python3 .spike-adr29/probe.py
  python3 .spike-adr29/source_probe.py
)
```

The readiness check uses TCP to avoid accepting the image's temporary initialization
server, which listens on a Unix socket. It stops after 30 failed attempts; each database
check has a one-second timeout. The subshell stops on failure and removes its container
on exit, including when readiness fails.

Do not point these scripts at an installation. `probe.py` creates and drops its own
`adr29_probe` schema inside the named disposable container. `constraint.sql` creates
`adr29` inside a transaction that it rolls back. The SQLite probe opens committed fixtures
read-only and changes only in-memory copies. `ADR29_CONTAINER` selects a different
container name when needed.

## Results

The revised reproduction block passed on 2026-09-24 in the same working copy, based on
`ed81edd3`. The image build reused the local build cache; a fresh container passed the
readiness check, all PostgreSQL probes, and both SQLite fixture probes. The exit trap
removed the container. This rerun did not test uncached registry or package downloads.

All 12 pgTAP assertions passed. They exercise valid and invalid inserts and updates,
referenced deletion and key update, nulls, history, unused deletion, validated constraint
state, and foreign-key enforcement with user triggers disabled.

The concurrent-session probes passed in both orders. A stock insert held a location
reference while deletion waited and then failed. A location deletion held its row while
an insert waited and then failed. The driver observed `pg_stat_activity.wait_event_type`
before releasing the first transaction; elapsed sleep time was not the readiness test.
These are database probes, not application booking or HTTP tests.

The migration prototype refused stock row 1 referencing missing location 99. The row and
absence of a foreign key survived the failed transaction unchanged. An explicit fixture
repair allowed the same migration to succeed. The prototype also creates the proposed
reference index. It does not implement the production bounded diagnostic report or
migration-version bookkeeping.

A shortened 100 ms lock timeout refused a conflicting lock acquisition. A shortened
statement timeout rolled back an earlier update. Another session could not insert while
an `ADD CONSTRAINT ... NOT VALID` followed by `VALIDATE CONSTRAINT` transaction remained
open. That experiment adds no stronger DDL lock before the addition, so its blocked
insert demonstrates the addition's retained lock.

Both supported SQLite fixtures, migrations 0255 and 0265, contained no dangling stock
locations. In-memory copies with one invalid reference produced the expected stock,
product, and location identifiers. Null references were excluded. These probes establish
the diagnostic query's behavior; they do not run `DatabaseImporter`.

## Source audit

Reproduce these findings by inspecting the named symbols at the base revision above.

| Symbol | Finding and design consequence |
|---|---|
| `DatabaseMigrationService::ExecuteSqlMigrationWhenNeeded()` | Begins a transaction before executing SQL and commits after recording the migration. A single SQL file cannot release its initial lock before validation. |
| `DatabaseService::InTransaction()` | Nested work joins the existing transaction; an escaping exception rolls back the outer operation and clears before-commit callbacks. Preserve exception propagation. |
| `StockService::UndoBooking()` | Consume/negative inventory, transfer-from and stock-edit-old restore historical locations. Correlated members share the outer transaction. |
| Transfer-from branch | A recreated row omits `location_id`. The insert trigger selects today's product default. Add an explicit historical location and test a different product default. |
| `StockService::UndoTransaction()` | Locks affected products, then undoes all transaction bookings inside one transaction. Test failure after an earlier member has written. |
| `GenericEntityApiController::DeleteObject()` | Checks location children but not stock. Translate the named foreign-key race as well as adding a readable precheck. |
| `BaseApiController::GenericErrorResponse()` | Sanitizes messages beginning with `SQLSTATE[`. A raw PDO exception cannot supply the required user explanation. |
| Stock default-location trigger | Populates a null insert from `products.location_id`. Nullable schema does not mean every null insert remains null. Preserve that behavior. |
| `DatabaseImporter::GetCommonTables()` | Uses target table-name order, placing locations before stock. Keep that dependency explicit in regression coverage. |
| `DatabaseImporter::SetTriggersEnabled()` | Disables only `TRIGGER USER`; foreign-key triggers remain active. The pgTAP experiment confirms that distinction. |
| `DatabaseImporter::Import()` | Truncation and copy share a target transaction. Add source preflight before target mutation and keep preflight/copy on one source snapshot. |
| `bin/victual-db-import` | Migrates the target before opening the source. An import refusal cannot promise to undo that earlier schema migration. |

## Limits and delivery gates

No production inventory or migration duration was measured. The 5-second lock and
60-second statement limits are proposed policy values. The isolated model has no stock
business triggers, API authorization, label retirement, or outbox; it cannot demonstrate
application rollback or importer atomicity.

After the maintainer's decision, implementation requires the PHPUnit and pgTAP cases
listed in the ADR, against the actual application and migration. PostgreSQL 15 delivery
checks also remain. No application coverage measurement was run for this proposal-only
change; there are no changed application files or executable application lines. The
implementation PR must report a fresh complete-run comparison, not reuse an earlier
coverage percentage.
