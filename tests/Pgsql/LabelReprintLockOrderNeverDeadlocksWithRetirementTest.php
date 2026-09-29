<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * PR #626 delta review (Opus), "cycle B": `LabelOperationsService::Reprint()` used to lock
 * its source `print_jobs` row (`FOR UPDATE`) before locking the label (`AssertLabelLive()`'s
 * own `FOR SHARE`). A concurrent retirement of the same label locks the two in the opposite
 * order: its trigger's own `UPDATE labels` first, then `cancel_queued_label_jobs()`'s own
 * `UPDATE print_jobs` second (migrations/0296.pgsql.sql) - which reaches a still-queued
 * source job for that label the same way it reaches any other queued job, because it is
 * one. Reprint() holding the source row while waiting on the label, and a retirement
 * holding the label while waiting on that same source row, is exactly a deadlock cycle:
 * reproduced deterministically, this aborted one side with SQLSTATE 40P01.
 *
 * The fix: Reprint() now locks the label first (see its own comment in
 * LabelOperationsService.php) - the same order the retirement trigger already uses - so the
 * two transactions can never form a cycle.
 *
 * Unlike LabelRevisedPrintNeverDeadlocksWithRetirementTest, Reprint() has no lock or other
 * blockable resource of its own between its two conflicting statements to pause on (
 * RevisedPrint() has LabelIdentityService::Issue()'s own advisory lock; Reprint() has
 * nothing comparable). This test therefore mirrors Reprint()'s exact two statements, in its
 * current order, via label-reprint-lockorder-hold-subprocess-helper.php, with an explicit
 * pause between them - see that file's own comment for why a literal SQL mirror is used
 * here instead of calling Reprint() itself, and for the exact statements it reproduces.
 *
 *   - a "reprint holder" subprocess (label-reprint-lockorder-hold-subprocess-helper.php)
 *     locks the label (FOR SHARE), then pauses on a coordination advisory lock, still
 *     holding that lock;
 *   - once that subprocess is observed paused, a "retire" subprocess
 *     (label-retirement-delete-subprocess-helper.php) runs the real production DELETE a
 *     retirement uses - which locks the entity row (uncontended), then its trigger tries to
 *     lock the label - blocked behind the reprint holder;
 *   - once the retire subprocess is observed blocked (matched by its own `application_name`),
 *     the reprint holder's gate is released: it proceeds to lock the source job row - which
 *     the still-blocked retire subprocess has not reached yet - and commits;
 *   - the retire subprocess's blocked label lock then releases, its trigger retires the
 *     label, and cancel_queued_label_jobs() locks and cancels the source job in turn - now
 *     uncontended, since the reprint holder already committed and released it.
 *
 * Both subprocess helpers set an explicit `statement_timeout` (20s) and this test bounds its
 * own pg_stat_activity polling the same way, per FIXER_RULES.md's requirement that a
 * concurrency test never hold the shared suite lock on a hang.
 */
class LabelReprintLockOrderNeverDeadlocksWithRetirementTest extends PgsqlSchemaTestCase
{
	/** Session-level advisory lock pair coordinating this class's own two subprocesses; not used by any production code. */
	private const GATE_CLASS = 1986600518;

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
	private static function startHoldSubprocess(string $labelUid, int $sourceJobId, int $gateClass, int $gateObject): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/label-reprint-lockorder-hold-subprocess-helper.php', $labelUid, (string)$sourceJobId, (string)$gateClass, (string)$gateObject],
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

	/** @return array{status: int, stderr: string, error_message?: string, sqlstate?: string} */
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

	/** Matches the hold subprocess by its own `application_name`, the same reasoning as LabelRetirementRacesReprintTest::waitForRowLockWaiter(). */
	private static function waitForHoldSubprocessPaused(int $gateClass, int $gateObject, float $timeoutSeconds = 10.0): void
	{
		$check = self::Pdo()->prepare(
			"SELECT pg_locks.pid FROM pg_locks JOIN pg_stat_activity a ON a.pid = pg_locks.pid
			 WHERE a.application_name = 'label-reprint-lockorder-holder'
			   AND pg_locks.locktype = 'advisory' AND NOT pg_locks.granted
			   AND pg_locks.classid = ? AND pg_locks.objid = ? LIMIT 1"
		);
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

		self::fail('Timed out waiting for the reprint-lockorder holder to pause on the coordination gate - it should have reached that point immediately after locking the label');
	}

	/** Matches the delete subprocess by its own `application_name`. */
	private static function waitForDeleteSubprocessBlocked(float $timeoutSeconds = 10.0): void
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

