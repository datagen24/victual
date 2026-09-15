# Batteries

Requires `FEATURE_FLAG_BATTERIES`. Tracks rechargeable batteries and when each was last
charged, in the same overview/tracking/journal/master-data shape as
[Chores](chores.md).

- **`/batteriesoverview`** — batteries due or overdue for a charge.
- **`/batterytracking`** — records a charge cycle now or on a chosen date.
- **`/batteriesjournal`** — every past charge cycle, undoable individually.
- **`/batteries`** / **`/battery/{id}`** — the battery list and edit form, including the
  charge interval and its own due-soon threshold override.
- **`/battery/{id}/grocycode`** renders that battery's Grocycode for a printed label.

## Settings

**`/batteriessettings`** sets the household-wide "due soon" day threshold
(`batteries_due_soon_days`).
