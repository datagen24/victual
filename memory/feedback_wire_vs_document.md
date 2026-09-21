---
name: Wire moves or document moves
description: When the OpenAPI document and the server disagree, which side the operator wants moved — decided per field on 2026-09-21 for issues #229-#233.
type: feedback
---

Asked on 2026-09-21, on issues #229-#233 (the defects the Swift client found), the operator
chose **opposite answers to two superficially identical questions**:

- **Eleven documented booleans shipping as 0/1 → the wire moves**, all eleven, not just the
  two the issue named. `services/WireBooleans.php`, [PR 234](https://github.com/datagen24/victual/pull/234).
- **54 fields typed `format: date-time` that are not RFC 3339 → the document moves.**
  Drop the format, describe the rendering. Not "render RFC 3339".
- **The undiscriminated `oneOf` → required properties plus the missing schema**, not a
  discriminator and not per-entity paths.

**Why:** the deciding test is which side is actually *wrong*, not which is cheaper. `0` is
not a boolean in any reading and the cost of moving was measurable (the contract snapshot
named every route). A timestamp rendered as local wall-clock is not wrong — only the
`format` label on it was — and converting would need a response normaliser keyed by field
name, which is a second, weaker copy of the schema.

**How to apply:** when the document and the server disagree, do not reach for one rule.
Measure the blast radius from `tests/Pgsql/snapshots/contract-admin.json` first — it records
the scalar type of every key of 159 routes — then present the per-field choice with the
count attached. The operator answers quickly when the options carry numbers.

Recorded as [ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
(Proposed). Related: [[feedback_verification_discipline]].
