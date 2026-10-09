#!/usr/bin/env bash
# Disposable runtime only: one PostgreSQL container and one PHP container on a private
# network, the repository tree at HEAD (git archive) plus this directory, one job, then
# everything removed. Modeled on .devtools/adr0036/run.sh.
#
#   .devtools/adr0041/run.sh probe <name>   run probes/<name>.php, JSON on stdout
#   .devtools/adr0041/run.sh suite          probe 4: stock phases before and after the
#                                           $usedDate patch, then the dated-booking probe
#
# Environment: ENGINE (podman), PG_IMAGE (localhost/victual-pg:pgtap), PHP_IMAGE
# (localhost/victual:dev), PG_MEM (1g), PHP_MEM (2500m), plus anything a probe reads
# (B41_ROUNDS, B41_SEED, B41_N, ...).
set -euo pipefail
cd "$(dirname "$0")/../.."
engine="${ENGINE:-podman}"
mode="${1:-}"
arg="${2:-}"
case "$mode" in probe|suite) ;; *) echo 'Use: run.sh probe <name> | run.sh suite' >&2; exit 2;; esac
suffix="b41-$$"
name="adr41-${suffix}"
work=$(mktemp -d)
evidence="$(pwd)/.devtools/adr0041/evidence"
cleanup() {
    "$engine" rm -f "$name-php" "$name-pg" >/dev/null 2>&1 || true
    "$engine" network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
pgpw=adr41-fixture-only
"$engine" network create "$name" >/dev/null
# deadlock_timeout is lowered from 1 s so that a deadlock is detected in 100 ms and the
# randomized lock-order trials stay fast; max_connections covers 64 children plus the harness.
"$engine" run -d --name "$name-pg" --network "$name" --memory "${PG_MEM:-1g}" \
    -e POSTGRES_PASSWORD="$pgpw" -e POSTGRES_DB=adr41_spike "${PG_IMAGE:-localhost/victual-pg:pgtap}" \
    -c max_connections=250 -c deadlock_timeout=100ms >/dev/null
"$engine" run -d --name "$name-php" --network "$name" --memory "${PHP_MEM:-2500m}" \
    -e PGHOST="$name-pg" -e PGPORT=5432 -e PGUSER=postgres -e PGPASSWORD="$pgpw" \
    -e PHPUNIT_DB_NAME=adr41_spike -e VICTUAL_DATAPATH=/tmp/adr41-data -e VICTUAL_ROOT=/app \
    -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD="$pgpw" -e SUITE_SCRATCH=/tmp/victual-suite \
    -e B41_ROUNDS="${B41_ROUNDS:-}" -e B41_SEED="${B41_SEED:-}" -e B41_N="${B41_N:-}" \
    -e B41_TRIALS="${B41_TRIALS:-}" -e B41_QUICK="${B41_QUICK:-}" \
    "${PHP_IMAGE:-localhost/victual:dev}" sleep infinity >/dev/null
git archive HEAD | tar -x -C "$work"
rm -rf "$work/.devtools/adr0041" && cp -R .devtools/adr0041 "$work/.devtools/adr0041"
(cd "$work" && COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' -cf ../adr41-tree-$$.tar .)
"$engine" cp "$work/../adr41-tree-$$.tar" "$name-php:/tmp/tree.tar"
rm -f "$work/../adr41-tree-$$.tar"
"$engine" exec "$name-php" sh -c 'cd /app && tar xf /tmp/tree.tar && mkdir -p /tmp/adr41-data /tmp/victual-suite && printf "<?php\n" > /tmp/adr41-data/config.php && cp services/StockService.php /tmp/StockService.orig.php'
ready=0
for _ in {1..90}; do
    if "$engine" exec "$name-pg" pg_isready -h 127.0.0.1 -U postgres -d adr41_spike >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
if [[ "$ready" -ne 1 ]]; then echo "PostgreSQL did not accept TCP connections within 90 s" >&2; exit 1; fi

if [[ "$mode" == probe ]]; then
    "$engine" exec "$name-php" sh -c "cd /app && php -d memory_limit=512M .devtools/adr0041/probes/${arg}.php"
    exit 0
fi

# suite: the PHPUnit phases that reach ConsumeProduct(), one at a time, unpatched then patched.
phases=(stockcoverage stockconcurrency recipeoperations stockmaintenance)
run_phases() {
    local label="$1"
    for phase in "${phases[@]}"; do
        local log="$evidence/suite-${label}-${phase}.log"
        local start=$SECONDS rc=0
        "$engine" exec "$name-php" sh -c "cd /app && .devtools/pgsql/run-tests.sh ${phase}" >"$log" 2>&1 || rc=$?
        echo "{\"label\":\"${label}\",\"phase\":\"${phase}\",\"exit\":${rc},\"seconds\":$((SECONDS-start))}"
    done
}
echo "== before patch" >&2
run_phases before
"$engine" cp .devtools/adr0041/evidence/usedDate.patch "$name-php:/tmp/usedDate.patch"
"$engine" exec "$name-php" sh -c 'cd /app && patch -p1 --dry-run < /tmp/usedDate.patch && patch -p1 < /tmp/usedDate.patch && php -l services/StockService.php' >&2
echo "== after patch" >&2
run_phases after
"$engine" exec "$name-php" sh -c "cd /app && php -d memory_limit=512M .devtools/adr0041/probes/probe4-useddate.php"
