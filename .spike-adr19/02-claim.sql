-- The mechanism decision items 2, 5 and 6 specify, as SQL functions so the tests exercise
-- the real concurrency rather than a description of it.

-- Claim: lock the job row, check four preconditions, insert the next attempt number.
-- The lock serialises claimers; UNIQUE (outbox_id, attempt_number) is what keeps the
-- guarantee true independently of the lock being taken.
CREATE OR REPLACE FUNCTION claim(p_worker INT, p_lease_s INT DEFAULT 30, p_abs_s INT DEFAULT 300)
RETURNS TABLE (attempt_id BIGINT, outbox_id BIGINT, refused TEXT) AS $$
DECLARE j RECORD; used INT; n INT;
BEGIN
  SELECT * INTO j FROM print_jobs pj
    WHERE pj.worker_id = p_worker
      AND pj.outcome IS NULL
      AND pj.artifact_ready                      -- ADR-0021: unattached artifact is unclaimable
    ORDER BY pj.outbox_id
    FOR UPDATE SKIP LOCKED
    LIMIT 1;
  IF NOT FOUND THEN
    RETURN QUERY SELECT NULL::BIGINT, NULL::BIGINT, 'no_job'::TEXT; RETURN;
  END IF;

  SELECT count(*) INTO used FROM print_attempts a WHERE a.outbox_id = j.outbox_id;
  IF used >= j.attempts_authorized THEN
    RETURN QUERY SELECT NULL::BIGINT, j.outbox_id, 'no_authorization'::TEXT; RETURN;
  END IF;

  -- A live attempt blocks a second one. An ended attempt does not return the job to the
  -- queue: item 6's rule starts at the claim, so only an authorization reopens it.
  IF EXISTS (SELECT 1 FROM print_attempts a
             WHERE a.outbox_id = j.outbox_id AND a.ended_at IS NULL
               AND a.lease_expires_at > now() AND NOT a.superseded) THEN
    RETURN QUERY SELECT NULL::BIGINT, j.outbox_id, 'attempt_running'::TEXT; RETURN;
  END IF;

  n := used + 1;
  INSERT INTO print_attempts (outbox_id, attempt_number, worker_id, lease_expires_at, absolute_deadline)
  VALUES (j.outbox_id, n, p_worker, now() + make_interval(secs => p_lease_s),
          now() + make_interval(secs => p_abs_s))
  RETURNING id INTO attempt_id;

  UPDATE print_jobs SET current_attempt_id = attempt_id WHERE print_jobs.outbox_id = j.outbox_id;
  RETURN QUERY SELECT attempt_id, j.outbox_id, NULL::TEXT;
END $$ LANGUAGE plpgsql;

-- A heartbeat extends the lease, but never past the absolute bound, and never revives an
-- attempt that has ended, expired or been superseded.
CREATE OR REPLACE FUNCTION heartbeat(p_attempt BIGINT, p_worker INT, p_lease_s INT DEFAULT 30)
RETURNS TEXT AS $$
DECLARE a RECORD;
BEGIN
  SELECT * INTO a FROM print_attempts WHERE id = p_attempt FOR UPDATE;
  IF NOT FOUND THEN RETURN 'unknown'; END IF;
  IF a.worker_id <> p_worker THEN RETURN 'not_yours'; END IF;
  IF a.ended_at IS NOT NULL THEN RETURN 'refused_ended'; END IF;
  IF a.superseded THEN RETURN 'refused_superseded'; END IF;
  IF a.lease_expires_at <= now() THEN RETURN 'refused_expired'; END IF;
  IF now() >= a.absolute_deadline THEN RETURN 'refused_absolute_bound'; END IF;
  UPDATE print_attempts
     SET lease_expires_at = LEAST(now() + make_interval(secs => p_lease_s), a.absolute_deadline)
   WHERE id = p_attempt;
  RETURN 'extended';
END $$ LANGUAGE plpgsql;

