# 14. Contract and regression scaffolding

**Goal:** Turn the fork's two differential test scripts into a suite anyone can run in
one command, add a response-contract snapshot so the additive-API rule is enforced by a
failing test rather than by vigilance, and put both behind minimal CI.
**Depends on:** nothing. Everything else in the roadmap is easier once this exists.
**Status:** **partly landed.** Pieces 1, 3 and 4 are in the tree — the runnable suite, CI,
and the coverage reporting added after the plan was written — landed as wave 0 between
2026-08-27 and 2026-08-29. **Piece 2, the response-contract snapshot, is not built**, and
remains scheduled for wave 5 after [11](11-api-error-handling.md) has stabilised the
failure paths it would record. See [Executed](#executed) for what landed and what the
suite grew in the doing. Everything below is the plan as written and reviewed; where it
describes piece 1 in the future tense, read the Executed section for the present one.

## Today

The fork has exactly one safety net and it is entirely manual.

**`difftest.php`** puts both engines into an identical table state and compares what
their views return. It is a good tool — it is what proves the PostgreSQL port rather than
asserting it. But its interface is:

    docker run --rm --network victualnet -v "$PWD":/app -v /path/to/scratch:/scratch \
      -v /path/to/scratch/data:/data \
      -e DIFFTEST_SQLITE_DSN=… -e DIFFTEST_PGSQL_DSN=… \
      victual-dev php /app/.devtools/pgsql/difftest.php seed.sql <view> [<view> …]

and `seed.sql` is not in the repository. Neither is the list of views. There are 45 views
in the baseline; which of them a given change should be tested against is knowledge that
lives in whoever ran it last. `DIFFTEST_SKIP_COPY=1` exists to run the comparison against
a database populated by `bin/victual-db-import` — a genuinely valuable second mode that is
documented in a comment and nowhere else.

**`trigdifftest.php`** is in better shape: eight scripts are committed under
`.devtools/pgsql/trigger-tests/`, it has an `-- @expect-error` convention for asserting
that a trigger rejects something, and it compares every table rather than a named list.
It still needs the same eight-line docker invocation, a `victual_en.db` pristine database
at a path only the operator knows, and a scratch directory.

**The two scripts do not share an environment.** `difftest.php` reads `VICTUAL_ROOT` and
`DIFFTEST_SQLITE_DSN`, `DIFFTEST_PGSQL_DSN`, `DIFFTEST_PGSQL_USER`,
`DIFFTEST_PGSQL_PASSWORD`, `DIFFTEST_SKIP_COPY`; `trigdifftest.php` reads a disjoint
`TRIGTEST_*` set — `TRIGTEST_SQLITE_PATH`, `TRIGTEST_PRISTINE_PATH`, `TRIGTEST_PGSQL_DSN`,
`TRIGTEST_PGSQL_USER`, `TRIGTEST_PGSQL_PASSWORD` — with its own defaults pointing at a
different database name (`victual_trig`) on the same host. So "the environment variables"
is really two namespaces describing two different setups of the same pair of engines, and
reconciling them into one is part of the runner's job rather than a detail of it.

**Neither the image nor the pristine database exists in this repository.** Both
invocations name a `victual-dev` image; there is no `Dockerfile`, no compose file and
no `Makefile` anywhere in the tree, so that image is something the operator built once
from instructions that were never written down. And `TRIGTEST_PRISTINE_PATH` wants a
*migrated* SQLite database, which nothing in the repo can produce from a command line:
`bin/victual-db-import` exits at line 68 when `DB_DRIVER` is sqlite, and migrations
otherwise run only as a side effect of the first `GET /`. Both gaps are inside this
plan's scope; see piece 1.

**Nothing tests the HTTP surface at all.** No test exists — of any kind — that calls an
endpoint and looks at the response. This matters more here than in most codebases,
because of what the review identified as the deeper additive-API risk: nearly every
response is a raw LessQL row or view serialised as-is. **The database schema is the wire
contract**, and there is no tripwire between a migration and a client. Change a view's
column type and the JSON changes; nothing anywhere notices.

The route/spec mismatch is a small illustration, and the story of how it was counted is a
better argument for piece 2 than the mismatch itself. **Corrected 2026-08-29:** this plan
and [11](11-api-error-handling.md) both said there were *two* mismatches pointing in
opposite directions — `/api/openapi/specification` registered at `routes.php:154` and
missing from `victual.openapi.json`, and `/api/recipes/{recipeId}/copy` documented in the
spec with no route behind it. Only the first is real. The copy route exists, at
`routes.php:237`, and has for as long as the recipes controller has had a `CopyRecipe`
method to point at:

```php
$group->Post('/recipes/{recipeId}/copy', [RecipesApiController::class, 'CopyRecipe']);
```

Note the capital `P`. PHP method names are case-insensitive, so Slim registers the route
and it answers normally; a `grep` for `$group->post(` does not see it. It is the only
capitalised method verb in `routes.php` — 124 `get`, 34 `post`, 7 `put`, 4 `delete`, and
this one `Post` — which is exactly the kind of single exception a hand-run extraction gets
wrong once and then nobody re-checks.

The real numbers: the `/api` group registers **87 operations across 74 paths**, the spec
documents **86 across 73**, and the one-item difference is `GET /openapi/specification` in
the routes and not in the spec. The spec has no entry that lacks a route. The earlier
"86 and 86, and the totals hide both" reading was itself the artifact — two errors, one in
the spec and one in the extractor, cancelling to an equal count and thereby looking like
confirmation.

That sharpens what piece 2 has to build rather than weakening it:

- **The extractor is a thing that can be wrong, and must be treated as such.** Match the
  method verb case-insensitively, and assert the operation count the extraction produces
  rather than only diffing the two sets — a route silently dropped on the way in makes the
  comparison agree for the wrong reason, which is precisely what happened here by hand.
- **The assertion stays a two-way set comparison.** Today's live defect points one way
  only, so a one-way "every route is in the spec" check would catch it. The other
  direction — a documented path whose route was deleted — has no live example, but it is
  exactly the defect this plan spent months believing it had, and a check that cannot see
  it is a check that would never have corrected the belief.

**Every tool measures from a state that is copied, not migrated.** `difftest.php`,
`trigdifftest.php` and the rollback phase each populate PostgreSQL with
`bin/victual-db-import` from an already-migrated SQLite database, so every case starts from
a PostgreSQL database whose rows came across from the other engine. Nothing has ever
asserted anything about what `bin/victual-migrate` produces on its own. That blind spot hid a real defect for the whole
life of the port: the PostgreSQL baseline is DDL only, while a third of the migrations it
stands in for also insert rows, so a freshly migrated PostgreSQL database had no admin
user, an empty permission hierarchy and no quantity units — and exited zero. It surfaced
far downstream, as `recipes_pos` refusing an ingredient for want of a quantity unit
conversion, and it was misdiagnosed once as a bad trigger port before anyone thought to
count rows on a database nobody had touched.

The check that closes it is small — migrate on both engines, change nothing, compare every
table — and it is now the suite's first phase (`migratedifftest.php`, run by
`run-tests.sh migrate`). It is listed here because the *shape* of the gap generalises: a
suite that only ever measures states it constructed itself cannot see a defect in how the
state is constructed. Every future phase should be asked which of its inputs it takes on
trust.

