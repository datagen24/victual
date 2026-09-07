-- I7 found the implementation refusing what the record requires. ADR-0019 decision item 5 is
-- explicit that `sent` and `result` are "accepted for a superseded or expired attempt too — a
-- worker may always report what its own attempt did; whether that report completes the job is
-- a separate question". The first implementation refused with `already_ended` once the reaper
-- had marked the attempt uncertain, which discards the one fact an operator most needs: that
-- the label did in fact come out.
--
-- Left that way, a worker delayed past its lease — by a credential refusal, or by anything
-- else — leaves an attempt that says "uncertain" while the printer is holding a finished
-- label, and the person deciding whether to authorize another attempt is told nothing. They
-- authorize, and a second label prints.
ALTER TABLE print_attempts ADD COLUMN IF NOT EXISTS reported_outcome TEXT;
ALTER TABLE print_attempts ADD COLUMN IF NOT EXISTS reported_at TIMESTAMPTZ;

CREATE OR REPLACE FUNCTION report_result(p_attempt BIGINT, p_worker INT, p_outcome TEXT, p_error TEXT DEFAULT NULL)
RETURNS TEXT AS $$
DECLARE a RECORD; j RECORD;
BEGIN
  SELECT * INTO a FROM print_attempts WHERE id = p_attempt FOR UPDATE;
  IF NOT FOUND THEN RETURN 'unknown_attempt'; END IF;
  IF a.worker_id <> p_worker THEN RETURN 'not_yours'; END IF;

  -- Already carries a worker's report: idempotent, and a second differing report does not
  -- overwrite the first.
  IF a.reported_outcome IS NOT NULL THEN RETURN 'already_reported'; END IF;

  IF a.ended_at IS NULL THEN
    UPDATE print_attempts
       SET ended_at = now(), outcome = p_outcome, error = p_error,
           reported_outcome = p_outcome, reported_at = now()
     WHERE id = p_attempt;
  ELSE
    -- The attempt already ended — reaped as uncertain, or superseded. The report is recorded
    -- on its own row and the lease outcome is left standing beside it, so the history says
    -- both what the server concluded and what the worker later said.
    UPDATE print_attempts
       SET reported_outcome = p_outcome, reported_at = now(),
           error = coalesce(p_error, a.error)
     WHERE id = p_attempt;
  END IF;

  SELECT * INTO j FROM print_jobs WHERE outbox_id = a.outbox_id FOR UPDATE;
  IF j.current_attempt_id = p_attempt AND NOT a.superseded AND a.ended_at IS NULL THEN
    IF p_outcome IN ('reported_complete','sent') THEN
      UPDATE print_jobs SET outcome = p_outcome WHERE outbox_id = a.outbox_id;
      UPDATE outbox SET delivered_at = now() WHERE id = a.outbox_id;
      RETURN 'recorded_completed_job';
    END IF;
    RETURN 'recorded_job_open';
  END IF;

  IF a.ended_at IS NOT NULL AND NOT a.superseded AND j.current_attempt_id = p_attempt THEN
    RETURN 'recorded_late_on_uncertain_attempt';
  END IF;
  RETURN 'recorded_on_own_row_only';
END $$ LANGUAGE plpgsql;

-- What an operator is shown before authorizing another attempt. The last column is the one
-- that stops a duplicate: an uncertain attempt whose worker later said it completed.
CREATE OR REPLACE VIEW job_monitor AS
SELECT j.outbox_id, j.outcome AS job_outcome, j.attempts_authorized,
       a.id AS attempt_id, a.outcome AS lease_outcome, a.reported_outcome,
       a.bytes_sent_at IS NOT NULL AS bytes_sent,
       (a.outcome = 'uncertain' AND a.reported_outcome IS NOT NULL) AS uncertain_but_worker_reported
  FROM print_jobs j LEFT JOIN print_attempts a ON a.id = j.current_attempt_id;
