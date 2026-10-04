<?php
// SPIKE ONLY (ADR-0037). Child process for the two-connection tests in model-probe.php.
// php conc-child.php <mode> <json args>   -> one JSON object on stdout.
define('VICTUAL_ROOT_PATH', '/app');
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);
ini_set('display_errors', 'stderr');

use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService;

[$script, $mode, $json] = $argv;
$args = json_decode($json, true);
$pdo = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
    getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET search_path TO ' . getenv('ADR37_SCHEMA') . ', public');
$pdo->exec("SET statement_timeout = '20s'");
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

$t0 = microtime(true);
$waited = fn() => round(microtime(true) - $t0, 2);
try {
    if ($mode === 'issue') {                      // the real label issuance: import lock, epoch check, row lock
        $pdo->beginTransaction();
        $epoch = (int)$pdo->query('SELECT epoch FROM label_import_state')->fetchColumn();
        $uid = (new LabelIdentityService($pdo))->Issue('stock_entry', (int)$args['row'], $epoch);
        $pdo->commit();
        echo json_encode(['result' => 'issuance returned a uid', 'uid' => $uid, 'waited_s' => $waited()]);
    } elseif ($mode === 'bump' || $mode === 'bump_hold') {   // the importer's first two steps: import lock, epoch bump
        $pdo->beginTransaction();
        LabelIdentityService::LockImport($pdo);
        $pdo->exec('UPDATE label_import_state SET epoch = epoch + 1 WHERE id = 1');
        if ($mode === 'bump_hold') { usleep((int)($args['hold_s'] * 1e6)); }
        $pdo->commit();
        echo json_encode(['result' => 'epoch bumped', 'waited_s' => $waited()]);
    } elseif ($mode === 'undo') {                 // the real StockService undo, plain (no revival hook)
        StockService::GetInstance()->UndoBooking((int)$args['booking']);
        echo json_encode(['result' => 'accepted', 'waited_s' => $waited()]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['result' => 'refused', 'message' => $e->getMessage(), 'waited_s' => $waited()]);
}
