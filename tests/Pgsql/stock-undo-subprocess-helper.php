<?php

// Runs one stock operation through the real StockService on a connection of its own, for the
// two-connection races of ADR-0037 (StockLabelRevivalRaceTest):
//
//   php stock-undo-subprocess-helper.php undo <bookingId>
//   php stock-undo-subprocess-helper.php consume <productId> <amount>
//
// The connection's application_name is VICTUAL_TEST_APPLICATION_NAME (default
// 'stock-undo-helper'), so the calling test can see this exact backend wait on a lock.
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// compact-stock-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200, "label_revival": {...}|null} on success, or {"status": 400,
// "error_message": "...", "sqlstate": "..."|null} on a thrown exception - "sqlstate" is set
// only for a \PDOException, so a deadlock (40P01) is told apart from an application refusal.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

// Stdout is the JSON answer and nothing else: a diagnostic goes to stderr, where the test
// reports it, rather than arriving in front of the answer and reducing json_decode() to null.
ini_set('display_errors', 'stderr');

$mode = (string)($argv[1] ?? '');

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
$pdo->prepare('SELECT set_config(\'application_name\', ?, false)')->execute([getenv('VICTUAL_TEST_APPLICATION_NAME') ?: 'stock-undo-helper']);
// Bounded: a lock that is never released fails this run after 20 s instead of hanging the suite.
$pdo->exec("SET statement_timeout = '20s'");
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

try
{
	if ($mode === 'undo')
	{
		$summary = StockService::GetInstance()->UndoBooking((int)$argv[2]);
		echo json_encode(['status' => 200, 'label_revival' => $summary]);
	}
	elseif ($mode === 'consume')
	{
		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct((int)$argv[2], (float)$argv[3], false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);
		echo json_encode(['status' => 200, 'transaction_id' => $transactionId]);
	}
	else
	{
		echo json_encode(['status' => 400, 'error_message' => "Unknown mode '$mode'", 'sqlstate' => null]);
	}
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage(), 'sqlstate' => $ex instanceof \PDOException ? (string)$ex->getCode() : null]);
}
