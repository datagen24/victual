# Victual 0.5.1 release dashboard

0.5.1: 0 of 8 issues closed. Latest release v0.5.0 (published 2026-10-19T12:28:41Z). Master `1058a243`. 27 open issues and 0 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-19T20:48:10Z.

[Interactive dashboard](dashboard.html) · [Structured source](release-data.json) · [Execution ledger](execution.md) · [Handoff](HANDOFF.md) · [0.5.0 release plan (frozen)](../2026-10-15/dashboard.md)

## Standing rules

- Every PR that changes a 0.5.1 issue updates release-data.json, appends a row to execution.md and re-runs render.py in the same PR.
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.

## Patch policy

0.x.y contains compatible fixes and narrowly necessary tests/documentation. A bug label or migration alone does not settle compatibility. P1: consequential security/integrity or required production evidence; P2: moderate affected-workflow risk; P3: later improvement. Priority is assessed here, not a GitHub label or a release-necessity score. S: bounded surface; M: several paths or a race; L: cross-layer/client/deployment work. These are planning estimates, not day commitments.

## 0.5.1 issues

| Issue | State | Priority | Size | Classification | Patch fit | Next action | PR |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [#252 Code that coverage proved nothing can reach](https://github.com/datagen24/victual/issues/252) | open | P3 | M | investigation | Compatible removal of unreachable code, or a comment that keeps it as a deliberate guard. | Draft a keep or delete proposal per item on the issue; the maintainer decides each one before any code moves. | None |
| [#900 ADR-0019 does not list the Resolve() validation refusal as a dead-letter cause](https://github.com/datagen24/victual/issues/900) | open | P2 | M | documentation / investigation | Documentation correction only. | Review factual correction separately; defer behavior decisions unless production label scope needs them. | None |
| [#904 Subprocess test helpers read stdout before stderr and deadlock on more than 64 KB of stderr](https://github.com/datagen24/victual/issues/904) | open | P2 | M | compatible fix (conditional) | Test infrastructure: no application change. | Recover 31b3439c/72fdffef without replacing them; migrate only callers required by upcoming validation first. | None |
| [#987 Consume stores any integer recipe_id, including negative ids and ids no recipe has](https://github.com/datagen24/victual/issues/987) | open | P3 | S | bug | Compatible fix: invalid input is refused, valid input is unchanged. | Review and merge PR #1013. | https://github.com/datagen24/victual/pull/1013 |
| [#988 prose-baseline-prune fails daily: it pushes to master, which requires a pull request](https://github.com/datagen24/victual/issues/988) | open | P3 | S | ci | CI hygiene: no application change. | Open or update a PR from a fixed branch instead of pushing to master. | None |
| [#989 Calendar API 500 includes the user-supplied event title in its body](https://github.com/datagen24/victual/issues/989) | open | P3 | S | security | Low-severity disclosure fix: the response body changes only on the error path. | Decide whether the 500 body may carry the title; otherwise log it server-side. | None |
| [#991 Uploading a userfile under an existing unreferenced name reveals that the name exists](https://github.com/datagen24/victual/issues/991) | open | P3 | S | security | Low-severity disclosure fix: needs the ADR-0048 item 9 decision first. | Decide whether the existence signal matters; update ADR-0048 item 9. | None |
| [#1010 Backup/restore drill against the v0.5.0 artifacts (release gate R5)](https://github.com/datagen24/victual/issues/1010) | open | P2 | M | release evidence | Verification and documentation only: closes release gate R5. | Run the documented dump and restore against the v0.5.0 images on a disposable database and record the report. | None |

## Open milestones

Observed 2026-10-19T20:48:10Z. Unassigned open issues: [#80](https://github.com/datagen24/victual/issues/80), [#86](https://github.com/datagen24/victual/issues/86), [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133), [#139](https://github.com/datagen24/victual/issues/139).

| Milestone | Open | Closed | Due | Description |
| --- | --- | --- | --- | --- |
| [0.6.0](https://github.com/datagen24/victual/milestone/6) | 8 | 0 | Unset | Later feature release: OAuth then federation; installation country, canonical/scoped barcode resolution and web onboarding, each behind its own design/ADR gates. Full API audit remains later work. These are not requirements for 0.5.0. |
| [0.5.1](https://github.com/datagen24/victual/milestone/7) | 8 | 0 | Unset | Follow-ups found while building 0.5.0 that do not block the first production release: presentation residuals, low-severity disclosures and CI hygiene. Lands after 0.5.0 and before 0.6.0. |
| [0.5.2](https://github.com/datagen24/victual/milestone/8) | 5 | 0 | Unset | Interm quality of life fixes |
| [vNext](https://github.com/datagen24/victual/milestone/9) | 1 | 0 | Unset | Farther in the future Ideas and Concepts, not commited to a planned release |

## Gates carried from 0.5.0

| Gate | At v0.5.0 | Now | Issues | Remaining | Closure rule |
| --- | --- | --- | --- | --- | --- |
| R5: Disposable backup/restore drill tied to supported deployment | Not evidenced at v0.5.0 | Tracked in 0.5.1 as #1010 | [#1010](https://github.com/datagen24/victual/issues/1010) | Run the restore drill against the v0.5.0 artifacts, or record a narrow restriction. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |
| R6: Production support claim for cluster labels and shutdown | Not evidenced at v0.5.0 | Not placed: no 0.5.1 claim | [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133) | #93 waits on ADR-0019 acceptance. 0.5.1 makes no cluster label or shutdown support claim. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |

## Planning decisions

| ID | Date | Question | Decision | Issues |
| --- | --- | --- | --- | --- |
| P1 | 2026-10-19 | Does #909, battery and equipment authorization residuals, stay in 0.5.1? | No. Moved to vNext: the issue is unscheduled and waits on the battery and equipment rework. | [#909](https://github.com/datagen24/victual/issues/909) |
| P2 | 2026-10-19 | Which backlog items join 0.5.1? | A new restore-drill issue for gate R5 (#1010) and #252, unreachable code. #133 stays unassigned. | [#1010](https://github.com/datagen24/victual/issues/1010), [#252](https://github.com/datagen24/victual/issues/252), [#133](https://github.com/datagen24/victual/issues/133) |
| P3 | 2026-10-19 | Where does the 0.5.1 dashboard live? | A new dated directory, release-planning/2026-10-19. The 0.5.0 plan in release-planning/2026-10-15 stays frozen. | None |

## GitHub writes made by planning

| When | Change |
| --- | --- |
| 2026-10-19 | [Milestone of #909 changed from 0.5.1 to vNext](https://github.com/datagen24/victual/issues/909) |
| 2026-10-19 | [Milestone of #252 set to 0.5.1](https://github.com/datagen24/victual/issues/252) |
| 2026-10-19 | [Opened #1010, backup and restore drill (gate R5), in 0.5.1](https://github.com/datagen24/victual/issues/1010) |

## Evidence

The latest snapshot is in `evidence/refresh-2026-10-19T204810Z`, taken from the GitHub REST API via gh. Assessments are planning estimates, not validation results.
