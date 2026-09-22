# ADR-0025: Three test tiers — PHPUnit against a real PostgreSQL, pgTAP for the SQL logic, probes for the browser

- **Status: Accepted, 2026-09-17.** **Three test tiers: PHPUnit against a real PostgreSQL
  for application code, pgTAP for the SQL logic, the browser probes as they are.** All five
  acceptance prerequisites below are met, each annotated in place with what met it; the
  evidence is [`.spike-adr25/RESULTS.md`](../../.spike-adr25/RESULTS.md), merged as
  [PR #194](https://github.com/datagen24/victual/pull/194), and the spikes' products
  (`tests/Support/PgsqlSchemaTestCase.php`, `tests/Pgsql/RbacTest.php`,
  `.devtools/pgtap/`, the `pgtap` phase of `run-tests.sh`, `phpunit/phpunit ^11.5` in
  `composer.json`) are in `master`. **Nothing in the decision was revised by this
  acceptance.** Three edges the spikes reported are recorded here rather than smoothed
  over. Decision 3's "nothing else changes" needed one addendum for a ported phase: its
  database is migrated by the test class, not by `build_pgsql()`, which follows from
  decision 2. Decision 6's "the dev image carries it" was met with `pg_prove` in the dev
  image and the extension in the compose-built PostgreSQL image, since the dev image never
  runs a server. Decision 5's CI check exists and is proven but is **not yet wired
  into CI**, because sixteen functions and triggers predate pgTAP and would fail every
  build. `.devtools/pgtap/README.md`'s "What is not covered yet" names them, and
  [issue 192](https://github.com/datagen24/victual/issues/192)'s ratchet-then-gate shape
  is the path to turning it on. Written 2026-09-17 for the coverage floor the maintainer
  set the same day (`docs/constitution.md`, standing invariants; issue 192 holds the
  backlog).
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-17.
- **Relationship:** builds on [ADR-0008](0008-postgresql-only-runtime-engine.md), whose
  option C keeps the differential harness only until
  [plan 14](../plans/landed/14-contract-and-regression-scaffolding.md) piece 2 replaces it; this
  record says what replaces it *with*. Does not touch
  [ADR-0005](0005-wire-contract-is-the-invariant.md): the response snapshot stays the wire
  contract's enforcement, and gains a runner. The constitution's verification bar — a
  booted instance against a real database, never a lint pass — is the constraint every
  tier below is shaped by.
- **Referenced by:** [issue 192](https://github.com/datagen24/victual/issues/192), whose
  mechanics items become this record's first plan once accepted;
  [14](../plans/landed/14-contract-and-regression-scaffolding.md) piece 2, whose harness is written
  in tier 1 from the start; [.devtools/coverage/README.md](../../.devtools/coverage/README.md).

## Context

The fork has no unit-test tier and never has. What it has is a bespoke harness:
`.devtools/pgsql/run-tests.sh` runs 23 phases, 24 PHP scripts, each an integration test
against a real PostgreSQL in a throwaway schema, eleven of them carrying their own `check()`
function, plus the label suites and the frontend probes `tests.yml` runs as separate
steps. Coverage is measured by pcov through `auto_prepend_file` on every process the run
spawns (`.devtools/coverage/`), and until 2026-09-17 nothing was gated on the number.

On 2026-09-17 the maintainer set a floor: **75%** line coverage of application code, **85 or
better** the target, **90** the ideal. Master stands at 37.81% across the 69 classes the
suite loads; 42 of them are below the floor and 2786 uncovered lines sit in those 42.

The coverage README's own explanation is that three of the four differential phases drive
SQL at the engines and barely enter PHP, so most controllers are near zero by design. That
was an acceptable reading while nothing was gated. Closing a gap that size with 25th, 26th
and 27th bespoke scripts, each with its own `check()`, is the wrong tool. The scripts have
no shared fixtures, no per-test isolation, no filtering, and no way to report which
assertion failed beyond what each author wrote by hand.

Two things about this codebase shape the choice of tools:

- **Static state.** 64 files use the `GetInstance()` singleton, and the only existing test
  that injects a connection does it through `ReflectionProperty` on `DatabaseService`
  (`.devtools/labels/identity-tests.php`). Mock-heavy unit tests would fight that in every
  file; tests against a real database do not.
- **Most of the fork's logic is in PostgreSQL.** Across the baseline and migrations 0256
  onward there are 58 functions, 47 triggers and 60 views, and the fork's own work is where
  they concentrate: nesting and depth checks, label retirement, the permission tree,
  `locations_resolved`. Today they are tested indirectly — `trigdifftest.php` compares
  trigger behaviour against SQLite over the frozen range, and retires with plan 14 piece 2 —
  and a line-coverage floor on PHP says nothing about them at all.

The maintainer's own instinct, raised while reviewing the backlog: test-driven development
was used on new codebases and is not obviously right for an existing one; PHPUnit looks
right for PHP; pgTAP looks right for the PostgreSQL logic. This record checks each of those
against the tree and decides.

## Decision

1. **Three tiers, each with its own measure.**

   | Tier | Runner | What it tests | Measure |
   |---|---|---|---|
   | 1. Application | PHPUnit, against a real PostgreSQL | `services/`, `controllers/`, `helpers/`, `middleware/`, `plugins/` and the three top-level files | line coverage: floor 75, target 85, ideal 90 |
   | 2. SQL logic | pgTAP, run by `pg_prove` | every function, trigger and view the fork's migrations create | completeness: every trigger and function in migrations 0256 onward has a pgTAP test, listed by name |
   | 3. Browser | the Playwright probes in `.devtools/frontend/`, as today | sink rules, forms, the designer, the scan page | the probe list in `tests.yml`; no percentage |

2. **Tier 1 tests run against a real PostgreSQL, in a schema of their own, never against
   mocks.** Each test class gets a throwaway schema the way the label suites do
   (`label_test_<random>` with `search_path` set), seeded by the same migrations the
   application runs. The singleton is injected, not avoided: one shared trait does the
   `DatabaseService` injection `identity-tests.php` does by reflection today, and a test that
   needs a service asks for the real one. Coverage keeps its instrument: PHPUnit's processes
   load `.devtools/coverage/prepend.php` through the same `PHP_INI_SCAN_DIR` mechanism, so
   the number the floor is measured against is one number, from one run.

3. **The bespoke phases migrate into tier 1 one at a time; `run-tests.sh` stays the
   entry point.** Each of the 24 scripts becomes a PHPUnit test class with the same
   assertions; the phase's line in `run-tests.sh` changes from `php <script>` to
   `phpunit --testsuite <name>` and nothing else does. The command a contributor runs does
   not change, and a phase that is not migrated yet keeps running as it is. No phase is
   deleted on the way; `trigdifftest.php`, `difftest.php`, `migratedifftest.php` and
   `rollback-tests.php` retire only when plan 14 piece 2 lands, per ADR-0008 option C.

4. **Plan 14 piece 2's response-snapshot harness is written in tier 1 from the start.**
   It is the largest unwritten test in the roadmap and the one that replaces the
   differential suite; writing it as a 25th bespoke script and porting it later would be
   doing the work twice.

5. **Tier 2 covers SQL logic by name, not by percentage.** pcov measures PHP lines and
   nothing else, so the floor cannot see a trigger. The rule for the SQL half is
   completeness: a list, kept in `.devtools/pgtap/README.md`, of every function and
   trigger the fork's migrations create, each with the test file that exercises it. A
   check (`check-pgtap-coverage.php`, the shape of `check-migrations.php`) fails CI when
   a migration creates a function or trigger the list does not name. Views are tested
   where they carry logic (recursive CTEs, the resolved views) and listed the same way.

6. **pgTAP is installed where PostgreSQL runs for tests, and nowhere else.** The CI job's
   `postgres:16` service does not ship it; the job installs the extension into the
   service's database before the tier runs, and the dev image (`Dockerfile`) carries it so
   `run-tests.sh` works from a clean checkout. The production images built by Nix do not
   get it: tests do not run there and an extension nobody uses is surface.

7. **Test-driven development is a practice, not a rule.** New code arrives with the tests
   that reach it — that is the floor's rule and the ratchet checks it. Whether the test is
   written first is the author's call. The backlog in issue 192 is characterisation work
   (pin what the code does today so a change to it is noticed), and characterisation is
   never test-first by definition.

8. **Composer owns both runners' PHP side.** `phpunit/phpunit` joins
   `phpunit/php-code-coverage` in `require-dev`, on the `^8.4.1` floor plan 15's C7 set.
   pgTAP has no PHP side; `pg_prove` is a Perl script the CI job and the dev image install
   with the extension.

## Options considered

**A. Keep writing bespoke scripts.** Zero new dependencies and the existing pattern is
understood. Rejected: the floor needs 40-plus new test files, and the pattern has no
shared fixtures, no isolation, no filtering and no structured failure output. Every
script re-solves those; PHPUnit solved them once.

**B. PHPUnit with mocks, no database.** The conventional unit-test tier. Rejected for this
codebase: the singleton pattern makes every mock a reflection exercise, and a test that
mocks the database is precisely the "it loads cleanly" verification the constitution rules
out. The tests would pass and prove nothing about the SQL, which is where the logic is.

**C. PHPUnit against a real PostgreSQL, pgTAP for the SQL logic, probes unchanged.** The
decision. Tier 1 keeps the verification bar and gains a runner; tier 2 tests the half a
PHP coverage number cannot see; tier 3 is already right.

**D. pgTAP only, PHP tested through SQL outcomes.** Tempting because the logic is in
PostgreSQL. Rejected: the 75% floor is on PHP lines, and controllers, redaction, the field
policy, the label services and the importer are PHP that a SQL test cannot reach.

**E. A percentage floor for SQL as well.** Rejected: no line-coverage instrument exists
for PL/pgSQL that is worth the cost, and a list of named triggers with a CI check that
refuses an unlisted one is both stricter and cheaper.

## Consequences

- **Two new dev dependencies** (`phpunit/phpunit`; pgTAP with `pg_prove`) and one new
  directory (`.devtools/pgtap/`). The production images are unchanged.
- **The coverage number becomes one number.** Today the label suites and the probes run
  outside `SUITE_COVERAGE`; after decision 2 every PHP test process is measured, which is
  what makes the floor meaningful. Issue 192's items 1 and 2 are this consequence.
- **`run-tests.sh` grows a `phpunit` phase and a `pgtap` phase** and loses phases only as
  ADR-0008 already scheduled. The 23-phase list in its header shrinks as scripts migrate.
- **Plan 14 piece 2 changes shape, not scope.** Its harness is a PHPUnit test suite that
  snapshots the route table; what it snapshots and compares is unchanged.
- **The differential retirement gets a second reason.** Once every trigger has a pgTAP
  test, `trigdifftest.php`'s comparison against SQLite is redundant as well as frozen.
- **AGENTS.md's "Running things" section** gains the two commands; the coverage README's
  "How it is wired" section gains the PHPUnit process; `.github/CONTRIBUTING.md`'s
  verification bullet names both tiers.

## Acceptance prerequisites

Each is a disposable spike: throwaway work that answers one question, merged into `master`
under a `.spike-adr25/` directory the way ADR-0022's spikes were, so the evidence outlives
any branch. The accepting pull request states how each was met.

1. **PHPUnit resolves on the floor.** `composer require --dev phpunit/phpunit` succeeds
   against `php: ^8.4.1`, the lock installs on both PHP 8.4 and the dev image's 8.5, and
   `vendor/bin/phpunit --version` runs. Answers: is there a dependency conflict with
   `phpunit/php-code-coverage ^11` or anything in the lock.
   *Met 2026-09-17:* `phpunit/phpunit` 11.5.56 resolved as `^11.5` beside
   `php-code-coverage ^11.0` with no conflict; `packages/bin/phpunit --version` runs on PHP
   8.4.19. The 8.5 half rests on PHPUnit's `php: >=8.2` having no upper bound, not on a
   measured run — the spike says so, and CI's `frontend-security` job on 8.5 is where a
   conflict would have surfaced since.
2. **One bespoke phase ports without losing an assertion.** `rbac-tests.php` (the largest
   phase with its own `check()` and `status()` helpers, session and permission fixtures)
   becomes a PHPUnit test class with the same assertions, run under `run-tests.sh rbac`,
   and the per-class coverage of the classes it exercises is equal or higher than before
   the port. Answers: does the schema-per-class pattern and the reflection injection hold
   up inside PHPUnit, and does coverage flow through `prepend.php` into the same report.
   *Met 2026-09-17, run first as the gate:* `rbac-tests.php` became `tests/Pgsql/RbacTest.php`
   on `tests/Support/PgsqlSchemaTestCase.php` (schema per class, `DatabaseService` injected
   by reflection, real migrations run in-process); 17 tests, 361 assertions, repeatable
   with clean teardown; `run-tests.sh rbac` runs it. Every class the phase exercises is
   identical or higher per class; the total dipped 14.62% to 14.45% only in bootstrap
   plumbing (`ConfigurationValidator`, `PostgresDialect::CreateConnection`,
   `LocalizationService`) that the ported phase no longer runs as a separate process and
   other phases cover in a full run.
3. **pgTAP installs in CI and in the dev image.** The `suite` job installs the extension
   into the `postgres:16` service and `pg_prove` runs a trivial test; the `Dockerfile` dev
   image carries both. Answers: can the stock image take the extension without a custom
   image, and how long it adds to the job.
   *Met 2026-09-17:* the `suite` job installs `libtap-parser-sourcehandler-pgtap-perl` on
   the runner and `postgresql-16-pgtap` into the running `postgres:16` service by
   `docker exec`; the dev image carries `pg_prove`, and `docker-compose.yml`'s PostgreSQL
   service builds from `.devtools/pgtap/postgres.Dockerfile` for local runs. No custom base
   image; an ordinary `apt-get` of 167 kB, seconds per run.
4. **One trigger family has a pgTAP file.** `trg_locations_check_parent`,
   `trg_locations_guard_children` and `retire_location_labels` (migrations 0269 and
   0273) get a test file asserting the recursion refusal, the depth limit, the
   child-guard and the retirement snapshot, run by `pg_prove` in the job. Answers: does
   pgTAP reach the fork's PL/pgSQL as written, including `RAISE` messages, and what a test
   file for this codebase looks like.
   *Met 2026-09-17:* `.devtools/pgtap/010-locations-trigger-family.sql`, eight assertions
   against a fully migrated database: self-parent and cycle refusals, the six-node chain
   accepted and the seventh generation refused, the child guard and its positive control,
   retirement and its snapshot. `throws_ok()` matched every `RAISE` message verbatim.
5. **The list check works.** `check-pgtap-coverage.php` reads `.devtools/pgtap/README.md`'s
   list and the migrations, and fails on a fixture migration that creates a function the
   list does not name. Answers: is the completeness rule mechanically enforceable, and
   what the list's format is.
   *Met 2026-09-17:* `.devtools/pgtap/check-pgtap-coverage.php` parses the README's table
   and every migration above 0255; against the real tree it names the sixteen unlisted
   functions and triggers and exits 1, and adding five rows to the list removed exactly
   those five, so it reacts to the list and nothing else. The list's format is the
   markdown table itself. Not yet wired into CI — see the status line.

Spike 2 is the schedule risk and runs first; if it fails, decision 2's injection approach
is wrong and the record is rewritten before the rest is attempted.
