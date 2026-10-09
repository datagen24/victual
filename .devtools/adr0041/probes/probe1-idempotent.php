<?php
// SPIKE ONLY. Probe 1: idempotent submission race (ADR-0041 rule 5) through the real
// StockService::ConsumeProduct(). JSON on stdout, progress on stderr.
//   B41_ROUNDS (default 200), B41_N (default "16,64"), B41_SEED (default 410001), B41_QUICK=1 -> 5 rounds
define('VICTUAL_USER_ID', 9000);
require __DIR__ . '/lib.php';

use Victual\Services\StockService as S;

$pdo = B41Env::create();
$schema = B41Env::schemaName();
$ctl = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'), getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ctl->exec("SET search_path TO $schema, public");
$rounds = (int)(getenv('B41_ROUNDS') ?: (getenv('B41_QUICK') ? 5 : 200));
$sizes = array_map('intval', explode(',', getenv('B41_N') ?: '16,64'));
$runSeed = (int)(getenv('B41_SEED') ?: 410001);
$loc = b41_location($pdo, 'B41 Shelf');
$out = ['probe' => 'idempotent-submission', 'environment' => b41_env_info($pdo), 'run_seed' => $runSeed, 'rounds_per_scenario' => $rounds];

function fresh_products(PDO $pdo, int $loc, string $tag): array
{
    $a = b41_product($pdo, "$tag-a", $loc); $b = b41_product($pdo, "$tag-b", $loc);
    b41_add($a, 50, $loc); usleep(1500); b41_add($b, 50, $loc); usleep(1500);
    return [$a, $b];
}

function request(array $o): array
{
    return ['user' => $o['user'], 'sys' => $o['sys'] ?? 'healthkit', 'eid' => $o['eid'], 'sua' => $o['sua'] ?? null,
        'payload' => ['status' => 'taken', 'medication_ref' => $o['med'] ?? 'hk:med:1', 'quantity' => $o['q1'], 'unit_label' => 'tablet',
            'occurred_at' => '2026-10-09T08:03:00-04:00', 'location_id' => null],
        'lines' => [[$o['p1'], $o['q1'], null], [$o['p2'], $o['q2'], null]],
        'recipe' => true, 'jitter_us' => $o['jitter'] ?? ['start' => 3000, 'after_t1' => 1000, 'after_lock' => 500, 'between_lines' => 300],
        'hooks' => $o['hooks'] ?? []];
}

function verify_round(PDO $pdo, array $results, array $p, array $q, string $eid, int $user, string $sys = 'healthkit'): array
{
    $bad = [];
    $booked = array_filter($results, fn($r) => ($r['booked_now'] ?? false) === true);
    $replayed = array_filter($results, fn($r) => ($r['booked_now'] ?? null) === false && ($r['state'] ?? '') === 'booked');
    $errors = array_filter($results, fn($r) => isset($r['error']));
    $inserted = array_filter($results, fn($r) => ($r['inserted'] ?? false) === true);
    if (count($booked) !== 1) { $bad[] = 'booked_now count ' . count($booked); }
    if (count($replayed) !== count($results) - 1) { $bad[] = 'replayed count ' . count($replayed); }
    if (count($errors) > 0) { $bad[] = 'errors ' . count($errors); }
    if (count($inserted) !== 1) { $bad[] = 'inserted count ' . count($inserted); }
    $txs = array_unique(array_map(fn($r) => $r['transaction_id'] ?? null, $results));
    if (count($txs) !== 1) { $bad[] = 'distinct transaction ids ' . count($txs); }
    $tx = reset($txs);
    $rows = (int)$pdo->query("SELECT count(*) FROM stock_log WHERE transaction_id='$tx' AND user_id=$user")->fetchColumn();
    $all = (int)$pdo->query("SELECT count(*) FROM stock_log WHERE product_id IN ({$p[0]},{$p[1]}) AND transaction_type='consume' AND user_id=$user")->fetchColumn();
    if ($rows !== 2 || $all !== 2) { $bad[] = "stock_log rows for tx $rows, for products $all"; }
    $ev = (int)$pdo->query("SELECT count(*) FROM b41_events WHERE user_id=$user AND source_system='$sys' AND source_event_id='$eid' AND state='booked'")->fetchColumn();
    if ($ev !== 1) { $bad[] = "event rows $ev"; }
    return $bad;
}

