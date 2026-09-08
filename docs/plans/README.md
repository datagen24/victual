# Plans

This folder contains research and design documents for major changes to Victual. Each
plan describes the problem, current behavior, proposed scope, constraints, alternatives,
open questions, and verification criteria. It provides the information needed to prepare
an implementation plan; it is not a coding prompt or a session-by-session task list.

Read the [constitution](../constitution.md) and [ADR index](../adr/README.md) first, then
use the status table and work order below to find the relevant plan and its dependencies.
Plan numbers are permanent identifiers, not execution order.

## Status

This table is the authority on delivery status, updated through 2026-09-08. “Landed” means
implemented; outstanding verification and follow-up work are listed separately. A plan's
**Executed** section records what shipped and any differences from the proposed design.

| # | Plan | Status | Dependencies or remaining work |
|---|---|---|---|
| — | [PostgreSQL support](../../db/pgsql/README.md) | Landed | SQLite runtime retirement remains. |
| 01 | [Database file storage](01-file-storage.md) | Landed | PostgreSQL; migration 0258. |
| 02 | [MCP endpoint](02-mcp-endpoint.md) | Draft | Read the [interface spec](../mcp-interface-spec.md), which supersedes the body. Requires 11, 13, 14 piece 2, and 15-C1. |
| 03 | [Category minimum stock](03-category-min-stock.md) | Landed | Wave 3b; PostgreSQL; migration 0268. Note-only shopping list row (Q1) remains a follow-up. |
| 04 | [Seed datasets](04-seed-datasets.md) | Draft, unscheduled | Importer when needed; dataset curation is ongoing. |
| 05 | [Store shopping lists](05-store-shopping-lists.md) | Draft | 12 landed. Parts A/C in wave 5; B depends on usage. |
| 06 | [Location barcodes](06-location-barcodes.md) | Scan surface implemented; printing remains | Wave 3b, [issue 79](https://github.com/datagen24/victual/issues/79). Stateless scan-and-show uses plan 25 group A, merged in PR 107. Print actions, content/placement and physical acceptance remain gated by [#93](https://github.com/datagen24/victual/issues/93): group B jobs/configuration, plan 27 validated artifacts, then group C delivery. ADR-0019 and ADR-0021 are accepted. Interactive current-location scanning remains deferred to a separate plan after 08. |
| 07 | [Nested products](07-nested-products.md) | Blocked on Q6 | Decide taxonomy versus packaging from the real catalogue; 08 precedes packaging hierarchy work. |
| 08 | [Nested locations](08-nested-locations.md) | Draft | 12 and 14's fixture tooling. |
| 09 | [US barcode lookup sources](09-barcode-lookup-sources.md) | Deferred | Q1's kitchen experiment; S14 before adding sources. |
| 10 | [Cold start and statelessness](10-cold-start-statelessness.md) | Landed | Pairs with 01 for a runtime without a persistent volume. |
| 11 | [API errors and authentication](11-api-error-handling.md) | Landed with follow-ups | API key expiry/rotation; Q5's schema-derived allowlist after 14 piece 2. |
| 12 | [Frontend shared core](12-frontend-shared-core.md) | Landed | S29 follow-up fixes and its CI guard landed through 21. |
| 13 | [Write-path transactions](13-write-path-transactions.md) | Landed | See Executed for transaction-nesting behavior. |
| 14 | [Contract and regression scaffolding](14-contract-and-regression-scaffolding.md) | Pieces 1, 3, 4 landed | Piece 2 remains; API read additions and 19's visibility work precede the contract freeze. |
| 15 | [Cleanup](15-deliberate-cleanup.md) | Partly landed | C1, C4, B1, B2 complete; C9 partly complete. C3, C5–C8, remaining C9 sites, C10, C11 remain. |
| 16 | [Project rename](16-project-rename.md) | Code rename landed | Registry/domain claims await announcement. |
| 17 | [Ecosystem clients](17-ecosystem-clients.md) | Ongoing | Q2/Q4 answered; Q1 and part of Q3 remain. Client implementations are outside this repository's wave order. |
| 18 | [MQTT state publication](18-mqtt-state-publication.md) | Landed | Home Assistant checks 2, 4, 8 remain. Includes InfluxDB events through an outbox. |
| 19 | [Roles and data visibility](19-rbac.md) | Piece 1 implemented | Wave 3a: roles and six domain read permissions. Piece 2, including price visibility, remains with 14 piece 2 in wave 5. |
| 20 | [Container infrastructure](20-container-infrastructure.md) | Piece 1 and part of 3 landed | Production Docker target retired. Pieces 2, remaining 3, 4, 5; credential split and SIGTERM verification remain. |
| 21 | [Frontend sink discipline](21-frontend-sink-discipline.md) | Landed | CI payload checks and stored-HTML cleanup on upgrade/import included. |
| 22 | [Medication tracking](22-medication-tracking.md) | Draft, unscheduled | 23 and 14 piece 2; ADR-0015/0016 remain Proposed. Q6's unresolved ownership of label infrastructure is now 25's. Reservations 0272–0273. |
| 23 | [Storage classes](23-storage-classes.md) | Draft, unscheduled | Before 22; interacts with 08. Q1/Q2 answered: derive `is_freezer` in the application. Reservation 0271. |
| 24 | [SQLite runtime retirement](24-sqlite-runtime-retirement.md) | Landed | ADR-0008's retirement work. The differential harness and migrations 0001–0255 stay until 14 piece 2. |
| 25 | [Label infrastructure](25-label-infrastructure.md) | Group A implemented; B/C remain | Wave 3b, [issue 93](https://github.com/datagen24/victual/issues/93); gates 06. Owns ADR-0011's unbuilt machinery and answers ADR-0019's question 1. **Narrowed 2026-09-07 by [ADR-0021](../adr/0021-label-templates-are-application-data.md)**: templates, rendering, previews and artifacts move to [27](27-label-templates-and-rendering.md), so 0270 carries eight tables rather than nine and this plan keeps identity, jobs, printer configuration and delivery. It also owns the import refusal ADR-0021 decision item 3 requires, since `labels` is its table. Gated on ADR-0019, **accepted 2026-09-07** with all five gates met, and on ADR-0021, accepted the same day and before it — **both gates cleared**. Migration 0269, identity, authorized resolution, retirement and import safety are implemented; jobs and delivery remain. Existing entity printing and webhook deletion are ADR-0019 item 7's steps 2–3, deferred; step 2 needs a wire-contract record of its own. |
| 26 | [Documentation site](26-documentation-site.md) | Piece 1 implemented | Wave-independent. Piece 1, the developer section, is built: staging script, MkDocs and Read the Docs configuration, the pinned phpDocumentor reference, and a strict build in the `lint` job. Implements [ADR-0020](../adr/0020-documentation-publication-boundary.md), which is **Proposed**; piece 1 is the evidence its prerequisites 2 and 4 ask for. Piece 2, the manual, waits on Q7's task documentation across 81 pages. |
| 27 | [Label templates and rendering](27-label-templates-and-rendering.md) | Draft | Wave 3b, alongside 25. Owns the browser designer, the headless renderer, previews and print artifacts; 25 keeps identity, jobs, printer configuration and the delivery worker. Gated on [ADR-0021](../adr/0021-label-templates-are-application-data.md), **accepted 2026-09-07** with all six prerequisites met — **gate cleared**. **No tracking issue yet**, unlike 25's #93, and 06 depends on this plan as well as on 25: a print job is not claimable until a validated artifact is attached. Migrations inventoried, not reserved. Owns sweep finding S32. |

## Order of operations

The existing sequence is retained below. Each change must be independently mergeable and
meet its plan's verification criteria. Claim migration numbers in
[RESERVATIONS.md](../../migrations/RESERVATIONS.md); lower-numbered migrations merge first.

| Wave | Work | Dependency or condition |
|---|---|---|
| 0–1 | Scaffolding, security hotfix, platform work | Complete. Details are in the owning plans' Executed sections and the security sweep. |
| 2 | API correctness and authentication | Complete, 2026-09-04, with the follow-ups listed above. |
| 2.5 | [24](24-sqlite-runtime-retirement.md): SQLite runtime retirement under ADR-0008 | Complete, 2026-09-05. `DB_DRIVER` accepts `pgsql` alone, the SQLite migration line is frozen at 0265, and fixture-tested imports run from 0255 through it. The separately planned production Docker retirement was already complete. |
| 3a | 19 piece 1: roles and read gating | Implemented, 2026-09-05. Six view permissions, existing-user backfill, four seed roles, role APIs/UI and the resolved permissions endpoint. Price visibility remains in wave 5. |
| 3b | 03 category minimums; **25 label infrastructure and 27 templates/rendering, then 06 location labels**; optionally 09 | 03 complete, 2026-09-06: migration 0268, the `product_groups_missing` read entity under `STOCK_VIEW`, and a `groupminstock` suite phase. **06 was rescoped 2026-09-06**: it cannot ship a print action over machinery ADR-0011 decided and nobody built, so 25 was created to own that machinery and 06 now depends on its first usable release. The wave delivers a *complete* location-label path — request, physical print, authorized scan back, demonstrated failure and reprint — not a table and a button. 09 joins only after its experiment and S14 work. Shared route/spec edits need coordination — 03 took the `ExposedEntity` enums and 25 needs them too. **Split 2026-09-07 by [ADR-0021](../adr/0021-label-templates-are-application-data.md)**: templates, rendering, previews and artifacts are [27](27-label-templates-and-rendering.md)'s, 25 keeps identity, jobs, printer configuration and delivery, and 06 is unchanged. Both gating records were accepted 2026-09-07, 0021 first. Plan 25 group A is implemented; its jobs and delivery and plan 27 remain ahead of 06's print actions and physical acceptance; 06's stateless scan surface is implemented over group A. |
| 4 | 08 nested locations, then the hierarchy selected by 07-Q6 | Taxonomy means an additive parent-group change after 03; packaging means 07 after 08 has been used. Resolve Q6 before scheduling the product work. |
| 5 | 14 piece 2 and 19 piece 2; then 02 read-only MCP and 05 A/C | Complete missing API reads and price-visibility checks before freezing the contract. MCP uses the calling user's permissions. |

New migrations are PostgreSQL-only: the SQLite line is frozen at 0265 and
`check-migrations.php` refuses a `.sqlite.sql` above it. Migrations 0256–0265 keep the
dual-engine rules they were written under, and the differential harness in `.devtools/pgsql/`
still runs — ADR-0008 keeps it until 14 piece 2's response snapshot replaces it, so SQLite
behaviour the suite compares against is not to be deleted before then.

Swift transport generation follows the API error contract and response snapshot. Swift UI
work follows 19 piece 2, which makes price fields optional. Home Assistant uses 18's MQTT
publication. See [17](17-ecosystem-clients.md) for client contracts and impact requirements.

Unscheduled work includes MCP writes after read-only use is proven, 05 B if shopping trips
justify it, 04's importer and datasets, remaining container work, plans 22/23/26, and the two
retirements 24 deferred: archiving migrations 0001–0255, and the differential harness itself,
both of which wait on 14 piece 2.

**Opaque label infrastructure now has an owner and a slot**: [25](25-label-infrastructure.md)
implements ADR-0011 in wave 3b. Two things it deliberately does not do stay unscheduled —
migrating the five entity types that already print through the webhook, and deleting
`VICTUAL_LABEL_PRINTER_WEBHOOK` with its constants. Both are explicit follow-up work toward
the retirement ADR-0011 already accepted, and the second carries an
[ADR-0005](../adr/0005-wire-contract-is-the-invariant.md) question about what the five
`*/printlabel` endpoints return. Observation proposals (ADR-0012) remain accepted and unbuilt;
that acceptance still assigns no ownership and no delivery slot.

**And a second owner beside it, 2026-09-07.**
[ADR-0021](../adr/0021-label-templates-are-application-data.md) supersedes three boundaries of
accepted ADR-0011 — template ownership, "reprint is resetting a row", and the importer's re-key
obligation, which no source `bin/victual-db-import` accepts can discharge. Templates become
application data, a reprint replays retained artifact bytes, and an import refuses a target
holding live labels. [27](27-label-templates-and-rendering.md) owns the designer, the renderer,
previews and artifacts; 25 keeps identity, jobs, printer configuration and delivery, and also
owns the import refusal because `labels` is its table. 27's migrations are inventoried rather
than reserved — 0271–0273 are still plans 23 and 22's, and the branch that writes the first file
applies the lowest-free-slot rule.

**Both records were accepted 2026-09-07**, 0021 first because 0019's ownership model is the one
0021 decides, each on its own bookkeeping-only pull request. All five of 0019's gates and all
six of 0021's prerequisites were met, with physical evidence on both printers this deployment
has. **That authorizes the work; it does not do it.** No schema, no route and no UI exists
under 25 or 27, every gate run was a disposable spike, and
[issue 79](https://github.com/datagen24/victual/issues/79) stays open behind all of it — see
that plan's *Where issue 79 stands* for the chain. Two gaps worth naming: **27 has no tracking
issue** while 25 has #93, and 27 sits on 06's critical path because a print job is not claimable
until a validated artifact is attached.

**Plan 26 is independent of the wave order**, since it touches no runtime code. Its piece 1,
the developer section of the documentation site, is implemented; its piece 2, the manual, waits
on new writing covering 81 undocumented pages. It implements
[ADR-0020](../adr/0020-documentation-publication-boundary.md), which is **Proposed** — piece 1
was built first deliberately, because two of that record's four acceptance prerequisites ask
for evidence only a working build can supply.

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
| S11 | Partial | 11: expiry and rotation remain. 02 must not restore query-string API keys. |
| S16 | Partial | 14 piece 2 / 11 Q5: body-schema validation remains. |
| S13 | Open | 15-C11: remove upstream release/update scripts. |
| S32 | Open | 27: a fail-closed group-to-read-permission table for the files API. |
| S14 | Open | 09: barcode filenames, image extensions, and fetch destination restrictions. |
| S15 | Open | 14 piece 2: regex filter bounds. |
| S20, S22, S24, S26 | Open | See sweep; unscheduled. |
| S30, S31 | Open | 19. |
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
