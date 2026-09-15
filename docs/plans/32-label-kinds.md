# 32. Label kinds: products, stock entries, recipes, chores and batteries

**Goal:** Every label Victual prints is a `labels` row with an opaque uid, rendered from a
template and delivered by the worker. The five entity types that still print a Grocycode
through the webhook move onto that path, and the webhook goes.
**Depends on:** [ADR-0024](../adr/0024-the-fork-writes-its-own-clients.md), **Proposed** —
this plan starts when it is accepted. Builds on [ADR-0011](../adr/0011-label-namespace.md),
[ADR-0019](../adr/0019-label-printers-are-master-data.md) decision item 7 (steps 2 and 3,
whose gate 0024 dissolves) and [ADR-0021](../adr/0021-label-templates-are-application-data.md).
**Interacts with:** [25](25-label-infrastructure.md) and [27](27-label-templates-and-rendering.md),
whose mechanism this extends kind by kind; [06](06-location-barcodes.md), the location
precedent every piece below mirrors; [14](14-contract-and-regression-scaffolding.md) piece 2,
which should snapshot after this lands or regenerate when it does; [17](17-ecosystem-clients.md),
whose premise 0024 replaces.
**Status:** draft, wave-independent, **not started**; gated on ADR-0024's acceptance.
Migration **0284** claimed (see [RESERVATIONS.md](../../migrations/RESERVATIONS.md)).

## Why this exists

[Plan 25](25-label-infrastructure.md) was ADR-0019's step 1 and only step 1: location
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

### A. Schema — migration 0284

- Widen the three `CHECK (... IN ('location','product','stock_entry'))` constraints on
  `labels.kind`, `label_templates.entity_kind` and `label_captures.entity_kind` to the six
  kinds `location`, `product`, `stock_entry`, `recipe`, `chore`, `battery`. PostgreSQL drops
  and re-adds the named constraint; the migration names them explicitly so the diff is
  reviewable.
- One retirement trigger per target table, the shape of `retire_location_labels`: on
  `DELETE` of a product, stock entry, recipe, chore or battery, the live label for it is
  retired with a `retirement_snapshot` of `id` and `name` (for a stock entry, the product
  name and the entry's `best_before_date` and `amount`, which is what a person holding a
  retired label most needs to see).
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
  opens the designer. Seeded by 0284 the way the location default is seeded today.
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

1. `.devtools/pgsql/check-migrations.php` passes with 0284 in the tree.
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
