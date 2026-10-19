# Bug bash 2026-10-13 — restart handoff

## Update 2026-10-14 ~00:50Z

- Later: #954, #955 and #956 MERGED by the maintainer. Variant d confirmation was not given explicitly before the merge; #901 still closed; #930 open.

- #956 READY at db7b907d: comment-only correction of UserfileOwnerPolicy.php (label_printer_status looked up by printer_id). #919 had merged, so a follow-up PR.
- #955 READY at dfc3fa9b (4adcb1b3 and dfc3fa9b unsigned, S1 disclosed). Revision 4 fixes the original #901 defect for merges with no own-unit row: when stock units differ, the removed product's factor must equal the kept product's factor before (if any) and in the merged state, checked before any write. Investigation: scratch/investigate-901-factor. Product-specific 1:1 rule answered and implemented. Awaiting maintainer confirmation of variant d (kept product's effective factor 2→1 via a moved removed-product row, no affected row) being refused. #901 stays closed unless the maintainer reopens it. #930 open.

## Update 2026-10-13 ~23:40Z — follow-ups after the maintainer's answers

- #945 MERGED (cb442afa); #892 open (Part of).
- #950 body corrected by the coordinator per maintainer instruction (ordinary-space " 2" accepted; NBSP and JSON-overflow reproductions; shopping-list finding kept).
- #953 filed: OpenAPI stock_label_type labels off by one.
- #954 READY at 8ee1e443: ADR-0048 records the view-key decision (stays Proposed; Refs #905). Docs only.
- (superseded) #955 was READY at 4adcb1b3: #901 follow-up implementing the refined cell 2 — different stock units need the kept product's merged-state resolved factor = 1. Open for the maintainer: does a removed-product-specific 1:1 count (currently yes); candidate pre-existing defect: MergeProducts' $factor ignores the kept product's own conversion on the same pair (not filed). #930 open.
- UserfileOwnerPolicy.php:30-31 docblock says label/print entities are not looked up (wrong for label_printer_status) — not filed.

## Update 2026-10-13 ~22:20Z — continuation for #919, #921, #945 (supersedes their rows below)

- #921 MERGED (c59c5023 → 9bc4a4c0). Closed #901, #929. Open for the maintainer: cell 2's "effective unit relationship remains unchanged" ships as "removed and kept stock unit ids equal" (stricter; can refuse a merge that changes nothing when a global 1:1 links the units); #930 race open.
- #919 MERGED (6f9b2966 → a7d9eb2e). Closed #941 only. View owners resolved by `id` per the maintainer's view-key decision; seven views have constant/colliding ids, so a file on them is governed by the view permission plus "a row with that id exists". #905 stays open behind #920. Proposed ADR-0048 open question 3 is stale.
- #945 READY at 08f17796, not merged, Part of #892 (no closing refs). #946/#947/#950 keep #892 open. #950's text is partly wrong: ASCII " 2" for recipe_id is stored as 2; dropped values are NBSP-prefixed strings and JSON 1e309/400 digits. Not commented on the issue.
- Filed #953 (OpenAPI stock_label_type labels off by one) at the maintainer's request.
- Validator reports of the prior session were recovered from coordinator transcript 692ceefe/subagents (SubagentHandback inputs); this session's are in scratch/validator-9xx and the ledger.
- Not touched (still as below): #904 part 2 (aca48984, its last suite run was interrupted), #898 (uncommitted), #893, #891, #801, #900.


Resume the existing round. Recover unfinished work before dispatching new fixes.
This handoff records a read-only reconciliation at 2026-10-13T17:56:48.953446+00:00; it does not resume agents,
run tests, merge PRs or authorize new scope. GitHub and local files can change after this snapshot.

## Read first

1. Repository AGENTS.md and its required constitution, ADR and plans indexes.
2. [LEDGER.md](LEDGER.md): reconciled state, exact heads, local work and next actions.
3. [DECISIONS.md](DECISIONS.md): recorded answers. The corrections below override stale pending notes in the archived ledger.
4. The relevant brief and fact sheet, then [FIXER_RULES.md](FIXER_RULES.md) and [VALIDATOR_RULES.md](VALIDATOR_RULES.md).

Repository: `/Users/speterson/src/grocy`. The old coordinator worktree is
`/Users/speterson/src/grocy/.claude/worktrees/talos-standup-ios-macos-5a3007`.
The primary checkout still points at kickoff `01c3fa1b`; the last GitHub master observation
was `fd7ba0d35258326b43236ce055c859408b979164`. Inspect/fetch before choosing a base.
Do not reset the primary checkout or existing agent worktrees to update them.

## First restart actions

1. Check whether old agents, suite processes or the shared lock are still active. A cutoff
   note saying “running” is not evidence of a live agent. Do not duplicate work or kill a
   process without checking ownership. Saved worktree locks alone do not establish liveness.
2. Inspect the six local recovery entries in LEDGER.md. Preserve #921's uncommitted revision
   and #934's unresolved merge. Recover local commits for #890 and #904 part 2 before creating replacements.
3. Refresh PR head SHAs, draft state, merge conflicts and all required checks. Recover final
   validator reports from the prior session when available. A suite log does not substitute
   for an independent verdict; CI on an older head does not validate local edits.
