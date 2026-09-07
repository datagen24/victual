#!/usr/bin/env bash
# ADR-0021 prerequisite 3, run against a real PostgreSQL.
#
# Three cases. The first shows the defect the guard exists to prevent, so that the second
# is a demonstration rather than an assertion; the third shows retirement history surviving
# an import that is allowed to proceed.
set -u
PGURL="${PGURL:-postgresql://postgres:spike@127.0.0.1:55432/spike}"
q() { psql "$PGURL" -tAq -c "SET search_path TO adr21; $1"; }
reset() {
  psql "$PGURL" -tAq -c "SET search_path TO adr21;
    TRUNCATE labels; TRUNCATE locations RESTART IDENTITY;
    INSERT INTO locations (name) VALUES ('Pantry'),('Cellar'),('Freezer'),('Garage'),('Shed');" >/dev/null
}
fails=0
say() { printf '  %-4s %-56s %s\n' "$1" "$2" "$3"; [ "$1" = FAIL ] && fails=$((fails+1)); return 0; }

echo "Case 1 -- the precheck race, with the guard where AssertTargetIsEmpty puts it today"
reset
# The importer's shape: count first, then sleep (standing in for schema checks and the
# source read), then the transaction. Issuance lands inside that window.
( psql "$PGURL" -tAq -c "SET search_path TO adr21;
    SELECT 'precheck live labels: ' || count(*) FROM labels WHERE retired_at IS NULL;" >/tmp/adr21-precheck.txt
  sleep 2
  psql "$PGURL" -tAq -c "SET search_path TO adr21;
    BEGIN; SELECT import_body(false, ARRAY['Attic','Boathouse','Cabin','Dock','Ell']); COMMIT;" >/dev/null
) &
IMPORT=$!
sleep 0.7
q "SELECT issue_label('VCTL0000000A1', 5)" >/dev/null
wait $IMPORT
cat /tmp/adr21-precheck.txt | sed 's/^/  /'
before="Shed"; after=$(q "SELECT name FROM locations WHERE id = 5")
resolved=$(q "SELECT resolve('VCTL0000000A1')")
echo "  label was issued for location 5 = '$before'; after the import location 5 = '$after'"
if [ "$resolved" = "resolved: $after" ] && [ "$after" != "$before" ]; then
  say ok "a precheck lets a label alias a replaced target" "$resolved"
else
  say FAIL "expected the aliasing defect to reproduce" "$resolved"
fi

echo
echo "Case 2a -- guard inside the transaction, issuance wins the lock"
reset
( sleep 0.7
  psql "$PGURL" -tAq -c "SET search_path TO adr21;
    BEGIN; SELECT import_body(true, ARRAY['Attic','Boathouse','Cabin','Dock','Ell']); COMMIT;" \
    >/tmp/adr21-import.txt 2>&1 ) &
IMPORT=$!
q "SELECT issue_label('VCTL0000000B2', 5)" >/dev/null
wait $IMPORT
out=$(cat /tmp/adr21-import.txt)
resolved=$(q "SELECT resolve('VCTL0000000B2')")
n5=$(q "SELECT name FROM locations WHERE id = 5")
echo "$out" | sed 's/^/  | /'
if echo "$out" | grep -q "import refused"; then
  say ok "the import is refused, naming the count" "$(echo "$out" | grep -o 'import refused[^"]*' | head -1)"
else
  say FAIL "the import was not refused" "$out"
fi
[ "$resolved" = "resolved: Shed" ] && say ok "the label is intact and correctly targeted" "$resolved" \
                                  || say FAIL "label wrong after refusal" "$resolved"
[ "$n5" = "Shed" ] && say ok "the target rows are untouched" "location 5 = $n5" \
                   || say FAIL "locations changed despite refusal" "location 5 = $n5"

