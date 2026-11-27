#!/usr/bin/env bash
# Start and stop the Keycloak test instance for the realm-configuration harness (plan 43).
# Keycloak 26.8.1 from the release tarball (checksum pinned below) on Java 21, with its own
# PostgreSQL 16 cluster on 127.0.0.1:55432. No container runtime is needed. Everything lives in
# the gitignored .work/ directory; the admin and database passwords are generated into
# .work/secrets.env on first use and never committed, with the two client secrets the realm
# configuration reads.
#   ./run.sh up        start PostgreSQL and Keycloak (start-dev) on 127.0.0.1:8180
#   ./run.sh stop      stop Keycloak only; the database keeps its data
#   ./run.sh start     start Keycloak again on the same database
#   ./run.sh import F [override]   run `kc.sh import --file F` against the database (Keycloak stopped)
#   ./run.sh down      stop both and delete .work/ except the downloaded tarball
set -euo pipefail
cd "$(dirname "$0")"
TAG=26.8.1
SHA256=4e2740b181faa2ea623a1c2ecf00855935311e546046e2f857615a47ed24cbe8
W=$PWD/.work
KC=$W/keycloak-$TAG
PGBIN=${PGBIN:-/usr/lib/postgresql/16/bin}
PGPORT=55432
mkdir -p "$W"
# Every credential the harness needs is generated here on first use; env.sh and lib.mjs read them.
touch "$W/secrets.env"
for k in ADMIN_PASS PG_PASS VICTUAL_CLIENT_SECRET SCIM_CLIENT_SECRET; do
  grep -q "^$k=" "$W/secrets.env" || printf '%s=%s\n' "$k" "$(openssl rand -hex 16)" >> "$W/secrets.env"
done
# shellcheck source=/dev/null
. "$W/secrets.env"

# as_pg runs a PostgreSQL binary as the postgres system user when this script runs as root,
# because initdb and postgres refuse to run as root.
as_pg() { if [ "$(id -u)" = 0 ]; then su postgres -s /bin/bash -c "$*"; else bash -c "$*"; fi; }

fetch() {
  if [ ! -d "$KC" ]; then
    [ -f "$W/keycloak-$TAG.tar.gz" ] || curl -fsSL -o "$W/keycloak-$TAG.tar.gz" \
      "https://github.com/keycloak/keycloak/releases/download/$TAG/keycloak-$TAG.tar.gz"
    echo "$SHA256  $W/keycloak-$TAG.tar.gz" | sha256sum -c --quiet
    tar xzf "$W/keycloak-$TAG.tar.gz" -C "$W"
  fi
}
pg_up() {
  if [ ! -d "$W/pg" ]; then
    mkdir -p "$W/pg"; [ "$(id -u)" = 0 ] && chown postgres "$W/pg" && chmod 755 "$W"
    printf '%s\n' "$PG_PASS" > "$W/pg.pw"; [ "$(id -u)" = 0 ] && chown postgres "$W/pg.pw"
    as_pg "$PGBIN/initdb -D $W/pg -U keycloak --pwfile=$W/pg.pw -A scram-sha-256 >/dev/null"
    rm -f "$W/pg.pw"
  fi
  as_pg "$PGBIN/pg_ctl -D $W/pg -o '-p $PGPORT -k /tmp -c listen_addresses=127.0.0.1' -l $W/pg/server.log -w start >/dev/null" || true
  PGPASSWORD=$PG_PASS psql -h 127.0.0.1 -p $PGPORT -U keycloak -d postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname='keycloak'" | grep -q 1 || \
    PGPASSWORD=$PG_PASS psql -h 127.0.0.1 -p $PGPORT -U keycloak -d postgres -qc 'CREATE DATABASE keycloak'
}
kc_env() {
  export KC_DB=postgres KC_DB_URL=jdbc:postgresql://127.0.0.1:$PGPORT/keycloak KC_DB_USERNAME=keycloak \
    KC_DB_PASSWORD=$PG_PASS KC_BOOTSTRAP_ADMIN_USERNAME=admin KC_BOOTSTRAP_ADMIN_PASSWORD=$ADMIN_PASS
}
kc_start() {
  kc_env
  nohup "$KC/bin/kc.sh" start-dev --http-host 127.0.0.1 --http-port 8180 --hostname http://localhost:8180 \
    > "$W/kc.log" 2>&1 &
  for _ in $(seq 1 120); do
    curl -fsS http://127.0.0.1:8180/realms/master/.well-known/openid-configuration >/dev/null 2>&1 \
      && { echo "keycloak $TAG ready on :8180 (PostgreSQL :$PGPORT)"; return 0; }
    sleep 2
  done
  tail -40 "$W/kc.log"; return 1
}
kc_stop() {
  pkill -f "$KC/bin/../lib/quarkus-run.jar" 2>/dev/null || true
  while pgrep -f "$KC/bin/../lib/quarkus-run.jar" >/dev/null; do sleep 1; done
}

case "${1:-up}" in
  up) fetch; pg_up; kc_stop; kc_start ;;
  start) kc_start ;;
  stop) kc_stop ;;
  import)
    kc_stop; kc_env
    "$KC/bin/kc.sh" import --file "$2" --override "${3:-false}" > "$W/import.log" 2>&1 \
      || { tail -30 "$W/import.log"; exit 1; } ;;
  down)
    kc_stop
    [ -d "$W/pg" ] && as_pg "$PGBIN/pg_ctl -D $W/pg -m fast -w stop >/dev/null" || true
    rm -rf "$W/pg" "$W/keycloak-$TAG" "$W"/*.log ;;
  *) echo "usage: $0 up|start|stop|import FILE [true|false]|down" >&2; exit 2 ;;
esac
