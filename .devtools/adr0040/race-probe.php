<?php
// SPIKE ONLY. Probe 2 for ADR-0040: rule 8 (concurrency) under randomized interleavings.
// Two long-lived worker processes (race-worker.php), each with its own PostgreSQL session,
// receive one command each per iteration with seeded random start delays and seeded random
// holds inside the transaction. The probe then reads what each transaction logged together
// with pg_xact_commit_timestamp() and checks the properties listed per scenario.
// Output: JSON on stdout. Scratch tables only; the stock side is the real StockService.
require __DIR__ . '/common.php';

use Victual\Services\StockService;

$N = (int)(getenv('A40_N') ?: 400);
$MASTER = (int)(getenv('A40_SEED') ?: 40090910);
$pdo = A40::create();
$schema = A40::schemaName();
$pdo->exec(file_get_contents(__DIR__ . '/scratch.sql'));

const O = 9201, G = 9202, H = 9203, U = 9204;
foreach ([9000, O, G, H, U] as $id) { $pdo->exec("INSERT INTO users(id, username, password) VALUES ($id, 'a40-$id', 'fixture-only')"); }
$loc = (int)$pdo->query("INSERT INTO locations(name) VALUES ('a40') RETURNING id")->fetchColumn();
$products = [];
$stock = StockService::GetInstance();
for ($i = 1; $i <= 4; $i++) {
    $pid = (int)$pdo->query("INSERT INTO products(name, location_id, qu_id_stock, qu_id_purchase, qu_id_consume, qu_id_price) VALUES ('a40-p$i', $loc, 2, 2, 2, 2) RETURNING id")->fetchColumn();
    $stock->AddProduct($pid, 1000000, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, $loc);
    $products[] = $pid;
}

