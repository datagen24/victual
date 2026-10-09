<?php
// SPIKE ONLY. Long-lived worker for race-probe.php. One JSON command per input line, one JSON
// answer per output line. Every operation follows ADR-0040 rule 8 as written:
//   - consume, undo-style reads: SELECT ... FOR SHARE on the recipe row, evaluate the share
//     afterwards, then DatabaseService::LockProductsStock() (ascending), then the real
//     StockService::ConsumeProduct() inside DatabaseService::InTransaction();
//   - grant, revoke, transfer, delete: SELECT ... FOR UPDATE on the recipe row first.
// Variants 'unsafe' (consume reads the share without taking the recipe lock) and
// 'unlocked' (grant without the recipe lock) exist only as controls for the harness.
// Random delays ("holds") are slept at fixed points inside the transaction to widen windows.
require __DIR__ . '/common.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

$pdo = A40::attach(getenv('A40_SCHEMA'));
$db = DatabaseService::GetInstance();
$stock = StockService::GetInstance();

function a40_ts(PDO $pdo): string { return $pdo->query('SELECT clock_timestamp()')->fetchColumn(); }

function a40_can(PDO $pdo, int $recipe, int $actor, string $right): string
{
    $r = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $recipe")->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return 'absent'; }
    if ((int)$r['owner_id'] === $actor) { return 'owner'; }
    $s = $pdo->query("SELECT $right AS r FROM a40_recipe_shares WHERE recipe_id = $recipe AND user_id = $actor")->fetch(PDO::FETCH_ASSOC);
    if (!$s) { return 'absent'; }
    return $s['r'] ? 'share' : 'forbidden';
}

function a40_log(PDO $pdo, array $c, string $begin, ?string $lockAt, string $outcome, ?string $detail): void
{
    $st = $pdo->prepare('INSERT INTO a40_log(scen, iter, actor, op, txid, begin_at, lock_at, outcome, detail) VALUES (?, ?, ?, ?, pg_current_xact_id()::xid, ?, ?, ?, ?)');
    $st->execute([$c['scen'], $c['iter'], $c['label'], $c['op'], $begin, $lockAt, $outcome, $detail]);
}

$h = fn(array $c, string $k) => (int)($c[$k] ?? 0);

