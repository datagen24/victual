<?php
// SPIKE ONLY. Probe 1 for ADR-0042: the estimate function in PHP and as SQL date + integer,
// over the ADR's examples and a seeded random set. Run once per PHP zone:
//   php -d date.timezone=<zone> dates.php     (PHP_TIMEZONE must name the same zone)
// Output: JSON on stdout.
require __DIR__ . '/lib.php';

$zone = getenv('PHP_TIMEZONE') ?: 'UTC';
if (date_default_timezone_get() !== $zone)
{
	fwrite(STDERR, "PHP_TIMEZONE=$zone but PHP's default zone is " . date_default_timezone_get() . "\n");
	exit(1);
}

const SEED = 20261009;
const UNIFORM = 60000;
const FIXED_DENOMINATORS = [100, 1000];

// ---------------------------------------------------------------------------------------------
// PHP side: the ADR's decision, then four ways to add whole days to a stored DATE.
// ---------------------------------------------------------------------------------------------

/** Decision: returns [days_after_fill|null, source|null, reason|null, explicit|null]. */
function decide(array $c, string $fractionMode): array
{
	if ($c['filled'] === null) { return [null, null, 'no_fill', null]; }
	if ($c['explicit'] !== null) { return [null, 'explicit', null, $c['explicit']]; }
	$s = $c['supplied'];
	if ($s === null || $s < 1 || $s > 730) { return [null, null, 'invalid_supply', null]; }
	$rule = $c['rule'];
	$p = $c['rule_n'];
	if ($rule === null)
	{
		return $s <= 14 ? [null, null, 'supply_not_longer_than_lead', null] : [$s - 14, 'fallback', null, null];
	}
	if ($rule === 'days_before_end')
	{
		if ($p === null || preg_match('/^\d+$/', $p) !== 1 || (int)$p > 730) { return [null, null, 'invalid_rule', null]; }
		return $s <= (int)$p ? [null, null, 'supply_not_longer_than_lead', null] : [$s - (int)$p, 'rule:days_before_end', null, null];
	}
	if ($rule === 'fixed_interval')
	{
		if ($p === null || preg_match('/^\d+$/', $p) !== 1 || (int)$p < 1 || (int)$p > 730) { return [null, null, 'invalid_rule', null]; }
		return [(int)$p, 'rule:fixed_interval', null, null];
	}
	if ($rule === 'fraction_elapsed')
	{
		if ($p === null || preg_match('/^(\d+)(?:\.(\d+))?$/', $p, $m) !== 1) { return [null, null, 'invalid_rule', null]; }
		$whole = (int)$m[1];
		$frac = $m[2] ?? '0';
		if ($whole >= 1 || (int)$frac === 0) { return [null, null, 'invalid_rule', null]; }
		$days = $fractionMode === 'float'
			? (int)floor($s * (float)$p)
			: intdiv($s * (int)$frac, 10 ** strlen($frac));
		return [$days, 'rule:fraction_elapsed', null, null];
	}
	return [null, null, 'invalid_rule', null];
}

/** Four ways of "filled + n whole days" for a stored YYYY-MM-DD. */
function addDays(string $variant, string $filled, int $n): string
{
	switch ($variant)
	{
		case 'utc_calendar': // the construction the task and the ADR describe
			return DateTimeImmutable::createFromFormat('!Y-m-d', $filled, new DateTimeZone('UTC'))
				->add(new DateInterval('P' . $n . 'D'))->format('Y-m-d');
		case 'default_zone_calendar': // same, anchored at midnight in PHP's default zone
			$d = DateTimeImmutable::createFromFormat('!Y-m-d', $filled);
			return $d === false ? 'PARSE_ERROR' : $d->add(new DateInterval('P' . $n . 'D'))->format('Y-m-d');
		case 'strtotime_days': // what StockService::GetDueProducts() does: date('Y-m-d', strtotime('N days'))
			return date('Y-m-d', strtotime($filled . ' +' . $n . ' days'));
		case 'seconds_86400': // negative control: calendar days as 86400-second steps
			return date('Y-m-d', strtotime($filled) + $n * 86400);
	}
	throw new LogicException($variant);
}

const VARIANTS = ['utc_calendar', 'default_zone_calendar', 'strtotime_days', 'seconds_86400'];

// ---------------------------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------------------------
mt_srand(SEED);