**Two facts that shape what the fixtures can be:**

- `DemoDataGeneratorService` is SQLite-only (defect 13 skipped it on other engines rather
  than porting it), so "generate a demo database" is not available as a way to populate a
  PostgreSQL test instance. The fixing pass also found that a *ported* generator would
  still be wrong: it inserts explicit `quantity_units` ids and never calls
  `ResyncGeneratedIdCounters`, so the identity sequence would sit behind the data.
- `difftest.php` has never been run against a recursive CTE. Plans
  [07](07-nested-products.md) and [08](08-nested-locations.md) both introduce one, so
  their fixtures will be new ground for the tool as well as for the schema.

## Proposed change

Three pieces, in dependency order. The first is worth doing even if the other two never
happen.

### 1. A runnable suite around the tools that exist

Two prerequisites come with this piece, because without them "one command from a clean
checkout" is not reachable and verification 6 below is not achievable:

- **An in-repo development environment.** A `Dockerfile` and a `docker-compose.yml`
  authored here: PHP 8.5 with `pdo_sqlite` and `pdo_pgsql`, `composer install` into
  `packages/`, and a PostgreSQL service alongside it. This is what `victual-dev`
  refers to in both existing invocations and it does not exist in this repository — so
  the suite's first requirement is the image the suite runs in. Once it is committed,
  CI uses the same image rather than assembling its own (Q3).
- **`bin/victual-migrate`, pulled forward out of [10](10-cold-start-statelessness.md).**
  `trigdifftest.php` needs `TRIGTEST_PRISTINE_PATH` to point at a *migrated* SQLite
  database and nothing in the repo can produce one from the CLI today
  (`bin/victual-db-import` exits early on SQLite; migrations otherwise run only on the
  first `GET /`). A small CLI entry point that runs the migrations against the
  configured database is the missing link, it is already scoped in 10, and the suite
  cannot build its own fixtures without it. It moves here; 10 keeps the rest of its
  scope — the request-time migration removal, the boot lock and the read-only-root
  work — and simply finds this piece already built when it arrives.

Then `.devtools/pgsql/run-tests.sh` (or a small PHP runner — Q1) that:

- stands up both engines, or connects to already-running ones, with the docker
  invocation and both environment-variable namespaces in the script instead of in a
  README code block — reconciling `DIFFTEST_*` and `TRIGTEST_*` onto one set of
  connection settings is part of the work, not a rename;
- generates the pristine SQLite database with `bin/victual-migrate` rather than expecting
  one at an operator-known path;
- compares a freshly migrated database on each engine against the other, before any
  fixture is applied to either — the one thing no other phase can check, since every one
  of them builds its PostgreSQL side by importing from SQLite;
- runs every committed view seed against its declared view list;
- runs every script in `trigger-tests/`;
- exits non-zero if any comparison differs;
- prints one line per case, not per row, unless something differs.

Alongside it, `.devtools/pgsql/view-tests/` gains committed seed files, mirroring the
existing `trigger-tests/` convention: each seed declares (in a leading comment, parsed by
the runner) which views it exercises. The seeds that were used to verify the PostgreSQL
port exist somewhere in the history of that work; recovering them is cheaper than writing
new ones, and they already cover the interesting cases.

Committed fixture data, not a generated demo database — for the reasons above, and
because a fixture whose contents are visible in the diff is a fixture you can reason
about. What that fixture *is* — a `.sql` seed, a checked-in SQLite file, or the seed
importer from [04](04-seed-datasets.md) — is Q2.

### 2. Response-contract snapshot

A script that, against a booted instance seeded with the same fixture:

- calls every operation in the route table (87, across 74 paths) with a valid key;
- records, per route, the **JSON key set** and the **scalar type of each value** — not the
  values themselves, which are fixture-dependent and would make the snapshot brittle;
- compares that against the OpenAPI schemas, and against the previous snapshot;
- runs against both engines and requires them to agree.

Values are deliberately out of scope. The engines are already known to disagree on float
accumulation order and on `chores.start_date` rendering; those are documented accepted
differences in `db/pgsql/README.md` and a value-comparing snapshot would fight them
forever. Key sets and types are the actual contract, and are what a migration silently
changes. Q4 covers whether the two accepted differences need an explicit exemption even
at the type level.

