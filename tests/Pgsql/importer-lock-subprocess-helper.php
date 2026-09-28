<?php

// Runs a real DatabaseImporter::Import(true) against the schema the calling test migrated,
// copying from a source SQLite fixture, and pauses immediately after ImportSnapshot() takes
// `LOCK TABLE print_jobs IN ACCESS EXCLUSIVE MODE` until a coordination advisory lock is
// released - the "import in flight" half of
// ImporterPrintJobLockConcurrencyTest's two-connection race.
//
// The pause reuses DatabaseImporter's own $progress callable - an existing constructor
// parameter, not a hook added to production code for this test - by recognising the exact
// progress line ImportSnapshot() reports right after taking the lock (see that method) and
// blocking there, on the same connection that holds the transaction and the lock, until the
// calling test releases the gate.
//
//   php importer-lock-subprocess-helper.php <source sqlite path> <gate class> <gate object>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables as
// request-subprocess-helper.php, attaching to the schema the calling test migrated.
// Output: {"status": 200, "messages": [...]} on success, or
// {"status": 400, "error_message": "...", "messages": [...]} on a thrown exception.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;

$sourcePath = $argv[1];
$gateClass = (int)$argv[2];
$gateObject = (int)$argv[3];

$target = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$target->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($target);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $target);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

$source = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$messages = [];
$progress = function (string $message) use (&$messages, $target, $gateClass, $gateObject)
{
	$messages[] = $message;

	if (str_contains($message, 'print_jobs locked'))
	{
		// Blocks this whole process - and so the transaction and the lock ImportSnapshot()
		// just took on $target, since nothing else advances the connection meanwhile - until
		// the calling test's own connection releases the same (classid, objid) pair. A
		// session-level advisory lock, not the transaction-scoped kind: it has to be
		// independent of the transaction already open here so waiting for it does not
		// itself require a transaction state change.
		$target->query('SELECT pg_advisory_lock(' . (int)$gateClass . ', ' . (int)$gateObject . ')')->fetchColumn();
		$target->query('SELECT pg_advisory_unlock(' . (int)$gateClass . ', ' . (int)$gateObject . ')')->fetchColumn();
	}
};

try
{
	(new DatabaseImporter($source, $target, DatabaseService::GetInstance()->GetDialect(), $progress))->Import(true);
	echo json_encode(['status' => 200, 'messages' => $messages]);
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage(), 'messages' => $messages]);
}
