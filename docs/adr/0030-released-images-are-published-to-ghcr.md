# ADR-0030: A release tag publishes the Nix images to GHCR for amd64 and arm64

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-09-24
- **Referenced by:** [ADR-0013](0013-nix-built-container-images.md) open questions 1
  and 4, [plan 16](../plans/16-project-rename.md) (registry claims),
  [releases](../releases/README.md)

## Context

[ADR-0013](0013-nix-built-container-images.md) makes the flake the production image
builder and leaves two questions open. Question 1 asks where the images go; its lean was
to publish nothing and load images into podman or a k3s node by hand. Question 4 asks
whether to build for one architecture or two; its lean was to build for the cluster's.

Two facts have changed since that lean was written on 2026-09-03:

1. The fork has tagged a release (`v0.1.0-MVP`, 2026-09-19), and a second
   (`0.1.1-MVP`) was recorded but never tagged. A release now has a version, a record and
   a verification table ([docs/releases/](../releases/README.md)). It has no artifact.
   Each release record lists image names, and no registry holds them. An operator has to
   build all six images from the flake to run a tagged version.
2. The deployment target is a k3s cluster (plan 20). A single-node `k3s ctr images
   import` does not scale to a node that the scheduler picks, and the manifests in
   `deploy/k3s/` name `localhost/` images that exist only on the machine that built them.

[Plan 16](../plans/16-project-rename.md) parks the `victual` and `victualer` registry
names until the rename is announced. It also notes that the image "lives under the
maintainer's own namespace either way", so publishing under `datagen24` claims no
parked name.

The existing `nix` workflow already builds the images on Linux, boots them read-only
against PostgreSQL, and walks every page and the API. It runs on x86_64 only. Nothing
has built or booted the aarch64 images, although `flake.nix` declares `aarch64-linux`.
GitHub provides `ubuntu-24.04-arm` runners to public repositories at no cost.

## Decision

1. **A `v*` tag publishes the release.** `.github/workflows/release.yml` runs on the tag
   push. The maintainer places the tag, signed, from the workstation, as
   [docs/releases/README.md](../releases/README.md) already describes. CI never creates
   a tag.
2. **The workflow refuses a tag that it cannot match to a release.** The tag must equal
   `v` plus `version.json`'s `Version`. `docs/releases/<Version>.md` must exist and be
   listed in the releases index. The tagged commit must be on `master`.
3. **The published images are the tested images.** The release workflow calls the
   `nix` workflow once per architecture. That workflow runs `nix flake check`, builds the
   images, runs the boot test and the page walk, and writes the six image streams as an
   artifact only after the walk passes. The publish job pushes those streams. It does not
   rebuild them.
4. **Two architectures: `linux/amd64` on `ubuntu-latest` and `linux/arm64` on
   `ubuntu-24.04-arm`.** Each is built natively, with no emulation. Each architecture is
   pushed as `<image>:<version>-<arch>`, then both are joined into one
   multi-architecture tag, `<image>:<version>`.
5. **The registry is GHCR, under the repository owner:**
   `ghcr.io/datagen24/victual-{app,web,migrate,label-renderer,label-worker,mcp}`. Pushes
   use the workflow's `GITHUB_TOKEN`. The job that holds `packages: write` builds
   nothing, and the build jobs hold only `contents: read`.
6. **The GitHub release lists each image by digest** and links the release record. The
   record stays the authority on what was verified; the release body is a pointer
   to it.
7. **No `latest` tag.** A deployment names a version. A moving tag would let a pod
   restart onto a release that nobody chose.

## Consequences

- An operator can run a tagged release without Nix. The `deploy/k3s/` manifests can
  name `ghcr.io/datagen24/…:<version>` and a node pulls it. The podman bootstrap and the
  `deploy/kind/` harness keep loading local builds; the kind overlay maps the GHCR names
  to `localhost/` ones.
- A tag costs two full builds. PHP is compiled from source on each architecture
  (nix/php.nix overrides the derivation), so the first release build on a runner is
  measured in tens of minutes. Nothing is cached between runs; adding a binary cache is
  a separate decision.
- The aarch64 build is exercised for the first time. The workflow also runs its two
  builds, and does not publish, on any pull request that changes it. That run is how the
  arm64 half is proven before a tag depends on it.
- **GHCR creates a new package as private.** GitHub's documentation states that the
  default visibility of a first publish is private, even from a public repository. The
  first release therefore needs a one-time step by the maintainer: set each of the six
  packages to public. The alternative is an `imagePullSecret` in every namespace that
  pulls them. The workflow cannot change this setting with `GITHUB_TOKEN`.
- Image signing and build provenance attestations are not part of this decision.
  The images are reproducible from the tag and the flake lock. A signature
  would let a node verify the images without that rebuild. That is a separate
  record if it is wanted.
- Rejected: pushing from inside the `nix` workflow. The workflow runs on every pull
  request that touches the flake, and its jobs would then hold `packages: write` on each
  of those runs.
- Rejected: attaching the image tarballs to the GitHub release instead of pushing them.
  It avoids a registry, but every node would still need an import step, and the k3s
  manifests could not name a pullable image.

## Acceptance prerequisites

1. A pull request that changes the release workflow ran both architecture builds to
   completion, including the boot test and the walk on `ubuntu-24.04-arm`.
2. The first tag run published all six images for both architectures, and
   `docker buildx imagetools inspect` on each `<image>:<version>` lists `linux/amd64` and
   `linux/arm64`.
3. A k3s node pulled the published `victual-app` image by its versioned tag, without an
   image pull secret, after the packages were made public.
