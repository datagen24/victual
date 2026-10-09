<?php

// One call into ConsumptionEventService, ConsumptionMappingService or StockService from its own
// process and connection, so a test can run several against the same events at once (ADR-0041
// rules 5 and 6).
//
//   php consumption-event-race-subprocess-helper.php <base64 of {"service": "events|mappings|stock", "method": "...", "args": [...], "delay_us": 0}>
//
// Same environment and protocol as consumption-race-subprocess-helper.php: it prints "ready", waits for
// a line on stdin, then prints one JSON line: {"ok": true, "result": ...} or
// {"ok": false, "status": int|null, "code": string|null, "message": string}.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\ConsumptionEventService;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionMappingService;
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
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

$service = match ($spec['service'])
{
	'events' => ConsumptionEventService::GetInstance(),
	'mappings' => ConsumptionMappingService::GetInstance(),
	'stock' => StockService::GetInstance(),
};

echo "ready\n";
flush();
fgets(STDIN);
if (!empty($spec['delay_us']))
{
	usleep((int)$spec['delay_us']);
}

try
{
	echo json_encode(['ok' => true, 'result' => $service->{$spec['method']}(...$spec['args'])]);
}
catch (ConsumptionException $exception)
{
	echo json_encode(['ok' => false, 'status' => $exception->status, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);
}
catch (\Throwable $exception)
{
	echo json_encode(['ok' => false, 'status' => null, 'code' => null, 'message' => $exception->getMessage()]);
}
