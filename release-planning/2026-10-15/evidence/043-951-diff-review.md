# Independent diff review: #951

Reviewer: GPT-6 Luna medium, read-only. Candidate: `/private/tmp/victual-951-precision` on `codex/gpt6_0.4.3-951-precision`, based on published v0.4.2 commit `b728d0d1a2203523155327b89a03716f915b58c4` plus an uncommitted diff.

## Finding and verdict

No surviving code finding. **SHIP** to the next review gate. This does not establish release readiness; #890 remains open pending merge and CI, including the coverage ratchet.

The patch preserves float round-trip text in both identified write paths: LessQL insert/update formatting in `services/Database/ChangeTrackingLessQlDatabase.php:61` and direct PDO parameter arrays in `services/Database/InstantStatement.php:60`. The reviewer found no application `bindValue()` or `bindParam()` path that bypasses these mechanisms.

The focused test exercises accepted boundary values through lookup update and insert, checks stored factors and trigger inverses, checks a representative API JSON response, and probes integer/string/boolean/null parameter compatibility. Raw storage checks cover all accepted boundaries; API JSON precision is checked for `PHP_FLOAT_MIN` only.

The reviewer did not run validation. The implementation report records PHP 8.4 PostgreSQL results against base SHA plus the uncommitted diff: barcodelookup 192 tests/361 assertions; stockcoverage 443/5,183; stockconcurrency 23/142; `git diff --check` passed. Coverage ratchet and full CI remain unverified.
