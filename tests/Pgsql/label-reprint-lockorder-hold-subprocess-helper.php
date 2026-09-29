<?php

// Mirrors LabelOperationsService::Reprint()'s current two lock-acquiring statements, in
// its current order, with a deliberate pause between them - proving (or, with the order
// swapped for manual verification, disproving) that Reprint()'s lock order can never
// deadlock against a concurrent retirement of the same label. This is a literal SQL mirror
// rather than a call to Reprint() itself, because - unlike RevisedPrint() (see
// LabelRevisedPrintNeverDeadlocksWithRetirementTest, which pauses on Issue()'s own existing
// LabelIdentityService::IMPORT_LOCK) - Reprint() has no lock or other blockable resource of
// its own between its two conflicting statements to pause on; a pause has to be inserted
// here instead.
//
// Reprint()'s current order (LabelOperationsService.php): AssertLabelLive()'s own
// `SELECT ... FOR SHARE` on `labels`, then `SELECT * FROM print_jobs ... FOR UPDATE` on the
// source job. A concurrent retirement's own order (migrations/0296.pgsql.sql): the
// retire_*_labels trigger's `UPDATE labels` first, then cancel_queued_label_jobs()'s own
// `UPDATE print_jobs` second - reaching a still-queued source job for the same label the
// same way it reaches any other queued job. Same order on both sides (labels, then
// print_jobs) - two transactions taking the same two locks in the same order can never
// deadlock; the worst that happens is one waits for the other, all the way to commit.
//
//   php label-reprint-lockorder-hold-subprocess-helper.php <labelUid> <sourceJobId> <gateClass> <gateObject>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200} on success, or {"status": 400, "error_message": "...",
// "sqlstate": "..."} on a thrown exception - "sqlstate" is set only for a \PDOException, so
// the calling test can tell a genuine SQLSTATE 40P01 deadlock apart from anything else.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;

$labelUid = (string)($argv[1] ?? '');
$sourceJobId = (int)($argv[2] ?? 0);
$gateClass = (int)($argv[3] ?? 0);
$gateObject = (int)($argv[4] ?? 0);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
$pdo->exec("SET application_name = 'label-reprint-lockorder-holder'");
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

try
{
	// Bounded the same way every other subprocess in this suite is: a pause that never
	// resumes fails loudly after 20s instead of hanging the shared suite lock.
	$pdo->exec("SET statement_timeout = '20s'");
	$pdo->beginTransaction();

	// Statement 1, matching AssertLabelLive()'s own query exactly.
	$pdo->query('SELECT retired_at FROM labels WHERE uid = ' . $pdo->quote($labelUid) . ' FOR SHARE')->fetchColumn();

	// Pause here, still holding the labels lock, until the calling test releases the gate.
	$pdo->query('SELECT pg_advisory_lock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();
	$pdo->query('SELECT pg_advisory_unlock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();

	// Statement 2, matching Reprint()'s own query exactly.
	$pdo->query('SELECT * FROM print_jobs WHERE id = ' . $sourceJobId . ' FOR UPDATE')->fetch();

	$pdo->commit();

	echo json_encode(['status' => 200]);
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
