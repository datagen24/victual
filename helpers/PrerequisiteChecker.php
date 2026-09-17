<?php

namespace Victual\Helpers;

/**
 * Thrown when a runtime prerequisite (PHP/SQLite version, PHP extension,
 * config file, Composer packages) is missing.
 */
class ERequirementNotMet extends \Exception
{
}

const REQUIRED_PHP_EXTENSIONS = ['fileinfo', 'gd', 'ctype', 'intl', 'zlib', 'mbstring',

	// These are core extensions, so normally can't be missing, but seems to be the case, however, on FreeBSD
	'filter', 'iconv', 'tokenizer', 'json'
];

/**
 * The PDO extension each engine needs, checked for the configured one only.
 *
 * pdo_sqlite used to be in the list above, so a PostgreSQL deployment required an
 * extension it never used and paid for an in-memory SQLite connection on every request
 * to read a version number it never consulted. ADR-0008's retirement made PostgreSQL the
 * only engine a deployment can be configured for, so the sqlite entry is now unreachable
 * from any supported configuration.
 *
 * It stays anyway, and the reason is worth stating rather than leaving as an oversight:
 * the differential suite still builds a SQLite side through
 * DatabaseDialect::SQLITE_TOOLING_ENV, and a suite run on a PHP without pdo_sqlite should
 * fail here - naming the missing extension - rather than several steps later inside a
 * migration. It goes when the harness goes.
 */
const REQUIRED_DRIVER_EXTENSIONS = ['sqlite' => 'pdo_sqlite', 'pgsql' => 'pdo_pgsql'];

const REQUIRED_PHP_VERSION = '8.4.0';
const REQUIRED_SQLITE_VERSION = '3.40.0';

/**
 * Checks on application startup that the runtime environment meets all
 * requirements: PHP version, required PHP extensions, presence of
 * config-dist.php and the Composer autoloader.
 *
 * In two parts, because they know different things. checkRequirements() runs from
 * public/index.php before anything is loaded, so it cannot know which database engine is
 * configured - it checks what every installation needs regardless.
 * checkDatabaseRequirements() runs from app.php once the configuration is in, and checks
 * what this installation's engine needs.
 */
class PrerequisiteChecker
{
	/**
	 * Runs all prerequisite checks.
	 *
	 * @throws ERequirementNotMet On the first unmet requirement found
	 */
	public function checkRequirements()
	{
		self::checkForPhpVersion();
		self::checkForConfigDistFile();
		self::checkForComposer();
		self::checkForPhpExtensions();
	}

	/**
	 * Checks the prerequisites which depend on the configured database engine.
	 *
	 * Separate from checkRequirements() only because of when each can run: this one needs
	 * the configuration, and the configuration needs the autoloader, which is one of the
	 * things checkRequirements() is there to verify.
	 *
	 * @param string $driver The DB_DRIVER setting
	 * @throws ERequirementNotMet When the engine's PDO driver or version is not usable
	 */
	public function checkDatabaseRequirements(string $driver)
	{
		$driver = strtolower($driver);

		if (array_key_exists($driver, REQUIRED_DRIVER_EXTENSIONS))
		{
			$extension = REQUIRED_DRIVER_EXTENSIONS[$driver];

			if (!in_array($extension, get_loaded_extensions()))
			{
				throw new ERequirementNotMet("PHP module '{$extension}' not installed, but required for the '{$driver}' database driver.");
			}
		}

		if ($driver === 'sqlite')
		{
			self::checkForSqliteVersion();
		}
	}

	private function checkForComposer()
	{
		if (!file_exists(__DIR__ . '/../packages/autoload.php'))
		{
			throw new ERequirementNotMet('/packages/autoload.php not found. Have you run Composer?');
		}
	}

	private function checkForConfigDistFile()
	{
		if (!file_exists(__DIR__ . '/../config-dist.php'))
		{
			throw new ERequirementNotMet('config-dist.php not found. Please do not remove this file.');
		}
	}

	/*
	 * There is deliberately no checkForConfigFile() here any more.
	 *
	 * It required config.php to exist in the data directory, and that requirement
	 * predates environment configuration: Setting() has since preferred a
	 * settingoverrides file and then a VICTUAL_* environment variable over its own
	 * defaults, so an installation can be fully configured without the file existing at
	 * all. app.php loads it when it is there.
	 *
	 * Removing the check is not tidiness. The requirement was the last thing forcing a
	 * container to carry a writable data directory — to hold a file with nothing in it —
	 * which cost an entrypoint, a seed layer, an emptyDir volume and the pcntl extension,
	 * and which is what stopped the pod starting under podman in issue #49. What replaces
	 * it is nothing: a missing config.php is now indistinguishable from an empty one,
	 * which is what it always was.
	 */

	private function checkForPhpExtensions()
	{
		$loadedExtensions = get_loaded_extensions();
		foreach (REQUIRED_PHP_EXTENSIONS as $extension)
		{
			if (!in_array($extension, $loadedExtensions))
			{
				throw new ERequirementNotMet("PHP module '{$extension}' not installed, but required.");
			}
		}
	}

	/**
	 * Only reached from the differential suite (see REQUIRED_DRIVER_EXTENSIONS above): it
	 * opens a throwaway in-memory database to read the library version, which is a
	 * connection a PostgreSQL deployment has no reason to make on every request.
	 */
	private function checkForSqliteVersion()
	{
		$sqliteVersion = self::getSqlVersionAsString();
		if (version_compare($sqliteVersion, REQUIRED_SQLITE_VERSION, '<'))
		{
			throw new ERequirementNotMet('SQLite ' . REQUIRED_SQLITE_VERSION . ' is required, however you are running ' . $sqliteVersion);
		}
	}

	private function checkForPhpVersion()
	{
		$phpVersion = phpversion();
		if (version_compare($phpVersion, REQUIRED_PHP_VERSION, '<'))
		{
			throw new ERequirementNotMet('PHP ' . REQUIRED_PHP_VERSION . ' is required, however you are running ' . $phpVersion);
		}
	}

	private function getSqlVersionAsString()
	{
		$dbh = new \PDO('sqlite::memory:');
		return $dbh->query('select sqlite_version()')->fetch()[0];
	}
}
