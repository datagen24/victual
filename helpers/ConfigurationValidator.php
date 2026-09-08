<?php

namespace Victual\Helpers;

use Victual\Services\Database\DatabaseDialect;
use Victual\Services\Storage\FileSizeLimit;

/**
 * Thrown when a VICTUAL_* configuration constant has an invalid value.
 */
class EInvalidConfig extends \Exception
{
}

/**
 * Validates the VICTUAL_* configuration constants (mode, database driver, file storage,
 * locale, currency, entry page, week start days, auto night mode range, and the MQTT and
 * InfluxDB outbound targets) on application startup, in that order.
 */
class ConfigurationValidator
{
	/**
	 * Runs all configuration checks.
	 *
	 * @throws EInvalidConfig On the first invalid configuration value found
	 */
	public function validateConfig()
	{
		self::checkMode();
		self::checkAuthClass();
		self::checkDatabaseDriver();
		self::checkFileStorage();
		self::checkLabelSubsystem();
		self::checkDefaultLocale();
		self::checkCurrencyFormat();
		self::checkFirstDayOfWeek();
		self::checkEntryPage();
		self::checkMealplanFirstDayOfWeek();
		self::checkAutoNightModeRange();
		self::checkCorsAllowedOrigins();
		self::checkMqttSettings();
		self::checkInfluxDbSettings();
	}

	private function checkMode()
	{
		$allowedModes = ['production', 'dev', 'demo', 'prerelease'];
		if (!in_array(VICTUAL_MODE, $allowedModes))
		{
			throw new EInvalidConfig('Invalid mode "' . VICTUAL_MODE . '" set, only ' . implode(', ', $allowedModes) . ' allowed');
		}
	}

	/**
	 * AUTH_CLASS names a class that exists and is an authentication middleware.
	 *
	 * app.php does `new $authMiddlewareClass(...)` on this value, which arrives from
	 * config.php, the environment or a settingoverrides file. Seven other settings were
	 * validated here and this one was not, so a typo was a fatal on the first request
	 * rather than a message at startup, and a value naming some other class was an object
	 * with no __invoke() (sweep finding S18, plan 15-B1).
	 *
	 * The trust level is the same as writing config.php, which is why the finding is Low.
	 * The check is worth having anyway: a fork that just removed an authentication backend
	 * wants the installations still naming it to be told so in one line, not to discover
	 * it as a stack trace.
	 */
	private function checkAuthClass()
	{
		$authClass = VICTUAL_AUTH_CLASS;

		if (!class_exists($authClass))
		{
			throw new EInvalidConfig('AUTH_CLASS "' . $authClass . '" does not exist. The LDAP backend was removed - reverse proxy authentication (Victual\\Middleware\\Auth\\ReverseProxyAuthMiddleware) is how an external directory reaches Victual now');
		}

		if (!is_subclass_of($authClass, \Victual\Middleware\Auth\BaseAuthMiddleware::class))
		{
			throw new EInvalidConfig('AUTH_CLASS "' . $authClass . '" is not an authentication middleware - it has to extend Victual\\Middleware\\Auth\\BaseAuthMiddleware');
		}
	}

