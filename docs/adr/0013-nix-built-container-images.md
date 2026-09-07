# ADR-0013: Production images are built by Nix from a flake in this repository

- **Status: Accepted, 2026-09-04.** **Nix is the production builder, and
  `Dockerfile`-based images are development containers** — heavier, with a surface Nix
  does not carry. All five acceptance prerequisites below are met, each annotated in place
  with what met it; none was amended or relaxed. **Supersedes the `Dockerfile`'s
  `production` target**, whose retirement this acceptance schedules rather than performs —
  see *Consequences* and open question 5, which is now
  [plan 20](../plans/20-container-infrastructure.md)'s piece 3.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **This record was never rejected.** A commit on 2026-09-03 (`1c97766f`) marked it
  Rejected and deleted the flake, and it was reverted the next commit (`62686a2b`) as
  having "read the maintainer's direction backwards". That is accurate but too gentle: the
  rejection was **an agent's hallucination of a decision the maintainer never made**, not
  a decision later reconsidered. It is recorded here rather than left in the git history
  because a reader who finds that commit deserves to know it carried no authority — and
  because "the maintainer rejected this once" is exactly the kind of false provenance a
  corpus of records exists to prevent. Numbers are permanent and reasons are preserved;
  so are non-reasons.
- **Recorded:** 2026-09-03, and revised the same day when
  [plan 10](../plans/10-cold-start-statelessness.md) landed a production image from the
  `Dockerfile` while this was in review. The revision is in *Context*; the decision did
  not change, but half of what it was arguing against did.
- **Relationship:** supplies the *how* for [ADR-0010](0010-workload-standard.md)'s fourth
  property — a workload "exists in the repository's deploy tree with health probes and
  resource limits, or it does not exist" — and makes its third, non-root with a read-only
  root filesystem, a structural property of the artifact rather than a line somebody
  remembers to add. 0010 is Proposed and this record does not assume otherwise: what
  follows stands on the build-system argument alone.
- **Supersedes:** the `Dockerfile`'s `production` target, which
  [10](../plans/10-cold-start-statelessness.md) landed on 2026-08-31. **Not** its `dev` target,
  which is a different artifact for a different job and stays — and which this acceptance
  makes the `Dockerfile`'s *only* job. Retiring the production stage is scheduled rather
  than done here, as [plan 20](../plans/20-container-infrastructure.md)'s piece 3: it
  carries the `images` CI job's assertions across, and the one that has no
  `nix flake check` equivalent — booting a container and fetching a URL — is now covered
  by that plan's verification instead. See *Consequences*.
- **Would affect:** [01](../plans/01-file-storage.md),
  [02](../plans/02-mcp-endpoint.md) and [18](../plans/18-mqtt-state-publication.md), whose
  workloads are born into this pattern or outside it;
  [16](../plans/16-project-rename.md) (registry claims).
- **Referenced by:** [plan 20](../plans/20-container-infrastructure.md), [the deploy
  tree](../../deploy/README.md), [nix/README.md](../../nix/README.md).

## Context

**This record was drafted against a repository with no production image, and that stopped
being true while it was in review.** PR #33 landed
[plan 10](../plans/10-cold-start-statelessness.md) with a `production` target in the same
`Dockerfile`: Apache with mod_php, non-root `www-data`, a view cache baked at build time,
a read-only root filesystem, a `.dockerignore`, and an `images` CI job asserting each of
those against the built artifact. Three of this record's original premises — that there
was no production image, that the only image ran as root, and that a read-only root
filesystem needed work a Dockerfile could not do — went with it.

What follows is therefore the argument as it stands *now*, against that image rather than
against a vacuum. It is a weaker case than the one first written, and saying so is the
point: the remaining reasons are fewer and have to carry more.

**"Pinned" is not what a Dockerfile means by pinned.** `FROM php:8.5-apache-bookworm`
followed by `apt-get update` resolves to different bytes on different days, by design.
Two builds of the same commit are two different dependency graphs, and neither is
recorded. This corpus already treats "measured, not assumed" as a standing rule, and
applies it to migration behaviour, to view compilation and to security findings; the
artifact those all ship inside is the one place it does not currently reach.

**The attack surface that matters is what is in the image at all.** The fork takes
authenticated issues seriously ([ADR-0006](0006-authenticated-issues-in-scope.md)) and the
security sweep's findings are about what an attacker can reach once inside. The production
image is Debian: a process that reaches code execution finds a shell, `apt`, `curl`, and
the PHP CLI. None of those is a vulnerability; all of them are capability.
`readOnlyRootFilesystem` and dropped capabilities do not remove them — they make them
harder to persist with, which is a different property. Removing them is not achievable by
editing the current file, because multi-stage-copying a PHP install and its shared
libraries out of a Debian builder by hand is a job people do badly and then stop
maintaining.

