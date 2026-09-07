# 27. Label templates, rendering, previews and artifacts

**Goal:** Let a person design a label in the browser, see an authoritative preview of what
will actually print, and print it — with the printed bytes kept, so an exact reprint is the
same label rather than a similar one.
**Depends on:** [12](12-frontend-shared-core.md) (landed), [01](01-file-storage.md) (landed),
[19](19-rbac.md) piece 1 (implemented), and [25](25-label-infrastructure.md)'s identity and
job work. Gated on [ADR-0021](../adr/0021-label-templates-are-application-data.md), which is
**Proposed** — see **Gates**.
**Status:** draft for review. Scheduled into wave 3b alongside 25.
**Migrations:** inventoried below; **no reservation is claimed yet**, deliberately.

## Why this plan exists

[ADR-0011](../adr/0011-label-namespace.md) put label templates in the drainer and
[ADR-0019](../adr/0019-label-printers-are-master-data.md) implemented that split, which makes
a household's label appearance editable only by whoever can cut a worker release.
[ADR-0021](../adr/0021-label-templates-are-application-data.md) moves the template document
into Victual while leaving rendering outside it, and this plan owns what that creates: the
editor, the renderer contract, previews, and the artifacts a reprint replays.

**The division with [25](25-label-infrastructure.md)**, which is the plan this one was split
out of:

| 25 owns | 27 owns |
|---|---|
| `labels`, uid generation, canonicalization, authorized resolution, retirement | Template drafts, published versions, assets, media profiles |
| The print job, attempts, evidence, claim/fence/lease semantics | Durable render requests, artifacts and their manifests |
| Printer and worker configuration, the nine worker routes, delivery | Previews, and promoting a preview to a print |
| The import refusal ADR-0021 decision item 3 requires, since `labels` is its table | The browser designer, and the renderer service and its deployment |

[06](06-location-barcodes.md) is unchanged and still owns the locations print action, what the
label says, and the stateless `vctl:` resolve surface.

## Gates

**ADR-0021 is Proposed, not Accepted**, and it carries six acceptance prerequisites. **No
schema, no route and no UI is written under this plan before that acceptance.** Two of those
prerequisites are this plan's to run — the renderer comparison and the artifact-format
comparison — and both are written comparisons or disposable spikes rather than the beginning
of the implementation.

**ADR-0019 must be reconciled in the same window.** Its decision item 1 ownership table and
item 3 template-registration rules change; nothing else about it does. Both records being
Proposed is what makes that reconciliation rather than a second supersede.

## Pieces

### Piece 1 — the template document and its lifecycle

A Victual-defined document format, not an editor's internal object graph. Drafts are mutable
and carry a revision token, so an update with a stale token is a conflict rather than a silent
overwrite of another editor's work. Publishing validates the document and every asset it
references and creates an immutable version with a digest.

- **Elements in version 1:** text, QR, stored raster images, lines, rectangles. No scripts, no
  HTML, no external URLs, no expressions, no editor plugins. Unknown `schema_version` and
  unknown features are rejected, never approximated.
- **Text** pins a font asset, size in points, box, alignment, wrapping and line spacing. The
  default overflow policy is `error`; `ellipsis` and bounded `shrink_to_fit` are explicit.
  A missing glyph is an error rather than silent font substitution.
