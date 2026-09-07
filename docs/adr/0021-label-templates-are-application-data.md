# ADR-0021: Label templates are application data, a reprint is a new job over retained bytes, and an import refuses live labels

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-07.
- **Supersedes** three boundaries of [ADR-0011](0011-label-namespace.md), which is
  **Accepted**: its decision item 4's assignment of templates to the drainer and its
  definition of a reprint as resetting a row, its Consequences paragraph *Rendering leaves
  this repository*, and its decision item 5's obligation on `bin/victual-db-import`.
  Everything else in 0011 stands unchanged — the `vctl:<uid>` payload, the mapping table,
  Grocycode as a read-only input symbology, and the retirement of
  `VICTUAL_LABEL_PRINTER_WEBHOOK`.
- **Relationship:** [ADR-0019](0019-label-printers-are-master-data.md) is **Proposed** and
  its decision item 1 gave template definitions to the worker repository. **Its decision items
  1 through 5 were reconciled against this record on 2026-09-07** — ownership, what a
  registration advertises, what a claim hands over, and the claim precondition that matched a
  template version, which no worker can satisfy any more and was removed rather than deferred.
  The rest of 0019 — printer configuration as master data, the pull transport, claim/fence
  semantics and no automatic redispatch — is unaffected and is not reopened here. Two
  format-dependent details are outstanding acceptance work on that record; prerequisite 2 below
  settles them.
- **Would affect:** [25](../plans/25-label-infrastructure.md),
  [27](../plans/27-label-templates-and-rendering.md), [06](../plans/06-location-barcodes.md),
  [01](../plans/01-file-storage.md), [17](../plans/17-ecosystem-clients.md).

## Context

**Three boundaries decided in ADR-0011 do not survive contact with what the fork now wants,
and they are one record because they fail together.** 0011 was accepted 2026-09-04 having
argued a narrow point well — a printed label outlives the row id printed on it — and having
disposed of the surrounding questions in a sentence each, on the reasonable ground that the
namespace was what needed deciding. Those sentences are now the constraint.

**Templates.** 0011 decision item 4 makes the print job row `(uid, template, payload,
status)` and its Consequences say plainly: "Rendering leaves this repository. Label
templates (the human-readable layer …) belong to the drainer. The fork's contract is the job
row, not the label's appearance." ADR-0019 decision item 1 then implemented that split,
giving the worker repository "Label templates, their versions, and their declared capability
requirements" and giving Victual only the pinned identity.

That is a defensible place to draw the line and it forecloses something the fork wants: a
person editing a label's appearance in the browser, against household data, with a preview
that is trustworthy. A template that lives in the worker repository is edited by changing
Python, is versioned by a git tag, is deployed by a revision bump, and is previewed by
printing one. Nobody in a household does any of that. Worse, the seam is drawn where it
costs the most: appearance is the part a person most wants to change and the part that
change is currently hardest for.

**Reprints.** 0011 item 4's "Reprint is resetting a row" was written against a status-column
queue and is the wrong mechanism twice over. It destroys the record of what already happened
— the whole point of ADR-0019's `print_attempts`, and the reason its decision item 6 refuses
automatic redispatch — and it silently reprints *whatever the drainer renders today*, which
after a template change or a renderer upgrade is not the label that was printed before. A
household reprinting a damaged shelf label wants the same label; the mechanism cannot promise
that, and does not say it cannot.

**The import.** 0011 item 5 says `bin/victual-db-import` "re-keys label targets with the rows
it creates; uids never change". **No source that importer accepts can carry a label.**
`DatabaseImporter::GetCommonTables()` intersects the target's tables with the SQLite source's;
`SUPPORTED_SOURCE_MIGRATION_MIN` is the fork's squashed baseline 0255 and
[ADR-0008](0008-postgresql-only-runtime-engine.md)'s retirement froze the SQLite line at 0265,
so every accepted source predates `labels` by construction. There is nothing to re-key.

