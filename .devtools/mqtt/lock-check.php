<?php

// Proves the publication lock serialises what it is supposed to.
//
//   VICTUAL_DATAPATH=... php .devtools/mqtt/lock-check.php          run the probe
//   VICTUAL_DATAPATH=... php .devtools/mqtt/lock-check.php --hold N hold the lock N seconds
//
// The race the lock closes leaves nothing in a log. Request A assembles the snapshot,
// request B commits a later change and publishes it, then A publishes what it read earlier -
// and since retained topics carry no version and no ordering, the broker keeps A's stale
// state until the next write. On a pod that sleeps for days that is exactly the failure the
// whole plan exists to prevent, and nothing failed, so nothing says so.
//
// The probe: a child process takes the lock and holds it, while the parent times how long
// its own WithPublicationLock() call takes to get in. If the lock works, the parent waits
// roughly as long as the child holds it. If it does not, the parent returns immediately -
// which is precisely the interleaving that loses the update.
//
// On SQLite this is expected to report NOT SERIALISED and that is correct rather than a
// failure: SqliteDialect::WithPublicationLock() is a documented no-op, because under
// ADR-0008 SQLite is not a runtime engine and the built-in server that serves a dev boot is
// single-process. Run it against PostgreSQL to see the lock actually hold.
//
// Exit codes: 0 when the engine's documented behaviour is what was observed.

use Victual\Services\DatabaseService;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: __DIR__ . '/../../data');
}

require_once __DIR__ . '/../../packages/autoload.php';
require_once __DIR__ . '/../../tests/Support/Subprocess.php';

if (file_exists(VICTUAL_DATAPATH . '/config.php'))
{
	require_once VICTUAL_DATAPATH . '/config.php';
}

require_once __DIR__ . '/../../config-dist.php';

if (!defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

$arguments = array_slice($argv, 1);
$holdIndex = array_search('--hold', $arguments, true);

$dialect = DatabaseService::GetInstance()->GetDialect();

// The child half: take the lock, announce it, hold it, release it
if ($holdIndex !== false)
{
	$seconds = (float)($arguments[$holdIndex + 1] ?? 3);

	$dialect->WithPublicationLock(function () use ($seconds)
	{
		echo "held\n";
		flush();
		usleep((int)($seconds * 1000000));
	});

	exit(0);
}

$hold = 3.0;

try
{
	$child = \Victual\Tests\Support\Subprocess::start(
		escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --hold ' . $hold,
		// getenv() rather than $_ENV: PHP's default variables_order has no "E", so $_ENV is
		// empty and the child would inherit none of the connection settings its config.php
		// reads - and would then report "never took the lock" for a reason that has nothing to
		// do with locking.
		['VICTUAL_DATAPATH' => VICTUAL_DATAPATH] + getenv()
	);
}
catch (RuntimeException $exception)
{
	fwrite(STDERR, "Could not start the holder process.\n");
	exit(1);
}

// Wait until the child says it actually has the lock, so the parent's wait measures
// contention rather than process startup. The helper keeps draining the child's stderr
// meanwhile; a child that never answers is given 60 s.
$announced = $child->readLine(60.0);

if (trim((string)$announced) !== 'held')
{
	// Whatever the holder said instead is the actual diagnosis - a connection failure, a
	// missing table - and printing it beats reporting a lock that did not hold
	fwrite(STDERR, "The holder never took the lock: " . var_export($announced, true) . "\n");
	fwrite(STDERR, $child->abort()['stderr']);
	exit(1);
}

$startedWaiting = microtime(true);

$dialect->WithPublicationLock(function ()
{
	// Nothing: the measurement is how long getting in here took
});

$waited = microtime(true) - $startedWaiting;

$child->finish();

$serialised = $waited > ($hold * 0.5);
$engine = $dialect->GetName();

printf("engine=%s  waited=%.2fs while another process held the lock for %.1fs\n", $engine, $waited, $hold);

if ($engine === 'pgsql')
{
	if (!$serialised)
	{
		fwrite(STDERR, "NOT SERIALISED - the advisory lock did not hold\n");
		exit(1);
	}

	echo "SERIALISED - the second caller waited for the first\n";
	exit(0);
}

if ($serialised)
{
	fwrite(STDERR, "Unexpected: SQLite's WithPublicationLock() is documented as a no-op but something blocked\n");
	exit(1);
}

echo "NOT SERIALISED, as documented for this engine - see SqliteDialect::WithPublicationLock()\n";
exit(0);
