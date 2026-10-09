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

## The API

The routes are under `/api/consumption/recipes`; see [The REST API](../operator/rest-api.md)
and `victual.openapi.json`. A consumption request that carries a `request_id` is idempotent: a
repeat answers `200` with `replayed` true and books nothing. Without a `request_id` a retry
books again.
