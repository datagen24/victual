# 0.5.1 release handoff

0.5.1: 5 of 8 issues closed. Latest release v0.5.0 (published 2026-10-19T12:28:41Z). Master `0e3a15ff`. 23 open issues and 0 open PRs repository-wide; 5 open issues have no milestone. Observed 2026-10-20T13:47:21Z.

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

- #252 (P3, M): Maintainer accepts or rejects ADR-0051 (Proposed) in a lifecycle-only PR; then one PR removes the webhook residue it lists. The other #252 items follow the review comment, in a separate PR.
- #904 (P2, M): On the pushed branch -x5-b (91 files), merge master, migrate the post-2026-10-13 stragglers, run the full pgsql suite once, open one PR.
- #1010 (P2, M): Run the drill in its own session against the v0.5.0 digests; report at docs/releases/0.5.0-restore-drill.md; check the manual's php bin/victual-migrate step against the migrate image.
