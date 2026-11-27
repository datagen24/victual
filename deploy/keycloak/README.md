# Keycloak realm configuration

Victual's Keycloak realm, as code, and the reconciler that applies it. Keycloak is the only
token issuer in OAuth mode
([ADR-0055](../../docs/adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md),
[ADR-0058](../../docs/adr/0058-upstream-identity-providers-federate-into-keycloak-and-provision-through-scim.md),
both Proposed). [Plan 43](../../docs/plans/43-keycloak-realm-configuration.md) records why the
configuration is applied this way, the alternatives, and the test results.

Pinned to **Keycloak 26.8.1**. The reconciler refuses another server version unless it is run
with `--allow-version-mismatch`.

## What is here

| File | What it is |
|---|---|
| [`realm.json`](realm.json) | The realm: settings, roles, client scopes, flows, identity providers, clients, client policies, registration policies and the user-profile attribute. Holds no secret: every `${NAME}` comes from the environment |
| [`identity-providers/entra-id.json`](identity-providers/entra-id.json) | The Entra ID identity provider as a template. Included only when `ENTRA_TENANT_ID` is set. No tenant has been tested |
| [`adapter-recipe.json`](adapter-recipe.json) | The registration adapter's pinned recipe (ADR-0055 decision 8 and question 18), version 3 |
| [`adapter.mjs`](adapter.mjs) | The recipe's reference implementation: register, deregister and reconcile the clients and grant scopes the adapter generates |
| [`reconcile.mjs`](reconcile.mjs) | The reconciler, with `lib/` (admin REST client, configuration loader, flow builder) |
| [`example.env`](example.env) | Every variable the configuration and the reconciler read, set to `CHANGE-ME` |

The tests are in [`.devtools/keycloak-config/`](../../.devtools/keycloak-config/README.md).

## Run

Node.js 22 and network access to Keycloak's admin API are needed. Nothing is installed: the
reconciler uses Node's standard library only.

```sh
set -a; . ./my-keycloak.env; set +a     # a filled-in copy of example.env, kept out of Git
node deploy/keycloak/reconcile.mjs --check   # report what an apply would change; writes nothing
node deploy/keycloak/reconcile.mjs           # apply, then read back and check again
```

The output is one JSON report on stdout. Progress lines go to stderr, with secrets replaced by
`***`.

| Exit | Meaning |
|---|---|
| 0 | The realm matches the configuration (after an apply, the read-back pass found nothing) |
| 1 | The configuration was refused before any write (unset variable, `CHANGE-ME`, unknown key), or Keycloak refused a write; the report names the call |
| 2 | `--check` found drift |
| 3 | An apply finished but the read-back still differs; the report lists what was not applied |
| 4 | Refused before any write: an unowned object holds a declared name, the Keycloak version differs from the pin, or the adapter recipe lists a scope the realm forbids |

Every run starts with a check pass, so a refusal with code 4 writes nothing. `--adopt` takes
over an unowned object that holds a declared name, which is how a realm configured by hand is
brought under the configuration.

## What the reconciler changes, and what it never touches

It manages the objects `realm.json` declares and stamps each one as its own:
`victual.owner = victual-realm-config` and `victual.config-version` in the attributes of
clients, client scopes and roles; `victual.owner` in an identity provider's config and in a
user-profile attribute's annotations; a ` [managed: victual-realm-config]` suffix on a flow's
description. It deletes an owned object the configuration no longer declares. It never deletes
an object it does not own.

It never creates, changes or deletes users, credentials, sessions, groups or group membership.
Reapplying the configuration therefore keeps every user and credential created after
installation (issue 1139). Plan 43's cases U5, D4 and P1 test this.

Some rules apply to objects nobody owns, because the configuration states them realm-wide:

- `forbiddenClientScopes`: no client holds `offline_access`, built-in clients included
  (ADR-0058 decision 6). Keycloak answers 403 to any change of `realm-management` and
  `security-admin-console`, so those two are listed in `forbiddenClientScopesExempt` with the
  reason. Offline tokens stay refused at the admin console because no user holds the
  `offline_access` role (case N2c).
- `defaultRoles.absent` and `realmDefaultClientScopes.absent`: `offline_access` is removed
  from the default roles and the realm's default client scopes.
- `flowBindings` and `clientRegistrationPolicies`: the realm's flow bindings and the two
  anonymous registration policies Keycloak creates are set to the declared values.

Objects the registration adapter generates are stamped `victual.owner =
victual-registration-adapter` and reconciled to the pinned recipe by `adapter.mjs`. The realm
reconciler leaves their grant scopes out of the sidecar's scope list when it compares it.

A secret Keycloak masks on read (an identity provider's client secret) is compared through a
SHA-256 digest stored beside it. Client secrets are compared through the admin API's
client-secret endpoint.

## Password login

ADR-0058 decision 7 limits Keycloak's password login to bootstrap and break-glass accounts.
The configuration enforces it with the realm role `victual-local-login`:

- the browser flow `victual-browser` keeps the password form, and a conditional subflow denies
  every account without the role after the password is checked;
- the direct-grant flow `victual-direct-grant` does the same, because Keycloak's built-in
  `admin-cli` client has the password grant on in every realm;
- every Victual client has the password grant off.

Keycloak cannot enforce the rest by configuration:

- Which account is a recovery account is the role assignment, made by hand. Any principal
  holding `manage-users` can grant the role or set a password. The SCIM sender and the session
  poller hold `manage-users`, so their credentials can create a break-glass account.
  Keycloak's fine-grained admin permissions might narrow this; that is untested.
- The password of an ordinary account is still verified, and failures still count towards
  brute-force lockout, before the account is denied (case N3f).
- Victual's own rule, that OAuth mode allows password login only for the bootstrap
  administrator and `ADMIN` accounts, is Victual code; Keycloak has no part in it.

## Decisions the configuration does not make

- **Back-channel logout** (ADR-0058 open question 4) is not configured: Victual's client
  declares an empty `backchannel.logout.url`, so a URL set by hand is reported as drift and
  removed. Victual has no logout endpoint yet.
- **The upstream claim that keys the federated identity** (ADR-0058 open question 1): the Entra
  ID template keeps Keycloak's default, the OIDC `sub` claim.
- **Offline tokens for a device-flow client** (ADR-0058 open question 3): refused for every
  client.
- **Leaf scopes** (ADR-0055 decision 5): only the four bundles are declared. The leaf list
  depends on ADR-0057's catalog ([plan 42](../../docs/plans/42-permission-catalog.md)).

## Chart integration

Not wired yet. The all-in-one and distributed values files (ADR-0055 answer 27) both run the
reconciler, as ADR-0058 decision 8's setup Job, against the Keycloak they use. Plan 43 describes
the follow-up.
