#!/usr/bin/env bash
# 0.4.4 -> 0.5.0 upgrade rehearsal; the record is docs/releases/0.5.0-upgrade-rehearsal.md.
# Run inside the flake dev shell (PHP 8.5) against a scratch PostgreSQL server. It drops and
# creates the databases r050_valid and r050_invalid there; point it at nothing else.
#   rehearse.sh <v0.4.4 tree, composer-installed> <candidate tree, composer-installed> <out dir>
# Connection: REHEARSAL_PGHOST (default 127.0.0.1), REHEARSAL_PGUSER (victual, a superuser),
# REHEARSAL_PGPASSWORD (rehearsal).
set -uo pipefail
SRC=$1; CAND=$2; OUT=$3
HERE=$(cd "$(dirname "$0")" && pwd)
H=${REHEARSAL_PGHOST:-127.0.0.1}; U=${REHEARSAL_PGUSER:-victual}
mkdir -p "$OUT/data"
echo '<?php' > "$OUT/data/config.php"
export VICTUAL_DATAPATH="$OUT/data" VICTUAL_DB_HOST=$H VICTUAL_DB_PORT=5432 \
	VICTUAL_DB_USER=$U VICTUAL_DB_PASSWORD=${REHEARSAL_PGPASSWORD:-rehearsal} TZ=UTC
export PGPASSWORD=${REHEARSAL_PGPASSWORD:-rehearsal}
PSQL="psql -h $H -U $U -X -q -tA"
DUMPS="--no-owner --no-privileges"
norm() { grep -v '^\\\(un\)\?restrict ' "$1"; }

probe() { # db
	$PSQL -d "$1" -c "SELECT json_build_object(
		'migration', (SELECT max(migration) FROM migrations),
		'stock', (SELECT json_object_agg(p.name, s.amount ORDER BY p.name) FROM stock_current s JOIN products p ON p.id = s.product_id WHERE p.name LIKE 'Upgrade audit%'),
		'stock_rows', (SELECT count(*) FROM stock), 'stock_log_rows', (SELECT count(*) FROM stock_log),
		'chore_executions', (SELECT count(*) FROM chores_log WHERE chore_id = (SELECT id FROM chores WHERE name = 'Upgrade audit chore')))"
}

for db in r050_valid r050_invalid; do
	$PSQL -d postgres -c "DROP DATABASE IF EXISTS $db" -c "CREATE DATABASE $db"
	VICTUAL_DB_NAME=$db php "$SRC/bin/victual-migrate" --quiet || { echo "source migrate failed for $db"; exit 1; }
done
VICTUAL_DB_NAME=r050_valid php "$HERE/fixture.php" --app="$SRC" || exit 1
VICTUAL_DB_NAME=r050_invalid php "$HERE/fixture.php" --app="$SRC" --nan || exit 1

echo "== valid: before"; probe r050_valid | tee "$OUT/valid-before.json"
for run in 1 2; do
	VICTUAL_DB_NAME=r050_valid php "$CAND/bin/victual-migrate"; echo "candidate run $run exit $?"
	pg_dump -h $H -U $U --schema-only $DUMPS r050_valid > "$OUT/schema-$run.sql"
	pg_dump -h $H -U $U --data-only --inserts --column-inserts $DUMPS r050_valid > "$OUT/data-$run.sql" 2>/dev/null
done
echo "== valid: after"; probe r050_valid | tee "$OUT/valid-after.json"
for k in schema data; do
	a=$(norm "$OUT/$k-1.sql" | sha256sum | cut -c1-64); b=$(norm "$OUT/$k-2.sql" | sha256sum | cut -c1-64)
	echo "$k run1 $a run2 $b $([ "$a" = "$b" ] && echo identical || echo DIFFERENT)"
done
echo "== 0315 marker and report"
$PSQL -d r050_valid -c "SELECT p.name, s.price, s.legacy_zero_price FROM stock s JOIN products p ON p.id = s.product_id WHERE p.name LIKE 'Upgrade audit%' ORDER BY p.name"
$PSQL -d r050_valid -c "SELECT source, policy, rule, stock_rows, stock_log_rows FROM price_interpretation_reports"
echo "== 0316 value_incomplete"
$PSQL -d r050_valid -c "SELECT p.name, s.value, s.value_incomplete FROM stock_current s JOIN products p ON p.id = s.product_id WHERE p.name LIKE 'Upgrade audit%' ORDER BY p.name"

echo "== invalid: before"; probe r050_invalid | tee "$OUT/invalid-before.json"
pg_dump -h $H -U $U $DUMPS r050_invalid > "$OUT/invalid-before.sql"
VICTUAL_DB_NAME=r050_invalid php "$CAND/bin/victual-migrate" > "$OUT/invalid-migrate.log" 2>&1; echo "candidate exit $?"
grep -o 'Migration refused[^.]*\.' "$OUT/invalid-migrate.log" | head -1
pg_dump -h $H -U $U $DUMPS r050_invalid > "$OUT/invalid-after.sql"
echo "== invalid: after"; probe r050_invalid
a=$(norm "$OUT/invalid-before.sql" | sha256sum | cut -c1-64); b=$(norm "$OUT/invalid-after.sql" | sha256sum | cut -c1-64)
echo "invalid full dump before $a after $b $([ "$a" = "$b" ] && echo identical || echo DIFFERENT)"
$PSQL -d r050_invalid -c "SELECT count(*) FROM stock WHERE price = 'NaN'"
