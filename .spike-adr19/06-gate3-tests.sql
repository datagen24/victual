\set ON_ERROR_STOP on
\pset pager off
\set QUIET on
TRUNCATE pending_rotations, pairing_material, worker_credentials RESTART IDENTITY CASCADE;
INSERT INTO pairing_material (worker_id, secret_hash, expires_at) VALUES (1, sha('pair-secret-1'), now() + interval '1 hour');
\set QUIET off

\echo '--- T8  pairing material is consumed by its first use'
SELECT pair('pair-secret-1') AS first_use \gset
SELECT left(:'first_use', 8) || '...' AS credential_issued;
SELECT pair('pair-secret-1') AS second_use;

\echo '--- T9  a lost rotation response, retried with the same request id, recovers'
SELECT rotate(:'first_use', 'R1', 'material-A') AS s1 \gset
SELECT rotate(:'first_use', 'R1', 'material-A') AS s2 \gset
SELECT (:'s1' = :'s2') AS replay_returns_the_same_successor,
       (SELECT count(*) FROM worker_credentials) AS credential_rows,
       (SELECT count(*) FROM pending_rotations) AS rotation_rows;

\echo '--- T10 a replay under the same id with different material is refused'
SELECT rotate(:'first_use', 'R1', 'material-B') AS different_material;
SELECT count(*) AS credential_rows_unchanged FROM worker_credentials;

\echo '--- T11 a worker killed before storing the successor recovers to exactly one credential'
-- It restarts holding the old credential and its pending record, and retries the same call.
SELECT rotate(:'first_use', 'R1', 'material-A') AS recovered \gset
SELECT (:'recovered' = :'s1') AS same_credential_recovered,
       (SELECT count(*) FROM worker_credentials WHERE consumed_at IS NULL AND revoked_at IS NULL)
         AS live_credentials;

\echo '--- T12 stale traffic after a rotation is refused, and revokes nothing'
SELECT authenticate(:'first_use') AS old_credential_on_a_normal_route;
SELECT authenticate(:'s1') AS successor_still_valid;
SELECT count(*) AS revoked_rows FROM worker_credentials WHERE revoked_at IS NOT NULL;

\echo '--- T13 a consumed credential under a DIFFERENT request id revokes the session'
SELECT rotate(:'first_use', 'R2', 'material-C') AS reuse_under_new_id;
SELECT authenticate(:'s1') AS successor_after_revocation;
SELECT count(*) AS revoked_rows FROM worker_credentials WHERE revoked_at IS NOT NULL;

\echo '--- T14 no path through any of this produced a print'
SELECT count(*) AS attempts_created FROM print_attempts WHERE outbox_id IS NOT NULL;
