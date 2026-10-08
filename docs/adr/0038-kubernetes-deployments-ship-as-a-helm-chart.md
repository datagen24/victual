# ADR-0038: Kubernetes deployments ship as a Helm chart, and `deploy/k3s/` becomes its rendered output

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-10-07. Answers to open questions 1, 2 and 3 recorded the same day,
  and decisions 2, 8, 9 and 10 reconciled with them. Prerequisites 1 to 3 met 2026-10-08,
  with two rulings that day reconciled into decision 6, and 4 and 7 met on kind the same
  day, as was 6's kind half (see "Prerequisite status")
- **Referenced by:** [plan 20](../plans/20-container-infrastructure.md) (piece 4, the k3s
  manifests), [deploy/](../../deploy/README.md); would supersede in part the answer to
  [ADR-0010](0010-workload-standard.md) open question 1; extends
  [ADR-0030](0030-released-images-are-published-to-ghcr.md)'s release workflow

## Context

Victual's Kubernetes deployment is a kustomize base, [`deploy/k3s/`](../../deploy/k3s/),
plus three ways of turning it into something a cluster runs:

| Directory | What it does | Consumes `deploy/k3s/` by |
|---|---|---|
| [`deploy/kind/`](../../deploy/kind/) | Test harness: a throwaway PostgreSQL and local images | `resources: [../k3s]` |
| [`deploy/talos/`](../../deploy/talos/) | The maintainer's cluster, kept as a worked example | Naming individual files, built with `--load-restrictor LoadRestrictionsNone` |
| [`deploy/production/`](https://github.com/datagen24/victual/tree/947407c3/deploy/production/) | A values file rendered into an overlay by `render.py`, applied by `deploy.sh` | Generating a kustomization that lists the base's files and patches them |

`deploy/production/` is a values-to-manifests renderer written for this repository.
[`render.py`](https://github.com/datagen24/victual/blob/947407c3/deploy/production/render.py) reads
[`values.example.yaml`](https://github.com/datagen24/victual/blob/947407c3/deploy/production/values.example.yaml). It refuses a missing,
mistyped or `CHANGE-ME` value by field name, then generates a namespace, an Ingress, a
ConfigMap patch and either `OnePasswordItem` resources or inline Secret patches.
`deploy.sh` applies the result and waits for the rollout. This is the job Helm does. Helm
also keeps release history, offers `helm diff` and `helm rollback`, and packages a
versioned artifact. Victual's renderer has none of these.

Updates require manual edits. The commit that carried the 0.3.0 version bump, `541076c9` on
2026-10-07, changed the image tag by hand in eight files under `deploy/`: the two k3s
manifests, the k3s label workers, the podman pod and label workers, the Compose label
workers, `kind/up.sh` and `values.example.yaml`. `version.json` is already the authority
for the version. [`nix/overlay.nix`](../../nix/overlay.nix) reads it, and
`release.yml` refuses a tag that disagrees with it. No deploy file reads it.

Three checks and one overlay read `deploy/k3s/` as plain YAML:

- [`check_deploy_manifest.py`](../../.devtools/ci/check_deploy_manifest.py) enforces
  ADR-0010's manifest-level properties. It runs `rglob("*.yaml")` under `deploy/` and calls
  `yaml.safe_load_all` on each file without a fallback. A Helm template containing
  `{{ … }}` is not valid YAML, so placing one under `deploy/` with a `.yaml` extension
  makes the check fail on a parse error.
- [`test_deploy_pod_parity.py`](../../.devtools/ci/test_deploy_pod_parity.py) checks that
  the podman pod and the k3s Deployment describe the same pod. It also checks that only
  the migrate initContainer can read the migrate role's Secret.
- [`test_deploy_label_parity.py`](../../.devtools/ci/test_deploy_label_parity.py) does the
  same for the label workloads across k3s, podman and Compose.
- The talos overlay references `../k3s/victual.yaml` and `../k3s/victual-mcp.yaml` by
  path.

Any packaging change must preserve these checks or name a replacement for each one.

Two runtime properties constrain what a rollback can do:

- Migrations run in the `migrate` initContainer under a PostgreSQL advisory lock
  (`bin/victual-migrate`). Two pods that start together run their migrations one after the
  other.
- [`SchemaVersionMiddleware`](../../middleware/SchemaVersionMiddleware.php) answers 503
  when the database is behind the code *or ahead of it*. Its docblock names the second
  case as "the rollback case".

## Options considered

1. **Keep `render.py` and the kustomize overlays (no change).** Costs nothing now. The
   renderer stays a single-purpose tool that nobody outside this repository knows, with no
   release history and no rollback. Each version bump still edits every deploy file by hand.
2. **A Kubernetes operator** (a controller and a `Victual` custom resource). An operator
   is worth its cost when a controller must keep reconciling state, such as database
   failover, backups, or several instances per cluster. Victual has none of these.
   ADR-0010 makes every shipped workload stateless. PostgreSQL is infrastructure the fork
   consumes, not one it ships. The label workloads are already `CronJob`s. An operator
   would add a controller image, custom resource definitions, cluster RBAC and an upgrade
   path for the controller itself. In exchange, it would run steps that Helm hooks or an
   initContainer already run.
3. **Plain kustomize overlays for every deployment**, as `deploy/talos/` is written.
   Kustomize has no validated values file. A deployment written this way learns about a
   missing database host when the pod fails to connect, not when it is rendered.
4. **A Helm chart as the single source, with `deploy/k3s/` rendered from it** (this
   decision).
5. **A Helm chart beside a hand-maintained `deploy/k3s/`.** This creates two sources for
   one pod, the drift `test_deploy_pod_parity.py` exists to prevent between podman and
   k3s, now with a third copy that no test reads.

## Decision

1. **The Kubernetes deployment is a Helm chart at `deploy/helm/victual/`.** It declares
   the Victual Deployment, the MCP sidecar, the two label `CronJob`s, their Service,
   ConfigMap and Secrets, as `deploy/k3s/` does today. Each optional workload has an
   `enabled` value. PostgreSQL stays outside the chart, as ADR-0010 requires.
2. **`deploy/k3s/` becomes generated output and stays committed.** A script renders the
   chart with `helm template` and a committed values file
   (`deploy/helm/victual/ci/k3s-values.yaml`) into `deploy/k3s/`, with `--no-hooks`;
   `deploy/k3s/kustomization.yaml` stays hand-written. The `lint` job renders it again and
   fails if the result differs from the committed files. `kubectl apply -k deploy/k3s`
   keeps working, with today's behaviour, for anyone who does not use Helm.
   `check_deploy_manifest.py` and both parity tests keep reading the same files. The kind
   and talos deployments stop reading them (decision 8).
3. **Chart templates are excluded from `check_deploy_manifest.py`'s glob, and the
   chart's rendered output is checked instead.** The `lint` job renders the chart once
   per entry in a values matrix under `deploy/helm/victual/ci/`. The matrix enables each
   optional workload and each secrets mode at least once. Each rendering goes through
   `check_deploy_manifest.py`. The check covers what can ship, not only the default
   rendering.
4. **One version for the chart, the application and the images.** The chart's `version`
   and `appVersion` equal `version.json`'s `Version`. Image tags default to
   `.Chart.AppVersion`. A version bump edits `version.json` and `Chart.yaml`, and
   `release.yml`'s tag check extends to `Chart.yaml`. The podman and Compose files keep
   their own image references; the label parity test already compares those tags.
5. **A release tag publishes the chart to GHCR as an OCI artifact,**
   `oci://ghcr.io/datagen24/charts/victual`, from the publish job that already holds
   `packages: write` under ADR-0030 decision 5. No other job gains that permission. As
   ADR-0030 decision 7 requires for images, the chart has no `latest`.
6. **The values file replaces `deploy/production/`.** The chart's `values.yaml` keeps the
   sections `values.example.yaml` already defines: image, ingress, database, MQTT,
   InfluxDB, MCP, settings and secrets. It drops `cluster`, whose context and namespace
   are Helm's `--kube-context` and `--namespace`, and adds `baseUrl`, because the Ingress
   that `VICTUAL_BASE_URL` was derived from is optional under decision 7. `render.py`'s
   refusals become a
   `values.schema.json` that Helm enforces at install and upgrade. The schema covers
   required fields, types, the reserved setting names and the `CHANGE-ME` placeholder.
   `deploy/production/` is removed in the change that lands the chart.
7. **Templates for cluster concerns are off by default.** The Ingress and the
   `OnePasswordItem` resources are rendered only when their values enable them, and
   `deploy/k3s/`'s rendering enables neither. The two database-role Secrets are rendered
   in three modes: placeholder, inline, or not at all because the values name an existing
   Secret, which works with any secrets operator. `deploy/k3s/`'s rendering uses the
   placeholder mode, so the base keeps the two placeholder Secrets it carries today. The
   kind overlay replaces them with `secretGenerator` and `behavior: replace`, the talos
   overlay deletes them with `$patch: delete`, and `test_deploy_pod_parity.py` reads their
   names. All three fail if the Secrets are absent from the base.
8. **`deploy/kind/` and `deploy/talos/` become Helm values files.** Each keeps its
   `up.sh`, which installs the chart with its own values file. Both also run a PostgreSQL
   in the cluster, and the chart does not ship one, so `deploy/kind/postgres.yaml` and
   `roles-job.yaml` stay plain manifests that `up.sh` applies before `helm install`, with
   talos's NFS claim and uid patch moved into its own copy or a small overlay of them. The
   kind values file points the images at the local `localhost/` builds, as the
   kustomize `images:` override does today, so a kind run still tests the working tree.
9. **Two hook Jobs, both rendered only under Helm.**
   - A `pre-upgrade` Job runs the upgrade preflight from the migrate image, holding the
     migrate role's Secret, which `bin/victual-timestamp-preflight` needs for its
     rolled-back `CREATE`. A refusal (exit 2) fails `helm upgrade` before any pod
     restarts, so the running version keeps serving and the report is in the Job's log.
   - A `post-install` and `post-upgrade` Job runs `bin/victual-publish-state` from the app
     image, holding the app role's Secret, when `mqtt.enabled` is true. This is the step
     the [MQTT operator guide](../manual/operator/home-assistant-mqtt.md) asks for after
     every deployment and nothing runs today.

   Both are declared workloads under ADR-0010 and pass `check_deploy_manifest.py` through
   the values matrix of decision 3, which renders hooks. Because decision 2 renders
   `deploy/k3s/` with `--no-hooks`, a `kubectl apply` of the base runs neither, which is
   what it does today.
10. **The chart holds no PostgreSQL superuser credential, and `roles.sql` is a manual
    step outside it.** Whoever administers the database runs `deploy/postgres/roles.sql`
    before the first install and again for every password rotation, because the script
    is where both roles' passwords are set. The chart's documentation and its install
    notes say so. kind and talos are not exceptions: they own their in-cluster
    PostgreSQL, and their roles Job runs from the manifests decision 8 keeps outside the
    chart.

## Consequences

- **The deploy boundary changes for the chart, not for the base.** ADR-0010 open question
  1's answer and [deploy/README.md](../../deploy/README.md) say the fork's manifests stay
  silent on ingress, storage classes and secrets management. `deploy/production/` already
  departs from that without a record. Decision 7 states the departure: the chart may carry
  optional templates for cluster concerns, off by default. The rendered base stays silent
  on them and carries only the placeholder Secrets it has today. On acceptance, this record supersedes that answer in part, and ADR-0010 gains a
  forward pointer.
- **`helm rollback` does not restore the database.** Migrations only move forward. After
  an upgrade that migrates, rolling back to the older chart starts older code against a
  newer schema, and `SchemaVersionMiddleware` answers that code's requests with 503. The
  expected result under the default rolling update is that nothing is served by the older
  code. The web container's readiness probe requests `/login`, which the middleware
  checks, so the rolled-back pod should never become ready. The newer pod would then keep
  serving and `helm rollback --wait` would time out. If the newer pod is already gone, the
  instance answers 503 until the newer version is redeployed or the database is restored
  from a backup. The first outcome is what kind showed on 2026-10-08, and the older
  `migrate` initContainer did not refuse the newer schema (acceptance prerequisite 4's
  row under "Prerequisite status"). Either way,
  rollback is a recovery only for releases that add no migration. The operator manual
  must say so, and acceptance prerequisite 4 records which outcome occurs.
- **Migrations stay in the initContainer.** The pod is unchanged, so podman parity and the
  credential check in `test_deploy_pod_parity.py` are unaffected. Decision 9's hooks run
  beside the pod, before and after it rolls; neither migrates. Moving the migration itself
  into a hook would change the pod and would exist only under Helm.
- **Helm and `kubectl apply` deployments behave differently on upgrade.** Only a Helm
  upgrade runs the preflight and publishes MQTT state. A deployment from the rendered base
  learns about a refusing migration from a `migrate` initContainer in a restart loop, as
  it does today, and must run `victual-publish-state` by hand.
- **The migrate credential is held by a second workload.** Today only the `migrate`
  initContainer can read `victual-db-migrate`. The preflight Job also reads it.
  `test_deploy_pod_parity.py` checks the pod, not the chart, so the chart needs its own
  assertion that the preflight Job and the initContainer are the only readers.
- **Rotation is manual and is not yet safe to do live.** The app opens database
  connections per request, so changing a role's password in PostgreSQL before the pods
  hold the new Secret refuses every request in between, and the pods read their Secrets
  only at start. A rotation handler, as suggested in the response to open question 3,
  has to order the secret-store write, the `ALTER ROLE` and the pod restart, and still
  leaves a gap of one restart. Designing it is separate work.
- **The talos deployment loses its evidence.** Its 2026-10-06 apply went through the
  kustomize overlay; decision 8 replaces that overlay, so acceptance prerequisite 6
  repeats the deployment through the chart.
- **Release history lives in the cluster.** Helm stores each release's rendered manifests
  in a Secret in the namespace. With `secrets.source: inline`, the database passwords
  appear there as well as in the Secrets they populate. Anyone who can read Secrets in the
  namespace already has the passwords, so this exposes nothing new. A backup of the
  namespace, however, now holds every past password.
- **Other tools can install the chart.** ArgoCD and Flux both install charts from an OCI
  registry. k3s ships a Helm controller that installs a chart from a `HelmChart` resource;
  whether it pulls from an OCI registry has not been checked. The chart depends on none of
  them.
- **A chart change and a base change happen in the same commit.** A pull request that
  edits a template also commits the regenerated `deploy/k3s/`, and the drift check rejects
  one without the other. The cost is a generated diff in review. The benefit is that a
  reviewer sees the effect of a template change on the manifests.
- **The first chart publish is private.** GHCR creates a new package as private, as
  ADR-0030 records for the images. The maintainer must make the chart package public once,
  just as for the six image packages.
- **Out of scope:** chart signing and provenance, as ADR-0030 leaves image signing out;
  installing PostgreSQL; and the podman and Compose targets, which keep their own files and
  parity tests.

## Open questions

1. **Do `deploy/kind/` and `deploy/talos/` become Helm values files?** They can stay as
   kustomize overlays over the rendered `deploy/k3s/` unchanged. Both also apply an
   in-cluster PostgreSQL, which the chart does not ship. Converting `talos/` to a values
   file plus a separate PostgreSQL manifest would make the worked example demonstrate the
   recommended method.

   > **Response (datagen24, 2026-10-07):** Migrate both to Helm. Both have a Kubernetes
   > API, and Helm has been run against both before. *Reconciled as decision 8.*
2. **Should a `pre-upgrade` hook run a preflight before migrating?**
   `bin/victual-timestamp-preflight` reports in advance whether migration 0301 would
   refuse. A hook Job that runs it would block `helm upgrade` before any pod restarts,
   rather than leaving a migrate initContainer in a restart loop. The hook would hold the
   migrate role's credential. It would also exist only under Helm, because `kubectl
   apply` and podman do not run hooks. A related question is whether
   `bin/victual-publish-state` becomes a `post-upgrade` hook when MQTT is enabled; the
   [MQTT operator guide](../manual/operator/home-assistant-mqtt.md) asks for it to run
   after every deployment, and nothing runs it today.

   > **Response (datagen24, 2026-10-07):** Yes to both. *Reconciled as decision 9.*
3. ~~**Where does `roles.sql` run?**~~ **Answered: decision 10.** It needs a PostgreSQL superuser and runs twice, before
   and after the first migration. The chart would need the superuser's credential to run
   it as a hook. The lean is to keep it a documented manual step, as `values.example.yaml`
   describes today.

   Facts that bear on it, read from `deploy/postgres/roles.sql` on 2026-10-07:
   - The script creates the two roles, sets their passwords, makes `victual_migrate` own
     the `public` schema, and grants `victual_app` row access, including default
     privileges on everything `victual_migrate` creates later. A role with less than
     superuser needs `CREATEROLE`, ownership of the database and membership in
     `victual_migrate`; only superuser has been run.
   - Password rotation needs it too: the passwords are set by `ALTER ROLE … PASSWORD` in
     this script, so changing either one means running it again with the same privilege.
   - The second run, after the first migration, appears to add nothing on a fresh database
     where the first run preceded migration, because the default privileges already cover
     every table `victual_migrate` creates. It matters when tables exist that another role
     created. This reading has not been tested.

   > **Response (datagen24, 2026-10-07):** More information needed before ruling. The
   > decider's inclination is that anything needing a superuser is a manual step on an
   > external database.
   >
   > **Response (datagen24, 2026-10-07, after the facts above):** Ruled: an external,
   > manual step. A rotation handler is worth considering: one script that generates the
   > new passwords into 1Password or HashiCorp Vault, asks for the superuser's name and
   > password interactively, and runs `roles.sql`. *Reconciled as decision 10; the
   > rotation handler is not part of this record.*

## Acceptance prerequisites

1. `helm template` with `ci/k3s-values.yaml` reproduces the committed `deploy/k3s/`, and
   `check_deploy_manifest.py`, `test_deploy_pod_parity.py` and
   `test_deploy_label_parity.py` pass against it unchanged. A hand edit to a file in
   `deploy/k3s/` fails the `lint` job.
2. Every values file in the `ci/` matrix renders and passes `check_deploy_manifest.py`.
   A matrix entry that removes a container's memory limit fails it (the negative control).
3. `helm install` with `values.example.yaml` unedited fails and names a `CHANGE-ME`
   field, as `render.py` does.
4. On kind, `helm install` at one version and `helm upgrade` to a version that adds a
   migration both reach a ready Deployment. A `helm rollback` after that upgrade is run,
   and the record states what happened: whether the older pod became ready, what the
   older `migrate` initContainer did, and what clients received.
5. The first tag after the chart lands publishes it, and `helm pull
   oci://ghcr.io/datagen24/charts/victual --version <Version>` succeeds without
   credentials after the package is made public.
6. `deploy/kind/up.sh` and `deploy/talos/up.sh` deploy through `helm install` with their
   values files, and the talos cluster serves `/login` from the published chart.
7. On kind, an upgrade whose preflight refuses (exit 2, from a database seeded with a
   value migration 0301 refuses) fails `helm upgrade` while the previous pod keeps
   serving. With MQTT enabled against a local broker, an upgrade's `post-upgrade` Job
   publishes the retained state topics.

## Prerequisite status

Recorded 2026-10-08 against the branch `claude/adr38-helm-chart-gates-1-3`, rendered with
Helm v4.3.0 (the version the `lint` job installs, pinned by checksum). Rows 4 and 7
were recorded the same day against `claude/adr38-helm-hooks`, on a one-node kind cluster
(Kubernetes v1.37, podman provider, arm64). The commands, logs and the client's per-second
record are in that branch's pull request.

| # | Status | Evidence |
|---|---|---|
| 1 | **Met** | `python3 .devtools/ci/render_k3s.py --check` reports `deploy/k3s/ matches the chart (3 file(s))`. The parity tests and `check_deploy_manifest.py` pass unchanged (`python3 -m unittest discover -s .devtools/ci`: 70 tests, OK). Changing `replicas: 1` to `3` in `deploy/k3s/victual.yaml` by hand made `--check` exit 1 with the diff, and failed two tests in `test_helm_chart.py`. The `lint` job runs both. `kubectl kustomize` of `deploy/k3s`, `deploy/kind` and `deploy/talos` was parsed before and after the change and compared by kind and name: 13, 18 and 17 documents, no differences |
| 2 | **Met** | `test_helm_chart.ValuesMatrixTest`: six values files in `deploy/helm/victual/ci/` render with hooks and pass `validate()`. Between them they cover all four secrets modes, the MCP sidecar, both label CronJobs, the Ingress, and the MQTT and InfluxDB Secrets, which a test asserts. The negative control, `ci/negative/app-without-memory-limit.yaml`, fails with `container/app: resources.limits.memory must be set` |
| 3 | **Met, with a different message** | `helm install victual deploy/helm/victual -f deploy/helm/victual/values.example.yaml --dry-run=client` exits non-zero and lists `at '/database/host'`, `at '/ingress/host'` and `at '/secrets/onepassword/vault'`, the example's three `CHANGE-ME` fields. Helm's validator words the refusal as `'not' failed` rather than `render.py`'s `still 'CHANGE-ME…'; fill it in`. Its regular-expression engine has no lookahead, so the schema can say "not this pattern" only through `not`. The example's header says what the refusal means |
| 4 | **Met** | On kind, install at 0.2.0-MVP and upgrade to the hook Jobs' build both reached a ready Deployment. `helm rollback` across the migration exited 1: the older pod never became ready and the newer pod kept serving. See [Prerequisite 4 on kind](#prerequisite-4-on-kind) |
| 5 | Not met | Nothing has been published |
| 6 | **Half met: kind** | `deploy/kind/up.sh` installs the chart with `deploy/kind/values.yaml`: from scratch, again over its own release, and over a namespace the old kustomize overlay had applied, each to a ready Deployment that logs in. `deploy/talos/up.sh` installs `oci://ghcr.io/datagen24/charts/victual` by version, or the local chart with `--local`; its rendering passes `validate()` and `kubectl apply --dry-run=client`, and has not been applied, so the talos cluster has not served `/login` from the published chart. See [Prerequisite 6](#prerequisite-6-kind-and-talos-through-the-chart) |
| 7 | **Met** | On kind, a preflight refusal (exit 2) failed `helm upgrade` before any pod was replaced, and clients kept getting 200. The `post-upgrade` Job published nine retained topics to a local broker. See [Prerequisite 7 on kind](#prerequisite-7-on-kind) |

### Prerequisite 4 on kind

The release ran in namespace `adr38` with Helm v4.3.0 and `secrets.source: existing`.
PostgreSQL and the roles came from `deploy/kind/`. An in-cluster client requested `/login`
once a second throughout.

1. **Install.** Revision 1, `helm install --wait` of the published `0.2.0-MVP` images, was
   ready in 3 seconds with the schema at migration 288.
2. **Upgrade.** Revision 3, `helm upgrade --wait` to images built from
   `claude/adr38-helm-hooks` as `0.3.0-adr38`, exited 0. The new pod's `migrate` initContainer converted 317 timestamps
   and reported the schema at migration 304. During the rollout the client got 503 three
   times from the old pod, then one connection failure while the Service switched pods.
3. **Rollback.** `helm rollback victual 1 --wait --timeout 3m` exited 1 with `context
   deadline exceeded`, and revision 4 is `failed`.
   - The older `migrate` initContainer exited 0 and logged `Schema is up to date at
     migration 304`. Its own migrations stop at 288, so it neither ran nor refused
     anything.
   - The older pod never became ready. Its readiness probe failed 25 times with `GET
     /login answered 503`. A request sent to that pod directly returned 503 with `Victual
     cannot serve requests: the database schema does not match this code.`
   - The newer pod stayed ready and answered all 177 of the client's requests with 200.
   - The rollback restored revision 1's ConfigMap, so the newer pod would start with MQTT
     off if it restarted.
4. **Recovery.** `helm rollback victual 3 --wait` exited 0.

This is the first outcome Consequences predicts. Rollback is a recovery only for releases
that add no migration.

### Prerequisite 6: kind and talos through the chart

Recorded 2026-10-08 against `claude/adr38-kind-talos-values`, with Helm v4.3.0 on the same
kind cluster, in scratch namespaces (the cluster's `victual` namespace belonged to another
session). Images were built from `b930242f` and tagged `0.3.0-helmkind`.

1. **From scratch** (namespace `helmkind`). `up.sh` exited 0 in 23 seconds: namespace,
   Secrets, PostgreSQL, the roles Job, then `helm upgrade --install --wait` installed
   revision 1. The Deployment was 1/1 and the MCP sidecar 2/2. The migrate initContainer
   reported the schema at migration 304. Through a port-forward, `GET /login` answered 200; a
   `POST /login` with the generated bootstrap password answered 302 to `/`, which answered 302
   to `/stockoverview`, which answered 200. A wrong password answered 302 to
   `/login?invalid=true`.
2. **Again over its own release.** `up.sh` exited 0 in 6 seconds with revision 2. The
   pre-upgrade preflight Job completed and logged `No TIMESTAMP column is left to convert`.
   The Victual pod kept its name, because nothing in its template changed. The same login
   sequence gave the same codes.
3. **Over the kustomize overlay** (namespace `helmadopt`). The old `deploy/kind` overlay,
   rendered from `b930242f`, was applied with `kubectl apply`, and `up.sh` was then run
   over it. The first attempt, without adoption, failed: Helm 4's server-side apply
   conflicted with the fields `kubectl-client-side-apply` owned. With `--take-ownership
   --force-conflicts` on the first install, which `up.sh` now passes only when no release
   exists, it exited 0 with revision 1. The PostgreSQL pod was not replaced. The admin row's
   `row_created_timestamp` was the same before and after, and the login sequence gave the
   same codes. The overlay's two label CronJobs, which `values.yaml` leaves out, stayed in
   place until deleted by hand. In a third namespace, an install with `--take-ownership`
   alone over the same overlay failed on those conflicts and left revision 1 `failed`.
   `up.sh` treats a release with no deployed revision as new, so its retry passed both flags
   and upgraded it to a deployed revision 2 that logs in.
4. **talos, not applied.** `up.sh` installs the chart at `version.json`'s version. The first
   published chart will be 0.3.1, the tag after the gate pull requests merge, and that
   release moves `version.json` to 0.3.1; no 0.3.0 chart exists.
   `helm template` with `deploy/talos/values.yaml` (11 documents, the preflight hook among them) and `kubectl kustomize deploy/talos/postgres` (6) pass
   `validate()`. Without the `OnePasswordItem`s, which kind has no CRD for, both pass
   `kubectl apply --dry-run=client` against kind. Compared by kind and name with the old
   overlay's rendering from `b930242f`, every object is the same, except three:
   - The Namespace is gone, because `up.sh` creates it.
   - The preflight Job is new.
   - The chart's three `OnePasswordItem`s gain the label `app.kubernetes.io/name: victual`.

   The comparison is with the overlay as committed, at 0.3.0. The cluster was last applied
   at `0.2.0-MVP`, so if it still runs those images the first install also upgrades it, and
   an install runs no `pre-upgrade` hook: the preflight has to be run by hand first. Neither
   the adoption nor that upgrade has been observed on the cluster.

### Prerequisite 7 on kind

The release of prerequisite 4, between its revisions 1 and 3.

1. A task completion of `infinity` was inserted as the superuser, as the upgrade
   rehearsal's invalid fixture does.
2. `helm upgrade --wait` to those images exited 1 after 3 seconds with
   `pre-upgrade hooks failed: resource Job/adr38/victual-upgrade-preflight not ready`.
   Revision 2 is `failed`.
3. The preflight container exited 2. Its log is the report and ends `tasks.done_timestamp:
   1 value(s) are infinite in UTC`.
4. The 0.2.0-MVP pod was not replaced: it kept the same name and start time. The ConfigMap
   kept revision 1's values, and the client got 200 on every request.
5. After the row was corrected, the upgrade to revision 3 enabled MQTT against an anonymous
   Mosquitto 2 broker in the namespace. Its `post-upgrade` Job completed in 3 seconds and
   logged `Published the discovery payloads (device mode) and the state snapshot`.
6. `mosquitto_sub --retained-only` read nine retained topics: eight under `victual/state/`
   and the Home Assistant device discovery topic.

How the chart differs from `deploy/production/`'s values:

- **The `cluster` section is gone.** `cluster.context` and `cluster.namespace` named the
  kubectl context and the namespace for `deploy.sh`. Under Helm these are `--kube-context`
  and `--namespace`, so the chart does not read them, and the schema refuses an unknown
  section rather than ignoring it.

  > **Response (datagen24, 2026-10-08):** Approved: drop `cluster`. *Reconciled into
  > decision 6.*
- **`baseUrl` is a value of its own.** `render.py` derived `VICTUAL_BASE_URL` from
  `ingress.host`, which worked because its Ingress was always on. Decision 7 makes the
  Ingress optional, and the base renders none but needs a URL. `baseUrl` is therefore
  required unless the Ingress is enabled; with it enabled and `baseUrl` empty, the URL is
  derived as before.

  > **Response (datagen24, 2026-10-08):** Approved: keep `baseUrl`. *Reconciled into
  > decision 6.*
- **Secrets have four modes:** `placeholder`, `inline`, `onepassword` and `existing`.
  These are decision 7's three, with the `OnePasswordItem` resources as a mode of their
  own. Every credential Secret follows the mode, including the four added by
  [#679](https://github.com/datagen24/victual/pull/679) and the label workers'
  `victual-label-credentials`. `secrets.names` sets the Secret names, which is how
  `existing` refers to Secrets the operator already has.
- **The label workers are in the chart**, behind `labelWorkers.enabled`. `values.yaml`
  turns them on, as the base always has. `values.example.yaml` turns them off, as
  `render.py` always did.
- **`values.example.yaml` is in the chart**, at `deploy/helm/victual/values.example.yaml`,
  and is packaged with it. In inline mode the bootstrap administrator's password is still
  required, as `render.py` required it.
- **The hook Jobs run `/opt/victual/php bin/<command>`.** The app and migrate images had
  no PATH, no shell and a `Cmd` of store paths, so a manifest had no name for either
  command. `nix/php-launcher.nix` places each image's own PHP at `/opt/victual/php`, as
  `nix/healthcheck.nix` places the probe. The scripts stay in the application root, which
  is the images' working directory. `upgradePreflight.enabled` turns the preflight off;
  `deploy/k3s/`'s values turn it off, although `--no-hooks` already leaves it out.
- **Memory limits are values** (`resources.*`). The schema does not require them, so that
  the negative control reaches `check_deploy_manifest.py`, which is the check this record
  names.