The comparison is five-way once piece 2 lands, and each leg catches something different:

| Comparison | Catches |
|---|---|
| snapshot vs previous snapshot | a migration that changed a response |
| snapshot vs OpenAPI schema | a response that never matched its documentation |
| engine vs engine | a port that diverged on the wire |
| Admin snapshot vs restricted-identity snapshot | a field that should be redacted and is not, or one redacted that should not be — asserted as *equal minus exactly the `x-visibility` keys*. The only leg that requires a difference rather than forbidding one. |
| schemas + snapshot bodies vs the sensitive-field vocabulary | a price or cost field nobody classified — the leak the leg above is structurally blind to, since an unannotated field is identical for both identities |

**Piece 2 runs per identity, not once.** [19](19-rbac.md) makes response *content* a
function of the caller, so "a valid key" no longer describes the contract. Every operation
is called twice against the same fixture — once as Admin, once as a fixture user without
`STOCK_PRICES_VIEW` — and the restricted snapshot is asserted to equal the
Admin one with exactly the `x-visibility`-annotated keys removed. Both legs are needed,
because a field absent for *everyone* satisfies the restricted leg exactly as a correctly
redacted one does, and only the Admin leg shows it was there to redact.

**What the double snapshot does not do is catch an unclassified field**, and 19's first
draft claimed it did — that a new path returning `price` without the annotation fails the
diff. It passes: with no annotation and no policy row nothing redacts the field, both
identities receive it, and "Admin minus the annotated fields" still equals the restricted
response. The diff polices fields somebody has already classified; an unclassified leak is
its blind spot. So this piece carries a **second, independent assertion — a completeness
check** — that walks the OpenAPI schemas *and* the recorded snapshot bodies for field names
in the price/cost vocabulary (`price`, `cost`, `value`, `amount_paid`, and their prefixed
and suffixed forms) and fails on any that carries neither `x-visibility` nor an explicit
`x-visibility: none` with a stated reason. The snapshot bodies are in scope alongside the
schemas because the hand-built responses are not all schema-backed. It is cheap, it is the
leg that actually generalises to a future endpoint, and its allow-list of deliberate
exceptions is the deliverable rather than a by-product.

That restricted user holds the `STOCK` **leaves** (`STOCK_CONSUME`, `STOCK_OPEN`, …) and
not `STOCK` itself: `STOCK_PRICES_VIEW` hangs under `STOCK` and the tree resolves
downward, so a user holding the parent inherits the leaf and would make the restricted leg
identical to the Admin one. A fixture that silently proves nothing is worse than no
fixture, so the seed states the grant as leaves and says why.

The costs are a second key, a second user in the committed seeds, and a doubled set of
golden files — question 6's churn twice over, accepted for the same reason it was accepted
there. 19's own upgrade verification additionally needs a *pre-migration* fixture holding
`STOCK` directly, which is a third seed rather than a fourth snapshot leg. Build this
harness here rather than in 19: it is an extension of the snapshot mechanism, not a
consumer of it, which is why 19's piece 2 is scheduled alongside this piece rather than
after it.

The one real route/spec mismatch gets fixed here — `/api/openapi/specification` added to
the spec — along with a route-table-vs-spec parity assertion written as a two-way set
comparison so the next one in either direction cannot survive. `/api/recipes/{recipeId}/copy`
needs nothing: it is in both, and the belief that it was not is what the extractor
requirements above exist to prevent. The assertion has to land with the fix rather than
before it: it fails from the moment it exists.

### 2b. The read surface has to be complete before it is frozen

Piece 2 freezes the API's response contract. That is only worth doing to a surface that
covers what its consumers need, and a measurement taken on 2026-08-29 says this one does
not yet — not because the API is small, but because the web UI has never been a consumer
of it for reads.

**What was measured.** Every non-API controller was inventoried: 12 files, 81 route
handlers, 71 rendered templates, 173 direct `$this->DB->` call sites across 34 distinct
tables and views, plus eight more reached only through raw SQL. Each of those was then
classified against the API — is it an `ExposedEntity`, is there a dedicated endpoint, and
if so does that endpoint actually return the same shape.

**Most of it is genuinely covered, and by more than name.** All the master-data list and
form pages, the chores, batteries and tasks pages, recipes and the meal plan, and the
shopping list are reachable. Several look like gaps and are not: `chores_current`,
`batteries_current`, `tasks_current`, `recipes_resolved` and `stock_missing_products` are
each read by an API endpoint that calls **the same service method** the page controller
calls, so the parity is real. `cache__quantity_unit_conversions_resolved` is the exposed
`quantity_unit_conversions_resolved` view under its materialized name. The chores and
batteries journals do their joining in the template from raw log rows plus lookup lists,
which is exactly what a client would do.

**Eight things are not covered**, and they are the ones that would have to be discovered
by a client author rather than by a planner:

| Page or need | Status | What is actually missing |
|---|---|---|
| Stock overview | **Nominal coverage only** | `GET /api/stock` returns `stock_current` plus a raw `products` row. The page renders `uihelper_stock_current_overview`, which joins `products_view` — and `products_view` is not exposed. Most of the difference is reconstructable from about eight calls, but `calories`, `calories_aggregated` and the two `quick_*_amount_qu_consume` columns require reimplementing that view's quantity-unit conversion joins in the client |
| Stock entries | Same `products_view` gap | Plus there is no "all stock entries across all products, pre-joined" call — only per-product or per-id |
| Stock journal | No endpoint at all | `uihelper_stock_journal` has no route. Reconstructable from four collections joined client-side |
| Stock journal summary | **Unrenderable** | `SUM(...) GROUP BY` in the view, and the generic endpoints have no aggregation at all — only filter, sort, limit, offset. The only alternative is shipping the whole `stock_log` to the client |
| Location content sheet | N+1 | `stock_current_location_content` is not exposed; the client would call the per-product locations route once per product |
| Spendings report | **Unreachable** | `products_price_history` is in no enum and behind no route |
| Calendar | Wrong shape | Only the iCal export exists; a JSON event feed does not |
| User permissions page | Shape *and* permission mismatch | The API returns raw unresolved rows and requires `ADMIN`; the page renders the `permission_hierarchy`-joined resolved shape and requires only `USERS_READ` |

