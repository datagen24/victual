# ADR-0016: Schedule expansion lives in the application, not the database

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-04, alongside [plan 22](../plans/22-medication-tracking.md).
- **Relationship:** an **input to** [ADR-0009](0009-database-as-the-logic-layer.md), not a
  contradiction of it. 0009 is Proposed, and per the constitution a Proposed record
  constrains nothing — so this record does not overrule anything. What it does is name one
  class of logic that should stay in PHP *even if 0009 is accepted*, and hand 0009 a concrete
  case to be judged against instead of an abstraction.
- **Constrains:** [22](../plans/22-medication-tracking.md) piece 4.
- **Would affect:** [0009](0009-database-as-the-logic-layer.md)'s scope if accepted,
  [18](../plans/18-mqtt-state-publication.md) and [02](../plans/02-mcp-endpoint.md) by way of
  what they can answer without waking the pod.

## Context

[Plan 22](../plans/22-medication-tracking.md) needs one question answered constantly: *what
doses are due between A and B*. Answering it means expanding a recurrence rule — twice daily at
08:00 and 20:00, every third day, 21 days on and 7 off — into discrete occurrences over a
window.

Under [ADR-0009](0009-database-as-the-logic-layer.md)'s direction of travel the instinct is a
view, and the instinct has a real argument behind it. 0009's strongest claim is that an
always-awake database can answer while the pod sleeps, and "what is due today" is precisely the
shape of question an always-on Home Assistant would poll for. This record has to beat that
argument, not ignore it.

Four things stand against putting the expansion in SQL:

1. **The dual-engine discipline is live.** [ADR-0008](0008-postgresql-only-runtime-engine.md)
   was accepted 2026-08-31, and the constitution is explicit that acceptance is not delivery:
   until the retirement work is scheduled, every view exists on both engines and is proved
   equivalent. Recurrence expansion is the most expensive possible thing to hold to that rule.
   It is a recursive CTE over date arithmetic, which is where SQLite and PostgreSQL diverge
   hardest — `date(x, '+1 day')` against `x + INTERVAL '1 day'`, no SQLite counterpart to
   `generate_series`, and different interval semantics at the edges. 0009 makes this exact point
   itself, in *Why this depends on 0008*: the dual-engine tax "is concentrated precisely in the
   layer this record wants to grow."
2. **The hard part is policy, and policy wants a table of test cases.** Wall-clock versus
   elapsed time across a DST transition is a decision with edge cases rather than an
   implementation detail. An "every 12 hours" regimen anchored to the clock skips or repeats an
   hour twice a year, and anchored to elapsed time it drifts away from the times a person
   actually takes a tablet. Medication wants wall clock. Proving that over both transitions in
   both directions is a unit-test table, which PHP has today and which pgTAP plus a differential
   proof would make several times more expensive for the same assurance.
3. **The vocabulary will grow, and views resist growth.** 0009's own Consequences section
   records that `CREATE OR REPLACE VIEW` may only append columns — never rename, reorder,
   retype or drop — so any real change is a drop-and-recreate cascading through the dependency
   layers. A schedule vocabulary that starts at five types and will acquire tapers, PRN
   ceilings and cycle offsets is exactly the shape of thing that forces those cascades
   repeatedly.
4. **Expansion generates rather than projects.** Every view in the tree is a projection over
   stored rows. "Due between A and B" over a cycle regimen manufactures rows that exist nowhere,
   and its cost scales with the window the caller asks for rather than with the data held. That
   is a different kind of object from `stock_current`, and giving it the same name would flatten
   a distinction worth keeping.

## Decision (proposed)

**Recurrence expansion — regimen to occurrences — is implemented in PHP, in
`MedicationService`. The algebra that turns a rule into dates does not live in SQL.**

Two things this deliberately does **not** say:

- It does not say medication reads avoid views generally. Days-of-supply, adherence rollups and
  refill-due lists are projections over stored rows and belong in views like anything else.
  Only the expansion itself is carved out.
