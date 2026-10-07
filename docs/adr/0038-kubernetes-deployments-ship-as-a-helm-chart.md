# ADR-0038: Kubernetes deployments ship as a Helm chart, and `deploy/k3s/` becomes its rendered output

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-10-07
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
| [`deploy/production/`](../../deploy/production/) | A values file rendered into an overlay by `render.py`, applied by `deploy.sh` | Generating a kustomization that lists the base's files and patches them |

`deploy/production/` is a values-to-manifests renderer written for this repository.
[`render.py`](../../deploy/production/render.py) reads
[`values.example.yaml`](../../deploy/production/values.example.yaml). It refuses a missing,
mistyped or `CHANGE-ME` value by field name, then generates a namespace, an Ingress, a
ConfigMap patch and either `OnePasswordItem` resources or inline Secret patches.
`deploy.sh` applies the result and waits for the rollout. This is the job Helm does. Helm
also keeps release history, offers `helm diff` and `helm rollback`, and packages a
versioned artifact. Victual's renderer has none of these.

Updates require manual edits. The 0.3.0 version bump, commit `541076c9` on 2026-10-07,
changed the image tag by hand in eight files under `deploy/`: the two k3s
manifests, the k3s label workers, the podman pod and label workers, the Compose label
workers, `kind/up.sh` and `values.example.yaml`. `version.json` is already the authority
for the version. [`nix/overlay.nix`](../../nix/overlay.nix) reads it, and
`release.yml` refuses a tag that disagrees with it. No deploy file reads it.

Four checks read `deploy/k3s/` as plain YAML:

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
   (`deploy/helm/victual/ci/k3s-values.yaml`) into `deploy/k3s/`; `deploy/k3s/kustomization.yaml` stays hand-written. The `lint` job renders
   it again and fails if the result differs from the committed files. `kubectl apply -k
   deploy/k3s` keeps working for anyone who does not use Helm. The kind and talos overlays,
   `check_deploy_manifest.py` and both parity tests keep reading the same files.
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
   sections `values.example.yaml` already defines: cluster, image, ingress, database,
   MQTT, InfluxDB, MCP, settings and secrets. `render.py`'s refusals become a
   `values.schema.json` that Helm enforces at install and upgrade. The schema covers
   required fields, types, the reserved setting names and the `CHANGE-ME` placeholder.
   `deploy/production/` is removed in the change that lands the chart.
7. **Templates for cluster concerns are off by default.** The Ingress, `OnePasswordItem`
   resources and the inline Secrets are rendered only when their values enable them, and
   `deploy/k3s/`'s rendering enables none of them. The chart also accepts the name of an
   existing Secret for each database role. This works with any secrets operator.

## Consequences

- **The deploy boundary changes for the chart, not for the base.** ADR-0010 open question
  1's answer and [deploy/README.md](../../deploy/README.md) say the fork's manifests stay
  silent on ingress, storage classes and secrets management. `deploy/production/` already
  departs from that without a record. Decision 7 states the departure: the chart may carry
  optional templates for cluster concerns, off by default, and the rendered base stays
  silent. On acceptance, this record supersedes that answer in part, and ADR-0010 gains a
  forward pointer.
- **`helm rollback` does not restore the database.** Migrations only move forward. After
  an upgrade that migrates, rolling back to the older chart starts older code against a
  newer schema. `SchemaVersionMiddleware` answers every request with 503 until the newer
  version is redeployed or the database is restored from a backup. Rollback is a recovery
  only for releases that add no migration. The operator manual must say so, and acceptance
  prerequisite 4 demonstrates it.
- **Migrations stay in the initContainer.** The pod is unchanged, so podman parity and the
  credential check in `test_deploy_pod_parity.py` are unaffected. Moving migrations to a
  `pre-upgrade` hook Job would change the pod, and would exist only under Helm. It is
  open question 2, not part of this decision.
- **Release history lives in the cluster.** Helm stores each release's rendered manifests
  in a Secret in the namespace. With `secrets.source: inline`, the database passwords
  appear there as well as in the Secrets they populate. Anyone who can read Secrets in the
  namespace already has the passwords, so this exposes nothing new. A backup of the
  namespace, however, now holds every past password.
- **The chart can be installed without Helm on the workstation.** k3s's built-in Helm
  controller installs a chart from a `HelmChart` resource. ArgoCD and Flux both install
  OCI charts. The chart does not depend on any of them.
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
   kustomize overlays over the rendered `deploy/k3s/` unchanged, which is the default
   under decision 2. Both also apply an in-cluster PostgreSQL, which the chart does not
   ship. Converting `talos/` to a values file plus a separate PostgreSQL manifest would
   make the worked example demonstrate the recommended method.
2. **Should a `pre-upgrade` hook run a preflight before migrating?**
   `bin/victual-timestamp-preflight` reports in advance whether migration 0301 would
   refuse. A hook Job that runs it would block `helm upgrade` before any pod restarts,
   rather than leaving a migrate initContainer in a restart loop. The hook would hold the
   migrate role's credential. It would also exist only under Helm, because `kubectl
   apply` and podman do not run hooks. A related question is whether
   `bin/victual-publish-state` becomes a `post-upgrade` hook when MQTT is enabled; the
   [MQTT operator guide](../manual/operator/home-assistant-mqtt.md) asks for it to run
   after every deployment, and nothing runs it today.
3. **Where does `roles.sql` run?** It needs a PostgreSQL superuser and runs twice, before
   and after the first migration. The chart would need the superuser's credential to run
   it as a hook. The lean is to keep it a documented manual step, as `values.example.yaml`
   describes today.

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
   migration both reach a ready Deployment. A `helm rollback` after that upgrade answers
   503 from `SchemaVersionMiddleware`, as stated in *Consequences*.
5. The first tag after the chart lands publishes it, and `helm pull
   oci://ghcr.io/datagen24/charts/victual --version <Version>` succeeds without
   credentials after the package is made public.
