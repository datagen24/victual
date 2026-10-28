# 40. Software bills of materials for each release

Publish software bills of materials (SBOMs) for every image a release ships. Generate
them in GitHub Actions, bind each one to the image's digest with a signed attestation,
attach them to an immutable GitHub release, and make them verifiable with
`gh attestation verify`.

Status: draft, research only, 2026-10-28. The maintainer answered the six open questions
on 2026-10-28. The answers are recorded under [Open questions](#open-questions) and
reconciled into the design below. Question 5's answer is a proposal awaiting the
maintainer's confirmation. No workflow is changed by this document. Signing and provenance
still need a new ADR (see [Dependencies](#dependencies)).

## Problem and outcome

A release today publishes six multi-architecture images and a Helm chart. The GitHub
release lists them by digest
([ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md), Proposed). Nothing
states what is inside those images. An operator who wants to know whether
`ghcr.io/datagen24/victual-app:0.5.5` contains a vulnerable `openssl`, `curl` or Composer
package must pull the image and scan it with their own tools. They also have no way to
check that a scan result describes the bytes the project built.

Success means:

- Each published image digest, per architecture, has two CycloneDX SBOM attestations
  signed by the `release` workflow. Both are stored in GitHub's attestation store and in
  GHCR beside the image.
  - The image SBOM lists everything found in the image bytes: the Nix runtime closure and
    the Composer, npm and Cargo packages.
  - The closure SBOM lists the Nix closure from the flake, with dependency edges and
    nixpkgs licenses.
- The GitHub release is immutable and carries every SBOM as an asset.
- A consumer verifies an image and its SBOMs with one documented command and no
  credentials.
- Advisories against the Nix closure reach the Security tab and the next release's
  milestone, the same way Dependabot's do for Composer and npm.
- The two label repositories publish their own attested SBOMs. Victual's `flake.lock`
  pins point at those released revisions.

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

Each image is built natively by `nix.yml`: amd64 on `ubuntu-latest`, arm64 on
`ubuntu-24.04-arm`. Each build is uploaded as a tar artifact and pushed as
`<image>:<version>-<arch>`. The two are then joined with `docker buildx imagetools
create`. The joined tag is a Docker manifest list
(`application/vnd.docker.distribution.manifest.list.v2+json`), not an OCI image index. The
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
no `.github/dependabot.yml`. Dependabot's pull requests come from security updates, and the
maintainer milestones each one to the next release.

`victual-label-renderer` and `victual-label-worker` are public and have no releases and no
workflows (`gh release list` and the `.github/workflows` contents API, 2026-10-28).
`flake.lock` pins them to commits of their default branches: `13586c82` and `6fbf5ac8`.

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
including the following, none of which ships in an image:

- `.devtools/spikes/oauth-hydra/package-lock.json`
- `.devtools/parity/harness/package.json`
- `.devtools/frontend/package-lock.json`
- `.devtools/docs/requirements.txt`
- the workflow files

This SBOM describes the repository's default branch, not a release:

- It is generated from `master` at request time. A workflow that calls it on a tag gets
  whatever `master` holds at that moment. Under
  [ADR-0047](../adr/0047-one-trunk-release-branches-and-promoted-images.md) (Proposed),
  the tag would be on a release branch, which the dependency graph does not read.
- It includes development and spike dependencies that no image contains. Composer alone
  shows 93 packages against 66 in `composer.lock`'s `packages` (27 are `packages-dev`).
- It omits everything Nix supplies: PHP, nginx, Node.js, OpenSSL, glibc and the rest of
  each runtime closure, and the Rust crates of the two label images.

The synchronous endpoint is being withdrawn. GitHub's REST documentation states that it
"will not be accessible after November 13, 2026." It points to an asynchronous pair,
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
  for example `openssl@3.5.9`, `curl@8.22.0`, `libpq@18.6`, `glibc@2.44-57` and
  `php@8.5.12`. Each has a purl such as `pkg:nix/openssl@3.5.9?outputhash=<hash>`.
- **Composer.** The 66 `pkg:composer` packages match `composer.lock`'s 66 production
  packages exactly. Syft reads `share/php/victual/packages/composer/installed.json` in the
  application's store path.
- **npm.** The `mcp` image's 6 dependencies plus the package itself match the production
  entries of `mcp/package-lock.json`.
- **Rust.** nixpkgs' `rustPlatform` builds with `cargo-auditable`, which embeds the crate
  list in the binary. Syft's `cargo-auditable-binary-cataloger` reads it.

What Syft misses on these images:

- **Dependency edges between store paths.** The 72 Nix packages in `app` have no
  `dependency-of` relationships among themselves; every edge is package-to-file. Syft's
  documentation says its current Nix cataloger reads `nix/var/nix/db/db.sqlite`
  ([Anchore: Nix capabilities](https://oss.anchore.com/docs/capabilities/nix/)). A
  `streamLayeredImage` image does not contain that file.
- **Licenses.** 90 of 160 `app` packages, and every Cargo crate, carry no license.
- **Output names.** Two `gcc@16.2.0` entries are the `-lib` and `-libgcc` outputs; the
  output suffix is dropped from the name.

The two architectures differ. The arm64 `app` image has 162 packages: it adds
`libxcrypt@4.5.2`, and every Nix purl's `outputhash` differs. One SBOM per architecture
is required.

Syft 1.54 writes CycloneDX 1.7 by default (`specVersion` in its `cyclonedx-json` output),
with a random `serialNumber`.

### Trivy against the same image

```bash
trivy image --platform linux/amd64 --format cyclonedx ghcr.io/datagen24/victual-app:0.5.5
```

Trivy found the 66 Composer packages and nothing else: no OS packages, no Nix packages,
and no PHP, OpenSSL or curl. It does not recognise a Nix store as a package source. It is
not suitable as an SBOM source for these images.

### GHCR and the OCI referrers API

```bash
curl -H "Authorization: Bearer $anonymous_token" \
  https://ghcr.io/v2/datagen24/victual-app/referrers/sha256:ca4ccc57…
```

Returned `404 MANIFEST_UNKNOWN` for the amd64 manifest of `victual-app:0.5.5`. GHCR does
not serve the OCI 1.1 referrers endpoint for this repository. An attestation pushed to
GHCR is therefore stored under the referrers tag scheme (a `sha256-<digest>` tag), which
the
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
| `actions/attest` with `sbom-path` | Signs an SPDX or CycloneDX JSON file against a subject digest. Stores the attestation with GitHub and, with `push-to-registry: true`, in the registry | Generates nothing; the SBOM's content is whatever the generator produced |
| `actions/attest-sbom` | The same, through the older interface | Deprecated "in favor of `actions/attest`" ([actions/attest-sbom](https://github.com/actions/attest-sbom)) |
| Build provenance (`actions/attest` without `sbom-path`, or `actions/attest-build-provenance`) | SLSA v1 provenance: which workflow, commit and runner built a digest | Not an SBOM |
| Immutable releases | A GitHub-signed release attestation over the release's tag, commit and assets. Checked by `gh release verify` and `gh release verify-asset` ([GitHub docs](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/secure-your-dependencies/verify-release-integrity)) | Covers release assets only; the images are not release assets |

`actions/attest` needs `id-token: write` and `attestations: write`. Registry storage also
needs `packages: write` and `artifact-metadata: write`. For a public repository it signs
with the public-good Sigstore instance, so the signature is also recorded in Rekor. It
accepts at most 1024 subjects and an SBOM up to 16 MB
([actions/attest](https://github.com/actions/attest)).

`actions/attest` accepts CycloneDX JSON when the document has `bomFormat`, `serialNumber`
and `specVersion`. It sets the in-toto predicate type to `https://cyclonedx.org/bom` for
every CycloneDX version (`src/sbom.ts` in `actions/attest`, read 2026-10-28). SPDX
predicates carry the version (`https://spdx.dev/Document/v2.3`); CycloneDX predicates do
not. Neither the action nor `gh attestation verify` requires SPDX.

A consumer verifies with:

```bash
gh attestation verify oci://ghcr.io/datagen24/victual-app@sha256:<digest> \
  -R datagen24/victual \
  --predicate-type https://cyclonedx.org/bom \
  --signer-workflow datagen24/victual/.github/workflows/release.yml
```

The default `--predicate-type` is SLSA provenance (`https://slsa.dev/provenance/v1`), so
an SBOM check must name the SBOM predicate. `--bundle-from-oci` reads the attestation from
the registry instead of GitHub's API
([gh attestation verify](https://cli.github.com/manual/gh_attestation_verify)).

### Nix-aware generators

| Tool | Release (checked 2026-10-28) | Output | Input | Notes |
|---|---|---|---|---|
| [sbomnix](https://github.com/tiiuae/sbomnix) (TII, Ghaf) | v1.9.0, 2026-10-06; 1.9.0 in nixpkgs | CycloneDX 1.4 JSON (`specVersion` is set to `"1.4"` in `src/sbomnix/exporters.py`), SPDX 2.3 JSON, CSV | Flake reference (recommended) or store path | Runtime closure by default, which it builds; `--buildtime` reads the derivation graph without building. Adds nixpkgs `meta` (license, description) for flake references, not for bare store paths. Companions: `vulnxscan`, `provenance` (SLSA v1) |
| [bombon](https://github.com/nikstur/bombon) | 0.4.0, 2025-10-09; commits to 2026-10-16 | CycloneDX 1.7, with a `serialNumber` derived from the output path, so the same build gives the same SBOM | A Nix function, `buildBom <drv> { }`, in the flake | Reads SBOMs of vendored ecosystems through passthru helpers: Cargo (`cargo-cyclonedx`), npm (`npm sbom` for `buildNpmPackage`), pnpm and Go. `extraPaths` adds store paths an image drops. Targets BSI TR-03183 |
| [genealogos](https://github.com/tweag/genealogos) (Tweag) | v1.1.1, 2026-09-09 | CycloneDX 1.3–1.5 | Flake reference, or a nixtract trace | Restarts Nix per component, which its README says can be "very slow"; misses dependencies held only in string contexts |

None of the three was run for this plan. Each needs the Linux store paths, which exist on
the CI runners and on the podman builder, not on the macOS host. Generating from the
flake gives two things Syft lacks on these images: store-path-to-store-path dependency
edges and nixpkgs license metadata.

The Composer vendor tree and the frontend's yarn packages are fixed-output derivations,
not one derivation per package. A Nix-only generator lists each as one opaque path.
bombon's vendored-SBOM helpers cover npm through `buildNpmPackage` and Cargo, but not
Composer or yarn. sbomnix has no equivalent. Syft finds both from the image bytes.

### Vulnerability matching

The SBOM is only as useful as what can match it against advisories.

- **Composer, npm, Cargo** purls match the GitHub Advisory Database and OSV. Dependabot
  already covers Composer and npm through the dependency graph.
- **`pkg:nix`** has no advisory feed. Anchore's documentation lists NVD as Grype's only
  source for Nix packages, which means CPE matching by name and version, with false
  positives. A NixOS Discourse thread reports zero Grype matches on a Syft Nix SBOM
  ([discussion](https://discourse.nixos.org/t/how-to-do-vulnerability-scanning-with-nix-sboms/66161)).
- OSV has no Nix ecosystem. sbomnix's `vulnxscan` therefore queries OSV by name and
  version across all ecosystems, which its documentation says "will include false
  positives" ([vulnxscan](https://github.com/tiiuae/sbomnix/blob/main/doc/vulnxscan.md)).
- `vulnxscan` combines Grype, its own OSV client and vulnix. Vulnix's name heuristic is
  noisier, but it is the one scanner that recognises CVE patches nixpkgs applied (by patch
  filename). `vulnxscan` drops a finding when every matching derivation carries such a
  patch.
- `vulnxscan` writes SARIF 2.1.0 (`--format sarif`) and reads a whitelist CSV for
  analysed findings.
- The [Nixpkgs security tracker](https://tracker.security.nixos.org/) matches CVEs to
  nixpkgs derivations.

A vulnerability in the Nix closure is fixed by a `flake.lock` bump of `nixpkgs`. Nothing
schedules that bump today, and Dependabot does not update flake inputs.

## Design

This design follows the maintainer's answers to the open questions. Question 5's
monitoring design is a proposal awaiting confirmation.

### Two CycloneDX 1.7 SBOMs per image and architecture

The published format is CycloneDX 1.7 JSON (question 1). Syft 1.54 emits it by default and
bombon emits nothing else, so it is the one version both generators produce. The workflow
names the version explicitly (`cyclonedx-json@1.7`), so a Syft upgrade cannot change it
silently. No SPDX document is published: neither `actions/attest` nor
`gh attestation verify` requires one.

Each image gets two SBOMs on each architecture (question 2). With six images and two
architectures, that is twenty-four documents per release.

| SBOM | Generator | Describes | Contains | File name |
|---|---|---|---|---|
| Image SBOM | Syft, over the tested image tar | The bytes pushed to GHCR | Nix store paths, Composer, npm (frontend and MCP), Cargo (through `cargo-auditable`), PHP extensions | `victual-<image>-<version>-<arch>.image.cdx.json` |
| Closure SBOM | bombon, as a flake output | The Nix runtime closure the flake builds | Nix packages with dependency edges and nixpkgs licenses; MCP npm and label Cargo crates through bombon's vendored helpers | `victual-<image>-<version>-<arch>.closure.cdx.json` |

bombon is chosen over sbomnix for four reasons:

- **Format.** It emits CycloneDX 1.7, the same version as Syft. sbomnix emits CycloneDX
  1.4, so the release would carry two versions or need a conversion step.
- **Location.** It runs as a Nix build inside the flake, pinned by `flake.lock` like every
  other build input. sbomnix would be a tool installed on the runner.
- **Reproducibility.** Its output is reproducible, including the `serialNumber`, so a
  rebuild of the tag can be compared byte for byte.
- **Vendored dependencies.** Its vendored helpers cover the two non-PHP ecosystems that
  this flake builds from source: Cargo and `buildNpmPackage`.

The cost is a new flake input, `bombon`, with `inputs.nixpkgs.follows = "nixpkgs"`. Its
last tagged release is 0.4.0 (2025-10-09). The vendored helpers are documented on `main`,
so the input is pinned by revision. sbomnix remains in use, for scanning (see
[Advisory monitoring](#advisory-monitoring-for-the-nix-closure)).

The two SBOMs stay separate rather than merged:

- **Different identities.** Syft names a store path by `pkg:nix/<name>@<version>?outputhash=…`
  taken from the image, and bombon names it from the derivation. A CycloneDX merge such as
  `cyclonedx-cli merge` concatenates components, so every Nix package would appear twice
  unless the project wrote and maintained its own deduplication.
- **Different evidence.** The image SBOM is evidence about the pushed bytes. The closure
  SBOM is evidence about the build graph, and anyone can rebuild it from the flake.
  Merging would make neither claim checkable on its own.
- **Reproducibility.** bombon's output is reproducible; merging it with Syft's randomly
  numbered document would lose that.

Consumers use the image SBOM for vulnerability scanning, because it covers every
ecosystem. They use the closure SBOM for licenses and for which Nix package pulls in
which.

#### Closure SBOM roots

The closure SBOM is built from the store paths the image contains, not from the
`image-<name>` attribute. `streamLayeredImage` produces a script that writes the tar; its
own closure includes the Python interpreter that runs the streamer, which is not in the
image. Each image therefore gets a `packages.<linux system>.sbom-<image>` output that
calls `buildBom` on the image's roots: the entries of its `contents` and the store paths
its `config` names, such as `app` and `php` for `victual-app`. It adds `extraPaths` where
an image drops references.

The label derivations and `nix/mcp.nix` gain bombon's `passthruVendoredSbom.rust` and
`passthruVendoredSbom.npm`, so their crates and npm packages appear in the closure SBOM.

### Attestation and publication

- **Image SBOMs.** Syft runs on each architecture in `nix.yml`, against the tar the boot
  test and walk already passed. It writes `*.image.cdx.json` beside the tar in the same
  artifact.
- **Closure SBOMs.** `nix.yml` builds `.#sbom-<image>` on the same runner and copies the
  result into the artifact.
- **Attestations.** In `publish`, once each per-architecture digest exists, both SBOMs
  are attested to that digest with `actions/attest@v4`, `sbom-path` and
  `push-to-registry: true`. Build provenance is attested to the per-architecture digests,
  the manifest list digest and the chart's `.tgz` digest.
- **Release.** The `release` job creates the GitHub release as a draft and uploads the
  twenty-four SBOMs as assets. It then publishes the release, which makes it immutable
  (question 3).
- **Release notes.** The release body gives the verification commands.
- **No repository SBOM.** The dependency-graph SBOM is not published (question 6).

### Immutable releases

The maintainer turns on immutable releases in the repository settings (question 3). A
published immutable release locks its tag to the commit, and its assets can no longer be
added, modified or deleted. Its title and notes stay editable. GitHub recommends creating
the release as a draft, attaching every asset, then publishing
([Immutable releases](https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases)).
On publication GitHub creates a release attestation over the tag, the commit and the
assets.

`release.yml` changes in three ways:

1. **Create as a draft.** `gh release create "$tag" --verify-tag --draft --notes-file
   notes.md`, then `gh release upload "$tag" sboms/*.cdx.json`, then
   `gh release edit "$tag" --draft=false`.
2. **Re-runs.** The existing `gh release edit "$tag" --notes-file notes.md` path still
   works after publication, because notes stay editable.
   - If the release is a draft, a failed earlier attempt left it, and the job uploads with
     `--clobber` and publishes.
   - If the release is already published, it uploads nothing. Each attached asset's
     SHA-256 (`gh release view --json assets`) must match the file the run produced.
     Otherwise the job fails, because a published immutable release cannot be corrected.
3. **A bad release needs a new version.** A published release's tag cannot be moved. If
   the release is deleted, the tag can be deleted but its name cannot be reused. The
   recovery is the next patch version. This belongs in
   [docs/releases/README.md](../releases/README.md) when the change lands.

Two points were not tested: whether a `v*` tag that is still a draft release counts as
locked, and how a release published before immutability was enabled behaves. Neither
blocks the change.

### Label repositories publish their own attested SBOMs

The renderer and worker live in their own repositories
([ADR-0019](../adr/0019-label-printers-are-master-data.md) decision item 1;
[ADR-0021](../adr/0021-label-templates-are-application-data.md)). Their release work
happens there (question 4). This plan records what each repository needs, as a
dependency:

- **A tagged release**, cut by a release workflow on a signed `v*` tag, with immutable
  releases turned on.
- **A CycloneDX 1.7 SBOM from `Cargo.lock`** (for example `cargo cyclonedx`, which
  bombon's Rust helper also uses), attached to the release as an asset. It is attested
  with `actions/attest` against a release asset the workflow builds, such as a
  `git archive` source tarball. GitHub's generated source archives are not covered by
  release attestations.
- **SLSA build provenance** for the same subject.
- **Pins to released revisions.** Victual's `flake.nix` input for each repository names
  the release tag: `github:datagen24/victual-label-renderer/v<x.y.z>`, not the default
  branch. Bumping a pin then means adopting an attested release, and `gh release verify`
  on that tag succeeds.

Victual's own image SBOMs for the label images still come from the image bytes and the
flake. The repositories' SBOMs describe their sources; Victual's describe what it ships.

### Advisory monitoring for the Nix closure

Proposed answer to question 5, for the maintainer to confirm. It fills the gap left by
Dependabot, which does not see the Nix closure. It is tied to the existing policy that a
newly found library vulnerability goes into the next release.

1. **A scheduled scan.** A new workflow, `nix-advisories.yml`, runs weekly, on every push
   to `master` that changes `flake.lock`, and after each release. It has two scanners:
   - **Grype** over the latest release's attested image SBOMs, downloaded from the
     release. This needs no Nix build and costs a few minutes. It matches Composer, npm
     and Cargo purls reliably; for Nix packages it falls back to NVD CPE matching.
   - **`vulnxscan`** from sbomnix over each image's closure output, built from the
     scanned ref. It adds vulnix's patch-aware matching and the OSV lookup, and it
     filters CVEs nixpkgs has already patched. It needs the closure built, so it runs on
     `flake.lock` changes and releases rather than weekly. Built from scratch, the
     closure takes the full release build time, since nothing is cached between runs.
2. **Results in the Security tab.** Each scanner writes SARIF (`grype -o sarif`,
   `vulnxscan --format sarif`). The workflow uploads it with
   `github/codeql-action/upload-sarif`, which `tests.yml` already uses, under one
   category per image and scanner. Nix-closure findings then appear in the Security tab
   with the Dependabot alerts.
   - Unverified: code scanning ties an alert to a file location, and the SARIF from these
     scanners names a package, not a repository file. An upload with no usable location
     may be rejected or shown without one. Run one upload before committing to this route.
     If it does not work, step 3 carries the findings alone.
3. **An issue on the next milestone.** A new finding that is not whitelisted opens a
   GitHub issue, or updates the open one, labelled `security` and `nix-closure` and
   milestoned to the next release. This matches how Dependabot pull requests are
   milestoned today.
4. **A triage file in the repository.** `.devtools/advisories/nix-whitelist.csv` uses
   `vulnxscan`'s whitelist format. Each entry records a CVE, the package, the reason and
   the date. A false positive or an accepted risk is a reviewed pull request, not a closed
   issue.
5. **Regular `flake.lock` bumps.** A scheduled
   [`DeterminateSystems/update-flake-lock`](https://github.com/DeterminateSystems/update-flake-lock)
   pull request (v29, 2026-09-09) runs weekly and is milestoned like Dependabot's, so
   fixes in nixpkgs arrive whether or not a scan found something. The bump runs the `nix`
   workflow's build, boot and walk like any `flake.lock` change. It also moves the PHP
   build: `nix/php.nix` compiles PHP from source, so every bump costs a full image build in
   CI.

Expected noise and recurring cost:

- **The first scan will be long.** vulnix's heuristic matches by product name, and NVD
  names collide. `vulnxscan`'s own example output reports Jenkins git-plugin CVEs against
  `git`. The OSV client queries without an ecosystem and reports matches from other
  ecosystems.
- **Early triage.** Expect the first scans to need a triage pass that fills the whitelist
  with tens of entries. After that, a weekly read of new findings, and the review of one
  `flake.lock` pull request a week.
- **Grype's CPE fallback** adds its own false positives on Nix names. Requiring agreement
  between two of the three `vulnxscan` sources (its `sum` column) before opening an issue
  is one way to cut noise. Whether to use that rule is the maintainer's call after the
  first scans.

## Workflow sketch

Illustrative only; no workflow is changed by this plan.

In `nix.yml`, after the walk and the image streams are written, on each architecture:

```yaml
- name: SBOMs for each image
  if: inputs.artifact-name != ''
  run: |
    set -euo pipefail
    out="$RUNNER_TEMP/images"
    for tar in "$out"/*.tar; do
      c=$(basename "$tar" .tar)
      syft scan "docker-archive:$tar" -o "cyclonedx-json@1.7=$out/$c.image.cdx.json"
      cp "$(nix build ".#sbom-$c" --no-link --print-out-paths)" "$out/$c.closure.cdx.json"
    done
```

Syft is installed by a pinned checksum, as the `publish` job installs Helm. The SBOMs
travel in the same artifact as the tars.

In `release.yml`'s `publish` job, capture the per-architecture digest after each
`docker push "$remote_ref"`, then attest both SBOMs. The job gains `id-token: write`,
`attestations: write` and `artifact-metadata: write`:

```yaml
- uses: actions/attest@v4
  with:
    subject-name: ghcr.io/datagen24/victual-app
    subject-digest: ${{ steps.push.outputs.app-amd64 }}
    sbom-path: images/images-amd64/app.image.cdx.json
    push-to-registry: true
- uses: actions/attest@v4
  with:
    subject-name: ghcr.io/datagen24/victual-app
    subject-digest: ${{ steps.push.outputs.app-amd64 }}
    sbom-path: images/images-amd64/app.closure.cdx.json
    push-to-registry: true
```

That is twenty-four SBOM attestations, plus provenance for twelve per-architecture
digests, six manifest lists and the chart. `push-to-registry` takes one image reference
per call. The job therefore uses a matrix over image and architecture, or a reusable
workflow, rather than seventy-odd literal steps.

In the `release` job, rename the SBOMs to their published file names. Create the draft,
upload the assets, add the verification commands to `notes.md`, then publish.

## Fit with the release model

Under [ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md) (Proposed), the
tag builds, tests and publishes. SBOM generation sits in `nix.yml` and attestation in
`publish`, as sketched.

Under [ADR-0047](../adr/0047-one-trunk-release-branches-and-promoted-images.md)
(Proposed), QA builds and pushes `qa-<sha>` images, and the tag only re-tags those
digests. Attestations are bound to digests, not tags, so attesting in QA covers the
promoted release with no further step. The release job then copies the SBOMs from the QA
run into the draft release and verifies the attestations rather than creating them. The
design works under either record.

## Fit with the dependency vulnerability policy

The maintainer's policy is that a newly disclosed library vulnerability is fixed in the
next release, and Dependabot pull requests are milestoned to it.

- A published image SBOM lets an operator check a running release against a new
  advisory without waiting for the next release.
- Comparing the next release's image SBOM with the previous one shows that the
  milestoned Dependabot fixes are present in the image, not only in the lockfile.
- The advisory monitoring above extends the same rule to the Nix closure. Findings are
  milestoned to the next release, and the weekly `flake.lock` pull request is how their
  fixes arrive.

## Dependencies

- **A new ADR for signing and provenance.** ADR-0030 left it to "a separate record." The
  maintainer's answers settle what this plan needed from it: CycloneDX SBOMs and
  provenance, attested to per-architecture digests in GitHub and GHCR, and immutable
  releases. The record still has to accept those choices, name the signing identity
  (`release.yml` on a `v*` tag), and state what a consumer is told to verify.
- **ADR-0030 and ADR-0047** are both Proposed. The design does not depend on which is
  accepted, but the workflow follows whichever is.
- **Public GHCR packages.** Anonymous `gh attestation verify --bundle-from-oci` depends on
  them, which the release guide already requires.
- **Release work in the two label repositories:** a release workflow, an attested SBOM and
  provenance, immutable releases, and tagged pins in `flake.nix` (question 4). It is a
  follow-up in those repositories. The pin change is a one-line `flake.nix` edit per input
  once a tag exists there.
- **A new flake input, `bombon`,** pinned by revision.

## Open questions

All six were answered by the maintainer (datagen24) on 2026-10-28. Question 5's answer is
a proposal written in response to the maintainer's request and is not yet confirmed.

1. **SPDX or CycloneDX?** SPDX 2.3 is what GitHub's export and `gh attestation verify`
   examples use; SPDX 2.2.1 is published as ISO/IEC 5962:2021. CycloneDX 1.6/1.7 is
   bombon's only output and what OWASP Dependency-Track ingests. The answer depends on who
   consumes the SBOM: the maintainer's own scanner, a Dependency-Track instance, or
   anonymous operators. Publishing both from one Syft scan costs one more file per image.

   > **Response:** datagen24, 2026-10-28: "CycloneDX is my preferred format." Reconciled:
   > every published SBOM is CycloneDX 1.7, the version both Syft 1.54 and bombon emit.
   > No SPDX is published. `actions/attest` and `gh attestation verify` accept CycloneDX
   > under the predicate type `https://cyclonedx.org/bom`, so nothing requires SPDX.

2. **Syft only, or Syft plus a Nix-aware generator?** Syft's output has no Nix dependency
   edges and leaves most Nix packages without a license. sbomnix or bombon fixes both but
   misses Composer, so it would be a second SBOM or a merge step. Needed only if license
   compliance or closure-level graphs matter to a consumer.

   > **Response:** datagen24, 2026-10-28: "Yes. It should be part of the release pipeline
   > so the SBOM artifact ships with the images." Reconciled: bombon builds a closure SBOM
   > per image and architecture as a flake output in `nix.yml`. It is attested to the same
   > digest as Syft's image SBOM and attached to the release. The two are kept separate;
   > see [Two CycloneDX 1.7 SBOMs per image and architecture](#two-cyclonedx-17-sboms-per-image-and-architecture)
   > for why bombon and not sbomnix, and why there is no merge.

3. **Turn on immutable releases?** It would give the release a GitHub-signed attestation
   over its SBOM assets. `release.yml` edits an existing release with `gh release edit` on
   a re-run; whether that still works on an immutable release was not tested.

   > **Response:** datagen24, 2026-10-28: "Yes." Reconciled: GitHub's documentation keeps
   > a published release's notes editable and locks its assets. `release.yml` therefore
   > creates a draft, uploads every SBOM, then publishes. A re-run against a published
   > release only edits notes and checks the attached assets' digests; see
   > [Immutable releases](#immutable-releases).

4. **Attest the label repositories' sources?** `victual-label-renderer` and
   `victual-label-worker` are pinned by revision in `flake.lock`. The image SBOM lists their
   crates through `cargo-auditable`. Provenance for the source revision is the other
   repositories' concern unless the ADR says otherwise.

   > **Response:** datagen24, 2026-10-28: "Yes; they should also have SBOMs pinned to
   > releases." Reconciled: each repository publishes immutable releases carrying an
   > attested CycloneDX SBOM and provenance, and Victual's `flake.nix` pins each input to
   > a release tag. The work is in those repositories and is listed under
   > [Dependencies](#dependencies) and
   > [Label repositories publish their own attested SBOMs](#label-repositories-publish-their-own-attested-sboms).

5. **Who watches the Nix closure for advisories?** See the policy gap above. Each option
   is a different amount of recurring work.

   > **Response:** datagen24, 2026-10-28: asked for a suggestion. **Proposed, awaiting
   > confirmation:**
   > - A weekly Grype scan over the release's image SBOMs.
   > - A `vulnxscan` scan of the closure on each `flake.lock` change and release.
   > - SARIF uploaded to code scanning, subject to one test upload.
   > - New, unwhitelisted findings open an issue on the next release's milestone.
   > - A reviewed whitelist CSV in the repository.
   > - A weekly `update-flake-lock` pull request milestoned like Dependabot's.
   >
   > See [Advisory monitoring for the Nix closure](#advisory-monitoring-for-the-nix-closure)
   > for the noise and cost.

6. **Keep the dependency graph's repository SBOM at all?** It is free to export but does
   not describe a release. Recommendation is not to publish it; the maintainer may want it
   for another reason.

   > **Response:** datagen24, 2026-10-28: "It sounds like it is not telling the truth
   > because of the complexity of master, so yes." Reconciled: it is not published.
   > It describes `master` at request time, includes dev and spike dependencies, and omits
   > the Nix closure, so it does not describe any release. The dependency graph itself
   > stays enabled, because Dependabot alerts depend on it.

## Verification

- For each of the six images and both architectures, this command succeeds without
  credentials and returns two SBOM attestations, the image SBOM and the closure SBOM:

  ```bash
  gh attestation verify oci://ghcr.io/datagen24/victual-<image>@<arch digest> \
    -R datagen24/victual --predicate-type https://cyclonedx.org/bom
  ```

  It fails for a digest the workflow did not build. Adding `--bundle-from-oci` also
  succeeds, showing the GHCR copy is readable.
- Every published SBOM has `"bomFormat": "CycloneDX"` and `"specVersion": "1.7"`.
- The `app` image SBOM lists:
  - the 66 production Composer packages from that tag's `composer.lock`;
  - `openssl`, `curl` and `php` at the versions the flake lock pins.
- The `mcp` image SBOM lists every production package in `mcp/package-lock.json`, and the
  label image SBOMs list their Cargo crates.
- The `app` closure SBOM:
  - has dependency edges between Nix packages;
  - gives a license for `openssl` and `php`;
  - does not contain `python3`, the image streamer's interpreter.
- Two builds of `.#sbom-app` from the same commit are byte-identical.
- The amd64 and arm64 SBOMs of one image differ in Nix output hashes, confirming they were
  generated from the per-architecture bytes.
- The release:
  - `gh release view v<version> --json isImmutable` reports `true`, and the release has
    twenty-four `.cdx.json` assets;
  - `gh release verify v<version>` succeeds, and
    `gh release verify-asset v<version> <file>` succeeds for a downloaded SBOM;
  - re-running the `release` job after publication changes no asset and fails if an
    asset's digest differs.
- The release body's verification command, copied verbatim, succeeds.
- A pull request that changes `release.yml` builds the SBOMs and does not attest, upload
  or push anything.
- Once the label repositories have releases:
  - `flake.nix` names a release tag for each, and `gh release verify` succeeds on that tag
    in its repository;
  - that release carries an attested CycloneDX SBOM.
- If question 5's proposal is confirmed:
  - a planted known-vulnerable Nix package version on a test branch produces a finding in
    the monitoring workflow's output, and an issue on the next milestone;
  - a whitelisted entry produces neither.
