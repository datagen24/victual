# Prescription refills

Victual records when a prescription was filled and for how many days, estimates when to reorder
it, and exposes that state and its notices through an authenticated API. It sends nothing.
This page is for the operator who runs the instance and for the developer of a client such as
`victual-kit`. The decisions behind it are
[ADR-0040](../../adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md) for access
and [ADR-0042](../../adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md) for
the rules.

The estimate is a date calculated from what a person entered. It is not clinical advice, and it
is not a statement that an insurer or a pharmacy will allow a refill on that date. It is also
independent of the stock on hand: a household can hold two weeks of tablets and be due to reorder,
or hold none and have just refilled. Victual has no dosing scheduler, dose alert or adherence
record ([ADR-0015](../../adr/0015-medication-records-never-advises.md)).

## What is recorded

A prescription is a [private consumption recipe](../using-victual/consumption-recipes.md). All refill records belong
to one recipe, so the recipe's owner and the members it is shared with can see them, and nobody
else can. An account administrator holds no implicit access.

| Record | What it holds |
|---|---|
| Fill | `filled_on` (the date the pharmacy supplied the medication), `supplied_days` (1 to 730, optional) and an optional note. A fill is never edited or deleted: a wrong fill is voided with a reason and a new one is recorded. |
| Rule | One medication-specific rule per recipe: `days_before_end`, `fixed_interval` or `fraction_elapsed`, with one integer parameter. |
| Explicit date | A reorder date a person entered for the current fill. |
| Warning lead | Days of advance warning, 0 to 60: the recipe's own value, else the user's setting `refill_warning_lead_days`, else 7. |
| Order | A request to the pharmacy: `open`, `received` or `cancelled`. At most one is open per recipe. |
| Acknowledgement | That one user has seen one notice. |

Every date is a calendar date written `YYYY-MM-DD` with no time and no offset. The server never
fills in a date: a request that records one must carry it, written from the person's own calendar.

## How the estimate is chosen

The estimate comes from the first rule that applies, and the response names it in `estimate.source`.

1. **Explicit date** (`explicit`). A date entered for one fill. It applies only to that fill and
   ends permanently when any newer fill is recorded. Voiding the newer fill does not bring it back.
2. **Medication-specific rule** (`rule:<kind>`):
    - `days_before_end` with N: `filled_on + supplied_days - N`.
    - `fixed_interval` with D: `filled_on + D`, whatever `supplied_days` is.
    - `fraction_elapsed` with P percent: `filled_on + floor(supplied_days * P / 100)`, in integers.
3. **Fallback** (`fallback`). `filled_on + supplied_days - 14`.

A 30-day fill on 2026-01-01 gives 2026-01-17 and a 90-day fill gives 2026-03-18. The fallback
never replaces a specific rule. The current fill is the unvoided fill with the greatest
`filled_on`, then the greatest id.

When no date can be calculated, `estimate.reorder_date` is `null`, `status` is `unknown` and
`estimate.reason` names the first failed check:

| Reason | When |
|---|---|
| `no_fill` | No unvoided fill exists. |
| `invalid_rule` | A rule parameter is missing or out of range. |
| `invalid_supply` | The rule or the fallback uses `supplied_days` and it is missing. `fixed_interval` and an explicit date do not use it. |
| `result_not_after_fill` | The date would fall on or before the fill date, such as a 14-day supply with the fallback. |

Victual does not clamp a date it would have to invent. A person fixes a short supply with a
`fixed_interval` rule or an explicit date.

## Today: the `as_of` parameter

The server runs in UTC and a person's calendar does not. Every read that depends on today
(`GET .../refill`, `GET /api/refills`, `GET /api/refills/notices`) takes `as_of=YYYY-MM-DD`, the
client's local date. The status is calculated from it and no stored value changes. A request
without `as_of` is answered with the UTC date and `"as_of_source": "server_utc"`; a household whose
clients are on UTC sees no difference, and any other household should send `as_of`. A New York
household that does not send it sees the status change at 20:00 local time in summer.

Write routes accept `as_of` as well, only to calculate the state they return. An `as_of` that is
not a real date answers `422 invalid_as_of`.

## Status

With R the reorder date, L the warning lead and T the `as_of` date:

| Status | Condition |
|---|---|
| `ok` | T is before R - L. |
| `approaching` | R - L <= T < R. |
| `due` | T >= R. `days_overdue` is T - R. |
| `ordered` | An order is open, whatever T is. |
| `unknown` | No date can be calculated. |

The reorder date itself is `due`. With L of 0 there is no `approaching` period.

## Orders, receipt and corrections

- Recording an order sets the status to `ordered`. It adds no stock, records no fill and changes
  neither the fill date nor the estimate.
