<?php

// Holds a print job "claimed" (current_attempt_id set, still uncommitted) on its own
// connection, pausing there until a coordination advisory lock is released - the "worker
// already claimed this job" half of LabelRetirementCancelsClaimedJobRaceTest's two-connection
// race (issue #516, M16, #487 remediation, maintainer decision D2).
//
// This does the same two writes PrintAttemptService::Claim() does just before it returns
// (PrintAttemptService.php: INSERT INTO print_attempts ...; UPDATE print_jobs SET
// current_attempt_id=...) rather than driving the full Claim() dispatch path (label printer,
// driver, worker capability, artifact readiness) - see
// ImporterPrintJobLockConcurrencyTest's own docblock for why a full Claim() fixture is not
// needed to prove a row-lock property: whether a concurrent retirement's own UPDATE blocks on
// this transaction's held row lock, and re-reads current_attempt_id as no-longer-NULL once
// this one commits, is decided entirely by which of the two backends reaches the print_jobs
// row first - not by how the "claimed" state was produced.
//
//   php label-retirement-claim-hold-subprocess-helper.php <jobId> <workerId> <gateClass> <gateObject>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200, "current_attempt_id": ..., "cancelled_at": ...} on success (the
// row's own state immediately after this process's commit), or
// {"status": 400, "error_message": "..."} on a thrown exception.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;

// Stdout is the JSON answer and nothing else: a diagnostic goes to stderr, where the test
// reports it, rather than arriving in front of the answer and reducing json_decode() to null.
ini_set('display_errors', 'stderr');

$jobId = (int)($argv[1] ?? 0);
$workerId = (int)($argv[2] ?? 0);
$gateClass = (int)($argv[3] ?? 0);
$gateObject = (int)($argv[4] ?? 0);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

try
{
	// A bounded wait, not an unbounded one, on the deliberate pg_advisory_lock() pause below
	// too: if the calling test crashed or failed to release the gate, this fails loudly
	// after 20s instead of hanging the shared suite lock the way a hung concurrency test
	// once did.
	$pdo->exec("SET statement_timeout = '20s'");
	$pdo->beginTransaction();

	$outboxId = (int)$pdo->query('SELECT outbox_id FROM print_jobs WHERE id = ' . $jobId)->fetchColumn();

	$attemptId = (int)$pdo->query(
		'INSERT INTO print_attempts (outbox_id, job_id, attempt_number, worker_id, lease_expires_at, lease_hard_deadline, acknowledged_on) VALUES ('
		. $outboxId . ', ' . $jobId . ", 1, $workerId, CURRENT_TIMESTAMP + interval '1 minute', CURRENT_TIMESTAMP + interval '5 minutes', 'report') RETURNING id"
	)->fetchColumn();

	// The row lock this whole scenario turns on: PrintAttemptService::Claim() takes it with
	// its own `FOR UPDATE OF j`, but a plain UPDATE takes exactly the same row-level lock
	// implicitly, which is all a concurrent retirement's own UPDATE needs to contend with.
	$pdo->exec('UPDATE print_jobs SET current_attempt_id = ' . $attemptId . ', attempts_made = 1 WHERE id = ' . $jobId);

	// Pause here, still holding the row lock in this open transaction, until the calling
	// test's own connection releases the same (classid, objid) pair - the identical
	// session-level advisory-lock gate ImporterPrintJobLockConcurrencyTest and its own helper
	// use for the same purpose.
	$pdo->query('SELECT pg_advisory_lock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();
	$pdo->query('SELECT pg_advisory_unlock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();

	$pdo->commit();

	$row = $pdo->query('SELECT current_attempt_id, cancelled_at FROM print_jobs WHERE id = ' . $jobId)->fetch(PDO::FETCH_ASSOC);
	echo json_encode(['status' => 200, 'current_attempt_id' => $row['current_attempt_id'] !== null ? (int)$row['current_attempt_id'] : null, 'cancelled_at' => $row['cancelled_at']]);
}
catch (\Throwable $ex)
{
	if ($pdo->inTransaction())
	{
		$pdo->rollBack();
	}
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
