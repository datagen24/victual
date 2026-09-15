-- api_keys.rotated_from_id: the lineage a rotation leaves behind (issue #130, sweep S11's
-- expiry-and-rotation half). Rotating a regular API key creates a successor rather than
-- mutating the row in place, so that the predecessor keeps authenticating until whoever
-- holds it is explicitly retired -- "no gap or double-validity window" the issue asks for
-- means the client controls when the old key stops working, not that the two can never
-- overlap. This column is what records "this row replaces that one" once the successor
-- exists, so the one-time reveal after a rotation can say so and a later reader can tell a
-- rotation from an unrelated key of the same type.
--
-- Self-referencing, nullable (most keys are never rotated), and ON DELETE SET NULL rather
-- than CASCADE: deleting a predecessor (the explicit retirement step) must not take its
-- successor down with it -- that would turn "retire the old key" into "break the new one",
-- exactly the gap this feature exists to avoid. No UNIQUE constraint: nothing here needs to
-- refuse rotating the same key twice, and a key that already has a successor is a UI
-- decision (offer nothing further), not a data integrity rule.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per
-- ADR-0008's retirement.

ALTER TABLE api_keys ADD COLUMN rotated_from_id INTEGER REFERENCES api_keys(id) ON DELETE SET NULL;
