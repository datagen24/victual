# Architecture decision records

This folder records architectural choices that constrain later work. Start with the
index, then read the records relevant to your change. Accepted records are binding;
Proposed records are under review. [Plans](../plans/README.md) describe the work needed
to implement changes and record delivery status.

## Format

```markdown
# ADR-NNNN: Title naming the decision

- **Status:** Proposed | Accepted (date) | Superseded by ADR-NNNN | Rejected (date)
- **Decider:** person responsible for accepting or rejecting the record
- **Recorded:** date written; identify retrospective records
- **Referenced by:** dependent plans and documents

## Context
The problem, relevant constraints, and evidence that requires a choice.

## Decision
The chosen approach and its scope.

## Consequences
Benefits, costs, limitations, and alternatives ruled out.
```

Add **Options considered**, **Research**, **Open questions**, or **Acceptance
prerequisites** when needed. Explain the technical reasoning for a software engineer;
keep review chronology and commentary about writing out of the argument. See
[documentation conventions](../documentation.md).

## Lifecycle

The maintainer, datagen24, accepts or rejects ADRs. Each lifecycle change has its own
pull request containing only:

1. The record's status line.
2. Its index row.
3. A forward pointer on any record it supersedes.
4. Its own reference to the superseded record.

Do not combine acceptance or rejection with substantive edits. Implementing a proposal,
citing it in a plan, or receiving no objections does not accept it. Where a record names
acceptance prerequisites, the accepting PR must state how each was met.

To change an accepted decision, write a superseding ADR. Keep the old record and its
number, including rejected and superseded records, so readers can find the original
choice and rationale.

## Index

| # | Decision | Status | Source |
|---|---|---|---|
| [0001](0001-postgresql-alongside-sqlite.md) | PostgreSQL is supported alongside SQLite | **Superseded by 0008** 2026-08-31 | [db/pgsql](../../db/pgsql/README.md) |
| [0002](0002-squashed-baseline.md) | PostgreSQL loads a squashed baseline, not a replayed migration history | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0003](0003-seed-data-in-php.md) | Seed data lives in PHP, not in the baseline DDL | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0004](0004-engine-specific-migrations.md) | Engine-specific migrations are marked, and migration numbers are never compared across engines | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0005](0005-wire-contract-is-the-invariant.md) | The JSON on the wire is the invariant — with two accepted exceptions | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0006](0006-authenticated-issues-in-scope.md) | Issues requiring an authenticated account are in scope | **Accepted** 2026-08-29 | [security sweep](../security-sweep.md), [SECURITY.md](../../.github/SECURITY.md) |
| [0007](0007-auth-state-outlives-the-process.md) | Authentication rate-limit state lives outside the process | **Accepted** 2026-08-29 | [security sweep](../security-sweep.md) S12 |
| [0008](0008-postgresql-only-runtime-engine.md) | PostgreSQL becomes the only runtime engine; SQLite becomes an import format | **Accepted** 2026-08-31, **supersedes [0001](0001-postgresql-alongside-sqlite.md)** | — |
| [0009](0009-database-as-the-logic-layer.md) | The database is the logic layer | **Proposed**, depends on 0008 | — |
| [0010](0010-workload-standard.md) | Fork-owned workloads are stateless, idempotent, unprivileged and declared | **Accepted** 2026-09-07 | [constitution](../constitution.md) |
| [0011](0011-label-namespace.md) | Labels carry stable opaque identifiers; grocycode becomes an input symbology | **Accepted** 2026-09-04, **three boundaries superseded by [0021](0021-label-templates-are-application-data.md)** 2026-09-07 | [06](../plans/06-location-barcodes.md) (narrowed by it) |
| [0012](0012-observations-are-proposals.md) | Observations write proposals, never bookings | **Accepted** 2026-09-04 | [06](../plans/06-location-barcodes.md) Q2 (routed out of it) |
| [0013](0013-nix-built-container-images.md) | Production images are built by Nix from a flake in this repository | **Accepted** 2026-09-04, **supersedes the `Dockerfile`'s `production` target** | [20](../plans/20-container-infrastructure.md), [nix/](../../nix/README.md), [deploy/](../../deploy/README.md) |
| [0014](0014-administering-a-user-is-a-subset-question.md) | Administering a user means holding everything they hold | **Proposed** | [security sweep](../security-sweep.md) S5, S6, S27 |
| [0015](0015-medication-records-never-advises.md) | Victual records medication; it never advises | **Proposed** | [22](../plans/22-medication-tracking.md) |
| [0016](0016-schedule-expansion-in-the-application.md) | Schedule expansion lives in the application, not the database | **Proposed**, an input to [0009](0009-database-as-the-logic-layer.md) | [22](../plans/22-medication-tracking.md) |
| [0017](0017-doctrine-dbal-is-the-persistence-seam.md) | Doctrine DBAL is the persistence seam; engine portability is an affordance, not a promise | **Proposed**, depends on 0008 | [24](../plans/24-sqlite-runtime-retirement.md), [15](../plans/15-deliberate-cleanup.md) |
| [0018](0018-role-grants-and-domain-reads.md) | Roles contribute grants; six domains require view permissions | **Proposed**, records wave 3a implementation of plan 19's answered questions | [19](../plans/19-rbac.md) |
| [0019](0019-label-printers-are-master-data.md) | Label printers are master data; a separate worker pulls print jobs over an authenticated API | **Accepted** 2026-09-07 with all five gates met, after [0021](0021-label-templates-are-application-data.md) and because of it; supplies the configuration, transport and ownership split [0011](0011-label-namespace.md) item 4 left open; relies on 0010 | [22](../plans/22-medication-tracking.md) Q6 (unowned by it), [20](../plans/20-container-infrastructure.md) piece 5 |
| [0020](0020-documentation-publication-boundary.md) | The documentation site publishes the manual, the developer reference and the ADRs; plans stay in the repository | **Proposed**, four acceptance prerequisites | [26](../plans/26-documentation-site.md) Q1 |
| [0021](0021-label-templates-are-application-data.md) | Label templates are application data, a reprint is a new job over retained bytes, and an import refuses live labels | **Accepted** 2026-09-07, all six prerequisites met, **supersedes three boundaries of [0011](0011-label-namespace.md)**; unblocks [0019](0019-label-printers-are-master-data.md)'s acceptance | [27](../plans/27-label-templates-and-rendering.md), [25](../plans/25-label-infrastructure.md) |

