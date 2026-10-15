<?php

// Runs one artifact write on an independent PostgreSQL connection so the collection test
// can observe the writer waiting on the shared per-file advisory lock.
$spec = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
require_once dirname(__DIR__, 2) . '/bootstrap.php';

ini_set('display_errors', 'stderr');
$db = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
$db->exec("SET lock_timeout = '10s'");
file_put_contents($spec['ready_file'], (string)$db->query('SELECT pg_backend_pid()')->fetchColumn());

try {
	$db->beginTransaction();
	$artifact = (new \Victual\Services\Labels\ArtifactService($db))->Accept(
		(int)$spec['request_id'],
		$spec['generation_token'],
		base64_decode($spec['bytes'], true),
		'fixture-renderer',
		'0.0.1-fixture'
	);
	$db->commit();
	echo json_encode(['artifact_id' => (int)$artifact['id'], 'file_name' => $artifact['file_name']], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
	if ($db->inTransaction()) {
		$db->rollBack();
	}
	fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
	exit(1);
}
