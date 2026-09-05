<?php

// Differential test for the migration path itself.
//
// The suite's other phases all start from a PostgreSQL database that was populated by
// copying an already-migrated SQLite one, so none of them has ever looked at what
// bin/victual-migrate produces on its own. That blind spot hid a real defect: the
// PostgreSQL baseline is schema only, while a third of the migrations it stands in for
// also insert rows, so a freshly migrated PostgreSQL database had no admin user, no
// permission hierarchy and no quantity units - and reported success.
//
// So this compares two databases that have had nothing done to them but migrate.
// db/pgsql/README.md claims the baseline is "equivalent to the state SQLite reaches
// after migrations 0001-0255"; this is that sentence written as a test.
//
// Usage: php migratedifftest.php
//
//   MIGRATEDIFF_SQLITE_PATH   a freshly migrated SQLite database
//   MIGRATEDIFF_PGSQL_DSN     a freshly migrated PostgreSQL database
//   MIGRATEDIFF_PGSQL_USER, MIGRATEDIFF_PGSQL_PASSWORD

require_once (getenv('VICTUAL_ROOT') ?: '/app') . '/packages/autoload.php';

use Victual\Services\Database\DatabaseImporter;
use Victual\Services\Database\ValueComparison;

/**
 * Tables an @engine-exclusive migration created on PostgreSQL and deliberately not on
 * SQLite, so that the two engines legitimately hold different table sets.
 *
 * "files" (migrations/0258.pgsql.sql) is the first. It holds uploaded files as BYTEA when
 * FILE_STORAGE is "database", and ConfigurationValidator refuses that setting on any
 * driver but pgsql - so a SQLite counterpart would be a table nothing could ever read.
 * Adding it here rather than letting the table set comparison pass by accident: this
 * phase exists to make a missing table loud, and an exemption it does not know about is a
 * missing table wearing a different hat. See db/pgsql/README.md.
 */
const ENGINE_EXCLUSIVE_TABLES = ['files', 'roles', 'role_permissions', 'user_roles'];

$sqlitePath = getenv('MIGRATEDIFF_SQLITE_PATH');
$pgsqlDsn = getenv('MIGRATEDIFF_PGSQL_DSN');

if (empty($sqlitePath) || empty($pgsqlDsn))
{
	exit('MIGRATEDIFF_SQLITE_PATH and MIGRATEDIFF_PGSQL_DSN must both be set' . PHP_EOL);
}

if (!is_readable($sqlitePath))
{
	exit('  SQLite database not found or unreadable: ' . $sqlitePath . PHP_EOL);
}

