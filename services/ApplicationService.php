<?php

namespace Victual\Services;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Provides application level metadata: version information, the changelog and
 * system/time diagnostics.
 */
class ApplicationService extends BaseService
{
	private $InstalledVersion;

	/**
	 * Parses the changelog/*.md files (named "<release number>_<version>_<release date>.md")
	 * into a list sorted by newest release first.
	 *
	 * @return array {changelog_items: array{version: string, release_date: string, body: string, release_number: int}[], newest_release_number: int}
	 */
	public function GetChangelog()
	{
		$changelogItems = [];
		foreach (glob(__DIR__ . '/../changelog/*.md') as $file)
		{
			$fileName = basename($file);
			$fileNameParts = explode('_', $fileName);

			if ($fileName == '__TEMPLATE.md')
			{
				continue;
			}

			$fileContent = file_get_contents($file);
			$version = $fileNameParts[1];
			$releaseDate = explode('.', $fileNameParts[2])[0];
			$releaseNumber = intval($fileNameParts[0]);

			$changelogItems[] = [
				'version' => $version,
				'release_date' => $releaseDate,
				'body' => $fileContent,
				'release_number' => $releaseNumber
			];
		}

		// Sort changelog items to have the changelog descending by newest version
		usort($changelogItems, function ($a, $b)
		{
			if ($a['release_number'] == $b['release_number'])
			{
				return 0;
			}

			return ($a['release_number'] < $b['release_number']) ? 1 : -1;
		});

		return [
			'changelog_items' => $changelogItems,
			'newest_release_number' => $changelogItems[0]['release_number']
		];
	}

	/**
	 * Returns the contents of version.json (Version, ReleaseDate), cached per instance.
	 *
	 * @return object
	 */
	public function GetInstalledVersion()
	{
		if ($this->InstalledVersion == null)
		{
			$this->InstalledVersion = json_decode(file_get_contents(__DIR__ . '/../version.json'));
		}

		return $this->InstalledVersion;
	}

	/**
	 * Collects environment information for the "About" dialog / system info API endpoint.
	 *
	 * @param Request|null $request The current request, for the "client" field below
	 * (plan 15-C8: read through PSR-7 rather than $_SERVER directly). Null is answered
	 * the same as a request carrying no User-Agent header - every caller has one in
	 * scope, but a service method should not require it to run.
	 * @return array {victual_version: object, php_version: string, sqlite_version: string, db_version: int, os: string, client: string}
	 */
	public function GetSystemInfo(?Request $request = null)
	{
		return [
			'victual_version' => $this->GetInstalledVersion(),
			'php_version' => phpversion(),
			'sqlite_version' => self::GetSqliteVersion(),
			'database_engine' => $this->GetDatabaseEngine(),
			'db_version' => $this->DB->migrations()->max('migration'),
			'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('v') . ' ' . php_uname('m'),
			'client' => $request !== null ? ($request->getHeaderLine('User-Agent') ?: 'unknown') : 'unknown'
		];
	}

