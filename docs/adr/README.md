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
| [0010](0010-workload-standard.md) | Fork-owned workloads are stateless, idempotent, unprivileged and declared | **Proposed** | [constitution](../constitution.md) |
| [0011](0011-label-namespace.md) | Labels carry stable opaque identifiers; grocycode becomes an input symbology | **Accepted** 2026-09-04 | [06](../plans/06-location-barcodes.md) (narrowed by it) |
| [0012](0012-observations-are-proposals.md) | Observations write proposals, never bookings | **Accepted** 2026-09-04 | [06](../plans/06-location-barcodes.md) Q2 (routed out of it) |
| [0013](0013-nix-built-container-images.md) | Production images are built by Nix from a flake in this repository | **Accepted** 2026-09-04, **supersedes the `Dockerfile`'s `production` target** | [20](../plans/20-container-infrastructure.md), [nix/](../../nix/README.md), [deploy/](../../deploy/README.md) |
| [0014](0014-administering-a-user-is-a-subset-question.md) | Administering a user means holding everything they hold | **Proposed** | [security sweep](../security-sweep.md) S5, S6, S27 |
| [0015](0015-medication-records-never-advises.md) | Victual records medication; it never advises | **Proposed** | [22](../plans/22-medication-tracking.md) |
| [0016](0016-schedule-expansion-in-the-application.md) | Schedule expansion lives in the application, not the database | **Proposed**, an input to [0009](0009-database-as-the-logic-layer.md) | [22](../plans/22-medication-tracking.md) |
| [0017](0017-doctrine-dbal-is-the-persistence-seam.md) | Doctrine DBAL is the persistence seam; engine portability is an affordance, not a promise | **Proposed**, depends on 0008 | [24](../plans/24-sqlite-runtime-retirement.md), [15](../plans/15-deliberate-cleanup.md) |
| [0018](0018-role-grants-and-domain-reads.md) | Roles contribute grants; six domains require view permissions | **Proposed**, records wave 3a implementation of plan 19's answered questions | [19](../plans/19-rbac.md) |

## Review and implementation notes

Gate review recorded 2026-09-04; consult each record for its full requirements.

| Record | Remaining review or delivery work |
|---|---|
| 0009 | 0008 dependency met. Measure whether the deployed pod sleeps after 18; complete the Anonymizer spike. The record requires rejection if the sleep premise fails. |
| 0010 | No named acceptance prerequisites. Enforcement remains an open question. The deploy tree and non-root runtime now exist. |
| 0011 | Accepted; label mapping and print outbox unbuilt. Plan 06 covers location-specific work. |
| 0012 | Accepted; proposal table and API unbuilt, with no owning plan. Confirmation permissions and `proposed_fields` semantics are in the Decision. |
| 0013 | Accepted with all five gates met. Images build and serve; production Docker target retired. Remaining deployment work is in plan 20. |
| 0014 | Proposed; see the record for the user-administration rule and its existing implementation. |
| 0015 / 0016 | Proposed, not assessed by the 2026-09-04 gate review. 0015 requires two reviews; 0016 requires a snapshot-table decision before plan 22 piece 4. Read 0016 when evaluating 0009. |
| 0017 | Proposed. Three acceptance prerequisites: a view-introspection spike, 14 piece 2 before its stage 3, and a measured image closure. Decide it before 14 piece 2 removes the differential suite, which is what currently justifies the dialect seam. Read it when evaluating 0009, whose direction reduces what the seam can carry. |

## Known unfiled decisions

These existing choices need ADRs:

- Request-scoped `define()` constants are retained under php-fpm, excluding worker-mode
  runtimes. See [10](../plans/10-cold-start-statelessness.md).
- Transaction state uses `PDO::inTransaction()` rather than a nesting-depth counter.
  See [13](../plans/13-write-path-transactions.md)'s Executed section.
- Database file storage replaces a persistent file volume. See
  [01](../plans/01-file-storage.md).
