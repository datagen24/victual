# Spike: Keycloak's inbound SCIM API for upstream group changes

Throwaway. On 2026-11-27 the maintainer answered
[ADR-0056](../../../docs/adr/0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md)
question 12: a membership change received through SCIM logs that user out on receipt, and
otherwise the session lifetime bounds a stale group. Question 15 asks which component receives
SCIM. This spike checks whether Keycloak can receive it, and what a SCIM removal does to a live
session.

"pass" and "FAIL" compare against answer 12 or the record's stated rules. "obs" records behaviour
no clause decides. A FAIL is a result: no expectation was weakened to make a case pass.

Keycloak **26.8.1** from the release tarball (`start-dev`, embedded H2), OpenJDK 21.0.13,
Python 3, run on 2026-11-27 in a Linux x86-64 cloud container. Keycloak's resident memory after
the run was 570 MB. A Python client stands in for the upstream's SCIM provisioning service.
Entra ID was not available.

## Run

```bash
cd .devtools/spikes/oauth-keycloak-scim
./run.sh up          # downloads the tarball once, starts Keycloak on 127.0.0.1:8180
python3 setup.py     # the realm, three clients and a user-profile attribute
python3 probe.py     # the cases; prints the table and writes results.json (about 5 s)
./run.sh down        # stops Keycloak and deletes its data directory
```

## Shape under test

- **Realm `scim`** with `scimApiEnabled: true`, admin events with details on. The API is at
  `/realms/scim/scim/v2`.
- **`scim-caller`:** a confidential client, client-credentials grant, service account holding
  `realm-management` `manage-users`, `view-users` and `query-groups`. An audience mapper adds the
  realm's SCIM base URL, `http://127.0.0.1:8180/realms/scim/scim/v2`; the endpoint refuses a token
  without it. This is the client an upstream's provisioning service would hold.
- **`scim-reader`** (`view-users` only) and **`scim-noaud`** (`manage-users`, no audience mapper)
  for the refusals.
- **`victual`:** a public client with the password grant for the probe's logins only. Its group
  membership mapper puts the user's Keycloak groups into the access token as `groups`. The
  brokering spike used an `upstream_groups` user attribute instead; SCIM writes Keycloak groups,
  so the mapper here reads membership.
- **User profile:** an attribute `upstreamId` annotated `kc.scim.schema.attribute: externalId`.

## Results

Run 2026-11-27, `python3 probe.py`:

| Case | Result | What happened |
|---|---|---|
| F0 | pass | `SCIM_API` is a `DEFAULT` feature, enabled without `--features`. `SSF` is `EXPERIMENTAL` and off. |
| F1 | obs | ServiceProviderConfig: PATCH and filter supported; bulk, ETag, sort and change-password not. |
| M0 | obs | Creating a group with `members` is refused, 400 "Managing members on updates are not supported". Members are added with PATCH. |
| M1 | obs | A user created without `active` is created disabled. A provisioner must send `active: true`. |
| M2 | pass | PATCH `add members` returns 200; the next login's token carries the group. |
| M3 | pass | PATCH `remove members[value eq "<id>"]` returns 200; Keycloak's membership is empty within 74 ms. |
| M4 | **FAIL** | The user's session is still there after the removal. Answer 12 wants it ended on receipt. |
| M5 | pass | The next refresh succeeds and its token has no `groups` claim. |
| M6 | obs | The access token issued before the removal still names the group until it expires. |
| M7 | pass | PATCH `active: false` disables the user. The sessions stay listed. |
| M8 | pass | A refresh after deactivation is refused, `invalid_grant`. |
| L1 | pass | The removal is an admin event, `DELETE GROUP_MEMBERSHIP`, path `scim/v2/Groups/<group>/<user>`, readable with `resourceTypes` and `operationTypes` filters. |
| L2 | pass | `POST /admin/realms/scim/users/<id>/logout` returns 204 and the user has no sessions. |
| L3 | pass | The old refresh token is refused, `invalid_grant`, 90 ms after the logout call. |
| N1 | pass | A `view-users` caller can list users. |
| N2 | pass | The same caller creating a user gets 403. |
| N3 | pass | A token from another realm gets 401 "Bearer token required". |
| N4 | pass | An end user's token from a public client gets 403 "Public client not allowed". |
| N5 | pass | A `manage-users` token without the SCIM audience gets 401 "Invalid token audience". |
| X1 | obs | Without a user-profile mapping, `externalId` is accepted and dropped. |
| X2 | pass | With `upstreamId` annotated `kc.scim.schema.attribute: externalId`, the value is stored and a SCIM filter on `externalId` finds the user. |

## What this shows

- **Keycloak can receive SCIM.** The API is a supported feature in 26.8.1. It is on per realm and
  authenticates an OAuth bearer token from a confidential client with the right audience and
  roles (F0, N1 to N5).
- **A SCIM removal reaches tokens at the next refresh.** Keycloak computes the group claim from
  its own membership at each issue, so the stale window is the access-token lifetime plus the
  permitted skew, six minutes (M3, M5, M6; ADR-0055 answer 24). The brokered path in the
  [upstream spike](../oauth-keycloak-upstream/) waits for the next upstream sign-in instead (U5).
- **It does not log the user out (M4).** Keycloak 26.8.1 has no event listener that calls an HTTP
  endpoint, and its workflow steps include none that ends sessions. A poller that reads
  `GROUP_MEMBERSHIP` admin events and calls the admin logout does end them (L1 to L3). Its poll
  interval adds to the delay. Victual's own browser session is separate from Keycloak's and is
  not ended by either.
- **Deactivation through SCIM blocks refresh at once (M8).**
- **Linking needs `externalId` mapped (X1, X2).** A SCIM-created user is a local Keycloak user.
  Linking it to the person's brokered upstream identity needs the upstream's stable id, and
  Keycloak keeps `externalId` only through a user-profile annotation.

## Not tested

Entra ID's provisioning service and the credential it can present; linking a SCIM-created user
to a brokered Entra login; a poller running as a Victual job; Keycloak with PostgreSQL;
persistence across restarts; the Victual images.
