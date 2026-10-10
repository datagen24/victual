# ADR-0041: Consumption events have a source identity, and reconciliation is explicit

- **Status:** **Accepted 2026-10-09.** Prerequisites are stated in the accepting pull request.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-09, against `master` at `216af2b2`. Design work only: it changes no
  code, reserves no migration number and amends no accepted record.
- **Referenced by:** [plan 22](../plans/22-medication-tracking.md) (Q12, Q13) and
  [issue 696](https://github.com/datagen24/victual/issues/696). Dependent implementation:
  [issue 698](https://github.com/datagen24/victual/issues/698) (manual consumption) and
  [issue 700](https://github.com/datagen24/victual/issues/700) (external events). Native
  acceptance: [issue 702](https://github.com/datagen24/victual/issues/702).
- **Relationship:** builds on [ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md)
  (Proposed) for recipe access, and on accepted
  [ADR-0032](0032-stock-amounts-compare-within-one-tolerance.md) (quantity tolerance),
  [ADR-0036](0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)
  (booking lineage) and [ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
  (Proposed; timestamps are RFC 3339). Related to
  [ADR-0012](0012-observations-are-proposals.md): an imported event is a report by a person's
  own device, so it books through the stock write paths after the person approves a mapping.
  It is not a probabilistic observation; acceptance prerequisite 4 asks the maintainer to
  confirm that reading.

## Context

A native client (`victual-kit`) can read a person's medication dose events from Apple Health
and send them to Victual. A person can also record the same consumption by hand in Victual.
Both must deduct stock exactly once, survive retries, and behave sensibly when the source
edits, deletes or delivers an event late.

### What the platform provides

Apple's HealthKit Medications API (WWDC25 session 321, reviewed 2026-10-09 at
<https://developer.apple.com/videos/play/wwdc2025/321/>) exposes authorized medications and
dose events. Per that session, events can arrive late and an edit can delete and recreate a
sample, so an event's identifier is not stable across edits. Complete recurrence schedules are
not established and are not needed here. Nothing in this record is verified against a real
device payload; [issue 702](https://github.com/datagen24/victual/issues/702) owns that.

A dose event does not say which household product supplied it, which unit conversion applies,
or which organizer location the item came from.

### What the stock write paths provide

Facts from `StockService` on `master` at `216af2b2`:

- `ConsumeProduct()` takes the transaction identifier by reference, generates one with
  `uniqid()` when null, and restricts consumption to `$locationId` when given. It refuses
  an amount above the stock in that scope by throwing a generic `\Exception`. Nine different
  causes (a missing or inactive product, a missing location, four insufficient-stock variants,
  an invalid type, a measured-container fraction) share that class and code 0, so a caller
  cannot tell them apart without matching the message.
- `DatabaseService::InTransaction()` begins a transaction only when none is open. A nested
  call runs its work inside the open transaction with no savepoint, and an exception rolls
  back everything at the outermost level. A caller needing several lines atomic wraps them in
  one outer call, as `RecipesService::ConsumeRecipe()` does. That method caps each line at
  available stock; the new path must refuse instead.
- `used_date` is written as `date('Y-m-d')` at booking (`StockService.php:908`, `:969`).
  A late event cannot carry its true date without a new optional parameter.
- `UndoTransaction()` undoes every booking of a transaction or none. `UndoBooking()` through
  `POST /api/stock/bookings/{bookingId}/undo` undoes one booking and only its `correlation_id` partners
  (a transfer's two halves). The lines of a multi-product consumption share a
  `transaction_id` and no `correlation_id`, so one line can be undone alone.
- A caller that locks several products up front takes them in ascending id order
  (`LockProductsStock()`, as `RecipesService.php:193` does). `ConsumeProduct()` and
  `UndoTransaction()` lock lazily, so two operations that touch overlapping products without
  a shared up-front lock can deadlock.
- No existing table records which client or source a booking came from.

### What deduplication alone does not solve

A unique key makes a repeated request a no-op. It cannot tell that a manual consumption and
an imported event describe the same real-world dose, because the manual booking has no source
key. Matching on product, amount and time would merge two separate doses taken close together
and lose a deduction.

## Decision (proposed)

### 1. Source identity

An event is identified by **(authenticated user, `source_system`, `source_event_id`)**.

- The user is the authenticated principal of the request, never a request field. A lookup by
  another user finds nothing and answers 404, so identities cannot be probed or reused.
  This reads "scoped to the authenticated client and owner" as the authenticated user plus
  the client's own `source_system` name; see option D for why a key is not part of it.
- `source_system` is a lowercase token matching `^[a-z0-9][a-z0-9._-]{0,31}$`. The value
  `manual` is reserved for events the server creates when a person consumes a recipe in
  Victual. A native client uses a stable name for itself, such as `healthkit`.
- `source_event_id` is a client-chosen string of 1 to 128 characters from `[A-Za-z0-9._:-]`,
  case-sensitive. For `manual` events it is a client-supplied `request_id` (a UUID) or, if
  omitted, a server-generated one. A manual consume is idempotent only when the client sends
  `request_id`; without it, a retry books again. The Victual UI always sends one.
- Identity is **not** scoped to an API key. A key can be rotated or a person can use two
  devices, and either would orphan the events. The key used is recorded for audit only.

### 2. Event record and states

An event row stores the identity, the payload hash, `source_updated_at`, the mapping it used,
its state and reason, its stock `transaction_id`, and one line per booked product. Tables are
for [issue 700](https://github.com/datagen24/victual/issues/700) to name. Lines store the
product, quantity in the stock unit, source location and booking ids as booked, so a later
recipe or mapping edit never changes what a past event consumed. Events and lines live in the
private tables [ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md)
rule 1 describes and are visible only to their user.

| State | Meaning | Stock effect |
|---|---|---|
| `received` | Row committed, booking not yet attempted or interrupted | None |
| `needs_mapping` | No approved mapping for this `medication_ref` | None |
| `needs_review` | A person must act; `reason` says why | None |
| `booked` | Bookings exist under `transaction_id` | Deducted once |
| `undone` | The person undid the bookings in Victual | Reversed; stays reversed |
| `voided` | The source reported the event deleted or not taken | Reversed by Victual |
| `linked` | Attached to an existing booking transaction | No new deduction |
| `dismissed` | A person or the mapping window excluded it | None |
| `no_consumption` | Response only: a non-`taken` status arrived and no row exists | None |

`needs_review` reasons: `ambiguous_location`, `insufficient_stock`, `unit_unconfirmed`,
`quantity_missing`, `recipe_unavailable`, `partially_undone`, `changed_after_undo`,
`undo_refused`, `invalid_mapping`, `source_deleted`, `stock_error`.

### 3. Which source statuses book

The request carries `status`, the client's translation of the source status:

| `status` | Effect |
|---|---|
| `taken` | Eligible to book |
| `not_logged` | The person undid a logged dose. Treated as a deletion with reason `entered_in_error` (rule 7) |
| `skipped`, `unanswered`, `scheduled` | Never book. If no row exists, none is created and the response is `no_consumption`. If a `booked` row exists, treated like `not_logged` |

A scheduled or reminded event never deducts stock. Victual stores no row for a skipped or
unanswered dose that was never taken, so it accumulates no adherence record.

### 4. Mapping: the person approves product, unit, quantity and source

An event books only through a **mapping** owned by the same user, keyed by
(`source_system`, `medication_ref`), where `medication_ref` is the client's opaque identifier
for the medication. A mapping is created or changed only by the person through an explicit
request, normally from a native approval screen, and holds:

- a target: one consumption recipe (access per ADR-0040), or one product with a unit and a
  `quantity_factor`;
- `unit_labels`: the unit strings the person has confirmed for this medication. An event with a
  label outside the list is not booked; it becomes `needs_review` / `unit_unconfirmed` and
  carries the label it sent, so the person sees the exact string. Resolving with
  `approve_unit` adds that label to the list and books the event. A mapping may start with an
  empty list; the first event then teaches it through that approval;
- `default_quantity`: optional. An event without `quantity` uses it. Without both, the event
  is `needs_review` / `quantity_missing`. The server never invents a quantity, and a recipe
  target ignores the event quantity;
- a location rule: `fixed` (a named location, usually one organizer), `single` (book only if
  exactly one location holds enough stock), or `explicit` (each event carries `location_id`);
- `effective_from`: events that occurred earlier are `dismissed`, so connecting a client does
  not book years of history.

`medication_ref` matches `^[A-Za-z0-9._:-]{1,128}$`, the same pattern as `source_event_id`. The
client derives it from the opaque medication identifier it receives, for example a hash, since
HealthKit documents no string form. Victual treats it as opaque.

Victual infers nothing: it does not guess a product from a medication name, a conversion from
a strength or a location from a schedule. A product target must have an existing quantity
unit conversion from the mapping's unit to the product's stock unit, or the mapping is refused
with 422. A mapping to a recipe takes the recipe's lines once per event.

Location outcomes: `fixed` with too little stock there gives `insufficient_stock`; it never
falls back to another organizer. `single` with several candidates gives `ambiguous_location`.
`explicit` without `location_id` gives `ambiguous_location`. Nothing books in these cases.
A `fixed` location matches that `location_id` exactly: stock held in a child location of an
organizer is not counted. A mapping read before the event is processed applies: an event that arrives after the
person switches the `fixed` location is charged to the new location. A client that knows the
organizer sends `location_id` and the mapping may allow it as an override. This limitation
is documented in the operator guide.

### 5. Idempotent submission and concurrency

`PUT /api/consumption/events/{source_system}/{source_event_id}` creates or updates an event.
The identity is in the path, so a retry has the same key as the original request and no
separate idempotency header is needed.

Processing for one request:

1. Transaction 1 inserts the row with `INSERT ... ON CONFLICT DO NOTHING` and commits, so the
   identity and any later failure reason are durable.
2. Transaction 2 locks the event row with `FOR UPDATE`, then the recipe row if the target is a
   recipe (`FOR SHARE` for a consumption, per ADR-0040 rule 8), then the product lock set in
   ascending order, then books every line and sets the state. Lock order: event, recipe,
   products.
3. If a booking throws, the exception escapes transaction 2 so the whole transaction rolls
   back, and transaction 3 sets `needs_review` with the reason. Transaction 2 must not catch
   the exception and continue. Insufficient stock is a PHP exception thrown before any SQL
   fails, so catching it inside the nested `InTransaction()` would leave earlier lines booked:
   the evidence measured stock falling from 10 to 6 with the event reporting `needs_review`.
   A swallowed SQL error followed by a commit rolls everything back without an error.

| Interleaving | Result |
|---|---|
| Identical request replayed after success | `200`, stored result, `replayed: true`, no booking |
| Two identical requests at once | One inserts; the other waits for the row lock, then returns the stored result |
| Same key from two devices of one user | Same as above; the key space is per user |
| Crash after transaction 1 | State stays `received`; the next request with that key continues |
| Request while state is `received`, or `needs_review` with reason `insufficient_stock` | Booking is attempted again |
| Request while state is `needs_review` with any other reason | Stored, no booking; waits for an explicit resolve (rule 8) |

`source_updated_at` (RFC 3339) is optional. It is the client's observation time of the source
record, for sources that can edit a record; HealthKit samples cannot be edited, so a client
may omit it. The payload hash excludes it. Ordering and conflicts then work as follows:

| Request | Result |
|---|---|
| Same payload hash as stored, any `source_updated_at` | Replay: stored result, `replayed: true`. A replay need not repeat its original value |
| Different hash, `source_updated_at` older than stored | `200` with `stale: true`, no change |
| Different hash, newer value, or either value absent | Applied as a correction (rule 6) |
| Different hash, both values present and equal | `409 same_version_different_payload` |

Payload hash covers `status`, `medication_ref`, `quantity`, `unit_label`, `occurred_at` and
`location_id`.

Multi-product consumption (a recipe target, or a product target with several lines) is booked
in transaction 2 with one `$transactionId` passed by reference to every `ConsumeProduct()`
call. Any failure, including permission, an inactive product or insufficient stock, rolls back
every line. The recipe path does not use `ConsumeRecipe()`'s cap-at-available behavior.

`occurred_at` more than five minutes in the future is refused with 422. For a past
`occurred_at`, `used_date` is the calendar date written in the offset the client sent, which
is the person's local date. A value sent as `Z` gives its UTC date. This follows the design
that the server runs in UTC and clients know local time, and it needs the optional parameter
described in the Consequences. [ADR-0042](0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)
applies the same principle to refill dates through `as_of`.

### 6. Corrections

An edit with a newer `source_updated_at` and a changed payload hash applies as follows:

| Current state | Material change | Result |
|---|---|---|
| `booked` | Quantity, unit, medication, location or the calendar date of `occurred_at` | Undo the old transaction and book the new one in one transaction; `revision` increases |
| `booked` | Only the time of day | Update `occurred_at`; bookings unchanged |
| `undone` or `partially_undone` | Any | `needs_review` with `changed_after_undo`; no booking |
| `voided` | Any, with `source_updated_at` after the void | Treated as a new event (rule 7) |
| `needs_mapping`, `needs_review`, `received` | Any | Stored; booking is attempted again as for a first submission |

A correction locks the union of the old and new products, in ascending order, before it undoes
anything. Without that union lock, two corrections deadlocked in 160 of 300 requests for
different recipes in the evidence; with it, 0.

The whole correction rolls back, the original booking stays in place, and the event records
`needs_review` when either step fails:

- the new booking needs more stock than exists after the old one is undone: `insufficient_stock`;
- the undo of the old transaction is refused because a later booking depends on the same lot:
  `undo_refused`. This hit 212 of 600 corrections in the mixed workload, so it is a normal
  outcome and not a rare one.

`UndoTransaction()` reads its booking set before it takes locks, so a concurrent
`UndoBooking()` on the same transaction makes it fail with "already undone" (446 refusals in
300 trials). The event path re-reads the event's bookings once and retries; if every booking is
undone it sets `undone`, and if some remain it sets `partially_undone`.

### 7. Deletions and recreation

A source deletion does not always mean the dose was not taken. Removing Health history,
archiving a medication or revoking access deletes records without un-taking a dose. The
`DELETE` request therefore carries a `reason` (query parameter or JSON body):

| `reason` | Meaning | Effect on a `booked` event |
|---|---|---|
| `entered_in_error` | The person says the dose was not taken | Void: undo the transaction, state `voided` |
| `history_cleared`, `medication_archived`, `access_revoked` | The source stopped holding the record | Stock untouched; the event stays `booked` with `source_removed_at` and the reason |
| omitted or `unknown` | The client cannot say | `needs_review` / `source_deleted`; stock untouched |

Two server rules apply on top, so a mistaken client cannot restore stock in bulk:

- A void (`entered_in_error`, or status `not_logged`, `skipped`, `unanswered`, `scheduled`
  on a booked row) applies automatically only when `occurred_at` is within the last 7 days
  (an instance setting). An older one becomes `needs_review` / `source_deleted`.
- A `replaces` void is exempt, because it books the replacement in the same transaction.

A person resolves a `source_deleted` event with `void` (restore stock) or `keep` (leave the
booking; the deletion is acknowledged). Neither is automatic.

- Voided: the undo happens in one database transaction, the row stays as a tombstone with
  `voided_at`. If the undo is refused, the event is `needs_review` / `undo_refused`.
- Not booked: the event becomes `voided` for `entered_in_error`, otherwise `dismissed`.
- A `PUT` of the same key with `source_updated_at` not after `voided_at` is stale and changes
  nothing. A later value, or any `PUT` that differs in payload when both versions are absent
  and the row is `voided`, is a new event.

An edit that the source performs as delete-and-recreate arrives as a new `source_event_id`.
The client sends `replaces: "<old source_event_id>"` only when it sees the deletion and the
insertion together for the same medication and scheduled date. The server then voids the old
event and books the new one in one transaction, which never leaves two deductions. Without
`replaces`, the old event's deletion and the new event's creation are independent requests;
a deletion with a reason other than `entered_in_error` leaves the old booking, so the client
must not send one for an edit. Victual does not infer that two different ids are the same
dose.

### 8. Local undo, resolution and the review inbox

A person may undo the bookings directly in the stock journal. `UndoTransaction` undoes all
bookings; `POST /stock/bookings/{id}/undo` can undo one. The stock routes are unchanged.

The event's effective state is derived on read from `stock_log.undone` for its bookings:

| Bookings | Effective state |
|---|---|
| None undone | `booked` |
| All undone | `undone` (persisted on next touch) |
| Some undone | `needs_review` / `partially_undone` |

Synchronization never rebooks an `undone` event, however many times the same payload is sent.
A changed payload is rule 6. A person can act on an unresolved event with
`POST /api/consumption/events/{source_system}/{source_event_id}/resolve`:

| `action` | Allowed in | Effect |
|---|---|---|
| `retry` | `needs_mapping`, `needs_review` | Attempt the booking again with the current mapping |
| `rebook` | `undone`, `partially_undone` | Book again; the only way an undone event returns |
| `approve_unit` | `needs_review` / `unit_unconfirmed` | Add the event's label to the mapping, then book |
| `void` | `needs_review` / `source_deleted` | Undo the booking and set `voided` |
| `keep` | `needs_review` / `source_deleted` | Keep the booking; set `booked` with the removal recorded |
| `dismiss` | any unbooked state | Terminal; never books |
| `link` | `needs_review`, `booked`, `received` | Attach to an existing transaction (rule 9) |

An action not allowed in the current state returns `409 invalid_transition`.

**Bulk resolution.** A bare HealthKit deletion has no reason, so clearing a medication's history
would otherwise queue one `source_deleted` decision per dose.
`POST /api/consumption/events/resolve` applies one `action` to up to 50 events, selected by
either of two forms:

- `events`: a list of `{source_system, source_event_id}` pairs;
- `filter`: `{source_system, medication_ref, state, reason}` (all required except `reason`),
  matching the caller's own events, oldest `occurred_at` first, then id.

The action is `void`, `keep`, `dismiss`, `retry`, `approve_unit` or `rebook`; `link` is not
bulk, because it needs one transaction id per event. Each item applies in its own transaction
and returns its own result, so an item in the wrong state (`409 invalid_transition`) does not
stop the others. With `filter`, the response also reports `remaining`, the count of matching
events beyond the 50 handled, so a client loops until it is 0. Scoping to the caller's own
events is the same rule as everywhere else: another user's events never match. A user lists their
unresolved events with `GET /api/consumption/events?state=needs_review,needs_mapping`.

### 9. Manual and imported records are linked by an explicit act

Every recipe consumption recorded in Victual creates a `manual` event. Other consumption
(a direct stock consume) has no event but has a `transaction_id`.

Two records are the same dose only when a person says so, using
`POST .../resolve {"action": "link", "transaction_id": "<id>"}`. Link requires:

- the transaction contains only unreversed `consume` bookings;
- it is not already linked to another event;
- the caller recorded those bookings, or holds `STOCK_EDIT`;
- the products are the ones the event's mapping targets.

On an event that is already `booked`, link undoes the event's own transaction and attaches the
manual one in one database transaction, so exactly one deduction remains. Victual's responses
include `possible_duplicates`: manual transactions by the same user within a configurable
window (default 30 minutes, product-matched) that are not yet linked. They are suggestions and
never act on their own. The default for a suspect pair is to book the import and surface the
suggestion; open question 1 asks whether to hold it instead.

### 10. Error and conflict contract

| Status | Body `error` | When |
|---|---|---|
| 400 | `invalid_request` | Malformed JSON, bad identity token, missing required field, unknown deletion `reason` |
| 401 / 403 | none | Not authenticated; lacking `STOCK_CONSUME` |
| 404 | `not_found` | No such event or mapping for this user; a recipe the user cannot read |
| 409 | `same_version_different_payload` | Rule 5 |
| 409 | `invalid_transition` | Rule 8 |
| 422 | `invalid_mapping`, `future_occurred_at` | Mapping cannot be valid; event in the future |

An event that stores successfully answers `200` or `201` even when it does not book; the
`state` and `reason` fields report the outcome. `201` is a newly created row.

A batch request, `POST /api/consumption/events/batch` with up to 50 items, applies each item
independently as above and returns an array of per-item results. One failing item does not
roll back another.

### 11. Events are private to their user

Events, mappings and their review lists are visible only to the user who owns them
([ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md) rule 1). Stock
readers see the resulting bookings as they see any booking. No generic route, label, webhook
or calendar entry includes an event.

## Contract

The routes, request and response objects, and the machine-readable fragment are in
[the contract appendix](#appendix-contract). The fragment is design material until
[issue 700](https://github.com/datagen24/victual/issues/700): `ContractTest` fails when
`victual.openapi.json` names a path with no route, so the paths merge with their routes.

## Options considered

**A. Source-scoped identity with explicit link (this record).** Deduplicates retries exactly,
treats a manual dose and an imported dose as separate until a person links them, and never
rebooks an undone event.

**B. Fuzzy match on product, amount and time.** Removes the double-deduction case without
user action. It also merges two real doses taken close together and silently loses one
deduction. The stock ledger is exact history ([constitution](../constitution.md)); rejected.

**C. Imports always propose, a person confirms each.** The pattern of
[ADR-0012](0012-observations-are-proposals.md). A device's own dose log is a report, not an
inference with a confidence value. Confirming every dose would remove the benefit of the
integration. A mapping approved once carries the same intent. Rejected.

**D. Scope identity by API key.** Simpler to enforce, and a key rotation or second device
would then duplicate every event. Rejected.

**E. Overwrite instead of undo-and-rebook on correction.** Editing booked rows in place breaks
ADR-0036 lineage and the audit trail. Rejected.

## Evidence

[Pull request 722](https://github.com/datagen24/victual/pull/722) holds the probes and
`RESULTS.md` (2026-10-09, PostgreSQL 16.15 and 15.19, PHP 8.5.10, scratch tables standing in
for the proposed ones, timing jitter rather than controlled schedules):

- Identical requests, 16 and 64 in parallel, 200 rounds each on both versions: exactly one
  booking set per round, 0 errors. Two users with one key: 60 of 60 rounds gave two events.
  A child killed after transaction 1 or mid-transaction 2 left the ledger consistent.
- Lock order event, recipe, products: 0 deadlocks against direct consumes and undo in 300
  trials per scenario. Corrections without the union lock deadlocked, as rule 6 now states.
- The `$usedDate` patch passed four stock phases (309, 23, 35 and 74 tests) unchanged, wrote
  both bookings of a two-lot consume with the earlier date, and a tokenizer audit of every
  `ConsumeProduct` call site found at most 10 arguments and no spreads. The count of call sites
  differed between two runs (135, then 119 on a different working copy); the maximum did not.
- Partial undo is reachable and ends in `undone` after `UndoTransaction()` on the remainder;
  lineage violations stayed at 0.

It does not exercise HealthKit, HTTP, the outbox, or victual-kit.

## Consequences

- **`ConsumeProduct()` needs an optional trailing `$usedDate` parameter**, defaulting to today,
  so late events carry their date. It is backward compatible; [issue 700](https://github.com/datagen24/victual/issues/700)
  adds it and a test that existing callers book today's date.
- **Insufficient stock must be classified without parsing a message.** The implementation
  pre-checks available stock in scope under the product locks, using `CompareAmounts()`
  (ADR-0032), and throws a typed exception, rather than matching the generic exception text.
  The pre-check agreed with `ConsumeProduct()` in 300 of 300 cases when it summed only the
  entries in the requested location scope; a product-wide sum disagreed in 154. A fractional
  consume of a measured container passes the pre-check and is then refused by
  `ConsumeProduct()`. The event path maps any refusal it cannot classify to `needs_review` /
  `stock_error` and stores the message privately for the person.
- **Imported events book as the authenticated user.** `stock_log.user_id` is that user.
- **Skipped and unanswered doses leave no trace.** Victual cannot answer an adherence
  question, by design.
- **A mapping change affects later processing only.** Events processed under an earlier
  mapping keep what they booked.
- **Private tables are new data to back up and import.** [Issue 700](https://github.com/datagen24/victual/issues/700)
  decides importer handling, following ADR-0040.
- **The unresolved inbox is a new surface.** It shows a person's own events only.

## Acceptance prerequisites

1. The maintainer answers open questions 1 to 3, or accepts the stated leans. Questions 1
   and 2 answered 2026-10-09; question 3 (the `possible_duplicates` window) takes the lean.
2. [ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md) is accepted, or
   the maintainer accepts this record's reliance on its private-table and lock-order rules.
3. The maintainer confirms that the optional `$usedDate` parameter is acceptable for
   `ConsumeProduct()`, which this record treats as an internal API. Confirmed by the
   maintainer 2026-10-09; the evidence that existing suites still pass is separate.
4. The maintainer confirms that a client-reported dose event is outside
   [ADR-0012](0012-observations-are-proposals.md) and may book through the stock write paths
   once the person has approved a mapping. Confirmed by the maintainer 2026-10-09.

## Open questions

1. **Hold a suspected duplicate instead of booking it?** Booking can double-deduct until the
   person links; holding leaves stock overstated until they act. *Lean: book and surface
   `possible_duplicates`, because an unlinked pair costs one visible correction and a held
   event can be forgotten.* A per-mapping `on_possible_duplicate` setting could expose both.
   *Decider's answer, 2026-10-09: book and surface `possible_duplicates`; no per-mapping setting in v0.5.0.*
2. **How long are `voided` and `dismissed` tombstones kept?** They guard against replaying a
   deleted event. *Lean: keep them while the mapping exists, then delete with it.*
   *Decider's answer, 2026-10-09: keep them while the mapping exists.*
3. **What is the `possible_duplicates` default window?** *Lean: 30 minutes, an instance setting.*
4. **Is 7 days the right automatic-void window?** *Input from the `victual-kit` maintainer,
   2026-10-09: keep it. A same-evening undo is the common case, and the larger risk is a stale
   edit from a phone that was offline for longer.* A deletion or `not_logged` older than the
   window waits for a person. *Lean: 7 days, an instance setting, because a mistaken bulk
   deletion by a client then restores nothing without review.*
5. **Does the client's rule for `replaces` (delete and insert together for the same medication
   and scheduled date) need server support?** *Lean: no; the server voids exactly what the
   client names. Input from the `victual-kit` maintainer, 2026-10-09: the client sends
   `replaces` only when a deletion and an insertion arrive in the same anchored-query batch for
   the same medication and scheduled date, only for scheduled doses and never for as-needed
   ones. It does not need the server to verify the pairing and will not rely on it.*

6. **When a correction's undo is refused because a later booking depends on the same lot, what
   happens?** *Lean: the whole correction rolls back and the original booking stays, with
   `needs_review` / `undo_refused`.* *Decider's answer, 2026-10-09: take the lean.*
7. **Is a concurrent "already undone" refusal retryable?** *Lean: yes, once, after re-reading
   the bookings.* *Decider's answer, 2026-10-09: take the lean.*
8. **Does a `fixed` location include child locations?** *Lean: no, exact match, and say so.*
   *Decider's answer, 2026-10-09: take the lean.*
9. **How is a measured-container refusal handled?** *Lean: map it to `needs_review` /
   `stock_error`.* *Decider's answer, 2026-10-09: take the lean.*

## Appendix: contract

### Routes

| Method and path | Purpose |
|---|---|
| `PUT /api/consumption/events/{source_system}/{source_event_id}` | Create or update an event |
| `GET /api/consumption/events/{source_system}/{source_event_id}` | Read one event |
| `DELETE /api/consumption/events/{source_system}/{source_event_id}` | Source deleted the event |
| `POST /api/consumption/events/{source_system}/{source_event_id}/resolve` | `retry`, `rebook`, `dismiss`, `link` |
| `GET /api/consumption/events` | List own events; `state`, `since`, `limit` filters |
| `POST /api/consumption/events/batch` | Up to 50 independent items |
| `POST /api/consumption/events/resolve` | One action on up to 50 events, by list or by `filter`; reports `remaining` |
| `PUT /api/consumption/mappings/{source_system}/{medication_ref}` | Approve or change a mapping |
| `GET /api/consumption/mappings`, `GET .../{source_system}/{medication_ref}` | Read mappings |
| `DELETE /api/consumption/mappings/{source_system}/{medication_ref}` | Remove a mapping |
| `POST /api/consumption/recipes/{id}/consume` | Manual consumption; body `request_id`, optional `location_id`, `occurred_at` |
| `GET /api/consumption/capabilities` | `{contract_version, features[]}`, so a client tests for a feature rather than guessing from the server version |

`features` lists only what the server implements. Version 1 names: `events`, `mappings`, `batch`,
`bulk_resolve`, `manual_consume`, `deletion_reasons`, `not_logged`, `default_quantity`,
`unit_labels` and `replaces`. A client gates a screen on membership, and a name is added when
its behavior ships, so a partial deployment never advertises a feature it lacks.

For the mapping screen, a product's valid unit conversions are
`GET /api/objects/quantity_unit_conversions_resolved?query[]=product_id=<id>` and the
locations holding it are `GET /api/stock/products/{productId}/locations`. Both exist today
under `STOCK_VIEW`; no new route is needed.

Permissions: every route needs authentication and `STOCK_CONSUME` (reads: `STOCK_VIEW`).
Recipe targets additionally follow ADR-0040's action matrix. The fragment is
[`.devtools/adr0041/consumption-events.openapi.json`](../../.devtools/adr0041/consumption-events.openapi.json).
Until [issue 700](https://github.com/datagen24/victual/issues/700) merges these routes into
`victual.openapi.json`, a client can generate types from that fragment and replace them when
the spec is regenerated.

### Examples

First submission, no mapping:

```json
PUT /api/consumption/events/healthkit/6F1C...A9
{ "status": "taken", "medication_ref": "hk:med:42", "quantity": 1,
  "unit_label": "tablet", "occurred_at": "2026-10-09T08:03:00-04:00",
  "source_updated_at": "2026-10-09T08:03:05-04:00" }

201 { "state": "needs_mapping", "reason": null, "replayed": false,
      "source_system": "healthkit", "source_event_id": "6F1C...A9" }
```

Approving a mapping, then a retry books:

```json
PUT /api/consumption/mappings/healthkit/hk:med:42
{ "product_id": 17, "quantity_factor": 1, "unit_labels": [], "default_quantity": null,
  "location": { "mode": "fixed", "location_id": 9 },
  "effective_from": "2026-10-09T00:00:00-04:00" }

POST /api/consumption/events/healthkit/6F1C...A9/resolve  { "action": "retry" }
200 { "state": "booked", "transaction_id": "65f3a1b2c4d5e",
      "lines": [{ "product_id": 17, "amount": 1, "location_id": 9 }] }
```

Replay, then a late event two days old:

```json
PUT .../healthkit/6F1C...A9   (identical body)
200 { "state": "booked", "replayed": true }

PUT .../healthkit/B20D...01   { "occurred_at": "2026-10-07T21:00:00-04:00", ... }
201 { "state": "booked", "lines": [{ "product_id": 17, "amount": 1, "used_date": "2026-10-07" }] }
```

Source edit as delete and recreate, then a deletion:

```json
PUT .../healthkit/C7E4...55   { "replaces": "B20D...01", "quantity": 2, ... }
200 { "state": "booked", "replaces": { "source_event_id": "B20D...01", "state": "voided" } }

DELETE .../healthkit/C7E4...55
200 { "state": "voided" }
```

Insufficient stock and ambiguous location:

```json
PUT .../healthkit/D1A0...77
201 { "state": "needs_review", "reason": "insufficient_stock" }

PUT .../healthkit/E5B8...12   (mapping mode "single", two locations hold enough)
201 { "state": "needs_review", "reason": "ambiguous_location",
      "candidate_location_ids": [9, 11] }
```

### Native acceptance test contract

[Issue 702](https://github.com/datagen24/victual/issues/702) records evidence from a real
device. The two kinds of evidence are different and both are reported:

- **Server fixture tests** ([issue 700](https://github.com/datagen24/victual/issues/700)) replay
  the sequences below as JSON against a real PostgreSQL schema. They show Victual's behavior.
  They do not show that Apple Health produces these requests.
- **Device evidence** ([issue 702](https://github.com/datagen24/victual/issues/702)) shows
  `victual-kit` on a named iOS version and device producing them. It records the real
  payload fields, the identifier behavior on edit and delete, and the late-delivery latency.

| # | Sequence | Expected result |
|---|---|---|
| 1 | Taken event, no mapping | `needs_mapping`, no stock change |
| 2 | Approve mapping, `retry` | `booked`, stock reduced once at the mapped location |
| 3 | Same request twice, then twice in parallel | One booking |
| 4 | Event dated before `effective_from` | `dismissed`, no booking |
| 5 | Late event (two days old) | `booked`, `used_date` is the event date |
| 6 | Edit as new id with `replaces` | Old `voided`, new `booked`, net one deduction |
| 7 | Edit as new id without `replaces`, delete then create, and create then delete | One deduction for delete then create. For create then delete, one deduction when the deleted event is the newest booking on every purchase it drew from; otherwise `needs_review` / `undo_refused` and two deductions until a person reconciles them (see Erratum) |
| 8 | Source status changes to `skipped` or `not_logged` after booking, within 7 days | `voided`, stock restored |
| 9 | Skipped or unanswered event with no row | `no_consumption`, no row |
| 9a | `DELETE` with `access_revoked`, `history_cleared` or `medication_archived` on a booked event | Stock unchanged, event stays `booked` |
| 9b | `DELETE` with no reason, or `entered_in_error` older than 7 days | `needs_review` / `source_deleted`; `void` restores, `keep` does not |
| 9c | Event with no `quantity`: mapping with and without `default_quantity` | Booked with the default; `quantity_missing` without one |
| 9d | First event with an unseen unit label | `unit_unconfirmed` showing the label; `approve_unit` books it and later events with it book directly |
| 9e | Replay with a different `source_updated_at` or none | Replay, no conflict |
| 9f | 120 `source_deleted` events for one medication, `void` by filter | Three calls handle 50, 50 and 20; `remaining` is 70, 20 and 0; each event ends `voided` |
| 9g | Bulk `keep` that includes an event already `voided` | That item reports `invalid_transition`; the rest apply |
| 10 | User undoes in stock, client replays the same event | Stays `undone`, no rebooking |
| 11 | User undoes one line of a two-line recipe | `partially_undone`, no rebooking |
| 12 | Manual consumption, then import, then `link` | One deduction total, event `linked` |
| 13 | Two similar manual and imported doses, not linked | Two deductions; `possible_duplicates` offered |
| 14 | Two-line recipe with insufficient stock for line 2 | Nothing deducted, `insufficient_stock` |
| 15 | Mapping `single` with stock in two locations | `ambiguous_location`, nothing deducted |
| 16 | Recipe share revoked before the event is processed | `needs_review` / `recipe_unavailable` |
| 17 | Another user sends the same `source_event_id` | Separate event; neither can read the other's |

## Erratum, 2026-10-10

Verification row 7 originally expected one deduction after create then delete in every case. ADR-0036
rule 7, step 3, refuses to undo a booking while a later live booking of the same product has an allocation
on any purchase lot the first one drew from. When the old and the new event draw on the same purchase,
deleting the old event therefore answers `needs_review` / `undo_refused` and both deductions remain until a
person resolves the event. When they draw on different purchases the delete succeeds and one deduction
remains. Fixture `07b-create-then-delete.json` shows the second case, and
`ConsumptionEventServiceTest::testAVoidWhoseUndoIsRefusedBecauseALaterBookingDependsOnItNeedsReview` the first.

The maintainer accepted this reading on 2026-10-10. Rebooking the later event under a new transaction id to
avoid the refusal would change ledger behavior and needs its own design decision. The rules above this
section are unchanged.
