# Coverage

Measure application PHP line coverage with the test suite:

```sh
SUITE_COVERAGE=1 SUITE_COVERAGE_CLOVER=clover.xml .devtools/pgsql/run-tests.sh
```

The runner prints a summary and writes `clover.xml`. Omit
`SUITE_COVERAGE_CLOVER` if you only need terminal output. For suite prerequisites and
phase selection, see the [runner usage notes](../pgsql/run-tests.sh).

The runner's report covers only the processes it starts. CI runs additional PHP tests
and merges their results before enforcing the coverage threshold. Use that complete run
when establishing or raising the CI baseline.

## Coverage requirements

The [constitution](../../docs/constitution.md#standing-invariants) sets a **75% minimum**
for application line coverage, an **85% target**, and a **90% ideal**. The minimum also
applies to individual files. Tests must check behavior and results; executing a line
does not establish that its behavior is correct.

The **floor** is the minimum acceptable coverage. The **ratchet** is the CI threshold:
it preserves the achieved aggregate coverage and is raised as coverage improves. Reaching
the floor does not allow the ratchet to be lowered.

For each pull request:

- Cover new code with tests. New files must reach at least 75%.
- Keep files already at or above 75% at or above that floor.
- Improve touched files that are below 75%, or bring them up to the floor.
- Do not lower aggregate coverage. Include the relevant file counts and complete-run
  results in the pull request's Verification section.

CI enforces the aggregate ratchet. Per-file compliance is reviewed using the inventory;
automated per-file enforcement remains optional under
[plan 33](../../docs/plans/33-coverage-floor.md).

## Measurement scope

[prepend.php](prepend.php) measures PHP files in `services/`, `controllers/`, `helpers/`,
`middleware/`, and `plugins/`, plus `app.php`, `routes.php`, and `config-dist.php`.
Vendor code, test tooling, and Blade templates are excluded.

Both the differential phases and the tier-1 PHPUnit phases contribute to the same
measurement. Differential tests primarily exercise SQL directly; PHPUnit phases also
exercise application services and controllers. See
[ADR-0025](../../docs/adr/0025-three-test-tiers.md) for the three test tiers.

Browser-driven tests do not contribute coverage. The separate `frontend-security` job
retains its pass/fail checks, but its PHP server is not instrumented or merged into this
report. Application PHP in the filter remains in the denominator even when browser tests
exercise it. This boundary is the maintainer's decision recorded in
[plan 33's open questions](../../docs/plans/33-coverage-floor.md#open-questions).

Line coverage does not establish SQL test completeness or browser correctness. The pgTAP
checks and frontend probes remain required independently.

## Read the reports

[report.php](report.php) prints a per-class summary and an aggregate covered/executable
line count. Files the suite never loads still contribute to the aggregate at zero
coverage. The text report also requests uncovered classes, but a class listing cannot
serve as a complete file inventory: files can contain several classes or none.

Use [inventory.php](inventory.php) for the per-file results:

```sh
php .devtools/coverage/inventory.php clover.xml
php .devtools/coverage/inventory.php clover.xml --floor=75 --format=csv
```

The default Markdown report lists files below the floor. CSV includes all files.
Both order executable files by the number of additional covered lines needed to reach
the floor, largest shortfall first:

```text
shortfall = max(0, ceil(0.75 * executable) - covered)
```

A file with 100 executable lines and 60 covered lines needs 15 more covered lines to
reach 75%, although 40 lines are uncovered. Files with no executable lines are listed
separately without a percentage.

CI attempts to print the inventory even when an earlier step fails (`if: always()`).
The inventory is informational; it does not fail the build when a file is below 75%.
The workflow also uploads `clover.xml` as the `coverage-clover` artifact when available.

## CI aggregation and enforcement

The `suite` job in [tests.yml](../../.github/workflows/tests.yml) merges the runner's
coverage with these separately measured steps:

| Tests | Coverage label |
|---|---|
| `canonical-json-tests.php` | `canonical-json` |
| `renderer-agreement-tests.php` | `renderer-agreement` |
| `path-parameter-tests.php` | `path-parameter` |
| `identity-tests.php` | `label-identity` |
| `artifact-tests.php` | `label-artifacts` |
| `print-job-tests.php` | `label-print-jobs` |
| `kinds-tests.php` | `label-kinds` |
| `worker-api-tests.php` | `label-worker-api` |
| `registry-tests.php` | `label-registry` |

Each step uses the shared `VICTUAL_COVERAGE_DIR` and `PHP_INI_SCAN_DIR`, and sets its own
`VICTUAL_COVERAGE_LABEL`. The canonical JSON tests compare 2,068 documents with an
ECMAScript oracle. Renderer agreement checks pass the real renderer's bytes to the
production verifier. The path-parameter tests dispatch a request through a real Slim
application using the constrained route pattern from `routes.php`.

After those steps finish, CI runs `report.php` with `--clover`, `--expect`, and `--min`.
The workflow contains the exact invocation and is the authority for the configured
threshold and expected labels.

| Result | Exit code |
|---|---:|
| Report completes and meets the minimum, if supplied | 0 |
| Aggregate coverage is below `--min` | 1 |
| Setup or input failure, including missing coverage, a missing expected label, or a nonnumeric `--min` | 2 |

### Detect missing measurement

A test can pass without producing coverage if its driver or prepend configuration is
missing. The percentage alone cannot reliably distinguish that failure from a test that
adds no newly covered lines.

`prepend.php` prefixes each coverage filename with the step's label.
`report.php --expect=label,label` requires a file for each expected label and names any
missing steps. It matches the sanitized label followed by a dot, so another label with a
similar prefix cannot satisfy the check.

[expectation-tests.php](expectation-tests.php) checks successful collection and failures
including a disabled prepend path, an absent driver, prefix collisions, and invalid
thresholds. Missing-step checks run with another step's file already present.
These checks run in CI before the ratchet.

Start each measurement with an empty directory. Label checks do not distinguish a fresh
file from a stale file with the same label. The runner clears its coverage directory
before collecting data.

### Preserve threshold precision

Set `--min` to the full measured percentage, with enough precision to parse back to the
same floating-point value that `report.php` computes. Do not copy the rounded percentage
from the terminal summary. The Markdown inventory prints the total to 20 decimal places;
verify the value when updating the workflow.

Rounding can admit a regression. At the earlier baseline of 4824/10199 lines, `--min=47`
would accept losing one covered line or adding one uncovered line. Even `--min=47.298`
would accept deleting a two-line file with one covered line: 4823/10197 is approximately
47.298225%, below the baseline but above the rounded threshold.

The parser also rejects nonnumeric thresholds. A previous placeholder was cast to `0.0`,
which allowed every measured run to pass. The parser check and its regression tests prevent
that configuration error from silently disabling the gate.

## Recorded results

The coverage documentation and workflow in [PR #256](https://github.com/datagen24/victual/pull/256),
at `6ce5bf496a97370154cea801a46b68fdd2cef06c`, record the following result for 2026-09-22:
**10,002 of 10,385 executable lines covered (96.31%)**, collected from 1,165 processes.
They report all 140 executable files at or above 75%, with four additional files having
no executable lines. The configured ratchet is `96.31198844487241217394`.

These figures are the implementation's reported results. Reproduce the measurement by
running the complete CI `suite` job, including the separate steps above. Delivery status
belongs in the [plan index](../../docs/plans/README.md).

Earlier baselines were 3857/10201 (37.81%) at `6133e15` on 2026-09-17, recorded in
[issue 192](https://github.com/datagen24/victual/issues/192), and 4824/10199
(47.29875477988038%) at `f6e7225` the same day, recorded in
[PR #196](https://github.com/datagen24/victual/pull/196). The latter established the CI
ratchet from 216 processes; plan 33 raised it twice.

The original issue's table showed only 69 classes because the text report omitted classes
with no covered statements. Those files were already included in the aggregate at zero;
the omission affected the listing, not the total. The report now requests uncovered
classes, and the Clover inventory supplies the complete per-file view.

### Why executable-line counts can change

For a loaded file, the coverage driver supplies the executable-line count. For a file
that is never loaded, `php-code-coverage` uses static analysis. The counts can differ.
Measured 2026-09-21 on `8c270ad`, PHP 8.4.19 with pcov, PostgreSQL 16.13, by comparing a
complete run against a single-phase run of `demodata`. Six of the 140 files in scope
disagree:

| File | Loaded | Never loaded |
|---|---:|---:|
| `controllers/StockController.php` | 457 | 461 |
| `controllers/RecipesController.php` | 157 | 159 |
| `controllers/ChoresController.php` | 80 | 82 |
| `services/Labels/MediaProfileService.php` | 54 | 56 |
| `services/Mqtt/StateSnapshotAssembler.php` | 151 | 152 |
| `helpers/ConfigurationValidator.php` | 96 | 97 |

Loading one of these files for the first time can both increase covered lines and reduce
the executable count. Check this possibility when a coverage change exceeds the apparent
gain from new tests. Record both counts, use comparable tooling, and investigate changes
outside the targeted files; a smaller denominator alone does not show stronger tests.

## Collection and dependencies

With `SUITE_COVERAGE=1`, [run-tests.sh](../pgsql/run-tests.sh) creates a temporary PHP
configuration fragment that sets `auto_prepend_file` to `prepend.php`. It adds that
directory to `PHP_INI_SCAN_DIR` while preserving the normal extension configuration.
PHP processes started by the runner, including PHPUnit, use this configuration.

`prepend.php` starts line coverage and registers a shutdown handler that writes a uniquely
named `.cov` file. When `VICTUAL_COVERAGE_DIR` is unset, it returns without loading the
autoloader, driver, or handler. `report.php` merges the files; run it with
`VICTUAL_COVERAGE_DIR` unset so it does not collect coverage of itself.

The development image and CI install [pcov](https://github.com/krakjoe/pcov). A host PHP
installation needs pcov or Xdebug configured for coverage. For pcov, install it with
`pecl install pcov` and enable the extension. Composer development dependencies include
both `phpunit/phpunit` and `phpunit/php-code-coverage`; the latter provides collection,
merging, and reports outside PHPUnit as well.
