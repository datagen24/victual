#!/usr/bin/env bash
# Lifecycle coordination spike for plan 35 (docs/plans/35-home-assistant-target.md,
# "Lifecycle spike"). Container-level only: podman on a developer machine, not a Supervisor.
#
# The old side is the published v0.2.0-MVP images (schema at migration 0288). The new side
# is this source tree run in the dev image (schema at the tree's latest migration). The
# "serving check" is what a serving add-on could observe with the application role alone:
# the HTTP status of /login through SchemaVersionMiddleware, and the migration advisory lock
# in pg_locks.
#
# Usage: spike.sh <case>...   cases: setup s1 s2 s3 s4 s5 s6 s7 s8 single teardown
# Output goes to stdout; pipe it into the evidence record.
#
# Debug output is off by default. With SPIKE_DEBUG=1 the harness also writes to stderr:
#   - every podman and psql command before it runs;
#   - every SPIKE_SAMPLE_SECONDS (default 10) while a migrator runs, each non-idle
#     victual_migrate and victual_app session: transaction age, statement age, wait event,
#     and the start of the statement, plus the highest committed migration;
#   - PostgreSQL's slow-statement log, including statements inside functions, with their
#     duration and plan (auto_explain, threshold SPIKE_SLOW_MS, default 1000). auto_explain
#     is loaded when the database container starts, so run `setup` with SPIKE_DEBUG=1.
#     SPIKE_EXPLAIN_ANALYZE=1 adds actual row counts and loop counts to those plans; it
#     slows the statements it measures. Also set at `setup`.
# Migrator output is shown in full in debug mode instead of its last three lines.
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
NET=ha-spike
PG=ha-spike-pg
DB=victual
SUPER_PW=spike-super
MIGRATE_PW=spike-migrate
APP_PW=spike-app
ADMIN_PW=spike-admin-password
LOCK_KEY=1986947956
OLD_TAG=0.2.0-MVP
DEV_IMAGE=localhost/victual:dev-ha654
OLD_PORT=18180
NEW_PORT=18181

SPIKE_DEBUG=${SPIKE_DEBUG:-0}
SPIKE_SAMPLE_SECONDS=${SPIKE_SAMPLE_SECONDS:-10}
SPIKE_SLOW_MS=${SPIKE_SLOW_MS:-1000}

ts() { date -u +%H:%M:%S; }
log() { echo "[$(ts)] $*"; }
debug() { [ "$SPIKE_DEBUG" = 1 ] && echo "[$(ts)] debug: $*" >&2; return 0; }
# podman, echoed first in debug mode
pm() { debug "podman $(printf '%s ' "$@" | sed -E 's/(PASSWORD=)[^ ]+/\1<redacted>/g')"; podman "$@"; }