	/**
	 * The database engine actually serving this installation, as "PostgreSQL 16.10".
	 *
	 * This is what `sqlite_version` below was *for* on an installation that runs SQLite:
	 * "what version is the database engine behind this?" On an installation with an
	 * external database it never answered that question — it reported the version of the
	 * SQLite library compiled into PHP, which is a client library for an engine nothing
	 * here is talking to. The About page showed it under the heading "SQLite Version" and
	 * the honest reading of that row on this fork was "3.46.1, and irrelevant".
	 *
	 * So the question is kept and the answer is made true. `PDO::ATTR_SERVER_VERSION`
	 * rather than `SELECT version()`, which returns PostgreSQL's full build banner —
	 * compiler, architecture, a hundred characters of it. The attribute is shorter but not
	 * short: libpq reports `16.15 (Debian 16.15-1.pgdg13+2)`, so the leading version is
	 * taken and the packaging suffix dropped. That is measured rather than assumed — the
	 * first version of this comment claimed the attribute was already the bare number.
	 *
	 * @return string
	 */
	private function GetDatabaseEngine()
	{
		try
		{
			$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();

			$driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
			$version = (string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

			// The leading version token: "16.15 (Debian ...)" -> "16.15". A server that
			// reports something this does not match keeps whatever it reported, because a
			// version nobody can parse is still more useful than an empty row.
			if (preg_match('/^\d+(\.\d+)*/', $version, $matches) === 1)
			{
				$version = $matches[0];
			}

			$name = $driver === 'pgsql' ? 'PostgreSQL' : ($driver === 'sqlite' ? 'SQLite' : $driver);

			return trim($name . ' ' . $version);
		}
		catch (\Throwable $ex)
		{
			// The About page and the 500 page both read this, and the 500 page is reached
			// precisely when things are broken — including, sometimes, the database. A
			// diagnostic that throws while reporting a diagnostic is how one 500 becomes
			// a fatal error, which is the bug this whole method sits next to.
			return 'unavailable';
		}
	}

	/**
	 * The SQLite library version, or an empty string where the driver is not installed.
	 *
	 * This used to open `new PDO('sqlite::memory:')` unconditionally, which was safe for
	 * as long as every deployment carried pdo_sqlite. Since plan 10 the serving container
	 * images do not: `checkDatabaseRequirements()` asks only for the configured engine's
	 * driver, and the Nix app and web images drop pdo_sqlite and the SQLite closure behind
	 * it. Only the migrate image keeps it, for bin/victual-db-import.
	 *
	 * The result was a fatal "could not find driver" on `/about` and
	 * `GET /api/system/info` — and, worse, inside `ExceptionController`, which calls this
	 * to build the 500 page. Every error page on those images was therefore a fatal error
	 * instead of an error page. Found by plan 20's verification, on the first pod that ran
	 * long enough to browse.
	 *
	 * The key stays in the response either way, because the wire contract is the invariant
	 * ([ADR-0005](../docs/adr/0005-wire-contract-is-the-invariant.md)); what changes is
	 * that a deployment without the driver reports "" rather than 500. Under
	 * [ADR-0008](../docs/adr/0008-postgresql-only-runtime-engine.md) that is the normal
	 * case and the field is vestigial.
	 *
	 * @return string
	 */
	private static function GetSqliteVersion()
	{
		if (!in_array('sqlite', \PDO::getAvailableDrivers()))
		{
			return '';
		}

		$pdo = new \PDO('sqlite::memory:');
		$version = $pdo->query('SELECT sqlite_version()')->fetch()[0];
		$pdo = null;

		return $version;
	}

	/**
	 * Formats a Unix timestamp as "Y-m-d H:i:s" in UTC.
	 */
	private static function convertToUtc(int $timestamp): string
	{
		$dt = new \DateTime('now', new \DateTimeZone('UTC'));
		$dt->setTimestamp($timestamp);
		return $dt->format('Y-m-d H:i:s');
	}

	/**
	 * The current local time as SQLite itself computes it (shifted by $offset seconds), or
	 * an empty string where the driver is not installed.
	 *
	 * The guard is the same defect GetSqliteVersion() above documents, in the one place the
	 * fix for that one missed: this also opened `new PDO('sqlite::memory:')`
	 * unconditionally, so on an image without pdo_sqlite - which since ADR-0008's
	 * retirement is every serving image - `GET /api/system/time` answered a fatal
	 * "could not find driver" rather than a time. It survived plan 20's verification
	 * because that walked the pages and the endpoints a browser reaches, and nothing in the
	 * UI calls this one.
	 *
	 * The key stays in the response, per
	 * [ADR-0005](../docs/adr/0005-wire-contract-is-the-invariant.md), and reports "" for
	 * the same reason the version does. What it was for - diagnosing clock skew between PHP
	 * and the storage engine - is not answered by a second engine's client library anyway
	 * once that engine stores nothing; `time_local` and PostgreSQL's own now() are.
	 */
	private static function getSqliteLocaltime(int $offset): string
	{
		if (!in_array('sqlite', \PDO::getAvailableDrivers()))
		{
			return '';
		}

		$pdo = new \PDO('sqlite::memory:');
		if ($offset > 0)
		{
			return $pdo->query('SELECT datetime(\'now\', \'+' . $offset . ' seconds\', \'localtime\');')->fetch()[0];
		}
		else
		{
			return $pdo->query('SELECT datetime(\'now\', \'' . $offset . ' seconds\', \'localtime\');')->fetch()[0];
		}
	}

	/**
	 * Returns the current server time from several perspectives (PHP local, UTC, SQLite),
	 * optionally shifted by $offset seconds.
	 *
	 * @param int $offset Shift in seconds relative to now
	 * @return array {timezone: string, time_local: string, time_local_sqlite3: string, time_utc: string, timestamp: int, offset: int}
	 */
	public function GetSystemTime(int $offset = 0): array
	{
		$timestamp = time() + $offset;
		$timeLocal = date('Y-m-d H:i:s', $timestamp);
		$timeUTC = self::convertToUtc($timestamp);
		return [
			'timezone' => date_default_timezone_get(),
			'time_local' => $timeLocal,
			'time_local_sqlite3' => self::getSqliteLocaltime($offset),
			'time_utc' => $timeUTC,
			'timestamp' => $timestamp,
			'offset' => $offset
		];
	}
}
