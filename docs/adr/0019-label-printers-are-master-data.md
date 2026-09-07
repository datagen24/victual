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
| The printer inventory: persisted instances, the configuration UI, and validation | Driver implementations, and the schema each advertises |
| The driver registry: which drivers exist, at which schema versions | The rasterizer and imaging code |
| The print job event and its payload contract | Label templates and their definitions |
| The claim/acknowledge/register API and its OpenAPI contract | Device transport (TCP, USB, whatever a driver needs) |
| The attempt record and the observed-status record | The worker's own tests, including geometry assertions |
| The flake input pinning the worker's revision, and the image built from it | |

Victual owns **configuration and monitoring**. The worker owns **rendering, device contact
and driver implementation**. This preserves ADR-0011's consequence that rendering leaves
this repository, and it makes the reason structural rather than a convention: template
semantics, driver quirks and imaging bugs move on their own schedule, and none of them
should be a reason to cut a Victual release.

The seam between the two halves is a **capability contract**: a worker advertises what its
drivers accept, and Victual holds that description, renders configuration from it,
validates against it and stores the result. Neither side hardcodes the other's vocabulary.

`flake.nix` gains the worker repository as a pinned input and builds its image from that
revision through `nix/images/lib.nix`, so the artifact carries the same uid, labels and
`nix flake check` assertions as the three existing images. A revision bump is a
`flake.lock` change reviewed like any other, per ADR-0013 decision item 8.

### 2. Transport: an authenticated pull API, not direct database access

**The worker holds no database credential and makes no database connection.** It
authenticates to Victual's HTTP API, advertises what it can drive, and pulls work. Six
endpoints, all additive:

- `POST /api/labels/drivers` — the worker registers each driver it carries, as
  `(driver_id, schema_version, schema, capabilities)`. Idempotent, append-only, and
  refused when it would contradict a stored registration — see decision item 3.
- `POST /api/labels/jobs/claim` — the worker asks for up to *n* jobs for the printers
  assigned to it and drivable by it. The response carries, per job, the label uid, the
  template name, the rendered text fields as captured at enqueue, **the printer's
  configuration resolved now**, an `attempt_id`, and a lease expiry.
- `POST /api/labels/attempts/{attempt_id}/sent` — bytes reached the device.
- `POST /api/labels/attempts/{attempt_id}/result` — terminal outcome, with the device's
  report or the error.
- `POST /api/labels/attempts/{attempt_id}/evidence` — optional, for camera verification.
- `POST /api/labels/printers/{id}/status` — observed status: what the device last
  reported. Write-only for workers, and never configuration — see decision item 3.

**Worker identity is an API key of a new type**, `ApiKeyService::API_KEY_TYPE_LABEL_WORKER`,
alongside the two existing constants (`services/ApiKeyService.php:15-16`) and the `mcp`
type the [MCP interface spec](../mcp-interface-spec.md) proposes. Keys are already stored
as a SHA-256 hash with a `key_hint` (migration 0264), already carry `key_type` on the
`api_keys` table, and are already validated per route — so a label-worker key is granted
and revoked independently of general API keys, and `ApiKeyAuthenticator` gains one type in
its accepted set for these six routes only. No new authentication machinery.

**Assignment is checked, not conventional** — see decision item 3's *Worker assignment*.
It combines a column naming the printer's worker with the driver registration the caller
holds, so a worker is never offered a job it could not render.

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

### 3. A driver registry and a capability contract, not a fixed column set

A printer's configuration is not one shape. A Brother QL wants a tape identity and a
two-colour flag; a Zebra wants ZPL darkness and a tear-off offset; a CUPS-attached device
wants a queue name and options. Freezing the union of those as columns means the second
driver family arrives as a migration, and the first family's vocabulary becomes the schema
everyone else is bent into. Freezing them as one opaque blob means Victual cannot render a
form, cannot validate a write, and discovers a bad configuration when a label fails to
print.

**Workers advertise versioned configuration schemas; Victual owns the UI, the persisted
instances and the validation.** Three tables, and the split between them is the point.

#### Common fields stay typed columns

`label_printers` carries, as ordinary columns, exactly what **Victual itself** reads:

| Column | Why Victual reads it |
|---|---|
| `id`, `name`, `description`, `row_created_timestamp`, `active`, `is_default` | Lifecycle and UI, as `shopping_locations` has them |
| `worker` | Routing: which worker is offered this printer's jobs. Nullable |
| `driver_id`, `driver_schema_version` | Validation, and claim-time compatibility |
| `connection` | The outbound destination. **Typed and auditable on purpose** — the security posture below depends on being able to point at every destination the deployment will dial, and a value buried inside a JSON document is not greppable. Interpreted by the driver: a TCP endpoint, a USB device path, a queue name. One field rather than host and port, because the latter already assumes one transport and the USB worker breaks it |
| `model`, `dpi` | Present on every raster label printer, and shown in the UI. The vocabulary is the driver's; the presence is not |