- Receiving an order records the fill that arrived and closes the order against it, in one
  request. Stock arrives separately, through a normal purchase booking. Recording a fill without
  an order is allowed.
- Cancelling an order restores the status the fills and rules say.
- A second open order answers `409 order_open`. A closed order answers `409 order_closed` to a
  receive or a cancel.
- Moving stock between organizers, consuming and undoing a consumption never change a fill, an
  order or an estimate. Low stock and a future reorder date can both be true.

## Notices

Victual does not send notifications. `GET /api/refills/notices` returns the caller's
unacknowledged notices, and a client delivers its own.

| Kind | Raised when |
|---|---|
| `approaching` | The status is `approaching`. |
| `due` | The status is `due`. |

An open order raises neither. A notice has a key, `<recipe_id>:<kind>:<reorder_date>`, and the
sentence in `text`: "Estimated reorder date 2026-03-18 (from your last fill)" for `approaching` and
"Reorder date reached: estimated 2026-03-18 (from your last fill)" for `due`.
The sentence names no medicine, so a client can show it where a title would disclose one; the name
is a separate field. It states a date and where the date came from and gives no instruction.

`POST /api/refills/notices/ack` with `{"notice_key": "..."}` acknowledges one notice for the
caller. The call is idempotent. The key must have the documented form (`400 invalid_notice_key`)
and name a recipe the caller can read (`404 not_found`). Acknowledgement is per user: a member who
shares the recipe sees and acknowledges their own notices.

Because the key holds the reorder date, a correction that changes the date raises a new notice,
and voiding a fill and recording an identical one keeps the key, so nobody is notified again.

## Routes

All routes need `STOCK_VIEW`. Access to a recipe's refill data is the recipe's own: reading needs
the `read` right and writing the `edit` right. A caller with no share on the recipe gets
`404 not_found`, the answer for a recipe that does not exist; a caller who can read but lacks the
`edit` right gets `403 right_missing`.

| Route | Purpose |
|---|---|
| `GET /api/consumption/recipes/{id}/refill` | State, settings and the fill and order history. |
| `PUT /api/consumption/recipes/{id}/refill` | Set `rule`, `warning_lead_days` or `explicit_reorder_date`. An absent field stays; `null` clears it. |
| `POST .../refill/fills` | Record a fill (`filled_on`, optional `supplied_days` and `note`). |
| `POST .../refill/fills/{fillId}/void` | Void a fill with a `reason`. |
| `POST .../refill/orders` | Record an order (`ordered_on`). |
| `POST .../refill/orders/{orderId}/receive` | The body is the fill that arrived. |
| `POST .../refill/orders/{orderId}/cancel` | Cancel an open order. |
| `GET /api/refills` | The state of every recipe the caller can read. |
| `GET /api/refills/notices`, `POST /api/refills/notices/ack` | Notices and their acknowledgement. |

Every write answers with the recipe's refill state. A request body is a JSON object; numbers
stay numbers, so `supplied_days` as a string is refused with `422 invalid_supplied_days`. The
error body is `{"error_message": "...", "error": "<token>"}`. The tokens are `not_found`,
`right_missing`, `permission_missing`, `date_required`, `invalid_date`, `invalid_as_of`,
`invalid_supplied_days`, `invalid_rule`, `invalid_lead`, `date_before_fill`, `no_current_fill`,
`unknown_field`, `reason_required`, `invalid_note`, `invalid_reason`, `fill_not_found`,
`order_not_found`, `order_open`, `order_closed`, `already_voided`, `invalid_notice_key`,
`invalid_request` and `conflict`.

The user setting `refill_warning_lead_days` is read and written through
`/api/user/settings/refill_warning_lead_days`. A value outside 0 to 60 answers `422 invalid_lead`.

## Discovery

`GET /api/consumption/capabilities` lists `refill` when the state, settings, fill and order routes
are implemented and `refill_notices` when the notice list and acknowledgement are. A client tests
membership instead of guessing from the server version. The contract version stays 1: the change is
additive.

## What the fixtures show

The fixtures under `tests/fixtures/consumption-refill/` replay these sequences against a real
PostgreSQL schema through the HTTP stack. They show how Victual answers a given request. They do not
show that a native client reads the notices, schedules a local notification or presents it. That
evidence comes from a named device and belongs to
[issue 702](https://github.com/datagen24/victual/issues/702).

## What is never published

Refill data is not published through MQTT, InfluxDB, a webhook or the calendar feed. Anyone who
holds a broker credential could read it, and it belongs to the recipe's owner and the members it is
shared with. The five tables are not exposed entities, so `/api/objects/consumption_refill_fills`
and its siblings answer `400`, and no label, export or integration carries them. A recipe's
deletion, and its owner's, deletes its refill records.
