<?php

// Deletes a product on its own connection - the real production path
// (GenericEntityApiController::DeleteObject()'s own `$row->delete()`, in autocommit, fires
// exactly this statement) - and reports whether it returned. Run concurrently with
// label-retirement-claim-hold-subprocess-helper.php, this is the "a label retires while a
// worker already holds its job" half of LabelRetirementCancelsClaimedJobRaceTest's race
// (issue #516, M16, #487 remediation, maintainer decision D2): retire_product_labels
// (BEFORE DELETE ON products) fires cancel_queued_label_jobs() (migrations/0296.pgsql.sql),
// whose own UPDATE ... WHERE current_attempt_id IS NULL blocks on the row lock the other
// subprocess holds, and is expected to resolve without cancelling the job once that lock is
// released with current_attempt_id already set.
//
//   php label-retirement-delete-subprocess-helper.php <productId>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200} on success, or {"status": 400, "error_message": "..."} on a thrown
// exception.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;

$productId = (int)($argv[1] ?? 0);

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
	// A bounded wait, not an unbounded one: if the row lock this is meant to queue behind is
	// never released (the unfixed-code case, or a genuine defect), this fails loudly after
	// 20s instead of holding the shared suite lock the way a hung concurrency test once did.
	$pdo->exec("SET statement_timeout = '20s'");
	$pdo->exec('DELETE FROM products WHERE id = ' . $productId);
	echo json_encode(['status' => 200]);
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
