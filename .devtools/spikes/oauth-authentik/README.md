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
node setup.mjs       # providers, applications, scopes, mappings, policies, users, via the admin API
node probe.mjs       # cases below; prints the table and writes results.json
./run.sh down
```

No npm install is needed: the probe uses `node:crypto` only. Every secret in these files is a
spike-only value for an instance on `127.0.0.1` with no real data. The expiry cases sleep
66 s, and each scripted login takes about 1 s.

## Gates (2026-10-25, second pass)

The first report left three gates open. Each line says what "supported configuration" means
here: settings, scope mappings and expression policies created through the admin API. None of
it needed `ak shell`, a patched image or an Enterprise licence, except case P8 as before.

| Gate | Result |
|---|---|
| G1 Exchanger identity | met-with-config: a scope mapping on every `/api` scope signs `victual_exchanger` (the authenticated exchanging client) and `victual_grant` (`azp`, `sid`, `jti` of the subject token) into each exchanged token. Not `act`: the same mapping can emit `act` (G1f), but Authentik's own `act` still needs an Actor (P7, P8) |
| G2 Dedicated MCP audience | met-with-config: a scope mapping on each MCP client's provider returns `aud` as `[client_id, <MCP audience>]`. Victual's registration adapter must attach that mapping to every provider it creates |
| G3 Scope ceiling as required configuration | met-with-config: an expression policy bound to the `/api` application by `setup.mjs`. It can hold a bundle-to-leaf table (G3a); that table is a second copy of Victual's bundles |
| G4 Code flow with PKCE, scripted login | met: a headless login through the flow executor API replaces the app-password grant for user tokens (G4a, G4b). S256-only is met-with-config (an expression policy, G4c). Refresh-token reuse does not revoke the family: not met (G4d) |

Overall: 40 of 45 cases pass on a cold start, twice. The five that do not are P1, P7, N11,
G4c0 and G4d. P1, P7 and N11 failed in the first report. G4c0 and G4d are Authentik's native
behaviour and are reported as evidence.

## Shape under test

| Provider (Authentik "client") | Plays | Grants |
|---|---|---|
| `victual-spike-mcp-client`, `-mcp-client-b` | Two registered MCP clients; user tokens come from here | code, refresh, client credentials, password |
| `victual-spike-sidecar` | The MCP sidecar, ADR-0055 decision 7 | client credentials, token exchange; trusts both MCP clients |
| `victual-spike-sidecar-b` | A second legitimate exchanger, for G1 | as the sidecar; trusts `mcp-client` |
| `victual-spike-api` | Victual's `/api` audience | trusts both exchangers to mint tokens for it |
| `victual-spike-other`, `-rogue-exchanger`, `-no-exchange-grant`, `-mcp-short`, `-mcp-scalar` | Negative cases and the scalar `aud` comparison | as named |

All providers use `issuer_mode: global` (one `iss` for the install, ADR-0055 decision 1),
`sub_mode: hashed_user_id`, the same signing key and five-minute access tokens.

`setup.mjs` now creates this required deployment configuration, so the probe tests the
configuration the ADRs would require:

- A scope-ceiling expression policy bound to the `/api` application (G3).
- A scope mapping named `exchanger` on each `/api` scope (`victual:read`, `victual:write`,
  `victual:admin`), whose expression is `EXCHANGER_EXPRESSION` in `setup.mjs` (G1).
- A scope mapping named `mcp-aud` on each of the MCP providers' scopes (`openid`,
  `victual:read`, `victual:write`, the bundle scope), returning `aud` (G2).
- A PKCE policy bound to the two MCP client applications (G4c).

User tokens come from authorization code with PKCE S256 and a scripted login for the
providers that have the code grant (`mcp-client`, `mcp-client-b`, `other`). The app-password
grant remains for `mcp-short` (two-second tokens), for the Actor in P8, and for a few G cases
that need a token from a provider without a login.

The resource-server check in `probe.mjs` is the one ADR-0055 decision 3 describes. Its
configuration is an issuer, a discovery URL and one audience. It checks RS256 against the
JWKS, `iss`, `exp` and `nbf` with 60 s skew, and `aud`, and it refuses an empty `aud`. It
contains no Authentik-specific code. The sidecar's copy now holds the one fixed MCP audience
(`victual-spike-mcp-resource`) instead of one client's `client_id`.

## Changes to the existing cases

Setup changed three things on purpose. No verdict changed: P1, P7 and N11 failed before and
fail now; the other 22 passed before and pass now.

- The scope-ceiling policy is bound by default. N11 now unbinds it for the length of the case
  and rebinds it, so N11 still shows Authentik's native escalation. N11b runs against the
  default binding instead of creating and deleting a policy.
- The sidecar's resource-server check uses the MCP audience, and the MCP providers' tokens carry
  it. P2 shows the new `aud`; P4, N1 and N2 are checked against the new audience.
- User tokens in the P, N and G cases use the code flow (described under "Shape under test").

## Results (2026-10-25, 40 of 45 pass)

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| P1 | 0055 d3: resource servers fetch discovery from the issuer | Discovery reachable from the issuer URL | `iss` is `http://localhost:9000/`; `/.well-known/openid-configuration` there is **404**. Per-provider discovery (`/application/o/<slug>/…`) is 200 and names the same issuer | **FAIL** |
| P2 | 0055 d4, 0056 d3: stable opaque subject | 200; `sub` opaque, no email | 200; hashed `sub`; `aud` = `[victual-spike-mcp-client, victual-spike-mcp-resource]`; 300 s lifetime | pass |
| P3 | 0055 d4: one subject per user across clients | Same `sub` on reissue and from another provider | Same on both | pass |
| P4 | 0055 d3: sidecar accepts its own audience | Accepted | Accepted (fixed MCP audience) | pass |
| P5 | 0055 d7: exchange MCP token for an `/api` token | 200; same `sub` and `iss`; `aud` = `/api` | As expected | pass |
| P6 | 0055 d3: `/api` accepts the exchanged token | Accepted | Accepted | pass |
| P7 | 0055 d7: exchanged token records the sidecar in `act` | `act` present | No `act`; `azp` is the target (`victual-spike-api`), so nothing in Authentik's own claims names the sidecar. The G1 claim `victual_exchanger` does | **FAIL** |
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
| N11 | 0055 d7: exchange adds no scope the subject token lacked, scope-ceiling policy unbound | `victual:write` refused or dropped for a read-only subject token | **200, `scope=victual:write victual:read openid`** | **FAIL** |
| N11b | N11 with the policy that `setup.mjs` binds | Widening refused; equal scope still exchanged | Widening `400 invalid_grant`; equal scope 200; write-to-write 200 | pass |
| N12 | 0055 d4: a disabled user's live token cannot be exchanged | Refused | `400 invalid_grant` | pass |
| N13 | 0055 d7: expired subject token refused | `invalid_grant` | `400 invalid_grant` | pass |
| N14 | 0055 d3: expired token refused after 60 s skew | Refused | Expired | pass |
| N15 | 0056 prerequisite 2: registration closed to anonymous callers | Refused | 401 without a token and 401 with a user token that lacks the registration scope | pass |
| G1a | 0055 d7, open question 8: a signed claim names the authenticated exchanger | `victual_exchanger` is the exchanging client's id; a second exchanger gets its own; `/api` accepts | `victual-spike-sidecar` and `victual-spike-sidecar-b` respectively; `/api` accepts | pass |
| G1b | Same: callers cannot inject or override it | Basic header wins over a body `client_id` and over claim-shaped form fields | Basic sidecar with body `client_id` of sidecar-b and fields `victual_exchanger`, `victual_grant`, `act`, `azp`, `aud`, `sub`: claim names the sidecar, no `act`, `aud` and `sub` unchanged. Basic sidecar-b with body sidecar: names sidecar-b. Duplicate body `client_id`: names the client whose secret matched | pass |
| G1c | Same: a `client_id` without a valid secret gets no token | All refused | Wrong secret, another client's secret, no secret, mismatched Basic: all `400 invalid_client` | pass |
| G1d | Same: a user attribute with the claim's name cannot override it | Claim keeps the authenticated value although a mapping spreads `user.attributes` | Claim and `victual_grant.azp` kept. An attribute named `act` did get through, because Authentik reserves no claim names | pass |
| G1e | 0055 d7: on every exchanged `/api` token, on no other | Present for each accepted scope set; exchanges without a `victual:*` scope refused; absent elsewhere | Present for four scope sets; `openid` only, no scope and an unknown scope: `400 invalid_grant`; absent from the `/api` provider's own token, the MCP user token and an exchange issued without `audience` | pass |
| G1f | Same: `act` populated by the same derivation | `act.sub` is the authenticated exchanger even when the body names another client and sets `act` | `act.sub` is the sidecar in both | pass |
| G1g | 0055 d7, 0056 d11: the token identifies the originating grant | `victual_grant.azp` is the MCP client; `sid` stable across a refresh and new for a second login; `jti` new per token | As expected | pass |
| G1h | 0055 d6: revoking the grant at the authorization server stops exchanges | Exchange of a deleted subject access token refused; an exchanged token already issued stays valid | `400 invalid_grant`; the earlier exchanged token is still accepted by `/api` | pass |
| G2a | 0055 d3, open question 9: one fixed MCP audience accepts two registered clients | Both accepted by a sidecar configured with the single audience | Both accepted. A sidecar pinned to `mcp-client`'s `client_id` refuses `mcp-client-b` (the old behaviour) | pass |
| G2b | Same: the sidecar refuses tokens without the audience | Refused: unbound client, `/api` client, exchanged token, bound client's token with no bound scope, unsigned token | All refused on `aud` or signature | pass |
| G2c | 0055 d3: a client not bound to the resource cannot obtain it | Unbound client asking `resource` and `audience`: no MCP audience. Bound client sending other values: `aud` unchanged | As expected | pass |
| G2d | 0055 d3: shape of `aud` | List form keeps the `client_id` in the `id_token`; scalar form drops it | List: `id_token` `aud` has both values. Scalar: `id_token` `aud` is only the MCP audience | pass |
| G2e | 0055 d7: the override does not break the exchange | The sidecar exchanges the second client's token; `/api` accepts | 200; accepted | pass |
| G3a | 0055 d7, open question 7: bundle-aware ceiling | Bundle held: leaves inside accepted, a leaf outside refused | `victual:read` and `victual:write` 200 with the leaf scope; `victual:admin` `400 invalid_grant` | pass |
| G3b | Same: a plain set comparison with bundles | The covered leaf wrongly refused; the outside leaf refused | Both `400 invalid_grant` | pass |
| G4a | 0055 d6: code flow with PKCE S256 and a scripted login | 200; `sid`; `amr` `pwd`; same `sub` as the app-password grant | As expected | pass |
| G4b | Same: verifier, replay and password enforced | All refused | Wrong verifier `invalid_grant`; replayed code `invalid_grant`; wrong password refused in the flow | pass |
| G4c | 0055 d6: S256 only, with the policy `setup.mjs` binds | No challenge and `plain` refused at `/authorize`; S256 works | Refused (the authorize page shows no redirect); S256 200 | pass |
| G4c0 | Same, policy unbound | Refused | Both **200**: Authentik accepts a code request with no challenge, or with `plain` | **FAIL** |
| G4d | 0055 d6: refresh rotation with reuse revoking the family | Old token refused after rotation; newest also refused after the reuse | Rotation works; old refresh token `invalid_grant`; the newest refresh token still **200** | **FAIL** |

