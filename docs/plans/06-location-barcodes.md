# 06. Location barcodes

**Goal:** Machine readable codes on storage locations, so a future camera based inventory
system can tell *where* it is looking and keep stock by location current without anyone
typing anything.
**Depends on:** [25](25-label-infrastructure.md)'s **first usable release** — the point at
which a requested label physically prints — and [12](landed/12-frontend-shared-core.md), per the
README. Pairs naturally with [08](landed/08-nested-locations.md) but does not need it.
**Status:** draft for review, **narrowed 2026-09-04 by
[ADR-0011](../adr/0011-label-namespace.md)**, **scoped 2026-09-06 against
[25](25-label-infrastructure.md)**, and **unblocked but not advanced 2026-09-08** by the
acceptance of ADR-0019 and ADR-0021 — see the three sections immediately below before reading
the body.

## What ADR-0011 took, and what is left

[ADR-0011](../adr/0011-label-namespace.md) was accepted 2026-09-04 and reconciling this
plan was one of its two acceptance gates. It generalized to every labelled thing the
argument this plan wrote for locations — a printed label outlives the row id printed on
it — and in doing so it decided four of the questions below. Reconciliation was by
**narrowing**: this plan keeps its number and its file, its prose stays as written, and
what the record took from it is marked here and in place rather than deleted.

**Decided by ADR-0011, not here:**

- **The payload.** A label carries `vctl:<uid>` — 13 characters of uppercase Crockford
  base32 over a mapping table the database owns. Not `grcy:l:7`, and not the
  `grcy:l:{uuid}` of Q1's response either: no new Grocycode type is added, because the
  record makes Grocycode a read-only input symbology the fork parses forever and emits
  never.
- **Label stability.** The mapping table replaces this plan's `locations.code_uuid`
  column, and covers stock entries and products at the same time rather than locations
  alone.
- **The symbology.** QR for new labels, DataMatrix retained for reading legacy
  Grocycodes (ADR-0011 Q2). This plan's Q3 expectation of a new PHP QR dependency does
  not follow, because rendering happens outside this repository. **Where outside changed on
  2026-09-07**: ADR-0011 put it with the print drainer, and
  [ADR-0021](../adr/0021-label-templates-are-application-data.md) superseded that — the
  template document is Victual's, and a separate headless renderer, not the drainer, turns
  it into an artifact. Nothing changes for this plan's conclusion; the sentence would
  otherwise name a component that no longer does the job.
- **The print path.** Label creation enqueues a row; a separate worker claims it over an
  authenticated HTTP pull API, prints it and reports the outcome, which the application
  records. The worker holds no database credential and marks nothing done itself —
  [ADR-0019](../adr/0019-label-printers-are-master-data.md) decision item 2. The webhook
  this plan proposed to reuse is retired with it.

**Still this plan's, and owned by nobody else:**

- **Where the label goes and what it says.** Placement on a shelf, and whether the
  human-readable line carries the tree path once [08](landed/08-nested-locations.md) lands —
  Q5, which ADR-0011 explicitly leaves to the plans that consume labels.
- **The locations UI.** A print action on the locations list and form, mirroring
  products, over whatever [12](landed/12-frontend-shared-core.md) landed.
- **Interactive scanning — decided out of this plan, 2026-09-04.** The "current
  location" notion — scan the shelf, then scan items onto it — is a session concept that
  touches the stock forms rather than the locations pair, and it gets its own plan after
  [08](landed/08-nested-locations.md). This plan ships labels and the print action only.

Q2's machine-reporting endpoint left this plan before ADR-0011 did, and by its own
response: it is the subject of [ADR-0012](../adr/0012-observations-are-proposals.md),
**accepted 2026-09-04**, which decides exactly the observation-then-confirm shape that
response reached for. Nothing here waits on it either way, and nothing here may route
around it: a camera that reads one of this plan's labels and reports what it sees writes a
proposal, not stock.

## Where issue 79 stands after the two acceptances, 2026-09-08

