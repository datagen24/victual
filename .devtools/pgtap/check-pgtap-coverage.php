<?php

// Checks that .devtools/pgtap/README.md's list says what the migrations actually made.
//
//   php check-pgtap-coverage.php
//
// ADR-0025 decision 5: tier 2 (pgTAP) is measured by completeness rather than by a
// percentage, since pcov measures PHP lines and nothing else and cannot see a trigger.
// The rule is a named list, kept in this directory's README, of every function and
// trigger the fork's migrations create; this is the enforcement of it, the shape of
// .devtools/pgsql/check-migrations.php.
//
// Migrations up to the SQLite baseline (0001-0255) are out of scope for the same reason
// check-migrations.php excludes them from its own per-engine check: PostgreSQL never
// runs them, it loads the squashed baseline in db/pgsql/baseline/ instead.

require_once (getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2)) . '/packages/autoload.php';

use Victual\Services\DatabaseMigrationService;

$migrationsPath = (getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2)) . '/migrations';
$readmePath = __DIR__ . '/README.md';

/**
 * Every function or trigger name a migration creates, as name => [migration => file].
 *
 * A line is only counted when the CREATE keyword actually starts the statement, not
 * when it appears inside a comment describing one - migrations/0279.pgsql.sql has both
 * "CREATE OR REPLACE FUNCTION" and "CREATE TRIGGER" in a `--` comment, which a plain
 * grep would miscount as a third definition.
 *
 * @return array<string, array{migration: int, file: string}>
 */
function CreatedByMigrations(string $migrationsPath): array
{
	$created = [];

	foreach (new FilesystemIterator($migrationsPath) as $file)
	{
		$name = $file->getBasename();
		$matches = [];

		if (!preg_match('/^(\d+)\./', $name, $matches))
		{
			continue;
		}

		$number = (int)$matches[1];

		if ($number <= DatabaseMigrationService::BASELINE_MIGRATION_ID)
		{
			continue;
		}

		foreach (file($file->getPathname()) as $line)
		{
			$trimmed = ltrim($line);

			if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//'))
			{
				continue;
			}

			if (preg_match('/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+([A-Za-z0-9_]+)/i', $line, $found)
				|| preg_match('/^\s*CREATE\s+TRIGGER\s+([A-Za-z0-9_]+)/i', $line, $found))
			{
				$created[$found[1]] = ['migration' => $number, 'file' => $name];
			}
		}
	}

	return $created;
}

/**
 * The README's "Name" column, as a flat set of the names it lists. A cell like
 * "`trg_locations_check_parent` (trigger `check_location_parent`)" names two
 * identifiers at once - the function and the trigger bound to it - so every
 * backtick-quoted token in the first column is taken, not only the first one.
 *
 * @return array<string, bool>
 */
function ListedNames(string $readmePath): array
{
	$listed = [];

	foreach (file($readmePath) as $line)
	{
		if (!str_starts_with(ltrim($line), '|'))
		{
			continue;
		}

		$cells = explode('|', $line);
		$firstColumn = $cells[1] ?? '';

		if (preg_match_all('/`([^`]+)`/', $firstColumn, $matches))
		{
			foreach ($matches[1] as $name)
			{
				$listed[$name] = true;
			}
		}
	}

	return $listed;
}

$created = CreatedByMigrations($migrationsPath);
$listed = ListedNames($readmePath);

$problems = [];

foreach ($created as $name => $where)
{
	if (!isset($listed[$name]))
	{
		$problems[] = $where['file'] . ' (migration ' . $where['migration'] . ') creates "' . $name
			. '", which .devtools/pgtap/README.md\'s list does not name. Add a row naming the pgTAP '
			. 'file that exercises it - there is no waiver for an untested one, unlike '
			. 'check-migrations.php\'s reserved-hole allowance, because decision 5 draws no such line.';
	}
}

echo 'Checked ' . count($created) . ' function/trigger definition(s) above the baseline ('
	. DatabaseMigrationService::BASELINE_MIGRATION_ID . ') against ' . count($listed) . ' listed name(s).' . PHP_EOL;

if (empty($problems))
{
	echo "PGTAP COVERAGE LIST OK\n";
	exit(0);
}

foreach ($problems as $problem)
{
	echo '  ' . $problem . "\n";
}

echo "\n" . count($problems) . " pgtap coverage problem(s)\n";
exit(1);
