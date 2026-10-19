# Consolidated compatibility review: #951, #917, #890

Reviewer: GPT-6 Astra high, read-only. Baseline: `b728d0d1a2203523155327b89a03716f915b58c4`. The working tree was at `302f1f68`; the reviewer did not substitute it for the named baseline. No tests or scripts were run and no state was changed.

## Verdict

**SHIP WITH FIXES.** #951 and #917 are bounded precision bug fixes that can remain separate PRs. They are not ready for release as complete until the importer verification failure is safe and the application fix covers the actual binding paths.

## Consequential findings

1. **Importer verification failure can leave target tables replaced.** `DatabaseImporter.php:884` commits before assertions at `:888-889`; the mismatch exception is at `:1893`, after the target rollback catch ends at `:882`. `Import()` rolls back only its SQLite read snapshot (`:353-371`), and the CLI catch at `bin/victual-db-import:145` exits without restoring the target. For supported SQLite input containing affected REAL values, an operator can receive “Import failed” after replacement; with `--force`, prior target data may already be gone. This contradicts #917’s clean-rollback claim for this baseline path. Keep copy verification inside the rollback boundary and test forced verification failure against target and ancillary state. Do not claim total import atomicity without covering post-copy transforms, permission seeding, and price-cache rebuilding (`:908-973`, `:1769`). [#917](https://github.com/datagen24/victual/issues/917)

2. **Importer binding can lose source REAL precision and normalized comparison can hide small errors.** `DatabaseImporter.php:1177-1187` passes PHP values to PDO without explicit round-trip serialization; `ValueComparison.php:49-51` rounds numbers to six decimal places. #917 records `2^63` changing from `9.223372036854776e+18` to `9.2233720368548e+18`. Test exact source-to-target readback for DBL_MAX, 2^63, and a finite 17-significant-digit fraction, plus a rollback case. Do not rely on the six-decimal differential comparison as proof of exact fidelity. [#917](https://github.com/datagen24/victual/issues/917)

3. **Application writes can invalidate lookup boundary validation after persistence.** `StockService.php:1517,1526` persists lookup floats after `BaseBarcodeLookupPlugin.php:255` validates them; trigger inverse generation is in `db/pgsql/baseline/06_triggers_a.sql:287`. With `precision=14`, accepted PHP_FLOAT_MIN and values around 2^1022 can become subnormal in storage or reciprocal. #951 records the affected intervals (99 lower-boundary and 80 upper-boundary values) on PHP 8.4.25/PostgreSQL 16.15. The existing accepted-row test checks positivity/finiteness, not exactness or normality (`tests/Pgsql/BarcodeLookupFactorNormalRangeTest.php:251-260`). Preserve the float through persistence, restore PostgreSQL readback assertions, and test both insert and update branches. Do not narrow the accepted range to compensate for binding loss. [#951](https://github.com/datagen24/victual/issues/951)

## Compatibility and implementation boundaries

The fixes need no schema change, new endpoint, permission, or type change. They are observable in future stored values, API numeric spelling, and derived conversions/prices/quantities. Existing rounded application values generally cannot be reconstructed; no repair migration is justified. Import correction only occurs when an operator runs the importer, which replaces common-table rows and can discard later target changes.

The application fix must cover more than LessQL. `StockService.php:460-487` uses LessQL for stock/ledger values; `StockLineageService.php:42-45,97-99` and `StockService.php:4750-4752` use direct PDO. A PDO-level correction must preserve existing integer, string, boolean, null, and statement-subclass behavior. The reviewer did not establish every route’s binding path or inspect installed LessQL source.

Importer policy remains separate from lookup policy. Its factor preflight accepts positive finite source factors (`DatabaseImporter.php:478`) and disables triggers while copying (`:833`); do not impose #890’s lookup normal-factor/inverse rule on imported historical data. For operational recovery, follow the manual’s stop-application-and-label-workers requirement, retain source and target recovery copies, use corrected importer code, and resume only after verification and post-copy processing. No candidate implementation or PostgreSQL 15/16/runtime/test results exist yet.

Keep two PRs with explicit ownership: #951 application binding and persisted lookup/API effects; #917 importer fidelity, comparison evidence, verification rollback, and recovery guidance. The CLI importer uses the application’s PDO connection (`bin/victual-db-import:132`), so #917 may depend on a shared statement-level behavior delivered by #951; avoid competing serializers.

## #890 decision and closure evidence

Live #890 retrieval showed exactly one comment, [issuecomment-6145979764](https://github.com/datagen24/victual/issues/890#issuecomment-6145979764), with no superseding decision:

> Require the lookup factor and its computed inverse to be finite, positive, normal floats. Test boundary cases against PostgreSQL.

The decision disclaims exact arithmetic and medically meaningful limits. Close #890 only after merged PostgreSQL evidence shows the following. Accepted boundary floats and adjacent values survive the actual lookup write exactly. Stored factor and trigger inverse are finite, positive, and normal. Rejected neighbors return 400 without product/barcode/conversion writes. Both insert and update branches work, and the removed regression is restored or replaced. #949 alone is insufficient. #917 is not needed for this lookup-specific closure.
