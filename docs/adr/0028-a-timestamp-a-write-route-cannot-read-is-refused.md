# ADR-0028: A timestamp a write route cannot read is refused, and the readable set is widened to RFC 3339

- **Status:** Proposed.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-21.
- **Relationship:** [ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
  decision 2 decided that this API's timestamps are local wall-clock strings and that the
  document says so. **Nothing of that decision is superseded here**, and it could not be:
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
   | `2026-09-21T14:30:00.123Z` | the same, fractional seconds discarded |

   All three routes accept all of it. The chore route's bare date stops being a local
   exception and becomes the rule, which is what it should have been: three fields with one
   name and three accepted sets is how the next caller discovers the same defect again.

3. **An absent field still means "use the current time", and the boundary is the presence of
   the key rather than the usefulness of the value.** This is the documented default and what
   every "do this now" button in `public/viewjs/` relies on. `null` and the empty string are
   values that are present, so they are refused: "I sent you something you could not use" is
   never answered by booking a different time, whatever the something was.

4. **Parsing is a fixed list of formats, not `new DateTimeImmutable($value)`.**
   `services/Labels/PrintEvidenceService.php:35` uses the constructor for `observed_at`, the
   one genuinely RFC 3339 field in this API, and that is right there: it is worker-submitted
   telemetry on a `TIMESTAMPTZ` column. It is wrong here. The constructor also accepts `now`,
   `tomorrow`, `+1 week` and `@1600000000`, and it silently rolls `2026-02-30` over to the
   2nd of March — which would replace one silent wrong answer with a smaller family of them
   on exactly the rows this record exists to make trustworthy.

5. **The document says what is accepted, in the same commit.** Each of the three fields
   carries a `pattern` covering the whole accepted shape and a description naming the
   refusal; the API-level "Dates and times" paragraph PR #234 added, which stated the silent
   ignore, says this instead. A test asserts that the documented pattern and the server agree
   about every rendering in both directions, because a pattern looser than the server puts a
   caller back where issue #231 left them and one tighter refuses in a generated client what
   the server would have taken.

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
