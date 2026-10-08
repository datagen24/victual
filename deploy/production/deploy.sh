#!/usr/bin/env bash
#
# Deploy Victual from deploy/production/values.yaml. See values.example.yaml for the steps.
#
#   deploy/production/deploy.sh render    write the overlay to build/ and show what it holds
#   deploy/production/deploy.sh apply     render, apply, and wait until it serves
#   deploy/production/deploy.sh down      delete the namespace (asks first). The database
#                                         is your PostgreSQL server's and is not touched.
#
# VALUES=path/to/values.yaml names another values file.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

VALUES="${VALUES:-deploy/production/values.yaml}"
BUILD=deploy/production/build

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

python3 deploy/production/render.py "$VALUES" --out "$BUILD"
# shellcheck source=/dev/null
. "$BUILD/deploy.env"
KUBECTL=(kubectl ${CONTEXT:+--context "$CONTEXT"})
manifests() { "${KUBECTL[@]}" kustomize --load-restrictor LoadRestrictionsNone "$BUILD"; }

case "${1:-render}" in
render)
	manifests | grep -E '^kind:|^  name:' | paste - - | sed 's/^kind: //; s/\t  name: / /'
	;;
apply)
	log "applying to namespace $NAMESPACE${CONTEXT:+ in context $CONTEXT}"
	manifests | "${KUBECTL[@]}" apply -f -
	if [ -n "$OPERATOR_SECRETS" ]; then
		log "waiting for the 1Password Connect operator to write the Secrets"
		# shellcheck disable=SC2086 # a space-separated list of Secret names, split on purpose
		for secret in $OPERATOR_SECRETS; do
			for _ in $(seq 60); do
				"${KUBECTL[@]}" -n "$NAMESPACE" get secret "$secret" >/dev/null 2>&1 && break
				sleep 2
			done
			"${KUBECTL[@]}" -n "$NAMESPACE" get secret "$secret" >/dev/null \
				|| { echo "Secret $secret did not appear: check its 1Password item and field names." >&2; exit 1; }
		done
	fi
	log "waiting for Victual (the migrate step runs first)"
	"${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual --timeout=600s
	[ -z "$MCP" ] || "${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/victual-mcp --timeout=300s
	log "up at $URL"
	;;
down)
	read -r -p "Delete namespace $NAMESPACE${CONTEXT:+ in $CONTEXT}? Type its name to confirm: " answer
	[ "$answer" = "$NAMESPACE" ] || { echo "Not deleted." >&2; exit 1; }
	"${KUBECTL[@]}" delete namespace "$NAMESPACE" --wait
	;;
*)
	echo "usage: $0 [render|apply|down]" >&2
	exit 2
	;;
esac
