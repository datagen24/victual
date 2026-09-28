<?php

// Issues a stock_entry label for the given stock row, with no ambient lock or transaction
// already open on the connection - a fresh connection of its own, exactly like a real
// POST /api/labels/stock_entry/{id}/print request would use.
//
// This is the other half of the race ADR-0033 acceptance prerequisite 1 asks for:
// compact-stock-subprocess-helper.php exercises CompactStockEntries() with no ambient lock;
// this exercises LabelIdentityService::Issue() the same way, so
// tests/Pgsql/StockMaintenanceCompactionTest.php can hold one of them mid-transaction on a
// second raw connection and observe the other genuinely block on - and then lose or win - the
// same row-level lock, rather than asserting a sequenced outcome no concurrency actually
// produced. LabelOperationsService::IssueLocation() is not used here on purpose: printer and
// template resolution happen only after Issue() itself returns, so calling Issue() directly
// isolates exactly the row lock and labels-table write this race is about.
//
//   php issue-stock-label-subprocess-helper.php <stockRowId> <expectedEpoch>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// compact-stock-subprocess-helper.php, attaching to the schema the calling test migrated.
//
// Output, in order:
//   1. One line, immediately after connecting: {"backend_pid": N} - so the calling test can
//      identify this process's PostgreSQL backend and poll pg_blocking_pids() for it without
//      guessing which pid in pg_stat_activity is this one.
//   2. After Issue() returns or throws: {"status": 200, "uid": "..."} or
//      {"status": 400, "error_message": "..."}. A caller-owned transaction wraps the call
//      (LabelService::Transaction()'s own requirement) and is committed on success, rolled
//      back on failure - never left open either way.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

use Victual\Services\Labels\LabelIdentityService;

$stockRowId = (int)($argv[1] ?? 0);
$expectedEpoch = (int)($argv[2] ?? 0);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');

echo json_encode(['backend_pid' => (int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()]) . "\n";
flush();

$pdo->beginTransaction();

try
{
	$uid = (new LabelIdentityService($pdo))->Issue('stock_entry', $stockRowId, $expectedEpoch);
	$pdo->commit();
	echo json_encode(['status' => 200, 'uid' => $uid]) . "\n";
}
catch (\Throwable $ex)
{
	$pdo->rollBack();
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]) . "\n";
}
