# Controllers

Route handlers. `routes.php` maps each route to a controller method; a controller checks
the caller's permissions, calls [services](../services/README.md), and writes the
response. Classes are in the `Victual\Controllers` namespace.

Requests reach a controller after the [middleware](../middleware/README.md) stack has
authenticated the caller.

## Contents

| Path | Contents |
|---|---|
| `BaseController.php` | Base for page controllers. `Render()` and `RenderPage()` fill the Blade template variables every page uses: localization, feature flags, permissions and the URL builder. Templates are in `views/`. |
| `*Controller.php` | Page controllers, one per area: stock, recipes, chores, batteries, tasks, calendar, equipment, users, label templates and print jobs, the consumption inbox, recipes and refills, login and system pages. |
| `ExceptionController.php` | Error handler registered in `app.php`. It answers an uncaught exception on an `/api` route with a JSON error and on a page route with an HTML error page. |
| `GenericEntityController.php` | Pages for userfields, user-defined entity types (userentities) and their instances (userobjects). |
| `Api/BaseApiController.php` | Base for REST controllers below `/api`: JSON response helpers, the `query[]`, `limit`, `offset` and `order` handling for list endpoints, and HTMLPurifier body sanitisation. `HandleApiCall()` maps exceptions to status codes; its doc comment has the table. |
| `Api/GenericEntityApiController.php` | `/api/objects/{entity}` CRUD and `/api/userfields/{entity}/{objectId}`. The `ExposedEntity*` enums decide which entities each operation accepts. Creating products, barcodes, locations, quantity units, product groups and stores needs `MASTER_DATA_ADD`; chores and tasks use `CHORES` and `TASKS` (ADR-0043). |
| `Api/*ApiController.php` | One controller per API area. `OpenApiController` serves `victual.openapi.json`. |
| `Api/EInvalidApiQuery.php`, `Api/EObjectNotFound.php` | Exceptions that `BaseApiController::HandleApiCall()` answers with 400 and 404. |
| `Users/User.php` | The `PERMISSION_*` constants and the checks against them. |
| `Users/EntityReadPolicy.php` | Read policy for generic objects and their userfields. |
| `Users/PermissionMissingException.php` | Thrown by `User::CheckPermission()` when the current user lacks a permission. |

## Permissions

A controller method asserts what it requires with `User::CheckPermission($request,
User::PERMISSION_…)`. `User::HasPermission()` answers from the
`user_permissions_resolved` database view, which combines a user's direct grants with
their roles' grants (`db/pgsql/roles-schema.sql`). Through `HandleApiCall()`, a
`PermissionMissingException` becomes a 403. The design is [plan 19](../docs/plans/19-rbac.md); the rules are:

- [ADR-0014](../docs/adr/0014-administering-a-user-is-a-subset-question.md): administering
  a user requires holding every permission that user holds (`User::MayAdminister()`).
- [ADR-0018](../docs/adr/0018-role-grants-and-domain-reads.md) and
  [ADR-0035](../docs/adr/0035-batteries-equipment-calendar-and-custom-entities-require-view-permissions.md):
  which domains require a view permission to read.
- [ADR-0043](../docs/adr/0043-creating-shared-master-data-is-a-right-below-master-data-edit.md):
  creating shared master data is a separate right.

Field-level redaction is applied when the response is written: `BaseApiController` and
`GenericEntityApiController` call `FieldPolicy` before returning an entity.

## API contract

- [ADR-0005](../docs/adr/0005-wire-contract-is-the-invariant.md): the JSON response shape
  is the invariant. `victual.openapi.json` documents it. A change to a response shape
  needs that record's process, not only a code change.
- [ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
  and [ADR-0028](../docs/adr/0028-a-timestamp-a-write-route-cannot-read-is-refused.md):
  timestamp and boolean encoding on the wire.
- [ADR-0006](../docs/adr/0006-authenticated-issues-in-scope.md): a permission bug that
  needs an authenticated account is a security issue.
