# Bug bash 2026-10-13 — reconciled ledger

Snapshot: 2026-10-13T17:56:48.953446+00:00. GitHub observations are distinct from prior-session validation reports.
No tests or fix work were performed during this reconciliation. Refresh mutable state at restart.
Read [HANDOFF.md](HANDOFF.md) for decisions and restart order.

## Open PR queue

| PR | GitHub head | State / CI at observation | Next action |
|---|---|---|---|
| [#919](https://github.com/datagen24/victual/pull/919) | `45aa7a53ed71813962e0044eb2f5dccde0893651` | Draft; UNSTABLE; suite: IN_PROGRESS; suite-floor: IN_PROGRESS; frontend-security: IN_PROGRESS; images: IN_PROGRESS | Recover round-4 verdict at 45aa7a53; wait for required CI; resolve view-owner decision. Partial #905, closes #941 only. |
| [#921](https://github.com/datagen24/victual/pull/921) | `b755337b8d477a27fef4796538c12c44612e9715` | Draft; CLEAN; No failing/pending checks in returned rollup | Recover dirty revision 4; finish decisions already answered; run affected phases and independent Opus revalidation at pushed head. |
| [#934](https://github.com/datagen24/victual/pull/934) | `afadc1a922b3345e397561dbe8613a582d8dac82` | Non-draft; DIRTY; No failing/pending checks in returned rollup | Resolve the existing changelog merge in its own worktree; push; review delta and rerun relevant checks. Do not restart branch. |
| [#940](https://github.com/datagen24/victual/pull/940) | `76c863ed00014998d827420e29a03acce7db465e` | Non-draft; CLEAN; No failing/pending checks in returned rollup | Recheck head, CI, body and closing references before maintainer review. Migration 0312 reserved by #924. |
| [#943](https://github.com/datagen24/victual/pull/943) | `5c19616f67042a82bf40c9f2f7f6f536eab2a8ab` | Draft; UNSTABLE; suite: IN_PROGRESS | Recover v1 verdict plus coordinator delta review; finish CI at 5c19616f before readiness. |
| [#945](https://github.com/datagen24/victual/pull/945) | `efa4da692a3ab67495942046c98716e8d831245c` | Draft; DIRTY; No failing/pending checks in returned rollup | Recover v1 verdict; resolve changelog conflict; validate resulting head. Partial #892; #946/#947 remain follow-ups. |

## Validation evidence carried forward

These are prior-coordinator reports, not fresh validation. Recover reports before relying on them.

| PR | Last recorded independent validation | Later delta / missing evidence |
|---|---|---|
| #919 | v3 found a userpictures disclosure; userfiles derivative design passed | v4 45aa7a53 fixes that disclosure; round-4 report not recovered |
| #921 | Round 3 validated f40d5f03 | b755337b master merge was coordinator-reviewed; latest policy revision is still uncommitted and unvalidated |
| #934 | v1 validated 7bd8d9be | afadc1a9 example/handoff delta coordinator-reviewed; unresolved local merge needs new evidence |
| #940 | v2 validated 76c863ed, including lock-timeout mutation | Head matched; required CI must be refreshed before review |
| #943 | v1 validated 0e242a29 | 5c19616f test/master delta coordinator-reviewed; suite pending at observation |
| #945 | No final v1 verdict recovered | Do not infer validation from successful phase logs |

## Local work to recover

Paths and status are read-only observations. Do not reset, clean, archive or overwrite these trees.
Check live process ownership before resuming. Remote publication of commits without PRs was not verified.

### claude/sonnet5_issue892-numeric-strings-w4

- Worktree: `/Users/speterson/src/grocy/.claude/worktrees/agent-a37866d43a24bbcc5`
- HEAD: `efa4da692a3ab67495942046c98716e8d831245c`
- Recovery: Clean at observation. Pending verdict or PR integration remains as recorded above.
### claude/sonnet5_issue922-track-count-s9

- Worktree: `/Users/speterson/src/grocy/.claude/worktrees/agent-a5da0bfe51632f60b`
- HEAD: `afadc1a922b3345e397561dbe8613a582d8dac82`
- Recovery: Unresolved master merge: `UU changelog/82_UNRELEASED_xxxx-xx-xx.md`; many staged incoming files. Preserve and finish the existing merge; do not mistake incoming master changes for new feature scope.
### claude/sonnet5_issue901-merge-conversion-conflict-g7

- Worktree: `/Users/speterson/src/grocy/.claude/worktrees/agent-ab0739714327b2554`
- HEAD: `b755337b8d477a27fef4796538c12c44612e9715`
- Recovery: Uncommitted changes: `services/StockService.php`, `tests/Pgsql/MergeProductsConversionConflictTest.php`. These are the latest decision revision; pushed PR still has the older head.
### claude/sonnet5_issue904-subprocess-migration-x5

- Worktree: `/Users/speterson/src/grocy/.claude/worktrees/agent-aca48984e166c66ba`
- HEAD: `31b3439cdb3faefbad0192a5eeac9c6fff8b8df1`
- Recovery: Clean local commit; no PR found. Last commit describes 28 read sites plus readLine/stdin/abort. Verify remaining-site inventory and static guard; do not claim all roughly 80 files were migrated.
### claude/sonnet5_issue890-lookup-factor-normal-m4

- Worktree: `/Users/speterson/src/grocy/.claude/worktrees/agent-ad5bc1c9090d3749c`
- HEAD: `b8ecaa5bd3577302a93308f848483d715f1427f8`
- Recovery: Clean local commit; no PR found. Last recorded stockcoverage run at 17:47:40Z exited 1. Recover its output and fix/verify before claiming green. Required arithmetic brief review was not recorded.
### claude/sonnet5_issue905-userfiles-owner-read-f6

- Worktree: `/Users/speterson/src/grocy/.claude/worktrees/agent-ae79539bc4ebc4a42`
- HEAD: `45aa7a53ed71813962e0044eb2f5dccde0893651`
- Recovery: Clean at observation. Pending verdict or PR integration remains as recorded above.

## Remaining original scope

| Issue | Dependency / next action |
|---|---|
| #898 | #925 merged. Refresh briefs/898 against current master, then implement conditional data-change signalling. |
| #893 | #938 merged. Use reader-5 inputs; derive coverage additions from current snapshots and schemas. |
| #891 | Finish #934 integration, then publish the server/client handoff with pinned revisions and acceptance tests. Keep client adoption open. |
| #801 | Apply O3 decisions last among shared OpenAPI edits: explicit production 500 ApiError; shared text/plain 503 per operation; central routing-error description; separate calendar-title disclosure assessment. |
| #900 | #932 merged Proposed ADR-0049, not acceptance. Resolve part-2 questions and prerequisites before separate acceptance. |
| #905 | #919 is partial; #920 remains a security dependency. ADR-0048 is Proposed, not accepted. |
| #904 | Part 1 merged as #907; recover part 2 and validate interactive/streaming callers. |
| #890 | Recover local commit and failed run; decision B′ already received. |
| #901 / #929 | Finish #921; latest maintainer cells already received. Track #930 separately; do not imply concurrency is solved by sequential checks. |
| #892 | #945 is partial; preserve #946/#947 as unresolved findings and do not close the parent from documentation alone. |

## Confirmed merged from this round

[#907](https://github.com/datagen24/victual/pull/907), [#910](https://github.com/datagen24/victual/pull/910), [#911](https://github.com/datagen24/victual/pull/911), [#914](https://github.com/datagen24/victual/pull/914), [#915](https://github.com/datagen24/victual/pull/915), [#918](https://github.com/datagen24/victual/pull/918), [#924](https://github.com/datagen24/victual/pull/924), [#925](https://github.com/datagen24/victual/pull/925), [#927](https://github.com/datagen24/victual/pull/927), [#931](https://github.com/datagen24/victual/pull/931), [#932](https://github.com/datagen24/victual/pull/932), [#935](https://github.com/datagen24/victual/pull/935), [#938](https://github.com/datagen24/victual/pull/938), [#939](https://github.com/datagen24/victual/pull/939).

#897 was recorded closed as not planned under decision B=a; battery residuals remain in #908/#909.
Merging Proposed ADRs #931/#932 does not accept ADR-0048/ADR-0049.

## Follow-up inventory — triage, not automatic scope expansion

- Security/authorization: #920 (detach/delete bypass), #941 (userpictures; intended fix in #919),
  #946 (numeric purification including permission_id), #908/#909 (battery residuals), #933 (shopping-list stock disclosure decision).
- Integrity/concurrency/recovery: #916 (NaN/Infinity strings), #917 (import precision), #926 (artifact reuse/collection race),
  #928 (unreadable outbox batch stalls), #929 (own-unit rescaling; in #921), #930 (conversion-check race), #947 (shopping-list amounts).
- Contracts/docs/testing: #912 (JSON_THROW_ON_ERROR decision), #913 (permission manual), #936 (chore frequency type),
  #937 (2xx write without transaction), #942 (intermittent fixture), #944 (worker registration NUL handling).
- #922 and #923 were decision follow-ups and are already assigned to #934 and #940.

Do not declare the disclosure closed while #920 remains unresolved. Do not claim a whole API
compliance result from partial documentation fixes or a passing structural validator.

## Operational recovery

Runner: `suite.sh`; log: `suite-runs.log`; existing r487 lock; default phase timeout 1800 seconds.
The observed log ended with a 17:56:33Z start in the #921 worktree without a matching completion
in the inspected tail. Check the current log and process state before scheduling; do not infer a hang or kill it from this note.
The prior log also recorded #890 stockcoverage exit 1; it must not disappear behind a summary of green runs.

Use actual runtime concurrency limits. Keep unique scratch files and per-phase result attribution.
Use `changelog/82_UNRELEASED_xxxx-xx-xx.md`; new docs pages need strict-site navigation entries.
The runner's previous database-recovery incident remains in the archived log; do not recreate
containers as part of handoff recovery without confirming current need and authorization.

## Historical evidence

The unchanged [original ledger](LEDGER.before-handoff-tuneup.md) contains the full chronological
validation and waiting-time log and original brief paths. Resolved “PENDING” entries there are
not new questions. DECISIONS.md already records #922/#923 and the four #921 cells.
The missing F5 clarification and genuine view-owner question are summarized in HANDOFF.md.

Append future observations with a timestamp, head SHA, evidence source and next action.
Only mark a PR ready when its current revision, intended closing references, validation and CI agree.

## Restart log

- 2026-10-13T19:12Z coordinator resumed. No suite lock, no suite/run-tests process. #921 dirty-tree run at 17:56Z: stockcoverage exit=1 (output lost), other 6 phases exit 0.
  Resumed existing agents in their own worktrees (no replacements): #934 merge (a5da0bfe), #921 revision (ab073971), #919 round-4 validator (acd8b88d),
  #945 validator (a56ca66c), #890 fixer (ad5bc1c9). Queued: #904 part 2 recovery (aca48984, local commit 31b3439c), then #898, #893, #891, #801;
  view-owner question for #919 to present after round-4 verdict. #943 and #940: refresh CI/body before readiness.
- 2026-10-13T19:40Z: #934 merged (e1a676ca merge commit UNSIGNED under S1, disclosed in agent report) and #940 merged by maintainer.
  #943 ready (body updated with follow-up evidence) -> new changelog conflict after #934/#940; fixer merging master.
  #945 v1 CHANGES REQUIRED (price null/"" branches missing at 4 sites); fixer revising + master merge; will become Fixes #892 (defects #946/#947/#950 split out).
  #946 corrected on issue (USERS_EDIT only, not ADMIN; wider rewrite set; not escalation).
  #949 (#890) opened by fixer; coordinator set Part of #890 (storage precision #951 breaks boundary); Opus validator running.
  Filed #950 (recipe_id silently dropped; remove-product negative id), #951 (PDO precision=14 on app write path).
  Maintainer decision: #919 view owners resolve via view key (posted on #905). Waiting on #919 round-4 verdict before sending it to the fixer.
- 2026-10-13T19:45:55Z: #921 rev 4 pushed efa4ecd1 (UNSIGNED, S1; maintainer cells + kept-product rows; 6 new tests fail on b755337b; 7 phases green incl. stockcoverage 391/4776 at 19:37:06Z). Earlier 17:59Z stockcoverage exit=1 explained: two new tests wrote a stale cache row before setup conversion. Round-4 Opus validation dispatched.
- 2026-10-13T20:09:45Z: #921 round-4 VALIDATED at efa4ecd1 (6 REG, 2 GUARD; kept-product probes refuse; 17-table snapshots). Coordinator applied narrower reading of 'unit relationship unchanged' (child ∅/∅ also requires same stock unit) -> small follow-up commit; body footer order fix. Report this interpretation to maintainer.
- 2026-10-13T20:22:45Z: #945 VALIDATED v2 at 1440c505 (Fixes #892; #946/#947/#950 split out); CI 18/19 (1 pending). #943 CI 19 pass at 4e8f984d (ready, non-draft; unsigned merge disclosed). #898 fixer dispatched.
- 2026-10-13T20:25:03Z: #919 round-4 VALIDATED at 45aa7a53 (userpictures probes refused; own avatar served; chains unchanged; productpictures master behaviour; 3 tests REGRESSION). Revision 5 (view owners resolve via view key, maintainer decision) sent to fixer.
- 2026-10-13T20:26:16Z: #949 VALIDATED v1 at b83498cb (4 REG refusal rows; PG inverse matches PHP at 7 boundaries). Residual #951 measured (float-typed factors only; 99+80 value windows) and posted. Filed #952 (resolved-chain overflow). Body touch-ups sent to fixer (no code change).
- 2026-10-13T20:26:42Z: #949 READY (validated at b83498cb; CI 19 pass; body updated; Part of #890, depends on #951). #945 suite still pending.

## STOP — 2026-10-13 ~20:45Z (maintainer: "lets stop here and build the retrospective")
- #943, #949 MERGED. Total merged this round: 18.
- #921 head 0863a0cd (signed): child ∅/∅ same-stock-unit rule + body footer fix; stockcoverage exit=0 (392). NOT VALIDATED.
- #945 1440c505: CI 11 pass, now CONFLICTING with master (likely changelog). Still draft.
- #919 45aa7a53: revision 5 (view-key owners) stopped mid-edit; uncommitted UserfileOwnerReadTest.php in agent-ae79539bc4ebc4a42.
- #904b: local-only commits 31b3439c + 72fdffef in agent-aca48984e166c66ba; not pushed; phase counts incomplete.
- #898: uncommitted RenderClaimChangeSignalTest.php + phpunit.xml in agent-a0c3aa729ea2134df.
- #893: stopped at start, no changes. #891, #801 not started.
- Retrospective: RETROSPECTIVE.md (copy of claudedocs/bugbash-20261013-retrospective.md).

## Continuation — three draft PRs (#919, #921, #945), started 2026-10-13T20:42Z
- Recovered: prior validator reports extracted from coordinator transcript 692ceefe subagents (#921 R4 a5dc4bf3, #945 v1/v2 a56ca66c, #919 R1–R4 acd8b88d). No live suite lock/process at start; last log entry (aca48984 helperunits…, #904b) was interrupted, not ours.
- #921 0863a0cd: Opus round-5 VALIDATED WITH NON-BLOCKING OBSERVATIONS (new test REGRESSION: revert → 392 tests, 1 failure; 7 phases green: stockcoverage 392/4786, stockconcurrency 23/142, consumption 374/9879, stockmaintenance 74/630, recipeoperations 35/100, rbac 114/1292, contract 28). Findings: #929 test lacks costs/shopping-list assertions; Summary over-claims vs #930; body stale; "unit relationship" reading needs maintainer confirmation. Fixer r6 dispatched (tests + body only).
- #919 45aa7a53: rev 5 (view owners via view key, maintainer decision) fixer dispatched; label_* all ADMIN in EntityReadPolicy, so ADMIN fallback == own policy (no new question).
- #945: merged master locally (ffa4a1d6, signed; changelog only); reworded permission_id VT/FF text as filter side effect (378b9138, signed); not yet pushed; body → Part of #892 drafted (scratch/coord-r2/pr-945-r2.md). Phases running.
- 2026-10-13T21:2xZ #945 v3 at 378b9138: CHANGES REQUIRED (Opus; report scratch/validator-945/*v3*). Phases at head: wirecontract exit=0 175/6363/8 skipped; contract exit=0 29; openapidocument exit=0 15; openapisecurity exit=0 11. Revert: 12 failures in testDocumentedPatternMatchesRuntime (REGRESSION); testFieldAcceptance GUARD. Served spec /api/openapi/specification: field schemas identical to file. Blocking: (1) recipe_id description wrong for overflow (JSON 99999999999 → 400; JSON 1e309/400 digits → 200 dropped) and missing #950; (2) remove-product product_id copied from add-product (negative/"-0" → 204 no-op, #950). #950 itself is partly wrong: ASCII " 2" is stored as 2; the dropped value is NBSP " 2" plus JSON 1e309/400 digits (not commented on the issue — maintainer to correct or authorize).
- DESIGN REVIEW (second substantive failure on #945, after v1): both v1 and v3 defects are hand-written descriptions diverging from the test's own EXPECTED rows; the pattern test checks only the oneOf branch. Remedy: a full description-vs-EXPECTED sweep of every documented field (not just the two findings), and a 99999999999 row for recipe_id. Queued for the next free fixer slot.
- 2026-10-13T21:4xZ #921 r6 pushed c59c5023 (signed): new #929 test (costs/calories/missing_amount/amount_on_shopping_list unchanged across merge); before-fix vs 405d6f13 StockService: "costs ... before 30, after 3" (REGRESSION for costs; others GUARD as loop stops). stockcoverage exit=0 393/4793, recipeoperations exit=0 35/100. Body: point-in-time qualifier + #930 open, cells quoted, cell-2 interpretation marked unconfirmed. Opus delta review dispatched. #945 r4 sweep fixer dispatched.
- 2026-10-13T22:0xZ #921 c59c5023 Opus delta review VALIDATED WITH NON-BLOCKING OBSERVATIONS (test-only delta; costs/calories/missing_amount REGRESSION vs 405d6f13, amount_on_shopping_list GUARD; stockcoverage exit=0 393/4793, recipeoperations exit=0 35/100). Coordinator corrected body (numbers +240/-10, 36 tests, round attribution, RecipesService wording, review note). Awaiting suite/suite-floor/images at c59c5023.
- #919 rev 5 pushed 6f9b2966 (0e9f9db9 + master merge, both signed): 12 views via id key; label_*/print_* all ADMIN (pinned by test); 3 REGRESSION tests (storagefiles 128 tests, 5 failures on unfixed policy); nine phases exit=0 on merged head (storagefiles 128/1393, rbac 114/1293, householdpages 90/697, stockpages 76/706, labelapi 174/3454, contract 29, openapidocument 15/36, openapisecurity 11/86, wirecontract 135/4085). Note: stock_current_locations/products_last_purchased have constant `1 AS id`. Proposed ADR-0048 open question 3 now stale (not edited). Opus R5 validation dispatched.
- 2026-10-13T21:46:11Z #921 READY at c59c50237740 (all 19 checks pass, suite line coverage 96.54%; closes #901,#929). Open for maintainer: cell-2 'unit relationship' reading (same stock unit id vs same effective factor); #930 race open (body says point-in-time).
- 2026-10-13T21:46:16Z #945 r4 pushed 08f17796 (signed): full sweep 17 fields/22 locations (scratch/fixer-892/sweep-r4.md), 3 divergences fixed (recipe_id both routes, remove product_id, add product_id); +30 test rows (overflow 2147483648/99999999999, null price). Phases: wirecontract exit=0 175/6393/8 skipped; contract exit=0 29; openapidocument exit=0 15; openapisecurity exit=0 11. closingIssuesReferences []. v4 Opus validation queued (validator slot busy with #919).
- 2026-10-13T21:49:41Z #921 MERGED by maintainer.
- 2026-10-13T21:52:57Z #919 R5 Opus VALIDATED WITH NON-BLOCKING OBSERVATIONS at 6f9b2966 (revert: 128 tests/5 failures, 3 REGRESSION; nine phases exit 0; all preserved decisions re-probed). Seven views have degenerate ids (1 AS id ×6, ±1, colliding barcode/product ids) — body corrected, maintainer note added. Gone-row test uses a never-existing id (limitation stated in body). Title now 'part of #905; #941'. ADR-0048 OQ3 stale (not edited). Waiting CI run 38472443550. #945 v4 Opus validation dispatched at 08f17796.
- 2026-10-13T22:03:34Z #919 READY at 6f9b2966 (19 checks pass, suite lines 96.54%; closes #941 only; partial #905, #920 open).
- 2026-10-13T22:08:37Z #919 MERGED by maintainer.
- 2026-10-13T22:10:11Z #945 v4 Opus VALIDATED WITH NON-BLOCKING OBSERVATIONS at 08f17796 (independent 914-cell re-sweep, no divergence; revert 175/5958/12 failures; phases exit 0). Title fixed (stock and user-permission fields); body validation note + known limits; candidate follow-up: stock_label_type enum labels contradict observed behaviour (not filed). Waiting suite run 38472983363.
- 2026-10-13T22:11:45Z #945 READY at 08f17796 (19 checks pass, suite lines 96.53%; Part of #892, no closing refs). Filed #953 (OpenAPI stock_label_type labels off by one: doc 1/2/3, runtime 0/1/2) at maintainer's request.

### Final state of the three-PR continuation (2026-10-13 ~22:20Z)
| PR | Final head | State | Validation | CI | Remaining |
|---|---|---|---|---|---|
| #921 | c59c5023 | MERGED 21:49:28Z (9bc4a4c0) | Opus r5 at 0863a0cd + r6 delta at c59c5023, both VALIDATED (non-blocking) | 19 pass, lines 96.54% | Maintainer: cell-2 reading (same stock unit id vs same effective factor). #930 race open (body says point-in-time). |
| #919 | 6f9b2966 | MERGED 22:07:49Z (a7d9eb2e); closed #941 | Opus R5 VALIDATED (non-blocking) | 19 pass, lines 96.54% | #905 open, #920 unresolved security dependency. 7 views have degenerate ids (in body). ADR-0048 OQ3 stale. |
| #945 | 08f17796 | READY (non-draft), not merged; Part of #892 | Opus v4 VALIDATED (non-blocking) after v1/v3 CHANGES REQUIRED + design review | 19 pass, lines 96.53% | #946/#947/#950 keep #892 open; #950 text partly wrong (ASCII " 2" is stored); no wording-vs-observation test. Filed #953. |
- 2026-10-13T22:15:14Z Maintainer answers recorded in DECISIONS.md. #950 body corrected (coordinator; ordinary-space accepted, NBSP/JSON-overflow reproductions, shopping-list finding kept). Dispatched: #901 follow-up fixer (branch claude/sonnet5_issue901-merge-unit-equivalence-e1) and ADR-0048 docs fixer (claude/sonnet5_adr0048-view-owner-decision-d1), both in fresh worktrees.
- 2026-10-13T22:19:10Z #945 MERGED by maintainer; #892 stays open (Part of).
- 2026-10-13T22:20:29Z #954 (ADR-0048) Opus: CHANGES REQUIRED at 34396999 — label_printer_status IS looked up (text said none); 'draft PR 919' stale (merged). Quote verbatim/date correct; no remaining admin-only view text anywhere in docs/. Sent back to the same docs fixer. Candidate follow-up: UserfileOwnerPolicy.php:30-31 docblock says label/print not looked up (wrong for label_printer_status) — not filed.
- 2026-10-13T22:22:26Z #954 READY at 8ee1e443 (docs-only; lint/prose/RTD pass, suites skipped by path filter; ADR stays Proposed; Refs #905).
- 2026-10-13T22:37:45Z #955 draft at 37a59323 (signed; follow-up to #901/#921): different stock units need a resolved conversion row with factor 1 (fallback 1 never qualifies). Removed-product-specific 1:1 counted as resolved path (open question). Fixer: before-fix stockcoverage 419/2 failures; after 7 phases exit 0 (stockcoverage 419/4924). Fixer claims criterion (c) violation not constructible; reports stock_current (0298) counts no-conversion child as 0. Opus validation dispatched.
- 2026-10-13T22:58:44Z #955 Opus VALIDATED WITH NON-BLOCKING OBSERVATIONS at 37a59323 (2 REGRESSION; 7 phases exit 0; no fallback-1 path; criterion (c) holds by construction; stock_current 0298 counts no-conversion child as 0 — confirmed). Coordinator treats obs. 2 (probes C/D: kept product's own factor 2 contradicts equivalence in merged state, now allowed) as required under the recorded 'evaluate against the proposed merged state' rule; sent back to fixer with stale-comment fixes. Candidate pre-existing defect (reported, not filed): MergeProducts' $factor ignores the kept product's own conversion on the same unit pair (stock moved at 1 when kept says 2).
- 2026-10-13T23:19:43Z #955 rev 2 pushed 4adcb1b3 (UNSIGNED, S1 disclosed): kept product's merged-state removed→kept factor must exist and equal 1; probes C/D REGRESSION vs 37a59323 (423 tests, 2 failures); 7 phases exit 0 (stockcoverage 423/4964). Opus delta validation dispatched.
- 2026-10-13T23:40:40Z #955 Opus r2 VALIDATED (non-blocking) at 4adcb1b3; body corrected by coordinator; READY (19 checks pass, suite lines 96.55%; follow-up to #901/#921, no closing refs). Open for maintainer: product-specific 1:1 as equivalence; candidate pre-existing $factor defect (not filed); stale comments ~:5090,:5182 noted in body.
- 2026-10-13T23:42:51Z Maintainer answers recorded. #955 returned to draft (product-specific rule change + scaling investigation may block).
- 2026-10-13T23:43:59Z #956 draft (db7b907d, signed): comment-only correction in UserfileOwnerPolicy.php (label_printer_status looked up by printer_id) — #919 had merged, so follow-up PR. Dispatched: Opus investigation of the no-child stock-scaling candidate (scratch/investigate-901-factor); #955 fixer revision 3 (product-specific 1:1 before/after governing-pair rule).
- 2026-10-13T23:51:46Z #955 rev 3 pushed 3e6f0030 (signed): no logic change (before/after governing-pair rule already enforced since 4adcb1b3); stale comments fixed; +5 GUARD tests (removed-only vs global 2 merges; kept-only no global refused early; kept-only vs global 2 refused; both own 1:1 merges; each→piece inverse merges). 7 phases exit 0 (stockcoverage 428/4983). Body's open question 1 must be marked answered. Validation waits on the scaling investigation.
- 2026-10-13T23:54:48Z Scaling investigation (Opus, master d02ec124): CONFIRMED original #901 defect for no-child/no-own-unit-row merges across different stock units (a: 3 pieces→1.5, b: 1.5, c: 6; d: Keep's effective factor 2→1; e control preserved; own-unit rows already refuse). Violates decision M; ruled #955 blocker per maintainer. Not filed separately (not distinct). Fixer rev 4 dispatched: require removed factor == kept before (if any) == kept merged-state, before any write.
- 2026-10-14T00:12:50Z #956 READY at db7b907d (19 checks pass; comment-only).
- 2026-10-14T00:21:04Z #955 rev 4 pushed dfc3fa9b (UNSIGNED, S1): stock-unit factor must agree across Remove before / Keep before (if any) / Keep merged-state, checked before any write; variants a–d REGRESSION vs 3e6f0030 (433 tests, 5 failures), e GUARD; 4 child-test assertions now name units/factors. 7 phases exit 0 (stockcoverage 433/5040). Full Opus validation dispatched.
- 2026-10-14T00:45:04Z #955 Opus full validation VALIDATED (non-blocking) at dfc3fa9b; body updated (6th revert failure is GUARD; dead child-branch comparison ~:5232-5245; R2P-shape conflict only probe-pinned; variant d confirmation requested). READY (19 pass, lines 96.55%).
- 2026-10-14T12:20:04Z #954, #955, #956 MERGED by maintainer.
