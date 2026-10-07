# Upgrade rehearsal evidence, 2026-10-06

This record is evidence for issue #650 and for ADR-0027 acceptance prerequisite 5. The
upgrade goes from a reproducible database written by `v0.2.0-MVP` to the 0.3.0 release
candidate, through the candidate's migrations. It accepts nothing. Acceptance is a separate
pull request.

Every database in this record was a disposable Podman container. No deployed database was
used. No production instance exists.

## Revisions and environment

| Item | Value |
|---|---|
| Source | `v0.2.0-MVP`, `ecc2944ae8544255ef4462890ea415bf02fd02cd`, migration 288 |
| Target | `master` at `3bf733f5cf9ff82b60f423dd1c9471efeb048ce9`, migration 302, plus this branch's fix to `DatabaseMigrationService::SyncUserSettingDefaults()` (below). The branch changes no migration. |
| Configured zone | `America/New_York` (`php -d date.timezone`) |
| PostgreSQL | 15.19 (Debian 15.19-1.pgdg13+2) and 16.15 (Debian 16.15-1.pgdg13+2), with pgTAP, on tmpfs |
| PHP | 8.5.10 with timezonedb 2026.3, in the dev image (`--target dev`). `composer.lock` and `Dockerfile` are identical at the source and the target, so one image runs both trees. |
| Oracle | Python 3.13.9 `zoneinfo`, with system zone data 2026c |
| Host | macOS 27.0.1, Apple Silicon. Podman VM with 4 CPUs and 8 GiB. |

## Fixture

`generate.php` ran against the source tree at migration 288 and wrote the following data:

- 225 ledger rows over 90 days from 2024-09-20, booked through `StockService`:
  - 92 purchases and 80 consumes, 13 of which were undone
  - 21 opens
  - 16 transfers
- 73 stock rows across 10 products.
- 118 chore executions and 14 battery charge cycles, at explicit wall clocks.
- 12 tasks, with due dates and completions, and 14 meal plan days.
- 4 labels issued through `LabelIdentityService`: two for locations, one for a product and
  one for a stock entry. The garage location label was retired by deleting its location.

The data has 61 legacy `TIMESTAMP` columns with 1,079 values, 39 `TIMESTAMPTZ` columns, and
11 `DATE` columns with 779 values.

The named values below have their UTC instants written by hand in `generate.php`. The rules
are the 2024 New York rules: EDT until 2024-11-03 02:00, then EST, with 2024-03-10 02:00 to
03:00 skipped.

| Table and column | Wall clock (New York) | Expected | Later reading (must not appear) |
|---|---|---|---|
| `chores_log.tracked_time` (via `TrackChore`) | 2024-11-03 01:30:00 | 2024-11-03T05:30:00.000000Z | 06:30:00Z |
| `chores_log.tracked_time` | 2024-11-03 01:59:59.25 | 2024-11-03T05:59:59.250000Z | 06:59:59.25Z |
| `battery_charge_cycles.tracked_time` | 2024-11-03 01:45:30.5 | 2024-11-03T05:45:30.500000Z | 06:45:30.5Z |
| `tasks.done_timestamp` | 2024-11-03 01:05:00 | 2024-11-03T05:05:00.000000Z | 06:05:00Z |
| `stock_log.row_created_timestamp` | 2024-11-03 01:20:00.000001 | 2024-11-03T05:20:00.000001Z | 06:20:00.000001Z |
| `stock_log` control | 2024-11-03 00:59:59.999999 | 2024-11-03T04:59:59.999999Z | |
| `stock_log` control | 2024-11-03 02:00:00 | 2024-11-03T07:00:00.000000Z | |
| `stock_log` control | 2024-03-10 01:59:59 | 2024-03-10T06:59:59.000000Z | |
| `stock_log` control | 2024-03-10 03:00:00 | 2024-03-10T07:00:00.000000Z | |
| `tasks.done_timestamp` | 2024-07-04 12:00:00 | 2024-07-04T16:00:00.000000Z | |
| `tasks.done_timestamp` | 2024-12-25 08:30:15 | 2024-12-25T13:30:15.000000Z | |

The fixture also has three existing instants. The retired label has `retired_at`
2024-11-03T05:30:00.123456Z, inside the repeated hour. A live location label has
`row_created_timestamp` 2024-06-15T16:34:56.000001Z. The stock entry label has
`row_created_timestamp` 2024-03-10T07:00:00.5Z.

The invalid fixture is the same data plus a chore execution at 2024-03-10 02:30:00, in the
skipped hour, and a task completion of `infinity`.

## Commands

The full procedure is in [README.md](README.md). The runs used:

```bash
git worktree add <scratch>/v020 v0.2.0-MVP
REHEARSAL_NETWORK=victual-g650 REHEARSAL_IMAGE=victual:dev-g650 \
  .devtools/pgsql/upgrade-rehearsal/rehearse.sh victual-pg16-g650 <scratch>/v020 <scratch>/run-pg16
```

The PostgreSQL 15 run used the same command against `victual-pg15-g650`.

## Results

### Attempts that failed

1. **Run 1 (PostgreSQL 16):** `verify.py` crashed. `snapshot.php` wrote an empty table as a
   JSON list. The fix was in the tool.