#### Driver-specific settings are a validated document

Everything a *driver* reads and Victual does not — media identity, colour capability, cut
behaviour, darkness, margins — lives in a `settings` JSON document on the same row,
**validated on write against the schema registered for that `driver_id` at that
`driver_schema_version`**. Victual never interprets its contents; it only enforces that
they match what the driver said it accepts.

The configuration form is generated from the registered schema, so adding a driver adds a
form without a frontend change. The same schema is what makes an invalid setting a 400 at
configuration time instead of a failed print an hour later.

#### The registry: `label_drivers`

One row per `(driver_id, schema_version)`: the JSON Schema for that driver's settings, a
capability document describing what the driver can do, the worker identity that registered
it, and when. **Append-only and immutable.**

- **Driver identity** is a stable namespaced string naming a *contract*, not an
  implementation — `brother.ql`, not `brother_ql` as one Python package spells it, and
  never a version. Two different workers may register the same `driver_id`, which is
  exactly what lets a spare worker take over an assigned printer. The identity is owned by
  the repository that implements the driver.
- **Schema compatibility** is `major.minor`. A major bump means the settings shape changed
  incompatibly; a minor bump means it grew additively.
- **Re-registering an existing `(driver_id, schema_version)` with a different schema
  document is refused.** Printer rows were validated against the stored one, so silently
  replacing it would leave stored settings claiming a validity nobody checked. A worker
  whose schema changed bumps the version. This is the rule that makes the version number
  mean something rather than being an assertion nobody tests.
- **A new minor is accepted only if every stored printer row on that driver's earlier
  minors still validates against it.** Victual does not attempt schema subsumption — it
  runs the rows it actually has. That is cheaper than reasoning about the schemas and it
  tests the property that matters.
- **A new major is accepted freely and adopts nothing.** Moving a printer instance to a
  new major is an explicit admin action that revalidates its settings and rewrites
  `driver_schema_version`. Never automatic, because a major bump is by definition a change
  the stored settings may not survive.

#### Worker assignment

A job is offered to a claiming worker when all of the following hold. Each is a check, not
a convention:

1. The printer is `active`.
2. `label_printers.worker` is null, or equals the caller's worker identity.
3. The caller has a current registration for the printer's `driver_id` at the **same
   major** and a **minor greater than or equal to** the row's pinned minor.

Rule 3 is what stops a worker being handed a job whose settings use a property its build
does not know about. A null `worker` with several workers registered means whichever claims
first takes it, which is safe because a claim is an insert.

#### Observed status is a separate table, written only by workers

`label_printer_status` holds what the device last reported: `last_seen_at`, the media the
device says is loaded, error or warning state, and the worker that reported it. It is
written only through the status endpoint by a label-worker key, is never admin-editable,
and is never read as configuration.

The separation earns its keep at exactly one place, and it is worth naming: **configured
media and reported media are different fields in different tables, and neither overwrites
the other.** A mismatch is a discrepancy a person resolves — the same stance ADR-0011 takes
for a scan of a retired uid. A design that let the device's report update the configuration
would make "someone loaded the wrong tape" indistinguishable from "someone changed the
configuration", and a design that let configuration overwrite the report would discard the
only evidence of what is physically in the machine.

There is no `online` boolean. Status goes **stale**, not false: "reported healthy three
days ago" and "reported healthy three seconds ago" must not render identically, which a
boolean cannot express and a `last_seen_at` with a staleness rule can.

#### Offline behaviour

- **Registrations are persisted, not a live session.** A worker going away deregisters
  nothing. Stored printer rows depend on their schema remaining available to be
  interpreted and re-validated, so deregistration is an explicit admin action, not a
  timeout.
- **Configuration works with nothing running.** The admin can create and edit printers
  against the stored schema while every worker is down.
- **Jobs queue, and nothing dead-letters for being unclaimed.** This is the precise failure
  [ADR-0011](0011-label-namespace.md) fact 2 named — "a printer that is off for a day eats
  a day of labels" — and the outbox exists to remove it. Unclaimed age is backlog, which is
  visible; it is not an error. Dead-lettering stays for what 0259 defined it for, a payload
  no version can read, plus decision item 4's deleted-printer case.
- **A printer with no compatible worker is a visible state, not a silent one.** Rule 3
  failing for every registered worker means the printer's jobs are never offered; the
  configuration screen says so, because the alternative is a queue that grows for a reason
  nobody can see.