// ---------------------------------------------------------------- workers
function spawn(string $schema, int $n): array
{
    $env = ['PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'),
        'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'), 'A40_SCHEMA' => $schema, 'PATH' => getenv('PATH')];
    $p = proc_open(['php', __DIR__ . '/race-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "/tmp/a40-worker$n.err", 'w']], $pipes, '/app', $env);
    return ['proc' => $p, 'in' => $pipes[0], 'out' => $pipes[1]];
}
$workers = [spawn($schema, 1), spawn($schema, 2)];

function send(array $w, array $cmd): void { fwrite($w['in'], json_encode($cmd) . "\n"); fflush($w['in']); }
function recv(array $w, int $timeout = 30): ?array
{
    $r = [$w['out']]; $wr = null; $ex = null;
    if (stream_select($r, $wr, $ex, $timeout) === 0) { return null; }
    $line = fgets($w['out']);
    return $line === false ? null : json_decode($line, true);
}
$pick = fn(array $xs) => $xs[mt_rand(0, count($xs) - 1)];
$holds = [0, 0, 300, 1500, 4000];
function cmd(array $base, $pick, array $holds): array
{
    return $base + ['pre' => mt_rand(0, 6000), 'h1' => $pick($holds), 'h2' => $pick($holds), 'h3' => $pick($holds)];
}
$deadlocks = fn() => (int)$pdo->query("SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()")->fetchColumn();
$newRecipe = function (int $owner, array $lineProducts) use ($pdo): int {
    $id = (int)$pdo->query("INSERT INTO a40_recipes(owner_id, name) VALUES ($owner, 'r') RETURNING id")->fetchColumn();
    foreach ($lineProducts as $p) { $pdo->exec("INSERT INTO a40_recipe_lines(recipe_id, product_id, amount) VALUES ($id, $p, 1)"); }
    return $id;
};
$share = fn(int $rid, int $uid, array $r) => $pdo->exec("INSERT INTO a40_recipe_shares(recipe_id, user_id, right_consume, right_edit, right_undo, right_share, granted_by) VALUES ($rid, $uid, "
    . implode(', ', array_map(fn($k) => ($r[$k] ?? false) ? 'true' : 'false', ['consume', 'edit', 'undo', 'share'])) . ', ' . O . ')');
$subset = function () use ($products) { $n = mt_rand(1, 3); $s = $products; shuffle($s); return array_slice($s, 0, $n); };
$allRights = ['consume' => true, 'edit' => true, 'undo' => true, 'share' => true];

/** Runs one scenario; $iteration(i) returns [cmdA, cmdB, ctx]; $after(i, ctx, results) returns extra checks. */
$seedOf = [];
$runScenario = function (string $name, int $seedOffset, callable $iteration, callable $after) use ($N, $MASTER, $workers, $deadlocks, $pdo, &$seedOf): array {
    mt_srand($MASTER + $seedOffset);
    $seedOf[$name] = $MASTER + $seedOffset;
    $d0 = $deadlocks();
    $rows = [];
    $errors = [];
    $hung = 0;
    for ($i = 1; $i <= $N; $i++) {
        [$a, $b, $ctx] = $iteration($i);
        $a += ['scen' => $name, 'iter' => $i, 'label' => 'A'];
        $b += ['scen' => $name, 'iter' => $i, 'label' => 'B'];
        send($workers[0], $a);
        send($workers[1], $b);
        $ra = recv($workers[0]);
        $rb = recv($workers[1]);
        if ($ra === null || $rb === null) { $hung++; $rows[] = ['iter' => $i, 'hung' => true]; break; }
        foreach ([$ra, $rb] as $r) {
            if ($r['outcome'] === 'error') { $k = $r['sqlstate'] ?: 'none'; $errors[$k] = ($errors[$k] ?? 0) + 1; }
        }
        $rows[] = ['iter' => $i, 'ctx' => $ctx, 'A' => ['op' => $a['op'], 'pre' => $a['pre'], 'h' => [$a['h1'], $a['h2'], $a['h3']], 'result' => $ra],
            'B' => ['op' => $b['op'], 'pre' => $b['pre'], 'h' => [$b['h1'], $b['h2'], $b['h3']], 'result' => $rb], 'checks' => $after($i, $ctx, $ra, $rb)];
    }
    sleep(3);   // let the statistics collector publish the deadlock counter
    return ['rows' => $rows, 'errors_by_sqlstate' => $errors, 'hung_iterations' => $hung, 'pg_stat_database_deadlocks_delta' => $deadlocks() - $d0];
};

/** Joined log rows of one scenario: per iteration, per label, microsecond epochs. */
$logRows = function (string $scen) use ($pdo): array {
    $q = $pdo->prepare("SELECT iter, actor, op, outcome, detail, (extract(epoch from lock_at) * 1000000)::bigint AS lock_us,
        (extract(epoch from pg_xact_commit_timestamp(txid)) * 1000000)::bigint AS commit_us FROM a40_log WHERE scen = ? ORDER BY iter, actor");
    $q->execute([$scen]);
    $by = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $by[(int)$r['iter']][$r['actor']] = $r; }
    return $by;
};
$stockRowsFor = function (string $tid) use ($pdo): int {
    $q = $pdo->prepare('SELECT count(*) FROM stock_log WHERE transaction_id = ?'); $q->execute([$tid]); return (int)$q->fetchColumn();
};

$out = ['environment' => ['php' => PHP_VERSION, 'postgres' => $pdo->query('SHOW server_version')->fetchColumn(),
    'track_commit_timestamp' => $pdo->query('SHOW track_commit_timestamp')->fetchColumn(), 'isolation' => $pdo->query('SHOW transaction_isolation')->fetchColumn(),
    'deadlock_timeout' => $pdo->query('SHOW deadlock_timeout')->fetchColumn(), 'schema' => $schema],
    'iterations_per_scenario' => $N, 'master_seed' => $MASTER, 'scenarios' => []];

$summ = function (array $rows, array $by, callable $classify) {
    // $classify(A, B, ctx) returns [key => string, violations => string[]]
    $table = []; $viol = []; $ties = 0; $n = 0;
    foreach ($by as $iter => $pair) {
        if (!isset($pair['A'], $pair['B'])) { $viol['missing_log_row'] = ($viol['missing_log_row'] ?? 0) + 1; continue; }
        $n++;
        [$key, $v, $tie] = $classify($pair['A'], $pair['B'], $rows[$iter - 1]['ctx'] ?? null);
        $ties += $tie ? 1 : 0;
        $table[$key] = ($table[$key] ?? 0) + 1;
        foreach ($v as $name) { $viol[$name] = ($viol[$name] ?? 0) + 1; }
    }
    ksort($table);
    return ['iterations_analysed' => $n, 'outcome_table' => $table, 'violations' => $viol, 'commit_timestamp_ties' => $ties];
};
$firstLock = fn($A, $B) => $A['lock_us'] === null || $B['lock_us'] === null ? '?' : ($A['lock_us'] <= $B['lock_us'] ? 'A' : 'B');

// ---------------------------------------------------------------- S1: consume under share vs revoke
foreach (['safe' => 1, 'unsafe' => 2] as $variant => $off) {
    $name = "S1_consume_vs_revoke_$variant";
    $r = $runScenario($name, $off,
        function ($i) use ($newRecipe, $share, $subset, $pick, $holds, $variant) {
            $lines = $subset();
            $rid = $newRecipe(O, $lines);
            $share($rid, G, ['consume' => true]);
            return [cmd(['op' => 'consume', 'recipe' => $rid, 'actor_id' => G, 'variant' => $variant], $pick, $holds),
                    cmd(['op' => 'revoke', 'recipe' => $rid, 'target_id' => G, 'variant' => 'safe'], $pick, $holds), ['recipe' => $rid, 'lines' => count($lines)]];
        },
        function ($i, $ctx, $ra, $rb) use ($pdo, $workers) {
            $left = (int)$pdo->query("SELECT count(*) FROM a40_recipe_shares WHERE recipe_id = {$ctx['recipe']} AND user_id = " . G)->fetchColumn();
            send($workers[0], ['op' => 'consume', 'recipe' => $ctx['recipe'], 'actor_id' => G, 'variant' => 'safe', 'scen' => 'late', 'iter' => $i, 'label' => 'L', 'pre' => 0]);
            $late = recv($workers[0]);
            return ['share_row_left' => $left, 'late_consume' => $late['outcome'] ?? 'hung'];
        });
    $by = $logRows($name);
    $s = $summ($r['rows'], $by, function ($A, $B, $ctx) use ($firstLock, $stockRowsFor) {
        $v = [];
        $first = $firstLock($A, $B);
        $consumed = $A['outcome'] === 'consumed';
        if ($consumed && $B['outcome'] === 'revoked' && $A['commit_us'] > $B['commit_us']) { $v[] = 'consume_committed_after_revoke_committed'; }
        if ($consumed !== ($first === 'A')) { $v[] = 'outcome_differs_from_lock_order'; }
        $second = $first === 'A' ? $B : $A; $firstRow = $first === 'A' ? $A : $B;
        if ($first !== '?' && $second['lock_us'] < $firstRow['commit_us']) { $v[] = 'second_lock_before_first_commit'; }
        if ($consumed && $stockRowsFor($A['detail']) === 0) { $v[] = 'consumed_without_stock_rows'; }
        if (!$consumed && $A['detail'] !== null) { $v[] = 'refused_with_stock_rows'; }
        return ["lock_first=$first consume=" . $A['outcome'] . ' revoke=' . $B['outcome'] . ' commit_first=' . ($A['commit_us'] < $B['commit_us'] ? 'consume' : 'revoke'), $v, $A['commit_us'] === $B['commit_us']];
    });
    foreach ($r['rows'] as $row) {
        if (($row['checks']['share_row_left'] ?? 0) !== 0) { $s['violations']['revocation_lost_share_row_remains'] = ($s['violations']['revocation_lost_share_row_remains'] ?? 0) + 1; }
        if (($row['checks']['late_consume'] ?? '') !== 'absent') { $s['violations']['late_consume_not_refused'] = ($s['violations']['late_consume_not_refused'] ?? 0) + 1; }
    }
    $out['scenarios'][$name] = ['kind' => $variant === 'safe' ? 'rule 8 as written' : 'harness control: consume decides from the share without the recipe lock'] + $s
        + ['errors_by_sqlstate' => $r['errors_by_sqlstate'], 'hung_iterations' => $r['hung_iterations'], 'pg_stat_database_deadlocks_delta' => $r['pg_stat_database_deadlocks_delta'], 'iterations' => $r['rows']];
}

// ---------------------------------------------------------------- S2: grant vs grant, same user
foreach (['safe' => 3, 'unlocked' => 4] as $variant => $off) {
    $name = "S2_grant_vs_grant_$variant";
    $rights = function () { return ['consume' => (bool)mt_rand(0, 1), 'edit' => (bool)mt_rand(0, 1), 'undo' => (bool)mt_rand(0, 1), 'share' => false]; };
    $r = $runScenario($name, $off,
        function ($i) use ($newRecipe, $pick, $holds, $variant, $rights, $subset) {
            $rid = $newRecipe(O, $subset());
            $ra = $rights(); $rb = $rights();
            return [cmd(['op' => 'grant', 'recipe' => $rid, 'actor_id' => O, 'target_id' => U, 'rights' => $ra, 'variant' => $variant], $pick, $holds),
                    cmd(['op' => 'grant', 'recipe' => $rid, 'actor_id' => O, 'target_id' => U, 'rights' => $rb, 'variant' => $variant], $pick, $holds), ['recipe' => $rid, 'rights_A' => $ra, 'rights_B' => $rb]];
        },
        function ($i, $ctx, $ra, $rb) use ($pdo) {
            $rows = $pdo->query("SELECT right_consume, right_edit, right_undo FROM a40_recipe_shares WHERE recipe_id = {$ctx['recipe']} AND user_id = " . U)->fetchAll(PDO::FETCH_ASSOC);
            return ['share_rows' => count($rows), 'final' => $rows[0] ?? null];
        });
    $by = $logRows($name);
    $s = $summ($r['rows'], $by, function ($A, $B, $ctx) {
        // In the unlocked control one transaction fails with 23505 and writes no log row, so only locked runs reach here with both rows.
        $v = [];
        $pair = [$A['outcome'], $B['outcome']]; sort($pair);
        if ($pair !== ['inserted', 'updated']) { $v[] = 'not_one_insert_one_update'; }
        return ['outcomes=' . implode('+', $pair), $v, $A['commit_us'] === $B['commit_us']];
    });
    // Final rights must equal the later committer's.
    $badFinal = 0; $checked = 0;
    foreach ($r['rows'] as $row) {
        $i = $row['iter']; if (!isset($by[$i]['A'], $by[$i]['B'])) { continue; }
        $later = $by[$i]['A']['commit_us'] > $by[$i]['B']['commit_us'] ? 'rights_A' : 'rights_B';
        $want = $row['ctx'][$later]; $got = $row['checks']['final'];
        $checked++;
        if ($got === null || (bool)$got['right_consume'] !== $want['consume'] || (bool)$got['right_edit'] !== $want['edit'] || (bool)$got['right_undo'] !== $want['undo']) { $badFinal++; }
    }
    $s['final_rights_equal_later_committers_checked'] = $checked;
    $s['final_rights_differ_from_later_committer'] = $badFinal;
    $multi = count(array_filter($r['rows'], fn($x) => ($x['checks']['share_rows'] ?? 1) !== 1));
    $s['iterations_with_share_row_count_not_one'] = $multi;
    $out['scenarios'][$name] = ['kind' => $variant === 'safe' ? 'rule 8 as written (FOR UPDATE on the recipe row)' : 'harness control: no recipe lock, unique constraint is the only guard'] + $s
        + ['errors_by_sqlstate' => $r['errors_by_sqlstate'], 'hung_iterations' => $r['hung_iterations'], 'pg_stat_database_deadlocks_delta' => $r['pg_stat_database_deadlocks_delta'], 'iterations' => $r['rows']];
}

// ---------------------------------------------------------------- S3: transfer vs consume
$name = 'S3_transfer_vs_consume';
$r = $runScenario($name, 5,
    function ($i) use ($newRecipe, $share, $subset, $pick, $holds, $allRights) {
        $rid = $newRecipe(O, $subset());
        $share($rid, G, $allRights);
        $actor = mt_rand(0, 1) ? O : G;
        return [cmd(['op' => 'transfer', 'recipe' => $rid, 'actor_id' => O, 'target_id' => G, 'variant' => 'safe'], $pick, $holds),
                cmd(['op' => 'consume', 'recipe' => $rid, 'actor_id' => $actor, 'variant' => 'safe'], $pick, $holds), ['recipe' => $rid, 'consumer' => $actor === O ? 'old_owner' : 'new_owner']];
    },
    function ($i, $ctx, $ra, $rb) use ($pdo) {
        $owner = (int)$pdo->query("SELECT owner_id FROM a40_recipes WHERE id = {$ctx['recipe']}")->fetchColumn();
        $oldShare = (int)$pdo->query("SELECT count(*) FROM a40_recipe_shares WHERE recipe_id = {$ctx['recipe']} AND user_id = " . O . ' AND right_consume AND right_edit AND right_undo AND right_share')->fetchColumn();
        $newShare = (int)$pdo->query("SELECT count(*) FROM a40_recipe_shares WHERE recipe_id = {$ctx['recipe']} AND user_id = " . G)->fetchColumn();
        return ['owner' => $owner, 'old_owner_full_share' => $oldShare, 'new_owner_share_rows' => $newShare];
    });
$by = $logRows($name);
$s = $summ($r['rows'], $by, function ($A, $B, $ctx) use ($firstLock, $stockRowsFor) {
    $v = [];
    $first = $firstLock($A, $B);
    if ($B['outcome'] !== 'consumed') { $v[] = 'consume_not_completed'; }
    if ($A['outcome'] !== 'transferred') { $v[] = 'transfer_not_completed'; }
    if ($B['outcome'] === 'consumed' && $stockRowsFor($B['detail']) === 0) { $v[] = 'consumed_without_stock_rows'; }
    return ['lock_first=' . ($first === 'A' ? 'transfer' : 'consume') . ' consumer=' . $ctx['consumer'] . ' transfer=' . $A['outcome'] . ' consume=' . $B['outcome'], $v, $A['commit_us'] === $B['commit_us']];
});
foreach ($r['rows'] as $row) {
    $c = $row['checks'] ?? null; if (!$c) { continue; }
    if ($c['owner'] !== G || $c['old_owner_full_share'] !== 1 || $c['new_owner_share_rows'] !== 0) { $s['violations']['final_ownership_invariant'] = ($s['violations']['final_ownership_invariant'] ?? 0) + 1; }
}
$out['scenarios'][$name] = ['kind' => 'rule 8 as written'] + $s + ['errors_by_sqlstate' => $r['errors_by_sqlstate'], 'hung_iterations' => $r['hung_iterations'], 'pg_stat_database_deadlocks_delta' => $r['pg_stat_database_deadlocks_delta'], 'iterations' => $r['rows']];

// ---------------------------------------------------------------- S4: owner delete vs consume
$name = 'S4_owner_delete_vs_consume';
$r = $runScenario($name, 6,
    function ($i) use ($newRecipe, $share, $subset, $pick, $holds) {
        $rid = $newRecipe(O, $subset());
        $share($rid, G, ['consume' => true]);
        $actor = mt_rand(0, 1) ? O : G;
        return [cmd(['op' => 'delete', 'recipe' => $rid, 'actor_id' => O, 'variant' => 'safe'], $pick, $holds),
                cmd(['op' => 'consume', 'recipe' => $rid, 'actor_id' => $actor, 'variant' => 'safe'], $pick, $holds), ['recipe' => $rid, 'consumer' => $actor === O ? 'owner' : 'share_holder']];
    },
    function ($i, $ctx, $ra, $rb) use ($pdo) {
        $rid = $ctx['recipe'];
        return ['recipe_rows' => (int)$pdo->query("SELECT count(*) FROM a40_recipes WHERE id = $rid")->fetchColumn(),
            'share_rows' => (int)$pdo->query("SELECT count(*) FROM a40_recipe_shares WHERE recipe_id = $rid")->fetchColumn(),
            'line_rows' => (int)$pdo->query("SELECT count(*) FROM a40_recipe_lines WHERE recipe_id = $rid")->fetchColumn(),
            'events_still_pointing_at_recipe' => (int)$pdo->query("SELECT count(*) FROM a40_consume_events WHERE recipe_id = $rid")->fetchColumn()];
    });
$by = $logRows($name);
$s = $summ($r['rows'], $by, function ($A, $B, $ctx) use ($firstLock, $stockRowsFor, $pdo) {
    $v = [];
    $first = $firstLock($A, $B);
    $consumed = $B['outcome'] === 'consumed';
    if ($A['outcome'] !== 'deleted') { $v[] = 'delete_not_completed'; }
    if ($consumed && $A['outcome'] === 'deleted' && $B['commit_us'] > $A['commit_us']) { $v[] = 'consume_committed_after_delete_committed'; }
    if ($consumed !== ($first === 'B')) { $v[] = 'outcome_differs_from_lock_order'; }
    if ($consumed) {
        if ($stockRowsFor($B['detail']) === 0) { $v[] = 'consumed_without_stock_rows'; }
        $q = $pdo->prepare('SELECT count(*) FROM a40_consume_events WHERE transaction_id = ? AND recipe_id IS NULL'); $q->execute([$B['detail']]);
        if ((int)$q->fetchColumn() !== 1) { $v[] = 'event_row_missing_or_still_linked'; }
    }
    return ['lock_first=' . ($first === 'A' ? 'delete' : 'consume') . ' consumer=' . $ctx['consumer'] . ' consume=' . $B['outcome'], $v, $A['commit_us'] === $B['commit_us']];
});
foreach ($r['rows'] as $row) {
    $c = $row['checks'] ?? null; if (!$c) { continue; }
    if ($c['recipe_rows'] + $c['share_rows'] + $c['line_rows'] + $c['events_still_pointing_at_recipe'] !== 0) { $s['violations']['cascade_incomplete'] = ($s['violations']['cascade_incomplete'] ?? 0) + 1; }
}
$out['scenarios'][$name] = ['kind' => 'rule 8 as written'] + $s + ['errors_by_sqlstate' => $r['errors_by_sqlstate'], 'hung_iterations' => $r['hung_iterations'], 'pg_stat_database_deadlocks_delta' => $r['pg_stat_database_deadlocks_delta'], 'iterations' => $r['rows']];

// ---------------------------------------------------------------- S5: grantor loses a right while granting
$name = 'S5_holder_grant_vs_holder_revoked';
$r = $runScenario($name, 7,
    function ($i) use ($newRecipe, $share, $subset, $pick, $holds) {
        $rid = $newRecipe(O, $subset());
        $share($rid, H, ['consume' => true, 'share' => true]);
        $stripOnly = (bool)mt_rand(0, 1);
        return [cmd(['op' => 'grant', 'recipe' => $rid, 'actor_id' => H, 'target_id' => U, 'rights' => ['consume' => true, 'edit' => false, 'undo' => false, 'share' => false], 'variant' => 'safe'], $pick, $holds),
                cmd(['op' => $stripOnly ? 'strip_share_right' : 'revoke', 'recipe' => $rid, 'target_id' => H, 'variant' => 'safe'], $pick, $holds), ['recipe' => $rid, 'owner_action' => $stripOnly ? 'strip share right' : 'revoke share']];
    },
    function ($i, $ctx, $ra, $rb) use ($pdo) {
        return ['target_share_rows' => (int)$pdo->query("SELECT count(*) FROM a40_recipe_shares WHERE recipe_id = {$ctx['recipe']} AND user_id = " . U)->fetchColumn()];
    });
$by = $logRows($name);
$s = $summ($r['rows'], $by, function ($A, $B, $ctx) use ($firstLock) {
    $v = [];
    $first = $firstLock($A, $B);
    if ($A['outcome'] === 'inserted' && $B['commit_us'] < $A['commit_us']) { $v[] = 'grant_committed_after_holders_right_was_removed'; }
    if (($A['outcome'] === 'inserted') !== ($first === 'A')) { $v[] = 'outcome_differs_from_lock_order'; }
    return ['lock_first=' . ($first === 'A' ? 'grant' : 'owner_change') . ' ' . $ctx['owner_action'] . ' grant=' . $A['outcome'], $v, $A['commit_us'] === $B['commit_us']];
});
$out['scenarios'][$name] = ['kind' => 'rule 8 as written'] + $s + ['errors_by_sqlstate' => $r['errors_by_sqlstate'], 'hung_iterations' => $r['hung_iterations'], 'pg_stat_database_deadlocks_delta' => $r['pg_stat_database_deadlocks_delta'], 'iterations' => $r['rows']];

// ---------------------------------------------------------------- S6: consume vs consume, overlapping product sets
$name = 'S6_consume_vs_consume_overlapping_products';
$r = $runScenario($name, 8,
    function ($i) use ($pdo, $newRecipe, $share, $products, $pick, $holds) {
        $s1 = $products; shuffle($s1); $s1 = array_slice($s1, 0, mt_rand(2, 4));
        $s2 = $products; shuffle($s2); $s2 = array_slice($s2, 0, mt_rand(2, 4));
        $r1 = $newRecipe(O, $s1);
        $r2 = $newRecipe(O, array_reverse($s2));
        $share($r1, G, ['consume' => true]); $share($r2, G, ['consume' => true]);
        return [cmd(['op' => 'consume', 'recipe' => $r1, 'actor_id' => G, 'variant' => 'safe'], $pick, $holds),
                cmd(['op' => 'consume', 'recipe' => $r2, 'actor_id' => G, 'variant' => 'safe'], $pick, $holds), ['recipe' => $r1, 'recipe_B' => $r2, 'overlap' => count(array_intersect($s1, $s2))]];
    },
    fn($i, $ctx, $ra, $rb) => []);
$by = $logRows($name);
$s = $summ($r['rows'], $by, function ($A, $B, $ctx) {
    $v = [];
    if ($A['outcome'] !== 'consumed' || $B['outcome'] !== 'consumed') { $v[] = 'consume_not_completed'; }
    return ['both=' . $A['outcome'] . '/' . $B['outcome'] . ' overlap=' . $ctx['overlap'], $v, false];
});
$out['scenarios'][$name] = ['kind' => 'rule 8 lock order across two recipes (recipe FOR SHARE, then ascending product locks)'] + $s + ['errors_by_sqlstate' => $r['errors_by_sqlstate'], 'hung_iterations' => $r['hung_iterations'], 'pg_stat_database_deadlocks_delta' => $r['pg_stat_database_deadlocks_delta'], 'iterations' => $r['rows']];

// ---------------------------------------------------------------- S7: edit vs edit
// Rule 8 puts `edit` in the FOR SHARE class. If an edit then writes the recipe row (a name
// change) or rewrites lines, two edits both hold FOR SHARE and are not serialized by the
// recipe lock. Variants: name only, lines only in random order, and FOR UPDATE as a control.
foreach (['S7a_edit_vs_edit_for_share_name_only' => ['share', 'name', 9], 'S7b_edit_vs_edit_for_share_lines_only' => ['share', 'lines', 10],
          'S7c_edit_vs_edit_for_update_name_and_lines' => ['update', 'both', 11]] as $name => [$lock, $touch, $off]) {
    $r = $runScenario($name, $off,
        function ($i) use ($newRecipe, $share, $subset, $pick, $holds, $lock, $touch, $products) {
            $lines = $products; shuffle($lines); $lines = array_slice($lines, 0, mt_rand(2, 4));
            $rid = $newRecipe(O, $lines);
            $share($rid, H, ['edit' => true]);
            $oa = $lines; shuffle($oa); $ob = $lines; shuffle($ob);
            return [cmd(['op' => 'edit', 'recipe' => $rid, 'actor_id' => O, 'lock' => $lock, 'touch' => $touch, 'order' => $oa], $pick, $holds),
                    cmd(['op' => 'edit', 'recipe' => $rid, 'actor_id' => H, 'lock' => $lock, 'touch' => $touch, 'order' => $ob], $pick, $holds), ['recipe' => $rid, 'lines' => count($lines)]];
        },
        function ($i, $ctx, $ra, $rb) use ($pdo) {
            $amounts = $pdo->query("SELECT amount FROM a40_recipe_lines WHERE recipe_id = {$ctx['recipe']}")->fetchAll(PDO::FETCH_COLUMN);
            return ['line_amounts' => array_map('floatval', $amounts)];
        });
    $table = []; $viol = [];
    foreach ($r['rows'] as $row) {
        if (isset($row['hung'])) { continue; }
        $oa = $row['A']['result']['outcome'] . (isset($row['A']['result']['sqlstate']) ? ':' . $row['A']['result']['sqlstate'] : '');
        $ob = $row['B']['result']['outcome'] . (isset($row['B']['result']['sqlstate']) ? ':' . $row['B']['result']['sqlstate'] : '');
        $pair = [$oa, $ob]; sort($pair);
        $k = implode(' + ', $pair);
        $table[$k] = ($table[$k] ?? 0) + 1;
        $done = ($oa === 'edited' ? 1 : 0) + ($ob === 'edited' ? 1 : 0);
        if ($touch !== 'name') {
            foreach ($row['checks']['line_amounts'] as $amt) {
                if ($amt != 1 + $done) { $viol['line_amount_not_1_plus_completed_edits'] = ($viol['line_amount_not_1_plus_completed_edits'] ?? 0) + 1; break; }
            }
        }
    }
    ksort($table);
    $out['scenarios'][$name] = ['kind' => $lock === 'share' ? 'rule 8 literally (edit takes FOR SHARE), then the edit writes' : 'harness control: edit takes FOR UPDATE',
        'iterations_analysed' => count($r['rows']), 'outcome_table' => $table, 'violations' => $viol, 'commit_timestamp_ties' => 0,
        'errors_by_sqlstate' => $r['errors_by_sqlstate'], 'hung_iterations' => $r['hung_iterations'], 'pg_stat_database_deadlocks_delta' => $r['pg_stat_database_deadlocks_delta'], 'iterations' => $r['rows']];
}

foreach ($workers as $w) { fclose($w['in']); proc_close($w['proc']); }
$out['total_pg_stat_database_deadlocks'] = $deadlocks();
foreach ($out['scenarios'] as $n => &$sc) { $sc = ['seed' => $seedOf[$n]] + $sc; }
unset($sc);
// Compact on purpose: the per-iteration rows make a pretty-printed file several megabytes. summarize.py prints the tables.
echo json_encode($out), "\n";
A40::drop();
