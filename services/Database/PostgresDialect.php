<?php

namespace Victual\Services\Database;

/**
 * PostgreSQL storage engine.
 *
 * Unlike SQLite, PostgreSQL cannot call back into PHP, so the helper functions Victual
 * registers per connection on SQLite (regexp, victual_user_setting, ceil) are instead
 * provided natively: regexp maps onto the "~" operator, ceil already exists, and
 * victual_user_setting is an SQL function installed by the baseline schema which resolves
 * the acting user from a session variable set by SetCurrentUserId().
 */
class PostgresDialect extends DatabaseDialect
{
	/**
	 * Single-row table replacing SQLite's file modification time as the store for the
	 * "when did data last change" timestamp (see GetDbChangedTime()).
	 */
	const CHANGED_TIME_TABLE = 'system_db_changed_time';

	/**
	 * The key WithMigrationLock() takes its advisory lock on.
	 *
	 * PostgreSQL keeps advisory locks in one database-wide namespace of arbitrary 64 bit
	 * integers, so the value only has to be a number nothing else in this database picks.
	 * It is the ASCII bytes of "vict" (0x76696374) - a constant chosen to be recognisable
	 * in pg_locks rather than to mean anything.
	 */
	const MIGRATION_ADVISORY_LOCK_KEY = 1986947956;

	/**
	 * The key WithPublicationLock() takes its advisory lock on.
	 *
	 * A number of its own rather than one shared with MIGRATION_ADVISORY_LOCK_KEY above,
	 * since two locks that guard unrelated things should not make callers of one wait on
	 * the other: a publish at the end of a request has no reason to queue behind a
	 * migration run. It is the ASCII bytes of "vic" followed by "P" for publish
	 * (0x766963 50), chosen on the same principle - recognisable in pg_locks rather than
	 * meaningful.
	 */
	const PUBLICATION_ADVISORY_LOCK_KEY = 1986943824;

	/**
	 * The class id LockProductStock() takes its advisory lock's two-integer form on, with
	 * the product id as the object id.
	 *
	 * A class id of its own rather than 0 (which would make the lock identity just the
	 * product id) is what keeps this keyspace from ever overlapping
	 * MIGRATION_ADVISORY_LOCK_KEY's or PUBLICATION_ADVISORY_LOCK_KEY's single-bigint locks:
	 * pg_advisory_xact_lock(classid, objid)'s identity is those two 32-bit integers
	 * concatenated into one 64-bit value, which only coincides with a single-bigint lock's
	 * identity when classid is 0. It is the ASCII bytes of "vicS" ("vic" for the same
	 * reason as the other two, "S" for stock), 0x76696353.
	 */
	const STOCK_BOOKING_ADVISORY_LOCK_CLASS = 1986618195;

	/** @var bool True while a data change has been recorded but not yet written to the changed time table */
	private $DbChangedPending = false;

	public function GetName(): string
	{
		return 'pgsql';
	}

	/**
	 * Connects using the VICTUAL_DB_* settings (host, port, name, user, password, sslmode).
	 * The PDO attributes deliberately match the SQLite dialect so both engines surface
	 * errors and NULLs identically.
	 */
	public function CreateConnection(): \PDO
	{
		$pdo = new \PDO\Pgsql($this->GetDsn(), VICTUAL_DB_USER, VICTUAL_DB_PASSWORD);
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);

