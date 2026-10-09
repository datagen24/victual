# victual-mcp

MCP sidecar for Victual (six read tools, three opt-in write tools), built from
[docs/mcp-interface-spec.md](../docs/mcp-interface-spec.md). It lives in this repository
rather than a separate one — see the spec's Open Question 1 amendment (2026-09-19) and
[ADR-0013](../docs/adr/0013-nix-built-container-images.md).

## Status (2026-09-19, auth update 2026-09-29)

Built, tested and running on a Kubernetes cluster (kind, v1.37) beside Victual. The six
§5 tools answer against a production-mode Victual over both protocol revisions §1 names:
`2026-07-28` (stateless, `server/discover`) and `2025-11-25` (the stateless legacy
fallback). Checked with the official SDK v2 client, not hand-built envelopes.

**Victual's side of the auth seam — issue #208 — has since shipped** (`4b9c0d1e`,
2026-09-19): `API_KEY_TYPE_MCP`, the per-key `read_only` flag (migration 0287), and
`GET /api/user/capabilities` all exist. This section is not re-verified against a
redeployed sidecar; it records what Victual now offers.

**Not done, and what it gates:**

- **Contract replay against plan 14's fixtures (§11.1).** The handler tests use the
  recorded *shapes*, but not the frozen fixtures themselves.
- **The actual client (§11.4).** The two motivating questions have not yet been asked
  through Claude in real use.
- **Household use of the read tools.** Issue #86 is still open.

## Write tools (issue #209, 2026-10-08)

`add_to_shopping_list`, `consume_product` and `purchase_product` are built per spec §6.
The earlier condition that they wait for #86 to be in household use was added by an
agent, not decided by the maintainer, and is withdrawn (plan 02 Q2). They are **off by default**: `all-read` does
not include them, so an operator enables each by name, for example
`MCP_ENABLED_TOOLS=all-read,add_to_shopping_list,consume_product,purchase_product`.The `all-read` keyword expands inside a comma list.

- Listed only to a key that is not `read_only` and whose user holds the tool's permission
  (`SHOPPINGLIST_ITEMS_ADD`, `STOCK_CONSUME`, `STOCK_PURCHASE`). `consume_product` and
  `purchase_product` also need `STOCK_VIEW`, because Victual answers their POST with the
  booked `stock_log` rows. Hiding is UX; Victual enforces on every forwarded call.
- The tool fields differ from the REST fields: `amount` -> `product_amount` and
  `shopping_list_id` -> `list_id` on the shopping-list route, `due_date` ->
  `best_before_date` on purchase. `shopping_location_id` keeps its name.
- `consume_product` and `purchase_product` return `transaction_id` in `structuredContent`.
  A booking answered without a transaction row is reported as `victual_error`.
- No tool creates a product; an unknown `product_id` is refused by Victual.
- Not done: the real-client household check ("add milk to the list", "I used the last of the
  coffee") and contract tests against plan 14's fixtures for the three POST routes.

## Layout

| Path | What |
|---|---|
| `src/main.ts` | Boot: parse the environment (§8), listen, stop on SIGTERM |
| `src/config.ts` | The §8 environment schema; exits non-zero on anything invalid |
| `src/server.ts` | `/healthz`, the pre-JSON-RPC 401, the §5 capability probe, and `createMcpHandler` with a per-request server factory |
| `src/auth/resolver.ts` | Credential → outbound headers (§2, §4). The IdP swap point |
| `src/victual/client.ts` | The REST client and the §7 status → category mapping |
| `src/victual/capabilities.ts` | `GET /api/user/capabilities`; a 404 means "serve unfiltered" |
| `src/victual/shape.ts`, `types.ts` | Row shaping (units, the `2999-12-31` sentinel, numeric coercion) and the REST slices read |
| `src/tools/*.ts` | One file per §5 tool: Zod input/output schemas and the handler |
| `tests/tools/` | Handler tests (mocked client) and HTTP tests (a fake Victual over real sockets) |
| `scripts/probe.mjs` | Drives a running sidecar with the official SDK v2 client |

## Working on it

```sh
npm ci
npm test                  # tsc, then node:test — 15 tests
npm run typecheck
```

Run it against a Victual:

```sh
npm run build
VICTUAL_BASE_URL=http://localhost:8080 MCP_PORT=3000 node dist/main.js
```

Probe it as a client would:

```sh
PROBE_NEGOTIATION=2026-07-28 npm run probe -- http://localhost:3000/mcp "$KEY" expiring_soon '{"days":7}'
```

`PROBE_NEGOTIATION` takes `auto` (the default: `server/discover` first, then fall back),
`legacy`, or a revision to pin. **The SDK client's own default is `legacy`.** A client
that doesn't opt in speaks `2025-11-25` without saying so. That is how the first probe of
this server reported `2025-11-25` against a server that serves both.

## Build and deploy

- **Image:** `nix build .#image-mcp`, or `nix/build-in-podman.sh images` from a Mac, which
  builds and loads all four images.
- **Changing `package-lock.json` changes `nix/hashes.nix`'s `mcpNpmDeps`.** Re-run
  `nix build .#mcp` and take the `got:` value.
- **Manifest:** [`deploy/k3s/victual-mcp.yaml`](../deploy/k3s/victual-mcp.yaml).
- **Local cluster:** [`deploy/kind/up.sh`](../deploy/kind/up.sh) brings up Victual, the
  sidecar and a throwaway PostgreSQL on kind.

**The image runs `node` directly and carries no shell.** `nix/checks.nix`'s
`mcp-image-has-no-shell` holds that. The first build had bash in the closure three ways:
buildNpmPackage's bin wrapper, npm through the full `nodejs`, and nodejs-slim's embedded
`process.config` naming its `-dev` outputs. [`nix/mcp.nix`](../nix/mcp.nix)'s header
explains each fix.

## Connecting a client

The client needs:

- the sidecar's URL;
- a static header, `Authorization: Bearer <Victual API key>`.

**The key travels in that header, so only send it over plain HTTP where nobody else can
read the traffic.** The sidecar speaks plain HTTP by design, and TLS is the ingress's job
(spec §8, §9).
- Over `http://`, use `kubectl port-forward` to `localhost:3000/mcp`, or a tunnel that
  encrypts, such as a tailnet.
- Anything else reaches it through the operator's ingress with TLS, as `https://…/mcp`.
- The manifest publishes no ingress, and the spec keeps the sidecar on the cluster or
  tailnet (§9, plan 02 Q4).

Inside the cluster, the sidecar forwards the key to Victual over the cluster network. If
you don't trust pod-to-pod traffic there, encrypting it is a mesh or CNI concern (mTLS);
the manifests don't provide it.

The key's user is who the assistant acts as, so its permissions are what the assistant
can see. **No v1 tool returns a price field**: every row is shaped from named fields
(§5), and none of them is a price. Issue #86's price-visibility residual applies once a
tool does. Then either each person gets their own key, or the shared key's user holds no
`STOCK_PRICES_VIEW`.
