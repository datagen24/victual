# Victual 0.5.2 release dashboard

0.5.2: 8 of 8 issues closed. Latest release v0.5.1 (published 2026-10-20T19:11:29Z). Master `e7edd6b5`. 18 open issues and 0 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-22T15:18:42Z.

[Interactive dashboard](dashboard.html) · [Structured source](release-data.json) · [Execution ledger](execution.md) · [Handoff](HANDOFF.md) · [0.5.1 release plan (frozen)](../2026-10-19/dashboard.md)

## Standing rules

- Implementation PRs do not touch release-planning/. After a wave's PRs merge, one planning PR records them: ledger rows, PR links, decisions and GitHub writes, then refresh.py and render.py (maintainer rule, 2026-10-21: per-PR dashboard edits made every parallel PR conflict).
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.

## Patch policy

0.x.y contains compatible fixes and narrowly necessary tests/documentation. A bug label or migration alone does not settle compatibility. P1: consequential security/integrity or required production evidence; P2: moderate affected-workflow risk; P3: later improvement. Priority is assessed here, not a GitHub label or a release-necessity score. S: bounded surface; M: several paths or a race; L: cross-layer/client/deployment work. These are planning estimates, not day commitments.

## 0.5.2 issues

| Issue | State | Priority | Size | Classification | Patch fit | Next action | PR |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [#737 Consumption recipe sharing: answer a share request identically whether or not the user exists](https://github.com/datagen24/victual/issues/737) | closed | P2 | M | security (low, authenticated) | Not strictly compatible: POST/PUT stop answering a documented 422 and answer 204; DELETE answers 404 instead of 403 for a share the caller may not remove; a POST with no target answers 400. Listed as compatibility notes in the 0.5.2 record. | Closed 2026-10-22 by PR #1051 (merged as e7edd6b5), after PR #1050 (merged as 50574fe8). | https://github.com/datagen24/victual/pull/1051 |
| [#762 Users: roles can't be set when creating a user, and the only entry point is an unlabeled lock icon](https://github.com/datagen24/victual/issues/762) | closed | P2 | S | accessibility / UI | Compatible with option (a), a client-side two-step create (POST /users then PUT /users/{id}/roles). Option (b), an optional roles field on POST /users, is an additive API change. | Closed 2026-10-21 by PR #1041, merged as 4a51909b. | https://github.com/datagen24/victual/pull/1041 |
| [#912 Decide whether ApiResponse encodes with JSON_THROW_ON_ERROR (from #844)](https://github.com/datagen24/victual/issues/912) | closed | P2 | M | decision, then compatible fix | Success bodies unchanged. Option (a) changes the 500 body from empty to the standard JSON error, the same kind of error-path change #989 made in 0.5.1. ExceptionController needs a fallback that does not recurse through ApiResponse. | Closed 2026-10-21 by PR #1046, merged as 710607a1 (D2 as amended). | https://github.com/datagen24/victual/pull/1046 |
| [#948 Label designer: describe merge fields with names, explanations and example values](https://github.com/datagen24/victual/issues/948) | closed | P3 | S | UI / copy | Compatible after the D6 split: merge-field names, explanations and example values as client strings; no API or schema change. | Closed 2026-10-21 by PR #1038, merged as 8c9d131e; the rest is #1033 and #1034 (0.6.0). | https://github.com/datagen24/victual/pull/1038 |
| [#1007 UI: group medication features in a Medication flyout and clarify labels](https://github.com/datagen24/victual/issues/1007) | closed | P3 | M | UI / wording | Compatible: navigation and labels only; URLs, permissions and API unchanged. About 4 to 7 msgids change in strings.pot. | Closed 2026-10-21 by PR #1048, merged as 9c8a9835. | https://github.com/datagen24/victual/pull/1048 |
| [#1026 Prove or keep the CanonicalJson and StderrLogger fallbacks #252 left in place](https://github.com/datagen24/victual/issues/1026) | closed | P3 | S | cleanup / record | Compatible: removes unreachable guards or keeps them with a comment; no wire change. CanonicalJson output feeds stored label template digests, so output must not change. | Closed 2026-10-21 by PR #1040, merged as 1ea89625. | https://github.com/datagen24/victual/pull/1040 |
| [#1035 File upload with a form content type answers 204 and stores an empty file](https://github.com/datagen24/victual/issues/1035) | closed | P3 | S | bug | Compatible: an upload whose JSON, form, XML or multipart type stored an empty file now answers 415 and stores nothing; every other type is unchanged. | Closed 2026-10-21 by PR #1039, merged as c9c4bcd1 (D7 as amended). | https://github.com/datagen24/victual/pull/1039 |
| [#1043 PHP2Shell: raise the PHP floor to 8.4.27 / 8.5.12 and rebuild the images on 8.5.12](https://github.com/datagen24/victual/issues/1043) | closed | P1 | M | security | Compatible for supported deployments: it raises the PHP floor to the patched releases and rebuilds the images. An installation on PHP 8.4.1 to 8.4.26 or 8.5.0 to 8.5.11 is refused at startup until PHP is updated. | Closed 2026-10-21 by PR #1045, merged as 56144682. Images build on PHP 8.5.12. | https://github.com/datagen24/victual/pull/1045 |

## Open milestones

Observed 2026-10-22T15:18:42Z. Unassigned open issues: [#80](https://github.com/datagen24/victual/issues/80), [#86](https://github.com/datagen24/victual/issues/86), [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133), [#139](https://github.com/datagen24/victual/issues/139).

| Milestone | Open | Closed | Due | Description |
| --- | --- | --- | --- | --- |
| [0.6.0](https://github.com/datagen24/victual/milestone/6) | 12 | 0 | Unset | Later feature release: OAuth then federation; installation country, canonical/scoped barcode resolution and web onboarding, each behind its own design/ADR gates. Full API audit remains later work. These are not requirements for 0.5.0. |
| [0.5.2](https://github.com/datagen24/victual/milestone/8) | 0 | 11 | Unset | Interm quality of life fixes |
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
| D2 | 2026-10-21 | Does ApiResponse encode with JSON_THROW_ON_ERROR (#912)? | Amended by the maintainer on 2026-10-21: option (a). An unencodable body answers the standard JSON 500 with a logged cause. Inside InRequestTransaction the write rolls back; outside, earlier writes stay committed. GenericErrorResponse keeps the original status with a fixed literal; ExceptionController has a fixed fallback. | [#912](https://github.com/datagen24/victual/issues/912) |
| D3 | 2026-10-21 | Does #737 ship in 0.5.2? | Accepted 2026-10-21, extended 2026-10-22: POST/PUT /shares answer 204 for every authorised caller and only a real user gets a row; rights before existence; a hidden share answers like an unknown user; DELETE answers 404 for a share the caller may not remove. A POST with no target is 400 invalid_request. | [#737](https://github.com/datagen24/victual/issues/737) |
| D4 | 2026-10-21 | How does #762 save roles at creation, and how is the entry point labelled? | Accepted by the maintainer on 2026-10-21: (a) client-side two-step, no API change; the chosen roles replace the defaults; a visible "Roles" text button with an aria-label. | [#762](https://github.com/datagen24/victual/issues/762) |
| D5 | 2026-10-21 | What are the Medication flyout names (#1007), and when does the parent entry show? | Accepted by the maintainer on 2026-10-21: Medication presets, Medication inbox and Refills, as the issue proposes; the parent shows when at least one child is visible. | [#1007](https://github.com/datagen24/victual/issues/1007) |
| D6 | 2026-10-21 | How is #948 scoped? | Accepted by the maintainer on 2026-10-21: split it. Merge-field descriptions as client strings in 0.5.2. Alignment, guides and grid as a 0.6.0 issue. Orientation, rotation and starter fonts as a 0.6.0 issue that needs a template-document schema decision. | [#948](https://github.com/datagen24/victual/issues/948) |
| D7 | 2026-10-21 | Does the empty-file upload limitation from 0.5.1 join 0.5.2? | Amended by the maintainer on 2026-10-21: 415 only for types the body parser or PHP consumes (JSON/+json, form-urlencoded, XML/+xml, multipart/form-data); every other type, including application/pdf, is stored as today. | None |
| D8 | 2026-10-21 | How do the three /mcp Dependabot PRs land? | Accepted by the maintainer on 2026-10-21: one PR with all three bumps and a recomputed nix/hashes.nix mcpNpmDeps; close #1030 to #1032. Each one fails flake on its own because the lockfile hash is stale, and the three conflict on the lockfile. hono 4.13.13 includes GHSA-5r4p-p66f-jhc7; mcp/ does not use serveStatic. | None |
| D9 | 2026-10-21 | How does 0.5.2 respond to PHP2Shell (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp)? | Accepted by the maintainer on 2026-10-21: floor ~8.4.27 \|\| ^8.5.12 in composer.json and PrerequisiteChecker; Dockerfile and CI pinned to those releases; flake.lock moved to a nixpkgs master commit with php85 8.5.12. The v0.5.0 and v0.5.1 images run the affected 8.5.10. | [#1043](https://github.com/datagen24/victual/issues/1043) |
| D10 | 2026-10-21 | Where do planning records live while PRs run in parallel? | In one planning PR per wave, after the wave merges. Implementation PRs do not touch release-planning/. #1040 and #1041 were reset to master's planning files to unblock them. | None |

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
| 2026-10-21 | [Posted the amended D7 decision comment on #1035](https://github.com/datagen24/victual/issues/1035#issuecomment-6322328246) |
| 2026-10-21 | [Opened #1043 in 0.5.2: PHP2Shell, raise the PHP floor to 8.4.27 / 8.5.12 (D9)](https://github.com/datagen24/victual/issues/1043) |
| 2026-10-21 | [Closed #1026 with a summary comment (PR #1040)](https://github.com/datagen24/victual/issues/1026) |
| 2026-10-21 | [Closed #762 with a summary comment (PR #1041)](https://github.com/datagen24/victual/issues/762) |
| 2026-10-21 | [Closed #948 with a summary comment (PR #1038)](https://github.com/datagen24/victual/issues/948) |
| 2026-10-21 | [Posted the amended D2 decision comment on #912](https://github.com/datagen24/victual/issues/912#issuecomment-6325246814) |
| 2026-10-21 | [Opened #1047 in 0.6.0: route the remaining direct json_encode writes through a checked encode](https://github.com/datagen24/victual/issues/1047) |
| 2026-10-21 | [Closed #912 with a summary comment (PR #1046)](https://github.com/datagen24/victual/issues/912) |
| 2026-10-21 | [Closed #1007 with a summary comment (PR #1048)](https://github.com/datagen24/victual/issues/1007) |
| 2026-10-22 | [Posted the extended D3 decision comment on #737](https://github.com/datagen24/victual/issues/737#issuecomment-6340732688) |
| 2026-10-22 | [Opened PR #1051 for the DELETE commit, which landed on the #1050 branch after #1050 merged](https://github.com/datagen24/victual/pull/1051) |

## Evidence

The latest snapshot is in `evidence/refresh-2026-10-22T151842Z`, taken from the GitHub REST API via gh. Assessments are planning estimates, not validation results.
