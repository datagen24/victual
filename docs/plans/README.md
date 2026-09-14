# Plans

This folder contains research and design documents for major changes to Victual. Each
plan describes the problem, current behavior, proposed scope, constraints, alternatives,
open questions, and verification criteria. It provides the information needed to prepare
an implementation plan; it is not a coding prompt or a session-by-session task list.

Read the [constitution](../constitution.md) and [ADR index](../adr/README.md) first, then
use the status table and work order below to find the relevant plan and its dependencies.
Plan numbers are permanent identifiers, not execution order.

## Status

This table is the authority on delivery status, updated through 2026-09-14. “Landed” means
implemented; outstanding verification and follow-up work are listed separately. A plan's
**Executed** section records what shipped and any differences from the proposed design.

| # | Plan | Status | Dependencies or remaining work |
|---|---|---|---|
| — | [PostgreSQL support](../../db/pgsql/README.md) | Landed | SQLite runtime retirement remains. |
| 01 | [Database file storage](01-file-storage.md) | Landed | PostgreSQL; migration 0258. |
| 02 | [MCP endpoint](02-mcp-endpoint.md) | Draft, [issue 86](https://github.com/datagen24/victual/issues/86) | Wave 5. Read the [interface spec](../mcp-interface-spec.md), which supersedes the body. Requires 11, 13, 14 piece 2, and 15-C1. |
| 03 | [Category minimum stock](03-category-min-stock.md) | Landed | Wave 3b; PostgreSQL; migration 0268. Note-only shopping list row (Q1) remains a follow-up. **The nested `parent_product_group_id` follow-on would be [30](30-nested-product-groups.md)'s**, once ADR-0023 is accepted and a later pull request schedules that plan; whether a parent group's minimum rolls up to descendant groups is that plan's Q1 and touches `product_groups_missing`, which this plan shipped. |
| 04 | [Seed datasets](04-seed-datasets.md) | Draft, unscheduled | Importer when needed; dataset curation is ongoing. |
| 05 | [Store shopping lists](05-store-shopping-lists.md) | Draft, [issue 85](https://github.com/datagen24/victual/issues/85) | 12 landed. Parts A/C in wave 5; B depends on usage. No migration number is claimed yet; claim one before writing part A. |
| 06 | [Location barcodes](06-location-barcodes.md) | Landed; two follow-ups | Wave 3b, [issue 79](https://github.com/datagen24/victual/issues/79) **closed 2026-09-09 on physical evidence**: a location label requested, printed on the QL-820NWBc, scanned back by an authorized user, and the failure-and-reprint cycle demonstrated against the device. Print actions on the list and form (PR 113) and the stateless scan surface (PR 108) are in `master`. Remaining: Q5's tree path on the human-readable line now that 08 has landed ([issue 137](https://github.com/datagen24/victual/issues/137)), and the placement convention. Interactive current-location scanning stays deferred to a plan of its own. |
| 07 | [Nested products](07-nested-products.md) | Blocked on Q6 — **answered, not yet ratified**, [issue 82](https://github.com/datagen24/victual/issues/82) | The sampling Q6 asked for was done 2026-09-13 and landed on **taxonomy**: seven of thirteen candidate pairs are separate products and none is a pool, so the roll-up relation had nothing to act on. That is evidence, not authority — [ADR-0023](../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md) is **Proposed**; prerequisite 1 (the sampling recorded in this plan) was met 2026-09-14 and prerequisites 2–4 are outstanding, tracked in [issue 128](https://github.com/datagen24/victual/issues/128). This plan is retired **after** that record is accepted, by a separate pull request — the acceptance itself carries bookkeeping only. Until then this stays blocked and [30](30-nested-product-groups.md)/[31](31-directed-substitution.md) are not its replacements. |
| 08 | [Nested locations](08-nested-locations.md) | Landed | Wave 4, [issue 81](https://github.com/datagen24/victual/issues/81). Migration 0273: `locations.parent_location_id`, the `locations_resolved` read entity under `STOCK_VIEW`, `hierarchy_depth_limit()` (which [07](07-nested-products.md) shares), a cycle/depth guard and a delete guard. Raises the engine minimum to PostgreSQL 15 for `NULLS NOT DISTINCT`. Verified by a `locations` suite phase and a `frontend-security` browser probe. [23](23-storage-classes.md) adds to the same table next and should be re-read against it. |
| 09 | [US barcode lookup sources](09-barcode-lookup-sources.md) | Deferred, [issue 80](https://github.com/datagen24/victual/issues/80) | Q1's kitchen experiment; S14 before adding sources. Both can run now; the plugins wait on the experiment's data. |
| 10 | [Cold start and statelessness](10-cold-start-statelessness.md) | Landed | Pairs with 01 for a runtime without a persistent volume. |
| 11 | [API errors and authentication](11-api-error-handling.md) | Landed with follow-ups | API key expiry/rotation, sweep S11's remaining half ([issue 130](https://github.com/datagen24/victual/issues/130), ready); Q5's schema-derived allowlist after 14 piece 2. The plan carries no Executed section; what shipped is recorded inline under each section. |
| 12 | [Frontend shared core](12-frontend-shared-core.md) | Landed | S29 follow-up fixes and its CI guard landed through 21. |
| 13 | [Write-path transactions](13-write-path-transactions.md) | Landed | See Executed for transaction-nesting behavior. |
| 14 | [Contract and regression scaffolding](14-contract-and-regression-scaffolding.md) | Pieces 1, 3, 4 landed | Piece 2 remains, [issue 83](https://github.com/datagen24/victual/issues/83), wave 5; API read additions (28, 31) and 19's visibility work precede the contract freeze. |
| 15 | [Cleanup](15-deliberate-cleanup.md) | Partly landed | C1, C4, B1, B2 complete; C9 partly complete. C2, C3, C5–C8, remaining C9 sites, C10–C14 remain and are unblocked, [issue 132](https://github.com/datagen24/victual/issues/132). C11 is sweep S13. B3 declined; B4 only if C7 resolves upward. |
| 16 | [Project rename](16-project-rename.md) | Code rename landed | Registry/domain claims await announcement. |
| 17 | [Ecosystem clients](17-ecosystem-clients.md) | Ongoing | Q2/Q4 answered; Q1 and part of Q3 remain. Client implementations are outside this repository's wave order. |
| 18 | [MQTT state publication](18-mqtt-state-publication.md) | Landed | Home Assistant checks 2, the Home Assistant half of 4, and 8 remain and need a running Home Assistant, [issue 139](https://github.com/datagen24/victual/issues/139). Includes InfluxDB events through an outbox. |
| 19 | [Roles and data visibility](19-rbac.md) | Piece 1 implemented | Wave 3a: roles and six domain read permissions; [ADR-0018](../adr/0018-role-grants-and-domain-reads.md) records the model, accepted 2026-09-14. Piece 2, including price visibility and S30/S31, is [issue 84](https://github.com/datagen24/victual/issues/84) in wave 5 and can start ahead of 14 piece 2, which it precedes. |
| 20 | [Container infrastructure](20-container-infrastructure.md) | Piece 1 and part of 3 landed | Production Docker target retired. Pieces 2, remaining 3, 4, 5 and the credential split remain and are unblocked, [issue 133](https://github.com/datagen24/victual/issues/133); piece 4's K3S apply is the same work as 25's verification 12. SIGTERM verification needs a cluster that honours `lifecycle.stopSignal`. |
| 21 | [Frontend sink discipline](21-frontend-sink-discipline.md) | Landed | CI payload checks and stored-HTML cleanup on upgrade/import included. |
| 22 | [Medication tracking](22-medication-tracking.md) | Draft, unscheduled | 23 ([issue 127](https://github.com/datagen24/victual/issues/127)) and 14 piece 2; ADR-0015/0016 remain Proposed. Q6's unresolved ownership of label infrastructure is now 25's. Reservations 0275–0276. |
| 23 | [Storage classes](23-storage-classes.md) | Draft, **scheduled into wave 4 2026-09-14**, [issue 127](https://github.com/datagen24/victual/issues/127) | Ready: depends on nothing, Q1–Q3 answered (derive `is_freezer` in the application), 08 landed, effort small. Before 22. Re-read the plan against what 08 shipped before writing 0274. Reservation 0274. |
| 24 | [SQLite runtime retirement](24-sqlite-runtime-retirement.md) | Landed | ADR-0008's retirement work. The differential harness and migrations 0001–0255 stay until 14 piece 2. |
| 25 | [Label infrastructure](25-label-infrastructure.md) | Groups A, B and C implemented; physically accepted 2026-09-09; one verification open | Wave 3b, [issue 93](https://github.com/datagen24/victual/issues/93). Owns ADR-0011's machinery and answers ADR-0019's question 1; narrowed 2026-09-07 by [ADR-0021](../adr/0021-label-templates-are-application-data.md) so that templates, rendering, previews and artifacts are [27](27-label-templates-and-rendering.md)'s and this plan keeps identity (0269), jobs, printer configuration and the worker API (0270), the delivery worker and the import refusal. Both gating records were accepted 2026-09-07. **Verification 13 — a label requested in Victual, rendered, verified, claimed, printed on the QL-820NWBc and scanned back — was met 2026-09-09**, with 14 and 15 and the crash-after-send and renderer-independent-reprint cases; two-colour printed through the whole path the same day. **Open: verification 12's deployment half** — a K3S apply, or a container runtime whose egress reaches the printer; the label printed from the native build of the pinned worker because the local podman VM cannot open TCP to it. That is the same work as [20](20-container-infrastructure.md) piece 4 ([issue 133](https://github.com/datagen24/victual/issues/133)). Deferred and unscheduled: ADR-0019 item 7 steps 2–3 — migrating the five entity types that print through the webhook, which needs a wire-contract record, then deleting the webhook. |
| 26 | [Documentation site](26-documentation-site.md) | Piece 1 implemented | Wave-independent. Piece 1, the developer section, is built: staging script, MkDocs and Read the Docs configuration, the pinned phpDocumentor reference, and a strict build in the `lint` job. Implements [ADR-0020](../adr/0020-documentation-publication-boundary.md), which is **Proposed**; piece 1 is the evidence its prerequisites 2 and 4 ask for, and its acceptance is bookkeeping, [issue 135](https://github.com/datagen24/victual/issues/135). Piece 2, the manual, is new writing across 81 pages and 84 settings, [issue 138](https://github.com/datagen24/victual/issues/138). |
| 27 | [Label templates and rendering](27-label-templates-and-rendering.md) | Implemented; two follow-ups | Wave 3b, alongside 25. Owns the browser designer, the headless renderer, previews and print artifacts; 25 keeps identity, jobs, printer configuration and the delivery worker. Gated on [ADR-0021](../adr/0021-label-templates-are-application-data.md), accepted 2026-09-07 — **gate cleared**. Migrations 0271 and 0272 are in `master`; the designer landed in PR 113 (2026-09-09), and verification 4's physical print and scan-back were met the same day. Remaining: migrating the designer off fabric 5.x, which cannot be a dependency bump ([issue 126](https://github.com/datagen24/victual/issues/126)); sweep finding S32, the fail-closed group-to-read-permission table for the files API ([issue 136](https://github.com/datagen24/victual/issues/136)). Question 9, where red belongs, waits on a `stock_entry` field kind behind ADR-0019 item 7 step 2. |
| 28 | [Open container measurement](28-open-container-measurement.md) | Draft, **ready**, [issue 118](https://github.com/datagen24/victual/issues/118) | Wave 4. Owns [ADR-0022](../adr/0022-open-containers-carry-a-measured-remainder.md), **accepted 2026-09-14** with all eight prerequisites met — the spike in `.spike-adr22/` is the evidence for six of them, and it found two things this plan must record when it specifies the write path: undoing an opening must clear all four measurement columns, and convertibility is the write path's check rather than a database constraint. The legacy tare fields stay on the wire at zero with their arithmetic removed (decision 7). An opened unit's remaining contents live on the `stock` entry, so counting pieces stops costing the ability to know a jug is half empty. It was scheduled beside 07 to share `stock_current`'s aggregation rewrite; ADR-0023, if accepted, cancels 07's half of that rewrite, so the co-scheduling reason lapses and the plan's "Interaction with 07" section should be re-read then. Additive on the stock reads, so it wants to land before [14](14-contract-and-regression-scaffolding.md) piece 2 freezes the contract. Migration 0277 reserved. |
| 29 | [Working container replenishment](29-working-container-replenishment.md) | Draft, **ready**, [issue 131](https://github.com/datagen24/victual/issues/131) | Wave 4. ADR-0022 was accepted 2026-09-14, so both halves are unblocked: the (product, location) minimum, its shortfall view and the one-tap refill, and weighing the bin, whose tare is the **location's** under decision 4 (`locations.tare_weight` and `tare_qu_id`, after 23's 0274 alters the same table). The scale scans the vessel's label and posts gross weight; the server subtracts. Bagged backstock feeds a kitchen bin: pack sizes as barcodes on one product, the bin as a location, refill as a `TransferProduct` — all of which already exist. Adds a **(product, location) minimum** whose consequence is a refill prompt, never a shopping list entry, and consumes [ADR-0022](../adr/0022-open-containers-carry-a-measured-remainder.md) decision 4's location-scoped tare to weigh the bin. Product-scoped tare cannot do this: `TransferProduct()` refuses tare-enabled products outright. Migration 0278 reserved. |
| 30 | [Nested product groups](30-nested-product-groups.md) | Draft, **blocked on ADR-0023 acceptance**, [issue 124](https://github.com/datagen24/victual/issues/124) | Wave 4. The taxonomy 07-Q6 chose: `product_groups.parent_product_group_id`, `product_groups_resolved`, and `UNIQUE(parent_product_group_id, name) NULLS NOT DISTINCT` replacing the global name constraint. Copies [08](08-nested-locations.md) almost exactly and is the **second consumer of `hierarchy_depth_limit()`** — not the one it was written generic for. Owns [ADR-0023](../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md)'s decision 1 and the follow-on [03](03-category-min-stock.md) reserved. Migration 0279 reserved. |
| 31 | [Directed substitution](31-directed-substitution.md) | Draft, **blocked on ADR-0023 acceptance**, [issue 125](https://github.com/datagen24/victual/issues/125) | Wave 4. Beans substitute for grounds and never the reverse. Needed because 07-Q6 made those forms **separate products**, and today substitution is only a view over parentage — with no shared parent there is no relation at all. Owns ADR-0023 decision 4. Additive on the reads, so it wants to land before [14](14-contract-and-regression-scaffolding.md) piece 2 freezes the contract. Migration 0280 reserved. |

## Order of operations

The existing sequence is retained below. Each change must be independently mergeable and
meet its plan's verification criteria. Claim migration numbers in
[RESERVATIONS.md](../../migrations/RESERVATIONS.md); lower-numbered migrations merge first.
Recommitted 2026-09-14: every item still in a wave is either **ready** — nothing gates it and
an issue tracks it — or names the single gate in front of it.

| Wave | Work | State on 2026-09-14 |
|---|---|---|
| 0–1 | Scaffolding, security hotfix, platform work | Complete. Details are in the owning plans' Executed sections and the security sweep. |
| 2 | API correctness and authentication | Complete, 2026-09-04. One follow-up is ready: API key expiry and rotation, sweep S11's remaining half — [issue 130](https://github.com/datagen24/victual/issues/130). Q5's schema-derived allowlist waits on 14 piece 2. |
| 2.5 | [24](24-sqlite-runtime-retirement.md): SQLite runtime retirement under ADR-0008 | Complete, 2026-09-05. `DB_DRIVER` accepts `pgsql` alone, the SQLite migration line is frozen at 0265, and fixture-tested imports run from 0255 through it. The differential harness and migrations 0001–0255 stay until 14 piece 2. |
| 3a | 19 piece 1: roles and read gating | Implemented, 2026-09-05; [ADR-0018](../adr/0018-role-grants-and-domain-reads.md), which records the model, accepted 2026-09-14. Price visibility is piece 2, wave 5. |
| 3b | 03 category minimums; 25 label infrastructure and 27 templates/rendering, then 06 location labels; optionally 09 | **Delivered.** 03 complete 2026-09-06. The location-label path — request, physical print, authorized scan back, demonstrated failure and reprint — was accepted on the QL-820NWBc 2026-09-09 and [issue 79](https://github.com/datagen24/victual/issues/79) closed on it; the print actions, the designer (PR 113) and the scan surface (PR 108) are in `master`, and nothing extends the webhook or emits a Grocycode. What the wave still owes, each ready: 25's verification 12 deployment half, [issue 93](https://github.com/datagen24/victual/issues/93), which is [20](20-container-infrastructure.md) piece 4 seen from the other side; 27's designer off fabric 5.x, [issue 126](https://github.com/datagen24/victual/issues/126); sweep S32, [issue 136](https://github.com/datagen24/victual/issues/136); and 06 Q5's tree path on the label, [issue 137](https://github.com/datagen24/victual/issues/137). 09 stays deferred behind its experiment and S14, [issue 80](https://github.com/datagen24/victual/issues/80), both of which can run now. |
| 4 | 08 nested locations; 23 storage classes; 28 and 29; the product hierarchy 07-Q6 chose, **pending ADR-0023's acceptance** | **08 complete, 2026-09-09.** **Ready:** [23](23-storage-classes.md), scheduled into this wave 2026-09-14 because nothing gates it and it precedes 22 — [issue 127](https://github.com/datagen24/victual/issues/127); [29](29-working-container-replenishment.md)'s location-minimum half — [issue 131](https://github.com/datagen24/victual/issues/131). **ADR-0022 accepted 2026-09-14**, so [28](28-open-container-measurement.md), [issue 118](https://github.com/datagen24/victual/issues/118), and 29's weighing half are ready too; 28 and 29 both alter tables 23 touches first, so 23 lands ahead of them. **Gated on ADR-0023's acceptance** ([issue 128](https://github.com/datagen24/victual/issues/128), three prerequisites left): [30](30-nested-product-groups.md), [issue 124](https://github.com/datagen24/victual/issues/124), and [31](31-directed-substitution.md), [issue 125](https://github.com/datagen24/victual/issues/125); the retirement of [07](07-nested-products.md) follows that acceptance in its own pull request, [issue 82](https://github.com/datagen24/victual/issues/82). Two pull requests stand between the record and the work, deliberately separate: one accepting it with bookkeeping only, a later one retiring 07 and scheduling 30 and 31. 28 and 31 want to land before 14 piece 2 freezes the contract. |
| 5 | 14 piece 2 and 19 piece 2; then 02 read-only MCP and 05 A/C | 19 piece 2, [issue 84](https://github.com/datagen24/victual/issues/84), can start now — it precedes the freeze rather than following it. 14 piece 2, [issue 83](https://github.com/datagen24/victual/issues/83), takes the snapshot only after the read surface is complete, which means after 19 piece 2 and after 28 and 31 have landed or been dropped from the wave 4 slate. 02, [issue 86](https://github.com/datagen24/victual/issues/86), and 05 A/C, [issue 85](https://github.com/datagen24/victual/issues/85), follow it; 05 needs a migration number claimed first. MCP uses the calling user's permissions. |
| — | Wave-independent work | Ready now: [15](15-deliberate-cleanup.md)'s remaining cleanup, [issue 132](https://github.com/datagen24/victual/issues/132); [20](20-container-infrastructure.md) pieces 2, 4, 5 and the credential split, [issue 133](https://github.com/datagen24/victual/issues/133); [26](26-documentation-site.md) piece 2, [issue 138](https://github.com/datagen24/victual/issues/138), and ADR-0020's acceptance, [issue 135](https://github.com/datagen24/victual/issues/135); 18's three Home Assistant checks, [issue 139](https://github.com/datagen24/victual/issues/139), which need a running Home Assistant; the undo defect [issue 121](https://github.com/datagen24/victual/issues/121). |

New migrations are PostgreSQL-only: the SQLite line is frozen at 0265 and
`check-migrations.php` refuses a `.sqlite.sql` above it. Migrations 0256–0265 keep the
dual-engine rules they were written under, and the differential harness in `.devtools/pgsql/`
still runs — ADR-0008 keeps it until 14 piece 2's response snapshot replaces it, so SQLite
behaviour the suite compares against is not to be deleted before then.

Swift transport generation follows the API error contract and response snapshot. Swift UI
work follows 19 piece 2, which makes price fields optional. Home Assistant uses 18's MQTT
publication. See [17](17-ecosystem-clients.md) for client contracts and impact requirements.

Unscheduled work includes MCP writes after read-only use is proven, 05 B if shopping trips
justify it, 04's importer and datasets, plan 22 (behind 23 and 14 piece 2), and the two
retirements 24 deferred: archiving migrations 0001–0255, and the differential harness itself,
both of which wait on 14 piece 2. Two label items are deliberate follow-ups toward the
retirement ADR-0011 accepted and are not scheduled: migrating the five entity types that still
print through the webhook, which carries an [ADR-0005](../adr/0005-wire-contract-is-the-invariant.md)
question about what the five `*/printlabel` endpoints return and needs a wire-contract record
of its own, and then deleting `VICTUAL_LABEL_PRINTER_WEBHOOK` with its constants. Observation
proposals (ADR-0012) remain accepted and unbuilt; that acceptance assigns no ownership and no
delivery slot. Interactive current-location scanning, which 06 deferred to a plan after 08, has
no plan yet.

**Label infrastructure is delivered, not merely owned.** [25](25-label-infrastructure.md)
implements ADR-0011 and ADR-0019; [27](27-label-templates-and-rendering.md) implements
ADR-0021, which superseded three boundaries of ADR-0011 — template ownership, "reprint is
resetting a row", and the importer's re-key obligation — so templates are application data, a
reprint replays retained artifact bytes, and an import refuses a target holding live labels.
Both records were accepted 2026-09-07, 0021 first because 0019's ownership model is the one
0021 decides. Schema (0269–0272), routes, the designer, the renderer and worker repositories
pinned into the flake, and the deployment manifests exist; a label printed and scanned back on
2026-09-09. Issue 79 closed on that evidence. Deployment under K3S is the one verification
still open, in issue 93.

**Plan 26 is independent of the wave order**, since it touches no runtime code. Its piece 1,
the developer section of the documentation site, is implemented; its piece 2, the manual, is
new writing covering 81 undocumented pages. It implements
[ADR-0020](../adr/0020-documentation-publication-boundary.md), which is **Proposed** — piece 1
was built first deliberately, because two of that record's four acceptance prerequisites ask
for evidence only a working build can supply, and that evidence now exists.

## Hardening

Use these sources for findings, evidence, and verification details:

- [Architecture review](../architecture-review.md): original defects and hardening work.
- [Security sweep](../security-sweep.md): findings by S-number and remediation history.
- [Architecture rigor review](../architecture-rigor-review.md): documentation and design gaps.
- [Parity suite](../../.devtools/parity/README.md): manual comparison with upstream grocy.
- [Database suite](../../db/pgsql/README.md): engine, view, trigger, and migration checks.

The security sweep's routing is retained here. Partial findings remain open for the work
named in the last column.

| Findings | State | Owner or next work |
|---|---|---|
| S1–S4, S7, S23, S28 | Fixed | Security hotfix. |
| S5, S6, S8, S9, S12, S17–S19, S21, S27 | Fixed | Wave 2, plans 11/15. |
| S10 | Fixed | 01. |
| S25 | Fixed | 10. |
| S29 | Fixed | 12 and 21; `frontend-security` runs the payload probe on pull requests. |
| S11 | Partial | 11: expiry and rotation remain, [issue 130](https://github.com/datagen24/victual/issues/130). 02 must not restore query-string API keys. |
| S16 | Partial | 14 piece 2 / 11 Q5: body-schema validation remains. |
| S13 | Open | 15-C11: remove upstream release/update scripts, [issue 132](https://github.com/datagen24/victual/issues/132). |
| S32 | Open | 27: a fail-closed group-to-read-permission table for the files API, [issue 136](https://github.com/datagen24/victual/issues/136). Closed for the label groups by keeping them out of the `FileGroups` enum; the general finding stands. |
| S14 | Open | 09: barcode filenames, image extensions, and fetch destination restrictions, [issue 80](https://github.com/datagen24/victual/issues/80). Required before any new source, so it can precede the experiment. |
| S15 | Open | 14 piece 2: regex filter bounds. |
| S20, S22, S24, S26 | Open | See sweep; unscheduled. |
| S30, S31 | Open | 19 piece 2, [issue 84](https://github.com/datagen24/victual/issues/84). |
| R1 | Fixed; regression check pending | 14 piece 2: assert `/system/config` retains `FEATURE_FLAG_STOCK`. |

Initial parity findings #44–#48 are fixed or recorded as accepted differences. The parity
suite's accepted-difference records hold the comparison details; it is a manual tool,
not a CI gate.

## Plan conventions

Follow [documentation conventions](../documentation.md). Keep numbered **Open questions**
and their inline `> **Response:**` answers. Preserve a landed plan's proposed design in
its original tense and add delivery details under **Executed**. Include client impact,
verification criteria, and dated, reproducible measurements. Cite code symbols rather
than unstable line numbers.

Architectural choices belong in [ADRs](../adr/README.md). Accepted ADRs constrain plans;
Proposed ADRs must be identified as proposals. Update this table and the work order
together when status or dependencies change.
