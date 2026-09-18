# Memory Index

> Auto-loaded at session start by `.claude/hooks/auto_orient.py`. The injector truncates at
> **8000 bytes** (`AUTO_ORIENT_MAX_BYTES`), at a line boundary — so this file stays short and
> keeps detail in the linked topic files. Everything below the cap is silently dropped.

## What memory is for here

This repository already has a documentation corpus that is the authority on the project:
[AGENTS.md](../AGENTS.md) for ground rules, [docs/constitution.md](../docs/constitution.md)
for standing principles, [docs/adr/README.md](../docs/adr/README.md) for decisions in force,
[docs/plans/README.md](../docs/plans/README.md) for what work exists and what gates it.

**Memory does not restate any of that** — it would drift, and the corpus would still be
right. Memory carries the three things the corpus does not: how this machine actually runs
the suites, what verification counts as evidence, and where the last session left off.

When memory and the corpus disagree, the corpus wins and the memory file is wrong: fix it.

## Verification protocol (the Stop hook enforces this)

`.claude/hooks/claim_check_hook.py` blocks or warns at turn end when a reply claims
done / shipped / verified / fixed / complete without a fresh verification entry. After you
actually verify something, log it:

```bash
python3 .claude/hooks/log_claim.py "<what you claim>" "<how you verified it>"
```

The entry must name a command that ran and what it returned. See
[feedback_verification_discipline.md](feedback_verification_discipline.md) for what counts.

## File conventions

Memory files live in this directory, prefix-typed:

| Prefix | Contents | Lifetime |
|---|---|---|
| `project_*` | Active work, session logs, in-flight state | Days to months |
| `feedback_*` | Operator decisions, locked doctrine, lessons learned | Long-lived |
| `reference_*` | Canonical patterns, playbooks, how-to | Long-lived |
| `user_*` | Operator identity, preferences, focus | Long-lived |

Frontmatter on every file:

```markdown
---
name: <short title>
description: <one line, used to decide relevance in a future session>
type: <project | feedback | reference | user>
---
```

Bodies of `feedback_*` and `project_*` files carry **Why** and **How to apply** lines, and
link related files as `[[name]]`. Facts that were measured name the date and how to
reproduce them, as [docs/documentation.md](../docs/documentation.md) requires of records.

## RECENT SESSIONS

<newest first; keep five. Concurrent branches both add a line here — on conflict keep both.>

