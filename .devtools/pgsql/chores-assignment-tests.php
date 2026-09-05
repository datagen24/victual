<?php

// What does the next-execution assignment do when there is nobody to assign?
//
//   php chores-assignment-tests.php
//
// Runs against whichever engine VICTUAL_DATAPATH's config.php selects, so the runner can
// point it at SQLite and then at PostgreSQL. See run-tests.sh.
//
// Like the rollback phase this asks one engine at a time rather than comparing two. The
// question is what ChoresService::CalculateNextExecutionAssignment() does with an empty
// assignment group, and the answer has no cross-engine dimension for three of the four
// assignment types - they walk an array PHP built. The fourth, "who-least-did-first",
// reads chores_execution_users_statistics, so what an empty group means there IS a
// per-engine answer, and that is the reason this runs on both.
//
// Why it exists. `random` picked with array_rand($assignedUsers) in an `else` branch, so a
// group of nobody fell into it; array_rand([]) is a ValueError, \Error is deliberately not
// caught (docs/plans/11-api-error-handling.md), and POST /api/chores/{id}/execute answered
//
//   500 {"error_message":"array_rand(): Argument #1 ($array) must not be empty"}
//
// for a chore whose assignment_type is "random" and whose assignment_config is empty.
//
// `in-alphabetical-order` reached the same empty array one branch down, at an array_shift()
// whose null it then read ->id off. That one is a warning rather than an \Error, so it
// answered with the null it should have and logged a line about it on every request instead
// of failing - which is why the phase asserts the value and not merely that nothing threw.
// The same is true of the last-execution row, which is null for every chore that has never
// been executed.
//
// An empty group is not only a chore nobody was ever assigned to, which is why this is a
// runtime guard rather than a validation at write time: assignment_config naming a user
// that has since been deleted resolves to the same nothing, and no check on the write that
// created the chore can see a deletion that happens afterwards. Both shapes are cases here.
//
// Every empty case is paired with a populated control. A guard that returned null for
// everything would satisfy the empty cases alone, and would be a worse bug than the one
// being fixed - so the controls are what make the empty assertions mean anything.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: VICTUAL_ROOT_PATH . '/data');
}

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

if (file_exists(VICTUAL_DATAPATH . '/config.php'))
{
	require_once VICTUAL_DATAPATH . '/config.php';
}

require_once VICTUAL_ROOT_PATH . '/config-dist.php';

