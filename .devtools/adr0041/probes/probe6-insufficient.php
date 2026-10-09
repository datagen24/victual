<?php
// SPIKE ONLY. Probe 6: can an insufficient-stock refusal be told apart from other refusals without
// matching the message, and does a pre-check under the product lock agree with ConsumeProduct()?
//   B41_SEED (default 460001), B41_TRIALS (default 600)
define('VICTUAL_USER_ID', 9000);
require __DIR__ . '/lib.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService as S;

$pdo = B41Env::create();
$db = DatabaseService::GetInstance();
$stock = S::GetInstance();
$seed = (int)(getenv('B41_SEED') ?: 460001);
$cases = (int)(getenv('B41_TRIALS') ?: (getenv('B41_QUICK') ? 40 : 600));
$out = ['probe' => 'typed-insufficient-stock', 'environment' => b41_env_info($pdo), 'run_seed' => $seed, 'tolerance' => S::AMOUNT_TOLERANCE];
$locs = [b41_location($pdo, 'B41 A'), b41_location($pdo, 'B41 B'), b41_location($pdo, 'B41 C')];
$empty = b41_location($pdo, 'B41 Empty');
$child = (int)$pdo->query("INSERT INTO locations(name, parent_location_id) VALUES ('B41 A child', {$locs[0]}) RETURNING id")->fetchColumn();

function consume(int $p, float $q, ?int $loc = null, $entry = 'default', string $type = S::TRANSACTION_TYPE_CONSUME): ?string
{
    $tx = null;
    S::GetInstance()->ConsumeProduct($p, $q, false, $type, $entry, null, $loc, $tx);
    return $tx;
}
function refusal(callable $f): array
{
    try { $f(); return ['outcome' => 'accepted']; }
    catch (Throwable $e) { return ['outcome' => 'refused', 'class' => get_class($e), 'code' => $e->getCode(), 'message' => $e->getMessage()]; }
}

// --- A. what ConsumeProduct() throws today, by cause -------------------------------------------------
$p = b41_product($pdo, 'stocked', $locs[0]); b41_add($p, 5, $locs[0]);
$inactive = b41_product($pdo, 'inactive', $locs[0]); b41_add($inactive, 5, $locs[0]); $pdo->exec("UPDATE products SET active=0 WHERE id=$inactive");
$other = b41_product($pdo, 'two-places', $locs[0]); b41_add($other, 5, $locs[0]); usleep(1500); b41_add($other, 10, $locs[1]);
$measured = b41_product($pdo, 'measured', $locs[0]);
$table = [];
$table['amount is zero'] = refusal(fn() => consume($p, 0.0));
$table['amount is negative'] = refusal(fn() => consume($p, -1.0));
$table['amount is NaN'] = refusal(fn() => consume($p, NAN));
$table['product does not exist'] = refusal(fn() => consume(99999999, 1.0));
$table['product is inactive'] = refusal(fn() => consume($inactive, 1.0));
$table['location does not exist'] = refusal(fn() => consume($p, 1.0, 99999999));
$table['insufficient: product-wide'] = refusal(fn() => consume($p, 6.0));
$table['insufficient: location scope, another location holds enough'] = refusal(fn() => consume($other, 7.0, $locs[0]));
$table['insufficient: location holds none'] = refusal(fn() => consume($other, 1.0, $locs[2]));
$table['insufficient: named stock entry too small'] = refusal(function () use ($pdo, $other) {
    $sid = $pdo->query("SELECT stock_id FROM stock WHERE product_id=$other ORDER BY id LIMIT 1")->fetchColumn();
    consume($other, 6.0, null, $sid);
});
$table['transaction type not valid'] = refusal(fn() => consume($p, 1.0, null, 'default', 'purchase'));
// A measured open container: a fractional consume is deferred and then refused with its own message.
try {
    $pdo->exec("INSERT INTO stock(product_id,amount,stock_id,purchased_date,location_id,open,opened_date,opened_amount,opened_qu_id,opened_tare,opened_measured_at,best_before_date) VALUES ($measured,1,'b41-measured','2026-10-01'," . $locs[0] . ",1,'2026-10-02',250,2,100,now(),'2999-12-31')");
    $table['fraction of a measured container'] = refusal(fn() => consume($measured, 0.5));
} catch (Throwable $e) { $table['fraction of a measured container'] = ['outcome' => 'fixture_failed', 'message' => $e->getMessage()]; }
// A negative stock row (a defect elsewhere): may be refused by a CHECK before it exists.
try {
    $neg = b41_product($pdo, 'negative-row', $locs[0]); b41_add($neg, 2, $locs[0]);
    $pdo->exec("UPDATE stock SET amount=-1 WHERE product_id=$neg");
    $table['negative stock row'] = refusal(fn() => consume($neg, 0.5));
} catch (Throwable $e) { $table['negative stock row'] = ['outcome' => 'not_constructible', 'sqlstate' => $e instanceof PDOException ? ($e->errorInfo[0] ?? '') : '', 'message' => mb_substr($e->getMessage(), 0, 140)]; }
$groups = [];
foreach ($table as $cause => $r) { if (($r['outcome'] ?? '') === 'refused') { $groups[$r['class'] . ' / code ' . $r['code']][] = $cause; } }
$out['A_thrown_today'] = ['by_cause' => $table, 'causes_sharing_one_class_and_code' => $groups,
    'typed_exception_classes_in_services' => 'see grep in RESULTS.md'];

// --- B. pre-check prototype ----------------------------------------------------------------------------
class B41InsufficientStock extends \Exception {}