`results.json` holds the same table with the exact responses.

## Findings

1. **Token exchange works (ADR-0055 prerequisite 4, Authentik half: met for the core
   path).** P5, P6 and N5 to N13 show it. The exchanged token keeps the user's `sub` and the
   install's `iss`, and takes the target provider's `client_id` as `aud`. Trust is explicit:
   the sidecar must federate with the client's provider, and `/api` must federate with the
   sidecar. Anything else is refused with the error the RFC names.
2. **Exchange does not cap scope at the subject token (N11), and one policy closes it (N11b,
   G3).** The exchanged token's scopes are bounded only by the target provider's scope mappings.
   `setup.mjs` now binds the ceiling policy, so the deployment configuration is the tested
   one. The policy sees the verified subject token as `oauth_jwt` and the scopes that will be
   granted as `oauth_scopes` (`authentik/providers/oauth2/token/base.py`, `check_policy_access`;
   `token_exchange.py`, `parse`). It also requires at least one `victual:*` scope, which G1
   relies on. Without the policy, a read-only token became a write token. The policy is
   Authentik configuration, not standard protocol surface, so the swap test has to name it.
3. **Bundles are the policy's weak point (G3a, G3b).** The policy can hold a bundle-to-leaf
   table in its expression (`BUNDLES` in `setup.mjs`). With it, a leaf inside the held bundle
   is accepted and a leaf outside is refused. Without it, a plain set comparison refuses the
   covered leaf. Whether to keep the table there is a maintainer decision. The cost is a second
   copy of Victual's bundle definitions, edited by hand in an admin policy and not covered by
   Victual's tests. A convention such as "bundle `victual:bundle:x` covers `victual:x:*`" would
   remove the table, but it fixes Victual's scope naming to what a string prefix can express.
   Victual's own expansion stays authoritative: decision 7 already has Victual and the sidecar
   re-check the result. The policy is a second fence for a compromised sidecar, and it can be
   coarser than Victual's.
