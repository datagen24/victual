<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * PR #626 delta review (Opus), "cycle A": `LabelOperationsService::RevisedPrint()` used to
 * take `FOR SHARE` on the label's row, then call `LabelIdentityService::Issue()`, which
 * takes its own `FOR UPDATE` on the target *entity* row (`locations.id` here) - labels, then
 * entity. A concurrent retirement (`DELETE FROM locations`) locks that same entity row first
 * (implicitly, as part of the `DELETE` itself), then its `BEFORE DELETE` trigger locks the
 * label row - entity, then labels. The two transactions were locking the same two resources
 * in opposite orders - exactly what deadlocks: reproduced deterministically, this aborted
 * one side with SQLSTATE 40P01.
 *
 * The fix (second delta round): `RevisedPrint()` now takes the label lock *after* `Issue()`'s
 * entity lock, not instead of it (an earlier version of this fix removed the label lock
 * entirely, which turned out to reopen D2 for `stock_entry` labels on the product-delete
 * cascade path - see LabelRevisedPrintCascadeCancelsStockEntryJobTest). Entity, then labels,
 * on both sides now: the two can never form a cycle.
 *
 * This test exercises the real, unmodified `LabelOperationsService::RevisedPrint()` ->
 * `Issue()` -> `AssertLabelLive()` call chain, with no test-only hook added to production
 * code. The deterministic pause comes from a "gate" connection pre-holding the *entity* row
 * itself (`SELECT id FROM locations WHERE id = ? FOR UPDATE`) before either subprocess
 * starts - both a revised-print subprocess (label-revisedprint-subprocess-helper.php) and a
 * retire subprocess (label-retirement-delete-subprocess-helper.php) then queue behind it:
 *
 *   - the gate connection locks the location row and holds it, uncommitted;
 *   - the revised-print subprocess blocks trying to lock that same row inside `Issue()`;
 *   - the retire subprocess (`DELETE FROM locations`) blocks trying to lock it too;
 *   - releasing the gate lets PostgreSQL's lock queue serve one of the two first. Whichever
 *     wins, the assertion holds: if the revised print wins, it creates a job and commits,
 *     and the retirement - unblocked next - retires the label and cancels that job; if the
 *     retirement wins, it retires the label and commits first, and the revised print then
 *     refuses on the now-retired label (`AssertLabelLive()`) rather than creating anything.
 *     Either way, no queued, unclaimed job for the label survives its retirement uncancelled.
 *
 * With the label lock taken *before* `Issue()`'s entity lock (an earlier, reverted version of
 * this fix - manually verified separately, not committed in that form), releasing the gate
 * produces a genuine deadlock instead: SQLSTATE 40P01 on one side.
 *
 * Both subprocess helpers set an explicit `statement_timeout` (20s) and this test bounds its
 * own pg_stat_activity polling the same way, per FIXER_RULES.md's requirement that a
 * concurrency test never hold the shared suite lock on a hang.
 */
class LabelRevisedPrintNeverDeadlocksWithRetirementTest extends PgsqlSchemaTestCase
{
	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private static function newPrinter(): int
	{
		$db = self::Pdo();
		$worker = (int)$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('revisedprint-deadlock-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		$db->beginTransaction();
		(new DriverRegistryService($db))->Register($worker, [self::driverDefinition()]);
		$db->commit();
		$db->beginTransaction();
		$printer = (int)(new PrinterConfigurationService($db))->Save([
			'name' => 'Revisedprint deadlock printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
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
		$template = (int)$templates->Create('Revisedprint deadlock QR label', null, 'location', 9000)['id'];
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
	 * See this class's own docblock for the full scenario. With the label lock taken before
	 * Issue()'s entity lock (manually verified separately, not committed in that form), this
	 * reproduces a genuine PostgreSQL deadlock: one of the two subprocesses is aborted with
	 * SQLSTATE 40P01 (deadlock_detected). With the order this test actually commits (entity,
	 * then label, on both sides), both subprocesses complete without either one ever
	 * receiving that SQLSTATE, and no queued, unclaimed job for the label survives its
	 * retirement uncancelled.
	 */
	public function testRevisedPrintNeverDeadlocksWithAConcurrentRetirement(): void
	{
		$db = self::Pdo();
		$printerId = self::newPrinter();
		$templateId = self::publishLocationTemplate();

		$locationId = (int)$db->query("INSERT INTO locations(name) VALUES ('Revisedprint deadlock shelf " . bin2hex(random_bytes(4)) . "') RETURNING id")->fetchColumn();

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
		$gate->query("SELECT id FROM locations WHERE id = $locationId FOR UPDATE")->fetchColumn();

		$revisedPrintProcess = self::start([__DIR__ . '/label-revisedprint-subprocess-helper.php', 'location', (string)$locationId, '0', (string)$printerId, (string)$templateId]);
		$deleteProcess = null;
		$revisedPrintResult = null;
		$deleteResult = null;

		try
		{
			self::assertTrue(self::waitBlocked('label-revisedprint-helper'), 'Timed out waiting for the revised-print subprocess to block on the gate-held location row');

			$deleteProcess = self::start([__DIR__ . '/label-retirement-delete-subprocess-helper.php', 'locations', (string)$locationId]);
			self::waitBlocked('label-retirement-delete-helper');
		}
		finally
		{
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
		self::assertSame(200, $deleteResult['status'], 'the retirement must succeed: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		// The revised print either also succeeds (it won the race for the location row), or
		// refuses with an ordinary application-level error (the retirement won instead, and
		// the label is now retired) - never a raw, unhandled database error of any kind.
		if ($revisedPrintResult['status'] !== 200)
		{
			self::assertNull($revisedPrintResult['sqlstate'] ?? null,
				'A refused revised print must be an application-level refusal, not a raw PDOException: ' . ($revisedPrintResult['error_message'] ?? ''));
		}

		$retiredAt = $db->query('SELECT retired_at FROM labels WHERE uid = ' . $db->quote($labelUid))->fetchColumn();
		self::assertNotNull($retiredAt, 'The label itself is retired either way');

		$jobs = $db->query('SELECT id, cancelled_at, current_attempt_id FROM print_jobs WHERE label_uid = ' . $db->quote($labelUid) . ' ORDER BY id')
			->fetchAll(PDO::FETCH_ASSOC);
		$uncancelled = array_filter($jobs, static fn (array $j): bool => $j['cancelled_at'] === null && $j['current_attempt_id'] === null);

		self::assertSame([], array_values($uncancelled),
			'D2 violated: the label is retired but a queued, unclaimed job for it (whether the original, or one the revised print won the race to create) survives uncancelled - '
			. json_encode(['revisedPrint' => $revisedPrintResult, 'delete' => $deleteResult, 'jobs' => $jobs]));
	}
}
