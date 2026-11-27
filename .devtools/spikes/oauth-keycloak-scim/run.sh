#!/usr/bin/env bash
# Keycloak 26.8.1's inbound SCIM 2.0 API (ADR-0056 questions 12 and 15). One Keycloak from the
# release tarball in start-dev mode with its embedded database, on 127.0.0.1:8180. Needs Java 21.
# Throwaway credentials only; the admin password is generated into the gitignored .admin.pw.
#   ./run.sh up | down
set -euo pipefail
cd "$(dirname "$0")"
TAG=${KEYCLOAK_TAG:-26.8.1}
case "${1:-up}" in
  up)
    [ -d keycloak-$TAG ] || { curl -fsSL -o keycloak-$TAG.tar.gz \
      https://github.com/keycloak/keycloak/releases/download/$TAG/keycloak-$TAG.tar.gz; tar xzf keycloak-$TAG.tar.gz; }
    [ -f .admin.pw ] || openssl rand -hex 16 > .admin.pw
    KC_BOOTSTRAP_ADMIN_USERNAME=admin KC_BOOTSTRAP_ADMIN_PASSWORD=$(cat .admin.pw) \
      nohup keycloak-$TAG/bin/kc.sh start-dev --http-host 127.0.0.1 --http-port 8180 > kc.log 2>&1 &
    for _ in $(seq 1 90); do
      curl -fsS http://127.0.0.1:8180/realms/master/.well-known/openid-configuration >/dev/null 2>&1 \
        && { echo "keycloak ready ($TAG)"; exit 0; }
      sleep 2
    done
    tail -40 kc.log; exit 1 ;;
  down)
    pkill -f "keycloak-$TAG/bin/../lib/quarkus-run.jar" || true
    while pgrep -f "keycloak-$TAG/bin/../lib/quarkus-run.jar" >/dev/null; do sleep 1; done
    rm -rf keycloak-$TAG/data ;;
esac
