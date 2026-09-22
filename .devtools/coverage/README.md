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
and the three top-level PHP files. The differential phases that gave this suite its name
drive SQL straight at each engine and barely enter PHP application code at all — that is
why most controllers used to read zero, and it was a fact about the suite's shape rather
than a quality judgement. Since plan 33 the tier-1 PHPUnit phases (ADR-0025) reach them
directly, so the number is now a statement about the application rather than about which
phases happen to enter it.

Read it as a map first: `StockService` sitting around two thirds means the stock write
paths are exercised, and that figure falling means a phase stopped reaching something it
used to, which is the failure this exists to make visible.

It is also a score, since 2026-09-17. The maintainer set a **floor of 75%** line coverage
of application code, a **target of 85% or better** and **90% as the ideal**
(`docs/constitution.md`, standing invariants). A threshold nobody chose gets lowered until
it stops failing; this one was chosen, which is the difference. `report.php` takes
`--min=NN`, and a pull request that lowers the number, or leaves a file it touched below
75%, has not met the verification bar.

**The tree is above all three, as of 2026-09-22: 10002 of 10385 executable lines,
96.31%, with every one of the 140 files in scope at or above the floor.** That is plan
33's domain work, done against the backlog [issue
192](https://github.com/datagen24/victual/issues/192) carries — which measured 37.81% at
`6133e15` on 2026-09-17, from a table that listed 69 classes because a file the suite
never loaded did not appear in it at all (see below). Four files have no executable lines
and are listed apart rather than counted either way.

The ratchet issue 192 asks for as its first step is wired: `tests.yml`'s `suite` job gates
on `report.php --min=96.31198844487241217394` in its "Enforce the coverage ratchet" step, near
the end rather than inside `run-tests.sh`, because it has to see everything the job
measured — including the label phases below — not just the differential suite's share of
it. The figure has been raised three times: 47.29875477988038% when the ratchet was wired
([#196](https://github.com/datagen24/victual/pull/196), `f6e7225`, 2026-09-17, 216
processes), and twice by plan 33's own work, ending at 10002 of 10385 from 1165 processes.

Each of those is a full figure rather than a rounded one, and one of them was not a figure
at all for a while: a placeholder was committed in its place, `report.php` cast it with
`(float)`, and `(float)'RATCHET_PLACEHOLDER'` is `0.0` — a threshold every run clears. The
gate ran, compared against zero and reported green. `report.php` now refuses a `--min` it
cannot read (exit 2, the setup-failure code) rather than casting it, and
`expectation-tests.php` carries the control that would have caught it.

`--min` is that full figure, not a rounded one — a shorter number is not a ratchet at the
current total, it is a ratchet at a nearby one. The arithmetic that settled this was done
against the original 4824/10199 and still reads the same way at any total: a bare `47`
passed a run that had lost one covered line (47.28895%) or gained one uncovered executable
line (47.29412%), and even `47.298` passed a regression that moved both counts together —
deleting a two-line, one-covered file loses one line from each of the numerator and the
denominator and reads 4823/10197 = 47.298225%, below the true baseline but still ≥
`47.298`. Both gaps were caught in review before that merge. Only the exact figure,
parsing back to bit-identically the double `report.php` computes at runtime from the same
two counts, closes them — which is checked rather than assumed each time the number is
raised.

The ratchet stays at the measured figure rather than dropping to 75% now that the number
clears it. A gate set to the floor would admit a twenty-point fall, and the floor is a
statement about the worst acceptable state, not about this one. The per-file half of the
floor is the inventory below.

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

## The denominator moves, and it is not a bug

An executable-line count is not a fixed property of a file here. For a file the run
actually loaded, the count comes from the driver; for one it never loaded, it comes from
php-code-coverage's static analysis, and the two disagree by a line or two on some files.
Measured on 2026-09-21, six of the 140 disagree:

| File | Loaded | Never loaded |
|---|---:|---:|
| `controllers/StockController.php` | 457 | 461 |
| `controllers/RecipesController.php` | 157 | 159 |
| `controllers/ChoresController.php` | 80 | 82 |
| `services/Labels/MediaProfileService.php` | 54 | 56 |
| `services/Mqtt/StateSnapshotAssembler.php` | 151 | 152 |
| `helpers/ConfigurationValidator.php` | 96 | 97 |

So covering a file for the first time raises the numerator **and** lowers the denominator,
and the total moves slightly more than the new lines alone explain. The direction is the
harmless one - the ratchet still only goes up - but two things follow. A pull request whose
total moved by more than its own lines is not necessarily measuring something odd; check
whether it loaded a file nothing loaded before. And a per-file count quoted from a run that
did not load that file is the static one, which is why `inventory.php` reports covered and
executable rather than only a percentage.

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

`canonical-json-tests.php` and `renderer-agreement-tests.php` were the last two named in
issue 192's mechanics item 2, and they are measured now too (plan 33's M2). The first is
2068 documents' worth of `Helpers\CanonicalJson`, run against an ECMAScript oracle; the
second is the only check that feeds the real renderer's bytes to the verifier that will
accept or refuse them in production. Both are exercise the number never saw.

One thing named there stays outside it:

- **The frontend Playwright probes** (`.devtools/frontend/*.js`, the `frontend-security`
  job). These drive a running `php -S` server over HTTP from a separate job on a separate
  runner, so counting them means the server process loading `prepend.php` and a
  cross-job merge of two coverage directories before `report.php` sees either — not
  something this change does. What they reach (Blade views, `routes.php`'s dispatch, the
  session and CORS middleware) is real application code no PHP-process phase here drives at
  all, so folding them in later would raise the number, not just add more of what is
  already measured.

## A step that stopped being measured

Adding a step to the directory is easy; noticing that one quietly left is not, because the
symptom is a number that stopped rising, which is also what an honest run with nothing new
to reach looks like. A lost `PHP_INI_SCAN_DIR`, a driver that failed to load, a step whose
`env:` block was dropped in a rebase — none of them fails anything on its own.

So each separately measured step sets **`VICTUAL_COVERAGE_LABEL`**, `prepend.php` makes that
the `.cov` filename's prefix, and the ratchet step passes **`report.php --expect=`** with
every label named. A label that left no file exits 2 — the same code as "no `.cov` files at
all", because both are setup failures rather than a coverage shortfall — and says which step
went missing. Matching is on that step's own prefix, so a file another step left behind
cannot stand in for it.

`.devtools/coverage/expectation-tests.php` is the control, and runs in CI just before the
ratchet. Fourteen checks: one positive (a wired process writes exactly one file named for
its label, and `--expect` accepts it) and the rest negative — the prepend path disabled, the
driver absent, and a label that is only a prefix of a real one. Each negative runs against a
directory that already holds the positive control's file, because a stale file concealing a
missing step is the specific way this mechanism could be useless.

## Which files are below the floor

`report.php` prints a per-**class** summary. The floor is stated per **file**
(`docs/constitution.md`), and those are not the same list: a file can hold more than one
class, and a file holding none at all is a class row nowhere.

```sh
php .devtools/coverage/inventory.php clover.xml [--floor=75] [--format=markdown|csv]
```

reads the Clover report and prints the files below the floor, worst first by **shortfall** —
`max(0, ceil(floor * executable) - covered)`, the number of lines that actually have to be
covered to reach it, not the file's whole uncovered count. Issue 192's backlog table quotes
the second, which overstates the work by about a quarter of every file's line count and puts
the wrong files at the top. Files with no executable lines are listed separately rather than
given a percentage: 0/0 is neither 0% nor 100%, and printing either invents a measurement.

The `suite` job runs it after the ratchet, with `if: always()`, so what a pull request left
below the floor is in the log whether the gate passed or not. It is not itself a gate —
plan 33's M3 leaves automated per-file enforcement optional.

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
