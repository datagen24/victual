# ADR-0022 acceptance prerequisites 1, 2, 3, 5, 6 and 7 — spike results

Run 2026-09-14 against PostgreSQL 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1), the cluster already
installed on this session's machine (`pg_lsclusters`), rather than a container — this
machine's `docker` has no reachable daemon (`/var/run/docker.sock` does not exist) where the
ADR-0023 spike's machine had podman's Docker-CLI shim. Booted with:

```
service postgresql start
su - postgres -c "psql -c \"CREATE ROLE victual LOGIN PASSWORD 'victual';\""
su - postgres -c "psql -c \"CREATE DATABASE adr22_spike OWNER victual;\""
export PGPASSWORD=victual
psql -h 127.0.0.1 -U victual -d adr22_spike -v ON_ERROR_STOP=1 -f 00-schema.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -v ON_ERROR_STOP=1 -f 01-stock-measurement.pgsql.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -v ON_ERROR_STOP=1 -f 02-seed.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -f 03-prereq1-coexistence.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -f 04-prereq2-volume-by-weight.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -f 05-prereq3-compaction.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -f 06-prereq5-container-identity.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -f 07-prereq6-undo.sql
psql -h 127.0.0.1 -U victual -d adr22_spike -f 08-prereq7-conversion-failure.sql
```

Files 03–08 are run without `ON_ERROR_STOP` on purpose: several statements are expected to
fail, and the failure itself is the assertion.

## Prerequisite 1 — coexistence, with a negative control

`03-prereq1-coexistence.sql`, against product 1 (Baking flour, stock unit Bag, a
product-specific "1 bag = 5 lb" conversion) and product 3 (Flour, weighed — the existing
`enable_tare_weight_handling` mechanism, stock unit Pound):

```
--- decision 2: the bag count is unchanged by opening one — still 4 bags ---
 total_bags
------------
          4

--- decision 3: the open bag's measured fraction ... (1 bag = 5 lb, so 1.2 lb = 0.24 bag) ---
     stock_id     | opened_amount | stock_qu_id | fraction_of_stock_unit
------------------+---------------+-------------+------------------------
 p1-open-measured |           1.2 |           1 |                   0.24

--- Context's own product total (stock_amount, as GetProductDetails() would report it) ---
 stock_amount
--------------
           20

--- someone weighs the open canister: gross reading 1.4 lb (1.2 lb flour + 0.2 lb tare) ---
 existing_mechanism_says_consumed | actually_consumed_from_this_one_canister
----------------------------------+------------------------------------------
                             18.8 |                                      3.8
```

Three sealed bags and one measured open bag give the correct total: the count stays 4 bags
(decision 2), and the open bag's own remainder resolves to 0.24 bag through the existing
`quantity_unit_conversions_resolved` view — no new conversion machinery, matching decision 3.

The negative control reproduces Context's Limit 2 exactly, against real rows: product 3
holds three sealed 5 lb bags plus the open canister, 20 lb total. Weighing the open canister
at a gross 1.4 lb (1.2 lb flour + 0.2 lb tare), `ConsumeProduct()`'s existing formula —
`abs($amount - $productDetails->stock_amount - $product->tare_weight)` — computes 18.8 lb
"consumed", against the 3.8 lb the canister actually gave up. The mechanism reads the
*product* total, and the three sealed bags sitting beside the open one are inside that total
too. This is not a source-derived limit anymore; it is a demonstrated defect against real
rows in real PostgreSQL, in the ratio the ADR's own numbers predict (18.8 is 20 minus 1.2,
not the 3.8 one canister actually gave up).

## Prerequisite 2 — volume measured by weight

`04-prereq2-volume-by-weight.sql`, product 2 (Milk, stock unit Gallon), a gallon jug on a
scale at 4.3 lb, with a product-specific "1 gallon jug = 8.6 lb" conversion:

```
--- derived fraction of a gallon remaining, through quantity_unit_conversions_resolved ---
 stock_id | measured_lb | gallons_remaining
----------+-------------+-------------------
 milk-1   |         4.3 |               0.5
```

Half the jug, derived through the same recursive view prerequisite 1 used — no density
column, no second conversion mechanism. The `factor` PostgreSQL returns
(`0.11627906976744186`, i.e. `1/8.6`) is exactly what a per-product row in
`quantity_unit_conversions` already stores.

## Prerequisite 3 — compaction

