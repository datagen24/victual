#!/usr/bin/env bash
# Start/stop Keycloak for the compatibility spike (ADR-0055 decision 2, prerequisites 3 and 4).
# PostgreSQL 16 and Keycloak on one podman network, each memory-capped because the podman VM is
# shared (4 CPU / 8 GiB). Binds 127.0.0.1:8180 only. Throwaway credentials only.
#   ./run.sh up [preview]   start; "preview" adds the delegation preview features
#   ./run.sh down           remove the containers and the network
#   ./run.sh restart        restart the Keycloak server container (same PostgreSQL database)
#   ./run.sh stats          memory use of both containers
#   ./run.sh readonly       start a second instance on a read-only root filesystem (port 8181)
#   ./run.sh logs [server|pg] [podman logs flags]
set -euo pipefail
cd "$(dirname "$0")"
NAME=victual-spike-keycloak
TAG=${KEYCLOAK_TAG:-26.8.1}
IMAGE=quay.io/keycloak/keycloak:$TAG
PORT=8180
# Spike-only secrets, generated on first use into .secrets.env (gitignored; lib.mjs reads it).
# This instance holds no real data and listens on 127.0.0.1 only.
[ -f .secrets.env ] || printf 'PG_PASS=%s\nADMIN_PASS=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > .secrets.env
# shellcheck source=/dev/null
. ./.secrets.env
KC_ENV=(-e KC_DB=postgres -e KC_DB_URL=jdbc:postgresql://$NAME-pg:5432/keycloak
        -e KC_DB_USERNAME=keycloak -e "KC_DB_PASSWORD=$PG_PASS"
        -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e "KC_BOOTSTRAP_ADMIN_PASSWORD=$ADMIN_PASS"
        -e KC_HTTP_ENABLED=true -e KC_HEALTH_ENABLED=true)

wait_ready() { # $1 = port, $2 = container
  for _ in $(seq 1 120); do
    curl -fsS "http://127.0.0.1:$1/realms/master/.well-known/openid-configuration" >/dev/null 2>&1 \
      && { echo "ready ($TAG, :$1)"; return 0; }
    sleep 2
  done
  podman logs --tail 40 "$2"; return 1
}

case "${1:-up}" in
  up)
    "$0" down >/dev/null 2>&1 || true
    FEATURES=()
    if [ "${2:-}" = preview ]; then
      FEATURES=(--features=token-exchange-delegation,parameterized-scopes,admin-fine-grained-authz:v2)
    fi
    podman network create "$NAME" >/dev/null
    podman run -d --network "$NAME" --name "$NAME-pg" --memory 384m \
      -e POSTGRES_DB=keycloak -e POSTGRES_USER=keycloak -e "POSTGRES_PASSWORD=$PG_PASS" \
      docker.io/library/postgres:16-alpine >/dev/null
    for _ in $(seq 1 30); do
      podman exec "$NAME-pg" pg_isready -U keycloak >/dev/null 2>&1 && break; sleep 1
    done
    podman run -d --network "$NAME" --name "$NAME-server" --memory 1200m -p 127.0.0.1:$PORT:8080 \
      "${KC_ENV[@]}" -e KC_HOSTNAME=http://localhost:$PORT \
      "$IMAGE" start-dev ${FEATURES[@]+"${FEATURES[@]}"} >/dev/null
    wait_ready $PORT "$NAME-server" ;;
  readonly)
    # Build step baked into a throwaway image, then run with a read-only root filesystem.
    podman rm -f "$NAME-ro-build" "$NAME-ro" >/dev/null 2>&1 || true
    podman run --name "$NAME-ro-build" --memory 1200m "$IMAGE" build --db=postgres --health-enabled=true >/dev/null 2>&1
    podman commit "$NAME-ro-build" localhost/$NAME-ro:$TAG >/dev/null
    podman rm -f "$NAME-ro-build" >/dev/null
    podman exec "$NAME-pg" psql -U keycloak -c 'CREATE DATABASE keycloak_ro' >/dev/null 2>&1 || true
    podman run -d --network "$NAME" --name "$NAME-ro" --memory 1200m --read-only \
      --tmpfs /tmp --tmpfs /opt/keycloak/data:rw,mode=1777 -p 127.0.0.1:8181:8080 \
      -e KC_DB=postgres -e KC_DB_URL=jdbc:postgresql://$NAME-pg:5432/keycloak_ro \
      -e KC_DB_USERNAME=keycloak -e "KC_DB_PASSWORD=$PG_PASS" \
      -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e "KC_BOOTSTRAP_ADMIN_PASSWORD=$ADMIN_PASS" \
      -e KC_HTTP_ENABLED=true -e KC_HEALTH_ENABLED=true -e KC_HOSTNAME=http://localhost:8181 \
      localhost/$NAME-ro:$TAG start --optimized >/dev/null
    wait_ready 8181 "$NAME-ro" ;;
  restart)
    podman restart "$NAME-server" >/dev/null
    wait_ready $PORT "$NAME-server" ;;
  down)
    podman rm -f "$NAME-ro" "$NAME-ro-build" "$NAME-server" "$NAME-pg" >/dev/null 2>&1 || true
    podman rmi -f localhost/$NAME-ro:$TAG >/dev/null 2>&1 || true
    podman network rm "$NAME" >/dev/null 2>&1 || true ;;
  stats)
    podman stats --no-stream --format '{{.Name}} {{.MemUsage}} {{.CPUPerc}}' "$NAME-server" "$NAME-pg" ;;
  logs) podman logs "${@:3}" "$NAME-${2:-server}" ;;
esac
