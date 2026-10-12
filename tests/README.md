# Tests

PHPUnit tests that run against a real PostgreSQL database. This directory is tier 1 of the
three test tiers in [ADR-0025](../docs/adr/0025-three-test-tiers.md):

| Tier | Tests | Location |
|---|---|---|
| 1 | PHPUnit against PostgreSQL, for application code | this directory |
| 2 | pgTAP, for SQL functions, triggers, and logic-bearing views | [.devtools/pgtap/](../.devtools/pgtap/README.md) |
| 3 | Playwright probes, for the browser | [.devtools/frontend/](../.devtools/frontend/README.md) |

## Contents

- `bootstrap.php`: PHPUnit's bootstrap. It loads the autoloader and nothing else;
  application configuration is loaded later by
  `Victual\Tests\Support\PgsqlSchemaTestCase::Boot()`.
- `Pgsql/*Test.php`: the test classes.
- `Pgsql/*-helper.php`: scripts a test runs in a separate PHP process. A scenario needs its
  own process when it must define a `VICTUAL_*` constant before `config-dist.php` runs, or
  when it races two connections against each other.
- `Pgsql/snapshots/`: recorded API responses that the `contract` suite compares against.
- `Pgsql/fixtures/`: data files for individual tests.
- `Support/`: shared base classes and test doubles. `PgsqlSchemaTestCase` gives each test
  class its own schema and injects it into `DatabaseService`.
- `fixtures/consumption-events/` and `fixtures/consumption-refill/`: request-and-response
  scenarios for the consumption APIs. Each directory has its own README:
  [consumption events](fixtures/consumption-events/README.md) and
  [consumption refills](fixtures/consumption-refill/README.md).

## Running the tests

Each `<testsuite>` in [phpunit.xml](../phpunit.xml) is also a phase of
[`.devtools/pgsql/run-tests.sh`](../.devtools/pgsql/run-tests.sh), with the same name.
`run-tests.sh` sets the connection environment (`PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD`,
`PHPUNIT_DB_NAME`, `VICTUAL_DATAPATH`); `phpunit.xml` configures no connection.

```sh
.devtools/pgsql/run-tests.sh rbac   # one phase
.devtools/pgsql/run-tests.sh        # every phase ("all")
```

`run-tests.sh` also runs phases that are not PHPUnit suites: the SQLite-against-PostgreSQL
differential checks, `pgtap`, and `check-migrations.php`. Its header comment lists every
phase and describes what each one checks. [docker-compose.yml](../docker-compose.yml)
defines the services the suite expects.

## Adding a test

1. Put the class in `Pgsql/` and extend `Victual\Tests\Support\PgsqlSchemaTestCase`.
2. Register the file in an existing `<testsuite>` in `phpunit.xml`. A new suite also needs a
   matching phase in `run-tests.sh`, including the `all` target.
3. Code that the change adds needs tests that reach it. The coverage floor and ratchet are
   described in [AGENTS.md](../AGENTS.md#ground-rules) and
   [.devtools/coverage/README.md](../.devtools/coverage/README.md).
