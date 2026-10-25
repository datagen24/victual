# Spike: Keycloak compatibility with ADR-0055 and ADR-0056

Throwaway. This spike checks what Keycloak does against the clauses of the Proposed
[ADR-0055](../../../docs/adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
and [ADR-0056](../../../docs/adr/0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md)
that depend on the authorization server. Keycloak is the candidate named in ADR-0055
decision 2, which sets two gates: token exchange that meets decision 7 without a Preview
feature, and fit with Victual-owned login. Each case has a positive or negative expectation
taken from a named clause, and the probe records what happened. It writes no Victual code and
accepts no record. A FAIL is a result: no expectation was weakened to make a case pass.

Keycloak **26.8.1** (`quay.io/keycloak/keycloak:26.8.1`, newest release on 2026-10-25;
`start-dev`, OpenJDK 21), PostgreSQL 16, run on 2026-10-25 on the shared podman VM
(4 CPU / 8 GiB, arm64). Server capped at 1200 MiB, PostgreSQL at 384 MiB.

## Run

```bash
cd .devtools/spikes/oauth-keycloak
./run.sh up              # PostgreSQL + Keycloak on one podman network, 127.0.0.1:8180
node setup.mjs           # realm, clients, client scopes, users, via the admin API
node probe.mjs           # core cases; prints the table and writes results.json
./run.sh down
./run.sh up preview      # adds token-exchange-delegation, parameterized-scopes, admin-fine-grained-authz:v2
node setup.mjs && node probe.mjs --preview
./run.sh down
./run.sh readonly        # second instance on a read-only root filesystem, :8181 (needs ./run.sh up)
./run.sh stats           # memory of both containers
```

No npm install is needed: the probe uses `node:crypto` and `fetch` only. Every secret in
these files is a spike-only value for an instance on `127.0.0.1` with no real data. The core
run takes about 70 s; each scripted login takes about 1 s. `results.json` and `state.json`
are gitignored.

## Gates (2026-10-25)

Same four gates as the [Authentik report](../oauth-authentik/README.md) (G1 to G4), then the
rest of what ADR-0055 decision 2 and the open questions ask of Keycloak. "Config" means a
setting, client scope, client policy or authentication flow created through the admin REST
API in `setup.mjs` or `probe.mjs`: no patched image, no Enterprise licence, no Preview
feature, unless the row says "needs Preview".

| Gate | Result | Cases |
|---|---|---|
| Exchange without Preview (decision 2, gate 1) | met: standard token exchange v2 is on by default and needs a per-client switch. Legacy exchange v1 is the Preview one and was not enabled | G1, P5, P5b, P6, N6 to N13, X6 |
| G1 Exchanger identity: `azp` | met: `azp` in the exchanged token is the authenticated exchanging client, not the target and not the subject token's `azp`. Callers cannot override it | P7b, P5b, X1 to X4b |
| G1 Exchanger identity: `act` | needs Preview: no `act` in the core run (P7). With `token-exchange-delegation`, `parameterized-scopes`, an FGAP v2 permission, a consent-required client and a service account on the sidecar, `act` names the sidecar (P8) | P7, P8, P8c, DP1, DP3 to DP6 |
| G1 Grant identity (per-client revocation key) | not met for the client id: the exchanged token carries `sid` of the subject token and `azp` of the sidecar, and nothing names the originating MCP client. `sid` identifies the session | R1, R2 |
| G2 Dedicated MCP audience | met-with-config: a client scope with an audience mapper (custom audience `http://localhost:3000/mcp`) as a default scope of each MCP client. The adapter must assign that scope to each client it registers (adapter-only for the assignment). `resource` (RFC 8707) is not supported: not met | P2, P4, N1 to N3, A1, A3, N5, A2 |
| G3 Scope ceiling: default | not met: the exchange widens `victual:read` to `victual:write` | N11 |
| G3 Scope ceiling: client policy | met-with-config: the `downscope-assertion-grant-enforcer` executor on a client policy that applies to all clients refuses widening, passes equal and narrower | N11b |
| G3 Scope ceiling: bundle against leaf | not met: Keycloak compares scope names; it holds no bundle-to-leaf expansion. A bundle subject token cannot be exchanged for a leaf, and a leaf cannot be exchanged for the bundle. The same bundle is accepted | N11c |
| G3 Scope ceiling: delegated exchange | not met in the default configuration (Preview): widening succeeded. Whether the client policy covers a delegated exchange is untested | DP2 |
| G4 Code flow with PKCE, scripted login | met: a headless login through the realm's login form replaces the password grant. S256-only is a per-client attribute, and the request without a challenge or with `plain` is refused | P2, N17, N19 |
| G4 Refresh rotation with reuse revoking the family | met-with-config: `revokeRefreshToken` set on the realm. Reuse of an old token is refused and the newer token of the same family is refused too | P10, N16 |
| Revocation | met-with-config: revoking the refresh token, deleting the session and deleting one client's consent each stop refresh and make the exchanged token inactive at introspection. A stateless check accepts the exchanged token until it expires (five minutes). Deleting one client's consent leaves another client's grant intact | R3 to R6, N12 |
| Discovery | met: OIDC and RFC 8414 discovery both served from the issuer path, issuer equals `iss`. `resource_indicators_supported` absent | P1 |
| Registration | met-with-config: anonymous registration refused by default (Trusted Hosts policy with no host). With a trusted host, anonymous callers can register confidential clients and wildcard redirects, so a client policy is needed to limit that | N15, D1, D2 |
| Victual-owned login (decision 2, gate 2) | met-with-config: identity brokering to an upstream OIDC provider creates or links a Keycloak user with no password credential, and the browser flow can be reduced to the redirector. The User Storage SPI was read, not run (untested) | L1, L2, L4 |
| Victual subject in the token | met-with-config: Keycloak's `sub` is its own UUID. Victual's subject can be copied into a claim, but only from a client scope requested at the exchange; a mapper on the client itself is not applied to the exchanged token | L3 |
| Licence, image, runtime | Apache-2.0; image 478 MB (arm64); runs as uid 1000; read-only root filesystem works after a build step with a tmpfs for `/tmp` and the data directory; 520 to 620 MiB for the server after the probe, 50 to 77 MiB for PostgreSQL | I1, `./run.sh readonly`, `./run.sh stats` |

Core run: **49 of 57 cases pass, 7 FAIL, 1 n/a** (P8 needs the Preview run). The seven FAIL
rows are P7, N5, N11, N11c, A2, R2 and L3. Preview run: **11 of 12 pass, 1 FAIL** (DP2).

Cold starts of the core run (`./run.sh down`, `up`, `setup.mjs`, `probe.mjs`):

| Run | Result | Note |
|---|---|---|
| 1 | 45 pass, 10 FAIL, 1 n/a of 56 | D1 and D2 used the network gateway as the registering host (Keycloak saw the container address); L4 expected the upstream URL where the redirector sends the browser to the broker endpoint. Harness fixes, no change to an expectation |
| 2 to 5 | 48 pass, 7 FAIL, 1 n/a of 56 each | L3 diagnostics extended between runs; same verdicts |
| 6 | 49 pass, 7 FAIL, 1 n/a of 57 | X4b added |

## Shape under test

| Client | Plays | Grants |
|---|---|---|
| `victual-spike-mcp-client` | Registered MCP client (public, PKCE); user tokens come from here | code, refresh |
| `victual-spike-mcp-consent` | A public client with Consent required, for the revocation and delegation cases | code, refresh |
| `victual-spike-sidecar` | The MCP sidecar, ADR-0055 decision 7 | standard token exchange, service account |
| `http://localhost:3000/mcp` | A sidecar whose client id is its resource URI (P5b) | standard token exchange |
| `http://localhost:8080/api` | Victual's `/api` audience, as a client with every flow off (Keycloak's `audience` parameter names clients) | none |
| `victual-spike-other`, `-rogue-exchanger`, `-no-exchange-grant`, `-mcp-short`, `-default-scopes` | Negative cases, 3 s tokens, and realm default scopes as Keycloak ships them | as named |

