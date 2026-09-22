# Stock

What is in the house, where it is, and the four bookings that move it: purchase, consume,
transfer and inventory. Requires `FEATURE_FLAG_STOCK`.

## Stock overview

`/stockoverview` lists products currently in stock, or below their minimum stock amount —
or every active product, if your [user setting](administration.md#user-settings)
`stock_overview_show_all_out_of_stock_products` is on — plus any product group that is
itself below its own minimum. This is the default landing page (`ENTRY_PAGE`).

## The four stock actions

Each has its own page, and each only offers products that make sense for it.

| Page | Route | Offers |
|---|---|---|
| Purchase | `/purchase` | Any product. Records a due date, price and location for the batch you are adding. |
| Consume | `/consume` | Only products currently in stock. Books a booking; can consume "spoiled" instead of eaten, which is tracked separately. |
| Transfer | `/transfer` | Only products that currently have stock of their own (not a product whose stock lives entirely under a parent). Moves an amount from one location to another. |
| Inventory | `/inventory` | Any product. Sets the stock amount to a stated value rather than adding or removing a delta — the stocktaking booking. |

A barcode scan on any of the four pre-selects the matching product; scan mode
(`scan_mode_purchase_enabled` / `scan_mode_consume_enabled`, per-user settings) submits the
form automatically after a scan instead of waiting for you to press the button. See
[Barcodes and scanning](../operator/barcodes-scanning.md).

**Opening a container.** When `FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING` is on, an entry
can be marked opened, optionally with a measured remainder; a scale button appears on the
stock entries page wherever the product's coherence rules permit it. The opened state backs
"treat opened as out of stock" and per-container tare weight corrections on a location. A
booking can be undone from the stock journal.

## Stock entries and the journal

- **`/stockentries`** lists individual stock entries (batches) rather than the per-product
  totals the overview shows — due date, location, opened state and amount for each one.
  From here you can edit an entry (`/stockentry/{id}`) or request a label through the
  [label subsystem](../operator/label-printing.md). Its API can reprint a job
  from retained bytes or cancel a job; cancelling a job does not void a label identity.
- **`/stockjournal`** is the full booking history: every purchase, consume, transfer,
  inventory and open, each undoable individually. Filter by product or by how many months
  back to load. **`/stockjournal/summary`** aggregates the same history by product.
- **`/stockreports/spendings`** reports what was spent, from the same booking history.

## Products and their master data

- **`/products`** / **`/product/{id}`** — the product list and edit form: name, quantity
  unit, purchase and stock quantity unit (with a conversion between them if they differ),
  default due days, minimum stock amount, product group, and default location. It also
  holds the per-product presets used by the pages above: quick consume amount, default
  stock label type, and "treat opened as out of stock".
- **`/locations`** / **`/location/{id}`** — where stock physically lives. Locations can
  nest (a shelf inside a fridge inside a room); a location can also carry a tare weight and
  quantity unit for scale-based replenishment. **`/locationcontentsheet`** prints what
  should be in one location, for a physical count against it.
- **`/shoppinglocations`** / **`/shoppinglocation/{id}`** — where you buy from (stores),
  used on price history and purchase records; unrelated to stock *locations* above despite
  the similar name.
- **`/quantityunits`** / **`/quantityunit/{id}`** — units of measure. **`/quantityunitconversion/{id}`**
  edits a conversion between two units for one product (e.g. "1 bag = 500 g"); the resolved
  table of every such conversion is at **`/quantityunitconversionsresolved`**.
  **`/quantityunitpluraltesting`** is a developer utility for checking plural-form strings,
  not a page a household needs day to day.
- **`/productgroups`** / **`/productgroup/{id}`** — groups can nest, and a group's own
  minimum stock amount rolls up from its members' shortfalls.
- **`/productbarcodes/{id}`** edits one barcode-to-product mapping. **`/productsubstitutions/new`**
  records a directed substitution (e.g. "coffee grounds substitute for beans", never the
  reverse) used by recipe fulfilment.

## Labels

- **`/locationlabels`** is a stateless barcode/Grocycode scanner for looking up what a
  label resolves to, without signing any booking to it.
- **`/labelprinters`** and **`/labelprintjobs`** administer the label subsystem's printers
  and print job queue; **`/labeltemplates`** and **`/labeltemplate/{id}`** are the label
  designer. All four need `FEATURE_FLAG_LABELS`. See
  [Label printing](../operator/label-printing.md) for printer setup and print operations.

## Settings

**`/stocksettings`** holds the household-wide stock defaults: decimal places for amounts
and prices, the "expiring soon" day threshold, default purchase/consume amounts, and the
shopping-list-integration options covered in [Shopping lists](shopping-lists.md).