// --- A: identical request from N parallel children, 200 rounds -------------------------------
$out['scenario_A_parallel_identical'] = [];
foreach ($sizes as $N) {
    b41_log("A: spawning $N workers");
    $workers = b41_spawn_workers($N, $schema);
    $rssKb = array_sum(array_column($workers, 'rss_kb'));
    $violations = []; $errs = []; $ms = []; $okRounds = 0; $seeds = []; $winners = [];
    for ($r = 1; $r <= $rounds; $r++) {
        $seed = $runSeed + $N * 100000 + $r; mt_srand($seed);
        $q1 = mt_rand(1, 5); $q2 = mt_rand(1, 5);
        [$p1, $p2] = fresh_products($pdo, $loc, "A$N-$r");
        $eid = "A$N-$r"; $seeds[] = $seed;
        $key = $N * 1000 + $r;
        b41_release_together($ctl, $key, $N, function () use ($workers, $seed, $key, $p1, $p2, $q1, $q2, $eid) {
            foreach ($workers as $w) {
                b41_send($w, ['op' => 'submit', 'seed' => $seed, 'barrier' => $key, 'jitter_us' => ['start' => 3000],
                    'req' => request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2])]);
            }
        });
        $results = [];
        foreach ($workers as $w) { $results[] = b41_recv($w) ?? ['error' => ['sqlstate' => 'NO_REPLY']]; }
        foreach ($results as $x) { if (isset($x['error'])) { $k = $x['error']['sqlstate'] ?? '?'; $errs[$k] = ($errs[$k] ?? 0) + 1; } $ms[] = $x['ms'] ?? 0; }
        $bad = verify_round($pdo, $results, [$p1, $p2], [$q1, $q2], $eid, 9000);
        $a1 = b41_stock_amount($pdo, $p1); $a2 = b41_stock_amount($pdo, $p2);
        if (abs($a1 - (50 - $q1)) > 1e-9 || abs($a2 - (50 - $q2)) > 1e-9) { $bad[] = "stock $a1/$a2 expected " . (50 - $q1) . '/' . (50 - $q2); }
        if ($bad) { $violations[] = ['round' => $r, 'seed' => $seed, 'problems' => $bad]; } else { $okRounds++; }
        foreach ($results as $x) { if (($x['booked_now'] ?? false) === true) { $winners[$x['worker']] = ($winners[$x['worker']] ?? 0) + 1; } }
        if ($r % 25 === 0) { b41_log("A N=$N round $r/$rounds ok=$okRounds"); }
    }
    sort($ms);
    $out['scenario_A_parallel_identical']["N=$N"] = [
        'children' => $N, 'rounds' => $rounds, 'rounds_exactly_once' => $okRounds, 'violations' => $violations,
        'error_counts_by_sqlstate' => (object)$errs, 'requests_total' => $N * $rounds,
        'latency_ms' => ['p50' => $ms[(int)(count($ms) * 0.5)], 'p95' => $ms[(int)(count($ms) * 0.95)], 'max' => end($ms)],
        'worker_rss_mb_total_at_start' => round($rssKb / 1024), 'distinct_winners' => count($winners),
        'first_seed' => $seeds[0], 'last_seed' => end($seeds), 'seed_rule' => 'run_seed + N*100000 + round',
    ];
    b41_kill_workers($workers);
    b41_log("A N=$N done: $okRounds/$rounds rounds exactly once");
}

