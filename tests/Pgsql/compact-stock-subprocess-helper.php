<?php

// Calls StockService::CompactStockEntries() directly against the given product, with no
// ambient transaction or lock already open on the connection.
//
// This is the one call shape none of StockService's own callers (AddProduct,
// EditStockEntry, WeighLocation) ever produce: every one of them locks the product before
// calling CompactStockEntries(), so driving it through any of their HTTP endpoints always
// runs it nested inside an already-held lock, where the pre-lock read
// tests/Pgsql/StockConcurrencyTest.php's compaction scenario is about cannot go stale -
// the ambient lock already protects it. CompactStockEntries() is public and its
// $productId = null form sweeps every product in one call with no caller-supplied lock at
// all, so this helper exercises exactly that unlocked entry point: a maintenance sweep, or
// any future caller that does not lock first.
//
//   php compact-stock-subprocess-helper.php <productId>
//
// Reads the same PG*/RBAC_TEST_SCHEMA/VICTUAL_DATAPATH/VICTUAL_ROOT environment variables
// as request-subprocess-helper.php, attaching to the schema the calling test migrated. Also
// reads VICTUAL_TEST_COMPACT_PAUSE_AT (optional): when set to 'after_row_locks' or
// 'after_first_rewrite', this process swaps in CompactionPauseDatabaseService (below) before
// calling CompactStockEntries(), so a B3/B5-style test can make this run block mid-transaction
// on a dedicated advisory lock it holds itself. Unset, this runs straight through exactly as
// bin/victual-compact-stock's own real, unpaused invocation does - CompactStockEntries() itself
// carries no test-only branch; the seam is this file swapping DatabaseService's singleton, the
// same technique mqttcoverage-subprocess-helper.php's ThrowingMqttDatabaseService already uses.
//
// Output, in order:
//   1. One line, immediately after connecting: {"backend_pid": N} - so the calling test can
//      identify this process's PostgreSQL backend and poll pg_blocking_pids()/pg_locks for it,
//      the same way issue-stock-label-subprocess-helper.php's own first line already does.
//   2. After CompactStockEntries() returns or throws: {"status": 200} on success, or
//      {"status": 400, "error_message": "..."} on a thrown exception.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_USER_ID', 9000);

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

// Stdout is the JSON answer and nothing else: a diagnostic goes to stderr, where the test
// reports it, rather than arriving in front of the answer and reducing json_decode() to null.
ini_set('display_errors', 'stderr');

/**
 * A DatabaseService whose raw-SQL calls pause CompactStockEntries() at one of its two
 * synchronisation points, so a two-connection test can observe it genuinely blocked mid-run
 * through real PostgreSQL lock contention rather than a sleep() and a hoped-for timing window.
 * Replaces the StockService::TestPauseHook() environment-gated branch that used to live in
 * production code: this class only ever exists in this subprocess, swapped in below through
 * ReflectionProperty(DatabaseService::class, 'instance') before CompactStockEntries() runs, the
 * same seam mqttcoverage-subprocess-helper.php's ThrowingMqttDatabaseService already uses.
 *
 * The two checkpoints are identified by the exact statement text CompactStockEntries() already
 * executes at those points, not by any marker added for this purpose:
 *   - 'after_row_locks': the `... FOR UPDATE` query that takes the candidate rows' row locks.
 *   - 'after_first_rewrite': the survivor's `UPDATE stock SET amount = ...` statement, the last
 *     statement of a group actually rewritten (a skipped group never reaches it), so pausing on
 *     its first occurrence is pausing after exactly one group's rewrite, matching the old
 *     $pausedAfterFirstRewrite guard.
 * Each pauses at most once per run, on a dedicated advisory lock class (1986600001) distinct
 * from every class StockService/PostgresDialect otherwise use, keyed on the product id this
 * script was invoked with - a test acquires that same lock itself before starting this
 * subprocess, so the call below blocks until the test releases it.
 */
class CompactionPauseDatabaseService extends DatabaseService
{
	private const PAUSE_LOCK_CLASS = 1986600001;

	private string $pauseAt;
	private int $productId;
	private bool $pausedAfterRowLocks = false;
	private bool $pausedAfterFirstRewrite = false;

	public function __construct(string $pauseAt, int $productId)
	{
		$this->pauseAt = $pauseAt;
		$this->productId = $productId;
	}

	public function ExecuteDbQuery(string $sql, ?array $params = null)
	{
		$result = parent::ExecuteDbQuery($sql, $params);

		if ($this->pauseAt === 'after_row_locks' && !$this->pausedAfterRowLocks && str_contains($sql, 'FOR UPDATE'))
		{
			$this->pausedAfterRowLocks = true;
			$this->Pause();
		}

		return $result;
	}

	public function ExecuteDbStatement(string $sql, ?array $params = null)
	{
		$result = parent::ExecuteDbStatement($sql, $params);

		if ($this->pauseAt === 'after_first_rewrite' && !$this->pausedAfterFirstRewrite
			&& $params === null && preg_match('/^UPDATE stock SET amount = /', $sql) === 1)
		{
			$this->pausedAfterFirstRewrite = true;
			$this->Pause();
		}

		return $result;
	}

	private function Pause(): void
	{
		$pdo = $this->GetDbConnectionRaw();
		$pdo->exec('SELECT pg_advisory_lock(' . self::PAUSE_LOCK_CLASS . ', ' . $this->productId . ')');
		$pdo->exec('SELECT pg_advisory_unlock(' . self::PAUSE_LOCK_CLASS . ', ' . $this->productId . ')');
	}
}

$productId = (int)($argv[1] ?? 0);

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

$pauseAt = getenv('VICTUAL_TEST_COMPACT_PAUSE_AT');
if ($pauseAt !== false && $pauseAt !== '')
{
	(new ReflectionProperty(DatabaseService::class, 'instance'))->setValue(null, new CompactionPauseDatabaseService($pauseAt, $productId));
}

echo json_encode(['backend_pid' => (int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()]) . "\n";
flush();

try
{
	StockService::GetInstance()->CompactStockEntries($productId);
	echo json_encode(['status' => 200]);
}
catch (\Throwable $ex)
{
	echo json_encode(['status' => 400, 'error_message' => $ex->getMessage()]);
}
