# 15. Deliberate cleanup batch

**Goal:** Clear the accumulated small debt in one deliberate pass, and put everything that
breaks compatibility onto one explicit, batched list instead of leaking it into feature
plans one item at a time.
**Depends on:** [11](11-api-error-handling.md) for the auth middleware ordering (do the
ordering fix there, the refactor here);
[14](14-contract-and-regression-scaffolding.md) for anything verified by result-set diff.
**Status:** Landed 2026-09-17, [issue 132](https://github.com/datagen24/victual/issues/132).
Deliberately a grab bag — see "Why one plan" below. 15-B2 (session cookie) landed early,
in the wave 0.5 hotfix; see its Executed note. B3 declined (Q5), B4 not applicable (Q4
resolved the PHP floor downward). Two items shipped as documented partial residuals
rather than complete — see C8 and C10's own Executed notes below.

> **Read [19](19-rbac.md) before starting C1** — as a consistency check, not as a blocker.
> It landed as a plan on 2026-08-30, and the sweep's permission findings that were parked
> against it — S5, S6, S27 and the `userpictures` residual — came back to wave 2 once it
> was read against the code: each needs the subset-of-caller *rule*, which
> `user_permissions_resolved` and `permission_tree` already support, rather than the
> *model* 19 defines. So C1's authenticator extraction is the place those fixes land, and
> what 19 is owed is that the rule C1 writes still holds when a grant can arrive through a
> role — 19 widens that view with a union and changes nothing a comparison against it
> would see. The roadmap's own rule about reading 17 before 11 and 16 exists because that
> was not done; this is the same rule, applied on time, and applied in the direction 19's
> own Depends-on line states rather than against it.

## Why one plan

Every item here is individually too small to justify a plan and individually easy to
defer forever. They also share a property that makes batching them the right call: about
half of them are **breaking**, and breaking changes want to happen together, once, with a
changelog entry, rather than dribbling out attached to unrelated features.

The review response to [05](05-store-shopping-lists.md) Q4 already established this for
the `shopping_locations` → `stores` rename: park it on an explicit "breaking changes,
batched" list for the fork rather than in any feature plan. This is that list.

## Today

Grouped by whether they change behaviour anyone can observe.

### Non-breaking — pure cleanup

| # | Item | Where |
|---|---|---|
| C1 | **Done (wave 2)** — auth middlewares instantiate *other* middlewares and call `AuthenticateRequest` cross-instance; visibility drifts (protected in `DefaultAuthMiddleware`, public in three siblings); `ProcessLogin` is an abstract static that three of five subclasses stub with `throw` | `middleware/Auth/` |
| C2 | **Done, 2026-09-17** — `StockReportsController` embeds three hand-written multi-join `SELECT`s — the only controller with raw SQL, and the only place outside services where the dialect boundary could be crossed again | `controllers/StockReportsController.php:71,90,107` |
| C3 | **Found already done, 2026-09-17** — About dialog reports `sqlite_version` from a throwaway `sqlite::memory:` connection even on a PostgreSQL install | `services/ApplicationService.php:84,114` |
| C4 | **Done (wave 2)** — `ExceptionController` has an unreachable duplicate `if (!defined('VICTUAL_AUTHENTICATED'))` inside its 404 branch, and trusts `$exception->getCode()` as an HTTP status with no clamping | `controllers/ExceptionController.php` |
| C5 | **Done, 2026-09-17** — Unused `EquipmentController::$UserfieldsService` | `controllers/EquipmentController.php` |
| C6 | **Done, 2026-09-17** — `config-dist.php` documents locale precedence as browser → user setting → default; `LocaleMiddleware::GetLocale` actually does user setting → browser → default | `config-dist.php:40-43` vs `middleware/LocaleMiddleware.php:41,52` |
| C7 | **Done, 2026-09-17** — `composer.json` requires PHP `8.5.*` and `PrerequisiteChecker::REQUIRED_PHP_VERSION` is `8.5.0`, while the real language floor in the code is 8.4 | `composer.json`, `helpers/PrerequisiteChecker.php:19` |
| C8 | **Mostly done, 2026-09-17** — Request data read three ways: PSR-7 `getQueryParams()` (most controllers), slim/http `getQueryParam()` (`GrocycodeTrait`, `ApiKeyAuthMiddleware`), raw superglobals (`BaseController:84` `$_GET['embedded']`, `ReverseProxyAuthMiddleware:47,53` `$_SERVER`, `ApplicationService:94`, `UrlManager:58-63`) | across |
| C9 | **Done, 2026-09-17** — five `new Service()` sites against the otherwise universal `GetInstance()` convention (~320 sites): three in `DemoDataGeneratorService` (`StockService`, `ChoresService`, `BatteriesService`), one in `SqliteDialect` (`UsersService`), and `middleware/Auth/ApiKeyAuthMiddleware.php:46` (`ApiKeyService`, done with C1) | services |
| C10 | **Bookkeeping half done, 2026-09-17** — `UndoBooking`'s switch repeats the same undo-bookkeeping block seven times; `StockService` returns LessQL rows from most methods and plain `stdClass` from the raw-SQL ones, so callers must know which they got | `services/StockService.php` |
| C11 | **Done, 2026-09-17** — Delete `update.sh` — it runs `rm -rf !(data|update.sh)` and then unpacks an unsigned `releases.grocy.info/latest` zip over the result, which is upstream Grocy and would destroy this fork's schema. `.devtools/create_release_package.bat` goes with it: this fork cuts no releases. Sweep S13, rigor review H3 | `update.sh`, `.devtools/create_release_package.bat` |
| C12 | **Done, 2026-09-17** — `DatabaseService::InTransaction`'s docblock points at "`DatabaseDialect` for the per-engine locking used around migrations"; no such method exists there and none is planned before [10](10-cold-start-statelessness.md) builds one. Reword to name 10, or make the `@see` resolve. Rigor review A4 | `services/DatabaseService.php` |
| C13 | **Done, 2026-09-17** — `.gitignore`'s `/.phpdoc` is anchored to the repository root, so a phpDocumentor run in a subdirectory leaves untracked output — `branding/.phpdoc/` is the live case. Unanchor it to `.phpdoc/`. Rigor review H1 | `.gitignore` |
| C14 | **Done, 2026-09-17** — CI lints PHP with `php -l` and never runs the `node --check` sweep over `public/**/*.js` that [14](14-contract-and-regression-scaffolding.md) piece 3 specifies. Add it, or amend 14 — but not neither, which is where it has sat. Rigor review A9 | `.github/workflows/tests.yml` |

C11 through C14 arrived from the two 2026-08-29 reviews rather than from the original
architecture review, and C11 is here for a reason worth recording: both the roadmap and
the security sweep already stated it *was* here. Neither had checked. Adding the row is
the fix; the reason it went missing is that a sentence saying where an item lives is
itself an unverified claim, which is the rigor review's H3 in one line.

C4's status clamping is worth a sentence: `ExceptionController` sets
`$status = $exception->getCode()` for any `HttpException`. Slim's own exceptions carry
sane codes, but `getCode()` on an arbitrary exception is an application error code, not an
HTTP status — and `GetParsedAndFilteredRequestBody` already throws a raw
`HttpException(…, 400)`, so the door is open. `withStatus(0)` throws.

### Breaking — needs the batched list

| # | Item | Breaks |
|---|---|---|
| B1 | **Done (wave 2)** — remove the LDAP auth backend (`LdapAuthMiddleware`, six `LDAP_*` settings) | Anyone using `AUTH_CLASS=…LdapAuthMiddleware` |
| B2 | Session cookie hardening: `HttpOnly`, `SameSite`, expiry | Plain-HTTP access if `Secure` is set; embedded/iframe use if `SameSite=Strict`; "stays logged in forever" if expiry is added |
| B3 | `shopping_locations` → `stores` rename (parked from [05](05-store-shopping-lists.md) Q4) | An `ExposedEntity` name, a table, ~250 references across 63 files, the iOS app and the Home Assistant integration |
| B4 | PHP version floor, if C7 resolves upward rather than downward | Anyone on 8.4 |

**B1's context.** Defect 8 fixed an LDAP filter injection in this middleware — by
inspection only, because no `ldap` extension was available in the fixing environment. That
is the argument for removal rather than maintenance: a security-relevant code path that
cannot be exercised is a liability, and `ReverseProxyAuthMiddleware` plus an OAuth proxy
at the ingress is both the deployment-appropriate answer and the direction the IdP
future-state note in [02](02-mcp-endpoint.md) records.

**B2's context.** The cookie is set by a bare `setcookie()` outside PSR-7
(`BaseAuthMiddleware::SetSessionCookie`), with `PHP_INT_MAX` as the expiry and no flags at
all. Defect 5 fixed how the session key is *generated*; how it is *transported* was left.

**B3's context.** It is on this list rather than in 05 because doing it inside a feature
plan means a feature ships with a 250-reference rename attached to it. Whether it is worth
doing at all is Q5.

## Proposed change

### C1 — extract authenticators

Plain classes with one job:

```
SessionAuthenticator::Authenticate(Request): ?object
ApiKeyAuthenticator::Authenticate(Request): ?object
ReverseProxyAuthenticator::Authenticate(Request): ?object
```

`BaseAuthMiddleware` keeps the flow it already has (public routes, auth-less modes, the
401/redirect branch) and composes authenticators instead of instantiating sibling
middlewares. `ProcessLogin` moves to whichever authenticator can actually process a login,
so the three `throw`-stubs disappear rather than being documented.

This is the "do it opportunistically when something touches auth" item from the review.
[02 MCP](02-mcp-endpoint.md) is the plan most likely to touch auth (a new API key type,
possibly a new authenticator); if 02 starts before this lands, do this first.

> **Executed, 2026-09-04, in wave 2.** The three named symptoms are gone and so is the
> class of bug the first of them caused.
>
> - **`SessionAuthenticator`, `ApiKeyAuthenticator` and `ReverseProxyAuthenticator`** are
>   plain objects with one method, composed by `BaseAuthMiddleware`'s subclasses.
>   `SessionAuthMiddleware` and `ApiKeyAuthMiddleware` are deleted: they were never
>   selectable as an `AUTH_CLASS`, only ever constructed by another middleware and called
>   into directly, which is the shape this item exists to remove.
> - **That shape was not merely untidy, and the proof is sweep S17.**
>   `ApiKeyAuthMiddleware` read `$this->RouteName`, which only
>   `BaseAuthMiddleware::__invoke()` sets — and a cross-constructed instance is never
>   invoked, so the field was null and the iCal sharing link's `secret` branch was
>   unreachable. Every calendar sharing URL the application generated answered 401. An
>   authenticator that asks the request its own questions cannot have that bug, and the
>   fix is one line in the new class rather than a field somebody has to remember to set.
> - **`ProcessLogin` is no longer an abstract static with three `throw` stubs.** The
>   password check moved to `PasswordLogin`, which is what `DefaultAuthMiddleware`
>   delegates to; `BaseAuthMiddleware` supplies a default that answers false, so a login
>   form posted on a reverse-proxy installation is "invalid credentials" rather than a 500.
> - **Visibility drift is gone with the classes it was spread across**, and **C9's
>   `ApiKeyAuthMiddleware.php:46` site went with it** — `ApiKeyService::GetInstance()`,
>   as C9 asks, done here rather than separately exactly as C9 says to.
> - **`SessionCookie`** carries the cookie setting and clearing that used to be a protected
>   static on the middleware. That is what let sweep S19's logout half be fixed at all:
>   `LoginController` had no way to reach it.
>
> Verified on a booted instance in production mode: session login, API key in the header,
> the sharing link (200 where it was 401), logout clearing the cookie and invalidating the
> session, and an `AUTH_CLASS` naming a class that no longer exists refused at startup with
> a message that names the LDAP removal.

### C2 — `StockReportsService`

Move the three `SELECT`s out of the controller into a service, matching every other
controller in the codebase. No SQL change — the defects pass already made these queries
portable by computing date cutoffs in PHP and binding them. This is purely about where the
raw SQL lives, so that "all raw SQL is behind the dialect boundary in services" becomes
true without exception, which matters the next time someone reaches for engine-specific
syntax.

One thing to move unchanged rather than "clean up" on the way past: all three queries use
`COLLATE NOCASE`, which looks engine-specific and is not. It is the deliberate
cross-engine pattern documented as hazard 15 in `db/pgsql/README.md` — PostgreSQL has a
matching `NOCASE` collation defined for exactly this. Rewriting it while relocating the
SQL would break case-insensitive ordering on both engines and would not show up in C2's
result-set diff unless the fixture happens to contain mixed-case names.

> **C2 executed, 2026-09-17.** `StockReportsService::GetSpendings()` holds all three
> `SELECT`s verbatim, `COLLATE NOCASE` included; `StockReportsController::Spendings()`
> now only parses query parameters and renders. Verified against a real PostgreSQL 16.13
> instance seeded with demo data: captured all three group-by modes' full rendered output
> (`group-by=product`, `productgroup`, `store`, with an explicit date range) from the
> pre-move controller, then from the post-move controller against the same database —
> byte-identical in every case (`diff` clean), which is the result-set-diff verification
> this item names.

### C3, C4, C5, C6, C9 — one-liners

C3: report the actual engine's version (`PDO::ATTR_SERVER_VERSION` on the live
connection), and drop the throwaway SQLite connection.
[10](10-cold-start-statelessness.md) removes the same pattern from `PrerequisiteChecker`;
this is the cosmetic twin and should say `postgresql_version` on a PostgreSQL install
rather than a misleading `sqlite_version`.

