\set ON_ERROR_STOP on
\pset pager off
\set QUIET on
TRUNCATE print_evidence, print_attempts, print_jobs, outbox RESTART IDENTITY CASCADE;
DELETE FROM label_workers;
INSERT INTO label_workers (id, name) VALUES (1,'w1'), (2,'w2');
SELECT setval(pg_get_serial_sequence('label_workers','id'), 2);
\set QUIET off

\echo '--- T1  heartbeat extends a lease; the absolute bound ends it'
INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
INSERT INTO print_jobs (outbox_id, printer_id, worker_id) VALUES (1, 10, 1);
SELECT attempt_id AS a1 FROM claim(1, 30, 300) \gset
SELECT heartbeat(:a1, 1, 60) AS extends;
UPDATE print_attempts SET absolute_deadline = now() - interval '1s' WHERE id = :a1;
SELECT heartbeat(:a1, 1, 60) AS past_absolute_bound;

\echo '--- T2  a failed attempt leaves the job unclaimable until a person authorizes another'
SELECT report_result(:a1, 1, 'failed', 'printer unreachable') AS result;
SELECT refused AS claim_after_failure FROM claim(1);
SELECT authorize_next(1, :a1) AS authorized;
SELECT attempt_id AS a2 FROM claim(1) \gset
SELECT :a2 IS NOT NULL AS claim_succeeds_after_authorization;

\echo '--- T3  authorization is refused while an attempt runs, and while one is unused'
SELECT authorize_next(1, :a2) AS while_running;
SELECT report_result(:a2, 1, 'failed', 'again') AS r2;
SELECT authorize_next(1, :a2) AS grants_one;
SELECT authorize_next(1, :a2) AS refuses_second_unused;

\echo '--- T4  a late result from a superseded attempt is recorded, and completes nothing'
SELECT attempt_id AS a3 FROM claim(1) \gset
UPDATE print_attempts SET superseded = true WHERE id = :a3;
SELECT report_result(:a3, 1, 'reported_complete') AS superseded_result;
SELECT heartbeat(:a3, 1) AS late_heartbeat_for_it;
SELECT outcome AS job_outcome_still_open FROM print_jobs WHERE outbox_id = 1;

\echo '--- T5  sent/result/evidence are idempotent after the first'
INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
INSERT INTO print_jobs (outbox_id, printer_id, worker_id) VALUES (2, 10, 1);
SELECT attempt_id AS b1 FROM claim(1) \gset
SELECT mark_sent(:b1,1) AS first_send, mark_sent(:b1,1) AS second_send;
SELECT report_result(:b1,1,'reported_complete') AS first_result, report_result(:b1,1,'reported_complete') AS second_result;
INSERT INTO print_evidence (attempt_id, source, submission_id, observed) VALUES (:b1,'device','s1','{}');
INSERT INTO print_evidence (attempt_id, source, submission_id, observed) VALUES (:b1,'device','s1','{}')
  ON CONFLICT (source, submission_id) DO NOTHING;
SELECT count(*) AS evidence_rows FROM print_evidence WHERE attempt_id = :b1;

\echo '--- T6  a worker killed between bytes_sent_at and its result: uncertain, and no second print'
INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
INSERT INTO print_jobs (outbox_id, printer_id, worker_id) VALUES (3, 10, 1);
SELECT attempt_id AS c1 FROM claim(1, 1, 300) \gset
SELECT mark_sent(:c1,1) AS bytes_sent;
UPDATE print_attempts SET lease_expires_at = now() - interval '1s' WHERE id = :c1;
SELECT reap() AS reaped;
SELECT outcome AS attempt_outcome, bytes_sent_at IS NOT NULL AS bytes_had_been_sent
  FROM print_attempts WHERE id = :c1;
SELECT refused AS second_print_refused FROM claim(1);

\echo '--- T7  an unattached artifact is not claimable (ADR-0021)'
INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested','{}');
INSERT INTO print_jobs (outbox_id, printer_id, worker_id, artifact_ready) VALUES (4, 10, 2, false);
SELECT refused AS unattached_artifact FROM claim(2);
UPDATE print_jobs SET artifact_ready = true WHERE outbox_id = 4;
SELECT attempt_id IS NOT NULL AS claimable_once_attached FROM claim(2);
