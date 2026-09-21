# Architecture review — 2026-08-27

Full-codebase review for architectural stability and uniformity, run area by area
(backend PHP, services + database layer, API surface, frontend). Judged against this
fork's actual bar: household scale, correctness and consistency over enterprise
ceremony, with the deployment target being immutable, scale-to-zero pods on k3s.

A companion documentation pass added PHPDoc/JSDoc coverage across controllers,
services, middleware, helpers, and the browser-side JS; see the commit history on this
branch. Review responses to the roadmap plans are inlined under each plan's numbered
open questions (`> **Response:**` blocks in `docs/plans/*.md`).

## Executive summary

The codebase is **architecturally stable and unusually uniform** for a
convention-driven app of this vintage: one controller shape, one middleware base, one
view/viewjs naming convention honored by all 73 views, a service layer with a single
access idiom, and an API whose route table and OpenAPI spec agree almost perfectly.
The fork's dual-engine database work is careful where it is deliberate (typed baseline
schema, CASE-wrapped booleans, a NOCASE collation, differential view/trigger tests).

The review found no structural rot. What it found instead is:

1. **A handful of real defects** — several of them fork-relevant, two of them
   one-line fixes (see "Defects to fix first").
2. **The single biggest architectural gap: raw SQLite date SQL in five page
   controllers** that bypasses the dialect layer and breaks those pages on
   PostgreSQL — the fork's own headline feature.
3. **Cold-start behavior hostile to scale-to-zero** — the first request on a fresh
   container is hijacked into a viewcache reset + redirect + inline web-triggered
   migration.
4. **Uniformity achieved by copy-paste** — the frontend's ~30 list/form scripts and
   the API's per-endpoint try/catch blocks are clone families; they are consistent
   today but drift is already measurable.

## Defects to fix first

Concrete bugs found while reviewing, ordered by impact. None are large.

**Status: all 13 fixed** in commit `36650cd`. The "Where" references below are the
line numbers as of the review; the documentation pass shifted most of them, so the
column names the file rather than a location to trust. The table is kept as a record
of what was found and what was decided.