`05-prereq3-compaction.sql`, three product-1 stock rows sharing every `stock_splits`
group-by column (`product_id`, `best_before_date`, `purchased_date`, `price`, `open`,
`opened_date`, `location_id`, `shopping_location_id`, `note`), one of them measured:

```
--- stock_splits candidates: only the two UNMEASURED rows form a group (count > 1) ---
 product_id | total_amount | stock_id_to_keep | id_group | stock_id_group
------------+--------------+------------------+----------+-----------------------
          1 |            2 | p1-split-a       | 7,6      | p1-split-b,p1-split-a

--- after: the two unmeasured rows merged into one (amount = 2); the measured row untouched ---
  stock_id  | amount | opened_amount
------------+--------+---------------
 p1-split-a |      2 |
 p1-split-c |      1 |            0.8
```

`p1-split-c`'s `opened_amount IS NOT NULL` keeps it out of `stock_splits` entirely — it never
becomes a merge candidate, whatever else matches — while `p1-split-a`/`p1-split-b`, identical
in every other column, still compact into one row exactly as `CompactStockEntries()`
(`services/StockService.php:2504-2552`) would, run here as the same two-statement sequence
against the real rows. The exclusion is decision 5's third clause on the same view that
already excludes per-unit and userfield-bearing entries; adding it did not disturb the other
two.

## Prerequisite 5 — container identity

`06-prereq5-container-identity.sql`:

```
--- two opened containers of product 1, separate state: one measured, one not ---
      stock_id      | amount | open | opened_amount | opened_qu_id
--------------------+--------+------+---------------+--------------
 p1-open-measured   |      1 |    1 |           1.2 |            2
 p1-open-unmeasured |      1 |    1 |               |

--- attaching a measurement directly is REFUSED — amount is 3, not 1 (decision 8) ---
ERROR:  new row for relation "stock" violates check constraint "stock_measurement_coherence_check"

--- final state: one measured container (amount = 1) and its unopened, unmeasured rest (amount = 2) ---
     stock_id      | amount | open | opened_amount | opened_qu_id
-------------------+--------+------+---------------+--------------
 p1-multiunit      |      1 |    1 |           0.5 |            2
 p1-multiunit-rest |      2 |    0 |               |
```

Two opened containers of the same product keep independent `opened_amount`/`opened_qu_id`
state — measuring one never touches the other, and both remain their own row. The
`OpenProduct()` in-place case (`open = 1, amount = 3`, the three-unlabelled-jugs state that
method produces today at `services/StockService.php:1609-1636`) refuses a measurement
outright via the coherence constraint; splitting first — the same operation's else-branch,
`:1637-1654` — leaves a 1-unit entry a measurement can attach to and a 2-unit unmeasured rest,
which is exactly the "refused or split" the prerequisite asks for.

## Prerequisite 6 — undo

`07-prereq6-undo.sql`, in two parts.

**Consuming a measured entry fully, then undoing the consumption:**

```
--- the measured entry before consumption ---
 stock_id  | amount | open | opened_amount | opened_qu_id | opened_tare | opened_measured_at
-----------+--------+------+---------------+--------------+-------------+---------------------
 p1-undo-1 |      1 |    1 |           1.2 |            2 |        0.05 | 2026-09-10 08:00:00

--- restored: remainder, unit, tare and timestamp all back ---
 stock_id  | amount | open | opened_amount | opened_qu_id | opened_tare | opened_measured_at
-----------+--------+------+---------------+--------------+-------------+---------------------
 p1-undo-1 |      1 |    1 |           1.2 |            2 |        0.05 | 2026-09-10 08:00:00
```

Identical before and after: mirroring `UndoBooking()`'s consume branch
(`services/StockService.php:2200-2213`) extended to carry the four measurement columns
through `stock_log`, the rebuilt row is indistinguishable from the one that was deleted.
Without that extension the row `UndoBooking()` reconstructs today has no measurement at all
— the plan's own claim, now shown against a real round trip rather than read from the source.

**Undoing the opening on a measured entry**, first as `UndoBooking()`'s `PRODUCT_OPENED`
branch is written today (`services/StockService.php:2280-2290`, clearing only `open` and
`opened_date`):

```
expect: REFUSED. Clearing open/opened_date alone would strand a measurement on a closed entry (decision 9), and the coherence constraint catches it outright.
ERROR:  new row for relation "stock" violates check constraint "stock_measurement_coherence_check"
DETAIL:  Failing row contains (17, 1, 1, 2026-11-01, 2026-09-08, p1-undo-1, 3.25, 0, null, 3, null, null, 1.2, 2, 0.05, 2026-09-10 08:00:00).
```

