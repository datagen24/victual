<?php

// Calls StockService::ConsumeProduct() for one product from its own process and connection, so
// a test can issue a booking while another connection (here, a paused maintenance run) holds
// the product's lock (ADR-0036 worked example 7, first case).
//
//   php stock-consume-subprocess-helper.php <productId> <amount>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// compact-stock-subprocess-helper.php. Prints {"backend_pid": ...} first, then one JSON line:
// {"status": 200, "transaction_id": ...} or {"status": 400, "error_message": ...}.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

ini_set('display_errors', 'stderr');

$productId = (int)$argv[1];
$amount = (float)$argv[2];

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

echo json_encode(['backend_pid' => (int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()]) . "\n";
flush();

try
{
	$transactionId = StockService::GetInstance()->ConsumeProduct($productId, $amount, false, StockService::TRANSACTION_TYPE_CONSUME);
	echo json_encode(['status' => 200, 'transaction_id' => $transactionId]);
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
