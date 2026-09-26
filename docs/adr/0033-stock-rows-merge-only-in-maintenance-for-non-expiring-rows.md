# ADR-0033: Stock rows are merged only by a maintenance routine, and only when they never expire

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-26, from the maintainer's decision in the issue
  [487](https://github.com/datagen24/victual/issues/487) remediation session the same day.
- **Referenced by:** [issue 488](https://github.com/datagen24/victual/issues/488) (C1),
  [issue 491](https://github.com/datagen24/victual/issues/491) (H2); relies on
  [PR #531](https://github.com/datagen24/victual/pull/531) (draft, unmerged at
  2026-09-26) remaining the safety net named in decision 4;
  [ADR-0010](0010-workload-standard.md), whose workload standard the new maintenance
  routine must meet.

## Context

`StockService::CompactStockEntries()` (`services/StockService.php:3221`) runs inline, inside
the same transaction, from three call sites: `AddProduct()`
(`services/StockService.php:399`), `EditStockEntry()` (`services/StockService.php:904`), and
`WeighLocation()` (`services/StockService.php:2663`).

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
The merge keeps `MAX(s.id)` as the surviving row, sums the group's amounts onto it
(`services/StockService.php:3298`), deletes every other row in the group
(`services/StockService.php:3294`), and rewrites every `stock_log` row that pointed at a
deleted `stock_id` to the surviving one (`services/StockService.php:3261-3262`), along with
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

The maintainer's reasoning: merging exists to keep an upstream SQLite-descended database
tidy, is better done as maintenance than as a side effect of every write, and has been seen
to squash an expiration.

## Decision

The maintainer decided:

1. **Remove the three inline calls.** `AddProduct()`, `EditStockEntry()` and
   `WeighLocation()` no longer call `CompactStockEntries()` as part of their own
   transactions.
2. **Merging becomes an explicit maintenance command**, runnable from a CronJob declared in
   `deploy/` per the workload standard ([ADR-0010](0010-workload-standard.md)). No CronJob
   exists in `deploy/` today — `deploy/kind/roles-job.yaml` is a one-off `Job`, and
   `deploy/k3s/label-workers.yaml` declares a long-running `Deployment` — so this is the
   first CronJob in the tree, and its manifest is subject to
   `.devtools/ci/check_deploy_manifest.py` the same as every other declared workload.
3. **The routine merges only rows whose `best_before_date` is `NULL` or `2999-12-31`** —
   this fork's "never expires" sentinel, in use since `migrations/0033.sql` for
   `default_best_before_days = -1` and documented at
   `services/StockService.php:219` — **and that carry no live label**, in addition to
   today's three `stock_splits` exclusions (per-unit `stock_id` prefixed `x`, a
   `userfield_values` row, a measured remainder). Rows with a real due date are never
   merged, by the maintenance routine or anything else.
4. **[PR #531](https://github.com/datagen24/victual/pull/531)'s atomic undo refusal
   remains the safety net** for bookings on rows a maintenance merge has touched. As
   drafted, its `STOCK_EDIT_OLD` branch refuses an undo atomically — rather than silently
   destroying another contribution or misreporting — when the target row's current amount
   no longer matches what the correlated `STOCK_EDIT_NEW` booking recorded: the signature of
   a merge having touched the row since the edit. The PR's own description states this is an
   interim, partial fix for #488, not the lot/lineage redesign #488's checklist item 2 still
   asks for.

**Rejected:**

- **Refusal alone, with inline merging kept.** Leaves the label-retirement hazard (#491) and
  the expiration-squashing risk live on every purchase, edit and weighing — the merge itself
  is the hazard, not only the undo that can follow it.
- **Split-back reversal** (an undo un-merges a row back into its pre-merge parts).
  Requires reconstructing which booking contributed which quantity and attributes after the
  merge already discarded that information — the same gap issue #487's "Corrections to the
  audit" item 5 names: "Stable row id is insufficient by itself. Repointing every booking to
  one merged row does not recover which booking contributed which quantity or attributes."
- **Dropping merging entirely.** Rejected because the maintainer states it serves a real
  purpose — keeping an upstream SQLite-descended database tidy — and moving it to
  maintenance preserves that purpose while removing the transactional hazard.

## Consequences

- **`WeighLocation()` currently depends on compaction having just run.** Its own comment
  (`services/StockService.php:2657-2662`) says compaction runs first "so that ordinary
  backstock-fed refills … collapse into the one row this correction can set the amount of",
  and it then refuses unless exactly one row remains
  (`services/StockService.php:2666-2668`). Removing the inline call means a vessel fed by more
  than one uncompacted refill in the current session cannot be weighed until the maintenance
  routine has run — a new operational dependency this decision creates. The accepting pull
  request must state how an operator is told why a weighing was refused, or how the weighing
  path triggers (or waits for) compaction itself without reintroducing an inline call.
- **A row's mergeability becomes visible and conditional** (due date and label state)
  rather than an unconditional side effect of every purchase, edit, and weighing. A row
  carrying a real expiration is never at risk of the id/lineage churn #488 and #491
  describe, closing the largest share of both findings' surface without changing
  `UndoBooking()` itself.
- **The underlying lineage gap is not solved.** The maintenance routine still performs the
  same three-statement rewrite (`stock_id` repoint, `stock_log` repoint,
  `stock_entry_origins` repoint) as today's inline call, so issue #487 Corrections item 5's
  finding stands: a stable row id does not by itself recover which booking contributed which
  quantity after a merge. This decision bounds the finding's surface to rows that can never
  expire and carry no label; it does not close it.
- Nothing here is built: no maintenance command, no CronJob, no changed `stock_splits`
  predicate. This record constrains the design of that work; it does not describe code that
  exists.

## Open questions

1. **#491's other half.** A full consume followed by undo recreates the row under a new id
   while its label stays retired. Should undo reuse the original row id and revive the
   label, or should the label stay retired? Not decided here.

## Acceptance prerequisites

1. The maintenance command is demonstrated against fixtures matching #488's and #491's
   reproductions:
   - a row with a real due date is never merged;
   - a labelled row is never merged, even when every other `stock_splits` column matches;
   - a `NULL`/`2999-12-31` row with no label is merged exactly as `CompactStockEntries()`
     merges it today.
2. [PR #531](https://github.com/datagen24/victual/pull/531) or an equivalent fix is merged,
   so the `STOCK_EDIT_OLD` refusal this record relies on as a safety net (decision 4) is
   actually in force before the three inline calls are removed.
3. The CronJob is declared in `deploy/` and checked against the workload standard's four
   properties — stateless, idempotent, unprivileged, declared
   ([ADR-0010](0010-workload-standard.md)) — the way `.devtools/ci/check_deploy_manifest.py`
   checks every other declared workload.
4. The decider confirms `WeighLocation()`'s new operational dependency (Consequences) is
   acceptable, or the accepting pull request states how it is mitigated.
