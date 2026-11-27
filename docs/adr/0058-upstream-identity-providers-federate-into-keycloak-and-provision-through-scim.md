# ADR-0058: Upstream identity providers federate into Keycloak, and provision users and groups through SCIM

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request, and it must not be accepted before
  [ADR-0055](0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md).
  When accepted, it supersedes the Proposed
  [ADR-0056](0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md),
  per that record's answer 10. Marking ADR-0056 superseded is its own bookkeeping pull request.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-11-27, against `master` at `8a8a5b35`. Design only: it changes no code,
  reserves no migration number and amends no accepted record.
- **Referenced by:** [issue 771](https://github.com/datagen24/victual/issues/771),
  [issue 1111](https://github.com/datagen24/victual/issues/1111),
  [issue 1141](https://github.com/datagen24/victual/issues/1141).
- **Builds on:** ADR-0055 (Keycloak issues every token), the Proposed
  [ADR-0057](0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md)
  (provider groups map to local groups; service credentials are Keycloak clients),
  [ADR-0014](0014-administering-a-user-is-a-subset-question.md),
  [ADR-0018](0018-role-grants-and-domain-reads.md) and
  [ADR-0038](0038-kubernetes-deployments-ship-as-a-helm-chart.md).

## Context

Issue 771 asks that household members sign in to Victual, and authorize API and MCP clients,
with an identity an external identity provider holds. ADR-0056 designed that with the
provider as the authorization server. The design changed after the spikes, and ADR-0056's
title and decision 1 now describe the earlier model.

### What changed

- **Keycloak is the only issuer.** The maintainer's 2026-10-29 answer (ADR-0055 question 26,
  ADR-0056 question 9): Keycloak handles logins and every token. An upstream identity
  provider federates into it as an identity source and issues no token Victual or the MCP
  sidecar accepts.
- **Authentik is excluded.** It has no atomic revocation of a refresh-token family
  (ADR-0055 questions 21 to 23). It is not a compatibility target, neither as issuer nor as
  upstream (ADR-0056 answer 10). The brokering spike used it only as a stand-in upstream.
- **Entra ID federates into Keycloak directly** (ADR-0056 answer 11). Production needs no
  intermediate provider.
- **Group changes arrive by SCIM.** A membership change received through SCIM logs the user
  out; otherwise the session lifetime bounds a stale group (ADR-0056 answer 12). An
  admin-event poller does the logout, and the fallback session lifetime is 8 hours
  (ADR-0056 answer 15).

### Evidence

Three spikes against Keycloak 26.8.1 bear on this record. Each is evidence, not acceptance.

- **Brokering** (`.devtools/spikes/oauth-keycloak-upstream/`, 2026-10-29, Authentik as the
  upstream). Keycloak issued tokens under its own issuer and subject for a person who signed
  in upstream, and kept one subject across logins (U1, U2). A first-broker-login flow of one
  step, "Create User If Unique", refused an email collision and linked nothing (U3).
  Upstream groups reached the token through a mapper (U4). A group removed upstream stayed in
  refreshed tokens until the person's next upstream sign-in, up to the realm's SSO maximum
  (U5a to U5d). With the upstream down, Keycloak kept refreshing and its own password login
  worked (U8).
- **SCIM** (`.devtools/spikes/oauth-keycloak-scim/`, 2026-11-27). Keycloak's SCIM 2.0 API is a
  supported feature, on by default and switched on per realm (F0). It accepted a bearer token
  only from a confidential client holding `manage-users` with the realm's SCIM base URL as
  audience (N1 to N5). A SCIM group removal changed Keycloak's membership within 74 ms, and the
  next refreshed token lacked the group (M3, M5). The removal did not end the session (M4). A
  poller that read the `GROUP_MEMBERSHIP` admin event and called the admin logout ended it,
  and the old refresh token was refused 90 ms later (L1 to L3). Keycloak dropped `externalId`
  unless a user-profile attribute was annotated to receive it (X1, X2).
- **Service accounts** (`.devtools/spikes/oauth-keycloak-ksa/`, 2026-10-30), recorded under
  ADR-0057 questions 6 to 8.

Not tested anywhere: an Entra ID tenant, either as a brokered upstream or as a SCIM sender.

### Today's login paths

ADR-0056's reading of the tree on 2026-10-24 still holds: `DefaultAuthMiddleware` accepts a
session cookie and, on API routes, an API key; `ReverseProxyAuthMiddleware` trusts a username
header from listed addresses; `users.must_change_password` refuses a flagged account's
password-derived credentials.

## Decision (proposed)

### 1. Keycloak is the only issuer, and upstream providers are identity sources

Keycloak issues every token Victual and the sidecar accept (ADR-0055 decisions 1 and 2). An
upstream identity provider is configured inside Keycloak as an identity provider. Victual and
the sidecar see only Keycloak's issuer and Keycloak's subjects. They never validate an
upstream token.

- **Production upstream:** Entra ID, configured in Keycloak directly.
- **Other upstreams:** any OIDC or SAML provider Keycloak can broker may be configured. The
  project tests only Entra ID and Keycloak's own accounts (ADR-0055 answer 28).
- **No upstream:** an install may run with Keycloak's own accounts only. The decisions below
  that concern an upstream then do not apply.

The Cloudflare Access edge credential stays separate (ADR-0055 decision 10).

### 2. Victual is Keycloak's relying party; the forward-auth proxy is not the path

Browser login runs authorization code with PKCE from Victual, as a confidential client of
Keycloak. On return, Victual validates the ID token, resolves the user through the mapping in
ADR-0055 decision 4, and creates the same server-side session a password login creates.
Everything after login is unchanged.

`ReverseProxyAuthMiddleware` stays as it is for installs that use it, and is not this path.
Its identity is a username, which can be renamed or reassigned. Its trust is an address list.
It also covers browsers only, so the API and the sidecar would still need tokens.

### 3. Two links, both explicit, neither by email

A person who signs in upstream crosses two boundaries, and each has its own link.

- **Upstream identity to Keycloak user.** Keycloak stores this link as a federated identity.
  The first-broker-login flow is one step, "Create User If Unique": it creates a Keycloak user
  when no account holds the email or username, and never links an existing one (spike U3).
  Keycloak's shipped flow, which offers to link after verification, is not used. A user that
  SCIM created before the person's first sign-in is linked by decision 5's step, not by email.
- **Keycloak subject to Victual user.** This is ADR-0055 decision 4's table,
  `(issuer, subject)` to `users`, keyed on Keycloak's issuer and subject. A user signed in by
  password starts a link from their own account page. An administrator may link an account,
  subject to ADR-0014. Unlinking is the same two paths in reverse.

An email match never links at either boundary. Just-in-time provisioning of a Victual user is
off by default. When an administrator turns it on, a login with no mapping creates a Victual
user only if the login's groups match a configured group mapping (decision 4). That user's
permissions come only from the mapping, and its local password is 32 random bytes nobody
knows.

### 4. Groups reach Victual as local groups, written by one source

A Keycloak group maps to a **local Victual group**, which holds roles (ADR-0057 decision 9).
The mapping is application data in Victual's database. Editing it requires `USERS_EDIT`, and
its author must hold every permission the group's roles confer (ADR-0014). Membership that
comes from Keycloak is recorded with that source, and reconciliation changes only the
memberships its mapping owns.

Victual recomputes provider-sourced membership at each browser login, and whenever an access
token carries a group set that differs from the stored one. A token with no group claim
leaves the stored set alone. A present and empty claim removes the user from every group the
mapping manages.

Keycloak's own groups have one writer per install:

- **Upstream sends SCIM (Entra ID):** SCIM writes Keycloak's groups (decision 5). The identity
  provider has no group mapper, so a brokered login does not also write them.
- **Upstream without SCIM:** an identity-provider mapper with sync mode `FORCE` writes the
  upstream's groups at each brokered login, as in spike U4.
- **No upstream:** an administrator edits Keycloak's groups.

The client mapper that puts `groups` into Victual's tokens reads Keycloak group membership.

### 5. SCIM provisions users and groups into Keycloak

Keycloak receives SCIM 2.0 from the upstream at `/realms/<realm>/scim/v2`, with the realm's
`scimApiEnabled` set. Victual does not run a SCIM endpoint.

- **The sender's credential** is a confidential Keycloak client with the client-credentials
  grant. Its service account holds `manage-users`, `view-users` and `query-groups` on
  `realm-management`. An audience mapper adds the realm's SCIM base URL, which the endpoint
  requires (spike N5). It holds nothing else.
- **Users** are created with `active: true`; Keycloak creates a user disabled without it
  (spike M1). `active: false` disables the user and refuses the next refresh (spike M8).
- **Group members** are added and removed with SCIM `PATCH`; Keycloak refuses members on group
  creation (spike M0).
- **The upstream's stable id** is stored through a user-profile attribute annotated
  `kc.scim.schema.attribute: externalId` (spike X2). Without the annotation Keycloak drops it
  (spike X1).
- **Linking a provisioned user.** When SCIM creates a Keycloak user, a link step creates its
  federated identity for the upstream from that stored id, through Keycloak's admin API. The
  person's first brokered sign-in then lands on the provisioned user instead of colliding with
  it. Which component runs the step, and which upstream claim the id must equal, are open
  questions 1 and 2.

### 6. A group removal ends sessions, and 8 hours bounds the rest

- **The poller.** A poller reads Keycloak's `GROUP_MEMBERSHIP` admin events and calls
  `POST /admin/realms/<realm>/users/<id>/logout` for each user who lost a group. That ends the
  user's Keycloak sessions, so no refresh token issued earlier works (spike L1 to L3). Its
  Keycloak credential holds what reading events and logging users out need, and nothing else.
  Where it runs is tracked with the revocation housekeeping job in issue 1141.
- **The fallback.** The realm's maximum SSO session lifetime is 8 hours. A group removal that
  reaches Keycloak without SCIM, or that the poller misses, lasts at most that long.
- **Offline tokens** outlive the SSO maximum, so no client in the realm may request
  `offline_access` unless open question 3 makes an exception.

The resulting bounds on a stale group:

| Path | Bound |
|---|---|
| SCIM removal, poller running | The poll interval, plus up to six minutes for an access token already issued (ADR-0055 decision 6) |
| SCIM removal, poller down | The next refresh drops the group (spike M5): up to six minutes after the access token's issue |
| Removal upstream without SCIM | The person's next brokered sign-in, or 8 hours |
| Deactivation through SCIM | The next refresh is refused (spike M8): up to six minutes |

Victual's own browser session outlives a Keycloak logout unless something ends it. Keycloak can
send an OIDC back-channel logout to Victual's client when it ends a user's sessions. Whether
that is the mechanism is open question 4.

### 7. Local accounts at each layer are recovery accounts

- **Victual.** The bootstrap administrator is created by the first migration with a generated
  password and `must_change_password` set, as today. Neither Keycloak nor an upstream creates,
  replaces or promotes it, and configuration cannot name its subject. It may link a Keycloak
  identity afterwards. In Classic mode (ADR-0055 decision 9) password login works for every
  local account. In OAuth mode it is limited to the bootstrap administrator and accounts
  holding `ADMIN`; any other account's password login is refused with a message that names
  provider sign-in.
- **Keycloak.** Keycloak's own password login is limited to bootstrap and break-glass accounts
  (ADR-0056 answer 13). It is the only new sign-in during an upstream outage (spike U8d), so
  the browser flow keeps the password form, and every other account is refused at it.
- **`must_change_password`** governs password-derived credentials only. A flagged account's
  local password login and API keys stay refused. Its Keycloak-issued token is accepted, and
  the browser is still sent to the password form with a choice to turn local login off.
- ADR-0007's login throttle applies to Victual's password login in both modes.

Password login cannot be turned off entirely at either layer, because an outage of the layer
above would lock everyone out.

### 8. First-run setup is an on-demand Job

First-run setup runs from the migrate image as a Kubernetes Job an operator starts (ADR-0056
answer 14). That image holds the only database role that can run DDL and the only PHP with
`pdo_sqlite` (ADR-0013 decision 6).

- It listens on a cluster-internal port only. The operator reaches it with
  `kubectl port-forward`, so access to the namespace is the gate before any Victual user
  exists. It exits when setup finishes.
- It covers the bootstrap administrator, Keycloak's realm, its upstream identity provider and
  SCIM client, and the legacy import. It holds Keycloak admin credentials while it runs, which
  is why it is not a long-running service.
- Service pairing is not part of it: devices redeem pairing codes on `/api` (ADR-0057
  question 7).
- An install without Kubernetes binds it to localhost and prints a one-time setup URL in the
  container log.

### 9. Service callers are Keycloak clients

ADR-0057 questions 6 to 8 govern this, as answered on 2026-11-27. A service account is a
Keycloak client using the client-credentials grant. Inside the cluster it authenticates with
its projected Kubernetes service-account token, with a 600 s lifetime; outside, with a key
pair it generated at pairing. Revoking a service account disables its Keycloak client.
Keycloak's availability target is similar to Victual's, because a service keeps working
through a Keycloak outage only until its access token expires.

### 10. Victual does not implement SAML

A directory that speaks only SAML is configured as a SAML identity provider in Keycloak.
Victual sees OIDC from Keycloak either way. Victual-side SAML would mean XML signature
validation in PHP, which has a long record of signature-wrapping vulnerabilities, for no user
the issue names.

### 11. Configuration is declarative, and secrets stay out of Victual's database

- Keycloak's realm, clients, identity providers, flows, mappers and user-profile annotations
  are configuration as code, versioned with the deployment and reapplied without removing
  users or credentials created since (issue 1139's constraint).
- Keycloak's issuer URL, Victual's client id and client secret, the provisioning setting and the
  local login mode come from environment variables and Helm values (ADR-0038). Secrets come
  from Kubernetes Secrets.
- The SCIM sender's client secret, or the key it authenticates with, is held by the upstream
  and by Keycloak, never by Victual.
- The group mapping (decision 4) is in Victual's database because it is application data an
  administrator edits, and it holds no secret.
- The chart's all-in-one values file runs Keycloak; its distributed values file points Victual
  at an external Keycloak (ADR-0055 answer 27).

### 12. Failure behaviour

| Event | Behaviour |
|---|---|
| Upstream unreachable | New brokered sign-ins fail. Live Keycloak sessions keep refreshing (spike U8a). Keycloak's recovery accounts sign in at Keycloak; Victual's at Victual (decision 7) |
| Keycloak unreachable | Victual's recovery accounts log in by password. Existing Victual sessions continue. Tokens validate until `exp` against the cached JWKS. Services stop when their access tokens expire |
| SCIM delivery delayed or failed | Keycloak's groups lag the upstream. The 8-hour SSO maximum bounds a removal that never arrives |
| Poller down | A SCIM removal still drops the group at the next refresh; sessions are not ended until it returns |
| Unknown `kid` | One JWKS refetch, at most once a minute (ADR-0055 decision 3); `401` if the key is still unknown |
| Clock skew beyond 60 seconds, or wrong audience | `401` |
| User disabled in Victual or unlinked | Refused on the next request |
| Email collision at first brokered sign-in | Refused at Keycloak; nothing is linked (decision 3) |

### 13. Authentication events go to an audit table

This keeps ADR-0056 decision 11 unchanged, restated so that it survives the supersession.
Victual adds one append-only table for authentication events:

| Event | Written when |
|---|---|
| `provider_login` | A Keycloak login completes and resolves to a Victual user |
| `provider_login_refused` | A Keycloak login is refused: no mapping with provisioning off, or a disabled user |
| `link` | An `(issuer, subject)` mapping is created, by the user or by an administrator |
| `unlink` | A mapping is removed |
| `token_exchange_used` | Victual first sees an exchanged token whose exchange authenticated evidence establishes (ADR-0055 decision 7) |

Its columns are those ADR-0056 decision 11 lists: `id`, `occurred_at`, `event`, `user_id`,
`issuer`, `subject`, `actor_user_id`, `client_id`, `exchanger`, `grant_id`,
`attribution_source`, `act_subject`, `token_id`, `reason` and `remote_address`. A trigger
refuses `UPDATE`, and refuses `DELETE` of a row younger than the retention period, which
defaults to 365 days. Reading it requires the leaf `AUTH_AUDIT_VIEW` under `USERS_READ`. No
scope grants it, and the generic objects API does not expose it. The implementation reserves a
migration number in its own pull request.

Keycloak's own admin and login events record SCIM changes and logouts, so Victual does not copy
them into this table.

## Options considered

- **Keep ADR-0056 and rename it.** Rejected by ADR-0056 answer 10: the change of issuer touches
  every decision, and a new record keeps the original reasoning readable.
- **Authentik upstream with Entra ID behind it.** Rejected by answers 10 and 11: Entra ID
  federates into Keycloak directly, and Authentik is excluded.
- **A SCIM endpoint on Victual.** Victual would recompute roles and end Keycloak sessions
  through the admin API. Rejected because Keycloak already accepts SCIM as a supported feature
  (spike F0), and a second receiver would make Victual hold Keycloak admin credentials on a
  public route.
- **Brokered group sync only.** No SCIM, and a short SSO maximum to bound stale groups.
  Rejected by answer 12: SCIM delivers a removal without waiting for a sign-in, and a short SSO
  maximum sends people upstream more often.
- **Link a provisioned user by email at first sign-in.** Rejected for decision 3's reason: the
  link's strength would depend on the upstream's email verification.

## Consequences

- People sign in with their Entra ID identity and hold one password, at Entra ID.
- Victual and the sidecar trust one issuer and know nothing of the upstream. A change of
  upstream is Keycloak configuration.
- A group removed in Entra ID reaches Victual within the poll interval plus six minutes. One
  that never arrives by SCIM lasts at most 8 hours.
- Keycloak becomes the component whose outage stops new sign-ins and, after five minutes, the
  services. Its availability target is similar to Victual's.
- Victual gains an OIDC relying-party flow, a linking page, a group mapping page, a back-channel
  logout endpoint, the audit table and its retention routine. It gains no SCIM endpoint and no
  SAML.
- The deployment gains a poller holding a narrow Keycloak credential, a first-run setup Job and
  versioned realm configuration.
- An Entra ID walkthrough (application registration, SCIM provisioning, claim and attribute
  mappings) goes into `docs/manual` with the implementation, not with this record.

## Acceptance prerequisites

1. **ADR-0055 is accepted.**
2. **Brokered sign-in from Entra ID.** A person signs in at Entra ID and receives a token under
   Keycloak's issuer, with the same Keycloak subject on a second sign-in, and the
   "Create User If Unique" flow refuses an email collision. Needs a tenant.
3. **SCIM from Entra ID.** Entra ID's provisioning service authenticates to Keycloak's SCIM
   endpoint with a credential decision 5 allows, creates an active user with its stable id
   stored, and adds and removes a group member.
4. **A provisioned user's first sign-in.** After decision 5's link step, the person's first
   brokered sign-in reaches the SCIM-created user, and an unlinked collision is still refused.
5. **The poller.** A SCIM group removal ends the user's Keycloak sessions within the stated
   poll interval, and the measured worst case is recorded with the command that reproduces it.
6. **Victual's session.** Open question 4's mechanism ends Victual's browser session after a
   group removal, measured the same way.
7. **Recovery.** With the upstream down, a Keycloak recovery account signs in and an ordinary
   Keycloak account is refused at the password form. With Keycloak down, Victual's recovery
   accounts log in and other accounts' password logins are refused in OAuth mode.

## Open questions

1. **Which upstream claim keys the federated identity, and does SCIM's `externalId` carry it?**
   Decision 5's link step needs the two to match. The recorder understands that Entra ID's
   OIDC `sub` is pairwise per application, while its object id (`oid`) is stable across
   applications. If so, Keycloak's identity provider must key on `oid`, and Entra ID's SCIM
   mapping must send `oid` as `externalId`. Neither is verified without a tenant.
   (Decision 5; prerequisites 2 to 4.)
2. **Which component runs decision 5's link step?** Proposed: the poller, which already reads
   Keycloak's admin events. A SCIM user creation is an admin event, and its handler creates
   the federated identity through the admin API. The alternative is a custom
   first-broker-login authenticator that matches the stored id, which is Keycloak extension
   code the project would maintain. (Decision 5.)
3. **May any client use offline tokens?** Offline tokens outlive the 8-hour SSO maximum, so
   decision 6 refuses `offline_access` to every client. A command-line client on the device
   flow may want one. Is that refused, or allowed with its own bound? (Decision 6.)
4. **How does a Keycloak logout end Victual's browser session?** Proposed: Victual registers a
   back-channel logout URL on its Keycloak client, and Keycloak's admin logout sends it a
   logout token naming the user's session. Not tested: the SCIM spike's relying party had no
   back-channel URL. The alternative is for the poller to end Victual's sessions directly.
   (Decision 6; prerequisite 6.)
5. **What is the poll interval?** It adds directly to the group-removal bound. Keycloak keeps
   admin events in its database, so a poller that restarts can resume from the last event it
   handled. (Decision 6; prerequisite 5.)
