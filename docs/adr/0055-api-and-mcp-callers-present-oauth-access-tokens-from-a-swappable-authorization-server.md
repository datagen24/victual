# ADR-0055: API and MCP callers present OAuth 2.1 access tokens from a swappable authorization server

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The maintainer answered all six open questions on 2026-10-24; the
  answers are folded into the decisions and recorded under Open questions. Acceptance
  prerequisites 1 to 5 are unmet as of 2026-10-24, and on 2026-10-25 the Hydra half of
  prerequisite 4 failed (Hydra has no RFC 8693 token exchange), which reopens the
  candidate table. The Authentik half of prerequisite 4 was met for the core exchange on
  2026-10-25, with two gaps recorded under it; prerequisite 1 has Authentik-side evidence
  and is not met. The maintainer answered the candidate-table question and open questions
  7 to 9 on 2026-10-25: Hydra is no longer the provisional target, Keycloak is the next
  candidate to evaluate, and Authentik stays the required external compatibility target
  (decisions 2, 3 and 7). The 2026-10-25 clarification assigns ordinary identity
  ownership to the provider and preserves Classic mode during migration (question 10).
  On 2026-10-26 the maintainer answered questions 11 to 14 from the server comparison
  (decisions 3, 6, 7 and 8); their evidence requirements are prerequisites 8 to 11. After
  the follow-up spikes, the maintainer answered questions 15 to 20 the same day, which
  narrow what counts as supported configuration and as revocation evidence. The
  2026-10-28 spikes tested those answers. Keycloak's generated objects meet question 18;
  prerequisites 8 to 10 remain unmet. The spikes raised questions 21 to 25. On 2026-10-29
  the Keycloak spike added evidence for prerequisites 2 and 3, and the Keycloak halves of
  prerequisites 4 and 11 were recorded; none of the four is met.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-24, against `master` at `d40f5afb`. Design only: it changes no code,
  reserves no migration number and amends no accepted record.
