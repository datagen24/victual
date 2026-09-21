# ADR-0028: A timestamp a write route cannot read is refused, and the readable set is widened to RFC 3339

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-21.
- **Relationship:** [ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
  decision 2 decided that the legacy surface's timestamps are local wall-clock strings and
  that the document says so — and, since the revision of 2026-09-21, names the three
  renderings that sit outside that rule (`TimeResponse.time_utc`, `observed_at`, and the
  label surface's `TIMESTAMPTZ` columns). The three write fields this record is about are
  inside it: what they store is a local wall-clock string, whatever rendering it arrived in. **Nothing of that decision is superseded here**, and it could not be:
  0027 is Proposed, so there is no accepted decision to supersede. Decision 2 also
  *describes* what the server does with the three write fields — "silently ignores a value
  in any other rendering and books the current time instead" — and this record is what stops
  that sentence being true. The sentence is corrected in place in the same change, with a
  pointer here, because leaving a record under review stating something the tree no longer
  does is how a record gets accepted for a reason that has expired.
  [ADR-0005](0005-wire-contract-is-the-invariant.md) is why the OpenAPI document moves in
  the same commit as the behaviour; [ADR-0024](0024-the-fork-writes-its-own-clients.md)
  decision 1 is why a breaking change is available at all, and why it is not free.
- **Referenced by:** [17 — Ecosystem clients](../plans/17-ecosystem-clients.md), whose
  catalogue this adds to; `helpers/extensions.php`'s `ParseApiDateTime()` and
  `BaseApiController::RequestedTimestamp()`, which implement it;
  `tests/Pgsql/WireContractTest.php`, which holds the regression.

## Context

Three API write routes take an optional timestamp:

| Route | Field |
|---|---|
| `POST /api/chores/{choreId}/execute` | `tracked_time` |
| `POST /api/batteries/{batteryId}/charge` | `tracked_time` |
| `POST /api/tasks/{taskId}/complete` | `done_time` |

Each was written as a single `if`:

```php
if (array_key_exists('tracked_time', $requestBody) && IsIsoDateTime($requestBody['tracked_time']))
{
	$trackedTime = $requestBody['tracked_time'];
}
```

`IsIsoDateTime()` was `DateTime::createFromFormat('Y-m-d H:i:s', $s)` plus a round-trip
equality check, so it accepted exactly `2026-09-21 14:30:00` and nothing else. **A value in
any other rendering fell through the `if`, and the route booked the current time**, answering
200 with nothing said about the caller's value having been discarded. `2026-09-21T14:30:00Z`
did that. So did a bare date on two of the three routes, a typo, and `null`.

That is not a theoretical caller. The first-party Swift client (ADR-0024) generates from
`victual.openapi.json`, and until [PR #234](https://github.com/datagen24/victual/pull/234)
these three fields were typed `format: date-time` — which is an instruction to a generated
client to send RFC 3339, the one rendering guaranteed to be thrown away. That pull request
(issue [#231](https://github.com/datagen24/victual/issues/231)) changed the document to
describe the format actually accepted and to state that anything else is silently ignored.
It deliberately did not change the behaviour, because "refuse" and "widen" are opposite
answers and choosing between them is a decision.

The two failures are different in kind and both are real:

- **The silent substitution is a data defect.** A chore execution, a charge cycle and a task
  completion are rows a person is expected to be able to read and undo. Booking one at a
  time the caller did not name, and answering 200, produces a wrong row that nothing reports
  and nothing can distinguish from a right one afterwards. The constitution's "the stock
  ledger is exact history" is about the stock ledger, but the reasoning does not stop at its
  edge.
- **Refusing RFC 3339 is a usability defect of this API's own making.** The rendering the
  document invited is the rendering a generated client produces, and converting it is four
  lines. Refusing it and nothing else would answer 400 to a timestamp whose meaning is
  unambiguous.

`ChoresApiController` additionally accepted a bare `IsIsoDate()` date and the other two did
not. That asymmetry was not a decision either: `public/viewjs/choretracking.js` and
`choresoverview.js` send `YYYY-MM-DD` for a chore whose `track_date_only` is set, and the
chore route grew a second predicate to take it. The other two routes have no such caller and
so never grew one.

## Decision

1. **A timestamp field that is present must be readable, or the request is refused with 400
   naming the field.** No value a write route cannot use is ever answered by booking a
   different time. The refusal names the field and the accepted renderings.

2. **The readable set is widened to the renderings a client plausibly sends**, normalised to
   the one this API stores (`Y-m-d H:i:s`, local wall clock in the server's configured zone,
   per ADR-0027 decision 2). `helpers/extensions.php`'s `ParseApiDateTime()` is the one
   place that decides:

   | Sent | Stored |
   |---|---|
   | `2026-09-21 14:30:00` | itself — the storage rendering, unchanged |
   | `2026-09-21` | `2026-09-21 00:00:00` — a bare date is its midnight |
   | `2026-09-21T14:30:00` | `2026-09-21 14:30:00` — no offset means the server's zone |
   | `2026-09-21T14:30:00Z`, `…+02:00` | that instant, rendered in the server's zone |
   | `2026-09-21T14:30:00.123456789Z` | the same, fractional seconds discarded, however many |

   **The `T` forms are RFC 3339 *shaped* and this is deliberately not that grammar**, in
   both directions, which is worth naming because calling the set "RFC 3339" would repeat
   the defect this record is about — a document promising something the server does not do.
   RFC 3339 requires an offset and this accepts a value without one, because a wall clock in
   the server's configured zone is what the rest of the API speaks and there is no reason to
   make a client invent an offset to say what it means. RFC 3339 permits a leap second `:60`
   and this refuses it: `createFromFormat()` reads `2016-12-31T23:59:60Z` as
   `2017-01-01 00:00:00`, so accepting it would book a different day without a word, which
   is decision 1's whole subject. Nothing behind this API can hold a leap second either —
   the columns are `TIMESTAMP`.

   All three routes accept all of it. The chore route's bare date stops being a local
   exception and becomes the rule, which is what it should have been: three fields with one
   name and three accepted sets is how the next caller discovers the same defect again.

3. **An absent field still means "use the current time", and the boundary is the presence of
   the key rather than the usefulness of the value.** This is the documented default and what
   every "do this now" button in `public/viewjs/` relies on. `null` and the empty string are
   values that are present, so they are refused: "I sent you something you could not use" is
   never answered by booking a different time, whatever the something was.

4. **The documented `pattern` is the gate, and it is one string.**
   `helpers/extensions.php`'s `API_DATE_TIME_PATTERN` is what `ParseApiDateTime()` matches a
   value against before any parsing happens, and it is byte for byte what the three fields
   carry as their `pattern` in `victual.openapi.json`; a test asserts that identity. The
   parse that follows decides one further question only — whether the date and time exist.

   This is structural rather than tidy. Written as two independent expressions they drift,
   and on the first review of this record they had: `DateTimeImmutable::createFromFormat()`
   is considerably more forgiving than its format strings suggest, and was accepting
   `+0200`, `+02`, `GMT`, a single-digit hour and a doubled separator space — none of them
   promised anywhere — while the document's `(\.\d+)?` promised fractional seconds of any
   length that PHP's `u` will not parse past six digits, so `.NET`'s round-trip format
   (seven) and Go's `RFC3339Nano` (up to nine) were refused for carrying precision this
   API discards. Fractional seconds are now taken off the value rather than parsed.

   **Every component of the pattern is range-bounded**, which is part of the decision and
   not formatting. As plain `\d{2}` it matched `+99:99`, and `createFromFormat()` read that
   as an offset of a hundred hours *without a warning* — so a booking the caller dated the
   4th of March was stored on the 28th of February. An offset nobody wrote is the same
   silent reinterpretation decision 1 exists to remove, one layer down, and it is worse
   than the original defect: the original discarded the caller's value, this one keeps it
   and means something else by it. `+24:00`, `05:60:07` and `2026-13-04` were in the same
   family. Bounding hours, minutes, seconds, offset hours, offset minutes, months and days
   in the pattern refuses all of them before anything is parsed.

5. **The parse behind the gate is a fixed list of formats, not `new DateTimeImmutable($value)`.**
   `services/Labels/PrintEvidenceService.php:35` uses the constructor for `observed_at`, the
   one genuinely RFC 3339 field in this API, and that is right there: it is worker-submitted
   telemetry on a `TIMESTAMPTZ` column. It is wrong here. The constructor also accepts `now`,
   `tomorrow`, `+1 week` and `@1600000000`, and it silently rolls `2026-02-30` over to the
   2nd of March — which would replace one silent wrong answer with a smaller family of them
   on exactly the rows this record exists to make trustworthy.

6. **The document says what is accepted, in the same commit.** Each of the three fields
   carries the pattern above and a description naming the refusal; the API-level "Dates and
   times" paragraph PR #234 added, which stated the silent ignore, says this instead. The
   agreement is tested over the whole shape space rather than a sample: tens of thousands
   of generated spellings, over boundary values for every component, asserting that
   **nothing the document refuses is accepted** and that the only values it accepts and the
   server refuses are those naming a day the month does not have — `2026-02-30`,
   `2026-04-31` — which is the one thing a regular expression cannot decide. A pattern
   looser than the server puts a caller back where issue #231 left them; one tighter
   refuses in a generated client what the server would have taken.

   The boundary values are load-bearing. The first version of this corpus carried no
   impossible minute, second or offset, so it did not see `+99:99`; a reviewer did. A
   property test is only as good as the edges it is given.

## Options considered

**A. Refuse, and widen nothing.** A present-but-unreadable value is a 400; the accepted set
stays `Y-m-d H:i:s` (plus the chore route's bare date). Truthful and the smallest change.
Rejected: it answers 400 to `2026-09-21T14:30:00Z`, a value whose meaning is not in doubt,
sent by a client this project writes and generates from its own document — and the document
is what asked for that rendering in the first place. It also leaves the three fields
accepting three different sets.

**B. Widen, and refuse nothing.** Parse everything parseable and keep falling back to the
current time for the rest. Rejected: it keeps the defect. A value that will not parse is
exactly the case where the caller most needs to be told, and it shrinks the silent-substitution
window rather than closing it, which makes the remaining cases rarer and therefore harder to
find.

**C. Widen and refuse.** The decision. It costs a caller who was relying on the fallback —
see the consequences — and it is the only option under which a booking's timestamp is either
the one the caller named or an error.

**D. Refuse in a middleware, from the document's `pattern`.** Validate every request body
against the OpenAPI schema generically. Attractive and much larger than this defect: the
document does not describe most write bodies accurately enough to validate against (ADR-0027
decision 4 is one instance, the 47 listable entities with no schema another), so turning it
into an enforcement mechanism is a project, not a fix. Its own record is where that is
decided.

## Consequences

- **A caller currently relying on the fallback gets a 400 where it used to get a 200.** This
  is a breaking change on three routes, and it is the point of the record rather than a side
  effect. Concretely it breaks a caller sending a rendering none of the six formats matches
  — and such a caller was already not getting the timestamp it asked for, so what breaks is
  a wrong result becoming a visible error.
- **A caller sending a bare date to battery charge or task completion changes meaning**, from
  "book the current time" to "book midnight of that date". No caller in this tree does it;
  the change is named here because it is the one case where a request that used to succeed
  still succeeds and stores something different.
- **The browser is unaffected.** All six senders were read rather than assumed:
  `batterytracking.js`, `batteriesoverview.js` and `tasks.js` send
  `moment().format('YYYY-MM-DD HH:mm:ss')`; `choretracking.js` and `choresoverview.js` send
  that too, **except** for a chore whose `track_date_only` is set, where both switch to
  `YYYY-MM-DD` — which is why decision 2 keeps the bare date rather than refusing it, and why
  option A would have broken a page. The date/time inputs are free text, but
  `public/viewjs/components/datetimepicker.js:317` parses the value with
  `moment(value, format, true)` and sets `setCustomValidity("error")` when it does not parse,
  so the form will not submit a rendering the server would now refuse. Both cases are covered
  by `WireContractTest::testTheTwoRenderingsTheBrowserSendsAreAccepted`.
- **`IsIsoDateTime()` is deleted.** `ParseApiDateTime()` subsumes it and it had no caller
  left. `IsIsoDate()` stays — five stock and recipe routes use it for `best_before_date` and
  `purchased_date`, which are SQL `DATE` columns and a different question.
- **A client may send fractional seconds of any length.** They are discarded, so refusing a
  value for carrying more of them than PHP parses would have been a refusal over precision
  this API throws away.
- **Two `format: date-time` write fields' worth of Swift client workaround goes away**, and
  `victual-kit` can send whatever its date encoder produces for these three fields.
- **Nothing else that takes a date changes.** `best_before_date`, `purchased_date` and
  `RecipesController`'s `start` still use `IsIsoDate()` and still fall through silently when
  they do not match. That is the same defect in a different family, on `DATE` columns whose
  callers this record did not examine, and it is left for its own change rather than widened
  into here.

## Acceptance prerequisites

This record changes a wire contract, so accepting it requires:

1. The decider confirms decision 1 — that a present-but-unreadable timestamp becomes a 400,
   which is a breaking change for any caller relying on the fallback.
2. The decider confirms decision 3's treatment of `null` and the empty string as present
   values, which is the one place the rule could reasonably have gone the other way.
3. `.devtools/pgsql/run-tests.sh all` green on a working copy, with the `contract` phase
   passing against the committed snapshot rather than regenerating it. Stated in the
   accepting pull request with the date and the working copy it was run against.
