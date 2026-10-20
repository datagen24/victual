# What a client must adopt from 0.5.0 (#891, gate M7)

The server changes merged since v0.4.4 that change what a client sends or receives. Compiled
2026-10-18 from the merged PRs, at master `dbfea56b`. Changes with no wire effect (#981, a CLI
exit code; #984, an internal marker; #996, web pages and InfluxDB; #998, a merge waiting on a
lock) are left out.

## Must handle

| PR | Issue | Change | Client obligation |
| --- | --- | --- | --- |
| [#985](https://github.com/datagen24/victual/pull/985) | #774 | `GET /api/stock` rows carry `value_incomplete`; product details carry `stock_value_incomplete`. `stock_value` / `value` is the sum of the **known** prices only. A price stored as 0 but never entered reads as `null`. `last_price`, `avg_price` and `current_price` are `null` when no price is known (they used to be 0). Recipe `costs` leave unknown prices out, and `prices_incomplete` is 1 only for an unknown cost, not for one that is exactly 0 (ADR-0045 D1–D4). | Show an incomplete marker when the flag is true. Treat `null` as unknown and `0` as a known zero (P2). Never coerce `null` to `0`. A caller without `STOCK_PRICES_VIEW` gets neither the value nor its flag. |
| [#980](https://github.com/datagen24/victual/pull/980) | #775 | The five stock bookings (purchase, consume, transfer, inventory, open) and their by-barcode wrappers accept an optional `Idempotency-Key` header. A repeated key with the same body replays the original answer; the same key with a different body is refused. Transaction ids are random (ADR-0046). | Send a fresh key per user action, and reuse it only to retry that action after a timeout. Don't parse or order transaction ids. ADR-0046 lists the client obligations. |
| [#999](https://github.com/datagen24/victual/pull/999) | #908 | `POST /api/batteries/{id}/charge` from a caller without `BATTERIES_VIEW` answers `{"id": <charge cycle id>}` only. With `BATTERIES_VIEW` it is the full row, as before. | Read `id` from either shape. Don't expect the other fields unless the account can view batteries. |
| [#1000](https://github.com/datagen24/victual/pull/1000) | #933 | `POST /api/stock/shoppinglist/add-missing-products` needs `STOCK_VIEW` as well as `SHOPPINGLIST_ITEMS_ADD`, and answers 403 without it (it used to answer 204). ADR-0050. | Hide or disable "add products below minimum" for an account without `STOCK_VIEW`, and handle the 403. Barcodes on shopping-list responses are unchanged. |
| [#1001](https://github.com/datagen24/victual/pull/1001) | #952 | A quantity unit conversion write (generic API, or a barcode lookup with `add=true`) answers **400** with a message beginning "This quantity unit conversion would make" when a factor, or one reached through other conversions, or its inverse, falls outside 2^-1022..2^1022. | Show the server's `error_message`. Don't retry. |

## Stricter input: 400 where there used to be a silent coercion

| PR | Issue | Change |
| --- | --- | --- |
| [#976](https://github.com/datagen24/victual/pull/976), [#977](https://github.com/datagen24/victual/pull/977) | #946, #947, #950, #892 | Numeric body fields are validated before the request purifier rewrites them. `"2 "`, `1e309`, a 400-digit number and similar values answer 400 instead of being dropped or rewritten. Shopping-list remove refuses a negative `product_id`. |
| [#979](https://github.com/datagen24/victual/pull/979) | #916 | `"NaN"`, `"Infinity"`, `"-Infinity"`, `"inf"` and JSON numbers outside the double range answer 400 on every numeric field. |
| [#983](https://github.com/datagen24/victual/pull/983) | #944 | Label worker registration answers 422 `LabelRefusal`, naming the path, for a NUL byte in any string. This concerns label workers, not the iOS client. |

A client that sends well-formed numbers needs no change, but should surface these 400s rather
than assume success.

## Authorization

| PR | Issue | Change |
| --- | --- | --- |
| [#978](https://github.com/datagen24/victual/pull/978) | #920, #905 | A write that changes which records own a userfile (setting or clearing a file userfield, deleting or merging the owning record) answers 403 unless the caller can read every current owner. ADR-0048. |

## Not for the iOS client

- [#982](https://github.com/datagen24/victual/pull/982) (#937): the MCP sidecar reports a 2xx booking answered with no transaction row as `outcome_unknown`.
