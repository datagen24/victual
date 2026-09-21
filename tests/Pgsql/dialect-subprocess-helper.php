<?php

// The dialect factory, in a process whose VICTUAL_DB_DRIVER is whatever the scenario needs.
//
//   php dialect-subprocess-helper.php <scenario>
//
// DatabaseDialect::Create() reads the VICTUAL_DB_DRIVER *constant*, and PHP cannot redefine
// one, so the branches below cannot all be reached from the process PHPUnit runs the class
// in. Setting() reads the VICTUAL_DB_DRIVER environment variable ahead of config.php's
// default (helpers/extensions.php:389), which is how the parent asks for each driver.
//
// The SQLite side is built only through DatabaseDialect::SQLITE_TOOLING_ENV, which is what
// AGENTS.md permits and is itself half of what is being asserted: ADR-0008 retired SQLite as
// a runtime engine, and the retirement is only real if a configuration naming it is refused.
//
// Prints one JSON object on stdout and exits 0. A scenario that behaves unexpectedly still
// exits 0 and reports what happened - the parent decides what is a failure, so that the
// assertion and its message live together.

$scenario = $argv[1] ?? '';

$datapath = getenv('VICTUAL_DATAPATH');

if ($datapath === false)
{
	fwrite(STDERR, "VICTUAL_DATAPATH must be set\n");
	exit(2);
}

define('VICTUAL_ROOT_PATH', dirname(__DIR__, 2));

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

$_SERVER['REQUEST_URI'] ??= '/';

define('VICTUAL_DATAPATH', $datapath);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_ID', 9000);
define('VICTUAL_USER_USERNAME', 'dialect-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

use Victual\Services\Database\DatabaseDialect;

function attempt(callable $work): array
{
	try
	{
		return ['ok' => true, 'value' => $work()];
	}
	catch (\Throwable $exception)
	{
		return ['ok' => false, 'class' => get_class($exception), 'message' => $exception->getMessage()];
	}
}

switch ($scenario)
{
	case 'driver':
		// What Create() does with the configured driver, and nothing else. Three callers
		// use this scenario with three different VICTUAL_DB_DRIVER values.
		$created = attempt(static fn () => get_class(DatabaseDialect::Create()));

		echo json_encode([
			'driver' => defined('VICTUAL_DB_DRIVER') ? VICTUAL_DB_DRIVER : null,
			'tooling_permitted' => DatabaseDialect::SqliteToolingIsPermitted(),
			'create' => $created,
			'name' => $created['ok'] ? (DatabaseDialect::Create())->GetName() : null,
		]);

		exit(0);

	case 'sqlite-behaviour':
		// The dialect's own answers, against a real in-memory SQLite connection. Built
		// through Create() rather than with `new`, because SQLITE_TOOLING_ENV is the only
		// way this fork permits one to exist (AGENTS.md).
		$dialect = DatabaseDialect::Create();

		// \PDO\Sqlite, not \PDO: PHP 8.4 moved createFunction() onto the driver subclass,
		// and SqliteDialect::CreateConnection() constructs the same class for that reason.
		// A plain new PDO('sqlite:...') here would fatal in OnConnected() and say nothing
		// about the application.
		$pdo = new \PDO\Sqlite('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$dialect->OnConnected($pdo);

		// The three functions OnConnected registers exist because SQLite has no native
		// equivalent. Asked through SQL rather than by reflection: whether the callback was
		// registered is exactly whether a statement can call it.
		$regexp = $pdo->query("SELECT 'abc' REGEXP '^a'")->fetchColumn();
		$ceiling = $pdo->query('SELECT ceil(1.2)')->fetchColumn();

		$missingTable = attempt(static function () use ($pdo)
		{
			$pdo->query('SELECT * FROM a_table_that_does_not_exist');
		});

		$missingTableVerdict = null;

		try
		{
			$pdo->query('SELECT * FROM a_table_that_does_not_exist');
		}
		catch (\PDOException $exception)
		{
			$missingTableVerdict = $dialect->IsMissingTableError($exception);
		}

		// A syntax error is also HY000 on SQLite, which is the reason the dialect checks the
		// message as well as the SQLSTATE. If this came back true the check would be
		// answering "SQLite failed" rather than "that table is not there".
		$syntaxVerdict = null;

		try
		{
			$pdo->query('SELECT SELECT SELECT');
		}
		catch (\PDOException $exception)
		{
			$syntaxVerdict = $dialect->IsMissingTableError($exception);
		}

		$path = $dialect->GetDbFilePath();
		touch($path);
		$dialect->SetDbChangedTime($pdo, '2021-03-04 05:06:07');
		$rewound = filemtime($path);
		clearstatcache(true, $path);
		$dialect->MarkDbChanged($pdo);
		$afterMark = filemtime($path);
		unlink($path);

		echo json_encode([
			'class' => get_class($dialect),
			'name' => $dialect->GetName(),
			'regexp' => (int)$regexp,
			'ceiling' => (float)$ceiling,
			'regexp_condition' => $dialect->GetRegexpCondition('products.name'),
			'missing_table_threw' => $missingTable['ok'] === false,
			'missing_table_recognised' => $missingTableVerdict,
			'syntax_error_recognised' => $syntaxVerdict,
			'db_file_path' => $path,
			'set_changed_time' => $rewound,
			'expected_changed_time' => strtotime('2021-03-04 05:06:07'),
			'mark_changed_time' => $afterMark,
			'requires_change_tracking' => $dialect->RequiresChangeTracking(),
			'supports_multi_statement_exec' => $dialect->SupportsMultiStatementExec(),
		]);

		exit(0);

	default:
		fwrite(STDERR, 'unknown scenario: ' . $scenario . "\n");
		exit(2);
}
