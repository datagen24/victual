# 0.4.3 #890 validation evidence

Observed 2026-10-15 at current master `d0e51344d30bedd40eda9c679b4761b6b4e3de28`.

No new #890 implementation was needed. The merged tree already enforces the recorded maintainer rule in `helpers/BaseBarcodeLookupPlugin.php:242-259`: the lookup factor and computed inverse must be finite, positive, and normal before lookup writes begin. The existing 400 refusal is retained.

`tests/Pgsql/BarcodeLookupFactorNormalRangeTest.php:225-` covers accepted boundary values and exact persisted factor/inverse, normality, rejected neighboring values with no product/barcode/conversion writes, and both lookup insert and update branches. This restores/replaces the removed range regression without introducing an arbitrary medically meaningful decimal limit.

Focused PHP 8.4/PostgreSQL validation from the clean #890 audit worktree:

- `barcodelookup`: 192 tests, 361 assertions, passed.
- `stockcoverage`: 443 tests, 5,182 assertions, passed.
- PHP syntax checks and `git diff --check`: passed.
- Coverage was not measured in this focused run.

The #951 and #917 changes and their tests are on this same master commit. GitHub checks on `d0e51344d30bedd40eda9c679b4761b6b4e3de28` passed: tests, CodeQL, Psalm Security Scan, and prose. See [tests](https://github.com/datagen24/victual/actions/runs/38805586887), [CodeQL](https://github.com/datagen24/victual/actions/runs/38805586263), [Psalm](https://github.com/datagen24/victual/actions/runs/38805586847), and [prose](https://github.com/datagen24/victual/actions/runs/38805587059).

Issue #890 remains open. No issue state or milestone was changed. The evidence supports maintainer review for closure; it does not by itself create a versioned 0.4.3 candidate or authorize tagging.
