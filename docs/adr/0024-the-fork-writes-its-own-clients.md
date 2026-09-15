# ADR-0024: The fork writes its own clients; the five `/printlabel` endpoints move to the label subsystem

- **Status: Proposed.** Records a decision the maintainer took on 2026-09-15 and the
  consequence it has for one accepted record: it dissolves the gate
  [ADR-0019](0019-label-printers-are-master-data.md) decision item 7 placed on its step 2.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-15.
- **Supersedes in part:** [ADR-0019](0019-label-printers-are-master-data.md) decision item
  7, the sentence *"this is the step that changes the wire, and the resolution below gates
  this step"*. Steps 1 to 3 stand; the gate on step 2 does not.
- **Relationship:** [ADR-0011](0011-label-namespace.md) decided that Grocycode is
  read-only and that every new label is a `labels` row with an opaque uid; this record
  changes nothing there and relies on it. [ADR-0005](0005-wire-contract-is-the-invariant.md)
  is the engine-parity rule and stays the overriding rule of the porting work; this record
  narrows what "the wire" is protected *for*, not whether it is protected.
- **Referenced by:** [32 — Label kinds](../plans/32-label-kinds.md), which owns the work;
  [25](../plans/25-label-infrastructure.md), whose mechanism plan 32 extends;
  [17 — Ecosystem clients](../plans/17-ecosystem-clients.md), whose premise this replaces;
  the manual's [label printing](../manual/operator/label-printing.md) chapter.

## Context

When the fork was planned, keeping upstream Grocy's clients working was a goal. Plan 17
catalogued the couplings, ADR-0005 made the JSON on the wire the invariant of the engine
port, and ADR-0019 sequenced the label retirement so that the five existing `/printlabel`
endpoints — products, stock entries, recipes, chores, batteries — would migrate only after a
wire-contract record decided what they return once `grcy:` may no longer be emitted.

That goal has since been abandoned. As the fork dug under the covers, the maintainer decided
to write its own clients rather than carry upstream's. Two facts follow that the corpus did
not yet say:

- **No external client has a recognised compatibility commitment.** This is a support
  policy, not a claim about non-use: no third-party client is one this project promises to
  keep working, and every planned Victual client will be maintained as part of the Victual
  project. Upstream's clients (the mobile apps, the Home Assistant integration) target
  upstream.
- **No response-contract freeze has been declared.** [Plan 14](../plans/14-contract-and-regression-scaffolding.md)
  piece 2 is scheduled, not done; when it lands, its snapshot is an internal regression
  tripwire between a migration and the fork's own clients, not a promise to anyone outside.

The five endpoints return the webhook payload as their response body, `grocycode` included,
and the browser fires the webhook from that body when `LABEL_PRINTER_RUN_SERVER` is false.
Their OpenAPI schema is an untyped object described as "WebHook data". The question ADR-0019
deferred — what do they return once they cannot return a Grocycode — was only hard while an
external client might be reading the answer. With none, it is a design choice inside the
fork, and the choice that follows ADR-0011 is that they stop existing: printing for those
five kinds goes through the same operations, jobs, templates and worker that locations use.

Grocycode itself is unchanged by this record. It stays a **read-only input symbology**: the
fork parses `grcy:*` indefinitely so labels printed before the fork still scan, and never
mints one again. The Grocycode design keyed a label to a row id in a type-specific string,
which was an easy way out and made the database hard to change; the `labels` table with its
opaque uid is the mapping that replaces it, for every kind.

## Decision

1. **The fork writes its own clients.** Compatibility with upstream Grocy's clients is not
   an obligation of this repository, so the five `/printlabel` endpoints may be removed
   without an upstream-compatibility shim. This is not a general licence to break the API:
   the constitution's rule that the wire contract is what this fork promises its clients
   stands, and a breaking change affecting a Victual-owned client requires that client to
   be updated before or in coordination with the server change. The OpenAPI specification,
   and plan 14's contract snapshot once it exists, must be updated in the same change —
   they record and detect the change; they do not by themselves make it acceptable.
   [Plan 17](../plans/17-ecosystem-clients.md)'s couplings become a catalogue of what the
   fork's own clients must handle, not breaks to avoid.
