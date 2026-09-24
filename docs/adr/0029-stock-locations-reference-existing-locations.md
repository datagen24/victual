# ADR-0029: Stock locations reference existing locations

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-09-23
- **Referenced by:** [issue 461](https://github.com/datagen24/victual/issues/461)

## Context

A non-null `stock.location_id` can name a deleted or nonexistent location. Such stock
still contributes to product totals but disappears from location-scoped reads. The
purchase check fixed by issue 246 protects one application path. Location deletion
currently checks for child locations but does not check for stock.

Inherited tables generally lack foreign keys. Fork-owned tables already use them,
including `product_location_min_stock.location_id`. This proposal adds one constraint
to an inherited table; it does not adopt proposed ADR-0009 or require a schema-wide
conversion. [ADR-0008](0008-postgresql-only-runtime-engine.md) permits PostgreSQL-only
migrations and keeps SQLite as an import format.

The [issue's recommendation](https://github.com/datagen24/victual/issues/461#issuecomment-5801971047)
explicitly leaves acceptance to the maintainer. The technical evidence below supports
this proposal without accepting it.

## Decision

1. Add `stock_location_id_fkey` from `stock.location_id` to `locations.id`, with
   `ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE`. Every non-null location
   stored in stock must exist. No cascade, automatic relocation, or stock deletion
   repairs a violation. Add an index on `stock(location_id)` for reference checks.

2. Preserve the nullable column. Existing default-location behavior stays in place:
   the stock insert trigger fills a null from the product's default location.
   A non-null default must pass the new constraint. An update to null remains valid;
   import retains nulls while user triggers are disabled. Requiring every stock row
   to name a location needs a separate decision.

3. Leave `stock_log.location_id` unconstrained. A historical booking may retain a
   deleted location identifier. Other location references, including product defaults,
   are outside this constraint's scope. A stale product default can therefore cause
   a future stock write to be refused; it must not silently select another location.

4. Refuse location deletion while any stock row references it, including a zero-amount
   row. Return HTTP 400 in the existing API error envelope with the message
   `Location has stock; move or consume it before deleting the location`.
   Keep the existing child-location refusal. The application check improves the
   explanation; the database constraint closes concurrent-write races.

   Deletion must also translate a violation of this specific constraint after a
   successful precheck into the same readable error. Do not expose SQL text or
   relabel every foreign-key error as a stock-location error. Writes racing with
   deletion must return a readable missing-location refusal and roll back their
   booking transaction, including log and outbox writes.

5. Refuse undo when it would restore stock to a deleted non-null historical location.
   Use `Cannot undo booking: original location no longer exists` in the existing
   HTTP 400 error envelope. Check only branches that restore a location; a history
   reference alone must not prevent an undo that removes stock or changes measurement.
   Lock the restored location with `FOR KEY SHARE` inside the booking transaction
   before the write so deletion cannot invalidate the check.

   Consume, negative inventory correction, transfer-from, and stock-edit-old reversals
   need this protection. Transfer-from must explicitly restore the logged location
   when recreating a row: its current omission invokes the product-default trigger.
   Do not substitute today's default for a deleted historical location. Null history
   keeps the existing nullable/default behavior described in decision 2.

   A failure propagates through `DatabaseService::InTransaction()`. The outermost
   transaction rolls back all correlated bookings and all bookings in an
   `UndoTransaction()` request, including undone flags, labels, and outbox effects.
   Never catch a restoration failure inside the correlated loop and continue.

6. Use a bounded blocking PostgreSQL migration within the existing SQL migration
   transaction. Set transaction-local `lock_timeout = '5s'` and
   `statement_timeout = '60s'`. Acquire `SHARE ROW EXCLUSIVE` locks on locations and
   stock before the final dangling-reference scan. Report invalid references and
   abort; otherwise create the index and add the validated constraint. The migration
   record commits with these changes. On failure, roll back all migration changes.

   These are per-lock and per-statement limits, not a promised total migration
   duration. Writes can wait while the transaction holds locks. Operators should
   schedule a quiet maintenance window; after a timeout, investigate active sessions
   and retry. Large installations may need a separately reviewed staged migration.
   Do not increase timeouts silently or claim this migration is lock-free.

7. Provide a read-only diagnostic query that lists stock row id, product id, and
   missing location id. Migration errors include the total count, a bounded sample,
   the query, and instructions to choose an explicit repair and rerun migration.
   Neither migration nor import changes quantities, assigns locations, or deletes
   stock to satisfy the constraint. Null values do not count as dangling references.

8. Validate SQLite source references before truncating or copying target data.
   Preflight and copy must read the same SQLite snapshot. Report source stock row,
   product, and missing location identifiers with an explicit repair instruction.
   `--force` does not bypass validation. Keep foreign-key triggers active and copy
   locations before stock. A failure during copying must roll back the target import
   transaction, including any truncation. Schema migration performed by the CLI
   before import remains a separate transaction; import rollback does not undo it.

## Migration alternatives

Application checks alone cannot prevent a concurrent deletion after a location check.
A database foreign key protects direct writes and future application paths. Cascading
stock deletion destroys inventory; automatically choosing a new location invents a
physical placement. Both are rejected.

A staged addition using `NOT VALID`, a commit, and validation in another transaction
would reduce the lock held during validation. It also introduces a partially applied
upgrade state and retry requirements. This proposal chooses bounded blocking within
`DatabaseMigrationService::ExecuteSqlMigrationWhenNeeded()` for this small change.

Adding `NOT VALID` and validating in the same transaction retains the addition's lock
until commit. It does not provide the staged approach's concurrency benefit. PostgreSQL
explains the lock distinction in its
[ALTER TABLE documentation](https://www.postgresql.org/docs/16/sql-altertable.html#SQL-ALTERTABLE-NOTES).
The [isolated experiment](../../.spike-adr29/RESULTS.md) demonstrates that retained lock.

## Consequences and client impact

Clients receive a refusal for writes that previously created dangling stock, and for
location deletion that previously stranded it. Success response shapes and the error
envelope stay unchanged. Document the new refusal conditions in the OpenAPI descriptions
and operator guidance; retain the response-contract snapshot checks required by
[ADR-0005](0005-wire-contract-is-the-invariant.md).

History remains readable after location deletion, but some historical operations can no
longer be undone. A person must resolve the missing location explicitly. The application
must not recreate a deleted location from an identifier alone.

The index adds storage and write cost. Foreign-key checks add database work and may wait
for concurrent location transactions. Existing dangling references prevent upgrade until
an operator repairs them. No production inventory has been inspected for this proposal.

## Acceptance prerequisites

1. Demonstrate restriction of invalid writes and referenced deletion, acceptance of nulls,
   unconstrained history, and both booking/deletion orderings against PostgreSQL.
   Met by the isolated pgTAP and concurrent-session experiments in the
   [evidence record](../../.spike-adr29/RESULTS.md).
2. Establish migration transaction boundaries, refusal without repair, lock timeout,
   and the same-transaction validation lock behavior. Met by source inspection and
   the migration experiments in that record. The proposed timeouts are policy values;
   they are not measurements of production migration time.
3. Identify undo restoration branches and import behavior, including source validation
   at both supported SQLite endpoints. Met by the code audit and fixture probes in
   that record. These establish design feasibility; application regression tests
   remain delivery requirements below.
4. The maintainer decides whether to accept decisions 1–8, including bounded blocking,
   nullable locations, and refusal of historical restoration. Open. Acceptance must
   be a separate bookkeeping-only pull request after substantive review.

## Delivery verification

After acceptance, implementation must include PHPUnit coverage against the real schema
and pgTAP coverage of the migration and constraint, following
[ADR-0025](0025-three-test-tiers.md). The isolated experiments do not replace these tests.

| Area | Required regression evidence |
|---|---|
| Writes | Valid insert/update, invalid insert/update, defaulted location, and nullable behavior. |
| Deletion | API message/envelope, direct SQL refusal, unused location success, and zero-amount stock refusal. |
| Concurrency | Both booking/deletion orders, observed lock waits, readable API refusal, and no partial ledger/outbox changes. |
| Migration | Dirty upgrade with bounded report and unchanged data/version; explicit repair and retry; valid upgrade; timeout rollback; fresh installation. |
| Undo | Consume, negative inventory, transfer and edit restoration after location deletion; successful controls; correlated and transaction-wide rollback including flags and side effects. |
| Import | Both supported SQLite endpoints; valid and null data preserved; dangling source report; `--force` refusal before truncation; copy failure rollback; source snapshot stability. |

Run the required suite on PostgreSQL 15 and 16, the documentation checks, and the complete
CI-equivalent coverage collection. Report aggregate covered/executable lines and touched
file coverage against the same baseline and toolchain. Aggregate coverage must not fall;
application files must satisfy the repository's floor. Update OpenAPI descriptions and
operator recovery instructions in the implementation PR.

Keep the proposal and its evidence in one PR, acceptance bookkeeping in another, and
implementation in later single-purpose PRs. The constraint and the application/import
handling it requires must ship together so no intermediate release exposes raw failures.
