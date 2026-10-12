# ADR-0047: One trunk, release branches for UAT, and each check run once at the stage that decides

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-12, against `master` at `7b486823`. The maintainer chose the
  delivery model (trunk with release branches) and the environments (QA on kind in CI, UAT
  on Talos) on 2026-10-12; this record writes those choices down and works out what they
  require. Design work only: it changes no workflow.
- **Referenced by:** [ADR-0030](0030-released-images-are-published-to-ghcr.md) decisions 2
  and 3, [ADR-0025](0025-three-test-tiers.md), [plan 33](../plans/33-coverage-floor.md),
  [coverage README](../../.devtools/coverage/README.md), pull request #825.

## Context

The `tests` workflow is the feedback loop for every change, and it has become the slowest
part of the work. Measured from CI job logs on 2026-10-12:

1. **The `suite` job takes 30 to 42 minutes, and it is the critical path.** Run 38255359626
   took 40.3 minutes for the 63 phases of `.devtools/pgsql/run-tests.sh` with
   `SUITE_COVERAGE=1`. The same 63 phases took 15.6 minutes in `suite-floor`, which runs
   without coverage. Run 38253521069 took 28.2 against 15.4. Pull request #825 attacks the
   per-process coverage cost directly; this record is about the rest.
2. **One change runs the full suite four times.** `suite`, `suite-floor` (PostgreSQL 15)
   and `images` (inside the development image) each run all 63 phases on the pull request,
   and all three run again on the push to `master` after the merge. The push re-tests a tree
   the pull request already tested, except for whatever merged in between.
3. **Master turns red from merges, not from defects.** Four of the six red `master` runs on
   2026-10-12 failed on one assertion (`43 is identical to 40`). Two pull requests, each
   green against its own base, each added OpenAPI examples. Nothing tests the merged result
   before it lands.
4. **Master has no branch protection.** No check is required to merge, so no gate in any
   delivery model can be enforced today.
5. **No CI job builds an image per merge.** `nix.yml` builds on `master` only when the flake
   or a lockfile changes, and publishes nothing. `release.yml` builds and publishes on a
   `v*` tag. The `master-<sha>` images on the Talos cluster are built by the maintainer.
6. **The suite only grows.** The `all)` line of `run-tests.sh` names 55 phase functions, and
   each feature pull request adds one. Nothing decides which phases a given change needs, so
   every change runs all of them.

The maintainer asked for two things: no test repeated on the same code, and a delivery
lifecycle with gates between development, QA, UAT and production.

## Options considered

1. **Long-lived environment branches (`dev` → `qa` → `uat` → `master`).** Each promotion is
   a merge, and each merge creates a commit that has not been tested. Either CI runs again at
   every promotion, which repeats the suite three more times per change, or an untested merge
   result moves forward. The branches also drift apart. A fix made in `uat` must be merged
   down to `qa` and `dev` again. With agent pull requests merging several times an hour, the
   merges between environment branches become a job of their own. The merge-order collision
   in context item 3 would recur at every promotion. Rejected.
2. **One trunk, with images promoted through environments.** `master` is the only
   integration branch. One built image moves from QA to UAT to production, and each gate
   checks something the previous gate did not. Nothing is tested twice. The weakness is
   that a fix needed by the version in UAT must ship with everything else merged to `master`
   since then.
3. **One trunk plus a release branch per version (chosen).** As option 2, but a version that
   enters UAT gets a `release/<major>.<minor>` branch. A fix for that version lands on
   `master` first and is cherry-picked to the release branch, so UAT never receives
   unrelated work.

## Decision (proposed)

**`master` is the trunk: the integration branch, which feeds the development
environment.** It is not production. Production is whatever a signed `v*` tag on a release
branch names. The maintainer described master as "stable production" when asking for this
record. Under the chosen model that role belongs to the tag, and this sentence exists so the
record cannot be read the other way.

1. **Each check runs once, at the stage whose decision it informs.**

   | Stage | Trigger | What runs | Gate to the next stage |
   |---|---|---|---|
   | Pull request | push to a pull request branch | lint, and the suite phases the change's paths map to (decision 3); frontend probes when frontend paths change | required checks on `master` |
   | Merge queue | the pull request enters the queue | the full suite, once, with coverage and the ratchet, inside the development image, against the actual merge result | the queue merges only a green result |
   | `master` (Dev) | push to `master` | no tests; the merge queue tested this exact tree | none; the commit is a QA candidate |
   | QA | scheduled, nightly, when `master` has changed since the last QA run | `nix flake check`, the six images for both architectures, the boot test and page walk, the suite on the PostgreSQL floor, a kind install of the built images by digest through the Helm chart, and the frontend probes against that install | `qa` GitHub Environment: every job green |
   | UAT | the maintainer cuts `release/<major>.<minor>` from a commit that passed QA | the QA images for that commit, installed on the Talos cluster by digest; the maintainer exercises them | `uat` GitHub Environment: maintainer approval |
   | Production | signed `v*` tag on the release branch | no tests; publishes the digests UAT approved | `production` GitHub Environment: maintainer approval |