4. **The exchanger can be signed into the token by configuration (G1).** The scope-mapping
   expression receives `user`, `http_request`, `provider` (the target) and `token` (the access
   token being built); it receives nothing that names the requesting provider
   (`authentik/providers/oauth2/models.py`, `ScopeMapping.evaluate`; `views/userinfo.py`,
   `get_claims`). The only trace is the HTTP request. The expression therefore calls
   `extract_client_auth`, the function `TokenView.post` used to find the provider
   (`authentik/providers/oauth2/utils.py`), so it reads the Basic header first and the form
   `client_id` only without one. `TokenRequest.parse` has already compared that provider's
   secret for the exchange grant (`token/base.py`) before any mapping runs. That ordering is why
   the Basic-header-plus-body-`client_id` injection in G1b fails to move the claim, and why a
   `client_id` without a valid secret never reaches a mapping (G1c). The expression emits
   nothing for a public client, because its `client_id` is not authenticated.
   - Claims from mapping output replace dataclass fields. `IDToken.to_dict` applies
     `id_dict.update(self.claims)` after `act` and `aud` are set (`id_token.py`), which is
     what makes both G1f (`act`) and G2 (`aud`) possible.
   - The claim is not `act` by default. A mapping's `act` replaces the Actor's `act` (static
     read of `to_dict`, not run), which would hide an Actor-based delegation (P8). Emitting `act`
     is available if the maintainer wants it (G1f). Using a separate claim name fits ADR-0056's
     `attribution_source` column, which names the claim.
   - Authentik reserves no claim names, and merges mappings in `scope_name` order
     (`get_claims`, `order_by("scope_name")`, so the database collation decides). The exchanger
     mapping sits on the `victual:*` scopes, which sort after `profile` and `openid`. G1d
     shows a mapping that spreads user attributes on `profile` cannot override it. A mapping
     that emits the same key on a scope sorting later would, and an attribute named `act`
     already got through in G1d. This is a rule for administrators: no mapping may emit
     `victual_exchanger`, `victual_grant`, `act` or `aud` from user data.
   - The expression imports `authentik.providers.oauth2.utils` and `...models`. Those are
     internal modules, not a documented extension API, so the expression needs a retest on every
     Authentik upgrade.
