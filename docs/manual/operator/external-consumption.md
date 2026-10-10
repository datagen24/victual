# External consumption events

An external client, such as `victual-kit` reading dose events from Apple Health, reports
consumption to Victual through an authenticated API. Victual books each reported event against
stock once, keeps the record private to the user who owns it, and lets that user resolve
anything it could not book. This page is for the operator who runs the instance and for the
developer of a client. The decisions behind it are
[ADR-0040](../../adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md) and
[ADR-0041](../../adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md).

Victual records what a person's device reported and what is on hand. It has no dosing
scheduler, reminder, dose alert or adherence record, and it does not convert between units on
its own ([ADR-0015](../../adr/0015-medication-records-never-advises.md)). A scheduled,
skipped or unanswered event never changes stock.

## What the server fixtures show

The fixtures under `tests/fixtures/consumption-events/` replay the sequences of ADR-0041
against a real PostgreSQL schema through the HTTP stack. They show how Victual answers a given
request. They do not show that Apple Health produces those requests, that the identifiers
behave as assumed on edit and delete, or how late a device delivers an event. That evidence
comes from a named iOS version and device and belongs to
[issue 702](https://github.com/datagen24/victual/issues/702).

## Identity and privacy

An event is identified by the authenticated user, a `source_system` and a `source_event_id`.
The user is the account that owns the API key, never a field of the request. Two users can use
the same source ids and never see each other's events; a lookup by another user answers `404`.

The API key that carried a request is recorded for audit only. Rotating a key, or sending from
a second device with another key of the same user, reaches the same events.

`source_system` is a lowercase token of up to 32 characters. `manual` is reserved for
consumption recorded in Victual, and a request that names it is refused. A client uses one
stable name for itself, such as `healthkit`.

Events, mappings and the review list are private tables. They are not exposed entities, so
`/api/objects/consumption_events` and its sibling tables answer `400`. They appear in no label,
calendar, export or integration. The stock bookings an event makes are ordinary stock
bookings and are visible to every user who can see the stock journal; they do not name the
event.

## Routes

All routes are under `/api/consumption`. Reads need `STOCK_VIEW`. Writes need `STOCK_VIEW` and
`STOCK_CONSUME`.

| Route | Purpose |
|---|---|
| `GET /capabilities` | The contract version and the features this server implements. A client tests membership of `features` instead of guessing from the server version. |
| `PUT /events/{source_system}/{source_event_id}` | Create or update an event. `201` for a new row, `200` otherwise. |
| `GET /events/{source_system}/{source_event_id}` | Read one event. |
| `DELETE /events/{source_system}/{source_event_id}?reason=` | The source no longer holds the event. |
| `POST /events/{source_system}/{source_event_id}/resolve` | One action by a person: `retry`, `rebook`, `approve_unit`, `void`, `keep`, `dismiss` or `link`. |
| `GET /events?state=&since=&limit=` | The caller's own events, newest first. |
| `POST /events/batch` | Up to 50 independent events. |
| `POST /events/resolve` | One action on up to 50 events, by list or by filter. |
| `PUT`, `GET`, `DELETE /mappings/{source_system}/{medication_ref}` and `GET /mappings` | The mappings the person approved. |
| `POST /recipes/{recipeId}/consume` | Manual consumption of a consumption recipe. |

A stored event answers `200` or `201` even when it did not book. The `state` and `reason`
fields report the outcome. The error responses are `400 invalid_request`, `404 not_found`,
`409 same_version_different_payload`, `409 invalid_transition`, `422 invalid_mapping`,
`422 invalid_link` and `422 future_occurred_at`.

## Mappings

An event books only through a mapping that the same user approved for its `source_system` and
`medication_ref`. Victual does not guess a product from a medication name, a conversion from a
strength, or a location from a schedule. A mapping holds:

- **A target.** One consumption recipe the user may consume, or one product. A product target
  can name `qu_id`, the unit the event quantity is in, and a `quantity_factor`. The unit needs
  a conversion to the product's stock unit that the household entered, or the mapping is
  refused with `422 invalid_mapping`.
- **`unit_labels`.** The unit strings the person confirmed. An event whose `unit_label` is not
  on the list is not booked. It becomes `needs_review` with reason `unit_unconfirmed` and
  carries the label it sent as `unit_label_seen`. The `approve_unit` action adds the label and
  books the event.
- **`default_quantity`.** Used when an event carries no `quantity`. With neither, the event is
  `needs_review` with reason `quantity_missing`. A recipe target ignores the event quantity.
- **A location rule.** See the next section.
- **`effective_from`.** An event that occurred earlier is `dismissed`, so connecting a client
  does not book old history.

Changing a mapping affects events processed afterwards. Events already booked keep their
products, amounts, locations and booking identifiers. Deleting a mapping also deletes that
medication's `voided` and `dismissed` events, which guard against replaying a deleted event;
events that booked stock keep their rows.

## Where stock is taken from

| Mode | Behavior |
|---|---|
| `fixed` | Takes stock from the named location only. Stock in a child location of that location is not counted. If the location holds too little, the event is `needs_review` with reason `insufficient_stock`. It never falls back to another location. |
| `single` | Takes stock from the one location that holds enough. With none, reason `insufficient_stock`. With several, reason `ambiguous_location` and `candidate_location_ids` lists them. |
| `explicit` | Takes stock from the `location_id` the event carries. Without one, reason `ambiguous_location`. |

An event that is `needs_review` has booked nothing. Several lines of a recipe book together or
not at all.

!!! note "Late events use the current mapping"
    Victual reads the mapping when it processes an event, not when the dose happened. An event
    that arrives after the person switched a `fixed` location is charged to the new location.
    A late event can therefore deduct from an organizer that was not in use when the dose was
    taken. A client that knows the organizer can use the `explicit` mode and send
    `location_id`. The booked date is not affected: it is the calendar date written in the
    offset of `occurred_at`.

## Repeats, versions and corrections

`PUT` is idempotent because the identity is in the path. A repeat of an earlier payload answers
`200` with `replayed: true` and books nothing, whether it arrives later or at the same time as
the first. The payload hash covers `status`, `medication_ref`, `quantity`, `unit_label`,
`occurred_at` and `location_id`, and excludes `source_updated_at`.

| Request | Result |
|---|---|
| Same hash as stored | Replay. |
| Different hash, older `source_updated_at` | `200` with `stale: true`. Nothing changes. |
| Different hash, equal `source_updated_at` | `409 same_version_different_payload`. |
| Different hash, newer value or no value | A correction. |

A correction to a booked event whose quantity, unit, medication, location or calendar date
changed undoes the old booking and makes the new one in one transaction, and raises
`revision`. A change in the time of day alone leaves the bookings.

If the new booking needs
more stock than exists, or the old one cannot be undone because a later booking draws on the
same purchase, the whole correction rolls back, the original booking stays, and the event is
`needs_review` with reason `insufficient_stock` or `undo_refused`. A refused undo is a normal
outcome when several doses came from one purchase and the earlier one is undone first.

An edit that the source performs as delete and recreate arrives under a new
`source_event_id`. The client sends `replaces` with the old id only when it sees the deletion
and the insertion together for the same medication and scheduled date. Victual then voids the
old event and books the new one in one transaction. Without `replaces`, Victual does not
infer that two ids are one dose.

## Deleting, skipping and removal

A status of `skipped`, `unanswered` or `scheduled` never books. If no row exists, none is
created and the response state is `no_consumption`. On a booked event, these statuses and
`not_logged` mean the same as a deletion with reason `entered_in_error`.

| `reason` | Effect on a booked event |
|---|---|
| `entered_in_error` | Voids the event and restores the stock, when `occurred_at` is within the automatic-void window. An older event becomes `needs_review` with reason `source_deleted`. |
| `history_cleared`, `medication_archived`, `access_revoked` | Stock is untouched. The event stays `booked` with `source_removed_at` and the reason. |
| omitted or `unknown` | `needs_review` with reason `source_deleted`. Stock is untouched. |

A person resolves `source_deleted` with `void`, which restores the stock, or `keep`, which
leaves the booking. An event that never booked becomes `voided` for `entered_in_error` and
`dismissed` for any other reason.

## Undo in stock

A person can undo the bookings of an event in the stock journal. Victual reads the state of
the bookings on every read. If all are undone, the event is `undone`. If some are undone, it is
`needs_review` with reason `partially_undone`. Sending the same payload again never books it
again. Only the `rebook` action does, and for a partly undone event it first undoes the
remaining bookings so that each line is deducted once. A changed payload for an undone event
becomes `needs_review` with reason `changed_after_undo`.

## Manual doses and imported doses

A consumption recorded in Victual and the same dose reported by a device are two records.
Victual does not decide that they are one from product, quantity and time. It books the
imported event and lists `possible_duplicates` in the response: the user's own manual or direct
stock consumptions of a product the event booked, within the duplicate window, that no event
is linked to. The user decides.

The `link` action with a `transaction_id` attaches the event to an existing booking
transaction. The transaction must hold only unreversed consumptions of the products the
event's mapping targets, must not be linked to another event, and must have been recorded by
the caller or by someone while the caller holds `STOCK_EDIT`. When the event had booked
itself, its own booking is undone in the same transaction, so one deduction remains.

## Contract details beyond the ADR

The fragment in ADR-0041 does not show five details of the API. A client developer needs each
one, so this section gives a case for each. The values are illustrative.

### A unit on a product mapping

A product mapping can carry `qu_id`, the unit the event quantity is in. Without it, the quantity
is in the product's stock unit. Victual computes `quantity × quantity_factor × conversion`, where
the conversion is the household's entered conversion from `qu_id` to the stock unit.

A product is held in tablets. A device reports 500 and the mapping names milligrams as `qu_id`,
with a household conversion of 0.002 tablets per milligram. The booking is
500 × 1 × 0.002 = 1 tablet. If the household entered no conversion, saving the mapping fails
with `422 invalid_mapping`, and no event can book through it.

### Fields on an event object

An event answers with `medication_ref` when it has one, so a client can match the response to
the medication it sent. Each booked line carries `used_date`, the calendar date in the offset
the client sent in `occurred_at`.

A dose at `2026-10-09T23:40:00-05:00` is booked under `2026-10-09`, although the same instant
is 2026-10-10 in UTC. The date is stored, so a retry or a resolve that books days later uses the
same date.

### The message on a `stock_error` event

An event ends `needs_review` with reason `stock_error` when the stock service refuses a booking
for a cause Victual cannot classify in advance. The refusal text is in `message`, and only the
event's owner receives it. Another user and the generic object endpoints never see it.

For example, a product with a measured open container refuses a half-unit consumption. The owner
sees `"message": "...measured container..."`, the stock is unchanged, and a later `retry` runs
again after the household fixes the product.

### `invalid_link` on `link`

Every refusal of the `link` action is `422 invalid_link` with the same text. The text does not
say which test failed, because a transaction the caller cannot see must not be probed.

The refusals include a transaction that does not exist, one already reversed, one that is not a
consumption, one linked to another event, one recorded by someone else when the caller lacks
`STOCK_EDIT`, and one for a product the mapping does not target. Bulk resolution has no `link`
action, so it never answers `invalid_link`.

### `replayed` on every answer to a PUT

A PUT answers `replayed: false` when it created the row and `replayed: true` when the identity
already existed.

Send the same request twice. The first answer is `201` with `replayed: false`, and the second is
`200` with `replayed: true` and no second deduction.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `CONSUMPTION_AUTO_VOID_DAYS` | `7` | Days after the dose within which a source report that it was not taken restores stock without a person. |
| `CONSUMPTION_DUPLICATE_WINDOW_MINUTES` | `30` | Minutes either side of an imported dose within which another consumption is listed as a possible duplicate. |

## Backup, restore and import

The tables are in the database, so a `pg_dump` backup carries them and a restore brings them
back. `victual-db-import` reads from SQLite, and no supported SQLite source holds these
tables. The import therefore refuses a target that already holds rows in them unless forced,
and a forced import clears them.

## Resolving many events

A client that deletes a medication's history without a reason would otherwise queue one
decision per dose. `POST /events/resolve` applies one action to up to 50 events, chosen by a
list of `{source_system, source_event_id}` or by a `filter` of `source_system`,
`medication_ref`, `state` and optionally `reason`, oldest dose first.

Each event is its own transaction and reports its own result, so one in the wrong state
answers `409 invalid_transition` without stopping the rest. With a filter the response carries `remaining`,
the matching events beyond those handled. A client repeats the call until `remaining` is
`0` for `void`, `keep`, `dismiss`, `rebook` and `approve_unit`, which move an event out of the
filter. `retry` can leave an event in the filter, so a client sends the list form.

A filter matches the state a person sees, so events whose bookings were undone in the stock
journal match `state: undone` even though they are stored as booked until something touches
them.

!!! note "Order matters for a bulk void"
    The filter takes the oldest `occurred_at` first. The stock journal refuses to undo a booking
    while a later booking draws on the same purchase. A client that booked several doses from one
    purchase in the order they happened therefore gets `undo_refused` for every dose except the
    last-booked one, and those events leave the filter. They stay `needs_review` with the stock
    deducted, and `void` no longer applies to them.

    Undoing the most recent booking first avoids this, so a person who needs to restore a run of doses undoes them newest first in the stock
    journal. [ADR-0041](../../adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md)
    fixes the filter order, and a different order is a decision for that record.

