# Release planning handoff

## Current execution status

0.5.1 planning: Signed v0.5.0 targets 555475aeec4ce2840671ab5623460ebf9f8440b3 and release workflow 39250766266 succeeded, so 0.5.0 is published. Current master is 431b1a97c1354a960a6e6a7e194eb1976bc4112a (PRs #1005 and #1006 land plan 22 and close its ledger). 4 issues are open in 0.5.1; no 0.5.1 candidate exists.

Start with [the execution ledger](execution.md) for merge commits, published artifact identities and remaining evidence limits. Latest release v0.5.0 was published 2026-10-19T12:28:41Z.

Assignments and milestone counts were refreshed from GitHub at 2026-10-19T14:43:24Z. 26 open issues, none in 0.5.0: 0.5.0 shipped on 2026-10-19 and its milestone holds 48 closed and 0 open. Actual: 4 assigned to 0.5.1 (#987–#989, #991), 6 to 0.6.0, 16 unassigned (including #1007, opened after the tag). #93 and #133, which D2 proposed for 0.5.0, did not ship in it and need a new placement.

0.5.0, the first production release, is published: signed tag v0.5.0 targets `555475ae` and release workflow 39250766266 published six images and the Helm chart for amd64 and arm64 on 2026-10-19. The 0.5.0 milestone is closed out (48 closed, 0 open). Two adopted gates were not evidenced at the tag: no backup/restore drill (R5) and no cluster label-delivery/shutdown demonstration (R6), so 0.5.0 claims neither. The next lane is 0.5.1 (4 open follow-ups). 26 issues remain open repository-wide.

Observed: 2026-10-19T14:43:24Z. Planning baseline SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`. Current master SHA: `431b1a97c1354a960a6e6a7e194eb1976bc4112a`. Current assignments were refreshed from the complete GitHub open-issue list and milestone API. Original assignments are preserved in `baseline_milestone` and evidence/release-data-before-apply.json.


## Durable files

- [Structured source](release-data.json) generates every planning view.
- [HTML dashboard](dashboard.html)
- [Markdown dashboard](dashboard.md)
- [Milestone batch](milestone-adjustments.md)
- [0.4.3 agent briefs](agents-0.4.3.md)
- [Renderer](render.py) runs with `python3 release-planning/2026-10-15/render.py`. No backend or network is used.

## Authority and preserved decisions

Read repository AGENTS.md, constitution, ADR index and plan index in order. The historical handoff/ledger/decisions/retrospective are copied verbatim under evidence/historical-*.md. Later ledger entries supersede stale retrospective findings. Do not repeat answered maintainer questions. ADR-0045, -0046, -0048 and -0050 were accepted for 0.5.0; ADR-0049 remains Proposed. Acceptance is separate bookkeeping.

## Completed planning work

The planning phase assessed 53 open issues. The 2026-10-16 full repository refresh found 43 open issues and 0 open PRs. Six milestone metadata updates and 25 issue reassignments were applied and verified during planning. PRs #971–#974 have all merged.

Latest refresh (2026-10-19T14:43:24Z): 26 open issues, none in 0.5.0: 0.5.0 shipped on 2026-10-19 and its milestone holds 48 closed and 0 open. Actual: 4 assigned to 0.5.1 (#987–#989, #991), 6 to 0.6.0, 16 unassigned (including #1007, opened after the tag). #93 and #133, which D2 proposed for 0.5.0, did not ship in it and need a new placement.

## Decisions and next action

D1–D4, D5b, D6 and D7 are recorded from the maintainer. D5a requires BATTERIES_VIEW plus tracking permission before charging; the separate undo rule remains unresolved. D8–D10 (2026-10-18) settled #908, #933 and #952, all shipped in 0.5.0. D2 proposed #93/#133 for 0.5.0; 0.5.0 shipped without them, and they need a new placement. Home Assistant #139 and MCP #86 remain excluded from the production support claim.

Signed tag v0.5.0 targets `555475aeec4ce2840671ab5623460ebf9f8440b3`; release workflow 39250766266 published both architectures, six images and the Helm chart. PR #1004 is the release record; PR #1005 records the published artifacts and lands plan 22. The scheduled Vale baseline-prune failure is tracked as #988 in 0.5.1. Never redispatch completed #919/#921/#945/#955.

## Evidence limits

The v0.5.0 release checks passed (see the release record). The historical planning-baseline coverage and lint findings remain in the original evidence snapshot and do not describe v0.5.0. No restore drill (R5) or cluster label/shutdown demonstration (R6) exists for 0.5.0. The upgrade rehearsal ran on PostgreSQL 16.15 from the dev shell. Accepted #702 evidence is simulator-only. Client adoption is tracked in victual-kit.

## Session and model record

Active Codex session; model identity/reasoning could not be changed or independently verified. Do not attribute coordinator work to GPT-6 Sol.

Luna medium handled two read-only questions; Astra high handled one consequential packet. No account-wide usage was attributed to this session. Per-session token/cost measurements unavailable; report elapsed wall time and agent activity only. Dashboard refresh and 0.4.4 closeout were subsequent work; separate token/cost measurements are unavailable.

Start 2026-10-15T14:52:34Z; research cutoff 2026-10-15T16:16:34Z; deadline 2026-10-15T16:52:34Z; elapsed 33.3 minutes. Remaining work: 0.5.0 is complete. Next release work is 0.5.1; no agents are dispatched by this dashboard refresh.
