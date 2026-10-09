<?php
// SPIKE ONLY. Probe 2: failure persistence (ADR-0041 rule 5, steps 2 and 3).
define('VICTUAL_USER_ID', 9000);
require __DIR__ . '/lib.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService as S;

$pdo = B41Env::create();
$db = DatabaseService::GetInstance();
$stock = S::GetInstance();
$loc = b41_location($pdo, 'B41 Shelf');
$out = ['probe' => 'failure-persistence', 'environment' => b41_env_info($pdo)];

function fixture(PDO $pdo, int $loc, string $tag, float $s1, float $s2): array
{
    $a = b41_product($pdo, "$tag-a", $loc); $b = b41_product($pdo, "$tag-b", $loc);
    b41_add($a, $s1, $loc); usleep(1500); b41_add($b, $s2, $loc); usleep(1500);
    return [$a, $b];
}
function snapshot(PDO $pdo, array $p): array
{
    $ids = implode(',', $p);
    return ['stock' => array_map('floatval', $pdo->query("SELECT COALESCE(sum(amount),0) FROM stock WHERE product_id IN ($ids) GROUP BY product_id ORDER BY product_id")->fetchAll(PDO::FETCH_COLUMN)),
        'stock_log_rows' => (int)$pdo->query("SELECT count(*) FROM stock_log WHERE product_id IN ($ids)")->fetchColumn(),
        'consume_rows' => (int)$pdo->query("SELECT count(*) FROM stock_log WHERE product_id IN ($ids) AND transaction_type='consume'")->fetchColumn(),
        'ledger_hash' => b41_ledger_hash($pdo, $p)];
}
function ev(PDO $pdo, string $eid): array
{
    return $pdo->query("SELECT state, reason, transaction_id FROM b41_events WHERE source_event_id='$eid'")->fetch(PDO::FETCH_ASSOC) ?: [];
}
$req = fn($eid, $p, $q1, $q2) => ['user' => 9000, 'sys' => 'healthkit', 'eid' => $eid, 'sua' => null, 'recipe' => true,
    'payload' => ['status' => 'taken', 'medication_ref' => 'm', 'quantity' => $q1, 'unit_label' => 'tablet', 'occurred_at' => '2026-10-09T08:00:00-04:00', 'location_id' => null],
    'lines' => [[$p[0], $q1, null], [$p[1], $q2, null]]];

// A. The ADR layout: transaction 2 fails on line 2, rolls back completely, transaction 3 commits needs_review.
[$a, $b] = fixture($pdo, $loc, 'A', 10, 3);
$before = snapshot($pdo, [$a, $b]);
$r = b41_submit($req('F1', [$a, $b], 4, 5));
$after = snapshot($pdo, [$a, $b]);
$out['A_three_transaction_layout'] = ['line1' => '4 of 10 (satisfiable)', 'line2' => '5 of 3 (insufficient)', 'before' => $before, 'after' => $after,
    'ledger_unchanged' => $before === $after, 'event' => ev($pdo, 'F1'), 'submit_result' => $r,
    'lineage_violations' => b41_lineage_violations($pdo)];

// B. Catching the application exception inside the one transaction and continuing: no PostgreSQL error, so nothing aborts.
[$a, $b] = fixture($pdo, $loc, 'B', 10, 3);
$before = snapshot($pdo, [$a, $b]);
$pdo->exec("INSERT INTO b41_events(user_id,source_system,source_event_id,payload_hash,payload) VALUES (9000,'healthkit','F2','x','{}')");
$caught = null;
$db->InTransaction(function () use ($db, $stock, $a, $b, &$caught) {
    $tx = null;
    $stock->ConsumeProduct($a, 4, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx);
    try {
        $db->InTransaction(function () use ($stock, $b, &$tx) { $stock->ConsumeProduct($b, 5, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx); });
    } catch (Throwable $e) {
        $caught = b41_err($e);
        $db->GetDbConnectionRaw()->exec("UPDATE b41_events SET state='needs_review', reason='insufficient_stock' WHERE source_event_id='F2'");
    }
});
$out['B_catch_app_exception_and_continue'] = ['caught' => $caught, 'after' => snapshot($pdo, [$a, $b]), 'event' => ev($pdo, 'F2'),
    'expected_if_atomic' => $before['stock'], 'partial_deduction_committed' => snapshot($pdo, [$a, $b])['stock'] !== $before['stock']];