## Review and implementation notes

Gate review recorded 2026-09-04; consult each record for its full requirements.

| Record | Remaining review or delivery work |
|---|---|
| 0009 | 0008 dependency met. Measure whether the deployed pod sleeps after 18; complete the Anonymizer spike. The record requires rejection if the sleep premise fails. |
| 0010 | No named acceptance prerequisites. Enforcement (open question 2) is answered: `.devtools/ci/check_deploy_manifest.py` checks the manifest; `nix/checks.nix` already checked the images. The deploy tree, non-root runtime, probes and limits exist; the database credential split is the one tracked gap left. Decision item 3 is scoped against [0019](0019-label-printers-are-master-data.md)'s no-database worker and its paired-worker exception. |
| 0011 | Accepted; label mapping and print outbox unbuilt. Plan 06 covers location-specific work. |
| 0012 | Accepted; proposal table and API unbuilt, with no owning plan. Confirmation permissions and `proposed_fields` semantics are in the Decision. |
| 0013 | Accepted with all five gates met. Images build and serve; production Docker target retired. Remaining deployment work is in plan 20. |
| 0014 | Proposed; see the record for the user-administration rule and its existing implementation. |
| 0015 / 0016 | Proposed, not assessed by the 2026-09-04 gate review. 0015 requires two reviews; 0016 requires a snapshot-table decision before plan 22 piece 4. Read 0016 when evaluating 0009. |
| 0017 | Proposed. Three acceptance prerequisites: a view-introspection spike, 14 piece 2 before its stage 3, and a measured image closure. Decide it before 14 piece 2 removes the differential suite, which is what currently justifies the dialect seam. Read it when evaluating 0009, whose direction reduces what the seam can carry. |
| 0019 | **Accepted 2026-09-07** with all five gates met, after 0021 and because of it — 0011's assignment of templates to the drainer had to be superseded before this record's ownership model could stand. The gates: the worker image built by Nix and asserted shell-free against the image's own contents, with a negative control that fires; claim, lease, fence and crash-after-send semantics against PostgreSQL; rotation, including the integrated claim, send, rotate and report case; the settings schema subset, with rejected writes shown to leave storage unchanged; and the capability contract exercised against the two device families this deployment has, which is where `artifact_forms`, `provenance` and the triple-scoped `completion_evidence` came from. Nothing is built: no `label_printers` table, no worker repository, and the five `/printlabel` endpoints still fire the webhook. Item 7 sequences the replacement and this acceptance authorizes step 1 only — plans [25](../plans/25-label-infrastructure.md) and [27](../plans/27-label-templates-and-rendering.md) own the work. |
| 0020 | Proposed, recorded 2026-09-07 and not assessed by the 2026-09-04 gate review. Four acceptance prerequisites: PR 92 landed, plan 26 handling the 173 ADR-to-plan links, the PHP API reference question answered, and the manual verified to stand alone. Note that accepting it makes every later ADR a published document. |
| 0021 | **Accepted 2026-09-07** with all six prerequisites met; recorded the same day. Supersedes ADR-0011's template ownership, its "reprint is resetting a row", and its importer re-key obligation, and the forward pointer on 0011 was added by this acceptance. Nothing is built: no template table, no renderer, no artifact storage — plan 27 owns that work and plan 25 owns identity, jobs and delivery. **The six prerequisites**: the renderer selected by rendering the template contract and qualified on kerning, right-to-left shaping and cost; the artifact-format comparison written, settling on an indexed raster on a fixed grid; the import refusal demonstrated under concurrency, which found the lock insufficient on its own and added an import epoch; a reprint printed with the renderer moved off disk and refused once the artifact was collected; RFC 8785 fixed and verified byte-identical against an ECMAScript oracle over 2,206 documents; and the deploy-manifest check extended to run-to-completion workloads and made to fail closed on any kind it cannot examine. ADR-0019's items 1-5 were reconciled on the same day, and the two format-dependent details it owed are written, so **0019 is now free to be accepted** — which it could not be while 0011 still assigned templates to the drainer. |

## Known unfiled decisions

These existing choices need ADRs:

- Request-scoped `define()` constants are retained under php-fpm, excluding worker-mode
  runtimes. See [10](../plans/10-cold-start-statelessness.md).
- Transaction state uses `PDO::inTransaction()` rather than a nesting-depth counter.
  See [13](../plans/13-write-path-transactions.md)'s Executed section.
- Database file storage replaces a persistent file volume. See
  [01](../plans/01-file-storage.md).
