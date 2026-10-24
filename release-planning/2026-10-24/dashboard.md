# Victual 0.5.5 release dashboard

0.5.5: 6 of 6 issues closed. Latest release v0.5.4 (published 2026-10-23T19:01:57Z). Master `e1c341e5`. 18 open issues and 0 open PRs repository-wide; 6 open issues have no milestone. Observed 2026-10-24T22:45:08Z.

[Interactive dashboard](dashboard.html) · [Structured source](release-data.json) · [Execution ledger](execution.md) · [Handoff](HANDOFF.md) · [0.5.2 release plan (frozen)](../2026-10-21/dashboard.md)

## Standing rules

- Implementation PRs do not touch release-planning/. After a wave's PRs merge, one planning PR records them: ledger rows, PR links, decisions and GitHub writes, then refresh.py and render.py (maintainer rule, 2026-10-21: per-PR dashboard edits made every parallel PR conflict).
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.
- A newly found or disclosed library vulnerability goes into the next release (maintainer policy, 2026-10-24); Dependabot PRs carry that milestone.

## Patch policy

0.x.y contains compatible fixes and narrowly necessary tests/documentation. A bug label or migration alone does not settle compatibility. P1: consequential security/integrity or required production evidence; P2: moderate affected-workflow risk; P3: later improvement. Priority is assessed here, not a GitHub label or a release-necessity score. S: bounded surface; M: several paths or a race; L: cross-layer/client/deployment work. These are planning estimates, not day commitments.

## 0.5.5 issues

