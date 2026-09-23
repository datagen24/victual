<?php

namespace Victual\Services\Database;

use Victual\Services\UsersService;

/**
 * The original (and default) storage engine. Behaviour here is deliberately identical to
 * what the code did before the dialect layer existed, so that existing installations are
 * untouched by the PostgreSQL work.
 */
class SqliteDialect extends DatabaseDialect
{
	public function GetName(): string
	{
		return 'sqlite';
	}

	/**
	 * Opens the database file (creating it when missing, as SQLite does by default).
	 * NULL_EMPTY_STRING matches upstream grocy: empty strings come back as null.
	 */
	public function CreateConnection(): \PDO
	{
		$pdo = new \PDO\Sqlite('sqlite:' . $this->GetDbFilePath());
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);

		return $pdo;
	}

	/**
	 * Registers the PHP callbacks SQLite lacks natively and Victual's SQL relies on:
	 * REGEXP (UTF-8 aware via mb_ereg), victual_user_setting() for user settings resolved
	 * inside views, and ceil(). PostgreSQL provides native equivalents of all three
	 * instead, since it cannot call back into PHP.
	 */
	public function OnConnected(\PDO $pdo): void
	{
		$pdo->createFunction('regexp', function ($pattern, $value)
		{
			mb_regex_encoding('UTF-8');
			return (false !== mb_ereg($pattern, $value)) ? 1 : 0;
		});

		$pdo->createFunction('victual_user_setting', function ($value)
		{
			$usersService = UsersService::GetInstance();
			return $usersService->GetUserSetting(VICTUAL_USER_ID, $value);
		});

		// Unfortunately not included by default
		// https://www.sqlite.org/lang_mathfunc.html#ceil
		$pdo->createFunction('ceil', function ($value)
		{
			return ceil($value);
		});
	}

	/**
	 * SQLite's REGEXP operator, backed by the mb_ereg callback from OnConnected().
	 */
	public function GetRegexpCondition(string $field): string
	{
		return $field . ' REGEXP ?';
	}

	/**
	 * Plain LIKE, which SQLite already applies case insensitively for ASCII
	 * (PRAGMA case_sensitive_like is off by default). This is the reference behaviour the
	 * PostgreSQL dialect mimics with ILIKE.
	 */
	public function GetLikeCondition(string $field, bool $negated): string
	{
		return $field . ($negated ? ' NOT LIKE ?' : ' LIKE ?');
	}

	/**
	 * PRAGMA table_info, which reports the type as it was declared in the CREATE statement
	 * and works on views as well as tables.
	 */
	public function GetColumnTypes(\PDO $pdo, string $table): array
	{
		$types = [];

		// PRAGMA takes no placeholders, hence the quoting; $table is never caller supplied
		// (it is the Result's own table) but quoting it is what makes that safe to say.
		foreach ($pdo->query('PRAGMA table_info(' . $this->QuoteIdentifier($table) . ')') as $column)
		{
			$types[$column['name']] = $column['type'];
		}

		return $types;
	}

	/**
	 * Local (process time zone) timestamp with second precision - the reference
	 * behaviour the PostgreSQL dialect mimics.
	 */
	public function GetNowExpression(): string
	{
		return "datetime('now', 'localtime')";
	}

	public function GetTimestampType(): string
	{
		return 'DATETIME';
	}

	/**
	 * SQLite never returns free pages to the OS on its own, so VACUUM after
	 * migrations keeps the single-file database compact.
	 */
	public function GetOptimizeStatement(): ?string
	{
		return 'VACUUM';
	}

	/**
	 * Runs the migration without taking a lock of its own.
	 *
	 * Not an oversight. Concurrent migration runs are a deployment problem - several pods
	 * starting at once - and under ADR-0008 SQLite is not a runtime engine: it is what
	 * bin/victual-db-import reads and what the differential suite and a local dev boot
	 * migrate one process at a time. Nothing races here.
	 *
	 * What protects the case anyway is SQLite's own file locking: a second writer gets
	 * SQLITE_BUSY and the run fails, which is loud rather than silent and cannot corrupt
	 * the database. That is a worse experience than waiting and a perfectly acceptable one
	 * for a path with no concurrent callers.
	 *
	 * This makes plan 10's question 3 - where a SQLite lock file should live - moot. It
	 * was a real question while SQLite was a deployment target.
	 */
	public function WithMigrationLock(callable $work)
	{
		return $work();
	}

	/**
	 * Runs the publication without taking a lock of its own. Not an oversight either, and
	 * for a different reason than WithMigrationLock() above.
	 *
	 * Under ADR-0008 SQLite is not a runtime engine - it is what bin/victual-db-import
	 * reads, what the differential suite builds, and what a local dev boot uses, and every
	 * one of those is one process at a time. The built-in server that serves a dev boot is
	 * single-process, so there is no second request to interleave with.
	 *
	 * Unlike a migration run there is no file locking to fall back on here, because the
	 * race is not between two database writers: it is between a read here and a network
	 * write to a broker, which SQLite knows nothing about. That is an argument for not
	 * pretending this engine is safe for concurrent publication rather than for inventing a
	 * lock file - and it costs nothing, because the deployment that publishes runs on
	 * PostgreSQL.
	 */
	public function WithPublicationLock(callable $work)
	{
		return $work();
	}

	/**
	 * Takes no lock. Not an oversight, for the same reason as WithMigrationLock() and
	 * WithPublicationLock() above: under ADR-0008 SQLite is not a concurrent runtime
	 * engine, and the differential suite and a local dev boot that construct this dialect
	 * are one process at a time, so there is no second stock booking to interleave with.
	 */
	public function LockProductStock(\PDO $pdo, int $productId): void
	{
	}

	/**
	 * Whether the error is "no such table", which on SQLite can only be told from the
	 * message.
	 *
	 * PDO_SQLite maps almost every failure onto SQLSTATE HY000 with driver code 1: a
	 * missing table, a missing column and a syntax error are indistinguishable by code.
	 * Measured rather than assumed - "no such table: migrations", "no such column: nope"
	 * and 'near "SELEC": syntax error' all arrive as
	 * ["HY000", 1, ...] on PHP 8.4's pdo_sqlite. So the message is the only thing that
	 * separates them, and matching on it is the honest implementation rather than a
	 * shortcut. The SQLSTATE is still checked first, so an error from some other layer
	 * that happens to contain the phrase cannot pass.
	 */
	public function IsMissingTableError(\PDOException $ex): bool
	{
		if (self::SqlStateOf($ex) !== 'HY000')
		{
			return false;
		}

		$message = $ex->errorInfo[2] ?? $ex->getMessage();

		return is_string($message) && stripos($message, 'no such table') !== false;
	}

	/**
	 * Standard SQL double-quote quoting, which SQLite supports alongside backticks.
	 */
	public function QuoteIdentifier(string $name): string
	{
		return '"' . str_replace('"', '""', $name) . '"';
	}

	public function GetIdentifierDelimiter(): string
	{
		// SQLite accepts both; the backtick is what LessQL has always used here
		return '`';
	}

	/**
	 * The database file's modification time serves as the changed time - the OS
	 * maintains it on every write, so no in-database bookkeeping is needed.
	 */
	public function GetDbChangedTime(\PDO $pdo): string
	{
		clearstatcache(true, $this->GetDbFilePath());
		return date('Y-m-d H:i:s', filemtime($this->GetDbFilePath()));
	}

	/**
	 * Rewinds the file modification time via touch() - how bookkeeping writes
	 * (session/API key last-used stamps) are hidden from clients polling for changes.
	 */
	public function SetDbChangedTime(\PDO $pdo, string $dateTime): void
	{
		touch($this->GetDbFilePath(), strtotime($dateTime));
	}

	public function MarkDbChanged(\PDO $pdo): void
	{
		// The file modification time SQLite maintains already is the changed time
	}

	/**
	 * False: the file modification time makes per-statement change tracking unnecessary.
	 */
	public function RequiresChangeTracking(): bool
	{
		return false;
	}

	/**
	 * Absolute path of the database file: victual.db in the data directory, or a
	 * per-locale victual_<suffix>.db in demo/prerelease mode.
	 */
	public function GetDbFilePath(): string
	{
		if (VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
		{
			$dbSuffix = VICTUAL_DEFAULT_LOCALE;
			if (defined('VICTUAL_DEMO_DB_SUFFIX'))
			{
				$dbSuffix = VICTUAL_DEMO_DB_SUFFIX;
			}

			return VICTUAL_DATAPATH . '/victual_' . $dbSuffix . '.db';
		}

		return VICTUAL_DATAPATH . '/victual.db';
	}
}
