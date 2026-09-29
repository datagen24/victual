<?php

// Deletes one row of a labelled entity table on its own connection - the real production
// path for a retirement (GenericEntityApiController::DeleteObject()'s own `$row->delete()`,
// in autocommit, fires exactly this statement for every kind but stock entries) - and
// reports whether it returned. Run concurrently with another subprocess holding a
// conflicting lock, this is the "a label retires while something else is mid-transaction"
// half of two different races (issue #516, M16, #487 remediation, maintainer decision D2):
//
//   - LabelRetirementCancelsClaimedJobRaceTest: a worker already holds the job
//     (label-retirement-claim-hold-subprocess-helper.php's row lock on print_jobs).
//     retire_product_labels (BEFORE DELETE ON products) fires cancel_queued_label_jobs()
//     (migrations/0296.pgsql.sql), whose own UPDATE ... WHERE current_attempt_id IS NULL
//     blocks on that row lock, and is expected to resolve without cancelling the job once
//     the lock is released with current_attempt_id already set.
//   - LabelRetirementRacesReprintTest: a reprint is mid-transaction
//     (label-reprint-hold-subprocess-helper.php's FOR SHARE lock on the labels row).
//     retire_location_labels' own UPDATE on that same row (migrations/0296.pgsql.sql) blocks
//     on it, and is expected to resolve - and cancel the job the reprint just committed -
//     once that lock is released.
//
//   php label-retirement-delete-subprocess-helper.php <table> <id>
//
// <table> is one of products, locations, recipes, chores, batteries, stock - the six tables
// a retirement trigger fires from (migrations/0269.pgsql.sql, 0283.pgsql.php) - checked
// against that fixed list rather than interpolated as given.
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

const ALLOWED_TABLES = ['products', 'locations', 'recipes', 'chores', 'batteries', 'stock'];

$table = (string)($argv[1] ?? '');
$id = (int)($argv[2] ?? 0);

if (!in_array($table, ALLOWED_TABLES, true)) {
	echo json_encode(['status' => 400, 'error_message' => "Unknown table '$table'"]);
	exit;
}

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
// A distinguishing application_name, so a calling test can identify this exact backend in
// pg_stat_activity - by name, not by guessing at "any" waiter of some lock type - when it
// polls for this specific connection to be genuinely blocked.
$pdo->exec("SET application_name = 'label-retirement-delete-helper'");
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

try
{
	// A bounded wait, not an unbounded one: if the row lock this is meant to queue behind is
	// never released (the unfixed-code case, or a genuine defect), this fails loudly after
	// 20s instead of holding the shared suite lock the way a hung concurrency test once did.
	$pdo->exec("SET statement_timeout = '20s'");
	$pdo->exec('DELETE FROM ' . $table . ' WHERE id = ' . $id);
	echo json_encode(['status' => 200]);
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
