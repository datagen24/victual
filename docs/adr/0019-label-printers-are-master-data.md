# ADR-0019: Label printers are master data, and the drainer reads its configuration at delivery time

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-06.
- **Relationship:** supplies the configuration model
  [ADR-0011](0011-label-namespace.md) decision item 4 left unspecified. 0011 decided that
  printing becomes an outbox a drainer consumes and that the four
  `VICTUAL_LABEL_PRINTER_*` constants are retired; it did not say where the printer's
  address, media and rendering parameters live once they are no longer constants. This
  record answers that, and answers it in a way that makes the drainer's own configuration
  a database read rather than an image rebuild.
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
manifest change and a pod restart. A printer's IP moves when the router reassigns it. The
tape in it changes when a roll runs out. Neither is a deployment event, and neither should
require one.

**There is no instance-level settings table to put them in.** `Setting()` and the
`user_settings` table are the whole of configuration in this tree: one is a constant, the
other is per-user preference. A printer is neither. It is a thing the household owns,
exactly as a shopping location is, and `shopping_locations`
(`db/pgsql/baseline/01_tables.sql:308`) is the shape it should take —
`id`/`name`/`description`/`row_created_timestamp`/`active`, served through
`GenericEntityApiController` because it is named in the OpenAPI `ExposedEntity` enum, and
edited through `Victual.EntityList` / `Victual.EntityForm`
(`public/viewjs/shoppinglocations.js`).

**ADR-0011 moved the print path but left its configuration unowned.**
[ADR-0011](0011-label-namespace.md) was accepted 2026-09-04: label payloads are
`vctl:<uid>`, printing becomes an outbox a drainer consumes, rendering leaves this
repository, and the webhook is retired. Nothing of it is built.
[Plan 22](../plans/22-medication-tracking.md) question 6 declined to own the machinery —
its lean is that medication ships without labels rather than a medication plan quietly
becoming the label subsystem's owner — so the `labels` table, the print outbox event and
the drainer have no owning plan today. That is why this record exists as a record rather
than as a plan section.

**The outbox already exists, and it already states the rule this record must follow.**
`migrations/0259.{pgsql,sqlite}.sql` created a generic `outbox` table —
`event_type`/`payload`/`delivered_at`/`dead_lettered_at`/`attempts`/`last_error` — and its
comment is explicit that it "is deliberately not an InfluxDB table: the point of the
contract is that consumers may multiply while contracts may not, so a second consumer
attaches by reading its own `event_type` rather than by minting a table of its own."
`services/Outbox/OutboxService.php` implements that, with one event type
(`stock.transaction_booked`) and a `PAYLOAD_VERSION` consumers refuse to guess past.

**The drainer is a fork-shipped image, and that is already decided.**
[Plan 20](../plans/20-container-infrastructure.md) piece 5 designates ADR-0011's print
drainer, plan 18's MQTT publisher and the MCP sidecar as images in this flake, taking uid,
labels, checks and manifest shape from `nix/images/lib.nix`. The MCP sidecar is a separate
repository by [02](../plans/02-mcp-endpoint.md) question 1's recorded response; the drainer
is not, and this record does not reopen that.

## Decision (proposed)

**Label printers are rows in a `label_printers` table, and the print drainer resolves a
job's printer configuration when it drains rather than when the job was enqueued.**

