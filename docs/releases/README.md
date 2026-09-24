# Releases

One record per tagged version, newest first. A record says what the tag is, what was
verified before it was placed, and what is known to be missing. The authorities it
summarises stay authoritative: the [plan index](../plans/README.md) for delivery status,
each plan's Executed section for what shipped, the [ADR index](../adr/README.md) for
decisions in force, and the [security sweep](../security-sweep.md) for findings.

A tag marks a commit that was verified working, not a schedule. The tag string, the image
tags the flake produces and the version `GET /api/system/info` reports are the same
string, read from `version.json`; `nix flake check` refuses a mismatch.

## Placing a tag

The maintainer places the tag, signed, from the workstation, on the `master` commit that
merges the release record. Pushing it runs `.github/workflows/release.yml`
([ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md), Proposed). That workflow
refuses a tag that is not `v` plus `version.json`'s `Version`, a version with no record
here, and a commit that is not on `master`. It then builds, boots and walks the images on
amd64 and arm64, pushes them to `ghcr.io/datagen24/victual-*:<version>`, and creates the
GitHub release with each image's digest.

```bash
git tag -s v<version> -m "Victual <version>" <merge commit>
git push origin v<version>
```

The first time a package is published, GHCR creates it as private. Set each of the six
packages to public once, under the repository's Packages settings, or a node cannot pull
them without a pull secret.

These records are not published on the documentation site
([ADR-0020](../adr/0020-documentation-publication-boundary.md)); the manual's
[Updating](../manual/getting-started.md#updating) page tells an operator to pull a tag.

| Version | Date | Record |
|---|---|---|
| 0.1.1-MVP | 2026-09-19 | [0.1.1-MVP.md](0.1.1-MVP.md) |
| 0.1.0-MVP | 2026-09-19 | [0.1.0-MVP.md](0.1.0-MVP.md) |
