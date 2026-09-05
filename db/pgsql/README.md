# PostgreSQL support

PostgreSQL is the only engine Victual runs on, since
[ADR-0008](../../docs/adr/0008-postgresql-only-runtime-engine.md)'s retirement landed
([plan 24](../../docs/plans/24-sqlite-runtime-retirement.md)). This directory holds what an
installation needs.

SQLite is still all over the pages below, and deliberately so: this is where the porting
work is written down, and the two things that keep needing it are `bin/victual-db-import`,
which reads SQLite as an input format, and the differential suite, which still builds a
SQLite side to prove the port did not change behaviour. Both are read-only uses of an engine
nothing here serves from.

## Layout

    baseline/01_tables.sql        37 tables
    baseline/02_indexes.sql       11 indexes
    baseline/03_functions.sql     victual_user_setting() and its defaults table
    baseline/03_views_group*.sql  views with no dependencies on other views
    baseline/04_views_*.sql       views layered on top of those
    baseline/06_triggers_*.sql    the SQLite triggers as PL/pgSQL

`baseline/` is DDL only. Together with `services/Database/InitialDataSeeder.php` it
reaches the state SQLite reaches after migrations 0001-0255 - schema and rows both.
PostgreSQL installations load the pair once instead of replaying a migration history they
were never part of; `DatabaseMigrationService` then records migrations 1-255 as applied
and continues from 0256 onwards.

## Both halves, or the database is not usable

The seeder is not an optional extra. A third of the migrations the baseline stands in for
insert rows as well as changing the schema: `0027.php` creates the admin account,
`0031.php` the default quantity units and location, `0062`/`0063` the default shopping
list, `0110.sql` the thirty-row permission hierarchy, `0149.sql` the internal meal plan
section. Recording them as applied without running them leaves a PostgreSQL database that
migrates successfully, reports itself up to date, and has nobody who can log into it.

It also degrades quietly rather than loudly. With no rows in `quantity_units`, the final
join in `quantity_unit_conversions_resolved` matches nothing, so the view is empty for
every product, so `products_ins` copies nothing into
`cache__quantity_unit_conversions_resolved`, and anything resolving a quantity unit fails
somewhere far from the cause - the report that led here was `recipes_pos` rejecting an
ingredient with "Provided qu_id doesn't have a related conversion for that product". The
trigger was faithful; it had nothing to copy.

Two consequences worth knowing about the seed data:

- **It has to be PHP, not SQL in `baseline/`.** Four of the six names are translated
  through `LocalizationService` into `VICTUAL_DEFAULT_LOCALE` (Piece, Pack, Fridge,
  Shopping list) and the admin password is hashed with a fresh Argon2id salt per
  installation. None of that can be a literal in a `.sql` file.
- **Some of the ids are historical accidents, reproduced deliberately.** `0006.sql` seeds
  a placeholder location and quantity unit which `0021.sql` deletes, so the real defaults
  land at location 2 and quantity units 2 and 3 with nothing at id 1. That gap is load
  bearing: `migrations/8888.php` creates a location with the literal id 1 when
  `FEATURE_FLAG_STOCK_LOCATION_TRACKING` is off, and would find id 1 already taken if
  PostgreSQL had numbered "Fridge" from 1.

## Supported ways to get a PostgreSQL database

Both, and they are checked:

    php bin/victual-migrate          a new installation, from nothing
    php bin/victual-db-import        an existing SQLite installation, moved

`bin/victual-db-import` calls `MigrateDatabase(false)` - schema, no seed - because it is
about to fill the database from the source and every seeded row would be one it replaces.
That also keeps its "target already contains data" check meaning what it says. Migrating
first and importing afterwards therefore needs `--force`, and the error message says so.

The source has to be within the supported span,
`DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN` through `SUPPORTED_SOURCE_MIGRATION_MAX`
(0255-0265); outside it the command refuses and names both numbers. Committed fixtures at
each end live in `.devtools/pgsql/fixtures/import/`, are rebuilt by `make-fixtures.sh` there,
and are what `run-tests.sh import` asserts against - the check that replaces the second
engine now that nothing here produces the input format.

**Migrations above 0265 are PostgreSQL-only.** The SQLite line is frozen at
`DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID` = 265: nothing here migrates a SQLite
database past that number, so a `NNNN.sqlite.sql` above it would be a file no engine can run
and no importable source can have applied. `check-migrations.php` refuses one, and refuses a
freeze constant that no file reaches.

The rest of this section describes the range 0256-0265, where both engines were maintained
together. It is kept rather than deleted because those files are still read - the suite
replays them to build its SQLite side, and the fixtures under
`.devtools/pgsql/fixtures/import/` were produced by them.

**Every migration from 0256 to 0265 had to leave both engines correct.** A portable
`migrations/0256.sql`, or a pair of `migrations/0256.sqlite.sql` and
`migrations/0256.pgsql.sql` - an engine specific file wins over a generic one with the
same number.

There is a third case, and it needs to be a deliberate one: a migration that applies to a
single engine because the other genuinely needs no change. `0256.sqlite.sql` is the first,
fixing a SQLite-only type defect that PostgreSQL never had. Ship one only when you can say
in the file why the other engine is already correct, and say it there rather than here —
literally, with an `@engine-exclusive` comment, because `.devtools/pgsql/check-migrations.php`
refuses a lone engine-specific file that does not carry one. A missing counterpart and a
deliberate omission look identical in a directory listing; the marker is what tells them
apart.

