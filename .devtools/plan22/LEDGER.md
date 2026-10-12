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
| [772](https://github.com/datagen24/victual/issues/772) OpenAPI drift | victual-kit #24 | Branch `claude/opus5_issue772-openapi-stock-drift-a9d9`: new `ContractTest` legs failed on master's document (`Failures: 2`) and pass on the fix; `contract` and `wirecontract` pass on PostgreSQL 16 and 15.19 at `e5a539c2`. Independent review approved; its four should-fix items are in `57f687e2`, which passes both phases on PostgreSQL 16 and 15.19. PR [791](https://github.com/datagen24/victual/pull/791) | Merge; follow-up gaps in [787](https://github.com/datagen24/victual/issues/787) | — | None | Done | implementation worker | Verified |
| [774](https://github.com/datagen24/victual/issues/774) unknown price as 0 | victual-kit #29, #33 | Proposed ADR-0045 in PR [778](https://github.com/datagen24/victual/pull/778) (reviewed; four should-fix items applied) | Null versus zero per field; implementation | ADR-0045 decision; victual-kit coordination (ADR-0024) | D1 | ADR-0045 | — | Not run |
| [775](https://github.com/datagen24/victual/issues/775) booking idempotency | victual-kit #35 | Proposed ADR-0046 merged in PR [779](https://github.com/datagen24/victual/pull/779) (reviewed; five should-fix items applied) | Implementation after acceptance; migration slot claimed then | ADR-0046 acceptance | D2 | ADR-0046 | — | Not run |
| [737](https://github.com/datagen24/victual/issues/737) share enumeration | #698 review | Maintainer decision 2026-10-09: accepted for v0.5.0, fix wanted afterwards | All (post-v0.5.0) | — | None; disposition stands | Chosen fix in the issue | — | — |

## Findings and filed issues

Numbering follows `.devtools/issue742/RESULTS.md` for F1 to F4. Filed 2026-10-11 without a milestone;
release scope is decision D5.

| Finding | Issue | Kind | Evidence | State |
|---|---|---|---|---|
| F1. Product merge deletes a product under `STOCK_EDIT`, which the Adult role holds | [782](https://github.com/datagen24/victual/issues/782) | authorization (ADR-0006) | Read from source: `StockApiController.php:1538`, `StockService::MergeProducts`; upstream `c9b5e144` | Needs an authorization decision (D6) |
| F2. `userpictures` writes are not scoped to the target user | sweep S6 | authorization | `FilesApiController.php:50` | Known; no new issue |
| F3. Shopping-list containers use item permissions | [783](https://github.com/datagen24/victual/issues/783) | design question | `GenericEntityApiController` create, edit and delete gates | Needs a decision (D6) |
| F4. Legacy import drops the three view leaves of migration 0299 | [784](https://github.com/datagen24/victual/issues/784) | defect | Reproduced: 12 failures in the `import` phase without the fix | Fix in PR [789](https://github.com/datagen24/victual/pull/789); `import` and `rbac` pass on PostgreSQL 16 and 15.19; reviewed |
| F5. A booking commits, then answers 403 without `STOCK_VIEW` | [785](https://github.com/datagen24/victual/issues/785) | authorization | `StockApiController.php:1301` after the service commits | Needs an authorization decision (D6) |
| F6. Stock add accepts JSON `true` as `transaction_type` | [786](https://github.com/datagen24/victual/issues/786) | defect | Loose comparison at `StockService.php:390`; found by the #772 review | Fix ready to assign; no decision needed |
| #772 follow-up: inherited schema gaps and a key-coverage check | [787](https://github.com/datagen24/victual/issues/787) | documentation | Recorded keys in `contract-admin.json` | Ready to assign after #772 merges |
| `quantity_factor` next to `qu_id` on a mapping is undefined | [788](https://github.com/datagen24/victual/issues/788) | documentation | victual-kit matrix difference 7 | Ready to assign |
| Possible stale average-price cache after an insert | not filed | unverified | R2 research: `trg_stock_log_INS` only upserts | Needs a reproduction before filing |

## Open pull requests

| PR | Issue | State |
|---|---|---|
| [778](https://github.com/datagen24/victual/pull/778) ADR-0045 | 774 | Rebased onto master after PR 779 merged; review fixes in |
| [789](https://github.com/datagen24/victual/pull/789) import view leaves | 784 | Reviewed; drift check added |
| [791](https://github.com/datagen24/victual/pull/791) OpenAPI stock drift | 772 | Reviewed; `contract` and `wirecontract` pass on PostgreSQL 16 and 15.19 at `57f687e2` |

## Decisions needed from the maintainer

| ID | Decision | Options and recommendation | Blocks |
|---|---|---|---|
| D1 | What a stored price of 0 means, and how responses mark an unknown value | ADR-0045: P1, P2 or P3; then A (`null`), B (`*_incomplete` flags) or C (document only); parent aggregation (prerequisite 6). The record recommends P2 with B, converting `stock` only and recording a watermark | #774 |
| D2 | Accept ADR-0046 or not; retention 24 h or 7 days; how clients discover support | ADR-0046 recommends 7 days and a field in `GET /api/system/info` | #775 |
| D3 | Master-data creation for medication setup | A: accept ADR-0043 (create-only right); B: a stock-only product endpoint in the medication flow; C: leave it with `MASTER_DATA_EDIT` and document it | #742 |
| D4 | Issue 702's text still names a real device | Edit criteria 1 and 6 to match the 2026-10-11 decision, or close the issue with a pointer to plan 22's Executed section | closing #702 |
| D5 | v0.5.0 scope | Plan 22 items 742, 772, 774, 775 and the new issues 782 to 788; non-plan-22 milestone items 762, 654, 483, 86, 80 | #703 |
| D6 | Authorization choices | 782: merge needs `MASTER_DATA_EDIT` (as chore merge) or both rights; 783: a container right or document the item rights; 785: refuse callers without `STOCK_VIEW` up front, or answer them without the row read | 782, 783, 785 |

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
