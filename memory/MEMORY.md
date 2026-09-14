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

- **2026-09-14 — ADR-0022's acceptance gates** (issue #129). Prerequisites 1, 2, 3, 5, 6 and 7
  run as a disposable spike against real PostgreSQL 16.13, not asserted —
  `claude/sonnet5_adr0022-prerequisites` at `64ec8f1`, results in `.spike-adr22/RESULTS.md` on
  that branch. Coexistence's negative control reproduces the existing tare mechanism's bug for
  real (18.8 lb "consumed" against an actual 3.8 lb, because it reads the whole-product total).
  Undo found a sharper defect than the ADR's own wording: undoing an opening on a measured
  entry doesn't merely strand the measurement, it violates the coherence constraint outright
  and would abort the transaction — clearing all four measurement columns together is required
  to complete the undo, not just to satisfy decision 9's intent. One finding not already in the
  ADR text: convertibility (decision 3) and coherence (decision 8) are different properties —
  only the second can be a database `CHECK`; the first has to be the write path's own job.
  Prerequisite 4 reworded on `claude/vibrant-volta-6osz2n` at `4cf7bd0` (the snapshot it named
  doesn't exist yet); prerequisite 8 was already decided the same day (`cb99bf3`). The
  bookkeeping-only accepting pull request is still open work. [→](project_state.md)
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
- **2026-09-14 — ADR-0020's acceptance gates** found prerequisite 2 unenforced: stage.py
  rewrites a link into an unpublished plan to an absolute GitHub URL, and `mkdocs build
  --strict` cannot see an absolute URL, so a mistyped plan link published as a 404 silently
  (demonstrated, exit 0). stage.py now resolves every rewritten link against `git ls-files`
  and fails naming it. Prerequisite 4 inspected over 46 pages: no page fails, but "wave N"
  was undefined anywhere on the site, so the Development overview gained a label table.
  Acceptance itself is still [issue 135](https://github.com/datagen24/victual/issues/135)
  and stays bookkeeping-only. [→](project_state.md)
- **2026-09-14 — Wave recommit and issue sweep** audited every plan row against its body,
  corrected the stale 06/25/27 rows (label path delivered 2026-09-09), rewrote the wave
  table so each item is ready or names its gate, and opened issues #127–#139. No open issue
  was closable. [→](project_state.md)
- **2026-09-08 — Memory and claim-check harness** built this index and its topic files;
  merged as part of the andon commit, then fixed a nesting bug that stopped the orient hook
  from being registered at all and moved the wiring into a tracked `.claude/settings.json`.
  [→](project_session_20260908.md)

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
