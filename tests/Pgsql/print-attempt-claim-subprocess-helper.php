<?php

// Calls PrintAttemptService::Claim($workerId) directly, in its own transaction, against the
// schema the calling test migrated - the "concurrent worker" half of
// ImporterPrintJobLockConcurrencyTest's two-connection race. A process of its own, like
// compact-stock-subprocess-helper.php, so it genuinely runs on a separate connection while
// the import subprocess sits paused mid-transaction on its own.
//
//   php print-attempt-claim-subprocess-helper.php <workerId>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200, "claimed": [{"job_id": ..., "attempt_id": ...}, ...]} - exactly
// the jobs Claim() actually dispatched, or {"status": 400, "error_message": "..."} on a
// thrown exception.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;
use Victual\Services\Labels\PrintAttemptService;

$workerId = (int)($argv[1] ?? 0);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

try
{
	$pdo->beginTransaction();
	// PrintAttemptService::Claim() itself issues the FOR UPDATE OF j SKIP LOCKED select that
	// this whole scenario is about - see PrintAttemptService.php:50. Its ROW SHARE table
	// lock on print_jobs is what DatabaseImporter's LOCK TABLE ... ACCESS EXCLUSIVE conflicts
	// with, so this call blocks here, on this connection, for exactly as long as that
	// conflict lasts.
	$claimed = (new PrintAttemptService($pdo))->Claim($workerId);
	$pdo->commit();
	echo json_encode(['status' => 200, 'claimed' => array_map(
		fn($claim) => ['job_id' => (int)$claim['attempt']['job_id'], 'attempt_id' => (int)$claim['attempt']['id']],
		$claimed
	)]);
}
catch (\Throwable $ex)
{
	if ($pdo->inTransaction())
	{
		$pdo->rollBack();
	}
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
