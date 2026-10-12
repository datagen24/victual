# Plan 22 remaining work: coordination ledger

Working record for the remaining work of [plan 22](../../docs/plans/22-medication-tracking.md)
and the defects victual-kit testing found. The plan's Executed section and the plans index stay
the authority on what shipped; this file tracks what is open, what each item waits on, and what
evidence exists. Read against `master` at `d4960138` (merge of #707) on 2026-10-11. Research was
read-only and static unless a row names a command that ran.

## Ledger

| Issue | Source | Current evidence | Remaining criteria | Depends on | Decision gate | Specification | Worker | Verification |
|---|---|---|---|---|---|---|---|---|
| [701](https://github.com/datagen24/victual/issues/701) refill | plan 22 | Merged in PRs 748, 749, 751, 755; criteria table and CI evidence in plan 22 Executed; maintainer approvals 2026-10-10 | None server-side. Native refill presentation is #702 | — | Maintainer closes the issue | Done (ADR-0042 plus approved deviations) | — | CI on PR heads recorded in plan 22; integrated run belongs to #703 |
| [702](https://github.com/datagen24/victual/issues/702) native acceptance | plan 22 | Maintainer decision 2026-10-11: closed on the current simulator evidence; no physical phone. Simulator UI round 1 on iOS 27.0 with administrator, Adult and Child keys against a Talos-hosted Victual stack with a disposable on-cluster database; unit tests; v0.4.0 live fixture replay (28 of 28; fixtures 16 and 17 with two administrators) | Change the gate wording in plan 22 (this branch) and issue 702 (maintainer); victual-kit matrix re-pin (client housekeeping) | — | None remaining | [NATIVE-ACCEPTANCE.md](NATIVE-ACCEPTANCE.md) | — | Accepted by the maintainer; limitation for the release record: real Health store behaviour (identifier changes on edit and delete, late and background delivery) is not observed |
| [703](https://github.com/datagen24/victual/issues/703) release | plan 22 | Baseline suite at `d4960138` (see [baseline](#baseline)) | Every criterion; upgrade rehearsal from 0.4.0; rest of 0.5.0 milestone | 702, 742, 772, 774, 775 dispositions | Maintainer: 0.5.0 scope | Not started; evidence collection only | coordinator | Baseline only |
| [742](https://github.com/datagen24/victual/issues/742) master-data rights | victual-kit wizard | `.devtools/issue742/RESULTS.md` (93 probes, 2026-10-10; static completion merged in PR [780](https://github.com/datagen24/victual/pull/780)); Proposed ADR-0043, revised for evidence in PR 780 | Choose option A, B or C (see [742](#742-master-data-rights)); implement if A or B | ADR-0043 decision | D3 | Options below | — | Not run |
| [772](https://github.com/datagen24/victual/issues/772) OpenAPI drift | victual-kit #24 | Closed by PR [791](https://github.com/datagen24/victual/pull/791), merged 2026-10-12: stock-route document and two `ContractTest` legs; `contract` and `wirecontract` passed on PostgreSQL 16 and 15.19 at `57f687e2` | Follow-up gaps in #787 | — | None | Done | implementation worker | Verified |
| [774](https://github.com/datagen24/victual/issues/774) unknown price as 0 | victual-kit #29, #33 | Proposed ADR-0045, merged in PR [778](https://github.com/datagen24/victual/pull/778); P2 price semantics confirmed 2026-10-11 | Null versus zero per field; implementation | ADR-0045 decision; victual-kit coordination (ADR-0024) | D1a to D1e | ADR-0045 | — | Not run |
| [775](https://github.com/datagen24/victual/issues/775) booking idempotency | victual-kit #35 | Proposed ADR-0046 merged in PR [779](https://github.com/datagen24/victual/pull/779) (reviewed; five should-fix items applied) | Implementation after acceptance; migration slot claimed then | ADR-0046 acceptance | D2a to D2c | ADR-0046 | — | Not run |
| [737](https://github.com/datagen24/victual/issues/737) share enumeration | #698 review | Maintainer decision 2026-10-09: accepted for v0.5.0, fix wanted afterwards | All (post-v0.5.0) | — | None; disposition stands | Chosen fix in the issue | — | — |

## Findings and filed issues

Numbering follows `.devtools/issue742/RESULTS.md` for F1 to F4. Filed 2026-10-11 without a milestone;
release scope is decision D5.

| Finding | Issue | Kind | Evidence | State |
|---|---|---|---|---|
| F1. Product merge deletes a product under `STOCK_EDIT`, which the Adult role holds | [782](https://github.com/datagen24/victual/issues/782) | authorization (ADR-0006) | Read from source: `StockApiController.php:1538`, `StockService::MergeProducts`; upstream `c9b5e144` | Needs an authorization decision (D6) |
| F2. `userpictures` writes are not scoped to the target user | sweep S6 | authorization | `FilesApiController.php:50` | Known; no new issue |
| F3. Shopping-list containers use item permissions | [783](https://github.com/datagen24/victual/issues/783) | design question | `GenericEntityApiController` create, edit and delete gates | Needs a decision (D6) |
| F4. Legacy import drops the three view leaves of migration 0299 | [784](https://github.com/datagen24/victual/issues/784) | defect | Reproduced: 12 failures in the `import` phase without the fix | Fixed by PR [789](https://github.com/datagen24/victual/pull/789), merged; issue closed |
| F5. A booking commits, then answers 403 without `STOCK_VIEW` | [785](https://github.com/datagen24/victual/issues/785) | authorization | `StockApiController.php:1301` after the service commits | Needs an authorization decision (D6) |
| F6. Stock add accepts JSON `true` as `transaction_type` | [786](https://github.com/datagen24/victual/issues/786) | defect | Reproduced: JSON `true` answered 200 and wrote `"1"` | Fixed by PR [792](https://github.com/datagen24/victual/pull/792), merged; issue closed |
| #772 follow-up: inherited schema gaps and a key-coverage check | [787](https://github.com/datagen24/victual/issues/787) | documentation | Recorded keys in `contract-admin.json` | Ready to assign after #772 merges |
| `quantity_factor` next to `qu_id` on a mapping is undefined | [788](https://github.com/datagen24/victual/issues/788) | documentation | victual-kit matrix difference 7 | Ready to assign |
| Possible stale average-price cache after an insert | not filed | unverified | R2 research: `trg_stock_log_INS` only upserts | Needs a reproduction before filing |

## Merged pull requests

PRs 777 to 780, 789 to 792 and 794 merged by 2026-10-12. Code changes: 789 (issue 784, import
restores the 0299 view leaves), 791 (issue 772, stock-route OpenAPI document and contract legs),
792 (issue 786, strict `transaction_type`). Maintainer responses to ADRs 0043 to 0046 merged in
PRs 793 and 795.

## Decisions

Recorded in the ADRs by the maintainer on 2026-10-11 (PRs 793 and 795). The three records stay
Proposed until a separate acceptance pull request.

| ID | Decision | Recorded answer |
|---|---|---|
| D1a | ADR-0045: legacy and existing zeros | Imported legacy zeros are unknown by default; the import discloses this and keeps original booking values. The live database holds no production data |
| D1b | ADR-0045: aggregates | Option B: the known subtotal plus an `*_incomplete` flag |
| D1c | ADR-0045: parent products | No roll-up; a parent reports its own stock only |
| D1d | ADR-0045: `current_price` with an unpriced next entry | Keep the last-known-price fallback |
| D1e | ADR-0045: ADR-0024 and meaning changes | A change of meaning counts for client coordination |
| D2a | ADR-0046: discovery | `GET /api/system/info` |
| D2b | ADR-0046: `transaction_id` collisions | Both: filter replays by the acting user, and generate ids with cryptographically secure randomness |
| D2c | ADR-0046: client evidence | The contract is documented and tested for any client; victual-kit evidence is not required |
| D3 | ADR-0043 | One `MASTER_DATA_ADD` permission; explicit grants; external lookup attaches a fetched picture to the new product only, never overwriting an existing one |

## Still open

On 2026-10-12 the maintainer started a cleanup of `victual.openapi.json`, tracked by
[issue 796](https://github.com/datagen24/victual/issues/796) and its sub-issues. PR 797 closed sub-issue
787 and PR 810 closed issue 788; issue 798 waits for the cleanup.

### Decisions recorded 2026-10-12

- **v0.5.0 scope (D5).** Keep ADR-0043 (#742), price correctness (ADR-0045, #774) and booking
  idempotency (ADR-0046, #775). Fix #782 and #785 before release. Defer unrelated milestone work:
  #654, #483, #86 and #80 leave the 0.5.0 milestone. #762 is undecided: it concerns assigning roles
  when a user is created, which the explicit `MASTER_DATA_ADD` grant may touch.
- **#782 (D6).** Product merge requires `MASTER_DATA_EDIT`.
- **#785 (D6).** A booking route refuses a caller without `STOCK_VIEW` before it books.
- **ADR-0045 Q8 to Q13, ADR-0046 Q5 and ADR-0043's release:** answered, recorded in PR
  [831](https://github.com/datagen24/victual/pull/831).

The milestone edits were not applied: the session's permission rules refused issue writes. The
maintainer applies them.

| ID | Item | State | Blocks |
|---|---|---|---|
| A1 | Accept ADR-0043, ADR-0045 and ADR-0046 | Every open question is answered once PR 831 merges. Acceptance is a bookkeeping PR per record, by the maintainer | #742, #774, #775 implementation |
| F1 | #782 merge gate and #785 up-front refusal | Implementation worker (2026-10-12), two branches | v0.5.0 |
| D4 | Issue 702's text still names a real device | Maintainer | Closing #702 |
| D7 | #762 in or out of v0.5.0 | Maintainer | #703 |
| D8 | #783 shopping-list container rights | Maintainer; outside v0.5.0 unless added | — |

## Specifications

### 772: document what the stock routes send

Problem: `victual.openapi.json` (unchanged since `89cea94a`, the v0.4.0 document) lists 4 of the 11
transaction types `StockService` writes (`services/StockService.php:36-71`); a generated client
rejected a successful transfer's `transfer_from` row. `missing_products[].product` and the
aggregated amounts on product details are sent and undocumented. The shared enum is also referenced
by the add and consume request bodies, where the server accepts {purchase, inventory-correction,
self-production} and {consume, inventory-correction}, so the document offers `product-opened` there,
which the server refuses.

Desired behaviour: the document describes the responses as they are; the server does not change
(ADR-0005: no wire change). Split the enum into a response enum of 11 values and one request enum
per booking. Add the embedded product, the aggregated amounts, the default-location fields, and the
undocumented `StockLogEntry`, `CurrentStockResponse` and `StockEntry` keys. Correct the `avg_price`
and parent `value` descriptions to current behaviour.

Excluded: inherited `Product`, `Location`, `QuantityUnit` and `ProductBarcode` gaps (about 200 key
paths across all routes); a general key-coverage check; controller validation of the request enum;
the misspelled `CurrentVolatilStockResponse` name.

Tests: `ContractTest` legs that check every recorded enum value against the document and the
`TRANSACTION_TYPE_*` constants against the response enum in both directions; fixture graph
extended so volatile lists are not empty and stock-edit, measurement and self-production rows
appear. Golden files gain additions only.

Follow-up PR (no gate): a key-coverage check scoped to the stock-route schemas, without an
allowlist, after this PR documents those schemas. The remaining inherited gaps need their own issue.

### 774: unknown stock value

The issue proposes `null` when any contributing entry is unpriced. Research found a prerequisite:
the web purchase form stores a blank price as 0 (`public/viewjs/purchase.js`), and price history,
the average price and `recipes_resolved.prices_incomplete` already treat 0 as unknown. A stored 0
mostly means "not entered", so "0 means free" needs a decision about the write path and existing
rows before the response shape.

A `null` `value` has three further consequences. It is a wire change that no ADR-0005 exception
covers. It creates a new engine difference against the frozen SQLite line in
`.devtools/pgsql/difftest.php`. It becomes 0.0 in the Influx capture (`BookingEventPublisher.php:221`). Specification: Proposed
ADR-0045 with three options (null, an additive `*_incomplete` flag, document only) and per-field,
per-case tables. Implementation waits for the maintainer's choice and victual-kit coordination
(ADR-0024).

### 775: idempotent stock bookings

Specification: Proposed ADR-0046. Recommended shape, pending the maintainer:

- An optional `Idempotency-Key` header on the five bookings and their by-barcode wrappers.
- Scope (user, operation, key), not API key (ADR-0041 rule 1).
- A fingerprint over operation, resolved product and body.
- The key row written in the booking's own transaction, so a racing duplicate blocks and then replays.
- A replay re-reads the booking under current permissions. Failures are not stored.
- Retention of 24 hours or 7 days (a decision). No change without a key. Nested callers take no key.
- A capability signal for clients.

`transaction_id` stays server-minted. It has no uniqueness, undo and `GET` select by it globally, and
nested callers share one id across many bookings.

F5 (booking commits, then 403 on the row read) is a separate authorization decision. Every seeded
role holds `STOCK_VIEW`, so only accounts with custom direct grants are affected.

### 742: master-data rights

Inventory complete (RESULTS.md addendum). Options for the maintainer, unchanged from the issue:

- A. Accept ADR-0043: a create-only right below `MASTER_DATA_EDIT`, granted per household.
- B. A purpose-built endpoint that creates a stock-only product and its conversion inside the
  medication flow under `STOCK_CONSUME`.
- C. Leave creation with `MASTER_DATA_EDIT` holders and document it; the victual-kit wizard already
  hands off to the web UI.

A and B both add an authorization rule, so neither starts before a decision.

## Baseline

Taken in a detached worktree of `master` at `d4960138` (no change of this branch in it), podman on
macOS (4 CPU, 8 GiB VM), `localhost/victual:dev` PHP 8.5.10, PostgreSQL in a container capped at
3 GiB with a tmpfs data directory. Runner: the podman recipe in
`memory/reference_local_environment.md`, one suite at a time behind a machine-wide lock.

| Run | Result |
|---|---|
| PostgreSQL 16.15, `SUITE_COVERAGE=1`, scratch on a host mount, 2026-10-11 23:21Z, 31 min 30 s | `SUITE FAILED — 3 case(s) differ`: `StorageFilesTest::testListingAnEmptyGroupWithTheEmptyPrefix` (filesystem backend; fails when scratch is on a host mount, per the environment notes), `MqttCoverageTest::testABrokerThatHangsUpMidBatchIsNotRecordedAsDelivered` (also failed locally on master `f42ecbbd` and passed in CI, plan 22 Executed), and the runner's own coverage report (PHP's 128 MiB `memory_limit`) |
| Coverage, `php -d memory_limit=2560M .devtools/coverage/report.php` over the run's `.cov` files | 13,992 of 14,520 lines (96.36%) from the runner alone. CI merges extra steps and reads higher; the last CI figure recorded in plan 22 is 96.72% (run 38065419551). The `tests.yml` ratchet is 96.31198844487241217394. Per-file floor not computed from this run |
| PostgreSQL 15.19, no coverage, scratch in `/tmp`, 2026-10-11 23:53Z, 12 min 42 s | `SUITE PASSED` (exit 0), including `MqttCoverageTest` and `StorageFilesTest` |