1. **`label_printers` is master data in the shape of `shopping_locations`.** One row per
   physical device, with `id`, `name`, `description`, `row_created_timestamp` and `active`
   as that table has them, plus the columns a printer driver reads: `model` (the
   `brother_ql` model identifier), `host`, `port`, `label_size` (the tape or die-cut
   identifier, e.g. `62` or `62red`), `dpi`, `two_colour` and the per-element colour
   choices, the font family and size fields today's `LABEL_PRINTER_PARAMS` carries, the
   short-date threshold in days, and `is_default`.

   It is reached the way every other master-data entity is. `label_printers` is added to
   the OpenAPI `ExposedEntity` enum and to `ExposedEntityEditRequiresAdmin`, so
   `GenericEntityApiController` serves `/api/objects/label_printers` and requires
   `PERMISSION_ADMIN` on write. It also gains a row in
   `EntityReadPolicy::PERMISSIONS`, which is fail-closed — an entity absent from that map
   throws "Entity has no read policy" — and that row is `PERMISSION_ADMIN` as well,
   because the columns are a network address and nothing in the application reads them.
   The UI is a `Victual.EntityList` list page and a `Victual.EntityForm` form, as
   `shoppinglocations` is.

   The migration is PostgreSQL-only. The SQLite line is frozen at 0265 by
   [plan 24](../plans/24-sqlite-runtime-retirement.md), so ADR-0011's *Consequences*
   paragraph calling the label tables "dual-engine liabilities" no longer describes what
   it would cost to add them. That is an observation about a cost that has since gone
   away, not an edit to that record.

2. **A print job names a printer; the drainer resolves it at delivery.** The outbox row's
   payload carries the label uid, the template name, the rendered text fields as they
   stood when the job was created, and a `printer_id` — not a host, not a port, not a
   label size. When the drainer takes the row it reads that printer's current row and
   prints with it. A job whose printer has been deleted or deactivated is dead-lettered
   with `last_error` saying so, rather than retried against a device that is gone.

3. **The outbox is reused, with the event type `label.print_requested`.** No second queue
   table. This is the second consumer 0259's comment anticipated, attaching by event type,
   and it carries its own `payload_version` under the same refuse-what-you-cannot-read
   discipline `OutboxService` already applies. `OutboxService`'s existing rule that nothing
   enqueues unless the consumer for that event type is configured on applies unchanged: an
   installation with no active printer row enqueues nothing.

4. **Rendering stays out of this repository.** ADR-0011's consequence holds without
   amendment. The application stores which printer and what text; the drainer decides
   appearance. Template *definitions* live in the drainer; the job row names one.

5. **`VICTUAL_LABEL_PRINTER_WEBHOOK`, `_RUN_SERVER`, `_PARAMS` and `_HOOK_JSON` are
   retired**, per ADR-0011 decision item 4. They also leave
   `SystemApiController::EXPOSED_SETTINGS` (`controllers/Api/SystemApiController.php:46-49`),
   which is a wire change — see *Scope* below.

### What stops `label_printers` becoming a settings framework

Nothing in the table is a key/value pair. Every column is a typed property of a physical
device, and the test for admitting a new one is whether a printer driver reads it: if no
driver does, it is not a printer column. Configuration that is not a property of a device
stays where it is — instance-wide behaviour in `Setting()` constants, per-person
preference in `user_settings`. This record creates one master-data entity and no mechanism;
a second device class would be a second table argued on its own merits, exactly as
`shopping_locations` and `locations` are two tables rather than one `places` table with a
`kind` column.

## Scope

**Option B: all five label endpoints move, and the webhook is deleted.** The five
`*/printlabel` routes — products, stock entries, recipes, chores, batteries
(`routes.php:239-240,256,265,276`) — become job receipts, `WebhookRunner` loses its only
caller, and the drainer is QR-only because no `grcy:` code is ever emitted again. The
alternative considered and rejected is in *Options considered*.

**This changes the wire, and that requires arguing an exception to
[ADR-0005](0005-wire-contract-is-the-invariant.md) rather than assuming one.** Two changes:

- **The five `/printlabel` responses.** Each currently returns the webhook payload it
  built: `product`, `grocycode`, `details`, the stock-entry variant's `stock_entry` and
  conditional `due_date`, merged with `VICTUAL_LABEL_PRINTER_PARAMS`
  (`controllers/Api/StockApiController.php::ProductPrintLabel` and `::StockEntryPrintLabel`,
  and the same shape in `RecipesApiController`, `ChoresApiController`,
  `BatteriesApiController`). A job receipt is a different object.
- **`GET /api/system/config`** loses four keys from its allowlist.

