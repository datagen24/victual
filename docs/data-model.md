# Data model

Victual stores everything in one PostgreSQL database: 46 tables defined in DDL, 44 views
layered on top of them, and 55 triggers that stand in for the constraints the schema does
not declare. This document names what is where; the six diagrams listed below show how
the pieces connect.

Two facts shape every diagram below and are worth stating before the pictures:

- **Only four foreign keys are declared in the whole schema**, all four in
  [`db/pgsql/roles-schema.sql`](../db/pgsql/roles-schema.sql) — `role_permissions` to
  `roles` and to `permission_hierarchy`, `user_roles` to `users` and to `roles`. Every
  other `*_id` column is a reference by convention. Referential integrity is maintained by
  the service layer and by triggers such as `remove_recipe_from_meal_plans` and
  `remove_items_from_deleted_shopping_list`, which delete dependents when a parent row
  goes. A `DELETE` issued outside those paths leaves orphans, and nothing in the engine
  stops it.
- **Views are the read model.** The `uihelper_*` and `*_resolved` views carry the joins and
  the derived amounts; services and controllers read them the same way they read tables,
  through LessQL. Three of them are expensive enough that triggers materialise them into
  `cache__*` tables instead.

## The diagrams

Each file is a self-contained HTML page — open it in a browser, no server and no build
step. They render at 1100px or wider and scroll horizontally below that.

| Diagram | Shows |
|---|---|
| [Data access · from request to engine](diagrams/orm-stack.html) | How a request reaches the database: controllers and services, LessQL, `DatabaseService`, the dialect, and the work deferred to commit. |
| [Schema map](diagrams/schema-map.html) | All 46 tables as six clusters, and the columns by which one cluster names another's rows. |
| [Stock & products](diagrams/erd-stock.html) | The hub cluster: `products` and the eight tables around it. |
| [Recipes & meal plan](diagrams/erd-recipes.html) | Recipes, their line items, recipe nesting, and the meal plan. |
| [Identity & access](diagrams/erd-identity.html) | Users, sessions, API keys, the permission tree, and the roles cluster — the only part with declared foreign keys. |
| [Household](diagrams/erd-household.html) | Chores, tasks, batteries, and the userfields pair that can attach to any of them. |

An entity-relationship diagram holds at most eight entities before it stops being
readable, which is why the schema is split across four of them rather than drawn once.
Where a diagram references an entity that another diagram owns, the field is marked `↗`.

## Data access

Business logic lives in `services/`; routes in `routes.php`. Controllers and services hold
the same LessQL connection, so there are two entry points into the database and a single
PDO connection underneath both.

- **`BaseService`** hands every service subclass `DatabaseService::GetInstance()->GetDbConnection()`
  in its constructor, and `GetInstance()` makes each subclass a singleton.
  **`BaseController`** takes the same connection in its constructor unless it opts out.
- **LessQL** — the `berrnd/lessql` fork of `morris/lessql`, pinned at `dev-master-fork` —
  is the whole ORM. There are no model classes: `$this->DB->products()->where(...)`
  resolves the table name at call time, which is why a view is queryable through exactly
  the same call as a table. `GenericEntityApiController` depends on that — it substitutes
  the entity name from the URL directly, gated by the `ExposedEntity` enums in
  `victual.openapi.json`. Primary keys are inferred from column names, so the two tables
  with composite keys, `user_roles` and `role_permissions`, are declared explicitly through
  `setPrimary()` in `DatabaseService::GetDbConnection()`.
- **`DatabaseService`** owns the single PDO connection, the LessQL wrapper, and the
  dialect. Its query callback fires on every LessQL statement, and `ExecuteDbStatement()`
  covers the raw SQL that bypasses LessQL, so change tracking sees both. It also owns
  transaction nesting (`InTransaction()` counts depth; only the outermost commits) and
  `RunAsBookkeeping()`, which suppresses change tracking for writes that are not user data.