2. **The jobs that repeat the suite today are absorbed.**
   - `images` stops running a separate suite. The merge-queue suite runs inside the
     development image, which is the reason `images` exists (the failures of 2026-09-30, red
     in the image and green on the runner).
   - `suite-floor` moves to QA. The PostgreSQL floor decides whether a release supports an
     older server, not whether a pull request may merge.
   - The `push: master` trigger of `tests.yml` is removed.

3. **What a pull request tests is declared in one file.** A committed map from path patterns
   to suite phases (`.devtools/ci/phases.yml`, or the like) decides what the pull request
   stage runs. A path no pattern matches runs every phase, so a new directory is never
   silently untested. A pull request that adds a phase adds its paths to the map in the same
   change. The map decides speed only, not coverage: the merge queue still runs everything.

4. **The coverage ratchet is enforced in the merge queue, not on the pull request.** Each
   queue entry is one pull request's merge result, so "every pull request leaves the number
   no lower than it found it" ([AGENTS.md](../../AGENTS.md)) still holds, and it now holds
   against the real merge result. Coverage collection leaves the pull request stage.

5. **Release branches.** `release/<major>.<minor>` is cut from a commit that passed QA, and
   `version.json` on the branch names that version. A fix lands on `master` through the
   normal queue first, then is cherry-picked to the release branch by pull request. A pull
   request to a release branch runs the full suite, because its tree is one `master` never
   had. The branch is deleted when the next minor version reaches production. Patch versions
   are tags on the same branch.

6. **Production publishes what UAT approved; it does not rebuild.** QA builds the images
   once and pushes them to GHCR under immutable `<image>:qa-<sha>` tags for both
   architectures. UAT installs those digests. A `v*` tag on the release branch re-tags the
   same digests as `<image>:<version>` and lists them in the GitHub release. This amends
   [ADR-0030](0030-released-images-are-published-to-ghcr.md) in two places:
   - Decision 2, "the tagged commit must be on `master`", becomes "the tagged commit must be
     on `release/<major>.<minor>` for the tag's version". A cherry-picked fix has a SHA that
     is not on `master`.
   - Decision 3's "the publish job pushes those streams; it does not rebuild them" is kept,
     and moves earlier: the streams are pushed by QA, and the tag only promotes them.

7. **Deployment into the Talos cluster stays with the maintainer.** `deploy/talos/up.sh`
   installs by digest. CI holds no credentials for the home cluster. The `uat` and
   `production` environments record the approval; they do not perform the deployment.

## Consequences

- **Pull requests get fast and deterministic feedback.** A pull request runs lint and its
  mapped phases, in minutes rather than 30 to 42. The full suite runs once per merge, in the
  queue.
- **Master stays green by construction.** The queue tests each merge result before it
  lands, which closes the gap that produced context item 3.
- **The merge queue serialises merges.** Each queue entry costs one full suite, about 16
  minutes without coverage and more with it until pull request #825's effect is measured.
  The queue tests entries in parallel speculatively, so several merges an hour remain
  possible. A red entry costs the entries behind it a re-run.
- **QA costs one two-architecture image build per day `master` changes.** ADR-0030 records
  that a build compiles PHP from source on each architecture. Building per merge instead was
  considered and left to open question 1.
- **QA and UAT exercise different architecture members of one image index.** kind on
  `ubuntu-latest` runs the `amd64` images and the Talos cluster runs the `arm64` images. Both
  come from one build of one commit, and both pass QA's boot test and page walk. The record
  claims no more than that.
- **The path map is a new artifact to maintain.** A stale map makes pull request feedback
  weaker, never the merge gate. The default for unmatched paths runs every phase.
- **Cherry-picks to release branches are manual work.** That work exists only while a version
  is in UAT and needs a fix.
- **Ruled out:** environment branches (option 1); running coverage on every pull request;
  a CI job that deploys into the home cluster.

## Acceptance prerequisites

1. `master` branch protection, with the pull request stage's checks required and direct
   pushes refused.
2. The merge queue enabled on `master`, with `tests.yml` triggered on `merge_group`.
3. ADR-0030 decisions 2 and 3 reconciled with decision 6 above. ADR-0030 is itself
   Proposed, so either both records are accepted together, or ADR-0030 is revised before
   this one is accepted.
4. The path-to-phase map written, with each of the 55 phases mapped, and reviewed by the
   maintainer.
5. The QA workflow exists and has produced one green run that published `qa-<sha>` digests.

## Open questions

1. **Per-merge or nightly images for Dev?** Nightly bounds the build cost and makes "when it
   runs" a fixed time. Per merge gives the Talos Dev install a build for every commit. This
   record proposes nightly.
2. **Does Dev need its own Talos namespace?** The cluster runs one install today. UAT could
   take it over, with Dev remaining the maintainer's local runs and kind.
3. **Retention of `qa-<sha>` images.** They accumulate in GHCR. A cleanup rule keeping those
   that a release tag references, plus the last N, is the obvious choice. It is not decided
   here.