This sharpens the ADR's own wording. Decision 9 says the unmodified branch "would strand a
measurement on a closed entry" — but against the coherence constraint added for decision 8,
the unmodified branch does not silently strand anything: it refuses to write at all, which
would abort the undo transaction outright. Clearing the four measurement columns in the same
statement is not only what decision 9 asks for; it is required for the undo to complete:

```
--- legal state: unopened, unmeasured entry, its own amount intact ---
 stock_id  | amount | open | opened_date | opened_amount | opened_qu_id | opened_tare | opened_measured_at
-----------+--------+------+-------------+---------------+--------------+-------------+--------------------
 p1-undo-1 |      1 |    0 |             |               |              |             |
```

## Prerequisite 7 — conversion failure

`08-prereq7-conversion-failure.sql`, two parts.

**7a — an unconvertible unit refused at entry.** Product 1's stock unit is Bag; no
conversion path exists from Fluid Ounce to Bag, product-specific or default:

```
SELECT * FROM quantity_unit_conversions_resolved WHERE product_id = 1 AND from_qu_id = 4 AND to_qu_id = 1;
(0 rows)
```

Zero rows is the same query the write path runs before issuing the `INSERT`/`UPDATE` at all
— there is nothing here for the coherence constraint (decision 8) to catch, because
convertibility is a different property than the one that constraint enforces. **This is the
spike's one finding not already stated in the ADR text**: the database can enforce "one
container, one unit" as a `CHECK`, because that is a property of the row itself, but it
cannot enforce "this unit converts to this product's stock unit" the same way without
re-deriving the recursive `quantity_unit_conversions_resolved` view per row — that has to be
the write path's own job in the service layer, checked before the row is written, not a
database constraint. Decision 3's refusal is real and matches; where it lives is additional
information plan 28 should record when it specifies the write path.

**7b — deleting a required conversion leaves the fraction unavailable, not reinterpreted.**
The stored measurement before deleting the conversion it depends on:

```
     stock_id     | opened_amount | fraction_of_stock_unit
------------------+---------------+------------------------
 p1-open-measured |           1.2 |                   0.24
```

After `DELETE FROM quantity_unit_conversions WHERE product_id = 1 AND from_qu_id = 2 AND to_qu_id = 1`:

```
--- the raw measurement is untouched ---
     stock_id     | opened_amount | opened_qu_id
------------------+---------------+--------------
 p1-open-measured |           1.2 |            2

--- but the derived fraction is now UNAVAILABLE — the join finds nothing, never an assumed 1.0 factor ---
     stock_id     | opened_amount | conversion_factor | fraction_of_stock_unit
------------------+---------------+--------------------+------------------------
 p1-open-measured |           1.2 |                    |
```

The raw `opened_amount`/`opened_qu_id` survive the deletion exactly as recorded; only the
derived fraction disappears, and it disappears to `NULL` rather than to a reinterpreted value
— there is no default conversion between Pound and Bag in this spike's seed data for the view
to fall back to, so nothing silently substitutes an assumed factor. This spike demonstrates
the "leaves fractions unavailable" half of decision 3's either/or; it does not demonstrate the
"refused" half (refusing the `DELETE` itself, which would need a trigger plan 28 has not yet
specified either way).

## What this spike does not cover

Prerequisite 4 (wire compatibility against a response snapshot) and prerequisite 8 (the
legacy tare decision) are not spiked here — see issue #129: prerequisite 8 was already
decided 2026-09-14 (`cb99bf3`, ADR-0022 decisions 4 and 7), and prerequisite 4 cannot be
discharged as written because plan 14 piece 2's snapshot does not exist yet; it needs
rewording or a waiver, not a spike, and this branch does not attempt either.

No API, no UI, no PHP `StockService` changes — every statement above is the SQL a service
method would issue, run directly, not the PHP itself. No location-scoped vessel tare
(decision 4's other half): none of the eight prerequisites test it, and issue #129's own
comment marks it optional here. No cycle/depth/delete guards, because none of these six
prerequisites are about hierarchy. No concurrency case, and no browser or API-level
refusal-message check — decision 3's refusal is demonstrated as "the query the write path
runs finds nothing," not as an HTTP 400 response, since that response shape is still open
question 3 and not yet decided.
