# Issue 648: how a generated Swift client decodes the `GET /objects` unions

Measured 2026-10-12 with swift-openapi-generator 1.14.0, OpenAPIKit 6.4.0 and
swift-openapi-runtime 1.13.0 (pinned by `Package.resolved`), Apple Swift 6.4, macOS on Apple
Silicon. Reproduce with `.devtools/issue648/run.sh [document]`; the first run resolves the
packages from GitHub.

`prepare.py` copies the two union schemas into `components` so that the generator names them,
and builds one row per listable entity. Keys come from the recorded response shape in
`tests/Pgsql/snapshots/contract-admin.json`, or from the declared properties of the entity's
mapped member where nothing is recorded (`shopping_list`, `uihelper_shopping_list` and
`userfields`). Values are typed placeholders. `main.swift` decodes each row as the generated
client would and prints the members that decoded it.

## Results

| Document | Union | Result |
|---|---|---|
| `master` at `7b486823` | `oneOf`, 10 members (9 on the single-object route) | Each row decodes as the first member in order that accepts it. `quantity_units` decodes as `Location`. `stock`, `stock_log`, `product_barcodes` and `product_barcodes_view` decode as `ShoppingListItem`. On the single-object route, `locations_resolved` also decodes as `ShoppingListItem`. |
| branch `claude/sonnet5_issue648-union-members-b8d3` at `c26b3303` | `anyOf`, 13 members (12), with `x-victual-entity-schemas` | 0 of 13 mapped entities fail to decode into their own member, on either route. Most rows also decode into other members. |

The `master` results contradict the statement that the members are mutually exclusive in a
strict client. That statement holds for a JSON Schema validator, but not for this generator,
for two reasons:

- **A required but nullable property is optional in Swift.** The generator emits it as an
  optional and decodes it with `decodeIfPresent`, so the key may be absent. `ShoppingListItem`
  therefore requires only `id` in practice, and `Location` only `id` and `name`.
- **Extra keys are ignored unless a schema is closed.** A row with more keys than a member
  declares still decodes into that member.

## Alternatives measured and rejected

- **`not` on `ProductBarcodeView` and `ShoppingListItem`.** The generator reports
  `Schema "not ()" is not supported` and skips both schemas. Through them it also skips both
  unions and the 200 response bodies of `GET /objects/{entity}` and
  `GET /objects/{entity}/{objectId}`.
- **A boolean `false` property schema**, meaning "this key must be absent". OpenAPIKit
  refuses to parse the document.
- **`additionalProperties: false` on all thirteen members (option E).** Rows that carry a
  subset of a member's keys still decode into that member. For example,
  `product_barcodes_view` decodes as `ProductBarcode`, because the timestamp `ProductBarcode`
  requires is nullable and so optional in Swift.

A client therefore selects the member by the entity it requested. The union carries that
mapping as `x-victual-entity-schemas`, and `tests/Pgsql/WireContractTest.php` pins it to
`UNION_MATCHES`.
