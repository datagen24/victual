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
| A delivered event landed on the right day | 532 priced purchases | `price_paid` multiset keyed by (product, **day**, price, amount) | one point rewritten one simulated day later, every tag and value identical | **Caught**: `unexpected point rolls\|2024-12-04\|1.3700\|1; missing 1 x rolls\|2024-12-03\|1.3700\|1` |
| An undone purchase removed *its own* lot | 2 undos | the lot assertion | *(no injection needed — a year run found it)* | **Caught**: the model had been undoing by FIFO consume, which agrees on every total and disagrees on which lot survives. 274 days of ledger, log, position and average-price invariants passed while the model held a lot the application had deleted. |
| A conversion factor applied to a booking | sub-product substitution | `rowsSum` on the consume: ask the parent for 1 pack, expect 500 g removed from the child | the stored factor set to 400 while the plan still expected 500 | **Caught**: `the booking moved -400, the plan intended -500` |
| Stock is in the right *place* | 53 transfers | `every product sits where the ledger put it` — per (product, location), monthly **and** at the end | one unit moved to another location | **Caught**: `fish@freezer: live 101, ledger 102; stock of 1 at 14@8 that the ledger does not place there` |

The last row is why this table was worth building: before it, an injected location change was
**not caught by any end-state invariant**. Position was asserted at monthly checkpoints during
the replay and nowhere afterwards, so a divergence introduced after the last checkpoint
survived to the end of the run unnoticed. The end-state position check exists because the
injection found that.

### Partial-lot consumption — now asserted

After an operation that had **more than one lot to choose between**, the remaining lots are
read back and compared as a whole against the model, which mirrors `stock_next_use`'s
ordering exactly: amount, best-before date, purchased date, price, open flag and location.
Totals are identical whichever lot was drawn; the dates and prices left behind are not, which
is what this compares.

Two things a year run established that were not obvious, and how each is handled:

**The application does not define which of two tied lots is consumed, and a transfer creates
tied lots routinely.** `stock_next_use` orders by `(default consume location match, open,
best-before, purchased date)` with no total tie-break. A transfer splits one lot into two
sharing all four, differing only by location — and location enters the ordering only through
the default-consume-location term, which is equal for both unless one of them sits at the
product's default consume location. So the next consume's choice between them is arbitrary
and two engines may differ. Day 169 of a year run is what found it: butter, transferred
fridge to freezer, then consumed from the half the model had not picked.

The first response was to stop asserting lots for that product entirely, which was too
broad — a tie leaves the amount removed, the product total, the tied group's own total and
the set of locations that group may occupy all exactly determined, and withdrawing the whole
assertion gave up every one of them to accommodate the single thing that is open. So the
**expectation is narrowed instead**: lots outside the tie are compared exactly, and each tied
group is compared against the outcomes the application permits (its total, its allowed
locations, its allowed prices). The model carries the ambiguity rather than resolving it — it
never publishes its own guess at the split and never reads the application's choice back to
adopt it, because either would introduce the deterministic tie-break the application does not
have and make every later assertion agree with whatever the build under test happened to do.

**"No price" has three representations and they are not interchangeable.** An entry created
with an omitted price, an explicit `null`, or an explicit `0` reads back from
`/stock/products/{id}/entries` as `null` in some situations and `0` in others. An earlier
version reacted by treating the two as one value everywhere, which generalised a single
observation into a rule.

The equivalence is now scoped to the one place it is justified — comparing a **valuation**,
where the price views themselves coalesce (`COALESCE(price, 0) > 0`) — while the raw values
stay in the trace and every representation difference is reported as an observation rather
than folded away. The smoke year reports two, both on Bread.

That mattered on the first run of the fixture built to test it. `price_paid` gates on
`price === null` (`services/Influx/BookingEventPublisher.php:795`), not on `> 0`, and the
publisher says why at `:191` — "a booking with no price is not a booking at a price of
nothing". So an explicitly-zero-priced purchase publishes a `price_paid` point of 0.0000 and
an unpriced one publishes none, while `products_average_price` excludes both. The suite's
delivery oracle had borrowed the view's rule and counted one point too few; the fixture
surfaced it as an unexpected `butter|0.0000|5` point. **The two surfaces genuinely disagree
about whether unknown and zero are the same thing**, which is exactly why the equivalence
could not stay global.

### The conversion fixture

`narrative/conversions.js` closes what was the largest gap, and correcting *why* it was a gap
mattered more than closing it. The list here used to say conversions could be exercised by
"purchasing in the purchase unit". They cannot: `AddProduct`
(`services/StockService.php:211`) applies no purchase-to-stock conversion at any point, so
the `amount` on `POST /stock/products/{id}/add` is in the **stock unit** and always was.
Sending 1 for a 500 g pack books one gram, and an assertion expecting 500 would have failed
against correct behaviour.

The factor is read in three places and only one changes what a booking removes:

- **Product details**, as `qu_conversion_factor_purchase_to_stock` (`:1129`) — display only.
- **The shopping list's print path** (`:1681-1687`) — not on a JSON endpoint.
- **Sub-product substitution** (`:620-623`, `:658`, `:1504`) — the only path where a factor
  moves stock.