- **Referenced by:** [issue 770](https://github.com/datagen24/victual/issues/770);
  [ADR-0056](0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md),
  which depends on this record and must not be accepted before it.
- **Reconciled by:** the Proposed [ADR-0057](0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md) (2026-10-25): its decision 9 derives
  the leaf and bundle scopes of decision 5 from its catalog, and makes an
  administrator-registered client-credentials client a service credential.
- **Relationship:** builds on accepted [ADR-0007](0007-auth-state-outlives-the-process.md),
  [ADR-0010](0010-workload-standard.md), [ADR-0014](0014-administering-a-user-is-a-subset-question.md),
  [ADR-0018](0018-role-grants-and-domain-reads.md), [ADR-0024](0024-the-fork-writes-its-own-clients.md),
  [ADR-0038](0038-kubernetes-deployments-ship-as-a-helm-chart.md) and
  [ADR-0052](0052-the-mcp-sidecar-builds-the-catalog-and-the-chart-exposes-it.md); amends none.
  Reuses the intersection rule of the Proposed
  [ADR-0044](0044-api-keys-carry-a-subset-of-their-owners-permissions.md) without treating it
  as accepted (decision 5). Sits beside the Proposed
  [ADR-0039](0039-the-mcp-sidecar-reads-its-configuration-from-victual.md). Answers
  ADR-0052 decision 7's stage 3 question. Its implementation would amend
  [`docs/mcp-interface-spec.md`](../mcp-interface-spec.md) section 4; this record does not
  edit the spec.

## Context

Every non-browser caller authenticates with a long-lived static API key, sent as
`VICTUAL-API-KEY` or, through the MCP sidecar, as `Authorization: Bearer <key>`.
`ApiKeyAuthenticator::Authenticate` returns the key owner's user row, so a key is a bearer
secret with no client identity, no consent step and no refresh. The native iOS and macOS
clients (ADR-0024) must store that secret permanently on the device.

MCP clients that implement the MCP authorization framework cannot connect to the sidecar.
Spec section 4.1 records this as a v1 gap: the sidecar answers `404` for
`/.well-known/oauth-protected-resource`. ADR-0052 decision 7 makes remote custom connectors
(claude.ai, Claude Desktop's connector settings, the mobile apps) a stage 3 that needs a
user credential Victual can verify, because those connectors authenticate with OAuth and
cannot send a static key.

### Maintainer decision on the authorization server

The maintainer chose option 3 of issue 770 in a comment on that issue: a built-in
authorization server for standalone installs, replaceable by an external one through
configuration. The built-in authorization server is not required to be PHP and is
expected to run as its own container. The comment sets the acceptance test for the
interface: pointing the same Victual and sidecar at Authentik must require configuration
changes only. This record writes that decision down and designs the interface around it;
it does not reopen the choice.

Terms used below: the **authorization server** issues tokens; a **resource server**
accepts them (Victual's `/api` and the MCP sidecar); the **built-in authorization server**
is the container this project ships; an **external authorization server** is one the
operator runs, with Authentik as the reference ([ADR-0056](0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md)).

### Spike evidence (2026-10-11)

`.devtools/spikes/oauth-hydra/` on `master` (commit `e81b40ec`) ran Ory Hydra v26.2.0 in
`--dev` mode with an in-memory store, one container, and a stub login-and-consent app. Its
README holds the full table and the commands to reproduce it. What it established:

- Hydra delegates login and consent to an application through challenges and its admin
  API, so no credential reaches Hydra. Custom claims land under `ext`.
- Authorization code with PKCE (public clients without a challenge are refused), refresh
  rotation with reuse detection, client credentials and the device flow all work.
- Hydra ignores the RFC 8707 `resource` parameter: the token gets an empty `aud`, and an
  unknown resource is accepted silently. The spike's consent app read `resource` from the
  original authorize URL and set the audience itself.
- Hydra's dynamic client registration (RFC 7591) is unauthenticated, and its response
  failed the MCP SDK's schema. The spike turned it off and served `/register` from the
  app, which applied a policy and returned a clean body.
- A stateless JWT check kept accepting a revoked token until `exp` (15 minutes in the
  spike). Introspection reported it inactive.
- Claude Code 2.1.277 completed the flow end to end. It followed the `401` to the
  protected-resource metadata, required a `registration_endpoint`, registered dynamically
  as a public client with a loopback redirect, sent `resource`, and requested every scope
  in `scopes_supported` plus `offline_access`.

Not tested by the spike: Hydra on PostgreSQL, two replicas, a Nix-built image, a licence
review, Authentik as the swap target, and Claude Desktop, claude.ai connectors and the
native apps.

### MCP authorization specification, revision 2026-07-28

Read on 2026-10-24 at
[modelcontextprotocol.io/specification/2026-07-28/basic/authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization)
and its [security considerations](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization/security-considerations).
This revision is newer than spec section 4.1, which tells the reader to re-check it. The
requirements that shape this record:

- An MCP server must publish RFC 9728 protected-resource metadata and must reject a token
  whose audience does not name it.
- Clients must send `resource` whether or not the authorization server supports it.
- "The MCP server **MUST NOT** pass through the token it received from the MCP client."
  A call to an upstream API uses a separate token issued for that API.
- Client ID Metadata Documents are the registration method authorization servers and
  clients **SHOULD** support. Dynamic client registration is a **MAY** and is marked
  deprecated, kept for authorization servers without metadata-document support.
- `scopes_supported` is the minimal set for basic use. Further scopes are requested by
  step-up after a `403` with `error="insufficient_scope"`. Servers should not list
  `offline_access`.
- Public clients must get rotated refresh tokens, and short-lived access tokens are
  recommended.

## Decision (proposed)

### 1. Victual never issues tokens

In OAuth mode, one authorization server per install issues every access token. It is
either the built-in server or an external one. The identity provider owns ordinary user
identities, credentials, authentication and identity lifecycle in either deployment.
Victual is an OIDC relying party for ordinary browser login and a resource server for
`/api`. The MCP sidecar is a second resource server.

Victual keeps application user records, the `(issuer, subject)` mapping, roles, groups,
permissions and audit references. These records represent access within Victual; they do
not require Victual to own the external identity or its password. Victual remains the
authority for application authorization.

Local bootstrap and break-glass accounts remain available independently of the provider,
including during a provider outage. They use local sessions for recovery; they do not
make Victual a token issuer or an upstream identity provider. Their recovery behavior
must be tested with the provider unavailable (ADR-0056).

Classic mode retains the existing local-user, password, session and static-key behavior
while this work is incomplete (decision 9). The narrower purpose of local credentials
in OAuth mode does not remove ordinary local login from Classic mode.

### 2. The built-in authorization server is not chosen; Keycloak is the next candidate

No built-in authorization server is selected. Ory Hydra was the provisional target and is
removed: v26.2.0 has no RFC 8693 token exchange (prerequisite 4), which decision 7
requires. Keycloak is the next candidate to evaluate, not a selected replacement. The
maintainer's reading of its documentation (2026-10-25) is that standard token exchange is
supported and its delegation feature is a Preview. Its exchange must meet this gate:

- Token exchange that meets decision 7, including the scope ceiling and the exchanger
  identity, without relying on a Preview feature for anything decision 7 requires.
The provider owning ordinary identities is expected. Keycloak need not delegate login
back to Victual. Neither a Victual upstream OIDC provider nor a user-storage integration
that keeps ordinary passwords in Victual is an acceptance gate. Migration must instead
link provider identities to the correct application records and preserve their access,
while retaining local bootstrap and break-glass recovery.

Authentik stays the required external compatibility target, whatever is chosen as the
built-in server (ADR-0056).

Whatever is chosen runs as its own workload under ADR-0010: its own container, its own
PostgreSQL database and role (ADR-0008 permits no other engine), an optional component of
the Helm chart (ADR-0038) that is off by default, two replicas. An upgrade therefore adds
no workload until an operator turns the component on (open question 5). The rest of this
record does not depend on which server is chosen, except through decision 7's exchange
and decision 8's registration adapter.

### 3. The resource-server contract uses standard surface only

Victual and the sidecar read one configuration: the issuer URL, the audience each one
accepts, and optionally an explicitly configured discovery URL. Without that URL they
fetch RFC 8414 or OIDC discovery metadata from the issuer; with it, from the configured
URL. Either way the returned metadata must name the pinned issuer, or the configuration
is refused. Its JWKS comes from that metadata. Nothing else about the authorization
server is configured or assumed.

The discovery URL exists because the Authentik spike needed it: with one issuer for the
install, Authentik serves no discovery document at the issuer URL (prerequisite 1). The
maintainer approved it on 2026-10-25.

"Configuration only" includes provider policies, scope mappings and similar provider
configuration (question 11), on three conditions: each uses a supported provider
interface, is versioned with the deployment configuration, and passes positive and
negative tests. The Victual and sidecar images stay unchanged between authorization
servers. The swap test runs with the exact policies and mappings installed
(prerequisite 1).

A supported expression editor does not make everything written in it supported
(question 15). An expression that imports the provider's internal models, or mutates the
provider's database directly, does not count without an explicit upstream stability
contract. Deleting rows must also preserve the provider's invariants and audit behaviour.

Objects the registration adapter generates at run time (per-client scopes, mappings,
providers) are versioned through their recipe (question 18). The adapter and its
templates are pinned; each generated object records its owner and recipe version; and
reconciliation and upgrades are repeatable. The runtime registrations themselves are not
committed.

A request is accepted when the access token:

- is a JWT signed by a key in the issuer's JWKS, with `iss` equal to the pinned issuer;
- has `exp` and `nbf` within 60 seconds of clock skew;
- has an `aud` that contains the receiver's own canonical URI. An empty or missing `aud`
  is refused. This check stays at the resource server whatever the authorization server
  does with `resource`, because the spike showed Hydra ignores it and Authentik's
  handling is unverified (ADR-0056 prerequisite 3).

`/api` and the sidecar have different audiences, so a token for one is refused by the
other. The sidecar accepts its own dedicated MCP resource audience and nothing else. It
does not accept the client id of every client registered through Victual: registration
identifies an application, and does not show that each token issued to it was meant for
this server (open question 9). Where an authorization server sets `aud` to the client id,
as Authentik does, a tested audience-binding configuration or adapter must give MCP tokens
the sidecar's audience before that server can be used.

Both answer a missing or invalid token with `401` and
`WWW-Authenticate: Bearer resource_metadata="…"`, and both serve
`/.well-known/oauth-protected-resource`. A token whose scope does not cover the operation
gets `403` with `error="insufficient_scope"` and the scopes it needs.

The JWKS is cached in process memory and refetched at most once a minute when a token
names an unknown `kid`. Losing that cache costs a refetch and nothing else, so it is a
pure cache under ADR-0007.

### 4. Every token resolves to a Victual user through one mapping

A new table maps `(issuer, subject)` to a `users` row. It is the only way a token becomes
a user, and ADR-0056 reuses it unchanged. Email is never part of the key.

- **Ordinary users, with either server:** the configured provider supplies its stable
  subject. Victual explicitly links `(issuer, subject)` to the application user record,
  following ADR-0056's linking rules. Victual does not generate the provider's subject
  or require its existing row id as that subject. Migration preserves the application
  record's permissions and audit references without matching accounts by email alone.
- **Client credentials:** the token's subject is the client. A client may use this grant
  only after a Victual user registers it and becomes its owner; the mapping row binds the
  client to that owner. The client then acts as its owner, narrowed by scope, in the same
  way as an API key. Its scope ceiling is fixed at registration, as ADR-0044 decision 5
  fixes a key's scope at creation. A different ceiling means registering a new client
  (open question 6).

An unmapped subject is refused with `401`. A mapped user who is deleted or disabled is
refused on the next request, because the lookup is a database read on every request.

### 5. Scope narrows; it never adds

Scopes are defined by the server, in two kinds (open question 1):

- **Four bundles.** `victual:read` (every view leaf), `victual:shopping` (shopping-list
  writes), `victual:stock` (the five bookings, undo and due dates) and `victual:catalog`
  (master-data creation and edits). They match ADR-0052's tool groups.
- **Leaf scopes.** One scope per permission leaf in `controllers/Users/User.php`, as
  ADR-0044 lets a key list single leaves.

The consent screen shows the four bundles by default. Leaf scopes sit behind an advanced
view, where ticking a bundle ticks its leaves and a user may untick some. A client that
requests leaf scopes is shown them in the advanced view already open, so nothing a client
asks for is hidden from the user approving it.

A request made with a token holds the permissions that are in both the owner's resolved
set (ADR-0018) and the set the token's scopes expand to. This is ADR-0044 decision 2's
intersection, applied at the same place, ADR-0044 decision 3's seam:
`User::GetPermissions()` and `ResolvedPermissionNames()` for the acting credential only. If
ADR-0044 is accepted first, tokens reuse its resolution code. If it is not, this record
carries the same rule on its own.

- No scope confers user administration, role management, key management or client
  management, and no leaf scope exists for `ADMIN` or the `USERS_*` leaves. Those stay
  session-only, as API keys cannot reach `/manageapikeys` today.
- A token with no write scope is treated as `read_only`:
  `BaseAuthMiddleware::ReadOnlyKeyRefusal` answers its non-GET requests with `403`, so the
  method-level backstop survives the change of credential.
- Roles are not scopes. A consent screen may pre-tick scopes from a role, but a grant is
  never assigned to a role, for the reason ADR-0044 decision 4 gives: a role's later edits
  must not move a live credential.
- `scopes_supported` lists only the read scope. Write scopes are requested by step-up.
  Claude Code 2.1.277 requested every listed scope in the spike, so listing only the read
  scope is what keeps a first connection read-only.

### 6. Grants, lifetimes and revocation

- Grants: authorization code with PKCE (`S256` only), refresh token, client credentials,
  and the device flow for command-line clients. No implicit grant and no password grant.
- Access tokens are JWTs with a five-minute lifetime. Refresh tokens rotate, and reuse of
  an old refresh token revokes its whole family (shown in the Hydra spike). A reused token
  that leaves its successor valid violates this requirement (question 12). An authorization
  server without that behaviour needs a supported mitigation before this record is
  accepted, or an explicit exception in an ADR (prerequisite 9). A documented, bounded
  revocation delay may be acceptable (question 16). Concurrent refreshes with one token
  must not leave a second live family: that needs a mitigation, and no exception is
  granted (question 17). Provider-supported atomic handling is preferred. A Victual-side
  sweep qualifies only if it reliably identifies every descendant and revokes them through
  supported operations; a periodic best-effort cleanup does not.
- Revocation at the authorization server takes effect within the access-token lifetime,
  because resource servers validate statelessly and do not introspect on each request.
  Five minutes is the stated worst case (question 24).
- Victual can also cut access at once, without introspection. It keeps a row per client
  grant (owner, client id, scope ceiling, last used, revoked). A request whose token names
  a revoked client, an unmapped subject or a disabled user is refused on that request.
- An exchanged token keeps the authenticated identity of its originating grant
  (question 13), through a server-signed claim or a trusted server-side mapping. Revoking
  one originating grant blocks the tokens exchanged from it and no other grant's. A
  caller-supplied identity is never authoritative (prerequisite 10).
- Where two grants of the same user share one provider session, revoking that session
  ends both (question 19). That is accepted as a documented broader revocation effect: it
  reduces availability and grants nothing. It is never described as isolated per-grant
  revocation, and an administrative action shows the affected grants before it runs.
- Revoking refresh access and rejecting an already-issued API token are separate effects
  (question 20). The second is Victual's immediate grant-revocation check above, or else
  the access-token lifetime, measured, is its delay.
- Write scopes do not introspect either (open question 3). The five-minute worst case
  above, together with Victual's own grant-row revocation, is the revocation design.

### 7. The sidecar exchanges the user's token; it does not forward it

The sidecar validates the inbound token against its own audience (decision 3). To call
Victual it needs a token whose audience is `/api`. It gets one by OAuth 2.0 Token Exchange
(RFC 8693) at the authorization server, as a confidential client with its own credential:

- the subject token is the user's MCP-audience token;
- the requested audience is Victual's `/api`;
- the issued token keeps the user as subject, identifies the sidecar as the exchanger,
  and carries no scope the subject token lacked.

The scope ceiling is enforced twice (open question 7). The authorization server must
refuse an exchange that widens scope, so that a compromised sidecar still cannot escalate.
The sidecar requests only the subset the call needs and checks the returned token before
using it. Both comparisons expand bundle scopes into leaves first. Victual then intersects
the result with the user's current permissions and the registered grant's ceiling
(decisions 5 and 6).

On Authentik, the scope-ceiling policy the compatibility report's case N11b demonstrates
is required, tested deployment configuration: without it, a read-only token was exchanged
for a write token.

The exchanger is identified by a claim the authorization server signs, derived from the
exchanging client's authentication, which no caller can set or override (open question
8). `act` is preferred. A documented alternative claim is permitted if the server derives
it the same way. `azp` is not used: on Authentik it names the target API, not the
exchanger. The token also keeps enough of the authenticated grant's identity for
decision 6's per-client revocation to work on exchanged tokens.

Victual records the first use of each exchanged token in the authentication audit table
that ADR-0056 decision 11 defines: the user, the exchanger identity and the client. The sidecar
may cache an
exchanged token in memory, keyed by a hash of the inbound token, until it expires; that is
a pure cache. The resolver seam ADR-0052 decision 8 names (`mcp/src/auth/resolver.ts`)
is where this change lands, and no tool changes.

Authentik 2026.8.5 performs the exchange (prerequisite 4), but sets `act` only for an
Authentik Actor that the open-source edition cannot create through its API. Whether it
can provide an alternative exchanger claim through supported configuration is a gate.
Hydra v26.2.0 does not implement RFC 8693 (prerequisite 4).

Rejected: forwarding the user's token with the sidecar's own credential beside it, and
Victual accepting the MCP audience only when that credential is present. It is less work,
but it is the pass-through the MCP specification forbids, and it makes every `/api`
request trust a header the sidecar adds.

### 8. Victual owns client registration policy

The spike showed the registration endpoint is where policy lives: public clients only for
loopback or HTTPS redirect URIs, known scopes only, and a response body MCP clients accept.
Victual serves that policy. The authorization server's own anonymous registration is off
or refused, its metadata advertises Victual's endpoint, and Victual creates the client
through the server's admin interface. That registration adapter is the one
server-specific piece of Victual.

The adapter also binds every registered MCP client to the dedicated MCP resource audience
(decision 3) through supported provider configuration (question 14): an audience mapper,
a client scope or a scope mapping, depending on the server. A client cannot select an
audience it is not bound to, tokens of unrelated clients are refused at the sidecar, and
the sidecar's exchanged tokens target only `/api` (prerequisite 11).

Clients obtain a client id by dynamic registration through that policy endpoint
(open question 4). Client ID Metadata Documents are not supported, although the
2026-07-28 MCP revision prefers them. They require the authorization server to fetch a URL
the client chooses, which is an outbound request to a caller-supplied URL, and AGENTS.md's
security posture keeps that surface out of this project. The choice is revisited if the
clients Victual supports stop offering dynamic registration.

### 9. Classic mode remains until the OAuth migration is complete

**Classic mode** is the existing local authentication mode: local users and passwords,
browser sessions, user-issued API keys and the sidecar's static-key authentication.
It remains supported while the OAuth implementation and migration work are incomplete.
An upgrade, ADR acceptance or configuring an authorization server does not remove these
paths or automatically move an installation to OAuth-only operation.

OAuth can be introduced alongside Classic authentication for migration. Before retiring
ordinary Classic authentication on an installation, the work must provide and verify
identity linking, preserved effective access, client migration and provider-independent
bootstrap and break-glass recovery. The operator explicitly selects the transition after
these conditions are met. This record sets no automatic removal date for Classic mode.
Local recovery accounts remain after that transition.

Existing keys are not converted to tokens. A bearer secret cannot become a consented grant
without its owner going through consent, and an automatic conversion would grant
something nobody approved.

An install with no authorization server configured, built-in or external, keeps
user-issued keys indefinitely, exactly as today. On an install with one configured
(an "OAuth install"), keys go through three phases (open question 2):

1. **Tokens beside keys.** Tokens are accepted alongside keys on `/api` and the sidecar.
   The manage-API-keys dialog, `read_only`, rotation and expiry are unchanged. The sidecar
   accepts a static MCP key or a token, so ADR-0052 stages 1 and 2 keep working.
2. **No new keys.** An install setting stops new user-issued keys being created, and
   rotation of an existing key is refused with it. Existing keys keep working.
3. **Existing keys retire.** User-issued keys stop authenticating on that install, and
   callers must use a token: authorization code for interactive clients, the device flow
   for command-line use, client credentials for unattended automation.

The operator moves an install from one phase to the next only after the migration
conditions above are met. Configuring a provider alone does not authorize key retirement. Phase 3 never applies to an
install with no authorization server. Calendar-sharing, label worker, verifier and renderer keys are
out of scope and unchanged in every phase.

### 10. The edge credential stays separate

ADR-0052's layering holds. A Cloudflare Access credential identifies a device to the edge
and ends there. The OAuth token identifies a user to Victual. The native clients
(ADR-0024) use authorization code with PKCE, keep the refresh token in the platform
keychain, and keep sending their per-device Access service token as before.

## Alternatives considered

- **An embedded PHP authorization server (`league/oauth2-server`).** One container fewer.
  The maintainer's comment lists it as the option the project is not tied to. It puts
  token issuance, key rotation and refresh-family tracking into the application this
  project audits by hand, and it is the weakest on the swap test because Victual's own
  code would be the authorization server.
- **Victual as a resource server only, external authorization server required.** Smallest
  Victual surface. Every install would need an identity provider before an MCP client
  could connect, which removes the standalone default issue 770 asks to keep.
- **Cloudflare Access managed OAuth for stage 3.** Access issues an opaque token that
  cannot be forwarded to the origin, and the identity then reaches Victual only as
  `Cf-Access-Jwt-Assertion`. The private-network route of ADR-0052 stage 1 bypasses Access,
  so Victual would trust a different identity source depending on the route. Rejected for
  the user credential. Access stays an edge control.
- **Opaque tokens with introspection on every request.** Revocation becomes immediate, but
  the authorization server sits on the hot path of every API and MCP call, and the sidecar
  makes a network round trip per request. Decision 6 keeps a Victual-side cut-off instead.
- **Hydra's own registration endpoint.** Unauthenticated, and its response failed the MCP
  SDK's schema in the spike.
- **Ory Hydra as the built-in server.** Delegates login and consent with no user store of
  its own, which suited the earlier assumption that Victual owned ordinary login. Removed as the provisional target on
  2026-10-25 because v26.2.0 has no RFC 8693 token exchange (prerequisite 4).
- **Dex, Zitadel.** Dex is a federation proxy and does not fit local users with device and
  client-credential grants. Zitadel carries its own user model; that is not a conflict with Victual's
  application authorization records. Both are from the issue 770 table and general knowledge; neither was tested.
  Keycloak, from the same table, is now the next candidate (decision 2).

## Consequences

- A household install that wants OAuth runs one more workload (the chosen server) and one more
  database. One that does not keeps static keys indefinitely and runs nothing new, since
  the chart component is off by default.
- On an OAuth install, automation that can only send a fixed header (a shell script with
  `curl`) stops working at phase 3 and must move to client credentials.
- Victual gains an OIDC login integration, a registration endpoint, two tables (the
  identity mapping and client grants), rows in ADR-0056's audit table, and a JWT
  validator. The sidecar gains the same
  validator, RFC 9728 metadata and a token-exchange client. No tool changes.
- Revocation at the authorization server has a stated latency of up to five minutes.
  Revocation in Victual is immediate.
- Every MCP request may cost one token-exchange round trip when the sidecar's cache is
  cold.
- Scope cannot widen a user, so a stolen token is bounded by its owner's permissions and
  its scopes, and by five minutes once the authorization server revokes it.
- Spec section 4 is amended when this is implemented: section 4.1's static-key-only
  posture and its `404` for protected-resource metadata no longer hold.

## Acceptance prerequisites

1. **The swap test.** The same Victual and sidecar images, pointed at the built-in
   authorization server and then at Authentik, pass the same checks with configuration
   changes only. The checks: a valid token for each audience is accepted; empty, wrong and
   foreign audiences are refused; an expired token is refused; a token for an unmapped
   subject is refused. Evidence names the date, both versions and how to reproduce it.

   **Authentik side, 2026-10-25: evidence, not met.** Authentik 2026.8.5, with the
   compatibility probe's resource-server check standing in for the Victual and sidecar
   images (spike report in `.devtools/spikes/oauth-authentik/`, cases P4, P6, N1 to N4 and N14). A valid token for
   each audience was accepted. Wrong and foreign audiences, a tampered token and an expired
   one were refused. Two things change what "configuration only" means on Authentik:

   - No discovery document is served at the install's issuer URL when one issuer is used
     (case P1), so the resource-server configuration needs a discovery URL beside the
     issuer. Decision 3 allows one since 2026-10-25, provided the metadata it returns
     names the pinned issuer.
   - `aud` is the `client_id` of the provider that issued the token, and `resource` is
     ignored at authorization, so a token an MCP client obtains is audienced to that
     client, not to the sidecar (report finding 5).

   Not run: the real images, the unmapped-subject refusal (Victual-side), and the Hydra
   half, which waits on the candidate table.
2. **The chosen built-in server on PostgreSQL at two replicas.** Login, consent, refresh
   rotation and revocation work with two pods sharing one database, and killing a pod
   mid-flow loses no grant. Written for Hydra; since 2026-10-25 it applies to whichever
   candidate decision 2 selects.

   **Keycloak, 2026-10-29: evidence, not met.** Keycloak 26.8.1, two production-mode
   containers on one PostgreSQL database, clustered by Infinispan over that database
   (JDBC_PING), behind a probe proxy standing in for a Service (Keycloak report finding 25,
   cases H0 to H9p):

   - Login, consent, refresh rotation, RFC 7009 revocation, administrative session deletion
     and token exchange work with consecutive requests on different replicas. Reuse of a
     rotated refresh token on one replica revokes the newest on the other.
   - Killing one replica mid-login, between issuing a code and redeeming it, or while it
     holds the grant lost nothing. Killing both lost no grant: sessions and consent persist
     in the database. Only logins in progress were lost.

   Not met because Keycloak is not yet selected (decision 2) and the run used containers,
   not pods behind the chart. Reproduce with `./run.sh ha-up && node ha.mjs` in
   `.devtools/spikes/oauth-keycloak/`.
3. **Its image and licence.** A Nix-built image (ADR-0013) that runs non-root on a
   read-only filesystem, and a licence review recorded in the plan. The same applies.

   **Keycloak, 2026-10-29: image met on arm64; licence review not recorded.** The spike's
   `nix/image.nix` builds Keycloak 26.8.1 from the nixpkgs revision `flake.lock` pins
   (Keycloak report finding 26). It runs as uid 65532 on a read-only root with two tmpfs
   mounts and has no shell. `kc.sh build` runs inside the derivation, and the entrypoint is
   the java command `kc.sh` would run. The core probe gave the same verdicts against it as
   against the upstream image.

   - Size: 827 MB, against the upstream 478 MB, because nixpkgs' headless JDK is not trimmed.
   - Not built for amd64.
   - Licence: Apache-2.0, but no plan records a review.
4. **Token exchange.** RFC 8693 exchange as decision 7 describes works against Hydra and
   against Authentik 2026.8 or later. If Hydra cannot do it, the candidate table is
   reopened before acceptance. Since 2026-10-25 it reads: against the built-in candidate
   and against Authentik, including a refused widening (the scope ceiling) and a signed
   exchanger identity, each by supported configuration.

   **Hydra half, 2026-10-25: not met.** Hydra v26.2.0, its newest release that day, has
   no RFC 8693 handler. It answers the exchange grant with the same `invalid_request` it
   gives a made-up grant type, and its binary carries no configuration key for it. Reproduce
   with `.devtools/spikes/oauth-hydra/exchange-probe.sh`; the spike README's "RFC 8693
   token exchange" section holds the table. Under this prerequisite the candidate table is
   therefore reopened.

   > **Response:** datagen24, 2026-10-25: the finding rules out Hydra v26.2.0 for
   > decision 7 as written. It does not invalidate the separate `/api` and MCP audiences,
   > scoped permissions or the swappable-server design. An RFC 7523 trust that lets the
   > sidecar assert arbitrary users is rejected, because it would give the sidecar
   > authority beyond the user tokens it receives. The next deliverable is an Authentik
   > compatibility report with reproducible positive and negative tests.

   **Authentik half, 2026-10-25: met for the core exchange, with two gaps.** Authentik
   2026.8.5 exchanged an MCP-audience user token for an `/api` token that kept the user's
   `sub` and the install's `iss` (cases P5, P6). It refused untrusted exchangers, untrusted,
   tampered and expired subject tokens, a disabled user, two audiences and `resource`
   (cases N5 to N10, N12, N13). The gaps:

   - The issued token is not limited to the subject token's scopes: a read-only token was
     exchanged for a write token (case N11). An expression policy bound to the `/api`
     application closes it (case N11b), which makes that policy part of the Authentik
     configuration.
   - A plain exchange records nothing in `act`. Delegation sets `act.sub`, but only for an
     Authentik Actor, and ownerless Actors are created through the Agents API, which needs
     an Enterprise licence (cases P7, P8).

   Reproduce with `./run.sh up && node setup.mjs && node probe.mjs` in `.devtools/spikes/oauth-authentik/`.

   **Keycloak half, 2026-10-25 and 2026-10-26: met for the core exchange, with gaps.**
   Keycloak 26.8.1 standard token exchange, which needs no Preview feature, exchanged an
   MCP-audience user token for an `/api` token with the same `sub` and `iss` (cases P5, P5b,
   P6). It refused an exchanger without the switch, a public client, an untrusted or
   tampered subject token and an unavailable audience (N6 to N10, N13, X6). The two required
   properties:

   - Scope ceiling: met by configuration. By default a read-only token was exchanged for a
     write token (N11); the `downscope-assertion-grant-enforcer` client policy refuses
     widening and passes equal and narrower scope (N11b).
   - Exchanger identity: `azp` of the exchanged token is the authenticated exchanging client.
     Keycloak's token-exchange guide documents it, and callers could not override it (P7b,
     X1 to X4). This is the documented alternative that question 8's answer permits. `act`
     appears only through the delegation Preview (P7, P8), which decision 2 rules out.

   The gaps: `resource` is ignored on exchange rather than refused (N5). Keycloak compares
   scope names and does not expand bundles into leaves, so a bundle cannot be exchanged for
   its own leaf (N11c). Grant identity on exchanged tokens is prerequisite 10's (K1).
   Reproduce with `./run.sh up && node setup.mjs && node probe.mjs` in
   `.devtools/spikes/oauth-keycloak/`.
5. **A real MCP client per registration path.** Claude Code completes connection,
   step-up to a write scope and a tool call against both authorization servers. Remote
   custom connectors (claude.ai) are tested once ADR-0052 stage 3's route exists.
   Not tested against Authentik as of 2026-10-25. Authentik refuses anonymous dynamic
   registration (report case N15), so Claude Code can register only through decision 8's
   endpoint.
6. **Open questions 1 and 4 answered.** Met 2026-10-24: the maintainer answered all six.

7. **Classic transition and local recovery.** Demonstrate that existing local logins,
   sessions, API keys and static-key MCP access still work during migration. Enabling
   OAuth must not automatically disable them. Demonstrate explicit identity linking with
   unchanged effective access, migrated clients, and local bootstrap and break-glass
   access with the provider unavailable. These checks remain unmet; selecting a provider
   does not satisfy them.
8. **Provider configuration is versioned and tested** (question 11). Every provider
   policy and mapping the swap test relies on uses a supported provider interface, lives
   with the deployment configuration under version control, and has positive and
   negative tests. The swap test (prerequisite 1) runs with exactly those installed and
   the Victual and sidecar images unchanged. Configuration that imports provider
   internals or mutates the provider database does not qualify without an upstream
   stability contract (question 15). For generated objects the evidence is the pinned
   adapter and templates, owner and recipe-version records on each object, and a
   repeatable reconciliation and upgrade (question 18).

   **Evidence, 2026-10-28: not met.** Keycloak's generated objects meet question 18 with
   configuration; Authentik waits on question 21. The swap test with this configuration
   installed has not run. Reproduce with each spike's `run.sh`, `setup.mjs` and `probe.mjs`.

   - Keycloak 26.8.1: the adapter stamps each object it creates with owner and recipe
     version in its `attributes` map. The stamps survive read-back and a restart.
     `reconcile()` is idempotent, repairs five kinds of drift, deletes an owned orphan,
     leaves unowned lookalikes alone and upgrades recipe 1 to recipe 2 (cases K4a to
     K4g). Keycloak refuses the `q=` attribute query, so listing by owner reads every
     client (case K4a).
   - Authentik 2026.8.5, exchanger scope mapping: rewritten to read only the HTTP request
     through documented helpers, with no `authentik.*` import (cases G1a to G1h, G8a).
   - Authentik scope-ceiling policy: it never imported anything; the earlier report was
     wrong to say it did (cases N11b, G3a, G3b).
   - Authentik refresh-reuse policy: no internal imports or row deletion now, but it holds
     a superuser API token (question 21).
9. **Refresh-family revocation** (question 12). On each authorization server, reuse of an
   old refresh token invalidates the whole family, including its newest token, by
   supported configuration or a supported mitigation. Concurrent-refresh tests are
   included. Otherwise an ADR records an explicit exception. Authentik 2026.8.5 does not
   meet this natively (the Authentik report's case G4d). The 2026-10-26 notification-rule
   mitigation (case G5d) imported internal models and deleted rows, so it did not qualify
   under question 15. The 2026-10-28 rewrite revokes through the admin API instead
   (question 21). Concurrent refresh is unmet (case G5f). Evidence for a bounded delay
   (question 16): repeated timing measurements; whether a refresh succeeds inside the
   window; whether descendants issued inside it are revoked; and what happens when the
   revocation worker fails or lags. A Victual-side sweep (question 17) must show that it
   finds every descendant and revokes through supported operations.

   **Authentik, 2026-10-28: evidence, not met.** Keycloak met this on 2026-10-25 with
   `revokeRefreshToken` (cases P10, N16). On Authentik 2026.8.5, ten reuse events per path
   were revoked in 746 / 788 / 935 ms (min / median / max) through an external webhook
   receiver, and in 676 / 906 / 1221 ms through the in-Authentik rule. The newest token was
   refused afterwards in all 20 (cases G5m, G5m2). The answer-16 tests:

   - A refresh 0 to 400 ms after the reuse succeeded (5 of 5); at 600 ms and later it was
     refused. Every descendant was refused afterwards (case G5n).
   - A holder refreshing in a tight loop made 117 to 138 refreshes in each of three runs
     and was never refused (case G5n2).
   - With the worker stopped for 20 s the newest token kept working and its holder could
     refresh. The family was revoked 6.6 s after the worker restarted (case G5o). Queued
     events ahead of the reuse delayed revocation to 50.5 s (300 events) and 166.6 s (1000
     events) (case G5p).

   For answer 17, five parallel refreshes with one token still left five live tokens and
   raised no event (case G5f). A Victual-side sweep over the admin API found every
   descendant of the fork in 6 of 6 runs and revoked it by single-row deletes. Against a
   holder refreshing during the sweep it cleared the family only after the holder stopped
   (cases G5r, G5r2). Both the receiver and the sweep are probe code. Questions 22 and 23
   ask for rulings.
10. **Originating-grant identity survives exchange** (question 13). A supported,
    server-signed claim or a trusted server-side mapping identifies the originating
    grant on exchanged tokens. Tests show that revoking one originating grant blocks the
    tokens exchanged from it without blocking another grant's, and that a caller-supplied
    identity is not accepted as authoritative. Keycloak 26.8.1 drops the originating
    client by default (cases R1 and R2) and carries it in a per-client signed claim under
    the downscope policy (cases K1c and K1f). Two separate tests are required (question
    20): revoking refresh access, and rejecting an already-issued `/api` token through
    Victual's immediate grant-revocation check, or a measured token-expiry delay.
    Keycloak's consent deletion has not shown that an exchanged token is revoked: the
    `/api` introspection still reported it active (case R5), so it is not revocation
    evidence. Where grants share a provider session, the evidence also shows the broader
    effect is documented and the affected grants are listed before an administrative
    revocation (question 19).

    **Keycloak, 2026-10-28: refresh access met; the already-issued token not met at
    Keycloak.** Each case now asserts one outcome: refresh (`-r`), new exchanges (`-x`) or
    the issued `/api` token at introspection (`-t`).

    - Refresh access: every per-grant mechanism refused grant A's refresh and new
      exchanges and left grant B's. The mechanisms were consent deletion, refresh-token
      revocation, session deletion in separate sessions, client not-before and
      offline-session deletion (cases K1r1 to K1r6, `-r` and `-x`).
    - The issued token: it stayed active at introspection after consent deletion, client
      not-before and refresh-token revocation in a shared session (cases K1r1s-t,
      K1r1h-t, K1r4s-t, K1r4h-t, K1r2h-t). It was refused at once after session deletion
      in the grant's own session and after the sidecar revoked it by RFC 7009.
    - The sidecar's not-before ends every grant's exchanged tokens, so it is not a
      per-grant mechanism (case K1r9s-t).
    - Victual's immediate grant-revocation check is not built, so nothing tested it
      (question 25).
    - Expiry delay: a stateless check under decision 3 accepted the token until `exp` +
      60.2 to 60.7 s after every mechanism. At the realm's 300 s lifespan that is six
      minutes after issue (cases E0 to E2; question 24).
    - Shared session: before a session was deleted, the admin API's user-sessions
      endpoint listed exactly the grants that then stopped. Another session's grant and
      another user's grant kept working (cases K5a, K5b). That meets question 19 with
      configuration.

    *Retraction:* the 2026-10-26 report passed five of these `-t` cases (K1r1s, K1r4s,
    K1r1h, K1r2h, K1r4h). They passed only because a probe-side lookup simulated a Victual
    check that does not exist. They now fail.

    **Authentik, 2026-10-28: identity carried; revocation of the issued token not
    tested.** The exchanger scope mapping copies `azp`, `sid` and `jti` from the verified
    subject token into the exchanged token. `azp` names the MCP client (case G1g). After
    the access-token row is deleted through the admin API, further exchanges are refused;
    exchanged tokens already issued stay valid until they expire (case G1h). The
    app-password grant carries no `sid`, and ending a login session does not revoke the
    refresh token.
11. **MCP audience binding through the registration adapter** (question 14). Tests show
    that a registered client cannot select an unauthorized audience, that tokens of
    unrelated clients are refused at the sidecar, and that exchanged tokens target only
    `/api`.

    **Keycloak, 2026-10-26: met by configuration for the simulated adapter; not met.** The
    spike's `adapter.mjs` stands in for Victual's adapter, which does not exist. Clients it
    created hold the MCP audience scope by default; their tokens were accepted at the
    sidecar (case K2a). Asking for `/api` through `audience`, `resource` or another audience
    scope did not change `aud` or was refused (K2b). A client without the scope was refused
    at the sidecar and could not bind itself (K2c). Exchanged tokens carried only `/api`,
    and could not be exchanged back into the MCP audience (K2d, K2e).

    Anonymous registration could not bind itself to the MCP audience while the Allowed
    Client Scopes policy's list was empty (K2g). With the audience scope on that list, a
    registrant bound itself (K2h). The list must therefore never contain it, which makes the
    empty list part of the versioned configuration of prerequisite 8. Not tested: a client
    registered anonymously and then bound by the adapter, and Authentik.

## Open questions and maintainer responses

All six were answered on 2026-10-24 and are folded into the decisions named in each
response. The answers do not accept this record.

1. **What are the initial scopes?** The draft suggested four bundles matching ADR-0052's
   tool groups and asked whether a scope may name a single permission leaf.

   > **Response:** datagen24, 2026-10-24: the four bundles `victual:read`,
   > `victual:shopping`, `victual:stock` and `victual:catalog`, plus scopes that name a
   > single permission leaf, as ADR-0044 allows for keys. The consent screen must stay
   > usable, for example bundles shown with leaf scopes behind an advanced view. No admin
   > scopes. (Decision 5.)

2. **Do static user-issued keys retire?** Retiring them breaks automation that can only
   send a fixed header.

   > **Response:** datagen24, 2026-10-24: user-issued static keys retire after phase 2,
   > but only on installs where an authorization server is configured. An install with
   > none keeps keys indefinitely. Calendar, label worker, verifier and renderer keys stay
   > out of scope. (Decision 9.)

3. **Should write scopes introspect?**

   > **Response:** datagen24, 2026-10-24: no introspection for writes. The five-minute
   > worst case plus Victual's own grant-row revocation stands. (Decision 6.)

4. **Client ID Metadata Documents or dynamic registration?**

   > **Response:** datagen24, 2026-10-24: dynamic registration through Victual's policy
   > endpoint. No Client ID Metadata Documents, because they add an outbound-fetch
   > surface. Revisit if clients drop dynamic registration. (Decision 8.)

5. **Is the built-in authorization server on by default in the chart?**

   > **Response:** datagen24, 2026-10-24: off by default. (Decision 2.)

6. **Does a client-credentials client hold a scope ceiling set at registration?**

   > **Response:** datagen24, 2026-10-24: yes. The ceiling is fixed at registration, and a
   > new ceiling means a new client. (Decision 4.)

### Raised by the Authentik compatibility report, 2026-10-25

7. **Who enforces the exchange scope ceiling?** The report's case N11b policy at the
   authorization server, the sidecar requesting no more than the inbound token's scopes,
   or both.

   > **Response:** datagen24, 2026-10-25: both. The authorization server must refuse
   > widening even if the sidecar is compromised. The sidecar requests only the needed
   > subset and validates the returned token before using it. Compare permissions after
   > expanding bundles into leaves. Victual still intersects the result with current user
   > permissions and the registered grant ceiling. The Authentik policy becomes required,
   > tested deployment configuration: the spike showed that omitting it permits
   > read-to-write escalation. (Decision 7.)
8. **How is the sidecar identified without a supported `act`?** Decision 7 and ADR-0056
   decision 11 expect `act`. On open-source Authentik it needs an Actor that only the
   Enterprise Agents API creates. The options include an Enterprise licence, another
   attribution of exchanges, or dropping the requirement.

   > **Response:** datagen24, 2026-10-25: require an authorization-server-signed identity
   > of the authenticated exchanger. Prefer `act`; permit a documented alternative claim
   > that the server derives from client authentication and that callers cannot override.
   > Do not infer the sidecar from Authentik's `azp`: the spike found that it names the
   > target API. Whether Authentik can provide the alternative through supported
   > configuration remains a gate. Preserve enough authenticated grant identity to support
   > the promised per-client revocation. (Decision 7.)
9. **What audience does the sidecar accept** when each registered MCP client's tokens
   carry that client's own `client_id`?

   > **Response:** datagen24, 2026-10-25: accept a dedicated MCP resource audience. Do not
   > accept every registered client id merely because registration occurred through
   > Victual. Registration identifies an application; it does not prove that every token
   > issued to it was intended for this MCP server. Require a tested audience-binding
   > configuration or adapter. MCP requires tokens intended for the receiving resource.
   > (Decision 3.)

**Candidate table.** Answered by datagen24 on 2026-10-25: remove Hydra as the provisional
implementation target. Evaluate Keycloak next while retaining Authentik as the required
external compatibility target. Keycloak documents supported standard token exchange, but
its delegation feature is Preview, so it is a candidate rather than a selected
replacement. The original response also treated Victual-owned login as a gate; the
clarification below replaces that interpretation. (Decision 2.)

### Identity ownership and Classic mode clarification, 2026-10-25

10. **Does keeping local users require Victual to own ordinary identities?**

    > **Response:** datagen24, 2026-10-25: local users in the OAuth design are for
    > bootstrap and break-glass access. The identity provider owns ordinary identities;
    > Victual keeps application authorization records. Remove the Victual-owned-login
    > interpretation from the candidate gate. Classic mode remains until this work is
    > complete. Decisions 1, 2, 4 and 9 record this clarification; prerequisite 7 requires
    > evidence before the transition retires ordinary Classic authentication.

### Server comparison, 2026-10-26

The spike reports on Authentik and Keycloak raised four questions. Each answer's evidence
requirement is an acceptance prerequisite.

11. **Do provider expression policies and scope mappings count as "configuration only" for
    the swap test?**

    > **Response:** datagen24, 2026-10-26: yes, when they use supported provider
    > interfaces, are versioned with deployment configuration, and pass positive and
    > negative tests. Victual and sidecar images must remain unchanged. Evidence: the swap
    > test with the exact policies and mappings installed. (Decision 3; prerequisite 8.)

12. **Authentik's refresh-token reuse leaves the newest token valid. Accept it or require
    a mitigation?**

    > **Response:** datagen24, 2026-10-26: require mitigation before acceptance. A reused
    > token leaving its successor valid violates the current family-revocation
    > requirement. Evidence: a supported mitigation that invalidates the family,
    > including concurrent-refresh tests. Otherwise, an explicit ADR exception is needed.
    > (Decision 6; prerequisite 9.)

13. **Keycloak drops the originating client on exchange. Carry it another way, or relax
    per-client revocation for exchanged tokens?**

    > **Response:** datagen24, 2026-10-26: preserve authenticated originating-grant
    > identity. Do not weaken per-client revocation or accept a caller-supplied identity
    > as authoritative. Evidence: a supported, server-signed claim or trusted server-side
    > mapping; tests showing revoking one originating grant blocks its exchanged tokens
    > without blocking another. (Decision 6; prerequisite 10.)

14. **Both servers need Victual's registration adapter to attach the MCP audience per
    client. Accept that?**

    > **Response:** datagen24, 2026-10-26: accept it as part of Victual's registration
    > adapter. It must bind registered MCP clients to the dedicated MCP resource audience
    > through supported provider configuration. Evidence: tests proving clients cannot
    > select an unauthorized audience, unrelated clients' tokens are rejected, and
    > exchanged tokens target only the API. (Decision 8; prerequisite 11.)

### Follow-up spike evidence, 2026-10-26

The Authentik and Keycloak follow-up spikes tested prerequisites 8 to 11 and raised these
questions.

15. **Do Authentik expressions that import internal models, or delete rows, count as
    supported configuration?**

    > **Response:** datagen24, 2026-10-26: do not count these as supported configuration
    > without an explicit upstream stability contract. A supported expression editor does
    > not make internal models or direct database mutations supported interfaces. Row
    > deletion must also preserve provider invariants and audit behavior. (Decision 3;
    > prerequisite 8.)

16. **Is a revocation delay of about 0.5 s after a refresh reuse acceptable?**

    > **Response:** datagen24, 2026-10-26: potentially acceptable as a documented bounded
    > delay. One observed timing is insufficient. Test whether refresh succeeds during
    > that window, whether descendants are also revoked, and what happens when the
    > revocation worker fails. (Decision 6; prerequisite 9.)

17. **Concurrent refreshes with one Authentik token leave several live tokens. Exception,
    or Victual-side sweep?**

    > **Response:** datagen24, 2026-10-26: require mitigation; do not grant an exception
    > yet. Prefer provider-supported atomic handling. A Victual-side sweep is acceptable
    > only if it reliably identifies every descendant and uses supported revocation
    > operations. A periodic best-effort cleanup would weaken the requirement.
    > (Decision 6; prerequisite 9.)

18. **Keycloak's adapter creates per-client scopes at run time. Does versioning the recipe
    count as "versioned with the deployment configuration"?**

    > **Response:** datagen24, 2026-10-26: yes, the recipe can be versioned. Pin the
    > adapter and templates, persist ownership and recipe-version information for
    > generated objects, and provide repeatable reconciliation and upgrades. Runtime
    > registrations do not need to be committed to Git. (Decision 3; prerequisite 8.)

19. **Two grants in one provider session revoke together on both servers. Acceptable?**

    > **Response:** datagen24, 2026-10-26: acceptable as a documented broader revocation
    > effect, provided both grants belong to that user and session. It reduces
    > availability rather than granting access. Do not describe it as isolated per-grant
    > revocation, and show the affected grants before an administrative action.
    > (Decision 6; prerequisite 10.)

20. **Does Keycloak's consent deletion count as revoking an exchanged token?**

    > **Response:** datagen24, 2026-10-26: for Keycloak, the corrected `/api` check is
    > decisive: consent deletion has not demonstrated revocation of an already-exchanged
    > token. Revoking refresh access and rejecting an issued API token are separate tests.
    > The latter must verify Victual's immediate grant-revocation check, or explicitly
    > measure the token-expiry delay. (Decision 6; prerequisite 10.)

### Spike evidence on answers 15 to 20, 2026-10-28

The Keycloak and Authentik spikes (PRs 1131 and 1132) tested answers 15 to 20. The
evidence is under prerequisites 8 to 10. These questions need a ruling.

21. **Does an Authentik policy that revokes through the admin API count as supported
    configuration?** The rewritten refresh-reuse policy imports no internals and changes
    no rows directly. It calls the admin API with the documented `requests` object, the
    same `DELETE` the admin interface uses. It does depend on three things answer 15 did
    not cover:

    - a superuser API token stored in the policy expression, which every administrator
      who can read policies can read; a non-superuser sees only their own tokens;
    - event context fields that Authentik does not document;
    - one of the worker's two threads, blocked for about a second per reuse, with
      concurrent reuses queued behind it.

    The external webhook receiver avoids the third point but needs the same token. The
    deletes leave no Authentik audit event, so the revoker must record them. A policy that
    reads only documented context cannot revoke anything. (Authentik report findings 19 and 20;
    cases G5d, G5g, G5h, G5m2. Decision 3; prerequisites 8 and 9.)

22. **Is a revocation delay bounded by worker health a "documented bounded delay"?** On
    Authentik the delay is under about 1.2 s while the worker is healthy. A refresh in the
    first 400 ms still succeeds, but its descendant is revoked with the family (case
    G5n). The bound is not a constant:

    - A stopped worker revokes only after it restarts (case G5o).
    - A queue of 1000 events stretches the delay to 166.6 s (case G5p).
    - Authentik's worker list still shows a stopped worker, so it is no liveness check.
    - A holder who refreshes continuously is never refused (case G5n2).

    One option is to state the bound as "worker healthy" and require a worker health
    alarm. Another is to treat the looping holder as part of question 23. (Authentik report
    findings 21 and 22. Decision 6; prerequisite 9.)

23. **Concurrent and continuous refresh on Authentik: which way forward?** Answer 17
    asked for a mitigation and granted no exception yet. Authentik has no atomic family
    revocation and no bulk delete. The sweep finds every descendant (6 of 6 runs) through
    API fields, but the schema does not document the `id_token` content it groups by. A
    holder who keeps refreshing outruns it, and it needs a superuser token. Run on a
    schedule, it is the periodic cleanup answer 17 rejected; run on each exchange, it
    covers only tokens that reach Victual. The options the evidence leaves:

    - an ADR exception covering forked and looping holders on Authentik;
    - an upstream request for atomic rotation, with prerequisite 9 open until it ships;
    - narrowing Authentik's compatibility target so that it excludes family revocation.

    (Authentik report finding 22; cases G5f, G5r, G5r2. Decision 6; prerequisite 9.)

24. **What worst case does decision 6 state?** Decision 6 says five minutes. Decision 3
    lets a resource server accept 60 s of clock skew on `exp`. The Keycloak run measured a
    stateless check accepting a revoked grant's token until `exp` + 60.2 to 60.7 s, six
    minutes after issue (Keycloak report finding 23; cases E0 to E2). The options are to
    state the worst case as the access-token lifetime plus the permitted skew, or to
    shorten the lifetime or the skew so that five minutes holds. (Decisions 3 and 6.)

25. **Must Victual's immediate grant-revocation check be built and tested before
    acceptance?** Answer 20 allows either that check or a measured expiry delay as the
    second test. The delay is now measured (question 24). The check does not exist. On
    Keycloak, only session-ending mechanisms and the sidecar's RFC 7009 revocation stop an
    issued `/api` token before it expires. A user who withdraws consent in Keycloak's
    account console changes nothing in Victual, because Keycloak 26.8.1 has no event
    listener that calls an HTTP endpoint. Is the measured delay enough for acceptance, with
    the check built as part of the implementation? Or is the check itself acceptance
    evidence? (Keycloak report findings 18, 19 and 23. Decision 6; prerequisite 10.)
