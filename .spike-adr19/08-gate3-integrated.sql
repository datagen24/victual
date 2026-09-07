\set ON_ERROR_STOP on
\pset pager off
\set QUIET on
TRUNCATE print_evidence, print_attempts, print_jobs, outbox RESTART IDENTITY CASCADE;
TRUNCATE pending_rotations, pairing_material, worker_credentials RESTART IDENTITY CASCADE;
INSERT INTO pairing_material (worker_id, secret_hash, expires_at) VALUES (1, sha('p1'), now() + interval '1 hour');
INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
INSERT INTO print_jobs (outbox_id, printer_id, worker_id) VALUES (1, 10, 1);
\set QUIET off

SELECT pair('p1') AS c1 \gset

\echo '--- I1  claim and send bytes on credential C1'
SELECT claim_as(:'c1') AS attempt \gset attempt_
SELECT :'attempt_attempt' AS attempt_id;
SELECT sent_as(:'c1', :attempt_attempt::bigint) AS bytes_sent;

\echo '--- I2  rotate mid-attempt; the attempt is untouched'
SELECT rotate(:'c1', 'RA', 'material-A') AS c2 \gset
SELECT ended_at IS NULL AS attempt_still_open, bytes_sent_at IS NOT NULL AS bytes_still_recorded,
       lease_expires_at > now() AS lease_still_live
  FROM print_attempts WHERE id = :attempt_attempt::bigint;

\echo '--- I3  the OLD credential is refused, and the refusal discards nothing'
SELECT result_as(:'c1', :attempt_attempt::bigint, 'reported_complete') AS old_credential_report;
SELECT ended_at IS NULL AS attempt_still_reportable, outcome AS attempt_outcome
  FROM print_attempts WHERE id = :attempt_attempt::bigint;
SELECT outcome AS job_outcome FROM print_jobs WHERE outbox_id = 1;

\echo '--- I4  the successor reports the same attempt, and it completes'
SELECT result_as(:'c2', :attempt_attempt::bigint, 'reported_complete') AS successor_report;
SELECT outcome AS attempt_outcome, ended_at IS NOT NULL AS ended
  FROM print_attempts WHERE id = :attempt_attempt::bigint;
SELECT outcome AS job_outcome, delivered_at IS NOT NULL AS outbox_delivered
  FROM print_jobs pj JOIN outbox o ON o.id = pj.outbox_id WHERE pj.outbox_id = 1;

\echo '--- I5  no second attempt and no second print came out of any of it'
SELECT count(*) AS attempts_on_the_job FROM print_attempts WHERE outbox_id = 1;

\echo '--- I6  the crash variant: rotate, die before storing, recover by replay, then report'
INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
INSERT INTO print_jobs (outbox_id, printer_id, worker_id) VALUES (2, 10, 1);
SELECT claim_as(:'c2') AS a2 \gset attempt2_
SELECT sent_as(:'c2', :attempt2_a2::bigint) AS bytes_sent_2;
SELECT rotate(:'c2', 'RB', 'material-B') AS c3_lost \gset
-- The worker never saw that response. It restarts holding C2 and its pending record, and
-- retries the identical call.
SELECT rotate(:'c2', 'RB', 'material-B') AS c3 \gset
SELECT (:'c3' = :'c3_lost') AS recovered_the_same_successor;
SELECT result_as(:'c3', :attempt2_a2::bigint, 'reported_complete') AS report_after_recovery;
SELECT count(*) AS attempts_on_job_2 FROM print_attempts WHERE outbox_id = 2;

\echo '--- I7  a credential refusal that outlasts the lease: what actually happens'
INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
INSERT INTO print_jobs (outbox_id, printer_id, worker_id) VALUES (3, 10, 1);
SELECT claim_as(:'c3', 1) AS a3 \gset attempt3_
SELECT sent_as(:'c3', :attempt3_a3::bigint) AS bytes_sent_3;
SELECT rotate(:'c3', 'RC', 'material-C') AS c4 \gset
-- The worker spends longer than its lease recovering, and the reaper runs first.
UPDATE print_attempts SET lease_expires_at = now() - interval '1s' WHERE id = :attempt3_a3::bigint;
SELECT reap() AS reaped;
SELECT result_as(:'c4', :attempt3_a3::bigint, 'reported_complete') AS late_report_after_expiry;
SELECT outcome AS attempt_outcome_after_late_report, bytes_sent_at IS NOT NULL AS bytes_were_sent
  FROM print_attempts WHERE id = :attempt3_a3::bigint;
SELECT count(*) AS attempts_on_job_3 FROM print_attempts WHERE outbox_id = 3;
