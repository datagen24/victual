# Issue 742: who may create the master data a medication needs

Measured 2026-10-10 against `master` at `0639a23d` (a clean worktree), PHP 8.5.10, PostgreSQL 16 from
`localhost/victual-pg:pgtap`, `localhost/victual:dev`, podman on macOS. Reproduce with
`.devtools/issue742/run.sh`; a second run printed the same 93 `PROBE` lines apart from row ids.
The probe asserts nothing. It sends each request through the whole middleware stack with a
per-user API key (the pattern of `OrganizerApiTest`) and prints the status and the start of the body.
No permission code was changed to take these measurements.

## Users

| Probe user | Grants | Effective permissions (`user_permissions_resolved`) |
|---|---|---|
| `child` | seeded `CHILD` role | 14, no `MASTER_DATA_EDIT` |
| `adult` | seeded `ADULT` role | 37, no `MASTER_DATA_EDIT` |
| `editor` | direct `MASTER_DATA_EDIT` only | 1 (`MASTER_DATA_EDIT`), no `ADMIN` |
| `editorconsumer` | direct `MASTER_DATA_EDIT`, `STOCK_VIEW`, `STOCK_CONSUME` | 3 |
| `admin` | direct `ADMIN` | 40 |

## Creating and changing master data (tested)

| Request | child | adult | editor | editorconsumer | admin |
|---|---|---|---|---|---|
| `POST /api/objects/products` | 403 | 403 | 200 | 200 | 200 |
| `POST /api/objects/quantity_unit_conversions` | 403 | 403 | 200 | 200 | 200 |
| `POST /api/objects/locations` | 403 | 403 | 200 | 200 | 200 |
| `POST /api/objects/quantity_units` | 403 | 403 | 200 | 200 | 200 |
| `POST /api/objects/product_barcodes` | 403 | 403 | 200 | 200 | 200 |
| `POST /api/objects/shopping_locations`, `product_groups` | 403 | 403 | 200 | 200 | 200 |
| `POST /api/objects/tasks`, `chores`, `batteries` | 403 | 403 | 200 | 200 | 200 |
| `PUT /api/objects/products/{id}` | 403 | 403 | 204 | 204 | 204 |
| `DELETE /api/objects/products/{id}` | 403 | 403 | 204 | 204 | 204 |
| `PUT /api/userfields/products/{id}` | 403 | 403 | 204 | 204 | 204 |
| `GET /api/stock/barcodes/external-lookup/{barcode}?add=true` | 403 `MASTER_DATA_EDIT` | 403 `MASTER_DATA_EDIT` | 403 `STOCK_VIEW` | 200, created a product | 400 (product already exists) |

Every 403 body is `Permission missing: <NAME>`. `ADMIN` is not needed: `editor` holds one
permission and passes every gate. The seeded `ADULT` role holds `STOCK`, `TASKS`, `CHORES`,
`BATTERIES`, `RECIPES` and 32 more effective permissions, and still cannot create a task, chore
or battery through the generic route.

## Mapping a product (tested)

`PUT /api/consumption/mappings/healthkit/{ref}` with `unit_labels: ["tablet"]` and a fixed
location. The product's stock unit is "tablet". `qu_id` is the mapping's quantity unit.

| Mapping | child | adult | editorconsumer | editor |
|---|---|---|---|---|
| no `qu_id` | 201 | 201 | 201 | 403 `STOCK_VIEW` |
| `qu_id` = the stock unit | 201 | 201 | 201 | 403 `STOCK_VIEW` |
| `qu_id` = another unit, no conversion row | 422 `invalid_mapping` | 422 `invalid_mapping` | 422 `invalid_mapping` | 403 `STOCK_VIEW` |
| `qu_id` = the product's purchase unit | 201 | 201 | 201 | 403 `STOCK_VIEW` |

So a conversion is needed only when the mapping names a unit other than the stock unit
(`ConsumptionMappingService::RequireConversion`, line 404, returns 1 for the stock unit).

The purchase-unit row of the last line deserves care. The trigger
`products_default_qu_conversions_INS` (`db/pgsql/baseline/06_triggers_a.sql`) fires on any insert
into `products`. When the purchase, consume or price unit differs from the stock unit and no
default conversion applies, it inserts a conversion of factor 1. The probe inserted the product
with SQL, so it did not exercise the web form or the API for this case. The product had two rows in
`quantity_unit_conversions` (bottle to tablet and tablet to bottle), both with factor 1, and the
mapping saved against that placeholder. The factor is wrong for a 30-tablet bottle until someone
edits it.

## Static inference (not exercised)

- `POST /api/stock/products/{keep}/merge/{remove}` checks `STOCK_EDIT` and deletes a product row,
  while `POST /api/chores/{keep}/merge/{remove}` checks `MASTER_DATA_EDIT`. The seeded `ADULT`
  role holds `STOCK_EDIT` through `STOCK`. Read from `StockApiController::MergeProducts` (line 1538).
- `MASTER_DATA_EDIT` has one parent, `ADMIN` (`InitialDataSeeder.php`, line 71). `permission_tree`
  (`db/pgsql/baseline/03_views_group1.sql`, line 92) resolves each permission to itself and its
  descendants, so a new permission placed under `MASTER_DATA_EDIT` is held by every account that
  holds `MASTER_DATA_EDIT` or `ADMIN`, and by no other account until someone grants it.
- `products` has no owner or creator column, so no route can tell whose product a row is.
- Dispatch-routed handlers (labels, consumption events, refills, by-barcode wrappers) show `[]`
  in `route-permissions.txt`. The script does not follow the dispatcher. ADR-0041 states that
  every consumption route needs `STOCK_CONSUME`, and `ConsumptionEventApiTest` tests it.
- `route-permissions.php` lists non-GET operations only. A scan of GET handlers for write-like
  calls found `external-lookup?add=true` (above), label `GET`s that are routers, and
  `GET /api/calendar/ical/sharing-link`, which mints the caller's own iCal key and writes no
  master data.
