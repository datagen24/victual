#!/usr/bin/env bash
#
# Bring Victual and its MCP sidecar up on the Talos Raspberry Pi cluster through the Helm
# chart (ADR-0038 decision 8): the published chart and images by default, values.yaml beside
# this script, and the in-cluster PostgreSQL in deploy/talos/postgres/.
#
# Two deployments share the cluster. The default is the development app: namespace victual,
# values.yaml, and the in-cluster PostgreSQL with test data only. --prod is production:
# namespace victual-prod, deploy/talos/prod-values.yaml, and the external PostgreSQL that
# file names, whose database and roles deploy/postgres/roles.sql creates before the first
# install. prod-values.yaml names internal hosts, so it is never committed (.gitignore);
# VICTUAL_PROD_VALUES points at a copy kept outside the checkout. Start it
# from deploy/helm/victual/values.example.yaml, with secrets.onepassword.items
# set to the victual-prod-* names seed-1password.sh --prod creates.
#
#   KUBE_CONTEXT=<ctx> deploy/talos/up.sh            install or upgrade, wait for Ready
#   KUBE_CONTEXT=<ctx> deploy/talos/up.sh --local    the same from deploy/helm/victual/
#   KUBE_CONTEXT=<ctx> deploy/talos/up.sh --prod     production instead (with or without --local)
#   VICTUAL_IMAGE_TAG=<tag> KUBE_CONTEXT=<ctx> deploy/talos/up.sh [--local]
#                                                    run the images at <tag> instead of the
#                                                    chart's appVersion (an unreleased build)
#   KUBE_CONTEXT=<ctx> deploy/talos/up.sh down       delete the namespace; the database's
#                                                    claim goes with it, and nfs-csi's Delete
#                                                    reclaim policy removes its data. With
#                                                    --prod the external database is untouched
#
# KUBE_CONTEXT is required: this script never uses the current context, which on the
# maintainer's machine may be any cluster. The chart comes from
# oci://ghcr.io/datagen24/charts/victual at CHART_VERSION (version.json's Version unless set);
# --local installs the working tree's chart instead, still with the published images.
# VICTUAL_IMAGE_TAG sets image.tag for the whole release. Helm resets any value a run leaves out, so
# a deployment running an unreleased build needs VICTUAL_IMAGE_TAG on every upgrade, or the next run
# falls back to the appVersion images.
# The first published chart is 0.3.1: no earlier chart exists.
#
# The passwords come from 1Password through the cluster's Connect operator: run
# deploy/talos/seed-1password.sh [--prod] once first, or the Secrets never appear.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

: "${KUBE_CONTEXT:?set KUBE_CONTEXT to the kubectl context of the Talos cluster}"
NAMESPACE=victual
VALUES=deploy/talos/values.yaml
IN_CLUSTER_DB=1
ADMIN_ITEM=victual-bootstrap-admin
# The same release name in both namespaces: the chart's resource and Secret names are fixed,
# so every kubectl recipe works on either deployment with only -n changed.
RELEASE=victual
CHART="oci://ghcr.io/datagen24/charts/victual"
CHART_VERSION="${CHART_VERSION:-$(sed -n 's/.*"Version": *"\([^"]*\)".*/\1/p' version.json)}"
CHART_ARGS=("$CHART" --version "$CHART_VERSION")
IMAGE_ARGS=()
[ -z "${VICTUAL_IMAGE_TAG:-}" ] || IMAGE_ARGS=(--set "image.tag=$VICTUAL_IMAGE_TAG")

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

while [ $# -gt 0 ]; do
	case $1 in
	--local)
		CHART_ARGS=(deploy/helm/victual)
		CHART_VERSION="$(sed -n 's/^version: *//p' deploy/helm/victual/Chart.yaml) (local)"
		;;
	--prod)
		NAMESPACE=victual-prod
		VALUES="${VICTUAL_PROD_VALUES:-deploy/talos/prod-values.yaml}"
		IN_CLUSTER_DB=
		ADMIN_ITEM=victual-prod-bootstrap-admin
		;;
	*) break ;;
	esac
	shift
done
KUBECTL=(kubectl --context "$KUBE_CONTEXT" -n "$NAMESPACE")

if [ "${1:-up}" = down ]; then
	log "deleting namespace $NAMESPACE"
	kubectl --context "$KUBE_CONTEXT" delete namespace "$NAMESPACE" --ignore-not-found --wait
	exit 0
fi

[ -f "$VALUES" ] || {
	echo "$VALUES is missing: production's values are kept out of the repository" >&2
	exit 1
}

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

if [ -n "$IN_CLUSTER_DB" ]; then
	log "applying PostgreSQL (deploy/talos/postgres)"
	kubectl --context "$KUBE_CONTEXT" kustomize --load-restrictor LoadRestrictionsNone deploy/talos/postgres \
		| "${KUBECTL[@]}" apply -f -
	wait_for_secret victual-postgres-superuser
	"${KUBECTL[@]}" rollout status deployment/victual-postgres --timeout=300s
fi

log "helm upgrade --install $RELEASE, chart $CHART_VERSION, images ${VICTUAL_IMAGE_TAG:-from appVersion}"
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
helm upgrade --install "$RELEASE" "${CHART_ARGS[@]}" -f "$VALUES" \
	${FIRST[@]+"${FIRST[@]}"} ${IMAGE_ARGS[@]+"${IMAGE_ARGS[@]}"} \
	--kube-context "$KUBE_CONTEXT" --namespace "$NAMESPACE" --wait=hookOnly --timeout 10m

log "waiting for the Connect operator to write the chart's three Secrets"
for secret in victual-db-migrate victual-db-app victual-bootstrap-admin; do
	wait_for_secret "$secret"
done

log "waiting for Victual and the sidecar"
# The roles Job belongs to the in-cluster PostgreSQL; an external one has its roles already.
[ -z "$IN_CLUSTER_DB" ] \
	|| "${KUBECTL[@]}" wait --for=condition=complete job/victual-db-roles --timeout=300s
"${KUBECTL[@]}" rollout status deployment/victual --timeout=600s
"${KUBECTL[@]}" rollout status deployment/victual-mcp --timeout=300s

HOST="$("${KUBECTL[@]}" get ingress victual -o jsonpath='{.spec.rules[0].host}' 2>/dev/null || true)"
cat <<MSG

Up at http://${HOST:-<no ingress>}

Log in as admin with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD from the 1Password item
$ADMIN_ITEM (it applies to the database this deployment first created).
MSG
