# ADR-0036: A stock row's quantity is attributed to the bookings that added it

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-03, against `master` at `a7bf78a31d70f1351019aa6d6cc1e22479402b59`
  (clean working copy, branch `claude/sonnet5_booking-lineage-adr-e04bda`).
- **Referenced by:** [issue 609](https://github.com/datagen24/victual/issues/609) (the
  design request, split out of [issue 488](https://github.com/datagen24/victual/issues/488)
  at the maintainer's direction on 2026-09-28); answers the gap named in
  [ADR-0033](0033-stock-rows-merge-only-in-maintenance-for-non-expiring-rows.md)
  Consequences; evidence in [`.spike-adr36/RESULTS.md`](../../.spike-adr36/RESULTS.md).

This record is design work. It changes no code, reserves no migration number, and does not
accept ADR-0033. A Proposed record constrains nothing.

## Context

### What is decided, what is built, what is verified

| State | Fact | Source |
|---|---|---|
| Maintainer decision | Merging leaves the booking paths and becomes a maintenance command that merges only never-expiring, unlabelled rows. | [Issue 488](https://github.com/datagen24/victual/issues/488) comments, 2026-09-26 and 2026-09-27 |
| Maintainer request | A design, as an ADR, for stable lot, row and booking identity across maintenance merges, with a migration verdict. | [Issue 609](https://github.com/datagen24/victual/issues/609) |
| Record status | ADR-0033 is **Proposed**. This change corrects its stale statements that PR 531 was open and that nothing was built. | [ADR-0033](0033-stock-rows-merge-only-in-maintenance-for-non-expiring-rows.md) |
| Implemented | [PR 531](https://github.com/datagen24/victual/pull/531) merged 2026-09-27 (`d48e5b30`): atomic undo refusal. [PR 580](https://github.com/datagen24/victual/pull/580) merged 2026-09-28 (`0e3e61c1`): inline compaction removed, `bin/victual-compact-stock`, `migrations/0290.pgsql.sql`, `WeighLocation()` totals. | `git log`, GitHub |
| Not implemented | A deployed schedule for the maintenance command: `deploy/`, `nix/` and `.devtools/ci/` contain no reference to it (ADR-0033 prerequisite 3). | `grep`, 2026-10-03 |
| Verified here | The behavior in the next section, by running the real `StockService` in a disposable schema. Test suites were listed, not run. | [`.spike-adr36/evidence/baseline.json`](../../.spike-adr36/evidence/baseline.json) |

The merge in question is `StockService::CompactStockEntries()`. Since PR 580 it runs only
from the maintenance command, and only over the rows `stock_splits` (`0290`) selects:
unlabelled, never-expiring (`best_before_date` NULL or `2999-12-31`), not per-unit, not
measured, no userfield value, identical in product, dates, price, open state, location,
shopping location and note.

### What each identifier means today

| Identifier | Names | Behavior |
|---|---|---|
| `stock.id` | One physical row. | A split creates a new id. A merge keeps `MAX(id)` and deletes the rest. A whole-take consume deletes the row; its undo re-inserts under the old id when the identity sequence allows, else under a fresh id. |
| `stock.stock_id` | An opaque text tag set when a row is created. | An open split gives the remainder a new tag. A transfer split gives the new row the same tag. A merge rewrites every tag in the group to `MIN(tag)` in `stock` and `stock_log`. |
| `stock_log.id` | One booking. | Never changes. It is the only stable per-booking identity. |
| `stock_log.stock_id` | The tag of the row acted on when the booking was written. | A merge rewrites it. After a merge, two purchases carry one tag. |
| `stock_log.stock_row_id` | The row the booking acted on. | Set by consume, open, transfer, edit and measure. **NULL on purchases.** No foreign key. A merge never rewrites it, so it can name a deleted row. |
| `transaction_id` | One request. | Groups the bookings of one call. `UndoTransaction()` undoes them together. |
| `correlation_id` | The halves of one operation. | Edit old and new, transfer from and to. Undone as a set. |
| `stock_entry_origins` | A split remainder's tag, linked to the purchase tag it came from. | Keyed by tag. A merge rewrites it. Not populated by the importer, not backfilled before `0267`. |
| `labels.target_id` | The `stock.id` of a live `stock_entry` label. | Cleared when the row is deleted. Labelled rows are excluded from merging. |

### Observed failure of attribution

Runtime reproduction, PostgreSQL 16.15, PHP 8.5.10, `master` at `a7bf78a3`
([evidence](../../.spike-adr36/evidence/baseline.json)):

- Purchases of 3 (booking 1, tag `a`) and 2 (booking 2, tag `b`) merged into **row 2**
  (the row of the purchase of 2) with tag `a` (the tag of the purchase of 3). Both bookings
  now carry tag `a`. The surviving row and the surviving tag come from different purchases.
  The other order behaves symmetrically.
- Consuming 4 books one consume against tag `a`. Undoing the purchase of 3 or of 2 is refused
  with "subsequent dependent bookings", whichever purchase the consumed units came from.
- After the consume is undone, undoing the purchase of 2 is refused again, with "split across
  multiple rows in a way that cannot be unambiguously reversed". The consume's undo inserted a
  second row beside the first.
- Without any consumption, undoing the older purchase is refused and the newer one succeeds.
  Before the merge both were independently undoable.
- Two whole-row openings of 2 and 3 merged into one opened row of 5: undoing the second
  opening is refused ("no longer exists in that state").
- An amount edit followed by a merge: undoing the edit is refused. PR 531 turned silent loss
  into refusal.
- A transfer split leaves two rows with one tag. A later maintenance run skipped the group
  holding the sibling row and merged nothing, including two unrelated new purchases in that
  group. An open split followed by a new purchase also merged nothing; by the code, the
  lineage guard for a group spanning two purchases skipped it.

The ledger cannot answer which units of the merged row came from which purchase. Each
refusal above is correct given what is recorded, and several are broader than they need to
be. Source inspection alone gave the same account; the runs make it a measured result.

### Why a stable row id or a merge alias does not solve it

A stable row id gives one row of 5. An alias maps either purchase's tag to that row. Neither
records that 3 of the 5 belong to one purchase and 2 to the other, so neither can say what
undoing the purchase of 2 must remove after 4 units were consumed. The consumed units have no
recorded source. Attribution needs a quantity per booking, kept current as the row changes.

### Evidence about compaction itself

ADR-0033 records that no measurement establishes an operational need for merging. This
research found none either. The eligibility rules and the guards in `CompactStockEntries()`
(shared tag, lineage confinement) skipped the groups involving a split in the runs above. The decision below keeps the maintainer's stated direction and makes it safe. Question
1 asks whether it should stay.

## Decision

Quantities are attributed to **lots**, a lot being the units introduced by one addition
booking. A merge moves quantity between rows without rewriting any booking, tag or lineage
record. Undo follows the lots. Two tables carry the attribution; no existing column changes
meaning on the wire.

### 1. Identities

| Term | Definition | Lifetime |
|---|---|---|
| Booking | One `stock_log` row, identified by `stock_log.id`. | Permanent. Only `undone`, `undone_timestamp` and a unit-conversion rescale of `amount` change. |
| Lot | The units introduced by an addition booking: a purchase, a self-production, an inventory correction with positive amount, or the `stock-edit-new` booking of an edit that raises the amount. Its identity is that booking's `stock_log.id`. | Permanent as a record. Live while any row holds a contribution of it. |
| Physical row | One `stock` row, `stock.id`. | Created by additions, splits and rebuilds. Ended by whole consumption, being a merge source, or the removal of its last lot. Not an accounting identity. |
| Transaction | `stock_log.transaction_id`: the bookings of one request. | Unchanged. |
| Correlation | `stock_log.correlation_id`: the halves of one operation. | Unchanged. |
| Contribution | The amount of one lot currently held by one row. | Changes with every operation that moves quantity. Deleted with its row. |
| Allocation | The signed amount of one lot that one booking added, removed or moved. | Written with the booking. Immutable except for a unit-conversion rescale. |

`stock.stock_id` becomes a row-group tag. It keeps its existing roles: addressing an entry in
consume and transfer requests, the userfield object id, and the Grocycode `grcy:s` input. It
is never used to decide attribution, ordering or undo, and no operation rewrites it.

### 2. Data model

```sql
CREATE TABLE stock_row_lots (
	id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	stock_row_id INTEGER NOT NULL REFERENCES stock (id) ON DELETE CASCADE,
	lot_id INTEGER REFERENCES stock_log (id) ON DELETE CASCADE,     -- NULL: unattributed pool
	amount DOUBLE PRECISION NOT NULL CHECK (amount > 0),
	basis TEXT NOT NULL CHECK (basis IN ('recorded', 'derived', 'unknown')),
	UNIQUE NULLS NOT DISTINCT (stock_row_id, lot_id),
	CHECK ((lot_id IS NULL) = (basis = 'unknown'))
);
CREATE INDEX ix_stock_row_lots_lot ON stock_row_lots (lot_id);

CREATE TABLE stock_booking_lots (
	id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	booking_id INTEGER NOT NULL REFERENCES stock_log (id) ON DELETE CASCADE,
	lot_id INTEGER REFERENCES stock_log (id) ON DELETE CASCADE,     -- NULL: a recorded draw on the pool
	amount DOUBLE PRECISION NOT NULL CHECK (amount <> 0),
	basis TEXT NOT NULL CHECK (basis IN ('recorded', 'derived', 'unknown')),
	UNIQUE NULLS NOT DISTINCT (booking_id, lot_id),
	CHECK (lot_id IS NOT NULL OR basis = 'recorded')
);
CREATE INDEX ix_stock_booking_lots_lot ON stock_booking_lots (lot_id, booking_id);
```

The statements above are the schema exercised in the spike
([`proposed.sql`](../../.spike-adr36/proposed.sql)); the final column names and constraint
names are an implementation detail.

- **Basis** records how the row was established. `recorded` means a lineage-aware writer
  wrote it at booking time. `derived` means the backfill proved it by ledger arithmetic.
  `unknown` means the quantity is in the unattributed pool, or the lot's units were merged
  before tracking and cannot be located.
- **Pool.** A contribution with `lot_id` NULL is quantity in a row whose lot is unknown. The
  pool is per product: every lookup by pool, such as the dependency check and the search for a
  booking's units, filters by the booking's product. `UNIQUE NULLS NOT DISTINCT` limits a row
  to one pool contribution. It needs PostgreSQL 15,
  the minimum the application enforces (`PostgresDialect::MINIMUM_MAJOR_VERSION`); the
  feature is in the [PostgreSQL 15 release notes](https://www.postgresql.org/docs/15/release-15.html).
- **Self-allocation.** An addition booking has an allocation to its own lot. Its amount is the
  lot's original quantity.
- **Deletion.** Deleting a row deletes its contributions. Deleting a `stock_log` row deletes
  every contribution and allocation that names it. Cascading deletes are not deferred
  ([CREATE TABLE](https://www.postgresql.org/docs/16/sql-createtable.html)), so a product
  removal that deletes `stock` and `stock_log` leaves no orphan; the spike confirmed both
  cascades. The generic
  entity API cannot delete either table (`ExposedEntityNoDelete` lists `stock` and
  `stock_log`), so the only deleter of a `stock_log` row is product removal.
- **Retention.** Allocations are kept as long as their booking. Contributions describe
  current stock only.
- **Indexes.** One on each table by `lot_id` supports "where is this lot" and the dependency
  check. The unique constraints index the row-side and booking-side lookups.
- **Not in the schema.** `stock_log` gains no column, and `stock_entry_origins` is unchanged
  and keeps feeding the price views. A merge is not a booking.

The contribution table is a materialized current state. The allocation table is the record.
For lots with a `recorded` or `derived` basis, the sum of a lot's live signed allocations
equals the sum of its contributions (invariant I3 below), so a divergence is detectable.
Which row holds a lot is state, not recorded history, because a merge is not a booking.

### 3. Invariants and precision

Tolerance is ADR-0032's: `tol(a, b) = max(1e-9, 1e-12 * max(|a|, |b|))`. Amounts stay
`DOUBLE PRECISION` and are stored unrounded. A contribution that falls within tolerance of zero
is deleted.

- **I1.** For every `stock` row with `amount > 0`, the sum of its contributions equals
  `stock.amount` within tolerance.
- **I2.** For every booking with allocations, the sum of its allocations equals
  `stock_log.amount` within tolerance. For an edit's old and new bookings the allocations are
  snapshots of the row's contributions before and after, so each sums to that booking's
  amount.
- **I3.** For every lot with `recorded` or `derived` basis, the sum over live bookings of
  its allocations, counting purchase, self-production, inventory, consume, transfer and
  `stock-edit-new` as written, `stock-edit-old` negated, and opening and measurement as zero,
  equals the sum of its contributions.
- **I4.** The existing ledger balance holds: the sum of `stock.amount` per product equals the
  live signed ledger sum, as `CrossOperationLedgerInvariantTest` already asserts.
- **Measured containers.** A row with `opened_amount` has `amount = 1` (the `0275` CHECK) and
  never merges. Its contribution is one lot, or the pool, with amount 1. A fractional
  consume or transfer of it stays refused as today.
- **Unit conversion.** Any statement that multiplies `stock.amount` and `stock_log.amount` by
  a factor must multiply both new tables' `amount` by the same factor in the same
  transaction. Two sites do: `StockService::MergeProducts()` and
  `trg_cascade_change_qu_id_stock` (`0294`). Neither new table has a product column, so the
  product move needs no other change.

I1 is enforced by the writers and by tests, not by a database constraint. A deferred
constraint trigger on `stock` would make an older application image, which does not write
contributions, fail every stock update during a rolling deployment or rollback. See
Migration.

### 4. Allocation policy

Attribution is **accounting, not physical provenance**: the units of a merged row are
interchangeable on the shelf, and the policy decides on paper which lot a removal came from.
The policy is deterministic and depends only on database state:

1. Rows are chosen as today (`stock_next_use` order, unchanged).
2. Within a row, units are taken from contributions in ascending `lot_id`, with the pool
   first. This is first in, first out by addition booking, which matches the consume rule's
   stated intent ("then first in first out").
3. A removal (consume, inventory-down, weigh-down, edit-down) records one allocation per lot
   touched, negative, and reduces those contributions.
4. A split (open remainder, transfer) gives the opened or transferred portion the earliest
   lots and leaves the rest on the source row.
5. A whole-row operation carries every contribution unchanged.
6. An edit that raises a row's amount creates a lot: the `stock-edit-new` booking. Nothing is
   attributed to a lot that did not add the units.

### 5. Writers

Every writer runs under the product advisory lock and, before using a row's contributions,
checks I1 for that row. A row whose contributions are missing or do not add up (written by an
older image, or by a legacy merge) is reclassified by the backfill rules for that product,
and never by guessing. A row that still cannot be proven becomes one pool contribution, and
every lot whose contribution that removes has its allocations set to basis `unknown`, so I3
stays true and the lot's addition can no longer be undone.

| Operation | Contribution change | Allocations written |
|---|---|---|
| `AddProduct()`, positive `InventoryProduct()`, upward `WeighLocation()` | New row, one contribution to the new lot. | Self-allocation. |
| `ConsumeProduct()`, downward inventory and weighing | FIFO removal; a fully emptied row is deleted. | One booking per touched row, one negative allocation per lot. |
| `OpenProduct()` | Whole row: unchanged. Split: opened portion keeps the row id with the earliest lots; the remainder row gets a new tag and the rest. | Positive allocations for the opened lots. |
| `TransferProduct()` | Whole row: unchanged. Split: the new row (same tag) gets the earliest lots. | Negative on the source booking, positive on the destination booking. |
| `EditStockEntry()` | Down: FIFO removal. Up: a new lot, the `stock-edit-new` booking. | Old booking: snapshot before. New booking: snapshot after. |
| `MeasureStockEntry()` | Unchanged. | Snapshots on both bookings. |
| Maintenance merge | Section 6. | None. |

### 6. Merge

The command keeps its eligibility view, its product lock and its ascending row locks. Inside
one transaction per product it re-reads eligibility after the locks, then for each group:

1. Keep the row with `MAX(id)` as the survivor, as today.
2. Move every other member's contributions onto the survivor, adding amounts where the
   survivor already holds the same lot (`ON CONFLICT (stock_row_id, lot_id) DO UPDATE`,
   atomic per [INSERT](https://www.postgresql.org/docs/16/sql-insert.html)).
3. Delete the other rows; their contributions go with them.
4. Set the survivor's amount to the sum of its contributions.

A merge writes no `stock_log` column, no `stock_entry_origins` row and no booking. Because
nothing is rewritten:

- **Both survivors.** The surviving row is `MAX(id)`. The survivor keeps its own tag. Which
  purchase's row or tag survives has no effect on attribution, because contributions are keyed
  by lot. Spike runs cover both purchase orders.
- **Repeated merges** only add contributions. A second run over unchanged data finds no
  group and changes nothing.
- **Shared tags.** The shared-tag skip rule exists only because the old merge rewrote every
  row with a tag. It has no purpose here and is removed, as are the lineage-confinement
  guards. Rows sharing a tag may merge or not by the same eligibility rules as any other.
- **Lineage crossing the group.** `stock_entry_origins` links are not touched, so a link
  naming a group member's tag stays valid. Edits on a merged row log the survivor's tag, and
  the price views weight equal-priced lots identically; see Consequences.

### 7. Undo

An undo of booking Y succeeds only when all of the following hold, and otherwise changes
nothing.

1. **Tracked.** Y has allocations. A booking with none is *untracked* and takes the legacy
   rules in the existing code unchanged, including PR 531's refusals.
2. **Known.** None of Y's allocations has basis `unknown`.
3. **No dependent booking.** No live booking of the same product with a greater id, outside
   Y's own correlated set, has an allocation on any lot Y allocated, the pool included. This is today's
   "newest first per tag" rule applied per lot, so a later purchase of another lot no longer
   blocks an earlier one, and a consume that took units from a lot still blocks that lot's
   purchase.
4. **State.** The units Y touched are found by lot, never by `stock.id` or `stock.stock_id`,
   in the state Y produced, and total at least Y's recorded amounts:

| Booking | Reversal |
|---|---|
| Addition | The lot's contributions must total its original amount. They are removed from whatever rows hold them; a row left empty is deleted (and a live label on it retires, as today). |
| Consume, inventory-down | The recorded lots are re-created in a new row with the booking's snapshot attributes. A whole-take consume rebuilds under its own `stock.id` when free, as today. |
| Opening | The units are taken from open rows holding the lots. If one row holds exactly them it is un-opened in place. Otherwise they are extracted into a new un-opened row with the restored due date, and the rest stays. |
| Transfer pair | The units are taken from rows at the destination. A whole row relocates in place; otherwise they go to the source row, or to a row rebuilt there. |
| Edit pair, measurement pair | The row is restored from the old snapshot in place if it holds exactly the new snapshot. Otherwise the edit's lots are extracted into a new row with the old attributes. |

5. **Atomic.** The whole undo runs in one transaction under the product lock. A refusal
   leaves `stock`, `stock_log`, both new tables and every label as they were, and names the
   rule that failed. There is no partial undo of a booking: a lot partly consumed by a later
   live booking is rule 3, a refusal, not a smaller reversal.
6. **Transactions and correlation.** `UndoTransaction()` locks every product touched in
   ascending order, then undoes the live bookings newest first, so a later booking of the
   same transaction is already undone when an earlier one is checked. One refusal rolls back
   the whole transaction. A correlated pair undoes as one set.
7. **Repeat and retry.** A second request for an undone booking or transaction is refused
   with the existing message and changes nothing. After a refusal or a failure the
   transaction has rolled back, so the same request can be retried with no residue.

Extraction creates a row with a new `stock.id` and the booking's recorded tag. It does not
revive a label: a live label names the row it was issued on, and a retired label has no
target.

### 8. History

A lot's history is answerable from the two tables and the ledger:

```sql
-- original, movements, remaining and reversals of the lot created by booking :lot
SELECT 'original' AS part, sl.id, sl.transaction_type, bl.amount, sl.undone
  FROM stock_booking_lots bl JOIN stock_log sl ON sl.id = bl.booking_id
 WHERE bl.lot_id = :lot AND bl.booking_id = :lot
UNION ALL
SELECT 'movement', sl.id, sl.transaction_type, bl.amount, sl.undone
  FROM stock_booking_lots bl JOIN stock_log sl ON sl.id = bl.booking_id
 WHERE bl.lot_id = :lot AND bl.booking_id <> :lot
UNION ALL
SELECT 'remaining', rl.stock_row_id, NULL, rl.amount, NULL
  FROM stock_row_lots rl WHERE rl.lot_id = :lot
ORDER BY 1, 2;
```

The existing endpoints and the stock journal are unchanged (Compatibility). Exposing lots on
the wire or in the journal is a separate decision (question 3).

### 9. Locking

No lock object is added and no order changes. The order is the one in
[`docs/data-model.md`](../data-model.md#concurrency-the-stock-advisory-lock) and ADR-0033
decision 3: product advisory locks in ascending id order, then, in label code, the import
lock, then `stock` row locks in ascending id, then `labels`, then `print_jobs`.

- Both new tables are written only by `StockService` writers, the maintenance command and
  the backfill, always under the product lock, and only on rows those callers have locked or
  created.
- The label subsystem never reads or writes them, so label issuance, which takes no product
  lock, cannot form a cycle with maintenance through them.
- Maintenance does not take the import lock. An issuance in flight holds the import lock and
  the row lock; maintenance waits for that row, then re-reads eligibility and finds the label.
  An issuance that reaches a row lock maintenance holds waits, finds the row deleted, and
  fails without inserting a label. The spike exercised both orders with two connections.
- Concurrent bookings of the same product wait on the product lock; the spike measured a
  consume blocked for the full period maintenance held its locks.
- The backfill runs under the migration lock; its lazy form runs under the product lock.

Row locks are held to transaction end and deadlocks are avoided by a consistent order
([Explicit Locking](https://www.postgresql.org/docs/16/explicit-locking.html)); this design
adds no new order to keep consistent.

### 10. Labels

Physical labels are unchanged. A `stock_entry` label names a `stock.id`, never a lot, and
keeps its opaque `vctl:` uid. A labelled row is not a merge candidate. A merged row can be
labelled afterwards. Restoring a row under its old id, or extracting a row, does not revive a
label, because retirement set `target_id` to NULL. Label revival after undo stays the open
question 1 of ADR-0033, unanswered here.
[ADR-0037](0037-an-undo-of-a-whole-row-consumption-revives-the-stock-entry-label-it-retired.md)
(Proposed) proposes an answer and does not depend on this record.

### 11. Compatibility

| Area | Effect |
|---|---|
| Wire contract | **No change.** `stock_row_id`, `stock_id`, `transaction_id` and `correlation_id` keep their types and meanings on every route in the committed contract snapshots. The new tables are not in the exposed-entity enum. Refusal text changes; status codes do not. |
| `stock_log.stock_id` | No longer rewritten. A merged booking keeps the tag it had. Clients that grouped bookings by tag after a merge see them separate. |
| `stock.stock_id` of a survivor | The survivor's own tag, not `MIN(tag)`. A request that names the tag of a merged-away row gets "not found", as it does today for every tag but the minimum. |
| History UI, stock journal | Unchanged. |
| Importer | Both tables are derived state like `stock_entry_origins`: truncated by an import, rebuilt by the backfill after it, and checked by `AssertDerivedStateIsEmpty()`. A SQLite source cannot carry them. |
| Permissions and prices | No new permission. Allocations hold quantities only, no price. Any later exposure joining a booking's price must pass `FieldPolicy` (`STOCK_PRICES_VIEW`). |
| MCP and other clients | The six read tools use `GET /api/stock` and objects; none read `stock_log`. No impact. |
| Product merge, unit change | Section 3: rescale both tables in the same statements. |

## Migration and legacy data

**A migration is required**: two new tables and a backfill. It is PostgreSQL-only
(migrations above 0265 are). Its number is not reserved here. At the research date
`migrations/RESERVATIONS.md` names 0303 as the next unclaimed number; the implementing change
must claim a number in that file first, because `check-migrations.php` fails when disk and
table disagree.

**Ordering.** The migration job creates the tables and runs the backfill before the new
image serves. New writers, the contribution-moving merge and the lineage-aware undo ship in
the same release.

**Three kinds of data.**

| Kind | Source | Representation |
|---|---|---|
| New bookings | Written by the new writers. | `recorded` allocations and contributions. |
| Reconstructible legacy | Proven by ledger arithmetic. | `derived`. |
| Ambiguous legacy | Merged or imported history that arithmetic cannot separate. | `unknown`: pool contributions, and untracked or `unknown` bookings. |

**Backfill classification.** Rows and bookings are grouped into *families*: a tag plus the
tags `stock_entry_origins` links to it. For each family the backfill compares the sum of the
live signed ledger with the sum of its rows.

- **E (exact).** Exactly one live addition and the two sums agree. Every row is a contribution
  of that lot, and every booking of the family, live or undone, is allocated to it. This covers
  never-merged purchases, splits with a recorded origin, and transfer splits.
- **X (exact by arithmetic).** Two or more live additions, no other live booking, one row, and
  the additions sum to the row. The row holds each addition's amount. Only the additions are
  allocated.
- **U (unknown).** Everything else: merged groups with later consumes or edits, splits with
  no recorded origin, and families whose sums disagree. Each row becomes one pool
  contribution. Each live addition gets a self-allocation with basis `unknown`. Other bookings
  stay untracked.
- **N.** Nothing live and no rows (a fully undone purchase). Nothing is written.

No allocation is invented. A group that arithmetic cannot separate is `unknown`, never a
reconstruction called exact.

**Validation.** After the backfill, I1 must hold for every row with `amount > 0` and I2 for
every tracked booking, or the migration fails and rolls back. `stock`, `stock_log`,
`stock_entry_origins` and `labels` are not written: totals, history and labels are preserved
by construction, and the spike confirmed it by hashing them before and after.

**Interruption and restart.** The backfill is idempotent: it writes only rows lacking
contributions and bookings lacking allocations, so a rerun after a failure or a second
deployment changes nothing. Measured: 4.3 s for 100,000 bookings and 7.6 s for 200,000 on the
spike machine, scaling roughly linearly.

**Writer transition and recovery.** The tables are additive, so rolling the image back leaves
them unused. During a rolling deployment or after a rollback, an older image can create rows
or merge without writing contributions. The next new writer that touches such a row finds I1
violated and reclassifies the row by the rules above. The result can be `unknown`, never a
false attribution. There is no down migration; a decision to reverse this record would drop
the tables in a later migration.

**What is possible on `unknown` data.** Consume, open, transfer, edit, inventory and weighing
all work: removals draw from the pool first and record the draw, and their undo returns the
units to the pool exactly. The legacy additions of an `unknown` family cannot be undone; the
refusal says why. Untracked legacy bookings keep the existing rules. Legacy rows may take part
in future merges: the pool is a contribution like any other and stays a pool.

## Worked examples

All quantities below were produced by running the rules in the spike's reference model
([`ref-model.php`](../../.spike-adr36/ref-model.php)) against the real schema, with I1 to I4
checked after every step; the full output is in
[`evidence/model.json`](../../.spike-adr36/evidence/model.json). "Baseline" results come from
the real `StockService` at `a7bf78a3`. A purchase is written `A`, `B`, `C` in booking order;
`A:3` means lot A, amount 3. Row ids and tags are illustrative, since the runs share one
schema.

### 1. Purchases of 3 and 2 merged, both orders

| Order | Before the merge | After the merge | `stock_log` |
|---|---|---|---|
| 3 then 2 | row 18 (tag `s1`) = 3 `A:3`; row 19 (`s2`) = 2 `B:2` | row 19 (`s2`) = 5 `A:3 + B:2` | Both bookings keep their tags and allocations. |
| 2 then 3 | row 26 (`s9`) = 2 `A:2`; row 27 (`s10`) = 3 `B:3` | row 27 (`s10`) = 5 `A:2 + B:3` | Unchanged. |

Baseline for 3 then 2: row 2 (the purchase of 2) with tag `a` (the purchase of 3), and both
bookings rewritten to tag `a`.

### 2. Partial consumption, then undo of either purchase

| Case (3 then 2) | State | Result |
|---|---|---|
| Consume 2 | row = 3 `A:1 + B:2`; consume −2 allocated `A:−2` | Undo A: refused (booking 26 touched lot A). Undo B: **accepted**, row = 1 `A:1`. Baseline: both refused. |
| Consume 4 | row = 1 `B:1`; consume −4 allocated `A:−3, B:−1` | Undo A: refused. Undo B: refused. Undo the consume: accepted. Then undo B and A: accepted. Baseline: B refused after the consume undo. |
| 2 then 3, consume 2 | row = 3 `B:3`; consume allocated `A:−2` | Undo A refused, undo B accepted. |
| 2 then 3, consume 4 | row = 1 `B:1`; consume allocated `A:−2, B:−2` | Both refused until the consume is undone. |

Refusals leave `stock`, `stock_log` and both new tables byte-identical to before the attempt
(the probe compares the state).

### 3. Merge, transfer, merge again, reversal

Purchases A=3 and B=2 merged into one row of 5. Transfer 2 to L2 (a split): L1 row = 3
`A:1 + B:2`, L2 row = 2 `A:2`, both with tag `s18`; the transfer bookings allocate `A:−2` and
`A:+2`. Purchase C=4, then a second maintenance run: row = 7 `A:1 + B:2 + C:4`; the L2 row is
untouched. The baseline skips this group because of the shared tag. Undo the transfer:
accepted; the L1 source row returns under its own id with `A:2`. A third run merges everything
into one row = 9 `A:3 + B:2 + C:4`. Undo purchase A: accepted, row = 6 `B:2 + C:4`.

### 4. Opening and editing, then merge, then reversal

| Sequence | After the merge | Reversal |
|---|---|---|
| Open 2 and open 3 as two whole rows, then merge | One open row = 5 `A:2 + B:3`. | Undo the second opening: accepted; row = 2 open `A:2` plus a new un-opened row = 3 `B:3`. Undo the first: accepted. Baseline: both refused. |
| Merge 2 + 3, open 2 (split), purchase C=4, merge | Open row `A:2`; remainder + C merge to 7 `B:3 + C:4`. Baseline: no merge. | Undo the opening: accepted in place; `B:3 + C:4` untouched. |
| Edit B's price 1.2 to 1.0 so B matches A, merge | One row = 5 `A:3 + B:2`, price 1.0. | Undo the edit: accepted; row = 3 `A:3` at 1.0 and a new row = 2 `B:2` at **1.2**. |
| Edit B's amount 2 to 2.5 (new lot E=0.5), merge | One row = 5.5 `A:3 + B:2 + E:0.5`. | Undo the edit: accepted; `A:3` stays, `B:2` returns on its own row, E is removed. |

### 5. Fully consumed stock

Merged 3 + 2, then consume 5: the row is deleted; the consume allocates `A:−3, B:−2`. Undo:
accepted; the row returns under its old id with `A:3 + B:2`.

### 6. Ambiguous legacy history

Fixtures were built with the real service before the tables existed (old-style merge, which
rewrites tags), then backfilled. Merged 3 + 2 with a later consume of 1: class U, row = 4
`pool:4`, additions `unknown`, the consume untracked. Undoing either purchase: **refused**,
state unchanged. A new consume of 1 draws `pool:−1`; its undo is accepted and returns the unit
to the pool. A new purchase C=4 merges with the row: 8 `pool:4 + C:4`; undoing C is accepted
and leaves `pool:4`. Merged 3 + 2 with nothing else: class X, row = 5 `A:3 + B:2` derived;
undoing the older purchase is accepted and leaves `B:2`.

### 7. Concurrent booking and label issuance during maintenance

| Case | Result |
|---|---|
| Consume issued while maintenance holds its product and row locks | The consume waited 1.5 s, then ran against the merged row (A:2 + B:2). |
| Label issuance (import lock, then row lock) on a row maintenance deletes | Issuance waited, found the row gone, inserted no label (0 live labels). |
| Label committed before maintenance re-reads eligibility | The labelled row stayed out of the group; 2 rows remained. |
| Maintenance asks for row locks while an issuance holds them | Maintenance waited about 1 s, re-read, merged nothing, and the label is live. |

### 8. Interrupted maintenance and a repeat run

Maintenance interrupted after its first group: the state was byte-identical to before (hash
of `stock`, `stock_log`, `stock_entry_origins`). A complete run merged 2 groups; a second run
merged 0 and changed nothing.

## Consequences

- **Attribution becomes recorded.** History, undo and refusal text can name the lot involved.
  Merges stop destroying information: `stock_log.stock_id`, `stock_entry_origins` and the
  surviving tag are no longer rewritten.
- **Undo accepts and refuses on different grounds.** It now accepts reversals the old rule
  refused: a purchase whose units were not consumed, a later purchase after consumption
  attributed to an earlier lot, an opening or edit of one lot inside a merged row. It still
  refuses reversals whose lots a later live booking touched. Because attribution is FIFO by
  addition booking, which lot a consume is charged to is a policy choice (question 2). Existing
  tests that encode the old per-tag rule change deliberately (see Acceptance prerequisites).
- **More merges happen.** Removing the shared-tag and lineage guards lets groups the baseline
  skipped merge. That raises row consolidation, which this research could not show is needed.
- **Storage grows by about one allocation per booking and one contribution per row.** Measured
  on a synthetic ledger of 100,114 bookings and 40,047 rows
  ([evidence](../../.spike-adr36/evidence/model.json)): the two tables took 20.0 MB
  (5.6 MB contributions, 14.4 MB allocations) against 26.7 MB for `stock_log` with its
  indexes, about 144 bytes per booking for allocations alone. That is an upper bound for the
  synthetic shape: each of its bookings has exactly one allocation. A household recording
  20 bookings a day would add under 2 MB a year at that rate. The figure is not a measurement
  of the maintainer's data.
- **Query cost is index lookups.** Dependency check, "where is lot", "lots of row" and
  "allocations of booking" each ran in under 0.02 ms on that ledger, comparable to the existing
  per-tag dependency query (0.016 ms). The backfill took 4.3 s for 100,114 bookings.
- **Average-price views are unchanged for equal-priced lots.** `stock_edited_entries` and
  `products_average_price` key on `stock_log.stock_id`, and edits on a merged row log the
  survivor's tag, so an edit lowers one lot's origin amount in those views by the whole
  change. Merge groups share one price, so the weighted average cannot change. The spike
  compared a merged row edited down to 4, 2 and 1 units against an unmerged control: 2.2,
  2.3333 and 2.5 in both. This holds only for equal-priced groups, which eligibility
  guarantees; it is an acceptance gate.
- **A stale pre-merge tag is not resolvable.** As today, a request naming the tag of a
  merged-away row finds nothing. Lot lookup could resolve it later; this record does not.
- **ADR-0033 interplay.** If both records are accepted, this one supersedes in part ADR-0033
  decision 3's shared-tag and lineage-confinement skip rules and its statement that a merge
  changes bookings and lineage. ADR-0033 decisions 1, 2, 3's eligibility, 4 and 5 are
  unchanged. This record does not edit those decisions.

## Options considered

| Criterion | A. Lots with contributions and allocations (chosen) | B. Immutable movements, contributions replayed | C. Stop merging, keep separate rows | D. Stable row ids or merge aliases only |
|---|---|---|---|---|
| Attributes quantity per booking | Yes, recorded. | Yes, by replay, but the policy must still be applied and kept stable forever. | Yes for new data: rows never combine. | No. |
| Legacy data | Classified, `unknown` kept explicit. | A replay cannot start from rewritten history. | Unaffected. | Unaffected, and unsolved. |
| Migration | Two tables and a backfill. | A movement table and replay logic. | None. | None, or a tombstone table. |
| Service complexity | Writers and undo gain lot handling. | Highest: every read replays. | Lowest. | Low, but the core problem stays. |
| Storage | About 144 bytes per booking measured. | Similar to A. | None. | A row per merge. |
| Query cost | Index lookups, under 0.02 ms measured. | Grows with a lot's movement count. | None. | Low. |
| Concurrency | Under the existing product lock. | Same. | None added. | Same. |
| Maintainer direction | Keeps the maintenance merge. | Keeps it. | Reverses it. | Keeps it. |
| Operations | Backfill once; invariant tests. | A replay repair job. | None. | None. |

**B** is A without the stored state. Per-lot totals are reproducible from allocations (I3),
but which row holds a lot is state that a merge, not being a booking, never records. B would
replay to find it on every read and still need the same allocation policy.

**C** removes the cause. It is the simplest correct design for new data and needs no
migration. It conflicts with the direction recorded on issue 488 (merging becomes an explicit
maintenance command) and ADR-0033 decision 2. Its cost is more rows. This research found no
measurement showing consolidation matters, and none showing it does not. C also leaves
unambiguous-split refusals (a purchase split across rows by a transfer) unsolved, which A
resolves with the same tables.

**D** fails on the example in the Context: a stable id or alias identifies where units are,
not how many belong to which booking.

**Extending the origin model** was evaluated. `stock_entry_origins` maps one tag to one
origin tag and has no amounts. A merged row has many origins, and the table is keyed by the
tags the old merge rewrote. Adding an amount column would put quantity in a table that three
views read for price weighting. It stays as it is, and contributions are keyed by booking id.

## Acceptance prerequisites

Each prerequisite is a gate. The accepting pull request states how it was met.

1. **Questions 1 and 2 answered by the maintainer**, and any answer that changes the
   allocation policy reflected in this record before acceptance.
2. **Migration number claimed** in `migrations/RESERVATIONS.md` before the migration file
   exists, and the migration passes `check-migrations.php` with a PostgreSQL-only file.
3. **Backfill fixtures (tier 1).** A PHPUnit class built on `PgsqlSchemaTestCase` reproduces
   families E, X, U and N with legacy states created by the real `StockService`, as the spike
   does. Totals, `stock`, `stock_log`, `stock_entry_origins` and labels are byte-identical
   before and after. A second run writes nothing, and I1 and I2 hold. A deliberately
   corrupted family makes the migration fail and roll back.
4. **Every writer.** `AddProduct`, `ConsumeProduct`, `OpenProduct`, `TransferProduct`,
   `EditStockEntry`, `MeasureStockEntry`, `InventoryProduct`, `WeighLocation` and recipe
   consumption write allocations and contributions. `CrossOperationLedgerInvariantTest`
   asserts I1 to I3 after every step for its existing seed and at least two further seeds.
5. **Worked examples as tests.** Examples 1 to 6 are PHPUnit cases, each purchase order
   included, asserting stock quantities, contributions, allocations, `stock_log` and
   `undone` flags after every accepted step and byte-identical state after every refusal.
6. **Merge.** The maintenance merge moves contributions and writes no `stock_log` column and
   no `stock_entry_origins` row. Interrupted-run rollback and repeat-run idempotence are tested
   as in `StockMaintenanceCompactionTest`. The two skip guards are removed with a test that
   a transfer-split group and an open-split group now merge.
7. **Concurrency (tier 1, two real connections).** Example 7's four cases, plus a deadlock
   check for issuance in flight against maintenance in both interleavings, reusing the
   subprocess helpers in `tests/Pgsql`.
8. **Unit conversion.** `MergeProducts()` with factor 1 and not 1, and a change of
   `qu_id_stock`, leave I1 to I3 true. A pgTAP file covers `trg_cascade_change_qu_id_stock`'s
   rescale of both tables, and `.devtools/pgtap/README.md` lists the new objects.
9. **Importer.** `ImporterIntegrityTest` or a sibling shows an import truncates both tables
   and rebuilds them by the backfill, and refuses a non-forced import into a non-empty target.
10. **Wire contract unchanged.** `tests/Pgsql/snapshots/contract-admin.json` and
    `contract-restricted.json` and `victual.openapi.json` are byte-identical after the
    implementation, or the change carries its own ADR under ADR-0005.
11. **Average price.** A test repeats the spike's comparison: a merged row edited down against
    an unmerged control gives the same `products_average_price`.
12. **Existing regression coverage preserved.** The following stay green unchanged unless
    named below: `StockMaintenanceCompactionTest`,
    `StockMaintenanceLineageConfinementKeptIdTest`, `StockMaintenanceCommandPrivilegesTest`,
    `StockUndoIntegrityTest`, `StockConcurrencyTest`, the compaction, undo and transfer cases
    of `StockCoverageTest`, `MergeProductsTest`, `UndoMeasuredContainerCoherenceTest` and the
    `UndoSequence*` and `UndoStolenRowInsertTest` classes. Assertions that encode the old
    per-tag rule (for example refusal of the earlier of two merged purchases, or of an edit
    after a merge) and the two guard tests change in the implementing pull request, each listed
    with the reason. Any other changed assertion is a regression.
13. **Coverage.** The suite's `report.php --min=96.31198844487241217394` ratchet does not
    fall. A new file reaches at least 75% line coverage, and every file the change touches ends
    at or above its prior figure and at least 75%. The pull request's Verification section
    reports the aggregate figure and the files below the floor.
14. **Browser probes.** None added, because no page changes. The existing `undo-toasts.js`
    probe stays green.
15. **Deployment.** The maintenance command's CronJob (ADR-0033 prerequisite 3) is declared
    and checked by `.devtools/ci/check_deploy_manifest.py` before this schema ships, or the
    release states that merging is not scheduled.

Implementation and verification are separate changes. The acceptance pull request links their
evidence and carries only lifecycle bookkeeping.

## Open questions

No maintainer responses are recorded yet. An answer goes directly below its question as a
`> **Response:**` block.

1. **Should merging stay?** Option C deletes the cause and the migration. It reverses the
   maintainer's direction on issue 488, and the research found no measurement that decides the
   value of consolidation. Recommended: keep merging, because the direction is recorded, the
   same tables also remove the existing "split across multiple rows" refusals, and the cost is
   measured above. If the answer is to stop merging, the legacy classification and `unknown`
   handling remain needed only for the already-merged rows, and a smaller record can replace
   this one.
2. **Is FIFO by addition booking the attribution policy?** It makes a consume charge the
   oldest lot first, so a later purchase can be undone while earlier units were consumed. The
   alternatives (newest lot first, proportional) give different undo outcomes: newest-first
   would refuse the later purchase in the same case, and proportional needs fractional
   contributions. Recommended: FIFO, because it matches the consume rule's stated order and
   gives whole-number results for whole-number bookings.
3. **Should lots be shown to users?** This record changes no response and no page. A lot
   history field or a journal column is an additive wire change under ADR-0005 and needs its
   own decision. Recommended: defer until the contributions exist and a real need appears.
4. **Should an operator be able to attest an `unknown` family?** The record refuses to
   guess. A command that lets the owner assert a split of a merged legacy row would turn
   `unknown` into an operator-asserted basis. Recommended: not now; add a fourth basis value
   only if legacy refusals prove a nuisance.
