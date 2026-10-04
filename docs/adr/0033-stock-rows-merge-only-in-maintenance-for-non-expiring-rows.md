# ADR-0033: Stock rows are merged only by a maintenance routine, and only when they never expire

- **Status:** Proposed. Decider's answers recorded 2026-10-04 (below). Both open questions
  are resolved, and the decider's half of acceptance prerequisite 4 is met.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-26, from the maintainer's decision in the issue
  [487](https://github.com/datagen24/victual/issues/487) remediation session the same day.
- **Referenced by:** [issue 488](https://github.com/datagen24/victual/issues/488) (C1),
  [issue 491](https://github.com/datagen24/victual/issues/491) (H2); relies on
  [PR #531](https://github.com/datagen24/victual/pull/531) (open, unmerged at
  2026-09-27, head `e60a5d22`; merged 2026-09-27 as `d48e5b30`) remaining the safety net
  named in decision 4;
  [ADR-0010](0010-workload-standard.md), whose workload standard the new maintenance
  routine must meet.

## Decider's answers (2026-10-04)

The decider answered the record's remaining decision points in an interview on 2026-10-04.

| Question | Answer |
|---|---|
| Decision 2: how the merge command runs | **A CronJob in `deploy/`**, with its own least-privilege database role under ADR-0010. Prerequisite 3 stands. Running the command only by hand was rejected: merging would then almost never happen, which is close to the "drop merging entirely" alternative. |
| Decision 5: weighing corrects the location's total instead of merging rows | **Confirmed.** |
| Open question 2: metadata for the row a higher reading adds | **As built in PR #580.** The operator must supply `best_before_date`, and the request is refused without it. Price is the product's last price, the shopping location is the last one used, and the purchase date is today. |
| Open question 1: does undoing a full consume bring back the label it retired? | **Yes, but only the label that this consume retired.** This is a new decision 6. |

## Context

[`StockService::CompactStockEntries()`](../../services/StockService.php) runs inline,
inside the same transaction, from three methods in that service: `AddProduct()`,
`EditStockEntry()`, and `WeighLocation()`.

It merges every group of stock rows equal in every grouping column of the `stock_splits`
view (`migrations/0275.pgsql.sql:175`):

```sql
WHERE s.stock_id NOT LIKE 'x%'
	AND s.opened_amount IS NULL
	AND NOT EXISTS(SELECT 1 FROM userfield_values WHERE object_id = s.stock_id AND …)
GROUP BY s.product_id, s.best_before_date, s.purchased_date, s.price, s.open,
	s.opened_date, s.location_id, s.shopping_location_id, COALESCE(s.note, '')
HAVING COUNT(*) > 1
```

(`migrations/0275.pgsql.sql:193-202`). No later PostgreSQL migration redefines this view —
the only other files matching `stock_splits` are the frozen SQLite-era migrations 0143,
0156 and 0178, which do not run under [ADR-0008](0008-postgresql-only-runtime-engine.md).
`CompactStockEntries()` keeps `MAX(s.id)` as the surviving row and writes the group's
`total_amount` onto it. The method deletes the other rows by `id`, rewrites `stock` and
`stock_log` references from each replaced `stock_id` to `stock_id_to_keep`, and updates
`stock_entry_origins` lineage.

This produces two demonstrated defects, both reproduced against master
`edd7f91e3e654f8a06442810335efdebe4599c14` on 2026-09-26:

**#488 (C1, Critical).** Purchase 3 units due `2030-01-01` and 2 units due `2030-02-02` at
the same price, purchase date and location. Edit the second row's due date to
`2030-01-01`: `EditStockEntry()`'s call into `CompactStockEntries()` merges them into one
row of 5. Undo the edit transaction. The surviving row becomes 2, while both original
purchase bookings (3 + 2) still read as live in `stock_log` — 3 units are gone from a
ledger that records no consumption of them.

A second sequence reproduces the same shape through `OpenProduct()`: purchase 2 and 3
matching units, open 2 then 1 more, purchase 4 matching units, then undo the second open.
The opened total stays at 3, although the ledger says the one-unit opening was undone.
Reproduce with the API sequences above and compare `SUM(stock.amount)` and the live
`stock_log` rows before and after each undo.

**#491 (H2, High).** Issue a live label on a stock entry (the `labels` table,
`kind = 'stock_entry'`, per [ADR-0011](0011-label-namespace.md)), then purchase matching
stock so `AddProduct()`'s compaction merges the labelled row into another. The
`retire_stock_entry_labels` trigger (`migrations/0283.pgsql.php:87-99`,
`BEFORE DELETE ON stock`) fires on the deleted row and retires the label. This happens even
though the row's `stock_id` is not prefixed `x` and carries no `userfield_values` row:
`stock_splits`' three existing exclusions have no awareness of the separate `labels`
mapping table ADR-0011/[ADR-0021](0021-label-templates-are-application-data.md)
introduced, so a live label is not protected from being merged away.
`GetProductIdFromBarcode('vctl:…')` then refuses the retired identifier.

A full consume followed by undo separately recreates the stock row under a new `id`, while
the label, keyed to the old id, stays retired.

The maintainer suggested that merging likely keeps an upstream SQLite-descended database
tidy. That explanation is tentative; it does not establish a measured operational need
or a maintainer rejection of removing merging entirely.

## Decision

The maintainer's 2026-09-26 decision moves merging to an explicit maintenance command
and limits eligible rows. The CronJob deployment, locking and identity safeguards, and
location-total weighing behavior below are proposed refinements requiring acceptance.

1. **Remove the three inline calls.** `AddProduct()`, `EditStockEntry()` and
   `WeighLocation()` no longer call `CompactStockEntries()` as part of their own
   transactions.
2. **Merging becomes an explicit maintenance command.** A CronJob in `deploy/` is the
   scheduling mechanism under [ADR-0010](0010-workload-standard.md). **The decider
   confirmed this on 2026-10-04.**
   `deploy/k3s/label-workers.yaml` already declares two CronJobs; the maintenance job
   joins them and `.devtools/ci/check_deploy_manifest.py` checks its manifest.
3. **Only unlabelled rows that never expire may merge.** Eligibility requires
   `best_before_date IS NULL` or `best_before_date = '2999-12-31'`, the product sentinel
   used by `StockService::AddProduct()` and freezer transfers in `TransferProduct()`.
   Every other grouping column must match. Preserve the existing exclusions for
   per-unit `stock_id` values prefixed `x`, stock userfield values, and measured remainders.
   A live label means a `labels` row with `kind = 'stock_entry'`,
   `target_id = stock.id`, and `retired_at IS NULL`; such a stock row cannot merge.

   Within the product lock and one transaction, lock candidate stock rows with
   `SELECT ... FOR UPDATE` in ascending row-id order. After the locks are granted,
   re-read eligibility, including live labels, and recompute groups and totals before
   writing. Leave out rows that no longer qualify. Do not add a product-lock acquisition
   to label issuance: issuance holds the import lock before its row lock, while stock
   transfers can hold the product lock before requesting the import lock for reprinting.

   Skip a group when any of its `stock_id` values is held by a stock row outside that
   group. Check this under the product lock before any identity rewrite. A merge changes
   only selected rows and their associated bookings and lineage; it must leave excluded
   rows and their history unchanged. Skip a group if lineage or historical-booking ownership
   cannot be confined to it without changing an outside row's history.
4. **Atomic undo refusal covers both `STOCK_EDIT_OLD` and `PRODUCT_OPENED`.**
   [PR #531](https://github.com/datagen24/victual/pull/531), or an equivalent fix, must
   refuse an ambiguous reversal without changing stock or marking the booking undone.
   Its row-identity and contribution checks protect against a maintenance merge deleting
   or changing the target. This is a partial fix for #488, not the lot/lineage redesign
   that issue still requires. The merge never rewrites `stock_log.stock_row_id`.
5. **`WeighLocation()` corrects the location's total without merging its rows.** For the
   existing single-product vessel case, compare the converted net reading with the sum of
   that product's stock at the exact location. Lock and re-read that stock before deciding
   the difference, and commit the correction and its bookings in one transaction.
   A lower reading consumes the difference through the existing inventory-correction
   service path in ordinary consumption order, restricted to that location. A higher
   reading adds a separate positive inventory-correction row there. Existing rows retain
   their due dates and identities; ordinary full-consumption label retirement still applies.

   Do not copy an arbitrary existing lot's due date onto a positive correction. **The
   contract (decider, 2026-10-04, as built in PR #580):** a higher reading requires the
   request to carry `best_before_date`, and without it the request is refused with a
   message that says why. The new row takes the product's `last_price`, its
   `last_shopping_location_id` and today's purchase date. These are the defaults
   `InventoryProduct()` already uses for its own positive correction. A matching reading creates no booking. Multi-product locations
   remain refused. The implementation must preserve ordinary booking and undo behavior;
   weighing does not directly overwrite several rows or revive retired labels.

6. **Undoing a full consume brings back the label that consume retired, and no other.**
   (Decider, 2026-10-04, resolving open question 1.) Take a jar labelled `vctl:7Q2K` on
   stock row 41. Booking 900 consumes the row completely, so the row is deleted and
   `retire_stock_entry_labels` retires the label. Undoing booking 900 restores row 41 under
   its original id, as [PR #531](https://github.com/datagen24/victual/pull/531) does. Under
   this decision, `vctl:7Q2K` then resolves to row 41 again.

   A label is revived only when all of the following hold. The label was a `stock_entry`
   label retired by deleting that row, *as part of the booking being undone*. The undo
   restores the row under the same `id`. No other live label targets that row. To check the
   first condition, the label row must record which booking retired it. Recording that is
   part of implementing this decision; today's trigger stores only an `{id, name}` snapshot.

   Nothing else revives a label: not an import, not deleting a product or location, not
   the maintenance merge (which never merges a labelled row, under decision 3), and not an
   undo of a different booking that happens to put stock back. A row restored under a new
   `id` leaves the label retired.

   This keeps [ADR-0021](0021-label-templates-are-application-data.md)'s identity rule.
   A retired uid is never reattached to a reused id belonging to a different target. It is
   reattached only to the same row, by reversing the event that detached it. Outside that
   one reversal, a retired label stays retired, so a retired label seen in the world still
   signals a discrepancy, as [ADR-0011](0011-label-namespace.md) intends.

**Alternatives considered:**

- **Refusal alone, with inline merging kept.** Leaves the label-retirement hazard (#491) and
  the expiration-squashing risk live on every purchase, edit and weighing — the merge itself
  is the hazard, not only the undo that can follow it.
- **Split-back reversal** (an undo un-merges a row back into its pre-merge parts).
  Requires reconstructing which booking contributed which quantity and attributes after the
  merge already discarded that information — the same gap issue #487's "Corrections to the
  audit" item 5 names: "Stable row id is insufficient by itself. Repointing every booking to
  one merged row does not recover which booking contributed which quantity or attributes."
- **Dropping merging entirely.** This proposal retains an explicit maintenance command
  as the maintainer requested. Its rationale is that optional consolidation can reduce
  duplicate rows. No measurement establishes that benefit, and the maintainer's tentative
  explanation does not constitute a rejection of removing merging.

## Consequences

- Without decision 5, removing inline compaction would make multi-row vessels impossible
  to weigh whenever their rows have real due dates or live labels. Waiting for maintenance
  cannot resolve those cases. Correcting the location total removes that dependency and
  preserves separate lot dates. Identical dated purchases will never merge again.
- **A row's mergeability becomes visible and conditional** (due date and label state)
  rather than an unconditional side effect of every purchase, edit, and weighing. A row
  carrying a real expiration is never at risk of the id/lineage churn #488 and #491
  describe, closing the largest share of both findings' surface without changing
  `UndoBooking()` itself.
- **The underlying lineage gap is not solved.** The maintenance routine still performs the
  identity and lineage rewrites on eligible groups, so issue #487 Corrections item 5's
  finding stands: a stable row id does not by itself recover which booking contributed which
  quantity after a merge. This decision bounds the finding's surface to rows that can never
  expire and carry no label; it does not close it. [ADR-0036](0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)
  (Proposed) is the design for that gap. `stock_log.stock_row_id` stays
  unchanged, so bookings on deleted rows retain their original physical row ids.
  PR #531 can refuse edit/open undo when that identity is gone; consume undo can recreate
  a fully consumed row under its original id. Reusing an id does not by itself revive a
  label. Under decision 6, only an undo of the consume that retired the label does.
- Implementation status at 2026-10-03: [PR #580](https://github.com/datagen24/victual/pull/580)
  (merged 2026-09-28 as `0e3e61c1`) built decisions 1, 3 and 5, the maintenance command
  `bin/victual-compact-stock` and the narrowed `stock_splits` view (`migrations/0290.pgsql.sql`).
  No CronJob is declared (decision 2, acceptance prerequisite 3). Label revival
  (decision 6, added 2026-10-04) is not built. The record is still Proposed, and the
  accepting pull request has not yet stated how each prerequisite was met.

## Open questions

Both questions were resolved by the decider on 2026-10-04. Their text is kept so the
answers can be read against what was asked.

1. **#491's remaining label-revival question.** PR #531 at `e60a5d22` already restores
   a fully consumed row under its original id. The retirement trigger clears the label's
   `target_id`, so restoring the row alone leaves the label retired. Whether undo should
   revive that label remains undecided; this proposal does not add revival.
   **Resolved: yes, limited to the label that the undone consume retired. See decision 6.**
2. **Positive weighing correction metadata.** Decision 5 adds a row when the measured
   total increases. Which date and other purchase metadata does the operator supply, and
   which existing inventory defaults may apply? This must be specified before implementation
   and demonstrated before acceptance; an arbitrary existing lot cannot supply the answer.
   **Resolved: the operator supplies `best_before_date`. Last price, last shopping location
   and today's purchase date fill the rest, as built. See decision 5.**

## Acceptance prerequisites

1. The maintenance command passes real-PostgreSQL fixtures for #488 and #491. Dated,
   labelled, measured and userfield-bearing rows stay separate; eligible matching
   never-expiring rows merge. A group sharing a `stock_id` with an outside row is skipped.
   Force the protected row's shared id to sort after another candidate id, and verify
   its amount, `stock_id`, bookings, lineage and live label remain unchanged.
   Two-connection tests cover label issuance before candidate locks and issuance waiting
   behind maintenance. A label committed before the eligibility recheck protects its row;
   issuance after a committed deletion must fail without producing a live orphan label.
2. PR #531 or an equivalent fix is merged before inline compaction is removed. Demonstrate
   atomic refusal for both `STOCK_EDIT_OLD` and `PRODUCT_OPENED` after maintenance changes
   their target, in both surviving-row orders. Assert unchanged `stock_row_id` values,
   quantities, opened totals, and ledger state on refusal. Test interrupted maintenance
   rollback and a repeat run with no new eligible rows.
3. The proposed CronJob is declared and passes the manifest checks. Review separately
   demonstrates ADR-0010's statelessness, idempotence, dedicated least-privilege credential
   and database role, and deployment requirements; a manifest check alone proves neither
   idempotence nor appropriate privileges.
4. The decider confirms location-total weighing and resolves open question 2. **The
   decider's half is met (2026-10-04)**; the regression tests below are not yet shown. Regression
   tests weigh a vessel after two identical dated refills and repeat with a labelled row.
   Cover lower, higher and unchanged readings, exact-location isolation, rollback, and
   undo. Booking deltas must equal the correction; surviving rows keep their due dates
   and identities. Test full-consumption label retirement separately from merge exclusion.
   Remove all three inline calls only with this weighing behavior available.

5. Decision 6 is implemented with real-PostgreSQL tests:
   - Undoing the full consume that retired a label revives it, and the uid resolves to the
     restored row.
   - Each of the following leaves the label retired: undoing a different booking,
     restoring the row under a new id, a label retired by an import or by deleting a
     product, and a row that already has another live label.
   - A revived label can be retired again by a later full consume.

Implementation and verification belong in separate changes. The acceptance pull request
links their evidence and carries only the required lifecycle bookkeeping.
