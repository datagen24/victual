# The Victual constitution

Standing principles for this fork. [ADRs](adr/README.md) record *decisions* and
[plans](plans/README.md) record *work*; this file records the principles that outlive
both — the things that should be true of any decision or plan before it is written. Where
a principle here was born in an ADR, the ADR is the authority and this file is the index
entry pointing at it. This file is amended by pull request like anything else, and a
change to it is a change to how the project is governed, reviewed accordingly.

## Authority

The maintainer (datagen24) decides. That is a statement about how this project is
governed — one maintainer, accountable for the tree — and not about how many people are
expected to run it: anyone is welcome to, and the records here are written to be read by
someone who does. Proposals are argued on their merits and records are written to be
argued with — but "who decides" is never implicit, and nothing is adopted by momentum. An
ADR is accepted in its own pull request,
carrying bookkeeping only; a record is not accepted by a plan that assumes it, a PR that
implements it, or by not being argued with. Acceptance prerequisites are gates, not
suggestions.

## Standing invariants

**The wire contract is the invariant.** The JSON on the wire is what clients depend on
and what this fork promises. [ADR-0005](adr/0005-wire-contract-is-the-invariant.md)
states it; plan 14's contract snapshot is becoming its enforcement.

**Anything that keeps state between requests is a cold-start problem.** State lives in
PostgreSQL, Redis, or the broker — never in process memory, except for pure caches whose
loss costs a recomputation and nothing else.
[ADR-0007](adr/0007-auth-state-outlives-the-process.md) is the first instance; plan 10
owns the rule.

**Authenticated issues are in scope.** A permission bug reachable by a logged-in
household member is a finding, not a curiosity.
[ADR-0006](adr/0006-authenticated-issues-in-scope.md).

**A Proposed record constrains nothing; an Accepted one constrains everything.** Work in
flight follows the accepted state of the world. Acceptance is also not delivery:
[ADR-0008](adr/0008-postgresql-only-runtime-engine.md) was accepted 2026-08-31 and the
dual-engine discipline still holds in full, because the retirement work it calls for is
not scheduled yet. An accepted record binds the next decision; it does not retroactively
relax a discipline the tree is still running on.

## The workload standard

One tool, one job, done well. Many small components are welcome; unmanaged components are
not. Every fork-owned workload is:

- **Stateless.** Its state lives in PostgreSQL, Redis, or the broker — the same
  stores the standing invariant and ADR-0007 permit. Killing it loses nothing.
- **Idempotent.** Every consumer is an at-least-once consumer; every side effect is
  idempotent or deduplicated. Queues are drained, not fired-and-forgotten.
- **Unprivileged.** Non-root, read-only filesystem, no capabilities it did not ask for,
  and its own identity — its own credential, its own database role where it holds one,
  least privilege for the job it does.
- **Declared.** It exists in the deploy tree with probes and limits, or it does not exist.

Consumers may multiply; contracts may not. One outbox schema with event types, one
proposal schema for observations ([ADR-0012](adr/0012-observations-are-proposals.md),
accepted 2026-09-04) — sprawl of tools over few stable contracts is the
design; sprawl of contracts is coupling with extra steps. (A binding, testable form of
this standard belongs in an ADR; until that record exists, this section is the statement
of intent it will formalize.)

## Data integrity

**The stock ledger is exact history.** Every booking is a fact a human can trust and
undo. Probabilistic observations — vision inference, sensor fusion, anything with a
confidence attached — never write the ledger directly; they write proposals, and a person
confirms them. [ADR-0012](adr/0012-observations-are-proposals.md), accepted 2026-09-04,
makes that a decision rather than a description, and draws it narrower than this paragraph
did: confirming a proposal — or rejecting one — requires exactly the permission the booking
it proposes requires, and **there is no auto-confirm path**. The threshold this paragraph
used to admit as a second kind of confirmer is deferred until there is precision data to
set it from.

**Physical artifacts are contracts.** A printed label outlives every deployment and
cannot be rolled back. Labels carry stable opaque identifiers resolved by a mapping the
database owns; formats printed into the world are retired by attrition, never by flag
day.

## Honesty in records

A record whose case erodes under review says so — corrections strengthen a proposal's
credibility rather than weakening it, and a benefit that shrank on inspection is
downgraded in place, not defended. Premises are measured before anything is built on them.
A deferral that contradicts a stated gate changes the gate's wording in the same change,
or the wording quietly becomes a claim nobody checks. Records are superseded, never
edited into different decisions; numbers are permanent; the reason something was *not*
done is kept, because it is what future readers most need and least often have.

## Compatibility posture

This fork's contract is its own OpenAPI specification. Upstream grocy compatibility is an
*import capability*, not a behavioral obligation: grocy SQLite is an input format for
`bin/victual-db-import`, grocycode is an input symbology for barcode resolution
([ADR-0011](adr/0011-label-namespace.md), accepted 2026-09-04, makes that a decision rather
than a description: parsed forever, emitted never), and both are kept honest by pinned
fixtures at stated supported versions. The fork accepts drift in exchange for the ceiling
coming off.
