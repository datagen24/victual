<?php

namespace Victual\Services\Database;

/**
 * Encapsulates everything about persistence that differs between database engines.
 *
 * Victual's data access is split in two: LessQL's fluent API (portable) and hand written
 * SQL in the migrations and a handful of services (not portable). This class is the seam
 * for the second part - connection setup, engine specific functions, and the few SQL
 * fragments that leak into PHP.
 *
 * Since ADR-0008's retirement there is one runtime engine, so the seam has one runtime
 * implementation. It is not therefore dead weight: ADR-0017 (**Proposed**, not accepted)
 * argues that this class stays as the boundary and that Doctrine DBAL replaces the roughly
 * half of its methods DBAL already answers - connection, quoting, platform expressions,
 * schema introspection and error translation - leaving advisory locks, the changed-time
 * bookkeeping and the optimize statement fork-owned. Read that record before removing or
 * inlining anything here; until it is accepted or rejected, neither its direction nor its
 * removal is decided.
 */
abstract class DatabaseDialect
{
	/**
	 * Every driver a running installation may be configured for.
	 *
	 * One entry, since ADR-0008's retirement landed: PostgreSQL is the sole runtime and
	 * the sole behavioural authority. DB_DRIVER accepts nothing else, and Create() below
	 * refuses "sqlite" outright rather than falling back to it.
	 */
	const RUNTIME_DRIVERS = ['pgsql'];

	/**
	 * Every suffix a migration file may carry.
	 *
	 * Wider than RUNTIME_DRIVERS and deliberately so: the SQLite migration line is frozen
	 * rather than deleted (DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID), because
	 * the files up to the freeze are what produced the schemas bin/victual-db-import
	 * accepts and what the differential suite still builds its SQLite side from. A file
	 * named NNNN.<something>.sql is only meaningful if <something> is in this list, and a
	 * list that exists twice is a list that will eventually disagree with itself - so the
	 * migration loader and .devtools/pgsql/check-migrations.php both read it from here.
	 */
	const MIGRATION_DRIVERS = ['pgsql', 'sqlite'];

	/**
	 * The environment variable the differential suite sets to construct a SQLite dialect.
	 *
	 * Not a Setting() and deliberately not one: a configuration setting is exactly the
	 * thing ADR-0008 retired, and anything spelled that way would be a supported way to
	 * run this fork on SQLite. This is a named escape hatch for the one caller that has to
	 * keep working through the transition - the harness in .devtools/pgsql/, which proves
	 * the retirement itself changed nothing and which ADR-0008 keeps until
	 * plan 14 piece 2's response snapshot replaces it. It goes when the harness goes.
	 */
	const SQLITE_TOOLING_ENV = 'DIFFTEST_SQLITE_RUNTIME';

	/**
	 * "pgsql" for a running installation; "sqlite" only for the import and suite dialect,
	 * which no DB_DRIVER value selects (see SQLITE_TOOLING_ENV).
	 */
	abstract public function GetName(): string;

	/**
	 * Creates a new PDO connection. Callers are expected to invoke OnConnected() afterwards.
	 */
	abstract public function CreateConnection(): \PDO;

	/**
	 * Installs engine specific functions and session settings on a fresh connection.
	 */
	abstract public function OnConnected(\PDO $pdo): void;

	/**
	 * The SQL condition implementing the API's "§" (regular expression) query operator,
	 * with a single positional placeholder for the pattern.
	 *
	 * This is part of the public API contract (see BaseApiController::FilterData), so every
	 * dialect has to offer it.
	 */
	abstract public function GetRegexpCondition(string $field): string;

