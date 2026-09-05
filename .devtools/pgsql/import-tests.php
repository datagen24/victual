<?php

// Does bin/victual-db-import still read the SQLite databases it promises to read?
//
//   php import-tests.php
//
//   IMPORTTEST_DB_NAME                   the PostgreSQL database to import into
//   PGHOST, PGPORT, PGUSER, PGPASSWORD   how to reach it
//
// This is the phase ADR-0008 asks for by name. Once SQLite stops being a runtime engine,
// the importer reads a format nothing in this repository produces: its input drifts on
// somebody else's schedule and there is no second engine here whose behaviour would notice.
// Committed fixtures at both ends of the supported span, and assertions about what
// importing each of them produces, are what replace the engine that used to be the check.
//
// It drives the command rather than the class. DatabaseImporter is exercised in passing by
// three other phases; what has never been asserted is the thing an operator actually runs -
// its exit code, the target it leaves behind, and its refusals. The refusal cases are the
// half that only exists because of the span: a message that says "no" without saying what
// would have been accepted sends its reader to the source code.
//
// The fixtures are built by fixtures/import/make-fixtures.sh, which documents how and why
// there are two of them.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

use Victual\Services\Database\DatabaseImporter;

const FIXTURE_DIR = __DIR__ . '/fixtures/import';

$dbName = getenv('IMPORTTEST_DB_NAME');

if (empty($dbName))
{
	exit('IMPORTTEST_DB_NAME must be set' . PHP_EOL);
}

$min = DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN;
$max = DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX;

$failures = 0;
$scratch = sys_get_temp_dir() . '/victual-import-tests-' . getmypid();

function Ok(string $label, string $detail): void
{
	printf("  ok     %-46s %s\n", $label, $detail);
}

function Fail(string $label, string $detail): void
{
	global $failures;

	$failures++;
	printf("  FAIL   %-46s %s\n", $label, $detail);
}

function Check(string $label, bool $condition, string $expected, string $actual): void
{
	$condition ? Ok($label, $actual) : Fail($label, 'expected ' . $expected . ', got ' . $actual);
}

/**
 * A data directory whose config.php points at the target database, so the command under
 * test is configured the way an operator's installation is rather than through arguments
 * this command does not have.
 */
function DataPath(string $scratch, string $dbName): string
{
	$path = $scratch . '/data';

	if (!is_dir($path))
	{
		mkdir($path, 0700, true);
	}

	file_put_contents($path . '/config.php', "<?php\n"
		. "Setting('DB_DRIVER', 'pgsql');\n"
		. "Setting('DB_HOST', getenv('PGHOST'));\n"
		. "Setting('DB_PORT', intval(getenv('PGPORT')));\n"
		. "Setting('DB_NAME', " . var_export($dbName, true) . ");\n"
		. "Setting('DB_USER', getenv('PGUSER'));\n"
		. "Setting('DB_PASSWORD', getenv('PGPASSWORD'));\n");

	return $path;
}

/**
 * Runs bin/victual-db-import against $source and returns [exit code, combined output].
 *
 * The environment is passed through rather than inherited wholesale so that
 * DIFFTEST_SQLITE_RUNTIME does not reach it: the command under test must configure itself
 * as a PostgreSQL installation, and a suite that quietly handed it the escape hatch would
 * be testing something no operator can run.
 */
function RunImport(string $dataPath, string $source, array $flags = []): array
{
	$command = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(VICTUAL_ROOT_PATH . '/bin/victual-db-import')
		. ' ' . escapeshellarg($source);

	foreach ($flags as $flag)
	{
		$command .= ' ' . escapeshellarg($flag);
	}

	$environment = [
		'PATH' => getenv('PATH'),
		'PGHOST' => getenv('PGHOST'),
		'PGPORT' => getenv('PGPORT'),
		'PGUSER' => getenv('PGUSER'),
		'PGPASSWORD' => getenv('PGPASSWORD'),
		'VICTUAL_DATAPATH' => $dataPath
	];

	// The coverage hook, when the run is measuring it: these are child processes and would
	// otherwise be the one part of the suite whose lines never show up.
	foreach (['PHP_INI_SCAN_DIR', 'VICTUAL_COVERAGE_DIR'] as $name)
	{
		if (getenv($name) !== false)
		{
			$environment[$name] = getenv($name);
		}
	}

	$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$process = proc_open($command . ' 2>&1', $descriptors, $pipes, VICTUAL_ROOT_PATH, $environment);

	if (!is_resource($process))
	{
		return [-1, 'could not start ' . $command];
	}

	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($process), $output];
}

/**
 * A copy of $fixture whose recorded schema version has been moved to $version, for the
 * refusal cases.
 *
 * Only the migrations table is touched: the point is a source whose *claim* about itself is
 * outside the span, which is exactly what a database from an older grocy or a newer
 * something-else looks like from here. Rewriting the schema as well would be testing a
 * different refusal.
 */
