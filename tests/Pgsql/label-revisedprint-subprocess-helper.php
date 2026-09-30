<?php

// Runs a real LabelOperationsService::RevisedPrint() to completion (success or a thrown
// refusal) on its own connection, then commits or rolls back accordingly. No pause is
// coded into this script at all - the pause LabelRevisedPrintNeverDeadlocksWithRetirementTest
// needs comes for free from RevisedPrint()'s own call to LabelIdentityService::Issue(),
// which takes `pg_advisory_xact_lock(109038)` (LabelIdentityService::IMPORT_LOCK) before
// locking the target entity row: the calling test pre-holds that same advisory lock on its
// own "gate" connection, so this subprocess blocks there, on its own, for as long as the
// test wants, using a lock production code already takes rather than a lock invented for
// testability.
//
//   php label-revisedprint-subprocess-helper.php <kind> <targetId> <epoch> <printerId> <templateId>
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

// Stdout is the JSON answer and nothing else: a diagnostic goes to stderr, where the test
// reports it, rather than arriving in front of the answer and reducing json_decode() to null.
ini_set('display_errors', 'stderr');

$kind = (string)($argv[1] ?? '');
$targetId = (int)($argv[2] ?? 0);
$epoch = (int)($argv[3] ?? 0);
$printerId = (int)($argv[4] ?? 0);
$templateId = (int)($argv[5] ?? 0);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
$pdo->exec("SET application_name = 'label-revisedprint-helper'");
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

try
{
	// Bounded the same way every other subprocess in this suite is: if the calling test's
	// gate is never released (it crashed, or a genuine regression left this stuck), this
	// fails loudly after 20s instead of hanging the shared suite lock.
	$pdo->exec("SET statement_timeout = '20s'");
	$pdo->beginTransaction();

	$job = (new LabelOperationsService($pdo))->RevisedPrint($kind, $targetId, $epoch, $printerId, $templateId, null, 'en', 'UTC');

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
