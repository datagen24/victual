<?php

// Differential test for triggers.
//
// Views are checked by comparing what they return. Triggers cannot be: what they do is
// change other rows. So this starts both engines from an identical table state, applies
// exactly the same statements to each, and then compares every table.
//
// Any difference means a trigger did not fire, fired when it should not have, or computed
// something different. Statements expected to be rejected by a trigger are handled too -
// see "-- @expect-error" below.
//
// Usage: php trigdifftest.php <script.sql> [<script.sql> ...]
//
// A script is plain SQL, one statement per ";" at end of line. A statement preceded by a
// line "-- @expect-error <substring>" must fail on BOTH engines, and both messages must
// contain <substring>; that is how RAISE(ABORT, ...) constraints are checked.

require_once (getenv('VICTUAL_ROOT') ?: '/app') . '/packages/autoload.php';

use Victual\Services\Database\DatabaseImporter;
use Victual\Services\Database\PostgresDialect;
use Victual\Services\Database\ValueComparison;

$scripts = array_slice($argv, 1);

$sqlitePath = getenv('TRIGTEST_SQLITE_PATH') ?: '/data/trigtest.db';
$pristinePath = getenv('TRIGTEST_PRISTINE_PATH') ?: '/scratch/demodata/victual_en.db';

$dialect = new PostgresDialect();
$failures = 0;

if (empty($scripts))
{
	exit('Usage: php trigdifftest.php <script.sql> [<script.sql> ...]' . PHP_EOL);
}

foreach ($scripts as $script)
{
	echo PHP_EOL . '== ' . basename($script) . PHP_EOL;

	// A script that cannot be read must not quietly pass. Reporting "identical state"
	// for a file that was never opened is worse than reporting nothing at all.
	if (!is_readable($script))
	{
		exit('  script not found or unreadable: ' . $script . PHP_EOL);
	}

	// Every script starts from the same pristine state on both sides
	copy($pristinePath, $sqlitePath);

	$sqlite = new PDO('sqlite:' . $sqlitePath);
	$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$pg = new PDO(
		getenv('TRIGTEST_PGSQL_DSN') ?: 'pgsql:host=victual-pg;port=5432;dbname=victual_trig',
		getenv('TRIGTEST_PGSQL_USER') ?: 'victual',
		getenv('TRIGTEST_PGSQL_PASSWORD') ?: 'victual'
	);
	$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	// The session zone the application gives every connection (PostgresDialect::OnConnected):
	// the configured zone, here the named source zone. Without it PostgreSQL reads a literal and
	// derives "today" in the server's default zone while SQLite uses local time, and the two only
	// agree when both happen to be UTC.
	$pg->exec('SET TIME ZONE ' . $pg->quote(getenv('DIFFTEST_SOURCE_ZONE') ?: date_default_timezone_get()));

	// Load the SQLite state into PostgreSQL with triggers off, so both sides begin
	// from the same rows rather than from rows the target's triggers have re-derived
	$importer = new DatabaseImporter($sqlite, $pg, $dialect, fn($m) => null);
	// purifyStoredHtml: false - this phase compares every table across both engines after
	// applying the same trigger scripts, so the target must stay a verbatim copy.
	$importer->Import(true, false);

	$statements = ParseScript(file_get_contents($script));

	if (empty($statements))
	{
		exit('  script contains no statements: ' . $script . PHP_EOL);
	}

	$scriptFailures = 0;

	foreach ($statements as [$sql, $expectError])
	{
		$resultA = RunStatement($sqlite, $sql);
		$resultB = RunStatement($pg, $sql);

		$label = trim(preg_replace('/\s+/', ' ', substr($sql, 0, 68)));

		if ($expectError !== null)
		{
			$aRejected = $resultA !== null;
			$bRejected = $resultB !== null;

			if (!$aRejected || !$bRejected)
			{
				echo "  FAIL  $label\n";
				echo "        expected both engines to reject it; SQLite "
					. ($aRejected ? 'rejected' : 'ACCEPTED') . ', PostgreSQL '
					. ($bRejected ? 'rejected' : 'ACCEPTED') . "\n";
				$scriptFailures++;
				continue;
			}

			$aMatches = stripos($resultA, $expectError) !== false;
			$bMatches = stripos($resultB, $expectError) !== false;

			if (!$aMatches || !$bMatches)
			{
				echo "  FAIL  $label\n";
				echo "        both rejected, but the message should contain \"$expectError\"\n";
				echo "        SQLite:     $resultA\n";
				echo "        PostgreSQL: $resultB\n";
				$scriptFailures++;
				continue;
			}

			echo "  ok    rejected by both: $label\n";
			continue;
		}

		if ($resultA !== null || $resultB !== null)
		{
			echo "  FAIL  $label\n";
			if ($resultA !== null) echo "        SQLite error:     $resultA\n";
			if ($resultB !== null) echo "        PostgreSQL error: $resultB\n";
			$scriptFailures++;
		}
	}

	$scriptFailures += CompareAllTables($sqlite, $pg);

	echo $scriptFailures === 0
		? "  -> identical state after " . count($statements) . " statements\n"
		: "  -> $scriptFailures problem(s)\n";

	$failures += $scriptFailures;
}

