# Deploying Victual

The manifests for the workloads this repository ships, and what they need to run.

The boundary is [ADR-0010](../docs/adr/0010-workload-standard.md)'s: **the fork declares
what its workloads need; the operator decides where they run.** So there is a pod
manifest here with probes, limits and a security context, and there is nothing here
about ingress classes, storage classes, secret management or DNS. PostgreSQL is not
here either — it is infrastructure the fork consumes, not a workload the fork ships.

The overlays are the exception that shows the line. [`kind/`](kind/) is a test
harness, [`talos/`](talos/) is one operator's cluster, kept as a worked example, and
[`production/`](production/) renders an overlay from a values file. All three consume `k3s/`
unchanged, and everything cluster-specific lives in the overlay or the values.

**Applied and serving since 2026-09-04.** The first application is what found
[#49](https://github.com/datagen24/victual/issues/49) — two ways this manifest meant
something different under `podman kube play` than it does under Kubernetes. Both are fixed
and commented where they bit. See [plan 20](../docs/plans/20-container-infrastructure.md),
"Verification", for what the run established and what is still outstanding.

## What is here

| File | What it is |
|---|---|
| [`podman/victual.yaml`](podman/victual.yaml) | The pod: a migrate initContainer, php-fpm, nginx |
| [`k3s/victual.yaml`](k3s/victual.yaml) | The same pod as a `Deployment`, with its `Service`, `ConfigMap` and its `Secret`s, one per credential (see ["One Secret per credential"](#one-secret-per-credential)). Applied to kind on 2026-09-19 (see ["What this deployment does not yet do"](#what-this-deployment-does-not-yet-do)). `.devtools/ci/test_deploy_pod_parity.py` keeps it the same pod as the one above |
| [`k3s/victual-mcp.yaml`](k3s/victual-mcp.yaml) | The read-only MCP sidecar ([docs/mcp-interface-spec.md](../docs/mcp-interface-spec.md)): its own `Deployment` (two replicas), `Service` and `ConfigMap`. It holds no database credential and no API key |
| [`k3s/kustomization.yaml`](k3s/kustomization.yaml) | The workloads above as one kustomize base — Victual, the MCP sidecar and the label workloads — for an operator's overlay to patch |
| [`kind/`](kind/) | A test harness, not a deployment: the base plus a throwaway PostgreSQL, driven by `kind/up.sh`, which generates local-only passwords into a gitignored `kind/.secrets/` |
| [`talos/`](talos/) | One operator's overlay, kept as a worked example: the maintainer's Talos Raspberry Pi cluster, from the published images, with a Traefik Ingress, PostgreSQL on an NFS claim, Secrets from 1Password Connect and no label workers. `talos/seed-1password.sh` seeds the vault once; `talos/up.sh` applies it. Applied 2026-10-06 |
| [`production/`](production/values.example.yaml) | The way to start a deployment of your own without writing kustomize: copy `values.example.yaml` to `values.yaml` (gitignored), fill it in, and `production/deploy.sh apply`. It renders an overlay on `k3s/` for an external PostgreSQL, optional MQTT and InfluxDB, and Secrets from 1Password or inline. Server dry-run only so far; not yet applied |
| [`postgres/roles.sql`](postgres/roles.sql) | The two database roles, and what each may do |
| [`k3s/label-workers.yaml`](k3s/label-workers.yaml) | The label renderer and the label worker as `CronJob`s, in the kustomize base above. Neither holds a database credential |
| [`podman/label-workers.yaml`](podman/label-workers.yaml) | The same two workloads as `Job`s, for `podman kube play --replace` on a systemd timer |
| [`compose/label-workers.yml`](compose/label-workers.yml) | The same two workloads as profiled Compose services, for `docker compose run --rm` on a systemd timer |

The pod manifest is a Kubernetes object rather than a compose file on purpose.
`podman kube play` gives the two serving containers a shared network namespace exactly as
Kubernetes does, so `127.0.0.1:9000` means the same thing on a laptop and in the cluster,
and there is one manifest to keep true instead of two.

## The label workloads: one pair, three deployment methods

The renderer and the worker are the same two programs everywhere, and they are **three
files** because the three targets do not offer the same kinds:

| Method | File | Kind | What repeats the run |
|---|---|---|---|
| K3s / Kubernetes | [`k3s/label-workers.yaml`](k3s/label-workers.yaml) | `CronJob`, every minute | the cluster's scheduler |
| Podman Kube | [`podman/label-workers.yaml`](podman/label-workers.yaml) | `Job` | a systemd timer running `podman kube play --replace` |
| Docker Compose | [`compose/label-workers.yml`](compose/label-workers.yml) | a profiled service | a systemd timer running `docker compose run --rm` |

Each file's header carries the timer unit for its own method. Two properties are constant
across all three and are the reason the split is safe:

- The worker is **run to completion** on every target: its claim loop breaks on an empty
  queue and exits 0, never mid-attempt, so it exits exactly when it holds no lease.
- **Exactly one worker serves a printer at a time**, which is `concurrencyPolicy: Forbid`
  on Kubernetes and `Type=oneshot` on the other two.

Losing the second property is the ownership ambiguity
[ADR-0019](../docs/adr/0019-label-printers-are-master-data.md) decision item 3 removes, so
if you drive either timer from cron instead, wrap it in `flock`.

[`.devtools/ci/test_deploy_label_parity.py`](../.devtools/ci/test_deploy_label_parity.py)
is what keeps three files from becoming three workloads. It compares image, arguments, key
path, uid, read-only root, dropped capabilities, memory ceiling and tmpfs size across all
three, and allows exactly two differences: the API base, and `imagePullPolicy: Never`.

**Why podman does not receive the Kubernetes file unchanged.** Podman plays "Pods,
Deployments, DaemonSets, Jobs, and PersistentVolumeClaims" (`podman kube play --help`,
podman 6.0.2) — not CronJob — and it **skips** an unsupported kind in a multi-document
file instead of refusing it.

Measured 2026-09-20 on podman 6.0.2, macOS, against the CronJob version of the file when
it still lived in `podman/`: the Secret was created, both CronJobs were dropped, and the
command **exited 0**, leaving no renderer, no worker and a success code. The renderer had
been a CronJob since it was written, so that file had never worked; it survived because
nothing here had ever run the command.

**Why Compose does not use `restart: always`.** A binary that exits when its queue
drains, under a restart policy, is a tight restart loop with a
`POST /api/labels/register` every cycle — the same defect that made deploying the worker as
a `replicas: 1` Deployment wrong. Both Compose services therefore sit behind a `manual`
profile so `docker compose up` does not start them.

[ADR-0026](../docs/adr/0026-a-wake-signal-may-announce-label-work.md) decision 6 calls this
the "resident" topology and would give the binaries a `--wait` mode that makes the host
timer unnecessary; the maintainer intends to accept that record, but **no part of it is
implemented** and the binaries have no `--wait` today. The timer is the interim answer, and
it stays the floor under the record if it lands.

**Upgrading a deployment that applied the worker as a `Deployment`:** delete that object
first, with `kubectl delete deployment victual-label-worker`. `concurrencyPolicy: Forbid`
scopes to the CronJob's own invocations and cannot see a resident Deployment, so the two
would claim from one printer's queue together — the ownership ambiguity
[ADR-0019](../docs/adr/0019-label-printers-are-master-data.md) decision item 3 removes.
Nothing here has ever applied it, so no tree in this repository needs the step.

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
#     The passwords go in psql's environment, never on a command line (roles.sql's header).
MIGRATE_PASSWORD=victual-migrate APP_PASSWORD=victual-app \
  podman exec -i -e MIGRATE_PASSWORD -e APP_PASSWORD victual-db \
  psql -q -v ON_ERROR_STOP=1 -U postgres -d victual -v db=victual \
  < deploy/postgres/roles.sql

# 3. The pod, with the ConfigMap and Secrets it references appended to the same stream.
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
---
apiVersion: v1
kind: Secret
metadata:
  name: victual-bootstrap-admin
stringData:
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

**The first administrator's password comes from its own Secret.** A fresh database has
no `admin`/`admin` any more: the migrate container seeds the `admin` account with
`VICTUAL_BOOTSTRAP_ADMIN_PASSWORD`, read once, on the run that creates the database. It sits in
`victual-bootstrap-admin`, which only the migrate container names, because the migrate
container is the only one that seeds; the serving containers never need it. It is not a key
in `victual-db-migrate` because a database Secret holds the database credential and nothing
else (see ["One Secret per credential"](#one-secret-per-credential)).

The pod requires the Secret, so a pod cannot start before it exists and seed a password
nobody chose. Leave the value blank and the first migration generates a password instead, prints it once to
the migrate container's log, and the account has to change it at first login; until it
does, the API answers `403` to everything except the change itself. For a deployment that
is the better default, since nothing then has to hold the password afterwards:

```sh
kubectl logs deploy/victual -c migrate --all-pods=true | grep 'generated password'   # Kubernetes 1.30+
podman logs victual-migrate 2>&1 | grep 'generated password'          # podman kube play
```

Read it before anything replaces that pod. The line is in the log of the migrate container
that created the database and nowhere else; a later pod's migrate run finds the database
already there and prints nothing. If it is gone before anybody logged in, the way back is an
empty database and a first migration with the key set.

Either way the key does nothing after the first run: removing it, or changing it, does not
touch an account that already exists. [The Manual's first-login
section](../docs/manual/getting-started.md#the-first-login) has the rest.

**The ConfigMap and the three Secrets must be in the stream, and each Secret must be a
Kubernetes `Secret`.** The `app` container also names `victual-mqtt` and `victual-influxdb`,
as optional; leave them out unless MQTT or InfluxDB is enabled. Two plausible-looking alternatives both fail:

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

`up.sh` runs every `kubectl` command against the context `kind-$KIND_CLUSTER` and never the
current one, which on a machine that also manages a real cluster may well be that cluster.
`KUBE_CONTEXT` names another. Pass `--context kind-kind-cluster` to commands you type
yourself for the same reason.

There is no `admin`/`admin`. `up.sh` generates `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` into
`deploy/kind/.secrets/bootstrap-admin.env`, beside the database passwords, and the migrate container
seeds the `admin` account with it on the run that creates the database — log in as `admin`
with that value, then create a key under *Manage API keys*. That key is what an MCP client
presents as `Authorization: Bearer …`. The value is read once: a `.secrets/` older than the
database it was generated beside, or one edited afterwards, does not change the account. A
`.secrets/` from before the bootstrap password had a file of its own kept it in
`migrate.env`; `up.sh` moves it to `bootstrap-admin.env` with its value.

A database first migrated without the key (before `up.sh` wrote it) has a generated password
instead, printed once in the migrate container's log and forced to change at first login:

```sh
kubectl -n victual logs deploy/victual -c migrate --all-pods=true | grep 'generated password'
```

Only the pod that created the database has that line. Until the password is changed the
account can open only the change-password form, and the API answers
`403` to everything but its save — an API key included.

The overlay is also the pattern for a real cluster. Put `deploy/k3s` (or this repository at
a pinned ref) in `resources`, then patch the ConfigMap's database host and base URL, the
Secrets and the image references. Keep the Secrets out of anything committed.

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
| `VICTUAL_DB_USER`, `VICTUAL_DB_PASSWORD` | From a Secret, **one Secret per workload** — see ["Two database roles"](#two-database-roles). Never in the ConfigMap |
| `VICTUAL_BASE_URL` | What the ingress publishes |
| `VICTUAL_MODE=production` | Any other value disables authentication and generates demo data |

`VICTUAL_DATAPATH` (`/data`) and `VICTUAL_VIEWCACHE_PATH` (the baked, read-only cache in
the image's store path) are set by the image and should be left alone.

### Two database roles

`victual-migrate` and `victual-app` do not share a database credential
([ADR-0010](../docs/adr/0010-workload-standard.md) property 3, plan 20 verification 8).
[`postgres/roles.sql`](postgres/roles.sql) creates both, once, run as a PostgreSQL superuser. It switches off statement logging for its session first, so the passwords it sets are not recorded by the server:

| Role | Held by | Can |
|---|---|---|
| `victual_migrate` | the `migrate` initContainer, in the Secret `victual-db-migrate` | Everything: it owns the schema, so it is the only role that can `CREATE`, `ALTER` and `DROP` |
| `victual_app` | the `app` container, in the Secret `victual-db-app` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` on tables and `USAGE` on sequences, including on tables a later migration creates. Not `CREATE`, `ALTER`, `DROP`, `TRUNCATE` or `TRIGGER` |
| — | the `web` container | Nothing; it holds no database variable at all |

Run the script before the first migration or after it: it is repeatable. Three consequences
follow:

- **`VICTUAL_MIGRATE_ON_ROOT_REQUEST` cannot be turned on** in a pod running as
  `victual_app`, since migrating in a request needs DDL.
- A database already populated by another role has to be handed to `victual_migrate`
  first (`REASSIGN OWNED BY <old> TO victual_migrate`) or its tables stay owned by someone
  the migrations cannot alter.
- `bin/victual-db-import`, which `TRUNCATE`s, belongs with the migrate credential, never
  the app's.

The application had to change for this to work. `PostgresDialect::OnConnected()` used to run
`CREATE TABLE IF NOT EXISTS` on every connection, and PostgreSQL checks `CREATE` on the schema
before it checks whether the table exists, so a role with no `CREATE` could not connect.

Three security-context settings each have a failure that does not say what it is:

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
`SIGTERM` dropped the connection. php-fpm answered **both** by exiting within about a second and
resetting the request, so nginx returned 502. That contradicts the sentence above for the app
tier — "SIGQUIT is php-fpm's graceful stop" is what the documentation says and not what this
deployment showed for a request blocked on the database. A request not blocked on the database
was not measured.

`lifecycle.stopSignal` is alpha (Kubernetes 1.33, feature gate `ContainerStopSignals`) and
needs `spec.os.name`; on a cluster without the gate the API server drops the field.

**Observed 2026-09-19 on kind v1.37 with default gates:** the applied
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

### One Secret per credential

Each database Secret holds `VICTUAL_DB_USER` and `VICTUAL_DB_PASSWORD` and nothing else. Every
other secret value has a Secret of its own, named only by the container that reads it:

| Secret (and 1Password item) | Holds | Named by |
|---|---|---|
| `victual-db-migrate` | `VICTUAL_DB_USER`, `VICTUAL_DB_PASSWORD` | the `migrate` initContainer |
| `victual-bootstrap-admin` | `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` | the `migrate` initContainer |
| `victual-db-app` | `VICTUAL_DB_USER`, `VICTUAL_DB_PASSWORD` | the `app` container |
| `victual-mqtt` | `VICTUAL_MQTT_PASSWORD` | the `app` container, optionally |
| `victual-influxdb` | `VICTUAL_INFLUXDB_TOKEN` | the `app` container, optionally |

The reason is password rotation ([plan 36](../docs/plans/36-database-credential-rotation.md#item-layout)).
Rotating a database password rewrites its 1Password item whole, and `op` cannot make that
write conditional on nobody else having changed the item. With nothing else in the item,
nothing else can be lost. The base references `victual-mqtt` and `victual-influxdb` as
optional, so a deployment without MQTT or InfluxDB creates neither; `production/render.py`
makes them required when the feature is on, so the pod cannot start without the password and
silently publish nothing.

**Moving an existing deployment to this layout.** Deployments created before 2026-10-08 kept
the bootstrap password in `victual-db-migrate` and, where used, the MQTT password and
InfluxDB token in `victual-db-app`. Move them in this order, which never leaves a container
without a value it reads:

1. **Create the new items, copying the current values.** For 1Password, run
   `talos/seed-1password.sh` on the Talos overlay: it creates `victual-bootstrap-admin` from the
   value in `victual-db-migrate`. Elsewhere, copy each field the same way, on a pipe so the value is on no
   command line:

   ```sh
   VAULT=your-vault; FROM=victual-db-app; TO=victual-mqtt; FIELD=VICTUAL_MQTT_PASSWORD
   op item get "$FROM" --vault "$VAULT" --format json --reveal \
     | python3 -c 'import json, sys; to, field = sys.argv[1:]
   value = {f["label"]: f.get("value") for f in json.load(sys.stdin)["fields"]}[field]
   json.dump({"title": to, "category": "SECURE_NOTE", "fields": [
       {"id": field, "label": field, "type": "CONCEALED", "value": value}]}, sys.stdout)' "$TO" "$FIELD" \
     | op item create --vault "$VAULT" >/dev/null
   ```

   Repeat with `FROM=victual-db-migrate TO=victual-bootstrap-admin
   FIELD=VICTUAL_BOOTSTRAP_ADMIN_PASSWORD`, and `TO=victual-influxdb FIELD=VICTUAL_INFLUXDB_TOKEN`
   if InfluxDB is on. The bootstrap password does nothing to a database that already exists,
   but the pod requires its Secret, and copying it keeps the record of what the first login
   was. With `secrets.source: inline` there is nothing to create: the values stay in
   `values.yaml`.
2. **Update the Secret references.** Apply this release's manifests: `talos/up.sh`, or for
   `production/`, add `bootstrapAdminItem`, `mqttItem` and `influxdbItem` to
   `secrets.onepassword` in `values.yaml` (`values.example.yaml` has them) and run
   `production/deploy.sh apply`. `render.py` refuses a 1Password `values.yaml` without
   `bootstrapAdminItem`. The pod template changes, so this starts a rollout; the new pod
   waits until every Secret it requires exists.
3. **Wait for the operator to write the new Secrets.** Each must exist and hold the same
   value as the field it was copied from. Compare digests, not values:

   ```sh
   kubectl -n victual get secret victual-bootstrap-admin -o jsonpath='{.data.VICTUAL_BOOTSTRAP_ADMIN_PASSWORD}' | shasum
   kubectl -n victual get secret victual-db-migrate -o jsonpath='{.data.VICTUAL_BOOTSTRAP_ADMIN_PASSWORD}' | shasum
   ```

4. **Roll and verify.** `kubectl -n victual rollout status deployment/victual` reaches Ready;
   an existing account logs in; with MQTT on, `bin/victual-publish-state` in the `app`
   container succeeds and Home Assistant's entities update
   ([Home Assistant and MQTT](../docs/manual/operator/home-assistant-mqtt.md)); with InfluxDB
   on, a booking writes a point to the bucket.
5. **Only then remove the moved fields from the database items:**

   ```sh
   op item edit victual-db-migrate --vault "$VAULT" 'VICTUAL_BOOTSTRAP_ADMIN_PASSWORD[delete]'
   op item edit victual-db-app --vault "$VAULT" 'VICTUAL_MQTT_PASSWORD[delete]' 'VICTUAL_INFLUXDB_TOKEN[delete]'
   ```

   Wait for the operator to rewrite `victual-db-migrate` and `victual-db-app` without them.
   The pod reads the moved values from their own Secrets by then, so no restart is needed.
   An inline deployment's apply leaves the old keys in the database Secrets, because
   `stringData` is write-only and `kubectl apply` cannot see them to remove; delete each with
   `kubectl -n victual patch secret victual-db-migrate --type=json
   -p '[{"op":"remove","path":"/data/VICTUAL_BOOTSTRAP_ADMIN_PASSWORD"}]'`.

`kind/up.sh` makes the same move for `deploy/kind/.secrets/` by itself.

## What this deployment does not yet do

Stated plainly because the gap is the point of tracking it:

- ~~**The label images declare a working directory they do not contain.**~~ **Fixed
  2026-09-20, in the change that found it.** `podman kube play` accepted the new `Job`
  kinds and created both pods, and then both containers failed with
  `starting container …: workdir "/app" does not exist on container`.
  [`nix/images/lib.nix`](../nix/images/lib.nix)'s `commonConfig` set `WorkingDir = "/app"`
  for every image while `scaffold` creates only `/tmp`. `app.nix` and `migrate.nix` set
  their own `appRoot` and `web.nix` overrides it, so the default applied to exactly the
  three images built from a single binary on no base image — the two label images and
  **the MCP sidecar** — none of which contain an `/app`. It was invisible on Kubernetes,
  where the CRI creates a missing working directory, which is why the sidecar had run on
  kind since 2026-09-19 carrying it. `WorkingDir` is now out of `commonConfig` and stated
  by each image; the three that inherited it say `/tmp`, the one directory they contain,
  as `web.nix` already did. Rebuilt and verified: both containers start, and the binaries
  reach `POST /api/labels/register` and `POST /api/labels/render/claim`, failing only on
  connection refused because no Victual was running.

- ~~**The k3s manifest has never been applied to a cluster.**~~ **Applied: kind
  2026-09-19, a real cluster 2026-10-06.** `deploy/kind/up.sh` loads the four images, applies
  `deploy/k3s` through the `deploy/kind` overlay, and waits for every rollout. What it
  established: the migrate initContainer, the credential split and all three probes behave
  under a real kubelet as they did under podman; the MCP sidecar serves every tool from two
  replicas; and `lifecycle.stopSignal` is dropped on v1.37 (see "Signals"). The real
  cluster is the maintainer's Talos one (v1.37, arm64 Raspberry Pi, Traefik, NFS CSI),
  through [`talos/`](talos/kustomization.yaml) from the published GHCR images. It showed the
  same probes, credential split and dropped `stopSignal`. PostgreSQL's data also survived a
  pod restart on NFS (plan 20, piece 4). An apply that reaches a printer is still plan 25's
  verification 12, and it is what keeps
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
