# ADR-0027 decision 2 (issue #650): implementation evidence

Measured 2026-10-04 on branch `claude/opus5_api-timestamps-rfc3339-utc-b4bb3d`, from base
`origin/master` at `3fbfc88c`. This file is evidence for ADR-0027's acceptance prerequisite 5
and for issue #650's checklist. It accepts nothing: acceptance is its own pull request.

**Production was not migrated, and no K3S cluster was used.** No production instance of
Victual exists (maintainer, 2026-10-04). Every database below was a disposable Podman
container.

## Environment

| Item | Version |
|---|---|
| Host | macOS 27.0.1, Apple Silicon; Podman VM 4 CPU, 8 GiB |
| PostgreSQL 15 | 15.19 (Debian 15.19-1.pgdg13+2), pgTAP 1.3.4, system tzdata 2026c |
| PostgreSQL 16 | 16.15 (Debian 16.15-1.pgdg13+2), pgTAP 1.3.4, system tzdata 2026b |
| PHP (CI's version) | 8.4.25, timezonedb 2026.3 (`Dockerfile` dev target with `FROM php:8.4`) |
| PHP (production images) | 8.5.10, timezonedb 2026.3 (`victual:dev`) |
| Browser | Playwright 1.56.1 Chromium, Node 26.10.0 |
| MQTT, InfluxDB | `eclipse-mosquitto:2`, `influxdb:2.7`, local containers |

The containers were `victual-pg15-650` and `victual-pg16-650` on network
`victual-suite-650`, both on a tmpfs data directory with a 3 GiB limit. A first full run on
PG16 with a 1.5 GiB limit lost a backend to the cgroup OOM killer (`signal 9`) because the
tmpfs counts against the limit; that run was discarded.

## Decisions recorded before they were relied on

ADR-0027's three open questions were answered by the decider on 2026-10-04 and recorded in
the ADR (commit `153b10ce`):

1. `time_local` keeps the server's offset: `2026-10-04T14:30:00.000000-04:00`.
2. Six fractional digits on every instant. Variable-length fractions break text ordering
   (`…:07.5Z` sorts before `…:07Z`); truncating discards the label surface's microseconds.
   ADR-0028 is amended to keep a write's fraction to the microsecond, truncated.
3. The views phase compares instants, with the SQLite side read in a named source zone.

## The conversion rule, measured

Neither engine's default implements "a repeated hour is the earlier instant, a skipped hour
is refused":

| Wall clock | Zone | PostgreSQL `AT TIME ZONE` | PHP `new DateTimeImmutable` | Required |
|---|---|---|---|---|
| `2026-11-01 01:30:00` | America/New_York | `06:30Z` (later) | `05:30Z` | `05:30Z` |
| `2026-04-05 01:45:00` | Australia/Lord_Howe | `15:15Z` (later) | `15:15Z` (later) | `14:45Z` |
| `2026-03-08 02:30:00` | America/New_York | `07:30Z` (moved) | `07:30Z` (moved) | refused |

`Instant::FromWallClock()` (PHP) and `victual_local_to_instant()` (SQL) implement the rule
with the same algorithm. `TimestampInstantTest` asks both about every transition from 2020
to 2027 in eight zones (New York, London, Lord Howe, Santiago, Kathmandu, Chatham,
St John's, UTC), at 15-minute steps either side, strict and lenient, and requires identical
answers. It passed on PG15 and PG16, under PHP 8.4.25 and 8.5.10. The migration preflight
also compares PHP's and PostgreSQL's zone data at every transition in the data's years.

## Requirement-to-evidence matrix

| Issue #650 item | Evidence | Status |
|---|---|---|
| Migration to `TIMESTAMPTZ`, earlier instant in a repeated hour | `migrations/0301.pgsql.php`, `services/Database/TimestampMigration.php`; pgTAP `027-timestamptz-conversion.sql` (28 assertions); rehearsal below | Done |
| Proven on a copy of real data with a repeated-hour value | Rehearsal on parity-generated data with a labelled fixture; no real data exists | Done with the stated substitution |
| Preflight; abort with a report | `bin/victual-timestamp-preflight`; refusal rehearsal below; `TimestampInstantTest::testThePreflightCountsAndRefusesWhatItCannotConvert` | Done |
| Legacy SQLite import by the same rule | `DatabaseImporter::SourceInstant()`, preflight `AssertSourceTimestamps()`, verification compares instants; `import` phase; `TimestampInstantTest::testTheImportReadsWallClocksByTheSameRule` | Done |
| Renderer: column type, not field names | `InstantStatement` (pdo_pgsql metadata, measured on tables, views, aliases, expressions, joins, unions); `testEveryFetchModeConvertsInstantsAndOnlyInstants`, `testTimestampShapedTextIsLeftAlone` | Done |
| ADR-0028 write path stores an instant | `ParseApiDateTime()`, `RequestedTimestamp()`; WireContractTest accepted/refused/DST cases | Done |
| Offset-free read in server zone; skipped refused; repeated earlier; offset is the instant | `testOnlyTheSkippedHourIsRefusedInADstZone`, `testTheSkippedWallClockRuleHoldsAcrossZones` | Done |
| OpenAPI: instants `format: date-time`, three DATEs `format: date`, same commit as the wire | `567507ec`; `testEveryInstantIsTypedDateTimeWithTheWirePattern`, `testDateBackedColumnsAreTypedDate` | Done |
| Browser shows device zone, sends an offset | `public/js/victual.js` `Victual.Instant`, the five named files plus `choreform.js`, `calendar.js`, the picker; `.devtools/frontend/timestamp-instants.js` 28/28 | Done |
| `track_date_only` bare date unchanged | Probe: date-only chore booked at the server-zone midnight; WireContractTest browser renderings | Done |
| MQTT payloads | `StateSnapshotAssembler`; `mqtt` phase; live check against local Mosquitto below | Done (local stand-in) |
| iCal feed | `CalendarApiController`; `CalendarIdentityTest`; feed inspected below | Done |
| Contract snapshots regenerated: instants in the new format, DATEs unchanged | `eab2bd7e`; `snapshot-diff.txt` | Done |
| `views` phase compares instants | `difftest.php`, `trigdifftest.php`, `ValueComparison::NormaliseInstant()`; negative controls in `TimestampInstantTest` | Done |
| Parity accepted difference compares instants | `adr-0027-timestamps-are-utc-instants`; `harness/selftest.js` controls | Done; full parity stack not run |
| Open questions answered | `153b10ce` | Done |
| Endpoint clients regenerated | `CLIENT-HANDOFF.md` | **Not done**: handoff only |
| `run-tests.sh all` green, contract against the committed snapshot | PG15 and PG16 runs below | See below |

## Rehearsal on generated data

There is no production data. As the maintainer directed, the data came from the parity
suite's year generator (`.devtools/parity/harness/run-year.js --profile year`, seed
20260905), replayed through the API of `origin/master` (migration 300) running with
`date.timezone = America/New_York`. Its committed plan lock did not match the generator, so
a scratch copy was run with `--update-plan-lock`; the tracked lock is unchanged. The replay
ran on the real clock (no faked clock) and stopped at day 300 of 2024 on a checkpoint
mismatch the harness expects without its clock; the database then held 1,634 ledger rows,
447 chore executions, 18 charge cycles and 305 tasks.

The data stops before New York's 2024-11-03 fall-back and holds no label. `rehearse.sh`
therefore adds, to each disposable copy only, a chore named `REHEARSAL FIXTURE repeated
hour` with executions at `2024-11-03 01:30:00` and `01:59:59.250000`, and one label
`0REHEARSA1B2C`. These are fixtures, not household data. The refusal copy adds one execution
at `2024-03-10 02:30:00`, a skipped hour.

| Run | Result |
|---|---|
| New York, convert | Preflight exit 0: 6,245 values in 62 columns, 4 in a repeated hour. Migration exit 0. Row counts, null counts, stock quantities, the ledger hash, task due dates and label identities identical before and after. 449 of 449 chore times equal an independent oracle. Fixtures: `2024-11-03T05:30:00.000000Z`, `2024-11-03T05:59:59.250000Z`. Second migrator run: no change. |
| New York, refuse | Preflight exit 2 naming counts only. Migrator exit 1, "Nothing was changed"; schema still at migration 300 with `TIMESTAMP` columns. |
| UTC (the maintainer's servers) | Conversion is the identity: fixture `2024-11-03T01:30:00.000000Z`. Same identity checks pass. |

The 62 columns include the rehearsal's own oracle table (`rehearsal_expected`); the
application schema has 61.

**Lock and rewrite cost.** The migration is one transaction holding exclusive locks for its
whole duration. `TimestampMigration::Apply()` took 0.22 s on the household-sized copy
(5,790 values) and 4.17 s on a copy scaled to 491,834 ledger rows and 45,147 chore
executions (624,490 values), on this machine.

**Recovery.** Every rehearsal began by restoring the pre-upgrade `pg_dump` into an empty
database, which is the documented recovery procedure. A `TIMESTAMPTZ` to `TIMESTAMP`
downcast was not used and is not documented as a rollback.

## Integrations

Against the branch app (`date.timezone = America/New_York`) with local Mosquitto and
InfluxDB, under the test-only topic prefix `victual650test`, discovery prefix
`homeassistant650test` and bucket `victual650`:

- Retained MQTT states carry instants in the wire rendering; a task's due date is the end
  of its day in the server zone; stock best-before dates are unchanged. A purchase
  republished the stock state (13 → 14).
- The purchase's `price_paid` point was stored at `2026-10-04T19:12:56Z`, the ledger row's
  instant, not shifted by the server zone. Redelivering the event, and a copy carrying
  pre-0301 wall clocks, both landed on that one point; nothing was left undelivered or
  dead-lettered.
- The iCal feed sends timed events as `DTSTART;TZID=America/New_York` with a generated
  `VTIMEZONE`, and all-day events as `VALUE=DATE`. A chore due at the repeated 01:30 is
  `20261101T013000`, which RFC 5545 reads as the first occurrence.

## Browser

`.devtools/frontend/timestamp-instants.js`, server zone America/New_York, device zones
Europe/London and Pacific/Auckland: 28/28 checks passed. In both device zones the date-only
chore shows the server's day (2026-10-04) while the device's own day is 2026-10-05.
`s29-payload.js`: 27/28, the same single failure (`manageapikeys-qr`) as on a clean
`origin/master` instance.

One pre-existing defect was found: the reschedule modal sent `""` for an unassigned user,
which the database refuses, with either date format. PR #651 fixed it on master, and this
branch merged that fix, keeping both changes to the save.

## Test runs

| Run | Revision | Result |
|---|---|---|
| `run-tests.sh all`, PG15, PHP 8.4.25 | `5fcb7e8d` | SUITE PASSED |
| `run-tests.sh all` with `SUITE_COVERAGE=1`, PG16, PHP 8.4.25, plus CI's measured label scripts | `5fcb7e8d` | SUITE PASSED; coverage below |
| `views`, `triggers`, `migrate`, `import` under `date.timezone` and `TZ` America/New_York, PG15 | harness fix after `4981ab77` | all passed (see the note below) |
| `wirecontract` (incl. `TimestampInstantTest`), PG15, PHP 8.4.25 | `5fcb7e8d` | 116 tests passed |
| `wirecontract`, PG15, PHP 8.5.10 | working copy after `eab2bd7e` | 105 tests passed |
| pgTAP, PG15 and PG16 | | 19 files, PASS |
| Parity harness self-test | | 7/7 |

**The New York differential run found three things, none of them a conversion defect.**
The harness's own PostgreSQL connections did not set the session zone the application
always sets, so the trigger phase read its literals in UTC and `chores_current` derived a
date-only chore's day in UTC; both agreed with SQLite under UTC only by coincidence. The
harness now sets the session zone to its named source zone. And `batteries_current`'s
"never" differs by design: SQLite's `2999-12-31 23:59:59` read in New York is
`3000-01-01T04:59:59Z`, while migration 0301 fixed "never" at `2999-12-31T23:59:59Z` on every
server. That one literal is mapped explicitly; nothing else is exempted.

## Coverage

CI-equivalent run on PG16 at `5fcb7e8d`. It runs `run-tests.sh` under `SUITE_COVERAGE=1`,
then the label and middleware scripts the `suite` job measures, then `report.php` with the
job's `--expect` list and `--min=96.31198844487241217394`. Result: **11,684 of 12,095
executable lines, 96.60%**, with `report.php` exit 0. The same job run on `origin/master`
(`3fbfc88c`) measured 11,260 of 11,667, 96.51%, so the aggregate rose.

No file is below 75%. Every touched application file is at or above its master figure, with
one exception: `helpers/extensions.php` went from 98.76% to 98.68%. This branch deleted
covered lines there (`ApiDateTimeWallClock()`). The file's two uncovered lines run when
Composer loads the file, before coverage starts, so no test can reach them. New files:
`Instant.php` 100%, `InstantStatement.php` 98.81%, `TimestampMigration.php` 98.96%.

The dev image ships no `php.ini`, so the run mounted `memory_limit = -1`; with PHP's 128 MB
default the coverage merge exhausts memory. `canonical-json-tests.php` needs Node, which the
dev image lacks; its PHP half ran and was measured, and its oracle half did not.

The coverage run's label scripts also found one defect, fixed in `4981ab77`: worker
credential rotation read `api_keys.expires` with `Instant::Parse()`, which refuses a wall
clock, and the label suites build a schema that predates migration 0301.

## Limitations and unverified gates

- No real household data exists; the rehearsal used generated data plus labelled fixtures.
- The full parity stack (`bin/parity all`) and the Nix image build were not run; they need
  the Nix-built images, which were not built in this session. PHP 8.5.10, the production
  images' version, was tested with the dev image instead.
- The CI step `renderer-agreement` needs the Rust renderer and was not run locally.
- MQTT and InfluxDB were local stand-ins, as directed; Home Assistant itself was not used.
- Userfield values of type `datetime` are household-defined text in
  `userfield_values.value`, not timestamp columns, and are not converted.
- `victual-kit` was not regenerated (`CLIENT-HANDOFF.md`).
