# Releases

One record per tagged version, newest first. A record says what the tag is, what was
verified before it was placed, and what is known to be missing. The authorities it
summarises stay authoritative: the [plan index](../plans/README.md) for delivery status,
each plan's Executed section for what shipped, the [ADR index](../adr/README.md) for
decisions in force, and the [security sweep](../security-sweep.md) for findings.

A tag marks a commit that was verified working, not a schedule. The tag string, the image
tags the flake produces, the Helm chart's `version` and `appVersion`, and the version
`GET /api/system/info` reports are the same string, read from `version.json`; `nix flake
check` refuses a mismatch in the images and `.devtools/ci/test_helm_chart.py` in the
chart ([ADR-0038](../adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md) decision 4).
A version bump therefore edits `version.json` and `deploy/helm/victual/Chart.yaml`
together, both keys.

## Placing a tag

The maintainer places the tag, signed, from the workstation, on the `master` commit that
merges the release record. Pushing it runs `.github/workflows/release.yml`
([ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md), Proposed). That workflow
refuses a tag that is not `v` plus `version.json`'s `Version`, a `Chart.yaml` whose
`version` or `appVersion` differs from it, a version with no record here, and a commit
that is not on `master`. It then builds, boots and walks the images on amd64 and arm64,
pushes them to `ghcr.io/datagen24/victual-*:<version>`, pushes the chart to
`oci://ghcr.io/datagen24/charts/victual:<version>`, and creates the GitHub release with
each image's digest and the chart's reference and digest.

```bash
git tag -s v<version> -m "Victual <version>" <merge commit>
git push origin v<version>
```

The first time a package is published, GHCR creates it as private. Set each of the six
image packages to public once, under the repository's Packages settings, or a node cannot
pull them without a pull secret. After the first tag that publishes the chart, `v0.3.1`, do
the same once for `charts/victual`, then check that it pulls without credentials:

```bash
helm pull oci://ghcr.io/datagen24/charts/victual --version <version>
```

These records are not published on the documentation site
([ADR-0020](../adr/0020-documentation-publication-boundary.md)); the manual's
[Updating](../manual/getting-started.md#updating) page tells an operator to pull a tag.

| Version | Date | Record |
|---|---|---|
| 0.3.2 | 2026-10-08 | [0.3.2.md](0.3.2.md) |
| 0.3.1 | 2026-10-08 | [0.3.1.md](0.3.1.md) |
| 0.3.0 | 2026-10-07 | [0.3.0.md](0.3.0.md) |
| 0.2.0-MVP | 2026-09-24 | [0.2.0-MVP.md](0.2.0-MVP.md) |
| 0.1.1-MVP | 2026-09-19, never tagged; its changes are in 0.2.0-MVP | [0.1.1-MVP.md](0.1.1-MVP.md) |
| 0.1.0-MVP | 2026-09-19 | [0.1.0-MVP.md](0.1.0-MVP.md) |
