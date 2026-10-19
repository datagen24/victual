# Release execution ledger

Historical execution record: this ledger began during the 0.4.2 release cycle from base `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`, on branch `codex/gpt6_release-0.4.2-20261015`. Current release status is recorded in [Current status refreshed 2026-10-16](#current-status-refreshed-2026-10-16): v0.4.4 is published and 0.5.0 readiness planning is next.

## Scope and roles

Coordinator owns release records, operator permissions guidance (#913), integration and communication. GPT-6 Sol high owns API documentation and supporting tests for #953, #936, #893 and #801. No runtime behavior changes are intended. No ADR acceptance, tagging, publishing or deployment is authorized by these implementation briefs.

## Findings recorded before scope changes

- API worker confirmed OpenAPI 3.2.0 is already the source format; retain it.
- #801 has recorded maintainer answers: explicit 500 ApiError per operation, shared per-operation text/plain 503, unmatched 404/405 centrally, current calendar 500 documented. No new policy question is required for these points.
- Original #801 missing-401 inventory is stale after #822: all 180 operations have 401; 147 have 400 and one has 413. None has 500/503. Trace missing403 outcomes individually, never blanket-add them.
- #893 candidate schema names are already in the fixed list; remaining work is complete reached-schema coverage including inline system/info.
- #953 and #936 correct descriptions/types of existing behavior. No runtime change is required.

## Progress

Implementation in progress. No issue is marked complete until its validation is recorded.

- #801 recorded decision D4: calendar internal-event500 may contain the event title. Preserve/document existing behavior; runtime disclosure assessment stays a separate follow-up, not an implicit patch change.
- Release identity check and all nine release-version helper tests pass after completing the hotfix record. Full CI helper run initially blocked by missing local PyYAML/jsonschema; this is an environment failure, not a test regression.

- Release correction committed48dd2d34, PR961 open. #913 guide committed1b21984e. All105CI helper tests pass with pinned dependencies. Repository hook passes with no new Vale findings. API changes remain uncommitted during targeted validation.

- PR962 opened for #913, stacked on PR961; both lint/prose checks passed. API branch is codex/gpt6_api-contract-0.4.2-20261015 over1b21984e.
- Review requested preserving reached-schema coverage guards while removing the key-check filter; worker agreed. #953 also needs a document-mapping assertion so the regression fails with the old specification.

## Status reader evidence (Luna medium)

Static inspection over1b21984e found source-backed missing403 declarations in generic entity reads, stock reads/bookings, chores/batteries/tasks reads, calendar and label print operations. Sources: GenericEntityApiController505/550/631; StockApiController658/675/902/1033+/1175+/1195+/1317+/1346+/1374+; ChoresApiController73/92; BatteriesApiController22/36; TasksApiController21; CalendarApiController67/237; LabelsApiController73–86. Implementation worker will verify exact route mapping before adding each response.

Self/system routes and public pairing/typed worker routes are exceptions to household permission403. Invalid generic entities can return400 before permission checking; system/time offset validation is another concrete400. The reader's results are static evidence only; live representative refusal probes remain required. These findings are within #801, not a new runtime permission change.

- Reader/worker reconciliation:33 specific operations have missing reachable403. No additional missing400 confirmed by the bounded static pass. Worker owns exact operation inventory and live validation.
- #944 docs-only400 concerns capability_document NUL. driver_id NUL currently accepts200; persisted representation remains unverified. No driver_id input or storage change is included in0.4.2.

### Corrected status inventory

The reader's first33-operation result was incomplete. The final source trace adds two recipe fulfillment reads,12 label admin operations and7 typed-worker operations. Typed workers can receive403 for wrong printer/attempt ownership via LabelWorkerAuthorization/LabelValidationException(forbidden); those responses use ForbiddenLabel, not the household Forbidden schema. Pairing and renderer routes remain distinct; renderer auth failures map to422. This supersedes the earlier generic no403 worker assumption. No runtime behavior is changed. Exact method/path inventory is owned by the API worker and will be included in its evidence artifact.

- Worker corrected the reader's worker403 inference using existing LabelWorkerRoute403Test (#894): register and jobs/claim cannot return403. Their tentative additions were removed before final validation. Only owned attempt/printer/artifact routes can raise forbidden. Final response inventory must preserve this existing negative contract; earlier54-operation count was provisional and is superseded by the final tested inventory.

### Coordinator middleware finding

The no403 assumption for self/system routes is also incomplete: LabelWorkerRoute403Test::testAUntrustedProxyAddressIsNotAnswered403OnTheseRoutes uses GET/api/user as a positive403 control. BaseAuthMiddleware calls AuthenticateRequest on ordinary user routes before controller code (lines128+), allowing ReverseProxyAuthenticator's untrusted-proxy refusal. Current spec/user,/user/capabilities,/system/info,/system/time,/system/config omit403. This is within #801; source/negative worker exceptions must be reconciled before calling the status inventory complete.

- Worker confirmed the coordinator's middleware finding and added403 documentation to14 remaining ordinary routes. Source-derived partition assertion and representative PostgreSQL probes are being added; public pairing/typed worker/renderer middleware bypasses remain separate. Final count awaits validation.

## Targeted results reported by implementation worker

At the working diff over1b21984e on2026-10-15: source OAS3.2 validation passed; contract29tests/80assertions (four existing deprecations); labelapi177/4826; authstack89/459; errors reachable and unreachable17assertions each. Earlier stockcoverage433/5046, wirecontract and served-openapi checks passed before final assertions/status additions. Final reruns and negative controls remain pending; these are not final-commit results.

## Frozen API candidate

Committed as21bebca8 after coordinator review. All final targeted results and negative controls are recorded in.devtools/issue801/verification-0.4.2.md. No runtime or migration changes. Full suite and coverage remain CI gates. Repository Vale hook reports0new findings.

## Candidate PRs at 2026-10-15T16:07:15.701601+00:00

API PR963 is21bebca8985c679b33d41b852bd43a417bdbd0f2; draft release PR964 is80b09d12d39cbfae1d26d2ae7e1cbe514e692987. Merge order961→962→963→964, retargeting each after its predecessor. Version identity, rendered chart equality, OpenAPI validation,105CI helper tests and Vale0new findings passed after the version update. Full API suite/coverage and release Nix CI are running. No merge, tag or publication.

The existing upgrade rehearsal is built around0.2.0 timestamp conversion and refuses a different container-name prefix. Running it unchanged would not establish the actual0.4.1-hotfix1→0.4.2 upgrade. No migrations or runtime logic changed; preceding-release operator evidence remains explicitly unverified. Do not mislabel the old fixture as current upgrade evidence.

## Actual predecessor database validation

Source b5532aafd7b91306f7e98dd3361ba82d1ebbe5da populated an isolated PostgreSQL 16.15 database at migration 312. Candidate 80b09d12d39cbfae1d26d2ae7e1cbe514e692987 ran its migrator twice with exit 0. Schema and data dumps remained byte-identical after removing only random pg_dump guard lines; no application rows were excluded. Candidate reads preserved stock 3 and chore average 24.5. Coordinator independently compared the six normalized dump hashes. Durable evidence is evidence/upgrade-042/REPORT.md and adjacent dumps/probes. This supersedes the earlier missing no-op upgrade evidence. Restore, refusal and production-image rollout remain untested by this experiment. The disposable database was removed.

CI architecture scope: ordinary nix PR workflow runs ubuntu-latest only. The release workflow invokes both amd64 and arm64 after tagging (or a PR changing release.yml). Do not describe the current PR Nix run as both architectures. No workflow change is needed for this patch.

## Reconciliation and handoff at 2026-10-15T16:53:10.913153+00:00

The maintainer merged PR 963 at c4695492f6cfa0fd9bf8443d53be48de755cba07, completing stacked PRs 961 and 962. GitHub confirms issues 801, 893, 913, 936 and 953 closed. Master CI passed. The maintainer rebased draft release PR 964 onto master at d2f8dfe6; its tree equals tested candidate 80b09d12. The coordinator rebased only the evidence commit onto that history and pushed d49f2de7f4b50438f22e81bee071f141947e1b7b without force. The only tree difference from 80b09d12 is docs/releases/0.4.2.md. No coordinator merge, tag or publication occurred.

All 19 checks passed on 80b09d12, including Nix amd64 build/boot. Its walk passed 45 pages, 86 API reads and 9 writes. PostgreSQL 15/16 and packaged-image suites passed. Coverage is 14,619/15,100 (96.8145695364%), versus hotfix 14,618/15,100; no executable file below 75%. Evidence: evidence/042-candidate-checks.json, evidence/042-suite-ci.log and evidence/042-nix-ci.log. The API commit's 17 checks also passed. New d49f2de7 checks are pending; do not relabel predecessor results as new-head results.

Next action: finish fresh head checks; maintainer approval/merge of draft PR 964; final master verification; maintainer signed tag under docs/releases/README.md. Do not dispatch 0.4.3 before this release is disposed. The actual-predecessor no-op database check passed, but it does not establish restore or production rollout. Production 0.5.0 decisions and gates remain as planned.

## Pending external gates at 16:54 UTC

The coordinator requested authorization to merge #964 after its fresh checks pass; no answer has arrived. The signed tag remains the maintainer's action. Current-head tests run 38772515981 is pending behind rebased candidate d2f8dfe6 run 38766491563, whose PG15 and PG16 differential-suite steps are still running after roughly 45 minutes. No failure or root cause is established. Its other jobs passed. Current-head Nix run 38772516162 is running; prose/static security checks passed. Do not substitute 80b09d12's passing evidence for unobserved new-head checks. No cancellation, merge, tag or publication was performed.

Overall session elapsed approximately 122 minutes; the original planning phase completed in 33.3 minutes and was followed by user-authorized execution. No token usage measurement is available. One Sol high implementation worker and one Luna medium reader were used during execution. All agents are finished.

## PR 964 review follow-up

CodeRabbit reported three Minor findings: prepublication K3s images, premature README release claims, and a cancelled workflow cited as passed. The latter workflow 38766173488 is cancelled overall although all seven jobs are individually successful; release evidence must cite job success without claiming workflow success. The earlier all-checks claim is narrower than whole-workflow success.

Nix job 116551809438 on d49f2de7 failed at Start PostgreSQL. Logs show the socket-only temporary initialization server accepted connections at17:01:29.991, then shut down at17:01:30.240; the final readiness check failed at17:01:30.243 before final startup. This establishes an infrastructure readiness race, not the unresolved application fixture issue942. Open-issue title inspection found no matching readiness issue. Scope: necessary CI support for0.4.2, no application-runtime behavior change. Fix waits on127.0.0.1; the controlled test reproduces old failure and checks new success plus timeout.

Other corrections: README candidate language with release-helper synchronization; optional K3s image-publication preflight checks a frozen manifest before applying anything; review record cites successful jobs and retains cancelled workflow conclusion. No cluster apply, tag, publication or merge has been performed.

Review fixes committed and pushed as302f1f68e6fea0994ce630fa61b6209117893ffb. All107helper tests passed, including old-socket negative control, new-TCP success/timeout, and missing-image refusal. Version/render/diff checks and Vale pass. Three CodeRabbit threads were resolved after the fixes. Fresh CI is pending. Earlier candidate individual jobs passed but overall tests workflow was cancelled; this supersedes any earlier whole-workflow pass claim. No merge, deployment, tag or publication.

## Tag authorization

User confirmed “Merged, ready to tag.” PR964 merged at b728d0d1a2203523155327b89a03716f915b58c4. Its tree is identical to302f1f68, whose checks all passed including Nix and PG15/PG16. Master runs38780445437(tests) and38780446285(Nix) are still running. No v0.4.2 tag exists remotely. Signed tag and push are authorized; wait for those gates before tagging.

Signed local v0.4.2 at b728d0d1. SSH signature verified against configured signing public key through a temporary allowed-signers file; no repository/global trust configuration was changed. Tag is not pushed yet. Nix master workflow passed; tests master workflow still running.

## v0.4.2 tagged

Master tests run38780445437 and Nix run38780446285 succeeded. Pushed signed annotated tag v0.4.2, object4362a1e21b650b068a5f8fc34f86a87341df3a74, targeting b728d0d1a2203523155327b89a03716f915b58c4. Local SSH signature verification succeeded against the configured signing public key. Remote tag exists. Release workflow38783941785 started at that commit: https://github.com/datagen24/victual/actions/runs/38783941785 . Publication is pending; do not claim artifacts are available until its multi-architecture build and publish jobs succeed. User authorization: “Merged, ready to tag.”

## Publication confirmed

Release workflow38783941785 completed successfully. GitHub published v0.4.2 at2026-10-15T18:29:55Z: https://github.com/datagen24/victual/releases/tag/v0.4.2 . Identity, amd64 and arm64 flake builds and publication all succeeded. The six image digests and chart digest are retained in release-data.json and the release notes. GitHub Release has no attached assets; images and chart are published to GHCR. This closes0.4.2 execution. Next release is0.4.3.

## 0.4.3 agent preparation at 2026-10-15T18:38:04.020709+00:00

Prepared [agents-0.4.3.md](agents-0.4.3.md) against published v0.4.2 / master b728d0d1a2203523155327b89a03716f915b58c4. It assigns two concurrent read-only fact tasks to GPT-6 Luna medium (#951 application writes, #917 importer), then one consolidated GPT-6 Astra high compatibility review after reports are entered in this shared record. Implementation is assigned GPT-6 Sol high only after a fully specified brief and approval. #890 remains a persisted factor/inverse evidence gate, using the maintainer decision quoted verbatim in the brief. #916 and #774 remain deferred. No agents dispatched, as requested to preserve the usage window. Refresh GitHub state and confirm model availability before dispatch.

## 0.4.3 readers dispatched at 2026-10-15T18:48:15.110188+00:00

After the maintainer asked to start agents, dispatched two GPT-6 Luna medium read-only readers against named commit b728d0d1a2203523155327b89a03716f915b58c4: #951 application write precision (/root/043_app_precision_reader) and #917 SQLite importer precision (/root/043_import_precision_reader). The briefs permit one shared lower-tier GPT-6 Luna low helper at reader discretion, only after coordinator checks capacity; at most two readers and four total active agents including coordinator. No helper or implementation agent has been dispatched yet. Reports must be recorded here and in release-data.json before the consolidated GPT-6 Astra high review.

## 0.4.3 reader reports recorded at 2026-10-15

Both Luna reports are now durable in `evidence/043-reader-951.md` and `evidence/043-reader-917.md`, and summarized in `release-data.json` before the compatibility decision. #951’s accepted PHP float can be rounded before PostgreSQL persistence, leaving a trigger-computed inverse based on a different value; a shared binding change may affect other double-precision writes. #917’s importer comparison rounds numeric values to six decimal places, and the source report’s clean-rollback claim conflicts with the named baseline’s commit-before-value-comparison flow. These findings affect patch compatibility and importer recovery claims. A single consolidated Astra review is now the next gate. Neither issue placement nor implementation has been approved or changed.

## Consolidated compatibility review dispatched at 2026-10-15

The two completed Luna reports are recorded before review. One GPT-6 Astra high reviewer is now checking #951, #917, and #890 as a single bounded compatibility packet at baseline `b728d0d1a2203523155327b89a03716f915b58c4`. The reviewer has read-only restrictions and may not implement or change GitHub state. No helper or implementation agent is active. The briefs explicitly permit a scoped lower-tier OpenAI helper at the assigned agent’s discretion, subject to reader and total-agent limits.

## 0.4.3 compatibility review completed

The consolidated GPT-6 Astra high review is recorded in `evidence/043-astra-compatibility-review.md` and `release-data.json`. Verdict: **SHIP WITH FIXES** for the proposed patch scope; this is not release readiness. #951 must preserve application float binding across LessQL and direct PDO paths, and #917 must keep verification failure inside a safe target rollback boundary. The review confirms #890’s single live maintainer decision remains current and lists the persisted PostgreSQL evidence required to close it. The two issues remain separate PRs; importer-specific verification may depend on the shared binding behavior. A bounded #951 implementation task is starting first. No release milestone or GitHub state has changed.

## #951 implementation completed for review

The GPT-6 Sol high implementation is recorded in `evidence/043-951-implementation.md`. It preserves floats at both LessQL pre-formatting and direct PDO `execute()` boundaries. Focused PostgreSQL phases passed: `barcodelookup` (192 tests/361 assertions), `stockcoverage` (443/5,183), and `stockconcurrency` (23/142); `git diff --check` passed. The change remains uncommitted in `/private/tmp/victual-951-precision`, based on published v0.4.2 commit `b728d0d1`. Coverage ratchet and full CI remain unverified. An independent diff review is the next gate. #917 implementation has not started.

## #951 independent review completed

The GPT-6 Luna medium diff review is recorded in `evidence/043-951-diff-review.md`. Verdict: SHIP to the next review gate, with no surviving code finding. The reviewer found that float-only conversion covers both LessQL and direct PDO paths and preserves other parameter types. API precision was checked for PHP_FLOAT_MIN; accepted-boundary storage was checked across all cases. Coverage ratchet and full CI remain unverified, and #890 remains open pending merged evidence and CI. The reviewed changes are still uncommitted.

To start #917 without duplicating shared serialization, the next step is a local commit anchor only; no push or GitHub PR creation is authorized by this action.

## #951 locally committed; #917 implementation started

After independent review, the four-file #951 change was committed locally as `9b18a394ae8cce6181e58737c3585f8dd2671a05` on `codex/gpt6_0.4.3-951-precision`; the task worktree is clean. Focused validation was run on the exact content immediately before commit. The commit is not pushed and has no PR. A GPT-6 Sol high #917 worker is now implementing in `/private/tmp/victual-917-precision` on `codex/gpt6_0.4.3-917-import-precision`, based on that commit. The work remains a distinct importer diff and inherits #951 as an explicit dependency. It must prove exact source-to-target values and safe target state after verification mismatch. No GitHub state changed.

## #917 implementation progress

The worker confirmed the commit-before-comparison defect in its worktree based on #951 commit `9b18a394ae8cce6181e58737c3585f8dd2671a05`. Its staged, uncommitted importer diff uses the shared `RoundTripFloat` helper, moves row/value checks ahead of sequence resync and lineage backfill, and adds tests for full target rollback and exact REAL readback. The worker reports no architecture or API change. Focused import validation is pending in isolated Podman containers because the host Compose path is unavailable. No outcome from that run is recorded yet.

## #917 implementation complete; PRs opened for CI

The GPT-6 Sol high implementation is committed as `406ac2b4b654f068a868f76e8cf5abd77244621b`, stacked on #951 commit `9b18a394ae8cce6181e58737c3585f8dd2671a05`. Its focused importer phase passed 87 assertions with 0 failures (`SQLITE IMPORT OK`, suite passed) on PHP 8.5.10 with isolated PostgreSQL/SQLite containers. PHP syntax, diff check, and Vale passed. The importer check now verifies values before sequence resynchronization and lineage rebuilding within the target transaction; tests cover DBL_MAX, 2^63, a 17-digit fraction, a forced verification mismatch and target restoration, successful CLI/plain-PDO imports, and unchanged six-decimal comparison semantics.

Post-copy cleanup/cache/repair/seed failures may still leave partially processed imports; operator recovery guidance retains source and target backups.

Pushed reviewed #951 branch and opened [PR #966](https://github.com/datagen24/victual/pull/966) against `master`. Pushed #917 and opened [draft PR #967](https://github.com/datagen24/victual/pull/967) against the #951 branch so it remains a separate dependent diff. Both are now available for GitHub CI. #917 independent diff review is running. No merge, issue closure, tag, publication, or deployment was performed.

## #917 independent review completed; PRs ready for review

The #917 implementation report is `evidence/043-importer-implementation.md`; its independent GPT-6 Luna medium review is `evidence/043-917-diff-review.md`. Reviewer verdict: SHIP, no surviving findings. The importer diff did not change input preflight and does not claim full import atomicity. PR #967 is now ready for review and remains based on #966. At the observation just after opening, CI was in progress on both PRs: #966 had 16 checks reported, with 4 completed successfully, 1 neutral, and the rest running; #967 had 9 checks, with 4 successful and the rest running. No failures were reported in that snapshot. No merge or release operation was performed.

## CI refresh after both PRs opened

Latest GitHub check-run snapshot: #966 has 17 checks, 13 successful, 4 running (`suite`, `images`, `suite-floor`, `frontend-security`), 0 failures. #967 has 9 checks, 5 successful, 4 running (`images`, `suite`, `frontend-security`, `suite-floor`), 0 failures. Both PRs are open and ready for review; #967 targets #966's branch. Heads are unchanged at `9b18a394ae8cce6181e58737c3585f8dd2671a05` and `406ac2b4b654f068a868f76e8cf5abd77244621b`. This is a status snapshot, not CI completion or release readiness.

## Maintainer release decisions recorded at 2026-10-15T20:43:54Z

D1 retains the already applied milestone batch, including the 0.6.0 destination; milestone dates remain unset. D2 selects core Helm plus cluster label and shutdown evidence for the 0.5.0 production claim. Home Assistant #139 and MCP #86 are excluded from that claim. The local proposal places #93 and #133 in 0.5.0; their actual GitHub assignments remain Unassigned until a metadata change is applied. D3 requires existing operation rights plus every current owner's read authorization, atomically with the mutation.

D4 requires raw field-specific validation, 400 with no mutation for invalid supplied values, and errors for nonpositive shopping amounts and product IDs. D5a requires BATTERIES_VIEW plus tracking permission before charging; undo remains a separate unresolved decision. D5b gates shopping barcodes and stock shortfall on STOCK_VIEW. D6 requires atomic refusal of writes that create unusable conversion chains, with a specific error. D7 adopts the individually specified production gates; each needs evidence or a narrowly justified restriction. No Proposed ADR was accepted. The structured record and generated dashboards preserve actual assignments separately from the proposal.

## #966 suite-floor correction pushed

On 2026-10-15, the initial suite-floor failure was traced to two stale expected response strings in `NumericStringAcceptanceTest` for `inventory.new_amount` value `1e-3`. The #966 precision fix returned `0.001000000000000334`; the test expected `0.0010000000000003`. This was deterministic output drift from the intended precision correction, not a race. Updated both expectations and pushed commit `ace47b4e46325344160792638d439a902e16bbcf` to PR #966. Focused PHP 8.4/PostgreSQL `wirecontract` passed 175 tests, 6,756 assertions, and 8 skips. The fresh GitHub `suite` and `suite-floor` jobs were still pending at the latest check; lint, prose, MCP, CodeQL, Psalm, CodeRabbit, and Read the Docs had passed.

The added response digits are observable and must be stated in release notes. No merge occurred.

## 0.4.3 precision PRs merged

GitHub confirms PR #966 (`ace47b4e46325344160792638d439a902e16bbcf`) and stacked PR #967 (`00757847fac41b096a5c7d8d3f83211789236643`) merged at 2026-10-15T20:53:25Z and 20:53:26Z. Both point to merge commit `d0e51344d30bedd40eda9c679b4761b6b4e3de28`. Issues #951 and #917 closed at 20:53:27Z and 20:53:28Z. The precision fixes are merged, but 0.4.3 is not released: #890 still needs persisted normal-factor/inverse evidence. Post-merge prose CI passed; tests were queued and Psalm/CodeQL were running at the latest observation. Do not call the merge commit a validated release candidate until those checks finish. PR #966’s low-order response output change needs release-note treatment.

## #890 implementation started

The existing maintainer decision is already recorded in issue comment 6145979764: require the barcode-lookup factor and computed inverse to be finite, positive, normal floats, with PostgreSQL boundary tests. No new policy choice is needed. Assigned the existing GPT-6 Sol high implementation worker to a separate #890 branch from current master `d0e51344d30bedd40eda9c679b4761b6b4e3de28`. The brief requires exact persisted accepted boundary/adjacent values, finite-positive-normal factor and trigger inverse, rejected-neighbor 400 responses with no product/barcode/conversion writes, and insert/update coverage. Do not apply this policy to historical SQLite import.

The worker may prepare a separate PR but may not merge, close the issue, change milestones, tag or release. Current master tests and CodeQL were still running when work began.

## #890 found complete on merged master

The assigned worker found no missing implementation in current master `d0e51344d30bedd40eda9c679b4761b6b4e3de28`; #890 behavior and regression tests already arrived in the merged #951 change. The worker ran focused PHP 8.4/PostgreSQL `barcodelookup` (192 tests/361 assertions) and `stockcoverage` (443 tests/5,182 assertions); both passed. Syntax and diff checks passed. Coverage was not measured. Durable detail is in `evidence/043-890-validation.md`. GitHub tests, CodeQL, Psalm Security Scan, and prose all passed on the same current master. Issue #890 remains open; no duplicate PR, close, or milestone mutation was made.

The remaining 0.4.3 work is issue-state review and preparing the versioned release record/candidate, then validating it before requesting tag approval.

## #890 closed; 0.4.3 release candidate PR opened

The maintainer authorized closure and release preparation. Posted the merged-code and PostgreSQL evidence to [issue comment 6202614511](https://github.com/datagen24/victual/issues/890#issuecomment-6202614511), then closed #890 as completed at 2026-10-15T21:25:56Z. No new implementation PR was created because the behavior and boundary tests were already in merged PR #966.

Prepared release commit `5e2e92d8075db170a0701b6cd658aea12e57bd2f` on `codex/gpt6_release-0.4.3-candidate-20261015` and opened draft [PR #969](https://github.com/datagen24/victual/pull/969). It bumps all version copies, updates the README to identify published 0.4.2 as the deployment version, and adds the 0.4.3 candidate record. Local checks passed: version identity, Helm/K3s render, OpenAPI validation, 107 CI helper tests, staged Vale (0 new findings), and diff checks. No migration files differ from v0.4.2. A populated PostgreSQL upgrade rehearsal was not run because Podman is unavailable and the host PostgreSQL server is not running. The candidate record states this; candidate CI is pending.

No merge, tag, or publication was performed. The PR is draft pending CI and review. Tagging is a separate maintainer action after the final release-record commit has passed its checks.

## PR #969 initial CI snapshot

At candidate head `5e2e92d8075db170a0701b6cd658aea12e57bd2f`, GitHub reported prose, MCP, two change-classification jobs and GitGuardian passing. Lint, the full PostgreSQL suite, suite-floor, images, frontend-security, Nix `flake`, Psalm and Read the Docs were still pending. CodeRabbit skipped review because the PR is draft. This is a check snapshot, not candidate validation or release readiness.


## 0.4.3 preparation update at 2026-10-15

#890 was closed after confirming the merged #951 implementation and PostgreSQL boundary evidence; no duplicate implementation was needed. Candidate PR #969 is open as a draft at `5e2e92d8075db170a0701b6cd658aea12e57bd2f`. The release record remains documentation/version metadata only.

A populated `v0.4.2` database was initialized and seeded from source tag commit `b728d0d1a2203523155327b89a03716f915b58c4` in a unique audit database on PostgreSQL 16.15. Candidate migration ran twice at migration 312; normalized schema/data dumps were byte-identical and candidate reads preserved stock quantity 3 and chore frequency 24.5 hours. Evidence, exact hashes, and limitations are in `evidence/upgrade-043/REPORT.md`; normalized dumps and fixture scripts are adjacent. The database was dropped. This does not test restore, refusal/recovery, arbitrary production data, PostgreSQL 15, or a production Nix image rollout.

Latest GitHub refresh: PR #969 remains draft and open. Passed checks include lint, Psalm, CodeQL, prose, MCP, and workflow checks; suite, suite-floor, frontend-security, Nix flake, and images were still in progress. No tag or publication exists. Next: monitor candidate checks and review feedback; update the final evidence against the merge SHA after authorized merge. Do not tag or publish without explicit authorization.

## 0.4.3 candidate upgrade rehearsal completed

The actual `v0.4.2` tag (`b728d0d1a2203523155327b89a03716f915b58c4`) was used to initialize a uniquely named PostgreSQL 16.15 database at migration 312. The candidate `5e2e92d8075db170a0701b6cd658aea12e57bd2f` migrator ran twice and left normalized schema/data dumps byte-identical. Candidate reads returned stock quantity 3 and chore average execution frequency 24.5 hours. The audit database was dropped. Detailed evidence and known limits are in `evidence/upgrade-043/REPORT.md`; the focused fixture and normalized dump hashes are retained alongside it. This is not restore, refusal/recovery, PostgreSQL 15, arbitrary production data, or production image rollout evidence.

Updated `docs/releases/0.4.3.md` and added `docs/releases/0.4.3-upgrade-rehearsal.md` to PR #969. Vale reported zero new findings. The latest GitHub status before this documentation update showed lint, Psalm, CodeQL, prose, MCP, changes, and GitGuardian passing; suite, suite-floor, frontend-security, Nix flake, and images in progress. The PR is still draft. No tag or publication exists.


## 0.4.3 release PR merged

PR #969 merged at `2026-10-15T21:47:14Z` as `c2e9ece9592588305dca0b96e7aecb5c026d1de6`. The merge contains the versioned release record and populated v0.4.2 upgrade rehearsal evidence. On the merge SHA, prose, Psalm Security Scan, and push-on-master passed; tests and Nix workflows were still running at the latest refresh. No 0.4.3 tag or publication exists. Record final master workflow outcomes before deciding tag readiness.


## 0.4.3 post-merge CI green

GitHub Actions runs 38812492719 (`tests`) and 38812492524 (`nix`) both completed successfully on merge SHA `c2e9ece9592588305dca0b96e7aecb5c026d1de6`. The test workflow passed lint, MCP, images, frontend-security, suite and suite-floor. Prose run 38812492653, Psalm Security Scan run 38812492588, and push-on-master run 38812490879 also passed. The populated `v0.4.2` upgrade rehearsal and local Vale check passed.

The merged `docs/releases/0.4.3.md` still said final candidate checks were pending. A documentation-only correction is open as PR #970: https://github.com/datagen24/victual/pull/970. It records the merge SHA and green workflow links. PR #970 CI is pending. No v0.4.3 tag or production publication exists. The signed-tag process in `docs/releases/README.md` remains the next release operation after the final record correction; obtain explicit user authorization before creating or pushing the signed tag.


PR #970 CI completed

PR #970 passed applicable lint, prose, changes, CodeQL, Read the Docs and GitGuardian checks; the docs-only change skips full suite/images checks. The PR remains open and requires maintainer merge. No tag or publication exists.


## 0.4.3 signed tag and publication complete

The maintainer pushed signed tag `v0.4.3`. GitHub verified its SSH signature. Annotated tag object `02b584ab7a578c9dae799d50c837d3103f1585e1` targets `320f610e62c966b1b5dc4a1987bb728cf9966718`, the PR #970 merge. Release workflow 38815984146 succeeded on both linux/amd64 and linux/arm64: image builds, migration, read-only filesystem checks, page/API walks, GHCR image publication, Helm chart publication, and GitHub release creation all passed. Release published 2026-10-15T22:31:00Z: https://github.com/datagen24/victual/releases/tag/v0.4.3 . Six image digests and the Helm chart digest are in `release-data.json` and the GitHub Release body.

The tagged release record still said publication was pending, so a post-publication documentation correction is open as PR #971: https://github.com/datagen24/victual/pull/971 . It has been staged and pushed; wait for CI and maintainer merge. This does not alter the signed tag or published artifacts.


PR #971 checks completed

PR #971 passed applicable lint, prose, CodeQL, Read the Docs, changes, and GitGuardian checks. Full suite, image and Psalm jobs were skipped because it only changes release documentation. The PR remains open for maintainer merge. The signed v0.4.3 tag and release are already live.


## Current status refreshed 2026-10-16

Version `v0.4.4` is published. The signed tag targets master commit `631c6b27cf0d743b6068b5f503940ff9dc97e496`; release workflow [38898838522](https://github.com/datagen24/victual/actions/runs/38898838522) succeeded for linux/amd64 and linux/arm64 and published six images and the Helm chart. The release is [v0.4.4](https://github.com/datagen24/victual/releases/tag/v0.4.4).

PRs #971 (`cbfae8af`), #972 (`c1add04d`), #973 (`79945292`) and #974 (`631c6b27`) merged. PR #972 completed #926; PR #973 completed #898; PR #974 corrected the release record. Repository-wide inventory refreshed at `2026-10-16T18:51:27Z`: 43 open issues, zero open PRs, six milestones. The 43 issues are the entire open backlog. Current GitHub placement is 22 in 0.5.0, 6 in 0.6.0, and 15 unassigned. The proposed 0.5.0 scope has 23 direct issue rows plus the runtime work unit split from #944; that does not make every item a mandatory production gate. No GitHub writes were made during this dashboard refresh.

The scheduled Vale baseline-prune workflow [38894184659](https://github.com/datagen24/victual/actions/runs/38894184659) pruned 889 resolved entries locally and then failed to push because the repository rule requires changes through a pull request. This is separate automation follow-up and did not affect v0.4.4.

The 0.5.0 upgrade rehearsal must start from the actual preceding release, v0.4.4. The recorded populated rehearsal covered v0.4.2 to v0.4.3; v0.4.4 added no migration files, but no populated database rehearsal was run for that release. Production readiness still requires integrated candidate evidence, including upgrade and refusal/recovery behavior, backup/restore, supported PostgreSQL versions, deployment paths, client adoption and the integrity gates documented in the dashboard. Current release readiness is not established by merged code or v0.4.4 publication.

The next work item is a refreshed 0.5.0 readiness review and approved implementation batch. Keep the 43-issue backlog count separate from the finite release gates. Do not start implementation from this dashboard update alone.


## 0.5.0 execution authorized

The maintainer instructed the coordinator to drive through the 0.5.0 release. GitHub still has 43 open issues and no open PRs; master is 631c6b27. Implementation started in `/private/tmp/victual-050-numeric`, branch `codex/gpt6_0.5.0-numeric-boundaries-20261016`, from that SHA. The first unit validates submitted numeric strings before purification (#946); the recorded D4 governs refusal. No sub-agents are dispatched. Battery undo authorization remains a pending maintainer question; independent work continues. Final release gates remain binding.


## Interrupted Codex 0.5.0 work preserved (2026-10-17)

The Codex coordinator stopped and left uncommitted 0.5.0 work in five `/private/tmp/victual-050-*` worktrees. `/private/tmp` does not survive a reboot. Master had moved to `d9b3a9b3` because PR #975 (partial-undo fixture) merged at 2026-10-17T00:53:11Z. On the maintainer's instruction, Claude saved each dirty worktree as an unsigned WIP commit. The commits used `--no-verify`, so they are unreviewed and untested, and they were pushed. No PRs were opened.

| Branch | WIP commit | Scope |
|---|---|---|
| `codex/gpt6_0.5.0-numeric-boundaries-20261016` | `c8912c48` | #946 numeric-string validation (5 files) |
| `codex/gpt6_0.5.0-booking-idempotency-20261016` | `104846a2` | stock booking idempotency; claims migration `0313.pgsql.sql` (17 files) |
| `codex/gpt6_0.5.0-userfile-writes-20261016` | `ca1910b6` | userfile mutation owner policy (6 files) |
| `codex/gpt6_0.5.0-battery-read-boundary-20261016` | `6ed2411a` | battery read permission boundary (3 files); D5a undo rule still pending |
| `codex/gpt6_0.5.0-price-semantics-20261016` | none | no changes; branch equals `631c6b27` |

All of these branches start from `631c6b27`. Rebase them onto current master before any PR. Next: Claude continues with #946 from the numeric-boundaries WIP.

## #946 PR opened (2026-10-17)

The numeric-boundaries WIP was rebased onto `d9b3a9b3` and completed. One fix went in on top: valid numeric fields now skip the purifier, so `permission_id` `"\v2"`/`"\f2"` are refused like `" 2"`. All six rewrite inputs from the issue comment are now pinned across the 19 characterised fields, and the OpenAPI text was corrected. The result was squashed into signed commit `2645e76b` and opened as [PR #976](https://github.com/datagen24/victual/pull/976). The full local `run-tests.sh all` passed (PHP 8.5.10, PostgreSQL 16, 34 phases). Vale was not run locally. CI is pending, and Auto-fix is on for the PR.

Merging and closing #946 are maintainer actions. Next lane: #947/#950 numeric boundaries, then the other preserved WIP branches (idempotency claims migration 0313).

## #947/#950/#892 closed; #920 PR opened (2026-10-17)

#976 merged as `1cfb7285`, which closed #946. Every row of the #947 and #950 issue tables was checked against the master expectations in `NumericStringAcceptanceTest`. Both issues are closed, and so is the #892 umbrella: its O1 option A is complete and all its residuals are closed. The cases #950 had only probed (`-1e309`, `-2147483648`, by-barcode consume `recipe_id`) are pinned in [PR #977](https://github.com/datagen24/victual/pull/977), signed `f3e04be1`; wirecontract passed locally.

The userfile-writes WIP was rebased onto `1cfb7285` and narrowed. Its global `SHARE ROW EXCLUSIVE` locks around every API write were replaced by an owner-set comparison before and after the write, in the write's own transaction. That comparison covers only the writes that can change a file's owners, under one advisory lock. [PR #978](https://github.com/datagen24/victual/pull/978), signed `ba662e93`, implements D3 for #920. It adds the derivative-name refusals and ADR-0048 item 9; the ADR stays Proposed. Its 20 regression cases fail on master's code.

The full local `run-tests.sh all` passed (34 phases), and contract/wirecontract/openapisecurity were rerun after the last OpenAPI edit. #905 stays open until #978 merges and the end-to-end evidence is in. Gate R1's candidate-specific evidence is still required at RC.

Open 0.5.0 issues after these merge: #952, #944, #937, #933, #930, #928, #916, #908, #905, #891, #775, #774, #742, #703, #702, #701.

## #977 and #978 merged (2026-10-17)

#977 (pinning #950's probe-only cases) and #978 (#920 owner-based write authorization, D3) both merged. CodeRabbit reviewed #978 and found one ADR-0048 placement issue, fixed in the unsigned docs commit `d94fbf94`; the maintainer approved skipping the signature because they were away. #905 can be closed by the maintainer. Gate R1 still needs evidence specific to the 0.5.0 candidate.

## #905 closed; #916 in progress (2026-10-17)

#905 was closed with a comment pointing to #919 (the read rule) and #978 (the write rule). Gate R1 still needs evidence from the 0.5.0 candidate.

#916 (gate R2) takes migration **0313**: a `<table>_<column>_finite` CHECK on the 35 double precision and numeric columns that had no finite-number check, behind a read-only preflight that reports every offending column at once and refuses without rewriting anything. That number was also claimed in the unmerged `codex/gpt6_0.5.0-booking-idempotency-20261016` WIP. Under the RESERVATIONS rule, the branch that lands first gets the lowest free number, so that branch must renumber its `0313.pgsql.sql` and its reservation row to **0314** before it can rebase.

## #979 merged; #916 closed (2026-10-18)

#979 merged. It adds migration 0313's finite-number constraints and covers gate R2's implementation; the evidence the release candidate needs is still to come. CodeRabbit approved it, and its only finding was docstring coverage, fixed in `96fc7eb9`. Both commits are unsigned; the maintainer approved that.

## #775 PR opened (2026-10-18)

The booking-idempotency WIP was rebased onto `4c89d26f`, and its migration was renumbered 0313 → **0314**, which settles the earlier note. It was finished, squashed and signed as `106e9951`, and opened as [PR #980](https://github.com/datagen24/victual/pull/980) for gate M3.

- **One real WIP bug:** the replay read bound `user_id` to the transaction id through a second LessQL `:1` placeholder, so every keyed booking answered 400.
- **Test corrections:** `undone` is the documented integer flag (#230); the redaction case uses a consume, because `STOCK_PRICES_VIEW` is a child of `STOCK_PURCHASE`; the race test uses the real advisory lock class; a newline key can't be sent as a header.
- **Added:** pgTAP 037, the id-format, CORS and `db-changed-time` tests, the migration header and data-model docs.
- **Verification:** the full local suite is green, apart from the deliberate snapshot change, which was regenerated and reverified.

ADR-0046 stays Proposed; accepting it is a separate PR. Before release, victual-kit must confirm it treats `transaction_id` as opaque, since ids are now 32 hex characters; that is tracked under #891 / M7.

## Small fixes and #980 review (2026-10-18)

Three small 0.5.0 fixes are open as signed PRs. Each has targeted local phases green; CI runs the full suite.

- [PR #981](https://github.com/datagen24/victual/pull/981) (#928): an outbox drain now goes past a batch of only unreadable rows. The regression test fails on master.
- [PR #982](https://github.com/datagen24/victual/pull/982) (#937): an MCP booking answered without a transaction row is `outcome_unknown` and keeps its status. MCP tests pass, 49/49.
- [PR #983](https://github.com/datagen24/victual/pull/983) (#944): a NUL byte in a label worker registration is now a 422 `LabelRefusal` naming its path, with nothing stored.

#980 review, commit `41555b2c`:
- A bounded `FOR UPDATE SKIP LOCKED` purge of expired keys now runs on every keyed booking; the thread is answered and resolved.
- Every function the diff touches has a docblock.
- CodeRabbit's out-of-scope finding on the transaction-id format was answered with a citation to ADR-0046 decision 7 and maintainer response 3; the change stays in the PR.

## #980 and #981 merged (2026-10-18)

#775 and #928 are closed. #982 (#937) and #983 (#944) are still open. 12 issues remain open in 0.5.0. The largest unbuilt gate is #774 (M2, price semantics under ADR-0045 option B). Three items wait on maintainer decisions: #908 (D5a-undo), #933 and #952. ADR-0046 acceptance is a separate bookkeeping PR.

## #774 implemented in two stacked PRs (2026-10-18)

Gate M2 (ADR-0045, which stays Proposed) is open as two signed PRs. #985's base is #984's branch, so #984 must merge first. #774 closes when #985 merges. Each PR's full local `run-tests.sh all` passed (35 phases).

- [PR #984](https://github.com/datagen24/victual/pull/984), part 1, migration **0315**. It adds `legacy_zero_price` on `stock` and `stock_log`, marks every pre-existing zero, and records it in `price_interpretation_reports` through `mark_legacy_zero_prices()`. The marker is copied at 19 `StockService` sites, the importer marks zeros after verification, `--legacy-zero-prices=known` overrides that, and `FieldPolicy::INTERNAL_FIELDS` keeps the marker off the wire. The contract snapshots are unchanged. Two problems were caught by tests:
  - Re-expanding `stock_next_use`'s `s.*` would have started sending `stock.import_epoch`; the contract snapshot caught it.
  - The importer's report row drew a sequence value before verification, which broke the import's rollback guarantee; `import-tests` caught it.
- [PR #985](https://github.com/datagen24/victual/pull/985), part 2, migration **0316**. It adds `value_incomplete` / `stock_value_incomplete`, the rewritten views behind accepted differences D1 to D4, `null` for marked raw prices, the P2 write paths, the UI markers, OpenAPI, and regenerated snapshots with unpriced and mixed fixture products. pgTAP 026's #629 case was updated for D3. The browser probe `price-semantics.js` was not run locally; CI is its first run.

Still open for M2: victual-kit must handle `null` meaning unknown and the new flags (ADR-0024, under #891 / M7). Not changed, as listed in #985: Influx still publishes `0.0` for a marked booking, `mealplan.js` still turns `null` into 0, the stock entries page shows the stored 0, and weighing has no test of its own.

- 2026-10-18 #985 frontend-security: the price-semantics probe failed with `null !== true` because DataTables detaches hidden-column cells (value and price columns are hidden by default), so `getElementById` found no icon. The probe now searches `DataTable().cells().nodes()`. Server render was already correct; blade changes reverted. Pushed d3e3e03b (signed); CI rerunning.

## Dashboard refreshed (2026-10-18)

`release-data.json` was refreshed from GitHub at 2026-10-18T17:53:15Z (snapshot in `evidence/refresh-2026-10-18`, with the previous data file and renderer saved alongside it), and every view was re-rendered. Master is `d4e35751`, and all 14 of its checks passed. There are 31 open issues: 10 in 0.5.0, 6 in 0.6.0 and 15 unassigned. There are 2 open PRs, #984 and #985. Twelve 0.5.0 issues have closed since 2026-10-16: #946, #947, #950, #892, #942, #920, #905, #916, #775, #928, #937 and #944.

Gates R1 and R2 are implemented on master, M3 is implemented with ADR-0046 acceptance pending, M2 is in review, and R3 is partly resolved (#930 is still open). `render.py`'s hard-coded "43 / 22 / 6 / 15" backlog sentences now read `assignment_distribution.note`. No GitHub writes were made.

- 2026-10-18 #984 CodeRabbit: the edit form sent a displayed legacy 0 back on every save, which cleared the marker. Fixed in signed commit 385da7b3: `stockentryform.js` omits an unchanged price. New probe `stockentry-price.js` (frontend-security; not run locally). Docstring coverage on touched functions went from 56% to 100%. Thread replied to and resolved. Standing rule saved as memory: docstrings ≥80% on touched files before the first push.

## Findings filed; milestone 0.5.1 created (2026-10-18)

At the maintainer's request, the findings from this run that had no issue were opened. None blocks 0.5.0, so all five went to the new [milestone 0.5.1](https://github.com/datagen24/victual/milestone/7) (between 0.5.0 and 0.6.0):

- [#987](https://github.com/datagen24/victual/issues/987): consume stores any integer `recipe_id`, including negative and nonexistent ids. This is the residual of #950.
- [#988](https://github.com/datagen24/victual/issues/988): `prose-baseline-prune` has failed daily since 2026-10-15, because it pushes to master and master requires a pull request.
- [#989](https://github.com/datagen24/victual/issues/989): the calendar 500 includes the user-supplied event title. This is #801's D4 follow-up.
- [#990](https://github.com/datagen24/victual/issues/990): legacy-zero prices still read as 0 on the stock entries page, the edit form, the meal plan cost and Influx; weighing is untested. These are the residuals of #774 listed in PR #985.
- [#991](https://github.com/datagen24/victual/issues/991): an upload under an existing unreferenced userfile name reveals that the name exists. ADR-0048 item 9 records this as not covered.

Not filed:
- The "4 contract deprecations also on master" note: the master CI logs don't confirm it.
- #978's "the guard is not optimized" note: it suggests a possible optimization; there is no defect.

The dashboard data now includes the five new issues and milestone 0.5.1, and every view was re-rendered.
- 2026-10-18: #990 moved to 0.5.0 at the maintainer's request and added to gate M2; 0.5.1 now holds #987, #988, #989, #991. Dashboard re-rendered.
- 2026-10-18: #984 merged as 5dda90e2. GitHub retargeted #985 to master and rebased it (484aa074), so its CI is rerunning from scratch. Signed df61f901 adds docblocks to the functions #985 touches; coverage by the local count went from 47% to 97% (38/39). The commit message says 100%, which is wrong: each added docblock pulls the function above it into the diff. Comments only, so no suites were rerun.

## #990 PR opened (2026-10-18)

[PR #996](https://github.com/datagen24/victual/pull/996), signed `cb692a92`, is based on master after #992–#994 (the ADR-0045, -0046 and -0048 acceptances, done by the maintainer). It changes these surfaces:
- **Stock entries page:** migration **0317** makes `uihelper_stock_entries` read a marked 0 as NULL, and the page and its row refresh show an unknown price as no price.
- **Edit form:** a marked 0 renders blank.
- **Meal plan:** a product with no known price shows a marker instead of a cost, and recipe, day and week costs carry incomplete markers. Recipes with no product are excluded.
- **Influx:** no `price_paid` point is written for a marked 0. This is a guard in practice.
- **Weighing:** gets its own test.

ADR-0045's closing line now cites #992. RESERVATIONS rows 0312–0316 now say they're in master.

The full local `run-tests.sh all` passed, on the tree before the rebase; master's changes since then are docs only. The browser probe extension is CI-only. Docstring coverage is 100% (19 of 19). The next item is #930 (gate R3).
- 2026-10-18 #996 frontend-security: the meal-plan part of price-semantics.js threw because mealplan.js's eventRender strips the fc-event class from each entry. Signed 0d13e89f finds the entry by data-meal-plan-entry instead. The stock entries assertions had already passed in CI.
- 2026-10-18 #996: the probe's meal-plan entry assertions now pass in CI. The day-cost marker assertion still fails. In SQL, the day's shadow recipe reads prices_incomplete = 1 with its product names (new StockPriceSemanticsTest case, passing locally), so the gap is on the page. Signed c2cae8eb makes the assertion print the page's day state; the next CI run will show which link is missing. No local browser is available because public/packages isn't installed and yarn is missing.
- 2026-10-18: #996 merged (a3e43100) before its tests workflow finished. On master, frontend-security then failed on the day-marker assertion. The diagnostic showed the data was right and `#day-summary-<day>` empty: mealplan.js nests an <h5> in an <h5>, which predates #990, so the parser leaves the summary text outside that element. [PR #997](https://github.com/datagen24/victual/pull/997), signed, makes the probe read the whole day header.
- 2026-10-18 #930 (R3): MergeProducts now takes `LOCK TABLE quantity_unit_conversions IN SHARE MODE` after its product locks, through a new dialect method (a no-op on SQLite). MergeProductsConversionLockTest (two connections) passes with the lock and fails without it. Without it, the merge ran past an uncommitted conflicting conversion and failed with a raw "QU conversion already exists" trigger error. A DML-only role was shown to take the SHARE lock on PG 16.15. Full suite running.

## Maintainer decisions D8–D10 (2026-10-18)

The maintainer posted these on GitHub; copies are in `evidence/decisions-2026-10-18/`.
- **D8, #908:** write-only battery clients are supported. Tracking a charge needs only BATTERIES_TRACK_CHARGE_CYCLE. Without BATTERIES_VIEW the response is success plus the new charge-cycle id; the full row needs BATTERIES_VIEW. Undo keeps its own permission.
- **D9, #933:** SHOPPINGLIST_VIEW allows barcode display and scanning in shopping-list workflows. Shortfall counts, markers and filters require STOCK_VIEW.
- **D10, #952:** a conversion write is refused when an evaluated chain factor or its inverse would be non-positive, non-finite or subnormal. The refusal gives a specific error and rolls the write back.

Queue after #930's PR: #908, #933, #952.
- 2026-10-18 #930: [PR #998](https://github.com/datagen24/victual/pull/998), signed 85e7baf7. Full local suite passed; the two new tests fail without the lock. Not covered: a concurrent qu_id_stock edit on the products row. #908: code, OpenAPI receipt schema, docs and tests written; affected phases running.
- 2026-10-18 #908: [PR #999](https://github.com/datagen24/victual/pull/999), signed. A charge tracked without BATTERIES_VIEW returns only {id}. The first schema, anyOf(entry, receipt), was reported as ambiguous by ContractTest; it now uses the single entry schema plus a description. rbac, wirecontract, contract, openapisecurity, openapidocument and householdpages passed. #998 and #999 will fail frontend-security until #997 merges (the inherited master failure).
- 2026-10-18: #997 merged; master merged into #998 (670a1f2a) and #999 (f8e4f3a8) with signed merge commits. #933 (D9) written: shortfall count, filter, row marker and the below-min add action need STOCK_VIEW; POST add-missing-products now also needs STOCK_VIEW; barcodes unchanged; ADR-0050 (Proposed); ShoppingListShortfallVisibilityTest. Affected phases running.
- 2026-10-18: #998 and #999 merged. #933 opened as draft [PR #1000](https://github.com/datagen24/victual/pull/1000), signed c94490b0, so the maintainer can review ADR-0050 (Proposed). Local phases are still running; stockpages will be rerun after the helper rename.
- 2026-10-18 #1000: every affected phase passed (stockpages 82 after the rename). The rename was already in c94490b0, so there is no extra commit. Still a draft pending the maintainer's ADR-0050 review.
- 2026-10-18 #952 (D10): migration 0318 written. The conversion triggers refuse (SQLSTATE VQ952) a written or resolved factor outside 2^-1022..2^1022, and a resolver overflow inside the rebuild becomes the same refusal; delete is checked too. ConversionChainRange maps VQ952 to a 400. #890's PG boundary test was updated to the new rule. New ConversionChainRangeTest and pgTAP 040. pgtap and stockcoverage running.
- 2026-10-18 #1000 lint: ADR-0050 was missing from the mkdocs.yml nav (strict build). Added in a signed commit.
- 2026-10-18 #952: the rerun found and fixed three problems. Global-only chains weren't evaluated, so an explicit global closure check was added. The lookup path got the generic message, so the mapping moved into WithoutDriverText. Four older fixtures planting negative factors were blocked, so the range rule is permissive while planting and then restored. pgtap and stockcoverage (453) pass. Full suite running.
- 2026-10-18: #1000 merged by the maintainer, who agreed with ADR-0050. The acceptance change (status line and index row only) is prepared on claude/0.5.0-accept-adr-0050. #952's commit and the acceptance commit are both waiting on 1Password for signing; #952's full suite passed.
- 2026-10-18: [PR #1001](https://github.com/datagen24/victual/pull/1001) (#952) and [PR #1002](https://github.com/datagen24/victual/pull/1002) (ADR-0050 acceptance) opened, both unsigned with the maintainer's approval (1Password locked). Open 0.5.0 issues are now #952 (in review), #891, #742, #703, #702 and #701.
- 2026-10-18: client-adoption list for #891 written (evidence/client-adoption-0.5.0.md). Plan 22 M1 reconciliation written (evidence/plan22-m1-reconciliation.md). It recommends closing #701 (all 7 criteria met; race fixed in #755) and #742, with the conversion residual recorded under ADR-0043. #702 closes after victual-kit records a resolving client commit (still 743ab1f).

## 0.5.0 published (2026-10-19)

- Signed tag `v0.5.0` (object `5a3960de`) targets `555475aeec4ce2840671ab5623460ebf9f8440b3`, the merge of [PR #1004](https://github.com/datagen24/victual/pull/1004). GitHub reports the tag signature valid. [Release workflow 39250766266](https://github.com/datagen24/victual/actions/runs/39250766266) succeeded and published six images and the Helm chart for amd64 and arm64; the release was published at 2026-10-19T12:28:41Z. Digests are in `docs/releases/0.5.0.md`.
- Before the tag: the tests workflow on release-PR head `2b350b1d` passed (suite PG16 with coverage, suite-floor PG15, frontend-security, images, mcp, lint), as did Psalm, prose, CodeQL and nix. The populated v0.4.4 → 0.5.0 upgrade rehearsal passed on PostgreSQL 16.15 (312 → 318, rerun byte-identical; a NaN database refused by 0313 and left at 312).
- Merged on 2026-10-18/19 and shipped: #1000 (#933), #1001 (#952), #1002 (ADR-0050), #1003 (plan 22 client commit record). After the tag, #1005 and #1006 landed plan 22 and closed its ledger (docs only). Master is `431b1a97`.
- Issues #701, #702, #703 and #742 closed on 2026-10-19; #891 closed **not planned** (victual-kit adopts the contract after the tag, tracked there). Milestone 0.5.0: 48 closed, 0 open.
- Not evidenced at the tag, so 0.5.0 claims neither: R5 (backup/restore drill) and R6 (cluster label delivery and shutdown; #93/#133 open and unassigned, although D2 had proposed them for 0.5.0). The rehearsal ran from the dev shell, not the production images.
- Dashboard refreshed from GitHub at 2026-10-19T14:43:24Z (`evidence/refresh-2026-10-19/`; prior data kept as `release-data-before.json`): 26 open issues (4 in 0.5.1, 6 in 0.6.0, 16 unassigned including #1007), 0 open PRs. Gates recorded at their v0.5.0 status. No GitHub writes. Next lane: 0.5.1.
