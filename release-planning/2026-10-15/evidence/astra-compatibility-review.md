# Consequential compatibility review

Reviewer: Codex GPT-6 Astra, high reasoning. Read-only review at `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`. No tests ran. The coordinator records this report before incorporating recommendations. Issue reproductions are prior evidence, not new reproductions.

## Findings and placement

| Issue | Finding at the named commit | Reader recommendation |
|---|---|---|
| #905 / #920 | UserfileOwnerReadTest.php:899–913 explicitly characterizes detaching a protected battery reference and reading the bytes. GenericEntityApiController.php:659 and FilesApiController.php:47 retain group-level write permission. F5 was not risk acceptance. | 0.5.0 decision work; #905 cannot close before #920. New owner-based write authorization restricts existing operations. |
| #908 | BatteriesApiController.php:51,65 authorizes tracking then returns the row. | 0.5.0 decision: require view before mutation or preserve tracking with a reduced response. Decide undo separately. |
| #909 | ADR-0043:404 explicitly defers battery writes to the battery/equipment rework. | Keep deferred; do not reopen the answered decision. It does not settle #908 or #920. |
| #933 | shoppinglist.blade.php:102,223,307 exposes barcodes and shortfall. | 0.5.0 policy decision; gating removes visible page/API data. |
| #916 | Nonfinite strings can persist and make subsequent API reads fail. | 0.5.0: success-to-refusal input change, existing-data reporting and no silent repair. Recommend production resolution. |
| #946 | BaseApiController.php:969 purifies numeric input; NumericStringAcceptanceTest.php:984 records transformed permission IDs. UsersApiController.php:33,51 confirms USERS_EDIT and the subset rule, not ADMIN. | 0.5.0: raw validation/refusal changes accepted grammar. Integrity defect, not established privilege escalation. |
| #947 / #950 | NumericStringAcceptanceTest.php:743,843,892 records default/substitute/ignore behavior. | 0.5.0 decision: reject invalid-present values before mutation or explicitly document chosen no-ops. Preserve legitimate numeric strings, including ordinary spaces. |
| #930 | StockService.php:5131 savepoint, :5151 rollback, :5318–5319 later conversion mutation support the possible interleaving. No two-connection reproduction was found. | May remain a patch investigation: serialization preserving the existing guarantee can be compatible. Cover concurrent insertion and global fallback changes, not only existing-row edits. |
| #942 | ConsumptionEventFixtureTest.php:140 asserts balance. One failing run and passing rerun leave root cause unknown. | May remain a patch investigation/fix if it restores existing undo semantics. Do not call it confirmed production corruption or dismiss it as flaky. |
| #951 / #917 | StockService.php:1516,1526 passes factors to persistence; DatabaseImporter.php:1172 executes values without explicit round-trip float serialization. | Conditional patch fixes. Last stored digits change; assess existing values, never invent recovery of pre-rounded originals. Restore PostgreSQL boundary read-back test. |
| #952 | Reported chain overflow refuses atomically already. Scalar-normality decision L does not define chain policy. | 0.5.0 decision. Recommend atomic specific refusal over silent clamping/skipping. |
| #926 | ArtifactService::Accept at :33, lookup :80, collection :219 have reuse/collection gaps. Historical artifact refusal at :138,160 must survive. Issue calls method Store, but current name is Accept. | Conditional patch repair for new render acceptance; preserve collected_at and historical reprint refusal. Verify both reuse and race. |
| #937 | mcp/src/victual/transaction.ts:13 emits victual_error. | 0.5.0: outcome_unknown changes the machine-readable category and retry advice; coordinate consumers. |

## Existing and recommended gates

Plan 22:21 explicitly requires #774/ADR-0045 and #775/ADR-0046 plus its other named deliverables. Plan 22:254–273 requires conversion, undo, authorization, rollback, supported-PostgreSQL, coverage, upgrade, image and Helm evidence. ADR lifecycle remains separate bookkeeping (docs/adr/README.md:36). ADR-0048/0049 remain Proposed. No rule makes every open security issue an automatic release blocker.

Recommend four additional production dispositions: close #905/#920 or define an effective operational restriction; prevent #916 creating unreadable rows; investigate/disposition #930 and #942 before claiming integrity; resolve #951 before declaring #890's persisted-boundary guarantee. Other packet issues need specific workflow/risk assessment, not automatic promotion to mandatory gates.

## Shipped release disposition

docs/releases/0.4.1.md:13–33 records MASTER_DATA_ADD, permission removals, refusing migrations, requeue CLI, MCP categories and generated-client changes. ADR-0043:239,324 targeted 0.5.0. Preserve tags; add a dated deviation note and actual grant/client/migration guidance. Do not claim these first ship in 0.5.0. Complete hotfix1's prohibited draft at docs/releases/0.4.1-hotfix1.md:3, empty effect at :15 and unrun rows :21–24, preserving pre-tag versus later evidence. Reconcile the original 0.4.1 tag claim with PR #957's later record.

## Unanswered decisions

- #920: existing operation rights plus every current owner's read authorization for reference removal, owner deletion and byte replacement/deletion; alternative is a separately specified file-management policy. Must be atomic and cover shared owners/cascades/derivatives.
- #908: view permission before charge mutation, or reduced response retaining tracking-only clients. Undo has a separate 204 contract.
- #933: gate barcodes/shortfall with STOCK_VIEW (recommended), or explicitly define them as shopping-domain information.
- #916/#946: raw field-aware validation, preserving established per-field numeric grammar, versus detecting/refusing purifier rewrites. Refuse nonfinite values; report existing rows.
- #947/#950: recommend 400/no mutation for invalid-present fields; alternative named no-op/ignore semantics.
- #952: refuse unusable resolved chains atomically with a specific error; alternative skip/clamp changes conversion meaning.
- Adopt recommended production dispositions or explicitly accept named residuals and affected operations. Existing maintainer decisions remain verbatim in historical-DECISIONS.md.

Issue evidence: saved open-items.json, comments-946.json, comments-905.json, comments-890.json and pulls-919.json/pulls-955.json. Scope did not expand into a new audit.
