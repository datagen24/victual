# Keycloak realm-configuration harness

Tests [`deploy/keycloak/`](../../deploy/keycloak/README.md) against a live Keycloak: the
reconciler's idempotency, drift repair, preservation of users and sessions, upgrade between
configuration versions and refusals, and the positive and negative cases of the policies the
realm configures. [Plan 43](../../docs/plans/43-keycloak-realm-configuration.md) states what
the results establish.

The harness deletes and rebuilds the realms `victual` and `upstream` on the instance it runs
against. It must not point at a Keycloak that holds real data.

## Run

Needs Java 21, Node.js 22, PostgreSQL 16's server binaries (`PGBIN`, default
`/usr/lib/postgresql/16/bin`) and `curl`. No container runtime is used.

```sh
cd .devtools/keycloak-config
./run.sh up                  # Keycloak 26.8.1 (tarball, checksum pinned) on PostgreSQL 16, 127.0.0.1:8180
node test.mjs                # every phase, about 3 minutes; prints the table, writes results/
node test.mjs --no-import    # without phase I, which stops and restarts Keycloak; about 20 seconds
./run.sh down                # stops both and deletes .work/ except the downloaded tarball
node --test config.test.mjs  # the offline checks CI runs; no Keycloak needed
```

`run.sh` keeps everything in the gitignored `.work/` directory. It generates the Keycloak admin
and PostgreSQL passwords into `.work/secrets.env` on first use. Every other credential in
`env.sh` and `test.mjs` is a `harness-only-*` value for an instance on 127.0.0.1.

## What stands in for what

| Real component | Stand-in |
|---|---|
| The cluster's service-account issuer | A stub in `lib.mjs` on 127.0.0.1:8199: OIDC discovery, a JWKS, and RS256 tokens shaped like projected service-account tokens |
| An upstream identity provider (Entra ID in production) | A second realm, `upstream`, on the same Keycloak, brokered over OIDC |
| The upstream's SCIM provisioning service | `fetch` calls with the `victual-scim` client's token |
| Victual's registration adapter | `deploy/keycloak/adapter.mjs` |
| Victual, the sidecar and the label workloads | Token requests made by the harness; no Victual image runs |

## Files

| File | What it is |
|---|---|
| `run.sh` | Starts and stops Keycloak and its PostgreSQL cluster, and runs `kc.sh import` for phase I |
| `env.sh` | The reconciler's environment for this instance |
| `lib.mjs` | Admin calls, the reconciler CLI, the scripted browser, SCIM calls, the service-account issuer stub |
| `test.mjs` | The cases, in phases U, M, D, N, F and I |
| `config.test.mjs` | Offline checks of the committed configuration, run by CI's `lint` job |
| `fixtures/realm-v1.json`, `fixtures/adapter-recipe-v2.json` | A synthetic earlier configuration version and the adapter recipe it pinned, for the upgrade cases |
| `results/` | The last recorded run: `results.md` (the table), `results.json`, and the reconciler's change lists for the upgrade and the drift repair |
