# ADR-0017: Doctrine DBAL is the persistence seam; engine portability is an affordance, not a promise

- **Status: Proposed.** Not accepted, not scheduled, not in the roadmap's wave order.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request, and this record
  carries **acceptance prerequisites** — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-05.
- **Depends on:** [ADR-0008](0008-postgresql-only-runtime-engine.md), accepted 2026-08-31
  and delivered by [plan 24](../plans/24-sqlite-runtime-retirement.md). Stage 3 of the
  decision additionally requires
  [plan 14](../plans/14-contract-and-regression-scaffolding.md) piece 2.
- **Would affect:** [14](../plans/14-contract-and-regression-scaffolding.md),
  [15](../plans/15-deliberate-cleanup.md),
  [11](../plans/11-api-error-handling.md),
  [01](../plans/01-file-storage.md).

## Context

Victual reaches its database through three layers. Measured against the working copy of
2026-09-05:

| Layer | Size | Maintained by |
|---|---|---|
| `services/Database/DatabaseDialect.php` and its two subclasses | 17 abstract methods, 1,087 lines | this fork |
| LessQL fluent API, reached as `$this->DB->…` | 440 call sites across 36 files | `berrnd/lessql`, a git-pinned fork |
| Hand-written SQL through `DatabaseService::ExecuteDbQuery/ExecuteDbStatement` | 69 call sites across 8 files, plus 20 raw `GetDbConnectionRaw()` sites | this fork |

[ADR-0008](0008-postgresql-only-runtime-engine.md) made PostgreSQL the only engine an
installation can be configured for. The dialect seam survived that retirement because the
differential suite still constructs a SQLite dialect, and that suite is scheduled for
removal when [14](../plans/14-contract-and-regression-scaffolding.md) piece 2 exists. At
that point the seam has one implementation, one caller, and no test that depends on its
existing in the abstract — and [15](../plans/15-deliberate-cleanup.md) is the plan that
removes code with no remaining caller. Whether the seam stays is therefore a decision that
has to be made before 14 piece 2 lands, not after.

Three facts constrain the answer.

**LessQL is a pinned fork of an unmaintained library.** `composer.json` requires
`morris/lessql` at `dev-master-fork`; `composer.lock` resolves that to
`https://github.com/berrnd/lessql.git` at commit `bab1170`. There is no upstream release
stream to take fixes from, so a PHP release that breaks it is this fork's problem to
diagnose and patch.

**LessQL defines the wire format.** `LessQL\Row` implements `JsonSerializable`, and
`BaseApiController::ApiResponse()` hands those objects to `json_encode()`.
`Row::jsonSerialize()` recurses into related rows and renders a `\DateTime` as
`Y-m-d H:i:s`. [ADR-0005](0005-wire-contract-is-the-invariant.md) makes the JSON on the
wire the invariant, and [14](../plans/14-contract-and-regression-scaffolding.md) records
that the database schema is that contract. Any replacement for LessQL changes response
bodies unless it reproduces that method's behaviour, and nothing in the repository
currently detects such a change.

**Portability does not live in the PHP layer.** `db/pgsql/baseline/` is 4,893 lines
holding 46 views and 55 triggers, the triggers backed by PL/pgSQL functions. An
abstraction over connections, quoting and query building ports none of it. A third party
running a different engine supplies an equivalent schema themselves.

## Decision

**The seam between the application and its database stays, and Doctrine DBAL is what it
becomes.** `DatabaseDialect` is retained as an architectural boundary rather than treated
as dead weight left over from the two-engine period, and fork-written abstraction is
replaced by DBAL wherever DBAL already answers the question.

**Engine portability is an affordance, not a supported feature.** Victual is tested
against PostgreSQL and against nothing else. A third party who supplies a schema and a
platform for another engine can run the PHP layer without patching it; they own the
schema, the verification, and any behavioural difference that results. This record does not
restore the multi-engine conformance that
[ADR-0008](0008-postgresql-only-runtime-engine.md) rejected as its option D, and no
equivalence proof, second CI engine, or portability check is created by accepting it.

