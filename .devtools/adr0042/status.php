<?php
// SPIKE ONLY. Probe 2 for ADR-0042: the status boundary table of section 4, evaluated in PHP and
// in SQL for T in R-L-1, R-L, R-1, R, R+3 and L in {0, 1, 7, 60}, against expectations written
// by hand from the ADR's section 4 table (not derived from the code under test).
// Output: JSON on stdout.
require __DIR__ . '/lib.php';

function phpStatus(?string $reorder, int $lead, string $asOf, bool $openOrder): array
{
	if ($openOrder) { return ['ordered', null]; }
	if ($reorder === null) { return ['unknown', null]; }
	$day = fn(string $d) => (int)(DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'))->getTimestamp() / 86400);
	$k = $day($asOf) - $day($reorder);
	if ($k >= 0) { return ['due', $k]; }
	return $k >= -$lead ? ['approaching', null] : ['ok', null];
}

function shift(string $d, int $n): string
{
	return DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

// Hand-written from section 4: ok if T < R-L; approaching if R-L <= T < R; due if T >= R.
// Cell key: "<L>|<T label>" => [status, days_overdue].
$expected = [];
foreach ([0, 1, 7, 60] as $L)
{
	foreach (['R-L-1', 'R-L', 'R-1', 'R', 'R+3'] as $label)
	{
		$offset = ['R-L-1' => -$L - 1, 'R-L' => -$L, 'R-1' => -1, 'R' => 0, 'R+3' => 3][$label];
		// offset = T - R. Written out as the ADR's three rows, not as code shared with phpStatus().
		if ($offset < -$L) { $e = ['ok', null]; }
		elseif ($offset < 0) { $e = ['approaching', null]; }
		else { $e = ['due', $offset]; }
		$expected["$L|$label"] = $e;
	}
}
// The ADR's verification-case row, for T on R-L-1, R-L, R-1, R, R+3 (generic, written for L >= 2).
$verificationRow = ['R-L-1' => ['ok', null], 'R-L' => ['approaching', null], 'R-1' => ['approaching', null], 'R' => ['due', 0], 'R+3' => ['due', 3]];

$admin = adr42_connect();
$schema = adr42_schema($admin, 'status');
$pdo = adr42_connect($schema);
adr42_load_sql($pdo, 'estimate.sql');

$table = [];
$mismatch = 0;
$verificationRowDiffers = [];
foreach (['2026-03-18', '2028-03-01', '2026-01-05', '2027-03-01'] as $R) // plain, leap-adjacent, crosses a year end at R-60, crosses a month end
{
	foreach ([0, 1, 7, 60] as $L)
	{
		foreach (['R-L-1' => -$L - 1, 'R-L' => -$L, 'R-1' => -1, 'R' => 0, 'R+3' => 3] as $label => $off)
		{
			$T = shift($R, $off);
			[$ps, $po] = phpStatus($R, $L, $T, false);
			$sqlRow = $pdo->query("SELECT status, days_overdue, warning_date::text FROM adr42_status(DATE '$R', $L, DATE '$T', false)")->fetch(PDO::FETCH_NUM);
			[$es, $eo] = $expected["$L|$label"];
			$ok = $ps === $es && $po === $eo && $sqlRow[0] === $es && ($sqlRow[1] === null ? null : (int)$sqlRow[1]) === $eo && $sqlRow[2] === shift($R, -$L);
			if (!$ok) { $mismatch++; }
			if ($R === '2026-03-18' && $verificationRow[$label] !== [$es, $eo])
			{
				$verificationRowDiffers[] = ['L' => $L, 'T' => $label, 'verification_row_says' => $verificationRow[$label], 'section_4_says' => [$es, $eo]];
			}
			// With an open order every cell is "ordered".
			$ordered = $pdo->query("SELECT status, days_overdue FROM adr42_status(DATE '$R', $L, DATE '$T', true)")->fetch(PDO::FETCH_NUM);
			$table[] = ['R' => $R, 'L' => $L, 'T_label' => $label, 'T' => $T, 'php' => [$ps, $po], 'sql' => [$sqlRow[0], $sqlRow[1] === null ? null : (int)$sqlRow[1]], 'expected_section_4' => [$es, $eo],
				'agree' => $ok, 'with_open_order' => [$ordered[0], $ordered[1]], 'warning_date' => $sqlRow[2]];
			if ($ordered[0] !== 'ordered') { $mismatch++; }
		}
	}
}
$unknown = $pdo->query("SELECT status FROM adr42_status(NULL, 7, DATE '2026-03-18', false)")->fetchColumn();

// "as_of in another zone's date": one instant, the dates it has in three zones, and the status each gives
// for the same stored reorder date.
$instant = new DateTimeImmutable('2026-03-17 23:30:00', new DateTimeZone('America/New_York'));
$byZone = [];
foreach (['America/New_York', 'UTC', 'Pacific/Kiritimati'] as $z)
{
	$asOf = $instant->setTimezone(new DateTimeZone($z))->format('Y-m-d');
	$s = $pdo->query("SELECT status, days_overdue FROM adr42_status(DATE '2026-03-18', 7, DATE '$asOf', false)")->fetch(PDO::FETCH_NUM);
	$byZone[$z] = ['as_of' => $asOf, 'status' => $s[0], 'days_overdue' => $s[1]];
}

// Cases the ADR does not decide, evaluated with the choices in estimate.sql, so the gaps are concrete.
$edge = [];
$q = fn(string $sql) => $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
$edge['fraction_elapsed_giving_zero_days (supplied 1, F 0.5)'] = $q("SELECT reorder::text, source, reason FROM adr42_estimate(NULL, 'fraction_elapsed', 0.5, DATE '2026-01-01', 1)");
$edge['fraction_elapsed_giving_zero_days_status_on_the_fill_date'] = $q("SELECT status, days_overdue FROM adr42_status(DATE '2026-01-01', 7, DATE '2026-01-01', false)");
$edge['fixed_interval_with_supplied_days_shorter_than_interval (D 60, supplied 30)'] = $q("SELECT reorder::text, source, reason FROM adr42_estimate(NULL, 'fixed_interval', 60, DATE '2026-01-01', 30)");
$edge['explicit_date_before_the_fill_date (no CHECK in section 7)'] = $q("SELECT reorder::text, source, reason FROM adr42_estimate(DATE '2025-06-01', NULL, NULL, DATE '2026-01-01', 30)");
$edge['days_before_end_N_equals_supplied (N 30, supplied 30)'] = $q("SELECT reorder::text, source, reason FROM adr42_estimate(NULL, 'days_before_end', 30, DATE '2026-01-01', 30)");
$edge['supplied_15_fallback'] = $q("SELECT reorder::text, source, reason FROM adr42_estimate(NULL, NULL, NULL, DATE '2026-01-01', 15)");
$edge['supplied_14_fallback'] = $q("SELECT reorder::text, source, reason FROM adr42_estimate(NULL, NULL, NULL, DATE '2026-01-01', 14)");
$edge['both_invalid_supply_and_invalid_rule (which reason?)'] = $q("SELECT reorder::text, source, reason FROM adr42_estimate(NULL, 'fixed_interval', 0, DATE '2026-01-01', 0)");
$edge['open_order_days_overdue_when_past_reorder_date'] = $q("SELECT status, days_overdue FROM adr42_status(DATE '2026-03-18', 7, DATE '2026-03-25', true)");
$edge['lead_out_of_range_is_not_validated_by_section_4 (L 61)'] = $q("SELECT status, warning_date::text FROM adr42_status(DATE '2026-03-18', 61, DATE '2026-01-16', false)");

$admin->exec("DROP SCHEMA $schema CASCADE");
adr42_emit([
	'edge_cases_not_decided_by_the_adr' => $edge,
	'probe' => 'status',
	'environment' => adr42_environment($pdo),
	'cells' => count($table),
	'cells_disagreeing_with_section_4_or_php_or_sql' => $mismatch,
	'unknown_when_reorder_null' => $unknown,
	'verification_row_vs_section_4' => $verificationRowDiffers,
	'one_instant_three_zones' => ['instant' => '2026-03-17T23:30:00-04:00 (America/New_York)', 'reorder_date' => '2026-03-18', 'lead' => 7, 'result' => $byZone],
	'table' => $table,
]);
