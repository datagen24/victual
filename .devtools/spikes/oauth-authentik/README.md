# Spike: Authentik compatibility with ADR-0055 and ADR-0056

Throwaway. This spike checks what Authentik does against the clauses of the Proposed
[ADR-0055](../../../docs/adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
and [ADR-0056](../../../docs/adr/0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md)
that depend on the authorization server. Each case has a positive or negative expectation
taken from a named clause, and the probe records what happened. It writes no Victual code
and accepts no record.

Authentik **2026.8.5** (`ghcr.io/goauthentik/server:2026.8.5`, newest release on
2026-10-25), PostgreSQL 16, run on 2026-10-25 on the shared podman VM (4 CPU / 8 GiB).
Server and worker are capped at 1 GiB each.

## Run

```bash
cd .devtools/spikes/oauth-authentik
./run.sh up          # PostgreSQL + Authentik server + worker on one podman network, :9000
node setup.mjs       # providers, applications, scopes, users and a group, via the admin API
node probe.mjs       # cases below; prints the table and writes results.json (about 70 s)
./run.sh down
```

No npm install is needed: the probe uses `node:crypto` only. Every secret in these files is a
spike-only value for an instance on `127.0.0.1` with no real data.

## Shape under test

| Provider (Authentik "client") | Plays | Grants |
|---|---|---|
| `victual-spike-mcp-client` | An MCP client; user tokens come from here | code, refresh, client credentials, password |
| `victual-spike-sidecar` | The MCP sidecar, ADR-0055 decision 7 | client credentials, token exchange; trusts `mcp-client` |
| `victual-spike-api` | Victual's `/api` audience | trusts `sidecar` to mint tokens for it |
| `victual-spike-other`, `-rogue-exchanger`, `-no-exchange-grant`, `-mcp-short` | Negative cases | as named |

All providers use `issuer_mode: global` (one `iss` for the install, ADR-0055 decision 1),
`sub_mode: hashed_user_id`, the same signing key and five-minute access tokens. User tokens
come from the app-password form of the client-credentials grant (Authentik's
machine-to-machine login), so no browser is involved.

The resource-server check in `probe.mjs` is the one ADR-0055 decision 3 describes. Its
configuration is an issuer, a discovery URL and one audience. It checks RS256 against the
JWKS, `iss`, `exp` and `nbf` with 60 s skew, and `aud`, and it refuses an empty `aud`. It
contains no Authentik-specific code.

## Results (2026-10-25, 22 of 25 pass)

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| P1 | 0055 d3: resource servers fetch discovery from the issuer | Discovery reachable from the issuer URL | `iss` is `http://localhost:9000/`; `/.well-known/openid-configuration` there is **404**. Per-provider discovery (`/application/o/<slug>/…`) is 200 and names the same issuer | **FAIL** |
| P2 | 0055 d4, 0056 d3: stable opaque subject | 200; `sub` opaque, no email | 200; hashed `sub`; `aud` = `victual-spike-mcp-client`; 300 s lifetime | pass |
| P3 | 0055 d4: one subject per user across clients | Same `sub` on reissue and from another provider | Same on both | pass |
| P4 | 0055 d3: sidecar accepts its own audience | Accepted | Accepted | pass |
| P5 | 0055 d7: exchange MCP token for an `/api` token | 200; same `sub` and `iss`; `aud` = `/api` | As expected | pass |
| P6 | 0055 d3: `/api` accepts the exchanged token | Accepted | Accepted | pass |
| P7 | 0055 d7: exchanged token records the sidecar in `act` | `act` present | No `act`; `azp` is the target (`victual-spike-api`), so nothing names the sidecar | **FAIL** |
| P8 | 0055 d7: delegation records the sidecar in `act` | 200; `act.sub` set | 200; `act.sub` set. **The Actor had to be created with `ak shell`**: see finding 3 | pass, unsupported setup |
| P9 | 0056 d4 and prerequisite 4: group changes reach the next token | Present, absent after removal, present after re-adding | As expected | pass |
| N1–N3 | 0055 d3: a token for another audience is refused | Refused on `aud` | All refused on `aud`, including a token from an unrelated provider of the same issuer | pass |
| N4 | 0055 d3: tampered token refused | Refused | Bad signature | pass |
| N5 | 0055 d3: `resource` not silently ignored on exchange | `invalid_target` | `400 invalid_target` | pass |
| N6 | 0055 d7: only the trusted sidecar mints `/api` tokens | `invalid_target` | `400 invalid_target` | pass |
| N7 | 0055 d7: a client without the exchange grant cannot exchange | Refused | `400 invalid_grant` | pass |
| N8 | 0055 d7: subject token from an untrusted provider refused | `invalid_grant` | `400 invalid_grant` | pass |
| N9 | 0055 d7: tampered subject token refused | `invalid_grant` | `400 invalid_grant` | pass |
| N10 | 0055 d3: one audience per token | `invalid_target` | `400 invalid_target` | pass |
| N11 | 0055 d7: exchange adds no scope the subject token lacked | `victual:write` refused or dropped for a read-only subject token | **200, `scope=openid victual:write victual:read`** | **FAIL** |
| N11b | N11 with a scope-ceiling expression policy on the `/api` application | Widening refused; equal scope still exchanged | Widening `400 invalid_grant`; equal scope 200; write-to-write 200 | pass |
| N12 | 0055 d4: a disabled user's live token cannot be exchanged | Refused | `400 invalid_grant` | pass |
| N13 | 0055 d7: expired subject token refused | `invalid_grant` | `400 invalid_grant` | pass |
| N14 | 0055 d3: expired token refused after 60 s skew | Refused | Expired | pass |
| N15 | 0056 prerequisite 2: registration closed to anonymous callers | Refused | 401 without a token and 401 with a user token that lacks the registration scope | pass |

`results.json` holds the same table with the exact responses.

## Findings

1. **Token exchange works (ADR-0055 prerequisite 4, Authentik half: met for the core
   path).** P5, P6 and N5 to N13 show it. The exchanged token keeps the user's `sub` and the
   install's `iss`, and takes the target provider's `client_id` as `aud`. Trust is explicit:
   the sidecar must federate with the client's provider, and `/api` must federate with the
   sidecar. Anything else is refused with the error the RFC names.
2. **Exchange does not cap scope at the subject token (N11).** The exchanged token's scopes
   are bounded only by the target provider's scope mappings. A read-only MCP token became a
   write `/api` token. Victual's intersection with the user's permissions still holds, so
   this cannot exceed the user, but it defeats the consent the user gave the client.
   Configuration closes it (N11b): an expression policy on the `/api` application compares
   the requested scope with the subject token's. That policy is Authentik configuration,
   not standard protocol surface, so the swap test has to name it.
3. **`act` needs an Actor that open-source Authentik cannot create (P7, P8).** A plain
   exchange records nothing about the caller. Delegation sets `act.sub`, but only with an
   actor token for an Authentik Actor. Ownerless Actors are created through the Agents API,
   which requires an Enterprise licence (`authentik/enterprise/agents/api.py`,
   `EnterpriseRequiredMixin`). P8 used `ak shell`, which is not a supported setup. Without
   it, Victual cannot tell from the token that the sidecar made the call.
4. **The issuer does not serve discovery (P1).** With `issuer_mode: global`, `iss` is the
   install root, and no discovery document is served there. Each provider has its own,
   naming that same issuer. A resource server therefore needs a discovery URL configured
   beside the issuer. That is still configuration only, but ADR-0055 decision 3 says
   "the issuer URL" alone. With `per_provider` (the default), discovery works but each
   provider is its own issuer, which conflicts with decision 1's one issuer.
5. **In Authentik, `aud` names the client, not the resource.** Every token's `aud` is the
   `client_id` of the provider that issued it (P2, P5). `resource` is ignored at
   `/authorize` (static read: `views/authorize.py` never reads it) and refused on exchange
   (N5). A token an MCP client obtains for the sidecar is therefore audienced to that client.
   The sidecar must accept the `client_id` of every client registered through Victual, or
   decide another binding. In the spike the sidecar's configured audience is the one
   client's id.
6. **With one issuer and one signing key, `aud` is the only barrier between providers (N3).**
   A token from an unrelated provider verifies against the same JWKS and is refused only on
   `aud`. ADR-0055 decision 3 already requires the `aud` check; on Authentik, a resource
   server that skips it accepts every other client's tokens.
7. **Registration needs a bearer token (N15).** Authentik's dynamic registration refuses
   anonymous callers. Claude Code registers anonymously (Hydra spike), so it cannot register
   with Authentik directly. That fits ADR-0055 decision 8 (Victual serves the registration
   policy and creates the client through the authorization server's admin API). With
   Authentik that adapter creates one provider per client, which feeds finding 5.

## Not tested

- Authorization code with PKCE, refresh rotation and reuse detection, and the device flow:
  they need a browser or a scripted login flow. The probe uses the app-password grant for
  user tokens.
- Claude Code or any real MCP client against Authentik (ADR-0055 prerequisite 5, ADR-0056
  prerequisite 2), and Victual's registration adapter, which does not exist.
- The full swap test (ADR-0055 prerequisite 1) with Victual and the sidecar images. Only the
  probe's resource-server check was pointed at Authentik.
- Break-glass with Authentik stopped (ADR-0056 prerequisite 5), back-channel logout, and an
  Entra ID upstream source.
- Two Authentik replicas, and any version other than 2026.8.5.
