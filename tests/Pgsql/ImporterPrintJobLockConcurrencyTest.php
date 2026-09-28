<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * CodeRabbit review of PR #561 (issue #487 remediation): LabelIdentityService::LockImport()
 * (taken at the top of DatabaseImporter::ImportSnapshot()) serialises label issuance only -
 * PrintAttemptService::Claim() never takes it, and takes its own `FOR UPDATE OF j` row lock
 * on print_jobs instead (PrintAttemptService.php:50). Without a table lock of its own, a
 * label worker still running against the target could claim a print job and be handed its
 * payload while a force import is in progress, before the copy loop's `TRUNCATE ... CASCADE`
 * on `api_keys` cascades away the worker's credentials - and ClearOutbox() would then
 * dead-letter the same job (`outcome = 'dead_lettered'`, "never going to be delivered") while
 * the worker might still print the payload it already received.
 *
 * DatabaseImporter::ImportSnapshot() now closes the window with
 * `LOCK TABLE print_jobs IN ACCESS EXCLUSIVE MODE`, taken immediately after LockImport() and
 * held for the rest of the transaction (through ClearOutbox(), which runs inside the same
 * transaction - see both methods' docblocks). This is the two-connection, two-subprocess
 * regression test that lock closes:
 *
 *   - the import subprocess (importer-lock-subprocess-helper.php) runs a real
 *     DatabaseImporter::Import(true) and pauses, mid-transaction, immediately after taking
 *     the print_jobs lock - reusing DatabaseImporter's own $progress callable as the pause
 *     seam, not a hook added to production code;
 *   - once the import is observed paused (still holding the lock), a second subprocess
 *     (print-attempt-claim-subprocess-helper.php) calls the real
 *     PrintAttemptService::Claim() for the worker that owns the printer this job targets;
 *   - the test asserts that claim is observed blocked, in pg_locks, on the print_jobs
 *     relation - the ROW SHARE table lock its own FOR UPDATE needs conflicts with the
 *     import's ACCESS EXCLUSIVE one;
 *   - only then does the test release the import's pause, letting it finish (dead-lettering
 *     this job's outbox row and finishing the job, per ClearOutbox()) and commit;
 *   - the previously queued claim then proceeds and is asserted to return nothing for this
 *     job - its own `WHERE ... AND o.dead_lettered_at IS NULL` (PrintAttemptService.php:48)
 *     now excludes the row the import just dead-lettered - and no print_attempts row for it
 *     exists afterward.
 *
 * The fixture's outbox payload (`{}`) is deliberately unreadable by
 * PrintJobPayload::DescribeUnreadable() - the same minimal shape
 * ImporterIntegrityTest::testImportDeadLettersAPendingPrintJobsOutboxRowAndFinishesTheJob()
 * uses, and for the same reason its own comment gives (see that test): building a fixture
 * that also clears Claim()'s deeper dispatch checks (a valid v2 payload, a registered driver
 * whose settings schema and capability document actually validate, a matching
 * label_worker_capabilities row, and a real label_artifacts row with its own
 * label_render_requests/label_media_profiles/label_captures dependencies) means reproducing
 * most of LabelServicesTest.php's fixture apparatus for a property that does not need it:
 * whether Claim()'s `FOR UPDATE OF j` blocks on the import's table lock is decided entirely
 * by PostgreSQL's lock manager before a single row (or its payload) is ever read, so an
 * unreadable payload still proves the blocking property this class exists to cover. What an
 * unreadable payload cannot show is the *unfixed* code returning a populated claim for this
 * exact job - Claim() would dead-letter it on its own the instant it could read it, whether
 * or not the import raced it - so the decisive before/after evidence this class's own test
 * relies on is the blocking assertion, not the emptiness of the claim (see that test's own
 * comment and this PR's Verification section for the before-fix failure it produces).
 */
class ImporterPrintJobLockConcurrencyTest extends PgsqlSchemaTestCase
{
	/** Session-level advisory lock pair coordinating this class's own two subprocesses; not used by any production code. */
	private const GATE_CLASS = 1986600501;

	private array $files = [];

	protected function tearDown(): void
	{
		foreach ($this->files as $file)
		{
			foreach ([$file, $file . '-wal', $file . '-shm'] as $path)
			{
				if (file_exists($path))
				{
					unlink($path);
				}
			}
		}
		$this->files = [];
	}

	/** A scratch copy of a committed fixture, as a file path a subprocess can open on its own connection. */
	private function sourceCopyPath(int $version): string
	{
		$file = tempnam(sys_get_temp_dir(), 'importer-lock-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);

		return $file;
	}

	/** A minimal worker -> driver -> printer chain, matching ImporterIntegrityTest's own fixture. */
	private function labelPrinterFixture(): array
	{
		$db = self::Pdo();
		$suffix = bin2hex(random_bytes(4));

		$workerId = (int)$db->query("INSERT INTO label_workers (name, configuration_mode) VALUES ('importer-lock-worker-$suffix', 'declared') RETURNING id")->fetchColumn();
		$db->exec('INSERT INTO label_drivers (driver_id, schema_version, contract_version, connection_types, discriminator_properties, combination_binding, settings_schemas, capability_document, registered_by_worker_id) VALUES '
			. "('importer-lock-driver-$suffix', '1', 1, '[]'::jsonb, '{}'::jsonb, '{}'::jsonb, '{}'::jsonb, '{}'::jsonb, $workerId)");
		$printerId = (int)$db->query('INSERT INTO label_printers (name, active, is_default, worker_id, driver_id, driver_schema_version, connection, connection_type, model, settings, settings_validated_at) VALUES '
			. "('importer-lock-printer-$suffix', 1, 0, $workerId, 'importer-lock-driver-$suffix', '1', 'tcp://localhost:9100', 'network', 'fixture', '{}'::jsonb, CURRENT_TIMESTAMP) RETURNING id")->fetchColumn();

		return [$workerId, $printerId];
	}

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
	private static function startImportSubprocess(string $sourcePath, int $gateClass, int $gateObject): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/importer-lock-subprocess-helper.php', $sourcePath, (string)$gateClass, (string)$gateObject],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			self::subprocessEnv()
		);

		return [$process, $pipes];
	}

	/** @return array{0: resource, 1: array} */
	private static function startClaimSubprocess(int $workerId): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/print-attempt-claim-subprocess-helper.php', (string)$workerId],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			self::subprocessEnv()
		);

		return [$process, $pipes];
	}

	/** @return array{status: int, stderr: string, messages?: array, claimed?: array, error_message?: string} */
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
	 * on the given advisory lock, or fails the test after $timeoutSeconds - the bounded
	 * pg_locks poll StockConcurrencyTest::waitForAdvisoryWaiter() uses, scoped here to this
	 * class's own coordination gate rather than the stock booking lock class.
	 */
	private static function waitForGateWaiter(int $gateClass, int $gateObject, float $timeoutSeconds = 10.0): int
	{
		$check = self::Pdo()->prepare('SELECT pid FROM pg_locks WHERE locktype = \'advisory\' AND NOT granted AND classid = ? AND objid = ? LIMIT 1');
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$check->execute([$gateClass, $gateObject]);
			$pid = $check->fetchColumn();

			if ($pid !== false)
			{
				return (int)$pid;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail('Timed out waiting for the import subprocess to pause on the coordination gate - it should have reached that point immediately after taking the print_jobs lock');
	}

	/**
	 * The assertion this whole class exists for: a backend genuinely queued, in PostgreSQL's
	 * own lock manager, behind an ACCESS EXCLUSIVE lock on $table. Fails the test after
	 * $timeoutSeconds - which is exactly what happens with DatabaseImporter's
	 * `LOCK TABLE print_jobs ...` line removed: PrintAttemptService::Claim()'s
	 * `FOR UPDATE OF j` then has nothing to queue behind, the claim subprocess finishes
	 * almost immediately, and this poll times out. See this test method's own comment for
	 * that failing run's quoted output.
	 */
	private static function waitForRelationWaiter(string $table, float $timeoutSeconds = 10.0): int
	{
		$check = self::Pdo()->prepare("SELECT pid FROM pg_locks WHERE locktype = 'relation' AND NOT granted AND relation = ?::regclass LIMIT 1");
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$check->execute([$table]);
			$pid = $check->fetchColumn();

			if ($pid !== false)
			{
				return (int)$pid;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail("Timed out waiting for a backend to block on the $table relation lock - a concurrent PrintAttemptService::Claim() must queue behind DatabaseImporter's print_jobs lock, not run unobstructed while the import is mid-transaction");
	}

	/**
	 * See this class's own docblock for the full scenario. Before the fix (the
	 * `LOCK TABLE print_jobs IN ACCESS EXCLUSIVE MODE` statement in
	 * DatabaseImporter::ImportSnapshot() commented out), this fails at
	 * waitForRelationWaiter() - the claim subprocess is never observed blocked, because
	 * nothing conflicts with its FOR UPDATE OF j row lock:
	 *
	 *   1) testConcurrentClaimQueuesBehindTheImportAndSeesTheJobDeadLettered
	 *      Timed out waiting for a backend to block on the print_jobs relation lock - a
	 *      concurrent PrintAttemptService::Claim() must queue behind DatabaseImporter's
	 *      print_jobs lock, not run unobstructed while the import is mid-transaction
	 *
	 * After the fix, the claim is observed blocked, the import is then allowed to finish,
	 * and the claim - unblocked only once the import committed - returns nothing for the job
	 * the import just dead-lettered.
	 */
	public function testConcurrentClaimQueuesBehindTheImportAndSeesTheJobDeadLettered(): void
	{
		$db = self::Pdo();
		[$workerId, $printerId] = $this->labelPrinterFixture();

		// The same minimal, deliberately-unreadable-payload shape as
		// ImporterIntegrityTest::testImportDeadLettersAPendingPrintJobsOutboxRowAndFinishesTheJob()'s
		// job A - see this class's own docblock for why that is enough here.
		$outboxId = (int)$db->query("INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id")->fetchColumn();
		$jobId = (int)$db->query('INSERT INTO print_jobs (outbox_id, printer_id, label_uid) VALUES ('
			. $outboxId . ', ' . $printerId . ", 'RACEJOB1DEKTSV4RRFFQ69') RETURNING id")->fetchColumn();

		$sourcePath = $this->sourceCopyPath(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);

		$gateObject = getmypid();
		$gate = self::secondConnection();
		$gate->query('SELECT pg_advisory_lock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

		$importProcess = self::startImportSubprocess($sourcePath, self::GATE_CLASS, $gateObject);
		$claimProcess = null;
		$importResult = null;
		$claimResult = null;

		try
		{
			// The import subprocess reaches its pause only after
			// LOCK TABLE print_jobs IN ACCESS EXCLUSIVE MODE has already been taken - see
			// importer-lock-subprocess-helper.php and ImportSnapshot() itself.
			self::waitForGateWaiter(self::GATE_CLASS, $gateObject);

			$claimProcess = self::startClaimSubprocess($workerId);

			// The decisive assertion: with the fix in place, the claim's own row lock
			// conflicts with the table lock the paused import already holds.
			self::waitForRelationWaiter('print_jobs');
		}
		finally
		{
			// Always released, so neither subprocess is ever left blocked forever: a
			// failure above (the unfixed-code case) must still let the import finish and
			// exit rather than hang the test run.
			$gate->query('SELECT pg_advisory_unlock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

			// Always drained and waited for too, even when the assertion above already
			// failed: otherwise both subprocesses are still running - still holding open
			// connections into this test's schema - when PHPUnit moves straight on to
			// tearDownAfterClass()'s DROP SCHEMA ... CASCADE, which can then deadlock
			// against the import subprocess's own still-in-flight TRUNCATE
			// (SQLSTATE 40P01, observed before this method reaped both processes
			// unconditionally here).
			$importResult = self::finishSubprocess($importProcess);

			if ($claimProcess !== null)
			{
				$claimResult = self::finishSubprocess($claimProcess);
			}
		}

		self::assertSame(200, $importResult['status'], 'setup: the import itself must succeed: ' . ($importResult['error_message'] ?? '') . ' ' . $importResult['stderr']);

		self::assertNotNull($claimResult, 'setup: the claim subprocess must have been started');
		self::assertSame(200, $claimResult['status'], 'the claim call itself must not error: ' . ($claimResult['error_message'] ?? '') . ' ' . $claimResult['stderr']);
		self::assertSame([], $claimResult['claimed'],
			'the claim, unblocked only once the import committed, must return nothing for the job the import just dead-lettered');

		$jobRow = $db->query('SELECT outcome, outcome_at FROM print_jobs WHERE id = ' . $jobId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame('dead_lettered', $jobRow['outcome'], 'the import finished the job while the queued claim waited behind its lock');
		self::assertNotNull($jobRow['outcome_at']);

		$outboxRow = $db->query('SELECT delivered_at, dead_lettered_at FROM outbox WHERE id = ' . $outboxId)->fetch(PDO::FETCH_ASSOC);
		self::assertNull($outboxRow['delivered_at']);
		self::assertNotNull($outboxRow['dead_lettered_at']);

		self::assertSame(0, (int)$db->query('SELECT count(*) FROM print_attempts WHERE job_id = ' . $jobId)->fetchColumn(),
			'no attempt was ever inserted for this job - the queued claim never got far enough to read it as claimable');
	}
}