| # | Defect | Where | Fix applied |
|---|---|---|---|
| 1 | `GET /api/batteries/{id}` always fails: committed debug `throw new \Exception('df')` | `controllers/Api/BatteriesApiController.php:18` | ✅ Line deleted |
| 2 | `/api/system/config` leaks `DB_PASSWORD`, `DB_USER`, `DB_HOST`, `LDAP_BIND_PW` to any authenticated API key — the DB leak is fork-introduced (the new `DB_*` settings joined an endpoint that filters by blocklist) | `controllers/Api/SystemApiController.php:13-38` | ✅ Allowlist of the settings the web UI itself is given (`MODE`, `CURRENCY`, `ENERGY_UNIT`, `DEFAULT_LOCALE`, the two calendar settings, `MEAL_PLAN_FIRST_DAY_OF_WEEK`, `ENTRY_PAGE`, `BASE_PATH`, `BASE_URL`, `DISABLE_URL_REWRITING`, `GROCYCODE_TYPE`, the four `LABEL_PRINTER_*`) plus every `FEATURE_FLAG_*` by prefix. OpenAPI summary no longer promises "all config settings" |
| 3 | Raw SQLite-only date SQL (`DATE('now', ...)`, `STRFTIME`) bypasses the dialect layer and crashes on PostgreSQL — five page routes **and** the due/expired-products API | `StockController.php:67,72`, `ChoresController.php:74,79`, `BatteriesController.php:87,92`, `StockReportsController.php:24`, `RecipesController.php:30,62`, `services/StockService.php:954,958,970` | ✅ Cutoffs computed in PHP and bound as parameters. **No dialect primitive was added** — every site turned out to be a per-request constant, not a row-correlated expression, so there was nothing for a primitive to do (see the note below) |
| 4 | Any authenticated user can delete any API key by ID (sequential IDs, no ownership check, `api_keys` not in `ExposedEntityNoDelete`) | `controllers/Api/GenericEntityApiController.php:96-99` | ✅ Own keys only unless admin; another user's key answers the same "Object not found" as a missing one, so IDs can't be enumerated. `ExposedEntityNoDelete` deliberately left alone — the manage-API-keys page deletes through this route |
| 5 | Session/API keys generated with non-crypto `rand()` | `helpers/extensions.php` (`RandomString`), used by `SessionService.php:79`, `ApiKeyService.php:152` | ✅ `random_int()`. Signature and alphabet kept — API keys travel in the iCal `?secret=` query string and session keys are cookie values, so the alphanumeric alphabet is load-bearing and existing keys stay valid |
| 6 | `WebhookRunner` catches nonexistent `Victual\Helpers\RequestException`, so a printer webhook failure 500s the user action instead of being handled | `helpers/WebhookRunner.php` | ✅ Catches `GuzzleHttp\Exception\GuzzleException`, **not** `RequestException` as originally suggested: `ConnectException` extends `TransferException`, so timeouts and DNS failures — the likeliest printer failures given the 2 s timeout — would still have escaped |
| 7 | `/recipes` 500s on a fresh install: unguarded `$selectedRecipe->id` when no recipes exist | `controllers/RecipesController.php:113` | ✅ `recipePositionsResolved` resolved inside the existing guard; the `FindObjectInArrayByPropertyValue` lookup, which can also return null, is guarded too |
| 8 | LDAP filter injection: raw POST username interpolated into the search filter | `middleware/Auth/LdapAuthMiddleware.php:35` | ✅ `ldap_escape(..., LDAP_ESCAPE_FILTER)`, plus an exact-one-result check before `$result[0]` is dereferenced |
| 9 | Stack traces served in production: `addErrorMiddleware(true, ...)` hardcoded, so every 500 includes `error_details.stack_trace` | `app.php:115` | ✅ Gated on `VICTUAL_MODE === 'dev'`. Error *logging* left off deliberately — tracked as its own piece of work |
| 10 | `FilesService.php:54` tests `$bestFitHeight !== null` twice (second was surely `$bestFitWidth`); `:70` catches an unimported `ImageResizeException` that can never match | `services/FilesService.php` | ✅ Operand fixed (the height-only branch was dead code); `Gumlet\ImageResizeException` imported |
| 11 | `LogMissingLocalization` returns `null` (→ 500) outside dev mode | `controllers/Api/SystemApiController.php:76-92` | ✅ Returns `EmptyApiResponse` unconditionally |
| 12 | `LocalizationService` per-locale instance cache never hits: `in_array($locale, self::$InstanceMap)` compares the locale string against the cached *objects*, so every call re-parses the `.po` files from disk | `services/LocalizationService.php:161-174` | ✅ `isset(self::$InstanceMap[$locale])` |
| 13 | Demo/prerelease mode hard-fails on PostgreSQL: `DemoDataGeneratorService` is unconditionally SQLite-flavored (`sqlite_sequence`, `STRFTIME`, `datetime('now','localtime')`) but is invoked regardless of `DB_DRIVER` | `services/DemoDataGeneratorService.php`, called from `controllers/SystemController.php:51` | ✅ Skipped (with a note on stderr) unless the dialect is SQLite, rather than hard-failing — `VICTUAL_MODE=dev` stays usable on PostgreSQL. The SQL was **not** ported |

### What the fixing pass turned up beyond the table

- **Defect 2 was leaking more than listed.** The blocklist also let through
  `VICTUAL_USER_USERNAME`, `VICTUAL_USER_PICTURE_FILE_NAME`, `VICTUAL_LOCALE` and
  `VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION`, alongside every `LDAP_*`, `AUTH_CLASS`,
  `REVERSE_PROXY_AUTH_HEADER` and `TPRINTER_*` setting. That is the argument for the
  allowlist over a longer blocklist: the endpoint fails open on every setting added
  after it.
