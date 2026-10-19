# Plan 22 acceptance evidence for #701, #702 and #742 (gate M1)

Reconciled 2026-10-18 against victual `master` at `dbfea56b` and victual-kit `main` at `dc05ee8`.
Gate M1 says: reconcile the acceptance evidence for #701, #702 and #742, and do not count general
conversion creation or full web-form convenience as required #742 scope.

## #701: refill history, reorder estimates and notices. Recommendation: close.

All seven criteria are server-side and evidenced in [plan 22, "Issue 701"](https://github.com/datagen24/victual/blob/master/docs/plans/22-medication-tracking.md#issue-701-refill-history-reorder-estimates-and-notices).
The criterion-by-criterion table there maps each one to code and tests.

| Criterion | Evidence | Status |
| --- | --- | --- |
| Fill history, supply, rules, explicit dates, precedence, corrections | Migration 0307 and its triggers, `RefillEstimator`, `ConsumptionRefillService`; pgTAP 033, `RefillEstimatorTest`, `ConsumptionRefillServiceTest`, fixtures 03, 07, 08 | Met |
| 14-day fallback only without a rule; unknown inputs; provenance | `estimate.source` / `estimate.reason`; `RefillEstimatorTest`; fixtures 01–04 | Met |
| Approaching/due/ordered/received, lead, repeat suppression | `RefillEstimator::Status`, notices and acknowledgement; service, API and race tests; fixtures 05–10; browser probe | Met |
| Transfers, consumption and orders do not reset fills or add stock | Three named tests; the probe reads stock back after an order and a receipt | Met |
| Scoped access and revocation on routes, UI and notices | `AuthoriseRefill`; `ConsumptionRefillRaceTest`, `ConsumptionRefillDisclosureTest`. The review's list/notices race was fixed in PR #755 (commit `74707545`) with `ConsumptionRefillReadRaceTest` | Met |
| API, OpenAPI, snapshots, UI, translations, operator docs; native delivery in victual-kit | PRs #749 and #751 | Met |
| Tests for 30/90-day fills, overrides, dates, invalid inputs, boundaries, corrections, dedup, receipt | ADR-0042's verification table, covered by the classes above and fixtures 01–11 | Met |

Maintainer approvals of 2026-10-10 cover the wording and the ADR-0042 deviations. What remains is
native delivery (#702) and integrated release verification (#703). Neither is a #701 criterion.

## #702: native acceptance. Recommendation: close after one victual-kit fix.

The maintainer's 2026-10-11 decision accepts simulator evidence, recorded in [plan 22, "Issue 702"](https://github.com/datagen24/victual/blob/master/docs/plans/22-medication-tracking.md#issue-702-native-acceptance-on-simulator-evidence)
and `.devtools/plan22/NATIVE-ACCEPTANCE.md`.

| Criterion | Evidence (victual-kit) | Status |
| --- | --- | --- |
| Platform versions and per-medication authorization | `ui-round-1-phone.md` (iOS 27.0 simulator), authorization rows of `plan-22-native.md` | Met |
| Mapping and source-location selection with admin, Adult and Child keys | `ui-round-1-phone.md` cases 7a–7c, `macos-app-manual-test.md` | Met |
| Sync, retry, reconciliation, refill: unit tests plus the 28-fixture replay | `plan-22-native.md`; `live-server-replay.md` (28/28 against v0.4.0, `f73d2bec`) | Met |
| Simulator end-to-end scenario | The UI round and replay above | Met |
| Exact client and server commits on every piece of evidence | **Not met.** `docs/acceptance/plan-22-native.md` still names client commit `743ab1f` (line 16 and every row), which no longer resolves after a rebase and predates the v0.4.0 spec pin. Still true at victual-kit `main` `dc05ee8` | **Open** |

The fix is in victual-kit: re-run the matrix and record a resolving commit. That fits with the
#891 client update, which will move the client to the 0.5.0 server anyway. The evidence above was
taken against a v0.4.0 server, so recording the matrix against 0.5.0 is the natural point to do it.
The release record lists the real-device limitations (Health store identifiers, late and background
delivery).

## #742: master-data rights for the medication setup. Recommendation: close, with the residual recorded.

| Research item | Evidence | Status |
| --- | --- | --- |
| Inventory every write route against its permission | `.devtools/issue742/RESULTS.md`: 93 probes across five users, measured 2026-10-10 | Done |
| Decide for each whether the permission is right, too coarse or missing | ADR-0043, accepted 2026-10-12 (#887) | Done |
| For medication: a narrower right, a purpose-built endpoint, or leave it with administrators | ADR-0043: `MASTER_DATA_ADD` below `MASTER_DATA_EDIT` creates products, locations, units, barcodes, stores and groups (#883–#886). The lookup may create its purchase-to-stock factor. **Conversion creation stays `MASTER_DATA_EDIT`.** | Done |
| Check the new right against ADR-0014 and ADR-0018 | ADR-0043's index row: "compatible with 0014 and 0018, no amendment" | Done |

The residual is that a medication mapped in a unit other than the product's stock unit needs a
conversion, and only `MASTER_DATA_EDIT` can create one. Gate M1 excludes general conversion
creation from #742's required scope, and ADR-0043 records the restriction. If the maintainer wants
it revisited, that is a new issue for a later release, not a reason to keep #742 open.

## Summary

| Issue | Recommendation | Action needed |
| --- | --- | --- |
| #701 | Close | Tick the seven boxes; comment linking plan 22's section |
| #702 | Close after the victual-kit fix | Record a resolving client commit in `plan-22-native.md` (part of #891) |
| #742 | Close | Comment recording the conversion residual and ADR-0043; optionally a later-release issue for it |

With these closed, gate M1's remaining item is #703, the integrated release verification.
