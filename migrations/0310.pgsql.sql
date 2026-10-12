-- Issue #865 (2026-10-12 audit; maintainer decision of the same day): undoing a chore
-- execution restores the reschedule date and the reschedule assignee that tracking
-- cleared.
--
-- ChoresService::TrackChore() clears chores.rescheduled_date and
-- chores.rescheduled_next_execution_assigned_to_user_id when an execution is recorded,
-- because the reschedule applied to the execution being recorded. Undoing that execution
-- put the chore back into the "due again" state but left both fields empty, so the
-- person's reschedule and the assignee they chose were lost. The two columns added here
-- hold the values tracking cleared, on the execution row that cleared them, so
-- UndoChoreExecution() can put them back.
--
-- TIMESTAMPTZ for the date, the type chores.rescheduled_date has since migration 0301
-- (ADR-0027 decision 2). The assignee is an INTEGER with no foreign key, as
-- chores.rescheduled_next_execution_assigned_to_user_id is: the chore column names a user
-- without constraining it, and the saved copy follows it.
--
-- Nullable, with no backfill: NULL means "nothing to restore", true for an execution that
-- cleared no reschedule and for one recorded before this migration, whose cleared values
-- are gone. Undoing such a row leaves the chore's fields as they are, which is what
-- undo did before.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per
-- ADR-0008's retirement.

ALTER TABLE chores_log ADD COLUMN prior_rescheduled_date TIMESTAMPTZ;
ALTER TABLE chores_log ADD COLUMN prior_rescheduled_next_execution_assigned_to_user_id INTEGER;
