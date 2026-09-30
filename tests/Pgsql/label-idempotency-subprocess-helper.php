<?php

// Attempts IdempotencyService::Begin() for the same principal/operation/key as the caller's
// still-open reservation, so the two race on label_idempotency_keys' UNIQUE constraint the
// way two concurrent replays of the same idempotency key would.
//
//   php label-idempotency-subprocess-helper.php <userId> <operation> <key> <requestJson>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated, and sets
// application_name so the caller can watch pg_stat_activity for this process entering a lock
// wait before it lets its own transaction commit.
//
// Output: the Begin() result as JSON on success (e.g. {"replay":true,"row":{...}}), or
// {"error": "<exception class>", "code": "<SQLSTATE or exception code>", "message": "..."}
// if it threw.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);

use Victual\Services\Labels\IdempotencyService;

// Stdout is the JSON answer and nothing else: a diagnostic goes to stderr, where the test
// reports it, rather than arriving in front of the answer and reducing json_decode() to null.
ini_set('display_errors', 'stderr');

[, $userId, $operation, $key, $requestJson] = $argv;
$request = json_decode($requestJson, true, 512, JSON_THROW_ON_ERROR);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
$pdo->exec("SET application_name = 'label_idempotency_child'");
$pdo->exec("SET statement_timeout = '8s'");

$pdo->beginTransaction();
try {
	$result = (new IdempotencyService($pdo))->Begin((int)$userId, $operation, $key, $request);
	echo json_encode($result);
} catch (\Throwable $error) {
	echo json_encode(['error' => get_class($error), 'code' => $error->getCode(), 'message' => $error->getMessage()]);
} finally {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
}
