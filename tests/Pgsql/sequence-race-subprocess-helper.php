<?php

// Hammers a bare SELECT nextval(<sequence>) - no table write, no primary key involved - for
// a fixed wall-clock duration, as fast as this process can manage, printing every value it
// received as a JSON array. Run as a genuinely separate OS process, concurrently with the
// calling test's own AdvanceIdentitySequence() calls on the same sequence
// (tests/Pgsql/DialectPolicyTest.php::testAdvanceIdentitySequenceNeverReissuesAConcurrentlyClaimedId):
// PDO's own synchronous, blocking calls from a single PHP process cannot produce genuine
// overlap by merely alternating in a loop, since each call fully completes before the next
// begins - two separate OS processes, scheduled independently by the kernel, can. A
// wall-clock duration (this run found 96 of the 52151 values this process drew reissued in
// 4s against the unfixed, read-then-setval version of AdvanceIdentitySequence()) gives the
// two processes' independently-scheduled round trips many chances to truly overlap, rather
// than a fixed iteration count that risks one side finishing before the other has properly
// started.
//
// nextval() rather than an INSERT deliberately: inserting into an identity column with a
// reissued id would hit that column's own PRIMARY KEY constraint and throw, not silently
// produce an observable duplicate - the sequence's own reissue is the thing under test, so
// this reads it directly.
//
//   php sequence-race-subprocess-helper.php <sequence_name> <duration_seconds>
//
// Reads the same PGHOST/PGPORT/PHPUNIT_DB_NAME/PGUSER/PGPASSWORD/RBAC_TEST_SCHEMA
// environment variables every other subprocess helper in this directory does. Output: a
// JSON array of every value nextval() returned, in the order this process received them.

$sequenceName = $argv[1] ?? '';
$durationSeconds = (float)($argv[2] ?? 3.0);

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');

// $sequenceName is interpolated, not bound: nextval() takes a regclass/text argument and
// accepts a bind parameter directly, but binding it defeats the purpose of measuring raw
// call overhead against a tight loop - this is a same-process, environment-supplied value
// (from pg_get_serial_sequence(), read by the calling test), never anything a remote
// caller supplies.
$draw = $pdo->prepare('SELECT nextval(\'' . $sequenceName . '\')');

$values = [];
$deadline = microtime(true) + $durationSeconds;
while (microtime(true) < $deadline)
{
	$draw->execute();
	$values[] = (int)$draw->fetchColumn();
}

echo json_encode($values);
