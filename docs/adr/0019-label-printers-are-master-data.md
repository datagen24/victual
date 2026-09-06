# ADR-0019: Label printers are master data, and a separate worker pulls print jobs over an authenticated API

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-06.
- **Relationship:** supplies the configuration model, the transport and the ownership
  split that [ADR-0011](0011-label-namespace.md) decision item 4 left unspecified. 0011
  decided that printing becomes an outbox a drainer consumes and that the four
  `VICTUAL_LABEL_PRINTER_*` constants are retired; it did not say where the printer's
  configuration lives, how the worker reaches a job, or which repository the worker's
  source belongs to. This record answers those three.
- **Relies on** two properties of [ADR-0010](0010-workload-standard.md), which is
  **Proposed** — see *Reliance on ADR-0010* below.
- **Would affect:** [06](../plans/06-location-barcodes.md),
  [17](../plans/17-ecosystem-clients.md), [20](../plans/20-container-infrastructure.md),
  [22](../plans/22-medication-tracking.md).

## Context

**Printing is configured entirely by four constants, and configuration by constant is
wrong for a device.** `config-dist.php:226-229` declares `LABEL_PRINTER_WEBHOOK`,
`LABEL_PRINTER_RUN_SERVER`, `LABEL_PRINTER_PARAMS` and `LABEL_PRINTER_HOOK_JSON` through
`Setting()` (`helpers/extensions.php`), which resolves a `config.php` default against a
`settingoverrides/*.txt` file or a `VICTUAL_*` environment variable and then `define()`s
it for the request. Changing any of them means editing a file or an environment and
restarting the process — under [ADR-0013](0013-nix-built-container-images.md) that is a
manifest change and a pod restart. A printer's address moves when the router reassigns it.
The tape in it changes when a roll runs out. Neither is a deployment event.

**There is no instance-level settings table to put them in.** `Setting()` and the
`user_settings` table are the whole of configuration in this tree: one is a constant, the
other is per-user preference. A printer is neither. It is a thing the household owns,
exactly as a shopping location is, and `shopping_locations`
(`db/pgsql/baseline/01_tables.sql:308`) is the shape it should take —
`id`/`name`/`description`/`row_created_timestamp`/`active`, served through
`GenericEntityApiController` because it is named in the OpenAPI `ExposedEntity` enum, and
edited through `Victual.EntityList` / `Victual.EntityForm`
(`public/viewjs/shoppinglocations.js`).

**ADR-0011 moved the print path but left its configuration and its transport unowned.**
[ADR-0011](0011-label-namespace.md) was accepted 2026-09-04: label payloads are
`vctl:<uid>`, printing becomes an outbox a drainer consumes, rendering leaves this
repository, and the webhook is retired. Nothing of it is built.
[Plan 22](../plans/22-medication-tracking.md) question 6 declined to own the machinery —
its lean is that medication ships without labels rather than a medication plan quietly
becoming the label subsystem's owner — so the `labels` table, the print event and the
worker have no owning plan today.

**The outbox already exists, and it already states the rule this record must follow.**
`migrations/0259.{pgsql,sqlite}.sql` created a generic `outbox` table —
`event_type`/`payload`/`delivered_at`/`dead_lettered_at`/`attempts`/`last_error` — and its
comment is explicit that it "is deliberately not an InfluxDB table: the point of the
contract is that consumers may multiply while contracts may not, so a second consumer
attaches by reading its own `event_type` rather than by minting a table of its own."
`services/Outbox/OutboxService.php` implements that, with one event type
(`stock.transaction_booked`) and a `PAYLOAD_VERSION` consumers refuse to guess past. Its
existing consumer, `bin/victual-publish-state`, is a PHP command in this repository
sharing the application's `config.php` and its database credential. The label worker is
not that, and the differences are the subject of this record.

**Building an image here does not determine where its source lives.**
[Plan 20](../plans/20-container-infrastructure.md) piece 5 designates ADR-0011's print
drainer, plan 18's MQTT publisher and the MCP sidecar as images in this flake, taking uid,
labels, checks and manifest shape from `nix/images/lib.nix`. That settles who *builds* the
artifact and to what standard. It says nothing about who owns the source, and those are
separate questions: the flake can build a pinned revision of another repository as easily
as it builds this one.

