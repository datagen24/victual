<?php
// SPIKE ONLY. Probe 3 for ADR-0042: do PHP's date('Y-m-d') and PostgreSQL's CURRENT_DATE agree?
// No clock can be injected into either side, so this probe does three things:
//   A. facts: where each side gets its zone (the real PostgresDialect::OnConnected() is used);
//   B. a live sample of date('Y-m-d') against CURRENT_DATE on app-style and bare connections;
//   C. the conversion CURRENT_DATE performs (timestamptz::date in the session zone) against PHP's
//      date() over a one-minute grid of real instants straddling local midnight, and over a
//      30-minute grid of 2026 and an hourly grid of 2027 for every zone name PHP knows.
// Output: JSON on stdout.
require __DIR__ . '/lib.php';
require '/app/packages/autoload.php';

use Victual\Services\Database\PostgresDialect;

function appStyleConnection(): PDO
{
	$pdo = adr42_connect();
	(new PostgresDialect())->OnConnected($pdo); // SET TIME ZONE <date_default_timezone_get()>, as every application connection does
	return $pdo;
}

/** PostgreSQL's date for each epoch second in this session's zone: the cast CURRENT_DATE performs on now(). */
function pgDates(PDO $pdo, array $ts): array
{
	$st = $pdo->prepare('SELECT to_timestamp(t)::date::text FROM unnest(?::bigint[]) WITH ORDINALITY AS u(t, o) ORDER BY o');
	$st->execute(['{' . implode(',', $ts) . '}']);
	return $st->fetchAll(PDO::FETCH_COLUMN);
}

$out = ['probe' => 'today'];

// ---- A. Facts -----------------------------------------------------------------------------
$bare = adr42_connect();
$out['facts'] = [
	'php_default_zone_in_this_run' => date_default_timezone_get(),
	'shipped_image_php_ini_date_timezone' => trim((string)shell_exec('php -r \'echo ini_get("date.timezone");\' 2>&1')),
	'TZ_America_New_York_does_php_follow_TZ' => trim((string)shell_exec('TZ=America/New_York php -r \'echo date_default_timezone_get();\' 2>&1')),
	'postgres_bare_connection_TimeZone' => $bare->query('SHOW TimeZone')->fetchColumn(),
	'postgres_log_timezone' => $bare->query('SHOW log_timezone')->fetchColumn(),
	'postgres_dialect_statement' => 'services/Database/PostgresDialect.php:131  $pdo->exec("SET TIME ZONE " . $pdo->quote(date_default_timezone_get()));',
	'php_tzdata' => timezone_version_get(),
	'postgres_version' => $bare->query('SHOW server_version')->fetchColumn(),
];

// ---- B. Live sample -----------------------------------------------------------------------
$live = [];
foreach (['America/New_York', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'UTC'] as $z)
{
	date_default_timezone_set($z);
	$app = appStyleConnection();
	$bareNow = adr42_connect();
	$r = ['php_zone' => $z, 'php_date_Y-m-d' => date('Y-m-d'), 'php_time' => date('H:i:s'),
		'app_style_session_zone' => $app->query('SHOW TimeZone')->fetchColumn(),
		'app_style_CURRENT_DATE' => $app->query('SELECT CURRENT_DATE::text')->fetchColumn(),
		'bare_session_zone' => $bareNow->query('SHOW TimeZone')->fetchColumn(),
		'bare_CURRENT_DATE' => $bareNow->query('SELECT CURRENT_DATE::text')->fetchColumn(),
		'app_style_matches_php' => null, 'bare_matches_php' => null];
	$r['app_style_matches_php'] = $r['app_style_CURRENT_DATE'] === $r['php_date_Y-m-d'];
	$r['bare_matches_php'] = $r['bare_CURRENT_DATE'] === $r['php_date_Y-m-d'];
	$live[] = $r;
}
date_default_timezone_set('UTC');
$out['live_sample_at_run_time_utc'] = gmdate('c');
$out['live_sample'] = $live;

