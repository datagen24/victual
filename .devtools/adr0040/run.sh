#!/usr/bin/env bash
# Disposable runtime only: builds a throwaway PostgreSQL + PHP container pair, extracts the
# repository tree at HEAD plus this directory, runs one probe, and removes everything.
# Usage: .devtools/adr0040/run.sh perm|race|surface   (writes JSON to stdout)
# Environment: PG_IMAGE (default localhost/victual-pg:pgtap), PHP_IMAGE (default
# localhost/victual:dev), A40_N (race iterations per scenario, default 400),
# A40_SEED (race master seed, default 40090910), A40_CALLER (surface caller id, default 9000).
set -euo pipefail
cd "$(dirname "$0")/.."
cd ..
engine="${ENGINE:-podman}"
probe="${1:-perm}"
case "$probe" in perm|race|surface) ;; *) echo 'Use perm, race or surface' >&2; exit 2;; esac
work=$(mktemp -d)
name="a40-$$"
cleanup() {
    "$engine" rm -f "$name-php" "$name-pg" >/dev/null 2>&1 || true
    "$engine" network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
if [[ -z "${A40_KEEP:-}" ]]; then trap cleanup EXIT; fi
"$engine" network create "$name" >/dev/null
# track_commit_timestamp lets the race probe order commits with pg_xact_commit_timestamp().
"$engine" run -d --name "$name-pg" --network "$name" -e POSTGRES_PASSWORD=adr40-fixture-only \
    -e POSTGRES_DB=adr40_spike "${PG_IMAGE:-localhost/victual-pg:pgtap}" postgres -c track_commit_timestamp=on >/dev/null
"$engine" run -d --name "$name-php" --network "$name" \
    -e PGHOST="$name-pg" -e PGPORT=5432 -e PGUSER=postgres -e PGPASSWORD=adr40-fixture-only \
    -e PHPUNIT_DB_NAME=adr40_spike -e VICTUAL_DATAPATH=/tmp/adr40-data \
    -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=adr40-fixture-only \
    -e A40_N="${A40_N:-400}" -e A40_SEED="${A40_SEED:-40090910}" -e A40_CALLER="${A40_CALLER:-9000}" \
    "${PHP_IMAGE:-localhost/victual:dev}" sleep infinity >/dev/null
git archive HEAD | tar -x -C "$work"
cp -R .devtools/adr0040 "$work/.devtools/adr0040"
(cd "$work" && COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' -cf ../a40-tree-$$.tar .)
"$engine" cp "$work/../a40-tree-$$.tar" "$name-php:/tmp/tree.tar"
rm -f "$work/../a40-tree-$$.tar"
"$engine" exec "$name-php" sh -c 'cd /app && tar xf /tmp/tree.tar && mkdir -p /tmp/adr40-data && printf "<?php\n" > /tmp/adr40-data/config.php'
# -h forces TCP: the image entrypoint runs a socket-only server during first start.
ready=0
for _ in {1..60}; do
    if "$engine" exec "$name-pg" pg_isready -h 127.0.0.1 -U postgres -d adr40_spike >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
if [[ "$ready" -ne 1 ]]; then echo "PostgreSQL did not accept TCP connections within 60 s" >&2; exit 1; fi
"$engine" exec "$name-php" sh -c "cd /app && php .devtools/adr0040/${probe}-probe.php"
if [[ "$probe" == race ]]; then
    echo "server version: $("$engine" exec "$name-pg" psql -h 127.0.0.1 -U postgres -d adr40_spike -Atc 'SHOW server_version')" >&2
    echo "postgres log lines containing 'deadlock detected': $("$engine" logs "$name-pg" 2>&1 | grep -c 'deadlock detected' || true)" >&2
fi
