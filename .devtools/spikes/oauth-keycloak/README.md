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

The 2026-10-28 third pass tests the maintainer's 2026-10-26 answers to questions 18, 19 and 20:

- Question 18 (prerequisite 8): the adapter stamps every object it creates with owner and recipe
  version. A `reconcile()` step repairs drift, removes its own orphans and upgrades between recipe
  versions (K4a to K4g).
- Question 19: the K5 cases list the grants of a session through the admin API before an
  administrative revocation, and check that exactly those grants stop.
- Question 20 (prerequisite 10): each K1r case asserts one outcome: refresh access (`-r`), new
  exchanges (`-x`), or the already-issued `/api` token at Keycloak's introspection (`-t`). The
  `--expiry` mode measures how long an issued `/api` token keeps working after its grant is
  revoked (E cases). K1r8 to K1r10 are three mechanisms not tried before.

The 2026-10-26 report counted a probe-side lookup on `victual_client`, `sid` and `sub` as blocking
the exchanged token. That lookup simulated a Victual check that does not exist. It no longer
decides any verdict; finding 18 and the gate table are corrected.

Keycloak 26.8.1 is still the newest release on 2026-10-28 (`gh api repos/keycloak/keycloak/releases`:
26.8.1 published 2026-10-13). The spike stays pinned to it.

The 2026-10-29 fourth pass covers two gaps the earlier passes left:

- Prerequisite 2: two production-mode replicas on one PostgreSQL database, with replicas killed
  mid-flow (`ha.mjs`, H cases, finding 25).
- Prerequisite 3: a Nix-built image in the shape ADR-0013 sets (`nix/image.nix`, finding 26).

## Run

```bash
cd .devtools/spikes/oauth-keycloak
./run.sh up              # PostgreSQL + Keycloak on one podman network, 127.0.0.1:8180
node setup.mjs           # realm, clients, client scopes, users, via the admin API
node probe.mjs           # core cases; prints the table and writes results.json (restarts the server once, K4g)
node probe.mjs --expiry  # E cases: expiry delay of an issued /api token after revocation (about 7 min)
./run.sh down
./run.sh up preview      # adds token-exchange-delegation, parameterized-scopes, admin-fine-grained-authz:v2
node setup.mjs && node probe.mjs --preview
./run.sh down
./run.sh readonly        # second instance on a read-only root filesystem, :8181 (needs ./run.sh up)
./run.sh stats           # memory of both containers
./run.sh restart         # restart the server container on the same database (the probe calls it for K4g)
./run.sh ha-up           # builds an optimized image, then two production-mode replicas on one database, :8183 and :8184
node ha.mjs              # H cases through a proxy on :8180; runs setup.mjs; writes results-ha.json (about 4 min)
./run.sh ha-down         # removes both replicas and drops their database; the PostgreSQL container stays
```

No npm install is needed: the probe uses `node:crypto` and `fetch` only. `run.sh` generates the
PostgreSQL and admin passwords into a gitignored `.secrets.env` on first start; the other
values in these files are spike-only, for an instance on `127.0.0.1` with no real data. The core
run takes about 75 s; each scripted login takes about 1 s. `results.json` and `state.json`
are gitignored.

Files: `setup.mjs` (realm, clients, client scopes, client policies, users), `adapter.mjs` (the
adapter simulation: the pinned recipe versions, `registerMcpClient`, `deregisterMcpClient`,
`listOwned` and `reconcile`, used by `setup.mjs` and by the K2 and K4 cases), `probe.mjs` (the cases), `lib.mjs` (admin REST, scripted login, resource-server
check), `idp-stub.mjs` (stub upstream provider for the L cases), `run.sh`. The fourth pass adds
`ha.mjs` (the two-replica cases and their proxy) and `nix/image.nix` (the Nix-built image).

