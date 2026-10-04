# ADR-0036 feasibility spike

Evidence for [ADR-0036](../docs/adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md).
Disposable schemas only. Nothing under `services/`, `migrations/`, `controllers/` or
`tests/` changed. The reference model is not a candidate implementation.

- **Date:** 2026-10-03 (local time).
- **Commit:** `a7bf78a31d70f1351019aa6d6cc1e22479402b59` (`master`), clean working copy.
  `run.sh` extracts that tree with `git archive HEAD` and overlays this directory.
- **Host:** macOS (Darwin 27.0.0, Apple Silicon), podman 6.0.2, libkrun VM with 4 CPUs and 8 GiB.
- **Images:** `localhost/victual:dev` (id `97aabbf5cf1f`, PHP 8.5.10) and
  `localhost/victual-pg:pgtap` (id `3ad78171b5ab`, PostgreSQL 16.15). The model probe was
  repeated against `docker.io/library/postgres:15` (15.19), the minimum version the
  application enforces.
- **Schema:** each run migrates a throwaway schema to HEAD through
  `DatabaseMigrationService::MigrateDatabase()` (the `PgsqlSchemaTestCase` path) and drops it.

## Commands

```sh
.spike-adr36/run.sh baseline > .spike-adr36/evidence/baseline.json
.spike-adr36/run.sh model    > .spike-adr36/evidence/model.json
ADR36_N=100000 .spike-adr36/run.sh model          # larger synthetic ledger (200,114 bookings)
PG_IMAGE=docker.io/library/postgres:15 ADR36_N=20000 .spike-adr36/run.sh model
```

| File | Role |
|---|---|
| `baseline-probe.php` | Runs today's real `StockService` (purchase, maintenance merge, consume, open, transfer, edit, undo) and records stock and ledger state. |
| `proposed.sql` | The two tables and the backfill classification, as a spike-only function. Not a migration. |
| `ref-model.php` | The ADR's rules as plain SQL over the real `stock` and `stock_log`: writers, merge, undo, invariants I1 to I4. |
| `model-probe.php` | Legacy fixtures made by the real service, backfill, constraint checks, the worked examples, concurrency, cost. |
| `conc-child.php` | Child process for the two-connection tests. |
| `evidence/` | Output of the runs above. |

## Results

**Baseline (real service, PostgreSQL 16.15).** The runs reproduce, through the service rather
than by reading code: the merge rewrites `stock_log.stock_id`; the surviving row (`MAX(id)`)
and the surviving tag (`MIN(tag)`) come from different purchases; after a merge the earlier
purchase cannot be undone first even with no consumption; after a partial consume and its undo
the later purchase is refused as "split across multiple rows"; two merged opened rows refuse
both opening undos; a transfer split makes the shared-tag guard skip an entire group,
including unrelated new purchases. Purchases carry `stock_row_id` NULL. Test suites were not
run.

**Model.** Seventeen scenarios ran with invariants I1 to I4 checked after every state:
no violation and no error on PostgreSQL 16.15 or 15.19. Refusals left the state unchanged
(compared by value) in every case.

**Backfill on fixtures built by the real service.** Classes E (4 families), X (1), U (4) and
N (1) as expected. `stock`, `stock_log` and `stock_entry_origins` hashed byte-identical before
and after; per-product totals unchanged; a second run inserted nothing.

**Constraints.** Seven invalid inserts were rejected (duplicate pool, pool with a recorded
basis, non-positive amount, missing row, missing lot, zero allocation, missing booking). Both
cascades worked.

**Concurrency (two real connections).** A consume issued during maintenance waited 1.5 s and
then ran against the merged row. An issuance that reached a locked row waited, found it
deleted, and inserted no label. A label committed before the eligibility re-read kept its row
out of the group. Maintenance waiting behind an issuance in flight waited about 1 s, then
re-read and merged nothing. No deadlock in either interleaving.

**Interruption.** An injected failure after the first group left state byte-identical. A
complete run merged two groups; the next merged none.

**Average price.** `products_average_price` for a merged row edited down to 4, 2 and 1 units
equalled an unmerged control: 2.2, 2.3333, 2.5.

**Cost (synthetic ledger, one allocation per booking).**

| Rows | Value |
|---|---|
| Bookings / `stock` rows | 100,114 / 40,047 |
| `stock_log` with indexes | 26,722,304 bytes |
| `stock_row_lots`, `stock_booking_lots` with indexes | 5,636,096 and 14,368,768 bytes |
| Backfill | 4.3 s (7.6 s for 200,114 bookings) |
| Index lookups (dependency, where-is-lot, lots-of-row, allocations) | 0.005 to 0.019 ms; existing per-tag dependency query 0.016 ms |

## Limits

- The reference model implements the ADR's rules for the operations in the examples. It has
  no measured-container handling, no recipes, no HTTP layer and no label service (labels were
  inserted directly, and the import lock was taken by hand).
- Legacy states come from the current service, not from an older release. They exercise the
  classification but not every historical shape.
- The cost data is synthetic: one purchase and one consume per family, not the maintainer's
  distribution. The stock_log triggers were disabled for the bulk load only.
- Timings come from one laptop VM. Lock waits used fixed sleeps (1 to 2 s) and show ordering,
  not throughput.
- The repository's PHPUnit, pgTAP and Playwright suites were not run.
