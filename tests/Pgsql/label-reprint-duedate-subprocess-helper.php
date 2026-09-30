<?php

// Runs one StockService booking with VICTUAL_FEATURE_FLAG_LABELS defined true before
// config.php loads - the constant that gates StockService's own auto-reprint check
// (issue #523). PHP constants cannot be redefined and PHPUnit runs a whole test class in
// one process (which boots with the flag off, the installation default - see
// StockPagesTest::testRendersWithTheFlagOff()), so the one call this regression needs runs
// in a process of its own, exactly like tests/Pgsql/stockpages-subprocess-helper.php.
// Everything else - fixture setup and every assertion - runs in the calling test's own
// process, against the same schema, the way LabelServicesTest drives the label services
// directly without any HTTP layer.
//
//   php label-reprint-duedate-subprocess-helper.php <base64 of a JSON spec>
//
// Spec: {"schema": "phpunit_...", "operation": "open", "productId": N, "amount": F,
// "specificStockEntryId": "..." (optional, default "default")}, or
// {"schema": "phpunit_...", "operation": "transfer", "productId": N, "amount": F,
// "locationIdFrom": N, "locationIdTo": N, "specificStockEntryId": "..." (optional)}
//
// Output: one JSON object, {"status": 200, "transaction_id": "..."} or
// {"status": 400, "error_message": "..."}. A caller-owned transaction is not needed here:
// StockService::OpenProduct() opens and commits (or rolls back) its own.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

$spec = json_decode(base64_decode($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

// Before config.php/config-dist.php, which is the whole reason this is a separate process.
define('VICTUAL_FEATURE_FLAG_LABELS', true);

require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_USER_ID', 9000);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'label-reprint-duedate-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

// Stdout is the JSON answer and nothing else: a diagnostic goes to stderr, where the test
// reports it, rather than arriving in front of the answer and reducing json_decode() to null.
ini_set('display_errors', 'stderr');

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . $spec['schema'] . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

try
{
	if ($spec['operation'] === 'open')
	{
		$transactionId = StockService::GetInstance()->OpenProduct(
			(int)$spec['productId'],
			(float)$spec['amount'],
			$spec['specificStockEntryId'] ?? 'default'
		);
	}
	elseif ($spec['operation'] === 'transfer')
	{
		$transactionId = StockService::GetInstance()->TransferProduct(
			(int)$spec['productId'],
			(float)$spec['amount'],
			(int)$spec['locationIdFrom'],
			(int)$spec['locationIdTo'],
			$spec['specificStockEntryId'] ?? 'default'
		);
	}
	else
	{
		throw new \InvalidArgumentException('Unknown operation "' . $spec['operation'] . '"');
	}

	echo json_encode(['status' => 200, 'transaction_id' => $transactionId]);
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
