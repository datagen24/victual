<?php
// SPIKE ONLY. Child process for the two-connection tests in model-probe.php.
require __DIR__ . '/ref-model.php';
[$script, $mode, $json] = $argv; $args = json_decode($json, true);
$pdo = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'), getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET search_path TO ' . getenv('ADR36_SCHEMA') . ', public');
$uid = fn() => '0' . substr(str_shuffle(str_repeat('0123456789ABCDEFGH', 3)), 0, 12);
$t0 = microtime(true);
try {
    if ($mode === 'consume') {
        $r = new Ref($pdo, $args['product'], 0);
        $r->consume((float)$args['amount']);
        echo json_encode(['result' => 'consumed', 'waited_s' => round(microtime(true) - $t0, 2)]);
    } elseif ($mode === 'issue' || $mode === 'issue_hold') {
        $pdo->beginTransaction();
        $pdo->exec('SELECT pg_advisory_xact_lock(109038)');               // the import lock, taken first by label issuance
        $row = $pdo->query('SELECT id FROM stock WHERE id=' . (int)$args['row'] . ' FOR UPDATE')->fetchColumn(); // then the row lock
        if ($row === false) { $pdo->rollBack(); echo json_encode(['result' => 'row gone: issuance refused, no label inserted', 'waited_s' => round(microtime(true) - $t0, 2)]); exit; }
        $pdo->exec("INSERT INTO labels(uid, kind, target_id) VALUES ('" . $uid() . "', 'stock_entry', " . (int)$args['row'] . ')');
        if ($mode === 'issue_hold') { usleep((int)($args['hold_s'] * 1e6)); }
        $pdo->commit();
        echo json_encode(['result' => 'label issued', 'waited_s' => round(microtime(true) - $t0, 2)]);
    }
} catch (Throwable $e) { echo json_encode(['result' => 'error', 'message' => $e->getMessage()]); }
