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
existing consumer, `bin/victual-publish-state`, is a PHP command in this repository sharing
the application's `config.php` and its database credential. The label worker is not that.

**Building an image here does not determine where its source lives.**
[Plan 20](../plans/20-container-infrastructure.md) piece 5 designates ADR-0011's print
drainer, plan 18's MQTT publisher and the MCP sidecar as images in this flake, taking uid,
labels, checks and manifest shape from `nix/images/lib.nix`. That settles who *builds* the
artifact and to what standard, not who owns the source: the flake can build a pinned
revision of another repository as easily as it builds this one.

**The deployment is a networked worker today and must not preclude a local one.** The
immediate target is a K3S worker reaching TCP-attached printers through firewall rules. A
worker attached to a printer over USB, on a machine that is not in the cluster and may not
be reachable from it, has to stay possible. That is the case the current design handles
badly: `LABEL_PRINTER_RUN_SERVER=false` exists because the Victual host may not be able to
reach the printer.

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
this repository: template semantics, driver quirks and imaging bugs move on their own
schedule, and none of them is a reason to cut a Victual release.

The seam is a **capability contract**. A worker advertises what its drivers accept; Victual
holds that description, renders configuration from it, validates against it and stores the
result. Neither side hardcodes the other's vocabulary.

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
- **Push from Victual to the worker** is what the webhook does now and what ADR-0011
  retires. It requires Victual to hold an outbound destination and reach the worker's
  network, which the USB worker cannot satisfy.

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
instances and the validation.** Three tables.

#### Common fields stay typed columns

`label_printers` carries, as ordinary columns, exactly what **Victual itself** reads:

| Column | Why Victual reads it |
|---|---|
| `id`, `name`, `description`, `row_created_timestamp`, `active`, `is_default` | Lifecycle and UI, as `shopping_locations` has them |
| `worker` | Routing: which worker is offered this printer's jobs. Nullable |
| `driver_id`, `driver_schema_version` | Validation, and claim-time compatibility |
| `connection` | The outbound destination, kept a column so every address the deployment will dial is auditable in one place. Interpreted by the driver: a TCP endpoint, a USB device path, a queue name. One field rather than host and port, which assumes one transport |
| `model`, `dpi` | Present on every raster label printer, and shown in the UI. The vocabulary is the driver's; the presence is not |

#### Driver-specific settings are a validated document

Everything a *driver* reads and Victual does not — media identity, colour capability, cut
behaviour, darkness, margins — lives in a `settings` JSON document on the same row,
**validated on write against the schema registered for that `driver_id` at that
`driver_schema_version`**. Victual never interprets its contents; it only enforces that
they match what the driver said it accepts.

The configuration form is generated from the registered schema, so adding a driver adds a
form without a frontend change, and an invalid setting a 400 at configuration time rather
than a failed print an hour later.

#### The registry: `label_drivers`

One row per `(driver_id, schema_version)`: the JSON Schema for that driver's settings, a
capability document describing what the driver can do, the worker identity that registered
it, and when. **Append-only and immutable.**

- **Driver identity** is a stable namespaced string naming a *contract*, not an
  implementation — `brother.ql`, not `brother_ql` as one Python package spells it, and
  never a version. Two workers may register the same `driver_id`, which is how a spare
  takes over an assigned printer. The identity is owned by the repository implementing the
  driver.
- **Schema compatibility** is `major.minor`. A major bump means the settings shape changed
  incompatibly; a minor bump means it grew additively.
- **Re-registering an existing `(driver_id, schema_version)` with a different schema
  document is refused.** Printer rows were validated against the stored one, so replacing
  it would leave stored settings claiming a validity nobody checked. A worker whose schema
  changed bumps the version.
- **A new minor is accepted only if every stored printer row on that driver's earlier
  minors still validates against it.** Victual runs the rows it has rather than attempting
  schema subsumption.
- **A new major is accepted freely and adopts nothing.** Moving a printer instance to a
  new major is an explicit admin action that revalidates its settings and rewrites
  `driver_schema_version`. A major bump is a change the stored settings may not survive, so
  nothing adopts one automatically.

#### Worker assignment

A job is offered to a claiming worker when all three hold:

1. The printer is `active`.
2. `label_printers.worker` is null, or equals the caller's worker identity.
3. The caller has a current registration for the printer's `driver_id` at the **same
   major** and a **minor greater than or equal to** the row's pinned minor.

