#!/usr/bin/env bash
#
# Bring Victual and its MCP sidecar up on a local kind cluster through the Helm chart, the
# Kubernetes counterpart of deploy/README.md's podman walkthrough (ADR-0038 decision 8).
#
#   deploy/kind/up.sh            load images, install or upgrade, wait for everything Ready
#   deploy/kind/up.sh down       delete the namespace (the database and the release go with it)
#
# In order: the namespace, the throwaway PostgreSQL and the roles Job beside this script
# (the chart ships no PostgreSQL, ADR-0010), the four Secrets from deploy/kind/.secrets/,
# then `helm upgrade --install` with deploy/kind/values.yaml. Run it again to upgrade: the
# second run is a `helm upgrade`, so the chart's pre-upgrade preflight runs too.
#
# Needs: a kind cluster (KIND_CLUSTER, default `kind-cluster`), Helm, and the images already
# in podman — `nix/build-in-podman.sh images` builds and loads them. kind's podman provider
# is still marked experimental, hence KIND_EXPERIMENTAL_PROVIDER. VICTUAL_NAMESPACE picks
# the namespace (default `victual`), so two runs can share a cluster.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

CLUSTER="${KIND_CLUSTER:-kind-cluster}"
NAMESPACE="${VICTUAL_NAMESPACE:-victual}"
RELEASE=victual
# kind's own context, never the current one: on a machine that also talks to a real cluster
# the current context may well be that cluster. KUBE_CONTEXT overrides it.
CONTEXT="${KUBE_CONTEXT:-kind-$CLUSTER}"
KUBECTL=(kubectl --context "$CONTEXT" -n "$NAMESPACE")
# The images the working tree builds are tagged with version.json's Version.
VERSION="${VICTUAL_IMAGE_TAG:-$(sed -n 's/.*"Version": *"\([^"]*\)".*/\1/p' version.json)}"
export KIND_EXPERIMENTAL_PROVIDER="${KIND_EXPERIMENTAL_PROVIDER:-podman}"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [ "${1:-up}" = down ]; then
	log "deleting namespace $NAMESPACE"
	kubectl --context "$CONTEXT" delete namespace "$NAMESPACE" --ignore-not-found --wait
	exit 0
fi

# The label workers are off in values.yaml, so their two images are not needed.
for image in victual-app victual-web victual-migrate victual-mcp; do
	log "loading localhost/$image:$VERSION into kind cluster $CLUSTER"
	kind load docker-image "localhost/$image:$VERSION" --name "$CLUSTER"
done
kind load docker-image docker.io/library/postgres:16 --name "$CLUSTER"

# Local-only credentials, generated once and kept beside the values file (gitignored), so a
# re-run reuses the passwords the database was initialised with.
SECRETS=deploy/kind/.secrets
# Owner-only from the moment each file exists, not only after the chmod below.
umask 077
mkdir -p "$SECRETS"
password() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32; }
[ -f "$SECRETS/superuser.env" ] || printf 'password=%s\n' "$(password)" > "$SECRETS/superuser.env"
[ -f "$SECRETS/migrate.env" ] || printf 'VICTUAL_DB_USER=victual_migrate\nVICTUAL_DB_PASSWORD=%s\n' "$(password)" > "$SECRETS/migrate.env"
[ -f "$SECRETS/app.env" ] || printf 'VICTUAL_DB_USER=victual_app\nVICTUAL_DB_PASSWORD=%s\n' "$(password)" > "$SECRETS/app.env"
# The first administrator's password, in a file and Secret of its own that only the migrate
# container reads: the database Secrets hold the database credential and nothing else. It
# matters only on the run that creates the database, and does nothing to an account that
# already exists. A .secrets/ from before the split kept it in migrate.env; it moves from
# there with its value, so the password the database was seeded with still logs in.
if [ ! -f "$SECRETS/bootstrap-admin.env" ]; then
	grep '^VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=' "$SECRETS/migrate.env" > "$SECRETS/bootstrap-admin.env" \
		|| printf 'VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=%s\n' "$(password)" > "$SECRETS/bootstrap-admin.env"
fi
if grep -q '^VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=' "$SECRETS/migrate.env"; then
	grep -v '^VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=' "$SECRETS/migrate.env" > "$SECRETS/migrate.env.new"
	mv "$SECRETS/migrate.env.new" "$SECRETS/migrate.env"
fi
chmod 600 "$SECRETS"/*.env

# Namespaced apply, not Helm's --create-namespace: PostgreSQL goes in before the chart.
kubectl --context "$CONTEXT" create namespace "$NAMESPACE" --dry-run=client -o yaml \
	| kubectl --context "$CONTEXT" apply -f -

# The Secrets the chart names (values.yaml: secrets.source: existing) and PostgreSQL's own.
# Applied rather than created, so a re-run is a no-op and an edited file takes effect.
secret() {
	"${KUBECTL[@]}" create secret generic "$1" --from-env-file="$SECRETS/$2" --dry-run=client -o yaml \
		| "${KUBECTL[@]}" apply -f -
}
secret victual-postgres-superuser superuser.env
secret victual-db-migrate migrate.env
secret victual-db-app app.env
secret victual-bootstrap-admin bootstrap-admin.env

# The roles script from its one real location, not a copy that could drift from it.
"${KUBECTL[@]}" create configmap victual-db-roles-sql \
	--from-file=roles.sql=deploy/postgres/roles.sql --dry-run=client -o yaml | "${KUBECTL[@]}" apply -f -

log "applying PostgreSQL and the roles Job"
"${KUBECTL[@]}" apply -f deploy/kind/postgres.yaml -f deploy/kind/roles-job.yaml
"${KUBECTL[@]}" rollout status deployment/victual-postgres --timeout=180s
"${KUBECTL[@]}" wait --for=condition=complete job/victual-db-roles --timeout=180s

log "helm upgrade --install $RELEASE at $VERSION"
# A namespace an earlier, kustomize-based up.sh applied becomes the release's on the first
# install: --take-ownership adopts the resources Helm did not create (it would otherwise
# refuse them), and --force-conflicts lets its server-side apply overwrite the fields
# `kubectl apply` owned. Only then, so a later run never takes another release's resources.
# The old overlay's label CronJobs, which the chart does not render here, are left alone.
# "Never installed" means no revision ever deployed: a first install that failed leaves a
# release whose retry must adopt too.
FIRST=()
helm history "$RELEASE" --kube-context "$CONTEXT" -n "$NAMESPACE" -o json 2>/dev/null \
	| grep -qE '"status":"(deployed|superseded)"' \
	|| FIRST=(--take-ownership --force-conflicts)
# --wait alone is Helm 4's `watcher`: it waits for the Deployments to be Ready, not only for
# the hooks, which is what the flag's absence means.
helm upgrade --install "$RELEASE" deploy/helm/victual -f deploy/kind/values.yaml \
	--set "image.tag=$VERSION" ${FIRST[@]+"${FIRST[@]}"} \
	--kube-context "$CONTEXT" --namespace "$NAMESPACE" --wait --timeout 5m

cat <<MSG

Up. Reach it with:
  kubectl --context $CONTEXT -n $NAMESPACE port-forward svc/victual 8080:8080
  kubectl --context $CONTEXT -n $NAMESPACE port-forward svc/victual-mcp 3000:3000

Log in as admin with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD from $SECRETS/bootstrap-admin.env
(it applies to the database this script first created; see deploy/README.md).
MSG
