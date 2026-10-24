# ADR-0055: API and MCP callers present OAuth 2.1 access tokens from a swappable authorization server

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The acceptance prerequisites below are unmet as of 2026-10-24.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-24, against `master` at `d40f5afb`. Design only: it changes no code,
  reserves no migration number and amends no accepted record.
- **Referenced by:** [issue 770](https://github.com/datagen24/victual/issues/770);
  [ADR-0056](0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md),
  which depends on this record and must not be accepted before it.
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

One authorization server per install issues every access token. It is either the built-in
authorization server or an external one; ADR-0056 states the same sentence from the
other side. Victual has three roles and no fourth:

1. A resource server for `/api`.
2. The login and consent provider for the built-in authorization server, because Victual
   owns the local users and their passwords. With an external authorization server, that
   server runs its own login and consent.
3. An OIDC relying party for browser login when an external authorization server is
   configured (ADR-0056). With the built-in one, browser login stays the existing local
   session and does not pass through it.

The MCP sidecar is a second resource server.

### 2. The built-in authorization server is Ory Hydra, provisionally

Hydra is the candidate to build against, pending prerequisites 2 to 5. It is the only
candidate in the issue 770 table that delegates login and consent while holding no user
store of its own. That property keeps Victual's passwords inside Victual.

Hydra runs as its own workload under ADR-0010: its own container, its own PostgreSQL
database and role (ADR-0008 permits no other engine), an optional component of the Helm
chart (ADR-0038), two replicas. If a prerequisite fails, the candidate table is reopened.
The rest of this record still holds, because it depends on Hydra only for token exchange
(decision 7) and through decision 8's registration adapter.

### 3. The resource-server contract uses standard surface only

Victual and the sidecar read one configuration: the issuer URL, and the audience each one
accepts. From the issuer they fetch RFC 8414 or OIDC discovery metadata and its JWKS.
Nothing else about the authorization server is configured or assumed. A request is
accepted when the access token:

- is a JWT signed by a key in the issuer's JWKS, with `iss` equal to the pinned issuer;
- has `exp` and `nbf` within 60 seconds of clock skew;
- has an `aud` that contains the receiver's own canonical URI. An empty or missing `aud`
  is refused. This check stays at the resource server whatever the authorization server
  does with `resource`, because the spike showed Hydra ignores it and Authentik's
  handling is unverified (ADR-0056 prerequisite 3).

`/api` and the sidecar have different audiences, so a token for one is refused by the
other. Both answer a missing or invalid token with `401` and
`WWW-Authenticate: Bearer resource_metadata="…"`, and both serve
`/.well-known/oauth-protected-resource`. A token whose scope does not cover the operation
gets `403` with `error="insufficient_scope"` and the scopes it needs.

The JWKS is cached in process memory and refetched at most once a minute when a token
names an unknown `kid`. Losing that cache costs a refetch and nothing else, so it is a
pure cache under ADR-0007.

### 4. Every token resolves to a Victual user through one mapping

A new table maps `(issuer, subject)` to a `users` row. It is the only way a token becomes
a user, and ADR-0056 reuses it unchanged. Email is never part of the key.

- **Built-in authorization server:** Victual's login-accept step sets the subject. The
  subject is an opaque random value generated once per user and stored in the mapping, not
  the user id, so a token reveals nothing about row ids and a restored database keeps the
  same subjects.
- **Client credentials:** the token's subject is the client. A client may use this grant
  only after a Victual user registers it and becomes its owner; the mapping row binds the
  client to that owner. The client then acts as its owner, narrowed by scope, in the same
  way as an API key.

An unmapped subject is refused with `401`. A mapped user who is deleted or disabled is
refused on the next request, because the lookup is a database read on every request.

### 5. Scope narrows; it never adds

Scopes are server-defined bundles of permission leaves from `controllers/Users/User.php`.
A request made with a token holds the permissions that are in both the owner's resolved
set (ADR-0018) and the set the token's scopes expand to. This is ADR-0044 decision 2's
intersection, applied at the same place, ADR-0044 decision 3's seam:
`User::GetPermissions()` and `ResolvedPermissionNames()` for the acting credential only. If
ADR-0044 is accepted first, tokens reuse its resolution code. If it is not, this record
carries the same rule on its own.

- No scope confers user administration, role management, key management or client
  management. Those stay session-only, as API keys cannot reach `/manageapikeys` today.
- A token with no write scope is treated as `read_only`:
  `BaseAuthMiddleware::ReadOnlyKeyRefusal` answers its non-GET requests with `403`, so the
  method-level backstop survives the change of credential.
- Roles are not scopes. A consent screen may pre-tick scopes from a role, but a grant is
  never assigned to a role, for the reason ADR-0044 decision 4 gives: a role's later edits
  must not move a live credential.
- `scopes_supported` lists only the read scope. Write scopes are requested by step-up.
  Claude Code 2.1.277 requested every listed scope in the spike, so listing only the read
  scope is what keeps a first connection read-only.

The initial scope names are open question 1.

### 6. Grants, lifetimes and revocation

- Grants: authorization code with PKCE (`S256` only), refresh token, client credentials,
  and the device flow for command-line clients. No implicit grant and no password grant.
- Access tokens are JWTs with a five-minute lifetime. Refresh tokens rotate, and reuse of
  an old refresh token revokes its whole family (shown in the spike).
- Revocation at the authorization server takes effect within the access-token lifetime,
  because resource servers validate statelessly and do not introspect on each request.
  Five minutes is the stated worst case.
- Victual can also cut access at once, without introspection. It keeps a row per client
  grant (owner, client id, scope ceiling, last used, revoked). A request whose token names
  a revoked client, an unmapped subject or a disabled user is refused on that request.
  Whether write scopes should introspect as well is open question 3.

### 7. The sidecar exchanges the user's token; it does not forward it

The sidecar validates the inbound token against its own audience (decision 3). To call
Victual it needs a token whose audience is `/api`. It gets one by OAuth 2.0 Token Exchange
(RFC 8693) at the authorization server, as a confidential client with its own credential:

- the subject token is the user's MCP-audience token;
- the requested audience is Victual's `/api`;
- the issued token keeps the user as subject, records the sidecar in `act`, and carries
  no scope the subject token lacked.

Victual writes `act` to the application log beside the user; it has no audit table
(ADR-0056 open question 6). The sidecar may cache an
exchanged token in memory, keyed by a hash of the inbound token, until it expires; that is
a pure cache. The resolver seam ADR-0052 decision 8 names (`mcp/src/auth/resolver.ts`)
is where this change lands, and no tool changes.

Authentik documents RFC 8693 delegation from 2026.8.0 (read 2026-10-24). Hydra's support
is unverified: an Ory forum post says Hydra 2.0's release notes claim it, and another
says fosite, the library Hydra uses, does not implement it. Prerequisite 4 settles it.

Rejected: forwarding the user's token with the sidecar's own credential beside it, and
Victual accepting the MCP audience only when that credential is present. It is less work,
but it is the pass-through the MCP specification forbids, and it makes every `/api`
request trust a header the sidecar adds.

### 8. Victual owns client registration policy

The spike showed the registration endpoint is where policy lives: public clients only for
loopback or HTTPS redirect URIs, known scopes only, and a response body MCP clients accept.
Victual serves that policy. For the built-in authorization server, Hydra's own
registration endpoint is off, its metadata advertises Victual's endpoint, and Victual
creates the client through Hydra's admin API. That adapter is the one Hydra-specific piece
of Victual.

Client ID Metadata Documents, which the 2026-07-28 MCP revision prefers, require the
authorization server to fetch a URL the client chooses. That is an outbound request to a
caller-supplied URL, which AGENTS.md's security posture keeps out of this project. Whether
to accept that surface in the authorization server's container is open question 4.

### 9. Static API keys keep working

Existing keys are not converted to tokens. A bearer secret cannot become a consented grant
without its owner going through consent, and an automatic conversion would grant
something nobody approved.

- **Phase 1:** tokens are accepted alongside keys on `/api` and the sidecar. The
  manage-API-keys dialog, `read_only`, rotation and expiry are unchanged. The sidecar
  accepts a static MCP key or a token, so ADR-0052 stages 1 and 2 keep working.
- **Phase 2, if the maintainer chooses it:** an install setting stops new user-issued keys
  being created once tokens work for that install. Existing keys run to their expiry.
- Retiring keys altogether is open question 2.
- Calendar-sharing, label worker, verifier and renderer keys are out of scope and
  unchanged.

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
- **Dex, Zitadel, Keycloak.** Dex is a federation proxy and does not fit local users with
  device and client-credential grants. Zitadel and Keycloak carry their own user model,
  which competes with Victual's. All three are from the issue 770 table and general
  knowledge; none was tested.

## Consequences

- A household install that wants OAuth runs one more workload (Hydra) and one more
  database. One that does not keeps static keys and runs nothing new.
- Victual gains a login-and-consent surface, a registration endpoint, two tables (the
  identity mapping and client grants) and a JWT validator. The sidecar gains the same
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
2. **Hydra on PostgreSQL at two replicas.** Login, consent, refresh rotation and
   revocation work with two Hydra pods sharing one database, and killing a pod mid-flow
   loses no grant.
3. **Hydra image and licence.** A Nix-built Hydra image (ADR-0013) that runs non-root on a
   read-only filesystem, and a licence review recorded in the plan.
4. **Token exchange.** RFC 8693 exchange as decision 7 describes works against Hydra and
   against Authentik 2026.8 or later. If Hydra cannot do it, the candidate table is
   reopened before acceptance.
5. **A real MCP client per registration path.** Claude Code completes connection,
   step-up to a write scope and a tool call against both authorization servers. Remote
   custom connectors (claude.ai) are tested once ADR-0052 stage 3's route exists.
6. **Open questions 1 and 4 answered.**

## Open questions

1. **What are the initial scopes?** A suggestion to argue with: `victual:read` (every view
   leaf), `victual:shopping` (shopping-list writes), `victual:stock` (the five bookings,
   undo and due dates), `victual:catalog` (master-data creation and edits). These match
   ADR-0052's tool groups. Whether a scope may name a single permission leaf, as ADR-0044
   allows for keys, affects how large the consent screen gets.
2. **Do static user-issued keys retire?** Phase 2 stops new keys on request. Removing them
   would break any automation that cannot do OAuth, such as a shell script with `curl`
   and a fixed header. The device flow covers interactive command-line use; client
   credentials cover unattended use.
3. **Should write scopes introspect?** That would make revocation immediate for writes at
   the cost of an authorization-server round trip per write.
4. **Client ID Metadata Documents or dynamic registration?** Accepting metadata documents
   means the authorization server fetches caller-chosen URLs. Staying on dynamic
   registration depends on clients continuing to support a method the MCP specification
   marks deprecated.
5. **Is the built-in authorization server on by default in the chart?** Off keeps an
   upgrade from adding a workload. On makes the remote connectors work without further
   steps.
6. **Does a client-credentials client hold a scope ceiling set at registration, as
   ADR-0044 decision 5 fixes a key's scope at creation?** The suggestion is yes, with a
   change of ceiling meaning a new client.
