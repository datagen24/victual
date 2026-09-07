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
- **Relies on** two properties of [ADR-0010](0010-workload-standard.md), **accepted
  2026-09-07** and scoped against this record on the way through — see
  *Reliance on ADR-0010* below.
- **Reconciled against [ADR-0021](0021-label-templates-are-application-data.md), 2026-09-07.**
  That record — also Proposed — supersedes ADR-0011's assignment of templates to the drainer,
  so template documents become Victual's and a third component, the headless renderer, takes
  the rasterizer. Decision items 1 through 5 are edited accordingly.
  **Nothing else about this record changes**: the pull transport, the driver registry and
  capability contract, claiming, fencing, leases, the four delivery facts and the
  no-automatic-redispatch rule are untouched. Both records are Proposed and each is accepted on
  its own pull request; neither acceptance implies the other's. Two format-dependent details
  are **outstanding acceptance work**, owed to this record before it is accepted and listed at
  the end of decision item 3.
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
| The printer inventory: persisted instances, the configuration UI, and validation | Driver implementations, and the settings schema and capability document each advertises |
| The registry of driver definitions, and the capability contract they are written against | Nothing about a label's appearance — templates and rendering are owned elsewhere, see below |
| The print job event and its payload contract, the pinned template identity, and the artifact the job carries | Verifying an artifact against the printer's resolved configuration before device I/O |
| The claim/acknowledge/register API and its OpenAPI contract | Device transport (TCP, USB, whatever a driver needs) |
| The attempt, evidence and observed-status records | The worker's own tests, including geometry assertions |
| The flake input pinning the worker's revision, and the image built from it | |

Victual owns **configuration and monitoring**. The worker owns **device contact and driver
implementation**.

**Rendering is a third component, and it is not the worker.**
[ADR-0021](0021-label-templates-are-application-data.md) makes the template document
application data and puts font shaping, layout, QR generation and rasterization in a headless
renderer that reads it. The split is three ways rather than two: Victual owns the template
document, its versions, assets and media profiles; the renderer turns one of those plus
captured fields into an immutable artifact; the worker turns an artifact into device
instructions and reports what happened. The renderer and the worker may share a repository or
a deployment, and their **contracts stay separate** — a render may be retried automatically
precisely because it cannot touch a printer, and no rendering retry may become a second
physical attempt. [Plan 27](../plans/27-label-templates-and-rendering.md) owns the renderer
and the document; this record's worker keeps the device. This preserves ADR-0011's consequence that rendering leaves
this repository: driver quirks and imaging bugs move on their own schedule, and neither is a
reason to cut a Victual release. Template *semantics* no longer travel with them — ADR-0021
moved the document into this repository precisely because a household editing a label's
appearance should not need a worker release — but the code that renders it still does.

The seam is a **capability contract** this repository defines and workers write against. A
worker advertises what its drivers support and accept; Victual holds those documents,
renders configuration from them, validates against them and stores the result. Neither side
hardcodes the other's vocabulary, and neither invents the vocabulary unilaterally.

`flake.nix` gains the worker repository as a pinned input and builds its image from that
revision through `nix/images/lib.nix`, so the artifact carries the same uid, labels and
`nix flake check` assertions as the three existing images. A revision bump is a
`flake.lock` change reviewed like any other, per ADR-0013 decision item 8.

### 2. Transport: an authenticated pull API, not direct database access

**The worker holds no database credential and makes no database connection.** It
authenticates to Victual's HTTP API, advertises what it can drive, and pulls work. Nine
endpoints, all additive. Two of them exist only to get a credential onto a worker:

- `POST /api/labels/pair` — exchanges single-use pairing material for a worker credential.
  The only route reachable without a worker key.
- `POST /api/labels/credentials/rotate` — exchanges a current credential for its successor,
  invalidating the one presented.
- `POST /api/labels/register` — the worker advertises what it carries: each
  `(driver_id, schema_version)`, with the definitions for any Victual has not seen, and the
  **artifact and profile contract versions it accepts**. Per ADR-0021 it advertises no
  template versions, because it carries no templates. Idempotent, append-only in its
  definitions, and refused when it would contradict a stored one — see decision item 3.
- `POST /api/labels/jobs/claim` — the worker asks for up to *n* jobs for the printers it is
  authorized to serve. The response carries, per job, the label uid, the pinned template
  identity, the captured text fields, **the artifact manifest and scoped access to its bytes**
  (ADR-0021), **the printer's configuration resolved now**, an `attempt_id`, and a lease
  expiry.
- `POST /api/labels/attempts/{attempt_id}/heartbeat` — extends the lease while the attempt
  is still running.
- `POST /api/labels/attempts/{attempt_id}/sent` — bytes reached the device.
- `POST /api/labels/attempts/{attempt_id}/result` — terminal outcome, with the device's
  report or the error.
- `POST /api/labels/attempts/{attempt_id}/evidence` — optional observations.
- `POST /api/labels/printers/{id}/status` — observed status: what the device last
  reported. Write-only for workers, and never configuration — see decision item 3.

**Every one of these is authorized against the caller, not merely authenticated** —
decision item 3's *Worker authorization*.

**A worker is a row, not a credential.** `label_workers` holds one row per worker — name,
description, configuration mode, `active` — and `label_printers.worker_id` references it.
Keys are issued against that row, so **rotating or revoking a key does not change the
identity**, and a printer's assignment survives its worker's credential being replaced.
Deactivating the row makes every printer assigned to it unclaimable, which the configuration
screen shows for the same reason it shows a worker that cannot drive a printer.

**Credentials are API keys of a new type**, `ApiKeyService::API_KEY_TYPE_LABEL_WORKER`,
alongside the two existing constants (`services/ApiKeyService.php:15-16`) and the `mcp` type
the [MCP interface spec](../mcp-interface-spec.md) proposes. Keys are already stored as a
SHA-256 hash with a `key_hint` (migration 0264), already carry `key_type` on the `api_keys`
table, and are already validated per route — so a label-worker key is granted and revoked
independently of general API keys, and `ApiKeyAuthenticator` gains one type in its accepted
set for these nine routes only. No new authentication machinery.

#### Two configuration modes, because two deployments

|  | Declared | Paired |
|---|---|---|
| Where it runs | In the cluster, under a manifest | Anywhere that can reach Victual — the USB case |
| How it gets a credential | A Secret, injected as environment | Single-use pairing material in the environment, exchanged once at first start |
| Credential lifetime | Operator-managed; no expiry | Rotates on an interval, with an absolute expiry |
| Durable state on the worker | None | A stored credential, plus a pending rotation record while a rotation is in flight — see *What a worker actually persists* |
| [ADR-0010](0010-workload-standard.md) property 1 | Holds | A stated exception — see *Consequences* |
| Recovery after credential loss | Restart | Re-pair |

**Pairing is one opaque string.** An admin creates the worker row in Victual and is shown
pairing material encoding Victual's own base URL and a single-use secret. The worker takes it
from the environment on first start and exchanges it at `POST /api/labels/pair` for a
credential and a session. One value to transfer rather than an address and a key.

