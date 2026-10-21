# 0.5.2 execution ledger

Append one row per merged PR that changes a 0.5.2 issue, in the same PR. Record the merge
commit after merge in a follow-up row, not by rewriting an earlier row. Planning decisions
and GitHub writes live in [release-data.json](release-data.json).

| Date | Issue | PR | Change | Validation | Docstring coverage of touched files |
| --- | --- | --- | --- | --- | --- |
| 2026-10-21 | None | Planning PR | Seeded the 0.5.2 dashboard from the 0.5.1 scripts; assessed the six milestone issues; recorded pending decisions D1 to D8. | `refresh.py` then `render.py`. | `refresh.py` and `render.py` unchanged apart from version strings. |
| 2026-10-21 | #762 | [#1041](https://github.com/datagen24/victual/pull/1041) | Create form gets a labelled Roles multi-select (defaults pre-selected, assignable roles only, USERS_EDIT callers only); POST /users then PUT /users/{id}/roles; alert with a permissions link if the second call fails; users list lock icon becomes a "Roles" button with an aria-label. | `householdpages` 93 tests (730 assertions) and `rbac` 116 tests pass after review fixes; `userform-roles.js` (terminal state, odd usernames, empty selection), `roles.js` and `userform-password.js` pass against a podman demo instance. | RolesService.php 100%, UsersController.php 100%, HouseholdPagesTest.php 100%, rbac-subprocess-helper.php 100%, userform.js 100% |
