# ADR-0010: Fork-owned workloads are stateless, idempotent, unprivileged and declared

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-31, revised 2026-09-07 against the acceptance review's findings:
  *Context* and *Consequences* were re-measured against a week's worth of tree that
  landed after this record was written, open questions 1 and 3 are answered by what
  landed in that time, open question 2 is answered rather than left leaning, and
  decision item 3
  is scoped against [ADR-0019](0019-label-printers-are-master-data.md), which this record
  did not know about on 2026-08-31. The decision did not change; what it binds is stated
  more precisely.
- **Relationship:** binds the workload family the roadmap is about to create — the MCP
  sidecar ([02](../plans/02-mcp-endpoint.md) / the
  [interface spec](../mcp-interface-spec.md)), the MQTT publisher
  ([18](../plans/18-mqtt-state-publication.md)), the print drainer
  ([ADR-0011](0011-label-namespace.md)) — and, retroactively, the main application image.
  The [constitution](../constitution.md)'s workload-standard section is the statement of
  intent this record makes binding. [ADR-0019](0019-label-printers-are-master-data.md)
  relies on two of its properties while it is still Proposed, and decision item 3 below is
  written with that reliance in view.
- **Would affect:** [10](../plans/10-cold-start-statelessness.md),
  [02](../plans/02-mcp-endpoint.md), [18](../plans/18-mqtt-state-publication.md).

## Context

The deployment is one container today and will not stay that way: the MCP interface spec
chose a sidecar, plan 18 needs a publisher, and ADR-0011 replaces a webhook consumer with
a queue drainer. The maintainer's operating philosophy — one tool, one job — welcomes
that multiplication, on the condition that failed elsewhere: sprawl without management.
The failure modes have names, and the fork has already ruled on two of them piecemeal.
State between requests is a cold-start problem
([ADR-0007](0007-auth-state-outlives-the-process.md); plan 10 owns the rule). Side
effects on write paths fire after commit and must tolerate redelivery
([13](../plans/13-write-path-transactions.md), [18](../plans/18-mqtt-state-publication.md)'s
retained-snapshot design). What has never been ruled on is privilege and declaration —
and the tree showed it on 2026-08-31: `Dockerfile` built `FROM php:8.5-cli-bookworm` with
**no `USER` directive**, so the container holding the database owner's credentials ran as
root, and the only deployment artifacts in the repository were that Dockerfile and a
compose file.

**Most of that premise did not survive the acceptance review.** [ADR-0013](0013-nix-built-container-images.md)
and [plan 20](../plans/20-container-infrastructure.md) landed a `deploy/` tree
([`deploy/podman/victual.yaml`](../../deploy/podman/victual.yaml)) carrying a pod
manifest with health probes, resource requests and limits, `readOnlyRootFilesystem`,
dropped capabilities and no privilege escalation for every container; the images it
deploys are Nix-built, run as uid 65532, and ship no shell (`nix/checks.nix`). Properties
3 and 4 are close to fully met by the workload this record was written against, within a
week of it being written, for reasons of their own — plan 10 and ADR-0013 needed the same
properties and did not wait for this record to require them. What remains open is
narrower than "privilege and declaration": the database credential split (*Consequences*),
and enforcement (open question 2). Accepting this record was never contingent on the
retrofit being finished, so this does not weaken the case for accepting it; it changes
what accepting it would still leave to do.

## Decision (proposed)

Every workload this repository ships is, as a condition of shipping:

1. **Stateless.** Its durable state lives in PostgreSQL, Redis, or the broker — the
   stores [ADR-0007](0007-auth-state-outlives-the-process.md) permits. Killing the
   process at any moment loses nothing. Process memory and APCu hold only pure caches
   whose loss costs a recomputation.
2. **Idempotent.** Every consumer is an at-least-once consumer; every side effect is
   idempotent or deduplicated by an explicit key. Queues are drained
   (`SELECT … FOR UPDATE SKIP LOCKED` or equivalent), never fired-and-forgotten.
3. **Unprivileged.** Non-root, read-only root filesystem, no capabilities it did not
   ask for — and its own identity: its own credential; and, **for a workload that holds
   a database connection at all**, its own database role rather than another workload's;
   least privilege for the one job it does, whatever that job's surface actually is.
