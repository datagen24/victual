# 33 — Coverage floor

[Issue 192](https://github.com/datagen24/victual/issues/192) owns the backlog.
Delivery status belongs in the [plan index](README.md). This is a proposed breakdown of
remaining work, implementing the existing coverage policy and
[ADR-0025](../adr/0025-three-test-tiers.md), not a new testing architecture.

## Outcome and scope

Reach and enforce 75% application line coverage, with every in-scope file at least 75%
or a documented, reviewed exclusion. Reach 85% overall or record the remaining gap with
named owners before closing issue 192; 90% remains the ideal. Tests must assert observable
behavior, including refusals and unchanged state after failed writes. Executing a line
without checking its result does not complete a backlog item.

Deliver this through independently mergeable changes. Each coverage change owns one
behavior family, its fixtures, and the assertions needed to detect a regression. Large
classes can take several changes; they do not become one oversized pull request merely
because the report groups their lines together.

This plan includes measurement gaps, application characterization, and final aggregate
floor enforcement. Automated per-file reporting and enforcement are optional follow-up
work, not delivery gates. The standing per-file coverage policy remains a review criterion.
SQL completeness remains a required check; browser tests are assessed by pass/fail only
and contribute nothing to the coverage measurement. It excludes feature development, wholesale test
rewrites, database abstraction changes, and retirement of the differential harness or
historical migrations. Those retirements require separate coverage-preservation evidence.
No client contract, production schema, endpoint, or deployment behavior should change.

## Evidence and current behavior

Repository inspection on 2026-09-20 used clean working copy
`9c5485ef9fe9b4269b3ee2f1a4afc76578abd25e`. Reproduce the inspection from
[tests.yml](../../.github/workflows/tests.yml),
[run-tests.sh](../../.devtools/pgsql/run-tests.sh),
[phpunit.xml](../../phpunit.xml), and the coverage and pgTAP files linked below.
No fresh coverage run was performed for this draft; neither historical figure below is
presented as coverage of this working copy.

| Item | Evidence at the inspected revision | Remaining work |
|---|---|---|
| Original issue baseline | Issue 192 records 3857/10201 lines, 37.81%, at `6133e15` on 2026-09-17 | Historical only; its class table omits never-loaded classes |
| Aggregate ratchet | `tests.yml` enforces `--min=47.29875477988038`; the coverage README attributes 4824/10199 to `f6e7225`, 2026-09-17 | Measure current coverage and raise from verified evidence |
| Never-loaded files | `prepend.php` enumerates the scope; `report.php` requests uncovered classes in its text report | Verify inventory against Clover, including files without classes |
| Separate PHP steps | Six label scripts and the path-parameter middleware script share the suite coverage directory | Canonical JSON and renderer agreement remain uninstrumented |
| PHPUnit integration | Eleven named suites are registered, including `rbac` and `contract` | Add or port only the phases needed for a behavior slice |
| SQL completeness | pgTAP README lists the former backlog; `run_pgtap_tests()` counts checker failure as suite failure | Preserve the existing gate; do not rebuild the completed backlog |
| Browser tests | Separate `frontend-security` job, outside PHP aggregate measurement | Retain pass/fail checks only; exclude browser-driven execution from coverage |
| Per-file policy | Aggregate `--min` exists; no touched-file comparator appears in this workflow | Review per-file evidence; automated comparison is optional |

The [coverage README](../../.devtools/coverage/README.md) documents measurement and its
historical results. The [pgTAP README](../../.devtools/pgtap/README.md) and runner show
that SQL completeness has progressed beyond the issue comments and ADR acceptance notes.
Those comments describe earlier states, not work this plan needs to repeat.

The PHP filter includes `services/`, `controllers/`, `helpers/`, `middleware/`, `plugins/`,
`app.php`, `routes.php`, and `config-dist.php`. Blade templates and test tooling are not
in that filter. Browser execution may reach filtered PHP, but the browser tier itself has
no percentage target under ADR-0025. Do not silently expand or shrink the denominator.

## Measurement and enforcement pieces

These are separate reviewable pieces, followed by the domain backlog. Their acceptance
criteria define boundaries; they are not instructions to implement all pieces at once.

### M1 — Current baseline and complete inventory

Produce a dated per-file inventory from one successful run equivalent to the complete
CI `suite` job, including its separately measured PHP steps. Running `run-tests.sh` alone
produces an interim report and cannot establish the final CI baseline. Record commit,
PHP and PostgreSQL versions, driver, exact covered/executable counts, invocation, and
artifact location. Start with an empty coverage directory so old processes cannot inflate
results. Keep credentials out of artifacts.

Reconcile the filter against Clover: every executable file must appear, including
never-loaded files; zero-executable-line files are identified separately rather than
assigned a misleading percentage. For each below-floor file record covered and executable
lines, shortfall to 75%, behavior gaps, owning slice, and status. The shortfall is
`max(0, ceil(0.75 * executable) - covered)`, not the entire uncovered-line count.
Reconcile every row in issue 192 with this inventory, explicitly identifying renamed,
removed, already-covered, and newly discovered files.

Acceptance: counts reconcile with the final aggregate; a never-loaded executable file
appears at zero; all below-floor files have an owner in the proposed backlog. Refresh the
inventory through periodic updates to issue 192 as new measurements become available.
Each update identifies the measured commit, counts, remaining gaps, and next slices; an
issue edit is not required for every pull request. No percentage gain is promised before
this measurement.

### M2 — Remaining PHP process measurement

Instrument `canonical-json-tests.php` and `renderer-agreement-tests.php` with the existing
prepend mechanism and shared output directory. Preserve the pinned real renderer and
its existing assertions. Verify that both processes contribute readable coverage files
and that final aggregation occurs after they finish. Raise the exact aggregate ratchet
from the resulting complete run without changing the filter.

Missing expected process output must be distinguishable from an honest zero gain. Include
a negative control for a missing driver or disabled prepend path; an old coverage file
must not conceal it. Any required hardening of collection is a separately scoped change
if it exceeds these two call sites.

### M3 — Optional per-file automation

Per-file CI comparison is a nice-to-have whose complexity must justify its cost. It is
not required before domain work, aggregate floor enforcement, or issue closure. Existing
Clover reports support review of the standing policy: new files reach 75%, existing files
below 75% improve or reach it, and files already above the floor remain at least 75%.
The aggregate must not regress, and its exact ratchet rises when coverage rises.

When a comparison needs base evidence, rerunning the base is acceptable. Use matched
tooling and scope, record both commits, and account for renames and deletions. An
instrumentation change requires a comparable base run under that instrumentation. A
smaller denominator alone is not evidence of stronger tests. There is no requirement to
build a retained-artifact lookup or cross-workflow comparison system.

If automation is taken up later, scope it separately and verify that it detects uncovered
new files and regressions, handles renames, and identifies missing or incomparable data.
Until then, targeted file counts belong in the pull request's verification evidence.

### Browser measurement boundary — resolved

Browser-driven tests are outside coverage. Their success or failure remains relevant when
running the applicable browser, parity, and longer-duration suites, without additional
coverage tracking. No browser coverage collection, server instrumentation, or cross-job
coverage merge is part of this plan.

Filtered application PHP remains in the denominator even if a browser test exercises it.
Uncovered behavior can receive tier-1 request tests where needed to meet the application
floor. Document this boundary in the coverage README when updating measurement wiring.

## Application backlog and chunk boundaries

The following order is provisional, based on risk and issue 192's historical gaps.
M1 determines the remaining methods and per-file targets; existing tests may already
satisfy some entries. Each semicolon-separated slice below is a candidate independent
change, not a requirement to combine a whole row into a pull request.

| Family | Small slices | Required behavioral evidence |
|---|---|---|
| Stock bookings | Add and consume; inventory corrections; transfer; undo booking and transaction | Exact ledger and quantities, invalid quantities, missing entities, permission refusal, rollback leaving no partial write |
| Open containers | Open and measure; weigh location and replenishment | Unit conversion, tare/coherence boundaries, stock-entry selection, refused changes preserving state |
| Stock reads and lists | Details and barcode variants; history and price visibility; shopping-list writes and merge | Response fields and redaction, no unintended booking, transaction effects, unresolved barcode behavior |
| Stock pages | Overview and entries; booking forms; reports | Authorized rendering and page data for empty/populated fixtures, feature settings, restricted prices |
| Users and permissions | User service and API mutations; user pages and read policy | Subset administration, forbidden grants, own-user cases, unchanged permissions on refusal |
| Recipes | Recipe service and API; recipe pages | Ingredient and fulfillment behavior, missing references, permissions and price redaction |
| Chores and tasks | Chore execution/service/API; chore pages; task API/pages | Assignment, completion and undo where supported, time boundaries, denied operations |
| Labels | Template document validation; field catalogue; template service; identity gaps | Malformed documents, field visibility, rejected writes, stable identity and retirement; retain real renderer agreement |
| Files and storage | File service/API; filesystem backend; database backend | Own-picture exception, group authorization, missing data, round trip and cleanup, size refusals |
| Shared API and bootstrap | Generic entity writes; base API errors; OpenAPI/print endpoints; app and middleware | Real dispatch where required, stable errors, forbidden entities, CORS and startup failures |
| Remaining helpers and services | One measured behavior family at a time in configuration, localization, userfields, MQTT, canonical JSON, URL/barcode helpers, and database code | Boundary inputs, failure recovery and externally observable outputs, using existing local integration fixtures |

The last row is not an unbounded catch-all implementation change: M1 must expand it into
named files and separate slices, including newly discovered zero-covered files. Do not
remove `SqliteDialect` or exclude it to satisfy its historical row while the differential
harness still uses it. External integrations use controlled existing fixtures and local
services; this plan introduces no configurable outbound production URL.

Prefer tests through a public service or request path, asserting both response and durable
state for writes. Use [PgsqlSchemaTestCase](../../tests/Support/PgsqlSchemaTestCase.php)
with real migrated PostgreSQL schemas. It isolates classes, not each test automatically:
slices must define fixture reset and singleton cleanup, and use isolated processes where
configuration constants require them. Existing request helpers and contract tests are
examples to reuse, not an instruction to build a new universal test framework.

A bespoke phase port preserves its assertion inventory and failure cases. Runner migration
and adding new scenarios should be separate changes when either is substantial. No existing
phase disappears as a side effect. If characterization finds a defect, preserve a focused
reproducer and scope its fix separately; do not encode a security defect as desired behavior
or regenerate contract snapshots merely to make the new test pass.

## Delivery gates and verification

M1 establishes the queue; M2 establishes the remaining process contributions. M3 is
optional and does not block domain work or closure. Browser coverage collection is out
of scope. Start domain work with one stock booking slice, then reassess
the measured gaps and review cost before expanding to the next family.

Each slice records the base and candidate commits, targeted behavior, relevant test results,
per-file counts, final aggregate counts, and exact ratchet value. A test-only slice must
increase coverage of its target or establish a specifically missing behavioral assertion;
a phase port must preserve coverage and assertions. Evidence includes a focused negative
control showing that an important incorrect result is detected, not merely a successful
HTTP status or a mock expectation. Relevant PostgreSQL 15 and 16 checks remain required.
The final CI-equivalent coverage run, rather than the focused phase alone, establishes the
ratchet update. Explain any coverage movement outside the targeted files.

The final enforcement change is small: set the aggregate gate to at least 75% once the
complete run satisfies it, preserving a higher ratchet if one has already been achieved.
It must demonstrate failure below the floor. Automated per-file enforcement is not a
closure condition; review of the inventory establishes the per-file requirement. Closure
also requires every inventory row at 75% or a reasoned, reviewed exclusion documented in
the coverage README, and 85% overall or a named-owner backlog for the remaining gap.
Any exclusion changes the measurement boundary and must be reviewed explicitly, with
before/after counts; it is never a substitute for testing hard-to-reach code.

Application coverage cannot demonstrate SQL completeness or browser correctness. Existing
pgTAP checks and frontend probes must still pass. Documentation-only planning changes do
not claim those runtime checks have been executed.

## Open questions

The three review questions below are answered. The current measurement requested by
question 3 remains M1's deliverable, rather than an unresolved policy decision.

1. **Should PHP executed by browser probes contribute to the aggregate?**

   > **Response:** Maintainer, 2026-09-20: Browser-driven tests are not part of coverage.
   > Track whether they succeed when running the applicable suites, including parity and
   > the one- and two-year suites; no additional browser coverage tracking is needed.

2. **Where should comparable base coverage live for per-file enforcement?**

   > **Response:** Maintainer, 2026-09-20: Rerunning the base is acceptable. Per-file CI
   > comparison is a nice-to-have that adds complexity, so automated reporting and
   > enforcement are optional rather than prerequisites for this plan.

3. **What remains below 75% after the current inventory is measured?**

   > **Response:** Maintainer, 2026-09-20: Update issue 192 periodically with new data.
   > M1 establishes the current inventory; subsequent measurements refresh the issue's
   > remaining gaps and slice priorities without requiring an update on every change.
