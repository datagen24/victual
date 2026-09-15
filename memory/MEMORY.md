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

- **2026-09-15 — Issue #126 landed** (label designer off fabric 5.x, plan 27's last
  dependency-bump-blocking item besides S32). Fabric 7.4.0 via a `type="module"` shim
  (`views/layout/default.blade.php`) assigning `window.fabric` from `dist/index.min.mjs` —
  fabric 6 dropped the UMD build entirely, and this tree has no bundler; a module script
  always finishes before `DOMContentLoaded`, and every `window.fabric` use in
  `labeltemplateeditor.js` is inside `$(document).ready`, so load order between the two
  script tags cannot race. `nix/runtime/nginx-conf.nix` gained a `\.mjs$` location forcing
  `application/javascript`, since the pinned nginx's own bundled `mime.types` is not
  guaranteed to know the extension and this sandbox has no nix to check it against directly.
  **The real find**: fabric 7's default `originX`/`originY` changed from `left`/`top` to
  `center` — every shape this editor draws only ever set `left`/`top`, so under the new
  default every one rendered shifted up-and-left by half its own size, and `absorb()`'s drag
  math read a corrupted position back. The shipped CI probe (add, save, publish) passed with
  this defect in place, because nothing in it ever checked *where* anything rendered — found
  instead by driving a real browser interactively (drag, read the saved x_mm/y_mm back,
  reload, resize, read again), watching it silently do nothing, and comparing
  `getActiveObject().oCoords` against hand-computed geometry until the mismatch pointed at
  the origin default rather than the drag math. Fixed by pinning `originX:'left',
  originY:'top'` on every shape `shapeFor()` builds. `label-designer.js` now carries the
  drag/reload/resize check permanently, plus an explicit Playwright viewport — the default
  one is short enough that a scrolled canvas can sit under the fixed top navbar, which looks
  identical to a drag that did nothing. Verified against a real PostgreSQL 16.13 demo
  instance booted per `.agents/skills/run-app/SKILL.md`: the updated `label-designer.js` and
  `label-printers.js` both pass, repeatably (3+ runs). **Not verified**: the container image
  build — this sandbox has no nix, so `nix/hashes.nix`'s `yarnOfflineCache` is reset to the
  bootstrap placeholder rather than a guessed value, and needs a real `nix build .#frontend`
  before `nix flake check` or an image build will pass. See plan 27's Executed section for
  the full account. [→](project_state.md)
