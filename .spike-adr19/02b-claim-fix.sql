-- Preconditions belong in the selection predicate, not after it.
--
-- The first version picked the lowest claimable-looking job, then checked authorization and
-- liveness and returned a refusal. With one exhausted job at the head of the queue, every
-- claim answered "no_authorization" and nothing behind it was ever offered — a blocked job
-- starving the queue, which is not a rule this record states anywhere. FOR UPDATE SKIP LOCKED
-- makes the fix cheap: the row that is picked is one that already passed.
CREATE OR REPLACE FUNCTION claim(p_worker INT, p_lease_s INT DEFAULT 30, p_abs_s INT DEFAULT 300)
RETURNS TABLE (attempt_id BIGINT, outbox_id BIGINT, refused TEXT) AS $$
DECLARE j RECORD; used INT; n INT;
BEGIN
  SELECT pj.* INTO j FROM print_jobs pj
    WHERE pj.worker_id = p_worker
      AND pj.outcome IS NULL
      AND pj.artifact_ready
      AND (SELECT count(*) FROM print_attempts a WHERE a.outbox_id = pj.outbox_id)
          < pj.attempts_authorized
      AND NOT EXISTS (SELECT 1 FROM print_attempts a
                      WHERE a.outbox_id = pj.outbox_id AND a.ended_at IS NULL
                        AND a.lease_expires_at > now() AND NOT a.superseded)
    ORDER BY pj.outbox_id
    FOR UPDATE SKIP LOCKED
    LIMIT 1;
  IF NOT FOUND THEN
    RETURN QUERY SELECT NULL::BIGINT, NULL::BIGINT, 'no_claimable_job'::TEXT; RETURN;
  END IF;

  SELECT count(*) INTO used FROM print_attempts a WHERE a.outbox_id = j.outbox_id;
  n := used + 1;
  INSERT INTO print_attempts (outbox_id, attempt_number, worker_id, lease_expires_at, absolute_deadline)
  VALUES (j.outbox_id, n, p_worker, now() + make_interval(secs => p_lease_s),
          now() + make_interval(secs => p_abs_s))
  RETURNING id INTO attempt_id;
  UPDATE print_jobs SET current_attempt_id = attempt_id WHERE print_jobs.outbox_id = j.outbox_id;
  RETURN QUERY SELECT attempt_id, j.outbox_id, NULL::TEXT;
END $$ LANGUAGE plpgsql;
