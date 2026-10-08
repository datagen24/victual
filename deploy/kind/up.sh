#!/usr/bin/env bash
#
# Bring Victual and its MCP sidecar up on a local kind cluster, the Kubernetes counterpart
# of deploy/README.md's podman walkthrough.
#
#   deploy/kind/up.sh            load images, apply, wait for everything to be Ready
#   deploy/kind/up.sh down       delete the namespace (the database goes with it)
#
# Needs: a kind cluster (KIND_CLUSTER, default `kind-cluster`), and the images already in
# podman — `nix/build-in-podman.sh images` builds and loads all six. kind's podman
# provider is still marked experimental, hence KIND_EXPERIMENTAL_PROVIDER.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

CLUSTER="${KIND_CLUSTER:-kind-cluster}"
NAMESPACE=victual
# kind's own context, never the current one: on a machine that also talks to a real cluster
# the current context may well be that cluster. KUBE_CONTEXT overrides it.
KUBECTL=(kubectl --context "${KUBE_CONTEXT:-kind-$CLUSTER}")
VERSION="${VICTUAL_IMAGE_TAG:-0.3.0}"
export KIND_EXPERIMENTAL_PROVIDER="${KIND_EXPERIMENTAL_PROVIDER:-podman}"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [ "${1:-up}" = down ]; then
	log "deleting namespace $NAMESPACE"
	"${KUBECTL[@]}" delete namespace "$NAMESPACE" --ignore-not-found --wait
	exit 0
fi

for image in victual-app victual-web victual-migrate victual-mcp victual-label-renderer victual-label-worker; do
	log "loading localhost/$image:$VERSION into kind cluster $CLUSTER"
	kind load docker-image "localhost/$image:$VERSION" --name "$CLUSTER"
done
kind load docker-image docker.io/library/postgres:16 --name "$CLUSTER"

# Local-only credentials, generated once and kept beside the overlay (gitignored), so a
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

"${KUBECTL[@]}" apply -f deploy/kind/namespace.yaml
# The roles script from its one real location, not a copy kustomize could drift from.
"${KUBECTL[@]}" -n "$NAMESPACE" create configmap victual-db-roles-sql \
	--from-file=roles.sql=deploy/postgres/roles.sql --dry-run=client -o yaml | "${KUBECTL[@]}" apply -f -

log "applying deploy/kind"
"${KUBECTL[@]}" apply -k deploy/kind

log "waiting for PostgreSQL, the roles, Victual and the sidecar"
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual-postgres --timeout=180s
"${KUBECTL[@]}" -n "$NAMESPACE" wait --for=condition=complete job/victual-db-roles --timeout=180s
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual --timeout=300s
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual-mcp --timeout=120s

cat <<MSG

Up. Reach it with:
  kubectl --context ${KUBECTL[2]} -n $NAMESPACE port-forward svc/victual 8080:8080
  kubectl --context ${KUBECTL[2]} -n $NAMESPACE port-forward svc/victual-mcp 3000:3000

Log in as admin with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD from $SECRETS/bootstrap-admin.env
(it applies to the database this script first created; see deploy/README.md).
MSG