**The deployment is a networked worker today and must not preclude a local one.** The
immediate target is a K3S worker reaching TCP-attached printers through firewall rules. A
worker attached to a printer over USB, on a machine that is not in the cluster and may not
be reachable from it, has to stay possible — that is the case the current webhook design
handles badly (`LABEL_PRINTER_RUN_SERVER=false` exists precisely because the Victual host
may not be able to reach the printer) and it is a constraint on the transport rather than
an afterthought.

## Decision (proposed)

### 1. Ownership: the worker's source is a separate repository

| Owned by this repository | Owned by the worker repository |
|---|---|
| The printer inventory (`label_printers`) and its CRUD and UI | The rasterizer and imaging code |
| The print job event and its payload contract | Label templates and their definitions |
| The claim/acknowledge API and its OpenAPI contract | Printer drivers and driver capability data |
| The attempt record: what was tried, what came back, what evidence exists | Device transport (TCP, USB, whatever a driver needs) |
| The flake input pinning the worker's revision, and the image built from it | The worker's own tests, including geometry assertions |

Victual owns **configuration and monitoring**. The worker owns **rendering and device
contact**. This preserves ADR-0011's consequence that rendering leaves this repository,
and it makes the reason structural rather than a convention: template semantics, driver
quirks and imaging bugs move on their own schedule, and none of them should be a reason to
cut a Victual release.

`flake.nix` gains the worker repository as a pinned input and builds its image from that
revision through `nix/images/lib.nix`, so the artifact carries the same uid, labels and
`nix flake check` assertions as the three existing images. A revision bump is a
`flake.lock` change reviewed like any other, per ADR-0013 decision item 8.

### 2. Transport: an authenticated pull API, not direct database access

**The worker holds no database credential and makes no database connection.** It
authenticates to Victual's HTTP API and pulls work. Four endpoints, all additive:

- `POST /api/labels/jobs/claim` — the worker asks for up to *n* jobs for the printers
  assigned to it. The response carries, per job, the label uid, the template name, the
  rendered text fields as captured at enqueue, **the printer's configuration resolved
  now**, an `attempt_id`, and a lease expiry.
- `POST /api/labels/attempts/{attempt_id}/sent` — bytes reached the device.
- `POST /api/labels/attempts/{attempt_id}/result` — terminal outcome, with the device's
  report or the error.
- `POST /api/labels/attempts/{attempt_id}/evidence` — optional, for camera verification.

**Worker identity is an API key of a new type**, `ApiKeyService::API_KEY_TYPE_LABEL_WORKER`,
alongside the two existing constants (`services/ApiKeyService.php:15-16`) and the `mcp`
type the [MCP interface spec](../mcp-interface-spec.md) proposes. Keys are already stored
as a SHA-256 hash with a `key_hint` (migration 0264), already carry `key_type` on the
`api_keys` table, and are already validated per route — so a label-worker key is granted
and revoked independently of general API keys, and `ApiKeyAuthenticator` gains one type in
its accepted set for these four routes only. No new authentication machinery.

**Assigned printers are a column, not a convention.** `label_printers.worker` names the
worker identity that serves that printer; the claim endpoint returns jobs only for
printers naming the caller. A null means any worker may take it, which is the
single-worker installation. This is what makes a remote USB worker and a cluster worker
coexist without either seeing the other's queue.

**Why pull rather than the alternatives**, since this is the item the deployment shape
decides:

- **Direct database access** would give the worker a PostgreSQL role, a route through the
  firewall to the database, and knowledge of the schema. A remote USB worker would then
  need database reachability from wherever it is, which is a worse thing to expose across
  a network boundary than an authenticated HTTP endpoint with a revocable, typed key. It
  also couples an independently released repository to Victual's migrations.
- **MQTT** is available — [plan 18](../plans/18-mqtt-state-publication.md) brings a broker
  and `php-mqtt/client` — and it is a reasonable fit for fan-out to many workers. It is
  the wrong fit for this: a print job is a unit of work claimed by exactly one worker with
  a lease and an acknowledgment, not a state fact published to whoever is listening, and
  QoS 1 gives redelivery without giving exclusivity. It would also make the broker a hard
  dependency of printing, and put household print jobs on a bus whose access is already
  noted in plan 18 as wider than Victual's own authentication.