	/**
	 * The SQL condition implementing the API's "~" and "!~" (substring match) query
	 * operators, with a single positional placeholder for the pattern.
	 *
	 * This exists for the same reason GetRegexpCondition() does, and is easier to miss:
	 * the operator is spelled differently per engine because the obvious spelling does not
	 * mean the same thing on both. SQLite's LIKE ignores ASCII case by default, PostgreSQL's
	 * does not, so a literal LIKE in the controller answers the same request with different
	 * rows depending on the engine - silently, since the response shape is identical either
	 * way. SQLite's case insensitivity is the documented behaviour of this API, so the
	 * PostgreSQL side matches it rather than the other way round.
	 *
	 * The agreement is guaranteed for ASCII and only for ASCII. SQLite's LIKE folds A-Z and
	 * nothing else; PostgreSQL's ILIKE folds per the database collation, so on a UTF-8
	 * database a pattern of "æ" also matches "Æ" where SQLite would not. That residual is
	 * deliberate - the alternative is reimplementing one engine's folding table in the
	 * other - and it is what `run-tests.sh filter` measures and prints on every run rather
	 * than leaving to be rediscovered. The API documents the same limit.
	 *
	 * @param bool $negated The "!~" form, which must negate the match rather than be wrapped
	 *                      in NOT by the caller - the two are the same here but only because
	 *                      both engines treat NULL identically, and that is worth pinning
	 *                      down in one place instead of at every call site.
	 */
	abstract public function GetLikeCondition(string $field, bool $negated): string;

	/**
	 * The declared/reported type of every column of $table, keyed by column name.
	 *
	 * Used to validate the fields a caller names in "query[]" and "order" before they reach
	 * SQL, so an unusable one is a 400 rather than whatever the engine happens to do with
	 * it. Works for views as well as tables, because most of what this API lists is a view.
	 *
	 * @return array<string, string> Empty when the table is unknown to the engine.
	 */
	abstract public function GetColumnTypes(\PDO $pdo, string $table): array;

	/**
	 * The types to validate against: the engine's own catalogue, with
	 * ColumnTypeManifest filling only the columns the catalogue could not type.
	 *
	 * This is the method callers want; GetColumnTypes() is the per-engine half of it. It is
	 * concrete and lives here rather than on either dialect precisely because the manifest
	 * has to be applied the same way on both - the whole point of it is that "may I search
	 * this field" stops depending on which engine is answering.
	 *
	 * The manifest fills gaps and never overrides. A catalogue that reports a real type is
	 * describing what the engine will actually do with the column, and no entry here can be
	 * more right about that than the engine is.
	 *
	 * @return array<string, string>
	 */
	final public function GetValidationColumnTypes(\PDO $pdo, string $table): array
	{
		$types = $this->GetColumnTypes($pdo, $table);

		foreach (ColumnTypeManifest::For($table) as $column => $semanticType)
		{
			if (array_key_exists($column, $types) && trim($types[$column]) === '')
			{
				$types[$column] = $semanticType;
			}
		}

		return $types;
	}

	/**
	 * Whether a column of the given declared type can be substring-matched.
	 *
	 * Deliberately one rule for both engines rather than one per dialect, because the
	 * point of it is that the two agree. It is SQLite's own TEXT-affinity rule - a declared
	 * type containing CHAR, CLOB or TEXT - which also happens to select exactly
	 * PostgreSQL's "text", "character varying" and "character" out of information_schema.
	 *
	 * Everything else is rejected on both engines, including timestamps. SQLite stores
	 * those as text and would happily match "2026-08" against one; PostgreSQL cannot,
	 * because a TIMESTAMP is not a string there. Rendering one to text so both could match
	 * is a real feature and a real decision - which format, in which time zone, with which
	 * precision - and it is not one to arrive at by accident through whatever CAST each
	 * engine happens to implement. Until that is designed, the honest answer is that this
	 * API does not offer substring matching on a timestamp, on either engine.
	 */
	public static function IsTextMatchableType(string $declaredType): bool
	{
		$type = strtoupper($declaredType);

		return str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT');
	}

	/**
	 * An SQL expression yielding the current local (not UTC) timestamp.
	 */
	abstract public function GetNowExpression(): string;

	/**
	 * The column type used for timestamps.
	 */
	abstract public function GetTimestampType(): string;

	/**
	 * Directory holding the squashed baseline schema for this engine, relative to the
	 * application root, or null when the engine replays the migration history instead.
	 */
	public function GetBaselineSchemaPath(): ?string
	{
		return null;
	}

	/**
	 * The statement to reclaim space / refresh planner statistics after migrations,
	 * or null when the engine needs nothing.
	 */
	abstract public function GetOptimizeStatement(): ?string;

