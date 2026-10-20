# 0.5.1 release handoff

0.5.1: 6 of 8 issues closed. Latest release v0.5.0 (published 2026-10-19T12:28:41Z). Master `e639389b`. 23 open issues and 1 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-20T17:17:47Z.

## Read first

Read the repository AGENTS.md, then docs/constitution.md, then [execution.md](execution.md) for what has merged. The 0.5.0 plan in [release-planning/2026-10-15](../2026-10-15/HANDOFF.md) is history: do not edit it.

## Standing rules

- Every PR that changes a 0.5.1 issue updates release-data.json, appends a row to execution.md and re-runs render.py in the same PR.
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.

## Keeping the dashboard current

```
python3 release-planning/2026-10-19/refresh.py
python3 release-planning/2026-10-19/render.py
```

refresh.py needs an authenticated `gh` and writes a new evidence directory; render.py is offline. Edit assessments (priority, size, fit, classification, next action, PR) in release-data.json by hand. Generated files are dashboard.md, dashboard.html and HANDOFF.md: never edit them directly.

## Open work

- #904 (P2, M): Merge PR #1027: -x5-b plus the nine post-2026-10-13 sites, every touched file documented to 80%, all 56 pgsql phases passing.
- #1010 (P2, M): Merge PR #1028: R5 evidenced by docs/releases/0.5.0-restore-drill.md; backup-restore.md corrected (roles.sql before pg_restore, restore as victual_migrate).
