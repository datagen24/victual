#!/usr/bin/env bash
# Two concurrent claims against one authorization must produce one attempt, with the loser
# refused rather than queued behind it. The lock serialises; the unique constraint is what
# keeps the guarantee true if the lock is ever not taken.
set -uo pipefail
PSQL="psql -U postgres -d spike -X -q -t -A"

$PSQL -c "TRUNCATE print_evidence, print_attempts, print_jobs, outbox RESTART IDENTITY CASCADE;
          INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
          INSERT INTO print_jobs (outbox_id, printer_id, worker_id) VALUES (1, 10, 1);" >/dev/null

echo "== two concurrent claims, one authorization =="
for i in 1 2 3 4 5 6; do $PSQL -c "SELECT coalesce(attempt_id::text, refused) FROM claim(1);" & done
wait
echo "attempts on the job: $($PSQL -c 'SELECT count(*) FROM print_attempts WHERE outbox_id = 1;')"

echo
echo "== the unique constraint alone, with the lock deliberately bypassed =="
# Insert the same attempt_number from two sessions at once: the constraint is the fence.
$PSQL -c "SELECT ended_at IS NULL FROM print_attempts WHERE outbox_id=1;" >/dev/null
for i in 1 2 3 4; do
  $PSQL -c "INSERT INTO print_attempts (outbox_id, attempt_number, worker_id, lease_expires_at, absolute_deadline)
            VALUES (1, 99, 1, now()+interval '30s', now()+interval '300s');" 2>&1 | tail -1 &
done
wait
echo "rows at attempt_number 99: $($PSQL -c 'SELECT count(*) FROM print_attempts WHERE outbox_id=1 AND attempt_number=99;')"
