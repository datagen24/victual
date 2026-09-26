<?php

namespace Victual\Services;

use Victual\Services\Database\DatabaseDialect;
use Victual\Services\Database\InitialDataSeeder;

/**
 * Brings the database schema up to date on application start.
 *
 * PostgreSQL loads a squashed baseline schema from db/pgsql/baseline/ equivalent to
 * migrations 0001-0255, records those as applied and continues with the regular migration
 * path from 0256 on. Applied migration numbers are tracked in the "migrations" table.
 *
 * SQLite replays the numbered files from the beginning instead, and since ADR-0008's
 * retirement that path is reachable only from the differential suite (see
 * DatabaseDialect::SQLITE_TOOLING_ENV) and describes only the schemas
 * bin/victual-db-import accepts. Its line is frozen at SQLITE_FROZEN_MIGRATION_ID below.
 */
class DatabaseMigrationService extends BaseService
{
	/**
	 * This migration will be always executed, can be used to fix things manually (will never be shipped).
	 */
	const EMERGENCY_MIGRATION_ID = 9999;

	/**
	 * This migration will be always executed, is used for things which need to be checked always.
	 */
	const DOALWAYS_MIGRATION_ID = 8888;

	/**
	 * Migrations 0001-0255 are SQLite only and describe how the schema grew historically.
	 * Engines added later start from a squashed baseline equivalent to that end state
	 * rather than replaying a history they were never part of.
	 *
	 * From here up to SQLITE_FROZEN_MIGRATION_ID the two engines were maintained together,
	 * so a migration in that range is a portable NNNN.sql, a per engine
	 * NNNN.sqlite.sql / NNNN.pgsql.sql pair, or a documented engine-exclusive file where
	 * the other engine genuinely needed no change. The last case means the two engines sit
	 * at different numbers while both being fully migrated, which is why
	 * GetLatestMigrationNumber() takes a dialect. Above the freeze there is only
	 * PostgreSQL to migrate.
	 */
	const BASELINE_MIGRATION_ID = 255;

	/**
	 * The last migration the SQLite line will ever have.
	 *
	 * ADR-0008 retired SQLite as a runtime engine and kept it as an import format, and an
	 * import format needs a fixed upper bound rather than a moving one: past this number
	 * no SQLite database is produced by anything in this repository, so a
	 * NNNN.sqlite.sql above it would be a file no engine here can ever run and no source
	 * database can ever have applied. .devtools/pgsql/check-migrations.php refuses one,
	 * and DatabaseImporter uses this as the upper end of the supported import span.
	 *
	 * 265 rather than "whatever the highest sqlite file happens to be", because the point
	 * of a freeze is that it stops being computed from the tree. The two agree today, and
	 * check-migrations.php asserts they still do.
	 */
	const SQLITE_FROZEN_MIGRATION_ID = 265;

	/**
	 * Applies all pending migrations: ensures the migrations table exists, loads the
	 * baseline schema when the engine uses one and the database is empty, then executes
	 * every not-yet-applied migration file in ascending number order. When anything was
	 * applied, generated-id counters are resynced (migrations insert explicit ids) and
	 * the engine's optimize statement (e.g. VACUUM/ANALYZE) is run.
	 *
	 * @param bool $seedInitialData Whether a database created from the baseline also gets
	 *                              the initial data that the migration history would have
	 *                              inserted. Only bin/victual-db-import passes false: it is
	 *                              about to fill the database from an existing one, and
	 *                              seeding first would leave it looking non-empty to its
	 *                              own overwrite check.
	 * @param callable|null $reportGeneratedAdminPassword Called with (username, password)
	 *                              when this run seeded the first administrator with a
	 *                              generated password - see ReportGeneratedAdminPassword().
	 *                              Defaults to error_log(); bin/victual-migrate prints it.
	 */
	public function MigrateDatabase(bool $seedInitialData = true, ?callable $reportGeneratedAdminPassword = null)
	{
		$dialect = DatabaseService::GetInstance()->GetDialect();
		$this->ReportGeneratedAdminPassword = $reportGeneratedAdminPassword ?? function (string $username, string $password)
		{
			error_log(self::GeneratedAdminPasswordNotice($username, $password));
		};

		// The whole run, baseline and the always-run 8888 included, happens with the
		// engine's migration lock held: everything below is check-then-apply, so two
		// processes starting together interleave rather than one waiting for the other.
		// See DatabaseDialect::WithMigrationLock().
		$dialect->WithMigrationLock(function () use ($dialect, $seedInitialData)
		{
			$this->RunMigrations($dialect, $seedInitialData);
		});

		// Whatever the boot check read before this ran is now wrong
		self::$AppliedMigrationNumbers = null;
	}