- It does not say the always-awake component may never answer. **Derived read state may be
  published to a plain table** by a scheduled workload of the shape the constitution's
  workload standard describes — and which [ADR-0010](0010-workload-standard.md), still
  Proposed, would formalise — "these are the occurrences for the next N days",
  recomputed on a cadence. That keeps 0009's pod-sleep property available without putting the
  recurrence algebra in SQL, and it is the compromise this record offers rather than a
  concession extracted later. Open question 1 is whether plan 22 v1 builds it.

## What this costs ADR-0009, said plainly

This removes a visible prize from 0009's case in the domain where that case is most intuitive.
"What is due today, answered by the database while the pod sleeps" is a better demonstration
than "what is expiring this week", and this record declines to build it that way. The snapshot
table recovers the *capability* and not the *elegance*: it is a scheduled recompute plus a
staleness window, which is exactly the sort of machinery 0009 hopes to avoid needing.

A reader deciding 0009 should weigh this record as evidence against it in at least one domain,
not as an unrelated carve-out.

## Consequences

**The medication module cannot be answered from the database alone.** An MQTT or MCP consumer
needs the pod awake, or the snapshot table. Today that costs nothing, because plan 22
deliberately closes both surfaces — [ADR-0015](0015-medication-records-never-advises.md) and
plan 22's MCP exclusion mean no always-awake consumer wants this data yet. The cost is
contingent and arrives with the first consumer that does.

**Carve-outs accumulate, and that is the real risk.** If several records like this land, 0009
is rejected in pieces without anyone ever deciding to reject it — which is worse than either
accepting or rejecting it, because the corpus would then contain a Proposed record that
everything quietly routes around. This record is the first. If there is a second, the right
response is to decide 0009 rather than to write a third.

**Test surface moves to PHP**, which is a genuine saving: a table of recurrence cases with DST
transitions is a unit-test file, against a pgTAP suite plus a differential proof on both
engines for the same assurance. This is the one consequence that is straightforwardly positive
and it should not be oversold — it is a saving on *this* logic, not an argument about where
logic belongs generally.

**Nothing is foreclosed.** The expansion is a service method with a defined signature.
Moving it into SQL later requires rewriting one component without a migration.
Extracting recurrence algebra from five layers of dependent views would cost more.
The lower cost of moving from PHP to SQL supports starting with PHP.

## Acceptance prerequisites

- **Open question 1 is answered** — whether plan 22 ships the snapshot table — before piece 4
  is built, because it decides whether there is a table at all and therefore what the service's
  callers look like.

## Open questions

1. **Snapshot table in v1?** *Lean: no. No always-awake consumer wants medication data — plan
   22 excludes it from MCP and publishes none of it over MQTT — so a snapshot table in v1 would
   be scheduling machinery serving nobody, plus a staleness window to reason about. Add it when
   a consumer exists and can say what window it needs.*
2. **Does this generalise beyond medication?** The same argument would cover chores, which
   compute their next execution in `ChoresService` today. *Lean: state it narrowly. Chores work,
   nothing proposes moving them, and a record that quietly annexes a working subsystem is
   claiming more than it has argued for.*
3. **If 0009 is accepted and 0008's SQLite retirement lands, does this revisit automatically?**
   Reasons 1 and 2 weaken considerably in a PostgreSQL-only tree; 3 and 4 survive intact.
   *Lean: a superseding record, not automatic expiry. A decision that silently stops applying
   when a precondition changes is how a corpus loses track of what is true, and the constitution
   already says records are superseded rather than edited into different decisions.*
4. **Where does the wall-clock-versus-elapsed decision itself get recorded?** It is a
   behavioural contract, not an implementation choice, and a reader of plan 22 in two years will
   want it stated rather than inferred from tests. *Lean: plan 22's piece 4, promoted to its own
   ADR only if a second subsystem ever needs the same answer.*

## Research

- Engine divergence in date arithmetic and the absence of a SQLite `generate_series`: the
  dual-engine work already documented in `db/pgsql/README.md` and the differential harness in
  `.devtools/pgsql/`, which exist precisely because this class of difference is not theoretical
  in this tree.
- `CREATE OR REPLACE VIEW` may only append columns —
  [ADR-0009](0009-database-as-the-logic-layer.md)'s Consequences section and its citations; not
  restated here.
- Tree facts (`ChoresService` next-execution computation, `StockService` write paths) measured
  on the working copy of 2026-09-04.
