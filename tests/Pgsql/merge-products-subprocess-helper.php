<?php

// One StockService::MergeProducts() call from its own process and connection, so a test can hold a
// row lock on a second connection and look at what the merge holds while it waits.
//
//   php merge-products-subprocess-helper.php <base64 of {"keep": int, "remove": int, "lock_timeout_ms": int}>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/PHPUNIT_DB_NAME/VICTUAL_DATAPATH/VICTUAL_ROOT variables as
// consumption-refill-race-subprocess-helper.php. Prints "ready <backend pid>", waits for a line on stdin,
// then prints one JSON line: {"ok": true} or {"ok": false, "message": string}.

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

$spec = json_decode(base64_decode($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
$pdo->exec('SET lock_timeout = ' . (int)$spec['lock_timeout_ms']);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

echo 'ready ' . (int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn() . "\n";
flush();
fgets(STDIN);

try
{
	StockService::GetInstance()->MergeProducts((int)$spec['keep'], (int)$spec['remove']);
	echo json_encode(['ok' => true]);
}
catch (\Throwable $exception)
{
	echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
