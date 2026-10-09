<?php
// SPIKE ONLY. Probe 3: lock order (event row, recipe row, ascending products) against concurrent
// direct consumes, undo and corrections, with randomized interleavings. Real StockService.
//   B41_TRIALS (default 300 per scenario), B41_SEED (default 430001), B41_QUICK=1 -> 8 trials
define('VICTUAL_USER_ID', 9000);
require __DIR__ . '/lib.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService as S;

$pdo = B41Env::create();
$schema = B41Env::schemaName();
$db = DatabaseService::GetInstance();
$stock = S::GetInstance();
$ctl = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'), getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ctl->exec("SET search_path TO $schema, public");
$trials = (int)(getenv('B41_TRIALS') ?: (getenv('B41_QUICK') ? 8 : 300));
$baseSeed = (int)(getenv('B41_SEED') ?: 430001);
$loc = b41_location($pdo, 'B41 Shelf');
$workers = b41_spawn_workers(4, $schema);
$out = ['probe' => 'lock-order', 'environment' => b41_env_info($pdo), 'run_seed' => $baseSeed, 'trials_per_scenario' => $trials, 'workers' => 4];

function classify(array $r): string
{
    if (!isset($r['error'])) { return 'ok'; }
    $s = $r['error']['sqlstate'] ?? '?';
    if ($s === '40P01') { return 'deadlock_40P01'; }
    if ($s === '40001') { return 'serialization_40001'; }
    $m = $r['error']['message'] ?? '';
    if ($s === 'APP' && (str_contains($m, 'already undone') || str_contains($m, 'not found'))) { return 'refused_already_undone'; }
    if ($s === 'APP' && str_contains($m, 'subsequent dependent bookings')) { return 'refused_dependent_booking'; }
    return $s === 'APP' ? 'app_error' : "sqlstate_$s";
}

function prior(PDO $pdo, array $p, string $evNo): array
{
    $stock = S::GetInstance(); $db = DatabaseService::GetInstance();
    $make = function (array $lines) use ($stock, $db) {
        $tx = null;
        $db->InTransaction(function () use ($stock, $lines, &$tx) {
            foreach ($lines as $pid) { $stock->ConsumeProduct($pid, 1, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx); }
        });
        return $tx;
    };
    $out = [];
    foreach ([[$p[0], $p[1]], [$p[2]]] as $i => $lines) {
        $tx = $make($lines);
        $st = $pdo->prepare("INSERT INTO b41_events(user_id,source_system,source_event_id,payload_hash,state,transaction_id,payload) VALUES (9000,'healthkit',?,'x','booked',?,'{}') RETURNING id");
        $st->execute(["P$evNo-$i", $tx]);
        $out[] = ['tx' => $tx, 'event' => (int)$st->fetchColumn(), 'first_booking' => (int)$pdo->query("SELECT min(id) FROM stock_log WHERE transaction_id='$tx'")->fetchColumn()];
    }
    return $out;
}

function invariants(PDO $pdo, array $p): array
{
    $bad = [];
    foreach ($p as $pid) {
        $s = (float)$pdo->query("SELECT COALESCE(sum(amount),0) FROM stock WHERE product_id=$pid")->fetchColumn();
        $l = (float)$pdo->query("SELECT COALESCE(sum(amount),0) FROM stock_log WHERE product_id=$pid AND undone=0 AND transaction_type IN ('purchase','consume')")->fetchColumn();
        if (abs($s - $l) > 1e-6) { $bad[] = "product $pid stock $s != log $l"; }
        $v = (int)$pdo->query("SELECT count(*) FROM stock_lineage_violations($pid)")->fetchColumn();
        if ($v) { $bad[] = "product $pid lineage violations $v"; }
    }
    return $bad;
}

function subset(array $p, int $min = 1): array
{
    do { $s = array_values(array_filter($p, fn() => mt_rand(0, 1))); } while (count($s) < $min);
    return $s;
}