5. **The originating grant is identified by `azp`, `sid` and `jti` of the subject token (G1g).**
   Exchange issues a token with no session, so `sid` is not carried over
   (`views/token.py`, `create_token_exchange_response`); the mapping copies it, along with `azp`
   and `jti`, from the subject token that the exchange has already verified (the expression
   only decodes the payload). `azp` is the MCP client, which is what decision 6's per-client
   revocation keys on. `sid` is the login session: it stayed the same after a refresh and
   changed on a second login. `jti` changes on every token. Nothing identifies the refresh token
   or an Authentik grant row. Two caveats: tokens from the app-password grant carry no `sid`,
   and the refresh token outlives the login session in Authentik (`RefreshToken.session` is
   `SET_DEFAULT`, static read), so ending a session does not revoke the grant. Revoking at the
   authorization server means deleting the access and refresh token rows (admin API
   `/oauth2/access_tokens/`, `/oauth2/refresh_tokens/`): after the access token row is deleted,
   the exchange is refused (G1h), while exchanged tokens already issued stay valid until they
   expire. That fits decision 6's five-minute worst case.
6. **A dedicated MCP audience is configuration plus an adapter step (G2).** The scope mapping
   returns `aud` as `[client_id, <MCP audience>]`, replacing `aud`
   (`id_token.py`, `to_dict`). Two registered clients are accepted by a sidecar configured
   with the single audience; an unbound client, the `/api` client, an exchanged token and an
   unsigned token are refused (G2a, G2b). A client not bound to the mapping cannot obtain the
   audience by any request parameter (G2c): scope mappings belong to the provider.
   - The list form keeps the client's own `client_id` in `aud`, so the OIDC `id_token` still names
     its client. The scalar form puts only the MCP audience into the `id_token`, which an OIDC
     client validating `aud` against its `client_id` would reject (G2d). The list has no `azp`
     in the `id_token`, which OIDC Core recommends for a multi-valued `aud`; MCP clients that
     use only the access token are not affected, and none was run here.
   - Authentik merges lists by appending, so one mapping per scope would repeat the audience.
     Each bound scope's mapping emits it only when it is the alphabetically first granted
     bound scope (`audExpression` in `setup.mjs`). A token granted none of the bound scopes
     (for example `profile` only) carries no MCP audience and the sidecar refuses it (G2b).
     That fails closed.
   - "Binding" means: the provider's `property_mappings` list includes the `mcp-aud` mappings for
     the scopes the client can receive. That is configuration. Creating it for every client is
     the adapter's work, because registration policy is Victual's (ADR-0055 decision 8). The
     adapter would POST the provider with those mapping ids through the admin API. Authentik's
     dynamic client registration has `override_property_mappings`
     (`authentik/providers/oauth2/models.py`, `OAuth2DynamicClientRegistration`) that could carry
     the same mappings; that was read and not run. Verdict: configuration in Authentik, adapter
     in Victual.
