<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * PR #626 delta review (Opus), third round: the entity-row-gated tests
 * (LabelRevisedPrintNeverDeadlocksWithRetirementTest,
 * LabelRevisedPrintCascadeCancelsStockEntryJobTest) both pre-hold the entity/stock row a
 * gate connection controls, which means `RevisedPrint()`'s subprocess *always* wins that row
 * first once the gate releases - the retirement is still queued behind the gate too, and
 * loses the race for the same row every time. That makes those tests unable to catch a
 * mutation that puts the label lock back *before* `Issue()`'s entity lock (the exact bug PR
 * #626's first delta round introduced and the second round fixed): with `RevisedPrint()`
 * always winning the entity row, its label lock - wherever it is taken relative to that row -
 * is never contended by a retirement that never gets there first.
 *
 * This test closes that gap using the same technique the second delta round's now-removed
 * `LabelRevisedPrintNeverDeadlocksWithRetirementTest` used: a "gate" connection pre-holds
 * `LabelIdentityService::IMPORT_LOCK` (109038) itself - the `pg_advisory_xact_lock()` every
 * call to `Issue()` takes *before* it ever reaches the entity row. That forces the revised
 * print's subprocess to block *before* touching the entity row (or, under the mutation this
 * test exists to catch, after already holding the label row), while the retire subprocess is
 * left completely unobstructed to run to full completion on its own:
 *
 *   - the gate connection takes `pg_advisory_xact_lock(109038)` and holds it, uncommitted;
 *   - a "revised print" subprocess (label-revisedprint-subprocess-helper.php) runs a real
 *     RevisedPrint() call; it reaches Issue()'s own advisory lock request and blocks there;
 *   - a "retire" subprocess (label-retirement-delete-subprocess-helper.php) runs the real
 *     production `DELETE FROM locations` - entirely uncontended in the fixed order (the
 *     revised print has not touched the entity row, and does not yet hold the label either),
 *     so it always finishes and commits before the gate is even released;
 *   - releasing the gate lets the revised print proceed to `Issue()`'s entity-row `FOR
 *     UPDATE` - the row is already gone, so it refuses cleanly with an ordinary
 *     application-level error, never a raw `\PDOException`.
 *
 * Under the mutation (label lock moved back to *before* `Issue()`, manually verified
 * separately, not committed in that form): the revised print already holds the label row by
 * the time it blocks on the import lock, so the retire subprocess - unobstructed on the
 * entity row but now blocked on the label - cannot finish either. Releasing the gate then
 * lets the revised print proceed to request the entity row the retirement already holds,
 * closing the cycle: revised print waits on the entity row (held by the retirement);
 * retirement waits on the label row (held by the revised print). PostgreSQL's own deadlock
 * detector aborts one side with SQLSTATE 40P01 within its `deadlock_timeout` (default 1s).
 *
 * Both subprocess helpers set an explicit `statement_timeout` (20s) and this test bounds its
 * own pg_stat_activity polling the same way, per FIXER_RULES.md's requirement that a
 * concurrency test never hold the shared suite lock on a hang.
 */
class LabelRevisedPrintLockOrderMutationTest extends PgsqlSchemaTestCase
{
	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private static function newPrinter(): int
	{
		$db = self::Pdo();
		$worker = (int)$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('revisedprint-lockorder-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		$db->beginTransaction();
		(new DriverRegistryService($db))->Register($worker, [self::driverDefinition()]);
		$db->commit();
		$db->beginTransaction();
		$printer = (int)(new PrinterConfigurationService($db))->Save([
			'name' => 'Revisedprint lockorder printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
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
		$template = (int)$templates->Create('Revisedprint lockorder QR label', null, 'location', 9000)['id'];
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
	private static function start(array $args): array
	{
		$process = proc_open(array_merge([PHP_BINARY], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::subprocessEnv());

		return [$process, $pipes];
	}

	/** @return array{status: int, stderr: string, error_message?: string, sqlstate?: string} */
	private static function finish(array $processAndPipes): array
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

	/** Matches a subprocess by its own `application_name`, not "any" waiter of some lock type. */
	private static function waitBlocked(string $applicationName, float $timeoutSeconds = 10.0): bool
	{
		$check = self::Pdo()->prepare("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$check->execute([$applicationName]);

			if ($check->fetchColumn() !== false)
			{
				return true;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		return false;
	}

	/**
	 * Waits until the revised-print subprocess is observed blocked specifically on the
	 * pre-held import advisory lock (matched by its own `application_name`, not "any"
	 * advisory waiter) - the pause point this test relies on, taken by
	 * `LabelIdentityService::LockImport()` inside `Issue()`, before that subprocess has
	 * touched the entity row at all.
	 */
	private static function waitForRevisedPrintBlockedOnImportLock(float $timeoutSeconds = 10.0): bool
	{
		$check = self::Pdo()->prepare("SELECT 1 FROM pg_stat_activity WHERE application_name = 'label-revisedprint-helper' AND wait_event_type = 'Lock' AND wait_event = 'advisory'");
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			if ($check->execute() && $check->fetchColumn() !== false)
			{
				return true;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		return false;
	}

	/**
	 * See this class's own docblock for the full scenario. Under the mutation (label lock
	 * moved back to before Issue()'s entity lock, manually verified separately, not
	 * committed in that form), this reproduces a genuine PostgreSQL deadlock: one of the two
	 * subprocesses is aborted with SQLSTATE 40P01 (deadlock_detected). With the order this
	 * test actually commits (entity, then label), both subprocesses complete without either
	 * one ever receiving that SQLSTATE.
	 */
	public function testRevisedPrintNeverDeadlocksWithAConcurrentRetirement(): void
	{
		$db = self::Pdo();
		$printerId = self::newPrinter();
		$templateId = self::publishLocationTemplate();

		$locationId = (int)$db->query("INSERT INTO locations(name) VALUES ('Revisedprint lockorder shelf " . bin2hex(random_bytes(4)) . "') RETURNING id")->fetchColumn();

		$db->beginTransaction();
		(new LabelPrintJobService($db))->Enqueue($locationId, 0, $printerId, $templateId);
		$db->commit();

		$labelUid = $db->query("SELECT uid FROM labels WHERE kind = 'location' AND target_id = $locationId")->fetchColumn();
		self::assertNotFalse($labelUid, 'Precondition: the location has a live label to revise');

		$gate = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$gate->exec('SET search_path TO ' . self::Schema() . ', public');
		$gate->beginTransaction();
		// LabelIdentityService::IMPORT_LOCK, held here rather than imported as a class
		// constant reference: this test deliberately uses the same literal key production
		// code uses, the same way a caller would, rather than reaching into that class.
		$gate->query('SELECT pg_advisory_xact_lock(109038)')->fetchColumn();

		$revisedPrintProcess = self::start([__DIR__ . '/label-revisedprint-subprocess-helper.php', 'location', (string)$locationId, '0', (string)$printerId, (string)$templateId]);
		$deleteProcess = null;
		$revisedPrintResult = null;
		$deleteResult = null;

		try
		{
			self::assertTrue(self::waitForRevisedPrintBlockedOnImportLock(), 'Timed out waiting for the revised-print subprocess to block on the pre-held import advisory lock');

			$deleteProcess = self::start([__DIR__ . '/label-retirement-delete-subprocess-helper.php', 'locations', (string)$locationId]);

			// Best-effort only, not a precondition: in the fixed order the delete subprocess
			// is never blocked at all (nothing of the revised print's is in its way yet), so
			// it may already have finished by the time this poll runs. Under the mutation
			// this test exists to catch, it genuinely blocks here.
			self::waitBlocked('label-retirement-delete-helper', 2.0);
		}
		finally
		{
			// Releases the import lock by ending the gate's own transaction - this is a
			// transaction-scoped advisory lock (pg_advisory_xact_lock), which has no explicit
			// unlock call; it is released only by COMMIT or ROLLBACK of the transaction that
			// took it.
			$gate->rollBack();

			$revisedPrintResult = self::finish($revisedPrintProcess);

			if ($deleteProcess !== null)
			{
				$deleteResult = self::finish($deleteProcess);
			}
		}

		self::assertNotNull($deleteResult, 'setup: the delete subprocess must have been started');

		self::assertNotSame('40P01', $revisedPrintResult['sqlstate'] ?? null,
			'The revised print must never be aborted by a deadlock: ' . ($revisedPrintResult['error_message'] ?? '') . ' ' . $revisedPrintResult['stderr']);
		self::assertNotSame('40P01', $deleteResult['sqlstate'] ?? null,
			'The retirement (DELETE FROM locations) must never be aborted by a deadlock: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		// The retirement never contends for anything the revised print holds in the fixed
		// ordering - it always succeeds outright.
		self::assertSame(200, $deleteResult['status'], 'the retirement must succeed: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		// The revised print either also succeeds, or refuses with an ordinary
		// application-level error (the location was deleted before it reached the entity
		// row) - never a raw, unhandled database error of any other kind.
		if ($revisedPrintResult['status'] !== 200)
		{
			self::assertNull($revisedPrintResult['sqlstate'] ?? null,
				'A refused revised print must be an application-level refusal, not a raw PDOException: ' . ($revisedPrintResult['error_message'] ?? ''));
		}

		self::assertSame(
			1,
			(int)$db->query('SELECT count(*) FROM labels WHERE uid = ' . $db->quote($labelUid) . ' AND retired_at IS NOT NULL')->fetchColumn(),
			'The label itself is retired either way'
		);
	}
}