> **C3 found already done, 2026-09-17.** By the time this item was picked up,
> `ApplicationService::GetDatabaseEngine()` already existed and already did exactly what
> this item asks — `PDO::ATTR_SERVER_VERSION` on the live connection, named
> `database_engine` in `GetSystemInfo()` rather than the `database_version` Q6's answer
> proposed, and already documented in `victual.openapi.json` as the field to read instead
> of the now-deprecated `sqlite_version`. The About page shows "Database Engine" from it
> and no longer shows a SQLite row at all. Neither this plan nor 10's Executed section
> records when or where that landed - a gap worth naming rather than quietly
> re-implementing. `sqlite_version` itself is kept, deprecated, per Q7: the breaking batch
> shrank to B1+B2, and removing a documented response field is not one of those two.
> Verified live: booting on PHP 8.4 against real PostgreSQL 16.13,
> `/api/system/info` answers `"database_engine":"PostgreSQL 16.13"` and a populated
> `sqlite_version` (this box happens to still carry `pdo_sqlite`, which is the case the
> field's own OpenAPI description already accounts for).

C4: delete the dead block; clamp the status to 400–599 with a 500 fallback.

> **C4 executed, 2026-09-04**, in wave 2, because sweep S8 made `/logout` a `POST` and the
> `GET` then rendered "a server error occured" with a 500. Both halves of the item, plus a
> third the item did not name and the first two imply: `HttpStatusOf()` clamps to 400–599
> with a 500 fallback in one place that the API branch, the view branch and the new error
> log all read, the unreachable duplicate `if (!defined('VICTUAL_AUTHENTICATED'))` is gone,
> and a 4xx that is neither 404 nor 403 renders `errors/4xx` with its own status rather
> than the server-error page. Telling a caller the server broke when their request could
> not be handled invites a retry that fails the same way.
C5: delete the property.
C6: fix the comment to match the code — the code's order (explicit user setting beats
browser guess) is the correct behaviour; the comment is what is wrong.
C9: `GetInstance()` at the five sites. Do the `ApiKeyAuthMiddleware.php:46` one as part of
C1's auth refactor rather than separately — that file is already being rewritten there, so
this avoids touching it twice.

