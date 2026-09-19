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
VERSION="${VICTUAL_IMAGE_TAG:-4.6.0}"
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
MSG