**The source is a denylist.** `COPY . /app` plus a `.dockerignore` means a new directory
in the repository is in the production image until somebody notices. That is how `docs/`
is in it today. The inverse — nothing is in the image unless it is named — is the property
that makes "what is in this artifact" a reviewable question.

**The deployment is about to become a family.** The MCP sidecar is settled as its own
container, plan 18 needs a publisher, [ADR-0011](0011-label-namespace.md) replaces a
webhook consumer with a drainer. None of them is PHP, so none of them inherits the
`Dockerfile`'s Debian-and-Apache answer, and each would otherwise be a fresh copy of
whatever base-image choice, non-root incantation and pinning discipline the first one
established by hand. This is the argument the current image does not answer at all, and it
is the one that gets stronger with time rather than weaker.

## Decision (proposed)

**Production container images are built by Nix, from a flake in this repository, one image
per workload, on no base image.**

1. **`flake.nix` and `nix/` are the production build.** Every image the fork publishes is
   a `dockerTools.streamLayeredImage` output of that flake. The contents are the
   transitive closure of what the process runs and nothing else: no base image, no
   package set, no shell, no package manager, no build tooling at runtime.

2. **The `Dockerfile`'s `dev` target stays, unchanged in purpose.** It is the development
   and CI image and it is good at that. Two images with two jobs is the correct number.

3. **The `Dockerfile`'s `production` target is retired when this is accepted**, not
   before, and the accepting change schedules that work. It has an `images` CI job
   asserting real properties of it, and those assertions move rather than disappear —
   `nix/checks.nix` is where their equivalents live.

4. **Source is an allowlist.** `nix/source.nix` names what goes in.

5. **Every image runs as uid 65532 with a read-only root filesystem and an empty `/bin`**,
   asserted in `nix/checks.nix` and therefore checkable by `nix flake check` rather than
   by reading a file for the string `USER`.

6. **One workload, one image, one credential.** `victual-app` (php-fpm, holds the database
   credential, no document root), `victual-web` (nginx, holds the document root, no
   credential and no PHP interpreter at all) and `victual-migrate` (the only one whose
   PHP carries `pdo_sqlite`, and the only one that should ever hold a role able to run
   DDL). What separates them is the credential each holds, not the bytes each carries:
   the application tree is identical in all three and is meant to be.

7. **The deploy tree lives in `deploy/`**, carrying manifests for fork-shipped workloads
   and saying nothing about ingress, storage classes or secret management. This is the
   concrete form of ADR-0010's open question 1, which leans the same way; if 0010 is
   accepted with the other answer the tree moves and the flake does not care.

8. **`flake.lock` is committed and reviewed.** It is the pin that makes "reproducible"
   mean anything, and a nixpkgs bump is a pull request that says what moved.

## Consequences

**`kubectl exec … sh` stops working, forever.** This is the cost that will be felt first
and most often, and it is the same property that stops an attacker typing `sh`. Debugging
becomes `kubectl debug --image=…` with an ephemeral container, or reproducing locally
against the same image. It should be decided on purpose rather than discovered during an
incident.

**Nix becomes a hard dependency of releasing.** On the maintainer's Mac that means a Linux
builder — `nix-darwin`'s `linux-builder`, or a Nix container under podman, since macOS
cannot produce a Linux image on its own. `nix/README.md` documents both. Paid once.

**Two content hashes are maintained by hand.** The Composer vendor tree and the yarn
offline mirror are fixed-output derivations, so `composer.lock` and `yarn.lock` each have a
hash in `nix/hashes.nix`. The failure is loud — the build stops and prints the correct
value — which makes it an annoyance rather than a hazard. It is still the thing most
likely to make someone want the Dockerfile back.

**Retiring the production stage costs its CI job's assertions.** The `images` job asserts
non-root, a baked and unwritable view cache, no `.git` or `data/`, and a live read-only
container serving a page. Four of those five have `nix/checks.nix` equivalents that are
stronger, because they are assertions about the artifact rather than about a container
started from it. The fifth — actually booting the thing and fetching a URL — does not, and
that gap is real: it is what acceptance prerequisite 1 exists to close, and what plan 20's
verification has to carry afterwards.

