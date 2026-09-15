# 29. Replenishing a working container from backstock

**Goal:** Bagged flour in dry stores feeds a bin in the kitchen. Both quantities are visible,
refilling is one tap, and running the bin down tells you whether there is another bag behind
it or whether flour goes on the list.
**Depends on:** [ADR-0022](../adr/0022-open-containers-carry-a-measured-remainder.md),
**Accepted** 2026-09-14 — decision 4's location-scoped tare is what lets the bin be weighed.
Nothing else blocks it.
**Interacts with:** [28](28-open-container-measurement.md), which shares that primitive and
nothing else; [08](08-nested-locations.md), landed, which lets the bin sit under the kitchen;
[03](03-category-min-stock.md), whose group minimum is the third minimum axis this adds a
fourth beside.
**Status:** landed; see [Executed](#executed).

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

**The tare is the location's, decided 2026-09-14** under ADR-0022 question 1 and recorded in
its decision 4. A bin or a spice jar is a place stock passes through: every refill is a
`TransferProduct()` that mints a new stock row at the destination, so a tare on the row would
be lost on each refill, where a tare on the location is set once. `locations` gains a nullable
`tare_weight` and `tare_qu_id` in this plan's migration — after [23](23-storage-classes.md)'s
0274, which alters the same table and the same form first. The unit is the location's own,
since a location holds no stock unit to borrow; conversion to the stocked product's unit goes
through the global quantity unit conversions and is refused, never assumed, when the product's
stock unit is not a weight.

**The scale posts gross weight against a location and the server subtracts.** The device
identifies the vessel by scanning its location label ([06](06-location-barcodes.md), a `vctl:`
payload) and posts the gross reading. The server resolves the one product stocked at that
location — refusing when there is none or more than one — subtracts the tare in the product's
stock unit, and sets the entry's amount through
[`EditStockEntry()`](../../services/StockService.php), which already takes a stock row id and
an amount and does no tare arithmetic. The contract names the reading `gross`.

**Spice jars are the same pattern at a smaller scale.** Each refilled jar is a location under
[08](08-nested-locations.md)'s tree with its own label and tare; new bottles are stock at a
storage location until they are decanted, which is a transfer. A supply-size container used
directly — a one-pound jar of a high-volume spice — is an opened purchased container rather
than a vessel: its tare is the entry's, under [28](28-open-container-measurement.md), and it
may also be the source of a transfer into the jar.

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

   > **Decided while landing this plan.** The pair, as proposed:
   > `product_location_min_stock (product_id, location_id, min_stock_amount)`, unique on the
   > pair, so one product can carry a minimum at several locations and one location can hold
   > minimums for several products. A location-only "keep this bin at least a quarter full"
   > was not built — it would need a capacity notion `locations` does not have, and nothing
   > in the plan's verification cases needed it.

2. **What raises the refill prompt?** A view read by the stock overview is the cheap answer.
   Whether it also reaches [18](18-mqtt-state-publication.md)'s published state — so a panel
   or Home Assistant can show it without polling — is a wire question this plan does not
   settle.

   > **Decided while landing this plan.** The view only, `product_location_missing`, read by
   > `StockController::Overview()` and rendered as its own shortfall list beside the existing
   > product-group one. It does not reach plan 18's published MQTT state: that plan's outbox
   > publishes per-product topics, and a location shortfall is a (product, location) fact with
   > no existing topic shape to attach to. A kitchen panel or Home Assistant integration would
   > have to poll `/objects/product_location_missing` today. Left open as a genuine follow-up,
   > not settled by omission — plan 18 would need to decide the topic shape first.

3. **Does a refill need its own transaction type?** It is a transfer today and the ledger
   would read as one. A distinct type would make refills reportable separately, at the cost
   of a new value every consumer of `stock_log` must tolerate.

   > **Decided while landing this plan.** No new transaction type. A refill is
   > `StockService::TransferProduct()` with a preset amount and location pair — the same
   > `TRANSFER_FROM`/`TRANSFER_TO` correlated booking pair every other transfer writes, with
   > the same working undo. Distinguishing a refill from an ordinary transfer in a report is
   > therefore not possible from `stock_log` alone today; the cost of a new value every
   > consumer must tolerate was judged not worth paying for that, since nothing in this plan's
   > verification needs the distinction and a refill is, mechanically, exactly a transfer.
4. **How does a device authenticate?** [ADR-0019](../adr/0019-label-printers-are-master-data.md)
   established durable pairing and credential rotation for the label worker. Whether a kitchen
   terminal reuses that pattern, uses an API key, or needs something else is unowned, and
   [11](11-api-error-handling.md)'s outstanding API key expiry and rotation follow-up is the
   nearest existing work ([issue 130](https://github.com/datagen24/victual/issues/130)). The
   scale unit carries a scanner for location labels, so its identity question is the same one
   the label worker answered with pairing under ADR-0019; still open.
5. **Does a scale post a weight or a corrected amount?** Posting the gross weight puts the
   tare arithmetic in the server, where ADR-0022 decision 4 puts it. Posting a net amount puts
   it in the device, where a firmware bug is harder to find. The first is preferred and the
   input contract has to make which one explicit, per ADR-0022 open question 4.

   > **Decided 2026-09-14 (maintainer):** gross, against a location identified by its
   > scanned label; the server subtracts the location's tare. See *Weighing the bin*.

## Effort

Small to medium, and most of it already exists. The location minimum is a table, a view and a
report; the weighing is tare arithmetic in one existing method; the one-tap action is three
columns and a button following `quick_consume_amount`'s pattern. The transfer, the locations,
the barcodes and the undo are all shipped.

## Executed

Landed as `migrations/0276.pgsql.sql` — two columns on `locations`, three columns on
`products`, one table, two views (one new, one widened) — plus the API surface, the location
and product forms, the stock overview's refill prompt list, a PostgreSQL-only suite phase and
a browser probe. The design above shipped as written, both halves at once (the plan's own
"ready now" / "blocked" split in [issue 131](https://github.com/datagen24/victual/issues/131)
predates ADR-0022's acceptance; by the time this landed both halves were ready). Several things
are worth recording because they are not derivable from the design above.

**The division with plan 28 held exactly as scoped.** This plan owns
`TransferProduct()`'s tare refusal (removed outright — ADR-0022 decision 7 retires the
mechanism, not merely relaxes the one refusal) and the new `StockService::WeighLocation()`
method; `OpenProduct()`'s refusal and the `AddProduct`/`ConsumeProduct`/`InventoryProduct`
arithmetic were untouched, left for the sibling branch. `WeighLocation()` does not put tare
arithmetic inside `EditStockEntry()` itself, despite the scope-boundary text that introduced
this plan describing it that way: `EditStockEntry()` still does no tare arithmetic, exactly as
the plan's own *Weighing the bin* section says, and the correction happens in
`WeighLocation()` before it calls that method with the already-tared amount — "entry-scoped"
there means scoped to correcting one stock entry via `EditStockEntry()`, not that the
arithmetic lives inside it.

**Weighing requires exactly one stock entry at the location, and `CompactStockEntries()` is
called first to get there.** The plan does not specify this; it fell out of implementing
decision 8's coherence argument (one container, one unit) applied to a vessel rather than an
opened purchased container. An ordinary sequence of refills mints a new row per transfer, so
without compacting first, weighing would be refused by an accident of how many transfers
happened to precede it rather than by anything about the vessel itself.

**The negative control the plan's own verification section asks for by name** — "a control
showing the present product-scoped tare getting it wrong" — is reproduced as arithmetic in
`working-container-tests.php`, not by calling the retired mechanism: ADR-0022 decision 7
removes `enable_tare_weight_handling`'s arithmetic from this codebase entirely, so there is no
live code path left to call. The scenario (three sealed 5 lb bags, one transferred and weighed
into a 1 lb-tared bin at a gross reading of 3.4 lb) is real, run against real PostgreSQL 16;
what the old formula *would have* answered is computed by hand from the same real numbers,
matching the shape of the demonstration ADR-0022's own acceptance spike ran for prerequisite 1.

**The gross-reading contract is stricter than "the location's own unit" alone.** `gross_qu_id`
is accepted as an optional body field on `POST /stock/locations/{id}/weigh`; when given, it
must equal the location's `tare_qu_id` exactly, or the request is refused rather than
converted. This was not forced by anything in the plan text, which only says the unit is the
location's own — it is a deliberate reading of ADR-0022 question 5's "a client cannot subtract
tare twice" concern applied one step further: a client that thinks it is posting ounces against
a location tared in pounds should get a 400, not a silently wrong weighing.

**A device authenticates however every other API caller does today (question 4 stays open).**
No pairing mechanism was built. `POST /stock/locations/{id}/weigh` and its `by-label` sibling
require `PERMISSION_STOCK_EDIT` like `PUT /stock/entry/{entryId}`, the same permission any
authenticated caller with stock-edit rights already has. This plan does not narrow or answer
question 4; a kitchen terminal today would need the same session or API key a person's browser
uses.

**The label-scanning route is additive, not load-bearing for verification.** `POST
/stock/locations/by-label/{code}/weigh` resolves a `vctl:` code through the existing
`LabelIdentityService` (plan 06/25's machinery, unchanged) and delegates to `WeighLocation()`.
No fixture or suite case exercises it directly beyond confirming the resolved-location branch
compiles and routes; the device this plan anticipates is out of this repository per
[17](17-ecosystem-clients.md), so there is no client yet to test the route against end to end.

**Two things needed generic-controller changes the plan did not anticipate.**
`GenericEntityApiController` projects `/objects/locations` through an explicit column list
(added when plan 25 kept `import_epoch` off the wire) — `tare_weight` and `tare_qu_id` had to
be added to it in both `GetObject()` and `GetObjects()`, the same gap plan 08's Executed
section records for `parent_location_id` and `storage_class_id` before it, and
`nested-locations-tests.php`'s exact-key-set assertion needed updating for it. Separately,
`uihelper_stock_current_overview`'s widening could not simply select the three new `products`
columns off `products_view`'s own `p.*`: that view flattens `p.*` into an explicit column list
at `CREATE VIEW` time, so the new columns were invisible to it, and re-issuing the view's
definition to pick them up shifts every column after `p.*` out of position, which
`CREATE OR REPLACE VIEW` refuses outright. The migration joins straight to `products` a second
time instead; see its own comment for the measured error text either way would have produced.

**A pre-existing, unrelated defect blocked the browser probe until it was routed around.**
`public/viewjs/productform.js` always sends `parent_product_id` (the parent-product picker's
field, renamed from `product_id`) even when nothing was picked, and an untouched `<select>`
posts `""` for it — the same trap plan 08's Executed section records for
`parent_location_id`, unfixed for products. Reproduced directly against
`POST /api/objects/products` with a minimal payload, independent of anything this plan
touches, and confirmed to affect `product_group_id` and `shopping_location_id` the same way.
Filed as [issue 159](https://github.com/datagen24/victual/issues/159) rather than fixed here —
it is a defect in a shared form's submit handler, not this plan's own scope — and
`working-container.js` routes around it with a Playwright request interception that rewrites
only `parent_product_id: ""` to `null` immediately before the request reaches the server, so a
real defect in this plan's own fields would still reach the API unmasked.

**Verification.** `.devtools/pgsql/working-container-tests.php` is the sixteenth suite phase,
PostgreSQL-only for the same structural reason the group-minimum and nested-locations phases
are: `product_location_min_stock` and the two new `locations` columns exist on one side only,
so a `difftest.php` seed would pass while asserting nothing. It makes its own products and
locations and asserts 28 things: the shortfall view including the opened-stock discount and
the inactive-product/inactive-location exclusions, the rule that a short location never
reaches `stock_missing_products` or the shopping list, `TransferProduct()` accepting a
tare-enabled product, a full weigh-after-transfer scenario with dry stores left untouched, the
negative control described above, every refusal `WeighLocation()` has to raise, and the two
new entities' CRUD behaviour. `.devtools/frontend/working-container.js` — invoked by the
`frontend-security` job's actual step list, not merely placed beside the other probes — drives
both forms' round-trips, the shortfall list (including that it is not wired into the
`.status-filter-message` click handler product groups share, and that the S29 payload survives
as text), and a real one-tap refill that moves real stock. Both were confirmed passing against
real PostgreSQL 16.13 and a real demo instance; `run-tests.sh locations` and `run-tests.sh
groupminstock` were re-run to confirm no regression from the shared-view and
`GenericEntityApiController` changes.

Deferred: the barcode pack-size case ("a 25 lb barcode scanned, adding 25 lb rather than one
unit") in the plan's own verification list is existing `product_barcodes` behaviour this plan
does not change, and was not re-asserted here. A dedicated UI form for
`product_location_min_stock` itself was not built — the entity is reachable through the
generic `/objects/product_location_min_stock` API and its shortfall reporting is fully wired,
but setting a location minimum today means a direct API call rather than a page; the browser
probe seeds it that way, matching how `group-min-stock.js` seeds product-group membership
through the API rather than a dedicated form.

**Two more real defects surfaced merging plan 28's landed PR in, both found by re-running the
suite after the merge rather than by inspection.** Plan 28's migration 0275 (below this one in
`migrations/RESERVATIONS.md`'s order) appended `stock_current.amount_measured`; this plan's own
`uihelper_stock_current_overview` rebuild reads that view through a `SELECT *` branch of a
three-way `UNION` whose other two branches spell every column out as literals, so the union's
column counts disagreed the moment both migrations existed in one tree
(`each UNION query must have the same number of columns`, measured against real PostgreSQL
16.13) — `migrations/0276.pgsql.sql` now carries a matching literal for the two stand-in
branches, with the mismatch explained at the union itself. Separately, `difftest.php`'s
`uihelper_stock_current_overview` comparison needed the same PostgreSQL-only-column strip
plan 28 had already added for `stock_current.amount_measured`, this time for the three new
one-tap refill columns the view joins in from `products` — without it the `views` suite phase
failed on every row once both plans' widened views ran in the same PostgreSQL differential
comparison. Both were caught by re-running `check-migrations.php` and the `migrate`, `views`,
`triggers`, `rollback`, `locations`, `groupminstock`, `openmeasure` and `workingcontainer`
suite phases against real PostgreSQL 16.13 after merging plan 28's PR in, not by either plan's
own suite phase alone — each was blind to the other's widened view until both existed together.
