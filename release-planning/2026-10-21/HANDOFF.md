# 0.5.2 release handoff

0.5.2: 0 of 7 issues closed. Latest release v0.5.1 (published 2026-10-20T19:11:29Z). Master `8b2ec3fc`. 24 open issues and 3 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-21T17:08:57Z.

## Read first

Read the repository AGENTS.md, then docs/constitution.md, then [execution.md](execution.md) for what has merged. The 0.5.1 plan in [release-planning/2026-10-19](../2026-10-19/HANDOFF.md) is history: do not edit it.

## Standing rules

- Every PR that changes a 0.5.2 issue updates release-data.json, appends a row to execution.md and re-runs render.py in the same PR.
- Every PR keeps docstring coverage at or above 80% for the functions in each file it creates or edits, counted before the first push.
- Run refresh.py before render.py whenever GitHub milestones or issue states have changed. refresh.py reads GitHub only and never writes to it.

## Keeping the dashboard current

```
python3 release-planning/2026-10-21/refresh.py
python3 release-planning/2026-10-21/render.py
```

refresh.py needs an authenticated `gh` and writes a new evidence directory; render.py is offline. Edit assessments (priority, size, fit, classification, next action, PR) in release-data.json by hand. Generated files are dashboard.md, dashboard.html and HANDOFF.md: never edit them directly.

## Open work

- #737 (P2, M): Wave 3, after PR 5 (OpenAPI): fix in 0.5.2, 204 for every authorised request, rights before existence.
- #762 (P2, S): In progress: PR 2 (wave 1), branch claude/v0-5-2-762-user-roles: two-step create, roles replace defaults, labelled Roles button.
- #912 (P2, M): Wave 2, after PR 1: option (a), throw, with a fixed fallback body in the error handler.
- #948 (P3, S): Split per D6. This issue keeps the merge-field descriptions only: PR 3 (wave 1), branch claude/v0-5-2-948-field-descriptions.
- #1007 (P3, M): Wave 2, after PR 2 (strings.pot): Medication presets, Medication inbox, Refills.
- #1026 (P3, S): In progress: PR 1 (wave 1), branch claude/v0-5-2-1026-fallbacks. Lands before #912.
- #1035 (P3, S): In review: draft PR 1039 (wave 1 PR 4), branch claude/v0-5-2-files-415.