**An engine-exclusive migration that creates a *table* needs one more thing.** The marker
tells `check-migrations.php` that the missing counterpart is deliberate, but it says
nothing to the differential suite, whose `migratedifftest.php` compares the two engines'
table sets and treats a table only one side has as the loudest possible defect — which is
what that phase is for. So a table that exists on one engine on purpose has to be named in
`ENGINE_EXCLUSIVE_TABLES` at the top of `migratedifftest.php`, with the reason, or the
suite fails on it. Naming it is the point: an exemption the suite does not know about is a
missing table wearing a different hat.

`migrations/0258.pgsql.sql`'s `files` table is the first (`0256.sqlite.sql` was a view
change, so this case had not come up). It holds uploaded files as `BYTEA` when
`FILE_STORAGE` is `database`, and `ConfigurationValidator` refuses that setting on any
driver but `pgsql` — so a SQLite counterpart would be a table nothing could ever read.
Note that this is a *different* list from `DatabaseImporter::TARGET_ONLY_TABLES`, which is
about tables the PostgreSQL baseline owns outright; `bin/victual-db-import` deliberately
still reports `files` as a target table with no source counterpart that stays empty, since
a household importing a SQLite installation wants to be told that its pictures have not
come across with it.

The same script rejects an engine-specific file that silently shadows a portable one of the
same number. Overriding is still legal — the loader prefers the specific file — but it has
to say `@overrides-generic`, because left implicit it means one engine never runs the
portable migration while both record the same number. With only two engines, a complete
per-engine pair is usually the clearer way to write that anyway.

The runtime loader enforces the other half: a migration whose name does not parse, or whose
suffix is not a real driver, now aborts the migration run instead of being skipped in
silence. `0256.sqlight.sql` used to be a file that ran nowhere and told nobody.

The consequence to keep in mind is that the two engines then sit at different migration
numbers while both being fully migrated, so nothing may compare one engine's number to the
other's. `DatabaseMigrationService::GetLatestMigrationNumber()` takes a dialect for this
reason; use it rather than assuming the highest file in `migrations/` applies everywhere.

**Claim the number before you write the file**, in
[migrations/RESERVATIONS.md](../../migrations/RESERVATIONS.md). Plans are worked in parallel
branches and each needs a number before any of them merges, so numbers handed out on a branch
collide or leave holes — and a hole is the worse of the two, because nothing complains. A
tree carrying 0257 and 0259 but not 0258 migrates a database that records `MAX(migration) =
259` and never ran 0258, which satisfies every check built on the maximum
(`GetLatestMigrationNumber()`, `DatabaseImporter`'s two-sided comparison, plan 10's boot
check) while the schema is missing a table. The runner itself is not fooled — it asks per
number whether a row exists, so a 0258 merged later is applied — but the checks that decide
whether a deployment is up to date are, and those are the ones that gate serving.

`check-migrations.php` enforces both halves: the sequence above the baseline has no holes,
and every number on disk is claimed in that file. A branch holding the higher of two in-flight
numbers therefore cannot go green until the branch holding the lower one merges, which is the
point — it is not independently mergeable, and the failure message says which branch it is
waiting for. `--allow-reserved-holes` (or `SUITE_ALLOW_RESERVED_HOLES=1` for `run-tests.sh`)
waives exactly that one failure so such a branch can still run its own suite; CI does not set
it. A number is retired rather than reused once a file has existed under it in `master`.

## Testing a change

Loading cleanly proves very little. The suite is one command:

    .devtools/pgsql/run-tests.sh [migrate|views|triggers|rollback|filter|schema|files|mqtt]

The runner's own header says what each phase asks and why; this list has been wrong three
times now by being maintained separately from it, so it is deliberately not repeated here -
and even the one line above is worth checking against `run-tests.sh` rather than trusted.
`migratedifftest.php` is the one to know about at this point: it migrates a database on each
engine, touches neither afterwards, and compares every table - that is the equivalence claim
above, written as a test, and it is the phase the missing seed data would have failed. The
view and trigger phases both populate PostgreSQL by copying an already-migrated SQLite
database, which is why neither could ever have caught it.

One thing about the `mqtt` phase does belong here, because it is a claim about coverage
rather than a description of a phase: **it runs against stand-ins, not against the real
systems.** InfluxDB is a PHP built-in server whose control file flips it between accepting,
rejecting, redirecting and answering with a page; the broker is a PHP stream socket speaking
the little of MQTT 3.1.1 `MqttPublisher` uses and recording what was published. That is what
keeps the phase free of a broker, a node install and an InfluxDB, and it is also the limit
of what a green run means: a real Mosquitto retaining the payload across a restart, Home
Assistant creating the entity, and InfluxDB accepting the line protocol are hand
verifications and stay so. Eight of the phase's probes run **twice, once per engine**, from
one SQLite database imported into PostgreSQL through `bin/victual-db-import`, because the
outbox is where this feature turns on transaction semantics - what a rolled back INSERT
leaves behind, how a driver reports a failure mid-transaction - and asserting that only on
the development engine would leave the deployment engine untested for the properties the
mechanism exists to provide.

`.devtools/pgsql/difftest.php` puts both engines into an identical table state and
compares what their views actually return:

    docker run --rm --network victualnet \
      -v "$PWD":/app -v /path/to/scratch:/scratch -v /path/to/scratch/data:/data \
      -e DIFFTEST_SQLITE_DSN=sqlite:/data/difftest.db \
      -e DIFFTEST_PGSQL_DSN='pgsql:host=victual-pg;port=5432;dbname=victual_full' \
      victual-dev php /app/.devtools/pgsql/difftest.php seed.sql <view> [<view> ...]

It seeds SQLite only, so SQLite's triggers fire, then copies the resulting tables into
PostgreSQL. That isolates view logic from trigger behaviour.

---

# Porting rules

Authoritative reference for porting Victual's schema. Every rule below exists because
breaking it changes what the REST API returns, which would break the iOS app and the
Home Assistant integration. **API compatibility is the hard constraint.**

