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
 * take `FOR SHARE` on the label's row (added to close a different race - see
 * LabelRetirementRacesReprintTest), then call `LabelIdentityService::Issue()`, which takes
 * its own `FOR UPDATE` on the target *entity* row (`locations.id` here). A concurrent
 * retirement (`DELETE FROM locations`) locks that same entity row first (implicitly, as
 * part of the `DELETE` itself), then its `BEFORE DELETE` trigger locks the label row
 * (`retire_location_labels`, migrations/0296.pgsql.sql's `cancel_queued_label_jobs()`). The
 * two transactions were locking the same two resources in opposite orders - exactly what
 * deadlocks: reproduced deterministically, this aborted one side with SQLSTATE 40P01.
 *
 * The fix: RevisedPrint() no longer locks the label row at all (see its own comment) -
 * `Issue()`'s existing `FOR UPDATE` on the entity row already serialises it against a
 * concurrent retirement of the same target, with nothing left to gain from a second lock
 * taken in the wrong order.
 *
 * This test exercises the real, unmodified `LabelOperationsService::RevisedPrint()` -> `Issue()`
 * call chain, with no test-only hook added to production code. The deterministic pause
 * comes from a lock `Issue()` already takes on its own: `LabelIdentityService::IMPORT_LOCK`
 * (109038), a `pg_advisory_xact_lock()` every call to `Issue()` takes before it ever reaches
 * the entity row. This test's own "gate" connection pre-holds that exact lock (a normal,
 * transaction-scoped advisory lock any session can contend for - not a hook, not a change to
 * production code) before starting the revised-print subprocess, so that subprocess reliably
 * blocks *before* it ever requests the entity row lock:
 *
 *   - the gate connection takes `pg_advisory_xact_lock(109038)` and holds it, uncommitted;
 *   - a "revised print" subprocess (label-revisedprint-subprocess-helper.php) runs a real
 *     RevisedPrint() call; it reaches Issue()'s own advisory lock request and blocks there,
 *     never having touched the entity row or (post-fix) the label row;
 *   - once that subprocess is observed blocked, a "retire" subprocess
 *     (label-retirement-delete-subprocess-helper.php) runs the real production
 *     `DELETE FROM locations` - which locks the entity row (uncontended: the revised print
 *     has not reached it) and then, inside its trigger, the label row;
 *   - before the fix, the revised print already held the label row (its own, since-removed
 *     `FOR SHARE`), so the retirement's trigger blocks there too - at which point releasing
 *     the gate lets the revised print proceed to request the entity row the retirement now
 *     holds, closing the cycle: revised print waits on the entity row (held by the
 *     retirement), retirement waits on the label row (held by the revised print).
 *     PostgreSQL's own deadlock detector aborts one side with SQLSTATE 40P01 within its
 *     `deadlock_timeout` (default 1s);
 *   - after the fix, releasing the gate lets the retirement run to completion first (nothing
 *     of the revised print's is in its way), and the revised print then either succeeds
 *     (if it manages to touch the entity row before the retirement got there - not possible
 *     in this exact interleaving, since the retirement already committed by the time the
 *     gate is released) or refuses with an ordinary application-level error
 *     (`LabelIdentityService::Issue()`'s own "not found in the requested import epoch",
 *     because the location is now gone) - never a raw `\PDOException` with SQLSTATE 40P01.
 *
 * Both subprocess helpers set an explicit `statement_timeout` (20s) and this test bounds its
 * own pg_locks/pg_stat_activity polling the same way, per FIXER_RULES.md's requirement that
 * a concurrency test never hold the shared suite lock on a hang.
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
	private static function startRevisedPrintSubprocess(int $locationId, int $epoch, int $printerId, int $templateId): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/label-revisedprint-subprocess-helper.php', 'location', (string)$locationId, (string)$epoch, (string)$printerId, (string)$templateId],
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

	/**
	 * Waits until the revised-print subprocess is observed blocked specifically on the
	 * pre-held import advisory lock (matched by its own `application_name`, not "any"
	 * advisory waiter) - the natural pause point this test relies on, taken by
	 * LabelIdentityService::LockImport() inside Issue(), before that subprocess has touched
	 * the entity row (or, pre-fix, the label row) at all.
	 */
	private static function waitForRevisedPrintBlockedOnImportLock(float $timeoutSeconds = 10.0): void
	{
		$check = self::Pdo()->prepare("SELECT pid FROM pg_stat_activity WHERE application_name = 'label-revisedprint-helper' AND wait_event_type = 'Lock' AND wait_event = 'advisory' LIMIT 1");
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

		self::fail('Timed out waiting for the revised-print subprocess to block on the pre-held import advisory lock - it should have reached Issue()\'s own LockImport() call immediately');
	}

	/**
	 * Waits, best-effort and briefly, for the delete subprocess to show up as blocked on
	 * some lock before the import-lock gate is released. This is *not* a precondition the
	 * way waitForRevisedPrintBlockedOnImportLock() is: releasing the gate is safe whether
	 * the delete subprocess is still starting up, already running unblocked, genuinely
	 * blocked, or already finished - the gate release is what lets a genuinely blocked
	 * delete proceed, and does nothing harmful in every other case. So this never fails the
	 * test: it only gives a blocked delete subprocess a head start at being observed as
	 * blocked (which nothing else in this test depends on), and gives up quickly otherwise.
	 *
	 * A longer, hard-failing wait here would be wrong in either direction: requiring "blocked
	 * observed" would fail every run where the delete subprocess is fast enough to finish
	 * before a single poll catches it (the fixed-code case, routinely under a millisecond);
	 * requiring "row absent" as a stand-in for "finished" is racy the *other* way -
	 * proc_open() returns before the PHP subprocess has even connected to Postgres, so an
	 * early poll can find no row for a reason that has nothing to do with having finished
	 * (it simply is not there *yet*), which released the gate too early in an earlier
	 * version of this method and silently defeated the race this test exists to reproduce.
	 */
	private static function waitForDeleteSubprocessToSettle(float $timeoutSeconds = 2.0): void
	{
		$check = self::Pdo()->prepare("SELECT wait_event_type FROM pg_stat_activity WHERE application_name = 'label-retirement-delete-helper'");
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$check->execute();
			$row = $check->fetch(PDO::FETCH_ASSOC);

			if ($row !== false && $row['wait_event_type'] !== null)
			{
				return;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		// Not blocked within the (short) grace period: most likely already finished, or
		// running unblocked - either way, nothing more to wait for.
	}

	/**
	 * See this class's own docblock for the full scenario. Before the fix (`FOR SHARE`
	 * restored on RevisedPrint()'s own label read), this reproduces a genuine PostgreSQL
	 * deadlock: one of the two subprocesses is aborted with SQLSTATE 40P01
	 * (deadlock_detected). After the fix, both subprocesses complete without either one ever
	 * receiving that SQLSTATE.
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
		// LabelIdentityService::IMPORT_LOCK, held here rather than imported as a class
		// constant reference: this test deliberately uses the same literal key production
		// code uses, the same way a caller would, rather than reaching into that class.
		$gate->query('SELECT pg_advisory_xact_lock(109038)')->fetchColumn();

		$revisedPrintProcess = self::startRevisedPrintSubprocess($locationId, 0, $printerId, $templateId);
		$deleteProcess = null;
		$revisedPrintResult = null;
		$deleteResult = null;

		try
		{
			self::waitForRevisedPrintBlockedOnImportLock();

			$deleteProcess = self::startDeleteSubprocess('locations', $locationId);

			self::waitForDeleteSubprocessToSettle();
		}
		finally
		{
			// Releases the import lock by ending the gate's own transaction - this is a
			// transaction-scoped advisory lock (pg_advisory_xact_lock), which has no explicit
			// unlock call; it is released only by COMMIT or ROLLBACK of the transaction that
			// took it.
			$gate->rollBack();

			$revisedPrintResult = self::finishSubprocess($revisedPrintProcess);

			if ($deleteProcess !== null)
			{
				$deleteResult = self::finishSubprocess($deleteProcess);
			}
		}

		self::assertNotNull($deleteResult, 'setup: the delete subprocess must have been started');

		$revisedPrintSqlstate = $revisedPrintResult['sqlstate'] ?? null;
		$deleteSqlstate = $deleteResult['sqlstate'] ?? null;

		self::assertNotSame('40P01', $revisedPrintSqlstate,
			'The revised print must never be aborted by a deadlock: ' . ($revisedPrintResult['error_message'] ?? '') . ' ' . $revisedPrintResult['stderr']);
		self::assertNotSame('40P01', $deleteSqlstate,
			'The retirement (DELETE FROM locations) must never be aborted by a deadlock: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		// The retirement never contends for anything the revised print holds in the fixed
		// ordering - it always succeeds outright.
		self::assertSame(200, $deleteResult['status'], 'the retirement must succeed: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		// The revised print either also succeeds, or refuses with an ordinary
		// application-level error (the location was deleted before it reached the entity
		// row) - never a raw, unhandled database error of any other kind.
		if ($revisedPrintResult['status'] !== 200)
		{
			self::assertNull($revisedPrintSqlstate,
				'A refused revised print must be an application-level refusal, not a raw PDOException: ' . ($revisedPrintResult['error_message'] ?? ''));
		}

		self::assertSame(
			1,
			(int)$db->query('SELECT count(*) FROM labels WHERE uid = ' . $db->quote($labelUid) . ' AND retired_at IS NOT NULL')->fetchColumn(),
			'The label itself is retired either way'
		);
	}
}
