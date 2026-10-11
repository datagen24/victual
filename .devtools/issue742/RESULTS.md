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

## Static completion, 2026-10-11 (master d4960138)

This section was read from source on `master` at `d4960138` and not exercised. No request was
sent and no test ran. Each line number was checked against the file at that commit. It
completes the "Static inference" list above, and it narrows the ADR-0041 bullet there: the
refill routes below do not check `STOCK_CONSUME`.

### Dispatch-routed handlers shown as `[]`

`route-permissions.php` does not follow a handler that checks inside a helper or a service, so
these routes show `[]` in `route-permissions.txt`. Their gates are:

| Routes | Gate | Source |
|---|---|---|
| Consumption events (`PUT`, `DELETE`, `resolve`, `batch`, bulk `resolve`) and mappings (`PUT`, `DELETE`) | `STOCK_VIEW` and `STOCK_CONSUME` | `controllers/Api/ConsumptionEventsApiController.php`: `Write()` at 105-109, called at 115, 135, 153, 180, 195, 208 and 234 |
| The same resources, read | `STOCK_VIEW` | the same file, `Read()` at 100-103 |
| Refill writes (`PUT .../refill`, fills, void, orders, receive, cancel) | Controller: `STOCK_VIEW` (`Read()` at 50-53). Service: the per-recipe `edit` right | `ConsumptionRefillsApiController.php` 113-167; `ConsumptionRecipeService::AuthoriseRefill()` 147-151, called with `'edit'` at `ConsumptionRefillService.php` 459, 539, 563, 595, 627 and 645 |
| `POST /api/refills/notices/ack` | `STOCK_VIEW`, then the read right on the named recipe; the row is the caller's own | `ConsumptionRefillsApiController.php` 185-199; `ConsumptionRefillService.php` 782-785 |
| `POST /api/stock/products/by-barcode/{barcode}/add`, `consume`, `transfer`, `inventory`, `open` | The gate of the plain route: `STOCK_PURCHASE`, `STOCK_CONSUME`, `STOCK_TRANSFER`, `STOCK_INVENTORY`, `STOCK_OPEN` | each wrapper resolves the product id and calls the plain handler (`StockApiController.php` 381, 612, 1394, 976, 1076); the plain handlers check at 257, 505, 1326, 879 and 993 |
| Label worker and renderer routes (`/api/labels/jobs/claim`, `attempts/...`, `printers/{id}/status`, `register`, `credentials/rotate`, `render/...`) | A machine credential, outside the user permission model | `middleware/Auth/BaseAuthMiddleware.php` 55-71 authenticates the credential and sets `label_worker_id`; `services/Labels/LabelWorkerAuthorization.php` 30-57 ties the attempt, printer or artifact to that worker. `POST /api/labels/pair` is excluded from that block (56) and passes unauthenticated (100-105) |
| `POST /api/labels/{kind}/{id}/print` and `revised-print`, and the `locations/{id}` forms | `MASTER_DATA_EDIT`, then the domain read from `FieldCatalogue::DomainPermission()` | `controllers/Api/LabelsApiController.php` 73-87; `services/Labels/FieldCatalogue.php` 242-251 |

Notes on the table:

- Events and mappings are keyed by `user_id`. The services read and write `WHERE user_id = ?`
  (`ConsumptionEventService.php` 226, 461, 575, 594; `ConsumptionMappingService.php` 111-113,
  143, 157, 175), and the controller passes `VICTUAL_USER_ID` (for example lines 119 and 212). A
  caller sees and changes only their own events and mappings.
- Refill writes need no `STOCK_CONSUME`. ADR-0041 (line 542) states that rule for the event
  and mapping routes. The refill routes follow the recipe rights of ADR-0040 and ADR-0042.
- Printing a household label is a `MASTER_DATA_EDIT` act. The docblock at
  `LabelsApiController.php` 54-59 states this as intended: `MASTER_DATA_EDIT` makes printing an
  administrative act, and the domain read lets the caller capture the value. The domain
  permission for a `battery` label is `BATTERIES`, not a view leaf (`FieldCatalogue.php` 248).
  Reprint, promote and cancel check `MASTER_DATA_EDIT` only (docblock 61-66).
- The web `POST` routes missing from the txt are `POST /manageapikeys/new`
  (`OpenApiController::CreateNewApiKey`, 75-118), `POST /manageapikeys/{id}/rotate`
  (`RotateApiKey`, 133-153), `POST /login` and `POST /logout` (`routes.php` 56 and 59).
  Neither key action checks a permission. `CreateApiKey()` stores the key under
  `VICTUAL_USER_ID` (`ApiKeyService.php` 142), so a caller creates only their own keys. Rotation
  answers 404 unless the predecessor is a user-issued key that the caller owns or the caller
  holds `ADMIN` (`OpenApiController.php` 143-148). `POST /login` is public
  (`BaseAuthMiddleware.php` 100-105), and `POST /logout` removes the caller's own session
  (`LoginController.php` 34-46).

### The static-inference items above

- `GET /api/stock/barcodes/external-lookup/{barcode}?add=true` creates a product. Confirmed:
  `StockApiController::ExternalBarcodeLookup` (851-866) checks `STOCK_VIEW` and
  `MASTER_DATA_EDIT` (853-854), sets `$addFoundProduct` from `add=true` (859), and
  `StockService::ExternalBarcodeLookup` (1368-1553) then creates the product, its barcode, a
  conversion and a picture. The two checks run before `add` is read, so they apply to a lookup
  without `add` too. The route is in `BaseAuthMiddleware::WRITING_GET_ROUTES` (301-305), so a
  read-only key is refused (276, 281-284).