And because `labels` is not in that intersection, it is **neither truncated nor copied**,
while `locations` *is* truncated `RESTART IDENTITY CASCADE` and repopulated from the source.
The surviving label rows then name ids that belong to different shelves. `--force` is the
live case: `AssertTargetIsEmpty()` returns immediately under it, so a target holding labels is
exactly the target this happens to. An obligation that cannot be discharged has been standing
in for a hazard that is real.

## Decision (proposed)

### 1. Victual owns template documents; rendering stays out of this repository

**The template document is application data** — drafted, versioned, published, permissioned
and stored by Victual, in a Victual-defined format rather than an editor's internal object
graph. **Rendering remains outside this repository**, in a headless renderer service that
reads that document. Both halves matter: 0011's argument that this repository does not do
font shaping is correct and is kept; its conclusion that the *design* therefore belongs to
whoever does the shaping does not follow.

| Owned by this repository | Owned by the renderer |
|---|---|
| Template drafts, published versions and their digests | Font shaping, layout, QR generation, rasterization |
| Assets (fonts, images) and their licensing metadata | Validation of a document against a media profile |
| The field catalogue, and captured values | Nothing durable: it holds no template and no mapping |
| Media profiles derived from validated printer capabilities | |
| Durable render requests, artifacts and their manifests | |

ADR-0019's decision item 1 row *"Label templates, their versions, and their declared
capability requirements"* moves from the worker column to Victual's. Its `label_templates`
registration table changes meaning accordingly: workers advertise which **artifact and
profile contract versions** they can accept, not which layouts they carry.

The document format admits text, QR, stored raster images, lines and rectangles. It admits no
scripts, no HTML, no external URLs, no expressions and no editor plugins. Unknown
`schema_version` values and unknown features are rejected rather than approximated — the same
posture `OutboxService`'s payload versioning already takes, for the same reason.

**Automatic height is bounded.** A template on continuous media computes its content extent
plus explicit spacing and then validates against the profile's length rules; it never crops,
and it never grows without a bound. Continuous tape is not an unbounded canvas.

### 2. A reprint is a new job over retained bytes

**A print produces an immutable artifact — exact bytes plus a manifest — and the artifact is
the authority for a reprint.** Four operations, distinguished because they differ in what they
create:

| Operation | New uid | New job | Content |
|---|---|---|---|
| **Issue** | Yes | Yes | Captured now; template at its published version |
| **Reprint** | No | Yes | The named source artifact's **exact bytes**, rerendered never |
| **Revised print** | No | Yes | Captured now, against a selected published version; recorded as a different operation |
| **Authorize another attempt** | No | **No** | The existing job's pinned payload, naming the ended attempt reviewed |

"Reprint is resetting a row" is withdrawn. Nothing rewinds a job, and no attempt record is
destroyed to make a second print possible: authorizing another attempt is ADR-0019 decision
item 6's mechanism and is unchanged by this record.

**Rerendering is never silently substituted for missing bytes.** A reprint whose artifact has
been collected is refused and says so. Provenance in the manifest — template, profile,
capture, asset and renderer versions — exists to diagnose or deliberately reproduce a label,
not to reconstruct one behind the operator's back.

**The output format is not decided here.** Version 1 is proposed as an opaque raster
(PNG, no alpha, profile palette only). A page-description artifact a downstream service
converts and delivers is a live alternative, and it removes the device adapter **only** if
that service is verified to do both the conversion and the delivery, with delivery evidence
this fork can read. Neither format removes the geometry problem: something still decides
device pixels per module and whether the content was resampled. Plan 27 carries the
comparison; this record fixes only that the artifact is immutable, digested, and the unit a
reprint replays.

### 3. An import refuses a target holding live labels, and the refusal is atomic

0011 item 5's re-key obligation is **withdrawn as unimplementable** and replaced by an
explicit policy:

**`bin/victual-db-import` refuses to import into a database that holds live labels**, naming
the count, whether or not `--force` was given. An operator who means it retires or removes
them deliberately, which is a decision a person makes rather than a mapping the importer
guesses at.

**The refusal is enforced inside the import transaction, under a lock the label-issuance path
also takes.** A precheck is not the policy: it sees no live label, an issuance commits one,
and the import then replaces its location — the same aliasing, arriving through a window. A
PostgreSQL advisory lock taken by both paths is sufficient and is the expected mechanism; an
importer that instead requires stated application downtime must say so rather than imply a
safety it does not enforce.

**Retired labels and their historical identity survive an import.** They are the record that
answers *what this label was*, they are what makes a retired uid a discrepancy signal rather
than an error, and no schema decision may put them in `TRUNCATE … CASCADE`'s path: neither a
label row nor a retirement snapshot may carry a foreign key to a table the importer truncates.

A Victual-to-Victual import that could carry mappings does not exist and is not created here.
If one is ever built, re-keying becomes a real question again and gets its own record.

## Consequences

**A designer, a renderer and artifact storage become scheduled work**, which
[plan 27](../plans/27-label-templates-and-rendering.md) owns. Plan 25 narrows to identity,
jobs, printer configuration and the delivery worker. That is more surface in wave 3b than
0011 implied, and the alternative is shipping a print action whose appearance nobody can
change without a Python release.

**ADR-0019 must be reconciled before its acceptance.** Decision item 1's ownership table and
decision item 3's template-registration rules change; its transport, claim, fence and
redispatch decisions do not. Both records are Proposed, so this is reconciliation rather than
a second supersede.

**Exact reprints impose retention.** Bytes are kept while the label is live, with the
captures, template versions and assets their manifests reference. Retirement makes them
eligible for reference-aware collection, and after collection an exact reprint is refused
rather than approximated. This is the cost of the promise; a fork unwilling to store the bytes
should not make it.

**An import becomes something an operator must clear rather than something that quietly
works.** That is the intended trade: the alternative is a silent re-key that cannot be
implemented and a silent alias that can.

**Artifacts are not files in the generic sense.** `FilesApiController::ServeFile` gates reads
by a hardcoded per-group chain and lets unlisted groups through on authentication alone — a
deliberate posture recorded under sweep finding S2, and a fail-open default for anything added
later. Print artifacts carry captured household data, so they are reachable only through
dedicated, authorized endpoints and **no `FileGroups` value is minted for them**. The general
version of that hazard is filed as S32.

**Nothing in the wire contract changes.** No existing response shape moves, the five
`/printlabel` endpoints keep the webhook through wave 3b, and every route this record implies
is additive — so [ADR-0005](0005-wire-contract-is-the-invariant.md) is untouched and
ADR-0019 item 7's steps 2 and 3 keep their own prerequisites.

**[ADR-0013](0013-nix-built-container-images.md) is not relaxed.** The renderer and the worker
are images on no base image with no shell, and this record grants no interpreter or shell
exception. Whether a chosen headless runtime can meet that is a selection criterion in plan 27,
not a waiver here.

## Options considered

**Leave templates with the worker (ADR-0011 and ADR-0019 as written).** No new surface, and
the appearance of a household's labels is editable only by whoever can cut a worker release.
Rejected: the seam is drawn through the part people most want to change.

**Templates in this repository, rendered in this repository.** One fewer service, and it puts
font shaping, a raster pipeline and their closures back into a PHP application that
deliberately has neither. Rejected on 0011's original and correct argument.

**Reprint by rerendering from pinned provenance.** No stored bytes, cheap retention, and it
promises an exact reprint it cannot deliver: a font upgrade or a renderer change alters the
output while every pinned identifier still matches. Rejected — a reprint that is *nearly* the
old label is the failure mode this record exists to remove.