**The application is served from a store path, not from `/app`.** This falls out of
something plan 10 made true: `bin/victual-warm-cache` compiles Blade templates whose
compiled file names hash the *absolute path* of the views directory. Warm at one path and
serve from another and every page is a 500 against a read-only cache. Baking the cache
inside the derivation that owns the tree makes the two paths the same by construction. The
cost is that paths in logs and manifests are store paths; the benefit is that the class of
bug is gone rather than documented.

**New workloads are born into this.** A Go or TypeScript sidecar is a `buildGoModule` or
`buildNpmPackage` away from an image with the same uid, labels, empty `/bin` and checks.
That is the payoff for deciding before the family exists rather than after.

**The images are smaller than the Debian one, and the number is not yet known.** No build
has run. Any figure here would be a guess, so there is none; taking the measurement is
acceptance prerequisite 2.

## Acceptance prerequisites

Gates, not suggestions. This record was written from interfaces read rather than run, and
these are what turned a read interface into a run one. **All five are met**, by
[plan 20](../plans/20-container-infrastructure.md) piece 1 and the run that closed
[#49](https://github.com/datagen24/victual/issues/49). None was amended, relaxed or
dropped; each carries what met it.

1. **All three images build, and the pod serves.** On the maintainer's Mac, through
   podman: `nix run .#load`, then `podman kube play deploy/podman/victual.yaml` against a
   throwaway PostgreSQL, then a rendered `/login` and one authenticated API read
   (`GET /api/stock`). The accepting pull request records image sizes and
   `nix path-info -rSh .#image-app`.
   — **met.** All three build and load, and the pod serves: `/login` renders, `GET
   /api/stock` returns JSON, and 27 top-level pages answer 200. Getting there took the two
   podman-versus-Kubernetes defects and the broken error page recorded in plan 20's second
   Executed section. Sizes: 284 MB, 206 MB and 291 MB.
2. **The comparison against `victual:production` is measured, not asserted.** Size, and
   whether a shell is present, for both. The claim in *Consequences* that these are
   smaller is either replaced by the number or deleted.
   — **met.** 284 MB, 205 MB and 291 MB for app, web and migrate, against the `Dockerfile`
   production image's 819 MB; no shell in any of the three.
3. **`nix flake check` passes**, including `image-has-no-shell` and
   `web-tier-carries-no-application`. If nixpkgs' PHP drags a shell into its runtime
   closure after all, this record's claim is amended to what is true rather than the check
   being relaxed.
   — **met**, 34 assertions. `image-has-no-shell` earned its place: it failed first, and
   correctly, on three shells reached through PEAR's `bin/pear`, PHP's `PROG_SENDMAIL`,
   and `gd`. The check was not relaxed; the closure was.
4. **The two fixed-output hashes reproduce** on a second machine or architecture. A hash
   that only holds where it was produced is not a pin.
   — **met, on a second architecture rather than a second machine.** The `nix` workflow
   builds this flake on `ubuntu-latest` (x86_64-linux) and the local build is aarch64-linux
   inside podman, from the same `nix/hashes.nix`. Both pass.
5. **The read-only root filesystem is proved on a running container**, not only asserted
   at evaluation — the one thing the `images` job does that `nix flake check` cannot. Plan
   20's verification check 4 is the form of it.
   — **met**, and probed rather than asserted: from inside the running app container,
   writes to `/opt`, `/etc` and `/data` are all refused while `/tmp` succeeds, across a
   create, an edit, a delete, and an image uploaded, served, thumbnailed and deleted
   through `FILE_STORAGE=database`. No `EROFS` anywhere, and the baked view cache's mtimes
   are still the image's epoch afterwards.

**An amendment was available and was not taken.** While #49 was open, gates 1 and 5 both
reduced to "a pod serves", and the thing blocking that was a manifest defect rather than a
build-system one — so splitting gate 1 into "the images build" and "the pod serves" would
have made this acceptable earlier, the way
[ADR-0008](0008-postgresql-only-runtime-engine.md) amended one of its own gates in the
open. The maintainer chose to fix #49 first and accept with every gate met as written.
Recorded because the road not taken is part of what a gate is worth: fixing it turned up
two further defects that no amendment would have found, one of which — every error page on
these images being a fatal error — had been shipped and unnoticed since plan 10.

## Open questions

1. **Where do the images go?** Nothing is published today and
   [16](../plans/16-project-rename.md) parks registry claims until announcement. *Lean:
   decide nothing now; `podman load` locally and a k3s node-local image is enough for the
   household deployment.*
2. **Should CI build the Nix images on every pull request?** *Lean: not per-PR. Build them
   on `master` and on tags, and keep the pull-request loop fast. The `images` job's
   read-only boot test should move across at the same time.*
3. **nginx, or something smaller?** A single static binary — Caddy, or serving the assets
   from the ingress and dropping the tier — would be smaller. *Lean: nginx for the first
   cut; re-answering later costs one file.*
4. **One architecture or two?** *Lean: build for the cluster's architecture and treat
   "runs on the laptop" as a property of the podman bootstrap.*
5. ~~**When does the `production` stage actually go?**~~ **Answered 2026-09-04: it is
   gone**, and the lean is what happened — the stage removed, the `images` job's boot test
   moved onto the Nix images in the `nix` workflow, the `assets` stage that existed only to
   feed production removed with it, and the four assertions with `nix flake check`
   equivalents left to those. `docker build .` now builds the dev image, which is the
   `Dockerfile`'s only stage.

   The lean said "in the same commit" as the acceptance and it was one commit later, for a
   reason the lean could not have known: it was written 2026-09-03, and the parity suite
   arrived on 2026-09-04 building `--target production` because that was the only image in
   this tree that served HTTP. Porting that suite onto the Nix images was a real piece of
   work — two serving containers sharing a network namespace where it expected one — so it
   was [issue #56](https://github.com/datagen24/victual/issues/56) rather than a rushed
   half of this change.

   **#56 is done, also 2026-09-04.** `.devtools/parity/stack/stack.sh` runs
   `victual-migrate` once PostgreSQL is up, then puts `victual-app` and `victual-web` in a
   `podman pod` joined to the parity network, with the read-only root, dropped
   capabilities and in-container probes the manifest uses. It still does not play
   `deploy/podman/victual.yaml`, for the reason it never did: an initContainer runs to
   completion before PostgreSQL would start. The suite now compares upstream against the
   artifact this repository ships rather than one it does not, which was the argument for
   doing it rather than deleting the target check.

## Options considered

**Keep the `Dockerfile`'s production target and do nothing.** The status quo, and it is
not a bad artifact — it is non-root, read-only-capable and CI-asserted. It does not
address reproducibility, image contents, or the non-PHP workloads coming.

**A distroless base.** Solves the shell and the package manager; does not solve
reproducibility (the base tag moves), does not solve PHP (there is no distroless PHP, so
the interpreter and every extension still come from a Debian builder stage), and adds a
dependency on a base image somebody else versions.

**Buildpacks / `ko` / `jib`.** Language-ecosystem tools with no PHP story worth having.

**Nix.** Costs a bootstrap and two hand-maintained hashes; gives an allowlisted source
tree, a closure-exact image, assertions about the artifact rather than about the file that
describes it, and the same conventions for every future workload in the family.

## Research

- Tree facts measured against the working copy of 2026-09-03, and **re-measured after
  PR #33 landed**, which is what invalidated the first three. The transferable lesson is
  not about Nix: "measured, not assumed" does not protect a measurement from going stale
  between taking it and acting on it.
- nixpkgs interfaces read against `nixos-unstable` on 2026-09-03: `php85` exists at
  8.5.10, satisfying `PrerequisiteChecker::REQUIRED_PHP_VERSION`; `php.buildEnv` replaces
  rather than extends the default extension set; `buildComposerProject2` splits the fetch
  into a hashed fixed-output derivation and honours `composer.json`'s `vendor-dir`;
  `fetchYarnDeps` handles the one git-resolved entry in `yarn.lock`; `streamLayeredImage`
  takes its closure roots from both `contents` and the image config, so a store path named
  only in `Env` is still in the image. Read, not run.
- **The scaffolding's first review found six defects, and they are the best available
  evidence for what prerequisite 1 is worth.** Five were packaging or configuration errors
  that reading could in principle have caught and did not — an omitted
  `victual.openapi.json`, a stripped `migrations/`, a config stub declared but never
  installed in an image, nginx temporary paths whose parent no volume creates, and a
  document root with no index file behind a `try_files` that needs one. The sixth was a
  Kubernetes semantics error: a `tcpSocket` probe cannot reach a loopback-bound php-fpm,
  because the kubelet resolves the target to the pod IP. All six are fixed. Five of six
  were invisible until something ran.
- Plan 10's landing supplied three things this record's images now depend on:
  `VICTUAL_VIEWCACHE_PATH` and `bin/victual-warm-cache` (so the cache is a layer rather
  than state), a driver-aware `checkDatabaseRequirements()` (so the serving images drop
  `pdo_sqlite`), and `SchemaVersionMiddleware` (so a pod whose migration has not finished
  answers 503 and is correctly not ready). The Blade absolute-path hash, which the warmer
  documents, is what decided the store-path layout.