- **Push from Victual to the worker** is what the webhook does now, and it is what ADR-0011
  retires. It requires Victual to hold an outbound destination and reach the worker's
  network, which is the case the USB worker cannot satisfy.

Pull inverts the direction: Victual is the server, never the client. A worker anywhere
that can reach Victual can print, and Victual needs to reach nothing.

### 3. `label_printers` is master data, with the device/template boundary drawn first

One row per physical device. It carries `id`, `name`, `description`,
`row_created_timestamp` and `active` as `shopping_locations` has them, plus:

- `driver` — the driver identity the worker resolves, e.g. `brother_ql`. **Not a model
  string in one library's namespace.** The driver names the family; the family decides how
  the rest of the row is interpreted.
- `model` — the device model within that driver's vocabulary.
- `connection` — interpreted by the driver: a TCP endpoint, a USB device path, a queue
  name. A single field, because "host and port" is already an assumption about one
  transport and the USB worker breaks it.
- `media` — the loaded tape or die-cut stock in the driver's vocabulary (`62`, `62red`).
- `dpi`, and the capability facts a template must respect: whether the loaded media is
  two-colour, and the printable geometry the driver reports.
- `worker` — the assigned worker identity, nullable.
- `is_default`.

**Fonts, per-element colour choices and the short-date threshold are not on this table.**
They are template configuration, and they belong to templates, which belong to the worker
repository. The boundary rule: a column belongs to `label_printers` if it describes what
the device *is or has loaded*, and to a template if it describes what a label *should look
like*. A printer's media being two-colour is a device fact; a template choosing to render
the due date in red is a template choice that is valid only where the device fact is true.
Freezing font and colour columns now would freeze them against one printer family before a
second driver exists to test the shape against.

**Reached the way every other master-data entity is.** `label_printers` is added to the
OpenAPI `ExposedEntity` enum and to `ExposedEntityEditRequiresAdmin`, so
`GenericEntityApiController` serves `/api/objects/label_printers` and requires
`PERMISSION_ADMIN` on write. It also gains a row in `EntityReadPolicy::PERMISSIONS`, which
is fail-closed — an entity absent from that map throws "Entity has no read policy" — and
that row is `PERMISSION_ADMIN` as well, because the row is a network address and no
ordinary application path reads it. The UI is a `Victual.EntityList` list page and a
`Victual.EntityForm` form, as `shoppinglocations` is. The migration is PostgreSQL-only;
the SQLite line is frozen at 0265 by [plan 24](../plans/24-sqlite-runtime-retirement.md).

**What stops this becoming a settings framework.** Nothing in the table is a key/value
pair. Every column is a typed property of a physical device, and the test for admitting a
new one is whether a driver reads it: if no driver does, it is not a printer column.
Configuration that is not a property of a device stays where it is — instance-wide
behaviour in `Setting()` constants, per-person preference in `user_settings`, appearance in
templates. This record creates one master-data entity and no mechanism.

### 4. The job names a printer; the configuration is resolved at claim time

The outbox payload carries the label uid, the template name, the rendered text fields as
they stood when the job was created, and a `printer_id` — not a connection, not a media
identity. The claim response resolves that printer's current row and returns it with the
job. A job whose printer has been deleted or deactivated is dead-lettered with
`last_error` saying so, rather than handed out against a device that is gone.

### 5. The outbox is reused; attempts are a separate evidence log

Event type `label.print_requested`, in the existing `outbox` table, under the existing
`payload_version` discipline. No second queue.

A `print_attempts` table records one row per claim: `attempt_id`, the outbox row it
belongs to, the worker identity, `claimed_at`, `lease_expires_at`, `bytes_sent_at`,
`device_reported_at`, outcome, error text, and a nullable evidence reference. **This is not
a second queue and does not multiply the contract 0259's comment protects.** The outbox
row remains the unit of work and is acknowledged by setting `delivered_at`; the attempt
rows are this consumer's own record of what it tried, which is the monitoring half of what
Victual owns. A claim is the insertion of an attempt row, so exclusivity is a database
constraint rather than a protocol promise, and an expired lease returns the job by making
the next claim legal.