- `GET /api/calendar/ical/sharing-link` mints only the caller's own calendar key. The handler
  checks `CALENDAR_VIEW` (`CalendarApiController.php` 237) and calls
  `GetOrCreateApiKey(API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL)` (242). That method looks for an
  unexpired key of that type for `VICTUAL_USER_ID` (`ApiKeyService.php` 248) and otherwise
  creates one owned by `VICTUAL_USER_ID` (142, 255). It writes no master data. The route is also
  in `WRITING_GET_ROUTES` (302).
- `GET /api/print/shoppinglist/thermal` belongs in the list of writing `GET`s. It drives a
  printer, checks `SHOPPINGLIST_VIEW` and `SHOPPINGLIST` (`PrintApiController.php` 25-26), and is
  in `WRITING_GET_ROUTES` (304). The writing `GET`s are therefore three: `external-lookup` with
  `add=true`, `ical/sharing-link` and `print/shoppinglist/thermal`.

### Findings

These findings come from reading the source. None is exercised. This record decides none of
them.

**F1. Merging products is gated by `STOCK_EDIT` and deletes a product.**
`POST /api/stock/products/{keep}/merge/{remove}` checks `STOCK_EDIT`
(`StockApiController.php` 1538). `StockService::MergeProducts` (4855) rewrites the removed
product's rows in `stock` and `stock_log` (4963-4964), `product_barcodes` (4982),
`quantity_unit_conversions` (4995-4996), `recipes_pos` and `recipes` (5001-5002), `meal_plan`
(5003), `shopping_list` (5004), `chores` (5011), `product_substitutions` (5025-5029) and
`product_location_min_stock` (5042-5043), reparents child products (5053-5054), and ends with
`DELETE FROM products WHERE id = ...` (5056).

The seeded `ADULT` role holds `STOCK` (`db/pgsql/roles-seed.sql` 18) and `STOCK_EDIT` is a
child of `STOCK` (`services/Database/InitialDataSeeder.php` 61), so an adult can delete a
product through merge. Deleting a product through the generic route needs `MASTER_DATA_EDIT`
(`GenericEntityApiController::DeleteObject`, 227), and merging chores checks `MASTER_DATA_EDIT`
(`ChoresApiController.php` 184). The merge button is on the products page, which needs only
`STOCK_VIEW` (`StockController::ProductsList`, 518; `views/products.blade.php` 167). The
behavior is inherited from upstream commit `c9b5e144` ("Make it possible to merge products",
2020-12-20), which added the same `STOCK_EDIT` check and the same `DELETE FROM products`.

**F2. `userpictures` upload is gated by "may edit some user".**
`FilesApiController::GROUP_WRITE_PERMISSIONS` lists `USERS_EDIT` and `USERS_EDIT_SELF` for
`userpictures` (line 50). The comment at lines 47-49 says the route carries no user id and that
binding a picture to its owner belongs with sweep finding S6. Delete is bound to the caller's
own picture by `CheckUserPictureDeletion()` (412). Upload is not, because the file name must be
new (`docs/security-sweep.md` 660-666; `FileStorage::Create()` refuses an existing name,
`services/Storage/FileStorage.php` 93-101). A holder of `USERS_EDIT_SELF`, which the seeded
`ADULT` and `CHILD` roles hold (`roles-seed.sql` 18 and 21), can therefore store new files in
`userpictures` that no user row claims.

**F3. Shopping list containers use the item permissions.**
`GenericEntityApiController` sends the `shopping_lists` entity through
`SHOPPINGLIST_ITEMS_ADD` for create and edit (36-41, 341-346) and `SHOPPINGLIST_ITEMS_DELETE`
for delete (203-208). The seeded `CHILD` role holds `SHOPPINGLIST_ITEMS_ADD` but not
`SHOPPINGLIST_ITEMS_DELETE` (`roles-seed.sql` 21), so a child can create and rename a list and
cannot delete one. Whether a list is an item or master data is a design question that no
record decides.

### Minimum permissions to set up a medication today

A member who sets up a medication for consumption mapping needs `MASTER_DATA_EDIT`,
`STOCK_VIEW` and `STOCK_CONSUME`, and `STOCK_PURCHASE` to add stock. `MASTER_DATA_EDIT` alone
cannot save a mapping (the probe above: 403 `STOCK_VIEW`), and the seeded `ADULT` and `CHILD`
roles cannot create the product. Steps:

1. Create the quantity unit and the product with `POST /api/objects/quantity_units` and
   `POST /api/objects/products`. Both fall to `MASTER_DATA_EDIT`
   (`GenericEntityApiController::AddObject`, 56).
2. If the product's purchase, consume or price unit differs from its stock unit, the insert
   fires `products_default_qu_conversions_INS`, which writes a conversion of factor 1 for each
   such unit that has no resolved conversion (`db/pgsql/baseline/06_triggers_a.sql` 156-193).
   The factor is a placeholder.
3. If the mapping names a unit other than the stock unit, a conversion with the real factor
   must exist, or the mapping answers 422 `invalid_mapping`
   (`ConsumptionMappingService::RequireConversion`, 393-417; the stock unit returns 1 at
   402-405). Correct the placeholder with `PUT /api/objects/quantity_unit_conversions/{id}`,
   which falls to `MASTER_DATA_EDIT` (`EditObject`, 359-362). The entity is not in the no-edit
   list of `victual.openapi.json`. The probe did not send this request.
4. Save the mapping with `PUT /api/consumption/mappings/{source_system}/{medication_ref}`. It
   needs `STOCK_VIEW` and `STOCK_CONSUME` (`ConsumptionEventsApiController.php` 105-109 and
   206-208; the service checks the same pair at `ConsumptionMappingService.php` 77).
5. Add stock with `POST /api/stock/products/{id}/add`, which needs `STOCK_PURCHASE`
   (`StockApiController.php` 257).
