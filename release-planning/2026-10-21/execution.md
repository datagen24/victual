# 0.5.2 execution ledger

Append one row per merged PR that changes a 0.5.2 issue, in the same PR. Record the merge
commit after merge in a follow-up row, not by rewriting an earlier row. Planning decisions
and GitHub writes live in [release-data.json](release-data.json).

| Date | Issue | PR | Change | Validation | Docstring coverage of touched files |
| --- | --- | --- | --- | --- | --- |
| 2026-10-21 | None | Planning PR | Seeded the 0.5.2 dashboard from the 0.5.1 scripts; assessed the six milestone issues; recorded pending decisions D1 to D8. | `refresh.py` then `render.py`. | `refresh.py` and `render.py` unchanged apart from version strings. |
| 2026-10-21 | #1026 | [PR](https://github.com/datagen24/victual/pull/1040) | Removed the four CanonicalJson/StderrLogger fallbacks with php-src 8.4/8.5 citations; three tests added to HelperUnitsTest. | helperunits, labelservices, labelapi pass on PHP 8.5.10 and 8.4.25; canonical-json-tests.php and node oracle agree on both. | CanonicalJson.php 14/14, StderrLogger.php 4/4, HelperUnitsTest.php 117/117. |