// --- B: same key from two users ---------------------------------------------------------------
{
    $N = 16; $workers = b41_spawn_workers($N, $schema, [9000, 9001]); $roundsB = min($rounds, 60);
    $viol = []; $okB = 0;
    for ($r = 1; $r <= $roundsB; $r++) {
        $seed = $runSeed + 7000000 + $r; mt_srand($seed);
        $q1 = mt_rand(1, 5); $q2 = mt_rand(1, 5);
        [$p1, $p2] = fresh_products($pdo, $loc, "B$r");
        $eid = "B$r"; $key = 900000 + $r;
        b41_release_together($ctl, $key, $N, function () use ($workers, $seed, $key, $p1, $p2, $q1, $q2, $eid) {
            foreach ($workers as $w) {
                b41_send($w, ['op' => 'submit', 'seed' => $seed, 'barrier' => $key, 'jitter_us' => ['start' => 3000],
                    'req' => request(['user' => $w['user'], 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2])]);
            }
        });
        $results = []; foreach ($workers as $w) { $x = b41_recv($w); $x['user'] = $w['user']; $results[] = $x; }
        $bad = [];
        foreach ([9000, 9001] as $u) {
            $mine = array_values(array_filter($results, fn($x) => $x['user'] === $u));
            $bad = array_merge($bad, array_map(fn($b) => "user $u: $b", verify_round_user($pdo, $mine, $eid, $u, $p1, $p2)));
        }
        $e = (int)$pdo->query("SELECT count(*) FROM b41_events WHERE source_event_id='$eid'")->fetchColumn();
        if ($e !== 2) { $bad[] = "event rows $e"; }
        $a1 = b41_stock_amount($pdo, $p1);
        if (abs($a1 - (50 - 2 * $q1)) > 1e-9) { $bad[] = "stock $a1 expected " . (50 - 2 * $q1); }
        if ($bad) { $viol[] = ['round' => $r, 'seed' => $seed, 'problems' => $bad]; } else { $okB++; }
    }
    // lookup by another user finds nothing
    $pdo->exec("INSERT INTO b41_events(user_id,source_system,source_event_id,payload_hash,payload) VALUES (9000,'healthkit','ONLY-9000','x','{}')");
    $other = (int)$pdo->query("SELECT count(*) FROM b41_events WHERE user_id=9001 AND source_system='healthkit' AND source_event_id='ONLY-9000'")->fetchColumn();
    $out['scenario_B_same_key_two_users'] = ['children' => $N, 'rounds' => $roundsB, 'rounds_two_independent_events_each_booked_once' => $okB, 'violations' => $viol,
        'lookup_of_other_users_key_rows_found' => $other, 'seed_rule' => 'run_seed + 7000000 + round'];
    b41_kill_workers($workers);
    b41_log("B done: $okB/$roundsB");
}

function verify_round_user(PDO $pdo, array $results, string $eid, int $user, int $p1, int $p2): array
{
    $bad = [];
    $booked = array_filter($results, fn($r) => ($r['booked_now'] ?? false) === true);
    $replayed = array_filter($results, fn($r) => ($r['booked_now'] ?? null) === false && ($r['state'] ?? '') === 'booked');
    if (count($booked) !== 1) { $bad[] = 'booked_now ' . count($booked); }
    if (count($replayed) !== count($results) - 1) { $bad[] = 'replayed ' . count($replayed); }
    $tx = reset($booked)['transaction_id'] ?? '';
    $n = (int)$pdo->query("SELECT count(*) FROM stock_log WHERE transaction_id='$tx' AND user_id=$user AND product_id IN ($p1,$p2)")->fetchColumn();
    if ($n !== 2) { $bad[] = "stock_log rows $n"; }
    return $bad;
}