**Environment credentials are not prohibited; the two modes differ in provisioning and
recovery.** A declared worker's credential is injected from a Secret and lives in its
environment for the life of the pod — that is the platform's own mechanism, the operator
rotates it, and a host that can read that environment is already a cluster compromise. A
paired worker sits on a device the operator does not otherwise manage, where the value is
placed by a person or a script and where nobody will notice it sitting in a shell history or
a unit file. Single-use expiring material is what makes that exposure worth little, and it
makes recovery a re-pair rather than a hunt for where a long-lived key was copied to. Same
mechanism, different threat and different recovery — not a rule that environment variables
are unsafe.

**Pairing conveys a Victual credential and nothing else.** No database address, no database
credential, and no broker credential — decision item 2's first sentence is not softened by
the pairing path. A worker that later wants the broker address discovers it from the API it
is already authenticated to.

#### Two clocks: the credential and the session

**A credential's lifetime and a pairing session's lifetime are different things, and only
the second bounds an attacker.** Rotation on its own bounds nothing: whoever holds the
current bearer credential can rotate it themselves and draw successors indefinitely, and if
the legitimate worker is switched off nothing contradicts them. What rotation gives is
detection, and it fires on the *victim's* next rotation rather than on the attacker's.

So there are two expiries:

- **The credential** expires on a short clock and is replaced by rotation. A credential not
  rotated in time stops working; the worker rotates again from its stored one if that is
  still within the session, and re-pairs if it is not.
- **The pairing session** expires on a long absolute clock that rotation does not extend.
  Past it no credential in the chain is honoured and no rotation is accepted — only fresh
  pairing, which requires an admin. **This is the bound**: a stolen credential is usable
  until the session ends however diligently the thief rotates, and the session ending is not
  something a credential holder can postpone.

A session also ends when an admin revokes it, when the worker row is deactivated, or on the
reuse detection below.

#### Rotation is a recoverable exchange

An unrecoverable rotation turns a dropped response into a bricked worker, or — retried
naively — into a revocation of the honest party.

- **The worker generates a `rotation_request_id` and persists it before calling.** Victual
  records that id against the credential it consumed. **A repeat of the same
  `(credential, rotation_request_id)` resolves to the same successor** rather than issuing a
  new one, so a lost response is recovered by retrying the identical request. *How* it resolves
  is not settled — see the hashed-storage problem below.
- **The stored pending rotation names the credential it started from.** On restart a worker
  holding a pending rotation compares it with the credential it has: if they match, the
  rotation did not complete and it retries with the same id; if they do not, the successor
  was already stored and it clears the pending record. A crash anywhere in the exchange
  therefore resolves without guessing.
- **The local replacement is atomic.** The successor is written to a temporary file in the
  same directory, flushed, and renamed over the current one, so the store never holds a
  half-written credential and never holds none.

**Replay conflicts with hashed storage, and this record does not yet say how.** API keys are
stored as a SHA-256 hash with a `key_hint` (migration 0264), which is the property that makes a
database disclosure not a credential disclosure — and it means Victual **cannot** hand back the
successor it issued, because it does not have it. "Returns the same successor" as written is
therefore not implementable against the existing storage. The options are not equivalent and
picking one on paper would be guessing:

- Retain the successor's plaintext briefly against the `rotation_request_id`, which
  reintroduces exactly the exposure hashing removes, for a bounded window.
- Make the successor **derivable rather than stored** — the worker contributes material to the
  exchange so that a replay reproduces the same value without Victual holding it.
- Narrow the guarantee: a replay is answered "this rotation already completed" and the worker,
  unable to obtain the successor, re-pairs. Recoverable, but it turns a dropped packet into an
  admin action, which is the failure mode this section exists to remove.

**Resolving this is part of the rotation acceptance prerequisite** rather than a detail for
implementation, because the answer may change what the storage is. Wave 3b's declared worker
does not rotate, so nothing in that wave is blocked on it.

#### Stale traffic is not reuse

These are different events and conflating them revokes honest workers:

| Event | Response |
|---|---|
| An expired or superseded credential on any ordinary route — `claim`, `heartbeat`, `sent`, `result`, `evidence`, `status`, `register` | **401 and nothing more.** Routine: a request in flight across a rotation, a retry, a worker that has not rotated yet. Never revokes anything |
| A rotation repeating a `rotation_request_id` already recorded against that credential | The same successor, returned again. A retry, by construction |
| A rotation presenting an **already-consumed** credential with a **different** `rotation_request_id` | **Reuse.** The session is revoked, the worker is marked as requiring re-pairing, and the event is surfaced |

The third row is the only suspicious pattern, and it is suspicious because a worker that
already obtained a successor has no reason to rotate its predecessor again under a new id.
The honest case that lands there is a worker restored from an older backup of its store,
which presents a credential the live chain has moved past — and that worker *is* a second
holder of a credential, so revoke-and-re-pair is the right outcome for it too.

**A worker never discards an unreported outcome because a credential was refused.** A 401 on
`result` or `evidence` means retry after rotating, or after re-pairing; it does not mean the
attempt's outcome is lost. `result` is idempotent per attempt and evidence per
`(source, submission_id)`, so a report held across a re-pairing lands exactly once when it
finally arrives.

**That rule is about refusal, not about crashes, and the difference is load-bearing.** An
unreported outcome survives a 401 because the worker still holds it in the process that
produced it. Whether it survives the *process* is a separate question with a different answer
per mode, and stating it as one unconditional promise would be wrong:

- **A declared worker holds nothing durable, so a crash between the device reporting and
  `result` arriving loses that report.** This is correct behaviour rather than a defect: the
  attempt is left *uncertain*, which is precisely the state decision item 6 creates for "nobody
  knows whether a label exists", and a person resolves it by looking at the printer. What the
  record does not claim is that the outcome is never lost.
- **A paired worker may hold a pending report across a restart**, since it already has a store
  for its credential. Whether it does is left to the worker implementation and is not a
  property Victual depends on — the server side behaves identically either way.

#### What a worker actually persists

Two values for a paired worker, not one, and the configuration-mode table above says so:

| Value | When it exists | Why |
|---|---|---|
| The current credential | Always, after pairing | It is what authenticates every route |
| A pending rotation record | Only while a rotation is in flight | It names the credential the rotation started from, so a crash mid-exchange resolves without guessing |

A declared worker persists neither. Its credential arrives from a Secret, it never rotates,
and ADR-0010 property 1 holds for it unconditionally. The paired worker's store is the stated
exception, and it is two values because a recoverable rotation cannot be built on one.

The declared worker does not rotate and has no session clock. Its credential is the
operator's to manage through the Secret, which keeps it stateless; rotation and sessions are
what a worker needs when there is no operator mechanism to do it for it.

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
instances and the validation.** Four kinds of row: the printer instances, the driver
definitions, what each worker advertises, and the observed status. Template definitions are
**not** among them — ADR-0021 makes the template document application data, owned by
[plan 27](../plans/27-label-templates-and-rendering.md) rather than registered by a worker.

#### Common fields stay typed columns

`label_printers` carries, as ordinary columns, exactly what **Victual itself** reads:

| Column | Why Victual reads it |
|---|---|
| `id`, `name`, `description`, `row_created_timestamp`, `active`, `is_default` | Lifecycle and UI, as `shopping_locations` has them |
| `worker_id` | The one worker authorized to serve this printer, referencing `label_workers`. Not nullable |
| `driver_id`, `driver_schema_version` | Validation, and claim-time compatibility |
| `connection` | The outbound destination, kept a column so every address the deployment will dial is auditable in one place. Interpreted by the driver: a TCP endpoint, a USB device path, a queue name. One field rather than host and port, which assumes one transport |
| `model` | Every capability document keys its combinations by model, so the contract makes this field universal. Shown in the UI. The vocabulary is the driver's; the presence is not |

