<?php
// SPIKE ONLY. Child process for the concurrency tests in constraints.php.
// argv: mode, JSON arguments. The child connects, prints READY, waits on a shared advisory lock
// the parent holds exclusively (so every child is released at the same moment), runs one action
// and prints one JSON line.
require __DIR__ . '/lib.php';
[$script, $mode, $json] = $argv;
$args = json_decode($json, true);
$pdo = adr42_connect(getenv('ADR42_SCHEMA'));
echo "READY\n";
flush();
$t0 = microtime(true);
$pdo->exec('SELECT pg_advisory_lock_shared(4242)'); // blocks until the parent unlocks
$sqlstate = function (Throwable $e): string { return $e instanceof PDOException ? (string)($e->errorInfo[0] ?? $e->getCode()) : get_class($e); };

try
{
	if ($mode === 'order')
	{
		$st = $pdo->prepare("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES (?, '2026-03-01')");
		$st->execute([$args['recipe']]);
		echo json_encode(['result' => 'inserted']), "\n";
	}
	elseif ($mode === 'ack')
	{
		$st = $pdo->prepare('INSERT INTO notice_acks (user_id, notice_key) VALUES (?, ?) ON CONFLICT DO NOTHING');
		$st->execute([$args['user'], $args['key']]);
		$rows = $st->rowCount();
		$at = $pdo->prepare('SELECT acknowledged_at::text FROM notice_acks WHERE user_id = ? AND notice_key = ?');
		$at->execute([$args['user'], $args['key']]);
		echo json_encode(['result' => 'ok', 'rows_inserted' => $rows, 'acknowledged_at' => $at->fetchColumn()]), "\n";
	}
	elseif ($mode === 'receive' || $mode === 'receive_then_hang')
	{
		// Receive = insert the fill, close the order to reference it, one transaction.
		$pdo->beginTransaction();
		$ins = $pdo->prepare("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES (?, '2026-03-02', 30) RETURNING id");
		$ins->execute([$args['recipe']]);
		$fillId = (int)$ins->fetchColumn();
		if ($mode === 'receive_then_hang')
		{
			echo json_encode(['result' => 'mid_transaction', 'fill_id' => $fillId]), "\n";
			flush();
			sleep(60);
		}
		$upd = $pdo->prepare("UPDATE refill_orders SET state = 'received', received_fill_id = ? WHERE id = ? AND state = 'open'");
		$upd->execute([$fillId, $args['order']]);
		if ($upd->rowCount() !== 1)
		{
			$pdo->rollBack();
			echo json_encode(['result' => 'lost_race_rolled_back']), "\n";
		}
		else
		{
			$pdo->commit();
			echo json_encode(['result' => 'received', 'fill_id' => $fillId]), "\n";
		}
	}
	elseif ($mode === 'receive_autocommit_then_hang')
	{
		// Control: the same two writes with no transaction, killed between them.
		$pdo->prepare("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES (?, '2026-03-02', 30)")->execute([$args['recipe']]);
		echo json_encode(['result' => 'mid_sequence']), "\n";
		flush();
		sleep(60);
	}
}
catch (Throwable $e)
{
	echo json_encode(['result' => 'error', 'sqlstate' => $sqlstate($e), 'message' => substr($e->getMessage(), 0, 160)]), "\n";
}
echo json_encode(['waited_s' => round(microtime(true) - $t0, 3)]), "\n";