# Samples what the migrator and the application role are doing until stop_sampler.
# Reads only pg_stat_activity and the committed migrations rows, with a lock timeout, so
# it cannot queue behind the migration's own locks.
SAMPLER_PID=
LOGTAIL_PID=
start_sampler() {
	[ "$SPIKE_DEBUG" = 1 ] || return 0
	(
		while :; do
			podman exec "$PG" psql -U postgres -d "$DB" -At -F ' | ' -v ON_ERROR_STOP=0 \
				-c "SET lock_timeout = '1s'" \
				-c "SELECT 'session', usename, pid, 'xact ' || coalesce(date_trunc('second', now() - xact_start)::text, '-'), 'stmt ' || coalesce(date_trunc('second', now() - query_start)::text, '-'), coalesce(wait_event_type || ':' || wait_event, 'running'), coalesce(nullif(btrim(left(regexp_replace(regexp_replace(query, '--[^\n]*', '', 'g'), '\s+', ' ', 'g'), 160)), ''), '(only comments in the first ' || current_setting('track_activity_query_size') || ' of the statement; nested statements appear in the [pg] lines) ' || left(regexp_replace(query, '\s+', ' ', 'g'), 100)) FROM pg_stat_activity WHERE usename IN ('victual_migrate', 'victual_app') AND state <> 'idle' ORDER BY xact_start" \
				-c "SELECT 'committed', 'highest migration ' || coalesce(max(migration), 0) FROM migrations WHERE migration < 8888" 2>&1 \
				| grep -v '^SET$' | sed "s/^/[$(ts)] debug: /" >&2
			sleep "$SPIKE_SAMPLE_SECONDS"
		done
	) &
	SAMPLER_PID=$!
	# Slow statements from auto_explain and log_min_duration_statement, as they finish.
	# Each slow-statement entry is a "duration:" line followed by tab-indented plan lines;
	# print those entries whole, plus errors and their context.
	( podman logs -f --since 1s "$PG" 2>&1 | awk '
		/ LOG: +duration:/ { keep = 1; print "[pg] " $0; fflush(); next }
		/ (ERROR|CONTEXT|STATEMENT): / { keep = 0; print "[pg] " $0; fflush(); next }
		/^\t/ && keep { print "[pg] " $0; fflush(); next }
		{ keep = 0 }' >&2 ) &
	LOGTAIL_PID=$!
}
stop_sampler() {
	[ -n "$SAMPLER_PID" ] && { kill "$SAMPLER_PID" 2>/dev/null; wait "$SAMPLER_PID" 2>/dev/null; }
	[ -n "$LOGTAIL_PID" ] && { pkill -P "$LOGTAIL_PID" 2>/dev/null; kill "$LOGTAIL_PID" 2>/dev/null; wait "$LOGTAIL_PID" 2>/dev/null; }
	SAMPLER_PID=; LOGTAIL_PID=
	return 0
}
trap stop_sampler EXIT

psql_as() { # psql_as <role> <password> <sql>
	podman exec -e PGPASSWORD="$2" "$PG" psql -h 127.0.0.1 -U "$1" -d "$DB" -At -v ON_ERROR_STOP=1 -c "$3"
}
psql_super() { podman exec "$PG" psql -U postgres -d "$DB" -At -v ON_ERROR_STOP=1 -c "$1"; }

# What the application role can see of the migration lock.
lock_state() {
	psql_as victual_app "$APP_PW" "SELECT coalesce(string_agg(granted::text, ',' ORDER BY granted DESC), 'none') FROM pg_locks WHERE locktype = 'advisory' AND classid = 0 AND objid = $LOCK_KEY AND objsubid = 1" 2>&1
}
applied_max() { psql_super "SELECT coalesce(max(migration), 0) FROM migrations WHERE migration < 8888" 2>&1 || true; }
http_status() { curl -s -o /dev/null -m 5 -w '%{http_code}' "http://127.0.0.1:$1/login"; }
http_body_head() { curl -s -m 5 "http://127.0.0.1:$1/login" | head -n "${2:-2}" | tr '\n' ' '; }

db_env() { # db_env <user> <password>
	echo -e "-e VICTUAL_MODE=production -e VICTUAL_DB_DRIVER=pgsql -e VICTUAL_DB_HOST=$PG -e VICTUAL_DB_PORT=5432 -e VICTUAL_DB_NAME=$DB -e VICTUAL_DB_USER=$1 -e VICTUAL_DB_PASSWORD=$2 -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=$ADMIN_PW"
}

migrate_tail() { if [ "$SPIKE_DEBUG" = 1 ]; then cat; else tail -n "${MIGRATE_TAIL:-3}"; fi; }

old_migrate() {
	start_sampler
	# shellcheck disable=SC2046
	pm run --rm --network "$NET" --read-only --tmpfs /tmp --tmpfs /data $(db_env victual_migrate "$MIGRATE_PW") \
		"ghcr.io/datagen24/victual-migrate:$OLD_TAG" 2>&1 | migrate_tail
	local rc=${PIPESTATUS[0]}
	stop_sampler
	return "$rc"
}
new_migrate() { # new_migrate [extra podman args...]
	start_sampler
	# shellcheck disable=SC2046
	pm run --rm --network "$NET" -v "$ROOT":/app:ro -v /app/packages -w /app --tmpfs /tmp \
		-e VICTUAL_DATAPATH=/tmp $(db_env victual_migrate "$MIGRATE_PW") "$@" \
		"$DEV_IMAGE" php bin/victual-migrate 2>&1 | grep -v "^#[0-9]" | migrate_tail
	local rc=${PIPESTATUS[0]}
	stop_sampler
	return "$rc"
}

old_serving_up() {
	podman pod rm -f ha-spike-old >/dev/null 2>&1
	podman pod create --name ha-spike-old --network "$NET" -p "127.0.0.1:$OLD_PORT:8080" >/dev/null
	# shellcheck disable=SC2046
	podman run -d --pod ha-spike-old --name ha-spike-old-app --read-only --tmpfs /tmp --tmpfs /data \
		$(db_env victual_app "$APP_PW") "ghcr.io/datagen24/victual-app:$OLD_TAG" >/dev/null
	podman run -d --pod ha-spike-old --name ha-spike-old-web --read-only --tmpfs /tmp \
		"ghcr.io/datagen24/victual-web:$OLD_TAG" >/dev/null
	for _ in $(seq 1 30); do [ "$(http_status $OLD_PORT)" != 000 ] && break; sleep 1; done
}
old_serving_down() { podman pod rm -f ha-spike-old >/dev/null 2>&1; }

new_serving_up() { # new_serving_up [app password] [extra podman args...]
	local pw=${1:-$APP_PW}; shift || true
	podman rm -f ha-spike-new >/dev/null 2>&1
	# shellcheck disable=SC2046
	podman run -d --name ha-spike-new --network "$NET" -p "127.0.0.1:$NEW_PORT:8080" \
		-v "$ROOT":/app:ro -v /app/packages -w /app --tmpfs /tmp -e VICTUAL_DATAPATH=/tmp \
		$(db_env victual_app "$pw") "$@" "$DEV_IMAGE" php -S 0.0.0.0:8080 -t public >/dev/null
	for _ in $(seq 1 30); do [ "$(http_status $NEW_PORT)" != 000 ] && break; sleep 1; done
}
new_serving_down() { podman rm -f ha-spike-new >/dev/null 2>&1; }

reset_db() {
	podman exec "$PG" psql -U postgres -d postgres -c "SELECT 1" >/dev/null 2>&1 || { log "postgres not up"; return 1; }
	podman exec "$PG" psql -U postgres -d postgres -q -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)" -c "CREATE DATABASE $DB" >/dev/null
	podman exec -i "$PG" psql -U postgres -d "$DB" -q -v ON_ERROR_STOP=1 -v db="$DB" \
		-v migrate_password="$MIGRATE_PW" -v app_password="$APP_PW" < "$ROOT/deploy/postgres/roles.sql" >/dev/null
}
regrant() { # roles.sql asks to be re-run after the first migration
	podman exec -i "$PG" psql -U postgres -d "$DB" -q -v ON_ERROR_STOP=1 -v db="$DB" \
		-v migrate_password="$MIGRATE_PW" -v app_password="$APP_PW" < "$ROOT/deploy/postgres/roles.sql" >/dev/null
}

