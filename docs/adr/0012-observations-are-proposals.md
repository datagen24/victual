# ADR-0012: Observations write proposals, never bookings

- **Status: Accepted, 2026-09-04.** **Probabilistic observations write proposals; a person
  confirms them, and the confirmation is what books.** Both acceptance prerequisites below
  are met, each annotated in place. Both asked for a *statement* rather than a measurement
  or a spike, so this acceptance writes their answers into the Decision section — the
  confirm permission into item 3, the payload's absent-versus-redacted-versus-unknown
  contract as item 6. That is the only substantive edit the lifecycle rule admits here.
  Nothing else was revised: no consequence softened, no argument improved on the way
  through, no prerequisite dropped. Three of the four open questions stay open; they are
  v1 design work rather than gates, and are marked as such below.
- **Accepting decides the contract, not the schedule.** No `proposals` table exists, no
  endpoint exists, no plan owns the work, and it is in no wave. What changes today is what
  may be built: no observer credential writes `stock_log`, no confirm path invents a
  reviewer role, and no proposal payload says "you may not see this" by leaving a key out.
- **Three statements in the body were checked at acceptance rather than edited.**
  *Consequences* calls the new tables a dual-engine liability "while ADR-0008 is Proposed".
  [0008](0008-postgresql-only-runtime-engine.md) was accepted 2026-08-31, the same day this
  record was written, and the liability is unchanged by that — the dual-engine discipline
  stays live until 0008's retirement work is scheduled, which it is not. (The same sentence
  counts *two* tables where the Decision names one entity, `proposals`, and never names a
  second. The tax is real at one table, nothing in the record turns on the count, and the
  sentence is left as written rather than tidied on the way through.) The same section says
  evidence *wants* [01](../plans/landed/01-file-storage.md); 01 landed 2026-09-02, so that
  conditional is settled in the favourable direction and an evidence reference can be a
  stored artifact from day one — "V1 can ship with evidence optional" is now a choice
  rather than a constraint. And *Where the boundary of this repository is* puts the pending
  count in [18](../plans/18-mqtt-state-publication.md)'s published snapshot. 18 also landed
  2026-09-02, so that is an eighth ambient sensor added to a shipped publisher rather than a
  line in a draft. 18 gains a note saying so — including that its price guard means the
  **count** is publishable and a proposal *payload* never is.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-31, which is when it was written; accepted 2026-09-04, which is when
  the decision was made.
- **Relationship:** decides what this fork's API offers to observation-producing
  clients. The clients themselves — the examples below include a vision pipeline and
  weight sensors — are illustrative operator-side systems and **do not live in this
  repository**; what lives here is the entity, the endpoints, and the invariant. The
  proposals schema is one of the flat contracts
  [ADR-0010](0010-workload-standard.md) requires consumers to share.
- **Would affect:**
  - [01](../plans/landed/01-file-storage.md) — **landed**; it is what evidence storage
    rests on.
  - [18](../plans/18-mqtt-state-publication.md) — **landed**; carries a note from this
    acceptance naming the sensor this work adds and the payload it must never publish.
  - [19](../plans/19-rbac.md) — carries a note from this acceptance naming the two
    permission facts decided here, and the one thing item 6 hands back to it: its
    `FIELD_POLICY` is keyed by column and a proposal's price is a key inside a payload.
  - [14](../plans/landed/14-contract-and-regression-scaffolding.md) and
    [02](../plans/02-mcp-endpoint.md) — neither states anything this acceptance makes
    false. The wire surface is additive and lands under 14's snapshot discipline when it
    is built, and 02 reads through a Victual user like any other client.

## Context

The stock ledger is exact, trusted history: every booking in `stock_log` is a fact a
household member made happen and can undo, and
[13](../plans/landed/13-write-path-transactions.md) centralised the write paths that keep it
that way. The API accordingly offers exactly one kind of write: a booking, asserted as
true.

Automated observers do not produce that kind of statement. A camera pipeline diffing
what it can see produces "entry X appears to be gone, confidence 0.7, and here is the
frame"; a shelf-weight sensor produces "this location lost 340g."

Wiring such a client to the booking API forces a choice between two bad options: the
client asserts certainty it does not have, silently polluting the ledger with guesses
that read exactly like human actions. Or it stays read-only, and the observations are
wasted. The failure mode of the first option is insidious precisely because the
ledger's value is that nobody doubts it.

There is also a threat-model angle. Under
[ADR-0006](0006-authenticated-issues-in-scope.md), the sensor boxes an operator wires up
are the least defensible credentialed things on the network; a compromised observer
holding a booking-capable key can rewrite inventory history. The narrowest useful write
grant for an observer is "may suggest."

