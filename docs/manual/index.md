# Manual

For someone running Victual, rather than changing it. Start with
[Getting started](getting-started.md) if this is a new installation; otherwise use the
section that matches what you are trying to do.

- **[Getting started](getting-started.md)** — installing from a checkout or from the Nix
  images, PostgreSQL, and the first login.
- **[Configuration](configuration.md)** — every setting in `config-dist.php`, grouped by
  what it affects.
- **Using Victual** — what each part of the application does and how the household tasks
  behind it work: [stock](using-victual/stock.md), [shopping lists](using-victual/shopping-lists.md),
  [recipes and meal plan](using-victual/recipes-and-meal-plan.md), [chores](using-victual/chores.md),
  [batteries](using-victual/batteries.md), [tasks](using-victual/tasks.md),
  [equipment](using-victual/equipment.md), [the calendar](using-victual/calendar.md),
  [administration](using-victual/administration.md) (users, roles and API keys), and
  [tips](using-victual/tips.md) (date shortcuts, keyboard shortcuts, installing as an app).
- **Operator reference** — [the REST API](operator/rest-api.md),
  [barcodes and scanning](operator/barcodes-scanning.md), [label printing](operator/label-printing.md),
  [Home Assistant and MQTT](operator/home-assistant-mqtt.md),
  [roles and permissions](operator/roles-permissions.md),
  [updating and migrations](operator/updating-migrations.md), and
  [backup and restore](operator/backup-restore.md).

For why Victual is built the way it is — PostgreSQL as the only engine, no persistent
application volume, the label subsystem's shape — see the
[Development section's decision records](../adr/README.md) instead. This Manual describes
what the application does today; it does not argue for the decisions behind it.
