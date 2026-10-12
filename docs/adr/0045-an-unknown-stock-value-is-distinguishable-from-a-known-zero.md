# ADR-0045: An unknown stock value or price is distinguishable from a known zero

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The record presents options and a recommendation. The maintainer
  confirmed zero, null and omission semantics on 2026-10-11. Option B, parent-only values, last-known-price fallback and client coordination
  are confirmed. Open questions 8 to 13 were answered on 2026-10-12.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-11, against `master` at `d4960138`. Design work only: it changes no
  code, reserves no migration number and amends no accepted record. Revised 2026-10-12
  against `master` at `8fd76607` to specify acceptance prerequisites 1, 5 and 7 (see
  [Specification of the gates](#specification-of-the-gates-2026-10-12)) and to add open
  questions 8 to 13. No maintainer decision changed. The maintainer's answers to open
  questions 8 to 13 were recorded on 2026-10-12.
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
change in the same pull request. The maintainer confirmed in open question 4 that a
semantic change also requires client coordination when its JSON type stays unchanged.

## Prerequisite decision P: what a stored price of 0 means

The maintainer selected P2's meaning for prices on 2026-10-11: zero is a known zero,
`null` is unknown, and an omitted price field is redacted. Existing rows hold test data only. Imported legacy zeros default to unknown,
with that interpretation disclosed and original booking values preserved. The storage
mechanism for this interpretation remains to be designed. The alternatives remain
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
recipe position has no known price. No `stock` or `stock_log` row in
`.devtools/pgsql/fixtures/00_base.sql` or `.devtools/pgsql/view-tests/01` to `05` is
priced `0`, and every position in `03_recipes.sql` resolves to a priced product. Gate 7
under [Specification of the gates](#specification-of-the-gates-2026-10-12) gives the
per-view result.

The following alternatives document the existing-data tradeoffs. The maintainer
confirmed there is no production data to preserve; historical imports remain in scope.
The selected import policy preserves original booking values and interprets legacy zeros
as unknown by default. It does not authorize rewriting `stock_log`. The constitution's data-integrity section says
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

Under P2, `bin/victual-db-import` must disclose and record the selected interpretation
of imported legacy zeros without rewriting original booking values. Whether upstream grocy's purchase page also writes `0` for a blank price was not
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
unpriced positions. `last_price` behaves as in option A. `current_price` and its
deprecated alias `oldest_price` retain the last-known-price fallback when the next entry
is unpriced. They return `null` when neither the entry nor the fallback has a known price.
OpenAPI must describe that fallback even while stock exists.

| Case | `value` / `stock_value` | `*_incomplete` | `last_price` | `avg_price` | `current_price`, `oldest_price` |
|---|---|---|---|---|---|
| (a) | `0` | `true` | `null` | `null` | `null` |
| (b) | sum of known prices | `true` | latest known price | mean of known prices | next entry's price, otherwise last known price |
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
  is untouched for `value`. The selected single-price rewrites need their own recorded ADR-0005 accepted
  differences or normalisations; the current-price fallback is retained.
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
  price as if the caller lacked the permission. ADR-0024 decision 1 requires coordinated client changes for that semantic
  change, as confirmed in open question 4.

### Parent-product values under selected option B

Prices and values do not roll up from sub-products, as confirmed by the maintainer
on 2026-10-11. A parent's `value` describes only its own stock; `amount_aggregated`
may include sub-products. OpenAPI and client displays must identify those different scopes.
The incompleteness flag considers only unpriced stock belonging to the parent itself.
A parent with no direct stock has direct value zero and a false incompleteness flag,
even when sub-products contribute to `amount_aggregated`. That zero makes no statement
about the value of the sub-products.

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

The maintainer selected P2 price semantics and option B aggregates on 2026-10-11.
A mixed aggregate returns the known subtotal plus an incompleteness flag. Parent values
exclude sub-products, and `current_price` retains its last-known-price fallback.
The table below describes individual price states; aggregates also require their flag.

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

## Rationale for the selected behavior

Option B preserves the known subtotal when some contributing prices are unknown.
The value and its incompleteness flag must be redacted together. A client that ignores
the flag can misrepresent a partial subtotal as a complete total, so semantic changes
require client coordination even when JSON types remain unchanged.

The only live database is a UI test database with no valid production data, as confirmed
by the maintainer on 2026-10-11. Historical grocy imports need an explicit policy because
legacy zeros cannot reliably distinguish a free purchase from a price never entered.
Treat them as unknown by default, disclose that interpretation during import, and preserve
original booking values. The representation of that interpretation requires further design;
no cutoff mechanism or ledger conversion is selected by this response. The next section
compares the candidate representations and recommends one; open question 8 asks the
maintainer to choose.

## Specification of the gates, 2026-10-12

This section states what acceptance prerequisites 1, 5 and 7 require. It was read from the
source at `8fd76607` on 2026-10-12. No test, suite phase or container was run, so every
statement about a suite result is inferred from the code and the fixtures. Where a gate
needs a choice the maintainer has not made, this section recommends an option and open
questions 8 to 13 ask for the decision.

### Gate 1: how imported legacy zeros keep their stored value

The importer must copy every `stock.price` and `stock_log.price` unchanged. A reader must
still tell an imported legacy `0` from a `0` written after the change. Four mechanisms were
examined.

**(a) A per-row marker.** `stock` and `stock_log` each gain a column owned by the target,
for example `legacy_zero_price SMALLINT NOT NULL DEFAULT 0` with
`CHECK (legacy_zero_price = 0 OR price IS NOT DISTINCT FROM 0)`, which also refuses a marked
row priced `NULL`. The importer sets it to `1` on each imported
row whose price is exactly `0`. Every reader treats a marked row's price as unknown. The
column changes no booking value; the importer writes it with an `UPDATE` that touches the
new column only.

**(b) An import watermark.** `import_epoch` cannot serve as one. Migration 0283 added it to
`products`, `stock`, `recipes`, `chores` and `batteries`, and migration 0269 to `locations`;
`stock_log` has none. Its default is `label_current_import_epoch()`, the generation held in
`label_import_state`. `DatabaseImporter` increments that generation before it copies,
whenever the target has a `labels` table, and `GetCommonColumns()` leaves `import_epoch` out of the copy. Each imported `stock` row
therefore takes the new generation from the default, and so does every row booked after the
import. The column exists so that a label request composed before an import is refused
(ADR-0021). It does not separate imported rows from later ones.

A new watermark, such as the highest `stock.id` and `stock_log.id` at import time, fails for
rows created later from imported ones:

- `TransferProduct()` and `OpenProduct()` split an entry into a new `stock` row that copies
  `price`.
- Undo re-inserts a `stock` row from its booking (`InsertRowFromBooking()`).
- `EditStockEntry()` writes a `stock-edit-new` booking that keeps the stored price when the
  request omits it.

Each of these rows has an id above the watermark and would read as a known zero.

**(c) A set of legacy `stock_id` values.** The importer records, in a table of its own, each
`stock_id` whose imported rows carry a `0`. Transfers, edits and undo keep the `stock_id`
(`TransferProduct()` copies `$stockEntry->stock_id`). `OpenProduct()` does not: a partial
open puts the remainder in a new `stock` row under a new `stock_id` (`$restStockId =
uniqid()`) that copies `price`. Under (c), that write path must add the new `stock_id` to the
set, the same propagation work (a) needs on every path.

Under (c), an edit that deliberately sets an imported entry's price to `0` keeps the `stock_id` too, and
the entry still reads as unknown. Removing the `stock_id` from the set on that edit would turn
the entry's earlier imported bookings into known zeros. Every price reader also needs a
semi-join on the set.

**(d) A side table of row ids.** The importer records `(table, id)` for each imported `0`.
This keeps the ledger tables free of a new column. Every write path that copies a price must
insert a side-table row where (a) copies a column, so it has (a)'s propagation work and adds a
join to every reader.

| Concern | (a) Per-row marker | (b) Row-id watermark | (c) Legacy `stock_id` set |
|---|---|---|---|
| Schema | One column and one `CHECK` on `stock` and `stock_log` | One recorded number per table | One table of `stock_id` values |
| Readers | Test the column | Compare ids with the watermark | Semi-join on the set |
| New bookings | Default `0`, a known price | Above the watermark, read as known | Unaffected unless they reuse an imported `stock_id` |
| Undo | Copies the marker from the booking | Re-inserted row reads as known: wrong | Correct, `stock_id` kept |
| Partial open | Remainder row copies the marker | Remainder row reads as known: wrong | Remainder's new `stock_id` must be added to the set |
| Edit of an imported entry | Explicit price writes `0`; kept price copies the marker | `stock-edit-new` reads as known: wrong | Explicit `0` still reads as unknown: wrong |
| Re-import | Truncated with the rows and set again | Recomputed | Truncated and rebuilt |
| Views phase of the differential suite | Not set; see placement below | Not set | Not set |

Under (a), each write that creates a `stock` or `stock_log` row by copying `price` from an
existing row must copy the marker with it. Read at `8fd76607`, these are in `StockService`:
`ConsumeProduct()`, `TransferProduct()`, `OpenProduct()`, `MeasureStockEntry()`, and the
undo paths `UndoBookingInTransaction()`, `UndoTrackedOpening()` and `InsertRowFromBooking()`.
A write that takes its price from the caller, such as a purchase or an inventory correction
with a price, writes `0`.

`EditStockEntry()` and `UndoTrackedEdit()` also update a `stock` row in place:

- `EditStockEntry()` always writes a `stock-edit-old` booking with the row's previous price
  (`'price' => $stockRow->price`), so that booking always copies the row's marker. Its
  in-place `$stockRow->update()` and its `stock-edit-new` booking keep the marker when the
  request omits the price, and write `0` when the request supplies one.
- `UndoTrackedEdit()` restores the row from the `stock-edit-old` booking
  (`'price' => $old->price`), so the same update must restore the marker from that booking.

Two further sites need attention:

- `CompactStockEntries()` merges the rows that `stock_splits` groups, and that view groups by
  `s.price`. The `GROUP BY` must include the marker, or compaction merges a legacy `0` with a
  known `0`.
- `MergeProducts()` already rewrites `stock.price` and `stock_log.price` in place as
  `price / factor`. That rewrite predates this record. A `0` stays `0`, and the marker stays
  on its row.

The `CHECK` refuses a marked row whose price is not `0`, so a path that sets a new price but
copies the marker fails loudly. The opposite omission, a copied `0` without its marker, is
silent. Each listed path therefore needs a test that books from a marked row.

Under (a), the readers that test the marker are:

- `stock_current`, for the gate 5 flag;
- `products_current_price`, so that a marked next-use entry falls back to the last known
  price, as question 2's answer requires;
- `products_price_history` and `products_average_price`, which exclude marked rows, and
  through them `products_last_purchased` and both price caches;
- `stock_splits`, as above;
- the routes that return `stock` or `stock_log` rows, which open question 10 covers.

The importer sets the marker inside the copy transaction: after the `CopyTable()` loop and
before `$this->Target->commit()`, where `RebuildStockLineage()` already runs, and only when
`applyRowMigrations` is true. An import then either commits with every legacy zero marked or
rolls back to the target's previous state. Setting it after the commit, in the block where
`RebuildPriceCaches()` runs, would leave a window in which a failure commits an import whose
legacy zeros read as known zeros, the silent outcome gate 1 exists to prevent.

`AssertValuesMatch()` runs after the commit and compares only the columns
`GetCommonColumns()` returns. That method must exclude the marker by name, so the assertion
still proves the copied columns verbatim and does not see the marker. `RebuildPriceCaches()`
runs after the commit in its own transaction and reads views that apply the committed
marker. If it fails, the caches are stale but every stored row is correct; rerunning the
import with `--force` rebuilds them, as for any failure of that step today. `difftest.php`
passes `false`, so the views phase never sees a marker set by the importer.

Under (a), the write paths listed above write the marker column, and the differential
suite's rollback phase drives `StockService` against SQLite. The SQLite fixture must
therefore gain the column on `stock` and `stock_log`, as `.devtools/pgsql/fixtures/00_base.sql`
already adds the four `opened_*` columns to SQLite's `stock` for the same phase. Three
consequences follow:

- `GetCommonColumns()` would then find the column on both sides and copy it. Its exclusion
  by name, as for `import_epoch`, also keeps a source from supplying the interpretation.
- `trigdifftest.php` compares the columns both engines share, so the column reaches it.
  Both sides hold `0` there, because no trigger sets it.
- SQLite re-expands `s.*` on every query, so its `stock_next_use` and
  `uihelper_stock_entries` carry the column without a rewrite. That is why gate 7 strips it
  on both sides, in the block that strips the `opened_*` columns. No view the suite compares
  projects `stock_log.*`: `products_price_history` and `products_average_price` read `sl.*`
  only inside a subquery, and the `sl.*` of `uihelper_shopping_list` is `shopping_list`.

The importer discloses the interpretation in its progress output, the channel
`RepairProductNesting()` already uses. An illustrative line:

```text
  stock_log    412 row(s) priced 0 read as unknown (imported legacy zero); stored prices unchanged
```

No table records an import's report today. Open question 8 asks whether a recorded row is
also required.

**Recommendation:** (a). It classifies each row exactly, follows an explicit edit, survives
undo, and its `CHECK` catches one of the two possible propagation errors. Its cost is the
copy work in the listed write paths and one test per path.

**Rows written by Victual before the change.** The maintainer confirmed on 2026-10-11 that
no production database exists. An upgrade still needs a rule for an installation that ran an
earlier release, where five write paths stored `0` for a blank price (see
[Context](#a-stored-price-of-0-usually-means-not-entered)). Open question 9 presents the two
treatments.

**Opt-out.** "Unknown by default" implies an operator may choose otherwise. Open question 11
asks whether the import command offers that choice.

### Gate 5: the incompleteness flags

Option B adds two documented flags and one column for the web overview:

| Flag | Schema and routes | `permission_fields` row | `WireBooleans::COLUMNS` | `DOCUMENTED_BOOLEANS` | `CONVERSION_COVERAGE` |
|---|---|---|---|---|---|
| `value_incomplete` | `CurrentStockResponse`: `GET /api/stock`, and `due_products`, `overdue_products` and `expired_products` of `GET /api/stock/volatile` | `('STOCK_PRICES_VIEW', 'stock_current', 'value_incomplete')` | `'stock_current' => ['is_aggregated_amount', 'value_incomplete']` | `'CurrentStockResponse.value_incomplete' => 'stock_current'` | `'stock_current.value_incomplete' => '/api/stock'` |
| `stock_value_incomplete` | `ProductDetailsResponse`: `GET /api/stock/products/{productId}` and `GET /api/stock/products/by-barcode/{barcode}` | `('STOCK_PRICES_VIEW', 'product_details', 'stock_value_incomplete')` | `'product_details' => ['is_aggregated_amount', 'stock_value_incomplete']` | `'ProductDetailsResponse.stock_value_incomplete' => 'product_details'` | `'product_details.stock_value_incomplete' => '/api/stock/products/9500'` |
| `value_incomplete` on `uihelper_stock_current_overview` | None; the stock overview page reads the view | `('STOCK_PRICES_VIEW', 'uihelper_stock_current_overview', 'value_incomplete')`, beside that view's `value` row | None | None | None |

The three maps are `services/WireBooleans.php`, and `DOCUMENTED_BOOLEANS` and
`CONVERSION_COVERAGE` in `tests/Pgsql/WireContractTest.php`.
`testEveryDocumentedBooleanIsAccountedFor()` fails until the first two agree with the
document. `testEveryConvertedPropertyIsProvenByAResponse()` fails until every converted
property has a route. The `permission_fields` rows go into `db/pgsql/prices-seed.sql`,
which the importer re-applies, and into the implementing migration. Migration 0282 applied
the seed file once, so it does not reach an existing installation.

No other response carries an aggregate stock value. `missing_products` reads
`stock_missing_products`, which has no price column. `stock_current_locations` has no
`value`. Recipes already carry `prices_incomplete`. `uihelper_shopping_list.last_price_total`
multiplies one price by an amount. `BookingEventPublisher` selects `product_id, amount, value`
by name, so a new `stock_current` column does not reach it.

A flag is `1` when any row whose price that `stock_current` branch sums has an unknown price:
`price IS NULL`, or the gate 1 marker under option (a). The rows are the product's own stock,
as question 1's answer requires. The `value` expression does not change:
`SUM(COALESCE(price, 0) * amount)` already sums known prices, and a marked `0` adds `0`
either way.

The flag can be computed in two places:

- **In `stock_current` (SQL).** Each `UNION` branch gains `value_incomplete` beside `value`.
  `GetCurrentStock()`, the three volatile lists, `GetProductDetails()` and
  `uihelper_stock_current_overview` read the same column. The unknown-price rule stays in SQL
  with the other readers listed under gate 1.
- **In `StockService` (PHP).** `GetCurrentStock()` runs a second query for products with an
  unknown-priced row and sets a PHP boolean. The stock overview reads
  `uihelper_stock_current_overview` through LessQL and would need the same rule a second time.
  The `DOCUMENTED_BOOLEANS` entries would be `'php'` and `WireBooleans` would not change.

**Recommendation:** compute it in `stock_current`, because one definition then serves every
reader. The consequences are:

- `difftest.php` gains `unset($row['value_incomplete'])` on the PostgreSQL side in its
  existing `stock_current` block, beside `amount_measured`, and in its existing
  `uihelper_stock_current_overview` block.
- `GetProductDetails()` sets `value_incomplete = 0` on its fallback row for a product with
  no `stock_current` row. It reads the column with `?? 0`, as it reads `amount_measured`,
  because the differential suite's rollback phase runs it against SQLite.

Open question 12 asks the maintainer to confirm the computing site.

### Gate 7: the rewritten views and the differential suite

Two properties of the harness decide what the views phase can see:

- `difftest.php` loads PostgreSQL through `DatabaseImporter::Import(true, false)`. With
  `applyRowMigrations` false, `RebuildPriceCaches()` and the gate 1 marker step do not run.
  The views phase therefore compares price caches copied from SQLite. A view that reads
  `cache__products_last_purchased` or `cache__products_average_price` cannot show a rewrite
  of `products_last_purchased` or `products_average_price`.
- No `stock` or `stock_log` row in the base fixture or the five view seeds is priced `0`. The
  unpriced rows are product 6 in `01_stock_basics.sql`, and `stock_log` rows in
  `04_chores_batteries_tasks.sql` and `05_users_and_journal.sql` that feed views not changed
  here.

| View | Rewrite under P2 and option B | Seed that compares it | Changes a value the frozen SQLite line computes for today's seeds | Treatment |
|---|---|---|---|---|
| `stock_current` | Adds `value_incomplete`; `value` unchanged | `01` | No value changes. The new column is a key SQLite lacks | Strip the column on the PostgreSQL side |
| `uihelper_stock_current_overview` | Projects `value_incomplete` | `01` | Same as `stock_current`; `last_price` and `average_price` come from the copied caches | Strip the column on the PostgreSQL side |
| `stock_next_use`, `uihelper_stock_entries` | Project the marker, if `products_current_price` reads it through `stock_next_use` | `01` | No values change. The marker is `0` on every row; SQLite's `s.*` carries it once the fixture adds it | Strip the marker on both sides in the existing block for these two views |
| `stock_splits` | Groups by the marker as well | `01` | No. Every marker is `0`, so the groups are unchanged | None |
| `products_price_history` | `price IS NOT NULL` and not marked, instead of `COALESCE(price, 0) > 0` | `02` | No. No row is priced `0`; `NULL` rows are excluded on both sides | Accepted difference D1 |
| `products_average_price` | Same filter | `02` | No, for the same reason | Accepted difference D1 |
| `products_last_purchased` | Drops `COALESCE(..., 0)`; can select a known `0` | `02` | No. Products 701 and 703 have only priced purchases, and 702 has none | Accepted differences D1 and D2 |
| `products_current_price` | A marked next-use entry falls back to the last known price; the fallback can be `NULL` | `02` | No. Every marker is `0`, and the fallback reads the copied cache | Accepted difference D2 |
| `recipes_pos_resolved` | `costs` is `NULL` when the position's product has no known current price | `03` | No. Every position resolves to product 3 at 1.50 or product 703 at 3.00 | Accepted difference D3 |
| `recipes_resolved` | `prices_incomplete` tests for a `NULL` cost instead of a `0` cost | `03` | No. No position costs exactly `0`, and every recipe resolves at least one position | Accepted difference D4 |
| `uihelper_product_details`, `uihelper_shopping_list` | Not rewritten; read the caches and `products_current_price` | `02`, `03` | No | None |

The `recipes_resolved` rewrite must keep today's result for a recipe with no resolved
positions. Today `MIN(COALESCE(rpr.costs, 0)) = 0` returns `1` for it, because the `LEFT
JOIN` yields one row with a `NULL` cost. A test of `rpr.costs IS NULL` over the same join
also returns `1`. A rewrite that counts only joined positions would return `0`, which this
record does not propose. The seeds were read for this: the meal plan trigger nests recipe
701 into every internal recipe it creates, and the migrations seed no recipes.

The proposed accepted differences name what each touches and whether it is exposed, as
ADR-0005's Consequences require. PostgreSQL is the conforming side in each, because the
OpenAPI descriptions written with this change define `0` as a known zero and `null` as
unknown. The frozen SQLite line cannot move.

- **D1.** A `stock_log` row priced `0` and not marked counts as a known price.
  `products_price_history` and `products_average_price` include it, and
  `products_last_purchased` can select it. The frozen SQLite line excludes it. Exposed:
  `products_average_price` and `products_last_purchased` are `ExposedEntity` values;
  `products_price_history` is read by `GET /api/stock/products/{productId}/price-history`;
  `avg_price` and `last_price` reach both product details routes.
- **D2.** A product whose purchases have no known price reports `null` from
  `products_last_purchased.price` and, with no known next-use price, from
  `products_current_price.price`. The frozen SQLite line reports `0`. Exposed through
  `products_last_purchased`, and as `last_price`, `current_price` and `oldest_price` on both
  product details routes.
- **D3.** `recipes_pos_resolved.costs` is `null` for a position whose product has no known
  current price; the frozen SQLite line reports `0`. Exposed: `recipes_pos_resolved` is an
  `ExposedEntity`.
- **D4.** `recipes_resolved.prices_incomplete` is `0` for a recipe whose positions all have
  known costs, some of them exactly `0`; the frozen SQLite line reports `1`. Exposed through
  `GET /api/recipes/fulfillment` and `GET /api/recipes/{recipeId}/fulfillment`.

No difftest normalisation maps a PostgreSQL value back to the SQLite one. Such a mapping
would also hide a regression in the same column. The harness only strips the columns that
exist on PostgreSQL alone, as it already does for `amount_measured` and the `opened_*`
columns. The cases D1 to D4 describe are tested in pgTAP and PHPUnit, not in the view seeds.
A seed row priced `0`, or a position without a known price, would make the views phase
report `DIFF`.

**Recommendation:** record D1 to D4 in this record, as
[ADR-0031](0031-barcode-identity-is-canonical-and-reserved.md) decision item 11 records its wire
changes, and leave ADR-0005's text unchanged. Open question 13 asks the maintainer to choose
between that and a superseding record that adds the exceptions to ADR-0005.

## Open questions

1. **Should a parent product's `value` include its sub-products?** `amount_aggregated`
   includes sub-product quantities, while the existing value query uses direct stock.

   > **Response:** datagen24, 2026-10-11: Prices and values do not roll up. A parent
   > product reports the value of its own stock only.

2. **When the next-use entry is unpriced, is `current_price` `null` or the last known
   price?** Today it falls back to the last purchase price. The OpenAPI description
   promises that fallback only when the product is not in stock. The selected answer
   retains fallback, so the description must change.

   > **Response:** datagen24, 2026-10-11: Keep the last-known-price fallback when the
   > next entry is unpriced.

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


   > **Response:** datagen24, 2026-10-11: Changes of meaning count for client coordination
   > under ADR-0024, even when the JSON type is unchanged. The server defines the
   > contract; affected maintained clients must interpret it correctly.

5. **How are known zero, unknown and redacted prices represented?**

   > **Response:** datagen24, 2026-10-11: Agreed: `0` is known zero, `null` is unknown,
   > and an absent field is redacted. Document this response in the API specification
   > so it is implemented correctly.

   No explicit redaction marker is introduced.

6. **How are existing and imported zero prices treated?**

   > **Response:** datagen24, 2026-10-11: The live database is a UI test database with
   > no valid production data. Historical grocy imports are the relevant preservation
   > concern. Treat imported legacy zeros as unknown by default, disclose that
   > interpretation during import, and preserve original booking values.

   The mechanism for storing the interpretation remains open; see open question 8.

7. **How is a mixed priced/unpriced total represented?**

   > **Response:** datagen24, 2026-10-11: Use the known subtotal with an added
   > `*_incomplete` flag, as in option B.

8. **How is an imported legacy zero recorded, and how does the import disclose it?** The
   candidates are (a) a per-row marker on `stock` and `stock_log`, (b) a row-id watermark,
   (c) a set of legacy `stock_id` values, and (d) a side table of row ids. The existing
   `import_epoch` cannot serve, because rows booked after an import share the imported
   rows' generation. The recommendation is (a), compared under gate 1. Disclosure has two
   forms: the progress output alone, or that output plus a row in a new target-owned table
   recording the import time, the rule applied and the count per table. The recommendation is
   both, so that an operator can read the interpretation after the terminal is gone.

   > **Response:** datagen24, 2026-10-12: Use the per-row marker on `stock` and
   > `stock_log`. Preserve stored prices. Produce both terminal disclosure and a persistent
   > import report containing the rule and counts. Constrain the marker to valid values and
   > require marked prices to equal zero. Test propagation through splits, edits,
   > compaction and undo.

9. **What does the upgrade migration do with price-`0` rows that Victual wrote before this
   change?**
   - Read them as known zeros. No row changes. This option is nearest the maintainer's
     2026-10-11 note that existing rows hold test data only. Blank web purchases made before
     the upgrade report a known price of `0`, the false statement issue 774 describes.
   - Read them as unknown. The migration applies the import's mechanism to every existing
     row priced `0` and reports the count per table. A deliberately free entry written
     through the API also becomes unknown, because no column records the writer. This
     extends response 6's rule, which covers imported legacy zeros, to rows Victual wrote;
     response 6 does not decide it.

   The recommendation is unknown. Five write paths stored `0` for a blank price, the same
   evidence that set the import default. Under (a) the migration changes the marker column
   only.

   > **Response:** datagen24, 2026-10-12: Treat pre-change zeros as unknown. Those rows
   > contain the same ambiguity as imported zeros because blank prices previously became
   > zero. Change only the marker and report counts. New explicit zeros mean known free
   > prices.

10. **What do routes that return raw `stock` and `stock_log` rows send for a marked price?**
    These are `GET /api/objects/stock`, `GET /api/objects/stock_log`,
    `GET /api/stock/entry/{entryId}`, the two `.../entries` routes,
    `GET /api/stock/bookings/{bookingId}`, `GET /api/stock/transactions/{transactionId}` and
    the booking responses.
    - Send `price: null`, mapped at the boundary where `FieldPolicy` redacts these rows, and
      keep the marker off the wire.
    - Send the stored `0` and expose the marker as a documented field.

    The recommendation is `null`, because the table under "Maintainer response and remaining
    choices" defines unknown as `null`. The stored `0` stays readable in the database.

    > **Response:** datagen24, 2026-10-12: Return `price: null` for a marked price and keep
    > the marker internal. Apply price-permission redaction consistently; an unauthorized
    > caller still receives an omitted field. Coordinate this semantic change with clients.

11. **Can an operator choose to import legacy zeros as known zeros?** "Unknown by default"
    implies an alternative. The options are no override in this change, or a
    `bin/victual-db-import` option that leaves the marker unset and is named in the
    disclosure. The recommendation is the option, because only the operator knows whether
    the source recorded free items as `0`.

    > **Response:** datagen24, 2026-10-12: Allow an explicit whole-import option to
    > interpret zeros as known. Default to unknown. Record the chosen policy in the
    > persistent report and explain that the override affects every zero, including
    > potentially unentered prices.

12. **Does `stock_current` (SQL) or `StockService` (PHP) compute the incompleteness flags?**
    The recommendation is `stock_current`, compared under gate 5.

    > **Response:** datagen24, 2026-10-12: Compute incompleteness in `stock_current`, so
    > API responses and the web overview use the same SQL rule.

13. **Where are the accepted differences D1 to D4 recorded?** The options are this record,
    as ADR-0031 decision item 11 records its wire changes, or a superseding record that adds
    them to ADR-0005's exceptions. The recommendation is this record, with no difftest
    normalisation of values, compared under gate 7.

    > **Response:** datagen24, 2026-10-12: Record D1 to D4 explicitly in ADR-0045, including
    > its precise relationship to ADR-0005. Do not rewrite the historical decision. Require
    > targeted tests exercising each difference; unchanged existing fixtures do not
    > demonstrate the new semantics.

## Acceptance prerequisites

1. P2's semantics and the import interpretation policy are confirmed. Before acceptance,
   the record must define how imported legacy zeros retain their original booking values
   while readers distinguish their unknown interpretation from new, known zeros. Gate 1
   under [Specification of the gates](#specification-of-the-gates-2026-10-12) defines the
   candidates, the write paths that must propagate the interpretation and its readers. Open
   questions 8 to 11 are answered (2026-10-12).
2. Option B and the answers to open questions 2 and 4 are confirmed.
3. A coordinated change for affected maintained clients is identified under ADR-0024.
   Clients read option B's flags, preserve absent versus present-null fields, and rely on
   zero meaning known zero only after legacy import interpretation is applied. This
   includes victual-kit without making that client the authority for the API contract.
4. Option A's additional value-normalisation and Influx requirements do not apply to
   the selected option B. The applicable differences remain covered by gates 5 and 7.
5. Under option B: the record names the flags' `permission_fields` rows, their
   `WireBooleans` entries, and whether `stock_current` or `StockService` computes them.
   Gate 5 names the flags, their rows and their entries in `WireBooleans`,
   `DOCUMENTED_BOOLEANS` and `CONVERSION_COVERAGE`. Open question 12 is answered:
   `stock_current` computes the flags.
6. Parent-only values and completeness are confirmed. The API descriptions must distinguish
   that scope from `amount_aggregated`, as defined above.
7. Under P2, or under option A or B: the record names the ADR-0005 accepted difference or
   difftest normalisation for each rewritten view (`products_price_history`,
   `products_average_price`, `products_last_purchased`, `products_current_price`,
   `recipes_pos_resolved`, `recipes_resolved`, as applicable). Import evidence must show
   original booking values unchanged and the disclosed interpretation applied consistently.
   Gate 7 gives the result and treatment per view, including `stock_current` and the
   `uihelper` views, and proposes accepted differences D1 to D4. Open question 13 is
   answered: D1 to D4 are recorded here, and each has a targeted test that exercises it,
   because the existing fixtures do not.

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
  migration test checks both price caches against the selected semantics. Import tests
  show that legacy zeros are interpreted as unknown, the importer discloses that policy,
  original booking values remain unchanged, and new known-zero purchases remain known.
  Under gate 1 option (a), one test per write path listed there books from a marked row and
  shows the new row carries the marker, and a compaction test shows a marked `0` and a
  known `0` stay separate.
- pgTAP for every view the change rewrites (`stock_current`, `products_last_purchased`,
  `products_price_history`, `products_average_price`, `products_current_price`,
  `recipes_resolved`), as `.devtools/pgtap/README.md`'s completeness list requires.
- Contract snapshot: `ContractTest`'s fixture gains an unpriced product and a mixed
  product before the stock reads. `contract-admin.json` and `contract-restricted.json` are
  regenerated, and `testRestrictedMatchesAdminMinusRedactedFields()` and
  `testSensitiveFieldVocabularyIsClassified()` pass.
- `WireContractTest`: `CurrentStockResponse` and `ProductDetailsResponse` validate
  against a served response for case (a). Under option A the response carries `null`;
  under option B it carries the flags, and `DOCUMENTED_BOOLEANS` and
  `CONVERSION_COVERAGE` list them.
- Differential suite: The views phase passes with the recorded differences or
  normalisations of prerequisite 7, plus the strip line (option B) or the `value`
  difference (option A). Under gate 7's recommendation the only harness changes are the
  strip lines named there.
- Browser probe: A probe in `.devtools/frontend/` shows that the stock overview and the
  product card display an unknown value for case (a) and `0.00` for case (e). Under P2, it
  also shows that a blank purchase price is sent as `null`.
- Influx, option A only: A publisher test shows that an unknown value is not
  published as `0.0`.
