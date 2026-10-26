# Spike: Keycloak compatibility with ADR-0055 and ADR-0056

Throwaway. This spike checks what Keycloak does against the clauses of the Proposed
[ADR-0055](../../../docs/adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
and [ADR-0056](../../../docs/adr/0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md)
that depend on the authorization server. Keycloak is the candidate named in ADR-0055
decision 2.

When the spike ran, decision 2 set two gates: token exchange that meets
decision 7 without a Preview feature, and fit with Victual-owned login. The maintainer's
2026-10-25 clarification of ADR-0055 (question 10, PR 1124) removed the second gate: the
identity provider owns ordinary identities, and Victual links `(issuer, subject)` to its
application records. Cases L1 to L4 stay as evidence of what brokering can do; they no longer
decide the candidate.

Each case has a positive or negative expectation
taken from a named clause, and the probe records what happened. It writes no Victual code and
accepts no record. A FAIL is a result: no expectation was weakened to make a case pass.

Keycloak **26.8.1** (`quay.io/keycloak/keycloak:26.8.1`, newest release on 2026-10-25;
`start-dev`, OpenJDK 21), PostgreSQL 16, run on 2026-10-25 on the shared podman VM
(4 CPU / 8 GiB, arm64). Server capped at 1200 MiB, PostgreSQL at 384 MiB.

The 2026-10-26 follow-up tests the maintainer's answers to ADR-0055 questions 11 to 14. It adds
three things: gate K1 (an exchanged token keeps its originating grant, prerequisite 10), gate K2
(the registration adapter binds each MCP client to the MCP audience, prerequisite 11), and the
[configuration inventory](#configuration-inventory-prerequisite-8) (prerequisite 8). It also
corrects earlier revocation evidence: see finding 10 and case K1i. The client policies that the
earlier run installed from `probe.mjs` now live in `setup.mjs`, so the deployment configuration
is versioned in one place. `adapter.mjs` simulates Victual's registration adapter.

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

No npm install is needed: the probe uses `node:crypto` and `fetch` only. `run.sh` generates the
PostgreSQL and admin passwords into a gitignored `.secrets.env` on first start; the other
values in these files are spike-only, for an instance on `127.0.0.1` with no real data. The core
run takes about 75 s; each scripted login takes about 1 s. `results.json` and `state.json`
are gitignored.

Files: `setup.mjs` (realm, clients, client scopes, client policies, users), `adapter.mjs` (the
adapter simulation: `registerMcpClient` and `deregisterMcpClient`, used by `setup.mjs` and by the
K2 cases), `probe.mjs` (the cases), `lib.mjs` (admin REST, scripted login, resource-server
check), `idp-stub.mjs` (stub upstream provider for the L cases), `run.sh`.

## Gates (2026-10-25, extended 2026-10-26)

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
| G1 Grant identity (per-client revocation key) | not met by default: the exchanged token carries `sid` of the subject token and `azp` of the sidecar, and nothing names the originating MCP client. K1 closes this with configuration | R1, R2, K1a to K1c |
| G2 Dedicated MCP audience | met-with-config: a client scope with an audience mapper (custom audience `http://localhost:3000/mcp`) as a default scope of each MCP client, assigned by the registration adapter (K2 tests the adapter's creates). `resource` (RFC 8707) is not supported: not met | P2, P4, N1 to N3, A1, A3, N5, A2, K2a to K2e |
| G3 Scope ceiling: default | not met: the exchange widens `victual:read` to `victual:write` | N11 |
| G3 Scope ceiling: client policy | met-with-config: the `downscope-assertion-grant-enforcer` executor on a client policy that applies to all clients refuses widening, passes equal and narrower | N11b |
| G3 Scope ceiling: bundle against leaf | not met: Keycloak compares scope names; it holds no bundle-to-leaf expansion. A bundle subject token cannot be exchanged for a leaf, and a leaf cannot be exchanged for the bundle. The same bundle is accepted | N11c |
| G3 Scope ceiling: delegated exchange | met-with-config (Preview): the `victual-downscope-all` policy refuses widening in a delegated exchange too. The 2026-10-25 run reported a FAIL because the policy was installed by the core probe only, not in the Preview realm. `setup.mjs` now installs it in both | DP2 | 0055 d7: the delegated exchange keeps the scope ceiling | `victual:write` refused or dropped | 400 invalid_scope (Scopes [victual:write] not present in the initial access token [victual:read, openid, dele); scope=undefined | pass |
| G4 Code flow with PKCE, scripted login | met: a headless login through the realm's login form replaces the password grant. S256-only is a per-client attribute, and the request without a challenge or with `plain` is refused | P2, N17, N19 |
| G4 Refresh rotation with reuse revoking the family | met-with-config: `revokeRefreshToken` set on the realm. Reuse of an old token is refused and the newer token of the same family is refused too | P10, N16 |
| Revocation | met-with-config for refresh and new exchanges; partly at introspection. Revoking the refresh token, deleting the session or deleting one client's consent stops refresh and new exchanges. A stateless check accepts the exchanged token until it expires (five minutes). The first version of this table said all three deactivate the exchanged token at introspection. That was wrong (finding 10, K1i) | R3 to R6, N12, K1i |
| Discovery | met: OIDC and RFC 8414 discovery both served from the issuer path, issuer equals `iss`. `resource_indicators_supported` absent | P1 |
| Registration | met-with-config: anonymous registration refused by default (Trusted Hosts policy with no host). With a trusted host, anonymous callers can register confidential clients and wildcard redirects, so a client policy is needed to limit that | N15, D1, D2 |
| K1 Originating grant: identity claim (prerequisite 10, question 13) | met-with-config: a per-client scope `victual-grant:<client id>` with a Hardcoded claim mapper, a default scope of the client and an optional scope of the sidecar. The exchanged token carries `victual_client`, signed by Keycloak. Needs no Preview feature and no deployed code. Victual must refuse a token without the claim (finding 15) | K1c, K1d, K1h |
| K1 Originating grant: no caller-supplied identity (prerequisite 10) | met-with-config, conditional on the `victual-downscope-all` client policy. With it, the sidecar cannot ask for another grant's scope and extra form fields change nothing. Without it, the sidecar relabelled grant A as grant B (K1f FAIL) | K1e, K1f |
| K1 Originating grant: per-grant revocation (prerequisite 10) | partly met at Keycloak's introspection, met with a Victual lookup on `victual_client`, `sid` and `sub`. Consent deletion and not-before changes do not reach introspection. A session shared by two grants, and a user logout, end both grants (finding 18) | K1i, K1j, K1r1s to K1r7s, K1r1h to K1r5h, K1s, K1g1, K1g2 |
| K1 Candidates that failed | a User Session Note mapper (a browser login stores only `AUTH_TIME` and `KC_DEVICE_NOTE`; no note names the client), a mapper on the MCP client or on the sidecar (not applied at the exchange), `sid` alone when two clients share one SSO session, script mappers (need a deployed JAR; not tried) | K1a, K1b, K1g2 |
| K2 MCP audience bound by the adapter (prerequisite 11, question 14) | met-with-config: clients the adapter creates with `victual-mcp-audience` as a default scope verify at the sidecar and cannot select another audience. A client without the scope is refused at the sidecar. Exchanged tokens carry only the `/api` audience (finding 20) | K2a to K2e |
| K2 Anonymous registration cannot self-bind (prerequisite 11) | met-with-config by Keycloak's Allowed Client Scopes policy with an empty allow list: a registrant asking for the audience scope is refused, and a registered client cannot request it. With `victual-mcp-audience` on that list, a registrant gets the MCP audience itself (K2h), so the list must never contain it | K2g, K2h |
| Victual-owned login (no longer a gate since the 2026-10-25 clarification) | evidence only. met-with-config: identity brokering to an upstream OIDC provider creates or links a Keycloak user with no password credential, and the browser flow can be reduced to the redirector. The User Storage SPI was read, not run (untested) | L1, L2, L4 |
| Victual subject in the token (not required since the 2026-10-25 clarification: Victual maps Keycloak's stable `sub`, P3) | evidence only. met-with-config: Keycloak's `sub` is its own UUID. Victual's subject can be copied into a claim, but only from a client scope requested at the exchange; a mapper on the client itself is not applied to the exchanged token | L3 |
| Licence, image, runtime | Apache-2.0; image 478 MB (arm64); runs as uid 1000; read-only root filesystem works after a build step with a tmpfs for `/tmp` and the data directory; 520 to 620 MiB for the server after the probe, 50 to 77 MiB for PostgreSQL | I1, `./run.sh readonly`, `./run.sh stats` |

Core run (2026-10-26): **77 of 90 cases pass, 12 FAIL, 1 n/a** (P8 needs the Preview run). The
twelve FAIL rows are P7, N5, N11, N11c, A2, R2 and L3 from the first report, and K1a, K1b, K1f,
K1g2 and K1r3h from the follow-up. K1f is the default-configuration result with the downscope
policy switched off, and the other four are candidates or topologies that do not give a
per-grant identity. Preview run: **12 of 12 pass** (DP2 now passes; see the note under the
Preview table).

Cold starts of the core run (`./run.sh down`, `up`, `setup.mjs`, `probe.mjs`):

| Run | Result | Note |
|---|---|---|
| 1 | 45 pass, 10 FAIL, 1 n/a of 56 | D1 and D2 used the network gateway as the registering host (Keycloak saw the container address); L4 expected the upstream URL where the redirector sends the browser to the broker endpoint. Harness fixes, no change to an expectation |
| 2 to 5 | 48 pass, 7 FAIL, 1 n/a of 56 each | L3 diagnostics extended between runs; same verdicts |
| 6 | 49 pass, 7 FAIL, 1 n/a of 57 | X4b added |
| 7 | 77 pass, 12 FAIL, 1 n/a of 90 | 2026-10-26 follow-up: K1, K2 and K3 cases added; client policies moved into `setup.mjs`. K2g first expected a registered client without the scope; Keycloak refused the registration (403), so the case now accepts a refusal or a client without the scope. Edited before the cold starts, in warm-realm runs of 74 and 76 passes |
| 8 | 77 pass, 12 FAIL, 1 n/a of 90 | Same verdicts as run 7 case by case. Two earlier cold starts, before the observed-text edits that shortened three table cells, gave the same counts and verdicts |

## Shape under test

| Client | Plays | Grants |
|---|---|---|
| `victual-spike-mcp-client` | Registered MCP client (public, PKCE); user tokens come from here | code, refresh |
| `victual-spike-mcp-consent` | A public client with Consent required, for the revocation and delegation cases | code, refresh |
| `victual-spike-sidecar` | The MCP sidecar, ADR-0055 decision 7 | standard token exchange, service account |
| `http://localhost:3000/mcp` | A sidecar whose client id is its resource URI (P5b) | standard token exchange |
| `http://localhost:8080/api` | Victual's `/api` audience, as a client with every flow off (Keycloak's `audience` parameter names clients) | none |
| `victual-spike-other`, `-rogue-exchanger`, `-no-exchange-grant`, `-mcp-short`, `-default-scopes` | Negative cases, 3 s tokens, and realm default scopes as Keycloak ships them | as named |
| `victual-spike-mcp-k1a`, `-k1b` | Two MCP clients created by `adapter.mjs` (K1) | code, refresh, Consent required |
| `victual-spike-mcp-k2-1`, `-k2-2`, `-k2-noaud` | Created and deleted by the K2 cases through `adapter.mjs`; the last one skips the audience scope | as above |

One realm (`victual`), so one issuer. Five-minute access tokens, `revokeRefreshToken` on,
`refreshTokenMaxReuse` 0. `fullScopeAllowed` is off on every client. Audience comes from
client scopes with audience mappers (`victual-mcp-audience`, `victual-api-audience`), and
the scopes `victual:read`, `victual:write`, `victual:stock` and `victual:stock:book` are
client scopes with no mappers. `victual-groups` carries a group membership mapper. `setup.mjs`
also installs two client policies: `victual-downscope-all` (an exchange never adds a scope) and
`victual-dcr-anonymous` (anonymous registration yields only public PKCE clients with https or
loopback redirects and Consent required). Cases N11 and D1 show Keycloak's default behaviour by
switching the relevant policy off for the duration of the case; K1f does the same for K1.

The adapter simulation (`adapter.mjs`) creates an MCP client through the admin API with
`victual-mcp-audience`, `victual-groups` and a per-grant scope (`victual-grant:<client id>`) as
default scopes, the four bundles and `offline_access` as optional scopes, `fullScopeAllowed`
off, S256 PKCE and Consent required. It adds the per-grant scope to the sidecar's optional
scopes. The per-grant scope has one Hardcoded claim mapper that writes `victual_client`, the
client id, into access tokens and introspection responses, and the lightweight token.

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
| N11b | 0055 d7 with the downscope-assertion-grant-enforcer client policy | widening refused; equal and narrower scope still exchanged; write-to-write works | widen -> 400 invalid_scope (Scopes [victual:write] not present in the initial access token [victual:read, openid, prof); same -> 200 token; narrower -> 200 token; write subject asking write -> 200 token | pass |
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
| R3 | 0055 d6: revoking the client's refresh token (RFC 7009) | refresh refused; stateless check keeps accepting until exp | revoke -> 200; refresh -> 400 invalid_grant (Session not active); introspect user token active=false; introspect exchanged active=false; stateless RS check of exchanged: accepted; exchange again -> 400 invalid_request | pass |
| R4 | 0055 d6: deleting the user session (admin API) by sid | refresh refused; exchanged token active=false at introspection; stateless check accepts until exp | DELETE session -> 204; refresh -> 400 invalid_grant (Session not active); introspect user token active=false; introspect exchanged active=false; stateless RS check of exchanged: accepted; exchange again -> 400 invalid_request | pass |
| R5 | 0055 d6: per-client grant revocation = deleting the user's consent for one client (admin API) | the client's refresh token and the tokens exchanged from it stop working | DELETE consent -> 204; refresh -> 400 invalid_grant (Session doesn't have required client); introspect user token active=false; introspect exchanged active=true; stateless RS check of exchanged: accepted; exchange again -> 400 invalid_request | pass |
| R6 | 0055 d6: revoking one client's consent leaves another client's grant of the same user intact | the other client's refresh token still works | DELETE consent (consent client) -> 204; MCP client refresh -> 200 token | pass |
| D1 | 0056 prereq 2: anonymous registration with only the Trusted Hosts policy configured | trusted host registers a loopback public client; untrusted redirect hosts refused; confidential clients and wildcards are what the policies allow | peer 10.89.13.3; loopback public -> 201 ; https://app.example -> 403 Policy 'Trusted Hosts' rejected request to client-registration service. Details: URI doesn't match any trusted host or trusted domain; http://evil.example -> 403 Policy 'Trusted Hosts' rejected request to client-registration service. Details: URI doesn't match any trusted host or trusted domain; confidential -> 201 ; scope request -> 403 scope=""; wildcard redirect -> 201  | pass |
| D2 | 0056 prereq 2: a client policy limits anonymous registration to public clients with loopback or https redirects | loopback and https public clients register with PKCE S256 and Consent required; http (non-loopback), wildcard and confidential clients are refused | loopback public -> 201 (public=true pkce=S256 consentRequired=true); https -> 201; http non-loopback -> 403 invalid_client_metadata; https wildcard -> 403 invalid_client_metadata; confidential (secret auth) -> 403 invalid_client_metadata; policy executors: secure-client-uris-pattern (redirectUris), pkce-enforcer, secure-client-authenticator (client-jwt only), consent-required | pass |
| L1 | 0055 d2, gate 2: users and passwords stay in Victual (brokering, first login creates the user) | Token for a user with no password credential | 200; user stored with username, email, names, a `victual_subject` attribute and one federated identity; 0 credentials; `sub` is Keycloak's UUID | pass |
| L2 | Same: a pre-created passwordless user is linked on first login | Same user id before and after; still no password | Same id; one federated identity added; 0 password credentials | pass |
| L3 | 0055 d4, 0056 d3: which subject Victual maps | Victual's subject in the user token and the exchanged token | User token: claim `victual_sub` equals the upstream `sub` (IdP attribute mapper, then user attribute mapper on the client). Exchanged token: **absent** with the mapper on the MCP client, the sidecar and the `/api` client. Present when the claim sits in a client scope included in the subject token's scope and requested at the exchange | **FAIL** |
| L4 | 0055 d2, gate 2: local password login can be disabled | No password form; password grant fails for the brokered user | Password grant `invalid_grant` for three guesses; with the realm browser flow reduced to the identity-provider redirector, `/auth` redirects to the broker endpoint, no form, and the login ends as the upstream identity | pass |
| I1 | 0055 prerequisite 3: runs non-root | uid other than 0 | uid 1000; image 478 MB | pass |

Follow-up cases K1 to K3 (2026-10-26), same run as the table above. K1r cases are named by mechanism number, then `s` (separate sessions) or `h` (same browser session).

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| K1a | 0055 d6, q13, prerequisite 10: a User Session Note mapper carries the originating MCP client into the exchanged token | a session note claim in the exchanged token names the MCP client | notes tried: clientId, client_id, client, AUTH_METHOD, AUTH_TIME, KC_DEVICE_NOTE, identity_provider, iss, scope, clientHost, clientAddress. Notes that exist on a browser login: AUTH_TIME, KC_DEVICE_NOTE (values: {"note_AUTH_TIME":"1793026656","note_KC_DEVICE_NOTE":"eyJpcEFkZHJlc3MiOiIxMC44"}); same claims on the exchanged token: true; the documented notes are identity_provider, identity_provider_identity (brokered logins), IMPERSONATOR_* and the service-account details; none holds the requesting client | **FAIL** |
| K1b | 0055 d6, q13: a mapper on the MCP client (or on the sidecar) reaches the exchanged token | the exchanged token carries the claim of the MCP client's mapper | user token: k1_on_mcp_client=mcp; exchanged token: k1_on_mcp_client=undefined, k1_on_sidecar=undefined. Mappers in a client's own (dedicated) scope are not applied at the exchange; only client scopes requested at the exchange are (as in L3) | **FAIL** |
| K1c | 0055 d6, q13, prerequisite 10: the exchanged token carries a server-signed claim naming the originating MCP client | for each of two clients: victual_client equals the client the user authorized; the per-grant scope is in `scope`; sid and sub carried over; azp is still the sidecar | A: 200 token victual_client=victual-spike-mcp-k1a sid=true azp=victual-spike-sidecar scope has victual-grant:victual-spike-mcp-k1a=true; B: victual_client=victual-spike-mcp-k1b azp=victual-spike-sidecar; sidecar asking without the grant scope: victual_client=undefined (Victual's rule: no claim, no access) | pass |
| K1d | 0055 d6, prerequisite 10: the claim is server-signed; editing it breaks the signature | a token with victual_client changed to the other client is refused | bad signature | pass |
| K1e | 0055 d6, q13, prerequisite 10: a caller-supplied identity is never authoritative | A's subject token with B's grant scope is refused; extra form fields change nothing; A cannot ask for B's scope at /authorize; a `claims` request parameter does not set the claim | B's scope with A's token -> 400 invalid_scope; extra victual_client and azp fields -> 200 token, victual_client=victual-spike-mcp-k1a; A asks B's scope at authorize -> 400 invalid_scope; victual_client and claims parameters at authorize -> 200 token, victual_client=victual-spike-mcp-k1a | pass |
| K1f | 0055 d6, q13, prerequisite 10: without the victual-downscope-all client policy the sidecar cannot relabel a grant | A's subject token with B's grant scope refused | policy switched off: 200 token victual_client=victual-spike-mcp-k1b (azp=victual-spike-sidecar); the policy is therefore required deployment configuration for this claim | **FAIL** |
| K1g1 | 0055 d6, q13: sid plus the admin API's session client list identifies the originating client (separate sessions) | one MCP client in each session; the exchange adds none (the exchange creates no session) | session A clients=["victual-spike-mcp-k1a"]; session B clients=["victual-spike-mcp-k1b"]; sids differ=true; the sidecar is not added to the session by an exchange | pass |
| K1g2 | 0055 d6, q13: sid identifies the originating client when two clients share one SSO session | the session names one MCP client | same browser, two clients: sid A == sid B: true; session clients=["victual-spike-mcp-k1b","victual-spike-mcp-k1a"]; after an exchange=["victual-spike-mcp-k1b","victual-spike-mcp-k1a"]. sid alone cannot say which grant an exchanged token came from | **FAIL** |
| K1h | 0055 d6, q13: the grant claim survives a lightweight access token | with lightweight tokens on, the exchanged token still names the client (claim flagged lightweight, and the per-grant scope in `scope`) | audience mapper not flagged lightweight: 400 invalid_request (Requested audience not available: http://localhost:8080/api); flagged: 200 token, claims=exp,iat,jti,iss,aud,sub,typ,azp,sid,scope,victual_client, victual_client=victual-spike-mcp-k1a, grant scope in scope=true; /api check=accepted; introspection returns the full claims: true | pass |
| K1i | 0055 d6: introspection of an exchanged token | active at the resource server (the /api client), the point Victual would check | introspected by the sidecar (the exchanger, azp): active=false; by the /api client (the audience): active=true. The sidecar's own introspection is inactive even before any revocation, so a revocation check must be made by the audience client | pass |
| K1j | 0055 d6, q13: an adapter-created client has Consent required, so the user's grant is a stored consent per client | a consent screen is shown on first use; the consent is stored; the next login skips the screen | screen shown after the stored consent was deleted=true; code after accepting=true; stored consents=victual-spike-mcp-k1a,victual-spike-mcp-k1b; second login: screen shown=false, code=true | pass |
| K1r1s | 0055 d6, q13, prerequisite 10: deleting the user's consent for client A (admin API), separate sessions | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 204; after: A introspect=true session+client=false consent=false notBeforeStale=false => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Session doesn't have required client); refresh B -> 200 token; stateless check A/B: accepted/accepted | pass |
| K1r2s | 0055 d6, q13, prerequisite 10: revoking client A's refresh token (RFC 7009), separate sessions | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 200; after: A introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Session not active); refresh B -> 200 token; stateless check A/B: accepted/accepted | pass |
| K1r3s | 0055 d6, q13, prerequisite 10: deleting the user session of grant A (admin API, by sid), separate sessions | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 204; after: A introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Session not active); refresh B -> 200 token; stateless check A/B: accepted/accepted | pass |
| K1r4s | 0055 d6, q13, prerequisite 10: client A's not-before (admin API PUT notBefore=now), separate sessions | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 204; after: A introspect=true session+client=true consent=true notBeforeStale=true => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Stale token); refresh B -> 200 token; stateless check A/B: accepted/accepted | pass |
| K1r5s | 0055 d6, q13, prerequisite 10: logging the user out of every session (admin API), separate sessions | both grants' exchanged tokens blocked (a user-wide mechanism) | before: A valid, B valid; call -> 204; after: A introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; B introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; refresh A -> 400 invalid_grant (Session not active); refresh B -> 400 invalid_grant (Session not active); stateless check A/B: accepted/accepted | pass |
| K1r1h | 0055 d6, q13, prerequisite 10: deleting the user's consent for client A (admin API), same browser session | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 204; after: A introspect=true session+client=false consent=false notBeforeStale=false => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Session doesn't have required client); refresh B -> 200 token; stateless check A/B: accepted/accepted | pass |
| K1r2h | 0055 d6, q13, prerequisite 10: revoking client A's refresh token (RFC 7009), same browser session | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 200; after: A introspect=true session+client=false consent=true notBeforeStale=false => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Session doesn't have required client); refresh B -> 200 token; stateless check A/B: accepted/accepted | pass |
| K1r3h | 0055 d6, q13, prerequisite 10: deleting the user session of grant A (admin API, by sid), same browser session | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 204; after: A introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; B introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; refresh A -> 400 invalid_grant (Session not active); refresh B -> 400 invalid_grant (Session not active); stateless check A/B: accepted/accepted | **FAIL** |
| K1r4h | 0055 d6, q13, prerequisite 10: client A's not-before (admin API PUT notBefore=now), same browser session | A's exchanged token blocked at introspection or the grant lookup; B's still valid | before: A valid, B valid; call -> 204; after: A introspect=true session+client=true consent=true notBeforeStale=true => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Stale token); refresh B -> 200 token; stateless check A/B: accepted/accepted | pass |
| K1r5h | 0055 d6, q13, prerequisite 10: logging the user out of every session (admin API), same browser session | both grants' exchanged tokens blocked (a user-wide mechanism) | before: A valid, B valid; call -> 204; after: A introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; B introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; refresh A -> 400 invalid_grant (Session not active); refresh B -> 400 invalid_grant (Session not active); stateless check A/B: accepted/accepted | pass |
| K1r6s | 0055 d6, q13, prerequisite 10: deleting the offline session of grant A (admin API, isOffline=true), separate sessions | A's exchanged token blocked; B's still valid | exchanged token's sid is the offline session (true); call -> 204; A introspect=false session+client=false consent=true notBeforeStale=false => BLOCKED; B introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 400 invalid_grant (Offline user session not found); refresh B -> 200 token | pass |
| K1r7s | 0055 d6: revoking client A's access token (RFC 7009) does not revoke the grant or its exchanged tokens | the exchanged token stays valid (the guide: revoking the subject access token does not revoke the exchanged one) | revoke -> 200; A introspect=true session+client=true consent=true notBeforeStale=false => valid; refresh A -> 200 token | pass |
| K1s | 0055 d6: a stateless check of an exchanged token after its grant was revoked | accepted until exp (five minutes at most) | consent deleted; stateless check -> accepted; exp in 300 s | pass |
| K2a | 0055 d3, d8, prerequisite 11: tokens of two clients the adapter created are accepted at the sidecar | both accepted; aud holds the MCP audience; the client's default scopes include victual-mcp-audience and not victual-api-audience | client 1: accepted aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; client 2: accepted aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; client 1 default scopes=acr,victual-mcp-audience,victual-groups,victual-grant:victual-spike-mcp-k2-1,profile,basic,email; optional=victual:read,offline_access,victual:write,victual:stock:book,victual:stock | pass |
| K2b | 0055 d3, d8, prerequisite 11: a client cannot select an audience it is not bound to | each attempt is refused, or the token's aud stays within the MCP audience and the sidecar's client id | audience=/api parameter -> 200 aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; resource=/api -> 200 aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; resource=unknown -> 200 aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; scope victual-api-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-api-audience); scope victual-rogue-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-rogue-audience); scope victual-other-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-other-audience) | pass |
| K2c | 0055 d3, d8, prerequisite 11: a client without the audience scope has no MCP audience, is refused at the sidecar and cannot bind itself | sidecar refuses; asking for the scope at authorize is refused; the sidecar cannot exchange its token | aud=[]; sidecar check -> empty aud; asking for victual-mcp-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-mcp-audience); exchange -> 403 access_denied (Client is not within the token audience) | pass |
| K2d | 0055 d7, d8, prerequisite 11: exchanged tokens target only /api | aud is exactly the /api URI; accepted at /api; refused at the sidecar | client 1 exchanged aud="http://localhost:8080/api"; client 2 exchanged aud="http://localhost:8080/api"; /api check: accepted, accepted; sidecar check of an exchanged token: aud http://localhost:8080/api lacks http://localhost:3000/mcp | pass |
| K2e | 0055 d7, d8, prerequisite 11: an exchanged token cannot be exchanged back into the MCP audience | every attempt is refused, or issues no MCP audience | sidecar: x1, audience=MCP URI -> 400 invalid_request; sidecar: x1, audience=sidecar -> 400 invalid_request; sidecar: x1, scope victual-mcp-audience -> 400 invalid_scope; sidecar: user token, audience=MCP URI -> 400 invalid_request; sidecar: user token, scope victual-mcp-audience -> 400 invalid_scope; client named by the MCP URI: x1 -> 403 access_denied | pass |
| K2g | 0055 d8, prerequisite 11: an anonymous registrant cannot bind itself to the MCP audience (Allowed Client Scopes list empty) | a registration asking for the scope is refused or the client does not hold it; a client registered without it is refused the scope at authorize and its tokens lack the MCP audience | register with scope -> 403 Policy 'Allowed Client Scopes' rejected request to client-registration; client holds the scope: false; register without scope -> 201, client holds the scope: false; authorize with the scope -> 400 invalid_scope (Invalid scopes: openid victual-mcp-audience); token aud=[] | pass |
| K2h | 0055 d8, prerequisite 11: demonstration of what the policy must forbid: the audience scope on the Allowed Client Scopes list of anonymous registration | with the scope on the list, a dynamic registrant binds itself to the MCP audience (so the list must not contain it) | list = [victual-mcp-audience]: register -> 201 granted scope="victual-mcp-audience"; client holds the scope: true; authorize with it -> 200 token; token aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; the list was restored afterwards | pass |
| K3b | 0055 d3, prerequisite 8: the /api resource client cannot obtain tokens through any flow | client credentials, password and authorization code requests as the /api client are refused | client_credentials -> 401 unauthorized_client (Client not enabled to retrieve service account); password -> 400 unauthorized_client (Client not allowed for direct access grants); authorization code -> 400 authorize_refused (Sign in to victual { "imports": { "rfc4648": "/resources/69i3o/common/keycloak/vendor/rfc4) | pass |
| K3 | 0055 prerequisite 8: the installed configuration equals what setup.mjs and adapter.mjs promise (README inventory) | every realm setting, client scope with its mappers, client policy, profile and client flag listed in the inventory is present with the stated value | realm settings 8, client scopes 10 with mappers, profiles 2 (victual-downscope, victual-dcr), policies 2, client flags (mcp, sidecar, api, adapter client) all as stated | pass |

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

The 2026-10-25 Preview run reported DP2 as a FAIL (200 with `victual:write`). That run did not have the `victual-downscope-all` policy in the Preview realm. With `setup.mjs` installing it, the delegated exchange refuses widening, so the policy covers delegation. The Preview run was executed twice in total (2026-10-25 and 2026-10-26); K1 does not depend on it.

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
3. **The originating MCP client is not in the exchanged token by default (R1, R2); K1 adds it
   with configuration (findings 15 to 17).** `sid` carries over; the MCP client's id does not,
   because `azp` is replaced. The first version of this report left a Keycloak-side mapper
   untried and said a client mapper would not help. K1b confirms a mapper on the client does
   not reach the exchanged token, and K1c shows a client scope requested at the exchange does.
4. **`act` needs Preview, a consent screen at every login, and a service account (P8, DP1
   to DP8).** The documented setup is: `token-exchange-delegation` and `parameterized-scopes`
   features, FGAP v2 with a `delegate` permission for the sidecar's client on users, a
   requesting client with Consent required, and a service account on the sidecar whose
   token is the `actor_token`. The user's token then carries `may_act`; the sidecar sends it
   with the actor token and gets `act`. The consent for the delegation scope is never stored
   (DP7), so the user sees the screen in every login session. The Preview page says the
   feature "is not fully supported". The scope ceiling did not hold in the 2026-10-25 run (DP2)
   because the client policy was missing from that realm. With the policy installed by
   `setup.mjs`, the 2026-10-26 Preview run refuses the widening.
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
10. **Revocation stops refresh and new exchanges; introspection catches only some mechanisms, and
    the earlier evidence for that was wrong (R3 to R6, K1i, K1r).** The first version of this
    report said revoking the refresh token, deleting the session and deleting one client's
    consent each made the exchanged token inactive at introspection. The probe introspected the
    exchanged token as the sidecar. The sidecar is the token's `azp`, and Keycloak answers
    `active=false` to that client for a valid token, so those results showed nothing (K1i). The
    probe now introspects as the `/api` client, the token's audience, and checks that the token
    is active before the revocation. With that change, deleting the session or revoking the
    refresh token makes the exchanged token inactive when the grant has its own session (R3,
    R4). Deleting the consent for one client does not: the exchanged token stays active (R5),
    although refresh and a new exchange are refused. A stateless resource-server check accepts
    the exchanged token until it expires, which fits decision 6's five-minute worst case. R6
    still holds: deleting the consent for one client leaves another client's grant intact.
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

15. **K1: a per-grant client scope puts the originating client into the exchanged token (K1c,
    K1d, K1h).** The adapter creates, for each MCP client, a client scope named
    `victual-grant:<client id>` with one Hardcoded claim mapper (`victual_client` = the client
    id). The scope is a default scope of that client and an optional scope of the sidecar. The
    sidecar reads the scope name from the verified subject token's `scope` claim and asks for
    it at the exchange. The exchanged token then carries `victual_client`, the scope name in
    `scope`, the subject's `sid` and `sub`, and `azp` of the sidecar. The claim is signed by
    Keycloak (K1d: editing it breaks the signature). The costs are one client scope per
    registered client, a longer optional-scope list on the sidecar, and a cleanup step in the
    adapter's delete path (`deregisterMcpClient`). Victual's scope expansion for decision 5 must
    ignore scopes that start with `victual-grant:`. A sidecar that omits the scope gets a token
    with no claim (K1c), so Victual must refuse an exchanged token without `victual_client`.
    Keycloak's own dynamic registration does not create the scope; only the adapter does.
16. **K1: the grant identity is trustworthy only with the downscope policy (K1e, K1f).** With
    `victual-downscope-all` installed, a request for another grant's scope is refused because
    the scope is not in the subject token, a client cannot ask for another client's scope at
    `/authorize`, and extra form fields (`victual_client`, `azp`) and a `claims` request
    parameter change nothing. With the policy switched off, the sidecar asked for B's scope
    with A's token and received a token that says B (K1f, FAIL). The policy is therefore part of
    the required configuration for prerequisite 10, in addition to its scope-ceiling role in
    N11b.
17. **K1: candidates that do not work.** A User Session Note mapper reads the user session's
    notes. A browser login stores `AUTH_TIME` and `KC_DEVICE_NOTE`; the guide documents
    `identity_provider`, `identity_provider_identity`, the impersonation notes and the
    service-account details. No note names the client (K1a). A mapper on the MCP client or on
    the sidecar is not applied at the exchange (K1b), as in L3. `sid` with the session's client
    list from the admin API identifies the client only when each grant has its own session
    (K1g1); two clients in one browser session share one `sid` and one client list (K1g2).
    Script mappers need a deployed script JAR, which counts as not supported configuration
    here, and the 26.8.1 protocol mapper provider list has no script mapper type. They were
    not tried. A lightweight access token works (K1h). The grant claim stays when its mapper has
    `lightweight.claim` on, and `scope` and `sid` are always kept. The audience mapper needs the
    same flag: without it the exchange fails closed with "Requested audience not available".
18. **K1: per-grant revocation depends on the mechanism and on whether grants share a session
    (K1r1s to K1r7s, K1r1h to K1r5h).** The table shows which signal blocks grant A's exchanged
    token when grant A is revoked, with grant B left valid. "Introspect" is Keycloak's
    introspection by the `/api` client. "Lookup" is a Victual check keyed on `victual_client`,
    `sid` and `sub`: the session still holds the client (online or offline), the consent is
    stored, and the token's `iat` is not before the client's not-before.

    | Mechanism | Separate sessions: introspect / lookup | Same SSO session: introspect / lookup |
    |---|---|---|
    | Delete consent for A | active / blocks A | active / blocks A |
    | Revoke A's refresh token | blocks A / blocks A | active / blocks A |
    | Delete A's session by `sid` | blocks A / blocks A | blocks A and B / blocks A and B |
    | Client A not-before | active / blocks A | active / blocks A |
    | Delete A's offline session | blocks A / blocks A | not run |
    | Log the user out | blocks A and B | blocks A and B |
    | Revoke A's access token (RFC 7009) | no effect | not run |

    In every row except the session deletion in a shared session and the user logout, B stays
    valid. Deleting a session that two grants share, and logging the user out, end both
    grants. Keycloak has no per-grant mechanism there.

    Refresh for A fails in every per-grant row
    (the K1r observations show the errors). A new exchange from A's unexpired access token was
    refused after the consent deletion, the refresh-token revocation and the session deletion
    (R3 to R5) and was not measured after the not-before change. Existing exchanged tokens
    expire within five minutes (K1s). Revoking A's access token has no effect on A's grant or on its
    exchanged tokens, as the guide says.
19. **K1: which side is authoritative.** Decision 6's grant row in Victual is the authoritative
    refusal point for Victual requests. Keycloak-side revocation reaches Victual through the
    lookup above, or after at most five minutes through failing refresh and exchange. Keycloak
    has no supported push for it: the 26.8.1 event listener providers are
    `workflow-event-listener`, `jboss-logging` and `email`, and none calls an HTTP endpoint.
    A user who revokes the consent in Keycloak's account console therefore does not change
    Victual's grant row. Polling the user events for a revoked grant needs events enabled on
    the realm (off in this realm) and was not tried. A per-request lookup costs three or four
    admin REST calls and needs an admin credential in Victual; caching and its revocation lag
    were not measured.
20. **K2: the adapter binds the audience, and a client cannot choose another (K2a to K2e).**
    Clients created through the admin API with `victual-mcp-audience` as a default scope get
    `aud` of the MCP audience and the sidecar's client id, and verify at the sidecar. The
    sidecar's client id is in `aud` because standard exchange needs it (N8). A client that
    sends `audience` or `resource` gets an unchanged token, and requests for the optional
    scopes of other clients (`victual-api-audience`, `victual-rogue-audience`,
    `victual-other-audience`) are refused with `invalid_scope`. A client created without the
    audience scope has an empty `aud`, is refused at the sidecar, cannot ask for the scope at
    `/authorize`, and cannot be exchanged. Exchanged tokens carry the `/api` audience alone. The
    sidecar cannot exchange an `/api` token or a user token into the MCP audience, whether it
    asks by `audience` or by scope, and the client whose id is the MCP URI cannot exchange it
    (K2e).
21. **K2: anonymous registration must not be able to name the audience scope (K2g, K2h).** With
    the Allowed Client Scopes policy's allow list empty, a registration that asks for
    `victual-mcp-audience` is refused with HTTP 403, and a client registered without it
    cannot request it. With the scope on the anonymous list, the registrant holds it and its
    tokens carry the MCP audience (K2h). The list must not contain it, and the realm's default
    client scopes must not include it either, because anonymous registrants receive those. The
    policy has an `authenticated` subtype too, used when registration needs an initial access
    token. If the adapter ever uses initial access tokens, that list needs the same rule; it was
    not tested. Keycloak's default Allowed Protocol Mappers policy also keeps anonymous
    registrants from adding audience and Hardcoded claim mappers (its anonymous allow list of
    mapper types, read from the realm's registration policy components, has neither); that was
    read, not run.

## Configuration inventory (prerequisite 8)

ADR-0055 prerequisite 8 accepts provider configuration when it uses a supported provider
interface, is versioned with the deployment configuration, and has positive and negative tests.
Every item below uses the admin REST API or a built-in provider. None needs a deployed JAR, a
patched image or a Preview feature. Case K3 reads the realm back and fails if any item with a
stated value differs, so the inventory and the installed configuration are checked against each
other. "Fixture" means the object exists only so a negative case has something to run against;
it is not deployment configuration.

Items installed by `setup.mjs`:

| Name | Kind | What it enforces | Positive case | Negative case |
|---|---|---|---|---|
| `accessTokenLifespan` 300 | realm setting | Five-minute access tokens (decision 6) | P2 (ttl 300 s) | none for the realm value: N13 and N14 use the 3 s client override. Flag |
| `ssoSessionIdleTimeout` 1800 | realm setting | Refresh token lifetime of 30 minutes idle | P10 (refresh claim 1800 s) | none. Flag |
| `ssoSessionMaxLifespan` 36000 | realm setting | Ten-hour session ceiling | none. Flag | none. Flag |
| `revokeRefreshToken` on, `refreshTokenMaxReuse` 0 | realm setting | Rotation, and reuse revokes the family (decision 6) | P10 | N16 |
| `registrationAllowed` off | realm setting | No self-registration page | none. Flag | none. Flag |
| `verifyEmail` off, `loginWithEmailAllowed` on | realm setting | Convenience for the scripted login; no control | not a control | not a control |
| `victual:read`, `victual:write`, `victual:stock`, `victual:stock:book` | client scopes, no mappers | Scope names the tokens carry; the ceiling compares them | P2, N11b (equal and narrower exchange) | N11 (default), N11b (widening), N11c |
| `victual-mcp-audience` (custom audience mapper, sidecar client audience mapper) | client scope | MCP audience on tokens of clients that hold it as a default scope | P2, P4, K2a | N1 to N3, A1, A3, K2b to K2e |
| `victual-api-audience` (client audience mapper) | client scope | `/api` audience for the sidecar's exchange | P5, P6, K2d | A1, N6, N6b, K2b |
| `victual-groups` (group membership mapper) | client scope | Group names in tokens (ADR-0056 decision 4) | P9 | P9 (absent after removal) |
| `victual-rogue-audience`, `victual-other-audience` | client scopes | Fixtures for N3, N6 and A1 | fixture | fixture |
| `victual-spike-mcp-client` flags | client | Public, S256 PKCE, no password grant, no implicit grant, full scope off | P2 | N17, N19, A3 |
| `victual-spike-sidecar` flags | client | Confidential, standard token exchange on, `victual-api-audience` default | P5 | N6, N7, N8, X1 to X6 |
| `serviceAccountsEnabled` on the sidecar | client setting | Needed only by the Preview delegation run | P8 (Preview) | none in the core run. Flag |
| `http://localhost:8080/api` flags | client | Resource only: every flow off | K1i (introspects with its credential) | K3b |
| `victual-downscope-all` and profile `victual-downscope` (`downscope-assertion-grant-enforcer`, any client) | client policy | An exchange adds no scope, which also protects the K1 claim | N11b | N11 (policy off), N11b, K1e, K1f (policy off) |
| `victual-dcr-anonymous` and profile `victual-dcr` (`secure-client-uris-pattern`, `pkce-enforcer`, `secure-client-authenticator`, `consent-required`) | client policy | Anonymous registration yields public PKCE clients with https or loopback redirects and Consent required | D2 | D1 (policy off), D2 (http, wildcard, confidential) |
| `-other`, `-rogue-exchanger`, `-no-exchange-grant`, `-mcp-short`, `-default-scopes` | clients | Fixtures | fixture | fixture |
| users `alice-spike`, `bob-spike`, group `victual-spike-household` | users and group | Fixtures | fixture | fixture |

Items the adapter creates for each registered client (`adapter.mjs`):

| Name | Kind | What it enforces | Positive case | Negative case |
|---|---|---|---|---|
| The MCP client with `victual-mcp-audience` as a default scope | client | Binds the client to the MCP audience (decision 8) | K2a | K2b, K2c |
| `victual-grant:<client id>` with a Hardcoded claim mapper | client scope | Names the originating client in exchanged tokens | K1c, K1h | K1d, K1e, K1f |
| Consent required on the client | client setting | A stored consent per client, revocable per client | K1j | K1j (the screen shows again after the consent is deleted), K1r1s, K1r1h |

Items present or changed outside `setup.mjs`:

| Name | Kind | What it enforces | Positive case | Negative case |
|---|---|---|---|---|
| Trusted Hosts (anonymous) | registration policy | Keycloak default, no host trusted, so anonymous registration is closed | D1 (trusted host registers) | N15 |
| Allowed Client Scopes (anonymous) | registration policy | Keycloak default, empty allow list: a registrant cannot name the audience scope | K2h (list changed for the demonstration, then restored) | K2g |
| Authentication flows, identity provider, user profile setting | `probe.mjs` L cases | Evidence for brokering only; created and removed inside the L cases | L1 to L4 | L4 |
| `victual-k1-notes`, a lightweight policy, and client mappers | `probe.mjs` K1a, K1b, K1h | Experiments, removed at the end of each case | K1h | K1a, K1b |

Gaps that block a clean prerequisite 8 for Keycloak:

1. Four realm settings have no test: `ssoSessionIdleTimeout` (one positive observation only),
   `ssoSessionMaxLifespan`, `registrationAllowed` and the access token lifespan as a realm value
   (no negative). They are session policy, not token validation. The maintainer can decide
   whether they count as "configuration that needs tests" or as settings.
2. `serviceAccountsEnabled` on the sidecar has no core-run case; it exists for the Preview run.
3. Two fixture scopes and five fixture clients carry no deployment meaning.
4. The per-client objects are created at run time. Prerequisite 8's second condition names the
   deployment configuration (`setup.mjs`), and a per-client object cannot live there. What is
   versioned is the recipe in `adapter.mjs`, which in Victual becomes application code that
   releases with Victual. Whether that satisfies "versioned with the deployment configuration"
   needs the maintainer's ruling. The probe tests the recipe, not an installed copy.
5. The client policies hold for this realm. A second realm (a second tenant, or a production
   realm) needs `setup.mjs` applied to it, and nothing here checks that it was.

## Not tested

- The User Storage SPI (docs only), and a deprovisioning path from Victual to Keycloak.
- Claude Code or any real MCP client against Keycloak (ADR-0055 prerequisite 5, ADR-0056
  prerequisite 2). Victual's real registration adapter does not exist; `adapter.mjs` simulates
  it with the admin REST API, and no case registers through the anonymous endpoint and then
  runs the adapter's binding.
- The full swap test (ADR-0055 prerequisite 1) with Victual and the sidecar images. Only the
  probe's resource-server check was pointed at Keycloak, and the K1 lookup is a probe function,
  not Victual code.
- Two Keycloak replicas on PostgreSQL (clustering, session sharing, and whether the session and
  consent lookups of K1 see the same data on both): one `start-dev` server, and one
  `start --optimized` server for the read-only check. Production hostname and TLS.
- A delegated exchange with a subject token from a client without Consent required. K1 was not
  tested with delegation.
- K1 for a client without Consent required: there is no stored consent to revoke, so the other
  mechanisms apply; all K1 clients have Consent required.
- K1r4 (client not-before): whether a new exchange from A's unexpired access token is refused.
  K1r6 (offline session) in a shared SSO session.
- Polling Keycloak's user events for a revoked grant, caching the K1 lookup, and its cost per
  request. Initial access tokens and the `authenticated` subtype of the registration policies.
- Behaviour when a mapper or policy fails; amd64 image size and memory; a Nix-built image.
- The device flow, DPoP, upgrade between Keycloak versions, break-glass with Keycloak
  stopped (ADR-0056 prerequisite 5), back-channel logout, and an Entra ID upstream.
- Any version other than 26.8.1.

## Sources read

Keycloak 26.8.1 documentation, read on 2026-10-26:

- [Configuring and using token exchange](https://www.keycloak.org/securing-apps/token-exchange):
  standard token exchange v2, scopes and audiences, the downscope enforcer, client policy
  integration, "never creates a new user session", revocation of exchanged tokens.
- [Server Administration Guide](https://www.keycloak.org/docs/latest/server_admin/): "Available
  user session data" (identity brokering notes), "OIDC user session note mappers", "Script
  mapper", "Using lightweight access token", client revocation and not-before ("Set to now"
  and "Push"), and the sessions and tokens settings.
- The provider lists of the running server (`/admin/serverinfo`): protocol mappers, client
  policy executors and conditions, event listeners, client registration policies.