function dayPool(): array
{
	$pool = ['leap_feb' => [], 'year_end' => [], 'month_end' => [], 'dst' => [], 'skipped_day' => []];
	for ($y = 1990; $y <= 2060; $y++)
	{
		$leap = checkdate(2, 29, $y);
		foreach ($leap ? [27, 28, 29] : [27, 28] as $d) { $pool['leap_feb'][] = sprintf('%04d-02-%02d', $y, $d); }
		$pool['leap_feb'][] = sprintf('%04d-03-01', $y);
		foreach ([20, 28, 29, 30, 31] as $d) { $pool['year_end'][] = sprintf('%04d-12-%02d', $y, $d); }
		foreach ([1, 2, 3] as $d) { $pool['year_end'][] = sprintf('%04d-01-%02d', $y, $d); }
		for ($m = 1; $m <= 12; $m++)
		{
			$last = (int)gmdate('t', gmmktime(12, 0, 0, $m, 1, $y)); // calendar fact; UTC so the case set cannot depend on the zone
			$pool['month_end'][] = sprintf('%04d-%02d-%02d', $y, $m, $last);
			$pool['month_end'][] = sprintf('%04d-%02d-01', $y, $m);
			$pool['month_end'][] = sprintf('%04d-%02d-%02d', $y, $m, $last - 1);
		}
	}
	// Local dates of every UTC-offset transition 1990..2060 in a fixed list of zones (the list does
	// not depend on the zone this run uses, so the case set is identical across runs), +-1 day.
	foreach (['America/New_York', 'Europe/London', 'Australia/Lord_Howe', 'Pacific/Apia', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'America/Sao_Paulo', 'Asia/Tehran'] as $name)
	{
		$tz = new DateTimeZone($name);
		foreach ($tz->getTransitions(strtotime('1990-01-01 UTC'), strtotime('2060-12-31 UTC')) as $t)
		{
			$local = (new DateTimeImmutable('@' . $t['ts']))->setTimezone($tz);
			foreach ([-1, 0, 1] as $off)
			{
				$pool['dst'][] = $local->modify($off . ' days')->format('Y-m-d');
			}
		}
	}
	$pool['dst'] = array_values(array_unique($pool['dst']));
	// Calendar days that did not exist in a local zone: Pacific/Kiritimati skipped 1994-12-31,
	// Pacific/Apia skipped 2011-12-30.
	foreach (['1994-12-29', '1994-12-30', '1994-12-31', '1995-01-01', '2011-12-28', '2011-12-29', '2011-12-30', '2011-12-31', '2012-01-01'] as $d)
	{
		$pool['skipped_day'][] = $d;
	}
	return $pool;
}

function pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }

function randomDate(): string
{
	return gmdate('Y-m-d', gmmktime(12, 0, 0, 1, 1, 1990) + mt_rand(0, (int)((strtotime('2060-12-31 UTC') - strtotime('1990-01-01 UTC')) / 86400)) * 86400);
}

