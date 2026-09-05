<?php

// Differential test: put both engines into an IDENTICAL table state, then compare what
// their views return. Loading cleanly proves nothing about equivalence - this does.
//
// The seed is applied to SQLite only, so its triggers fire naturally, and the resulting
// table contents are then copied verbatim into PostgreSQL. That isolates what is being
// tested (view logic) from what is not yet ported (triggers), and the copy step is a
// prototype of the eventual SQLite -> PostgreSQL migration command.
//
// Usage: php difftest.php <seed.sql> <view> [<view> ...]

require_once (getenv('VICTUAL_ROOT') ?: '/app') . '/packages/autoload.php';

use Victual\Services\Database\ValueComparison;

$seedFile = $argv[1];
$views = array_slice($argv, 2);

// PDO\Sqlite, not PDO: createFunction() is only on the driver specific subclass
$sqlite = new PDO\Sqlite(getenv('DIFFTEST_SQLITE_DSN') ?: 'sqlite:/data/difftest.db');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Victual registers helper functions on its SQLite connections from PHP (see
// SqliteDialect::OnConnected). Without them every view that uses one - anything built on
// products_volatile_status, for instance - fails on the SQLite side before the two engines
// are even compared, which would quietly exempt those views from being tested at all.
$defaultUserSettings = [];
$configDist = (getenv('VICTUAL_ROOT') ?: '/app') . '/config-dist.php';
if (file_exists($configDist))
{
	// Only to pick up the default user settings; the constants are irrelevant here
	if (!defined('VICTUAL_DATAPATH')) define('VICTUAL_DATAPATH', sys_get_temp_dir());
	@include $configDist;
	global $VICTUAL_DEFAULT_USER_SETTINGS;
	$defaultUserSettings = $VICTUAL_DEFAULT_USER_SETTINGS ?? [];
}

$sqlite->createFunction('victual_user_setting', function ($key) use ($sqlite, $defaultUserSettings)
{
	$statement = $sqlite->prepare('SELECT value FROM user_settings WHERE user_id = 1 AND key = ?');
	$statement->execute([$key]);
	$value = $statement->fetchColumn();

	if ($value !== false && $value !== null)
	{
		return $value;
	}

	return $defaultUserSettings[$key] ?? null;
});

$sqlite->createFunction('regexp', function ($pattern, $value)
{
	mb_regex_encoding('UTF-8');
	return (false !== mb_ereg($pattern, $value)) ? 1 : 0;
});

$sqlite->createFunction('ceil', fn($value) => ceil($value));

$pg = new PDO(getenv('DIFFTEST_PGSQL_DSN') ?: 'pgsql:host=victual-pg;port=5432;dbname=victual_full', getenv('DIFFTEST_PGSQL_USER') ?: 'victual', getenv('DIFFTEST_PGSQL_PASSWORD') ?: 'victual');
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Set DIFFTEST_SKIP_COPY=1 to compare against a PostgreSQL database that was populated
// some other way - in particular one filled by bin/victual-db-import, which verifies the
// real migration command rather than this script's own copier.
$skipCopy = (bool)getenv('DIFFTEST_SKIP_COPY');

// 1. Seed SQLite, letting its triggers do whatever they do
if (!$skipCopy)
{
	foreach (array_filter(array_map('trim', explode(";\n", file_get_contents($seedFile)))) as $statement)
	{
		if ($statement !== '')
		{
			$sqlite->exec($statement);
		}
	}

	// 2. Mirror every table into PostgreSQL using the real import command's logic, which
	// disables triggers for the duration. Once triggers exist in the target, copying rows
	// that the source's triggers already shaped would otherwise fire them a second time -
	// cascading deletes and re-deriving values that are already correct.
	// purifyStoredHtml: false. This script's question is whether both engines return the
	// same view rows, so the target has to stay a verbatim copy of the source - a
	// description the purifier rewrote on one side only would read as an engine difference.
	// The real command's purification is covered by richtext-tests.php instead.
	$report = (new Victual\Services\Database\DatabaseImporter(
		$sqlite,
		$pg,
		new Victual\Services\Database\PostgresDialect(),
		fn($m) => null
	))->Import(true, false);

	echo '  copied ' . array_sum($report) . ' rows across ' . count($report) . " tables into PostgreSQL\n\n";
}

// PostgreSQL resolves victual_user_setting() against this table, which DatabaseMigrationService
// fills in a real deployment. Mirror it here so both engines fall back to the same defaults.
if (!empty($defaultUserSettings))
{
	$upsert = $pg->prepare('INSERT INTO user_settings_defaults (key, value) VALUES (?, ?)
		ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value');

	foreach ($defaultUserSettings as $key => $value)
	{
		$upsert->execute([$key, is_bool($value) ? ($value ? '1' : '0') : (string)$value]);
	}
}

// 3. Compare the views. The normalisation rules live in services/ so that this script
// and DatabaseImporter's verification cannot drift apart about what "equal" means.

$failures = 0;

foreach ($views as $view)
{
	try
	{
		$a = $sqlite->query("SELECT * FROM $view")->fetchAll(PDO::FETCH_ASSOC);
		$b = $pg->query("SELECT * FROM $view")->fetchAll(PDO::FETCH_ASSOC);
		// Compare the original six columns on the frozen SQLite model. PostgreSQL's
		// additional role provenance is asserted by rbac-tests.php.
		if ($view === 'uihelper_user_permissions')
		{
			foreach ($b as &$row) unset($row['via_roles']);
			unset($row);
		}
	}
	catch (Exception $ex)
	{
		echo "  FAIL $view -> " . $ex->getMessage() . "\n";
		$failures++;
		continue;
	}

	$ja = array_map(fn($r) => ValueComparison::NormaliseRow($r), $a);
	$jb = array_map(fn($r) => ValueComparison::NormaliseRow($r), $b);

	// Views have no inherent row order, so sort both sides by their normalised form and
	// keep the rows in step with it - the type comparison below is only meaningful when
	// it is looking at the same logical row on each side.
	array_multisort($ja, $a);
	array_multisort($jb, $b);

	// Compare what json_encode would actually emit, not just the numeric value. PDO hands
	// back NUMERIC as a string and BOOLEAN as a bool, so a view can produce numerically
	// equal output that serialises differently - "2.50" instead of 2.5, true instead of 1.
	// Note json_encode(6.0) emits 6, so int-vs-float is NOT a difference on the wire.
	$typeMismatches = [];

	if ($ja === $jb && !empty($a))
	{
		foreach (array_keys($a[0]) as $column)
		{
			foreach ($a as $i => $rowA)
			{
				if (!isset($b[$i]) || !array_key_exists($column, $b[$i])) continue;
				if ($rowA[$column] === null || $b[$i][$column] === null) continue;

				$encodedA = json_encode($rowA[$column]);
				$encodedB = json_encode($b[$i][$column]);

				if (!ValueComparison::ComparableTypes($rowA[$column], $b[$i][$column]))
				{
					$typeMismatches[$column] = gettype($rowA[$column]) . ' ' . $encodedA
						. '  vs  ' . gettype($b[$i][$column]) . ' ' . $encodedB;
					break;
				}
			}
		}
	}

	if ($ja === $jb && empty($typeMismatches))
	{
		echo "  ok   $view (" . count($a) . " rows identical)\n";
		continue;
	}

	if ($ja === $jb)
	{
		$failures++;
		echo "  TYPE $view -- values match but JSON types differ:\n";
		foreach ($typeMismatches as $column => $detail)
		{
			echo "         $column: SQLite $detail\n";
		}
		continue;
	}

	$failures++;
	echo "  DIFF $view -- SQLite " . count($a) . " rows, PostgreSQL " . count($b) . " rows\n";

	foreach (array_slice(array_diff($ja, $jb), 0, 6) as $only) echo "         only SQLite: $only\n";
	foreach (array_slice(array_diff($jb, $ja), 0, 6) as $only) echo "         only PgSQL:  $only\n";
}

echo "\n" . ($failures === 0 ? 'ALL VIEWS IDENTICAL' : "$failures view(s) differ") . "\n";
exit($failures === 0 ? 0 : 1);
