<?php

// The half of CredentialSplitTest that has to run as another database role. The
// application's connection settings are constants defined once per process
// (config-dist.php), so the test's own process - connected as the suite's superuser -
// cannot also be victual_app; this one is spawned with VICTUAL_DB_USER and
// VICTUAL_DB_PASSWORD set to the role under test, connects the way the serving container
// does (DatabaseService, so PostgresDialect::OnConnected() runs), and reports what that
// role can and cannot do as one JSON object on stdout.
//
//   VICTUAL_DB_USER=victual_app VICTUAL_DB_PASSWORD=... php credential-split-helper.php

define('VICTUAL_ROOT_PATH', dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
define('VICTUAL_USER_ID', 1);
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

use Victual\Services\DatabaseService;

$report = ['connected_as' => null, 'connect_error' => null, 'attempts' => []];

try
{
	$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
	$report['connected_as'] = $pdo->query('SELECT current_user')->fetchColumn();
}
catch (Throwable $ex)
{
	$report['connect_error'] = $ex->getMessage();
	echo json_encode($report);
	exit(0);
}

// [label, statement]. Each is run on its own so that one refusal does not mask the next,
// and the outcome recorded is the SQLSTATE - "allowed" is null, and 42501 is
// insufficient_privilege, which is the only refusal this test accepts as the right one.
$attempts = [
	'select_view' => 'SELECT * FROM stock_current LIMIT 1',
	'insert' => "INSERT INTO locations (name) VALUES ('credential-split-probe')",
	'update' => "UPDATE locations SET name = 'credential-split-probe-2' WHERE name = 'credential-split-probe'",
	'delete' => "DELETE FROM locations WHERE name = 'credential-split-probe-2'",
	// The sequence behind an INSERT, which is what USAGE on sequences is for: an INSERT
	// that omits the id draws from it, and a role without the grant fails there.
	'sequence' => "SELECT nextval(pg_get_serial_sequence('locations', 'id'))",
	// setval() is what PostgresDialect::ResyncGeneratedIdCounters() calls after the demo and
	// prerelease generators insert explicit ids, and it needs UPDATE on the sequence, which
	// nextval() does not - so a grant that lost UPDATE would pass the line above.
	'sequence_setval' => "SELECT setval(pg_get_serial_sequence('locations', 'id'), (SELECT COALESCE(MAX(id), 1) FROM locations))",
	// The table the migrate role creates *after* roles.sql ran, which is what the default
	// privileges are for; the test creates it, this reads and writes it.
	'later_table_insert' => "INSERT INTO credential_split_later (note) VALUES ('written by the app role')",
	'later_table_select' => 'SELECT note FROM credential_split_later',
	'create_table' => 'CREATE TABLE credential_split_probe (id integer)',
	'alter_table' => 'ALTER TABLE locations ADD COLUMN credential_split_probe integer',
	'drop_table' => 'DROP TABLE locations',
	'drop_view' => 'DROP VIEW stock_current',
	'truncate' => 'TRUNCATE locations',
	'create_trigger' => 'CREATE TRIGGER credential_split_probe BEFORE INSERT ON locations FOR EACH ROW EXECUTE FUNCTION pg_catalog.suppress_redundant_updates_trigger()',
	'create_schema' => 'CREATE SCHEMA credential_split_probe',
];

foreach ($attempts as $label => $sql)
{
	try
	{
		$pdo->exec($sql);
		$report['attempts'][$label] = null;
	}
	catch (Throwable $ex)
	{
		$report['attempts'][$label] = $ex->getCode();
	}
}

echo json_encode($report);
