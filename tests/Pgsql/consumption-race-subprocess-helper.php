<?php

// One ConsumptionRecipeService call from its own process and connection, so a test can run two
// of them against the same recipe at once (ADR-0040 rule 8).
//
//   php consumption-race-subprocess-helper.php <base64 of {"method": "...", "args": [...], "delay_us": 0}>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/PHPUNIT_DB_NAME/VICTUAL_DATAPATH/VICTUAL_ROOT variables as
// stock-consume-subprocess-helper.php. After the optional delay it prints one JSON line:
// {"ok": true, "result": ...} or {"ok": false, "status": int|null, "code": string|null, "message": string}.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\DatabaseService;

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
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

// Tell the parent the connection is ready, then wait for the release line so both processes start
// their call together; the delay jitters the order they take the first lock in.
echo "ready\n";
flush();
fgets(STDIN);
if (!empty($spec['delay_us']))
{
	usleep((int)$spec['delay_us']);
}

try
{
	$result = ConsumptionRecipeService::GetInstance()->{$spec['method']}(...$spec['args']);
	echo json_encode(['ok' => true, 'result' => $result]);
}
catch (ConsumptionException $exception)
{
	echo json_encode(['ok' => false, 'status' => $exception->status, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);
}
catch (\Throwable $exception)
{
	echo json_encode(['ok' => false, 'status' => null, 'code' => null, 'message' => $exception->getMessage()]);
}
