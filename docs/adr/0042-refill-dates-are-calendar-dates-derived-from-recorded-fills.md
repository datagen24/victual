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
received, corrections, and who delivers a notice. Seven days of warning is a recommendation,
not a maintainer decision.

Facts about the current tree (source inspection, 2026-10-09, `master` at `216af2b2`):

- There is no per-user or per-installation time zone setting. Every "today" is
  `date('Y-m-d')` in the PHP default zone, and `Instant::ServerZone()` returns that zone
  (`services/Time/Instant.php:92`). Stock due dates compare `best_before_date`, a `DATE`, against
  that string (`StockService.php:1811-1832`).
- Per-user preferences are `DefaultUserSetting(...)` entries in `config-dist.php`.
- The workload standard ([ADR-0010](0010-workload-standard.md)) makes every fork-owned
  workload stateless and declared. Victual has no scheduler that pushes to a person, and the
  MQTT and webhook publishers carry stock events only.

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
| Refill settings (one per recipe) | rule kind and parameter, explicit reorder date and the fill it applies to, warning lead override | Optional; absent means fallback rule and default lead |
| Fill | `filled_on` (DATE), `supplied_days` (integer), optional note, `voided_at`, `void_reason` | History; never deleted |
| Order | `ordered_on` (DATE), state `open`, `received` or `cancelled`, received fill id | A request; adds no stock |
| Notice acknowledgement | user, notice key, `acknowledged_at` | Per user |

`supplied_days` is entered for each fill and must be an integer from 1 to 730. A 30-day and a
90-day fill carry their own value. The fill's date is the date the pharmacy supplied the
medication, entered by the person.

### 2. Rule vocabulary and precedence

The estimated reorder date comes from the first rule below that applies. The result carries
the rule that produced it.

1. **Explicit date.** A date the person entered for the current fill. It applies only while
   that fill is current: recording a newer fill or voiding the current one ends it, so a stale
   date is never carried to the next fill. Source: `explicit`.
2. **Medication-specific rule**, one of:
   - `days_before_end` with `N`: `filled_on + supplied_days - N`.
   - `fixed_interval` with `D` days: `filled_on + D`, regardless of `supplied_days`.
   - `fraction_elapsed` with `F`, where `0 < F < 1`: `filled_on + floor(supplied_days * F)`.

   Source: `rule:<kind>`.
3. **Fallback.** `filled_on + supplied_days - 14`. Source: `fallback`. A specific rule
   never gets overwritten by the fallback, and the fallback never runs when a rule or an
   explicit date applies.

Examples of the fallback, from `date` arithmetic checked on 2026-10-09:

| Fill date | Supplied days | Estimated reorder date | Days after fill |
|---|---|---|---|
| 2026-01-01 | 30 | 2026-01-17 | 16 |
| 2026-01-01 | 90 | 2026-03-18 | 76 |
| 2027-12-20 | 90 | 2028-03-05 | 76 (crosses a year end) |
| 2028-01-15 | 30 | 2028-01-31 | 16 (leap year) |

The estimate is approximate. It is not a statement that an insurer or pharmacy will allow
the refill on that date, and no field, label or notice may say so.

### 3. Unknown, invalid and short supplies

The estimate is `null` with `status: unknown` and a `reason` when no date can be computed:

| Reason | When |
|---|---|
| `no_fill` | No unvoided fill exists and no explicit date applies |
| `invalid_supply` | `supplied_days` missing or outside 1 to 730 |
| `invalid_rule` | A rule parameter is missing or out of range |
| `supply_not_longer_than_lead` | `days_before_end` or the fallback with a supply of `N` days or fewer, so the date would fall on or before the fill |

Victual does not clamp a date it would have to invent. A person who gets a seven-day fill
and sees `unknown` can set an explicit date or a `fixed_interval` rule.

### 4. Calendar zone and boundaries

