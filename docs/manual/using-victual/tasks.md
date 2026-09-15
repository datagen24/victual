# Tasks

Requires `FEATURE_FLAG_TASKS`. General to-dos, distinct from chores in that a task has no
recurrence — it is marked completed once and stays completed.

- **`/tasks`** — open tasks, each markable complete or undoable from here.
- **`/task/{id}`** — the task edit form: name, description, due date and category.
- **`/taskcategories`** / **`/taskcategory/{id}`** — categories used to group tasks.

## Settings

**`/taskssettings`** sets the household-wide "due soon" day threshold
(`tasks_due_soon_days`).
