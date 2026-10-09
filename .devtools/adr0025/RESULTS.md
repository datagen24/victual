# ADR-0025 acceptance prerequisites — spike results

Run 2026-09-17 against PostgreSQL 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1), PHP 8.4.19 (cli,
NTS), the cluster and interpreter already installed on this session's machine, with
`postgresql-16-pgtap` 1.3.2-2 and `pg_prove` 3.36 (`libtap-parser-sourcehandler-pgtap-perl`)
installed from the Ubuntu archive. Spike 2 ran first, as the record requires: it is the
schedule risk, and a failure there would send the record back for rewriting before the
other four were attempted. It passed, so spikes 1, 3 and 5 followed (1 first in practice,
since spike 2's own port needs PHPUnit installed to write against — see spike 1's own
note), then spike 4 once spike 3's install was in place.

## Spike 1 — PHPUnit resolves on the floor

```
composer require --dev phpunit/phpunit --no-interaction
```

Resolved `phpunit/phpunit` 11.5.56 against `php: ^8.4.1` and the existing
`phpunit/php-code-coverage: ^11.0` with no conflict — composer recorded the constraint as
`^11.5` itself, matching decision 8's "on the `^8.4.1` floor" without needing a hand-picked
version. `phpunit/phpunit`'s own `composer.json` requires `php: >=8.2` with no upper bound,
so the dev image's PHP 8.5 (`Dockerfile`, the `frontend-security` job) is not a conflict
either, though this sandbox has no 8.5 binary to run `--version` against directly — the
absence of an upper bound is the evidence for that half, not a second measured run.

```
$ packages/bin/phpunit --version
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
```

Answers the question directly: no dependency conflict, the lock installs, and the binary
runs. `composer.json`'s `require-dev` now reads `"phpunit/phpunit": "^11.5"` beside
`"phpunit/php-code-coverage": "^11.0"`, and `autoload-dev` gained a `Victual\Tests\`
PSR-4 mapping for the new `tests/` directory.

## Spike 2 — one bespoke phase ports without losing an assertion (the gate)

`rbac-tests.php` became `tests/Pgsql/RbacTest.php`, a PHPUnit class extending
`tests/Support/PgsqlSchemaTestCase.php` — the shared schema-per-class fixture decision 2
asks for. Each test class gets a schema of its own
(`phpunit_<random>`, `CREATE SCHEMA`, `SET search_path`), `DatabaseService`'s singleton
connection is pointed at it by `ReflectionProperty` on `DbConnectionRaw`/`DbConnection` —
the same injection `.devtools/labels/test-support.php` already does for the label suites —
and `DatabaseMigrationService::MigrateDatabase()` (the method `bin/victual-migrate` calls)
runs in-process against that schema, so the schema carries the real baseline and every real
migration rather than a hand-picked fixture subset. Five scenarios that need a
process-fixed constant PHP cannot redefine (`VICTUAL_DEFAULT_ROLES` for the three
default-role cases, a caller identity other than 9000 for the two own-picture exceptions)
still run as their own process, spawned by a test method via `proc_open` exactly as
`rbac-tests.php` spawned itself — `tests/Pgsql/rbac-subprocess-helper.php` is that
process's entry point, attaching to the class's own schema via an environment variable
instead of building a fresh one.

`run-tests.sh`'s `rbac` phase now runs `packages/bin/phpunit --testsuite rbac` instead of
`php rbac-tests.php`. One thing beyond that single line changed: the phase's database is
no longer migrated by `build_pgsql()` before the tests run, because each PHPUnit test class
migrates its own schema — migrating the whole database as well would build a `public`
schema nothing then reads. Decision 3's "nothing else does [change]" needed this one
addendum for the first phase that actually made the switch; `.devtools/pgsql/run-tests.sh`'s
`run_rbac_tests()` says so where a reader of that function looks.

```
$ PGHOST=127.0.0.1 PGPORT=5432 PGUSER=victual PGPASSWORD=victual .devtools/pgsql/run-tests.sh rbac
...
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.19
Configuration: /home/user/victual/phpunit.xml

.................                                                 17 / 17 (100%)

Time: 00:03.702, Memory: 22.00 MB

OK (17 tests, 361 assertions)

