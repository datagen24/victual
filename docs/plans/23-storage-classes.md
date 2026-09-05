# 23. Storage classes for locations

**Goal:** A location declares *how cold it is kept*, not merely whether it is a freezer.
Deep freeze, freezer, fridge, cooler and ambient are distinguishable, so a wine cooler, a
cheese cave and a medication fridge stop being the same thing.
**Depends on:** nothing. Interacts with [08](08-nested-locations.md), whose Q3 answer this
adopts unchanged.
**Consumed by:** [22](22-medication-tracking.md), which needs a product to be able to
require a class. Extracted from 22 per its Q1 — this is a general locations feature that
medication happens to need first, and it changes a column every client of `/objects/locations`
can see, which is not a change that should arrive as a side effect of a medication plan.
**Status:** draft for review.

## Today

`locations` has been flat with a boolean since `migrations/0002.sql`:

```
id, name (unique), description, row_created_timestamp, is_freezer, active
```

`is_freezer` drives due-date arithmetic through `products.default_best_before_days_after_freezing`
and `_after_thawing`, and `products.should_not_be_frozen` is the guard against putting the
wrong thing in one. That is the whole vocabulary: frozen, or not frozen.

The household already has four things on the wrong side of that boolean — a wine cooler, a
cheese cave, a medication fridge and a deep freeze. All four are `is_freezer = 0` except the
last, which is `is_freezer = 1` and thereby indistinguishable from a domestic freezer holding
peas. Nothing in the tree can express that 2–8 °C is a requirement rather than a preference,
which is why [22](22-medication-tracking.md) cannot be built on what exists.

## Proposed change

### Schema

New `storage_classes`: `name` (unique), `min_temp_c`, `max_temp_c`, `treats_as_freezer`,
`sort_order`, `active`. Seeded — **in PHP, not in the baseline DDL**, per
[ADR-0003](../adr/0003-seed-data-in-php.md) — with Deep freeze, Freezer, Fridge, Cooler and
Ambient. The table is user-extensible because the seed cannot anticipate a cheese cave's set
point.

Temperatures are stored as data rather than encoded in an enum, because the cases that forced
this plan differ by *set point* and not by kind: a wine cooler and a cheese cave are the same
sort of appliance held at different temperatures, and a class list that cannot express that
would need a new enum member per appliance.

`locations` gains `storage_class_id INTEGER`, nullable. NULL means unclassified, which is what
every existing row means today and therefore what the migration leaves them as — no
behavioural change on upgrade.

### `is_freezer` stays, and stays truthful

`is_freezer` keeps its exact meaning, its exact type and its exact values, derived from the
class on write via `storage_classes.treats_as_freezer`. The freeze/thaw due-date arithmetic is
untouched, and so is every consumer of `/objects/locations`.

This is what keeps the change additive under
[ADR-0005](../adr/0005-wire-contract-is-the-invariant.md): one new field appears, no existing
field changes meaning. A version of this plan that replaced `is_freezer` with the class would
be a contract break for the sake of tidiness, and would break the due-date path in the same
stroke.

A location with a NULL class keeps whatever `is_freezer` it has. Derivation applies when a
class is set, which makes the two consistent going forward without rewriting history.

**Decided (Q1, Q2).** `is_freezer` is derived, not independently editable, and the derivation
lives in the application write path rather than in a trigger. The column therefore has two
modes during the transition — authoritative on an unclassified location, derived on a
classified one — which is the price of Q3's answer that a class may be absent, and is paid
deliberately rather than by backfilling a guess.

### Views, API, UI

No new views. `locations_resolved`, when [08](08-nested-locations.md) builds it, carries the
class alongside everything else it carries; nothing here needs it first.

`locations` gains `storage_class_id`; `storage_classes` becomes a new `ExposedEntity`. Both
additive.

The location form gains a class picker. The freezer checkbox becomes a derived display rather
than an input wherever a class is set — which is the only visible behaviour change in this
plan, and the one most likely to surprise someone who has been ticking that box for two years.

### Migration

One pair, claiming **0267** in [RESERVATIONS.md](../../migrations/RESERVATIONS.md) before any
file is written, per [ADR-0004](../adr/0004-engine-specific-migrations.md). It is a table, a
column and a seed — no views, and **no triggers**, since Q2 put derivation in the application
— so the dual-engine tax here is the small kind, and stays that way.

## Interaction with 08

[08](08-nested-locations.md) Q3 answered whether `is_freezer` inherits down a location tree:
**it does not**, and a child location defaults its flag from its parent at creation instead.
Storage class adopts that answer unchanged, for the same reason and with the same
default-from-parent behaviour.

08's Q5 response is the case that proves it: the real layout has
`Basement / StorageRoom / UprightFreezer / Door`, where the freezer is level 3 and the thing
stock actually points at is level 4. Under non-inheritance, `Door` carries the class itself.
That is the fixture this plan should be tested against too.

## Interaction with 22