function FixtureClaiming(string $fixture, int $version, string $scratch): string
{
	$path = $scratch . '/claims-' . $version . '.db';

	if (!is_dir($scratch))
	{
		mkdir($scratch, 0700, true);
	}

	copy($fixture, $path);

	$db = new \PDO('sqlite:' . $path);
	$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	$db->exec('DELETE FROM migrations');
	$db->exec('INSERT INTO migrations (migration) VALUES (' . $version . ')');
	$db = null;

	return $path;
}

function Target(string $dbName): \PDO
{
	$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . $dbName;
	$pdo = new \PDO($dsn, getenv('PGUSER') ?: null, getenv('PGPASSWORD') ?: null);
	$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	$pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);

	return $pdo;
}

function Scalar(\PDO $pdo, string $sql)
{
	return $pdo->query($sql)->fetchColumn();
}

function RemoveTree(string $path): void
{
	if (!is_dir($path))
	{
		@unlink($path);

		return;
	}

	foreach (scandir($path) ?: [] as $entry)
	{
		if ($entry === '.' || $entry === '..')
		{
			continue;
		}

		RemoveTree($path . '/' . $entry);
	}

	@rmdir($path);
}

echo 'SQLite import (span ' . $min . '-' . $max . ")\n";

$dataPath = DataPath($scratch, $dbName);

// --- The two ends of the span import at all ----------------------------------------
//
// --force because the runner migrated the target with bin/victual-migrate, which seeds a
// fresh installation's rows - the state an operator's target is in when they reach for this
// command, and the reason the flag exists.

foreach ([$min, $max] as $version)
{
	$fixture = FIXTURE_DIR . '/victual-' . $version . '.db';

	echo PHP_EOL . 'A. A source at ' . $version . PHP_EOL;

	if (!is_readable($fixture))
	{
		Fail('the fixture exists', $fixture . ' is missing - run fixtures/import/make-fixtures.sh');

		continue;
	}

	[$code, $output] = RunImport($dataPath, $fixture, ['--force']);

	Check('the command exits 0', $code === 0, 'exit 0', 'exit ' . $code . ($code === 0 ? '' : ': ' . trim($output)));

	if ($code !== 0)
	{
		continue;
	}

	$target = Target($dbName);

	// Every row that was in the source is in the target. Asserted per table rather than as
	// a total, because a total hides a table that copied nothing against another that
	// copied twice - and the tables the older fixture does not have at all are exactly the
	// case this is here to watch.
	$source = new \PDO('sqlite:' . $fixture);
	$source->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

	$mismatched = [];
	$compared = 0;

	foreach ($source->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN) as $table)
	{
		if (in_array($table, DatabaseImporter::NOT_COPIED_TABLES, true))
		{
			continue;
		}

		try
		{
			$expected = intval(Scalar($source, 'SELECT COUNT(*) FROM "' . $table . '"'));
			// Compare the frozen source rows; separately assert the six new grants below.
			$where = $table === 'permission_hierarchy' ? ' WHERE id <= 30' : ($table === 'user_permissions' ? ' WHERE permission_id <= 30' : '');
			$actual = intval(Scalar($target, 'SELECT COUNT(*) FROM "' . $table . '"' . $where));
		}
		catch (\PDOException $ex)
		{
			// A table the target does not have is not a failure of the copy: the target is
			// migrated for its own engine and the source is a foreign schema. It is a
			// failure of this assertion to be able to say anything, so it is named.
			$mismatched[] = $table . ' (not in the target)';

			continue;
		}

		$compared++;

		if ($expected !== $actual)
		{
			$mismatched[] = $table . ' (' . $expected . ' -> ' . $actual . ')';
		}
	}

	Check('every table copied its rows', empty($mismatched), 'no differences',
		empty($mismatched) ? $compared . ' tables compared' : implode(', ', $mismatched));

	$leaves = Scalar($target, "SELECT COUNT(*) FROM permission_hierarchy WHERE name IN ('STOCK_VIEW','SHOPPINGLIST_VIEW','CHORES_VIEW','TASKS_VIEW','RECIPES_VIEW','MEALPLAN_VIEW')");
	Check('six read leaves restored after copy', (int)$leaves === 6, '6', (string)$leaves);
	$backfilled = Scalar($target, "SELECT COUNT(*) FROM user_permissions up JOIN permission_hierarchy p ON p.id=up.permission_id WHERE p.name IN ('STOCK_VIEW','SHOPPINGLIST_VIEW','CHORES_VIEW','TASKS_VIEW','RECIPES_VIEW','MEALPLAN_VIEW')");
	$expectedGrants = 6 * (int)Scalar($source, 'SELECT COUNT(*) FROM users');
	Check('imported users keep all previous reads', (int)$backfilled === $expectedGrants, (string)$expectedGrants, (string)$backfilled);
	$roleGrants = Scalar($target, 'SELECT COUNT(*) FROM role_permissions');
	Check('built-in role grants restored', (int)$roleGrants === 28, '28', (string)$roleGrants);

	// The two row transformations the target's own migration run could not see, because it
	// ran against an empty database and the rows arrived afterwards.
	$dangerous = Scalar($target, "SELECT COUNT(*) FROM recipes WHERE description LIKE '%<script%' OR description LIKE '%onerror%'");
	Check('stored HTML was purified', intval($dangerous) === 0, '0 rows carrying a payload', $dangerous . ' rows');

	$readable = Scalar($target, "SELECT COUNT(*) FROM api_keys WHERE key_type = 'default' AND api_key !~ '^[0-9a-f]{64}$'");
	Check('regular API keys were hashed', intval($readable) === 0, '0 readable keys', $readable . ' readable');

	// ...and the exception migration 0264 makes, which has to survive being applied twice.
	$calendar = Scalar($target, "SELECT COUNT(*) FROM api_keys WHERE key_type <> 'default' AND api_key ~ '^[0-9a-f]{64}$'");
	Check('calendar keys stay readable', intval($calendar) === 0, '0 hashed calendar keys', $calendar . ' hashed');

	// An empty string is not a NULL. The importer sets PDO::NULL_NATURAL on the source for
	// this, and the failure it prevents is silent: a NOT NULL column rejecting a row, or a
	// value that was "" coming back as nothing.
	$emptyString = Scalar($target, "SELECT COUNT(*) FROM shopping_locations WHERE description = ''");
	Check('an empty string stayed an empty string', intval($emptyString) === 1, '1 row', $emptyString . ' rows');

	// The target keeps its own migration history rather than the source's, which is what
	// stops a PostgreSQL database claiming it ran the SQLite-only files.
	$claimsSqliteOnly = Scalar($target, 'SELECT COUNT(*) FROM migrations WHERE migration = 256');
	Check('the target kept its own migration history', intval($claimsSqliteOnly) === 0,
		'no SQLite-only migration recorded', $claimsSqliteOnly . ' recorded');

	$source = null;
	$target = null;
}

