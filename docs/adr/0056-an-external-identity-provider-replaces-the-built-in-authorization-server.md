# ADR-0056: An external identity provider replaces the built-in authorization server, with Authentik as the reference

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request, and it must not be accepted before
  [ADR-0055](0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md).
  The acceptance prerequisites below are unmet as of 2026-10-24.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-24, against `master` at `d40f5afb`. Design only: it changes no code,
  reserves no migration number and amends no accepted record.
- **Referenced by:** [issue 771](https://github.com/datagen24/victual/issues/771).
- **Relationship:** depends on the Proposed ADR-0055 and reuses its identity mapping
  (decision 4) and resource-server contract (decision 3) unchanged. Builds on accepted
  [ADR-0007](0007-auth-state-outlives-the-process.md),
  [ADR-0014](0014-administering-a-user-is-a-subset-question.md),
  [ADR-0018](0018-role-grants-and-domain-reads.md) and
  [ADR-0038](0038-kubernetes-deployments-ship-as-a-helm-chart.md); amends none. Sits beside
  the Proposed [ADR-0039](0039-the-mcp-sidecar-reads-its-configuration-from-victual.md) and
  [ADR-0044](0044-api-keys-carry-a-subset-of-their-owners-permissions.md).

## Context

Issue 771 asks that household members sign in to Victual, and authorize API and MCP
clients, with an identity held by an external identity provider. The maintainer's home lab
runs Authentik; the design must also work with another standards-compliant provider such
as Keycloak, Authelia, Entra ID or Google.

### What issue 770's decision makes of this issue

ADR-0055 decision 1 records the maintainer's choice on issue 770: one authorization
server per install issues every token, either the built-in one or an external one, and
Victual never issues tokens. The maintainer's comment on issue 771 draws the consequence:
Authentik is the first replacement for the built-in authorization server, not an addition
to it. The work here is therefore browser login against that provider, identity linking,
group-to-role mapping, and the Authentik-specific configuration. Token validation is
ADR-0055's.

### Today's login paths

Read from the tree on 2026-10-24:

- `DefaultAuthMiddleware` recognises a session cookie (`SessionAuthenticator`) and, on API
  routes only, an API key (`ApiKeyAuthenticator`). Password login goes through
  `PasswordLogin`, throttled in the database under ADR-0007.
- `ReverseProxyAuthMiddleware` trusts a username in a header (default `REMOTE_USER`) from
  addresses in `REVERSE_PROXY_AUTH_TRUSTED_PROXIES`. `ReverseProxyAuthenticator` creates a
  local user the first time a username appears, with `DEFAULT_PERMISSIONS` and a password
  of 32 random bytes that nobody knows. The LDAP backend was removed in favour of this
  path (`config-dist.php`).
- `users.must_change_password` (migration 0265) is set when an account logs in with the
  seeded default password, or when the first migration generates the administrator's
  password and prints it to the log
  (`DatabaseMigrationService::FlagGeneratedAdminPasswordForChange`).
  `BaseAuthMiddleware::PasswordChangeRequired` then refuses the account's API keys and
  sends its browser to the password form. The check is skipped when
  `VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION` is defined, since there is no local password
  to change.

### Production edge

Since 2026-10-23 production reaches Victual through Cloudflare Access, which signs browsers
in with Entra ID (ADR-0052). That identity ends at the edge. Which identity provider issues
Victual's own tokens in production is open question 1; Authentik is the home-lab reference
and the test target.

### Authentik capabilities, read 2026-10-24

From Authentik's [OAuth2 provider documentation](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/)
and the pages it links. These are documentation claims; none was run in this repository.

| Capability | Documentation says | Status for this record |
|---|---|---|
| Authorization code with PKCE, refresh | Supported | Assumed; prerequisite 1 tests it |
| Client credentials | Supported; the token's subject is a service account, either a named account authenticating with an app password or one Authentik generates per provider | Assumed; decision 6 maps it |
| Device code flow | Has its own page | Assumed; prerequisite 1 tests it |
| Dynamic client registration | From 2026.8.0, gated by a bearer token carrying a DCR scope and by policy bindings; public clients allowed | Claude Code registered anonymously in ADR-0055's spike, so this may not work for it; prerequisite 2 |
| Client ID Metadata Documents | Not mentioned | Unknown |
| RFC 8707 `resource` | Not mentioned | Unknown; prerequisite 3 |
| RFC 8693 token exchange | Impersonation, and delegation from 2026.8.0; target named by `audience` | Needed by ADR-0055 decision 7 |
| Group membership in claims | The `profile` scope includes it | Assumed; decision 4 depends on it |
| Back-channel and front-channel logout | Each has a page | Needed by decision 7 |
| Introspection and revocation endpoints | Published | Not on the hot path (ADR-0055 decision 6) |

## Decision (proposed)

### 1. One issuer, and it is the external provider when one is configured

One authorization server per install issues every token, either the built-in one or an
external one; Victual never issues tokens. This is ADR-0055 decision 1. With an external
provider configured, the built-in authorization server is not deployed, the provider's
issuer URL is the one ADR-0055 decision 3 pins, and Victual is its OIDC relying party
for browser login. Exactly one issuer per install; multiple issuers are open question 2.

### 2. Victual is the relying party; the forward-auth proxy is not the path

Browser login runs authorization code with PKCE from Victual, as a confidential client of
the provider. On return, Victual validates the ID token, resolves the user through the
identity mapping (decision 3), and creates the same server-side session a password login
creates. Everything after login (session cookie, cross-origin check, permission checks)
is unchanged.

`ReverseProxyAuthMiddleware` stays as it is for installs that use it, and is not the
Authentik path. Its identity is a username, which an administrator can rename and a
provider can reassign, so it is the wrong key for ADR-0055's mapping. Its trust is an IP
allow-list, so anything that can send from a trusted address can be any user. And it
covers browsers only: the API and the sidecar still need tokens, so a household on that
path would run two identity mechanisms.

### 3. Linking is explicit, keyed on issuer and subject, never email

The mapping is ADR-0055 decision 4's table, `(issuer, subject)` to `users`, unchanged.

- **Linking an existing account.** A user signed in to Victual by password starts a link
  from their own account page. The provider round trip that follows records the mapping.
  An administrator may also link an account, subject to ADR-0014 (holding everything the
  target holds). Unlinking is the same two paths in reverse.
- **An email match does not link.** A provider login whose email equals a local user's,
  with no mapping row, is refused with a message pointing to account linking. A provider
  that lets users edit their own email would otherwise let anyone claim any account.
- **Just-in-time provisioning is off by default.** When an administrator turns it on, a
  provider login with no mapping creates a user only if the login's groups match at least
  one configured group mapping (decision 4). That user's permissions come only from the
  mapping; `DEFAULT_PERMISSIONS` is not applied. Its password is 32 random bytes, as
  `ReverseProxyAuthenticator` already does, so it has no usable local login.

### 4. Groups map to roles, and the mapping can only give what its author holds

An administrator maps provider group names to role codes (ADR-0018). The mapping is
stored in Victual's database because it holds no secret, and editing it requires
`USERS_EDIT`. The author must hold every permission each mapped role confers (ADR-0014's
subset rule, applied as `CheckMayGrant` applies it to a role assignment today). No
group confers `ADMIN` unless a mapping to a role holding it is written down explicitly.

Role assignments that come from the provider are recorded with that source, apart from
assignments an administrator made directly. Direct grants and direct role assignments are
never changed by the provider.

Provider-sourced assignments are recomputed at each browser login and whenever an access
token carries a group set that differs from the stored one. The write happens only when
the set changes. A group removed at the provider therefore removes its roles at the next
login or within one access-token lifetime (five minutes, ADR-0055 decision 6) for an
active API or MCP caller. A token with no group claim leaves the stored set alone.

### 5. The bootstrap administrator stays local

The first administrator is created by the first migration with a generated password and
`must_change_password` set, exactly as today. An external provider does not create,
replace or promote it. After changing that password, the administrator may link a
provider identity to the account like any other user.

`must_change_password` describes the local password, so it governs password-derived
credentials only:

- A local password login and the API keys of a flagged account stay refused, as
  `PasswordChangeRequired` refuses them now.
- A provider login or a provider-issued token for a flagged account is accepted, because
  it proves the provider identity and not the leaked password. The browser is still sent
  to the password form, with a second choice to turn off local login for that account,
  so the leaked password stops being a credential either way.
- `VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION` is not defined on this path. Local accounts
  keep their passwords, so the check must keep running.

Whether an operator may name the administrator's provider subject in configuration, so
that an install never has a local admin password, is open question 3.

### 6. Service callers map like any client

A client-credentials token from Authentik names a service account as its subject. It maps
through ADR-0055 decision 4: a Victual user registers the client, owns the mapping row,
and the client acts with that owner's permissions narrowed by scope. A service account
with no mapping row is refused. Device-flow tokens are ordinary user tokens.

### 7. Local login stays available

Password login stays on by default so that an install still works when the provider is
down. An administrator may restrict it to accounts holding `ADMIN` (a break-glass mode).
Turning it off entirely is open question 4, because a provider outage would then lock
everyone out. ADR-0007's login throttle applies to password login in every mode.

Sessions have Victual's own lifetime (`SESSION_STAY_LOGGED_IN_DAYS`). Logging out of
Victual ends the Victual session and sends the browser to the provider's end-session
endpoint. Back-channel logout, where the provider tells Victual to end a subject's
sessions, is in scope; front-channel logout is not, because it depends on third-party
cookies in the browser.

### 8. Victual does not implement SAML

An environment whose directory speaks only SAML puts a provider in front that accepts
SAML upstream and presents OIDC to Victual. Authentik can do this. Victual-side SAML
would mean XML signature validation in PHP, a library class with a long record of
signature-wrapping vulnerabilities, maintained by one person, for no user the issue
names. The maintainer's comment on issue 771 leans the same way.

### 9. Configuration is declarative and secrets stay out of the database

The issuer URL, the client id, the client secret, whether provisioning is on and the local
login mode come from environment variables and Helm values (ADR-0038). The client secret
comes from a Kubernetes Secret. None of these is stored in a table, so a database restore
cannot leak them. The group mapping (decision 4) is in the database because it is
application data an administrator edits and holds no secret.

The MCP sidecar needs the issuer, its own audience and its token-exchange client
credential, and nothing else from the provider. Under ADR-0039 it would read the issuer
and audience from Victual; the credential stays a Secret.

### 10. Failure behaviour

| Event | Behaviour |
|---|---|
| Provider unreachable | Provider login fails with a message; password login works per decision 7; existing sessions continue; tokens keep validating until `exp` against the cached JWKS |
| Unknown `kid` (key rotation) | One JWKS refetch, at most once a minute (ADR-0055 decision 3); the request fails with `401` if the key is still unknown |
| Clock skew beyond 60 seconds | `401` |
| Wrong or empty audience | `401` |
| User disabled at the provider, token still live | Accepted until `exp`, at most five minutes; refresh fails at the provider; the next browser login fails |
| User disabled or unlinked in Victual | Refused on the next request |
| Group removed at the provider | Decision 4: next login, or within one token lifetime |

### 11. Audit

Victual has no general audit table: none exists in `services/`, `controllers/` or
`migrations/` (searched 2026-10-24). Bookings carry their user on the stock journal, and
that is unchanged. This record adds no audit table. A provider login, a link and an unlink
are written to the application log with the Victual user, the issuer, the subject and,
for a link or unlink, who performed it. Whether these events need a table is open
question 6.

## Alternatives considered

- **Forward authentication at the proxy (Authentik outpost with Traefik).** Least code.
  Rejected as the reference path for decision 2's reasons: username identity, IP-based
  trust, and browsers only.
- **Link by verified email.** Convenient for a household. Rejected because the strength of
  the link would depend on each provider's email verification and on whether users can
  edit their email.
- **Provisioning on by default.** Rejected because a provider misconfiguration would
  create Victual accounts silently. Off by default costs an administrator one setting.
- **Group claims re-evaluated per request without storing them.** Avoids writes, but every
  permission check would depend on a claim only some requests carry, and a browser session
  carries none after login.
- **Victual-side SAML.** Decision 8.

## Consequences

- Household members sign in with their provider identity and keep one password, held by
  the provider.
- A provider outage blocks provider login but not password login, existing sessions, or
  tokens until they expire.
- Victual gains an OIDC relying-party flow, a linking page, a group mapping page and a
  back-channel logout endpoint. `ReverseProxyAuthMiddleware` is unchanged.
- A group change at the provider reaches active API callers within five minutes and
  browsers at their next login.
- An Authentik walkthrough (provider, application, scope mappings, group claims, redirect
  URIs) goes into `docs/manual` with the implementation, not with this record.

## Acceptance prerequisites

1. **ADR-0055 is accepted**, and its prerequisite 1 (the swap test against Authentik) is
   met.
2. **An MCP client registers against Authentik.** Claude Code obtains a client id from the
   Authentik configuration this record describes, by dynamic registration, a metadata
   document or pre-registration, and completes a tool call. If Authentik's registration
   needs a bearer token Claude Code does not have, the record states which registration
   path the install uses instead.
3. **Audience from Authentik.** A token Authentik issues for the sidecar carries the
   sidecar's audience, and one for `/api` carries `/api`'s, whether through `resource`,
   Authentik's own audience setting or token exchange. Both refusals of ADR-0055
   decision 3 hold against Authentik tokens.
4. **Group claims reach Victual.** A group added and then removed at Authentik is reflected
   at the next login and within one token lifetime, as decision 4 states.
5. **Break-glass.** With Authentik stopped, an administrator logs in by password and the
   other users' provider logins fail with a message.
6. **Open questions 1 and 3 answered.**

## Open questions

1. **Which provider issues Victual's tokens in production?** Production's edge signs in
   with Entra ID through Cloudflare Access. Victual's tokens could come from Authentik,
   from Entra ID as a second reference, or from the built-in authorization server.
2. **One issuer or several?** One keeps the mapping, the JWKS cache and the failure table
   simple. Several would let a household mix a work provider and a personal one.
3. **May configuration name the administrator's provider subject?** It would let an install
   have no local administrator password. The cost is that a provider outage with password
   login restricted leaves no way in.
4. **May local login be turned off entirely?** Decision 7 stops at break-glass mode.
5. **Can a provider-linked user create static API keys?** ADR-0055 decision 9 keeps keys
   tied to a user row; a linked user has one. A provisioned user has no local password
   to re-enter when creating a key, if key creation ever asks for one.
6. **Do login and linking events need an audit table?** Issue 771 asks whether they belong
   in "the existing audit trail", and the tree has none. A table would also be the place
   for ADR-0055 decision 7's `act` claim.
