# ADR-0015: Victual records medication; it never advises

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-04, alongside [plan 22](../plans/22-medication-tracking.md). The
  decision was made when the plan was drafted; this record is not a backfill.
- **Relationship:** constrains [22](../plans/22-medication-tracking.md) throughout, and
  [23](../plans/landed/23-storage-classes.md) Q6 defers to it. Pairs with
  [ADR-0016](0016-schedule-expansion-in-the-application.md), written at the same time and
  deciding a different question about the same plan.
- **Would affect:** [02](../plans/02-mcp-endpoint.md),
  [18](../plans/18-mqtt-state-publication.md), [17](../plans/17-ecosystem-clients.md).

## Context

[Plan 22](../plans/22-medication-tracking.md) puts drug strength, route, dose, schedule and
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

**The medication module records what a person did and what is physically present. It does not
evaluate, warn, calculate or recommend.**

The line, stated so it can be applied to a feature request without re-arguing this record:

> **Arithmetic over data the household entered is in scope. Any assertion requiring knowledge
> the household did not enter is not.**

**In scope.** Schedules a human wrote down; administrations a human recorded; quantities,
dates, lots, storage conditions. Days-of-supply, which is division over the household's own
numbers, and a physical-fact comparison between two fields the household supplied — a dose
against a tablet's recorded `min_dose_increment`, a product's recorded storage requirement
against a location's recorded class.

**Out of scope, permanently.** Drug–drug, drug–food and drug–condition interaction checking.
Dose-range or maximum-dose validation against any external reference. Duplicate-therapy or
same-ingredient detection. Missed-dose guidance. Weight- or age-based dose calculation.
Allergy and contraindication checking. Importing or embedding any clinical drug knowledge base.

**The excursion case is where this bites first and hardest.** Plan 22 records that a fridge
went out of range and which stock was in it. It does not decide whether the insulin is still
good. That judgement stays with a person, and the usability cost of stopping there is real and
accepted.

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
record does not solve it.** Excluding medication tools from MCP keeps the model from *querying*
the data; it does not stop a user pasting their regimen into a chat. What this record can bind
is what this repository ships: no tool that answers a clinical question, and no tool
*description* phrased as though it could.

A tool description is part of what a model reasons over, so it is in scope for review the
same way UI copy is. Beyond that, the boundary is the model's, not ours, and pretending
otherwise would be the kind of claim this corpus is supposed to catch.

**It is not enforceable by tooling.** There is no grep for "this feature crossed the line", and
this record should not pretend there is. It is a review discipline, applied to plan 22's UI
copy and to any later feature request, and its only enforcement is that a reviewer has
something specific to point at. Open question 4.

**It does not make the data less sensitive.** Refusing to give advice is orthogonal to who can
read a subject's regimen; that is [19](../plans/19-rbac.md)'s and plan 22 piece 3's problem,
and this record settles none of it.

## Acceptance prerequisites

- **Plan 22's UI copy is reviewed against the line** before the module ships — specifically
  that state is displayed and never phrased as an instruction. "3 doses due today" is a fact;
  "take your morning dose" is an imperative the application is not entitled to issue.
- **[02](../plans/02-mcp-endpoint.md)'s interface spec states medication exposure**, including
  tool descriptions, rather than leaving it to be decided when that plan is built.

## Open questions

1. **Do reminders count as advice?** A notification that a dose is due repeats the household's
   own instruction back to it and asserts nothing new, which puts it inside the line as drawn.
   It is nonetheless the closest call in this record, because a reminder is *behaviourally* a
   prompt to act. *Lean: in scope — a reminder restates a user-entered schedule and adds no
   knowledge. Phrasing carries the weight: "due at 08:00" rather than "time to take your
   medication".*
2. **Missed doses.** Displaying a dose as missed is a fact. Ordering the next action is not.
   *Lean: display the state, offer the recording actions (taken / skipped / held), and never
   rank or recommend among them.*
3. **Does an ingredient field re-open duplicate-therapy detection by the back door?** If
   products carry active ingredients — useful for grouping a generic under a brand — then "two
   of these contain paracetamol" becomes a query rather than a knowledge base. *Lean: an
   ingredient field is fine as master data the household enters and the module may group by;
   surfacing a warning derived from it is not, because the warning is the assertion, not the
   data. This is the sharpest test of whether the line as stated is actually workable, and if
   it turns out not to be, this record needs revising rather than reinterpreting.*
4. **Should anything mechanical enforce this?** *Lean: no, and say so rather than shipping a
   check that catches nothing and implies coverage. The honest enforcement is that plan 22 and
   this record are both cited in the module's own documentation, so a contributor proposing an
   interaction checker meets the argument before writing the code.*

## Research

- Regulatory framing: the FDA's clinical decision support software guidance and its criteria
  for CDS that falls outside device regulation, and EU MDR Annex VIII Rule 11 for software
  providing information used for diagnostic or therapeutic decisions. Cited for the *shape* of
  the boundary — the specific criteria are not restated here because this record does not turn
  on their detail: the decision is to stay well clear of the line rather than to sit near it.
- Plan and tree facts as of the working copy of 2026-09-04; see
  [22](../plans/22-medication-tracking.md).