// --- C: crash simulations ---------------------------------------------------------------------
{
    $pool = b41_spawn_workers(8, $schema); $roundsC = min($rounds, 30);
    $res = ['after_t1_alone' => ['rounds' => $roundsC, 'ok' => 0, 'violations' => []],
        'after_t1_raced_by_8' => ['rounds' => $roundsC, 'ok' => 0, 'violations' => []],
        'killed_in_t2_before_commit' => ['rounds' => $roundsC, 'ok' => 0, 'violations' => []]];
    $spawnCrash = function (string $hook) use ($schema) {
        $env = array_merge(getenv(), ['B41_SCHEMA' => $schema, 'B41_USER' => '9000', 'B41_WORKER' => '99']);
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', '/app/.devtools/adr0041/probes/worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/tmp/b41-crash.err', 'a']], $pipes, '/app', $env);
        $w = ['proc' => $p, 'in' => $pipes[0], 'out' => $pipes[1], 'user' => 9000, 'id' => 99];
        fgets($w['out']);
        return $w;
    };
    $stateOf = fn($eid) => $pdo->query("SELECT state||'/'||COALESCE(transaction_id,'-') FROM b41_events WHERE source_event_id='$eid'")->fetchColumn();
    for ($r = 1; $r <= $roundsC; $r++) {
        $seed = $runSeed + 8000000 + $r; mt_srand($seed); $q1 = mt_rand(1, 5); $q2 = mt_rand(1, 5);
        // (1) crash worker alone: dies after transaction 1; next request must finish the job
        [$p1, $p2] = fresh_products($pdo, $loc, "C1-$r"); $eid = "C1-$r";
        $w = $spawnCrash('crash_after_t1');
        b41_send($w, ['op' => 'submit', 'req' => request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2, 'hooks' => ['crash_after_t1' => true]])]);
        $marker = fgets($w['out']); proc_close($w['proc']);
        $bad = [];
        if (!str_contains((string)$marker, 'T1_COMMITTED')) { $bad[] = 'no marker'; }
        if ($stateOf($eid) !== 'received/-') { $bad[] = 'state after crash ' . $stateOf($eid); }
        if (abs(b41_stock_amount($pdo, $p1) - 50) > 1e-9) { $bad[] = 'stock changed by crashed request'; }
        $r1 = b41_submit(request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2]));
        if (!($r1['booked_now'] ?? false) || ($r1['inserted'] ?? true)) { $bad[] = 'continuation did not book'; }
        $r2 = b41_submit(request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2]));
        if (($r2['booked_now'] ?? true) || abs(b41_stock_amount($pdo, $p1) - (50 - $q1)) > 1e-9) { $bad[] = 'second retry rebooked'; }
        $bad ? $res['after_t1_alone']['violations'][] = ['round' => $r, 'seed' => $seed, 'problems' => $bad] : $res['after_t1_alone']['ok']++;

        // (2) crash worker raced by eight healthy children released together
        [$p1, $p2] = fresh_products($pdo, $loc, "C2-$r"); $eid = "C2-$r"; $key = 800000 + $r;
        $w = $spawnCrash('crash_after_t1');
        b41_release_together($ctl, $key, 9, function () use ($pool, $w, $seed, $key, $p1, $p2, $q1, $q2, $eid) {
            b41_send($w, ['op' => 'submit', 'seed' => $seed, 'barrier' => $key, 'req' => request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2, 'hooks' => ['crash_after_t1' => true]])]);
            foreach ($pool as $x) { b41_send($x, ['op' => 'submit', 'seed' => $seed, 'barrier' => $key, 'req' => request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2])]); }
        });
        $results = []; foreach ($pool as $x) { $results[] = b41_recv($x); }
        fgets($w['out']); proc_close($w['proc']);
        $booked = array_filter($results, fn($x) => ($x['booked_now'] ?? false) === true);
        $bad = [];
        if (count($booked) !== 1) { $bad[] = 'booked_now ' . count($booked); }
        if (abs(b41_stock_amount($pdo, $p1) - (50 - $q1)) > 1e-9 || abs(b41_stock_amount($pdo, $p2) - (50 - $q2)) > 1e-9) { $bad[] = 'stock not decremented exactly once'; }
        if (count(array_filter($results, fn($x) => isset($x['error']))) > 0) { $bad[] = 'errors'; }
        $bad ? $res['after_t1_raced_by_8']['violations'][] = ['round' => $r, 'seed' => $seed, 'problems' => $bad] : $res['after_t1_raced_by_8']['ok']++;

        // (3) killed after both ConsumeProduct() calls, before transaction 2 commits
        [$p1, $p2] = fresh_products($pdo, $loc, "C3-$r"); $eid = "C3-$r";
        $before = b41_ledger_hash($pdo, [$p1, $p2]);
        $w = $spawnCrash('die_in_t2');
        b41_send($w, ['op' => 'submit', 'req' => request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2, 'hooks' => ['die_in_t2' => true]])]);
        $marker = fgets($w['out']); proc_close($w['proc']);
        $bad = [];
        if (!str_contains((string)$marker, 'BOOKED_UNCOMMITTED')) { $bad[] = 'no marker'; }
        usleep(200000); // let the server notice the dead client
        if (b41_ledger_hash($pdo, [$p1, $p2]) !== $before) { $bad[] = 'ledger changed by a killed transaction'; }
        if ($stateOf($eid) !== 'received/-') { $bad[] = 'state ' . $stateOf($eid); }
        $r1 = b41_submit(request(['user' => 9000, 'eid' => $eid, 'p1' => $p1, 'p2' => $p2, 'q1' => $q1, 'q2' => $q2]));
        if (!($r1['booked_now'] ?? false) || abs(b41_stock_amount($pdo, $p1) - (50 - $q1)) > 1e-9) { $bad[] = 'retry did not book exactly once'; }
        $bad ? $res['killed_in_t2_before_commit']['violations'][] = ['round' => $r, 'seed' => $seed, 'problems' => $bad] : $res['killed_in_t2_before_commit']['ok']++;
    }
    $out['scenario_C_crash'] = $res + ['seed_rule' => 'run_seed + 8000000 + round'];
    b41_kill_workers($pool);
    b41_log('C done');
}

