# 25. Label infrastructure

**Goal:** Build the label machinery [ADR-0011](../adr/0011-label-namespace.md) decided and
nobody owned — stable opaque identities, a transactional print job with honest delivery
semantics, the minimum printer configuration to aim one, and a worker that renders and
prints — so that a label requested in Victual comes off the printer and scans back to the
thing it names.
**Depends on:** [12](12-frontend-shared-core.md) (landed), [18](18-mqtt-state-publication.md)'s
`outbox` (landed), [19](19-rbac.md) piece 1 (implemented), [20](20-container-infrastructure.md)
piece 1 (landed) and part of piece 4. Gated on
[ADR-0019](../adr/0019-label-printers-are-master-data.md), **accepted 2026-09-07** with all
five gates met, and on [ADR-0021](../adr/0021-label-templates-are-application-data.md),
accepted the same day and before it — see **Gates** below. **Both gates are cleared.**
**Status:** draft for review. Scheduled into wave 3b. Revised 2026-09-07 against ADR-0019,
which is more specific than this plan's first draft on several points and contradicts it on
one — see **What ADR-0019 settled**.
**Migrations:** 0269 and 0270, claimed in
[RESERVATIONS.md](../../migrations/RESERVATIONS.md).
**This plan answers [ADR-0019](../adr/0019-label-printers-are-master-data.md)'s open question
1** — which plan owns delivery. That record could not name one because none existed when it
was written.

## Why this plan exists

[ADR-0011](../adr/0011-label-namespace.md) was accepted 2026-09-04 and says so itself: "no
`labels` table exists, no print outbox exists, and the fork still emits Grocycodes through the
webhook … acceptance decides the namespace, not the schedule." Nothing was scheduled
afterwards. [22](22-medication-tracking.md)'s question 6 found the same gap from the other
side and declined to close it, for a reason worth repeating here: a medication plan that built
a general label system would have become that system's owner by accident.

[06](06-location-barcodes.md) is the first scheduled consumer, and it cannot ship a print
action without this. So this plan takes ownership explicitly rather than letting a consuming
plan absorb it, and 06 depends on this plan's first usable release.

The scope is the machinery every labelled thing needs. It is *not* the migration of what
already prints — see **Deferred, deliberately**.

## Today

- `helpers/Grocycode.php` mints `grcy:p:{id}` and friends over four types. ADR-0011 makes it
  read-only: parsed forever, emitted never, no fifth type.
- Printing is a webhook. Nine `Victual.FrontendHelpers.RunWebhook` call sites in
  `public/viewjs/` fire at `VICTUAL_LABEL_PRINTER_WEBHOOK`, fed by five `*/printlabel`
  endpoints that assemble a payload and, when `VICTUAL_LABEL_PRINTER_RUN_SERVER` is set,
  POST it server-side through `helpers/WebhookRunner.php`. A failed print prints nothing and
  records nothing.
- A generic outbox already exists. `migrations/0259.{pgsql,sqlite}.sql` created
  `outbox (id, event_type, payload, row_created_timestamp, delivered_at, dead_lettered_at,
  attempts, last_error)` and `services/Outbox/OutboxService.php` drains it. That migration's
  own comment states the rule this plan inherits: "consumers may multiply while contracts may
  not, so a second consumer attaches by reading its own `event_type` rather than by minting a
  table of its own."
- Configuration is `config.php` constants through `Setting()` (`helpers/extensions.php:250`)
  plus the per-user `user_settings` table. There is no instance-level settings table.
- There is no printer in the deployment. The pod under `deploy/podman/` serves three images;
  a QL-820NWBc sits on the network with nothing talking to it.

## What ADR-0019 settled

[ADR-0019](../adr/0019-label-printers-are-master-data.md) — *Label printers are master data,
and a separate worker pulls print jobs over an authenticated API* — reached the tree
2026-09-07 and answers three questions this plan's first draft left open or answered wrongly.

- **The transport is decided, and it is the pull API.** The worker holds no database
  credential and makes no database connection; it authenticates to Victual's HTTP API,
  advertises what it can drive, and pulls work over nine additive routes. This plan's first
  draft carried it as an open question with the pull API as a recommendation. It is no longer
  a recommendation and no longer this plan's to decide.
- **Configuration is a driver registry and a capability contract, not a fixed column set.**
  Nine tables rather than the one `label_printers` this plan first sketched. A printer's
  settings are validated against the schema its driver advertises, so a second driver family
  is a registration rather than a migration.
- **This plan's delivery policy was wrong and is replaced.** The first draft argued for
  automatic retry on the grounds that a duplicate label is cheaper than a silent gap. ADR-0019
  decision item 6 decides the opposite: **no automatic redispatch after a claimed attempt**.
  The argument that beat it is the crash-after-send case — a worker killed between writing
  bytes and posting its result is indistinguishable from one whose bytes never arrived, so
  redispatching resolves the ambiguity by guessing, and guesses in the direction that prints.
  What a person has and Victual does not is the ability to look at the printer. The corrected
  policy is in piece 2.

And one thing went the other way. Writing migration 0270 against the record found decision
item 5 requiring a job row — `attempts_authorized`, `current_attempt_id`, the job's outcome —
that none of the eight tables it named was, and that could not go on the shared `outbox`
without breaking the very rule the record relies on to reuse it. **That was fixed in ADR-0019
on 2026-09-07 rather than worked around here**, which is what a Proposed record is for: it now
names `print_jobs` and counts nine. Recorded in both places because a plan that quietly
compensates for a gap in a record leaves the next reader of the record with the gap.

## What ADR-0021 moved out, 2026-09-07

[ADR-0021](../adr/0021-label-templates-are-application-data.md) — **Proposed** — supersedes
three boundaries of accepted [ADR-0011](../adr/0011-label-namespace.md), and two of them change
this plan's scope. Template documents become application data rather than the worker's, and a
reprint becomes a new job over retained artifact bytes rather than "resetting a row". The
machinery that follows from those — the designer, the headless renderer, previews, and the
artifacts a reprint replays — is [27](27-label-templates-and-rendering.md)'s, written the same
week and scheduled alongside this plan.

**This plan keeps** identity, the print job and its attempts, printer and worker configuration,
the nine worker routes, and delivery. Three consequences inside it:

- **Piece 3's migration 0270 carries eight tables, not nine.** `label_templates` moves to 27
  and becomes Victual's template identity; workers advertise the **artifact and profile
  contract versions** they accept rather than the layouts they carry.
  **ADR-0019's decision items 1 through 5 were reconciled in place on 2026-09-07** and now say
  this — a Proposed record is edited rather than compensated around. Its claim precondition no
  longer matches a template version, which after ADR-0021 no worker advertises and which would
  therefore have offered no job to anyone. Two format-dependent details remain **outstanding
  acceptance work** on 0019, listed at the end of its item 3, and neither blocks the
  comparisons that settle them.
- **Piece 2's job gains a readiness condition.** A job exists while its render is pending and
  **is not claimable until a validated artifact is attached**, so claimability reads readiness
  and authorization rather than `delivered_at` alone — which this plan already said, for the
  different reason that an unresolved job is not delivered either.