One realm (`victual`), so one issuer. Five-minute access tokens, `revokeRefreshToken` on,
`refreshTokenMaxReuse` 0. `fullScopeAllowed` is off on every client. Audience comes from
client scopes with audience mappers (`victual-mcp-audience`, `victual-api-audience`), and
the scopes `victual:read`, `victual:write`, `victual:stock` and `victual:stock:book` are
client scopes with no mappers. `victual-groups` carries a group membership mapper.

User tokens come from authorization code with PKCE S256 and a scripted login (`lib.mjs`
drives the login form with a cookie jar, and accepts the consent screen when a client
requires it). The password grant is never used for tokens; N19 checks it is refused.

`idp-stub.mjs` is a stub upstream OpenID provider on `127.0.0.1:8190`, reached from the
container as `host.containers.internal`. It stands in for Victual's login. It has no login
screen and treats every authorize request as an already signed-in user whose claims the
probe sets. It is not Victual code and not a model of Victual's design.

The resource-server check in `probe.mjs` is the one ADR-0055 decision 3 describes: an
issuer, a discovery URL and one audience; RS256 against the JWKS, `iss`, `exp` and `nbf`
with 60 s skew, `aud`, and an empty `aud` refused. It contains no Keycloak-specific code.

## Results

Core run; `results.json` holds the exact responses. IDs P1 to P10 and N1 to N19 follow the
Authentik report where the case is the same; the others are new to this spike.

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| G1 | 0055 d2: nothing decision 7 requires relies on a Preview feature | No Preview or experimental feature enabled | None enabled; Preview and disabled: legacy `TOKEN_EXCHANGE`, `TOKEN_EXCHANGE_DELEGATION`, `PARAMETERIZED_SCOPES`, others | pass |
| P1 | 0055 d3: discovery from the issuer | Reachable; issuer equals `iss` | `iss` is `http://localhost:8180/realms/victual`; OIDC and RFC 8414 discovery both 200 with the same issuer; no `resource_indicators_supported` | pass |
| P2 | 0055 d4, 0056 d3: stable opaque subject; MCP audience | 200; `sub` opaque; `aud` has the MCP URI | `sub` is a UUID; `aud` = `[http://localhost:3000/mcp, victual-spike-sidecar]`; `azp` the MCP client; 300 s; `iss` parameter on the redirect | pass |
| P3 | 0055 d4: one subject per user across clients | Same `sub` on reissue and from a second client | Same on both | pass |
| P4 | 0055 d3: sidecar accepts its audience | Accepted | Accepted | pass |
| P5 | 0055 d7: exchange for an `/api` token | 200; same `sub` and `iss`; `aud` = `/api` | As expected; no refresh token | pass |
| P5b | 0055 d3, d7: the MCP audience is the sidecar's own client id | Exchange works for a sidecar whose client id is the URI | 200; `azp` is the URI; `aud` is `/api` | pass |
| P6 | 0055 d3: `/api` accepts the exchanged token | Accepted | Accepted | pass |
| P7 | 0055 d7: exchanged token records the sidecar in `act` | `act` present | **No `act`** | **FAIL** |
| P7b | 0055 d7, open question 8: another claim derived from client authentication names the sidecar | A signed claim equals the authenticating client | `azp` is `victual-spike-sidecar`; the subject token's `azp` was the MCP client. The token-exchange guide documents `azp` as the requester | pass |
| P8 | 0055 d7: delegation records the sidecar in `act` | `act` set | Not run in the core run: see the Preview run | n/a |
| P9 | 0056 d4: group changes reach the next token | Present, absent after removal, present after re-adding | As expected | pass |
| P10 | 0055 d6: refresh tokens rotate | New refresh token differs; same `sub` | As expected; refresh claim ttl 1800 s | pass |
| N16 | 0055 d6: reuse revokes the family | Old token refused; newer token refused after reuse | Old: `400 invalid_grant` (maximum reuse exceeded); newer: `400 invalid_grant` | pass |
| N1 to N3 | 0055 d3: a token for another audience is refused | Refused on `aud` | All refused, including a token from another client of the realm | pass |
| N4 | 0055 d3: tampered token refused | Refused | Bad signature | pass |
| N5 | 0055 d3: `resource` not silently ignored on exchange | An unknown `resource` refused | **200**; the parameter is ignored. The guide says exchange "does not yet have support for the resource parameter" | **FAIL** |
| N6 | 0055 d7: only the trusted sidecar mints `/api` tokens | Refused | A client without the `/api` audience scope: `400 invalid_request`; a client whose scopes allow it but absent from the subject token's `aud`: `403 access_denied` | pass |
| N6b | 0055 d7: the sidecar cannot mint for an audience outside its scopes | Refused | `400 invalid_request` | pass |
| N7 | 0055 d7: a client without the exchange switch cannot exchange | Refused | `400 invalid_request` | pass |
| N8 | 0055 d7: a subject token not audienced to the exchanger is refused | Refused | `403 access_denied` | pass |
| N9 | 0055 d7: tampered subject token refused | Refused | `400 invalid_request` | pass |
| N10 | 0055 d3: one audience per token | Refused | `400 invalid_request` for an unavailable audience. Keycloak allows several audiences when the requester's scopes produce them, so the refusal comes from availability, not from a one-audience rule | pass |
| N11 | 0055 d7: exchange adds no scope the subject token lacked, default configuration | `victual:write` refused or dropped for a read-only token | **200, `scope` includes `victual:write`** | **FAIL** |
| N11b | N11 with the `downscope-assertion-grant-enforcer` client policy | Widening refused; equal and narrower still exchanged | Widening `400 invalid_scope`; equal 200; narrower 200; write-to-write 200 | pass |
| N11c | 0055 d7, open question 7: both comparisons expand bundles into leaves first | Leaf inside the held bundle allowed; bundle from a leaf refused | Bundle subject asking a leaf: `400 invalid_scope`; same bundle: 200; leaf subject asking the bundle: `400 invalid_scope`. Keycloak compares names | **FAIL** |
| N12 | 0055 d4: a disabled user's live token cannot be exchanged | Refused | Exchange `400 invalid_request`; the refresh token `400 invalid_grant`; a stateless check of the same access token still accepts it | pass |
| N13 | 0055 d7: expired subject token refused | Refused | `400 invalid_request` | pass |
| N14 | 0055 d3: expired token refused after 60 s skew | Refused | Expired | pass |
| N15 | 0056 prerequisite 2: registration closed to anonymous callers | Refused | `403`, Trusted Hosts policy: host not trusted | pass |
| N17 | 0055 d6: PKCE S256 only | No challenge and `plain` refused | Both `400 invalid_request` | pass |
| N19 | 0055 d6: no password or implicit grant | Password grant refused for the MCP client | `400 unauthorized_client`. Realm discovery still lists `implicit` and `password`; the per-client switches decide | pass |
| X1 | 0055 d7, q8: another client's secret under the sidecar's id | Refused | `401 unauthorized_client` | pass |
| X2 | Same: the sidecar's id with no secret | Refused | `401 unauthorized_client` | pass |
| X3 | Same: Basic as another client plus the sidecar's `client_id` in the body | Refused, or issued to the authenticated client | `401 unauthorized_client` | pass |
| X4 | Same: extra `azp`, `act`, a second `client_id` | `azp` stays the authenticated client; no `act` | `400 invalid_request` (duplicated parameter) | pass |
| X4b | Same: a valid sidecar request with `azp`, `authorized_party`, `act`, `may_act` fields | `azp` stays the sidecar; no `act` | 200; `azp` is the sidecar; no `act`, no `may_act` | pass |
| X5 | 0055 d7: an `actor_token` without the Preview feature does not become `act` | Refused, or ignored | 200; no `act`; the parameter is ignored | pass |
| X6 | 0055 d7: a public client cannot exchange | Refused | `400 invalid_request` | pass |
| A1 | 0055 d3, q9: the MCP client cannot obtain the `/api` audience scope | Refused | `400 invalid_scope` | pass |
| A2 | 0055 d3, RFC 8707: `resource` at `/authorize` refused when unknown | Unknown value refused | **200** for an unknown value and for the `/api` URI; `aud` unchanged | **FAIL** |
| A3 | 0055 d3: the default `aud` is not the receiver's URI | No MCP audience unless configured; empty `aud` refused | A default client gets `aud` = `account`; with full scope off and realm default scopes, no `aud`, and the check refuses it | pass |
| R1 | 0055 d6, q8: a session identifier survives the exchange | `sid` equal in both tokens | Equal; the guide: exchange "never creates a new user session" | pass |
| R2 | 0055 d6, q8: the exchanged token names the originating MCP client | A claim naming the MCP client | **None**: only `azp` (sidecar) and `sid` | **FAIL** |
| R3 | 0055 d6: revoking the refresh token (RFC 7009) | Refresh refused | Revoke 200; refresh `400 invalid_grant`; user and exchanged tokens inactive at introspection; stateless check accepts the exchanged token; new exchange `400` | pass |
| R4 | 0055 d6: deleting the session by `sid` (admin API) | Same effects | 204; same effects as R3 | pass |
| R5 | 0055 d6: deleting the user's consent for one client | Refresh and exchanged tokens stop | 204; refresh `400 invalid_grant`; tokens inactive; stateless check accepts the exchanged token | pass |
| R6 | 0055 d6: one client's revocation leaves another client's grant | The other client's refresh works | 200 | pass |
| D1 | 0056 prerequisite 2: anonymous registration with only Trusted Hosts configured | Trusted host registers a loopback public client; untrusted redirect hosts refused | Loopback public 201; `https://app.example` and `http://evil.example` 403 (URI not trusted); **confidential client 201**; scope request not granted; **wildcard redirect 201** | pass |
| D2 | 0056 prerequisite 2: a client policy limits anonymous registration | Loopback and https public clients register; http, wildcard and confidential refused | Loopback 201 with S256 set; https 201; `http://app.example` 403; wildcard 403; confidential 403. Executors: `secure-client-uris-pattern`, `pkce-enforcer`, `secure-client-authenticator` (`client-jwt`) | pass |
| L1 | 0055 d2, gate 2: users and passwords stay in Victual (brokering, first login creates the user) | Token for a user with no password credential | 200; user stored with username, email, names, a `victual_subject` attribute and one federated identity; 0 credentials; `sub` is Keycloak's UUID | pass |
| L2 | Same: a pre-created passwordless user is linked on first login | Same user id before and after; still no password | Same id; one federated identity added; 0 password credentials | pass |
| L3 | 0055 d4, 0056 d3: which subject Victual maps | Victual's subject in the user token and the exchanged token | User token: claim `victual_sub` equals the upstream `sub` (IdP attribute mapper, then user attribute mapper on the client). Exchanged token: **absent** with the mapper on the MCP client, the sidecar and the `/api` client. Present when the claim sits in a client scope included in the subject token's scope and requested at the exchange | **FAIL** |
| L4 | 0055 d2, gate 2: local password login can be disabled | No password form; password grant fails for the brokered user | Password grant `invalid_grant` for three guesses; with the realm browser flow reduced to the identity-provider redirector, `/auth` redirects to the broker endpoint, no form, and the login ends as the upstream identity | pass |
| I1 | 0055 prerequisite 3: runs non-root | uid other than 0 | uid 1000; image 478 MB | pass |