// --- Importing the same source twice ------------------------------------------------
//
// An operator who runs this again - after a failed first attempt, or to refresh a staging
// copy - must get the same target rather than a doubled one, and in particular must not
// have their API keys hashed a second time. A hash of a hash is a well-formed value that
// nothing rejects and every client is locked out by.

echo PHP_EOL . 'B. The same source imported twice' . PHP_EOL;

$fixture = FIXTURE_DIR . '/victual-' . $min . '.db';

if (is_readable($fixture))
{
	[$code] = RunImport($dataPath, $fixture, ['--force']);
	$target = Target($dbName);
	$firstPass = Scalar($target, "SELECT api_key FROM api_keys WHERE key_type = 'default' ORDER BY id LIMIT 1");

	[$code, $output] = RunImport($dataPath, $fixture, ['--force']);
	Check('the second run exits 0', $code === 0, 'exit 0', 'exit ' . $code . ($code === 0 ? '' : ': ' . trim($output)));

	$secondPass = Scalar($target, "SELECT api_key FROM api_keys WHERE key_type = 'default' ORDER BY id LIMIT 1");
	Check('the key is hashed once, not twice', $firstPass === $secondPass,
		'the same hash', $firstPass === $secondPass ? substr((string)$secondPass, 0, 12) . '...' : 'it changed');

	$target = null;
}

// --- Outside the span ----------------------------------------------------------------

echo PHP_EOL . 'C. A source outside the span' . PHP_EOL;

foreach ([$min - 1, $max + 1] as $version)
{
	$doctored = FixtureClaiming(FIXTURE_DIR . '/victual-' . $max . '.db', $version, $scratch);

	[$code, $output] = RunImport($dataPath, $doctored, ['--force']);

	Check('a source at ' . $version . ' is refused', $code !== 0, 'a non-zero exit', 'exit ' . $code);

	// Both numbers, because the operator's next question is "then what would you take?" and
	// an error that does not answer it sends them to the source code.
	$namesBoth = str_contains($output, (string)$min) && str_contains($output, (string)$max);
	Check('the refusal names both ends of the span', $namesBoth,
		$min . ' and ' . $max . ' in the message', $namesBoth ? 'it does' : trim($output));

	unlink($doctored);
}

// --- A file that is not one of ours ---------------------------------------------------

echo PHP_EOL . 'D. A source that is not a grocy or Victual database' . PHP_EOL;

if (!is_dir($scratch))
{
	mkdir($scratch, 0700, true);
}

$empty = $scratch . '/empty.db';
$db = new \PDO('sqlite:' . $empty);
$db->exec('CREATE TABLE something (id INTEGER)');
$db = null;

[$code, $output] = RunImport($dataPath, $empty, ['--force']);
Check('an unrelated SQLite file is refused', $code !== 0, 'a non-zero exit', 'exit ' . $code);
unlink($empty);

[$code, $output] = RunImport($dataPath, $scratch . '/does-not-exist.db');
Check('a missing file is refused', $code !== 0, 'a non-zero exit', 'exit ' . $code);

// The scratch directory, not the committed fixtures. Recursive because the data directory
// gains a view cache: the command under test loads the configuration, and HTMLPurifier's
// definition cache is written under VIEWCACHE_PATH, which defaults inside it.
RemoveTree($scratch);

echo PHP_EOL;

if ($failures === 0)
{
	echo "SQLITE IMPORT OK\n";
	exit(0);
}

echo $failures . " case(s) failed\n";
exit(1);
