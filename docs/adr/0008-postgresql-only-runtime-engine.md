# ADR-0008: PostgreSQL becomes the only runtime engine; SQLite becomes an import format

- **Status: Accepted, 2026-08-31.** **Supersedes
  [ADR-0001](0001-postgresql-alongside-sqlite.md).** How each acceptance gate was met:
  the supported import span is stated in this record (open question 1, answered at
  acceptance; end fixtures land with the retirement PR per the amended gate), and the
  differential harness in `.devtools/pgsql/` stays until
  [14](../plans/landed/14-contract-and-regression-scaffolding.md) piece 2 exists. The
  retirement work itself is not yet scheduled in the roadmap's wave order.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30.
- **Relationship:** [ADR-0009](0009-database-as-the-logic-layer.md) is not viable unless
  this is accepted. This one is defensible on its own and should be judged on its own.
- **Would affect:** [10](../plans/landed/10-cold-start-statelessness.md),
  [01](../plans/landed/01-file-storage.md), [07](../plans/retired/07-nested-products.md),
  [08](../plans/landed/08-nested-locations.md),
  [14](../plans/landed/14-contract-and-regression-scaffolding.md),
  [15](../plans/landed/15-deliberate-cleanup.md).

## Context

[ADR-0001](0001-postgresql-alongside-sqlite.md) bought dual-engine support and, with it, a
standing per-change tax. Measured against the tree on 2026-08-30:

| | |
|---|---|
| Views maintained on both engines | **45** (`db/pgsql/baseline/0[345]_views_*.sql`) |
| Triggers maintained on both engines | **55**, as 21 PL/pgSQL functions plus their triggers |
| Baseline DDL | **4,893 lines** across 13 files |
| Differential test tooling | **2,086 lines** in `.devtools/pgsql/`, four phases, 5 view-test and 8 trigger-test fixture sets |
| Dialect layer | **1,692 lines** in `services/Database/` |
| Documented porting hazards | **17**, plus 2 accepted differences ([ADR-0005](0005-wire-contract-is-the-invariant.md)) |
| Migration files | **257**, of which 0001–0255 are stood in for by the baseline |
| CI | both `pdo_sqlite` and `pdo_pgsql` installed; `postgres:16` service container |

Every new view is written twice and proved equivalent. Every migration from 0256 on is
portable or a matched pair, with the marker discipline of
[ADR-0004](0004-engine-specific-migrations.md) and a `check-migrations.php` guard for when
someone forgets. That machinery is good — it was built because the hazards are real — but
it is a tax on exactly the kind of change this fork keeps making, and the deployment
target has been PostgreSQL-only since the fork began.

## Decision

`DB_DRIVER` stops accepting `sqlite`. **PostgreSQL becomes the sole runtime and the sole
behavioural authority.** SQLite survives only as an external input format.

What that requires is narrower than it first appears, and an earlier draft of this record
got it wrong by conflating two different things:

1. **A supported upstream range for the importer, stated as a migration number span.**
   `bin/victual-db-import` accepts grocy/Victual SQLite within that range and refuses
   anything outside it with a message naming both numbers.
2. **Fixture-based importer tests.** Committed SQLite fixtures at the supported schema
   versions, each with an assertion on the PostgreSQL result of importing it. This is what
   keeps the importer honest once no engine in this repository produces its input.

**What it does not require is continued behavioural equivalence with SQLite.** Once
PostgreSQL is the only runtime, there is no second engine for it to agree with, and
"behaves like grocy" stops being a property this project needs to hold — its contract is
its own OpenAPI spec, frozen by
[14](../plans/landed/14-contract-and-regression-scaffolding.md) piece 2. The differential harness
in `.devtools/pgsql/` may be kept **during the transition**, as a check that the retirement
itself changed nothing, and retired afterwards. It is a migration aid, not a permanent
architectural requirement.

## Options considered

