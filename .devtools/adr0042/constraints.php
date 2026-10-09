<?php
// SPIKE ONLY. Probe 4 for ADR-0042: constraints and concurrency in scratch DDL shaped like the
// ADR (model.sql). Output: JSON on stdout.
require __DIR__ . '/lib.php';

$admin = adr42_connect();
$schema = adr42_schema($admin, 'cons');
$pdo = adr42_connect($schema);
adr42_load_sql($pdo, 'estimate.sql');
adr42_load_sql($pdo, 'model.sql');
$out = ['probe' => 'constraints', 'environment' => adr42_environment($pdo)];

function newRecipe(PDO $pdo): int { return (int)$pdo->query('INSERT INTO recipes DEFAULT VALUES RETURNING id')->fetchColumn(); }

/** Run a statement in autocommit; return "ok" or the SQLSTATE. */
function attempt(PDO $pdo, string $sql, array $params = []): string
{
	try { $pdo->prepare($sql)->execute($params); return 'ok'; }
	catch (PDOException $e) { return (string)$e->errorInfo[0]; }
}

// ---- 1. Declarative constraints ------------------------------------------------------------
$r = newRecipe($pdo);
$r2 = newRecipe($pdo);
$goodFill = (int)$pdo->query("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES ($r, '2026-01-01', 30) RETURNING id")->fetchColumn();
$otherFill = (int)$pdo->query("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES ($r2, '2026-01-01', 30) RETURNING id")->fetchColumn();
$fillSql = 'INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES (?, ?, ?)';
$setSql = 'INSERT INTO refill_settings (recipe_id, rule_kind, rule_param, lead_days) VALUES (?, ?, ?, ?)';
$tests = [
	// [label, sql, params, expected]
	['supplied_days = 1', $fillSql, [$r, '2026-01-01', 1], 'ok'],
	['supplied_days = 730', $fillSql, [$r, '2026-01-01', 730], 'ok'],
	['supplied_days = 0', $fillSql, [$r, '2026-01-01', 0], '23514'],
	['supplied_days = 731', $fillSql, [$r, '2026-01-01', 731], '23514'],
	['supplied_days = -1', $fillSql, [$r, '2026-01-01', -1], '23514'],
	['supplied_days = NULL', $fillSql, [$r, '2026-01-01', null], '23502'],
	['filled_on = NULL', $fillSql, [$r, null, 30], '23502'],
	['fill for a recipe that does not exist', $fillSql, [999999, '2026-01-01', 30], '23503'],
	['void reason without voided_at', "UPDATE refill_fills SET void_reason = 'x' WHERE id = $goodFill", [], '23514'],
	['ordered_on = NULL', 'INSERT INTO refill_orders (recipe_id, ordered_on) VALUES (?, ?)', [$r, null], '23502'],
	['order state = \'lost\'', "INSERT INTO refill_orders (recipe_id, ordered_on, state) VALUES (?, '2026-03-01', 'lost')", [$r], '23514'],
	['order closed as received with no fill', "INSERT INTO refill_orders (recipe_id, ordered_on, state) VALUES (?, '2026-03-01', 'received')", [$r], '23514'],
	['order open but pointing at a fill', "INSERT INTO refill_orders (recipe_id, ordered_on, state, received_fill_id) VALUES (?, '2026-03-01', 'open', $goodFill)", [$r], '23514'],
	['order received with a fill of another recipe', "INSERT INTO refill_orders (recipe_id, ordered_on, state, received_fill_id) VALUES (?, '2026-03-01', 'received', $otherFill)", [$r], '23503'],
	['order received with a fill of the same recipe', "INSERT INTO refill_orders (recipe_id, ordered_on, state, received_fill_id) VALUES (?, '2026-03-01', 'received', $goodFill)", [$r], 'ok'],
	['days_before_end N = 0', $setSql, [newRecipe($pdo), 'days_before_end', 0, null], 'ok'],
	['days_before_end N = 730', $setSql, [newRecipe($pdo), 'days_before_end', 730, null], 'ok'],
	['days_before_end N = -1', $setSql, [newRecipe($pdo), 'days_before_end', -1, null], '23514'],
	['days_before_end N = 731', $setSql, [newRecipe($pdo), 'days_before_end', 731, null], '23514'],
	['days_before_end N = 7.5', $setSql, [newRecipe($pdo), 'days_before_end', 7.5, null], '23514'],
	['fixed_interval D = 1', $setSql, [newRecipe($pdo), 'fixed_interval', 1, null], 'ok'],
	['fixed_interval D = 0', $setSql, [newRecipe($pdo), 'fixed_interval', 0, null], '23514'],
	['fixed_interval D = 731', $setSql, [newRecipe($pdo), 'fixed_interval', 731, null], '23514'],
	['fraction_elapsed F = 0.5', $setSql, [newRecipe($pdo), 'fraction_elapsed', '0.5', null], 'ok'],
	['fraction_elapsed F = 0', $setSql, [newRecipe($pdo), 'fraction_elapsed', '0', null], '23514'],
	['fraction_elapsed F = 1', $setSql, [newRecipe($pdo), 'fraction_elapsed', '1', null], '23514'],
	['fraction_elapsed F = 1.0', $setSql, [newRecipe($pdo), 'fraction_elapsed', '1.0', null], '23514'],
	['fraction_elapsed F = 0.0001', $setSql, [newRecipe($pdo), 'fraction_elapsed', '0.0001', null], 'ok'],
	['rule kind without parameter', $setSql, [newRecipe($pdo), 'fixed_interval', null, null], '23514'],
	['unknown rule kind', $setSql, [newRecipe($pdo), 'every_other_tuesday', 3, null], '23514'],
	['lead_days = 0', $setSql, [newRecipe($pdo), null, null, 0], 'ok'],
	['lead_days = 60', $setSql, [newRecipe($pdo), null, null, 60], 'ok'],
	['lead_days = 61', $setSql, [newRecipe($pdo), null, null, 61], '23514'],
	['lead_days = -1', $setSql, [newRecipe($pdo), null, null, -1], '23514'],
	['explicit date without a fill reference', 'INSERT INTO refill_settings (recipe_id, explicit_date) VALUES (?, ?)', [newRecipe($pdo), '2026-03-01'], '23514'],
	['explicit date bound to a fill of another recipe', 'INSERT INTO refill_settings (recipe_id, explicit_date, explicit_fill_id) VALUES (?, ?, ?)', [newRecipe($pdo), '2026-03-01', $otherFill], '23503'],
	['explicit date bound to a fill of its own recipe', 'INSERT INTO refill_settings (recipe_id, explicit_date, explicit_fill_id) VALUES (?, ?, ?)', [$r, '2026-03-01', $goodFill], 'ok'],
];
$results = []; $bad = 0;
foreach ($tests as [$label, $sql, $params, $expected])
{
	$got = attempt($pdo, $sql, $params);
	$results[] = ['case' => $label, 'expected' => $expected, 'got' => $got, 'pass' => $got === $expected];
	if ($got !== $expected) { $bad++; }
}
// Recipe deletion cascades to fills, orders, settings (ADR section 7, last bullet).
$pdo->exec("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES ($r2, '2026-03-01')");
$pdo->exec("INSERT INTO refill_settings (recipe_id, explicit_date, explicit_fill_id) VALUES ($r2, '2026-03-01', $otherFill)");
$before = $pdo->query("SELECT (SELECT count(*) FROM refill_fills WHERE recipe_id = $r2), (SELECT count(*) FROM refill_orders WHERE recipe_id = $r2), (SELECT count(*) FROM refill_settings WHERE recipe_id = $r2)")->fetch(PDO::FETCH_NUM);
$del = attempt($pdo, "DELETE FROM recipes WHERE id = $r2");
$after = $pdo->query("SELECT (SELECT count(*) FROM refill_fills WHERE recipe_id = $r2), (SELECT count(*) FROM refill_orders WHERE recipe_id = $r2), (SELECT count(*) FROM refill_settings WHERE recipe_id = $r2)")->fetch(PDO::FETCH_NUM);
$out['declarative_constraints'] = ['cases' => count($results), 'unexpected' => $bad, 'results' => $results,
	'recipe_delete_cascade' => ['delete' => $del, 'rows_before_fills_orders_settings' => array_map('intval', $before), 'rows_after' => array_map('intval', $after)]];

