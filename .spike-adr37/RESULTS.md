# ADR-0037 feasibility spike

Evidence for [ADR-0037](../docs/adr/0037-an-undo-of-a-whole-row-consumption-revives-the-stock-entry-label-it-retired.md).
Disposable schemas only. Nothing under `services/`, `migrations/`, `controllers/` or `tests/`
changed. The reference model is not a candidate implementation.

- **Date:** 2026-10-04 (local time).
- **Commit:** `7e311cd35c26c7422855d02887f46b5e2e7b4d22` (`master`, includes PR 645), clean working
  copy, branch `claude/sonnet5_label-revival-adr-7c2f41`. `run.sh` extracts that tree with
  `git archive HEAD` and overlays this directory.
- **Host:** macOS 27.0.1, Apple Silicon, podman 6.0.2, libkrun VM with 4 CPUs and 8 GiB.
- **Images:** `localhost/victual:dev` (id `97aabbf5cf1f`, PHP 8.5.10) and
  `localhost/victual-pg:pgtap` (id `3ad78171b5ab`, PostgreSQL 16.15). The model probe was
  repeated against `docker.io/library/postgres:15` (id `572491f76228`, 15.19), the minimum
  version the application enforces.
- **Schema:** each run migrates a throwaway schema to HEAD (299 migrations applied, latest 300)
  through `DatabaseMigrationService::MigrateDatabase()` (the `PgsqlSchemaTestCase` path) and
  drops it.

## Commands

```sh
.spike-adr37/run.sh baseline > .spike-adr37/evidence/baseline.json
.spike-adr37/run.sh model    > .spike-adr37/evidence/model.json
PG_IMAGE=docker.io/library/postgres:15 ADR37_N=20000 .spike-adr37/run.sh model   # summarized in model-postgres15-summary.json
```

| File | Role |
|---|---|
| `baseline-probe.php` | Runs today's real `StockService` and label services: schema invariants read from the catalogue, whole-row and partial consumption, undo with the id free and taken, several rows in one transaction, other retirement paths, a refused undo, and print jobs at retirement. |
| `proposed.sql` | The proposed table, the event trigger on `labels`, the legacy backfill and the revival function, as spike-only SQL. Not a migration. |
| `model-probe.php` | Worked examples E1 to E16, the two-connection tests C1 to C3 and a size and lookup measurement. It plays the part of the application hook that the implementation would put in `UndoBooking()`. |
| `conc-child.php` | Child process for the two-connection tests. Uses the real `LabelIdentityService`, the real `StockService::UndoBooking()` and the importer's first two steps. |
| `evidence/` | Output of the runs above. |

## Results

**Baseline (real code, PostgreSQL 16.15).**

- `labels` carries one CHECK (live: target and no snapshot; retired: no target and a snapshot),
  one partial unique index (one live label per kind and target), no trigger and no foreign key.
  No migration after 0283 changed any of these. Three invalid row shapes and a second live label
  were rejected.
- A whole-row consumption of a labelled row retires the label. The undo restores the row under its
  original id when that id is free and the label stays retired. When an unrelated row holds the id,
  the undo inserts a fresh id and leaves the unrelated row untouched.
- A partial consumption does not retire the label. Its undo adds a separate row.
- One consumption over two labelled rows writes two bookings and retires two labels.
- A partial and a whole consumption of one row leave two bookings with the same `stock_row_id`, so
  the snapshot's row id does not name the booking that retired the label.
- An undo of a purchase and the deletion of a product also retire labels, with no booking.
- A refused undo (location deleted) changed nothing.
- At retirement, the queued job is cancelled. A job with an uncertain attempt and a job holding an
  authorized retry are left as they were. A plain `UPDATE` that clears `retired_at` makes the
  authorized retry claimable again.

**Model (PostgreSQL 16.15 and 15.19, identical outcomes).** Sixteen examples and three race tests ran.

- Revival happened for the full consumption, for each row of a multi-row consumption, for the second
  of two consume and undo cycles, and for a restored row under a different id.
- Revival was declined at and after the deadline, with the pending row never cleaned up, for a
  booking whose product or amount changed, for a restored target that already carries a live label,
  and under a legacy, unproven or other-epoch retirement.
- A rollback after the revival step restored stock, booking, label and event exactly. A refused undo
  changed nothing.
- Retirement by product deletion, by a direct row delete and with a stale context all produced
  `unproven` events.
- The claim predicate with `jobs_through_id` kept the authorized retry unclaimable after revival and
  left a job requested after revival claimable.
- A label issuance started during the undo waited 1.49 s on the import lock, then returned the
  revived uid. A second undo request waited 1.49 s on the product lock, then refused. An importer
  waited 1.49 s behind the revival; with the importer first, the revival found no reference for the
  new epoch and the undo still succeeded.
- 100,000 events took 35.9 MB with indexes (359 bytes per event) against 24.7 MB for the 100,000
  `labels` rows they reference. The lookup by epoch and booking used the unique index in 0.013 ms.

## Limitations

- The model is SQL plus a PHP wrapper. The real `UndoBooking()` and `ConsumeProduct()` are
  unmodified. A trigger on `stock_log` stands in for the settings `ConsumeProduct()` would write
  before the delete, and the wrapper finds the rebuilt row by its transaction and tag, which the
  implementation would take from its own insert. An earlier version of the wrapper picked the newest
  rebuilt row, which failed for the second booking of a multi-row undo; the tag filter fixed it.
- E7 inserts a live label for a row that does not exist. The application cannot reach that state;
  a scan of such a label raises `Live label has no target` (`LabelIdentityService::Resolve()`).
- E6 simulates an import by bumping the epoch and giving another product the booking and row ids.
  It does not run `bin/victual-db-import`.
- The clock is not controlled. E3 passes the evaluation time to the spike-only function to test the
  exclusive boundary exactly.
- The cost run needs `ANALYZE labels` between the bulk inserts. Without it, the foreign-key check
  planned a sequential scan and the load was quadratic. Single-row inserts do not need it.
- Nothing here tests a physical label, a printer, the browser or a deployed cluster. No suite from
  `run-tests.sh` was run.