#### Driver-specific settings are a validated document

Everything a *driver* reads and Victual does not — media identity, colour capability, cut
behaviour, darkness, margins — lives in a `settings` JSON document on the same row,
**validated on write against the schema registered for that `driver_id` at that
`driver_schema_version`**. Victual never interprets its contents; it only enforces that
they match what the driver said it accepts.

The configuration form is generated from the registered schema, so adding a driver adds a
form without a frontend change, and an invalid setting a 400 at configuration time rather
than a failed print an hour later.

#### The registry: definitions, and who advertises them

Registration writes two kinds of row, and separating them is what makes exact matching
possible.

**Definitions are immutable and shared.** `label_drivers` holds one row per
`(driver_id, schema_version)`: the settings schema **and** the capability document, both
fixed by that row. It is never rewritten. There is no worker-registered template row: a
template's input contract — which captured fields it consumes — and the capability
requirements it declares live in Victual's own published template version, per ADR-0021.

**Advertisements are per worker and current.** `label_worker_capabilities` records which
`(driver_id, schema_version)` pairs each worker advertises, which **artifact and profile
contract versions** it accepts, and when it last registered. A worker may advertise several
versions of the same driver at once, and dropping one is a registration that no longer lists
it.

The rules on those rows:

- **Driver identity** is a stable namespaced string naming a *contract*, not an
  implementation — `brother.ql`, not `brother_ql` as one Python package spells it, and never
  a version. Two workers may advertise the same `driver_id`; the identity is owned by the
  repository implementing the driver.
- **Re-registering an existing `(driver_id, schema_version)` with a different settings
  schema or a different capability document is refused.** Printer rows were validated
  against the stored schema, and templates were checked against the stored capability
  document, so replacing either would leave stored decisions claiming a validity nobody
  checked. A worker whose schema or capabilities changed publishes a new version. Victual's
  published template versions are immutable for the same reason and by their own rule
  (ADR-0021), which is not this registration's to enforce.
- **A version number is a label, not a compatibility claim.** `major.minor` is a naming
  convention and nothing is inferred from it. A newer minor can validate every printer row
  saved today and still reject a configuration the older schema would have permitted
  tomorrow, so "newer minor serves older printer" is not a property a version number
  establishes. Compatibility comes from a worker advertising the exact version, which is
  a statement it makes about itself rather than an inference Victual draws about it.
- **Moving a printer to a different schema version is an explicit admin action** that
  revalidates its settings against that version and rewrites `driver_schema_version`.
  Nothing adopts a version automatically, in either direction.

**Enqueue validation no longer depends on a worker having registered a template.** Victual
pins one of its own published template versions into every job and refuses a job whose
template requires a capability the target printer's driver does not offer — it holds both
sides of that comparison now, which is the half of this rule ADR-0021 makes simpler rather
than removes.

What registration must still establish is the other half: **that some worker authorized to
serve the target printer accepts the artifact and profile contract versions the job will
carry.** Otherwise a job is enqueued that no deployed worker can consume, and the failure
surfaces as decision item 4's blocked outcome instead of as a refusal at the moment a person
asked for the label. That was the point of the original rule and it survives the change of
what is being matched.

#### The capability contract

The capability document is not free-form. This repository defines a versioned contract and
drivers write against it, so that a template can ask "does this printer do two colours"
without knowing which driver answers.

Version 1 carries:

| Key | Content |
|---|---|
| `connection_types` | The transports the driver accepts: `tcp`, `usb`, `cups` |
| `models` | The device models this driver supports |
| `combinations` | The authoritative list of what actually works — see below |
| `completion_evidence` | What the driver can report: `none`, `transport`, or `device_reported` |

**`combinations` is a list, not the product of several lists.** Independent lists of media,
resolutions and colour modes claim every crossing of them works, which is false of every
printer family: a Brother QL supports red only on `62red` tape, and not at every resolution.
Each entry names a `model`, a `media`, a `resolution_x` and `resolution_y` in dpi, and a
`color_mode` — and carries the geometry for that entry alone:

- `printable_width_um`, in micrometres.
- `printable_length_um`, either a fixed value for die-cut media or a `{min, max}` range for
  endless tape, whose length is a property of the job rather than of the stock.
- `feed_direction`, so orientation is stated rather than inferred.

Horizontal and vertical resolution are separate because they differ on real hardware — a
600 dpi Brother QL is 600 along the feed and 300 across it — and a single `dpi` invites the
caller to assume they are equal.

