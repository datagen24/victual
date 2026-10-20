# ADR-0050: Shopping-list workflows show product barcodes, and stock shortfalls need STOCK_VIEW

- **Status:** Accepted, 2026-10-18
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-18, against `master` at `033f9912`. It records the maintainer's two
  decisions of 2026-10-18 on [issue 933](https://github.com/datagen24/victual/issues/933).
- **Referenced by:** [issue 933](https://github.com/datagen24/victual/issues/933).

## Context

[ADR-0018](0018-role-grants-and-domain-reads.md) gives stock and shopping lists separate
view leaves: `STOCK_VIEW` and `SHOPPINGLIST_VIEW`. Elsewhere, reading the
`product_barcodes` table requires `STOCK_VIEW` (`EntityReadPolicy`). The shopping-list
pages, the item picker and `GET /api/objects/uihelper_shopping_list` gave a
`SHOPPINGLIST_VIEW`-only caller two stock-domain facts:

- **Product barcodes.** They appear in the list's HTML, in the API row's `product_barcodes`,
  and in the item picker's search data for every active product.
- **Stock shortfalls.** The page showed the household-wide count of products below their
  minimum stock amount, a marker on each listed product below it, and a filter for them.
  Only the stock feature flag hid these, not a permission.

## Decision

1. **Barcodes are part of the shopping-list workflow.** `SHOPPINGLIST_VIEW` permits
   barcode display and scanning or search within the shopping-list pages, the item picker
   (including active products not yet on the selected list) and the shopping-list API
   response. If someone can see the shopping list, they can scan a barcode to add to it.
   Reading the `product_barcodes` table in general still requires `STOCK_VIEW`.
2. **Shortfalls are stock information.** The shortfall count, the per-row below-minimum
   markers and the shortfall filter require `STOCK_VIEW`. A `SHOPPINGLIST_VIEW`-only caller
   gets none of them, through the page HTML or a supporting response. This includes
   `POST /api/stock/shoppinglist/add-missing-products`: the items it adds are exactly the
   shortfall set, so it requires `STOCK_VIEW` in addition to `SHOPPINGLIST_ITEMS_ADD`.

The `STOCK_PRICES_VIEW` redaction of shopping-list prices is unchanged.

## Consequences

- A household member who only shops can still add items by scanning, but no longer sees
  which products are below their minimum, or why an item was added for that reason.
- A caller holding `SHOPPINGLIST_ITEMS_ADD` without `STOCK_VIEW` gets 403 from
  add-missing-products, where it used to get 204. This is a deliberate wire change.
- Products added automatically when a stock booking leaves them below their minimum
  (`shopping_list_auto_add_below_min_stock_amount`) still appear on the list. The booking
  that adds them is made by a caller with stock permissions, and the list rows carry no
  marker saying why they were added.