4. **Declared.** It exists in the repository's deploy tree with health probes and
   resource limits, or it does not exist.

**"Its own database role" is scoped to workloads that touch the database; it does not
manufacture a role for one that does not.** [ADR-0019](0019-label-printers-are-master-data.md)'s
label-print worker is the first workload this family proposes with no database
connection at all — it authenticates to Victual's own API and holds nothing else. That
worker has no role to separate, and reading property 3 as requiring one would be reading
into it a requirement this record never intended: least privilege applies to whatever a
workload actually holds, and a workload holding no database credential has nothing there
to over-privilege.

**A property may be departed from, but only by name, in the record that proposes the
workload — never by omission and never by this record being read as having already
anticipated it.** [ADR-0019](0019-label-printers-are-master-data.md)'s *paired* worker
(one of its two configuration modes) is the first instance: it keeps one durable value,
its own credential, because the device it runs on has no operator-managed mechanism to
inject one — the *declared* worker, its other mode, keeps property 1 in full. 0019 states
which value is held, why no operator mechanism covers it, and what recovery looks like
without it (re-pairing, not a restart). That is what makes it an argued exception rather
than a silent violation: this record still binds every workload that has an operator
mechanism to satisfy it, unchanged, and a workload claiming otherwise says so in its own
accepting record.

And one rule about the family rather than its members: **consumers may multiply;
contracts may not.** One outbox schema discriminated by event type, one proposals schema
([ADR-0012](0012-observations-are-proposals.md)) — new consumers attach to existing
contracts rather than minting their own.

The standard binds what this repository ships. Workloads an operator runs *against* the
fork — integration pipelines, bridges, anything consuming the API or the published state
from outside — are their operator's to govern; this record is guidance there, not law.

## Consequences

