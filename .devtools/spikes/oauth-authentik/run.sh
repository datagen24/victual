#!/usr/bin/env bash
# Start/stop Authentik for the compatibility spike (ADR-0055 prerequisites 1/3/4, ADR-0056
# prerequisites 2-4). PostgreSQL, Authentik server and Authentik worker on one podman network,
# each memory-capped because the podman VM is shared (4 CPU / 8 GiB). Not one pod: the worker
# also binds :9000, collides with the server in a shared network namespace and shuts down.
# Throwaway credentials only.
set -euo pipefail
cd "$(dirname "$0")"
POD=victual-spike-authentik
TAG=${AUTHENTIK_TAG:-2026.8.5}
# Spike-only secrets: this instance holds no real data and listens on 127.0.0.1 only.
ENV=(-e AUTHENTIK_POSTGRESQL__HOST=$POD-pg -e AUTHENTIK_POSTGRESQL__NAME=authentik
     -e AUTHENTIK_POSTGRESQL__USER=authentik -e AUTHENTIK_POSTGRESQL__PASSWORD=spike-only-pg
     -e AUTHENTIK_SECRET_KEY=spike-only-secret-key-0123456789abcdef0123456789
     -e AUTHENTIK_BOOTSTRAP_TOKEN=spike-only-bootstrap-token-0123456789
     -e AUTHENTIK_BOOTSTRAP_PASSWORD=spike-only-akadmin
     -e AUTHENTIK_ERROR_REPORTING__ENABLED=false -e AUTHENTIK_DISABLE_UPDATE_CHECK=true
     -e AUTHENTIK_DISABLE_STARTUP_ANALYTICS=true)
case "${1:-up}" in
  up)
    "$0" down >/dev/null 2>&1 || true
    podman network create "$POD" >/dev/null
    podman run -d --network "$POD" --name "$POD-pg" --memory 384m \
      -e POSTGRES_DB=authentik -e POSTGRES_USER=authentik -e POSTGRES_PASSWORD=spike-only-pg \
      docker.io/library/postgres:16-alpine >/dev/null
    sleep 5
    podman run -d --network "$POD" --name "$POD-server" --memory 1g -p 127.0.0.1:9000:9000 \
      "${ENV[@]}" "ghcr.io/goauthentik/server:$TAG" server >/dev/null
    podman run -d --network "$POD" --name "$POD-worker" --memory 1g "${ENV[@]}" \
      "ghcr.io/goauthentik/server:$TAG" worker >/dev/null
    for _ in $(seq 1 180); do
      # Ready means the worker has applied the default blueprints setup.mjs relies on.
      curl -fsS -H "Authorization: Bearer spike-only-bootstrap-token-0123456789" \
        "http://localhost:9000/api/v3/flows/instances/?slug=default-provider-authorization-implicit-consent" 2>/dev/null \
        | grep -q '"count":1' && { echo "ready ($TAG)"; exit 0; }
      sleep 2
    done
    podman logs --tail 30 "$POD-server"; exit 1 ;;
  down)
    podman rm -f "$POD-worker" "$POD-server" "$POD-pg" >/dev/null 2>&1 || true
    podman network rm "$POD" >/dev/null 2>&1 || true ;;
  logs) podman logs "${@:3}" "$POD-${2:-server}" ;;
esac