	/**
	 * The migration run itself, always called with the migration lock held.
	 */
	private function RunMigrations(DatabaseDialect $dialect, bool $seedInitialData)
	{
		$this->EnsureMigrationsTable($dialect);
		$this->ApplyBaselineSchemaWhenNeeded($dialect, $seedInitialData);

		$migrationCounter = 0;
		foreach (self::GetMigrationFiles($dialect) as $migrationNumber => $migrationFile)
		{
			if ($migrationFile->getExtension() === 'php')
			{
				$this->ExecutePhpMigrationWhenNeeded($migrationNumber, $migrationFile->getPathname(), $migrationCounter);
			}
			else
			{
				$this->ExecuteSqlMigrationWhenNeeded($migrationNumber, file_get_contents($migrationFile->getPathname()), $migrationCounter);
			}
		}

		$this->SyncUserSettingDefaults($dialect);
		$this->FlagGeneratedAdminPasswordForChange();

		if ($migrationCounter > 0)
		{
			// Migrations routinely insert rows with explicit ids
			$dialect->ResyncGeneratedIdCounters(DatabaseService::GetInstance()->GetDbConnectionRaw());

			$optimizeStatement = $dialect->GetOptimizeStatement();

			if ($optimizeStatement !== null)
			{
				DatabaseService::GetInstance()->ExecuteDbStatement($optimizeStatement);
			}
		}
	}

	/**
	 * Every migration number this database has recorded, ascending, with no gaps invented
	 * and none hidden. Empty when it has recorded none - which is also the answer for a
	 * database that is completely empty and has no migrations table yet.
	 *
	 * The whole set rather than its maximum, because the maximum is not a schema version.
	 * Migrations reach master in whatever order their pull requests merge, so a database
	 * can hold 0257 and 0259 and not 0258, and MAX(migration) then reports 259 for a
	 * schema that never ran 0258 - the gate says "current" while the table 0258 creates
	 * does not exist. Comparing the sets is the only form of this check that cannot be
	 * fooled by merge order, and it is also the only one that repairs a database which
	 * already reached that state.
	 *
	 * Memoized for the request: the boot check asks on every request and the answer
	 * cannot change underneath it, since nothing migrates during a request unless this
	 * process does it, and the memo is dropped when it does. APCu is the answer if the
	 * one query per request ever becomes measurable; ADR-0007 is explicit that a cache
	 * losing its memo on a restart is the acceptable kind of lost state.
	 *
	 * Only a missing "migrations" table reads as "nothing applied". Every other database
	 * failure - unreachable server, a role that may not read the table, a timeout -
	 * leaves the memo unset and propagates, because the schema version is then *unknown*
	 * rather than zero, and answering zero would tell an operator whose database is down
	 * to run migrations at it. See DatabaseDialect::IsMissingTableError().
	 *
	 * Negative numbers are dropped. The table is also used as bookkeeping for things that
	 * are not migrations - DemoDataGeneratorService records "the demo data already ran" as
	 * migration -1 - and a marker that no engine has a file for otherwise reads as a
	 * migration from a newer deployment, which is how demo mode made itself unserveable:
	 * the first request seeded the data and every request after it was refused with "the
	 * database is ahead of the code". Filtering here rather than in the two callers keeps
	 * this method's contract ("every migration number this database has recorded") true,
	 * and there is no migration 0 or below to hide.
	 *
	 * @return int[]
	 * @throws \PDOException When the database could not be asked at all
	 */
	public function GetAppliedMigrationNumbers(): array
	{
		if (self::$AppliedMigrationNumbers === null)
		{
			$numbers = [];

			try
			{
				$statement = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT migration FROM migrations');

				if ($statement === false)
				{
					throw new \Exception('The migrations table could not be read.');
				}

				$numbers = array_filter(
					array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN, 0)),
					fn (int $migration) => $migration >= 0
				);
			}
			catch (\PDOException $ex)
			{
				if (!DatabaseService::GetInstance()->GetDialect()->IsMissingTableError($ex))
				{
					throw $ex;
				}

				// No migrations table at all: an untouched database, which is behind by
				// every migration there is.
				$numbers = [];
			}