// ---- helpers for races -----------------------------------------------------------------------
function race(PDO $pdo, string $schema, string $mode, array $argsList): array
{
	$n = count($argsList);
	$pdo->exec('SELECT pg_advisory_lock(4242)'); // parent holds it; children block on the shared lock
	$env = array_merge(getenv(), ['ADR42_SCHEMA' => $schema]);
	$procs = [];
	foreach ($argsList as $i => $args)
	{
		$p = proc_open([PHP_BINARY, '-d', 'date.timezone=UTC', __DIR__ . '/conc-child.php', $mode, json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$procs[$i] = ['proc' => $p, 'out' => $pipes[1], 'err' => $pipes[2]];
	}
	foreach ($procs as $i => $p) { $line = fgets($p['out']); if (trim((string)$line) !== 'READY') { throw new RuntimeException("child $i not ready: " . stream_get_contents($p['err'])); } }
	$deadline = microtime(true) + 15;
	do
	{
		$waiting = (int)$pdo->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND ((classid::bigint << 32) | objid::bigint) = 4242")->fetchColumn();
		if ($waiting >= $n) { break; }
		usleep(20000);
	} while (microtime(true) < $deadline);
	$out = ['all_waiting_before_release' => $waiting >= $n, 'waiting' => $waiting];
	$pdo->exec('SELECT pg_advisory_unlock(4242)');
	$out['children'] = [];
	foreach ($procs as $i => $p)
	{
		$first = trim((string)fgets($p['out']));
		$res = json_decode($first, true) ?? ['raw' => $first];
		if (in_array($mode, ['receive_then_hang', 'receive_autocommit_then_hang'], true))
		{
			proc_terminate($p['proc'], 9); // SIGKILL between the two writes
		}
		else
		{
			stream_get_contents($p['out']);
		}
		proc_close($p['proc']);
		$out['children'][] = $res;
	}
	return $out;
}

function waitBackendsGone(PDO $pdo): void
{
	for ($i = 0; $i < 100; $i++)
	{
		$n = (int)$pdo->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND state IN ('idle in transaction', 'active')")->fetchColumn();
		if ($n === 0) { return; }
		usleep(100000);
	}
}

// ---- 2. One open order per recipe under contention -----------------------------------------
$orderRuns = [];
foreach ([2 => 40, 8 => 20, 32 => 20] as $n => $rounds)
{
	$exactlyOne = 0; $sqlstates = []; $notAllWaiting = 0; $openRows = [];
	for ($round = 0; $round < $rounds; $round++)
	{
		$rid = newRecipe($pdo);
		$res = race($pdo, $schema, 'order', array_fill(0, $n, ['recipe' => $rid]));
		if (!$res['all_waiting_before_release']) { $notAllWaiting++; }
		$ok = 0;
		foreach ($res['children'] as $c)
		{
			if (($c['result'] ?? '') === 'inserted') { $ok++; $sqlstates['inserted'] = ($sqlstates['inserted'] ?? 0) + 1; }
			elseif (($c['result'] ?? '') === 'error') { $sqlstates[$c['sqlstate']] = ($sqlstates[$c['sqlstate']] ?? 0) + 1; }
		}
		$open = (int)$pdo->query("SELECT count(*) FROM refill_orders WHERE recipe_id = $rid AND state = 'open'")->fetchColumn();
		$openRows[] = $open;
		if ($ok === 1 && $open === 1) { $exactlyOne++; }
	}
	$orderRuns[] = ['concurrent_inserts' => $n, 'rounds' => $rounds, 'rounds_with_exactly_one_success_and_one_open_row' => $exactlyOne,
		'outcome_counts_over_all_children' => $sqlstates, 'rounds_where_not_all_children_were_waiting_at_release' => $notAllWaiting];
}
$out['one_open_order_per_recipe'] = $orderRuns;

// ---- 3. Receive = close order + insert fill, atomically --------------------------------------
$receive = [];
// 3a. normal path
$rid = newRecipe($pdo);
$pdo->exec("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES ($rid, '2026-03-01')");
$oid = (int)$pdo->query("SELECT id FROM refill_orders WHERE recipe_id = $rid")->fetchColumn();
$res = race($pdo, $schema, 'receive', [['recipe' => $rid, 'order' => $oid]]);
$receive['normal'] = ['child' => $res['children'][0], 'order' => $pdo->query("SELECT state, received_fill_id IS NOT NULL AS has_fill FROM refill_orders WHERE id = $oid")->fetch(PDO::FETCH_ASSOC),
	'fills' => (int)$pdo->query("SELECT count(*) FROM refill_fills WHERE recipe_id = $rid")->fetchColumn()];
// 3b. SIGKILL between the two writes, inside a transaction
$rid = newRecipe($pdo);
$pdo->exec("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES ($rid, '2026-03-01')");
$oid = (int)$pdo->query("SELECT id FROM refill_orders WHERE recipe_id = $rid")->fetchColumn();
$res = race($pdo, $schema, 'receive_then_hang', [['recipe' => $rid, 'order' => $oid]]);
waitBackendsGone($pdo);
$receive['killed_inside_transaction'] = ['child' => $res['children'][0], 'order_state_after' => $pdo->query("SELECT state FROM refill_orders WHERE id = $oid")->fetchColumn(),
	'fills_after' => (int)$pdo->query("SELECT count(*) FROM refill_fills WHERE recipe_id = $rid")->fetchColumn(), 'nothing_half_applied' => null];
$receive['killed_inside_transaction']['nothing_half_applied'] = $receive['killed_inside_transaction']['order_state_after'] === 'open' && $receive['killed_inside_transaction']['fills_after'] === 0;
// 3c. control: the same writes without a transaction
$rid = newRecipe($pdo);
$pdo->exec("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES ($rid, '2026-03-01')");
$oid = (int)$pdo->query("SELECT id FROM refill_orders WHERE recipe_id = $rid")->fetchColumn();
$res = race($pdo, $schema, 'receive_autocommit_then_hang', [['recipe' => $rid, 'order' => $oid]]);
waitBackendsGone($pdo);
$receive['control_autocommit_killed_between_writes'] = ['order_state_after' => $pdo->query("SELECT state FROM refill_orders WHERE id = $oid")->fetchColumn(),
	'fills_after' => (int)$pdo->query("SELECT count(*) FROM refill_fills WHERE recipe_id = $rid")->fetchColumn(), 'half_applied' => null];
$receive['control_autocommit_killed_between_writes']['half_applied'] = $receive['control_autocommit_killed_between_writes']['order_state_after'] === 'open' && $receive['control_autocommit_killed_between_writes']['fills_after'] === 1;
// 3d. the second statement fails inside the transaction
$rid = newRecipe($pdo);
$pdo->exec("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES ($rid, '2026-03-01')");
$oid = (int)$pdo->query("SELECT id FROM refill_orders WHERE recipe_id = $rid")->fetchColumn();
$pdo->beginTransaction();
$pdo->exec("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES ($rid, '2026-03-02', 30)");
$err = attempt($pdo, "UPDATE refill_orders SET state = 'received' WHERE id = $oid"); // forgot received_fill_id: violates received_has_fill
$pdo->rollBack();
$receive['second_statement_fails_then_rollback'] = ['second_statement' => $err, 'order_state_after' => $pdo->query("SELECT state FROM refill_orders WHERE id = $oid")->fetchColumn(),
	'fills_after' => (int)$pdo->query("SELECT count(*) FROM refill_fills WHERE recipe_id = $rid")->fetchColumn()];
// 3e. eight concurrent receives of one order
$conc = [];
for ($round = 0; $round < 10; $round++)
{
	$rid = newRecipe($pdo);
	$pdo->exec("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES ($rid, '2026-03-01')");
	$oid = (int)$pdo->query("SELECT id FROM refill_orders WHERE recipe_id = $rid")->fetchColumn();
	$res = race($pdo, $schema, 'receive', array_fill(0, 8, ['recipe' => $rid, 'order' => $oid]));
	$counts = [];
	foreach ($res['children'] as $c) { $counts[$c['result'] ?? 'x'] = ($counts[$c['result'] ?? 'x'] ?? 0) + 1; }
	$conc[] = ['outcomes' => $counts, 'fills_in_table' => (int)$pdo->query("SELECT count(*) FROM refill_fills WHERE recipe_id = $rid")->fetchColumn(),
		'order_state' => $pdo->query("SELECT state FROM refill_orders WHERE id = $oid")->fetchColumn()];
}
$receive['eight_concurrent_receives_of_one_order'] = ['rounds' => count($conc),
	'rounds_with_exactly_one_received_and_one_fill' => count(array_filter($conc, fn($c) => ($c['outcomes']['received'] ?? 0) === 1 && ($c['outcomes']['lost_race_rolled_back'] ?? 0) === 7 && $c['fills_in_table'] === 1 && $c['order_state'] === 'received')),
	'first_round' => $conc[0]];
$out['receive_order'] = $receive;

// ---- 4. Notice acknowledgement ---------------------------------------------------------------
$ackRuns = []; $allOneRow = 0; $errors = 0; $oneWinner = 0; $sameTimestamp = 0; $rounds = 20;
for ($round = 0; $round < $rounds; $round++)
{
	$key = "7:$round:approaching:2026-03-18";
	$res = race($pdo, $schema, 'ack', array_fill(0, 32, ['user' => 1, 'key' => $key]));
	$rows = (int)$pdo->query("SELECT count(*) FROM notice_acks WHERE user_id = 1 AND notice_key = " . $pdo->quote($key))->fetchColumn();
	$inserted = 0; $stamps = [];
	foreach ($res['children'] as $c)
	{
		if (($c['result'] ?? '') !== 'ok') { $errors++; continue; }
		$inserted += $c['rows_inserted']; $stamps[$c['acknowledged_at']] = true;
	}
	if ($rows === 1) { $allOneRow++; }
	if ($inserted === 1) { $oneWinner++; }
	if (count($stamps) === 1) { $sameTimestamp++; }
}
$out['notice_ack_idempotent'] = ['concurrent_identical_acks' => 32, 'rounds' => $rounds, 'rounds_with_exactly_one_row' => $allOneRow, 'rounds_with_exactly_one_inserting_call' => $oneWinner,
	'rounds_where_all_32_calls_read_the_same_acknowledged_at' => $sameTimestamp, 'calls_that_raised_an_error' => $errors];

// ---- 5. Notice keys across void and re-record --------------------------------------------------
function noticeKeys(PDO $pdo, int $recipe, string $asOf, int $user = 1): array
{
	$st = $pdo->prepare('SELECT n.kind, n.notice_key, (a.notice_key IS NOT NULL) AS acknowledged FROM adr42_notices(?::date, 7) n
		LEFT JOIN notice_acks a ON a.user_id = ? AND a.notice_key = n.notice_key WHERE n.recipe_id = ? ORDER BY n.kind');
	$st->execute([$asOf, $user, $recipe]);
	return array_map(fn($r) => ['kind' => $r['kind'], 'key' => $r['notice_key'], 'acknowledged' => (bool)$r['acknowledged']], $st->fetchAll(PDO::FETCH_ASSOC));
}
$keyLog = [];
$rid = newRecipe($pdo);
$f1 = (int)$pdo->query("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES ($rid, '2026-01-01', 90) RETURNING id")->fetchColumn();
$keyLog[] = ['step' => 'fill 1 (2026-01-01, 90 days), as_of 2026-03-12', 'notices' => noticeKeys($pdo, $rid, '2026-03-12')];
$pdo->prepare('INSERT INTO notice_acks (user_id, notice_key) VALUES (1, ?) ON CONFLICT DO NOTHING')->execute(["$rid:$f1:approaching:2026-03-18"]);
$keyLog[] = ['step' => 'user 1 acknowledges the approaching notice', 'notices' => noticeKeys($pdo, $rid, '2026-03-12')];
$keyLog[] = ['step' => 'same state, user 2 (sharer)', 'notices' => noticeKeys($pdo, $rid, '2026-03-12', 2)];
$pdo->exec("UPDATE refill_fills SET voided_at = clock_timestamp(), void_reason = 'typo' WHERE id = $f1");
$f2 = (int)$pdo->query("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES ($rid, '2026-01-01', 90) RETURNING id")->fetchColumn();
$keyLog[] = ['step' => 'fill 1 voided, identical fill 2 recorded (same date, same supply)', 'notices' => noticeKeys($pdo, $rid, '2026-03-12')];
$pdo->exec("UPDATE refill_fills SET voided_at = clock_timestamp(), void_reason = 'wrong date' WHERE id = $f2");
$f3 = (int)$pdo->query("INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES ($rid, '2026-01-05', 90) RETURNING id")->fetchColumn();
$keyLog[] = ['step' => 'fill 2 voided, fill 3 recorded with filled_on 2026-01-05 (reorder date moves to 2026-03-22), as_of 2026-03-16', 'notices' => noticeKeys($pdo, $rid, '2026-03-16')];
$keyLog[] = ['step' => 'as_of 2026-03-22 (due) with nothing acknowledged for fill 3', 'notices' => noticeKeys($pdo, $rid, '2026-03-22')];
$pdo->exec("INSERT INTO refill_settings (recipe_id, explicit_date, explicit_fill_id) VALUES ($rid, '2026-03-30', $f3)");
$keyLog[] = ['step' => 'explicit date 2026-03-30 set on fill 3, as_of 2026-03-25', 'notices' => noticeKeys($pdo, $rid, '2026-03-25')];
$pdo->exec("INSERT INTO refill_orders (recipe_id, ordered_on) VALUES ($rid, '2026-03-21')");
$keyLog[] = ['step' => 'open order recorded, as_of 2026-03-25', 'notices' => noticeKeys($pdo, $rid, '2026-03-25')];
$garbage = attempt($pdo, "INSERT INTO notice_acks (user_id, notice_key) VALUES (1, 'not-a-real-key') ON CONFLICT DO NOTHING");
$out['notice_keys'] = ['steps' => $keyLog, 'ack_of_a_key_no_notice_has_produced' => $garbage];

$admin->exec("DROP SCHEMA $schema CASCADE");
adr42_emit($out);
