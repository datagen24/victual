# Consumption inbox

Another app can report that you consumed something, for example a phone app that reads dose
events from Apple Health. Victual books each report against stock once, after you approved a
mapping for that medication. The events it could not book wait in the **consumption inbox** at
`/consumptioninbox`. The page needs the `STOCK_VIEW` permission to open. Resolving an event also
needs `STOCK_CONSUME`. Without it the buttons are disabled and the page says why.

Victual records what was reported and what is on hand. It does not schedule anything, remind
you, judge whether a dose was on time, or give advice. A scheduled, skipped or unanswered
report never changes stock. See
[ADR-0015](../../adr/0015-medication-records-never-advises.md).

Only you see your events. Another user's events, and yours, are absent from each other's inbox
and answer "not found" on the API. Developers of a client read
[External consumption events](../operator/external-consumption.md).

## What the inbox lists

The inbox lists your events that are waiting: not booked because there is no mapping, not enough
stock, an unconfirmed unit and similar reasons, and events the source deleted. Turn on **Also
show undone events** to list events whose booking you undid in the stock journal. A booked event
appears when other consumptions of the same product were recorded near the same time.

Each row shows the source, the medication reference the app sent, the time the dose happened in
your browser's time zone, the lines it booked, and a sentence that states what happened. Examples:

| Sentence | Meaning |
|---|---|
| Not booked: no mapping for this medication | The app sent an event for a medication you have not mapped. |
| Not booked: not enough stock at the chosen location | The mapped location holds less than the dose. Victual does not take the difference from another location. |
| Not booked: the location is not clear | More than one location could supply the dose, or the app named none. The row lists the candidates. |
| Not booked: the unit has not been confirmed | The app sent a unit label you have not confirmed. The row shows the exact label. |
| The source deleted this record; stock unchanged | The source removed the record. Victual leaves the booking until you decide. |
| Undone: the booking was reversed and stock was restored | You undid the booking in the stock journal. |

An event that did not book changed no stock. The lines of a recipe are booked together or not at
all.

## Actions

| Button | When it is offered | Result |
|---|---|---|
| Try booking again | Not booked, for any reason except those below | Books the event with the mapping as it is now. |
| Confirm the unit and book | The unit was not confirmed | Adds the label to the mapping and books the event. Later events with that label book directly. |
| Book again | You undid the booking | Books it once more. Reporting the same event again never does this. |
| Undo the booking | The source deleted the record | Restores the stock. |
| Keep the booking | The source deleted the record | Leaves the booking and records that you saw the deletion. |
| Dismiss | The event has no live booking | Closes it. A dismissed event never books. |
| Link to an existing consumption | Not booked, or booked with a possible duplicate | Says the event and an existing consumption are the same dose. |

### Link to an existing consumption

You recorded a dose in Victual and the app reported the same dose. Victual cannot tell that they
are the same from the product, amount and time, because two doses can be taken close together.
It books the reported event and lists the nearby consumptions as possible duplicates. Choose one,
or enter a transaction id, and **Link** attaches the event to it. If the event had booked, its own
booking is undone, so one deduction remains.

The consumption must be of the products the mapping
targets, must not be linked to another event, and must have been recorded by you, unless you also
hold `STOCK_EDIT`.

## Resolve many at once

If an app clears a medication's history, every dose can arrive as "The source deleted this
record". **Resolve many at once** applies one action to every event of one medication in one
state. The server handles up to 50 per request and the page repeats until none match or a round
changes nothing. An event that cannot take the action reports its own refusal and the rest still
apply.

## When an undo is refused

The stock journal refuses to undo a booking while a later booking draws on the same purchase.
When Victual needs such an undo, for example to void an event or to correct one, it leaves the
booking as it was and the event shows "Not changed: the stock refused to undo the earlier
booking". Undo the later bookings first, or correct the stock with an inventory booking.

## Limitations

- The location is read from the mapping when the event is processed, not when the dose
  happened. If you changed the mapped location, a late event is charged to the new one.
- The fixtures and tests for this feature show how Victual answers requests. They do not show
  that Apple Health delivers those requests.