// --- D: replay with a different or omitted source_updated_at; ordering table -------------------
{
    [$p1, $p2] = fresh_products($pdo, $loc, 'D'); $base = ['user' => 9000, 'eid' => 'D1', 'p1' => $p1, 'p2' => $p2, 'q1' => 2, 'q2' => 3, 'jitter' => []];
    $first = b41_submit(request($base + ['sua' => '2026-10-09T08:03:05-04:00']));
    $cases = [];
    foreach (['newer' => '2026-10-09T09:00:00-04:00', 'older' => '2026-10-09T07:00:00-04:00', 'omitted' => null, 'equal' => '2026-10-09T08:03:05-04:00'] as $label => $sua) {
        $r = b41_submit(request(['sua' => $sua] + $base));
        $cases[$label] = ['booked_now' => $r['booked_now'] ?? null, 'state' => $r['state'] ?? null, 'same_transaction' => ($r['transaction_id'] ?? null) === $first['transaction_id']];
    }
    $rows = (int)$pdo->query("SELECT count(*) FROM stock_log WHERE product_id IN ($p1,$p2) AND transaction_type='consume'")->fetchColumn();
    $h = b41_payload_hash(request($base)['payload']);
    $h2 = b41_payload_hash(array_merge(request($base)['payload'], ['quantity' => 9]));
    $table = [];
    foreach ([['older', '2026-10-09T07:00:00Z'], ['newer', '2026-10-09T09:00:00Z'], ['stored absent', null], ['equal', '2026-10-09T08:00:00Z']] as [$l, $s]) {
        $table["different hash, new sua $l"] = b41_ordering($h, $l === 'stored absent' ? null : '2026-10-09T08:00:00Z', $h2, $s);
    }
    $table['same hash, any sua'] = b41_ordering($h, '2026-10-09T08:00:00Z', $h, '2030-01-01T00:00:00Z');
    $out['scenario_D_source_updated_at'] = ['first' => $first['state'], 'replays' => $cases, 'consume_bookings_total' => $rows, 'expected_bookings' => 2,
        'payload_hash_ignores_sua' => $h === b41_payload_hash(request($base + ['sua' => 'x'])['payload']), 'ordering_table' => $table];
}

$out['lineage_violations_after_all'] = b41_lineage_violations($pdo);
$a = $out['scenario_A_parallel_identical'];
$out['summary'] = ['A' => array_map(fn($s) => "$s[rounds_exactly_once]/$s[rounds]", $a), 'B' => $out['scenario_B_same_key_two_users']['rounds_two_independent_events_each_booked_once'] . '/' . $out['scenario_B_same_key_two_users']['rounds'],
    'C' => array_map(fn($s) => is_array($s) && isset($s['ok']) ? "$s[ok]/$s[rounds]" : null, array_filter($out['scenario_C_crash'], 'is_array'))];
B41Env::destroy();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
