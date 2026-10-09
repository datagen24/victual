# ADR-0040 prerequisite evidence

Evidence for [ADR-0040](../../docs/adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md),
which is Proposed. This directory does not accept, edit or amend the ADR. It changes nothing under
`services/`, `controllers/`, `migrations/`, `tests/` or `victual.openapi.json`. The scratch tables
are shaped like the ADR's description; they are not a design for
[issue 698](https://github.com/datagen24/victual/issues/698).

- **Date:** 2026-10-09. The runs started at 13:50 UTC and ended at 14:18 UTC.
- **Commit:** `14b2df2e1c3d4f9ff70d09a0d3befef377a6b5ca` (`master`). `run.sh` extracts that tree with
  `git archive HEAD` and overlays this directory. The ADR was recorded against `216af2b2`; the files
  it cites (`EntityReadPolicy.php`, `GenericEntityApiController.php`, `UserfieldsService.php`,
  `LabelIdentityService.php`, `User.php`, `victual.openapi.json`) have no diff between that commit and `14b2df2e`.
- **Host:** macOS (Darwin 27.0.0, arm64), podman 6.0.2, VM with 4 CPUs and 8 GiB.
- **Images:** `localhost/victual:dev` (id `05591fb29900`, PHP 8.5.10), `localhost/victual-pg:pgtap`
  (id `b74ab6fca41d`, PostgreSQL 16.15) and `docker.io/library/postgres:15` (id `572491f76228`, PostgreSQL 15.19).
  The PostgreSQL container starts with `-c track_commit_timestamp=on`. Isolation level is the default,
  `read committed`; `deadlock_timeout` is the default, 1 s.
- **Schema:** each run migrates a throwaway schema to HEAD through `PgsqlSchemaTestCase`, the
  path the repository's PHPUnit tests use, and drops it. Scratch tables are in `scratch.sql`.

## Commands

```sh
.devtools/adr0040/run.sh perm > .devtools/adr0040/evidence/permission-noninteraction.json
A40_N=400 .devtools/adr0040/run.sh race > .devtools/adr0040/evidence/race-postgres16.json
A40_N=400 PG_IMAGE=docker.io/library/postgres:15 .devtools/adr0040/run.sh race > .devtools/adr0040/evidence/race-postgres15.json
A40_CALLER=9301 .devtools/adr0040/run.sh surface > .devtools/adr0040/evidence/surface-limited-caller.json
A40_CALLER=9000 .devtools/adr0040/run.sh surface > .devtools/adr0040/evidence/surface-admin-caller.json
.devtools/adr0040/repro.sh > .devtools/adr0040/evidence/deadlock-repro.txt
python3 .devtools/adr0040/summarize.py .devtools/adr0040/evidence/race-postgres16.json
```

| File | Role |
|---|---|
| `run.sh` | Builds a PostgreSQL and a PHP container on a private network named `a40-<pid>`, runs one probe, removes both. `A40_KEEP=1` skips the removal. |
| `common.php`, `scratch.sql` | Schema bootstrap on the repository's own PHPUnit base class; scratch tables. |
| `perm-probe.php`, `perm-child.php` | Probe 1. |
| `race-probe.php`, `race-worker.php`, `summarize.py` | Probe 2. |
| `surface-probe.php` | Probe 3. |
| `repro.sh` | Probe 2 follow-up: the S7 deadlock without the application. |
| `evidence/` | Output of the runs above. Race files are compact JSON with every iteration; `*.summary.txt` holds the tables. |

## Probe 1: share writes do not change resolved permissions

Result: **pass.** This shows that, for scratch tables of this shape, writing share rows does not
change what `User::MayAdminister()`, `User::CheckMayGrant()` and `user_permissions_resolved` return. It does not show that a future
implementation is correct. It shows nothing about code that does not exist yet, such as a route that
reads a share.

Users: owner (`STOCK_VIEW`, `STOCK_CONSUME`), grantee (same), unrelated (`STOCK_VIEW`), account manager
(`USERS_EDIT`, `STOCK_VIEW`, `STOCK_CONSUME`) and administrator (`ADMIN`). One long-lived PHP process per caller
runs the real `User` class, because `VICTUAL_USER_ID` is a constant. At each checkpoint every caller records:
the resolved permission names of all five users, `MayAdminister()` for all five targets, and `CheckMayGrant()` for six
permission-id sets. The canonical JSON of the 5 callers is compared byte for byte with the baseline.

| Measure | Value |
|---|---|
| Checkpoints compared with the baseline | 18 (9 named steps, 8 in a seeded random phase, 1 after deleting all recipes) |
| Rows affected by direct statements on the share tables | 350 (inserts, updates, deletes, ownership changes, recipe deletes that cascade) |
| Checkpoints identical to the baseline | 18 of 18 (`sha256` `214906242fc9…` at every checkpoint) |
| Random phase | 400 operations, seed `40100909` |
| Positive control | Inserting `USERS_EDIT` into `user_permissions` for the grantee changed 8 of the 15 recorded sections (`admin.resolved`, `grantee.resolved`, `grantee.may_administer`, `grantee.check_may_grant`, `manager.resolved`, `owner.resolved`, `owner.may_administer`, `unrelated.resolved`). Deleting the row restored the baseline hash. |

The share writes included: a share to the account manager and to the administrator with all five rights, a
share holder granting to another user, revocation, an ownership transfer, recipes owned by the
manager and the administrator, and recipe deletion. In every case the account manager's `MayAdminister()`
answers stayed the same (true for owner, grantee, unrelated and itself, false for the administrator).
Evidence: `evidence/permission-noninteraction.json`.

## Probe 2: rule 8 under randomized interleavings

Two long-lived worker processes, each with its own PostgreSQL session, receive one command each per
iteration. Each command carries a seeded random start delay (0 to 6 ms) and three seeded random holds
(0, 0.3, 1.5 or 4 ms, with 0 twice as likely) slept at fixed points inside the transaction to widen the
windows. Every operation follows rule 8 as written:

- consume: `SELECT … FOR SHARE` on the recipe row, then the share check, then
  `DatabaseService::LockProductsStock()`, then the real `StockService::ConsumeProduct()` for each line inside
  `DatabaseService::InTransaction()`, then an event row;
- grant, revoke, transfer, delete: `SELECT … FOR UPDATE` on the recipe row first.

Each transaction logs its transaction id and, with `track_commit_timestamp`, its commit time. The probe
derives the lock order from a clock reading taken right after the lock call returned. Seeds are
`40090910 + n` per scenario and are recorded with every scenario in the evidence. The delays are
replayable; the operating system's scheduling is not, so a rerun gives the same parameters and different counts.

Deadlocks are counted three ways: SQLSTATE `40P01` returned to a worker, the change in
`pg_stat_database.deadlocks`, and `deadlock detected` lines in the server log. The three agree in every run.
Each scenario ran 400 iterations on PostgreSQL 16.15 and again on 15.19. No iteration hung.

S1 checks six properties:

1. No consume committed after the revoke committed.
2. The consume's outcome matches the lock order.
3. The second transaction acquired the lock only after the first committed.
4. The share row is gone afterwards, so no revocation was lost.
5. A consume sent after both finished is refused.
6. A consume that reports success has its stock rows, and a refused one has none.

The other scenarios check the properties named next to their outcome tables.

### Results with rule 8 as written (PostgreSQL 16.15, then 15.19)

| Scenario | Outcome table (16.15) | Deadlocks | Violations |
|---|---|---|---|
| S1 consume under share vs revoke | Consume took the lock first and succeeded: 208. Revoke took it first and the consume was refused (absent): 192. 15.19: 204 and 196. | 0 and 0 | 0 and 0 |
| S2 grant vs grant, same user | 400 of 400: one `inserted` and one `updated`; the final rights equal the later committer's. 15.19: same. | 0 and 0 | 0 and 0 |
| S3 transfer ownership vs consume | The consume succeeded in 400 of 400. Consume locked first: 220. Transfer locked first: 180. 15.19: 212 and 188. Final state in every iteration: new owner, previous owner holds a share with all five rights, new owner has no share row. | 0 and 0 | 0 and 0 |
| S4 owner delete vs consume | Consume locked first and succeeded: 192; its event row kept the transaction id with `recipe_id` null. Delete locked first and the consume was refused: 208. 15.19: 188 and 212. Shares, lines and event links were gone in every iteration. | 0 and 0 | 0 and 0 |
| S5 holder grants vs owner removes the holder | Grant locked first and succeeded: 195. Owner change first: 205 (100 revoke then grant `absent`; 105 strip of the `share` right then grant `refused`). 15.19: 191 and 209. No grant committed after the holder lost the right. | 0 and 0 | 0 and 0 |
| S6 consume vs consume, two recipes with overlapping product sets | 400 of 400 both consumed, overlap between 0 and 4 products. | 0 and 0 | 0 and 0 |

The "violations" column counts, per scenario, the checks listed above plus the invariants named in the outcome
column (commit order, lock order, final state, stock rows). Commit timestamps never tied. In S1 the second
transaction acquired the recipe lock after the first committed in every iteration.

### Controls that show the harness detects a problem

| Control | Result (16.15, then 15.19) |
|---|---|
| S1 with the consume deciding from the share *without* the recipe lock | A consume committed after the revoke committed in 336 and 344 of 400 iterations. The same harness reports 0 for the locked version. |
| S2 with the grant *without* the recipe lock, only the unique constraint | 256 and 252 of 400 iterations ended with SQLSTATE `23505` for the second grant instead of an update. The other 144 and 148 completed as insert plus update. The share row count was one in every iteration. |

The second control matters for rule 8's wording: "the unique constraint makes a double insert impossible"
is true, but "the second sees the first's row and updates it" holds only because of the recipe lock.

### Finding: `edit` in the `FOR SHARE` class deadlocks

Rule 8 lists `edit` among the operations that take `FOR SHARE` on the recipe row. If an edit then writes the
recipe row (a name change) or updates lines, two concurrent edits both hold `FOR SHARE` and neither is serialized
by the recipe lock. Each then waits for the other's share lock to release before it can write. PostgreSQL
aborts one with `40P01`. This is the lock-upgrade deadlock; the ADR's lock order does not address it because both
transactions request the same lock first.

| Scenario | Both edits succeeded (16.15 / 15.19) | One edit aborted with `40P01` (16.15 / 15.19) |
|---|---|---|
| S7a: edit takes `FOR SHARE`, updates the recipe's `name` | 206 / 204 | 194 / 196 |
| S7b: edit takes `FOR SHARE`, updates the recipe's lines in random order | 317 / 312 | 83 / 88 |
| S7c control: edit takes `FOR UPDATE`, updates name and lines | 400 / 400 | 0 / 0 |

`repro.sh` reproduces the S7a pattern with two `psql` sessions and one table, and prints the server's report:
`Process 71 waits for ShareLock on transaction 734; blocked by process 73. Process 73 waits for ShareLock on
transaction 733; blocked by process 71.` With `FOR UPDATE` both sessions complete (`evidence/deadlock-repro.txt`).

This depends on the design choice that an edit writes the recipe row or the lines while holding only `FOR SHARE`.
The fix that S7c tested is to put `edit` in the `FOR UPDATE` class with grant, revoke, transfer and delete. That
serializes edits on one recipe and does not block a consume on a different recipe. A consume on the same recipe
would wait for an edit in flight, which was not measured.

### What the lock order did not deadlock on

The ADR's order (recipe row, then ascending product locks) produced no deadlock in S1 to S6: 0 deadlocks in
3,200 iterations on each PostgreSQL version (S1 to S6, counting the two controls as scenarios).
The real `ConsumeProduct()` takes its own product lock on top of the caller's `LockProductsStock()`; that did not interfere.
No revocation was lost and no consume committed after a revoke in the rule-8 versions.

## Probe 3: generic surface and the accepted leak

Method: the real `GenericEntityApiController` and `StockApiController` called directly with a Slim request, the way
`tests/Pgsql/RbacTest.php` calls them, plus the real `UserfieldsService`. It does not go through Slim routing or
HTTP, so route registration is not exercised. Two callers: user 9000 with `ADMIN`, and user 9301 with only
`STOCK_VIEW` and `MASTER_DATA_EDIT`. Requests that carry a body set `Content-Type: application/json`; without it the
controllers answer 400 `Bad Content-Type` before the entity check, which would hide the refusal under test.

Result: **pass.** Eight entities, none in `EntityReadPolicy::PERMISSIONS` or in the `ExposedEntity` enum of
`victual.openapi.json`: four scratch tables that exist (`a40_recipes`, `a40_recipe_lines`, `a40_recipe_shares`,
`a40_consume_events`) and four plausible names that do not exist as tables (`consumption_recipes`,
`consumption_recipe_lines`, `consumption_recipe_shares`, `consumption_events`). For each, nine calls:

| Call | Answer, both callers |
|---|---|
| `GetObjects`, `GetObject`, `GetUserfields`, `SetUserfields`, `AddObject`, `EditObject` | 400 `Entity does not exist or is not exposed` |
| `DeleteObject` | 400 `Invalid entity` (a different message) |
| `UserfieldsService::SetValues()` and `GetValues()` | exception `Entity does not exist or is not exposed` |

72 of 72 calls per caller were refused for the entity, and the scratch row in `a40_recipes` was unchanged.
Controls:

- `locations` lists with 200 for both callers.
- `recipes` answers 403 `Permission missing: RECIPES_VIEW` for the limited caller and 200 for the administrator.
  That is the "domain leaf and nothing finer" behavior in the ADR's Context.
- `SetUserfields` on `locations` with an unknown field answers 400 `Field x is not a valid userfield`.
  The call passes the entity check for an exposed entity.

`label_artifacts` and `label_captures` are in neither the policy nor the enum.

### The residual leak in rule 11

A consume of two products inside one `InTransaction()` with `recipeId` null, read by the limited caller (no
`STOCK_CONSUME`, no `RECIPES_VIEW`):

| Read | Rows | `recipe_id` |
|---|---|---|
| `GET /objects/stock_log?query[]=transaction_id=…` | ids 3 and 4, products 1 and 2, amount −1 each, one `transaction_id` | null |
| `GET /stock/transactions/{id}` | the same two bookings | null |
| `GET /stock/bookings/{id}` | booking 3 with that `transaction_id` | null |

The two products left stock together and a `STOCK_VIEW` holder can see that. The contrast run, with the same consume
given a food recipe id, shows `recipe_id` 1 on both bookings through the same three routes: the disclosure the ADR avoids by never setting it.
Evidence: `evidence/surface-limited-caller.json`, `evidence/surface-admin-caller.json`.

## ADR claims checked

Verified against the tree at `14b2df2e`:

- `EntityReadPolicy::PERMISSIONS` maps one view leaf per entity (`controllers/Users/EntityReadPolicy.php:10`); the generic read
  paths require both policy coverage and enum membership (`GenericEntityApiController.php:441`, `:487`).
- `stock_log.recipe_id` is returned by `/objects/stock_log`, `/stock/bookings/{id}` and `/stock/transactions/{id}` to a `STOCK_VIEW` holder (Probe 3).
- `LabelIdentityService::ResolveTarget` selects a recipe name with a raw query
  (`services/Labels/LabelIdentityService.php:202`) and the route gates it on `FieldCatalogue::DomainPermission($kind)`, `RECIPES_VIEW` for `recipe`
  (`controllers/Api/LabelsApiController.php:42`, `services/Labels/FieldCatalogue.php:246`).
- `PUT /api/userfields/{entity}/{id}` checks `MASTER_DATA_EDIT` then relies on `SetValues()` (`GenericEntityApiController.php:589`, `UserfieldsService.php:159`).
- The stock undo routes require `STOCK_EDIT` (`controllers/Api/StockApiController.php:1494`, `:1514`).
- `MayAdminister()` and `CheckMayGrant()` read `user_permissions_resolved` and `permission_tree` (`controllers/Users/User.php:176`, `:243`).

Imprecise or unverifiable:

| ADR location | Issue |
|---|---|
| ADR line 182-186 (rule 8) | `edit` is placed in the `FOR SHARE` class. When an edit writes the recipe row or lines, two edits deadlock (S7a, S7b above). "A single global order prevents a consumption and a revocation from deadlocking" is supported by S1; it says nothing about edit against edit. |
| ADR line 192 (outcome table, row 1) | "Consume begins before revoke commits" is not the dividing condition. The outcome followed which transaction acquired the recipe lock first: in 192 of 400 iterations on 16.15 the revoke locked first and the consume was refused. "Has acquired the recipe lock" is the accurate condition. |
| ADR line 194 (row 3) | "The second sees the first's row and updates it" holds only with the recipe lock. With only the unique constraint, 256 of 400 iterations ended with `23505` for the second grant (S2 control). |
| ADR line 196 (row 5) | "The consume completes; ownership changes afterwards" held in 220 of 400 iterations. In 180 the transfer locked first, so the consume completed after the ownership change. The consume succeeded in all 400, because the old owner holds all five rights after the transfer and the new owner is the owner. |
| ADR line 47 | The quoted phrase "a permission wearing a different shape" is not in the current `docs/plans/22-medication-tracking.md`. It is in the earlier revision, at line 188 of that file before commit `4ce3c662` (`git show 4ce3c662^:docs/plans/22-medication-tracking.md`). The draft dated 2026-09-04 is the right lineage; the citation needs the commit. |
| ADR line 24 | "No row in the current schema has an owner who is allowed to hide it from other holders of the same permission" is a claim about the whole schema. This work did not audit it. |
| ADR matrix, `userobjects` and `userentity-*` row | "No user-defined entity may reference a consumption recipe" has no enforcement in the tree. `EntityReadPolicy::Covers()` returns true for any name starting with `userentity-` (`EntityReadPolicy.php:85`). The statement is a requirement on issue 698, not a fact about today's code. |
| ADR "all generic paths … the existing 400" | The status is 400 everywhere, but `DeleteObject` answers `Invalid entity` where the others answer `Entity does not exist or is not exposed`. A test that pins the message would fail on delete. |

## Limitations and what this evidence does not show

- The scratch tables and the worker code are written from the ADR's text. They show that the stated lock sequence has the stated
  properties under the stated operations. They do not show that an implementation of issue 698 follows that sequence.
- Probe 1 covers share-table writes only. It does not cover a route that reads a share, a change to `permission_hierarchy`,
  or role assignment, and it makes no statement about administrator password reset (open question 1).
- Probe 2 uses two concurrent transactions per iteration. Three or more actors, such as two consumes against one revoke, were not run. Randomization
  covers start offsets and in-transaction holds, not the operating system's scheduling. The holds are up to 4 ms, longer than
  real windows, so they raise the collision rate; a measured rate of 49% for S7a is not a prediction for production.
- Row locks queue: a stream of `FOR SHARE` holders can delay a waiting `FOR UPDATE`. Starvation of a revoke under a
  steady stream of consumes was not measured.
- Removal of a global permission during a consume (rule 8's last paragraph) was not tested; the workers do not evaluate global permissions.
- `ConsumeProduct()` ran with `allowSubproductSubstitution` off. With it on, the product lock set can grow, and the caller's set
  would need to include the sub products to keep the order.
- Commit order comes from `pg_xact_commit_timestamp()`, microsecond resolution; no ties occurred.
- Probe 3 calls controllers directly. It does not exercise Slim routing, the OpenAPI contract test or any HTTP layer.
- The default `deadlock_timeout` of 1 s was used, so a worker that deadlocks waits about a second before PostgreSQL aborts one transaction.
- The repository's PHPUnit, pgTAP and Playwright suites were not run.
- All timings come from one laptop VM with other containers running.
