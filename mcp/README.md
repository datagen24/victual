# victual-mcp

Read-only MCP sidecar for Victual. Build from
[docs/mcp-interface-spec.md](../docs/mcp-interface-spec.md) — this tree is the
skeleton it describes in §12, adapted to live in this repository rather than a
separate one (see the spec's Open Question 1 amendment, 2026-09-19, and
[ADR-0013](../docs/adr/0013-nix-built-container-images.md)).

## Status: framework only, unbuilt

This was scaffolded in a sandbox with no `npm` registry access and no Nix, so **nothing
here has been installed, compiled, or run.** Treat the first real build as part of the
work, the same way `nix/README.md` treats the first `nix build` of the other three
images — the SDK v2 API surface is described in the spec from a reading, not a build,
and it is exactly the kind of interface that turns out to differ once code is written
against it.

## What's here

- `src/config.ts` — the §8 environment schema.
- `src/auth/resolver.ts` — the credential→outbound-headers seam (§2, §4), the IdP swap
  point later.
- `src/victual/client.ts` — the REST client and §7 status→category error mapping.
- `src/victual/shape.ts` — row shaping helpers (unit names, due-date sentinel).
- `src/tools/*.ts` — one file per §5 tool: input/output Zod schemas transcribed from the
  spec, handlers that throw `not implemented`. The schemas are real; the bodies are not.
- `src/server.ts`, `src/main.ts` — boot and server wiring, stubbed at the point the
  actual `@modelcontextprotocol/sdk` v2 API is needed.
- `tests/{fixtures,contract,tools}/` — empty, waiting on plan 14 piece 2's
  response-contract fixtures (landed 2026-09-17 in the main tree) to be copied in.

## What the local session needs to do first

1. `npm install` to generate `package-lock.json` (not committed — could not be produced
   without npm registry access here) and confirm the dependency versions in
   `package.json` against whatever the MCP TypeScript SDK v2 actually ships as on
   npm — the spec names it descriptively (`@modelcontextprotocol/server` /
   `/express`/`/node`, `@modelcontextprotocol/core`), not by exact package name, and
   that needs verifying against the real registry, not memory.
2. `npm run build`, then wire `src/server.ts`'s `buildServer()` to the real SDK: the
   `/mcp` Streamable HTTP handler, `/healthz`, and the `tools/list` capability filter
   (§5) against `GET /api/user/capabilities` — which does not exist in Victual yet
   either (spec §4.2 item 5). That endpoint, `API_KEY_TYPE_MCP`, and the `read_only`
   key flag are Victual-side work this scaffolding step deliberately left undone; it
   was scoped to the sidecar and its Nix build only.
3. Fill in each tool's `handler`, replay-tested against plan 14's fixtures per spec
   §11.1.
4. `nix build .#mcp` from the repository root once `package-lock.json` exists — this
   will fail on the first run with a hash mismatch for `nix/hashes.nix`'s
   `mcpNpmDeps` placeholder, by design; see `nix/README.md`, "Bootstrapping the
   hashes". `nix build .#image-mcp` after that.
5. `compose.yaml` (§11.2–§11.5's stack) is not written yet — add it once there is a
   real server to point MCP Inspector at.