function utcAdd(string $d, int $n): string
{
	return DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

function makeCase(string $date, string $cat): array
{
	$supplyPool = [7, 10, 14, 15, 21, 28, 30, 45, 60, 84, 90, 100, 120, 180, 270, 365, 540, 730];
	$c = ['explicit' => null, 'rule' => null, 'rule_n' => null, 'filled' => $date, 'supplied' => pick($supplyPool), 'cat' => $cat, 'kind' => ''];
	$r = mt_rand(1, 100);
	$randSupply = fn() => mt_rand(0, 1) ? pick($supplyPool) : mt_rand(1, 730);
	if ($r <= 28) { $c['kind'] = 'fallback'; $c['supplied'] = $randSupply(); }
	elseif ($r <= 46) { $c['kind'] = 'days_before_end'; $c['rule'] = 'days_before_end'; $c['rule_n'] = (string)pick([0, 1, 3, 7, 10, 14, 21, 30, 60, mt_rand(0, 730)]); $c['supplied'] = $randSupply(); }
	elseif ($r <= 58) { $c['kind'] = 'fixed_interval'; $c['rule'] = 'fixed_interval'; $c['rule_n'] = (string)pick([1, 7, 14, 28, 30, 60, 90, mt_rand(1, 730)]); }
	elseif ($r <= 76)
	{
		$c['kind'] = 'fraction_elapsed'; $c['rule'] = 'fraction_elapsed'; $c['supplied'] = $randSupply();
		$q = mt_rand(1, 100);
		if ($q <= 70) { $c['rule_n'] = '0.' . str_pad((string)mt_rand(1, 99), 2, '0', STR_PAD_LEFT); }
		elseif ($q <= 90) { $c['rule_n'] = '0.' . str_pad((string)mt_rand(1, 999), 3, '0', STR_PAD_LEFT); }
		else { $c['rule_n'] = pick(['0.5', '0.25', '0.75', '0.9', '0.1']); }
	}
	elseif ($r <= 82)
	{
		$c['kind'] = 'explicit'; $c['explicit'] = utcAdd($date, mt_rand(-30, 800));
		if (mt_rand(0, 1)) { $c['rule'] = 'fixed_interval'; $c['rule_n'] = '30'; }
	}
	elseif ($r <= 86) { $c['kind'] = 'no_fill'; $c['filled'] = null; }
	elseif ($r <= 91) { $c['kind'] = 'invalid_supply'; $c['supplied'] = pick([null, 0, -3, 731, 1000]); }
	elseif ($r <= 96)
	{
		$c['kind'] = 'invalid_rule';
		$bad = pick([['days_before_end', '-1'], ['days_before_end', '731'], ['days_before_end', '7.5'], ['days_before_end', null],
			['fixed_interval', '0'], ['fixed_interval', '731'], ['fixed_interval', null],
			['fraction_elapsed', '0'], ['fraction_elapsed', '1'], ['fraction_elapsed', '1.5'], ['fraction_elapsed', '0.00'], ['fraction_elapsed', null], ['bogus_rule', '5']]);
		[$c['rule'], $c['rule_n']] = $bad;
	}
	else
	{
		$c['kind'] = 'short_supply'; $c['supplied'] = mt_rand(1, 14);
		if (mt_rand(0, 1)) { $c['rule'] = 'days_before_end'; $c['rule_n'] = (string)mt_rand(0, 14); }
	}
	return $c;
}

$pool = dayPool();
$cases = [];
$quota = ['uniform' => UNIFORM, 'leap_feb' => 12000, 'year_end' => 12000, 'month_end' => 12000, 'dst' => 20000, 'skipped_day' => 4000];
foreach ($quota as $cat => $count)
{
	for ($i = 0; $i < $count; $i++)
	{
		$cases[] = makeCase($cat === 'uniform' ? randomDate() : pick($pool[$cat]), $cat);
	}
}
// Every skipped-day date with every rule kind and a small and a large offset, so those dates are
// certain to be covered whatever the seed produced.
foreach ($pool['skipped_day'] as $d)
{
	foreach ([['fixed_interval', '1'], ['fixed_interval', '2'], ['fixed_interval', '3'], ['fixed_interval', '30']] as [$rule, $n])
	{
		$cases[] = ['explicit' => null, 'rule' => $rule, 'rule_n' => $n, 'filled' => $d, 'supplied' => 30, 'cat' => 'skipped_day', 'kind' => 'fixed_interval'];
	}
}
foreach ($cases as $i => &$c) { $c['id'] = $i + 1; }
unset($c);

// The ADR's examples: [fill, supplied, expected reorder date] from section 2 and the verification cases.
$adrExamples = [
	['2026-01-01', 30, '2026-01-17', 'fallback'], ['2026-01-01', 90, '2026-03-18', 'fallback'],
	['2027-12-20', 90, '2028-03-05', 'fallback (year end)'], ['2028-01-15', 30, '2028-01-31', 'fallback (2028 leap year)'],
];

// ---------------------------------------------------------------------------------------------
// SQL side
// ---------------------------------------------------------------------------------------------
$admin = adr42_connect();
$schema = adr42_schema($admin, 'dates');
$pdo = adr42_connect($schema, $zone); // the SQL session zone follows the PHP zone, as the application does
adr42_load_sql($pdo, 'estimate.sql');
$pdo->exec('CREATE TABLE cases (id integer PRIMARY KEY, explicit date, rule text, rule_n numeric, filled date, supplied integer, cat text, kind text)');
$rows = [];
foreach ($cases as $c)
{
	$f = fn($v) => $v === null ? '\\N' : (string)$v;
	$rows[] = implode("\t", [$c['id'], $f($c['explicit']), $f($c['rule']), $f($c['rule_n']), $f($c['filled']), $f($c['supplied']), $c['cat'], $c['kind']]);
}
$pdo->pgsqlCopyFromArray('cases', $rows);
$sessionZone = $pdo->query('SHOW TimeZone')->fetchColumn();
$pdo->exec("CREATE TABLE sql_out AS
	SELECT c.id, e.reorder::text AS reorder, e.source, e.reason,
		CASE WHEN e.source = 'rule:fraction_elapsed' THEN (c.filled + floor(c.supplied * c.rule_n::float8)::integer)::text END AS reorder_float8
	FROM cases c, LATERAL adr42_estimate(c.explicit, c.rule, c.rule_n, c.filled, c.supplied) e");
$sql = [];
foreach ($pdo->query('SELECT id, reorder, source, reason, reorder_float8 FROM sql_out ORDER BY id', PDO::FETCH_ASSOC) as $r) { $sql[(int)$r['id']] = $r; }
$sqlHash = md5(json_encode(array_values($sql)));
$caseHash = md5(json_encode(array_map(fn($c) => [$c['explicit'], $c['rule'], $c['rule_n'], $c['filled'], $c['supplied']], $cases)));

// ---------------------------------------------------------------------------------------------
// Compare
// ---------------------------------------------------------------------------------------------
$tupleMismatch = ['count' => 0, 'examples' => []];
$variants = [];
foreach (VARIANTS as $v) { $variants[$v] = ['compared' => 0, 'mismatches' => 0, 'by_category' => [], 'examples' => []]; }
$distribution = ['source' => [], 'reason' => []];
$fractionFloat = ['compared' => 0, 'php_float_vs_sql_numeric' => 0, 'sql_float8_vs_sql_numeric' => 0, 'examples' => []];

foreach ($cases as $c)
{
	$s = $sql[$c['id']];
	[$n, $source, $reason, $explicit] = decide($c, 'exact');
	$phpReorder = null;
	if ($explicit !== null) { $phpReorder = $explicit; }
	elseif ($n !== null) { $phpReorder = addDays('utc_calendar', $c['filled'], $n); }
	if ($phpReorder !== $s['reorder'] || $source !== $s['source'] || $reason !== $s['reason'])
	{
		$tupleMismatch['count']++;
		if (count($tupleMismatch['examples']) < 10) { $tupleMismatch['examples'][] = ['case' => $c, 'php' => [$phpReorder, $source, $reason], 'sql' => [$s['reorder'], $s['source'], $s['reason']]]; }
	}
	$k = $source ?? ('unknown:' . $reason);
	$distribution['source'][$k] = ($distribution['source'][$k] ?? 0) + 1;

	if ($n === null) { continue; }
	foreach (VARIANTS as $v)
	{
		$got = addDays($v, $c['filled'], $n);
		$variants[$v]['compared']++;
		if ($got !== $s['reorder'])
		{
			$variants[$v]['mismatches']++;
			$variants[$v]['by_category'][$c['cat']] = ($variants[$v]['by_category'][$c['cat']] ?? 0) + 1;
			// A span "touches" a skipped calendar day when it starts on it, ends on it or contains it.
			$touches = false;
			foreach (['1994-12-31', '2011-12-30'] as $skipped)
			{
				if ($c['filled'] <= $skipped && $s['reorder'] >= $skipped) { $touches = true; }
			}
			if (!$touches) { $variants[$v]['mismatches_not_touching_a_skipped_day'] = ($variants[$v]['mismatches_not_touching_a_skipped_day'] ?? 0) + 1; }
			if (count($variants[$v]['examples']) < 6) { $variants[$v]['examples'][] = ['filled' => $c['filled'], 'days' => $n, 'php' => $got, 'sql' => $s['reorder']]; }
		}
	}
	if ($source === 'rule:fraction_elapsed')
	{
		[$nf] = decide($c, 'float');
		$fractionFloat['compared']++;
		$phpFloat = addDays('utc_calendar', $c['filled'], $nf);
		if ($phpFloat !== $s['reorder'])
		{
			$fractionFloat['php_float_vs_sql_numeric']++;
			if (count($fractionFloat['examples']) < 6) { $fractionFloat['examples'][] = ['supplied' => $c['supplied'], 'F' => $c['rule_n'], 'exact_days' => $n, 'php_float_days' => $nf]; }
		}
		if ($s['reorder_float8'] !== $s['reorder']) { $fractionFloat['sql_float8_vs_sql_numeric']++; }
	}
}
ksort($distribution['source']);

// Exhaustive fraction grid: every supplied_days 1..730 against F = k/100 and k/1000.
$grid = [];
foreach ([100 => 99, 1000 => 999] as $den => $max)
{
	$mismatch = 0; $total = 0; $ex = [];
	for ($s = 1; $s <= 730; $s++)
	{
		for ($k = 1; $k <= $max; $k++)
		{
			$total++;
			$exact = intdiv($s * $k, $den);
			$float = (int)floor($s * ($k / $den));
			$text = (int)floor($s * (float)('0.' . str_pad((string)$k, strlen((string)$den) - 1, '0', STR_PAD_LEFT)));
			if ($exact !== $float || $exact !== $text)
			{
				$mismatch++;
				if (count($ex) < 5) { $ex[] = ['supplied' => $s, 'F' => $k . '/' . $den, 'exact' => $exact, 'php_float_k_over_den' => $float, 'php_float_of_text' => $text]; }
			}
		}
	}
	$sqlCounts = $pdo->query("SELECT count(*) AS total,
			count(*) FILTER (WHERE floor(s * (k::numeric / $den)) <> (s * k) / $den) AS numeric_vs_integer,
			count(*) FILTER (WHERE floor(s * (k::numeric / $den)::float8) <> floor(s * (k::numeric / $den))) AS float8_vs_numeric
		FROM generate_series(1, 730) s, generate_series(1, $max) k")->fetch(PDO::FETCH_ASSOC);
	$grid['denominator_' . $den] = ['php_total' => $total, 'php_float_vs_integer_exact_mismatches' => $mismatch, 'examples' => $ex, 'sql' => $sqlCounts];
}

// The ADR's four table examples through both sides.
$examples = [];
foreach ($adrExamples as [$fill, $supplied, $expected, $label])
{
	$phpDate = addDays('utc_calendar', $fill, $supplied - 14);
	$sqlDate = $pdo->query("SELECT (SELECT reorder FROM adr42_estimate(NULL, NULL, NULL, DATE '$fill', $supplied))::text")->fetchColumn();
	$examples[] = ['fill' => $fill, 'supplied' => $supplied, 'adr_says' => $expected, 'php' => $phpDate, 'sql' => $sqlDate, 'days_after_fill' => $supplied - 14, 'agree' => $phpDate === $expected && $sqlDate === $expected, 'note' => $label];
}
// 0.75 on 90 days
$examples[] = ['rule' => 'fraction_elapsed 0.75 on a 90-day fill', 'adr_says' => 'filled_on + 67', 'php_days' => decide(['filled' => '2026-01-01', 'explicit' => null, 'supplied' => 90, 'rule' => 'fraction_elapsed', 'rule_n' => '0.75'], 'exact')[0],
	'sql_days' => $pdo->query("SELECT (SELECT reorder FROM adr42_estimate(NULL, 'fraction_elapsed', 0.75, DATE '2026-01-01', 90)) - DATE '2026-01-01'")->fetchColumn()];

// The skipped-day check, spelled out: what each PHP variant says for the days the zone lacks.
$skipped = [];
foreach (['1994-12-30' => 2, '1994-12-31' => 0, '2011-12-29' => 2, '2011-12-30' => 0] as $d => $n)
{
	$row = ['filled' => $d, 'days' => $n, 'sql' => $pdo->query("SELECT (DATE '$d' + $n)::text")->fetchColumn()];
	foreach (VARIANTS as $v) { $row[$v] = addDays($v, $d, $n); }
	$skipped[] = $row;
}

// SQL must not depend on its session zone: the same function in a session for each zone.
$zoneIndependence = [];
foreach (['UTC', 'America/New_York', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Pacific/Apia'] as $z)
{
	$pdo->exec('SET TIME ZONE ' . $pdo->quote($z));
	$h = $pdo->query("SELECT md5(string_agg(coalesce(e.reorder::text,'-') || coalesce(e.source,'-') || coalesce(e.reason,'-'), ',' ORDER BY c.id))
		FROM cases c, LATERAL adr42_estimate(c.explicit, c.rule, c.rule_n, c.filled, c.supplied) e")->fetchColumn();
	$zoneIndependence[$z] = $h;
}
$pdo->exec('SET TIME ZONE ' . $pdo->quote($zone));

$cat = [];
foreach ($cases as $c) { $cat[$c['cat']] = ($cat[$c['cat']] ?? 0) + 1; }

$pgZones = [];
foreach (['UTC', 'America/New_York', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Pacific/Apia', 'Australia/Lord_Howe'] as $z)
{
	$pgZones[$z] = (bool)$pdo->query('SELECT count(*) FROM pg_timezone_names WHERE name = ' . $pdo->quote($z))->fetchColumn();
}

$admin->exec("DROP SCHEMA $schema CASCADE");
adr42_emit([
	'probe' => 'dates',
	'environment' => adr42_environment($pdo) + ['sql_session_timezone' => $sessionZone, 'zones_known_to_postgres' => $pgZones],
	'seed' => SEED,
	'cases' => count($cases), 'cases_by_category' => $cat, 'case_set_md5' => $caseHash, 'sql_result_md5' => $sqlHash,
	'sql_result_md5_by_session_zone' => $zoneIndependence,
	'outcome_distribution' => $distribution['source'],
	'adr_examples' => $examples,
	'tuple_php_utc_calendar_vs_sql' => $tupleMismatch,
	'php_variants_vs_sql_reorder_date' => $variants,
	'fraction_elapsed_in_random_set' => $fractionFloat,
	'fraction_elapsed_exhaustive_grid' => $grid,
	'skipped_calendar_days' => $skipped,
]);
