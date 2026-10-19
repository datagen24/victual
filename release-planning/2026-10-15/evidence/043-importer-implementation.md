# #917 implementation report

Implementation agent: GPT-6 Sol high. Worktree: `/private/tmp/victual-917-precision`, branch `codex/gpt6_0.4.3-917-import-precision`, commit `406ac2b4b654f068a868f76e8cf5abd77244621b`, based on #951 commit `9b18a394ae8cce6181e58737c3585f8dd2671a05`. Worktree clean. PR #967 is stacked on #966. No issue or milestone state changed.

## Change

Importer writes use #951’s shared `RoundTripFloat::Format` behavior for SQLite REAL values. Copied-row value checks now run before sequence resynchronization and lineage rebuilding, within the PostgreSQL target rollback boundary. The three-file diff updates `services/Database/DatabaseImporter.php`, `.devtools/pgsql/import-tests.php`, and `docs/manual/getting-started.md`.

Tests verify exact source-to-target values for DBL_MAX, 2^63, and `0.12345678901234566`. They force a post-copy verification mismatch and compare all target rows, identity sequences, and trigger states including epoch/outbox/login attempts. They preserve caller source and nested target transaction ownership. They verify successful plain-PDO and CLI imports, post-copy hashing/permissions, and unchanged six-decimal comparator behavior. No new input acceptance rules, schema, or API changes were added.

## Validation

The import phase passed in isolated PHP 8.5.10/PostgreSQL/SQLite Podman containers at this commit: 87 `ok` assertions, 0 `FAIL`, `SQLITE IMPORT OK`, `SUITE PASSED`. PHP syntax checks, `git diff --check`, selected-page Vale, and full pre-commit Vale passed with 0 new findings. The tested commit is `406ac2b4b654f068a868f76e8cf5abd77244621b`.

## Recovery limit

The copy and verification transaction restores the previous target after verification mismatch. Post-copy cleanup, hashing, repair/cache rebuild, and seeding remain after that transaction commits. Their failure can leave a partly processed import. The operator guide directs operators to keep the SQLite source and a PostgreSQL backup until import completion and restore the backup before retry after post-copy failure. The patch does not claim full import atomicity.