function run_scenario(string $name, int $trials, int $seedBase, array $workers, PDO $pdo, PDO $ctl, callable $build): array
{
    $loc = b41_location($pdo, "B41 $name");
    $counts = []; $violations = []; $deadlockSeeds = []; $ms = []; $msgs = [];
    for ($i = 1; $i <= $trials; $i++) {
        $seed = $seedBase + $i; mt_srand($seed);
        // Fresh products and lots per trial: an undo is refused while a later booking depends on
        // the same lot, so shared lots would make nearly every undo a refusal and hide the lock order.
        $p = [];
        foreach (['a', 'b', 'c'] as $t) { $p[] = b41_product($pdo, "$name-$i-$t", $loc); }
        foreach ($p as $pid) { b41_add($pid, 1000, $loc); usleep(1500); }
        $pri = prior($pdo, $p, "$name-$i");
        $actors = $build($p, $pri, "$name-$i", $seed);
        $n = count($actors); $key = crc32($name) % 100000 * 1000 + $i;
        b41_release_together($ctl, $key, $n, function () use ($actors, $workers, $key, $seed) {
            foreach ($actors as $k => $cmd) { b41_send($workers[$k], $cmd + ['seed' => $seed, 'barrier' => $key]); }
        });
        $res = [];
        foreach ($actors as $k => $_) { $res[] = b41_recv($workers[$k]) ?? ['error' => ['sqlstate' => 'NO_REPLY', 'message' => '']]; }
        foreach ($res as $k => $r) {
            $label = ($actors[$k]['label'] ?? $actors[$k]['op']);
            $c = classify($r); $counts["$label:$c"] = ($counts["$label:$c"] ?? 0) + 1;
            $counts["_all:$c"] = ($counts["_all:$c"] ?? 0) + 1; $ms[] = $r['ms'] ?? 0;
            if ($c !== 'ok') { $mk = "$label: " . mb_substr($r['error']['message'] ?? '', 0, 110); $msgs[$mk] = ($msgs[$mk] ?? 0) + 1; }
            if ($c === 'deadlock_40P01' && count($deadlockSeeds) < 20) { $deadlockSeeds[] = $seed; }
        }
        $bad = invariants($pdo, $p);
        if ($bad) { $violations[] = ['trial' => $i, 'seed' => $seed, 'problems' => $bad]; }
    }
    ksort($counts); sort($ms);
    $dead = $counts['_all:deadlock_40P01'] ?? 0;
    $trialsWithDeadlock = count(array_unique($deadlockSeeds));
    return ['trials' => $trials, 'first_seed' => $seedBase + 1, 'last_seed' => $seedBase + $trials, 'outcomes' => (object)$counts, 'refusal_messages' => (object)$msgs, 'deadlocks_40P01' => $dead,
        'trials_with_a_deadlock_sample_seeds' => array_slice(array_values(array_unique($deadlockSeeds)), 0, 10), 'invariant_violations' => $violations,
        'latency_ms' => ['p50' => $ms[(int)(count($ms) * 0.5)], 'p95' => $ms[(int)(count($ms) * 0.95)], 'max' => end($ms)]];
}

$J = ['start' => 4000, 'after_t1' => 2000, 'after_lock' => 3000, 'between_lines' => 2500, 'between_steps' => 2500];
$ev = fn($eid, $lines) => ['op' => 'submit', 'label' => 'event', 'jitter_us' => $J, 'req' => [
    'user' => 9000, 'sys' => 'healthkit', 'eid' => $eid, 'sua' => null, 'recipe' => true, 'jitter_us' => $J,
    'payload' => ['status' => 'taken', 'medication_ref' => 'm', 'quantity' => 1, 'unit_label' => 'tablet', 'occurred_at' => '2026-10-09T08:00:00-04:00', 'location_id' => null],
    'lines' => array_map(fn($pid) => [$pid, 1, null], $lines)]];
$directSingle = fn($pid) => ['op' => 'direct', 'label' => 'direct_single', 'jitter_us' => $J, 'lines' => [[$pid, 1]]];
$directAsc = fn($lines) => ['op' => 'direct', 'label' => 'direct_multi_ascending', 'jitter_us' => $J, 'outer' => true, 'prelock' => true, 'lines' => array_map(fn($x) => [$x, 1], $lines)];

