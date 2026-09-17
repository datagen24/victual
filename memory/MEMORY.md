# Memory Index

> Auto-loaded at session start by `.claude/hooks/auto_orient.py`. The injector truncates at
> **8000 bytes** (`AUTO_ORIENT_MAX_BYTES`), at a line boundary — so this file stays short and
> keeps detail in the linked topic files. Everything below the cap is silently dropped.

## What memory is for here

This repository already has a documentation corpus that is the authority on the project:
[AGENTS.md](../AGENTS.md) for ground rules, [docs/constitution.md](../docs/constitution.md)
for standing principles, [docs/adr/README.md](../docs/adr/README.md) for decisions in force,
[docs/plans/README.md](../docs/plans/README.md) for what work exists and what gates it.

**Memory does not restate any of that** — it would drift, and the corpus would still be
right. Memory carries the three things the corpus does not: how this machine actually runs
the suites, what verification counts as evidence, and where the last session left off.

When memory and the corpus disagree, the corpus wins and the memory file is wrong: fix it.

## Verification protocol (the Stop hook enforces this)

`.claude/hooks/claim_check_hook.py` blocks or warns at turn end when a reply claims
done / shipped / verified / fixed / complete without a fresh verification entry. After you
actually verify something, log it:

```bash
python3 .claude/hooks/log_claim.py "<what you claim>" "<how you verified it>"
```

The entry must name a command that ran and what it returned. See
[feedback_verification_discipline.md](feedback_verification_discipline.md) for what counts.

## File conventions

Memory files live in this directory, prefix-typed:

| Prefix | Contents | Lifetime |
|---|---|---|
| `project_*` | Active work, session logs, in-flight state | Days to months |
| `feedback_*` | Operator decisions, locked doctrine, lessons learned | Long-lived |
| `reference_*` | Canonical patterns, playbooks, how-to | Long-lived |
| `user_*` | Operator identity, preferences, focus | Long-lived |

Frontmatter on every file:

```markdown
---
name: <short title>
description: <one line, used to decide relevance in a future session>
type: <project | feedback | reference | user>
---
```

Bodies of `feedback_*` and `project_*` files carry **Why** and **How to apply** lines, and
link related files as `[[name]]`. Facts that were measured name the date and how to
reproduce them, as [docs/documentation.md](../docs/documentation.md) requires of records.

## RECENT SESSIONS

<newest first; keep five. Concurrent branches both add a line here — on conflict keep both.>

- **2026-09-17 — Post-merge bookkeeping for #186–#189** (plan 32 → `0283.pgsql.php`, files
  API own-picture fix, plan 15's cleanup batch, manual corrections). Closed #132 and #177
  with landing notes; #179's two named items were already fixed by PR 172's second round
  (`d54dadb`); PR 190 then rewrote plan 27's Executed evidence for the image build and
  closed it. Fixed plan 32's status line (still
  said "ready to start"), marked 0283 in master, rewrote the root README's Labels row (six
  kinds, webhook gone) and the wave-independent cell. Next unclaimed migration: 0286. Wave
  5's remaining items: #83 (14 piece 2, no longer blocked), then #86 and #85. **Coverage
  floor decided the same day**: 75% minimum, 85+ target, 90 ideal, written into the
  constitution, AGENTS.md, CONTRIBUTING and the PR template; master is at 37.81% and
  [issue #192](https://github.com/datagen24/victual/issues/192) holds the 42-class backlog
  and the ratchet-then-gate plan. Nothing is wired in CI yet; that is 192's first step.

- **2026-09-15 — Issue #176 closed: 19 piece 2's four open price channels** (`0282.pgsql.php`,
  branch `claude/issue-176-regression-aqz409`). The one that mattered was the importer:
  `TRUNCATE ... CASCADE` on `permission_hierarchy` empties `permission_fields` through its FK,
  so **every import removed price redaction entirely and left `PricesVisible()` false even for
  ADMIN** — a security feature that silently uninstalled itself. Fixed by extracting the seed to
  `db/pgsql/prices-seed.sql`, the way `roles-seed.sql` already was, applied by the new migration
  and re-applied by `DatabaseImporter` after its verbatim-copy assertions. Also: `/stock/bookings/{id}`
  redacted (its sibling `StockTransactions` had been and it had not), policy rows for
  `product_barcodes`/`product_barcodes_view` `last_price`, four Blade pages moved off the feature
  flag onto `$pricesVisible` and made to *omit* the value rather than `d-none` it, `/stockreports/spendings`
  now 403, the `'*'` whole-object marker wired into `AssertWholeObjectReadable()`, and the
  `NaN`/`undefined` half in `shoppinglist.js`/`mealplan.js`. **Both defects were reproduced before
  being fixed** — removing the `StockBooking` redaction fails 3 of the new assertions, removing the
  importer's seed re-application fails 6 — which is the evidence the claim-check asks for and is
  cheap to get here because the phases already isolate one identity at a time. The audit for other
  injected regressions found one real leftover (`stockoverview.blade.php`'s Value and Default-store
  `<td>`s were never paired with their `d-none` headers — invisible while it only fired on the
  flag, visible now that every Child hits it) and one stale doc #178 does not list (the Manual's
  roles page said price visibility was "still-unbuilt" on the day it shipped). Lesson: when a
  feature's state lives in a table the SQLite import span cannot carry, the importer is part of
  the feature — check it in the same change, not in the follow-up issue.
