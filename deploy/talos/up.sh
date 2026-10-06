#!/usr/bin/env bash
#
# Bring Victual and its MCP sidecar up on the Talos Raspberry Pi cluster, from the published
# GHCR images. See kustomization.yaml for what the overlay contains and what it leaves out.
#
#   deploy/talos/up.sh           apply, wait for everything to be Ready
#   deploy/talos/up.sh down      delete the namespace (the database goes with it)
#
# Uses the current kubectl context; set KUBE_CONTEXT to name another.
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

# Generated once and reused, so a re-run keeps the passwords the database was initialised
# with. Same files and keys as deploy/kind/up.sh.
SECRETS=deploy/talos/.secrets
mkdir -p "$SECRETS"
password() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32; }
[ -f "$SECRETS/superuser.env" ] || printf 'password=%s\n' "$(password)" > "$SECRETS/superuser.env"
[ -f "$SECRETS/migrate.env" ] || printf 'VICTUAL_DB_USER=victual_migrate\nVICTUAL_DB_PASSWORD=%s\n' "$(password)" > "$SECRETS/migrate.env"
grep -q '^VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=' "$SECRETS/migrate.env" \
	|| printf 'VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=%s\n' "$(password)" >> "$SECRETS/migrate.env"
[ -f "$SECRETS/app.env" ] || printf 'VICTUAL_DB_USER=victual_app\nVICTUAL_DB_PASSWORD=%s\n' "$(password)" > "$SECRETS/app.env"
chmod 600 "$SECRETS"/*.env

log "applying deploy/talos"
"${KUBECTL[@]}" kustomize --load-restrictor LoadRestrictionsNone deploy/talos | "${KUBECTL[@]}" apply -f -

log "waiting for PostgreSQL, the roles, Victual and the sidecar"
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual-postgres --timeout=300s
"${KUBECTL[@]}" -n "$NAMESPACE" wait --for=condition=complete job/victual-db-roles --timeout=300s
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual --timeout=600s
"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual-mcp --timeout=300s

cat <<MSG

Up at http://victual.10.130.34.240.nip.io

Log in as admin with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD from $SECRETS/migrate.env.
The database is an emptyDir: it is lost when the PostgreSQL pod restarts.
MSG
