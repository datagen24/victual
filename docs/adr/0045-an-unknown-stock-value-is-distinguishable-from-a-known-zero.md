# ADR-0045: An unknown stock value or price is distinguishable from a known zero

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The record presents options and a recommendation. The maintainer
  confirmed zero, null and omission semantics on 2026-10-11. Existing-data treatment
  and aggregate response behavior remain undecided.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-11, against `master` at `d4960138`. Design work only: it changes no
  code, reserves no migration number and amends no accepted record.
- **Referenced by:** [issue 774](https://github.com/datagen24/victual/issues/774), which
  asks for the change; [issue 702](https://github.com/datagen24/victual/issues/702), which
  issue 774 names as related; `victual-kit`
  [PR 33](https://github.com/datagen24/victual-kit/pull/33), which displays `0` as a known
  zero.
- **Relationship:** [ADR-0005](0005-wire-contract-is-the-invariant.md) governs the engine
  comparison that P2 and both wire-changing options disturb.
  [ADR-0024](0024-the-fork-writes-its-own-clients.md) decision 1 requires a Victual-owned
  client to be updated in coordination with a breaking change.
  [ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
  (Proposed) is a precedent for a deliberate wire change; this record does not depend on
  its acceptance.

## Context

Issue 774 reports that `GET /api/stock` sends `"value": 0` for a product whose eight stock
entries all have `price: null`. A client therefore cannot tell stock that was free from
stock whose price nobody recorded. The issue proposes that unknown stay `null`. That
proposal assumes that a stored price of `0` means "free". In data written by the shipped
web UI, a stored `0` usually means "not entered".

### A stored price of 0 usually means "not entered"

Five write paths store `0` when nobody entered a price:

- Purchase page, blank price: `views/purchase.blade.php` renders the price field with
  `'isRequired' => false` and an empty value. `public/viewjs/purchase.js` computes
  `Number.parseFloat(jsonForm.price * factor).toFixed(...)`, and an empty field times the
  factor is `0`, so the request carries `"0.00"`.
- Purchase page, price tracking off: The same function sets `jsonData.price = 0` when
  `VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING` is false.
- Inventory correction: `StockService::InventoryProduct()` books a positive correction
  at `$productDetails->last_price` when the request has no price. `last_price` is `0` for a
  product that was purchased but never with a positive price.
- Weighing a location: `StockService::WeighLocation()` books a higher reading at the
  same `last_price`.
- Self-production: `RecipesService` books the produced product at
  `recipes_resolved.costs_per_serving`, which `COALESCE`s to `0` when ingredient costs are
  unknown.

Three other write paths store `NULL` for "not entered". `POST /api/stock/products/{id}/add`
leaves `price` `NULL` when the key is absent, `null` or `""` (`StockApiController`).
`PUT /api/stock/entry/{entryId}` clears the stored price on `null` or `""`. The MCP
sidecar's `purchase-product` tool omits `price` unless the caller gives one
(`mcp/src/tools/purchase-product.ts`). No `stock_log` column records which client wrote a
row, so existing `0` rows cannot be sorted by writer.

The read side already treats `0` as unknown in four places:

- `products_price_history` keeps only rows with `COALESCE(sl.price, 0) > 0`
  (`migrations/0261.pgsql.sql`).
- `products_average_price` applies the same filter and has no row for a product with no
  qualifying purchase (`db/pgsql/baseline/04_views_l1a.sql`).
- `recipes_resolved.prices_incomplete` is `1` when any position's cost is `0`
  (`db/pgsql/baseline/05_views_l3.sql`; the SQLite line has the same expression in
  `migrations/0247.sql`).
- `views/stockoverview.blade.php` hides the last price with `!empty($currentStockEntry->last_price)`.

Making `0` mean "free", as issue 774 asks, therefore requires changing these write paths,
these filters and the meaning of rows already stored. That is prerequisite decision P,
below. The response options depend on its answer.

### What each field reports today

The values below are inferred from the SQL and PHP at `d4960138`. They were not executed.
"Unpriced" means `price IS NULL`. The product has no sub-products.

| Case | `value` (`GET /api/stock`) | `stock_value` | `last_price` | `avg_price` | `current_price`, `oldest_price` | Entry `price` |
|---|---|---|---|---|---|---|
| (a) all entries unpriced | `0` | `0` | `0` | `null` | `0` | `null` |
| (b) priced and unpriced entries | sum of priced entries | same sum | latest price above 0 | mean of priced purchases | next entry's price; last price above 0 if that is `null` | mixed |
| (c1) never stocked | no row | `0` (PHP fallback) | `null` | `null` | `null` | none |
| (c2) stock 0, priced history | no row | `0` (PHP fallback) | latest price above 0 | mean of priced purchases | latest price above 0 | none |
| (d) all entries priced above 0 | sum | sum | latest price | mean | next entry's price | number |
| (e) an entry priced `0` | adds 0 | adds 0 | skips it | skips it | `0` when it is next | `0` |
| (f) caller without `STOCK_PRICES_VIEW` | key absent | key absent | key absent | key absent | key absent | key absent |

The sources of each column:

- `value` comes from `stock_current`, which sums `COALESCE(price, 0) * amount` and wraps the
  first branch's sum in a second `COALESCE(..., 0)` (`migrations/0298.pgsql.sql`, both
  `UNION` branches). A product with no stock has no row, so `GET /api/stock` omits it.
- `stock_value` is `stock_current.value`. `StockService::GetProductDetails()` substitutes
  `0` when the product has no `stock_current` row.
- `last_price` comes from `cache__products_last_purchased`, which mirrors
  `products_last_purchased`. That view takes the newest row of `products_price_history`
  and defaults to `0` (`migrations/0261.pgsql.sql`). A product with no purchase has no
  cache row, so the `LEFT JOIN` in `uihelper_product_details` yields `null`.
- `avg_price` comes from `cache__products_average_price`. It averages all non-undone
  purchases with a price above 0, including stock no longer held. The OpenAPI description
  says "all stock entries currently in stock" (open question 3).
- `current_price` is `COALESCE(snu.price, plp.price)` in `products_current_price`: the
  next-use entry's price, or the last purchase price when that is `NULL`. `oldest_price`
  is deprecated and equals `current_price`.
- Case (f): `FieldPolicy::RedactRow()` removes each key listed in
  `db/pgsql/prices-seed.sql` for a caller without `STOCK_PRICES_VIEW`. An absent key is
  already distinct from every value above.

Case (a) and case (e) are indistinguishable on `value` and `stock_value`. In case (b),
`current_price` can report the price of an entry other than the one consumed next.

### Consumers of these fields

| Consumer | Field | Handling today |
|---|---|---|
| `views/stockoverview.blade.php` | `value`, `last_price` | prints `value` as is; hides an empty `last_price` |
| `public/viewjs/stockoverview.js` | `stock_value` | `.text(result.stock_value)` |
| `public/viewjs/components/productcard.js` | `stock_value`, `last_price`, `avg_price` | a falsy `stock_value` is shown as `'0'`; `last_price` and `avg_price` show "Unknown" when `null` |
| `public/viewjs/mealplan.js` | `last_price` | `null` becomes `0` for cost arithmetic |
| `public/viewjs/purchase.js`, `public/viewjs/inventory.js` | `last_price` | prefill the price field; `null` and `0` leave it blank |
| `StockService::InventoryProduct()`, `StockService::WeighLocation()` | `last_price` | books it as the correction's price |
| `services/Influx/BookingEventPublisher.php` | `stock_current.value` | casts with `(float)`, so `NULL` becomes `0.0`; the payload validator requires a number |
| `victual-kit` `ProductDetail.swift`, `StockSummary.swift` | all price fields | documents `nil` as "no `STOCK_PRICES_VIEW`" and "never zero-filled" |

The MCP sidecar reads none of these fields (`mcp/src/victual/types.ts`).

`victual-kit` reads the fields through a client generated from `victual.openapi.json`; PR 33
changes `Sources/VictualAPI/openapi.json` and `Scripts/update-openapi.py`. A Swift optional
decoded with `Codable` maps an absent key and a JSON `null` to the same `nil`. That is
general `Codable` behaviour; `victual-kit`'s generated code was not inspected for it. The
doc comment in `victual-kit`'s `ProductDetail.swift` says `nil` means "no permission". It
is already inaccurate for `averagePrice`, and for `lastPrice` on a never-purchased product.

### Constraints from accepted records

ADR-0005's two accepted exceptions are engine differences: float accumulation order and
date-only `chores.start_date`. Neither covers a deliberate change to what PostgreSQL
returns for an unpriced entry. `.devtools/pgsql/difftest.php` compares `stock_current`
against the frozen SQLite definition, `IFNULL(price,0)` in `migrations/0233.sql`.

Every view rewrite this record discusses changes PostgreSQL's answer while the frozen
SQLite line keeps the old one. That holds for option A's `value`, for the single-price
fields that options A and B share, and for P2's filters. Each rewrite therefore needs the
same treatment: an ADR-0005 accepted difference, or a difftest normalisation that the
implementing pull request records. The options differ in which rewrites today's fixtures
exercise, not in whether the treatment is needed.

ADR-0024 decision 1 allows a breaking change to the API only when a Victual-owned client is
updated before or in coordination with it. The OpenAPI document and the contract snapshot
change in the same pull request. The record does not say whether a change of meaning with
no change of type is a breaking change. Open question 4 asks the maintainer.

## Prerequisite decision P: what a stored price of 0 means

The maintainer selected P2's meaning for prices on 2026-10-11: zero is a known zero,
`null` is unknown, and an omitted price field is redacted. Existing zero-valued rows
and imported data still need a treatment selected below. The alternatives remain
for comparison; P1 is not the selected direction.

**P1. Keep "0 means not entered" and add an explicit representation of "free".** Every
reader treats `0` as unknown, as the four read paths above already do. A free entry needs
a new column on `stock` and `stock_log` (for example a boolean), a new API field, a control
on the purchase page and a matching change to the importer. Existing rows keep their
meaning, and no row needs conversion. The cost is a schema change, an API field and a form
control. The API's `NULL` and `0` would both mean unknown, which leaves two encodings of
one state.

**P2. Make 0 mean "free" and store `NULL` for "not entered".** The purchase page sends
`null` for a blank field and when price tracking is off. `InventoryProduct()`,
`WeighLocation()` and self-production write `NULL` when no price is known. The four read
paths change with it:

- `products_price_history` and `products_average_price` filter on `price IS NOT NULL`
  instead of `COALESCE(price, 0) > 0`, so a free purchase enters the history and the
  average.
- `prices_incomplete` tests for an unknown position cost instead of a zero one, which
  needs `recipes_pos_resolved` to carry `NULL` for an unpriced ingredient.
- The stock overview shows a last price of `0` instead of hiding it.

These rewritten views diverge from the frozen SQLite line whatever response option is
chosen, so P2 needs the ADR-0005 treatment described under "Constraints from accepted
records". Today's fixtures exercise the divergence only where a row is priced `0` or a
recipe position has no known price. No stock row in `.devtools/pgsql/view-tests/01` to
`03` is priced `0`. Whether `03_recipes.sql` has a position without a known price was not
checked.

Existing rows need one of four treatments. The constitution's data-integrity section says
the stock ledger is exact history, and `stock_log` is that ledger, so the treatments differ
in whether they rewrite it:

- Convert every stored `0` to `NULL` in `stock` and `stock_log`, and rebuild the two
  price caches. This rewrites recorded bookings and cannot be undone after the migration
  commits. A deliberately free entry written through the API becomes unknown, because no
  column records the writer. If chosen, the upgrade needs a preflight that counts the rows
  per table and product before any change. It also needs a report of every converted row
  (table, id, product, date), written where an operator can read it after the upgrade.
- Convert `stock` only and record the interpretation of `stock_log`. Current stock stops
  reporting false zeros. The ledger keeps its recorded `0`s, and the migration records a
  watermark, such as the highest `stock_log.id` at upgrade time. The history views read a
  `0` at or below the watermark as unknown and a `0` above it as free. The ledger is
  unchanged, at the cost of a second rule every reader of `stock_log` must apply.
- Convert nothing and record the same watermark for both tables. No row changes, and every
  reader of `stock` and `stock_log` applies the watermark rule.
- Leave stored rows unchanged with no watermark. Every blank web purchase made before the
  change then reports a known price of `0`, which is the false statement issue 774
  describes.

A heuristic selection of rows, such as purchases dated before the release, was considered
and set aside. It has the conversion's loss with less predictable results.

Under P2, `bin/victual-db-import` must apply the same conversion to an imported SQLite
database. Whether upstream grocy's purchase page also writes `0` for a blank price was not
checked. The web UI's `0` handling descends from upstream, so this is likely but
unverified.

**P3. Leave the meaning of 0 ambiguous.** No write path or filter changes. Options A and B
can then distinguish only `NULL` entries, which API clients and stock entry edits write.
A blank web purchase still stores `0` and still reports as a known zero. Issue 774's
requirement, that a known zero shows as `0.00` and an unknown value shows differently, is
met for API-written data only.

## Response options

The tables in this section assume P2. Under P1, a price of `0` without the free marker
counts as unpriced wherever the tables say `NULL`. Under P3, case (e) stays ambiguous.

### Option A: `null` when a contributing price is unknown

This is issue 774's proposal. `value` and `stock_value` are `null` when any stock entry
that contributes to the sum is unpriced. `CurrentStockResponse.value` and
`ProductDetailsResponse.stock_value` change from `type: number` to
`type: ["number", "null"]`. `last_price` is `null` when no purchase has a known price, so
`products_last_purchased` drops its `COALESCE(..., 0)` and its cache is rebuilt.

`current_price` is one entry's price with a fallback, so "any contributing entry" does not
define it. The proposed rule follows the field's OpenAPI description: the next-use entry's
price, `null` when that entry is unpriced, and the last known price only when nothing is in
stock. Open question 2 asks the maintainer to confirm it.

| Case | `value` | `stock_value` | `last_price` | `avg_price` | `current_price`, `oldest_price` |
|---|---|---|---|---|---|
| (a) | `null` | `null` | `null` | `null` | `null` |
| (b) | `null` | `null` | latest known price | mean of known prices | next entry's price, `null` if unpriced |
| (c1) | no row | `0` | `null` | `null` | `null` |
| (c2) | no row | `0` | latest known price | mean of known prices | latest known price |
| (d) | sum | sum | latest price | mean | next entry's price |
| (e) | adds 0 | adds 0 | `0` when latest | includes 0 | `0` when it is next |
| (f) | key absent | key absent | key absent | key absent | key absent |

```js
// GET /api/stock, case (b): 6 at 2.50 and 2 unpriced
// today
{ "product_id": 7, "amount": 8, "value": 15 }
// option A
{ "product_id": 7, "amount": 8, "value": null }

// GET /api/stock/products/7, case (a)
// today
{ "stock_value": 0, "last_price": 0, "avg_price": null, "current_price": 0, "oldest_price": 0 }
// option A
{ "stock_value": null, "last_price": null, "avg_price": null, "current_price": null, "oldest_price": null }
```

Consequences of option A:

- Differential suite: `.devtools/pgsql/view-tests/01_stock_basics.sql` inserts product 6
  with 8 unpriced units (`view-stk-5`). PostgreSQL would return `value` `NULL` for it, and
  the frozen SQLite line returns `0`. `difftest.php` compares the normalised rows with
  `$ja === $jb`, so `stock_current` and `uihelper_stock_current_overview` (which selects
  `sc.value`) would both report `DIFF`. This is inferred from the SQL; the phase was not
  run. ADR-0005 would need an accepted difference, or the harness would need a
  normalisation that maps PostgreSQL's `NULL` to SQLite's `0` for these two columns.
  The single-price rewrites diverge in the same way: dropping `products_last_purchased`'s
  `COALESCE(..., 0)` for a product with purchases and none priced, and the
  `products_current_price` rule of open question 2. `02_products_and_pricing.sql` compares
  both views but prices every purchase, and `01_stock_basics.sql`, which has an unpriced
  product, compares neither. These divergences are therefore not exercised today, but they
  need the same treatment.
- Parity suite: Upstream 4.6.0 sends `0` where the fork sends `null`, a `type`
  difference on `GET /api/stock` and both product detail routes for unpriced stock. `.devtools/parity/harness/lib/accepted.js`
  would need an entry. Whether the parity generator's data contains unpriced stock was not
  checked.
- Influx: `BookingEventPublisher` would publish `0.0` for an unknown value, because of
  its `(float)` cast. Without the cast, its validator refuses the snapshot. The publisher
  needs a rule, for example omitting the `value` field for that product.
- Web UI: The stock overview prints an empty cell for a `null` `value`.
  `productcard.js` turns `null` into `'0'` with `|| '0'` and must show "Unknown" instead.
  `stockoverview.js` writes `null` as empty text after a booking.
- Inventory correction: `InventoryProduct()` and `WeighLocation()` book `NULL` for a
  product with no known price, where they book `0` today. Under P2 that is the intended
  result.
- `victual-kit`: A strict generated decoder refuses a `null` in a `type: number`
  property, so a `victual-kit` release decoding the new document must ship in coordination
  with the server (ADR-0024 decision 1). The apps then need to tell an absent key from
  `null`, which a generated optional does not do.

### Option B: additive booleans redacted with the value

`value` and `stock_value` keep today's number: the sum of known prices, which is a lower
bound in case (b). Two booleans state whether the sum is complete:
`CurrentStockResponse.value_incomplete` and `ProductDetailsResponse.stock_value_incomplete`.
They follow `recipes_resolved.prices_incomplete`, which marks a recipe cost that ignores
unpriced positions. `last_price`, `current_price` and `oldest_price` behave as in option A,
because the document already types them `["number", "null"]` and the product card already
renders `null` as "Unknown".

| Case | `value` / `stock_value` | `*_incomplete` | `last_price` | `avg_price` | `current_price`, `oldest_price` |
|---|---|---|---|---|---|
| (a) | `0` | `true` | `null` | `null` | `null` |
| (b) | sum of known prices | `true` | latest known price | mean of known prices | next entry's price, `null` if unpriced |
| (c1) | no row / `0` | no row / `false` | `null` | `null` | `null` |
| (c2) | no row / `0` | no row / `false` | latest known price | mean of known prices | latest known price |
| (d) | sum | `false` | latest price | mean | next entry's price |
| (e) | adds 0 | `false` | `0` when latest | includes 0 | `0` when it is next |
| (f) | key absent | key absent | key absent | key absent | key absent |

```js
// GET /api/stock, case (b)
// option B
{ "product_id": 7, "amount": 8, "value": 15, "value_incomplete": true }

// GET /api/stock/products/7, case (a)
// option B
{ "stock_value": 0, "stock_value_incomplete": true, "last_price": null, "avg_price": null, "current_price": null, "oldest_price": null }
```

A variant, B2, keeps `0` in `last_price` and `current_price` and adds `last_price_known`
and `current_price_known` booleans. It changes no existing value. It is not recommended,
because `null` already appears in these fields for a never-purchased product, and two
encodings of "no known price" would remain.

Consequences of option B:

- Redaction: Each flag needs a `permission_fields` row for `STOCK_PRICES_VIEW` in
  `db/pgsql/prices-seed.sql` and the migration that applies it. A flag reveals whether
  prices were recorded, which is price information. Two `ContractTest` methods check this.
  `testSensitiveFieldVocabularyIsClassified()` fails for any recorded key matching
  `SENSITIVE_FIELD_PATTERN` without a `permission_fields` row, and the pattern matches both
  flag names. `testRestrictedMatchesAdminMinusRedactedFields()` checks that the CHILD role
  receives neither key.
- Booleans: A view column holding `0`/`1` needs an entry in `services/WireBooleans.php`
  and in `WireContractTest::DOCUMENTED_BOOLEANS`, as `prices_incomplete` has.
  `WireContractTest::testEveryDocumentedBooleanIsAccountedFor()` fails until both exist.
- Differential suite: `value` keeps its number, so the flag adds no engine disagreement
  on it. If `stock_current` gains the flag as a column, `difftest.php` needs one more
  PostgreSQL-only strip line, the precedent `amount_measured` set for
  `migrations/0275.pgsql.sql`. If `StockService` computes the flag in PHP, the views phase
  is untouched for `value`. The single-price rewrites are the same as option A's and need
  the same ADR-0005 accepted difference or normalisation.
- Parity suite: The flags are `extra-field` differences on every stock row, and the
  single-price `null`s are `type` differences as under option A. One `accepted.js` entry
  covers both.
- Influx: `value` stays numeric, so the publisher and its validator are unchanged.
  Publishing the flag is optional.
- Web UI: Pages that ignore the flag show today's numbers. Showing "unknown" or a
  partial marker requires reading the flag in `stockoverview.blade.php`, `stockoverview.js`
  and `productcard.js`.
- Inventory correction: As under option A.
- `victual-kit`: No decoder fails, because no documented type changes. Showing
  unknown values requires reading the flags. The single-price fields change meaning:
  `last_price` and `current_price` become `null` where they are `0` today, and the apps
  read `nil` as "no permission". An app built before the change would hide a known-unknown
  price as if the caller lacked the permission. Whether ADR-0024 decision 1 requires a
  coordinated release for that change is open question 4.

### Parent products under options A and B

`stock_current`'s first branch sums `value` over the parent's own entries only, while
`amount_aggregated` includes converted sub-product stock (open question 1). A parent whose
stock is held entirely in sub-products therefore reports `value` `0`. Under option A that
`0` reads as a known zero. Under option B it reads as `0` with `value_incomplete` `false`.
Both are the false known zero issue 774 reports. Two ways to keep it out of this decision:

- Define the answer for rows with `is_aggregated_amount` `true` in this record. For
  example, option B sets `value_incomplete` to `true` whenever sub-product stock contributes
  to `amount_aggregated`, because `value` omits it. Option A returns `null` in the same
  case. The record then constrains the later answer to open question 1.
- Make open question 1 an acceptance prerequisite, so the aggregation rule is settled
  before either option is accepted.

The record does not choose between them; acceptance prerequisite 6 asks the maintainer.

### Option C: document the current behaviour

The wire and the database stay as they are. The OpenAPI descriptions of `value`,
`stock_value`, `last_price` and `current_price` state that an unpriced entry contributes
`0` and that `0` can mean free or unknown. No test, client or suite changes, apart from the
document. Issue 774's requirement is not met: a client still cannot show a known zero
differently from an unknown value.

```js
// CurrentStockResponse.value, description today
"The total value of this product's stock currently held (price times amount, summed across stock entries)"
// option C (illustrative wording)
"The total value of this product's stock currently held. An entry without a price contributes 0, so 0 can mean free or not recorded."
```

### Contract measurements

Measured on 2026-10-11 against the working copy at `d4960138`, from
`tests/Pgsql/snapshots/contract-admin.json` (198 route entries). The count covers six
in-scope key names on the three routes that carry them, plus `price` on
`GET /api/objects/{entity}` for `products_last_purchased`, an `ExposedEntity` whose view
both options change. It excludes `product_barcodes.last_price`, a barcode's own price, and
`GET /api/user/settings/{settingKey}`'s unrelated `value`. To reproduce:

```sh
jq -r 'to_entries[] | .key as $r | .value.shape | paths(scalars) as $p | ($p[-1]|tostring) as $k
  | select(($k|test("^(value|stock_value|last_price|avg_price|current_price|oldest_price)$"))
      or ($k == "price" and ($r|test("products_last_purchased"))))
  | "\($r)\t\($p|map(tostring)|join("."))\t\(getpath($p))"' tests/Pgsql/snapshots/contract-admin.json
```

The command prints 18 lines; removing the six excluded ones leaves 12 in-scope keys on 4
routes:

- `[].value` on `GET /api/stock`;
- `stock_value`, `last_price`, `avg_price`, `current_price` and `oldest_price` on
  `GET /api/stock/products/{productId}` and `GET /api/stock/products/by-barcode/{barcode}`;
- `[].price` on `GET /api/objects/{entity} (products_last_purchased)`, recorded as
  `integer|number`. No member of `components.schemas` describes this entity, so its
  documented type is the generic `GET /objects/{entity}` union.

| Measure | A | B | C |
|---|---|---|---|
| OpenAPI properties whose type changes | 2 | 0 | 0 |
| OpenAPI properties added | 0 | 2 | 0 |
| Recorded keys whose documented type widens to include `null` | 3, on 3 routes | 0 | 0 |
| Recorded keys already nullable, or without a member schema, that become `null` in more cases | 7, on 3 routes | 7, on 3 routes | 0 |
| Recorded keys added | 0 | 3, one per route | 0 |
| Recorded scalar types that change with the current fixture | 0 | 0 | 0 |

The last row is 0 because `ContractTest::testStockOperations()` records `GET /api/stock` and
`GET /api/stock/products/{productId}` after one add priced at 2.5. It records the by-barcode
read after an open, a transfer, an inventory correction from 9 to 8 at the first location,
a consume and a merge of a product with no stock. The correction is downward, so it books a
consumption and no new entry. All recorded stock is priced when these reads run.

This is read from the test code; the test was not run. An unpriced and a mixed product must be added to the fixture before
the snapshot shows either option's effect (Verification).

`GET /api/stock/volatile` returns `CurrentStockResponse` rows in three of its lists, so it
changes with `CurrentStockResponse`. The fixture leaves all four lists empty, and the
snapshot records no `value` key there.

## Maintainer response and remaining choices

The following response fixes the three price states. It does not select how an
aggregate reports a mixture of priced and unpriced stock, or how existing zeros
are converted. Options A and B retain those aggregate tradeoffs for review.

| Price state | API representation |
|---|---|
| Known zero | Numeric `0` |
| Unknown | JSON `null` |
| Redacted from the caller | Field omitted |

The implementing change must document these meanings on the affected properties in
`victual.openapi.json`. Client models must preserve absent versus present-null fields;
a single optional value that conflates them does not represent this contract.
The specification and implementation must change together; this proposal does not
claim that every existing price route already follows the selected semantics.

## Recommendation for remaining choices

P2's price semantics are selected. Option B remains a recommendation for aggregate
values; open question 2's fallback behavior remains undecided.

- P2 matches the writers that already distinguish. The add route, the stock entry edit
  and the MCP tool already store `NULL` for "not entered". Issue 774's test list already
  expects a price of `0` to report `0`. P1 adds a column, an API field and a form control,
  and keeps two encodings of unknown. P3 leaves every blank web purchase reporting a false
  zero.
- P2's existing rows should be read as "not entered". The shipped purchase page stores the
  same `0` for a typed zero and for a blank field, so no stored zero from the web UI can be
  read as "free". The recommended treatment converts `stock` and records a watermark for
  `stock_log`, which leaves the ledger as it was booked. Converting both tables is the
  alternative if the maintainer prefers one rule for readers over an unchanged ledger. It
  then needs the preflight and the converted-row report. Either loses any free entry
  written through the API before the upgrade.
- Option B changes no documented type, so no `victual-kit` decoder fails. It does not
  avoid ADR-0005 work: P2's filters and the single-price rewrites need an accepted
  difference or a normalisation under any option. Option B adds no further divergence on
  `value`, while option A adds one that `01_stock_basics.sql` exercises.
- Option B keeps the partial sum. In case (b) the known part of the value remains
  available, which option A discards.
- The single-price fields use `null`. The document already permits it, the product card
  already renders it, and option A would make the same change.

Option B has costs. A client must read two keys to display one value. A client that ignores
the flag still shows `0.00` for unpriced stock. The flag and the value must be redacted
together. The single-price `null`s still change meaning for `victual-kit` (open question
4). Option A is the better choice if the maintainer wants a client that reads only `value`
to be correct. Choosing option A adds a coordinated `victual-kit` release, a second
ADR-0005 accepted difference or normalisation for `value`, and an Influx rule.

## Open questions

1. **Should a parent product's `value` include its sub-products?** In `stock_current`'s
   first branch, `amount_aggregated` sums the parent's and its sub-products' stock through
   unit conversion. `value` sums only the parent's own entries
   (`SELECT ... FROM stock WHERE product_id = pr.parent_product_id`). A parent whose stock
   is held entirely in sub-products reports `value` `0` with a positive
   `amount_aggregated`. Under option A or B this reads as a complete, known zero. The
   aggregation rule itself is outside this decision. Its effect on options A and B is set
   out under "Parent products under options A and B", and acceptance prerequisite 6 asks
   how the record handles it.
2. **When the next-use entry is unpriced, is `current_price` `null` or the last known
   price?** Today it falls back to the last purchase price. The OpenAPI description
   promises that fallback only when the product is not in stock. The recommendation is
   `null`, following the description.
3. **Should the `avg_price` description be corrected?** The document says "all stock
   entries currently in stock". `products_average_price` averages every non-undone
   purchase with a known price. This is handled by
   [issue 772](https://github.com/datagen24/victual/issues/772), a document-only change that
   does not depend on this decision.
4. **Does ADR-0024 decision 1 cover a change of meaning with no change of type?** Under
   either option, `last_price` and `current_price` become `null` where they are `0` today.
   The document already permits `null`, so no decoder fails, but `victual-kit` reads `nil`
   as "no permission". ADR-0024 speaks of a "breaking change" without defining it. If a
   change of meaning counts, option B also needs a `victual-kit` release in coordination
   with the server.

5. **How are known zero, unknown and redacted prices represented?**

   > **Response:** datagen24, 2026-10-11: Agreed: `0` is known zero, `null` is unknown,
   > and an absent field is redacted. Document this response in the API specification
   > so it is implemented correctly.

   No explicit redaction marker is introduced. Existing-data treatment, aggregate
   completeness, parent-product valuation and the fallback in question 2 remain open.

## Acceptance prerequisites

1. P2's price semantics are confirmed. The maintainer still chooses the treatment of
   existing price-`0` rows and imported databases before this gate is met.
2. The maintainer chooses option A, B or C, and answers open questions 2 and 4.
3. A `victual-kit` change is identified for the chosen option, following ADR-0024
   decision 1. Under option A it is a coordinated release that decodes the nullable
   properties and tells an absent key from `null`. Under option B it reads the flags. Under
   P2 it relies on PR 33's rule that `0` is a known zero only after the existing-row
   conversion.
4. Under option A: the record names the ADR-0005 accepted difference or the harness
   normalisation for `stock_current.value` and `uihelper_stock_current_overview.value`, the
   parity `accepted.js` entry, and the Influx publisher's rule for an unknown value.
5. Under option B: the record names the flags' `permission_fields` rows, their
   `WireBooleans` entries, and whether `stock_current` or `StockService` computes them.
6. The maintainer chooses how parent products are handled: the record defines the flag or
   `null` for rows with `is_aggregated_amount` `true`, or open question 1 is answered first.
7. Under P2, or under option A or B: the record names the ADR-0005 accepted difference or
   difftest normalisation for each rewritten view (`products_price_history`,
   `products_average_price`, `products_last_purchased`, `products_current_price`,
   `recipes_pos_resolved`, `recipes_resolved`, as applicable). Under P2 with a conversion
   of `stock_log`, it also names the upgrade preflight and the converted-row report.

## Verification

An implementation of the chosen option must add the following tests and pass them in CI on
its pull request:

- PHPUnit against PostgreSQL (ADR-0025 tier 1): one test per case (a) to (f) on
  `GET /api/stock`, `GET /api/stock/volatile` and `GET /api/stock/products/{productId}`.
  Each asserts every field in the chosen option's table. A further test records a parent
  whose stock is held only in sub-products, so that open question 1's answer has a
  baseline.
- Write paths under P2: An API purchase with an absent, `null` or `""` price stores
  `NULL`. An inventory correction and a weighing for a product with no known price book
  `NULL`. A self-production from a recipe with `prices_incomplete` books `NULL`. A
  migration test applies the existing-row treatment the maintainer chose, rebuilds both
  price caches, and, if `stock_log` is converted, checks the preflight count against the
  converted-row report. An import test applies the same treatment.
- pgTAP for every view the change rewrites (`stock_current`, `products_last_purchased`,
  `products_price_history`, `products_average_price`, `products_current_price`,
  `recipes_resolved`), as `.devtools/pgtap/README.md`'s completeness list requires.
- Contract snapshot: `ContractTest`'s fixture gains an unpriced product and a mixed
  product before the stock reads. `contract-admin.json` and `contract-restricted.json` are
  regenerated, and `testRestrictedMatchesAdminMinusRedactedFields()` and
  `testSensitiveFieldVocabularyIsClassified()` pass.
- `WireContractTest`: `CurrentStockResponse` and `ProductDetailsResponse` validate
  against a served response for case (a). Under option A the response carries `null`;
  under option B it carries the flags, and `DOCUMENTED_BOOLEANS` lists them.
- Differential suite: The views phase passes with the recorded differences or
  normalisations of prerequisite 7, plus the strip line (option B) or the `value`
  difference (option A).
- Browser probe: A probe in `.devtools/frontend/` shows that the stock overview and the
  product card display an unknown value for case (a) and `0.00` for case (e). Under P2, it
  also shows that a blank purchase price is sent as `null`.
- Influx, option A only: A publisher test shows that an unknown value is not
  published as `0.0`.
