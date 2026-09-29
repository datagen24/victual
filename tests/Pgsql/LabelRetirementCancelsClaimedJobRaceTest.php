<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #516 (M16, #487 remediation), maintainer decision D2, verbatim: "cancel queued,
 * unclaimed print jobs when their label is retired. Leave running jobs untouched. ...
 * Tests should verify that ... concurrent retirement and worker claims [do not cancel] a job
 * that has already been claimed."
 *
 * migrations/0296.pgsql.sql's cancel_queued_label_jobs() (called from every label retirement
 * trigger) takes the same row-lock discipline PrintAttemptService::Claim() and
 * LabelOperationsService::Cancel() already use: its own `UPDATE print_jobs ... WHERE
 * current_attempt_id IS NULL` is what takes the row lock, and PostgreSQL's EvalPlanQual
 * re-checks that WHERE clause against the row's post-commit version once a lock it had to
 * wait for is released. .devtools/pgtap/023-label-retirement-cancels-jobs.sql already proves
 * the sequential shape of this (a job claimed *before* its label retires is left alone); what
 * a single-connection pgTAP script cannot drive is the genuine race - a job claimed by one
 * backend while a second backend's retirement is *concurrently* trying to cancel the exact
 * same row - which needs two real connections contending for the same lock. This is that
 * test, following the same two-subprocess, advisory-lock-gate pattern
 * ImporterPrintJobLockConcurrencyTest already established for the analogous print_jobs race:
 *
 *   - a "claim holder" subprocess (label-retirement-claim-hold-subprocess-helper.php) inserts
 *     a print_attempts row and sets the job's current_attempt_id, still inside its own open
 *     transaction, then pauses on a coordination advisory lock - still holding the row lock
 *     that UPDATE took;
 *   - once that subprocess is observed paused (still holding the row lock), a "retire"
 *     subprocess (label-retirement-delete-subprocess-helper.php) runs the real production
 *     DELETE a product's own retirement path uses
 *     (GenericEntityApiController::DeleteObject()'s own `$row->delete()`) - which fires
 *     retire_product_labels, which fires cancel_queued_label_jobs(), whose own UPDATE
 *     contends for the same row;
 *   - the test asserts the retire subprocess is observed genuinely blocked, in pg_locks, on
 *     the claim holder's row-level lock (a `transactionid` wait, not a `relation` wait - see
 *     waitForRowLockWaiter()'s own comment for why);
 *   - only then does the test release the claim holder's pause, letting it commit (finalising
 *     the "claim": current_attempt_id set, nothing rolled back);
 *   - the previously queued retirement then proceeds - re-checking current_attempt_id IS NULL
 *     against the now-committed row and finding it false - and is asserted to have left the
 *     job exactly as claimed: not cancelled, its outbox row not dead-lettered, while the
 *     label itself is still retired.
 *
 * Both subprocess helpers set an explicit statement_timeout (20s) and this test bounds its
 * own pg_locks polling the same way ImporterPrintJobLockConcurrencyTest's waiters do, per
 * FIXER_RULES.md's requirement that a concurrency test never hold the shared suite lock on a
 * hang - a previous one held it for an hour.
 */
class LabelRetirementCancelsClaimedJobRaceTest extends PgsqlSchemaTestCase
{
	/** Session-level advisory lock pair coordinating this class's own two subprocesses; not used by any production code. */
	private const GATE_CLASS = 1986600516;

	/** A second, independent PDO connection into the same test schema - the coordination gate's owner. */
	private static function secondConnection(): PDO
	{
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');

		return $pdo;
	}

	private static function subprocessEnv(): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');

