# Chores

Requires `FEATURE_FLAG_CHORES`. Chores follow the same shape as batteries and tasks: an
overview of what is due, a tracking action that records completion, a journal of every past
completion, and master data behind the schedule.

- **`/choresoverview`** — due and overdue chores, each with a "track" shortcut.
- **`/choretracking`** — records that a chore was done now (or on a chosen date/time), and
  who did it when `FEATURE_FLAG_CHORES_ASSIGNMENTS` rotates assignment between household
  members. The overview's two tracking buttons — "track next chore schedule" and "track
  chore execution now" — can be swapped with the per-user setting
  `chores_overview_swap_tracking_buttons`.
- **`/choresjournal`** — every past execution, undoable individually.
- **`/chores`** / **`/chore/{id}`** — the chore list and edit form: schedule type and
  period, assignment rotation, and the due-soon threshold override.
- **`/chore/{id}/grocycode`** renders that chore's Grocycode for a printed label.

## Settings

**`/choressettings`** sets the household-wide "due soon" day threshold
(`chores_due_soon_days`) that colors the overview.