Target: PostgreSQL 13+ (tested on 17).

## The overriding rule: the JSON on the wire must not change

Victual serialises rows straight to JSON with `json_encode`. So the PHP type PDO produces
for each column *is* the API contract. Verified empirically:

| Postgres type       | PHP type from PDO | JSON        | Verdict |
|---------------------|-------------------|-------------|---------|
| `SMALLINT`          | `integer`         | `1`         | use for 0/1 flags |
| `INTEGER`           | `integer`         | `1`         | use for ids, counts, day counts |
| `NUMERIC(15,2)`     | **`string`**      | **`"2.50"`**| **NEVER USE** - breaks `type: number` |
| `DOUBLE PRECISION`  | `double`          | `2.5`       | use for all amounts/prices |
| `TIMESTAMP`         | `string`          | `"2026-08-26 00:56:15"` | matches SQLite |
| `BOOLEAN`           | `bool`            | `true`      | **NEVER USE** - spec says `type: integer` |

## Type mapping

| SQLite | PostgreSQL | Notes |
|---|---|---|
| `INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE` | `INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY` | `BY DEFAULT`, not `ALWAYS` - Victual inserts explicit ids |
| `INTEGER` / `INT` | `INTEGER` | |
| `TEXT` | `TEXT` | |
| `TINYINT` **with** `CHECK(x IN (0,1))` or `(1,2)` etc. | `SMALLINT` + same CHECK | |
| `TINYINT` used as a flag, no CHECK | `SMALLINT` | |
| `TINYINT` holding an **id** | `INTEGER` | see hazard 1 |
| `DATETIME` | `TIMESTAMP` | |
| `DATE` | `DATE` | |
| `DECIMAL(p,s)` | `DOUBLE PRECISION` | never NUMERIC |
| `REAL` | `DOUBLE PRECISION` | |
| `DEFAULT (datetime('now', 'localtime'))` | `DEFAULT date_trunc('second', LOCALTIMESTAMP)` | |

### Hazard 1: `TINYINT` columns that are actually ids
SQLite's `TINYINT` has INTEGER affinity, i.e. 64-bit. PostgreSQL's `SMALLINT` caps at
32767. `chores.product_id` is declared `TINYINT` upstream but holds a product id, so it
**must** become `INTEGER` or installs with many products break.

### Hazard 2: `INTEGER` columns that actually hold fractions
SQLite is loosely typed - an `INTEGER` column happily stores `2.5`. `products.min_stock_amount`
is declared `INTEGER` yet the OpenAPI spec says `type: number`, and a live database really
does contain `2.5` there. PostgreSQL would round or reject.

Rule: **amount / servings / quantity / price / weight / factor -> `DOUBLE PRECISION`**,
regardless of what SQLite declares. Day counts, sort numbers, ids and flags stay `INTEGER`.

Known cases: `products.min_stock_amount`, `products.calories`, `recipes.base_servings`,
`recipes.desired_servings`, `meal_plan.recipe_servings`.

## Function and expression mapping

| SQLite | PostgreSQL |
|---|---|
| `IFNULL(a, b)` | `COALESCE(a, b)` |
| `datetime('now', 'localtime')` | `date_trunc('second', LOCALTIMESTAMP)` |
| `datetime(x)` | `x::timestamp` |
| `date('now', 'localtime')` | `CURRENT_DATE` |
| `date(x)` | `x::date` |
| `strftime('%Y-%m-%d', x)` | `to_char(x::timestamp, 'YYYY-MM-DD')` |
| `strftime('%s', x)` | `EXTRACT(EPOCH FROM x::timestamp)` |
| `julianday(a) - julianday(b)` | `EXTRACT(EPOCH FROM (a::timestamp - b::timestamp)) / 86400.0` |
| `group_concat(x, sep)` | `string_agg(x::text, sep)` |
| `instr(haystack, needle)` | `position(needle IN haystack)` |
| `IIF(c, a, b)` | `CASE WHEN c THEN a ELSE b END` |
| `x ISNULL` / `x NOTNULL` | `x IS NULL` / `x IS NOT NULL` |
| `substr`, `ceil`, `abs`, `round`, `min`, `max`, `length` | same, native |
| `CAST(x AS INT)` | `CAST(x AS INTEGER)` |

### Hazard 3: boolean expressions leak into the output
This is the easiest way to silently break the API.

In SQLite `SELECT (a > b) AS flag` yields integer `0`/`1`. In PostgreSQL it yields a
boolean, which `json_encode` renders as `true`/`false`.

**Every** projected expression that is a comparison, `AND`/`OR`/`NOT`, `IN`, `EXISTS`,
`IS NULL` or `LIKE` must be wrapped:

```sql
-- WRONG                              -- RIGHT
SELECT p.amount > 0 AS in_stock       SELECT CASE WHEN p.amount > 0 THEN 1 ELSE 0 END AS in_stock
```

Conditions inside `WHERE`, `ON`, `HAVING` and `CASE WHEN` need no wrapping - only values
that end up in the SELECT list.

### Hazard 4: integer division
`5 / 2` is `2` in both engines when both operands are integers. Where SQLite operands were
REAL (and so divided as floats), make sure the Postgres operand really is
`DOUBLE PRECISION` - cast with `::double precision` if unsure.

### Hazard 5: `||` with non-text operands
PostgreSQL needs at least one side to be text. Cast: `x::text || ' ' || y::text`.

### Hazard 6: `GROUP BY` strictness
PostgreSQL requires every non-aggregated select column to appear in `GROUP BY`. SQLite does
not. Add the missing columns; do not wrap them in an aggregate, which would change results.

