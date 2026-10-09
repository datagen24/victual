#!/usr/bin/env bash
# Runs every probe in order, one PostgreSQL + one PHP container at a time (run.sh creates and
# removes each pair). Evidence JSON goes to evidence/; progress logs go to $B41_LOGDIR.
set -uo pipefail
cd "$(dirname "$0")/../.."
ev=.devtools/adr0041/evidence
logs="${B41_LOGDIR:-/tmp/b41-logs}"
mkdir -p "$logs"
run() { # name, probe, [pg image]
    local name="$1" probe="$2" pg="${3:-localhost/victual-pg:pgtap}"
    echo "== $name ($probe on $pg) $(date +%H:%M:%S)" >&2
    PG_IMAGE="$pg" .devtools/adr0041/run.sh probe "$probe" > "$ev/$name.json" 2> "$logs/$name.log" || echo "FAILED $name (see $logs/$name.log)" >&2
}
run probe2-failure-pg16 probe2-failure
run probe5-partial-undo-pg16 probe5-partial-undo
run probe6-insufficient-pg16 probe6-insufficient
run callers-audit callers-audit
run probe1-idempotent-pg16 probe1-idempotent
run probe3-lockorder-pg16 probe3-lockorder
echo "== suite $(date +%H:%M:%S)" >&2
.devtools/adr0041/run.sh suite > "$ev/probe4-suite-summary.jsonl" 2> "$logs/suite.log" || echo "FAILED suite" >&2
run probe1-idempotent-pg15 probe1-idempotent docker.io/library/postgres:15
run probe3-lockorder-pg15 probe3-lockorder docker.io/library/postgres:15
echo "== done $(date +%H:%M:%S)" >&2
