# Victual release plan and dashboard

## Active release execution

0.5.1 planning: Signed v0.5.0 targets 555475aeec4ce2840671ab5623460ebf9f8440b3 and release workflow 39250766266 succeeded, so 0.5.0 is published. Current master is 431b1a97c1354a960a6e6a7e194eb1976bc4112a (PRs #1005 and #1006 land plan 22 and close its ledger). 4 issues are open in 0.5.1; no 0.5.1 candidate exists.

[Execution ledger](execution.md)

Master `431b1a97`; last validated `555475ae`.

- 0.5.1: #987, #988, #989, #991
- Decide R5 (restore drill) and R6 (cluster labels/shutdown) evidence and their milestone
- Place #93 and #133, proposed for 0.5.0 under D2 but not shipped in it
- Triage #1007 and the other unassigned issues

No 0.5.1 units merged yet.

Shipped units, listed in the execution ledger: 0.5.0 (25) · 0.4.4 (5) · 0.4.3 (5) · 0.4.2 (4).

0.5.0, the first production release, is published: signed tag v0.5.0 targets `555475ae` and release workflow 39250766266 published six images and the Helm chart for amd64 and arm64 on 2026-10-19. The 0.5.0 milestone is closed out (48 closed, 0 open). Two adopted gates were not evidenced at the tag: no backup/restore drill (R5) and no cluster label-delivery/shutdown demonstration (R6), so 0.5.0 claims neither. The next lane is 0.5.1 (4 open follow-ups). 26 issues remain open repository-wide.

Observed: 2026-10-19T14:43:24Z. Planning baseline SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`. Current master SHA: `431b1a97c1354a960a6e6a7e194eb1976bc4112a`. Current assignments were refreshed from the complete GitHub open-issue list and milestone API. Original assignments are preserved in `baseline_milestone` and evidence/release-data-before-apply.json.


[Interactive dashboard](dashboard.html) · [Structured source](release-data.json) · [Adjustment table](milestone-adjustments.md) · [Handoff](HANDOFF.md)

## Evidence and scope

Inventory refreshed 2026-10-19T14:43:24Z: 26 open issues and 0 open PRs across the repository. 12 further issues from the original 53-issue cohort closed since the prior snapshot; 21 remain open. Latest release: v0.5.0, published 2026-10-19T12:28:41Z from `555475aeec4ce2840671ab5623460ebf9f8440b3`. Actual issue milestone assignments and all six milestone states/counts were refreshed from GitHub.

Priority, severity, size and release necessity are separate assessments. S: bounded surface; M: several paths or a race; L: cross-layer/client/deployment work. These are planning estimates, not day commitments. P1: consequential security/integrity or required production evidence; P2: moderate affected-workflow risk; P3: later improvement. Priority is assessed here, not a GitHub label or a release-necessity score.

## Release outcomes

### Shipped release history: 0.4.1 — Released; preserve history

Historical stabilization plus features and compatibility changes; it does not meet the supplied patch policy.

Published 2026-10-14T21:22:50Z, tag b69f5a626673ea1363990f8711a5958584f7bc04. 91 merged PRs since 0.4.0 include the completed three-PR round and #955, MASTER_DATA_ADD, five migrations and permission/API changes.

Proposed scope: No new scope: record actual shipped contents and historical deviations. Tag still reports Version 0.4.0 and lacks its release record; tag publication identity job failed.

Deferral or limit: No retroactive rescope or retag. Correct guidance in a later commit/release.

[evidence/shipped-041-commits.txt](evidence/shipped-041-commits.txt), [evidence/tag-version-evidence.txt](evidence/tag-version-evidence.txt), [evidence/release-041-jobs.txt](evidence/release-041-jobs.txt), [Published 0.4.1](https://github.com/datagen24/victual/releases/tag/v0.4.1)

### Shipped release history: 0.4.1-hotfix1 — Published historical hotfix; superseded by 0.4.4

Repairs release version identity and includes the release automation/record additions after the earlier tag.

Historical tag v0.4.1-hotfix1 targets b5532aafd7b91306f7e98dd3361ba82d1ebbe5da. It was the predecessor to 0.4.2 and has since been superseded by published v0.4.4. Do not use it as the next-release upgrade baseline.

Proposed scope: Historical release lane. Do not use it as the next upgrade baseline; v0.4.4 is the actual preceding release for 0.5.0. Retain its release record and observed lint failure as history.

Deferral or limit: Use the actual preceding release v0.4.4 as the 0.5.0 upgrade start. Complete its populated upgrade rehearsal and refusal/recovery evidence before production.

[evidence/releases.json](evidence/releases.json), [evidence/hotfix-diff.txt](evidence/hotfix-diff.txt), [evidence/lint-log.txt](evidence/lint-log.txt), [Published hotfix](https://github.com/datagen24/victual/releases/tag/v0.4.1-hotfix1)

### Shipped release history: 0.4.2 — Published 2026-10-15; superseded by 0.4.4

Clients and operators can rely on corrected documentation of already shipped behavior.

Published at https://github.com/datagen24/victual/releases/tag/v0.4.2. Six images and Helm chart published; details remain in the release record and publication_043 predecessor entry. Closed in the tracked release cohort: #801, #893, #913, #936 and #953.

Proposed scope: #801 bounded residual known-error documentation, #893 snapshot-reached non-stock keys, #936 fractional chore type, #953 label-mode mapping, #913 view-permission manual; #944 documentation-only child. Include narrowly necessary dated release-record corrections. Each PR remains independently reviewable.

Deferral or limit: #908 permission decision, #892 runtime residuals, #890 precision bundle, full #796 audit. No broad audit completion or runtime input narrowing.

[#801](https://github.com/datagen24/victual/issues/801), [#893](https://github.com/datagen24/victual/issues/893), [#936](https://github.com/datagen24/victual/issues/936), [#953](https://github.com/datagen24/victual/issues/953), [#913](https://github.com/datagen24/victual/issues/913)

### Shipped release history: 0.4.3 — Published 2026-10-15; post-publication record correction merged in PR #974

Application and legacy import paths preserve computed/source floats.

Signed SSH tag v0.4.3 object 02b584ab7a578c9dae799d50c837d3103f1585e1 targets 320f610e62c966b1b5dc4a1987bb728cf9966718. Release workflow 38815984146 succeeded on linux/amd64 and linux/arm64; six images and Helm chart published. GitHub release: https://github.com/datagen24/victual/releases/tag/v0.4.3. PR #969 merged at c2e9ece; PR #970 merged at tagged SHA. PR #971 merged; its publication evidence is recorded in the 0.4.4 release record (PR #974). Closed in the tracked release cohort: #890, #917 and #951.

Proposed scope: #951 application precision, #917 SQLite REAL import verification, and #890 lookup factor/inverse normality. Release notes disclose low-order response digits. Candidate has no schema/migration changes.

Deferral or limit: #774 price semantics, #916 nonfinite input policy, #905 write authorization, unrelated #904 migration and label signaling remain outside this patch.

[#951](https://github.com/datagen24/victual/issues/951), [#917](https://github.com/datagen24/victual/issues/917), [#890](https://github.com/datagen24/victual/issues/890), [0.4.4 release record correction (PR #974)](https://github.com/datagen24/victual/pull/974)

### Shipped release history: 0.4.4 — Published 2026-10-16; superseded by 0.5.0

New label render artifacts retain bytes and visible lifecycle changes reach monitors.

Signed SSH tag v0.4.4 targets 631c6b27cf0d743b6068b5f503940ff9dc97e496. Release workflow 38898838522 completed successfully on 2026-10-16T12:26:57Z; six images and the Helm chart were published for linux/amd64 and linux/arm64. GitHub release: https://github.com/datagen24/victual/releases/tag/v0.4.4. PR #972 completed #926; PR #973 completed #898; PR #974 merged the release record.

Proposed scope: #926 artifact acceptance/collection and #898 visible-state signalling, with tests for those repairs. Preserve collected-artifact reprint refusal and quiet idle polls. The release record also corrects the v0.4.3 publication statement.

Deferral or limit: Numeric/error-contract decisions, stock-integrity investigations and independent InfluxDB drain repair remain in the 0.5.0 review/disposition queue. A bundle need not be one PR.

[Issue #926](https://github.com/datagen24/victual/issues/926), [Issue #898](https://github.com/datagen24/victual/issues/898), [PR #972](https://github.com/datagen24/victual/pull/972), [PR #973](https://github.com/datagen24/victual/pull/973), [Release record PR #974](https://github.com/datagen24/victual/pull/974), [Published release](https://github.com/datagen24/victual/releases/tag/v0.4.4), [Release workflow](https://github.com/datagen24/victual/actions/runs/38898838522)

### Shipped release history: 0.5.0 — Published 2026-10-19; first production release

Medication inventory/private consumption/refills plus deliberate price and idempotency contracts, supported by reproducible production evidence.

Signed tag v0.5.0 (object 5a3960de) targets 555475aeec4ce2840671ab5623460ebf9f8440b3, the merge of PR #1004. Release workflow 39250766266 completed successfully; six images and the Helm chart were published for linux/amd64 and linux/arm64. GitHub release published 2026-10-19T12:28:41Z. Milestone 0.5.0: 48 closed, 0 open. Spans PRs #975–#1003; migrations 0313–0318; ADR-0045, -0046, -0048 and -0050 accepted. Plan 22 landed (PRs #1005, #1006).

Proposed scope: Mandatory Plan 22 boundary (M1–M9); proposed security/integrity disposition gates R1–R6; maintain accurate operation contracts and required client adoption. Assigned candidates need implementation or an explicit residual disposition, not automatic promotion of every P1 into a mandatory feature. The 43 figure is the total unresolved assessed backlog, not the 0.5.0 gate count; preserve the distinction between mandatory gates, selected additional gates, and candidate/deferred issues.

Deferral or limit: Not evidenced at the tag and therefore not claimed: backup/restore drill (R5) and cluster label delivery and shutdown (R6, #93/#133). Upgrade rehearsal ran on PostgreSQL 16.15 only; PostgreSQL 15 is covered by the tests workflow's migration phase. Apple Health acceptance is simulator-only. #891 closed not planned; victual-kit adopts the contract after the tag.

[Release record](https://github.com/datagen24/victual/blob/master/docs/releases/0.5.0.md), [Upgrade rehearsal](https://github.com/datagen24/victual/blob/master/docs/releases/0.5.0-upgrade-rehearsal.md), [Published release](https://github.com/datagen24/victual/releases/tag/v0.5.0), [Release workflow](https://github.com/datagen24/victual/actions/runs/39250766266), [tests workflow on 2b350b1d](https://github.com/datagen24/victual/actions/runs/39187337919), [Release PR #1004](https://github.com/datagen24/victual/pull/1004)

### Active or proposed release lanes: 0.5.1 — Next active milestone; follow-ups after the first production release

Close the non-blocking residuals found while building 0.5.0.

GitHub milestone 0.5.1 is open with 4 open issues (#987, #988, #989, #991); its due date is unset.

Proposed scope: recipe_id validation (#987), calendar 500 title (#989), userfile name-existence signal (#991), Vale baseline prune (#988).

Deferral or limit: R5 and R6 evidence, and placement of #93/#133, are not assigned here; they need a maintainer decision.

[Milestone 0.5.1](https://github.com/datagen24/victual/milestone/7)

### Active or proposed release lanes: 0.6.0 (rename 0.5.5) — Proposed later minor milestone

Retain the maintainer’s later feature horizon with a policy-compatible minor version.

Existing 0.5.5 holds #770/#771/#483/#484/#486; all are feature/design work.

Proposed scope: Rename the existing milestone to 0.6.0; preserve its five assignments and add full #796 audit as recommended later work. Sequence #770 before #771, #486 defaults before #483 integration, and #483 independent conflicts page before #484 hosts it.

Deferral or limit: No pull-forward into first production. Every architecture still needs its own accepted ADR and prerequisites.

[#770](https://github.com/datagen24/victual/issues/770), [#771](https://github.com/datagen24/victual/issues/771), [#483](https://github.com/datagen24/victual/issues/483), [#484](https://github.com/datagen24/victual/issues/484), [#486](https://github.com/datagen24/victual/issues/486)

Version 0.5.0, the first production release, is the latest shipped release. Treat 0.4.1 through 0.5.0 as history, not active release lanes. The next active destination is 0.5.1. Rehearse each upgrade from the actual preceding release.

## Open backlog

26 open issues, none in 0.5.0: 0.5.0 shipped on 2026-10-19 and its milestone holds 48 closed and 0 open. Actual: 4 assigned to 0.5.1 (#987–#989, #991), 6 to 0.6.0, 16 unassigned (including #1007, opened after the tag). #93 and #133, which D2 proposed for 0.5.0, did not ship in it and need a new placement.

The proposed 0.5.0 lane has 0 direct issue rows. Classification within those rows: . In particular, candidate/deferred rows are not automatic production gates; review the six recommended dispositions for narrowing or deferral before implementation. The original milestone proposal is preserved for maintainer review.

The 0.5.0 checklist below is the record of how each gate closed at the v0.5.0 tag. Milestone assignment by itself does not make an issue a production gate.

## Current GitHub milestone inventory

Observed 2026-10-19T14:43:24Z. Dates remain unset.

| Milestone | State | Open issues | Closed issues | Due date | Description |
| --- | --- | --- | --- | --- | --- |
| [0.3.0](https://github.com/datagen24/victual/milestone/1) | closed | 0 | 9 | Unset | Exit criteria only: green CI on the RC, upgrade demonstrated from 0.2.0-MVP, #249 resolved, #625 disposed, limitations #609/#612 recorded, release notes, version.json + record + index, signed tag and published images. Feature work stays out. |
| [0.5.0](https://github.com/datagen24/victual/milestone/2) | open | 0 | 48 | Unset | First production release: complete the agreed Plan 22 boundary, ADR-0045 price semantics, ADR-0046 booking idempotency, maintained-client contract adoption, and candidate-specific tests, upgrade, images/Helm, operator documentation and release evidence. Explicitly disposition remaining security and integrity findings. Required and recommended gates are distinguished in the approved release plan. |
| [0.4.2](https://github.com/datagen24/victual/milestone/3) | open | 0 | 9 | Unset | Compatible contract stabilization only: bounded OpenAPI/documentation corrections for shipped behavior, supporting regression fixtures, operator permission guidance and dated release-record corrections. No runtime permission/input changes and no full API audit umbrella. |
| [0.4.3](https://github.com/datagen24/victual/milestone/4) | open | 0 | 3 | Unset | Conditional precision patch: lossless application/import float binding and persisted lookup-boundary verification. Preserve valid inputs, source values and rollback. Publish only after observable storage/output compatibility is reviewed. |
| [0.4.4](https://github.com/datagen24/victual/milestone/5) | open | 0 | 2 | Unset | Conditional label artifact lifecycle patch: new-render bytes survive reuse/collection, and visible state changes signal correctly. Preserve historical artifact retention and reprint refusals. Keep PRs independently reviewable. |
| [0.6.0](https://github.com/datagen24/victual/milestone/6) | open | 6 | 0 | Unset | Later feature release: OAuth then federation; installation country, canonical/scoped barcode resolution and web onboarding, each behind its own design/ADR gates. Full API audit remains later work. These are not requirements for 0.5.0. |
| [0.5.1](https://github.com/datagen24/victual/milestone/7) | open | 4 | 0 | Unset | Follow-ups found while building 0.5.0 that do not block the first production release: presentation residuals, low-severity disclosures and CI hygiene. Lands after 0.5.0 and before 0.6.0. |

## Milestone metadata proposal

| Milestone | Action | Proposed title | Proposed description | Date | Reason |
| --- | --- | --- | --- | --- | --- |
| [0.3.0](https://github.com/datagen24/victual/milestone/1) | Close milestone | 0.3.0 | Exit criteria only: green CI on the RC, upgrade demonstrated from 0.2.0-MVP, #249 resolved, #625 disposed, limitations #609/#612 recorded, release notes, version.json + record + index, signed tag and published images. Feature work stays out. | Leave unset | Released v0.3.0 exists and every assigned issue in the complete inventory is closed; this is metadata cleanup, not release-history repair. |
| [0.5.0](https://github.com/datagen24/victual/milestone/2) | Replace description | 0.5.0 | First production release: agreed Plan 22 boundary, adopted ADR decisions, maintained-client contracts, supported deployment paths, and candidate-specific tests, upgrade, recovery, operator documentation and release evidence. Exclude unverified Home Assistant and MCP paths from the support claim. | Leave unset | Make first-production scope finite and reviewable. |
| [0.4.2](https://github.com/datagen24/victual/milestone/3) | Replace description and adjust assignments | 0.4.2 | Compatible contract stabilization only: bounded OpenAPI/documentation corrections for shipped behavior, supporting regression fixtures, operator permission guidance and dated release-record corrections. No runtime permission/input changes and no full API audit umbrella. | Leave unset | Replace Bug Hunt with an independently useful small patch outcome. |
| [0.4.3](https://github.com/datagen24/victual/milestone/4) | Replace description and adjust assignments | 0.4.3 | Conditional precision patch: lossless application/import float binding and persisted lookup-boundary verification. Preserve valid inputs, source values and rollback. Publish only after observable storage/output compatibility is reviewed. | Leave unset | Group prerequisite #951 with #890 and import #917. |
| [0.4.4](https://github.com/datagen24/victual/milestone/5) | Replace description and adjust assignments | 0.4.4 | Conditional label artifact lifecycle patch: new-render bytes survive reuse/collection, and visible state changes signal correctly. Preserve historical artifact retention and reprint refusals. Keep PRs independently reviewable. | Leave unset | Reduce the mixed eleven-issue bucket to one workflow. |
| [0.5.5](https://github.com/datagen24/victual/milestone/6) | Rename milestone and retain feature horizon | 0.6.0 | Later feature release: OAuth then federation; installation country, canonical/scoped barcode resolution and web onboarding, each behind its own design/ADR gates. Full API audit remains later work. These are not requirements for 0.5.0. | Leave unset | All five current assignments are feature/breaking architecture work and cannot ship in a patch milestone. |

## Shipped 0.4.1 compatibility conflicts

These are historical deviations and corrective documentation proposals. Moving an issue changes no bytes in a release branch or tag. No tag is to be rewritten. The original 0.4.1 release object exists, while its automatic image publication failed; hotfix1 has published digests.

| Shipped change | Observable effect | Policy assessment | Concrete disposition | Source |
| --- | --- | --- | --- | --- |
| MASTER_DATA_ADD and lookup creation (PR883–886) | New permission and create capability; no seeded role gains it. Chore/task create/edit move to CHORES/TASKS, removing rights from MASTER_DATA_EDIT-only accounts. | Feature and deliberate permission break; ADR-0043 was originally targeted to 0.5.0. | Record actual first shipment in 0.4.1, preserve original decision text, add a dated delivery correction and before/after grant checklist. Do not claim new delivery in 0.5.0. | [docs/releases/0.4.1.md#L13-L14](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1.md#L13-L14) |
| Read/write security gates (PR834/840/875/906/910/919/927) | Previously successful bookings/merges/reads now deny or redact for callers without additional rights. #919 does not close #920. | Security purpose does not establish patch compatibility; the changed permission/response boundary requires an explicit release deviation record. | Preserve tags; list each affected right/client path, existing decisions and any missing release exception. Carry unresolved bypass forward as a production disposition. | [docs/releases/0.4.1.md#L15-L20](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1.md#L15-L20) |
| Migrations 0309/0310/0312 and input refusals | Nonfinite/nonpositive factors, duplicate transaction-to-event links and chore consume flags outside 0/1 can block upgrade/import or refuse writes. | The presence of a migration is not the conflict. Narrowed data/input eligibility and refusal behavior need specific compatibility assessment; 0308/0311 also include feature/behavior changes. | Publish exact refusing preflight queries/reports and explicit repair or restore guidance. Rehearse populated upgrade and refused-state recovery. Retain nullability and no-silent-repair decisions. | [docs/releases/0.4.1.md#L21-L27](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1.md#L21-L27) |
| InfluxDB operator requeue (PR918) | New --requeue-dead operator command, dry-run and explicit execution selection. | New operator feature exceeds a compatible-fix-only patch scope even if related to a defect. | Record historical feature exception and safe operating guidance; do not relabel it as a future feature or extend to label requeue without a new decision. | [docs/releases/0.4.1.md#L28](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1.md#L28) |
| MCP category and retry advice (PR872/935) | New outcome_unknown/victual_unavailable categories and tool visibility changes affect machine-readable handling. | Behavioral client contract effects require coordination, even when preventing unsafe retries. | List categories/permissions in upgrade notes and confirm maintained consumers; #937 remains a separate selected change. | [docs/releases/0.4.1.md#L30](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1.md#L30) |
| OAS 3.2, anyOf unions, schema/key/type corrections | Generator input changes; removed never-sent track_count and corrected permission_id can require regenerated client source even without a server wire change. | Documentation corrections may be compatible fixes; generator/runtime effects must be assessed separately. OAS version migration is a feature/tooling compatibility concern. | Pin source and served documents, generator version and generated client tests; retain #891 adoption as incomplete. Do not claim structural validation establishes full API compliance. | [docs/releases/0.4.1.md#L33](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1.md#L33) |

## Consolidated issue and PR placement

| Issue or PR | Current milestone | Proposed milestone | Bundle | Classification | Reason | Dependency or release consequence | Confidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [#953 OpenAPI stock_label_type labels are off by one: the document says 1/2/3, the runtime uses 0/1/2](https://github.com/datagen24/victual/issues/953) | 0.4.2 | 0.4.2 | Contract accuracy | documentation | Recommended: Closed; included in shipped 0.4.2 documentation/API contract work. | Closed in 0.4.2; see published release record. Historical issue row retained for traceability. | high |
| [#952 Conversion chains can overflow in quantity_unit_conversions_resolved, refusing an accepted factor with a generic 400](https://github.com/datagen24/victual/issues/952) | 0.5.0 | 0.5.0 | Conversion integrity | investigation | Required: No compatible remedy is established. Clamping/skipping or new refusal semantics require a decision outside a patch. | #949 merged; its normal scalar/inverse rule does not cover chains. #951 is separate persisted precision work. | low |
| [#951 App write path stores floats at 14 significant digits (PDO precision), changing factors and breaking #890's boundary guarantee](https://github.com/datagen24/victual/issues/951) | 0.4.3 | 0.4.3 | Float preservation | compatible fix (conditional) | Recommended: Closed after implementation and boundary validation shipped in 0.4.3. | Closed in 0.4.3; see published release record. Historical issue row retained for traceability. | medium |
| [#950 Consume silently drops an unusable recipe_id; shopping-list remove accepts a negative product_id as a no-op](https://github.com/datagen24/victual/issues/950) | 0.5.0 | 0.5.0 | Numeric input contracts | breaking change (proposed) | Required: Closed 2026-10-17 via PR #976, PR #977. | #945 merged and characterizes behavior at 08f17796. #946 purification can affect input interpretation; #892 stays partial. | medium |
| [#948 Feature Request: Label Designer Quality of life enhancements](https://github.com/datagen24/victual/issues/948) | Unassigned | Unassigned | Deferred experience | feature | Retain: Optional designer features are unnecessary for the defined production release. | Existing label designer is implemented; no implementation or verification for these enhancements identified. | medium |
| [#947 Shopping list add/remove: invalid or negative product_amount is silently stored as 1, -2, or deletes the row](https://github.com/datagen24/victual/issues/947) | 0.5.0 | 0.5.0 | Numeric input contracts | breaking change (proposed) | Required: Closed 2026-10-17 via PR #976. Closed after every issue-table row was checked against master (NumericStringAcceptanceTest). | #945 provides characterizations; #946 raw-input boundary precedes final validation semantics. #892 depends on completion/disposition. | medium |
| [#946 Request purifier rewrites numeric fields before validation: "1\^@2" becomes 12 (incl. permission_id)](https://github.com/datagen24/victual/issues/946) | 0.5.0 | 0.5.0 | Numeric input contracts | breaking change (proposed) | Required: Closed 2026-10-17 via PR #976. | Independent HTTP correction is in issuecomment-6153664205; #945 is merged. Related #916 must be assessed without blanket string rejection. | medium |
| [#944 Label worker register: NUL bytes give an undocumented 400 in capability_document and are accepted in driver_id](https://github.com/datagen24/victual/issues/944) | 0.5.0 | 0.5.0 | Worker contract | investigation | Required: Closed 2026-10-18 via PR #983. The 0.5.0 runtime portion merged; the 0.4.2 documentation portion was already shipped. | #943 merged; #801 owns error-contract work. No independent stored-driver verification found. | low |
| [#942 Intermittent failure: ConsumptionEventFixtureTest fixture 11 (partial undo) step 5 stock 8.0 vs 10.0](https://github.com/datagen24/victual/issues/942) | 0.5.0 | 0.5.0 | Integrity investigations | investigation | Recommended: Closed 2026-10-17 via PR #975. | Recorded failure at 3e6f443b, run 38409918145; local and CI reruns passed. No root-cause evidence found. | low |
| [#937 MCP: a 2xx write answered without a transaction row is reported as victual_error, not outcome_unknown](https://github.com/datagen24/victual/issues/937) | 0.5.0 | 0.5.0 | MCP outcome contract | breaking change | Required: Closed 2026-10-18 via PR #982. | #872/#935 merged; current transaction.ts still pins victual_error for this distinct case. | high |
| [#936 OpenAPI: ChoreDetailsResponse.average_execution_frequency_hours is a number on the wire, documented as integer](https://github.com/datagen24/victual/issues/936) | 0.4.2 | 0.4.2 | Contract accuracy | documentation | Recommended: Closed; included in shipped 0.4.2 documentation/API contract work. | Closed in 0.4.2; see published release record. Historical issue row retained for traceability. | high |
| [#933 Shopping list pages give SHOPPINGLIST_VIEW-only callers product barcodes and stock shortfall (decide)](https://github.com/datagen24/victual/issues/933) | 0.5.0 | 0.5.0 | Authorization policy | investigation | Recommended: Neither permitting nor removing these stock facts has been decided; a restriction changes page/API output. | #927 merged; reported probes show residual barcodes/shortfall. #905 userfiles is a different disclosure. | medium |
| [#930 MergeProducts conversion check and merge can be separated by a concurrent conversion edit](https://github.com/datagen24/victual/issues/930) | 0.5.0 | 0.5.0 | Integrity investigations | investigation | Recommended: Sequencing recommendation, not a claim of incompatibility. A bounded lock fix may qualify for an earlier patch after reproduction. | #921/#955 merged and validated sequential preservation; #458 booking locks merged but do not establish conversion-writer serialization. | low |
| [#928 Outbox drain stops at a batch of only unreadable rows, leaving deliverable rows queued behind it](https://github.com/datagen24/victual/issues/928) | 0.5.0 | 0.5.0 | Outbox delivery | compatible fix (conditional) | Recommended: Closed 2026-10-18 via PR #981. | #918/#874 merged; requeue exists in shipped hotfix baseline. No fix for this residual found. | medium |
| [#926 Label artifact Store reuses collected bytes and races preview collection on shared files](https://github.com/datagen24/victual/issues/926) | 0.4.4 | 0.4.4 | Label artifact lifecycle | compatible fix (conditional) | Retain: Completed in merged PR #972; issue closed for 0.4.4. | PR #972 merged at c1add04dfcb4df403f5f49817361afb1f9943a1e; release and tag v0.4.4 published successfully. | medium |
| [#920 Security: removing a protected userfile's owning reference (clearing the value or deleting the owner) bypasses the #905 read gate](https://github.com/datagen24/victual/issues/920) | 0.5.0 | 0.5.0 | Confidential userfiles | breaking change (proposed) | Recommended: Closed 2026-10-17 via PR #978. | #919 merged/validated; UserfileOwnerReadTest currently asserts the bypass. F5 explicitly was not acceptance of it. | medium |
| [#917 Legacy import refuses large REAL values: floats bound at 14 significant digits, then compared](https://github.com/datagen24/victual/issues/917) | 0.4.3 | 0.4.3 | Float preservation | compatible fix (conditional) | Retain: Closed after implementation and boundary validation shipped in 0.4.3. | Closed in 0.4.3; see published release record. Historical issue row retained for traceability. | medium |
| [#916 Numeric columns accept the strings 'NaN' and 'Infinity', leaving rows the API cannot serialise](https://github.com/datagen24/victual/issues/916) | 0.5.0 | 0.5.0 | Numeric input contracts | breaking change (proposed) | Required: Closed 2026-10-18 via PR #979. | #911 fixed float plugin output but not these strings. #912 error encoding is separate and does not repair stored data. | medium |
| [#913 Manual: roles-permissions page omits the ADR-0035 view leaves (BATTERIES_VIEW, CALENDAR_VIEW, EQUIPMENT_VIEW)](https://github.com/datagen24/victual/issues/913) | 0.4.2 | 0.4.2 | Operator contract | documentation | Recommended: Closed; included in shipped 0.4.2 documentation/API contract work. | Closed in 0.4.2; see published release record. Historical issue row retained for traceability. | high |
| [#912 Decide whether ApiResponse encodes with JSON_THROW_ON_ERROR (from #844)](https://github.com/datagen24/victual/issues/912) | Unassigned | Unassigned | Deferred response design | investigation | Retain: JSON_THROW_ON_ERROR changes failure bodies; do not fold it into a patch or assume it solves post-commit uncertainty. | #911 merged one prevention path; #801 must document actual production 500 behavior independently. | low |
| [#909 Battery and equipment rework: residual battery authorization findings](https://github.com/datagen24/victual/issues/909) | Unassigned | Unassigned | Deferred battery rework | feature / breaking change | Retain: Recorded maintainer deferral under ADR-0043 stands; #897 closed not_planned does not mean these findings were fixed. | #897 closed by decision B; ADR-0043 preserves battery writes. No rework implementation found. | low |
| [#908 Battery charge-cycle write returns the new row to a caller without BATTERIES_VIEW](https://github.com/datagen24/victual/issues/908) | 0.5.0 | 0.5.0 | Authorization policy | breaking change (proposed) | Required: Adding BATTERIES_VIEW or reducing response fields changes permissions/output; patch placement is not justified. | ADR-0035 accepted; static BatteriesApiController check supports finding. No runtime probe in source issue. | medium |
| [#905 userfiles are readable by any authenticated caller; they should follow the owning record's read permission](https://github.com/datagen24/victual/issues/905) | 0.5.0 | 0.5.0 | Confidential userfiles | release gate | Required: Closed 2026-10-17 via PR #919, PR #978. Closed with #919 (read rule) and #978 (write rule). | #919 merged a7d9eb2e, validated 6f9b2966; #941 closed. #920 open. ADR-0048 remains Proposed. | medium |
| [#904 Subprocess test helpers read stdout before stderr and deadlock on more than 64 KB of stderr](https://github.com/datagen24/victual/issues/904) | Unassigned | Unassigned | Validation reliability | compatible fix (conditional) | Recommended: Part 1 shipped. Remaining broad helper migration is not necessary supporting work for a precision-only patch. | #907 merged four hang sites. Part 2 remains local-only in historical ledger; no open PR or recorded complete phase counts. | low |
| [#900 ADR-0019 does not list the Resolve() validation refusal as a dead-letter cause](https://github.com/datagen24/victual/issues/900) | Unassigned | Unassigned | Label lifecycle governance | documentation / investigation | Retain: Split factual ADR-0049 part 1 from undecided lifecycle part 2. Neither is silently accepted or a new patch feature. | #932 Proposed record and #881 runtime merged. ADR-0049 remains Proposed; acceptance alone does not close #900. | low |
| [#898 Render claim changes visible state without signalling a data change](https://github.com/datagen24/victual/issues/898) | 0.4.4 | 0.4.4 | Label artifact lifecycle | compatible fix (conditional) | Recommended: Completed in merged PR #973; issue closed for 0.4.4. | PR #973 merged at 79945292d3ff29e177f7639ca7588a67d933e20b; release and tag v0.4.4 published successfully. | medium |
| [#893 Extend the recorded-key contract check beyond the stock routes](https://github.com/datagen24/victual/issues/893) | 0.4.2 | 0.4.2 | Contract accuracy | compatible fix (tests/documentation) | Retain: Closed; included in shipped 0.4.2 documentation/API contract work. | Closed in 0.4.2; see published release record. Historical issue row retained for traceability. | medium |
| [#892 Request fields typed number/integer accept numeric strings at runtime](https://github.com/datagen24/victual/issues/892) | 0.5.0 | 0.5.0 | Numeric input contracts | release gate | Required: Closed 2026-10-17 via PR #976, PR #977. Umbrella closed: O1 option A complete and all residuals closed. | #945 merged cb442afa and validated 08f17796; #946/#947/#950 open. Ordinary-space correction is settled. | medium |
| [#891 victual-kit and the client handoff: adopt the anyOf entity unions and x-victual-entity-schemas](https://github.com/datagen24/victual/issues/891) | 0.5.0 | 0.5.0 | Maintained client adoption | release gate | Recommended: Coordinate adopted contract with 0.5.0; a server handoff alone is incomplete. | #828/#934 merged server changes. No client adoption completion evidence found; maintainer O4 requires keeping this open. | medium |
| [#890 Decide whether the lookup's purchase-to-stock factor needs a range limit](https://github.com/datagen24/victual/issues/890) | 0.4.3 | 0.4.3 | Float preservation | release gate | Recommended: Closed after implementation and boundary validation shipped in 0.4.3. | Closed in 0.4.3; see published release record. Historical issue row retained for traceability. | medium |
| [#801 OpenAPI: document known authentication, validation and server-error outcomes](https://github.com/datagen24/victual/issues/801) | 0.4.2 | 0.4.2 | Contract accuracy | documentation | Retain: Closed; included in shipped 0.4.2 documentation/API contract work. | Closed in 0.4.2; see published release record. Historical issue row retained for traceability. | medium |
| [#796 Audit and bring the REST API specification into full OpenAPI 3.2 compliance](https://github.com/datagen24/victual/issues/796) | 0.6.0 | 0.6.0 (rename 0.5.5) | Deferred full API audit | investigation | Recommended: Move broad umbrella out of a small patch; Plan 22 explicitly excludes whole-audit completion as a 0.5.0 gate. | OAS 3.2 gate #818 and many corrections merged; #801/#891/#892/#893 remain. External scan detail is missing. | low |
| [#775 Plan 22: Stock bookings have no idempotency key, so a timed-out write has an unknown outcome](https://github.com/datagen24/victual/issues/775) | 0.5.0 | 0.5.0 | Booking idempotency | feature | Retain: Closed 2026-10-18 via PR #980. | #785/#834 merged up-front permission check. ADR-0046 Proposed; key storage/replay feature unbuilt. | medium |
| [#774 Unpriced stock is reported as value 0, so a client cannot tell free from unknown](https://github.com/datagen24/victual/issues/774) | 0.5.0 | 0.5.0 | Price semantics | breaking change / feature | Required: Explicit Plan 22 0.5.0 requirement; wire meaning, imports and clients change. Issue body's null-total proposal is superseded by ADR decisions. | ADR-0045 Proposed with questions 1–13 answered where applicable; evidence/prerequisites and implementation remain, not those decisions. | medium |
| [#771 Design: external identity provider (OIDC and SAML) with Authentik as the reference IdP](https://github.com/datagen24/victual/issues/771) | 0.6.0 | 0.6.0 (rename 0.5.5) | Future identity | feature | Required: Federation is new architecture; retain later ordering by renaming the feature milestone to a minor release. | #770 not delivered; accepted auth-state/subset rules bind design. No federation ADR accepted. | low |
| [#770 Design: OAuth 2.1 access tokens for API and MCP, replacing static API keys as the primary credential](https://github.com/datagen24/victual/issues/770) | 0.6.0 | 0.6.0 (rename 0.5.5) | Future identity | feature | Required: OAuth is a feature and deployment/client change; 0.5.5 cannot carry it under patch policy. | #773 spike merged, not production OAuth. ADR-0044 Proposed scopes are related, not accepted implementation. | low |
| [#762 Users: roles can't be set when creating a user, and the only entry point is an unlabeled lock icon](https://github.com/datagen24/victual/issues/762) | Unassigned | Unassigned | Deferred experience | feature | Retain: Explicitly deferred by Plan 22; current API supports role updates. | Existing roles API implemented; no verified new UI. No prerequisite for #703. | medium |
| [#742 Plan 22: creating a product or conversion needs the whole-household MASTER_DATA_EDIT right; audit for similar gaps](https://github.com/datagen24/victual/issues/742) | 0.5.0 | 0.5.0 | Master-data permission | feature / release gate | Retain: ADR-0043 slice already shipped; remaining conversion setup restriction is an explicit limitation, not implicit unfinished 0.5.0 feature scope. | #883–#886 implemented/merged; #887 accepted ADR-0043. Recorded merged coverage 96.44%. Broader issue intentionally remains open. | high |
| [#737 Consumption recipe sharing: answer a share request identically whether or not the user exists](https://github.com/datagen24/victual/issues/737) | Unassigned | Unassigned | Deferred privacy | breaking change | Retain: Maintainer explicitly accepted the oracle for 0.5.0 and wants correction afterwards; do not re-ask. | ADR-0040 implemented; #737 body records 2026-10-09 acceptance. No fix claimed. | high |
| [#703 Plan 22: verify delivery and prepare v0.5.0 release evidence](https://github.com/datagen24/victual/issues/703) | 0.5.0 | 0.5.0 | Production verification | release gate | Retain: Existing mandatory integrated gate; milestone is not permission to tag an unverified commit. | 698–700 closed; 701 merged and 702 accepted simulator evidence; 774/775 unbuilt; no integrated 0.5.0 completion found. | medium |
| [#702 Plan 22: coordinate victual-kit HealthKit integration and native acceptance](https://github.com/datagen24/victual/issues/702) | 0.5.0 | 0.5.0 | Maintained client adoption | release gate | Retain: Acceptance is already decided. Repair traceability and verify new 0.5.0 contract adoption, without demanding physical-device evidence. | Plan 22/PR777 accept simulator evidence; 28/28 server replays reported. No real-device/Health-store behavior observed. | medium |
| [#701 Plan 22: implement refill history, reorder estimates and notices](https://github.com/datagen24/victual/issues/701) | 0.5.0 | 0.5.0 | Plan 22 refill | feature / release gate | Retain: Implementation already shipped in 0.4.0; open checkboxes require reconciliation, not redispatch. | #748/#749/#751 merged; #755 authorization-race fix merged. Wording/deviations accepted in issuecomment-6099923433. | high |
| [#655 Implement Home Assistant add-on version upgrades independently of Grocy import](https://github.com/datagen24/victual/issues/655) | Unassigned | Unassigned | Deferred Home Assistant target | feature | Retain: Required only for the new HA deployment target, not existing Helm production scope. | #654 unbuilt; Plan35 draft. Existing Kubernetes migration path does not prove Supervisor credential isolation. | low |
| [#654 Feature Request: Target as Home Assistant Addon](https://github.com/datagen24/victual/issues/654) | Unassigned | Unassigned | Deferred Home Assistant target | feature | Retain: Explicit Plan 22 deferral; MQTT integration is distinct from this new deployment target. | Plan35 research only; #655 unbuilt; no supported add-on artifacts verified. | low |
| [#486 Installation country setting (environment variable and onboarding)](https://github.com/datagen24/victual/issues/486) | 0.6.0 | 0.6.0 (rename 0.5.5) | Future onboarding/barcodes | feature | Required: New configuration behavior belongs to a minor release; keep prerequisite before #483's default-pattern integration. | #483 uses country; #484 may host UI; both unbuilt. #80 still needs sampling. | medium |
| [#484 Planning: web setup flow — upload a grocy database and bring the app up](https://github.com/datagen24/victual/issues/484) | 0.6.0 | 0.6.0 (rename 0.5.5) | Future onboarding/barcodes | feature | Required: New workflow and import maintenance state are feature work, not a patch. | #483 should ship its own conflicts page first but does not require wizard. #486 country integration is related. | low |
| [#483 Planning: canonical, symbology-tagged barcode storage and one inbound scan resolver](https://github.com/datagen24/victual/issues/483) | 0.6.0 | 0.6.0 (rename 0.5.5) | Future onboarding/barcodes | breaking change / feature | Required: Changes identity, accepted inputs and response behavior; 0.5.5 violates patch policy. Explicitly deferred from 0.5.0. | #481 closed as duplicate of this work. ADR-0031 Proposed; #486 feeds default patterns; own conflicts UI precedes #484 integration. | low |
| [#252 Code that coverage proved nothing can reach](https://github.com/datagen24/victual/issues/252) | Unassigned | Unassigned | Deferred cleanup | investigation | Retain: No production need to remove it; do not widen release scope into a cleanup sweep. | Coverage issue #192 closed; this is residual cleanup, not a coverage-floor blocker. | medium |
| [#139 Plan 18 — run the Home Assistant verifications (2, the HA half of 4, and 8)](https://github.com/datagen24/victual/issues/139) | Unassigned | Unassigned | Deferred HA verification | release gate (conditional) | Retain: Maintainer excluded unverified Home Assistant paths from the 0.5.0 production support claim (D2). Keep later and disclose the limitation. | Plan18 broker evidence exists; actual HA restart/rollover/week checks are unverified. Distinct from #654. | low |
| [#133 Plan 20 — pieces 2, 4, 5 and the credential split (piece 4 is also #93's last gate)](https://github.com/datagen24/victual/issues/133) | Unassigned | Needs maintainer placement | Deployment verification | release gate (conditional) | Required: Maintainer selected cluster label and shutdown evidence for the 0.5.0 production claim (D2). Actual GitHub milestone remains Unassigned pending an approved metadata batch. | Credential split and older Talos/Helm operation recorded; cluster signal/printer residuals open; overlaps #93, do not duplicate execution. | low |
| [#93 Verify K3S operation (label worker, SIGTERM on a cluster)](https://github.com/datagen24/victual/issues/93) | Unassigned | Needs maintainer placement | Deployment verification | release gate (conditional) | Required: Maintainer selected cluster label and shutdown evidence for the 0.5.0 production claim (D2). Actual GitHub milestone remains Unassigned pending an approved metadata batch. | ADR-0019 accepted and label machinery built, contrary to stale issue body. Native #79 evidence accepted; K3S path still unverified. | low |
| [#86 MCP sidecar: deployment, Inspector, real-client checks and two-replica soak](https://github.com/datagen24/victual/issues/86) | Unassigned | Unassigned | Deferred MCP verification | release gate (conditional) | Retain: Maintainer excluded unverified MCP deployment/client paths from the 0.5.0 production support claim (D2). Core MCP code checks remain in CI. | Read-only sidecar and historical two-pod checks implemented; current real-client/soak completion not established. | low |
| [#80 Wave 3b (deferred) — Plan 09: US barcode lookup sources](https://github.com/datagen24/victual/issues/80) | Unassigned | Unassigned | Deferred lookup sources | feature | Retain: Explicitly deferred by Plan 22; optional coverage expansion is not required production functionality. | S14 work shipped; sampling and plan09 questions remain. #486 country defaults are related future work. | low |
| [PR #919 fix: a userfile is readable only by callers every owning record permits (part of #905; #941)](https://github.com/datagen24/victual/pull/919) | Unassigned | Shipped in 0.4.1 | Confidential userfiles | shipped fix with compatibility effects | Retain: Merge and validated head confirmed; no implementation dispatch. | Closed #941, not #905; #920 remains. | high |
| [PR #921 fix: refuse a product merge when an own-unit row would convert differently (#901)](https://github.com/datagen24/victual/pull/921) | Unassigned | Shipped in 0.4.1 | Conversion integrity | shipped fix with compatibility effects | Retain: Merge and validated head confirmed; no implementation dispatch. | Closed #901/#929; #955 completes stock-factor protection; #930 remains. | high |
| [PR #945 docs(openapi): document the numeric-string grammar of stock and user-permission request fields](https://github.com/datagen24/victual/pull/945) | Unassigned | Shipped in 0.4.1 | Numeric input contracts | documentation | Retain: Merge and validated head confirmed; no implementation dispatch. | Part of #892 only; #946/#947/#950 remain. | high |
| [PR #955 fix: refuse a merge whose stock-unit conversion differs between the products, and allow equivalent units (#901 follow-up)](https://github.com/datagen24/victual/pull/955) | Unassigned | Shipped in 0.4.1 | Conversion integrity | shipped fix with compatibility effects | Retain: Merge and validated head confirmed; no implementation dispatch. | Original #901 sequential defect addressed; do not redispatch. #930 concurrency remains. | high |
| [PR #961 Correct shipped 0.4.1 release evidence](https://github.com/datagen24/victual/pull/961) | 0.4.2 | 0.4.2 | Release evidence | documentation | Retain: Implementation authorized after planning; verified change submitted for review. | No implementation prerequisite | high |
| [PR #962 Document all domain view permissions](https://github.com/datagen24/victual/pull/962) | 0.4.2 | 0.4.2 | Operator permissions | documentation | Retain: Implementation authorized after planning; verified change submitted for review. | Stacked on #961; guide diff independently reviewable | high |
| [PR #963 Align API contract with existing responses](https://github.com/datagen24/victual/pull/963) | 0.4.2 | 0.4.2 | API contract | documentation | Retain: Implementation authorized after planning; verified change submitted for review. | Stacked on #962; final master verification remains required | high |
| [PR #964 Prepare 0.4.2 release candidate](https://github.com/datagen24/victual/pull/964) | 0.4.2 | 0.4.2 | Release evidence | documentation | Retain: Implementation authorized after planning; verified change submitted for review. | All three CodeRabbit review threads resolved. Nix startup race fixed with negative-control test; fresh integrated CI pending. | high |
| [#987 Consume stores any integer recipe_id, including negative ids and ids no recipe has](https://github.com/datagen24/victual/issues/987) | 0.5.1 | 0.5.1 | Numeric boundaries | bug | Recommended: Found closing #950; #950 explicitly excluded the negative case. | #950, PR #976, PR #977 | high |
| [#988 prose-baseline-prune fails daily: it pushes to master, which requires a pull request](https://github.com/datagen24/victual/issues/988) | 0.5.1 | 0.5.1 | CI hygiene | ci | Recommended: Every scheduled run since 2026-10-15 fails at the push step. | Repository rule requiring PRs on master | high |
| [#989 Calendar API 500 includes the user-supplied event title in its body](https://github.com/datagen24/victual/issues/989) | 0.5.1 | 0.5.1 | Disclosure | security | Recommended: #801 decision D4 deferred the runtime disclosure assessment. | #801 D4, #511 | high |
| [#990 Legacy-zero prices still read as 0 on the stock entries page, edit form, meal plan cost and Influx](https://github.com/datagen24/victual/issues/990) | 0.5.0 | 0.5.0 | Price semantics | bug | Recommended: PR #985 lists these surfaces as deliberately unchanged. | #774, PR #984, PR #985, ADR-0045 | high |
| [#991 Uploading a userfile under an existing unreferenced name reveals that the name exists](https://github.com/datagen24/victual/issues/991) | 0.5.1 | 0.5.1 | Userfile authorization | security | Recommended: ADR-0048 item 9 records it as not covered. | #905, #920, PR #978, ADR-0048 | high |
| [#1007 UI: group medication features in a Medication flyout and clarify labels](https://github.com/datagen24/victual/issues/1007) | Unassigned | Unassigned | Not assessed | enhancement | Retain: Opened after the 0.5.0 tag; not assessed in this refresh. | Not assessed. | not assessed |

## 0.5.0 production checklist at the tag

M1–M9 are existing mandatory requirements. The maintainer adopted R1–R6 as additional 0.5.0 gates. Status is as of the signed v0.5.0 tag; a gate marked not evidenced means 0.5.0 makes no claim it would have supported. No issue-count percentage represents production readiness.

### Existing mandatory

| Gate | Status | Owner | Evidence | Remaining work | Release consequence | Source |
| --- | --- | --- | --- | --- | --- | --- |
| M1: Complete the agreed Plan 22 boundary | Met at v0.5.0 | Release coordinator + domain maintainers | Plan 22 landed. #701, #702 and #742 were reconciled and closed on 2026-10-19 after the native matrix was re-pinned (PR #1003, victual-kit PR 44); #703 closed with the release record. #774/#775 closed. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | None for 0.5.0. | No 0.5.0 tag while required outcomes or their evidence are missing. | [docs/plans/22-medication-tracking.md#L9-L39](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/plans/22-medication-tracking.md#L9-L39), [#703](https://github.com/datagen24/victual/issues/703) |
| M2: Price semantics and ADR-0045 acceptance prerequisites | Met at v0.5.0 | Database/API maintainer + maintained-client owner | PRs #984/#985 (#774) and #996/#997 (#990) merged; ADR-0045 accepted in PR #992. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | None for 0.5.0. Client adoption is tracked in victual-kit (#891 closed not planned). | Mandatory feature and semantic change; keep out of patch milestones. No production claim without client/import correctness. | [docs/adr/0045-an-unknown-stock-value-is-distinguishable-from-a-known-zero.md#L904-L935](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0045-an-unknown-stock-value-is-distinguishable-from-a-known-zero.md#L904-L935), [docs/plans/22-medication-tracking.md#L21-L39](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/plans/22-medication-tracking.md#L21-L39), [#774](https://github.com/datagen24/victual/issues/774) |
| M3: Booking idempotency and ADR-0046 prerequisites | Met at v0.5.0 | Stock API maintainer + validator | PR #980 (Idempotency-Key) and PR #982 (outcome_unknown) shipped; ADR-0046 accepted in PR #993. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | None for 0.5.0. | Mandatory 0.5.0 feature. Server-side evidence can satisfy client-obligation prerequisite; do not invent a requirement for one particular native integration. | [docs/adr/0046-stock-bookings-accept-an-optional-idempotency-key.md#L611-L633](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0046-stock-bookings-accept-an-optional-idempotency-key.md#L611-L633), [#775](https://github.com/datagen24/victual/issues/775) |
| M4: Plan 22 integrated workflows and accepted medication constraints | Met at v0.5.0 with recorded limits | QA validator + native-client maintainer | Plan 22 acceptance (698–702, 742, 774, 775, 782, 783, 785, 787, 788) closed with evidence; see plan 22 Executed. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | None for 0.5.0. Apple Health acceptance is simulator-only; conversions for non-stock-unit mappings need MASTER_DATA_EDIT (ADR-0043). | Required integrated workflow evidence is absent; merged components alone do not pass. | [docs/plans/22-medication-tracking.md#L254-L273](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/plans/22-medication-tracking.md#L254-L273), [docs/adr/0015-medication-records-never-advises.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0015-medication-records-never-advises.md), [docs/adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md), [docs/adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md), [docs/adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md) |
| M5: Supported database, migration, import and coverage checks | Met at v0.5.0 | QA/database maintainers | tests workflow on 2b350b1d: suite with coverage on PostgreSQL 16, suite-floor on PostgreSQL 15, frontend-security, images, mcp, lint; Psalm, prose, CodeQL and nix passed. The merged tree differs only in the release record. Validated SHA: 2b350b1d | None for 0.5.0. | Final required checks must pass. Current baseline success does not resolve #942 or validate future code. | [.github/workflows/tests.yml#L182](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/.github/workflows/tests.yml#L182), [.github/workflows/tests.yml#L440](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/.github/workflows/tests.yml#L440), [.github/workflows/tests.yml#L476](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/.github/workflows/tests.yml#L476), [docs/constitution.md#L38](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/constitution.md#L38), [docs/adr/0025-three-test-tiers.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0025-three-test-tiers.md), [AGENTS.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/AGENTS.md) |
| M6: Upgrade from the actual preceding release and explain refusal/recovery | Met at v0.5.0 with recorded limits | Release operator + database maintainer | Populated v0.4.4 → 0.5.0 rehearsal on PostgreSQL 16.15 / PHP 8.5.10: 312 → 318, rerun byte-identical; a NaN database refused by 0313 and left byte-identical at 312. Validated SHA: 17f9f000 | Rehearsal used the dev shell, not the production images, and PostgreSQL 16 only; PostgreSQL 15 is covered by the tests migration phase. Not a restore test (see R5). | Candidate-specific upgrade evidence must start from v0.4.4 and include refusal/recovery guidance. | [docs/plans/22-medication-tracking.md#L272](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/plans/22-medication-tracking.md#L272), [docs/manual/operator/updating-migrations.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/manual/operator/updating-migrations.md), [docs/manual/operator/backup-restore.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/manual/operator/backup-restore.md), [docs/releases/0.4.1.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1.md) |
| M7: Accurate depended-on API contracts and maintained-client adoption | Server side met; client adoption moved to victual-kit | API reviewer + victual-kit maintainer | Spec and contract checks passed on the release PR. #891 closed not planned on 2026-10-19: victual-kit follows released server versions and adopts the 0.5.0 contract after the tag. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | Client adoption evidence lives in victual-kit, not this repository. | Required contract/client evidence blocks release; full #796 audit, third-party Grocy clients and real-device #702 testing are not new gates. | [docs/adr/0005-wire-contract-is-the-invariant.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0005-wire-contract-is-the-invariant.md), [docs/adr/0024-the-fork-writes-its-own-clients.md#L68-L79](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0024-the-fork-writes-its-own-clients.md#L68-L79), [docs/plans/22-medication-tracking.md#L36-L39](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/plans/22-medication-tracking.md#L36-L39), [#891](https://github.com/datagen24/victual/issues/891) |
| M8: Production images, Helm and declared workload constraints | Met at v0.5.0 | Release operator | release.yml run 39250766266 built, booted and walked amd64 and arm64 images on the signed tag and published six images and the chart; digests are in the release record. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | Cluster label and shutdown claims are R6. | Build/publish evidence is necessary but not live-deployment evidence. Keep proposed ADR-0030/0047 distinct from current procedural requirements. | [docs/adr/0010-workload-standard.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0010-workload-standard.md), [docs/adr/0013-nix-built-container-images.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0013-nix-built-container-images.md), [docs/adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md), [docs/releases/README.md#L9-L47](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/README.md#L9-L47), [deploy/README.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/deploy/README.md) |
| M9: Completed release record, operator guidance, decisions and signed tag | Met at v0.5.0 | Maintainer + release coordinator | Release record docs/releases/0.5.0.md (PR #1004); signed v0.5.0 targets 555475ae; publication recorded in PR #1005. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | None. | No rewriting tags. Do not treat a successful publication workflow as a substitute for all required checks. | [docs/releases/README.md#L9-L37](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/README.md#L9-L37), [docs/releases/0.4.1-hotfix1.md#L3](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1-hotfix1.md#L3), [docs/adr/README.md#L30-L47](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/adr/README.md#L30-L47) |

### Recommended additional

| Gate | Status | Owner | Evidence | Remaining work | Release consequence | Source |
| --- | --- | --- | --- | --- | --- | --- |
| R1: Close confidential-userfile write bypass or restrict affected production use | Met at v0.5.0 | Maintainer + security reviewer | PRs #919/#978 shipped; ADR-0048 accepted in PR #994; regressions ran in the release PR suite. Validated SHA: 2b350b1d | None for 0.5.0. #991 (name-existence signal) is in 0.5.1. | Recommend blocking production confidential-file use until resolved. This is a recommendation for release disposition, not a claim every security issue is an existing gate. | [#905](https://github.com/datagen24/victual/issues/905), [#920](https://github.com/datagen24/victual/issues/920), [tests/Pgsql/UserfileOwnerReadTest.php#L899-L913](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/tests/Pgsql/UserfileOwnerReadTest.php#L899-L913), [evidence/astra-compatibility-review.md](evidence/astra-compatibility-review.md) |
| R2: Prevent persistent unreadable numeric rows | Met at v0.5.0 | API/database maintainer | PRs #976/#979 shipped; the upgrade rehearsal showed the 0313 refusal leaving the database byte-identical. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | None for 0.5.0. #912 stays separate. | Recommend resolution before storing production data; no silent repair or broad unreviewed numeric-string rejection. | [#916](https://github.com/datagen24/victual/issues/916), [evidence/astra-compatibility-review.md](evidence/astra-compatibility-review.md) |
| R3: Disposition conversion concurrency and partial-undo uncertainty | Met at v0.5.0 | Stock/concurrency validator | PR #998 (#930) takes a SHARE lock on quantity_unit_conversions during a merge; two-connection tests fail without it. #942 closed by PR #975. Validated SHA: 555475aeec4ce2840671ab5623460ebf9f8440b3 | Not covered: a concurrent qu_id_stock edit on the products row. | Recommend disposition before claiming production stock integrity; a green rerun does not suffice. Compatible fixes remain eligible for patches if independently bounded. | [#930](https://github.com/datagen24/victual/issues/930), [#942](https://github.com/datagen24/victual/issues/942), [evidence/astra-compatibility-review.md](evidence/astra-compatibility-review.md) |
| R4: Verify persisted factor normality and lossless import | Met at v0.5.0 | Database maintainer | Boundary and import regressions ran in the release PR suite; PR #1001 (#952) adds the normal-range refusal. Validated SHA: 2b350b1d | None for 0.5.0. | Do not redispatch completed precision implementation. Final candidate regression evidence remains required. | [#890](https://github.com/datagen24/victual/issues/890), [#951](https://github.com/datagen24/victual/issues/951), [#917](https://github.com/datagen24/victual/issues/917), [evidence/astra-compatibility-review.md](evidence/astra-compatibility-review.md) |
| R5: Disposable backup/restore drill tied to supported deployment | Not evidenced at v0.5.0 | Release operator | The release record states the upgrade rehearsal is not a backup-restore test. No restore report exists for 0.5.0. Validated SHA: No final candidate evidence | Run the restore drill against the 0.5.0 artifacts, or record a narrow restriction. Not assigned to a milestone. | Recommend a separate release acceptance check. It demonstrates recovery, which successful forward migration alone cannot establish. | [docs/manual/operator/backup-restore.md#L1-L48](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/manual/operator/backup-restore.md#L1-L48), [docs/manual/operator/updating-migrations.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/manual/operator/updating-migrations.md) |
| R6: Production support claim for cluster labels and shutdown | Not evidenced at v0.5.0 | Maintainer + release operator | The release record has no cluster label-delivery or measured-shutdown demonstration; #93 and #133 are open and unassigned. Validated SHA: No final candidate evidence | Run the pinned-artifact Helm/cluster demonstration, or keep the claim out. 0.5.0 makes no cluster label or shutdown support claim. | 0.5.0 cannot claim the selected core cluster deployment path until this evidence exists. | [#93](https://github.com/datagen24/victual/issues/93), [#133](https://github.com/datagen24/victual/issues/133), [evidence/luna-production-review.md](evidence/luna-production-review.md) |

### Improvements and evidence that can follow production

| Items | Disposition |
| --- | --- |
| [#737](https://github.com/datagen24/victual/issues/737), [#909](https://github.com/datagen24/victual/issues/909) | Explicit accepted deferrals: share-user oracle and battery/equipment rework. Preserve recorded residuals. |
| [#80](https://github.com/datagen24/victual/issues/80), [#86](https://github.com/datagen24/victual/issues/86), [#654](https://github.com/datagen24/victual/issues/654), [#655](https://github.com/datagen24/victual/issues/655), [#762](https://github.com/datagen24/victual/issues/762), [#483](https://github.com/datagen24/victual/issues/483), [#484](https://github.com/datagen24/victual/issues/484), [#486](https://github.com/datagen24/victual/issues/486), [#770](https://github.com/datagen24/victual/issues/770), [#771](https://github.com/datagen24/victual/issues/771), [#948](https://github.com/datagen24/victual/issues/948), [#252](https://github.com/datagen24/victual/issues/252) | Optional lookup/client soak/new deployment/onboarding/identity/designer/cleanup work; no automatic 0.5.0 dependency. |
| [#796](https://github.com/datagen24/victual/issues/796) | Full API audit is not a blanket gate; required changed/depended-on contract checks remain M7. |
| [#139](https://github.com/datagen24/victual/issues/139) | HA restart/rollover/idle-week evidence applies to specific integration claims; disclose as unverified unless maintainer includes it. |
| [#900](https://github.com/datagen24/victual/issues/900), [#912](https://github.com/datagen24/victual/issues/912) | Unselected label lifecycle/recovery and JSON encoding design remain separate decisions, not covert patch changes. |

## Critical dependency sequence

1. Freeze scope and resolve consequential release choices. Approve milestone batch; decide owner writes, numeric/permission contracts and supported deployment claims. Preserve already answered price/idempotency/simulator decisions. Owner: Maintainer. Gates: M1, R1, R2, R6.

2. Repair bounded prerequisite defects and investigate integrity. Precision #951/#917→#890; #920 confidentiality fix after D3; #930/#942 root-cause/disposition. Reuse historical work and independently verify new heads. Owner: Domain implementers + independent validators. Gates: R1, R2, R3, R4.

3. Deliver mandatory price and idempotency contracts. #774/ADR-0045 and #775/ADR-0046: evidence each prerequisite, coordinated contract/client work, then separate lifecycle acceptance PRs. Numeric changes affecting fingerprints or booking responses settle first. Owner: API/database/client maintainers. Gates: M2, M3, M7.

4. Integrate native, operator and workflow evidence. Reconcile #701/#702/#742; verify #891 and new price semantics. Complete #703 workflow evidence with accepted simulator scope and explicit limitations. Owner: QA/client/release maintainers. Gates: M1, M4, M7.

5. Validate candidate and recovery on supported paths. Exact RC SHA and digests: PG15/16, tests/coverage, served spec, upgrade from the most recently shipped predecessor, backup restore, images/Helm and selected deployment checks. Owner: QA + release operator. Gates: M5, M6, M8, R5, R6.

6. Prepare record, verify merge, then sign and publish. Release notes name deliberate breaks, guidance and limitations. All required checks must match the release-record merge. Maintainer tags; record published digests afterward. Proposed ADR-0047 pipeline redesign is not implicitly adopted. Owner: Maintainer. Gates: M9.

## Smallest useful first implementation batch

The 0.5.0 issue lane is broad: 23 direct rows among 43 open issues. Nine rows are explicitly candidates or deferred and six are recommended dispositions, not automatic release gates. First confirm which of those 15 remain necessary; then order retained gates and prepare one bounded implementation brief. No GitHub changes or implementation dispatch are part of this dashboard refresh.

| Order | Unit | Scope | Owner / size | Prerequisites | Validation | Release |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Confirm the finite 0.5.0 issue scope | Keep the 43-issue inventory separate from the release gates. Review the 9 candidate/deferred rows and 6 recommended dispositions currently placed in the proposed 0.5.0 lane; retain only items required by accepted policy or an explicit production-support decision. | Maintainer + release coordinator / S / S | Current full GitHub snapshot at master 631c6b27cf0d743b6068b5f503940ff9dc97e496; D1–D7 recorded. | Record each retain/defer decision in the shared structured source, preserving actual GitHub assignments separately. | 0.5.0 planning |
| 2 | Order the approved gates and dependencies | For each retained gate, confirm prerequisite implementation, merge and verification state; keep ADR acceptance lifecycle PRs separate. | Release coordinator / S / S | Finite 0.5.0 scope; resolve D5a undo rule only if battery undo is selected. | Dependency graph has no prerequisite scheduled after its dependent; every gate names owner, candidate evidence and release consequence. | 0.5.0 planning |
| 3 | Prepare the smallest approved implementation brief | Select one independently useful unit from the critical path and specify observable behavior, compatibility boundaries, tests, evidence and independent review. | Maintainer + release coordinator / S / S | Scope and dependency approval; named implementation base SHA. | Approved bounded brief; do not dispatch implementation until the unit and validation are explicit. | 0.5.0 |

## Decision batch and application status

The historical decisions are preserved verbatim in [DECISIONS.md snapshot](evidence/historical-DECISIONS.md). Price-zero interpretation, option B, idempotency retention/discovery, simulator acceptance, conversion-preservation policy, numeric-string option A and the userfile read/view-key decisions are not asked again.

| ID / question | Factual question | Recorded decision | Status | Release effect | Needed for |
| --- | --- | --- | --- | --- | --- |
| D1: Approve one milestone adjustment batch | May the actual baseline be changed to the proposed issue table and milestone descriptions? | Keep the applied milestone batch, including the 0.6.0 destination. Leave all milestone dates unset. | Recorded; applied batch retained | No milestone-date changes. The existing applied batch and 0.6.0 rename stay in force. | Planning approval |
| D2: Define the production deployment claim | Does 0.5.0 require remaining cluster label/shutdown and Home Assistant evidence? | Require the core Helm deployment path plus cluster label and shutdown evidence for the 0.5.0 production claim. Exclude unverified Home Assistant and MCP paths from that claim. | Resolved by maintainer | Propose #93 and #133 for 0.5.0; keep actual GitHub assignments separate until the metadata batch is applied. Keep #139 and #86 deferred. | Production support statement |
| D3: Owner-based file write authorization | Which current-owner checks must accompany each reference/owner/byte mutation that can widen file access? | Require existing operation rights and read authorization for every current owner, checked atomically with the mutation. | Resolved by maintainer | Owner/reference/byte mutations must preserve authorization across all current owners. Treat removed rights as an explicit compatibility decision. | Recommended confidential-file production gate |
| D4: Numeric boundary and invalid-present fields | What replaces purified or unusable numeric fields that currently succeed with changed meaning? | Validate raw supplied values by field. Return 400 and make no mutation when a supplied value is invalid. Treat nonpositive shopping add/remove amounts and nonpositive product IDs as errors. | Resolved by maintainer | Define field-specific validators and transaction tests. Document input compatibility changes in 0.5.0 release notes. | 0.5.0 selected numeric changes |
| D5: Battery charging and shopping read boundaries | What permissions apply to charging and shopping-page fields, and what separate rule applies to battery undo? | D5a: require BATTERIES_VIEW plus the tracking permission before charging. D5b: gate shopping barcodes and stock shortfall on STOCK_VIEW. | Resolved for charging and shopping; undo decision split out | Implement charging and shopping permissions independently. Battery undo remains an unresolved sub-decision. | 0.5.0 selected authorization changes |
| D6: Unusable conversion-chain policy | Should conversion writes refuse unusable resolved chains, or should resolution skip/clamp them? | Atomically refuse writes that would produce unusable conversion chains, and return a specific error. | Resolved by maintainer | Reject the write before committing any chain mutation; add concurrency and rollback coverage. | Selected #952 behavior |
| D7: Adopt additional production dispositions | Which recommended gates become required for the first production claim? | Adopt the individually specified production gates. A gate closes only with evidence, or with a narrowly justified production restriction that names its scope and affected users. | Resolved by maintainer | R1–R6 are adopted as additional 0.5.0 gates. Do not substitute issue counts, an unverified rerun, or unsupported claims for gate evidence. | Production go/no-go |
| D5a-undo: Battery charge undo authorization | Which permission must authorize undoing a battery charge cycle, and what information does the undo response disclose? | No answer recorded. D5a explicitly leaves undo separate. | Unresolved | Keep the undo rule out of implementation briefs until the maintainer decides. | Battery authorization implementation |
| D8: #908 battery charge-cycle write for a caller without BATTERIES_VIEW | Does a charge-cycle write need BATTERIES_VIEW, and what does a caller without it get back? | Option A: support write-only battery clients (including future smart-charger integrations). Tracking needs BATTERIES_TRACK_CHARGE_CYCLE only; without BATTERIES_VIEW the response is success plus the new charge-cycle id; the full row needs BATTERIES_VIEW. Undo keeps BATTERIES_UNDO_CHARGE_CYCLE and its empty success. | Resolved by maintainer | A write-only client gets success and the cycle id; the row stays behind BATTERIES_VIEW. | 0.5.0 implementation |
| D9: #933 shopping-list barcodes and stock shortfalls | May SHOPPINGLIST_VIEW callers see product barcodes and stock shortfalls on shopping-list pages? | Barcodes: SHOPPINGLIST_VIEW permits barcode display and scanning within shopping-list workflows (pages, item picker, shopping-list API response), including active-product candidates in the picker; general product_barcodes reads still need STOCK_VIEW. Shortfalls: counts, markers and filters require STOCK_VIEW. | Resolved by maintainer | Barcodes allowed within shopping-list workflows; shortfalls require STOCK_VIEW. | 0.5.0 implementation |
| D10: #952 conversion chains that overflow or underflow | What happens to a conversion write whose resolved chain overflows or underflows? | Option A: refuse a conversion write whose evaluated chain factors or inverses would be non-positive, non-finite or subnormal (#890's normal-float rule extended to the resolver), with a specific error and the write rolled back; no clamping or silent omission. | Resolved by maintainer | Refused with a specific error and rolled back; no clamping. | 0.5.0 implementation |

## New findings from planning

| Finding | Classification | Evidence and effect | Existing tracking | Next action / role |
| --- | --- | --- | --- | --- |
| F1: Published 0.4.1 tag has stale identity | independent follow-up | Tag b69f5a62 reports 0.4.0 and has no release record; identity job failed and images/publish were skipped. GitHub release nevertheless exists. [evidence/tag-version-evidence.txt](evidence/tag-version-evidence.txt), [evidence/release-041-jobs.txt](evidence/release-041-jobs.txt), [evidence/issues-957.json](evidence/issues-957.json) | PR957 describes the stale tag; PR960 supersedes identity with hotfix1. Both closed. No duplicate issue proposed. | Dated correction and predecessor guidance; never delete or move the published tag. Release coordinator |
| F2: Current release record is a draft and lint fails | historical documentation defect | The 0.4.1 tag/version mismatch and hotfix1 draft-record lint finding are historical. v0.4.4 is now the latest published release; PR #974 records and corrects the 0.4.3 publication record. [docs/releases/0.4.1-hotfix1.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/releases/0.4.1-hotfix1.md), [evidence/lint-log.txt](evidence/lint-log.txt) | Historical release records and merged PR #974; no new issue proposed. | Retain the historical correction. Do not present hotfix1 or 0.4.3 as the current release or next upgrade baseline. Release coordinator |
| F3: Status summaries hide delivered and partial work | independent follow-up | #701/#702/#742 remain open despite delivered/accepted slices; #905/#890/#892 hide residual dependencies. Plan index says #702/#703 not started while Plan 22 records simulator acceptance. #93 retains obsolete Proposed/unbuilt claims. [docs/plans/README.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/plans/README.md), [docs/plans/22-medication-tracking.md](https://github.com/datagen24/victual/blob/b5532aafd7b91306f7e98dd3361ba82d1ebbe5da/docs/plans/22-medication-tracking.md), [evidence/open-items.json](evidence/open-items.json) | Use existing issues and authoritative plan/ADR records; do not redispatch #919/#921/#945/#955 or file duplicate work. | Reconcile state with evidence; close only individually completed criteria after approval. Preserve #742 conversion limitation, #905/#920 and #892 residuals. Documentation/release coordinator |
| F4: Historical validation gaps have different current outcomes | independent follow-up | Later ledger supplies #921 before-fix failure and final-head delta validation. #904 part 2 still lacks complete phase counts and a PR. [evidence/historical-LEDGER.md](evidence/historical-LEDGER.md), [evidence/historical-RETROSPECTIVE.md](evidence/historical-RETROSPECTIVE.md) | #921 merged and #904 open; preserve existing local recovery work. | Retain recovered #921 evidence; require exact phase attribution if #904 resumes. No full-suite rerun merely to reconstruct the old session. QA/release coordinator |

## Historical leads reconciled

| Lead | Current conclusion |
| --- | --- |
| #919/#921/#945/#955 completion | All four merged; exact head and merge SHAs retained in resolved rows. Their recorded final validation matches PR heads. No completed implementation is redispatched. |
| #905 depends on #920 | Confirmed open. Shipped owner reads leave executable detach/delete residual; not accepted disclosure. |
| #892 depends on #946/#947/#950 | Confirmed open; #945 has Part of rather than closing scope. Numeric-string option A and ordinary-space correction remain settled. |
| #901 closed, #955 protection, #930 race | #901/#929 closed; #955 refuses differing effective stock factors before writes. Keep recorded preservation decision. #930 remains a distinct unproved concurrency window. |
| ADR-0048/0049 Proposed | Confirmed at local/remote SHA. #954 corrects view-owner record without acceptance. #900 part 2 stays undecided. |
| #890 depends on #951 | Confirmed; #949 guard merged but persistence boundary issue remains. #952 is distinct chain overflow, not a reason to reopen decision L. |
| #942 fixture failure | Still open and uninvestigated in inspected evidence. Passed rerun is not a root-cause result. |
| #921 before-fix evidence missing | Superseded by later ledger: round 5 at 0863a0cd observed one failure on revert; c59c5023 delta validated costs/calories/missing_amount regressions, shopping amount guard. |
| #904 part 2 phase counts missing | Still a gap. Local recovery commits 31b3439c/72fdffef have no current open PR; preserve work and exact missing evidence. |
| Closed outcomes and stale umbrella text | #897 closed by explicit decision with residuals #908/#909. #481 is duplicate of #483. #79 native label acceptance does not establish #93 cluster printing. #960 fixed version identity but its closed state does not complete the draft record. |

## Full assessment of each open issue and execution PR

### #948 Feature Request: Label Designer Quality of life enhancements

Outcome: Label authors can rotate, align and preview templates with usable fonts.

feature; P3 (Low immediate impact); size L; uncertainty medium; confidence medium. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Template editing remains less convenient; existing printing can work.

Next action: Retain backlog; scope rotation/fonts/alignment into independently reviewable units later.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/948), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #912 Decide whether ApiResponse encodes with JSON_THROW_ON_ERROR (from #844)

Outcome: API encoding failures have an intentional error body and logging policy.

investigation; P2 (Moderate assessed impact); size M; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Other unencodable outputs can still produce an empty 500 after committed writes.

Next action: Keep separate decision; evaluate after #916 prevention, without moving transaction boundaries implicitly.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/912), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#911](https://github.com/datagen24/victual/pull/911) | Merged implementation or documentation, according to PR title | Merged 2026-10-13T17:02:26Z | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |
| [#916](https://github.com/datagen24/victual/issues/916) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #909 Battery and equipment rework: residual battery authorization findings

Outcome: Battery/equipment rights and lifecycle can be redesigned together.

feature / breaking change; P2 (Moderate assessed impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Existence oracle and broad battery CRUD/cascading deletion remain as explicitly recorded residuals.

Next action: Retain deferred rework; distinguish #908 and #920 from this accepted scope.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/909), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#897](https://github.com/datagen24/victual/issues/897) | Completed or dispositioned in tracker; see recorded owning outcome | Owning merged work/decision recorded in source; issue closure alone is not a merge | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #904 Subprocess test helpers read stdout before stderr and deadlock on more than 64 KB of stderr

Outcome: Subprocess tests terminate without pipe deadlock and preserve interactive/streaming behavior.

compatible fix (conditional); P2 (Moderate assessed impact); size M; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.4.3. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Some future tests can hang and obscure failures; old local work lacks complete phase evidence.

Next action: Recover 31b3439c/72fdffef without replacing them; migrate only callers required by upcoming validation first.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/904), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Separate work unit | Proposed destination | Classification | Boundary |
| --- | --- | --- | --- |
| 904-required-callers | As required by owning fix | compatible fix (tests) | Recover helper and migrate only subprocess callers required for new regression evidence. |
| 904-rest | Unassigned | compatible fix (tests) | Preserve broad interactive/streaming migration as separate work with missing phase counts. |

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#907](https://github.com/datagen24/victual/pull/907) | Merged implementation or documentation, according to PR title | Merged 2026-10-13T14:21:55Z | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #900 ADR-0019 does not list the Resolve() validation refusal as a dead-letter cause

Outcome: Operators and records agree on label dead-letter causes and recovery limits.

documentation / investigation; P2 (Moderate assessed impact); size M; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Inactive-printer jobs can be dead-lettered; configuration recovery remains limited to documented reprint behavior.

Next action: Review factual correction separately; defer behavior decisions unless production label scope needs them.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/900), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Separate work unit | Proposed destination | Classification | Boundary |
| --- | --- | --- | --- |
| 900-record | Unassigned | documentation | Review factual ADR-0049 part 1; acceptance in its own bookkeeping PR. |
| 900-behavior | Unassigned | feature / breaking change | Keep part 2 lifecycle/recovery/storage questions as later work unless selected production scope requires them. |

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#932](https://github.com/datagen24/victual/pull/932) | Merged implementation or documentation, according to PR title | Merged 2026-10-13T17:49:41Z | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |
| [#881](https://github.com/datagen24/victual/pull/881) | Merged implementation or documentation, according to PR title | Merged 2026-10-13T00:57:26Z | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #796 Audit and bring the REST API specification into full OpenAPI 3.2 compliance

Outcome: A reproducible full source/served/client audit replaces unsupported scan totals.

investigation; P2 (Moderate assessed impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.4.2. Actual milestone: 0.6.0. Proposed: 0.6.0 (rename 0.5.5). Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Unreviewed operations may retain drift; changed/depended-on 0.5.0 operations still require accurate contracts.

Next action: Retain bounded child corrections earlier; do not claim full compliance from structural validation.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/796), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Separate work unit | Proposed destination | Classification | Boundary |
| --- | --- | --- | --- |
| 796-required-contracts | 0.5.0 | release gate | Only contracts changed or depended on by the production release. |
| 796-full-audit | 0.6.0 (rename 0.5.5) | investigation | Complete broad source/served/client audit separately. |

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#818](https://github.com/datagen24/victual/pull/818) | Merged implementation or documentation, according to PR title | Merged 2026-10-12T13:23:41Z | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |
| [#801](https://github.com/datagen24/victual/issues/801) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |
| [#891](https://github.com/datagen24/victual/issues/891) | Server contract implemented; handoff/client adoption not established | Partial merged work; see issue evidence | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |
| [#892](https://github.com/datagen24/victual/issues/892) | Partial grammar documentation implemented | Partial merged work; see issue evidence | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |
| [#893](https://github.com/datagen24/victual/issues/893) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #771 Design: external identity provider (OIDC and SAML) with Authentik as the reference IdP

Outcome: Users and clients can use an external identity provider.

feature; P3 (Low immediate impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.5. Actual milestone: 0.6.0. Proposed: 0.6.0 (rename 0.5.5). Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Local credentials remain; SAML is undecided and not production-core necessity.

Next action: Keep research after #770 architecture; do not introduce unreviewed outbound URL surfaces.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/771), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#770](https://github.com/datagen24/victual/issues/770) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #770 Design: OAuth 2.1 access tokens for API and MCP, replacing static API keys as the primary credential

Outcome: API and MCP clients obtain short-lived scoped tokens.

feature; P3 (Low immediate impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.5. Actual milestone: 0.6.0. Proposed: 0.6.0 (rename 0.5.5). Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Static API keys remain the supported authentication path.

Next action: Preserve later destination; resolve authorization-server architecture in its own ADR.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/770), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#773](https://github.com/datagen24/victual/pull/773) | Merged implementation or documentation, according to PR title | Merged 2026-10-11T22:47:50Z | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #762 Users: roles can't be set when creating a user, and the only entry point is an unlabeled lock icon

Outcome: Administrators can assign roles while creating a user and find the roles control.

feature; P3 (Low immediate impact); size M; uncertainty medium; confidence medium. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Extra navigation remains; production authorization can still be configured.

Next action: Retain backlog and enforce MayAdminister on eventual UI work.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/762), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #737 Consumption recipe sharing: answer a share request identically whether or not the user exists

Outcome: Share responses can eventually hide whether a username exists.

breaking change; P2 (Moderate assessed impact); size S; uncertainty low; confidence high. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Authorized recipe sharers can distinguish target-user existence within the accepted LAN scope.

Next action: Retain post-0.5.0 work; later response change needs release placement and contract tests.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/737), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#698](https://github.com/datagen24/victual/issues/698) | Completed or dispositioned in tracker; see recorded owning outcome | Owning merged work/decision recorded in source; issue closure alone is not a merge | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #655 Implement Home Assistant add-on version upgrades independently of Grocy import

Outcome: HA add-on upgrades work without Grocy import and without giving serving processes DDL credentials.

feature; P3 (Low immediate impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: No supported HA add-on lifecycle can be claimed.

Next action: Keep dependent on #654 topology/design; verify fresh/upgrade/refusal/restore per advertised architecture later.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/655), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#654](https://github.com/datagen24/victual/issues/654) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Design/topology prerequisite within the same target; not a requirement to complete all #654 first. |

### #654 Feature Request: Target as Home Assistant Addon

Outcome: Households install and operate Victual through HA Supervisor with companion printing/MCP.

feature; P3 (Low immediate impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Users retain existing deployment paths; no add-on promise.

Next action: Retain backlog; #655 is required delivery, not optional import work.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/654), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#655](https://github.com/datagen24/victual/issues/655) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Delivery requirement: #655 cannot be omitted when #654 ships. |

### #486 Installation country setting (environment variable and onboarding)

Outcome: Country-specific barcode/default settings can be configured explicitly.

feature; P3 (Low immediate impact); size M; uncertainty medium; confidence medium. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.5. Actual milestone: 0.6.0. Proposed: 0.6.0 (rename 0.5.5). Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Country-derived defaults remain unavailable.

Next action: Decide precedence/default behavior in later design; environment slice need not wait for wizard.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/486), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #484 Planning: web setup flow — upload a grocy database and bring the app up

Outcome: Administrators can import Grocy and finish setup through the web.

feature; P3 (Low immediate impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.5. Actual milestone: 0.6.0. Proposed: 0.6.0 (rename 0.5.5). Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: CLI import and documented bootstrap remain necessary.

Next action: Keep later; preserve decided import-only maintenance scope and #483's independent conflict page.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/484), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#483](https://github.com/datagen24/victual/issues/483) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #483 Planning: canonical, symbology-tagged barcode storage and one inbound scan resolver

Outcome: Equivalent barcode forms resolve consistently with scoped conflicts and explicit quarantine.

breaking change / feature; P3 (Low immediate impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.5. Actual milestone: 0.6.0. Proposed: 0.6.0 (rename 0.5.5). Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Raw matching limitations remain; existing issues are not duplicate new work.

Next action: Keep later; complete ADR-0031's six prerequisites and accept separately before implementation.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/483), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#486](https://github.com/datagen24/victual/issues/486) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #252 Code that coverage proved nothing can reach

Outcome: Maintainers decide whether proven unreachable code is useful defensive behavior.

investigation; P3 (Low immediate impact); size M; uncertainty medium; confidence medium. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Maintenance cost persists without demonstrated user failure.

Next action: Retain inventory; decide each guard separately when touched.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/252), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#192](https://github.com/datagen24/victual/issues/192) | Completed or dispositioned in tracker; see recorded owning outcome | Owning merged work/decision recorded in source; issue closure alone is not a merge | Recorded 2026-09-22 coverage floor complete; current baseline separately 96.81%. | Prerequisite or required outcome |

### #139 Plan 18 — run the Home Assistant verifications (2, the HA half of 4, and 8)

Outcome: HA retained entities and date templates work while Victual sleeps, with a measured idle week.

release gate (conditional); P2 (Moderate assessed impact); size M; uncertainty high; confidence low. Release necessity: Conditional supported-path gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Release operator.

Affected users / consequence of deferral: Cannot claim these HA resilience/idle guarantees; base application can ship with stated limitation.

Next action: Retain the later assignment; do not claim Home Assistant verification for 0.5.0.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/139), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #133 Plan 20 — pieces 2, 4, 5 and the credential split (piece 4 is also #93's last gate)

Outcome: Cluster shutdown and credential/image behavior meet the advertised production path.

release gate (conditional); P1 (High assessed impact); size M; uncertainty high; confidence low. Release necessity: Selected production support gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Needs maintainer placement. Actual assignees: none. Responsible role is proposed: Release operator.

Affected users / consequence of deferral: Historical Talos applies do not prove candidate shutdown or printer behavior.

Next action: D2 proposed this for 0.5.0; 0.5.0 shipped on 2026-10-19 without it and makes no cluster label or shutdown support claim (gate R6 not evidenced). Place it in 0.5.1, 0.6.0 or later. Consolidate the shared pinned-artifact Helm, label and shutdown evidence; reconcile completed Plan 20 pieces before changing GitHub assignment.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/133), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #93 Verify K3S operation (label worker, SIGTERM on a cluster)

Outcome: A deployed worker image prints and scans a label on the supported cluster/printer path.

release gate (conditional); P1 (High assessed impact); size M; uncertainty high; confidence low. Release necessity: Selected production support gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Needs maintainer placement. Actual assignees: none. Responsible role is proposed: Release operator.

Affected users / consequence of deferral: Native printing is not evidence that a released image reaches the printer.

Next action: D2 proposed this for 0.5.0; 0.5.0 shipped on 2026-10-19 without it and makes no cluster label or shutdown support claim (gate R6 not evidenced). Place it in 0.5.1, 0.6.0 or later. Consolidate the shared pinned-artifact Helm, label and shutdown evidence; reconcile completed Plan 20 pieces before changing GitHub assignment.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/93), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#133](https://github.com/datagen24/victual/issues/133) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #86 MCP sidecar: deployment, Inspector, real-client checks and two-replica soak

Outcome: Actual supported MCP clients and two replicas pass Inspector/replay/soak checks.

release gate (conditional); P2 (Moderate assessed impact); size M; uncertainty high; confidence low. Release necessity: Conditional supported-path gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Release operator.

Affected users / consequence of deferral: Do not claim the outstanding real-client/soak checks are verified.

Next action: Retain later; state the MCP support limitation and do not claim real-client/soak evidence.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/86), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

| Dependency | Implemented | Merged | Verified | Edge |
| --- | --- | --- | --- | --- |
| [#133](https://github.com/datagen24/victual/issues/133) | No completed implementation established in inspected evidence | No merged completion established | No item-specific final verification independently recovered here; baseline CI is not proof of every acceptance criterion. | Prerequisite or required outcome |

### #80 Wave 3b (deferred) — Plan 09: US barcode lookup sources

Outcome: US pantry barcodes resolve through sources proven useful by sampling.

feature; P3 (Low immediate impact); size L; uncertainty high; confidence low. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Open Food Facts misses some US products; manual entry remains.

Next action: Keep pantry experiment before new source implementation; preserve fixed outbound-host protections.

Evidence: [Current issue body](https://github.com/datagen24/victual/issues/80), [Saved inventory](evidence/open-items.json). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #987 Consume stores any integer recipe_id, including negative ids and ids no recipe has

Outcome: Validate recipe_id names a recipe; 400 otherwise.

bug; P3 (Low); size S; uncertainty low; confidence high. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.1. Actual milestone: 0.5.1. Proposed: 0.5.1. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Does not block 0.5.0; see the issue body.

Next action: Validate recipe_id names a recipe; 400 otherwise.

Evidence: [Issue body](https://github.com/datagen24/victual/issues/987). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #988 prose-baseline-prune fails daily: it pushes to master, which requires a pull request

Outcome: Open or update a PR from a fixed branch instead of pushing to master.

ci; P3 (Low); size S; uncertainty low; confidence high. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.1. Actual milestone: 0.5.1. Proposed: 0.5.1. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Does not block 0.5.0; see the issue body.

Next action: Open or update a PR from a fixed branch instead of pushing to master.

Evidence: [Issue body](https://github.com/datagen24/victual/issues/988). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #989 Calendar API 500 includes the user-supplied event title in its body

Outcome: Decide whether the 500 body may carry the title; otherwise log it server-side.

security; P3 (Low); size S; uncertainty low; confidence high. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.1. Actual milestone: 0.5.1. Proposed: 0.5.1. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Does not block 0.5.0; see the issue body.

Next action: Decide whether the 500 body may carry the title; otherwise log it server-side.

Evidence: [Issue body](https://github.com/datagen24/victual/issues/989). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #991 Uploading a userfile under an existing unreferenced name reveals that the name exists

Outcome: Decide whether the existence signal matters; update ADR-0048 item 9.

security; P3 (Low); size S; uncertainty low; confidence high. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: 0.5.1. Actual milestone: 0.5.1. Proposed: 0.5.1. Actual assignees: none. Responsible role is proposed: Domain implementer + independent validator.

Affected users / consequence of deferral: Does not block 0.5.0; see the issue body.

Next action: Decide whether the existence signal matters; update ADR-0048 item 9.

Evidence: [Issue body](https://github.com/datagen24/victual/issues/991). Repository inspection SHA: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

### #1007 UI: group medication features in a Medication flyout and clarify labels

Outcome: See the issue body.

enhancement; Not assessed (Not assessed); size Not assessed; uncertainty not assessed; confidence not assessed. Release necessity: Candidate or deferred; not an automatic production gate.

Original baseline: Unassigned. Actual milestone: Unassigned. Proposed: Unassigned. Actual assignees: none. Responsible role is proposed: Maintainer triage.

Affected users / consequence of deferral: Not assessed.

Next action: Assess and place; no milestone assigned on GitHub.

Evidence: [Issue body](https://github.com/datagen24/victual/issues/1007). Repository inspection SHA: `431b1a97c1354a960a6e6a7e194eb1976bc4112a`; no item-specific new validation in this session.

Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.

## Next release agent plan

0.5.0: Coordinator implementation in progress; no sub-agents dispatched.

[Historical 0.4.3 agent briefs](agents-0.4.3.md)

Current planning baseline: `631c6b27cf0d743b6068b5f503940ff9dc97e496`. Agents dispatched: no.

## Verification, freshness and budget

| Check | Result | Evidence |
| --- | --- | --- |
| Inventory completeness | Complete GitHub REST snapshot at 2026-10-16T18:51:27Z: 43 open issues, 0 open PRs, six milestones. The original 53 assessed issues now have 43 open and 10 closed. The repository-wide closed-issue total was not refreshed. | [Evidence](evidence/refresh-2026-10-16/open-issues.json) |
| Repository identity | Current GitHub master is 631c6b27cf0d743b6068b5f503940ff9dc97e496; v0.4.4 tag targets the same commit. The planning baseline SHA remains b5532aafd7b91306f7e98dd3361ba82d1ebbe5da for traceability. | [Evidence](evidence/refresh-2026-10-16/master.json) |
| Read-only model reviews | Luna medium completed two bounded source questions; Astra high completed one consequential compatibility packet. Reports recorded before use. No implementation agents or suites. | [Evidence](evidence/astra-compatibility-review.md) |
| Planning-baseline CI (historical) | This was the initial b5532aafd7b9 planning-baseline snapshot; it is superseded by the subsequent 0.4.2–0.4.4 checks below. | [Evidence](evidence/suite-summary.txt) |
| Current issue refresh and resolved cohort reconciliation | Complete open-issue query returned 43 issues. Ten of the original 53 issue cohort are closed: the prior eight in 0.4.2/0.4.3 plus #926 and #898 in 0.4.4. All six milestone states and counts and all open-issue milestone fields were refreshed. Repository-wide open PR query returned zero. | [Evidence](evidence/refresh-2026-10-16/open-issues.json) |
| Dashboard validation limit | Standalone HTML and embedded JSON generated locally; JavaScript syntax checked. Browser validation could not run: the computer-use runtime returned no available browsers. Rendering and interactive behavior remain unverified in a real browser. | [Evidence](evidence/artifact-validation.md) |
| Authorized milestone remap | The approved six milestone metadata updates and 25 issue reassignments were applied. Current GitHub status was refreshed 2026-10-16T14:48:00Z: 0.5.0 has 22 open/18 closed; 0.6.0 has 6 open/0 closed; 0.4.4 has 0 open/2 closed. Due dates remain unset. Original milestone assignments remain in baseline_milestone. | [Evidence](evidence/refresh-2026-10-16/milestones.json) |
| 0.4.3 tagged-release validation | On tagged commit 320f610e: master tests run 38780445437 and Nix run 38780446285 succeeded. Tag-triggered workflow 38815984146 passed identity, amd64, arm64, publish and release jobs. Signed tag verified. Published image and chart digests are recorded in execution.publication_043. PR #971 merged in the 0.4.4 closeout; see the 0.4.4 release validation below. | [Evidence](https://github.com/datagen24/victual/actions/runs/38815984146) |
| 0.4.3 actual predecessor upgrade | Populated v0.4.2-to-v0.4.3 rehearsal passed on PostgreSQL 16.15 at migration 312. Candidate migrator ran twice; normalized schema/data dumps were byte-identical; stock quantity 3 and chore average frequency 24.5 hours were preserved. Restore, refusal/recovery, arbitrary production data, PostgreSQL 15 rehearsal and production image rollout remain untested. | [Evidence](evidence/upgrade-043/REPORT.md) |
| 0.4.4 signed tag and publication | Published 2026-10-16T12:26:57Z. Signed annotated tag object 9e8f2964a206c8dab2e2723f6217abec1f04871e targets 631c6b27cf0d743b6068b5f503940ff9dc97e496. Release workflow 38898838522 completed successfully; six images and the Helm chart were published for linux/amd64 and linux/arm64. The tag release is separate from the merged PR CI runs. The 0.5.0 upgrade rehearsal must start from this actual preceding release. | [Evidence](evidence/refresh-2026-10-16/release-0.4.4.json) |
| 0.4.4 master CI | Tests, Nix, Psalm Security Scan, prose and push-on-master checks completed successfully on the release target master SHA. The release workflow also passed its tagged image verification and publication steps. | [Evidence](https://github.com/datagen24/victual/actions/runs/38833436087) |
| Independent scheduled Vale baseline-prune automation failure | The scheduled job pruned 889 resolved baseline entries and passed Vale. Its direct push to master was rejected by the repository rule “Changes must be made through a pull request.” This did not affect the release or its checks. Existing follow-up: adjust this automation to submit the baseline change through a PR; no change is made in this dashboard task. | [Evidence](https://github.com/datagen24/victual/actions/runs/38894184659) |
| v0.4.4 tests workflow | Passed on master SHA 631c6b27cf0d743b6068b5f503940ff9dc97e496. | [Evidence](https://github.com/datagen24/victual/actions/runs/38833436087) |
| v0.4.4 Nix workflow | Passed on master SHA 631c6b27cf0d743b6068b5f503940ff9dc97e496. | [Evidence](https://github.com/datagen24/victual/actions/runs/38833436026) |
| v0.4.4 Psalm Security Scan | Passed on master SHA 631c6b27cf0d743b6068b5f503940ff9dc97e496. | [Evidence](https://github.com/datagen24/victual/actions/runs/38833436008) |
| v0.4.4 prose workflow | Passed on master SHA 631c6b27cf0d743b6068b5f503940ff9dc97e496. | [Evidence](https://github.com/datagen24/victual/actions/runs/38833436003) |
| v0.4.4 push-on-master workflow | Passed on master SHA 631c6b27cf0d743b6068b5f503940ff9dc97e496. | [Evidence](https://github.com/datagen24/victual/actions/runs/38833435759) |
| v0.5.0 tests workflow (release PR head 2b350b1d) | Passed: suite (PG16, coverage), suite-floor (PG15), frontend-security, images, mcp, lint. | [Evidence](https://github.com/datagen24/victual/actions/runs/39187337919) |
| v0.5.0 release workflow | Succeeded on tag target 555475aeec4ce2840671ab5623460ebf9f8440b3; six images and the chart published. | [Evidence](https://github.com/datagen24/victual/actions/runs/39250766266) |
| v0.5.0 tag signature | GitHub reports the tag object verified (reason: valid). | [Evidence](evidence/refresh-2026-10-19/tag-object-v0.5.0.json) |
| Repository refresh 2026-10-19 | 26 open issues, 0 open PRs, seven milestones. | [Evidence](evidence/refresh-2026-10-19/open-issues.json) |

Session started 2026-10-15T14:52:34Z; two-hour deadline 2026-10-15T16:52:34Z; no new research after 2026-10-15T16:16:34Z. Recorded elapsed time: 33.3 minutes. Per-session token/cost measurements unavailable; report elapsed wall time and agent activity only. Dashboard refresh and 0.4.4 closeout were subsequent work; separate token/cost measurements are unavailable.

Active Codex session; model identity/reasoning could not be changed or independently verified. Do not attribute coordinator work to GPT-6 Sol.

Readers: GPT-6 Luna medium, two bounded source tasks; GPT-6 Astra high, one consolidated compatibility task. At most two readers ran concurrently. Reports are saved in [Luna report](evidence/luna-production-review.md) and [Astra report](evidence/astra-compatibility-review.md).

Remaining work: 0.5.0 is complete. Next release work is 0.5.1; no agents are dispatched by this dashboard refresh.

## Limits on the conclusion

- No application suite, reproduction, deployment, registry pull or backup restore ran in this planning session. GitHub CI and historical validator results are attributed to their recorded SHAs.

- Do not label either confirmed production corruption. #944 persisted driver_id behavior is also unknown.

- Simulator evidence for #702 is accepted. No real-device/real-Health-store delivery or identifier behavior is claimed. The stale client SHA needs evidence reconciliation.

- Reader models were explicit Luna medium and Astra high. Coordinator model selection cannot be changed/attested from this session; no claim that GPT-6 Sol performed coordination.

- Milestone dates are all unset. The plan provides dependency order and coarse size, not fabricated delivery dates or token usage.

- The scheduled Vale baseline-prune job still needs a pull-request-based write path; the failure is independent of v0.4.4 and does not block 0.5.0 readiness.

- The GitHub inventory is a timestamped, non-atomic snapshot from 2026-10-19T14:43:24Z. Refresh it before any later milestone change or release batch.

- 0.5.0 was published without R5 (restore) and R6 (cluster labels/shutdown) evidence; the release record makes neither claim. The upgrade rehearsal ran on PostgreSQL 16.15 from the dev shell, not the production images.

- This refresh read GitHub and the release record only; it ran no suite, deployment, registry pull or restore.

## Approval boundary

The approved milestone metadata batch was applied and live GitHub assignments were refreshed. #944 is closed in both portions. ADR-0045, -0046, -0048 and -0050 were accepted in their own PRs before the tag. Version 0.5.0 is signed and published. It was published without restore (R5) or cluster label/shutdown (R6) evidence, so it claims neither; placing that work is a maintainer decision.
