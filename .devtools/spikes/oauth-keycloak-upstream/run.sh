#!/usr/bin/env bash
# Keycloak brokering to Authentik as an upstream identity source (ADR-0055 question 26, ADR-0056
# question 9). Reuses the Authentik spike's stack (its PostgreSQL, server, worker, receiver) and adds
# one Keycloak in start-dev mode with its embedded file database, so the spike runs one PostgreSQL
# server. Persistence of Keycloak is not under test here. Keycloak listens on 127.0.0.1:8180 and
# reaches Authentik as host.containers.internal:9000, the same name the probe uses (it resolves it to
# 127.0.0.1 itself), so both see one Authentik issuer. Throwaway credentials only.
#   ./run.sh up | down        start or remove Authentik and Keycloak
#   ./run.sh ak-stop          stop Authentik's server and worker (an upstream outage)
#   ./run.sh ak-start         start them again and wait for Authentik
set -euo pipefail
cd "$(dirname "$0")"
AK_DIR=../oauth-authentik
KC_DIR=../oauth-keycloak
NAME=victual-spike-kcup
AK=victual-spike-authentik
TAG=${KEYCLOAK_TAG:-26.8.1}
# The Keycloak spike's lib.mjs reads the admin password from its .secrets.env; share it.
[ -f $KC_DIR/.secrets.env ] || printf 'PG_PASS=%s\nADMIN_PASS=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > $KC_DIR/.secrets.env
# shellcheck source=/dev/null
. $KC_DIR/.secrets.env

wait_kc() {
  for _ in $(seq 1 120); do
    curl -fsS http://127.0.0.1:8180/realms/master/.well-known/openid-configuration >/dev/null 2>&1 \
      && { echo "keycloak ready ($TAG)"; return 0; }
    sleep 2
  done
  podman logs --tail 40 $NAME; return 1
}
wait_ak() {
  for _ in $(seq 1 120); do
    curl -fsS http://127.0.0.1:9000/-/health/ready/ >/dev/null 2>&1 && { echo "authentik ready"; return 0; }
    sleep 2
  done
  return 1
}

case "${1:-up}" in
  up)
    podman rm -f $NAME >/dev/null 2>&1 || true
    podman container exists $AK-server && podman container inspect -f '{{.State.Running}}' $AK-server | grep -q true \
      || $AK_DIR/run.sh up
    podman run -d --name $NAME --memory 1200m -p 127.0.0.1:8180:8080 \
      -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e "KC_BOOTSTRAP_ADMIN_PASSWORD=$ADMIN_PASS" \
      -e KC_HOSTNAME=http://localhost:8180 \
      quay.io/keycloak/keycloak:$TAG start-dev >/dev/null
    wait_kc ;;
  down)
    podman rm -f $NAME >/dev/null 2>&1 || true
    $AK_DIR/run.sh down ;;
  ak-stop) podman stop -t 10 $AK-server $AK-worker >/dev/null ;;
  ak-start) podman start $AK-server $AK-worker >/dev/null; wait_ak ;;
esac