echo PHP_EOL . 'Excluded from comparison: the migrations table (per engine by design), '
	. 'row_created_timestamp everywhere (clock), '
	. 'chores.start_date and chores.rescheduled_date, and the dummy id on cache__ tables '
	. '(both accepted differences, see db/pgsql/README.md).' . PHP_EOL;

echo PHP_EOL . ($failures === 0 ? 'TRIGGER BEHAVIOUR IDENTICAL' : "$failures problem(s)") . PHP_EOL;
exit($failures === 0 ? 0 : 1);

/**
 * @return array<array{0:string,1:?string}> [sql, expectedErrorSubstring]
 */
function ParseScript(string $content): array
{
	$statements = [];
	$expect = null;
	$buffer = '';

	foreach (explode("\n", $content) as $line)
	{
		if (preg_match('/^\s*--\s*@expect-error\s+(.+?)\s*$/', $line, $m))
		{
			$expect = $m[1];
			continue;
		}

		if (preg_match('/^\s*--/', $line) && trim($buffer) === '')
		{
			continue;
		}

		$buffer .= $line . "\n";

		if (preg_match('/;\s*$/', $line))
		{
			if (trim($buffer) !== '')
			{
				$statements[] = [trim($buffer), $expect];
			}

			$buffer = '';
			$expect = null;
		}
	}

	if (trim($buffer) !== '')
	{
		$statements[] = [trim($buffer), $expect];
	}

	return $statements;
}

/**
 * @return string|null The error message, or null when the statement succeeded
 */
function RunStatement(PDO $db, string $sql): ?string
{
	try
	{
		$db->exec($sql);
		return null;
	}
	catch (PDOException $ex)
	{
		return trim(preg_replace('/\s+/', ' ', $ex->getMessage()));
	}
}

