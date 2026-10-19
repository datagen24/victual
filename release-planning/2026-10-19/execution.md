# 0.5.1 execution ledger

Append one row per merged PR that changes a 0.5.1 issue, in the same PR. Record the merge
commit after merge in a follow-up row, not by rewriting an earlier row. Planning decisions
and GitHub writes live in [release-data.json](release-data.json).

| Date | Issue | PR | Change | Validation | Docstring coverage of touched files |
| --- | --- | --- | --- | --- | --- |
| 2026-10-19 | None | Planning PR | Seeded the 0.5.1 dashboard; moved #909 to vNext, #252 to 0.5.1; opened #1010. | `render.py` rendered all three views. | `refresh.py` and `render.py` 100%; template script 100%. |
| 2026-10-19 | #988 | [PR #1012](https://github.com/datagen24/victual/pull/1012) | Prune job opens or updates a pull request from chore/vale-baseline-prune instead of pushing to master. | actionlint clean; Vale tooling tests 29 OK with pinned Vale; ruff clean. | audit.py 10/10, test_staged.py 14/14. |
| 2026-10-19 | #987 | [PR #1013](https://github.com/datagen24/victual/pull/1013) | Consume refuses a recipe_id that names no recipe with 400; accepted spellings unchanged. | pgsql phases wirecontract (177 OK) and openapidocument (15 OK); OpenAPI document check exit 0. | StockApiController.php 43/43, NumericStringAcceptanceTest.php 14/14. |
| 2026-10-19 | #991 | [PR #1016](https://github.com/datagen24/victual/pull/1016) | ADR-0048 item 9 records the name-existence signal as an accepted residual. | Vale check on the ADR: 0 new findings. | No functions touched. |
