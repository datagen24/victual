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

## In flight as of 2026-09-08

Recorded because it is younger than the last corpus update, not as a substitute for it.

- **Label infrastructure (plans 25, 27, 06)** is the active line of work. Plan 25 group B —
  jobs, configuration and the worker API — merged in
  [PR #109](https://github.com/datagen24/victual/pull/109). Plan 27, application-owned
  templates and the rendering contract, is open in
  [PR #110](https://github.com/datagen24/victual/pull/110). Location-label printing stays
  gated by [issue #93](https://github.com/datagen24/victual/issues/93) on physical
  acceptance.
- **This memory harness is untracked.** `memory/`, `.claude/hooks/` and `.agents/hooks/` sit
  in the master working tree without being committed, and no `settings.json` registers
  either hook, so neither fires yet. Registration is a `UserPromptSubmit` entry for
  `auto_orient.py` and a `Stop` entry for `claim_check_hook.py` (start it in
  `CLAIM_CHECK_ENFORCE_MODE=warn`, promote to `block` once it stops false-firing) — merged
  into any existing arrays, never overwriting them.

**How to apply:** update this file when an entry here becomes wrong, and delete an entry once
the corpus states it. A line here that the plans README also states should be the line here
that gets cut.