Rule 3 stops a worker being handed a job whose settings use a property its build does not
know. A null `worker` with several workers registered means whichever claims first takes it;
a claim is an insert, so that is safe.

#### Observed status is a separate table, written only by workers

`label_printer_status` holds what the device last reported: `last_seen_at`, the media the
device says is loaded, error or warning state, and the worker that reported it. It is
written only through the status endpoint by a label-worker key, is never admin-editable,
and is never read as configuration.

**Configured media and reported media are different fields in different tables, and
neither overwrites the other.** A mismatch is a discrepancy a person resolves — the same
stance ADR-0011 takes for a scan of a retired uid. Letting the device's report update the
configuration would make "someone loaded the wrong tape" indistinguishable from "someone
changed the configuration"; letting configuration overwrite the report would discard the
only evidence of what is physically in the machine.

There is no `online` boolean. Status goes **stale**, not false: "reported healthy three
days ago" and "reported healthy three seconds ago" must not render identically, which
`last_seen_at` with a staleness rule expresses and a boolean does not.

#### Offline behaviour

- **Registrations are persisted, not a live session.** A worker going away deregisters
  nothing. Stored printer rows depend on their schema remaining available to be
  interpreted and re-validated, so deregistration is an explicit admin action, not a
  timeout.
- **Configuration works with nothing running.** The admin can create and edit printers
  against the stored schema while every worker is down.
- **Jobs queue, and nothing dead-letters for being unclaimed.** Unclaimed age is backlog
  and is visible; it is not an error. This is the failure
  [ADR-0011](0011-label-namespace.md) fact 2 named — "a printer that is off for a day eats
  a day of labels" — which the outbox removes. Dead-lettering stays for what 0259 defined
  it for, a payload no version can read, plus decision item 4's deleted-printer case.
- **A printer with no compatible worker is a visible state.** Rule 3 failing for every
  registered worker means its jobs are never offered, and the configuration screen says
  so.

#### Where the three kinds of setting live

| Kind | Set by | Lives in | Example |
|---|---|---|---|
| Device settings | An admin | `label_printers` columns and its validated `settings` | Which tape is loaded; the connection |
| Template settings | The template author | The worker repository | The font; rendering the due date in red |
| Observed status | A worker, reporting | `label_printer_status` | The tape the device says is loaded; a paper-out warning |

The rule for placing a value: if a person sets it, it is device settings; if a worker
reports it, it is status; if it describes what a label looks like, it is a template
setting. Nothing crosses. A printer's media being two-colour is a device fact; rendering
the due date in red is a template choice valid only where that fact is true, and the
capability document is how the template learns it.

#### How these entities are reached

`label_printers`, `label_drivers` and `label_printer_status` are added to the OpenAPI
`ExposedEntity` enum for reading, to `ExposedEntityNoEdit` and `ExposedEntityNoDelete`, and
each gains a `PERMISSION_ADMIN` row in `EntityReadPolicy::PERMISSIONS` — which is
fail-closed, throwing "Entity has no read policy" for an entity absent from it. Reads go
through `GenericEntityApiController` and the UI is a `Victual.EntityList` list page as
`shoppinglocations` is.

