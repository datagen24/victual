<?php
// SPIKE ONLY. Probe 4, second half: run against the container copy of StockService.php that
// run.sh patched with evidence/usedDate.patch. Shows a booking dated two days back.
define('VICTUAL_USER_ID', 9000);
require __DIR__ . '/lib.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService as S;

$pdo = B41Env::create();
$db = DatabaseService::GetInstance();
$stock = S::GetInstance();
$loc = b41_location($pdo, 'B41 Shelf');
$out = ['probe' => 'used-date', 'environment' => b41_env_info($pdo)];
$m = new ReflectionMethod(S::class, 'ConsumeProduct');
$out['signature'] = ['parameters' => array_map(fn($p) => $p->getName(), $m->getParameters()), 'count' => $m->getNumberOfParameters(), 'required' => $m->getNumberOfRequiredParameters()];
$out['service_file_sha256'] = hash_file('sha256', '/app/services/StockService.php');
$out['patched'] = in_array('usedDate', $out['signature']['parameters'], true);

$twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
$today = date('Y-m-d');
$rows = fn(string $tx) => $pdo->query("SELECT id, product_id, amount, used_date, undone FROM stock_log WHERE transaction_id='$tx' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$p = b41_product($pdo, 'dated', $loc); b41_add($p, 2, $loc); usleep(1500); b41_add($p, 5, $loc);
$cases = [];
// whole-take and split branches in one call: 2 + 1.5 of a 2-unit and a 5-unit row
$tx = null; $stock->ConsumeProduct($p, 3.5, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx, false, false, $twoDaysAgo);
$cases['dated_two_days_back'] = ['requested' => $twoDaysAgo, 'bookings' => $rows($tx)];
$tx2 = null; $stock->ConsumeProduct($p, 1, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx2);
$cases['default_is_today'] = ['server_today' => $today, 'bookings' => $rows($tx2)];
foreach (['2026-02-30', '09/10/2026', '2026-10-9', ''] as $bad) {
    try { $t = null; $stock->ConsumeProduct($p, 0.5, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $t, false, false, $bad); $cases["invalid '$bad'"] = 'accepted'; }
    catch (Throwable $e) { $cases["invalid '$bad'"] = get_class($e) . ': ' . $e->getMessage(); }
}
$out['cases'] = $cases;
// the dated booking undoes like any other, and the lineage stays clean
$before = b41_lineage_violations($pdo);
$stock->UndoTransaction($tx2);
$stock->UndoTransaction($tx);
$out['undo_of_dated_transaction'] = ['bookings_after' => $rows($tx), 'stock_after' => b41_stock_amount($pdo, $p), 'expected_stock' => 7.0, 'lineage_violations_before' => $before, 'lineage_violations_after' => b41_lineage_violations($pdo)];
// a recipe-style multi-line consume with one date
$a = b41_product($pdo, 'dated-a', $loc); $b = b41_product($pdo, 'dated-b', $loc); b41_add($a, 3, $loc); usleep(1500); b41_add($b, 3, $loc);
$t = null;
$db->InTransaction(function () use ($stock, $a, $b, &$t, $twoDaysAgo) {
    foreach ([$a, $b] as $x) { $stock->ConsumeProduct($x, 1, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $t, false, false, $twoDaysAgo); }
});
$out['two_line_transaction_one_date'] = $rows($t);
B41Env::destroy();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