**The main application image was non-compliant when this was written, on properties 3
and 4.** That was deliberate: accepting the standard does not require the retrofit to be
done, it requires the gap to be a tracked work item rather than an unstated fact. The
retrofit this paragraph predicted would be expensive — reconciling every path the
application writes (the data directory, the Blade view cache, plan 10's HTMLPurifier
serializer path, which that plan already calls its most annoying writable-path problem)
with a read-only root filesystem — turned out to be exactly that expensive, and mostly
paid already: plan 10 and plan 20 baked the view cache into the image and removed the
need for a writable data directory entirely (issue #49), and property 4's deploy tree,
probes and limits shipped with it (2026-09-04). **What is not yet paid is the
credential** half of property 3: `victual-app`, `victual-web` and `victual-migrate`
still share one database role, tracked as plan 20 verification check 8, "Needs a role
with no DDL rights; the bootstrap uses one superuser." Accepting this record still does
not require that split to exist — it requires it to stay the tracked item it already is,
rather than becoming an unstated fact again.

**New workloads are born compliant or not born.** For a Go or Node binary on a distroless
base this costs nearly nothing, which is the point of deciding it before the family
exists rather than after.

**Idempotency becomes reviewable.** "What is this consumer's dedup key" and "what happens
when this delivery arrives twice" become questions a PR review is entitled to ask about
any consumer, the way "which engines does this migration run on" is askable today under
[ADR-0004](0004-engine-specific-migrations.md).

**A deploy tree has to exist**, and it becomes part of the reviewed surface. Manifests
are code — and, since open question 2's answer below, part of the *checked* surface too,
not only the reviewed one.

## Open questions

1. ~~**Where does the deploy tree live?**~~ **Answered, by construction: in-repository,
   `deploy/`.** [ADR-0013](0013-nix-built-container-images.md) decision item 7 and
   [plan 20](../plans/20-container-infrastructure.md) built it there —
   [deploy/podman/victual.yaml](../../deploy/podman/victual.yaml) — before this record
   itself was accepted, the lean this question already favored.
   [deploy/README.md](../../deploy/README.md) states the boundary this question asked
   about, in the same words the lean used: the fork's manifests carry what the workload
   needs, and stay silent on ingress, storage classes and secrets management, which are
   the operator's.

2. ~~**Enforcement.**~~ **Answered: CI enforces what a script can check without
   understanding the workload; review establishes what requires understanding it.**

   **What CI already enforced, and its gap.** `nix/checks.nix`'s
   `image-runs-unprivileged` and `image-has-no-shell` assert non-root and no interpreter
   beyond PHP against the *artifact*, and the `nix` workflow's boot test proves a read-only
   root filesystem actually holds at runtime — property 3's image half, checked. Nothing
   asserted the *manifest* half: a `deploy/**` change dropping a probe, a resource limit,
   `readOnlyRootFilesystem` or a dropped capability compiled, merged and shipped with no
   red check anywhere, and `deploy/**` was not even in the `nix` workflow's path filter for
   anyone to notice by coincidence.

   **The gap closes the way the lean already said it would: a cheap check, not a
   linter — added to the tree with this revision, not merely proposed.**
   [`check_deploy_manifest.py`](../../.devtools/ci/check_deploy_manifest.py), run
   unconditionally by the `lint` job, asserts structurally that every container in every
   `deploy/**` manifest sets `readOnlyRootFilesystem`, drops the `ALL` capability, refuses
   privilege escalation and declares a memory limit, and that every *serving* container
   declares at least one probe — the same class of assertion `nix/checks.nix` already
   makes about images, extended to the manifest that deploys them. It runs in `lint`
   rather than joining `deploy/**` to the `nix` workflow's path filter on purpose: the
   manifest is not a Nix build input, and gating a YAML-only change behind a full
   three-image rebuild would be the expensive answer to a cheap question — the workflow
   most people would reach for is not always the workflow the cost argues for.

   **What a mechanical check cannot decide stays review's job, named rather than left
   implied.** Whether a consumer's dedup key is actually well-chosen for its idempotency
   (*Consequences*' "what is this consumer's dedup key") and whether a credential is
   scoped to least privilege for the one job its workload does are both judgments about
   what a workload *does*, not what its manifest *declares* — a check can confirm a
   database role exists and is not the superuser, it cannot confirm the role's grants are
   the minimum the workload's queries need. "Consumers may multiply; contracts may not"
   is the same kind of question: nothing greps for a second workload quietly reading
   another's outbox event type. PR review is where those get enforced, the same way
   [ADR-0004](0004-engine-specific-migrations.md) already makes "which engines does this
   migration run on" review's question rather than a linter's.

3. ~~**When does the main image's retrofit land?**~~ **Answered: most of it already had,
   by the time this record was revised, for reasons that had nothing to do with this
   record.** Non-root, read-only root filesystem, dropped capabilities, probes and
   resource limits shipped with plan 20 piece 1 (2026-09-04) — ahead of this record's own
   acceptance, because plan 10 and [ADR-0013](0013-nix-built-container-images.md) needed
   the same properties for their own reasons and did not wait on this one binding first.
   The root-user finding the lean called highest-leverage did not wait long behind any
   formula; it is gone. What remains is the database credential split (*Consequences*),
   tracked as plan 20 verification check 8 rather than gated on this record's acceptance,
   which is exactly the "tracked work item, not an unstated fact" bar decision item 3 set.

## Research

- Tree facts (`Dockerfile`, absence of a deploy tree) measured on the working copy of
  2026-08-31; re-measured 2026-09-07 for the acceptance review against
  [`deploy/podman/victual.yaml`](../../deploy/podman/victual.yaml), `nix/checks.nix`, and
  the `nix` and `tests` workflows — the transferable lesson
  [ADR-0013](0013-nix-built-container-images.md)'s Research section already drew:
  "measured, not assumed" does not protect a measurement from going stale between taking
  it and acting on it.
- The at-least-once and after-commit disciplines this record generalizes are established
  in [13](../plans/13-write-path-transactions.md) and
  [18](../plans/18-mqtt-state-publication.md); the state rule in
  [ADR-0007](0007-auth-state-outlives-the-process.md) and
  [10](../plans/10-cold-start-statelessness.md).
- The database-credential-split gap is plan 20's own verification record, check 8: "Not
  done. Needs a role with no DDL rights; the bootstrap uses one superuser." Read
  2026-09-07 against [`docs/plans/20-container-infrastructure.md`](../plans/20-container-infrastructure.md).
- [ADR-0019](0019-label-printers-are-master-data.md)'s label-print worker (recorded
  2026-09-06, after this record and independently of it) is what showed decision item 3's
  wording needed scoping: it is a workload this family proposes with no database
  connection at all, and its paired configuration mode is a stateful departure from
  property 1 argued in that record's own *Consequences*.
