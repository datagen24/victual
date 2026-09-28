# ADR-0034 acceptance experiment

The roll-up experiment passes sixteen PostgreSQL checks. Nine of those checks fail
against the current view. This establishes the proposed calculation including inactive intermediate
groups; the browser checks below complete [ADR-0034's prerequisites](../../docs/adr/0034-product-group-minimum-counts-descendant-groups.md#acceptance-prerequisites).

## Run

Use a disposable PostgreSQL database migrated by this checkout, with pgTAP available.
The database role must be able to replace views and create the pgTAP extension.
Set `PGHOST`, `PGPORT`, `PGUSER`, and `PGPASSWORD` for that database server.

```sh
.devtools/pgsql/run-tests.sh groupminstock
.devtools/adr0034/run.sh victual_group_min_stock current
.devtools/adr0034/run.sh victual_group_min_stock candidate
```

The `current` run is a negative control and must exit 1 with nine failed assertions
against commit `956c5a07`. The `candidate` run must exit 0 with sixteen passing assertions.
Both runs use a transaction and roll back the fixture rows, extension creation, and
view replacement. A SQL error terminates the connection and rolls back the transaction.

`rollup.sql` is an experiment, not a migration. It joins active products to every
ancestor through `product_groups_resolved`, then sums their effective stock once per
ancestor. It does not filter group activity during traversal. The maintainer confirmed this behavior
on 2026-09-28. The fixtures cover inactive intermediate groups, inactive leaf groups,
and inactive products beneath them.

## Evidence

Measured on 2026-09-28 against checkout `5b0d152d` (application base `956c5a07`) in
`/Users/speterson/.codex/worktrees/b031/grocy`, with the expanded fixtures and recorded confirmation
in the working copy. The runtime used PHP 8.4.25 and PostgreSQL 16.15 in a disposable container
built from `localhost/victual-pg:pgtap`.

| Prerequisite | Result |
|---|---|
| 1: database calculation and activity decision | Cleared by the experiment: sixteen assertions pass; current view fails nine. The maintainer confirmed the inactive-subgroup rule on 2026-09-28. |
| 2: controller and browser behavior | Cleared: the controller includes descendant products, the browser filters by ids and displays paths, and the descendant probe passes with current and experimental SQL. |
| 3: accurate Manual | Corrected to describe direct membership, effective stock, opened-stock exclusion, and no unit conversion. Vale reports no findings. Descendant wording must accompany delivery. |

The `groupminstock` phase passed all 25 assertions again on 2026-09-28. The
`productgroups` phase passed all 41 assertions on 2026-09-27. These phases guard
current behavior and do not establish descendant behavior.

## Controller and browser evidence

Measured on 2026-09-28 against checkout `04bfd5b2` plus the controller, template,
JavaScript, and test changes in this working copy. PHP 8.4.25 and PostgreSQL 16.15
served a disposable dev instance; Playwright 1.56.1 drove local Chrome.

Run these probes against a disposable dev or demo instance with frontend dependencies
installed. See the [run-app skill](../../.agents/skills/run-app/SKILL.md) for setup.
Set `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` if using a local Chrome executable.

```sh
node .devtools/frontend/group-min-stock-descendants.js http://127.0.0.1:8344
node .devtools/frontend/group-min-stock.js http://127.0.0.1:8344
node .devtools/frontend/s29-payload.js --url http://127.0.0.1:8344 --out /tmp/adr34-s29.json
.devtools/pgsql/run-tests.sh stockpages
```

The descendant probe fails against the original controller because the zero-stock
descendant is absent. It passes with the changed controller and browser, both before
and after applying `rollup.sql` to the disposable database. It verifies independent
filters for same-named groups, full paths, inactive intermediate groups, and exclusion
of unrelated branches and inactive products. The existing group-minimum probe also
passes. The `frontend-security` CI job invokes both probes.

The security probe reports 28/28 clean checks. The stock-page suite passes 62 tests
and 527 assertions, including the new controller assertion. Vale reports no new
findings, and JavaScript syntax checks and `git diff --check` pass.

The complete `run-tests.sh all` coverage run increased from 10,607/11,067 lines
(95.8435%) to 10,611/11,071 (95.8450%). `StockController` remains 100% covered:
459/459 lines before and 463/463 after. These are runner measurements, excluding the
separate label and middleware steps that CI merges into its report.

Both runs reproduce the same five `HelperUnitsTest` failures: helper subprocesses
try to dynamically load extensions compiled into this PHP image, and startup warnings
contaminate their JSON output. No new test failures were found. The baseline coverage
report exceeded the image's 128 MB memory limit; rerunning the report with
`php -d memory_limit=512M` succeeded. The changed run used the same 512 MB limit.

Reproduce the coverage run with `SUITE_COVERAGE=1 SUITE_COVERAGE_CLOVER=clover.xml
.devtools/pgsql/run-tests.sh all` on each revision, then use
`.devtools/coverage/inventory.php` to inspect the controller's line counts.

The runtime minimum calculation remains direct-members-only. This change adds the
controller and browser behavior; `rollup.sql` remains an acceptance experiment.

ADR-0034 is Accepted as of 2026-09-28. This README records its acceptance evidence.
Its two open questions about shopping-list automation and mixed units remain deferred
design work, not additional acceptance gates.