#### Where the three kinds of setting live

| Kind | Set by | Lives in | Example |
|---|---|---|---|
| Device settings | An admin | `label_printers` columns and its validated `settings` | Which tape is loaded; the connection |
| Template settings | The template author | The worker repository | The font; rendering the due date in red |
| Observed status | A worker, reporting | `label_printer_status` | The tape the device says is loaded; a paper-out warning |

The rule for placing a value: if a person sets it, it is device settings; if a worker
reports it, it is status; if it describes what a label looks like, it is a template
setting. Nothing crosses. A printer's media being two-colour is a device fact; a template
choosing to render the due date in red is a template choice that is only valid where that
device fact is true, and the capability document is how the template learns it.

#### How these entities are reached

`label_printers`, `label_drivers` and `label_printer_status` are added to the OpenAPI
`ExposedEntity` enum for reading, to `ExposedEntityNoEdit` and `ExposedEntityNoDelete`, and
each gains a `PERMISSION_ADMIN` row in `EntityReadPolicy::PERMISSIONS` — which is
fail-closed, throwing "Entity has no read policy" for an entity absent from it. Reads go
through `GenericEntityApiController` and the UI is a `Victual.EntityList` list page as
`shoppinglocations` is.

**Writes do not go through the generic path, and that is a departure from this record's
first draft worth stating plainly.** Two reasons, one of them concrete:
`GenericEntityApiController` has no per-entity validation hook, and
`BaseApiController::GetParsedAndFilteredRequestBody` explicitly skips arrays when
sanitising ("HTMLPurifier removes boolean values and arrays, so explicitly keep them"), so
a nested settings document would reach the database through it unexamined. Schema
validation would then be the only gate on that document, applied nowhere. A dedicated
controller validates against the registry and requires `PERMISSION_ADMIN` directly. The
read-generically, write-purposefully pattern is not new: `ExposedEntityNoEdit` already
holds fifteen entities including `roles`, which plan 19 writes through its own endpoints.

The migrations are PostgreSQL-only; the SQLite line is frozen at 0265 by
[plan 24](../plans/24-sqlite-runtime-retirement.md).

#### What stops this becoming a settings framework

The registry is a mechanism, and the previous draft's claim that this record creates none
no longer holds. What bounds it: a registration describes **one driver's device settings**,
and nothing else may be stored through it. The schema is supplied by a label-worker key and
is reachable only from the label-worker routes. Configuration that is not a property of a
printing device stays where it is — instance-wide behaviour in `Setting()` constants,
per-person preference in `user_settings`, appearance in templates. The test for admitting a
field to `settings` is unchanged and now enforced rather than argued: a driver declared it,
or it cannot be stored.

### 4. The job names a printer; the configuration is resolved at claim time

The outbox payload carries the label uid, the template name, the rendered text fields as
they stood when the job was created, and a `printer_id` — not a connection, not a media
identity, not a settings document. The claim response resolves that printer's current row
and returns its typed columns, its validated `settings`, and the `driver_id` and
`driver_schema_version` they were validated against, so the worker knows which of its
builds' expectations apply rather than inferring them. A job whose printer has been deleted
or deactivated is dead-lettered with `last_error` saying so, rather than handed out against
a device that is gone.

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

**C. A `label_printers` master-data table.** The proposal. Persisted instances, an admin
UI, and multiple printers and multiple workers falling out of it rather than being designed
in.

### How a printer's settings are shaped

**A. A fixed column set, wide enough for the printers in hand.** What the first draft of
this record proposed. It is simple until the second driver family arrives, at which point
it is a migration per family, and the first family's vocabulary is the schema every later
one is bent into. Rejected.

**B. One opaque JSON column.** No migration per family, and no validation, no generated
form, and no way to tell a typo from an intentional setting until a print fails. Rejected:
it moves the failure from configuration time to print time, which is the direction this
record is trying to move things away from.

**C. A registry of worker-advertised, versioned schemas, with common fields typed.** The
proposal. Costs a JSON Schema dependency and a registry the workers write to; buys
validation at configuration time, a form Victual can generate for a driver it has never
heard of, and a second driver family that arrives with no migration and no frontend change.

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
- The writer is an admin: `connection` is set through the dedicated write controller,
  which requires `PERMISSION_ADMIN`, and read through an entity whose `EntityReadPolicy`
  row is `PERMISSION_ADMIN`. Not any authenticated household member. Under
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
worker holds instead is a typed API key whose permission set is bounded by the six routes
it may call.

