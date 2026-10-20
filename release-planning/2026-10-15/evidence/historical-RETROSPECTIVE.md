# Bug bash 2026-10-13: retrospective

**Bottom line.** 18 PRs merged. They closed 13 of the 21 issues in scope and 2 issues filed during the round (#922, #923). #897 was closed by decision B. Three PRs are still open, and none of them is ready to merge without further work:
- #919 has an unfinished revision.
- #921's last commit has not been validated.
- #945 passed CI but now conflicts with master.

Independent validation found eight defects in code that CI had passed. Most rework came from my own briefs: about nine of them had errors. The rest of this document gives the evidence and what to change next time.

State recorded at 2026-10-13, about 20:45Z. The ledger, decisions and briefs are in `~/.claude/projects/-Users-speterson-src-grocy/bugbash-20261013/`.

## 1. Outcome

### Merged (18)

#907, #910, #911, #914, #915, #918, #924, #925, #927, #931 (ADR-0048, Proposed), #932 (ADR-0049, Proposed), #934, #935, #938, #939, #940, #943, #949.

### Issues closed

- **Closed by this round's merges:** #798, #844, #888, #889, #894, #895, #896, #899, #902, #903, #922, #923.
- **Closed by decision:** #897 (B=a).

A closed issue means the PR that names it merged after validation at its head SHA. It does not mean the defect class is gone everywhere. #905 is the counter-example: it is still open because the bypass we found through it (#920) is a separate, unresolved dependency.

### Open PRs at stop

| PR | Issues | Head | State | What it needs |
|---|---|---|---|---|
| #919 | Part of #905, Fixes #941 | 45aa7a53 (validated, round 4) | Draft | Revision 5 (resolve view owners through the view key, the maintainer's answer). It was stopped mid-edit with uncommitted changes to `UserfileOwnerReadTest.php` in `agent-ae79539bc4ebc4a42`. Finish it, then validate round 5. |
| #921 | Fixes #901, #929 | 0863a0cd (signed) | Draft | The follow-up landed: a child with no conversion before or after merging needs the same stock unit, otherwise the merge refuses. stockcoverage passed (392 tests, exit 0). This commit is **not validated**, and no before-fix failure was observed for the new test. Next: Opus validation at 0863a0cd. |
| #945 | Fixes #892 | 1440c505 (validated v2) | Draft, **CONFLICTING** | CI passed (11 checks). It conflicts with master after #943 and #949 merged, most likely in the changelog. Merge master, check CI again, then mark ready. |

### Work stopped without a PR

| Issue | Where | State |
|---|---|---|
| #904 part 2 | `agent-aca48984e166c66ba`, branch `…issue904-subprocess-migration-x5` | Commits 31b3439c and 72fdffef are local only. They migrate 28 read sites and add readLine, stdin and abort to the helper. Not pushed. The agent was fixing a sed failure on macOS. The before/after phase counts are not complete. |
| #898 | `agent-a0c3aa729ea2134df` | A new `RenderClaimChangeSignalTest.php` and a `phpunit.xml` edit, both uncommitted. It was about to run labelapi on unfixed code. |
| #893 | `agent-a02a71130d352b069` | Just started. No changes. |
| #891, #801 | none | Not started. |

### Still open in scope

#890 (part merged through #949; the rest depends on #951), #891, #892, #893, #898, #900 (waiting on decisions in ADR-0049 Part 2), #901, #904, #905 (waiting on #920), #929, #941, #801, and tracker #796.

### Filed during the round (24)

#908, #909, #912, #913, #916, #917, #920, #922, #923, #926, #928, #929, #930, #933, #936, #937, #941, #942, #944, #946, #947, #950, #951, #952.

## 2. What validation caught that CI did not

Each of these PRs was green on CI when an independent validator rejected it at the exact head SHA. Every rejection was backed by a reproduction or a revert experiment.

| PR | Defect | Severity |
|---|---|---|
| #919 | Thumbnail ("derivative") filenames bypassed the owner check. Found twice, in different forms. | Security disclosure |
| #919 | The design-review fix derived access from the root file for every group. That opened a userpictures disclosure. | Security disclosure |
| #911 | The walk covered arrays but not objects, and did not handle cycles. | Correctness |
| #915 | An enum value had no backing data (the BatteryChargeCycleEntry row). | Contract |
| #935 | A test could hang. | CI reliability |
| #945 | Price fields had unhandled null branches. | Correctness |
| #921 | Over-refusal: the merge refused cases that are legitimate. | Behaviour regression |

These are the eight defects (#919 accounts for three). Two of them were disclosures that CI had no test for. That makes validation at the head SHA the most valuable step in this process. It was also the slowest: #919 and #921 each took four validation rounds.

## 3. Where the time went

### My brief and design errors (about 9)

1. **#911.** The brief said "walk array", so the fixer did not walk objects.
2. **#915.** The brief left out the BatteryChargeCycleEntry row.
3. **F3 decision doc.** It described `EntityReadPolicy::null` as "no policy". It actually means "open to any authenticated caller". That cost a second maintainer decision (F3′).
4. **#931 and #932.** The briefs did not mention the mkdocs `nav` entry, so the strict lint failed on CI. This went back to the maintainer as "agent forgot to add it to the nav".
5. **#939.** The brief dropped one done-when item.
6. **#901.** The brief had test 3 inverted and missed the child, flag-1 and duplicate-cache cases. The Opus brief review caught this before dispatch.
7. **#899.** The brief specified a blocking advisory lock, which would have serialised the renderers again. The Opus brief review caught this.
8. **#921.** My follow-up instruction caused over-refusal, which then needed a full design review.
9. **#919.** The design review's root-derivation rule had no group scope. Validation caught the resulting userpictures hole.

The Opus brief reviews stopped 2 of these 9 before any code was written. The other 7 reached a fixer, and 5 of those reached validation or CI.

### Process friction

- **Phase counts shifted between phases** in fixer reports, twice (#903/#914 and #923/#940). The fix was a rule: paste the runner's own `[suite] phase=X exit=N` line. That rule should exist from the start.
- **One PR body was overwritten.** It turned "Fixes #902" into "Part of #902". A scripted check of `closingIssuesReferences` caught it.
- **Changelog merge conflicts** on at least five PRs (#921, #932, #934, #943, #945). Every PR appends to the same unreleased file.
- **Signing.** 1Password SSH signing failed later in the session. The S1 fallback was used on 7 commits, each disclosed in its PR (#934, #943, #945 ×3, #949, #921). #921's latest commit was signed.
- **Usage-limit cutoffs** killed agents mid-run. Resuming them in their own worktrees through SendMessage recovered the work. Two lost phase outputs had to be re-run.
- **Shared runner.** suite-runs.log has 711 lines for the round. Queue waits of 10–30 minutes per fixer were normal, and that set the round's pace more than agent speed did.
- **One intermittent CI failure** (a consumption fixture, filed as #942). It passed on rerun.

## 4. What worked

- **Haiku readers producing fact sheets against a named commit.** They were cheap, and fixers started from verified line references.
- **Opus brief reviews before risky dispatch.** They caught 2 of the 9 brief errors, both of which would have been expensive.
- **Opus validators at the exact head SHA, with revert experiments.** They caught the 8 defects in section 2.
- **Escalating to a whole-design review after a second validation failure.** This broke the patch-on-patch loop on #921 and #919. One design table with maintainer cells (#921) settled behaviour that four rounds of patches had not.
- **Batched maintainer decisions** recorded verbatim in DECISIONS.md: S1, F1–F6, F3′, M, L, O1–O4, Q1–Q4, B, A, #922/#923, the #921 cells and the #919 view owners. No decision had to be asked twice.
- **The serialized runner** (suite.sh). There were no VM OOMs this round, which the previous round did have.
- **A durable ledger and handoff.** They made the restart after the usage limit cheap.

## 5. Changes for the next round

1. **A brief checklist, applied by the coordinator before dispatch:**
   - every done-when item is carried into the brief;
   - a new docs page gets its `mkdocs.yml` nav entry;
   - the changelog line is included;
   - "walk" means arrays **and** objects, with cycle handling;
   - every enum value has a backing row;
   - policy semantics are quoted from the code, not paraphrased.

   Seven of the nine brief errors would have been caught by this list.
2. **Opus brief review for every brief that touches a security or permission boundary, or a data migration**, not only the ones I judged risky. Both disclosures were in #919, and its first brief was not reviewed.
3. **Scope design-review output before dispatch.** Restate every rule as "applies to groups X; does not apply to Y". That would have caught the userpictures hole.
4. **Runner-line reporting and a closing-reference check in FIXER_RULES from day one.**
5. **Changelog conflicts.** Either write each PR's changelog line to its own fragment file, if the repository adopts that, or merge master and resolve the changelog as the last step before marking ready. Do not do it as an afterthought.
6. **Plan for signing failure.** Get the S1 decision in the first batch. It was obtained early this round, and that paid off.
7. **A usage budget.** This round used about 25% of the weekly allowance in one day. Before starting, cap concurrent fixers and validation rounds per PR, for example at three before escalating to a design review.
8. **Never leave a commit unvalidated at stop.** If the round must stop, stop fixers before they push, or record the exact unvalidated SHA, as for #921 above.

## 6. Remaining decisions and limitations

- **#900:** waits for decisions in ADR-0049 Part 2.
- **#905:** stays open until #920 (the F5 bypass) is resolved. #919 must not be described as closing the disclosure.
- **ADR-0048 and ADR-0049 are Proposed.** Accepting them is a separate PR.
- **#890:** the rest depends on #951.
- **#942:** the intermittent consumption fixture is filed but not investigated.
- **Coverage.** Coverage numbers come from per-PR CI only. No round-level coverage run was done.
- **Untested this round:** no before-fix failure was observed for the new #921 test, and #904 part 2 has no phase counts.