	/**
	 * Runs $work with a cross process lock held, so that only one migration run at a
	 * time touches this database.
	 *
	 * DatabaseMigrationService is check-then-apply from end to end - does this migration
	 * number exist, is the migrations table empty, does location 1 exist - and two
	 * processes starting together interleave those checks. The losing one rolls back on
	 * a primary key violation rather than corrupting anything, but it exits non-zero, and
	 * an initContainer that fails because a sibling won is an outage rather than a
	 * no-op. The always-run 8888 fixup is worse: it is outside the per-migration
	 * try/catch, so its race has nothing to catch it at all.
	 *
	 * It lives on the dialect because locking is where engines differ most, but there is
	 * only one real implementation: PostgreSQL's advisory lock. Under
	 * ADR-0008 PostgreSQL is the only runtime engine, and SqliteDialect's version is a
	 * deliberate no-op - see the reasoning there. Engine-neutral composition of work
	 * belongs on DatabaseService (see InTransaction); this is its counterpart.
	 *
	 * It wraps the entire MigrateDatabase() call rather than each migration, baseline
	 * included, because the checks that race are spread across all of it.
	 *
	 * @param callable $work Receives no arguments; its return value is passed through
	 * @return mixed Whatever $work returns
	 * @throws \Throwable Whatever $work throws, after the lock is released
	 */
	abstract public function WithMigrationLock(callable $work);

	/**
	 * Runs $work with a cross process lock held, so that only one process at a time
	 * assembles and publishes the retained MQTT state.
	 *
	 * It takes a key of its own rather than sharing one with WithMigrationLock() above,
	 * because two locks that guard unrelated things should not make callers of one wait on
	 * the other - a publish at the end of a request has no reason to queue behind a
	 * migration run, and vice versa.
	 *
	 * The race it closes is a lost update with no error anywhere. Publishing is
	 * read-then-write across a network: request A assembles the snapshot, request B commits
	 * a later change and publishes it, then A publishes the state it read earlier. Retained
	 * topics have no version and no ordering, so the broker simply keeps whatever arrived
	 * last - and A's stale snapshot stands until the next write, which on a pod that sleeps
	 * for days is exactly the failure this whole plan exists to prevent. Nothing logs it,
	 * because nothing failed.
	 *
	 * **The assembly has to be inside the lock, not just the publish.** A lock around the
	 * publish alone still lets both requests read before either writes, which is the same
	 * lost update with a smaller window. Holding it across assemble, diff, publish and the
	 * ledger update makes the loser re-read after the winner released, so it publishes
	 * state that is at least as new.
	 *
	 * It lives on the dialect for the same reason WithMigrationLock() does, and has the
	 * same single real implementation: PostgreSQL's advisory lock, with SqliteDialect's
	 * version a deliberate no-op.
	 *
	 * @param callable $work Receives no arguments; its return value is passed through
	 * @return mixed Whatever $work returns
	 * @throws \Throwable Whatever $work throws, after the lock is released
	 */
	abstract public function WithPublicationLock(callable $work);

	/**
	 * Takes a transaction scoped advisory lock on one product's stock rows, closing the
	 * read-then-write window every stock booking path opens between reading `stock` /
	 * `stock_log` and deciding what to write (issue #458): two concurrent bookings of the
	 * same product can otherwise both read the pre-write state, both pass whatever check
	 * that state supports, and together do something neither read alone justified - an
	 * over-consume past zero, or an undo racing the very booking its "any subsequent
	 * booking" guard exists to catch.
	 *
	 * Deliberately transaction scoped (pg_advisory_xact_lock), not session scoped like
	 * WithMigrationLock() and WithPublicationLock() above. Those wrap one call that owns
	 * its whole lifetime; a stock booking does not - DatabaseService::InTransaction() lets
	 * an inner call join whatever transaction its caller already opened (ConsumeRecipe()
	 * calling ConsumeProduct() per ingredient, UndoTransaction() calling UndoBooking() per
	 * line, OpenProduct() calling TransferProduct() when "move on open" is set), so a lock
	 * taken by an inner call has to survive until the outermost commit or rollback, not
	 * until the inner call returns. A transaction scoped lock does exactly that, taken from
	 * anywhere in the call graph, released only when the transaction it was taken inside
	 * ends - and calling it again for a product already locked in the same transaction is
	 * a cheap no-op rather than a self-deadlock.
	 *
	 * Uses pg_advisory_xact_lock's two-integer form, keyed on a class id
	 * (PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS) and the product id as the object
	 * id. That form's lock identity is the 64-bit concatenation of the two integers, so it
	 * only shares a keyspace with WithMigrationLock()'s and WithPublicationLock()'s
	 * single-bigint locks when the class id is zero; a nonzero class id keeps this
	 * incapable of colliding with either regardless of which product id is locked.
	 *
	 * A caller touching more than one product in the same transaction (MergeProducts(),
	 * RecipesService::ConsumeRecipe() consuming several ingredients) must lock every
	 * product id it will touch, in ascending order, before writing any of them - otherwise
	 * two such callers touching an overlapping set in different orders can each hold one
	 * lock the other needs and deadlock. See DatabaseService::LockProductsStock().
	 *
	 * Requires a transaction already open on $pdo: a transaction scoped lock taken with no
	 * transaction open releases the instant the statement finishes, before the caller's
	 * next statement runs, which would silently provide no protection at all rather than
	 * fail loudly. DatabaseService::LockProductStock() is the caller-facing wrapper that
	 * enforces this.
	 *
	 * SqliteDialect's version is a deliberate no-op, for the same reason as
	 * WithMigrationLock()'s and WithPublicationLock()'s: under ADR-0008 SQLite is not a
	 * concurrent runtime engine.
	 *
	 * @param \PDO $pdo The connection already inside the caller's transaction
	 * @param int $productId
	 * @return void
	 */
	abstract public function LockProductStock(\PDO $pdo, int $productId): void;

