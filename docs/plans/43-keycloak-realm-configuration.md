# 43. Keycloak realm configuration as code

Version Victual's Keycloak realm with the deployment configuration, apply it through Keycloak's
admin REST API with a reconciler in this repository, and test both the reconciler and the
policies the realm configures. This is the evidence for
[ADR-0055](../adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
acceptance prerequisite 8 on Keycloak, and for
[ADR-0058](../adr/0058-upstream-identity-providers-federate-into-keycloak-and-provision-through-scim.md)
decision 11.

Status: draft, 2026-11-27. The configuration, the reconciler and the harness are in the tree
(`deploy/keycloak/`, `.devtools/keycloak-config/`). The Helm chart runs the reconciler as an
opt-in hook Job from its own image; the chart does not run Keycloak itself yet (see
[Executed](#executed)). ADR-0055 and ADR-0058 stay Proposed. Nothing here accepts or amends a
record.

## Problem and outcome

ADR-0055 prerequisite 8 accepts provider configuration when it uses a supported provider
interface, lives with the deployment configuration under version control, and has positive
and negative tests (question 11). Configuration that imports provider internals or writes the
provider's database does not qualify (question 15). For objects the registration adapter
generates at run time, the evidence is the pinned adapter and templates, an owner and
recipe-version record on each object, and a repeatable reconciliation and upgrade (question 18).
ADR-0058 decision 11 adds a constraint from
[issue 1139](https://github.com/datagen24/victual/issues/1139): reapplying the configuration
must keep every user and credential created since installation.

The 2026-10-28 evidence under prerequisite 8 covered the adapter's generated objects
(`.devtools/spikes/oauth-keycloak/`, cases K4a to K4g). The rest of the realm existed only as
spike setup scripts that delete and rebuild the realm, which fails issue 1139's constraint.

The outcome is one realm configuration, `deploy/keycloak/realm.json`, holding everything
ADR-0055, ADR-0057 questions 6 to 8 and ADR-0058 decisions 3 to 7 and 9 describe as Keycloak
configuration. A reconciler applies it, and a harness tests it against Keycloak 26.8.1.

## Requirements

The mechanism must:

1. use only Keycloak's supported interfaces (question 15);
2. read its input from files in Git, with secrets from the environment and never in Git
   (ADR-0058 decision 11);
3. be idempotent: a second apply changes nothing;
4. repair drift made through the admin console or API;
5. leave users, credentials, sessions, groups and SCIM-provisioned users intact on reapply
   (issue 1139);
6. upgrade a realm from one configuration version to the next, including removing what the
   new version drops;
7. refuse, or report, anything it cannot apply;
8. reconcile the adapter's generated objects to the pinned recipe (question 18).

## What the realm contains

Each item names its source clause and the harness cases that test it. Case results are in
[Measurements](#measurements); `results.md` holds the full table.

| Item | Source | Positive | Negative |
|---|---|---|---|
| Access tokens 300 s, SSO idle 1800 s, SSO maximum 28800 s, refresh rotation with reuse revocation | ADR-0055 d6, ADR-0058 d6 | N8 | U3 (36000 replaced on upgrade), D1 (3600 repaired) |
| No `offline_access`: removed from every client, the realm's default scopes and the default roles; offline session maximum also 28800 s | ADR-0058 d6, open question 3 | — | N2a to N2d, U4, F10 |
| Four bundle scopes `victual:read`, `victual:shopping`, `victual:stock`, `victual:catalog` | ADR-0055 d5 | N6a | N6b |
| `victual-mcp-audience`, `victual-api-audience` client scopes | ADR-0055 d3, d7 | N6a, N6e, N10a | N6b to N6d |
| `victual-groups`: Keycloak group membership as `groups` in access, ID and introspection tokens | ADR-0058 d4 | N9 | D1 (deleted mapper repaired) |
| `victual`: confidential, authorization code with S256 PKCE only, no back-channel logout URL | ADR-0058 d2, open question 4 | N3b, N9 | N3e, N5a, N5b, D1 |
| `/api` resource client, every flow off | ADR-0055 d7 | N6a | — |
| `victual-mcp`: standard token exchange, Kubernetes service-account authentication | ADR-0055 d7, ADR-0057 q6 | N6a | N6b to N6d |
| `victual-label-worker`, `victual-label-renderer`: client credentials, Kubernetes service-account authentication | ADR-0057 q6, ADR-0058 d9 | N10a | N10b |
| `victual-registration-adapter`: `manage-clients`, `view-clients` | ADR-0055 d8 | — | — |
| `victual-session-poller`: `view-events`, `view-users`, `manage-users` | ADR-0058 d6 | — | — |
| `victual-scim`: client credentials, `manage-users`, `view-users`, `query-groups`, SCIM audience mapper | ADR-0058 d5 | N1a | N1b, N1c, D1 |
| User-profile attribute `upstreamId`, annotated `kc.scim.schema.attribute: externalId` | ADR-0058 d5 | N1d | D1 |
| Identity provider `kubernetes` | ADR-0057 q6 | N6a, N10a | N6c |
| Client policies `victual-downscope-all` and `victual-dcr-anonymous` | ADR-0055 d7, d8 | N6a | N6b, D1 |
| Anonymous registration policies: no trusted host, empty allowed-scope list | ADR-0055 d8, prerequisite 11 (K2g, K2h) | — | N7, D1 |
| Flow `victual-first-broker-login`: one step, "Create User If Unique" | ADR-0058 d3 | B1 | N4a |
| Flows `victual-browser` and `victual-direct-grant`: password login for `victual-local-login` accounts only | ADR-0058 d7 | N3b, N3d | N3a, N3c |
| Entra ID identity provider, template only | ADR-0058 d1, open questions 1 and 2 | F11 | F11 (pruned when unset) |
| Adapter recipe version 3 | ADR-0055 d8, q18 | U4 | F10 |

The two service-account clients without cases (`victual-registration-adapter`,
`victual-session-poller`) are configured and reconciled. Nothing in the harness yet calls the
admin API with their tokens, because neither the adapter nor the poller exists as a workload.

Leaf scopes (ADR-0055 decision 5) are not declared. Their names come from ADR-0057's catalog,
which [plan 42](42-permission-catalog.md) lists and no migration has seeded.

## Mechanisms compared

Measured on 2026-11-27 against Keycloak 26.8.1 where a case is named; the rest is from the
sources named.

| Condition | `kc.sh import` / `--import-realm` | Admin REST partial import | keycloak-config-cli | Reconciler in this repository |
|---|---|---|---|---|
| Supported interface | Yes, the server's own command | Yes | Yes, through the admin REST API | Yes, the admin REST API only |
| Keeps users on reapply (issue 1139) | `--override true` deleted all 5 users (I3). `--override false` and `--import-realm` skip an existing realm (I2) | Only with SKIP; OVERWRITE replaced the client and its id, and the exported secret mask replaced its secret (I1) | Per its documentation, users are managed only when listed in the file | Yes: U5, D4, P1 |
| Repairs drift | No: an existing realm is skipped (I2) | Only for the kinds it accepts, and only by deleting and recreating | Not measured | Yes: 16 kinds, D1 to D3 |
| Covers flows, client policies, client scopes, user profile | Yes, on first import | No: a flow, a client scope and a client policy were silently not created (I4) | Per its documentation, yes | Yes |
| Prunes only what it owns | No | No | Per its documentation, remote state in realm attributes records what it created | Yes: F7; unowned objects refused (F5) |
| Generated objects (question 18) | No | No | No; the adapter's recipe needs its own code either way | The same code path, `adapter.mjs` (U4) |
| Refuses or reports what it cannot apply | No | Partly: unknown kinds are ignored (I4) | Per its documentation, undefined variables are errors | Yes: F1 to F5, F8, F10; read-back pass (exit 3) |
| Runtime and image (ADR-0013) | In the Keycloak image; needs Keycloak stopped | None | A 116 MB Java 21 jar, or the adorsys images on Docker Hub and quay.io built on `eclipse-temurin:21-jre` | Node.js standard library; no dependency |

keycloak-config-cli, from its repository README, `LICENSE.txt`, `docs/MANAGED.md`,
`docs/FEATURES.md`, `Dockerfile` and releases page, read on 2026-11-27 (Maven Central's
listing could not be fetched from this environment):

- Licence Apache-2.0, copyright adorsys GmbH & Co. KG. Maintained by adorsys, which also sells
  support. 14 open issues and 13 open pull requests on 2026-11-27.
- Release v6.6.0 on 2026-11-24, after v6.5.1 (2026-05-22), v6.5.0 (2026-03-12) and v6.4.1
  (2026-01-28). The README lists 26.8.1 among the tested Keycloak versions.
- v6.6.0 carries `keycloak-config-cli-26.8.1.jar`, SHA-256
  `41fc3a12de28279a0eb4ac9b100ef3df80b0e772d9843bfa87877ef929d3593d`, which matched the
  release's `.sha256` file on 2026-11-27.
- Variable substitution is off by default and uses `$(env:NAME)`. `import.remote-state.enabled`
  (default on) limits deletion to objects it created; `import.managed.<kind>` chooses `full` or
  `no-delete`.

keycloak-config-cli was not run. Running the downloaded jar in the cloud container was refused
by the session's permission policy, so its behaviour on drift, on reapply and on users is taken
from its documentation and is unmeasured. Under ADR-0013 it would need its own image: the
release jar fetched by hash with a Nix JRE, or a Maven build in Nix. Either is a second Java
runtime beside Keycloak's, for a job that runs a few times per release.

### Decision

Use the reconciler in this repository, `deploy/keycloak/reconcile.mjs`.

- It meets requirements 1 to 8, and each is tested (see [Measurements](#measurements)).
- `kc.sh import` and partial import fail requirement 5 or requirement 4, on measurement
  (I1 to I4).
- keycloak-config-cli may meet requirements 1 to 7, unmeasured here. It does not meet
  requirement 8, so the adapter's reconcile code is needed in any case. With the reconciler,
  the realm and the generated objects share one ownership model and one test harness, and no
  second JVM image is added.

The cost is about 900 lines of Node.js the project maintains (`deploy/keycloak/`). Keycloak's admin API is the
contract it depends on, and a Keycloak upgrade is the event that can break it. The
configuration pins the Keycloak version and the reconciler refuses another, so an upgrade
means changing the pin and running the harness. If that cost grows, keycloak-config-cli is
the alternative to measure first: the harness's U, M, D, F and P cases can run against it
unchanged in intent.

## Design

### Ownership

Every object the configuration declares is stamped `victual.owner = victual-realm-config`, in
the attributes of clients, client scopes and roles, in the config of identity providers and in
the annotations of user-profile attributes. Flows carry a description suffix, because Keycloak
stores no attributes on a flow. The reconciler prunes only stamped objects. It refuses to take
over an unstamped object that holds a declared name unless `--adopt` is given (F5, F6).

The adapter's objects carry `victual.owner = victual-registration-adapter` and
`victual.recipe` (question 18). The realm reconciler calls the adapter's reconcile step, and
ignores the adapter's grant scopes when it compares the sidecar's optional-scope list.

Some rules apply to objects nobody owns, because the configuration states them realm-wide:
the forbidden `offline_access` scope on every client, the default roles, the realm's default
client scopes, the flow bindings and the two anonymous registration policies Keycloak creates.
Keycloak answers 403 to a change of `realm-management` or `security-admin-console`, so the
configuration exempts those two with the reason written beside them, and the report lists the
exemptions on every run.

### One run

1. Load `realm.json`, expand `$include` entries, substitute `${NAME}` from the environment.
   Refuse an unknown top-level key, an unset variable or a `CHANGE-ME` before any admin call
   (F1 to F3).
2. Refuse a Keycloak version other than the pinned one (F4).
3. Run every step in check mode. Refuse when an unowned object holds a declared name (F5).
4. Run every step again, writing. A write Keycloak refuses stops the run and names the call
   (F8); the next run converges (F9).
5. Run every step in check mode again. Anything still different is reported as not applied,
   with exit 3. This catches a setting Keycloak accepts and then ignores.

The steps run in dependency order. Realm settings, roles, client scopes, and flows with their
bindings come first. Identity providers and clients follow, each client with its mappers, scope
lists, secret, service-account roles and scope mappings. Then come the adapter's objects,
forbidden scopes, client policies, registration policies and the user profile. Pruning and the
configuration version recorded on the realm come last.

A flow whose tree differs is rebuilt under `<alias>--next`, every binding moves to the copy,
and the original is rebuilt and the bindings moved back. Each binding names a complete flow at
every point, so a reapply never leaves the browser flow bound to Keycloak's built-in flow.

`fullScopeAllowed` is off on every Victual client, so a service account's roles reach its token
only through the client's scope mappings. The reconciler keeps the scope mappings equal to the
declared service-account roles. The first harness run found this: the SCIM sender's token held
no roles and SCIM answered 403. The fixture had not checked SCIM's response status either;
both are fixed, and drift case D1 covers the scope mapping.

### Password login

ADR-0058 decision 7 asks that Keycloak's password login be limited to bootstrap and break-glass
accounts. Keycloak can enforce this by configuration: the browser and direct-grant flows deny,
after the password check, every account without the realm role `victual-local-login`. Both
flows need it, because Keycloak's built-in `admin-cli` client has the password grant on in
every realm (N3a to N3d).

Keycloak cannot enforce three things:

- which account is a recovery account: the role is assigned by hand, and any principal holding
  `manage-users` can assign it or set a password. The SCIM sender and the session poller hold
  `manage-users`, so either credential can create a break-glass account. Keycloak's
  fine-grained admin permissions might narrow this; untested (open question 3);
- that an ordinary account's password is never checked: it is checked before the deny step,
  and failures count towards brute-force lockout (N3f);
- Victual's own OAuth-mode rule for the bootstrap administrator and `ADMIN` accounts, which is
  Victual code.

### Layout

| Path | Contents |
|---|---|
| `deploy/keycloak/realm.json` | The realm configuration, version 2 |
| `deploy/keycloak/identity-providers/entra-id.json` | The Entra ID template, included when `ENTRA_TENANT_ID` is set |
| `deploy/keycloak/adapter-recipe.json`, `adapter.mjs` | The adapter's pinned recipe and its reference implementation |
| `deploy/keycloak/reconcile.mjs`, `lib/` | The reconciler |
| `deploy/keycloak/example.env` | Every variable, as `CHANGE-ME` |
| `.devtools/keycloak-config/` | The harness, the offline checks and the recorded results |

CI's `lint` job runs the offline checks (`config.test.mjs`): the configuration loads with every
variable set, is refused with `example.env` as shipped, references only scopes that exist, and
lists no forbidden scope, password grant, implicit grant or literal secret. The live harness
does not run in CI; it downloads a 174 MB Keycloak release and takes about three minutes.

### Chart integration

Follow-up, not in this change. ADR-0055 answer 27 sets one chart with two values files.

- **All-in-one values file:** the chart runs Keycloak, then the reconciler as ADR-0058 decision
  8's on-demand setup Job, with the bootstrap admin credential from a Kubernetes Secret. The
  Job's environment maps chart values to the variables in `example.env`; every `*_SECRET`
  variable is a `secretKeyRef`. `KUBERNETES_SA_ISSUER` is the cluster's service-account issuer,
  and the workloads' projected tokens use `expirationSeconds: 600` (ADR-0057 answer 8).
- **Distributed values file:** the same Job runs against the operator's Keycloak, with an admin
  client credential the operator provides. An operator who manages that realm another way does
  not run the Job, and `--check` reports how the realm differs from Victual's configuration.
- **Image:** Keycloak's own Nix-built image and its licence review are [plan 44](44-keycloak-licence-and-image.md)'s. The reconciler Job needs Node.js and `deploy/keycloak/`. Under ADR-0013 that is a Nix-built
  image, or the reconciler is ported to PHP and runs from the migrate image that ADR-0058
  decision 8 names (open question 2).
- **Upgrades:** a chart upgrade that changes `realm.json` raises `configVersion`, and the
  operator runs the Job again. The realm records `victual.config-version` and
  `victual.config-digest`, so a check can tell which version a realm was last brought to.

Verification for that change: install the all-in-one values file on kind, run the Job twice
(the second run reports zero changes), and run the harness's N cases against the installed
realm with the real sidecar and label-worker images.

## Measurements

Run on 2026-11-27 in a Linux x86-64 cloud container. Keycloak 26.8.1 from the release tarball
(SHA-256 `4e2740b181faa2ea623a1c2ecf00855935311e546046e2f857615a47ed24cbe8`), `start-dev` on
PostgreSQL 16, OpenJDK 21.0.13, Node.js 22.23.5. Docker's daemon was not running in the
container and quay.io was not used. Working copy: branch `claude/opus5_keycloak-realm-config-p8`;
`results/results.json` records the commit each run started from. Reproduce with:

```sh
cd .devtools/keycloak-config && ./run.sh up && node test.mjs
```

The recorded run started at 20:25 UTC from commit `41fd416`: all 53 pass or fail cases passed,
and five cases are observations. Phases U to F then ran three more times on the same instance
with no FAIL. The full table is
[`results.md`](../../.devtools/keycloak-config/results/results.md).

An earlier run failed case F5 once: the export digest moved although nothing was written. The
moved field was a client's `defaultClientScopes`, which Keycloak exported in two orders over 30
exports with no write in between. The digest now sorts string lists, which Keycloak stores as
sets, and names the part of the export that moved when a case finds one.

| Requirement | Cases | Result |
|---|---|---|
| 3. Idempotent | M1, M2, U2, D3, F11 | A second and third apply made zero changes; Keycloak's partial export hashed the same before and after |
| 4. Repairs drift | D1 to D3 | 16 drifts made through the admin API, including a disabled deny step, a deleted client policy, a wildcard redirect, `offline_access` back in the default roles, and the MCP audience on the anonymous allowed-scope list (the ADR-0055 K2h hazard). `--check` named all 16 and wrote nothing; one apply repaired them |
| 5. Keeps users and sessions | U5, U6, D4, D5, P1 | Five users (a break-glass account, two ordinary accounts, a brokered user with a federated identity, a SCIM user with its upstream id and group) kept their ids, credential ids, links, groups and session counts through the upgrade, the drift repair and every refusal case. A session from before the upgrade kept refreshing |
| 6. Upgrades | U0 to U4 | Version 1 to version 2 made 35 changes in 2.5 s, including the read-back pass: a removed client and client scope deleted, the SSO maximum changed, the browser flow rebuilt with its gate, a new flow bound, and the adapter's client moved from recipe 2 to recipe 3 |
| 7. Refuses or reports | F1 to F10 | Unset variable, unknown key, `CHANGE-ME`, version pin, unowned object, forbidden recipe: refused with nothing written. A write Keycloak refused stopped the run and named the call. No secret value appeared in any output |
| 8. Generated objects | U4, F10 | The adapter's objects were brought to the pinned recipe; a recipe listing a forbidden scope was refused |
| Policies | N1a to N10b, B1 | Every positive case succeeded and every negative case was refused, as listed in [What the realm contains](#what-the-realm-contains) |
| Comparison | I1 to I4 | Observations; see [Mechanisms compared](#mechanisms-compared) |

Limits on these results:

- One Keycloak node in `start-dev` mode. The two-replica production-mode evidence is
  ADR-0055 prerequisite 2's, from the 2026-10-29 spike.
- The 8-hour SSO maximum was read back, not waited out.
- No Victual, sidecar or label image ran. The Kubernetes issuer, the upstream provider and the
  SCIM sender are stand-ins (the harness README lists them).
- No Entra ID tenant: the template's endpoints were never contacted (ADR-0058 prerequisites 2
  to 4).
- The upgrade is between two configuration versions on one Keycloak version. A Keycloak upgrade
  is not tested.

## Open questions

1. **Does Victual's client register a back-channel logout URL?** This is ADR-0058 open question
   4. The configuration declares an empty `backchannel.logout.url`, so a URL set by hand is
   reported as drift and removed. If the answer is yes, the URL becomes a variable in a new
   configuration version, and Victual needs the logout endpoint first.
2. **Where does the reconciler run?** ADR-0058 decision 8 puts first-run setup in the migrate
   image, which has PHP and no Node.js. The options are a Nix-built Node.js Job image, or a
   PHP port of the reconciler in the migrate image. The harness tests behaviour through the
   CLI, so it can test either.

   > **Response:** datagen24, 2026-11-27: a new Nix-built image, `victual-keycloak-setup`.
   > The alternatives offered were adding `deploy/keycloak/` to the MCP sidecar's image and
   > porting the reconciler to PHP for the migrate image.
3. **Should `manage-users` be narrowed for the SCIM sender and the session poller?** Either
   credential can grant `victual-local-login` and set a password today. Keycloak's
   fine-grained admin permissions might restrict them to users and groups; untested.
4. **How long does Keycloak keep admin events?** The poller resumes from the last event it
   handled (ADR-0058 open question 5). The realm sets no admin-event expiry, so events are kept
   until deleted. A retention period belongs in the configuration once the poll interval is
   decided.
5. **Does a scheduled `--check` belong in the chart?** It would report drift between upgrades,
   but needs a read-only admin credential that lives in the cluster, which decision 8's
   on-demand Job avoids.

## Executed

### Chart integration: the setup Job, 2026-11-27

What shipped:

- **Image:** `victual-keycloak-setup` (`nix/keycloak-setup.nix`, `nix/images/keycloak-setup.nix`),
  per the answer to open question 2. It holds `deploy/keycloak/`'s reconciler, realm
  configuration and adapter recipe, and runs on the MCP sidecar's stripped Node 24 runtime. Its
  entrypoint is `node reconcile.mjs`, as uid 65532 in `/tmp`. Two new `nix/checks.nix` checks:
  `keycloak-setup-image-has-no-shell`, and `keycloak-setup-refuses-an-empty-environment`, which
  runs the packaged entrypoint with no variables and expects exit 1 naming them. The `nix`
  workflow writes it as a seventh release stream, and `release.yml` publishes it with the other
  six. Its path filter now includes `deploy/keycloak/`.
- **Chart:** `templates/keycloak-setup.yaml`, off by default. With `keycloakSetup.enabled` the
  Job runs as a `post-install,post-upgrade` hook with `backoffLimit: 0`. Its delete policy is
  `before-hook-creation`, so a failed run's log stays readable. `keycloakSetup.checkOnly` adds
  `--check`, and exit 2 on drift fails the helm command. The trigger was the maintainer's choice
  on 2026-11-27: an opt-in hook, turned on for the run that should apply the realm.
- **Values:** `url`, `redirectUri` and `kubernetesIssuer` are required when the Job is on.
  `apiAudience` defaults to `<baseUrl>/api`, `postLogoutRedirectUri` to `<baseUrl>/`, and
  `mcpAudience` to `https://<mcp.ingress.host>/mcp`; without a sidecar Ingress the template
  refuses an empty `mcpAudience`. `VICTUAL_NAMESPACE` is the release's namespace. The schema
  refuses `CHANGE-ME`, a malformed URL, and an Entra ID tenant without a client id.

Departures from [Chart integration](#chart-integration):

- The chart does not run Keycloak. The Job targets whatever Keycloak `keycloakSetup.url` names,
  so it serves the distributed values file now. The all-in-one values file needs Keycloak as a
  chart component first.
- The two Secrets are read with `envFrom`, not one `secretKeyRef` per variable. The admin
  Secret holds either a client credential or the bootstrap admin's username and password, and
  `envFrom` lets the operator choose without a chart value.
- The chart never renders either Secret, whatever `secrets.source` says. The admin credential
  therefore exists only while the operator keeps the Secret, as ADR-0058 decision 8 intends.

Verification, 2026-11-27, on branch `claude/opus5_keycloak-setup-job` in a Linux x86-64 cloud
container:

- `nix build .#image-keycloak-setup` and the two new checks built, with `nixpkgs` and the label
  inputs overridden to local clones at their locked revisions, because the environment's proxy
  does not serve GitHub archive downloads. `nix flake check --no-build` evaluated every check.
  The image's config reads `User 65532:65532`, `WorkingDir /tmp`, and the entrypoint above. Its
  uncompressed stream is 195 MB.
- The image's entrypoint was run from its store paths as uid 65532, with the environment
  `helm template` rendered for the Job, against the harness's Keycloak 26.8.1. No container
  runtime was available, so the read-only root filesystem was not exercised. The first apply
  made 39 changes and the second none; `--check` exited 0. After an `accessTokenLifespan`
  change, `--check` exited 2, an apply repaired it, and `--check` exited 0 again. A label-worker
  token issued afterwards carried the chart's derived `/api` audience.
- `python3 -m unittest discover -s .devtools/ci`: 123 tests passed with Helm 4.3.1 from the
  pinned nixpkgs. CI pins 4.3.0; `get.helm.sh` was not reachable. This includes nine new
  `KeycloakSetupTest` cases, the matrix entries in `ci/inline-all-features.yaml` and
  `ci/existing-secrets.yaml`, and `render_k3s.py --check`.

Remaining:

- Keycloak as a chart component, for the all-in-one values file (ADR-0055 answer 27; plan 44
  has its image).
- An install on kind with the published images. The Job has not run in a cluster.
- The two Secrets through `secrets.source`'s 1Password mode, if an operator wants them managed
  outside `kubectl`.
- [ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md) decision 5 (Proposed) names
  six images; the release now publishes seven.
