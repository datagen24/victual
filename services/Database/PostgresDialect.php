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
	 * Sets every identity column's sequence to MAX(id) + 1 (at least 1), needed after
	 * inserting rows with explicit ids (migrations, demo data, database import).
	 */
	public function ResyncGeneratedIdCounters(\PDO $pdo): void
	{
		// GENERATED BY DEFAULT AS IDENTITY leaves its sequence untouched when a row is
		// inserted with an explicit id, unlike SQLite's AUTOINCREMENT. Without this the
		// sequence eventually catches up with rows that already exist and inserts start
		// failing on the primary key.
		$pdo->exec(
			'DO $$
			DECLARE
				r RECORD;
			BEGIN
				FOR r IN
					SELECT table_name, column_name
					FROM information_schema.columns
					WHERE table_schema = current_schema()
						AND is_identity = \'YES\'
				LOOP
					-- GREATEST because some tables hold rows with negative ids on purpose
					-- (meal_plan_sections has the internal section at -1), and a sequence
					-- cannot be set below 1
					EXECUTE format(
						\'SELECT setval(pg_get_serial_sequence(%L, %L), GREATEST(COALESCE((SELECT MAX(%I) FROM %I), 0) + 1, 1), false)\',
						r.table_name, r.column_name, r.column_name, r.table_name
					);
				END LOOP;
			END $$;'
		);
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
