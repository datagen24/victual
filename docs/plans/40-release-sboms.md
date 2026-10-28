# 40. Software bills of materials for each release

Publish a software bill of materials (SBOM) for every artifact a release ships, generated
in GitHub Actions, bound to the artifact's digest by a signed attestation, and verifiable
by a consumer with `gh attestation verify`.

Status: draft, research only, 2026-10-28. The maintainer asked for each release to publish
an SBOM from GitHub. No workflow is changed by this document, and no format, generator or
signing decision is made by it. Signing and provenance need a new ADR (see
[Dependencies](#dependencies)).

## Problem and outcome

A release today publishes six multi-architecture images and a Helm chart, listed by digest
in the GitHub release ([ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md),
Proposed). Nothing states what is inside those images. An operator who wants to know
whether `ghcr.io/datagen24/victual-app:0.5.5` contains a vulnerable `openssl`, `curl` or
Composer package must pull the image and scan it with their own tools, and has no way to
check that a scan result describes the bytes the project built.

Success means:

- Each published image digest, per architecture, has an SBOM attestation signed by the
  `release` workflow, stored in GitHub's attestation store and in GHCR beside the image.
- The SBOM lists the Nix runtime closure (PHP, nginx, Node.js, glibc, OpenSSL, the C
  libraries), the Composer, npm and Cargo packages inside the image, and the versions of
  each.
- A consumer verifies an image and its SBOM with one documented command and no
  credentials.
- The GitHub release body names how to fetch and verify the SBOMs.

## What a release ships

Read from `.github/workflows/release.yml`, `nix/images/` and `flake.nix` at `c04a2505`.

| Artifact | Built by | Package sources inside it | Published as |
|---|---|---|---|
| `victual-app` | `nix/images/app.nix` | Nix closure (PHP 8.5 with extensions, libpq, curl, OpenSSL, image libraries); Composer `--no-dev` vendor tree | GHCR, per-arch tags joined into one manifest list |
| `victual-web` | `nix/images/web.nix` | Nix closure (nginx); frontend npm packages from `yarn.lock` in the webroot | GHCR |
| `victual-migrate` | `nix/images/migrate.nix` | As `app`, plus `pdo_sqlite` | GHCR |
| `victual-label-renderer` | `nix/label-renderer.nix`, source pinned in `flake.lock` | Nix closure; Cargo crates from the `victual-label-renderer` repository | GHCR |
| `victual-label-worker` | `nix/label-worker.nix`, source pinned in `flake.lock` | Nix closure; Cargo crates from the `victual-label-worker` repository | GHCR |
| `victual-mcp` | `nix/mcp.nix` (`buildNpmPackage`) | Nix closure (Node.js 24 and its libraries); npm packages from `mcp/package-lock.json` | GHCR |
| Helm chart | `helm package deploy/helm/victual` | Templates and values only; `Chart.yaml` declares no `dependencies` | `oci://ghcr.io/datagen24/charts/victual:<version>` |
| GitHub release | `gh release create` | Release notes with the digests; no assets | GitHub Releases |

Each image is built natively on `ubuntu-latest` (amd64) and `ubuntu-24.04-arm` (arm64) by
`nix.yml`, uploaded as a tar artifact, pushed as `<image>:<version>-<arch>`, then joined
with `docker buildx imagetools create`. The joined tag is a Docker manifest list
(`application/vnd.docker.distribution.manifest.list.v2+json`), not an OCI image index; the
GitHub release records only the manifest list digest.

The chart contains no third-party code, so it has no meaningful SBOM. It needs a
provenance attestation on its `.tgz` digest, not an SBOM.

## Current behavior

No workflow generates, attests or publishes an SBOM, and no `cosign`, `attest` or SLSA
step exists. ADR-0030's Consequences states that image signing and build provenance "are
not part of this decision" and would be "a separate record if it is wanted." The latest
release, `v0.5.5`, has `immutable: false` and no assets.

The repository is public and Dependabot alerts are enabled
(`GET /repos/datagen24/victual/vulnerability-alerts` returned 204 on 2026-10-28). There is
no `.github/dependabot.yml`; Dependabot's pull requests come from security updates, and the
maintainer milestones each one to the next release.

## Measurements

All measured 2026-10-28 against published `0.5.5` images, from the workstation, without
starting a container. Syft 1.54.0 and Trivy 0.76.0 came from nixpkgs (`nix shell
nixpkgs#syft nixpkgs#trivy`) and read the images straight from the registry.

### GitHub's dependency-graph SBOM

```bash
gh api repos/datagen24/victual/dependency-graph/sbom
```

Returns SPDX 2.3 JSON with 287 packages: 181 `pkg:npm`, 93 `pkg:composer`, 10
`pkg:githubactions`, 2 `pkg:pypi`, and the root `pkg:github/datagen24/victual@master`.
It has no Nix packages and no Cargo crates. The dependency graph read 19 manifests,
including `.devtools/spikes/oauth-hydra/package-lock.json`,
`.devtools/parity/harness/package.json`, `.devtools/frontend/package-lock.json`,
`.devtools/docs/requirements.txt` and the workflow files.

This SBOM describes the repository's default branch, not a release:

- It is generated from `master` at request time. A workflow calling it on a tag gets
  whatever `master` holds then, and under [ADR-0047](../adr/0047-one-trunk-release-branches-and-promoted-images.md)
  (Proposed) the tag would be on a release branch that the dependency graph does not read.
- It includes development and spike dependencies that no image contains. Composer alone
  shows 93 packages against 66 in `composer.lock`'s `packages` (27 are `packages-dev`).
- It omits everything Nix supplies: PHP, nginx, Node.js, OpenSSL, glibc and the rest of
  each runtime closure, and the Rust crates of the two label images.

The synchronous endpoint is being withdrawn. GitHub's REST documentation states that it
"will not be accessible after November 13, 2026" and points to an asynchronous pair,
`GET …/dependency-graph/sbom/generate-report` and `GET …/sbom/fetch-report/{sbom_uuid}`,
whose report is kept for up to a week
([REST API: SBOMs](https://docs.github.com/en/rest/dependency-graph/sboms)).

### Syft against the Nix images

```bash
syft scan registry:ghcr.io/datagen24/victual-<image>:0.5.5 --platform linux/amd64 -o syft-json=<file>
```

| Image (amd64) | Packages | By cataloger | Without a license |
|---|---|---|---|
| `app` | 160 | 72 nix, 66 composer, 17 PHP extension binaries, 5 PEAR | 90 |
| `migrate` | 164 | 75 nix, 66 composer, 18 binaries, 5 PEAR | 94 |
| `web` | 73 | 58 npm, 14 nix, 1 binary | 15 |
| `mcp` | 37 | 29 nix, 7 npm, 1 binary (`node@24.21.0`) | 31 |
| `label-renderer` | 120 | 112 Cargo crates, 8 nix | 120 |
| `label-worker` | 70 | 62 Cargo crates, 8 nix | 70 |

What Syft gets right on these images:

- **The Nix closure.** Syft's `nix-cataloger` lists every store path by name and version,
  for example `openssl@3.5.9`, `curl@8.22.0`, `libpq@18.6`, `glibc@2.44-57`,
  `php@8.5.12`, with a purl such as `pkg:nix/openssl@3.5.9?outputhash=<hash>`.
- **Composer.** The 66 `pkg:composer` packages match `composer.lock`'s 66 production
  packages exactly. Syft reads `share/php/victual/packages/composer/installed.json` in the
  application's store path.
- **npm.** The `mcp` image's 6 dependencies plus the package itself match the production
  entries of `mcp/package-lock.json`.
- **Rust.** nixpkgs' `rustPlatform` builds with `cargo-auditable`, which embeds the crate
  list in the binary; Syft's `cargo-auditable-binary-cataloger` reads it.

What Syft misses on these images:

- **Dependency edges between store paths.** The 72 Nix packages in `app` have no
  `dependency-of` relationships among themselves; every edge is package-to-file. Syft's
  documentation says its current Nix cataloger reads `nix/var/nix/db/db.sqlite`
  ([Anchore: Nix capabilities](https://oss.anchore.com/docs/capabilities/nix/)), which a
  `streamLayeredImage` image does not contain.
- **Licenses.** 90 of 160 `app` packages, and every Cargo crate, carry no license.
- **Output names.** Two `gcc@16.2.0` entries are the `-lib` and `-libgcc` outputs; the
  output suffix is dropped from the name.

The two architectures differ. The arm64 `app` image has 162 packages; it adds
`libxcrypt@4.5.2`, and every Nix purl's `outputhash` differs. One SBOM per architecture
is required.

### Trivy against the same image

```bash
trivy image --platform linux/amd64 --format cyclonedx ghcr.io/datagen24/victual-app:0.5.5
```

Trivy found the 66 Composer packages and nothing else: no OS, no Nix packages, no PHP,
OpenSSL or curl. It does not recognise a Nix store as a package source. It is not
suitable as the SBOM source for these images.

### GHCR and the OCI referrers API

```bash
curl -H "Authorization: Bearer $anonymous_token" \
  https://ghcr.io/v2/datagen24/victual-app/referrers/sha256:ca4ccc57…
```

Returned `404 MANIFEST_UNKNOWN` for the amd64 manifest of `victual-app:0.5.5`. GHCR does
not serve the OCI 1.1 referrers endpoint for this repository, so an attestation pushed to
GHCR is stored under the referrers tag scheme (a `sha256-<digest>` tag) that the
[Sigstore bundle specification](https://github.com/sigstore/cosign/blob/main/specs/BUNDLE_SPEC.md)
describes as the fallback. Which tag `actions/attest` writes in that case was not
measured.

### Size

The largest Syft JSON was 645 kB (`app`); its CycloneDX form was 164 kB. `actions/attest`
refuses an SBOM over 16 MB, so the limit is not close.

## Options

### GitHub-native

| Mechanism | Covers | Misses |
|---|---|---|
| Dependency graph export (`/dependency-graph/sbom`) | Manifests GitHub parses on the default branch: Composer, npm, PyPI, Actions | Nix, Cargo in other repositories, release refs; includes dev and spike dependencies |
| `actions/attest` with `sbom-path` | Signs any SPDX or CycloneDX JSON file against a subject digest; stores the attestation with GitHub and, with `push-to-registry: true`, in the registry | Generates nothing; the SBOM's content is whatever the generator produced |
| `actions/attest-sbom` | The same, through the older interface | Deprecated "in favor of `actions/attest`" ([actions/attest-sbom](https://github.com/actions/attest-sbom)) |
| Build provenance (`actions/attest` without `sbom-path`, or `actions/attest-build-provenance`) | SLSA v1 provenance: which workflow, commit and runner built a digest | Not an SBOM |
| Immutable releases | A GitHub-signed release attestation over the release's assets, checked by `gh release verify` and `gh release verify-asset` ([GitHub docs](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/secure-your-dependencies/verify-release-integrity)) | Covers release assets only; the images are not release assets |

`actions/attest` needs `id-token: write`, `attestations: write` and, for registry storage,
`packages: write` and `artifact-metadata: write`. For a public repository it signs with
the public-good Sigstore instance, so the signature is also recorded in Rekor. It accepts
at most 1024 subjects and an SBOM up to 16 MB
([actions/attest](https://github.com/actions/attest)).

A consumer verifies with:

```bash
gh attestation verify oci://ghcr.io/datagen24/victual-app@sha256:<digest> \
  -R datagen24/victual \
  --predicate-type https://spdx.dev/Document/v2.3 \
  --signer-workflow datagen24/victual/.github/workflows/release.yml
```

The default `--predicate-type` is SLSA provenance (`https://slsa.dev/provenance/v1`), so
an SBOM check must name the SBOM predicate. `--bundle-from-oci` reads the attestation from
the registry instead of GitHub's API
([gh attestation verify](https://cli.github.com/manual/gh_attestation_verify)).

### Nix-aware generators

| Tool | Release (checked 2026-10-28) | Output | Input | Notes |
|---|---|---|---|---|
| [sbomnix](https://github.com/tiiuae/sbomnix) (TII, Ghaf) | v1.9.0, 2026-10-06; 1.9.0 in nixpkgs | CycloneDX JSON, SPDX 2.3 JSON, CSV | Flake reference (recommended) or store path | Runtime closure by default, which it builds; `--buildtime` reads the derivation graph without building. Adds nixpkgs `meta` (license, description) for flake references, not for bare store paths. Companions: `vulnxscan`, `provenance` (SLSA v1) |
| [bombon](https://github.com/nikstur/bombon) | 0.4.0, 2025-10-09; commits to 2026-10-16 | CycloneDX 1.7 | A Nix function, `buildBom <drv> { }`, in the flake | Reads SBOMs of vendored ecosystems through passthru helpers for Cargo, npm (`buildNpmPackage`), pnpm and Go. `extraPaths` for references an image drops. Targets BSI TR-03183 |
| [genealogos](https://github.com/tweag/genealogos) (Tweag) | v1.1.1, 2026-09-09 | CycloneDX 1.3–1.5 | Flake reference, or a nixtract trace | Restarts Nix per component, which its README says can be "very slow"; misses dependencies held only in string contexts |

None of the three was run for this plan. Each needs the Linux store paths, which exist on
the CI runners and on the podman builder, not on the macOS host. Generating from the
flake reference is what gives store-path-to-store-path dependency edges and nixpkgs
license metadata, the two things Syft lacks on these images.

The Composer vendor tree and the frontend's npm packages are fixed-output derivations, not
one derivation per package, so a Nix-only generator lists them as one opaque path. bombon's
vendored-SBOM helpers cover npm and Cargo, not Composer. sbomnix has no equivalent. Either
tool's output therefore needs the Composer and frontend packages added from another
source, and Syft already finds them.

### Vulnerability matching

The SBOM is only as useful as what can match it against advisories.

- **Composer, npm, Cargo** purls match the GitHub Advisory Database and OSV. Dependabot
  already covers Composer and npm through the dependency graph.
- **`pkg:nix`** has no advisory feed. Anchore's documentation lists NVD as Grype's only
  source for Nix packages, which means CPE matching by name and version, with false
  positives. A NixOS Discourse thread reports zero Grype matches on a Syft Nix SBOM
  ([discussion](https://discourse.nixos.org/t/how-to-do-vulnerability-scanning-with-nix-sboms/66161)).
  Nix-specific scanners are sbomnix's `vulnxscan` (which combines Grype, OSV and vulnix)
  and the [Nixpkgs security tracker](https://tracker.security.nixos.org/).

A vulnerability in the Nix closure is fixed by a `flake.lock` bump of `nixpkgs`. Nothing
schedules that bump today, and Dependabot does not update flake inputs.

## Recommended pipeline

Recommendation, not decision. The format and the signing record are open questions.

1. **Generator per artifact.**
   - Images: Syft, run against the image tar `nix.yml` already writes, per architecture.
     It is the only measured tool that finds the Nix closure, Composer, npm and Cargo in
     one pass, and it reads the tested bytes.
   - Optionally, sbomnix on the image's flake output on the same runner, attached as a
     second SBOM for the dependency graph and licenses. Whether two SBOMs per image is
     worth it is open question 2.
   - Chart: no SBOM; build provenance on the `.tgz` digest.
2. **One SBOM per image per architecture**, twelve in all, attested to the per-architecture
   manifest digest, which is the digest a node pulls and the digest Syft describes. Build
   provenance is attested to the per-architecture digests and to the manifest list digest.
3. **Format:** SPDX 2.3 JSON, from Syft's `spdx-json` output, unless a consumer needs
   CycloneDX (open question 1). Syft writes both from one scan, so the choice is cheap to
   change.
4. **Attestation:** `actions/attest@v4` with `subject-name`, `subject-digest`, `sbom-path`
   and `push-to-registry: true`, in the `publish` job, which is where the digests first
   exist. Build provenance from the same action without `sbom-path`.
5. **Publication:**
   - The attestation in GitHub's store and in GHCR beside each image.
   - The SBOM files as GitHub release assets, so a reader without `gh` can download them.
   - A verification command in the release body.
6. **Repository SBOM:** not published. It describes `master`, not the release, and lists
   tooling that does not ship. If a source-level SBOM is wanted, it is generated from the
   tagged tree's `composer.lock`, `yarn.lock` and `mcp/package-lock.json` by Syft's
   directory scan, which also respects the tag.

### Workflow sketch

Illustrative only; no workflow is changed by this plan.

In `nix.yml`, after the walk and the image streams are written, on each architecture:

```yaml
- name: SBOM for each image stream
  if: inputs.artifact-name != ''
  run: |
    for tar in "$RUNNER_TEMP"/images/*.tar; do
      c=$(basename "$tar" .tar)
      syft scan "docker-archive:$tar" -o "spdx-json=$RUNNER_TEMP/images/$c.spdx.json"
    done
```

The SBOMs ride in the same artifact as the tars. Syft is installed by a pinned checksum, as
the `publish` job installs Helm.

In `release.yml`'s `publish` job, after each `docker push "$remote_ref"`, capture the
per-architecture digest, then attest. The job gains `id-token: write`,
`attestations: write` and `artifact-metadata: write`:

```yaml
- uses: actions/attest@v4
  with:
    subject-name: ghcr.io/datagen24/victual-app
    subject-digest: ${{ steps.push.outputs.app-amd64 }}
    sbom-path: images/images-amd64/app.spdx.json
    push-to-registry: true
```

That is twelve steps for SBOMs and twelve or more for provenance, so the job likely loops
in a matrix or a small script over `subject-checksums`. `push-to-registry` requires a
single image reference per call, so the registry half cannot be batched.

In the `release` job, upload the twelve SBOMs as assets and add a verification command
to `notes.md`.

### Fit with the release model

Under [ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md) (Proposed) the tag
builds, tests and publishes, so SBOM generation sits in `nix.yml` and attestation in
`publish`, as sketched.

Under [ADR-0047](../adr/0047-one-trunk-release-branches-and-promoted-images.md)
(Proposed), QA builds and pushes `qa-<sha>` images and the tag only re-tags those digests.
Attestations are bound to digests, not tags, so attesting in QA covers the promoted release
with no further step. The release job then copies the SBOMs to the release and verifies
the attestations rather than creating them. The pipeline above works under either record.

### Fit with the dependency vulnerability policy

The maintainer's policy is that a newly disclosed library vulnerability is fixed in the
next release, and Dependabot pull requests are milestoned to it. SBOMs support that policy
in two ways and leave one gap:

- A published SBOM lets an operator check a running release against a new advisory
  without waiting for the next one.
- Comparing the next release's SBOM with the previous one shows that the milestoned
  Dependabot fixes are present in the image, not only in the lockfile.
- The gap is the Nix closure. Dependabot does not open pull requests for it, so a CVE in
  OpenSSL, curl or PHP reaches a release only when someone bumps `nixpkgs`. Options:
  a scheduled `nix flake update` pull request milestoned like Dependabot's; a nightly
  `vulnxscan` or Grype run over the latest release's SBOMs that opens an issue; or
  accepting the gap until a release bumps the lock for another reason.

## Dependencies

- A new ADR for signing and provenance. ADR-0030 left it to "a separate record." The
  record would decide what is attested (SBOM, provenance, both), where (GitHub, GHCR,
  both), with which identity, and what a consumer is told to verify. This plan is input to
  it.
- ADR-0030 and ADR-0047 are both Proposed. The pipeline does not depend on which is
  accepted, but the workflow sketch follows whichever is.
- GHCR packages are public; anonymous `gh attestation verify --bundle-from-oci` depends on
  that, which the release guide already requires.

## Open questions

1. **SPDX or CycloneDX?** SPDX 2.3 is what GitHub's export and `gh attestation verify`
   examples use; SPDX 2.2.1 is published as ISO/IEC 5962:2021. CycloneDX 1.6/1.7 is bombon's
   only output and what OWASP Dependency-Track ingests. The answer depends on who consumes
   the SBOM: the maintainer's own scanner, a Dependency-Track instance, or anonymous
   operators. Publishing both from one Syft scan costs one more file per image.
2. **Syft only, or Syft plus a Nix-aware generator?** Syft's output has no Nix dependency
   edges and leaves most Nix packages without a license. sbomnix or bombon fixes both but
   misses Composer, so it would be a second SBOM or a merge step. Needed only if license
   compliance or closure-level graphs matter to a consumer.
3. **Turn on immutable releases?** It would give the release a GitHub-signed attestation
   over its SBOM assets. `release.yml` edits an existing release with `gh release edit` on
   a re-run; whether that still works on an immutable release was not tested.
4. **Attest the label repositories' sources?** `victual-label-renderer` and
   `victual-label-worker` are pinned by revision in `flake.lock`. The image SBOM lists their
   crates through `cargo-auditable`; provenance for the source revision is the other
   repositories' concern unless the ADR says otherwise.
5. **Who watches the Nix closure for advisories?** See the policy gap above. Each option
   is a different amount of recurring work.
6. **Keep the dependency graph's repository SBOM at all?** It is free to export but does
   not describe a release. Recommendation is not to publish it; the maintainer may want it
   for another reason.

## Verification

- For each of the six images and both architectures, `gh attestation verify
  oci://ghcr.io/datagen24/victual-<image>@<arch digest> -R datagen24/victual
  --predicate-type <SBOM predicate>` succeeds without credentials, and fails for a digest
  the workflow did not build.
- The same command with `--bundle-from-oci` succeeds, showing the GHCR copy is readable.
- The `app` SBOM lists the 66 production Composer packages from that tag's
  `composer.lock`, and `openssl`, `curl` and `php` at the versions the flake lock pins.
- The `mcp` SBOM lists every production package in `mcp/package-lock.json`; the label
  images list their Cargo crates.
- The amd64 and arm64 SBOMs of one image differ in Nix output hashes, confirming they were
  generated from the per-architecture bytes.
- The release body's verification command, copied verbatim, succeeds.
- A pull request that changes `release.yml` builds the SBOMs and does not attest or push
  anything, matching the workflow's existing rule that nothing publishes from a pull
  request.
