# Releases

One record per tagged version, newest first. A record says what the tag is, what was
verified before it was placed, and what is known to be missing. The authorities it
summarises stay authoritative: the [plan index](../plans/README.md) for delivery status,
each plan's Executed section for what shipped, the [ADR index](../adr/README.md) for
decisions in force, and the [security sweep](../security-sweep.md) for findings.

A tag marks a commit that was verified working, not a schedule. The tag string, the image
tags the flake produces and the version `GET /api/system/info` reports are the same
string, read from `version.json`; `nix flake check` refuses a mismatch.

These records are not published on the documentation site
([ADR-0020](../adr/0020-documentation-publication-boundary.md)); the manual's
[Updating](../manual/getting-started.md#updating) page tells an operator to pull a tag.

| Version | Date | Record |
|---|---|---|
| 0.1.1-MVP | 2026-09-19 | [0.1.1-MVP.md](0.1.1-MVP.md) |
| 0.1.0-MVP | 2026-09-19 | [0.1.0-MVP.md](0.1.0-MVP.md) |
