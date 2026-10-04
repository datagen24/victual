#!/usr/bin/env bash
# Disposable runtime only: builds throwaway PostgreSQL + PHP containers, extracts the
# repository tree at HEAD plus this directory, runs one probe, and removes everything.
# Usage: .spike-adr36/run.sh baseline|model   (writes JSON to stdout)
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
cp -R .spike-adr36 "$work/.spike-adr36"
(cd "$work" && COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' -cf ../adr36-tree.tar .)
"$engine" cp "$work/../adr36-tree.tar" "$name-php:/tmp/tree.tar"
rm -f "$work/../adr36-tree.tar"
"$engine" exec "$name-php" sh -c 'cd /app && tar xf /tmp/tree.tar && mkdir -p /tmp/adr36-data && printf "<?php\n" > /tmp/adr36-data/config.php'
for attempt in {1..60}; do
    if "$engine" exec "$name-pg" pg_isready -U postgres >/dev/null 2>&1; then break; fi
    sleep 1
done
"$engine" exec "$name-php" sh -c "cd /app && php .spike-adr36/${probe}-probe.php"
