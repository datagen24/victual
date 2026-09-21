<?php

// The out-of-process half of tests/Pgsql/HelperUnitsTest.php.
//
// Three of that file's subjects cannot be exercised from inside the PHPUnit process:
// ConfigurationValidator and IsApiRoutePath() read VICTUAL_* constants, which PHP cannot
// redefine (harness brief section 4); PrerequisiteChecker asks what the interpreter has
// loaded, which only a differently started interpreter can answer differently; and
// StderrLogger writes to php://stderr, which has to belong to a process whose stderr the
// test can read.
//
// Two shapes, decided by the SAPI:
//
//   php helperunits-subprocess-helper.php <base64 json spec>
//       Runs one task and prints one JSON object on stdout.
//
//   php -S 127.0.0.1:<port> helperunits-subprocess-helper.php
//       The local stand-in WebhookRunner posts to. It appends one JSON line per request
//       to the file named by HELPERUNITS_WEBHOOK_LOG and answers 204. AGENTS.md:40 keeps
//       this tree free of user-configurable outbound URLs, so the runner is never pointed
//       at anything but this loopback listener.

if (PHP_SAPI === 'cli-server')
{
	$log = getenv('HELPERUNITS_WEBHOOK_LOG');

	$record = [
		'method' => $_SERVER['REQUEST_METHOD'] ?? '',
		'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
		'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
		'body' => file_get_contents('php://input')
	];

	if ($log !== false && $log !== '')
	{
		file_put_contents($log, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
	}

	http_response_code(204);
	return;
}

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
define('VICTUAL_IS_EMBEDDED_INSTALL', false);

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

$spec = json_decode(base64_decode($argv[1] ?? ''), true);

if (!is_array($spec) || !isset($spec['task']))
{
	fwrite(STDERR, "no task in the spec\n");
	exit(2);
}

/**
 * Loads config-dist.php, which is what turns the VICTUAL_* environment variables the
 * caller set into the constants the subject reads (Setting(), helpers/extensions.php).
 *
 * A "user_settings" entry in the spec is planted in the global first: DefaultUserSetting()
 * keeps the first registration of a name, which is the only way to hand
 * checkAutoNightModeRange() a value config-dist.php does not define.
 */
function BootConfiguration(array $spec): void
{
	// An empty setting value cannot travel in the environment - proc_open drops a variable
	// whose value is the empty string, and getenv() then answers false - so a case that has
	// to hand the validator an empty DB_NAME or INFLUXDB_ORG defines the constant directly
	// instead. That is what a data/config.php does, which is the other supported source.
	foreach (($spec['defines'] ?? []) as $name => $value)
	{
		define('VICTUAL_' . $name, $value);
	}

	if (!empty($spec['user_settings']))
	{
		global $VICTUAL_DEFAULT_USER_SETTINGS;

		foreach ($spec['user_settings'] as $key => $value)
		{
			$VICTUAL_DEFAULT_USER_SETTINGS[$key] = $value;
		}
	}

	require_once VICTUAL_ROOT_PATH . '/config-dist.php';
}

/**
 * Runs $work and reports whether it refused, so the caller asserts on the refusal rather
 * than on an exit code.
 */
function Report(callable $work): void
{
	try
	{
		$result = $work();

		echo json_encode(['ok' => true, 'result' => $result]);
	}
	catch (\Throwable $exception)
	{
		echo json_encode([
			'ok' => false,
			'class' => get_class($exception),
			'message' => $exception->getMessage()
		]);
	}
}

switch ($spec['task'])
{
	case 'configuration':
		BootConfiguration($spec);
		Report(function ()
		{
			(new \Victual\Helpers\ConfigurationValidator())->validateConfig();

			return ['mode' => VICTUAL_MODE, 'file_storage' => VICTUAL_FILE_STORAGE];
		});
		break;

	case 'requirements':
		Report(function ()
		{
			(new \Victual\Helpers\PrerequisiteChecker())->checkRequirements();

			return ['php_version' => phpversion()];
		});
		break;

	case 'database-requirements':
		Report(function () use ($spec)
		{
			(new \Victual\Helpers\PrerequisiteChecker())->checkDatabaseRequirements((string)$spec['driver']);

			return ['driver' => $spec['driver']];
		});
		break;

	case 'api-route-path':
		BootConfiguration($spec);
		Report(function () use ($spec)
		{
			$answers = [];

			foreach ($spec['paths'] as $path)
			{
				$answers[$path] = IsApiRoutePath($path);
			}

			return ['base_path' => VICTUAL_BASE_PATH, 'answers' => $answers];
		});
		break;

	case 'route-cache-file':
		BootConfiguration($spec);
		Report(function ()
		{
			return [
				'file' => \Victual\Helpers\CachePaths::RouteCacheFile(),
				'glob' => \Victual\Helpers\CachePaths::RouteCacheGlob()
			];
		});
		break;

	case 'logger':
		Report(function () use ($spec)
		{
			// A process whose stderr has been closed is what the logger's "the stream could
			// not be opened" branch is about; php://stderr cannot be reopened once fd 2 is
			// gone, which is the only way to reach it.
			if (!empty($spec['close_stderr']))
			{
				fclose(STDERR);
			}

			$logger = array_key_exists('minimum_level', $spec)
				? new \Victual\Helpers\StderrLogger($spec['minimum_level'])
				: new \Victual\Helpers\StderrLogger();

			foreach ($spec['records'] as $record)
			{
				$context = $record['context'] ?? [];

				// A context value nothing can encode has to be built here: JSON carries no
				// resource, so the caller can only ask for one by name.
				if (($record['context_kind'] ?? '') === 'resource')
				{
					$context = ['handle' => fopen('php://memory', 'r')];
				}

				if (($record['context_kind'] ?? '') === 'too-deep')
				{
					$deep = 'leaf';

					for ($level = 0; $level < 600; $level++)
					{
						$deep = [$deep];
					}

					$context = ['deep' => $deep];
				}

				$logger->log($record['level'], $record['message'], $context);
			}

			return ['records' => count($spec['records'])];
		});
		break;

	case 'application-info':
		BootConfiguration($spec);
		Report(function () use ($spec)
		{
			// The caller's test schema, so that the migrations table this reads is the one
			// PgsqlSchemaTestCase migrated rather than an empty public schema.
			\Victual\Services\DatabaseService::GetInstance()->GetDbConnectionRaw()
				->exec('SET search_path TO ' . $spec['schema'] . ', public');

			$service = \Victual\Services\ApplicationService::GetInstance();
			$info = $service->GetSystemInfo();
			$time = $service->GetSystemTime((int)($spec['offset'] ?? 0));

			return [
				'drivers' => \PDO::getAvailableDrivers(),
				'sqlite_version' => $info['sqlite_version'],
				'database_engine' => $info['database_engine'],
				'time_local' => $time['time_local'],
				'time_local_sqlite3' => $time['time_local_sqlite3']
			];
		});
		break;

	case 'localization-dev':
		BootConfiguration($spec);
		Report(function () use ($spec)
		{
			$pot = VICTUAL_ROOT_PATH . '/localization/strings.pot';
			$before = hash_file('sha256', $pot);

			$service = \Victual\Services\LocalizationService::GetInstance($spec['locale']);

			$translated = [];

			foreach ($spec['texts'] as $text)
			{
				$translated[$text] = $service->__t($text);
			}

			return [
				'mode' => VICTUAL_MODE,
				'translated' => $translated,
				'pot_unchanged' => $before === hash_file('sha256', $pot)
			];
		});
		break;

	default:
		fwrite(STDERR, 'unknown task ' . $spec['task'] . "\n");
		exit(2);
}
