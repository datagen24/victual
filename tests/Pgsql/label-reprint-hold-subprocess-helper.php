<?php

// Runs a real LabelOperationsService::Reprint() to completion - including creating its
// print_jobs row - on its own connection, then pauses there, still holding the `FOR SHARE`
// lock AssertLabelLive()'s own read of `labels` took (LabelOperationsService.php), until a
// coordination advisory lock is released. This is the "a reprint is mid-transaction" half of
// LabelRetirementRacesReprintTest's race (issue #516, M16, #487 remediation, maintainer
// decision D2): without that lock, a concurrent retirement could read the label as still
// live, create this same job, and never see it to cancel - "a reprint racing a retirement
// can commit a new queued job after the cancel has already run", breaking D2's "permanently
// ineligible to print" for a job that exists but was never cancelled.
//
// Reprint() is deliberately the operation this test exercises, not RevisedPrint(): unlike
// RevisedPrint(), which calls LabelIdentityService::Issue() and so also takes a `FOR UPDATE`
// on the target *entity* row (locations.id here) - already enough, on its own, to serialise
// against a concurrent DELETE of that same row - Reprint() never touches the entity table at
// all. It locks only the source print_jobs row (`FOR UPDATE`) and reads the label
// (AssertLabelLive()). So Reprint() is the one operation whose race protection depends
// entirely on AssertLabelLive()'s own `FOR SHARE` lock, with nothing else to fall back on.
//
// A gate connection that pre-holds the *source* print_jobs row FOR UPDATE externally (rather
// than relying on this script's own advisory-lock pause) forces Reprint()'s own internal
// `SELECT * FROM print_jobs WHERE id=? FOR UPDATE` to block there naturally - the technique
// LabelReprintNeverDeadlocksWithRetirementTest uses, passing a gateClass/gateObject nobody
// else holds so this script's own pause passes straight through.
//
//   php label-reprint-hold-subprocess-helper.php <sourceJobId> <printerId> <gateClass> <gateObject>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200, "job_id": ...} on success, or {"status": 400, "error_message": "...",
// "sqlstate": "..."} on a thrown exception - "sqlstate" is set only for a \PDOException, so
// the calling test can tell a genuine SQLSTATE 40P01 deadlock apart from an ordinary
// application-level refusal.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelOperationsService;

$sourceJobId = (int)($argv[1] ?? 0);
$printerId = (int)($argv[2] ?? 0);
$gateClass = (int)($argv[3] ?? 0);
$gateObject = (int)($argv[4] ?? 0);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
// A distinguishing application_name, so a calling test can identify this exact backend in
// pg_stat_activity by name, the same reasoning label-retirement-delete-subprocess-helper.php's
// own application_name follows.
$pdo->exec("SET application_name = 'label-reprint-hold-helper'");
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

try
{
	// Bounded the same way every other subprocess in this suite is: a pause that never
	// resumes (the calling test crashed, or failed to release the gate) fails loudly after
	// 20s instead of hanging the shared suite lock.
	$pdo->exec("SET statement_timeout = '20s'");
	$pdo->beginTransaction();

	$job = (new LabelOperationsService($pdo))->Reprint($sourceJobId, $printerId);

	// Pause here, still holding the FOR SHARE lock AssertLabelLive()'s own read of `labels`
	// took, until the calling test's own connection releases the same (classid, objid) pair
	// - the identical session-level advisory-lock gate every other subprocess in this suite
	// uses for the same purpose.
	$pdo->query('SELECT pg_advisory_lock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();
	$pdo->query('SELECT pg_advisory_unlock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();

	$pdo->commit();

	echo json_encode(['status' => 200, 'job_id' => (int)$job['id']]);
}
catch (\Throwable $ex)
{
	if ($pdo->inTransaction())
	{
		$pdo->rollBack();
	}

	$result = ['status' => 400, 'error_message' => $ex->getMessage()];

	if ($ex instanceof \PDOException)
	{
		$result['sqlstate'] = $ex->errorInfo[0] ?? $ex->getCode();
	}

	echo json_encode($result);
}
