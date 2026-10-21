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

/**
 * The oldest PHP release each supported branch may run, keyed by "major.minor".
 *
 * These are security floors, not language floors (the code needs 8.4.1; composer.json's
 * lockfile dependencies need nothing newer). 8.4.27 and 8.5.12 are the releases that fix
 * PHP2Shell, GHSA-w8hw-w9fx-j79w and GHSA-6wmf-4rv5-p3rp: unauthenticated remote code
 * execution in PHP core, reachable through PHP-FPM. A branch absent from the map has no
 * floor of its own, which is how 8.6 and later are accepted; a branch below the lowest key
 * is refused outright. Keep composer.json's `require.php` in step
 * (`~8.4.27 || ^8.5.12`): composer's platform check is disabled, so this is what enforces it.
 */
const REQUIRED_PHP_VERSION_8_4 = '8.4.27';
const REQUIRED_PHP_VERSION_8_5 = '8.5.12';
const PHP_VERSION_FLOORS = ['8.4' => REQUIRED_PHP_VERSION_8_4, '8.5' => REQUIRED_PHP_VERSION_8_5];
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

	/**
	 * Checks that the Composer autoloader is present.
	 *
	 * @throws ERequirementNotMet When the Composer packages are not installed
	 */
	private function checkForComposer()
	{
		if (!file_exists(__DIR__ . '/../packages/autoload.php'))
		{
			throw new ERequirementNotMet('/packages/autoload.php not found. Have you run Composer?');
		}
	}

	/**
	 * Checks that config-dist.php is present.
	 *
	 * @throws ERequirementNotMet When config-dist.php is missing
	 */
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

	/**
	 * Checks that every extension in REQUIRED_PHP_EXTENSIONS is loaded.
	 *
	 * @throws ERequirementNotMet Naming the first missing extension
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

	/**
	 * Checks the running interpreter against the per-branch floor.
	 */
	private function checkForPhpVersion()
	{
		self::checkPhpVersion(phpversion());
	}

	/**
	 * Refuses a PHP release below the floor of its branch (PHP_VERSION_FLOORS): below 8.4.27,
	 * or 8.5.x below 8.5.12. 8.6 and later have no entry and pass. Takes the version as a
	 * parameter so the boundaries can be tested without changing PHP_VERSION.
	 *
	 * @param string $phpVersion A PHP version string such as phpversion() returns
	 * @throws ERequirementNotMet When the version is below the minimum for its branch; the
	 *                            message names that minimum
	 */
	public static function checkPhpVersion(string $phpVersion): void
	{
		$lowestBranch = array_key_first(PHP_VERSION_FLOORS);
		$branch = implode('.', array_slice(explode('.', $phpVersion), 0, 2));

		if (version_compare($branch, $lowestBranch, '<'))
		{
			throw new ERequirementNotMet('PHP ' . PHP_VERSION_FLOORS[$lowestBranch] . ' or later (8.4.x from ' . REQUIRED_PHP_VERSION_8_4 . ', 8.5.x from ' . REQUIRED_PHP_VERSION_8_5 . ') is required, however you are running ' . $phpVersion);
		}

		if (isset(PHP_VERSION_FLOORS[$branch]) && version_compare($phpVersion, PHP_VERSION_FLOORS[$branch], '<'))
		{
			throw new ERequirementNotMet('PHP ' . PHP_VERSION_FLOORS[$branch] . ' is the minimum for the PHP ' . $branch . ' branch (earlier releases have the PHP2Shell remote code execution flaw), however you are running ' . $phpVersion);
		}
	}

	/**
	 * Reads the SQLite library version through a throwaway in-memory connection.
	 *
	 * @return string The version, e.g. 3.40.0
	 */
	private function getSqlVersionAsString()
	{
		$dbh = new \PDO('sqlite::memory:');
		return $dbh->query('select sqlite_version()')->fetch()[0];
	}
}
