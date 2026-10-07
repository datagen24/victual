#!/usr/bin/env bash
#
# Issue #650 upgrade rehearsal: a fixture written by v0.2.0-MVP, upgraded through this tree's
# migrations, in a disposable PostgreSQL container. See README.md for what it proves.
#
#   .devtools/pgsql/upgrade-rehearsal/rehearse.sh <postgres container> <source tree> <out dir>
#
# <postgres container> must be a running container whose name starts with `victual-pg` and
# which is on the network $REHEARSAL_NETWORK. Only databases named rehearsal650_* are created,
# dropped or written. Nothing else is touched.
# <source tree> is a checkout of the source revision (git worktree add <dir> v0.2.0-MVP).
# The target is this tree. Both run in $REHEARSAL_IMAGE, the dev image (`--target dev`).
#
# Exit status 0 when every check passes.

set -euo pipefail

PG=${1:?postgres container}
SOURCE=$(cd "${2:?source tree}" && pwd)
OUT=$(mkdir -p "${3:?out dir}" && cd "$3" && pwd)
TARGET=$(cd "$(dirname "$0")/../../.." && pwd)
HERE="$TARGET/.devtools/pgsql/upgrade-rehearsal"
NETWORK=${REHEARSAL_NETWORK:?set REHEARSAL_NETWORK to the container network}
IMAGE=${REHEARSAL_IMAGE:-victual:dev}
ZONE=America/New_York

case "$PG" in victual-pg*) ;; *) echo "refusing: $PG is not a victual-pg* container" >&2; exit 3 ;; esac

log() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" | tee -a "$OUT/rehearsal.log"; }
psql_at() { podman exec "$PG" psql -U victual -d "$1" -v ON_ERROR_STOP=1 -At -c "$2"; }

# Runs php in the dev image against tree $1 and database $2; the rest is the command line.
php_in() {
	local tree=$1 db=$2
	shift 2
	podman run --rm --network "$NETWORK" -v "$tree":/app:ro -v /app/packages -v "$HERE":/rehearsal:ro -v "$OUT":/out \
		-w /app -e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=rehearsal-admin-650 "$IMAGE" sh -c '
			mkdir -p /tmp/d && printf "%s\n" "<?php" "Setting(\"DB_DRIVER\", \"pgsql\");" "Setting(\"DB_HOST\", \"'"$PG"'\");" \
				"Setting(\"DB_PORT\", 5432);" "Setting(\"DB_NAME\", \"'"$db"'\");" "Setting(\"DB_USER\", \"victual\");" \
				"Setting(\"DB_PASSWORD\", \"victual\");" > /tmp/d/config.php
			export VICTUAL_DATAPATH=/tmp/d
			exec php -d date.timezone='"$ZONE"' -d display_errors=stderr "$@"' php "$@"
}

fresh() {
	case "$1" in rehearsal650_*) ;; *) echo "refusing to touch $1" >&2; exit 3 ;; esac
	podman exec "$PG" dropdb -U victual --if-exists "$1"
	podman exec "$PG" createdb -U victual "$1"
}

source_fixture() {
	local db=$1 manifest=$2
	shift 2
	fresh "$db"
	php_in "$SOURCE" "$db" bin/victual-migrate --quiet 2> >(grep -v upload_max_filesize >&2)
	php_in "$SOURCE" "$db" /rehearsal/generate.php --app=/app --manifest="/out/$manifest" "$@" 2> >(grep -v upload_max_filesize >&2)
	log "$db: generated under $(git -C "$SOURCE" rev-parse HEAD) at migration $(psql_at "$db" 'SELECT max(migration) FROM migrations')"
}

snapshot() { php_in "$TARGET" "$1" /rehearsal/snapshot.php "$PG" "$1" > "$OUT/$2"; }