-- Bytes reached the device. Idempotent: repeated delivery changes nothing after the first.
CREATE OR REPLACE FUNCTION mark_sent(p_attempt BIGINT, p_worker INT) RETURNS TEXT AS $$
DECLARE a RECORD;
BEGIN
  SELECT * INTO a FROM print_attempts WHERE id = p_attempt FOR UPDATE;
  IF a.worker_id <> p_worker THEN RETURN 'not_yours'; END IF;
  IF a.bytes_sent_at IS NOT NULL THEN RETURN 'already_sent'; END IF;
  UPDATE print_attempts SET bytes_sent_at = now() WHERE id = p_attempt;
  RETURN 'sent';
END $$ LANGUAGE plpgsql;

-- A terminal result. Accepted for a superseded or expired attempt too — a worker may always
-- report what its own attempt did — but only the job's current attempt completes the job.
CREATE OR REPLACE FUNCTION report_result(p_attempt BIGINT, p_worker INT, p_outcome TEXT, p_error TEXT DEFAULT NULL)
RETURNS TEXT AS $$
DECLARE a RECORD; j RECORD;
BEGIN
  SELECT * INTO a FROM print_attempts WHERE id = p_attempt FOR UPDATE;
  IF a.worker_id <> p_worker THEN RETURN 'not_yours'; END IF;
  IF a.ended_at IS NOT NULL THEN RETURN 'already_ended'; END IF;
  UPDATE print_attempts SET ended_at = now(), outcome = p_outcome, error = p_error WHERE id = p_attempt;
  SELECT * INTO j FROM print_jobs WHERE outbox_id = a.outbox_id FOR UPDATE;
  IF j.current_attempt_id = p_attempt AND NOT a.superseded THEN
    IF p_outcome IN ('reported_complete','sent') THEN
      UPDATE print_jobs SET outcome = p_outcome WHERE outbox_id = a.outbox_id;
      UPDATE outbox SET delivered_at = now() WHERE id = a.outbox_id;
      RETURN 'recorded_completed_job';
    END IF;
    RETURN 'recorded_job_open';
  END IF;
  RETURN 'recorded_on_own_row_only';
END $$ LANGUAGE plpgsql;

-- Expiry: an attempt past its lease with no terminal result is uncertain, and it ends.
CREATE OR REPLACE FUNCTION reap() RETURNS INT AS $$
DECLARE n INT;
BEGIN
  UPDATE print_attempts SET ended_at = now(), outcome = 'uncertain'
   WHERE ended_at IS NULL AND lease_expires_at <= now();
  GET DIAGNOSTICS n = ROW_COUNT; RETURN n;
END $$ LANGUAGE plpgsql;

-- A person authorizes another attempt, naming the ended attempt they reviewed. Three checks
-- are what stop a second label appearing from one job, and what make it double-click safe.
CREATE OR REPLACE FUNCTION authorize_next(p_outbox BIGINT, p_reviewed BIGINT) RETURNS TEXT AS $$
DECLARE j RECORD; a RECORD; used INT;
BEGIN
  SELECT * INTO j FROM print_jobs WHERE outbox_id = p_outbox FOR UPDATE;
  IF NOT FOUND THEN RETURN 'unknown_job'; END IF;
  SELECT * INTO a FROM print_attempts WHERE id = p_reviewed;
  IF NOT FOUND OR a.outbox_id <> p_outbox THEN RETURN 'refused_not_this_job'; END IF;
  IF j.current_attempt_id IS DISTINCT FROM p_reviewed THEN RETURN 'refused_not_current'; END IF;
  IF a.ended_at IS NULL THEN RETURN 'refused_still_running'; END IF;
  SELECT count(*) INTO used FROM print_attempts WHERE outbox_id = p_outbox;
  IF used < j.attempts_authorized THEN RETURN 'refused_unused_authorization'; END IF;
  UPDATE print_jobs SET attempts_authorized = attempts_authorized + 1 WHERE outbox_id = p_outbox;
  RETURN 'authorized';
END $$ LANGUAGE plpgsql;