Explicit units are load-bearing: issue [#90](https://github.com/datagen24/victual/issues/90)
is a geometry defect produced by two components disagreeing about which dot count a
dimension meant, and a contract that says "width: 696" repeats it. A record of an endless
label's length as a range rather than a number is the same defect's other half.

**Supported, configured and observed are three different statements**, and the contract
keeps them apart:

| | Says | Lives in | Written by |
|---|---|---|---|
| Supported | What the driver can do | `label_drivers` capability document | The worker, in a registration |
| Configured | What the admin selected | `label_printers` | An admin |
| Observed | What the device currently reports | `label_printer_status` | A worker, reporting |

Registration advertises support; **it does not prove that an attached printer currently has
the capability available.** A driver supporting `62red` and a printer configured for `62red`
still print nothing when the device reports black tape loaded, and that is an observed-state
failure rather than a configuration error.

**Namespaced extensions are allowed**, as `x-<driver_id>.<key>`, and generic templates
ignore them. **A template declares the capabilities it requires** — in Victual's published
template version under ADR-0021, rather than in a worker registration — and a job is refused
at enqueue when the target printer's driver does not support the combination, not at print
time, where the person who asked for the label is no longer watching.

#### The settings schema subset

The dialect is pinned to what the chosen PHP validator and the form renderer **both**
support; the intersection is the subset, and it is chosen by checking the two together
rather than picking a dialect and discovering the renderer's limits afterwards.

Version 1 accepts an object whose properties are strings, booleans, integers, numbers or
enums, with `required`, numeric and length bounds, `pattern`, `title` and `description`.
Unknown properties in a settings document are rejected. External `$ref` is rejected
outright — resolving one would be a fetch of a schema named by a registration, which is the
class of outbound call this record removes. **Registration rejects a schema using anything
outside the subset**, rather than accepting it and ignoring the parts it cannot handle.

**Flat scalars cannot express which combinations of model, media, resolution and colour are
valid**, and that is the constraint the subset has to answer. Under a pull-only transport
Victual cannot ask a worker to resolve a schema for a selection the admin just made, so any
mechanism has to be registered ahead of time. The registration therefore declares its
**discriminator properties** — typically model and media — and supplies **one resolved
schema per supported combination**. Selecting a model and media selects a schema; no
conditional evaluation is needed in either the validator or the renderer, which is what
keeps the subset flat. A registration whose combination count exceeds a stated limit is
refused rather than accepted and rendered slowly. Bounded `if`/`then` conditionals on
declared discriminators are the alternative and would need conditional support in both
libraries for no gain here, since both mechanisms must pre-register.

**The generated form is an editor, not a gate.** Server-side validation on write and the
worker's own validation before printing are the authoritative checks, and a settings
document that reaches the database through any other path is still validated by both.

#### Worker authorization

**Registering a driver is a capability claim, not an authorization.** It says "I can drive
this"; it does not say "you may send me this household's print content and this printer's
connection details". The two are separate, and only an admin grants the second.

`label_printers.worker_id` is **not nullable**. Every printer references exactly one
authorized worker row, and a printer with no assignment cannot exist, so there is no state in
which any worker advertising the right driver may claim a job. Moving a printer to a spare
worker is an admin edit of that column — an explicit act with a record, rather than a race
between whoever claims first.

A job is offered to a claiming worker when all three hold:

1. The printer is `active`.
2. `label_printers.worker_id` is the caller's worker, and that worker row is `active`.
3. The caller currently advertises the printer's **exact** `(driver_id,
   driver_schema_version)` and is **compatible with the job's artifact and profile contract
   versions**.

   Template-version matching was the second half of this precondition until ADR-0021, and it
   is removed rather than deferred: workers advertise no template versions now, so a
   precondition requiring one could never succeed and would offer no job to any worker. What
   replaces it is the same question asked about what a worker is actually handed — an
   artifact against a profile. **The precise fields compared are settled by ADR-0021's
   prerequisite 2**, since a raster and a page description put different obligations on the
   worker; that it is the artifact and profile contract, and not a template, is settled here.

Every other route is authorized the same way, against the row rather than the key type:

| Route | Authorized when |
|---|---|
| `sent`, `result` | The attempt's `worker_id` is the caller. **Accepted for a superseded or expired attempt too** — a worker may always report what its own attempt did; whether that report completes the job is a separate question decision item 5 answers |
| `heartbeat` | The attempt's `worker_id` is the caller **and** the attempt is the job's current one and has neither expired nor ended. A heartbeat never revives an expired, abandoned or superseded attempt |
| `status` | The caller is the assigned worker of the printer named in the path |
| `pair` | Valid, unexpired, unconsumed pairing material for a worker row that is `active`. No worker key required, and this is the only such route |
| `rotate` | The caller presents its current credential within a live session. A repeat of a recorded `rotation_request_id` resolves to the same successor by the mechanism the rotation spike selects; a consumed credential under a *different* id revokes the session |
| `evidence` | The caller owns the attempt, **or** holds a verifier grant for that printer |
| `claim` | The three rules above |

**Evidence has its own grant.** A camera is not a print worker, so a verifier authenticates
with `ApiKeyService::API_KEY_TYPE_LABEL_VERIFIER`, whose only route is the evidence
endpoint, and is granted per printer by an admin. A worker may also post evidence, but only
about its own attempts — device status it read back from the printer it was talking to. The
`source` field records which of the two submitted the row.

An authorized worker pool — several workers permitted to serve one printer, failing over
between themselves — is the alternative to a single assignment, and it is not taken here.
Automatic failover between workers is the ownership ambiguity decision item 5 exists to
remove, and a spare that requires one admin edit is a cheap price for keeping it removed.

#### Observed status is a separate table, written only by workers

`label_printer_status` holds what the device last reported: `last_seen_at`, the media the
device says is loaded, error or warning state, and the worker that reported it. It is
written only through the status endpoint, and only by the printer's assigned worker; it is
never admin-editable and never read as configuration.

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
- **Unclaimed jobs stay queued, indefinitely, and nothing dead-letters for being
  unclaimed.** Unclaimed age is backlog and is visible; it is not an error. This is the
  failure [ADR-0011](0011-label-namespace.md) fact 2 named — "a printer that is off for a
  day eats a day of labels" — which the outbox removes, and it is distinct from decision
  item 6's rule, which starts at the claim.
  Dead-lettering stays for what 0259 defined it for, a payload no version can read, plus
  decision item 4's deleted-printer case.
- **A printer whose assigned worker cannot drive it is a visible state.** When that worker
  does not advertise the printer's exact driver version, or the artifact and profile contract
  versions a job needs, the job is never offered — and the configuration screen says which of the two
  is missing rather than leaving a queue that grows for no visible reason.

#### Where the three kinds of setting live

| Kind | Set by | Lives in | Example |
|---|---|---|---|
| Device settings | An admin | `label_printers` columns and its validated `settings` | Which tape is loaded; the connection |
| Template settings | The template author | Victual's published template version ([27](../plans/27-label-templates-and-rendering.md)) | The font; rendering the due date in red |
| Observed status | A worker, reporting | `label_printer_status` | The tape the device says is loaded; a paper-out warning |

The rule for placing a value: if a person sets it, it is device settings; if a worker
reports it, it is status; if it describes what a label looks like, it is a template
setting. Nothing crosses. A printer's media being two-colour is a device fact; rendering
the due date in red is a template choice valid only where that fact is true, and the
capability document is how the template learns it.

#### How these entities are reached

All eight tables — `label_workers`, `label_printers`, `label_drivers`,
`label_worker_capabilities`, `label_printer_status`, `print_jobs`, `print_attempts` and
`print_evidence` — 
are added to the OpenAPI `ExposedEntity` enum for reading, to `ExposedEntityNoEdit` and
`ExposedEntityNoDelete`, and each gains a `PERMISSION_ADMIN` row in
`EntityReadPolicy::PERMISSIONS`, which is fail-closed and throws "Entity has no read policy"
for an entity absent from it. Reads go through `GenericEntityApiController` and the UI is a
`Victual.EntityList` list page as `shoppinglocations` is. Nothing is writable there: every
write arrives through a worker route or the dedicated administration controller.

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

#### What ADR-0021 still owes this record before acceptance

Decision items 1 through 5 are reconciled: nothing above depends on a worker registering a
template, and no precondition requires an advertisement that can no longer exist. **Blocking
implementation until both records are accepted would not have made a contradictory accepted
contract safe**, which is why item 5's template-version match was removed outright rather than
left for later — a precondition that cannot succeed offers no job to any worker.

What is left is narrower, and it is **outstanding acceptance work rather than deferred
reconciliation**: two places name the artifact and profile contract without saying what is
compared, because that follows from whether an artifact is a raster or a page description.

| Owed | Where | Settled by |
|---|---|---|
| What the artifact adds to the job payload, and how much geometry the worker still decides | Item 4 | ADR-0021 prerequisite 2 |
| Which fields the claim precondition compares for artifact/profile compatibility | Item 5 | ADR-0021 prerequisite 2 |

**Both must be written into this record before it is accepted**, and neither blocks the work
that settles them: ADR-0021's renderer comparison (prerequisite 1) and its artifact-format
comparison (prerequisite 2) proceed against the template contract without needing these fields
fixed first. The order is comparison, then these two edits, then each record's own
bookkeeping-only acceptance pull request.

### 4. What a job pins, and what it resolves at claim time

The outbox payload pins the label uid, the captured text fields as they stood when the job
was created, `payload_version`, and **`template_id` with an immutable `template_version` or
content digest**. It names a `printer_id` and nothing else about the device — no connection,
no media identity, no settings document.

**Since [ADR-0021](0021-label-templates-are-application-data.md) the job also carries an
immutable artifact, and the two have different jobs to do.** Template identity and captured
fields are **provenance**: they record which design and which values the label was meant to
express, which is what makes a wrong label diagnosable and a revised print distinguishable
from a reprint. The **artifact bytes are the authority**: they are what the worker sends and
what an exact reprint replays, and no rerender may be substituted for them. Where provenance
and bytes could ever disagree, the bytes are what was printed.

That distinction holds whatever the artifact turns out to be. **What the artifact's format
adds to this payload — and how much geometry it leaves the worker to decide — follows
ADR-0021's prerequisite 2**, because a raster and a page description hand the device adapter
different work.

The claim response resolves that printer's current row and returns its typed columns, its
validated `settings`, and the `driver_id` and `driver_schema_version` they were validated
against, so the worker knows which of its builds' expectations apply. A job whose printer
has been deleted or deactivated is dead-lettered with `last_error` saying so, rather than
handed out against a device that is gone.

**A template name alone is not an identity.** A worker can recognise the name while its
implementation has changed underneath, producing a label that differs from the one the
operator asked for with nothing recording that it did. Pinning a version or digest makes the
job say which rendering it meant. Three rules follow:

- **A worker upgrade retains the contract versions queued jobs need**, or the upgrade carries
  an explicit migration of those jobs. Before ADR-0021 the thing a worker had to keep was the
  pinned *template* version; now it is the artifact and profile contract, because that is what
  it is handed. The rule is unchanged in substance: an upgrade may not silently strand work
  that was already queued against it.
- **An unavailable version is a visible blocked outcome, never a fallback to the latest** —
  and after ADR-0021 that covers a missing artifact as much as a missing contract version: a
  job whose artifact has not been validated and attached is not claimable, and one whose bytes
  are gone is refused rather than rerendered.
  The attempt records `blocked` naming the missing version and ends there. Like every other
  failed attempt it does not return the job to the queue: restoring the version makes
  another attempt *possible*, and a person authorizes it. A blocked attempt provably sent no
  bytes, so it is the one failure that could be redispatched without risking a duplicate;
  that remains a future exception to be approved explicitly, as part of the deferred
  automatic-retry decision in item 6, and is not taken here.
- **Reprinting and printing with a revised template are different operator actions.** A
  reprint enqueues a job pinning the same template version and the same captured content; a
  revised print pins the new version and is recorded as a different operation. Neither
  mutates the original job.

### 5. The outbox is reused; attempts and evidence are separate records

Event type `label.print_requested`, in the existing `outbox` table, under the existing
`payload_version` discipline. No second queue.

`print_attempts` records one row per claim: `attempt_id`, `attempt_number`, the outbox row
it belongs to, `worker_id`, `claimed_at`, `lease_expires_at`, `bytes_sent_at`,
`device_reported_at`, outcome, error text, and whether the attempt was superseded. The
outbox row remains the unit of work and is acknowledged by setting `delivered_at`; the
attempt rows are this consumer's record of what it tried.

**`print_jobs` holds this consumer's per-job state, one row per outbox row.** The sections
below give a job an authorization count, a current attempt and an outcome, and none of those
can live where the first draft of this record implied. They cannot go on `outbox`: that table
is shared with [plan 18](../plans/18-mqtt-state-publication.md)'s event type under the "one
outbox schema discriminated by event type" rule this record relies on, and columns meaningful
to one consumer are exactly what that rule exists to prevent. They cannot be derived from
`print_attempts` either — `current_attempt_id` could be, as the highest `attempt_number` for
the job, but `attempts_authorized` is a counter a person increments and an authorization
granted but not yet consumed leaves no attempt row to derive it from. That state is precisely
what distinguishes "waiting for a person" from "waiting for a worker", so it needs a row.

| Column | Content |
|---|---|
| `outbox_id` | The job. Unique, so the one-to-one with the outbox row is enforced rather than assumed |
| `printer_id` | Denormalized from the payload, because the claim query filters by the printers a worker may serve and must do so under the row lock rather than by extracting JSON in the hot path |
| `attempts_authorized` | Starts at 1; only a person increments it |
| `current_attempt_id` | The attempt that may complete the job |
| `outcome` | The job's resolution, distinct from any single attempt's |

A row is written in the same transaction as the outbox row, so a job never exists without its
state. This is a ninth table, and the *Consequences* count below says so.

#### Claiming, leases and fencing

**A job is claimable only for as many attempts as have been authorized.** The job row
carries `attempts_authorized`, which starts at 1. A claim consumes one authorization, so **a
failed or expired attempt does not make the job claimable again**. Authorizing another
attempt increments the count, and only a person does that; decision item 6 says what that
means.

- **A claim has four preconditions, all checked in the claiming transaction.** The job is
  not delivered; it has no attempt that is still running — one with no terminal outcome and
  a lease in the future; the authorization count leaves an attempt available; and the
  authorization rules below have already been satisfied. The first two are stated
  separately from the count rather than derived from it, because a claim path that only
  counts would depend on the authorization rules being correct to stay safe.
- **Consuming an authorization is atomic, and the database enforces it.** `print_attempts`
  carries `attempt_number`, 1-based per job, under `UNIQUE (outbox_id, attempt_number)`. A
  claim runs in one transaction that locks the job row (`SELECT … FOR UPDATE SKIP LOCKED`,
  which ADR-0010 property 2 already names), checks the four preconditions, and inserts
  `attempt_number = count + 1`, recording it as the job's `current_attempt_id`.

  The lock serializes claimers; the unique constraint is what makes the guarantee
  independent of the lock being taken. Two concurrent claims that both computed the same
  `attempt_number` cannot both commit — the second violates the index and fails — so
  "checked the count, then inserted" cannot interleave into two attempts consuming one
  authorization, whatever a future code path forgets to lock.
- **A lease is renewable, up to a bound.** The heartbeat route extends `lease_expires_at`
  while the attempt runs. Renewal stops at a maximum total execution time, past which the
  attempt is `abandoned` whatever the worker believes, so a wedged worker cannot hold a job
  indefinitely by heartbeating. An expiry ends the attempt; it does not return the job.
- **An unresolved job stays undelivered without being retried**, which is a departure from
  what `delivered_at IS NULL` means for the outbox's existing event type. A job whose only
  attempt ended uncertain has no acknowledgment and no further authorization, so it is
  neither delivered nor claimable, and `OutboxService`'s undelivered set is not by itself
  the set of work this consumer will do. Anything reporting backlog for
  `label.print_requested` reads the authorization state, not `delivered_at` alone.
- **A late result is recorded; only a current attempt's result completes the job.** These
  are two rules and conflating them loses one of them. **Recording:** an authenticated
  owner may submit `sent` or `result` for its own attempt at any time, including after that
  attempt expired or was superseded, and the value lands on that attempt's row. Refusing it
  would discard the only account of what the worker actually did, which is the evidence a
  person needs to decide whether a label exists. **Completing:** only the job's
  `current_attempt_id` can acknowledge the outbox row or set the job's outcome. A result
  arriving for a superseded attempt is marked as such on its own row and touches nothing
  else — not the newer attempt's outcome, not the job. `heartbeat` is the exception to the
  recording rule, because it is not a report of what happened but a claim on the future: it
  is refused for an attempt that is not current, has expired, or has ended, so no late
  heartbeat can revive an attempt or extend a lease that has already passed to another.
- **Bookkeeping is idempotent, so a network retry never prints.** `sent`, `result`,
  `heartbeat` and `evidence` may each be delivered more than once. Repeating `sent` or
  `result` for an attempt that already recorded one returns the stored value unchanged rather
  than writing a second. Evidence deduplicates on `(source, submission_id)` under a unique
  constraint — a source-generated identifier, not a natural key, because two genuinely
  distinct observations can share an attempt, a type and a timestamp, and collapsing them
  would discard one. A retried submission carries the identifier it carried the first time
  and lands on the stored row; a second observation carries a new one and is stored beside
  it. None of these routes can enqueue work or authorize an attempt, so retrying bookkeeping
  cannot produce a physical print under any ordering.
- **Authorizing an attempt names the attempt being reviewed, and cannot be banked.** The
  request identifies the failed, blocked or uncertain attempt the operator looked at, and
  one transaction under the job row lock verifies three things: that attempt is still the
  job's `current_attempt_id`, it has ended — a terminal outcome, an expiry, or abandonment
  — and `attempts_authorized` equals the number of attempts already made, so no unused
  authorization is outstanding. Only then is the count incremented.

  Each condition removes a distinct failure. Naming the attempt means an operator authorizes
  a retry of the failure they were shown rather than of whatever has happened since. The
  ended check stops B being authorized while A is still printing, which is the case that
  produces two labels from one job with no fault anywhere in the worker. The
  no-unused-authorization check stops authorizations accumulating into a job that can be
  claimed several times over, and it is also what makes the action idempotent: a
  double-click or a retried request finds the authorization it just created still unused,
  changes nothing, and is answered with the current state rather than an error.

#### `print_evidence` records an observation, not a conclusion

One row per observation, and every row carries all six of:

| Field | Content |
|---|---|
| `attempt_id` | The attempt this observation is about. Required — see decision item 6 |
| `submission_id` | An identifier the source generates, unique per source. What deduplication is keyed on |
| `evidence_type` | The kind of observation: a decoded scan, a device status report, an image |
| `source` | The authenticated identity that submitted it |
| `observed_at` | When the observation happened |
| `received_at` | When Victual took it |

The rest depend on `evidence_type`: `decoded_uid`, `printer_status`, `image_ref`,
`confidence`. All optional, and none is a verdict — the row says what was seen, and decision
item 6 says what may be concluded from it.

**An image is a managed storage reference, never a URL.** It is stored through the existing
file storage under a new `FileGroups` value, and `image_ref` holds that identifier. Victual
does not fetch an address a submitter supplies; sweep finding S14 is the tree's one instance
of that pattern, and it is a finding rather than a precedent.

#### Retention

One policy, several lifetimes. Images are the largest rows and the shortest-lived;
structured attempts and evidence outlive them; outbox rows outlive both.

What retention never removes:

- **Pending jobs, uncertain outcomes and unresolved dead letters**, regardless of age. An
  attempt that never reached a terminal outcome keeps its rows, because that is the history
  someone needs to explain a print nobody can account for.
- **Enough history to explain an unresolved print.** Removing an image leaves its evidence
  row, recording that an image existed and was retained until a stated date. Removing
  evidence leaves the attempt.
- **Label identities and retirement mappings.** ADR-0011's `labels` table is outside
  print-history cleanup: a printed label outlives every deployment, so the mapping that
  resolves it cannot be pruned on a print-history schedule.

**Nothing is removed solely because its parent outbox row was delivered.** Cleanup preserves
referential integrity — no row outlives what it points at — and exact durations belong to
the implementation plan.

### 6. Delivery semantics

A print is not a boolean. Four facts, recorded separately because conflating them hides a
missing label:

| State | Meaning | Recorded as |
|---|---|---|
| **Sent** | The worker wrote the job to the device without a transport error | `bytes_sent_at` |
| **Reported complete** | The device itself reported the job finished | `device_reported_at` plus outcome |
| **Verified** | Optional external evidence about this attempt has been recorded | An evidence row referencing this attempt. Evidence is attached, not adjudicated: it informs a person, it does not resolve a state |
| **Uncertain** | The attempt ended with no terminal result | Neither timestamp reached a terminal outcome. A resting state, not a transient one |

**Sent is not printed.** A device can accept bytes and then jam, run out of tape, or be
switched off mid-job. Where a driver can report completion, the outbox row is acknowledged
on the report; where it cannot, it is acknowledged on send, and the record says which of
the two happened rather than presenting them as the same fact.

**No automatic redispatch after a claimed attempt.** A failed attempt records its error; an
expired attempt is uncertain. Neither makes the job claimable again. A person authorizes
another attempt, naming the ended attempt they reviewed, and both that attempt and its
evidence are preserved beside the new one rather than replaced.

The crash-after-send case is why. A worker killed between writing bytes and posting its
result is indistinguishable from one whose bytes never arrived — the printer may already
hold a label, and nothing in Victual can tell. Redispatching automatically resolves that
ambiguity by guessing, and it guesses in the direction that prints. What a person has and
Victual does not is the ability to look at the printer.

**Queued is not the same as failed.** A job no worker has claimed stays queued while its
worker is offline, indefinitely, and prints when the worker returns. That is the failure
[ADR-0011](0011-label-namespace.md) fact 2 named and the outbox removes it. **The rule here
starts at the claim**, and applies to every attempt from that moment — including one whose
worker died before it reached the printer, and one that ended `blocked` having deliberately
sent nothing. Whether bytes left the worker is not the test, because the state where nobody
knows is precisely the state this rule exists for.

**Automatic retry is deferred, not rejected.** Deciding it needs three things this record
does not have: printer-specific knowledge of what a device does with a truncated job,
validation that delivery reporting is trustworthy enough to distinguish "not sent" from
"sent and unacknowledged", and an explicit policy decision about who bears the cost of a
duplicate. It is outside wave 3b.

**The verifier correlates its observation with an attempt; Victual does not infer the
correlation.** `attempt_id` is required on every evidence row. A uid is stable by
construction under ADR-0011, so a matching uid says a label exists — not which attempt
produced it, and not which of several reprints succeeded. An observer that can read a label
but cannot name the attempt it is checking is not a print verifier, and this endpoint is not
where its observation belongs.

**Confidence never promotes uncertain to confirmed.** A confidence value is a property of an
observation, and no threshold applied to one moves an attempt out of *uncertain*. That state
ends when a worker posts a terminal result or a person resolves it. A missing verification
re-prints nothing automatically — it is a discrepancy for a person, as ADR-0011 makes a scan
of a retired uid.

**Print evidence never books inventory, and it is not automatically an ADR-0012 proposal.**
[ADR-0012](0012-observations-are-proposals.md) governs confidence-bearing claims about
*bookings* — writes to the stock ledger — and "a label came out of the printer" is not one.
The two are separable in the case that mixes them: a camera that reads a shelf and reports
both "this label exists" and "there are three of these here" submits the first as print
evidence and the second as a proposal, and the proposal is ADR-0012's to govern. Should
confidence-based print confirmation ever be wanted, the rule is here rather than borrowed: a
person confirms it, there is no auto-confirm threshold, and confirming a print confirms a
print — it writes no stock.

### 7. Retirement is the destination, sequenced

Full retirement of the webhook is the destination: every entity type prints through this
path, `WebhookRunner` loses its last caller, and the four constants go. The steps have
different prerequisites.

1. **Location labels first, in wave 3b.** [Plan 06](../plans/06-location-barcodes.md) is
   wave 3b in the [plans index](../plans/README.md) and locations have no `/printlabel`
   endpoint today, so this is purely additive: the new entities and their event type, the
   nine worker routes of decision item 2, the printer and worker administration routes, and
   a print action on the locations pages. **No existing response changes.**
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
Embedding the connection at enqueue loses the fix: a queue of jobs enqueued against the
wrong media would print against the wrong media whenever it drained, and correcting it would
mean deleting and recreating every queued job rather than editing one row.

The bound: a payload field may be late-bound only if it describes the *delivery device*,
never if it describes *what happened*. The test is whether a person who changed the value
would expect queued work to use the new one. For "the printer moved to a new address", yes.
For "the product was renamed after the label was queued", no — the label records what was
intended when the booking happened, per the constitution's rule that physical artifacts are
contracts. `printer_id` is late-bound; the uid and the rendered text are not.

**Two repositories to release, and a pin between them.** A driver bump is a revision bump
and a `flake.lock` change here, which is slower than a one-repository change. In exchange,
imaging bugs do not gate Victual releases and a second driver family arrives without touching
this tree. Since ADR-0021 a *template* fix is neither: it is a published version in this
database, made by a person with `ADMIN` and no release at all, which is most of that record's
argument.

**The worker needs no database role, which removes a problem rather than adding one.**
[Plan 20](../plans/20-container-infrastructure.md)'s verification check 8 — "the credential
split is real", recorded as "Not done. Needs a role with no DDL rights; the bootstrap uses
one superuser" — stays a two-role problem instead of becoming a three-role one. What the
worker holds instead is a typed API key whose reach is bounded by the nine routes it may
call and, on each, by the printer or attempt named in the request — and, for a paired worker,
by an expiry.

**A paired worker is stateful.** It departs from
[ADR-0010](0010-workload-standard.md) property 1: it keeps its credential, and while a
rotation is in flight a pending-rotation record beside it — two values, not one, because a
recoverable rotation cannot be built on a single one. Killing it does lose something, and
recovery is re-pairing rather than a restart. The departure is bounded to those values and
confined to deployments with no operator mechanism to inject a Secret; the declared worker
keeps the property in full, and pays for it by being unable to hold an unreported outcome
across a crash.

**Its stored credential is not protected in the sense the word usually carries.** **Encryption whose key lives on the same storage protects against
nothing that copies that storage:** a pulled SD card carries the ciphertext and the key
together, and is readable wherever it is taken. Encryption at rest protects only when the
decryption key stays outside what was copied — hardware-backed storage such as a TPM or a
secure element, or a passphrase supplied at start — and the worker should use one where the
platform offers it. File permissions keep another local user out; they stop nothing that has
the device.

**The session clock bounds the exposure; the store and rotation do not.** A credential on a
device the operator does not control should be assumed readable by anyone who takes the
device. Rotation makes a second holder detectable; the session expiry makes possession finite
whether or not detection fires. The cost is operational: a worker switched off past its
session expiry needs a person to pair it again, which for a seasonally used printer is a real
annoyance rather than a theoretical one.

**A worker writes four kinds of row, and one of them is a definition.** Attempts, status and
evidence are bookkeeping about work it did. A driver definition is different: a machine
identity supplies a document that governs what an admin may later store. Decision item 3
bounds it structurally — a definition is append-only and a registration contradicting a stored
one is refused, so a compromised or buggy worker can publish a driver nobody uses, but cannot
rewrite a definition that existing printer rows were validated against. Template definitions
were the second kind until ADR-0021 moved them out of a worker's reach entirely, which is a
smaller machine-writable surface rather than a differently bounded one.

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

**Eight tables under the migration discipline**, PostgreSQL-only and plain, with no views
or triggers: `label_workers`, `label_printers`, `label_drivers`,
`label_worker_capabilities`, `label_printer_status`, `print_jobs`, `print_attempts` and
`print_evidence`.
Each holds a different lifetime — worker identities and admin-edited instances, immutable
driver definitions, current per-worker advertisements, worker-overwritten status, mutable
per-job authorization state, append-only attempts, and append-only observations with the
shortest retention. `label_templates` was the ninth until ADR-0021 made it Victual's template
identity rather than a worker's registration; it is [27](../plans/27-label-templates-and-rendering.md)'s
now, and this subsystem's migration drops it. It is a large surface for one subsystem, and the cost of keeping
definitions immutable while what workers advertise changes underneath them.

`print_jobs` was the eighth table this record did not name until 2026-09-07, when
[plan 25](../plans/25-label-infrastructure.md) went to write the migration and found decision
item 5 requiring a job row that none of the eight was. The count was wrong rather than the
design: reusing the shared `outbox` for the queue means the consumer's own per-job state needs
somewhere to live, and that is the same conclusion the "contracts may not multiply" rule
reaches from the other direction. Recorded here because a table count that disagrees with the
decision it summarises is how a record stops being usable as a specification.

The `labels` table ADR-0011 requires is
separate; [plan 25](../plans/25-label-infrastructure.md) owns it as of 2026-09-06, and this
record does not claim it.

## Reliance on ADR-0010, accepted 2026-09-07

**This section was written while 0010 was Proposed and is kept rather than deleted**, because
the reason each reliance was defensible without 0010 is the reason it is still defensible if
0010 is ever superseded. Two of its properties carry weight here:

- **"Consumers may multiply; contracts may not"** — the reason decision item 5 reuses the
  outbox rather than minting a queue. This rule has two homes that are not 0010: the
  [constitution](../constitution.md)'s workload-standard section states it, and migration
  0259's comment implements it in the tree with a table that exists. Relying on it is not
  relying on a proposal alone.
- **Unprivileged, with its own identity and least privilege for one job** — the reason the
  worker gets a typed API key rather than a general one, and the reason its outbound
  reach is a manifest property. This is also in the constitution's workload standard, and
  its concrete form for the existing images is ADR-0013 decision item 6.

Both reliances were written as arguments for accepting 0010 rather than as claims that it was
accepted, and **0010 was accepted on 2026-09-07**, so they are now reliance on a binding
record. What that changes is smaller than it looks: the independent grounding above still
holds, so a future record superseding 0010 does not by itself unseat decision items 2 and 5 —
they would fall back to the constitution, which is a weaker but not empty basis. Neither was
ever load-bearing for decision item 3: whether printers are master data does not depend on
0010 at all.

Two of 0010's properties bind this record rather than merely supporting it, and both are
already discharged in the decision above: the worker is unprivileged with its own identity
(a typed API key over nine routes), and it is declared — an image in this flake with probes
and limits, per decision item 1. The paired mode's departure from property 1 is named as an
exception in *Consequences*, which is the form 0010 now requires an exception to take.

**0010 was revised in light of this record, and the two no longer read as contradicting
each other.** Its decision item 3 said "its own database role" without qualification,
which this worker cannot satisfy — it makes no database connection at all — and its
property 1 said "stateless" the same way, which the paired configuration mode above
cannot satisfy either. 0010 now scopes the first to workloads that hold a database
connection (a worker with none is outside the property's scope, not a violation of it)
and states the second as departable by name in the record proposing the exception, citing
the paired worker above as that instance.

**One discrepancy survives that crossing and is this record's to carry, not 0010's.** 0010's
accepted text describes the paired worker as keeping "one durable value, its own credential".
That was true of this record when 0010 was written and is no longer true of it: an acceptance
review on 2026-09-07 found that a recoverable rotation cannot be built on a single stored
value, so the paired worker persists **two** — its credential, and a pending-rotation record
while an exchange is in flight. This record was corrected; 0010 is Accepted and is not edited
here, and it does not need to be for its decision to hold. What 0010 decides is that a
departure from property 1 is stated by name in the record proposing the workload, with the
value held, why no operator mechanism covers it, and what recovery looks like. That is
satisfied by two values exactly as it was by one, and *What a worker actually persists* above
is where the count is authoritative. If 0010's illustration is ever restated, it is a
superseding record's business.

Neither amendment weakens 0010's reliance value
here: it is the same standard, stated so that this record's two departures are the
argued exceptions they were always written to be, rather than a silent conflict between
two Proposed records each assuming the other would give way.

## Acceptance prerequisites

Gates, not suggestions. Each tests a decision this record makes and is **a disposable
spike**: throwaway code on a scratch branch, kept only until it has answered its question,
and not the beginning of the implementation. Delivery verification — working registration,
a generated form, authentication and authorization end to end, a real upgrade — belongs to
the plan that owns this work, which does not exist yet. Putting it here would require the
subsystem to be built before the architecture authorizing it is accepted.

1. **The worker packages as an image on no base image.** `brother-ql-inventree` is not in
   nixpkgs, and a packaging failure would invalidate plan 20 piece 5's designation. A built
   image from a pinned revision through `nix/images/lib.nix`, passing `nix flake check`
   including `image-has-no-shell`, with its closure size recorded. The pinned revision may
   be a scratch branch.
2. **Claiming, fencing, pairing and crash-after-send behave as decision items 2, 5 and 6
   specify.**
   Against a fake device, in throwaway code: a heartbeat extends a lease and the bound ends
   it; a failed or expired attempt leaves the job unclaimable until an attempt is
   authorized; authorization is refused while an attempt is still running, and refused again
   while an authorization it already granted is unused, so authorizations cannot accumulate;
   two concurrent claims against one authorization produce one attempt, with the loser
   refused rather than queued behind it; a late result from a superseded attempt is
   **accepted and recorded on its own row** while completing nothing, and a late heartbeat
   for the same attempt is refused; repeated deliveries of `sent`, `result` and `evidence`
   change nothing after the first; **a worker killed between `bytes_sent_at` and its
   terminal result leaves a visible uncertain job and produces no second print**; and
   pairing material is consumed by its first use, and a rotated credential stops working.
3. **Rotation survives its failure modes, and stale traffic is not treated as theft.**
   **First, the replay mechanism is chosen** from the three options in *Rotation is a
   recoverable exchange* and written into this record, because "returns the same successor" is
   not implementable against hashed key storage and the choice may change what that storage is.
   Then, in the same spike: a lost rotation response retried with the same
   `rotation_request_id` recovers under whichever mechanism was chosen, without issuing a
   second successor; a worker killed before storing the
   successor recovers to exactly one credential on restart; an old heartbeat or result
   arriving after a rotation is refused with a 401 and revokes nothing; a consumed credential
   presented under a different `rotation_request_id` revokes the session; and no path through
   any of these produces another print.

   Note the last clause is deliberately not "loses an attempt's outcome". A declared worker
   holds nothing durable, so a crash before it reports can lose that report — leaving an
   uncertain attempt, which is the designed outcome. What the spike must show is that no path
   loses an outcome *to a credential refusal*, which is the promise this record actually makes.
4. **The schema subset is fixed against a named validator and a named form renderer.** Both
   libraries are chosen, and the subset is the intersection they both support, established
   by trying the model/media case against the pair rather than by reading two feature lists.
   Recorded as the subset, not as a form generator.
5. **The capability contract version 1 expresses two real driver families.** Brother QL and
   one other, written out on paper against the contract, including an endless-tape length
   range, asymmetric horizontal and vertical resolution, and a colour mode available on only
   some combinations. A key the exercise shows is missing amends the contract before
   acceptance rather than after.

## Open questions

The boundaries above are decided; these are the values inside them, and they belong to the
implementation plan rather than to this record.

1. **Which plan owns delivery.** No plan does today —
   [22](../plans/22-medication-tracking.md) question 6 declined the label machinery and
   [06](../plans/06-location-barcodes.md) covers the locations half only. The verification
   these gates deliberately exclude has to land somewhere, and a plan is where.

   > **Answered 2026-09-06 by [25](../plans/25-label-infrastructure.md)**, written the same
   > day as this record and in another branch, which is why neither knew of the other. 25 owns
   > the machinery, is scheduled into wave 3b, and carries the delivery verification these
   > gates exclude — including the two checks no spike can stand in for: a physical label
   > printed on the QL-820NWBc, and that label scanned back to the correct location by an
   > authorized user. 06 depends on 25's first usable release. 22 question 6 is annotated with
   > the same answer.
2. **The per-type evidence fields.** Decision item 5 fixes the six required fields and the
   rule that images are storage references. Which optional fields each `evidence_type`
   requires, and what a `printer_status` observation contains, follow from what the first
   verifier can actually report.
3. **The credential lifetime and the session lifetime.** Decision item 2 fixes the two
   clocks, the recovery rules and what counts as reuse; the durations are open. The session
   length is the one that matters, since it and not rotation is what bounds a stolen
   credential, and it trades that bound against how often a seasonally used printer needs an
   admin to pair it again. Neither number has evidence behind it yet.
4. **Retention durations.** Decision item 5 fixes the policy shape — several lifetimes, what
   is never removed, and referential integrity. The numbers need a measured growth rate for
   `print_evidence` images, which no deployment has yet.
5. **What a second driver family shows the capability contract is missing.** Gate 4
   exercises two families on paper; a second family in service is what shows whether a key
   is Brother-shaped or whether one is absent. The contract is versioned so that finding out
   is a version bump rather than a redesign.
6. **Automatic retry, and whether any failure class earns an exception.** Decision item 6
   defers it and names what deciding it needs: printer-specific behaviour under a truncated
   job, validated delivery reporting, and a policy decision about who bears a duplicate. The
   `blocked` outcome is the strongest candidate for an early exception, since it provably
   sent no bytes. Outside wave 3b either way.

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
- The files API's `FileGroups` enum in `victual.openapi.json` holds five values
  (`equipmentmanuals`, `recipepictures`, `productpictures`, `userfiles`, `userpictures`) and
  is allow-listed on every file route; sweep finding S14 is the one place the tree fetches a
  URL a plugin returned, and it is recorded as a finding. Checked 2026-09-06.
- Plan 06 is wave 3b in the [plans index](../plans/README.md); wave 3b's row records 03 as
  complete and 06 as remaining, and notes that shared route and spec edits in that wave
  need coordination because 03 took the `ExposedEntity` enums.
- [Issue #90](https://github.com/datagen24/victual/issues/90) measured the resize behaviour
  against the installed `brother-ql-inventree` and is reproducible from the snippet it
  carries; the rotation sign it reports is code-read and the issue states it needs one
  physical print.
