# 29. Replenishing a working container from backstock

**Goal:** Bagged flour in dry stores feeds a bin in the kitchen. Both quantities are visible,
refilling is one tap, and running the bin down tells you whether there is another bag behind
it or whether flour goes on the list.
**Depends on:** [ADR-0022](../adr/0022-open-containers-carry-a-measured-remainder.md),
**Proposed** — decision 4's entry-scoped tare is what lets the bin be weighed. Nothing else
blocks it.
**Interacts with:** [28](28-open-container-measurement.md), which shares that primitive and
nothing else; [08](08-nested-locations.md), landed, which lets the bin sit under the kitchen;
[03](03-category-min-stock.md), whose group minimum is the third minimum axis this adds a
fourth beside.
**Status:** draft for review.

## Why this is not plan 28

28 measures what is left in a container you are partway through. This moves stock from one
place to another and watches a level. They met because both need tare on the stock entry
rather than on the product, and they share nothing else: 28 adds columns beside `amount`,
this one never touches `amount`'s meaning at all.

## Today

Everything the flow needs exists except the weighing and the level.

| Need | Mechanism | State |
|---|---|---|
| One product, several pack sizes | `product_barcodes.qu_id` + `amount` | exists |
| Dry stores and the bin as places | `locations`, nested since [08](08-nested-locations.md) | exists |
| Moving flour from one to the other | `TransferProduct()`, with a `TRANSFER_FROM`/`TRANSFER_TO` pair and working undo | exists |
| Seeing both quantities | `stock_current_locations` — `SUM(amount)` grouped by product and location | exists |
| Buy more flour when the total runs low | `products.min_stock_amount` | exists |
| **Weighing the bin** | — | **missing** |
| **Knowing the bin is low** | — | **missing** |

**Weighing is missing because tare is on the product.** Weighing the bin subtracts the stock
amount of every entry of that product, dry stores included
([ADR-0022](../adr/0022-open-containers-carry-a-measured-remainder.md) context limit 2). And
the obvious workaround — make the bin its own tare-enabled product — is refused outright:
`TransferProduct()` throws for tare-enabled products (limit 4). Tare on the product and
backstock feeding a vessel are mutually exclusive by construction, not by convention.

