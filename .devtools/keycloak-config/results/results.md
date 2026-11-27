| Case | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| U0 | plan 43: apply to an empty instance | exit 0, nothing left unapplied | exit 0; 34 changes; unapplied [] | pass |
| B1 | ADR-0058 d3: a unique upstream identity creates and links one Keycloak user | token issued; erin has one federated identity at upstream | token 200; federated ["upstream"] | pass |
| U1 | ADR-0055 q18: upgrade version 1 to version 2 | exit 0, nothing left unapplied | exit 0; 35 changes in 2786 ms, including the read-back pass; unapplied [] | pass |
| U2 | plan 43: version 2 holds after the upgrade | --check exit 0, no changes | exit 0; 0 changes | pass |
| U3 | ADR-0055 q18: an upgrade removes what version 2 dropped and changes what it changed | legacy client and victual:write gone; SSO max 28800; direct grant bound to victual-direct-grant; browser flow has the local-only gate; realm records version 2 | {"legacyClient":false,"writeScope":false,"ssoMax":28800,"directGrant":"victual-direct-grant","gate":true,"version":"2"} | pass |
| U4 | ADR-0055 q18, ADR-0058 d6: the adapter's objects are upgraded to recipe 3 | client and grant scope stamped recipe 3; offline_access no longer optional | client recipe 3; grant scope recipe 3; optional victual:read,victual:catalog,victual:shopping,victual:stock | pass |
| U5 | issue 1139: reapplying preserves users, credentials, sessions, SCIM users, links | no user, credential, federated identity, group membership, upstream id or session count changes | 5 users identical | pass |
| U6 | issue 1139: a session from before the upgrade keeps working | its refresh token refreshes; the break-glass password still logs in | refresh 200; password login code issued | pass |
| M1 | plan 43: a second apply changes nothing | exit 0, zero changes, Keycloak's partial export unchanged | exit 0; 0 changes; export 4424356745f036d5 -> 4424356745f036d5 | pass |
| M2 | plan 43: a third apply changes nothing | exit 0, zero changes | exit 0; 0 changes | pass |
| D1 | plan 43: --check reports every drift without writing | exit 2; names all 16 drifts | exit 2; 17 changes; not named: none | pass |
| D2 | plan 43: apply repairs the drift | exit 0, nothing left unapplied | exit 0; 18 changes; unapplied [] | pass |
| D3 | plan 43: the repaired realm is in sync | --check exit 0 | exit 0; 0 changes | pass |
| D4 | issue 1139: drift repair preserves users, credentials, sessions, SCIM users, links | no difference from the snapshot after U6 | identical | pass |
| D5 | issue 1139: the session survives the repair (including the secret being reset) | refresh 200 | refresh 200  | pass |
| N1a | ADR-0058 d5: the SCIM sender reaches the SCIM API | 200 | 200; 5 users | pass |
| N1b | ADR-0058 d5: a manage-users token without the SCIM audience is refused | 401 | 401 {"schemas":["urn:ietf:params:scim:api:messages:2.0:Error"],"status":"401","detai | pass |
| N1c | ADR-0058 d5: an end user's token is refused at SCIM | 401 or 403 | 401 | pass |
| N1d | ADR-0058 d5: externalId is stored and searchable | one user for the filter | 200; 1 result(s) | pass |
| N2a | ADR-0058 d6: no offline token for Victual's client | no Offline refresh token | {"error":"invalid_scope Invalid scopes: openid offline_access"} | pass |
| N2b | ADR-0058 d6: no offline token for an adapter-registered MCP client | no Offline refresh token | {"error":"invalid_scope Invalid scopes: openid offline_access"} | pass |
| N2c | ADR-0058 d6: no offline token at the exempt admin console client | refused or not Offline | {"error":"400 not_allowed Offline tokens not allowed for the user or client"} | pass |
| N2d | ADR-0058 d6: no offline token through admin-cli's password grant | no Offline refresh token | 400 invalid_scope | pass |
| N3a | ADR-0058 d7: an ordinary account's password login is refused at the form | no code; the page names provider sign-in | stopped at 401: "Sign in with your organisation&#39;s identity provider." | pass |
| N3b | ADR-0058 d7: a break-glass account's password login works | code issued | code issued | pass |
| N3c | ADR-0058 d7: an ordinary account's password grant (admin-cli) is refused | not 200 | 401 {} | pass |
| N3d | ADR-0058 d7: a break-glass account's password grant (admin-cli) works | 200 | 200 | pass |
| N3e | ADR-0055 d6: no password grant at Victual's client | 400 unauthorized_client | 400 unauthorized_client | pass |
| N3f | ADR-0058 d7: an ordinary account's wrong password is still counted by brute-force protection | observation | numFailures 2, disabled true | obs |
| N4a | ADR-0058 d3: an email collision at first broker login is refused and links nothing | no code; 'already exists'; carol keeps zero federated identities; no user created | Sign in to Victual Victual We are sorry... User with email carol&#64;example.invalid already exists. Please login to acc; federated 0; users 5 -> 5 | pass |
| N5a | ADR-0055 d6: an authorization request without PKCE is refused | error | invalid_request Missing parameter: code_challenge_method | pass |
| N5b | ADR-0055 d6: plain PKCE is refused | error | invalid_request Invalid parameter: code challenge method is not matching the configured one | pass |
| N6a | ADR-0055 d7, ADR-0057 q6: the sidecar exchanges with its service-account token | 200; aud is /api; same sub | 200; subject aud ["victual-mcp","http://localhost:3000/mcp"]; exchanged aud "http://localhost:8080/api"; azp victual-mcp; same sub true | pass |
| N6b | ADR-0055 d7: an exchange that widens scope is refused | 400 invalid_scope | 400 invalid_scope | pass |
| N6c | ADR-0057 q6: another namespace's service-account token is refused | 401 | 401 invalid_client | pass |
| N6d | ADR-0057 q6: a client secret is refused for the federated-jwt sidecar | 401 | 401 invalid_client | pass |
| N6e | ADR-0055 d3: the MCP client's token carries the MCP audience | aud includes the MCP resource URI | ["victual-mcp","http://localhost:3000/mcp"] | pass |
| N7 | ADR-0055 d8: anonymous dynamic registration is refused | 403 | 403 {"error":"insufficient_scope","error_description":"invalid_client_metadata"} | pass |
| N8 | ADR-0055 d6, ADR-0058 d6: five-minute access tokens, 30-minute idle, 8-hour SSO maximum | access ttl 300; refresh ttl <= 1800; realm SSO max 28800 | access 300 s; refresh 1800 s; SSO max 28800 s (read back; not waited out) | pass |
| N9 | ADR-0058 d4: Victual's tokens carry Keycloak group membership | groups contains household in access and ID token | access ["household"]; id ["household"] | pass |
| N10a | ADR-0057 q6: the label worker gets a token with its service-account token | 200; aud is /api | 200 aud http://localhost:8080/api | pass |
| N10b | ADR-0057 q6: the renderer's token cannot act as the worker | refused | 400 invalid_client | pass |
| F1 | ADR-0058 d11: an unset secret refuses the whole configuration | exit 1 naming SCIM_CLIENT_SECRET; nothing written | exit 1; configuration refused: unset variables: SCIM_CLIENT_SECRET; export unchanged; secrets in output false | pass |
| F2 | plan 43: an unknown key is refused, so a typo is not ignored | exit 1; nothing written | exit 1; configuration refused: unknown top-level keys: realmSettings; export unchanged; secrets in output false | pass |
| F3 | plan 43: a CHANGE-ME placeholder is refused | exit 1; nothing written | exit 1; configuration refused: CHANGE-ME placeholders at: /clients/0/secret; export unchanged; secrets in output false | pass |
| F4 | plan 43: a pinned Keycloak version that differs is refused | exit 4; nothing written | exit 4; Keycloak is 26.8.1; the configuration is pinned to 26.8.0; export unchanged; secrets in output false | pass |
| F5 | plan 43: an unowned object holding a declared name is refused | exit 4 naming victual-extra; nothing written | exit 4; refused: unowned objects hold declared names (use --adopt to take them over); client victual-extra: exists and is not owned by victual-realm-config; export unchanged; secrets in output false | pass |
| F6 | plan 43: --adopt takes an unowned object over explicitly | exit 0; client stamped | exit 0; owner victual-realm-config | pass |
| F7 | plan 43: an owned object the configuration drops is deleted | exit 0; victual-extra deleted | exit 0; victual-extra deleted | pass |
| F8 | plan 43: a write Keycloak refuses stops the run with the refusal | exit 1 naming the client-policies call | exit 1; Keycloak refused a write: PUT /admin/realms/victual/client-policies/profiles -> 400 {"errorMessage":"proposed client profile contains the executor, which does not have valid provider, or has invalid configuration."}; export unchanged; secrets in output false | pass |
| F9 | plan 43: after a refused run, the next apply converges | exit 0; --check exit 0 | apply exit 0 (0 changes); check exit 0 | pass |
| F10 | ADR-0058 d6: an adapter recipe that lists a forbidden scope is refused | exit 4; nothing written | exit 4; refused: adapter recipe 2 lists forbidden client scopes: offline_access; export unchanged; secrets in output false | pass |
| F11 | ADR-0058 q1, q2: the Entra ID template applies only with its variables, idempotently, and is pruned without them | exit 0 three times; created; second run zero changes; deleted when ENTRA_TENANT_ID is unset | exits 0/0/0; created true (first broker flow victual-first-broker-login); second run 0 changes; after unset deleted | pass |
| P1 | issue 1139: after every refusal, adoption and template run, users are untouched | no difference from the snapshot taken before F1 | identical | pass |
| I1 | plan 43 comparison: partial import OVERWRITE of one client | observation | overwritten 1; client id replaced; session refresh 401 unauthorized_client; secret replaced by the export's mask or a new value | obs |
| I4 | plan 43 comparison: partial import of a flow, a client scope and a client policy | observation | response added 0, skipped 0; created {"flow":false,"scope":false,"policy":false} | obs |
| I2 | plan 43 comparison: kc.sh import --override false on an existing realm | observation | import log: already exists; accessTokenLifespan 300 (file said 1234); users 5 | obs |
| I3 | plan 43 comparison: kc.sh import --override true on an existing realm | observation | accessTokenLifespan 1234; users 5 -> 0; break-glass session refresh 401 unauthorized_client | obs |

58 cases, 0 FAIL; {"started":"2026-11-27T20:13:16.041Z","finished":"2026-11-27T20:15:40.188Z","keycloak":"26.8.1","node":"v22.23.5","base":"7fbed58"}
