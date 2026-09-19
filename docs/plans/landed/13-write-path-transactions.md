# 13. Write-path transactions

**Goal:** No stock operation can leave the ledger half-written. Wrap the four unwrapped
multi-row entrypoints in transactions, and give `DatabaseImporter` the same guarantee.
**Depends on:** nothing.
**Status:** **landed in the codebase** (2026-08-29). All seven entrypoints and the
importer are wrapped; see [Executed](#executed) below for what landed, and for the two
places the plan's own scope grew in the doing. Everything from here down is the plan as
written and reviewed, kept because the reasoning is what the code has to keep being
judged against. Still the prerequisite it was written to be for letting anything other
than a human drive writes — in particular [02 MCP](../02-mcp-endpoint.md) — which is now
satisfied rather than pending.

## Today

The pattern is known and correctly applied in four places. `StockService::MergeProducts`
(`services/StockService.php:2160`), `StockService::CompactStockEntries` (`:2218`),
`ChoresService::MergeChores` (`ChoresService.php:317`) and
`RecipesService::ConsumeRecipe` (`RecipesService.php:114`) all do the same thing:

```php
DatabaseService::GetInstance()->GetDbConnectionRaw()->beginTransaction();
try { … } catch (\Exception $ex) { …->rollback(); throw $ex; }
…->commit();
```

The four operations that most need it do not have it.

| Entrypoint | What it does per iteration | Failure leaves |
|---|---|---|
| `ConsumeProduct` (`:516`) | deletes or updates a `stock` row, inserts a `stock_log` row, per stock entry until the amount is satisfied | some entries consumed, some not, `stock` and `stock_log` disagreeing about the same transaction id |
| `TransferProduct` (`:1676`) | same shape, plus freeze/thaw due-date recalculation, plus a **synchronous label-printer webhook per entry** (`:1774`) | stock split across two locations mid-move, and labels already printed for entries that were then rolled back |
| `UndoBooking` (`:1917`) | recursive — undoes a booking and its correlated bookings | a partially undone booking, which the ledger has no way to represent |
| `UndoTransaction` (`:2113`) | iterates every `stock_log` row of a transaction calling `UndoBooking(…, true)` | a partially undone transaction |

Each runs as a sequence of autocommit statements. Nothing is corrupt in the database
sense — every individual statement is valid — but the inventory ledger's invariant, that
`stock` and `stock_log` describe the same history, is not enforced anywhere except by the
code completing.

Two structural details that shape the fix:

- **`UndoTransaction` calls `UndoBooking`, and `UndoBooking` is itself recursive.** PDO
  does not nest transactions. Wrapping both naively means `beginTransaction()` inside an
  open transaction, which throws on both engines.
- **`AddProduct` (`:209`), `InventoryProduct` (`:1253`) and `OpenProduct` (`:1343`) have
  the same shape** — loops over stock entries with webhook calls at `:339`, `:392` and
  `:1414`. The review named four entrypoints; these three are the same class of risk and
  the question of whether they are in scope is Q4, not an assumption.

**`DatabaseImporter`** (`services/Database/DatabaseImporter.php`) truncates every common
table in the target (`:70`), then copies row batches, then asserts the row counts match
(`AssertRowCountsMatch`, `:288`). There is no transaction around any of it: a failure
partway through leaves the target truncated and half-repopulated, with nothing to roll
back to. The post-check compares counts only — which is exactly the check that all fifteen
of the type-coercion hazards (1-15) in `db/pgsql/README.md` would pass.

## Proposed change

### A transaction helper, because of the nesting problem

```php
// DatabaseService
public function InTransaction(callable $work)
```

Begins a transaction if none is open, runs `$work`, commits; if one is already open, just
runs `$work` and lets the outermost caller own the commit. That makes
`UndoTransaction` → `UndoBooking` → `UndoBooking` safe without savepoints and without
each method having to know whether it is the outermost.

Savepoints would give partial rollback and are supported on both engines, but nothing here
wants partial rollback — an undo that half-succeeds is precisely the state being
eliminated. Depth counting is the simpler mechanism and matches the semantics. Q2 checks
that reasoning.

The four existing correct sites are then rewritten onto the helper too, so there is one
idiom rather than one idiom plus a helper.

### The four entrypoints

`ConsumeProduct`, `TransferProduct`, `UndoBooking` and `UndoTransaction` wrap their body
— validation included or excluded, Q3 — in `InTransaction`. This mirrors `MergeProducts`
exactly; there is no new mechanism.

### The webhook inside `TransferProduct`'s loop

This is the one real design decision in the plan, and it exists for `AddProduct`,
`InventoryProduct` and `OpenProduct` too if Q4 brings them in.

A label-printer webhook is an outbound side effect with a 2 s timeout, called while a
write transaction is open. Two problems compound: it holds the transaction open for up to
2 s per entry (on SQLite, that is a held write lock), and a rollback afterwards cannot
un-print a label.

Three shapes:

- **(a) Collect and fire after commit.** Accumulate the webhook payloads during the loop,
  commit, then run them. The transaction is short, and a label is only printed for a
  transfer that actually happened. If the webhook then fails, the printer did not print —
  which is the pre-existing behaviour and is handled (defect 6 made `WebhookRunner` catch
  `GuzzleException` rather than escaping).
- **(b) Leave it inline.** Simplest diff, keeps the code shape, accepts that a rollback
  may leave labels printed for entries that did not move and that the transaction is held
  open across network I/O.
- **(c) Fire-and-forget.** Not really available — `WebhookRunner` is synchronous Guzzle
  and making it asynchronous is a bigger change than this plan.

I lean to **(a)**. It is a small refactor, it makes the printed label mean "this
happened", and it takes the network call out of the lock. Q1.

### `DatabaseImporter`

Wrap `Import()` — truncate and all — in a single target-side transaction. PostgreSQL
handles DDL and `TRUNCATE` transactionally, so this genuinely works there; it is the only
target engine today. A failure then leaves the target exactly as it was, which is what
"you can re-run the import" should mean.

Add a value-level spot check alongside `AssertRowCountsMatch`: for each common table,
compare a bounded sample of rows column-by-column between source and target, with the
comparison normalised the same way `difftest.php` normalises engine differences. The
sampling rule is Q5. This is the check that would catch the coercion hazards — a `TINYINT`
id that became a boolean, an `INTEGER` that lost a fraction — none of which change a row
count.

`SetTriggersEnabled` already exists and is used around the copy; the transaction has to
wrap that too, or a failure leaves triggers disabled on the target, which is worse than a
half-copy.

### Not in scope

`UndoBooking`'s switch contains the same undo-bookkeeping block seven times, and
`StockService`'s methods return LessQL rows from some paths and plain `stdClass` from the
raw-SQL ones. Both are real debt in files this plan opens, and both are tempting to fix
while in there. Neither should be — a transaction change verified by "the ledger is
consistent" is verifiable; a transaction change plus a deduplication is not. They belong
in [15](15-deliberate-cleanup.md) if anywhere.

### Schema

None. No migration, so no dual-engine migration shape applies. The change is entirely in
how existing statements are grouped.

Worth stating explicitly because it is easy to assume otherwise: this does not add,
remove or re-order any statement. The same rows are written in the same order; they are
merely committed together.

### API

**No change.** Same endpoints, same request bodies, same success responses, same status
codes. The only observable difference is on the failure path: an operation that fails
partway now returns its error *and* leaves the database as it was, where today it returns
the same error and leaves the database partly changed. No response field moves.

One second-order note: if Q1 lands on (a), the webhook fires after commit rather than
during the loop, so a label prints marginally later and — on a failed transfer — not at
all. Nothing on the wire changes.

**Client impact: none, and it is the good kind of none.** A client that retried a failed
consume used to retry against a half-changed database and could double-count; now it
retries against the state it started from. Behaviour a client relied on cannot have been
relied on correctly, so there is nothing to warn about — but the *label printer* is a
client of a sort, and it is the one thing that changes: a failed transfer no longer prints
a label for a move that did not happen.

## Verification

Row counts are not enough here, and neither is "it still works". The check has to be that
a *failed* operation leaves nothing behind.

1. **Injected mid-loop failure, per entrypoint.** Temporarily throw from inside each of
   the four loops after the first iteration (a debug `throw`, reverted afterwards — the
   defects table's item 1 is a reminder of what happens when one is not). For each:
   snapshot `stock` and `stock_log` fully before, run the operation, expect the error,
   snapshot after. The two snapshots must be identical, row for row and value for value.
   Today at least three of the four will differ, and that difference is the baseline
   proving the test is real.
2. **The same, on both engines.** SQLite and PostgreSQL handle rollback of a loop of
   deletes differently enough to be worth checking separately, and SQLite's write-lock
   behaviour under a longer transaction is only observable there.
3. **`trigdifftest.php` before and after.** `01_purchase_consume_undo.sql`,
   `03_parent_child_products.sql`, `06_qu_change_with_stock.sql` and `07_cascades.sql`
   exercise the same triggers these entrypoints fire; every one must produce identical
   table state across both engines before and after the change.

   What this does **not** do, despite an earlier wording here that said it did: those
   scripts are plain SQL applied straight to each engine through PDO — nothing under
   `.devtools/pgsql/` calls `StockService` — so they never enter the wrapped code and
   never run inside one of the new transactions. They confirm the surrounding trigger
   layer is untouched, which is worth having and is a real regression check. They cannot
   confirm that an entrypoint rolls back, and reading a green suite as evidence of that
   would be reading it wrong.

   Which leaves check 1 as the only thing that verifies the property this plan exists
   for. That is an argument for its probe being committed rather than run once and
   discarded — as things stand, nothing in CI would notice if the wrapping were removed
   again.
4. **Happy-path equivalence against a real dataset.** Take a populated database, run a
   scripted sequence — purchase, consume partial, transfer between locations, open, undo
   the transaction — and diff the resulting `stock` and `stock_log` against the same
   sequence on the unmodified code. Identical, or the wrapping changed behaviour.
5. **Webhook behaviour.** With a webhook target that always fails: a transfer must still
   succeed and still commit (defect 6's guarantee). With a webhook target that logs: under
   Q1's answer (a), a *failed* transfer must produce zero webhook calls, and a successful
   one must produce the same number as before the change.
6. **SQLite lock contention.** Transfer a product with many stock entries while a second
   process reads. On SQLite the longer write transaction is the one plausible regression
   in this plan; it needs to be observed rather than assumed away, particularly if Q1
   lands on (b) and network I/O stays inside the lock.
7. **`DatabaseImporter`.** Import a real SQLite database into an empty PostgreSQL target,
   then repeat with a forced failure partway (an unwritable column type, or a `kill`
   mid-run): the target must be either fully imported or untouched, never truncated and
   partial. Then run `difftest.php` with `DIFFTEST_SKIP_COPY=1` against the imported
   database — the existing workflow for verifying the real import command — and confirm
   the value-level spot check agrees with it.

## Sequencing

**Before [02 MCP](../02-mcp-endpoint.md), and specifically before 02's write tools.** This
is the review's own ordering and it is right: read-only MCP tools do not need it, but the
moment an assistant can call `consume_product`, "a failure mid-loop leaves a half-consumed
booking" stops being a theoretical risk and becomes something that will happen unattended,
with nobody watching the screen to notice the stock went strange. If 02 ships read-only
first (as both the plan and 02's Q2 response recommend), this can
land during that window rather than blocking it.

**Independent of the other hardening plans.** It touches `services/StockService.php`,
`services/DatabaseService.php` and `services/Database/DatabaseImporter.php`.
[10](10-cold-start-statelessness.md) touches the migration service and
[11](../11-api-error-handling.md) touches controllers; neither collides. It can be done in
parallel with any of them.

**It de-risks [07 nested products](../retired/07-nested-products.md) as a side effect.** 07 makes
`stock_current` aggregate across a subtree, which means more rows touched per operation
and more places for a half-completed loop to produce a plausible-looking but wrong
aggregate. Doing this first means 07's fixtures are asserting against a ledger that
cannot be half-written.

Against feature plans generally: it blocks none of 01–09 outright.

## Open questions

1. **What happens to the per-entry webhook inside `TransferProduct`'s loop?** Options (a),
   (b) and (c) above. I lean to (a) — collect during the loop, fire after commit — because
   it takes a 2 s network call out of a write transaction and makes a printed label mean
   the transfer happened. The cost is that the webhook payload has to be built and held
   rather than sent inline, which is a slightly bigger diff in a method that is already
   long.

   > **Response:** (a). Implementation note worth a comment in the code: build the
   > payloads *eagerly during the loop* from values in hand — only the firing moves
   > after commit. A label describing an entry should describe it as it was booked,
   > not as re-read after commit.
2. **Depth counting or savepoints for the nested `UndoBooking` case?** Depth counting is
   simpler and matches the semantics (nothing wants partial rollback). Savepoints are more
   general and would let a future caller undo one booking of a transaction without
   abandoning the rest — which is not a use case that exists today. I lean to depth
   counting, but if `InTransaction` is going to be the fork's one transaction idiom
   forever, it is worth deciding deliberately rather than by expedience.

   > **Response:** Depth counting. Nothing wants partial rollback, and an undo that
   > half-succeeds is the disease being cured. Note the helper is not merely
   > convenient but *required* by the existing call graph: `ConsumeRecipe` already
   > wraps, and once `ConsumeProduct` wraps too, that pair nests before any undo
   > recursion enters the picture. If a future feature genuinely needs savepoints,
   > the helper's signature doesn't change.
3. **Does the transaction include the validation, or start after it?** `ConsumeProduct`
   and `TransferProduct` both do a run of `throw`-on-invalid checks before touching
   anything. Including them is simpler to write and means a validation failure rolls back
   an empty transaction, which is harmless. Excluding them keeps the transaction as short
   as possible, which matters on SQLite. Marginal either way; worth being consistent.

   > **Response:** After validation — the transaction contains only writes, the
   > SQLite write-lock window stays minimal, and it is the version of consistent
   > that reads best.
4. **Are `AddProduct`, `InventoryProduct` and `OpenProduct` in scope?** They have the same
   loop-plus-webhook shape as `TransferProduct` and the same failure mode. The review
   named four entrypoints, not seven. Including them triples the webhook question's blast
   radius but leaves nothing half-done; excluding them means a second pass later. I lean
   to including them, on the grounds that "the write paths are transactional" is a
   property worth being able to state without exceptions.

   > **Response:** Include all seven. "Every stock write path is transactional" is
   > the property to have — and `InventoryProduct` is precisely the entrypoint plan
   > 06's future camera-ingest work would hit hardest. Q1's webhook answer applies
   > uniformly, so this is one decision applied seven times, not three new
   > decisions.
5. **What is the sampling rule for the importer's value-level check?** Every row is
   correct and, for a 30 MB database, probably fast enough to just do. A bounded sample
   (first N, last N, plus N random) is cheaper but can miss the one coerced value. Given
   the import runs once per deployment lifetime, I lean to checking everything and
   accepting the runtime — but that should be measured on a real database rather than
   assumed.

   > **Response:** Everything, measured. It runs once per deployment lifetime
   > against a ~30 MB database; stream rows with difftest's normalization and
   > compare all of it. If measurement says minutes rather than seconds, that is
   > still fine for a once-ever command.
   >
   > "difftest's normalization" is unnamed work, so name it: `normalise()` lives as
   > a bare function at `.devtools/pgsql/difftest.php:106`, which is neither
   > autoloaded nor shipped in the image, while `DatabaseImporter` is in
   > `services/`. It has to move to be shared. It moves to `services/` as part of
   > [14](14-contract-and-regression-scaffolding.md) piece 1 — which owns that file
   > and needs the same function for its comparator — with the `.devtools` scripts
   > calling it there. This plan consumes it; it does not extract it. If 13 somehow
   > lands first, extract it here and 14 inherits it, but do not duplicate it in
   > both places.
6. **Should `InTransaction` be on `DatabaseService` or on the dialect?** It is
   engine-independent as written, which argues for `DatabaseService`. But
   [10](10-cold-start-statelessness.md) proposes a per-engine `WithMigrationLock` on
   `DatabaseDialect`, and having two similar-looking wrappers in two places invites
   confusion about which to reach for. Worth deciding once, with both plans in view.

   > **Response:** `DatabaseService`. The split against 10's dialect-level
   > `WithMigrationLock` is principled — engine-neutral composition on the service,
   > engine-specific behavior on the dialect. A cross-referencing docblock each way
   > solves the discoverability worry; colocation would solve nothing.

## Executed

Landed 2026-08-29 in three commits, in the order the plan argues for: the helper first,
then the entrypoints that use it, then the importer.

- **`7abfd2fa` — the transaction helper.** `DatabaseService::InTransaction(callable)`
  (`services/DatabaseService.php:221`). An inner call joins an already-open transaction
  and the outermost caller owns the commit, which is what the nesting problem above
  needs and what none of these operations wants to differ from — nothing here wants a
  partial rollback. "Is a transaction already open?" is asked of PDO rather than tracked
  in a counter, because `DatabaseMigrationService` opens its own transactions directly
  and a counter would not know about them.
- **`782289b8` — the entrypoints.** **Seven, not the four this plan's table names**, per
  question 4: `AddProduct`, `ConsumeProduct`, `InventoryProduct`, `OpenProduct`,
  `TransferProduct`, `UndoBooking` and `UndoTransaction`. "Every stock write path is
  transactional" is worth being able to say without exceptions. The four sites that
  already rolled their own transactions — `MergeProducts`, `CompactStockEntries`,
  `ChoresService::MergeChores` and `RecipesService::ConsumeRecipe` — were converted to
  the same helper, so the codebase has one idiom rather than an idiom plus a helper.
  Each transaction starts after validation, per question 3, so it holds only writes and
  the SQLite write-lock window stays as short as the work. The label-printer webhooks
  moved out of the transaction per question 1(a), with payloads still built inside the
  loop so a label describes the entry as it was booked.

  **Eight since 2026-09-02**: `EditStockEntry` was wrapped by
  [18](../18-mqtt-state-publication.md)'s review, which found it writing a correlated pair of
  `stock_log` rows and mutating the stock row between them with no transaction at all. That
  it was missed here is the interesting part - "every stock write path is transactional"
  was said above about the seven *booking* entrypoints, and an edit that rewrites a booking
  pair is one of those by any reading. 18 forced the question by adding a ninth write to
  the method that has to commit with the rest or not at all; the fix is 13's shape applied
  to an eighth entrypoint, recorded here so this list stays the authority on which paths
  are transactional.
- **`96f9ec99` — the importer.** The transaction covers the truncate, the trigger
  toggling and the copy, which it has to: the truncate happens before anything can go
  wrong, and a failure between disabling and re-enabling triggers leaves a target that
  looks fine and has quietly stopped maintaining itself. The row-count check became a
  column-by-column value comparison through `ValueComparison` — the same normalisation
  [14](14-contract-and-regression-scaffolding.md)'s suite uses — per question 5, because
  every one of `db/pgsql/README.md`'s fifteen coercion hazards (1-15) arrives with the right
  number of rows and the wrong values inside them.

Verified as the Verification section asks: a probe that injects a real failure mid-operation
and compares the full `stock` and `stock_log` ledger before and after. On unmodified code
all five cases report DIRTY, every one genuinely throwing; with the change all five are
CLEAN and the ledger is byte-identical after a failed operation. That baseline is the part
that makes the result mean anything. Webhook behaviour was measured against a request-counting
target rather than reasoned about: a successful three-unit labelled purchase fires three
calls as before, and the same purchase failing on its second unit now fires zero where it
previously printed a label for a unit that was then rolled back. The differential suite
passes on both engines.

## Effort

Small — the smallest of the hardening plans by code volume. The helper is an hour, the
four (or seven) wraps are mechanical, the importer transaction is a `beginTransaction`
around an existing method body. The webhook decision in Q1 is the only design work, and
the value-level spot check is the only genuinely new code.

The verification is a larger share of the total than the implementation, which is the
right ratio for a change whose entire purpose is what happens when something fails.
