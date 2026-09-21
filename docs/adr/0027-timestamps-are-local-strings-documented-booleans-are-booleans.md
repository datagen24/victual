# ADR-0027: The API's timestamps are local wall-clock strings, and its documented booleans are booleans

- **Status:** Proposed.
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
  `TimeResponse.time_utc` is UTC rather than the configured zone, so the rendering this
  fork sends is not uniform and a rule written as though it were would not survive contact
  with the label routes. Decision 2 states the rule over the surface it was measured on and
  names the three renderings outside it.

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

## Decision

1. **A property the document types `boolean` is `true`/`false` on the wire.** The eleven
   move; `services/WireBooleans.php` names them per response shape and converts at the same
   boundary `FieldPolicy` redacts at — `BaseApiController::FilteredApiResponse()`, the two
   generic entity reads, and the hand-built responses that already know their own entity
   name. This is ADR-0005's rule applied, not an exception to it: the document said boolean
   and the server was wrong.

   Three things it deliberately does not do. It does not touch the flags the document types
   `integer` — `undone` sits in the same `stock_log` row as `spoiled` and stays an integer,
   as do `active`, `no_own_stock` and the rest. That inconsistency is real; settling it
   means changing what the document promises, which is a different decision from making the
   server keep the promise it already made. It does not convert in SQL: `CAST(x AS BOOLEAN)`
   in a view would work through pdo_pgsql — that is the hazard
   `db/pgsql/baseline/05_views_l2.sql:346` and `05_views_l3.sql:40` already describe — but
   the differential suite's `views` phase compares against the frozen SQLite line, SQLite
   has no boolean type, and six of the eleven come from views that phase reads. And it does
   not convert by column name alone: userfields are household-defined key/value pairs
   attached under a `userfields` key, so a household with a userfield named `spoiled` would
   otherwise have its value answered as `true`.

2. **The legacy surface's timestamps are local wall-clock strings and the document says so.**
   Every date and time rendered or accepted by the routes over the pre-label schema — whose
   date columns are SQL `TIMESTAMP`, and which is where all fifty-four mistyped properties
   are — is `YYYY-MM-DD HH:MM:SS` in the server's configured zone, documented as a `string`
   with that `pattern` and no `format`. A field whose column is a SQL `DATE` carries
   `format: date`, which it already satisfies — `Task.due_date`,
   `CurrentTaskResponse.due_date` and `ProductPriceHistory.date` were typed `date-time` and
   are dates.

   Three renderings sit outside that rule and are named, because the same rule stated over
   *every* timestamp this API sends would be false on the day it was accepted:

   - **`TimeResponse.time_utc` is UTC**, not the configured zone:
     `ApplicationService::GetSystemTime()` renders it through `new DateTimeZone('UTC')`. It
     keeps the local *shape*, and therefore the `pattern`, with the zone carried by the
     property name rather than by the value. The document described it as local time —
     `time_local`'s description, verbatim — which this record corrects.
   - **`observed_at` on the label evidence endpoint is RFC 3339** and keeps
     `format: date-time`. It is the only property in the document carrying that keyword.
   - **The label surface keeps its own clocks.** Migrations 0269 to 0272 store absolute
     instants as `TIMESTAMPTZ`, deliberately, and what PostgreSQL renders for one carries a
     UTC offset and fractional seconds: `2026-03-04 05:06:07.891011-05`. `labels.retired_at`
     on `GET /labels/resolve/{code}` is the one such value in a response body this document
     describes, and it is documented as an opaque `string` with neither the `pattern` nor a
     `format`. `expires_at` on the two worker-credential routes is rendered with
     `->format('c')`, which is RFC 3339, inside responses the document types only as
     `object`. That surface is a newer schema with a different rule about absolute time, and
     retyping it is not what this record is for.

   The three write fields in this family — `tracked_time` on chore execution and battery
   charge, `done_time` on task completion — are documented the same way, and their
   description says what the server does: `helpers/extensions.php`'s `IsIsoDateTime()`
   demands exactly `Y-m-d H:i:s`, and the controllers' `if` **silently ignores** a value in
   any other rendering and books the current time instead. Documenting them as
   `format: date-time` was worse than inaccurate there: it invited a generated client to
   send RFC 3339 and have its timestamp discarded without a word.

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
   `row_created_timestamp`), and `PUT` writes only the keys present, so a partial body is a
   partial update and nothing validates the body against a schema. Sharing the schemas would
   now mean documenting `id` as mandatory on a create and forcing a read-modify-write for
   every partial update.