**Keep 0011 item 5's re-key obligation and implement it later.** It would need a
Victual-to-Victual import path that does not exist, and meanwhile the obligation reads as
though the hazard is handled. Rejected: an undischargeable obligation is worse than a stated
refusal.

**Import silently clears labels.** Consistent and cheap, and it destroys printed physical
artifacts without anyone deciding to. Rejected in favour of refusal, which puts the choice in
front of the person who has the shelves.

## Acceptance prerequisites

Gates, not suggestions. Each is a **disposable spike** or a written comparison, not the
beginning of the implementation.

1. **A headless renderer is selected by rendering the template contract**, not by feature
   list. At least two candidates produce the same label from the same document: multi-line
   wrapping, a pinned font with a missing glyph, QR at a declared module size with asymmetric
   `dpi_x`/`dpi_y`, black/red output, and continuous length against `length_rules`. Each
   candidate's image closure is measured and checked against ADR-0013's no-shell assertion.
   **Fabric.js is selected for browser editing only** and is not a candidate here by default;
   a headless runtime qualifies by reading the document, not by sharing the editor's engine.
2. **The artifact format comparison is written**: raster against page-description, including
   whether a downstream service exists that verifiably converts *and* delivers with readable
   evidence, and what each format leaves the device adapter to decide about geometry.
   **Two edits to [ADR-0019](0019-label-printers-are-master-data.md) follow from it and are
   owed to that record before *it* is accepted** — what the artifact adds to the job payload
   and how much geometry the worker decides (its item 4), and which fields the claim
   precondition compares for artifact and profile compatibility (its item 5). Its items 1
   through 5 were otherwise reconciled on 2026-09-07; the template-version match that could no
   longer succeed was removed then rather than deferred to here.
3. **The import refusal is demonstrated under concurrency** — issuance running against an
   import ends with the import refused or the label intact and correctly targeted, never with
   a label naming a replaced target — and retired snapshots are shown to survive an import
   that proceeds.
4. **A reprint is demonstrated to be renderer-independent**: with the renderer unavailable,
   an exact reprint of a retained artifact still queues and prints; with the artifact
   collected, the reprint is refused rather than rerendered.
5. **The canonical JSON encoding is named and fixed** — [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785)
   unless a reason is recorded — and a digest of the same document computed twice through the
   intended implementation matches. Ordinary serialization order is not a digest contract.
6. **The manifest check covers run-to-completion workloads.**
   `.devtools/ci/check_deploy_manifest.py`'s `POD_SPEC_PATHS` knows Pod, Deployment,
   StatefulSet and DaemonSet, and returns no errors for a kind it does not know — so a Job or
   CronJob renderer passes today by not being examined. Either the checker learns those kinds
   with a run-to-completion probe rule, or the renderer is a kind it already checks. A
   scale-to-zero workload that silently escapes ADR-0010's manifest gate is not deployable.

## Open questions

1. **Does the renderer credential need capability scoping rather than a key type?**
   ADR-0019 adds `API_KEY_TYPE_LABEL_WORKER` and gates nine routes on it. A renderer that may
   read its assigned inputs and upload its own result, and may *not* claim a print attempt, is
   a second identity with a different authority — and "which routes" is a coarser question
   than "which resources of this request". Whether that is a second key type, a scope set on
   the key, or a per-request grant is an implementation decision plan 27 should make with
   evidence rather than this record by assertion.
2. **What is the retention period for idempotency keys**, and what does a client do after it
   expires? The rule that a same-key replay returns the original resource has to end
   somewhere, and a client treating an expired key as a guaranteed-safe retry prints twice.
3. **Which limits** — compressed bytes, decoded pixels, element count, text length, execution
   time — does the renderer enforce? They need a representative render to be set against,
   which no deployment has yet.
4. **Does a household ever need per-print template selection?** This record pins a template
   version per job and offers revised printing as its own operation. Choosing a template at
   print time is a plausible fifth operation and is deliberately not decided.
