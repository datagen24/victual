# Victual 0.5.2 release dashboard

0.5.2: 0 of 7 issues closed. Latest release v0.5.1 (published 2026-10-20T19:11:29Z). Master `8b2ec3fc`. 24 open issues and 3 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-21T17:08:57Z.

[Interactive dashboard](dashboard.html) · [Structured source](release-data.json) · [Execution ledger](execution.md) · [Handoff](HANDOFF.md) · [0.5.1 release plan (frozen)](../2026-10-19/dashboard.md)

## Standing rules

- Every PR that changes a 0.5.2 issue updates release-data.json, appends a row to execution.md and re-runs render.py in the same PR.
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.

## Patch policy

0.x.y contains compatible fixes and narrowly necessary tests/documentation. A bug label or migration alone does not settle compatibility. P1: consequential security/integrity or required production evidence; P2: moderate affected-workflow risk; P3: later improvement. Priority is assessed here, not a GitHub label or a release-necessity score. S: bounded surface; M: several paths or a race; L: cross-layer/client/deployment work. These are planning estimates, not day commitments.

## 0.5.2 issues

| Issue | State | Priority | Size | Classification | Patch fit | Next action | PR |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [#737 Consumption recipe sharing: answer a share request identically whether or not the user exists](https://github.com/datagen24/victual/issues/737) | open | P2 | M | security (low, authenticated) | Not clearly compatible: POST and PUT /shares stop answering a documented 422 and answer 204, and the existence check moves after the rights check to close the 403/422 second oracle. | Wave 3, after PR 5 (OpenAPI): fix in 0.5.2, 204 for every authorised request, rights before existence. | None |
| [#762 Users: roles can't be set when creating a user, and the only entry point is an unlabeled lock icon](https://github.com/datagen24/victual/issues/762) | open | P2 | S | accessibility / UI | Compatible with option (a), a client-side two-step create (POST /users then PUT /users/{id}/roles). Option (b), an optional roles field on POST /users, is an additive API change. | Draft PR #1041 open (wave 1, PR 2): two-step create, roles replace defaults, labelled Roles button. Householdpages and rbac pass; waiting for review and CI. | https://github.com/datagen24/victual/pull/1041 |
| [#912 Decide whether ApiResponse encodes with JSON_THROW_ON_ERROR (from #844)](https://github.com/datagen24/victual/issues/912) | open | P2 | M | decision, then compatible fix | Success bodies unchanged. Option (a) changes the 500 body from empty to the standard JSON error, the same kind of error-path change #989 made in 0.5.1. ExceptionController needs a fallback that does not recurse through ApiResponse. | Wave 2, after PR 1: option (a), throw, with a fixed fallback body in the error handler. | None |
| [#948 Label designer: describe merge fields with names, explanations and example values](https://github.com/datagen24/victual/issues/948) | open | P3 | S | UI / copy | Compatible after the D6 split: merge-field names, explanations and example values as client strings; no API or schema change. | Split per D6. This issue keeps the merge-field descriptions only: PR 3 (wave 1), branch claude/v0-5-2-948-field-descriptions. | None |
| [#1007 UI: group medication features in a Medication flyout and clarify labels](https://github.com/datagen24/victual/issues/1007) | open | P3 | M | UI / wording | Compatible: navigation and labels only; URLs, permissions and API unchanged. About 4 to 7 msgids change in strings.pot. | Wave 2, after PR 2 (strings.pot): Medication presets, Medication inbox, Refills. | None |
| [#1026 Prove or keep the CanonicalJson and StderrLogger fallbacks #252 left in place](https://github.com/datagen24/victual/issues/1026) | open | P3 | S | cleanup / record | Compatible: removes unreachable guards or keeps them with a comment; no wire change. CanonicalJson output feeds stored label template digests, so output must not change. | In progress: PR 1 (wave 1), branch claude/v0-5-2-1026-fallbacks. Lands before #912. | None |
| [#1035 File upload with a form content type answers 204 and stores an empty file](https://github.com/datagen24/victual/issues/1035) | open | P3 | S | bug | Compatible: a request that stored an empty file now answers 415 and stores nothing; documented requests are unchanged. | In progress: PR 4 (wave 1), branch claude/v0-5-2-files-415. | None |

## Open milestones

Observed 2026-10-21T17:08:57Z. Unassigned open issues: [#80](https://github.com/datagen24/victual/issues/80), [#86](https://github.com/datagen24/victual/issues/86), [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133), [#139](https://github.com/datagen24/victual/issues/139).

| Milestone | Open | Closed | Due | Description |
| --- | --- | --- | --- | --- |
| [0.6.0](https://github.com/datagen24/victual/milestone/6) | 11 | 0 | Unset | Later feature release: OAuth then federation; installation country, canonical/scoped barcode resolution and web onboarding, each behind its own design/ADR gates. Full API audit remains later work. These are not requirements for 0.5.0. |
| [0.5.2](https://github.com/datagen24/victual/milestone/8) | 10 | 0 | Unset | Interm quality of life fixes |
| [vNext](https://github.com/datagen24/victual/milestone/9) | 1 | 0 | Unset | Farther in the future Ideas and Concepts, not commited to a planned release |

## Gates carried from 0.5.0

| Gate | At v0.5.0 | Now | Issues | Remaining | Closure rule |
| --- | --- | --- | --- | --- | --- |
| R5: Disposable backup/restore drill tied to supported deployment | Not evidenced at v0.5.0 | Evidenced 2026-10-20 for v0.5.0 (docs/releases/0.5.0-restore-drill.md). It holds for 0.5.2 only while 0.5.2 adds no migration and changes no deployment path. | [#1010](https://github.com/datagen24/victual/issues/1010) | None for database storage. Not drilled: FILE_STORAGE filesystem, labels/MQTT/InfluxDB traffic, cross-version restore. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |
| R6: Production support claim for cluster labels and shutdown | Not evidenced at v0.5.0 | Not placed: 0.5.2 makes no cluster label or shutdown support claim. | [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133) | #93 waits on ADR-0019 acceptance. 0.5.1 makes no cluster label or shutdown support claim. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |

## Planning decisions

| ID | Date | Question | Decision | Issues |
| --- | --- | --- | --- | --- |
| D1 | 2026-10-21 | What does it take to remove a #1026 fallback? | Accepted by the maintainer on 2026-10-21: per branch, documented PHP 8.4 and 8.5 behaviour plus a test that runs on both; a branch without that proof stays, with a deliberately-defensive comment. | [#1026](https://github.com/datagen24/victual/issues/1026) |
| D2 | 2026-10-21 | Does ApiResponse encode with JSON_THROW_ON_ERROR (#912)? | Accepted by the maintainer on 2026-10-21: (a) throw, so an unencodable body gives the standard JSON 500 with a logged cause; the error handler gets a fixed fallback body. Alternative (b): keep the empty 500 and close the issue as a decision. | [#912](https://github.com/datagen24/victual/issues/912) |
| D3 | 2026-10-21 | Does #737 ship in 0.5.2? | Accepted by the maintainer on 2026-10-21: yes, as a patch. It answers 204 for every authorised share request and checks rights before existence. The alternative is 0.6.0 with an accepted residual note in the form of ADR-0048 item 9. | [#737](https://github.com/datagen24/victual/issues/737) |
| D4 | 2026-10-21 | How does #762 save roles at creation, and how is the entry point labelled? | Accepted by the maintainer on 2026-10-21: (a) client-side two-step, no API change; the chosen roles replace the defaults; a visible "Roles" text button with an aria-label. | [#762](https://github.com/datagen24/victual/issues/762) |
| D5 | 2026-10-21 | What are the Medication flyout names (#1007), and when does the parent entry show? | Accepted by the maintainer on 2026-10-21: Medication presets, Medication inbox and Refills, as the issue proposes; the parent shows when at least one child is visible. | [#1007](https://github.com/datagen24/victual/issues/1007) |
| D6 | 2026-10-21 | How is #948 scoped? | Accepted by the maintainer on 2026-10-21: split it. Merge-field descriptions as client strings in 0.5.2. Alignment, guides and grid as a 0.6.0 issue. Orientation, rotation and starter fonts as a 0.6.0 issue that needs a template-document schema decision. | [#948](https://github.com/datagen24/victual/issues/948) |
| D7 | 2026-10-21 | Does the empty-file upload limitation from 0.5.1 join 0.5.2? | Accepted by the maintainer on 2026-10-21: yes, as a new issue. PUT /api/files/{group}/{name} answers 415 for any content type other than application/octet-stream instead of storing 0 bytes. Size S. | None |
| D8 | 2026-10-21 | How do the three /mcp Dependabot PRs land? | Accepted by the maintainer on 2026-10-21: one PR with all three bumps and a recomputed nix/hashes.nix mcpNpmDeps; close #1030 to #1032. Each one fails flake on its own because the lockfile hash is stale, and the three conflict on the lockfile. hono 4.13.13 includes GHSA-5r4p-p66f-jhc7; mcp/ does not use serveStatic. | None |

## GitHub writes made by planning

| When | Change |
| --- | --- |
| 2026-10-21 | [Opened #1033 in 0.6.0: label orientation, rotation and starter fonts (split from #948, D6)](https://github.com/datagen24/victual/issues/1033) |
| 2026-10-21 | [Opened #1034 in 0.6.0: label centring, guides and grid (split from #948, D6)](https://github.com/datagen24/victual/issues/1034) |
| 2026-10-21 | [Opened #1035 in 0.5.2: file upload with a form content type stores an empty file (D7)](https://github.com/datagen24/victual/issues/1035) |
| 2026-10-21 | [Retitled #948 to its 0.5.2 scope, merge-field descriptions (D6)](https://github.com/datagen24/victual/issues/948) |
| 2026-10-21 | [Posted the D1 decision comment on #1026](https://github.com/datagen24/victual/issues/1026#issuecomment-6321883156) |
| 2026-10-21 | [Posted the D2 decision comment on #912](https://github.com/datagen24/victual/issues/912#issuecomment-6321883597) |
| 2026-10-21 | [Posted the D3 decision comment on #737](https://github.com/datagen24/victual/issues/737#issuecomment-6321883991) |
| 2026-10-21 | [Posted the D4 decision comment on #762](https://github.com/datagen24/victual/issues/762#issuecomment-6321884476) |
| 2026-10-21 | [Posted the D5 decision comment on #1007](https://github.com/datagen24/victual/issues/1007#issuecomment-6321884829) |
| 2026-10-21 | [Posted the D6 decision comment on #948](https://github.com/datagen24/victual/issues/948#issuecomment-6321885204) |
| 2026-10-21 | [Closed Dependabot PR #1030 as superseded by PR #1037 (D8)](https://github.com/datagen24/victual/pull/1030) |
| 2026-10-21 | [Closed Dependabot PR #1031 as superseded by PR #1037 (D8)](https://github.com/datagen24/victual/pull/1031) |
| 2026-10-21 | [Closed Dependabot PR #1032 as superseded by PR #1037 (D8)](https://github.com/datagen24/victual/pull/1032) |

## Evidence

The latest snapshot is in `evidence/refresh-2026-10-21T170857Z`, taken from the GitHub REST API via gh. Assessments are planning estimates, not validation results.
