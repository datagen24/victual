#!/usr/bin/env bash
# Backup and restore drill against the published v0.5.0 images (issue #1010, release gate R5).
#
# Follows docs/manual/operator/backup-restore.md on a disposable PostgreSQL, using the
# deployment shape deploy/README.md documents for a Mac with podman: deploy/postgres/roles.sql
# for the two roles, and deploy/podman/victual.yaml through `podman kube play` for the pod.
#
#   .devtools/pgsql/restore-drill/v0.5.0/drill.sh <output directory>
#
# Needs podman and the three images pulled by digest (the digests are below). Every command
# it runs and what it printed goes to <output directory>/drill.log; the comparisons go to
# files beside it. It takes the machine-wide suite lock, because it runs PostgreSQL and a
# pod on the same VM as the test suites. Nothing here touches a real database: the server
# is a container this script starts and removes.
#
# The passwords below are local-only, as in deploy/README.md's walkthrough.
set -euo pipefail

OUT=${1:?usage: drill.sh <output directory>}
REPO=$(cd "$(dirname "$0")/../../../.." && pwd)
HERE=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$OUT"
LOG="$OUT/drill.log"
: > "$LOG"

MIGRATE_IMAGE=ghcr.io/datagen24/victual-migrate@sha256:0dd533652d32f8937274a72a8d83e33a5d6ad143fc4c9ff610e2387e02e75ecc
APP_IMAGE=ghcr.io/datagen24/victual-app@sha256:c85a6e4074374c07a97c4a7218446aabf9f51789dbc286e0504257111fe8b55f
WEB_IMAGE=ghcr.io/datagen24/victual-web@sha256:b8bf0c282f8c3d4a6cae0f5e6b2d69c7d980db7ca5a8b830bb3d0a37dcb3b746
# The migrate image's PHP and application root, from `podman image inspect` of that digest.
MIGRATE_PHP=/nix/store/ladw66sn33rg9arjyxjh5jvp2lyiyh5i-php-with-extensions-8.5.10/bin/php
CODE=/nix/store/609w02h4yzlghnyr8n6m0sdmb5ibvw2p-victual-0.5.0/share/php/victual

PG=restore-drill-pg
PG_PORT=55440
SOURCE=victual_source
TARGET=victual_restored
MIGRATE_PASSWORD=victual-migrate
APP_PASSWORD=victual-app
ADMIN_PASSWORD=victual-admin
LOCK="$HOME/.victual-suite-lock"

# Prints a timestamped line to the terminal and the log.
say() { printf '%s %s\n' "$(date -u +%H:%M:%SZ)" "$*" | tee -a "$LOG"; }

# Runs a command, logging the command line and its combined output; returns its exit status.
run() {
	say "\$ $*"
	local status=0
	"$@" >>"$LOG" 2>&1 || status=$?
	say "  exit $status"
	return $status
}

# Seconds since the epoch, with fractions, for the timings in the report.
now() { python3 -c 'import time; print(f"{time.time():.3f}")'; }

# Records how long a named step took, given its start time from now().
took() { python3 -c "import sys; print(f'{sys.argv[1]}: {float(sys.argv[3]) - float(sys.argv[2]):.1f} s')" "$1" "$2" "$(now)" | tee -a "$OUT/timings.txt" >>"$LOG"; }

