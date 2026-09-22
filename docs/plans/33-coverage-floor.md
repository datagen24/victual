# 33 — Coverage floor

This plan brings application PHP line coverage to at least **75% per file and overall**.
Before closing [issue 192](https://github.com/datagen24/victual/issues/192), overall
coverage must reach **85%**, or the remaining work must have named owners. **90%** remains
the ideal.

The plan implements the existing [coverage policy](../constitution.md#standing-invariants)
and [ADR-0025's three test tiers](../adr/0025-three-test-tiers.md). It does not introduce
a new testing architecture. Issue 192 tracks the backlog; the [plan index](README.md)
tracks delivery. The baseline and proposed work below describe the plan as prepared on
2026-09-20, not the current implementation status.

## Outcome and scope

Tests must assert observable behavior: expected results, refused operations, and unchanged
state after failed writes. Executing a line without checking its result does not complete
a backlog item. Any file excluded from the 75% requirement needs a documented, reviewed
reason.

Each change should cover a manageable group of related behaviors, including fixtures and
assertions that detect regressions. A large class may need several independently mergeable
changes. The report's class grouping does not determine pull request size.

The plan covers:

- Gaps in coverage collection and reporting.
- Tests that establish application behavior and detect regressions.
- Enforcement of the overall coverage minimum.

Automated per-file reporting and enforcement are optional follow-up work. Reviewers must
still check per-file compliance. SQL completeness remains a required check; browser tests
are assessed by pass/fail and do not contribute to coverage.

Feature development, wholesale test rewrites, and database abstraction changes are out of
scope. Retiring the differential harness or historical migrations requires separate
evidence that coverage is preserved. This work must not change client contracts,
production schemas, endpoints, or deployment behavior.

## Baseline at planning time (2026-09-20)

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

M1 establishes the baseline and identifies gaps. M2 completes collection from the separate
PHP test processes. M3 is optional. Each piece has its own acceptance criteria so it can
be reviewed separately from the application tests.

### M1 — Current baseline and complete inventory

Measure one successful run equivalent to the complete CI `suite` job, including its
separate PHP steps. `run-tests.sh` alone produces an interim report and cannot establish
the final CI baseline. Start with an empty coverage directory to prevent stale results
from inflating coverage. Keep credentials out of artifacts.

Record the following evidence:

- Date, commit, PHP and PostgreSQL versions, and coverage driver.
- Invocation, artifact location, and exact covered/executable line counts.
- For every file below 75%: covered and executable lines, additional lines needed,
  untested behaviors, the group of changes responsible for them, and status.

Compare the Clover inventory with the coverage filter. Every executable file must appear,
including files the tests never load. List files with no executable lines separately,
without assigning a percentage. Calculate the additional lines needed as:

```text
shortfall = max(0, ceil(0.75 * executable) - covered)
```

The shortfall counts only the lines needed to reach 75%. Reconcile every row in issue 192
with the inventory, identifying renamed, removed, already-covered, and newly found files.

M1 is complete when the file counts reconcile with the aggregate, never-loaded executable
files appear at zero, and every below-floor file has an assigned owner in the backlog.
Refresh issue 192 periodically with the measured commit, counts, remaining gaps, and next
priorities. An update is not required for every pull request. Measure the baseline before
promising a percentage gain.

### M2 — Remaining PHP process measurement

Include `canonical-json-tests.php` and `renderer-agreement-tests.php` in coverage using
the existing prepend mechanism and shared output directory. Preserve the pinned real
renderer and its assertions. Both processes must produce readable coverage files, and
the final report must merge them after they finish. Raise the aggregate threshold from
the complete run, using its full precision and the unchanged coverage filter.

A missing measurement must be distinguishable from a test that ran but added no newly
covered lines. Include a negative control for a missing driver or disabled prepend path,
with an old coverage file present to verify that it cannot hide the failure. Scope any
collection hardening beyond these two call sites as a separate change.

### M3 — Optional per-file automation

Automated per-file comparison is optional. Its complexity must justify its cost; it does
not block application tests, aggregate enforcement, or issue closure. Reviewers can use
Clover reports to check the existing policy:

- New files reach at least 75%.
- Touched files below 75% improve or reach the floor.
- Files already at or above 75% remain at or above it.
- Aggregate coverage does not regress. Raise its threshold as coverage improves.

Rerunning the base commit is acceptable when comparison evidence is needed. Use matching
tooling and scope, identify both commits, and account for renames and deletions. If
collection changes, rerun the base with comparable collection. A smaller executable-line
count alone does not demonstrate stronger tests. This plan does not require stored base
artifacts or a comparison system spanning multiple workflows.

Any later automation must detect uncovered new files and regressions, handle renames,
and identify missing or incomparable data. Until then, include the targeted file counts
in each pull request's verification evidence.

### Browser measurement boundary — resolved

Browser-driven tests remain outside coverage. Their pass/fail results still matter in the
applicable browser, parity, and longer-duration suites. This plan adds no browser coverage
collection, server instrumentation, or cross-job merge.

Application PHP in the coverage filter remains in the denominator even when browser
tests exercise it. Add tier-1 request tests where needed to cover those behaviors.
Document this boundary in the coverage README when updating collection.

## Application backlog and chunk boundaries

The order below is provisional, based on risk and issue 192's historical gaps. M1 identifies
the methods and files that still need tests; existing tests may already satisfy some
entries. Each semicolon-separated group is a candidate independent change. A table row
does not need to become a single pull request.

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

Expand the final row into named files and separate changes during M1, including newly
found files with zero coverage. Keep `SqliteDialect` in scope while the differential
harness uses it. External integrations use controlled fixtures and local services; this
plan adds no configurable outbound production URL.

### Test design

Prefer public service methods or request paths. For writes, assert both the response and
the persisted state. Use [PgsqlSchemaTestCase](../../tests/Support/PgsqlSchemaTestCase.php)
with real migrated PostgreSQL schemas. It isolates test classes, not individual tests:
each group of tests must define fixture resets and singleton cleanup. Use isolated
processes where configuration constants require them. Reuse existing request helpers and
contract tests where appropriate; a new universal test framework is not required.

When porting a custom test phase, preserve its assertions and failure cases. Separate the
runner migration from new scenarios when either change is substantial. Do not remove an
existing phase as a side effect.

If a test exposes a defect, retain a focused reproducer and handle the fix separately.
Do not treat a security defect as intended behavior or regenerate contract snapshots
solely to make a new test pass.

## Delivery gates and verification

M1 establishes the backlog; M2 completes collection from the remaining PHP processes.
M3 does not block application work or closure. The proposed starting point is one stock
booking group, followed by reassessment of the measured gaps and review effort before
expanding to another family. Browser coverage remains out of scope.

### Evidence for each change

Record the base and candidate commits, targeted behavior, relevant test results, per-file
counts, final aggregate counts, and exact threshold. A test-only change must either
increase coverage of its target or add a specifically missing behavioral assertion.
Porting a phase must preserve both coverage and assertions.

Include a focused negative control that demonstrates detection of an important incorrect
result. A successful HTTP status or mock expectation alone is insufficient. Relevant
PostgreSQL 15 and 16 checks remain required.

Use the final CI-equivalent coverage run to establish a threshold update; a focused test
phase is insufficient. Explain any coverage movement outside the targeted files.

### Closure requirements

The final aggregate gate must enforce at least 75%. Preserve any higher threshold already
achieved, and demonstrate that the gate fails below the floor. To close issue 192:

- Every inventory row reaches 75%, or has a reasoned, reviewed exclusion documented in
  the coverage README.
- Overall coverage reaches 85%, or the remaining gap has a backlog with named owners.
- Existing pgTAP completeness checks and frontend probes pass.

Automated per-file enforcement is not required for closure; reviewers assess the
inventory. An exclusion changes the measurement scope and needs explicit review with
before/after counts. Difficulty testing a file is not sufficient reason to exclude it.

Application line coverage does not establish SQL completeness or browser correctness.
Documentation-only planning changes do not claim that runtime checks have been run.

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

## Executed

[PR #256](https://github.com/datagen24/victual/pull/256), at
`6ce5bf496a97370154cea801a46b68fdd2cef06c`, records implementation of M1, M2, and the
application backlog on 2026-09-22. The PR is open at the time of this entry; the
[plan index](README.md) retains the delivery status recorded by the implementation.

The implementation adds a Clover-based file inventory, measures the canonical JSON and
renderer agreement processes, and adds fifteen tier-1 test phases. Coverage labels and
`report.php --expect` detect missing output from separately measured steps. The aggregate
threshold remains at the measured result rather than being reduced to 75%.

The implementation records 10,002 of 10,385 executable lines covered (96.31%), with all
140 executable files at or above 75%. This meets issue 192's numerical requirement of
85% overall or a remaining backlog with named owners. See the
[coverage results](../../.devtools/coverage/README.md#recorded-results) for the recorded
baseline, process count, and reproduction method.

The implementation's plan-index entry at the revision above records 28 defects found
and filed, with their fixes kept outside this work; several require contract decisions. Per-file CI enforcement (M3) was not taken up
and remains optional. Browser tests remain outside coverage.
