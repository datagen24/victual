# Plans

This folder contains research and design documents for major changes to Victual. Each
plan describes the problem, current behavior, proposed scope, constraints, alternatives,
open questions, and verification criteria. It provides the information needed to prepare
an implementation plan; it is not a coding prompt or a session-by-session task list.

Read the [constitution](../constitution.md) and [ADR index](../adr/README.md) first, then
use the status table and work order below to find the relevant plan and its dependencies.
Plan numbers are permanent identifiers, not execution order.

## Status

This table is the authority on delivery status, updated through 2026-09-05. “Landed” means
implemented; outstanding verification and follow-up work are listed separately. A plan's
**Executed** section records what shipped and any differences from the proposed design.

| # | Plan | Status | Dependencies or remaining work |
|---|---|---|---|
| — | [PostgreSQL support](../../db/pgsql/README.md) | Landed | SQLite runtime retirement remains. |
| 01 | [Database file storage](01-file-storage.md) | Landed | PostgreSQL; migration 0258. |
| 02 | [MCP endpoint](02-mcp-endpoint.md) | Draft | Read the [interface spec](../mcp-interface-spec.md), which supersedes the body. Requires 11, 13, 14 piece 2, and 15-C1. |
| 03 | [Category minimum stock](03-category-min-stock.md) | Draft | Wave 3b; independent of 07-Q6. |
| 04 | [Seed datasets](04-seed-datasets.md) | Draft, unscheduled | Importer when needed; dataset curation is ongoing. |
| 05 | [Store shopping lists](05-store-shopping-lists.md) | Draft | 12 landed. Parts A/C in wave 5; B depends on usage. |
| 06 | [Location barcodes](06-location-barcodes.md) | Draft | 12 landed; constrained by accepted ADR-0011. Interactive current-location scanning is deferred to a separate plan after 08. |
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
| 22 | [Medication tracking](22-medication-tracking.md) | Draft, unscheduled | 23 and 14 piece 2; ADR-0015/0016 remain Proposed. Q6 leaves ownership of label infrastructure unresolved. Reservations 0268–0269. |
| 23 | [Storage classes](23-storage-classes.md) | Draft, unscheduled | Before 22; interacts with 08. Q1/Q2 answered: derive `is_freezer` in the application. Reservation 0267. |
| 24 | [SQLite runtime retirement](24-sqlite-runtime-retirement.md) | Landed | ADR-0008's retirement work. The differential harness and migrations 0001–0255 stay until 14 piece 2. |

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
| 3b | 03 category minimums; 06 location labels; optionally 09 | New reads use 3a's permissions. 09 joins only after its experiment and S14 work. Shared route/spec edits need coordination. |
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
justify it, 04's importer and datasets, remaining container work, plans 22/23, and the two
retirements 24 deferred: archiving migrations 0001–0255, and the differential harness itself,
both of which wait on 14 piece 2. Opaque
label infrastructure (ADR-0011) and observation proposals (ADR-0012) are accepted but
unbuilt; acceptance does not assign implementation ownership or a delivery slot.

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