- **2026-09-18 — Plan 05 parts A/C landed**, issue #85: `migrations/0286.pgsql.sql` —
  three nullable columns (`shopping_lists.shopping_location_id`,
  `products.default_shopping_list_id`, `recipes.default_shopping_list_id`), no defaults,
  no foreign keys, PostgreSQL-only above the freeze exactly as the plan specified.
  **Deliberately did NOT re-issue `products_view`/`shopping_lists_view`**: both flatten
  `p.*`/`sl.*` at `CREATE VIEW` time, and migration 0276 already hit and documented the
  failure mode (`CREATE OR REPLACE VIEW` refuses to reposition an existing output column) —
  the generic API is unaffected either way since `GenericEntityApiController` reads the
  base tables directly. Added `default_shopping_list_id` to the `Product`/
  `ProductWithoutUserfields` OpenAPI schemas (no schema exists for `recipes`/
  `shopping_lists` to extend) and regenerated `tests/Pgsql/snapshots/contract-{admin,
  restricted}.json` per ADR-0024 decision 1 — diff is exactly the three new fields,
  identical on both sweeps (no sensitive-vocabulary match). New tier-1 test
  `tests/Pgsql/ShoppingListStoresTest.php` (`run-tests.sh shopliststores`, wired into
  `phpunit.xml` and the `all` target) round-trips all three columns through the real API
  and pins the view non-reissue as a test, not just a comment. Verified against real
  PostgreSQL 16.13 in podman (`docker build --target dev`, stock `postgres:16` +
  `apt-get install postgresql-16-pgtap`): `run-tests.sh all` (25 phases,
  `SUITE_ALLOW_RESERVED_HOLES=1` for still-unwritten 0284/0285) ends `SUITE PASSED`. Next
  unclaimed migration: 0287. Wave 5 remaining: 20's pieces (#133), then 02 (#86), then 18's
  HA checks (#139).
- **2026-09-18 — Wave 5 order set**: 05 A/C (0286 claimed, snapshot regenerates with it)
  and 20's remaining pieces (#133) first because they change responses and deployment;
  then 02 (#86); then 18's HA checks (#139). Found 14 piece 2 had landed 2026-09-17
  (`fb97824`, `ContractTest.php`) with #83 still open and plan 14's status line stale —
  closed and fixed. Next unclaimed migration (at the time): 0287, since claimed and landed
  by the entry above.
- **2026-09-17 — Issue #83 / plan 14 piece 2 landed** (branch `claude/nifty-fermat-g3mffe`),
  the largest remaining item in wave 5: the response-contract snapshot, `tests/Pgsql/ContractTest.php`
  on `PgsqlSchemaTestCase` per ADR-0025 decision 4, a `contract` phase in `run-tests.sh`/`phpunit.xml`
  beside `rbac`. `tests/Support/RouteInventory.php` boots `routes.php` for the live Slim route
  table (the `.devtools/check-path-id-validation.php` pattern, generalised) rather than a second
  regex extractor; `tests/Support/JsonShape.php` turns a decoded JSON value into its key-set-and-
  scalar-type shape, list items collapsed to one merged representative element. One fixture graph
  built as Admin through the real write endpoints (stock add/consume/transfer/inventory/open/
  measure/weigh/merge by id and by barcode, recipes, chores, batteries, tasks, users/roles, a file
  round trip, the generic `/objects/{entity}` sweep over every non-label `ExposedEntity`) is
  snapshotted, then re-swept as the **existing CHILD role** (not a new fixture — CHILD already
  holds the STOCK leaves/not-STOCK shape the plan asks for) for a restricted comparison. Four
  comparison legs, not the plan's five: "engine vs engine" is moot since ADR-0008 retired SQLite
  as a runtime, so only one engine boots the app. The redaction leg computes expected-redacted
  fields from the live `permission_fields` table (never hand-maintained, per `FieldPolicy`'s own
  docblock) and was verified the mutation-shaped way — `FieldPolicy::RedactRow`'s loop body
  replaced with `foreach ([] as $field)`, confirmed the leg named exactly the nine leaked fields,
  reverted. Two real defects found and fixed on the way, on a route nothing had called with real
  event data before: `CalendarApiController::Ical` called `$response->write()` (not a
  `ResponseInterface` method) and hand the iCal library's `Presentation\Component` object to
  `getBody()->write()` unstringified. Spec fixes landed with the parity assertion:
  `/api/openapi/specification` added to `victual.openapi.json` (the one real gap plan 14 found
  by hand), `info.version` `"xxx"` → `version.json`'s `4.6.0`, and `ProductPriceHistory`'s schema
  description corrected (claimed "see x-visibility on the operation"; the operation carries none,
  only the schema does — fixed the sentence to match the code rather than the code to match a
  stale sentence). **Not done, contrary to the issue's own text**: S15 (regex filter bounds) and
  S16's remaining half (11's Q5, a schema-derived write allowlist) are separately-scoped input-
  validation work, not response-contract testing, and stayed out on purpose — recorded as such in
  security-sweep.md and plan 14's own Executed section rather than silently left implied-closed.
  Full account: [plan 14's Executed section](../docs/plans/14-contract-and-regression-scaffolding.md#executed).
  Next: retiring the SQLite differential harness (unblocked by this landing, not performed by
  it), plan 22, issue 192's remaining items.
- **2026-09-17 — ADR-0025 accepted** (bookkeeping PR after PR #194's spikes): status line
  annotates each prerequisite with what met it and records three edges honestly — the
  ported phase migrates its own schema (decision 3 addendum), the extension lives in the
  compose PostgreSQL image not the dev image, and `check-pgtap-coverage.php` is proven but
  not gating CI until the sixteen pre-pgTAP names are listed (issue 192's ratchet shape).
  Next: #83 in PHPUnit, #192 items 1–2, the ratchet.
- **2026-09-17 — ADR-0025's five acceptance spikes executed** (branch
  `claude/adr-0025-spike-execution-893row`), spike 2 first as the record requires since it
  gates the other four. **Spike 1**: `composer require --dev phpunit/phpunit` resolved
  `11.5.56` against the `php: ^8.4.1` floor with no `php-code-coverage ^11.0` conflict.
  **Spike 2 (the gate, passed)**: `.devtools/pgsql/rbac-tests.php` ported to
  `tests/Pgsql/RbacTest.php`, a new `Victual\Tests\Support\PgsqlSchemaTestCase` giving
  each PHPUnit class its own PostgreSQL schema (migrated in-process via
  `DatabaseMigrationService::MigrateDatabase()`) with `DatabaseService`'s singleton
  reflection-injected onto it — the label suites' own injection pattern, extended to the
  real migration path. Five identity-fixed scenarios (default roles, the two own-picture
  exceptions) still spawn their own process via a new
  `tests/Pgsql/rbac-subprocess-helper.php`, attaching to the class's schema by env var.
  `run-tests.sh rbac` now runs `phpunit --testsuite rbac`; per-class coverage is identical
  or higher on every RBAC class, three points lower only in bootstrap plumbing the
  schema-per-class design no longer runs as a separate process (`.spike-adr25/RESULTS.md`
  has the full diff). **Spike 3**: pgTAP installs into the stock `postgres:16` image and
  `pg_prove` into the stock dev image with a plain `apt-get install` each side - no custom
  base image; wired into `tests.yml` (`docker exec` into the running service container)
  and `docker-compose.yml` (`postgres` now builds `.devtools/pgtap/postgres.Dockerfile`).
  **Spike 4**: `.devtools/pgtap/010-locations-trigger-family.sql`, 8 pgTAP assertions
  against migrations 0269/0273's real trigger family (self-parent, cycle, the six-node
  depth limit, the child guard, the retirement snapshot), all against a fully migrated
  database. **Spike 5**: `.devtools/pgtap/check-pgtap-coverage.php` (the shape of
  `check-migrations.php`) parses `.devtools/pgtap/README.md`'s table against every
  migration above the SQLite baseline; proven against the real tree's own backlog (16
  unlisted names across 5 migrations) rather than a synthetic fixture. **Not done**:
  wiring `check-pgtap-coverage.php` into CI (would fail on those 16 pre-existing names;
  tracked in the README as future work, same ratchet-then-gate shape as issue 192) and
  porting the other four bespoke phases to PHPUnit (this record's decision 3 - one at a
  time, `run-tests.sh` stays the entry point). PR not yet opened as of this entry.


## DOCTRINE (operator-locked decisions)

- [Verification discipline](feedback_verification_discipline.md) — "it loads" is not
  evidence; which suite answers which question; the parity suite is not a CI gate.

## REFERENCE PATTERNS

- [Local environment](reference_local_environment.md) — running the three suites on this
  Apple Silicon Mac: podman directly (the documented `docker compose` invocation is broken
  here), the frontend probes' yarn/Playwright prerequisites, the Nix build, git signing.
- [DEVONthink index](reference_devonthink_index.md) — `~/src/grocy` is indexed into the
  `Code-Projects` database, so full-text and proximity search spans the code and the docs
  corpus at once. It indexes **master's tree only, never a worktree** — find things with it,
  verify nothing with it.

## USER PROFILE

- [Operator profile](user_profile.md) — datagen24, sole maintainer; BLUF replies; the
  deployment target and the machine the work happens on.

## ACTIVE PROJECT STATE

- [Project state](project_state.md) — pointer to the authorities plus what is in flight
  that no plan row states yet.

## Archive

When this index passes ~150 lines, move superseded entries to
`archive/MEMORY_pre_consolidation_YYYYMMDD.md` and leave a pointer here.
