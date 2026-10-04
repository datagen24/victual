# ADR-0027: The API's timestamps are UTC instants in RFC 3339, and its documented booleans are booleans

- **Status:** Proposed. Decider's answers recorded 2026-10-04 (below); decision 2 was
  revised by them, and the record cannot be accepted until that revision is implemented
  (acceptance prerequisite 5).
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-21.
- **Relationship:** [ADR-0005](0005-wire-contract-is-the-invariant.md) decides which
  *engine* is right when two engines disagree, and is untouched by this record — decision 1
  below is that rule applied, not an exception to it. This record answers the question
  ADR-0005 does not: what to do when both engines agree and the **document** is the thing
  that is wrong. [ADR-0024](0024-the-fork-writes-its-own-clients.md) decision 1 is why a
  wire change is available at all, and is also why it is not free: a breaking change
  affecting a Victual-owned client requires that client to be updated in coordination with
  the server change.
- **Referenced by:** issues
  [229](https://github.com/datagen24/victual/issues/229),
  [230](https://github.com/datagen24/victual/issues/230),
  [231](https://github.com/datagen24/victual/issues/231),
  [232](https://github.com/datagen24/victual/issues/232) and
  [233](https://github.com/datagen24/victual/issues/233);
  [17 — Ecosystem clients](../plans/17-ecosystem-clients.md), whose catalogue this adds to;
  [14](../plans/landed/14-contract-and-regression-scaffolding.md) piece 2, whose snapshot is
  what measured the first decision.

## Decider's answers (2026-10-04)

The decider answered acceptance prerequisites 1 to 3 in an interview on 2026-10-04. The
record was originally written proposing that the document move for timestamps (the old
title was "The API's timestamps are local wall-clock strings"). One of the answers reverses
that, so decision 2, decision 5, the options and the consequences below are revised in
place. Nothing is superseded, because the record was never accepted. The file name still
carries the old slug so that existing links keep working.

| Question | Answer |
|---|---|
| Decision 1: eleven documented booleans sent as `0`/`1` | **Confirmed. The wire moves.** |
| Decision 2: fifty-four `format: date-time` properties that are not RFC 3339 | **Reversed. The wire moves to RFC 3339.** Option A replaces option B. |
| How to record the reversal | **Revise this record in place**, not a superseding ADR. Decision 5's cost list becomes the implementation scope. |
| Which offset | **UTC, written as `Z`**: `2026-10-04T18:30:00Z`. Not the server's local offset. |
| Storage | **Migrate the legacy `TIMESTAMP` columns to `TIMESTAMPTZ`.** Converting only on output was rejected. |
| Scope | **One rule for the whole API**: the label surface's `TIMESTAMPTZ` values, `TimeResponse.time_utc` and the echoed write fields all join it. SQL `DATE` columns stay `YYYY-MM-DD`. |
| Browser display zone | **The viewer's device zone**, not the server's configured zone. |
| Clients being locked down before this lands | **Decode both renderings** until decision 2 is implemented, then drop the old one. |
| Decision 4: generic write bodies become `GenericEntityWrite` | **Confirmed.** |
| Prerequisite 3: three entities decode under a member that is not theirs | **Accepted only while it is tracked for a fix**, as [issue 648](https://github.com/datagen24/victual/issues/648). The decider wants it fixed, not left as a permanent limit. |

## Context

Building the first-party Swift client (ADR-0024) meant generating code from
`victual.openapi.json` with `swift-openapi-generator`, which is the first consumer this
project has had that reads the document strictly rather than reading the JSON and hoping.
It found five places where the document and the server disagree. Two of them are type
mismatches the server could fix and three are defects in the document alone.

The two type mismatches were measured rather than guessed, against
`tests/Pgsql/snapshots/contract-admin.json`, which records the scalar type of every key of
159 routes:

- **Eleven of the fifteen properties the document types `boolean` ship as the integer
  `0`/`1`**, across about twenty routes: `spoiled`, `is_aggregated_amount`,
  `track_date_only`, `rollover`, `is_rescheduled`, `is_reassigned`, `need_fulfilled`,
  `need_fulfilled_with_shopping_list`, `prices_incomplete`, `show_as_column_in_tables` and
  `input_required`. `db/pgsql/baseline/01_tables.sql` stores every flag as `SMALLINT`
  holding `0`/`1`, deliberately, and nothing converted these eleven on the way out. The
  twelfth in the same family, `ProductDetailsResponse.has_childs`, is a bare `boolval()` one
  line away from `is_aggregated_amount` in `StockService::GetProductDetails()`, which is
  what makes this an omission rather than a convention.
- **Fifty-four properties carry `format: date-time` and exactly one of them is RFC 3339.**
  The rest render as `"2019-05-03 18:24:04"` — what PostgreSQL renders for a `TIMESTAMP`
  column, with no `T` separator and no offset. The document's own examples say so
  throughout. The one genuine case is `observed_at` on the label evidence endpoint, whose
  column is a `TIMESTAMPTZ` and whose value is parsed with `new DateTimeImmutable()`.

  All fifty-four are on the pre-label schema. The label surface added by migrations 0269 to
  0272 keeps absolute instants in `TIMESTAMPTZ` and renders them with an offset, and
  `TimeResponse.time_utc` is UTC rather than the configured zone. The rendering this fork
  sends is therefore not uniform, and a rule written as though it were would not survive
  contact with the label routes. The first draft of decision 2 therefore stated its rule
  over the surface it was measured on and named three renderings outside it. The revised
  decision 2 removes the non-uniformity instead: every timestamp moves to one rendering.

The consequences differ in kind, which is why the two are decided differently below. An
integer where a boolean was promised fails a strict decoder on that field; for `spoiled`
the failing response is a booking's own result, so the stock has already moved when the
client reports an error. A non-RFC-3339 timestamp fails the *whole* response, on
essentially every stock read — but the value itself is not wrong. Only the label on it is.

ADR-0005 settles which side moves when the two *engines* disagree: "the conforming answer
is the one the OpenAPI spec documents, and the porting work moves whichever engine is
wrong". Both engines agree here. Nothing in the corpus said what happens when the document
is the party that is wrong, and answering "the document is always right" would commit this
project to rendering RFC 3339 across fifty-four fields on the strength of a `format`
keyword nobody deliberately chose.

On 2026-10-04 the decider chose RFC 3339 deliberately, and for
a stronger reason than the keyword: a timestamp should be an instant on the wire and in
storage. The decider's answers above record this.

## Decision

1. **A property the document types `boolean` is `true`/`false` on the wire.** The eleven
   move; `services/WireBooleans.php` names them per response shape and converts at the same
   boundary `FieldPolicy` redacts at — `BaseApiController::FilteredApiResponse()`, the two
   generic entity reads, and the hand-built responses that already know their own entity
   name. This is ADR-0005's rule applied, not an exception to it: the document said boolean
   and the server was wrong.

   Three things it deliberately does not do:

   - **It does not touch the flags the document types `integer`.** `undone` sits in the
     same `stock_log` row as `spoiled` and stays an integer, as do `active`,
     `no_own_stock` and the rest. That inconsistency is real; settling it means changing
     what the document promises, which is a different decision from making the server
     keep the promise it already made.
   - **It does not convert in SQL.** `CAST(x AS BOOLEAN)` in a view would work through
     pdo_pgsql — that is the hazard `db/pgsql/baseline/05_views_l2.sql:346` and
     `05_views_l3.sql:40` already describe — but the differential suite's `views` phase
     compares against the frozen SQLite line, SQLite has no boolean type, and six of the
     eleven come from views that phase reads.
   - **It does not convert by column name alone.** Userfields are household-defined
     key/value pairs attached under a `userfields` key, so a household with a userfield
     named `spoiled` would otherwise have its value answered as `true`.

2. **Every timestamp this API stores or sends is an exact instant. It is stored as
   `TIMESTAMPTZ`, sent as RFC 3339 in UTC with a `Z` suffix, and documented as
   `format: date-time`.** *Revised 2026-10-04 by the decider. As first written, this
   decision went the other way: it kept the wire unchanged and corrected the document
   instead (option B, now rejected).*

   - **Storage.** The legacy schema's `TIMESTAMP` columns behind the fifty-four properties
     migrate to `TIMESTAMPTZ`. Each existing value is a wall-clock time in the server's
     configured zone, and the migration converts it on that basis
     (`col AT TIME ZONE '<configured zone>'`). When daylight saving ends, one clock hour
     occurs twice. A value in that repeated hour is read as the **earlier** of the two
     instants, the same rule
     [ADR-0028](0028-a-timestamp-a-write-route-cannot-read-is-refused.md) applies to a new
     write without an offset. The import of a legacy SQLite database converts upstream's
     wall-clock values the same way. The label surface (migrations 0269 to 0272) already
     stores `TIMESTAMPTZ` and does not migrate.
   - **Rendering.** `YYYY-MM-DDTHH:MM:SSZ`, for example `2026-10-04T18:30:00Z`, where the
     record stored `2026-10-04 14:30:00` on a server set to `America/New_York`. The `Z` is
     part of the contract: a client may compare these values as text.
   - **One rule, no exceptions.** The three renderings that the record as first written
     set aside are now covered by it:
     - `TimeResponse.time_utc` renders as `2026-10-04T18:30:00Z`. Its former rendering
       used the local format with no offset.
     - `observed_at` on the label evidence endpoint is already RFC 3339. It is normalised
       to `Z`.
     - The label surface's `TIMESTAMPTZ` values are covered too. `labels.retired_at` on
       `GET /labels/resolve/{code}` changes from `2026-03-04 05:06:07.891011-05` to
       `2026-03-04T10:06:07…Z`; whether the fraction is kept is open question 2. The same
       applies to `expires_at` on the two worker-credential routes, which `->format('c')`
       currently renders with the server's offset.
   - **The write fields echo it.** `tracked_time` on chore execution and battery charge
     and `done_time` on task completion accept what ADR-0028 lists. They are stored as
     instants and rendered back by this rule.
   - **Dates stay dates.** A field whose column is a SQL `DATE` is a calendar date, not an
     instant. It keeps `YYYY-MM-DD` and `format: date`. This covers `best_before_date`,
     `purchased_date`, `Task.due_date`, `CurrentTaskResponse.due_date` and
     `ProductPriceHistory.date`. The last three were typed `date-time` and are corrected to
     `format: date`.
   - **Each viewer sees their own zone.** The browser converts each instant to the viewing
     device's zone for display. It therefore sends writes with an explicit offset: an
     offset-free value is read in the server's configured zone (ADR-0028), which is not
     necessarily the zone the viewer typed the value in. The consequences below state what
     this costs.
   - **Until this is implemented, the wire is unchanged.** The server still sends local
     wall-clock strings, and the document still describes them as it does today, with the
     `pattern` [PR #234](https://github.com/datagen24/victual/pull/234) added and no
     `format`. The document changes in the same commit as the wire, under
     [ADR-0005](0005-wire-contract-is-the-invariant.md). Until then, first-party clients
     accept both renderings and send RFC 3339, which ADR-0028 already accepts.

   **The three write fields' history, kept for the record.** When this record was first
   written, `helpers/extensions.php`'s `IsIsoDateTime()` accepted exactly `Y-m-d H:i:s`.
   The controllers silently ignored a value in any other format and booked the current
   time instead, so documenting the fields as `format: date-time` invited a generated
   client to send a value that would be thrown away.
   [ADR-0028](0028-a-timestamp-a-write-route-cannot-read-is-refused.md) settled the
   question this record left open: it refuses what it cannot read and accepts the RFC
   3339 forms.

3. **The three document-only defects are fixed in the document.** `GET /user` is an array of
   `UserDto`, which is what `GetUsersAsDto()->where(...)` serialises to. The
   `GET /objects/{entity}` union's members each declare `required` properties no other
   member has, and `LocationResolved` joins it, so `Product` — which required nothing and
   therefore matched every JSON object — stops swallowing every entity's rows. What that
   buys is bounded and the consequences below state the bound: the ten members become
   mutually exclusive, forty-four of the forty-seven entities with no member stop matching
   anything, and three keep matching one. And a
   `Content-Type` carrying a `charset` parameter is a media type with a parameter (RFC 9110
   §8.3), parsed rather than string-compared, which is the same parse Slim's own
   `BodyParsingMiddleware` already does to decide the body was JSON.

4. **The two generic write bodies stop sharing the read union.** `POST /objects/{entity}`
   and `PUT /objects/{entity}/{objectId}` document a `GenericEntityWrite` object rather than
   the nine entity schemas. This follows from decision 3 and from what the server
   implements: it takes whatever keys the body carries, drops the two it owns (`id` and
   `row_created_timestamp`), and `PUT` writes only the keys present. A partial body is
   therefore a partial update, and nothing validates the body against a schema. Sharing the
   schemas would now mean documenting `id` as mandatory on a create and forcing a
   read-modify-write for every partial update.

5. **What decision 2 costs, and therefore its implementation scope.** The record as first
   written listed these costs as the reason to defer the change. The decider has chosen to
   pay them:

   - **The migration**: about fifty-four columns move to `TIMESTAMPTZ`, and every SQL view
     that reads them is rewritten. The differential suite's `views` phase compares against
     the frozen SQLite line, which has no timezone-aware type, so that phase needs an
     accepted difference or a normalised comparison.
   - **A renderer** that writes every instant in the decision 2 format. PostgreSQL's ISO
     output (`2026-10-04 18:30:00+00`) is not RFC 3339, so values still have to be
     rewritten on the way out. The draft identified timestamps in a response by field name
     only, and called that a second, weaker copy of the schema. Once the columns are
     `TIMESTAMPTZ`, the renderer may be able to use the column's type instead of the
     field's name, but that is not yet verified. Without it, the field-name approach and
     its risk remain.
   - **Coordinated changes** to plan 18's MQTT payloads, the iCal feed, and the browser
     code that reads and writes these values. That code converts to the device's zone for
     display and sends writes with an offset.
   - **Every Victual-owned client.** They already accept both renderings under decision 2's
     interim rule.
   - **ADR-0028's storage step** changes from storing a wall-clock time to storing an
     instant. That record carries the change.
   - **The parity suite** gains an accepted difference: upstream sends local wall-clock
     strings, and this fork sends UTC instants that should describe the same moment.

## Options considered

**A. Render RFC 3339 and keep `format: date-time`.** **The decision, as of 2026-10-04**,
taken further than the option as first written. Storage migrates to `TIMESTAMPTZ`, the
wire carries UTC with `Z`, and the API's former exceptions are brought under the same rule.
The document becomes correct, every value is an unambiguous instant, and generated clients
get real date types. The costs the record first used to reject this option are listed
under decision 5, and the decider has accepted them.

**B. Document what is sent.** *Proposed as the decision when this record was written, and
rejected by the decider on 2026-10-04.* It would have kept a generated client decoding at
the price of a date type. It would also have left every timestamp dependent on a configured
zone the value does not carry, and kept three exceptions to the rule.

**C. Retype the eleven booleans as `integer` and change nothing on the wire.** Symmetrical
with B, and the API's prevailing convention — `undone`, `active` and `no_own_stock` are all
documented integers. Rejected: unlike the timestamps, the value here really is wrong.
`0` is not a boolean in any reading, `has_childs` beside it already converts, and the cost
of moving is bounded and measurable — the contract snapshot named every route, and the
browser code that reads these fields compares with `== 1`, which is true for `true`.

**D. Split `GET /objects/{entity}` into per-entity paths.** Real discrimination and real
types for all fifty-seven listable entities. Rejected here as disproportionate to the
defect: it is a new route surface, and it contradicts the generic-entity design the route
exists to provide. Its own record is where that would be decided.

**E. Close the union's members with `additionalProperties: false`.** The only thing that
would separate `uihelper_shopping_list` from `shopping_list`, which no required property can
do because the first is a superset of the second. Rejected as unavailable rather than as
wrong: nine of the ten members declare fewer properties than their entity's rows carry —
`Product` leaves sixteen of `products`' forty-two columns undeclared, `ShoppingListItem` two
of `shopping_list`'s eight — so closing them today would stop the ten *intended* entities
decoding as well. Completing the ten schemas first is the same work option D describes at a
tenth of the scale, and is where this would be decided.

## Consequences

- **Every timestamp on the wire changes once decision 2 is implemented.** This is a breaking
  change on essentially every read route. For example, `"2026-10-04 14:30:00"` becomes
  `"2026-10-04T18:30:00Z"` on a New York server. A consumer that displays the string
  without parsing it will show UTC. That includes a Home Assistant template, an iCal
  subscriber that reads the raw value, or a script. It is the reason decision 5 lists
  every consumer that has to move with the change.
- **Two people viewing the same record can see different clock times.** The browser shows
  each viewer their own device's zone. A chore done at 14:30 in New York appears as 19:30 to
  a household member viewing from London. Boundaries the server computes stay in the
  server's configured zone: "due today", "overdue", "expires in N days", and the day a
  `DATE` falls on. A viewer in another zone can therefore see a record due "today" whose
  displayed time falls on their tomorrow. The decider accepted this mismatch in exchange for
  showing each viewer their own time.
- **The browser must send an offset.** If the browser shows a viewer their own zone but
  sends an offset-free value, that value is read in the server's zone, and the booking
  lands at the wrong instant. `moment().format('YYYY-MM-DD HH:mm:ss')` in
  `choretracking.js`, `choresoverview.js`, `batterytracking.js`, `batteriesoverview.js` and
  `tasks.js` must become a format that carries an offset. A chore whose `track_date_only` is
  set is the exception: it still sends a bare date, which has no offset to carry.
- **The upgrade is a data migration over live history.** Every existing timestamp is
  reinterpreted from a wall-clock time to an instant, using the configured zone in force
  when the migration runs. A server whose zone was changed after data was written will
  convert its older rows at the new zone's offset. Nothing in the stored data can detect or
  correct that.
- **Eleven fields change value type on the wire**, on about twenty routes. The browser is
  unaffected: every reader of these fields in `public/viewjs/` compares with `== 1`, `!= 1`
  or truthiness, and the Blade views that compare with `== 1` in PHP read from the
  non-API controllers, which are not on this path. `tests/Pgsql/snapshots/contract-*.json`
  are regenerated, and the regeneration is the evidence: 60 lines, all `integer` →
  `boolean`, and no other key changed.
- **The fork-versus-upstream parity suite gains one accepted difference**,
  `issue-230-documented-booleans` in `.devtools/parity/harness/lib/accepted.js`. Its matcher
  demands that the two sides agree about the value — upstream `1` against `true`, upstream
  `0` against `false` — so a flag genuinely set differently is still reported.
- **Forty-four of the forty-seven listable entities with no schema in the
  `GET /objects/{entity}` union stop decoding at all** in a strict client, where before
  every one of them decoded as a `Product` with most of its fields discarded. That is
  deliberate and it is the improvement: a loud failure in place of a silent wrong answer.
  Writing their schemas is separate work.
- **Three are still a candidate for a member that is not theirs, and this record does not
  pretend otherwise. [Issue 648](https://github.com/datagen24/victual/issues/648) tracks the
  fix; the decider accepts the residual only while that issue is open.** A `stock_log` row carries `id`, `stock_id` and `product_id`, which is
  everything `StockEntry` requires; a `product_barcodes_view` row carries `barcode` and
  `product_id`, which is everything `ProductBarcode` requires; and `uihelper_shopping_list`
  is a superset of `shopping_list`, so it carries `id` and `shopping_list_id` and matches
  `ShoppingListItem`. Each is a candidate for exactly one member, and nothing else in the
  union's shape rules it out. Whether candidacy becomes a wrong decode is the reader's to
  decide, and since the members' nullability was modelled (the next bullet) both readers
  answer the same way. A strict JSON Schema validator now accepts these rows against the
  member that is not theirs. `swift-openapi-generator` — the client this record was written
  for — accepted them already, taking an explicit `null` for an optional property through
  `decodeIfPresent`. Candidacy is therefore the whole of the decision for both, and the row
  is decoded under a schema that is not its own.
  Required properties make the ten members mutually exclusive, which is what issue #232
  asked for; they do not separate those ten from every other relation this route can list,
  and nothing short of option E or D would. `tests/Pgsql/WireContractTest.php` measures
  all fifty-seven listable entities against all ten members — off real responses where the
  fixture gives an entity a row, and off the relation's columns where it does not, with the
  two checked against each other. It pins the result, so a fourth cannot appear unnoticed.
- **Candidacy is what is measured, and it is not the same as a successful decode.** The
  same test validates real rows against members with a JSON Schema validator. **When this
  record was written it validated every row against all ten members, and exactly one
  pairing survived**: `locations_resolved` against `LocationResolved`. Every other candidate
  failed the same way — a column that is NULL in the row against a member that declared it a
  non-nullable scalar (`description` on `Product`, `Chore`, `Location` and `QuantityUnit`;
  `note` on `ShoppingListItem`; `config` on `Userfield`; `shopping_location_id` on
  `StockEntry` and `ProductBarcode`). Six
  of those ten were the union's *own* intended pairings, so it was a gap in the members'
  nullability and never a defence against the three unintended ones — it failed the intended
  pairings first.

    **That gap is closed.** `fix: align API response nullability with PostgreSQL`
    (`c6d27881`, 2026-09-28) types every one of those properties as its column allows —
    `["string", "null"]` and `["integer", "null"]`. The test no longer records which
    pairings validate. It now validates each row against the members that row is a
    *candidate* for, rather than against all ten, and asserts that **every** such pairing
    passes on every row, with no errors.

    So the three unintended candidacies above are now decodes rather than near misses,
    which is the reading the bullet before this one states. Closing them still needs option
    E or option D. Every row of every entity is validated, not one per entity: validity
    turns on values, so a row whose nullable columns happen to be set could validate where
    another does not.

- **`victual-kit` sheds two workarounds now and a third later.** The two that go now are
  the middleware that strips the charset parameter and the boolean remapping in its
  specification normalizer. The date transcoder that accepts both renderings **stays until
  decision 2 is implemented**, under that decision's interim rule. It then shrinks to plain
  RFC 3339, with the `format: date-time` the document will carry. `victual-kit` still
  reads `GET /objects/{entity}` outside its generated client for any entity without a
  schema, until issue 648 is fixed.
- **A generated client loses its typed write body** for the two generic entity write
  routes. What it loses was not real: the union was matched by declaration order, so every
  entity's create body was already a `Product`, and two of `Product`'s properties were
  documented as settable when the server drops them.
- **A new phase, `wirecontract`**, holds the regression tests
  (`tests/Pgsql/WireContractTest.php`). What it checks about the booleans is a *pair*, not a
  name: every `(schema, property)` the document types `boolean` is declared against the
  `WireBooleans` shape responsible for it, and every `(shape, property)` `WireBooleans`
  converts is read back off a named route and asserted to be a boolean on a row that carries
  it. A name alone would have been satisfied by `spoiled` appearing anywhere in the
  conversion map, so documenting `spoiled` on a second schema — one whose response converts
  nothing — would have passed. Pairing them also found three entries in the conversion map
  that convert nothing. `chores_current.rollover` is removed: the view reads
  `chores.rollover` to compute the next execution and does not project it, so no row carries
  the key. `userfield_values_resolved` is kept and recorded as unproven, because no route
  reaches it — the one reader reduces its rows to key/value pairs — while
  `Userfield.show_as_column_in_tables` still documents the property, so the conversion has a
  promise to serve if a route ever answers those rows whole. The test asserts it is still
  unreachable, so the day a route makes it reachable this has to be revisited rather than
  quietly becoming untrue.
- **`uihelper_stock_journal` was the third, and was removed rather than kept.** It was
  recorded as unproven for the same reason at first, but its case was not the same: the only
  schemas that documented the property, `StockJournal` and `StockJournalSummary`, were
  referenced by no path at all. `StockJournal.spoiled` was therefore a documented boolean no
  response could carry — a promise to nobody — and the pair of dead schemas was deleted from
  the document, which took the conversion's reason to exist with it. The two views they
  described are untouched and still feed the Blade journal pages
  (`StockController::Journal()` and `::JournalSummary()`); what is gone is the claim that the
  API answers them. **Exposing the journal over the API was the alternative and was
  deliberately not taken here.** Plan 14 already lists "a stock journal read" among the gaps
  it says are "argued explicitly rather than slipped in", and that argument is surface
  growth, not the documented-boolean cleanup this record is. `WireContractTest` pins the
  deletion from both ends, so re-declaring either schema fails until that argument is made.
- **The journal pair was not the only unreferenced schema, and the rest are classified
  rather than swept.** Measuring the document's reachability properly — seeded from
  everything outside `components/schemas`, because `components/parameters` carries `$ref`s
  too and the paths name *derived* enums rather than the base vocabularies they are computed
  from — leaves twelve schemas no path references, and they are four different things.
  **Six are read by PHP at request time**, so the reference is a property lookup no walk over
  the JSON can see: `OpenApiController::DocumentationSpec()` derives the four `ExposedEntity_*`
  enums from `StringEnumTemplate` and the `ExposedEntity`/`NoEdit`/`NoDelete`/`NoListing`
  lists, and `GenericEntityApiController` reads `ExposedEntityEditRequiresAdmin` to gate
  userfield writes behind `ADMIN`. **Three describe rows `GET /objects/{entity}` answers
  today** — `Task`, `StorageClass` and `ProductGroupResolved` — and are not members of that
  route's union, which that route's own description already states is deliberate.
  **`Error500` is the `dev` error body**, rendered by `ExceptionController` when
  `displayErrorDetails` is on and by nothing in production, where no route documents a 500 at
  all. **`ApiKey` and `Session` were the fourth kind and went the way of the journal pair**:
  `api_keys` is the sole member of `ExposedEntityNoListing` and `sessions` is not an
  `ExposedEntity`, so both reads answer 400. Here the journal's deferral does not apply:
  these relations hold a key's hash and a live session key, and `ExposedEntityNoListing`
  exists to stop the generic route answering the first of them. Exposure is therefore not
  a gap plan 14 lists but something the design refuses. What still describes a key is
  `CurrentUserCapabilities`, which answers `key_type` and `read_only` about the calling
  credential. The classification itself is the pin: `WireContractTest` re-runs the walk and
  requires the answer to be exactly the ten classified names, and each of the three kinds has
  a test behind its claim, so a new unreferenced schema cannot arrive without making one of
  them true.

## Open questions

Decision 2's revision raised these. None blocks recording the decision; each must be
answered before decision 2 is implemented.

1. **`TimeResponse.time_local`.** By definition it is the server's local wall-clock time.
   Should it carry the server's offset (`2026-10-04T14:30:00-04:00`), which is the one
   place a non-`Z` RFC 3339 value would remain, or be retired in favour of `time_utc` and
   the configured zone name?
2. **Fractional seconds.** The legacy columns hold whole seconds. The label surface's
   `TIMESTAMPTZ` values hold microseconds. Should the renderer truncate to whole seconds
   everywhere, or send whatever precision is stored?
3. **The differential suite's `views` phase.** Should the phase gain an accepted difference
   for the `TIMESTAMPTZ` columns, or compare after normalising both sides to instants?

## Acceptance prerequisites

This record changes a wire contract, so accepting it requires:

1. The decider confirms decisions 1 and 2. **Met 2026-10-04**, with decision 2 revised:
   the eleven booleans move the wire (confirmed), and the timestamps also move the wire,
   to RFC 3339 in UTC over `TIMESTAMPTZ` storage, under one rule with no exceptions. The
   first draft proposed the opposite for timestamps.
2. The decider confirms decision 4, the one change here that no issue asked for. **Met
   2026-10-04.**
3. The decider accepts that `stock_log`, `product_barcodes_view` and `uihelper_shopping_list`
   are still candidates for a member that is not theirs. Since `c6d27881` modelled the
   members' nullability, each is now a successful decode under a schema that is not its own,
   rather than a candidate a strict validator would reject. Closing that needs option E or
   option D rather than more `required` properties. **Met conditionally, 2026-10-04.** The
   decider accepts the residual only while
   [issue 648](https://github.com/datagen24/victual/issues/648) is open and tracked. The
   accepting pull request must link the issue in that state, or show it fixed.
4. `.devtools/pgsql/run-tests.sh all` green on a working copy, with the `contract` phase
   passing against the committed snapshot rather than regenerating it. Stated in the
   accepting pull request with the date and the working copy it was run against.
5. **Decision 2 is implemented and demonstrated**, in separate changes from the acceptance:
   - the `TIMESTAMPTZ` migration, run against a copy of real data that contains a value in
     a repeated fall-back hour, showing the earlier instant was chosen
   - the contract snapshot regenerated, with every timestamp in the decision 2 format and
     every `DATE` unchanged
   - the browser showing the device's zone and sending writes with an offset
   - the MQTT payloads, the iCal feed and `victual-kit` updated
   - the parity suite's accepted difference for timestamps, checked to compare instants
     and not just accept anything
   - the open questions above answered