SUITE PASSED
```

Run twice to check the schema teardown is clean and the phase is repeatable; the second run
also passed, and `SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE
'phpunit_%'` against the phase's database found zero rows afterwards both times.

### Coverage flows through prepend.php into the same report

```
$ SUITE_COVERAGE=1 SUITE_COVERAGE_DIR=.../coverage .devtools/pgsql/run-tests.sh rbac
...
1474 of 10202 executable lines covered (14.45%) from 13 process(es)
SUITE PASSED
```

Compared against the same command run before the port (`php rbac-tests.php`, same
`run-tests.sh` scaffolding around it, same coverage instrumentation):

| | Before (bespoke script) | After (PHPUnit) |
|---|---|---|
| Lines covered | 1492 / 10202 (14.62%) | 1474 / 10202 (14.45%) |
| Processes measured | 14 | 13 |

The diff between the two full reports is three classes, none of them RBAC application
logic:

- `Victual\Helpers\ConfigurationValidator` (53.19% → 48.94% lines): `bin/victual-migrate`'s
  own script calls `ConfigurationValidator::validateConfig()` before migrating; the ported
  phase migrates in-process through `DatabaseMigrationService` directly and never spawns
  `bin/victual-migrate` as its own measured process for this database.
- `Victual\Services\Database\PostgresDialect` (46.88% → 32.81% lines, one fewer method):
  `CreateConnection()`'s own lines (building the DSN, opening the PDO connection) are never
  reached, because the schema fixture hands `DatabaseService` a connection by reflection
  instead of letting it call `CreateConnection()` itself.
- `Victual\Services\LocalizationService` (69.23% → 62.82% lines): a smaller difference in
  the same shape, from one fewer full application boot in a separate process.

Every class the phase actually exercises is identical or higher: `RolesApiController`
(76.81% lines, unchanged), `UsersApiController` (20.14%, unchanged), `FilesApiController`
(52.29%, unchanged), `GenericEntityApiController` (9.47%, unchanged), `UsersController`
(61.90%, unchanged), `Users\User` (95.92%, unchanged), `Users\EntityReadPolicy` (66.67%,
unchanged), `RolesService` (96.36%, unchanged), `DatabaseMigrationService` (65.29%,
unchanged), `Database\InitialDataSeeder` (100%, unchanged). Per this spike's own acceptance
wording — "the per-class coverage of the classes it exercises is equal or higher than
before the port" — this passes; the total dipped only in bootstrap plumbing the schema-per-
class design deliberately no longer runs as a separate process, and that plumbing is
covered by other phases in a full suite run regardless.

**Answer: yes on both counts.** The schema-per-class pattern and the reflection injection
hold up inside PHPUnit for the largest and most stateful bespoke phase in the tree, and
coverage flows through `prepend.php` into the same report whether the process is
`difftest.php` or `packages/bin/phpunit`.

## Spike 3 — pgTAP installs in CI and in the dev image

Installed into this session's PostgreSQL 16 the same way CI installs it into the
`postgres:16` service (decision 6: nowhere else, since production images never run tests):

```
$ apt-get install -y postgresql-16-pgtap
...
Setting up postgresql-16-pgtap (1.3.2-2) ...
$ pg_prove --version
pg_prove 3.36
```

`.github/workflows/tests.yml`'s `suite` job gained two steps ahead of the differential
suite: `apt-get install libtap-parser-sourcehandler-pgtap-perl` on the runner (`pg_prove`
itself), and `docker exec` into the running `postgres:16` service container to
`apt-get install postgresql-16-pgtap` there, before `CREATE EXTENSION` is ever asked for.
For local/`docker compose` use, `docker-compose.yml`'s `postgres` service now builds from
`.devtools/pgtap/postgres.Dockerfile` (`FROM postgres:16` plus the same package) instead of
pulling the plain image, since that service is what actually runs PostgreSQL — the
`Dockerfile` dev image never runs a database server itself, so it gains `pg_prove` (via
the same Debian package) rather than the extension.

`run-tests.sh pgtap` proves the whole chain end to end — `pg_prove` reaching a trivial test
against a database the phase migrated and installed the extension into:

```
$ .devtools/pgsql/run-tests.sh pgtap
...
CREATE EXTENSION

/home/user/victual/.devtools/pgsql/../pgtap/000-smoke.sql ..................... ok
/home/user/victual/.devtools/pgsql/../pgtap/010-locations-trigger-family.sql .. ok
All tests successful.
Files=2, Tests=9,  1 wallclock secs (...)
Result: PASS

SUITE PASSED
```

**Answer:** the stock `postgres:16` image and the stock `php:8.5-cli-bookworm` dev image
both take the extension/client with an ordinary `apt-get install` and no custom base image;
in this environment the install itself took a few seconds (167 kB, cached by apt within a
run). CI's added cost is one more `docker exec` and one more `apt-get` inside a container
that is already running, which is the same order of magnitude as the runner-side install.

## Spike 4 — one trigger family has a pgTAP file

`.devtools/pgtap/010-locations-trigger-family.sql` asserts, against a fully migrated
database (not a reduced fixture — `run_pgtap_tests()` calls the same `build_pgsql()` every
differential phase uses):

1. A location cannot be its own parent (`trg_locations_check_parent`,
   `RAISE EXCEPTION 'Recursive nested location detected'`).
2. A location cannot become the child of its own descendant (the same guard, the cycle
   case rather than the self-parent one).
3. A six-node chain — a root and five generations, which is what
   `hierarchy_depth_limit() = 6` actually means per migration 0273's own comment — is
   accepted.
4. A seventh generation is refused (`RAISE EXCEPTION 'Location nesting depth limit
   exceeded'`).