### 6. Delivery semantics: four states, and the crash case is one of them

A print is not a boolean. Four distinct facts, recorded separately because conflating them
is how a missing label becomes invisible:

| State | Meaning | Recorded as |
|---|---|---|
| **Sent** | The worker wrote the job to the device without a transport error | `bytes_sent_at` |
| **Reported complete** | The device itself reported the job finished | `device_reported_at` plus outcome |
| **Verified** | Optional external evidence that a physical label exists | An evidence row referencing this attempt |
| **Uncertain** | The lease expired with no terminal result | Neither timestamp reached a terminal outcome |

**Sent is not printed.** A device can accept bytes and then jam, run out of tape, or be
switched off mid-job. Where a driver can report completion, the outbox row is acknowledged
on the report; where it cannot, it is acknowledged on send, and the record says which of
the two happened rather than presenting them as the same fact.

**The crash-after-send case gets a stated policy: retry, and accept a duplicate label.** A
worker killed between writing bytes and posting its result leaves an attempt that expires,
and the job is claimed again. Under at-least-once delivery there is no way to tell that
case apart from "the bytes never arrived", and the two failure costs are not symmetric: a
duplicate label is a few centimetres of tape and a person throwing one away, while a
missing label is a physical artifact that does not exist for something the ledger says was
booked. The `attempts` counter and the attempt rows make a repeatedly duplicating printer
visible, which is the check that keeps this from being silent.

**Camera verification is evidence, never control flow.** Evidence attaches to an
`attempt_id`, not to a label uid, and this is the reason attempts exist as rows at all: a
uid is stable by construction under ADR-0011, so it cannot distinguish an original from a
reprint, and evidence keyed on it would confirm the wrong attempt. A missing verification
does not re-print anything automatically — it is a discrepancy for a person, in the same
way ADR-0011 makes a scan of a retired uid a discrepancy signal rather than an error.

### 7. Retirement is the destination, and it is sequenced

Full retirement of the webhook is the accepted destination: every entity type prints
through this path, `WebhookRunner` loses its last caller, and the four constants go. It
does not all happen at once, and the two halves have different prerequisites.

1. **Location labels first, in wave 3b.** [Plan 06](../plans/06-location-barcodes.md) is
   wave 3b in the [plans index](../plans/README.md) and locations have no `/printlabel`
   endpoint today, so this is purely additive: a new entity, a new event type, four new
   API routes, and a print action on the locations pages. **No existing response changes.**
2. **The five existing endpoints migrate afterwards** — products, stock entries, recipes,
   chores, batteries (`routes.php:239-240,256,265,276`). This is the step that changes the
   wire, and the resolution below gates *this step*, not step 1.
3. **The webhook and its constants are deleted** when step 2 completes, taking
   `WebhookRunner`'s last caller and the four `SystemApiController::EXPOSED_SETTINGS`
   entries (`controllers/Api/SystemApiController.php:46-49`) with them.

**The existing webhook keeps working through steps 1 and 2.** Coexistence costs nothing
structural: the old path prints `grcy:` codes through the webhook to whatever renders them
today, the new path prints `vctl:` codes through the worker, and the two do not interact.
The new worker never needs to render DataMatrix for legacy codes, so it never needs
`treepoem` or Ghostscript, and `grcy:` emission stops entity by entity as step 2 proceeds
rather than on a flag day — which is what the constitution's rule that formats are retired
by attrition asks for.