			sort($numbers);
			self::$AppliedMigrationNumbers = $numbers;
		}

		return self::$AppliedMigrationNumbers;
	}

	/**
	 * The highest migration number this database has recorded, or 0 when it has recorded
	 * none.
	 *
	 * A summary for a human reading an error message, not a schema version: two databases
	 * with the same maximum can hold different sets. Anything deciding whether the schema
	 * matches has to ask GetMissingMigrationNumbers() / GetUnknownMigrationNumbers().
	 */
	public function GetAppliedMigrationNumber(): int
	{
		$applied = $this->GetAppliedMigrationNumbers();

		return empty($applied) ? 0 : max($applied);
	}

	/**
	 * The migrations this engine has files for that this database has not recorded -
	 * everything bin/victual-migrate would apply if it ran now, ascending.
	 *
	 * @return int[]
	 */
	public function GetMissingMigrationNumbers(DatabaseDialect $dialect): array
	{
		return array_values(array_diff(self::GetRequiredMigrationNumbers($dialect), $this->GetAppliedMigrationNumbers()));
	}

	/**
	 * The migrations this database has recorded that this engine has no file for,
	 * ascending.
	 *
	 * That is the rollback case - an older image deployed against a database a newer one
	 * already migrated - and it is exactly as unserveable as being behind while looking
	 * much more like everything is fine.
	 *
	 * @return int[]
	 */
	public function GetUnknownMigrationNumbers(DatabaseDialect $dialect): array
	{
		return array_values(array_diff($this->GetAppliedMigrationNumbers(), self::GetRequiredMigrationNumbers($dialect)));
	}

	/** @var int[]|null Memo for GetAppliedMigrationNumbers(), for the life of the request */
	private static $AppliedMigrationNumbers = null;

	/**
	 * Returns the migrations which apply to the given engine, keyed and ordered by
	 * migration number.
	 *
	 * A migration file is named either NNNN.sql / NNNN.php, meaning it applies to every
	 * engine, or NNNN.<driver>.sql / NNNN.<driver>.php, meaning it applies only to that
	 * engine and takes precedence over a generic file with the same number.
	 *
	 * @return \SplFileInfo[]
	 */
	/**
	 * The highest migration number that exists for an engine.
	 *
	 * Dialect-aware on purpose. A migration can be engine-exclusive — shipped as
	 * NNNN.sqlite.sql or NNNN.pgsql.sql with no counterpart, because the other engine
	 * genuinely needs no change — and once one exists, "what number should this
	 * database be at?" has a different answer per engine. Anything comparing schema
	 * versions has to ask this rather than assume the two engines count alike.
	 */
	public static function GetLatestMigrationNumber(DatabaseDialect $dialect): int
	{
		$numbers = self::GetRequiredMigrationNumbers($dialect);

		return empty($numbers) ? 0 : max($numbers);
	}

	/**
	 * Every migration number a fully migrated database of this engine must have recorded,
	 * ascending.
	 *
	 * Dialect-aware for the reason GetLatestMigrationNumber() is, and the set rather than
	 * its maximum for the reason GetAppliedMigrationNumbers() explains: merge order can
	 * put a hole below the maximum, and only a set knows about holes.
	 *
	 * The engines legitimately require different sets. On PostgreSQL the numbers up to
	 * BASELINE_MIGRATION_ID are recorded by the baseline load rather than by running the
	 * files, so they belong here on both engines; above it, an engine-exclusive migration
	 * is required on its own engine and on no other.
	 *
	 * @return int[]
	 */
	public static function GetRequiredMigrationNumbers(DatabaseDialect $dialect): array
	{
		// The always-run migrations are fixups rather than schema versions, and they are
		// deliberately never recorded in the migrations table — requiring them would put
		// every database permanently "behind" a number it can never reach.
		$numbers = array_values(array_filter(
			array_keys(self::GetMigrationFiles($dialect)),
			fn($number) => $number !== self::DOALWAYS_MIGRATION_ID && $number !== self::EMERGENCY_MIGRATION_ID
		));

		sort($numbers);

		return $numbers;
	}

	private static function GetMigrationFiles(DatabaseDialect $dialect): array
	{
		$generic = [];
		$specific = [];

		foreach (new \FilesystemIterator(__DIR__ . '/../migrations') as $file)
		{
			$matches = [];
			$name = $file->getBasename();

			if (preg_match('/^(\d+)\.(sql|php)$/', $name, $matches))
			{
				$generic[intval($matches[1])] = $file;
			}
			elseif (preg_match('/^(\d+)\.([a-z]+)\.(sql|php)$/', $name, $matches))
			{
				// A suffix that does not name a real engine matches nothing and would
				// otherwise be skipped in silence on every engine — the migration simply
				// never runs, and nothing says so. A typo here is indistinguishable from
				// a deliberate omission at runtime, so refuse to start instead.
				if (!in_array($matches[2], DatabaseDialect::MIGRATION_DRIVERS, true))
				{
					throw new \Exception('Migration "' . $name . '" is suffixed "' . $matches[2]
						. '", which is not a database driver this fork has migrations for. '
						. 'Expected one of: ' . implode(', ', DatabaseDialect::MIGRATION_DRIVERS) . '.');
				}

				if ($matches[2] === $dialect->GetName())
				{
					$specific[intval($matches[1])] = $file;
				}
			}
			elseif (preg_match('/\.(sql|php)$/', $name))
			{
				// Same failure, different spelling: a migration whose name does not parse
				// at all is dead weight nobody would notice.
				throw new \Exception('Migration "' . $name . '" does not follow the naming '
					. 'convention. Expected NNNN.sql, NNNN.php, or NNNN.<driver>.sql / '
					. 'NNNN.<driver>.php.');
			}
		}

		$migrationFiles = $specific + $generic;
		ksort($migrationFiles);

		return $migrationFiles;
	}

	/**
	 * Creates the "migrations" bookkeeping table (applied migration number plus
	 * execution timestamp) when it does not exist yet.
	 *
	 * Looked up before it is created, the same way PostgresDialect::OnConnected() already
	 * looks up the changed-time table before creating it: PostgreSQL checks CREATE on the
	 * schema *before* it checks whether the table exists, so a bare
	 * "CREATE TABLE IF NOT EXISTS" of a table that is already there still fails 42501 for a
	 * role with no CREATE - exactly the role that serves ordinary requests
	 * (deploy/postgres/roles.sql's victual_app, ADR-0010 property 3). That turned
	 * SystemController::Root()'s MIGRATE_ON_ROOT_REQUEST fallback, and every other caller of
	 * MigrateDatabase(), into a hard failure under the split-credential app role even when
	 * the table was already there and nothing needed to change. Reading first costs that
	 * role nothing beyond the USAGE/SELECT it already holds, and only a role that can
	 * actually create the table is ever asked to.
	 *
	 * No race guard is needed the way OnConnected()'s read-then-create has one: this method
	 * is only ever reached from RunMigrations(), always under WithMigrationLock(), so
	 * nothing else can create the table between the read below and the write.
	 */
	private function EnsureMigrationsTable(DatabaseDialect $dialect)
	{
		try
		{
			DatabaseService::GetInstance()->ExecuteDbQuery('SELECT 1 FROM migrations LIMIT 0');

			return;
		}
		catch (\PDOException $ex)
		{
			if (!$dialect->IsMissingTableError($ex))
			{
				throw $ex;
			}
		}

		DatabaseService::GetInstance()->ExecuteDbStatement(
			'CREATE TABLE IF NOT EXISTS migrations ('
			. 'migration INTEGER NOT NULL PRIMARY KEY, '
			. 'execution_time_timestamp ' . $dialect->GetTimestampType() . ' DEFAULT (' . $dialect->GetNowExpression() . ')'
			. ')'
		);
	}

	/**
	 * On an engine which starts from a baseline rather than from the migration history,
	 * loads that baseline into an empty database, seeds the data those migrations would
	 * have inserted, and records the migrations it stands in for as already applied.
	 *
	 * Schema and data go in together on purpose. The baseline files are DDL only, while
	 * a third of the migrations they stand in for also insert rows - the admin user, the
	 * permission hierarchy, the default quantity units - so loading the schema alone
	 * produces a database that migrates cleanly and cannot be logged into. See
	 * InitialDataSeeder.
	 *
	 * @param bool $seedInitialData False to load the schema alone, for a caller which is
	 *                              about to import an existing database into it
	 */
	private function ApplyBaselineSchemaWhenNeeded(DatabaseDialect $dialect, bool $seedInitialData)
	{
		$baselinePath = $dialect->GetBaselineSchemaPath();

		if ($baselinePath === null)
		{
			return;
		}

		$appliedCount = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM migrations')->fetchColumn();

		if ($appliedCount > 0)
		{
			return;
		}

		$baselineFiles = glob($baselinePath . '/*.sql');
		sort($baselineFiles);

		if (empty($baselineFiles))
		{
			throw new \Exception('No baseline schema found for database engine "' . $dialect->GetName() . '" in ' . $baselinePath);
		}

		$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$pdo->beginTransaction();

		try
		{
			foreach ($baselineFiles as $baselineFile)
			{
				DatabaseService::GetInstance()->ExecuteDbStatement(file_get_contents($baselineFile));
			}

			if ($seedInitialData)
			{
				$seeder = new InitialDataSeeder($pdo, $dialect);
				$seeder->Seed();
			}

			for ($migration = 1; $migration <= self::BASELINE_MIGRATION_ID; $migration++)
			{
				DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO migrations (migration) VALUES (' . $migration . ')');
			}
		}
		catch (\Exception $ex)
		{
			$pdo->rollback();
			throw $ex;
		}

		$pdo->commit();

		// Reported as soon as the row it describes is committed, and not after the
		// migrations that follow: were one of those to fail, the next run would find the
		// baseline already loaded and never seed again, and an administrator whose password
		// was generated and never shown is an installation nobody can log into.
		if (isset($seeder) && $seeder->GetGeneratedAdminPassword() !== null)
		{
			($this->ReportGeneratedAdminPassword)($seeder->GetAdminUsername(), $seeder->GetGeneratedAdminPassword());
		}
	}

	/** @var callable|null See MigrateDatabase() */
	private $ReportGeneratedAdminPassword = null;

	/**
	 * The text an operator reads in the migrate container's log after a first migration.
	 * Deliberately says what to do with it, because it is the only copy there is.
	 */
	public static function GeneratedAdminPasswordNotice(string $username, string $password): string
	{
		return 'Victual: created the first administrator "' . $username . '" with the generated password ' . $password
			. ' - it is shown once, here, and must be changed at first login.'
			. ' Set ' . InitialDataSeeder::BOOTSTRAP_PASSWORD_ENV . ' before the first migration to choose it instead.';
	}

	/**
	 * Marks the administrator whose password the seeder generated as having to change it
	 * (users.must_change_password, migration 0265), so the copy in a log stops being a
	 * credential after its first use.
	 *
	 * Not in InitialDataSeeder, because the seeder runs with the baseline, which stands in
	 * for migrations up to 0255, and the column arrives with 0265: at seed time there is
	 * nothing to write to. So the seeder leaves a marker row
	 * (InitialDataSeeder::PENDING_FORCED_CHANGE_KEY in user_settings) in the same
	 * transaction as the account, and this - run at the end of every migration run, once
	 * every migration has applied - turns it into the flag and removes it, in one
	 * transaction.
	 *
	 * A row rather than something this object remembers, because the baseline commits
	 * before the migrations after it run. If one of those fails, the retry finds the
	 * baseline loaded, seeds nothing, and would have nothing in memory to act on - the
	 * account would keep a password that has been printed to a log with no forced change.
	 * Found by CodeRabbit in review of PR #213.
	 *
	 * A user setting is safe for the marker even though its owner can delete it through
	 * the API (the reason 0265 is a column): nothing serves requests until a migration run
	 * has completed - the boot check refuses a database missing any required migration -
	 * and the run that completes is the one that consumes the marker.
	 */
	private function FlagGeneratedAdminPasswordForChange(): void
	{
		$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$pending = $pdo->prepare('SELECT user_id FROM user_settings WHERE key = ?');
		$pending->execute([InitialDataSeeder::PENDING_FORCED_CHANGE_KEY]);
		$userIds = $pending->fetchAll(\PDO::FETCH_COLUMN);

		if (empty($userIds))
		{
			return;
		}

		$pdo->beginTransaction();

		try
		{
			$flag = $pdo->prepare('UPDATE users SET must_change_password = 1 WHERE id = ?');
			$clear = $pdo->prepare('DELETE FROM user_settings WHERE user_id = ? AND key = ?');

			foreach ($userIds as $userId)
			{
				$flag->execute([(int)$userId]);
				$clear->execute([(int)$userId, InitialDataSeeder::PENDING_FORCED_CHANGE_KEY]);
			}
		}
		catch (\Exception $ex)
		{
			$pdo->rollback();
			throw $ex;
		}

		$pdo->commit();
	}

	/**
	 * Mirrors the default user settings from the PHP configuration into the database, for
	 * engines which resolve settings in SQL rather than through a PHP callback.
	 */
	private function SyncUserSettingDefaults(DatabaseDialect $dialect)
	{
		if ($dialect->GetName() !== 'pgsql')
		{
			return;
		}

		global $VICTUAL_DEFAULT_USER_SETTINGS;

		foreach ($VICTUAL_DEFAULT_USER_SETTINGS as $key => $value)
		{
			DatabaseService::GetInstance()->ExecuteDbStatement(
				'INSERT INTO user_settings_defaults (key, value) VALUES (?, ?) '
				. 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
				[$key, is_bool($value) ? ($value ? '1' : '0') : (string)$value]
			);
		}
	}

	/**
	 * Includes the given PHP migration file unless it was already applied. The special
	 * EMERGENCY/DOALWAYS ids run on every start and are never recorded as applied;
	 * regular migrations are recorded and increment $migrationCounter.
	 *
	 * The include and its version row share one transaction, the way
	 * ExecuteSqlMigrationWhenNeeded() below shares one for its SQL. PostgreSQL DDL is
	 * transactional, so a PHP migration that fails partway through - 0274 creating
	 * storage_classes and then failing to add locations.storage_class_id because that
	 * column already exists, say - leaves nothing behind, and a retry sees the same
	 * starting state rather than a table the previous attempt orphaned. Before this, each
	 * statement inside the include auto-committed on its own, so a failure partway left the
	 * successful statements in place with no version row to show they ran, and a retry
	 * failed on a second, different conflict (the orphaned CREATE TABLE) instead of the
	 * original one.
	 *
	 * A PHP migration that opens its own nested transaction (DatabaseService::InTransaction(),
	 * which 0266/0282/0283 use) is unaffected: InTransaction() already checks whether a
	 * transaction is open and joins this one instead of starting and committing a second.
	 */
	private function ExecutePhpMigrationWhenNeeded(int $migrationId, string $phpFile, int &$migrationCounter)
	{
		$rowCount = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM migrations WHERE migration = ' . $migrationId)->fetchColumn();
		if ($rowCount == 0 || $migrationId == self::EMERGENCY_MIGRATION_ID || $migrationId == self::DOALWAYS_MIGRATION_ID)
		{
			DatabaseService::GetInstance()->GetDbConnectionRaw()->beginTransaction();

			try
			{
				include $phpFile;

				if ($migrationId != self::EMERGENCY_MIGRATION_ID && $migrationId != self::DOALWAYS_MIGRATION_ID)
				{
					DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO migrations (migration) VALUES (' . $migrationId . ')');
					$migrationCounter++;
				}
			}
			catch (\Exception $ex)
			{
				DatabaseService::GetInstance()->GetDbConnectionRaw()->rollback();
				throw $ex;
			}

			DatabaseService::GetInstance()->GetDbConnectionRaw()->commit();
		}
	}

	/**
	 * Executes the given SQL migration in a transaction unless it was already applied,
	 * with the same special-id and bookkeeping rules as ExecutePhpMigrationWhenNeeded()
	 * (PHP migrations, in contrast, manage their own transactions).
	 */
	private function ExecuteSqlMigrationWhenNeeded(int $migrationId, string $sql, int &$migrationCounter)
	{
		$rowCount = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM migrations WHERE migration = ' . $migrationId)->fetchColumn();
		if ($rowCount == 0 || $migrationId == self::EMERGENCY_MIGRATION_ID || $migrationId == self::DOALWAYS_MIGRATION_ID)
		{
			DatabaseService::GetInstance()->GetDbConnectionRaw()->beginTransaction();

			try
			{
				DatabaseService::GetInstance()->ExecuteDbStatement($sql);

				if ($migrationId != self::EMERGENCY_MIGRATION_ID && $migrationId != self::DOALWAYS_MIGRATION_ID)
				{
					DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO migrations (migration) VALUES (' . $migrationId . ')');
					$migrationCounter++;
				}
			}
			catch (\Exception $ex)
			{
				DatabaseService::GetInstance()->GetDbConnectionRaw()->rollback();
				throw $ex;
			}

			DatabaseService::GetInstance()->GetDbConnectionRaw()->commit();
		}
	}
}