if (!defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

use Victual\Services\ChoresService;
use Victual\Services\DatabaseService;

$db = DatabaseService::GetInstance();
$pdo = $db->GetDbConnectionRaw();
$engine = $db->GetDialect()->GetName();
$chores = ChoresService::GetInstance();

$failures = 0;

// Ids well above anything a migrated database or the base fixture holds, so this phase
// cannot collide with rows another phase left behind in the same database.
const USER_ALICE = 9001;
const USER_BOB = 9002;
const USER_GONE = 9003;

/**
 * Two users with display names that sort the way the alphabetical cases assume, plus a
 * third that is deleted again immediately - the "assignment_config names somebody who no
 * longer exists" case needs an id that was real and is not.
 */
function SeedUsers(PDO $pdo): void
{
	foreach ([[USER_ALICE, 'alice', 'Alice'], [USER_BOB, 'bob', 'Bob'], [USER_GONE, 'gone', 'Gone']] as $user)
	{
		$statement = $pdo->prepare('INSERT INTO users (id, username, first_name, password) VALUES (?, ?, ?, ?)');
		$statement->execute([$user[0], $user[1], $user[2], 'x']);
	}

	$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([USER_GONE]);
}

/**
 * Creates a chore and returns its id.
 *
 * $assignmentConfig is passed through as given - null and '' are two different ways for a
 * database to hold "nobody", and both reach the service.
 */
function MakeChore(PDO $pdo, int $id, string $assignmentType, $assignmentConfig): int
{
	$pdo->prepare('DELETE FROM chores WHERE id = ?')->execute([$id]);

	$statement = $pdo->prepare('INSERT INTO chores (id, name, period_type, period_days, assignment_type, assignment_config, active)
		VALUES (?, ?, \'manually\', 1, ?, ?, 1)');
	$statement->execute([$id, 'Assignment Test Chore ' . $id, $assignmentType, $assignmentConfig]);

	return $id;
}

/** Records an execution, which is what who-least-did-first counts and the others read back. */
function TrackExecution(PDO $pdo, int $choreId, int $userId, string $trackedTime): void
{
	$statement = $pdo->prepare('INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone)
		VALUES (?, ?, ?, 0)');
	$statement->execute([$choreId, $trackedTime, $userId]);
}

/** The stored assignment, read back through PDO rather than through the row object that wrote it. */
function AssignedUser(PDO $pdo, int $choreId)
{
	$statement = $pdo->prepare('SELECT next_execution_assigned_to_user_id FROM chores WHERE id = ?');
	$statement->execute([$choreId]);

	$value = $statement->fetchColumn();

	return $value === false || $value === null ? null : (int)$value;
}

/**
 * One case: calculate, then check what was stored against what was expected.
 *
 * $expected is either null, an int, or an array of acceptable ints - the random strategy
 * has no single right answer for a group of more than one, and asserting a particular
 * member of the group would be asserting the seed of PHP's RNG.
 *
 * A throw is a failure whatever $expected says: the defect this phase exists for was an
 * exception escaping to the caller, so "it threw" can never be a pass here.
 */
function Case_(string $label, int $choreId, $expected): void
{
	global $chores, $pdo, $failures;

	try
	{
		$chores->CalculateNextExecutionAssignment($choreId);
	}
	catch (\Throwable $ex)
	{
		printf("  FAIL   %-46s threw %s: %s\n", $label, get_class($ex), $ex->getMessage());
		$failures++;

		return;
	}

	$actual = AssignedUser($pdo, $choreId);
	$acceptable = is_array($expected) ? $expected : [$expected];

	if (!in_array($actual, $acceptable, true))
	{
		printf("  FAIL   %-46s assigned %s, expected %s\n", $label,
			var_export($actual, true),
			implode(' or ', array_map(function ($value) { return var_export($value, true); }, $acceptable)));
		$failures++;

		return;
	}

	printf("  ok     %-46s assigned %s\n", $label, var_export($actual, true));
}

echo 'Next-execution assignment with an empty group (' . $engine . ")\n\n";

SeedUsers($pdo);

// --- Nobody to assign -------------------------------------------------------------
//
// Three shapes of "nobody" against all four assignment types. Every one of them is null,
// which is the value CHORE_ASSIGNMENT_TYPE_NO_ASSIGNMENT stores: no strategy can invent a
// user, so "nobody" is the answer rather than a failure.

$emptyConfigs = [
	'no assignment_config' => null,
	'empty assignment_config' => '',
	'only a deleted user' => (string)USER_GONE
];

$choreId = 9100;

foreach ($emptyConfigs as $shape => $config)
{
	foreach ([
		ChoresService::CHORE_ASSIGNMENT_TYPE_RANDOM,
		ChoresService::CHORE_ASSIGNMENT_TYPE_IN_ALPHABETICAL_ORDER,
		ChoresService::CHORE_ASSIGNMENT_TYPE_WHO_LEAST_DID_FIRST,
		ChoresService::CHORE_ASSIGNMENT_TYPE_NO_ASSIGNMENT
	] as $type)
	{
		$id = MakeChore($pdo, $choreId++, $type, $config);
		Case_($type . ', ' . $shape, $id, null);
	}
}

echo "\n";

// --- The controls -----------------------------------------------------------------

$id = MakeChore($pdo, 9200, ChoresService::CHORE_ASSIGNMENT_TYPE_RANDOM, (string)USER_ALICE);
Case_('random, one user', $id, USER_ALICE);

$id = MakeChore($pdo, 9201, ChoresService::CHORE_ASSIGNMENT_TYPE_RANDOM, USER_ALICE . ',' . USER_BOB);
Case_('random, two users', $id, [USER_ALICE, USER_BOB]);

// Alice sorts before Bob, so the user after the one who last did it is Bob.
$id = MakeChore($pdo, 9202, ChoresService::CHORE_ASSIGNMENT_TYPE_IN_ALPHABETICAL_ORDER, USER_ALICE . ',' . USER_BOB);
TrackExecution($pdo, $id, USER_ALICE, '2026-02-03 08:00:00');
Case_('in-alphabetical-order, after the first', $id, USER_BOB);

// And after the last one in the list it wraps to the first - the branch whose array_shift()
// was the second empty-group hazard.
$id = MakeChore($pdo, 9203, ChoresService::CHORE_ASSIGNMENT_TYPE_IN_ALPHABETICAL_ORDER, USER_ALICE . ',' . USER_BOB);
TrackExecution($pdo, $id, USER_BOB, '2026-02-03 08:00:00');
Case_('in-alphabetical-order, wrapping round', $id, USER_ALICE);

// Bob has done it once and Alice not at all, so the least is Alice.
$id = MakeChore($pdo, 9204, ChoresService::CHORE_ASSIGNMENT_TYPE_WHO_LEAST_DID_FIRST, USER_ALICE . ',' . USER_BOB);
TrackExecution($pdo, $id, USER_BOB, '2026-02-03 08:00:00');
Case_('who-least-did-first, two users', $id, USER_ALICE);

// A manual reschedule outranks every strategy, including on a chore with an empty group -
// the early return above the strategies, which the empty-group guard must not shadow.
$id = MakeChore($pdo, 9205, ChoresService::CHORE_ASSIGNMENT_TYPE_RANDOM, null);
$pdo->prepare('UPDATE chores SET rescheduled_next_execution_assigned_to_user_id = ? WHERE id = ?')
	->execute([USER_BOB, $id]);
Case_('random, empty group but rescheduled', $id, USER_BOB);

echo "\n";

if ($failures === 0)
{
	echo "EVERY ASSIGNMENT CASE ANSWERED\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