$scenarios = [
    'A_event_vs_direct' => function ($p, $pri, $eid) use ($ev, $directSingle, $directAsc) {
        $a = [$ev($eid, subset($p))];
        for ($k = mt_rand(1, 2); $k > 0; $k--) { $a[] = mt_rand(0, 1) ? $directSingle($p[mt_rand(0, 2)]) : $directAsc(subset($p, 2)); }
        return $a;
    },
    'B_event_vs_undo' => function ($p, $pri, $eid) use ($ev, $directSingle) {
        $a = [$ev($eid, subset($p))];
        $pool = [['op' => 'undo_tx', 'label' => 'undo_tx_1', 'jitter_us' => ['start' => 4000], 'transaction_id' => $pri[0]['tx']],
            ['op' => 'undo_tx', 'label' => 'undo_tx_2', 'jitter_us' => ['start' => 4000], 'transaction_id' => $pri[1]['tx']],
            ['op' => 'undo_booking', 'label' => 'undo_booking', 'jitter_us' => ['start' => 4000], 'booking_id' => $pri[0]['first_booking']],
            $directSingle($p[mt_rand(0, 2)])];
        shuffle($pool);
        return array_merge($a, array_slice($pool, 0, mt_rand(1, 3)));
    },
    'B2_undo_transaction_vs_undo_one_booking' => function ($p, $pri) {
        $a = [['op' => 'undo_tx', 'label' => 'undo_tx', 'jitter_us' => ['start' => 5000], 'transaction_id' => $pri[0]['tx']],
            ['op' => 'undo_booking', 'label' => 'undo_booking', 'jitter_us' => ['start' => 5000], 'booking_id' => $pri[0]['first_booking']]];
        if (mt_rand(0, 1)) { $a[] = ['op' => 'undo_tx', 'label' => 'undo_tx_second', 'jitter_us' => ['start' => 5000], 'transaction_id' => $pri[0]['tx']]; }
        return $a;
    },
];
// C: two corrections plus one more actor. The recipe row is locked FOR SHARE, as ADR-0040 rule 8 says a
// consumption does; C5 uses FOR UPDATE to show what a literal reading of "locks the recipe row" would do.
// C1, C2: different recipes. C3, C4, C5: the same recipe. C1 and C3 pre-lock the union of the old and the
// new products in ascending order; the others take product locks as UndoTransaction() and
// ConsumeProduct() do on their own.
$cVariants = [
    'C1_corrections_prelocked_union_different_recipes' => [true, false, 'share'],
    'C2_corrections_no_prelock_different_recipes' => [false, false, 'share'],
    'C3_corrections_prelocked_union_same_recipe_share' => [true, true, 'share'],
    'C4_corrections_no_prelock_same_recipe_share' => [false, true, 'share'],
    'C5_corrections_no_prelock_same_recipe_for_update' => [false, true, 'update'],
];
foreach ($cVariants as $nm => [$pre, $sameRecipe, $mode]) {
    // Starts are staggered by at most 0.3 ms and the pause between the undo and the new bookings is up
    // to 4 ms, so both corrections usually hold their undo locks before either books.
    $scenarios[$nm] = function ($p, $pri, $eid) use ($pre, $sameRecipe, $mode, $ev, $directSingle) {
        $Jc = ['start' => 300, 'after_lock' => 500, 'between_lines' => 1000, 'between_steps' => 4000];
        $corr = fn($pr, $lines, $rid) => ['op' => 'correction', 'label' => 'correction', 'jitter_us' => $Jc, 'recipe_id' => $rid, 'recipe_lock' => $mode, 'event_id' => $pr['event'], 'prelock' => $pre, 'lines' => array_map(fn($x) => [$x, 1], $lines)];
        $a = [$corr($pri[0], subset($p), 1), $corr($pri[1], subset($p), $sameRecipe ? 1 : 2)];
        $r = mt_rand(0, 3);
        if ($r === 0) { $a[] = $directSingle($p[mt_rand(0, 2)]); }
        elseif ($r === 1) { $e = $ev($eid, subset($p)); $e['req']['recipe_id'] = 3; $e['req']['recipe_lock'] = $mode; $a[] = $e; }
        elseif ($r === 2) { $a[] = ['op' => 'undo_tx', 'label' => 'undo_tx', 'jitter_us' => ['start' => 4000], 'transaction_id' => $pri[mt_rand(0, 1)]['tx']]; }
        return $a;
    };
}
$scenarios['D_control_opposite_order_no_prelock'] = function ($p) use ($J) {
    $x = subset($p, 2);
    return [['op' => 'direct', 'label' => 'multi_ascending', 'jitter_us' => $J, 'outer' => true, 'prelock' => false, 'lines' => array_map(fn($i) => [$i, 1], $x)],
        ['op' => 'direct', 'label' => 'multi_descending', 'jitter_us' => $J, 'outer' => true, 'prelock' => false, 'lines' => array_map(fn($i) => [$i, 1], array_reverse($x))]];
};

$idx = 0;
foreach ($scenarios as $name => $build) {
    $idx++;
    b41_log("scenario $name ($trials trials)");
    $out['scenarios'][$name] = run_scenario($name, $trials, $baseSeed + $idx * 100000, $workers, $pdo, $ctl, $build);
}
$out['lineage_violations_after_all'] = b41_lineage_violations($pdo);
$out['summary'] = array_map(fn($s) => ['trials' => $s['trials'], 'deadlocks_40P01' => $s['deadlocks_40P01'], 'invariant_violations' => count($s['invariant_violations']),
    'refused_already_undone' => ((array)$s['outcomes'])['_all:refused_already_undone'] ?? 0, 'refused_dependent_booking' => ((array)$s['outcomes'])['_all:refused_dependent_booking'] ?? 0, 'app_error' => ((array)$s['outcomes'])['_all:app_error'] ?? 0], $out['scenarios']);
b41_kill_workers($workers);
B41Env::destroy();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