### Hazard 7: `CAST(x AS INT)` truncates in SQLite but rounds in PostgreSQL
SQLite truncates toward zero; PostgreSQL rounds to nearest. A 30.5 hour gap becomes `30`
on SQLite and `31` on PostgreSQL. Use `CAST(trunc(x) AS INTEGER)` to preserve behaviour.

### Hazard 8: window functions return `bigint`
`ROW_NUMBER()`, `RANK()`, `COUNT()` etc. return `bigint` in PostgreSQL. Wrap in
`CAST(... AS INTEGER)` where the column is a sort number or a count that SQLite produced
as a plain integer.

### Hazard 9: `strftime('%Y-%W', ...)` has no PostgreSQL equivalent
SQLite's `%W` is a Monday-based week number where the days before the year's first Monday
are week `00`. PostgreSQL's `to_char(..., 'WW')` is not Monday-based and `'IW'` is ISO-8601
(which can push a date into the adjacent year). The baseline therefore ships a helper,
`victual_sqlite_percent_w(date) RETURNS INTEGER`, in `03_views_group1.sql`.

**Use that helper - do not reinvent it.** `recipes.name` for `type = 'mealplan-week'` is
written by triggers on `meal_plan` using this same expression and read back by
`meal_plan_internal_recipe_relation`. If the trigger and the view compute the week
differently the join silently stops matching and meal plans quietly break.

### Hazard 10: "dummy id" columns and `GROUP BY`
Several views select a bare `id` column that is not in the `GROUP BY`, with a comment
saying "Dummy, LessQL needs an id column". SQLite tolerates this and picks an arbitrary
row. Do **not** resolve it by adding the column to `GROUP BY` - that changes the view's
row count, which is the one thing that must not change. Use `MIN(x)` instead and leave an
inline comment.

### Hazard 11: scalar subqueries that can return several rows
`(SELECT 1 FROM products WHERE parent_product_id = p.id) IS NOT NULL` works on SQLite,
which silently takes the first row. PostgreSQL raises "more than one row returned by a
subquery used as an expression" and the whole query fails. Rewrite as
`EXISTS(SELECT 1 ...)`. This already bit `products_view`.

### Hazard 12: date-only values in DATETIME columns
SQLite echoes back exactly the string that was stored, so a `DATETIME` column holding
`"2026-08-26"` returns `"2026-08-26"`. PostgreSQL's `TIMESTAMP` normalises it to
`"2026-08-26 00:00:00"`, which changes the API response.

Already handled in the table DDL (`stock.opened_date`, `stock_log.opened_date` and
`tasks.due_date` are `DATE`). If a view exposes another such column and the differential
test shows a `" 00:00:00"` suffix, that is this hazard - report it rather than papering
over it with `to_char`, because the underlying column type is what needs to change.

### Hazard 13: aggregates that return `NUMERIC`
`AVG(integer)` and `EXTRACT(EPOCH FROM ...)` both return `NUMERIC` in PostgreSQL, and
`NUMERIC` reaches PHP as a string. Anything built on them stays `NUMERIC` too, so
`AVG(EXTRACT(EPOCH FROM ...) / 86400.0)` leaks a JSON string. Cast the expression to
`double precision`.

`COUNT()`, `ROW_NUMBER()` and friends return `bigint` - see hazard 8.

### Hazard 14: `SELECT *` over a join with colliding column names
`CREATE VIEW ... AS SELECT * FROM stock s JOIN products_view p ON ...` is rejected by
PostgreSQL when both sides share a column name. SQLite accepts it and disambiguates the
second occurrence by appending `:1`, so the view really does have columns literally named
`id:1`, `location_id:1` and so on - verified against a live database.

Reproduce them exactly with quoted aliases (`AS "id:1"`) and an explicit column list. Do
not "clean this up": the names are part of what the view returns today.

**Only `uihelper_stock_entries` is actually affected.** Establish that empirically before
assuming a view has this problem - fetch a row on SQLite and look at the keys:

    php -r '$p=new PDO("sqlite:victual.db"); print_r(array_keys($p->query("SELECT * FROM <view> LIMIT 1")->fetch(PDO::FETCH_ASSOC)));'

`stock_missing_products` and `uihelper_stock_current_overview` were both wrongly suspected
of it during the port: the first is `SELECT *` over a *derived table* rather than over a
join, and the second lists all 47 of its columns explicitly. Neither has duplicates.

### Hazard 15: `COLLATE NOCASE` is written into the PHP, not just the schema
Victual sorts and compares names case insensitively using SQLite's built in `NOCASE`
collation, spelled directly into its queries - 116 times across eleven PHP files. Almost
all are `ORDER BY` on list pages and API responses; one is a barcode lookup in
`StockService`. PostgreSQL has no such collation and rejects the query outright, so this
breaks ordering nearly everywhere without touching the schema at all.

Rather than rewriting 116 call sites, `03_functions.sql` creates a collation under that
name, so the `COLLATE NOCASE` the PHP already emits resolves to it (identifiers fold to
lower case). No PHP change is needed. Verified that both ordering and the equality
comparison behave the same as SQLite.

It needs a PostgreSQL built with ICU, which the official images are. Without ICU the
migration fails at that statement, which is the right place to find out.

### Hazard 16: `LIKE` is case insensitive on SQLite and case sensitive on PostgreSQL
**Fixed in the dialect - kept here because the shape recurs.** This was the one hazard on
the list that produced a wrong *answer* rather than an error, on a public endpoint, with
nothing to notice it.

SQLite's `LIKE` ignores ASCII case by default (`PRAGMA case_sensitive_like` is off).
PostgreSQL's `LIKE` is case sensitive; `ILIKE` is the case insensitive form. The two engines
therefore disagree on every `LIKE` the application emits, and the application emits it in
exactly one place - `BaseApiController::FilterData()`, for the `~` and `!~` operators of the
generic list filter:

```php
case '~':
    $data = $data->where($matches['field'] . ' LIKE ?', '%' . $matches['value'] . '%');
```

That reaches every `GET /api/objects/{entity}` and everything else routed through
`FilteredApiResponse`. Measured on a three row table (`Milk`, `milk chocolate`, `Butter`)
with `name LIKE '%milk%'`:

| Engine | Rows returned |
|---|---|
| SQLite | `Milk`, `milk chocolate` |
| PostgreSQL | `milk chocolate` |
| PostgreSQL with `ILIKE` | `Milk`, `milk chocolate` |

No error, no log line, no failing view diff - the differential suite drives SQL at each
engine and never enters `BaseApiController`, which is the blind spot
[14](../../docs/plans/14-contract-and-regression-scaffolding.md)'s coverage section was
added to make visible.

**Fixed.** The fix is the one `GetRegexpCondition()` already models:
`GetLikeCondition(string $field, bool $negated)` on the dialect, returning `LIKE` /
`NOT LIKE` on SQLite and `ILIKE` / `NOT ILIKE` on PostgreSQL, with `FilterData` calling it
instead of spelling the operator itself. SQLite's behaviour is the reference and PostgreSQL
now matches it, because SQLite's is what the API has always documented and what any existing
client was written against.

Verified by instantiating both dialects and running the SQL they actually emit against a
real SQLite and a real PostgreSQL 16, on the fixture above plus a `NULL` row:

| Operator | SQLite | PostgreSQL before | PostgreSQL after |
|---|---|---|---|
| `~` | 1, 2 | 2 | 1, 2 |
| `!~` | 3 | - | 3 |

The `!~` row is the one worth having checked rather than assumed: `NOT ILIKE` leaves the
`NULL` name out on both engines, so negation did not quietly become three-valued on one side.

**The agreement is ASCII only, and that is the interesting part of this hazard.** SQLite's
`LIKE` folds `A-Z` and nothing else; `ILIKE` folds according to the database collation, so
the two still part company past ASCII:

| Pattern | SQLite | PostgreSQL (`C.UTF-8`) |
|---|---|---|
| `milk` against `Milk` / `milk chocolate` | both | both |
| `æ` against `ÆBLE` / `æble` | `æble` | both |

Closing that would mean reimplementing one engine's folding table inside the other, which
is a great deal of machinery for a case no fixture in this repository contains. It is not
closed, so it is measured: the `filter` phase of `run-tests.sh` prints both engines' answers
and the database collation on every run, and asserts the invariant that actually holds -
identical on ASCII, and beyond ASCII PostgreSQL may fold *more* than SQLite but never less.
An exact non-ASCII assertion would be wrong to write, because which characters fold is a
property of the database's collation rather than of this code: the same test would fail on a
`C`-locale database for something that is not a defect.

The OpenAPI spec described these operators as "LIKE" and "not LIKE", which was the SQLite
spelling rather than the contract. It now says "contains, case insensitive for ASCII", and
spells the non-ASCII limit out, because "case insensitive" unqualified would have promised
the agreement this section has just said does not hold.

**The operator is now restricted to text columns, on both engines.** That was the second
half of this hazard and it needed a different kind of fix. `?query[]=id~2` used to be
answered by SQLite, which coerces the integer to text and matches, and by PostgreSQL with
`operator does not exist: integer ~~* unknown` on the 500 path - a divergence in both the
result and the status.

Casting on the PostgreSQL side would have closed it and was the obvious move; it is the
wrong one. Measured across the two engines:

| column | SQLite as text | PostgreSQL as text |
|---|---|---|
| `INTEGER 2` | `2` | `2` |
| `REAL 1.0` | **`1.0`** | **`1`** |
| timestamp | `2026-08-29 18:08:32` | same |

So a cast agrees on integers and timestamps and silently disagrees on floats, which trades
a loud error for a wrong answer - the exact failure this hazard already is. A canonical
cross-engine stringification is a real design (which format, which precision, which time
zone) and is worth having one day, but it has to be designed, not fall out of whatever
`CAST` each engine happens to implement.

So the fields are validated instead, before any SQL is built:
`DatabaseDialect::GetColumnTypes()` per engine, and one shared rule,
`DatabaseDialect::IsTextMatchableType()`, deciding what may be matched. The rule is
SQLite's own TEXT-affinity rule - a declared type containing `CHAR`, `CLOB` or `TEXT` -
which also selects exactly `text`, `character varying` and `character` out of
PostgreSQL's `information_schema`. Anything else, timestamps included, is `400` on both
engines. A field the entity does not have is `400` on both engines too, which is what
closes hazard 17 below.

**Two catalogues cannot answer for a computed view column, so a manifest does.** SQLite
does not type a view column that is an expression: `PRAGMA table_info` returns an empty
string for a `GROUP_CONCAT`, a `COALESCE` or a concatenation, where PostgreSQL resolves the
expression and reports what it came out as. Comparing catalogues therefore leaves the
verdict engine-dependent on exactly those columns - which is the same category of silent
divergence as the operator bug itself, so it is not something to leave documented and
unfixed.

`ColumnTypeManifest` holds the answer for them: 13 entries, semantic types (`text`, not
`TEXT` or `character varying`) so that neither engine's vocabulary becomes the contract by
default, applied to both engines identically by
`DatabaseDialect::GetValidationColumnTypes()`. Three rules keep it honest, and the `filter`
phase enforces all three against the real schema on both engines:

1. **It fills gaps and never overrides.** An entry applies only where the catalogue has
   nothing to say, so a wrong entry cannot make an engine accept something it will then
   fail on.