## Gates (2026-10-25, extended 2026-10-26, 2026-10-28 and 2026-10-29)

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
| G3 Scope ceiling: delegated exchange | met-with-config (Preview): the `victual-downscope-all` policy refuses widening in a delegated exchange too. The 2026-10-25 run reported a FAIL because the policy was installed by the core probe only, not in the Preview realm. `setup.mjs` now installs it in both (the 2026-10-26 Preview run: `400 invalid_scope`) | DP2 |
| G4 Code flow with PKCE, scripted login | met: a headless login through the realm's login form replaces the password grant. S256-only is a per-client attribute, and the request without a challenge or with `plain` is refused | P2, N17, N19 |
| G4 Refresh rotation with reuse revoking the family | met-with-config: `revokeRefreshToken` set on the realm. Reuse of an old token is refused and the newer token of the same family is refused too | P10, N16 |
| Revocation | met-with-config for refresh and new exchanges; partly at introspection; bounded at a stateless check. Revoking the refresh token, deleting the session or deleting one client's consent stops refresh and new exchanges. A stateless check accepts the issued token until `exp` + 60 s (finding 23). Corrected in finding 10 | R3 to R6, N12, K1i, E0 to E2 |
| Discovery | met: OIDC and RFC 8414 discovery both served from the issuer path, issuer equals `iss`. `resource_indicators_supported` absent | P1 |
| Registration | met-with-config: anonymous registration refused by default (Trusted Hosts policy with no host). With a trusted host, anonymous callers can register confidential clients and wildcard redirects, so a client policy is needed to limit that | N15, D1, D2 |
| K1 Originating grant: identity claim (prerequisite 10, question 13) | met-with-config: a per-client scope `victual-grant:<client id>` with a Hardcoded claim mapper, a default scope of the client and an optional scope of the sidecar. The exchanged token carries `victual_client`, signed by Keycloak. Needs no Preview feature and no deployed code. Victual must refuse a token without the claim (finding 15) | K1c, K1d, K1h |
| K1 Originating grant: no caller-supplied identity (prerequisite 10) | met-with-config, conditional on the `victual-downscope-all` client policy. With it, the sidecar cannot ask for another grant's scope and extra form fields change nothing. Without it, the sidecar relabelled grant A as grant B (K1f FAIL) | K1e, K1f |
| K1 Originating grant: revoke refresh access (prerequisite 10, question 20) | met: every per-grant mechanism (consent deletion, refresh-token revocation, session deletion in separate sessions, client not-before, offline-session deletion) refuses A's refresh and A's new exchanges and leaves B's. Deleting a session two grants share ends both (K5a lists them first) | K1r1s-r to K1r6s-r, K1r1s-x to K1r5s-x, K1r1h-r to K1r5h-r, K1r1h-x to K1r5h-x |
| K1 Originating grant: reject the already-issued `/api` token (prerequisite 10, question 20) | not met at Keycloak after consent deletion, client not-before, or refresh-token revocation in a shared session: `/api` introspection stays active. Refused at once after session-ending mechanisms in a grant's own session and after the sidecar revokes the token. A stateless check accepts it until `exp` + 60 s. Victual's check is not built or tested (findings 18, 19, 23) | K1r1s-t to K1r10s-t, K1r1h-t to K1r5h-t, K1r6s-t, K1r7s, K1s, E1, E2 |
| Shared provider session (question 19) | met-with-config: before deleting a session or logging a user out, the admin API's user-sessions endpoint lists the grants (user, session, client) the action ends, and exactly those grants stop; alice's grant in another session and bob's grant keep working. This is a broader effect, not per-grant revocation | K5a, K5b, K1r3h-r, K1r3h-x, K1r3h-t |
| Expiry delay of an issued `/api` token (question 20) | measured: introspection refuses after consent deletion at `exp` + 1.2 to 1.9 s, after session deletion and refresh-token revocation within 0.1 s. The stateless check refuses at `exp` + 60.2 to 60.7 s in every mechanism, so the worst case after a revocation is the token lifespan plus the 60 s skew (300 + 60 s with this realm) | E0, E1-consent, E1-session, E1-refresh, E2-consent, E2-session, E2-refresh |
| K1 Candidates that failed | a User Session Note mapper (a browser login stores only `AUTH_TIME` and `KC_DEVICE_NOTE`; no note names the client), a mapper on the MCP client or on the sidecar (not applied at the exchange), `sid` alone when two clients share one SSO session, script mappers (need a deployed JAR; not tried) | K1a, K1b, K1g2 |
| K2 MCP audience bound by the adapter (prerequisite 11, question 14) | met-with-config: clients the adapter creates with `victual-mcp-audience` as a default scope verify at the sidecar and cannot select another audience. A client without the scope is refused at the sidecar. Exchanged tokens carry only the `/api` audience (finding 20) | K2a to K2e |
| K2 Anonymous registration cannot self-bind (prerequisite 11) | met-with-config by Keycloak's Allowed Client Scopes policy with an empty allow list: a registrant asking for the audience scope is refused, and a registered client cannot request it. With `victual-mcp-audience` on that list, a registrant gets the MCP audience itself (K2h), so the list must never contain it | K2g, K2h |
| K4 Generated objects: versioned recipe (prerequisite 8, question 18) | met-with-config: owner and recipe stamps in the `attributes` maps survive read-back and a restart. `reconcile()` is idempotent, repairs five kinds of drift, deletes an owned orphan, leaves unowned lookalikes untouched and upgrades recipe 1 to 2. Listing by owner reads every client: the `q=` attribute query is refused (finding 22) | K4a to K4g, K3 |
| Victual-owned login (no longer a gate since the 2026-10-25 clarification) | evidence only. met-with-config: identity brokering to an upstream OIDC provider creates or links a Keycloak user with no password credential, and the browser flow can be reduced to the redirector. The User Storage SPI was read, not run (untested) | L1, L2, L4 |
| Victual subject in the token (not required since the 2026-10-25 clarification: Victual maps Keycloak's stable `sub`, P3) | evidence only. met-with-config: Keycloak's `sub` is its own UUID. Victual's subject can be copied into a claim, but only from a client scope requested at the exchange; a mapper on the client itself is not applied to the exchanged token | L3 |
| Licence, image, runtime | Apache-2.0; image 478 MB (arm64); runs as uid 1000; read-only root filesystem works after a build step with a tmpfs for `/tmp` and the data directory; 520 to 620 MiB for the server after the probe, 50 to 77 MiB for PostgreSQL | I1, `./run.sh readonly`, `./run.sh stats` |
| Two replicas on one database (0055 prerequisite 2) | met for two containers behind a proxy: production-mode replicas cluster over the shared database (JDBC_PING). Login, consent, rotation, revocation and exchange work across replicas. One replica killed mid-login, between code and redemption, or holding the grant loses nothing; both killed loses only logins in progress | H0 to H9p |
| Nix-built image (0055 prerequisite 3, ADR-0013) | met on arm64: `nix/image.nix` builds 26.8.1 from the flake's nixpkgs pin, uid 65532, no shell, read-only root with two tmpfs mounts. The core probe gives the same verdicts as on the upstream image. 827 MB against the upstream 478 MB | I1, finding 26 |

Core run (2026-10-28): **101 of 123 cases pass, 21 FAIL, 1 n/a** (P8 needs the Preview run).
The 21 FAIL rows:

- P7, N5, N11, N11c, A2, R2 and L3 from the first report; K1a, K1b, K1f and K1g2 from the follow-up.
- Six `-t` rows where the already-issued `/api` token stays active at Keycloak's introspection:
  consent deletion (K1r1s-t, K1r1h-t), client not-before (K1r4s-t, K1r4h-t), the `/api` client's
  not-before (K1r8s-t), and refresh-token revocation in a shared session (K1r2h-t).
- K1r9s-t: the sidecar's not-before ends B's token too.
- K1r3h-r, K1r3h-x and K1r3h-t: deleting a shared session ends both grants. K5a shows the
  affected grants are listed first.

Five of those `-t` rows (K1r1s, K1r4s, K1r1h, K1r2h, K1r4h) are mechanisms the 2026-10-26 report
passed because a probe-side lookup counted as blocking (finding 18). K1r8s-t and K1r9s-t are new
mechanisms. The one K1r3h FAIL became three rows. Expiry run: **7 of 7 pass**.

Core run (2026-10-26): **77 of 90 cases pass, 12 FAIL, 1 n/a** (P8 needs the Preview run). The
twelve FAIL rows are P7, N5, N11, N11c, A2, R2 and L3 from the first report, and K1a, K1b, K1f,
K1g2 and K1r3h from the follow-up. K1f is the default-configuration result with the downscope
policy switched off, and the other four are candidates or topologies that do not give a
per-grant identity. Preview run: **12 of 12 pass** (DP2 now passes; see the note under the
Preview table).

Cold starts of the core run (`./run.sh down`, `up`, `setup.mjs`, `probe.mjs`; run 9 also `probe.mjs --expiry`):

| Run | Result | Note |
|---|---|---|
| 1 | 45 pass, 10 FAIL, 1 n/a of 56 | D1 and D2 used the network gateway as the registering host (Keycloak saw the container address); L4 expected the upstream URL where the redirector sends the browser to the broker endpoint. Harness fixes, no change to an expectation |
| 2 to 5 | 48 pass, 7 FAIL, 1 n/a of 56 each | L3 diagnostics extended between runs; same verdicts |
| 6 | 49 pass, 7 FAIL, 1 n/a of 57 | X4b added |
| 7 | 77 pass, 12 FAIL, 1 n/a of 90 | 2026-10-26 follow-up: K1, K2 and K3 cases added; client policies moved into `setup.mjs`. K2g first expected a registered client without the scope; Keycloak refused the registration (403), so the case now accepts a refusal or a client without the scope. Edited before the cold starts, in warm-realm runs of 74 and 76 passes |
| 8 | 77 pass, 12 FAIL, 1 n/a of 90 | Same verdicts as run 7 case by case. Two earlier cold starts, before the observed-text edits that shortened three table cells, gave the same counts and verdicts |
| 9 | 101 pass, 21 FAIL, 1 n/a of 123 (85 s); expiry run 7 of 7 (362 s) | 2026-10-28 third pass: K1r split; K1r8 to K1r10, K4 and K5 added. A dev run before it gave 99 pass, 23 FAIL; the fixes after it are listed below the table |
| 10 | 101 pass, 21 FAIL, 1 n/a of 123 (86 s) | Same verdicts as run 9 case by case |

Fixes between the 2026-10-28 dev run and run 9; none changed an expectation:

- `reconcile()` did not remove a mapper added to the client itself. K4c caught it.
- Recipe 2's first mapper change (`userinfo.token.claim` = `false`) was already Keycloak's stored
  default, so it changed nothing. It was replaced by `lightweight.claim`.
- K5b listed alice's live sessions from earlier cases. It now logs alice out before building its
  fixtures, and also asserts that none of her sessions remain.
- K4a first expected the `q=` attribute query to work. Keycloak refused it (400) and the probe
  stopped before recording any verdict. The case now lists by owner over all clients and reports
  the refusal.

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
| `victual-spike-mcp-k4-1` to `-k4-4`, `-k4-lookalike` | Created and deleted by the K4 cases: `-k4-3` and `-k4-4` at recipe 1, `-k4-2` deleted behind the adapter's back, the lookalike without stamps | as above |

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

Since the 2026-10-28 pass the adapter also stamps the client and its grant scope through their
`attributes` maps: `victual.owner` = `victual-registration-adapter`, `victual.recipe` = the recipe
version, and on the scope `victual.grant-client` = the client id. A client created with a
deliberate deviation from the recipe (the K2 negative fixtures) carries no stamp.

`RECIPES` in `adapter.mjs` holds every recipe version the adapter can build, and `RECIPE_VERSION`
(2) is the one it builds and reconciles to. Version 2 is what `setup.mjs` builds and K1 and K2
test. Version 1 is the same without the grant mapper's `lightweight.claim` flag and the client
description. It is synthetic, so that the upgrade case has a mapper setting and a client field to
change.

`reconcile()` lists owned clients and client scopes by the owner attribute (finding 22). It repairs
client fields, the client's own mappers, default and optional scopes, the grant scope and its
mapper, the sidecar's optional-scope link and the stamps. It recreates a missing grant scope and
deletes an owned grant scope whose client no longer exists. It returns the list of changes it made.
It never repairs or deletes an object without the owner attribute.

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

Follow-up cases K1 to K5 (2026-10-26, extended 2026-10-28). K1a to K1j, K2 and the rows above this table are from the 2026-10-26 run; K1r, K1s, K3, K3b, K4 and K5 are from the 2026-10-28 cold start 2. K1r cases are named by mechanism number, then `s` (separate sessions) or `h` (same browser session), then `-r` (refresh access), `-x` (new exchanges) or `-t` (the already-issued `/api` token at Keycloak's introspection). The 2026-10-26 K1r rows asserted refresh and a probe-side lookup together; each row now asserts one outcome.

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
| K1r1s-r | 0055 d6, q13, q20, prerequisite 10: deleting the user's consent for client A (admin API), separate sessions: refresh access | A's refresh token refused; B's still refreshes | call -> 204; refresh A -> 400 invalid_grant (Session doesn't have required client); refresh B -> 200 token | pass |
| K1r1s-x | 0055 d6, q13, q20, prerequisite 10: deleting the user's consent for client A (admin API), separate sessions: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 200 token | pass |
| K1r1s-t | 0055 d6, q13, q20, prerequisite 10: deleting the user's consent for client A (admin API), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=true, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=false iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | **FAIL** |
| K1r2s-r | 0055 d6, q13, q20, prerequisite 10: revoking client A's refresh token (RFC 7009), separate sessions: refresh access | A's refresh token refused; B's still refreshes | call -> 200; refresh A -> 400 invalid_grant (Session not active); refresh B -> 200 token | pass |
| K1r2s-x | 0055 d6, q13, q20, prerequisite 10: revoking client A's refresh token (RFC 7009), separate sessions: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 200; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 200 token | pass |
| K1r2s-t | 0055 d6, q13, q20, prerequisite 10: revoking client A's refresh token (RFC 7009), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 200; after: introspect A active=false, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=true iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | pass |
| K1r3s-r | 0055 d6, q13, q20, prerequisite 10: deleting the user session of grant A (admin API, by sid), separate sessions: refresh access | A's refresh token refused; B's still refreshes | call -> 204; refresh A -> 400 invalid_grant (Session not active); refresh B -> 200 token | pass |
| K1r3s-x | 0055 d6, q13, q20, prerequisite 10: deleting the user session of grant A (admin API, by sid), separate sessions: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 200 token | pass |
| K1r3s-t | 0055 d6, q13, q20, prerequisite 10: deleting the user session of grant A (admin API, by sid), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=false, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=true iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | pass |
| K1r4s-r | 0055 d6, q13, q20, prerequisite 10: client A's not-before (admin API PUT notBefore=now), separate sessions: refresh access | A's refresh token refused; B's still refreshes | call -> 204; refresh A -> 400 invalid_grant (Stale token); refresh B -> 200 token | pass |
| K1r4s-x | 0055 d6, q13, q20, prerequisite 10: client A's not-before (admin API PUT notBefore=now), separate sessions: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 200 token | pass |
| K1r4s-t | 0055 d6, q13, q20, prerequisite 10: client A's not-before (admin API PUT notBefore=now), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=true, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=true consent=true iat<notBefore=true; B session holds client=true consent=true iat<notBefore=false | **FAIL** |
| K1r5s-r | 0055 d6, q13, q20, prerequisite 10: logging the user out of every session (admin API), separate sessions: refresh access | both grants' refresh tokens refused | call -> 204; refresh A -> 400 invalid_grant (Session not active); refresh B -> 400 invalid_grant (Session not active) | pass |
| K1r5s-x | 0055 d6, q13, q20, prerequisite 10: logging the user out of every session (admin API), separate sessions: new exchanges | neither grant's unexpired user access token can be exchanged | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 400 invalid_request (Invalid token) | pass |
| K1r5s-t | 0055 d6, q13, q20, prerequisite 10: logging the user out of every session (admin API), separate sessions: the already-issued /api token at Keycloak | both exchanged tokens inactive at introspection by the /api client | before: A active=true, B active=true; call -> 204; after: introspect A active=false, B active=false; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=true iat<notBefore=false; B session holds client=false consent=true iat<notBefore=false | pass |
| K1r8s-t | 0055 d6, q13, q20, prerequisite 10: the /api client's not-before (admin API PUT notBefore=now on the audience client), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=true, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=true consent=true iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | **FAIL** |
| K1r9s-t | 0055 d6, q13, q20, prerequisite 10: the sidecar's not-before (admin API PUT notBefore=now on the exchanger, the tokens' azp), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=false, B active=false; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=true consent=true iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | **FAIL** |
| K1r10s-t | 0055 d6, q13, q20, prerequisite 10: the sidecar revokes A's exchanged token (RFC 7009, authenticated as the sidecar), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 200; after: introspect A active=false, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=true consent=true iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | pass |
| K1r1h-r | 0055 d6, q13, q20, prerequisite 10: deleting the user's consent for client A (admin API), same browser session: refresh access | A's refresh token refused; B's still refreshes | call -> 204; refresh A -> 400 invalid_grant (Session doesn't have required client); refresh B -> 200 token | pass |
| K1r1h-x | 0055 d6, q13, q20, prerequisite 10: deleting the user's consent for client A (admin API), same browser session: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 200 token | pass |
| K1r1h-t | 0055 d6, q13, q20, prerequisite 10: deleting the user's consent for client A (admin API), same browser session: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=true, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=false iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | **FAIL** |
| K1r2h-r | 0055 d6, q13, q20, prerequisite 10: revoking client A's refresh token (RFC 7009), same browser session: refresh access | A's refresh token refused; B's still refreshes | call -> 200; refresh A -> 400 invalid_grant (Session doesn't have required client); refresh B -> 200 token | pass |
| K1r2h-x | 0055 d6, q13, q20, prerequisite 10: revoking client A's refresh token (RFC 7009), same browser session: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 200; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 200 token | pass |
| K1r2h-t | 0055 d6, q13, q20, prerequisite 10: revoking client A's refresh token (RFC 7009), same browser session: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 200; after: introspect A active=true, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=true iat<notBefore=false; B session holds client=true consent=true iat<notBefore=false | **FAIL** |
| K1r3h-r | 0055 d6, q13, q20, prerequisite 10: deleting the user session of grant A (admin API, by sid), same browser session: refresh access | A's refresh token refused; B's still refreshes | call -> 204; refresh A -> 400 invalid_grant (Session not active); refresh B -> 400 invalid_grant (Session not active) | **FAIL** |
| K1r3h-x | 0055 d6, q13, q20, prerequisite 10: deleting the user session of grant A (admin API, by sid), same browser session: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 400 invalid_request (Invalid token) | **FAIL** |
| K1r3h-t | 0055 d6, q13, q20, prerequisite 10: deleting the user session of grant A (admin API, by sid), same browser session: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=false, B active=false; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=true iat<notBefore=false; B session holds client=false consent=true iat<notBefore=false | **FAIL** |
| K1r4h-r | 0055 d6, q13, q20, prerequisite 10: client A's not-before (admin API PUT notBefore=now), same browser session: refresh access | A's refresh token refused; B's still refreshes | call -> 204; refresh A -> 400 invalid_grant (Stale token); refresh B -> 200 token | pass |
| K1r4h-x | 0055 d6, q13, q20, prerequisite 10: client A's not-before (admin API PUT notBefore=now), same browser session: new exchanges | the sidecar cannot exchange A's unexpired user access token; B's still exchanges | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 200 token | pass |
| K1r4h-t | 0055 d6, q13, q20, prerequisite 10: client A's not-before (admin API PUT notBefore=now), same browser session: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=true, B active=true; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=true consent=true iat<notBefore=true; B session holds client=true consent=true iat<notBefore=false | **FAIL** |
| K1r5h-r | 0055 d6, q13, q20, prerequisite 10: logging the user out of every session (admin API), same browser session: refresh access | both grants' refresh tokens refused | call -> 204; refresh A -> 400 invalid_grant (Session not active); refresh B -> 400 invalid_grant (Session not active) | pass |
| K1r5h-x | 0055 d6, q13, q20, prerequisite 10: logging the user out of every session (admin API), same browser session: new exchanges | neither grant's unexpired user access token can be exchanged | call -> 204; exchange A's user token -> 400 invalid_request (Invalid token); exchange B's -> 400 invalid_request (Invalid token) | pass |
| K1r5h-t | 0055 d6, q13, q20, prerequisite 10: logging the user out of every session (admin API), same browser session: the already-issued /api token at Keycloak | both exchanged tokens inactive at introspection by the /api client | before: A active=true, B active=true; call -> 204; after: introspect A active=false, B active=false; stateless check A/B: accepted/accepted; lookup inputs (observation, not a Victual check): A session holds client=false consent=true iat<notBefore=false; B session holds client=false consent=true iat<notBefore=false | pass |
| K1r6s-r | 0055 d6, q13, q20, prerequisite 10: deleting the offline session of grant A (admin API, isOffline=true), separate sessions: refresh access | A's offline refresh token refused; B's still refreshes | exchanged token's sid is the offline session (true); call -> 204; refresh A -> 400 invalid_grant (Offline user session not found); refresh B -> 200 token | pass |
| K1r6s-t | 0055 d6, q13, q20, prerequisite 10: deleting the offline session of grant A (admin API, isOffline=true), separate sessions: the already-issued /api token at Keycloak | A's exchanged token inactive at introspection by the /api client at once; B's still active | before: A active=true, B active=true; call -> 204; after: introspect A active=false, B active=true | pass |
| K1r7s | 0055 d6: revoking client A's user access token (RFC 7009) does not revoke the grant or its exchanged tokens | the exchanged token stays active (the guide: revoking the subject access token does not revoke the exchanged one); refresh still works | revoke -> 200; introspect A's exchanged token active=true; refresh A -> 200 token | pass |
| K1s | 0055 d6: a stateless check of an exchanged token after its grant was revoked | accepted until exp (the E cases measure when it stops) | consent deleted; stateless check -> accepted; exp in 300 s | pass |
| K5a | 0055 d6, q19, prerequisite 10: deleting the shared session (admin API, by sid): the affected grants are listed before the action and are exactly the grants that stop | the listed grants all belong to alice (and, for K5a, to the one session); after the action exactly the listed grants stop (introspection inactive and refresh refused); alice's grant in another session (K5a) and bob's grant keep working | shared sid a1==a2: true; before: all active=true; listed=[alice-spike/19-LtxfH/victual-spike-mcp-k1b, alice-spike/19-LtxfH/victual-spike-mcp-k1a]; call -> 204; after: alice-spike/19-LtxfH/victual-spike-mcp-k1a stopped; alice-spike/19-LtxfH/victual-spike-mcp-k1b stopped; alice-spike/CyyfA-ZI/victual-spike-mcp-k1a working; bob-spike/ACCrFSat/victual-spike-mcp-k1b working | pass |
| K5b | 0055 d6, q19, prerequisite 10: logging alice out of every session (admin API): the affected grants are listed before the action and are exactly the grants that stop | the listed grants all belong to alice (and, for K5a, to the one session); after the action exactly the listed grants stop (introspection inactive and refresh refused); alice's grant in another session (K5a) and bob's grant keep working | shared sid a1==a2: true; before: all active=true; listed=[alice-spike/Bh3nO_yV/victual-spike-mcp-k1b, alice-spike/Bh3nO_yV/victual-spike-mcp-k1a, alice-spike/pzmtYMep/victual-spike-mcp-k1a]; call -> 204; after: alice-spike/Bh3nO_yV/victual-spike-mcp-k1a stopped; alice-spike/Bh3nO_yV/victual-spike-mcp-k1b stopped; alice-spike/pzmtYMep/victual-spike-mcp-k1a stopped; bob-spike/TKEwL7Tm/victual-spike-mcp-k1b working; alice's sessions left: 0 | pass |
| K2a | 0055 d3, d8, prerequisite 11: tokens of two clients the adapter created are accepted at the sidecar | both accepted; aud holds the MCP audience; the client's default scopes include victual-mcp-audience and not victual-api-audience | client 1: accepted aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; client 2: accepted aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; client 1 default scopes=acr,victual-mcp-audience,victual-groups,victual-grant:victual-spike-mcp-k2-1,profile,basic,email; optional=victual:read,offline_access,victual:write,victual:stock:book,victual:stock | pass |
| K2b | 0055 d3, d8, prerequisite 11: a client cannot select an audience it is not bound to | each attempt is refused, or the token's aud stays within the MCP audience and the sidecar's client id | audience=/api parameter -> 200 aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; resource=/api -> 200 aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; resource=unknown -> 200 aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; scope victual-api-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-api-audience); scope victual-rogue-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-rogue-audience); scope victual-other-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-other-audience) | pass |
| K2c | 0055 d3, d8, prerequisite 11: a client without the audience scope has no MCP audience, is refused at the sidecar and cannot bind itself | sidecar refuses; asking for the scope at authorize is refused; the sidecar cannot exchange its token | aud=[]; sidecar check -> empty aud; asking for victual-mcp-audience -> 400 invalid_scope (Invalid scopes: openid victual:read victual-mcp-audience); exchange -> 403 access_denied (Client is not within the token audience) | pass |
| K2d | 0055 d7, d8, prerequisite 11: exchanged tokens target only /api | aud is exactly the /api URI; accepted at /api; refused at the sidecar | client 1 exchanged aud="http://localhost:8080/api"; client 2 exchanged aud="http://localhost:8080/api"; /api check: accepted, accepted; sidecar check of an exchanged token: aud http://localhost:8080/api lacks http://localhost:3000/mcp | pass |
| K2e | 0055 d7, d8, prerequisite 11: an exchanged token cannot be exchanged back into the MCP audience | every attempt is refused, or issues no MCP audience | sidecar: x1, audience=MCP URI -> 400 invalid_request; sidecar: x1, audience=sidecar -> 400 invalid_request; sidecar: x1, scope victual-mcp-audience -> 400 invalid_scope; sidecar: user token, audience=MCP URI -> 400 invalid_request; sidecar: user token, scope victual-mcp-audience -> 400 invalid_scope; client named by the MCP URI: x1 -> 403 access_denied | pass |
| K2g | 0055 d8, prerequisite 11: an anonymous registrant cannot bind itself to the MCP audience (Allowed Client Scopes list empty) | a registration asking for the scope is refused or the client does not hold it; a client registered without it is refused the scope at authorize and its tokens lack the MCP audience | register with scope -> 403 Policy 'Allowed Client Scopes' rejected request to client-registration; client holds the scope: false; register without scope -> 201, client holds the scope: false; authorize with the scope -> 400 invalid_scope (Invalid scopes: openid victual-mcp-audience); token aud=[] | pass |
| K2h | 0055 d8, prerequisite 11: demonstration of what the policy must forbid: the audience scope on the Allowed Client Scopes list of anonymous registration | with the scope on the list, a dynamic registrant binds itself to the MCP audience (so the list must not contain it) | list = [victual-mcp-audience]: register -> 201 granted scope="victual-mcp-audience"; client holds the scope: true; authorize with it -> 200 token; token aud=["http://localhost:3000/mcp","victual-spike-sidecar"]; the list was restored afterwards | pass |
| K3b | 0055 d3, prerequisite 8: the /api resource client cannot obtain tokens through any flow | client credentials, password and authorization code requests as the /api client are refused | client_credentials -> 401 unauthorized_client (Client not enabled to retrieve service account); password -> 400 unauthorized_client (Client not allowed for direct access grants); authorization code -> 400 authorize_refused (Sign in to victual { "imports": { "rfc4648": "/resources/lleuy/common/keycloak/vendor/rfc4) | pass |
| K3 | 0055 prerequisite 8: the installed configuration equals what setup.mjs and adapter.mjs promise (README inventory) | every realm setting, client scope with its mappers, client policy, profile, client flag and adapter stamp listed in the inventory is present with the stated value | realm settings 8, client scopes 10 with mappers, profiles 2 (victual-downscope, victual-dcr), policies 2, client flags (mcp, sidecar, api, adapter client), adapter ownership and recipe-version stamps on the client and its grant scope (recipe 2) all as stated | pass |
| K4a | 0055 prerequisite 8, q18: every object the adapter creates records its owner and recipe version through a supported interface | the client's and the grant scope's attributes hold victual.owner=victual-registration-adapter and victual.recipe=2 after a read-back; the scope names its client; listing by owner returns exactly the adapter-built clients (k1a, k1b, k4-1) | client attributes: {"victual.owner":"victual-registration-adapter","victual.recipe":"2"}; scope attributes: {"victual.grant-client":"victual-spike-mcp-k4-1","victual.owner":"victual-registration-adapter","victual.recipe":"2"}; attribute query q=victual.owner:victual-registration-adapter -> 400 {"error":"invalid_request","error_description":"Attributes [victual.owner] not allowed for search"}; listed by owner: [victual-spike-mcp-k1a, victual-spike-mcp-k1b, victual-spike-mcp-k4-1]; owned scopes 3 | pass |
| K4b | 0055 prerequisite 8, q18: reconciliation is repeatable | a second run with nothing changed in between makes no change | first run: 0 changes; second run: 0 changes | pass |
| K4c | 0055 prerequisite 8, q18: reconciliation repairs drift in owned objects | after five manual changes (grant mapper relabelled to another client, audience scope removed, sidecar link removed, Consent required off, a client-level mapper added) one run restores the recipe; tokens name the right client again; the next run changes nothing | changes: client victual-spike-mcp-k4-1: set consentRequired; client victual-spike-mcp-k4-1: removed client mapper drift-client-mapper; scope victual-grant:victual-spike-mcp-k4-1: mapper config claim.value; client victual-spike-mcp-k4-1: added default scope victual-mcp-audience; sidecar: added optional scope victual-grant:victual-spike-mcp-k4-1; after: claim.value=victual-spike-mcp-k4-1, audience default=true, sidecar link=true, consentRequired=true, client mappers=0; token: aud=["victual-spike-sidecar","http://localhost:3000/mcp"] exchanged victual_client=victual-spike-mcp-k4-1; next run: 0 changes | pass |
| K4d | 0055 prerequisite 8, q18: reconciliation removes an owned orphan | an owned grant scope whose client was deleted outside the adapter is deleted, and the sidecar no longer lists it | scope present before: true; changes: scope victual-grant:victual-spike-mcp-k4-2: deleted (orphan; client victual-spike-mcp-k4-2 absent); scope gone: true; sidecar lists it: false | pass |
| K4f | 0055 prerequisite 8, q18: an upgrade from recipe 1 to recipe 2 updates every owned object and records the new version | clients created at recipe 1 get recipe 2's mapper setting (lightweight.claim true) and client description; every owned client and scope then records recipe 2; tokens still name the client; a second run changes nothing | stamps before: 1,1; changes, for each of k4-3 and k4-4: client description and `victual.recipe`; scope `victual.recipe`; mapper `lightweight.claim` (six changes). Owned objects after: all ten (five clients, five grant scopes) at recipe 2; k4-3 mapper lightweight.claim before=false after=true; token victual_client=victual-spike-mcp-k4-3; next run: 0 changes. Full text in results.json | pass |
| K4e | 0055 prerequisite 8, q18: reconciliation never touches an object it does not own | an unstamped client and grant scope with adapter-like names, an unstamped scope naming a missing client, and a scope stamped with another owner are byte-identical after every reconcile run above, and no change names them | reconcile runs: 12 changes in total; changes naming a lookalike: none; snapshot identical: true | pass |
| K4g | 0055 prerequisite 8, q18: ownership and recipe records survive a Keycloak restart | after a container restart on the same database, owned objects keep their attributes and mapper settings, reconciliation changes nothing, and a grant token still names its client | restart took 5 s; owned objects: 4; attributes and mappers identical: true; reconcile after restart: 0 changes; token victual_client=victual-spike-mcp-k1a | pass |

Expiry run (`node probe.mjs --expiry`, 2026-10-28 cold start 1, after the core run). One user per measurement; the grant is revoked 5 s after the exchanged token's `iat`; introspection by the `/api` client and the stateless check are polled once a second. E1 runs once at the realm's 300 s lifespan; E2 runs five times per mechanism with the sidecar's `access.token.lifespan` set to 20 s, concurrently with E1. The dev run before it (same code) gave the same verdicts and figures within 0.8 s.

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| E0 | 0055 d6, q20: the lifespans the expiry measurement runs under | real: exchanged tokens carry the realm's 300 s; short: an override gives 20 s | real exchanged exp-iat=300,300,300 s (user tokens 300,300,300 s); sidecar attribute access.token.lifespan=20: exchanged exp-iat=20 s; short exchanged exp-iat=20 s; revocation at iat+5 s; poll interval 1 s | pass |
| E1-consent | 0055 d6, q20, prerequisite 10: already-issued /api token after consent deletion, real 300 s lifespan (one run) | refused at introspection by exp at the latest; refused by the stateless check by exp + 60 s (its clock skew) at the latest | iat+5 s revoke (204); refresh after -> 400 invalid_grant (Session doesn't have required client); introspection refused at iat+301.9 s = exp+1.9 s, 296.9 s after revocation; stateless refused (expired) at iat+360.2 s = exp+60.2 s, 355.2 s after revocation | pass |
| E1-session | 0055 d6, q20, prerequisite 10: already-issued /api token after session deletion, real 300 s lifespan (one run) | refused at introspection by exp at the latest; refused by the stateless check by exp + 60 s (its clock skew) at the latest | iat+5 s revoke (204); refresh after -> 400 invalid_grant (Session not active); introspection refused at iat+5.1 s = exp-294.9 s, 0.1 s after revocation; stateless refused (expired) at iat+360.3 s = exp+60.3 s, 355.3 s after revocation | pass |
| E1-refresh | 0055 d6, q20, prerequisite 10: already-issued /api token after refresh-token revocation, real 300 s lifespan (one run) | refused at introspection by exp at the latest; refused by the stateless check by exp + 60 s (its clock skew) at the latest | iat+5 s revoke (200); refresh after -> 400 invalid_grant (Session not active); introspection refused at iat+5.1 s = exp-294.9 s, 0.1 s after revocation; stateless refused (expired) at iat+360.3 s = exp+60.3 s, 355.3 s after revocation | pass |
| E2-consent | 0055 d6, q20, prerequisite 10: already-issued /api token after consent deletion, 20 s override, 5 runs | every run: refused at introspection by exp at the latest; refused by the stateless check by exp + 60 s at the latest | 5/5 runs measured; min/median/max s: introspection refused after revocation 16.2/16.3/16.3, relative to exp 1.2/1.3/1.3; stateless refused after revocation 75.7/75.7/75.7, relative to exp 60.7/60.7/60.7; stateless reason expired; refresh after revocation 400 invalid_grant | pass |
| E2-session | 0055 d6, q20, prerequisite 10: already-issued /api token after session deletion, 20 s override, 5 runs | every run: refused at introspection by exp at the latest; refused by the stateless check by exp + 60 s at the latest | 5/5 runs measured; min/median/max s: introspection refused after revocation 0/0.1/0.1, relative to exp -15/-14.9/-14.9; stateless refused after revocation 75.7/75.7/75.7, relative to exp 60.7/60.7/60.7; stateless reason expired; refresh after revocation 400 invalid_grant | pass |
| E2-refresh | 0055 d6, q20, prerequisite 10: already-issued /api token after refresh-token revocation, 20 s override, 5 runs | every run: refused at introspection by exp at the latest; refused by the stateless check by exp + 60 s at the latest | 5/5 runs measured; min/median/max s: introspection refused after revocation 0/0/0.1, relative to exp -15/-14.9/-14.9; stateless refused after revocation 75.7/75.7/75.7, relative to exp 60.7/60.7/60.7; stateless reason expired; refresh after revocation 400 invalid_grant | pass |

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

Two-replica run (`./run.sh ha-up`, `node ha.mjs`, 2026-10-29). Two production-mode replicas
(`start --optimized` from a `kc.sh build --db=postgres --cache=ispn` image, cache stack
`jdbc-ping`) on one PostgreSQL database, each capped at 1200 MiB, behind the probe's proxy on
:8180. The proxy alternates per request and skips a replica that refuses the connection, as a
Kubernetes Service skips an unready pod. Kills are `podman kill -s KILL`.

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| H0 | 0055 prerequisite 2: two replicas share state | both replicas log a two-member Infinispan view | both log view `(2) [ha-a, ha-b]`; two rows in `jgroups_ping`; `cache-ispn.xml` defines no cache, so Keycloak's built-in cache defaults apply | pass |
| H1 | prerequisite 2: login and consent across replicas | six login, consent and code redemptions, alternating replicas per request, all issue tokens | 6/6 issued; 10 requests to each replica | pass |
| H2 | prerequisite 2: refresh rotation across replicas (decision 6) | rotate on a, then on b; reuse of the first rotated token on a refused, then the newest refused on b | rotate a 200; rotate b 200; reuse on a `400 invalid_grant` (reuse exceeded); newest on b `400 invalid_grant` | pass |
| H3 | prerequisite 2: revocation across replicas | a refresh token revoked on a (RFC 7009) is refused on b | revoke on a 200; refresh on b `400 invalid_grant` (session not active); the access token inactive at introspection on b | pass |
| H4a | prerequisite 2; the K1 lookups | alice's sessions and consents read directly from each replica are identical | 8 sessions and the same consent on both | pass |
| H4b | prerequisite 2: administrative revocation across replicas | a session deleted through b's admin API refuses its refresh token on a | delete on b 204; refresh on a `400 invalid_grant` | pass |
| H5 | prerequisite 2 with decision 7: exchange across replicas | a user token from a, exchanged by the sidecar on b, is accepted at `/api` | exchange on b 200; `/api` check accepted | pass |
| H6 | prerequisite 2: a pod killed mid-login | login form from a; a killed; credentials and code redemption on b issue a token | 200 token; every request after the kill reached b | pass |
| H6r | prerequisite 2: a killed replica rejoins | the restarted replica logs a two-member view | both log `(2) [ha-b, ha-a]` | pass |
| H7 | prerequisite 2: a pod killed between code and redemption | code issued by b; b killed; the code is redeemed on a | 200 token | pass |
| H8 | prerequisite 2: a pod killed while holding the grant | grant from a; a killed; refresh on b works; the rotated-away token is refused on the restarted a | refresh on b 200; old token on a `400 invalid_grant` (reuse exceeded) | pass |
| H9 | prerequisite 2: full outage | both killed and restarted; a refresh token from before the outage works and the consent remains | the grant's session row was in `offline_user_session` before the outage; refresh 200; sidecar check accepted; consent present | pass |
| H9p | observation: what a full outage loses | a login whose form was served before the outage | the credentials were answered with the login form again: the authentication session did not survive | obs |

Nix image run (`./run.sh nix-up`, `node setup.mjs && node probe.mjs`, 2026-10-29). The core run
against `localhost/victual-spike-keycloak-nix:26.8.1` (finding 26), read-only root with
`--read-only-tmpfs=false`. Every case present in both runs gave the same verdict as the upstream
image's core run above (101 pass; the same 22 FAIL and n/a). K4g restarted the container in
23 s. I1 reads `uid 65532 (PID 1 user keycloak; no id in the image)`; the image has no `id`, so I1
now falls back to podman's view of PID 1.

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
    the exchanged token until it expires. The 2026-10-26 report said that fits decision 6's
    five-minute worst case; the E cases measure it at `exp` + 60 s, because of the check's skew
    (finding 23). R6
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
18. **K1: revoking one grant stops its refresh and new exchanges; only some mechanisms reach the
    already-issued `/api` token (K1r cases, corrected 2026-10-28).** The 2026-10-26 version of this
    table counted a probe-side lookup on `victual_client`, `sid` and `sub` as blocking the exchanged
    token. That lookup simulated a Victual check that does not exist. It is now an observation in
    the `-t` cases and decides nothing. The table shows, for grant A revoked and grant B of the same
    user left alone, what Keycloak itself does. "Refresh / exchange" is A's refresh token and a new
    exchange of A's unexpired user access token (`-r`, `-x`). "Issued token" is Keycloak's
    introspection, by the `/api` client, of the `/api` token already exchanged from A (`-t`).

    | Mechanism | Separate sessions: refresh / exchange; issued token | Same SSO session: refresh / exchange; issued token |
    |---|---|---|
    | Delete consent for A (K1r1) | A refused, B works; **active** (FAIL) | A refused, B works; **active** (FAIL) |
    | Revoke A's refresh token (K1r2) | A refused, B works; inactive at once | A refused, B works; **active** (FAIL) |
    | Delete A's session by `sid` (K1r3) | A refused, B works; inactive at once | A and B refused; A and B inactive (FAIL as per-grant; K5a) |
    | Client A not-before (K1r4) | A refused, B works; **active** (FAIL) | A refused, B works; **active** (FAIL) |
    | Log the user out (K1r5) | A and B refused; both inactive (user-wide, as expected) | same |
    | Delete A's offline session (K1r6) | A refused, B works; inactive at once | not run |
    | Revoke A's user access token, RFC 7009 (K1r7) | no effect, as the guide says | not run |
    | `/api` client not-before (K1r8) | not asked; **active** for A and B (FAIL) | not run |
    | Sidecar not-before (K1r9) | not asked; inactive for A and B (FAIL as per-grant) | not run |
    | Sidecar revokes A's exchanged token, RFC 7009 (K1r10) | not asked; A inactive, B active | not run |

    Refresh and new exchanges stop per grant for every per-grant mechanism, including the
    not-before change (K1r4s-x and K1r4h-x close the item the 2026-10-26 report left untested).

    The already-issued token is refused at Keycloak only when the grant's session ends or when the
    token itself is revoked. Consent deletion, which is what a user does in Keycloak's account
    console, never reaches it. Introspection checks the session and the client of the token's
    `azp`, which is the sidecar. A not-before on client A or on the `/api` client therefore does
    not apply, and a not-before on the sidecar ends every exchanged token of every grant.

    K1r10 is the one per-token path: the sidecar, which issued the exchange, revokes the exchanged
    token, and introspection refuses it at once while B's token stays active. It needs the sidecar
    to keep a list of the tokens it exchanged per grant, and it does nothing for a stateless
    resource server. Where Keycloak does not refuse the token, the measured expiry delay applies
    (finding 23).

19. **K1: which side is authoritative.** Decision 6's grant row in Victual is the authoritative
    refusal point for Victual requests. Victual's immediate grant-revocation check does not exist,
    and nothing in this spike tests it. As K1 established, it would read the exchanged token's
    `victual_client`, `sid` and `sub`. The `-t` cases print the admin-API facts such a check could
    read as "lookup inputs" (session holds the client, consent stored, client not-before). They
    show that those facts change after each mechanism. They do not show that a check built on them
    works.

    Keycloak-side revocation otherwise reaches Victual after at most the token lifespan plus the resource
    server's 60 s skew (finding 23). Keycloak has no supported push for it: the 26.8.1 event
    listener providers are `workflow-event-listener`, `jboss-logging` and `email`, and none calls
    an HTTP endpoint. A user who revokes the consent in Keycloak's account console therefore does
    not change Victual's grant row.

    Polling the user events for a revoked grant needs events
    enabled on the realm (off in this realm) and was not tried. A per-request lookup would cost
    three or four admin REST calls and an admin credential in Victual; that and its caching were
    not measured.
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

22. **K4: the adapter's runtime objects carry owner and recipe version, and reconciliation is
    repeatable (K4a to K4g, question 18).** Clients and client scopes keep arbitrary entries in
    their `attributes` maps through the admin REST API. `victual.owner`, `victual.recipe` and
    `victual.grant-client` read back unchanged (K4a) and survive a restart of the server container on
    the same database (K4g; the restart took 5 s). Keycloak refuses `GET /clients?q=victual.owner:...`
    with `400 Attributes [victual.owner] not allowed for search`, so listing by owner reads every
    client and filters on the attribute. Making the attribute searchable is a server option that was
    not tried. Client scopes have no query parameter at all. Reconciliation found nothing to change
    on a fresh realm and nothing on a second run (K4b). K4c made five manual changes to one client:
    the grant mapper relabelled to another client, the audience scope removed, the sidecar link
    removed, Consent required off, and a mapper added to the client itself. One run restored the
    recipe, and the client's exchanged token named it again. An owned grant scope whose client was deleted
    behind the adapter's back was deleted (K4d). An unstamped client and scope with adapter-like
    names, an unstamped scope naming a missing client, and a scope stamped with another owner were
    byte-identical after every run (K4e). Two clients built at recipe 1 were moved to recipe 2: the
    mapper's `lightweight.claim`, the client description and both stamps changed, and every owned
    object then recorded recipe 2 (K4f). Keycloak adds `userinfo.token.claim` = `false` to a stored
    mapper on its own, so reconciliation compares only the keys the recipe sets.
23. **E: an already-issued `/api` token works until its expiry plus the resource server's skew
    when Keycloak does not refuse it (E0 to E2, question 20).** One run at the realm's 300 s
    lifespan and five runs with a 20 s lifespan per mechanism (the sidecar's
    `access.token.lifespan` attribute applies to the tokens it exchanges, E0). Each grant was
    revoked 5 s after the exchanged token's `iat`, and the token was polled once a second. After
    consent deletion, introspection by the `/api` client kept answering `active` until `exp` and
    refused it 1.2 to 1.9 s after `exp` (the poll interval and whole-second `exp`). After session
    deletion and refresh-token revocation it refused within 0.1 s. The stateless check of decision
    3 accepted the token in every mechanism until `exp` + 60.2 to 60.7 s, because it allows 60 s
    of clock skew. With the realm's lifespan that is 355.2 to 355.3 s after the revocation and
    360 s after issue. The same-day dev run gave figures within 0.8 s of these. Decision 6 states five minutes as the worst case; with a 60 s skew the measured
    worst case for a stateless resource server is six minutes.
24. **K5: a shared session's grants can be listed before they are revoked (K5a, K5b, question
    19).** `GET /users/{id}/sessions` returns each session with its client map. Alice's grants for k1a
    and k1b share one session. Before that session was deleted, the listing named exactly those two
    grants. After the deletion exactly those two stopped (introspection inactive, refresh refused).
    Alice's k1a grant in another session and bob's k1b grant kept working (K5a). Before logging
    alice out, the listing over all her sessions named the three grants that then stopped, with
    bob's grant unaffected and no session of alice left (K5b). Offline sessions are listed by a
    different endpoint (`/users/{id}/offline-sessions/{client}`) and were not part of K5.

25. **Two replicas on one database lose no grant to a single kill (H0 to H9p, prerequisite
    2).** Two production-mode replicas cluster through JDBC_PING over the shared database with no
    setting beyond `KC_CACHE=ispn` and `KC_CACHE_STACK=jdbc-ping`. Every step of a login, a rotation,
    a revocation and an exchange works with consecutive requests on different replicas. The admin
    API returns the same sessions and consents from both, so the K1 lookups read one view.

    What survives which failure:

    - One replica killed: a login in progress, an issued but unredeemed code, and a grant whose
      refresh token the dead replica issued all continue on the other (H6 to H8). A replica that
      restarts rejoins and refuses a refresh token rotated away while it was down (H8).
    - Both killed: grants survive because Keycloak 26 persists user sessions (the grant's session
      row is in `offline_user_session`), and consent is a database row (H9). Logins in progress are
      lost; the user signs in again (H9p).

    Limits: two containers behind a proxy in the probe, not two pods behind the chart's Service.
    No rolling upgrade, network partition, graceful shutdown or production TLS. Each replica used
    496 to 544 MiB after the run.
26. **A Nix-built image runs with no shell on a read-only root (I1, prerequisite 3).**
    `nix/image.nix` builds from the nixpkgs revision `flake.lock` pins, whose `keycloak` is 26.8.1,
    the same release as the rest of the spike. Three departures from the nixpkgs package:

    - `kc.sh build` runs inside the derivation with `db=postgres`, `health-enabled=true` and
      `cache=ispn`, so the image starts with `start --optimized` and never writes its install tree.
    - `bin/` is deleted: `kc.sh` and the admin CLI are bash scripts. The entrypoint is the java
      command line `kc.sh` 26.8.1 execs, written out, so no shell is in the closure.
    - `data/` is a symlink to `/var/lib/keycloak/data`, a tmpfs at runtime.

    The image runs as uid 65532 with `--read-only --read-only-tmpfs=false` and two tmpfs mounts
    (`/tmp`, the data directory); `podman exec … sh` fails because there is no shell. It started in
    2.0 s and used 550 MiB after the probe. The runtime closure is the Keycloak tree (168 MiB, no
    references) and OpenJDK headless 21.0.12 (618 MiB with glibc and its libraries). The image is
    827 MB against the upstream image's 478 MB. nixpkgs' `jre_headless` is a full headless JDK
    with `jmods`; a `jlink`-trimmed runtime would close most of the gap and was not tried. arm64
    only; amd64 not built.

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
| `accessTokenLifespan` 300 | realm setting | Five-minute access tokens (decision 6) | P2 (ttl 300 s), E0 and E1 (exchanged tokens 300 s) | none for the realm value: N13 and N14 use the 3 s client override. Flag |
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
| Consent required on the client | client setting | A stored consent per client, revocable per client | K1j | K1j (the screen shows again after the consent is deleted), K1r1s-r, K1r1h-r |
| `victual.owner`, `victual.recipe` on the client; the same plus `victual.grant-client` on the grant scope | client and client-scope attributes | Ownership and recipe version of each generated object (question 18), read by `reconcile()` | K4a, K4g, K3 | K4e (unstamped and foreign-owned lookalikes are left alone) |
| No mapper on the client itself | client (dedicated scope) | Only the grant scope names the client | K4c (removed by reconcile) | K1b |

Items present or changed outside `setup.mjs`:

| Name | Kind | What it enforces | Positive case | Negative case |
|---|---|---|---|---|
| Trusted Hosts (anonymous) | registration policy | Keycloak default, no host trusted, so anonymous registration is closed | D1 (trusted host registers) | N15 |
| Allowed Client Scopes (anonymous) | registration policy | Keycloak default, empty allow list: a registrant cannot name the audience scope | K2h (list changed for the demonstration, then restored) | K2g |
| Authentication flows, identity provider, user profile setting | `probe.mjs` L cases | Evidence for brokering only; created and removed inside the L cases | L1 to L4 | L4 |
| `victual-k1-notes`, a lightweight policy, and client mappers | `probe.mjs` K1a, K1b, K1h | Experiments, removed at the end of each case | K1h | K1a, K1b |
| K4 clients, lookalike client and scopes; not-before values; the sidecar's 20 s `access.token.lifespan` and the expiry users | `probe.mjs` K4, K1r4, K1r8, K1r9, `--expiry` | Fixtures, removed or reset at the end of each case or run | K4a to K4g, E0 | K4e |

Gaps that block a clean prerequisite 8 for Keycloak:

1. Four realm settings have no test: `ssoSessionIdleTimeout` (one positive observation only),
   `ssoSessionMaxLifespan`, `registrationAllowed` and the access token lifespan as a realm value
   (no negative). They are session policy, not token validation. The maintainer can decide
   whether they count as "configuration that needs tests" or as settings.
2. `serviceAccountsEnabled` on the sidecar has no core-run case; it exists for the Preview run.
3. Two fixture scopes and five fixture clients carry no deployment meaning.
4. The per-client objects are created at run time. The maintainer's answer to question 18
   (2026-10-26) accepts a versioned recipe in place of committed objects, given a pinned adapter
   and templates, owner and recipe-version records on each generated object, and repeatable
   reconciliation and upgrade. Since the 2026-10-28 pass the spike has all three. `RECIPES` and
   `RECIPE_VERSION` in `adapter.mjs` are the pinned templates. Every adapter-built client and grant
   scope carries `victual.owner` and `victual.recipe` (K4a, K3, kept across a restart in K4g).
   `reconcile()` is idempotent, repairs drift, deletes its own orphans, never touches unowned
   objects and upgrades recipe 1 to recipe 2 (K4b to K4f). Three things are left. Objects built
   before the stamps existed carry none, so `reconcile()` treats them as unowned, and an adoption
   step would be needed. Listing by owner reads every client, because the attribute is not
   searchable by default (finding 22). The recipe here is spike code, not Victual's adapter.
5. The client policies hold for this realm. A second realm (a second tenant, or a production
   realm) needs `setup.mjs` applied to it, and nothing here checks that it was.

## Not tested

- The User Storage SPI (docs only), and a deprovisioning path from Victual to Keycloak.
- Claude Code or any real MCP client against Keycloak (ADR-0055 prerequisite 5, ADR-0056
  prerequisite 2). Victual's real registration adapter does not exist; `adapter.mjs` simulates
  it with the admin REST API, and no case registers through the anonymous endpoint and then
  runs the adapter's binding.
- The full swap test (ADR-0055 prerequisite 1) with Victual and the sidecar images. Only the
  probe's resource-server check was pointed at Keycloak.
- Victual's immediate grant-revocation check (question 20). It does not exist. The `-t` cases
  print the admin-API facts it would read as an observation and decide nothing with them.
- Replicas as pods behind the chart's Service, a rolling upgrade between versions, a network
  partition between replicas, and production hostname and TLS. The H cases use two containers
  behind the probe's proxy (finding 25).
- A delegated exchange with a subject token from a client without Consent required. K1 was not
  tested with delegation.
- K1 for a client without Consent required: there is no stored consent to revoke, so the other
  mechanisms apply; all K1 clients have Consent required.
- K1r6 (offline session) in a shared SSO session; K1r8 to K1r10 in a shared SSO session.
- Making `victual.owner` a searchable client attribute (a server option), and adopting objects
  that were created before they carried stamps.
- The expiry measurement with a resource server whose clock skew is not 60 s, and with a
  sidecar-side list of exchanged tokens that it revokes per grant (K1r10 shows the revocation of
  one token only).
- Polling Keycloak's user events for a revoked grant, caching the K1 lookup, and its cost per
  request. Initial access tokens and the `authenticated` subtype of the registration policies.
- Behaviour when a mapper or policy fails; amd64 images, Nix-built or upstream; a `jlink`-trimmed
  Java runtime for the Nix image.
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