echo
echo "Case 2b -- guard inside the transaction, the import wins the lock"
reset
( psql "$PGURL" -tAq -c "SET search_path TO adr21;
    BEGIN; SELECT label_lock(); SELECT pg_sleep(2);
    SELECT import_body(true, ARRAY['Attic','Boathouse','Cabin','Dock','Ell']); COMMIT;" \
    >/tmp/adr21-import.txt 2>&1 ) &
IMPORT=$!
sleep 0.5
start=$(python3 -c 'import time;print(time.time())')
issued=$(q "SELECT issue_label('VCTL0000000C3', 5)")
waited=$(python3 -c "import time;print(f'{time.time()-$start:.1f}')")
wait $IMPORT
resolved=$(q "SELECT resolve('VCTL0000000C3')")
n5=$(q "SELECT name FROM locations WHERE id = 5")
echo "  issuance blocked ${waited}s on the import's lock, then returned: $issued"
if [ "$resolved" = "resolved: $n5" ]; then
  say ok "the label names what it was issued against" "$resolved (location 5 = $n5)"
else
  say FAIL "label does not match its target" "$resolved vs $n5"
fi
case "$resolved" in *DANGLING*) say FAIL "the label dangles" "$resolved";; esac

echo
echo "Case 2c -- the same race, with the request carrying the epoch it was composed at"
reset
epoch=$(q "SELECT epoch FROM import_epoch")
( psql "$PGURL" -tAq -c "SET search_path TO adr21;
    BEGIN; SELECT label_lock(); SELECT pg_sleep(2);
    SELECT import_body(true, ARRAY['Attic','Boathouse','Cabin','Dock','Ell']); COMMIT;" \
    >/tmp/adr21-import.txt 2>&1 ) &
IMPORT=$!
sleep 0.5
issued=$(q "SELECT issue_label_at_epoch('VCTL0000000F6', 5, $epoch)")
wait $IMPORT
echo "  request composed at epoch $epoch, executed after the import: $issued"
case "$issued" in
  refused*) say ok "issuance refuses rather than labelling the new occupant" "$issued";;
  *) say FAIL "issuance minted a label for a replaced target" "$issued";;
esac
[ "$(q "SELECT count(*) FROM labels")" = "0" ] && say ok "nothing was minted" "0 labels" \
                                              || say FAIL "a label exists after the refusal" "$(q "SELECT count(*) FROM labels")"

echo
echo "Case 3 -- an import that proceeds leaves retirement history intact"
reset
q "SELECT issue_label('VCTL0000000D4', 5)" >/dev/null
q "SELECT retire('VCTL0000000D4')" >/dev/null
retired_before=$(q "SELECT resolve('VCTL0000000D4')")
out=$(psql "$PGURL" -tAq -c "SET search_path TO adr21;
  BEGIN; SELECT import_body(true, ARRAY['Attic','Boathouse','Cabin','Dock','Ell']); COMMIT;" 2>&1)
echo "$out" | grep -v '^$' | sed 's/^/  | /'
retired_after=$(q "SELECT resolve('VCTL0000000D4')")
n5=$(q "SELECT name FROM locations WHERE id = 5")
echo "$out" | grep -q "imported 5" && say ok "the import proceeds: no live labels" "$(echo "$out" | tr -d '\n')" \
                                   || say FAIL "the import did not proceed" "$out"
[ "$retired_after" = "$retired_before" ] && say ok "the retired snapshot survives TRUNCATE CASCADE" "$retired_after" \
                                         || say FAIL "snapshot lost" "$retired_before -> $retired_after"
[ "$n5" = "Ell" ] && say ok "location 5 is now the imported row" "$n5" || say FAIL "unexpected location 5" "$n5"
reissued=$(q "SELECT issue_label('VCTL0000000E5', 5)")
[ "$reissued" = "issued" ] && say ok "the reused id can be labelled again" "$reissued" \
                           || say FAIL "partial unique index blocked reuse" "$reissued"
r_old=$(q "SELECT resolve('VCTL0000000D4')"); r_new=$(q "SELECT resolve('VCTL0000000E5')")
[ "$r_old" != "$r_new" ] && say ok "the old uid never resolves to the new occupant" "$r_old / $r_new" \
                         || say FAIL "old uid follows the reused id" "$r_old"

echo
echo "$fails failure(s)"
exit $((fails > 0))