- **`PostgresDialect`** holds everything engine-specific: identifier quoting, advisory
  locks for migrations and publications, the `REGEXP`/`LIKE` rewrites, and the db-changed
  timestamp. It is reached only through `DatabaseService::GetDialect()`.
- **After the outermost commit**, registered listeners run: the outbox drainer, MQTT state
  publication, and the Influx booking-event writer. None of them writes inside the caller's
  transaction, and all three are independently configurable.

`services/Database/` also holds the pieces that operate on stored values rather than on
queries: `StoredHtmlPurifier` (re-purifies rich text already in the database),
`StoredApiKeyHasher` (hashes keys stored in plaintext by an older version),
`ColumnTypeManifest` (semantic types for columns the catalogue cannot classify, used by
the API's generic filter validation), and `DatabaseImporter`.

## The 46 tables

`migrations` is not listed: `DatabaseMigrationService` creates it on every engine before
the baseline loads, because it is what records that the baseline was applied.

**Stock & products (12)** — `products`, `product_groups`, `product_barcodes`,
`quantity_units`, `quantity_unit_conversions`, `locations`, `shopping_locations`, `stock`,
`stock_log`, `stock_entry_origins`, `shopping_list`, `shopping_lists`.

`stock` holds current entries and `stock_log` is the append-only ledger; a consumed entry
disappears from `stock` while its bookings stay in the ledger the views read.
`stock_entry_origins` (migration 0267) links an entry split off by a partial open back to
the purchase it came from, because the split entry has no `stock_log` row of its own.

**Identity & access (11)** — `users`, `user_settings`, `user_settings_defaults`,
`sessions`, `api_keys`, `user_permissions`, `permission_hierarchy`, `roles`,
`role_permissions`, `user_roles`, `login_attempts`.

`permission_hierarchy` is a self-referencing tree: holding a parent permission grants every
child. `permission_tree` expands it and `user_permissions_resolved` unions direct grants
with grants inherited through roles, which is the view every authorisation check reads.

**Household (7)** — `chores`, `chores_log`, `tasks`, `task_categories`, `batteries`,
`battery_charge_cycles`, `equipment`. `equipment` references nothing and is referenced by
nothing, so it does not appear in the household diagram.

**Caches & infrastructure (7)** — `cache__products_average_price`,
`cache__products_last_purchased`, `cache__quantity_unit_conversions_resolved`, `files`,
`outbox`, `mqtt_product_entities`, `mqtt_published_entities`.

The three `cache__*` tables are maintained entirely by triggers — 30 of the 55 write to one
of them — and are read by the views as if they were views themselves. `files` is database
file storage ([plan 01](plans/01-file-storage.md)); `outbox` carries MQTT and InfluxDB
events out of the request transaction ([plan 18](plans/18-mqtt-state-publication.md)).

**Recipes & meal plan (5)** — `recipes`, `recipes_pos`, `recipes_nestings`, `meal_plan`,
`meal_plan_sections`. `recipes_nestings` names a recipe twice; `prevent_self_nested_recipes`
and `prevent_infinite_nested_recipes` are what keep it acyclic.

**Extensibility (4)** — `userfields`, `userfield_values`, `userentities`, `userobjects`.
`userfields.entity` is a table name held as text and `userfield_values.object_id` is an id
held as text, so a userfield can attach to a row in any table without the schema recording
which. `userentities` and `userobjects` go further: they let an installation define an
entity the schema has never heard of.

## Keeping this current

The diagrams are generated, not hand-drawn. The entity lists, coordinates, and connector
routes are a Python spec in [`.devtools/diagrams/build.py`](../.devtools/diagrams/build.py);
see [its README](../.devtools/diagrams/README.md) for how to run it and what to check
afterwards.

Update them when a migration adds or removes a table, changes a reference column, or moves
a table between clusters — the table counts printed on the schema map and the ORM diagram
will be wrong otherwise. A migration that only adds a non-referencing column does not
require a redraw.
