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
- **2026-09-15 — Plan 26 piece 2 landed** (the Manual, issue #138), wave-independent.
  `docs/manual/` (getting started; an 85-setting configuration reference generated-checked
  against `config-dist.php`; nine household-task pages plus a tips page; seven operator
  pages, including a label-printing chapter rewritten for plans 25/27's actual subsystem
  rather than only the legacy webhook) replaces `docs/usage.md` and `docs/label-printing.md`,
  wired into `mkdocs.yml`'s nav and a new `TREES` entry in `.devtools/docs/stage.py`. Both
  counts issue 138 cites (81 pages, 84 settings) were stale from corpus growth; measured
  today: 85 settings, 89 page routes — the issue's own route-counting grep only excludes the
  literal `/api` route, not the whole `/api` group, so it had to be redone by line range.
  Verified: `python3 .devtools/docs/stage.py --no-api && mkdocs build --strict --site-dir
  /tmp/docs-site` (the exact `lint` job commands) exit 0; 321/321 offsite links resolving;
  the new `check_settings_reference()` reports 85/85 settings covered. Not run: booting a
  live instance to click through Getting started end to end (writing-only session scope) —
  said plainly in the plan's Executed section rather than assumed. Found and fixed in the
  same change, not re-litigated: ADR-0020 is **Accepted** 2026-09-14 in its own file and
  index row; `docs/plans/README.md` still called it Proposed and is now corrected — that is
  a stale cross-reference fix, not [issue 135](https://github.com/datagen24/victual/issues/135)'s
  acceptance bookkeeping, which this session did not touch. [→](project_state.md)
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
  `label-printers.js` both pass, repeatably (3+ runs). The container image build was not
  verifiable in this sandbox (no nix) but is verified now: [PR #172](https://github.com/datagen24/victual/pull/172)'s
  `flake` CI job reported the real `yarnOfflineCache` hash from its own fixed-output-derivation
  failure, and the job then built and booted all three images clean. A same-day maintainer
  review on the PR found a real second regression the origin-default fix didn't cover — fabric
  7's `Line` still derives `left`/`top` from its two points, but the box-to-origin translation
  now folds in `strokeWidth`, so an untouched line's `left`/`top` sat `strokeWidth/2` short and
  every drag carried that constant into the saved document, drifting a line further on each
  touch. Fixed the same way (`absorb()`'s line branch adds `strokeWidth/2` back) and confirmed
  both analytically (constructing the same `Line` against real 7.4.0) and with a diagonal-line
  drag before/after. See plan 27's Executed section for the full account, including the two
  documentation-lag and one test-race findings the same review caught. [→](project_state.md)
- **2026-09-15 — Issue #137 landed** (plan 06 Q5, the location label's tree path). Dispatched
  claiming locations still print through `VICTUAL_LABEL_PRINTER_WEBHOOK` and that the `vctl:`
  labels machinery was unbuilt — both stale: PR 113 (2026-09-08) already moved location
  printing onto plan 25/27's job path, and the webhook survives only for the five
  `*/printlabel` routes (products, stock entries, recipes, chores, batteries). Added
  `FieldCatalogue`'s `location.path`, reading `locations_resolved`'s self row via a new
  `'select'` key `LabelCaptureService::Capture()` now honours (a catalogue field's SQL can
  differ from its stored column); refuses the capture rather than printing a blank line if a
  location has no self row (unreachable through the app, since the depth guard blocks nesting
  that far). `LabelIdentityService::Resolve()` reads the same view for `/locationlabels`,
  falling back to the bare name on a miss; retired snapshots stay name-only, unchanged. No new
  migration — reused plan 08's `locations_resolved`/`hierarchy_depth_limit()` rather than a
  third `Get…WithPaths()` helper, since both call sites want one row, not the whole tree.
  Verified against real PostgreSQL 16.13 in this session's own sandbox:
  `artifact-tests.php` (53, extended with a real nested capture and a `TemplateDocument`
  acceptance check), `identity-tests.php` (10047), `registry`/`print-job`/`worker-api`/
  `canonical-json` tests unaffected by the widened fixture, `check-migrations.php` clean. The
  frontend probe (extended for a nested path) passed against a real demo instance booted per
  the `run-app` skill; a disposable script also drove capture and resolve directly against
  that instance's real schema and triggers for a genuinely nested location, confirming the
  composed path both ways and a name-only retirement snapshot. `test-support.php`'s fixture
  now loads migration 0273 unmodified; `identity-tests.php`'s hand-built `locations` stub
  could not load 0273 as-is (no `locations_name_key` to drop, `parent_location_id` added a
  second time later) and instead carries a copy of its function and view — the same
  shadowed-stub shape plan 08 already found in this test file's sibling. Not verified: a
  physical print through the real Rust renderer, unavailable in this sandbox. [→](project_state.md)
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
