#!/usr/bin/env bash
#
# Bring Victual and its MCP sidecar up on a local kind cluster, the Kubernetes counterpart
# of deploy/README.md's podman walkthrough.
#
#   deploy/kind/up.sh            load images, apply, wait for everything to be Ready
#   deploy/kind/up.sh down       delete the namespace (the database goes with it)
#
# Needs: a kind cluster (KIND_CLUSTER, default `kind-cluster`), and the images already in
# podman — `nix/build-in-podman.sh images` builds and loads all four. kind's podman
# provider is still marked experimental, hence KIND_EXPERIMENTAL_PROVIDER.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

CLUSTER="${KIND_CLUSTER:-kind-cluster}"
NAMESPACE=victual
VERSION="${VICTUAL_IMAGE_TAG:-0.1.1-MVP}"
export KIND_EXPERIMENTAL_PROVIDER="${KIND_EXPERIMENTAL_PROVIDER:-podman}"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [ "${1:-up}" = down ]; then
	log "deleting namespace $NAMESPACE"
	kubectl delete namespace "$NAMESPACE" --ignore-not-found --wait
	exit 0
fi

for image in victual-app victual-web victual-migrate victual-mcp; do
	log "loading localhost/$image:$VERSION into kind cluster $CLUSTER"
	kind load docker-image "localhost/$image:$VERSION" --name "$CLUSTER"
done
kind load docker-image docker.io/library/postgres:16 --name "$CLUSTER"

# Local-only credentials, generated once and kept beside the overlay (gitignored), so a
# re-run reuses the passwords the database was initialised with.
SECRETS=deploy/kind/.secrets
mkdir -p "$SECRETS"
password() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32; }
[ -f "$SECRETS/superuser.env" ] || printf 'password=%s\n' "$(password)" > "$SECRETS/superuser.env"
[ -f "$SECRETS/migrate.env" ] || printf 'VICTUAL_DB_USER=victual_migrate\nVICTUAL_DB_PASSWORD=%s\n' "$(password)" > "$SECRETS/migrate.env"
# The first administrator's password, in the migrate Secret because the migrate container
# is the only one that seeds. Appended rather than only written with the file, so a
# .secrets/ from before this line existed gains it too; it matters only on the run that
# creates the database, and does nothing to an account that already exists.
grep -q '^VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=' "$SECRETS/migrate.env" \
	|| printf 'VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=%s\n' "$(password)" >> "$SECRETS/migrate.env"
[ -f "$SECRETS/app.env" ] || printf 'VICTUAL_DB_USER=victual_app\nVICTUAL_DB_PASSWORD=%s\n' "$(password)" > "$SECRETS/app.env"
chmod 600 "$SECRETS"/*.env

kubectl apply -f deploy/kind/namespace.yaml
# The roles script from its one real location, not a copy kustomize could drift from.
kubectl -n "$NAMESPACE" create configmap victual-db-roles-sql \
	--from-file=roles.sql=deploy/postgres/roles.sql --dry-run=client -o yaml | kubectl apply -f -

log "applying deploy/kind"
kubectl apply -k deploy/kind

log "waiting for PostgreSQL, the roles, Victual and the sidecar"
kubectl -n "$NAMESPACE" rollout status deployment/victual-postgres --timeout=180s
kubectl -n "$NAMESPACE" wait --for=condition=complete job/victual-db-roles --timeout=180s
kubectl -n "$NAMESPACE" rollout status deployment/victual --timeout=300s
kubectl -n "$NAMESPACE" rollout status deployment/victual-mcp --timeout=120s

cat <<MSG

Up. Reach it with:
  kubectl -n $NAMESPACE port-forward svc/victual 8080:8080
  kubectl -n $NAMESPACE port-forward svc/victual-mcp 3000:3000

Log in as admin with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD from $SECRETS/migrate.env
(it applies to the database this script first created; see deploy/README.md).
MSG
