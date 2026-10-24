# ADR-0053: A label print job stays queued while its printer is inactive, cancellation is its own state, and reprint stays the only recovery

- **Status:** Accepted, 2026-10-24 (all six questions, as decided)
- **Decider:** datagen24 (maintainer). Each of the six questions below is decided
  separately; the record can be accepted in part. Acceptance is its own pull request; see
  the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-24, against `master` at `d40f5afb`. Design record only: it changes
  no code and reserves no migration number. A migration for question 6, if chosen, is
  reserved by its implementation pull request.
- **Referenced by:** [issue 1021](https://github.com/datagen24/victual/issues/1021)
- **Relationship:** decides the six questions accepted
  [ADR-0049](0049-label-print-job-dead-letter-causes-as-the-code-applies-them.md) left open
  in *Part 2: not decided here*, and partly supersedes ADR-0049: part 1's deactivated-printer
  dead letter (question 1), and the three statements in part 1 corrected under Context.
  ADR-0049 part 1 stays the record of what the code did on 2026-10-20. [ADR-0019](0019-label-printers-are-master-data.md) is touched only through
  ADR-0049.

Questions 1, 3 and 6 change behaviour or the schema if the recommended option is not
"keep". Questions 2, 4 and 5 recommend keeping what the code does and recording it.

## Context

ADR-0049 part 1 lists five ways a print job's outbox row gets `dead_lettered_at`. They
are an unreadable payload (cause 1), a `PrinterConfigurationService::Resolve` refusal at
claim time (cause 2), a deleted printer (cause 3), a render request that ended without an
artifact (cause 4), and an operator cancellation (cause 5). Part 2 asks when each should
happen. The answers depend on four properties of the code, established by reading it at
`d40f5afb` on 2026-10-24.

**The claim filters in SQL, then resolves per row in PHP.** `PrintAttemptService::Claim`
selects candidate jobs for the worker's printers with `ORDER BY j.outbox_id LIMIT 200` and
`FOR UPDATE OF j SKIP LOCKED`, then calls `Resolve` for each candidate. A job that the SQL
admits but the PHP loop skips without dead-lettering is selected again on every claim. If
200 such jobs sit at the front of the queue, they fill the `LIMIT` and the worker's other
printers get nothing. Any "leave it queued" option must therefore be expressible in the
`WHERE` clause, or it starves the worker.

**A job can only be created for a printer `Resolve` accepts.** Every print operation goes
through `LabelOperationsService::ResolvePrinter`, which calls `Resolve`. A job for an
inactive printer, an inactive worker or an invalid configuration is refused at creation.
Cause 2 therefore arises only when something changes after the job is queued.

**Configuration refusals other than inactivity cannot arise through the application.**
`Resolve` runs `Validate` over the printer row and its `label_drivers` row. Both inputs are
fixed once a printer is saved:

- `label_drivers` rows are immutable. `DriverRegistryService::Register` inserts with
  `ON CONFLICT (driver_id,schema_version) DO NOTHING` and refuses a re-registration that
  differs with `contradicting_registration`. No code deletes or updates them.
- `label_printers (driver_id, driver_schema_version)` has a foreign key to
  `label_drivers` (`migrations/0270.pgsql.sql`), so a driver version cannot be removed from
  under a printer.
- `PrinterConfigurationService::Save` runs `Validate` before every insert and update, and
  it is the only code that writes `label_printers`.
- `DatabaseImporter::NOT_COPIED_TABLES` excludes the label tables, so a legacy import
  creates no printer rows.

The remaining arms of `Validate` (`unknown_driver_version`, the `value_out_of_range`,
`schema_unevaluable`, `unknown_property` and `unsupported_combination` refusals) can fire at
claim time only if a later release tightens `Validate`, `SettingsSchemaValidator` or
`DriverRegistryService::Matches`, or if someone edits `label_printers` by SQL. The only
reachable cause-2 refusal in normal operation is `inactive_printer`. This was established
by reading the write paths and grepping `services/`, `controllers/` and `migrations/` for
writes to `label_drivers` and `label_printers` at `d40f5afb`; it was not tested.

**A label outbox row's `last_error` is written once.** No drain claims
`label.print_requested` rows: `OutboxService` drains are called with
`stock.transaction_booked` only, and `OutboxService::RequeueDeadLettered` refuses every
other event type. Each label path writes `last_error` in the same statement that sets
`dead_lettered_at`, and `Claim` never selects a dead-lettered row again. ADR-0049 part 2
item 3 says "the next failure on the same outbox row overwrites `last_error`"; that holds
for InfluxDB rows, not label rows.

### Three corrections to ADR-0049 part 1

1. There is a sixth cause, which this record calls cause 6. `DatabaseImporter::ClearOutbox`,
   run by a legacy import into a database that already has print history, dead-letters
   every undelivered outbox row a `print_jobs` or `print_attempts` row still references,
   with `last_error` beginning `Replaced by an import`. It then sets `outcome =
   'dead_lettered'` on those jobs that are neither finished nor cancelled. ADR-0049 part 1
   omits it. The import replaced the data the job described, so the job is not meant to be
   recovered; none of the six questions changes this path.
2. Cause 5 has a second path. `cancel_queued_label_jobs()` in
   `migrations/0296.pgsql.sql`, run by the label retirement triggers, sets the job's
   `cancelled_at` with `cancelled_reason = 'label retired'` and the outbox row's
   `dead_lettered_at` with `last_error = 'Cancelled: label retired'`. ADR-0049 cites only
   `LabelOperationsService::Cancel`. Question 4 covers both.
3. `last_error` for a label row is not overwritten, as stated above. The cause text
   survives; it is not shown anywhere (see question 6).

## Decision

Each question states the current behaviour, the options with their consequences, and a
recommendation. The maintainer decided all six on 2026-10-24 and took each recommendation;
the decision line under each question records it. The record stays Proposed until its
acceptance pull request, under the lifecycle rule in [the index](README.md).

### 1. A deactivated printer

**Today.** `Claim` has no `p.active` condition. A queued job whose printer was deactivated
is selected, `Resolve` refuses it with `inactive_printer`, and the job is dead-lettered at
the worker's next claim. `LabelServicesTest::testJobThatCannotBeDispatchedIsDeadLetteredWithItsReason`
asserts the outcome.

| Option | Consequence |
|---|---|
| (a) Keep: dead-letter at the next claim. | Deactivating a printer to swap a label roll or move it destroys its queue within one worker poll. Recovery is one reprint per job. Deactivation and deletion become indistinguishable to a queued job, though only deletion is irreversible. |
| (b) Add `p.active = 1` to the `Claim` query's `WHERE`. | Jobs stay queued, spend no attempt, and become claimable on reactivation. Matches a deactivated worker and ADR-0019's "nothing dead-letters for being unclaimed". Deleting the printer stays the terminal action. Costs: one predicate and a replaced test; `Monitor` shows such a job as `queued` without saying why it does not move. |
| (c) Skip inactive printers in the PHP loop. | Same visible behaviour as (b) but starves the worker once 200 such jobs head the queue (see Context). Ruled out. |

Under (b), `Resolve`'s `inactive_printer` arm can still fire when a deactivation commits
between the `Claim` select and `Resolve` within one claim. The implementation must skip
that job (`continue`) rather than dead-letter it, so the race gives the same result as the
predicate.

**Recommendation:** (b).

**Maintainer decision:** (b), decided 2026-10-24: add the `p.active = 1` predicate to
`Claim`, and skip rather than dead-letter a job when the deactivation race reaches
`Resolve`.

### 2. A deactivated worker

**Today.** `LabelWorkerAuthorization::Authorize` refuses an inactive worker on the claim
route, so `Claim` never runs for it and its printers' jobs stay queued. The
`LabelPrintersApiController` worker update (`SaveWorker`) also revokes the worker's
sessions and API keys when it deactivates it.

There is a recovery path already. `Claim` selects by `p.worker_id`, and
`PrinterConfigurationService::Save` accepts a new `worker_id` if that worker is active.
Reassigning a printer to another worker moves its queued jobs with it.

| Option | Consequence |
|---|---|
| (a) Keep: jobs stay queued. | Reactivating the worker, or reassigning its printers, resumes printing with no reprint. Matches ADR-0019's line on worker deactivation. |
| (b) Dead-letter the worker's printers' queued jobs on deactivation. | Deactivation is the response to a lost or compromised worker credential, because it revokes them. Under (b), that response also destroys the household's queue, and the recovery is one reprint per job. It adds a write to `SaveWorker` that must exclude jobs held by a live attempt, as `DeletePrinter` does. |

**Recommendation:** (a).

**Maintainer decision:** (a), decided 2026-10-24: jobs stay queued.

### 3. Other `Resolve` refusals

**Today.** Every `LabelValidationException` from `Resolve` dead-letters the job, with the
message in `last_error`. As the Context shows, only `inactive_printer` is reachable through
the application; the other arms need a release that tightens validation or a direct SQL
edit.

| Option | Consequence |
|---|---|
| (a) Keep: dead-letter with the refusal text. | The job leaves the candidate set, so it cannot starve the worker. After the operator fixes the printer, a reprint succeeds; before that, `Reprint` calls `ResolvePrinter` and refuses with the same message, which tells the operator what to fix. |
| (b) Leave the job queued until the configuration is fixed. | Cannot be expressed in SQL, because deciding needs `Validate`. Every claim re-selects and re-validates these jobs, and 200 of them stop the worker's other printers (see Context). Avoiding that needs a persisted "printer invalid" flag set by an upgrade check, which is new schema and a new state. |
| (c) Record a `blocked` attempt, as `Claim` does when the worker no longer advertises the driver version. | Spends an authorized attempt and requires an operator to authorize another. The job stays out of the candidate set until then, so no starvation. It uses the attempt history for something that never reached a printer, and the existing `blocked` path's error text is fixed rather than the refusal message. |

**Recommendation:** (a). The refusals are reachable only through an upgrade or a manual
edit; a release that tightens `Validate` should ship a preflight that reports printers the
new rules refuse, so the operator fixes them before jobs dead-letter. That preflight is a
release-checklist item for such a change, not part of this record.

**Maintainer decision:** (a), decided 2026-10-24: keep dead-lettering, with the release
preflight for a change that tightens validation.

### 4. Cause 5: a cancelled job's outbox row

**Today.** `LabelOperationsService::Cancel` and `cancel_queued_label_jobs()` set the job's
`cancelled_at` and leave `outcome` null, and set the outbox row's `dead_lettered_at` with
`Cancelled: <reason>`. The code already treats cancellation as its own state:
`LabelPrintJobService::Monitor` tests `cancelled_at` before `outcome` and reports
`cancelled`, and `DeletePrinter` and `Claim` exclude cancelled jobs separately from
dead-lettered ones. No label code counts dead-lettered outbox rows;
`OutboxService::CountDeadLettered` is called for `stock.transaction_booked` only.

| Option | Consequence |
|---|---|
| (a) Cancellation is a separate state. `outbox.dead_lettered_at` on a label row means "never deliver"; the job's state is read from `print_jobs`. | Matches the code; no change. ADR-0019's closed list of dead-letter causes is causes 1 to 4 and 6. A future count of label dead letters must count `print_jobs.outcome = 'dead_lettered'`, not outbox rows. |
| (b) Count cancellation as a dead letter. | A cancelled job never printed and an operator chose it; reporting it as a dead letter mixes a decision with a failure. It contradicts the comments in `Monitor` and `DeletePrinter`. |
| (c) Stop setting `outbox.dead_lettered_at` on cancellation. | The outbox has no other column meaning "do not deliver"; `delivered_at` would be false. `Claim` already excludes the job by `cancelled_at`, so the marker is redundant for the claim. `DatabaseImporter::AssertOutboxIsHandleable` counts rows with both columns null as undelivered work, so every cancelled job would block an import without `--force`. Two write paths change, one in a migration function. No benefit over (a). |

**Recommendation:** (a).

**Maintainer decision:** (a), decided 2026-10-24: cancellation is a separate state.

### 5. Recovery

**Today.** `POST /api/labels/jobs/{id}/reprint` (`LabelOperationsService::Reprint`) creates
a new job over the source job's retained artifact. It checks that the job exists, has an
artifact whose bytes are still held, that the label is not retired, and that the target
printer resolves. It does not check the source job's `outcome`, so a dead-lettered job can
be reprinted, to its own printer or another. `LabelPrintJobService::AuthorizeAnotherAttempt`
refuses a job with an outcome. The InfluxDB requeue from
[issue 902](https://github.com/datagen24/victual/issues/902) refuses label rows.

Reprint covers every dead-letter cause:

| Cause | Artifact | Recovery today |
|---|---|---|
| 1, unreadable payload | Always (`Claim` requires `artifact_id IS NOT NULL`) | Reprint. The new payload is written by the current code. |
| 2, printer refused | Always, for the same reason | Reprint after reactivating or fixing the printer, or to another printer. |
| 3, printer deleted | Present if the render finished | Reprint to another printer; otherwise a revised print. |
| 4, render request ended | Never | `Reprint` refuses `no_artifact`; `RevisedPrint` renders again. |
| 6, replaced by an import | Varies | Not meant to be recovered: the import discarded the data the job described. |

| Option | Consequence |
|---|---|
| (a) Reprint stays the only recovery. | No change. Each recovery is a new job with its own id and outbox row, and the dead-lettered job stays in history with its reason. |
| (b) A label-specific requeue for configuration-caused dead letters. | Requires clearing `print_jobs.outcome`, which `AuthorizeAnotherAttempt`, `Monitor` and `DeletePrinter` treat as terminal, and clearing `dead_lettered_at`. It needs the cause to be selectable (question 6). If question 1 is (b) and question 3 is (a), the only configuration-caused dead letters left are the upgrade and manual-edit cases, so the tool would serve almost nothing. |
| (c) No recovery. | Removes reprint for dead-lettered jobs, which works today. No reason to. |

**Recommendation:** (a).

**Maintainer decision:** (a), decided 2026-10-24: reprint is the only recovery.

### 6. How the cause is stored

**Today.** The cause exists only as `outbox.last_error` text, which for label rows is
written once and not overwritten (see Context). No API or page shows it:
`LabelPrintJobService::Monitor`, behind `GET /api/labels/jobs` and the print jobs page,
selects `j.*`, the attempt's `error_text` and the render request's error fields, but not
`o.last_error`. An operator sees `dead_lettered` and no reason.

| Option | Consequence |
|---|---|
| (a) No schema change. | Nothing can select by cause except by matching text. Adequate if question 5 is (a), because no tool selects by cause. |
| (b) A stable code prefix in `last_error`, such as `printer_refused: ...`. | No migration, but a convention in free text that every writer, including the SQL function in `0296`, must follow, and existing rows do not carry it. |
| (c) A `print_jobs.dead_letter_cause` column with a check constraint over causes 1 to 4 and 6. | A migration, a backfill from `last_error` text for existing rows, four writers to change (`PrintAttemptService::DeadLetter`, the printer deletion in `LabelPrintersApiController::DeletePrinter`, `RenderRequestService::DeadLetterWaitingJobs` and `DatabaseImporter::ClearOutbox`), and a wire field if exposed. Needed only if question 5 is (b). |

**Recommendation:** (a). Separately, exposing `last_error` in `Monitor` would let an
operator see why a job was dead-lettered. That adds a field to the
`GET /api/labels/jobs` response, a wire contract change under
[ADR-0005](0005-wire-contract-is-the-invariant.md), so it is a follow-up with its own
review, not part of this decision.

**Maintainer decision:** (a), decided 2026-10-24: no schema change.

## Consequences

With the decisions of 2026-10-24, which took every recommendation:

- A job dead-letters for causes 1 to 4 and 6 only, and cause 2 in normal operation becomes
  unreachable: an inactive printer or worker leaves its jobs queued, and the other refusals
  need an upgrade or a manual edit. Deleting a printer stays the way to abandon its queue.
- One behaviour change ships: the `p.active = 1` predicate in `PrintAttemptService::Claim`,
  the skip in place of a dead letter for the `inactive_printer` race, and a test replacing
  the deactivated-printer assertion in
  `LabelServicesTest::testJobThatCannotBeDispatchedIsDeadLetteredWithItsReason`. The
  comment on `Claim` that cites ADR-0049's causes is updated in the same change.
- No migration, no new state, and no wire change.
- Queued jobs for an inactive printer are not marked as such in `Monitor`. An operator
  learns why a job does not move from the printer's `active` flag, not from the job.

Costs of the recommendations: a printer deactivated and forgotten holds its queue until it
is reactivated or deleted; there is no expiry. Question 3's option (a) still loses jobs on
an upgrade that tightens validation, which the release preflight is meant to prevent.

**Alternatives ruled out.** Amending ADR-0049 in place is ruled out by the lifecycle rule:
ADR-0049 is accepted. Deciding the questions inside an implementation pull request is ruled
out for the same reason ADR-0049 kept its two parts apart: each decision can be accepted
without the code change.

## Follow-ups

Not part of this record and not done here.

- Implementation of question 1 (b) as its own pull request with the test above, after
  this record is accepted.
- Exposing `last_error` in `LabelPrintJobService::Monitor` (question 6), as a wire change:
  [issue 1102](https://github.com/datagen24/victual/issues/1102).
- The regression test ADR-0049 lists, that `last_error` carries the `Resolve` refusal text,
  applies to whichever cause-2 path remains.