- **Defect 3 had two sites the table missed:** `RecipesController`'s meal plan window
  (`DATE('$start', '-$days days')`) and two *bare, zero-argument* `date()` calls in
  `StockService::GetDueProducts`/`GetExpiredProducts` — easy to miss with a
  `DATE('now'` grep, and PostgreSQL has no zero-argument `date()` at all. Those two
  were also the only sites using UTC (SQLite's bare `date()`) while every sibling used
  `'localtime'`; they are now local like everything else, a sub-day behaviour change
  around midnight.
- **The review's "add a date-offset primitive to `DatabaseDialect`" recommendation did
  not survive contact.** Every offending cutoff is a per-request constant, so the
  portable fix is to compute it in PHP. `DatabaseService::ExecuteDbQuery()` gained a
  `$params` argument (it previously could not bind at all) and `GetCurrentStock()` /
  `GetRecipesResolved()` thread parameters through to their callers. The dialect is
  unchanged and still has exactly one date primitive, `GetNowExpression()`.
- **SQLite's `%W` needed reimplementing in PHP**, not replacing with `date('W')`:
  PHP's `W` is ISO-8601 and shifts dates across the year boundary. The meal plan
  triggers write week recipe names with SQLite's definition, so
  `RecipesService::GetMealPlanWeekRecipeName()` reproduces it exactly (verified
  against SQLite for every day of 2015–2035). This is the PHP-side counterpart of
  `victual_sqlite_percent_w()` in the PostgreSQL baseline.
- **Defect 13 has a second PostgreSQL hazard even if the SQL is ported one day.**
  `DemoDataGeneratorService` inserts explicit `quantity_units` IDs and never calls
  `DatabaseDialect::ResyncGeneratedIdCounters()`, so a ported generator would still
  leave the identity sequence behind the data and throw duplicate-key errors on the
  next user insert.
- **Two defects could not be executed in the fixing environment** and rest on
  inspection only: 8 (no `ldap` extension available) and the PostgreSQL half of 13
  (no PostgreSQL instance). Everything else was verified against a running instance,
  including an old-SQL-vs-new-SQL row-level equivalence check for defect 3 and a
  two-user API-key test for defect 4.

## Statelessness / k3s readiness

Sessions and API keys are DB-backed — the hard part is already right. What still
touches local mutable state:

- **`data/viewcache` + version-hash marker (the big one).** On any container whose
  viewcache lacks the current version hash, `app.php:52-77` empties the cache, resets
  opcache, and 302-redirects the request — whatever it was — to `/`, which then runs
  schema migrations inline (`SystemController::Root`). On an ephemeral filesystem this
  happens on **every cold start**: API clients get a 302 instead of data, and
  concurrent first requests race through `EmptyFolder()` + migration with no locking.
  The Slim route cache (`app.php:118`) is written to the same data dir.
  **Direction:** bake compiled Blade views and the route cache into the image (or an
  emptyDir keyed by image version), run migrations from an initContainer/CLI
  entrypoint (`DatabaseMigrationService` works outside a request), drop the redirect.
  This is the prerequisite for scale-to-zero being pleasant, and pairs with plan 01
  (file storage in the database), which removes `data/storage`.
- **`data/storage`** — uploaded files; removed by plan 01.
- **`data/config.php` + `data/settingoverrides/`** — read-only at runtime; a stub
  config plus `VICTUAL_*` env vars is already viable, ConfigMap-compatible.
- **SQLite artifacts** (`data/victual.db`, file-mtime change tracking) — moot under
  PostgreSQL; the Postgres dialect already replaced mtime tracking with a table.
- **Request-scoped `define()` constants** (`VICTUAL_USER_ID`, `VICTUAL_LOCALE`, …) — safe
  under php-fpm, but permanently rule out worker-mode runtimes (FrankenPHP, Swoole).
  Not worth changing unless that becomes a goal.
- **PrerequisiteChecker requires `pdo_sqlite` ≥ 3.40 and opens `sqlite::memory:` on
  every request even on pgsql-only deployments** — skip when `VICTUAL_DB_DRIVER=pgsql`
  to slim the image and the request path.

## Backend (controllers, middleware, helpers)

**Verdict: uniform to a fault; the defects above are the exceptions, not the rule.**

- Layering is the conventional grocy split: controllers read views/tables directly
  for page data, services own writes and domain logic. It is applied consistently and
  is fine at this scale. The one outlier worth moving: `StockReportsController`
  embeds three hand-written multi-join SELECTs (also the SQLite-date offender) —
  a `StockReportsService` would put all raw SQL behind the dialect boundary.
- Services are singletons via `GetInstance()` while a PHP-DI container is wired and
  used for controller construction — two DI idioms coexisting. Harmless, but pick one
  direction for new code (the plans' new services should take constructor injection
  from the container).
- Auth middleware chain: composition works but is fragile — middlewares instantiate
  *other* middlewares and call `AuthenticateRequest` cross-instance, visibility
  drifts per subclass (protected in `DefaultAuthMiddleware`, public in three others),
  and `ProcessLogin` is an abstract static that three of five subclasses stub with
  `throw`. Extracting credential-checking into plain authenticator classes would
  remove the awkwardness; do it opportunistically, e.g. when plan 02 touches auth.
- Session cookie: set via bare `setcookie()` outside PSR-7, no `HttpOnly`/`SameSite`/
  `Secure`, never expires. Worth hardening in one small change alongside defect 5.
- Request-data access has three coexisting styles (PSR-7 `getQueryParams()`,
  slim/http decorated `getQueryParam()`, raw superglobals in three places).
  Standardize opportunistically.
- Minor debt: unused `EquipmentController::$UserfieldsService`; unreachable duplicate
  block in `ExceptionController` (also: it trusts `$exception->getCode()` as an HTTP
  status — clamp it); config comment for locale priority contradicts
  `LocaleMiddleware`'s actual order; `composer.json` pins PHP `8.5.*` while the real
  code floor is 8.4.

## API surface

**Verdict: structurally sound and spec-honest; per-endpoint error discipline is the
weak spot.**

- Route table vs `victual.openapi.json`: of ~74 API routes, the only mismatch is the
  self-referential `/api/openapi/specification` missing from the spec. The
  `ExposedEntity` allow-lists are read from the spec at runtime, so entity drift is
  impossible by construction — a genuinely good mechanism, and the right foundation
  for the "additive API" ground rule.
- Error handling is where uniformity breaks: permission failures return 403 or 400
  depending on whether `CheckPermission` sits inside or outside a `try`;
  `UsersApiController` alone re-emits real status codes via a second catch; malformed
  `?query[]=`/`?order=` params 500 with stack traces; missing objects are 404 from
  `GetObject` but 400 from `EditObject`/`DeleteObject`. **Direction:** one shared
  helper in `BaseApiController` (catch `HttpSpecializedException` before the generic
  catch, map query-parse errors to 400, missing resource to 404) and hoist permission
  checks above try blocks — a mechanical, low-risk cleanup that would make the whole
  API behave like its best controller.
- The 401 path bypasses the JSON and CORS middleware (auth is app-level, they are
  route-level): unauthenticated API calls get a bodyless 401 without CORS headers,
  and `OPTIONS` preflights sit behind auth, so browser cross-origin API-key use
  cannot work at all despite `CorsMiddleware` existing. Short-circuit `OPTIONS` and
  emit the standard error JSON on 401 — or, given the deployment, consider making
  CORS config-driven and default-off.
- The deeper "additive API" risk: nearly every response is a raw LessQL row/view
  serialized as-is, so the DB schema *is* the wire contract, with no tripwire when a
  migration changes it. The differential tests already check JSON value types across
  engines; extending that idea one layer up — a snapshot test of JSON key sets and
  scalar types per endpoint against the OpenAPI schemas — is the single best
  investment before building the MCP endpoint (plan 02) on top of this API.
- Smaller items: `ChoresApiController::CalculateNextExecutionAssignments` has no
  permission check; generic CRUD allows mass assignment of `id`/timestamps and the
  `ExposedEntityEditRequiresAdmin` gate is an empty enum (dead code — populate or
  remove); API keys are accepted via query parameter (logs) and stored/compared in
  plaintext; keys' `last_used` is UPDATEd on every request.

  *This bullet is the state on 2026-08-27 and is kept as the record of what was found.
  [Plan 11](plans/11-api-error-handling.md) landed the whole list in wave 2 on
  2026-09-04, with API-key expiry and rotation following on 2026-09-15; the plan
  records each piece inline. On the enum specifically, 11-Q6 chose **populate**:
  `ExposedEntityEditRequiresAdmin` now reads `["userfields", "userentities"]`, so
  editing those two definition-level entities requires `ADMIN` and a non-admin holding
  only `MASTER_DATA_EDIT` is answered 403.*

## Frontend (views, viewjs, shared JS)

**Verdict: stable, convention-complete, no dead files — but the conventions are
enforced by copying, and the copies are drifting.**

- The wiring is exemplary for a no-framework app: layout auto-loads
  `/viewjs/{view}.js`, all API traffic goes through `Victual.Api.*` (zero `$.ajax`/
  `fetch` bypasses), inline Blade scripts are data-injection only, and every
  view/viewjs/route name lines up.
- **Silent failures are the default error path:** 148 error callbacks in 41 files do
  nothing but `console.error(xhr)` — a failed delete gives the user nothing. The fix
  is central, not 148-fold: make `Victual.Api`'s error parameter default to
  `Victual.FrontendHelpers.ShowGenericError`.
- **Clone families:** ~14 master-data list scripts and ~15 entity-form scripts are
  byte-identical modulo entity name (~2,300 lines total); the delete-confirm dialog
  appears 31 times; `Victual.Api` itself repeats its 30-line XHR handler six times (and
  has no timeout/`onerror` handling — a dropped connection during save leaves the UI
  busy-locked forever); `datetimepicker2` is a full 344-line clone of
  `datetimepicker` existing only so two pickers can share a page. Drift is already
  observable: sibling lists disagree about the embedded-dialog reload convention,
  `userobjectform.js` lost the Enter-to-submit handler its siblings have,
  `stockjournal.js`/`userpermissions.js` hand-roll `toastr.error(JSON.parse(...))`.
  **Direction:** three small shared helpers — `VictualEntityList(entity, opts)`,
  `VictualEntityForm(entity, url)`, and a single private `request()` core inside
  `Victual.Api` — would collapse ~1,500 lines and end the drift. Do it before plans
  05/06/08 add more list/form pairs to copy from.
- `purchase.js` doubles as an unguarded shared library on three other pages (pulled
  in via `@push` in their Blade views); extract the shared dialog logic instead.
- Minor: list-page Blade chrome repeats across ~14 templates (partials exist and are
  used elsewhere — apply the idiom); `mealplan`/`recipes` blades inject bare globals
  instead of namespacing under `Victual.*`; two components aren't registered under
  `Victual.Components`.

## Services and database layer

**Verdict: the dialect abstraction is well designed and cleanly honored by the core
plumbing (`DatabaseService`, migrations, the API's `§` regex operator) — but not yet
fully honored by its consumers.** `StockService` reached for raw SQLite date SQL
(defect 3), and `DemoDataGeneratorService` is SQLite-only yet ran whenever
`VICTUAL_MODE` is dev/demo/prerelease (defect 13). Both are now fixed — but not the way
this section proposed. The review assumed the missing piece was a dialect primitive
for "today"/"N days from now"; in practice every consumer wanted a *per-request
constant*, not a per-row expression, so the fix was to compute the cutoff in PHP and
bind it. No primitive was added, and none is needed until something genuinely has to
do date arithmetic per row inside a query.

The observation underneath still holds: no service calls `GetNowExpression()` and only
`BaseApiController` calls `GetRegexpCondition()`. The dialect's real consumers are the
plumbing, not the services — which is the right shape, now that the services no longer
have engine-specific SQL to hide.

- **Transactions are applied inconsistently, and the highest-risk operations are the
  unwrapped ones.** `MergeProducts`, `CompactStockEntries`, `ChoresService::
  MergeChores`, and `RecipesService::ConsumeRecipe` correctly wrap their multi-row
  writes in transactions — proof the pattern is known. But `ConsumeProduct` (a loop
  of stock-entry deletes/updates plus `stock_log` inserts), `TransferProduct` (same,
  with a synchronous webhook interleaved per entry), `UndoBooking` (recursive), and
  `UndoTransaction` (iterates `UndoBooking`) run as sequences of autocommit
  statements. A failure mid-loop leaves a half-consumed booking in an inventory
  ledger. This is the single most valuable hardening in the services layer, and a
  prerequisite for letting an assistant drive writes via the planned MCP endpoint
  (plan 02). Wrapping the four entrypoints mirrors the existing pattern.
- **The migration runner has no concurrency guard — a direct k3s concern.**
  `MigrateDatabase()` runs on every hit to `/`, and both the baseline path and each
  migration do check-then-apply with no lock. Two pods (or two tabs) racing through a
  cold start roll back cleanly on the loser's primary-key violation — no corruption —
  but produce a user-visible 500; the always-run `8888.php` migration has the same
  race with no try/catch. Fix: `pg_advisory_lock` (pgsql) / `flock` (sqlite) around
  `MigrateDatabase()`, and preferably move migration to an init step outside request
  handling entirely (see Statelessness — same work item).
- **`DatabaseImporter` (SQLite→PostgreSQL) truncates the target, then copies with no
  surrounding transaction** — a failure partway leaves the target truncated and
  half-repopulated. Its post-check compares row counts only, not values, despite the
  porting rules documenting several type-coercion hazards that would pass a count
  check. Wrap the import in a target-side transaction and add a value-level spot
  check.
- **StockService as god class:** large but cohesive; the debt is the 7× repeated
  undo-bookkeeping block in `UndoBooking`'s switch and the mixed return conventions
  (LessQL rows from most methods, plain `stdClass` from the raw-SQL ones — callers
  must know which flavor they got). No correctness landmines found beyond the
  transaction gap.
- **Forward risk for plan 07 (nested products), confirmed at the SQL level:**
  `products_resolved` is a flat one-level mapping on both engines, and
  `StockService.php:1146,1204` embed that one-level assumption in raw SQL; the
  `stock_current` aggregated columns build on it. The plan's audit table is accurate;
  note the difftest tooling has never exercised a recursive CTE, so plan 08/07's
  fixtures will be new ground for it too. Relatedly, the 0256+ dual-engine migration
  mechanism (generic vs per-engine file precedence) reads correctly but has never run
  real content — the first shipped migration should be a small one (plan 06 is ideal).
- **Cosmetics:** `GetSystemInfo`/`GetSystemTime` report `sqlite_version` diagnostics
  from a throwaway in-memory connection even on pgsql installs (misleading in the
  About dialog); `DemoDataGeneratorService` and `SqliteDialect` construct services
  with `new` against the otherwise-universal `GetInstance()` convention (4 sites vs
  ~320); the new `services/Database/` namespace is constructor-injected PSR-style —
  the better pattern, and the direction new services should take.

## Uniformity: the short list of deviants

Files that break their area's dominant pattern (details in sections above):
`StockReportsController` (raw SQL in controller), `ExceptionController` (API base
class for HTML errors, manual construction), `UsersApiController` (the only
correct-status API controller — make its pattern the base), `RecipesApiController::
AddNotFulfilledProductsToShoppingList` (no try/catch), `FilesApiController` (throws
404 for input errors), `CalendarApiController::Ical` (non-JSON, fine),
`userobjectform.js`, `userpermissions.js`, `stockjournal.js`,
`quantityunitconversionsresolved.js`, `productgroups.js` (frontend drift markers),
`purchase.js` (dual-role script), `LoginController` (superglobals + static dispatch),
`DemoDataGeneratorService`.

## Suggested order of remedial work

1. ~~**Defects table** — one sitting; items 1–3 before anything else (3 blocks the
   PostgreSQL story; 1 and 2 are one-liners with real impact).~~ **Done** (`36650cd`).
2. **Cold-start rework** (viewcache/route-cache into the image, migrations to an
   init step, advisory lock) — this is the scale-to-zero enabler and should precede
   serious k3s work.
3. **API error-handling unification** in `BaseApiController` + the 401/OPTIONS
   middleware ordering fix.
4. **Frontend shared helpers** (`request()` core with default error toast, entity
   list/form factories) — before plans 05/06/08 mint new copies.
5. **Transactions around the four unwrapped stock entrypoints** (`ConsumeProduct`,
   `TransferProduct`, `UndoBooking`, `UndoTransaction`) — before plan 02 writes.
6. **Response-contract snapshot test** — before plan 02 ships, so the "additive API"
   rule is enforced by a failing test instead of vigilance.

Items 3–6 are each a focused session; none block the roadmap plans, but 4–6
specifically de-risk them.

## Latent oddities surfaced by the documentation pass

Found while documenting (annotated in-code, deliberately not fixed in a
comment-only pass):

- `public/viewjs/userform.js` — sets `Victual.DeleteUserePictureOnSave` (typo, extra
  "e") where the submit handler checks `DeleteUserPictureOnSave`: choosing a new
  picture likely fails to cancel a pending "delete current picture" flag.
- `public/viewjs/tasks.js` — the user-filter handler builds an anchored regex in an
  if/else and never uses it; the filter silently falls back to substring search.
- `controllers/Api/StockApiController.php` — `ConsumeProduct` reads
  `transaction_type` in one place and `transactiontype` in another; one spelling can
  never match a real request body.
- `public/viewjs/stockoverview.js` — calls `UndoStockTransaction()` defined in
  `purchase.js`; works only because the Blade view also pulls in `purchase.js`
  (see the frontend section's `purchase.js` finding).
- `services/FilesService.php:54,70` — the double `$bestFitHeight` test and the
  unimported `ImageResizeException` (defect 10 in the table above). **Fixed.**

The other four items in this list are still open — they were not part of the defects
table and remain untouched.