2. **Run 2 (PostgreSQL 16), target `3bf733f5` unchanged.** This run found two differences
   that needed explaining and one defect:
   - Migration 0292 rebuilds the two price caches with new surrogate ids, and one average
     differed in its last bit. `verify.py` now compares those tables by content, with prices
     to 12 significant digits.
   - The upgrade itself writes `system_db_changed_time`.
   - **The defect:** the second `bin/victual-migrate` run, with nothing to migrate, still
     advanced `system_db_changed_time`. The cause was
     `DatabaseMigrationService::SyncUserSettingDefaults()`, which upserted every default
     user setting on every run, and each upsert counted as a write. `v0.2.0-MVP` behaves the
     same way. The migrator runs at every pod start, so every restart told polling clients
     to refetch.

   The fix writes a default only when it is missing or different. It advances the changed
   time only when it wrote a default. The regression test is
   `tests/Pgsql/MigrationRerunChangedTimeTest.php`, in the `credentialsplit` phase. Without
   the fix, `testARunWithNothingToMigrateLeavesTheChangedTimeAlone` failed. With the fix,
   the phase passed 16 tests.

### Passing runs

Both PostgreSQL versions gave the same `verify.py` output.

| Requirement | Result |
|---|---|
| 1. Legacy timestamps convert to the expected instants | **Pass.** All 61 columns are `TIMESTAMPTZ`. 1,078 values equal the `zoneinfo` oracle, and 5 of them are in the repeated hour. The other converted value is `system_db_changed_time`, which the upgrade writes itself. 651 NULLs stayed NULL. Every named value equals its written expectation, and none took the later instant. |
| 2. Existing instants keep their meaning and precision | **Pass.** 20 `TIMESTAMPTZ` values have identical epoch microseconds, including the three named label instants. |
| 3. Calendar dates unchanged | **Pass.** 779 values in 11 `DATE` columns are byte-identical, and their types are unchanged. |
| 4. Row counts, quantities, history and labels intact | **Pass.** Every other value of every table is unchanged. There are five named exceptions: the `migrations` rows for 0289 to 0302, the three `permission_hierarchy` rows from 0299, `system_db_changed_time`, and the two caches compared by content. The 4 labels resolve to the same targets. |
| 5. Re-running the upgrade changes nothing | **Pass.** After the second run, 1,018 rows in 73 tables have 0 differences. This needs the fix above. |
| 6. Invalid data is refused without partial conversion | **Pass.** The preflight exited 2 and named the skipped wall clock and the infinity. The migrator exited 1 with "Nothing was changed". All 61 columns are still `TIMESTAMP`, and 1,082 wall clocks are byte-identical. |
| Fixture reproducible | **Pass.** The two generations have identical manifests and 10,008 identical values. The comparison skips only values the source code takes from `uniqid()`, random tokens and password salts. |
| Negative controls | **Pass.** `verify.py` detected each planted fault: a repeated hour read as the later instant, an instant moved by 1 µs, a changed best-before date, a lost ledger row, and a second run that writes. |

**A refusal is not a return to 0.2.0-MVP.** Each migration commits on its own, so
migrations 0289 to 0300 stayed applied, and the refused database was at migration 300. The
operator page "Updating and migrations" now says this.

The preflight also ran against the source schema at migration 288 before the upgrade. It
exited 0 and reported 1,079 values in 61 columns, 5 of them in a repeated hour. The upgrade
took between 1.3 s and 1.6 s and reported 1,091 converted values. The extra 12 are the
`migrations` rows that 0289 to 0300 add in the same run.

## Full suite

The suite ran on the branch with the fix, at commit `324fa6bb`. The later commits on the
branch change only documentation and this directory.

| Run | Result |
|---|---|
| `run-tests.sh all`, PostgreSQL 15.19 | **SUITE PASSED.** The `contract` phase passed 16 tests against the committed snapshots, with `CONTRACT_REGEN` unset, and left the snapshots unchanged. |
| `run-tests.sh all` with `SUITE_COVERAGE=1`, PostgreSQL 16.15, first attempt | Not counted. The PostgreSQL container hit its 3 GiB memory limit during the `recipeoperations` phase, a backend was killed, and 8 cases failed with "no connection to the server". |
| The same, second attempt, on a new container with 4 GiB | Every test case passed. The runner then reported one failing case: its closing `report.php` ran out of PHP's default 128 MiB. The local environment notes describe this failure. I ran `report.php` again by hand with `memory_limit=2560M`. |

Coverage is measured by the suite alone, without CI's label scripts, the same way on both
trees: PostgreSQL 16.15, `SUITE_COVERAGE=1`, and `report.php` with `memory_limit=2560M`.

| Tree | Aggregate | `DatabaseMigrationService.php` |
|---|---|---|
| master `3bf733f5` | 11,636 of 12,095 lines (96.21%) | 142 of 158 lines (89.87%) |
| This branch | 11,641 of 12,100 lines (96.21%) | 147 of 163 lines (90.18%) |

The branch adds five executable lines, and the suite covers all five. The aggregate and the
touched file both rose slightly. The master run's only failing case was the same
`report.php` memory limit.

## Not covered here

- Only one configured zone was rehearsed, `America/New_York`. `TimestampInstantTest` covers
  eight zones for the conversion function itself.
- The CI steps that need the Rust label renderer were not run, so the aggregate above does
  not include them. It is lower than CI's ratchet figure for that reason.
- `victual-kit` regeneration is post-release work after 0.3.0 (ADR-0027 prerequisite 6), and
  it is not part of this record.
