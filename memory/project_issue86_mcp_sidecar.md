---
name: Issue 86 — MCP sidecar framework
description: Status and next steps for the read-only MCP sidecar scaffolded in-repo at mcp/, and why it lives there instead of a new victual-mcp repository.
type: project
---

## What exists (2026-09-19, merged to master via PR 207)

**PR [207](https://github.com/datagen24/victual/pull/207) merged 2026-09-19**, branch
`claude/cool-faraday-mx372b`. CodeRabbit review round: 4 findings, all closed before
merge — Node engine floor raised to `>=22.6.0`, `nix/mcp.nix` given
`sourceRoot = "source/mcp"` (a real bug: `buildNpmPackage` would have looked for the
manifest at the wrong path), the missing-lockfile/placeholder-hash finding withdrawn by
CodeRabbit as intentional and already tracked, and an HTTPS-enforcement suggestion
declined in writing (contradicts spec §8/§9's deliberate in-cluster-HTTP design) —
left for @datagen24 to weigh in on if the threat model should change. A first push had
also broken the `flake` CI job by wiring an `mcp-image-has-no-shell` check into
`nix/checks.nix` that forced `nix flake check` to build the still-unbuildable `mcp`
package on every PR; fixed by pulling that check back out until `mcpNpmDeps` is real.

Framework only — nothing has been built, installed, or run. The sandbox that wrote this
had no Nix and no npm registry access.

- `mcp/src/tools/*.ts` — Zod input/output schemas transcribed from
  [docs/mcp-interface-spec.md](../docs/mcp-interface-spec.md) §5 for all six v1 tools.
  Every `handler` throws `not implemented`.
- `mcp/src/auth/resolver.ts` — the credential→outbound-headers seam (spec §2, §4).
- `mcp/src/victual/client.ts` — the REST client and spec §7 status→category error
  mapping.
- `mcp/src/server.ts`, `mcp/src/main.ts` — boot and server wiring, stubbed at the point
  the actual `@modelcontextprotocol/sdk` v2 API is needed.
- `nix/mcp.nix`, `nix/images/mcp.nix` — a fourth Nix image (`.#image-mcp`), wired into
  `flake.nix` and `nix/overlay.nix` alongside `image-app`/`image-web`/`image-migrate`/
  `image-label-renderer`/`image-label-worker`.
- `nix/hashes.nix`'s `mcpNpmDeps` — still the fakeHash placeholder, because
  `mcp/package-lock.json` doesn't exist yet.

**Lesson from PR #207's first CI run:** an initial commit also added
`mcp-image-has-no-shell` to `nix/checks.nix`. That broke the `flake` CI job outright —
`nix flake check` builds every `checks.<system>.*` derivation, so a check closing over
`mcp` forces `mcp` to build on *every* pull request, and that build fails on purpose
while `mcpNpmDeps` is a placeholder. Reverted in the next commit, with a comment in
`nix/checks.nix` explaining why the check waits for a real hash. Add it back only once
`mcpNpmDeps` is real — don't repeat this.

## Why in-repo, not a new `victual-mcp` repository

The interface spec's Open Question 1 (2026-08-29) answered "new repo." Two things
reversed that, recorded as a dated amendment inline in the spec:

1. [ADR-0013](../docs/adr/0013-nix-built-container-images.md), accepted 2026-09-04 —
   five days after that answer — names plan 02 by number in its *Would affect* list and
   states the rule directly: a TypeScript sidecar is a `buildNpmPackage` away from an
   image with the same uid, labels, empty `/bin` and checks as `image-app`.
2. `mcp__github__create_repository` for `datagen24/victual-mcp` failed:
   `403 Resource not accessible by integration` — the GitHub App installed for Claude
   Code sessions on this repo has no repository-creation scope. That made the new-repo
   path a blocker, not a preference, in the moment it mattered.

The cost, stated honestly: the independent release cadence and `mcp-grocy`-derived
CI/release packaging that a separate repo would have inherited under spec §12 do not
apply here and are not built. `docs/mcp-interface-spec.md` §12 is kept as reference
material only.

## How to apply — next steps, in order

1. `cd mcp && npm install`. This also verifies the actual
   `@modelcontextprotocol/sdk` v2 package name/version against the real npm registry —
   the spec names it descriptively ("`@modelcontextprotocol/server` with the
   `/express` or `/node` adapter, plus `@modelcontextprotocol/core`"), not exactly, and
   was written from reading the spec rather than installing the package. Commit the
   resulting `package-lock.json`.
2. `npm run build` (tsc), then wire `mcp/src/server.ts`'s `buildServer()` to the real
   SDK: the `/mcp` Streamable HTTP handler, `/healthz`, and the `tools/list` capability
   filter against `GET /api/user/capabilities` (spec §5) — which does not exist in
   Victual yet either (spec §4.2 item 5, Victual-side work, out of this step's scope).
3. From the repository root: `nix build .#mcp`. First run fails on purpose with the
   real `mcpNpmDeps` hash — paste it into `nix/hashes.nix`, same as
   `nix/README.md`'s "Bootstrapping the hashes" describes for `composerVendor` and
   `yarnOfflineCache`. Then `nix build .#image-mcp` and `nix flake check`.
4. Fill in each tool's `handler`, replay-tested against plan 14's response-contract
   fixtures per spec §11.1.
5. Only then: the Victual-side auth work spec §4.2 describes (`API_KEY_TYPE_MCP`, the
   per-key `read_only` flag, `GET /api/user/capabilities`) and spec §11's full
   verification plan (MCP Inspector, the auth/capability matrix, the actual client,
   the two-replica soak).

See [mcp/README.md](../mcp/README.md) for the same list kept next to the code.