	/**
	 * DB_DRIVER, and the connection settings PostgreSQL needs.
	 *
	 * "sqlite" is refused by name rather than falling through to the generic "invalid
	 * driver" message, because it is the one wrong value an existing installation is
	 * likely to hold: it was this fork's default until ADR-0008's retirement, so an
	 * operator upgrading meets this check with a config.php that was correct yesterday.
	 * The message therefore has to say what to do, not just that the value is wrong -
	 * bin/victual-db-import is the whole answer, and it still reads that file.
	 */
	private function checkDatabaseDriver()
	{
		$driver = strtolower(VICTUAL_DB_DRIVER);

		if ($driver === 'sqlite')
		{
			if (!DatabaseDialect::SqliteToolingIsPermitted())
			{
				throw new EInvalidConfig('DB_DRIVER "sqlite" is no longer a runtime database engine '
					. '(ADR-0008). SQLite is an import format now: set DB_DRIVER to "pgsql" with the '
					. 'DB_HOST, DB_NAME and DB_USER settings, run "php bin/victual-migrate", and then '
					. 'move the existing database across with "php bin/victual-db-import '
					. '/path/to/victual.db".');
			}
		}
		elseif (!in_array($driver, DatabaseDialect::RUNTIME_DRIVERS))
		{
			throw new EInvalidConfig('Invalid database driver "' . VICTUAL_DB_DRIVER . '" set, only ' . implode(', ', DatabaseDialect::RUNTIME_DRIVERS) . ' allowed');
		}

		if (!in_array($driver, \PDO::getAvailableDrivers()))
		{
			throw new EInvalidConfig('The PDO driver for "' . $driver . '" is not installed in this PHP environment');
		}

		if ($driver === 'pgsql')
		{
			if (empty(VICTUAL_DB_NAME) || empty(VICTUAL_DB_HOST) || empty(VICTUAL_DB_USER))
			{
				throw new EInvalidConfig('DB_HOST, DB_NAME and DB_USER need to be set when DB_DRIVER is "pgsql"');
			}

			$allowedSslModes = ['', 'disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
			if (!in_array(VICTUAL_DB_SSLMODE, $allowedSslModes))
			{
				throw new EInvalidConfig('Invalid DB_SSLMODE "' . VICTUAL_DB_SSLMODE . '" set, only ' . implode(', ', array_filter($allowedSslModes)) . ' allowed');
			}
		}
	}

	/**
	 * FILE_STORAGE, the two combinations it may not be in, and the upload size limit.
	 *
	 * Both are refused here rather than at the first upload, which is the point: a
	 * household that flips the setting finds out at startup, when it can still change its
	 * mind, instead of when someone tries to attach a picture.
	 *
	 * "database" needs PostgreSQL because the backend is BYTEA, bound as a LOB through raw
	 * PDO; there is deliberately no SQLite BLOB counterpart (plan 01, and ADR-0008 makes
	 * PostgreSQL the only runtime engine anyway).
	 *
	 * It is refused in demo/prerelease mode because FilesystemStorage gives each demo
	 * instance its own storage sub folder, and the files table's UNIQUE(file_group, name)
	 * has no column for that suffix - two demo instances sharing a database would collide
	 * on it. The demo path already provisions a database per instance, so scoping it out
	 * costs nothing (plan 01 Q4).
	 */
	private function checkFileStorage()
	{
		$allowedStorages = ['filesystem', 'database'];
		if (!in_array(VICTUAL_FILE_STORAGE, $allowedStorages))
		{
			throw new EInvalidConfig('Invalid file storage "' . VICTUAL_FILE_STORAGE . '" set, only ' . implode(', ', $allowedStorages) . ' allowed');
		}

		if (VICTUAL_FILE_STORAGE === 'database')
		{
			if (strtolower(VICTUAL_DB_DRIVER) !== 'pgsql')
			{
				throw new EInvalidConfig('FILE_STORAGE "database" requires DB_DRIVER "pgsql", but DB_DRIVER is "' . VICTUAL_DB_DRIVER . '" - either switch the database driver or set FILE_STORAGE back to "filesystem"');
			}

			if (VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
			{
				throw new EInvalidConfig('FILE_STORAGE "database" is not supported in "' . VICTUAL_MODE . '" mode, because demo instances share a storage location by file name suffix and the files table has no column for it - use FILE_STORAGE "filesystem" here');
			}
		}

		if (!is_numeric(VICTUAL_FILE_STORAGE_MAX_SIZE_MB) || VICTUAL_FILE_STORAGE_MAX_SIZE_MB <= 0)
		{
			throw new EInvalidConfig('FILE_STORAGE_MAX_SIZE_MB must be a positive number of megabytes, "' . VICTUAL_FILE_STORAGE_MAX_SIZE_MB . '" given');
		}

		// Resolving the limit here rather than at the first upload is what makes it a
		// startup fact: a FILE_STORAGE_MAX_SIZE_MB larger than what PHP will accept logs
		// its clamp while someone is still looking at the boot output, instead of the
		// first time a household member is refused a picture. Startup keeps running
		// either way - a clamp is information, not a failure (plan 01 Q2).
		FileSizeLimit::EffectiveMaxBytes();
	}

	/**
	 * The label subsystem stores artifacts and assets in the database or it does not run.
	 *
	 * Plan 27 question 7, answered by the maintainer: require PostgreSQL-backed storage and
	 * say so at boot. FILE_STORAGE "filesystem" would put captured household data and every
	 * printed label on disk, reintroducing the persistent volume plan 10 exists to remove -
	 * and there is deliberately no silent fallback, because a household that enables labels
	 * with the wrong backend should find out while it can still change its mind rather than
	 * when an artifact has already gone somewhere it was not meant to live. That is
	 * checkFileStorage()'s own stated reason for refusing at startup rather than at first
	 * upload, applied to a second thing that needs it.
	 *
	 * Conditional on the flag, in the shape checkMqttSettings() and checkInfluxDbSettings()
	 * already use: a deployment that does not print labels is not asked to change its storage.
	 */
	private function checkLabelSubsystem()
	{
		if (!VICTUAL_FEATURE_FLAG_LABELS)
		{
			return;
		}

		if (VICTUAL_FILE_STORAGE !== 'database')
		{
			throw new EInvalidConfig('FEATURE_FLAG_LABELS requires FILE_STORAGE "database", but FILE_STORAGE is "' . VICTUAL_FILE_STORAGE . '" - label artifacts and assets carry captured household data and every printed label, and putting them on disk would reintroduce the persistent volume the deployment does not have');
		}

		// Said directly rather than left to be derived from two separate refusals:
		// checkFileStorage() already refuses FILE_STORAGE "database" in these modes, so
		// requiring it here excludes them transitively, and an operator should read that
		// once rather than assemble it.
		if (VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
		{
			throw new EInvalidConfig('FEATURE_FLAG_LABELS cannot be enabled in "' . VICTUAL_MODE . '" mode: it requires FILE_STORAGE "database", which these modes do not support because demo instances share a storage location by file name suffix and the files table has no column for it');
		}
	}

	private function checkDefaultLocale()
	{
		if (!file_exists(__DIR__ . '/../localization/' . VICTUAL_DEFAULT_LOCALE))
		{
			throw new EInvalidConfig('Invalid locale "' . VICTUAL_DEFAULT_LOCALE . '" set, locale needs to exist in folder localization');
		}
	}

	private function checkFirstDayOfWeek()
	{
		if (!(VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK == '' ||
			(is_numeric(VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK) && VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK >= 0 && VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK <= 6)))
		{
			throw new EInvalidConfig('Invalid value for CALENDAR_FIRST_DAY_OF_WEEK');
		}
	}

	private function checkCurrencyFormat()
	{
		if (!(preg_match('/^([A-z]){3}$/', VICTUAL_CURRENCY)))
		{
			throw new EInvalidConfig('CURRENCY is not in ISO 4217 format (three letter code)');
		}
	}

	private function checkEntryPage()
	{
		$allowedPages = ['stock', 'shoppinglist', 'recipes', 'chores', 'tasks', 'batteries', 'equipment', 'calendar', 'mealplan'];
		if (!in_array(VICTUAL_ENTRY_PAGE, $allowedPages))
		{
			throw new EInvalidConfig('Invalid entry page "' . VICTUAL_ENTRY_PAGE . '" set, only ' . implode(', ', $allowedPages) . ' allowed');
		}
	}

	private function checkMealplanFirstDayOfWeek()
	{
		if (!(VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK == '' ||
			(is_numeric(VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK) && VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK >= -1 && VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK <= 6)))
		{
			throw new EInvalidConfig('Invalid value for MEAL_PLAN_FIRST_DAY_OF_WEEK');
		}
	}

	/**
	 * Every entry of CORS_ALLOWED_ORIGINS has to be an origin, because that is what
	 * CorsMiddleware compares the request's Origin header against - exactly, since a
	 * prefix or substring match on an origin is how a rule meant for
	 * "https://home.example.com" comes to admit "https://home.example.com.evil.test".
	 *
	 * The failure this refuses is a silent one: a browser sends `Origin` with no path and
	 * no trailing slash, so an entry written as "https://home.example.com/" never matches
	 * anything and the setting looks configured while behaving as if it were empty.
	 */
	private function checkCorsAllowedOrigins()
	{
		foreach (\Victual\Middleware\CorsMiddleware::AllowedOrigins() as $origin)
		{
			if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?$#', $origin))
			{
				throw new EInvalidConfig('Invalid CORS_ALLOWED_ORIGINS entry "' . $origin . '" - each entry has to be a bare origin such as "https://home.example.com", with no path and no trailing slash');
			}
		}
	}

	/**
	 * MQTT is off by default, so the only way to get this wrong is to turn it on. Both
	 * checks catch a misconfiguration that would otherwise be silent: a publish path
	 * swallows every failure by design (a broker must never break a write), so an empty
	 * host would show up as nothing being published and no error anywhere.
	 */
	private function checkMqttSettings()
	{
		if (!VICTUAL_MQTT_ENABLED)
		{
			return;
		}

		if (empty(trim(VICTUAL_MQTT_HOST)))
		{
			throw new EInvalidConfig('MQTT_HOST needs to be set when MQTT_ENABLED is true');
		}

		$allowedDiscoveryModes = ['device', 'entity'];
		if (!in_array(VICTUAL_MQTT_DISCOVERY_MODE, $allowedDiscoveryModes))
		{
			throw new EInvalidConfig('Invalid MQTT_DISCOVERY_MODE "' . VICTUAL_MQTT_DISCOVERY_MODE . '" set, only ' . implode(', ', $allowedDiscoveryModes) . ' allowed');
		}

		// The library rejects anything below 1 second outright, and this timeout is what
		// bounds how long an unreachable broker delays a committed write
		if (!is_numeric(VICTUAL_MQTT_CONNECT_TIMEOUT_SECONDS) || (int)VICTUAL_MQTT_CONNECT_TIMEOUT_SECONDS < 1)
		{
			throw new EInvalidConfig('MQTT_CONNECT_TIMEOUT_SECONDS needs to be a whole number of seconds, at least 1');
		}
	}

	/**
	 * Same shape as checkMqttSettings(), same reason: the write path swallows its own
	 * failures so that a metrics server can never break a committed booking, which means a
	 * misconfiguration would otherwise be entirely silent.
	 */
	private function checkInfluxDbSettings()
	{
		if (!VICTUAL_INFLUXDB_ENABLED)
		{
			return;
		}

		if (empty(trim(VICTUAL_INFLUXDB_URL)))
		{
			throw new EInvalidConfig('INFLUXDB_URL needs to be set when INFLUXDB_ENABLED is true');
		}

		if (empty(trim(VICTUAL_INFLUXDB_BUCKET)) || empty(trim(VICTUAL_INFLUXDB_ORG)))
		{
			throw new EInvalidConfig('INFLUXDB_ORG and INFLUXDB_BUCKET need to be set when INFLUXDB_ENABLED is true');
		}

		if (!is_numeric(VICTUAL_INFLUXDB_TIMEOUT_SECONDS) || (int)VICTUAL_INFLUXDB_TIMEOUT_SECONDS < 1)
		{
			throw new EInvalidConfig('INFLUXDB_TIMEOUT_SECONDS needs to be a whole number of seconds, at least 1');
		}
	}

	private function checkAutoNightModeRange()
	{
		global $VICTUAL_DEFAULT_USER_SETTINGS;
		if (!(preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_from'])))
		{
			throw new EInvalidConfig('auto_night_mode_time_range_from is not in HH:mm format (' . $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_from'] . ')');
		}
		if (!(preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_to'])))
		{
			throw new EInvalidConfig('auto_night_mode_time_range_to is not in HH:mm format (' . $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_to'] . ')');
		}
	}
}
