# 32. Label kinds: products, stock entries, recipes, chores and batteries

**Goal:** Every label Victual prints is a `labels` row with an opaque uid, rendered from a
template and delivered by the worker. The five entity types that still print a Grocycode
through the webhook move onto that path, and the webhook goes.

**Depends on:** [ADR-0024](../../adr/0024-the-fork-writes-its-own-clients.md), **accepted
2026-09-15**. Builds on [ADR-0011](../../adr/0011-label-namespace.md),
[ADR-0019](../../adr/0019-label-printers-are-master-data.md) decision item 7 (steps 2 and 3,
whose gate 0024 dissolves) and [ADR-0021](../../adr/0021-label-templates-are-application-data.md).

**Interacts with:** [25](../25-label-infrastructure.md) and [27](27-label-templates-and-rendering.md),
whose mechanism this extends kind by kind; [06](../06-location-barcodes.md), the location
precedent every piece below mirrors; [14](14-contract-and-regression-scaffolding.md) piece 2,
which should snapshot after this lands or regenerate when it does; [17](../17-ecosystem-clients.md),
whose premise 0024 replaces.

**Status:** **Landed 2026-09-16** as `migrations/0283.pgsql.php`, [issue 182](https://github.com/datagen24/victual/issues/182)
closed; see [Executed](#executed-2026-09-16). Was: draft, ready to start once ADR-0024 was
accepted 2026-09-15.

Migration **0283** (see [RESERVATIONS.md](../../../migrations/RESERVATIONS.md)) was 0284
until [issue 176](https://github.com/datagen24/victual/issues/176)'s follow-up to plan 19
piece 2 was written as `0282.pgsql.php`, hours after this plan claimed its number. That
written file took the lowest free slot, moving plan 22 and this plan up one each. The number
was then 0285 until this plan's own migration was written and, by the same rule, took the
lowest free slot below it — moving plan 22's two numbers, still unwritten claims, up in turn
to 0284-0285.

## Kinds not yet in the label subsystem

[Plan 25](../25-label-infrastructure.md) was ADR-0019's step 1 and only step 1: location
labels, purely additive because locations had no `/printlabel` endpoint. The five endpoints
that existed — `GET /api/stock/products/{id}/printlabel`, `/stock/entry/{id}/printlabel`,
`/recipes/{id}/printlabel`, `/chores/{id}/printlabel`, `/batteries/{id}/printlabel` — kept
building a webhook payload with a `grcy:` code in it, and the browser or the server kept
POSTing that payload at `VICTUAL_LABEL_PRINTER_WEBHOOK`. Step 2 was gated on a wire-contract
record because a client might depend on those five responses. ADR-0024 records that no such
client exists or is planned, so the five endpoints can go and the five kinds can join the
subsystem locations already use.

The new path is location-only at every layer today, not only in templates:

| Layer | Location today | The five kinds |
|---|---|---|
| `labels.kind` (0269) | `CHECK (kind IN ('location','product','stock_entry'))` | `recipe`, `chore`, `battery` are not kinds at all |
| `label_templates.entity_kind`, `label_captures.entity_kind` (0271, 0272) | same three-value check | same |
| Retirement trigger | `retire_location_labels` on `locations` | none |
| `FieldCatalogue::For()` | `location` only; anything else throws `unsupported_entity_kind` | none |
| `LabelIdentityService` | `IssueLocation()`, `Resolve()` assumes `kind = 'location'` | none |
| `LabelOperationsService` | `IssueLocation()`, `RevisedPrint()` query `kind='location'` | none |
| Routes | `POST /labels/locations/{id}/print`, `revised-print` | five `GET .../printlabel` |
| Frontend | print action on the locations list and form | ten `viewjs` files call `printlabel` and fire the webhook |
| Purchase flow | n/a | `StockService::AddProduct` builds per-unit payloads inside the transaction and fires the webhook after the commit (`stockLabelType` 1 and 2) |

## Pieces

Five pieces in three dependency groups. A alone; B and C after A; D after C; E last.

### A. Schema — migration 0283

- Widen the three `CHECK (... IN ('location','product','stock_entry'))` constraints on
  `labels.kind`, `label_templates.entity_kind` and `label_captures.entity_kind` to the six
  kinds `location`, `product`, `stock_entry`, `recipe`, `chore`, `battery`. PostgreSQL drops
  and re-adds the named constraint; the migration names them explicitly so the diff is
  reviewable.
- One retirement trigger per target table, the shape of `retire_location_labels`: on
  `DELETE` of a product, stock entry, recipe, chore or battery, the live label for it is
  retired with a `retirement_snapshot` of `id` and `name`. A stock entry has no name of its
  own, so its snapshot carries `id`, the product name and the entry's `best_before_date` and
  `amount` instead, which is what a person holding a retired label most needs to see.
- `labels_one_live_per_target` already covers `(kind, target_id)`; nothing to add.
- Consuming a stock entry to zero does not delete its row, so its label stays live and
  resolves to an entry with `amount = 0`. That is correct: the jar is still on the shelf
  until somebody throws it out. Q2 below asks whether resolution should say so.

### B. Catalogue and identity, per kind

- `FieldCatalogue::For()` gains one catalogue per kind. Minimum fields, all `'null' =>
  'error'` unless noted: `product.name`, `product.description` (nullable), `product.id`,
  `product.group` (the group's name, nullable); `stock_entry.product_name`,
  `stock_entry.best_before_date` (nullable — `never_overdue` products have none),
  `stock_entry.amount`, `stock_entry.qu_name`, `stock_entry.purchased_date`,
  `stock_entry.location_name` (nullable), `stock_entry.id`; `recipe.name`, `recipe.id`;
  `chore.name`, `chore.id`; `battery.name`, `battery.id`. `TableFor()` and `SampleFor()`
  extend in step; the sample values are what the designer's preview renders.
- `LabelIdentityService` generalises `IssueLocation(int $id, int $expectedEpoch)` to
  `Issue(string $kind, int $id, ...)`. The import-epoch fence is a location concept (plan
  25's import refusal); for the other kinds the fence is the same `import_epoch` column
  each target table gains the way `locations` did in 0270, or none if Q1 decides the
  refusal only matters for locations. `Resolve()` returns `kind` from the row instead of the
  literal, and `target` carries the kind's catalogue `name` plus `path` for locations only.
- `LabelOperationsService::IssueLocation()` and `RevisedPrint()` take a kind; the default
  template lookup by `entity_kind` already does.
- The five `retire_*` triggers from A are what make `Resolve()`'s retired branch reachable
  for the new kinds; the identity suite's disposable schema grows the five tables.

### C. Operations, templates and the frontend

- Routes: `POST /labels/{kind}/{id}/print` and `/revised-print` for the six kinds, with
  `{kind}` constrained by a route regex to the six values rather than validated in the
  controller. The two location routes stay as they are (no rename for its own sake); the
  OpenAPI spec documents the generic pair and marks the location pair as the same
  operation.
- One seeded default template per kind, in the same shape as the location default
  (`LabelTemplateService::EmptyDocument`): the QR and one text line bound to the kind's
  `name` field, so a fresh install prints something readable for every kind before anyone
  opens the designer. Seeded by 0283 the way the location default is seeded today.
- The designer's field picker (`labeltemplateeditor.js`) reads the catalogue for the
  template's kind instead of the hard-coded location list — the same defect plan 06 Q5 hit
  with `location.path`.
- The ten `viewjs` files that call `printlabel` (`productform`, `stockentries`, `purchase`,
  `inventory`, `recipeform`, `recipes`, `choreform`, `choresoverview`, `batteryform`,
  `batteriesoverview`) call the new operation instead and drop the browser-side webhook
  code. The print action is the one the locations list has: printer picker, template
  picker, job link. Sink rules per AGENTS.md.
- The five `GET .../printlabel` routes and their controller methods are deleted, with
  their OpenAPI paths.

### D. Purchase-time labels

- `StockService::AddProduct` with `stockLabelType` 1 enqueues one job for the booking;
  with 2, one job per unit's stock entry. The enqueue happens inside the same transaction
  as the entries, replacing the after-commit webhook loop — the print job outbox is
  transactional by design (ADR-0011 decision 4), which the webhook never was.
- `purchase.js` and `inventory.js` lose the client-side webhook branch that
  `LABEL_PRINTER_RUN_SERVER = false` used.
- Q3 asks which printer and template a purchase-time job uses when the purchase form
  names none.

### E. Delete the webhook

- `config-dist.php`: `LABEL_PRINTER_WEBHOOK`, `LABEL_PRINTER_RUN_SERVER`,
  `LABEL_PRINTER_PARAMS`, `LABEL_PRINTER_HOOK_JSON` and `FEATURE_FLAG_LABEL_PRINTER` go;
  `ConfigurationValidator` and `SystemApiController::EXPOSED_SETTINGS` lose their entries;
  the manual's configuration reference loses the rows (the `stage.py` settings check fails
  the build if a row outlives its setting, so this is enforced).
- `WebhookRunner` stays: plan 18's `InfluxEventWriter` uses it. Only the label call sites
  in `StockService`, `StockApiController`, `RecipesApiController`, `ChoresApiController` and
  `BatteriesApiController` go.
- `docs/security-sweep.md`'s webhook line and the manual's "What still uses the older
  webhook" paragraph and "The webhook (legacy)" section are removed; `AGENTS.md`'s
  "Until 25 lands, the tree still prints Grocycodes through the webhook" sentence goes with
  them.
- The parity harness fixture codes for `grcy:*` parsing stay: Grocycode remains a read-only
  input symbology (ADR-0011 decision 3), and `ResolveBarcode` keeps trying it after the
  `vctl:` namespace.

## Questions

**Q1 — Does the import-epoch refusal apply to the new kinds?** Plan 25's refusal exists
because an import re-keys locations and a label printed before it would name the wrong row.
The same is true of every kind, since `bin/victual-db-import` truncates and re-copies all of
them. Proposed answer: yes, every target table gains `import_epoch` the way `locations` did,
and the importer bumps all six. The alternative, refusing only for locations, leaves five
kinds where an imported database silently re-points printed labels.

**Q2 — What does resolving a consumed stock entry's label say?** The row exists with
`amount = 0`, so the label resolves. Proposed answer: `resolved`, with `amount` in the
target so the scan page can say "empty since {date}"; retiring on consumption would make a
re-opened or corrected entry unlabelled.

**Q3 — Printer and template for purchase-time jobs.** The purchase form has no printer
picker. Proposed answer: the default printer (the first active one, the same rule
`LabelOperationsService::ResolvePrinter` applies when a request names none) and the kind's
default template; the form gains a printer picker only if a household with two printers
asks for one.

**Q4 — Recipes, chores and batteries: keep a print button at all?** Upstream prints a
Grocycode for them so a scan can start a chore or log a battery charge. **Answered by
ADR-0024 decisions 3 and 5:** all three become label kinds with print operations, because
the scan-to-act flow is the same one locations have and the cost is one catalogue and one
seeded template each. Removing any of the three later requires a superseding decision, not
a plan-level choice.

## Verification

1. `.devtools/pgsql/check-migrations.php` passes with 0283 in the tree.
2. The label test suites' disposable schemas carry the six kinds; `identity-tests.php`
   issues, resolves and retires one label of each kind, and the retired branch carries each
   kind's snapshot.
3. `artifact-tests.php` captures every catalogue field of every kind and hits each
   `'null' => 'error'` refusal once.
4. A frontend probe per kind: open the entity's page, print, follow the job link, assert the
   job row names the right kind and template.
5. A purchase with `stockLabelType = 2` and amount 3 leaves three stock entries, three live
   labels and three queued jobs in one transaction; a purchase that fails leaves none.
6. `grep -rn "printlabel\|LABEL_PRINTER" --include=*.php --include=*.js --include=*.blade.php`
   over the tree finds only the Grocycode parsing fixtures.
7. `python3 .devtools/docs/stage.py --no-api && mkdocs build --strict` passes with the
   settings rows removed.
8. The manual's label printing chapter describes one path.
9. One label of each of the six kinds printed on the QL-820NWBc and scanned back — the
   physical check plan 25's verification 13 set as the bar.

## Executed (2026-09-16)

All five pieces shipped as migration `0283.pgsql.php` and the application code around it,
[issue 182](https://github.com/datagen24/victual/issues/182). The migration was renumbered
down from `0285.pgsql.php` after CI's migration-numbering check refused this branch.
`migrations/RESERVATIONS.md`'s own rule is that a written file takes the lowest free slot and
an unwritten claim below it yields. That is what plan 22's still-unwritten 0283-0284 were
doing under this plan's 0285 — see that file's numbering log for the renumbering itself.

Verified against a real PostgreSQL 16.13 (this sandbox's own instance) and a real
`bin/victual-migrate` run against a fresh database — not against a mocked schema.

### Piece A — schema

Written as a PHP migration, not `.pgsql.sql`: the seeded templates need
`CanonicalJson::Digest()` over the RFC 8785 encoding, which only exists in PHP, and seeding
through `LabelTemplateService::Create()`/`Publish()` means the seeded rows are validated by
`TemplateDocument::Validate()` exactly as a household's own template would be. The three
`CHECK`s widened by dropping and re-adding the named constraint (`labels_kind_check`,
`label_templates_entity_kind_check`, `label_captures_entity_kind_check`); `import_epoch` on
`products`, `stock`, `recipes`, `chores`, `batteries` with the same
`DEFAULT label_current_import_epoch()` locations carries; one retirement trigger per table,
`retire_stock_entry_labels` carrying the richer `{id, product_name, best_before_date,
amount}` snapshot the plan named, the other four `{id, name}` like `retire_location_labels`.

**One correction to this plan's own text, found while implementing it and not before.** The
"seeded by 0283 the way the location default is seeded today" clause does not describe
anything in `master`. The tree has no seeded default template for `location` and never has —
a household creates one by hand through the designer, or prints nothing. So the five new
defaults are the first seeded templates this codebase has shipped, not a repeat of an
existing mechanism.

A second correction follows from the first: `LabelTemplateService::EmptyDocument()`'s own
comment explains why a seeded default cannot draw a text field. There is no default font
asset to pin one to, and referencing one that does not exist refuses to publish.

So the five seeded defaults are QR-only, the same shape a hand-created template starts from,
rather than "the QR and one text line bound to the kind's name field" this plan's piece A
bullet asked for. A QR is still something a fresh install can scan, which is the goal the
bullet gave for wanting a seeded default in the first place. The text line is one designer
edit away once a font is uploaded.

### Piece B — catalogue and identity per kind

`FieldCatalogue::For()` gained `product`, `stock_entry`, `recipe`, `chore`, `battery`
alongside `location`, plus a new `DomainPermission()` method mapping each kind to the read
grant `LabelsApiController::Operate()` checks. `LabelIdentityService::IssueLocation()`/
`LocationContext()` became thin wrappers over new `Issue(string $kind, ...)`/`Context(string
$kind, ...)` methods, matching the plan's instruction for that class exactly.
`LabelOperationsService::IssueLocation()` and `RevisedPrint()` kept their names and gained a
leading `$kind` parameter, per the plan's explicit (and different) instruction for that
class. `DatabaseImporter::GetCommonColumns()`'s `import_epoch` exclusion widened from
`locations` alone to all six target tables.

`Resolve()` needed more than "return `kind` from the row." With six kinds each gating on a
different domain permission, a single `bool $mayReadLocations` flag can no longer answer "may
this caller read this label." That decision depends on the label's own `kind`, which is not
known until the row is read. `Resolve()` now takes a `callable(string $kind): bool`, the same
shape `LabelCaptureService`'s own `$permissionCheck` already uses.

The original "a denied caller does no label lookup at all" property is asserted by
`identity-tests.php` renaming the `labels` table and observing that the denied path never
touches it. This is preserved for a caller denied every kind — checked up front, before any
query — rather than only for a single-flag denial. A caller holding some permissions but not
the one the row turns out to need still gets `{"status":"unknown"}`, indistinguishable from a
code that does not exist, just after one lookup rather than zero.

**A bug this session's own consistency check caught before it shipped**, not one an external
review found. `LabelOperationsService::FieldsOf()` and the identical private copy in
`LabelTemplatesApiController` both built `$document['entity_kind'] . '.name'` as the
always-captured field. For `stock_entry` that produces `stock_entry.name`, which does not
exist in the catalogue — `FieldCatalogue::For('stock_entry')` names the product it holds
`stock_entry.product_name`, because a stock entry has no name of its own. Every stock-entry
issuance would have refused with `unknown_field`.

Fixed in both places before the first test run exercised issuance, so it never reached
committed test output as a failure — the `kinds-tests.php` suite added for verification 2
exercises this path directly and passes. `PrintJobPayload::DescribeUnreadable()` picked up the
same special case for its own captured-name check, plus the six-kind allow-list
`payload['kind']` is checked against.

### Piece C — routes, templates, designer, frontend

Routes: `POST /labels/{kind:location|product|stock_entry|recipe|chore|battery}/{id:[0-9]+}/print`
and `/revised-print`, `GET /labels/{kind:product|stock_entry|recipe|chore|battery}/{id:[0-9]+}/context`
— `{kind}` constrained by the route's own regex rather than validated in the controller, as
the plan specified. This is also the first route in this tree to use an inline regex
constraint. The two location routes are untouched.

`LabelsApiController::Operate()` resolves `$kind` from whichever route matched and checks
`FieldCatalogue::DomainPermission($kind)` alongside `MASTER_DATA_EDIT` for issue and
revised-print. Reprint, promotion and cancellation check only `MASTER_DATA_EDIT`, because none
of the three performs a fresh authorized capture — a reprint replays stored bytes, a promotion
promotes a capture already taken, a cancellation reads nothing. The domain grant a capture
needs therefore does not apply, and requiring one would refuse a caller who administers a
chore's or a recipe's labels without separately holding `STOCK_VIEW`.

`victual.openapi.json` documents the generic pair — path keys carry the same inline regex the
route does, verbatim, since `.devtools/check-path-id-validation.php` matches on the raw Slim
pattern, not a normalised `{kind}` — and notes the location pair as the same operation. The
five old `*/printlabel` paths are deleted.

The five `GET .../printlabel` controller methods and routes are deleted
(`StockApiController::ProductPrintLabel`/`StockEntryPrintLabel`, `RecipesApiController::
RecipePrintLabel`, `ChoresApiController::ChorePrintLabel`, `BatteriesApiController::
BatteryPrintLabel`), along with the now-unused `Grocycode`/`WebhookRunner` imports each left
behind.

**The frontend rollout touched more files than the plan's own list.** Piece C names ten
`viewjs` files. The actual tree has three more genuine `printlabel` call sites the list
missed — `stockjournal.js` and `stockoverview.js` (both print a *product* label from a stock
row, not the stock entry itself) and `stockentryform.js` (a "reprint stock entry label"
checkbox with no printer picker, fired on save). All three are converted the same way as the
listed ten.

The stock-entry-form checkbox is replaced with the standard standalone print widget, the same
as every other form, rather than kept as a save-time side effect. It had no printer picker
under the old webhook, which needed none, but the new path needs one. Folding it into "print
separately, the same everywhere" was the smaller design than teaching one form a bespoke
printer-picker-on-save flow.

A shared JS module (`public/js/victual_label_print.js`, `Victual.LabelPrinting.Wire()`) and
two Blade partials (`views/components/label_print_widget.blade.php` for a form page,
`label_print_list_header.blade.php` for a list page's shared printer-select-and-status
region) replace the location-only hardcoding. `locationform.blade.php`/`locations.blade.php`
were themselves migrated onto the shared partials, rather than left as a second, divergent
copy of the pattern the other five kinds now also use, and their print buttons' attributes
renamed `data-location-id`/`-name` → `data-target-id`/`-name` to match.

The label template designer's field picker (`labeltemplateeditor.js`) gained a
`FIELDS_BY_KIND` map mirroring `FieldCatalogue` by hand, the same non-dynamic mirroring issue
137 already used for `location.path` — there is still no runtime fetch of the catalogue.

The template list page (`labeltemplates.blade.php`/`.js`) gained an entity-kind `<select>`. It
previously hardcoded `'entity_kind': 'location'` on every create, which meant there was no way
to create a template for any other kind through the browser at all.

Blade rendering was checked by compiling every touched template through the real
`Jenssegers\Blade` compiler and `php -l`-checking the output (no unbalanced `@if`/`@endif`,
no malformed `@include` argument list) — **not** by rendering them against live data through
a running instance. `public/index.php`'s `PrerequisiteChecker` requires PHP 8.5.0, and this
sandbox has 8.4.19 (composer install needed `--ignore-platform-req=php` for the same reason).
`bin/victual-migrate` and the `.devtools/labels/*.php` suites bypass that gate and did run for
real, but the HTTP app did not boot here.

Said plainly rather than assumed: the per-page button placement, wording and permission
gating were reviewed by reading the diff against each page's existing markup, not by clicking
through it.

### Piece D — purchase-time labels

`StockService::AddProduct()`'s `stockLabelType` 1 and 2 branches now call a new private
`IssueStockEntryLabel()` inside the same `DatabaseService::InTransaction()` closure that
writes the booking and the stock entry, instead of accumulating webhook payloads to fire
after commit. `LabelOperationsService::ResolvePrinter()` gained a `?int $printerId = null`
default-printer fallback (`SELECT id FROM label_printers WHERE active=1 ORDER BY is_default
DESC, name LIMIT 1`, refusing `no_printer` if none). Question 3's answer names this exact
rule, but it did not exist anywhere in the tree before this change. `IssueLocation()`'s
`$printerId` parameter became nullable to carry it through. `ResolveTemplate()` already
defaulted to a kind's published default template when none is named, so question 3's second
half needed no new code.

**Two more call sites the plan's piece D prose does not mention were found by grepping for
what piece E asks to delete** (`WebhookRunner`/`Grocycode` inside `StockService.php`, not just
the one `AddProduct()` discusses). `OpenProduct()`'s and `TransferProduct()`'s
`auto_reprint_stock_label` handling re-built and fired the same webhook payload whenever an
entry's due date shifted because it was opened, frozen or thawed.

Converted to a new `ReviseStockEntryLabelIfLive()` — a revised print, same identity — but
**only when the entry already carries a live label**, which the old webhook could not
distinguish because it had no notion of a label's history to consult. "Reprint" is what the
setting is named for; an entry nobody printed a label for is not enrolled into the label
subsystem by its due date moving under it.

This is a considered interpretation where the plan is silent, not a literal instruction, and
is recorded as one rather than presented as the only possible reading.

`purchase.js` and `inventory.js` lost their client-side `Victual.Webhooks.labelprinter`
branches, built inline from the booking response and never through a `printlabel` GET — the
plan's piece C file list naming them among ten `printlabel` callers does not match what those
two files actually did. Every remaining `VICTUAL_FEATURE_FLAG_LABEL_PRINTER` check in both
files, plus `productform.js`, `stocksettings.js` and four Blade templates (`stocksettings`,
`purchase`, `productform`, `inventory`), was renamed to `VICTUAL_FEATURE_FLAG_LABELS` — the
`stock_label_type` UI these gate is not itself part of the webhook, so it survives under the
surviving flag.

**Verified against real PostgreSQL** (not asserted from reading the code): a script driving
the actual `StockService::AddProduct()` against a freshly `bin/victual-migrate`d database
confirmed `stockLabelType=1` issues exactly one `stock_entry` label and one print job,
`stockLabelType=2` with amount 3 issues exactly three of each, and `stockLabelType=0` issues
none. Verification 5's negative half also held: deactivating the only configured printer and
retrying `stockLabelType=2` throws rather than partially succeeding, leaving the `stock` and
`stock_log` row counts exactly where they started. `.claude/hooks/log_claim.py` has the
command and the exact counts.

### Piece E — delete the webhook

`config-dist.php` lost `LABEL_PRINTER_WEBHOOK`, `LABEL_PRINTER_RUN_SERVER`,
`LABEL_PRINTER_PARAMS`, `LABEL_PRINTER_HOOK_JSON` and `FEATURE_FLAG_LABEL_PRINTER`;
`SystemApiController::EXPOSED_SETTINGS` lost the four webhook entries.
**`helpers/ConfigurationValidator.php` never had entries for these settings** — the plan
bullet claiming it did was checked and found not to describe the tree; nothing to remove
there.

`views/layout/default.blade.php`'s `Victual.Webhooks` object (built only for the
label-printer webhook) and `public/js/victual.js`'s `RunWebhook()` helper are deleted outright
rather than left with a zero-caller definition, since every one of their callers was a site
this plan's own scope removed.

`helpers/WebhookRunner.php` (the PHP-side runner) is kept, per the plan's instruction — **but
the stated reason is false**: `InfluxEventWriter` calls `GuzzleHttp\Client` directly, not
`WebhookRunner`, so as of this landing `WebhookRunner` has zero callers anywhere in the tree.
Recorded rather than quietly fixed by deleting the class, because retiring a webhook path and
deleting a helper nothing calls are two different cleanups, and only the first was this
plan's to do. `docs/security-sweep.md` now says so directly instead of repeating the InfluxDB
claim.

**Deleted 2026-09-23.** `helpers/WebhookRunner.php` was removed with the four
`HelperUnitsTest` cases and the loopback listener that existed only to exercise it, after a
search of PHP, `public/viewjs`, the Blade views, `bin/` and the configuration found no caller.

Documentation: the manual's "Label printer webhook" settings section and its
`FEATURE_FLAG_LABEL_PRINTER` row are gone. `docs/manual/operator/label-printing.md`'s
"two independent paths" framing, "What still uses the older webhook" paragraph and "The
webhook (legacy)" section are replaced with one path covering all six kinds, including the
purchase-time and auto-reprint behaviour piece D added. `AGENTS.md`'s "Until 25 lands, the
tree still prints Grocycodes through the webhook" sentence is rewritten to name what actually
shipped (25, 27, 32) rather than describe a still-pending state.

### Verification

1. **Passes locally, failed in CI, then was fixed by renumbering.** `check-migrations.php
   --allow-reserved-holes` passed against this branch throughout implementation, waiving what
   looked like two holes unrelated to this change — plan 22's unwritten claims sitting below
   this plan's then-number, 0285. CI does not set that waiver (`migrations/RESERVATIONS.md`
   says so by name), and its `suite` job failed on exactly those two holes once this migration
   had a file behind it. The rule the waiver exists to describe is not "wave until the other
   branch merges." It is "the number about to have a file behind it takes the lowest free
   slot, and an unwritten claim yields" — the same rule `RESERVATIONS.md` had already applied
   ten times before this plan's own number ever collided with anything. Applying it here
   renumbered this migration from `0285.pgsql.php` down to `0283.pgsql.php` and moved plan 22's
   two numbers up in turn, to 0284–0285; `check-migrations.php` then passes with no waiver
   needed, which is the state a mergeable branch is supposed to be in.
2. **Passes**, as a new suite (`.devtools/labels/kinds-tests.php`, 44 assertions, added to
   the `suite` CI job). For each of the five new kinds, every `FieldCatalogue` field captures
   without refusing, `Issue()`/`Resolve()` round-trip live, and deleting the target retires
   the label with a snapshot naming it. This exercises migration 0283's five triggers for real
   rather than asserting from reading the SQL. **A real regression this item's own suite
   passing did not catch**, found only because the unrelated `import` phase of `run-tests.sh`
   crashed on the same pattern: `Resolve()`'s generalisation from a `bool` flag to a per-kind
   `callable(string):bool` (piece B) was not carried into every existing caller outside
   `.devtools/labels/`.

   Two call sites still passed a bare `true`: `.devtools/pgsql/import-tests.php` (a test,
   which is what actually crashed CI's `suite` job with an uncaught `TypeError`) and, more
   seriously, `StockApiController::WeighLocationByLabel()` (`POST
   /api/stock/locations/by-label/{code}/weigh`, the plan 29 endpoint a kitchen scale calls by
   scanning a location label). This second site shipped in this plan's original push with the
   same defect and no test coverage of its own to catch it. Every request to that endpoint
   would have thrown instead of weighing anything.

   The test was fixed with `fn (string $kind): bool => true`, the pattern already used where a
   caller has separately established the permission it needs. The endpoint got a narrower
   callable on review, `fn (string $kind): bool => $kind === 'location'`, since it only ever
   wants a location and `Resolve()`'s per-kind gate exists to refuse a denied kind's lookup
   entirely rather than merely reject it after resolving.

   The endpoint's own kind check would have caught a mismatch either way, but only the
   narrower callable keeps the "denied kind, no lookup at all" property `identity-tests.php`
   tests by name for read permissions generally. Re-verified with a full `run-tests.sh all`
   (no waiver) and both label suites, all clean against real PostgreSQL 16.13.
3. **Partly.** `kinds-tests.php` captures every catalogue field of every kind (the first half
   of this item). It does not separately hit a `'null' => 'error'` refusal per kind. Every
   such field in the five new catalogues sits on a `NOT NULL` database column, so there is no
   reachable case to refuse against without corrupting the fixture past what a real
   installation could produce — the same is already true of `location.name` in the existing
   `artifact-tests.php`.
4. **Still not run for the five new kinds; the location and designer probes that do exist were
   run in the PR #186 follow-up and both needed a fix.** No frontend probe exists for any of
   the five new kinds' print action — that gap stands.

   But `.devtools/frontend/location-print.js` and `label-designer.js` do exist, predate this
   plan, and are two of the three steps `frontend-security`'s CI job runs against the labels
   instance. This plan's original verification pass never ran them, because PHP 8.5 was
   unavailable in this sandbox, so the labels instance piece C's own account above describes
   could not be booted at the time.

   CI caught what that gap hid: three pieces of markup piece C actually shipped that were
   never carried into these two probes when piece C was written.

   - Piece C's generic `data-target-id`/`data-target-name` attributes, replacing
     `location-print.js`'s hard-coded `data-location-name`.
   - The shared `label_print_widget` partial's `{idPrefix}-button`/`{idPrefix}-status` ids,
     replacing `#location-form-print-button`/`#location-print-status`, which the partial
     never produced.
   - The designer's kind-neutral "Create a label template" button text, replacing
     `label-designer.js`'s "Create a location label template".

   Fixed by updating both probes to the markup piece C actually ships, and re-verified for
   real this time: the `run-app` skill's `PrerequisiteChecker` workaround (reverted after)
   booted a PHP 8.5-gated labels instance, and
   `label-printers.js`/`location-print.js`/`label-designer.js` — the exact three steps
   `frontend-security` runs — all passed against it.
5. **Passes**, against real PostgreSQL — see piece D above for the exact counts.
6. **Passes.** `grep -rn "printlabel\|LABEL_PRINTER" --include=*.php --include=*.js
   --include=*.blade.php` over the tree returns nothing at all (not even a Grocycode
   fixture — the pattern does not match Grocycode's own `grcy:` prefix).
7. **Not run.** The settings-reference half is verified directly (every `Setting()` in
   `config-dist.php` has a backtick-quoted row in `docs/manual/configuration.md` and vice
   versa, checked by replicating `check_settings_reference()`'s own regex against both files
   rather than by installing mkdocs — pip timed out fetching it in this sandbox). The
   `mkdocs build --strict` half is not run.
8. **True by inspection**, not by a script: `docs/manual/operator/label-printing.md` now has
   one path, described above.
9. **Not run.** No physical printer is reachable from this sandbox. Plan 25's verification 13
   physical print-and-scan for the original three kinds was met on real hardware in an
   earlier session, but nothing here re-demonstrates it for the two new kinds nor for the
   five kinds this plan added.
