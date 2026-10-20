# Independent diff review: #917

Reviewer: GPT-6 Luna medium, read-only. Candidate: commit `406ac2b4b654f068a868f76e8cf5abd77244621b`, stacked on #951 commit `9b18a394ae8cce6181e58737c3585f8dd2671a05`. PR #967 was draft during review. Reviewer did not retrieve PR body, run tests, or change state; `git diff --check` showed no whitespace errors.

## Finding and verdict

No surviving finding. **SHIP.**

The #917-only diff preserves finite source values by formatting floats with `%.17g` before PDO binding. Tests cover `PHP_FLOAT_MAX`, 2^63, and a 17-significant-digit fraction with exact readback for direct importer and operator-command paths. Value and row-count checks run inside the target transaction. The forced mismatch test compares target table rows, identity sequences, and trigger states with their pre-import state, and verifies the caller-owned SQLite transaction remains open.

Existing importer preflight is unchanged. Operator documentation says verification mismatch rolls back target replacement and warns that later processing failures can leave a partly processed import. The review did not rerun tests; implementation evidence is in `evidence/043-importer-implementation.md`. The PR checks remain in progress.
