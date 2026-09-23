<?php

// Calls StockService::CompactStockEntries() directly against the given product, with no
// ambient transaction or lock already open on the connection.
//
// This is the one call shape none of StockService's own callers (AddProduct,
// EditStockEntry, WeighLocation) ever produce: every one of them locks the product before
// calling CompactStockEntries(), so driving it through any of their HTTP endpoints always
// runs it nested inside an already-held lock, where the pre-lock read
// tests/Pgsql/StockConcurrencyTest.php's compaction scenario is about cannot go stale -
// the ambient lock already protects it. CompactStockEntries() is public and its
// $productId = null form sweeps every product in one call with no caller-supplied lock at
// all, so this helper exercises exactly that unlocked entry point: a maintenance sweep, or
// any future caller that does not lock first.
//
//   php compact-stock-subprocess-helper.php <productId>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables
// as request-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200} on success, or {"status": 400, "error_message": "..."} on a
// thrown exception.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

$productId = (int)($argv[1] ?? 0);

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
	StockService::GetInstance()->CompactStockEntries($productId);
	echo json_encode(['status' => 200]);
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
