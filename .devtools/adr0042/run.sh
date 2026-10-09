#!/usr/bin/env bash
# Disposable runtime only: builds a throwaway PostgreSQL + PHP container pair, extracts the
# repository tree at HEAD plus this directory, runs probes, and removes everything.
#   .devtools/adr0042/run.sh dates [zone]   probe 1 (stdout: JSON)
#   .devtools/adr0042/run.sh status         probe 2
#   .devtools/adr0042/run.sh today          probe 3
#   .devtools/adr0042/run.sh constraints    probe 4
#   .devtools/adr0042/run.sh history        probe 5
#   .devtools/adr0042/run.sh clock <zone> <fake-start>
#                                           probe 3, live: PostgreSQL's clock is faked (libfaketime image
#                                           localhost/parity-postgres-faketime:16, start "YYYY-MM-DD HH:MM:SS"
#                                           UTC, then ticking) so CURRENT_DATE crosses a real local midnight
#   .devtools/adr0042/run.sh all            every probe; writes .devtools/adr0042/evidence/*.json
# One PostgreSQL and one PHP container at a time; names carry the pid.
set -euo pipefail
cd "$(dirname "$0")/../.."
engine="${ENGINE:-podman}"
probe="${1:-all}"
zone="${2:-UTC}"
case "$probe" in dates|status|today|constraints|history|clock|all) ;; *) echo 'Use dates, status, today, constraints, history, clock or all' >&2; exit 2;; esac
pgimage="${PG_IMAGE:-localhost/victual-pg:pgtap}"
pgenv=()
if [[ "$probe" == clock ]]; then
    pgimage="${PG_IMAGE:-localhost/parity-postgres-faketime:16}"
    pgenv=(-e "FAKETIME=@${3:?clock needs a fake start time}" -e FAKETIME_CACHE_DURATION=1)
fi
work=$(mktemp -d)
name="c42-$$"
db=adr42_spike
cleanup() {
    "$engine" rm -f "$name-php" "$name-pg" >/dev/null 2>&1 || true
    "$engine" network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
"$engine" network create "$name" >/dev/null
"$engine" run -d --name "$name-pg" --network "$name" -e POSTGRES_PASSWORD=adr42-fixture-only \
    -e POSTGRES_DB=$db ${pgenv[@]+"${pgenv[@]}"} "$pgimage" >/dev/null
"$engine" run -d --name "$name-php" --network "$name" \
    -e PGHOST="$name-pg" -e PGPORT=5432 -e PGUSER=postgres -e PGPASSWORD=adr42-fixture-only \
    -e PGDATABASE=$db "${PHP_IMAGE:-localhost/victual:dev}" sleep infinity >/dev/null
git archive HEAD | tar -x -C "$work"
mkdir -p "$work/.devtools"
cp -R .devtools/adr0042 "$work/.devtools/adr0042"
(cd "$work" && COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' -cf ../c42-tree-$$.tar .)
"$engine" cp "$work/../c42-tree-$$.tar" "$name-php:/tmp/tree.tar"
rm -f "$work/../c42-tree-$$.tar"
"$engine" exec "$name-php" sh -c 'cd /app && tar xf /tmp/tree.tar'
# -h forces TCP: the image entrypoint runs a socket-only server during first start.
ready=0
for _ in {1..60}; do
    if "$engine" exec "$name-pg" pg_isready -h 127.0.0.1 -U postgres -d $db >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
if [[ "$ready" -ne 1 ]]; then echo "PostgreSQL did not accept TCP connections within 60 s" >&2; exit 1; fi

phprun() { # phprun <zone> <script> [args]
    local z="$1"; shift
    "$engine" exec -e PHP_TIMEZONE="$z" "$name-php" php -d date.timezone="$z" -d memory_limit=1G "/app/.devtools/adr0042/$@"
}

if [[ "$probe" != all ]]; then
    case "$probe" in
        dates) phprun "$zone" dates.php ;;
        clock) phprun UTC clock.php "$zone" ;;
        *) phprun UTC "$probe.php" ;;
    esac
    exit 0
fi

ev=".devtools/adr0042/evidence"
mkdir -p "$ev"
"$engine" exec "$name-pg" postgres --version > "$ev/postgres-version.txt"
"$engine" exec "$name-php" php -v | head -1 > "$ev/php-version.txt"
for z in UTC America/New_York Pacific/Kiritimati Pacific/Pago_Pago Pacific/Apia Australia/Lord_Howe; do
    echo "dates $z" >&2
    phprun "$z" dates.php > "$ev/dates-$(echo "$z" | tr '/' '_').json"
done
for p in status today history constraints; do
    echo "$p" >&2
    phprun UTC "$p.php" > "$ev/$p.json"
done