2. **Entries must name real columns** - a stale one is a failure, because it reads as a
   deliberate classification of something that is not there.
3. **Silence means rejected.** A computed column nobody has classified stays unsearchable
   on both engines; adding one to a view does not quietly make it searchable on one.

Result: **731 of 731 columns across 82 shared tables and views reach the same verdict on
both engines**, and the phase fails if that ever stops being true. The columns worth
searching are searchable again on SQLite as well as PostgreSQL -
`stock_missing_products.name`, `users_dto.display_name`,
`recipes_resolved.product_names_comma_separated` and the comma-separated barcode lists the
UI helper views build.

The alternative to the manifest was to keep rejecting all 113 untyped columns, which had
the smaller *count* of divergences (13 against the 100 that allowing them blindly would
leave) but was still 13 engine-dependent answers, on the most useful columns.

**Catalogue failure is not silent.** If the columns of an entity cannot be read at all, a
request that named a field in `query[]` or `order` is answered `500` and the failure is
logged, rather than being run unvalidated: failing open there would restore the very
200-on-one-engine / 500-on-the-other divergence this exists to remove, intermittently and
with nothing saying so. A request that named no field needs no validation and is served
normally, so a catalogue problem costs filtering rather than availability.

**The suite can see all of this**, which was not true when the first half of the fix
landed. The `filter` phase of `run-tests.sh` asks each dialect for the condition it emits,
runs both against their own engine and compares the rows, then compares the two engines'
verdicts on every column of every shared table and view. It is the first phase that
compares *application* behaviour rather than SQL, and it was checked the way
[14](../../docs/plans/14-contract-and-regression-scaffolding.md) asks - by putting the
defect back and confirming the phase fails (three ASCII cases, `[1,2] vs [2]`). It earned
its place immediately: it is what caught the untyped-view-column residual above, before
that shipped as a silent divergence.

Do not reach for the `nocase` collation of hazard 15 to solve this. It is nondeterministic,
and PostgreSQL rejects `LIKE` against a nondeterministic collation outright.

### Hazard 18: `lastInsertId()` with no sequence name is `lastval()`
**Fixed in `GenericEntityApiController::AddObject`; kept here because the mechanism is
live for any other caller.**
`PDO::lastInsertId()` on `pdo_pgsql` with no argument runs `SELECT lastval()`, which
returns the last value generated by *any* sequence in the session — not the last value
generated by the table just inserted into. An `AFTER INSERT` trigger that writes to
another table with an identity column therefore owns the answer.

`products` has exactly such a trigger: `products_INS` writes to
`cache__quantity_unit_conversions_resolved`, and
`quantity_unit_conversions_resolved` emits at least one row for every product. So
`POST /api/objects/products` answered with the cache table's id. Measured on
PostgreSQL 16.13, a product actually created as `id` 6:

```
real product id                 : 6
lastInsertId()                  : 12   <- the cache table's sequence
lastInsertId('products_id_seq') : 6
```

SQLite has no equivalent: `sqlite3_last_insert_rowid()` is per-connection and per-table
in the sense that matters, so the same code was correct there and the defect is
PostgreSQL-only — which is why the differential suite could not see it either. It is also
invisible on a virgin database, where both sequences start at 1 and advance together; the
two only drift once a product has more than one quantity unit conversion.

The fix is not to pass the sequence name at the call site but to read the id LessQL
already put on the row: `Row::save()` looks it up as
`lastInsertId($db->getSequence($table))` and assigns it to the primary key. Any new caller
that reaches for `lastInsertId()` directly should pass a sequence name or, better, use the
saved row.

### Hazard 17: an identifier that reaches LessQL is quoted, and quoting is case sensitive
**Fixed by validating the field - kept here because the mechanism is still live for any
identifier that reaches LessQL from somewhere else.**
`PostgresDialect::GetIdentifierDelimiter()` returns `"` with the comment "Victual's tables
and columns are all lower case, so quoting them is safe". That is true of the schema - all
37 tables, all their columns and all 45 views are lower case, verified by parsing every
migration and the whole baseline - and it is not true of the *inputs*.

LessQL quotes every identifier it is handed:

```php
// Result::orderBy()
$clone->orderBy[] = $this->db->quoteIdentifier($column) . " " . $direction;
```

and `BaseApiController::QueryData()` hands it a request parameter verbatim:

```php
$data = $data->orderBy($parts[0]);   // $parts[0] is ?order=<field>
```

So `?order=Name` becomes `` ORDER BY `Name` `` on SQLite, where backtick quoting still
resolves case insensitively, and `ORDER BY "Name"` on PostgreSQL, where it does not:

    ERROR: column "Name" does not exist
    HINT: Perhaps you meant to reference the column "t.name".

Same request, 200 on one engine and 500 on the other. The frontend never sends `order=`, so
this is reachable only by an API client - which is to say by both of the clients
[17](../../docs/plans/17-ecosystem-clients.md) tracks.

The `query[]` filter is *not* affected, and the reason is worth knowing because it is
accidental: `FilterData` interpolates the field into a raw condition string
(`$matches['field'] . ' = ?'`) rather than passing it as an identifier, so LessQL never
quotes it and PostgreSQL folds it to lower case like any other bare identifier.
`?query[]=Name=Milk` works on both engines. One code path is safe because it builds SQL by
string concatenation and the other is broken because it does the tidier thing.

**Fixed, by rejecting rather than by widening the quoting.** The field named in `order` is
checked against the entity's real columns before it reaches LessQL, so `?order=Name` is now
`400 Invalid query: unknown field "Name"` on both engines instead of a sort on one and a 500
on the other. `?order=nope` was a 500 on *both* engines and is now the same 400 on both.

