#!/usr/bin/env bash
# Keycloak authenticating Victual's service workloads by their Kubernetes service-account tokens
# (ADR-0057 question 6). Uses the existing kind cluster (context kind-kind-cluster) and touches only
# the kcsa-spike and kcsa-other namespaces. The Keycloak admin password is generated on first use
# into .secrets.env (gitignored) and stored in the kcsa-admin Secret; probe.mjs reads the file.
#   ./run.sh up      apply k8s.yaml and wait for Keycloak and the worker pod
#   ./run.sh down    delete both namespaces
set -euo pipefail
cd "$(dirname "$0")"
K=(kubectl --context "${KCSA_CONTEXT:-kind-kind-cluster}")
[ -f .secrets.env ] || printf 'ADMIN_PASS=%s\n' "$(openssl rand -hex 16)" > .secrets.env
# shellcheck source=/dev/null
. ./.secrets.env
case "${1:-up}" in
  up)
    "${K[@]}" apply -f k8s.yaml >/dev/null
    "${K[@]}" -n kcsa-spike create secret generic kcsa-admin --from-literal=username=admin \
      --from-literal=password="$ADMIN_PASS" --dry-run=client -o yaml | "${K[@]}" apply -f - >/dev/null
    "${K[@]}" -n kcsa-spike rollout status deploy/keycloak --timeout=600s
    "${K[@]}" -n kcsa-spike wait --for=condition=Ready pod/worker --timeout=300s ;;
  down)
    "${K[@]}" delete ns kcsa-spike kcsa-other --ignore-not-found --wait=true >/dev/null ;;
esac
