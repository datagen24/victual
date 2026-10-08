#!/usr/bin/env bash
#
# Bring Victual and its MCP sidecar up on the Talos Raspberry Pi cluster through the Helm
# chart (ADR-0038 decision 8): the published chart and images by default, values.yaml beside
# this script, and the in-cluster PostgreSQL in deploy/talos/postgres/.
#
#   KUBE_CONTEXT=<ctx> deploy/talos/up.sh            install or upgrade, wait for Ready
#   KUBE_CONTEXT=<ctx> deploy/talos/up.sh --local    the same from deploy/helm/victual/
#   KUBE_CONTEXT=<ctx> deploy/talos/up.sh down       delete the namespace; the database's
#                                                    claim goes with it, and nfs-csi's Delete
#                                                    reclaim policy removes its data
#
# KUBE_CONTEXT is required: this script never uses the current context, which on the
# maintainer's machine may be any cluster. The chart comes from
# oci://ghcr.io/datagen24/charts/victual at CHART_VERSION (version.json's Version unless set);
# --local installs the working tree's chart instead, still with the published images.
# The first published chart is 0.3.1: no earlier chart exists.
#
# The passwords come from 1Password through the cluster's Connect operator: run
# deploy/talos/seed-1password.sh once first, or the four Secrets never appear.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

: "${KUBE_CONTEXT:?set KUBE_CONTEXT to the kubectl context of the Talos cluster}"
NAMESPACE=victual
RELEASE=victual
KUBECTL=(kubectl --context "$KUBE_CONTEXT" -n "$NAMESPACE")
CHART="oci://ghcr.io/datagen24/charts/victual"
CHART_VERSION="${CHART_VERSION:-$(sed -n 's/.*"Version": *"\([^"]*\)".*/\1/p' version.json)}"
CHART_ARGS=("$CHART" --version "$CHART_VERSION")

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [ "${1:-}" = --local ]; then
	CHART_ARGS=(deploy/helm/victual)
	CHART_VERSION="$(sed -n 's/^version: *//p' deploy/helm/victual/Chart.yaml) (local)"
	shift
fi

if [ "${1:-up}" = down ]; then
	log "deleting namespace $NAMESPACE"
	kubectl --context "$KUBE_CONTEXT" delete namespace "$NAMESPACE" --ignore-not-found --wait
	exit 0
fi

wait_for_secret() {
	for _ in $(seq 60); do
		"${KUBECTL[@]}" get secret "$1" >/dev/null 2>&1 && return 0
		sleep 2
	done
	echo "Secret $1 did not appear: is its 1Password item seeded?" >&2
	exit 1
}

kubectl --context "$KUBE_CONTEXT" create namespace "$NAMESPACE" --dry-run=client -o yaml \
	| kubectl --context "$KUBE_CONTEXT" apply -f -

log "applying PostgreSQL (deploy/talos/postgres)"
kubectl --context "$KUBE_CONTEXT" kustomize --load-restrictor LoadRestrictionsNone deploy/talos/postgres \
	| "${KUBECTL[@]}" apply -f -
wait_for_secret victual-postgres-superuser
"${KUBECTL[@]}" rollout status deployment/victual-postgres --timeout=300s

log "helm upgrade --install $RELEASE, chart $CHART_VERSION"
# The namespace was `kubectl apply`'d through a kustomize overlay until ADR-0038. On the
# first install --take-ownership adopts what that apply created (Helm would otherwise refuse
# it), and --force-conflicts lets its server-side apply overwrite the fields kubectl owned.
# Only then, so a later run never takes another release's resources.
# "Never installed" means no revision ever deployed: a first install that failed leaves a
# release whose retry must adopt too.
FIRST=()
helm history "$RELEASE" --kube-context "$KUBE_CONTEXT" -n "$NAMESPACE" -o json 2>/dev/null \
	| grep -qE '"status":"(deployed|superseded)"' \
	|| FIRST=(--take-ownership --force-conflicts)
# hookOnly: wait for the pre-upgrade preflight, not for the Deployments. On a first install
# the migrate initContainer cannot log in until the roles Job has run, and that Job needs
# the two role Secrets this install's OnePasswordItems create; the waits below follow it.
helm upgrade --install "$RELEASE" "${CHART_ARGS[@]}" -f deploy/talos/values.yaml \
	${FIRST[@]+"${FIRST[@]}"} \
	--kube-context "$KUBE_CONTEXT" --namespace "$NAMESPACE" --wait=hookOnly --timeout 10m

log "waiting for the Connect operator to write the chart's three Secrets"
for secret in victual-db-migrate victual-db-app victual-bootstrap-admin; do
	wait_for_secret "$secret"
done

log "waiting for the roles, Victual and the sidecar"
"${KUBECTL[@]}" wait --for=condition=complete job/victual-db-roles --timeout=300s
"${KUBECTL[@]}" rollout status deployment/victual --timeout=600s
"${KUBECTL[@]}" rollout status deployment/victual-mcp --timeout=300s

cat <<MSG

Up at http://victual.10.130.34.240.nip.io

Log in as admin with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD from the 1Password item
victual-bootstrap-admin (it applies to the database this deployment first created).
MSG
