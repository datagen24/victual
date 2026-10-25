# Issue 1111: permission inventory (what the code does today)

Evidence for the first acceptance criterion of issue 1111 ("Inventory every resource and action, its current
checks, and its required CRUD/action permissions"). Facts about today's tree only. Anything in a column
headed "candidate" is a placeholder for the ADR to fill; it is empty where the inventory would only be guessing.

Abbreviations: **MDE** = `MASTER_DATA_EDIT`, **MDA** = `MASTER_DATA_ADD`, **GEA** =
`controllers/Api/GenericEntityApiController.php`, **ERP** = `controllers/Users/EntityReadPolicy.php`,
**OA** = `victual.openapi.json`, **FAPI** = `controllers/Api/FilesApiController.php`. A bare `NNN` after a
controller name is a line in that file.

## 1. Method

| Item | Value |
|---|---|
| Tree | `42883e0e0eb3ea9be338555eea96c0ab1e68675c` (`master`, "Merge pull request #1110", version 0.5.5), clean worktree |
| Date | 2026-10-25 |
| Run or read | **Read only.** PHP is not installed on this host; nothing was executed against a database, no request was sent. Every cell is static inference from source. |
| Route enumeration | `routes.php` parsed by `.devtools/issue1111/extract-route-permissions.py` (regex over the file). Output: `.devtools/issue1111/routes-raw.tsv` (270 routes: line, verb, path, handler, permission tokens with `NAME@line`). |
| Token extraction | Same script: for each handler it lists every `PERMISSION_*` constant in the method body, plus names of policy objects, and follows `$this->`/`self::` helpers of the same class two levels (`via <helper>`). |
| Hand-added | Everything the script cannot see: `GenericEntityApiController` branch lines (read by hand), `EntityReadPolicy` map, `FilesApiController` group tables, service-layer checks (`grep -nE "CheckPermission\|HasPermission\|CheckMay\|DomainPermission" services/`), blade `permission-*` classes, ADR text. |
| Script blind spots | (1) `Dispatch()` controllers (`LabelPrintersApiController`, `LabelTemplatesApiController`, `LabelWorkerApiController`, `LabelRenderApiController`) show only the top-level check. (2) Policy objects (`EntityReadPolicy`, `UserfileOwnerPolicy`, `FieldPolicy`, `RolesService::CheckMay*`) show as names, not constants. (3) Service-layer checksare not followed. (4) A token on a line is not proof the check is unconditional; branches were read by hand for the generic controller, external lookup, chore execution, and file groups only. |
| Tree seeding | PostgreSQL runtime: 30 base rows in `services/Database/InitialDataSeeder.php:42-71` (comment at :36 confirms the USERS chain shape, copied from `migrations/0110.sql`, which is SQLite-era and only a cross-reference), `+6` `*_VIEW` leaves `db/pgsql/roles-seed.sql:1-6`, `+1` `STOCK_PRICES_VIEW` `db/pgsql/prices-seed.sql:23` (migration 0281/0282), `+3` `BATTERIES_VIEW`/`CALENDAR_VIEW`/`EQUIPMENT_VIEW` `db/pgsql/view-leaves-seed.sql:26-36` (0299), `+1` `MDA` `db/pgsql/master-data-add-seed.sql:30` (0308) = **41** = the 41 `PERMISSION_*` constants at `controllers/Users/User.php:15-56`. |
| Exposure lists | They are not in `config-dist.php`; they are enums in `victual.openapi.json`: `ExposedEntity` :18180 (58 entries), `ExposedEntityNoListing` :18243 (1), `ExposedEntityNoEdit` :18249 (32), `ExposedEntityNoDelete` :18286 (31), `ExposedEntityEditRequiresAdmin` :18322 (2). They are read at `GEA:977-1011`. Custom entities: `ExposedEntity_IncludingUserEntities` :18457. |
| MCP | Section 4 lists the API routes each MCP tool calls (`mcp/src/tools/*.ts`); the sidecar's internals were not read. |
| Not re-run | `.devtools/issue742/route-permissions.php` needs PHP in a container (`localhost/victual:dev`) and prints non-GET routes only. **Not current** (see 5.14); this inventory supersedes it for route coverage and replaces its container requirement with a regex, at the cost of the blind spots above. |
| Resolution semantics | `permission_tree` resolves **downward**: holding a node means holding every descendant (`User::HasPermission` reads `user_permissions_resolved`, `User.php:113-116,275-278`; view in `db/pgsql/roles-schema.sql`). Direct grants (`user_permissions`) and role grants (`role_permissions` via `user_roles`) are unioned. There is no user-group table (`grep` of schema: none) and no per-API-key permission table (`grep key_permissions` over `db migrations services controllers middleware`: none). |

## 2. Permission tree

### 2.1 Hierarchy (parent > child; 41 names)

```
ADMIN
  USERS
    USERS_CREATE
      USERS_EDIT
        USERS_READ
  USERS_EDIT_SELF
  STOCK
    STOCK_PURCHASE
      STOCK_PRICES_VIEW
    STOCK_CONSUME
    STOCK_INVENTORY
    STOCK_TRANSFER
    STOCK_OPEN
    STOCK_EDIT
    STOCK_VIEW
  SHOPPINGLIST
    SHOPPINGLIST_ITEMS_ADD
    SHOPPINGLIST_ITEMS_DELETE
    SHOPPINGLIST_VIEW
  RECIPES
    RECIPES_MEALPLAN
      MEALPLAN_VIEW
    RECIPES_VIEW
  CHORES
    CHORE_TRACK_EXECUTION
    CHORE_UNDO_EXECUTION
    CHORES_VIEW
  BATTERIES
    BATTERIES_TRACK_CHARGE_CYCLE
    BATTERIES_UNDO_CHARGE_CYCLE
    BATTERIES_VIEW
  TASKS
    TASKS_UNDO_EXECUTION
    TASKS_MARK_COMPLETED
    TASKS_VIEW
  EQUIPMENT
    EQUIPMENT_VIEW
  CALENDAR
    CALENDAR_VIEW
  MASTER_DATA_EDIT
    MASTER_DATA_ADD
```

Constant names: `User::PERMISSION_<NAME>` for every node (`User.php:15-56`). Seeds: see Method.

### 2.2 Built-in roles (`db/pgsql/roles-seed.sql:12-23`; role `builtin=1`)

Closure counts are computed by hand from 2.1 and the grant lists (static; not queried).

| Role code | Directly granted | Resolved (closure) | Resolved count | Not held |
|---|---|---|---|---|
| `ADMIN` | `ADMIN` | all | 41 | none |
| `ADULT` | `STOCK`, `SHOPPINGLIST`, `RECIPES`, `RECIPES_MEALPLAN`, `CHORES`, `TASKS`, `BATTERIES`, `EQUIPMENT`, `CALENDAR`, `USERS_READ`, `USERS_EDIT_SELF` | every descendant of those | 35 | `ADMIN`, `USERS`, `USERS_CREATE`, `USERS_EDIT`, `MASTER_DATA_EDIT`, `MASTER_DATA_ADD` |
| `CHILD` | `STOCK_VIEW`, `STOCK_CONSUME`, `STOCK_OPEN`, `SHOPPINGLIST_VIEW`, `SHOPPINGLIST_ITEMS_ADD`, `CHORES_VIEW`, `CHORE_TRACK_EXECUTION`, `TASKS_VIEW`, `TASKS_MARK_COMPLETED`, `RECIPES_VIEW`, `MEALPLAN_VIEW`, `CALENDAR`, `USERS_EDIT_SELF` | those + `CALENDAR_VIEW` | 14 | 27 incl. `MASTER_DATA_*`, `STOCK_PURCHASE`, `STOCK_EDIT`, `BATTERIES*`, `EQUIPMENT*`, `RECIPES`, `CHORES`, `TASKS` |
| `GUEST` | `STOCK_VIEW`, `RECIPES_VIEW`, `MEALPLAN_VIEW` | same | 3 | all others |

- Default grants for new users: `DEFAULT_PERMISSIONS` and `DEFAULT_ROLES` (`VICTUAL_DEFAULT_ROLES`, `services/RolesService.php:98-112`); the manual says both are empty by default (`docs/manual/operator/roles-permissions.md:12-16`).
- Upgrade backfill: `roles-seed.sql:8-10` gave every pre-existing user the six original `*_VIEW` leaves as **direct** grants.
- `.devtools/issue742/RESULTS.md` reports 37 resolved rows for ADULT; the distinct closure here is 35. Probable cause (inferred, not run): `user_permissions_resolved` (`db/pgsql/roles-schema.sql`) joins `permission_tree` (one row per ancestor/descendant) against the held ids, and ADULT holds both `RECIPES` and `RECIPES_MEALPLAN`, so `RECIPES_MEALPLAN` and `MEALPLAN_VIEW` each appear twice (35 + 2 = 37). `ResolvedPermissionNames` dedupes with `array_unique` (`User.php:149-163`) and `HasPermission` uses `fetch()`, so authorization is unaffected.
- `ADULT` holds `CHORES`/`TASKS`/`RECIPES`/`EQUIPMENT` (the parents), which is why it can create chores and tasks since `GEA:69,73` (it could not when issue 742 was measured), and still cannot create batteries, task categories, meal plan sections (`GEA:81`).

## 3. Resource table

One row per **base resource** (a table or a table-like unit the API reads or writes). The 32 read-only views of `ExposedEntityNoEdit` map to the base resource in Appendix A and are **not** rows, otherwise the count inflates by about 30.

How to read the checks:

- **Generic write check** = `GEA` `AddObject` (:49-91), `EditObject` (:412-450), `DeleteObject` (:230-262). Branch lines are cited as `C/U/D` triplets.
- **Generic read check** = `ERP` map (:10-82) called from `GetObjects` (`GEA:574-580`), `GetObject` (`GEA:528-534`), `GetUserfields` (`GEA:653-662`). A read of an entity absent from `ERP` is refused (`ERP:103-105`).
- In all three write methods the permission check runs **before** the exposure check (`GEA:51-82` before :84; :232-255 before :257; :414-441 before :443).
- `MDE`'s `else` branch also runs for entities that are not writable (e.g. `api_keys`, `stock`), so an unprivileged caller gets 403 where a privileged one gets 400.
- "Page" = the HTML route; edit-form pages check only the read leaf (5.8).

Candidate column intentionally omitted from this table; it belongs in the ADR. (The ask allows it as a clearly labelled column; leaving it out avoids guessing.)

### 3.1 Stock master data

| # | Resource | Entity / table | Create | Read | Update | Delete | Generic-API exposure | Evidence (file:line) |
|---|---|---|---|---|---|---|---|---|
| 1 | Products | `products` | **MDA** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity; none of NoEdit/NoDelete/NoListing | C `GEA:77`; R `ERP:20`; U `GEA:440`; D `GEA:254`. Page R: `StockController:519,389`. Create also via `ExternalBarcodeLookup?add=true` (4.1). Merge: 4.1 |
| 2 | Product barcodes | `product_barcodes` | **MDA** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:77`; R `ERP:21`; U `GEA:440`; D `GEA:254`; page `StockController:315`. Read view `product_barcodes_view` (App. A) |
| 3 | Locations | `locations` | **MDA** | `STOCK_VIEW` | **MDE** | **MDE** (plus refusals: has children, has stock, mapped) | ExposedEntity | C `GEA:77`; R `ERP:22`; U `GEA:440`; D `GEA:254`, refusals `GEA:291,305,327-337,358`; page `StockController:209,145` |
| 4 | Product groups | `product_groups` | **MDA** | `STOCK_VIEW` | **MDE** | **MDE** (refused if child groups) | ExposedEntity | C `GEA:77`; R `ERP:27`; U `GEA:440`; D `GEA:254,300`; page `StockController:500,458` |
| 5 | Quantity units | `quantity_units` | **MDA** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:77`; R `ERP:24`; U `GEA:440`; D `GEA:254`; page `StockController:666,622` |
| 6 | Quantity unit conversions | `quantity_unit_conversions` | **MDE** (not MDA, by decision) | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:81` (falls to `else`; ADR-0043 decision 2, Accepted 2026-10-12, keeps it at MDE); R `ERP:25`; U `GEA:440`; D `GEA:254`; page `StockController:578` |
| 7 | Stores (shopping locations) | `shopping_locations` | **MDA** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:77`; R `ERP:26`; U `GEA:440`; D `GEA:254`; page `StockController:839,814` |
| 8 | Storage classes | `storage_classes` | **MDE** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:81`; R `ERP:23`; U `GEA:440`; D `GEA:254`. No page route in `routes.php` |
| 9 | Product substitutions | `product_substitutions` | **MDE** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:81`; R `ERP:29`; U `GEA:440`; D `GEA:254`; page `StockController:361` |
| 10 | Product-location minimum stock | `product_location_min_stock` | **MDE** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:81`; R `ERP:31`; U `GEA:440`; D `GEA:254` |
| 11 | MQTT product entities | `mqtt_product_entities` | **MDE** | `STOCK_VIEW` | **MDE** | **MDE** | ExposedEntity (writable per `ExposedEntity_NotIncludingNotEditable`, OA:18329) | C `GEA:81`; R `ERP:42`; U `GEA:440`; D `GEA:254` |

### 3.2 Stock itself

| # | Resource | Entity / table | Create | Read | Update | Delete | Generic-API exposure | Evidence |
|---|---|---|---|---|---|---|---|---|
| 12 | Stock entries | `stock` | n/a (rows appear only through purchase/transfer/inventory; see 4.1) | `STOCK_VIEW` | `STOCK_EDIT` + `STOCK_VIEW` (edit entry, measure, weigh) | n/a (rows leave through consume/transfer/merge; see 4.1) | ExposedEntity, NoEdit, NoDelete | R `ERP:34`, `StockApiController:743,1429`; U `StockApiController:810-813,932-935,1584-1587`; generic U/D refused by NoEdit/NoDelete (OA:18249,18286); but see 5.5 for the 403/400 split |
| 13 | Stock log (bookings) | `stock_log` | n/a (written by bookings) | `STOCK_VIEW` (price fields additionally `STOCK_PRICES_VIEW`) | n/a | n/a (history is never deleted; bookings are undone) | ExposedEntity, NoEdit, NoDelete | R `ERP:33`, `StockApiController:1400`; undo is an action (4.1); `permission_fields` redaction `FieldPolicy` (`BaseApiController:331,356`) |

### 3.3 Shopping

| # | Resource | Entity / table | Create | Read | Update | Delete | Exposure | Evidence |
|---|---|---|---|---|---|---|---|---|
| 14 | Shopping lists (headers) | `shopping_lists` | `SHOPPINGLIST_ITEMS_ADD` | `SHOPPINGLIST_VIEW` | `SHOPPINGLIST_ITEMS_ADD` | `SHOPPINGLIST_ITEMS_DELETE` | ExposedEntity | C `GEA:53`; R `ERP:44`; U `GEA:416`; D `GEA:234`; page `StockController:743` |
| 15 | Shopping list items | `shopping_list` | `SHOPPINGLIST_ITEMS_ADD` | `SHOPPINGLIST_VIEW` (shortfall columns need `STOCK_VIEW`, ADR-0050) | `SHOPPINGLIST_ITEMS_ADD` | `SHOPPINGLIST_ITEMS_DELETE` | ExposedEntity | C `GEA:53`; R `ERP:43`; U `GEA:416`; D `GEA:234`; page `StockController:690,768,715`; also 4.1 shopping-list actions |

### 3.4 Recipes and meal plan

| # | Resource | Entity / table | Create | Read | Update | Delete | Exposure | Evidence |
|---|---|---|---|---|---|---|---|---|
| 16 | Recipes | `recipes` | `RECIPES` (the **parent** node) | `RECIPES_VIEW` | `RECIPES` | `RECIPES` | ExposedEntity | C `GEA:57`; R `ERP:46`; U `GEA:420`; D `GEA:238`; page `RecipesController:224,322` |
| 17 | Recipe ingredients | `recipes_pos` | `RECIPES` | `RECIPES_VIEW` | `RECIPES` | `RECIPES` | ExposedEntity | C `GEA:57`; R `ERP:47`; U `GEA:420`; D `GEA:238`; page `RecipesController:365` |
| 18 | Recipe nestings | `recipes_nestings` | `RECIPES` | `RECIPES_VIEW` | `RECIPES` | `RECIPES` | ExposedEntity | C `GEA:57`; R `ERP:48`; U `GEA:420`; D `GEA:238` |
| 19 | Meal plan entries | `meal_plan` | `RECIPES_MEALPLAN` | `MEALPLAN_VIEW` | `RECIPES_MEALPLAN` | `RECIPES_MEALPLAN` | ExposedEntity | C `GEA:61`; R `ERP:50`; U `GEA:424`; D `GEA:242`; page `RecipesController:30` |
| 20 | Meal plan sections | `meal_plan_sections` | **MDE** | `MEALPLAN_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:81`; R `ERP:51`; U `GEA:440`; D `GEA:254`; pages `RecipesController:429,408` gate on `MEALPLAN_VIEW` |

### 3.5 Chores, tasks, batteries, equipment

| # | Resource | Entity / table | Create | Read | Update | Delete | Exposure | Evidence |
|---|---|---|---|---|---|---|---|---|
| 21 | Chores | `chores` | `CHORES` (parent) | `CHORES_VIEW` | `CHORES` (parent) | **MDE** | ExposedEntity | C `GEA:69`; R `ERP:52`; U `GEA:432`; D `GEA:254` (no `chores` branch in `DeleteObject`); page `ChoresController:69,28` |
| 22 | Chore log (executions) | `chores_log` | n/a (written by execute; 4.2) | `CHORES_VIEW` | n/a | n/a (undone, not deleted) | ExposedEntity, NoEdit, NoDelete | R `ERP:53`; page `ChoresController:103` |
| 23 | Tasks | `tasks` | `TASKS` (parent) | `TASKS_VIEW` | `TASKS` (parent) | **MDE** | ExposedEntity | C `GEA:73`; R `ERP:54`; U `GEA:436`; D `GEA:254`; page `TasksController:28,127` |
| 24 | Task categories | `task_categories` | **MDE** | `TASKS_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:81`; R `ERP:55`; U `GEA:440`; D `GEA:254`; page `TasksController:78,102`. ADR-0043 open question 2 keeps MDE |
| 25 | Batteries | `batteries` | **MDE** | `BATTERIES_VIEW` | **MDE** | **MDE** | ExposedEntity | C `GEA:81`; R `ERP:71`; U `GEA:440`; D `GEA:254`; page `BatteriesController:28,63`. ADR-0043 open question 2 keeps MDE; ADR-0035 set the read leaf |
| 26 | Battery charge cycles | `battery_charge_cycles` | n/a (written by charge; 4.3) | `BATTERIES_VIEW` | n/a | n/a (undone) | ExposedEntity, NoEdit, NoDelete | R `ERP:72`; page `BatteriesController:95` |
| 27 | Equipment | `equipment` | `EQUIPMENT` (parent) | `EQUIPMENT_VIEW` | `EQUIPMENT` | `EQUIPMENT` | ExposedEntity | C `GEA:65`; R `ERP:73`; U `GEA:428`; D `GEA:246`; page `EquipmentController:47,23` |

### 3.6 User fields, custom entities, files

| # | Resource | Entity / table | Create | Read | Update | Delete | Exposure | Evidence |
|---|---|---|---|---|---|---|---|---|
| 28 | Userfield definitions | `userfields` | **MDE** + `ADMIN` | **none** (any authenticated caller) | **MDE** + `ADMIN` | **MDE** + `ADMIN` | ExposedEntity; EditRequiresAdmin | C `GEA:81,88`; R `ERP:77` (null); U `GEA:440,447`; D `GEA:254,261`; pages `GenericEntityController:25-33` need MDE + `ADMIN`. Read left open on purpose (ADR-0035 amendment, #869; comment `ERP:75-76`) |
| 29 | Custom entity definitions | `userentities` | **MDE** + `ADMIN` | **none** | **MDE** + `ADMIN` | **MDE** + `ADMIN` | ExposedEntity; EditRequiresAdmin | same lines as 28; `ERP:78` |
| 30 | Custom entity rows | `userobjects` | **MDE** | **MDE** | **MDE** | **MDE** | ExposedEntity | C `GEA:81`; R `ERP:81`; U `GEA:440`; D `GEA:254`; pages `GenericEntityController:120,148` need MDE |
| 31 | Userfield values (per record) | stored under the owning entity name; custom-entity values under `userentity-<name>` | n/a (values are set, not created) | the owning entity's read gate; `userentity-*` needs **MDE** | **MDE** | n/a | `GET/PUT /api/userfields/{entity}/{id}`; `userentity-*` via `ExposedEntity_IncludingUserEntities` | R `GEA:653-662`, `ERP:94-111` (userentity at :96-100); U `GEA:676-678`; ownership guard `UserfileOwnerPolicy::GuardOwnershipChange` (`GEA:692`) |
| 32 | Files: product pictures | group `productpictures` | **MDE** (upload) | `STOCK_VIEW` | n/a (exclusive create; replace is delete + upload) | **MDE** | `/api/files/{group}/{file}` | W `FAPI:52`; R `FAPI:97` |
| 33 | Files: recipe pictures | `recipepictures` | `RECIPES` | `RECIPES_VIEW` | n/a | `RECIPES` | same | W `FAPI:53`; R `FAPI:98` |
| 34 | Files: equipment manuals | `equipmentmanuals` | `EQUIPMENT` | `EQUIPMENT_VIEW` | n/a | `EQUIPMENT` | same | W `FAPI:54`; R `FAPI:99` |
| 35 | Files: userfield files | `userfiles` | **MDE** | owner-governed: every record referencing the file must admit the reader (`UserfileOwnerPolicy`) | n/a | **MDE** + ownership guard | same | W `FAPI:55`; R `FAPI:100`, `UserfileOwnerPolicy` (ADR-0048); upload/delete `FAPI:405-412,281-288` |
| 36 | Files: user pictures | `userpictures` | `USERS_EDIT` **or** `USERS_EDIT_SELF` | `USERS_READ` (own picture exempt) | n/a | group write check `USERS_EDIT` or `USERS_EDIT_SELF` first (`FAPI:59`), then `USERS_EDIT` + `MayAdminister` unless own and unshared (`CheckUserPictureDeletion`) | same | W `FAPI:59`; R `FAPI:101,217-229`; delete `FAPI:483-497` |

### 3.7 Label subsystem

All label management is `ADMIN`. Worker/renderer machine routes use credentials, not permissions (4.5).

| # | Resource | Entity / table | Create | Read | Update | Delete | Exposure | Evidence |
|---|---|---|---|---|---|---|---|---|
| 37 | Label printers | `label_printers` | `ADMIN` | `ADMIN` | `ADMIN` | `ADMIN` | ExposedEntity, NoEdit, NoDelete (generic is read-only) | dedicated routes `routes.php:230-233` -> `LabelPrintersApiController:16`; R `ERP:14`; page `LabelPrintJobsController:19` |
| 38 | Label workers | `label_workers` | `ADMIN` | `ADMIN` | `ADMIN` | n/a (credentials are revoked, the row is not deleted) | ExposedEntity, NoEdit, NoDelete | `routes.php:234-238` -> `:16`; R `ERP:11` |
| 39 | Label drivers | `label_drivers` | n/a | `ADMIN` | n/a | n/a | ExposedEntity, NoEdit, NoDelete | R `ERP:12`, `routes.php:241` |
| 40 | Worker capabilities | `label_worker_capabilities` | n/a | `ADMIN` | n/a | n/a | same | `ERP:13` |
| 41 | Printer status | `label_printer_status` | n/a (worker writes) | `ADMIN` | n/a | n/a | same | `ERP:15` |
| 42 | Label templates | `label_templates` | `ADMIN` | `ADMIN` | `ADMIN` (draft PUT) | n/a (archive, not delete) | same | `routes.php:255-263` -> `LabelTemplatesApiController:30`; R `ERP:64`; page `LabelTemplatesController:29,45` |
| 43 | Template versions | `label_template_versions` | n/a (publish creates one) | `ADMIN` | n/a | n/a | same | `ERP:65`; `routes.php:259-261` |
| 44 | Label assets | `label_assets` | `ADMIN` | `ADMIN` | n/a | n/a | same | `ERP:66`; `routes.php:264-266` |
| 45 | Media profiles | `label_media_profiles` | n/a | `ADMIN` | n/a | n/a | same | `ERP:67` |
| 46 | Render requests | `label_render_requests` | n/a (created by preview/print) | `ADMIN` | n/a | n/a | same | `ERP:68`; `routes.php:267` |
| 47 | Print jobs | `print_jobs` | n/a as CRUD; created by the print actions (4.4) | `ADMIN` (generic) and `GET /api/labels/jobs` | n/a | n/a (cancel is an action) | ExposedEntity, NoEdit, NoDelete | `ERP:16`; `routes.php:239`; page `LabelPrintJobsController:13` |
| 48 | Print attempts | `print_attempts` | n/a (worker) | `ADMIN` | n/a | n/a | same | `ERP:17` |
| 49 | Print evidence | `print_evidence` | n/a (worker) | `ADMIN` | n/a | n/a | same | `ERP:18` |

`label_artifacts` and `label_captures` are deliberately absent from `ExposedEntity` and `ERP` (comment `ERP:59-63`).

### 3.8 Users, roles, keys, settings, medication consumption, calendar

| # | Resource | Entity / table | Create | Read | Update | Delete | Exposure | Evidence |
|---|---|---|---|---|---|---|---|---|
| 50 | Users | `users` | `USERS_CREATE` + `CheckMayGrant` | `USERS_READ` | `USERS_EDIT` + `MayAdminister`; own record `USERS_EDIT_SELF` | `USERS_EDIT` + `MayAdminister` | **not in `ExposedEntity`** although `ERP:58` has a row; dedicated `/api/users` | C `UsersApiController:72-87`; R :598 (`GetUsers`); U :163-178,363; D :116-123; pages `UsersController:125,63-88`. `MayAdminister`/`CheckMayGrant`: `User.php:177-264` (ADR-0014) |
| 51 | User direct permission grants | `user_permissions` | `USERS_EDIT` + `MayAdminister` + `CheckMayGrant` | `USERS_READ` | `USERS_EDIT` + same (PUT replaces the set) | n/a as a distinct route (removal = PUT without the id) | dedicated routes | `UsersApiController:35-53,651,670-684`; `routes.php:215-217` |
| 52 | Permissions (the tree) | `permission_hierarchy` | n/a | `USERS_READ` | n/a | n/a | ExposedEntity, NoEdit, NoDelete | `ERP:56` |
| 53 | Roles | `roles` | `USERS_EDIT` | `USERS_READ` | `USERS_EDIT` + `CheckMayManage` | `USERS_EDIT` + `CheckMayManage` | ExposedEntity, NoEdit, NoDelete; dedicated `/api/roles` | `RolesApiController:14,20,40,60`; `RolesService:147-153`; R `ERP:57`; pages `UsersController:139-165` |
| 54 | Role permission grants | `role_permissions` | n/a (set) | `USERS_READ` | `USERS_EDIT` + `CheckMayManage` + `CheckMayGrant` | n/a (set) | dedicated | `RolesApiController:80,90`; `RolesService:181-186` |
| 55 | User role assignments | `user_roles` | n/a (set) | `USERS_READ` | `USERS_EDIT` + `MayAdminister` + `CheckMayAssign` | n/a (set) | dedicated | `RolesApiController:100,106`; `RolesService:160-169` |
| 56 | API keys | `api_keys` | **none** (any authenticated user, own key) via `POST /manageapikeys/new` | page lists own keys; `ADMIN` sees all | n/a (rotation creates a successor) | owner **or** `ADMIN`, via generic `DELETE` | ExposedEntity, NoListing, NoEdit | `OpenApiController:50` (ADMIN switch), :145 (rotate); `GEA:248-250,274-279`; `ERP:74` (null) |
| 57 | User settings | `user_settings` | n/a: PUT upserts (counted under Update) | none (self only) | none (self only) | none (self only) | `/api/user/settings*` | `routes.php:293-296`; handlers have no tokens (`routes-raw.tsv`) |
| 58 | Medication presets (consumption recipes) | `consumption_recipes` | `STOCK_VIEW` + `STOCK_CONSUME` | `STOCK_VIEW` + owner/share | `STOCK_VIEW` + share right `edit` | `STOCK_VIEW` + **owner only** | not exposed (ADR-0040) | `ConsumptionRecipesApiController:70-115`; service `ConsumptionRecipeService:96-113,236-295`. Per-record rights `read/consume/edit/undo/share/owner` from `consumption_recipe_shares` |
| 59 | Preset shares | `consumption_recipe_shares` | `STOCK_VIEW` + owner or share right `share` | same | same | same | not exposed | `ConsumptionRecipesApiController:161-235`; `ConsumptionRecipeService:750-905` |
| 60 | Refill ledger (settings, fills, orders, notices) | refill tables | `STOCK_VIEW` + recipe right `edit` | `STOCK_VIEW` + recipe right | `STOCK_VIEW` + recipe right `edit` | n/a (void / cancel are actions) | not exposed | `ConsumptionRefillsApiController:52`; `ConsumptionRecipeService::AuthoriseRefill` :150-152 |
| 61 | Consumption events | `consumption_events` | n/a: PUT upserts (counted under Update) | `STOCK_VIEW` (own rows) | `STOCK_VIEW` + `STOCK_CONSUME` | same | not exposed | `ConsumptionEventsApiController:102-109`; `ConsumptionEventService:510-530` |
| 62 | Consumption mappings | `consumption_mappings` | n/a: PUT upserts (counted under Update) | `STOCK_VIEW` (own rows) | `STOCK_VIEW` + `STOCK_CONSUME` | same | not exposed | `ConsumptionEventsApiController:102-109`; `ConsumptionMappingService:77` |
| 63 | Calendar | derived (stock, tasks, chores, batteries, meal plan) | n/a | `CALENDAR_VIEW`, plus per-source view leaf for each event type; iCal also by secret | n/a | n/a | not exposed | `CalendarController:21`; `CalendarApiController:67,245`; `CalendarService:40,63,82,115,137` |

Instance settings: no table or route exists for editing instance-wide settings through the API; configuration is file-based (`config-dist.php`). The per-user settings pages (`/stocksettings`, `/choressettings`, ...) check only the domain read leaf (5.8). I did not find a separate "settings" resource to inventory.

## 4. Domain actions

Reads that merely have a dedicated route (e.g. `GET /api/stock/products/{id}`) are listed only where they carry a permission different from the generic read.

### 4.1 Stock and shopping

| Action | Route | Current check | Evidence |
|---|---|---|---|
| Purchase (add stock) | `POST /api/stock/products/{id}/add`, `.../by-barcode/{barcode}/add` | `STOCK_PURCHASE` + `STOCK_VIEW` | `StockApiController:343,346,470-471` |
| Consume | `.../consume`, `.../by-barcode/.../consume` | `STOCK_CONSUME` + `STOCK_VIEW` | :602,605,716 |
| Open | `.../open`, by-barcode | `STOCK_OPEN` + `STOCK_VIEW` | :1138,1141,1212 |
| Transfer | `.../transfer`, by-barcode | `STOCK_TRANSFER` + `STOCK_VIEW` | :1488,1491,1547 |
| Inventory (set amount) | `.../inventory`, by-barcode | `STOCK_INVENTORY` + `STOCK_VIEW` | :1015,1018,1115 |
| Edit a stock entry | `PUT /api/stock/entry/{id}` | `STOCK_EDIT` + `STOCK_VIEW` | :810,813 |
| Measure an entry / weigh a location | `POST /api/stock/entry/{id}/measure`, `.../locations/{id}/weigh`, `.../by-label/{code}/weigh` | `STOCK_EDIT` + `STOCK_VIEW` | :932,935,1584,1587,1650 |
| Undo a booking / transaction | `POST /api/stock/bookings/{id}/undo`, `/transactions/{id}/undo` | `STOCK_EDIT` only (no `STOCK_VIEW`) | :1682,1701 |
| Merge products | `POST /api/stock/products/{keep}/merge/{remove}` | `STOCK_EDIT` **and** `MDE` | :1723-1724 |
| External barcode lookup | `GET /api/stock/barcodes/external-lookup/{barcode}` | `STOCK_VIEW`; with `add=true` also `MDA` (ADR-0043) | :985,997; listed in `BaseAuthMiddleware::WRITING_GET_ROUTES` :302-306 so read-only keys are refused |
| Price history read | `GET /api/stock/products/{id}/price-history` | `STOCK_VIEW` + `STOCK_PRICES_VIEW` (whole-object gate) | :1276,1281 |
| Price visibility | field redaction on stock, shopping list, recipes, meal plan, journal | `STOCK_PRICES_VIEW` through `permission_fields` rows (`FieldPolicy`) and `User::PricesVisible` (`User.php:291-300`) | `BaseApiController:331-360`; `StockReportsController:35` (spendings report) |
| Add missing / overdue / expired products to list | `POST /api/stock/shoppinglist/add-missing-products`, `add-overdue-products`, `add-expired-products` | `SHOPPINGLIST_ITEMS_ADD` (+ `STOCK_VIEW` for missing, ADR-0050) | :238-239,275,308 |
| Add / remove product on list; clear list | `.../add-product`, `.../remove-product`, `.../clear` | add `SHOPPINGLIST_ITEMS_ADD`; remove and clear `SHOPPINGLIST_ITEMS_DELETE` | :489,1348,556 |
| Print shopping list (thermal) | `GET /api/print/shoppinglist/thermal` | `SHOPPINGLIST_VIEW` **and** `SHOPPINGLIST` (the parent) | `PrintApiController:25-26`; in `WRITING_GET_ROUTES` |
| Stock reports | `GET /stockreports/spendings` (page) | `STOCK_VIEW` + `STOCK_PRICES_VIEW` | `StockReportsController:28,35` |

### 4.2 Recipes, chores, tasks, batteries

| Action | Route | Current check | Evidence |
|---|---|---|---|
| Recipe: add not-fulfilled products to list | `POST /api/recipes/{id}/add-not-fulfilled-products-to-shoppinglist` | `RECIPES_VIEW` + `SHOPPINGLIST_ITEMS_ADD` | `RecipesApiController:29-30` |
| Recipe: consume | `POST /api/recipes/{id}/consume` | `RECIPES_VIEW` + `STOCK_CONSUME`; a recipe that produces a product also needs `STOCK_PURCHASE` | :59-60; `RecipesService:229` |
| Recipe fulfilment (read) | `GET /api/recipes/{id}/fulfillment`, `/recipes/fulfillment` | `RECIPES_VIEW` (twice); prices by `FieldPolicy` | :77-78,94 |
| Chore: execute (track) | `POST /api/chores/{id}/execute` | `CHORE_TRACK_EXECUTION`; the same again when `done_by` is another user; `STOCK_CONSUME` when the chore consumes a product | `ChoresApiController:114,145`; `ChoresService:359` |
| Chore: undo execution | `POST /api/chores/executions/{id}/undo` | `CHORE_UNDO_EXECUTION`; `STOCK_EDIT` when the execution booked stock | :165; `ChoresService:537` |
| Chore: recalculate next assignments | `POST /api/chores/executions/calculate-next-assignments` | `CHORES` (parent) | :35 |
| Chore: merge | `POST /api/chores/{keep}/merge/{remove}` | `MDE` only (no `CHORES`) | :185 |
| Task: complete | `POST /api/tasks/{id}/complete` | `TASKS_MARK_COMPLETED` | `TasksApiController:35` |
| Task: undo | `POST /api/tasks/{id}/undo` | `TASKS_UNDO_EXECUTION` | :61 |
| Battery: charge | `POST /api/batteries/{id}/charge` | `BATTERIES_TRACK_CHARGE_CYCLE` (+ `BATTERIES_VIEW` for the full row) | `BatteriesApiController:56,71` |
| Battery: undo charge | `POST /api/batteries/charge-cycles/{id}/undo` | `BATTERIES_UNDO_CHARGE_CYCLE` | :87 |
| Current chores / tasks / batteries (read) | `GET /api/chores`, `/chores/{id}`, `/tasks`, `/batteries`, `/batteries/{id}` | `CHORES_VIEW` / `TASKS_VIEW` / `BATTERIES_VIEW` | `ChoresApiController:93,74`; `TasksApiController:21`; `BatteriesApiController:36,22` |
| Calendar export | `GET /api/calendar/ical` | `CALENDAR_VIEW`, skipped when a valid special-purpose calendar secret is presented (ADR-0035) | `CalendarApiController:67` |
| Calendar sharing link | `GET /api/calendar/ical/sharing-link` | `CALENDAR_VIEW`; in `WRITING_GET_ROUTES` | :245 |

### 4.3 Medication consumption (private rows, per-record rights; mixes global and record-level checks)

| Action | Route | Current check | Evidence |
|---|---|---|---|
| Record / resolve / delete consumption events, bulk | `POST /api/consumption/events/batch`, `/resolve`; `PUT/DELETE /events/{src}/{id}`; `POST .../resolve` | `STOCK_VIEW` + `STOCK_CONSUME` | `ConsumptionEventsApiController:107-109`; `ConsumptionEventService:521-528`; linking another user's booking needs `STOCK_EDIT` (:1497-1505) |
| Read events / mappings / capabilities | `GET /api/consumption/events*`, `/mappings*`, `/capabilities` | `STOCK_VIEW` | :102 |
| Put / delete a mapping | `PUT/DELETE /api/consumption/mappings/{src}/{ref}` | `STOCK_VIEW` + `STOCK_CONSUME` | :107-109; `ConsumptionMappingService:77` |
| Preset: list/read/create/update/delete | `/api/consumption/recipes*` | see rows 58-59 | |
| Preset: consume | `POST /api/consumption/recipes/{id}/consume` | `STOCK_VIEW` + `STOCK_CONSUME` + record right `consume` | `ConsumptionRecipesApiController:128-129`; `ConsumptionRecipeService:434,481,496` |
| Preset: undo event | `POST .../events/{eventId}/undo` | `STOCK_VIEW` + `STOCK_EDIT` + record right `undo` | :152-153; `ConsumptionRecipeService:704` |
| Preset: share / unshare / change rights / transfer ownership | `.../shares*`, `.../transfer` | `STOCK_VIEW` + record right `share` or owner | `ConsumptionRecipesApiController:181-235`; service :805-905 |
| Refill: settings, record fill, void fill, record order, receive, cancel, ack notice | `/api/consumption/recipes/{id}/refill*`, `/refills*` | `STOCK_VIEW` + record right | `ConsumptionRefillsApiController:52`; `ConsumptionRecipeService:150-152` |

### 4.4 Labels, users, keys

| Action | Route | Current check | Evidence |
|---|---|---|---|
| Print label / revised print | `POST /api/labels/locations/{id}/print`, `/revised-print`, `/print-subtree`; `POST /api/labels/{kind}/{id}/print`, `/revised-print` (kinds: location, product, stock_entry, recipe, chore, battery) | `MDE` **and** the kind's read leaf (`FieldCatalogue::DomainPermission`: STOCK_VIEW for location/product/stock_entry, `RECIPES_VIEW`, `CHORES_VIEW`, `BATTERIES_VIEW`); the capture rechecks each field's permission | `LabelsApiController:75,86`; `FieldCatalogue:247-256`; `LabelCaptureService:49,122-130` |
| Reprint / cancel a job / promote an artifact | `POST /api/labels/jobs/{id}/reprint`, `/cancel`; `/artifacts/{id}/promote` | `MDE` only (docblock `LabelsApiController:62-70` says this is deliberate: no fresh capture) | :75 (`$kind` is null for these, so line 86 is skipped) |
| Read a label context / resolve a code | `GET /api/labels/{kind}/{id}/context`, `/locations/{id}/context`, `/resolve/{code}` | read leaf of the kind; `resolve` checks per kind inside a callback | `LabelsApiController:29,42,22` |
| Manage printers, workers, templates, assets, pairing, authorize attempt, preview | `/api/labels/printers*`, `/workers*`, `/templates*`, `/assets*`, `/jobs/{id}/authorize-attempt`, `/artifacts/{id}/image` | `ADMIN` | `LabelPrintersApiController:16`; `LabelTemplatesApiController:30,78` |
| List print jobs (page, API) | `/labelprintjobs`, `/labelprinters`, `GET /api/labels/jobs` | `ADMIN` | `LabelPrintJobsController:13,19`; `LabelPrintersApiController:16` |
| Grant a permission directly | `POST/PUT /api/users/{id}/permissions` | `USERS_EDIT` + `MayAdminister` (target's resolved set is a subset of the caller's) + `CheckMayGrant` (closure of the grant is a subset of the caller's) | `UsersApiController:35-53,670-684`; `User.php:177-264` |
| Assign roles | `PUT /api/users/{id}/roles` | `USERS_EDIT` + `MayAdminister` + `CheckMayAssign` | `RolesService:160-169` |
| Set a role's permissions | `PUT /api/roles/{id}/permissions` | `USERS_EDIT` + `CheckMayManage` + `CheckMayGrant` | `RolesService:181-186` |
| Create / rotate own API key | `POST /manageapikeys/new`, `/manageapikeys/{id}/rotate` | none for creation (authenticated); rotation `ADMIN` or owner (`OpenApiController:145`) | `OpenApiController:50,145` |
| Read-only key | any non-GET `/api` request | refused before the controller when the acting key is flagged read-only | `BaseAuthMiddleware:261-297`; `WRITING_GET_ROUTES` :302-306 |
| Price visibility, calendar export | rows above (4.1, 4.2) | | |

### 4.5 Service identities (no `PERMISSION_*`; credential-typed)

Authenticated by a typed API key in `BaseAuthMiddleware:57-71` (`LabelWorkerAuthorization::ROUTE_KEY_TYPES`, `LabelWorkerAuthorization.php:9-29`); the request then runs as `VICTUAL_USER_ID = worker['user_id']`. Authorization after that is by ownership (`LabelWorkerAuthorization::Authorize` :30-57): worker active; attempt owned by worker; printer assigned to worker; artifact owned by a live attempt.

| Caller | Key type | Routes (`routes.php`) |
|---|---|---|
| Label worker (printer service) | `label-worker` (`label-verifier` also accepted on `labels-evidence`) | `POST /api/labels/register`, `/credentials/rotate`, `/jobs/claim`, `/attempts/{id}/heartbeat`, `/sent`, `/result`, `/evidence`, `/printers/{id}/status`; `GET /api/labels/artifacts/{id}/bytes` |
| Label renderer | `label-renderer` | `POST /api/labels/render/claim`, `/render/{id}/result`, `/invalid`, `/failed`; `GET /api/labels/assets/{id}/bytes` |
| Pairing | none (`labels-pair` is the unauthenticated enrolment) | `POST /api/labels/pair` (`BaseAuthMiddleware:57,101`) |
| iCal client | `special-purpose-calendar-ical` | `GET /api/calendar/ical?secret=` |
| MCP sidecar | `mcp` (user-issued, `USER_ISSUED_KEY_TYPES`, `ApiKeyService:51`) | acts as the key's owner, with that owner's full permissions (no per-key subset exists in the tree; ADR-0044 is Proposed). The sidecar calls the routes below |
| Victualer | not found in this tree | |

MCP tools to API routes (one line each; `mcp/src/tools/`):

| Tool | API calls |
|---|---|
| `stock_overview` | `GET /api/stock` |
| `expiring_soon`, `missing_products` | `GET /api/stock/volatile` |
| `find_product` | `GET /api/objects/products`, `GET /api/stock` |
| `shopping_list` | `GET /api/objects/products`, `GET /api/objects/shopping_list` |
| `recipes_i_can_cook` | `GET /api/objects/recipes`, `GET /api/recipes/fulfillment` |
| `recent_transactions` | `GET /api/objects/stock_log`, `GET /api/objects/products/{id}` |
| `list_locations`, `list_product_groups`, `list_quantity_units` | `GET /api/objects/{locations,product_groups,quantity_units}` |
| `lookup_barcode` | `GET /api/stock/barcodes/external-lookup/{code}`, `GET /api/stock/products/by-barcode/{code}` |
| `purchase_product` | `POST /api/stock/products/{id}/add` |
| `consume_product` | `POST /api/stock/products/{id}/consume` |
| `open_product` | `POST /api/stock/products/{id}/open` |
| `transfer_product` | `POST /api/stock/products/{id}/transfer` |
| `inventory_product` | `GET /api/stock/products/{id}`, `POST /api/stock/products/{id}/inventory` |
| `undo_transaction` | `POST /api/stock/transactions/{id}/undo` |
| `set_due_date` | `GET /api/stock/products/{id}/entries`, `PUT /api/stock/entry/{id}` |
| `add_to_shopping_list` | `POST /api/stock/shoppinglist/add-product` |
| `check_off_shopping_list_item` | `PUT /api/objects/shopping_list/{id}` |
| `create_product` | `POST /api/objects/products` |
| `update_product` | `PUT /api/objects/products/{id}` |
| `create_location`, `create_product_group`, `create_quantity_unit`, `add_barcode` | `POST /api/objects/{locations,product_groups,quantity_units,product_barcodes}` (all four are `MDA` entities) |

## 5. Gaps and anomalies

"fact" = visible in the cited lines. "suspected" = needs a run or a maintainer answer.

| # | Finding | Severity | Evidence |
|---|---|---|---|
| 5.1 | **`MASTER_DATA_EDIT` gates, in the generic API:** create on **11** entities (the `else` branch), update on **17**, delete on **19**. **Headline: `MDE` gates at least one write operation on 22 of the 63 resource rows** (rows 1-11, 20, 21, 23-25, 28-32, 35); `MDA` gates create on 6 rows (1, 2, 3, 4, 5, 7). Outside the generic route: see 5.1a below. | fact | `GEA:81,440,254`; counts derived from the enum lists (`ExposedEntity` minus `NoEdit` = 26 writable; minus the 15 on other branches = 11 create-else / 17 update-else; `ExposedEntity` minus `NoDelete` = 27 deletable incl. `api_keys`; minus 8 on other branches = 19 delete-else) |
| 5.2 | **`MASTER_DATA_ADD` gates** create on **6** entities (`products`, `product_barcodes`, `locations`, `quantity_units`, `product_groups`, `shopping_locations`) plus `external-lookup?add=true`. It is not held by any seeded role other than via `ADMIN`; it nests under `MASTER_DATA_EDIT`. | fact | `GEA:77`, `StockApiController:997`, ADR-0043 decision 2 (Accepted), `db/pgsql/master-data-add-seed.sql:30` |
| 5.3 | `DeleteObject` has no `chores` or `tasks` branch: create and update of a chore or task need `CHORES`/`TASKS`; delete needs `MDE`. The seeded `ADULT` can create and edit a chore but cannot delete it. | fact | `GEA:69,73,432,436` vs :230-254 |
| 5.4 | Parent nodes double as write permissions: `RECIPES`, `EQUIPMENT`, `CHORES`, `TASKS` gate definition writes, and `SHOPPINGLIST` gates the thermal print. Because the tree resolves downward, granting any of them also grants every child (`CHORES` includes `CHORE_TRACK_EXECUTION`, `CHORE_UNDO_EXECUTION`, `CHORES_VIEW`; `RECIPES` includes `RECIPES_MEALPLAN`, `MEALPLAN_VIEW`, `RECIPES_VIEW`). There is no way to grant "edit chore definitions" without the execution and view leaves, nor the reverse for `CHORES`. | fact | `GEA:57,65,69,73`; `ChoresApiController:35`; `PrintApiController:26`; hierarchy 2.1 |
| 5.5 | Permission check precedes the exposure check in `AddObject`, `EditObject`, `DeleteObject`. A caller without the branch's permission gets 403 for any entity name, even one that does not exist or is not editable; a caller with it gets 400. | fact | `GEA:51-82` before :84; :232-255 before :257; :414-441 before :443 |
| 5.6 | The MDA list omits `quantity_unit_conversions`, which issue 1111 lists as a resource with its own Create. Create falls to `MDE`. This is a recorded decision, not an accident. | fact (decision) | `GEA:77`; ADR-0043 decision 2 |
| 5.7 | `shopping_lists` (the list header) shares `SHOPPINGLIST_ITEMS_ADD` for create **and update**, and `SHOPPINGLIST_ITEMS_DELETE` for delete, with `shopping_list` (items). There is no permission specific to the list header. | fact | `GEA:51-54,232-235,414-417` |
| 5.8 | Edit-form and list pages check only the read leaf. Which buttons a user sees is decided in the view by a `permission-<NAME>` CSS class resolved client-side from `Victual.UserPermissions` (`views/layout/default.blade.php:125`) and, for label widgets, a server-side `@if(HasPermissions(MDE))` (`views/components/label_print_widget.blade.php:4`; `views/locationform.blade.php:200`; `views/recipes.blade.php:197`). The write is enforced only by the API. Examples: `/product/{id}` `StockController:389` checks `STOCK_VIEW`; the add button carries `permission-MASTER_DATA_ADD` (`views/products.blade.php:28`); merge button carries `permission-STOCK_EDIT permission-MASTER_DATA_EDIT` (`views/products.blade.php:167`). | fact | cited lines |
| 5.9 | View classes and API checks disagree for several controls: `views/batteries.blade.php:15,59,65` use `permission-MASTER_DATA_EDIT` (matches API `GEA:81`); `views/choresoverview.blade.php:199` uses `permission-CHORES` for the edit link (matches `GEA:432`) but the delete path needs `MDE` (5.3); `views/stockjournal.blade.php:243` and `views/stockoverview.blade.php:332` show an `MDE` link. I did not compare every `permission-*` class with its API route. | suspected (partial survey) | cited lines |
| 5.10 | Page vs API on custom-entity definitions: pages require `MDE` + `ADMIN` (`GenericEntityController:25-33`) but the API read of `userfields` and `userentities` has no permission (`ERP:77-78`). Documented: ADR-0035 amendment, issue 869; comment `ERP:75-76`. | fact (decision) | cited |
| 5.11 | Pages and APIs for `meal_plan_sections`, `task_categories`, `batteries` gate reads on the domain read leaf but all writes on `MDE`. The same is true of `storage_classes`, `product_substitutions`, `product_location_min_stock`, `mqtt_product_entities` (read `STOCK_VIEW`, write `MDE`). ADR-0043 open question 2 records `MDE` for batteries and task categories. | fact | rows 8-11, 20, 24, 25 |
| 5.12 | Mixed gates on merges: product merge needs `STOCK_EDIT` **and** `MDE`; chore merge needs `MDE` only. | fact | `StockApiController:1723-1724`; `ChoresApiController:185` |
| 5.13 | Booking routes require the write leaf **plus** `STOCK_VIEW` (to return the booking), but undo requires `STOCK_EDIT` alone. | fact | `StockApiController:343/346`, :1682,1701 |
| 5.14 | Routes with **no** `PERMISSION_` check in the handler (authenticated only, unless noted): `GET /about`, `/manifest`, `/barcodescannertesting`, `/usersettings`, `/api` (docs UI); `GET /api/openapi/specification`; `GET /api/system/info`, `/time`, `/db-changed-time`, `/config`, `/localization-strings`; `POST /api/system/log-missing-localization`; `GET /api/user`, `/user/capabilities`; `GET/PUT/DELETE /api/user/settings*`; `/login`, `POST /logout`; and the label worker, renderer and pairing routes (credential-checked, 4.5). Whether `/api/system/*` needs a session was not read in `BaseAuthMiddleware`. | fact (no token); auth requirement unverified | `routes-raw.tsv` rows with empty last column |
| 5.15 | `api_keys`: create has no permission; read is own-or-`ADMIN` (page); delete is owner-or-`ADMIN` through the generic route with the `DeleteObject` permission step explicitly skipped. No per-key permission subset or role package exists in the tree; the only key-level restriction is the `read_only` flag, enforced by HTTP method in the middleware (3 GET routes are treated as writes). | fact | `GEA:248-250,274-279`; `BaseAuthMiddleware:261-325`; ADR-0044 Proposed |
| 5.16 | Direct grants are the only per-user path besides roles; there is no user-group table, so "group inherits roles" in issue 1111 has no schema today. | fact | `db/pgsql/roles-schema.sql`; `grep` of `01_tables.sql` |
| 5.17 | The consumption/medication features mix a global `STOCK_VIEW`/`STOCK_CONSUME`/`STOCK_EDIT` check with per-record rights (`read/consume/edit/undo/share/owner`) that live in `consumption_recipe_shares`. Those rights are not entries in `permission_hierarchy`. | fact | `ConsumptionRecipeService:42,96-113`; schema `migrations/0305.pgsql.sql` (header names ADR-0040/0041) |
| 5.18 | The `users` entity has a row in `EntityReadPolicy` but is not in `ExposedEntity`, so the generic route does not serve it. `roles` and `permission_hierarchy` are exposed read-only. | fact | `ERP:56-58`; OA:18180 |
| 5.19 | Label print: print/revised-print need `MDE` + the kind's read leaf; reprint, cancel, promote need `MDE` only; manage/preview need `ADMIN`; reads of the job tables need `ADMIN`. Three different gates on one subsystem, with one documented rationale. | fact | `LabelsApiController:62-86` |
| 5.20 | Staleness of the earlier map. `.devtools/issue742/route-permissions.txt` (measured at `0639a23d`, 2026-10-10) predates: `MASTER_DATA_ADD` (its `POST /api/objects/{entity}` lists `SHOPPINGLIST_ITEMS_ADD RECIPES RECIPES_MEALPLAN EQUIPMENT MASTER_DATA_EDIT ADMIN`, no `CHORES`, `TASKS`, `MASTER_DATA_ADD`); the external-lookup change (`MDA` at `StockApiController:997`, previously `MDE`); `POST /api/labels/locations/{id}/print-subtree` (new); and its "ADULT cannot create a task or chore" no longer holds (`GEA:69,73` require the parents, which ADULT holds). It prints non-GET routes only and does not follow policies or services. This inventory covers all 270 routes. | fact | `.devtools/issue742/route-permissions.txt:2-4`; `RESULTS.md` Users table |

**5.1a (continues 5.1).** Outside the generic route, `MASTER_DATA_EDIT` also gates `PUT /api/userfields/*`, 8 label operate routes, product merge and chore merge. It also gates the file groups `productpictures` and `userfiles`, 6 custom-entity pages, `userobjects` and `userentity-*` reads, and nav visibility (`views/layout/default.blade.php:387,496`). Because the tree resolves downward, `MASTER_DATA_EDIT` also satisfies the 6 `MASTER_DATA_ADD` creates, so it can create on 17 entities; 19 distinct entities in total, since delete covers the union.

## 6. Counts

| Quantity | Value | How |
|---|---|---|
| Routes enumerated | 270 (11 DELETE, 151 GET, 89 POST, 19 PUT); 88 outside `/api`, 182 under `/api` (the `GET /api` docs page is counted with the latter) | `routes-raw.tsv` |
| Routes with no `PERMISSION_` token in the handler | 7 outside `/api` (`/about`, `/manifest`, `/barcodescannertesting`, `/usersettings`, `/login` x2, `/logout`); 29 under `/api` (15 label worker/renderer/pairing, which authenticate by credential; 14 system, openapi, docs, `/api/user*`) | `routes-raw.tsv` (empty last column) |
| Resource types (rows 1-63 of section 3) | **63** (file groups counted one each = 5; label tables = 13) | section 3 |
| Distinct (resource, operation) pairs under a pure CRUD scheme | 63 x 4 = **252** | |
| of which supported, so need a permission | **187** (Create 42, Read 63, Update 42, Delete 40) | per-row flags below |
| of which "n/a - not supported" | **65** | 252 - 187 |
| Resources with all four operations | 31 | rows 1-11, 14-21, 23-25, 27-30, 37, 50, 53, 58, 59 |
| Read-only resources (Read only) | 14 | `stock_log`, `chores_log`, `battery_charge_cycles`, 9 label/print tables (`label_drivers`, `label_worker_capabilities`, `label_printer_status`, `label_template_versions`, `label_media_profiles`, `label_render_requests`, `print_jobs`, `print_attempts`, `print_evidence`), `permission_hierarchy`, `calendar` |
| Resources with 2 or 3 operations | 18 (`stock`, `userfield_values`, 5 file groups, `label_workers`, `label_templates`, `label_assets`, `user_permissions`, `role_permissions`, `user_roles`, `api_keys`, `user_settings`, `consumption_refills`, `consumption_events`, `consumption_mappings`) | |

Per-row support flags used for the counts (C=create, R=read, U=update, D=delete; anything not listed is n/a):

- CRUD: rows 1-11, 14-21, 23-25, 27-30, 37, 50, 53, 58, 59.
- R only: rows 13, 22, 26, 39-41, 43, 45-49, 52, 63.
- RU: rows 12, 31, 54, 55. CRU: rows 38, 42, 51, 60. CR: row 44.
- CRD: rows 32-36 and 56. RUD: rows 57, 61, 62.

Counting rules:

- A file group is one resource each.
- `PUT` upserts (user settings, consumption events and mappings) count as Update with Create folded in.
- "Set" endpoints (`PUT /users/{id}/permissions`, role grants, user roles) count as Update, not Create plus Delete.
- Rows 12 and 13 differ because `PUT /api/stock/entry/{id}` exists while no direct create or delete of stock does. "Not supported" is by absence of a route or by `ExposedEntityNoEdit`/`NoDelete`; it is a statement about today's API, not a design judgement.

## Appendix A. Read-only views in `ExposedEntityNoEdit` and their gate

All have `EntityReadPolicy` entries; none can be written. View -> base resource -> read gate.

| View | Base resource (row) | Read gate | ERP line |
|---|---|---|---|
| `stock_current_locations`, `products_last_purchased`, `products_average_price` | stock (12) | `STOCK_VIEW` | 35-37 |
| `quantity_unit_conversions_resolved` | 6 | `STOCK_VIEW` | 38 |
| `locations_resolved` | 3 | `STOCK_VIEW` | 39 |
| `product_groups_resolved`, `product_groups_missing` | 4 | `STOCK_VIEW` | 40,28 |
| `product_barcodes_view` | 2 | `STOCK_VIEW` | 41 |
| `product_substitutions_resolved` | 9 | `STOCK_VIEW` | 30 |
| `product_location_missing` | 10 | `STOCK_VIEW` | 32 |
| `uihelper_shopping_list` | 15 | `SHOPPINGLIST_VIEW` | 45 |
| `recipes_pos_resolved` | 17 | `RECIPES_VIEW` | 49 |
| `permission_hierarchy` | 52 | `USERS_READ` | 56 |
| `roles` | 53 | `USERS_READ` | 57 |
| `api_keys` | 56 | none (NoListing) | 74 |
| `label_*`, `print_*` (13 tables) | 37-49 | `ADMIN` | 11-18, 64-68 |

(`ExposedEntity` has 58 names; 26 are writable base tables, the remainder are the views and read-only tables above.)

## Appendix B. Things I could not determine

- Whether `/api/system/*`, `/api/openapi/specification`, `/api/user*` require a session or API key (middleware not traced beyond `BaseAuthMiddleware:50-75`).
- A "Victualer" service: no code references it. [Plan 16](../../docs/plans/16-project-rename.md) uses the name for the automation actor that writes log entries; no workload of that name exists yet.
- Whether every `permission-<NAME>` class in the views matches the API route it fronts (5.9 is a sample).
- The later sections of `docs/manual/operator/roles-permissions.md` (only lines 1-40 read: model, defaults, prices).
