# Consumption recipes

A consumption recipe is a list of product quantities that you consume together, such as a
prescription you have entered. **`/consumptionrecipes`** lists the recipes you own or that someone
shared with you. It needs only the `STOCK_VIEW` permission to open. The menu entry appears when
`FEATURE_FLAG_STOCK` is on, which it is by default. As for the other stock pages, the page
itself stays reachable by its address when the flag is off. What you can do on a recipe depends
on your rights on it (see [Sharing](#sharing)).

Victual records what was consumed and what is on hand. It does not schedule anything, remind
you, calculate an amount or give advice about how to take any product. See
[ADR-0015](../../adr/0015-medication-records-never-advises.md).

## Who can see a recipe

Only its owner and the users it is shared with can see a recipe, its lines and its history.
Belonging to the household grants no access, and an administrator has no automatic access
either: a recipe you cannot see is absent from your list and answers "not found", the same
as a recipe that does not exist.

Two things are not private:

- **Stock.** Anyone who can see stock sees the products, amounts and locations, including those
  a recipe uses.
- **Consumption bookings.** A recorded consumption is an ordinary stock booking. It appears in
  the stock journal with its products, amounts and time, under one transaction. The booking
  does not name the recipe, but a reader of the journal can see that those products left stock
  together.

An administrator who can change another user's password can sign in as that user and so see
their recipes. [ADR-0014](../../adr/0014-administering-a-user-is-a-subset-question.md) accepts
that for a household instance.

## Creating and editing

Choose **New consumption recipe**, give it a name, and add one line per product. A line has a
product, an amount and a unit. The units offered for a product are its stock unit and every
unit with a conversion to the stock unit, which you enter under the product's quantity unit
conversions. Victual does not guess a conversion: a unit with none is not offered, and a
conversion removed later makes the recipe refuse to be recorded until you fix the line.

Changing a recipe does not change what past consumptions booked. Each recorded consumption
keeps the products and amounts it took.

## Recording a consumption

**Record consumption** books every line at once, or none of them. If one product has too
little stock, a unit has lost its conversion, or you lack a right, nothing is booked and no
entry is made in the history.

Leave **Take from** on *Any location* to use the usual order (earliest due date first), or
choose a location, for example one weekly organizer, to use only stock there. If that location
holds too little, the consumption is refused; Victual does not take the difference from
another location. Filling an organizer is a transfer in [Stock](stock.md), which moves stock
and does not consume it.

The booked date is the date in your browser's time zone when you press the button.

## Weekly organizers

A weekly pill organizer, or two or three of them for a trip, is an ordinary
[location](stock.md): create one location per organizer and print its label from the location
page. The stock stays tracked, product by product, while it is in an organizer.

- **Filling an organizer** is a transfer on `/transfer` from the location that holds the stock to
  the organizer. It moves stock and books no consumption, so the household total does not change.
- **Recording a consumption from an organizer** uses **Take from**, or the location on `/consume`.
  Only stock in that location is used. If it holds too little, the consumption is refused; the
  difference is never taken from another organizer or from the cabinet.
- **Returning unused contents** is another transfer, from the organizer back to the cabinet.
- **Any location** uses the product's default consume location first, if it has one, and then the
  earliest due date across every location, organizers included. Set a product's default consume
  location to the organizer in use, or choose **Take from**, when a particular organizer must be
  the one charged. Rows with the same due date and purchase date have no defined order.
- **Distinct strengths** are distinct products. Each has its own stock and its own conversions.
- **Tablets, liquids and single-use items** are all stock in the product's stock unit: tablets,
  millilitres or single items. A bottle or a box is a purchase unit converted to that unit by a
  conversion you enter.

### Choosing the source from the API

Both consume routes take a source location. `POST /api/stock/products/{productId}/consume` and
`POST /api/consumption/recipes/{recipeId}/consume` accept `location_id`, and only stock at that
location is used. The stock route also accepts `stock_entry_id`. A transfer keeps the entry id of
the row it splits, so combine it with `location_id` to name the rows of one organizer.

- A shortfall at the chosen location is refused and books nothing, even when other organizers hold
  enough. The stock route answers `400`; the recipe route answers `409` with `stock_refused`.
- A `location_id` that is not an integer, or that names no active location, is refused with `400`
  and nothing is consumed. Earlier versions dropped such a value and consumed from any location.
  `null` and `""` still mean that no location was sent.
- With no location, the order is the one described under **Any location** above.

Two kinds of configured source exist. For manual consumption with no location, the product's
default consume location comes first. For an event an external client submits, a consumption
mapping chooses the location: `fixed` is that location only, with no fallback, `single` is the one
location that holds enough, and `explicit` is the location the event names. When a mapping cannot
name one location, the event is not booked. See
[consumption events](consumption-events.md#what-the-inbox-lists). A client that must charge a
particular organizer through the stock route sends `location_id`.

### Labels and scans

The location, product and stock entry labels an organizer household prints carry the fields in the
label field catalogue: names, dates, amounts and location names. A scan answers with the target's
id, name and path. None of them includes a consumption recipe, its note, a consumption event or a
refill date, whatever the stock has been through, and a reader with only `STOCK_VIEW` sees the
same. `OrganizerLabelDisclosureTest` and `OrganizerApiTest` check this for captures, live and
sample previews, print jobs, scans, context reads and the snapshot a retired label keeps.

### Checking an installation

`.devtools/pgsql/run-tests.sh consumption` runs the organizer tests against PostgreSQL. The
browser flow is `node .devtools/frontend/organizers.js <url>`, run against a disposable demo
instance as described in the
[frontend checks](https://github.com/datagen24/victual/blob/master/.devtools/frontend/README.md#weekly-organizers).

An undo reverses a booking using the purchase it came from. When a later booking has moved units
of the same purchase, for example the return of another organizer, the earlier consumption can
no longer be undone and the request is refused with a message saying so. Undo consumptions
before the later transfers, or correct the amount with an inventory booking.

## History and undo

**History** lists your own recorded consumptions of the recipe. Other users' consumptions of a
shared recipe are not shown to you. **Undo** reverses a consumption using the amounts it
booked, not the recipe's current lines. It needs the *undo* right on the recipe and the
`STOCK_EDIT` permission. Anyone with `STOCK_EDIT` can also undo the same bookings from the
stock journal.

## Sharing

The owner shares a recipe with a user by username and chooses rights:

| Right | Allows |
|---|---|
| View | Seeing the recipe and your own history. Every share includes it. |
| Record consumption | Recording a consumption. |
| Edit | Changing the name, note and lines. |
| Undo | Undoing your own recorded consumptions. |
| Share | Adding, changing and removing shares of the other rights. |

A share confers no permission. Recording a consumption also needs `STOCK_CONSUME`, undoing
needs `STOCK_EDIT`, and every action needs `STOCK_VIEW`; a share to a user who lacks the
permission is accepted and does nothing until they hold it. A user with the *share* right can
grant only rights they hold, never the *share* right itself, and cannot change a share that
holds a right they lack. Anyone can remove their own share with **Remove my access**.

The owner can **Make owner** a user who already holds a share. The previous owner then holds a
share with every right, which the new owner can remove. Only the owner deletes a recipe.
Deleting it removes its lines and shares; the recorded consumptions stay in the stock history.
Deleting a user's account deletes the recipes that user owns.

## Events from other apps

An app can report consumption on your behalf. Events that could not book wait in the
[consumption inbox](consumption-events.md).

## The API

The routes are under `/api/consumption/recipes`; see [The REST API](../operator/rest-api.md)
and `victual.openapi.json`. A consumption request that carries a `request_id` is idempotent: a
repeat answers `200` with `replayed` true and books nothing. Without a `request_id` a retry
books again.

## Retrying a manual consumption

Reuse a `request_id` only for the same recipe, source location and submitted time.
The server returns the recorded event for an identical retry, even if the recipe has since changed.
Reusing the id with different inputs returns `409 request_id_conflict` and changes no stock.
If the first request omitted `occurred_at`, omit it again on a retry.

Events recorded before input fingerprints were stored return `409 request_id_unverifiable` on retry.
Inspect the existing event before recording anything else. Automatically assigning a new id could
book the same consumption twice.
