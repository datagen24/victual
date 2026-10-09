#!/usr/bin/env bash
# Minimal, application-free reproduction of the S7 deadlock: two sessions take SELECT ... FOR SHARE
# on one row and then UPDATE it. Needs only a PostgreSQL container. Prints each session's result
# and the server's deadlock report, then repeats with FOR UPDATE as the control.
# Usage: .devtools/adr0040/repro.sh   (PG_IMAGE selects the server image)
set -euo pipefail
engine="${ENGINE:-podman}"
name="a40-$$-repro"
cleanup() { "$engine" rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
"$engine" run -d --name "$name" -e POSTGRES_PASSWORD=adr40-fixture-only -e POSTGRES_DB=adr40_repro \
    "${PG_IMAGE:-localhost/victual-pg:pgtap}" postgres >/dev/null
for _ in {1..60}; do
    if "$engine" exec "$name" pg_isready -h 127.0.0.1 -U postgres -d adr40_repro >/dev/null 2>&1; then break; fi
    sleep 1
done
psql() { "$engine" exec -i "$name" psql -h 127.0.0.1 -U postgres -d adr40_repro -X -q -v ON_ERROR_STOP=0 "$@"; }
echo "server: $(psql -Atc 'SHOW server_version')"
psql -c "CREATE TABLE r (id int PRIMARY KEY, name text); INSERT INTO r VALUES (1, 'start');"
for mode in "FOR SHARE" "FOR UPDATE"; do
    echo "--- two sessions: BEGIN; SELECT id FROM r WHERE id = 1 $mode; pg_sleep(1); UPDATE r ...; COMMIT;"
    for who in A B; do
        ( psql -c "BEGIN; SELECT id FROM r WHERE id = 1 $mode; SELECT pg_sleep(1); UPDATE r SET name = '$who' WHERE id = 1; COMMIT;" 2>&1 | sed "s/^/session $who: /" ) &
    done
    wait
done
echo "--- server log"
"$engine" logs "$name" 2>&1 | grep -A6 'deadlock detected' | head -20
