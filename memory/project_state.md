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
  in both engines). It did not retire plan 07 or touch `docs/plans/README.md`'s wave order —
  that stays #82's later PR.
- **ADR-0022 is not yet accepted, but every non-decision prerequisite is now discharged**
  (issue #129): 1, 2, 3, 5, 6, 7 via a disposable spike on
  `claude/sonnet5_adr0022-prerequisites` at `64ec8f1`; 4 reworded on
  `claude/vibrant-volta-6osz2n` at `4cf7bd0` (named a snapshot — plan 14 piece 2 — that
  doesn't exist yet); 8 decided 2026-09-14 (`cb99bf3`). What's left is the bookkeeping-only
  accepting pull request itself, then plan 28 and plan 29's weighing half can start.
- **Memory harness**: `claim_check_hook.py` runs in `CLAIM_CHECK_ENFORCE_MODE=warn`; promote
  it to `block` once it stops false-firing. Project hooks need the workspace-trust dialog
  accepted before they run at all.

**How to apply:** update this file when an entry here becomes wrong, and delete an entry once
the corpus states it. A line here that the plans README also states should be the line here
that gets cut.
