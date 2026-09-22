# Data model

Victual stores everything in one PostgreSQL database: tables defined in DDL, views layered
on top of them, and triggers that stand in for the constraints the schema does not declare.

The DDL files define 71 tables and 50 views, with 65 triggers. A migrated database holds two
more base tables, which are created at run time and appear on no diagram: `migrations`
(by `DatabaseMigrationService`) and `system_db_changed_time` (by `PostgresDialect`).
Counted 2026-09-19 against PostgreSQL 16 after `bin/victual-migrate`; the query and the
matching file-based count are in the [diagram generator's README](../.devtools/diagrams/README.md).

This document names what is where; the ten diagrams listed below show how the pieces
connect.

Two facts shape every diagram below:

- **Few foreign keys are declared outside the label subsystem.** Of the 46 declared in
  the schema, 36 belong to the label tables (plans [25](plans/25-label-infrastructure.md)
  and [27](plans/landed/27-label-templates-and-rendering.md)). The other ten are
  `api_keys.rotated_from_id`, `locations.storage_class_id`, `locations.tare_qu_id`,
  `permission_fields.permission_name`, two on `product_location_min_stock`, and the four
  role join keys in [`db/pgsql/roles-schema.sql`](../db/pgsql/roles-schema.sql) —
  `role_permissions` to `roles` and to `permission_hierarchy`, `user_roles` to `users` and
  to `roles`. Every other `*_id` column is a reference by convention. Referential integrity is maintained by
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
| [Schema map](diagrams/schema-map.html) | All 71 tables as seven clusters, and the columns by which one cluster names another's rows. |
| [Stock & products](diagrams/erd-stock.html) | The hub cluster: `products` and the seven tables around it, including `product_substitutions`. |
| [Places](diagrams/erd-locations.html) | Locations and their tree, storage classes, stores, shopping lists, and per-location minimums. |
| [Recipes & meal plan](diagrams/erd-recipes.html) | Recipes, their line items, recipe nesting, and the meal plan. |
| [Identity](diagrams/erd-identity.html) | Users, sessions, API keys (type, read-only flag, rotation), and user settings. |
| [Access](diagrams/erd-access.html) | Roles, the permission tree, and `permission_fields` — the field-level policy behind price redaction. |
| [Household](diagrams/erd-household.html) | Chores, tasks, batteries, and the userfields pair that can attach to any of them. |
| [Labels](diagrams/erd-labels.html) | Label identity, templates and their versions, media profiles, captures, render requests and artifacts. |
| [Printing](diagrams/erd-printing.html) | Workers, drivers, printers, print jobs, attempts, and evidence. |

An entity-relationship diagram holds at most eight entities before it stops being
readable, which is why the schema is split across eight of them rather than drawn once.
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
- **Around the outermost commit**, registered work runs. The outbox row is written inside
  the transaction, just before it commits, so an event exists if and only if the change
  does. MQTT state publication and the Influx booking-event writer run after the commit, at
  the end of the request. All three are independently configurable.
- **The label services** take the raw PDO connection and run hand-written SQL on it, which
  LessQL's query callback never sees. Their controllers therefore open the transaction
  through `DatabaseService::InTransaction()` (`BaseApiController::InRequestTransaction()`)
  and call `DatabaseService::MarkDbChanged()` after a commit that wrote, so the db-changed
  time advances. Worker polling — claims, heartbeats, registration, printer status —
  deliberately does not.

`services/Database/` also holds the pieces that operate on stored values rather than on
queries. These are `StoredHtmlPurifier` (re-purifies rich text already in the database),
`StoredApiKeyHasher` (hashes keys stored in plaintext by an older version),
`ColumnTypeManifest` (semantic types for columns the catalogue cannot classify, used by
the API's generic filter validation), and `DatabaseImporter`.

## The tables

`migrations` is not listed: `DatabaseMigrationService` creates it on every engine before
the baseline loads, because it is what records that the baseline was applied.

**Stock & products (15)** — `products`, `product_groups`, `product_barcodes`,
`product_substitutions`, `quantity_units`, `quantity_unit_conversions`, `locations`,
`storage_classes`, `product_location_min_stock`, `shopping_locations`, `stock`, `stock_log`,
`stock_entry_origins`, `shopping_list`, `shopping_lists`.

`stock` holds current entries and `stock_log` is the append-only ledger; a consumed entry
disappears from `stock` while its bookings stay in the ledger the views read.
`shopping_lists.shopping_location_id`, `products.default_shopping_list_id` and
`recipes.default_shopping_list_id` (migration 0286,
[plan 05](plans/05-store-shopping-lists.md)) tie a list to a store and name the list a
product or recipe adds to by default; like the other references they are by convention.

`stock_entry_origins` (migration 0267) links an entry split off by a partial open back to
the purchase it came from, because the split entry has no `stock_log` row of its own.

`locations` is a tree since migration 0273 ([plan 08](plans/landed/08-nested-locations.md)):
`parent_location_id` is a reference by convention like every other, and `locations_resolved`
is the recursive view over it, one row per (ancestor, descendant) pair plus each location
paired with itself at depth 0, carrying the descendant's display path. Its name is unique
only among siblings — `UNIQUE NULLS NOT DISTINCT (parent_location_id, name)`, which is why
the engine minimum is PostgreSQL 15. Three guards stand in for the constraints the shape
would need: `check_location_parent` refuses a cycle and a chain past
`hierarchy_depth_limit()`, and `guard_location_children` refuses deleting a parent.

`storage_classes` (migration 0274, [plan 23](plans/landed/23-storage-classes.md)) is how cold a
location is kept — Deep freeze, Freezer, Fridge, Cooler, Ambient, seeded in PHP per
[ADR-0003](adr/0003-seed-data-in-php.md) and user-extensible beyond those five.
`locations.storage_class_id` references it and is nullable; NULL means unclassified, which
is every location's meaning before this migration and stays available afterwards (question
3). `is_freezer` keeps its exact meaning and is derived from the chosen class's
`treats_as_freezer` in the write path (`GenericEntityApiController::WithDerivedIsFreezer()`)
rather than a trigger, because the importer never sets a class at all and there is nothing
for a trigger to fire on. An unclassified location keeps the flag independently editable.

`product_groups` is a tree since migration 0278 ([plan 30](plans/landed/30-nested-product-groups.md),
[ADR-0023](adr/0023-taxonomy-is-groups-packaging-is-parent-product.md)): the catalogue's
taxonomy — Spices / Garlic / Fresh, Dairy / Cheese — lives in `parent_product_group_id`, the
same shape and the same recursive `product_groups_resolved` view as `locations`, sharing
`hierarchy_depth_limit()`. `products.parent_product_id` keeps its separate, unrelated meaning
(ADR-0023 decision 2): the same product in different packagings, one level deep, enforced by
`trg_enfore_product_nesting_level`. A group may hold products and subgroups at once with no
special case, since `product_group_id` and `parent_product_group_id` are independent columns
(ADR-0023 decision 6) — `Garlic` can be both a product's group and a subgroup's parent in the
same row.

**Identity & access (12)** — `users`, `user_settings`, `user_settings_defaults`,
`sessions`, `api_keys`, `user_permissions`, `permission_hierarchy`, `permission_fields`,
`roles`, `role_permissions`, `user_roles`, `login_attempts`.

`permission_hierarchy` is a self-referencing tree: holding a parent permission grants every
child. `permission_tree` expands it and `user_permissions_resolved` unions direct grants
with grants inherited through roles, which is the view every authorisation check reads.

**Household (7)** — `chores`, `chores_log`, `tasks`, `task_categories`, `batteries`,
`battery_charge_cycles`, `equipment`. `equipment` references nothing and is referenced by
nothing, so it does not appear in the household diagram.

**Caches & infrastructure (7)** — `cache__products_average_price`,
`cache__products_last_purchased`, `cache__quantity_unit_conversions_resolved`, `files`,
`outbox`, `mqtt_product_entities`, `mqtt_published_entities`.

The three `cache__*` tables are maintained entirely by triggers, and are read by the views as if they were views themselves. `files` is database
file storage ([plan 01](plans/landed/01-file-storage.md)); `outbox` carries MQTT and InfluxDB
events out of the request transaction ([plan 18](plans/18-mqtt-state-publication.md)).

**Recipes & meal plan (5)** — `recipes`, `recipes_pos`, `recipes_nestings`, `meal_plan`,
`meal_plan_sections`. `recipes_nestings` names a recipe twice; the functions behind
`prevent_self_nested_recipes` and `prevent_infinite_nested_recipes` (four triggers, insert
and update each) are what keep it acyclic.

**Labels & printing (21)** — identity and templates: `labels`, `label_import_state`,
`label_templates`, `label_template_drafts`, `label_template_versions`, `label_assets`,
`label_media_profiles`, `label_captures`, `label_render_requests`, `label_artifacts`,
`label_idempotency_keys`; printing: `label_workers`, `label_drivers`,
`label_worker_capabilities`, `label_printers`, `label_printer_status`,
`label_worker_sessions`, `label_worker_credentials`, `print_jobs`, `print_attempts`,
`print_evidence`. Plans [25](plans/25-label-infrastructure.md) and
[27](plans/landed/27-label-templates-and-rendering.md) own them.

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
