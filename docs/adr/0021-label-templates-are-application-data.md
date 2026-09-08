# ADR-0021: Label templates are application data, a reprint is a new job over retained bytes, and an import refuses live labels

- **Status: Accepted, 2026-09-07.** **Victual owns the template document; a reprint is a new
  job replaying retained bytes; an import refuses a target holding live labels.** All six
  acceptance prerequisites below are met, each annotated in place with what was run and how to
  reproduce it. **Nothing in the decision was revised on the way through** — no consequence
  softened, no argument improved, no prerequisite dropped. The three edits the prerequisites
  forced landed before this acceptance, in the pull request that ran them: the output format in
  item 2, which item 2 had explicitly deferred to prerequisite 2; the import epoch in item 3,
  which prerequisite 3 found by running the guard rather than reasoning about it; and the
  measured retention figure in *Consequences*.
- **Accepting decides ownership, not the schedule.** No template table exists, no renderer
  exists, and label appearance is still whatever the webhook's external service does. What
  changes today is what may be built — and, immediately, that
  [ADR-0019](0019-label-printers-are-master-data.md) may now be accepted, which it could not be
  while ADR-0011 still assigned templates to the drainer.
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
  format-dependent details were outstanding acceptance work on that record; they were written
  into its items 4 and 5 on 2026-09-07, and prerequisite 2 below confirmed that neither
  depended on the format. **This record is accepted first.** Until it is, ADR-0011's assignment of
  templates to the drainer still stands, and accepting 0019's reconciled ownership model ahead
  of this one would put two accepted records in contradiction. **That ordering is discharged by
  this acceptance**: 0011's three superseded boundaries are superseded as of today, and 0019 is
  free to be accepted next.
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

**The output format is settled by prerequisite 2, and it is a raster.** Wave 3b ships one
form: `raster/png-indexed;v=1`, PNG colour type 3 at bit depth 2 over the profile's palette,
with the pixel grid **fixed by the resolved combination** — ADR-0019's `geometry: fixed_grid`.
`pdf/1.4` with `geometry: device_placed` remains registerable and unshipped, for a deployment
whose printers can take one.

Three findings decided it, none of them a preference between the formats:

- **The QL-820NWBc advertises no page-description format on either transport**, so a page
  description reaches the one device wave 3b must print on only through a converting host —
  and that host's report of what it did is not the printer's report of what it printed. The
  laser is its own converter and does report readable evidence, which is what makes `pdf/1.4`
  worth keeping expressible.
- **Retention does not separate them.** The same label is 2,573 bytes as an indexed raster and
  1,573 as a compressed PDF.
- **A reprint means something stronger under a fixed grid.** The retained bytes are the dots,
  so replaying them reproduces the label; under device placement they are instructions, and the
  page depends on the device's current interpretation. This section makes a reprint a replay of
  bytes, and that is the reading it implies.

