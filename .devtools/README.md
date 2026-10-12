# Developer tooling

`.devtools/` holds the test suites, CI checks, documentation tooling, and decision evidence
that sit outside the application. Nothing here is loaded by the running application.

Read [AGENTS.md](../AGENTS.md) first for the three test tiers and the coverage floor. The
[plan index](../docs/plans/README.md) and [ADR index](../docs/adr/README.md) record what each
harness was built for and the state of that work; this file only locates the tooling.

## Test suites

| Path | Contents | Entry point | Authority |
|---|---|---|---|
| `pgsql/` | The differential suite and its phases: PHP test scripts, trigger and view SQL fixtures, the migration and runtime-SQL checks, the ledger generator, and the upgrade rehearsal. | `.devtools/pgsql/run-tests.sh <phase>` | [ADR-0025](../docs/adr/0025-three-test-tiers.md); [upgrade rehearsal README](pgsql/upgrade-rehearsal/README.md) |
| `pgtap/` | pgTAP tests for the SQL logic migrations add. | `.devtools/pgsql/run-tests.sh pgtap` | [pgtap README](pgtap/README.md) |
| `frontend/` | Playwright probes for the browser, including the `s29-payload.js` sink check. | `node <probe>.js --url <base url>`, run in `frontend/` | [frontend README](frontend/README.md) |
| `parity/` | HTTP comparison of this fork against upstream grocy 4.6.0, with a container stack and a fake-time helper. | `.devtools/parity/stack/stack.sh` | [parity README](parity/README.md) |
| `labels/` | Label subsystem tests: identity, registry, print jobs, rendering, the worker API, and canonical JSON. | `php .devtools/labels/<name>-tests.php` | [labels README](labels/README.md) |
| `mqtt/` | MQTT state publication checks, a broker and InfluxDB stand-in, and a payload assembler. | `php .devtools/mqtt/<name>-check.php`; `.devtools/mqtt/engine-diff.sh` | [Plan 18: MQTT state publication](../docs/plans/18-mqtt-state-publication.md) |
| `middleware/` | `PathParameterMiddleware` driven through a real Slim route dispatch, with no database. | `php .devtools/middleware/path-parameter-tests.php` | `tests.yml` in [.github/workflows](../.github/workflows/tests.yml) |
| `coverage/` | Line-coverage collection, filtering, inventory, and the report that CI ratchets on. | `php .devtools/coverage/report.php` | [coverage README](coverage/README.md) |

## CI and repository checks

| Path | Contents | Entry point | Authority |
|---|---|---|---|
| `ci/` | Python checks CI runs: changed-path detection, ADR headers, the deploy manifest, the OpenAPI document, vendor paths, Helm chart parity, and the k3s renderer. Each check has a `test_*.py`. | `python3 .devtools/ci/<check>.py` | [ci README](ci/README.md) |
| `check-cited-jobs.php` | Fails when the documentation names a CI job that does not exist. | `php .devtools/check-cited-jobs.php` | Header comment in the script |
| `check-path-id-validation.php` | Fails when an id-shaped API path parameter is untyped in `victual.openapi.json`. | `php .devtools/check-path-id-validation.php` | [Plan 11: API error handling](../docs/plans/11-api-error-handling.md) |
| `nix/` | `walk.py`, which drives a running image over HTTP: every top-level page, the API read side, and one write cycle. | `.devtools/nix/walk.py --base-url <url> --password <admin password>` | [Plan 20: container infrastructure](../docs/plans/20-container-infrastructure.md) |

## Documentation tooling

| Path | Contents | Entry point | Authority |
|---|---|---|---|
| `docs/` | The staging script and assets for the documentation site. | `python3 .devtools/docs/stage.py`, then `mkdocs build` | [docs README](docs/README.md) |
| `vale/` | Vale styles, installer, baseline audit, and the staged-file check the pre-commit hook runs. | `python3 .devtools/vale/install.py`; `python3 .devtools/vale/audit.py --check` | [vale README](vale/README.md) |
| `diagrams/` | Builds the HTML diagrams in `docs/diagrams/`. | `python3 .devtools/diagrams/build.py docs/diagrams` | [diagrams README](diagrams/README.md) |

## Decision evidence

Each directory holds the probes and recorded results behind an ADR's acceptance
prerequisites or a design question. `RESULTS.md` (or `README.md`) states what was run and
what it returned; `evidence/` holds the raw output. Re-running a probe reproduces its
evidence; it is not part of any CI job.

| Path | Entry point | Authority |
|---|---|---|
| `adr0022/` | Numbered SQL files, applied in order | [ADR-0022](../docs/adr/0022-open-containers-carry-a-measured-remainder.md) |
| `adr0025/` | `RESULTS.md` only | [ADR-0025](../docs/adr/0025-three-test-tiers.md) |
| `adr0027/` | `rehearse.sh`; `snapshot-diff.py` | [ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md) |
| `adr0029/` | `probe.py`; `source_probe.py` | [ADR-0029](../docs/adr/0029-stock-locations-reference-existing-locations.md) |
| `adr0032/` | `run.sh` | [ADR-0032](../docs/adr/0032-stock-amounts-compare-within-one-tolerance.md) |
| `adr0034/` | `run.sh` | [ADR-0034](../docs/adr/0034-product-group-minimum-counts-descendant-groups.md); [adr0034 README](adr0034/README.md) |
| `adr0036/` | `run.sh` | [ADR-0036](../docs/adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md) |
| `adr0037/` | `run.sh` | [ADR-0037](../docs/adr/0037-an-undo-of-a-whole-row-consumption-revives-the-stock-entry-label-it-retired.md) |
| `adr0040/` | `run.sh`; `repro.sh` | [ADR-0040](../docs/adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md) |
| `adr0041/` | `run.sh`; `run-all.sh` | [ADR-0041](../docs/adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md) |
| `adr0042/` | `run.sh` | [ADR-0042](../docs/adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md) |
| `issue648/` | `run.sh` (a Swift package that checks OpenAPI union decoding) | [ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md) |
| `issue742/` | `run.sh` | [ADR-0043](../docs/adr/0043-creating-shared-master-data-is-a-right-below-master-data-edit.md) |
| `home-assistant/` | `probe/deploy.sh <ssh-host>`; `lifecycle-spike/spike.sh` | [Plan 35: Home Assistant target](../docs/plans/35-home-assistant-target.md) |
| `plan22/` | `LEDGER.md` and `NATIVE-ACCEPTANCE.md`, working records with no scripts | [Plan 22: medication tracking](../docs/plans/22-medication-tracking.md) |
| `spikes/oauth-hydra/` | `run.sh` | [oauth-hydra README](spikes/oauth-hydra/README.md) |

## Inherited from upstream grocy

| Path | Contents |
|---|---|
| `data_generation_scripts/` | PHP scripts that run inside `DatabaseMigrationService` to load large demo datasets or reset the administrator password, plus a meal plan spreadsheet. No document in this repository references them. |
| `install_dependencies.bat`, `update_dependencies.bat` | Windows wrappers for `composer install`/`update` and `yarn install`/`upgrade`, run from the repository root. |
| `transifex_*.bat` | Windows wrappers for the Transifex CLI (`tx pull`, `tx push`) over `localization/`. |