observe() { # observe <label> <port>
	log "$1: http=$(http_status "$2") lock=$(lock_state) applied=$(applied_max) body=\"$(http_body_head "$2" 1)\""
}

case_setup() {
	podman network create "$NET" >/dev/null 2>&1
	podman rm -f "$PG" >/dev/null 2>&1
	local pgargs=()
	if [ "$SPIKE_DEBUG" = 1 ]; then
		pgargs=(-c shared_preload_libraries=auto_explain -c auto_explain.log_min_duration="$SPIKE_SLOW_MS"
			-c auto_explain.log_nested_statements=on -c auto_explain.log_format=text
			-c log_min_duration_statement="$SPIKE_SLOW_MS")
		if [ "${SPIKE_EXPLAIN_ANALYZE:-0}" = 1 ]; then
			pgargs+=(-c auto_explain.log_analyze=on -c auto_explain.log_timing=off)
		fi
	fi
	pm run -d --name "$PG" --network "$NET" -e POSTGRES_PASSWORD="$SUPER_PW" \
		--tmpfs /var/lib/postgresql/data postgres:16 "${pgargs[@]}" >/dev/null
	for _ in $(seq 1 30); do podman exec "$PG" pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
	sleep 2
	reset_db && log "setup: postgres $(psql_super 'SHOW server_version'), roles from deploy/postgres/roles.sql"
	# Confirm how the 32-bit lock key appears in pg_locks before any case relies on it.
	podman exec -e PGPASSWORD="$MIGRATE_PW" "$PG" psql -h 127.0.0.1 -U victual_migrate -d "$DB" -At \
		-c "SELECT pg_advisory_lock($LOCK_KEY)" \
		-c "SELECT locktype, classid, objid, objsubid, granted FROM pg_locks WHERE locktype = 'advisory'"
}