	/**
	 * Whether the given driver error means "that table does not exist", as opposed to any
	 * other reason a query can fail.
	 *
	 * This exists for one caller and one question: the boot check asks an untouched
	 * database for its applied migrations, and on a database nobody has migrated yet the
	 * "migrations" table genuinely is not there. That single condition maps to "nothing
	 * applied". Everything else a query can fail with - the server being unreachable, the
	 * role not being allowed to read the table, a statement timeout, a typo in the SQL -
	 * means the schema version is *unknown*, which is a different answer and needs a
	 * different response. Catching them all and calling the result zero tells an operator
	 * whose database is down to run migrations against it.
	 *
	 * Per engine because the engines say it differently, and one of them says it badly:
	 * PostgreSQL has a dedicated SQLSTATE, SQLite reports nearly everything as HY000.
	 *
	 * Deliberately strict rather than lenient. A false negative surfaces a genuinely
	 * missing table as an unavailable database, which is noisy but honest; a false
	 * positive is the defect this method exists to prevent.
	 */
	abstract public function IsMissingTableError(\PDOException $ex): bool;

	/**
	 * The SQLSTATE the driver reported for an error, or null when it reported none.
	 *
	 * PDOException carries it twice - as the exception code and as the first element of
	 * errorInfo - and the two can disagree when the exception was constructed by
	 * something other than the driver, so errorInfo wins where it exists.
	 */
	final protected static function SqlStateOf(\PDOException $ex): ?string
	{
		$sqlState = $ex->errorInfo[0] ?? $ex->getCode();

		return is_string($sqlState) && $sqlState !== '' ? $sqlState : null;
	}

	/**
	 * Quotes a single table or column name for safe interpolation into SQL.
	 */
	abstract public function QuoteIdentifier(string $name): string;

	/**
	 * The character LessQL wraps table and column names in. LessQL defaults to the MySQL
	 * backtick, which PostgreSQL rejects outright.
	 */
	abstract public function GetIdentifierDelimiter(): string;

	/**
	 * When the data last changed, as "Y-m-d H:i:s".
	 *
	 * Exposed publicly as GET /api/system/db-changed-time and used by the iOS app and the
	 * Home Assistant integration to decide whether to refetch, so the semantics have to hold
	 * across engines: it must advance on data changes and must NOT advance for bookkeeping
	 * writes such as session or API key last-used stamps.
	 */
	abstract public function GetDbChangedTime(\PDO $pdo): string;

	/**
	 * Overwrites the changed time with an explicit value ("Y-m-d H:i:s" or anything
	 * strtotime() accepts). Used to restore the previous value after bookkeeping writes.
	 */
	abstract public function SetDbChangedTime(\PDO $pdo, string $dateTime): void;

	/**
	 * Records that data changed. May defer the actual write until FlushDbChangedTime().
	 */
	abstract public function MarkDbChanged(\PDO $pdo): void;