**A. Keep both engines.** Status quo. The tax is real but paid in small increments, and
small increments feel worse in aggregate than they are per change. Costs: everything below
stays true, and every ceiling in *Consequences* stays in place.

**B. Retire the runtime engine and the differential harness together, immediately.**
Cleanest deletion. Rejected only on sequencing: it discards, in the same change, the one
tool that could show the retirement was behaviour-preserving. The end state is right; the
ordering is not.

**C. Retire the runtime engine; keep the harness through the transition; add fixture-based
importer tests.** The proposal. Reaches B's end state with a check on the step itself, and
leaves behind the narrower thing the importer actually needs.

**D. Retire the runtime engine but keep the differential harness permanently.** Considered
and rejected — it was this record's first draft. It sounds conservative and is not: it
holds the project to conformance with an engine it no longer runs, which would make every
future PostgreSQL-only improvement look like a regression against a standard nobody
chose. [ADR-0005](0005-wire-contract-is-the-invariant.md) is enforced going forward by
14's contract snapshot, not by SQLite.

## Consequences

### What it buys

**Plan 10 gets shorter, and in places turns from "make it conditional" into "delete it."**

| [Plan 10](../plans/landed/10-cold-start-statelessness.md) item | Under two engines | Under one |
|---|---|---|
| `PrerequisiteChecker` opening `sqlite::memory:` every request | Make it driver-aware | Delete the branch |
| `pdo_sqlite` in the image | Conditional | Gone |
| Q3, where the SQLite `flock` lock file lives | A real question | Moot |
| Q6's boot check | Must be dialect-aware | One number, one comparison |
| Q7's `dialect` column | A migration and a backfill policy | Unnecessary |
| `DatabaseDialect::WithMigrationLock` | Two implementations | One |
| Q4's `MIGRATE_ON_ROOT_REQUEST` | Still wanted | Still wanted, unchanged |

**`SqliteDialect` shrinks rather than vanishing.** `bin/victual-db-import` still reads
SQLite, so the type-coercion knowledge in hazards 1–14 survives — read-only, one
direction, no write counterpart, no equivalence proof. Hazards 15 (`COLLATE NOCASE`
written into the PHP), 16 (`LIKE` case sensitivity) and 17 (LessQL identifier quoting) are
the ones that genuinely die, because all three are about the same code having to behave on
both engines.

**Migrations 0001–0255 become history that never executes anywhere** and can be archived
rather than maintained. The baseline stops being a stand-in and becomes the schema.

**The ceiling comes off.** Recursive CTEs with known semantics for
[07](../plans/retired/07-nested-products.md) and [08](../plans/landed/08-nested-locations.md) — which the
roadmap notes neither plan has ever exercised through the suite. `bytea` decided on its
merits for [01](../plans/landed/01-file-storage.md) rather than as half of a portable pair. And
everything in [ADR-0009](0009-database-as-the-logic-layer.md), which is not seriously
available while this record is unaccepted.

### What it costs

**Enforcement of [ADR-0005](0005-wire-contract-is-the-invariant.md) has to transfer, and
until it does there is a gap.** Today the differential suite is what makes the wire
contract testable rather than aspirational — `difftest.php` puts both engines into an
identical table state and compares what the views return. That mechanism disappears with
the second engine. The replacement is
[14](../plans/landed/14-contract-and-regression-scaffolding.md) piece 2's response snapshot,
which is the right mechanism and is **outstanding**. So the ordering constraint is real
even though the permanent requirement is not: **do not retire the harness before 14 piece
2 exists**, or the fork spends a window with neither check. Keeping the harness through
the transition (option C) is what covers that window.

This also reframes what the harness was ever for. It proved two engines agreed. It never
proved the fork's own contract was stable over time — 14 does that, for a single engine,
and would be needed even if SQLite stayed.

**Upstream cherry-picks get harder.** Grocy's logic is PHP over SQLite views. The fork has
already accepted drift, but this widens it from "our schema is grocy's, typed" to "our
schema is ours."

