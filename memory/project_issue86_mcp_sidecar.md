---
name: Issue 86 — MCP sidecar
description: Status, gotchas and next steps for the read-only MCP sidecar at mcp/ — built, tested, deployed to kind; what #208 still gates; how to drive the kind harness.
type: project
---

## Where it stands (2026-09-19, branch `claude/issue-86-kubernetes-deploy-cc575a`)

Built and running. PR [207](https://github.com/datagen24/victual/pull/207) merged the
framework; this branch made it real:

- **`mcp/`**: six §5 tools implemented, `createMcpHandler` wiring, 15 node:test tests
  (`npm test`, green on Node 22 and 26). `scripts/probe.mjs` drives it with the official
  SDK v2 client.
- **Nix**: `mcpNpmDeps` is a real hash. `.#image-mcp` is 232 MB, with no `/bin/sh`, running as
  uid 65532. `mcp-image-has-no-shell` is in `nix/checks.nix`.
- **Deploy**: `deploy/k3s/victual-mcp.yaml` (two replicas), `deploy/k3s/kustomization.yaml`,
  and the `deploy/kind/` harness (`up.sh`, a throwaway PostgreSQL, the roles Job).
- **Verified on kind v1.37**:
  - All six tools answer against a production-mode Victual over `2026-07-28` and
    `2025-11-25`.
  - A request with no header is 401 before any JSON-RPC.
  - A garbage key gets an `unauthorized` tool error.
  - Two-replica soak: 40 of 40 calls answered correctly.

## Gotchas this session paid for — don't repeat

- **SDK v2 package names.** Use `@modelcontextprotocol/server`, `/node`, `/core` and
  `/client`. `@modelcontextprotocol/sdk` stops at 1.30.
- **The v2 *client* defaults to the legacy handshake.** Pass
  `versionNegotiation: { mode: 'auto' | {pin} }`. Otherwise a probe reports `2025-11-25` and
  proves nothing about the server.
- **The SDK turns a factory exception into its own 500.** So anything that must become
  401/403, like the capability probe, runs *before* `handler.fetch`.
- **Keeping bash out of the image** took three fixes, all in `nix/mcp.nix`:
  - buildNpmPackage's bin wrapper is a bash script. It is deleted.
  - The full `nodejs` drags in npm and corepack. The image uses `nodejs-slim` instead.
  - nodejs-slim's `bin/node` embeds `process.config`, which names every `-dev` output
    (icu4c-dev reaches bash). The runtime copies the binary and runs
    `remove-references-to` on the `-dev`/`-bin` paths and on nodejs-slim's own prefix.
  - The second `grep` in that pipeline needs `-a`, or it treats its input as binary and
    silently scrubs nothing.
- **`node --test <dir>` fails on Node 22.** Use a quoted glob.
- **zsh does not word-split `$VAR`.** Build curl flags with a bash script or an array.
- **Production-mode Victual forces `admin` through a password change** before any page,
  API-key creation included. `PUT /api/users/1` needs `current_password`.

## Open, in order

1. **#208, Victual-side auth.** `API_KEY_TYPE_MCP`, the `read_only` column (claim the next
   migration number), the `/manageapikeys` UI, and `GET /api/user/capabilities`. Until it
   lands, `tools/list` is served unfiltered: the probe 404s and falls back. That includes
   a garbage key, which gets a list and then `unauthorized` on the first call. The sidecar
   expects `{key_type, read_only, permissions: [names]}`, with permission names from
   `controllers/Users/User.php` — the `*_VIEW` leaves.
2. §11.1: replay tests against plan 14's frozen fixtures.
3. §11.4: the real Claude client, and tuning the `limit` defaults from transcripts.
4. #209, the write tools: only after #208 and real use.

## Driving the kind harness

The images come from the builder container `victual-nix-builder-86`, created from
`localhost/victual-nix-warm:133`:

```
BUILDER=victual-nix-builder-86 NIX_IMAGE=localhost/victual-nix-warm:133 nix/build-in-podman.sh images
```

Then `deploy/kind/up.sh`. The cluster is `kind-cluster`, podman provider, one arm64 node;
the script sets `KIND_EXPERIMENTAL_PROVIDER`.

## Why in-repo rather than `victual-mcp`

Two reasons, recorded in the spec's Open Question 1 amendment:
[ADR-0013](../docs/adr/0013-nix-built-container-images.md)'s precedent, and a `403` on repo
creation. The cost: no independent release cadence.
