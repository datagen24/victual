# 0.4.3 agent briefs

Status: two read-only Luna readers completed; their reports are recorded in `evidence/043-reader-951.md` and `evidence/043-reader-917.md`. A consolidated Astra review is now starting. No implementation agent has been dispatched.

## Baseline and scope

Use repository commit `b728d0d1a2203523155327b89a03716f915b58c4` (tag `v0.4.2`) as the named baseline. Refresh `master`, issue states, milestones, PRs and comments before dispatch. The current planned 0.4.3 scope is #951 and #917 as separate fixes, with #890 completed only by persisted-factor evidence. #949 is closed and is the originating lookup-range fix. Keep #916 non-finite input policy and #774 price semantics deferred.

The shared failure is PHP float serialization at PDO writes with `precision=14`. #917 additionally changes or relies on `ValueComparison` normalization. Assess observed value changes and import acceptance before calling either patch-compatible.

The maintainer’s recorded #890 decision, quoted verbatim from the 2026-10-13 comment:

> L: B'. Require the lookup factor and its computed inverse to be finite, positive, normal floats. Test boundary cases against PostgreSQL. This avoids overflow and subnormal results; it does not guarantee exact arithmetic or establish medically meaningful limits.

The comment says this decision was verified against master `01c3fa1b`; check for newer comments before relying on it. #951’s persisted round-trip test is a prerequisite to claiming #890 complete.

## Coordinator

**Assignment:** GPT-6 Sol, high reasoning.

Refresh the baseline and model availability before dispatch. Do not silently substitute a model. Use no more than two readers concurrently. Copy findings that affect scope, risk or placement into the shared release record before using them to decide implementation. Keep the 0.4.3 scope bounded and its PRs independent. No ADR acceptance, deployment or tag is implied by these briefs.

**Lower-tier delegation:** An assigned agent may delegate a clearly bounded, independent subtask to a lower-tier OpenAI model at its discretion. The helper inherits the named commit, read-only restrictions and structured report format. Keep no more than two issue readers active at once and no more than four active agents including the coordinator. Check the live agent roster before dispatching a helper; if capacity is unclear, proceed without one. The parent agent owns and verifies helper output. Do not use Anthropic models or separate user-facing chats.

## Reader A: application write precision

**Assignment:** GPT-6 Luna, medium reasoning. **Role:** `.agents/agents/php-pro.md`, narrowed to read-only PHP source analysis. **One question:** where do PHP floats become strings or lose round-trip precision on application write paths, and what observable effects would changing that behavior have for #951 and persisted conversion factors?

Read the four authority files in required order, then relevant Accepted ADRs and #951/#890/#949. Trace the actual path from caller through `StockService`/PDO to PostgreSQL, including the lookup factor and trigger-written inverse. Inventory other affected DOUBLE PRECISION writes only far enough to establish compatibility blast radius. Inspect recorded tests and results; do not rerun full suites.

Read-only restrictions: do not write files, run migrations, or change Git/GitHub state. Shell is limited to read-only commands such as `git show`, `git grep`, `rg`, `sed`, `nl`, `git status`, and `git rev-parse`. Do not execute arbitrary repository scripts or tests. You may delegate one lower-tier read-only helper for a narrow subtask if the coordinator rule’s capacity limits permit it; the helper inherits these restrictions.

Report: findings; `path:line` at the named commit; issue/PR links and states; existing measured evidence; exact affected values and observable behavior; existing-data/migration implications; dependencies marked implemented/merged/verified; uncertainty; release-placement recommendation. Return the report to the coordinator. Do not write the shared record.

## Reader B: legacy import precision

**Assignment:** GPT-6 Luna, medium reasoning, concurrent with Reader A. **Role:** `.agents/agents/php-pro.md`, narrowed to read-only PHP source analysis. **One question:** how does the legacy SQLite importer bind REAL values and compare imported rows, and what compatibility changes are required to preserve valid finite values for #917?

Read the four authority files in required order, then relevant Accepted ADRs and #917/#951. Trace `DatabaseImporter` inserts and `ValueComparison`; identify which source formats and columns are affected, current accepted/refused inputs, rollback behavior and whether normalization can hide or introduce differences. Cover the issue’s DBL_MAX, 2^63 and 17-significant-digit cases from existing evidence. Inspect recorded results; do not rerun full suites.