Neither form removes the geometry question — something still decides device pixels per module —
but `geometry` names *who*, once per form, rather than leaving it to each implementation to
assume. That is the half of issue [#90](https://github.com/datagen24/victual/issues/90) a format
choice can fix.

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

**The lock is necessary and not sufficient, and prerequisite 3 is where that surfaced.** It
orders issuance against the import; it cannot tell an issuance that the ground moved. A request
composed before an import and executed after it mints a label for whatever now holds that id —
a row that is self-consistent and is not what anybody asked for. So **the issuance request
carries the import epoch it was composed at**, a counter the import increments, and issuance
refuses when it no longer matches. It is one integer and it closes the last window; without it
the guard is honest about concurrent imports and silent about consecutive ones.

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

**Exact reprints impose retention**, and prerequisite 2 measured the bill: about 2.6 KB per
label as an indexed raster, so ten thousand live labels are roughly 25 MB of artifact bytes.
Bytes are kept while the label is live, with the captures, template versions and assets their
manifests reference. Retirement makes them
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
exception. Whether a chosen headless runtime can meet that was a selection criterion in
plan 27, and prerequisite 1's selected candidate does: a single Rust binary over
`usvg`/`tiny-skia`, with no shell and no interpreter in its closure. The alternative this
constraint ruled out is on the record too — a CUPS and Ghostscript conversion path, which
closes over 400 MiB and carries two shells.

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

**All six are met.** The spikes are on the disposable branch
`claude/opus5_adr0021-prerequisites` at `4a3b0713ec07e90669887790363126985678d3fd`, except
prerequisite 6, which is a change to a checked-in CI script rather than a spike and is on the
branch carrying this record. Every run below is from 2026-09-07 on the maintainer's Apple
silicon machine, against PostgreSQL 16.15 in podman and the two printers on the household
network.

1. **A headless renderer is selected by rendering the template contract**, not by feature
   list. At least two candidates produce the same label from the same document: multi-line
   wrapping, a pinned font with a missing glyph, QR at a declared module size with asymmetric
   `dpi_x`/`dpi_y`, black/red output, and continuous length against `length_rules`. Each
   candidate's image closure is measured and checked against ADR-0013's no-shell assertion.
   **Fabric.js is selected for browser editing only** and is not a candidate here by default;
   a headless runtime qualifies by reading the document, not by sharing the editor's engine.

   **Met.** Three candidates were run on 2026-09-07 and a single binary over `usvg`/`tiny-skia`
   was recommended then — the only one that emitted a palette-conformant artifact and the only
   one with neither shell nor interpreter in its closure. Selection waited on three
   qualifications, and all three now have expectations computed by something that is not the
   renderer:

   | Check | How the expectation was formed | Result |
   |---|---|---|
   | Kerning | `fontTools` walks GPOS and predicts the advance of five pairs from the font's own tables | All five agree exactly, and all five differ from the unkerned sum: `AV` −40, `To` −70, `PA` −50, `AT` −70, `LT` −20 units per em |
   | Right-to-left | Hebrew is strong RTL, so the first logical character must be rightmost; Arabic beh joins, with `init` and `fina` advances resolved from GSUB | Eight of eight. The wide final mem is the right cluster and the narrow yod the left; the joined pair's advance is `uniFE91` + `uniFE90` and one ink cluster, and a zero-width non-joiner restores both the isolated advance and the gap |
   | Cost | Twenty runs per contract case, wall clock and peak resident set of the whole process | 7.9–22.9 ms mean, 24.8 ms worst p95, ≤ 9.1 MiB peak RSS. A refusal (`MISSING_GLYPH`) costs 2.5 ms and 3.0 MiB |

   Two things the kerning work settled beyond the check itself. usvg reports a text node's
   bounding box as **the run's advance**, not its ink extent — confirmed against `hmtx` for six
   glyphs individually — which is the right quantity for line breaking and the one kerning
   changes. And the cost figures are for one process per render with no daemon to amortise the
   start, which is what a run-to-completion render job actually is.

   Reproduce: `python3 .spike-renderer/qualify/kerning.py`,
   `python3 .spike-renderer/qualify/rtl.py <a font with Hebrew and Arabic>`,
   `python3 .spike-renderer/qualify/cost.py`.

2. **The artifact format comparison is written**: raster against page-description, including
   whether a downstream service exists that verifiably converts *and* delivers with readable
   evidence, and what each format leaves the device adapter to decide about geometry.

   **Met**, in `.spike-adr21/artifact-format-comparison.md`. **Wave 3b ships one form**: an
   indexed raster whose pixel grid is fixed by the resolved combination
   (`raster/png-indexed;v=1`, `geometry: fixed_grid`, PNG colour type 3 at bit depth 2 over the
   profile's palette). `pdf/1.4` with `geometry: device_placed` stays registerable and unshipped.

   What decided it was not a preference between the formats:

   - **The QL-820NWBc advertises no page-description format on either transport** — raw 9100
     has no format negotiation and returns nothing at all, and its IPP
     `document-format-supported` is `application/octet-stream` and `image/urf`. So a page
     description reaches the one device wave 3b must print on only through a converting host.
     The laser, by contrast, is its own converter and reports readable evidence: demonstrated
     twice, most recently as prerequisite 4's reprint.
   - **The retention argument for page descriptions does not survive measurement.** The same
     label is 2,573 bytes as a 2-bit indexed PNG against 1,573 as a Flate-compressed PDF — 1.6×,
     not the order of magnitude the raster form is usually charged with.
   - **Reprint fidelity is what actually decides it.** Under `fixed_grid` the retained bytes are
     the dots, so replaying them reproduces the label; under `device_placed` they are
     instructions, and the page depends on the device's current interpretation. Decision item 2
     above makes a reprint a replay of bytes, and for a label that has to scan and match a shelf
     for years the stronger reading is the one that contract implies.
   - **Shipping a converter instead is priced rather than dismissed.** `cups`, `cups-filters`
     and `ghostscript` from this flake's pinned nixpkgs close over **419,574,616 bytes across
     119 store paths and carry `bash` and `bash-interactive`**, against the Rust label worker's
     62,644,200 bytes, 7 paths and no shell at all. It fails
     [ADR-0013](0013-nix-built-container-images.md)'s assertion by the very name that check's negative control
     was built to catch.

   Two findings belong to [plan 27](../plans/27-label-templates-and-rendering.md) rather than
   here: the renderer emits RGBA where the identifier says indexed, 14× larger for identical
   pixels (37,145 bytes against 2,573, and the re-encode is lossless — decoded palette counts
   match the renderer's reported 38,439 black / 0 red / 339,489 white exactly); and a form
   identifier has to pin colour type and bit depth, or "the same form" spans artifacts differing
   by more than that.

   **This comparison confirmed, rather than supplied, ADR-0019's two format-dependent edits.**
   They were written first, over form identifiers rather than over formats, and nothing here
   changed them.

3. **The import refusal is demonstrated under concurrency** — issuance running against an
   import ends with the import refused or the label intact and correctly targeted, never with
   a label naming a replaced target — and retired snapshots are shown to survive an import
   that proceeds.

   **Met**, twelve of twelve assertions, `.spike-adr21/import/run.sh` against PostgreSQL
   16.15. The spike is shaped after `services/Database/DatabaseImporter.php` as it stands:
   `AssertTargetIsEmpty()` before `beginTransaction()`, one transaction around
   `TRUNCATE … RESTART IDENTITY CASCADE` and the copy, and `ResyncGeneratedIdCounters()` after
   the commit.

   | Case | Result |
   |---|---|
   | The guard where `AssertTargetIsEmpty` puts it today | **The defect reproduces**: the precheck sees zero labels, an issuance lands in the window, and the uid then resolves to `Ell` — the row that replaced the `Shed` it was minted for |
   | Guard inside the transaction, issuance wins the lock | The import is refused naming the count; the label still resolves to `Shed`; `locations` is untouched |
   | Guard inside the transaction, import wins the lock | Issuance blocks 1.5 s on the lock rather than racing it, then mints against the committed state |
   | The residue that leaves | A request composed before an import and executed after it labels whatever now holds that id — self-consistent, and not what anybody asked for |
   | The same race with an import epoch on the request | Refused: `the location set was replaced (epoch 3 -> 4)`, and nothing minted |
   | An import that proceeds | Retired snapshots survive `TRUNCATE … CASCADE`; the freed id can be labelled again; the old uid reports `retired: Shed` and never follows the id to `Ell` |

   Two things this fixes in what decision item 3 says. The lock alone is **not** sufficient —
   a **monotonic import epoch carried on the issuance request** is what closes the last window,
   and it is cheap. And `labels` must carry **no foreign key to `locations`**, deliberately: an
   FK puts every retirement snapshot in `TRUNCATE … CASCADE`'s path and erases exactly the
   history retirement exists to keep.

4. **A reprint is demonstrated to be renderer-independent**: with the renderer unavailable,
   an exact reprint of a retained artifact still queues and prints; with the artifact
   collected, the reprint is refused rather than rerendered.

   **Met**, twelve of twelve, end to end against the networked laser
   (`.spike-adr21/reprint/run.sh`). The artifact is rendered once and retained; the renderer
   binary is then **moved off disk** and shown to fail with exit 127; the reprint queues over
   the same artifact row and creates no second artifact; the bytes come back out of the database
   with the digest they went in with
   (`533d05dd59dd286cb5791ee7b73ebaf13f3caee10ae70d2f43d76d822c33530f`, 16,124 bytes) and print
   — IPP job 56, `job-state = completed`, `job-completed-successfully`, one impression. The
   artifact is then collected and the same reprint is **refused**, naming the artifact and its
   digest, with the renderer still absent and no job queued.

5. **The canonical JSON encoding is named and fixed** — [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785)
   unless a reason is recorded — and a digest of the same document computed twice through the
   intended implementation matches. Ordinary serialization order is not a digest contract.

   **Met.** RFC 8785 it is, and the implementation is Victual's own: **no JSON Canonicalization
   Scheme library exists in `composer.json` and PHP's `json_encode` is not one.** The spike
   (`.spike-adr21`, run on PHP 8.5.9) shows exactly where it diverges — `1.0e+30` for `1e+30`,
   `1.0e-7` for `1e-7`, `a\/b` for `a/b`, `\u00e9` for `é` — so the number layout and the
   string escaping have to be written, not configured.

   Verified two ways. Twenty-three vector assertions covering the ECMAScript
   `Number::toString` boundaries (`1e+21` and `1e-7` from both sides, the smallest subnormal,
   the largest double, 2^53), key-order independence, UTF-16 code-unit key ordering, and
   refusals for NaN, Infinity and integers that do not survive the double round trip. Then a
   **differential test against an ECMAScript oracle** — a fifteen-line reference implementation
   that is correct by construction, since `JSON.stringify` already produces RFC 8785's number
   layout and string escaping and `Array.prototype.sort` already compares UTF-16 code units.
   **2,206 documents, 2,000 of them doubles built from random bit patterns: byte-identical.**

   Three rules the spike forces into the implementation. Object keys sort by **UTF-16 code
   unit**, which is not UTF-8 byte order — an astral character is a surrogate pair below
   `U+E000`, so `😀` sorts before `ﬀ` and byte order gets it backwards, for exactly the keys a
   household's own language data is most likely to carry. Integers are refused when they do not
   round-trip through a double, tested by **exact representability** rather than the safe-integer
   range, since 2^53 is representable and 2^53+1 is not. And NaN and Infinity are refused rather
   than encoded.

6. **The manifest check covers run-to-completion workloads.**
   `.devtools/ci/check_deploy_manifest.py`'s `POD_SPEC_PATHS` knows Pod, Deployment,
   StatefulSet and DaemonSet, and returns no errors for a kind it does not know — so a Job or
   CronJob renderer passes today by not being examined. Either the checker learns those kinds
   with a run-to-completion probe rule, or the renderer is a kind it already checks. A
   scale-to-zero workload that silently escapes ADR-0010's manifest gate is not deployable.

   **Met**, by teaching the checker. `Job` and `CronJob` now resolve their pod specs, and their
   containers are exempt from the probe requirement for the same reason init containers already
   were — a probe on a container that is *meant* to terminate either never runs or reports the
   normal end of the work as a failure. Every security and resourcing obligation still applies.

   The gap was not the two missing kinds; it was that an unknown kind passed by not being
   examined, with no difference between "holds no containers" and "holds containers I could not
   reach". So **any** unrecognised kind carrying a container list is now an error naming
   `POD_SPEC_PATHS`, and the next kind fails closed instead of repeating this. ConfigMaps and
   Services still pass. Five new tests, one of which pins the gap itself: the same bad Job
   produces errors now and resolved to no pod-spec path before. Twenty-three tests pass under
   `python3 -m unittest discover -s .devtools/ci`, and the real manifest is unchanged.

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
   time — does the renderer enforce? Prerequisite 1 supplies the baseline they would be set
   against, which is the part that was missing: a contract-fixture label costs 7.9–22.9 ms and
   at most 9.1 MiB of resident set, and a refusal costs 2.5 ms and 3.0 MiB. What a limit should
   be is still a judgement about the worst document a household could author, not about these
   five, so the question stays open with numbers under it rather than none.
4. **Does a household ever need per-print template selection?** This record pins a template
   version per job and offers revised printing as its own operation. Choosing a template at
   print time is a plausible fifth operation and is deliberately not decided.