**The smallest set that closes it**, which is the piece-2-adjacent work: expose
`products_view` or fold its `qu_factor_*` and `has_sub_products` columns into the
`products` payload (fixes the first two rows at once); a stock journal read; an
aggregation answer for the summary; `stock_current_location_content`; a decision on
`products_price_history`; and a JSON calendar feed.

**Why this belongs to 14 rather than to a plan of its own.** The ordering is the whole
point: a snapshot taken before the surface grows freezes an incomplete contract and then
makes every addition a snapshot change, which is exactly the churn piece 2 exists to
prevent. So grow the surface, then freeze it. Three of the entries above are also
`ExposedEntity` decisions rather than code, and the additive-API ground rule says those
are argued explicitly rather than slipped in.

Two of them are not merely technical and should not be decided by whoever writes the
endpoint. **`products_price_history`** is the household's purchase history and exposing it
is a deliberate widening, not an oversight to correct. **The permissions page mismatch**
may be intentional — loosening a resolved-permission read from `ADMIN` to `USERS_READ`
has consequences the server-rendered page's stricter phrasing might have been protecting.
Both want a recorded answer, in the manner of this roadmap's other open questions.

> **The permissions one is deferred to [19](19-rbac.md)**, which landed as a plan on
> 2026-08-30 and carries it as its own question 9. It is not a missing endpoint but a
> question about who may see the permission model, and answering it here would fix a number
> in one view while the model it reflects is being redesigned. 19's API section states the
> rule it wants for its own new role endpoints — read behind `USERS_READ`, write behind
> `USERS_EDIT` — and the obvious extension is to apply it to this endpoint too, which wave
> 2 may do rather than wait. But 19 has not recorded that decision: its API block still
> lists `GET /users/{id}/permissions` as "unchanged shape". So this is an extension
> available to be taken, not an answer already given. What this section still owes regardless:
> whatever that plan lands on has to be reachable through the API before piece 2 freezes
> the contract, since the permissions page is one of the eight, and 19's piece 1 is
> scheduled in wave 3 for exactly that reason.
>
> **`products_price_history` is answered by the same plan**, which this section could not
> know when it recorded the widening as open. 19's `STOCK_PRICES_VIEW` leaf is what makes
> exposing it safe: the endpoint is refused outright for a caller without the leaf rather
> than returned empty. The widening is still a decision, but it is no longer an unbounded
> one.

**Caveat on the measurement.** The view definitions were read from the SQLite migration
files, taking the highest-numbered migration touching each view as authoritative. The
PostgreSQL definitions in `db/pgsql/` were not cross-checked, and this fork has real
per-engine divergence elsewhere — so the column lists above want a spot-check against the
PostgreSQL side before anyone builds from them.

### 3. Minimal CI

`php -l` over every `.php` file, `node --check` over every `.js` file, and the suite from
piece 1. That is the whole of it. Lint is nearly worthless on its own — it is in here
because it is free and because it catches the one class of mistake (a syntax error in a
file nobody exercised) that costs a deploy. The differential suite is the part that
matters, and it needs a PostgreSQL service container, which is the only real question
about the CI shape (Q3).

Piece 2 in CI needs a booted instance, not just two databases, which is a step up in
complexity. It may be right to run it locally first and add it to CI once it has proven
stable.

### 4. Coverage of the suite itself (added after the plan was written)

Not part of the original plan and not a fourth kind of test: a way to see what pieces 1
and 3 actually reach. `SUITE_COVERAGE=1` on the runner hooks every PHP process the run
spawns and prints a line-coverage summary at the end; CI does this on every run and keeps
the Clover file. Nothing is gated on the number.

The reason it is worth having here specifically is the blind spot this plan's own suite
had, and which cost three PostgreSQL defects to find: the view and trigger phases drive
SQL at each engine and never enter application code, so for a while nothing in the suite
executed a single line of `StockService` against PostgreSQL and no report said so. A
coverage figure would have. See [.devtools/coverage/README.md](../../.devtools/coverage/README.md).

**A fourth defect of exactly that shape was found later, and it is the argument for piece
2 covering behaviour and not only shape.** `db/pgsql/README.md`'s hazard 16: the `~`
operator of the generic list filter emitted `LIKE`, which is case-insensitive on SQLite and
case-sensitive on PostgreSQL, so `?query[]=name~milk` returned "Milk" on one engine and not
the other. Nothing in the suite could see it — the SQL never appears in a view or a trigger,
it is assembled in `BaseApiController`, and the response *shape* is identical either way,
so even piece 2's key-set-and-type snapshot would pass on both engines while the row sets
differ.

It has since been fixed, and the fix brought a fifth phase with it, because there was
nowhere in the suite to put a regression test for it. **`run-tests.sh filter`**
(`filterdifftest.php`) asks each dialect for the condition it emits for `~` and `!~`, runs
both against their own engine and compares the rows. It is the first phase that compares
*application* behaviour rather than SQL, and it was verified the mutation-shaped way this
plan's Verification section asks for: the defect was put back and the phase failed on three
ASCII cases (`[1,2] vs [2]`).