# psql as the bootstrap superuser, inside the PostgreSQL container, against a database.
psql_super() { local db=$1; shift; podman exec -i "$PG" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$db" "$@"; }

# Takes the suite lock and arranges for this script's containers and the lock to go on exit.
take_lock() {
	until mkdir "$LOCK" 2>/dev/null; do say "waiting for $LOCK"; sleep 30; done
	trap teardown EXIT
}

# Removes the pod, the PostgreSQL container and the lock. Safe to run more than once.
teardown() {
	podman pod rm -f victual >/dev/null 2>&1 || true
	podman secret rm victual-db-migrate victual-db-app victual-bootstrap-admin >/dev/null 2>&1 || true
	podman rm -f "$PG" >/dev/null 2>&1 || true
	rmdir "$LOCK" 2>/dev/null || true
}

# Gives the digest-pulled images the localhost names deploy/podman/victual.yaml expects, so
# the pod spec is used unchanged. Refuses if those names already point somewhere else.
tag_images() {
	local pair name image
	for pair in "victual-migrate:$MIGRATE_IMAGE" "victual-app:$APP_IMAGE" "victual-web:$WEB_IMAGE"; do
		name=${pair%%:*}; image=${pair#*:}
		if podman image exists "localhost/$name:0.5.0" && [ "$(podman image inspect "localhost/$name:0.5.0" --format '{{.Id}}')" != "$(podman image inspect "$image" --format '{{.Id}}')" ]; then
			say "localhost/$name:0.5.0 already names a different image; refusing to retag it"; exit 1
		fi
		run podman tag "$image" "localhost/$name:0.5.0"
	done
	podman image inspect "$MIGRATE_IMAGE" "$APP_IMAGE" "$WEB_IMAGE" --format '{{index .RepoDigests 0}} id={{.Id}} {{.Architecture}}' > "$OUT/images.txt"
}

# Starts the throwaway PostgreSQL 16 and waits for it over TCP, as deploy/README.md says.
start_postgres() {
	run podman run -d --name "$PG" --memory 1536m -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=admin \
		-e POSTGRES_DB=postgres -p "$PG_PORT:5432" docker.io/library/postgres:16
	until podman exec "$PG" pg_isready -q -h 127.0.0.1 -U postgres -d postgres; do sleep 1; done
	{ psql_super postgres -At -c 'SELECT version()'; podman exec "$PG" pg_dump --version; podman exec "$PG" pg_restore --version; } > "$OUT/postgres.txt"
}

# Creates an empty database, as the superuser.
create_database() { run psql_super postgres -c "CREATE DATABASE $1"; }

# Drops a database if it exists, as the superuser.
drop_database() { run psql_super postgres -c "DROP DATABASE IF EXISTS $1 WITH (FORCE)"; }

# Applies deploy/postgres/roles.sql to a database, with the passwords in psql's environment.
apply_roles() {
	say "\$ roles.sql -v db=$1"
	MIGRATE_PASSWORD=$MIGRATE_PASSWORD APP_PASSWORD=$APP_PASSWORD podman exec -i -e MIGRATE_PASSWORD -e APP_PASSWORD "$PG" \
		psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$1" -v db="$1" < "$REPO/deploy/postgres/roles.sql" >>"$LOG" 2>&1
	say "  exit 0"
}

# The pod's ConfigMap and Secrets, as deploy/README.md's walkthrough writes them, for a database.
pod_objects() {
	cat <<YAML
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: victual-config
data:
  VICTUAL_MODE: production
  VICTUAL_DB_DRIVER: pgsql
  VICTUAL_DB_HOST: host.containers.internal
  VICTUAL_DB_PORT: "$PG_PORT"
  VICTUAL_DB_NAME: $1
  VICTUAL_FILE_STORAGE: database
  VICTUAL_BASE_URL: http://localhost:8080
---
apiVersion: v1
kind: Secret
metadata:
  name: victual-db-migrate
stringData:
  VICTUAL_DB_USER: victual_migrate
  VICTUAL_DB_PASSWORD: $MIGRATE_PASSWORD
---
apiVersion: v1
kind: Secret
metadata:
  name: victual-bootstrap-admin
stringData:
  VICTUAL_BOOTSTRAP_ADMIN_PASSWORD: $ADMIN_PASSWORD
---
apiVersion: v1
kind: Secret
metadata:
  name: victual-db-app
stringData:
  VICTUAL_DB_USER: victual_app
  VICTUAL_DB_PASSWORD: $APP_PASSWORD
YAML
}

# Starts the v0.5.0 pod against a database and waits until nginx answers /login.
pod_up() {
	say "\$ podman kube play deploy/podman/victual.yaml (VICTUAL_DB_NAME=$1)"
	{ cat "$REPO/deploy/podman/victual.yaml"; pod_objects "$1"; } | podman kube play - >>"$LOG" 2>&1
	local tries=0
	until curl -fsS -o /dev/null http://localhost:8080/login; do
		tries=$((tries + 1)); [ $tries -lt 120 ] || { say "pod did not answer /login"; podman pod logs victual >>"$LOG" 2>&1 || true; exit 1; }
		sleep 1
	done
	podman logs victual-migrate >"$OUT/pod-migrate-$1.log" 2>&1 || true
	say "  pod serving against $1"
}

# Stops the pod and removes the ConfigMap and Secrets podman kept for it.
pod_down() { run podman pod rm -f victual; podman secret rm victual-db-migrate victual-db-app victual-bootstrap-admin >/dev/null 2>&1 || true; }

# Runs a PHP script from this directory inside the migrate image, as victual_migrate. The
# script is mounted at <code>/.devtools/, beside the application, and its arguments follow.
php_in_migrate_image() {
	local db=$1 script=$2 target=$3; shift 3
	podman run --rm --entrypoint "$MIGRATE_PHP" -v "$script:$CODE/$target:ro" \
		-e VICTUAL_MODE=production -e VICTUAL_DB_DRIVER=pgsql -e VICTUAL_DB_HOST=host.containers.internal \
		-e VICTUAL_DB_PORT="$PG_PORT" -e VICTUAL_DB_NAME="$db" -e VICTUAL_DB_USER=victual_migrate \
		-e VICTUAL_DB_PASSWORD="$MIGRATE_PASSWORD" -e VICTUAL_FILE_STORAGE=database \
		"$MIGRATE_IMAGE" "$CODE/$target" "$@"
}

# Runs the v0.5.0 migrator against a database, as the pod's initContainer does: the
# migrate role, and VICTUAL_BOOTSTRAP_ADMIN_PASSWORD set because the Secret forces it.
migrate() {
	podman run --rm -e VICTUAL_MODE=production -e VICTUAL_DB_DRIVER=pgsql -e VICTUAL_DB_HOST=host.containers.internal \
		-e VICTUAL_DB_PORT="$PG_PORT" -e VICTUAL_DB_NAME="$1" -e VICTUAL_DB_USER=victual_migrate \
		-e VICTUAL_DB_PASSWORD="$MIGRATE_PASSWORD" -e VICTUAL_FILE_STORAGE=database \
		-e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD="$ADMIN_PASSWORD" "$MIGRATE_IMAGE"
}

# Calls the API through the pod with the drill's key; prints the body and fails on a non-2xx.
api() {
	local method=$1 path=$2; shift 2
	curl -fsS -X "$method" -H "VICTUAL-API-KEY: $API_KEY" "$@" "http://localhost:8080/api$path"
}

# Writes the comparison files for one database under $OUT/<label>/: normalized schema and
# data dumps and their SHA-256 (the data dump order-free), per-table row counts, sequence positions, stock totals and
# the SHA-256 of every stored file.
snapshot() {
	local db=$1 dir="$OUT/$2"
	mkdir -p "$dir"
	podman exec "$PG" pg_dump -U postgres --schema-only "$db" | grep -v -E '^\\(un)?restrict ' > "$dir/schema.sql"
	podman exec "$PG" pg_dump -U postgres --data-only "$db" 2>/dev/null | grep -v -E '^\\(un)?restrict ' > "$dir/data.sql"
	# pg_dump orders tables that share circular foreign keys by object id, which a restore
	# renumbers, so the data dump is hashed with its lines sorted: same rows, any order.
	sort "$dir/data.sql" > "$dir/data-sorted.sql"
	shasum -a 256 "$dir/schema.sql" "$dir/data-sorted.sql" | sed "s|$dir/||" > "$dir/dump-sha256.txt"
	psql_super "$db" -At -F ' ' -c "SELECT table_name, (xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM public.%I', table_name), false, true, '')))[1]::text FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY 1" > "$dir/row-counts.txt"
	psql_super "$db" -At -F ' ' -c "SELECT sequencename, coalesce(last_value::text, 'unset') FROM pg_sequences WHERE schemaname = 'public' ORDER BY 1" > "$dir/sequences.txt"
	psql_super "$db" -At -F ' ' -c "SELECT product_id, sum(amount), count(*) FROM stock GROUP BY product_id ORDER BY product_id" > "$dir/stock-totals.txt"
	psql_super "$db" -At -F ' ' -c "SELECT file_group, name, size_bytes, encode(sha256(content), 'hex') FROM files ORDER BY file_group, name" > "$dir/files-sha256.txt"
	psql_super "$db" -At -c "SELECT max(migration) FROM migrations" > "$dir/migration.txt"
}

# Tries the manual's restore exactly as written, into a fresh empty database with no
# roles.sql, as the given role. Records the outcome; never stops the drill.
probe_restore_as_written() {
	local role=$1 password=$2 label=$3
	drop_database "$TARGET"; create_database "$TARGET"
	say "probe $label: pg_restore --exit-on-error as $role into an empty database, no roles.sql"
	local status=0
	podman exec -e PGPASSWORD="$password" "$PG" pg_restore --exit-on-error -h 127.0.0.1 -U "$role" -d "$TARGET" /tmp/victual.dump >"$OUT/probe-$label.txt" 2>&1 || status=$?
	echo "exit $status" >> "$OUT/probe-$label.txt"
	say "  probe $label exit $status: $(head -1 "$OUT/probe-$label.txt")"
}

# Tries the restore as the given role after roles.sql has prepared the empty database.
probe_restore_with_roles() {
	local role=$1 password=$2 label=$3
	drop_database "$TARGET"; create_database "$TARGET"; apply_roles "$TARGET"
	say "probe $label: pg_restore --exit-on-error as $role after roles.sql"
	local status=0
	podman exec -e PGPASSWORD="$password" "$PG" pg_restore --exit-on-error -h 127.0.0.1 -U "$role" -d "$TARGET" /tmp/victual.dump >"$OUT/probe-$label.txt" 2>&1 || status=$?
	echo "exit $status" >> "$OUT/probe-$label.txt"
	say "  probe $label exit $status: $(head -1 "$OUT/probe-$label.txt")"
	return $status
}

# --- The drill -------------------------------------------------------------------------------

take_lock
tag_images
start_postgres

# 1. The source: a v0.5.0 instance on database storage, seeded through the artifact's code.
t=$(now)
create_database "$SOURCE"
apply_roles "$SOURCE"
pod_up "$SOURCE"
took "source pod up (migrate + serve)" "$t"
t=$(now)
say "\$ ledger-generator.php --products=25 --days=120 --seed=1010 --reconcile (in the migrate image)"
php_in_migrate_image "$SOURCE" "$REPO/.devtools/pgsql/ledger-generator.php" .devtools/pgsql/ledger-generator.php \
	--products=25 --days=120 --seed=1010 --reconcile | tee "$OUT/ledger.txt" >>"$LOG"
took "ledger generation" "$t"
API_KEY=$(php_in_migrate_image "$SOURCE" "$HERE/apikey.php" .devtools/apikey.php | tail -1)
[ -n "$API_KEY" ] || { say "no API key"; exit 1; }
for i in 1 2 3; do
	head -c $((i * 40000 + 123)) /dev/urandom > "$OUT/userfile-$i.bin"
	name=$(printf 'restore-drill-%d.zip' "$i" | base64)
	# The documented content type matters: with curl's default form type the framework parses
	# the body first, and v0.5.0 then stores an empty file and still answers 204.
	api PUT "/files/userfiles/$name" -H 'Content-Type: application/octet-stream' --data-binary "@$OUT/userfile-$i.bin" >>"$LOG"
	say "uploaded userfile $i ($(wc -c < "$OUT/userfile-$i.bin" | tr -d ' ') bytes)"
done
shasum -a 256 "$OUT"/userfile-*.bin | sed "s|$OUT/||" > "$OUT/userfiles-uploaded-sha256.txt"
pod_down
snapshot "$SOURCE" source
# The source has to hold what was uploaded, or the restore comparison proves nothing.
for i in 1 2 3; do
	stored=$(psql_super "$SOURCE" -At -c "SELECT encode(sha256(content), 'hex') FROM files WHERE file_group = 'userfiles' AND name = 'restore-drill-$i.zip'")
	uploaded=$(shasum -a 256 "$OUT/userfile-$i.bin" | cut -d' ' -f1)
	[ "$stored" = "$uploaded" ] || { say "userfile $i: stored $stored, uploaded $uploaded"; exit 1; }
done
say "the source stores the three userfiles byte for byte"

# 2. The dump, as the manual writes it: pg_dump -Fc, here as the application's role.
t=$(now)
run podman exec -e PGPASSWORD="$APP_PASSWORD" "$PG" sh -c "pg_dump -Fc -h 127.0.0.1 -U victual_app $SOURCE > /tmp/victual.dump"
took "pg_dump -Fc as victual_app" "$t"
podman exec "$PG" ls -l /tmp/victual.dump >> "$OUT/postgres.txt"

# 3. The restore as the manual writes it, then with the deployment's role setup.
probe_restore_as_written victual_app "$APP_PASSWORD" a-app-no-roles || true
probe_restore_as_written victual_migrate "$MIGRATE_PASSWORD" b-migrate-no-roles || true
probe_restore_with_roles victual_app "$APP_PASSWORD" c-app-with-roles || true
t=$(now)
probe_restore_with_roles victual_migrate "$MIGRATE_PASSWORD" d-migrate-with-roles
took "roles.sql + pg_restore as victual_migrate" "$t"
snapshot "$TARGET" restored-before-migrate

# 4. The migrator from the same version, which must change nothing.
t=$(now)
migrate "$TARGET" > "$OUT/migrate-on-restore.txt" 2>&1
took "victual-migrate on the restore" "$t"
snapshot "$TARGET" restored

# 5. The comparison: every file under source/ against restored/, then against the restore
#    before the migrator ran.
for f in dump-sha256.txt row-counts.txt sequences.txt stock-totals.txt files-sha256.txt migration.txt; do
	if cmp -s "$OUT/source/$f" "$OUT/restored/$f"; then echo "same $f"; else echo "DIFFERENT $f"; fi
done > "$OUT/comparison.txt"
if cmp -s "$OUT/restored-before-migrate/dump-sha256.txt" "$OUT/restored/dump-sha256.txt"; then
	echo "migrator changed nothing (dump-sha256.txt)"
else
	echo "MIGRATOR CHANGED dump-sha256.txt"
fi >> "$OUT/comparison.txt"
cat "$OUT/comparison.txt" >> "$LOG"

# 6. The restored instance serves: the userfiles' bytes, then a purchase and its undo.
pod_up "$TARGET"
for i in 1 2 3; do
	name=$(printf 'restore-drill-%d.zip' "$i" | base64)
	api GET "/files/userfiles/$name" -o "$OUT/userfile-$i.served"
done
shasum -a 256 "$OUT"/userfile-*.served | sed "s|$OUT/||; s|\.served|.bin|" > "$OUT/userfiles-served-sha256.txt"
if cmp -s "$OUT/userfiles-uploaded-sha256.txt" "$OUT/userfiles-served-sha256.txt"; then echo "same userfile bytes served" >> "$OUT/comparison.txt"; else echo "DIFFERENT userfile bytes served" >> "$OUT/comparison.txt"; fi
product=$(psql_super "$TARGET" -At -c "SELECT min(product_id) FROM stock")
before=$(psql_super "$TARGET" -At -c "SELECT coalesce(sum(amount), 0) FROM stock WHERE product_id = $product")
log_before=$(psql_super "$TARGET" -At -c "SELECT max(id) FROM stock_log")
api POST "/stock/products/$product/add" -H 'Content-Type: application/json' --data '{"amount": 3, "price": 1.25}' > "$OUT/roundtrip-add.json"
transaction=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); r=d[0] if isinstance(d, list) else d; print(r["transaction_id"])' "$OUT/roundtrip-add.json")
booking=$(psql_super "$TARGET" -At -c "SELECT min(id) FROM stock_log WHERE transaction_id = '$transaction'")
during=$(psql_super "$TARGET" -At -c "SELECT coalesce(sum(amount), 0) FROM stock WHERE product_id = $product")
api POST "/stock/transactions/$transaction/undo" > "$OUT/roundtrip-undo.json"
after=$(psql_super "$TARGET" -At -c "SELECT coalesce(sum(amount), 0) FROM stock WHERE product_id = $product")
{
	echo "product $product: stock $before before, $during after the purchase, $after after its undo"
	echo "stock_log max id before the purchase $log_before; the purchase's first booking id $booking (transaction $transaction)"
} | tee "$OUT/roundtrip.txt" >> "$LOG"
pod_down
say "drill finished; results in $OUT"