- **2026-09-15 — Wave 5 bookkeeping after six reviewed merges** (#169–#175). Reviewed each PR
  with one adversarial agent per PR, verified the top findings by reading the branch, posted one
  comment per PR. #173 and #175 fixed their blockers before merge (rotated key owner; designer
  field and OpenAPI `path`); #170 (19 piece 2, now `0281.pgsql.sql` after losing the 0280 race
  to #173) merged with only its CI fix, so four price channels are still open on master —
  [issue #176](https://github.com/datagen24/victual/issues/176), which should land before 14
  piece 2 snapshots per role. #174's own-picture bypass is #177, #171's manual errors #178,
  #172's stale plan-27 wording #179. Closed #84, #130, #138, #137, #126, #121 with landing
  notes. Lesson: a review comment is not a gate — the dispatching session merges on green CI,
  so blocking findings need a follow-up issue the moment the PR merges without them.
- **2026-09-16 — Plan 32 landed** (label kinds, issue #182 closed), the largest item and the
  one everything else in labels waited on. Migration `0283.pgsql.php` (a PHP migration, not
  `.sql`: the five seeded templates need `CanonicalJson::Digest()`, PHP-only; renumbered down
  from `0285.pgsql.php` after PR #186's CI failed the un-renumbered branch — see the follow-up
  entry below) widens the three
  `entity_kind`/`kind` `CHECK`s to six values, adds `import_epoch` to `products`/`stock`/
  `recipes`/`chores`/`batteries`, adds one retirement trigger per table, and seeds a QR-only
  default template per new kind through the real `LabelTemplateService`. `FieldCatalogue` gained
  five catalogues plus `DomainPermission()`; `LabelIdentityService::Resolve()` now takes a
  `callable(string):bool` instead of one flag, since which permission gates a code is not known
  until its row names a kind; `LabelOperationsService::IssueLocation()`/`RevisedPrint()` take a
  leading `$kind` and `ResolvePrinter()` gained a default-printer fallback for `null`. Routes
  `POST /labels/{kind:location|product|stock_entry|recipe|chore|battery}/{id:[0-9]+}/print` etc,
  `{kind}` constrained by the route's own regex (the tree's first inline-regex route) rather than
  validated in the controller; OpenAPI path keys had to repeat that regex verbatim; `check-path-id-validation.php` matches on the raw Slim pattern. Thirteen `viewjs`/blade pairs
  migrated onto a shared `Victual.LabelPrinting.Wire()` and two new partials
  (`label_print_widget`/`label_print_list_header`) — three more than the plan's own ten-file
  list named (`stockjournal.js`/`stockoverview.js` print a *product* from a stock row;
  `stockentryform.js`'s reprint-on-save checkbox became the standard standalone widget).
  `StockService::AddProduct()` now issues a `stock_entry` label and enqueues its job inside the
  same transaction as the booking (replacing an after-commit webhook loop); `OpenProduct()`/
  `TransferProduct()`'s `auto_reprint_stock_label` webhook sites (not named in the plan's piece D
  prose, found by grepping for what piece E asks to delete) became a revised print gated on the
  entry already carrying a live label. Piece E deleted `LABEL_PRINTER_*`/`FEATURE_FLAG_LABEL_PRINTER`
  end to end — config, `EXPOSED_SETTINGS`, the manual, `AGENTS.md`, `Victual.Webhooks`,
  `RunWebhook()` — leaving `grep -rn "printlabel\|LABEL_PRINTER"` clean. Three corrections to the
  plan's own text found while implementing it, recorded rather than silently fixed: no seeded
  location template exists anywhere in `master` to model the new ones on ("the way the location
  default is seeded today" describes nothing real); `ConfigurationValidator` never had
  `LABEL_PRINTER_*` entries to remove; `InfluxEventWriter` calls Guzzle directly, not
  `WebhookRunner`, so `WebhookRunner` (kept per the plan) is actually dead code now, just not
  this plan's dead code to remove. A real bug this session's own consistency check caught before
  any test ran: `FieldsOf()` (two copies) assumed every kind's always-captured field is
  `<kind>.name`, which does not exist for `stock_entry` (`stock_entry.product_name` does).
  Verified against real PostgreSQL 16.13 in this sandbox: `check-migrations.php`,
  `check-path-id-validation.php`, `identity-tests.php` (10047), `artifact-tests.php` (55),
  `print-job-tests.php` (36), `registry-tests.php` (23), `worker-api-tests.php` (25), and a new
  `kinds-tests.php` (44, added to CI) exercising all five new kinds' capture/issue/resolve/retire
  for real; a disposable script drove `StockService::AddProduct()` against a `bin/victual-migrate`d
  database confirming exact label/job counts per `stockLabelType` and a full rollback (no stock
  entry, no booking) when the configured printer is inactive. Not verified: any live HTTP
  rendering (PHP 8.5 unavailable in this sandbox, only 8.4 — blade templates were instead
  compiled through the real `Jenssegers\Blade` compiler and `php -l`-checked), `mkdocs build
  --strict` (mkdocs unavailable, pip timed out — the settings-reference half was verified by
  replicating its check directly), and physical printing on the QL-820NWBc for the five new
  kinds. See the plan's own [Executed section](../docs/plans/32-label-kinds.md#executed-2026-09-16)
  for the full account, piece by piece.
- **2026-09-16 — Plan 32 follow-up on PR #186.** Four defects found after landing, all fixed
  and pushed to the same branch. First, a real `TypeError`: `LabelOperationsService::RevisedPrint()`
  declared `$printerId` as non-nullable `int`, but `StockService::ReviseStockEntryLabelIfLive()`
  passes `null` for an automatic revised print (matching `ResolvePrinter()`'s documented
  default-printer behaviour) — any due-date change with `auto_reprint_stock_label` enabled and
  a live label threw and rolled back the open/transfer transaction. Fixed by widening the
  parameter to `?int`, matching `IssueLocation()`. Second, CI's `suite` job failed "checking
  migration numbering": `.devtools/pgsql/run-tests.sh` never sets `SUITE_ALLOW_RESERVED_HOLES`
  (`migrations/RESERVATIONS.md` says so by name — "CI does not set it"), so the two unwritten
  claims plan 22 held below this plan's number, 0283–0284 under the migration as originally
  written at 0285, hard-failed rather than being waived the way it was locally throughout
  implementation. This was not a false positive: `RESERVATIONS.md`'s own rule — a written file
  takes the lowest free slot, an unwritten claim below it yields — applied to this plan's own
  migration exactly as it had to the ten collisions already logged there. Renumbered
  `0285.pgsql.php` down to `0283.pgsql.php` (content otherwise unchanged, one internal comment
  string updated) and moved plan 22's two claims up to 0284–0285; `check-migrations.php` then
  passes with no waiver. Re-verified against real PostgreSQL 16.13: the `migrate` suite phase
  and `kinds-tests.php` (44 assertions) both pass clean post-renumber. See
  [RESERVATIONS.md](../migrations/RESERVATIONS.md)'s "eleventh move" entry and the plan's own
  [Executed section](../docs/plans/32-label-kinds.md#executed-2026-09-16), verification item 1.
  Third, `frontend-security`'s CI job failed too, on two stale `.devtools/frontend` probes
  piece C's generalisation broke and this session's own verification never re-ran: locations'
  print button kept the class `location-print-button` (no rename for its own sake) but its
  data attributes became the shared `data-target-id`/`data-target-name`
  (`victual_label_print.js` reads those, generically, across all six kinds), the location
  form's print button id became `{idPrefix}-button` (`location-form-button`) from the shared
  `label_print_widget` partial rather than the old `location-form-print-button`, and the list's
  status region kept `location-list-status` from `label_print_list_header` rather than a
  `location-print-status` that never existed after the partial split. `location-print.js` still
  asserted the old names on all four counts. Separately, `label-designer.js` looked for a
  button named "Create a location label template" — the designer's create button lost its
  per-kind text when the entity-kind picker (`#new-template-entity-kind`) was added beside it;
  the button now just says "Create a label template", and the picker's first option
  ('location') still makes the probe's own intent hold. Both probes updated to match the
  markup piece C actually ships; re-run for real against a local PHP 8.5-gated instance (the
  `run-app` skill's `PrerequisiteChecker` workaround, reverted after) with
  `label-printers.js`/`location-print.js`/`label-designer.js` — the exact three steps
  `frontend-security` runs against the labels instance — all passing clean. The gap: this
  session's original verification pass ran the PHP-side suites and a disposable PostgreSQL
  script, never these three browser probes, so two pre-existing tests silently going stale was
  invisible until CI ran them for real.
  Fourth, and most severe: a real production `TypeError` in a live, device-facing endpoint,
  not merely an internal call path. `StockApiController::WeighLocationByLabel()`
  (`POST /api/stock/locations/by-label/{code}/weigh` — a kitchen scale scanning a location
  label, plan 29) called `LabelIdentityService::Resolve($args['code'], true)`, passing a bare
  `true` where `Resolve()` now requires a `callable(string):bool` (piece B generalised it from
  a single `$mayReadLocations` flag). Every request to that endpoint would have thrown rather
  than weighing anything or returning a proper error response — this shipped in the original
  push and CI never exercises it (no probe drives a scale device). It surfaced only because
  `.devtools/pgsql/run-tests.sh all`'s `import` phase crashed on the identical stale-call
  pattern in `.devtools/pgsql/import-tests.php` (lines 448/450 — a PostgreSQL-only test outside
  the `.devtools/labels/` suite this session's original call-site sweep covered) with an
  uncaught `TypeError` and the same stack shape, which is what made grepping the whole tree for
  `->Resolve(` worth doing — it found both, not just the one that happened to crash a test.
  Fixed the test with `fn (string $kind): bool => true` (it resolves a plain location fixture
  and needs nothing narrower, matching `kinds-tests.php`/`identity-tests.php`'s own style).
  `WeighLocationByLabel()` first got the same fix and, on review, a second one: a caller that
  only ever wants a location should scope the callable to that kind -
  `fn (string $kind): bool => $kind === 'location'` - rather than authorize every kind and
  reject afterward. `Resolve()`'s per-kind gate (`AnyAllowed()`, then `!$mayRead($row['kind'])`
  before `ResolveTarget()` runs) exists precisely so a denied kind's target is never looked up
  at all; granting `true` unconditionally would have let a scanned recipe/chore/battery/
  product/stock_entry code resolve fully before the endpoint's own kind check rejected it,
  reopening the "denied caller does no label lookup" boundary `identity-tests.php` tests by
  name for read permissions in general. Full `run-tests.sh all` (no waiver) and
  `kinds-tests.php`/`identity-tests.php` re-verified clean against real PostgreSQL 16.13
  afterward. The lesson repeats the third finding's own: a signature generalised for one
  caller's convenience has to be grepped for across the *whole* tree, not just the directory
  this session happened to be editing in - and the narrowest correct callable is not always the
  first one that compiles.

## DOCTRINE (operator-locked decisions)

- [Verification discipline](feedback_verification_discipline.md) — "it loads" is not
  evidence; which suite answers which question; the parity suite is not a CI gate.

## REFERENCE PATTERNS

- [Local environment](reference_local_environment.md) — running the three suites on this
  Apple Silicon Mac: podman directly (the documented `docker compose` invocation is broken
  here), the frontend probes' yarn/Playwright prerequisites, the Nix build, git signing.
- [DEVONthink index](reference_devonthink_index.md) — `~/src/grocy` is indexed into the
  `Code-Projects` database, so full-text and proximity search spans the code and the docs
  corpus at once. It indexes **master's tree only, never a worktree** — find things with it,
  verify nothing with it.

## USER PROFILE

- [Operator profile](user_profile.md) — datagen24, sole maintainer; BLUF replies; the
  deployment target and the machine the work happens on.

## ACTIVE PROJECT STATE

- [Project state](project_state.md) — pointer to the authorities plus what is in flight
  that no plan row states yet.

## Archive

When this index passes ~150 lines, move superseded entries to
`archive/MEMORY_pre_consolidation_YYYYMMDD.md` and leave a pointer here.
