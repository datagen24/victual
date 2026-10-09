# ADR-0042: Refill dates are calendar dates derived from recorded fills, and Victual exposes notices without sending them

- **Status:** **Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-09, against `master` at `216af2b2`. Design work only: it changes no
  code, reserves no migration number and amends no accepted record.
- **Referenced by:** [plan 22](../plans/22-medication-tracking.md) (Q14, Q16) and
  [issue 697](https://github.com/datagen24/victual/issues/697). Dependent implementation:
  [issue 701](https://github.com/datagen24/victual/issues/701). Native delivery:
  [issue 702](https://github.com/datagen24/victual/issues/702).
- **Relationship:** refill data belongs to a consumption recipe, so access follows
  [ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md) (Proposed).
  Dates follow the rule in [ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
  (Proposed) that a SQL `DATE` is a calendar date. [ADR-0015](0015-medication-records-never-advises.md)
  (Proposed) bounds the wording: a refill notice states a recorded fact and an estimate.

## Context

The maintainer decided on 2026-10-09 that Victual tracks when to reorder a prescription. The
general approximation is `last fill date + supplied days - 14 days`, a medication-specific rule
takes precedence, and the household wants an advance warning and a notice on the reorder
date. Five points were left to design: the rule vocabulary, the calendar zone, ordered versus
received, corrections, and who delivers a notice. The maintainer chose a seven-day default
warning on 2026-10-09.

Facts about the current tree (source inspection, 2026-10-09, `master` at `216af2b2`):

- Victual stores and serves instants in UTC and clients convert them to local time
  ([ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)). That is
  the maintainer's stated design for the whole application: the cluster, the logs and the
  database run in UTC, and each front end knows the person's local time.
- The installation has one PHP zone, `date.timezone`, which the shipped images set to UTC
  (`nix/runtime/php-ini.nix:59`). No per-user zone setting exists. `PostgresDialect.php:131`
  copies that zone to the database session, so PHP's `date('Y-m-d')` and `CURRENT_DATE` agree
  on a connection the application opens; they can disagree on any other client.
- Stock due dates compare a `DATE` column against a date string computed in PHP
  (`StockService.php:1811-1832`). Not every "today" works that way: `chores_current` computes
  it in SQL from the transaction's clock (`migrations/0289.pgsql.sql:167-171`), and the web
  front end uses the browser's local date.
- Per-user preferences are `DefaultUserSetting(...)` entries in `config-dist.php`.
- The workload standard ([ADR-0010](0010-workload-standard.md)) makes every fork-owned
  workload stateless and declared. Victual has no scheduler that pushes to a person; the
  label worker and the outbox drainer are its only background consumers. MQTT snapshots carry
  facts and no derived states (`StateSnapshotAssembler.php:18-22`).

The refill estimate is unrelated to stock on hand. A person can hold two weeks of pills and
be due to reorder, or hold none and have just refilled. The two numbers answer different
questions and stay separate.

## Decision (proposed)

### 1. Records

All refill records belong to one consumption recipe (a prescription) and are private under
ADR-0040. Reading a recipe's refill data needs the `read` right; recording or changing it
needs `edit`. No new share right is added. Table and column names are for
[issue 701](https://github.com/datagen24/victual/issues/701) to fix.

| Record | Fields | Notes |
|---|---|---|
| Refill settings (one per recipe) | rule kind and parameter, warning lead override | Optional; absent means fallback rule and default lead |
| Explicit reorder date | `reorder_on` (DATE), the fill it was entered for, `ended_at` | At most one live row per recipe |
| Fill | `filled_on` (DATE), `supplied_days` (integer), optional note, `voided_at`, `void_reason` | History; never deleted |
| Order | `ordered_on` (DATE), state `open`, `received` or `cancelled`, received fill id | A request; adds no stock |
| Notice acknowledgement | user, notice key, `acknowledged_at` | Per user |

`supplied_days` is entered for each fill and must be an integer from 1 to 730. A 30-day and a
90-day fill carry their own value. The fill's date is the calendar date the pharmacy supplied
the medication, entered by the person or sent by a client from the person's local date. The
server never fills in `filled_on` from its own clock, because its clock is UTC.

### 2. Rule vocabulary and precedence

The estimated reorder date comes from the first rule below that applies. The result carries
the rule that produced it.

1. **Explicit date.** A date the person entered for one fill. It applies only to that fill
   and ends, permanently, when any newer fill is recorded. Voiding the newer fill does not
   bring the older explicit date back, so a stale date is never carried forward. Source:
   `explicit`.
2. **Medication-specific rule**, one of:
   - `days_before_end` with `N`: `filled_on + supplied_days - N`.
   - `fixed_interval` with `D` days: `filled_on + D`, regardless of `supplied_days`.
   - `fraction_elapsed` with `P`, an integer percent from 1 to 99:
     `filled_on + floor(supplied_days * P / 100)`, in integer arithmetic.

   Source: `rule:<kind>`. A fraction is stored as an integer percent because a floating-point
   fraction was off by one day in 37 of 21,536 random cases (100 days at 0.58 gave 57).
3. **Fallback.** `filled_on + supplied_days - 14`. Source: `fallback`. A specific rule
   never gets overwritten by the fallback, and the fallback never runs when a rule or an
   explicit date applies.

Examples of the fallback, from `date` arithmetic checked on 2026-10-09:

| Fill date | Supplied days | Estimated reorder date | Days after fill |
|---|---|---|---|
| 2026-01-01 | 30 | 2026-01-17 | 16 |
| 2026-01-01 | 90 | 2026-03-18 | 76 |
| 2027-12-20 | 90 | 2028-03-05 | 76 (crosses a year end and a leap day) |
| 2028-02-20 | 30 | 2028-03-07 | 16 (crosses a leap day) |

The estimate is approximate. It is not a statement that an insurer or pharmacy will allow
the refill on that date, and no field, label or notice may say so.

### 3. Unknown, invalid and short supplies

The estimate is `null` with `status: unknown` and a `reason` when no date can be computed.
Checks run in this order and the first failure is the reason:

| Order | Reason | When |
|---|---|---|
| 1 | `no_fill` | No unvoided fill exists |
| 2 | `invalid_rule` | A rule parameter is missing or out of range |
| 3 | `invalid_supply` | The rule or fallback uses `supplied_days` and it is missing or outside 1 to 730. `fixed_interval` and an explicit date do not use it |
| 4 | `result_not_after_fill` | A computed date is on or before `filled_on`, for example a 14-day supply with the fallback, or `fraction_elapsed` on a 1-day supply |

An explicit date skips checks 2 to 4, and must not be earlier than the fill it belongs to; the
write is refused with 422 otherwise.

Victual does not clamp a date it would have to invent. A person who gets a seven-day fill
and sees `unknown` can set an explicit date or a `fixed_interval` rule.

### 4. Calendar zone and boundaries

Fill, order and reorder values are SQL `DATE`s and travel as `YYYY-MM-DD`, with no time or
offset ([ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)).
Day arithmetic is calendar arithmetic, with no DST involvement.

**The client supplies "today".** A person's local calendar date belongs to the person, and the
server runs in UTC. Every read that depends on today (`GET .../refill`, `GET /api/refills`,
`GET /api/refills/notices`) accepts `as_of=YYYY-MM-DD`, the client's local date. The server
computes status from it and changes no stored value. When `as_of` is absent the server uses
the UTC date and the response says so with `"as_of_source": "server_utc"`; a household that
keeps its clients on UTC sees no difference, and one that does not should send `as_of`.
A write that records a date (`filled_on`, `ordered_on`, an explicit date) requires the date
and never defaults it.

The server takes `as_of` from the request once, in PHP, and binds it as a parameter. Refill SQL
does not use `CURRENT_DATE`, `now()` or a `DEFAULT CURRENT_DATE` column, because those depend on
the session zone and on when a transaction started. The response carries no zone name.

Boundaries, with `R` the reorder date, `L` the warning lead in days and `T` the as-of date:

| Status | Condition |
|---|---|
| `ok` | `T < R - L` |
| `approaching` | `R - L <= T < R` |
| `due` | `T >= R`; `days_overdue = T - R` |
| `ordered` | An open order exists, whatever `T` is |
| `unknown` | Rule in section 3 |

The reorder date itself is `due`. The warning date is the first day of `approaching`. `L` is
the recipe's override if set, else the user's setting `refill_warning_lead_days`, else 7. `L`
is an integer from 0 to 60; a write outside that range is refused with 422. With `L = 0` there
is no `approaching` period, and `R - 1` is `ok`. `days_overdue` is null unless the status is
`due`; an `ordered` recipe reports it as null.

### 5. Ordering, receiving and corrections

- **Order.** Recording an order sets status `ordered`. It does not add stock, record a fill,
  or change the fill date or the estimate.
- **Receive.** A person receives an order by recording the fill that arrived. Order closing and
  fill insertion happen in one transaction, and a closed order references its fill. Recording
  a fill without an order is allowed. Stock arrives separately, through a normal purchase
  booking; refill records never write the ledger.
- **Cancel.** A cancelled order restores the underlying status.
- **Correct.** A wrong fill is voided with a reason and a new fill is recorded. Both stay in
  history. The current fill is the unvoided fill with the greatest `filled_on`, then the
  greatest id. Voiding the current fill makes the previous one current and recomputes. An
  explicit date tied to a voided or superseded fill stops applying.
- **Transfers and consumption.** Moving stock between organizers, consuming, and undoing a
  consumption never change a fill, an order or an estimate. Low stock and a future reorder
  date can both be true; screens may show them side by side, but no field combines them.

### 6. Notices: Victual exposes them and sends none

Victual does not push notifications in this release. It exposes the state, and delivery
belongs to the client: `victual-kit` schedules local notifications from the dates and polls
for state. This keeps Victual without a scheduler or a push credential, in line with the
workload standard.

`GET /api/refills/notices?as_of=` returns the caller's unacknowledged notices. A notice
has a key and a kind:

| Kind | Raised when |
|---|---|
| `approaching` | Status is `approaching` and no order is open |
| `due` | Status is `due` and no order is open |

A person who acknowledged nothing sees only `due` once the reorder date arrives, not both kinds.

The key is `<recipe_id>:<kind>:<reorder_date>`. A client acknowledges with
`POST /api/refills/notices/ack` and `{"notice_key": "..."}`. The key must have that form and
name a recipe the caller can read; anything else is 400 or 404, so the table cannot grow from
arbitrary strings. The call is idempotent, and acknowledgement is per user. Because the key
holds the reorder date, a correction that changes the date raises a new notice.

Voiding and re-recording a fill with identical data produces the same date and the same key, so nobody is
notified again. A user who shares the recipe sees and acknowledges their own notices. An open
order suppresses new notices without deleting acknowledged ones.

Refill state is not published to MQTT, Influx, webhooks or the calendar feed. Anyone holding a
broker credential could read it, and it belongs to the owner and share holders under ADR-0040.

Notice text states facts: "Estimated reorder date 2026-03-18 (from your last fill)". It does
not tell a person to take, stop or change a medication, and does not claim eligibility.

### 7. Schema and invariants for implementation

Constraints belong in the database, per the pattern of the tables created since migration 0266:

- `supplied_days` between 1 and 730; `filled_on` and `ordered_on` not null.
- A partial unique index allows at most one `open` order per recipe.
- Rule parameters are checked: `N` from 0 to 730, `D` from 1 to 730, `P` from 1 to 99.
- An explicit date references the fill it was entered for and carries `ended_at`. Recording a
  newer fill sets `ended_at` in the same transaction. The service refuses a date earlier
  than that fill's `filled_on`, and a test pins it.
- A notice acknowledgement stores the recipe id, kind and date as columns, not only the key,
  so ADR-0040 access applies to the row.
- Fills and orders reference the recipe with `ON DELETE CASCADE`, matching deletion in
  ADR-0040 rule 5.

## Contract

| Route | Purpose |
|---|---|
| `GET /api/consumption/recipes/{id}/refill` | Settings, current fill, estimate, status, history |
| `PUT /api/consumption/recipes/{id}/refill` | Set rule, explicit date, lead |
| `POST /api/consumption/recipes/{id}/refill/fills` | Record a fill |
| `POST /api/consumption/recipes/{id}/refill/fills/{fillId}/void` | Void with reason |
| `POST /api/consumption/recipes/{id}/refill/orders` | Record an order |
| `POST /api/consumption/recipes/{id}/refill/orders/{orderId}/receive` | Body is the fill; closes the order |
| `POST /api/consumption/recipes/{id}/refill/orders/{orderId}/cancel` | Cancel |
| `GET /api/refills` | State for every recipe the caller can read |
| `GET /api/refills/notices`, `POST /api/refills/notices/ack` | Notices |

Every route is authenticated, uses ADR-0040 for access (404 when the recipe is not readable,
403 when the right is missing), and takes `STOCK_VIEW`. As with ADR-0041, the routes merge into
`victual.openapi.json` with their implementation.

A state response:

```json
{
  "recipe_id": 12,
  "as_of": "2026-03-12", "as_of_source": "client",
  "status": "approaching", "days_overdue": null,
  "current_fill": { "id": 4, "filled_on": "2026-01-01", "supplied_days": 90 },
  "estimate": { "reorder_date": "2026-03-18", "warning_date": "2026-03-11",
                "source": "fallback", "lead_days": 7 },
  "open_order": null
}
```

An unknown estimate:

```json
{ "status": "unknown", "estimate": { "reorder_date": null, "source": null,
  "reason": "result_not_after_fill" } }
```

## Verification cases for implementation

[Issue 701](https://github.com/datagen24/victual/issues/701) turns each row into a PHPUnit
case, with pgTAP for the constraints and the Playwright flow for the screens.

| Case | Expected |
|---|---|
| 30-day and 90-day fills on 2026-01-01 | 2026-01-17 and 2026-03-18, source `fallback` |
| Year-end and leap-day fills in section 2 | The dates in the table |
| Explicit date plus a rule plus the fallback present | Explicit wins; remove it and the rule wins; remove both and the fallback runs |
| Record a newer fill after setting an explicit date, then void the newer fill | Explicit never applies again; the rule or fallback uses the current fill |
| Explicit date earlier than its fill's `filled_on` | 422 |
| `supplied_days` 14 with the fallback; `fraction_elapsed` 50 on a 1-day supply | `unknown`, `result_not_after_fill` |
| Both an invalid rule and an invalid supply | `invalid_rule` |
| `fixed_interval` with a missing supply | Computed; `invalid_supply` does not apply |
| `supplied_days` 0, 731, null | `unknown`, `invalid_supply` |
| `fraction_elapsed` 75 on a 90-day fill; 58 on a 100-day fill | `filled_on + 67`; `filled_on + 58` |
| `L = 7` and `T` on `R - 8`, `R - 7`, `R - 1`, `R`, `R + 3` | `ok`, `approaching`, `approaching`, `due`, `due` with `days_overdue` 3 |
| `L = 0` and `T` on `R - 1`, `R` | `ok`, `due` |
| `L` of -1 or 61 on write | 422 |
| `as_of` absent, then `as_of` a day later | `as_of_source` is `server_utc`, then `client`; same stored values, different status |
| Midnight UTC while a client holds the previous local date | Status follows the supplied `as_of`, not the server clock |
| Open order, then receive with a fill | Status `ordered`, then recomputed from the new fill; no stock change |
| Organizer transfer, consumption and undo | Fill, order and estimate unchanged |
| Void the current fill | Previous fill becomes current; estimate recomputed; both kept in history |
| Two concurrent orders for one recipe | One succeeds, the other gets 409 |
| Acknowledge the same notice twice | One row; second call returns the same result |
| Correct a fill so the reorder date changes after acknowledgement | New key, new notice |
| Void and re-record a fill with identical data | Same key; no new notice |
| Acknowledge `not-a-real-key`, or a key for an unreadable recipe | 400, 404 |
| Unshared user, share holder, owner | 404 for the first, own notices for the other two |

## Options considered

**A. Calendar dates, a client-supplied `as_of` defaulting to the UTC date, no server push (this
record).** Matches the stated design of a UTC server and clients that convert to local time.

**B. Instants in a per-user zone.** A refill date is a date. A per-user zone would be a new
concept for one feature and would need a settings migration. Rejected; the client already
knows its local date, so `as_of` covers the case.

**C. Server-sent push or email.** Needs a scheduler, a delivery credential and retry state,
none of which exist, and puts private medication details in an outbound message.
[ADR-0015](0015-medication-records-never-advises.md)'s wording rules would then have to cover
a channel Victual does not control. Rejected for this release.

**D. Clamp short supplies to the fill date.** Produces an immediate notice for a one-week
fill. Rejected in favor of an unknown estimate the person can override.

**E. A single editable "last fill" field.** Loses history and makes a correction
indistinguishable from a new fill. Rejected.

## Consequences

- **The 14-day figure is a default, not law.** A rule or explicit date overrides it. The
  record states that the fallback is approximate.
- **Notice delivery depends on the client.** Without `victual-kit`, notices appear only in the
  web interface. The native acceptance tests live in [issue 702](https://github.com/datagen24/victual/issues/702).
- **Short supplies need a person's input.** They produce `unknown`, not a guess.
- **A client that omits `as_of` gets the UTC date.** A New York household's status flips at
  20:00 local in summer unless its client sends `as_of`. This is the intended split between a
  UTC server and local-time clients, and the operator guide says so.
- **Four small tables and one setting** are added, all private under ADR-0040.
- **Refill state is available to share holders.** A caregiver with `read` sees the estimate.

## Acceptance prerequisites

1. The maintainer answers open questions 1 to 3, or accepts the stated leans. Questions 1, 3
   and 4 answered 2026-10-09; question 2 (order expiry) takes the lean.
2. [ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md) is accepted, or
   the maintainer accepts this record's reliance on its access rules.

## Open questions

1. **Is seven days the right default warning lead, and is a per-user setting plus per-recipe
   override the right shape?** Seven days is the recommendation only. *Lean: yes.*
   *Decider's answer, 2026-10-09: 7 days, as a per-user setting with a per-recipe override.*
2. **Should an open order expire into a reminder?** An order never received could leave a
   prescription silently `ordered`. *Lean: no automation in this release; the order list
   shows age.*
3. **Is `fraction_elapsed` wanted, or are `days_before_end` and `fixed_interval` enough?**
   Some plans allow a refill after a share of the supply elapses. *Lean: keep it; it is
   one line of arithmetic and one check constraint.*
   *Decider's answer, 2026-10-09: keep all three rules.*
4. **Should a short supply with the fallback be `unknown` or clamped?** *Lean: `unknown`.*
   *Decider's answer, 2026-10-09: `unknown`; the person sets a date or rule.*
5. **Where does "today" come from?** *Decider's input, 2026-10-09: the server and cluster run
   in UTC, and front ends know local time and convert on the fly. This revision follows it:
   the client sends `as_of`, and the server defaults to the UTC date.*
6. **Should a void and re-record of an identical fill re-notify?** *Lean: no. The notice key
   holds the reorder date and not the fill id, so identical data produces the same key.*
