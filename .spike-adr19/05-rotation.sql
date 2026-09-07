-- Gate 3 — the replay mechanism, chosen and then tested.
--
-- The record lists three options and says picking one on paper would be guessing. This spike
-- implements the second — a successor derivable rather than stored — because it is the only
-- one that keeps both properties the other two trade away: hashed storage (option 1 gives it
-- up for a window) and recovery without an admin (option 3 gives that up).
--
-- The exchange: the worker generates rotation_request_id R and secret material M, persists
-- both before calling, and sends both with its current credential. Victual derives
--
--     successor = HMAC-SHA256(key = M, message = from_credential_id || ':' || R)
--
-- and stores only SHA-256(successor), exactly as migration 0264 leaves api_keys. A replay
-- carries the same R and the same M, so the same successor is reproduced without Victual ever
-- holding it. What is stored against R is a *hash* of M, never M: enough to refuse a replay
-- that presents different material — which would otherwise mint a second successor under one
-- request id — and not enough to derive anything from the database alone.
--
-- The entropy of a credential is therefore the worker's to supply, which is a real property to
-- state rather than hide: a worker with a broken RNG weakens its own credential and no other.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

ALTER TABLE pending_rotations DROP COLUMN IF EXISTS worker_material;
ALTER TABLE pending_rotations ADD COLUMN IF NOT EXISTS material_hash TEXT NOT NULL DEFAULT '';

CREATE OR REPLACE FUNCTION derive_successor(p_from BIGINT, p_rid TEXT, p_material TEXT)
RETURNS TEXT AS $$
  SELECT encode(hmac(p_from::text || ':' || p_rid, p_material, 'sha256'), 'hex');
$$ LANGUAGE sql IMMUTABLE;

CREATE OR REPLACE FUNCTION sha(p TEXT) RETURNS TEXT AS $$
  SELECT encode(digest(p, 'sha256'), 'hex');
$$ LANGUAGE sql IMMUTABLE;

-- Returns the successor plaintext on success; otherwise a reason prefixed with '!'.
CREATE OR REPLACE FUNCTION rotate(p_current TEXT, p_rid TEXT, p_material TEXT) RETURNS TEXT AS $$
DECLARE c RECORD; pr RECORD; succ TEXT; new_id BIGINT;
BEGIN
  SELECT * INTO c FROM worker_credentials WHERE key_hash = sha(p_current) FOR UPDATE;
  IF NOT FOUND THEN RETURN '!unknown_credential'; END IF;
  IF c.revoked_at IS NOT NULL THEN RETURN '!revoked'; END IF;
  IF c.session_expires_at <= now() THEN RETURN '!session_expired'; END IF;

  SELECT * INTO pr FROM pending_rotations WHERE rotation_request_id = p_rid FOR UPDATE;

  IF FOUND THEN
    -- A replay. It must be the same credential and the same material, or it is not a replay.
    IF pr.from_credential_id <> c.id THEN RETURN '!refused_other_credential'; END IF;
    IF pr.material_hash <> sha(p_material) THEN RETURN '!refused_material_mismatch'; END IF;
    RETURN derive_successor(pr.from_credential_id, p_rid, p_material);
  END IF;

  -- Not a replay. A consumed credential presented under a *different* request id is reuse of
  -- a credential the worker should have replaced: that revokes the session, per the record.
  IF c.consumed_at IS NOT NULL THEN
    UPDATE worker_credentials SET revoked_at = now() WHERE session_id = c.session_id;
    RETURN '!reuse_session_revoked';
  END IF;

  succ := derive_successor(c.id, p_rid, p_material);
  INSERT INTO worker_credentials (worker_id, key_hash, key_hint, session_id, session_expires_at)
  VALUES (c.worker_id, sha(succ), right(succ, 4), c.session_id, c.session_expires_at)
  RETURNING id INTO new_id;
  UPDATE worker_credentials SET consumed_at = now() WHERE id = c.id;
  INSERT INTO pending_rotations (rotation_request_id, from_credential_id, to_credential_id, material_hash)
  VALUES (p_rid, c.id, new_id, sha(p_material));
  RETURN succ;
END $$ LANGUAGE plpgsql;

-- Authentication for the other routes: a consumed or revoked credential is refused, and the
-- refusal revokes nothing by itself — stale traffic is not theft.
CREATE OR REPLACE FUNCTION authenticate(p_key TEXT) RETURNS TEXT AS $$
DECLARE c RECORD;
BEGIN
  SELECT * INTO c FROM worker_credentials WHERE key_hash = sha(p_key);
  IF NOT FOUND THEN RETURN '401_unknown'; END IF;
  IF c.revoked_at IS NOT NULL THEN RETURN '401_revoked'; END IF;
  IF c.consumed_at IS NOT NULL THEN RETURN '401_superseded'; END IF;
  IF c.session_expires_at <= now() THEN RETURN '401_session_expired'; END IF;
  RETURN 'ok';
END $$ LANGUAGE plpgsql;

-- Pairing: single-use material, consumed by its first use.
CREATE OR REPLACE FUNCTION pair(p_secret TEXT) RETURNS TEXT AS $$
DECLARE m RECORD; cred TEXT;
BEGIN
  SELECT * INTO m FROM pairing_material WHERE secret_hash = sha(p_secret) FOR UPDATE;
  IF NOT FOUND THEN RETURN '!unknown_material'; END IF;
  IF m.consumed_at IS NOT NULL THEN RETURN '!already_consumed'; END IF;
  IF m.expires_at <= now() THEN RETURN '!expired'; END IF;
  cred := encode(gen_random_bytes(32), 'hex');
  INSERT INTO worker_credentials (worker_id, key_hash, key_hint, session_id, session_expires_at)
  VALUES (m.worker_id, sha(cred), right(cred,4), encode(gen_random_bytes(8),'hex'), now() + interval '30 days');
  UPDATE pairing_material SET consumed_at = now() WHERE id = m.id;
  RETURN cred;
END $$ LANGUAGE plpgsql;
