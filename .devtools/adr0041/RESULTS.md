# ADR-0041 prerequisite evidence

Evidence for [ADR-0041](../../docs/adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md)
(Proposed), collected for [issue 696](https://github.com/datagen24/victual/issues/696). The probes
run the real `StockService`, `DatabaseService` and `RecipesService` code in disposable containers.
Nothing under `services/`, `controllers/`, `migrations/`, `tests/` or `victual.openapi.json` is
changed, and the existing design fragment `consumption-events.openapi.json` is untouched. The
event, event-line and recipe tables the probes use are scratch tables created inside a throwaway
schema; they are not candidate designs.

**None of this exercises HealthKit, `victual-kit`, an iOS device or a real device payload.** Every
request is a JSON-shaped call made by a PHP process. [Issue 702](https://github.com/datagen24/victual/issues/702)
still owns device evidence.

- **Date:** 2026-10-09 (local time).
- **Commit:** `14b2df2e1c3d4f9ff70d09a0d3befef377a6b5ca`. `run.sh` extracts it with `git archive HEAD`
  and overlays this directory. `services/StockService.php`, `services/DatabaseService.php` and
  `services/RecipesService.php` have no commits between the ADR's recorded base `216af2b2` and this
  commit (`git log 216af2b2..HEAD -- <those files>` is empty).
- **Host:** macOS (Darwin 27.0.0, arm64), podman 6.0.2, libkrun VM with 4 CPUs and 8 GiB, shared
  with other containers while the probes ran.
- **Images:** `localhost/victual:dev` (id `05591fb29900`, PHP 8.5.10), `localhost/victual-pg:pgtap`
  (id `b74ab6fca41d`, PostgreSQL 16.15) and `docker.io/library/postgres:15` (id `f6a528436858`,
  PostgreSQL 15.19). The images were not rebuilt.
- **Server settings:** `max_connections=250` and `deadlock_timeout=100ms` (the default is 1 s; the lower
  value makes each detected deadlock cost 0.1 s). Containers ran with `--memory` limits: PostgreSQL
  1 GiB, PHP 2500 MiB.
- **Schema:** each run migrates a throwaway schema to HEAD through
  `DatabaseMigrationService::MigrateDatabase()` (the `PgsqlSchemaTestCase` path) and drops it.

## Commands

```sh
.devtools/adr0041/run-all.sh          # B41_ROUNDS=200 B41_TRIALS=300; every probe below, sequentially
.devtools/adr0041/run.sh probe probe1-idempotent      # one probe, JSON on stdout
.devtools/adr0041/run.sh suite                        # probe 4: four test phases before and after the patch
PG_IMAGE=docker.io/library/postgres:15 .devtools/adr0041/run.sh probe probe3-lockorder
```

`run-all.sh` ran with `B41_ROUNDS=200 B41_TRIALS=300`. The PostgreSQL 15 run of probe 1 was repeated
with `PG_MEM=2g` after its first attempt ended when the 1 GiB container dropped a connection at
round 129 of the 64-child scenario (cause not investigated; the PostgreSQL 16 run at 1 GiB completed).

| File | Role |
|---|---|
| `run.sh`, `run-all.sh` | Container lifecycle. One PostgreSQL and one PHP container at a time, removed afterwards. |
| `probes/lib.php`, `probes/worker.php` | Schema setup, the rule 5 protocol, and the long-lived child process (own connection, own `VICTUAL_USER_ID`). |
| `probes/probe1-idempotent.php` | Probe 1. |
| `probes/probe2-failure.php` | Probe 2. |
| `probes/probe3-lockorder.php` | Probe 3. |
| `probes/probe4-useddate.php`, `probes/callers-audit.php`, `evidence/usedDate.patch` | Probe 4. |
| `probes/probe5-partial-undo.php` | Probe 5. |
| `probes/probe6-insufficient.php` | Probe 6. |
| `evidence/*.json`, `evidence/suite-*.log` | Output of the runs. Seeds are in the JSON. |

## Summary

| # | Question | Result |
|---|---|---|
| 1 | Does rule 5 book exactly once under a race? | **Pass.** 200/200 rounds at 16 and at 64 children on PostgreSQL 16.15 and on 15.19 (16,000 requests per engine in scenario A), no error of any SQLSTATE. Two users: 60/60. Crash cases: 90/90 on each engine. |
| 2 | Is a three-transaction layout needed? | **Pass, with a different reason than the task text.** Insufficient stock does not abort the PostgreSQL transaction; it leaves earlier lines pending, and they commit. |
| 3 | Is "event, recipe, ascending products" a safe lock order? | **Partly.** 0 deadlocks in 900 event, direct-consume and undo trials (and in 600 prelocked correction trials). Corrections need the union of old and new products locked first: without it 124 to 162 of 300 trials deadlocked per variant. |
| 4 | Is `$usedDate` backward compatible? | **Pass.** 4 phases, 441 tests, identical before and after. No caller passes more than 10 arguments. Dated booking shown. |
| 5 | Is partial undo reachable, and does the derived state hold? | **Pass.** Reachable by two routes; derived states match rule 8; lineage violations 0 at every step. |
| 6 | Can insufficient stock be classified without the message? | **No today.** Nine causes share `\Exception` code 0. The pre-check agreed with `ConsumeProduct()` in 300/300 randomized cases but not for a measured container. |

## Probe 1: idempotent submission race

Protocol in `lib.php::b41_submit()`: transaction 1 runs `INSERT ... ON CONFLICT DO NOTHING` and
commits; transaction 2 is one `DatabaseService::InTransaction()` that runs `SELECT ... FOR UPDATE`
on the event row, `FOR SHARE` on a scratch recipe row, `LockProductsStock([p1, p2])`, two
`ConsumeProduct()` calls sharing one transaction id passed by reference, and the state update;
transaction 3 records a failure. Children are persistent processes released together by a
PostgreSQL advisory-lock barrier, with a random start delay of 0 to 3 ms, up to 1 ms after
transaction 1 and up to 0.5 ms after the row lock, all derived from the round's seed.

| Scenario | Rounds | PostgreSQL 16.15 | PostgreSQL 15.19 |
|---|---|---|---|
| A: identical request from 16 children | 200 | 200 exactly once | 200 exactly once |
| A: identical request from 64 children | 200 | 200 exactly once | 200 exactly once |
| B: same key, 16 children split over two users | 60 | 60 (two events, each booked once) | 60 |
| C1: child killed after transaction 1, then one retry | 30 | 30 (state `received`, retry books once, second retry does not rebook) | 30 |
| C2: child killed after transaction 1, raced by 8 children | 30 | 30 | 30 |
| C3: child killed after booking, before commit; then a retry | 30 | 30 (ledger hash unchanged by the killed transaction, retry books once) | 30 |
| D: replay with a newer, older, equal and omitted `source_updated_at` | 1 | 4 replays, 0 new bookings | same |

"Exactly once" per round means: one `booked_now`, N-1 `replayed`, one inserted row, one distinct
transaction id, two `stock_log` rows, event state `booked`, both stock amounts reduced by the
requested quantity, and no error.

Error counts by SQLSTATE were empty in every scenario. Seeds are
`run_seed + N*100000 + round` (run seed 410001; first seed 810002 at N=16). Request latency at 64
children: p50 129 ms, p95 195 ms, max 398 ms on 16.15; p50 113 ms, p95 147 ms, max 243 ms on 15.19.
Summed child RSS at start was 2,125 MB for 64 children (shared pages are counted once per
process, so this overstates the container's use). `stock_lineage_violations()` returned 0 rows
after each run.

The payload hash excludes `source_updated_at`: `b41_ordering()` returned `replay` for the same hash
with any value, `stale` for a different hash with an older value, `correction` for a newer value or an
absent one, and `conflict_409` for equal values.

A transaction opened with `$pdo->beginTransaction()` instead of `InTransaction()` would never run
`RunBeforeOutermostCommit()`, so the outbox listener `BookingEventPublisher::RecordTransaction()`
registers would not fire. The probes use `InTransaction()` throughout; InfluxDB was off, so the
outbox write itself was not exercised.

## Probe 2: failure persistence

`evidence/probe2-failure-pg16.json`. Two-line consume, 4 of 10 and 5 of 3.

| Case | Observed |
|---|---|
| A: ADR layout | Transaction 2 rolls back; `stock`, `stock_log` and the ledger hash are identical before and after; transaction 3 commits `needs_review` / `insufficient_stock`. |
| B: catch the application exception inside the one transaction and write `needs_review` | No PostgreSQL error is raised. Line 1 stays pending and commits: stock 10 becomes 6, one `consume` row exists, and the event says `needs_review`. A partial deduction. |
| C: catch a SQL error (foreign key, SQLSTATE 23503) in a nested `InTransaction()` and issue another statement | The next statement fails with SQLSTATE 25P02 (`current transaction is aborted`); the outer call rolls back everything. |
| D: catch a SQL error in a nested call and let the outer call return | The outer call returns a transaction id without error, `commit()` raises nothing, and the ledger is unchanged. A silent rollback. |

`ConsumeProduct()` signals insufficient stock with `throw new \Exception`, before any SQL fails, so
case B is the one that applies to it. The three-transaction layout is required because an
application exception cannot be caught and recorded in the same transaction without committing
the earlier lines. The ADR text does not say a caught exception aborts the transaction, and the
wording "leaves the PostgreSQL transaction aborted" holds only for SQL-originated errors (cases C
and D).

## Probe 3: lock order

Four persistent workers, 300 trials per scenario, fresh products and purchase lots per trial,
random start delays and pauses between steps (up to 4 ms). Each trial starts the actors together
and checks, for the three products, that `stock` equals the sum of non-undone `purchase` and
`consume` rows and that `stock_lineage_violations(product)` is empty. Seeds are
`run_seed (430001) + scenario number * 100000 + trial`. Deadlocks are counted by
`errorInfo[0] = '40P01'` (40001 never occurred).

| Scenario | Actors | 40P01 (16.15) | 40P01 (15.19) | Invariant violations |
|---|---|---|---|---|
| A | event booking vs 1 to 2 direct consumes (single product, or several products locked ascending) | 0 | 0 | 0 / 0 |
| B | event booking vs 1 to 3 of: `UndoTransaction`, `UndoBooking`, direct consume | 0 | 0 | 0 / 0 |
| B2 | `UndoTransaction` vs `UndoBooking` of one of its bookings | 0 | 0 | 0 / 0 |
| C1 | two corrections (undo old, book new), different recipes, union of old and new products locked ascending first | 0 | 0 | 0 / 0 |
| C2 | same, no union lock | 160 | 162 | 0 / 0 |
| C3 | same recipe (`FOR SHARE`), union locked | 0 | 0 | 0 / 0 |
| C4 | same recipe (`FOR SHARE`), no union lock | 124 | 126 | 0 / 0 |
| C5 | same recipe (`FOR UPDATE`), no union lock | 13 | 13 | 0 / 0 |
| D | control: two multi-product consumes in opposite order | 300 | 300 | 0 / 0 |

Every scenario's deadlock count is per request, so C2 on 16.15 is 160 deadlocked requests in 300
trials. Scenario D confirms the harness detects a deadlock when one is possible.

Other outcomes, per request, which are refusals rather than failures:

- `Booking has subsequent dependent bookings, undo not possible`: 70 in B and 237 in C1 (16.15). A
  consume can be undone only while no later booking depends on the same lot, so a concurrent
  consume of the same product makes the undo, and any correction built on it, fail. In C1, 212 of
  600 corrections were refused for this reason.
- `Booking does not exist or was already undone` (or `This transaction was not found or already
  undone`): 446 in B2. `UndoTransactionInTransaction()` reads the transaction's bookings before it
  opens its own transaction (`StockService.php:4724`, before the `InTransaction()` call that follows), so a concurrent `UndoBooking` of one of them
  makes the whole `UndoTransaction` roll back with this message. No ledger invariant broke.

The recipe row in the probes is a scratch row. `FOR SHARE` follows [ADR-0040](../../docs/adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md)
rule 8 (a consumption takes `FOR SHARE`); ADR-0041 rule 5 says only "locks the recipe row".
Comparing C4 with C5, `FOR UPDATE` on a shared recipe row serialized most of the corrections and
reduced, but did not remove, the deadlocks (13 against 124).

## Probe 4: `$usedDate`

`evidence/usedDate.patch` (applies to HEAD with `git apply --check`; it adds an optional trailing
`$usedDate = null`, validates `Y-m-d`, and uses it for both `used_date` writes at `StockService.php:908`
and `:969`). `run.sh suite` ran the phases one at a time, unpatched, applied the patch inside the
container, and ran them again:

| Phase | Before | After |
|---|---|---|
| `stockcoverage` | OK, 309 tests, 3,996 assertions | OK, 309 tests, 3,996 assertions |
| `stockconcurrency` | OK, 23 tests, 126 assertions | OK, 23 tests, 126 assertions |
| `recipeoperations` | OK, 35 tests, 100 assertions | OK, 35 tests, 100 assertions |
| `stockmaintenance` | OK, 74 tests, 630 assertions | OK, 74 tests, 630 assertions |

These phases do not assert the default `used_date`; the default is shown by the probe instead.
After the patch (`evidence/probe4-useddate-patched.json`):

- A 3.5 unit consume spanning two lots (one whole-row take, one split) with `$usedDate = '2026-10-07'` on 2026-10-09 wrote both bookings dated 2026-10-07.
- A call without the argument wrote 2026-10-09.
- `2026-02-30`, `09/10/2026`, `2026-10-9` and `''` raised `InvalidArgumentException`.
- `UndoTransaction` of the dated bookings restored stock to 7 with 0 lineage violations.
- Two products consumed in one transaction with one date both carried it.

Caller audit (`evidence/callers-audit.json`, tokenizer over 509 PHP files, excluding `packages/`):
135 calls to a method named `ConsumeProduct`, with 3 arguments (46, the controller's own
`ConsumeProduct($request, $response, $args)`), 4 (58), 5 (1), 7 (4), 8 (14), 9 (10) and 10 (2). The
maximum is 10, in `controllers/Api/StockApiController.php:578` and `services/RecipesService.php:253`; the
others in production code are `ChoresService.php:374` (9), `DemoDataGeneratorService.php:411` (4) and
`StockService.php:2298` (4) and `:3341` (7). No call uses a spread or more than 10 arguments, and a grep for
`call_user_func`, string method names and `ReflectionMethod` near `ConsumeProduct` found none.

## Probe 5: partial undo and derived state

`evidence/probe5-partial-undo-pg16.json`. Effective state is computed as rule 8 states.

| Step | Effective state | Stock | Lineage violations |
|---|---|---|---|
| Two-line consume (2 of A, 3 of B), `correlation_id` null on both rows | `booked` | 8, 7 | 0 |
| `UndoBooking(line 1)` accepted | `needs_review/partially_undone` | 10, 7 | 0 |
| `UndoTransaction` on the same transaction accepted (undoes the remainder) | `undone` | 10, 10 | 0 |
| `UndoTransaction` again | refused, `This transaction was not found or already undone` | | |
| One line served by two lots: undo the older booking, then the newer | `partially_undone` after each (the other product's line is still booked) | | 0 |
| `UndoTransaction` while line 1 has a later dependent booking | refused; ledger hash unchanged (all or none); state `booked` | | |
| `UndoBooking(line 2)` alone | accepted; `partially_undone` | | 0 |
| `UndoTransaction` again | refused; `partially_undone` persists | | |

A partially undone transaction is reachable through `UndoBooking` and also through a refused
`UndoTransaction` followed by an undo of the unblocked line, which leaves the blocked line booked
indefinitely. The code comment at `StockService.php:4731` calls such a transaction "a state the
ledger cannot represent"; the ledger does represent it.

## Probe 6: typed insufficient-stock detection

`evidence/probe6-insufficient-pg16.json`. `ConsumeProduct()` throws `InvalidArgumentException` for a
zero, negative or non-finite amount, and `\Exception` with code 0 for everything else, including:
product missing or inactive (same message), location missing, insufficient stock (product-wide,
location-scoped, empty location, named stock entry too small), invalid transaction type, and a
fractional consume of a measured container. Nine causes share class `Exception` and code 0, so
the insufficient-stock case cannot be told apart without comparing the message. A negative stock
row cannot be constructed: `stock_amount_non_negative_check` (SQLSTATE 23514) rejects it.

Prototype: under `LockProductStock()`, sum `GetProductStockEntries()` (or `...ForLocation()`)
amounts and compare with `StockService::CompareAmounts()`, throwing a typed exception.
Randomized comparison with `ConsumeProduct()` (300 cases, seed 460001 + case number; stock in up
to four locations including a child of the first; scopes: none, each location, an empty one, the
child; amounts exactly equal to the scope's stock, above it by 1e-7, 5e-10 and 1e-13, below it,
and above it by up to 5 units):

| Pre-check verdict | `ConsumeProduct()` | Cases |
|---|---|---|
| sufficient | accepted | 123 |
| insufficient | refused as insufficient | 177 |
| any other pair | | 0 |

The same comparison without the location scope (product-wide sum) disagreed in 154 of 300 cases.
Named cases: need 7 at a location holding 3 while another holds 20, scope-aware `insufficient`
and product-wide `sufficient`, `ConsumeProduct()` refuses; stock held only in a child of the
requested location is not counted by `ConsumeProduct()` (exact `location_id` match) or by the
pre-check.

Where the pre-check and `ConsumeProduct()` differ: a product whose only stock is a measured open
container, with a fractional amount. The pre-check says sufficient; `ConsumeProduct()` refuses
with `Cannot consume a fraction of a measured container...` (needing 1, the whole container, is
accepted). A caller that treats every `\Exception` after a passing pre-check as unexpected still
cannot classify that refusal without a typed exception of its own.

## ADR-0041 Context: false, imprecise and unverifiable claims

| ADR text | Finding |
|---|---|
| "Locks are taken per product, in ascending id order (`LockProductsStock()`)" (Context, line 61) | `LockProductsStock()` sorts, but `ConsumeProduct()` locks only its own product (or its substitution set) when called, and `UndoTransaction()` locks only its transaction's products. Ascending order holds only for callers that pre-lock, as `RecipesService.php:193` does. Imprecise for the booking and correction paths. |
| "`POST /stock/bookings/{id}/undo`" (line 58) | The route is `POST /api/stock/bookings/{bookingId}/undo` (`routes.php:309`, inside the `/api` group). Abbreviated, not false. |
| "an exception rolls back everything at the outermost level" (line 52) | Holds for an exception that propagates. A caught exception does not roll back (probe 2 B), and a caught SQL error leaves an aborted transaction whose `commit()` rolls back silently (probe 2 C, D). |
| "`UndoTransaction()` undoes every booking of a transaction or none" (line 57) | Verified (probe 5). It refuses with no ledger change when any booking is blocked. |
| "The lines ... share a `transaction_id` and no `correlation_id`, so one line can be undone alone" (line 59) | Verified, with one condition: the line must not have a later dependent booking on its lot. |
| "`ConsumeRecipe()` caps each line at available stock" (line 53) | Verified at `RecipesService.php:247-250`. It also skips lines with no stock and books with substitution on. |
| "`used_date` is written as `date('Y-m-d')` at booking (`StockService.php:908`, `:969`)" (line 55) | Verified, line numbers exact. |
| "No existing table records which client or source a booking came from" (line 62) | Verified for `stock_log` (`user_id` only; `db/pgsql/baseline/01_tables.sql:335`); `api_keys` is not linked to bookings. |
| ADR-0040 rule 8 as the lock-order authority (rule 5, step 2) | ADR-0040 rule 8 takes the recipe row `FOR SHARE` for a consumption; ADR-0041 does not say which mode. The choice changes the deadlock rate of corrections (probe 3, C4 against C5). |
| Rule 6 and rule 7: "Undo the old transaction and book the new one" | The undo is refused whenever a later booking depends on the same lot, which 212 of 600 corrections hit in a mixed workload (probe 3, C1). Rule 6 names no outcome for a refused undo; rule 7 names `undo_refused` for a void only. |
| Apple HealthKit claims (Context, lines 33-38) | Partly verified by reading the WWDC25 session 321 page on 2026-10-09: dose events can be logged for past days, can be deleted and re-persisted on edit, and anchored queries must handle deletions and late data. The page does not state that the identifier changes on edit, and the ADR itself says nothing is verified against a device. Unverifiable here. |
| "Recorded ... against `master` at `216af2b2`" | The three service files have no later commits. |

## Limitations

- Randomization is jitter on start times and pauses between steps, not a controlled schedule; the
  counts depend on the host's timing. Probe 3 trials share one host with other containers.
- `deadlock_timeout` was 100 ms; at the default 1 s the same deadlocks would last ten times longer.
- The event, line and recipe tables are scratch tables. [ADR-0040](../../docs/adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md)
  tables, permissions, mappings, the HTTP layer and the review inbox were not built or exercised.
- A booking runs as the child's `VICTUAL_USER_ID`; permission checks inside the controllers were not
  exercised.
- InfluxDB was off, so the outbox write inside `RecordTransaction()` was not run.
- Probe 6's pre-check does not cover sub-product substitution, which `ConsumeProduct()` supports.
- The existing suite phases do not assert the default `used_date` and have no caller of the new
  parameter; the regression evidence is "no existing test changed" plus probe 4's direct checks.
- Timing and counts come from one laptop VM and one run each (PostgreSQL 16.15 and 15.19); no
  repetition across runs was made. The first PostgreSQL 15 run of probe 1 lost its server
  connection at a 1 GiB memory limit and was rerun at 2 GiB.
- The dev image runs PHP 8.5.10; CI runs PHP 8.4.