Fill, order and reorder values are SQL `DATE`s and travel as `YYYY-MM-DD`, with no time or
offset ([ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)).
Day arithmetic is calendar arithmetic, with no DST involvement.

"Today" is the date in the application's server zone, the same value the rest of the
application uses, and the response reports it as `as_of` with the zone name. A client may
send `as_of=YYYY-MM-DD` to ask for the state on its own local date; it does not change any
stored value. This is a decision to reuse the existing single zone, since no per-user zone
exists; a household whose members live in different zones can use `as_of`.

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
is an integer from 0 to 60; with `L = 0` there is no `approaching` period.

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
| `approaching` | Status is `approaching` or later and no order is open |
| `due` | Status is `due` and no order is open |

The key is `<recipe_id>:<fill_id>:<kind>:<reorder_date>`. A client acknowledges with
`POST /api/refills/notices/ack` and `{"notice_key": "..."}`. The call is idempotent, and
acknowledgement is per user. Because the key contains the fill and the reorder date, a
correction that changes either raises a new notice, and a retry of the same acknowledgement
changes nothing. A user who shares the recipe sees and acknowledges their own notices. An
open order suppresses new notices without deleting acknowledged ones.

Notice text states facts: "Estimated reorder date 2026-03-18 (from your last fill)". It does
not tell a person to take, stop or change a medication, and does not claim eligibility.

### 7. Schema and invariants for implementation

Constraints belong in the database, per the pattern of the tables created since migration 0266:

- `supplied_days` between 1 and 730; `filled_on` and `ordered_on` not null.
- A partial unique index allows at most one `open` order per recipe.
- Rule parameters are checked: `N` from 0 to 730, `D` from 1 to 730, `F` strictly between 0 and 1.
- The explicit date references the fill it applies to, and is ignored once the fill is not current.
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
  "as_of": "2026-03-12", "zone": "America/New_York",
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
  "reason": "supply_not_longer_than_lead" } }
```

## Verification cases for implementation

[Issue 701](https://github.com/datagen24/victual/issues/701) turns each row into a PHPUnit
case, with pgTAP for the constraints and the Playwright flow for the screens.

| Case | Expected |
|---|---|
| 30-day and 90-day fills on 2026-01-01 | 2026-01-17 and 2026-03-18, source `fallback` |
| Year-end and leap-year fills in section 2 | The dates in the table |
| Explicit date plus a rule plus the fallback present | Explicit wins; remove it and the rule wins; remove both and the fallback runs |
| Record a newer fill after setting an explicit date | Explicit no longer applies; the rule or fallback uses the new fill |
| `supplied_days` 14 with the fallback | `unknown`, `supply_not_longer_than_lead` |
| `supplied_days` 0, 731, null | `unknown`, `invalid_supply` |
| `fraction_elapsed` 0.75 on a 90-day fill | `filled_on + 67` |
| `T` on `R - L - 1`, `R - L`, `R - 1`, `R`, `R + 3` | `ok`, `approaching`, `approaching`, `due`, `due` with `days_overdue` 3 |
| `L = 0` | No `approaching` day |
| `as_of` in another zone's date | Same stored values, different status |
| Open order, then receive with a fill | Status `ordered`, then recomputed from the new fill; no stock change |
| Organizer transfer, consumption and undo | Fill, order and estimate unchanged |
| Void the current fill | Previous fill becomes current; estimate recomputed; both kept in history |
| Two concurrent orders for one recipe | One succeeds, the other gets 409 |
| Acknowledge the same notice twice | One row; second call returns the same result |
| Correct a fill after acknowledging its notice | New key, new notice |
| Unshared user, share holder, owner | 404 for the first, own notices for the other two |

## Options considered

**A. Calendar dates in the server zone, client-supplied `as_of`, no server push (this record).**
Matches the rest of the application and the workload standard.

**B. Instants in a per-user zone.** A refill date is a date. A per-user zone would be a new
concept for one feature and would need a settings migration. Rejected; `as_of` covers the case.

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