### What DBAL absorbs, and what stays fork-owned

Of the 17 abstract methods on `DatabaseDialect`, DBAL answers roughly half. The split is
what decides whether the dependency is worth taking.

| `DatabaseDialect` method | DBAL equivalent |
|---|---|
| `CreateConnection` | `DriverManager::getConnection()` |
| `GetName` | `Connection::getDatabasePlatform()` |
| `QuoteIdentifier` | `AbstractPlatform::quoteIdentifier()` |
| `GetNowExpression` | `AbstractPlatform::getCurrentTimestampSQL()` |
| `GetTimestampType` | `AbstractPlatform::getDateTimeTypeDeclarationSQL()` |
| `GetRegexpCondition` | `AbstractPlatform::getRegexpExpression()` |
| `GetColumnTypes` | `AbstractSchemaManager` introspection — subject to prerequisite 1 |
| `IsMissingTableError` | `Doctrine\DBAL\Exception\TableNotFoundException` |
| `GetLikeCondition` | None. DBAL has no `ILIKE` abstraction |
| `OnConnected` | None as a dialect concern; DBAL middleware is the nearest hook |
| `GetOptimizeStatement` | None |
| `WithMigrationLock`, `WithPublicationLock` | None. PostgreSQL advisory locks are engine-specific by construction |
| `GetDbChangedTime`, `SetDbChangedTime`, `MarkDbChanged` | None. This is fork bookkeeping, not a database capability |
| `GetIdentifierDelimiter` | None needed. Its only caller is `DatabaseService`, which passes it to LessQL's `setIdentifierDelimiter()`, so the method retires with stage 3 |

`IsMissingTableError` is the clearest case for the dependency. It exists because
PDO_SQLite reports a missing table, a missing column and a syntax error identically as
SQLSTATE `HY000`, so `SqliteDialect` had to match on the message text. DBAL converts driver
errors into a typed exception hierarchy, which removes the class of problem rather than the
one instance of it.

`ResyncGeneratedIdCounters`, the seed data of
[ADR-0003](0003-seed-data-in-php.md), the numbered migration files of
[ADR-0002](0002-squashed-baseline.md) and [ADR-0004](0004-engine-specific-migrations.md),
and `migrations/RESERVATIONS.md` are unaffected. **`doctrine/migrations` is not adopted**,
and neither is an ORM: this record covers the connection, platform, error translation and
query-building layers only.

### Sequence

Three stages, each independently valuable and independently abandonable.

1. **Connection, platform and error translation.** `DatabaseDialect` delegates the eight
   methods above to a DBAL `Connection` and its platform, keeping its own interface. No
   query changes, no response changes. This stage carries no wire risk and is where the
   dependency proves or fails to prove itself.
2. **Schema introspection.** `GetColumnTypes()` uses DBAL's schema manager. This decides
   whether a caller-supplied `query[]` field is a 400 or reaches SQL
   ([plan 11](../plans/11-api-error-handling.md)), so it is gated on prerequisite 1.
3. **The LessQL replacement.** DBAL's query builder replaces `$this->DB->…` at 440 call
   sites, and the `LessQL\Result` and `LessQL\Row` types named in 20 files' signatures and
   docblocks are replaced with fork-owned types. **This stage requires
   [14](../plans/14-contract-and-regression-scaffolding.md) piece 2's response snapshot to
   exist first**, because `Row::jsonSerialize()` is the current definition of the wire
   format and nothing else records what it produces.

Stage 3 is the large one and is the one that may never be worth doing. Stages 1 and 2 leave
the codebase better whether or not it follows.

### Interaction with ADR-0009