- **This plan owns the import refusal**, because `labels` is its table. ADR-0021 decision
  item 3 withdraws ADR-0011's re-key obligation as unimplementable — no source
  `bin/victual-db-import` accepts can carry a label — and replaces it with an explicit policy:
  the import **refuses** a target holding live labels, `--force` included, enforced **inside
  the import transaction under a lock the issuance path also takes** rather than as a
  precheck, **plus a monotonic import epoch on the request** for the consecutive case the lock
  cannot see. Retired labels and their historical identity survive an import, so neither a label
  row nor its retirement snapshot may carry a foreign key into `TRUNCATE … CASCADE`'s path.
  Verification 4 below is superseded by that policy and is restated in this plan's terms when
  ADR-0021 is accepted.

**Both records are Proposed**, so nothing here is settled until each is accepted on its own
pull request, and ADR-0019's decision items 1 and 3 are reconciled before its acceptance.

## Gates

**Two gates, in order, and neither is a formality.**

**ADR-0021 is accepted first, and ADR-0019 cannot be accepted before it.** ADR-0019's
ownership model — templates as application data, a renderer that is not the worker — is the
model ADR-0021 decides, and it **contradicts still-Accepted
[ADR-0011](../adr/0011-label-namespace.md)**, whose decision item 4 assigns templates to the
drainer and whose Consequences say rendering and the label's appearance leave this repository.
Accepting 0019 while 0011 still says that would put two accepted records in contradiction and
leave the tree with no answer to "who owns a template". So the order is a dependency rather
than a preference: 0021 supersedes those boundaries of 0011, and only then does 0019's
reconciled text stand on an uncontradicted footing. Each still gets its own bookkeeping-only
acceptance pull request.

**ADR-0019 and ADR-0021 were both accepted on 2026-09-07, in that order — 0021 first**, each
on its own bookkeeping-only pull request. The gate this section carried is therefore cleared:
schema, routes and UI may now be written under this plan. Merging a record into the tree was
never what accepted it — the [lifecycle rule](../adr/README.md) is explicit that implementing a
proposal, citing it in a plan, or receiving no objections does not accept it — which is why
this plan waited for the two pull requests rather than for the two files to appear.

Nothing else about this gate changes. **The scope below is what the acceptances authorize**, and
decision item 7's step 1 is what 0019 authorizes: the five existing `/printlabel` endpoints do
not move and no existing response shape changes.

The record carries five acceptance prerequisites, each a **disposable spike** — throwaway code
on a scratch branch, kept only until it has answered its question. They are not this plan's
implementation and must not be grown into it:

1. The worker packages as an image on no base image, from a pinned revision through
   `nix/images/lib.nix`, passing `nix flake check` including `image-has-no-shell`, closure
   size recorded. `brother-ql-inventree` is not in nixpkgs, and a packaging failure would
   invalidate [20](20-container-infrastructure.md) piece 5's designation.
2. Claiming, fencing, pairing and crash-after-send behave as decision items 2, 5 and 6
   specify, against a fake device.
3. Rotation survives its failure modes, and stale traffic is not treated as theft.
4. The schema subset is fixed against a named validator and a named form renderer — the
   intersection they both support, established by trying the model/media case rather than by
   reading feature lists. There is **no JSON Schema validator in `composer.json`** today.
5. The capability contract version 1 expresses two real driver families on paper.

ADR-0021 carries six of its own, which [27](27-label-templates-and-rendering.md) runs; two of
them — the renderer comparison and the artifact-format comparison — also settle the two
details ADR-0019 still owes before *it* is accepted.

Gate 1 is the schedule risk among 0019's five and should run first. If ADR-0019 is rejected,
pieces 1 and 2's identity and queue work still stand on ADR-0011 and ADR-0021; pieces 3 and 4
are rewritten around whatever the rejection says. If **ADR-0021** is rejected, this plan
returns to nine tables and a worker that owns templates, and 27 does not exist.

## Proposed change

Five pieces, in **three dependency groups**. This plan's first draft called pieces 1 and 2
independently mergeable, which was wrong: piece 2's job service needs `print_jobs` and
`print_attempts`, and those tables are in piece 3's migration. Nothing is gained by pretending
the queue can land before the schema it writes to.

| Group | Pieces | Note |
|---|---|---|
| A | 1 | Identity. Migration 0269 and the uid/resolution work. Genuinely standalone — nothing else in this plan is needed to make a label uid exist and resolve |
| B | 2 + 3 | The job service and the schema it requires. **One group, merged together or in schema-then-service order within it.** Splitting them across merges leaves a service with no tables or tables with no writer |
| C | 4 | The worker and its deployment. Depends on B's routes existing |

Piece 5 is [06](06-location-barcodes.md)'s and is named here only so the seam is visible.

**None of A, B or C starts before ADR-0019 is accepted**, so the grouping is about merge order
inside the implementation rather than about what can begin now. The answer to "what can begin
now" is the acceptance spikes and nothing else.

### Piece 1 — identity

`labels`, migration 0269: `uid` unique, `kind`, `target_id`, `row_created_timestamp`,
`retired_at`. `kind` is general from the start — ADR-0011 names "at least stock entries,
products, and locations" — but only `location` is minted in wave 3b.

- **Generation.** 64 bits from a CSPRNG rendered as 13 uppercase Crockford base32 characters,
  alphabet `0123456789ABCDEFGHJKMNPQRSTVWXYZ`, no check symbol. The unique index is the
  collision stance: a generator that draws a duplicate fails its insert and draws again.
- **Canonicalization before lookup.** Case-insensitive, folding Crockford's decode aliases
  (`I`, `L` → `1`, `O` → `0`), so a uid read by eye or off a marginal scan still resolves.
- **Authorized resolution.** Resolving a uid is a read of the thing it names, so it is gated
  by the permission that reads that thing — `MASTER_DATA_EDIT` is not the right gate for a
  read, and [19](19-rbac.md) piece 1's six domain view permissions are the vocabulary to use.
  A caller who may not read locations does not learn from a scan that a location exists.
- **Distinctness is a property of the authorized answer, not of the endpoint.** ADR-0011 is
  explicit that a retired label seen in the world is a discrepancy signal, not an error to
  swallow, so **an authorized caller gets three outcomes and three responses**: resolved,
  retired-with-what-it-was, and unknown. Never a silent null, and never a 404 that conflates
  the last two.

  **An unauthorized caller gets one**, and it is the same answer an unknown uid gets. This
  plan's first draft asked for three distinguishable outcomes *and* for the unauthorized case
  to leak nothing, which are incompatible: a caller who can tell "exists, not yours" from
  "no such uid" has been told the label exists, which is the fact being withheld. The
  authorization check therefore runs **before** the lookup's outcome reaches the response, and
  the shape and timing of the two answers do not differ. `EntityReadPolicy::PERMISSIONS` is
  fail-closed already, which is the right default here for the same reason.
