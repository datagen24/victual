# ADR-0005: The JSON on the wire is the invariant — with two accepted exceptions

- **Status:** **Accepted.** The overriding rule of the porting work.
- **Decider:** datagen24 (maintainer), retrospectively — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30, retrospectively.
- **Referenced by:** [14](../plans/landed/14-contract-and-regression-scaffolding.md),
  [17](../plans/17-ecosystem-clients.md), and every porting hazard in
  [db/pgsql/README.md](../../db/pgsql/README.md).
- **Extended by:** [ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
  (Proposed), which answers the question this record does not — which side moves when both
  engines agree and the *document* is what is wrong. Nothing here is superseded by it.

## Context

Seventeen documented hazards exist because the same query returns subtly different values
on the two engines: booleans leaking into output, integer division, `CAST` truncating on
one side and rounding on the other, window functions returning `bigint`, aggregates
returning `NUMERIC`. Each needed a rule for which side was correct.

## Decision

**The engine may change; the JSON on the wire may not.** Where the two engines disagree,
the conforming answer is the one the OpenAPI spec documents, and the porting work moves
whichever engine is wrong.

## Accepted exceptions

Two differences are known, deliberate, judged harmless, and **must not be "fixed"**:

- **Float accumulation order.** `products_average_price.price` can be
  `4.124499999999999` on SQLite and `4.1245` on PostgreSQL. Summing floats in a different
  order changes the last bit; the discrepancy is ~1e-15 and is not stable on SQLite either.
  Rounding would change the documented value. It reaches
  `uihelper_product_details.average_price`, `uihelper_stock_current_overview.average_price`
  and `recipes_resolved.costs` / `costs_per_serving`; only `products_average_price` is an
  `ExposedEntity`.
- **`chores.start_date` where the stored value has no time.** SQLite returns
  `"2025-01-01"`; PostgreSQL's `TIMESTAMP` renders `"2025-01-01 00:00:00"`. `chores` *is*
  an `ExposedEntity`. `DATE` is not an option — the chore form is a datetimepicker and the
  `default_start_date_when_empty` triggers write a real time — and
  `"2025-01-01 00:00:00"` is the more conformant rendering of the documented
  `format: date-time` anyway. Only a date-only string differs, and
  `trigdifftest.php` confirmed this is the only such column across all 37 tables.

  **"The more conformant rendering" was too generous, and a client author reading this
  exception would reasonably conclude it is narrower than it is.** Neither rendering is
  RFC 3339: `"2025-01-01 00:00:00"` has no `T` separator and no offset either, and that is
  true of every one of the fifty-four fields the document typed `format: date-time`, not
  just this column. What is specific to `chores.start_date` is only the engine
  *disagreement*, which is what this exception is about and which stands. The type itself
  is [ADR-0027](0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)'s
  (Proposed), which drops `format: date-time` wherever the server does not send RFC 3339 -
  this field included - and documents the rendering instead.
  [Issue 231](https://github.com/datagen24/victual/issues/231) is where it was found.

  **This exception has one consequence beyond the column itself**, found by the parity
  suite in 2026-09 and recorded here rather than as a third exception because it is not
  an independent decision — it is this one, seen downstream. `chores_current`'s `daily`
  branch takes the time of day out of `start_date` *positionally* upstream, with
  `SUBSTR(CAST(h.start_date AS TEXT), -8)` (`migrations/0185.sql:44`). Given
  `"2025-01-01"` that reads `"25-01-01"`, so `DATETIME("2026-02-04 " || "25-01-01")` is
  `NULL` and `next_estimated_execution_time` collapses for the whole chore. The port
  reads the same time semantically, with `to_char(h.start_date, 'HH24:MI:SS')`
  (`db/pgsql/baseline/05_views_l2.sql:63`), which a `TIMESTAMP` column cannot make
  malformed. So a chore given a date-only `start_date` and then executed answers
  `"2026-02-04 23:59:59"` here and `null` upstream, on `GET /api/chores` and
  `GET /api/chores/{id}`. **This fork is the conforming side** — the spec documents the
  field as a non-nullable `format: date-time` string — so matching upstream would mean
  reproducing a string-slicing bug in typed SQL, which is what this ADR exists to
  prevent. `chores` is an `ExposedEntity` and the value also reaches
  `chores_log.scheduled_execution_time`, the chores overview, `CalendarService` and
  [18](../plans/18-mqtt-state-publication.md)'s MQTT payloads. `.devtools/pgsql/`
  structurally cannot see it: every chore it seeds carries a full timestamp, and with a
  time present the two idioms are the same function.

A third was recorded as accepted and then withdrawn, which is worth keeping visible: the
`qu_factor_*` `TEXT`-versus-number difference was excused on the grounds that no affected
view was an `ExposedEntity`. **That reasoning was too generous** — PostgreSQL was already
the conforming side, and a sibling view had always cast correctly, so one view conformed
and two did not for no reason anyone had chosen. `0256.sqlite.sql` fixed it. The lesson is
that "not on a public endpoint" is not by itself grounds for accepting a difference.

## Consequences

- An accepted difference has to name what it touches and whether any of it is exposed.
- The differential suite is what makes this rule testable rather than aspirational, which
  is the dependency [ADR-0008](0008-postgresql-only-runtime-engine.md) has to answer for.
