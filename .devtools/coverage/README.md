# Coverage

What the differential test suite actually reaches.

```sh
SUITE_COVERAGE=1 .devtools/pgsql/run-tests.sh
```

The run prints a per-class summary at the end and nothing else changes. Add
`SUITE_COVERAGE_CLOVER=clover.xml` to also write Clover XML, which is what CI keeps as an
artifact.

## What the number means

It is line coverage of `services/`, `controllers/`, `helpers/`, `middleware/`, `plugins/`
and the three top-level PHP files, by a suite that is not a unit test suite. Three phases
drive SQL straight at each engine and barely enter PHP application code at all; the fourth
goes through `StockService`. So most controllers are at zero by design, and the total is
low for a reason that is not a quality judgement.

Read it as a map first: `StockService` sitting around two thirds means the stock write
paths are exercised, and that figure falling means a phase stopped reaching something it
used to, which is the failure this exists to make visible.

It is also a score, since 2026-09-17. The maintainer set a **floor of 75%** line coverage
of application code, a **target of 85% or better** and **90% as the ideal**
(`docs/constitution.md`, standing invariants). The tree is below the floor —
37.81% on master at `6133e15`, with the backlog and the plan to close it in
[issue 192](https://github.com/datagen24/victual/issues/192) — whose first step wires a CI ratchet
(`report.php --min` at the current total, raised as it climbs) and whose last turns on the
hard floor.
A threshold nobody chose gets lowered until it stops failing; this one was chosen, which
is the difference. `report.php` takes `--min=NN`, and a pull request that lowers the
number, or leaves a file it touched below 75%, has not met the verification bar.

## How it is wired

The suite is a couple of dozen short-lived PHP processes: `difftest.php` once per seed,
`migratedifftest.php`, `trigdifftest.php`, `rollback-tests.php` once per engine,
`bin/victual-migrate` and
`bin/victual-db-import` several times each. Rather than editing each call site — which means
remembering to edit the next one too — `run-tests.sh` writes a throwaway `php.ini`
fragment setting `auto_prepend_file` and puts it on `PHP_INI_SCAN_DIR`. Every PHP process
the run spawns then loads `prepend.php` first.

- **`prepend.php`** starts a line-coverage driver and registers a shutdown handler that
  writes one `.cov` file named for the process. It returns immediately when
  `VICTUAL_COVERAGE_DIR` is unset, so an ordinary run is untouched: no driver, no autoloader,
  no handler. This is also what tier 1 (ADR-0025) measures: `packages/bin/phpunit` is a PHP
  process like any other the suite spawns, so it loads `prepend.php` through the same
  `auto_prepend_file` mechanism with no PHPUnit-specific wiring at all — one number, from
  one run, whether the process is `difftest.php` or a PHPUnit test class.
- **`report.php`** merges every `.cov` in the directory — no single process can know it is
  the last one — and prints the summary. It is run with `VICTUAL_COVERAGE_DIR` unset so it
  does not measure itself into the directory it is reading.

The driver is [pcov](https://github.com/krakjoe/pcov): line coverage only, which is all
this needs, and fast enough that the suite's runtime does not visibly change. Xdebug
satisfies the same check if it is what you have. The `Dockerfile` installs pcov and CI asks
`shivammathur/setup-php` for it; on a host PHP, `pecl install pcov` and enable it.

The merging and reporting is `phpunit/php-code-coverage`, a `require-dev` dependency. The
library works standalone — PHPUnit is not installed and is not needed.
