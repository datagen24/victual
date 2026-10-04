# victual-kit after issue #650: client handoff

Issue #650 sequences the server first and the endpoint clients afterwards. This file is
what the client work needs. The server change is on branch
`claude/opus5_api-timestamps-rfc3339-utc-b4bb3d`; regenerate from the `victual.openapi.json`
at the commit that merges it, not from a later one.

## What changed on the wire

- Every instant is RFC 3339 in UTC with exactly six fractional digits:
  `2026-10-04T18:30:00.000000Z`. The document types each one `format: date-time` with
  the pattern `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$`.
- `TimeResponse.time_local` is the one exception: the same instant with the server's
  offset, `2026-10-04T14:30:00.000000-04:00`. `time_local_sqlite3` has the same rendering or
  is the empty string, so it carries no `format`.
- Calendar dates stay `YYYY-MM-DD`, typed `format: date`. That now includes
  `Task.due_date`, `CurrentTaskResponse.due_date` and `ProductPriceHistory.date`.
- The three write fields (`tracked_time` on chore execution and battery charge,
  `done_time` on task completion) accept RFC 3339 with an offset, and keep a fraction to the
  microsecond. A value without an offset is read in the server's zone. `null` and `""` are
  refused; omit the key to mean "now".

## Measured against the current client

On 2026-10-04, against victual-kit at `3b3677a`, Foundation's `ISO8601DateFormatter` (the
first thing `VictualDateTranscoder.decode` tries) decoded all of these on macOS 27.0.1:

| Value | Decoded |
|---|---|
| `2026-10-04T18:30:00.000000Z` | 1791138600.0 |
| `2026-10-04T18:30:00.123456Z` | 1791138600.123 |
| `2026-10-04T14:30:00.000000-04:00` | 1791138600.0 |

So the interim claim in ADR-0027 holds: the shipped client decodes the new rendering, and
its zone-less fallback still decodes the old one. The client does not need to change before
the server rolls out.

## Required changes after the server lands

1. Copy `victual.openapi.json` from the merge commit into `openapi/` and
   `Sources/VictualAPI/openapi.json`, and regenerate. Generated properties for instants
   become `Date`; the three DATE fields become strings decoded by `VictualDates.day`.
2. `VictualDateTranscoder` can drop the zone-less formats (`yyyy-MM-dd HH:mm:ss`,
   `yyyy-MM-dd'T'HH:mm:ss`) and the server-zone lookup they need, once every server the app
   talks to is at migration 0301. With one maintained deployment that is the day it upgrades;
   keep the fallback until then.
3. Precision. `ISO8601DateFormatter` keeps milliseconds and drops the rest
   (`.123456` decodes as `.123`). Values the server writes with whole seconds are
   unaffected. The label surface's values carry microseconds; if the client ever compares
   two label times for equality or order, parse the fraction itself, or compare the strings
   (fixed width and UTC, so text order is chronological order).
4. Writes: keep sending RFC 3339 with an offset (the generated `ISO8601DateTranscoder`
   already sends `Z`). Never send `null` for the three write fields.

## Tests to add in victual-kit

- Decode every instant field of a response captured from the merged server, and assert the
  `Date` equals the instant in the string.
- Decode `time_local` and `time_utc` from one `GET /api/system/time` and assert equality.
- Assert that a DATE field (`best_before_date`) is not shifted by the device's zone.
- Round-trip a chore execution with an explicit offset and read back the same instant.

Prerequisite 5 of ADR-0027 names `victual-kit` updated. It is not met until this work is
done and verified in that repository.
