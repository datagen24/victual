#!/usr/bin/env bash
# Disposable runtime only: builds throwaway PostgreSQL + PHP containers, extracts the
# repository tree at HEAD plus this directory, runs one probe, and removes everything.
# Usage: .spike-adr37/run.sh baseline|model   (writes JSON to stdout)
set -euo pipefail
cd "$(dirname "$0")/.."
engine="${ENGINE:-podman}"
probe="${1:-baseline}"
case "$probe" in baseline|model) ;; *) echo 'Use baseline or model' >&2; exit 2;; esac
work=$(mktemp -d)
name="adr37-${probe}-$$"
cleanup() {
    "$engine" rm -f "$name-php" "$name-pg" >/dev/null 2>&1 || true
    "$engine" network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
"$engine" network create "$name" >/dev/null
"$engine" run -d --name "$name-pg" --network "$name" -e POSTGRES_PASSWORD=adr37-fixture-only \
    -e POSTGRES_DB=adr37_spike "${PG_IMAGE:-localhost/victual-pg:pgtap}" >/dev/null
"$engine" run -d --name "$name-php" --network "$name" \
    -e PGHOST="$name-pg" -e PGPORT=5432 -e PGUSER=postgres -e PGPASSWORD=adr37-fixture-only \
    -e PHPUNIT_DB_NAME=adr37_spike -e VICTUAL_DATAPATH=/tmp/adr37-data \
    -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=adr37-fixture-only -e ADR37_N="${ADR37_N:-}" "${PHP_IMAGE:-localhost/victual:dev}" sleep infinity >/dev/null
git archive HEAD | tar -x -C "$work"
cp -R .spike-adr37 "$work/.spike-adr37"
(cd "$work" && COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' -cf ../adr37-tree.tar .)
"$engine" cp "$work/../adr37-tree.tar" "$name-php:/tmp/tree.tar"
rm -f "$work/../adr37-tree.tar"
"$engine" exec "$name-php" sh -c 'cd /app && tar xf /tmp/tree.tar && mkdir -p /tmp/adr37-data && printf "<?php\n" > /tmp/adr37-data/config.php'
# -h forces TCP: the image entrypoint runs a socket-only server during first start, and the
# probe connects over TCP once the real server is up.
ready=0
for _ in {1..60}; do
    if "$engine" exec "$name-pg" pg_isready -h 127.0.0.1 -U postgres -d adr37_spike >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
if [[ "$ready" -ne 1 ]]; then echo "PostgreSQL did not accept TCP connections within 60 s" >&2; exit 1; fi
"$engine" exec "$name-php" sh -c "cd /app && php .spike-adr37/${probe}-probe.php"
