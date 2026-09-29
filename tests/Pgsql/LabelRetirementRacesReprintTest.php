<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #516 (M16, #487 remediation), maintainer decision D2 - a race a PR validator found
 * in review: `LabelOperationsService::AssertLabelLive()` (used by `Reprint()`,
 * `PromotePreview()`, and now `RevisedPrint()` too) read whether a label is still live with a
 * bare `SELECT`, no row lock. A retirement's own `UPDATE labels ... WHERE retired_at IS NULL`
 * (every retire_*_labels trigger, migrations/0296.pgsql.sql) could therefore commit *after*
 * one of these had already read "still live" and gone on to create a new `print_jobs` row -
 * a row that did not exist yet when the retirement's own `cancel_queued_label_jobs()` ran
 * over "whatever is queued for this label right now". That job would never be cancelled:
 * `PrintAttemptService::Claim()`'s own retired-label exclusion still stops it from ever
 * printing, but it sits queued forever instead of cancelled, which is exactly the inaccurate
 * monitor state D2 exists to prevent.
 *
 * The fix: `AssertLabelLive()` now takes `FOR SHARE` on the label's row, held through job
 * creation by the same caller-owned transaction every other write in this class already
 * relies on.
 *
 * This test exercises **`Reprint()`**, not `RevisedPrint()`, deliberately.
 * `RevisedPrint()` also calls `LabelIdentityService::Issue()`, which takes its own `FOR
 * UPDATE` on the target *entity* row (`locations.id` for a location) first - already enough,
 * on its own, to serialise against a concurrent `DELETE` of that same row (see
 * LabelRevisedPrintNeverDeadlocksWithRetirementTest for that call chain's own race), which
 * would make a test built around `RevisedPrint()` pass even with `AssertLabelLive()`'s
 * `FOR SHARE` reverted, and prove nothing about it. `Reprint()` never touches the entity
 * table: it locks only the label (`AssertLabelLive()`) and then the source `print_jobs` row
 * (`FOR UPDATE`). So `Reprint()`'s race protection depends entirely on `AssertLabelLive()`'s
 * own `FOR SHARE` lock, with no other lock to fall back on - the one operation that actually
 * isolates what this fix does.
 *
 * The two-connection proof, following the same advisory-lock-gate pattern
 * LabelRetirementCancelsClaimedJobRaceTest already established:
 *
 *   - a "reprint holder" subprocess (label-reprint-hold-subprocess-helper.php) runs a real
 *     Reprint() to completion - including creating its print_jobs row - inside its own open
 *     transaction, then pauses on a coordination advisory lock, still holding the FOR SHARE
 *     lock its own label read took;
 *   - once that subprocess is observed paused, a "retire" subprocess
 *     (label-retirement-delete-subprocess-helper.php) runs the real production DELETE a
 *     location's own retirement path uses - which fires retire_location_labels, which fires
 *     cancel_queued_label_jobs(), whose own UPDATE contends for the same labels row;
 *   - the test asserts the retire subprocess is observed genuinely blocked, matched by its
 *     own `application_name` in `pg_stat_activity` (see this class's own
 *     waitForRowLockWaiter());
 *   - only then does the test release the reprint holder's pause, letting it commit (the
 *     reprint's job now exists, committed);
 *   - the previously queued retirement then proceeds - now able to see the job the reprint
 *     holder just committed - and is asserted to have cancelled it, along with retiring the
 *     label itself.
 *
 * Both subprocess helpers set an explicit `statement_timeout` (20s) and this test bounds its
 * own pg_locks polling the same way, per FIXER_RULES.md's requirement that a concurrency
 * test never hold the shared suite lock on a hang.
 */
class LabelRetirementRacesReprintTest extends PgsqlSchemaTestCase
{
	/** Session-level advisory lock pair coordinating this class's own two subprocesses; not used by any production code. Distinct from LabelRetirementCancelsClaimedJobRaceTest's own gate class. */
	private const GATE_CLASS = 1986600517;

	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** A fresh worker advertising the fixture driver, and a printer assigned to it - the same shape LabelJobLifecycleTest::newPrinter() already establishes. */
	private static function newPrinter(): int
	{
		$db = self::Pdo();
		$worker = (int)$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('reprint-race-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		$db->beginTransaction();
		(new DriverRegistryService($db))->Register($worker, [self::driverDefinition()]);
		$db->commit();
		$db->beginTransaction();
		$printer = (int)(new PrinterConfigurationService($db))->Save([
			'name' => 'Reprint race printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc', 'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]);
		$db->commit();
		return $printer;
	}

	/** A QR-only, location-kind template, published once - appearance is not the subject here. */
	private static function publishLocationTemplate(): int
	{
		$db = self::Pdo();
		$templates = new LabelTemplateService($db);
		$db->beginTransaction();
		$template = (int)$templates->Create('Reprint race QR label', null, 'location', 9000)['id'];
		$db->commit();
		$draft = $templates->GetDraft($template);
		$document = ['schema_version' => 1, 'entity_kind' => 'location',
			'canvas' => ['width_mm' => 62.0, 'height_mm' => 30.0, 'max_height_mm' => null, 'margins_mm' => ['top' => 1.0, 'right' => 1.0, 'bottom' => 1.0, 'left' => 1.0]],
			'elements' => [['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
				'ec_level' => 'M', 'quiet_zone_modules' => 4, 'color' => 'black', 'source' => 'label.payload']]];
		$db->beginTransaction();
		$templates->SaveDraft($template, $document, $draft['revision_token'], 9000);
		$db->commit();
		$db->beginTransaction();
		$templates->Publish($template, 9000);
		$db->commit();
		return $template;
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
	private static function startReprintHoldSubprocess(int $sourceJobId, int $printerId, int $gateClass, int $gateObject): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/label-reprint-hold-subprocess-helper.php', (string)$sourceJobId, (string)$printerId, (string)$gateClass, (string)$gateObject],
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

	/** The same bounded pg_locks poll LabelRetirementCancelsClaimedJobRaceTest::waitForGateWaiter() uses, scoped to this class's own coordination gate. */
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

		self::fail('Timed out waiting for the reprint-hold subprocess to pause on the coordination gate - it should have reached that point immediately after Reprint() created its job');
	}

	/**
	 * Matches the delete subprocess by its own `application_name`
	 * (label-retirement-delete-subprocess-helper.php sets it precisely so this can), rather
	 * than polling for "any" backend blocked on "any" lock: an unrelated wait elsewhere
	 * (another test's leftover activity, a background autovacuum, anything) would otherwise
	 * make this method return early without the delete subprocess actually being blocked at
	 * all - which is exactly what happened before this fix: the assertion below did not
	 * reliably fail the way the "before" evidence in this method's own history describes;
	 * the test instead ran to completion and failed at its own final assertion
	 * (`cancelled_at` still null) rather than here. Scoping the poll to the one backend this
	 * test actually cares about makes the failure land at the right place.
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

		self::fail('Timed out waiting for the delete subprocess to block on the reprint holder\'s FOR SHARE lock - a concurrent label retirement must queue behind a mid-transaction reprint, not run unobstructed while it is still creating its job');
	}

	/**
	 * See this class's own docblock for the full scenario. Before the fix (`FOR SHARE`
	 * removed from AssertLabelLive()), this fails at its own final assertion - the reprint
	 * job's `cancelled_at` is still null - because with no lock to hold, the delete
	 * subprocess never blocks and instead runs straight through: it retires the label and
	 * cancels whatever is queued for it *before* the reprint holder's job even exists,
	 * committing without cancelling anything. `waitForRowLockWaiter()` itself may or may not
	 * observe a wait in that state (there is nothing left to wait *for*), so it is the final
	 * assertion - not that intermediate poll - that is the reliable "before" evidence:
	 *
	 *   1) testConcurrentReprintCommitsAJobThatRetirementStillCancels
	 *      The reprint job, committed only after the retirement waited behind its FOR SHARE
	 *      lock, is still visible to that retirement's cancellation - it does not escape as
	 *      an uncancellable, permanently queued job
	 *      Failed asserting that null is not null.
	 *
	 * After the fix, the retirement is observed blocked (waitForRowLockWaiter() passes
	 * because there really is something to wait for now), the reprint holder is then allowed
	 * to commit its job, and the retirement - unblocked only once that commit landed - is
	 * asserted to have cancelled the very job that was racing it into existence.
	 */
	public function testConcurrentReprintCommitsAJobThatRetirementStillCancels(): void
	{
		$db = self::Pdo();
		$printerId = self::newPrinter();
		$templateId = self::publishLocationTemplate();

		$locationId = (int)$db->query("INSERT INTO locations(name) VALUES ('Reprint race shelf " . bin2hex(random_bytes(4)) . "') RETURNING id")->fetchColumn();

		// The source job Reprint() replays: issued and rendered like any other, fully
		// committed before either subprocess starts.
		$db->beginTransaction();
		$sourceJobId = (int)(new LabelPrintJobService($db))->Enqueue($locationId, 0, $printerId, $templateId);
		$db->commit();
		\renderAndAttach($db, $sourceJobId);

		$labelUid = $db->query("SELECT uid FROM labels WHERE kind = 'location' AND target_id = $locationId")->fetchColumn();
		self::assertNotFalse($labelUid, 'Precondition: the location has a live label to reprint');
		self::assertNotNull(
			$db->query("SELECT artifact_id FROM print_jobs WHERE id = $sourceJobId")->fetchColumn(),
			'Precondition: the source job is rendered - Reprint() refuses one with no artifact to replay'
		);

		$gateObject = getmypid();
		$gate = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$gate->exec('SET search_path TO ' . self::Schema() . ', public');
		$gate->query('SELECT pg_advisory_lock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

		$reprintProcess = self::startReprintHoldSubprocess($sourceJobId, $printerId, self::GATE_CLASS, $gateObject);
		$deleteProcess = null;
		$reprintResult = null;
		$deleteResult = null;

		try
		{
			// The reprint-hold subprocess reaches its pause only after Reprint() has already
			// created its print_jobs row, still uncommitted - see
			// label-reprint-hold-subprocess-helper.php.
			self::waitForGateWaiter(self::GATE_CLASS, $gateObject);

			$deleteProcess = self::startDeleteSubprocess('locations', $locationId);

			// The decisive assertion: with the fix in place, the retirement's own row-level
			// UPDATE on the labels row conflicts with the FOR SHARE lock the paused reprint
			// holder already took.
			self::waitForRowLockWaiter();
		}
		finally
		{
			$gate->query('SELECT pg_advisory_unlock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

			$reprintResult = self::finishSubprocess($reprintProcess);

			if ($deleteProcess !== null)
			{
				$deleteResult = self::finishSubprocess($deleteProcess);
			}
		}

		self::assertSame(200, $reprintResult['status'], 'setup: the reprint itself must succeed: ' . ($reprintResult['error_message'] ?? '') . ' ' . $reprintResult['stderr']);
		$reprintedJobId = (int)$reprintResult['job_id'];
		self::assertNotSame($sourceJobId, $reprintedJobId, 'Precondition: Reprint() creates a new job distinct from its source');

		self::assertNotNull($deleteResult, 'setup: the delete subprocess must have been started');
		self::assertSame(200, $deleteResult['status'], 'the concurrent retirement (DELETE FROM locations) must not itself error: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		$jobRow = $db->query("SELECT cancelled_at, cancelled_reason FROM print_jobs WHERE id = $reprintedJobId")->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($jobRow['cancelled_at'],
			'The reprint job, committed only after the retirement waited behind its FOR SHARE lock, is still visible to that retirement\'s cancellation - it does not escape as an uncancellable, permanently queued job');
		self::assertSame('label retired', $jobRow['cancelled_reason']);

		self::assertSame(
			1,
			(int)$db->query('SELECT count(*) FROM labels WHERE uid = ' . $db->quote($labelUid) . ' AND retired_at IS NOT NULL')->fetchColumn(),
			'The label itself is still retired'
		);
	}
}