function CompareAllTables(PDO $sqlite, PDO $pg): int
{
	$pgTables = $pg->query("SELECT table_name FROM information_schema.tables
		WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
		ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

	$sqliteTables = $sqlite->query("SELECT name FROM sqlite_master
		WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

	$problems = 0;

	foreach (array_intersect($pgTables, $sqliteTables) as $table)
	{
		// The migrations table records how each database's schema was built, which is
		// per engine by design: PostgreSQL replaces migrations 0001-0255 with a squashed
		// baseline, and an engine-exclusive migration such as 0256.sqlite.sql applies to
		// one side only. Two fully migrated databases therefore hold different rows here
		// and always will. It is bookkeeping, no trigger touches it, and comparing it
		// would report a difference on every script forever.
		if ($table === 'migrations')
		{
			continue;
		}

		$columns = array_values(array_intersect(
			array_map(fn($c) => $c['name'], $sqlite->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC)),
			$pg->query("SELECT column_name FROM information_schema.columns
				WHERE table_schema = current_schema() AND table_name = " . $pg->quote($table))->fetchAll(PDO::FETCH_COLUMN)
		));

		$columns = array_values(array_diff($columns, IgnoredColumns($table)));

		if (empty($columns))
		{
			continue;
		}

		$list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));

		$rowsA = $sqlite->query('SELECT ' . $list . ' FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
		$pgStatement = $pg->query('SELECT ' . $list . ' FROM "' . $table . '"');
		$rowsB = $pgStatement->fetchAll(PDO::FETCH_ASSOC);

		// Timestamps compared as instants, as difftest.php's views phase does (ADR-0027 open
		// question 3): PostgreSQL's TIMESTAMPTZ columns by the driver's metadata, the SQLite
		// side's wall clocks read in the named source zone.
		$sourceZone = new DateTimeZone(getenv('DIFFTEST_SOURCE_ZONE') ?: date_default_timezone_get());
		foreach (ValueComparison::TimestampColumnsOf($pgStatement)['instants'] as $column)
		{
			foreach ($rowsA as &$row)
			{
				$row[$column] = ValueComparison::NormaliseInstant($row[$column], $sourceZone);
			}
			unset($row);
			foreach ($rowsB as &$row)
			{
				$row[$column] = ValueComparison::NormaliseInstant($row[$column], $sourceZone);
			}
			unset($row);
		}

		if (IsInternalRecipeTable($table))
		{
			$rowsA = ProjectReachableRecipeRows($table, $rowsA, RecipeIdentities($sqlite));
			$rowsB = ProjectReachableRecipeRows($table, $rowsB, RecipeIdentities($pg));
		}

		$a = array_map([ValueComparison::class, 'NormaliseRow'], $rowsA);
		$b = array_map([ValueComparison::class, 'NormaliseRow'], $rowsB);

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
 * The three tables that Victual's meal plan triggers mint hidden rows in.
 */
function IsInternalRecipeTable(string $table): bool
{
	return in_array($table, ['recipes', 'recipes_pos', 'recipes_nestings'], true);
}

/**
 * Every recipe's id mapped to what actually identifies it.
 *
 * For a real recipe that is its id, which is stable and meaningful. For one of the
 * hidden rows the meal plan triggers generate it is the name and type, because the id is
 * an artefact of how many times the generating trigger happened to fire.
 *
 * @return array<int|string, string> Recipe id => identity
 */
function RecipeIdentities(PDO $db): array
{
	$identities = [];

	foreach ($db->query('SELECT id, name, type FROM recipes')->fetchAll(PDO::FETCH_ASSOC) as $recipe)
	{
		$identities[(string)$recipe['id']] = $recipe['type'] === 'normal'
			? 'recipe#' . $recipe['id']
			: 'internal:' . $recipe['type'] . ':' . $recipe['name'];
	}

	return $identities;
}

/**
 * The reachable state of one of the internal recipe tables.
 *
 * This is the single accepted behavioural difference between the engines, and the one
 * place the suite compares something other than the raw rows. db/pgsql/README.md records
 * it: on SQLite a single INSERT INTO meal_plan re-fires update_internal_recipe several
 * times, minting a new internal recipe id on each pass and abandoning the one before, so
 * recipes_pos and recipes_nestings accumulate rows pointing at internal recipes that no
 * longer exist. The port generates each one once. PostgreSQL therefore has strictly
 * fewer unreachable rows, and no application code can see the difference, because a row
 * whose recipe_id resolves to nothing is not reachable from any view or route.
 *
 * So: drop the rows that point at a recipe which no longer exists, and compare the rest
 * by what identifies them rather than by generated ids. What is deliberately NOT relaxed
 * is the content — every reachable row still has to match on product, amount, servings
 * and everything else. If the two engines ever disagree about what a meal plan actually
 * contains, this still fails, which is the whole point of not simply excluding the
 * tables.
 *
 * @param array[] $rows
 * @param array<int|string, string> $identities From RecipeIdentities()
 * @return array[]
 */
function ProjectReachableRecipeRows(string $table, array $rows, array $identities): array
{
	$projected = [];

	foreach ($rows as $row)
	{
		if ($table === 'recipes')
		{
			// A generated recipe's own id is meaningless; a real one's is not.
			if (array_key_exists('id', $row))
			{
				$row['id'] = $identities[(string)$row['id']] ?? ('recipe#' . $row['id']);
			}

			$projected[] = $row;
			continue;
		}

		// recipes_pos and recipes_nestings: unreachable rows are dropped entirely, and
		// the surrogate key goes with them - its value counts how many rows were minted
		// and discarded before it, which is exactly the difference being accepted.
		if (!array_key_exists('recipe_id', $row) || !isset($identities[(string)$row['recipe_id']]))
		{
			continue;
		}

		$row['recipe_id'] = $identities[(string)$row['recipe_id']];

		if (array_key_exists('includes_recipe_id', $row) && $row['includes_recipe_id'] !== null)
		{
			if (!isset($identities[(string)$row['includes_recipe_id']]))
			{
				continue;
			}

			$row['includes_recipe_id'] = $identities[(string)$row['includes_recipe_id']];
		}

		unset($row['id']);

		$projected[] = $row;
	}

	return $projected;
}

/**
 * Columns excluded from the comparison, and why. Everything here is either
 * non-deterministic or a difference recorded in db/pgsql/README.md as accepted - never a
 * convenient way to make a real failure disappear. The run prints what it skipped.
 *
 * @return string[]
 */
function IgnoredColumns(string $table): array
{
	// Set from the clock, so it legitimately differs between the two runs
	$ignored = ['row_created_timestamp'];

	// Accepted difference: SQLite returns the stored string verbatim, so a date-only
	// value comes back without a time where PostgreSQL renders "00:00:00"
	if ($table === 'chores')
	{
		$ignored[] = 'start_date';
		$ignored[] = 'rescheduled_date';
	}

	// Accepted difference: SQLite's INSERT OR REPLACE deletes and reinserts, taking a new
	// id, where PostgreSQL's ON CONFLICT DO UPDATE keeps the existing one. These ids are
	// "dummy" columns that LessQL requires and nothing reads - no view selects them and no
	// cache table is an exposed entity.
	if (str_starts_with($table, 'cache__'))
	{
		$ignored[] = 'id';
	}

	return $ignored;
}

