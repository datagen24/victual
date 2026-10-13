-- 2026-10-13 bug bash: chores.consume_product_on_execution is 0 or 1 (issue #923).
--
-- WHAT THIS CHANGES.
--   chores.consume_product_on_execution gets CHECK (consume_product_on_execution IN (0, 1)).
--   The column was SMALLINT NOT NULL DEFAULT 0 with no CHECK (migration 0084; upstream grocy has
--   none either), while its siblings (active, ...) are constrained, ChoresService tests the flag
--   with `== 1`, and the chore form writes 1 or 0. The generic POST/PUT /api/objects/chores
--   wrote any integer, so a 2 or a -1 meant "does not consume" without anyone having said so.
--   A write of any other value now fails with SQLSTATE 23514.
--
-- NULLABILITY. Unchanged, as the maintainer decided: the column is already NOT NULL and this
--   migration leaves that alone. The CHECK by itself would not reject NULL (NULL IN (0, 1) is
--   not false); the NOT NULL does, so there is no NULL case to preflight.
--
-- PREFLIGHT. The constraint is added only if no chore holds another value; otherwise the
--   migration is refused with a report and repairs nothing (ADR-0029). The constraint is added
--   validated, never NOT VALID, and no row is updated or deleted here.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

-- ADR-0029: bounded blocking, validated atomically with the migration record. chores is small,
-- so the lock the ALTER needs anyway is taken up front instead of escalating to it after the check.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE chores IN ACCESS EXCLUSIVE MODE;

DO $$
DECLARE
    bad_count bigint;
    sample text;
BEGIN
    SELECT count(*) INTO bad_count
    FROM chores
    WHERE consume_product_on_execution NOT IN (0, 1);

    IF bad_count > 0 THEN
        SELECT string_agg(format('(%s, %s, %s)', id, name, consume_product_on_execution), ', ' ORDER BY id)
        INTO sample FROM (
            SELECT id, name, consume_product_on_execution
            FROM chores
            WHERE consume_product_on_execution NOT IN (0, 1)
            ORDER BY id LIMIT 10
        ) bad;
        RAISE EXCEPTION 'Migration refused: % chores have a consume_product_on_execution that is not 0 or 1. Sample (chore id, name, value): %. Choose an explicit repair and rerun migration. No chore has been changed.', bad_count, sample
            USING HINT = 'List all: SELECT id, name, consume_product_on_execution FROM chores WHERE consume_product_on_execution NOT IN (0, 1) ORDER BY id;';
    END IF;
END $$;

-- Named as PostgreSQL names an unnamed column CHECK (chores_active_check, ...): table, column, check.
ALTER TABLE chores
    ADD CONSTRAINT chores_consume_product_on_execution_check CHECK (consume_product_on_execution IN (0, 1));