## Decision

**Probabilistic observations never write the ledger. They write proposals.**

1. **A `proposals` entity**: source, kind (a proposed booking — consume, purchase,
   transfer, inventory-correction), the proposed payload, confidence, an optional
   evidence reference, a **unique source-event id**, status
   (pending / confirmed / rejected), and who resolved it.
2. **Creating a proposal is its own narrow grant.** An observer's API key resolves to a
   user whose permissions allow creating proposals and nothing else — the shape
   [19](../plans/19-rbac.md) exists to express. A compromised observer can spam the
   review queue; it cannot touch history.
3. **Confirmation executes the booking** through the existing service write paths — the
   confirmed proposal is the audit trail from observation to booking, and the booking
   itself is indistinguishable from any other because it *is* any other.
   Rejection records why, which is training data for whatever proposed it.
   **Confirming a proposal requires exactly the permission the booking it proposes
   requires directly** — confirming a consume needs `STOCK_CONSUME`, confirming a purchase
   needs `STOCK_PURCHASE` — and **rejecting requires the same permission as confirming.**
   There is no reviewer role and no `PROPOSALS_CONFIRM` leaf: a queue is a way of putting
   work in front of the people who could already do it. A queue whose *rejections* need
   a grant its confirmations do not is the second admin surface this record's second gate
   exists to prevent. *(Decided 2026-09-04 by this record's acceptance, as open question 2's
   lean stood; [19](../plans/19-rbac.md) carries the note.)*
4. **The unique source-event id makes creation idempotent** — a redelivered sensor event
   cannot double-propose — which is [ADR-0010](0010-workload-standard.md)'s idempotency
   rule applied at the API boundary rather than inside a consumer.
5. **Deterministic sources are out of scope.** A scale that weighs an open jar against a
   known tare is not guessing; neither is a human with a scanner. Those clients use the
   booking API as they do today. The line is confidence: a client that would have to
   invent a confidence value belongs on the booking API; one that genuinely has one
   belongs here.