**A no-wire-change narrowing is not actually available**, which is the part that has to be
said rather than left for the accepting PR to discover. The `grocycode` field's *value* is
already forbidden by ADR-0011 decision item 3 — grocycode is emitted never — so the three
candidate paths are: keep emitting `grcy:` from `/printlabel` only, which contradicts an
accepted record; return `vctl:<uid>` under the key `grocycode`, which keeps the key and
changes what it means, so a client that renders that value itself would print a DataMatrix
of a `vctl:` payload and produce a physical artifact in the wrong symbology; or change the
response. The constitution's rule that physical artifacts are contracts makes the second
the worst of the three, so the choice is between contradicting 0011 and changing the wire.

**ADR-0005 has two accepted exceptions and one that was withdrawn**, and all three are
cases where the two engines disagreed and one side had to be named conforming. This is not
that. It is a fork-initiated redesign of a response, which is a different kind of change
and should be argued as one rather than filed as a third row under a record about engine
disagreement. Whether it belongs in 0005 at all, or in a record of its own, is the
accepting PR's to settle.

**The blast radius is small by a check that has already been run, not by inspection.**
ADR-0011's acceptance checked [plan 17](../plans/17-ecosystem-clients.md)'s client
catalogue for anything that *generates* Grocycodes and found neither tracked client does:
Grocy-SwiftUI scans them, the Home Assistant integration does not model them. The
`/system/config` half needs its own check of the same catalogue — the Home Assistant
integration does read that endpoint — and that check is an acceptance prerequisite below
rather than a claim made here.

## Options considered

### Where printer configuration lives

**A. Keep it in `Setting()` constants, renamed.** Zero schema. Keeps every property of a
physical device in a file that requires a pod restart to change, and cannot express more
than one printer at all. Rejected on the deployment argument in *Context*.

**B. A general instance-settings table.** A `settings` table of typed key/value rows, with
printer keys as its first tenant. It would answer this question and several later ones at
once, which is exactly the problem: the first tenant of a general mechanism sets its
semantics — scoping, defaults, precedence against environment variables, who may read
which key — and a printer is a poor specimen to design those against. Rejected as premature;
if a general settings table is ever wanted, `label_printers` is not evidence against it and
does not have to migrate into it.

**C. A `label_printers` master-data table.** The proposal. One entity, the same CRUD and UI
machinery every other master-data entity already uses, no new mechanism, and multiple
printers fall out of it rather than being designed in.

### How much of printing moves

**A. Locations only.** [Plan 06](../plans/06-location-barcodes.md)'s locations take the new
path; products, stock entries, chores, batteries and recipes keep the webhook. No wire
change, and no ADR-0005 argument to have. The costs are two: two printing subsystems exist
indefinitely, each with its own configuration model and its own failure behaviour; and the
drainer must render DataMatrix for the surviving `grcy:` codes as well as QR, which puts
`treepoem` and Ghostscript into an image built on no base image. That is a materially
larger closure and a second rendering path, bought to avoid a wire change on five endpoints
that produce a payload for a webhook this record deletes.

**B. Full retirement.** All five entity types move to `vctl:` through the outbox, the
webhook and its four constants are deleted, and the drainer is QR-only. The proposal. It
costs the ADR-0005 argument above and it is the reason this record has an acceptance
prerequisite about it.

## Consequences

**The application tier makes no outbound HTTP call at all.** `WebhookRunner` currently
fires from five endpoints server-side, and from the browser when
`LABEL_PRINTER_RUN_SERVER` is false. Both go. The security sweep's line that "webhooks
target only the `VICTUAL_LABEL_PRINTER_WEBHOOK` constant … no user-configurable outbound
URL exists, so no SSRF beyond S14" (`docs/security-sweep.md`) becomes wrong in its
subject rather than its conclusion, and needs rewriting in the same change that lands the
retirement.

**A database-configured printer address is a user-configurable outbound destination, and
AGENTS.md warns against exactly that.** Stating the net position rather than waiving it:

- What is removed is an HTTP call, made by the request-handling tier, to a URL, with the
  response returned to the caller. What is added is a raw TCP connection to a host and
  port, made by a separate process that handles no requests, to a destination no HTTP
  request can influence, whose response never reaches an API caller. There is no scheme,
  no path, no redirect following, and no reflected body — the classes of SSRF that make a
  webhook target dangerous do not have an analogue here.
- The writer is an admin (`ExposedEntityEditRequiresAdmin` plus the
  `EntityReadPolicy` row), not any authenticated household member. Under
  [ADR-0006](0006-authenticated-issues-in-scope.md) that is a narrowing of who can reach
  it relative to "any authenticated user", not an excuse for it.
- What genuinely widens: before, changing the outbound destination required editing
  `config.php`, a `settingoverrides` file or the environment. After, it is an API write
  by an admin. An admin-level compromise therefore yields a destination the drainer will
  connect to, where previously it yielded nothing.
- The control that answers this belongs to the drainer, not to the application: it is a
  declared workload with its own network policy and its own identity, and restricting
  what it may dial is a manifest property. That is where the constraint should live and
  where the accepting PR should say it lives.

The sweep therefore needs a note recording the new destination and its shape. It does not
need a waiver, and this record does not ask for one.

**Late binding departs from what migration 0259's comment argues for, deliberately and
narrowly.** That comment makes the case for self-contained payloads: a consumer that
re-read the ledger at delivery time "would compute a different timestamp on every retry and
give a drained backlog the latest stock snapshot rather than each transaction's own, which
is the difference between at-least-once delivery being safe and being lossy in a new way."
That argument is about facts. Printer configuration is not a fact about the past; it is the
current description of a device. The failure modes point opposite ways:

- Re-reading the ledger at delivery loses information — the retry no longer knows what the
  first attempt would have sent.
- Embedding the printer address at enqueue loses the fix — a queue of jobs that failed
  because the label size was wrong retries forever with the wrong label size, and correcting
  it means deleting and recreating every queued job.

The bound, so this does not generalise into "consumers may re-read anything": a payload
field may be late-bound only if it describes the *delivery device*, never if it describes
*what happened*. The test is whether a person who changed the value would expect queued
work to use the new one. For "the printer moved to a new address", yes. For "the product
was renamed after the label was queued", no — the label records what was intended when the
booking happened, and the constitution's rule that physical artifacts are contracts is why.
Concretely: `printer_id` is late-bound, the uid and the rendered text are not.

**The drainer needs its own PostgreSQL role, and it needs read access to two tables.**
`outbox` (select, and update to acknowledge) and `label_printers` (select). It needs
nothing else, and specifying that is the same work as
[plan 20](../plans/20-container-infrastructure.md)'s verification check 8, which is
recorded as "Not done. Needs a role with no DDL rights; the bootstrap uses one superuser."
The drainer makes that a third role rather than a second, which is a reason to do it once
properly rather than a new problem.