		return array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
	}

	/** @return array{0: resource, 1: array} */
	private static function startClaimHoldSubprocess(int $jobId, int $workerId, int $gateClass, int $gateObject): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/label-retirement-claim-hold-subprocess-helper.php', (string)$jobId, (string)$workerId, (string)$gateClass, (string)$gateObject],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			self::subprocessEnv()
		);

		return [$process, $pipes];
	}

	/** @return array{0: resource, 1: array} */
	private static function startDeleteSubprocess(string $table, int $id): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/label-retirement-delete-subprocess-helper.php', $table, (string)$id],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			self::subprocessEnv()
		);

		return [$process, $pipes];
	}

	/** @return array{status: int, stderr: string, error_message?: string} */
	private static function finishSubprocess(array $processAndPipes): array
	{
		[$process, $pipes] = $processAndPipes;
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the subprocess printed no JSON. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;

		return $result;
	}

	/**
	 * Blocks the calling PHP process (not the database) until some other backend is waiting
	 * on the given advisory lock, or fails the test after $timeoutSeconds - the same bounded
	 * pg_locks poll ImporterPrintJobLockConcurrencyTest::waitForGateWaiter() uses, scoped to
	 * this class's own coordination gate.
	 */
	private static function waitForGateWaiter(int $gateClass, int $gateObject, float $timeoutSeconds = 10.0): void
	{
		$check = self::Pdo()->prepare('SELECT pid FROM pg_locks WHERE locktype = \'advisory\' AND NOT granted AND classid = ? AND objid = ? LIMIT 1');
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$check->execute([$gateClass, $gateObject]);

			if ($check->fetchColumn() !== false)
			{
				return;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail('Timed out waiting for the claim-hold subprocess to pause on the coordination gate - it should have reached that point immediately after taking the print_jobs row lock');
	}

	/**
	 * The assertion this whole class exists for: the delete subprocess genuinely queued, in
	 * PostgreSQL's own lock manager, behind the row lock the claim holder's own UPDATE
	 * already took. Matched by the delete subprocess's own `application_name`
	 * (label-retirement-delete-subprocess-helper.php sets it precisely so this can), the same
	 * reasoning LabelRetirementRacesReprintTest::waitForRowLockWaiter() already uses - an
	 * earlier version of this method polled `pg_locks` for "any" ungranted `transactionid`
	 * lock on the whole server (CodeRabbit review of PR #626), which could return true for a
	 * wait that has nothing to do with this test's own two subprocesses.
	 *
	 * Unlike ImporterPrintJobLockConcurrencyTest's own waitForRelationWaiter() - which polls
	 * for a `locktype = 'relation'` wait, because that class's contention is a table-level
	 * `LOCK TABLE ... ACCESS EXCLUSIVE` conflicting with `FOR UPDATE OF j`'s table-level ROW
	 * SHARE request - this class's contention is two ordinary row-level UPDATEs on the exact
	 * same row, which PostgreSQL represents as a wait on `locktype = 'transactionid'` rather
	 * than `relation`. `wait_event_type = 'Lock'` alone is enough here without naming that
	 * locktype explicitly: the delete subprocess never takes any other kind of lock this test
	 * could mistake for it.
	 *
	 * Fails the test after $timeoutSeconds, which is exactly what happens with
	 * cancel_queued_label_jobs()'s row lock removed (an unfixed UPDATE with no WHERE clause
	 * matching this row, or one that simply does not touch print_jobs at all): the delete
	 * subprocess then finishes almost immediately instead of queuing, and this poll times
	 * out.
	 */
	private static function waitForRowLockWaiter(float $timeoutSeconds = 10.0): void
	{
		$check = self::Pdo()->prepare("SELECT pid FROM pg_stat_activity WHERE application_name = 'label-retirement-delete-helper' AND wait_event_type = 'Lock' LIMIT 1");
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			if ($check->execute() && $check->fetchColumn() !== false)
			{
				return;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail('Timed out waiting for the delete subprocess to block on the claim holder\'s row lock - a concurrent label retirement must queue behind an already-claimed job\'s row lock, not run unobstructed while it is mid-transaction');
	}

	/**
	 * See this class's own docblock for the full scenario. Before the fix (migration
	 * 0296.pgsql.sql not applied, so no trigger calls cancel_queued_label_jobs() at all),
	 * this fails at waitForRowLockWaiter() - the delete subprocess is never observed
	 * blocked, because retire_product_labels does nothing that touches print_jobs and so
	 * nothing conflicts with the claim holder's row lock:
	 *
	 *   1) testConcurrentRetirementQueuesBehindAnAlreadyClaimedJobAndLeavesItAlone
	 *      Timed out waiting for a backend to block on the claim holder's row lock - a
	 *      concurrent label retirement must queue behind an already-claimed job's row lock,
	 *      not run unobstructed while it is mid-transaction
	 *
	 * After the fix, the retirement is observed blocked, the claim holder is then allowed to
	 * commit, and the retirement - unblocked only once the claim committed - leaves the job
	 * claimed rather than cancelling it.
	 */
	public function testConcurrentRetirementQueuesBehindAnAlreadyClaimedJobAndLeavesItAlone(): void
	{
		$db = self::Pdo();

		$db->exec("INSERT INTO locations (name) VALUES ('Race516 location')");
		$db->exec("INSERT INTO quantity_units (name) VALUES ('Race516 qu')");
		$workerId = (int)$db->query("INSERT INTO label_workers (name, configuration_mode) VALUES ('Race516 worker', 'declared') RETURNING id")->fetchColumn();

		$productId = (int)$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ("
			. "'Race516 product', (SELECT id FROM locations WHERE name = 'Race516 location'), "
			. "(SELECT id FROM quantity_units WHERE name = 'Race516 qu'), (SELECT id FROM quantity_units WHERE name = 'Race516 qu')) RETURNING id")->fetchColumn();

		$labelUid = 'F' . strtoupper(substr(md5((string)random_int(0, PHP_INT_MAX)), 0, 12));
		$db->exec("INSERT INTO labels (uid, kind, target_id) VALUES ('$labelUid', 'product', $productId)");

		$outboxId = (int)$db->query("INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id")->fetchColumn();
		$jobId = (int)$db->query("INSERT INTO print_jobs (outbox_id, printer_id, label_uid) VALUES ($outboxId, 9601, '$labelUid') RETURNING id")->fetchColumn();

		$gateObject = getmypid();
		$gate = self::secondConnection();
		$gate->query('SELECT pg_advisory_lock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

		$claimProcess = self::startClaimHoldSubprocess($jobId, $workerId, self::GATE_CLASS, $gateObject);
		$deleteProcess = null;
		$claimResult = null;
		$deleteResult = null;

		try
		{
			// The claim-hold subprocess reaches its pause only after current_attempt_id has
			// already been set, still uncommitted - see label-retirement-claim-hold-
			// subprocess-helper.php.
			self::waitForGateWaiter(self::GATE_CLASS, $gateObject);

			$deleteProcess = self::startDeleteSubprocess('products', $productId);

			// The decisive assertion: with the fix in place, the retirement's own row-level
			// UPDATE inside cancel_queued_label_jobs() conflicts with the row lock the
			// paused claim holder already took.
			self::waitForRowLockWaiter();
		}
		finally
		{
			// Always released, so neither subprocess is ever left blocked forever: a
			// failure above (the unfixed-code case) must still let the claim holder finish
			// and exit rather than hang the test run.
			$gate->query('SELECT pg_advisory_unlock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

			// Always drained and waited for too, even when the assertion above already
			// failed, for the same reason ImporterPrintJobLockConcurrencyTest reaps both of
			// its own subprocesses unconditionally here: otherwise both are still running -
			// still holding open connections into this test's schema - when PHPUnit moves
			// straight on to tearDownAfterClass()'s DROP SCHEMA ... CASCADE.
			$claimResult = self::finishSubprocess($claimProcess);

			if ($deleteProcess !== null)
			{
				$deleteResult = self::finishSubprocess($deleteProcess);
			}
		}

		self::assertSame(200, $claimResult['status'], 'setup: the claim hold itself must succeed: ' . ($claimResult['error_message'] ?? '') . ' ' . $claimResult['stderr']);
		self::assertNotNull($claimResult['current_attempt_id'], 'setup: the claim hold must have set current_attempt_id before this test\'s assertions run');

		self::assertNotNull($deleteResult, 'setup: the delete subprocess must have been started');
		self::assertSame(200, $deleteResult['status'], 'the concurrent retirement (DELETE FROM products) must not itself error: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		$jobRow = $db->query("SELECT current_attempt_id, cancelled_at, cancelled_reason FROM print_jobs WHERE id = $jobId")->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($jobRow['current_attempt_id'], 'the job is still claimed - retirement must never undo an existing claim');
		self::assertNull($jobRow['cancelled_at'], 'a job already claimed when its label retires, concurrently, is left uncancelled (D2: leave running jobs untouched)');
		self::assertNull($jobRow['cancelled_reason']);

		$outboxRow = $db->query("SELECT delivered_at, dead_lettered_at FROM outbox WHERE id = $outboxId")->fetch(PDO::FETCH_ASSOC);
		self::assertNull($outboxRow['delivered_at']);
		self::assertNull($outboxRow['dead_lettered_at'], 'the claimed job\'s outbox row must not be dead-lettered by a retirement that could not cancel it');

		self::assertSame(
			1,
			(int)$db->query("SELECT count(*) FROM labels WHERE uid = '$labelUid' AND retired_at IS NOT NULL")->fetchColumn(),
			'the label itself is still retired - only the already-claimed job is left alone, not the retirement'
		);
	}
}
