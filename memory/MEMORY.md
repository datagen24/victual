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
