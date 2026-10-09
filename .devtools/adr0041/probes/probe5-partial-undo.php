<?php
// SPIKE ONLY. Probe 5: partial undo reachability and the effective state of ADR-0041 rule 8.
define('VICTUAL_USER_ID', 9000);
require __DIR__ . '/lib.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService as S;

$pdo = B41Env::create();
$db = DatabaseService::GetInstance();
$stock = S::GetInstance();
$loc = b41_location($pdo, 'B41 Shelf');
$out = ['probe' => 'partial-undo', 'environment' => b41_env_info($pdo)];

/** ADR rule 8: derived on read from stock_log.undone of the event's bookings. */
function effective_state(PDO $pdo, string $tx): string
{
    $r = $pdo->query("SELECT count(*) AS n, count(*) FILTER (WHERE undone=1) AS u FROM stock_log WHERE transaction_id='$tx' AND transaction_type='consume'")->fetch(PDO::FETCH_ASSOC);
    if ((int)$r['n'] === 0) { return 'no_bookings'; }
    if ((int)$r['u'] === 0) { return 'booked'; }
    return (int)$r['u'] === (int)$r['n'] ? 'undone' : 'needs_review/partially_undone';
}
function rows(PDO $pdo, string $tx): array
{
    return array_map(fn($r) => ['id' => (int)$r['id'], 'product_id' => (int)$r['product_id'], 'amount' => (float)$r['amount'], 'undone' => (int)$r['undone'], 'correlation_id' => $r['correlation_id']],
        $pdo->query("SELECT id,product_id,amount,undone,correlation_id FROM stock_log WHERE transaction_id='$tx' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
}
function attempt(callable $f): array
{
    try { $f(); return ['result' => 'accepted']; } catch (Throwable $e) { return ['result' => 'refused', 'class' => get_class($e), 'message' => $e->getMessage()]; }
}
function book(array $lines): string
{
    $db = DatabaseService::GetInstance(); $stock = S::GetInstance(); $tx = null;
    $db->InTransaction(function () use ($stock, $lines, &$tx) {
        foreach ($lines as [$p, $q]) { $stock->ConsumeProduct($p, $q, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx); }
    });
    return $tx;
}
function mk(PDO $pdo, int $loc, string $name, float ...$lots): int
{
    $p = b41_product($pdo, $name, $loc);
    foreach ($lots as $q) { b41_add($p, $q, $loc); usleep(1500); }
    return $p;
}

// 1. Two-line consume, undo one booking, then UndoTransaction on the remainder.
$p1 = mk($pdo, $loc, 'u1', 10); $p2 = mk($pdo, $loc, 'u2', 10);
$tx = book([[$p1, 2], [$p2, 3]]);
$steps = [];
$snap = function (string $label) use (&$steps, $pdo, $tx, $p1, $p2) {
    $steps[] = ['step' => $label, 'effective_state' => effective_state($pdo, $tx), 'stock' => [b41_stock_amount($pdo, $p1), b41_stock_amount($pdo, $p2)],
        'bookings' => rows($pdo, $tx), 'lineage_violations' => b41_lineage_violations($pdo)];
};
$snap('booked');
$b1 = (int)$pdo->query("SELECT min(id) FROM stock_log WHERE transaction_id='$tx'")->fetchColumn();
$a = attempt(fn() => $stock->UndoBooking($b1)); $snap('after UndoBooking(line 1): ' . $a['result']);
$a = attempt(fn() => $stock->UndoTransaction($tx)); $snap('after UndoTransaction on the remainder: ' . $a['result']);
$steps[] = ['step' => 'UndoTransaction on the fully undone transaction'] + attempt(fn() => $stock->UndoTransaction($tx));
$steps[] = ['step' => 'UndoBooking on an undone booking'] + attempt(fn() => $stock->UndoBooking($b1));
$out['1_two_line_consume'] = ['transaction_id' => $tx, 'consume_rows_have_null_correlation_id' => (int)$pdo->query("SELECT count(*) FROM stock_log WHERE transaction_id='$tx' AND correlation_id IS NOT NULL")->fetchColumn() === 0, 'steps' => $steps];

// 2. One line served by two lots (two bookings under the same transaction).
$p3 = mk($pdo, $loc, 'u3', 3, 4); $p4 = mk($pdo, $loc, 'u4', 10);
$tx2 = book([[$p3, 5], [$p4, 1]]);
$ids = $pdo->query("SELECT id FROM stock_log WHERE transaction_id='$tx2' AND product_id=$p3 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$st = [['step' => 'booked', 'effective_state' => effective_state($pdo, $tx2), 'bookings_for_line_1' => count($ids)]];
$a = attempt(fn() => $stock->UndoBooking((int)$ids[0])); $st[] = ['step' => 'undo the older booking of line 1 first (the two bookings come from different lots)'] + $a + ['effective_state' => effective_state($pdo, $tx2)];
$a = attempt(fn() => $stock->UndoBooking((int)$ids[1])); $st[] = ['step' => 'undo the newer booking of line 1'] + $a + ['effective_state' => effective_state($pdo, $tx2)];
$a = attempt(fn() => $stock->UndoBooking((int)$ids[0])); $st[] = ['step' => 'undo the older booking again (already undone)'] + $a + ['effective_state' => effective_state($pdo, $tx2), 'lineage_violations' => b41_lineage_violations($pdo)];
$out['2_one_line_two_bookings'] = $st;

// 3. UndoTransaction is all-or-none; a single booking can still be undone when the transaction cannot.
$p5 = mk($pdo, $loc, 'u5', 10); $p6 = mk($pdo, $loc, 'u6', 10);
$tx3 = book([[$p5, 2], [$p6, 2]]);
book([[$p5, 1]]); // a later booking from the same lot blocks undoing line 1
$before = rows($pdo, $tx3); $h = b41_ledger_hash($pdo, [$p5, $p6]);
$a = attempt(fn() => $stock->UndoTransaction($tx3));
$st = [['step' => 'UndoTransaction while line 1 has a later dependent booking'] + $a + ['ledger_unchanged' => b41_ledger_hash($pdo, [$p5, $p6]) === $h, 'effective_state' => effective_state($pdo, $tx3)]];
$b2 = (int)$pdo->query("SELECT max(id) FROM stock_log WHERE transaction_id='$tx3'")->fetchColumn();
$a = attempt(fn() => $stock->UndoBooking($b2)); $st[] = ['step' => 'UndoBooking(line 2) alone'] + $a + ['effective_state' => effective_state($pdo, $tx3), 'lineage_violations' => b41_lineage_violations($pdo)];
$a = attempt(fn() => $stock->UndoTransaction($tx3)); $st[] = ['step' => 'UndoTransaction again (line 1 still blocked)'] + $a + ['effective_state' => effective_state($pdo, $tx3)];
$out['3_all_or_none_and_stuck_partial'] = $st;

$out['lineage_violations_after_all'] = b41_lineage_violations($pdo);
B41Env::destroy();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