[ADR-0009](0009-database-as-the-logic-layer.md), if accepted, moves more behaviour into
PostgreSQL views and functions. That reduces what a DBAL seam can carry across engines: the
affordance this record offers shrinks in proportion to how much logic lives in PL/pgSQL. The
two records are compatible — 0009 decides where logic lives, this one decides what the PHP
talks through — but accepting both means a clean seam over a schema that is not portable,
and a reader evaluating either should know that.

## Options considered

**A. Keep the fork-owned dialect, unchanged.** Costs nothing today. Leaves 1,023 lines of
abstraction maintained by this project, a message-text check for a missing table, and no
answer for LessQL's maintenance risk. The seam also has no stated purpose once the
differential suite goes, which makes its removal a reasonable cleanup proposal that nothing
in the record set currently refuses.

**B. Dissolve the seam and write PostgreSQL directly.** The smallest codebase, and the
honest expression of "one engine forever". It closes the affordance permanently: a
successor wanting another engine rewrites the data access layer rather than supplying a
platform. It also keeps LessQL, which is the dependency with the actual maintenance
problem, so it does not address the risk that prompted this record.

**C. Adopt DBAL as the seam, in the three stages above.** The proposal.

**D. Adopt DBAL together with `doctrine/migrations` and an ORM.** Rejected. The migration
numbering, its reservation record and the squashed baseline are three accepted decisions
([0002](0002-squashed-baseline.md), [0003](0003-seed-data-in-php.md),
[0004](0004-engine-specific-migrations.md)) that work and that a second migration framework
would have to be reconciled with for no stated benefit. An ORM additionally conflicts with
[ADR-0005](0005-wire-contract-is-the-invariant.md): the API currently serialises view rows,
and an entity mapping layer would put a hand-maintained model between the schema and the
wire.

## Consequences

### What it buys

- **A maintained dependency in place of a pinned fork.** DBAL has releases, a security
  process and PHP-version support. `berrnd/lessql` has a commit hash.
- **Typed database errors.** Every place that currently distinguishes failure modes by
  SQLSTATE or message text can ask a class instead. `DatabaseDialect::IsMissingTableError()`
  is the existing instance; the boot check in
  [plan 10](../plans/10-cold-start-statelessness.md) is its caller.
- **One row type.** [Plan 15](../plans/15-deliberate-cleanup.md) item C10 records that
  `StockService` returns LessQL rows from some methods and plain `stdClass` from its
  raw-SQL methods, so callers have to know which they got. Stage 3 removes the cause.
- **A stated home for engine-specific code.** `StockReportsController`'s three hand-written
  joins are plan 15 item C2 precisely because they cross the dialect boundary. That
  boundary keeps a definition.
- **The affordance the maintainer asked for.** A successor with a different deployment
  target changes a platform and a schema rather than the application.

### What it costs

- **A dependency and its closure.** [ADR-0013](0013-nix-built-container-images.md) argues
  for an image that is the transitive closure of what the process needs; DBAL is PHP code
  rather than a system library, but it is still a measurable addition. See prerequisite 3.
- **Two abstractions while both exist.** Between stages 1 and 3 the codebase has a DBAL
  connection underneath a LessQL fluent API, and a reader has to know which layer a given
  call site is in.
- **A wire-contract risk concentrated in stage 3**, and a hard dependency on 14 piece 2 to
  contain it.
- **DBAL's own major-version churn.** DBAL 4 removed APIs that DBAL 3 offered. Taking the
  dependency means tracking that.
- **An affordance that could be misread as a promise.** A record saying "any engine" invites
  bug reports about engines nobody tests. The Decision states the limit; the README and the
  Manual's [Getting started](../manual/getting-started.md) chapter should not restate it more
  warmly.

## Acceptance prerequisites

Gates, not suggestions. The accepting pull request states how each was met.

1. **A spike showing DBAL can type the columns of a view, not only of a table.** Most of
   what this API lists is a view, and `GetColumnTypes()` is what turns an unusable filter
   field into a 400 rather than a 500 or a wrong result set
   ([plan 11](../plans/11-api-error-handling.md)). `PostgresDialect::GetColumnTypes()`
   currently reads `information_schema.columns` restricted to the search path, which
   reports views and tables alike. If DBAL's schema manager does not match that, stage 2 is
   dropped and this record says so rather than leaving it to be discovered.