[22](22-medication-tracking.md) adds `required_storage_class_id` to its own medication master
data and warns on a mismatch — the product declares a requirement, the location declares a
capability, and the comparison is between two fields a human entered. That comparison lives in
22, not here. This plan supplies the vocabulary and nothing else.

## Open questions

1. **Is `is_freezer` derived, or does it stay independently editable with the class as
   advisory metadata?** Derived means one source of truth and a checkbox that stops being an
   input. Independent means the two can disagree, and a location marked Fridge while
   `is_freezer = 1` will eventually be found by someone debugging a thaw date. *Lean: derived.
   Two fields that can disagree about the same physical fact is the thing this plan exists to
   remove, not to double.*

   > **Response, 2026-09-04:** Derived. The checkbox becomes a display wherever a class is
   > set, and the derivation is the only writer of `is_freezer` from that point.
   >
   > The consequence worth stating is the one this creates with Q3: because a class may be
   > absent, `is_freezer` is authoritative on an unclassified location and derived on a
   > classified one, and the tree will hold both kinds indefinitely. The alternative —
   > backfilling every location so the column is derived everywhere — was considered and
   > rejected, and the reason is not tidiness. Backfilling means writing Freezer where
   > `is_freezer = 1`, which is correct by construction, and **Ambient everywhere else**,
   > which is a guess. NULL and Ambient are not the same claim: NULL says nobody has
   > classified this, Ambient says someone has classified it as room temperature. Plan
   > [22](22-medication-tracking.md) warns on a mismatch between a product's required class
   > and its location's, so a guessed Ambient would make that check fire against data no
   > human entered — and a warning derived from a guess is the kind of thing
   > [ADR-0015](../adr/0015-medication-records-never-advises.md) exists to keep out of a
   > medication surface. The two modes are cheaper than that.

2. **Where does derivation live — a trigger, or the application?** A trigger catches
   `bin/victual-db-import` and any future direct writer; application-level derivation is
   easier to read and is bypassed by exactly those paths. *Lean: trigger, on the grounds that
   the importer is a first-class path under
   [ADR-0008](../adr/0008-postgresql-only-runtime-engine.md) rather than a corner case, and a
   derived column that the importer silently leaves wrong is a defect nobody would think to
   look for.* Costs a trigger pair under the dual-engine discipline.

   > **Response, 2026-09-04: the application — the lean is overturned, and by its own
   > argument.** The lean rested on the importer, and the importer cannot produce the
   > inconsistency it was worried about. `bin/victual-db-import` reads upstream grocy
   > SQLite, which has `is_freezer` and **no storage class at all**, so every imported
   > location arrives with a NULL class. Under Q1's answer a NULL class means `is_freezer`
   > stays as it came in, which is exactly right — there is nothing for a trigger to derive,
   > and a trigger placed there would fire on no rows.
   >
   > What is left of the lean is a future direct writer that does not exist, which is not
   > worth a trigger pair on both engines plus its differential proof — a real cost under
   > the live dual-engine discipline, paid against a speculative caller.
   >
   > **This does not automatically settle [22](22-medication-tracking.md) Q4**, which asks
   > the same trigger-versus-application question about copying medication stock attributes
   > on a split. 22 Q4 said the two should be answered together or the difference explained;
   > here is the difference. This question's trigger case was the importer, and it
   > evaporated. That one's is a `StockService` call site somebody forgets to update when a
   > fourth split path is added — a live risk in code that already has three, not a
   > speculative one. Same question, different facts, so a different answer there would be
   > consistent rather than contradictory.

3. **May a location have no class?** *Lean: yes, and every existing row starts that way. A
   migration that forced a class would have to guess, and guessing "Ambient" for a row whose
   `is_freezer = 1` would be wrong in the one case that matters.*

4. **Do the seeded temperature ranges mean anything to the code, or are they documentation?**
   Nothing in v1 resolves a class *from* a temperature, so the ranges are metadata that
   happens to be structured — which also means the touching boundaries (Cooler 8–15, Ambient
   15–25) are harmless. *Lean: keep them structured anyway. [22](22-medication-tracking.md)'s
   excursion handling wants to compare an observed temperature against a range, and a range
   stored as two numbers is ready for that while a range stored in a name is not.*

5. **Celsius only, or a display unit?** There is no per-user unit setting in the tree to hang
   a conversion on. *Lean: store and display °C in v1; a display conversion is a settings
   question, not a schema one, and can be added without touching this.*

6. **Does a class carry an excursion tolerance** — how far out of range, for how long, before
   it matters? *Lean: no, and deliberately. That number is the beginning of a judgement about
   whether a product is still usable, which [ADR-0015](../adr/0015-medication-records-never-advises.md)
   says this project does not make, and it should not be designed before there is real sensor
   data to look at. 22 Q8 owns the question.*

## Effort

Small. A table, a nullable column, a seed, a form control and one derivation decision. The
only part that is not mechanical is Q2, and the only part that will surprise a user is the
checkbox becoming a display.