/** Pre-check under the product lock, in the scope ConsumeProduct() uses (no substitution). */
function precheck(int $productId, float $amount, ?int $locationId, bool $scoped = true): void
{
    $svc = S::GetInstance();
    $entries = ($locationId === null || !$scoped) ? $svc->GetProductStockEntries($productId, false, false) : $svc->GetProductStockEntriesForLocation($productId, $locationId, false, false);
    $sum = 0.0;
    foreach ($entries as $e) { $sum += (float)$e->amount; }
    if (S::CompareAmounts($amount, $sum) > 0) { throw new B41InsufficientStock("need $amount, hold $sum"); }
}
function verdict(int $p, float $q, ?int $loc, bool $scoped): string
{
    $db = DatabaseService::GetInstance();
    try { $db->InTransaction(function () use ($db, $p, $q, $loc, $scoped) { $db->LockProductStock($p); precheck($p, $q, $loc, $scoped); }); return 'sufficient'; }
    catch (B41InsufficientStock) { return 'insufficient'; }
}
function actual(int $p, float $q, ?int $loc): string
{
    try { consume($p, $q, $loc); return 'accepted'; }
    catch (\Exception $e) { return str_contains($e->getMessage(), 'cannot be > current stock amount') ? 'refused_insufficient' : 'refused_other:' . mb_substr($e->getMessage(), 0, 70); }
}

mt_srand($seed);
$matrix = []; $disagree = []; $naive = [];
$bump = function (array &$m, string $k) { $m[$k] = ($m[$k] ?? 0) + 1; };
for ($i = 1; $i <= $cases; $i++) {
    $caseSeed = $seed + $i; mt_srand($caseSeed);
    $pid = b41_product($pdo, "pc-$i", $locs[0]);
    $where = [];
    foreach (array_merge($locs, [$child]) as $l) {
        if (mt_rand(0, 2) > 0) { $q = round(mt_rand(1, 40) / 4, 2); b41_add($pid, $q, $l); $where[$l] = ($where[$l] ?? 0) + $q; usleep(1200); }
    }
    $scopeChoices = [null, $locs[0], $locs[1], $locs[2], $empty, $child];
    $scope = $scopeChoices[mt_rand(0, count($scopeChoices) - 1)];
    $inScope = $scope === null ? array_sum($where) : ($where[$scope] ?? 0);
    $mode = mt_rand(0, 5);
    $amount = match ($mode) {
        0 => $inScope,                       // exactly the scope's stock
        1 => $inScope + 1e-7,                // above the tolerance
        2 => $inScope + 5e-10,               // inside the tolerance
        3 => $inScope + 1e-13,               // float noise
        4 => max(0.25, $inScope - 0.25),     // comfortably below
        default => $inScope + round(mt_rand(1, 20) / 4, 2),
    };
    if ($amount <= 1e-9) { $amount = 0.25; }
    $pre = verdict($pid, $amount, $scope, true);
    $naivePre = verdict($pid, $amount, $scope, false);
    $act = actual($pid, $amount, $scope);
    $key = "precheck=$pre actual=$act";
    $bump($matrix, $key);
    $agree = ($pre === 'sufficient' && $act === 'accepted') || ($pre === 'insufficient' && $act === 'refused_insufficient');
    if (!$agree && count($disagree) < 10) { $disagree[] = ['seed' => $caseSeed, 'amount' => $amount, 'scope' => $scope, 'held_by_location' => $where, 'precheck' => $pre, 'actual' => $act]; }
    $nAgree = ($naivePre === 'sufficient' && $act === 'accepted') || ($naivePre === 'insufficient' && $act === 'refused_insufficient');
    $bump($naive, $nAgree ? 'agrees' : 'disagrees');
}
$out['B_precheck_vs_consume'] = ['cases' => $cases, 'seed_rule' => 'run_seed + case number', 'matrix' => $matrix,
    'scope_aware_precheck_disagreements' => array_sum(array_filter($matrix, fn($v, $k) => !in_array($k, ['precheck=sufficient actual=accepted', 'precheck=insufficient actual=refused_insufficient'], true), ARRAY_FILTER_USE_BOTH)),
    'disagreement_samples' => $disagree, 'product_wide_precheck_ignoring_scope' => $naive];

// --- C. named edge cases ---------------------------------------------------------------------------------
$c = [];
$o2 = b41_product($pdo, 'edge-other-location', $locs[0]); b41_add($o2, 3, $locs[0]); usleep(1500); b41_add($o2, 20, $locs[1]);
$c['another location holds stock: need 7 at A (holds 3), product total 23'] = ['scope_aware' => verdict($o2, 7, $locs[0], true), 'product_wide' => verdict($o2, 7, $locs[0], false), 'consume' => actual($o2, 7, $locs[0])];
$c['child location holds the stock, scope is the parent'] = (function () use ($pdo, $locs, $child) {
    $x = b41_product($pdo, 'edge-child', $locs[0]); b41_add($x, 4, $child);
    return ['scope_aware' => verdict($x, 1, $locs[0], true), 'consume' => actual($x, 1, $locs[0])];
})();
$m = b41_product($pdo, 'edge-measured-only', $locs[0]);
$pdo->exec("INSERT INTO stock(product_id,amount,stock_id,purchased_date,location_id,open,opened_date,opened_amount,opened_qu_id,opened_tare,opened_measured_at,best_before_date) VALUES ($m,1,'b41-m2','2026-10-01'," . $locs[0] . ",1,'2026-10-02',250,2,100,now(),'2999-12-31')");
$c['only stock is a measured container, need 0.5'] = ['scope_aware' => verdict($m, 0.5, null, true), 'consume' => actual($m, 0.5, null)];
$c['only stock is a measured container, need 1 (the whole container)'] = ['scope_aware' => verdict($m, 1, null, true), 'consume' => actual($m, 1, null)];
$out['C_named_cases'] = $c;
$out['lineage_violations_after_all'] = b41_lineage_violations($pdo);
B41Env::destroy();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