2. **[14](../plans/14-contract-and-regression-scaffolding.md) piece 2 exists**, or the
   accepting pull request states that stage 3 is out of scope until it does. Stages 1 and 2
   do not need it.
3. **The dependency is measured, not assumed.** `nix path-info -rSh .#image-app` before and
   after, recorded with the date, so
   [ADR-0013](0013-nix-built-container-images.md)'s closure argument stays a measurement.

## Open questions

1. **Does `DatabaseDialect::Create()` gain a registration point, and does
   `ConfigurationValidator`'s allow-list become dynamic?** Today `Create()` is a `switch`
   over `RUNTIME_DRIVERS`, so a third party adding an engine patches two files. A
   `Register(string $driver, callable $factory)` hook removes that, at the cost of making
   the "invalid database driver" message depend on what has been registered by the time
   configuration is validated. *No lean recorded: the mechanism is small, and building it
   before an engine exists to register would produce a hook with no caller and no test.*
2. **Is `Row::jsonSerialize()`'s output reproduced, or is it allowed to change?** Reproducing
   it constrains the replacement types to a shape chosen by an unmaintained library.
   Changing it needs the exception process in
   [ADR-0005](0005-wire-contract-is-the-invariant.md) and a client-impact assessment under
   [17](../plans/17-ecosystem-clients.md). *Lean: reproduce it for stage 3, and treat any
   deliberate change as a separate wire-contract decision, so the two are never confused in
   the same diff.*
3. **DBAL 4 or DBAL 3?** `composer.json` declares PHP `8.5.*`, which points at 4.x, but the
   real language floor is 8.4 and reconciling the two is plan 15 item C7. The answer depends
   on that item.
4. **What happens to the `~` operator's case-insensitivity?**
   `DatabaseDialect::GetLikeCondition()` uses `ILIKE` on PostgreSQL because SQLite's `LIKE`
   folds ASCII case, and the fork documented SQLite's behaviour as the API's. With one
   engine that is no longer a compatibility measure but a standing API choice, and DBAL does
   not abstract it. *Lean: keep the behaviour and restate the reason as a product decision
   rather than a porting artefact, since clients depend on it; but it should be recorded as
   a choice rather than inherited silently.*

## Research

Measurements taken on the working copy of 2026-09-05, reproducible from the repository
root:

```
grep -rn '\$this->DB->' --include='*.php' controllers/ services/ middleware/ helpers/ | wc -l
grep -rn 'ExecuteDbQuery(\|ExecuteDbStatement(' --include='*.php' controllers/ services/ middleware/ helpers/ | wc -l
grep -c 'abstract public function' services/Database/DatabaseDialect.php
wc -l services/Database/DatabaseDialect.php services/Database/PostgresDialect.php services/Database/SqliteDialect.php
wc -l db/pgsql/baseline/*.sql
grep -ho 'CREATE \(OR REPLACE \)\?VIEW' db/pgsql/baseline/*.sql | wc -l
grep -ho '^CREATE TRIGGER' db/pgsql/baseline/*.sql | wc -l
```

The view and trigger counts differ from the table in
[ADR-0008](0008-postgresql-only-runtime-engine.md), which recorded 45 views on 2026-08-30
against 46 here. The trigger count agrees. The discrepancy has not been traced to a
specific view and does not affect either record's argument.

The LessQL fork's source URL and commit are in `composer.lock` under `morris/lessql`.
`Row::jsonSerialize()` is in `packages/morris/lessql/src/LessQL/Row.php`.

DBAL's coverage in the table above is taken from its documented platform and schema-manager
APIs and has not been verified against this schema. Prerequisite 1 is the part that most
needs verifying, because it is the one where a shortfall changes an API response rather
than an implementation detail.
