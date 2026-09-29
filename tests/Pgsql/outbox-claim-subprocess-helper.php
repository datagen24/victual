<?php

// Runs one step of OutboxClaimConcurrencyTest's regression scenarios (issue #510) against the
// schema the calling test migrated, on a connection of its own:
//
//   php outbox-claim-subprocess-helper.php claim-hold  <gate class> <gate object>
//   php outbox-claim-subprocess-helper.php claim-probe
//   php outbox-claim-subprocess-helper.php drain
//
// "claim-hold" opens a transaction, claims the undelivered stock.transaction_booked row with
// OutboxService::ClaimUndelivered() - issue #510's `FOR UPDATE SKIP LOCKED` claim - then
// blocks on a session-level advisory lock the calling test already holds, so the claim's row
// lock stays taken, on this connection, for exactly as long as the test wants it to.
//
// "claim-probe" claims the same event type on its own connection with no pause at all:
// SKIP LOCKED means it never blocks behind "claim-hold"'s lock, it simply does not see the
// row that lock covers - which is the decisive, non-racy assertion this whole scenario
// exists for (see the test class's own docblock for why a probe that returns instantly is
// enough, unlike a scenario built on a lock that blocks).
//
// "drain" runs the real BookingEventPublisher::Drain() once, against the InfluxDB stand-in
// (.devtools/mqtt/influx-standin.php) the test starts on VICTUAL_INFLUXDB_URL.
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated, plus
// (for "drain") whichever VICTUAL_INFLUXDB_* settings the calling test passed as this
// process's environment - config-dist.php's Setting() reads them before this script touches
// the database.
//
// Output: {"status": 200, "claimed_ids": [...]} for claim-hold/claim-probe, or
// {"status": 200, "delivered": true|false} for drain - or {"status": 400,
// "error_message": "..."} on a thrown exception.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\Outbox\OutboxService;

$mode = $argv[1] ?? '';

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

try
{
	if ($mode === 'claim-hold')
	{
		$gateClass = (int)$argv[2];
		$gateObject = (int)$argv[3];

		$ids = DatabaseService::GetInstance()->InTransaction(function () use ($pdo, $gateClass, $gateObject)
		{
			$rows = (new OutboxService())->ClaimUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED);

			// Blocks this whole process - and so the transaction and the row lock
			// ClaimUndelivered() just took, since nothing else advances this connection
			// meanwhile - until the calling test's own connection releases the same
			// (classid, objid) pair. Session-level, not transaction-scoped: waiting for it
			// must not itself require a transaction state change.
			$pdo->query('SELECT pg_advisory_lock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();
			$pdo->query('SELECT pg_advisory_unlock(' . $gateClass . ', ' . $gateObject . ')')->fetchColumn();

			return array_column($rows, 'id');
		});

		echo json_encode(['status' => 200, 'claimed_ids' => $ids]);
	}
	elseif ($mode === 'claim-probe')
	{
		$ids = DatabaseService::GetInstance()->InTransaction(function ()
		{
			return array_column((new OutboxService())->ClaimUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED), 'id');
		});

		echo json_encode(['status' => 200, 'claimed_ids' => $ids]);
	}
	elseif ($mode === 'drain')
	{
		$delivered = BookingEventPublisher::Drain();

		echo json_encode(['status' => 200, 'delivered' => $delivered]);
	}
	else
	{
		echo json_encode(['status' => 400, 'error_message' => 'unknown mode "' . $mode . '"']);
	}
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
