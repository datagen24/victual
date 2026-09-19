# Deploying Victual

The manifests for the workloads this repository ships, and what they need to run.

The boundary is [ADR-0010](../docs/adr/0010-workload-standard.md)'s: **the fork declares
what its workloads need; the operator decides where they run.** So there is a pod
manifest here with probes, limits and a security context, and there is nothing here
about ingress classes, storage classes, secret management or DNS. PostgreSQL is not
here either — it is infrastructure the fork consumes, not a workload the fork ships.

**Applied and serving since 2026-09-04.** The first application is what found
[#49](https://github.com/datagen24/victual/issues/49) — two ways this manifest meant
something different under `podman kube play` than it does under Kubernetes. Both are fixed
and commented where they bit. See [plan 20](../docs/plans/20-container-infrastructure.md),
"Verification", for what the run established and what is still outstanding.

## What is here

| File | What it is |
|---|---|
| [`podman/victual.yaml`](podman/victual.yaml) | The pod: a migrate initContainer, php-fpm, nginx |
| [`k3s/victual.yaml`](k3s/victual.yaml) | The same pod as a `Deployment`, with its `Service`, `ConfigMap` and the two `Secret`s. Applied to kind on 2026-09-19 (see below). `.devtools/ci/test_deploy_pod_parity.py` keeps it the same pod as the one above |
| [`k3s/victual-mcp.yaml`](k3s/victual-mcp.yaml) | The read-only MCP sidecar ([docs/mcp-interface-spec.md](../docs/mcp-interface-spec.md)): its own `Deployment` (two replicas), `Service` and `ConfigMap`. It holds no database credential and no API key |
| [`k3s/kustomization.yaml`](k3s/kustomization.yaml) | The two workloads above as one kustomize base, for an operator's overlay to patch |
| [`kind/`](kind/) | A test harness, not a deployment: the base plus a throwaway PostgreSQL, driven by `kind/up.sh`, which generates local-only passwords into a gitignored `kind/.secrets/` |
| [`postgres/roles.sql`](postgres/roles.sql) | The two database roles, and what each may do |
| [`podman/label-workers.yaml`](podman/label-workers.yaml) | The label renderer (a CronJob) and the label worker (a Deployment); neither holds a database credential |

The pod manifest is a Kubernetes object rather than
a compose file on purpose: `podman kube play` gives the two serving containers a shared
network namespace exactly as Kubernetes does, so `127.0.0.1:9000` means the same thing on
a laptop and in the cluster, and there is one manifest to keep true instead of two.

## Bootstrapping on a Mac with podman

```sh
# 1. Build and load the images. On macOS this needs a Linux builder — see nix/README.md.
nix run .#load

# 2. A throwaway PostgreSQL. Not a deployment artifact; a database to point at.
podman run -d --name victual-db \
  -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=admin -e POSTGRES_DB=victual \
  -p 5432:5432 postgres:16

# 2b. The two roles the pod connects as. The superuser above only bootstraps; nothing in
#     the pod ever holds it. See "Two database roles" below. Wait over TCP first: the
#     image's init-time server listens on its socket only, so a socket check passes early.
until podman exec victual-db pg_isready -q -h 127.0.0.1 -U postgres -d victual; do sleep 1; done
podman exec -i victual-db psql -q -v ON_ERROR_STOP=1 -U postgres -d victual \
  -v db=victual -v migrate_password=victual-migrate -v app_password=victual-app \
  < deploy/postgres/roles.sql

# 3. The pod, with the ConfigMap and Secret it references appended to the same stream.
{ cat deploy/podman/victual.yaml; cat <<'YAML'
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: victual-config
data:
  VICTUAL_MODE: production
  VICTUAL_DB_DRIVER: pgsql
  VICTUAL_DB_HOST: host.containers.internal
  VICTUAL_DB_PORT: "5432"
  VICTUAL_DB_NAME: victual
  VICTUAL_FILE_STORAGE: database
  VICTUAL_BASE_URL: http://localhost:8080
---
apiVersion: v1
kind: Secret
metadata:
  name: victual-db-migrate
stringData:
  VICTUAL_DB_USER: victual_migrate
  VICTUAL_DB_PASSWORD: victual-migrate
  VICTUAL_BOOTSTRAP_ADMIN_PASSWORD: victual-admin
---
apiVersion: v1
kind: Secret
metadata:
  name: victual-db-app
stringData:
  VICTUAL_DB_USER: victual_app
  VICTUAL_DB_PASSWORD: victual-app
YAML
} | podman kube play -

# 4. http://localhost:8080/ — log in as admin / victual-admin (the bootstrap password above).
```

**The passwords in this walkthrough are local-only.** `victual_migrate` owns the schema, and
the Secrets above write both passwords in the clear into objects a `podman kube play` leaves
on the machine. Use them against the throwaway PostgreSQL in step 2 and nothing else; for a
persistent or shared database pick your own and keep them out of anything committed.

**The first administrator's password comes from the migrate Secret.** A fresh database has
no `admin`/`admin` any more: the migrate container seeds the `admin` account with
`VICTUAL_BOOTSTRAP_ADMIN_PASSWORD`, read once, on the run that creates the database. It sits in
`victual-db-migrate` and not in `victual-db-app` because the migrate container is the only
one that seeds — the serving containers never need it, the same split as the database
credentials. Leave the key out and the first migration generates a password instead, prints
it once to the migrate container's log, and the account has to change it at first login;
until it does, the API answers `403` to everything except the change itself. For a
deployment that is the better default, since nothing then has to hold the password
afterwards:

```sh
kubectl logs deploy/victual -c migrate | grep 'generated password'   # Kubernetes
podman logs victual-migrate 2>&1 | grep 'generated password'          # podman kube play
```

Read it before anything replaces that pod. The line is in the log of the migrate container
that created the database and nowhere else; a later pod's migrate run finds the database
already there and prints nothing. If it is gone before anybody logged in, the way back is an
empty database and a first migration with the key set.

Either way the key does nothing after the first run: removing it, or changing it, does not
touch an account that already exists. [The Manual's first-login
section](../docs/manual/getting-started.md#the-first-login) has the rest.

**The ConfigMap and both Secrets must be in the stream, and each Secret must be a
Kubernetes `Secret`.** This is worth stating plainly because two plausible-looking
alternatives both fail:

- `podman kube play --secret …` takes a *podman* secret (`podman secret create`), which
  is not the same object. Passing one fails with
  `secret victual-db-app is not valid JSON/YAML: cannot unmarshal string into Go value of type v1.Secret`.
- `--configmap /dev/stdin` can only supply the ConfigMap, so the manifest is still
  rejected with `no secret with name or id "victual-db-app"`.

Concatenating the documents, as above, is the form that works. `VICTUAL_FILE_STORAGE`
belongs in the ConfigMap rather than being optional: this pod mounts nothing writable, so
the default `filesystem` backend would fail on the first upload.

## Trying it on kind

`deploy/kind/up.sh` is the Kubernetes counterpart of the podman walkthrough above. It
needs a kind cluster (`KIND_CLUSTER`, default `kind-cluster`) and the four images in
podman (`nix/build-in-podman.sh images`):

```sh
deploy/kind/up.sh
kubectl -n victual port-forward svc/victual 8080:8080
kubectl -n victual port-forward svc/victual-mcp 3000:3000
deploy/kind/up.sh down      # the database goes with the namespace
```

There is no `admin`/`admin`. `up.sh` generates `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` into
`deploy/kind/.secrets/migrate.env` with the database passwords, and the migrate container
seeds the `admin` account with it on the run that creates the database — log in as `admin`
with that value, then create a key under *Manage API keys*. That key is what an MCP client
presents as `Authorization: Bearer …`. The value is read once: a `.secrets/` older than the
database it was generated beside, or one edited afterwards, does not change the account.

A database first migrated without the key (before `up.sh` wrote it) has a generated password
instead, printed once in the migrate container's log and forced to change at first login:

kubectl -n victual logs deploy/victual -c migrate --all-pods=true | grep 'generated password'

Only the pod that created the database has that line. Until the password is changed the
account can open only the change-password form, and the API answers
`403` to everything but its save — an API key included.

The overlay is also the pattern for a real cluster. Put `deploy/k3s` (or this repository at
a pinned ref) in `resources`, then patch the ConfigMap's database host and base URL, the
two Secrets and the image references. Keep the Secrets out of anything committed.

## What a running instance needs

**Configuration is environment variables.** `config-dist.php`'s `Setting()` resolves in
this order: a file in `$VICTUAL_DATAPATH/settingoverrides`, then a `VICTUAL_`-prefixed
environment variable, then the shipped default. So a container needs no `config.php` at
all, and since 2026-09-04 it does not have one: `app.php` loads the file when it is there
and carries on when it is not.

That is a change from what this file said until then. The images used to seed a near-empty
`config.php` into `$VICTUAL_DATAPATH` from `/etc/victual/config.php`, through an
entrypoint, purely because `PrerequisiteChecker` refused to start without the file
existing. The seed, the entrypoint, the writable data directory they needed and the
`pcntl` extension the entrypoint used are all gone together — see issue #49, which is what
that arrangement cost. A deployment that would rather supply a real `config.php` still
can: mount one at `$VICTUAL_DATAPATH/config.php`, which is an empty read-only directory in
the image.

The minimum for a PostgreSQL deployment:

| Variable | Why |
|---|---|
| `VICTUAL_DB_DRIVER=pgsql` | The only value there is, since ADR-0008's retirement; set explicitly so the pod's configuration says what it runs on rather than relying on a default |
| `VICTUAL_DB_HOST`, `_PORT`, `_NAME` | Connection, from the ConfigMap |
| `VICTUAL_DB_USER`, `VICTUAL_DB_PASSWORD` | From a Secret, **one Secret per workload** — see below. Never in the ConfigMap |
| `VICTUAL_BASE_URL` | What the ingress publishes |
| `VICTUAL_MODE=production` | Any other value disables authentication and generates demo data |

`VICTUAL_DATAPATH` (`/data`) and `VICTUAL_VIEWCACHE_PATH` (the baked, read-only cache in
the image's store path) are set by the image and should be left alone.

### Two database roles

`victual-migrate` and `victual-app` do not share a database credential
([ADR-0010](../docs/adr/0010-workload-standard.md) property 3, plan 20 verification 8).
[`postgres/roles.sql`](postgres/roles.sql) creates both, once, as a role that can create roles:

| Role | Held by | Can |
|---|---|---|
| `victual_migrate` | the `migrate` initContainer, in the Secret `victual-db-migrate` | Everything: it owns the schema, so it is the only role that can `CREATE`, `ALTER` and `DROP` |
| `victual_app` | the `app` container, in the Secret `victual-db-app` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` on tables and `USAGE` on sequences, including on tables a later migration creates. Not `CREATE`, `ALTER`, `DROP`, `TRUNCATE` or `TRIGGER` |
| — | the `web` container | Nothing; it holds no database variable at all |

Run the script before the first migration or after it: it is repeatable. Three consequences
worth knowing before they surprise anyone: **`VICTUAL_MIGRATE_ON_ROOT_REQUEST` cannot be turned
on** in a pod running as `victual_app`, since migrating in a request needs DDL; a database
already populated by another role has to be handed to `victual_migrate` first (`REASSIGN OWNED
BY <old> TO victual_migrate`) or its tables stay owned by someone the migrations cannot alter;
and `bin/victual-db-import`, which `TRUNCATE`s, belongs with the migrate credential, never the
app's.

The application had to change for this to work. `PostgresDialect::OnConnected()` used to run
`CREATE TABLE IF NOT EXISTS` on every connection, and PostgreSQL checks `CREATE` on the schema
before it checks whether the table exists, so a role with no `CREATE` could not connect.

**Three security-context settings are load-bearing**, and each has a failure that does
not say what it is:

- `runAsNonRoot: true` with `runAsUser: 65532` — the images already declare this in
  their OCI config, but a cluster policy that reads the manifest rather than the image
  wants it said here.
- `fsGroup: 65532` — an `emptyDir` volume is otherwise `root:root 0755` and uid 65532
  cannot write to it, which is the single most common reason a correctly built non-root
  image fails to start. **`podman kube play` does not honour it** (issue #49), so this pod
  is built not to need it: nothing writable is mounted except the two `/tmp` tmpfs
  volumes. It stays in the manifest because a real cluster does honour it and the day
  something writable comes back is not the day to rediscover this.
- `readOnlyRootFilesystem: true` — the images are built to run this way. If it has to be
  turned off to get a green pod, something wrote where it should not have, and that is a
  finding rather than a workaround.

**Signals.** The images set `StopSignal=SIGQUIT`, which is php-fpm's graceful stop and
nginx's graceful shutdown. Kubernetes ignores the image's `StopSignal` and sends
`SIGTERM` unless the container spec sets `lifecycle.stopSignal`, so the manifest says it
again. On a cluster too old for that field the containers get `SIGTERM`, which both
treat as "stop now" — acceptable for stateless request handlers, but not the same thing,
and worth knowing before wondering where a truncated request went.

**Measured, 2026-09-18, on podman rather than a cluster** (plan 20's Executed section has the
method): with a request held inside PostgreSQL, `SIGQUIT` to nginx let the response finish and
`SIGTERM` dropped the connection; php-fpm answered **both** by exiting within about a second and
resetting the request, so nginx returned 502. That contradicts the sentence above for the app
tier — "SIGQUIT is php-fpm's graceful stop" is what the documentation says and not what this
deployment showed for a request blocked on the database. A request not blocked on the database
was not measured. `lifecycle.stopSignal` is alpha (Kubernetes 1.33, feature gate
`ContainerStopSignals`) and needs `spec.os.name`; on a cluster without the gate the API server
drops the field. **Observed 2026-09-19 on kind v1.37 with default gates:** the applied
Deployment came back with no `lifecycle` on either container, so SIGTERM is what this pod
gets on a stock cluster. To get SIGQUIT, enable the gate on the API server and the kubelet.
The manifest does not assume either way.

**Migrations run before anything serves.** The `migrate` initContainer runs
`bin/victual-migrate`, which is a no-op against an up-to-date database, takes a
`pg_advisory_lock` so concurrent runs are safe, and exits non-zero on failure — so a bad
migration keeps the pod from starting rather than letting it start and answer 503 to
everything. Since plan 10, `SchemaVersionMiddleware` is what makes that 503 rather than an
unpredictable failure, and it is also why the readiness probe below reports the gate.

**`VICTUAL_BASE_PATH` is a build input, not a runtime setting.** The baked route cache is
named after a fingerprint of `routes.php` and the base path, so an image built with one and
deployed under another does not misroute — Slim refuses to start, naming the cache
directory. Serving under a sub-path is a rebuild (`nix/app.nix`'s `basePath`), not an
environment variable.

**The app container's probe is an `exec`, and it must stay one.** php-fpm binds
`127.0.0.1:9000` so that nothing outside the pod's containers can reach it, and the kubelet
resolves a TCP probe's target to the *pod IP* — so a `tcpSocket` probe fails against a
healthy pool and restarts it on every failure threshold. Setting the probe's `host` to
`127.0.0.1` does not help either: that names the node's loopback. The probe runs
`/opt/victual/healthcheck` inside the container instead. What it cannot see — a pool
accepting connections whose workers are all wedged — is covered by the web container's
readiness probe, which renders `/login` through Blade.

## What this deployment does not yet do

Stated plainly because the gap is the point of tracking it:

- ~~**The k3s manifest has never been applied to a cluster.**~~ **Applied to kind,
  2026-09-19; not yet to k3s.** `deploy/kind/up.sh` loads the four images, applies
  `deploy/k3s` through the `deploy/kind` overlay, and waits for every rollout. What it
  established: the migrate initContainer, the credential split and all three probes behave
  under a real kubelet as they did under podman; the MCP sidecar serves every tool from two
  replicas; and `lifecycle.stopSignal` is dropped on v1.37 (see "Signals"). A K3S apply
  that reaches a printer is still plan 25's verification 12, and it is what keeps
  [issue 93](https://github.com/datagen24/victual/issues/93) open.

- ~~**One writable mount remains, and it is not the view cache.**~~ **Done, 2026-09-04.**
  It named `PrerequisiteChecker::checkForConfigFile()` as the only thing keeping `/data`,
  and said removing it "deletes the entrypoint and this mount together". That is what
  happened, and issue #49 is why it happened when it did rather than eventually: podman
  does not honour `fsGroup`, so the mount the check required could not be written to on a
  laptop and the pod would not start. The two writable mounts left are `/tmp`, one per
  serving container.
- ~~**File uploads still land on the filesystem.**~~ **Done.** Plan 01 landed, and this
  deployment sets `VICTUAL_FILE_STORAGE=database` rather than treating it as an option —
  with nothing writable mounted, the `filesystem` backend has nowhere to write. A
  deployment that wants files on a volume must mount one and say so.
- ~~**Two production images exist in the tree.**~~ **Resolved, 2026-09-04.**
  [ADR-0013](../docs/adr/0013-nix-built-container-images.md) was accepted and its open
  question 5 answered by removing the `Dockerfile`'s `production` target. These are the
  production images; the `Dockerfile` builds the development and CI image and nothing
  else.