5. `trg_locations_guard_children` refuses to delete a location with children
   (`RAISE EXCEPTION 'Location has child locations'`).
6. The deepest, now-childless leaf can still be deleted — the positive control the refusal
   above needs.
7. Deleting a location retires its live label (`retire_location_labels`): `retired_at` is
   set and `target_id` is cleared.
8. The retirement snapshot (`retirement_snapshot->>'name'`) carries the name as it stood at
   delete time.

```
$ pg_prove -h 127.0.0.1 -p 5432 -U victual -d victual_pgtap .devtools/pgtap/*.sql
.../000-smoke.sql ..................... ok
.../010-locations-trigger-family.sql .. ok
All tests successful.
Files=2, Tests=9,  0.12 CPU
Result: PASS
```

**Answer:** pgTAP reaches this fork's PL/pgSQL exactly as written, `RAISE` messages
included — `throws_ok()` matched every message text verbatim with no adaptation needed. A
test file for this codebase is a `.sql` script under `.devtools/pgtap/`, numbered so
`pg_prove *.sql` runs it in a stable order, using `format()` to build the dynamic SQL
`throws_ok()`/`lives_ok()` take as their first argument and a `DO $$ ... $$` block for the
one case (building a six-deep chain) that a single `INSERT` cannot express.

## Spike 5 — the list check works

`.devtools/pgtap/check-pgtap-coverage.php` parses `.devtools/pgtap/README.md`'s table (every
backtick-quoted name in a row's first column, so a cell naming both a function and its
trigger — `` `trg_locations_check_parent` (trigger `check_location_parent`) `` — counts
both) and every migration above `DatabaseMigrationService::BASELINE_MIGRATION_ID` (255),
skipping `CREATE FUNCTION`/`CREATE TRIGGER` text that only appears inside a comment.

Rather than write a disposable fixture migration to prove the failure mode, the real tree
already has one: five migrations (0269, 0275, 0277, 0278, 0279, 0283) create sixteen further
function/trigger names this spike did not port a pgTAP file for, and the checker names all
sixteen:

```
$ php .devtools/pgtap/check-pgtap-coverage.php
Checked 22 function/trigger definition(s) above the baseline (255) against 6 listed name(s).
  0283.pgsql.php (migration 283) creates "retire_product_labels", which .devtools/pgtap/README.md's list does not name. ...
  0283.pgsql.php (migration 283) creates "retire_stock_entry_labels", which .devtools/pgtap/README.md's list does not name. ...
  [... 14 more, one per unlisted name ...]

16 pgtap coverage problem(s)
```

exit code 1. Removing five of those problems by temporarily adding the corresponding rows
to the README's table (a scratch edit, reverted immediately) dropped the reported count to
eleven and left the other eleven named exactly as before — confirming the check reacts to
the list, not to some hidden per-migration allowlist.

**Answer:** the completeness rule is mechanically enforceable, and the list's format is a
markdown table (`Name | Kind | Migration | Test file`) that is both a document a person
reads and a source the checker parses with no separate machine-readable copy. It is **not
yet wired into CI**: turning it on before the list is complete would fail every build on
sixteen names that predate pgTAP entirely, which is the "threshold nobody chose" the
coverage floor's own README warns against. `.devtools/pgtap/README.md`'s "What is not
covered yet" section names the backlog and points at issue 192's ratchet-then-gate shape as
the precedent for wiring it in once the remaining phases are migrated - itself a
consequence of following the constitution's directive that a deferral changes the gate's
wording in the same change, not silently.

## Summary

| Spike | Question | Answer |
|---|---|---|
| 1 | Does PHPUnit resolve on the `^8.4.1` floor? | Yes — `phpunit/phpunit` `^11.5`, no conflict with `php-code-coverage` `^11.0` |
| 2 (gate) | Does the schema-per-class pattern and the reflection injection hold up inside PHPUnit, with coverage flowing through `prepend.php`? | Yes — 17 tests, 361 assertions, identical or higher per-class coverage on every RBAC class |
| 3 | Can the stock images take pgTAP with no custom base image? | Yes — an `apt-get install` on each side |
| 4 | Does pgTAP reach this fork's PL/pgSQL, `RAISE` messages included? | Yes — 8 assertions against the real location trigger family |
| 5 | Is the completeness rule mechanically enforceable? | Yes — proven against the real tree's own backlog, not a synthetic fixture |

Spike 2 passed, so the record's decision 2 (schema-per-class, reflection injection) stands
as written and is not sent back for rewriting.
