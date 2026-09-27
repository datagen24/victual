# ADR-0032 acceptance spike

ADR-0032 is not ready for acceptance. Both experimental policies pass the focused
functional checks, but both retain the demonstrated bulk residue. The relative policy
also discards a genuine small remainder at large magnitudes. The arithmetic-policy
choice and a production implementation remain outstanding.

## Working copies and runtime

Measured 2026-09-27 using PostgreSQL 16.15, PHP 8.4.25 and PHPUnit 11.5.56.
The test tree combines:

- Master `ef62f10e9387d0c251b94ad76c2cb7c1c031debe`.
- PR #531 head `f21d09f7697c84de552ef1a3a12d7e748c3783d6`.
- Their conflict-free `git merge-tree --write-tree` result,
  `36aab8909c3f216562289926ed4f3d60c76d6f51`.

The dependency was unmerged when this experiment began. The baseline below is this
combined tree, not deployed master. `prepare.py` modifies exported copies of its stock
and recipe services for each experimental policy. Application files in the working
checkout remain unchanged. The experiment does not accept or implement the ADR on master.

Each run creates its own migrated PostgreSQL schema through `PgsqlSchemaTestCase` and
removes it afterwards. Containers use a separate disposable database and fixture
credentials. No production database, printer, or external service is used.

The local images were:

| Image | Image id |
|---|---|
| `localhost/victual:dev-coord` | `6906621b4df823b5b66059562e9f4cefcabd86e2c3e3aa7113bd659b4a907e4f` |
| `localhost/victual-pg:pgtap` | `3ad78171b5ab5b94ee20cbb4f51b70d835dba7908eaa22ec2ea305bb26f97d55` |

## Policies compared

The absolute experiment uses `abs(a-b) <= 1e-9`. The relative experiment uses
`abs(a-b) <= max(1e-9, 1e-12 * max(abs(a), abs(b)))`. Both compare subtraction operands
before deciding that a computed remainder is zero. A tolerated whole-entry match
exhausts the transient request before another candidate or unit conversion is processed.
Neither rounds a surviving stock value explicitly.

Both experiments also apply the same finite-input guards, exact measurement coherence,
inventory equality refusal, and recipe availability predicates. These shared fixes must
not be attributed to choosing one tolerance over the other.

## Results with PHP precision 14

| Case | Absolute | Relative |
|---|---|---|
| Focused functional checks | 76 passed | 76 passed |
| Existing undo-integrity tests | 41 tests, 360 assertions passed | 41 tests, 360 assertions passed |
| Seed `(999999999.9 + 0.1)` and consume in portions `999999999.9`, then `0.1` | Leaves about `2.384186e-8` | Leaves the same residue |
| Request `1e9 - 0.0005` from a billion-unit row | Preserves about `0.000499963760376` | Deletes the row and books its full amount |
| Subtract `0.1` through 1,000 service bookings from `1e5`, `1e6`, or `1e7`, then consume the expected balance | No residue in these cases | No residue in these cases |

The five bulk/drift records are observations, not assertions that these outcomes are
correct. Their successful execution does not turn the surviving residue or lost
remainder into a passing acceptance criterion. JSON records identify them separately.

The unchanged combined baseline passes 42 of the 75 comparable functional checks.
The experiments add one direct predicate-boundary check that the baseline cannot run.
The baseline failures include inconsistent availability comparisons, accepted negative
or non-finite inputs, and application-approved measurements rejected by PostgreSQL's
exact coherence constraint. Raw outcomes are in `evidence/baseline.json`.

The existing scoped-availability suite reports 14 passing tests and one failing
characterization, out of 15 tests and 64 assertions under the absolute patch.
`testOpenWithANonExactConversionFactorCurrentlyRefusesOnFloatAccumulation` deliberately
expects the old refusal. The experiment permits the opening, so production adoption must
replace that characterization with assertions for the successful stock and ledger result.
The test was not weakened or removed to obtain a green report.

### Serialization matters

The standalone binary64 probe in the ADR does not model every database round trip.
At PHP's measured default `precision=14`, a float passed as a PDO parameter is converted
to a decimal string with that precision. In a direct PDO check,
`1000000.0 - 0.1` prints as `999999.90000000002` at 17 digits, casts to
`999999.9`, and PostgreSQL stores `999999.9`.

That round trip suppresses drift for the repeated `0.1` cases above. It is not a
correctness guarantee and can lose other significant digits. The bulk residue survives
it. The ADR's claim that no safe magnitude or booking-count range has been established
still holds. Do not use the standalone arithmetic counts as application measurements.

### Results with PHP precision 17

Both policies still pass all 76 focused functional checks. The accumulated-drift
observations differ because 17-digit decimal serialization preserves the binary64 values:

| Starting stock, 1,000 bookings of 0.1 | Absolute final consume | Relative final consume |
|---|---|---|
| 100,000 | Refuses the expected balance as a shortage | Completes; pre-consume error about -5.82e-9 |
| 1,000,000 | Leaves about 2.33e-8 | Completes with no row |
| 10,000,000 | Leaves about 3.73e-7 | Completes with no row |