**The wire change, stated so step 2 cannot start without resolving it.** The five
`/printlabel` responses currently return the webhook payload they built: `product`,
`grocycode`, `details`, the stock-entry variant's `stock_entry` and conditional `due_date`,
merged with `VICTUAL_LABEL_PRINTER_PARAMS`
(`controllers/Api/StockApiController.php::ProductPrintLabel` and `::StockEntryPrintLabel`,
and the same shape in `RecipesApiController`, `ChoresApiController`,
`BatteriesApiController`). `GET /api/system/config` additionally loses four keys.
A no-change option does not exist for step 2, because ADR-0011 decision item 3 already
forbids the `grocycode` field's current value: the choices are to keep emitting `grcy:`
from `/printlabel` alone, which contradicts an accepted record; to return `vctl:<uid>`
under the key `grocycode`, which keeps the key and changes what it means, so a client
rendering that value itself would print a DataMatrix of a `vctl:` payload and produce a
physical artifact in the wrong symbology; or to change the response.
[ADR-0005](0005-wire-contract-is-the-invariant.md)'s two accepted exceptions and its one
withdrawn exception are all cases where the engines disagreed and one side was named
conforming. This is a fork-initiated redesign of a response, which is a different kind of
change, and it belongs in a record of its own rather than as a third row under a record
about engine disagreement. Writing that record is step 2's prerequisite.

**Blast radius, by a check already run and one still owed.** ADR-0011's acceptance checked
[plan 17](../plans/17-ecosystem-clients.md)'s catalogue for clients that *generate*
Grocycodes and found neither tracked client does. The `/system/config` half is a different
question — the Home Assistant integration reads that endpoint — and it is owed before step
3, not before step 1.

## Options considered

### Where printer configuration lives

**A. Keep it in `Setting()` constants, renamed.** Zero schema. Keeps every property of a
physical device in a file that requires a pod restart to change, and cannot express more
than one printer or more than one worker at all. Rejected on the deployment argument.

**B. A general instance-settings table.** A typed key/value `settings` table with printer
keys as its first tenant. The first tenant of a general mechanism sets its semantics —
scoping, defaults, precedence against environment variables, who may read which key — and
a printer is a poor specimen to design those against. Rejected as premature; if a general
settings table is ever wanted, `label_printers` is not evidence against it.

**C. A `label_printers` master-data table.** The proposal. One entity, the CRUD and UI
machinery every other master-data entity already uses, no new mechanism, and multiple
printers and multiple workers fall out of it.

### How the worker gets its work

Compared in decision item 2: direct database access, MQTT, push, and the authenticated
pull API that is the proposal. The deciding constraint is that a USB-attached worker
outside the cluster must remain possible, which pull satisfies and the other three
satisfy only by exposing something worse across the boundary.

### How much moves, and when

**A. Locations only, indefinitely.** Two printing subsystems that never converge, each
with its own configuration model and failure behaviour. Rejected as a destination — but it
is not rejected as a *step*, which is what decision item 7 makes it.

**B. Everything at once.** One release that adds the worker, migrates five endpoints,
changes two response shapes and deletes the webhook. Rejected on risk, not on principle:
it makes the location work wait on an ADR-0005 resolution it does not need.

**C. Full retirement, sequenced.** The proposal. Locations first because they are
additive, existing endpoints second behind the wire-change resolution, deletion third.

## Consequences

**Victual makes no outbound connection for printing, at any point.** Not to a printer, not
to a worker. `WebhookRunner` currently fires from five endpoints server-side and from the
browser when `LABEL_PRINTER_RUN_SERVER` is false; both go with step 3. The security
sweep's line that "webhooks target only the `VICTUAL_LABEL_PRINTER_WEBHOOK` constant … no
user-configurable outbound URL exists, so no SSRF beyond S14"
(`docs/security-sweep.md`) becomes wrong in its subject rather than its conclusion, and
needs rewriting in the change that lands step 3.

**A configurable outbound destination still exists; it has moved to the worker.**
AGENTS.md warns against user-configurable outbound destinations, and this is one, so the
position is stated rather than waived:

- Victual stores the connection and never dials it. The worker dials it, inside the
  worker's own network, and the worker handles no requests from anyone — it is a pull
  client, so there is no request whose contents could steer it.
- What an admin can cause is a connection attempt from the worker to an address of their
  choosing, with the error text returned through the acknowledgment. That is an oracle,
  and it is worth naming: it is bounded by the driver's transport (a printer driver speaks
  to a printer, not arbitrary HTTP), by the error text the worker chooses to return, and
  by the worker's own network policy under [ADR-0010](0010-workload-standard.md) property
  3. The control belongs to the worker's manifest, which is where the accepting change
  should put it.
