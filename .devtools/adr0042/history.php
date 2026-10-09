<?php
// SPIKE ONLY. Probe 5 for ADR-0042: fill history. The current fill is the unvoided fill with the
// greatest filled_on, then the greatest id; voiding the current fill makes the previous one
// current; an explicit date bound to a voided fill stops applying. Expectations are written by
// hand from ADR sections 2 and 5. Output: JSON on stdout.
require __DIR__ . '/lib.php';

$admin = adr42_connect();
$schema = adr42_schema($admin, 'history');
$pdo = adr42_connect($schema);
adr42_load_sql($pdo, 'estimate.sql');
adr42_load_sql($pdo, 'model.sql');

$steps = [];
$failed = 0;
function recipe(PDO $pdo): int { return (int)$pdo->query('INSERT INTO recipes DEFAULT VALUES RETURNING id')->fetchColumn(); }
function fill(PDO $pdo, int $r, string $on, int $days): int
{
	$st = $pdo->prepare('INSERT INTO refill_fills (recipe_id, filled_on, supplied_days) VALUES (?, ?, ?) RETURNING id');
	$st->execute([$r, $on, $days]);
	return (int)$st->fetchColumn();
}
function voidFill(PDO $pdo, int $id): void { $pdo->prepare("UPDATE refill_fills SET voided_at = clock_timestamp(), void_reason = 'entered wrong' WHERE id = ?")->execute([$id]); }
function explicitDate(PDO $pdo, int $r, string $date, int $fillId): void
{
	$pdo->prepare('INSERT INTO refill_settings (recipe_id, explicit_date, explicit_fill_id) VALUES (?, ?, ?)
		ON CONFLICT (recipe_id) DO UPDATE SET explicit_date = EXCLUDED.explicit_date, explicit_fill_id = EXCLUDED.explicit_fill_id')->execute([$r, $date, $fillId]);
}
function state(PDO $pdo, int $r): array
{
	$st = $pdo->prepare('SELECT fill_id, filled_on::text, reorder::text, source, reason, explicit_applies FROM refill_state WHERE recipe_id = ?');
	$st->execute([$r]);
	$row = $st->fetch(PDO::FETCH_ASSOC);
	$hist = $pdo->prepare('SELECT id, filled_on::text, supplied_days, voided_at IS NOT NULL AS voided FROM refill_fills WHERE recipe_id = ? ORDER BY id');
	$hist->execute([$r]);
	return ['current_fill_id' => $row['fill_id'] === null ? null : (int)$row['fill_id'], 'current_filled_on' => $row['filled_on'], 'reorder_date' => $row['reorder'], 'source' => $row['source'],
		'reason' => $row['reason'], 'explicit_applies' => (bool)$row['explicit_applies'], 'history_rows_kept' => $hist->fetchAll(PDO::FETCH_ASSOC)];
}
function check(string $name, array $got, array $expect, array &$steps, int &$failed): void
{
	$ok = true; $diff = [];
	foreach ($expect as $k => $v) { if (($got[$k] ?? null) !== $v) { $ok = false; $diff[$k] = ['expected' => $v, 'got' => $got[$k] ?? null]; } }
	if (!$ok) { $failed++; }
	$steps[] = ['step' => $name, 'pass' => $ok, 'diff' => $diff, 'state' => $got];
}

// Scenario 1: the task's case. An explicit date bound to the current fill; a newer fill ends it;
// voiding that newer fill returns to the earlier fill.
$r = recipe($pdo);
$a = fill($pdo, $r, '2026-01-01', 90);
explicitDate($pdo, $r, '2026-03-01', $a);
check('1.1 explicit date bound to fill A (current)', state($pdo, $r), ['current_fill_id' => $a, 'reorder_date' => '2026-03-01', 'source' => 'explicit', 'explicit_applies' => true], $steps, $failed);
$b = fill($pdo, $r, '2026-03-25', 90);
check('1.2 newer fill B recorded: explicit bound to A stops applying, fallback on B', state($pdo, $r), ['current_fill_id' => $b, 'reorder_date' => '2026-06-09', 'source' => 'fallback', 'explicit_applies' => false], $steps, $failed);
voidFill($pdo, $b);
// Expectation from section 2 and 5 read together ("superseded ... stops applying"): the explicit
// date stays dead. The derived reading in section 7 ("ignored once the fill is not current")
// revives it. Record what the derived view does.
$s13 = state($pdo, $r);
check('1.3 B voided: A is current again; section 2/5 say a superseded explicit date stays ended', $s13, ['current_fill_id' => $a, 'source' => 'fallback', 'reorder_date' => '2026-03-18'], $steps, $failed);

// Scenario 2: an explicit date bound to the fill that is then voided.
$r2 = recipe($pdo);
$a2 = fill($pdo, $r2, '2026-01-01', 90);
$b2 = fill($pdo, $r2, '2026-04-01', 30);
explicitDate($pdo, $r2, '2026-04-20', $b2);
check('2.1 explicit date bound to current fill B', state($pdo, $r2), ['current_fill_id' => $b2, 'reorder_date' => '2026-04-20', 'source' => 'explicit', 'explicit_applies' => true], $steps, $failed);
voidFill($pdo, $b2);
check('2.2 B voided: A is current, explicit date bound to voided B ceases to apply, both fills kept', state($pdo, $r2),
	['current_fill_id' => $a2, 'reorder_date' => '2026-03-18', 'source' => 'fallback', 'explicit_applies' => false], $steps, $failed);
$st = state($pdo, $r2);
$steps[] = ['step' => '2.3 history keeps both rows', 'pass' => count($st['history_rows_kept']) === 2 && $st['history_rows_kept'][1]['voided'] === true];
if (!$steps[count($steps) - 1]['pass']) { $failed++; }

// Scenario 3: ties and backdating.
$r3 = recipe($pdo);
$x = fill($pdo, $r3, '2026-02-15', 30);
$y = fill($pdo, $r3, '2026-02-15', 30);
check('3.1 same filled_on: greatest id is current', state($pdo, $r3), ['current_fill_id' => $y], $steps, $failed);
$z = fill($pdo, $r3, '2025-12-01', 30);
check('3.2 a backdated fill recorded last is in history but not current', state($pdo, $r3), ['current_fill_id' => $y, 'current_filled_on' => '2026-02-15'], $steps, $failed);
voidFill($pdo, $y);
check('3.3 tie member voided: the other member is current', state($pdo, $r3), ['current_fill_id' => $x], $steps, $failed);
voidFill($pdo, $x);
check('3.4 only the backdated fill is left unvoided: it is current', state($pdo, $r3), ['current_fill_id' => $z, 'current_filled_on' => '2025-12-01'], $steps, $failed);
voidFill($pdo, $z);
check('3.5 every fill voided: unknown, no_fill', state($pdo, $r3), ['current_fill_id' => null, 'reorder_date' => null, 'reason' => 'no_fill'], $steps, $failed);

// Scenario 4: a backdated fill does not end an explicit date bound to the current fill.
$r4 = recipe($pdo);
$a4 = fill($pdo, $r4, '2026-02-01', 90);
explicitDate($pdo, $r4, '2026-04-10', $a4);
fill($pdo, $r4, '2025-11-01', 90);
check('4.1 backdated fill: explicit date on the still-current fill keeps applying', state($pdo, $r4), ['current_fill_id' => $a4, 'reorder_date' => '2026-04-10', 'explicit_applies' => true], $steps, $failed);

// Scenario 5: the recorded fill is not the one a voided explicit date was set on, but one with the
// same date and length: a re-record. The explicit date belongs to the voided row, not the new one.
$r5 = recipe($pdo);
$a5 = fill($pdo, $r5, '2026-01-01', 90);
explicitDate($pdo, $r5, '2026-03-01', $a5);
voidFill($pdo, $a5);
$a5b = fill($pdo, $r5, '2026-01-01', 90);
check('5.1 void and re-record identical fill: explicit date does not carry over', state($pdo, $r5), ['current_fill_id' => $a5b, 'source' => 'fallback', 'reorder_date' => '2026-03-18', 'explicit_applies' => false], $steps, $failed);

$admin->exec("DROP SCHEMA $schema CASCADE");
adr42_emit([
	'probe' => 'history',
	'environment' => adr42_environment($pdo),
	'steps' => count($steps),
	'steps_failing_the_hand_written_expectation' => $failed,
	'failing_steps' => array_values(array_map(fn($s) => ['step' => $s['step'], 'diff' => $s['diff'] ?? null], array_filter($steps, fn($s) => !$s['pass']))),
	'results' => $steps,
]);