The carried bulk residue remains about `2.38e-8` under both policies. Relative tolerance
still deletes the genuine roughly `0.0005` remainder from the billion-unit row; absolute
tolerance preserves it. These measurements support a tradeoff, not a general claim that
relative tolerance solves stock arithmetic.

The runner was exercised end to end for both 17-digit runs, including export, patching,
container setup, schema migration and teardown. The 14-digit runs used the same exported
tree and patch generator with persistent disposable containers. No safe numerical range
is inferred from either set of fixtures.

## Gate audit

| Gate | Evidence | Remaining requirement |
|---|---|---|
| 1: policy confirmation | Both policies tested with identical service paths, units, and operands; bulk limitations demonstrated | Maintainer chooses the policy and explicitly disposes of open question 3; exact coherence and scope refinements need confirmation |
| 2: booking and undo regressions | Consume, open, transfer, inventory in both directions, purchase, self-production and positive inventory-correction undo; separate 0.1 and 0.2 rows; transfer-undo residue fixture | Promote spike cases to maintained PHPUnit regressions and land the implementation/dependency separately |
| 3: boundaries and conversions | Inclusive zero predicate; below/above availability shortages; 0.001 preservation; no extra booking after exhaustion; factors 4 and 0.001; recipe clamps; inventory equal-count refusal; bulk observations | Update the old refusal characterization; retain the chosen policy's documented limitations |
| 4: coherence | Edit, measure and measured open at 1, 1 ± 0.5e-9, 0.995, 0.998, 1.002, 1.005; exact request checks and preserved positive split remainder | Land exact application predicates; SQL constraint remains unchanged |
| 5: comparison inventory | Audit below and reproducible experimental patch | Production review must verify the final implementation, including any newer dependency sites |
| 6: invalid inputs | Six stock write methods tested with -5e-10, NaN, positive and negative infinity; measurement non-finite inputs; zero edit succeeds; refused operations preserve stock and ledger | Land guards with regression coverage; service-level checks do not establish the HTTP error contract |

No gate is marked complete solely because an exported experiment passes. Gate 1 requires
a maintainer decision. Gates 2–6 require durable production implementation and regression
evidence. Acceptance must remain a separate bookkeeping-only pull request.

## Comparison audit

| Method or operation | Experiment |
|---|---|
| `ConsumeProduct()` | Scoped availability and candidate whole-entry selection use operand comparison; existing zero/corrupt-row guards remain; transient exhausted request becomes zero |
| `OpenProduct()` | Unopened availability and candidate selection use operand comparison; measurement request and one-unit structural checks remain exact |
| `TransferProduct()` | Scoped source availability and candidate selection use operand comparison |
| `InventoryProduct()` | Equality and direction use operand comparison; equal counts retain the validation error |
| Purchase, self-production, positive inventory undo | Compare total stock with booking before deciding shortage or zero |
| Transfer-to and transfer-from undo | Compare stock and booking operands before zero/negative decisions; identity checks remain |
| Open, edit and whole-transfer undo guards | Existing amount-match guards use the selected operand comparison |
| `RecipesService::ConsumeRecipe()` | Positive-stock check and availability clamp use the selected predicates |
| `EditStockEntry()`, `MeasureStockEntry()` | Coherence against exactly one unit, independent of tolerance |
| Input signs and transaction classification | Exact signs; invalid finite and non-finite inputs refused before mutation |
| Printer and shopping-list rounding | The three `round()` calls remain unchanged |
| SQL and browser stock comparisons | Unchanged; remain the ADR's explicit deferred scope |

## Reproduce

The runner requires Git with `merge-tree --write-tree`, Python 3, and Docker or Podman.
Both pinned commits must be available locally. The PHP image must contain the project's
Composer dependencies and PostgreSQL driver; the repository's development Dockerfile
provides them. Supply available image tags with `PHP_IMAGE` and `PG_IMAGE` if the recorded
local tags do not exist. Record any version difference when comparing results.

```sh
.spike-adr32/run.sh baseline > /tmp/adr32-baseline.json
.spike-adr32/run.sh absolute > /tmp/adr32-absolute.json
.spike-adr32/run.sh relative > /tmp/adr32-relative.json
PHP_PRECISION=17 .spike-adr32/run.sh absolute > /tmp/adr32-absolute-17.json
PHP_PRECISION=17 .spike-adr32/run.sh relative > /tmp/adr32-relative-17.json
```

Set `ADR32_FAST=1` to omit the three 1,000-booking observations. `ENGINE=podman` selects
Podman explicitly. The runner cleans up only the uniquely named containers, network, and
temporary export it creates. It emits JSON containing each check and observation;
inspect the `pass` fields, since baseline failures are expected evidence and do not make
the runner exit unsuccessfully. A setup failure does exit unsuccessfully.

The spike makes no coverage-floor claim. It changes no production application code;
full-suite coverage, HTTP validation, PostgreSQL 15, and concurrency regression runs
remain implementation verification work.
