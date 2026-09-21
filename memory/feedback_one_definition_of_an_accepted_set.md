---
name: One definition of an accepted set
description: When a set of accepted inputs is written twice — a schema pattern and parsing code — they drift, and a test that compares them by sampling will not notice; make one of them the gate.
type: feedback
---

From [PR 235](https://github.com/datagen24/victual/pull/235) (2026-09-21), which fixed three
write routes that silently booked the current time when `tracked_time` / `done_time` was not
exactly `Y-m-d H:i:s`. The fix was small. Getting it *right* took four review findings and
one maintainer-found defect, and every one of them came from the same two mistakes.

## Mistake 1: the accepted set was written twice

An OpenAPI `pattern` said what a client may send; a list of `createFromFormat()` formats
decided what the server takes. Two descriptions of one set, and they disagreed in both
directions at once:

- `createFromFormat()` is far more forgiving than its format strings suggest. It was taking
  `+0200`, `+02`, `GMT`, a single-digit hour and a doubled separator space — none documented.
- The pattern's `(\.\d+)?` promised fractional seconds of any length; PHP's `u` stops at six,
  so `.NET`'s seven-digit round-trip format was refused while the document said it was fine.

**How it is fixed:** `API_DATE_TIME_PATTERN` in `helpers/extensions.php` is one string. It is
the gate `ParseApiDateTime()` matches a value against *before* parsing, and a test asserts it
is byte-identical to what the three schemas carry. The parse behind it answers one further
question only — whether the date and time exist.

## Mistake 2: the test compared the two sides by sampling

`testTheDocumentedPatternDescribesExactlyWhatIsAccepted` walked a hand-written list and
passed. Two things it could not do:

- **It missed edges it was not given.** The list had no impossible minute, second or offset.
- **It structurally cannot see "both sides agree and both are wrong."** `+99:99` matched the
  unbounded `\d{2}` *and* parsed — `createFromFormat()` read it as a hundred-hour offset, so
  a booking the caller dated 4 March was stored on 28 February. That is **worse** than the
  defect being fixed: the original discarded the caller's value, this one kept it and meant
  something else by it.

**How it is fixed:** the corpus walks the cross product of boundary values for every
component (tens of thousands of spellings) and asserts nothing the document refuses is
accepted. And a value that is *wrong* rather than merely undocumented goes through the HTTP
refusal path, where the assertion is "400 and nothing booked" — not "the two sides agree".

## Why: this is the defect the record exists to stop, reappearing inside the fix for it

Three of the four review findings were the document promising what the code does not do.
[ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
and [ADR-0028](../docs/adr/0028-a-timestamp-a-write-route-cannot-read-is-refused.md) both
exist to stop exactly that.

A related one worth keeping: "RFC 3339" was the wrong name for the accepted set, in both
directions — that grammar requires an offset this accepts without, and permits a leap second
this refuses. Naming a set after a standard it does not match is the same defect in miniature.

## How to apply

- When a set of accepted inputs exists in both a schema and code, make one of them the gate
  and assert the identity. Do not maintain two and test that they agree.
- A property test is only as good as the edges it is given; write the impossible values in
  deliberately.
- A test that compares two implementations finds drift, not shared error. Anything that could
  be *wrong* rather than merely inconsistent needs an assertion about the outcome — the
  status code and the rows written — not about agreement.
- Say what a format is, not which standard it resembles.

See [[feedback_verification_discipline]] for what counts as having checked any of this, and
[[reference_local_environment]] for the suite's blind spot that hid the last defect: it runs
on UTC, so no test in it could reach a wall clock a zone skipped.