It also demonstrates the thing this plan keeps having to relearn about its own suite — that
a phase is only as good as what it takes on trust. The condition under test is not written
out in the test; it is fetched from `GetLikeCondition()`, so a future change to the dialect
is caught rather than mirrored into the fixture. And the non-ASCII case is asserted
*directionally* (PostgreSQL may fold more than SQLite, never less) rather than exactly,
because which characters fold is a property of the database's collation: an exact assertion
would fail on a `C`-locale database for something that is not a defect. A test that has to
be loosened later is worse than one that states the invariant it actually has.

**And it paid for itself before it was even committed.** The phase grew a second half —
comparing the two engines' verdicts on which columns the `~` operator may be used on, across
every column of every shared table and view — and that half immediately failed on 13
columns nobody had thought about: SQLite's `PRAGMA table_info` reports an empty type for a
view column that is a computed expression, where PostgreSQL resolves it. Those 13 would
have shipped as a silent per-engine difference on exactly the columns most worth matching
(`stock_missing_products.name`, `users_dto.display_name`). They are now listed by name on
every run instead. That is the whole argument for behavioural phases in one incident: the
defect was in the *fixture-free* part of the system, over real schema, and no snapshot of
response shapes would have contained it.

**What is still owed to piece 2** is the general case. The `filter` phase covers one
operator pair on one code path; the snapshot still needs cases that compare the **rows** a
parameterised endpoint returns, not only the shape of them, for the rest of the
`query[]`/`order` surface. That surface is one code path serving every entity, and it is
the only place in the tree where the two dialects can disagree about a *result set* rather
than a value.

### Schema

None. This plan adds no migration and touches no view. It does add fixture SQL, which is
not schema and does not ship in the image, and a `Dockerfile`/`docker-compose.yml` for
the development and CI environment, which is not schema either.

If Q2 lands on reusing [04](04-seed-datasets.md)'s importer for fixtures, that creates a
dependency in the other direction — 04 would need building first — which is an argument
against it for now.

### API

**No change to any endpoint**, with one exception: `victual.openapi.json` gains the missing
`/api/openapi/specification` path, and gains documented error responses for whatever
[11](11-api-error-handling.md) has converted by then. Adding a path to the spec is
additive by definition and changes no response. Nothing is removed from the spec — the
earlier plan to drop `/api/recipes/{recipeId}/copy` rested on a miscount and would have
deleted the documentation of a working endpoint.

The plan's *purpose*, though, is API compatibility: after it exists, "existing endpoints
keep their response shape" — the second ground rule in this README — is a test that fails
rather than a rule that gets remembered.

`info.version` in the spec is the literal `"xxx"` and is fixed here too, with the path
addition — a placeholder in a document a contract suite now reads is its own small
absurdity. What version string it should carry is [17](17-ecosystem-clients.md)'s Q1 and
is still open; if Q1 has not answered by the time piece 2 lands, the spec takes
`version.json`'s value, which is at least a fact.

**Client impact: none directly, and this plan is where every other plan's becomes
visible.** Nothing here changes a response. Piece 2 is the mechanism
[17](17-ecosystem-clients.md)'s item 1 asks for — client endpoint and field manifests
asserted against the snapshot, so a plan that moves a route or a status code fails CI with
the client named. 17's own post-mortem on 16 is the argument for widening that from paths
to **request headers and response keys**: neither of 16's two breaks was a path change, so
a path manifest would have passed while both clients 401'd.

## Verification

A test suite whose own correctness is unverified is worse than no suite, because it
produces confident green output. So the verification here is mutation-shaped: break
things deliberately and confirm the suite notices.

1. **The view suite catches a real regression.** Change one baseline view on the
   PostgreSQL side in a way that alters output — a `CAST(x AS INT)` where SQLite
   truncates and PostgreSQL rounds is hazard 7 in `db/pgsql/README.md` and is a realistic
   mistake — and confirm the runner exits non-zero and names the view. Revert.
2. **The trigger suite still passes unchanged.** All eight existing scripts must produce
   identical results through the new runner as through the current manual invocation. If
   the runner changes any of them, the runner is wrong.
3. **The migration phase catches a missing seed.** Remove the initial-data seeding from
   `DatabaseMigrationService` and confirm the phase exits non-zero and names every table
   that differs. Done: it reports seven, `users` and `permission_hierarchy` among them.
   Revert.
4. **The runner fails loudly on a missing fixture.** `trigdifftest.php` already refuses to
   report "identical state" for a script it could not read; the new runner must have the
   same property for seeds and for a database it could not connect to. A suite that
   silently skips is the failure mode to design against.
5. **The contract snapshot catches a schema change.** Add a column to a view that is
   exposed through an endpoint, regenerate, and confirm the diff shows exactly that key
   appearing on exactly that route. Then change a column's type and confirm the type diff
   shows. Revert both.
6. **The contract snapshot survives the accepted differences.** Run it against both
   engines with the fixture and confirm a clean result — `products_average_price.price`
   and `chores.start_date` must not produce a diff. If they do, Q4's exemption mechanism
   is needed rather than optional.
7. **The whole suite runs from a clean checkout** on a machine that has not run it before,
   with only the documented prerequisites, in one command. This is the actual acceptance
   criterion; everything above is secondary to it. If it takes two environment-variable
   namespaces, an image nobody has the recipe for and a scratch directory that only one
   person knows the path to, it has not been built. The Dockerfile, the compose file and
   `bin/victual-migrate` from piece 1 are what make this check passable at all.
8. **Recursive CTE coverage.** Add a throwaway fixture with a three-level product tree and
   a recursive view over it, and confirm the runner compares it correctly on both engines.
   This is a check on the *tool*, not on the schema — [07](07-nested-products.md) and
   [08](08-nested-locations.md) depend on it working and it has never been exercised.