| Issue | State | Priority | Size | Classification | Patch fit | Next action | PR |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [#1021 Decide ADR-0049 part 2: when a label print job becomes unclaimable or dead-lettered](https://github.com/datagen24/victual/issues/1021) | closed | P2 | S | decision record | Compatible: design record only (ADR-0053 proposed in #1095, accepted in #1107). | Closed 2026-10-24 by PR #1107 (merged as 6993218b), after #1095 (85aa11f7). | https://github.com/datagen24/victual/pull/1107 |
| [#1034 Label designer: centre, centre guides and snap-to-grid (split from #948)](https://github.com/datagen24/victual/issues/1034) | closed | P3 | M | enhancement | Compatible: editor-only; grid spacing and snap stored per user, locally (D2), so no schema or wire change. | Closed 2026-10-24 by PR #1100 (merged as 6bf3e988). | https://github.com/datagen24/victual/pull/1100 |
| [#1047 Route the remaining direct json_encode writes through a checked encode (from #912)](https://github.com/datagen24/victual/issues/1047) | closed | P3 | S | robustness | Compatible: an encode failure answers the standard JSON 500 instead of an empty body or false; no success-path change. | Closed 2026-10-24 by PR #1094 (merged as 5f27358c). | https://github.com/datagen24/victual/pull/1094 |
| [#1056 Label printers page cannot issue a renderer credential; label setup needs a UI workflow](https://github.com/datagen24/victual/issues/1056) | closed | P2 | S | operability | Compatible: adds a UI action over the existing renderer-credential route and a manual section; Helm chart unchanged. | Closed 2026-10-24 by PR #1093 (merged as e31a04e0). | https://github.com/datagen24/victual/pull/1093 |
| [#1103 Keep a deactivated printer's queued label jobs queued (ADR-0053 Q1(b))](https://github.com/datagen24/victual/issues/1103) | closed | P2 | S | behaviour (label queue) | Behaviour change, decided in ADR-0053 Q1(b): a deactivated printer's queued jobs stay queued instead of being dead-lettered at the next claim. No migration, no wire change. Listed as a compatibility note in the 0.5.5 record. | Closed 2026-10-24 by PR #1105 (merged as 119eecd7), after ADR-0053's acceptance in #1107. | https://github.com/datagen24/victual/pull/1105 |
| [#1104 ADR-0031 prerequisite 1: victual_barcode_canonicalize with pgTAP coverage](https://github.com/datagen24/victual/issues/1104) | closed | P3 | M | groundwork (schema function) | Compatible: adds migration 0319, one IMMUTABLE function with no callers; evidence for ADR-0031 prerequisite 1 while the ADR is Proposed. | Closed 2026-10-24 by PR #1106 (merged as c6561478). | https://github.com/datagen24/victual/pull/1106 |

## Open milestones

Observed 2026-10-24T22:45:08Z. Unassigned open issues: [#80](https://github.com/datagen24/victual/issues/80), [#86](https://github.com/datagen24/victual/issues/86), [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133), [#139](https://github.com/datagen24/victual/issues/139), [#1090](https://github.com/datagen24/victual/issues/1090).

| Milestone | Open | Closed | Due | Description |
| --- | --- | --- | --- | --- |
| [0.6.0](https://github.com/datagen24/victual/milestone/6) | 9 | 0 | Unset | Later feature release: OAuth then federation; installation country, canonical/scoped barcode resolution and web onboarding, each behind its own design/ADR gates. Full API audit remains later work. These are not requirements for 0.5.0. |
| [vNext](https://github.com/datagen24/victual/milestone/9) | 3 | 0 | Unset | Farther in the future Ideas and Concepts, not commited to a planned release |
| [0.5.5](https://github.com/datagen24/victual/milestone/11) | 0 | 28 | Unset | Interm release for bugs fix and a few cherry picked items from 0.6.0 |

## Gates carried from 0.5.0

| Gate | At v0.5.0 | Now | Issues | Remaining | Closure rule |
| --- | --- | --- | --- | --- | --- |
| R5: Disposable backup/restore drill tied to supported deployment | Not evidenced at v0.5.0 | Evidenced 2026-10-20 for v0.5.0, not re-drilled for 0.5.5. That drill carried over only while a release adds no migration; 0.5.5 adds 0319, one IMMUTABLE function that creates and reads no table (#1106). Release operator decides whether to re-drill. | [#1010](https://github.com/datagen24/victual/issues/1010) | None for database storage. Not drilled: FILE_STORAGE filesystem, labels/MQTT/InfluxDB traffic, cross-version restore. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |
| R6: Production support claim for cluster labels and shutdown | Not evidenced at v0.5.0 | Not placed: 0.5.5 makes no cluster label or shutdown support claim. #1105 changes which queued label jobs a worker claims, not the deployment. | [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133) | #93 waits on ADR-0019 acceptance. 0.5.5 makes no cluster label or shutdown support claim. | Close with candidate-specific evidence, or a narrowly justified production restriction naming affected operations/accounts and user impact. |

## Planning decisions

| ID | Date | Question | Decision | Issues |
| --- | --- | --- | --- | --- |
| D1 | 2026-10-24 | What goes into the interim 0.5.5 release? | Maintainer, 2026-10-24: master as of v0.5.4 onward (PRs #1080-#1089 already merged) plus nine items cherry-picked from 0.6.0, four full (#1056, #1047, #1034, #486) and four groundwork (#1021, #796, #483, #770/#771). Later added: #1103 (ADR-0053 Q1(b) implementation) and #1104 (ADR-0031 prerequisite 1). | [#1056](https://github.com/datagen24/victual/issues/1056), [#1047](https://github.com/datagen24/victual/issues/1047), [#1034](https://github.com/datagen24/victual/issues/1034), [#486](https://github.com/datagen24/victual/issues/486), [#1021](https://github.com/datagen24/victual/issues/1021), [#796](https://github.com/datagen24/victual/issues/796), [#483](https://github.com/datagen24/victual/issues/483), [#770](https://github.com/datagen24/victual/issues/770), [#771](https://github.com/datagen24/victual/issues/771), [#1103](https://github.com/datagen24/victual/issues/1103), [#1104](https://github.com/datagen24/victual/issues/1104) |
| D2 | 2026-10-24 | #1034: where are grid spacing and snap stored? | Per user, locally (browser); no template document field, no schema change. | [#1034](https://github.com/datagen24/victual/issues/1034) |
| D3 | 2026-10-24 | #486: does the onboarding (database-stored) country go into 0.5.5 as migration 0319? | Deferred. 0.5.5 ships the environment half only, with InstallationCountry::Get() as the seam; the database half waits on #484 and on #486 Q1/Q2, and the precedence rule needs a record first. | [#486](https://github.com/datagen24/victual/issues/486), [#484](https://github.com/datagen24/victual/issues/484) |
| D4 | 2026-10-24 | #483: write a new ADR-0054 or revise the existing Proposed ADR-0031? | Revise ADR-0031 in place (option A). ADR-0054 is unused. | [#483](https://github.com/datagen24/victual/issues/483) |
| D5 | 2026-10-24 | ADR-0053: the six ADR-0049 part 2 questions. | All six on the recommended options: inactive printer's and worker's jobs stay queued; other Resolve refusals dead-letter; cancellation is its own state; reprint is the only recovery; no schema change. Accepted in #1107; Q1 built in #1105; dead-letter reason display is #1102 (0.6.0). | [#1021](https://github.com/datagen24/victual/issues/1021), [#1103](https://github.com/datagen24/victual/issues/1103), [#1102](https://github.com/datagen24/victual/issues/1102) |
| D6 | 2026-10-24 | ADR-0031 open questions. | US plus common GS1 member-organisation layouts, cited (prerequisite 7); US default 2IIIIIVPPPPC; unmapped country has no default. Default computed at read time. /api/scan answers unknown for an unreadable vctl: label. SHOPPINGLIST_VIEW gets a reduced product payload. Still Proposed. | [#483](https://github.com/datagen24/victual/issues/483) |
| D7 | 2026-10-24 | ADR-0055 open questions. | Bundle plus single-leaf scopes, none for admin; user keys retire after phase 2 only where an authorization server runs; no write introspection; dynamic registration, no metadata documents; built-in server off by default; client scope ceiling fixed at registration. Proposed. | [#770](https://github.com/datagen24/victual/issues/770) |
| D8 | 2026-10-24 | ADR-0056 open questions. | Authentik issues production tokens, Entra ID upstream; one issuer; bootstrap admin stays local; break-glass is the strictest login mode; linked users may create keys until retirement reaches their install; add an audit table. Proposed; never accepted before ADR-0055. | [#771](https://github.com/datagen24/victual/issues/771) |
| D9 | 2026-10-24 | Where do the groundwork issues go after 0.5.5? | #483, #486, #770, #771 and #796 move to 0.6.0, each with a comment on what 0.5.5 delivered and what remains. 0.5.5 closes at 28 of 28. | [#483](https://github.com/datagen24/victual/issues/483), [#486](https://github.com/datagen24/victual/issues/486), [#770](https://github.com/datagen24/victual/issues/770), [#771](https://github.com/datagen24/victual/issues/771), [#796](https://github.com/datagen24/victual/issues/796) |
| D10 | 2026-10-24 | Where do dependency vulnerability fixes go? | Maintainer policy, stated 2026-10-24: a newly found or disclosed library vulnerability goes into the next release. #1101 (zod in mcp/) and #1108 (zod in the hydra spike, MCP client in the parity harness) are 0.5.5. | None |

## GitHub writes made by planning

| When | Change |
| --- | --- |
| 2026-10-24 | [Moved #1056, #1047, #1034, #486, #1021, #796, #483, #770 and #771 into 0.5.5 (D1)](https://github.com/datagen24/victual/milestone/11) |
| 2026-10-24 | [Opened #1102 in 0.6.0: show a dead-lettered label job's reason (D5)](https://github.com/datagen24/victual/issues/1102) |
| 2026-10-24 | [Opened #1103 in 0.5.5: ADR-0053 Q1(b) implementation (D5)](https://github.com/datagen24/victual/issues/1103) |
| 2026-10-24 | [Opened #1104 in 0.5.5: ADR-0031 prerequisite 1 (D1)](https://github.com/datagen24/victual/issues/1104) |
| 2026-10-24 | [Pushed 9ca09918 to Dependabot PR #1101: moved mcpNpmDeps for the zod 4.6.7 lockfile](https://github.com/datagen24/victual/pull/1101) |
| 2026-10-24 | [Moved #483, #486, #770, #771 and #796 to 0.6.0 with delivery comments (D9)](https://github.com/datagen24/victual/milestone/6) |

## Evidence

The latest snapshot is in `evidence/refresh-2026-10-24T224508Z`, taken from the GitHub REST API via gh. Assessments are planning estimates, not validation results.
