# 0.5.2 execution ledger

Append one row per merged PR that changes a 0.5.2 issue, in the same PR. Record the merge
commit after merge in a follow-up row, not by rewriting an earlier row. Planning decisions
and GitHub writes live in [release-data.json](release-data.json).

| Date | Issue | PR | Change | Validation | Docstring coverage of touched files |
| --- | --- | --- | --- | --- | --- |
| 2026-10-21 | None | Planning PR | Seeded the 0.5.2 dashboard from the 0.5.1 scripts; assessed the six milestone issues; recorded pending decisions D1 to D8. | `refresh.py` then `render.py`. | `refresh.py` and `render.py` unchanged apart from version strings. |
| 2026-10-21 | [#948](https://github.com/datagen24/victual/issues/948) | [#1038](https://github.com/datagen24/victual/pull/1038) | Label designer describes each merge field with a name, an explanation and an example value, and says the example is sample data a printed label replaces (D6). Client strings only. | Phases `labelservices` and `labelapi`; `label-designer.js` probe extended. Results in the PR. | `labeltemplateeditor.js` 22/22 (100%); `label-designer.js` 2/2. |
