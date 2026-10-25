# ADR-0056: An external identity provider replaces the built-in authorization server, with Authentik as the reference

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request, and it must not be accepted before
  [ADR-0055](0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md).
  The maintainer answered all six open questions on 2026-10-24; the answers are folded
  into the decisions and recorded under Open questions. Acceptance prerequisites 1 to 5
  are unmet as of 2026-10-24. On 2026-10-25 the Authentik compatibility report met the
  Authentik side of prerequisite 4 and recorded evidence against prerequisites 2 and 3.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-24, against `master` at `d40f5afb`. Design only: it changes no code,
  reserves no migration number and amends no accepted record.
- **Referenced by:** [issue 771](https://github.com/datagen24/victual/issues/771).
- **Reconciled by:** the Proposed [ADR-0057](0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md) (2026-10-25): its decision 9 maps
  provider groups to local groups, which hold the roles, instead of decision 4's direct
  group-to-role mapping.
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
in with Entra ID (ADR-0052). That identity ends at the edge. Authentik is the home-lab
reference, the test target and, by the maintainer's answer to open question 1, the issuer
of production's tokens (decision 1).

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
for browser login. Exactly one issuer per install (open question 2): the mapping, the JWKS
cache and the failure table in decision 10 each assume a single issuer.

In production that issuer is Authentik (open question 1). Entra ID is configured as an
upstream source inside Authentik, so a person keeps one sign-in and Victual sees only
Authentik's issuer and subjects. The Cloudflare Access edge credential stays separate
(ADR-0055 decision 10). Configuring Authentik, its Entra ID source and Cloudflare is
infrastructure work outside this repository. What Victual requires of it:

- one Authentik OAuth2/OpenID provider whose issuer URL is reachable from Victual and the
  sidecar, with a published JWKS;
- the redirect URI of Victual's relying-party callback registered on that provider;
- a stable subject per person whether they sign in through Entra ID or directly, so that
  one person maps to one Victual user;
- group membership in the token claims (decision 4);
- token exchange enabled for the sidecar's client (ADR-0055 decision 7).

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

Configuration cannot name the administrator's provider subject (open question 3). The
bootstrap administrator is always a local account, and it may link a provider identity
afterwards.

### 6. Service callers map like any client

A client-credentials token from Authentik names a service account as its subject. It maps
through ADR-0055 decision 4: a Victual user registers the client, owns the mapping row,
and the client acts with that owner's permissions narrowed by scope. A service account
with no mapping row is refused. Device-flow tokens are ordinary user tokens.

Linked and provisioned users may create static API keys like any other user until
ADR-0055 decision 9 stops new keys on their install (phase 2); their keys then retire
with everyone else's at phase 3 (open question 5). Key creation must not require
re-entering a local password, because a provisioned user has none it knows. The
manage-API-keys form asks for no password today (`OpenApiController::CreateNewApiKey`,
read 2026-10-24), and that stays true.

### 7. Local login stays available

Password login stays on by default so that an install still works when the provider is
down. An administrator may restrict it to accounts holding `ADMIN` (break-glass mode).
Break-glass is the strongest mode: password login cannot be turned off entirely
(open question 4), because a provider outage would then lock everyone out. ADR-0007's
login throttle applies to password login in every mode.

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

### 11. Authentication events go to an audit table

Victual has no general audit table: none exists in `services/`, `controllers/` or
`migrations/` (searched 2026-10-24). Bookings carry their user on the stock journal, and
that is unchanged. This record adds one table for authentication events only
(open question 6). Its implementation reserves a migration number in its own pull
request; this record reserves none.

Events recorded:

| Event | Written when |
|---|---|
| `provider_login` | A provider login completes and resolves to a Victual user |
| `provider_login_refused` | A provider login is refused: no mapping and provisioning off, an email match with no link, or a disabled user |
| `link` | An `(issuer, subject)` mapping is created, by the user or by an administrator |
| `unlink` | A mapping is removed |
| `token_exchange_used` | Victual first sees an exchanged token carrying `act` (ADR-0055 decision 7) |

Columns:

| Column | Content |
|---|---|
| `id` | Row id |
| `occurred_at` | `TIMESTAMPTZ` |
| `event` | One of the five values above, held to them by a `CHECK` |
| `user_id` | The Victual user, or null for a refusal that resolved to nobody |
| `issuer`, `subject` | The provider identity the event concerns |
| `actor_user_id` | Who performed a `link` or `unlink`; null otherwise |
| `client_id` | The OAuth client, for a provider login through a client or a token exchange |
| `act_subject` | The `act` subject of an exchanged token |
| `token_id` | The exchanged token's `jti`, unique where present |
| `reason` | A short refusal code for `provider_login_refused` |
| `remote_address` | The request's client address as Victual saw it |

`token_exchange_used` is written once per exchanged token. The insert conflicts on
`token_id` and does nothing the second time, so one exchanged token is one row however
many requests it serves. The sidecar's cache (ADR-0055 decision 7) bounds this to about one
row per user per token lifetime while the user is active.

Rows are append-only. A trigger refuses `UPDATE` and refuses `DELETE` of a row younger
than the retention period. Retention defaults to 365 days, set by an install setting, and
a maintenance routine deletes older rows. A database restore brings the table back with
the rest of the data; it holds identifiers and addresses, not secrets.

Reading the table requires a new permission leaf, `AUTH_AUDIT_VIEW`, placed under
`USERS_READ`, because inspecting who signed in is part of inspecting users. It is not
exposed through the generic objects API, and no route writes to it except the events
above. No scope grants it (ADR-0055 decision 5 keeps user administration session-only).

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
- Victual gains an OIDC relying-party flow, a linking page, a group mapping page, a
  back-channel logout endpoint, an authentication audit table with its permission leaf
  `AUTH_AUDIT_VIEW`, and a retention routine for it. `ReverseProxyAuthMiddleware` is
  unchanged.
- An install cannot run without a local administrator password, and cannot turn password
  login off entirely; break-glass mode is the strongest restriction.
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

   **2026-10-25: not met.** Authentik 2026.8.5's registration endpoint refused a request
   with no token and one with a user token lacking its registration scope (report case
   N15, `.devtools/spikes/oauth-authentik/`). Claude Code registers anonymously, so on Authentik it must use
   ADR-0055 decision 8's endpoint, which creates one Authentik provider per client. No
   client was run against Authentik.
3. **Audience from Authentik.** A token Authentik issues for the sidecar carries the
   sidecar's audience, and one for `/api` carries `/api`'s, whether through `resource`,
   Authentik's own audience setting or token exchange. Both refusals of ADR-0055
   decision 3 hold against Authentik tokens.

   **2026-10-25: partly met.** Exchange gives an `/api` token `/api`'s audience, and both
   refusals held against Authentik tokens (cases P5, P6, N1 to N3). A sidecar audience
   does not arise: `aud` is the issuing provider's `client_id`, `resource` is ignored at
   authorization, and `audience` exists only on exchange. A token an MCP client obtains
   is audienced to that client (ADR-0055 open question 9).
4. **Group claims reach Victual.** A group added and then removed at Authentik is reflected
   at the next login and within one token lifetime, as decision 4 states.

   **Authentik side, 2026-10-25: met.** A group was present in the token's `groups`
   claim, absent from the next token after removal, and present again after re-adding
   (case P9). Victual's recompute is not built, so the Victual side is untested.
5. **Break-glass.** With Authentik stopped, an administrator logs in by password and the
   other users' provider logins fail with a message. Not tested as of 2026-10-25.
6. **Open questions 1 and 3 answered.** Met 2026-10-24: the maintainer answered all six.

## Open questions and maintainer responses

All six were answered on 2026-10-24 and are folded into the decisions named in each
response. The answers do not accept this record.

1. **Which provider issues Victual's tokens in production?** Production's edge signs in
   with Entra ID through Cloudflare Access.

   > **Response:** datagen24, 2026-10-24: Authentik issues production tokens, with Entra
   > ID as an upstream source in Authentik, so people keep one sign-in. The Cloudflare
   > Access edge credential stays separate (ADR-0055 decision 10). Infrastructure
   > configuration is not in this repository; the record describes requirements only.
   > (Decision 1.)

2. **One issuer or several?**

   > **Response:** datagen24, 2026-10-24: one issuer per install. (Decision 1.)

3. **May configuration name the administrator's provider subject?**

   > **Response:** datagen24, 2026-10-24: no. The bootstrap administrator stays local and
   > may link a provider identity afterwards. (Decision 5.)

4. **May local login be turned off entirely?**

   > **Response:** datagen24, 2026-10-24: no. The strongest mode is break-glass, password
   > login restricted to holders of `ADMIN`. (Decision 7.)

5. **Can a provider-linked user create static API keys?**

   > **Response:** datagen24, 2026-10-24: linked or provisioned users may create static
   > keys until key retirement applies to their install, under ADR-0055 open question 2's
   > rule. Key creation must not require re-entering a local password such a user does
   > not have. (Decision 6.)

6. **Do login and linking events need an audit table?** Issue 771 asks whether they belong
   in "the existing audit trail", and the tree has none.

   > **Response:** datagen24, 2026-10-24: add an audit table for provider login, link and
   > unlink, and for ADR-0055 decision 7's `act` claim on token exchange. Design it at
   > record level: events, columns, retention, and who may read it through a permission
   > leaf. Its implementation reserves a migration in its own pull request. (Decision 11;
   > ADR-0055 decision 7.)

### Raised by the Authentik compatibility report, 2026-10-25 (unanswered)

7. **What does decision 11 record for `token_exchange_used`** if the exchanged token
   carries no `act`? See ADR-0055 open question 8.