		self::fail('Timed out waiting for the delete subprocess to block on the reprint holder\'s label lock - a concurrent retirement must queue behind a still-locked label, not run unobstructed');
	}

	/**
	 * See this class's own docblock for the full scenario. With Reprint()'s two statements
	 * mirrored in the *reversed* (pre-fix) order - manually verified separately, not
	 * committed in that form - this reproduces a genuine PostgreSQL deadlock: one of the two
	 * subprocesses is aborted with SQLSTATE 40P01 (deadlock_detected). With the order this
	 * test actually commits (the fixed order: label first, source job second), both
	 * subprocesses complete without either one ever receiving that SQLSTATE.
	 */
	public function testReprintLockOrderNeverDeadlocksWithAConcurrentRetirement(): void
	{
		$db = self::Pdo();

		$db->exec("INSERT INTO locations (name) VALUES ('Reprint lockorder location " . bin2hex(random_bytes(4)) . "')");
		$db->exec("INSERT INTO quantity_units (name) VALUES ('Reprint lockorder qu " . bin2hex(random_bytes(4)) . "')");
		$productId = (int)$db->query(
			"INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ("
			. "'Reprint lockorder product " . bin2hex(random_bytes(4)) . "', (SELECT id FROM locations ORDER BY id DESC LIMIT 1), "
			. "(SELECT id FROM quantity_units ORDER BY id DESC LIMIT 1), (SELECT id FROM quantity_units ORDER BY id DESC LIMIT 1)) RETURNING id"
		)->fetchColumn();

		$labelUid = 'D' . strtoupper(substr(md5((string)random_int(0, PHP_INT_MAX)), 0, 12));
		$db->exec("INSERT INTO labels (uid, kind, target_id) VALUES ('$labelUid', 'product', $productId)");

		// A still-queued source job for that label - "Reprint() of a still-queued source
		// job" is exactly the scenario PR #626 review named: cancel_queued_label_jobs()
		// reaches this row too, because it is itself a queued, unclaimed job for this label.
		$outboxId = (int)$db->query("INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id")->fetchColumn();
		$sourceJobId = (int)$db->query("INSERT INTO print_jobs (outbox_id, printer_id, label_uid) VALUES ($outboxId, 9701, '$labelUid') RETURNING id")->fetchColumn();

		$gateObject = getmypid();
		$gate = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$gate->exec('SET search_path TO ' . self::Schema() . ', public');
		$gate->query('SELECT pg_advisory_lock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

		$holdProcess = self::startHoldSubprocess($labelUid, $sourceJobId, self::GATE_CLASS, $gateObject);
		$deleteProcess = null;
		$holdResult = null;
		$deleteResult = null;

		try
		{
			self::waitForHoldSubprocessPaused(self::GATE_CLASS, $gateObject);

			$deleteProcess = self::startDeleteSubprocess('products', $productId);

			self::waitForDeleteSubprocessBlocked();
		}
		finally
		{
			$gate->query('SELECT pg_advisory_unlock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

			$holdResult = self::finishSubprocess($holdProcess);

			if ($deleteProcess !== null)
			{
				$deleteResult = self::finishSubprocess($deleteProcess);
			}
		}

		self::assertNotNull($deleteResult, 'setup: the delete subprocess must have been started');

		self::assertNotSame('40P01', $holdResult['sqlstate'] ?? null,
			'The reprint holder must never be aborted by a deadlock: ' . ($holdResult['error_message'] ?? '') . ' ' . $holdResult['stderr']);
		self::assertNotSame('40P01', $deleteResult['sqlstate'] ?? null,
			'The retirement (DELETE FROM products) must never be aborted by a deadlock: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		self::assertSame(200, $holdResult['status'], 'the reprint holder must succeed: ' . ($holdResult['error_message'] ?? '') . ' ' . $holdResult['stderr']);
		self::assertSame(200, $deleteResult['status'], 'the retirement must succeed: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		$sourceRow = $db->query("SELECT cancelled_at, cancelled_reason FROM print_jobs WHERE id = $sourceJobId")->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($sourceRow['cancelled_at'], 'The still-queued source job is cancelled once its label retires, exactly like any other queued job for that label');
		self::assertSame('label retired', $sourceRow['cancelled_reason']);

		self::assertSame(
			1,
			(int)$db->query("SELECT count(*) FROM labels WHERE uid = '$labelUid' AND retired_at IS NOT NULL")->fetchColumn(),
			'The label itself is retired'
		);
	}
}