**A worker now writes three kinds of row, and one of them is a schema.** Attempts and
status are per-device bookkeeping and unremarkable. The driver registry is different: a
machine identity supplies a document that later governs what an admin may store. The bound
is in decision item 3 rather than in trust — a registration is append-only, is refused when
it contradicts a stored one, and is refused when it would invalidate an existing printer
row. So a compromised or buggy worker can add a driver nobody uses; it cannot rewrite the
rules under configurations that already exist, and it cannot make a stored printer stop
validating.

**A JSON Schema validator becomes a dependency, and there is none in the tree.**
`composer.json`'s eighteen requirements include no schema validator, so this is a new
package. Two consequences beyond the package itself: it is the second addition this fork
has made to `composer.json` after `php-mqtt/client`, which plan 18's security notes say the
sweep's dependency review should pick up; and a `composer.lock` change moves the
fixed-output hash in `nix/hashes.nix`, which ADR-0013 records as one of two hashes
maintained by hand. Both are known costs rather than surprises, and the alternative —
hand-rolled validation of an arbitrary driver-supplied schema — is worse.

**The configuration form is generated, which is a frontend capability the tree does not
have.** `Victual.EntityForm` binds fixed fields to an entity. Rendering a form from a JSON
Schema is new work, and it is the largest single cost in this record. What it buys is that
a second driver family ships with no frontend change at all; what it risks is a form
generator growing to cover schema features nobody needs. The bound: the generator supports
the subset a driver actually uses, and a schema using more than that is rejected at
registration rather than rendered badly.

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

**Four tables under the migration discipline**, PostgreSQL-only and plain, with no views or
triggers: `label_printers`, `label_drivers`, `label_printer_status` and `print_attempts`.
That is more than the first draft's one, and the reason is that device settings, the schemas
that validate them, observed status and attempt history are four different lifetimes —
admin-edited, append-only, worker-overwritten and append-only respectively. Collapsing any
pair of them is what produces the failure modes decision item 3 exists to avoid. The
`labels` table ADR-0011 requires is separate and still unowned; this record does not claim
it.

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
   `label_worker`-type key can register, claim and acknowledge, and is refused on
   `GET /api/stock` and on writing `label_printers`; a `default`-type key is refused on the
   six label routes. Reported as observed responses, not as a reading of the middleware.
4. **The registry's compatibility rules hold under the cases that motivate them**, against
   two registered drivers so the rules are exercised rather than described:
   re-registering an existing `(driver_id, schema_version)` with a changed schema is
   refused; a new minor that would invalidate a stored printer row is refused, and the same
   minor is accepted once that row is corrected; a worker registered at a lower minor than a
   printer's pinned version is not offered that printer's jobs; and a printer whose settings
   are invalid for its driver is refused at configuration time rather than at print time.

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
4. **What the capability document contains, as distinct from the settings schema.** The
   settings schema says what an admin may configure; the capability document says what the
   driver can do, and templates read it — whether two colours are available, the printable
   geometry, whether the device reports completion. Its shape is not decided here. *Lean:
   let the first driver's document be whatever the first template needs, and standardise
   only the two or three keys a second driver family proves are general. A capability
   vocabulary designed against one family is the mistake the fixed column set would have
   made, one level up.*
5. **Which JSON Schema dialect and which subset the form generator supports.** These are
   the same question asked twice: the generator's supported subset is what registration
   should enforce. *Lean: a draft the chosen PHP library implements, restricted to object
   schemas of scalar and enumerated properties with titles and descriptions, and a
   registration using anything outside that refused with a message saying so.*

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
- **No JSON Schema validator is in `composer.json`**, whose `require` block holds eighteen
  packages, the most recent additions being `ramsey/uuid` and `php-mqtt/client`. Checked
  2026-09-06.
- **`GenericEntityApiController` has no per-entity validation hook**, and
  `BaseApiController::GetParsedAndFilteredRequestBody` sanitises scalar values while
  explicitly skipping arrays and booleans — its comment says "HTMLPurifier removes boolean
  values (true/false) and arrays, so explicitly keep them". A nested settings document
  therefore passes through it unexamined, which is why decision item 3 writes through a
  dedicated controller. `ExposedEntityNoEdit` holds fifteen entities today, so
  read-generically and write-purposefully is the tree's existing pattern rather than a new
  one.
- Plan 06 is wave 3b in the [plans index](../plans/README.md); wave 3b's row records 03 as
  complete and 06 as remaining, and notes that shared route and spec edits in that wave
  need coordination because 03 took the `ExposedEntity` enums.
- [Issue #90](https://github.com/datagen24/victual/issues/90) measured the resize behaviour
  against the installed `brother-ql-inventree` and is reproducible from the snippet it
  carries; the rotation sign it reports is code-read and the issue states it needs one
  physical print.
