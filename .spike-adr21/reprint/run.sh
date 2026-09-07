#!/usr/bin/env bash
# ADR-0021 prerequisite 4, end to end against the networked laser.
#
# Renders once, hides the renderer, reprints the retained bytes, delivers them over IPP and
# reads the completion back. Then collects the artifact and shows the reprint refused rather
# than quietly rerendered.
set -u
PGURL="${PGURL:-postgresql://postgres:spike@127.0.0.1:55432/spike}"
PRINTER="${PRINTER:-ipp://10.130.30.13/ipp/print}"
RENDERER=".spike-renderer/rsrender/target/release/rsrender"
WORK=/tmp/adr21-reprint
q() { psql "$PGURL" -tAq -c "SET search_path TO adr21r; $1"; }
fails=0
say() { printf '  %-4s %-52s %s\n' "$1" "$2" "$3"; [ "$1" = FAIL ] && fails=$((fails+1)); return 0; }

mkdir -p "$WORK"
UID_="vctl:0123456789ABC"

echo "1 -- render once and retain the bytes"
python3 .spike-adr19/gate5/mkpdf.py "$UID_" 1.0 "$WORK/artifact.pdf" >/dev/null
sha=$(shasum -a 256 "$WORK/artifact.pdf" | cut -d' ' -f1)
len=$(wc -c < "$WORK/artifact.pdf" | tr -d ' ')
q "TRUNCATE print_jobs, artifacts RESTART IDENTITY CASCADE" >/dev/null
aid=$(psql "$PGURL" -tAq -c "SET search_path TO adr21r;
  INSERT INTO artifacts (label_uid, form, combination, bytes, sha256, byte_length)
  SELECT '$UID_', 'pdf/1.4',
         '{\"connection_type\":\"ipp\",\"model\":\"laser\",\"media\":\"letter\",\"resolution\":[600,600],\"color_mode\":\"monochrome\"}'::jsonb,
         ''::bytea, '$sha', $len
  RETURNING artifact_id;")
# The bytes go in as a hex literal rather than through pg_read_binary_file, which the
# server would only read from its own filesystem.
psql "$PGURL" -q -c "SET search_path TO adr21r;
  UPDATE artifacts SET bytes = decode('$(xxd -p "$WORK/artifact.pdf" | tr -d '\n')', 'hex')
   WHERE artifact_id = $aid;"
jid=$(q "INSERT INTO print_jobs (label_uid, operation, artifact_id)
         VALUES ('$UID_', 'first_issuance', $aid) RETURNING job_id;")
stored_len=$(q "SELECT length(bytes) FROM artifacts WHERE artifact_id = $aid")
say ok "artifact $aid retained, job $jid" "$stored_len bytes, sha256 ${sha:0:16}..."

echo
echo "2 -- make the renderer unavailable"
mv "$RENDERER" "$RENDERER.absent"
trap 'mv -f "$RENDERER.absent" "$RENDERER" 2>/dev/null' EXIT
[ ! -x "$RENDERER" ] && say ok "the renderer binary is gone" "$RENDERER" \
                     || say FAIL "the renderer is still present" "$RENDERER"
"$RENDERER" --dir . --case x >/dev/null 2>&1 && say FAIL "the renderer still ran" "unexpected" \
                                             || say ok "invoking it fails" "exit $?"

echo
echo "3 -- reprint over the retained bytes, with no renderer on the machine"
res=$(q "SELECT reprint($jid)")
say ok "reprint queued" "$res"
newjid=$(echo "$res" | grep -oE '[0-9]+$')
same=$(q "SELECT (a.artifact_id = b.artifact_id AND a.sha256 = b.sha256)
          FROM print_jobs j1 JOIN artifacts a ON a.artifact_id = j1.artifact_id,
               print_jobs j2 JOIN artifacts b ON b.artifact_id = j2.artifact_id
         WHERE j1.job_id = $jid AND j2.job_id = $newjid")
[ "$same" = "t" ] && say ok "it replays the same artifact row" "artifact $aid" \
                  || say FAIL "the reprint points elsewhere" "$same"
[ "$(q "SELECT count(*) FROM artifacts")" = "1" ] && say ok "no second artifact was created" "1 row" \
                                                  || say FAIL "an artifact was created" "$(q "SELECT count(*) FROM artifacts")"

echo
echo "4 -- deliver the retained bytes and read the completion back"
q "COPY (SELECT encode(bytes,'hex') FROM artifacts WHERE artifact_id = $aid) TO STDOUT" \
  | tr -d '\n' | xxd -r -p > "$WORK/delivered.pdf"
out_sha=$(shasum -a 256 "$WORK/delivered.pdf" | cut -d' ' -f1)
[ "$out_sha" = "$sha" ] && say ok "bytes out of the database are the bytes in" "${out_sha:0:16}..." \
                        || say FAIL "digest changed in storage" "$out_sha vs $sha"
cat > "$WORK/print.test" <<'IPP'
{
  OPERATION Print-Job
  GROUP operation-attributes-tag
  ATTR charset attributes-charset utf-8
  ATTR language attributes-natural-language en
  ATTR uri printer-uri $uri
  ATTR name requesting-user-name victual-spike
  ATTR name job-name adr21-prereq4-reprint
  ATTR mimeMediaType document-format application/pdf
  FILE $filename
  STATUS successful-ok
  DISPLAY job-id
  DISPLAY job-state
}
IPP
ipp_out=$(ipptool -tv -f "$WORK/delivered.pdf" "$PRINTER" "$WORK/print.test" 2>&1)
echo "$ipp_out" | grep -E "job-id|job-state|status-code|Print-Job" | sed 's/^/  | /'
job_id=$(echo "$ipp_out" | grep -oE 'job-id \(integer\) = [0-9]+' | grep -oE '[0-9]+$')
if [ -n "$job_id" ]; then
  say ok "the printer accepted the retained artifact" "IPP job $job_id"
  cat > "$WORK/status.test" <<'IPP'
{
  OPERATION Get-Job-Attributes
  GROUP operation-attributes-tag
  ATTR charset attributes-charset utf-8
  ATTR language attributes-natural-language en
  ATTR uri printer-uri $uri
  ATTR integer job-id $job-id
  ATTR name requesting-user-name victual-spike
  STATUS successful-ok
  DISPLAY job-state
  DISPLAY job-state-reasons
  DISPLAY job-impressions-completed
}
IPP
  # The laser interprets before it marks, so poll to a terminal state rather than
  # guessing a sleep. A single wait long enough for this page is a wait that is wrong
  # for the next one.
  state=""; impr=""
  for _ in $(seq 1 30); do
    sleep 5
    st=$(ipptool -tv -d job-id="$job_id" "$PRINTER" "$WORK/status.test" 2>&1)
    state=$(echo "$st" | grep -oE 'job-state \(enum\) = [a-z-]+' | sed 's/.*= //')
    impr=$(echo "$st" | grep -oE 'job-impressions-completed \(integer\) = [0-9]+' | grep -oE '[0-9]+$')
    case "$state" in completed|aborted|canceled) break;; esac
  done
  echo "$st" | grep -E "job-state|job-impressions" | sed 's/^/  | /' 
  q "UPDATE print_jobs SET outcome = 'completed',
       evidence = jsonb_build_object('ipp_job_id', $job_id, 'job_state', '$state',
                                     'impressions', ${impr:-0})
      WHERE job_id = $newjid" >/dev/null
  [ "$state" = "completed" ] && say ok "device-reported completion for the reprint" \
                                      "job-state=$state, impressions=${impr:-?}" \
                             || say FAIL "the reprint did not complete" "job-state=$state"
else
  say FAIL "the printer did not accept the artifact" "$(echo "$ipp_out" | tail -3 | tr '\n' ' ')"
fi

echo
echo "5 -- collect the artifact; the reprint must refuse, not rerender"
q "UPDATE artifacts SET bytes = NULL, collected_at = now() - interval '1 day'
    WHERE artifact_id = $aid" >/dev/null
res=$(q "SELECT reprint($jid)")
case "$res" in
  refused*) say ok "the reprint is refused, naming the artifact" "$res";;
  *) say FAIL "the reprint was queued without bytes" "$res";;
esac
[ ! -x "$RENDERER" ] && say ok "and the renderer was never restored to serve it" "still absent" \
                     || say FAIL "the renderer reappeared" "present"
n=$(q "SELECT count(*) FROM print_jobs")
[ "$n" = "2" ] && say ok "no job was queued for the refused reprint" "$n jobs" \
               || say FAIL "an extra job exists" "$n jobs"

echo
echo "$fails failure(s)"
exit $((fails > 0))
