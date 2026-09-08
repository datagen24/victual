---
name: Session 2026-09-08 — memory and claim-check harness
description: Built the memory index and its topic files so the auto-orient and claim-check hooks have something to load; hook registration was left to the operator.
type: project
---

**What was asked:** populate `memory/MEMORY.md` from the template the operator had just
placed there, because two quality harnesses depend on it.

**The two harnesses**, both present as untracked files in the master working tree and both
duplicated between `.claude/hooks/` and `.agents/hooks/`:

- `auto_orient.py` — a `UserPromptSubmit` hook that injects `memory/MEMORY.md` once per
  session, truncating at 8000 bytes on a line boundary. Fail-safe: a missing file, a read
  error or an already-oriented session injects nothing and never blocks the prompt. It
  resolves `memory/MEMORY.md` relative to the session `cwd`, so committing `memory/` to the
  repository is what makes it work in each of the ~20 worktrees rather than only in master.
- `claim_check_hook.py` + `log_claim.py` — a `Stop` hook that matches done-claims in the
  last assistant message and warns (default) or blocks when
  `~/.claude/state/claim_checks/log.jsonl` has no entry inside 15 minutes.

**What was written**, five files under `memory/`: the index, `user_profile.md`,
`feedback_verification_discipline.md`, `reference_local_environment.md`, `project_state.md`,
and this log.

**The design decision worth keeping.** This repository's documentation corpus is unusually
complete and its plans README was current to the same day, so a memory set that restated
project status would only drift while the corpus stayed right. Memory was scoped instead to
the three things the corpus does not carry: the host-specific way to run each suite, what
counts as verification evidence, and session continuity. The index says so explicitly, and
says the corpus wins on disagreement.

Sources were the operator's own accumulated notes, carried across rather than re-derived: the
podman workarounds and the `-v /app/packages` trap, the `manageapikeys-qr` probe that fails on
clean master here, the Nix build's two container constraints, and the 1Password signing
failure and the stash damage that followed it once.

The `log_claim.py` protocol was put in the index rather than in `AGENTS.md`. The hook's own
usage notes say to tell the agent in `CLAUDE.md`; because the index is auto-loaded, stating it
there satisfies the same requirement without editing the operator's doctrine file.

**Added mid-session:** the operator noted that `~/src/grocy` is synced with DEVONthink. Rather
than record the claim, it was probed — which produced
[reference_devonthink_index.md](reference_devonthink_index.md) and two findings the claim did
not contain. The index is clean (`vendor/` and `public/packages/` return nothing) and close to
live (files written at 19:32 were indexed by 19:33). But **worktrees are excluded**:
`name:StockService` returns exactly one hit rather than one per worktree, so DEVONthink shows
master's tree and not the branch under edit. That makes it a discovery tool and disqualifies
it as a verification one, which is now stated in both files.

**Left open, deliberately.** Nothing was committed, no `settings.json` was created or edited,
and `AGENTS.md` was not touched — so as of this session neither hook fires. The files were
written into the master working tree next to the operator's own untracked copies rather than
onto the `claude/memory-harness-setup-04be24` worktree branch, which means committing them
still needs a branch: `master` is not pushed to directly.

Two flaws in the template were corrected rather than carried forward: the prefix table said
`docs/plan` where every section body says `project_*`, and the "under 200 lines" guidance
described the wrong limit — the injector's cap is 8000 bytes.

**One open risk, unresolved.** `RECENT SESSIONS` is an append-at-top list in a file that
around twenty concurrent worktrees will all edit. Every pair of branches that both wrap a
session conflicts on that line. The index caps the list at five and says to keep both lines on
conflict; if it becomes noise, the fix is to stop tracking per-session lines in the shared
index and let the session files stand alone.