case_s1() {
	log "S1 empty database: serving starts before the migrator"
	reset_db; new_serving_up
	observe "S1 before migrate" $NEW_PORT
	log "S1 body before migrate: $(curl -s -m 5 http://127.0.0.1:$NEW_PORT/login | head -n 6 | tr '\n' ' ')"
	log "S1 migrate: $(new_migrate | tr '\n' ' ')"; regrant
	observe "S1 after migrate" $NEW_PORT
	new_serving_down
}

case_s2() {
	log "S2 older schema: database at $OLD_TAG, serving from this tree"
	reset_db; log "S2 old migrate: $(old_migrate | tr '\n' ' ')"; regrant
	new_serving_up
	observe "S2 before upgrade" $NEW_PORT
	log "S2 new migrate: $(new_migrate | tr '\n' ' ')"; regrant
	observe "S2 after upgrade" $NEW_PORT
	new_serving_down
}

case_s3() {
	log "S3 newer schema: an applied migration this tree does not know"
	reset_db; new_migrate >/dev/null; regrant
	psql_as victual_migrate "$MIGRATE_PW" "INSERT INTO migrations (migration) VALUES (9990)" >/dev/null
	new_serving_up
	observe "S3 serving" $NEW_PORT
	log "S3 serving body: $(curl -s -m 5 http://127.0.0.1:$NEW_PORT/login | head -n 4 | tr '\n' ' ')"
	local out rc; out=$(new_migrate); rc=$?
	log "S3 migrate against the newer schema: exit $rc: $(echo "$out" | tr '\n' ' ')"
	new_serving_down
}

case_s4() {
	log "S4 unreadable schema"
	reset_db; new_migrate >/dev/null; regrant
	new_serving_up wrong-password
	observe "S4 wrong app password" $NEW_PORT
	log "S4 body: $(curl -s -m 5 http://127.0.0.1:$NEW_PORT/login | head -n 3 | tr '\n' ' ')"
	new_serving_down
	new_serving_up
	podman stop -t 2 "$PG" >/dev/null
	log "S4 postgres stopped: http=$(http_status $NEW_PORT) body=\"$(http_body_head $NEW_PORT 3)\""
	podman start "$PG" >/dev/null
	for _ in $(seq 1 30); do podman exec "$PG" pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
	log "S4 note: postgres runs on tmpfs, so a restart empties it; later cases call reset_db"
	new_serving_down
}

case_s5() {
	log "S5 lock held with a current schema"
	reset_db; new_migrate >/dev/null; regrant
	new_serving_up
	podman exec -d -e PGPASSWORD="$MIGRATE_PW" "$PG" psql -h 127.0.0.1 -U victual_migrate -d "$DB" \
		-c "SELECT pg_advisory_lock($LOCK_KEY)" -c "SELECT pg_sleep(20)"
	sleep 3
	observe "S5 lock held" $NEW_PORT
	sleep 20
	observe "S5 lock released" $NEW_PORT
	new_serving_down
}