Note what this costs on SQLite: `?order=Name` used to work there, because backtick quoting
resolves case-insensitively. It does not any more. Accepting a field name in a case the
entity does not actually use was never a documented behaviour, and keeping it would have
meant either case-folding field names into the schema's spelling - which is the identifier
equivalent of the cast rejected under hazard 16 - or leaving the two engines disagreeing.

The same check covers `query[]`, so an unknown field is a `400` there too rather than a
`no such column` 500 on SQLite. Both are `400 Invalid query`, which is the status
[11](../../docs/plans/11-api-error-handling.md) wanted for this surface.

## What was checked and found clean

The audit behind hazards 16 and 17 swept the whole tree for case sensitivity differences.
The negative results are recorded here so the next person does not repeat them:

- **No mixed case tables, views or columns exist on either engine.** Every `CREATE TABLE`,
  `CREATE VIEW` and `ALTER TABLE … ADD COLUMN` across all 256 migrations and the baseline was
  parsed; every identifier is lower case. The premise `GetIdentifierDelimiter()` relies on
  holds for the schema.
- **Trigger names are mixed case and it does not matter.** `products_INS`,
  `trg_stock_log_DEL` and the rest are created unquoted, so PostgreSQL folds them, and
  nothing ever names one: `DatabaseImporter::SetTriggersEnabled()` uses
  `ALTER TABLE … DISABLE TRIGGER USER`, which names no trigger at all.
- **`newest_Id`** (`migrations/0054.sql`) is the only mixed case alias in any SQL. It is a
  derived table's output column, joined as `slg.newest_id`, unquoted on both sides, in a
  migration that runs on SQLite only. Harmless, and it would stop being harmless the moment
  someone quoted either spelling.
- **`sl.NAME`** (`StockReportsController.php:118`) is the only upper case column reference in
  PHP. Unquoted, so it folds. Cosmetic.
- **Hazard 15's `nocase` collation does what it claims.** `deterministic = false` makes `=`
  case insensitive, so `StockService`'s barcode lookup matches the same rows as SQLite -
  checked directly, not assumed.
- **The `§` regexp operator agrees across engines.** SQLite's `REGEXP` is backed by
  `mb_ereg`, which is case sensitive; PostgreSQL's `~` is case sensitive. Consistent, and
  `~*` was correctly not used.
- **User defined entity and userfield names are values, not identifiers.** They are compared
  with `=` against a column with no `COLLATE`, so both engines are case sensitive and agree.

## Accepted differences

Two differences are known, deliberate and judged harmless. Do not try to "fix" them.

**Float accumulation order.** `products_average_price.price` can come out as
`4.124499999999999` on SQLite and `4.1245` on PostgreSQL. Summing floating point values in
a different order gives a different last bit; the discrepancy is around 1e-15 and is not
stable on SQLite either. Rounding would change the documented value, so it stands.

The same artifact reaches `uihelper_product_details.average_price`,
`uihelper_stock_current_overview.average_price` and `recipes_resolved.costs` /
`costs_per_serving`, which are computed from it. Of these only `products_average_price` is
in the `ExposedEntity` enum.

**`chores.start_date` where the stored value has no time.** SQLite returns exactly the
string it stored, so a value written as `"2025-01-01"` comes back as `"2025-01-01"`;
PostgreSQL's `TIMESTAMP` renders it `"2025-01-01 00:00:00"`. This one *is* on a public
endpoint (`chores` is in the `ExposedEntity` enum), so it is worth being explicit about.

`DATE` is not an option: the chore form is a datetimepicker with format
`YYYY-MM-DD HH:mm:ss` and the `default_start_date_when_empty` triggers write
`DATETIME('now', 'localtime')`, so real chores genuinely carry a time and `DATE` would
discard it. Anything the UI creates therefore matches exactly on both engines. Only a
date-only string - the demo data generator, or an API client posting one - differs, and
`"2025-01-01 00:00:00"` is the more conformant rendering of the documented
`format: date-time` anyway.

Verified with `trigdifftest.php` that this is the *only* such column left across all 37
tables.

**`qu_factor_*` in `products_view`, `uihelper_stock_entries` and
`uihelper_stock_current_overview` - fixed, no longer a difference.**
`cache__quantity_unit_conversions_resolved.factor` is `TEXT` upstream, so SQLite used to
return the JSON string `"1.0"` where PostgreSQL returns the number `1`. This was recorded
here as accepted on the grounds that none of the three views is in the `ExposedEntity`
enum. That reasoning was too generous: PostgreSQL was already the conforming side (the
OpenAPI spec documents the field as `type: number`) and `uihelper_product_details` had
always wrapped the same expression in `CAST(... AS REAL)`, so one view was conforming and
its siblings were not, for no reason anyone had chosen.

`migrations/0256.sqlite.sql` applies that same cast in `products_view`, which the other
two inherit the columns from. SQLite now returns a number as well. The differential suite
covers all three views and would catch a regression.

**The `migrations` table.** Excluded from `trigdifftest.php`'s table comparison, and no
longer copied by `DatabaseImporter`. It records how a particular database's schema was
built, which is per engine by design: PostgreSQL replaces migrations 0001-0255 with the
squashed baseline, and an engine-exclusive migration such as `0256.sqlite.sql` applies to
one side only. Two fully migrated databases therefore hold different rows here and always
will.

This is why `DatabaseImporter` checks each side against the latest migration for *its own*
engine rather than comparing the two numbers to each other, and why it leaves the target's
migrations table alone. Copying the source's history into the target was harmless only
while the engines happened to number alike; once they do not, a target carrying the
source's numbers would skip a future migration of its own believing it had already run.

## Triggers

Each SQLite trigger becomes a PL/pgSQL function plus a `CREATE TRIGGER`. Naming: function
`trg_<trigger_name>()`, trigger keeps its original name.