// ---- C1. Grid around local midnight ----------------------------------------------------------
$bareZone = $out['facts']['postgres_bare_connection_TimeZone'];
$scans = [
	['America/New_York', ['2026-03-07', '2026-03-08', '2026-10-09', '2026-11-01']],
	['Pacific/Kiritimati', ['2026-10-09', '1994-12-30', '1994-12-31']],
	['Pacific/Pago_Pago', ['2026-10-09']],
	['Pacific/Apia', ['2011-12-29', '2011-12-30', '2011-12-31']],
	['Australia/Lord_Howe', ['2026-04-05', '2026-10-04']],
	['UTC', ['2026-10-09']],
];
$windows = [];
$totals = ['points' => 0, 'app_style_disagree' => 0, 'bare_disagree' => 0];
foreach ($scans as [$z, $dates])
{
	date_default_timezone_set($z);
	$app = appStyleConnection();
	$bareScan = adr42_connect();
	foreach ($dates as $d)
	{
		$mid = (new DateTimeImmutable($d . ' 00:00:00', new DateTimeZone($z)))->getTimestamp();
		$ts = range($mid - 30 * 3600, $mid + 54 * 3600, 60); // 84 hours around local midnight at 1-minute steps
		$php = array_map(fn($t) => date('Y-m-d', $t), $ts);
		$modes = ['app_style' => pgDates($app, $ts), 'bare' => pgDates($bareScan, $ts)];
		$row = ['zone' => $z, 'around_local_midnight_of' => $d, 'points' => count($ts)];
		foreach ($modes as $mode => $pg)
		{
			$runs = []; $cur = null; $dis = 0;
			foreach ($ts as $i => $t)
			{
				if ($pg[$i] !== $php[$i])
				{
					$dis++;
					$entry = ['php' => $php[$i], 'postgres' => $pg[$i]];
					if ($cur !== null && $cur['php'] === $entry['php'] && $cur['postgres'] === $entry['postgres'] && $cur['_end'] === $t - 60) { $cur['_end'] = $t; $cur['minutes']++; }
					else { if ($cur !== null) { $runs[] = $cur; } $cur = $entry + ['_start' => $t, '_end' => $t, 'minutes' => 1]; }
				}
			}
			if ($cur !== null) { $runs[] = $cur; }
			$fmt = fn($t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone($z))->format('Y-m-d H:i T');
			$row[$mode] = ['disagreeing_minutes' => $dis, 'windows' => array_map(fn($w) => ['php_date' => $w['php'], 'postgres_date' => $w['postgres'], 'from_local' => $fmt($w['_start']), 'to_local' => $fmt($w['_end']), 'minutes' => $w['minutes']], $runs)];
			$totals['points'] += ($mode === 'app_style' ? count($ts) : 0);
			$totals[$mode . '_disagree'] += $dis;
		}
		$windows[] = $row;
	}
}
date_default_timezone_set('UTC');
$out['bare_connection_zone_used_in_grid'] = $bareZone;
$out['midnight_grid'] = $windows;
$out['midnight_grid_totals'] = $totals;

// ---- C2. Every zone PHP knows ----------------------------------------------------------------
$names = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
$known = array_flip($bare->query('SELECT name FROM pg_timezone_names')->fetchAll(PDO::FETCH_COLUMN));
$grid = [];
for ($t = gmmktime(0, 0, 0, 1, 1, 2026); $t < gmmktime(0, 0, 0, 1, 1, 2027); $t += 1800) { $grid[] = $t; }
for ($t = gmmktime(0, 0, 0, 1, 1, 2027); $t < gmmktime(0, 0, 0, 1, 1, 2028); $t += 3600) { $grid[] = $t; }
$unknownToPg = []; $disagree = []; $compared = 0; $points = 0;
$cmp = $bare->prepare('SELECT count(*), min(t) FROM unnest(?::bigint[], ?::text[]) AS u(t, d) WHERE to_timestamp(t)::date::text <> d');
foreach ($names as $z)
{
	if (!isset($known[$z])) { $unknownToPg[] = $z; continue; }
	date_default_timezone_set($z);
	$bare->exec('SET TIME ZONE ' . $bare->quote($z));
	$php = array_map(fn($t) => date('Y-m-d', $t), $grid);
	$cmp->execute(['{' . implode(',', $grid) . '}', '{' . implode(',', $php) . '}']);
	[$count, $first] = $cmp->fetch(PDO::FETCH_NUM);
	$compared++; $points += count($grid);
	if ((int)$count > 0) { $disagree[] = ['zone' => $z, 'disagreeing_points' => (int)$count, 'first_utc' => gmdate('c', (int)$first)]; }
}
date_default_timezone_set('UTC');
$out['all_zones_2026_2027'] = [
	'zones_known_to_php' => count($names), 'zones_compared' => $compared, 'zones_php_has_and_postgres_lacks' => $unknownToPg,
	'instants_per_zone' => count($grid), 'instants_compared' => $points, 'zones_with_disagreement' => count($disagree), 'disagreements' => array_slice($disagree, 0, 20),
	'tzdata' => ['php' => timezone_version_get(), 'postgres_pg_timezone_names_rows' => (int)$bare->query('SELECT count(*) FROM pg_timezone_names')->fetchColumn()],
];

// ---- D. Transaction semantics --------------------------------------------------------------
$tx = adr42_connect();
$tx->beginTransaction();
$first = $tx->query("SELECT now()::text, clock_timestamp()::text, CURRENT_DATE::text")->fetch(PDO::FETCH_NUM);
usleep(1500000);
$second = $tx->query("SELECT now()::text, clock_timestamp()::text, CURRENT_DATE::text")->fetch(PDO::FETCH_NUM);
$tx->commit();
$out['transaction_semantics'] = [
	'first' => ['now' => $first[0], 'clock_timestamp' => $first[1], 'CURRENT_DATE' => $first[2]],
	'after_1_5_seconds_in_same_transaction' => ['now' => $second[0], 'clock_timestamp' => $second[1], 'CURRENT_DATE' => $second[2]],
	'now_unchanged' => $first[0] === $second[0], 'clock_timestamp_advanced' => $first[1] !== $second[1],
	'consequence' => 'CURRENT_DATE is derived from now(), the transaction start. date() reads the clock at the call.',
];

adr42_emit($out);