while (($line = fgets(STDIN)) !== false) {
    $c = json_decode($line, true);
    $res = ['label' => $c['label']];
    try {
        usleep($h($c, 'pre'));
        $rid = (int)$c['recipe'];
        $res['outcome'] = $db->InTransaction(function () use ($pdo, $db, $stock, $c, $rid, $h) {
            $begin = a40_ts($pdo);
            $lockAt = null;
            $outcome = null;
            $detail = null;
            switch ($c['op']) {
                case 'consume':
                    $actor = (int)$c['actor_id'];
                    if ($c['variant'] === 'safe') {
                        $row = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $rid FOR SHARE")->fetch(PDO::FETCH_ASSOC);
                        $lockAt = a40_ts($pdo);
                        usleep($h($c, 'h1'));
                        $who = $row ? a40_can($pdo, $rid, $actor, 'right_consume') : 'absent';
                    } else {
                        $who = a40_can($pdo, $rid, $actor, 'right_consume');   // decided without the lock
                        $lockAt = a40_ts($pdo);
                        usleep($h($c, 'h1'));
                    }
                    if ($who === 'absent' || $who === 'forbidden') { $outcome = $who; break; }
                    $lines = $pdo->query("SELECT product_id, amount FROM a40_recipe_lines WHERE recipe_id = $rid ORDER BY product_id")->fetchAll(PDO::FETCH_ASSOC);
                    $db->LockProductsStock(array_column($lines, 'product_id'));
                    usleep($h($c, 'h2'));
                    $tid = uniqid('a40-');
                    foreach ($lines as $l) {
                        $t = $tid;
                        $stock->ConsumeProduct((int)$l['product_id'], (float)$l['amount'], false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $t);
                    }
                    $pdo->prepare('INSERT INTO a40_consume_events(transaction_id, recipe_id, user_id) VALUES (?, ?, ?)')->execute([$tid, $rid, $actor]);
                    usleep($h($c, 'h3'));
                    $outcome = 'consumed';
                    $detail = $tid;
                    break;

                case 'edit':
                    // lock 'share' is rule 8 as written for edit; 'update' is the harness control.
                    $mode = $c['lock'] === 'share' ? 'FOR SHARE' : 'FOR UPDATE';
                    $row = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $rid $mode")->fetch(PDO::FETCH_ASSOC);
                    $lockAt = a40_ts($pdo);
                    usleep($h($c, 'h1'));
                    $who = $row ? a40_can($pdo, $rid, (int)$c['actor_id'], 'right_edit') : 'absent';
                    if ($who === 'absent' || $who === 'forbidden') { $outcome = $who; break; }
                    if (in_array($c['touch'], ['name', 'both'], true)) {
                        $pdo->exec("UPDATE a40_recipes SET name = 'edit-{$c['label']}-{$c['iter']}' WHERE id = $rid");
                    }
                    usleep($h($c, 'h2'));
                    if (in_array($c['touch'], ['lines', 'both'], true)) {
                        foreach ($c['order'] as $pid) {
                            $pdo->exec("UPDATE a40_recipe_lines SET amount = amount + 1 WHERE recipe_id = $rid AND product_id = " . (int)$pid);
                            usleep($h($c, 'h3'));
                        }
                    }
                    $outcome = 'edited';
                    break;

                case 'revoke':
                    $row = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $rid FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
                    $lockAt = a40_ts($pdo);
                    usleep($h($c, 'h1'));
                    if (!$row) { $outcome = 'absent'; break; }
                    $n = $pdo->exec("DELETE FROM a40_recipe_shares WHERE recipe_id = $rid AND user_id = " . (int)$c['target_id']);
                    usleep($h($c, 'h3'));
                    $outcome = $n === 1 ? 'revoked' : 'noop';
                    break;

                case 'strip_share_right':
                    $row = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $rid FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
                    $lockAt = a40_ts($pdo);
                    usleep($h($c, 'h1'));
                    if (!$row) { $outcome = 'absent'; break; }
                    $n = $pdo->exec("UPDATE a40_recipe_shares SET right_share = false WHERE recipe_id = $rid AND user_id = " . (int)$c['target_id']);
                    usleep($h($c, 'h3'));
                    $outcome = $n === 1 ? 'revoked' : 'noop';
                    break;

                case 'grant':
                    $lockSql = $c['variant'] === 'safe' ? ' FOR UPDATE' : '';
                    $row = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $rid$lockSql")->fetch(PDO::FETCH_ASSOC);
                    $lockAt = a40_ts($pdo);
                    usleep($h($c, 'h1'));
                    if (!$row) { $outcome = 'absent'; break; }
                    $grantor = (int)$c['actor_id'];
                    $rights = $c['rights'];   // consume, edit, undo, share booleans
                    if ($grantor !== (int)$row['owner_id']) {
                        $mine = $pdo->query("SELECT right_consume, right_edit, right_undo, right_share FROM a40_recipe_shares WHERE recipe_id = $rid AND user_id = $grantor")->fetch(PDO::FETCH_ASSOC);
                        if (!$mine) { $outcome = 'absent'; break; }
                        if (!$mine['right_share']) { $outcome = 'refused'; break; }
                        // rule 4: only rights the holder holds, and never `share`
                        $ok = !$rights['share'] && (!$rights['consume'] || $mine['right_consume']) && (!$rights['edit'] || $mine['right_edit']) && (!$rights['undo'] || $mine['right_undo']);
                        if (!$ok) { $outcome = 'refused'; break; }
                    }
                    $target = (int)$c['target_id'];
                    $exists = $pdo->query("SELECT 1 FROM a40_recipe_shares WHERE recipe_id = $rid AND user_id = $target")->fetchColumn();
                    usleep($h($c, 'h2'));
                    $b = fn($k) => $rights[$k] ? 'true' : 'false';
                    if ($exists) {
                        $pdo->exec("UPDATE a40_recipe_shares SET right_consume = {$b('consume')}, right_edit = {$b('edit')}, right_undo = {$b('undo')}, right_share = {$b('share')}, granted_by = $grantor WHERE recipe_id = $rid AND user_id = $target");
                        $outcome = 'updated';
                    } else {
                        $pdo->exec("INSERT INTO a40_recipe_shares(recipe_id, user_id, right_consume, right_edit, right_undo, right_share, granted_by) VALUES ($rid, $target, {$b('consume')}, {$b('edit')}, {$b('undo')}, {$b('share')}, $grantor)");
                        $outcome = 'inserted';
                    }
                    usleep($h($c, 'h3'));
                    break;

                case 'transfer':
                    $row = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $rid FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
                    $lockAt = a40_ts($pdo);
                    usleep($h($c, 'h1'));
                    $old = (int)$c['actor_id'];
                    $new = (int)$c['target_id'];
                    if (!$row || (int)$row['owner_id'] !== $old) { $outcome = 'absent'; break; }
                    if (!$pdo->query("SELECT 1 FROM a40_recipe_shares WHERE recipe_id = $rid AND user_id = $new")->fetchColumn()) { $outcome = 'refused'; break; }
                    $pdo->exec("DELETE FROM a40_recipe_shares WHERE recipe_id = $rid AND user_id = $new");
                    $pdo->exec("UPDATE a40_recipes SET owner_id = $new WHERE id = $rid");
                    $pdo->exec("INSERT INTO a40_recipe_shares(recipe_id, user_id, right_consume, right_edit, right_undo, right_share, granted_by) VALUES ($rid, $old, true, true, true, true, $new)");
                    usleep($h($c, 'h3'));
                    $outcome = 'transferred';
                    break;

                case 'delete':
                    $row = $pdo->query("SELECT owner_id FROM a40_recipes WHERE id = $rid FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
                    $lockAt = a40_ts($pdo);
                    usleep($h($c, 'h1'));
                    if (!$row) { $outcome = 'absent'; break; }
                    $pdo->exec("DELETE FROM a40_recipes WHERE id = $rid");
                    usleep($h($c, 'h3'));
                    $outcome = 'deleted';
                    break;

                default:
                    throw new RuntimeException('unknown op ' . $c['op']);
            }
            a40_log($pdo, $c, $begin, $lockAt, $outcome, $detail);
            return $outcome;
        });
    } catch (Throwable $e) {
        $res['outcome'] = 'error';
        $res['sqlstate'] = $e instanceof PDOException ? ($e->errorInfo[0] ?? (string)$e->getCode()) : '';
        $res['message'] = substr($e->getMessage(), 0, 300);
    }
    echo json_encode($res), "\n";
    fflush(STDOUT);
}