6. **A proposal payload separates absent, redacted and unknown, and never encodes two of
   them the same way.** A proposed booking is partial by nature — a mass delta proposes an
   amount but maybe not a price. The payload object therefore carries exactly the keys the
   observer proposed, and the row carries **`proposed_fields`**: the sorted key list of the
   payload as submitted, stored at creation and **never redacted**. For any field of the
   booking a proposal proposes:

   - **In `proposed_fields` and in the payload** — *proposed*, at the value shown. A `null`
     there is a proposal of **no value**, which is a real answer wherever null is a
     legitimate booking value (a consume has no price), not a missing one.
   - **In `proposed_fields`, absent from the payload** — *redacted*: the reader lacks the
     permission covering that field and [19](../plans/19-rbac.md) piece 2's funnel removed
     it. Removal rather than nulling is 19's rule, kept here unchanged.
   - **In neither** — *unknown*: nobody proposed a value. This is the ordinary case, and it
     says nothing about the reader.

   `proposed_fields` is the whole mechanism, and it exists because 19 redacts by *removing*
   the key: without it, "you may not see this" and "the camera could not tell" are the same
   bytes, which is exactly the confusion the ledger's other payloads never have to survive.
   It costs one column and no new error kind — refusal stays [11](../plans/11-api-error-handling.md)'s
   403, redaction stays a 200 with a shorter body, and a filter on a redacted field stays
   19's `EInvalidApiQuery` — so nothing here asks 11 for a taxonomy slot.
   *(Decided 2026-09-04 by this record's acceptance, meeting the first gate below.)*

## Where the boundary of this repository is

This record deliberately ships the *narrow* half. In scope: the entity, its endpoints
under the wire-contract discipline, the permission, and surfacing pending proposals —
in the UI, and as a count in [18](../plans/18-mqtt-state-publication.md)'s published
snapshot so an always-on consumer can nag. Out of scope, permanently: the observers
themselves, their sensors, their inference, their scheduling. The fork's promise to that
ecosystem is a stable, idempotent, least-privilege way to say "I think something
happened" — nothing more.

## Consequences

**A review queue is a chore.** A household that ignores it accumulates stale pending
proposals, and the feature degrades to noise. This is the real risk — not technical —
and it is why proposals surface through channels the household already looks at rather
than a page nobody visits.

**New wire surface.** The entity and endpoints land under
[14](../plans/landed/14-contract-and-regression-scaffolding.md)'s snapshot discipline, and are
additive.

**Evidence wants [01](../plans/landed/01-file-storage.md).** An evidence reference without file
storage is a URL to somewhere with its own retention; with 01 it is a stored artifact
that lives exactly as long as the proposal. V1 can ship with evidence optional.

**Two more dual-engine tables** while
[ADR-0008](0008-postgresql-only-runtime-engine.md) is Proposed — same small-but-real tax
noted in [ADR-0011](0011-label-namespace.md).

**Auto-confirmation is explicitly deferred.** A threshold above which a proposal
self-confirms is a policy decision with the exact failure mode this record exists to
prevent, and it should be earned by months of precision data, not designed in advance.
V1 has no auto-confirm path.

## Acceptance prerequisites

Gates, not suggestions. **Both are met**, by the acceptance of 2026-09-04; neither was
amended, relaxed or dropped, and each carries what met it. Both asked for a statement, so
what met them lives in the Decision above rather than being restated here.

- **The absent-versus-redacted-versus-unknown contract for proposal payloads is stated**
  — a proposed booking is partial by nature (a mass delta proposes an amount but maybe
  not a price), and [19](../plans/19-rbac.md)'s wire-contract questions about absent
  keys apply here from day one.
  — **met**: decision item 6. `proposed_fields` is the key set as submitted, kept
  unredacted, so absence from the payload means redaction and absence from both means
  nobody proposed a value. The gate points at 19, and item 6 is settled against the two of
  19's rules that are *decided* rather than open. Redaction removes the key instead of
  nulling it, and a redacted field is already distinguishable from a refused call without a
  new error kind. It therefore does **not** wait on 19's open question 8, which asks whether
  reads are gated at the object level at all. Whichever way that goes, a proposal a reader
  may not see is a refusal, and a proposal they may partly see is item 6's middle case. The
  index's reading that this record "reads better after 19 unblocks" was right about the
  reading order and wrong about the dependency, and is corrected there.
- **The confirm permission is decided** — open question 2 carries the lean — because it
  is the difference between a review queue and a second admin surface.
  — **met**, as the lean stood: confirming requires exactly the permission the underlying
  booking requires directly, rejecting requires the same, and no reviewer role or
  `PROPOSALS_CONFIRM` leaf is minted. It is decision item 3, which is where a reader looks
  for it; open question 2 is annotated with the fact that it was decided, not with the
  decision. Note what this leaves standing: **creating** a proposal is still its own narrow
  grant (item 2), so an observer credential and a confirming household member are different
  identities holding different permissions. A compromised observer credential can still
  only create a proposal; confirming or rejecting one needs the booking permission itself.

## Open questions

Question 2 was a gate and is decided; 1, 3 and 4 stay open. They are v1 design work rather
than gates — none of them changes what may be built, only what the first build chooses —
and they are answered by whoever schedules the work.

1. **Expiry.** Do pending proposals age out? *Lean: no automatic expiry — a stale
   pending proposal is information about the household's engagement, and silently
   deleting it hides exactly that. Staleness is displayed, not enforced.*
2. **Who may confirm?** *Lean: any user holding the permission for the underlying
   booking type — confirming a consume requires what consuming requires. No separate
   "reviewer" role; [19](../plans/19-rbac.md) already prices this correctly.*

   > **Decided 2026-09-04 by this record's acceptance**, as the lean stood, and with
   > rejection held to the same permission as confirmation — the lean did not say, and a
   > queue whose rejections need a grant its confirmations do not is precisely the second
   > admin surface the gate names. The answer is decision item 3; the question stays here
   > because this is where a reader asks *why* there is no reviewer role.
3. **Confirm-with-edit.** May the confirmer adjust the payload (the amount, the
   product) before it books? *Lean: yes — the observation was approximately right and
   the human is the precision step; the proposal keeps both the proposed and the booked
   payload so the delta is visible to whoever tunes the observer.*
4. **Is inventory-correction a proposal kind in v1**, given corrections are the
   heaviest-handed booking? *Lean: yes but last — it is the kind a reconciling observer
   most wants and the kind a wrong observer does the most damage with; shipping
   consume/purchase/transfer first lets the trust be earned on smaller stakes.*

## Research

- Ledger and write-path facts: `stock_log`,
  [13](../plans/landed/13-write-path-transactions.md)'s Executed section, working copy of
  2026-08-31.
- The narrow-grant argument is [ADR-0006](0006-authenticated-issues-in-scope.md)'s
  threat model applied to operator-side observers; the idempotency rule is
  [ADR-0010](0010-workload-standard.md)'s.
