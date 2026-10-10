#!/usr/bin/env bash
# Issue 742 probe: who may create the master data a medication needs.
# Starts a throwaway PostgreSQL, runs Probe742Test.php through the whole middleware stack as five
# users, and prints its PROBE lines. The probe is copied into tests/Pgsql for the run and removed.
# Usage: .devtools/issue742/run.sh        (ENGINE=podman|docker, DEV_IMAGE, PG_IMAGE override defaults)
set -euo pipefail
cd "$(dirname "$0")/../.."
engine="${ENGINE:-podman}"
sfx="p742-$$"
cleanup() {
	rm -f tests/Pgsql/Probe742Test.php
	"$engine" rm -f "victual-pg-$sfx" >/dev/null 2>&1 || true
	"$engine" network rm "victual-suite-$sfx" >/dev/null 2>&1 || true
}
trap cleanup EXIT
"$engine" network create "victual-suite-$sfx" >/dev/null
"$engine" run -d --name "victual-pg-$sfx" --network "victual-suite-$sfx" \
	-e POSTGRES_USER=victual -e POSTGRES_PASSWORD=victual -e POSTGRES_DB=victual \
	--tmpfs /var/lib/postgresql/data "${PG_IMAGE:-localhost/victual-pg:pgtap}" >/dev/null
for _ in $(seq 1 60); do
	"$engine" exec "victual-pg-$sfx" pg_isready -U victual >/dev/null 2>&1 && break
	sleep 1
done
cp .devtools/issue742/Probe742Test.php tests/Pgsql/Probe742Test.php
"$engine" run --rm --network "victual-suite-$sfx" -v "$PWD":/app -v /app/packages -w /app \
	-e VICTUAL_ROOT=/app -e PGHOST="victual-pg-$sfx" -e PGPORT=5432 -e PGUSER=victual -e PGPASSWORD=victual \
	-e PHPUNIT_DB_NAME=victual_probe -e VICTUAL_DATAPATH=/tmp/probe-data "${DEV_IMAGE:-localhost/victual:dev}" bash -c '
	sleep 2
	createdb -h "$PGHOST" -U victual victual_probe
	mkdir -p /tmp/probe-data
	cat > /tmp/probe-data/config.php <<PHPC
<?php
Setting("DB_DRIVER", "pgsql");
Setting("DB_HOST", getenv("PGHOST"));
Setting("DB_PORT", intval(getenv("PGPORT")));
Setting("DB_NAME", getenv("PHPUNIT_DB_NAME"));
Setting("DB_USER", getenv("PGUSER"));
Setting("DB_PASSWORD", getenv("PGPASSWORD"));
PHPC
	php packages/bin/phpunit --bootstrap tests/bootstrap.php tests/Pgsql/Probe742Test.php 2>&1' | grep -E '^PROBE|^Tests:|^OK' || true