		return $pdo;
	}

	/**
	 * The oldest PostgreSQL major version this application runs on: the oldest the test
	 * suite is run against. 14 is known not to work - migration 0273 declares
	 * `UNIQUE NULLS NOT DISTINCT`, which PostgreSQL added in 15 - and CI covers this
	 * version as well as the newest one, so the number does not drift from what is tested.
	 * Raising it means changing the CI matrix in the same commit.
	 */
	public const MINIMUM_MAJOR_VERSION = 15;

	/**
	 * Refuses a server older than MINIMUM_MAJOR_VERSION.
	 *
	 * A version string this cannot read is let through: it is a server that is not
	 * PostgreSQL by name (a compatible engine reports its own scheme), and refusing it on
	 * a parse failure would turn a version check into a compatibility claim nobody made.
	 *
	 * @param string $serverVersion As PDO reports it, e.g. "16.13 (Debian 16.13-1.pgdg13+1)"
	 * @throws \RuntimeException When the major version is below the minimum
	 */
	public static function AssertSupportedServerVersion(string $serverVersion): void
	{
		if (!preg_match('/^(\d+)/', trim($serverVersion), $matches))
		{
			return;
		}

		if ((int)$matches[1] < self::MINIMUM_MAJOR_VERSION)
		{
			throw new \RuntimeException('Victual needs PostgreSQL ' . self::MINIMUM_MAJOR_VERSION
				. ' or newer, and the database server reports version ' . $serverVersion
				. '. Upgrade the server, or point DB_HOST at one that is new enough.');
		}
	}

	/**
	 * Aligns the session time zone with PHP's and bootstraps the changed time table,
	 * which must exist before the very first migration can run.
	 */
	public function OnConnected(\PDO $pdo): void
	{
		// Before anything is sent to the server: an unsupported version fails here, with
		// the reason, rather than partway through a migration with a syntax error.
		// PDO::ATTR_SERVER_VERSION is the server_version parameter libpq was told at
		// connect time, so this costs no round trip on the per-request path.
		self::AssertSupportedServerVersion((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));

		// SQLite's datetime('now', 'localtime') follows the process time zone - make
		// LOCALTIMESTAMP agree with it so timestamps mean the same thing on both engines
		$pdo->exec("SET TIME ZONE " . $pdo->quote(date_default_timezone_get()));

		// Everything else this dialect needs is created by the baseline schema migration.
		// The changed time table is the exception: it has no dependencies and has to exist
		// before the first migration runs, because migrating is itself a data change.
		//
		// This is the one piece of schema work that cannot be inside the migration lock -
		// it happens while the connection the lock would be taken on is being opened - and
		// PostgreSQL's CREATE TABLE IF NOT EXISTS is documented as not being free of race
		// conditions: two connections opening against an empty database at the same moment
		// both find the table missing and one fails on pg_type's unique index. The failure
		// means the table now exists, which is all this wanted, so it is not an error here.
		// Without this catch, two pods starting together fail before the lock is reached
		// and the whole guard below is untestable.
		//
		// The table is looked up before it is created, and that is not an optimisation:
		// PostgreSQL checks CREATE on the schema *before* it checks whether the table
		// exists, so a "CREATE TABLE IF NOT EXISTS" of a table that is already there still
		// fails with 42501 for a role that has no CREATE - which is exactly the role the
		// serving image is meant to hold (ADR-0010 property 3, plan 20 verification 8).
		// This ran on every connection, so every request under such a role was a 500.
		// to_regclass() answers from the catalog with no privilege beyond USAGE on the
		// schema, and the CREATE below is then reached only by the role that migrates.
		$exists = $pdo->prepare('SELECT to_regclass(?)');
		$exists->execute([self::CHANGED_TIME_TABLE]);

		if ($exists->fetchColumn() === null)
		{
			try
			{
				$pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::CHANGED_TIME_TABLE . ' ('
					. 'id INTEGER NOT NULL PRIMARY KEY, '
					. 'changed_time TIMESTAMP NOT NULL DEFAULT LOCALTIMESTAMP)');
			}
			catch (\PDOException $ex)
			{
				// 42P07 duplicate_table, 23505 unique_violation (the pg_type index), 23P01
				// exclusion_violation - the three shapes the lost race takes
				if (!in_array($ex->getCode(), ['42P07', '23505', '23P01'], true))
				{
					throw $ex;
				}
			}
		}

		$pdo->exec('INSERT INTO ' . self::CHANGED_TIME_TABLE . ' (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
	}

	/**
	 * The "~" operator - PostgreSQL's native, case sensitive POSIX regex match.
	 */
	public function GetRegexpCondition(string $field): string
	{
		// PostgreSQL has no REGEXP operator; "~" is the case sensitive equivalent and,
		// like SQLite's REGEXP via mb_ereg, treats the pattern as a POSIX regular expression
		return $field . ' ~ ?';
	}

	/**
	 * ILIKE, not LIKE - PostgreSQL's LIKE is case sensitive where SQLite's is not.
	 */
	public function GetLikeCondition(string $field, bool $negated): string
	{
		// ILIKE folds case using the database collation, so it agrees with SQLite's ASCII
		// only folding on ASCII and is more correct beyond it - the same trade this port
		// already makes for COLLATE NOCASE (hazard 15 in db/pgsql/README.md)
		return $field . ($negated ? ' NOT ILIKE ?' : ' ILIKE ?');
	}

	/**
	 * information_schema.columns, which covers views as well as tables. Restricted to the
	 * search path so a same-named table in another schema cannot answer for this one.
	 */
	public function GetColumnTypes(\PDO $pdo, string $table): array
	{
		$statement = $pdo->prepare(
			'SELECT column_name, data_type FROM information_schema.columns '
			. 'WHERE table_name = ? AND table_schema = ANY(current_schemas(false))'
		);
		$statement->execute([$table]);

		$types = [];

		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $column)
		{
			$types[$column['column_name']] = $column['data_type'];
		}

		return $types;
	}

	/**
	 * LOCALTIMESTAMP truncated to seconds, equivalent to SQLite's
	 * datetime('now', 'localtime') given the SET TIME ZONE in OnConnected().
	 */
	public function GetNowExpression(): string
	{
		// SQLite stores second precision, so truncate to match
		return "date_trunc('second', LOCALTIMESTAMP)";
	}

	public function GetTimestampType(): string
	{
		return 'TIMESTAMP';
	}

	/**
	 * PostgreSQL installs from a squashed baseline schema (db/pgsql/baseline, equivalent
	 * to SQLite migrations 0001-0255) instead of replaying the SQLite-only migration history.
	 */
	public function GetBaselineSchemaPath(): ?string
	{
		return __DIR__ . '/../../db/pgsql/baseline';
	}

	public function GetOptimizeStatement(): ?string
	{
		// VACUUM cannot run inside a transaction block and PostgreSQL reclaims space via
		// autovacuum anyway; refreshing planner statistics is what actually matters after
		// a schema migration
		return 'ANALYZE';
	}

	/**
	 * Serialises migration runs on a session level advisory lock.
	 *
	 * Advisory locks are the right tool because nothing here is a row or a table: what is
	 * being serialised is "a migration run against this database", which has no object to
	 * hang a lock on. It is taken on the raw connection because a session level lock lives
	 * on the connection that took it - which is also what makes a crash safe, since a
	 * dying process closes its connection and PostgreSQL releases the lock without anyone
	 * having to clean up.
	 *
	 * pg_advisory_lock() blocks until it can be taken, and is reentrant within one
	 * session, so a nested call cannot deadlock against itself.
	 *
	 * **This lock requires a direct connection, or a pool in session mode.** A session
	 * level advisory lock lives on a backend, and a transaction-mode pooler (pgbouncer's
	 * default, and the obvious thing to reach for once many short-lived pods each open
	 * connections) is free to hand the unlock to a different backend than the lock - which
	 * leaks the lock permanently and wedges every later migration run. ADR-0009's finding
	 * F1 records this; the transaction-scoped pg_advisory_xact_lock() is the safe form
	 * where the whole run fits in one transaction, and this one deliberately does not,
	 * because it wraps a run that opens and commits transactions of its own. So the
	 * requirement is on the deployment: bin/victual-migrate connects to PostgreSQL
	 * directly, or through a session-mode pool entry.
	 */
	public function WithMigrationLock(callable $work)
	{
		$pdo = \Victual\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();

		$pdo->prepare('SELECT pg_advisory_lock(?)')->execute([self::MIGRATION_ADVISORY_LOCK_KEY]);

		try
		{
			return $work();
		}
		finally
		{
			$pdo->prepare('SELECT pg_advisory_unlock(?)')->execute([self::MIGRATION_ADVISORY_LOCK_KEY]);
		}
	}

	/**
	 * A session level advisory lock on PUBLICATION_ADVISORY_LOCK_KEY, held across the whole
	 * assemble-publish-record cycle so two requests cannot interleave a read of the state
	 * with a write of it.
	 *
	 * Taken on the raw connection, because a session level lock lives on the connection
	 * that took it - which is also what makes a crash safe, since a dying process closes
	 * its connection and PostgreSQL releases the lock with nobody having to clean up.
	 * pg_advisory_lock() blocks until it can be taken and is reentrant within one session,
	 * so nesting cannot deadlock against itself.
	 *
	 * **This lock requires a direct connection, or a pool in session mode**, for exactly
	 * the reason WithMigrationLock() above gives, with a different consequence: a leaked
	 * publication lock makes every later publish block in a shutdown handler until the
	 * connect timeout, on every request that writes. ADR-0009's finding F1 records the
	 * mechanism. The transaction-scoped pg_advisory_xact_lock() is the safe form where the
	 * work fits in one transaction, and this one deliberately does not: it runs at the end
	 * of a request with every transaction already closed, which is the whole point of the
	 * seam it is called from.
	 */
	public function WithPublicationLock(callable $work)
	{
		$pdo = \Victual\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();

		$pdo->prepare('SELECT pg_advisory_lock(?)')->execute([self::PUBLICATION_ADVISORY_LOCK_KEY]);

		try
		{
			return $work();
		}
		finally
		{
			$pdo->prepare('SELECT pg_advisory_unlock(?)')->execute([self::PUBLICATION_ADVISORY_LOCK_KEY]);
		}
	}

	/**
	 * pg_advisory_xact_lock(classid, objid) on STOCK_BOOKING_ADVISORY_LOCK_CLASS and
	 * $productId. Transaction scoped: it releases automatically at the commit or rollback
	 * of whichever transaction is open on $pdo when this runs, wherever in the call graph
	 * that was, and blocks until it can be taken rather than failing - a booking waits for
	 * the one ahead of it rather than refusing.
	 *
	 * See DatabaseDialect::LockProductStock() for why this is transaction rather than
	 * session scoped and why the two-integer form is what keeps it from colliding with
	 * MIGRATION_ADVISORY_LOCK_KEY or PUBLICATION_ADVISORY_LOCK_KEY.
	 */
	public function LockProductStock(\PDO $pdo, int $productId): void
	{
		$pdo->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([self::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);
	}

	/**
	 * Whether the error is PostgreSQL's undefined_table.
	 *
	 * 42P01 and nothing else. PostgreSQL gives every error condition its own SQLSTATE, so
	 * unlike SQLite there is no need to read the message: connection failures (08xxx),
	 * insufficient_privilege (42501) and query_canceled (57014) all say so in the code,
	 * and none of them means "nothing has been migrated yet".
	 */
	public function IsMissingTableError(\PDOException $ex): bool
	{
		return self::SqlStateOf($ex) === '42P01';
	}

	/**
	 * Standard SQL double-quote quoting (the only form PostgreSQL accepts).
	 */
	public function QuoteIdentifier(string $name): string
	{
		return '"' . str_replace('"', '""', $name) . '"';
	}

	public function GetIdentifierDelimiter(): string
	{
		// Victual's tables and columns are all lower case, so quoting them is safe and also
		// covers the ones which would otherwise collide with reserved words
		return '"';
	}

	/**
	 * Reads the changed time from the tracking table (SQLite reads the file modification
	 * time instead). Flushes any pending change first so callers within the same request
	 * see their own writes; falls back to "now" when the row is missing.
	 */
	public function GetDbChangedTime(\PDO $pdo): string
	{
		$this->FlushDbChangedTime($pdo);

		$value = $pdo->query('SELECT changed_time FROM ' . self::CHANGED_TIME_TABLE . ' WHERE id = 1')->fetchColumn();

		if ($value === false || $value === null)
		{
			return date('Y-m-d H:i:s');
		}

		return date('Y-m-d H:i:s', strtotime($value));
	}

	/**
	 * Writes an explicit changed time, discarding any pending deferred change
	 * (the SQLite equivalent is touch()ing the database file).
	 */
	public function SetDbChangedTime(\PDO $pdo, string $dateTime): void
	{
		// An explicit set overrides whatever change was pending - this is how the session
		// and API key services keep their last-used bookkeeping from invalidating client caches
		$this->DbChangedPending = false;

		$statement = $pdo->prepare('UPDATE ' . self::CHANGED_TIME_TABLE . ' SET changed_time = ? WHERE id = 1');
		$statement->execute([date('Y-m-d H:i:s', strtotime($dateTime))]);
	}

	/**
	 * Only sets a flag; the actual UPDATE happens in FlushDbChangedTime().
	 */
	public function MarkDbChanged(\PDO $pdo): void
	{
		// Deferred so that a request writing many rows still only costs one extra UPDATE
		$this->DbChangedPending = true;
	}

	/**
	 * Writes the deferred changed time, if any. Called at request shutdown by
	 * DatabaseService and before every GetDbChangedTime() read.
	 */
	public function FlushDbChangedTime(\PDO $pdo): void
	{
		if (!$this->DbChangedPending)
		{
			return;
		}

		$this->DbChangedPending = false;
		$pdo->exec('UPDATE ' . self::CHANGED_TIME_TABLE . ' SET changed_time = LOCALTIMESTAMP WHERE id = 1');
	}

	/**
	 * @return bool The current value of $DbChangedPending
	 */
	public function CapturePendingChangeState()
	{
		return $this->DbChangedPending;
	}

	/**
	 * @param bool $state A value previously returned by CapturePendingChangeState()
	 */
	public function RestorePendingChangeState($state): void
	{
		$this->DbChangedPending = (bool)$state;
	}

	/**
	 * Sets every identity column's sequence to MAX(id) + 1, or leaves it where it already
	 * is if that is higher (at least 1 either way), needed after inserting rows with
	 * explicit ids (migrations, demo data, database import).
	 *
	 * Uses setval(), which is not atomic against a concurrent nextval() the way
	 * AdvanceIdentitySequence() below is - deliberately left this way here. Every caller
	 * of this method (InitialDataSeeder, DatabaseMigrationService, DatabaseImporter,
	 * DemoDataGeneratorService) runs as a single process against a database nothing else
	 * is concurrently writing to yet - a migration or an import in progress, not a live
	 * application serving requests - so there is no concurrent nextval() for the
	 * read-then-write race to lose. AdvanceIdentitySequence() exists because its own
	 * caller (UndoBooking()'s CONSUME rebuild) runs at request time, where that
	 * assumption does not hold.
	 */
	public function ResyncGeneratedIdCounters(\PDO $pdo): void
	{
		// GENERATED BY DEFAULT AS IDENTITY leaves its sequence untouched when a row is
		// inserted with an explicit id, unlike SQLite's AUTOINCREMENT. Without this the
		// sequence eventually catches up with rows that already exist and inserts start
		// failing on the primary key.
		//
		// Never move a sequence *backward* (#555): setting it to exactly MAX(id) + 1
		// regardless of where it already stood let a batch that deleted the newest rows -
		// an undo, a rolled-back migration, a partial import - pull the sequence back down
		// with them, so the very next ordinary insert reissued an id that had already been
		// handed out once. That silently handed a deleted row's old identity to an
		// unrelated new row: services/StockService.php's own undo logic depends on an id,
		// once issued, never coming back once it is freed (see UndoBooking()'s CONSUME/
		// negative-INVENTORY_CORRECTION rebuild, and the stock_id cross-check every
		// stock_row_id match now also carries as its own defence in depth). Taking the
		// greater of the sequence's own current next value (last_value, plus one only if
		// is_called - an untouched sequence's last_value is just its seed, not something
		// already issued) and MAX(id) + 1 keeps this call idempotent and forward-only
		// without a floor that can retreat.
		$pdo->exec(
			'DO $$
			DECLARE
				r RECORD;
				seq_name text;
				seq_last_value bigint;
				seq_is_called boolean;
				next_from_seq bigint;
				next_from_data bigint;
			BEGIN
				FOR r IN
					SELECT table_name, column_name
					FROM information_schema.columns
					WHERE table_schema = current_schema()
						AND is_identity = \'YES\'
				LOOP
					seq_name := pg_get_serial_sequence(quote_ident(r.table_name), r.column_name);
					EXECUTE format(\'SELECT last_value, is_called FROM %s\', seq_name) INTO seq_last_value, seq_is_called;
					next_from_seq := seq_last_value + CASE WHEN seq_is_called THEN 1 ELSE 0 END;
					EXECUTE format(\'SELECT COALESCE(MAX(%I), 0) + 1 FROM %I\', r.column_name, r.table_name) INTO next_from_data;
					-- GREATEST also keeps the floor of 1, because some tables hold rows
					-- with negative ids on purpose (meal_plan_sections has the internal
					-- section at -1) and a sequence cannot be set below 1.
					PERFORM setval(seq_name, GREATEST(next_from_seq, next_from_data, 1), false);
				END LOOP;
			END $$;'
		);
	}

	/**
	 * The most ids AdvanceIdentitySequence() will draw through nextval() to close a gap,
	 * refusing rather than paying an unbounded cost past it (CodeRabbit review of PR #577,
	 * inline comment 4117658616).
	 *
	 * Measured directly against this method's own generate_series()/nextval() query
	 * (2026-09-27): draws run at roughly 6.4 million nextval() calls per second, linear in
	 * the gap - a gap of 1e5 costs about 17ms and 1e6 about 157ms, both negligible next to
	 * everything else a request does. The worst case is what sets the cap, not the common
	 * one: a gap approaching PostgreSQL's INTEGER range measured at roughly 5.6 minutes and
	 * roughly 39GB of temporary files, all while holding StockService::LockProductStock()'s
	 * advisory lock on this product for the whole duration. That worst case needs a gap of
	 * over two billion between a booking's recorded stock_row_id and the sequence's actual
	 * position, which only operator-imported data can open - stock_log is not otherwise
	 * editable through the API, so nothing reachable through it lets a booking's own id get
	 * that far ahead of the sequence - and a gap a real import leaves is ordinarily small.
	 * 100,000 keeps the ordinary case (see the per-100k cost above) unrestricted while
	 * refusing the pathological one.
	 *
	 * When the cap refuses, AdvanceIdentitySequence()'s only caller (UndoBooking()'s CONSUME
	 * rebuild, in services/StockService.php) does not reuse the recorded id: it inserts the
	 * rebuilt row under a fresh, ordinary one instead - exactly what it already does for a
	 * booking with no recorded stock_row_id at all. A later TRANSFER_TO/FROM or
	 * PRODUCT_OPENED undo that still names the abandoned id then refuses safely on its own,
	 * the same refusal #531 already gives any of those undos whenever that id's row is
	 * simply gone - reusing the id is only ever an optimization that keeps such a later undo
	 * working, never a correctness requirement of the rebuild itself, so refusing to chase it
	 * past this cap costs nothing but that optimization.
	 */
	public const MAX_SEQUENCE_ADVANCE_GAP = 100000;

	/**
	 * Advances one identity column's sequence to at least $minNextValue, never backward -
	 * the same never-backward invariant ResyncGeneratedIdCounters() keeps for a whole
	 * schema (#555), scoped here to the one sequence a caller already knows needs
	 * advancing. An explicit-id INSERT (services/StockService.php's own consume-undo
	 * rebuild reuses a deleted row's original id, so a later-undone booking naming that
	 * same id still finds it) bypasses the sequence entirely - nothing else moves it past
	 * that id on its own, and DatabaseImporter::Import()'s own resync (from the target's
	 * surviving row maximum, not from an id a booking merely names) can leave the
	 * sequence sitting at or below an id that is nonetheless taken again by then. Unlike
	 * ResyncGeneratedIdCounters() above, this runs at request time, where a concurrent
	 * nextval() is a real possibility - see the advance query's own comment for why it
	 * never calls setval().
	 *
	 * Refuses - reporting false without reading, let alone drawing, a single nextval() -
	 * when $minNextValue is more than MAX_SEQUENCE_ADVANCE_GAP past the sequence's current
	 * position; see that constant's own docblock for why and for what its only caller does
	 * about a refusal. The gap is read here as a plain, non-atomic SELECT, which is safe
	 * precisely because nothing here or in that caller ever moves a sequence backward
	 * (#555): the true gap at execute() time below can therefore only be the same or
	 * smaller than what this read saw, whatever else concurrently draws from the same
	 * sequence in between, so a decision to proceed made here never ends up drawing more
	 * nextval() calls than it already accounted for.
	 *
	 * Issue #584: when this read finds the sequence already at or past $minNextValue, the
	 * id this call cares about ($minNextValue - 1, "X" below) was necessarily drawn once
	 * already by *something* - X can only be handed out through nextval() on this same
	 * sequence - so true is returned exactly as before, with no draws at all. It is NOT
	 * guaranteed once this read finds the sequence at or below X: closing that gap still
	 * takes as many nextval() calls as before, but nothing reserves X for this call in
	 * particular - a concurrent caller can draw X first, in which case this call's own
	 * draws land on whatever the sequence has moved on to instead, past X, and the caller
	 * that named X (UndoBooking()'s CONSUME rebuild) would collide with whoever the
	 * concurrent caller's own INSERT/booking already gave X to if it went ahead and reused
	 * it anyway. So in that branch this method checks whether X was actually among the
	 * values its own nextval() draws returned - `nextval()` itself is atomic and every
	 * value it ever returns on a given sequence is unique, so if this call's own draws
	 * include X, nothing else can have drawn it, ever, and reusing it is safe; if they do
	 * not, X went to a concurrent caller (or was already skipped over, another sequence
	 * artefact `AdvanceIdentitySequence()`'s own callers already rely on being tolerable)
	 * and this method refuses so its own caller falls back to a fresh id instead of racing
	 * an explicit-id INSERT against whatever already holds X.
	 *
	 * @return bool True if the sequence was already at or past $minNextValue, or if this
	 *              call's own nextval() draws (needed to reach it) included
	 *              $minNextValue - 1 itself. False if $minNextValue was more than
	 *              MAX_SEQUENCE_ADVANCE_GAP past the sequence's current position (left
	 *              completely untouched), or if this call's own draws did not include
	 *              $minNextValue - 1 (the sequence was still advanced - just not through a
	 *              draw this call can vouch for).
	 */
	public function AdvanceIdentitySequence(\PDO $pdo, string $table, string $column, int $minNextValue): bool
	{
		$sequenceNameStatement = $pdo->prepare('SELECT pg_get_serial_sequence(?, ?)');
		$sequenceNameStatement->execute([$table, $column]);
		$sequenceName = $sequenceNameStatement->fetchColumn();
		if (empty($sequenceName))
		{
			// $table.$column is not backed by an identity/serial sequence - nothing to
			// advance, and so nothing stopping the caller from proceeding either.
			return true;
		}

		// A plain read, not yet the atomic advance below - see this method's own docblock
		// for why a gap measured here can only ever be an overestimate of the gap the
		// advance query below actually has to close, never an underestimate.
		$currentPosition = (int)$pdo->query(
			'SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END FROM ' . $sequenceName
		)->fetchColumn();

		if ($minNextValue - $currentPosition > self::MAX_SEQUENCE_ADVANCE_GAP)
		{
			return false;
		}

		if ($currentPosition >= $minNextValue)
		{
			// The sequence is already at or past $minNextValue, so $minNextValue - 1 was
			// necessarily drawn once already (#584) - nothing to do, exactly as before.
			return true;
		}

		// setval() is not atomic: it reads the sequence's current position and then writes
		// a new one in two separate steps, and a concurrent nextval() landing between the
		// two is silently undone - setval() simply overwrites whatever nextval() just
		// returned, so that caller's own claimed id collides with whatever the next
		// nextval() after this setval() hands out (a two-connection loop against the
		// read-then-setval version of this method reissued 96 of 52151 drawn values in 4s -
		// tests/Pgsql/DialectPolicyTest.php's own race test, run against the unfixed code).
		// nextval() itself IS atomic, so this advances by calling it however many times are
		// actually needed - once per row generate_series() produces - rather than jumping
		// straight to a computed target: a concurrent nextval() landing in the middle of
		// that still atomically claims its own unique value, this call's own among them,
		// and the only visible effect of the two interleaving is a gap in the sequence,
		// which is already normal, documented sequence behaviour that nothing here relies
		// on being gap-free. $sequenceName is interpolated (not bound) because a FROM
		// target cannot be a bind parameter; it is safe here because it came back from
		// pg_get_serial_sequence() above, not from anything a caller supplies directly.
		//
		// bool_or(v = ?) (#584) rather than a bare count(): the number of draws needed to
		// close the gap is unaffected by a concurrent nextval() racing in - each call's own
		// nextval() still returns exactly one atomically-unique value per row
		// generate_series() produces - but WHICH values those are is not, once the sequence
		// sits at or below X. Checking whether X itself is among this call's own draws (not
		// merely that it drew the right count) is the only way to know it was this call,
		// and not some concurrent caller, that was actually handed X.
		$advance = $pdo->prepare(
			'SELECT coalesce(bool_or(v = ?), false) FROM (SELECT nextval(?) AS v FROM generate_series(1, ?)) advance_draws'
		);
		$advance->execute([$minNextValue - 1, $sequenceName, $minNextValue - $currentPosition]);
		return (bool)$advance->fetchColumn();
	}

	/**
	 * Stores the acting user's id in the "victual.user_id" session variable, which the SQL
	 * victual_user_setting() function reads. On SQLite the same function is a PHP callback
	 * that sees VICTUAL_USER_ID directly, so no equivalent call is needed there.
	 */
	public function SetCurrentUserId(\PDO $pdo, $userId): void
	{
		$statement = $pdo->prepare("SELECT set_config('victual.user_id', ?, false)");
		$statement->execute([(string)intval($userId)]);
	}

	/**
	 * Builds the PDO DSN from the VICTUAL_DB_* settings (sslmode only when configured).
	 */
	private function GetDsn(): string
	{
		$dsn = 'pgsql:host=' . VICTUAL_DB_HOST
			. ';port=' . intval(VICTUAL_DB_PORT)
			. ';dbname=' . VICTUAL_DB_NAME;

		if (!empty(VICTUAL_DB_SSLMODE))
		{
			$dsn .= ';sslmode=' . VICTUAL_DB_SSLMODE;
		}

		return $dsn;
	}
}