So the fixture builds the pair the household does not otherwise have: a parent stocked in
packs holding nothing of its own, a child stocked in grams, and one product-specific
pack-to-gram factor of 500. Asking the parent for one pack with
`allow_subproduct_substitution` books 500 grams against the child, and `rowsSum` is what says
so — a wrong factor changes that number and nothing else in the suite would notice, because
every total, position and price stays consistent with whatever the factor claims.

**One conversion row, two directions, both asserted.** `stock_current` aggregates a parent's
children into the *parent's* unit and joins the conversion the other way round — `from_qu_id`
= the child's stock unit, `to_qu_id` = the parent's (migration 0233:23-26). Nothing was
written for that direction; `quantity_unit_conversions_resolved` derives the inverse of every
row it finds. So the parent reports 2 packs for 1000 grams, and after the consumes it reports
0.5 — a quantity that is not a whole number of packs, where an integer division would report
zero.

### The price-representation fixture

`narrative/prices.js` runs after the year, on a product with no default consume location, and
buys the same thing three ways — price omitted, price `null`, price `0` — then does to those
lots what the year does to everything else: a partial consume, a partial transfer, a consume
that has to choose, and an edit.

| What it asserts | Why |
|---|---|
| amount, best-before, purchased date, location, open — exactly | the operation determines them |
| the price's *valuation* | the price views coalesce null and 0, so they are equal there |
| the tied pair the partial transfer creates — total, allowed locations, allowed prices | built to produce a tie deliberately, rather than waiting for the narrative to stumble into one |
| `price_paid` presence, by the publisher's own null rule | the surfaces disagree; each is modelled by its own rule |
| *not* which representation reads back | nothing establishes it — the raw row stays in the trace |

## A known instability, and what it is

A year run does not always finish. When it does not, the failure is always the same and it is
**not** in the harness:

- nginx answers 504, `upstream timed out … while reading response header from upstream,
  fastcgi://127.0.0.1:9000` — every php-fpm child has stopped responding;
- PostgreSQL is healthy throughout: one backend, `idle`, `wait_event = ClientRead`, its last
  statement completed. Nothing is blocked, nothing is waiting on a lock;
- the pod is at 0% CPU.

The trigger is the clock step, and the evidence is unambiguous. Over 260 replayed days one
run logged eight `PDOException: SQLSTATE[08006] … connection to server at "postgres" … timeout
expired`, and **every one of the eight is stamped exactly `09:00:00`** — the instant the
timestamp file is rewritten. libpq computes its connect deadline as `time() + connect_timeout`
and re-checks it against the wall clock; a worker whose faketime cache lapses between those
two points sees the deadline a simulated day in the past and reports a timeout against a
server that is answering immediately. That is an artefact of faking the clock, not a defect in
the fork.

**What the fork does contribute is the escalation.** The stack trace shows the fatal thrown
from `ExceptionController->__construct()` → `BaseController->__construct()` →
`DatabaseService->GetDbConnection()`: the error path opens a database connection of its own,
so a connect failure cannot be rendered as a 500 and becomes an uncaught fatal instead. That
is worth fixing independently of this suite — a brief database outage should produce an error
page, not a dead worker.

Runs do complete: a committed run records 365 steps, zero clock violations, every invariant
passing, in 797 seconds. The instability is intermittent and is recorded here rather than
worked around, because a mitigation that hid it would also hide the escalation.

## Asserted, not yet demonstrated

Mechanisms with a real independent assertion, where no defect has been injected to prove the
assertion fires.

| Behaviour | Operation | Independent assertion |
|---|---|---|
| Every booking moved its intended amount | every stock operation | `rowsSum` — the signed total, from the response itself |
| A recipe consumed its ingredients, nested ones included | every `cook` | `POST /recipes/{id}/consume` answers 204, so the evidence is the state it left: a stock read **and** a lot read per affected product, named by the recipe. `requirements()` resolves nesting, so the model knows what a nested recipe should have drawn and by how much. |
| An edit carried its prior consumption | 11 edits/year | the average-price oracle's `edited_origin_amount` term — the edited amount plus what had already been drawn from that entry. The generator now chooses both the product and the entry so that term is non-zero: all 11 edits in a year land on an entry already consumed from, where previously 1 landed and it was untouched. The entry is also named by its own dates with a uniqueness assertion, rather than taken as the lowest id while the model meant the FIFO-first one. |
| A transfer moved stock to the right place, at the step | every transfer | the lot assertion, whose `LOT_FIELDS` include `location_id` and which is emitted after every transfer. This was listed as a gap until the lot work closed it: per-operation checks previously asserted only the product total, which a transfer never changes. |
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
| **Undo dependencies** | 2 undos per year | Deliberately avoided, and the precondition is now the right one: `UndoBooking` refuses when the purchased entry has later bookings against it (`services/StockService.php:2064`) and otherwise deletes that entry whole (`:2078`), so the generator only emits an undo when the model still holds that entry intact. The **refusal** is the interesting case and nothing asserts it — no operation in the year expects `Booking has subsequent dependent bookings, undo not possible`. |

## What would close them

In the order the gaps are worth closing:

1. **Bring undo dependencies into scope** with their own isolated fixture and an explicitly
   stated expected outcome, as the FIFO tie probe already does.
