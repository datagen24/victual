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
[issue 192](https://github.com/datagen24/victual/issues/192).
A threshold nobody chose gets lowered until it stops failing; this one was chosen, which
is the difference. `report.php` takes `--min=NN`, and a pull request that lowers the
number, or leaves a file it touched below 75%, has not met the verification bar.

The ratchet issue 192 asks for as its first step is wired: `tests.yml`'s `suite` job gates
on `report.php --min=47.29875477988038` in its "Enforce the coverage ratchet" step, near
the end rather than inside `run-tests.sh`, because it has to see everything the job
measured — including the label phases below — not just the differential suite's share of
it. Not the 37.81 `6133e15` measured, because that figure predates both fixes below: the
pull request that wired this ratchet ([#196](https://github.com/datagen24/victual/pull/196))
had its own `suite` job report 4824 of 10199 executable lines covered, 47.29875477988038%
exactly, from 216 processes, at `f6e7225` (2026-09-17) — with never-loaded files shown and
the label suites measured, the real total turned out well above the last recorded one, not
below it, which is what adding coverage rather than hiding or losing it should do.

`--min` is that full figure, not a rounded one — a shorter number is not a ratchet at the
current total, it is a ratchet at a nearby one. A bare `47` passes a run that lost one
covered line (47.28895%) or added one uncovered executable line (47.29412%). Even `47.298`
still passes a regression that moves both counts together: deleting a two-line,
one-covered file — one line lost from each of the numerator and the denominator — reads
4823/10197 = 47.298225%, below the true baseline but still ≥ `47.298`. Both gaps were
caught in review on this same PR before merge (see the PR discussion for the arithmetic);
only the exact figure, matched bit-for-bit against what `report.php` computes at runtime
from the same 4824/10199, closes them. `--min` is raised by hand as the number climbs; the
hard floor of 75% is issue 192's last step, once the backlog in it is retired.

## Every file in scope, not just the ones a table happened to list

Issue 192's first mechanics question was whether a file the suite never loads at all shows
up as 0%, or drops out of the report entirely — because if it drops out, a percentage
computed from what remains reads too high. It does not drop out of the *total*:
`CodeCoverage::getData()` adds every file `prepend.php`'s filter names to the report at 0%
by default (`includeUncoveredFiles()`), so `report.php`'s aggregate percentage always
counted them. What used to drop a never-loaded class was the per-file table underneath it:
`Report\Text` omits a class with zero covered statements unless told `showUncoveredFiles:
true`, which `report.php` did not pass. The backlog table issue 192 carries was built from
that listing, so a class the suite never reaches even once was invisible in it rather than
named at 0% — 140 files across the five scoped directories and three top-level ones, 69
classes shown. `report.php` now passes `showUncoveredFiles: true`, so every file the filter
names appears, at 0% where nothing reached it.

## What the label phases add

`tests.yml` also runs the label subsystem's own PHP test scripts as separate steps —
`identity-tests.php`, `artifact-tests.php`, `print-job-tests.php`, `kinds-tests.php`,
`worker-api-tests.php`, `registry-tests.php` — and until now they ran outside
`SUITE_COVERAGE` entirely: they are their own workflow steps, not something
`run-tests.sh` invokes, so the `auto_prepend_file` wiring above never reached them and a
real share of `Services\Labels\*`'s exercise went uncounted. They now carry the same
`VICTUAL_COVERAGE_DIR` and `PHP_INI_SCAN_DIR` `run-tests.sh` set up, pointed at the same
directory, so their `.cov` files merge with the differential suite's rather than being
measured — or not measured — on their own.

The same wiring now also covers `middleware/PathParameterMiddleware.php`, which had no
test of any kind before: nothing else in this tree boots a real Slim App and dispatches a
request through it (every controller test calls the controller method directly). The new
`.devtools/middleware/path-parameter-tests.php` step does exactly that, against a
throwaway app whose one route is registered with the same FastRoute-constrained pattern
`routes.php` uses for the generic label routes.

Two things named in issue 192's mechanics section are still outside the number, and stay
that way for now rather than being folded in without saying so:

- **`canonical-json-tests.php` and `renderer-agreement-tests.php`.** Both are label-related
  PHP processes in the same job, and could be wired the same way; they are not yet, so the
  ratchet below does not yet count what they reach.
- **The frontend Playwright probes** (`.devtools/frontend/*.js`, the `frontend-security`
  job). These drive a running `php -S` server over HTTP from a separate job on a separate
  runner, so counting them means the server process loading `prepend.php` and a
  cross-job merge of two coverage directories before `report.php` sees either — not
  something this change does. What they reach (Blade views, `routes.php`'s dispatch, the
  session and CORS middleware) is real application code no PHP-process phase here drives at
  all, so folding them in later would raise the number, not just add more of what is
  already measured.

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
