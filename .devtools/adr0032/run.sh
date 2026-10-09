#!/usr/bin/env bash
# Disposable runtime only; does not modify application files in the checkout.
set -euo pipefail
cd "$(dirname "$0")/.."
engine="${ENGINE:-docker}"
mode="${1:-absolute}"
case "${PHP_PRECISION:-14}" in 14|17) ;; *) echo "PHP_PRECISION must be 14 or 17" >&2; exit 2;; esac
case "$mode" in baseline|absolute|relative) ;; *) echo 'Use baseline, absolute, or relative' >&2; exit 2;; esac
base=ef62f10e9387d0c251b94ad76c2cb7c1c031debe
undo=f21d09f7697c84de552ef1a3a12d7e748c3783d6
tree=$(git merge-tree --write-tree "$base" "$undo")
work=$(mktemp -d)
name="adr32-${mode}-$$"
cleanup() {
    "$engine" rm -f "$name-php" "$name-pg" >/dev/null 2>&1 || true
    "$engine" network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
# Images must already exist locally; see RESULTS.md for the measured image versions.
"$engine" network create "$name" >/dev/null
"$engine" run -d --name "$name-pg" --network "$name" -e POSTGRES_PASSWORD=adr32-fixture-only -e POSTGRES_DB=adr32_spike "${PG_IMAGE:-localhost/victual-pg:pgtap}" >/dev/null
"$engine" run -d --name "$name-php" --network "$name" \
    -e PGHOST="$name-pg" -e PGPORT=5432 -e PGUSER=postgres -e PGPASSWORD=adr32-fixture-only \
    -e PHPUNIT_DB_NAME=adr32_spike -e VICTUAL_DATAPATH=/tmp/adr32-data \
    -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=adr32-fixture-only -e ADR32_MODE="$mode" \
    -e ADR32_FAST="${ADR32_FAST:-}" "${PHP_IMAGE:-localhost/victual:dev-coord}" sleep infinity >/dev/null
git archive "$tree" | tar -x -C "$work"
if [[ "$mode" != baseline ]]; then python3 .devtools/adr0032/prepare.py "$work" "$mode"; fi
cp .devtools/adr0032/probe.php "$work/adr32-probe.php"
python3 - "$work" <<'PYTAR'
import pathlib, sys, tarfile
root = pathlib.Path(sys.argv[1])
with tarfile.open(root / 'tree.tar', 'w') as archive:
    for item in root.iterdir():
        if item.name != 'tree.tar':
            archive.add(item, arcname=item.name)
PYTAR
"$engine" cp "$work/tree.tar" "$name-php:/tmp/tree.tar"
"$engine" exec "$name-php" sh -c 'cd /app && tar xf /tmp/tree.tar && mkdir -p /tmp/adr32-data && printf "<?php\n" > /tmp/adr32-data/config.php'
for attempt in {1..30}; do
    if "$engine" exec "$name-pg" pg_isready -U postgres >/dev/null; then break; fi
    sleep 1
done
"$engine" exec "$name-php" sh -c "cd /app && php -d precision=${PHP_PRECISION:-14} adr32-probe.php"