	/**
	 * Persists a pending MarkDbChanged(). Called once at the end of a request.
	 */
	public function FlushDbChangedTime(\PDO $pdo): void
	{
	}

	/**
	 * Brings generated-id counters back in line with the data.
	 *
	 * SQLite's AUTOINCREMENT tracks the highest id ever used, so inserting an explicit id
	 * implicitly moves the counter past it. Other engines do not necessarily do that, and
	 * would then hand out ids which already exist. Anything that inserts explicit ids -
	 * migrations, demo data, importing an existing database - has to call this afterwards.
	 */
	public function ResyncGeneratedIdCounters(\PDO $pdo): void
	{
	}

	/**
	 * Tells the connection which user it is acting for, so that SQL side helpers resolving
	 * user settings work. Called once authentication has established the user.
	 */
	public function SetCurrentUserId(\PDO $pdo, $userId): void
	{
	}

	/**
	 * True when a single PDO::exec() may contain several semicolon separated statements,
	 * which is how the .sql migration files are written.
	 */
	public function SupportsMultiStatementExec(): bool
	{
		return true;
	}

	/**
	 * True when the changed time has to be maintained by inspecting executed statements.
	 * Engines where the storage layer already tracks it (SQLite, via the file modification
	 * time) return false so that no per-query work is done at all.
	 */
	public function RequiresChangeTracking(): bool
	{
		return true;
	}

	/**
	 * True when the SQL in the given string writes data, used to keep the changed time
	 * accurate for the raw SQL paths that bypass LessQL.
	 */
	public function IsWriteStatement(string $sql): bool
	{
		return preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $sql) === 1;
	}

	/**
	 * Factory: picks the dialect matching the VICTUAL_DB_DRIVER setting ("pgsql" when
	 * undefined). Called once per request by DatabaseService.
	 *
	 * The default is PostgreSQL rather than SQLite as it was before ADR-0008's retirement,
	 * and that change is louder than it looks: an installation whose config.php never named
	 * a driver used to open a file and now needs connection settings. That is the retirement
	 * rather than a side effect of it - there is no engine left for the old default to mean -
	 * and ConfigurationValidator says so in the message an operator actually reads.
	 *
	 * @throws \Exception On an unsupported driver value
	 */
	public static function Create(): self
	{
		$driver = defined('VICTUAL_DB_DRIVER') ? strtolower(VICTUAL_DB_DRIVER) : 'pgsql';

		switch ($driver)
		{
			case 'pgsql':
				return new PostgresDialect();

			case 'sqlite':
				// Not a supported configuration: see SQLITE_TOOLING_ENV for the one caller
				// that may still ask, and why it is an environment variable rather than a
				// setting. Everyone else gets the message that names the way out.
				if (self::SqliteToolingIsPermitted())
				{
					return new SqliteDialect();
				}

				throw new \Exception('SQLite is no longer a runtime database engine (ADR-0008): '
					. 'set DB_DRIVER to "pgsql" and the DB_* connection settings, then move the '
					. 'existing database across with "php bin/victual-db-import '
					. '/path/to/victual.db".');

			default:
				throw new \Exception('Unsupported database driver "' . $driver . '", only '
					. implode(' and ', self::RUNTIME_DRIVERS) . ' is supported');
		}
	}

	/**
	 * Whether this process is the differential suite, which may still build a SQLite
	 * database. See SQLITE_TOOLING_ENV.
	 *
	 * Public because ConfigurationValidator has to give the same answer: it runs first, from
	 * bin/victual-migrate and from app.php, so a suite run would be refused there before
	 * Create() was ever reached. One method rather than the same getenv() in two files, for
	 * the reason MIGRATION_DRIVERS gives - the copy is what eventually disagrees.
	 *
	 * Read from the environment on every call rather than memoized: the suite sets it once
	 * for a whole run and nothing in a run flips it, so there is nothing to cache, and a
	 * memo here would be process state about configuration - the category ADR-0007 keeps
	 * out of this layer.
	 */
	public static function SqliteToolingIsPermitted(): bool
	{
		$value = getenv(self::SQLITE_TOOLING_ENV);

		return $value !== false && $value !== '' && $value !== '0';
	}
}