**Writes go through a dedicated controller**, which validates the settings document
against the registry and requires `PERMISSION_ADMIN`. `GenericEntityApiController` has no
per-entity validation hook, and `BaseApiController::GetParsedAndFilteredRequestBody`
explicitly skips arrays when sanitising ("HTMLPurifier removes boolean values and arrays,
so explicitly keep them"), so a nested settings document would reach the database through
it unexamined. `ExposedEntityNoEdit` already holds fifteen entities read generically and
written through their own endpoints, `roles` among them.

The migrations are PostgreSQL-only; the SQLite line is frozen at 0265 by
[plan 24](../plans/24-sqlite-runtime-retirement.md).

#### What stops this becoming a settings framework

The registry is a mechanism, and these are its bounds. A registration describes **one
driver's device settings** and nothing else may be stored through it; the schema is
supplied by a label-worker key and reachable only from the label-worker routes.
Configuration that is not a property of a printing device stays where it is — instance-wide
behaviour in `Setting()` constants, per-person preference in `user_settings`, appearance in
templates. Admission to `settings` is enforced rather than argued: a driver declared the
field, or it cannot be stored.

### 4. The job names a printer; the configuration is resolved at claim time

The outbox payload carries the label uid, the template name, the rendered text fields as
they stood when the job was created, and a `printer_id` — not a connection, not a media
identity, not a settings document. The claim response resolves that printer's current row
and returns its typed columns, its validated `settings`, and the `driver_id` and
`driver_schema_version` they were validated against, so the worker knows which of its
builds' expectations apply. A job whose printer has been deleted
or deactivated is dead-lettered with `last_error` saying so, rather than handed out against
a device that is gone.

### 5. The outbox is reused; attempts are a separate evidence log

Event type `label.print_requested`, in the existing `outbox` table, under the existing
`payload_version` discipline. No second queue.

A `print_attempts` table records one row per claim: `attempt_id`, the outbox row it
belongs to, the worker identity, `claimed_at`, `lease_expires_at`, `bytes_sent_at`,
`device_reported_at`, outcome, error text, and a nullable evidence reference. It is not a
second queue: the outbox row remains the unit of work and is acknowledged by setting
`delivered_at`, while the attempt rows are this consumer's record of what it tried. A claim
is the insertion of an attempt row, so exclusivity is a database constraint rather than a
protocol promise, and an expired lease returns the job by making the next claim legal.

### 6. Delivery semantics

A print is not a boolean. Four facts, recorded separately because conflating them hides a
missing label:

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

**Crash after send: retry, and accept a duplicate label.** A worker killed between
writing bytes and posting its result leaves an attempt that expires, and the job is claimed
again. Under at-least-once delivery that case is indistinguishable from "the bytes never
arrived", and the costs are not symmetric: a duplicate label is a few centimetres of tape,
while a missing one is a physical artifact that does not exist for something the ledger says
was booked. The `attempts` counter and the attempt rows make a repeatedly duplicating
printer visible.

**Camera verification is evidence, never control flow.** Evidence attaches to an
`attempt_id`, not to a label uid: a uid is stable by construction under ADR-0011, so it
cannot distinguish an original from a reprint, and evidence keyed on it would confirm the
wrong attempt. A missing verification re-prints nothing automatically — it is a discrepancy
for a person, as ADR-0011 makes a scan of a retired uid.

### 7. Retirement is the destination, sequenced

Full retirement of the webhook is the destination: every entity type prints through this
path, `WebhookRunner` loses its last caller, and the four constants go. The steps have
different prerequisites.

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

**The existing webhook keeps working through steps 1 and 2.** The old path prints `grcy:`
codes through the webhook to whatever renders them today; the new path prints `vctl:` codes
through the worker; the two do not interact. The worker therefore never renders DataMatrix
and never needs `treepoem` or Ghostscript, and `grcy:` emission stops entity by entity as
step 2 proceeds rather than on a flag day, which is the attrition the constitution
requires.

**The wire change gating step 2.** The five
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
conforming. This is a fork-initiated redesign of a response, so it belongs in a record of
its own rather than as a third row under 0005. Writing that record is step 2's
prerequisite.

**Blast radius.** ADR-0011's acceptance checked
[plan 17](../plans/17-ecosystem-clients.md)'s catalogue for clients that *generate*
Grocycodes; neither tracked client does. The `/system/config` half is a separate check —
the Home Assistant integration reads that endpoint — owed before step 3.

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
UI, and support for several printers and several workers without designing either in.

### How a printer's settings are shaped

**A. A fixed column set, wide enough for the printers in hand.** Simple until the second
driver family arrives, at which point it is a migration per family and the first family's
vocabulary is the schema every later one is bent into. Rejected.

**B. One opaque JSON column.** No migration per family, and no validation, no generated
form, and no way to tell a typo from an intentional setting until a print fails. Rejected:
it moves the failure from configuration time to print time.

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
with its own configuration model and failure behaviour. Rejected as a destination; decision
item 7 keeps it as a step.

**B. Everything at once.** One release that adds the worker, migrates five endpoints,
changes two response shapes and deletes the webhook. Rejected because it makes the location
work wait on an ADR-0005 resolution it does not need.

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
AGENTS.md warns against these, and this is one:

- Victual stores the connection and never dials it. The worker dials it, inside the
  worker's own network, and the worker handles no requests from anyone — it is a pull
  client, so there is no request whose contents could steer it.
- An admin can cause a connection attempt from the worker to an address of their choosing,
  with the error text returned through the acknowledgment. That oracle is bounded by the
  driver's transport (a printer driver speaks to a printer, not arbitrary HTTP), by the
  error text the worker returns, and by the worker's network policy under
  [ADR-0010](0010-workload-standard.md) property 3. The control belongs to the worker's
  manifest.
- The writer is an admin: `connection` is set through the dedicated write controller, which
  requires `PERMISSION_ADMIN`, and read through an entity whose `EntityReadPolicy` row is
  `PERMISSION_ADMIN`. Not any authenticated household member, which under
  [ADR-0006](0006-authenticated-issues-in-scope.md) narrows who can reach it without
  excusing it.

The application tier's outbound surface goes to zero, and a smaller, differently shaped
surface appears in a workload whose network policy is a reviewed manifest. The sweep gets a
note recording that, not a waiver.

**Late binding is a narrow departure from migration 0259's self-contained-payload rule.**
That comment argues a consumer re-reading the ledger at delivery time "would compute a
different timestamp on every retry and give a drained backlog the latest stock snapshot
rather than each transaction's own, which is the difference between at-least-once delivery
being safe and being lossy in a new way." That argument is about facts. Printer
configuration is not a fact about the past; it is the current description of a device, and
the failure modes point opposite ways. Re-reading the ledger at delivery loses information.
Embedding the connection at enqueue loses the fix: a queue of jobs that failed because the
media was wrong retries forever with the wrong media, and correcting it means deleting and
recreating every queued job.

The bound: a payload field may be late-bound only if it describes the *delivery device*,
never if it describes *what happened*. The test is whether a person who changed the value
would expect queued work to use the new one. For "the printer moved to a new address", yes.
For "the product was renamed after the label was queued", no — the label records what was
intended when the booking happened, per the constitution's rule that physical artifacts are
contracts. `printer_id` is late-bound; the uid and the rendered text are not.

**Two repositories to release, and a pin between them.** A template fix or a driver bump
is a revision bump and a `flake.lock` change here, which is slower than a one-repository
change. In exchange, imaging bugs do not gate Victual releases and a second driver family
arrives without touching this tree.

**The worker needs no database role, which removes a problem rather than adding one.**
[Plan 20](../plans/20-container-infrastructure.md)'s verification check 8 — "the credential
split is real", recorded as "Not done. Needs a role with no DDL rights; the bootstrap uses
one superuser" — stays a two-role problem instead of becoming a three-role one. What the
worker holds instead is a typed API key whose permission set is bounded by the six routes
it may call.

**A worker writes three kinds of row, and one of them is a schema.** Attempts and status
are per-device bookkeeping. The driver registry is different: a machine identity supplies a
document that governs what an admin may later store. Decision item 3 bounds it structurally
— a registration is append-only, refused when it contradicts a stored one, and refused when
it would invalidate an existing printer row. A compromised or buggy worker can add a driver
nobody uses; it cannot rewrite the rules under configurations that already exist.

**A JSON Schema validator becomes a dependency.** `composer.json`'s eighteen
requirements include none. Two consequences beyond the package: it is the second addition
this fork has made after `php-mqtt/client`, which plan 18's security notes route to the
sweep's dependency review; and a `composer.lock` change moves the fixed-output hash in
`nix/hashes.nix`, one of the two ADR-0013 records as maintained by hand. The alternative is
hand-rolled validation of an arbitrary driver-supplied schema.

**The configuration form is generated, and the tree cannot do that today.**
`Victual.EntityForm` binds fixed fields to an entity; rendering a form from a JSON Schema is
new frontend work, and the largest single cost here. It is what lets a second driver family
ship with no frontend change. The risk is a generator growing to cover schema features
nobody uses, bounded by supporting the subset drivers actually use and rejecting a
registration that exceeds it.

**The render/print seam has two kinds of defect and needs two kinds of check.**
[Issue #90](https://github.com/datagen24/victual/issues/90) records both against the
prototype whose imaging code the worker reuses. The resize defect — the renderer authors
its canvas against `dots_total` while `brother_ql` compares against `dots_printable`, so
every endless print is silently resampled and at 600 dpi comes out roughly 1.9× too long —
is measured, reproducible, and catchable by an assertion spying on `Image.Image.resize`
across `convert()` and requiring zero resize calls, which runs in the worker repository's
CI. The rotation *sign* is not catchable that way: no library default competes with the
prototype's hardcoded `-90`, so the issue states it needs one physical print against the
tape feed direction. Both checks are required.

**Four tables under the migration discipline**, PostgreSQL-only and plain, with no views or
triggers: `label_printers`, `label_drivers`, `label_printer_status` and `print_attempts`.
Four tables because device settings, the schemas that validate them, observed status and
attempt history have four lifetimes: admin-edited, append-only, worker-overwritten and
append-only. The `labels` table ADR-0011 requires is separate and still unowned; this record
does not claim it.

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

Gates, not suggestions. Each is evidence the accepting pull request reports.

1. **The worker packages as an image on no base image.** `brother-ql-inventree` is not in
   nixpkgs, and a packaging failure would invalidate plan 20 piece 5's designation. The
   gate is a built image from a pinned revision of the worker repository through
   `nix/images/lib.nix`, passing `nix flake check` including `image-has-no-shell`, with its
   closure size recorded.
2. **Claim, acknowledge and crash-after-send behave as decision item 6 specifies**, against
   a fake device: two workers cannot hold the same job, an expired lease returns a job to
   the queue, and a worker killed between `bytes_sent_at` and its terminal result produces a
   duplicate label rather than a lost one.
3. **The worker's identity is provisioned and its reach measured.** A `label_worker`-type
   key can register, claim and acknowledge, and is refused on `GET /api/stock` and on
   writing `label_printers`; a `default`-type key is refused on the six label routes.
   Reported as observed responses.
4. **The registry's compatibility rules hold**, against two registered drivers:
   re-registering an existing `(driver_id, schema_version)` with a changed schema is
   refused; a new minor that would invalidate a stored printer row is refused, then accepted
   once that row is corrected; a worker registered at a lower minor than a printer's pinned
   version is not offered that printer's jobs; and a printer whose settings are invalid for
   its driver is refused at configuration time.

## Open questions

1. **What does an evidence row contain?** It attaches to an `attempt_id`; whether it holds
   a decoded uid, an image reference or a confidence value is undecided. *Lean: a decoded
   uid plus an image reference. If a confidence value appears,
   [ADR-0012](0012-observations-are-proposals.md) governs what may be done with it —
   "this label was printed" asserted with a confidence is an observation.*
2. **Retention.** Nothing prunes delivered outbox rows today, by 0259's deferral, and
   `print_attempts` grows faster than the outbox. *Lean: decide both together.*
3. **Template versioning across a pinned-revision bump.** A worker revision that changes
   what a template name means will render queued jobs differently from how they were
   intended. *Lean: the job carries the template name and the worker refuses a name it does
   not know, dead-lettering rather than guessing — the same discipline `PAYLOAD_VERSION`
   applies to payload shapes.*
4. **What the capability document contains, as distinct from the settings schema.** The
   settings schema says what an admin may configure; the capability document says what the
   driver can do, and templates read it — two-colour availability, printable geometry,
   whether the device reports completion. Its shape is undecided. *Lean: let the first
   driver's document be whatever the first template needs, and standardise only the keys a
   second driver family proves general.*
5. **Which JSON Schema dialect, and which subset the form generator supports.** One
   question: the generator's supported subset is what registration enforces. *Lean: a draft
   the chosen PHP library implements, restricted to object schemas of scalar and enumerated
   properties with titles and descriptions, with anything beyond that refused at
   registration.*

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
  therefore passes through it unexamined. `ExposedEntityNoEdit` holds fifteen entities
  today, so reading generically and writing through a purpose-built endpoint is an existing
  pattern.
- Plan 06 is wave 3b in the [plans index](../plans/README.md); wave 3b's row records 03 as
  complete and 06 as remaining, and notes that shared route and spec edits in that wave
  need coordination because 03 took the `ExposedEntity` enums.
- [Issue #90](https://github.com/datagen24/victual/issues/90) measured the resize behaviour
  against the installed `brother-ql-inventree` and is reproducible from the snippet it
  carries; the rotation sign it reports is code-read and the issue states it needs one
  physical print.