case_s6() {
	local rows=${S6_ROWS:-200000} products=${S6_PRODUCTS:-1000}
	log "S6 old serving running during the upgrade; $rows stock_log rows over $products products"
	reset_db; old_migrate >/dev/null; regrant
	# Fixture rows only, loaded as the superuser with triggers off: the stock_log triggers
	# otherwise take minutes per hundred thousand rows. Rows are spread over many products
	# because migration 0292 rebuilds the price caches one product at a time.
	psql_super "SET session_replication_role = replica;
		INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock)
		SELECT 'spike ' || g, (SELECT min(id) FROM locations), (SELECT min(id) FROM quantity_units), (SELECT min(id) FROM quantity_units)
		FROM generate_series(1, $products) g;
		INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, transaction_type, stock_id, user_id, price)
		SELECT p.id, 1, current_date, current_date, 'purchase', md5(g::text), (SELECT min(id) FROM users), 1.5
		FROM generate_series(1, $rows) g
		JOIN (SELECT id, row_number() OVER (ORDER BY id) - 1 AS n FROM products WHERE name LIKE 'spike %') p
		  ON p.n = g % $products" 2>&1 | grep -v '^SET$\|^INSERT' | sed 's/^/S6 fixture: /'
	log "S6 stock_log rows: $(psql_super 'SELECT count(*) FROM stock_log')"
	old_serving_up
	observe "S6 before upgrade" $OLD_PORT
	local loopfile; loopfile=$(mktemp)
	( while :; do
		r=$(curl -s -o /dev/null -m 60 -w '%{http_code} %{time_total}' "http://127.0.0.1:$OLD_PORT/login")
		echo "$(ts) $r"
		sleep 0.2
	done ) > "$loopfile" &
	local loop=$!
	sleep 2
	local t0; t0=$(date +%s)
	log "S6 new migrate: $(new_migrate | tr '\n' ' ')"
	log "S6 upgrade took $(( $(date +%s) - t0 )) s"
	sleep 3
	kill "$loop" 2>/dev/null; wait "$loop" 2>/dev/null
	log "S6 request loop: $(wc -l < "$loopfile") requests; status counts: $(awk '{print $2}' "$loopfile" | sort | uniq -c | tr '\n' ' ')"
	log "S6 slowest: $(sort -k3 -n -r "$loopfile" | head -n 3 | tr '\n' ' ')"
	log "S6 status transitions: $(awk '$2!=p {print $1, $2; p=$2}' "$loopfile" | tr '\n' ' ')"
	observe "S6 after upgrade" $OLD_PORT
	rm -f "$loopfile"; old_serving_down
}

case_s7() {
	log "S7 a migration that fails partway"
	reset_db; new_migrate >/dev/null; regrant
	# A copy of migrations/ with one failing migration added, mounted over the source's.
	# Mounting a single file into the read-only source mount would make the runtime create
	# an empty mount point file in the working tree.
	local dir; dir=$(mktemp -d)
	cp -R "$ROOT/migrations/." "$dir/"
	printf 'CREATE TABLE ha_spike_partial (id INT);\nSELECT 1 / 0;\n' > "$dir/9900.pgsql.sql"
	chmod -R a+rX "$dir"
	local mount=(-v "$dir:/app/migrations:ro")
	new_serving_up "$APP_PW" "${mount[@]}"
	local out rc; out=$(new_migrate "${mount[@]}"); rc=$?
	log "S7 migrate: exit $rc: $(echo "$out" | tr '\n' ' ')"
	log "S7 partial table exists: $(psql_super "SELECT to_regclass('ha_spike_partial') IS NOT NULL")"
	observe "S7 serving with the failing migration in its code" $NEW_PORT
	rm -rf "$dir"; new_serving_down
}

case_s8() {
	log "S8 two migrators start at once on an empty database"
	reset_db
	new_migrate > /tmp/ha-spike-s8a 2>&1 &
	local a=$!
	new_migrate > /tmp/ha-spike-s8b 2>&1 &
	local b=$!
	for _ in 1 2 3 4 5 6; do sleep 1; log "S8 lock holders/waiters: $(lock_state)"; done
	wait "$a"; local ra=$?; wait "$b"; local rb=$?
	log "S8 exits: $ra $rb; outputs: [$(tr '\n' ' ' < /tmp/ha-spike-s8a)] [$(tr '\n' ' ' < /tmp/ha-spike-s8b)]"
	log "S8 migrations rows: $(psql_super 'SELECT count(*), count(DISTINCT migration) FROM migrations'); users: $(psql_super 'SELECT count(*) FROM users')"
	rm -f /tmp/ha-spike-s8a /tmp/ha-spike-s8b
}

case_single() {
	log "single migrator on an empty database"
	reset_db
	local out rc; out=$(new_migrate); rc=$?
	log "single: exit $rc: $(echo "$out" | tr '\n' ' ')"
}

case_teardown() {
	old_serving_down; new_serving_down
	podman rm -f "$PG" >/dev/null 2>&1
	podman network rm "$NET" >/dev/null 2>&1
	log "teardown done"
}

for c in "$@"; do "case_$c"; done
