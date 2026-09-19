# 10. Cold start and statelessness

**Goal:** A container that serves its first request correctly, with no writable state
outside the database, so scale-to-zero pods are a deployment choice rather than a
gamble.
**Depends on:** nothing. Pairs with [01 file storage](01-file-storage.md), which removes
the other half of the writable data directory.
**Status:** **landed in the codebase** (2026-09-02), except Q7's `dialect` column, which
[ADR-0008](../../adr/0008-postgresql-only-runtime-engine.md)'s acceptance made unnecessary
before it was built. See [Executed](#executed) for what landed, for the two defects the
verification found that the plan did not predict, and for the one check this environment
could not run. Everything from here down is the plan as written and reviewed, kept
because the reasoning is what the code has to keep being judged against.

## Today

Three things happen before the router is even built, and all three are hostile to an
ephemeral filesystem.

**The version-hash redirect** (`app.php:52-77`). A hash of `version.json` plus
`VICTUAL_BASE_URL` plus `VICTUAL_BASE_PATH` names a marker file inside
`data/viewcache`. If the marker is absent the whole cache directory is emptied,
opcache is reset, and the request — whatever it was — is answered with a 302 to `/`.
On an ephemeral filesystem the marker is always absent, so this is not an upgrade
path, it is the cold-start path. An API client's first call after a scale-up gets a
redirect to an HTML page instead of data.

**Migrations run inside a request.** `/` is the only route that calls
`MigrateDatabase()` (`SystemController::Root`), which is why the redirect targets it.
`DatabaseMigrationService` has no concurrency guard anywhere: `EnsureMigrationsTable`,
`ApplyBaselineSchemaWhenNeeded` and each of `ExecuteSqlMigrationWhenNeeded` /
`ExecutePhpMigrationWhenNeeded` do check-then-apply. Two pods starting together roll
back cleanly on the loser's primary-key violation — no corruption — but the loser's user
sees a 500. `migrations/8888.php` is worse: it runs on *every* invocation by design, does
`count() == 0` then `INSERT id 1` on `locations`, and is not inside the per-migration
try/catch, so the race there is unguarded.

**Everything writable lives in one directory that is also the cache directory.**

| Path | Written by | Needs to survive a restart? |
|---|---|---|
| `data/viewcache/*.php` | Blade, on first render of each of the 96 templates under `views/` (73 at the top level, the rest under `components/`, `layout/` and `errors/`) | No — derivable from the source tree |
| `data/viewcache/route_cache.php` | Slim (`app.php:120`) | No — derivable from `routes.php` |
| `data/viewcache/<hash>.txt` | `app.php:68` | No — exists only to drive the redirect |
| `data/viewcache/` HTMLPurifier serializer | `BaseApiController::GetParsedAndFilteredRequestBody` | No — but it is a runtime write on every JSON write request |
| `data/storage/` | `FilesService` | Yes — until [01](01-file-storage.md) lands |
| `data/config.php`, `data/settingoverrides/` | nobody | Read-only; ConfigMap-compatible already |
| `data/victual.db` | SQLite | Moot under PostgreSQL |

The HTMLPurifier serializer path is the one the review did not list, and it is the
reason a "read-only except for the database" pod does not work today even after the
redirect is gone: every `POST`/`PUT` through the generic CRUD API touches it.

**One thing that is better than it looks.** The hash includes `VICTUAL_BASE_URL` and
`VICTUAL_BASE_PATH`, which implies compiled views embed the deployment URL. They do not.
No template under `views/` references either constant; URLs are built by the `$U`
closure injected at render time (`controllers/BaseController.php:78`). Compiled Blade
output is therefore a pure function of the source tree, which is what makes baking it
into the image possible at all. The two URL terms in the hash appear to be defensive
rather than load-bearing — see Q1.

**PrerequisiteChecker runs on every request** and requires `pdo_sqlite` plus SQLite
≥ 3.40, opening `sqlite::memory:` to read the version, regardless of `DB_DRIVER`. On a
PostgreSQL-only deployment that is a pointless connection per request and a pointless
extension in the image. `REQUIRED_PHP_VERSION` there is `8.5.0`, which is its own
problem — see [15](15-deliberate-cleanup.md).

## Proposed change

### Separate the cache directory from the data directory

New setting `VICTUAL_VIEWCACHE_PATH`, defaulting to `VICTUAL_DATAPATH . '/viewcache'` so
nothing changes for existing installs. Container images set it to a baked, image-local
path such as `/app/viewcache`. `SlimBladeView`, the Slim route collector and the
HTMLPurifier serializer all read that one setting instead of deriving their own path
from the data path.

### Bake the cache at image build time

`bin/victual-warm-cache`: compiles every template under `views/` and writes the Slim
route cache, then exits. Run it as the last step of the image build. The result is
deterministic per source tree, so it is a layer, not state.

Blade compiles on demand when a compiled file is missing, so a template the warmer
missed degrades to today's behaviour rather than failing — but only if the directory is
writable. Whether to make it strictly read-only (and so turn a missed template into a
hard error, which is the honest failure mode for an immutable image) is Q2.

### Move migrations out of the request path

`bin/victual-migrate`, a CLI entry point that bootstraps config the way `bin/victual-db-import`
already does, takes the lock, runs `MigrateDatabase()`, and exits non-zero on failure.
That is an initContainer in k3s and a one-line `docker exec` elsewhere.
`DatabaseMigrationService` already works outside a request; nothing about it needs to
change except the lock.

**This one piece ships ahead of the rest of this plan, in wave 0 with
[14](14-contract-and-regression-scaffolding.md) piece 1.** `trigdifftest.php` needs
`TRIGTEST_PRISTINE_PATH` — a migrated SQLite database — and nothing in the tree can
produce one from a command line today: `bin/victual-db-import` returns early when
`DB_DRIVER` is `sqlite` (`bin/victual-db-import:68`), and migrations otherwise only run
from `GET /`. So 14's suite cannot run at all until this exists, which inverts the
roadmap's ordering. Pulling just the CLI forward is the smallest cut that fixes it; the
lock, the cache work and everything else below stay here in wave 1. When this plan's
turn comes, `bin/victual-migrate` already exists and gains the lock.

`SystemController::Root` then stops calling `MigrateDatabase()`. Q4 covers whether a
web-triggered fallback should remain for people running this fork from a stock
container image with no init step.

### One cross-process lock around the whole run

```
pgsql:  SELECT pg_advisory_lock(<constant>)   ... run ...   SELECT pg_advisory_unlock(...)
sqlite: flock() on <db path>.migrate.lock, LOCK_EX, blocking
```

A new `DatabaseDialect` method (`WithMigrationLock(callable)`) is the natural home:
it is genuinely per-engine, unlike the date-offset primitive the review proposed and the
defects pass rejected. The lock wraps the *entire* `MigrateDatabase()` call, baseline
included, so the always-run `8888.php` is covered by the same guard as everything else.

The advisory lock is session-scoped and held on the connection, so it must be taken on
`GetDbConnectionRaw()` and released in a `finally`; a crashed process releases it when
its connection closes, which is the behaviour we want.

`8888.php` should additionally be made idempotent on its own terms — the location-1
insert becomes conditional in SQL rather than in PHP — so that it is safe even if
someone runs migrations outside the CLI entry point.

### Remove the version-hash redirect

With the cache baked and migrations moved, `app.php:52-77` deletes entirely: no
`EmptyFolder`, no marker file, no `opcache_reset` (meaningless in an immutable image
that is replaced rather than updated in place), no 302. Upgrades in a mutable
deployment are then covered by "run `bin/victual-warm-cache` after updating", which
`update.sh` can do.

### Skip the SQLite checks on a PostgreSQL-only deployment

`PrerequisiteChecker` takes the configured driver into account: `pdo_sqlite` and the
SQLite version check apply when `DB_DRIVER` is `sqlite`, `pdo_pgsql` when it is `pgsql`.
That removes a per-request in-memory connection and lets the image drop the extension.
`ApplicationService::GetSystemInfo()` opens the same throwaway connection for the About
dialog; that one is cosmetic and is handled in [15](15-deliberate-cleanup.md).

### Harden the image this plan is the first to publish — sweep S25

The `Dockerfile` runs as root and does `COPY . /app` with no `.dockerignore`, so `.git`
and `data/` go into the layer; compose and CI use `victual`/`victual` for PostgreSQL. All
of that is correct for what it is today — a dev and CI image, tmpfs database, no published
ports — and the sweep rates it Info for exactly that reason. It stops being correct at the
moment this plan bakes a production image from the same file, which is the step "Bake the
cache at image build time" above describes. So: a `.dockerignore`, a non-root `USER`, and
credentials that are not the compose defaults, landing in the same change that publishes
the image rather than after it.

**This item had two claimed owners and therefore none.** The roadmap assigned it here ("10
is the first plan to publish an image from the Dockerfile, so sweep S25 ... is 10's") while
the sweep's own roadmap section assigned it to [15](15-deliberate-cleanup.md)'s
non-breaking table, and neither plan carried a row for it. It is settled here, on the
roadmap's reasoning: 15's table is for cleanup that can land whenever a PR opens the file,
and this cannot — it is meaningless before the production image exists and mandatory in
the same commit as it. The rigor review's H3 records the general shape of the mistake.

### Explicitly not in scope

The request-scoped `define()` constants (`VICTUAL_USER_ID`, `VICTUAL_LOCALE`, …) stay. They
are safe under php-fpm and only rule out worker-mode runtimes, which are not a goal.
Recording that as a decision is worth more than changing it.

### Record which dialect applied each migration

One column on `migrations`, holding the driver name that supplied the file — or the
literal `generic` for a portable one. See Q7. The reason it belongs here rather than in a
plan of its own is that the boot check below has to be dialect-aware anyway, so this is
the one moment someone is already holding this code with that distinction in mind.

It is diagnostic only. Nothing may start *requiring* it, because a database migrated
before the column existed cannot supply it, and making it load-bearing would turn an
older database into an unimportable one.

### Schema

One migration, portable: add the `dialect` column to `migrations`, backfilled `generic`
up to 0255 and left NULL above it (Q7 explains why an honest NULL beats a plausible
guess). Nothing else here changes what a migration does — only when they run and how
they are serialised.

### API

No endpoint gains, loses or changes a field. Two behavioural changes are worth stating
plainly because they are visible to clients:

- **A cold-start request is no longer answered with a 302 to `/`.** This is a fix, but
  any client that learned to follow that redirect will simply stop seeing it.
- **`GET /` no longer migrates the schema.** Anyone pulling this fork into a container
  that has no init step, and relying on "hit the page once after an update", loses that
  behaviour unless Q4 says otherwise. This is the one item in this plan that can break
  an existing deployment, and it should be in the changelog rather than discovered.

**Client impact: no field changes, two behavioural ones, and both are above.** Neither
tracked client in [17](../17-ecosystem-clients.md) follows the cold-start redirect or relies
on `GET /` to migrate — they authenticate to `/api/` and would have failed against an
unmigrated database anyway. The exposure is deployment scripts rather than clients, which
is the distinction 16 got wrong in the other direction: 16's premise was true of
deployments and false of clients, and here it is the reverse.

## Verification

Lint proves nothing here; every check below wants a booted instance.

1. **Fresh container, first request is an API call.** Empty data directory, empty
   PostgreSQL database, migrations run by the init step. `curl -H 'VICTUAL-API-KEY: …'
   /api/stock` as the very first request must return 200 with a JSON body — today it
   returns 302 with an HTML target. Repeat on SQLite.
2. **Concurrent cold start.** Two `bin/victual-migrate` processes against the same empty
   PostgreSQL database, started together; and the same on SQLite. Both must exit 0 and
   the `migrations` table must contain each number exactly once. Run it ten times — the
   current race is timing-dependent and a single green run means nothing.

   Run this check with `FEATURE_FLAG_STOCK_LOCATION_TRACKING=false`. The `locations`
   row this plan's race is about is inserted by `migrations/8888.php` *inside* an
   `if (!VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)` guard, and `config-dist.php:167`
   defaults that flag to **true**. On a default install the guard never runs, and the
   only row in `locations` is the id **2** "Fridge" that `migrations/0006.sql` inserts —
   so the assertion "exactly one row with id 1" fails for a reason that has nothing to
   do with concurrency, and the race the plan exists to close is never exercised at all.
   With the flag off, assert both the `migrations` uniqueness and the id-1 row.
3. **Racing pods against an already-migrated database.** Five concurrent
   `bin/victual-migrate` runs must be no-ops and must not deadlock (this is the always-run
   `8888.php` path, which is the one that runs on every start forever).
4. **Read-only root filesystem.** Boot with the image's filesystem mounted read-only
   except for the database, browse every top-level page, and perform one create, one
   edit and one delete through the API. Any write attempt to the baked cache directory
   is a failure to fix, not to work around — this is what catches a template the warmer
   missed and the HTMLPurifier serializer path.
5. **Schema equivalence unchanged.** `difftest.php` over the full view list and the
   existing `trigdifftest.php` scripts must produce the same output before and after.
   Nothing here should touch schema, so any diff is a bug in the lock wrapping.
6. **Prerequisite skip is real.** On a `pgsql` deployment, remove `pdo_sqlite` from the
   image and confirm the app boots and serves pages; confirm a `sqlite` deployment still
   fails loudly with the existing message when the extension is missing.

## Sequencing

**First among the hardening plans, and independent of the feature roadmap.** Nothing in
01–09 blocks on it and it blocks nothing, but it is the prerequisite for k3s work being
pleasant rather than superstitious, and it should land before any effort goes into
scale-to-zero tuning.

It pairs with [01 file storage](01-file-storage.md): 01 removes `data/storage`, this
removes everything else writable, and only both together give a pod with no volume. Do
this one first — 01's importer is easier to reason about when the cold-start path is no
longer rewriting requests.

Against the other hardening plans it is independent: it touches `app.php`, the migration
service and `PrerequisiteChecker`, none of which [11](../11-api-error-handling.md),
[12](12-frontend-shared-core.md), [13](13-write-path-transactions.md) or
[14](14-contract-and-regression-scaffolding.md) go near. It can be done in parallel with
any of them — with the two seams noted below.

`bin/victual-migrate` is the exception in the other direction: it ships early, in wave 0,
because [14](14-contract-and-regression-scaffolding.md) piece 1 cannot run without it
(see *Move migrations out of the request path*).

The second seam is with [13](13-write-path-transactions.md), and **13 has landed, so this
is now a resolved worry rather than a live constraint.** `DatabaseMigrationService` opens
raw transactions of its own, and this plan wraps the whole migration run in a lock; 13
converted seven service entrypoints to an `InTransaction` helper and deliberately left
those alone. This paragraph used to say that was fine "as long as the helper counts depth
rather than assuming it opens the outermost transaction", and that a depth-blind helper
would mis-nest if a PHP migration ever called a service through it.

The helper that shipped does something better than counting: it asks
`PDO::inTransaction()`. A counter would only know about transactions opened through the
helper, so `DatabaseMigrationService`'s own would be invisible to it and the mis-nesting
this paragraph feared would happen *because of* the counter. Asking the connection cannot
have that blind spot. 13-Q2's recorded response chose depth counting and the
implementation overrode it with a reason written into the docblock — the deviation is
noted in 13's Executed section. Nothing is owed here; the constraint is discharged.

What this plan still owes 13 is the other half of that docblock: it points at
`DatabaseDialect` for "the per-engine locking used around migrations", and no such method
exists yet because it is *this* plan's lock. Either the lock lands here and the pointer
resolves, or the docblock is reworded first — 15-C12 carries it as the cheaper of the two.

## Open questions

1. **Are `VICTUAL_BASE_URL` / `VICTUAL_BASE_PATH` still load-bearing in the cache hash?**
   Nothing under `views/` reads either constant and `$U` resolves at render time, which
   says no. But the hash was written for a reason, and the honest check is to compile the
   cache under one base path, serve under another, and diff the rendered HTML — not to
   reason about it. If they are genuinely irrelevant, the cache is a build artifact and
   this whole plan gets simpler.

   > **Response:** Run exactly the empirical check proposed — compile under one base
   > path, serve under another, diff the HTML. Expect "not load-bearing" (the
   > `$U`-closure evidence is strong). If it somehow fails, the fallback is warming
   > in the initContainer per deployment instead of the image — the plan survives,
   > one layer moves. Note what that fallback costs, because it collides with Q2:
   > an initContainer-warmed cache needs a writable `emptyDir` shared with the app
   > container, so the cache directory can no longer be read-only and the
   > build-time "did every template compile?" gate becomes a deployment-time one.
   > The pod still has no *persistent* volume, so 01+10's goal survives, but Q2's
   > answer would have to be revisited rather than silently kept.
2. **Should the baked cache directory be read-only, or writable as a fallback?**
   Read-only turns a missed template into a 500 at exactly the moment someone opens that
   page, which is a bad time to find out. Writable hides the problem and quietly
   reintroduces a mutable path. I lean read-only *plus* a warmer that fails the build if
   it did not compile every file under `views/`, which moves the discovery to build time
   where it belongs.

   > **Response:** Agreed — read-only, with a warmer that fails the build unless
   > every file under `views/` compiled. One addition: the warmer must also
   > pre-generate the **HTMLPurifier definition cache** (one dummy purify call at build
   > time does it — definitions depend only on the library version and config).
   > Otherwise the first JSON write request hits the read-only serializer path, and
   > verification check 4 fails on the first POST rather than on a missed template.
3. **Where does the SQLite lock file live?** `flock` needs a real file. Next to the
   database is the obvious answer and inherits its permissions, but it puts a second file
   in a directory that some deployments treat as "the database, nothing else". The
   alternative is locking the database file itself, which is safe with `flock` but
   surprising to read.

   > **Response:** Sibling file (`<db>.migrate.lock`), not `flock` on the database
   > file itself — SQLite holds its own locks on that file, and "safe but
   > surprising" is the wrong property for locking code. The k3s target is
   > PostgreSQL anyway; the SQLite path is a dev convenience, and plain beats
   > clever.
4. **Keep a web-triggered migration fallback?** Removing it entirely is cleanest and
   matches the deployment target. Keeping it behind a setting
   (`MIGRATE_ON_ROOT_REQUEST`, default off) costs almost nothing and keeps the fork
   usable in a stock container image with no init step. I lean to keeping the setting,
   defaulting off, precisely so the default is the immutable one.

   > **Response:** Agreed — `MIGRATE_ON_ROOT_REQUEST`, default off. Refinement: the
   > Q6 fail-fast message should name the setting and `bin/victual-migrate`, so the
   > failure is its own documentation.
5. **Does the migration CLI also warm the cache?** They are different lifecycles — one is
   per image build, the other is per deployment — but a single `bin/victual-init` that does
   both is one less thing to forget. I lean to keeping them separate commands and letting
   the image build and the initContainer each call the one they need.

   > **Response:** Agreed, keep them separate. They run at different lifecycle
   > moments (image build vs deployment); a combined `victual-init` blurs exactly the
   > distinction this plan exists to draw.
6. **What happens when the app boots against a database that is behind the code?**
   Today it silently migrates. With migrations moved out, the options are: fail fast with
   a clear message, serve anyway and break unpredictably, or check the `migrations` table
   on boot and refuse. Failing fast is the only honest one, but it needs a cheap check —
   one `SELECT MAX(migration)` per request is probably acceptable, and probably wants to
   be behind the same setting as Q4.

   > **Response:** Fail fast, unconditionally — do not tie the *check* to the Q4
   > setting; only auto-migration is opt-in. One `SELECT MAX(migration)`, memoized
   > per request, is noise at household scale. Two additions: also fail on a
   > database *ahead* of the code (a rollback scenario that would otherwise break
   > unpredictably), and if the per-request cost ever bothers anyone, APCu is the
   > answer then — not now.
   >
   > Two things the answer above leaves undefined, settled here because both bite
   > later. **What the code's expected number is:** take the maximum of
   > `DatabaseMigrationService::GetMigrationFiles($dialect)`, not a hardcoded
   > constant and not a count. It must be dialect-aware, because
   > engine-exclusive migrations exist — `0256.sqlite.sql` is already in the tree
   > and [01](01-file-storage.md) adds `0257.pgsql.sql` — and a dialect-blind
   > maximum would put a deployment permanently "behind" a file it is never
   > supposed to run. `DatabaseMigrationService::GetLatestMigrationNumber($dialect)`
   > already exists for this; `DatabaseImporter` was the first caller.
   > **What the failure looks like:** HTTP 503 with a plain-text body naming the
   > database's number, the code's number, `MIGRATE_ON_ROOT_REQUEST` and
   > `bin/victual-migrate` (per Q4's refinement). 503 rather than 500 because the
   > condition is transient and operational, and it is the one pre-[11](../11-api-error-handling.md)
   > status decision that 11 should inherit rather than revisit.
7. **Should the `migrations` table record which dialect applied each migration?**
   Today it stores the number and a timestamp, and nothing else. The number is the
   migration's *identity*, while the file that supplied it — `0260.sql`,
   `0260.sqlite.sql`, `0260.pgsql.sql` — is not recorded anywhere. That was
   unambiguous while every migration applied to every engine. It stopped being so
   the moment `0256.sqlite.sql` landed: two databases can both hold the row `256`,
   have run different files, and hold different schemas, and nothing in the data
   says which happened.

   The guards added with the suite close the gap from the *authoring* side —
   `check-migrations.php` refuses an unmarked engine-exclusive or shadowing file,
   and the loader refuses a name that does not parse. Both reason about the
   repository. Neither can answer the question a running database poses: *this*
   row 256, in *this* database — what actually ran?

   A `dialect` column (nullable, or the literal `generic`) answers it, and it is a
   small migration. The cost is not the column, it is the callers: this plan's
   boot check, `DatabaseImporter`'s two version assertions, and anything else
   reading `MAX(migration)` would all want to consider it, and the column has to be
   backfilled for existing rows — where the honest backfill is "unknown", because
   the information was never recorded.

   > **Response:** Do it, in this plan rather than as its own. The boot check is
   > already opening this code and already has to be dialect-aware, so the column
   > lands where someone is holding the file. Two constraints on how.
   >
   > **Backfill `generic` for everything up to 0255, and NULL above it.** The
   > historical migrations genuinely were portable-or-SQLite-only in a way the
   > baseline already encodes, so `generic` is true rather than convenient. Above
   > the baseline, a row written before this column existed has no recoverable
   > answer and NULL should say so — a plausible guess here would be worse than an
   > admission, because the whole point of the column is to stop two databases
   > silently disagreeing.
   >
   > **The column is diagnostic, not load-bearing.** No comparison may start
   > *requiring* it, or a pre-column database becomes unimportable. The version
   > checks stay as they are — each side against
   > `GetLatestMigrationNumber($dialect)` for its own engine — and the column is
   > what a human reads when those checks disagree and the reason is not obvious.
   > It earns its place by making a confusing failure diagnosable, not by adding a
   > new way to fail.

## Executed

Landed 2026-09-02 in six commits, in the order this plan argues for: the cache path and
its warmer, the lock, the redirect and the boot check, the prerequisite split, and the
image. Measured against the working copy at `1036a52` (this plan's branch, off
`5be7a58`), on PHP 8.4.19 and PostgreSQL 16.

**[ADR-0008](../../adr/0008-postgresql-only-runtime-engine.md) was accepted while this was
in flight, and it shortened the plan rather than changing it.** The plan text above is
left as written; each item it shrank or dropped is named below with 0008 as the reason.
The retirement *work* is not scheduled, so SQLite still runs here — the differential
suite, `run-app`, demo mode — and nothing below breaks it.

- **`cced9e8` — the cache path and the warmer.** `VICTUAL_VIEWCACHE_PATH`
  (`config-dist.php`), defaulting to `VICTUAL_DATAPATH . '/viewcache'` so an existing
  installation is untouched. `SlimBladeView`, the Slim route collector and the
  HTMLPurifier serializer path all read it. `bin/victual-warm-cache` compiles all 96
  templates under `views/`, writes the route cache and generates the HTMLPurifier
  definition cache, exiting non-zero unless every one compiled (Q2). Separate from
  `bin/victual-migrate`, per Q5. The purifier configuration moved to
  `BaseApiController::CreateHtmlPurifier()` so the warmer and the API cannot drift into
  building two different definition caches.

  **One addition the plan does not describe:** the route cache file is named after a hash
  of `routes.php` and `VICTUAL_BASE_PATH` (`helpers/CachePaths.php`). FastRoute never
  invalidates its cache, and Slim prefixes the base path onto every pattern *before*
  FastRoute compiles them — so a route cache is only valid for one routing table and one
  base path. The deleted version hash covered that from a distance and only for released
  version changes; naming the one file that depends on those inputs after them covers it
  precisely and locally.
- **`6b46fdf` — the migration lock.** `DatabaseDialect::WithMigrationLock(callable)`,
  wrapping the whole of `MigrateDatabase()` including the baseline and the always-run
  8888. PostgreSQL takes `pg_advisory_lock(1986947956)` on `GetDbConnectionRaw()` and
  releases it in a `finally`. `migrations/8888.php` inserts its location conditionally in
  SQL as well as in PHP.

  **One implementation, not two (0008: "`DatabaseDialect::WithMigrationLock` — two
  implementations to one").** `SqliteDialect::WithMigrationLock()` runs the callable and
  takes no lock, with a docblock saying why: SQLite is not a runtime engine, nothing
  migrates it concurrently, and its own file locking makes a hypothetical loser fail
  rather than corrupt. **Q3 — where the SQLite lock file lives — is therefore moot**, and
  is recorded as moot rather than answered.

  **The lock requires a direct connection or a session-mode pool entry**, which
  [ADR-0009](../../adr/0009-database-as-the-logic-layer.md)'s finding F1 asked this plan to
  say. A session-scoped advisory lock lives on a backend, so a transaction-mode pooler can
  hand the unlock to a different one and leak the lock permanently. It is stated in
  `PostgresDialect::WithMigrationLock()`'s docblock and in `bin/victual-migrate`'s header
  comment, which is where an operator reads it. `pg_advisory_xact_lock()` is not the
  answer here because the run opens and commits transactions of its own.

  **A second race had to be closed for the first one to be reachable.**
  `PostgresDialect::OnConnected()` creates the changed-time table with `CREATE TABLE IF
  NOT EXISTS`, which PostgreSQL documents as not race-free, and it runs while the
  connection the lock would be taken on is being opened. Two pods starting together failed
  there, before the lock existed. Losing that race is now treated as the outcome it is.
- **`258aadf` — the redirect, the root route and the boot check.** `app.php:52-77` is
  gone entirely: no marker file, no `EmptyFolder`, no `opcache_reset`, no 302.
  `SystemController::Root` migrates only when `MIGRATE_ON_ROOT_REQUEST` is true, default
  false (Q4). `middleware/SchemaVersionMiddleware.php` compares one memoized
  `SELECT MAX(migration)` against `GetLatestMigrationNumber($dialect)` on every request
  and answers 503 in plain text, naming both numbers, the setting and the command, in
  either direction (Q6). **Both halves of that sentence — the maximum, and what the query
  was allowed to fail with — were wrong, and are corrected in the second review fix
  below**; the commit is recorded as it shipped.

  **Where it lives, and the ordering problem it has to avoid.** It is app-level
  middleware added after `addRoutingMiddleware()` and before `addErrorMiddleware()`, so it
  runs inside error handling and *outside* routing and authentication — an unmigrated
  database should not be asked to resolve a route or identify a user first. That places it
  before `RouteContext` exists, so the one route it must not run in front of — `/`, when
  `MIGRATE_ON_ROOT_REQUEST` is on and the migrations table legitimately does not exist yet
  — is matched on the request path with the base path stripped. Every other route is
  checked even then, which is what keeps the fallback from being a hole: the API still
  refuses to answer from an unmigrated database. An empty database reads as migration 0 and
  gets the same 503 with "(nothing migrated yet)".

  `update.sh` runs `bin/victual-warm-cache` after updating, and `.agents/skills/run-app`
  migrates before booting — both replacing something the redirect used to do implicitly.
- **`841c4f6` — the prerequisite split.** `checkRequirements()` keeps what
  `public/index.php` can know before anything is loaded; `checkDatabaseRequirements($driver)`
  runs from `app.php` with the configuration in hand and checks `pdo_pgsql` for pgsql,
  `pdo_sqlite` plus the SQLite version for sqlite. **Kept driver-conditional rather than
  deleted** (0008: "Delete the branch"), because the sqlite branch is three lines, the
  suite and `run-app` still need it, and the retirement PR is what removes it — the
  constant says so in place.
- **`5ec3e72` — a defect the verification found.** Blade names a compiled file after a
  hash of the absolute path of its source, so the warmer's `bin/../views/...` spelling and
  the application's `views/...` are two different cache entries. Everything the warmer
  compiled was being ignored and recompiled on demand — invisible on a writable directory,
  every page a 500 on a read-only one. `realpath()` in the warmer. Found by running check 4,
  not by reading the diff, which is the entire argument for check 4.
- **`5a3ab76` — the image (sweep S25).** `.dockerignore` (`.git`, `data/`, the composer
  and yarn output, coverage), a named `dev` target for the existing image, and a
  `production` target: Apache with mod_php on 8080, `USER www-data`, the view cache baked
  by the warmer into `/app/viewcache` owned by root so the serving user cannot write it,
  front end packages from a node stage, composer removed after use. Nothing under `/app` is
  written at runtime; a read-only root filesystem needs `/var/run/apache2` and the data
  directory writable, which the Dockerfile says. CI builds both targets and asserts
  non-root, cache baked and unwritable, and no `.git` or `data/` inside.

  **`pdo_sqlite` stays in the production image** (0008: "Gone"), because
  `bin/victual-db-import` still reads SQLite as an import format — the one thing 0008 keeps
  SQLite for. The Dockerfile says that where the extension is installed.

  **The compose credentials stay `victual`/`victual`, and now say why in place.** That
  database lives for the length of a suite run, on a tmpfs, with no published ports, and
  every documented invocation of the suite depends on the values. Changing them would move
  the secret rather than remove it. What S25 actually asked for is that the *published*
  image bake none, and it bakes none: the connection arrives as `VICTUAL_DB_*` or as
  `settingoverrides` files.

**Q7's `dialect` column was not built.** ADR-0008's consequences table rates it
"Unnecessary" — the column exists to tell two engines' identically numbered rows apart,
and after retirement there is only one engine. The migration number `0257` is released
rather than consumed. The gap the column would have closed is recorded in
[ADR-0004](../../adr/0004-engine-specific-migrations.md) and stays open until retirement
closes it by removing the ambiguity itself.

### What was verified, and how

Every check ran against a booted instance or real concurrent processes. The scripts were
throwaway rather than committed fixtures, so each is described in the form that
reproduces it.

1. **Q1 — are `BASE_URL` / `BASE_PATH` load-bearing in the compiled templates? No.**
   Warmed one cache under `VICTUAL_BASE_PATH=/elsewhere` and
   `VICTUAL_BASE_URL=https://elsewhere.example/app`, another under the serving
   configuration, served 17 pages against each and diffed the HTML: **identical, byte for
   byte, on every page**. The `$U`-closure reasoning in *Today* holds, and the fallback the
   response describes (warming per deployment instead of per image) is not needed.

   Two things the check turned up on the way. Compiled Blade output is **not**
   byte-reproducible — two warmings of the same tree under the same configuration differ in
   47 of 96 files, because `@once` embeds a fresh UUID per compilation — so a byte
   comparison of caches proves nothing and the rendered-HTML comparison is the only honest
   form of this check. And the *route* cache genuinely does depend on the base path, which
   is why it is named after it; served with a base path the baked cache was not warmed for,
   a read-only image fails at boot with `RuntimeException: Route collector cache file
   directory ... is not writable` rather than 404ing every route. Verified by doing it.
2. **Check 1 — first request is an API call.** Fresh data directory, empty database,
   migrated by `php bin/victual-migrate`, then `curl -H 'VICTUAL-API-KEY: ...' /api/stock`
   as the very first request: **200 with a JSON body, no `Location` header**, on both
   engines. Before the migration the same request is **503** with the plain text body.
   `GET /` is a 302 to `/stockoverview` — the entry page, not to itself. The
   `MIGRATE_ON_ROOT_REQUEST=true` path was checked in the same shape: `/api/stock` 503 on
   an empty database, `GET /` migrates and 302s, `/api/stock` then reaches authentication.
3. **Check 2 — concurrent cold start, ten times, `FEATURE_FLAG_STOCK_LOCATION_TRACKING=false`.**
   Two `bin/victual-migrate` processes started together against an empty PostgreSQL
   database, each iteration asserting both exits, `migrations` holding each number exactly
   once, and the id-1 location existing. **On the unmodified tree (`2312ac2`, exported to a
   scratch checkout): 10 of 10 iterations failed**, the loser dying on
   `duplicate key value violates unique constraint "pg_type_typname_nsp_index"`. **With the
   change: 0 of 10.** The baseline is what makes the result mean anything, and it is also
   what found the `OnConnected()` race, which was still failing 9 of 10 after the lock
   alone. On SQLite, per ADR-0008, this is not a runtime concern; a single
   `bin/victual-migrate` run and a repeat run were confirmed to work and to leave 256 rows
   and the id-1 location.
4. **Check 3 — five concurrent runs against a migrated database.** Five processes, five
   iterations, no-op each time: **0 failures, no deadlock**, which is the always-run 8888
   path.
5. **Check 4 — read-only cache directory.** The cache warmed and then `chmod -R a-w`,
   owned by root, with the server run as `ubuntu` (root ignores file permissions, so this
   check is meaningless as root). 41 top-level pages browsed and one create, one edit and
   one delete through `/api/objects/locations` with an HTML-bearing description, which is
   what exercises the HTMLPurifier serializer. **Nothing was written into the cache
   directory** (`find -newer` a marker: empty) and the server log holds no permission or
   write errors. This is the check that found `5ec3e72`.

   Three pages 500 on PostgreSQL, and **all three fail identically on the unmodified
   tree** — they are pre-existing PostgreSQL defects, not cold-start ones. See *What this
   turned up* below.

   **The container-level read-only root filesystem could not be tested here: Docker is
   not available in this environment.** Neither could the image be built. The production
   target, its Apache configuration and the CI job that builds it were therefore unproven
   as written — reviewed, not run. **That gap is now closed in CI rather than by
   assertion**; see *Review fix* below.
6. **Check 5 — the differential suite.** `.devtools/pgsql/run-tests.sh`, all five phases,
   against this working copy: **SUITE PASSED**, including `check-migrations.php`
   ("MIGRATION NUMBERING OK"), `MIGRATED STATE IDENTICAL`, all view phases, both rollback
   phases and the filter phase. Nothing here touches schema and nothing in the suite moved.
7. **Check 6 — prerequisite skip is real.** A PHP configuration directory without
   `20-pdo_sqlite.ini` or `20-sqlite3.ini`, selected with `PHP_INI_SCAN_DIR`: with
   `DB_DRIVER=pgsql`, `bin/victual-migrate` runs and the application serves `/api/stock`
   (200) and its pages with **no SQLite extension loaded at all**. With `DB_DRIVER=sqlite`
   the same runtime refuses to start: *"PHP module 'pdo_sqlite' not installed, but required
   for the 'sqlite' database driver."*

### Review fix: the temporary directory a read-only filesystem still needs

The maintainer's review of this plan's pull request found the hole the environment above
could not: **the image named its writable paths and the list was wrong.** It said
`/var/run/apache2` and the data directory and nothing else, while
`FilesService::GetDownscaledFileName` calls `ImageResize::getImageAsString()`, which is
implemented as `save()` to a `tempnam(sys_get_temp_dir(), '')` followed by
`file_get_contents` and `unlink`. On a read-only root filesystem with no temporary
directory provisioned, the first request for a thumbnail fails — not at boot, where it
would be obvious, but on whichever page first shows a picture.
[01](01-file-storage.md)'s `DatabaseStorage` reaches the same place from the other
direction: it streams through `php://temp/maxmemory:2097152`, which spills to the
temporary directory for anything over 2 MiB.

**The complete list, now in the Dockerfile where an operator writing a deployment reads
it:** `/data` (the data directory — uploads under `FILE_STORAGE=filesystem`, and a SQLite
database where one is used), `/var/run/apache2` (Apache's pid file), and `/tmp` (PHP's
temporary directory, and now Apache's lock directory too). `TMPDIR`, `sys_temp_dir` and
`upload_tmp_dir` are all set to `/tmp` rather than left to the default, because
`sys_get_temp_dir()` consults the ini setting before the environment and because a value
an operator can read in `phpinfo()` beats one they have to infer.

Two things the fix turned up on the way:

- **Apache's lock directory would have killed the container before Apache started.**
  Debian's `envvars` sets `APACHE_LOCK_DIR=/var/lock/apache2`, which does not exist in
  this image, and `apache2-foreground` runs `mkdir -p` over it on every start — which
  fails on a read-only root. It is repointed at `/tmp`, which always exists and is always
  writable, so the list stays at three paths rather than four. The build greps for the
  line it rewrote, so a base image that spells the variable differently fails the build
  rather than the container. `APACHE_LOG_DIR` needs nothing: the logs go to the
  container's stdout and stderr, set globally as well as per vhost because the main server
  opens its error log before any vhost applies.
- **The image was quietly capping every upload at 2 MiB.** `php.ini-production` sets
  `upload_max_filesize = 2M`, and [01](01-file-storage.md)'s `FileSizeLimit` takes the
  smallest of that, `post_max_size` and `FILE_STORAGE_MAX_SIZE_MB` as the effective limit —
  so a household configuring 64 MB got 2. Both directives are 8M in the image's own ini
  now, which is what `php.ini-production`'s `post_max_size` already was.

**The finding was reproduced before it was fixed**, without Docker, because the failure
does not need a container: a booted instance served as `ubuntu` against a baked read-only
cache, with `php -d sys_temp_dir=<a mode 555 directory>`, which is what a read-only root
filesystem looks like to `tempnam()`. Uploading a 200×200 PNG succeeds (**204**) and the
next request for it — `?force_serve_as=picture&best_fit_width=64` — is a **500**. Pointed
at a writable directory instead, the same request is **200** and the served image really
is 64×64. Nothing wrote into the read-only cache directory in either run. The same host
also printed `FileSizeLimit`'s own clamp on startup — *"FILE_STORAGE_MAX_SIZE_MB is 64 MB,
but PHP's upload_max_filesize (2M) is smaller, so uploads are limited to 2 MB"* — which is
the second item above, measured rather than reasoned about.

**Verification 4 now runs in CI**, in the `images` job, as the step *"The production image
serves with a read-only root filesystem"*. It runs the production image with `--read-only`
and tmpfs mounts for exactly the three paths above and nothing else, migrates through
`bin/victual-migrate` (nothing migrates inside a request any more), waits for
`/stockoverview`, and then exercises the two paths the finding names: a 200×200 PNG
uploaded and re-fetched with `best_fit_width=64`, which is the `tempnam` path, and a
3 MiB body through the upload API, which is over the old clamp. The `php://temp` spill is
exercised directly in the container rather than through an upload, because the database
backend only exists on PostgreSQL — `ConfigurationValidator` refuses it on SQLite — while
the spill is a property of the filesystem the container is running on. Finally
`docker diff` must be empty: every write above landed on a tmpfs, and a tmpfs is not part
of the container layer, so anything the image wrote to itself shows up there.

### Review fix: the gate compares sets, and stops calling every failure an empty database

The same review found two defects in the boot check itself, both of which make it answer
confidently and wrongly. They are corrected together because they are the same mistake in
two places: the check was reading one number and treating it as the whole truth.

**A maximum is not a schema version (P1).** The check compared `MAX(migration)` against
`GetLatestMigrationNumber($dialect)`, and Q6's response above is where that came from.
Migrations reach `master` in the order their pull requests merge, not in numeric order, so
the split of this wave's work makes the hole reachable rather than hypothetical: #36
applies 0257 and 0259, #34 then introduces 0258. After both have landed the database holds
{…, 257, 259}, `MAX(migration)` is 259, the code's latest is 259 — and the gate reports the
schema current although the table 0258 creates was never made. Worse, it reports it
current *forever*: nothing about the maximum ever changes again, so the check cannot
notice, and cannot prompt anyone to repair a database that already reached that state.

The check now compares the required set with the applied set.
`DatabaseMigrationService::GetRequiredMigrationNumbers($dialect)` reads the migration files
the way `GetLatestMigrationNumber()` already did — dialect-aware, always-run 8888/9999
excluded, per ADR-0004 — and `GetAppliedMigrationNumbers()` reads the whole `migrations`
column instead of its maximum. `GetMissingMigrationNumbers()` and
`GetUnknownMigrationNumbers()` are the two directions, and a request is served only when
both are empty. It is still one memoized query per request, so Q6's cost argument stands
unchanged; what it retracts is Q6's `SELECT MAX(migration)`, which cannot answer the
question it was asked. The 503 names the migrations rather than only the numbers around
them — *"Missing from the database: 258"*, or *"1-256"* for a database nobody has migrated,
consecutive runs collapsed into ranges so that "every migration there is" is readable.

**Not every database failure means "nothing has been migrated" (P2).** The old lookup
caught `\Exception` and answered 0, memoized. That catch is as wide as the database: an
unreachable server, a role without `SELECT` on `migrations`, a statement timeout and a
malformed query all became "this database is empty", and the operator was told to run
migrations at a database that was not the problem. Only the specific condition maps to
zero now — `DatabaseDialect::IsMissingTableError()`, per engine because the engines say it
differently. PostgreSQL has a SQLSTATE for it (42P01) and nothing else qualifies; SQLite
reports a missing table, a missing column and a syntax error alike as `HY000` with driver
code 1, so its implementation checks the SQLSTATE and then the message, which is the only
thing that separates them. Everything else propagates, unmemoized, and
`SchemaVersionMiddleware` turns it into a **distinct** 503 that says the schema version
could not be read at all and does not mention migrating.

That response is deliberately not the 500 error page. `ExceptionController` renders a
template and asks `ApplicationService` for system information, both of which reach the
database that is already failing; a middleware that answers in plain text before routing
can say what happened without depending on anything that is broken. The driver's own
message goes to the server log and, in `dev` mode only, to the body — this response is
emitted before authentication, and a connection failure names the host, port and role. The
SQLSTATE is in the body either way, because it identifies the condition without describing
the deployment.

**Neither is an engine question, and both had to be proved on both engines**, so the
verification is a sixth phase of the differential suite rather than a throwaway script:
`.devtools/pgsql/schemagatetest.php`, run by `run-tests.sh schema` against SQLite and then
PostgreSQL. It digs the hole the finding describes (deletes the second-highest applied
migration, so the maximum does not move), asserts the set-based check finds it *and* that
the maximum-based check would not have, renames the `migrations` table away and asserts
that alone reads as an empty database, renames the `migration` column away and asserts that
propagates instead, and asks each dialect directly whether it can tell a missing table from
a missing column and from a syntax error. Measured rather than assumed: SQLite answers
`HY000` to all three, PostgreSQL answers `42P01`, `42703` and `42601`.

**What was run, 2026-09-03, in the repository's own dev image against `postgres:16`:**
the full suite (`.devtools/pgsql/run-tests.sh`) — **SUITE PASSED**, all six phases,
`MIGRATION NUMBERING OK`, `MIGRATED STATE IDENTICAL`, both `SCHEMA GATE OK` — and the CI
syntax sweep. Over HTTP, on a booted instance: a migrated database serves (401 from
authentication, so the gate let it through); with row 255 deleted and `MAX(migration)`
still 256 the same request is **503** naming *"Missing from the database: 255"*; with the
row restored it is 401 again; an unmigrated database is 503 naming *"1-256"*; and a
PostgreSQL database whose `migration` column was renamed while the server was running is
**503** with the database-unavailable body and `SQLSTATE: 42703`, with
`SQLSTATE[42703]: Undefined column …` in the server log and in the dev-mode body.

**One thing this fix does not reach, found while verifying it.** A database that is
unreachable *at bootstrap* never gets as far as this middleware: `app.php` constructs
`ExceptionController` while building the error middleware, `BaseController::__construct`
opens the database, and the request dies with an uncaught `PDOException` rendered as a raw
PHP fatal error — with a **200** status, on the built-in server. That is the same defect
`f4d1769b` fixed one line above for middlewares (constructing a service opens the database),
it predates this plan, and fixing it means making `BaseController` acquire its connection
lazily, which is a wider change than a review fix should carry. The database-unavailable
response above is reachable for every failure after the connection is open — a server that
goes away mid-process, a revoked grant, a timeout — which is what the finding is about; the
bootstrap case is recorded here rather than quietly left as though it were covered.

### What this turned up

Three pages return 500 on PostgreSQL, on the unmodified tree as much as on this one, so
they belong to nobody's plan yet and are recorded here because this is the first time
anything browsed every page on PostgreSQL:

- `/shoppinglist` — `column "uihelper_shopping_list.product_name" must appear in the GROUP BY clause`
- `/mealplan` — the same, on `meal_plan_sections.sort_number`
- `/locationcontentsheet` — `function ifnull(integer, integer) does not exist`

The first two are the GROUP BY strictness difference between the engines reaching
PHP-built queries the differential suite never asks for; the third is a SQLite function
name written into PHP. All three are invisible to `difftest.php`, which compares views
rather than pages, and all three become "the application is broken" rather than "one
engine is broken" once [ADR-0008](../../adr/0008-postgresql-only-runtime-engine.md)'s
retirement lands.

## Effort

Medium. The individual pieces are all small — the cache warmer is an afternoon, the lock
is a dialect method, deleting the redirect is a deletion — but the verification is the
real cost: several of the checks above want two engines and genuinely concurrent
processes, which is fixture work that does not exist yet. Doing
[14](14-contract-and-regression-scaffolding.md) first would give this plan somewhere to
put its cold-start tests, but it is not a hard dependency and this one is more urgent.