- The writer is an admin (`ExposedEntityEditRequiresAdmin` plus the `EntityReadPolicy`
  row), not any authenticated household member. Under
  [ADR-0006](0006-authenticated-issues-in-scope.md) that narrows who can reach it; it does
  not excuse it.

Net: the application tier's outbound surface goes to zero, and a smaller, differently
shaped surface appears in a workload whose network policy is a reviewed manifest. The
sweep gets a note recording that, not a waiver.

**Late binding departs from what migration 0259's comment argues for, deliberately and
narrowly.** That comment makes the case for self-contained payloads: a consumer that
re-read the ledger at delivery time "would compute a different timestamp on every retry and
give a drained backlog the latest stock snapshot rather than each transaction's own, which
is the difference between at-least-once delivery being safe and being lossy in a new way."
That argument is about facts. Printer configuration is not a fact about the past; it is the
current description of a device. The failure modes point opposite ways: re-reading the
ledger at delivery loses information, while embedding the connection at enqueue loses the
fix — a queue of jobs that failed because the media was wrong retries forever with the
wrong media, and correcting it means deleting and recreating every queued job.

The bound, so this does not generalise into "consumers may re-read anything": a payload
field may be late-bound only if it describes the *delivery device*, never if it describes
*what happened*. The test is whether a person who changed the value would expect queued
work to use the new one. For "the printer moved to a new address", yes. For "the product
was renamed after the label was queued", no — the label records what was intended when the
booking happened, and the constitution's rule that physical artifacts are contracts is why.
`printer_id` is late-bound; the uid and the rendered text are not.

**Two repositories to release, and a pin between them.** A template fix or a driver bump
is a revision bump and a `flake.lock` change here. That is a real cost — a two-repository
change is slower than a one-repository change — and it buys the property that imaging bugs
do not gate Victual releases, and that a second driver family arrives without touching this
tree at all.

**The worker needs no database role, which removes a problem rather than adding one.**
[Plan 20](../plans/20-container-infrastructure.md)'s verification check 8 — "the credential
split is real", recorded as "Not done. Needs a role with no DDL rights; the bootstrap uses
one superuser" — stays a two-role problem instead of becoming a three-role one. What the
worker holds instead is a typed API key whose permission set is bounded by the four routes
it may call.

