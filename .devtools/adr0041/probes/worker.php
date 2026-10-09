<?php
// SPIKE ONLY. Long-lived child for probes 1 and 3. Reads one JSON command per line on stdin,
// answers with one JSON line on stdout. Each worker is its own process with its own database
// connection and its own VICTUAL_USER_ID, running the real StockService.
define('VICTUAL_USER_ID', (int)getenv('B41_USER'));
require __DIR__ . '/lib.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService as S;

$pdo = B41Env::attach(getenv('B41_SCHEMA'));
$workerId = (int)getenv('B41_WORKER');
$rss = static function (): int { preg_match('/VmRSS:\s+(\d+)/', (string)@file_get_contents('/proc/self/status'), $m); return (int)($m[1] ?? 0); };
echo json_encode(['ready' => true, 'pid' => getmypid(), 'rss_kb' => $rss()]) . "\n";
fflush(STDOUT);

$db = DatabaseService::GetInstance();
$stock = S::GetInstance();

while (($line = fgets(STDIN)) !== false) {
    $cmd = json_decode($line, true);
    if (!is_array($cmd) || ($cmd['cmd'] ?? '') === 'quit') { break; }
    mt_srand(((int)($cmd['seed'] ?? 0)) * 1000 + $workerId);
    if (isset($cmd['barrier'])) {
        $pdo->query('SELECT pg_advisory_lock_shared(' . B41_BARRIER_CLASS . ', ' . (int)$cmd['barrier'] . ')');
        $pdo->query('SELECT pg_advisory_unlock_shared(' . B41_BARRIER_CLASS . ', ' . (int)$cmd['barrier'] . ')');
    }
    b41_jit($cmd, 'start');
    $t0 = microtime(true);
    $out = ['worker' => $workerId, 'op' => $cmd['op'] ?? null];
    try {
        switch ($cmd['op']) {
            case 'submit':
                $out += b41_submit($cmd['req']);
                break;
            case 'direct': // ConsumeProduct per line; optionally in one outer transaction with the products locked ascending first
                $lines = $cmd['lines'];
                $run = function () use ($cmd, $lines, $stock, $db) {
                    if (!empty($cmd['prelock'])) { $db->LockProductsStock(array_map(fn($l) => $l[0], $lines)); }
                    $tx = null;
                    foreach ($lines as $l) {
                        $stock->ConsumeProduct($l[0], (float)$l[1], false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx);
                        b41_jit($cmd, 'between_lines');
                    }
                    return $tx;
                };
                $out['transaction_id'] = !empty($cmd['outer']) ? $db->InTransaction($run) : $run();
                $out['ok'] = true;
                break;
            case 'undo_tx':
                $stock->UndoTransaction($cmd['transaction_id']);
                $out['ok'] = true;
                break;
            case 'undo_booking':
                $stock->UndoBooking((int)$cmd['booking_id']);
                $out['ok'] = true;
                break;
            case 'correction': // ADR rule 6: undo the old transaction and book the new one in one database transaction
                $out['transaction_id'] = $db->InTransaction(function () use ($cmd, $db, $stock) {
                    $p = $db->GetDbConnectionRaw();
                    $st = $p->prepare('SELECT id,transaction_id FROM b41_events WHERE id=? FOR UPDATE');
                    $st->execute([$cmd['event_id']]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $p->query('SELECT id FROM b41_recipes WHERE id=' . (int)($cmd['recipe_id'] ?? 1) . ' ' . (($cmd['recipe_lock'] ?? 'share') === 'update' ? 'FOR UPDATE' : 'FOR SHARE'));
                    b41_jit($cmd, 'after_lock');
                    $lines = $cmd['lines'];
                    if (!empty($cmd['prelock'])) {
                        $old = $p->query("SELECT DISTINCT product_id FROM stock_log WHERE transaction_id='" . $row['transaction_id'] . "' AND undone=0")->fetchAll(PDO::FETCH_COLUMN);
                        $db->LockProductsStock(array_merge($old, array_map(fn($l) => $l[0], $lines)));
                    }
                    $stock->UndoTransaction($row['transaction_id']);
                    b41_jit($cmd, 'between_steps');
                    $tx = null;
                    foreach ($lines as $l) {
                        $stock->ConsumeProduct($l[0], (float)$l[1], false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx);
                        b41_jit($cmd, 'between_lines');
                    }
                    $p->prepare('UPDATE b41_events SET transaction_id=?, revision=revision+1 WHERE id=?')->execute([$tx, $row['id']]);
                    return $tx;
                });
                $out['ok'] = true;
                break;
            default:
                throw new RuntimeException('unknown op ' . $cmd['op']);
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = b41_err($e);
    }
    $out['ms'] = round((microtime(true) - $t0) * 1000, 1);
    $out['rss_kb'] = $rss();
    echo json_encode($out) . "\n";
    fflush(STDOUT);
}