## Sequencing

**Highest leverage of the hardening plans, and the one with the most downstream
dependents.** Three separate things in the roadmap already assume it exists:

- [07](07-nested-products.md)'s "Verification" section says to extend `.devtools/pgsql/`
  with a three-level-tree fixture *before touching anything*. That is this plan.
- The review comments in [08](08-nested-locations.md) make the same point, and the
  roadmap's order of operations puts the fixture suite before the recursive-hierarchy
  work generally.
- [02 MCP](02-mcp-endpoint.md) is the reason the response-contract snapshot is worth
  building at all: it puts a second consumer on an API whose wire format is an accident of
  the schema.

**Piece 2 after the read surface grows**, per 2b above. This is a new constraint and it
is the one most likely to be missed, because nothing about the snapshot script depends on
it — the script works fine against an incomplete surface and freezes it just as happily.

**Before [11](11-api-error-handling.md)**, if both are being done. 11 deliberately changes
status codes on failure paths across 87 operations, and its own verification section is two
full-surface sweeps. Building the sweep here and letting 11 present its changes as a diff
is strictly better than 11 asserting them by hand.

**After [10](10-cold-start-statelessness.md)** is mildly preferable but not required — a
suite that boots an instance is simpler to write once the first request is not a redirect.
One piece of 10 does move earlier, though: `bin/victual-migrate` comes forward into piece 1,
because the trigger suite needs a migrated SQLite database it can build itself. 10 keeps
everything else it owns and inherits a working migrate command when it starts.

**It blocks no feature plan today**, but it is the fixture home that
[07](07-nested-products.md) and [08](08-nested-locations.md) both plan against, and the
enforcement mechanism the README's additive-API ground rule currently lacks. The first
shipped dual-engine migration should still be a small one
([06](06-location-barcodes.md), per the review) — this suite is what will tell you whether
it worked.

## Open questions

1. **Bash runner or PHP runner?** Bash is the obvious shape for "start containers, loop
   over files, collect exit codes" and adds no dependency. PHP means the runner can share
   `difftest.php`'s normalisation logic and can parse the seed headers properly rather
   than with `grep`. I lean to a thin bash script that orchestrates and PHP that compares,
   which is roughly what exists already — the missing part is only the orchestration.

   > **Response:** Agreed — thin bash orchestrator, PHP comparator; seed-header
   > parsing belongs in PHP, not grep.
2. **What is the fixture?** Committed `.sql` seeds are the most legible and match the
   existing `trigger-tests/` convention. A checked-in SQLite file is faster to load and
   harder to review. [04](04-seed-datasets.md)'s name-keyed importer would be the most
   reusable but does not exist yet, and building this plan on an unbuilt one is backwards.
   I lean to committed `.sql` seeds now, with the option to regenerate them from 04's
   importer later. Note that the demo data generator is *not* an option on PostgreSQL.

   > **Response:** Committed `.sql` seeds, and agreed on not building this on 04's
   > unbuilt importer. Regenerating seeds *from* 04 later stays attractive precisely
   > because committed SQL keeps the current fixtures legible in the meantime.
