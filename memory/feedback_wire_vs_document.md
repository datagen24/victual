---
name: Wire moves or document moves
description: When the OpenAPI document and the server disagree, which side the operator wants moved — decided per field on 2026-09-21 for issues #229-#233.
type: feedback
---

Asked on 2026-09-21, on issues #229-#233 (the defects the Swift client found), the operator
chose **opposite answers to two superficially identical questions**:

- **Eleven documented booleans shipping as 0/1 → the wire moves**, all eleven, not just the
  two the issue named. `services/WireBooleans.php`, [PR 234](https://github.com/datagen24/victual/pull/234).
- **54 fields typed `format: date-time` that are not RFC 3339 → the document moved on
  2026-09-21, and the operator reversed that on 2026-10-04: the wire moves.** Every
  timestamp is stored as `TIMESTAMPTZ` and sent as RFC 3339 in UTC (`Z`), with no
  exceptions. `DATE` columns stay `YYYY-MM-DD`. The browser shows the viewer's device zone.
  ADR-0027 decision 2 was revised in place.
- **The undiscriminated `oneOf` → required properties plus the missing schema**, not a
  discriminator and not per-entity paths.

**Why:** the deciding test is which side is actually *wrong*, not which is cheaper. On
2026-10-04 the operator judged that a timestamp without a zone *is* wrong, and paid for the
migration rather than document a value with no zone. `0` is
not a boolean in any reading and the cost of moving was measurable (the contract snapshot
named every route). A timestamp rendered as local wall-clock is not wrong — only the
`format` label on it was — and converting would need a response normaliser keyed by field
name, which is a second, weaker copy of the schema.

**How to apply:** when the document and the server disagree, do not reach for one rule.
Measure the blast radius from `tests/Pgsql/snapshots/contract-admin.json` first — it records
the scalar type of every key of 159 routes — then present the per-field choice with the
count attached. The operator answers quickly when the options carry numbers, and better
still when each option shows a concrete before/after example. The 2026-10-04 reversal
came from an interview that showed both renderings side by side.

Recorded as [ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
(Proposed). Related: [[feedback_verification_discipline]].
