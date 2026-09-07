-- Gate 3's integrated case: the routes authorize by credential, so a rotation mid-attempt is
-- exercised against real print state rather than against credential rows alone.
--
-- The promise under test is narrow and worth restating: not "no outcome is ever lost" — a
-- declared worker holds nothing durable, so a crash before it reports loses that report and
-- leaves an uncertain attempt, which is the designed outcome — but that **no outcome is lost
-- to a credential refusal**.

CREATE OR REPLACE FUNCTION worker_of(p_key TEXT) RETURNS INT AS $$
DECLARE c RECORD;
BEGIN
  IF authenticate(p_key) <> 'ok' THEN RETURN NULL; END IF;
  SELECT * INTO c FROM worker_credentials WHERE key_hash = sha(p_key);
  RETURN c.worker_id;
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION claim_as(p_key TEXT, p_lease_s INT DEFAULT 30)
RETURNS TEXT AS $$
DECLARE w INT; r RECORD;
BEGIN
  w := worker_of(p_key);
  IF w IS NULL THEN RETURN '401:' || authenticate(p_key); END IF;
  SELECT * INTO r FROM claim(w, p_lease_s, 300);
  RETURN coalesce(r.attempt_id::text, 'refused:' || r.refused);
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION sent_as(p_key TEXT, p_attempt BIGINT) RETURNS TEXT AS $$
DECLARE w INT;
BEGIN
  w := worker_of(p_key);
  IF w IS NULL THEN RETURN '401:' || authenticate(p_key); END IF;
  RETURN mark_sent(p_attempt, w);
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION result_as(p_key TEXT, p_attempt BIGINT, p_outcome TEXT) RETURNS TEXT AS $$
DECLARE w INT;
BEGIN
  w := worker_of(p_key);
  IF w IS NULL THEN RETURN '401:' || authenticate(p_key); END IF;
  RETURN report_result(p_attempt, w, p_outcome);
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION heartbeat_as(p_key TEXT, p_attempt BIGINT) RETURNS TEXT AS $$
DECLARE w INT;
BEGIN
  w := worker_of(p_key);
  IF w IS NULL THEN RETURN '401:' || authenticate(p_key); END IF;
  RETURN heartbeat(p_attempt, w, 30);
END $$ LANGUAGE plpgsql;