| SQLite | PostgreSQL |
|---|---|
| `SELECT CASE WHEN cond THEN RAISE(ABORT, 'msg') END;` | `IF cond THEN RAISE EXCEPTION 'msg'; END IF;` |
| `RAISE(ABORT, 'msg')` | `RAISE EXCEPTION 'msg'` |
| `BEFORE ... WHEN cond` | same, PostgreSQL supports `WHEN` on row triggers |
| `NEW.x`, `OLD.x` | same |

Rules:
- `BEFORE` row triggers must `RETURN NEW` (or `RETURN NULL` to cancel the row).
- `AFTER` row triggers must `RETURN NULL`.
- A SQLite `AFTER INSERT` trigger that fixes up the row it just inserted
  (`UPDATE t SET c = ... WHERE id = NEW.id`) should become a **`BEFORE INSERT`** trigger
  assigning `NEW.c := ...` directly. That avoids recursion and is the idiomatic form.
  Only do this when the trigger touches its own row; leave genuinely cross-table
  `AFTER` triggers as `AFTER`.
- Statements are separated by `;` inside `BEGIN ... END;` in both, but the PL/pgSQL body
  must be dollar-quoted: `AS $$ BEGIN ... END; $$ LANGUAGE plpgsql;`
- Watch trigger firing order: PostgreSQL fires row triggers in **name order**. Where two
  SQLite triggers on the same table/event must run in a particular order, name them so the
  alphabetical order matches.

## Style
- Tabs for indentation, matching the existing migration files.
- Preserve original column order, names, nullability and comments exactly.
- Do not "improve" anything. A faithful port is the goal; behaviour changes are bugs.

## Moving an existing installation

    php bin/victual-db-import [/path/to/victual.db] [--force]

Point `config.php` at the target database first (`DB_DRIVER`, `DB_HOST`, ...). The command
creates the schema if it is not there yet, so an empty PostgreSQL database is a valid
target, then copies every row across.

It refuses to run when the target already holds data (pass `--force` to replace it) and
when the two schemas are at different migration levels - start the old installation once
so it migrates itself up to date first.

A target that `bin/victual-migrate` has already been run against holds data too: the initial
data of a fresh installation. `--force` is the right answer there - the import truncates
before it copies, so nothing is duplicated - but it is deliberately not automatic, because
this command cannot tell those rows from a month of real use.

Triggers are disabled for the duration of the copy. The rows being copied were already
shaped by the source's triggers, so letting the target's fire again would cascade deletes
and recompute derived values a second time.

Two details that are easy to get wrong and are handled here: values are read with
`PDO::NULL_NATURAL`, because the application's usual `NULL_EMPTY_STRING` would turn every
empty string into NULL on the way through (Victual stores an empty name for the internal
meal plan section, which is enough to violate a NOT NULL column); and the generated id
counters are resynced afterwards, since every row arrives with an explicit id.

## Testing triggers

Views are checked by comparing what they return; triggers cannot be, because what they do
is change *other* rows. `.devtools/pgsql/trigdifftest.php` starts both engines from an
identical table state, applies the same statements to each, and then compares every table:

    docker run --rm --network victualnet \
      -v "$PWD":/app -v /path/to/scratch:/scratch -v /path/to/scratch/data:/data \
      -e TRIGTEST_SQLITE_PATH=/data/trigtest.db \
      -e TRIGTEST_PRISTINE_PATH=/scratch/demodata/victual_en.db \
      -e TRIGTEST_PGSQL_DSN='pgsql:host=victual-pg;port=5432;dbname=victual_trig' \
      victual-dev php /app/.devtools/pgsql/trigdifftest.php script.sql [script.sql ...]

A script is plain SQL, one statement per `;` at end of line. To check a constraint that a
trigger is supposed to enforce, precede the statement with

    -- @expect-error qu_id_stock can only be changed

which requires *both* engines to reject it with a message containing that substring. That
is how `RAISE(ABORT, ...)` constraints are verified.

`row_created_timestamp` is excluded from the comparison, since it comes from the clock.

**Internal meal plan recipes accumulate fewer orphan rows.** Victual generates hidden
`recipes` rows of type `mealplan-day`, `mealplan-week` and `mealplan-shadow` from triggers
on `meal_plan`. In SQLite a single `INSERT INTO meal_plan` re-fires `update_internal_recipe`
several more times, minting a new internal recipe id on each pass and abandoning the
previous one, so `recipes_nestings` and `recipes_pos` fill up with rows pointing at
internal recipe ids that no longer exist. The port collapses that to one deterministic
generation.

This is not a difference in what the application can see. Measured after the same meal plan
statements on both engines: the reachable state - rows whose `recipe_id` still resolves - is
identical (27 recipes, 24 `recipes_pos`, 20 `recipes_nestings`, matching on name, type,
servings, product and amount). Only the count of unreachable rows differs, and PostgreSQL
has fewer.

Worth knowing that this is pre-existing upstream behaviour rather than something the port
introduced: Victual's own demo dataset ships with 146 of its 166 `recipes_nestings` rows
already dangling. A client listing `/objects/recipes_nestings` or `/objects/recipes_pos`
therefore sees slightly fewer junk rows on PostgreSQL.

### Wave 3a role model

`.devtools/pgsql/run-tests.sh rbac` tests the PostgreSQL-only role/read model, including
the six view permissions, backfill, role provenance, grants, rollback, default roles and
Blade rendering. It also runs as part of the full suite. The SQLite model stays at 0265:
the migration comparison projects the original 30 permissions, and the view comparison
projects the original six `uihelper_user_permissions` columns. Import checks assert both
the copied legacy rows and the new target-only read grants.
