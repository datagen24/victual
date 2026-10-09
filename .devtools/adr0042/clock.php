<?php
// SPIKE ONLY. Probe 3, live part: PostgreSQL's clock is faked by libfaketime (run.sh clock), set
// to start shortly before a local midnight of the zone given as argv[1] and then tick at normal
// speed. This script samples, every 200 ms across the crossing, PostgreSQL's clock_timestamp()
// and CURRENT_DATE on two connections, and PHP's date('Y-m-d') for that same instant:
//   app_style: SET TIME ZONE <PHP zone> through the real PostgresDialect::OnConnected()
//   bare:      no OnConnected(); the server's default zone
// PHP's own clock is not faked, so PHP's date is computed for PostgreSQL's instant. That is
// date('Y-m-d', $instant), the value date('Y-m-d') returns at that moment.
// Output: JSON on stdout.
require __DIR__ . '/lib.php';
require '/app/packages/autoload.php';

use Victual\Services\Database\PostgresDialect;

$zone = $argv[1] ?? 'America/New_York';
date_default_timezone_set($zone);
$app = adr42_connect();
(new PostgresDialect())->OnConnected($app);
$bare = adr42_connect();
$q = 'SELECT extract(epoch from clock_timestamp())::float8, CURRENT_DATE::text';

[$first] = $app->query($q)->fetch(PDO::FETCH_NUM);
$firstTs = (int)floor($first);
$crossing = (new DateTimeImmutable('@' . $firstTs))->setTimezone(new DateTimeZone($zone))->modify('tomorrow')->getTimestamp();
$result = ['probe' => 'clock', 'zone' => $zone, 'bare_zone' => $bare->query('SHOW TimeZone')->fetchColumn(), 'app_style_zone' => $app->query('SHOW TimeZone')->fetchColumn(),
	'postgres_clock_at_start_utc' => gmdate('c', $firstTs), 'local_midnight_utc' => gmdate('c', $crossing), 'seconds_to_midnight_at_start' => $crossing - $firstTs,
	'real_wall_clock_utc_of_this_host' => gmdate('c')];
if ($crossing - $firstTs > 600 || $crossing < $firstTs)
{
	$result['verdict'] = 'inconclusive: the faked clock did not start within 10 minutes before a local midnight of ' . $zone;
	adr42_emit($result);
	exit(0);
}
$samples = []; $changes = ['php' => [], 'app_style' => [], 'bare' => []]; $last = ['php' => null, 'app_style' => null, 'bare' => null]; $disagree = ['app_style_vs_php' => 0, 'bare_vs_php' => 0];
$deadline = microtime(true) + ($crossing - $firstTs) + 12;
while (microtime(true) < $deadline)
{
	[$ea, $da] = $app->query($q)->fetch(PDO::FETCH_NUM);
	[$eb, $db] = $bare->query($q)->fetch(PDO::FETCH_NUM);
	$php = date('Y-m-d', (int)floor($ea)); // PHP's date for the instant PostgreSQL's clock showed
	$phpB = date('Y-m-d', (int)floor($eb));
	foreach (['php' => $php, 'app_style' => $da, 'bare' => $db] as $k => $v)
	{
		if ($last[$k] !== $v) { $changes[$k][] = ['at_utc' => gmdate('H:i:s', (int)floor($ea)) . sprintf('.%03d', ($ea - floor($ea)) * 1000), 'to' => $v]; $last[$k] = $v; }
	}
	if ($da !== $php) { $disagree['app_style_vs_php']++; }
	if ($db !== $phpB) { $disagree['bare_vs_php']++; }
	$samples[] = ['pg_utc' => gmdate('H:i:s', (int)floor($ea)), 'php_date' => $php, 'app_style_CURRENT_DATE' => $da, 'bare_CURRENT_DATE' => $db];
	usleep(200000);
}
$result['samples_taken'] = count($samples);
$result['date_changes_first_value_then_changes'] = $changes;
$result['samples_where_app_style_CURRENT_DATE_differs_from_php_date'] = $disagree['app_style_vs_php'];
$result['samples_where_bare_CURRENT_DATE_differs_from_php_date'] = $disagree['bare_vs_php'];
$result['sample_every_10th'] = array_values(array_filter($samples, fn($s, $i) => $i % 10 === 0, ARRAY_FILTER_USE_BOTH));
adr42_emit($result);