- **A point is a physical size, and the device has two resolutions.** One point is 1/72 inch.
  **Horizontal geometry resolves against `dpi_x` and vertical geometry against `dpi_y`**, and
  measuring, wrapping and painting must all use that same physical coordinate model — 11 pt on
  a 300 × 600 device is a **nominal em of 45.8 × 91.7 device pixels**, and a renderer reaches
  it by scaling outlines anisotropically rather than by resampling a raster. That is the em,
  not a glyph: actual advances and ink bounds depend on the font and on shaping.

  This is stated because leaving it implicit produced a real defect. In the renderer comparison
  of 2026-09-07, two candidates sized the font at `size_pt × dpi_y / 72` and then measured
  *horizontal* advances in that space, making every string twice as wide as its physical size:
  the same document wrapped to five lines instead of three, and through the automatic-height
  rule that changes the label's length in millimetres. It is the same class of silent geometry
  error as [issue #90](https://github.com/datagen24/victual/issues/90), arriving from the
  document format rather than from a driver.
- **QR** binds to the server-supplied `label.payload` — never a user-editable literal for a
  production location label — with a declared error correction level and minimum physical
  module size, a four-module quiet zone, and whole device pixels per module on each axis.
  Stretching or smoothing modules is forbidden.
- **Automatic height is bounded**, per ADR-0021 decision item 1: content extent plus explicit
  spacing, validated against the profile's length rules. It never crops and it never grows
  without a bound, because continuous tape is not an unbounded canvas.
- **A default version is an administrative pointer.** Changing it affects new issuance and
  revised prints only; it cannot touch queued jobs, previews or existing artifacts.

Publication establishes structural validity, not fitness for every field value — overflow,
glyph coverage, QR geometry and media compatibility are still checked at each render.

### Piece 2 — assets, the field catalogue and captures

Assets are uploaded to managed storage and validated by type, decoded dimensions and size,
with bounded decoding. Version 1 accepts approved font formats and raster images; SVG and any
remote retrieval are excluded. Fonts are pinned by content and carry licensing metadata.

The field catalogue is per entity kind — `location` first — and each field declares a type, the
permission required to read it, a length bound and its null behaviour. **Clients submit entity
references, never purported values**: Victual authorizes and captures in one transaction, and
`captured_fields` is immutable afterwards. Formatting uses pinned locale and timezone inputs
rather than host defaults or the wall clock at render time, so the same capture renders the
same way later. Sample data exists for previews only and is marked as sample data.

### Piece 3 — media profiles

An immutable, versioned, digested contract derived from validated printer capabilities, and
distinct from the mutable printer row and its network address: media identity and physical
size, printable area, independent `dpi_x`/`dpi_y`, required raster width, permitted lengths and
increments, the correspondence between image axes and tape feed, colour mode, and a versioned
threshold/dither policy with its permitted palette.

Physical-to-pixel rounding is specified once, in the renderer contract. The profile separates
content pixels from declared non-printing transport padding: adding padding is permitted,
resampling content is not. At claim time an incompatible media or resolution change **blocks**
delivery — no fallback to the nearest size, the latest profile, or monochrome. A changed
address is resolved at claim, which is ADR-0019 decision item 4 unchanged.

### Piece 4 — the headless renderer

A separate service that reads the template document, the profile and the captured fields
through authenticated managed-storage access, and produces an artifact. It accepts no
caller-controlled fetch destination.

- **Requests are durable**: `pending → rendering → ready | invalid | failed`, with leases and
  generation tokens fencing competing renderers. **Expired computation may be retried
  automatically precisely because it cannot touch a printer** — the no-automatic-redispatch
  rule is about physical attempts and is not weakened here. A stale result cannot replace a
  committed artifact.
- **`invalid` is input or layout error** — `TEXT_OVERFLOW`, `MISSING_GLYPH`, `QR_TOO_SMALL`,
  `MEDIA_INCOMPATIBLE`, `ASSET_UNAVAILABLE`, `UNSUPPORTED_VERSION`, each naming the affected
  element — and `failed` is infrastructure, with bounded retry. A warning never authorizes
  printing through a failed validation.
- **The receiving service verifies before marking ready**: byte digest, decoded dimensions,
  palette, request identity, and that the QR decodes to the pinned payload. Bounds apply to
  both compressed bytes and decoded pixels.
- **It scales to zero**, claims one durable request, uploads its result and exits. The
  dispatcher must find queued work while no renderer exists, tolerate duplicate starts through
  claim fencing, and recover a lost invocation — no request may depend on a live Victual
  process remembering to launch it.

**Runtime selection is open — candidate C recommended, not selected** (question 3 records the
run). It is decided by rendering the contract, per ADR-0021's prerequisite 1: the same document through at least two candidates, exercising multi-line
wrapping, a pinned font with a missing glyph, QR at a declared module size with asymmetric
resolutions, black/red output, and continuous length against `length_rules` — with each
candidate's image closure measured against ADR-0013's no-shell assertion. **Fabric.js is
selected for browser editing only.** A headless runtime qualifies by reading the document, not
by sharing the editor's engine; the authoritative preview is the render, so the browser canvas
is a design aid rather than a fidelity claim.

**The artifact format is a comparison, not a settled choice** (ADR-0021 prerequisite 2). The
proposal is an opaque raster; a page-description artifact is live, and removes the device
adapter **only** if a downstream service verifiably converts *and* delivers with evidence this
fork can read. Neither format removes the geometry question — something still decides device
pixels per module and whether content was resampled — so the comparison records what each
leaves the adapter to decide.

### Piece 5 — artifacts and their manifests

An artifact is immutable bytes plus a manifest: digest, byte length and MIME type; exact
geometry and resolutions; the profile, template, capture, renderer and asset identities it came
from; and validation provenance. Multiple historical artifacts may share one uid, and a reprint
reuses stored bytes rather than duplicating them.

- **Bytes are the authority for a reprint.** Rerendering is never silently substituted for
  missing bytes; a reprint whose artifact has been collected is refused and says so.
- **Digest equality grants nothing.** Authorization follows the owning request, job and entity
  kind.
- **Structured digests use [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) canonical JSON**
  and artifact digests cover exact bytes. Ordinary serialization order is not a digest
  contract, and no client-supplied digest is trusted without server verification.

**Where the bytes live, and how the generic files API is kept away from them.** Plan 01's
`files` table takes the bytes: `file_group` is an ordinary column, while
`FilesApiController` validates the group against the OpenAPI `FileGroups` enum and rejects
anything absent from it. So artifacts and assets are stored under group names **deliberately
not minted in that enum**, which makes the generic upload, serve and delete routes refuse them
by the check they already run, and leaves dedicated authorized endpoints as the only path.
That is not decoration: `ServeFile` gates reads by a hardcoded per-group chain and lets
unlisted groups through on authentication alone — the posture recorded under S2 and filed
generally as **S32**. Artifacts must not be exposed through `ExposedEntity` either, for the
reason migration 0258 already gives for `files` itself.

**Artifact storage requires the database backend, and the requirement is checked at startup**
(question 7). `FILE_STORAGE=filesystem` would put captured household data and every printed
label on disk, reintroducing the persistent volume [10](10-cold-start-statelessness.md) exists
to remove. So the check is conditional on the label subsystem being enabled, in the shape
`ConfigurationValidator::checkMqttSettings()` and `::checkInfluxDbSettings()` already use —
return early when the subsystem is off, and otherwise refuse a configuration it cannot honour
with an `EInvalidConfig` naming both settings. **There is no fallback to filesystem storage**,
silent or otherwise: a household that enables labels with the wrong backend finds out at
startup, when it can still change its mind, rather than when an artifact goes somewhere it was
not meant to live. That is `checkFileStorage()`'s own stated reason for refusing at startup
rather than at first upload.

Two consequences to carry rather than discover:

- **The enabling flag is a new one, not `FEATURE_FLAG_LABEL_PRINTER`.** That constant exists
  (`config-dist.php`, default false) and gates the *webhook* path's buttons across nine
  `public/viewjs` call sites. Binding the storage requirement to it would make turning on the
  existing product/stock-entry printing demand database storage — a behaviour change to the
  path [ADR-0019](../adr/0019-label-printers-are-master-data.md) item 7 deliberately leaves
  alone through wave 3b. A separate flag keeps the two paths independent during coexistence
  and gives step 3 something to delete.
- **The label subsystem cannot be enabled in `demo` or `prerelease` mode.**
  `checkFileStorage()` already refuses `FILE_STORAGE=database` there, because
  `FilesystemStorage` gives each demo instance its own sub-folder and `files`'
  `UNIQUE(file_group, name)` has no column for that suffix (plan 01 Q4). Requiring the
  database backend therefore excludes those modes transitively, and the error a demo operator
  sees should say so directly rather than making them derive it from two separate refusals.

### Piece 6 — previews, and promoting one to a print

Authoritative previews use the production renderer and profiles. Sample previews use a
synthetic payload and create no mapping; draft previews pin a snapshot so later edits cannot
change an in-flight result. A live preview requires the same read permissions as capture, and
may use a real uid; a location with no label gets a sample, and the UI must not describe it as
the exact image that will print.

A preview creates no print event and no physical attempt. Watermarks are UI overlays, never
modifications of an artifact that might later print. **"Print this preview"** is available only
for a valid retained live artifact from a published template and a real live uid: the server
rechecks print permission, retirement and target-profile compatibility, then queues the same
bytes and captured values. Snapshot age is disclosed; refreshing creates a new render. Sample
and draft previews cannot be promoted.

Unpromoted previews expire after 24 hours. Promotion changes an artifact's retention class, and
promotion and collection must not race.

### Piece 7 — operations, idempotency and the API surface

The four operations ADR-0021 decision item 2 fixes — issue, reprint, revised print, authorize
another attempt — plus preview promotion. **Revised printing ships in wave 3b**, so a renamed
location can get a current label under the same uid without altering the earlier artifact.

- Issuance commits the mapping, the capture, the job and the existing
  `label.print_requested` outbox event atomically, under a **new payload version**. The job
  exists while rendering is pending; **workers cannot claim it until a validated artifact is
  attached**, so claimability reads readiness and authorization rather than `delivered_at`
  alone. Rendering never holds a transaction open while computing.
- **Idempotency keys are scoped to principal and operation and bound to a canonical request
  fingerprint.** Same key and same inputs returns the original resource; changed inputs
  conflicts; authorization is rechecked before an existing result is returned. The browser
  keeps one pending action's key across retries and reloads, and a second intentional action
  gets a new key. The deduplication retention period is published, because a client treating an
  expired key as a guaranteed-safe retry prints twice.
- Concurrency: a partial unique index over `(kind, target_id) WHERE retired_at IS NULL` keeps
  one live uid per target; two concurrent issuances yield one uid, the loser enqueueing against
  the winner's. Conflict recovery must not continue inside an aborted transaction.

Routes are additive and follow the existing error envelope, with a stable code, an element or
field path where applicable, a readable message, and whether retrying can help. Asynchronous
creation answers `202` with a status location; a pending image exposes status rather than an
empty body; stale drafts and idempotency conflicts are `409`; unauthorized access discloses
neither captured values nor existence.

**Permissions**, per the maintainer's answer to question 2: `ADMIN` for template and printer
administration; `MASTER_DATA_EDIT` **plus the relevant domain read** for printing, reprinting,
revised printing, preview promotion and attempt authorization — `STOCK_VIEW` for locations.
These are permission checks, never role names; a custom role carrying the grants qualifies.

**The renderer's credential is not the worker's.** ADR-0019 adds
`API_KEY_TYPE_LABEL_WORKER` for nine delivery routes. A renderer may read its assigned inputs
and upload its own result and **may not claim a print attempt**, which is a per-resource
authority rather than a per-route one. Whether that is a second key type, a scope set, or a
per-request grant is ADR-0021 question 1 and this plan decides it with evidence.

### Piece 8 — deployment

Both services are images on no base image with no shell, per
[ADR-0013](../adr/0013-nix-built-container-images.md) and
[ADR-0010](../adr/0010-workload-standard.md); this plan grants no interpreter or shell
exception, and a candidate runtime that cannot meet it is disqualified rather than waived.
The renderer needs Victual's assigned assets and its result API — not printers, not arbitrary
outbound destinations — and runs under bounded memory, pixels, elements, text length and
execution time.

**The manifest gate must actually see the renderer.** `.devtools/ci/check_deploy_manifest.py`
resolves containers for Pod, Deployment, StatefulSet and DaemonSet and returns no errors for a
kind it does not know, so a Job or CronJob renderer passes today **by not being examined** —
the one new workload the gate exists for. Either the checker learns those kinds, with a
run-to-completion rule for probes that mirrors its existing init-container exemption, or the
renderer is a kind it already checks. This is ADR-0021 prerequisite 6 and it is this plan's to
discharge.

## Migration inventory

**Inventoried, not reserved.** `migrations/RESERVATIONS.md` is unchanged by this draft
deliberately: 0269–0270 are plan 25's and 0271–0273 are held by plans 23 and 22, which have
moved six times already, and the table's own rule is that scheduled work takes the lowest free
slots. Applying that rule again is a decision for the branch that writes the first file, not
for this plan.

**Moved from plan 25's 0270 to this plan.** `label_templates` was one of ADR-0019's nine tables
and held worker-advertised template definitions. Under ADR-0021 it becomes Victual's, so 25's
0270 drops it to **eight** tables and workers advertise the artifact and profile contract
versions they accept instead.

**This plan's tables**, in two groups because the second references the first:

| Group | Table | Holds |
|---|---|---|
| A | `label_templates` | Template identity: name, entity kind, default version pointer, archival |
| A | `label_template_drafts` | The mutable document and its revision token |
| A | `label_template_versions` | Immutable published documents and their digests |
| A | `label_assets` | Font and image references, digests, licensing metadata |
| A | `label_media_profiles` | Immutable versioned profiles and their digests |
| B | `label_captures` | Immutable captured fields, locale and timezone inputs, digest |
| B | `label_render_requests` | Durable request state, lease, generation token, purpose, expiry |
| B | `label_artifacts` | Manifests, referencing bytes in `files` |
| B | `label_idempotency_keys` | Principal, operation, request fingerprint, resource, expiry |

**Plus an alteration to plan 25's `print_jobs`**: the operation that created it, the artifact
and render request it points at, the source job a reprint names, and its idempotency key. That
is the ordering constraint — 25's 0270 lands before this plan's group B — and it is the reason
group B is not independently mergeable ahead of 25.

Nothing here is dual-engine: every number is above 0265 and therefore PostgreSQL-only.

## Client impact

Additive. No existing response shape changes, no existing print path moves, and the five
`/printlabel` endpoints keep the webhook through wave 3b —
[ADR-0019](../adr/0019-label-printers-are-master-data.md) item 7's steps 2 and 3 are untouched
by this plan. The `FileGroups` enum is deliberately **not** extended, so no client sees a new
file group. [17](17-ecosystem-clients.md) gains nothing to carry beyond coupling 4.

## Security

- **Artifacts and assets are never reachable through the generic files API**, and no
  `FileGroups` value is minted for them. Sweep finding **S32** records the general hazard: file
  group reads are open-by-default and nothing forces a new group to declare a gate, in a tree
  whose entity reads are fail-closed.
- **Artifacts carry captured household data.** Access follows the owning request, job and
  entity kind; a digest is not a capability.
- **No new outbound capability.** The renderer reaches Victual's API and nothing else; the
  worker reaches its declared devices. Victual remains the server, never the client — the
  property [25](25-label-infrastructure.md)'s security section already claims and this plan
  must not quietly spend.
- **Uploads are bounded and validated** on decode as well as on declared type, and no remote
  retrieval exists to be pointed anywhere.

## Verification

1. A **browser-free API request** prints a location label from a published template.
2. An authoritative preview and the job promoted from it reference the **same digest**; a
   sample preview creates neither a mapping nor a job and cannot be promoted.
3. Multi-line wrapping, a missing glyph, overflow, black/red output, continuous length and
   asymmetric resolutions each produce a validated artifact **or a specific error naming the
   element** — never a silently approximated label.
   **Text width is asserted against an independently calculated physical size**, not against
   the renderer's own measurement. A test that only checks that measuring and painting agree
   passes just as happily when both are wrong on the same axis — which is exactly the defect the
   2026-09-07 comparison found. The expected value has to come from somewhere the renderer is
   not, and what that takes differs by case:

   - **Plain width** uses a deliberately simple fixture — a font with known advances and no
     kerning between the chosen characters — so the expected width is those advances scaled by
     `dpi_x`, computed outside the renderer.
   - **Kerning and right-to-left** cannot be checked that way: summing character advances is
     not what shaping produces. Those need independently expected shaping results — a reference
     the fixture states, from a second implementation or from values fixed by inspection and
     recorded — and the assertion is against that, not against a sum.
4. A physical QR scans back to the pinned uid; the adapter detects any unexpected resizing;
   a physical print confirms feed orientation and dimensions.
5. **A reprint is renderer-independent**: with the renderer unavailable, an exact reprint of a
   retained artifact queues and prints; with the artifact collected, the reprint is **refused**
   rather than rerendered. Template, font and renderer upgrades do not change the bytes.
6. One request delivered twice creates one job; the same key with changed parameters conflicts;
   two intentional requests share a uid without merging their jobs.
7. A renderer crash retries without printing; a stale render result cannot replace a committed
   artifact; a crash during physical delivery still triggers no automatic redispatch.
8. Concurrent issuance, deletion and import cannot produce a live mapping to a replacement
   target; promotion and collection cannot lose an accepted print's artifact.
9. An artifact is **not retrievable** through `GET /api/files/{group}/{fileName}`, and an
   unauthorized caller learns neither captured values nor existence. Invalid digests and
   oversized decoded images are rejected.
10. Cancellation racing a claim produces one truthful outcome; retirement stops new claims
    without rewriting a running attempt as safely cancelled.
11. The renderer's deploy manifest is **examined** by `.devtools/ci/check_deploy_manifest.py` —
    demonstrated by a manifest missing a limit failing the check — rather than skipped for its
    kind.
12. A digest computed twice over the same document through the intended implementation matches,
    with key order and unicode escaping varied in the input.

## Open questions

1. **Does revised printing ship with the designer?**

   > **Response (maintainer, 2026-09-07):** Yes. Wave 3b includes exact reprint and printing
   > updated data with the same uid. Image bytes are stored in PostgreSQL-backed managed
   > storage and linked through immutable artifacts; reprints reuse the bytes.

2. **Which existing grants authorize printing and retry authorization?**

   > **Response (maintainer, 2026-09-07):** `ADMIN` for templates and printers;
   > `MASTER_DATA_EDIT` plus the relevant domain read permission for printing and retry
   > authorization. For locations the read grant is `STOCK_VIEW`. Custom roles can carry these
   > existing permissions; no role name is privileged by the implementation.

3. **Which renderer and editor implement the contract?**

   > **Response (maintainer, 2026-09-07):** Fabric.js is selected for the browser editor. The
   > headless runtime remains open. Compare multiline layout, pinned fonts, QR geometry,
   > asymmetric resolution, black/red output and runtime closure before selecting it.

   > **Note, 2026-09-07:** carried into ADR-0021 prerequisite 1 as a comparison run against the
   > template contract. Sharing the editor's engine is explicitly not a qualification.

   > **Comparison run, 2026-09-07 — candidate C recommended, selection pending.**
   >
   > Tested revision `a5f593fc` on `claude/opus5_adr0021-renderer-comparison`; nixpkgs pinned at
   > `3ed67ec0a4d3c7ab4ae1f04f8ee8df07bfa506a2`; built and run for `aarch64-linux` inside a
   > podman `nixos/nix` container against one template document, one media profile at
   > **300 × 600 dpi**, and five cases under `.spike-renderer/contract/`.
   >
   > Reproduce, from that branch, in a builder container with the worktree mounted at `/src`:
   >
   > ```
   > nix build --impure -f /tmp/rsrender.nix          # rustPlatform over .spike-renderer/rsrender
   > rsrender --dir /src/.spike-renderer/contract --case <id>    >          --fonts /src/.spike-renderer/fonts --out /tmp/<id>.png
   > ```
   >
   > **Candidates.** A: Python + Pillow. B: a Python emitter in front of the resvg CLI.
   > **C: a single Rust binary over `usvg`/`tiny-skia`** that measures the same text node it
   > paints. Fabric.js was not a candidate — it is selected for editing only. Chromium was
   > measured for reference as the one runtime that could share the editor's engine.
   >
   > **Candidate C's five results**
   >
   > | Case | Result |
   > |---|---|
   > | `c1_wrap` | 696 × 543 px, 23.0 mm, 3 lines |
   > | `c2_glyph` | `MISSING_GLYPH` naming `冷蔵庫`, refused before drawing |
   > | `c3_qr_geometry` | 33 modules at 6 × 12 device px, anisotropy 2.0, decodes to the pinned uid |
   > | `c4_colour` | artifact contains exactly `#000000`, `#ff0000`, `#ffffff` |
   > | `c5_length` | 22.8 mm; against a 20 mm profile, `MEDIA_INCOMPATIBLE` naming both numbers |
   >
   > **Closures**, with shell and interpreter references counted as `image-has-no-shell` counts
   > them: **C 62,632,768 B over 7 paths, 0 references**; resvg CLI alone 101,943,896 B, 7, 0;
   > Pillow environment 316,784,648 B, 56, 1; emitter environment 332,754,904 B, 57, 1;
   > Chromium 1,842,653,336 B, 340, 2. C's closure is glibc, libgcc, libidn2, libunistring and
   > itself.
   >
   > **Why the others are out.** Pillow exposes no FreeType transform, so it cannot scale a
   > glyph anisotropically and its only route resamples the text layer — forbidden by piece 3.
   > The emitter-plus-CLI candidate measured with `hmtx` advances and painted with rustybuzz;
   > those disagreed by up to 5.77% on kerning-heavy strings. **Corrected observation:** on
   > these five cases candidate B's painted text nonetheless stayed inside its box, so the
   > divergence is a measured hazard rather than an overflow observed here.
   >
   > **What C does not settle.** It resolves the *renderer's* shell and closure question only.
   > The Python Brother worker's packaging blocker — nixpkgs' CPython referencing bash from
   > `subprocess.py`, found by ADR-0019 gate 1 — is a separate decision on a separate service
   > and is untouched by this result.
   >
   > **Still required before selection**, and ADR-0021 prerequisite 1 stays open until they
   > support it: a font with real kerning pairs, right-to-left shaping, and render-time and
   > resource measurements against piece 8's bounds.

4. **Which limits and retention periods apply?**

   > **Response (maintainer, 2026-09-07):** Keep print images until the label is retired so
   > exact reprints remain possible. Retired uid and historical identity remain permanent;
   > images then become eligible for reference-aware cleanup. Unpromoted previews expire after
   > 24 hours. Idempotency lifetime and byte, pixel, length and execution limits remain open and
   > require representative measurements.

5. **Where does rendering run?**

   > **Response (maintainer, 2026-09-07):** A separate renderer service that exists only when it
   > has work, doing one job per invocation and exiting. It scales to zero. The dispatcher or
   > orchestration mechanism is not selected; it must launch work from durable requests.

   > **Note, 2026-09-07:** whichever mechanism is chosen, its manifest must be a kind
   > `.devtools/ci/check_deploy_manifest.py` examines — see piece 8.

6. **What record changes authorize this scope?**

   > **Response (maintainer, 2026-09-07):** Proceed with document reconciliation. ADR-0019
   > remains Proposed and acceptance remains separate. Review of accepted ADR-0011 found that
   > template ownership and reprint semantics need a superseding decision. That record is not
   > accepted or drafted by this reconciliation; its acceptance is an implementation gate,
   > alongside ADR-0019's existing gates.

   > **Answered 2026-09-07 by [ADR-0021](../adr/0021-label-templates-are-application-data.md)**,
   > which supersedes three boundaries of ADR-0011 — template ownership, reprint semantics and
   > the importer's mapping obligation — and is **Proposed**, with acceptance a separate
   > bookkeeping-only pull request.

7. **Does artifact storage follow `FILE_STORAGE`, or require the database backend?** A
   deployment running the filesystem backend would put artifacts on disk, which reintroduces
   the persistent volume [10](10-cold-start-statelessness.md) exists to remove. *Lean: require
   the database backend for artifacts and say so at boot, rather than silently honouring a
   setting that changes where household data lives.*

   > **Response (maintainer, 2026-09-07):** Require PostgreSQL-backed artifact storage. It
   > matches the agreed image-storage contract and preserves the deployment's lack of a
   > persistent file volume. Make the configuration check **conditional on the label subsystem
   > being enabled**: if its storage requirement is unmet, fail startup with a clear error.
   > Do not silently fall back to filesystem storage.

   > **Note, 2026-09-07:** carried into piece 5, with two things the answer implies rather than
   > states. The enabling flag is a new one rather than `FEATURE_FLAG_LABEL_PRINTER`, whose
   > nine call sites gate the webhook path this wave leaves alone. And requiring the database
   > backend excludes `demo` and `prerelease` mode transitively, since `checkFileStorage()`
   > already refuses `FILE_STORAGE=database` there — the error should say that directly.

8. **Is the renderer credential a key type, a scope set, or a per-request grant?** ADR-0021
   question 1. It needs the authorization model piece 7 lands against, not a guess now.

## Effort

Large. Piece 1 and piece 2 are ordinary application work over a document format that has to be
specified carefully once. Piece 4 is the risk: a new service, a runtime chosen against a
contract rather than a preference, and a dispatch mechanism that must survive having nothing
running. Pieces 5 and 6 are small once the artifact contract is fixed. The designer is
front-loaded on the document format and cheap afterwards, which is the argument for specifying
the format before writing the editor rather than discovering it from what Fabric.js emits.
