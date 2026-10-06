# ADR-0037: An undo of a whole-row consumption revives the stock-entry label it retired

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-04, against `master` at `7e311cd35c26c7422855d02887f46b5e2e7b4d22`
  (includes [PR 645](https://github.com/datagen24/victual/pull/645); clean working copy), on
  branch `claude/sonnet5_label-revival-adr-7c2f41`. The session started on
  `claude/stock-label-undo-grace-period-aa752a` at `a7bf78a31d70f1351019aa6d6cc1e22479402b59`,
  clean and older than PR 645, so the work moved to a branch cut from current `master`. Rebased the
  same day onto `fc990867` ([PR 649](https://github.com/datagen24/victual/pull/649)), which adds
  ADR-0033 decision 6; the design and the model were aligned with it.
- **Referenced by:** [issue 612](https://github.com/datagen24/victual/issues/612) (the design
  request) and [issue 491](https://github.com/datagen24/victual/issues/491) (the maintainer
  decision of 2026-09-28); answers
  [ADR-0033](0033-stock-rows-merge-only-in-maintenance-for-non-expiring-rows.md) open question 1
  and its decision 6 (the decider's answer of 2026-10-04), and the revival question that
  [ADR-0036](0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md) section 10
  leaves open; evidence in [`.spike-adr37/RESULTS.md`](../../.spike-adr37/RESULTS.md).

This record is design work. It changes no code, reserves no migration number, and does not
accept ADR-0033 or ADR-0036. A Proposed record constrains nothing. It does not implement
label revival.

## Context

### What is decided, built and verified

| State | Fact | Source |
|---|---|---|
| Maintainer decision | After a consumption is undone, the label stays retired and the user prints a new one. Repointing a recently retired label is an optional later improvement. Superseded for the undo case by the next row. | [Issue 491](https://github.com/datagen24/victual/issues/491), 2026-09-28 |
| Decider's answer | Undoing a full consume brings back the label that consume retired, and no other. The row must return under its original id, no other live label may target it, and the label must record which booking retired it. Nothing else revives a label. The decision states no time limit. | [ADR-0033](0033-stock-rows-merge-only-in-maintenance-for-non-expiring-rows.md) decision 6, 2026-10-04 |
| Maintainer request | A design covering the grace period, the purge job, what "repoint" means for the retirement snapshot, and the interaction with ADR-0019 label identity. | [Issue 612](https://github.com/datagen24/victual/issues/612) |
| Record status | ADR-0033 (including decision 6) and ADR-0036 are Proposed. PR 645 (merged 2026-10-04T14:58:04Z) delivered ADR-0036 and its spike. It implemented no per-booking lineage. ADR-0036 section 10 leaves revival out of scope. | `git log`, GitHub |
| Implemented | Retirement triggers (`0269`, `0283`, `0295`, `0296`) and an undo that rebuilds a consumed row. Nothing of this design. | Tree at `7e311cd3` |
| Not implemented | A deployed schedule for any maintenance command. [Issue 133](https://github.com/datagen24/victual/issues/133) (K3S apply) is backlogged, and this design does not depend on it. | `deploy/`, plan 20 |
| Verified here | The behavior in the next two sections, by running the real `StockService` and label services in a disposable schema. Test suites were not run. | [`baseline.json`](../../.spike-adr37/evidence/baseline.json) |

### Current behavior

A whole-row consumption writes a `stock_log` booking that names the row (`stock_row_id`) and
then deletes the row. The `retire_stock_entry_labels` trigger (`0283`) retires the live label on
that row. The label keeps its uid, loses its target and gains a snapshot of the product name,
due date and amount. A scan of the sticker answers `retired` with that snapshot
(`LabelIdentityService::Resolve()`).

An undo of the consumption restores the stock. It does not revive the label.

| Case | Stock after the undo | Label after the undo |
|---|---|---|
| Whole-row consumption, original id free | Row rebuilt under the original id. | Stays retired. |
| Same, an unrelated row now holds that id | Row rebuilt under a fresh id. The unrelated row is untouched. | Stays retired. |
| Partial consumption | The labelled row never left. The undo adds a separate row. | Stays live on the original row. |
| One consumption over two labelled rows | Two bookings, two rebuilt rows. | Both stay retired. |

Issue 612 says "the stock comes back as a new stock row". That holds only when the original id is
taken. `UndoBooking()` rebuilds a fully consumed row under its original id when the id is free
(PR 531). A reused numeric id is not evidence of the same stock: an import restarts every identity
sequence (`TRUNCATE ... RESTART IDENTITY`, `DatabaseImporter::Import()`), so ids repeat across
generations.

### Why a label retires, and what names the booking

| Path | Retires a stock-entry label | A booking is responsible |
|---|---|---|
| Whole-row consumption, inventory-down, weighing-down (`ConsumeProduct()`) | Yes, through the delete trigger | Yes: written in the same transaction, before the delete. |
| Undo of a purchase or a split transfer (`UndoBooking()` deletes the row) | Yes | No: an undo is not a booking. |
| Product deletion (`trg_cascade_product_removal`, `0295`, `0296`) | Yes, with the product name | No: the bookings are deleted too. |
| Maintenance merge (`bin/victual-compact-stock`) | No: `stock_splits` excludes labelled rows (`0290`) | Not applicable. |
| Import | No: an import refuses live labels (ADR-0021 decision 3) | Not applicable. |

Nothing in `labels` records which booking caused a retirement. The snapshot holds the row id and
amount. A row id does not identify the booking: a partial consumption followed by a whole one
leaves two bookings with the same `stock_row_id` (baseline scenario S5). The booking that deleted the
row is the one that exists in the same transaction, but today no code writes that fact down.

### Schema invariants

Verified against a schema migrated to HEAD (299 migrations applied, latest 0300). They match
`0269` and `0283`. No later migration alters the columns, constraints or indexes of `labels`.

- A live label has `retired_at` NULL, a `target_id` and no snapshot. A retired label has `retired_at`,
  no target and a snapshot. One CHECK (`labels_check`) enforces both shapes.
- A partial unique index (`labels_one_live_per_target`) allows one live label per `(kind, target_id)`.
- `labels_kind_check` lists six kinds. `labels` has no trigger and no foreign key in either
  direction. `target_id` has no foreign key, so the CHECK cannot tell whether the target exists.

## Decision

A successful undo of the whole-row consumption that retired a stock-entry label revives that
label on the row the undo rebuilds under its original id. Revival happens only when the rebuild is
proven to be that consumption's and the undo runs inside a window that defaults to 30 days. In every other case
the label stays retired and a person prints a new one, as today.

This record is the detailed design for ADR-0033 decision 6, which the decider wrote on 2026-10-04.
It adopts that decision's conditions. It adds a window, an event table instead of a column on
`labels`, an import epoch and a claim predicate. Section "Relationship to ADR-0033 decision 6" lists
the differences.

### 1. Scope

- **In scope:** labels of kind `stock_entry`, and the undo of a `consume` or negative
  `inventory-correction` booking whose whole-row take deleted the row.
- **Out of scope:** the other five label kinds, general label reassignment, automatic print
  retries, and revival after product deletion, import, maintenance, or the undo of a purchase or
  transfer. No accepted rule about those is changed.

### 2. Revival is automatic

ADR-0033 decision 6 decides that an undo brings the label back. This section records why the
revival needs no second request. Section "Options considered" compares the alternatives.

- The undo itself rebuilds the row the label belonged to. The system holds the proof that a person
  would otherwise have to supply.
- An explicit action needs a request or response change, which is a wire change under
  [ADR-0005](0005-wire-contract-is-the-invariant.md), and a user who knows a sticker exists.
- A declined revival costs one reprint, which is today's behavior. A revival of a sticker already
  thrown away costs nothing, because the revived label names real stock.

### 3. The retirement event

Every retirement of a `stock_entry` label is recorded as one row in a new table,
`stock_label_retirements`. A trigger on `labels` writes it, so every retirement path is covered,
including direct SQL and later retirement sites. The row holds:

- the label uid and the retirement time (`labels.retired_at`);
- a copy of the retirement snapshot;
- the cause: `consumption` when the retirement context proves a booking, `unproven` otherwise,
  `legacy` for a retirement that predates the table;
- for a `consumption` event, the booking id, product id, amount, import epoch, the deadline
  (`revivable_until`), and the highest print job id of that label at retirement
  (`jobs_through_id`);
- the outcome of the revival: pending, `revived` with the target row id, or `declined` with a reason.

`ConsumeProduct()` supplies the retirement context. After it writes a whole-row booking and
before it deletes the row, it sets two transaction-local settings: the booking id and the
grace period in seconds. The trigger accepts the context only when the booking exists, is a live
`consume` or negative `inventory-correction`, names the deleted row in `stock_row_id`, and has the
snapshot's amount. A stale or forged value then yields `unproven`, never a false proof. A writer that
sets nothing, such as an older image or a console session, yields `unproven`.

The `labels` row keeps its shape. When a label is revived, its snapshot is cleared as the CHECK
requires. The event keeps the snapshot, so each retirement stays explainable.

### 4. Proof that the restored stock is the same stock

"Same restored stock" means the one row that the undo of booking B inserts, where B is the
whole-row consumption whose deletion retired the label. A numeric id is necessary and never
sufficient: decision 6 requires the original id, and booking, epoch and amount must also match.
Revival requires all of these inside the undo transaction, under the locks in section 7:

1. A pending `consumption` event exists for B in the current import epoch.
2. The database clock is before `revivable_until` (section 5).
3. B has the event's product and the row id in the event's snapshot as its `stock_row_id`, and its
   amount equals the event's within the ADR-0032 tolerance.
4. The rebuilt row was inserted by this undo, has B's product, has the event's amount, and carries
   the current import epoch.
5. The rebuilt row has the row id in the event's snapshot, its original id.
6. No live label names the rebuilt row.
7. The label is still retired.

An undo of a whole-row consumption always inserts a new row and never merges into an existing one.
The amount check in condition 4 declines the case where a row would hold other stock. One
consumption over several rows writes one booking per row, so each row has its own event and its own
revival. A booking that matches no pending event revives nothing, so several retired labels with the
same product or the same old id cannot claim the stock.

### 5. The window

| Question | Answer |
|---|---|
| Does a window exist? | Yes, confirmed by the maintainer on 2026-10-06, as an addition to decision 6, which states no time limit. A booking is permanent history, but the longer an undo comes after the consumption, the weaker the case that the sticker is on the same item. |
| Duration | Default: 30 days (2,592,000 s), chosen by the maintainer on 2026-10-06 (open question 2). |
| Configuration | The draft uses a constant in `StockService`, stored per event as `revivable_until`. The maintainer chose the default duration; an operator or user setting has not been decided. Zero disables new references in the draft. |
| Start | The retirement time, `labels.retired_at`, which is the database clock at the start of the consuming transaction. |
| Clock | The database. The undo reads `clock_timestamp()` after it holds its locks. The application clock plays no part. |
| Boundary | Exclusive. An undo is eligible while the clock is before `revivable_until`, and declined at that instant and after it. |
| Repeated cycles | Each retirement is its own event with its own window. A consume, undo, consume, undo sequence revives twice. |
| Later setting changes | None apply to existing events, because each event stores its deadline. |
| Delayed cleanup | Nothing waits for cleanup (section 11). Eligibility is the stored deadline compared with the clock. |

### 6. A declined revival never blocks the undo

Stock correctness comes first. An ineligible or conflicting revival leaves the label retired and
the undo succeeds. The event records the reason.

| Condition at undo | Revival | Label | Event |
|---|---|---|---|
| All conditions hold | Done | Live on the rebuilt row | `revived`, with the row id |
| Deadline reached | Declined | Stays retired | `declined`, `expired` |
| A live label already names the rebuilt row | Declined | The live label is untouched; the old one stays retired | `declined`, `target_labelled` |
| Product, amount or row of the booking differs from the event | Declined | Stays retired | `declined`, `mismatch` |
| The row came back under a new id | Declined | Stays retired | `declined`, `id_changed` |
| Retirement was `unproven` or `legacy`, the epoch differs, or no event exists | Not attempted | Stays retired | Unchanged |
| The original product was deleted | The undo refuses ("does not exist"), because its bookings were deleted | Stays retired | Stays pending, inert |
| An unexpected database error | The whole undo rolls back | Unchanged | Unchanged |

An unexpected error is not caught. A savepoint would hide a defect in the revival code. The undo is
rare and a retry costs nothing, so the request fails and the user retries.

The design does not compare the text printed on the sticker with the restored row. A live label
already resolves to the row's current values and not to its printed text, and the printed text can
already be stale for any live label. Revival restores the attributes the row had at consumption.
Database tests cannot verify a physical label, and this record makes no such claim.

### 7. Atomicity, locks and order

An undo and its revival are one transaction. A refusal or failure leaves stock, bookings, label
state, events and print state as they were. The order extends the existing one
([data model](../data-model.md#concurrency-the-stock-advisory-lock), ADR-0033 decision 3):

1. Product advisory locks, as `UndoBooking()` takes them today.
2. A probe for a pending event by `(import_epoch, booking_id)`. With no event, the undo adds this
   one indexed read and nothing else.
3. With an event: the import lock (`LabelIdentityService::IMPORT_LOCK`), taken straight after the
   product lock and before the undo takes its location lock (`LockUndoLocation()`). Then the row
   rebuild, the `labels` row `FOR UPDATE`, the event row `FOR UPDATE`, and the checks and writes.

Retirement takes the `labels` row, then writes the event, then cancels queued jobs (`0296`). Revival
takes `labels` before the event, so both orders agree. Nothing takes the import lock after a row
lock. The existing path from a product lock to the import lock is the order that ADR-0033 records.

| Race | Result |
|---|---|
| Undo and the expiry boundary | One comparison under the event row lock. No job exists to race. Example E3. |
| Undo and issuance of a label for the same id | Issuance waits on the import lock, then returns the revived uid. Before the undo, issuance finds no row and refuses. Example C1. |
| Two undo requests for one booking | The second waits on the product lock, then refuses. One revival. Example C2. |
| Consumption and a scan or print | Unchanged: `0296` and its race tests apply. The new trigger adds an insert after the label update. |
| Retirement and a claimed delivery | Unchanged: only unclaimed jobs are cancelled (section 9). |
| Import and revival | The import lock orders them. A revival that commits first leaves a live label, which makes the importer refuse. An import first changes the epoch, and the revival finds no reference. Example C3. |

### 8. Permissions and visibility

The permission to undo, `STOCK_EDIT`, is sufficient. No other existing permission is required and no
permission is added.

- Retirement already happens under `STOCK_CONSUME` with no label permission. Revival reverses that
  retirement and creates no identity, capture or print job, so it asks for no more than the undo.
- Issuing and revised printing need `MASTER_DATA_EDIT` and the domain read grant because they
  capture entity values. Revival captures nothing.
- Resolving a revived sticker needs the same grant as any `stock_entry` scan, `STOCK_VIEW`
  (`FieldCatalogue::DomainPermission()`). A caller without it still gets `unknown`.
- Events hold no price. They hold the snapshot fields a `STOCK_VIEW` scan of a retired label
  already returns. No endpoint exposes them. Any later exposure is a wire change and must pass
  `FieldPolicy`.

### 9. Print jobs

Revival writes nothing to `print_jobs`, `print_attempts`, `outbox`, captures or artifacts. A job
cancelled by the retirement stays cancelled. An attempt that expired, ended or is uncertain stays
as it is. Revival does not change the rule that a person authorizes another attempt.

One predicate is needed. Today a retired label excludes its jobs from claims. A job holding an
authorized retry would become claimable again when the label turns live (baseline scenario S8). The
claim query therefore excludes a job whose id is at or below `jobs_through_id` of any retirement
event of its label. A job requested after the revival has a higher id and is claimable.
Reprints and revised prints after a revival follow the existing rules, and a reprint whose bytes were
collected is still refused.

### 10. Import

Events are history. The importer must not copy or truncate them, so the table joins
`DatabaseImporter::NOT_COPIED_TABLES`. Its only foreign key points at `labels`, which an import
never truncates. It has no foreign key to `stock`, `stock_log` or `products`, which an import
truncates with `RESTART IDENTITY` ([ADR-0021](0021-label-templates-are-application-data.md)
decision 3).

An import increments the import epoch inside its transaction, under the import lock. An event of an
earlier epoch is never matched, so every pending reference dies at the epoch change and no extra
write is needed. A revived label is live, and an import refuses live labels until a person retires
them. Retired snapshots and events survive an import.

### 11. Cleanup

No scheduled cleanup is needed, and none is proposed. Eligibility is a stored deadline compared with
the clock at the moment of use. A pending event past its deadline is inert and holds no live
pointer. The undo of its booking closes it as expired (example E4). Without that undo it stays
pending.

| Item | Answer |
|---|---|
| What it expires or removes | Nothing is removed. Past-deadline events are derived as lapsed. |
| Eligibility query | Derived: `outcome IS NULL AND revivable_until <= clock_timestamp()`. |
| Indexes, batches, transactions | None beyond the event table's two indexes. The only writes are inside retirement and undo transactions. |
| Retry, overlap, interruption | Not applicable. Each write is atomic with its transaction. |
| Role and privileges | The application role. Default privileges grant the new table. `bin/victual-compact-stock` needs no change, because it never deletes a labelled row. |
| Schedule and mechanism | None. [ADR-0010](0010-workload-standard.md) adds no workload to declare. |
| Observability | The lapsed-count query, and `reason` counts over closed events. |
| Delay beyond expiry | No effect. Example E4 ran with cleanup absent. |

If the maintainer wants a stored `expired` state, one statement closes lapsed events:
`UPDATE stock_label_retirements SET outcome = 'declined', reason = 'expired', closed_at = clock_timestamp()
WHERE outcome IS NULL AND revivable_until <= clock_timestamp()`. It needs `UPDATE` on that table
only and belongs in an existing maintenance command, not a new workload. Nothing depends on it.

### 12. What the user sees

The undo interface tells the user whether the label was restored or remains retired and needs a
new print. This is the maintainer's decision of 2026-10-06 (open question 3). A transaction that
restores several stock rows must report mixed revival outcomes without implying that every label
was restored. An undo with no affected label keeps its ordinary success message.

The current undo response is 204 with no body. The implementation must define how the browser
receives the committed revival result before building the notice. Any response change requires an
explicit contract decision under [ADR-0005](0005-wire-contract-is-the-invariant.md), with matching
OpenAPI and snapshot changes. The notice must not expose retirement events or snapshot fields to
callers without their read permission. A refused or rolled-back undo must never report a revival.

Scanning remains available: `resolved` with the stock entry means revived, and `retired` means print
a new label. The Manual's label chapter must explain both the notice and the scan result.

## Identity and state model

The label uid and its `labels` row are permanent. The event table is permanent history. Only the
eligibility to revive is bounded, and it needs no deletion. ADR-0019 puts label identities and
retirement mappings outside print-history cleanup, and this design extends that rule to events. The
print-artifact retention in [ADR-0021](0021-label-templates-are-application-data.md) does not apply.

```sql
CREATE TABLE stock_label_retirements (
	id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	label_uid TEXT NOT NULL REFERENCES labels (uid),
	retired_at TIMESTAMPTZ NOT NULL,
	snapshot JSONB NOT NULL,
	cause TEXT NOT NULL CHECK (cause IN ('consumption', 'unproven', 'legacy')),
	import_epoch BIGINT,                 -- no foreign key to anything an import truncates
	booking_id BIGINT, product_id BIGINT, amount DOUBLE PRECISION,
	jobs_through_id BIGINT, revivable_until TIMESTAMPTZ,
	outcome TEXT CHECK (outcome IN ('revived', 'declined')), reason TEXT,
	closed_at TIMESTAMPTZ, revived_target_id BIGINT
	-- plus the CHECKs listed in .spike-adr37/proposed.sql
);
CREATE UNIQUE INDEX ON stock_label_retirements (import_epoch, booking_id) WHERE booking_id IS NOT NULL;
CREATE INDEX ON stock_label_retirements (label_uid);
```

The statements are the schema exercised in the spike ([`proposed.sql`](../../.spike-adr37/proposed.sql)).
Final names are an implementation detail.

Invariants. Each one is a pgTAP or PHPUnit assertion in the acceptance prerequisites.

- **R1.** Every retirement of a `stock_entry` label has exactly one event, written by the trigger in
  the same statement. The backfill gives that to older retirements.
- **R2.** A retired label's latest event is not `revived`. A live label has no event or a `revived`
  latest event. At most one event per label is pending, and none for a live label.
- **R3.** `(import_epoch, booking_id)` is unique. A `consumption` event has a booking, product,
  amount, epoch, deadline and `jobs_through_id`. A non-consumption event is closed at creation.
- **R4.** An event changes once, from pending to closed. Nothing deletes it.
- **R5.** No foreign key joins an event to `stock`, `stock_log`, `products` or any table the importer
  truncates.
- **R6.** A retired label's snapshot equals its latest event's snapshot. A revived label's snapshot
  is NULL and its event still holds it.
- **R7.** At the moment of revival, the label's new target exists, has the event's product and
  amount, and carries the current import epoch.

Repeated retirement and revival produce one event per retirement, in order. Example E8 shows two
events for one uid, both `revived`, the second window starting at the second retirement.

## Worked examples

All results were produced by [`model-probe.php`](../../.spike-adr37/model-probe.php) with the real
`StockService`, and by [`baseline-probe.php`](../../.spike-adr37/baseline-probe.php) for current
behavior. Row and booking ids come from one run and are illustrative.
[`evidence/model.json`](../../.spike-adr37/evidence/model.json) has the full state after every step.
`L` is a label uid, a row is `row(amount)`, and the window is 30 days.

### E1. Full consumption and undo inside the window

| Step | Stock | Bookings | Label L | Event |
|---|---|---|---|---|
| Purchase 3, label issued | row 1 (3) | 1 purchase 3 | live, row 1 | none |
| Consume 3 | empty | 2 consume −3, `stock_row_id` 1 | retired, snapshot row 1 | #1 pending, 2,592,000 s |
| Undo booking 2 | row 1 (3) | 2 undone | live, row 1; scan `resolved` | #1 `revived`, target 1 |
| A person prints "a replacement" | row 1 (3) | unchanged | the same uid, no second label | unchanged |

Several rows in one consumption: consuming 5 over rows of 2 and 3 writes two bookings and two events.
Undoing the transaction revives both labels, each on its own rebuilt row (E2).

### E3 and E4. Expiry, and cleanup that never ran

| Case | Undo | Revival | Label | Event |
|---|---|---|---|---|
| One microsecond before the deadline | Accepted | Done | Live | `revived` |
| Exactly at the deadline | Accepted | Declined | Retired | `declined`, `expired` |
| Consumed 40 days ago, no cleanup exists | Accepted | Declined | Retired | Pending until this undo, then `expired` |

### E1, E5 and E6. Row ids and imports

| Case | Rebuilt row | Label after the undo |
|---|---|---|
| Original id free (E1) | Original id | Revived on the original id |
| An unrelated row holds the id (E5) | Fresh id 8; the unrelated row 7 (7 units, other product) is untouched | Stays retired (`id_changed`), as decision 6 requires. Row 7 has no live label. |
| Import between retirement and undo (E6): epoch 0 to 1, row id 9 and booking id 16 now belong to another product | A row of that other product | Stays retired (`no_reference`): the event belongs to epoch 0. A match on row id 9 alone would have named unrelated stock. |

### E10, E7, E8. Partial consumption, a live label on the target, repeated cycles

| Case | State | Result |
|---|---|---|
| Consume 2 of 5 (E10) | row 14 (3), label live on row 14, no event | Undo adds row 15 (2). No revival is attempted. The label stays on row 14. |
| A live label M already names the id the undo restores (E7, fixture; the application cannot reach it) | L retired, event pending | Undo accepted, revival `declined`, `target_labelled`. M and L are unchanged. |
| Consume, undo, consume, undo (E8) | Events #10 and #11 | Both `revived`. The second deadline is 30 days after the second retirement. |

### E9, E11, E12. Legacy and unproven retirements, mismatch

| Case | Event | Undo | Label |
|---|---|---|---|
| Retired before the table existed (E9) | Backfilled `legacy`, closed. The snapshot's row id equals the booking's row id, and the design does not use that. | Accepted | Retired |
| Undo of a purchase (E11); product deletion, direct delete and a stale context (E16) | `unproven`, closed | Not applicable | Retired |
| Booking's product changed by a merge, or amount rescaled (E12) | Pending until the undo, then `declined`, `mismatch` | Accepted | Retired |

### E13, E14. Rollback and refusal

| Case | Result |
|---|---|
| A failure after the revival step (E13) | Stock, booking, label and event are identical to the state before. A retry then succeeds and revives. |
| A refused undo, the booking's location deleted (E14) | Refused ("original location no longer exists"). Stock, booking, label and event unchanged. |

### E15. Print jobs across a retirement

| Step | Job 1 (queued) | Job 2 (uncertain attempt) | Job 3 (authorized retry) |
|---|---|---|---|
| Retirement | Cancelled | Unchanged | Unchanged; event `jobs_through_id` is 3 |
| Revival | Unchanged | Unchanged | Claimable under today's predicate. Not claimable under the proposed one. |
| A job requested after the revival (id 4) | | | Claimable |

### C1 to C3. Two real connections

| Race | Observed |
|---|---|
| Issuance during the undo (C1) | Waited 1.49 s on the import lock, then returned the revived uid. One live label on the restored row. |
| A second undo of the same booking (C2) | Waited 1.49 s on the product lock, then refused ("already undone"). One revival. |
| Importer step during the undo (C3a) | Waited 1.49 s; the label was live afterwards, which the real importer refuses. |
| Undo during an importer step (C3b) | Waited 1.34 s; the epoch had changed, so no reference matched. The undo succeeded and the label stayed retired. |

## Migration and legacy data

**A PostgreSQL-only migration is required.** It adds a table, two indexes, a trigger function and
trigger, and the backfill. Migrations above 0265 are PostgreSQL-only ([ADR-0008](0008-postgresql-only-runtime-engine.md)).
A new index on `print_jobs (label_uid, id)` serves the trigger's lookup and the claim predicate.
The migration changes no `retire_*` function, no column of `labels` and not its CHECK. It does not
reserve a number here. At the research date `migrations/RESERVATIONS.md` names 0303 as the next
unclaimed number, and the implementing change must claim one first.

**Legacy retirements.** The migration writes one `legacy` event for every `stock_entry` label that is
retired at migration time. It copies `retired_at` and the snapshot and nothing else. It does not set a
booking, an epoch or a deadline, and it does not match a snapshot's row id with a booking. The two
can agree (example E9), but nothing proves the booking belongs to the same database generation.
A legacy label is therefore never revivable. A person prints a new label, as today.

**Validation and rerun.** After the backfill, every retired `stock_entry` label must have exactly one
event, or the migration fails and rolls back (migrations run inside a transaction). A second run
writes nothing (example E9).

**Deployment order.** The migration job runs first. The trigger is additive and, with no context
set, writes `unproven` events. The image that sets the context, revives, and carries the claim
predicate follows. They ship together.

**Compatibility.**

- An older image keeps working: its consumptions yield `unproven` events and its undo revives
  nothing, which is today's behavior.
- During a rolling deployment an older replica could claim a job without the new predicate. That
  needs a revival and an authorized retry on one label in the overlap, and the retry is a job a
  person authorized for a label whose stock was consumed. The release notes state this.
- Rolling the image back leaves the table unused. There is no down migration. Reversing this
  record needs a later migration that drops the objects.

## Relationship to ADR-0033 decision 6

ADR-0033 decision 6 (decider, 2026-10-04) decides that an undo of a full consume brings back the
label that consume retired. This record is its detailed design. The table separates what it adopts
from what it adds.

| Decision 6 | This record |
|---|---|
| Only the label retired by the undone consume | Adopted: the event names the booking (condition 1 and 3). |
| The row returns under its original id | Adopted: condition 5. A new id leaves the label retired (`id_changed`, example E5). |
| No other live label targets the row | Adopted: condition 6. |
| Nothing else revives a label | Adopted: imports, product deletion, undo of a purchase and unrelated bookings never match. |
| The label row records which booking retired it | Changed. The booking is stored in an event table, because a live label must have no snapshot and the booking must outlive the revival as history. The requirement that retirement records its booking holds. |
| No time limit stated | Added: a window defaulting to 30 days, confirmed by the maintainer on 2026-10-06 (open question 2). |
| Not mentioned | Added: an import epoch, the claim predicate for print jobs, and the legacy backfill. |

Decision 6's acceptance prerequisite 5 lists the tests it needs. Examples E1, E5, E7, E8, E9, E11 and
E16 cover each of its cases.

## Relationship to ADR-0036

#612 does not need ADR-0036. Revival needs the booking that deleted the row, which `stock_row_id` has
recorded for consumptions since PR 531 and which the new event captures. It needs the row the undo
rebuilds, which `UndoBooking()` inserts itself. Neither needs per-booking lots.

ADR-0036 section 7 keeps the property revival relies on: "The recorded lots are re-created in a new
row with the booking's snapshot attributes". Section 10 leaves revival open and keeps a labelled row
out of merges.

| Question | Answer |
|---|---|
| Can the design proceed while ADR-0036 is Proposed? | Yes. Research and implementation of this record do not wait. |
| What must ADR-0036's implementation preserve? | An undo of a whole-row consumption inserts one new, unmerged row holding exactly the booking's amount, and hands its id to the revival step. |
| Which change adds the test? | Whichever lands second adds a test that both hold (prerequisite 12). |
| Is ADR-0036's attribution policy changed? | No. |

## Supersession scope

This record supersedes no clause of an Accepted record in full. It narrows one assumption, and
that assumption is not a recorded decision.

| Record | Statement | Effect |
|---|---|---|
| ADR-0011 decision 1 | "a retired label seen in the world is a discrepancy signal" | Narrowed. A label retired by a whole-row consumption and revived by its undo is live and no longer retired. A retired label stays a discrepancy signal in every other case. |
| ADR-0011 open question 4 | Lean: labels are never deleted and `retired_at` is set on consumption. | Still true. Labels are never deleted. The lean was never accepted and implied no one-way rule. |
| ADR-0019 retention | Label identities and retirement mappings are outside print-history cleanup. | Extended to events. |
| ADR-0021 decision 3 | Retired snapshots survive an import; no foreign key to a truncated table. | Followed. Events survive and have no such key. |
| ADR-0021 consequences | Bytes are kept while a label is live; retirement makes them collectable. | Unchanged. A reprint after revival is refused if the bytes were collected. |
| ADR-0033 open question 1 | Whether undo revives the label. | Answered by decision 6 of the same record. This record designs it and supersedes nothing in it. |
| ADR-0036 section 10 | "Label revival after undo stays the open question 1 of ADR-0033, unanswered here" | Answered here. No clause of ADR-0036 changes. |

If the maintainer decides the narrowing of ADR-0011 needs a record, the accepting change adds the
forward pointer under the lifecycle rule.

## Options considered

| Criterion | A. Keep reprinting | B. Event table with a booking-linked, automatic revival (chosen) | C. Keep the retired row targetable and purge (issue 612's sketch) | D. Revival inside ADR-0036's lots | E. Explicit "restore label" action |
|---|---|---|---|---|---|
| Correctness | Always correct. | Proof by booking, epoch, amount and original id. | Needs a target that survives. The CHECK and ADR-0021 forbid a retired label with a target. A stored old id can name unrelated stock. | As B, with lot data that revival does not use. | As B, plus a person's choice. |
| History | Snapshot only. | Event per retirement. | Overwrites the snapshot. | As B. | As B. |
| Complexity | None. | One table, one trigger, one hook, one claim predicate. | A cleanup workload and a changed CHECK. | B plus the lot model. | B plus an endpoint and a screen. |
| Migration | None. | One table and a backfill. | Changes the identity table. | ADR-0036's migration first. | As B. |
| Concurrency | None. | Existing locks plus one ordered extension. | A purge races every undo. | As B. | As B. |
| Permissions | None. | `STOCK_EDIT`. | None. | None. | Needs a decision. |
| Operator experience | Reprint after every undo. | The sticker works again within 30 days. | As B. | As B, after a larger delivery. | Requires knowing a sticker exists. |
| Wire change | None. | Result transport remains to be designed for the required notice (section 12). | None. | None. | Yes (ADR-0005). |

**A** is the current decision. It stays correct for every case B declines. B differs in avoiding a
reprint for the common case of an undo that is caught quickly.

**C** stores the target on a retired label. It breaks the CHECK that separates live from retired,
would overwrite the retirement history, and needs a purge job to remove stale targets. Row ids are
reused across imports, so a retained id is a hazard. B keeps the same effect (a working sticker) by
storing a booking and an epoch, and never a target.

**D** makes revival wait for ADR-0036's acceptance, implementation and backfill, which revival does
not use. Neither record needs the other.

**E** needs a wire or interface change and a person who knows the sticker exists.

## Consequences

- **A sticker survives an undo caught within 30 days that restores the original id.** The old sticker scans as `resolved`. A person
  who undoes later, or whose undo is declined, prints a new label as today.
- **Revival never blocks an undo, and an undo never half-succeeds.** Stock correctness is unchanged.
- **A retired label can become live again.** Any code that assumed `retired_at` is final needs
  review: `AssertLabelLive()`, `Claim()`, `RevisedPrint()` and the scan page read it live, so they
  follow the new state. The one new rule is the claim predicate.
- **Retirement writes one more row.** A consumption of a labelled row inserts an event. A consumption
  of an unlabelled row adds two `set_config` calls and no label work.
- **Storage is small and permanent.** 100,000 events took 35.9 MB with indexes, 359 bytes each, against
  24.7 MB for the 100,000 `labels` rows they reference. The figure is synthetic. A lookup by epoch and
  booking took 0.013 ms.
- The maintainer chose a default of 30 days on 2026-10-06. Household undo timing has not been
  measured. A duration change alters new events only.
- The undo interface reports the label outcome. Result transport and any wire change require
  design and contract verification before implementation.
- **The record does not prove a sticker is on the restored item.** It proves the restored stock is
  the stock the label was on, by booking. A person who moved a sticker to other stock after the
  consumption defeats it.
- **Stale pending events remain.** They are inert and cost nothing at the measured size.

## Acceptance prerequisites

Each prerequisite is a gate. The accepting pull request states how it was met. Acceptance is a
separate bookkeeping-only pull request, and implementation is a separate change.

1. **Open questions 1 to 3 answered** by the maintainer, and any answer that changes the window,
   the trigger, the permission rule or the notice reflected in this record before acceptance.
2. **Migration number claimed** in `migrations/RESERVATIONS.md` before the file exists. The file is
   PostgreSQL-only and passes `check-migrations.php`.
3. **pgTAP (tier 2).** A new file after `026-recipe-substitution-units.sql` covers the table CHECKs
   and the trigger for each retirement path. The paths are whole-row consumption with and without
   context, undo of a purchase, product deletion and a direct label update. The file also covers a
   stale context, the legacy backfill and its rerun, and R1 to R7 as assertions. A catalogue
   assertion shows that no foreign key joins the table to `stock`, `stock_log` or `products`.
   `.devtools/pgtap/README.md` lists the new objects and `check-pgtap-coverage.php` passes.
4. **PHPUnit on real PostgreSQL (tier 1).** A class on `PgsqlSchemaTestCase` runs examples E1, E2,
   E3 to E16 against the real `StockService` with the hook in place, asserting stock, bookings,
   labels and events after every step, and byte-identical state after every refusal and rollback.
   `StockUndoIntegrityTest`, `UndoSequenceForcedRaceTest`, `UndoSequenceReuseUnracedTest` and
   `UndoStolenRowInsertTest` stay green unchanged.
5. **Concurrency (two real connections).** Examples C1, C2, C3a and C3b, plus an interleaving check
   that retirement and revival of the same label never deadlock, reusing the subprocess helpers in
   `tests/Pgsql`. `LabelRetirementCancelsClaimedJobRaceTest` and the other label race tests stay
   green unchanged.
6. **Claim predicate.** A test shows a job at or below `jobs_through_id` is not claimable after a
   revival, a job requested after it is, and the existing claim tests are unchanged.
7. **Importer.** A test shows an import leaves events in place, adds the table to
   `NOT_COPIED_TABLES`, refuses a target holding a revived label, and that an event of an earlier
   epoch never matches. A catalogue assertion confirms the foreign-key rule in R5.
8. **Permission.** A test shows a user holding `STOCK_EDIT` alone revives a label, that a caller
   without `STOCK_VIEW` still scans it as `unknown`, and that no endpoint returns an event.
9. **Notice and wire contract.** Define how the browser receives the committed revival outcome.
   Document any wire change in an ADR under ADR-0005 before implementation, and update
   `victual.openapi.json` and the contract snapshots in the same change as the wire.
   If the transport preserves the contract, prove the snapshots and OpenAPI remain unchanged.
   Permission tests show the notice exposes no protected event or snapshot fields.
10. **Hot path.** A test counts the statements of an undo and of a consumption with no label. The undo
    adds one indexed read and takes no import lock. The consumption adds two `set_config` calls and
    no label statement.
11. **Documentation.** The Manual's label chapter and its undo section describe the result and how to
    read it, and the glossary entries here are updated. The documentation checks pass.
12. **ADR-0036 interface.** Whichever of ADR-0036 and this record is implemented second adds a test
    that an undo of a whole-row consumption inserts one new, unmerged row holding exactly the
    booking's amount, and that revival still holds.
13. **Coverage.** The `report.php --min=96.31198844487241217394` ratchet does not fall. A new file
    reaches 75% and every touched file stays at or above its prior figure and 75%. The pull request
    reports the aggregate and any file below the floor.
14. **Browser probes.** Extend `undo-toasts.js` to verify notices for restored labels, labels that
    remain retired, mixed outcomes and an undo with no affected label. Refusal and rollback must
    never display a revival success. Existing undo checks stay green.
15. **Deployment and release notes.** The order in "Migration and legacy data" and the rolling
    overlap caveat appear in the release notes.

## Open questions

An answer goes directly below its question as a `> **Response:**` block.

1. **Should an undo revive the label automatically?** Options are automatic revival (recommended),
   an explicit action, and keeping the reprint. Automatic revival has no wire change and a declined
   case costs a reprint. An explicit action adds an endpoint or a request field and relies on the
   user knowing a sticker exists. Keeping the reprint is the current decision and implements
   nothing.

   > **Response:** Resolved by ADR-0033 decision 6 (decider, 2026-10-04): an undo of a full consume
   > brings back the label that consume retired, and no other. This record designs it.

2. **Should revival have a window, and is 30 days right?** ADR-0033 decision 6 states no time limit.
   Issue 612 asked for a grace period. The record recommends a fixed 30 days, exclusive at the
   deadline, with zero disabling it, and flags this as the one place it narrows decision 6. The
   alternative is no limit: store no deadline and drop the expiry branch. No measurement of how late
   households undo exists. A shorter window loses the late undo. A longer one revives stickers likely
   already discarded, which costs nothing.

   > **Response:** The maintainer chose a default expiration of 30 days on 2026-10-06.
   > This answer sets the default duration; it does not decide whether to expose a setting.

3. **Should the user be told the result?** The original recommendation was not to add a notice. The scan shows the result and the
   Manual explains it. A message in the undo response or interface is an additive change under
   ADR-0005 and needs its own decision and probe.

   > **Response:** Yes. The maintainer requires the user to be told the result, 2026-10-06.
   > Section 12 and prerequisites 9 and 14 require the notice, its result transport and tests.

4. **Should anything ever delete closed events?** Recommended: no. They are permanent history like
   `labels`, 359 bytes each, and ADR-0019 keeps retirement mappings outside cleanup.
5. **Should a later record extend revival to the other label kinds?** Recommended: no, until a need
   appears. Their retirements are deletions of master data, not undoable bookings.
