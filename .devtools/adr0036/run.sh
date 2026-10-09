#!/usr/bin/env bash
# Disposable runtime only: builds throwaway PostgreSQL + PHP containers, extracts the
# repository tree at HEAD plus this directory, runs one probe, and removes everything.
# Usage: .devtools/adr0036/run.sh baseline|model   (writes JSON to stdout)
set -euo pipefail
cd "$(dirname "$0")/.."
engine="${ENGINE:-podman}"
probe="${1:-baseline}"
case "$probe" in baseline|model) ;; *) echo 'Use baseline or model' >&2; exit 2;; esac
work=$(mktemp -d)
name="adr36-${probe}-$$"
cleanup() {
    "$engine" rm -f "$name-php" "$name-pg" >/dev/null 2>&1 || true
    "$engine" network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
"$engine" network create "$name" >/dev/null
"$engine" run -d --name "$name-pg" --network "$name" -e POSTGRES_PASSWORD=adr36-fixture-only \
    -e POSTGRES_DB=adr36_spike "${PG_IMAGE:-localhost/victual-pg:pgtap}" >/dev/null
"$engine" run -d --name "$name-php" --network "$name" \
    -e PGHOST="$name-pg" -e PGPORT=5432 -e PGUSER=postgres -e PGPASSWORD=adr36-fixture-only \
    -e PHPUNIT_DB_NAME=adr36_spike -e VICTUAL_DATAPATH=/tmp/adr36-data \
    -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=adr36-fixture-only -e ADR36_N="${ADR36_N:-}" "${PHP_IMAGE:-localhost/victual:dev}" sleep infinity >/dev/null
git archive HEAD | tar -x -C "$work"
cp -R .devtools/adr0036 "$work/.devtools/adr0036"
(cd "$work" && COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' -cf ../adr36-tree.tar .)
"$engine" cp "$work/../adr36-tree.tar" "$name-php:/tmp/tree.tar"
rm -f "$work/../adr36-tree.tar"
"$engine" exec "$name-php" sh -c 'cd /app && tar xf /tmp/tree.tar && mkdir -p /tmp/adr36-data && printf "<?php\n" > /tmp/adr36-data/config.php'
# -h forces TCP: the image entrypoint runs a socket-only server during first start, and the
# probe connects over TCP once the real server is up.
ready=0
for _ in {1..60}; do
    if "$engine" exec "$name-pg" pg_isready -h 127.0.0.1 -U postgres -d adr36_spike >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
if [[ "$ready" -ne 1 ]]; then echo "PostgreSQL did not accept TCP connections within 60 s" >&2; exit 1; fi
"$engine" exec "$name-php" sh -c "cd /app && php .devtools/adr0036/${probe}-probe.php"