Read-only restrictions: do not write files, run migrations, or change Git/GitHub state. Shell is limited to read-only commands such as `git show`, `git grep`, `rg`, `sed`, `nl`, `git status`, and `git rev-parse`. Do not execute arbitrary repository scripts or tests. You may delegate one lower-tier read-only helper for a narrow subtask if the coordinator rule’s capacity limits permit it; the helper inherits these restrictions.

Report: findings; `path:line` at the named commit; issue/PR links and states; importer source/target types and existing test evidence; acceptance/refusal and rollback effects; existing-data implications; dependencies marked implemented/merged/verified; uncertainty; release-placement recommendation. Return the report to the coordinator. Do not write the shared record.

## Consolidation gate

The coordinator records both Luna reports in the structured release data and execution ledger before dispatching the reviewer. This gate is complete. Reader reports alone must not change the milestone plan.

## Compatibility review

**Assignment:** GPT-6 Astra, high reasoning. **Role:** `.agents/agents/surgeon-reviewer.md`, with its diff-first, falsify-suspicions, concrete-trigger and ship/no-ship responsibilities preserved. Read the two recorded reader reports plus relevant source and issue evidence.

Review one bounded packet: do #951 and #917 remain compatible patch work after accounting for API-visible last-digit changes, other PDO float writes, import acceptance and comparison semantics, existing data, migration/preflight/recovery needs, and deployment sequencing? Can the work remain two independently reviewable PRs? Is #890’s recorded decision still current, and what evidence is required before closing it? Keep #916 and #774 outside scope. Do not write code or files and do not accept ADRs.

Report only surviving consequential concerns. For each, give the trigger, affected user/data, observable consequence, evidence and required decision or validation. End with `SHIP`, `SHIP WITH FIXES`, or `DO NOT SHIP` for the proposed 0.4.3 scope. State uncertainty explicitly. Return findings to the coordinator for durable recording before they affect placement.

## Future implementation

**Assignment:** GPT-6 Sol, high reasoning, only after the compatibility packet is recorded and the maintainer approves fully specified briefs. **Role:** `.agents/agents/php-pro.md`, narrowed to the actual PHP project and its repository rules; ignore generic Laravel/Symfony assumptions.

1. PR A, #951: preserve PHP float round-trip precision on the application write path. Restore the persisted factor/inverse boundary regression test. Prove PostgreSQL stores the same finite normal factor and inverse the PHP path computed. State affected write surfaces and any response-value effect.
2. PR B, #917: preserve finite REAL values through import binding and row comparison. Test DBL_MAX, 2^63 and a 17-significant-digit value; verify clean rollback on refusal. Keep importer policy separate from generic non-finite input policy.
3. Close #890 only when PR A’s persisted evidence proves its accepted factor/inverse rule. Do not add a new numeric range or silently broaden the decision.

Use separate branches/PRs. Merge PR A before PR B if the importer uses a shared serialization helper. Tie every validation result to the tested PR head. Do not dispatch in this preparation phase.

## Independent review and release gates

After implementation, use `.agents/agents/surgeon-reviewer.md` with GPT-6 Luna, medium reasoning, for a diff-first independent review. Escalate to Astra only if a consequential compatibility, data, migration or production judgment remains unresolved. Review current PR heads, not the planning baseline. Run focused tests first; let required CI enforce the coverage ratchet and supported PostgreSQL 15/16 checks. Do not rerun full suites just to classify backlog work.

Before a 0.4.3 tag, verify issue outcomes and separate PR merges, and run the version/chart/OpenAPI/render checks. Prepare the release record and client/operator notes for any observable precision effect. Run full required CI on the merge commit and an upgrade check from actual published v0.4.2. A migration is not presumed breaking; inspect data, upgrade sequence and clients if one is proposed. If the fixes change behavior beyond compatible precision preservation, stop and seek an explicit release decision rather than labeling the change a patch.

## Dispatch checklist for the next usage window

- [ ] Confirm GPT-6 Luna, Astra and Sol are available; disclose any unavailable assignment before dispatch.
- [ ] Refresh GitHub and record the current master SHA, milestones, issue comments and open PRs.
- [x] Dispatch Readers A and B concurrently with the explicit prompts above; no more than two readers.
- [x] Record both reports before assigning the consolidated Astra review.
- [ ] Record the compatibility verdict and any unresolved decision before preparing implementation briefs.
- [ ] Ask for approval only after the PR briefs and release consequences are concrete.
