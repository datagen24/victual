<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * PR #626 delta review (Opus, second round): the previous version of this test drove a raw
 * SQL mirror of Reprint()'s two statements (label-reprint-lockorder-hold-subprocess-helper.php)
 * rather than the real method, which the review flagged as a test-fidelity gap - a change to
 * Reprint()'s actual lock order could pass this test without the mirror being updated to
 * match. This version drives the real, unmodified
 * `LabelOperationsService::Reprint()` -> `AssertLabelLive()` call chain instead, through
 * label-reprint-hold-subprocess-helper.php, with no test-only hook added to production code.
 *
 * Reprint() locks its source `print_jobs` row (`FOR UPDATE`) and, before it, the label
 * (`AssertLabelLive()`'s own `FOR SHARE`). The deterministic pause this test needs comes from
 * pre-holding the *source job's own row* on a "gate" connection (`SELECT ... FOR UPDATE`)
 * before starting the reprint subprocess - not the label row, which is why an earlier design
 * of this test held the label row instead and could not actually force Reprint() to block at
 * a useful point (holding the label row conflicts with AssertLabelLive() itself, refusing the
 * call outright rather than pausing it mid-transaction). Reprint()'s own internal
 * `FOR UPDATE` on that pre-locked source row is what blocks:
 *
 *   - a "reprint" subprocess (label-reprint-hold-subprocess-helper.php) runs a real
 *     Reprint() call; it passes AssertLabelLive() (the label is still live and nothing else
 *     holds it yet) and then blocks on its own `FOR UPDATE` of the source row, which the gate
 *     already holds;
 *   - once that subprocess is observed blocked, a "retire" subprocess
 *     (label-retirement-delete-subprocess-helper.php) runs the real production
 *     `DELETE FROM locations` - which locks the entity row (uncontended) and then, inside its
 *     trigger, tries to lock the label - blocked behind the reprint subprocess's own
 *     `FOR SHARE` (still held, since that transaction has not committed);
 *   - releasing the gate lets the reprint subprocess's `FOR UPDATE` succeed, finish, and
 *     commit (releasing the label lock too);
 *   - the retirement then proceeds, retires the label, and cancels the very job the reprint
 *     subprocess just committed.
 *
 * With Reprint()'s two statements in the *reversed* (pre-fix) order - manually verified
 * separately, not committed in that form - releasing the gate produces a genuine PostgreSQL
 * deadlock (SQLSTATE 40P01): the reprint subprocess holds the source row (from the gate) and
 * waits on the label (held by the now-blocked retirement); the retirement holds the label
 * and waits on the source row (held by the reprint subprocess, still mid-transaction because
 * it has not yet reached the label check in that reversed order). Both subprocess helpers
 * set an explicit `statement_timeout` (20s) and this test bounds its own pg_stat_activity
 * polling the same way, per FIXER_RULES.md's requirement that a concurrency test never hold
 * the shared suite lock on a hang.
 */
class LabelReprintNeverDeadlocksWithRetirementTest extends PgsqlSchemaTestCase
{
	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private static function newPrinter(): int
	{
		$db = self::Pdo();
		$worker = (int)$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('reprint-lockorder-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		$db->beginTransaction();
		(new DriverRegistryService($db))->Register($worker, [self::driverDefinition()]);
		$db->commit();
		$db->beginTransaction();
		$printer = (int)(new PrinterConfigurationService($db))->Save([
			'name' => 'Reprint lockorder printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
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
		$template = (int)$templates->Create('Reprint lockorder QR label', null, 'location', 9000)['id'];
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
	 * See this class's own docblock for the full scenario. With Reprint()'s two statements
	 * reversed (manually verified separately, not committed in that form), releasing the gate
	 * produces a genuine PostgreSQL deadlock: one of the two subprocesses is aborted with
	 * SQLSTATE 40P01 (deadlock_detected). With the order this test actually commits (the
	 * fixed order: label first, source job second), both subprocesses complete without either
	 * one ever receiving that SQLSTATE, and the retirement cancels the job the reprint
	 * created.
	 */
	public function testReprintNeverDeadlocksWithAConcurrentRetirement(): void
	{
		$db = self::Pdo();
		$printerId = self::newPrinter();
		$templateId = self::publishLocationTemplate();

		$locationId = (int)$db->query("INSERT INTO locations(name) VALUES ('Reprint lockorder shelf " . bin2hex(random_bytes(4)) . "') RETURNING id")->fetchColumn();

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

		$gate = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$gate->exec('SET search_path TO ' . self::Schema() . ', public');
		$gate->beginTransaction();
		// The source job's own row, not the label - see this class's own docblock for why.
		$gate->query("SELECT id FROM print_jobs WHERE id = $sourceJobId FOR UPDATE")->fetchColumn();

		// An unheld advisory gate: this subprocess's own internal pause (used by
		// LabelRetirementRacesReprintTest) passes straight through, since nothing this test
		// does holds that lock. The pause this test relies on is the pre-held source row above.
		$reprintProcess = self::start([__DIR__ . '/label-reprint-hold-subprocess-helper.php', (string)$sourceJobId, (string)$printerId, '1986600599', (string)getmypid()]);
		$deleteProcess = null;
		$reprintResult = null;
		$deleteResult = null;

		try
		{
			$reprintBlocked = false;
			$check = $db->prepare("SELECT 1 FROM pg_stat_activity WHERE application_name = 'label-reprint-hold-helper' AND wait_event_type = 'Lock'");
			$deadline = microtime(true) + 10;
			do
			{
				$check->execute();
				if ($check->fetchColumn() !== false)
				{
					$reprintBlocked = true;
					break;
				}
				usleep(20000);
			}
			while (microtime(true) < $deadline);
			self::assertTrue($reprintBlocked, 'Timed out waiting for the reprint subprocess to block on the gate-held source job row');

			$deleteProcess = self::start([__DIR__ . '/label-retirement-delete-subprocess-helper.php', 'locations', (string)$locationId]);
			self::assertTrue(self::waitBlocked('label-retirement-delete-helper'), 'Timed out waiting for the delete subprocess to block on the reprint subprocess\'s own label lock');
		}
		finally
		{
			$gate->rollBack();

			$reprintResult = self::finish($reprintProcess);

			if ($deleteProcess !== null)
			{
				$deleteResult = self::finish($deleteProcess);
			}
		}

		self::assertNotNull($deleteResult, 'setup: the delete subprocess must have been started');

		self::assertNotSame('40P01', $reprintResult['sqlstate'] ?? null,
			'The reprint must never be aborted by a deadlock: ' . ($reprintResult['error_message'] ?? '') . ' ' . $reprintResult['stderr']);
		self::assertNotSame('40P01', $deleteResult['sqlstate'] ?? null,
			'The retirement (DELETE FROM locations) must never be aborted by a deadlock: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		self::assertSame(200, $reprintResult['status'], 'the reprint must succeed: ' . ($reprintResult['error_message'] ?? '') . ' ' . $reprintResult['stderr']);
		self::assertSame(200, $deleteResult['status'], 'the retirement must succeed: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		$reprintedJobId = (int)$reprintResult['job_id'];
		self::assertNotSame($sourceJobId, $reprintedJobId, 'Precondition: Reprint() creates a new job distinct from its source');

		$jobRow = $db->query("SELECT cancelled_at, cancelled_reason FROM print_jobs WHERE id = $reprintedJobId")->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($jobRow['cancelled_at'],
			'The reprint job, committed only after the retirement waited behind its own label lock, is still visible to that retirement\'s cancellation - it does not escape as an uncancellable, permanently queued job');
		self::assertSame('label retired', $jobRow['cancelled_reason']);

		self::assertSame(
			1,
			(int)$db->query('SELECT count(*) FROM labels WHERE uid = ' . $db->quote($labelUid) . ' AND retired_at IS NOT NULL')->fetchColumn(),
			'The label itself is retired'
		);
	}
}
