# What the year actually establishes

Four columns, and the fourth is the one that matters: **behaviour → the operation that
exercises it → the assertion that is independent of the application → the defect that
assertion has been *demonstrated* to catch.**

A row with an operation and no distinguishing assertion is a row where the suite proves the
endpoint answered and nothing else. Those rows are listed too, and marked, because a coverage
table that only recorded finished work would be the same kind of false pass the invariants
were rewritten to remove.

"Demonstrated" means a defect was injected into a database holding a completed year and the
named assertion failed. Injections are DB-level (`UPDATE`/`INSERT` behind the application's
back), which stands in for what a defective build would leave behind; they are not a
substitute for a mutation of the application itself.

## Demonstrated

| Behaviour | Operation | Independent assertion | Injected defect | Result |
|---|---|---|---|---|
| Stock arithmetic composes over a year | ~1,800 bookings | `stock matches the shadow ledger` — a model that never read the database | 5 phantom units `INSERT`ed into `stock` | **Caught**, twice: the ledger check and the log identity, both naming the product |
| The log and the table agree | every booking | `sum(stock_log) equals stock, per product`, using the contribution function (`product-opened` contributes 0, `stock-edit-old` negated) | a `consume` row flipped to `undone=1` | **Caught**: `milk: stock 2, log implies 3` |
| Each type moved what was booked | all nine types | `every transaction type moved what the plan booked` — summed amounts, both directions | the same flipped row | **Caught**: `consume: log sums to -85297, plan booked -85298` |
| Price arithmetic over a year | 532 priced purchases | `products_average_price matches the ledger, to 4dp`, modelling the view's edited-entry rule | a purchase price raised by 0.50 | **Caught**: `tomatoes: view 1.6325, ledger 1.6230` |
| Stock is in the right *place* | 53 transfers | `every product sits where the ledger put it` — per (product, location), monthly **and** at the end | one unit moved to another location | **Caught**: `fish@freezer: live 101, ledger 102; stock of 1 at 14@8 that the ledger does not place there` |

The last row is why this table was worth building: before it, an injected location change was
**not caught by any end-state invariant**. Position was asserted at monthly checkpoints during
the replay and nowhere afterwards, so a divergence introduced after the last checkpoint
survived to the end of the run unnoticed. The end-state position check exists because the
injection found that.

## Asserted, not yet demonstrated

Mechanisms with a real independent assertion, where no defect has been injected to prove the
assertion fires.

| Behaviour | Operation | Independent assertion |
|---|---|---|
| Every booking moved its intended amount | every stock operation | `rowsSum` — the signed total, from the response itself |
| Each operation left the intended state | ~275/year sampled, always after open/transfer/inventory/undo/edit/spoil/self-production/tare | a read asserting `stock_amount` against the ledger |
| Partial opening | ~52 opens | `stock_amount` unchanged **and** `stock_amount_opened` up by the opened quantity |
| Events reached InfluxDB | 532 priced purchases | `price_paid` count **and** a multiset of (product, price, amount) |
| Both halves of an event were delivered | 1,767 events | every `price_paid` event_id also appears in `stock_value` |
| Undo does not rewrite history | 2 undos | the already-published `price_paid` still present |
| Undo keeps its audit trail | 2 undos | each named by bound id: undone, timestamped, retained; and nothing else undone |
| The broker holds current state | year end | retained `victual/state/stock` agrees with the ledger's product count |
| Purchase dates are real dates | 532 purchases | `price-history` covers every day the plan bought on |
| The clock actually moved | 365 steps | the pool must agree on the new time before any operation; failure is INCOMPLETE |

## Gaps — operations without a distinguishing assertion

These run. Nothing establishes they ran *correctly*.

| Behaviour | Operation | Why nothing distinguishes it |
|---|---|---|
| **Partial-lot consumption** | ~1,040 consumes, many spanning entries | `rowsSum` and every total are identical whether the app drew from the correct lot or a different one. Which lot was consumed decides the *price* on the booking and the remaining best-before dates, and neither is asserted. `products_average_price` is unaffected by consumption, so it cannot cover this. |
| **Quantity-unit conversions** | bread 1 piece = 18 slices, coffee/pasta 1 pack = 500 g | **Not exercised at all.** The fixture configures the conversions, but every purchase sends an amount already in the *stock* unit (bread `amount: 18`, pasta `amount: 500`), so the factor never participates. A conversion that was silently wrong would change nothing this suite sends or checks. |
| **Nested recipe transactions** | `Sunday lunch` nests roast + soup; `Leftovers` nests pasta bake | `POST /recipes/{id}/consume` answers 204, so there is no booking to assert `rowsSum` against, and `cook()` emits no post-state read. A failure to consume the *nested* recipe's ingredients is caught only in aggregate, at the next monthly checkpoint, attributed to a product rather than to the recipe. |
| **Edits after consumption** | 1 stock-entry edit per year | The average-price oracle *does* model `edited_origin_amount` as the edited amount plus what was consumed from that entry first — so the arithmetic is asserted. But the generator picks the first entry in FIFO order, so whether that entry had prior consumption is incidental; the interesting case is not guaranteed to occur. The edit also carries no `rowsSum`. |
| **Undo dependencies** | 2 undos per year | Deliberately avoided: the generator only emits an undo when the model still holds the purchased stock, because undoing a booking whose stock has been consumed drove `stock_log` to imply a negative balance. The dependent case is the interesting one and is currently out of scope rather than covered. |
| **Transfers, per operation** | 53 transfers | Per-operation, only the product total is asserted — which a transfer never changes. Position is asserted monthly and at the end, so a wrong transfer is caught within a month rather than at the step. |

## What would close them

In the order the gaps are worth closing:

1. **Assert the lot.** After a consume, read `/stock/products/{id}/entries` and compare the
   remaining entries — count, amounts, best-before dates — against the shadow ledger, which
   already models `stock_next_use` ordering exactly. This is the largest gap: it is the most
   frequent operation in the year and the one with the least distinguishing evidence.
2. **Exercise conversions.** Purchase in the purchase unit and expect `rowsSum` to be the
   converted amount. Nothing tests the factor today.
3. **Assert nested recipes.** Emit a post-state read per affected product after a recipe
   consume, so the failure names the recipe rather than surfacing as a product total a month
   later.
4. **Force the edit-after-consumption case**, rather than letting FIFO order decide it.
5. **Bring undo dependencies into scope** with their own isolated fixture and an explicitly
   stated expected outcome, as the FIFO tie probe already does.
