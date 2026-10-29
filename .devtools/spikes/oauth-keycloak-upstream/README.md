# Spike: Keycloak brokering to Authentik as an upstream identity source

Throwaway. On 2026-10-29 the maintainer clarified the model in
[ADR-0055](../../../docs/adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
question 26 and
[ADR-0056](../../../docs/adr/0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md)
question 9. Keycloak issues every token. A lab identity provider, or Entra ID, signs people in
upstream and federates into Keycloak. This spike checks what that boundary does: linking,
groups, deactivation, logout and an upstream outage.

The records do not yet contain this model, so each case names the clause it would satisfy once
they are rewritten for it. "pass" and "FAIL" compare against that clause; "obs" records behaviour
no clause decides yet. A FAIL is a result: no expectation was weakened to make a case pass.

Keycloak **26.8.1** (`start-dev`, embedded file database) and Authentik **2026.8.5** (the
[Authentik spike](../oauth-authentik/)'s stack: PostgreSQL 16, server, worker), run on 2026-10-29
on the shared podman VM (4 CPU / 8 GiB, arm64). Keycloak is capped at 1200 MiB and used 707 MiB
after the run. Keycloak's persistence is not under test, so it uses no PostgreSQL of its own and
the spike runs one PostgreSQL server.

## Run

```bash
cd .devtools/spikes/oauth-keycloak-upstream
./run.sh up          # the Authentik spike's stack, then Keycloak on 127.0.0.1:8180
node setup.mjs       # both sides through their admin APIs; writes state.json
node probe.mjs       # the U cases; prints the table and writes results.json (about 40 s)
./run.sh down
```

`./run.sh ak-stop` and `ak-start` stop and start Authentik's server and worker; the probe calls
them for U8. Files: `browser.mjs` (a scripted browser across both servers), `setup.mjs`,
`probe.mjs`, `run.sh`. The Keycloak admin password comes from the Keycloak spike's gitignored
`.secrets.env`, and its `lib.mjs` supplies the admin REST helper.

## Shape under test

- **One Authentik hostname.** Authentik derives its issuer from the request's Host. Keycloak, in
  a container, reaches it as `host.containers.internal:9000`. The probe on the Mac uses the same
  name and resolves it to 127.0.0.1 itself (`node:http` with a `lookup`), so both see one issuer.
- **Authentik:** one confidential OAuth2 provider for Keycloak, with a per-provider issuer, the
  built-in `openid`, `profile` and `email` scopes, and back-channel logout to Keycloak's realm
  endpoint `/protocol/openid-connect/logout/backchannel-logout`.
- **Keycloak:** identity provider `authentik` (OIDC, PKCE, signature validated by JWKS, sync mode
  FORCE). A mapper copies the upstream `groups` claim into the user attribute `upstream_groups`,
  and the client's mapper puts it in the access token as `groups`. First and last name are not
  required, because Authentik sends no `family_name`; otherwise the first login stops at an
  "update account" form.
- **First-broker-login flow `victual-first-broker-nolink`:** one REQUIRED step, "Create User If
  Unique". It creates the user when no account holds the email or username and never links one.
  The shipped flow is tried once for comparison (U3b).

## Results

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| U1 | ADR-0056 q9: a person signs in upstream and Keycloak issues the token | token from Keycloak's issuer; `sub` is the Keycloak user id; no password; one federated identity at `authentik` | token; `iss` Keycloak's realm; `sub` the Keycloak user id; 0 credentials; federated `authentik:kcup-alice` | pass |
| U2 | ADR-0055 d4, ADR-0056 d3: one person, one stable subject | a second login in a new browser gives the same `sub`, one user, one federated identity | same `sub`; 1 user; 1 federated identity; went upstream | pass |
| U3 | ADR-0056 d3 at Keycloak: an email match never links | no token; the local account gains no federated identity; no user is created | Keycloak error page "User with email … already exists. Please login to account management to link the account"; 0 federated identities; no user created | pass |
| U3b | observation: the shipped first broker login flow on the same collision | what it asks for | "Account already exists … How do you want to continue? Review profile / Add to existing account"; no link and no token without a further step; the "Add to existing account" path was not followed | obs |
| U4 | ADR-0056 prerequisite 4: upstream groups reach the token | `groups` holds the Authentik group | `[victual-kcup-adults]` | pass |
| U5a | ADR-0056 d4, prerequisite 4: a removal reaches an active caller within one token lifetime | after removal at Authentik, a refreshed token lacks the group | refresh 200; `groups` still `[victual-kcup-adults]` | **FAIL** |
| U5b | ADR-0056 d4: a removal reaches the next login | a login in the same browser lacks the group | token without going upstream (live Keycloak session); `groups` still `[victual-kcup-adults]` | **FAIL** |
| U5c | ADR-0056 d4: a removal reaches the next login | a login in a new browser goes upstream and lacks the group | went upstream; `groups` `[]` | pass |
| U5d | observation: one fresh upstream login and the other sessions | a refresh in the first browser's session after U5c | `groups` `[]`: the mapper rewrote the user attribute, which every session reads. Without a fresh upstream login the stale set lasts while the session refreshes: 1800 s idle, 36000 s maximum in this realm | obs |
| U6a | observation: deactivation at Authentik and the live Keycloak session | Keycloak sessions after 10 s; refresh; same-browser login | 0 Keycloak sessions; refresh `invalid_grant` (session not active); same-browser login sent upstream and refused | obs |
| U6b | ADR-0056 d1 with q9: a deactivated upstream user cannot sign in anew | a new-browser login is refused upstream | refused at Authentik's password stage | pass |
| U7 | ADR-0056 d7: back-channel logout ends the subject's sessions | ending bob's Authentik session ends his Keycloak session | 1 Authentik session deleted; Keycloak sessions 1 → 0 within 1 s; refresh `invalid_grant` | pass |
| U8a | ADR-0056 prerequisite 5 with q9: an upstream outage leaves issued grants working | refresh with Authentik stopped | 200 | pass |
| U8b | observation: a login inside a live Keycloak session during the outage | same browser | token, without going upstream | obs |
| U8c | observation: a new brokered login during the outage | what the user sees | Keycloak redirects to Authentik, which refuses the connection; no Keycloak error page | obs |
| U8d | ADR-0056 prerequisite 5 with q9: Keycloak's local login is the recovery path | a local Keycloak account signs in through Keycloak's form; the master admin gets a token | local account token; master admin 200 | pass |
| U8e | ADR-0056 prerequisite 5: brokered login returns with the upstream | a new-browser login after Authentik restarts | token | pass |

Run history: the first run stopped at U8c, because the scripted browser threw on the refused
connection. The second run reported U8d as a FAIL because the harness fetched the redirect URI
after the local form instead of reading it. Both were harness fixes. The verdicts above are from
the third run, and U1 to U7 gave the same verdicts in all three.

## Findings

1. **Brokering works with configuration only (U1, U2, U4, U8e).** Keycloak issues the token under
   its own issuer and subject. Authentik's subject is held as a federated identity, so Victual
   maps one issuer and one `sub` per person. Upstream groups arrive through two mappers.
2. **The email rule needs a custom first-login flow (U3, U3b).** "Create User If Unique" alone
   refuses a collision with a Keycloak error page and links nothing. The shipped flow offers to
   link the existing account, after a verification step that was not followed here. Under ADR-0056 decision 3 the custom flow is the
   safe default, and it becomes versioned configuration under ADR-0055 prerequisite 8.
3. **A group removed upstream stays in Keycloak's tokens until the person signs in upstream again
   (U5a to U5d).** Sync mode FORCE runs only on a brokered login. A refresh, or a login inside a
   live Keycloak session, keeps the old groups. One fresh upstream login updates the user
   attribute, and with it every session of that user. Without one, the stale set lasts as long
   as the session keeps refreshing: up to the realm's SSO maximum (36000 s here).
   ADR-0056 decision 4's "within one access-token lifetime" does not hold through Keycloak as
   configured here.
4. **Authentik's back-channel logout reaches Keycloak (U7).** Ending an Authentik session ended
   the Keycloak session within 1 s. After a deactivation the Keycloak session was gone when
   checked 10 s later (U6a), but that run did not capture which request ended it. Keycloak 26.8.1 validates the upstream logout token at its realm back-channel endpoint
   (`OIDCIdentityProvider.validateLogoutToken`). Group changes do not end sessions, so finding 3
   stands.
5. **An upstream outage leaves Keycloak serving (U8).** Refresh and logins inside a live session
   keep working. A new brokered login ends on a browser connection error, not a Keycloak page.
   Keycloak's own login form and its master admin keep working. That needs the browser flow to
   keep the password form: the Keycloak spike's L4 showed a redirector-only flow removes it.

## Not tested

- Entra ID as an upstream, either directly or through Authentik (ADR-0056 question 11). It needs
  a tenant.
- The "Add to existing account" path of the shipped flow, and an explicit link that Victual or an
  administrator starts.
- Offline tokens (`offline_access`), which outlive the SSO maximum and would extend finding 3.
- Group mapping into Victual roles (Victual's recompute is not built), token exchange for a
  brokered user, and the Victual and sidecar images.
- Keycloak on PostgreSQL or on two replicas in this shape; a production hostname and TLS on
  either side; Authentik's front-channel logout.
