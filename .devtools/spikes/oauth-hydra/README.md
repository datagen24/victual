# Spike: Ory Hydra as the swappable authorization server (issue #770)

Throwaway. Answers two questions before the ADR: does Hydra's delegated login/consent fit a
Victual-shaped app, and what does a real MCP client need from the authorization server.
Nothing here is Victual code or a design.

Hydra v26.2.0 (`docker.io/oryd/hydra:latest`, pulled 2026-10-11), `--dev`, in-memory store,
one container capped at 512 MiB. Postgres was deliberately not run: the shared podman VM is
4 CPU / 8 GiB. Hydra's Postgres support is therefore **untested here**.

## Run

```bash
cd .devtools/spikes/oauth-hydra
npm install                 # only rs.mjs needs it (same SDK range as mcp/package.json)
./run.sh up                 # Hydra on :4444 (public) / :4445 (admin)
node idp-stub.mjs &         # login + consent + device app on :3000
node rs.mjs &               # MCP resource server on :8080/mcp
node probe.mjs              # scripted flows, prints what happened
./run.sh down
```

## Findings (probe.mjs output, 2026-10-11)

| Question | Result |
|---|---|
| Login/consent delegation | Works. Hydra redirects with a challenge, the app accepts via admin API and adds claims; no credentials reach Hydra. |
| Custom claims in the JWT | Land under `ext` (`ext.victual_user_id`). Top-level mirroring is configurable (`oauth2.mirror_top_level_claims`). Untested. |
| Auth code + PKCE | Works. Public clients without `code_challenge` are refused (`oauth2.pkce.enforced_for_public_clients`). |
| Refresh rotation | Works. Reusing an old refresh token is refused **and** its successor stops working (family revoked). |
| client_credentials | Works; token `sub` is the client id, no user. |
| Device flow | Works end to end (`/oauth2/device/auth` -> verify -> `/device` -> login -> consent). Needs `urls.device.*`. |
| RFC 8414 / OIDC discovery | Both published. `registration_endpoint` is **absent until** `webfinger.oidc_discovery.client_registration_url` is set, even with DCR enabled. |
| Dynamic client registration | Works but is **unauthenticated**: anyone who can reach `/oauth2/register` can register a client. Its response (`client_uri: ""`, `contacts: null`) also fails the MCP SDK's schema, so Claude Code could not register. See the next row. |
| App-owned registration | The spike turns Hydra's endpoint off and serves `/register` from the app. The app checks policy (public clients, loopback or https redirects, known scopes) and returns a clean RFC 7591 body. This fixed both problems. |
| **RFC 8707 `resource`** | **Ignored.** No error, no `aud`. A resource Hydra has never heard of is silently accepted and the token has an empty `aud`. Hydra's own `audience` parameter works and is whitelisted per client. |
| 8707 workaround | The consent request still carries the original authorize URL (`request_url`), so the consent app can read `resource`, check it against its own list and set the token audience. `idp-stub.mjs` does this; a disallowed resource is rejected `invalid_target`. Enforcement then lives in Victual's consent code, not in Hydra. |
| Token validation at the resource server | `rs.mjs` verifies RS256 against JWKS, `iss`, `exp`, `nbf`, `aud`. Empty `aud` -> 401; tampered signature -> 401. |
| Revocation | Introspection sees a revoked token as inactive, but a stateless JWT check **keeps accepting it until `exp`** (15 min here). Revocation latency equals the access-token TTL unless the sidecar introspects. |

## Real client: Claude Code 2.1.277 (CLI, this Mac), 2026-10-11

Interactive `/mcp` -> authenticate against `rs.mjs` + Hydra, end to end: **succeeded**.

- 401 with `WWW-Authenticate: Bearer resource_metadata=...`, then it fetched the protected-resource
  metadata, then the authorization-server metadata.
- It **requires** `registration_endpoint` in that metadata and uses **dynamic client registration**: a
  public client (`token_endpoint_auth_method: none`), loopback redirect on a random port
  (`http://localhost:<port>/callback`), `grant_types` authorization_code + refresh_token. No
  pre-registered client was involved. Whether it would use a client ID metadata document instead was not
  tested.
- It requests **every scope in the resource's `scopes_supported`** plus `offline_access`, including
  `victual.write`. Scope narrowing is therefore the consent app's job.
- It sent `resource` (the only way the token got its `aud`, via the consent shim) and the token was accepted
  by the resource server, which then served `initialize` / `notifications/initialized` / `tools/list`.

Claude Desktop, claude.ai custom connectors and the iOS/macOS apps were not tested and may differ. The
claude.ai connectors in particular cannot reach `localhost`.

## Not answered

- Postgres-backed Hydra, two-replica behaviour, Nix image build, licence review.
- Authentik as the swap target (the actual acceptance criterion).
- Whether the tool call itself carries a token the sidecar can map onward to a Victual user (the spike only
  proves the token is accepted and carries `ext.victual_user_id`).