> **C5, C6, C9 executed, 2026-09-17.** C5: the property and its docblock are gone from
> `EquipmentController`; the class still reaches `UserfieldsService::GetInstance()`
> directly everywhere it needs it, unaffected. C6: `config-dist.php`'s precedence comment
> now reads user setting, then browser, then default, matching `LocaleMiddleware::GetLocale()`.
> C9: the four remaining `new Service()` sites (`DemoDataGeneratorService`'s
> `StockService`/`ChoresService`/`BatteriesService`, `SqliteDialect`'s `UsersService`) are
> `::GetInstance()`; the fifth, `ApiKeyAuthMiddleware.php:46`, went with C1 as planned.
> Exercised live: booting the demo instance runs `DemoDataGeneratorService` end to end
> (200 on `/stockoverview` after generation), which is every one of the three
> `DemoDataGeneratorService` sites in one pass.

### C7 — pin alignment

Decide the floor once and apply it in both places, plus the README. Q4 — the two settings
currently disagree with reality in the same direction, so this is a decision, not just an
edit.

> **C7 executed, 2026-09-17.** Q4's response (8.4, against the plan's own lean) applied in
> both declared places: `composer.json`'s `require.php` and
> `PrerequisiteChecker::REQUIRED_PHP_VERSION` (was `8.5.*` / `'8.5.0'`). No README states
> a PHP floor, so there was no third place to fix. The image keeps building on 8.5
> (`Dockerfile`, `nix/`), per Q4's "keep shipping 8.5 in the image" — that is the declared
> floor and the serving image agreeing to differ on purpose, not drift.
>
> **Corrected the same day, on review**: the first pass declared `8.4.0` in both places,
> which is a floor `composer install` cannot actually stand on - two locked dependencies,
> `symfony/clock` and `symfony/translation`, require `>=8.4.1`. Both declarations are now
> `8.4.1` (`composer.json`'s `require.php` is `^8.4.1`), which is the real floor rather
> than the nearest round number to it. The PHP running in this environment (8.4.19)
> already satisfied the corrected floor, so the "actually boot on it" verification below
> did not need to be redone.
>
> **Verification item 5 — actually boot on the floor version — was met directly**, not
> simulated: the environment this landed in already runs PHP 8.4.19. `composer install`
> now needs no `--ignore-platform-req=php`, `php bin/victual-migrate` and the full demo
> boot (`php -S` + demo generation + every page hit in this plan's other verifications)
> all ran on genuine 8.4. The CI `suite` job's own `--ignore-platform-req=php` and its
> explaining comment are removed (`.github/workflows/tests.yml`), and the `frontend-security`
> job's comment is corrected to say it deliberately stays on 8.5 to match the serving
> image rather than the (now accurate) declared floor. The `run-app` skill's PHP-8.4
> workaround step - patch `REQUIRED_PHP_VERSION` down, remember to revert it - is deleted
> from both `.claude/skills/run-app/SKILL.md` and `.agents/skills/run-app/SKILL.md`, since
> the patch it performed is now a no-op.

### C8 — request data access

Standardise on PSR-7 `getQueryParams()`. The `$_SERVER` reads are the interesting ones,
not the query-parameter ones: `ReverseProxyAuthMiddleware` reads the auth header from
`$_SERVER` rather than from the request, which is the difference between "the header the
proxy set" and "whatever the SAPI put in the array" — a security-relevant distinction in
the one middleware where it matters most. `UrlManager:58-63` mutates `$_SERVER['HTTPS']`
as a side effect of reading it, which is the sort of thing that is fine until it is not.

`$_GET['embedded']` in `BaseController` is trivial. The `$_SERVER` sites are worth doing
carefully.

> **C8 executed, 2026-09-17, in part.** The `$_SERVER` sites got the careful treatment
> this item asks for, each on its own terms rather than by one mechanical rule:
> - `ApplicationService::GetSystemInfo()` no longer reads `$_SERVER['HTTP_USER_AGENT']`;
>   it takes an optional `?Request $request` and reads `getHeaderLine('User-Agent')`. Its
>   three call sites (`SystemApiController::GetSystemInfo`, `SystemController::About`,
>   `ExceptionController`'s 500 branch) already had a request in scope and now pass it.
> - `UrlManager::GetBaseUrl()` no longer *mutates* `$_SERVER['HTTPS']` to make its own next
>   line see the forwarded scheme — the exact "fine until it is not" pattern this item
>   named. A local `$isHttps` boolean replaces the read-then-write-then-reread. It still
>   reads `$_SERVER` (no `Request` reaches this factory without adding container-level
>   request-capture middleware — see below), but no longer leaves global state changed as
>   a side effect of answering a question.
> - `ReverseProxyAuthMiddleware`'s `$_SERVER` reads turned out to already be the careful,
>   deliberate version this item was worried about missing: C1's refactor (2026-09-04)
>   split header mode (`$request->getHeader()`, real PSR-7) from
>   `REVERSE_PROXY_AUTH_USE_ENV` mode (`$_SERVER` directly, for a value the web server
>   sets rather than a client-supplied header) in `ReverseProxyAuthenticator`, with its own
>   docblock explaining exactly why each is safe. Nothing to do here.
> - `GrocycodeTrait`'s slim/http `getQueryParam()` calls (typed against the PSR-7
>   interface, which does not declare that method - only the concrete request class
>   happens to have it) are now `getQueryParams()['size'] ?? null` /
>   `['download'] ?? false`. `ApiKeyAuthMiddleware`'s `getQueryParam()` site named in this
>   item is also already gone: C1's `ApiKeyAuthenticator::Authenticate()` reads
>   `$request->getQueryParams()['secret']` today.
>
> **Left as documented debt: `$_GET['embedded']` in `BaseController::Render()`.** Not
> because it is hard, but because doing it right costs more than the item is worth.
> `Render()`/`RenderPage()` have no `Request` in scope at all - not the container, not the
> constructor - and every one of the ~103 call sites across 17 controllers would need one
> threaded through for `$embedded` to keep working identically on every page rather than
> silently going false on whichever routes a partial migration missed. The lower-risk
> alternative - a new container-level request-capture middleware, positioned outside
> `BaseAuthMiddleware` since `UrlManager`'s factory is the earliest consumer - was
> considered and set aside for this session: it is real surface added to the request
> pipeline for a flag with no security consequence, not carefully-scoped harm reduction
> like the four sites above. Stays exactly as trivial as this item called it.
>
> Verified live on a real PostgreSQL-backed boot: `/api/system/info` with a custom
> `User-Agent` header round-trips it back in the `client` field (confirming the PSR-7 read
> works end to end); a synthetic 500 driven straight through `ExceptionController::__invoke()`
> renders with the request-derived system info and no error; `/product/{id}/grocycode?size=100&download=1`
> still returns a correctly-sized PNG as an attachment.

### C10 — dedupe the undo bookkeeping; leave the return types

Named here so it is on the record and not rediscovered. The seven-fold repetition in
`UndoBooking` and the mixed return conventions in `StockService` are real, but
[13](13-write-path-transactions.md) is opening exactly those methods to add transactions,
and a transaction change verified by "the ledger is consistent" stops being verifiable if
a deduplication rides along. Accepted debt, revisit after 13.

> **C10 executed, 2026-09-17, in part.** 13 landed (2026-08-29) and
> [issue 121](https://github.com/datagen24/victual/issues/121) - the missing
> `SELF_PRODUCTION` undo branch, in this same function - closed 2026-09-15 carrying its
> own dedication-adjacent fix (the final `else { throw }` that stops an unmatched
> transaction type from passing as a silent no-op). With both riding this function open
> already, the bookkeeping half rode with them: the nine now-identical
> `$logRow->update(['undone' => 1, 'undone_timestamp' => date(...)])` blocks (one more than
> "seven-fold" - two measurement branches were added since this item was written) are one
> `MarkBookingUndone($logRow)` private helper, called from every branch including the
> `else`-adjacent ones that only had the bookkeeping and no other work.
>
> **The mixed-return-types half (LessQL rows vs. `stdClass` from raw-SQL methods) is left
> as its own undertaking**, not folded in here: it touches roughly a dozen methods across
> the read side of `StockService`, each a separate decision about whether to rewrite as a
> LessQL query or keep raw SQL and change what it returns, and none of it shares the
> narrow "one repeated block, one helper" shape that made the bookkeeping dedup safe to do
> in the same sitting. Revisit as its own item rather than silently dropped.
>
> Verified against real PostgreSQL 16.13, not by inspection: a script drove
> `StockService` through `AddProduct`/`UndoBooking` (the `PURCHASE` branch, which
> `SELF_PRODUCTION` shares), `ConsumeProduct`/`UndoBooking` (`CONSUME`), `OpenProduct`/
> `UndoBooking` (`PRODUCT_OPENED`) and `EditStockEntry`/`UndoBooking` (the correlated
> `STOCK_EDIT_NEW`/`STOCK_EDIT_OLD` pair, undone via the newer booking) - four of the nine
> branches, chosen to cover a plain delete, a re-create, a flag clear and a correlated
> pair. Every check read the ledger and `stock` back with raw PDO queries rather than
> through LessQL, whose per-process row cache made an early version of this same script
> report false failures against genuinely-correct writes. 16 checks, 16 passed: booking
> recorded, stock mutated, undo reverses the mutation, `undone`/`undone_timestamp` both
> set. `.devtools/pgsql/rollback-tests.php`'s `UndoTransaction` case (injected-failure
> rollback, a fifth branch by a different route) also still passes unmodified.

### C11–C14 — executed, 2026-09-17

These four arrived from the 2026-08-29 reviews (see the note above the breaking table)
rather than the original architecture review, and none had its own proposed-change
write-up; recorded together here for the same reason.

**C11.** `update.sh` and `.devtools/create_release_package.bat` are deleted. Closes
**sweep S13**. The two places that pointed at this plan for the deletion question -
[16](16-project-rename.md)'s "kept verbatim... a deletion question, which is 15's
business" checklist item and the architecture rigor review's H3 row - are both updated to
say so rather than left describing a file that no longer exists.

**C12.** `DatabaseService::InTransaction`'s docblock no longer says "see `DatabaseDialect`
for the per-engine locking used around migrations" as unresolvable prose - it is now
`@see DatabaseDialect::WithMigrationLock()`, a method that has existed since plan 10
landed. The docblock was stale, not wrong in direction: the pointer target had shipped,
nobody had gone back to make the pointer resolve. Closes rigor review **A4**.

**C13.** `.gitignore`'s `/.phpdoc` is now `.phpdoc` (unanchored), so `branding/.phpdoc/`
is ignored the same as a root-level run's output. Closes rigor review **H1**.

**C14.** The `lint` job's syntax sweep gained a second half:
`find public -name '*.js' -not -path 'public/packages/*' -print0 | xargs -0 -n1 -P4 node --check`,
right after the `php -l` step it mirrors (`public/packages` is yarn-installed and
gitignored, excluded the same way `php -l` excludes `packages/`). All 108 of this fork's
own `.js` files under `public/` passed `node --check` locally before the gate was added,
so the new step starts green rather than needing a fix landed alongside it. Closes rigor
review **A9**, per its own stated choice ("add it, or amend 14 - but not neither"):
this adds it rather than amending 14.

### B1 — LDAP removal

Delete `middleware/Auth/LdapAuthMiddleware.php` and the six `LDAP_*` settings from
`config-dist.php`. Q1 covers whether a stub remains that fails with a pointer to
reverse-proxy auth, versus `AUTH_CLASS` simply not resolving.

Note the interaction with the defects table: item 2's `/api/system/config` allowlist means
the `LDAP_*` settings are no longer exposed, so removing them has no API consequence — a
blocklist would have made this a two-part change.

> **Executed, 2026-09-04, in wave 2.** `middleware/Auth/LdapAuthMiddleware.php` and the
> six `LDAP_*` settings are deleted. Q1's stub-versus-nothing question is answered in a
> third way that costs less than either: there is no stub class, and
> `ConfigurationValidator` refuses an `AUTH_CLASS` that does not exist with a message
> naming the removal and pointing at reverse-proxy authentication. So an installation
> still configured for LDAP is told what happened, at startup, in one line — which is what
> the stub was for — without a class existing whose only job is to fail.
>
> That validation is **sweep S18** as well, and it goes one step further than the finding
> asked: `class_exists` first, then
> `is_subclass_of(..., BaseAuthMiddleware::class)`. The defects table's `/api/system/config`
> allowlist means the removed settings were never exposed, so there is no API consequence,
> exactly as this section predicted.

### B2 — session cookie — **landed in the wave 0.5 hotfix**

`HttpOnly` unconditionally (nothing reads the cookie from JavaScript). `SameSite` and
`Secure` and the expiry each need a decision — Q2, Q3. Move the call inside PSR-7 while
in there, so the response carries the header rather than PHP's output layer emitting it.

> **Executed, 2026-08-29.** Pulled forward by the [security sweep](../security-sweep.md)
> as S3, because it is what turns that sweep's two stored-XSS findings from "script runs"
> into "session stolen". Q2 and Q3's recorded answers are what shipped: `HttpOnly` and
> `SameSite=Lax` always, `Secure` when the request arrived over HTTPS
> (`X-Forwarded-Proto` honored, as `UrlManager` already does), `path` from
> `VICTUAL_BASE_PATH`, and the expiry mirroring the session row — a browser-session
> cookie for a normal login, and the stay-logged-in lifetime when that box was ticked.
>
> Q3's "if that lifetime is currently infinite, give it a bound" is included:
> `SessionService::CreateSession` no longer writes `PHP_INT_MAX` but
> `VICTUAL_SESSION_STAY_LOGGED_IN_DAYS`, a new setting defaulting to 90. That is a
> behaviour change for anyone who ticked the box expecting forever, and the only part of
> this item that is not purely additive.
>
> **What did not land is the PSR-7 half.** `ProcessLogin` is a static with no response
> object to write a header to, so the call is still `setcookie()`. Moving it wants the
> construction C1 rewrites, and belongs there rather than in a hotfix.

### B3 — the rename, if it happens

A dual-engine migration and a large mechanical rename. The migration shape, stated because
the ground rules require it: `ALTER TABLE shopping_locations RENAME TO stores` works on
both engines, but every view referencing it must be recreated, and PostgreSQL's baseline
would need the same treatment — so it is a **per-engine pair**,
`migrations/NNNN.sqlite.sql` and `migrations/NNNN.pgsql.sql`, not a portable file. Adding
a `stores` *view* over the renamed table would keep the old `ExposedEntity` name working
and turn a breaking change into an additive one, at the cost of two names forever. Q5.

### Schema

Only B3, and only if Q5 says yes. Shape stated above: per-engine pair, plus every
dependent view recreated on both sides. Everything else in this plan is code and config.

### API

Split by group.

**The non-breaking items change no response.** C2 moves SQL between files; the three
report queries must return byte-identical result sets. C3 changes the About *page*
(server-rendered, not an API route) and `GetSystemInfo`, which is also surfaced by
`/api/system/info` — so the `sqlite_version` key changing meaning or name **is** an API
change. Additive if a `db_version`-style key is added alongside; breaking if
`sqlite_version` is removed. Q6.

**Client impact, per group.** The non-breaking items are invisible on the wire except
C3, which is in this section for exactly that reason — a cleanup that reaches
`/api/system/info` is not a cleanup, and Q6 decides whether `sqlite_version` gains a
sibling or loses its meaning. The breaking table below *is* the client-impact line for the
rest, and B3 is the largest single client break available anywhere on the roadmap: it
changes response *fields* on `stock`, `stock_log` and `shopping_list`, not just a path,
which is why both 05-Q4 and 15-Q5 declined it and why
[the MCP spec](../mcp-interface-spec.md) now uses `shopping_location_id` rather than
minting a second name for it.

**The breaking items, explicitly:**

| Change | Impact |
|---|---|
| B1 LDAP removal | Config-only. No endpoint, no response. Breaks startup for LDAP deployments |
| B2 cookie flags | No API response changes. `SameSite=Strict` would break any embedded/iframe use; `Secure` breaks plain-HTTP |
| B3 rename | **Largest compatibility break in the fork.** `shopping_locations` is in `ExposedEntity`, so `/api/objects/shopping_locations` is a documented endpoint. Also a column name (`shopping_location_id`) on `stock`, `stock_log`, `shopping_list` and several views — so it changes response *fields*, not just a path |
| B4 PHP floor | Deployment-only |

B3 is the one that would break the iOS app and the Home Assistant integration, which
`db/pgsql/README.md` names as the hard constraint on the whole port
(*"API compatibility is the hard constraint"*). That framing is worth carrying into this
decision rather than treating the rename as a naming preference.

## Verification

Different items need genuinely different verification; a single "it still runs" would be
worthless across a batch this heterogeneous.

1. **C2 — result-set diff, both engines.** Capture all three report queries' full output
   from a populated database before the move, and again after, with the same parameters
   (including the date-range parameters, which are the ones the defects pass changed).
   Byte-identical, or the move changed something. Then load `/stockreports/spendings` in a
   browser on both engines and compare the rendered numbers.
2. **C1 — every auth path, booted.** For each `AUTH_CLASS` value that survives B1: a valid
   login, an invalid login, an authenticated page request, an API request with a valid
   key, an API request with an invalid key, an API request with no credentials, and the
   iCal `?secret=` route. Twenty-odd cases, all of which currently work and none of which
   is covered by any test. Do them before the refactor as a baseline, then after.
3. **B1 — removal is complete and loud.** `grep -ri ldap` over the tree returns nothing
   outside the changelog. A config still setting `AUTH_CLASS` to the removed class must
   fail at startup with a message naming the replacement, not with a class-not-found
   fatal.
4. **B2 — cookie inspected, sessions survive.** Read the actual `Set-Cookie` header and
   confirm the flags. Then: log in, close and reopen the browser, and confirm the
   stay-logged-in behaviour matches whatever Q3 decided. Then confirm login still works
   over plain HTTP if `Secure` was not set, and confirm it correctly *stops* working over
   plain HTTP if it was.
5. **C7 — actually boot on the floor version.** Install the declared minimum PHP and run
   the app, rather than trusting the constraint. This is the check that says whether the
   floor is 8.4 or 8.5 in reality, which is the whole of Q4.
6. **C3/C6/C4/C5/C9 — spot checks.** About dialog on a PostgreSQL install shows a
   PostgreSQL version. `/api/system/info` compared before and after against Q6's answer.
   A user with a locale setting and a conflicting `Accept-Language` header gets the user
   setting. A forced exception with a nonsense `getCode()` produces a 500, not a crash.
7. **B3, if it happens — the full contract sweep.**
   [14](14-contract-and-regression-scaffolding.md)'s response snapshot on both engines
   before and after, plus `difftest.php` over every view that references the table, plus
   `trigdifftest.php`'s full script set. This one is not verifiable by inspection at any
   scale, and should not be started before 14 exists.

## Sequencing

**Last of the hardening plans, but not "eventually".** Two items have earlier triggers:

- **C1 (auth refactor) before [02 MCP](02-mcp-endpoint.md)** if 02 adds an authenticator
  or a key type, which it plans to. Refactoring auth with a new backend already in it is
  strictly harder.
- **C7 (PHP pin) whenever the runtime image is next rebuilt**, which
  [10](10-cold-start-statelessness.md) will do. Cheap to fold in there.

**After [11](11-api-error-handling.md)** for the auth work specifically: 11 moves
`JsonMiddleware`/`CorsMiddleware` to app level relative to the auth middleware, which is a
three-line change in `app.php` and `routes.php`. Doing that ordering fix inside this
plan's refactor would entangle a behaviour change with a structural one. Small fix there,
structural change here.

**After [13](13-write-path-transactions.md)** for C10, which is why C10 is deferred rather
than included.

**After [14](14-contract-and-regression-scaffolding.md)** for B3 and for C2's diffing —
both are verified by comparing result sets, and 14 is what makes that a command rather
than an afternoon.

**Against the feature roadmap: blocks nothing, de-risks 02.** B3 interacts with
[05 store shopping lists](05-store-shopping-lists.md), which is the plan that made the
rename tempting; 05 should ship using the existing name and this list should decide the
rename separately, exactly as the response to 05's Q4 concluded.

## Open questions

1. **LDAP: delete outright, or leave a failing stub?** A stub that throws with "LDAP
   support was removed in this fork; use `ReverseProxyAuthMiddleware` behind an
   authenticating proxy" costs ten lines and turns a class-not-found fatal into an
   explanation. Against that, it keeps a dead file in `middleware/Auth/` forever. I lean
   to the stub for one release, then deletion — but that only works if there is a release
   cadence to hang it on, which for a personal fork there may not be.

   > **Response:** Delete outright — and put the guard in `ConfigurationValidator`,
   > not a stub. "`AUTH_CLASS` does not resolve to a class — valid values are …;
   > LDAP support was removed in this fork" is ten lines that turns *every* future
   > bad auth class into a clear startup failure, forever, with no dead file in
   > `middleware/Auth/`. It also sidesteps the release-cadence problem the stub
   > depends on.
2. **`SameSite`: `Lax` or `Strict`?** `Lax` is the safe default and keeps normal
   navigation working. `Strict` is better and would break any flow where Victual is reached
   from another origin — the iCal feed does not use cookies, but embedded install mode
   (`VICTUAL_IS_EMBEDDED_INSTALL`) might. I lean `Lax` unless embedded mode is confirmed
   unused. `Secure` should be conditional on the request being HTTPS rather than
   unconditional, since local plain-HTTP access is a real use case here.

   > **Response:** `Lax`, `Secure` conditional on HTTPS, `HttpOnly` unconditional —
   > as leant. One data point: embedded install mode is the desktop packaging
   > serving same-origin on localhost, so even `Strict` would likely survive it, but
   > `Lax` costs nothing and tolerates deep links arriving cross-context.
3. **Should the session cookie expire?** Today it is `PHP_INT_MAX` and session validity is
   enforced server-side by `SessionService`. That is defensible — the server is the
   authority — but it means a stolen cookie is valid until the server-side session
   expires, with no client-side bound. Matching the cookie expiry to the server-side
   session lifetime is more consistent; making it a session cookie unless
   "stay logged in" was ticked is more conventional. The current behaviour is not wrong,
   just unexamined.

   > **Response:** Mirror the server-side session record: a session cookie (no
   > `Expires`) when "stay logged in" is unticked; when ticked, set the cookie
   > expiry to the server-side session lifetime — and if that lifetime is currently
   > infinite, give it a bound (90 days, configurable) as part of this change. The
   > server stays the authority; the cookie stops being a forever-token if stolen.
4. **Is the PHP floor 8.4 or 8.5?** The review says the code floor is 8.4 while both
   declarations say 8.5. Lowering to 8.4 widens compatibility for no benefit this fork
   needs; raising the *code* to genuinely require 8.5 makes the declarations honest and
   costs nothing on a container deployment where the runtime is chosen at build time. I
   lean to keeping 8.5 and treating it as a real requirement, since this fork controls its
   own image — but that should be a decision, not the current accident.

   > **Response:** 8.4 — against the lean. The deciding evidence arrived during the
   > defects verification: the pin had to be temporarily relaxed to boot on a real
   > 8.4 box, and real-hardware verification is now part of this fork's methodology.
   > The code floor *is* 8.4; declaring 8.5 buys nothing today and taxes exactly the
   > workflow that just proved its worth. Pin 8.4 in both places, keep shipping 8.5
   > in the image, and raise the pin in the commit that first uses an 8.5 feature —
   > that is the honest place for the bump.
5. **Is the `shopping_locations` → `stores` rename worth doing at all?** ~250 references
   across 63 files, an `ExposedEntity` name, a column name on four tables and several
   views, and a break for the two known external consumers. Against: "shopping location"
   genuinely is a confusing name next to "location", and [05](05-store-shopping-lists.md)
   makes it more load-bearing. The compatibility-view option (rename the table, add a
   `shopping_locations` view over it) is a real middle path and would keep the API
   additive, at the cost of two names in the schema permanently. I lean to the middle path
   if it happens at all, and to not doing it until something else forces the file to be
   opened.

   > **Response:** No — not even the compatibility-view middle path, yet. The middle
   > path pays "two names in the schema forever" to resolve a naming niggle, and
   > every future migration, view, seed and plan then chooses which name to use.
   > Ship 05 with `shopping_locations`; record the rename as declined unless a
   > breaking batch happens for other reasons, in which case it rides along via the
   > compatibility view.
6. **Does `GetSystemInfo`'s `sqlite_version` key change name, or gain a sibling?**
   Renaming it is honest and breaks `/api/system/info` consumers. Adding
   `database_version` alongside and leaving `sqlite_version` reporting whatever SQLite the
   PHP build has is additive and preserves a field that is now meaningless on half of
   deployments. I lean to adding the new key and marking the old one deprecated in the
   spec, then removing it with the rest of the breaking batch.

   > **Response:** Agreed — add `database_version` (engine name + version from
   > `PDO::ATTR_SERVER_VERSION` on the live connection, which also answers C3 in the
   > same change), deprecate `sqlite_version`, remove it with the batch.
7. **Do the non-breaking and breaking halves ship together?** They are one plan for
   planning purposes, but the non-breaking half can land any time and the breaking half
   wants a version bump, a changelog entry, and a moment when nothing else is in flight.
   I lean to landing C1–C9 opportunistically and treating B1–B4 as a single deliberate
   release.

   > **Response:** Agreed, ship the halves separately — and note the breaking batch
   > just shrank: with Q4 resolved to 8.4 (non-breaking), Q5 declined, and Q1 a
   > config-validation change, "breaking" is down to B1 + B2. That no longer needs a
   > ceremonial release; land C1–C9 opportunistically as planned, and B1+B2 together
   > as one ordinary, clearly changelogged change whenever 11's auth-adjacent work
   > has the files open.

## Effort

Small in aggregate for the non-breaking half — most of C3–C9 is an afternoon, and C1 and
C2 are each a focused session, with C1's cost being the twenty-case auth baseline rather
than the code.

The breaking half is not comparable to it. B1 and B2 are small. B3 alone is larger than
everything else in this plan combined and is the reason it has its own open question
rather than a proposed change: it is a decision to make, not work to schedule.