log "postgres: $(psql_at postgres 'SELECT version()')"
log "source: $(git -C "$SOURCE" describe --tags --always) $(git -C "$SOURCE" rev-parse HEAD)"
log "target: $(git -C "$TARGET" rev-parse HEAD)$(git -C "$TARGET" diff --quiet HEAD -- . ':!.devtools/pgsql/upgrade-rehearsal' || echo ' (with uncommitted changes)')"
log "zone: $ZONE; image: $IMAGE ($(podman image inspect "$IMAGE" --format '{{.Id}}' | cut -c1-12))"
status=0

# The valid fixture, upgraded twice.
source_fixture rehearsal650_valid manifest-valid.json
podman exec "$PG" pg_dump -U victual -Fc rehearsal650_valid > "$OUT/source-valid.dump"
snapshot rehearsal650_valid before.json
set +e
php_in "$TARGET" rehearsal650_valid bin/victual-timestamp-preflight > "$OUT/preflight-valid.txt" 2>&1
log "preflight on the v0.2.0 schema: exit $? ($(tail -1 "$OUT/preflight-valid.txt"))"
start=$(python3 -c 'import time; print(time.time())')
php_in "$TARGET" rehearsal650_valid bin/victual-migrate > "$OUT/migrate-valid.txt" 2>&1
rc=$?
log "upgrade: exit $rc in $(python3 -c "import time; print(round(time.time() - $start, 2))") s, now at migration $(psql_at rehearsal650_valid 'SELECT max(migration) FROM migrations')"
set -e
[ "$rc" -eq 0 ] || status=1
snapshot rehearsal650_valid after.json
set +e
php_in "$TARGET" rehearsal650_valid bin/victual-migrate > "$OUT/migrate-valid-again.txt" 2>&1
rc=$?
set -e
log "second upgrade run: exit $rc ($(grep -v upload_max "$OUT/migrate-valid-again.txt" | tail -1))"
[ "$rc" -eq 0 ] || status=1
snapshot rehearsal650_valid again.json
python3 "$HERE/verify.py" upgrade "$OUT/manifest-valid.json" "$OUT/before.json" "$OUT/after.json" "$OUT/again.json" | tee "$OUT/verify-upgrade.txt" || status=1
python3 "$HERE/verify.py" selftest "$OUT/manifest-valid.json" "$OUT/before.json" "$OUT/after.json" "$OUT/again.json" | tee "$OUT/verify-selftest.txt" || status=1

# The same generation again: the fixture is reproducible.
source_fixture rehearsal650_repeat manifest-repeat.json
snapshot rehearsal650_repeat repeat.json
cmp -s "$OUT/manifest-valid.json" "$OUT/manifest-repeat.json" && log "manifests identical" || { log "manifests differ"; status=1; }
python3 "$HERE/verify.py" reproducible "$OUT/before.json" "$OUT/repeat.json" | tee "$OUT/verify-reproducible.txt" || status=1

# The invalid fixture: refused before anything is converted.
source_fixture rehearsal650_invalid manifest-invalid.json --invalid
snapshot rehearsal650_invalid invalid-before.json
set +e
php_in "$TARGET" rehearsal650_invalid bin/victual-timestamp-preflight > "$OUT/preflight-invalid.txt" 2>&1
prc=$?
php_in "$TARGET" rehearsal650_invalid bin/victual-migrate > "$OUT/migrate-invalid.txt" 2>&1
mrc=$?
set -e
log "invalid fixture: preflight exit $prc, upgrade exit $mrc, now at migration $(psql_at rehearsal650_invalid 'SELECT max(migration) FROM migrations')"
[ "$prc" -eq 2 ] && [ "$mrc" -ne 0 ] || status=1
snapshot rehearsal650_invalid invalid-after.json
python3 "$HERE/verify.py" refusal "$OUT/manifest-invalid.json" "$OUT/invalid-before.json" "$OUT/invalid-after.json" | tee "$OUT/verify-refusal.txt" || status=1

log "rehearsal $([ $status -eq 0 ] && echo PASSED || echo FAILED)"
exit $status
