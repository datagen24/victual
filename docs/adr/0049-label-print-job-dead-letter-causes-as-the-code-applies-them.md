# ADR-0049: A label print job is dead-lettered for the causes the claim applies, and the unclaimable-versus-dead-lettered rules stay open

- **Status:** Accepted, 2026-10-20 (part 1; part 2 decides nothing)
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-13, against `master` at `33fbec60`. Design record only: it changes
  no code, reserves no migration number and does not touch
  [ADR-0019](0019-label-printers-are-master-data.md). The maintainer's decision A on
  [issue 900](https://github.com/datagen24/victual/issues/900) is the source of its shape.
- **Referenced by:** [issue 900](https://github.com/datagen24/victual/issues/900). Accepting
  this record does not close that issue; the behaviour questions in part 2 are open.
- **Relationship:** partly supersedes accepted
  [ADR-0019](0019-label-printers-are-master-data.md): only the closed list of dead-letter
  causes under *Offline behaviour* (including its 2026-10-12 amendment), and the two
  sentences that contradict one another about a deactivated printer and worker (see
  Context). Every other part of ADR-0019 is unchanged. Related to, and independent of,
  [issue 902](https://github.com/datagen24/victual/issues/902), which concerns requeue of
  InfluxDB outbox rows.

The decision has two parts that are kept apart on purpose. Part 1 corrects the record to
match what the code does today and changes no behaviour. Part 2 lists the behaviour
questions the maintainer has not decided. Nothing in part 2 is chosen here.

## Context

[ADR-0019](0019-label-printers-are-master-data.md) states the closed set of reasons a print
job is dead-lettered in three places, and they disagree with each other and with the code:

1. *Offline behaviour* (line 858) says "nothing dead-letters for being unclaimed" and that
   dead-lettering "stays for what 0259 defined it for, a payload no version can read, plus
   decision item 4's deleted-printer case, plus the third cause added by the amendment of
   2026-10-12". That is three causes: unreadable payload, deleted printer, ended render
   request. A printer that is merely inactive is not on the list.
2. Decision item 4 (line 1005) says "A job whose printer has been deleted or deactivated is
   dead-lettered with `last_error` saying so". Deactivation is on this list.
3. *A worker is a row* (line 203) says "Deactivating the row makes every printer assigned to
   it unclaimable". That describes a worker, not a printer, and it describes a job staying
   queued, not being dead-lettered.

The 2026-10-12 amendment was made in place in
[PR 881](https://github.com/datagen24/victual/pull/881). The [lifecycle rule](README.md)
defines no in-place amendment of an accepted record, and the maintainer ruled on 2026-10-13
that PR 881 is not sufficient authority to bypass the documented ADR process. This record
is the process the maintainer asked for. It covers the cause list only; it does not revisit
the render-request amendment's behaviour, which stays as the code implements it.

## Part 1: factual correction (no behaviour change)

### What the code does

A print job is dead-lettered by setting `print_jobs.outcome = 'dead_lettered'` and the
`outbox` row's `dead_lettered_at` and `last_error`. Four code paths do this. A fifth path
marks the outbox row without giving the job an outcome.

| # | Cause | Where | `last_error` |
|---|---|---|---|
| 1 | The payload cannot be read: unsupported payload version, invalid label uid, invalid target, unknown operation, missing captured name, missing or unsupported artifact reference. | Claim, `services/Labels/PrintAttemptService.php:80-84` via `PrintJobPayload::DescribeUnreadable`, `services/Labels/PrintJobPayload.php:59-88` | The `DescribeUnreadable` message |
| 2 | `PrinterConfigurationService::Resolve` refuses the job's printer at claim time. Causes are listed below. | Claim, `PrintAttemptService.php:85-90`; the dead-letter itself is `PrintAttemptService.php:126-130` | The `LabelValidationException` message |
| 3 | The printer is deleted. | Deletion of a printer, `controllers/Api/LabelPrintersApiController.php:60-81`, for jobs neither cancelled nor held by a live attempt; and `PrintAttemptService::DeadLetterIfPrinterGone`, `PrintAttemptService.php:139-148`, reached from `Reap` (`:43`) and `Result` (`:196`) for a job a worker was holding | `Printer deleted` |
| 4 | The render request the job waits on ends `invalid`, terminally `failed` or expired, and the job has no artifact, outcome or cancellation. | `services/Labels/RenderRequestService.php:131-139`, called at `:94`, `:117`, `:248` and `:260` | `Render request <id> <state>: <code> - <detail>` |
| 5 | An operator cancels a job that has no attempt. The outbox row is marked, but the job gets `cancelled_at` and no `outcome`. | `services/Labels/LabelOperationsService.php:286-287` | `Cancelled: <reason>` |

Causes 1 to 4 are the closed set the claim path and its siblings apply. Cause 5 is listed
because it sets `outbox.dead_lettered_at`. The print job view
(`services/Labels/LabelPrintJobService.php:85`) reports an outcome of `dead_lettered` only
for causes 1 to 4. Whether ADR-0019's list should count cause 5 is an open question in part 2.

### What Resolve refuses (cause 2)

`Resolve` (`services/Labels/PrinterConfigurationService.php:89-97`) selects the printer
joined to its worker with `p.active = 1 AND w.active = 1`, then runs `Validate` (`:7-62`).
Every `LabelValidationException` it raises is a cause-2 dead letter. The complete list is:

- `inactive_printer`, "Printer or assigned worker is missing or inactive" (`:91-94`): the
  printer row is missing or inactive, or its worker row is missing or inactive.
- `unknown_driver_version` (`:9-12`): no `label_drivers` row for the printer's driver and
  schema version.
- `value_out_of_range` for `name`, `connection`, `connection_type` or `model` (`:13-17`): not
  a string, or blank.
- `value_out_of_range` for `worker_id`, "An active worker is required" (`:18-20`).
- `value_out_of_range` for `settings`, "Settings must be an object" (`:21-24`).
- `schema_unevaluable` for `settings` (`:33-35`): not exactly one settings schema matches the
  discriminator tuple. The settings validator can also raise it (`SettingsSchemaValidator.php:89`).
- `unknown_property` or `value_out_of_range` on a settings field (`:36-39`, from
  `SettingsSchemaValidator.php:87`): the settings fail the matched schema.
- `unsupported_combination` (`:47-49`): not exactly one capability combination matches.
- `unsupported_combination`, "No unique completion evidence matches the combination"
  (`:58-60`).

### The claim selects jobs for inactive printers

The `Claim` query (`PrintAttemptService.php:68-75`) joins `label_printers` and filters on
`p.worker_id = ?`. It has no `p.active` or worker `active` condition. A queued job for a
deactivated printer is therefore selected, `Resolve` refuses it with `inactive_printer`, and
the job is dead-lettered (`:85-90`). `tests/Pgsql/LabelServicesTest.php:3004-3008` asserts
this outcome. It asserts the job's `outcome` only, not the `last_error` text.

A deactivated worker behaves differently. The claim route authorizes the calling worker
first (`controllers/Api/LabelWorkerApiController.php:30`) and
`LabelWorkerAuthorization::Authorize` refuses an inactive worker with `unauthorized`
(`services/Labels/LabelWorkerAuthorization.php:35-37`). Deactivating a worker also revokes
its sessions and credentials (`LabelPrintersApiController.php:108-113`). The worker cannot
reach `Claim`, so its printers' jobs stay queued. The worker-inactive arms of `Resolve` can
fire only if a deactivation commits after `Authorize` and before `Resolve` within one claim.

### Which ADR-0019 statement matches the code

| ADR-0019 statement | Matches the code? |
|---|---|
| Line 858, closed list of three causes | Incomplete. It omits cause 2: the printer-configuration refusals at claim time, which include a deactivated printer. |
| Line 1005, "deleted or deactivated ... dead-lettered" | Matches for a printer, deleted (cause 3) or deactivated (cause 2). It does not cover a deactivated worker. |
| Line 203, deactivating a worker makes its printers unclaimable | Matches for a worker: the job stays queued and is not dead-lettered. It is wrong if read as covering a deactivated printer. |

The statements describe different objects. Read that way the code is consistent with item 4
and line 203, and only the closed list at line 858 is wrong. This part states the code's
behaviour as it stands. It does not state that the behaviour is desirable; see part 2.

### Superseded text if this record is accepted

The closed list under ADR-0019 *Offline behaviour* is replaced by causes 1 to 4 above, with
cause 5 handled as the maintainer decides in part 2. The sentence "Dead-lettering stays for
what 0259 defined it for ... plus the third cause" is replaced by that list. Nothing else
in ADR-0019 changes. The forward pointer on ADR-0019 is added by the accepting pull
request, not this one.

A code comment repeats the older, narrower list. `PrintAttemptService.php:56-59` says
ADR-0019 dead-letters an unclaimed job "only for a payload no version can read or a deleted
printer". The comment is out of scope here; see Follow-ups.

## Part 2: not decided here

When a job should become unclaimable and when it should be dead-lettered is a behaviour
question. These items record the options; none is chosen.

1. **A deactivated printer.** Today a job for an inactive printer is dead-lettered at the next
   claim by its worker (cause 2). Options: (a) keep that; (b) exclude inactive printers from
   the `Claim` query so the job stays queued and becomes claimable again when the printer is
   reactivated. Option (b) matches how a deactivated worker already behaves and the
   principle in ADR-0019 that "nothing dead-letters for being unclaimed". It changes the
   claim query and needs a test.
2. **A deactivated worker.** Today its jobs stay queued (part 1, "The claim selects jobs for inactive printers"). Options: keep that, or
   dead-letter jobs when the worker is deactivated. ADR-0019 line 203 is the existing
   statement for keeping them.
3. **Other `Resolve` refusals.** A configuration refusal other than inactivity, for example an
   unknown driver version after a worker's driver schema is removed, dead-letters the job
   today. Options: keep that, or leave such a job queued until the configuration is fixed.
   The error text is the only place the cause survives; the next failure on the same outbox
   row overwrites `last_error`.
4. **Cause 5.** Whether a cancelled job's marked outbox row counts as a dead letter in the
   closed list, or is described as a separate state.
5. **Recovery.** [Issue 902](https://github.com/datagen24/victual/issues/902) adds an
   operator requeue for InfluxDB outbox rows only. A label print row is not safe to requeue
   blindly: a cancelled job, an uncertain attempt that may have printed, an unreadable
   payload, a render-caused dead letter and a deleted printer must all be refused. The
   existing label recovery is reprint (`POST /api/labels/jobs/{id}/reprint`), which creates a
   new job. `AuthorizeAnotherAttempt` refuses jobs that already have an outcome. Options for
   labels: (a) reprint stays the only recovery; (b) a label-specific requeue limited to
   configuration-caused dead letters, which needs the cause to be queryable, because
   `last_error` is free text; (c) none.
6. **How the cause is stored.** Part 1 lists causes by code path. If an operator tool is to
   select by cause, the cause needs a column or a stable code, which is a migration.

## Consequences

Accepting part 1 aligns the record with the code and removes the contradiction between
ADR-0019's three statements. It changes no behaviour, no wire contract and no schema.

Accepting part 1 does not decide any item in part 2. Until the maintainer decides them,
operators can lose a queued job by deactivating its printer, and the fix for that,
if wanted, is a separate change to the claim query.

**Alternatives ruled out.** Amending ADR-0019 in place again is ruled out by decision A. A
single record that both corrects the list and changes when jobs dead-letter was ruled out
for the same reason: the correction can be accepted without the behaviour decision.

## Follow-ups

These are not part of this record and are not done here.

- A regression test that `last_error` carries the `Resolve` refusal text for a deactivated
  printer. `LabelServicesTest.php:3004-3008` asserts the outcome only. This is a code change.
- Correct the comment at `PrintAttemptService.php:56-59` once the list is accepted.
