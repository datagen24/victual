#!/usr/bin/env bash
# Migration 0301 rehearsal on a disposable copy of parity-generated data (issue #650).
# usage: rehearse.sh <target-db> <zone> [refuse]
set -euo pipefail
PGC=victual-pg16-650; DB=$1; ZONE=$2; MODE=${3:-convert}
S=$(cd "$(dirname "$0")" && pwd)
W=${VICTUAL_TREE:?set VICTUAL_TREE to the working copy}
# Guard: only a rehearsal_* database inside the disposable container, never the source.
case "$DB" in rehearsal_src) echo "refusing to touch the source"; exit 3;; rehearsal_*) ;; *) echo "refusing: $DB is not a rehearsal_ database"; exit 3;; esac
[ "$(podman inspect $PGC --format '{{.Config.Image}}')" = "localhost/victual-pg:16-pgtap-650" ] || { echo "refusing: $PGC is not the disposable container"; exit 3; }
psql() { podman exec -i $PGC psql -U victual -d "$DB" -v ON_ERROR_STOP=1 -At "$@"; }
echo "target: $PGC/$DB, image $(podman inspect $PGC --format '{{.Config.Image}}'), zone $ZONE, mode $MODE"
podman exec $PGC dropdb -U victual --if-exists "$DB"; podman exec $PGC createdb -U victual "$DB"
podman exec -i $PGC pg_restore -U victual -d "$DB" --exit-on-error < "$S/source.dump"
# Labelled fixtures, added to this disposable copy only. Not household data.
psql <<SQL
INSERT INTO chores (name, period_type, period_interval, start_date, track_date_only, active) VALUES ('REHEARSAL FIXTURE repeated hour', 'daily', 1, '2024-11-02 01:30:00', 0, 1);
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) SELECT id, '2024-11-03 01:30:00', 1, 0 FROM chores WHERE name = 'REHEARSAL FIXTURE repeated hour';
INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) SELECT id, '2024-11-03 01:59:59.250000', 1, 0 FROM chores WHERE name = 'REHEARSAL FIXTURE repeated hour';
INSERT INTO labels (uid, kind, target_id) SELECT '0REHEARSA1B2C', 'location', min(id) FROM locations;
SQL
if [ "$MODE" = refuse ]; then
  psql -c "INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) SELECT id, '2024-03-10 02:30:00', 1, 0 FROM chores WHERE name = 'REHEARSAL FIXTURE repeated hour'"
fi
snapshot() {
  psql <<'SQL'
SELECT 'rows', string_agg(t || '=' || n, ' ' ORDER BY t) FROM (
  SELECT 'stock_log' t, count(*) n FROM stock_log UNION ALL SELECT 'chores_log', count(*) FROM chores_log UNION ALL
  SELECT 'battery_charge_cycles', count(*) FROM battery_charge_cycles UNION ALL SELECT 'stock', count(*) FROM stock UNION ALL
  SELECT 'tasks', count(*) FROM tasks UNION ALL SELECT 'labels', count(*) FROM labels UNION ALL SELECT 'products', count(*) FROM products) x;
SELECT 'nulls', count(*) FILTER (WHERE tracked_time IS NULL), count(*) FILTER (WHERE undone_timestamp IS NULL) FROM chores_log;
SELECT 'stock_qty', md5(string_agg(id || ':' || amount || ':' || coalesce(best_before_date::text,'') || ':' || coalesce(purchased_date::text,'') || ':' || coalesce(location_id::text,''), ',' ORDER BY id)) FROM stock;
SELECT 'ledger', md5(string_agg(id || ':' || amount || ':' || coalesce(stock_id,'') || ':' || coalesce(transaction_id,'') || ':' || coalesce(correlation_id,'') || ':' || undone || ':' || coalesce(best_before_date::text,'') || ':' || coalesce(purchased_date::text,'') || ':' || coalesce(used_date::text,''), ',' ORDER BY id)) FROM stock_log;
SELECT 'tasks_dates', md5(string_agg(id || ':' || coalesce(due_date::text,''), ',' ORDER BY id)) FROM tasks;
SELECT 'labels', md5(string_agg(uid || ':' || kind || ':' || coalesce(target_id::text,''), ',' ORDER BY uid)) FROM labels;
SQL
}
echo "== before"; snapshot | tee "$S/$DB.before"
# Independent oracle for every converted chore time: PostgreSQL's own reading of an
# unambiguous wall clock, and for the repeated hour the earlier instant written out by hand.
psql -c "CREATE TABLE rehearsal_expected AS SELECT id, tracked_time AS wall, tracked_time AT TIME ZONE '$ZONE' AS expected FROM chores_log"
if [ "$ZONE" = America/New_York ]; then
  psql -c "UPDATE rehearsal_expected SET expected = (wall::text || '-04')::timestamptz WHERE wall >= '2024-11-03 01:00:00' AND wall < '2024-11-03 02:00:00'"
fi
echo "== preflight"
run() { podman run --rm --network victual-suite-650 -v $W:/app -v /app/packages -w /app -e DB=$DB victual:dev-650 sh -c "mkdir -p /tmp/d && printf '%s\n' \"<?php\" \"Setting('DB_DRIVER','pgsql');\" \"Setting('DB_HOST','$PGC');\" \"Setting('DB_PORT',5432);\" \"Setting('DB_NAME',getenv('DB'));\" \"Setting('DB_USER','victual');\" \"Setting('DB_PASSWORD','victual');\" > /tmp/d/config.php && VICTUAL_DATAPATH=/tmp/d php -d date.timezone=$ZONE $*"; }
set +e; run bin/victual-timestamp-preflight; echo "preflight exit=$?"; set -e
echo "== migrate"
set +e; start=$(date +%s.%N 2>/dev/null || python3 -c 'import time;print(time.time())'); run bin/victual-migrate; rc=$?; end=$(python3 -c 'import time;print(time.time())'); set -e
echo "migrate exit=$rc"
psql -c "SELECT max(migration) FROM migrations" | sed 's/^/max migration: /'
psql -c "SELECT data_type FROM information_schema.columns WHERE table_name='chores_log' AND column_name='tracked_time'" | sed 's/^/chores_log.tracked_time: /'
[ $rc -ne 0 ] && exit 0
echo "== after"; snapshot | tee "$S/$DB.after"
diff <(grep -v '^nulls' "$S/$DB.before") <(grep -v '^nulls' "$S/$DB.after") && echo "rows, quantities, ledger, dates and labels identical"
diff <(grep '^nulls' "$S/$DB.before") <(grep '^nulls' "$S/$DB.after") && echo "null counts identical"
psql -c "SELECT 'instants', count(*), count(*) FILTER (WHERE c.tracked_time IS NOT DISTINCT FROM e.expected), count(*) FILTER (WHERE c.tracked_time IS DISTINCT FROM e.expected) FROM chores_log c JOIN rehearsal_expected e USING (id)"
psql -c "SET TIME ZONE 'UTC'; SELECT 'fixture', to_char(tracked_time, 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') FROM chores_log c JOIN chores h ON h.id=c.chore_id WHERE h.name LIKE 'REHEARSAL FIXTURE%' ORDER BY c.id"
echo "== second run"
run bin/victual-migrate --quiet; echo "second migrate exit=$?"
psql -c "SELECT 'unchanged after second run', count(*) FILTER (WHERE c.tracked_time IS NOT DISTINCT FROM e.expected) = count(*) FROM chores_log c JOIN rehearsal_expected e USING (id)"