**Nothing here is built, and [issue 79](https://github.com/datagen24/victual/issues/79) is not
closed by any of it.** What changed is that the work under it is now authorized. Recorded
because the difference between "unblocked" and "delivered" is exactly the kind of thing a
status table quietly loses.

[ADR-0019](../adr/0019-label-printers-are-master-data.md) and
[ADR-0021](../adr/0021-label-templates-are-application-data.md) were both **accepted
2026-09-07**, 0021 first because 0019's ownership model is the one 0021 decides. Five gates and
six prerequisites were met, including a physical two-colour label off the QL-820NWBc and a
reprint printed from retained bytes with the renderer removed from the machine. Every one of
those runs was a disposable spike: **no schema, no route and no UI exists.**

The chain to this issue, and where it now breaks:

| # | Gate | State |
|---|---|---|
| 1 | ADR-0019 and ADR-0021 accepted | **Met**, 2026-09-07 |
| 2 | [25](25-label-infrastructure.md) group A — identity, migration 0269 | unwritten |
| 3 | 25 group B — migration 0270, the job service, the worker routes, the admin surface | unwritten |
| 4 | [27](landed/27-label-templates-and-rendering.md) — the template document, the renderer, artifacts | unwritten |
| 5 | 25 group C — the worker repository, its image, the manifest, a physical print | unwritten |
| 6 | **This plan** — the print actions, the label's content, the `vctl:` resolve surface | unwritten |

Two things about that table are worth stating rather than leaving to be inferred. **27 is now
on the critical path**, which it was not when this plan was scoped on 2026-09-06: a job is not
claimable until a validated artifact is attached, and 27 owns the artifact. And **27 has no
tracking issue**, while 25 has [#93](https://github.com/datagen24/victual/issues/93) — so the
one plan that gates this one twice over is the one nothing is tracking.

What this plan owns is unchanged by the acceptances. The print actions, what the label says and
where it goes, and the stateless resolve surface are still its, and the sections below still
describe them.

## What plan 25 owns, and what "owned by nobody else" cost

The section above was written on 2026-09-04 and said the locations UI was owned by nobody
else. That was true of the UI and false of everything underneath it. ADR-0011 had decided the
payload, the stability mechanism, the symbology and the print path, and had scheduled none of
them; [22](22-medication-tracking.md)'s question 6 found the same gap and declined to close
it. So this plan was left holding a print action with nothing to print — the `labels` table,
the print job, the printer configuration and the worker that renders were all unowned.

**[25](25-label-infrastructure.md) owns them, as of 2026-09-06**, and this plan depends on
that plan's first usable release: the point at which a label requested in Victual physically
comes off the printer. The division is:

- **25:** opaque identities and the authorized resolution API, the transactional print job
  and its delivery semantics, printer configuration and print-job monitoring, and the worker
  repository that renders and prints.
- **06:** the locations print action on the list and the form, what the label says and where
  it goes, and — added 2026-09-07 — **the surface that resolves a scanned `vctl:` code to its
  location**. Everything in the bullets above stays this plan's.

### The scan surface, and why it is not the thing this plan deferred

A label nobody can scan back does not close [issue 79](https://github.com/datagen24/victual/issues/79),
whose third criterion is that a printed label scans to the correct location *for an authorized
user*. 25 owns the resolution API; nothing owned the place a person uses it, which is a
locations UI question and therefore this plan's.

**It is not the "current location" notion deferred on 2026-09-04, and the distinction is the
whole reason this can be added without reopening that decision.** What was deferred is a
*session* concept: scan the shelf, and subsequent scans of items are booked against it. That
touches the stock forms, it holds state between requests, and it still gets its own plan after
[08](landed/08-nested-locations.md).

What is added here is **stateless**: a `vctl:` code entered or scanned resolves to one
location and shows it. Nothing is remembered, no stock form changes, and no booking targets
it. If an implementation of this starts holding a selected location across requests, it has
crossed into the deferred plan and should stop.

Resolution stays authorized by the permission that reads a location, per 25 piece 1. An
authorized user sees three outcomes — resolved, retired with what the label was, and unknown.
An unauthorized one sees the unknown answer whether the uid exists or not, because a caller
who can tell "exists, not yours" from "no such uid" has been told the label exists.

What this plan may not do while the two are in flight is route around 25 to ship something
sooner. Location printing does not extend `VICTUAL_LABEL_PRINTER_WEBHOOK` and emits no
Grocycode, both of which ADR-0011 settled and neither of which a delivery deadline reopens.
The webhook survives wave 3b for the five entity types that already use it; that is a stage
on the way to the retirement ADR-0011 accepted, not a reprieve from it.

## The use case drives the design

This is not primarily "scan a shelf with your phone". The intent is a fixed or handheld
camera that reads a marker on a shelf, knows which location it is looking at, and reports
what it sees back into Victual. That changes three things a phone-first design would get
wrong, and they are worth deciding before any code:

1. **Labels are physical and long lived; database ids are not.** A printed label stuck to a
   shelf may outlive several restores, re-imports and migrations. `grcy:l:7` is only stable
   as long as that row keeps id 7.
2. **Optics matter.** A code read at distance and at an angle by a fixed camera has
   different requirements from one held 10 cm from a phone.
3. **The write path is the point.** Scanning to navigate a UI is incidental; an external
   system needs an API to say "location 7 now contains these things".

## Today

Victual already has an internal barcode format, `Grocycode` (`helpers/Grocycode.php`):

```php
public const PRODUCT = 'p';
public const BATTERY = 'b';
public const CHORE   = 'c';
public const RECIPE  = 'r';
public const MAGIC   = 'grcy';
```

producing codes like `grcy:p:42`, with `Validate()`, `GetType()`, `GetId()` and optional
extra data already implemented. Label printing exists for products, and
`GROCYCODE_TYPE` config selects Code128 or DataMatrix.

Locations are not one of the supported types.

## Proposed change

This is the smallest item on the roadmap because the mechanism already exists.

### Grocycode

**Superseded by [ADR-0011](../adr/0011-label-namespace.md) decision item 3:** no new
Grocycode type is added, and `grcy:l:` is not minted. The paragraph below is kept as the
reasoning that led to the record rather than as work to do.

Add `public const LOCATION = 'l';` and include it in whatever validation list constrains
the type character. Everything else — parsing, rendering, printing — is generic.

### Label stability

**Decided by [ADR-0011](../adr/0011-label-namespace.md):** option two below won the
argument and was then generalized past this plan — a `labels` table mapping opaque uids
to targets, rather than a `code_uuid` column on `locations`. The section stands as
written because it is where the case was first made.

`Grocycode` encodes the row id, so a location label reads `grcy:l:7`. That is fine while
the database is continuous, and wrong the first time ids shift — a restore from a seed, a
re-import, or a rebuild. Every printed label then points at the wrong shelf, silently.

Two options:

- **Accept id based codes.** Simplest, and ids are in practice stable for a database that
  is only ever migrated rather than rebuilt. `bin/victual-db-import` preserves ids exactly,
  so the PostgreSQL move does not break labels.
- **Add a stable opaque id.** `locations.code_uuid`, generated once, encoded in the label
  instead of the row id. Survives anything. `ramsey/uuid` is already a dependency and
  currently unused.

For a camera system that may run for years against labels printed once, the second is
cheap insurance. It only matters for entities that get physical labels, so it need not
apply to products. See Q1.

### Symbology

**Decided by [ADR-0011](../adr/0011-label-namespace.md)'s Q2:** QR for new labels,
DataMatrix kept for reading legacy Grocycodes. The dependency question below dissolves
with it — rendering belongs to the print drainer, so this repository needs no QR library.

`GROCYCODE_TYPE` currently offers `1D` (Code128) or `2D` (DataMatrix). DataMatrix is
designed for small marks read close up — good for a product label, less good for a shelf
marker read across a room at an angle. QR carries stronger error correction and is what
most camera pipelines expect.

Adding `QR` as a third `GROCYCODE_TYPE` value is a small change and probably the single
most useful thing here for the camera use case. Worth checking whether the bundled
`interficieis/php-barcode` can emit QR, or whether another dependency is needed.

### Scanning and the write path

Two separate concerns:

- **Interactive scanning** — the existing scan-input handler resolves a Grocycode and acts
  on it. A location code should preselect that location as the target for subsequent scans,
  so you scan the shelf then scan items onto it. That implies a small "current location"
  notion Victual does not have today.
- **Machine reporting** — an external system needs an endpoint that says "these products,
  these amounts, are at this location". Victual has `/stock/transfer` and inventory endpoints,
  which mutate one product at a time and assume a human decided. A camera system reporting
  observed contents is a different shape, and is closer to inventory reconciliation than to
  a transfer. See Q2 — this is the part that needs real design, and it may be better as its
  own plan once the camera side is more concrete.

### Label printing

**Half superseded.** The print *action* is still this plan's, and so is what the label
says — the location name, and its path once [08](landed/08-nested-locations.md) lands. What it
may not do is reuse the webhook: [ADR-0011](../adr/0011-label-namespace.md) decision item
4 makes printing an outbox row a drainer consumes, and retires
`VICTUAL_LABEL_PRINTER_WEBHOOK` with the tree's only outbound call.

Locations get a "print label" action, reusing the existing webhook/thermal printer paths.
The label wants the location name and, once [08](landed/08-nested-locations.md) lands, probably
its path rather than the bare name.

### API

Additive. If products expose a `grocycode` field, locations should expose the same in the
same shape. No existing response changes.

**Client impact, as written — and as ADR-0011 changed it.** The non-numeric-id hazard
below was real for `grcy:l:{uuid}` and is now moot: no location Grocycode is minted, so no
client meets a non-numeric id in a `grcy:` code. The hazard it is replaced by is smaller
and different — a scanner that only knows `grcy:` does not recognise `vctl:` at all, which
fails visibly rather than resolving to the wrong shelf. [17](17-ecosystem-clients.md)'s
coupling 4 carries it.

**Client impact: additive, and one thing a parser can choke on.** `/objects/locations`
gains a field, which is safe. The real item is Q1's answer: `grcy:l:{uuid}` puts a
**non-numeric id** in a Grocycode for the first time, so any client that parses grocycodes
with a numeric assumption breaks on locations. `docs/grocycode.md` has been corrected to
say the id is opaque rather than `[0-9]+`; a client written against the old wording is the
exposure, and this fork's own scanner input path is the first place to check.

### UI

A print action on the locations list and form, mirroring products.

## Open questions

1. **Id based or stable opaque codes?** I lean to adding `locations.code_uuid` given labels
   are printed once and expected to last. It is one column and one migration now, versus
   reprinting every label later. Note it only needs to apply to physically labelled
   entities.

   > **Response:** Yes, `locations.code_uuid` — and decide the encoding explicitly:
   > `grcy:l:{uuid}` with the uuid *as* the id (an indexed lookup, and a Grocycode
   > parser that accepts non-numeric ids) is cleaner than appending the uuid as
   > extra data while the row id stays authoritative. Labels carry only the stable
   > identifier.

   > **Superseded 2026-09-04 by [ADR-0011](../adr/0011-label-namespace.md).** The
   > response's principle survives intact — labels carry only the stable identifier —
   > and its mechanism does not. The stable identifier is a `vctl:<uid>` over a mapping
   > table, not a `code_uuid` column read through a Grocycode type that was never added.
   > Kept because it is the reasoning the record generalized.
2. **What shape should the machine reporting endpoint take?** The interesting one. A camera
   reporting "I see 3 of product X at location 7" is an *observation*, and Victual has no
   concept of one — it has authoritative stock that humans mutate. Options range from
   mapping observations onto the existing inventory endpoint (simple, but an incorrect
   observation silently corrupts stock) to recording observations separately and letting a
   human accept them (safer, more work, and a new concept). Worth deciding once you know
   what the camera side can actually report, and possibly worth splitting into its own plan.

   > **Response:** Agreed — out of scope here, its own plan once the camera exists.
   > When it comes, the observation-then-accept shape (a staging record a human
   > confirms) is the one that cannot silently corrupt stock; a camera writing
   > straight through the inventory endpoint is the trapdoor to avoid.

   > **Decided 2026-09-04 by [ADR-0012](../adr/0012-observations-are-proposals.md)**, which
   > is this response generalized past cameras to anything carrying a confidence value: a
   > `proposals` entity, creation as its own narrow grant, confirmation executing the
   > booking through the existing write paths, and a unique source-event id making
   > redelivery harmless. The record owns the question now; it did not become a plan, and it
   > is scheduled in no wave. Kept because it is the reasoning the record generalized.
3. **Add `QR` to `GROCYCODE_TYPE`?** I think yes, specifically for this use case. Needs a
   check that the bundled barcode library can produce it.

   > **Response:** Yes — and expect a new dependency: the bundled generation is
   > 1D/DataMatrix oriented, and a GD/SVG-capable QR library (e.g.
   > chillerlan/php-qrcode) is the usual PHP answer. Verify before assuming.

   > **Superseded 2026-09-04 by [ADR-0011](../adr/0011-label-namespace.md).** QR yes,
   > for the new namespace's labels; the new PHP dependency no. Rendering leaves this
   > repository with the drainer, so the library the response told us to verify is one
   > this repository never adds.
4. **Should other master data get codes at the same time?** `shopping_locations`,
   `quantity_units`, `product_groups` are the same one-line change. Cheap together,
   another round of work later.

   > **Response:** Just locations now. `quantity_units` and `product_groups` never
   > get physical labels; add `shopping_locations` only if a use appears.
5. **Does the label need the tree path** or just the name? Path is more useful physically
   but longer on a small label. Interacts with [08](landed/08-nested-locations.md).

   > **Response:** The human-readable text line shows the path once 08 lands; the
   > encoded payload stays the bare uuid. Never encode display strings into the
   > machine side.

## Effort

The Grocycode and printing part is small — half a day, plus a little for QR and the UUID
column. Q2 is unbounded until the camera side is specified, and should probably not be
scoped as part of this. Recommend shipping codes, printing and interactive scanning first,
so the physical labels exist and are stable, and treating the ingest API as separate work
once there is something real to ingest from.


## Executed

### Identity dependency and stateless scan surface, 2026-09-08

[PR 107](https://github.com/datagen24/victual/pull/107) delivered plan 25 group A:
migration 0269, opaque identity issuance, authorized resolution, retirement snapshots and
atomic import protection. The earlier gate table is a snapshot from before that merge;
its group A row is now complete. Its API distinguishes live, retired and unknown labels
for a reader with `STOCK_VIEW`; callers without that permission receive unknown.

The location list now links to `/locationlabels`, a stateless scan-and-show page over that
API. The page requires `STOCK_VIEW`, like the locations list; the API independently returns
unknown to callers without that grant. Keyboard scanners submit with Enter; the existing
camera component can supply a code.

Live labels display the location name, retired labels display the retained former name, and
unknown or unauthorized labels display the same unknown result. Names are rendered as text.
Failed requests have a retryable error; an older response cannot replace a newer scan. Editing
the input clears the result. This page neither persists a selected location nor changes any
booking form. It does not issue labels.

This implements the scan surface ahead of physical delivery, using group A's available API.
It does not satisfy the physical scan-back acceptance check: that still requires a label
produced by the production print path. No existing API response changes.

The next implementation boundary is [issue 93](https://github.com/datagen24/victual/issues/93):
plan 25 group B's atomic print jobs, printer configuration and worker API. Plan 27 must
supply validated artifacts before jobs can be claimed; group C supplies delivery and the
physical printer verification. After those dependencies, this plan adds the list/form print
actions, the location-name label content and placement, and verifies request → physical
print → authorized scan plus visible failure and explicitly authorized reprint. The
encoded payload remains `vctl:<uid>`; tree paths await plan 08. No webhook fallback or
placeholder print action is added.

Browser regression coverage is `.devtools/frontend/location-labels.js`; the identity and
permission boundary remains covered by `.devtools/labels/identity-tests.php`.

Validation on 2026-09-08 against `codex/gpt-6_location-scan-79`, based on merged
PR 107: the browser probe passed, as did real PostgreSQL live/retired scans using disposable
fixtures with HTML in their names, PHP syntax checks and the strict documentation build.
Run the probe with `node .devtools/frontend/location-labels.js --url <disposable-app-url>`;
the `frontend-security` CI job runs it automatically. Camera event handling was exercised;
physical camera decoding and production printed-label acceptance remain unverified.

### Print actions, 2026-09-08

The print action is on the locations list and on the location form
([PR 113](https://github.com/datagen24/victual/pull/113), commit `d954838`), over plan 25's
job path and plan 27's artifact path rather than a placeholder. It reads the import epoch
immediately before each request rather than rendering it into the page, so a page left open
across an import fails loudly. It carries one idempotency key per intended action, kept
across a retry and spent when the action happens, so a double-click returns the first job
and a second deliberate print gets a key of its own.

It is gated on `FEATURE_FLAG_LABELS`, on `MASTER_DATA_EDIT` plus `STOCK_VIEW`, and on a
printer being configured. It extends neither the webhook nor Grocycode and encodes only
`vctl:<uid>`; the five entity types that already print keep the webhook, which is ADR-0019
item 7 step 1 and only step 1.

### Physical acceptance, 2026-09-09

[Issue 79](https://github.com/datagen24/victual/issues/79) closed on this evidence, against
the QL-820NWBc at `10.130.30.94` with DK-22251 tape. All four closing conditions were met. A
label was requested from the locations list (uid `EPRWM5YJFEX3N`, job queued
`awaiting_artifact`) and printed over IPP, with the device reporting `job-state = 9` and
`job-impressions-completed = 1`. An authorized user then scanned it back to `Pantry top
shelf`.

The failure-and-reprint cycle ran end to end. The printer's address was pointed at a closed
port, the attempt failed visibly with its error, and the next claim was offered nothing. A
person authorized a second attempt naming the first, and the label printed when the address
was restored. Plan 25's Executed section carries the full record, including the two worker
defects the first attempt found.

What this plan still owed at that point: question 5's tree path on the human-readable line,
now that [08](landed/08-nested-locations.md) has landed ([issue 137](https://github.com/datagen24/victual/issues/137)),
and the placement convention. Interactive current-location scanning remains deferred to a plan
of its own. The worker's deployment under K3S is plan 25's verification 12 and
[issue 93](https://github.com/datagen24/victual/issues/93)'s, not this plan's.

### Question 5, the tree path, 2026-09-15

[Issue 137](https://github.com/datagen24/victual/issues/137) closed the print half of question 5:
the human-readable line shows the location's path, from `locations_resolved`
(migrations/0273.pgsql.sql, plan 08), rather than the bare name. The placement convention noted
above remains open and is not code.

**Not the webhook.** The dispatch that raised this issue described locations as still printing
through `VICTUAL_LABEL_PRINTER_WEBHOOK`, carried over from this plan's own body above. That
was true when the body was written and is no longer true of the tree.

PR 113 (2026-09-08) already moved location printing onto plan 25/27's job path —
`FieldCatalogue`, `LabelCaptureService`, `LabelOperationsService` — ahead of this issue. The
webhook survives only for the five `*/printlabel` routes ADR-0019 item 7 step 1 names:
products and stock entries (`StockApiController`), recipes, chores and batteries. This work
extends the job path, not the webhook, and mints no new Grocycode or `vctl:` mechanism — both
stay exactly as ADR-0011 and 25/27 left them.

**Reused plan 08's own path helper**, per its own precedent for plan 30: `StockService` never
gained a *third* `Get…WithPaths()`. Both new call sites read `locations_resolved` directly, the
same view `GetLocationsWithPaths()` and `GetLocationAncestorIds()` already read. One wants a
single row's path (the print capture) and the other reads it inline off `labels`/`locations`
in one query (the scan resolve). Reusing the shared full-tree PHP helper would have meant
walking its whole-table pre-order result down to one row instead.

**`FieldCatalogue::For('location')` gained `location.path`.** Its `column` is `path`, but its
value comes from a new `select` key: a correlated subquery against `locations_resolved`'s self
row (`ancestor_location_id = descendant_location_id = locations.id`). The value is not a
stored column the way `name`, `description` and `id` are.

`LabelCaptureService::Capture()` now builds its `SELECT` list keyed by column name rather than
as a plain deduplicated list. It substitutes a definition's `select` expression when one is
present, and its bare `column` otherwise; every existing field is unaffected.

`null => 'error'` on the new field means a location with no self row in `locations_resolved`.
That state is unreachable through the app — the depth guard refuses nesting that deep before
insert — but possible from data older than migration 0273, or from a restore that bypassed
its triggers. The capture refuses rather than printing a blank line, which is the "renderer
refuses rather than resamples" the issue asks for, enforced earlier than the renderer: at
capture time, in the same transaction that would have created the print job.

`max_length` is 750: `hierarchy_depth_limit()`'s 6 nodes at a name comfortably longer than
`location.name`'s own 120-character cap, plus " / " separators. Existing templates that only
draw `location.name` are unaffected. This is one new optional field, not a default added to
`LabelOperationsService::FieldsOf()`, which still captures only the name plus whatever a
document's own elements reference.

**`/locationlabels` shows the path for a live label, the name for a retired one — unchanged.**
`LabelIdentityService::Resolve()` gained the same `locations_resolved` self-row join on its
existing query, and returns `path` inside `target` for a `resolved` result.

It falls back to the bare name if a self row is somehow missing, since this is a read-only
display rather than a print, and a stale label deserves *something* rather than a failed
lookup. That is a real difference from the field catalogue's refusal, and one worth being
honest about: it is defense in depth that was not exercised against real data, since the app
cannot create a location that deep.

The path here is read live on every scan, not pinned the way a label's `captured_fields` are.
A location renamed or re-parented after printing shows its current path immediately, which is
correct for a scan and would be wrong for a reprint. A `retired` result is untouched: its
`retirement_snapshot` carries only the name it always did, per the issue's own instruction
that "the snapshot is what it was at retirement". No path is added there, and none should be,
since migration 0269's retirement trigger fixes the snapshot at delete time and a path
computed later would not be that.

**Verification, 2026-09-15, against PostgreSQL 16.13 in this session's own sandbox** (started
the local cluster and ran everything directly). `php .devtools/labels/artifact-tests.php` ran
53 assertions, extended with a real nested-location capture — `Basement / Shelf` — and a check
that `TemplateDocument::Validate()` accepts a `location.path` text element. `php
.devtools/labels/identity-tests.php` ran 10047 assertions, one new: a live resolution's `path`
matches its `name` for a root location. `registry-tests.php` (23), `print-job-tests.php` (36),
`worker-api-tests.php` (25) and `canonical-json-tests.php` were unaffected by the widened
fixture. `php .devtools/pgsql/check-migrations.php` ran clean — no migration was added, since
this is application-level, reusing 0273's view.

`node .devtools/frontend/location-labels.js` extended with a `nested` case asserting a
four-level path renders, run against a live demo instance booted per
`.agents/skills/run-app/SKILL.md` (migrated to 279, PHP 8.4 with the documented
`REQUIRED_PHP_VERSION` override, reverted before anything was committed) — passed.

Beyond the test suites, a disposable script ran the real capture and resolve paths directly
against that demo database's actual schema and triggers rather than a fixture. It created
`Pantry / Top shelf (verify)` through plain SQL, so migration 0273's real guard triggers, not
a test double, accepted it. It then captured `location.path` through `LabelCaptureService` and
read it back through `LabelIdentityService::Resolve()`, both returning `Pantry / Top shelf
(verify)`. Deleting the location retired the label, and the retirement snapshot carried the
name alone, matching the paragraph above. Output and the script are not committed; the
assertions above and the demo run are what they showed.

**Two test fixtures needed widening, and one could not be widened the same way — the same shape
plan 08's own Executed section names for `.devtools/labels/identity-tests.php`'s `locations`
stub.** `.devtools/labels/test-support.php`'s `fixture()` now loads migration `0273.pgsql.sql`
unmodified alongside the others it already loads, so `artifact-tests.php`'s capture tests run
against the real view and the real triggers. The stub's `locations.name` gained `UNIQUE` so
0273's `DROP CONSTRAINT locations_name_key` finds what it expects, matching the baseline's own
naming.

`identity-tests.php` could not take 0273 as-is: it builds `locations` by hand across several
stages, already adds `parent_location_id` partway through for its own HTTP-controller section,
and never declares the name-uniqueness constraint 0273 tries to drop. Loading it verbatim
would have failed on both counts.

Its fixture instead declares `parent_location_id` from the start and carries a copy of 0273's
`hierarchy_depth_limit()` function and `locations_resolved` view, not its triggers or its
constraint changes, which that file's concurrency scenarios do not exercise. That is added
early enough that `Resolve()`'s new join has something to read from the file's first assertion
onward. Whoever next widens `locations_resolved` has both copies to update, same as plan 08
found for the `/objects/locations` stub in the other labels test file.

**What was not verified.** No physical label printed and no artifact went through the real Rust
renderer — this sandbox has neither a paired printer nor the pinned renderer, the same gap prior
sessions recorded for `renderer-agreement-tests.php`. Issue 137's own verification line asks for
"a rendered preview of a nested location through the real renderer showing the path"; that step
is unmet here and needs an environment with the renderer available.