3. **Does CI get a PostgreSQL service container, and where does CI run?** There is no
   `.github/workflows/` directory today — the fork has never had CI. A service container
   is standard and free on GitHub Actions. The question is really whether CI runs there at
   all, given this is a personal fork deployed to a private k3s cluster; a git hook or a
   local `make check` may be the honest answer, in which case "minimal CI" means "one
   command a human runs" rather than a workflow file.

   > **Response:** Firmer than the hedge: GitHub Actions, with a PostgreSQL service
   > container. This fork's workflow is already PR-driven, and a suite that runs on
   > every PR without local discipline is worth *more* to a solo maintainer — there
   > is no second person to catch the skipped local run. Keep the runner as the
   > local entry point; the workflow file just calls it. Piece 2 stays local until
   > stable, as planned.
   >
   > On the image: an earlier draft of this answer said the workflow would build and
   > run the in-repo `Dockerfile` so that CI and local use one environment by
   > construction. It does not, and should not. A runner already has PHP and
   > PostgreSQL, so building the image on every pull request costs minutes and buys
   > no coverage; the image exists so a contributor can run the suite without
   > installing either by hand. What keeps the two honest is not a shared image but
   > a shared entry point — both call `.devtools/pgsql/run-tests.sh`, so there is
   > one definition of what the suite is. The cost is real and worth naming: CI runs
   > PHP 8.4 (the fork's floor) against the image's 8.5, so a version-specific
   > difference would only show on one of them.
4. **Do the two accepted engine differences need an exemption at the type level?**
   `products_average_price.price` differs in the last bit, which is a value difference, not
   a type one — so probably not. `chores.start_date` differs in *rendering* of the same
   string type — also probably not. But `qu_factor_*` in `products_view` is documented as
   SQLite returning the string `"1.0"` where PostgreSQL returns the number `1`, which **is**
   a type difference and would fail a type-level snapshot. That view is not in
   `ExposedEntity` and may not be reachable from any route — worth confirming before
   designing an exemption mechanism nothing needs.

   > **Response:** The reachability check is done, and the answer is no: build no
   > exemption mechanism — but not for the reason first recorded here, which was
   > wrong and worth correcting because the decision rests on it.
   >
   > The claim was that none of the views is in `ExposedEntity`, so no route can
   > return the column. Being outside `ExposedEntity` only rules out the generic
   > `/objects/{entity}` route; a hand-built response can select from any view.
   > `uihelper_product_details` does exactly that: `services/StockService.php:1076-1077`
   > returns its `qu_factor_purchase_to_stock` and `qu_factor_price_to_stock` as
   > `qu_conversion_factor_purchase_to_stock` and `qu_conversion_factor_price_to_stock`
   > on `/stock/products/{productId}`. Those columns are safe because that view has
   > always cast them, not because nothing reads them.
   >
   > So the real reason no mechanism is needed is that after
   > `migrations/0256.sqlite.sql` there is no type difference left to exempt: every
   > view exposing `qu_factor_*` now casts. The
   > standing rule if a type difference ever does surface on a reachable route: the
   > porting rules say that is a port bug — fix the view (a `CAST`), don't exempt
   > it. An exemption mechanism is where wire-format bugs go to become permanent.
5. **How much of the API does the contract snapshot cover?** All 87 operations is the
   complete answer and is mostly mechanical, since 40-odd of them are the generic
   `/api/objects/{entity}` shape. The stock, recipes and chores endpoints are the ones
   with hand-built responses and the ones where a change is most likely — starting there
   and expanding is a reasonable staging, as long as the gap is recorded rather than
   forgotten.

   > **Response:** All 86 from the start. The generic 40-odd are one loop over the
   > entity enum, so full coverage is nearly free; staging by hand-built-first is
   > fine as implementation order within one sitting, not as a scope decision.
6. **Are snapshots committed, or generated and compared in-place?** Committed golden files
   make the diff show up in code review, which is the whole point — a reviewer sees "this
   PR changes the shape of `/api/stock`" without running anything. They also add churn and
   invite blind regeneration. I lean to committed, with the regeneration command named in
   the failure message so at least the blind regeneration is deliberate.

   > **Response:** Committed golden files — the diff appearing in the PR is the
   > feature, and naming the regeneration command in the failure message is the
   > right mitigation. There is no better one.
7. **Where do the original difftest seeds live?** The PostgreSQL port was verified with
   seeds that are not in the repository. Recovering them from that work's history is much
   cheaper than rewriting, and they encode which views matter. If they are genuinely gone,
   the effort estimate below roughly doubles.

   > **Response:** Timebox recovery to an hour. The eight trigger-tests plus the
   > README's documented hazards are map enough to rewrite from — bounded
   > work, not doubled. Don't let archaeology block piece 1.

## Executed

Wave 0, landed 2026-08-27 to 2026-08-29. Pieces 1, 3 and 4; piece 2 is untouched.

- **`40e1f57f` — the dev/CI environment and the migration CLI.** The `Dockerfile` and
  `docker-compose.yml` this plan's Today section says do not exist now do, and
  `bin/victual-migrate` is the CLI pulled forward from
  [10](10-cold-start-statelessness.md) so `trigdifftest.php` can be handed a migrated
  SQLite database from a command line. That inversion — 10's CLI preceding 14 — is
  recorded in the roadmap's order of operations as the one place the wave order overrides
  the plan numbering.
- **`d80a88f0` — the suite made runnable.** `.devtools/pgsql/run-tests.sh`, committed
  fixtures under `fixtures/` and `view-tests/`, and the `normalise()` extraction this plan
  promised [13](13-write-path-transactions.md) it would make reusable, now
  `services/Database/ValueComparison.php` — which 13 duly consumed rather than duplicating.
- **`fd506a85` — CI.** `.github/workflows/tests.yml`: lint plus the suite, with a
  PostgreSQL service container, which was Q3's open question and is now answered by
  a working workflow.

Three things the suite grew that the plan did not ask for, each because the plan's own
"ask every phase which of its inputs it takes on trust" test found a gap:

- **`31401f0`** added `.devtools/pgsql/check-migrations.php`, which enforces the
  `@engine-exclusive` and `@overrides-generic` markers `db/pgsql/README.md` defines.
- **`4ae6990`** added `migratedifftest.php`, the migrate-on-both-engines-and-compare phase
  described in Today, which immediately found the defect Today describes: a freshly
  migrated PostgreSQL database with no admin user, an empty permission hierarchy and no
  quantity units.
- **`d2524a3`** committed the rollback tests, and **`36a3032`** added the coverage
  reporting that is piece 4.

**Piece 2, landed 2026-09-17** ([issue 83](https://github.com/datagen24/victual/issues/83),
wave 5): the response-contract snapshot, written in tier 1 from the start per
[ADR-0025](../adr/0025-three-test-tiers.md) decision 4, as `tests/Pgsql/ContractTest.php`
on `PgsqlSchemaTestCase` - a `contract` phase in `run-tests.sh` and `phpunit.xml`
alongside `rbac`, the pattern decision 3 set. Piece 2b (growing the read surface) needed
nothing further: plans 28 and 31 and 19 piece 2 had already closed the eight gaps its own
2026-08-29 measurement found.

The route-table-vs-spec parity assertion is a two-way set comparison over the live Slim
route table (`tests/Support/RouteInventory.php`, which boots `routes.php` the way
`.devtools/check-path-id-validation.php` already did rather than trusting a second regex
extractor), fixing the one real gap plan 14 found by hand: `/api/openapi/specification`
is now in `victual.openapi.json`, alongside the `info.version` placeholder (`"xxx"` →
`version.json`'s `4.6.0`, per this plan's own fallback rule - [17](17-ecosystem-clients.md)
Q1 is still unanswered). R1 (`/system/config` keeps `FEATURE_FLAG_STOCK`) is one assertion
in the same class.

The snapshot itself builds one fixture graph as Admin through the real write endpoints -
so the writes are exercised too - covering every non-label operation: master data,
stock (add/consume/transfer/inventory/open/measure/weigh/merge, both by id and by
barcode, undo on both a booking and a transaction), recipes (including consume and
copy), chores (execute, undo, merge, next-assignment calculation), batteries, tasks,
users and roles, a file upload/serve/delete round trip, and the generic `/objects/{entity}`
sweep over every `ExposedEntity` the label subsystem doesn't own. Two real defects
surfaced building it, on a route the fixture graph was the first thing to call with real
event data: `CalendarApiController::Ical` called `$response->write()`, a method
`Psr\Http\Message\ResponseInterface` does not have, and handed the iCal library's
`Presentation\Component` object to `getBody()->write()` unstringified once that was fixed
too. Both are one-line fixes, in this same change.

The comparison is four-way, not the five the plan describes - "engine vs engine" is not
attempted, because ADR-0008 already retired SQLite as a runtime engine, so there is only
one engine left to boot the application on; the differential harness's SQLite side is
what used to stand in for that leg, and stays until its own retirement (still gated on
this piece having landed, not performed by it - see the wave 5 status line):

1. **Snapshot vs previous snapshot.** Two committed golden files,
   `tests/Pgsql/snapshots/contract-{admin,restricted}.json`, each the JSON key set and
   scalar type of every operation's response (never values, per the plan) -
   `CONTRACT_REGEN=1 .devtools/pgsql/run-tests.sh contract` regenerates them, named in
   the failure message, per Q6's response.
2. **Snapshot vs OpenAPI schema.** The schema-completeness leg below subsumes this for
   the vocabulary that matters; a full per-operation schema diff over all 145 operations
   was judged not worth building given how much of the surface has no schema at all
   (hand-built responses `BaseApiController::GetOpenApispec()`'s own callers read
   selectively) - a gap worth naming rather than hiding.
3. **Admin vs restricted.** The restricted identity is the existing CHILD role
   (`db/pgsql/roles-seed.sql`), not a new fixture - CHILD already holds exactly the
   shape the plan asks for, `STOCK_VIEW`/`STOCK_CONSUME`/`STOCK_OPEN` and the other
   domain `*_VIEW` leaves, never bare `STOCK` or `STOCK_PURCHASE`, so it cannot inherit
   `STOCK_PRICES_VIEW` the way a parent-holder would. Restricted is asserted to equal
   Admin minus exactly the fields `permission_fields` redacts for CHILD, computed from
   the live table (never a hand-maintained list, matching `FieldPolicy`'s own docblock)
   and cross-checked in both directions: every policed field the fixture graph reaches
   must actually be missing from the restricted response, and every restricted 200/403
   split must be one of those two codes and nothing else.
4. **Schema and snapshot bodies vs the sensitive-field vocabulary.** The completeness
   leg 3 is structurally blind to (`price`, `cost`, `value`, `amount_paid` and their
   prefixed/suffixed forms) walks both the recorded Admin bodies and every OpenAPI
   schema property, failing on a match with neither an `x-visibility` annotation (schema-
   or property-level - `ProductPriceHistory`'s own schema carries it once rather than
   its `price` property, and its description said "see the operation", which was wrong
   until this change fixed the sentence to match the code) nor a `permission_fields`
   row. Verified the way the plan's own Verification section asks: `FieldPolicy::RedactRow`
   was mutated to redact nothing, and this leg named all nine leaked fields
   (`value, costs, costs_per_serving, prices_incomplete, last_price, avg_price,
   oldest_price, current_price, stock_value`) before the mutation was reverted. Seven
   field names the regex matches but that are not amounts at all (`qu_id_price`,
   `default_purchase_price_type`, `quantity_unit_price`, `qu_conversion_factor_price_to_stock`,
   `price_factor`, `stock_auto_decimal_separator_prices`, `default_value`) are the
   allow-list this leg's own docstring says is the actual deliverable, each with why in
   `tests/Pgsql/ContractTest.php`.

**What is deliberately not covered**, named rather than silently skipped: the label
pairing/worker/renderer/template-admin operations and the thirteen label-subsystem
`ExposedEntity` rows behind them need real device credentials, paired worker crypto
material or a rendered artifact this harness cannot manufacture - route/spec parity
still covers their wire shape, the same division plan 25/27's physical verification
already drew. `StockApiController::ExternalBarcodeLookup` is excluded because it calls a
configured plugin/network endpoint, which is sweep finding S14's surface and not
something a contract test should give a target.

**What this piece does not close, contrary to the issue's own text**: S15 (regex filter
pattern bounds) and S16's remaining half (a schema-derived write allow-list for the
generic entity controller, 11's Q5) are both real, separately-scoped pieces of work -
input validation hardening, not response-contract testing - and are not attempted here.
Both stay open, tracked where they already were.

**And one documentation debt this plan owns because it owns the suite.**
`db/pgsql/README.md` still describes the runner as `[migrate|views|triggers]` and still
says "the other three all populate PostgreSQL by copying". Both were true when they were
written and neither is now: `rollback` migrates PostgreSQL independently, `filter` compares
application behaviour rather than SQL, and there are five selectors. The rigor review
caught it at four (its B6) and it has drifted one further since. Whichever change next
opens that file fixes the two sentences; it is a doc edit outside `docs/`, which is the
only reason it is recorded here rather than done.

## Effort

Medium, with a wide range depending on Q7. Piece 1 is half a day if the seeds are
recoverable and two days if they must be written from scratch, plus most of a day for the
two prerequisites it now carries — the Dockerfile and compose file, and `bin/victual-migrate`
brought forward from [10](10-cold-start-statelessness.md). That is still the single
best-value item in the whole hardening set, because it is what makes every subsequent
plan's verification section actually runnable, and the dev environment is reused by every
one of them.

Piece 2 is the larger piece and the more interesting one: a day for the harness, plus the
per-route work, plus the schema comparison. Piece 3 is an hour once piece 1 exists.

Worth splitting: land piece 1 on its own and use it. Piece 2 can wait until
[11](11-api-error-handling.md) or [02](02-mcp-endpoint.md) makes it urgent, but not later
than either of those.