**The render/print seam is verified two ways, because it has two kinds of defect.**
[Issue #90](https://github.com/datagen24/victual/issues/90) records both against the
prototype whose imaging code the worker reuses. The resize defect — the renderer authors
its canvas against `dots_total` while `brother_ql` compares against `dots_printable`, so
every endless print is silently resampled and at 600 dpi comes out roughly 1.9× too long —
is measured, reproducible, and catchable by an assertion that spies on `Image.Image.resize`
across `convert()` and requires zero resize calls. That assertion runs in the worker
repository's CI and costs nothing. The rotation *sign* is not catchable that way: the issue
says so, having established that no library default competes with the prototype's hardcoded
`-90`, and it needs one physical print against the tape feed direction. Both are required;
neither substitutes for the other.

**One more table under the migration discipline**, PostgreSQL-only, plain, with no views or
triggers, plus `print_attempts`. The `labels` table ADR-0011 requires is separate and still
unowned; this record does not claim it.

## Reliance on ADR-0010, which is Proposed

Two properties of [ADR-0010](0010-workload-standard.md) carry weight here, and this record
does not treat a proposal as binding:

- **"Consumers may multiply; contracts may not"** — the reason decision item 5 reuses the
  outbox rather than minting a queue. This rule has two homes that are not 0010: the
  [constitution](../constitution.md)'s workload-standard section states it, and migration
  0259's comment implements it in the tree with a table that exists. Relying on it is not
  relying on a proposal alone.
- **Unprivileged, with its own identity and least privilege for one job** — the reason the
  worker gets a typed API key rather than a general one, and the reason its outbound
  reach is a manifest property. This is also in the constitution's workload standard, and
  its concrete form for the existing images is ADR-0013 decision item 6.

Both reliances are arguments for accepting 0010, not claims that it is accepted. If 0010 is
rejected, decision items 2 and 5 need re-arguing on the constitution alone, which is a
weaker but not empty basis. Neither is load-bearing for decision item 3: whether printers
are master data does not depend on 0010 at all.

## Acceptance prerequisites

Gates, not suggestions. Each is evidence to be reported by the accepting pull request under
the lifecycle's bookkeeping-only rule; none asks that pull request to decide anything.

1. **The worker packages as an image on no base image.** `brother-ql-inventree` is not in
   nixpkgs and the images are built with no base, which is the highest-risk item here and
   the one that would invalidate plan 20 piece 5's designation if it failed. The gate is a
   built image from a pinned revision of the worker repository through
   `nix/images/lib.nix`, passing the existing `nix flake check` assertions including
   `image-has-no-shell`, with its closure size recorded.
2. **Claim, acknowledge and crash-after-send behave as decision item 6 specifies**,
   demonstrated against a fake device with no printer involved: two workers cannot hold the
   same job, an expired lease returns a job to the queue, and a worker killed between
   `bytes_sent_at` and its terminal result produces a duplicate label rather than a lost
   one.
3. **The worker's identity is provisioned and its reach measured.** A
   `label_worker`-type key can claim and acknowledge, and is refused on `GET /api/stock`
   and on `/api/objects/label_printers`; a `default`-type key is refused on the four label
   routes. Reported as observed responses, not as a reading of the middleware.

## Open questions

1. **What does a camera verify against, concretely?** Evidence attaches to an `attempt_id`,
   which is decided; what an evidence row *contains* — a decoded uid, an image reference, a
   confidence value — is not. *Lean: a decoded uid plus a reference to the image, and if a
   confidence value ever appears, [ADR-0012](0012-observations-are-proposals.md) governs
   what may be done with it, because "this label was printed" asserted with a confidence is
   an observation.*
2. **Retention.** Nothing prunes delivered outbox rows today, by 0259's deliberate
   deferral, and `print_attempts` grows faster than the outbox does. *Lean: decide both
   together, when there is a retention decision to make rather than two independent
   guesses.*
3. **Template versioning across a pinned-revision bump.** A worker revision that changes
   what a template name means will render queued jobs differently from how they were
   intended. *Lean: the job carries the template name and the worker refuses a name it does
   not know, dead-lettering rather than guessing — the same discipline `PAYLOAD_VERSION`
   applies to payload shapes.*
4. **The capability model for a second driver family.** Decision item 3 draws the
   device/template boundary but leaves the driver capability vocabulary to the worker.
   *Lean: keep it in the worker until a second family exists; the first driver's needs are
   a bad specification for the general case, which is the mistake the Brother-specific
   column set would have made.*

## Research

- Tree facts measured on the working copy of 2026-09-06: the four `LABEL_PRINTER_*`
  settings (`config-dist.php:226-229`) and their reach into
  `SystemApiController::EXPOSED_SETTINGS`; the five `*/printlabel` routes
  (`routes.php:239-240,256,265,276`) and the payload each builds; `Setting()` in
  `helpers/extensions.php`; `shopping_locations` (`db/pgsql/baseline/01_tables.sql:308`) as
  the master-data shape; `EntityReadPolicy::PERMISSIONS` and its fail-closed default;
  `GenericEntityApiController`'s use of the `ExposedEntity*` enums; `api_keys.key_type` and
  `ApiKeyService`'s two type constants with hashed storage and `key_hint` since migration
  0264; the `outbox` table and `OutboxService`'s event-type and payload-version discipline;
  `bin/victual-publish-state` as the existing in-repository consumer.
- Plan 06 is wave 3b in the [plans index](../plans/README.md); wave 3b's row records 03 as
  complete and 06 as remaining, and notes that shared route and spec edits in that wave
  need coordination because 03 took the `ExposedEntity` enums.
- [Issue #90](https://github.com/datagen24/victual/issues/90) measured the resize behaviour
  against the installed `brother-ql-inventree` and is reproducible from the snippet it
  carries; the rotation sign it reports is code-read and the issue states it needs one
  physical print.
