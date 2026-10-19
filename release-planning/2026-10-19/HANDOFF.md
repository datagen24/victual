# 0.5.1 release handoff

0.5.1: 0 of 8 issues closed. Latest release v0.5.0 (published 2026-10-19T12:28:41Z). Master `1058a243`. 27 open issues and 0 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-19T20:48:10Z.

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

- #252 (P3, M): Draft a keep or delete proposal per item on the issue; the maintainer decides each one before any code moves.
- #900 (P2, M): Review factual correction separately; defer behavior decisions unless production label scope needs them.
- #904 (P2, M): Recover 31b3439c/72fdffef without replacing them; migrate only callers required by upcoming validation first.
- #987 (P3, S): Review and merge PR #1013.
- #988 (P3, S): Merge PR #1012, enable 'Allow GitHub Actions to create and approve pull requests', then run the workflow by hand once.
- #989 (P3, S): Decide whether the 500 body may carry the title; otherwise log it server-side.
- #991 (P3, S): Decide whether the existence signal matters; update ADR-0048 item 9.
- #1010 (P2, M): Run the documented dump and restore against the v0.5.0 images on a disposable database and record the report.
