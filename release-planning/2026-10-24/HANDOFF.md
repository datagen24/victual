# 0.5.5 release handoff

0.5.5: 6 of 6 issues closed. Latest release v0.5.4 (published 2026-10-23T19:01:57Z). Master `e1c341e5`. 18 open issues and 0 open PRs repository-wide; 6 open issues have no milestone. Observed 2026-10-24T22:45:08Z.

## Read first

Read the repository AGENTS.md, then docs/constitution.md, then [execution.md](execution.md) for what has merged. The 0.5.2 plan in [release-planning/2026-10-21](../2026-10-21/HANDOFF.md) is history: do not edit it.

## Standing rules

- Implementation PRs do not touch release-planning/. After a wave's PRs merge, one planning PR records them: ledger rows, PR links, decisions and GitHub writes, then refresh.py and render.py (maintainer rule, 2026-10-21: per-PR dashboard edits made every parallel PR conflict).
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.
- A newly found or disclosed library vulnerability goes into the next release (maintainer policy, 2026-10-24); Dependabot PRs carry that milestone.

## Keeping the dashboard current

```
python3 release-planning/2026-10-24/refresh.py
python3 release-planning/2026-10-24/render.py
```

refresh.py needs an authenticated `gh` and writes a new evidence directory; render.py is offline. Edit assessments (priority, size, fit, classification, next action, PR) in release-data.json by hand. Generated files are dashboard.md, dashboard.html and HANDOFF.md: never edit them directly.

## Open work

- None.