$sqlite = new PDO('sqlite:' . $sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Not NULL_EMPTY_STRING, which the application sets on its own connections: the internal
// meal plan section really is named with an empty string, and folding that to NULL here
// would make the two sides differ over something neither engine did.
$sqlite->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

$pg = new PDO($pgsqlDsn, getenv('MIGRATEDIFF_PGSQL_USER') ?: null, getenv('MIGRATEDIFF_PGSQL_PASSWORD') ?: null);
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pg->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

echo '== freshly migrated databases' . PHP_EOL;

$problems = CompareTableSets($sqlite, $pg) + CompareRows($sqlite, $pg);

echo PHP_EOL . 'Excluded from comparison: the migrations table (per engine by design), the '
	. 'engine-exclusive tables (' . implode(', ', ENGINE_EXCLUSIVE_TABLES) . '), '
	. 'row_created_timestamp everywhere (clock) and users.password (hashed with a fresh '
	. 'salt per installation).' . PHP_EOL;

echo PHP_EOL . ($problems === 0 ? 'MIGRATED STATE IDENTICAL' : "$problems problem(s)") . PHP_EOL;
exit($problems === 0 ? 0 : 1);

/**
 * Reports tables that only one engine has. A missing table is the loudest possible
 * version of the defect this phase exists for, and it would otherwise be invisible:
 * comparing only the tables both sides have makes a table nobody created look like
 * agreement.
 *
 * Which is exactly why the exemptions below are a list rather than a skip: every name in
 * one is a table somebody decided one engine should not have, and the decision is written
 * down here next to the check it silences.
 */
function CompareTableSets(PDO $sqlite, PDO $pg): int
{
	$problems = 0;

	// Two families of PostgreSQL-only table. TARGET_ONLY_TABLES is what the baseline owns
	// outright - user_settings_defaults resolves settings in SQL where SQLite uses a PHP
	// callback, and system_db_changed_time replaces SQLite's file mtime; same list
	// DatabaseImporter works from. ENGINE_EXCLUSIVE_TABLES is what an @engine-exclusive
	// migration created on one engine on purpose.
	foreach (array_diff(PgTables($pg), SqliteTables($sqlite), DatabaseImporter::TARGET_ONLY_TABLES, ENGINE_EXCLUSIVE_TABLES) as $table)
	{
		echo "  DIFF  table $table exists on PostgreSQL only\n";
		$problems++;
	}

	foreach (array_diff(SqliteTables($sqlite), PgTables($pg)) as $table)
	{
		echo "  DIFF  table $table exists on SQLite only\n";
		$problems++;
	}

	return $problems;
}

/**
 * Compares the contents of every table both engines have, column by column.
 */
function CompareRows(PDO $sqlite, PDO $pg): int
{
	$problems = 0;

	foreach (array_intersect(PgTables($pg), SqliteTables($sqlite)) as $table)
	{
		// Per engine by design - PostgreSQL replaces migrations 0001-0255 with the
		// baseline, and 0256.sqlite.sql applies to one side only, so two fully migrated
		// databases hold different rows here and always will.
		if ($table === 'migrations')
		{
			continue;
		}

		$columns = array_values(array_diff(
			array_intersect(
				array_map(fn($c) => $c['name'], $sqlite->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC)),
				$pg->query("SELECT column_name FROM information_schema.columns
					WHERE table_schema = current_schema() AND table_name = " . $pg->quote($table))->fetchAll(PDO::FETCH_COLUMN)
			),
			IgnoredColumns($table)
		));

		if (empty($columns))
		{
			continue;
		}

		$list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));

		$a = array_map([ValueComparison::class, 'NormaliseRow'], $sqlite->query('SELECT ' . $list . ' FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC));
		// Wave 3a adds six read leaves and their upgrade backfill only on PostgreSQL.
		// Compare the entire frozen permission model; the new model has rbac-tests.php.
		$where = $table === 'permission_hierarchy' ? ' WHERE id <= 30' : ($table === 'user_permissions' ? ' WHERE permission_id <= 30' : '');
		$b = array_map([ValueComparison::class, 'NormaliseRow'], $pg->query('SELECT ' . $list . ' FROM "' . $table . '"' . $where)->fetchAll(PDO::FETCH_ASSOC));

		sort($a);
		sort($b);

		if ($a === $b)
		{
			continue;
		}

		$problems++;
		echo "  DIFF  table $table -- SQLite " . count($a) . " rows, PostgreSQL " . count($b) . " rows\n";

		foreach (array_slice(array_diff($a, $b), 0, 4) as $only) echo "        only SQLite: $only\n";
		foreach (array_slice(array_diff($b, $a), 0, 4) as $only) echo "        only PgSQL:  $only\n";
	}

	return $problems;
}

/**
 * Columns excluded from the comparison, and why. Both entries are non-deterministic
 * rather than inconvenient.
 *
 * @return string[]
 */
function IgnoredColumns(string $table): array
{
	// Set from the clock, so it legitimately differs between the two migration runs
	$ignored = ['row_created_timestamp'];

	// The admin account's password is hashed per installation, salt and all, rather than
	// shipped as a fixed digest - so the same password produces a different string on
	// every run, on one engine as much as on two.
	if ($table === 'users')
	{
		$ignored[] = 'password';
	}

	return $ignored;
}

/**
 * @return string[]
 */
function PgTables(PDO $pg): array
{
	return $pg->query("SELECT table_name FROM information_schema.tables
		WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
		ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * @return string[]
 */
function SqliteTables(PDO $sqlite): array
{
	return $sqlite->query("SELECT name FROM sqlite_master
		WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}
