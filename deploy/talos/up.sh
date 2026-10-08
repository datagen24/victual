#!/usr/bin/env bash
#
# Bring Victual and its MCP sidecar up on the Talos Raspberry Pi cluster, from the published
# GHCR images. See kustomization.yaml for what the overlay contains and what it leaves out.
#
#   deploy/talos/up.sh           apply, wait for everything to be Ready
#   deploy/talos/up.sh down      delete the namespace; the database's claim goes with it,
#                                and nfs-csi's Delete reclaim policy removes its data
#
# Uses the current kubectl context; set KUBE_CONTEXT to name another. The database
# passwords come from 1Password through the cluster's Connect operator: run
# deploy/talos/seed-1password.sh once first, or the four Secrets never appear.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

NAMESPACE=victual
KUBECTL=(kubectl ${KUBE_CONTEXT:+--context "$KUBE_CONTEXT"})

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [ "${1:-up}" = down ]; then
	log "deleting namespace $NAMESPACE"
	"${KUBECTL[@]}" delete namespace "$NAMESPACE" --ignore-not-found --wait
	exit 0
fi

log "applying deploy/talos"
"${KUBECTL[@]}" kustomize --load-restrictor LoadRestrictionsNone deploy/talos | "${KUBECTL[@]}" apply -f -

log "waiting for the Connect operator to write the four Secrets"
for secret in victual-postgres-superuser victual-db-migrate victual-db-app victual-bootstrap-admin; do
	for _ in $(seq 60); do
		"${KUBECTL[@]}" -n "$NAMESPACE" get secret "$secret" >/dev/null 2>&1 && break
		sleep 2
	done
	"${KUBECTL[@]}" -n "$NAMESPACE" get secret "$secret" >/dev/null \
		|| { echo "Secret $secret did not appear: is its 1Password item seeded?" >&2; exit 1; }
done

log "waiting for PostgreSQL, the roles, Victual and the sidecar"
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual-postgres --timeout=300s
"${KUBECTL[@]}" -n "$NAMESPACE" wait --for=condition=complete job/victual-db-roles --timeout=300s
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual --timeout=600s
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual-mcp --timeout=300s

cat <<MSG

Up at http://victual.10.130.34.240.nip.io

Log in as admin with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD from the 1Password item
victual-bootstrap-admin (it applies to the database this overlay first created).
MSG