**The importer needs a pinned understanding of a schema nobody here runs any more.** Today
`bin/victual-db-import` is exercised against a database this repository produces.
Afterwards it reads a format only *other* installations produce, drifting on someone
else's schedule. Mitigation: the importer supports grocy/Victual SQLite at a stated
migration number, checked against a committed fixture, and says so when handed something
newer.

**Dev ergonomics.** `run-app` and the demo mode boot on SQLite today, and a one-file dev
database is genuinely nicer than a container. Real loss, small — the compose file exists
and CI already runs `postgres:16`.

## Acceptance prerequisites

Gates, not suggestions. The accepting pull request says how each was met.

- **The supported upstream migration range is stated as a number span** in this
  record at acceptance. The fixtures that cover its ends land with the retirement PR
  itself, before any dual-engine machinery is removed — *amended 2026-08-31; the
  original gate required the fixtures committed before acceptance.* The reasoning for
  the amendment, made in the open rather than dropped on the way through: the fixtures
  guard the importer, and the importer does not change until the retirement PR, so
  their absence gates that PR rather than this decision. Stating the span is the
  decision-shaped half and remains an acceptance gate.
- **[14](../plans/landed/14-contract-and-regression-scaffolding.md) piece 2 exists**, or the
  accepting PR states explicitly that the differential harness stays until it does. This
  is the enforcement-transfer gap above and is the one ordering constraint this record
  has.

## Open questions

1. **What is the supported upstream range, and what does the importer do outside it?**
   *Lean: refuse with a message naming both numbers rather than attempting a best-effort
   import — an importer that half-works on an unknown schema is worse than one that
   declines. The lower bound is the harder half: grocy databases in the wild are older
   than this fork, and "as far back as we have a fixture for" is an honest answer where
   "all of them" is not.*

   **Answered 2026-08-31, at acceptance.** The span is **0255 — the fork's squashed
   baseline — through the SQLite dialect's latest migration number at retirement time**,
   frozen thereafter. Refusal outside the span names both numbers, per the lean. The
   lower bound is honest rather than generous by design: any wild grocy 4.x install
   reaches 0255 by booting upstream grocy once, so the narrow span costs an adopter one
   boot of the software they are leaving rather than costing this fork an import surface
   across every historical schema delta.
2. **How many fixtures, and how are they generated?** A fixture per supported version is
   the thorough answer and the expensive one. *Lean: two — the oldest supported and the
   current — on the grounds that the importer's failure modes are schema-shaped rather
   than version-shaped. Unverified; a spike on the actual schema deltas would settle it.*
3. **What happens to `run-app` and the demo mode?** *Lean: they move to the compose
   Postgres, costing a slower first boot and nothing else. Worth confirming rather than
   assuming — a demo that needs a container is a different thing from a demo that needs a
   file.*
4. **Which parts of `.devtools/pgsql/` survive the transition, and for how long?**
   `difftest.php` and `trigdifftest.php` populate PostgreSQL by copying an
   already-migrated SQLite database, so they can run for as long as a fixture exists.
   `migratedifftest.php` migrates *both* sides from nothing, so it needs a live SQLite
   migration path and is the phase that does not survive retirement — and it is the phase
   that caught the missing seed data. *Lean: accept losing it, because what it protects
   (the baseline agreeing with the history it stands in for) stops being a live risk once
   the history is no longer replayed anywhere.* Worth stating in the accepting PR rather
   than discovering.

## Research

- **`pg_cron` and other extensions are not the argument here** — they belong to
  [ADR-0009](0009-database-as-the-logic-layer.md). This record stands on the authoring tax
  and the plan-10 simplification alone.
- Tree measurements above are reproducible with `grep` against `db/pgsql/baseline/`,
  `.devtools/pgsql/` and `services/Database/`, on the working copy of 2026-08-30.
