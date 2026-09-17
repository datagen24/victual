---
name: Project state
description: Pointers to the authorities on project status, plus the in-flight items no plan row states yet. Deliberately thin — the docs corpus is what stays current.
type: project
---

**The authorities, in reading order.** Do not summarise these here; the summary drifts and
they do not.

1. [AGENTS.md](../AGENTS.md) — ground rules, and the only place they belong.
2. [docs/constitution.md](../docs/constitution.md) — standing principles.
3. [docs/adr/README.md](../docs/adr/README.md) — decisions in force. Do not contradict an
   Accepted ADR; do not treat a Proposed one as accepted.
4. [docs/plans/README.md](../docs/plans/README.md) — the status table is **the authority on
   what is real**, and it carries the wave order and each plan's remaining work.

A landed plan gains an **Executed** section recording what actually shipped, including
divergence from the plan body above it. When you want to know whether something exists, read
the status row and the Executed section, in that order.

## In flight as of 2026-09-14

Recorded because it is younger than the last corpus update, not as a substitute for it.

- **The wave table was recommitted 2026-09-14** and every open work item has a GitHub issue:
  #127–#139 were opened that day for the ready items (plan 23, the ADR-0022/0023/0018/0020
  acceptances, S11, plan 29, plan 15's remainder, plan 20's remainder, S32, 06-Q5, 26 piece 2,
  18's Home Assistant checks). When a plan row and an issue disagree, the row is the authority
  and the issue needs a comment.
- **Label infrastructure is delivered** (plans 25, 27, 06): issue #79 closed 2026-09-09 on a
  physical print and scan-back. Only #93's K3S deployment half is open, and it is the same work
  as plan 20 piece 4 (#133).
- **ADR-0023 is Accepted**: [PR #149](https://github.com/datagen24/victual/pull/149) merged
  2026-09-14, all four prerequisites met (2 and 3 via a disposable spike on
  `claude/sonnet5_adr0023-prerequisites` at `4da3d35d`; 4 via inspecting the maintainer's
  pre-fork Grocy backup, which also surfaced [issue #148](https://github.com/datagen24/victual/issues/148) —
  `enfore_product_nesting_level` enforces one-level nesting on `UPDATE` only, never `INSERT`,
  in both engines). Plan 07 was retired and 30/31 scheduled by the separate PR on 2026-09-14, which also
  renumbered the wave 4 reservations (28→0275, 29→0276, 30→0277, 31→0278, 22→0279–0280) — moved
  again the next day when #148's own fix took 0277 ahead of 30 (30→0278, 31→0279, 22→0280–0281).
  **Plan 30 landed 2026-09-15** as `migrations/0278.pgsql.sql`; see its own
  [Executed](../docs/plans/30-nested-product-groups.md#executed) section. Plan 31 is next in
  wave 4, now unblocked.
- **ADR-0022 is Accepted 2026-09-14**, all eight prerequisites met: [PR #153](https://github.com/datagen24/victual/pull/153)
  merged, closing [issue #129](https://github.com/datagen24/victual/issues/129). 1, 2, 3, 5,
  6, 7 via a disposable spike, merged into master (not left unmerged like ADR-0023's spike
  branch) as [PR #152](https://github.com/datagen24/victual/pull/152) at `64ec8f1` — its
  files live on under `.spike-adr22/`, permanent evidence rather than a citation to a branch
  that could be deleted, closing the gap [PR #145](https://github.com/datagen24/victual/pull/145)
  had to close for ADR-0021. 4 reworded then met by a real check against `victual.openapi.json`
  (no collision with the four new field names; legacy tare fields still present at zero); 8
  decided 2026-09-14 (`cb99bf3`). Plan 28 is unblocked outright; plan 29's weighing half is
  unblocked. `docs/plans/README.md`'s status table still needs a pass for both rows (left out
  of the acceptance PR deliberately, matching how ADR-0023's left plan 30's row) — that and
  plan 28/29's actual implementation are what's next.
- **Wave 5 opened 2026-09-15**: 19 piece 2 landed as `0281.pgsql.sql` (#170); its four open
  price channels ([issue #176](https://github.com/datagen24/victual/issues/176) — importer
  cascade, `/stock/bookings/{id}`, `product_barcodes.last_price`, four flag-only Blade pages)
  closed the same day as `0282.pgsql.php`, so 14 piece 2
  ([issue #83](https://github.com/datagen24/victual/issues/83)) is no longer waiting on
  anything to freeze its per-role snapshot. Also merged that day: #173 (API key
  expiry/rotation, 0280), #171 (the Manual), #172 (fabric 7), #174 (files API S32), #175
  (label tree path), #169 (undo self-production). Review follow-ups: #177 (files API own-picture bypass) and #178 (manual corrections)
  closed by 2026-09-17 via PRs 187 and 188; #179 (fabric leftovers) has one last PR pending. Plan 15's remainder ([issue #132](https://github.com/datagen24/victual/issues/132))
  is unblocked now that #130 and #121 are closed. **2026-09-16: plan 32 landed** as
  `0283.pgsql.php` (issue #182); its migration was written at 0285 and renumbered down after CI
  refused the hole left by plan 22's still-unwritten claims below it — `migrations/
  RESERVATIONS.md`'s own numbering log is the authority on the current claim table; re-read it
  when resyncing rather than trusting a number claimed earlier. **Next unclaimed migration
  number: 0286.**
- **Memory harness**: `claim_check_hook.py` runs in `CLAIM_CHECK_ENFORCE_MODE=warn`; promote
  it to `block` once it stops false-firing. Project hooks need the workspace-trust dialog
  accepted before they run at all.

**How to apply:** update this file when an entry here becomes wrong, and delete an entry once
the corpus states it. A line here that the plans README also states should be the line here
that gets cut.