- **An import refuses a target holding live labels, atomically.** ADR-0011 decision item 5 put
  an obligation on `bin/victual-db-import` to re-key label targets with the rows it creates;
  **ADR-0021 decision item 3 withdrew that obligation as unimplementable** and this plan
  implements what replaced it, because `labels` is this plan's table. No source the importer
  accepts can carry a label — `DatabaseImporter::GetCommonTables()` intersects the target's
  tables with the SQLite source's, and every accepted source predates `labels` — so there was
  never anything to re-key, while `labels` survived a truncate that replaced `locations` and
  left uids naming different shelves.

  What is built instead: the importer **refuses** when the target holds live labels, `--force`
  included, naming the count. The check is taken **inside the import transaction, under a lock
  the label-issuance path also takes**, because a precheck races — it sees no live label,
  an issuance commits one, and the import replaces its target underneath. Retired labels and
  their historical identity **survive** an import that proceeds, which constrains the schema:
  neither a label row nor its retirement snapshot may carry a foreign key into
  `TRUNCATE … CASCADE`'s path.

  **The lock is necessary and not sufficient, which ADR-0021's prerequisite 3 spike found by
  running it.** The lock orders issuance against a *concurrent* import; it says nothing about a
  *consecutive* one. A request composed before an import and executed after it mints a label
  for whatever now holds that id — a row that is internally consistent and is not what anybody
  asked for. So **`locations` carries a monotonic import epoch, the print request carries the
  epoch it was composed at, and issuance refuses when the two no longer match**, naming both.
  One integer, one comparison, and the last window closes.

  None of this is asserted: the spike reproduced the precheck defect first — a uid resolving to
  the row that replaced the one it was minted for — and then showed each of the three cases
  behaving, which is what verification 4 now specifies.

### Piece 2 — the print job

A `label.print_requested` event on the existing `outbox`, enqueued inside the transaction that
creates the `labels` row, so a rollback takes the job with it.

- **Versioned payload.** `payload_version` from the first row written, as
  `OutboxService::PAYLOAD_VERSION` already does for stock events. A payload a version cannot
  read is dead-lettered rather than acknowledged — 0259's third state exists for exactly this
  and the reasoning is not repeated here.
- **A job pins; a claim resolves.** The payload pins the label uid, the captured text fields
  as they stood, `payload_version`, and `template_id` with an immutable version or digest. It
  names a `printer_id` and nothing else about the device. The claim response resolves that
  printer's row *now* — its typed columns, its validated `settings`, and the driver and schema
  version they were validated against. A job whose printer has been deleted or deactivated is
  dead-lettered saying so rather than handed out against a device that is gone.
- **A claim's preconditions live in the query that selects the job, not after it.** Found by
  the gate 2 spike on 2026-09-07, and worth stating because the wrong version passes every
  single-job test: an implementation that picks the lowest matching job and *then* checks
  authorization and liveness answers "no authorization" forever once one exhausted job sits at
  the head of the queue, and nothing behind it is ever offered. One blocked job starves the
  subsystem, which no part of [ADR-0019](../adr/0019-label-printers-are-master-data.md) says
  should happen. `SELECT … FOR UPDATE SKIP LOCKED` makes the fix free, since the row that is
  picked is one that already passed every precondition.
- **Claiming is authorized, leased and fenced.** `print_attempts` holds one row per claim
  under `UNIQUE (outbox_id, attempt_number)`. A claim locks the job row
  (`SELECT … FOR UPDATE SKIP LOCKED`), checks four preconditions and inserts the next attempt
  number. The lock serializes claimers; the unique constraint is what keeps the guarantee true
  independently of the lock being taken, so two concurrent claims cannot consume one
  authorization. A lease is renewable by heartbeat up to a maximum total execution time, past
  which the attempt is abandoned whatever the worker believes.
- **No automatic redispatch after a claimed attempt.** A failed attempt records its error; an
  expired one is uncertain; neither returns the job to the queue. A person authorizes another
  attempt, **naming the ended attempt they reviewed**, and one transaction verifies that it is
  still the job's current attempt, that it has ended, and that no authorization it already
  granted is unused. Those three checks are what stop a second label appearing from one job —
  and what make the action idempotent under a double-click.
- **Queued is not failed.** A job no worker has claimed stays queued indefinitely while its
  worker is offline and prints when the worker returns. That is precisely the failure ADR-0011
  fact 2 named and the outbox removes. The no-redispatch rule starts *at the claim*.
- **A print is four facts, not a boolean.** *Sent* — bytes reached the device without a
  transport error. *Reported complete* — the device itself said so. *Evidence recorded* —
  an observation about this attempt was stored; the name is deliberately not "verified",
  because evidence is attached rather than adjudicated and recording one settles nothing.
  *Uncertain* — the attempt ended with no terminal
  result, which is a resting state rather than a transient one. Sent is not printed: a device
  can accept bytes and then jam. Where a driver can report completion the outbox row is
  acknowledged on the report; where it cannot, on send — and the record says which happened.
- **Evidence is attached, never adjudicated.** `print_evidence` records one observation per
  row, each naming the attempt it is about, deduplicated on `(source, submission_id)`.
  Confidence never promotes uncertain to confirmed; a missing verification reprints nothing.
  Images are managed storage references, never URLs — sweep finding S14 is the tree's one
  instance of that pattern and it is a finding, not a precedent.
- **A late report is kept beside the lease outcome, and the monitor shows the combination.**
  ADR-0019 decision item 5, amended 2026-09-07: `print_attempts` carries `reported_outcome` and
  `reported_at` separately from the outcome the server concluded, because a worker delayed past
  its lease has its attempt reaped as `uncertain` while the label is finished and in the output
  slot. The row that matters to an operator is `uncertain` **plus** a later worker report of
  completion — that is where authorizing another attempt prints twice.
- **Failure is visible in the application, not only in a log.** Somewhere a person looks is
  piece 3. Note that `OutboxService`'s undelivered set is *not* this consumer's work queue: an
  unresolved job is neither delivered nor claimable, so backlog for `label.print_requested`
  reads authorization state rather than `delivered_at` alone.

### Piece 3 — configuration and monitoring, minimally

Gated on both acceptances. The bar is exactly the wave's bar and no higher: *select a
configured printer, request a print, inspect the outcome.*

- **Ten tables in migration 0270**, PostgreSQL-only, plain, no views and no triggers:
  `label_workers`, `label_printers`, `label_drivers`,
  `label_worker_capabilities`, `label_printer_status`, `print_jobs`, `print_attempts` and
  `print_evidence`, plus `label_worker_sessions` and `label_worker_credentials`. The latter
  two implement durable pairing sessions and pending rotations without widening `api_keys`.
  `print_jobs` is the one this plan found missing — see below.
  `label_templates` was the ninth and is **not here**: ADR-0021 makes it Victual's template
  identity, owned by [27](27-label-templates-and-rendering.md). That is still a large surface
  for one subsystem, and ADR-0019 says why it is the cost of keeping driver definitions
  immutable while what workers advertise changes underneath them.
- **A driver registry, not a column set.** A printer's `settings` document is validated
  against the schema its driver advertised at registration. A Brother QL wants a tape identity
  and a two-colour flag; a Zebra wants ZPL darkness and a tear-off offset. Freezing the union
  as columns makes the second driver family a migration and bends everyone into the first
  family's vocabulary.
