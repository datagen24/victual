# Victual 0.5.1 release dashboard

0.5.1: 8 of 8 issues closed. Latest release v0.5.0 (published 2026-10-19T12:28:41Z). Master `64b7113f`. 21 open issues and 0 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-20T18:38:59Z.

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
| [#252 Code that coverage proved nothing can reach](https://github.com/datagen24/victual/issues/252) | closed | P3 | M | record correction / cleanup | Compatible removal of unreachable code, or a comment that keeps it as a deliberate guard. | Closed 2026-10-20 by PRs #1022 to #1025; the remaining fallbacks are #1026 (0.5.2). | https://github.com/datagen24/victual/pull/1025 |
| [#900 ADR-0019 does not list the Resolve() validation refusal as a dead-letter cause](https://github.com/datagen24/victual/issues/900) | closed | P2 | M | documentation / investigation | Documentation correction only. | Closed 2026-10-20 by PR #1020; ADR-0049 part 2 is #1021 (0.6.0). | https://github.com/datagen24/victual/pull/1020 |
| [#904 Subprocess test helpers read stdout before stderr and deadlock on more than 64 KB of stderr](https://github.com/datagen24/victual/issues/904) | closed | P2 | M | compatible fix (conditional) | Test infrastructure: no application change. | Closed 2026-10-20 by PR #1027. | https://github.com/datagen24/victual/pull/1027 |
| [#987 Consume stores any integer recipe_id, including negative ids and ids no recipe has](https://github.com/datagen24/victual/issues/987) | closed | P3 | S | bug | Compatible fix: invalid input is refused, valid input is unchanged. | Review and merge PR #1013. | https://github.com/datagen24/victual/pull/1013 |
| [#988 prose-baseline-prune fails daily: it pushes to master, which requires a pull request](https://github.com/datagen24/victual/issues/988) | closed | P3 | S | ci | CI hygiene: no application change. | Merge PR #1012, enable 'Allow GitHub Actions to create and approve pull requests', then run the workflow by hand once. | https://github.com/datagen24/victual/pull/1012 |
| [#989 Calendar API 500 includes the user-supplied event title in its body](https://github.com/datagen24/victual/issues/989) | closed | P3 | S | security | Low-severity disclosure fix: the response body changes only on the error path. | Review and merge PR #1015. | https://github.com/datagen24/victual/pull/1015 |
| [#991 Uploading a userfile under an existing unreferenced name reveals that the name exists](https://github.com/datagen24/victual/issues/991) | closed | P3 | S | security | Low-severity disclosure fix: needs the ADR-0048 item 9 decision first. | Review PR #1016. It corrects the planning premise: DELETE does not reveal the name. | https://github.com/datagen24/victual/pull/1016 |
| [#1010 Backup/restore drill against the v0.5.0 artifacts (release gate R5)](https://github.com/datagen24/victual/issues/1010) | closed | P2 | M | release evidence | Verification and documentation only: closes release gate R5. | Closed 2026-10-20 by PR #1028; gate R5 evidenced. | https://github.com/datagen24/victual/pull/1028 |

## Open milestones

Observed 2026-10-20T18:38:59Z. Unassigned open issues: [#80](https://github.com/datagen24/victual/issues/80), [#86](https://github.com/datagen24/victual/issues/86), [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133), [#139](https://github.com/datagen24/victual/issues/139).

| Milestone | Open | Closed | Due | Description |
| --- | --- | --- | --- | --- |
| [0.6.0](https://github.com/datagen24/victual/milestone/6) | 9 | 0 | Unset | Later feature release: OAuth then federation; installation country, canonical/scoped barcode resolution and web onboarding, each behind its own design/ADR gates. Full API audit remains later work. These are not requirements for 0.5.0. |
| [0.5.1](https://github.com/datagen24/victual/milestone/7) | 0 | 8 | Unset | Follow-ups found while building 0.5.0 that do not block the first production release: presentation residuals, low-severity disclosures and CI hygiene. Lands after 0.5.0 and before 0.6.0. |
| [0.5.2](https://github.com/datagen24/victual/milestone/8) | 6 | 0 | Unset | Interm quality of life fixes |
| [vNext](https://github.com/datagen24/victual/milestone/9) | 1 | 0 | Unset | Farther in the future Ideas and Concepts, not commited to a planned release |

## Gates carried from 0.5.0

| Gate | At v0.5.0 | Now | Issues | Remaining | Closure rule |
| --- | --- | --- | --- | --- | --- |
| R5: Disposable backup/restore drill tied to supported deployment | Not evidenced at v0.5.0 | Evidenced 2026-10-20 against the v0.5.0 digests: docs/releases/0.5.0-restore-drill.md | [#1010](https://github.com/datagen24/victual/issues/1010) | None for database storage. Not drilled: FILE_STORAGE filesystem, labels/MQTT/InfluxDB traffic, cross-version restore. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |
| R6: Production support claim for cluster labels and shutdown | Not evidenced at v0.5.0 | Not placed: no 0.5.1 claim | [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133) | #93 waits on ADR-0019 acceptance. 0.5.1 makes no cluster label or shutdown support claim. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |

## Planning decisions

| ID | Date | Question | Decision | Issues |
| --- | --- | --- | --- | --- |
| P1 | 2026-10-19 | Does #909, battery and equipment authorization residuals, stay in 0.5.1? | No. Moved to vNext: the issue is unscheduled and waits on the battery and equipment rework. | [#909](https://github.com/datagen24/victual/issues/909) |
| P2 | 2026-10-19 | Which backlog items join 0.5.1? | A new restore-drill issue for gate R5 (#1010) and #252, unreachable code. #133 stays unassigned. | [#1010](https://github.com/datagen24/victual/issues/1010), [#252](https://github.com/datagen24/victual/issues/252), [#133](https://github.com/datagen24/victual/issues/133) |
| P3 | 2026-10-19 | Where does the 0.5.1 dashboard live? | A new dated directory, release-planning/2026-10-19. The 0.5.0 plan in release-planning/2026-10-15 stays frozen. | None |
| P4 | 2026-10-19 | How does the Vale baseline prune land, now that master accepts only pull requests (#988)? | The workflow opens or updates a pull request from chore/vale-baseline-prune using GITHUB_TOKEN. The maintainer enables "Allow GitHub Actions to create and approve pull requests" and closes and reopens each prune pull request to start its checks. | [#988](https://github.com/datagen24/victual/issues/988) |
| P5 | 2026-10-19 | May the calendar 500 body carry the user-supplied event title (#989)? | No. The body is the fixed message; the title, type and id go to the server log. | [#989](https://github.com/datagen24/victual/issues/989) |
| P6 | 2026-10-19 | Does the userfile name-existence signal on upload need a fix (#991)? | No. Accepted as a residual in ADR-0048 item 9. The first rationale offered said DELETE revealed the same signal; it does not (204 either way). The maintainer kept the acceptance on the corrected reasoning. | [#991](https://github.com/datagen24/victual/issues/991) |
| P7 | 2026-10-20 | How do the four open 0.5.1 issues proceed? | #900 is waiting on ADR-0049 acceptance, not on new design work. #904 lands the existing -x5-b migration as one PR rather than a caller-by-caller subset. #252 starts with a record correction, because WebhookRunner was deleted while accepted ADR-0024 says it stays. #1010 runs in its own session. Drafts are in issue-responses-2026-10-20.md. | [#252](https://github.com/datagen24/victual/issues/252), [#900](https://github.com/datagen24/victual/issues/900), [#904](https://github.com/datagen24/victual/issues/904), [#1010](https://github.com/datagen24/victual/issues/1010) |
| P8 | 2026-10-20 | Is ADR-0049 accepted? | Yes, part 1, on the maintainer's motion. Part 2 decides nothing and stays open, so #900 stays open until the comment correction lands. Acceptance is PR #1019, which carries only the lifecycle edits; its dashboard row lives here. | [#900](https://github.com/datagen24/victual/issues/900) |
| P9 | 2026-10-20 | How is ADR-0024's WebhookRunner claim corrected (#252)? | By a superseding record, ADR-0051, as the maintainer directed. The InfluxDB writer never called WebhookRunner (Guzzle directly since 296d99dd), so ADR-0019's original last-caller statement stands. ADR-0051 also proposes removing the webhook residue: three unused catalogue strings, one docstring and plan 20's curl row. | [#252](https://github.com/datagen24/victual/issues/252) |
| P10 | 2026-10-20 | Does ConfigurationValidator keep its demo/prerelease label refusal (#252)? | No. The maintainer ruled to remove it, as the review comment said. checkFileStorage() and the label check already refuse every demo or prerelease configuration that enables labels, and HelperUnitsTest keeps proving it. | [#252](https://github.com/datagen24/victual/issues/252) |
| P11 | 2026-10-20 | Does the #904 PR document every file it touches, when the migration touches 99 PHP files and 79 are below 80%? | Yes. The maintainer chose to honour the docstring rule in full: about 3,100 lines of docblocks in a separate commit, so review can skip it. | [#904](https://github.com/datagen24/victual/issues/904) |
| P12 | 2026-10-20 | What does the manual tell an operator to restore with, given the two-role deployment (#1010)? | Run deploy/postgres/roles.sql against the empty target, then pg_restore as victual_migrate. The drill showed the manual as written fails both as victual_app and as victual_migrate, and that victual_app cannot restore even after roles.sql. The dump itself may run as victual_app. | [#1010](https://github.com/datagen24/victual/issues/1010) |

## GitHub writes made by planning

| When | Change |
| --- | --- |
| 2026-10-19 | [Milestone of #909 changed from 0.5.1 to vNext](https://github.com/datagen24/victual/issues/909) |
| 2026-10-19 | [Milestone of #252 set to 0.5.1](https://github.com/datagen24/victual/issues/252) |
| 2026-10-19 | [Opened #1010, backup and restore drill (gate R5), in 0.5.1](https://github.com/datagen24/victual/issues/1010) |
| 2026-10-20 | [Posted the 2026-10-20 planning comment on #252](https://github.com/datagen24/victual/issues/252#issuecomment-6291698864) |
| 2026-10-20 | [Posted the 2026-10-20 planning comment on #900](https://github.com/datagen24/victual/issues/900#issuecomment-6291699319) |
| 2026-10-20 | [Posted the 2026-10-20 planning comment on #904](https://github.com/datagen24/victual/issues/904#issuecomment-6291699719) |
| 2026-10-20 | [Posted the 2026-10-20 planning comment on #1010](https://github.com/datagen24/victual/issues/1010#issuecomment-6291700046) |
| 2026-10-20 | [Pushed the local-only #904 branch claude/sonnet5_issue904-subprocess-migration-x5-b (e532e4f5) to origin](https://github.com/datagen24/victual/tree/claude/sonnet5_issue904-subprocess-migration-x5-b) |
| 2026-10-20 | [Opened PR #1019, accepting ADR-0049 part 1](https://github.com/datagen24/victual/pull/1019) |
| 2026-10-20 | [Opened PR #1020, correcting the Claim() dead-letter comment to ADR-0049 part 1](https://github.com/datagen24/victual/pull/1020) |
| 2026-10-20 | [Opened #1021 in 0.6.0: decide ADR-0049 part 2](https://github.com/datagen24/victual/issues/1021) |
| 2026-10-20 | [Opened PR #1023, accepting ADR-0051](https://github.com/datagen24/victual/pull/1023) |
| 2026-10-20 | [Opened PR #1024, removing the webhook residue ADR-0051 lists](https://github.com/datagen24/victual/pull/1024) |
| 2026-10-20 | [Opened PR #1025, removing three unreachable branches (#252)](https://github.com/datagen24/victual/pull/1025) |
| 2026-10-20 | [Closed #252 with a summary comment](https://github.com/datagen24/victual/issues/252) |
| 2026-10-20 | [Opened #1026 in 0.5.2: prove or keep the CanonicalJson and StderrLogger fallbacks](https://github.com/datagen24/victual/issues/1026) |
| 2026-10-20 | [Opened PR #1027 for #904](https://github.com/datagen24/victual/pull/1027) |
| 2026-10-20 | [Opened PR #1028 for #1010 (restore drill, gate R5)](https://github.com/datagen24/victual/pull/1028) |
| 2026-10-20 | [Opened PR #1029, the 0.5.1 release pull request](https://github.com/datagen24/victual/pull/1029) |

## Evidence

The latest snapshot is in `evidence/refresh-2026-10-20T183859Z`, taken from the GitHub REST API via gh. Assessments are planning estimates, not validation results.