// C. A SQL-originated failure caught inside the nested call: the transaction is aborted.
[$a, $b] = fixture($pdo, $loc, 'C', 10, 3);
$before = snapshot($pdo, [$a, $b]);
$pdo->exec("INSERT INTO b41_events(user_id,source_system,source_event_id,payload_hash,payload) VALUES (9000,'healthkit','F3','x','{}')");
$c = ['first_error' => null, 'next_statement_error' => null, 'outer_exception' => null];
try {
    $db->InTransaction(function () use ($db, $stock, $a, &$c) {
        $tx = null;
        $stock->ConsumeProduct($a, 4, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx);
        try {
            $db->InTransaction(function () use ($db) { $db->GetDbConnectionRaw()->exec('INSERT INTO b41_event_lines(event_id,product_id,amount) VALUES (-1, 1, 1)'); });
        } catch (Throwable $e) { $c['first_error'] = b41_err($e); }
        try {
            $db->GetDbConnectionRaw()->exec("UPDATE b41_events SET state='needs_review', reason='booking_error' WHERE source_event_id='F3'");
        } catch (Throwable $e) { $c['next_statement_error'] = b41_err($e); throw $e; }
    });
} catch (Throwable $e) { $c['outer_exception'] = b41_err($e)['sqlstate']; }
$c['after'] = snapshot($pdo, [$a, $b]); $c['event'] = ev($pdo, 'F3'); $c['ledger_unchanged'] = $c['after']['ledger_hash'] === $before['ledger_hash'];
$out['C_catch_sql_error_and_continue'] = $c;

// D. Same, but the caller swallows the SQL error and lets the outermost call commit.
[$a, $b] = fixture($pdo, $loc, 'D', 10, 3);
$before = snapshot($pdo, [$a, $b]);
$pdo->exec("INSERT INTO b41_events(user_id,source_system,source_event_id,payload_hash,payload) VALUES (9000,'healthkit','F4','x','{}')");
$d = ['outer_exception' => null];
try {
    $d['outer_returned'] = $db->InTransaction(function () use ($db, $stock, $a) {
        $tx = null;
        $stock->ConsumeProduct($a, 4, false, S::TRANSACTION_TYPE_CONSUME, 'default', null, null, $tx);
        try {
            $db->InTransaction(function () use ($db) { $db->GetDbConnectionRaw()->exec('INSERT INTO b41_event_lines(event_id,product_id,amount) VALUES (-1, 1, 1)'); });
        } catch (Throwable $e) { /* swallowed */ }
        return $tx;
    });
} catch (Throwable $e) { $d['outer_exception'] = b41_err($e); }
$d['after'] = snapshot($pdo, [$a, $b]);
$d['ledger_unchanged_although_outer_call_returned'] = $d['after']['ledger_hash'] === $before['ledger_hash'];
$out['D_swallow_sql_error_then_commit'] = $d;

$out['lineage_violations_after_all'] = b41_lineage_violations($pdo);
$out['conclusions'] = [
    'insufficient_stock_is_an_application_exception' => 'B shows no PostgreSQL error is raised, so the transaction is not aborted; line 1 stays pending and commits with the outer call.',
    'sql_errors_abort_the_transaction' => 'C shows SQLSTATE 25P02 on the next statement after a swallowed SQL error.',
    'swallowed_sql_error_then_commit' => 'D shows commit() reports success while PostgreSQL rolled the transaction back.',
];
B41Env::destroy();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
