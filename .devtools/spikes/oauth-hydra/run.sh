#!/usr/bin/env bash
# Start/stop the spike's single Hydra container. One container, memory-capped, no Postgres:
# the podman VM is shared (4 CPU / 8 GiB) and a second PG once OOM-killed other sessions.
set -euo pipefail
cd "$(dirname "$0")"
NAME=victual-spike-hydra
case "${1:-up}" in
  up)
    podman rm -f "$NAME" >/dev/null 2>&1 || true
    podman run -d --name "$NAME" --memory 512m \
      -p 127.0.0.1:4444:4444 -p 127.0.0.1:4445:4445 \
      -v "$PWD/hydra.yml:/etc/hydra.yml:ro" \
      docker.io/oryd/hydra:latest serve all --dev -c /etc/hydra.yml
    for _ in $(seq 1 30); do
      curl -fsS http://localhost:4445/health/ready >/dev/null 2>&1 && { echo ready; exit 0; }
      sleep 1
    done
    podman logs "$NAME" | tail -20; exit 1 ;;
  down) podman rm -f "$NAME" ;;
  logs) podman logs "${@:2}" "$NAME" ;;
esac