7. **Authorization code with PKCE works headless (G4a, G4b), with two gaps.** The login runs
   through `/api/v3/flows/executor/<slug>/` with a hand-kept cookie jar and the CSRF header; the
   default authentication flow needs only the identification and password stages here. S256 is
   not enforced by Authentik: a code request with no challenge, or with `plain`, succeeds
   (G4c0; `token/authorization_code.py`). The authorize-time policy context carries the challenge
   and its method (`views/authorize.py`, `modify_policy_request`), so a bound expression policy
   refuses both (G4c). The refusal shows as an authorize page, not an OAuth error redirect.
   Refresh tokens rotate, and the old one is refused, but reuse does not revoke the newest one
   (G4d; `views/token.py`, `create_refresh_response` marks only the used token revoked and
   raises a suspicious-request event in `refresh_token.py`). ADR-0055 decision 6 asks for
   family revocation. No setting offered it. The consequence: an attacker who replays a stolen
   old refresh token gets a refusal, and the legitimate holder's chain stays valid.
8. **The issuer does not serve discovery (P1).** With `issuer_mode: global`, `iss` is the
   install root, and no discovery document is served there. Each provider has its own,
   naming that same issuer. A resource server therefore needs a discovery URL configured
   beside the issuer. That is still configuration only, and ADR-0055 decision 3 now permits
   it. With `per_provider` (the default), discovery works but each provider is its own issuer,
   which conflicts with decision 1's one issuer.
9. **With one issuer and one signing key, `aud` is the only barrier between providers (N3).**
   A token from an unrelated provider verifies against the same JWKS and is refused only on
   `aud`. A resource server that skips the `aud` check accepts every other client's tokens.
10. **`act` still needs an Actor that open-source Authentik cannot create (P7, P8).** Delegation
    sets `act.sub`, but only with an actor token for an Authentik Actor. Ownerless Actors are
    created through the Agents API, which requires an Enterprise licence
    (`authentik/enterprise/agents/api.py`, `EnterpriseRequiredMixin`). P8 used `ak shell`, which
    is not a supported setup. The G1 claim replaces what `act` would have said for the
    exchanger.
11. **Registration needs a bearer token (N15).** Authentik's dynamic registration refuses
    anonymous callers. Claude Code registers anonymously (Hydra spike), so it cannot register
    with Authentik directly. That fits ADR-0055 decision 8 (Victual serves the registration
    policy and creates the client through the authorization server's admin API). With
    Authentik that adapter creates one provider per client and attaches the mappings from
    finding 6.

## Not tested

- The device flow, and the S256 policy and `mcp-aud` mappings on a public client.
- Claude Code or any real MCP client against Authentik (ADR-0055 prerequisite 5, ADR-0056
  prerequisite 2), and Victual's registration adapter, which does not exist.
- The full swap test (ADR-0055 prerequisite 1) with Victual and the sidecar images. Only the
  probe's resource-server check was pointed at Authentik.
- Behaviour when a mapping or policy expression raises an error. Static read only: a policy
  that raises returns a failing result (`authentik/policies/expression/evaluator.py`), and a
  mapping that raises is logged and skipped (`views/userinfo.py`, `get_claims`), so its claims
  are missing. A token without `victual_exchanger` must therefore make Victual refuse it.
- The branch of the exchanger expression that skips a public client.
- Delegation combined with the exchanger mapping; a mapping's `act` replacing the Actor's.
- Break-glass with Authentik stopped (ADR-0056 prerequisite 5), back-channel logout, and an
  Entra ID upstream source.
- Two Authentik replicas, and any version other than 2026.8.5.