Preview run (`./run.sh up preview`, `node probe.mjs --preview`):

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| G1p | 0055 d2: which Preview features are on | Delegation and parameterized scopes | `TOKEN_EXCHANGE_DELEGATION`, `PARAMETERIZED_SCOPES`. FGAP v2 needed no feature switch beyond its default | pass |
| DP0 | Docs: the feature creates a `delegation:client` client scope | Present | Present, optional | pass |
| DP1 | 0055 d7: a delegation scope needs an FGAP v2 delegate permission | No `may_act` without the permission | 200 token; delegation scope dropped; no `may_act` | pass |
| P8 | 0055 d7: delegation records the sidecar in `act` | 200; `act` names the sidecar; `sub` unchanged; `aud` = `/api` | `may_act` in the user token; exchange with `actor_token` (the sidecar's service-account token): `act` = `{sub: <service account id>, client_id: victual-spike-sidecar}`; same `sub`; `aud` `/api`; no refresh token | pass |
| P8c | 0055 d3, d7: `/api` accepts the delegated token | Accepted | Accepted | pass |
| DP2 | 0055 d7: the delegated exchange keeps the scope ceiling | `victual:write` refused or dropped | **200 with `victual:write`**; the client policy was not applied in this run | **FAIL** |
| DP3 | 0055 d7, q8: a caller cannot override `act` | `act` stays the sidecar | `act` and `azp` are the sidecar with forged `act` and `azp` fields | pass |
| DP4 | 0055 d7: an actor token of another client is refused | Refused | `403 access_denied` (client not within the token audience): the refusal comes from the audience check, not from a mismatch between actor token and `may_act` | pass |
| DP5 | 0055 d7: an `actor_token` with no user-granted `may_act` gives no `act` | Refused or no `act` | `400 invalid_request` (invalid `may_act` claim) | pass |
| DP6 | 0055 d7: delegation for an actor with no permission is dropped | No `may_act` for the rogue client | 200 token; no `may_act` | pass |
| DP7 | Docs: the delegation consent is never stored | Stored consent has no delegation scope | Stored scopes: `profile`, `email` | pass |
| DP8 | 0055 d7: a plain exchange in the Preview realm is unchanged | `azp` = sidecar; no `act` | As expected | pass |

## Findings

1. **Exchange meets decision 7 without a Preview feature, apart from `act` and the scope
   ceiling's defaults (G1, P5 to P6, N6 to N13).** Standard token exchange v2 is on by
   default; each requester needs the "Standard token exchange" switch, must be confidential,
   and must be in the subject token's `aud` (N7, N8, X6). Trust is explicit in two places:
   the subject token's `aud` must name the exchanger, and the exchanger's client scopes must
   offer the target audience (N6, N6b). The legacy exchange (v1, with FGAP v1 permissions)
   is the Preview feature and was not used.
2. **The exchanger is identified by `azp`, with no Preview feature (P7b, X1 to X4b).** The
   guide documents `azp` as the requester and the probe confirms it: the subject token's
   `azp` was the MCP client and the exchanged token's `azp` is the sidecar. Form fields named
   `azp`, `act`, `may_act` and a second `client_id` change nothing: duplicates are refused,
   and a single valid request ignores the extra fields (X4, X4b). Wrong or missing secrets,
   and Basic authentication for another client, are refused (X1 to X3). This differs from the
   Authentik finding that `azp` named the target. `act` itself needs Preview (P7, P8):
   finding 4. The open-question 8 response accepts a documented alternative claim derived
   from client authentication, and `azp` fits it. If the maintainer wants `act`, the Preview
   run shows what that costs.
3. **The originating MCP client is not in the exchanged token (R1, R2).** `sid` carries
   over; the MCP client's id does not, because `azp` is replaced. Decision 6's per-client
   revocation therefore cannot key on a claim in the exchanged token. It can key on the
   session (`sid`), and the sidecar can keep the subject token's `azp` itself, since it
   verifies that token before the exchange. A Keycloak-side mapper to preserve it was not
   tried (finding 11 explains why a client mapper would not help).
4. **`act` needs Preview, a consent screen at every login, and a service account (P8, DP1
   to DP8).** The documented setup is: `token-exchange-delegation` and `parameterized-scopes`
   features, FGAP v2 with a `delegate` permission for the sidecar's client on users, a
   requesting client with Consent required, and a service account on the sidecar whose
   token is the `actor_token`. The user's token then carries `may_act`; the sidecar sends it
   with the actor token and gets `act`. The consent for the delegation scope is never stored
   (DP7), so the user sees the screen in every login session. The Preview page says the
   feature "is not fully supported". The scope ceiling did not hold in this run (DP2); the
   client policy was not applied there, and whether it covers delegated exchanges is untested.
   DP4 shows an actor token of another client refused, but for the audience reason, so the
   actor-mismatch check itself is not isolated.
5. **Exchange does not cap scope by default (N11); one client policy closes it for equal
   names (N11b).** Setting `downscope-assertion-grant-enforcer` in a client policy that
   applies to all clients refuses widening and passes equal and narrower requests. It is
   standard configuration (client policies are a supported feature), and the guide names it
   for this purpose. It needs to be required, tested deployment configuration, as for
   Authentik.
6. **Bundles are not expanded (N11c).** The executor compares scope names. A token holding
   `victual:stock` cannot be exchanged for `victual:stock:book`, and the reverse is refused
   too. Open question 7's response asks for comparison after expanding bundles into leaves.
   With Keycloak that comparison happens in Victual and the sidecar, not at the server. The
   server's check then covers only same-name requests, so a compromised sidecar that holds a
   bundle can mint that same bundle and nothing wider. Whether that meets "the authorization
   server must refuse widening" depends on what the maintainer counts as widening.
7. **A dedicated MCP audience is client-scope configuration (P2, P4, N1 to N3, A1, A3).**
   An audience mapper with a custom audience, in a client scope that is a default scope of
   the MCP client, yields `aud` of `[<MCP audience>, <sidecar client id>]`. The sidecar's id
   is in `aud` because standard exchange requires the requester there (N8); the sidecar's
   copy of the contract checks for the MCP audience only. A client that does not have the
   scope cannot request it through another client's scope (A1). A client with Keycloak's
   defaults gets `aud` of `account`, and with full scope off gets none (A3). Assigning the
   scope to each registered client is the adapter's work (decision 8). P5b shows that
   naming the sidecar's client id after its resource URI also works.
8. **`resource` is not supported (N5, A2).** The parameter is ignored at `/authorize` and on
   exchange: an unknown value is accepted and `aud` does not change. The guide lists "Resource
   parameter per RFC 8693" as "Not supported yet"
   ([token exchange](https://www.keycloak.org/securing-apps/token-exchange), read 2026-10-25).
   MCP clients that send `resource` still get tokens; the audience comes from scopes.
   Discovery does not advertise `resource_indicators_supported` (P1).
9. **Refresh rotation with reuse detection works (P10, N16).** With `revokeRefreshToken` on
   and `refreshTokenMaxReuse` 0, the old token is refused and the newest token is refused
   too: the family dies. That is the behaviour Authentik did not provide (its G4d).
10. **Revocation works in three places and stays stateless for five minutes (R3 to R6).**
    Revoking the refresh token, deleting the session, and deleting the user's consent for a
    client each stop refresh, and make the user token and the exchanged token inactive at
    introspection. A stateless resource-server check accepts the exchanged token until it
    expires, which fits decision 6's worst case. Deleting the consent for one client left
    another client's grant intact (R6). The per-client case used a client with Consent
    required; for a client without it, no per-client revocation call was tried. The DCR
    policy "Consent Required" would make registered clients consent-required.
11. **Victual-owned login works through identity brokering (L1 to L4).** Keycloak keeps a
    user record per Victual user: username, email, names, and an attribute holding the
    upstream subject, with no password credential. First login creates the user, or links a
    pre-created one (L2). Disabling local login means replacing the realm browser flow with
    the identity-provider redirector (L4); the master realm and its admin console keep their
    passwords. The costs are a copy of identity data in Keycloak (so a deprovisioning path
    from Victual is needed, which was not built) and a second subject: Keycloak's `sub` is a
    UUID, and Victual's subject is a claim (L3). The claim reaches the exchanged token only
    when it is in a client scope that is in the subject token's `scope` and is requested at
    the exchange. A mapper on the client is not applied on exchange. The scope must be in
    the subject token's `scope` for the exchange to accept it, because the ceiling compares
    names, so the claim scope must have "Include in token scope" on. The User Storage SPI,
    the alternative that would make Victual the user store, was read, not run: it needs a
    Java provider deployed into Keycloak with `UserLookupProvider` and
    `CredentialInputValidator`
    ([Server Developer Guide](https://www.keycloak.org/docs/latest/server_development/index.html),
    read 2026-10-25).
12. **Anonymous registration is closed by default and opened by configuration (N15, D1,
    D2).** The Trusted Hosts policy has no trusted host by default, so anonymous
    registration is refused. With a trusted host, anonymous callers registered a confidential
    client and a wildcard redirect (D1). A client policy with `secure-client-uris-pattern`,
    `pkce-enforcer` and `secure-client-authenticator` limited anonymous registration to
    public PKCE clients with https or loopback http redirects (D2). The registration guide
    states that the default client registration provider "is deprecated since Keycloak 26.8
    and will be removed in a future major release", and points to the admin REST API or the
    OpenID registration endpoint
    ([client registration](https://www.keycloak.org/securing-apps/client-registration), read
    2026-10-25). Decision 8 has Victual serve registration and create clients through the
    admin API, which does not depend on that provider.
13. **Discovery is served from the issuer path (P1).** Both OIDC and RFC 8414 documents
    answer, and their issuer equals `iss`. The realm is the issuer, so decision 1's single
    issuer holds per realm, with one signing key set per realm.
14. **Operating footprint (I1, `./run.sh readonly`, `./run.sh stats`).** The image is 478 MB
    on arm64 and runs as uid 1000. A read-only root filesystem works after
    `kc.sh build --db=postgres`, with tmpfs for `/tmp` and `/opt/keycloak/data` and
    `start --optimized`; writing to `/opt/keycloak` fails as intended. The server used 520 to
    620 MiB after the probe (575 MiB idle on the read-only instance), PostgreSQL 50 to 77
    MiB. The licence is Apache-2.0 (`gh api repos/keycloak/keycloak/license`). A Nix-built
    image (ADR-0013) was not tried.

## Not tested

- The User Storage SPI (docs only), and a deprovisioning path from Victual to Keycloak.
- Claude Code or any real MCP client against Keycloak (ADR-0055 prerequisite 5, ADR-0056
  prerequisite 2), and Victual's registration adapter, which does not exist.
- The full swap test (ADR-0055 prerequisite 1) with Victual and the sidecar images. Only the
  probe's resource-server check was pointed at Keycloak.
- Two Keycloak replicas on PostgreSQL (clustering, session sharing): one `start-dev` server,
  and one `start --optimized` server for the read-only check. Production hostname and TLS.
- Whether the client policy of N11b applies to a delegated exchange, and a delegated
  exchange with a subject token from a client without Consent required.
- Preserving the originating client in the exchanged token with a Keycloak mapper.
- Per-client revocation for a client without Consent required.
- Behaviour when a mapper or policy fails; amd64 image size and memory; a Nix-built image.
- The device flow, DPoP, upgrade between Keycloak versions, break-glass with Keycloak
  stopped (ADR-0056 prerequisite 5), back-channel logout, and an Entra ID upstream.
- Any version other than 26.8.1. The Preview run was executed once.