- **A worker is a row, not a credential.** `label_workers` holds the identity;
  `label_printers.worker_id` references it; keys are issued against the row, so rotating or
  revoking a key does not change the identity and a printer's assignment survives it.
- **Reads are generic; writes are not.** All eight tables go into `ExposedEntity` for reading
  plus `ExposedEntityNoEdit` and `ExposedEntityNoDelete`, each with a `PERMISSION_ADMIN` row
  in `EntityReadPolicy::PERMISSIONS` — which is fail-closed and throws for an entity absent
  from it. Every write arrives through a worker route or a dedicated administration
  controller that validates the settings document against the registry. It cannot go through
  `GenericEntityApiController`, which has no per-entity validation hook and whose
  `BaseApiController::GetParsedAndFilteredRequestBody` explicitly skips arrays when
  sanitising, so a nested settings document would reach the database unexamined.
  [03](03-category-min-stock.md) already edited those enums in this wave; merge order matters.
- **Monitoring is a view of jobs and attempts**, not a dashboard: queued, claimed, sent,
  reported, failed with its error, uncertain, dead-lettered — and **uncertain-but-reported**,
  which is its own state rather than a shade of uncertain, and the one an operator must see
  before authorizing another attempt. Enough to answer "did my label
  print, and if not, why" without a database client — and to authorize the next attempt, which
  is an operator action rather than a timer.
- **What is deliberately not here:** the label designer, template editing, previews and
  artifact storage — all [27](27-label-templates-and-rendering.md)'s under ADR-0021 — and
  per-print printer selection, which nothing owns. A job still pins a template version; what
  changed is that the version it pins is one Victual published rather than one a worker
  registered. Configuration that is not a property of a printing device stays where it is —
  instance behaviour in `Setting()`, per-person preference in `user_settings`, appearance in
  the template document. Admission to `settings` is enforced, not argued: a driver declared
  the field or it cannot be stored.

### Piece 4 — the worker

A **separate repository** holding printer drivers and device transport, built from a pinned
revision by this repository's Nix flake, deployed to K3S, and verified against the QL-820NWBc
that is already on the network over TCP.

**It does not render.** ADR-0021 moves font shaping, layout, QR generation and rasterization
into a headless renderer that reads Victual's template document, and
[27](27-label-templates-and-rendering.md) owns it. What this piece's worker receives is a
validated artifact; what it does is verify that artifact against the printer's resolved
configuration and encode it for the device. The two may share a repository or a deployment,
and their contracts stay separate — a render may be retried automatically because it cannot
touch a printer, and no rendering retry becomes a second physical attempt.

**Rendering and delivery are separate invocations even when they share a binary.** One Rust
program renders a label and drives the device, which is a packaging convenience and must not
become a coupling: a render is retried automatically precisely because it cannot touch a
printer, and a delivery is never retried automatically at all. So the render invocation is
given **no printer access** — no address, no socket — and an automatically retried render can
therefore not print by construction rather than by discipline. Sharing code between them is
fine; sharing an invocation is not.

**The worker is Rust and carries no interpreter** (maintainer, 2026-09-07). The packaging gate
found that nixpkgs' CPython references bash — through `subprocess.py`, `python3-config` and
`ctypes/macholib/fetch_macholib` — so a Python worker ships an executable shell; removing the
reference means rebuilding the Python package set under an interpreter that breaks the test
suites of anything shelling out, cffi and six included. A Rust worker satisfies
[ADR-0013](../adr/0013-nix-built-container-images.md) with nothing removed: **62,644,200 bytes
over seven store paths, zero shell or interpreter references**, rendering and driving the device
in one binary.

`brother_ql-inventree` therefore becomes a **reference for constants rather than a dependency** —
90 bytes per row and 400 invalidate bytes from its `models.py`, 732/696/12/35 for label `62`
from its `labels.py`. What a QL actually needs is the raster command stream and a socket.

- **Why separate.** [20](20-container-infrastructure.md) piece 5 says the print drainer is
  "an image in this flake". That stays true and is not in tension with a separate repository:
  the flake owns the image, the pin and the deployment; the other repository owns the driver
  matrix and device transport. The MCP sidecar took the same shape
  by 02-Q1. Pinning by revision is what keeps "reproducible" true across the seam, and a
  revision bump is a `flake.lock` change reviewed like any other.
- **The worker holds no database credential and makes no database connection.** It
  authenticates with an API key of a new type — `API_KEY_TYPE_LABEL_WORKER`, alongside the two
  existing constants in `services/ApiKeyService.php` — over nine additive routes: pair,
  rotate, register, claim, heartbeat, sent, result, evidence, and printer status. Keys are
  already hashed with a `key_hint` since migration 0264 and already carry `key_type`, so this
  is one type added to `ApiKeyAuthenticator`'s accepted set for those routes and no new
  authentication machinery. Every route is authorized against the caller, not merely
  authenticated.
- **Two configuration modes.** *Declared* — in the cluster, credential injected from a Secret,
  no durable state, no rotation. *Paired* — anywhere that can reach Victual, single-use
  pairing material exchanged once at first start, credential rotating on a short clock inside
  a pairing session on a long absolute clock. The session, not the rotation, is what bounds a
  stolen credential. Wave 3b needs only the declared mode; the paired mode is what the USB
  case will want and its rules are decided rather than built.
- **Seed material.** The prototype at `grocy-label-printer-brother` documents the device half —
  the raster and transport path — and is read for its constants rather than ported. Its Flask `/print` route is the
  webhook ADR-0011 retires and does not survive the port. Its **imaging** code — layout,
  endless versus die-cut, 2-colour, short-date highlighting — is seed material for
  [27](27-label-templates-and-rendering.md)'s renderer rather than for this worker, and the
  renderer comparison run on 2026-09-07 found two defects in it that a port must not inherit:
  `getbbox()` raises on multi-line text under a libraqm-enabled Pillow, and Pillow cannot
  scale a glyph anisotropically at all, which a 300 × 600 device requires.