2. **ADR-0005 stands, for what it was written for.** The JSON on the wire remains the
   invariant between engines: where PostgreSQL and the frozen SQLite import line disagree,
   the spec decides and the wrong engine moves. That rule protects the port's correctness
   and is not touched by decision 1.
3. **The five `GET /api/.../printlabel` endpoints are removed, not reshaped.** Each of the
   five kinds gains print operations of the same form locations have —
   `POST /labels/{kind}/{id}/print` and `POST /labels/{kind}/{id}/revised-print` —
   enqueuing a job the worker drains.
   Purchase-time labels (`stockLabelType` 1 and 2) enqueue jobs inside the same service
   call instead of firing the webhook after the commit. Nothing emits `grcy:` again.
4. **ADR-0019 decision item 7's gate on step 2 is dissolved.** Step 2 (the five kinds) and
   step 3 (delete the webhook, the four `LABEL_PRINTER_*` settings, their
   `SystemApiController::EXPOSED_SETTINGS` entries and the browser-side webhook code) are
   one plan, [32](../plans/32-label-kinds.md), scheduled as wave-independent work.
5. **`labels.kind` widens** from `location | product | stock_entry` to add `recipe`,
   `chore` and `battery`, each with its own field catalogue, identity issuer and retirement
   trigger, so that a label for any of the six kinds is the same kind of thing.

## Options considered

**A. Keep the five endpoints and return the enqueued job.** Preserves the routes for a
client that might exist. Rejected: no such client exists or is planned, the routes would
carry a name (`printlabel`) and a verb (`GET`, with a side effect) that the label subsystem
deliberately does not use, and every one of them would need its own response schema written
for nobody.

**B. Keep the five endpoints and return `vctl:<uid>` under `grocycode`.** Rejected by
ADR-0019 already: same key, different meaning, wrong symbology if a client renders it.

**C. Remove the endpoints and add per-kind operations mirroring locations.** The decision.
One mechanism, one set of routes per kind, one worker.

**D. Leave the five kinds on the webhook indefinitely.** ADR-0019 option A, rejected there
as a destination and rejected here for the same reason: two printing subsystems that never
converge.

## Consequences

- **Plan 17's status changes meaning.** Its open questions about upstream client breaks
  are closed by decision 1; what remains of it is the catalogue the fork's own clients will
  need, and that is for the client repositories to consume.
- **Plan 14 piece 2 is unaffected in mechanism** and gains a clearer purpose: it protects
  the fork's own clients from the fork's own migrations. It should land after plan 32, or
  regenerate its snapshot when 32 lands, so it never pins the five webhook payloads.
- **The manual's label printing chapter** loses its "What still uses the older webhook"
  paragraph and its legacy webhook section when plan 32 lands; until then the paragraph
  says the migration is scheduled, not deliberately unscheduled.
- **After plan 32 lands, Victual makes no outbound connection for printing** — ADR-0019's
  own consequence, true once step 3 completes and not before. `WebhookRunner` itself
  stays: plan 18's InfluxDB writer uses it, so ADR-0019's statement that step 3 takes
  "`WebhookRunner`'s last caller" was already overtaken when plan 18 landed; this record
  corrects it, and the accepting pull request places a forward pointer beside that
  statement as well as beside the step 2 gate.
- **The four `LABEL_PRINTER_*` settings** disappear from `config-dist.php`, the
  configuration reference and `/system/config`. `FEATURE_FLAG_LABEL_PRINTER` goes with them;
  `FEATURE_FLAG_LABELS` is the only label flag afterwards.

## Acceptance prerequisites

This record removes a compatibility obligation rather than adding a mechanism, so it
carries no spike; plan 32's verification owns the replacement mechanism. Accepting it
requires that the decider confirm decisions 1, 3, 4 and 5 as written — in particular that
the five routes are removed rather than kept, and that `labels.kind` widens to six —
and that the accepting pull request add the forward pointers to ADR-0019's index row, its
decision item 7 and its "`WebhookRunner`'s last caller" statement. Decision 2 restates
ADR-0005 and needs no separate confirmation. Plan 32's question 4 is answered by decision
5 and is marked so.