- **2026-09-15 — Plan 30 landed** (nested product groups, issue #124), unblocked by the same
  day's #148 fix below. Migration `0278.pgsql.sql` is `0273.pgsql.sql` (plan 08) with the
  nouns changed: `product_groups.parent_product_group_id`, `UNIQUE(parent_product_group_id,
  name) NULLS NOT DISTINCT`, `product_groups_resolved` (second consumer of
  `hierarchy_depth_limit()`), and the nesting/delete guards, advisory lock and `VOLATILE`
  included. `product_groups` carries no explicit `select()` list and no OpenAPI schema of its
  own (unlike `Location`), so the new column reached the wire for free and only
  `ProductGroupResolved` needed adding. New `StockService::GetProductGroupsWithPaths()`/
  `GetProductGroupAncestorIds()`; group dropdowns show the path only where a write depends on
  it (the group form's parent picker, the product form's group picker) — filter-only group
  selects are untouched, since plan 30 Q2 (shopping-list grouping) is unanswered. The mixed
  node ADR-0023 decision 6 claims needs no special case (a group holding a product and a
  subgroup at once) is demonstrated in the new suite phase rather than merely argued.
  Verified against real PostgreSQL 16.13 in this session's own sandbox (started the local
  `postgresql` service and ran the suite directly, not a spike branch): `check-migrations.php`
  clean with no waiver, the new `.devtools/pgsql/nested-product-groups-tests.php` (41/41,
  `run-tests.sh productgroups`) including the same concurrent-re-parenting construction plan
  08's case 10 uses (measured 2.50s lock wait, `provolatile = 'v'` asserted), and `run-tests.sh
  all` green with no regressions. Two defects the new phase's first run caught were in the
  test itself, not the migration (backwards `UPDATE` parameters in a depth-refusal case; a
  mixed-node count that forgot case 2's own leftover fixture rows) — both are recorded in the
  plan's Executed section as a caution about trusting a first green run of hand-written SQL
  parameters. The new browser probe was written and reviewed but **not run**: this sandbox's
  PHP is 8.4.19 and the app refuses to boot below 8.5.0 on every route, and the PHP 8.5
  package is on a host (`ppa.launchpadcontent.net`) the outbound proxy returns 403 for —
  confirmed by reproduction (curled `/stockoverview` under 8.4, got the refusal text at HTTP
  200), not assumed. Plan 31 (directed substitution, issue #125) is next in wave 4, now
  unblocked. [→](project_state.md)
- **2026-09-15 — Issue #148 fixed** (`enfore_product_nesting_level` UPDATE-only trigger),
  unblocking plan 30. Reproduced the bug for real first, against baseline DDL loaded into a
  local PostgreSQL 16.13: three plain `INSERT`s (Protein, then Beef parented to Protein, then
  Beef Roast parented to Beef) built the two-level chain with no rejection. The original
  predicate turned out to be one-directional, not just INSERT-blind — it only rejects a row
  being given a parent while something already points at *it* as a parent, so a leaf inserted
  straight under an already-nested product was never caught even by an UPDATE touching the
  leaf; verified that a naive "just add BEFORE INSERT to the unchanged body" would have left
  the exact reported scenario possible. Migration `0277.pgsql.sql` adds the missing direction
  (a product's own named parent must not itself have a parent) alongside the original check,
  folds both events into one `BEFORE INSERT OR UPDATE` trigger, and nulls out any existing
  violation the way `migrations/0130.sql` once did. Took the lowest free migration slot rather
  than the next unclaimed number, since 0277 was already plan 30's claim — renumbered 30→0278,
  31→0279, 22→0280–0281 in `migrations/RESERVATIONS.md`, with the plan docs and
  `docs/plans/README.md` updated to match (ninth application of the lowest-free-slot rule).
  Verified against real PostgreSQL 16.13 (fix rejects both the INSERT and UPDATE forms of the
  attack, ordinary single-level reparenting still works, the existing
  `trigger-tests/03_parent_child_products.sql` scenario still rejects with the same message)
  and `php .devtools/pgsql/check-migrations.php` (`MIGRATION NUMBERING OK`) after `composer
  install --ignore-platform-reqs` (host PHP is 8.4, composer.json wants 8.5.*). Not run: the
  full `trigdifftest.php`/demo-data harness, which needs `/scratch/demodata` and config
  bootstrap beyond this session's scope. [→](project_state.md)
- **2026-09-14 — ADR-0022 is Accepted** (issue #129, closed). Prerequisites 1, 2, 3, 5, 6 and 7
  discharged by a disposable spike against real PostgreSQL 16.13, results in
  `.spike-adr22/RESULTS.md` — merged into master as [PR #152](https://github.com/datagen24/victual/pull/152)
  at `64ec8f1` rather than left on an unmerged branch, so the evidence outlives the branch
  (closing the citation gap [PR #145](https://github.com/datagen24/victual/pull/145) named for
  ADR-0021). Coexistence's negative control reproduces the existing tare mechanism's bug for
  real (18.8 lb "consumed" against an actual 3.8 lb, because it reads the whole-product total).
  Undo found a sharper defect than the ADR's own wording: undoing an opening on a measured
  entry doesn't merely strand the measurement, it violates the coherence constraint outright
  and would abort the transaction — clearing all four measurement columns together is required
  to complete the undo, not just to satisfy decision 9's intent. One finding not already in the
  ADR text: convertibility (decision 3) and coherence (decision 8) are different properties —
  only the second can be a database `CHECK`; the first has to be the write path's own job.
  Prerequisite 4 reworded, then met by a real check against `victual.openapi.json` (no
  collision with the four new field names); prerequisite 8 was decided the same day
  (`cb99bf3`). Accepted by [PR #153](https://github.com/datagen24/victual/pull/153), all eight
  prerequisites annotated in place with what met them. Plan 28 and plan 29's weighing half are
  now unblocked; `docs/plans/README.md`'s status table still needs its own pass.
  [→](project_state.md)
- **2026-09-14 — ADR-0023's acceptance gates** (issue #128). Prerequisites 2 and 3 (the mixed
  node; the `NULLS NOT DISTINCT` name-uniqueness change) run as a disposable spike against real
  PostgreSQL 16.15, not asserted — `claude/sonnet5_adr0023-prerequisites` at `4da3d35d`.
  Prerequisite 4 needed a real catalogue and this fork has none of its own yet, so the
  maintainer supplied a pre-fork upstream Grocy SQLite backup; inspecting it found 22/66
  products used `parent_product_id` as pure taxonomy (confirming the ADR's own sampling on an
  independent dataset) and one genuine two-level chain, which does contradict decision 2 —
  traced to `enfore_product_nesting_level` checking only `UPDATE`, never `INSERT`, in both
  engines, filed as [issue #148](https://github.com/datagen24/victual/issues/148) rather than
  fixed inline. [PR #149](https://github.com/datagen24/victual/pull/149). [→](project_state.md)

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