5. **Moving the rendering to RFC 3339 stays available, and is not done here.** What it would
   take is named so a later record can cost it: a response normaliser keyed by field name
   (nothing else knows which strings are timestamps — the values come from base tables
   through LessQL, from the SQL views, and from service-built arrays), an offset resolved
   per instant rather than from the current zone so it survives a DST boundary, and
   coordinated updates to plan 18's MQTT payloads, the iCal feed, the browser code that
   reads these strings, and every Victual-owned client. A superseding ADR is how that
   happens, not a patch.

## Options considered

**A. Render RFC 3339 and keep `format: date-time`.** The document would be right and the
values would be correct for any consumer. Rejected for now, under decision 5: it is a
wire change on fifty-four fields whose cheapest implementation is a name-keyed response
normaliser — a second, weaker copy of the schema, sitting where a bug in it silently
rewrites data — and it needs a timezone rule this project has never had to state.

**B. Document what is sent.** The decision. It costs a generated client the convenience of a
date type and gains it a response that decodes, which is the trade the issue was raised
about. It also makes the document say something true about the three write fields, where
`format: date-time` was actively misleading.

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
- **Three still decode under the wrong member, and this record does not claim otherwise.**
  A `stock_log` row carries `id`, `stock_id` and `product_id`, which is everything
  `StockEntry` requires; a `product_barcodes_view` row carries `barcode` and `product_id`,
  which is everything `ProductBarcode` requires; and `uihelper_shopping_list` is a superset
  of `shopping_list`, so it carries `id` and `shopping_list_id` and matches
  `ShoppingListItem`. Each matches exactly one member, so `oneOf` is satisfied and the row
  is decoded silently under a schema that is not its own. Required properties make the ten
  members mutually exclusive, which is what issue #232 asked for; they do not separate those
  ten from every other relation this route can list, and nothing short of option E or D
  would. `tests/Pgsql/WireContractTest.php` measures all fifty-seven listable entities
  against all ten members — off real responses where the fixture gives an entity a row, and
  off the relation's columns where it does not, with the two checked against each other —
  and pins the result, so a fourth cannot appear unnoticed.
- **`victual-kit` sheds three workarounds** — the middleware that strips the charset
  parameter, the date transcoder that accepts both renderings, and the boolean remapping in
  its specification normalizer — and keeps reading `GET /objects/{entity}` outside its
  generated client for any entity without a schema.
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
  deliberately not taken here**: plan 14 already lists "a stock journal read" among the gaps
  it says are "argued explicitly rather than slipped in", and that argument is surface
  growth, not the documented-boolean cleanup this record is. `WireContractTest` pins the
  deletion from both ends, so re-declaring either schema fails until that argument is made.

## Acceptance prerequisites

This record changes a wire contract, so accepting it requires:

1. The decider confirms decisions 1 and 2 as written — in particular that the eleven
   booleans move the wire and the fifty-four timestamps move the document, which are
   opposite answers to superficially similar questions, and that decision 2's rule is stated
   over the legacy surface with `time_utc`, `observed_at` and the label surface's
   `TIMESTAMPTZ` renderings named as sitting outside it.
2. The decider confirms decision 4, the one change here that no issue asked for.
3. The decider accepts that `stock_log`, `product_barcodes_view` and `uihelper_shopping_list`
   still decode under a member that is not theirs, and that closing that needs option E or
   option D rather than more `required` properties.
4. `.devtools/pgsql/run-tests.sh all` green on a working copy, with the `contract` phase
   passing against the committed snapshot rather than regenerating it. Stated in the
   accepting pull request with the date and the working copy it was run against.
