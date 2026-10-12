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
| [742](https://github.com/datagen24/victual/issues/742) master-data rights | victual-kit wizard | `.devtools/issue742/RESULTS.md` (93 probes, 2026-10-10); Proposed ADR-0043; static completion and ADR-0043 evidence revision on branch `claude/sonnet5_issue742-inventory-addendum-7c3e` (`7ee4f415`, Vale 0 new, `mkdocs build --strict` clean) | Choose option; implement if a new right is chosen | ADR-0043 acceptance | Maintainer: option A, B or C; ADR-0043 accept or reject; release | Options below | — | Not run |
| [772](https://github.com/datagen24/victual/issues/772) OpenAPI drift | victual-kit #24 | Branch `claude/opus5_issue772-openapi-stock-drift-a9d9` (`593d3a48` tests, `19c7b238` document, `e5a539c2` comment). New `ContractTest` legs failed on master's document (`Failures: 2`: 16 enum violations, 7 missing values) and pass on the fix; `contract` (21 tests) and `wirecontract` (116 tests, 1,953 assertions) pass on PostgreSQL 16 | Inherited `Product`, `Location`, `QuantityUnit`, `ProductBarcode` gaps and `ProductDetailsResponse.default_location` (documented, never sent) need their own issue; key-coverage check follow-up | — | None | Done | implementation worker | `contract` and `wirecontract` also pass on PostgreSQL 15.19 at `e5a539c2`; independent review pending |
| [774](https://github.com/datagen24/victual/issues/774) unknown price as 0 | victual-kit #29, #33 | See R2 results below | Null versus zero per field | Wire change | Maintainer: wire change (ADR-0005 has no exception for it) | Below | — | — |
| [775](https://github.com/datagen24/victual/issues/775) booking idempotency | victual-kit #35 | Static research 2026-10-11 (below) | Key design, implementation, OpenAPI, CORS | New ADR | Maintainer: accept the ADR | Draft ADR | — | — |
| [737](https://github.com/datagen24/victual/issues/737) share enumeration | #698 review | Maintainer decision 2026-10-09: accepted for v0.5.0, fix wanted afterwards | All (post-v0.5.0) | — | None; disposition stands | Chosen fix in the issue | — | — |

## New findings (no issue filed)

Numbering follows `.devtools/issue742/RESULTS.md`: F1 to F4 there, F5 here.

Filing an issue changes external state, so these wait for the maintainer.

- **F1. Product merge deletes shared master data under `STOCK_EDIT`.** `controllers/Api/StockApiController.php:1538`
  checks `STOCK_EDIT`; `StockService::MergeProducts` ends in `DELETE FROM products` and rewrites
  recipes, meal plans, chores, conversions and barcodes first. The seeded Adult role holds
  `STOCK_EDIT` through `STOCK` and not `MASTER_DATA_EDIT`; deleting a product through
  `DELETE /api/objects/products/{id}` needs `MASTER_DATA_EDIT`, and chore merge checks
  `MASTER_DATA_EDIT` (`ChoresApiController.php:184`). Inherited from upstream `c9b5e144`
  (2020). A finding under ADR-0006; part of the #742 inventory.
- **F5. A booking can commit and then answer 403.** `AddProduct`, `ConsumeProduct`,
  `TransferProduct`, `OpenProduct` and `InventoryProduct` book through `StockService`, whose
  transaction commits before the controller calls `StockTransactions()`, which checks
  `STOCK_VIEW` (`StockApiController.php:1301`). A caller holding the booking permission but not
  `STOCK_VIEW` gets 403 for a booking that happened. Observed in code; not exercised. Relevant
  to #775 because it is a second source of an unknown outcome. Fixing it is an authorization
  choice (refuse such callers up front, or answer them without the row read).
- **F4. Legacy import drops the three view leaves of migration 0299 (reproduced, fix ready).**
  `DatabaseImporter` truncates `permission_hierarchy`, copies the frozen source and replays only
  `roles-seed.sql` and `prices-seed.sql` (`services/Database/DatabaseImporter.php:847-866`);
  `BATTERIES_VIEW`, `CALENDAR_VIEW` and `EQUIPMENT_VIEW` come from migration 0299 alone. Found by
  the #742 documentation worker. The fix is on branch `claude/opus5_import-restores-view-leaves-f3eb`.
  - Test commit `fcc49e2c` fails the `import` phase with 12 failures: no row for each leaf, and
    ADMIN, Adult and Child resolve none of them.
  - Fix commit `489231d3` adds `db/pgsql/view-leaves-seed.sql` (0299's three inserts), replayed
    after `prices-seed.sql`. `import` and `rbac` pass on PostgreSQL 16 and 15.19.
  - 0299 was the only migration from 0266 to 0307 whose permission rows no replayed seed restored.
  - Relevant to #703's import rehearsal.
- F2 (userpictures scope) and F3 (shopping-list containers under item permissions) were
  rechecked: F2 is the known sweep S6 gap; F3 is a design question with no record.
</content>
</invoke>

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