- **[Issue #90](https://github.com/datagen24/victual/issues/90) is carried into the worker and
  closed there**, because it is a device-geometry defect rather than a layout one. The
  prototype hands `brother_ql` an image authored against `dots_total`
  while the library compares against `dots_printable`, so every endless print is silently
  resampled; with `dpi_600` set — its default — a 900-pixel label is resized twice and comes
  out 1711 pixels long. The fix is to author at `dots_printable[0]` (or twice it at 600 dpi),
  pass `rotate` explicitly so there is one rotation authority, and assert zero resize calls
  across `convert()` in a test. The rotation *sign* is settled by a physical print, which is
  in this piece's verification and not optional.
- **QR only, and not this worker's to draw.** ADR-0011 keeps DataMatrix for *reading* legacy
  Grocycodes; nothing in wave 3b emits one. That removes `treepoem` and Ghostscript from the
  renderer's image, which is a large closure difference — and it is the renderer's image
  rather than this worker's, since under ADR-0021 the worker is handed an artifact and draws
  nothing.
- **The worker is unprivileged and has its own identity**, per
  [ADR-0010](../adr/0010-workload-standard.md) rule 3 — a typed API key granted and revoked
  independently of general API keys. This plan's first draft said that also closed
  [20](20-container-infrastructure.md)'s verification check 8. It does not: check 8 is
  `victual-app` running with a database role that has no DDL rights, and ADR-0019's transport
  means the worker holds no database credential at all. Check 8 stays open and stays plan 20's.

### Piece 5 — the consumer

[06](06-location-barcodes.md) owns the locations print action on the list and the form, what
the label says, and the surface that resolves a scanned `vctl:` code to its location. Named
here so the seam is explicit: 06 depends on this plan's first usable release, meaning pieces 1
through 4 delivered far enough that a requested label physically prints.

**This plan owns the resolution API; 06 owns the place a person uses it.** That split was left
implicit until 2026-09-07 and the consequence was a gap — a label nobody could scan back,
against a closing criterion that requires exactly that. 06 also draws the line that keeps its
new surface stateless, and so distinct from the "current location" session concept it deferred
on 2026-09-04.

The human-readable line carries the location **name** in wave 3b.
[08](08-nested-locations.md) adds the tree path later, and 06's Q5 response is unchanged by
anything here: the encoded payload stays the bare uid, and display strings are never encoded
into the machine side.

## Deferred, deliberately

**Existing printing does not move in wave 3b.** Products, stock entries, chores, batteries and
recipes keep the webhook, and `VICTUAL_LABEL_PRINTER_WEBHOOK` is not deleted. That is a
delivery stage, not a change of destination.
[ADR-0019](../adr/0019-label-printers-are-master-data.md) decision item 7 sequences it in
three steps, of which **this plan is step 1 and only step 1**: location labels are purely
additive, because locations have no `/printlabel` endpoint today, so **no existing response
changes**.

Step 2 migrates the five existing endpoints and is where the wire changes. It has a
prerequisite this plan does not discharge: ADR-0019 establishes that no
no-change option exists — ADR-0011 already forbids `/printlabel` emitting `grcy:`, and
returning `vctl:<uid>` under the key `grocycode` would keep the key while changing its meaning,
so a client rendering that value itself would print a DataMatrix of a `vctl:` payload and
produce a physical artifact in the wrong symbology. Since this is a fork-initiated redesign
rather than the engine disagreement
[ADR-0005](../adr/0005-wire-contract-is-the-invariant.md)'s exceptions cover, **it belongs in
a record of its own**, and writing that record is step 2's gate. Step 3 deletes the webhook,
`WebhookRunner`'s last caller and the four `SystemApiController::EXPOSED_SETTINGS` entries,
after a check of the Home Assistant integration's use of `/system/config`.

The two paths do not interact while both exist: the old one prints `grcy:` through the webhook
to whatever renders it today, the new one prints `vctl:` through the worker. So the worker
never renders DataMatrix and never needs `treepoem` or Ghostscript, and `grcy:` emission stops
entity by entity rather than on a flag day.

Two rules hold during coexistence, and they are what stop a temporary stage becoming a
permanent second system:

- **New location printing does not extend the webhook.** No new `RunWebhook` call site, no new
  `VICTUAL_LABEL_PRINTER_*` reader.
- **New location printing emits no Grocycode.** No fifth type, no `grcy:l:`, ever.

Also outside wave 3b, and none of them a prerequisite for anything above: USB-attached
printers, printer drivers beyond the QL-820NWBc, camera verification of a printed label,
interactive current-location scanning (06 decided it out on 2026-09-04; it gets its own plan
after 08), and a label designer. The worker protocol stays capable of reporting different
delivery evidence so that adding any of them later is an implementation rather than a
protocol change.

## Client impact

Additive in wave 3b. No existing response shape changes, because no existing print path moves.
A scanner that only knows `grcy:` does not recognise `vctl:` at all, which fails visibly
rather than resolving to the wrong shelf — carried as coupling 4 in
[17](17-ecosystem-clients.md). ADR-0011's acceptance checked that catalogue for clients that
*generate* Grocycodes and found none, so the print-time blast radius is zero by that check.

## Security

This plan adds **no application outbound capability**, and the claim is exactly that — not
that the application ends with fewer. The webhook is still there when this wave finishes, and
it is removed by ADR-0019 item 7's step 3, which is deferred. Claiming a reduction now would
be claiming credit for work this plan explicitly does not do.

What the plan does add is a printer address configured in the database, which is a
user-configurable outbound destination and exactly what the security posture in
[AGENTS.md](../../AGENTS.md) warns against. It lives in the worker, and the pull transport is
what keeps it there: **Victual is the server and never the client.** The application never
connects to a printer and never connects to the worker; it writes rows and answers requests.
So the tree's outbound surface at the end of this wave is the webhook it already had, and no
second one.

Two consequences worth naming rather than leaving implicit. Evidence images are stored through
the existing file storage under a new `FileGroups` value and referenced by identifier —
Victual does not fetch an address a submitter supplies, and sweep finding S14 is the tree's one
instance of that pattern rather than a precedent for it. And a worker credential is a typed API
key granted and revoked independently of general API keys, so a compromised worker key reaches
nine routes rather than the API.

This needs a note in the [security sweep](../security-sweep.md) recording where the outbound
surface now lives, rather than a waiver.

## Verification

1. A uid generated 10,000 times is 13 characters, drawn only from the Crockford alphabet, and
   never repeats; a forced duplicate insert fails on the unique index and the generator
   recovers.
2. Canonicalization resolves `vctl:` payloads containing `I`, `L`, `O` and lowercase to the
   same label as the canonical form.
3. **For an authorized caller**, a live uid, a retired uid and an unknown uid produce three
   distinguishable responses. **For an unauthorized caller**, an existing uid and an unknown
   uid are indistinguishable — same status, same body, and no timing difference that separates
   a lookup that hit from one that missed.
4. `bin/victual-db-import` **refuses** a target holding live labels, `--force` included, and
   the refusal is taken inside the import transaction under a lock the issuance path also
   takes — issuance running concurrently with an import ends with the import refused or the
   label intact and correctly targeted, never with a label naming a replaced target. **A print
   request composed before an import and executed after it is refused, naming the epoch it was
   composed at and the current one**, and mints nothing. An import that proceeds leaves retired
   labels and their historical identity intact, the freed id can be labelled again, and the old
   uid still reports what it was rather than following the id to its new occupant. This
   replaces the re-key check the plan carried until 2026-09-07: ADR-0021 decision item 3
   withdrew that obligation as unimplementable, because no source the importer accepts can
   carry a label.

   The first of these must be written as a **reproduction of the defect first**: a test that
   only exercises the guard passes just as happily against a guard that never had the race,
   which is how a precheck came to look sufficient in the first place.
5. A print request and its `labels` row are one transaction: a forced rollback leaves neither.
6. **A job whose `(model, media, resolution, colour_mode)` is absent from the driver's
   `combinations` is refused at enqueue**, naming what is unsupported — not built, not sent, and
   not left to fail at the device — **and checked again against the printer's resolved
   configuration immediately before device I/O**, because media or driver version may have
   changed in between. The assertion is that an unsupported two-colour-at-600-dpi request
   results in **zero bytes reaching the device**, counted rather than assumed. Demonstrated the
   wrong way round on 2026-09-07: such a job was assembled and sent, and the QL-820NWBc refused
   it with "Communications Command Error" although the capability document already said that
   combination does not exist.
7. A failed or expired attempt leaves the job **unclaimable** until a person authorizes
   another, and authorization is refused while an attempt is still running and refused again
   while an authorization it already granted is unused.
   **A regression for starvation:** with an exhausted job ordered ahead of an eligible one, a
   claim returns the eligible job rather than a refusal. That is the defect the gate 2 spike
   found, and a suite that only ever holds one job cannot see it.
8. A worker killed between `bytes_sent_at` and its terminal result leaves a visible uncertain
   job and produces **no second print**. A worker that rotates its credential mid-attempt keeps
   that attempt: the superseded credential is refused without discarding anything, and the
   successor's report lands on the same attempt. A report arriving **after** the lease expired is
   recorded rather than refused, and the monitor shows the attempt as uncertain with a later
   worker report of completion — the state in which authorizing another attempt would print
   twice. Two concurrent claims against one authorization
   produce one attempt. A late result from a superseded attempt is recorded on its own row
   while completing nothing; a late heartbeat for it is refused.
9. A payload a version cannot read is dead-lettered with a reason, and does not block the rows
   queued behind it. A job whose validated artifact is not yet attached is **not claimable**;
   one whose target printer's worker does not accept the job's artifact and profile contract
   versions records `blocked` naming what is missing, and does not fall back to the latest.
10. A worker key is refused on a route it is not authorized for, and a revoked key is refused
   everywhere while the printer's assignment to its worker row survives the revocation.
11. `nix flake check` passes with the worker image added, and the image runs as a non-root uid
    with no shell, per `nix/checks.nix`. The worker's deploy manifest passes
    `.devtools/ci/check_deploy_manifest.py` — health probes and resource limits — which
    [ADR-0010](../adr/0010-workload-standard.md)'s acceptance made a binding condition of a
    workload shipping rather than a proposed one.
12. The worker deploys under K3S and prints to the QL-820NWBc over TCP.
13. **A physical location label is printed, and scanned back to the correct location by an
    authorized user.** This is the check the plan exists for and no earlier check substitutes
    for it.
    **The device half was demonstrated 2026-09-07**, ahead of the application half: a
    two-colour label printed on the QL-820NWBc from the Rust encoder over raw port 9100 and
    scanned back to its uid. What that does not yet cover is the path *through Victual* — a
    label requested, a job enqueued, an artifact attached, a worker claiming it — which is what
    this check is finally about.
14. Issue #90's assertion — zero resize calls across `convert()` for `62` and `62red` at both
    DPI settings — passes in the worker repository, and the rotation sign is confirmed by a
    printed label rather than by reading.
15. A failure is demonstrated end to end: printer unreachable, the job visible as failed with
    its error, a person authorizing a second attempt naming the first, and the label printing
    when the printer returns.

## Open questions

The transport question this plan's first draft carried is gone: ADR-0019 decided it, and the
job-state hole this plan found in that record was closed in it on 2026-09-07 rather than
worked around here. What is left is the values inside its boundaries, which it explicitly
assigns to the implementation plan, plus two questions of this plan's own. **All six were
answered in review on 2026-09-07**, and the responses are inline below.

1. **The credential lifetime and the session lifetime** (ADR-0019 question 3). The session
   length is the one that matters, since it and not rotation bounds a stolen credential, and
   it trades that bound against how often a seasonally used printer needs an admin to re-pair
   it. *Lean: pick numbers with the reasoning written down and revisit after the first real
   deployment; neither has evidence behind it yet, and wave 3b's declared worker does not
   rotate at all.*

   > **Response:** Defer production durations to paired-worker delivery. Wave 3b uses declared
   > credentials, which do not rotate and have no session clock, so neither number is exercised
   > by anything this wave ships and inventing operational defaults now would be guessing that
   > later reads as a decision. Exercise the *expiry boundaries* with short test values in the
   > rotation acceptance spike — the point there is that the clocks work, not what they are set
   > to.
2. **Retention durations** (ADR-0019 question 4). The policy shape is fixed; the numbers need
   a measured growth rate for `print_evidence` images that no deployment has. *Lean: state
   conservative durations and record that they are unmeasured.*

   > **Response:** No automatic pruning in wave 3b. Measure structured-history growth instead,
   > and set durations when there is a rate to set them against. Camera images are deferred, so
   > image retention — the largest rows and the reason the policy has several lifetimes — need
   > not gate this release at all. What the wave must still honour is the part of the policy
   > that is not a duration: unresolved attempts and uncertain outcomes are never removed, and
   > `labels` is outside print-history cleanup entirely, because a printed label outlives every
   > deployment.
3. **The per-type evidence fields** (ADR-0019 question 2). Which optional fields each
   `evidence_type` requires follows from what the first verifier can report, and wave 3b has
   no verifier. *Lean: implement the six required fields and one `evidence_type`, leaving the
   others to the plan that brings a verifier.*

   > **Response:** Start with a device-report evidence type, and only if the QL driver supplies
   > something meaningful to put in it. The six envelope fields on their own are not evidence —
   > they say an observation happened without saying what was observed — so the type ships with
   > a required type-specific payload or it does not ship. Camera payloads and image upload are
   > deferred with the verifier that would produce them.
4. **Does the worker keep an HTTP surface of its own?** A health probe is required by
   ADR-0010 rule 4. A label preview endpoint is useful for tuning layout. *Lean: both, with
   the rule that the application never calls the worker — a preview is something a person
   opens, not something a Victual page fetches, or the outbound surface returns by the back
   door.*

   > **Response:** Health only. A probe does not inherently require HTTP — choose whatever the
   > deployment can actually execute against an image with no shell, which is a real constraint
   > here and the reason `nix/runtime/webcheck.c` exists for the web tier. The preview endpoint
   > is declined for this wave: layout is inspected from saved render artifacts, which needs no
   > server and no access-control story, and a preview server would need one because it renders
   > label content on request.
   >
   > **Amended 2026-09-07:** this response originally noted that ADR-0010, where the probe
   > requirement comes from, was itself Proposed. **It was accepted on 2026-09-07**, so the
   > probe is a binding requirement rather than a proposed one — which does not change the
   > answer, since the answer was already "health only". What it does change is that
   > "declared: it exists in the deploy tree with health probes and resource limits, or it does
   > not exist" is now a condition of the worker shipping, and
   > `.devtools/ci/check_deploy_manifest.py` checks the manifest for it.
5. **Label retirement.** ADR-0011's question 4 leans to never deleting labels and setting
   `retired_at` when the target is consumed or removed. Locations are both soft-deletable
   (`active`) and hard-deletable through `objects/locations`. *Lean: hard delete retires the
   label; `active = 0` does not, because a disabled location is still that shelf.*

   > **Response:** Agreed — hard deletion retires the label, deactivation does not. Three
   > requirements come with that and are the actual work: retirement happens in the same
   > transaction as the deletion, so a deleted target can never leave a live label pointing at
   > nothing; enough historical identity is preserved to answer *what this label was* when a
   > retired uid is scanned, which is the discrepancy signal ADR-0011 question 4 wants; and a
   > reused target id must not revive an old label — PostgreSQL identity columns will hand out a
   > previously used id after an import or a reseed, and a retired row matched on
   > `(kind, target_id)` alone would silently attach to the new occupant of that id.
6. **Packaging risk.** `brother-ql-inventree` is not in nixpkgs and the image is built from
   `scratch`. This is also ADR-0019's acceptance gate 1. *Lean: run it first; it is the
   highest-risk item in the sequence and the one most likely to move the schedule.*

   > **Response:** Confirmed — run the packaging spike first. Scope it to what a physical QL
   > print actually needs rather than to `brother-ql-inventree` alone: the QR generator, the
   > fonts, and the imaging stack, all packaged into an image built on no base image. A spike
   > that packages the driver and discovers Pillow or the font path at deployment time has not
   > answered the question it was run to answer.

## Effort

Large, and front-loaded on decisions rather than code. Pieces 1 and 2 are small and mostly
determined — the identity rules are written in ADR-0011 and the outbox already exists. Piece 3
is small once its gate clears. Piece 4 is the bulk and the risk: a new repository, a Nix
package for a library that is not in nixpkgs, a K3S deployment, and a physical printer that
has to actually produce a correct label. The prototype's imaging code is the reason that is
weeks rather than months.


## Executed

### Group A — identity (2026-09-08, implementation branch)

Migration 0269 adds the opaque mapping, one-live-label-per-target index, retirement
snapshot, and import epoch. Only location identities are issued. The identity service
requires an enclosing transaction so group B can insert its job atomically with the uid;
there is no standalone issuance HTTP endpoint that could bypass that future job.

`GET /api/labels/resolve/{code}` returns resolved, retired with a historical name, or
unknown. `STOCK_VIEW` gates location resolution; denied callers return unknown without
querying labels. `GET /api/labels/locations/{locationId}/context` supplies the name and
import epoch for composing a request. Existing location and product-detail responses omit
the new column, and generic writes cannot set it.

The durable singleton `label_import_state` survives imports, including imports with no
locations. New location rows inherit its epoch through their database default. The importer
increments it within the same transaction as replacement, under the advisory lock shared
with issuance; the generation cannot come from a source file. Live labels refuse imports,
including `--force`. Issuance checks the request epoch before looking up a target and locks
the location row. A delete trigger retires its live label with the last name atomically;
deactivation does not retire it. Label history has no foreign key into the imported tables.

Verification on 2026-09-08, branch `codex/gpt-6_label-identity-79`, against PostgreSQL 16
in the existing Podman test container and PHP 8.4.25: the new
[identity regressions](../../.devtools/labels/identity-tests.php) reproduce the precheck
aliasing defect first, then cover both import/issuance lock orderings using the actual
importer, both deletion/issuance orderings, collision recovery, rollback, authorization,
and retained historical identity. The existing
[import CLI regressions](../../.devtools/pgsql/import-tests.php) exercise both frozen
SQLite fixtures and the live-label refusal through the command, with and without `--force`.
All 10,045 identity/API assertions and the import CLI checks passed. Migration numbering,
runtime SQL, route parameter coverage, and the strict documentation build also passed.
The identity check is included in the `suite` CI job.

Groups B and C, plan 27, and plan 06 remain unimplemented. This group does not close issue
79: no print job, artifact, worker, print action, or scan UI has shipped with it.


### Group B — jobs, configuration and worker API (2026-09-08)

Implementation branch: `claude/opus5_label-jobs-93`. Migration 0270 contains ten plain
PostgreSQL tables, using `TIMESTAMPTZ` for its clocks. The eight configuration/history
entities are read-only through generic APIs and require `ADMIN`; credential tables are
not exposed. Configuration and worker writes use dedicated routes.

A location print request requires `MASTER_DATA_EDIT` and commits the identity, outbox event
and print job together. `PrintJobPayload::PAYLOAD_VERSION` is 1 and belongs to the label
payload, rather than sharing the stock event's version constant. Unsupported settings and
combinations refuse with 422 and a field/code. The registered definitions are immutable;
changing a printer's schema version is a separate, revalidating action. Registration accepts
at most 64 driver definitions and at most 256 resolved schemas/combinations per definition.
The administrator pages provide scalar schema-generated settings controls, worker credential
management and the job monitor; no additional browser schema library is introduced.

The two credential tables retain pairing/session clocks, consumed credentials and the
pending rotation binding. A pairing session also retains `created_by_user_id`: the public
pair request must not choose the owner of the credential it receives. `StoredValueOf()` now
hashes every key type except the recoverable calendar key; `GetOrCreateApiKey()` likewise
returns recoverable keys only for calendar sharing. Worker authentication precedes browser
and development-mode bypasses and is route-scoped. Rotation derives successors through
ADR-0019's HMAC construction, binds exact replays, and commits reuse revocation as an outcome
rather than rolling it back as an exception. Neither rotation nor revocation changes printer
assignment. Imports exclude the new subsystem tables as input and revoke retained pairing
sessions because their creating-user ids belong to the replaced account set.

Three implementation details resolve the inputs' omissions: `connection_type` is an explicit
printer column; `combination_binding` maps capability axes to driver settings properties;
and credential state occupies the two separate tables described above. All services accept
the caller's PDO and keep transaction ownership with the caller. The outbox provides an
explicit-PDO transactional enqueue helper returning the inserted event id.

**Production claims are blocked until plan 27.** The maintainer chose this boundary during
implementation on 2026-09-08: no artifact columns or storage are added here, and the default
readiness check always refuses dispatch. Jobs are visible as `awaiting_artifact` (shown as
“Waiting for label rendering”). Only the regression suite overrides the protected readiness
seam. This corrects the implementation input's artifact-free claim example; it does not
weaken the accepted artifact prerequisite. Plan 27 must replace that seam with validated
artifact readiness and supply the manifest/byte-access response before production claims
can return work. The current empty-list response carries no fictitious artifact.

Claims filter exhausted jobs before selecting, use `SKIP LOCKED`, and retain the unique
`(outbox_id, attempt_number)` fence. Missing advertisements produce visible blocked attempts;
unreadable payloads are dead-lettered without starving the next eligible job. Expired
attempts are uncertain, never automatically retried. Late reports remain on their original
attempt; superseded reports cannot finish a replacement. Another attempt names the current
ended attempt and is idempotent. The monitor separates delivery state from authorization
state, orders uncertain-but-reported first, and shows the age of the latest printer report.
Evidence is deduplicated by authenticated source and submission id; image evidence and
verifier provisioning remain deferred.

Validation uses PHP 8.5.10 and PostgreSQL 16 in disposable Podman databases. Reproduction:
`.devtools/pgsql/run-tests.sh`, then the four PHP programs documented in
[the label test README](../../.devtools/labels/README.md). The authenticated HTTP walkthrough
is `.devtools/labels/http-tests.py`; the browser form/monitor probe is
`.devtools/frontend/label-printers.js` and runs in frontend-security CI. The isolated Nix
app build verified the updated Composer vendor hash. The identity suite passed 10,046 assertions, registry validation 23, print jobs 35, and
worker protocol 25. CI results belong to the implementing PR.

Verification 6's device half is **blocked on Group C**. Verification 9's artifact half is
**blocked on plan 27**. Neither is recorded as passed, and no physical printer was contacted.
Plan 25 verifications 11–15 remain open, as do issues 93 and 79. Plan 06's print actions and
physical failure/reprint acceptance follow artifacts and delivery.

### Group C — the worker, and the seam plan 27 closed (2026-09-08, branch `claude/opus5_label-templates-27`)

**The readiness seam is gone.** Group B shipped `PrintAttemptService::ArtifactReady()` returning
false for every job, because the artifact it named did not exist and the maintainer chose to
keep production claims blocked rather than open them against nothing. Plan 27's 0272 gives
`print_jobs` the column, its `ArtifactService` gives it something to point at, and the method is
now `artifact_id IS NOT NULL AND cancelled_at IS NULL`. No runtime setting turns it off and no
path claims a job whose bytes were not verified. Group B's suites no longer rely on a subclass
that answered "ready": a test wanting a claimable job produces an artifact the way production
does.

**Two repositories, pinned.**
[victual-label-renderer](https://github.com/datagen24/victual-label-renderer) at
`f05c432f7857f976e0223296f968d641fa401254` and
[victual-label-worker](https://github.com/datagen24/victual-label-worker) at
`da9258989f42988662c1f9ee7ca60ebcf376be4b`, both as `flake = false` inputs built here by
`rustPlatform`. The flake owns the image, the pin and the deployment; the other repositories own
the driver matrix and the device transport.

The worker is the QL encoder that physically printed on 2026-09-07, with its constants taken
from `brother_ql-inventree` 1.3 rather than invented, plus an IPP transport — which exists for
the reason ADR-0019 scopes `completion_evidence` to a triple: over raw 9100 the device answers
no status request, so `transport` is all that can honestly be reported, while over IPP the same
byte stream reaches `job-state = completed`.

**Verification 6's device half is met on the worker side and not on the device.** The
zero-bytes assertion is a unit test stated as "verify refuses and encode is never reached",
which is the stronger shape: a count of zero on a socket that was opened would still have made
the connection. **Verification 11 is met** — `nix flake check` gains
`label-images-have-no-shell` over both closures, and the deploy manifests pass
`.devtools/ci/check_deploy_manifest.py`, with the CronJob demonstrably examined rather than
skipped.

**Verifications 12 through 15 remain open**, and so does issue 93. The QL-820NWBc was powered
off on 2026-09-08; nothing has been deployed and no physical label has been printed through
this path. Issue 79 is unchanged by that: its definition of done is a label requested,
physically printed, and scanned back, and only the first of those three is demonstrable today.

Issue [#90](https://github.com/datagen24/victual/issues/90) is carried into the worker and is
**not closed**. Its no-resize property is designed out rather than fixed — there is no
resampling step, so a raster whose width is not the device's printable dot count is a refusal —
and that is asserted at three resolutions in the renderer and once in the worker. What remains
is its other half, the rotation sign, which the plan says is settled by a printed label rather
than by reading.

### Physical acceptance (2026-09-09, QL-820NWBc at 10.130.30.94)

**Verification 13 is met, and it is the check the plan exists for.** A location label was
requested in Victual, rendered by the pinned renderer, verified by `ArtifactService`, claimed
by the worker, printed on the QL-820NWBc, and **scanned back to the location it names**.
Delivery went over **IPP**, where the device answered `job-state = 9` with
`job-impressions-completed = 1` - `device_reported` evidence rather than `transport`.

**Verification 15 is met.** With the printer's address pointed at a closed port on the same
device, the attempt failed with `Connection refused`, the job was visible as `failed` carrying
that error, and the worker was offered nothing on the next claim. A person authorized another
attempt naming the ended one, the address was restored, and the label printed.

**Verification 8's crash-after-send half is met.** A worker that claimed, recorded
`bytes_sent_at` and stopped without posting a result left the job `uncertain` once its lease
passed, and the next claim was offered nothing - no second print.

**Verification 5's reprint half is met on hardware.** An exact reprint of a printed job
created no render request and delivered from the retained bytes with no renderer running; the
same reprint with the bytes collected was refused as `artifact_collected` and rendered nothing
in their place.

**Verification 14's geometry half is met.** The 696 x 354 px artifact measured right way round
at the size the profile fixes, which settles issue
[#90](https://github.com/datagen24/victual/issues/90)'s rotation sign by a printed label
rather than by reading.

**What the day cost, and what it bought.** The first attempt declared plain `62` while `62red`
was loaded - the device's own status page reports "62mm" without distinguishing two-colour
tape, which is the limitation ADR-0019 already records. The device latched into an error state,
and the two attempts after it could not have succeeded whatever they carried. Over raw 9100 the
worker reported *sent* for all three, because *sent* is the most that path can honestly report;
the IPP path is what turned a guess into a report. That is the argument for scoping
`completion_evidence` to the `(driver, connection_type, combination)` triple, made by a device
rather than on paper.

Two defects in the worker were found before anything printed, both fixed and both in its own
repository: two-colour was being read from the artifact's ink rather than from the loaded roll,
and `--dry-run` consumed an authorization while reporting nothing.

**Two-colour printed too, and through the whole path.** The first label was black-only on
`62red` tape: the stream carried two planes with the red one empty, so nothing red was laid
down and only the 2026-09-07 spike had ever put red on tape. A second template with a filled
red band and white text knocked out of it produced an artifact carrying 27,306 red pixels, and
it printed red. Red on a QL is a property of the **roll** rather than of an ink well - DK-22251
has a layer that develops red at a different temperature - which is why the resolved
combination and not the artifact's ink decides whether the two-plane stream is sent, and is a
defect this found in the worker before anything printed.

**The immutability rule caught a real change, and the recovery is the one the record
prescribes.** Correcting the capability document's provenance altered a definition that had
already been registered, and Victual refused the re-registration with `A registered driver
version is immutable`. The worker published `1.1`; the printer was moved to it by the explicit
revalidating action; and the job queued against `1.0` in between recorded a visible `blocked`
attempt naming what was missing, with provably zero bytes sent. That is ADR-0019 decision item
3's "a visible blocked outcome, not a silent pass-over", observed rather than asserted.

**Verification 12 is half met and stays open.** The worker printed to the QL-820NWBc over TCP,
which is the second half. The first half - deploying under K3S - did not happen: the maintainer
chose podman locally, and this machine's podman VM cannot open TCP to the printer at all (it
answers ICMP and refuses 9100 and 631), so the delivery that printed was the native build of
the pinned revision rather than the image. The images build, run, answer their health probe and
carry no shell; what has not been shown is one of them reaching a printer.