**A recipe was considered and rejected.** `recipes.product_id` self-produces stock, and
`RecipesService` passes `$addExactAmount = true`, which makes production into a tare-enabled
product come out right rather than reinterpreting the amount as a gross weight. It works. It
was rejected for two reasons: the self-production booking cannot be undone
([issue 121](https://github.com/datagen24/victual/issues/121)), and a recipe is something you
find in a list and scale by servings, which cannot become one tap. Moving flour from a bag to
a bin is not production and the ledger should not record it as such.

## Proposed change

### Pack sizes are barcodes, not products

**Decided 2026-09-09.** One product, "Baking flour", with a weight stock unit. The 1, 5, 10,
25 and 50 lb bags are five rows in `product_barcodes` carrying `qu_id` and `amount`, so
scanning the 25 lb bag adds 25 lb. No parent product, no five products.

This is the opposite choice from the soda containers in
[28](28-open-container-measurement.md), and the reason is per-unit labelling: nobody labels a
bag of flour, so nothing forces a stock unit of pieces here. What is given up is the bag
count — the shelf reads "75 lb in dry stores", never "one 50 and one 25". For flour that is
the better trade; for a product where the count matters, the pooled-parent shape in 28 is
still available.

### A minimum on the place, not only on the product

A fourth minimum axis beside the product's own, the group's
([03](03-category-min-stock.md)) and nothing else: a minimum for a (product, location) pair,
so "keep 5 lb in the bin" is expressible separately from "keep 20 lb of flour".

**The two minimums mean different things and must not share a consequence.** A product below
its minimum is a purchase — `StockService::AddMissingProductsToShoppingList()` puts it on the
list. A bin below its minimum is *not*: there may be 75 lb of flour ten feet away, and adding
flour to the shopping list would be wrong. A location minimum produces a **refill prompt**,
and only the product total produces a shopping list entry.

Getting this backwards would put flour on the list every time the bin ran low, which is the
failure this plan exists to avoid.

Shape: a table keyed on product and location rather than a column, because most products have
no location minimum and a column on `stock` or `products` cannot express the pair. A view in
the shape of [03](03-category-min-stock.md)'s `product_groups_missing` reports the shortfalls.
`stock_missing_products` is not changed — it stays product-keyed, for the reason 03 records:
`AddMissingProductsToShoppingList()` uses each row's product id.

### Weighing the bin

An entry-scoped tared correction, per ADR-0022 decision 4.
[`EditStockEntry()`](../../services/StockService.php) already takes a stock row id and an
amount and does no tare arithmetic; adding it there sets the bin's entry from a gross weight
without touching dry stores. Where the tare is recorded is ADR-0022's to say; this plan
consumes it.

### One tap, on defaults that already have a shape

**The common actions are one tap. This is a requirement, not a preference.** The household
includes people who will not navigate a product picker, two location dropdowns and an amount
field to move flour, and a correct form with six fields is a feature nobody uses.

The pattern exists. `products.quick_consume_amount` gives the consume action a preset amount,
and `products.default_consume_location_id` gives it a preset location; both are columns on
`products` and both are read by `public/viewjs/consume.js`. A per-product default refill —
preset amount, preset source and destination — follows that shape rather than inventing one,
and renders as a single "Refill flour bin" action.

Refill prompts from the location minimum are the same button, surfaced where the shortfall is
reported rather than found by navigating to the product.

### Clients

The device this is likely to be operated from — a panel with a scale and a barcode scanner —
is out of this repository, and its contract is already settled.
[ADR-0012](../adr/0012-observations-are-proposals.md) decision item 5 names it exactly: *"A
scale that weighs an open jar against a known tare is not guessing; neither is a human with a
scanner. Those clients use the booking API."* So a kitchen terminal books directly and writes
no proposals, and nothing in ADR-0012 has to be revisited to build one.

Two repository-side questions such a device raises are open questions 4 and 5 below. Client
implementations themselves sit outside this repository's wave order, per
[17](17-ecosystem-clients.md).

### Migration

One PostgreSQL-only migration, claimed in [RESERVATIONS.md](../../migrations/RESERVATIONS.md):
the location-minimum table and its view. The lowest-free-slot rule applies as it has nine
times before.

## Verification

`.devtools/pgsql/difftest.php` over the new view, and a suite phase in the shape
[08](08-nested-locations.md)'s `locations` phase took. The cases that matter:

- flour bought into dry stores, transferred to the bin, both location rows correct
- the bin run to zero with backstock present: refill prompt raised, **nothing added to the
  shopping list**
- the bin and the product total both below minimum: refill prompt *and* a list entry
- a bin weighed by entry-scoped tared correction, with dry stores unchanged — and a control
  showing the present product-scoped tare getting it wrong
- a transfer undone, restoring both locations, since `TRANSFER_FROM`/`TRANSFER_TO` undo as a
  correlated set
- a 25 lb barcode scanned, adding 25 lb rather than one unit

A browser probe for the one-tap refill, invoked by the `frontend-security` job rather than
merely placed beside it — [08](08-nested-locations.md)'s Executed section records that
distinction being missed.

## Open questions

1. **Does a location minimum belong to the location or the pair?** A table keyed on
   (product, location) is proposed. A minimum on the location alone ("keep this bin at least
   a quarter full") would need a capacity notion that does not exist.
2. **What raises the refill prompt?** A view read by the stock overview is the cheap answer.
   Whether it also reaches [18](18-mqtt-state-publication.md)'s published state — so a panel
   or Home Assistant can show it without polling — is a wire question this plan does not
   settle.
3. **Does a refill need its own transaction type?** It is a transfer today and the ledger
   would read as one. A distinct type would make refills reportable separately, at the cost
   of a new value every consumer of `stock_log` must tolerate.
4. **How does a device authenticate?** [ADR-0019](../adr/0019-label-printers-are-master-data.md)
   established durable pairing and credential rotation for the label worker. Whether a kitchen
   terminal reuses that pattern, uses an API key, or needs something else is unowned, and
   [11](11-api-error-handling.md)'s outstanding API key expiry and rotation follow-up is the
   nearest existing work.
5. **Does a scale post a weight or a corrected amount?** Posting the gross weight puts the
   tare arithmetic in the server, where ADR-0022 decision 4 puts it. Posting a net amount puts
   it in the device, where a firmware bug is harder to find. The first is preferred and the
   input contract has to make which one explicit, per ADR-0022 open question 4.

## Effort

Small to medium, and most of it already exists. The location minimum is a table, a view and a
report; the weighing is tare arithmetic in one existing method; the one-tap action is three
columns and a button following `quick_consume_amount`'s pattern. The transfer, the locations,
the barcodes and the undo are all shipped.
