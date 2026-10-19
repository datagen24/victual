# #951 implementation report

Implementation agent: GPT-6 Sol high. Worktree: `/private/tmp/victual-951-precision`, branch `codex/gpt6_0.4.3-951-precision`, based on published tag commit `b728d0d1a2203523155327b89a03716f915b58c4`. Changes are uncommitted. No issue, milestone, PR, tag, or release state changed.

## Change

The bug had two serialization points. LessQL formats float values before PDO receives them, and direct PDO `execute()` arrays stringify floats using PHP’s configured precision. `services/Database/RoundTripFloat.php` adds a 17-significant-digit round-trip formatter. `ChangeTrackingLessQlDatabase::format()` applies it before LessQL formats insert/update values. `InstantStatement::execute()` applies it only to floats in direct PDO parameter arrays; other values retain existing behavior.

`tests/Pgsql/BarcodeLookupFactorNormalRangeTest.php` now checks persisted factor and trigger inverse exactness on both conversion update and insert paths, including `PHP_FLOAT_MIN`, its next accepted neighbor, and the upper accepted boundary and neighbor. It checks representative API JSON fields remain numbers with expected values and exercises direct PDO with integer, string, boolean, and null parameters.

## Validation

All commands used the PHP 8.4 dev container and PostgreSQL service; results are tied to base SHA `b728d0d1a2203523155327b89a03716f915b58c4` plus the uncommitted worktree diff.

- `.devtools/pgsql/run-tests.sh barcodelookup`: 192 tests, 361 assertions passed.
- `.devtools/pgsql/run-tests.sh stockcoverage`: 443 tests, 5,183 assertions passed.
- `.devtools/pgsql/run-tests.sh stockconcurrency`: 23 tests, 142 assertions passed, including statement-subclass compatibility.
- `git diff --check`: passed.

Coverage ratchet, full CI, and an independent diff review have not run. No candidate commit SHA exists yet.

## Observable effect and limits

The stored factor and reciprocal now retain intended PHP binary64 values. For `PHP_FLOAT_MIN`, old PHP float stringification produced bits `0010000000000000` as `000FFFFFFFFFFFFD`; the corrected API numeric spelling is `2.2250738585072014e-308` rather than `2.2250738585072e-308`. For the upper boundary, old storage bits `7FD000000000000A` become `7FD0000000000000`. The “before” API spellings are inferred from measured PHP stringification; tests verify the corrected API response. Existing rounded rows remain unchanged. Coverage of all application float-write surfaces remains subject to independent review.