**The render/print seam needs an assertion, not a physical label, as its test.**
[Issue #90](https://github.com/datagen24/victual/issues/90) records a measured defect in
the prototype print server whose imaging code the drainer is expected to reuse: on endless
tape the renderer authors its canvas against `dots_total` while `brother_ql` compares
against `dots_printable`, so every endless print is silently resampled, and at 600 dpi the
label comes out roughly 1.9× too long. The library logs a resize warning on every print and
the prototype does not surface it. This is not an architectural decision, and it is not
this record's to fix. It is evidence for how the seam is verified: the assertion that would
have caught it is "spy on `Image.Image.resize` across `convert()` and assert zero resize
calls", which costs nothing and runs in CI, whereas the defect survived to a physical
label.

**One more table under the migration discipline**, PostgreSQL-only, plain, with no views or
triggers. The `labels` table ADR-0011 requires is separate and still unowned; this record
does not claim it.

## Reliance on ADR-0010, which is Proposed

Two properties of [ADR-0010](0010-workload-standard.md) carry weight here, and this record
does not treat a proposal as binding:

- **"Consumers may multiply; contracts may not"** — the reason decision item 3 reuses the
  outbox rather than minting a `print_jobs` table. This rule has two homes that are not
  0010: the [constitution](../constitution.md)'s workload-standard section states it, and
  migration 0259's comment implements it in the tree with a table that exists. So relying
  on it is not relying on a proposal alone.
- **"Its own credential, its own database role, least privilege"** — the reason the
  drainer's role is specified rather than inherited. This also appears in the
  constitution's workload standard, and its concrete form for the existing images is
  ADR-0013 decision item 6.

Both reliances are arguments for accepting 0010, not claims that it is accepted. If 0010
is rejected, decision items 2 and 3 need re-arguing on the constitution alone, which is a
weaker but not empty basis. Neither reliance is load-bearing for decision item 1: whether
printers are master data does not depend on 0010 at all.

## Acceptance prerequisites

Gates, not suggestions.

1. **Option A versus B is decided and written into this record by the accepting PR.** The
   argument above is for B. The scope decision is the maintainer's, and it determines
   whether the drainer needs a DataMatrix rendering path at all.
2. **The ADR-0005 exception is argued and accepted, or B is narrowed until no wire shape
   changes.** If it is argued, the accepting PR states whether it belongs in 0005 or in a
   record of its own, and names what the five `/printlabel` responses become. If it is
   narrowed, it says how, given that the `grocycode` field's current value is already
   forbidden by ADR-0011.
   The `/system/config` half carries its own check: plan 17's catalogue is read for any
   tracked client that reads `LABEL_PRINTER_*` from that endpoint, the way ADR-0011's
   acceptance read it for Grocycode generation.
3. **The drainer's PostgreSQL role is specified** — the grants it holds, on which tables,
   and how it is provisioned. This is plan 20 verification check 8's problem with one more
   role in it, and that check is currently open.

## Open questions

1. **Does the drainer keep an HTTP surface?** *Lean: a health probe, which
   [ADR-0010](0010-workload-standard.md)'s fourth property requires anyway, plus a preview
   endpoint a person hits directly to see what a template renders. The application never
   calls the drainer — if it does, the outbound call this record removes comes back under
   a different name.*
2. **Where does per-printer configuration end and per-label-kind configuration begin?**
   The short-date threshold is the case that does not obviously belong to a device.
   *Lean: a `template` string on the job row, with template definitions living in the
   drainer, so the configuration screens stay a device inventory and do not grow into a
   label designer.*
3. **One printer or many?** *Lean: the schema supports many, the installation ships one
   marked `is_default`, and no per-print picker is added until somebody wants one. Whether
   one drainer serves several printers or one drainer runs per printer is settled when the
   drainer is built; the schema does not care.*
4. **Python packaging risk.** `brother-ql-inventree` is not in nixpkgs, and the image is
   built on no base image under [ADR-0013](0013-nix-built-container-images.md). *Lean:
   build it early. It is the highest-risk item in the sequence, and it is the one that
   would change the answer to "the drainer is an image in this flake" if it turns out to be
   expensive.*

## Research

- Tree facts measured on the working copy of 2026-09-06: the four `LABEL_PRINTER_*`
  settings (`config-dist.php:226-229`) and their reach into
  `SystemApiController::EXPOSED_SETTINGS`; the five `*/printlabel` routes
  (`routes.php:239-240,256,265,276`) and the payload each builds; `Setting()` and
  `DefaultUserSetting()` in `helpers/extensions.php`; `shopping_locations`
  (`db/pgsql/baseline/01_tables.sql:308`) as the master-data shape;
  `EntityReadPolicy::PERMISSIONS` and its fail-closed default;
  `GenericEntityApiController`'s use of the `ExposedEntity*` enums; the `outbox` table and
  `OutboxService`'s event-type and payload-version discipline.
- [Issue #90](https://github.com/datagen24/victual/issues/90) measured the resize behaviour
  against the installed `brother-ql-inventree` and is reproducible from the snippet it
  carries; the rotation sign it reports is code-read and needs one physical print.
