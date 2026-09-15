# 11. API error handling, auth surface and error logging

**Goal:** Make the whole API behave like its best controller — real status codes, one
error shape, permission failures that say 403 — and close the API-key and middleware
gaps found alongside them.
**Depends on:** nothing hard, but land
[14 contract and regression scaffolding](14-contract-and-regression-scaffolding.md)
first if both are being done, so the status-code changes here show up as a diff rather
than as an assertion.
**Status:** landed in wave 2, 2026-09-04, recorded inline under each section rather than in an
Executed section. Its one follow-up, API key expiry and rotation
([issue 130](https://github.com/datagen24/victual/issues/130)), landed 2026-09-15 -
recorded below, in the same place the rest of the API-key hygiene work is. Contains the
only deliberate response-shape changes in the hardening set.

## Today

The `/api` route group registers 87 operations across 74 paths and `victual.openapi.json`
documents 86 across 73 — one mismatch, `GET /openapi/specification`, which is routed and
undocumented (see [14](14-contract-and-regression-scaffolding.md)). The earlier reading
here, that the totals agreed at 86 apiece with two mismatches hidden inside them, was
wrong in both halves: it dropped one route on the way in and invented one spec-only path.
The
`ExposedEntity` allow-lists are read from the spec at runtime so entity drift is
impossible by construction, and every controller returns the same
`{ "error_message": … }` body. The structure is sound. What is not uniform is *which
status code* that body arrives with, and it is not uniform in four separate ways.

**Permission checks land inside or outside a `try` at random.** `User::CheckPermission`
throws a Slim `HttpForbiddenException`. Where the call sits above the `try`
(`GenericEntityApiController::AddObject`, `UsersApiController::CreateUser`) the exception
escapes to `ExceptionController` and the client gets 403. Where it sits inside
the generic `catch (\Exception)` swallows it and returns 400 with the forbidden message
in the body. Same failure, two status codes, decided by indentation. The scale is worth
being precise about: of the 54 `CheckPermission` call sites, only 7 sit inside a `try` at
all, and the real 400→403 blast radius is three routes — `POST /chores/{id}/execute`,
`POST /chores/executions/{id}/undo`, and `GET /print/shoppinglist/thermal`. The
inconsistency is systemic; the observable change is small.

**`UsersApiController` is the only controller that gets it right**, via a
`catch (\Slim\Exception\HttpSpecializedException)` ahead of the generic catch that
re-emits `$ex->getCode()`. That is the pattern; it exists in exactly three methods
(`controllers/UsersApiController.php:35`, `:217`, `:269`) and nowhere else in the tree.

**Query-parse failures are 500s.** `BaseApiController::QueryData` throws a bare
`\Exception('Invalid sort order …')` and `FilterData` throws `\Exception('Invalid query')`.
Neither is caught by the list endpoints, so a malformed `?order=` or `?query[]=` — client
error, entirely — reaches `ExceptionController` as an unclassified throwable and is
answered 500.

> **Landed early — this plan's validation approach, applied to the `query[]`/`order`
> surface only.** The rest of the plan is unbuilt. What is in the tree is the piece the
> hazard-16/17 work needed, and it is recorded here because this plan is its home:
>
> - `Invalid sort order` and `Invalid query` are `HttpException(400)` rather than bare
>   `\Exception`, so they no longer arrive as 500s.
> - **The field is validated before any SQL is built.** `DatabaseDialect::GetColumnTypes()`
>   reports the entity's columns per engine, and an unknown field is
>   `400 Invalid query: unknown field "x"` on both. That is hazard 17's fix as well as this
>   plan's: `?order=Name` used to sort on SQLite and 500 on PostgreSQL, and `?order=nope`
>   was a 500 on both.
> - **`~`, `!~` and `§` are restricted to text columns**, by one shared rule
>   (`DatabaseDialect::IsTextMatchableType()`) rather than one per engine, so both give the
>   same answer. `?query[]=id~2` used to match on SQLite, which coerces, and 500 on
>   PostgreSQL, which has no such operator for the type. Both now answer `400`.
> - **`ColumnTypeManifest` closes what two catalogues cannot.** SQLite does not type a
>   computed view column, so eligibility would otherwise be engine-dependent on exactly the
>   columns worth searching. 13 semantic entries, applied identically to both engines,
>   gap-filling only, and enforced by the suite. All 731 columns across 82 shared
>   tables/views now reach the same verdict on both engines.
> - **`MaterialiseFiltered()` is the backstop**: the filtered query is run where it can be
>   caught, and a PDO failure on a request that carried caller-supplied `query[]`/`order`
>   becomes a `400` rather than an unclassified 500.
> - **A catalogue that cannot be read is a `500`, not a silent pass.** Validation that
>   cannot be performed is refused rather than skipped — failing open would restore the
>   200/500 divergence intermittently and invisibly. An unfiltered request needs no
>   validation and is unaffected, so this costs filtering rather than availability.
>
> The failure log is `error_log()` because this fork has no logger. That is this plan's
> other half — "error logging" is in its title — and this line is one of the things that
> wants it.
>
> The direction was deliberate and is worth keeping in view when the rest of this plan
> lands: casting the column to text on PostgreSQL would also have made `id~2` work on both,
> and was rejected because the two engines render a float differently (`1.0` against `1`),
> which would have replaced a loud error with a silent wrong answer. See hazard 16 in
> `db/pgsql/README.md` for the measurements and for the one residual this leaves.
>
> **Client-visible, and so belongs in this plan's breaking-changes list**: three request
> shapes that used to return `200` on SQLite now return `400` on both engines —
> `~`/`!~`/`§` on a non-text column, an unknown field in `query[]`, and `order` naming a
> field in the wrong case. Nothing that was already `400` moved, and no successful response
> changed shape.

> **Landed early — the same validation approach, applied to path parameters.** Found by
> the parity suite and filed as issue #48, and recorded here because this plan owns the
> error surface. The rest of the plan is still unbuilt.
>
> - **An integer id is validated before any SQL is built**, by
>   `middleware/PathParameterMiddleware.php` on the `/api` group. A non-integer where the
>   endpoint takes an integer id is `400 Invalid path parameter: {name} has to be an
>   integer`, on every id-taking endpoint, from one place.
> - **Which parameters those are is read from `victual.openapi.json`**, not from a list in
>   the middleware. The spec already draws the distinction a list keyed on the parameter
>   name cannot: `{objectId}` is an integer on `/objects/{entity}/{objectId}` and a string
>   on `/userfields/{entity}/{objectId}`, because `userfield_values.object_id` is `TEXT`
>   and carries a grocycode for the `stock` entity.
> - **`.devtools/check-path-id-validation.php` in the `suite` job** fails when a route takes
>   a path parameter the spec does not type, so "the spec did not say" cannot quietly mean
>   "unvalidated". Three `/recipes/{recipeId}` parameters were typed `string` while
>   `recipes.id` is `INTEGER` when this landed — one of them on a route that answered 500 —
>   which is the drift it exists to catch.
> - **No response carries the driver's message any more.** `GenericErrorResponse()` refuses
>   a message beginning `SQLSTATE[`, and `ExceptionController` does the same for one that
>   escaped. This matters beyond the 500s: most controller methods are written as
>   `catch (\Exception $ex) { … $ex->getMessage() … }`, and `PDOException` is an
>   `\Exception`, so **45 endpoints answered 400 with the failing statement in the body** —
>   `PUT /api/objects/equipment/undefined` was one. Fixing only the 500s would have left
>   every one of those.
>
> Sanitising in `GenericErrorResponse()` rather than at the 45 call sites is deliberate: it
> cannot be forgotten by the next method written, and it leaves those methods to this
> plan's `HandleApiCall()`, which is what should classify exceptions properly. When that
> lands, `PDOException` needs its own row in its table — as drafted, its `\Exception`
> fallback of "400, exactly as today" would have re-opened the leak.
>
> **Client-visible, and so belongs in this plan's breaking-changes list**: a non-integer id
> answered `500` (six endpoints) or `400` with driver text (the rest) and now answers `400`
> with a fixed message everywhere. Upstream answers `404` for the same request, because
> SQLite's dynamic typing makes it a silent non-match; the fork's `400` is deliberate — a
> malformed id is a request that cannot be parsed, not a row that is absent — and is
> recorded in the parity suite as `non-integer-object-id`.

> **Landed — the middleware ordering, the CORS setting and the error log.** This is the
> second of the three sessions the Effort section splits this plan into, taken first
> because it is self-contained and because the logging half was what made every other
> failure in this plan invisible in production. Landed 2026-09-04 in wave 2. The shared
> `HandleApiCall()` helper and the API-key work are still unbuilt.
>
> - **`JsonMiddleware` and `CorsMiddleware` are application level**, added after the
>   authentication middleware *and* after the error middleware, so they are outermost of
>   everything. On the route group they could not be: authentication and routing both ran
>   first. A 401 now carries `{"error_message": "Unauthorized"}` and
>   `Content-Type: application/json`; a 404 and a 405 are typed too, which they were not.
>   Both middlewares decide by path whether a request is theirs, so the rendered pages are
>   untouched — and that predicate is now `IsApiRoutePath()`, which strips
>   `VICTUAL_BASE_PATH` first. The bare `string_starts_with($path, '/api/')` it replaces
>   answered **false for every API request on an installation mounted in a
>   subdirectory**, in `ExceptionController` and `BaseAuthMiddleware` alike — an API error
>   there was an HTML page.
> - **`CorsMiddleware` has to be outside the routing middleware, not merely outside
>   authentication.** The plan said "before authentication is attempted", which is
>   necessary and not sufficient: no route registers `OPTIONS`, so routing raises
>   `HttpMethodNotAllowedException` before any inner middleware runs. That is the real
>   reason the `$app->any('/api/{routes:.+}', …)` catch-all existed, and deleting it
>   without moving CORS outside routing would have replaced a 401 preflight with a 405 one.
> - **The catch-all is deleted**, as the plan said to decide deliberately. An unmatched
>   `/api/*` path answered 200 with an empty body and now answers 404 — a row for the
>   change table below, added here rather than left implicit.
> - **`VICTUAL_CORS_ALLOWED_ORIGINS`, empty by default**, per Q3. Exact-match against the
>   request's `Origin`, `Vary: Origin` on every API response once the list is non-empty,
>   and the entries are validated at startup by `ConfigurationValidator` — a value written
>   with a trailing slash matches nothing a browser sends, so it is refused rather than
>   silently behaving as if CORS were off. Sweep **S21** closes with it.
> - **A preflight is answered 204 whether or not the origin is allowed.** It carries no
>   credentials, so authenticating it could only ever refuse a request that was asking
>   permission; a disallowed origin gets the 204 without CORS headers, which is what makes
>   the browser refuse the real request.
> - **Errors are logged**: `helpers/StderrLogger.php`, a PSR-3 logger writing one line per
>   record to `php://stderr` (Q7), handed to `ExceptionController` through its constructor.
>   Slim's `addErrorMiddleware()` only passes its logger to *its own* `ErrorHandler`, and
>   this application replaces that handler — so the parameter the plan noted on
>   `__invoke()` is not the socket, and the constructor is. The line carries method, path,
>   status, exception class, message and — under `logErrorDetails` — file, line and trace.
>   It carries no request body, deliberately: bodies here contain product notes, user names
>   and passwords. A client error logs at `warning` and a server fault at `error`, so a
>   malformed filter cannot drown a real fault.
> - **Sweep S9 closes with it**, because it is the same surface: the 500 page's detail
>   block — file, line, message, stack trace and `json_encode($systemInfo)` — is now
>   rendered in `dev` mode only and every value in it is printed with `{{ }}` rather than
>   `{!! !!}`. The `/` route is unauthenticated, so the old page showed all of it to
>   anonymous visitors, and an exception message is the one string on that page that can
>   carry request data.
>
> **One defect this move introduced, found by reading the code it now wraps rather than by
> a failing test, and fixed the same day.** `JsonMiddleware` set `Content-Type` on every API
> response and recognised the two that choose their own — a file download and the calendar
> feed — by their `Content-Disposition`. Out here it also wraps `SchemaVersionMiddleware`,
> whose schema-mismatch 503 is deliberately `text/plain`, and which has no
> `Content-Disposition` to be recognised by. It now leaves any response that set a type
> alone, which covers all three by the rule rather than two by the symptom. Stamping
> `application/json` on a plain-text body would have been a lie in the one response an
> operator reads when a deployment is misconfigured.
>
> Verified on a booted instance, both modes: unauthenticated `GET /api/system/info`
> answers `401` with the JSON body and content type; `OPTIONS` answers `204`, with the
> CORS headers for a configured origin and without them for any other; a UI page carries
> no CORS header at all; an unmatched `/api/*` answers `404` as JSON while an unmatched UI
> path still answers HTML; and a forced exception in production leaves the stderr line with
> no `error_details` in the response, while in `dev` the same exception renders on the page
> with `&lt;script&gt;` escaped.

**A related divergence in the same method was *not* a status-code question, and has since
been fixed** — noted here because it is the reason `FilterData` no longer spells its own
operators. `FilterData`'s `~` and `!~` emitted `LIKE`, which is case-insensitive on SQLite
and case-sensitive on PostgreSQL, so the same filter returned different rows on the two
engines with no error at all (hazard 16). It now calls `GetLikeCondition()` on the dialect,
mirroring `GetRegexpCondition()`, and PostgreSQL gets `ILIKE`. SQLite's behaviour was taken
as the reference, so no client pointed at a SQLite instance sees any change; a client
pointed at PostgreSQL now gets the rows the API always documented.

**A create that creates nothing answers 200.** `POST /api/objects/{entity}` with a body
that sets no column reaches `GenericEntityApiController::AddObject`, where LessQL skips the
insert entirely — a row with no modified columns is already "clean" — and the endpoint then
asks the driver for the id of an insert that never happened and answers 200 with it.
`pdo_sqlite` says the string `"0"`, `pdo_pgsql` raises `SQLSTATE[55000] lastval is not yet
defined in this session` and LessQL turns that into `null`. Neither is an object id. The
body is a client error and wants a 400; until then the parity suite records the
`null`-versus-`"0"` difference against this plan as `no-insert-no-last-insert-id`
(issue #47).

**Missing objects are 404 or 400 depending on the verb.** `GetObject` returns
`GenericErrorResponse($response, 'Object not found', 404)`; `EditObject` and `DeleteObject`
return `GenericErrorResponse($response, 'Object not found', 400)`. (This paragraph used to
say `GetObject` throws a Slim `HttpNotFoundException`, which stopped being true before
issue #48 was written; the verb-dependent inconsistency it describes is still real.)

Alongside those, five smaller things on the same surface:

- **`ChoresApiController::CalculateNextExecutionAssignments` has no permission check at
  all.** Any authenticated key can recompute every chore's assignment.
- **Generic CRUD is mass-assignable.** `AddObject`/`EditObject` pass the purified request
  body straight to `createRow()`/`update()`, so `id` and the `row_created_timestamp`
  columns are writable by any client with edit permission.
- **`ExposedEntityEditRequiresAdmin` is an empty enum.** `IsEntityWithEditRequiresAdmin`
  is called in three places and can never return true. It is either a missing policy or
  dead code, and right now nobody can tell which.
- **The 401 path bypasses the JSON and CORS middleware.** Auth is added app-level
  (`app.php:110`), `JsonMiddleware`/`CorsMiddleware` are added per route group
  (`routes.php:268`), so a 401 from `BaseAuthMiddleware` is bodyless, has no
  `Content-Type` and no CORS headers. `OPTIONS` preflights hit auth first and are 401'd
  before `CorsMiddleware` ever runs, which means browser cross-origin API-key use cannot
  work at all despite CORS being implemented.
- **There is already a workaround for that, sitting outside the group.**
  `routes.php:271-275` registers a catch-all `$app->any('/api/{routes:.+}', …)` returning
  an empty response with `CorsMiddleware` attached, commented "For CORS preflight OPTIONS
  requests". It is a second, parallel CORS path — and because it is `any` rather than
  `options`, it is also the fallback that answers any unmatched `/api/*` request.
- **`CorsMiddleware` is unconditionally `Access-Control-Allow-Origin: *`.** Not
  configurable, not disableable.

**API keys.** `ApiKeyAuthMiddleware` accepts the key in the `VICTUAL-API-KEY` header, in a
query parameter of the same name ("not recommended", per its own comment — and it lands
in every access log and every `Referer`), and, on the iCal route only, in `?secret=`.
Keys are stored and compared in plaintext. `last_used` is `UPDATE`d on every
authenticated request.

**Errors are not logged anywhere.** Defect 9 gated `displayErrorDetails` on dev mode,
which stopped serving stack traces to clients. The second and third arguments of
`addErrorMiddleware` — `logErrors`, `logErrorDetails` — are still `false`, and no logger
is wired. In production a 500 currently leaves no trace at all. The socket for this is
already cut: `ExceptionController::__invoke` takes a `?LoggerInterface $logger`
parameter and nothing ever passes one. That was deliberately deferred out of the defects
pass; this is where it lands.

> **Landed — the shared helper, the controller conversion and the mass-assignment
> blocklist.** The first and third of the three sessions the Effort section splits this
> plan into, 2026-09-04, in wave 2. What is still unbuilt after this is the API-key work
> (Q4's hashing, the query-parameter form) — everything else in this plan is in the tree.
>
> - **`BaseApiController::HandleApiCall()` exists and every API controller method uses
>   it.** 67 methods carried the identical
>   `catch (\Exception $ex) { return $this->GenericErrorResponse($response, $ex->getMessage()); }`,
>   which answers 400 to everything; they are now closures handed to one classifier.
>   `EInvalidApiQuery` and `EObjectNotFound` are the two new types the plan called for.
> - **The table gained two rows the plan drafted as open.** `FileTooLargeException` moves
>   into the helper from `FilesApiController`, which is the only place it was ever caught,
>   and keeps its 413. `PDOException` **stays a 400** rather than becoming a 500: the plan
>   noted it "needs its own row" because the drafted `\Exception` fallback would have
>   re-opened issue #48's leak, and the answer is that the leak is closed by the *message*
>   rather than by the status — `GenericErrorResponse()` already replaces driver text
>   whatever route it arrives by — while the common cause of a `PDOException` here is a
>   request body naming a column that does not exist, which is the caller's error. It
>   therefore needs no clause and has none. `\Error` is deliberately not caught either: a
>   `TypeError` is this application being wrong about its own types, and 400 would file a
>   bug as a client mistake. (`POST /api/users` with an empty body is exactly that today —
>   a 500 from a `TypeError` in `UsersService::CreateUser` — and it is fixed by validating
>   the body in the S5/S6 work rather than by widening this catch.)
> - **The seven permission checks that sat inside a `try` are above the wrapper**, except
>   the one in `TrackChoreExecution` that cannot be: it fires only when `done_by` names
>   another user, so it needs the parsed body. The helper answers it 403 anyway, which is
>   the point of having a helper. `UsersApiController`'s three hand-written
>   `catch (HttpSpecializedException)` blocks — the pattern this plan generalised — are
>   deleted, since the helper is now that pattern.
> - **`CalculateNextExecutionAssignments` gains `PERMISSION_CHORES`** (Q2). Proved on a
>   booted instance with a user holding the `CHORE_TRACK_EXECUTION` leaf and not its
>   parent — the one population Q2 predicted this excludes — and with the chore
>   assignments hashed before and after the 403 to confirm nothing ran.
> - **`PUT` and `DELETE` on a missing object answer 404**, including the api_keys
>   ownership guard, which deliberately answers the same "not found" as a genuinely
>   missing row so that ids cannot be enumerated. The guard therefore moved with it rather
>   than being left behind as the one 400.
> - **Mass assignment is closed** by the Q5 blocklist: `id` and `row_created_timestamp`
>   are dropped from the body in `AddObject` and `EditObject` (sweep **S16**'s first half;
>   the body-schema validation half stays with 14 piece 2, as Q5 says). Keys are dropped
>   rather than refused, so a client that reads an object, edits a field and PUTs the whole
>   thing back — which the fork's own forms do — keeps working.
> - **`ExposedEntityEditRequiresAdmin` is populated** with `userfields` and `userentities`
>   (Q6), so the gate that could never fire now does.
> - **A create that creates nothing is a 400.** The plan said the body is a client error
>   and wants one; it now gets one, and the parity suite's `no-insert-no-last-insert-id`
>   entry is replaced by `create-with-no-fields-refused` recording the refusal instead of
>   the two ways of saying nothing happened. Note the interaction with the blocklist: a
>   body of `{"id": 5}` is empty after stripping, and so is refused rather than silently
>   creating a row.
> - **`FilesApiController` and `RecipesApiController::AddNotFulfilledProductsToShoppingList`
>   are on the helper like everything else**, which is what the two named deviants needed.
>   `ServeFile` used to re-throw *every* failure as a 404, so an invalid group and an
>   invalid file name were indistinguishable from a file that is not there; those are 400
>   now and "not found" stays 404.
> - **The spec was edited with the code, not left for 14.** The `500`/`Error500` response
>   is gone from exactly the nine list operations this plan names — that is the one
>   response-shape narrowing here, and the changelog names them. Every operation gained a
>   `401`, the 40 whose handler checks a permission gained a `403`, and the operations that
>   can now say "not found" gained a `404`; all three point at a new `ApiError` schema,
>   identical in shape to `Error400` under a name that does not claim a status code.
>
> Verified on booted instances rather than by reading the diff. A 63-call snapshot of the
> API — every list endpoint, the filter and ordering failure paths, missing ids, and
> fifteen writes — was taken before the conversion and again after it: **every status code
> identical, and every body identical modulo timestamps, `uniqid()` handles and the demo
> generator's random prices.** The intended changes were then made and the same snapshot
> re-run, which moved exactly three rows (the empty create to 400, and `PUT`/`DELETE` on a
> missing id to 404) and nothing else. The 403s were proved separately in production mode
> against three real users — an admin, one holding `MASTER_DATA_EDIT` and the
> `CHORE_TRACK_EXECUTION` leaf, and one holding nothing — and the mass-assignment fix by
> reading `products.id` and `row_created_timestamp` back after a `PUT` that tried to set
> both.

## Proposed change

### One error helper in `BaseApiController`

```php
protected function HandleApiCall(Response $response, callable $work): Response
```

Controllers wrap their body in it instead of writing their own `try`. Inside, in order:

| Caught | Answer |
|---|---|
| `HttpSpecializedException` (403, 404, 405, …) | `$ex->getCode()`, message from the exception |
| a new `EInvalidApiQuery` from `QueryData`/`FilterData` | 400 |
| a new `EObjectNotFound` from the CRUD paths | 404 |
| `\Exception` | 400, exactly as today |

The two new exception types are the only way to distinguish "the client asked for
something impossible" from "something broke", which is the distinction the current single
`\Exception` catch cannot make. Both extend `\Exception`, so any controller not yet
converted keeps behaving as it does today — the migration is per method, not big-bang.

**Permission checks move above the wrapper** everywhere, which is the mechanical half of
the fix and is what makes 403 mean 403. `CalculateNextExecutionAssignments` gains the
check it is missing (`PERMISSION_CHORES` — see Q2, the choice is not obvious).

The three named deviants from the review get the same treatment:
`RecipesApiController::AddNotFulfilledProductsToShoppingList` (no `try` at all, so every
failure is a 500) and `FilesApiController` (throws 404 for what are input errors) end up
on the shared helper like everything else.

### Middleware ordering

Move `JsonMiddleware` and `CorsMiddleware` from the route group to app level, added
*after* the auth middleware so they wrap it (Slim's `add()` is LIFO — outermost last).
Then a 401 carries `Content-Type: application/json`, the standard
`{ "error_message": "Unauthorized" }` body and CORS headers, and `OPTIONS` is
short-circuited by `CorsMiddleware` before authentication is attempted.

That move must also settle the `$app->any('/api/{routes:.+}', …)` catch-all at
`routes.php:271-275`. Once `CorsMiddleware` runs app-level ahead of auth, the catch-all's
stated reason to exist is gone and it should be deleted — but deleting it changes what an
unmatched `/api/*` path returns (today: 200 with an empty body from the catch-all;
after: a 404 from Slim through `ExceptionController`), so it is a behaviour change to
make deliberately rather than as a side effect. Leaving it in place alongside an
app-level `CorsMiddleware` means two code paths adding the same headers, which is the
worse option of the two.

`CorsMiddleware` becomes config-driven: `VICTUAL_CORS_ALLOWED_ORIGINS`, empty by default,
meaning no CORS headers at all. Given the deployment target — a household instance behind
an ingress, with no browser-based third-party client — default-off is the honest default,
and `*` on an authenticated API was never a good one. Q3 covers whether the default
should instead preserve today's behaviour.

### API-key hygiene

> **Landed, 2026-09-04, all three.**
>
> - **The query-parameter form is gone**, with 15-C1 rather than here, because that
>   refactor rewrote the file that read it. The header and, on the calendar route only,
>   `?secret=` are the two ways a key reaches the application.
> - **Keys are stored as SHA-256**, per Q4: migration `0263` (a per-engine pair) adds
>   `api_keys.key_hint`, and `0264` (PHP, portable) hashes each key in place and backfills
>   the hint from its last four characters. A key issued before the migration keeps
>   working — proved on a database migrated from 262 with a plaintext key in it.
> - **`last_used` is stamped once a day, not once a request.** A read-only GET used to
>   issue a write on the hot path of the endpoint clients poll most, to record a value the
>   screen displays as a date.
>
> **One thing this plan did not anticipate, because it was written before the wave that
> fixed it:** special-purpose calendar keys are deliberately *not* hashed. Sweep finding
> S17's fix is what made the sharing link work at all, and the application has to be able
> to hand that URL back to whoever asks for it — which it cannot do from a hash.
> Regenerating the key on each request instead would break every calendar application
> already subscribed. The exposure is bounded and is not the API: `ApiKeyAuthenticator`
> accepts such a key on the `calendar-ical` route only, and `IsValidApiKey()` checks
> `key_type`, so a leaked table yields the household's calendar rather than an account.
> `ApiKeyService::StoredValueOf()` is the one place that distinction lives.
>
> **The manage-keys screen changed in the way Q4 said it would, and one way it did not
> say.** It shows `••••` plus the hint instead of the key, and offers the QR code only for
> a special-purpose key — for a regular one there is nothing left to encode. The way it
> did not say: creating a key now *renders* the page rather than redirecting to it, because
> the response to the create is the only moment the plaintext exists. Putting it in the
> redirect URL was the obvious alternative and is exactly the query-string key path S11
> exists to remove, in the place it would be most durable — browser history.
>
> **That moved a sweep S29 sink rather than removing one, and the payload probe follows
> it.** The QR dialog interpolates a key's description into a `bootbox` message, which
> renders as HTML; with no plaintext left on a regular key's row there is nothing to encode
> there, so the dialog now exists only on the one-time reveal — which carries the
> description just typed, both so a person can tell which key they are looking at and so the
> sink stays a real one. `.devtools/frontend/s29-payload.js`'s `manageapikeys-qr` case
> creates a key rather than finding one, for the same reason. **The `frontend-security` job
> would have failed without that**, and not for the interesting reason: its seed read the new
> key's id out of the redirect this change removed, so *both* API-key probes reported "the
> sink was never reached". Found by running the probe locally rather than by watching CI.

> **Landed, 2026-09-15 — expiry and rotation ([issue 130](https://github.com/datagen24/victual/issues/130)), closing S11's last residual.**
>
> - **A regular key gets a real, finite expiry.** `ApiKeyService::CreateApiKey()` takes an
>   optional lifetime in days, clamped to the new `VICTUAL_API_KEY_MAX_LIFETIME_DAYS`
>   (default 365) and defaulting to that maximum when the caller gives none — the manage-
>   keys "Add" modal's new "Expires in (days)" field is that caller. The clamp, not a
>   refusal, because this is a view form rather than an API request. Every other key type
>   (the calendar sharing key, and the label worker/verifier/renderer credentials
>   `LabelWorkerCredentialService` writes directly and which never call this method) keeps
>   the year-2999 expiry unconditionally, so ADR-0019's paired rotation and the calendar
>   key's own story are untouched — the issue's own "must not regress those" line.
> - **Rotation is create-a-successor-then-retire**, exactly as the issue asked: rotating
>   creates a new key of the same type and description
>   (`ApiKeyService::RotateApiKey()`) and does nothing to the predecessor at all, so it
>   keeps authenticating through the rotation with no gap. Retiring the predecessor is the
>   caller's own, separate, explicit act — the existing ownership-checked
>   `DELETE /api/objects/api_keys/{id}` — never a side effect of creating the successor.
>   Restricted to `API_KEY_TYPE_DEFAULT`: rotating any other type is refused, both in the
>   service and again in `OpenApiController::RotateApiKey()`, so the special-purpose
>   rotation stories cannot be reached through the new path.
> - **The lineage lives in a real column, per ADR-0007.** Migration `0280.pgsql.sql` adds
>   `api_keys.rotated_from_id`, a nullable self-reference with `ON DELETE SET NULL` rather
>   than `CASCADE` — deleting a predecessor (the retirement step) must not take its
>   successor down with it, which would turn "retire the old key" into "break the new one".
>   No process-memory or APCu state anywhere in this.
> - **The manage-keys screen** gained the expiry field on creation and a "Rotate" button
>   per regular-type row; both are `POST`, for the S8 reason `/manageapikeys/new` already
>   is. Rotating renders the new plaintext once, exactly as creating does, with a note that
>   the predecessor keeps working until it is deleted. The rotate confirmation is a second
>   bootbox sink on this page, carrying the same `data-apikey-name` the delete confirmation
>   does, so it was given its own `.devtools/frontend/s29-payload.js` case
>   (`manageapikeys-rotate`) rather than assumed safe by neighbourhood — the S29 amendment
>   above is the reason that assumption is never made twice.
>
> **A defect found in review, the same day, before merge: the successor of an admin's
> rotation belonged to the admin, not to the key's actual owner.** `CreateApiKey()` always
> wrote `user_id => VICTUAL_USER_ID` — the caller — and `RotateApiKey()` passed nothing to
> override it. The controller deliberately lets an admin rotate a key that is not theirs
> (the same rule `DeleteObject` already applies to `api_keys`), so an admin rotating a
> household member's key minted a row that authenticated as the admin, carrying that
> member's own description and `rotated_from_id` — installed in their client, it would have
> handed the admin their session rather than replacing their key. `CreateApiKey()` gained
> an explicit `$ownerId` parameter (every other caller keeps passing none, meaning "the
> current user"), and `RotateApiKey()` passes the predecessor's own `user_id` through it.
> The admin-rotation case in `apikey-tests.php` now asserts the successor's owner
> (`SELECT user_id FROM api_keys WHERE rotated_from_id = …`) rather than only the response
> shape, which is what let this pass review's own tests the first time — confirmed by
> reverting the fix and watching that assertion fail before restoring it.
>
> Verified against real PostgreSQL 16.13 (2026-09-15) with a new
> `.devtools/pgsql/apikey-tests.php` suite phase (`run-tests.sh apikeys`, 31/31
> assertions), and the full `run-tests.sh all` (21 phases) green alongside it: a key with a short
> lifetime is accepted before its stored expiry and refused after; an over-long requested
> lifetime is clamped rather than refused; a calendar or label-worker-type key created via
> `CreateApiKey()` still gets the year-2999 expiry; a rotated predecessor authenticates
> throughout the rotation and is refused only once explicitly deleted, while its successor
> authenticates throughout and survives the predecessor's deletion; rotation is refused for
> a special-purpose key both in the service and in the controller; the hint/hash behaviour
> from 0263/0264 is unchanged for a rotated key; and, at the controller layer, a non-admin
> rotating someone else's key gets the same 404 a missing row would (so ids cannot be
> enumerated), a non-integer id is refused the same way, an admin may rotate a key that is
> not theirs, and both `CreateNewApiKey` and `RotateApiKey` render the manage-keys page with
> the new plaintext rather than redirecting to it. **Not run**: the manage-keys UI in an
> actual browser and the `frontend-security` job. This sandbox's PHP is 8.4.19, and while
> the standalone `.devtools/pgsql/` suite (which never loads `app.php`) is unaffected, the
> application's `PrerequisiteChecker` refuses every HTTP route below PHP 8.5.0, which
> `composer.json` requires and this sandbox does not have installed.

- **Drop the query-parameter form** of `VICTUAL-API-KEY`. The iCal `?secret=` path is
  separate, is scoped to `API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL`, is the reason that
  affordance exists at all, and stays.
- **Hash stored keys** (Q4). This is doable without invalidating anything: a migration
  hashes each existing plaintext key in place, clients keep sending the same string, and
  lookup becomes hash-then-compare. What is lost is the manage-API-keys screen's ability
  to display an existing key — it can only show a key once, at creation.
- **Stop writing `last_used` on every request** — round to the day, or drop the column's
  update to "only when it changes date". A read-only GET currently issues a write, which
  is both a needless write and a needless invalidation.

### Mass assignment

Strip `id` and `row_created_timestamp` from the request body in `AddObject`/`EditObject`
before `createRow()`/`update()`. A blocklist of two columns is the small version; deriving
an allowlist per entity from the OpenAPI schemas is the thorough version and is Q5.

`ExposedEntityEditRequiresAdmin` gets populated or deleted — Q6. It cannot stay an empty
enum with three live call sites.

### Error logging

Wire a PSR-3 logger writing to `php://stderr` (the correct sink for a container; the
platform collects it) and set `addErrorMiddleware(VICTUAL_MODE === 'dev', true, true)`.
Log line carries method, path, status, exception class, message, file and line. It must
not carry the request body — bodies here contain product notes, user names and, on the
user endpoints, passwords.

### Schema

Two migrations, both gated on Q4's yes to hashing, because Q4's answer brings DDL with
it:

- a per-engine `NNNN.sqlite.sql` / `NNNN.pgsql.sql` pair adding the `api_keys.key_hint`
  column. Adding a column is DDL and DDL is where the two engines diverge, so a pair is
  the right one of the three shapes here — the "portable single file" reading only held
  while the change was a pure `UPDATE`, and the third shape does not apply because both
  engines genuinely need the column, which is what would have to be true to write
  `@engine-exclusive` and mean it;
- a `NNNN.php` migration doing the hashing itself: read each row, hash `api_key` in
  place, populate `key_hint` from its last four characters. This half genuinely is
  engine-agnostic — it runs through `ExecutePhpMigrationWhenNeeded` on both — and it must
  be numbered after the DDL pair so the column exists when it runs.

`api_keys.api_key` changes meaning from plaintext to hash. Note that it is irreversible
by construction, which is the point, and that both files must run under the lock from
[10](10-cold-start-statelessness.md) like everything else.

### API

**This is the one plan in the hardening set that deliberately changes existing
responses.** The additive-API ground rule is about response *shape*; this changes status
codes, and that needs to be explicit rather than slipped in:

| Case | Today | After |
|---|---|---|
| Permission denied, check inside a `try` | 400 | 403 |
| Malformed `?query[]=` / `?order=` | 500 | 400 |
| `PUT`/`DELETE` on a missing object | 400 | 404 |
| Unauthenticated API call | bodyless 401 | 401 with JSON body |
| `OPTIONS` on an API route | 401 | 204 with CORS headers |
| Cross-origin `GET` | `Allow-Origin: *` | no CORS header unless configured |
| Unmatched `/api/*` path | 200, empty body | 404 |
| `POST /api/objects/{entity}` with a body that sets no column | 200, `created_object_id` that identifies nothing | 400 |
| `GET /api/files/...` with an invalid group or file name | 404 | 400 |
| API key in a query parameter | accepted | 401 |
| `POST`/`PUT`/`DELETE` `/api/objects/{userfields\|userentities}` as a non-admin | 200 | 403 |
| `?query[]=` or `?order=` naming a field the caller may not see | 200, filtered | 400 |

The **final** row is added by [19](19-rbac.md) rather than by this plan, and is recorded
here because this is where the status-code contract lives. It is the only shape in the
list that goes from 200 to 400; the other rows that used to succeed now deny, with 401 or
403. Mechanically it is one call site:
`AssertFieldExists()` already refuses a field the entity does not have, from both the
`query[]` and the `order` path, and it gains the caller's field policy alongside the column
list. Note that a filter on a redacted field and a filter on a nonexistent one deliberately
share the 400 and differ only in message — a distinct code would confirm the field exists,
which is the hole the redaction closes. Nothing else in 19 needs a slot in the taxonomy
above: a redacted field is a 200 with a shorter body, and a refused call is this plan's
403, so the two are distinguishable without a new error kind.

**Client impact: the largest on the roadmap after [16](16-project-rename.md), and unlike
16's it is knowable in advance.** Every row above is a client-visible change, and the ones
that bite are the ones where a client's *success* path moves: a client treating any
non-2xx as "retry" now retries a 403 forever, and one that read a bodyless 401 by status
alone now parses a JSON body it did not expect. The wildcard CORS removal is the one that
breaks silently in a browser and not in a test. This is why the roadmap puts
[14](14-contract-and-regression-scaffolding.md) before this plan — ~74 routes are better
shown as a diff than asserted by hand — and why [17](17-ecosystem-clients.md)'s manifests
want to cover status codes and response keys, not just paths.

The `userfields`/`userentities` row, second from the end, is Q6's answer and is a
deliberate behaviour change, not a code correction: populating
`ExposedEntityEditRequiresAdmin` turns a gate that can never fire
into one that does, and a non-admin who can edit master data today can create user fields
today. Accepted — definition-level entities reshape the data model — but it is the one
row here that denies something that currently succeeds, so it belongs on the
breaking-changes list with the rest rather than being read as a bug fix.

**One of these rows is a response-shape change, and the ground rule says to say so.**
The malformed-`?query[]=`/`?order=` row is not only a status code. The nine list
operations that document a `500` today — `GET /objects/{entity}`, `/users`,
`/stock/products/{productId}/locations`, `/stock/products/{productId}/entries`,
`/stock/locations/{locationId}/entries`, `/recipes/fulfillment`, `/chores`,
`/batteries`, `/tasks` — document it as the `Error500` schema, which carries
`error_details` (`stack_trace`, `file`, `line`) alongside `error_message`. `Error400`
carries `error_message` only. So a client that hits an invalid filter parameter moves
from a documented body with an optional `error_details` object to one without it. That
is a narrowing, the additive-API rule in the [README](README.md) is about exactly this,
and it needs two things rather than a shrug: a spec edit removing the `500`/`Error500`
response from those nine operations as part of this plan (not left for
[14](14-contract-and-regression-scaffolding.md) to notice), and a changelog entry naming
the nine.

Response *bodies* are otherwise unchanged in shape: still `{ "error_message": … }`.
Success responses are untouched.

The Home Assistant integration and the iOS app are the two consumers to think about.
Neither should be affected by codes that only appear on failure paths — but "should" is
not evidence, and Q1 exists because of that.

The OpenAPI spec's error coverage is broad but coarse: 76 operations document a `400`
(schema `Error400`) and 9 document a `500` (schema `Error500`), and that is the whole of
it — no operation documents a `403`, a `404` or a `401` anywhere. So the work is not
adding error responses to a spec that has none; it is adding the codes this plan makes
real to operations that currently claim `400` is the only way to fail. Each converted
endpoint gets its real `4xx` responses added, which also makes the spec the place the
contract test in [14](14-contract-and-regression-scaffolding.md) reads from. There is one
route/spec mismatch, not the two this plan previously listed — `/api/openapi/specification`
is in the route table and not the spec, and is fixed in 14 alongside the parity check that
would have caught it. `/api/recipes/{recipeId}/copy` was *not* the second: **corrected
2026-08-29**, the route exists at `routes.php:237`, written `$group->Post(` with a capital
`P` that a case-sensitive grep for `$group->post(` steps straight over. See 14's Today
section for the corrected counts and for what it means for the parity assertion.

## Verification

1. **Status-code matrix, before and after, on a booted instance.** For every API route:
   call it unauthenticated, with a key lacking the permission, with a malformed
   `?order=zzz`, and against a non-existent id. Record method, path, case, status. The
   before-run is the baseline; the after-run must differ only in the rows of the table
   above. Doing this by hand across 87 operations is the argument for landing
   [14](14-contract-and-regression-scaffolding.md) first and adding this as a case there.
2. **Success responses byte-identical.** Same harness, happy paths only, both engines:
   the diff must be empty. This is the check that the helper refactor did not change
   anything it was not supposed to.
3. **Two-user API-key test, repeated.** The defects pass established this for defect 4;
   re-run it after the key changes, including: key in header works, key in query
   parameter is now rejected, iCal `?secret=` still works, a key created before the
   hashing migration still authenticates afterwards.
4. **Browser preflight.** From a page on a different origin, with
   `VICTUAL_CORS_ALLOWED_ORIGINS` set to that origin: `OPTIONS` returns 204 with the
   headers, the subsequent `GET` with a key succeeds. With the setting empty: no CORS
   headers on either, which is the intended default.
5. **Permission check on the chores endpoint.** `POST
   /api/chores/executions/calculate-next-assignments` with a key whose user lacks the
   chosen permission must return 403, and the chore assignments in the database must be
   unchanged — checked by comparing `chores.next_execution_assigned_to_user_id` across
   all rows before and after.
6. **Mass assignment.** `POST /api/objects/locations` with `{"name":"x","id":9999}` must
   create a row whose id is the next sequence value, not 9999; and on PostgreSQL the
   identity counter must still be correct for the *next* insert afterwards — this is the
   same class of failure the fixing pass flagged in `DemoDataGeneratorService`.
7. **Logging.** Force a 500 (a temporarily broken query) in production mode and confirm:
   a line on stderr with the exception class and location, and a response body with no
   `error_details`.

## Sequencing

**After [14](14-contract-and-regression-scaffolding.md), before
[02 MCP](02-mcp-endpoint.md).** The dependency on 14 is soft but real: verification
checks 1 and 2 above are exactly what 14 builds, and doing this plan first means doing
that work twice or doing it by hand.

02 is the plan this most directly de-risks. Every MCP tool is a call onto this API (or
onto the same services behind it); an assistant that receives 400 for "you are not
allowed" and 500 for "you typed the filter wrong" cannot recover sensibly from either.
If 02's Q6 response lands on a separate MCP container calling
the REST API, this plan stops being a nicety and becomes the interface contract.

Against the other hardening plans: independent of [10](10-cold-start-statelessness.md)
and [12](12-frontend-shared-core.md). It overlaps [15](15-deliberate-cleanup.md) in the
auth middleware — do the middleware *ordering* change here (it is three lines in
`app.php` and `routes.php`) and leave the authenticator-class extraction to 15, rather
than entangling a small ordering fix with a refactor.

It blocks no feature plan. It should land before any new API surface is written, because
every new endpoint written before it is another one to convert afterwards.

**A constraint this plan inherits from the deployment**, recorded here because it is
easy to implement wrong and easy to miss: sweep S12's login throttle lands in this
plan's wave, and **its state cannot live in the process.** The target is a pod that
scales to zero and, per [17](17-ecosystem-clients.md)'s Q2, actually will — idle
windows of a night or longer are the normal case rather than the edge one. An in-memory
or APCu counter is therefore reset for free by an attacker who waits, which is the same
as having no throttle at all while looking like having one. Redis is always-on in the
cluster and is the obvious home; a table is the alternative and costs a write per
attempt. Either is fine. Neither is optional.

The same reasoning applies to anything else this plan might keep between requests —
error-rate counters, log sampling state, an `Origin` nonce if S8's check ever needs one.
On this deployment, "keep it in memory" means "keep it until the pod next sleeps".

## Open questions

1. **Ship the status-code changes outright, or behind a compatibility flag?** They are
   corrections, every one of them is on a failure path, and a flag means maintaining two
   behaviours forever. I lean to shipping them outright and putting them on the
   deliberate breaking-changes list in [15](15-deliberate-cleanup.md) — but that is a
   real call, and the answer depends on whether the Home Assistant integration
   distinguishes 400 from 403 anywhere. Worth ten minutes reading its error handling
   before deciding.

   > **Response:** Ship outright, no flag. All changed codes are on failure paths,
   > they are corrections, and a flag means testing two behaviours forever. Do the
   > Home Assistant read first as confirmation, not as a decision gate. Changelog
   > entry, listed with 15's breaking batch for visibility.
2. **Which permission for `CalculateNextExecutionAssignments`?** It is a write (it
   assigns chores to users) exposed as a `POST` with no check on it at all, so
   `PERMISSION_CHORES` is the obvious reading. The alternative would be a finer-grained
   or a laxer gate, and the answer depends on who is expected to be able to trigger a
   recalculation, which is a product question.

   > **Response:** `PERMISSION_CHORES`, and nothing else — no server-side render
   > change, no second gate. The two premises that made this look hard both fail on
   > inspection. First, there is no viewer-lockout tension: all four JS callers
   > (`choreform.js:39` and `:66`, `choresoverview.js:355` and `:381`) fire
   > *after* a write the caller has just performed, so none of them is a render
   > refresh and none of them is reachable by a user who could not already write.
   > Second, the gate is weak rather than restrictive — `CHORES` is the *parent* of
   > `CHORE_TRACK_EXECUTION` in the permission hierarchy
   > (`migrations/0110.sql:52`, `:78`), so anyone holding the feature permission
   > holds tracking too and this does not carve out a "chore manager" tier. It
   > excludes exactly one population: a user granted the leaf
   > `CHORE_TRACK_EXECUTION` without its parent. That is the right amount of gate
   > for an endpoint that currently has none, and it costs nothing to add.
3. **CORS default: off, or preserve `*`?** Off is right for the stated deployment and
   makes the setting meaningful. Preserving `*` avoids breaking a browser-based client
   that might exist and that I do not know about. I lean off, on the grounds that
   `Allow-Origin: *` on an API-key-authenticated endpoint is not a feature anyone should
   be relying on.

   > **Response:** Off, without reservation. `Allow-Origin: *` on an authenticated
   > API was never a feature; nothing browser-cross-origin exists; the ingress can
   > add headers in an emergency.
4. **Hash API keys?** Yes on principle; the cost is that the manage-keys screen can only
   ever show a key at creation time, and anyone who wrote a key down nowhere and reads it
   back off that screen loses that. For a household instance that is a mild annoyance
   against a real improvement. The migration is one-way, which is the honest form of the
   change but also means "decide once".

   > **Response:** Yes — and use SHA-256, not `password_hash`. Keys must be looked
   > up *by value*, which salted bcrypt cannot do without a full-table scan, and
   > these are 50-character random strings (~250 bits): brute force is not the
   > threat model, a leaked `api_keys` table is. Unsalted SHA-256 gives O(1) lookup
   > and is exactly right for high-entropy secrets. Keep a `key_hint` (last four
   > characters) column so the manage screen can still identify keys after
   > creation — and note that `key_hint` is a new column, so this answer brings DDL
   > with it and the change is no longer a single portable PHP migration. It ships
   > as a per-engine `NNNN.sqlite.sql` / `NNNN.pgsql.sql` pair adding the column,
   > followed by a `NNNN.php` migration that hashes each key and backfills the
   > hint. The Schema section above is written to match.
5. **Mass-assignment: blocklist or spec-derived allowlist?** Blocklisting `id` and the
   timestamps is five minutes and covers the known problem. An allowlist derived from the
   OpenAPI schemas is correct-by-construction and would also catch the next column added
   with a meaning nobody wants clients writing — but the spec's entity schemas would have
   to be complete enough to trust, and that has never been tested.

   > **Response:** Blocklist now — `id` + `row_created_timestamp` covers the known
   > problem in five minutes. The spec-derived allowlist depends on entity schemas
   > being complete, and 14's snapshot-vs-schema leg is what will make them
   > trustworthy. Revisit the allowlist after 14 has run for a while; do not build
   > it on an unvalidated spec.
6. **`ExposedEntityEditRequiresAdmin`: populate or delete?** If there is a set of entities
   where a non-admin should be able to read but not edit, name them and populate it. If
   there is not, delete the enum and its three call sites. Leaving an empty gate in place
   is the one option that is definitely wrong.

   > **Response:** Populate with `userfields` and `userentities` — definition-level
   > entities that reshape the data model, which is a different act from editing
   > master data. Accept the consequence and record it: a non-admin who can `POST`,
   > `PUT` or `DELETE` `/api/objects/userfields` or `/api/objects/userentities`
   > today starts getting 403, which is a new denial of something that currently
   > succeeds rather than a corrected status code. It is now a row in the change
   > table above and belongs in the changelog with the rest of the breaking batch.
   > If on reflection nobody should be admin-gated, delete the enum and its three
   > call sites the same day and drop the row.
7. **What is the retention story for the error log?** stderr and let the platform handle
   it is the k3s answer and needs no code. But a household instance with no log
   aggregation gets errors that scroll away. A file with rotation is more work and
   reintroduces a writable path that [10](10-cold-start-statelessness.md) just removed.
   I lean stderr only.

   > **Response:** stderr only. Correct for k3s and for the household case too —
   > `kubectl logs` / `docker logs` *is* the log file. A rotating file reintroduces
   > the writable path 10 just removed; decline it.

## Effort

Medium, and it splits cleanly into three sessions that can land separately: the shared
helper plus the mechanical per-controller conversion (the bulk, and the boring part); the
middleware ordering, CORS setting and error logging (small, self-contained, could go
first); the API-key and mass-assignment work (small, but Q4 gates it). The verification
is the part that is easy to underestimate — checks 1 and 2 across 87 operations on two
engines is not something to do by hand more than once.