4. Finish the existing PR queue. Integrate shared-file changes deliberately and validate each
   changed head/delta. Then start #898, #893 and #891; do #801 after other OpenAPI edits settle.

## State at reconciliation

- 14 PRs in the original handoff are confirmed merged: [#907](https://github.com/datagen24/victual/pull/907), [#910](https://github.com/datagen24/victual/pull/910), [#911](https://github.com/datagen24/victual/pull/911), [#914](https://github.com/datagen24/victual/pull/914), [#915](https://github.com/datagen24/victual/pull/915), [#918](https://github.com/datagen24/victual/pull/918), [#924](https://github.com/datagen24/victual/pull/924), [#925](https://github.com/datagen24/victual/pull/925), [#927](https://github.com/datagen24/victual/pull/927), [#931](https://github.com/datagen24/victual/pull/931), [#932](https://github.com/datagen24/victual/pull/932), [#935](https://github.com/datagen24/victual/pull/935), [#938](https://github.com/datagen24/victual/pull/938), [#939](https://github.com/datagen24/victual/pull/939).
- #940: open, non-draft, clean, reported checks successful; independent validation recorded at its current head.
- #934: open, non-draft, but conflicts with master; local merge is unresolved. Old green CI is not readiness after conflict resolution.
- #943: draft; current head has a recorded coordinator-reviewed delta, but suite CI was still running.
- #919: draft; round-4 verdict not recovered, and CI was still running. This remains a partial #905 fix.
- #921: draft; pushed head predates the uncommitted revision for the latest maintainer decisions.
- #945: draft, conflicts with master; initial validator verdict not recovered. Partial #892 work.
- #890 and #904 part 2: local commits found, no corresponding PR in the checked open-PR inventory.

#925 and #938 have merged, so the recorded merge dependencies for #898 and #893 are satisfied.
#932 also merged, but ADR-0049 is still Proposed and #900 remains open.

## Decisions already answered — do not ask again

- F3′: ADMIN-only files on userfields/userentities definition rows; metadata stays openly
  readable to authenticated callers. Do not reinterpret EntityReadPolicy::null. api_keys files remain deny-all.
- F5 clarification: #905 is partial while clearing a protected reference or deleting its owner
  can bypass the gate. #920 is an unresolved security dependency, not an accepted disclosure.
  #919 currently closes #941 only; do not add a closing reference to #905.
- #922: remove the never-sent track_count from schema and example; record client impact.
- #923: add IN (0, 1), preserve nullability, refuse incompatible existing data without rewriting it.
- #921/#901: refuse a barcode conversion lost by merge; allow a child with no conversion before
  or after only for factor 1 under established tolerance AND unchanged effective unit relationship;
  refuse a child gaining a conversion; refuse changes to kept-product rows' effective quantities
  or conversions. Include global fallbacks and prove every refusal leaves all rows unchanged.
- Existing S1 approval persists for this round's PR branches. No unsigned release tags or global
  signing changes. Do not request the same approval again merely because this is a new session.

## Questions genuinely unresolved

- #919: files attached to view-backed userfield owners. The previous coordinator recorded
  ADMIN-only fallback without owner lookup; no maintainer answer was found. Inspect supported
  view keys and callers, then present the exact alternatives. Do not silently accept this fallback.
- ADR-0049 part 2: deactivated printers/workers, configuration refusals, cancellation, recovery
  and cause representation. Read the merged Proposed record before preparing a decision batch.
- New findings such as #912 and #933 have their own decisions. They do not become approved scope
  merely by appearing in the follow-up inventory.

## Execution and evidence rules

Use Haiku readers, Sonnet fixers and Opus independent validators, with roles verified from
`.agents/agents/`. Respect actual runtime capacity; the prior round's ceiling of five is not
permission to exceed it. Keep private scratch directories and explicit file ownership.

Tests use `/Users/speterson/.claude/projects/-Users-speterson-src-grocy/bugbash-20261013/suite.sh` only,
with the existing shared lock and per-phase timeout. Reconcile an unfinished log entry before
launching another run. Keep each `[suite] phase=... exit=...` line beside the matching result;
do not reconstruct phase counts from memory. Do not start the whole suite to reconstruct lost evidence.

Fixer rules requiring a new branch apply to new work only. Resumed workers reuse their branch
and preserve its dirty state. Merged migrations are immutable; 0312 is reserved by #924 for
#940. Check current master reservations before any new migration claim.

No merge or issue-closing authorization is granted by this handoff. Before presenting a PR
as ready, check its current body, closing references, head, independent evidence and CI together.
#891's server handoff does not complete client adoption. Proposed ADRs need their separate
acceptance process. Newly filed issues need triage; do not silently expand the remaining round.

## Sources and preserved history

[STATE-SNAPSHOT.json](STATE-SNAPSHOT.json) contains the GitHub observation and local recovery
inventory. [LEDGER.before-handoff-tuneup.md](LEDGER.before-handoff-tuneup.md) preserves the original
ownership table, validation log and waiting-time notes. Its “pending”, “running” and “ready”
labels are historical and are superseded by this handoff and the reconciled ledger.
The original terse handoff is [HANDOFF.before-handoff-tuneup.md](HANDOFF.before-handoff-tuneup.md).
