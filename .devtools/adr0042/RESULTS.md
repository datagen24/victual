# ADR-0042 prerequisite evidence

Evidence for [ADR-0042](../../docs/adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)
(Proposed), issue [697](https://github.com/datagen24/victual/issues/697). This directory changes
nothing under `services/`, `controllers/`, `migrations/` or `tests/`, and does not edit or accept
the ADR. The SQL and PHP here are scratch models of the ADR text, not candidate implementations.

## Environment

| Item | Value |
|---|---|
| Run date | 2026-10-09 (host local time, America/New_York) |
| Base commit | `14b2df2e1c3d4f9ff70d09a0d3befef377a6b5ca` (`master`); `run.sh` extracts it with `git archive HEAD` and overlays this directory |
| ADR text read | the file at that commit; the ADR records `master` at `216af2b2`, which is an ancestor of the base commit |
| Host | macOS, Darwin 27.0.0 arm64, podman 6.0.2, libkrun VM with 4 CPUs and 8 GiB |
| PHP image | `localhost/victual:dev`, id `05591fb29900`, PHP 8.5.10, tzdata 2026.3, `date.timezone` UTC |
| PostgreSQL images | `localhost/victual-pg:pgtap` id `b74ab6fca41d` (16.15); `docker.io/library/postgres:15` id `f6a528436858` (15.19); `localhost/parity-postgres-faketime:16` id `ff7f96b525fb` (16.15 with libfaketime, clock probe only) |

Every probe runs in a disposable network with one PostgreSQL and one PHP container named
`c42-<pid>-pg` and `c42-<pid>-php`, in a scratch schema, and removes both afterwards. No existing
container was touched. PHP 8.5 has no `pcntl`, so the concurrent children are `proc_open` processes.

## Commands

```sh
.devtools/adr0042/run.sh all                  # probes 1 to 5 on PostgreSQL 16.15; writes evidence/*.json
.devtools/adr0042/run.sh dates America/New_York   # one PHP zone, JSON on stdout
PG_IMAGE=docker.io/library/postgres:15 .devtools/adr0042/run.sh status > .devtools/adr0042/evidence/pg15-status.json
# likewise history, constraints, today, and dates America/New_York for pg15-*.json
.devtools/adr0042/run.sh clock America/New_York "2026-10-09 03:58:20"        > .devtools/adr0042/evidence/clock-America_New_York.json
.devtools/adr0042/run.sh clock Pacific/Kiritimati "2026-10-09 09:58:20"      > .devtools/adr0042/evidence/clock-Pacific_Kiritimati.json
```

| File | Role |
|---|---|
| `run.sh` | Container lifecycle and probe dispatch. |
| `lib.php` | Connection, scratch schema, JSON output. |
| `estimate.sql` | `adr42_estimate()` (sections 2 and 3) and `adr42_status()` (section 4) as `date + integer` SQL. |
| `model.sql` | Section 1 and 7 tables, constraints, current-fill view, notice function. |
| `dates.php` | Probe 1. |
| `status.php` | Probe 2. |
| `today.php`, `clock.php` | Probe 3: conversion and live checks, and the faked-clock midnight crossing. |
| `constraints.php`, `conc-child.php` | Probe 4. |
| `history.php` | Probe 5. |
| `evidence/` | Output of the runs above. |

## Summary

| Probe | Result | Numbers |
|---|---|---|
| 1. Date arithmetic, PHP against SQL | Pass | 120,036 seeded cases; 0 differences in the `(reorder date, source, reason)` tuple; 86,157 date computations in each of 6 PHP zones; the 4 table examples and `floor(90 * 0.75) = 67` agree |
| 1b. PHP zone independence | Pass for UTC-anchored arithmetic | 0 differences in UTC, America/New_York, Pacific/Kiritimati, Pacific/Pago_Pago, Pacific/Apia, Australia/Lord_Howe; SQL result hash identical in all 6 runs and under 5 session zones |
| 1c. `fraction_elapsed` with a PHP `float` | Fail (a finding) | 37 of 21,536 random cases differ; 44 of 72,270 and 76 of 729,270 grid points differ; SQL `float8` differs from SQL `numeric` by the same counts |
| 2. Status boundary table | Pass | 80 cells (4 reorder dates x 4 leads x 5 offsets); 0 differences between PHP, SQL and the section 4 table; the ADR's verification row differs from section 4 in 2 cells, both at `L = 0` |
| 3. PHP `date()` against `CURRENT_DATE` | Agree on app-style connections; disagree on connections that skip `OnConnected()` | 0 of 70,574 minute points differ app-style; 28,598 differ on a bare UTC session; 0 differences in 11,011,320 instants over 419 zones |
| 4a. Declarative constraints | Pass | 37 cases, 0 unexpected |
| 4b. One open order per recipe | Pass | 2, 8 and 32 concurrent inserts: 80 of 80 rounds had exactly 1 success; every other child got SQLSTATE `23505` (800 of 800) |
| 4c. Receive is atomic | Pass | `SIGKILL` between the two writes in a transaction left 0 fills and the order open; the same writes without a transaction left 1 fill and the order open; 10 of 10 rounds of 8 concurrent receives closed the order once |
| 4d. Acknowledgement idempotent | Pass | 20 rounds of 32 identical `ON CONFLICT DO NOTHING` calls: 20 rounds had 1 row, 1 inserting call, 0 errors, one shared `acknowledged_at` |
| 4e. Notice key regeneration | Pass, with a consequence | Void and re-record changes the fill id, so the key changes even when the data is identical |
| 5. Fill history | Pass with one finding | 13 steps; 12 match the hand-written expectation; step 1.3 shows the derived explicit date coming back to life |

Two questions stay inconclusive because one host cannot measure them: clock skew between a PHP host
and an external PostgreSQL host, and tzdata skew between the production PHP and PostgreSQL images.

PostgreSQL 15.19 gave identical counts for probes 1 (New York run), 2, 3, 4 and 5.

## Probe 1: date arithmetic

`dates.php` builds 120,036 cases from `mt_srand(20261009)`. There are 60,000 uniform dates in 1990
to 2060, and 12,000 each near February 27 to March 1, near year ends and near month ends. A further
20,000 fall on or next to a UTC offset transition in 8 zones, and 4,036 fall on the calendar days
that Pacific/Kiritimati (1994-12-31) and Pacific/Apia (2011-12-30) skipped. Each case picks a rule
kind, including out-of-range parameters, missing fills and short supplies. The outcome mix covers
every source and reason:

| Outcome | Cases |
|---|---|
| `fallback` | 30,606 |
| `rule:days_before_end` | 19,651 |
| `rule:fixed_interval` | 14,364 |
| `rule:fraction_elapsed` | 21,536 |
| `explicit` | 7,195 |
| `unknown: supply_not_longer_than_lead` | 9,978 |
| `unknown: invalid_rule` | 5,976 |
| `unknown: invalid_supply` | 5,955 |
| `unknown: no_fill` | 4,775 |

The SQL side is `adr42_estimate()`: `filled + integer` on `DATE` columns. The PHP side compares the
whole tuple using `DateTimeImmutable::createFromFormat('!Y-m-d', ..., new DateTimeZone('UTC'))`
plus `P{n}D`. Four PHP ways to add days then run against the SQL date in each zone. The last is a
negative control that shows the comparison can detect zone dependence.

| PHP method | UTC | New_York | Kiritimati | Pago_Pago | Apia | Lord_Howe |
|---|---|---|---|---|---|---|
| UTC-anchored `P{n}D` (the ADR's construction) | 0 | 0 | 0 | 0 | 0 | 0 |
| Same, anchored in the default zone | 0 | 0 | 357 | 0 | 380 | 0 |
| `date('Y-m-d', strtotime("$d +$n days"))` (the idiom of `StockService.php:1811`, applied to a stored date) | 0 | 0 | 17 | 0 | 21 | 0 |
| `date('Y-m-d', strtotime($d) + $n * 86400)` (control) | 0 | 12,648 | 1,643 | 0 | 5,001 | 22,432 |

Each column compares 86,157 computed dates (the remaining cases end in `unknown`). In all 357, 380, 17
and 21 default-zone differences, the span starts on, ends on or contains a calendar day that the zone
does not have (Kiritimati 1994-12-31, Apia 2011-12-30); the evidence field
`mismatches_not_touching_a_skipped_day` is 0. New York DST days, leap days and year ends gave no
difference. The seconds-based control fails on New York DST days, which shows the harness would
have caught a zone dependence.

Storing a fill date as a SQL `DATE` and adding days in SQL or with UTC-anchored PHP therefore does
not depend on PHP's zone. The existing `strtotime` idiom agrees for every zone that exists today.

### The `fraction_elapsed` representation

The ADR writes `floor(supplied_days * F)` and does not say what type `F` has. `0.58 * 100` in
binary floating point is 57.99999999999999, so floor gives 57 where the decimal answer is 58.

| Comparison | Mismatches |
|---|---|
| PHP `float` against SQL `numeric`, random set | 37 of 21,536 |
| SQL `float8` against SQL `numeric`, random set | 37 of 21,536 |
| PHP `float` against exact integer arithmetic, `F = k/100`, `supplied_days` 1 to 730 | 44 of 72,270 |
| The same for `F = k/1000` | 76 of 729,270 |
| SQL `numeric` against exact integer arithmetic, both grids | 0 |

Examples: `supplied_days = 100` with `F = 0.29`, `0.57`, `0.58`; `supplied_days = 90` with `F = 0.70`. If
`F` is stored as `numeric` and computed in SQL or with integer arithmetic, the result matches the
ADR's examples. If a client or service multiplies a `float`, the reorder date can be one day early.

## Probe 2: status boundaries

`status.php` evaluates `adr42_status()` and an independent PHP function for `T` in `R-L-1`, `R-L`,
`R-1`, `R`, `R+3` and `L` in 0, 1, 7, 60, for reorder dates 2026-03-18, 2028-03-01, 2026-01-05 and
2027-03-01 (leap-year, year-end and month-end spans). Expectations are the three rows of the section 4
table, written by hand. All 80 cells agree, including `days_overdue`, `warning_date = R - L`, and
`ordered` for every cell when an open order exists.

| L | `R-L-1` | `R-L` | `R-1` | `R` | `R+3` |
|---|---|---|---|---|---|
| 0 | ok | due, 0 | ok | due, 0 | due, 3 |
| 1 | ok | approaching | approaching | due, 0 | due, 3 |
| 7 | ok | approaching | approaching | due, 0 | due, 3 |
| 60 | ok | approaching | approaching | due, 0 | due, 3 |

The ADR's verification row (`ok`, `approaching`, `approaching`, `due`, `due` with 3 overdue) holds for
`L >= 1`. At `L = 0`, `R-L` is `R` itself, which is `due`, and `R-1` is `ok`. The row states the generic
case; the following sentence in the ADR ("`L = 0`: no `approaching` day") covers the other. The row
needs an `L >= 1` qualifier to be a testable case.

One instant gives different statuses by zone. At 2026-03-17 23:30 in New York with `R = 2026-03-18`
and `L = 7`: New York `as_of` 2026-03-17 is `approaching`; UTC and Pacific/Kiritimati `as_of`
2026-03-18 are `due`. The stored values are the same.

## Probe 3: "today"

### Where each side takes its zone

`PostgresDialect::OnConnected()` (`services/Database/PostgresDialect.php:131`) runs
`SET TIME ZONE` with `date_default_timezone_get()`. It is called from
`DatabaseService.php:238`, and `PostgresDialect.php:76` is the only place the PHP tree opens a
PostgreSQL connection. The label worker and renderer images were not inspected. PHP's zone comes from `date.timezone` (`Instant::ServerZone()` reads the
same value). The probe calls the real `OnConnected()` for the app-style connection.

| Fact (measured in `victual:dev`) | Value |
|---|---|
| `php.ini` `date.timezone` | `UTC` |
| `TZ=America/New_York php -r 'echo date_default_timezone_get();'` | `UTC`; PHP 8 ignores `TZ` |
| Server `TimeZone` on a connection that skips `OnConnected()` | `Etc/UTC` (both PostgreSQL 15.19 and 16.15 images) |
| Shipped Nix images | `date.timezone = UTC` (`nix/runtime/php-ini.nix:59`) |

A household in New York therefore sees "today" computed in UTC unless the operator edits `php.ini`.
The ADR's example response with `"zone": "America/New_York"` is what an operator gets only after that
change. On the shipped default, the date rolls over at 20:00 local in summer and 19:00 in winter, and
the ADR's `as_of` parameter is the only way to correct it.

### Agreement

Method: a one-minute grid of 84 hours around local midnight on selected dates, comparing PHP
`date('Y-m-d', $t)` with `to_timestamp($t)::date` in the session zone (the cast that `CURRENT_DATE`
applies to the transaction start time). Dates cover New York DST days (2026-03-08, 2026-11-01),
Kiritimati and Apia around their skipped days, Lord Howe's 30-minute DST, Pago_Pago and UTC.

| Connection | Minute points | Disagreements |
|---|---|---|
| App-style (`OnConnected()` applied, session zone equals the PHP zone) | 70,574 | 0 |
| Bare (server default `Etc/UTC`, PHP in the named zone) | 70,574 | 28,598 |

A second check covers every zone name PHP knows (419) at 30-minute steps in 2026 and hourly in 2027
(26,280 instants each, 11,011,320 in total, app-style): 0 differences, and PostgreSQL knows every
zone PHP does. PHP's tzdata is 2026.3; the PostgreSQL images use Debian's system tzdata, whose
version the probe does not report.

### Live crossing of local midnight

`run.sh clock` starts PostgreSQL under libfaketime, with the clock set 100 seconds before a local
midnight, then samples every 200 ms for about 112 seconds on two connections: app-style and bare.
PHP's date is computed for the instant PostgreSQL's `clock_timestamp()` reports, because PHP's own
clock is not faked. The first sample read the configured start time although the server had been
running for several seconds, which indicates that each backend process starts its own faked clock.
The comparison is therefore made within one connection.

| Zone and instant | Samples | App-style `CURRENT_DATE` against PHP | Bare `CURRENT_DATE` against PHP |
|---|---|---|---|
| America/New_York, 2026-10-09 04:00:00 UTC | 558 | 0 differ; both flip to 2026-10-09 at 04:00:00.096 | 494 differ (bare stays 2026-10-09 while PHP is 2026-10-08, from 03:58:20 to 04:00:00) |
| Pacific/Kiritimati, 2026-10-10 00:00 local | 558 | 0 differ; both flip to 2026-10-10 at 10:00:00.191 | 63 differ (bare stays 2026-10-09 after PHP flips) |

### When the two dates differ

| Condition | Window | Measured |
|---|---|---|
| Connection without `OnConnected()` (`psql`, pgTAP, a BI client, a column `DEFAULT CURRENT_DATE` evaluated by another client) and server zone `Etc/UTC` | For a zone at UTC+h: local 00:00 to h:00. At UTC-h: local (24-h):00 to 24:00 | Kiritimati 00:00 to 14:00 (840 min a day); Lord Howe 00:00 to 10:30 or 11:00 by DST; New York 20:00 to 24:00 EDT and 19:00 to 24:00 EST; Pago_Pago 13:00 to 24:00 |
| Transaction opened before midnight and read after it | From midnight until the transaction ends | `now()` and `CURRENT_DATE` stayed fixed over 1.5 s inside one transaction while `clock_timestamp()` advanced; `date()` reads the clock at each call |
| PHP host and PostgreSQL host clocks differ (external PostgreSQL) | The clock skew, around each local midnight | Not measurable on one host |
| PHP and PostgreSQL carry different tzdata for a zone | From the rule change on | 0 zones differ on these images; unmeasured for the production images and an external server |

In the PHP tree, every connection passes through `OnConnected()`, so the first row does not
occur there. The ADR has to name one source of "today". The repository already binds PHP-computed
dates as parameters for this reason (`StockService.php:1806-1811`). Using that pattern for refills
keeps the PHP request, the API response and the SQL in step: compute `as_of` once per request in PHP,
bind it, and add no `CURRENT_DATE`, `now()` or `DEFAULT CURRENT_DATE` to refill SQL or columns.

## Probe 4: constraints and concurrency

Scratch DDL in `model.sql` follows ADR sections 1 and 7. The 37 constraint cases cover:

- `supplied_days` of 0, 1, 730, 731, -1 and null, and null dates;
- the parameter ranges for `N`, `D` and `F`, and `lead_days` of 0, 60, 61 and -1;
- a received order with no fill, with another recipe's fill, and with its own fill;
- an explicit date with no fill and with another recipe's fill.

All 37 gave the expected SQLSTATE. A separate check deleted a recipe and confirmed that its
1 fill, 1 order and 1 settings row were removed.

Concurrency uses a held advisory lock so every child is connected and waiting before any starts; each
round confirmed that all N children were waiting at release.

| Test | Rounds | Result |
|---|---|---|
| 2 concurrent order inserts, one recipe | 40 | 40 rounds with exactly 1 inserted row and 1 `open` row; 40 children got `23505` |
| 8 concurrent order inserts | 20 | 20 rounds with exactly 1; 140 children got `23505` |
| 32 concurrent order inserts | 20 | 20 rounds with exactly 1; 620 children got `23505` |
| Receive (insert fill, close order in one transaction), normal | 1 | Order `received` with its fill, 1 fill |
| Receive, `SIGKILL` between the two writes | 1 | Order `open`, 0 fills (nothing half-applied) |
| Control: same two writes without a transaction, killed between | 1 | Order `open`, 1 fill (half-applied) |
| Second statement fails the `received_has_fill` check, then rollback | 1 | `23514`, order `open`, 0 fills |
| 8 concurrent receives of one order | 10 | 10 rounds with 1 `received`, 7 `lost_race_rolled_back`, 1 fill |
| 32 concurrent identical acknowledgements | 20 | 20 rounds with 1 row, 1 inserting call, 0 errors, one `acknowledged_at` read by all 32 |

The receive transaction needs the guard `UPDATE ... WHERE state = 'open'` and a row-count check, as in
`conc-child.php`; without it the losers of a concurrent receive would keep their fills. A rolled-back
insert still consumes a sequence value, so fill ids can have gaps.

### Notice keys

The key `<recipe_id>:<fill_id>:<kind>:<reorder_date>` behaves as the ADR says (`as_of` 2026-03-12 unless
noted, default lead 7, user 1 acknowledging):

| Step | Notices |
|---|---|
| Fill 1 (2026-01-01, 90 days) | `R:1:approaching:2026-03-18`, unacknowledged |
| User 1 acknowledges | The same key, acknowledged |
| User 2 (a sharer) | The same key, unacknowledged |
| Fill 1 voided, identical fill 2 recorded | `R:2:approaching:2026-03-18`, unacknowledged again |
| Fill 2 voided, fill 3 on 2026-01-05, `as_of` 2026-03-16 | `R:3:approaching:2026-03-22`, unacknowledged |
| `as_of` 2026-03-22, nothing acknowledged for fill 3 | Two notices: `approaching` and `due` |
| Explicit date 2026-03-30 on fill 3, `as_of` 2026-03-25 | `R:3:approaching:2026-03-30` |
| Open order | None |

Two consequences follow from the text. A correction that leaves date and supply unchanged still
re-notifies every user. On the due date, a user who has acknowledged nothing gets both an
`approaching` and a `due` notice, because `approaching` is raised for "approaching or later". An
acknowledgement of a string no notice produced (`not-a-real-key`) is accepted, so the table can grow
without bound unless the endpoint validates keys against current notices.

## Probe 5: fill history

`history.php` runs 13 steps over six recipes with the view `refill_current_fill`
(`DISTINCT ON (recipe_id) ... WHERE voided_at IS NULL ORDER BY recipe_id, filled_on DESC, id DESC`).

| Step | Result |
|---|---|
| Two fills on the same `filled_on` | Greater id is current |
| Fill backdated before the current one | In history, not current; an explicit date on the current fill keeps applying |
| Void the current fill | Previous unvoided fill becomes current; estimate recomputed; both rows kept |
| Explicit date bound to B, then B voided | A is current; the explicit date no longer applies; fallback on A |
| Void the last unvoided fill | `unknown`, `no_fill` |
| Void and re-record an identical fill | The explicit date does not carry over |
| Explicit date on A, newer fill B recorded, then B voided | The explicit date on A applies again (step 1.3) |

Step 1.3 differs from the hand-written expectation. Section 7 says the explicit date is "ignored once the
fill is not current", which a join on the current fill implements; voiding B makes A current, so the
date returns. Sections 2 and 5 say recording a newer fill "ends it" and a superseded fill's date "stops
applying", which reads as a permanent end. The two readings give 2026-03-01 and 2026-03-18 for the
same history. The ADR has to choose one, and the second needs the explicit date cleared (or marked
ended) when a newer fill is recorded.

## Statements in the ADR Context

| ADR text | Finding |
|---|---|
| `Instant::ServerZone()` returns the PHP default zone (`services/Time/Instant.php:92`) | True. |
| Stock due dates compare `best_before_date`, a `DATE`, with that string (`StockService.php:1811-1832`) | True. Lines 1811 and 1832 are the `date('Y-m-d')` calls; the column is `DATE` (`db/pgsql/baseline/01_tables.sql:53`). |
| "There is no per-user or per-installation time zone setting" | Imprecise. There is no Victual setting, but the installation has one zone: PHP's `date.timezone`, set to `UTC` in the shipped images and documented for operators (`docs/manual/operator/updating-migrations.md:133`). |
| "Every 'today' is `date('Y-m-d')` in the PHP default zone" | False as written. `chores_current` computes today in SQL from the transaction start time: with `LOCALTIMESTAMP` in migration 0289 (`migrations/0289.pgsql.sql:167-171`), and with `to_char(CURRENT_TIMESTAMP, ...)` in the definition that migration 0301 applies (`services/Database/TimestampMigration.php:140-145`). Browser code computes dates with `moment()` in the device zone (32 uses in `public/viewjs`, for example `purchase.js:476`). The SQL value agrees with PHP only because `OnConnected()` aligns the session zone; the browser value follows the device. |
| Per-user preferences are `DefaultUserSetting(...)` entries in `config-dist.php` | True (`config-dist.php:360` onward). |
| The workload standard makes workloads stateless and declared (ADR-0010) | True; ADR-0010 is Accepted 2026-09-07. |
| "MQTT and webhook publishers carry stock events only" | False. `MqttStatePublicationService` publishes state snapshots that include `next_chore`, `next_battery` and `next_task` (`services/Mqtt/StateSnapshotAssembler.php:46-48`). No webhook publisher remains: the label webhook was removed (`AGENTS.md`, `StockService.php:448`). The InfluxDB writer carries booking events. |
| Victual "has no scheduler that pushes to a person" | Not contradicted: no cron or scheduler code outside the label worker and outbox drainer, which print and publish. |
| Tables since migration 0266 enforce invariants with constraints | True for 0270 and 0272 (19 and 20 `CHECK` occurrences); not checked for every later migration. |
| Date table: 2028-01-15 plus 16 days labelled "leap year" | The date is right (2028-01-31), but the span never reaches February, so the row does not test a leap day. A leap-day case would be 2028-02-20 plus 16 days = 2028-03-07 (2028-03-06 in a non-leap year). The random set covers 12,000 cases around February 27 to March 1 with 0 differences. |
| Table dates checked "on 2026-10-09" | Reproduced: all four dates and `floor(90 * 0.75) = 67` agree in PHP and SQL. |
| Maintainer decision on 2026-10-09; seven days is a recommendation | Cannot be verified from the tree. Plan 22 Q14 and Q16 exist (`docs/plans/22-medication-tracking.md:325,337`). Issues 697, 701 and 702 exist and are open. |

## Gaps in the ADR text the probes made concrete

Each item changes an observable result. Choices the probe made to proceed are in `estimate.sql`.

1. Representation of `F` in `fraction_elapsed`: `numeric`/integer arithmetic matches the examples; `float` is wrong for 44 of 72,270 hundredths grid points.
2. Explicit-date lifetime: ended by an event (sections 2 and 5) or derived from "is the fill current" (section 7). They differ after voiding a newer fill.
3. `fraction_elapsed` can return 0 days (supply 1, `F = 0.5`), so `reorder_date = filled_on` and the status is `due` on the fill date. No unknown reason covers it.
4. The reason when `supplied_days` and the rule are both invalid (the probe returns `invalid_supply`), and whether `invalid_supply` applies to `fixed_interval`, which does not use the supply.
5. `no_fill` says "and no explicit date applies", but an explicit date references a fill, so the combination cannot exist.
6. An explicit date earlier than the fill date is accepted by every constraint in section 7.
7. `days_overdue` for `ordered` is unspecified; the model returns null.
8. `lead_days` outside 0 to 60 is a database check in section 7, but section 4 does not say what the API does on input outside it.
9. On the due date both `approaching` and `due` notices are raised.
10. An acknowledgement is accepted for any string.
11. The default zone on shipped images is UTC, so the `as_of` default for most households is the UTC date (see Probe 3).
12. MQTT publishes facts and no derived states by design (`StateSnapshotAssembler.php:18-22`). Publishing `approaching` or `due` would break that rule, and refill data on a broker is readable by anything holding broker credentials. The ADR does not forbid this; ADR-0015's privacy wording is the likely place.

## Limitations

- No clock can be injected into PHP. The live midnight test fakes only PostgreSQL's clock and derives PHP's date from that instant, and each backend starts its own faked clock.
- The all-zone comparison covers 2026 and 2027; historic rules were compared only in the minute scans around the Kiritimati and Apia skipped days.
- Clock skew between a PHP host and an external PostgreSQL host, and tzdata skew between production images, were not measured.
- The scratch SQL and the PHP model are readings of the ADR text. Where the ADR is silent the choice is listed above, so agreement of the two sides shows that the arithmetic is consistent, not that the ADR's intent is captured.
- Test suites of the application were not run, and no application code was changed.
