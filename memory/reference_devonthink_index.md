---
name: DEVONthink index of this repository
description: ~/src/grocy is indexed into the Code-Projects database, giving full-text and proximity search over code and the docs corpus together — but it indexes master's tree only, never a worktree.
type: reference
---

The master checkout at `~/src/grocy` is **indexed** into DEVONthink (indexed, not imported —
the files still live on disk and DEVONthink reads them in place). Verified 2026-09-08:

- Database **`Code-Projects`**, UUID `78C4296E-CB25-4E56-AF39-0AF85D3EE2BB`.
- The repository is the group **`/grocy/`**, UUID `0A4629B8-8559-4CC3-AAF1-226AE3303013`.
  Pass that as `group_uuid` to scope a search; the same database also holds other projects
  (`/sleep_adapter/`, `/mcp-grocy/`) whose `node_modules` and `build/DerivedData` **are**
  indexed, so an unscoped query picks up their noise.
- **The index is close to live.** Files written at 19:32 were indexed by 19:33 with correct
  locations. No manual re-index step was needed.
- **Only source and records are indexed.** `vendor/`, `public/packages/` and the like return
  nothing — `name:ClassLoader OR name:autoload OR name:jquery-3` scoped to the group is 0
  hits. So a code search is not drowned in dependencies.
- **Full text spans code and prose together.** The exact phrase
  `"wire contract is the invariant"` returns ADR-0005, `docs/constitution.md`, `AGENTS.md`
  **and** `services/ApplicationService.php` — four hits, one query, across two file types.

## The caveat that matters

**Worktrees are not indexed.** `name:StockService` returns exactly one hit,
`/grocy/services/`, not one per worktree — `.claude/worktrees/` is excluded. So DEVONthink
shows the **master tree**, which is not the tree you are editing on a branch.

Never confirm a change through DEVONthink, and never read a file's current contents from it
when you are on a branch: it will happily return the master version and look right. Use it to
find *where something is discussed*; use Read and Grep in the worktree for what the code
*says now*. See [[feedback_verification_discipline]] — a DEVONthink hit is not a verification.

## What it is good for

Grep is exact, current, and scoped to the working tree; it stays the tool for code truth.
DEVONthink adds what grep cannot do over a corpus of 27 plans, 21 ADRs, a constitution and a
few hundred source files:

- **Proximity and phrase operators** — `NEAR/5`, `BEFORE/n`, `AFTER/n`, `~substring`,
  quoted phrases, `AND`/`OR`/`XOR`/`NOT`, and `{ any: … }` / `{ all: … }` sub-criteria.
- **Filters that narrow a conceptual search** — `kind:markdown`, `extension:sql`,
  `modified:This Week`, `created>=2026-09-01`, `item:flagged`, `wordcount>2000`.
- **Similarity, not just matching** — `find_similar_records` on an ADR or plan surfaces the
  records that discuss the same thing without sharing vocabulary, which is the case grep
  structurally cannot serve.

**How to apply:** reach for it when the question is "where was this decided, and what else
touches it" and you do not know the filename — the fork's answer is usually spread across an
ADR, a plan's Open questions, and the code that implements it. Reach for Grep when you know
what string you are looking for, or when you are on a branch and the answer must be current.
