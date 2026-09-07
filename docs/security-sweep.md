# Security sweep — 2026-08-29

Run against `origin/master` at `6060de5` (PR #23). Static review only: every finding
below was checked by opening the file; nothing was booted and nothing was exploited.

**Status, 2026-08-29:** the wave 0.5 hotfix has landed — S1, S2, S3, S7, S23 and R1 are
fixed and verified on a booted instance. See [What the hotfix
changed](#what-the-hotfix-changed) below, which also records where the fix departed from
the remediation this document proposed and why. Everything else stands as written.
References are to symbols rather than line numbers, per the rigor review's D5.

**Status, 2026-09-04:** wave 2 closed the rest of the authentication and permission work.
**Every High and Med finding is now fixed**, with two named residuals rather than none:
S11's *expiry and rotation* half — the query-string key path is gone and stored keys are
hashed — and S16's *body-schema validation* half, where the `id`/`row_created_timestamp`
blocklist is in and the spec-derived allowlist is deliberately parked behind
[14](plans/14-contract-and-regression-scaffolding.md) piece 2. What is left open below is
Low and Info: S13, S14, S15, S20, S22, S24, S26, and the two Info findings S30 and S31 that
belong to [19](plans/19-rbac.md). Each row says what was done and where it departed from
the remediation proposed here; wave 2's departures are worth reading, because two of them
are places this document was written before the thing it constrains existed.

Scope: authentication and sessions, API keys, CORS/CSRF, permission checks, input
sanitisation and output escaping, SQL construction, file upload/serve/delete, error
disclosure, webhooks and plugins (SSRF/command execution), dependencies, container and CI
configuration. Out of scope: the two external clients' code, the MCP sidecar (unbuilt),
and anything under `vendor/` or `public/packages/`.

Threat model, from `.github/SECURITY.md`: a household instance where "issues that require
an account a child would have" are in scope. Several findings are only interesting under
that model — a low-privilege account reaching an admin session — and are rated
accordingly.

## Summary

Two things need fixing before anything else on the roadmap: the request-body sanitiser
undoes its own escaping (S1), and the files API has no permission check and serves
uploads inline with a sniffed MIME type (S2). Either one alone gives any authenticated
account stored script in every other user's browser; the session cookie lacking
`HttpOnly` (S3) turns that into session theft. All three are small, disjoint changes.
Beneath them, the reverse-proxy auth backend trusts a client header with no proxy
allowlist (S4), and `DEFAULT_PERMISSIONS = ['ADMIN']` makes three separate paths mint
admins (S5).

One non-security regression surfaced on the way and is recorded here because it is live
and came from plan 16: every feature flag is dropped from the UI and the API (R1).

## Findings

| # | Sev | Finding | Where | Fix |
|---|---|---|---|---|
| S1 | **High** — *fixed* | **Sanitiser un-escapes after purifying.** `GetParsedAndFilteredRequestBody` runs HTMLPurifier, then `str_replace`s `&lt;`/`&gt;`/`&amp;` back to raw characters. Text that arrived as entity-encoded `&lt;script&gt;` leaves the purifier as the (safe) entity text and is then converted to a literal `<script>` and stored. Views then emit these fields unescaped: `stockoverview.blade.php` (`{!! $currentStockEntry->product_description !!}`), `recipes.blade.php` (`{!! $recipe->description !!}`, position notes), `shoppinglist.blade.php` (item notes, three places), `components/userfields_tbody.blade.php` (userfield values). Any account with `MASTER_DATA_EDIT`, `RECIPES` or `SHOPPINGLIST_ITEMS_ADD` gets stored XSS against every user including admins. Inherited from upstream. | `controllers/Api/BaseApiController.php::GetParsedAndFilteredRequestBody` | Delete the three `str_replace` calls. If some non-HTML column needs a literal `&`, handle it per column, not globally. |
| S2 | **High** — *fixed* | **Files API: no permission check, arbitrary upload, inline serve with sniffed type.** `FilesApiController::DeleteFile/ServeFile/UploadFile` never call `User::CheckPermission`; every other API controller does. `UploadFile` accepts any body under any extension; `ServeFile` answers with `Content-Type: mime_content_type($filePath)` and `Content-Disposition: inline`. An `.svg`/`.html` upload (or HTML named `manual.pdf`) executes in the app origin, and the UI links straight to it (`userfields_tbody.blade.php` userfile links, `equipmentform.blade.php` `<embed>` of manuals). Any zero-permission account can also `unlink` every picture and manual in all five groups. No `X-Content-Type-Options: nosniff` and no CSP anywhere in the tree. | `controllers/Api/FilesApiController.php`, `services/FilesService.php::DeleteFile` | Permission per group on PUT/DELETE (`MASTER_DATA_EDIT` for productpictures/equipmentmanuals, `RECIPES` for recipepictures, `USERS_EDIT`/self for userpictures); allow-list extensions per group and validate images with `getimagesize`; `attachment` disposition unless the sniffed type is a safe image; add `nosniff` and a `sandbox` CSP on the files route. |
| S3 | **High** — *fixed* | **Session cookie has no `HttpOnly`, `Secure` or `SameSite`.** `BaseAuthMiddleware::SetSessionCookie` is a bare `setcookie(name, key, PHP_INT_MAX >> 32)`. The key *is* the credential (`SessionAuthMiddleware` reads `$_COOKIE` straight into `IsValidSession`). Without `HttpOnly`, S1/S2 become session theft; without `SameSite` (on browsers that do not default to Lax) the CSRF surface in S8 is reachable. Client-side expiry is ~2106 regardless of the 30-day server expiry. This is plan 15-B2, currently scheduled in wave 2 behind 11. | `middleware/Auth/BaseAuthMiddleware.php::SetSessionCookie` | `setcookie(name, key, ['httponly'=>true, 'samesite'=>'Lax', 'secure'=>isHttps, 'path'=>base path, 'expires'=>…])`. Pull 15-B2 forward; it is one line and nothing reads the cookie from JavaScript. |
| S4 | **High** (when `ReverseProxyAuthMiddleware` is configured) — *fixed* | **Reverse-proxy auth trusts a request header with no trusted-proxy check.** In the default `REVERSE_PROXY_AUTH_USE_ENV = false` mode `AuthenticateRequest` reads `$request->getHeader(VICTUAL_REVERSE_PROXY_AUTH_HEADER)` and, if no user matches, `CreateUser`s one — with `DEFAULT_PERMISSIONS`, i.e. ADMIN (S5). Nothing compares `REMOTE_ADDR` to a proxy allowlist, so anyone who can reach the PHP backend directly, or whose proxy does not strip inbound `REMOTE_USER`, is admin. On the k3s target, "reach the backend directly" is any pod in the namespace. | `middleware/Auth/ReverseProxyAuthMiddleware.php::AuthenticateRequest` | Add a `REVERSE_PROXY_AUTH_TRUSTED_PROXIES` CIDR list checked against `REMOTE_ADDR`, refuse when unset; prefer `USE_ENV` (server-populated) and document that the proxy must strip the header inbound. |
| S5 | **Med** — *fixed* | **`DEFAULT_PERMISSIONS = ['ADMIN']` mints admins on three paths.** `UsersService::CreateUser` grants it unconditionally, so: any LDAP user matching `LDAP_USER_FILTER` is admin on first login; any reverse-proxy username is admin (S4); and a user holding only `USERS_CREATE` can `POST /api/users` an admin and log in as it — a direct escalation past the permission model. | `config-dist.php` `Setting('DEFAULT_PERMISSIONS', …)`, `services/UsersService.php::CreateUser` | Default to a minimal set; never grant a permission the creating user lacks. **Done 2026-09-04 in wave 2**, both halves: `DEFAULT_PERMISSIONS` is `[]`, and `POST /api/users` refuses with 403 when the closure of what the default would confer is not a subset of the creator's resolved permissions (`User::CheckMayGrant`). The two call sites with no creator to compare against — reverse-proxy user creation, and the LDAP one that 15-B1 has now deleted — get the config default and nothing else, which is why the config half was the important one there. |
| S6 | **Med** — *fixed* | **`USERS_EDIT` can reset any user's password, including admins.** `UsersApiController::EditUser` checks `USERS_EDIT` (or `USERS_EDIT_SELF`) and `UsersService::EditUser` rehashes any non-empty password. No check that the target's permissions are a subset of the caller's; no current-password confirmation on self-edit. | `controllers/Api/UsersApiController.php::EditUser`, `services/UsersService.php::EditUser` | Refuse to edit users holding permissions the caller lacks; require the current password for self password change. **Done 2026-09-04 in wave 2**, both halves, and applied wider than the finding: `User::MayAdminister()` compares the target's resolved permissions against the caller's over `user_permissions_resolved`, and gates `PUT /api/users/{id}`, `DELETE /api/users/{id}`, both permission-assignment endpoints and the deletion of another user's picture. Changing your own password needs `current_password` (or `current_password_base64`), which the user form now asks for. The chain noted below is why the rule and not "just require `USERS_EDIT`" is the fix. |
| S7 | **Med** — *fixed* | **Sanitiser allow-list admits `iframe[src]` from any origin, `id` on every element and `data:` URIs.** `HTML.SafeIframe` with `URI.SafeIframeRegexp = '%^.*%'` and `*[style|class|id]`. Independently of S1, a master-data editor can embed an arbitrary external page in every user's stock overview (phishing overlay) and DOM-clobber the front-end via `id`. | `controllers/Api/BaseApiController.php::GetParsedAndFilteredRequestBody` | Drop `iframe` and `id` from `HTML.Allowed`, or pin `SafeIframeRegexp` to specific hosts. |
| S8 | **Med** — *fixed* | **CSRF on state-changing routes that take no JSON body, and two state-changing GETs.** Most API writes are incidentally protected by the `Content-Type: application/json` check. Routes that act on path parameters only are not: `POST /api/stock/bookings/{id}/undo`, `/stock/transactions/{id}/undo`, `/stock/products/{a}/merge/{b}`, `/tasks/{id}/undo`, `/chores/executions/{id}/undo`, `/chores/{a}/merge/{b}`, `/recipes/{id}/copy`. `GET /logout` and `GET /manageapikeys/new` (creates an API key with an attacker-chosen description) are state-changing GETs, reachable even under `SameSite=Lax`. `PUT /api/users/{id}/permissions` uses raw `getParsedBody()` and so accepts form encoding. | `routes.php`, `controllers/Api/OpenApiController.php::CreateNewApiKey`, `controllers/LoginController.php::Logout`, `controllers/Api/UsersApiController.php::SetUserPermissions` | S3's `SameSite=Lax` closes most of it; make key creation and logout POST; add an `Origin` check for cookie-authenticated non-GET API requests. **Done 2026-09-04 in wave 2**, all three as remediated. `/logout` and `/manageapikeys/new` are `POST` (the nav item is a form, the key dialog submits one, and the payload probe seeds its key the same way). The `Origin` check lives in `BaseAuthMiddleware` and applies to a non-GET API request that was recognised by a credential the browser attaches on its own — a session cookie, or a reverse-proxy header — refusing it 403 when `Origin`, or failing that `Referer`, names another origin. An API-key request is exempt by construction: a key has to be put in a header deliberately. **An absent `Origin` is allowed**, which is the one deliberate gap: browsers send it on every cross-origin request, so this closes the browser case, while refusing an absent one would refuse a script driving the API with a session cookie. **A *present* one that is not this origin is refused whatever it says** — including the opaque `null` a sandboxed iframe or a `data:` document sends, and anything that does not parse as an origin at all. Treating `null` as an absence was a bypass a page could produce on purpose, and it is where review of this pull request caught the first draft. Proved on `POST /api/stock/bookings/{id}/undo` — a path-parameter-only write the JSON content-type check never covered. |
| S9 | **Med** — *fixed* | **500 page discloses trace, paths and system info to anyone, unescaped.** `ExceptionController` renders `errors/500` for every non-HTTP exception on a UI route; the `$displayErrorDetails` guard only covers the API branch. `errors/base.blade.php` prints `getFile():getLine()`, `getMessage()`, `getTraceAsString()` and `json_encode($systemInfo)` (PHP, OS, DB version) with `{!! !!}`. The `/` route is unauthenticated and runs migrations, so a migration failure shows this to anonymous users. Reflected XSS if any exception message ever carries request data (none found today — latent sink). | `controllers/ExceptionController.php`, `views/errors/base.blade.php` | Gate the detail block on `VICTUAL_MODE === 'dev'`; switch to `{{ }}`. **Done 2026-09-04 with [11](plans/11-api-error-handling.md)**, as remediated: the block is `dev` only and every value in it is printed with `{{ }}`. The operator's copy did not simply disappear with it — the same change wired `helpers/StderrLogger.php`, so an uncaught exception is now recorded on stderr with its class, file, line and trace, which in production was previously recorded nowhere at all. |
| S10 | **Med** — *fixed* | **Unbounded upload size and unbounded downscale cache.** `UploadFile` streams the raw body with no cap (a raw PUT is not subject to `post_max_size`). `FilesService::GetFilePath` names cache files from unclamped `best_fit_width`/`best_fit_height` (only `is_numeric`), so every distinct pair decodes and resizes the image again and writes a new file. Disk/CPU DoS for any account. | `controllers/Api/FilesApiController.php::UploadFile`, `services/FilesService.php::GetFilePath` | Cap upload size (413 above ~20 MB); clamp best-fit to a small allow-list of sizes. **Done 2026-09-02 with [01](plans/01-file-storage.md)** (`520919d`): `FILE_STORAGE_MAX_SIZE_MB`, default 64, enforced *while* the body is streamed rather than after it is stored — so it bounds the write, which is what the deferred half of S2's fix was about — with both storage backends discarding what they had written and the API answering 413 with the limit named. The effective limit is clamped to the smallest of the setting, `upload_max_filesize` and `post_max_size`, logged at startup and reported by `GET /api/system/config`, so a configured value PHP will not honour is visible rather than a silent lie. `best_fit_height`/`best_fit_width` snap to the nearest of 32, 64, 250, 400 and 800 — every size the front end asks for, plus one larger default — rather than 400ing, so existing clients keep working while the number of distinct decodes and cached copies is bounded. |
| S11 | **Med** — *fixed, except expiry* | **API key accepted from the query string; keys never expire.** `ApiKeyAuthMiddleware::AuthenticateRequest` falls back to `?VICTUAL-API-KEY=` — it lands in access logs, browser history and `Referer`. `ApiKeyService::CreateApiKey` sets expiry to 2999. | `middleware/Auth/ApiKeyAuthMiddleware.php`, `services/ApiKeyService.php::CreateApiKey` | Drop the query-string path (the iCal `secret` is the only legitimate URL-borne key and has its own branch); add expiry/rotation to keys. Plan 02's bearer-key seam should not inherit the query path. **The query-string path is gone, 2026-09-04 with 15-C1** — `ApiKeyAuthenticator` reads the header and, on the calendar route only, the `secret` parameter, which is what that affordance exists for. A key in `?VICTUAL-API-KEY=` is a 401. **Keys are also no longer stored in plaintext**, 2026-09-04 with plan 11's question 4 — migrations 0263 and 0264 replace each regular key with its SHA-256 hash and keep the last four characters as a hint, so a database dump is no longer a set of live credentials. A special-purpose calendar key is deliberately left readable and the reasoning is recorded in 0264 and in plan 11. Expiry and rotation are not done and stay open. |
| S12 | **Med** — *fixed* | **No brute-force protection on login; default `admin`/`admin`.** `DefaultAuthMiddleware::ProcessLogin` runs `password_verify` on every attempt with no counter or delay; `migrations/0027.php` seeds `admin`/`admin` with no forced change. | `middleware/Auth/DefaultAuthMiddleware.php`, `migrations/0027.php` | Per-IP/per-user throttle; force a password change while the seeded hash is in use. **The throttle state has to outlive the pod.** On the scale-to-zero target a counter held in process memory (or APCu) is reset every time the pod scales down, so an attacker resets it by waiting out the idle window — and per [17](plans/17-ecosystem-clients.md)'s Q2 those windows are long and ordinary. Put it in Redis, which is always-on in the cluster, or in a table. **Done 2026-09-04 in wave 2, in a table** (`login_attempts`, migration 0262, a per-engine pair) — so the counter survives the pod sleeping, which is the whole of why this note is here. One counter, **per username**, in a rolling window; `LOGIN_THROTTLE_MAX_ATTEMPTS` (10) and `LOGIN_THROTTLE_WINDOW_MINUTES` (15) configure it, and 0 turns it off. Hitting the limit is answered exactly like a wrong password, because saying so tells a guesser where the limit is. Rows are written on failure, dropped on the next success for that identity, and pruned to the window on every write, so a healthy installation's table is empty. The forced change is a stored flag rather than a check, because checking means an Argon2id verification on every request: the login path is the only place a plaintext password exists, so that is where the question is answered. It is the **`users.must_change_password` column** (migration 0265) and not the user setting the first draft used — review of this pull request pointed out that a setting is a bag its owner can empty, and `DELETE /api/user/settings/must_change_password` lifted the restriction without changing any password. While the flag is set, every *rendered page* redirects to the account's own edit form with the password fields open; API routes are deliberately untouched, since that form saves through the API.

**There is deliberately no per-address counter, and that is the finding's most interesting outcome.** The first draft had one, alongside the per-username limit. Review of this pull request found a bypass in how a success cleared it — it deleted every row from that address, so nine guesses at `admin` followed by logging into your own account wiped the slate, repeatable indefinitely — and narrowing it to the username alone then exposed the deeper problem the maintainer named: **behind a reverse proxy `REMOTE_ADDR` is the proxy for every request**, so a per-address count was never per-address. It was a whole-instance lockout wearing a per-address name, which is [ADR-0007](plans/../adr/0007-auth-state-outlives-the-process.md)'s own objection in a different disguise — something that looks like protection and is not.

So the counter is gone rather than tuned. What remains bounds the thing this finding is about, wherever the request came from: how many times one username may be guessed per window. Rate limiting a misbehaving *client address* needs the real address, which only the proxy knows, and belongs at that layer (fail2ban, `limit_req`, an ingress middleware); `config-dist.php` says so where an operator will read it. **The residual, stated plainly:** an attacker spreading attempts thinly across many usernames from one address is not slowed down by this application, by design. |
| S13 | **Low** | **`update.sh` wipes the install and unpacks an unsigned upstream Grocy zip.** `rm -rf !(data|update.sh)` then `wget https://releases.grocy.info/latest` and `unzip -o` — no checksum, no signature, and it is upstream Grocy, so it would destroy the fork's schema. Already flagged as homeless in the rigor review (H3). | `update.sh` | Delete it (15's non-breaking table). |
| S14 | **Low** | **Barcode lookup writes a file named from the raw route argument and fetches whatever URL the plugin returns.** `StockService::ExternalBarcodeLookup` uses `$pluginOutput['__barcode'] . '.' . $ext` as the picture filename without `IsValidFileName`, and `file_get_contents`s `__image_url` (`^https?://` only). Slim decodes the path before routing so `/` cannot reach `$args`, which limits it to odd names inside `productpictures/`; the fetch is SSRF only via a spoofed lookup service. Plan 09 adds more lookup sources and should inherit the fix. | `services/StockService.php::ExternalBarcodeLookup`, `plugins/OpenFoodFactsBarcodeLookupPlugin.php` | Filter `__barcode` to `[0-9A-Za-z_-]`, allow-list `$fileExtension` to image types, refuse loopback/private hosts before fetching. |
| S15 | **Low** | **Regex filter operator (`§`) runs caller-supplied patterns per row.** `SqliteDialect` registers `regexp` as `mb_ereg($pattern, $value)`; PostgreSQL's `~` is equally exposed. An authenticated caller can ReDoS a list endpoint. Not injection — the pattern is bound. | `services/Database/SqliteDialect.php`, `controllers/Api/BaseApiController.php` filter parsing | Cap pattern length and reject nested quantifiers, or restrict `§` to admins. Worth a line in plan 14's filter contract now that `filterdifftest.php` exists. |
| S16 | **Low** — *half fixed* | **Generic PUT/POST has no column allow-list.** `GenericEntityApiController::AddObject/EditObject` hand the whole body to `createRow`/`update`. `users`, `user_permissions`, `sessions` are not exposed and `api_keys` is `NoEdit`, so no escalation — but a `MASTER_DATA_EDIT` user can rewrite `id` and `row_created_timestamp` on any exposed row and create `userfields` with `entity = 'users'`. | `controllers/Api/GenericEntityApiController.php` | Strip `id`/`row_created_timestamp`; validate the body against the entity's OpenAPI schema (14 piece 2 territory). **The stripping is done, 2026-09-04 with [11](plans/11-api-error-handling.md)** — `WithoutServerOwnedColumns()` drops both keys in `AddObject` and `EditObject`, and drops them rather than refusing them so that a read-modify-write client keeps working. The schema validation half stays with 14 piece 2 on purpose: an allowlist derived from the spec is only correct if the entity schemas are complete, which has never been tested, and 14's snapshot leg is what will make them trustworthy (11's question 5). The `userfields` with `entity = 'users'` half of this row is a separate question and still open. |
| S17 | **Low** — *fixed* | **iCal `secret` branch is dead, and the calendar key is instance-wide.** `ApiKeyAuthMiddleware` only checks `secret` when `$this->RouteName === 'calendar-ical'`, but `RouteName` is set in `BaseAuthMiddleware::__invoke`, and `DefaultAuthMiddleware`/`ReverseProxyAuthMiddleware` construct a fresh `ApiKeyAuthMiddleware` and call `AuthenticateRequest` directly — so `RouteName` is null and sharing links 401. When fixed, note `ApiKeyService::GetOrCreateApiKey` selects by `key_type` only, not `user_id`: one calendar key is handed to every user and authenticates as whoever created it. This is the cross-instance construction 15-C1 exists to remove. | `middleware/Auth/ApiKeyAuthMiddleware.php`, `services/ApiKeyService.php::GetOrCreateApiKey` | Resolve the route inside `AuthenticateRequest` via `RouteContext::fromRequest`; scope special-purpose keys per user. Fold into 15-C1. **Done 2026-09-04 with 15-C1**, exactly as remediated: `ApiKeyAuthenticator` resolves the route itself, and `GetOrCreateApiKey` matches on `user_id` as well as `key_type`. Proved on a booted instance — `GET /api/calendar/ical/sharing-link` returns a URL that now answers 200 where it answered 401. The cross-instance construction that caused it is gone with the middlewares that did it. |
| S18 | **Low** — *fixed* | **`AUTH_CLASS` is instantiated from config/env/`settingoverrides` with no type check.** `app.php` does `new $authMiddlewareClass(...)`; `ConfigurationValidator` validates seven other settings and not this one. Same trust level as writing `config.php`, so Low — but 15-B1 already plans the check. | `app.php`, `helpers/ConfigurationValidator.php` | `is_subclass_of(VICTUAL_AUTH_CLASS, BaseAuthMiddleware::class)` in the validator. **Done 2026-09-04 with 15-B1**, as remediated, plus a `class_exists` check ahead of it whose message names the LDAP removal — an installation still configured for it is told in one line at startup instead of fataling on the first request. |
| S19 | **Low** — *three of four fixed, one moot* | **LDAP bind with no TLS enforcement; username enumeration by timing; logout leaves the cookie; sessions never pruned.** `LdapAuthMiddleware` never calls `ldap_start_tls` and the documented example is `ldap://`. `DefaultAuthMiddleware::ProcessLogin` short-circuits `password_verify` for unknown users (Argon2id makes the timing gap large). `LoginController::Logout` deletes the row but not the cookie; `sessions` grows without cleanup. LDAP goes away with 15-B1. | `middleware/Auth/LdapAuthMiddleware.php`, `middleware/Auth/DefaultAuthMiddleware.php`, `controllers/LoginController.php`, `services/SessionService.php` | Dummy-hash verify for unknown users; expire the cookie on logout; prune expired sessions on login. **Done 2026-09-04 in wave 2**, all three as remediated: `PasswordLogin` verifies against a constant Argon2id hash when the username is unknown, `LoginController::Logout` expires the cookie through `SessionCookie::Clear()`, and `SessionService::RemoveExpiredSessions()` runs on login. The LDAP TLS half is moot — 15-B1 deleted the backend. |
| S20 | **Low** | **`Host` header builds absolute redirect URLs.** `UrlManager::GetBaseUrl` uses `$_SERVER['HTTP_HOST']` when `BASE_URL` is `/`. Only exploitable if the web server accepts arbitrary `Host` values. | `helpers/UrlManager.php` | Require `BASE_URL` in the deployment docs, or validate `Host`. |
| S21 | **Low** — *fixed* | **Wildcard CORS on every response.** `Access-Control-Allow-Origin: *`, `Allow-Headers: *`, no `Allow-Credentials` — so cookies are not sent cross-origin and this is surface, not a hole. The preflight route is unnamed so `BaseAuthMiddleware` answers `OPTIONS` with 401 (functional, not security). | `middleware/CorsMiddleware.php`, `routes.php` | Restrict to configured origins once 17 decides which browser clients exist. **Done 2026-09-04 with [11](plans/11-api-error-handling.md)**, and without waiting on 17: `CORS_ALLOWED_ORIGINS` is empty by default, so the answer to "which browser clients exist" is now "none unless an operator names one" rather than "all of them". Entries are exact-matched against `Origin`, validated at startup so a trailing slash is refused rather than silently matching nothing, and `Vary: Origin` is sent once the list is non-empty. The unnamed preflight route is gone with the same change — `OPTIONS` is answered `204` by the middleware outside routing, where the 401 came from. |
| S22 | **Low** | **Integer ids concatenated into SQL, guarded upstream.** `StockService::MergeProducts` and `ChoresService::MergeChores` build `UPDATE … WHERE product_id = ' . $id` strings; safe only because the controllers `FILTER_VALIDATE_INT` first. `stock_id` strings are interpolated in quotes and are `uniqid()`-generated today. | `services/StockService.php::MergeProducts`, `services/ChoresService.php::MergeChores` | Pass as `?` params — `ExecuteDbStatement` already takes them. |
| S23 | **Low** — *fixed* | **Content-Disposition filename unquoted.** `ServeFile` concatenates the decoded name into `filename="…"`; `IsValidFileName` does not reject `"`. slim/psr7 rejects CR/LF so this is not header injection. | `controllers/Api/FilesApiController.php::ServeFile` | `filename*=UTF-8''` + `rawurlencode`. |
| S24 | **Low** | **GitHub Actions pinned to tags, not SHAs.** No secrets in the workflow and no `pull_request_target`, so supply-chain only. | `.github/workflows/tests.yml` | Pin to full SHAs. |
| S25 | **Info** — *fixed* | **Dev container runs as root and `COPY . /app` with no `.dockerignore`** (copies `.git` and `data/`); compose and CI use `victual`/`victual` Postgres credentials. All documented as non-production, tmpfs DB, no published ports. Matters only when 10 bakes a production image from this Dockerfile. | `Dockerfile`, `docker-compose.yml` | `.dockerignore`, non-root `USER`, before 10 publishes an image. **Done 2026-09-02 with [10](plans/10-cold-start-statelessness.md)** (`5a3ab76`): a `.dockerignore` keeping `.git`, `data/` and the build outputs out of the context, and a `production` target that runs as `www-data` with a baked, unwritable view cache and no baked credentials. The compose defaults stay and now say why in place — a tmpfs database with no published ports, created from nothing by every run, where changing the values moves the secret rather than removing it. CI builds both targets and asserts the non-root and no-`.git`/`data/` claims rather than describing them. |
| S26 | **Info** | **`DISABLE_AUTH`/non-production modes.** `MODE` is settable via env or `settingoverrides/MODE.txt`; `dev` disables auth entirely and enables API error details. `DISABLE_AUTH` defines `VICTUAL_USER_ID = 1` while the middleware picks the lowest-id user — they diverge if user 1 is deleted. | `app.php`, `middleware/Auth/BaseAuthMiddleware.php`, `services/SessionService.php` | Note only. |

## What the hotfix changed

Landed 2026-08-29, one PR, before wave 1. Each item was verified on a booted
instance rather than by reading the diff, per the roadmap's rule. Three of the six
departed from the remediation proposed above; each departure is recorded here with
the evidence that forced it.

**S1 — the sanitiser.** The proposed fix was to delete the three `str_replace` calls.
Booting the purifier against real input showed that doing so alone would store
`Ben &amp; Jerry's` for `Ben & Jerry's` in *every* text column, which then displays as
`Ben &amp;amp; Jerry's` wherever a view escapes it — every product name with an
ampersand, and the same for `<` and `>`. Decoding only `&amp;` and leaving `&lt;` alone
looked like a way to keep both properties, and is not: `&LT;script&GT;` survives the
purifier as entity text, the `&amp;` decode turns it into `&LT;script&GT;`, and a
browser decodes `&LT;` to `<` in a raw-rendered column. That was tested, not reasoned
about.

So the fix is the escape hatch the remediation column named — per column rather than
globally. `GetParsedAndFilteredRequestBody` now takes the entity name, and
`HTML_RENDERED_COLUMNS` lists the five columns whose stored value is rendered as HTML:
`products`, `recipes`, `equipment`, `chores` and `shopping_lists`, all `description`.
Those keep the purifier's output exactly as it came out — the S1 chain is closed for
precisely the columns that are rendered raw. Every other column is text: still purified,
then un-escaped as before, so nothing about how `&` displays changes.

**What is still open, and the claim that overreached.** An earlier draft of this section
said the S1 chain was "closed for precisely the columns that are rendered raw". That is
false, and the enumeration behind it was incomplete: it found `{!! !!}` in Blade and
`.html($x->description)` in viewjs, and missed markup built by *string concatenation* and
handed to `.html()`. `public/viewjs/mealplan.js` does exactly that with three text columns
— `recipes.name` (`:213`), `products.name` (`:286`) and `meal_plan.note` (`:309`), plus the
same names inside `data-recipe-name`/`data-product-name` attributes at `:208`, `:220`,
`:281` and `:293`. Those columns are text, so they are still purified-then-un-escaped, and
the original S1 chain reaches them: a product name of `&lt;img src=x onerror=…&gt;` is
stored as a live tag and fires on opening the meal plan.

This is not a regression — before the hotfix every column was un-escaped, so the sink was
already live — and it is not fixed here, because fixing it means escaping at the sink in
`public/viewjs`, which is [12](plans/12-frontend-shared-core.md)'s territory and which this
hotfix is required not to touch. Making the *storage* safe instead is not available either:
that is precisely the change that stores `M&amp;M's` for every name.

**Then the review overruled the deferral, and a survey found the class is much larger than
the one file.** "File ownership by plan 12 is not a security boundary" is right, so the
`mealplan.js` sinks are fixed here: `products.name` and `meal_plan.note` are escaped before
they are concatenated into markup, `recipes.name` is escaped again where it is read back out
of a `data-` attribute (`.attr()` returns the decoded string, so the escaping applied when
the attribute was written is not in effect), and the consume toast escapes the product name
it interpolates.

Proved rather than asserted, in a browser against a stored payload: without the fix a
meal-plan note of `&lt;img src=x onerror=window.__xss=2&gt;` sets `window.__xss` on page
load; with it, `window.__xss` is unset and the payload renders as text. The product-name
path is the same one-line change at the top of the same function and is verified by reading
it, not in the browser — a meal-plan product entry will not render without a quantity-unit
conversion for the product, which this fixture had no way to produce.

The survey that went with it turned up **S29**: this is a systemic pattern, not three lines.
Roughly 45 sites across ~25 files feed unescaped names into `bootbox` and `toastr`, both of
which render their message as HTML by construction. That is recorded as its own finding
rather than fixed here, because it is ~45 individual judgements about whether a variable is
display-only, in exactly the files [12](plans/12-frontend-shared-core.md) rewrites — and
because a security change that large deserves its own review rather than riding in at the
end of this one.

**S29 is folded into [12](plans/12-frontend-shared-core.md) as its step 3a**, decided
2026-08-30, rather than left as an unowned finding — and **fixed there on 2026-09-02**; the
row below carries the payload evidence. The factories that plan already builds
absorb the ~24 confirmation dialogs structurally — the delete-confirm dialog it exists to
collapse appears 31 times — and the ~20 toast sites, `productamountpicker.js` and two
irregular confirmations are swept by hand in the same step, since no factory reaches them.
12's status changes with it: it was drift cleanup with no security content, and is now the
fix for a High finding, which is the strongest argument for its place in wave 1.

So the accurate claim, finally: **S1's storage-side behaviour is closed, the Blade renders
and the `.html($x->description)` sinks are safe, `mealplan.js` is fixed, and S29 was the
same class in another ~45 places — closed on 2026-09-02 by 12's step 3a, structurally for the
confirmations and by hand for the toasts, and proved with the stored payload.**

Two things follow from that split, and both are part of this change:

- **The text columns that were being rendered raw are now escaped in the view**, since
  they are text and nothing purifies markup out of them any more:
  `shoppinglist.blade.php` (item notes, three places), `recipes.blade.php` (position
  notes) and `components/userfields_tbody.blade.php` (the checklist branch). The two
  genuinely-HTML sites — `stockoverview.blade.php`'s `product_description` and
  `recipes.blade.php`'s `description` — are unchanged and now safe by their column's
  treatment.
- **`chores.description` is treated as an HTML column** although nothing offers a rich
  text editor for it, because `public/viewjs/components/chorecard.js` renders it with
  `.html()`. The alternative — escaping it in that file — belongs to
  [12](plans/12-frontend-shared-core.md), which owns `public/viewjs` and which this
  hotfix is required not to touch.

**S7, in the same edit.** `iframe` and `id` are gone from `HTML.Allowed`, along with
`HTML.SafeIframe`, the `%^.*%` `SafeIframeRegexp` and `Attr.EnableID`. Verified: an
`<iframe>` posted into a description is dropped entirely and `<div id="submit">` comes
back as `<div>`. `data:` stays in `URI.AllowedSchemes` — the editor stores a pasted
image as one, and HTMLPurifier only accepts a data URI that really decodes to a JPEG,
GIF or PNG. Removing it would have broken pasted images to close nothing.

**S2 — the files API.** Permission per group on upload and delete, an extension
allow-list per group, `getimagesize` on anything stored under an image extension, and
serving from a fixed type list with `X-Content-Type-Options: nosniff`. Reads are
deliberately left open to any authenticated user: every picture in the UI and both
tracked clients in [17](plans/17-ecosystem-clients.md) fetch them, and the finding is
about writes and content type, not about who may look.

Two departures:

- **`equipmentmanuals` is gated on `EQUIPMENT`, not `MASTER_DATA_EDIT`.**
  `GenericEntityApiController` gates the equipment rows themselves on `EQUIPMENT`, so
  `MASTER_DATA_EDIT` would have locked the manual out of the hands of exactly the
  account that may edit the record it hangs off. Verified both ways: an
  `EQUIPMENT`-only account uploads a manual and is refused a product picture.
- **PDFs are served inline**, not as an attachment. `equipmentform.blade.php` shows the
  manual in an `<embed>`, and an attachment disposition would have replaced a working
  feature with a download prompt. It is served with an exact `application/pdf` and
  `nosniff`, so it is never treated as a document in this origin. Images are inline
  only when the sniffed type is one of JPEG, PNG, GIF or WebP — SVG is deliberately
  absent and therefore downloads. Everything else is `attachment` with
  `application/octet-stream`.

  `userfiles` takes the document formats a household attaches to a record and none of
  the ones a browser executes: no `svg`, `html`, `xhtml`, `xml` or `js`. A format that
  turns out to be wanted is one line in `GROUP_ALLOWED_EXTENSIONS`.

Three residuals, recorded rather than left for someone to rediscover:

- **`nosniff` is load-bearing, not defence in depth.** A GIF/HTML polyglot passes
  `getimagesize` and sniffs as `image/gif`, so it is served inline — and stays an image
  only because the header stops the browser sniffing on to `text/html`. The allow-list and
  the content check do not cover that case; the header does. It is commented as such in
  `ServeFile` so a future tidy-up does not remove it as redundant.
- **The content check runs after the body is on disk**, so it bounds what can be *served*,
  not what can be *written*. With S10's upload cap deferred to wave 1, an account that may
  upload at all can still force unbounded disk writes; the extension allow-list does not
  help, because the write happens first. **Closed 2026-09-02 by S10's fix in
  [01](plans/01-file-storage.md)**, which caps the write itself; the content check still
  runs afterwards and still only bounds what is served.
- **`userfiles` admits `bmp`, `tif`, `tiff` and `heic`, which are not in
  `IMAGE_EXTENSIONS`**, so they are stored without a content check. Safe under the serving
  rules — they sniff to a type outside `INLINE_SERVED_TYPES` and are therefore downloaded
  rather than rendered — but it is a real gap between "allowed as an image" and "validated
  as an image", and it is only safe for as long as `INLINE_SERVED_TYPES` stays short.

  **`userpictures` deletes are bound to the caller's own picture.** `USERS_EDIT_SELF` is a
  natural grant — it is what lets a household member change their own password — and the
  files route carries no user id, so without a check it would also let them delete every
  other user's picture: S2's mass-unlink reduced rather than removed. `DeleteFile` now
  requires `USERS_EDIT` unless the file being deleted is the caller's own
  `VICTUAL_USER_PICTURE_FILE_NAME`. Uploads need no equivalent — the name is new, so there
  is nothing to take away — which is why the binding is on delete alone.

**S23 rode along**, per the roadmap's rule for S20–S24: the `Content-Disposition`
filename is now RFC 5987 encoded (`filename*=UTF-8''` plus `rawurlencode`), so a quote
in a name cannot end the parameter.

**S3 / 15-B2 — the session cookie.** `HttpOnly` and `SameSite=Lax` always, `Secure`
when the request arrived over HTTPS (honoring `X-Forwarded-Proto`, as `UrlManager`
already does), `path` from `VICTUAL_BASE_PATH`, and an expiry that mirrors the session
row rather than 2106. 15's questions 2 and 3 already held the answers and this
implements them, including Q3's "if that lifetime is currently infinite, give it a
bound": `SessionService::CreateSession` no longer writes `PHP_INT_MAX` for a
stay-logged-in session but `VICTUAL_SESSION_STAY_LOGGED_IN_DAYS`, defaulting to 90.
A login without the box ticked gets a browser-session cookie against the existing
30-day row. Verified by reading the actual `Set-Cookie` for all three cases against
the `sessions` rows.

The call is still `setcookie()` rather than a PSR-7 response header — 15-B2's other
half, left where it was because `ProcessLogin` is a static with no response to write
to, and 15-C1 rewrites that construction anyway.

Two notes on what the cookie change touches:

- **`X-Forwarded-Proto` is trusted with no proxy allowlist**, which is the pattern S4
  rates High. It is Low here because of which way it fails: the header can only make the
  cookie *more* restrictive, so forging it adds `Secure` and costs that browser its own
  session over plain HTTP — a self-inflicted denial of service, not an escalation, and it
  can neither remove a flag nor reveal anything. The comparison is against the first
  entry of the list, matched exactly, rather than a substring test. When S4's
  trusted-proxy allowlist lands in wave 2, this should be bounded by it as well, so both
  header-trust decisions live in one place.
- **The explicit `path` is an upgrade hazard for a subdirectory install.** `setcookie()`
  previously defaulted to the request URI's directory; it is now `VICTUAL_BASE_PATH/`. A
  root install resolves to `/` either way and nothing changes. Under a subdirectory, an
  existing cookie at the old path can coexist with the new one under the same name, and
  S19 — `Logout` deletes the session row without clearing the cookie — means the stale one
  is not cleaned up either. Nothing is deployed today so nothing is affected; whoever
  fixes S19 in wave 2 should clear the old path at the same time.

**S4 — reverse-proxy trust**, pulled forward out of wave 2 in review of the hotfix. The
roadmap deferred it on the grounds that 11 and 15-C1 rewrite these files anyway and fixing
it now means doing the auth refactor twice. That reasoning holds for the *refactor* and not
for the *hole*: file ownership is a scheduling convention, and "High if configured" is only
safe while nobody configures it, which is a fact about today rather than a property of the
code.

`ReverseProxyAuthMiddleware` now refuses the header unless `REMOTE_ADDR` matches
`REVERSE_PROXY_AUTH_TRUSTED_PROXIES`, a comma-separated list of addresses and CIDR ranges
(`IsIpInCidrList`, which compares packed forms so a v4 address is never inside a v6 range).
**An unset list refuses everything** rather than trusting everything — a header-mode
deployment that has not named its proxy is not one whose header means anything — so the
default configuration is now safe rather than dangerous.

Deliberately *not* applied to `REVERSE_PROXY_AUTH_USE_ENV` mode. There the username comes
from `$_SERVER`, which the web server populates and a client header cannot reach, since PHP
exposes request headers as `HTTP_*`. Requiring a proxy list there would also break a correct
setup: Apache doing its own authentication sets `REMOTE_USER` with no proxy in front, so
`REMOTE_ADDR` is the end user. `USE_ENV` remains the mode to prefer, and the config comment
now says the proxy must strip the header inbound.

Verified as a set of three on a booted instance with `AUTH_CLASS` actually set to the
reverse-proxy backend — the first attempt tested nothing, because `Setting()` is
first-write-wins and an appended override never took effect:

| Case | Result |
|---|---|
| No trusted list, forged `REMOTE_USER: eve` | Refused, naming the setting. No user created |
| List contains the caller, `REMOTE_USER: eve` | Authenticated, user created — the legitimate path still works |
| List configured but caller outside it | Refused: "request did not come from a trusted proxy" |

What is *not* closed by this: S5, which is why the finding is dangerous in the first place —
an auto-created reverse-proxy user still gets `DEFAULT_PERMISSIONS`, i.e. ADMIN. That stays
in wave 2 with the rest of the permission work.

**R1 — the feature flags.** `str_starts_with` in both loops, and `substr($constant, 8)`
in the API one, so the UI sees `VICTUAL_FEATURE_FLAG_*` (what `public/viewjs` indexes
by) and `/system/config` answers `FEATURE_FLAG_*` (the shape its other keys use).
Verified: 21 flags in `Victual.FeatureFlags`, 21 in the API response, and the consume
form shows its location field again.

### What the hotfix deliberately did not do

- **S10's upload cap and downscale clamp**, which the roadmap gives to wave 1 track A
  along with the move to database storage. The upload path is open in this change and
  the cap would be three lines; it is left where the roadmap put it rather than widened
  into a hotfix.
- **Document the 403 in `victual.openapi.json`.** The files routes now answer 403 where
  they answered nothing, but no operation in the spec documents a 403 today, including
  the many that have always thrown `PermissionMissingException`. Adding it to these
  three alone would make the spec less consistent, not more; the status-code sweep is
  [11](plans/11-api-error-handling.md)'s and the snapshot is 14 piece 2's.
- **Anything in `public/viewjs` or `middleware/Auth` beyond the cookie line** — wave 1
  track B and wave 2 own those files, and the hotfix's premise is that it collides with
  neither.

### Found while fixing, 2026-08-29

| # | Sev | Finding | Where | Fix |
|---|---|---|---|---|
| S29 | **High** — *fixed*, [12](plans/12-frontend-shared-core.md) step 3a, 2026-09-02; **re-opened and closed again in review, 2026-09-03** — see the amendment under this table | **Every "are you sure" dialog and success toast is an HTML sink fed an unescaped name.** Two libraries render their message as HTML by construction: `bootbox` does `body.find('.bootbox-body').html(options.message)`, and `toastr` ships `escapeHtml: false` as its default and this fork never sets it. The frontend then builds those messages by interpolating a name straight from a text column — `__t('Are you sure you want to delete location "%s"?', objectName)` where `objectName` came from a `data-*-name` attribute, and `toastr.success(__t('…%s…', result.product.name))`. Around 45 sites across ~25 files: roughly 24 delete/action confirmations and 20 success toasts, over `products.name`, `recipes.name`, `locations.name`, `chores.name`, `batteries.name`, `tasks.name`, `quantity_units.name`, `product_groups.name`, `shopping_lists.name`, `users.username`, `api_keys.description` and more. `components/productamountpicker.js` builds `<option>` markup from `quantity_units.name` the same way. **Demonstrated, not inferred**: a product named `&lt;img src=x onerror=…&gt;` is stored as a live tag by the S1 path and executes on view. The existing `.escapeHTML()` convention is one call site in the whole tree (`mealplan.js:169`), and even that is defeated when the value is written into a `data-*` attribute and read back with `.attr()`, which returns the decoded string. | `public/viewjs/*.js`, `public/js/victual_entity.js` | **Fixed in 12 step 3a.** Escaped at each interpolation, never at the sink: `toastr.options.escapeHtml` stays off, because ten of these messages carry deliberate markup including the consume Undo button, which the flag would render as visible tag text. Structurally for the ~24 confirmations - `Victual.EntityList.ConfirmDelete` in the new `public/js/victual_entity.js` takes the entity name as *data* and escapes it on the way into the message, so no caller can pass markup through it and the next list page added is safe by construction. By hand for the rest: the ~20 toast sites in `consume`, `purchase`, `transfer`, `inventory`, `stockoverview`, `stockentries`, `choresoverview`, `choretracking`, `batteriesoverview`, `batterytracking`, `tasks`, `recipes`, `mealplan` and `shoppinglistitemform`; `components/productamountpicker.js`'s `<option>` builder; and the irregular confirmations in `manageapikeys`, `shoppinglist`, `components/productpicker`, `recipeform` and `calendar`. `Victual.FrontendHelpers.EscapeHtml` is the new null-tolerant function form of the tree's `String.prototype.escapeHTML`. Every site escapes at the point of use, so a value that round-trips through a `data-` attribute is escaped where it is read back, not where it was written. **Proved with the payload, not by reading the diff**: `.devtools/frontend/s29-payload.js` seeds a location, chore, quantity unit, shopping list, product, task, battery, equipment item, task category, product group, shopping location and API key named `&lt;img src=x onerror=window.__xss=1&gt;`, confirms from the API that the sanitiser stored a live tag, then opens the delete confirmation or triggers the success toast on each page. 16 of 16 probes set `window.__xss` on the unfixed head and 0 of 16 do after, with the payload rendering as visible text and no `<img>` in the dialog or the toast. |
| S28 | **Med** — *fixed* | **`javascript:` URIs in userfield links.** `components/userfields_tbody.blade.php` renders `<a href="{{ $userfieldObject->value }}">` for `USERFIELD_TYPE_LINK` and the decoded `$link` for `LINK_WITH_TITLE`. Blade's `{{ }}` escapes the *attribute* and does nothing about the *scheme*, and a userfield value is a text column — no markup for HTMLPurifier to act on, and `URI.AllowedSchemes` only governs hrefs inside purified HTML, never a bare column value a view drops into an `href`. So any `MASTER_DATA_EDIT` account can store `javascript:…` and it runs in this origin on click. Missed by the original sweep because its output-escaping pass looked for unescaped rendering, and this value is escaped; the category it lacked is URL-scheme sinks. Raised in review of the hotfix. | `views/components/userfields_tbody.blade.php`, `helpers/extensions.php` | Fixed in the hotfix: `SafeExternalUrl()` allows relative URLs plus `http`, `https` and `mailto`, and answers `#` otherwise. The probe strips whitespace and control characters before reading the scheme, because browsers ignore those inside one. The value is still shown as the link text, so nothing is hidden — it just does not navigate. |
| S27 | **Low** — *fixed* | **The permissions API stores `permission_id` unvalidated, and a wrong value fails silently.** `SetPermissions` (`PUT`) and `AddPermission` (`POST`) write the body's `permission_id` into `user_permissions` verbatim — no check that it exists in `permission_hierarchy`, and the column takes a string. `PUT …/permissions` with `{"permissions":["STOCK"]}` answers 204 and writes a row that grants nothing, because `uihelper_user_permissions` joins on the numeric id. The failure is closed rather than open — the user ends up with *fewer* permissions, not more — but an administrator is told the grant succeeded when it did not, which is the same class of silent authorization failure as R1. Found while building a low-privilege account to verify S2's permission gate. | `controllers/Api/UsersApiController.php::SetPermissions`, `::AddPermission` | Validate each id against `permission_hierarchy` and answer 400 otherwise. Body-schema validation is 14 piece 2's (S16); this one is small enough to ride with 15-C1's user-permission work in wave 2. **Done 2026-09-04 in wave 2**, as remediated, and in `User::CheckMayGrant()` rather than at the two call sites — both endpoints want the existence question and S5's subset question asked together, and neither wants them asked in two places. |

#### Amendment, 2026-09-03 — S29 was not fully closed on 2026-09-02

Recorded rather than folded into the row above, because the reason a sweep missed something
is what a later reader most needs. Review of 12's landing pull request found:

- **One sink the by-hand sweep missed.** `recipeform.js` passed a stored recipe-ingredient
  note, read out of `data-recipe-pos-note` with `.attr()`, straight to `bootbox.alert()`.
  `recipes_pos.note` is a text column, so it is stored as a live tag by the same S1 path as
  every name in the row above. The sweep edited the two neighbouring handlers in that same
  file and stopped at this one: **it was looking for a name, and this sink carries a note.**
- **Two more of the same class, found auditing for it**, neither of them from a `data-`
  attribute: `ShowGenericError`'s "Error details" dialog in `victual.js`, and the "Unable to
  print" body in `shoppinglist.js`. Both interpolate a *server error message* into an HTML
  sink — which is reachable with stored data on this fork specifically, because a uniqueness
  violation on PostgreSQL quotes the offending value back into the message it returns.
- **The evidence could not fail.** `s29-payload.js` recorded its assertions and evaluated
  none of them; it exited 0 whatever happened, including a run in which every action errored.

All three sinks are escaped at the point of use, the probe now derives a verdict and exits
non-zero — treating "the sink was never reached" and "the action threw" as failures rather
than skips — and it runs on every pull request as the `frontend-security` job rather than
once by hand. That last clause was written on 2026-09-03 and was not true until 2026-09-04:
the job was named in four documents, this one included, before anyone wrote it, and
[plan 21](plans/21-frontend-sink-discipline.md) is what added it and what made the claim
checkable. The `shoppinglist.js` sink is fixed but **not** probed: it is behind
`VICTUAL_FEATURE_FLAG_THERMAL_PRINTER`, which a demo instance does not set. Details in
[12's second-pass record](plans/12-frontend-shared-core.md#executed--s29-second-pass).

### Regression found on the way

| # | Finding | Where | Fix |
|---|---|---|---|
| R1 | *fixed* — **Every feature flag is dropped from the UI and from `GET /api/system/config`.** Both loops test `substr($constant, 0, 19) === 'VICTUAL_FEATURE_FLAG_'`. The prefix is 21 characters (`GROCY_FEATURE_FLAG_` was 19), so the comparison never matches. `BaseController` therefore sets `featureFlags` to `[]`, `Victual.FeatureFlags` is empty in the layout, and all 64 `Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_*` checks in `public/viewjs` evaluate false — location tracking, price tracking, recipe consume, chore assignments, camera scanning and the rest are silently off in the browser while the PHP-side menus still show them. The API loop additionally uses `substr($constant, 6)` where the prefix is now 8, so it would answer `_FEATURE_FLAG_*` keys once the length is fixed. Introduced by plan 16 (`4fffaf4`/`be8f6b0` era) and not caught by 16's verification, which is worth recording in 16's Executed section. The Home Assistant integration reads `/system/config` feature flags, so this is also a 17 coupling. | `controllers/BaseController.php` (feature-flag loop), `controllers/Api/SystemApiController.php::GetConfig` | Use `str_starts_with($constant, 'VICTUAL_FEATURE_FLAG_')` and `substr($constant, 8)`; add a contract test in 14 piece 2 that `/system/config` returns at least `FEATURE_FLAG_STOCK`. |

## Checked and found sound

Recorded so the findings read in proportion.

- **Password hashing** is Argon2id via `password_hash` on create and edit, with `password_needs_rehash` upgrade on login.
- **Session and API-key entropy**: `RandomString(50)` over a 62-character alphabet using `random_int` (≈297 bits); keys are server-side rows, regenerated at each login, so no fixation. Lookup is a parameterised equality, not `==` on input.
- **LDAP filter injection** is closed (`ldap_escape`, defect 8); empty password rejected before bind; exactly-one-result check.
- **Filter, `query[]` and `order` parsing** validates field names against the live column catalogue (`ColumnTypeManifest`, PR #23), operators are a fixed set, values are always bound, `limit`/`offset` are `intval`'d, direction is `asc|desc`.
- **Entity names from the URL** are gated by `IsValidExposedEntity` plus the `NoListing`/`NoEdit`/`NoDelete` enums on every verb; `api_keys` delete is ownership-checked; `users`, `user_permissions`, `sessions` are not exposed; `GET /api/users` reads `users_dto` (no hashes).
- **Path traversal** in the files API: `group` is allow-listed against the OpenAPI `FileGroups` enum and `IsValidFileName` rejects `/` and `\` after base64 decode; uploads open with `xb` so nothing is overwritten. (The gap is permissions and content type — S2 — not paths.)
- **Webhooks** target only the `VICTUAL_LABEL_PRINTER_WEBHOOK` constant; **printing** uses constants for host/port; **plugin loading** takes the class name from config, not the request. No user-configurable outbound URL exists, so no SSRF beyond S14.
- **No** `unserialize`, `eval`, shell execution, `extract`, variable `include`, `preg_replace /e` or `header()` with user input outside `vendor/` and `.devtools/`.
- **`GET /api/system/config`** is an allow-list (`EXPOSED_SETTINGS`); DB and LDAP secrets are not in it.
- **`data/`** is outside the docroot, `Deny from all` in its `.htaccess`, and contains nothing committed. Git history holds no `config.php`, `.db`, `.env` or key material.
- **JSON-in-`<script>`** blocks (`{!! json_encode(...) !!}`) are safe: PHP's default slash escaping turns `</script>` into `<\/script>`.
- **Dependencies** (composer.lock / yarn.lock): Slim 4.15.2, slim/psr7 1.8.0, Guzzle 7.15.2, HTMLPurifier 4.19.0, moment 2.30.1, chart.js 2.9.4, jQuery 3.7.1 — no known-vulnerable pins. Two to watch: Bootstrap 4.6.2 is EOL and carries CVE-2024-6531 (carousel `data-slide` XSS; low reachability here, no 4.x fix exists), and yarn.lock resolves jQuery to both 3.7.1 and 4.0.0 — dedupe before 12 rewrites the frontend core. Parsedown 1.8.0 renders only the repo changelog.

## Where this lands in the roadmap

The README's rule is that waves are strictly ordered and tracks inside a wave touch
disjoint files. S1–S3 touch `BaseApiController::GetParsedAndFilteredRequestBody`,
`FilesApiController`, and one line of `BaseAuthMiddleware` — files that wave 1's three
tracks (10/01, 12, 13) do not touch, and that wave 2's 11 and 15-C1 will later rewrite
around trivially. They should land as a single hotfix PR **before wave 1 starts**, with
a booted-instance verification (upload an SVG, confirm it downloads rather than renders;
`Set-Cookie` inspected; a stored `&lt;script&gt;` round-trips as text). R1 rides in the
same PR because it is two lines and its verification (open the consume form with
location tracking on) is the same booted instance.


**The permission-model findings were parked on an RBAC plan; four of the five are now
unparked.** That plan landed as [19](plans/19-rbac.md) on 2026-08-30, and reading it
against the code reversed the parking rather than confirming it. 19's own Depends-on line —
in its first draft, before any review edits — puts it *after* wave 2's S5/S6, so parking S5
and S6 on 19 inverted the dependency the plan itself states. The distinction the parking missed is between the *rule* and the *model*: the
subset-of-caller rule needs a caller's resolved set, a target's resolved set and the
closure of a proposed grant, all of which are `user_permissions_resolved` and
`permission_tree` as they stand today. 19 widens that view with a union over
`role_permissions`; a comparison written against it in wave 2 keeps working verbatim.

- **S5** (`DEFAULT_PERMISSIONS = ['ADMIN']`) — **wave 2.** The value is not a model
  question: `[]` is the premise 19 is written on, whose `VICTUAL_DEFAULT_ROLES` also
  defaults to empty. Two of the three `CreateUser` call sites have no creator to compare
  against — `ReverseProxyAuthMiddleware.php:79` and `LdapAuthMiddleware.php:108`, the
  latter deleted by 15-B1 — so for those the default *is* the whole fix, and only
  `POST /api/users` gets the subset rule.
- **S6** (`USERS_EDIT` can reset an admin's password) — **wave 2**, and worse than recorded
  above. "May A administer B" does have an answer today — B's resolved permissions are a
  subset of A's — computable from the existing view. And the escalation does not need
  `USERS_EDIT` at all: `USERS` → `USERS_CREATE` → `USERS_EDIT` → `USERS_READ` is a chain
  (`migrations/0110.sql:29-43`) and the tree resolves downward, so `USERS_CREATE` alone
  already resolves to `USERS_EDIT`. An account that may create users may rewrite any
  admin's password today.
- **S27** (unvalidated `permission_id`, silently granting nothing) — **wave 2.** One
  existence check against `permission_hierarchy`. 19 adds rows to that table; it does not
  change what validating one means. **Done 2026-09-04**, in `User::CheckMayGrant()`
  alongside the subset check, because both endpoints that take a `permission_id` want both
  questions asked and neither wants them asked in two places. An id naming no permission is
  a 400 rather than a stored row that grants nothing.
- **The `userpictures` residual** — **wave 2**, with S6. It is a route gap rather than a
  model gap: the route carries no user id, but `users.picture_file_name` recovers the
  owner, so the check becomes owner-is-caller → `USERS_EDIT_SELF`, else `USERS_EDIT`. The
  filename comparison is standing in for a lookup, not for a model. Whether S6's subset
  rule also applies to a picture — may an editor delete the avatar of a user holding
  permissions they lack — is a separate and smaller question, worth deciding rather than
  assuming in either direction.

  **Done 2026-09-04, and the smaller question is answered yes.** `CheckUserPictureDeletion()`
  looks the owner up by `picture_file_name` and applies `User::MayAdminister()` on top of
  `USERS_EDIT`. Deleting the avatar of someone whose permissions you do not hold is the
  same act of administering them as editing their account, and answering no would have made
  this the one place the rule does not reach. A picture no user row claims is orphaned and
  needs `USERS_EDIT` alone — there is no owner to compare against.
- **The permissions page's `ADMIN`-versus-`USERS_READ` mismatch**, recorded in
  [14](plans/14-contract-and-regression-scaffolding.md)'s section 2b, is the one that
  genuinely belongs to 19, which carries it as its question 9. Wave 2 can still take the
  answer from the plan rather than wait for it.

  **Wave 2 took the read half and left the write half, 2026-09-04.**
  `GET /api/users/{id}/permissions` moved from `ADMIN` to `USERS_READ`, which is what the
  server-rendered page has always required — the two halves of one screen disagreed about
  who may look at it, and the strict half was the one nothing rendered from. The write
  endpoints stay on `ADMIN`: loosening a *grant* path to `USERS_EDIT` is a decision about
  the permission model rather than about a read, 19 has not recorded it, and the lesson
  above about parking findings on that plan cuts both ways.

S4 was never on that list: it was about trusting a header, not about permissions, and it
landed in the hotfix. S5 remained the reason it mattered — an auto-created reverse-proxy
user still got ADMIN — until wave 2 landed S5 on 2026-09-04. The residual the hotfix
knowingly accepted is now closed at both ends.

S4–S6 (reverse proxy, default permissions, user edit) belong with 11/15-C1 in wave 2,
where the auth files are open anyway — but 15-B2 (S3) should not wait for them.

**S30 | Info — no permission gates a read of household data.** Surfaced while reviewing
[19](plans/19-rbac.md) against the code, and recorded here because it is the sweep's shape
rather than that plan's. `PERMISSION_STOCK` is declared
(`controllers/Users/User.php:30`) and checked nowhere; every read method on
`StockApiController` and both `GenericEntityApiController::GetObject` and `::GetObjects`
run with no `CheckPermission`, and the API route group adds only CORS and JSON middleware.
The users surface is the sole exception — `UsersController.php:23,93` and
`UsersApiController::GetUsers` require `USERS_READ`, `::ListPermissions` requires `ADMIN`.
So every authenticated user reads all stock, all prices, all recipes, all chores and the
whole permission tree regardless of what they hold. Rated Info rather than a finding
because on a single-household instance every account is trusted and the permission model
is about restraint rather than defence — but it is the premise 19's Child role and its
`RECIPES_VIEW` leaf were drafted against, and it is why 19 carries a question 8 asking
whether object-level read gating is in scope at all. Nothing in the sweep's original
output would have caught it: the pass looked for gates that could be bypassed, not for
gates that were never there.

**S31 | Info — `FEATURE_FLAG_STOCK_PRICE_TRACKING` is not a permission and never was.**
Surfaced the same way S30 was, reviewing [19](plans/19-rbac.md) against the code, and it is
the reason that plan is two plans wearing one number. It is
declared in `config-dist.php` and read only in the presentation layer — 14 Blade views and
6 `public/viewjs` scripts, where it adds `d-none` to price columns and hides price inputs.
Nothing under `services/` or `controllers/` consults it, so no API path does. Combined with
S30 — where no read is gated at all — that means *every authenticated user* can read
`stock.price`, `products_average_price`, `product_price_history` and a recipe's `costs`
straight from `/objects/…` and `/stock/products/{id}` with the flag off and the columns
hidden. That is not a defect against any stated policy, because the fork has never stated
one; it is recorded here so that "the price columns are hidden" is never mistaken for
"prices are protected".

**S32 | Low — a new file group is readable by every authenticated account, and nothing makes
it declare otherwise.** Filed 2026-09-07 while
[ADR-0021](adr/0021-label-templates-are-application-data.md) was deciding where print
artifacts live, and recorded as its own finding because it is a property of the files API
rather than of that record. `FilesApiController::ServeFile` gates reads with a hardcoded
chain — `productpictures` requires `STOCK_VIEW`, `recipepictures` requires `RECIPES_VIEW` —
and every other group falls through to authentication alone. For the five groups that exist
that is the posture S2's fix chose deliberately and said so: "Reads are deliberately left open
to any authenticated user … the finding is about writes and content type, not about who may
look." The finding here is what happens *next*: the default for a group nobody thought about
is open, the two gates that do exist were added one at a time as
[19](plans/19-rbac.md) landed its domain reads, and there is no structure that fails a group
whose read gate was never decided. `EntityReadPolicy::PERMISSIONS` is the same question asked
the other way round and it throws for an entity absent from it — the files API is the one
place in the tree where forgetting is silently permissive.

Rated Low rather than Info because, unlike [S30](#findings), it is not a stated posture but a
gap in how a stated posture is maintained; and rated no higher because every group that exists
today is either deliberately open or gated. Its cost is entirely in what gets added: print
artifacts carry captured household data, which is why
[27](plans/27-label-templates-and-rendering.md) stores them under group names deliberately
absent from the OpenAPI `FileGroups` enum, so `ServeFile`'s existing allow-list refuses them
and dedicated authorized endpoints are the only path. That works, and it works by one plan
remembering.

Remediation: give the files API a group-to-read-permission table with the same fail-closed
shape as `EntityReadPolicy::PERMISSIONS`, listing every group — including the four whose answer
is "open to any authenticated user", so that answer is written down rather than implied by an
absent branch — and refusing a group that is not in it. Owner: [27](plans/27-label-templates-and-rendering.md),
which is the first plan to add a group and the first that must not get this wrong.

Two plans should absorb items rather than a hotfix: **14 piece 2** takes the
`/system/config` contract test (R1), the body-schema validation that closes S16, and a
filter-contract line for S15; **02** must not inherit the query-string key path (S11)
and should treat S4's trusted-proxy pattern as the model for its own bearer seam. **09**
inherits S14 before adding lookup sources. **15**'s non-breaking table gains `update.sh`
(S13), as row C11. **S25 is [10](plans/10-cold-start-statelessness.md)'s, not 15's** —
this paragraph used to send it here, the roadmap sent it to 10, and for a while neither
plan carried it. It belongs where the production image is first published, because that is
the commit in which it stops being an Info finding about a dev container; 10 now has the
section, and it landed there on 2026-09-02 in the same commit as the production
image, which is what the argument above said it would take.

## What the original sweep did not do

*This section describes the 2026-08-29 static pass, not the hotfix that followed it — the
hotfix's own verification was a booted instance, twice, and is recorded above.*

It did not boot an instance, so none of the XSS chains were demonstrated end to end —
S1 and S2 are read from the code, and the verification above is what would confirm
them. It did not read the two external clients. It did not review the MCP interface spec
against the protocol text (the rigor review's open item). It did not run a dependency
scanner; the version notes above are from reading the lock files.
