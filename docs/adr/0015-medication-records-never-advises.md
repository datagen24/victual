# ADR-0015: Victual records medication; it never advises

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-04, alongside [plan 22](../plans/22-medication-tracking.md). The
  decision was made when the plan was drafted; this record is not a backfill. **Revised
  2026-10-09** for the inventory scope the maintainer set that day (see Context). The status
  stays Proposed.
- **Relationship:** constrains [22](../plans/22-medication-tracking.md) throughout, and
  [23](../plans/landed/23-storage-classes.md) Q6 defers to it. Written with
  [ADR-0016](0016-schedule-expansion-in-the-application.md), whose subject has no consumer
  in the revised scope (see its Disposition). The scope it governs is described by
  [ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md),
  [ADR-0041](0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md) and
  [ADR-0042](0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md), all Proposed.
- **Would affect:** [02](../plans/02-mcp-endpoint.md),
  [18](../plans/18-mqtt-state-publication.md), [17](../plans/17-ecosystem-clients.md).

## Context

### Revision, 2026-10-09

The maintainer narrowed [plan 22](../plans/22-medication-tracking.md) on 2026-10-09. It now
covers medication and vitamin inventory, private consumption recipes, organizer locations,
consumption entered by hand or sent by a client, and refill notices. The earlier proposal
for per-person regimens, a dose scheduler, administrations, adherence and dose alerts is
withdrawn. This record keeps the original argument below, because it explains why the line
sits where it does, and revises the decision to fit the new scope.

### Original context (2026-09-04)

[Plan 22](../plans/22-medication-tracking.md) then put drug strength, route, dose, schedule and
per-person regimens into the database. Once a system holds those five things it is one small,
useful feature away from clinical decision support, and it will be one small feature away
permanently.

The features are individually reasonable and that is the problem. Interaction warnings. A
maximum-daily-dose check. Duplicate-therapy detection when two products share an active
ingredient. "You missed the 08:00 dose — take it now, or skip to the next one." A mg/kg
calculator for the dog. An allergy flag on a subject that cross-references what is being
administered. Each is a plausible afternoon's work; each is the kind of thing a household
member would ask for the week after the module ships.

Three facts make the aggregate a bad idea:

1. **Clinical advice can bring medical-device obligations.** The FDA's Non-Device CDS
   exclusion requires all four criteria in section 520(o)(1)(E) of the FD&C Act.
   These include intended use by a health care professional and enabling that professional
   to independently review the basis for recommendations without relying primarily on them.
   Victual is a household inventory app intended for lay users. Clinical recommendations
   directed to those users do not meet these FDA conditions. See the
   [FDA's Non-Device CDS criteria](https://www.fda.gov/medical-devices/digital-health-center-excellence/step-6-software-function-intended-provide-clinical-decision-support).

   In the EU, [MDR Annex VIII, Rule 11](https://eur-lex.europa.eu/eli/reg/2017/745/oj?locale=en)
   provides a risk-based classification framework for software that qualifies as a medical
   device. Software supplying information for diagnostic or therapeutic decisions is
   Class IIa by default. It is Class IIb when those decisions could cause serious health
   deterioration or require surgery, and Class III when they could cause death or
   irreversible health deterioration. Rule 11 does not supply the FDA's clinician-use
   and independent-review exclusion.

   The project excludes clinical recommendations to keep its intended purpose within
   household inventory and recording. Describing a recommendation feature as household
   use does not establish an exclusion from medical-device requirements.
2. **The knowledge cannot be maintained here.** An interaction table is only useful if it is
   current, and this fork has one maintainer whose interest is inventory. A stale warning is
   worse than no warning, because a warning that has ever appeared teaches the reader that
   silence means safe.
3. **The failure mode is asymmetric.** A wrong stock count wastes a trip to the shop.
   A wrong dose assertion has more serious consequences. Care elsewhere in the application
   does not reduce the consequences of a wrong dose assertion.

There is a fourth fact specific to this fork: [02](../plans/02-mcp-endpoint.md) puts a
language model in front of this data. A model asked "can I take these together?" will answer
from whatever it is given, and it will answer fluently. That raises the stakes on what the
tools look like, not merely on what they return.

## Decision (proposed)

**Victual records what a person did and what is physically present. It does not evaluate,
warn, calculate or recommend about medication.**

The line, stated so it can be applied to a feature request without re-arguing this record:

> **Arithmetic over data the household entered is in scope. Any assertion requiring knowledge
> the household did not enter is not.**

**In scope.**

- Stock quantities, units the household entered, expiry and storage, and transfers between
  locations such as weekly organizers.
- A consumption recipe: a list of products and quantities a person wrote down and can
  consume together ([ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md)).
- A recorded consumption, entered by hand or reported by the person's own device
  ([ADR-0041](0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md)).
- A refill estimate: a calendar date computed from the fill date and supply the person
  entered and a rule the household chose, shown with the rule that produced it, and a
  notice that the date is approaching or has arrived
  ([ADR-0042](0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)).
- A comparison between two values the household entered, such as a quantity against an
  existing stock amount.

**Out of scope, permanently.**

- Drug-drug, drug-food and drug-condition interaction checking.
- Dose-range or maximum-dose validation against any external reference, and weight- or
  age-based dose calculation.
- Duplicate-therapy or same-ingredient detection.
- Allergy and contraindication checking.
- Importing or embedding any clinical drug knowledge base.
- Missed-dose guidance of any kind.
- Dose scheduling, recurrence expansion, adherence or missed-dose classification, and dose
  alerts or reminders. These left the scope on 2026-10-09 and returning them needs a new
  decision, because a reminder is behaviorally a prompt to act.

A refill estimate is an inventory fact. It must not say or imply that an insurer or pharmacy
will approve a refill, that a person has too little medication, or that they should
change how they take it.

### Wording

UI copy, API field names, error messages, notices and MCP tool descriptions state facts and
never give instructions.

| Use | Avoid |
|---|---|
| "Estimated reorder date 2026-03-18 (from your last fill)" | "Time to reorder", "Reorder now" |
| "Reorder date reached" | "You are running out", "You will run out" |
| "Consumed 1 tablet from Organizer A" | "Dose taken", "Take your dose" |
| "No stock recorded at this location" | "You missed your medication" |
| "Fill recorded 2026-01-01, 90 days supplied" | "Refill eligible", "Insurance allows" |
| "Recipe", "consumption recipe", "prescription (as you entered it)" | "Regimen", "treatment plan", "therapy" |

**The excursion case, as originally written.** A fridge that went out of range is not an
event this release records. If excursion ingestion returns, it records which stock was
resident and does not decide whether a product is still good. That judgement stays with a
person.

## Options considered

**A. No boundary; add checks as they are asked for.** The default outcome of not writing this
record. Each addition is defensible alone and the aggregate is a device nobody decided to
build.

**B. Checks behind a disclaimer.** A banner saying "not medical advice" above a screen giving
medical advice. It changes what the software says about itself, not what it does, and the
person it fails is the one who trusted a warning that was two years stale. Rejected.

**C. This record.** A stated line, applied at review time.

**D. Integrate a maintained commercial drug database.** The only version of A that is
honest — real licensing, real update cadence, real liability. It would change what this project
is, and it is a serious answer for someone building a different product. Rejected as out of
scope for a household inventory fork, and named here so a future reader knows it was considered
rather than overlooked.

## Consequences

**Useful things are refused, and will be asked for again.** The record exists so the answer
is a decision with reasons rather than the maintainer's mood on the day.

**The module is less helpful than a commercial medication app**, and users arriving from one
will notice the absence. Worth saying in the module's own documentation rather than leaving as
a gap people assume is a missing feature.

**[02](../plans/02-mcp-endpoint.md) inherits the hardest version of the problem, and this
record does not solve it.** Excluding consumption and refill routes from MCP keeps the model
from *querying* that data; it does not stop a user pasting a prescription into a chat. What this record can bind
is what this repository ships: no tool that answers a clinical question, and no tool
*description* phrased as though it could.

A tool description is part of what a model reasons over, so it is in scope for review the
same way UI copy is. Beyond that, the boundary is the model's, not ours, and pretending
otherwise would be the kind of claim this corpus is supposed to catch.

**It is mostly not enforceable by tooling.** There is no grep for "this feature crossed the line".
It is a review discipline, applied to plan 22's UI copy and to any later feature request. One
narrow part is mechanical: `mcp/tests/tools/medication-exposure.test.mjs` fails if an MCP tool
reaches a consumption or refill route or its description uses clinical language. Open question 4.

**It does not make the data less sensitive.** Refusing to give advice is orthogonal to who can
read a prescription; [ADR-0040](0040-consumption-recipes-are-private-rows-with-scoped-shares.md)
settles that, and this record settles none of it.

## Acceptance prerequisites

1. **UI copy, API names, notices and error strings are reviewed against the wording table
   above before the feature ships.** Status on 2026-10-09: **unmet**, because no UI exists.
   The implementing pull requests ([issue 698](https://github.com/datagen24/victual/issues/698),
   [699](https://github.com/datagen24/victual/issues/699) and
   [701](https://github.com/datagen24/victual/issues/701)) each list the strings they add in
   their description, and the reviewer checks them against the table.
2. **[The MCP interface spec](../mcp-interface-spec.md) states medication exposure, including
   tool descriptions.** Evidence recorded 2026-10-09, with the verdict left to the accepting
   pull request: [section 10.1](../mcp-interface-spec.md#101-medication-and-private-consumption-data) and
   by the test named in Consequences.

## Open questions

Questions 2 and 3 of the 2026-09-04 text concerned missed doses and an ingredient field. The
2026-10-09 scope has neither a schedule nor an ingredient field, so both are withdrawn and
recorded here so the earlier numbering stays traceable.

1. **Does a refill notice count as advice?** A notice that a reorder date has arrived repeats
   the household's own rule and asserts nothing new. It is nonetheless a prompt to act.
   *Lean: in scope, because it restates a user-entered fill and rule. The wording table
   carries the weight: "Reorder date reached" rather than "Time to reorder".*
2. **Missed doses.** Withdrawn 2026-10-09; no schedule exists to miss.
3. **Ingredient field.** Withdrawn 2026-10-09; products carry no active ingredient in this
   scope. If one returns, grouping by ingredient is master data and a warning derived from it
   is an assertion, so this record would need revising before it ships.
4. **Should anything mechanical enforce this?** *Lean: only what is already mechanical, the
   MCP test above and the wording review in prerequisite 1. A broader check would catch
   nothing and imply coverage.*

## Research

- Regulatory framing: the FDA's clinical decision support software guidance and its criteria
  for CDS that falls outside device regulation, and EU MDR Annex VIII Rule 11 for software
  providing information used for diagnostic or therapeutic decisions. Cited for the *shape* of
  the boundary — the specific criteria are not restated here because this record does not turn
  on their detail: the decision is to stay well clear of the line rather than to sit near it.
- Plan and tree facts as of the working copy of 2026-09-04; see
  [22](../plans/22-medication-tracking.md).
