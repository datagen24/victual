# 0.5.1 execution ledger

Append one row per merged PR that changes a 0.5.1 issue, in the same PR. Record the merge
commit after merge in a follow-up row, not by rewriting an earlier row. Planning decisions
and GitHub writes live in [release-data.json](release-data.json).

| Date | Issue | PR | Change | Validation | Docstring coverage of touched files |
| --- | --- | --- | --- | --- | --- |
| 2026-10-19 | None | Planning PR | Seeded the 0.5.1 dashboard; moved #909 to vNext, #252 to 0.5.1; opened #1010. | `render.py` rendered all three views. | `refresh.py` and `render.py` 100%; template script 100%. |
