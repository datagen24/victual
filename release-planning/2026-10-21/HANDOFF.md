# 0.5.2 release handoff

0.5.2: 8 of 8 issues closed. Latest release v0.5.1 (published 2026-10-20T19:11:29Z). Master `e7edd6b5`. 18 open issues and 0 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-22T15:18:42Z.

## Read first

Read the repository AGENTS.md, then docs/constitution.md, then [execution.md](execution.md) for what has merged. The 0.5.1 plan in [release-planning/2026-10-19](../2026-10-19/HANDOFF.md) is history: do not edit it.

## Standing rules

- Implementation PRs do not touch release-planning/. After a wave's PRs merge, one planning PR records them: ledger rows, PR links, decisions and GitHub writes, then refresh.py and render.py (maintainer rule, 2026-10-21: per-PR dashboard edits made every parallel PR conflict).
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.

## Keeping the dashboard current

```
python3 release-planning/2026-10-21/refresh.py
python3 release-planning/2026-10-21/render.py
```

refresh.py needs an authenticated `gh` and writes a new evidence directory; render.py is offline. Edit assessments (priority, size, fit, classification, next action, PR) in release-data.json by hand. Generated files are dashboard.md, dashboard.html and HANDOFF.md: never edit them directly.

## Open work

- None.
