<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\IdempotencyService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\LabelValidationException;
use Victual\Services\Labels\PrintAttemptService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #516 (audit finding M16): label job lifecycle boundary cases
 * `.devtools/labels/print-job-tests.php` does not reach.
 *
 * That suite already covers the ordinary claim/attempt/authorization path and the starvation
 * and blocked-attempt cases its own fixtures produce. This class covers: what an attempt costs
 * when nothing was actually attempted; what a retired label's queued job does and does not
 * become (ADR-0019: it stays queued, indefinitely, the same as any other unclaimed job, whether
 * or not it was ever rendered, and cannot be crowded out of the claim query's own LIMIT by a
 * page of other retired jobs ahead of it); what two concurrent reservations of one idempotency
 * key do to each other; what deleting a printer may and may not finish on jobs it is walking
 * away from - immediately, and later, when a surviving attempt against it is finally reported
 * or reaped; and what AttachArtifact() does with a renderer result that outlives the job it was
 * meant for.
 */
class LabelJobLifecycleTest extends PgsqlSchemaTestCase
{
	private const CALLER_USER = 9000;
	private const ADMIN_USER = 9601;

	private static PDO $db;
	private static int $template;

	/** Distinct locations across the class, so no two jobs ever contend for one label. */
	private static int $locationSequence = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		require_once VICTUAL_ROOT_PATH . '/.devtools/labels/test-support.php';

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::CALLER_USER . ", 'lifecycle-caller', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::ADMIN_USER . ", 'lifecycle-admin', 'fixture')");
		self::$db->exec("DELETE FROM user_permissions WHERE user_id = " . self::ADMIN_USER);
		self::$db->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT " . self::ADMIN_USER . ", id FROM permission_hierarchy WHERE name = '" . User::PERMISSION_ADMIN . "'");

		self::$template = self::publishLocationTemplate();
	}

	// --- fixtures ---------------------------------------------------------------------------

	/** Runs $work in the caller-owned transaction every label service requires. */
	private static function tx(callable $work): mixed
	{
		self::$db->beginTransaction();
		try {
			$result = $work();
			self::$db->commit();
			return $result;
		} catch (\Throwable $error) {
			if (self::$db->inTransaction()) {
				self::$db->rollBack();
			}
			throw $error;
		}
	}

	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** A fresh worker advertising the fixture driver, and a printer assigned to it. */
	private static function newPrinter(): array
	{
		$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('lifecycle-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		self::tx(static fn () => (new DriverRegistryService(self::$db))->Register($worker, [self::driverDefinition()]));
		$printer = self::tx(static fn () => (new PrinterConfigurationService(self::$db))->Save([
			'name' => 'Lifecycle printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc', 'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]));
		return [$worker, $printer];
	}

	private static function newLocation(string $name): int
	{
		self::$locationSequence++;
		self::$db->exec("INSERT INTO locations(name, description) VALUES ('" . $name . ' ' . self::$locationSequence . "', 'Fixture')");
		return (int)self::$db->lastInsertId();
	}

	/** A QR-only, location-kind template, published once and reused: appearance is not the subject here. */
	private static function publishLocationTemplate(): int
	{
		$templates = new LabelTemplateService(self::$db);
		$template = (int)self::tx(static fn () => $templates->Create('Lifecycle QR label', null, 'location', self::CALLER_USER))['id'];
		$draft = $templates->GetDraft($template);
		$document = ['schema_version' => 1, 'entity_kind' => 'location',
			'canvas' => ['width_mm' => 62.0, 'height_mm' => 30.0, 'max_height_mm' => null, 'margins_mm' => ['top' => 1.0, 'right' => 1.0, 'bottom' => 1.0, 'left' => 1.0]],
			'elements' => [['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
				'ec_level' => 'M', 'quiet_zone_modules' => 4, 'color' => 'black', 'source' => 'label.payload']]];
		self::tx(static fn () => $templates->SaveDraft($template, $document, $draft['revision_token'], self::CALLER_USER));
		self::tx(static fn () => $templates->Publish($template, self::CALLER_USER));
		return $template;
	}

	private static function enqueue(int $printerId, string $locationName): int
	{
		return self::tx(static fn () => (new LabelPrintJobService(self::$db))
			->Enqueue(self::newLocation($locationName), 0, $printerId, self::$template));
	}

	/** An unrendered job whose label is retired before it is ever claimed. */
	private static function retiredJob(int $printerId): int
	{
		$location = self::newLocation('Lifecycle bulk-retired');
		$jobId = self::tx(static fn () => (new LabelPrintJobService(self::$db))->Enqueue($location, 0, $printerId, self::$template));
		self::$db->exec('DELETE FROM locations WHERE id = ' . $location);
		return $jobId;
	}

	private static function jobRow(int $jobId): array
	{
		return self::$db->query('SELECT * FROM print_jobs WHERE id = ' . $jobId)->fetch(PDO::FETCH_ASSOC);
	}

	// --- M16: an unrendered job with a missing worker capability -----------------------------

	/**
	 * A job fresh out of Enqueue() carries no artifact until a renderer produces one and
	 * Victual verifies it (LabelOperationsService::IssueLocation()'s docblock, ADR-0019
	 * section 4: "a job whose artifact has not been validated and attached is not
	 * claimable"). That rule does not stop applying because the worker also fails a second,
	 * unrelated precondition - the exact combination this reproduces.
	 */
	public function testUnrenderedJobWithMissingCapabilityIsNotClaimableAndSpendsNoAttempt(): void
	{
		[$worker, $printer] = self::newPrinter();
		$jobId = self::enqueue($printer, 'Lifecycle unrendered');

		$before = self::jobRow($jobId);
		self::assertNull($before['artifact_id'], 'Precondition: Enqueue() leaves the job unrendered');
		self::assertSame(0, (int)$before['attempts_made'], 'Precondition: nothing has attempted this job yet');

		// The exact M16 reproduction: the worker that advertised the driver when the job was
		// enqueued no longer does, discovered only when a claim is actually attempted.
		self::$db->exec('DELETE FROM label_worker_capabilities WHERE worker_id = ' . $worker);

		$claims = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker));
		self::assertSame([], $claims, 'An unrendered job is not claimable, missing capability or not');

		$after = self::jobRow($jobId);
		self::assertSame(0, (int)$after['attempts_made'],
			'An unrendered job spends no attempt: it was never really offered to this worker to fail against');
		self::assertSame(1, (int)$after['attempts_authorized'], 'No authorization was consumed');
		self::assertNull($after['outcome'], 'The job is still queued, not dead-lettered or blocked');
		self::assertNull($after['current_attempt_id'], 'No attempt was ever attached to the job');
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM print_attempts WHERE job_id = ' . $jobId)->fetchColumn(),
			'No attempt row records a claim that never really happened');
	}

	// --- M16: retirement leaves a queued job uncancelled --------------------------------------

	/**
	 * "Retirement first, then the bytes" (LabelOperationsService::Reprint()) and "retirement
	 * stops new claims" (LabelOperationsService::AssertLabelLive(), and now Claim()'s own
	 * claim-query SQL) are the whole of the decided contract. What a retired label's queued job
	 * *becomes* is explicitly undecided: ADR-0019 (around "Unclaimed jobs stay queued,
	 * indefinitely, and nothing dead-letters for being unclaimed ... Dead-lettering stays for
	 * [a payload no version can read, plus] decision item 4's deleted-printer case") rules out
	 * inventing a terminal state here, so this asserts only what is decided - not claimed, and
	 * otherwise untouched - and leaves the open question open.
	 */
	public function testRetiredLabelJobIsNeverClaimedAndStaysQueued(): void
	{
		[$worker, $printer] = self::newPrinter();
		$location = self::newLocation('Lifecycle retiring shelf');
		$jobId = self::tx(static fn () => (new LabelPrintJobService(self::$db))->Enqueue($location, 0, $printer, self::$template));
		$labelUid = self::jobRow($jobId)['label_uid'];

		self::$db->exec('DELETE FROM locations WHERE id = ' . $location);

		$retiredAt = self::$db->query('SELECT retired_at FROM labels WHERE uid = ' . self::$db->quote($labelUid))->fetchColumn();
		self::assertNotNull($retiredAt, 'Precondition: deleting the location retires its label (migration 0269 trigger)');

		$before = self::jobRow($jobId);

		$claims = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker));
		self::assertSame([], $claims, 'A retired label is never claimed');

		$after = self::jobRow($jobId);
		self::assertNull($after['outcome'], 'Retirement stops new claims; it does not dead-letter a job for merely being unclaimed (ADR-0019)');
		self::assertNull($after['cancelled_at'], 'Retirement is not a user-initiated cancellation (LabelOperationsService::Cancel()) either');
		self::assertSame(0, (int)$after['attempts_made'], 'No attempt is spent discovering the label is retired');
		self::assertEquals($before, $after, 'The job row is otherwise untouched by an unclaimable retirement');

		$outboxRow = self::$db->query('SELECT dead_lettered_at, last_error FROM outbox WHERE id = ' . (int)$after['outbox_id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNull($outboxRow['dead_lettered_at'], 'The outbox row is not touched by retirement');
		self::assertNull($outboxRow['last_error'], 'No error is recorded for a merely-retired target');
	}

	/**
	 * Both of testRetiredLabelJobIsNeverClaimedAndStaysQueued()'s predecessors (round 1) used an
	 * unrendered fixture, so a fix that only special-cased "no artifact yet" would have passed
	 * them. Claim()'s retirement check is unconditional on artifact_id, and this proves it: the
	 * job is rendered - fully claimable but for its label - before its target is deleted.
	 */
	public function testRetiredLabelJobIsNeverClaimedEvenWhenRendered(): void
	{
		[$worker, $printer] = self::newPrinter();
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");

		$location = self::newLocation('Lifecycle retiring rendered shelf');
		$jobId = self::tx(static fn () => (new LabelPrintJobService(self::$db))->Enqueue($location, 0, $printer, self::$template));
		\renderAndAttach(self::$db, $jobId);

		$rendered = self::jobRow($jobId);
		self::assertNotNull($rendered['artifact_id'], 'Precondition: the job is fully rendered before its label retires');

		self::$db->exec('DELETE FROM locations WHERE id = ' . $location);
		$retiredAt = self::$db->query('SELECT retired_at FROM labels WHERE uid = ' . self::$db->quote($rendered['label_uid']))->fetchColumn();
		self::assertNotNull($retiredAt, 'Precondition: deleting the location retires its label');

		$claims = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker));
		self::assertSame([], $claims, 'A retired label is never claimed, rendered or not');

		$after = self::jobRow($jobId);
		self::assertNull($after['outcome'], 'Retirement does not dead-letter a rendered job either; it stays queued like any other unclaimed job');
		self::assertNull($after['cancelled_at'], 'Retirement is not a cancellation');
	}

	/**
	 * The real starvation control the fixer assignment asks for: the claim query's own
	 * `LIMIT 200` means a per-row PHP check after fetching cannot protect an eligible job from
	 * 200 retired ones ahead of it in queue order - they would fill the window before the
	 * eligible row is even read. Retirement has to be excluded in the SQL predicate itself, so
	 * these 200 rows never occupy a slot the eligible job needs.
	 */
	public function testRetiredLabelJobsCannotStarveAnEligibleJobUnderTheClaimLimit(): void
	{
		[$worker, $printer] = self::newPrinter();

		$retired = [];
		for ($i = 0; $i < 200; $i++) {
			$retired[] = self::retiredJob($printer);
		}

		// renderAndAttach() below claims whatever render request is next in the whole queue,
		// unconditional on which job it belongs to, so the 200 retired jobs' own (never going
		// to be claimable) requests have to be out of the way first.
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");
		$eligibleJob = self::enqueue($printer, 'Lifecycle starvation-200 eligible');
		\renderAndAttach(self::$db, $eligibleJob);

		$claims = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker));
		self::assertCount(1, $claims, 'The eligible job dispatches even with 200 retired jobs ahead of it in queue order');
		self::assertSame($eligibleJob, (int)$claims[0]['attempt']['job_id'], 'Retired jobs occupying the claim query\'s LIMIT window did not starve the eligible job');

		foreach ($retired as $jobId) {
			$row = self::jobRow($jobId);
			self::assertNull($row['outcome'], 'A retired job stays queued rather than being dead-lettered for being unclaimed');
			self::assertNull($row['cancelled_at'], 'Retirement is not a cancellation either');
		}
	}

	// --- M16: concurrent identical idempotency reservations -----------------------------------

	/** A connection of its own, the same way request-subprocess-helper.php's caller opens one. */
	private static function rawConnection(): PDO
	{
		$pdo = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'),
			getenv('PGPASSWORD'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');
		return $pdo;
	}

	/**
	 * Two Begin() calls for the same principal/operation/key race on
	 * label_idempotency_keys' UNIQUE constraint. PostgreSQL blocks the second insert behind
	 * the first's uncommitted row rather than letting both through, so once the first commits,
	 * "same key and same inputs returns the original resource" (IdempotencyService's own
	 * docblock) is supposed to apply to the second caller too - not a raw SQLSTATE 23505
	 * escaping to whoever asked. A real second connection is used rather than two calls on one
	 * connection, because the failure mode is specifically what PostgreSQL does when a second
	 * *transaction* reserves a key the first has not yet committed.
	 */
	public function testConcurrentIdempotencyReservationsReplayInsteadOfConflicting(): void
	{
		$key = 'lifecycle-race-' . bin2hex(random_bytes(6));
		$request = ['op' => 'print', 'seq' => 1];
		$process = null;
		$pipes = [];

		// A plain try/finally rather than tx(): this test must hold the reservation open
		// (uncommitted) *while* the child races it, which is the one shape none of this
		// class's other tests need from the transaction helper. Guarded all the same, so a
		// failure here cannot leave the connection every other test method shares
		// mid-transaction, or a child process still running past its own test.
		self::$db->beginTransaction();
		try {
			$begin = (new IdempotencyService(self::$db))->Begin(self::CALLER_USER, 'print', $key, $request);
			self::assertSame(['replay' => false, 'row' => null], $begin, 'The first reservation is not a replay');

			$environment = array_filter(array_merge($_SERVER, $_ENV, [
				'RBAC_TEST_SCHEMA' => self::Schema(),
				'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
				'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
				'PGHOST' => getenv('PGHOST'),
				'PGPORT' => getenv('PGPORT'),
				'PGUSER' => getenv('PGUSER'),
				'PGPASSWORD' => getenv('PGPASSWORD'),
				'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
			]), 'is_scalar');

			$process = proc_open(
				[PHP_BINARY, __DIR__ . '/label-idempotency-subprocess-helper.php', (string)self::CALLER_USER, 'print', $key, json_encode($request)],
				[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes,
				null,
				$environment
			);

			$observer = self::rawConnection();
			$blocked = false;
			$deadline = microtime(true) + 5;
			while (microtime(true) < $deadline) {
				$waiting = (int)$observer->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = 'label_idempotency_child' AND wait_event_type = 'Lock'")->fetchColumn();
				if ($waiting > 0) {
					$blocked = true;
					break;
				}
				usleep(20000);
			}
			self::assertTrue($blocked, 'The concurrent reservation reaches a real row lock rather than a timing race that happens not to collide');

			(new IdempotencyService(self::$db))->Record(self::CALLER_USER, 'print', $key, 'print_job', 918273, ['job_id' => 918273]);
			self::$db->commit();
		} finally {
			if (self::$db->inTransaction()) {
				self::$db->rollBack();
			}
		}

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the child printed no JSON. stdout: $output\nstderr: $errors");
		self::assertArrayNotHasKey('error', $result, 'The concurrent reservation replays the committed one instead of raising a constraint violation: ' . $output);
		self::assertTrue($result['replay'] ?? null, 'Once the first reservation commits, the second sees a completed reservation to replay');
		self::assertSame(918273, (int)($result['row']['resource_id'] ?? 0), 'The replay carries the resource the first request recorded');

		self::assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM label_idempotency_keys WHERE idempotency_key = ' . self::$db->quote($key))->fetchColumn(),
			'The race produced exactly one reservation row, not two and not zero');
	}

	// --- Source-only: printer deletion sweeps running and cancelled jobs too ------------------

	private static function insertAttempt(int $jobId, int $workerId, int $leaseSeconds, ?string $outcome, bool $ended): int
	{
		$outboxId = self::jobRow($jobId)['outbox_id'];
		$statement = self::$db->prepare("INSERT INTO print_attempts(outbox_id, job_id, attempt_number, worker_id, lease_expires_at, lease_hard_deadline, acknowledged_on, ended_at, outcome, error_text)
			VALUES (?, ?, 1, ?, CURRENT_TIMESTAMP + make_interval(secs => ?), CURRENT_TIMESTAMP + make_interval(secs => 300), 'send', "
			. ($ended ? 'CURRENT_TIMESTAMP' : 'NULL') . ', ?, ?) RETURNING id');
		$statement->execute([$outboxId, $jobId, $workerId, $leaseSeconds, $outcome, $outcome === null ? null : 'Fixture attempt']);
		return (int)$statement->fetchColumn();
	}

	private static function issueAdminKey(): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey($key), substr($key, -4), self::ADMIN_USER, ApiKeyService::API_KEY_TYPE_DEFAULT]);
		return $key;
	}

	/** @return array{status: int, body: string, json: mixed} */
	private static function Send(string $method, string $path, string $key): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $key]];
		$environment = array_filter(array_merge($_SERVER, $_ENV, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]), 'is_scalar');

		$process = proc_open(
			[PHP_BINARY, VICTUAL_ROOT_PATH . '/tests/Pgsql/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");
		$result['json'] = json_decode((string)($result['body'] ?? ''), true);
		return $result;
	}

	/**
	 * Source-only claim in the assignment: `LabelPrintersApiController::DeletePrinter()`
	 * matched every job with a null outcome, which also matches a job a worker currently
	 * holds and a job already cancelled through LabelOperationsService::Cancel() - neither of
	 * which has an outcome yet either, for reasons that are not "this printer is gone".
	 *
	 * A job merely awaiting human re-authorization (a spent, ended attempt, no live claim, not
	 * cancelled) is deliberately still swept: only "currently running" and "already cancelled"
	 * are excluded, and this is the negative control proving the fix is not broader than that.
	 */
	public function testDeletingPrinterDeadLettersOnlyQueuedAndAwaitingAuthorizationJobs(): void
	{
		[$worker, $printer] = self::newPrinter();

		$queued = self::enqueue($printer, 'Lifecycle delete queued');
		$running = self::enqueue($printer, 'Lifecycle delete running');
		$cancelled = self::enqueue($printer, 'Lifecycle delete cancelled');
		$awaitingAuth = self::enqueue($printer, 'Lifecycle delete awaiting-auth');

		// A live, unexpired claim - simulated directly rather than through a real Claim(),
		// which this test is not otherwise about and which needs a rendered artifact.
		$runningAttempt = self::insertAttempt($running, $worker, 60, null, false);
		self::$db->exec("UPDATE print_jobs SET current_attempt_id = $runningAttempt, attempts_made = 1 WHERE id = $running");

		self::tx(static fn () => (new LabelOperationsService(self::$db))->Cancel($cancelled, 'lifecycle test cancellation'));

		$failedAttempt = self::insertAttempt($awaitingAuth, $worker, -60, 'failed', true);
		self::$db->exec("UPDATE print_jobs SET current_attempt_id = $failedAttempt, attempts_made = 1 WHERE id = $awaitingAuth");

		$response = self::Send('DELETE', '/api/labels/printers/' . $printer, self::issueAdminKey());
		self::assertSame(200, $response['status'], 'An admin may delete a printer: ' . $response['body']);
		self::assertTrue($response['json']['deleted'] ?? null, 'The printer row itself is reported deleted');

		$queuedRow = self::jobRow($queued);
		self::assertSame('dead_lettered', $queuedRow['outcome'], 'A purely queued job is retired visibly when its printer disappears');

		$runningRow = self::jobRow($running);
		self::assertNull($runningRow['outcome'], 'A job a worker currently holds is left alone: a worker already talking to a printer is not something a database row can recall');
		self::assertSame($runningAttempt, (int)$runningRow['current_attempt_id'], 'The running attempt is untouched');
		$runningOutbox = self::$db->query('SELECT dead_lettered_at, last_error FROM outbox WHERE id = ' . (int)$runningRow['outbox_id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNull($runningOutbox['dead_lettered_at'], 'The running job\'s outbox row is not touched either - only print_jobs.outcome was asserted before, and an unguarded outbox UPDATE would still have passed without this');
		self::assertNull($runningOutbox['last_error'], 'No error is recorded against a job that is still genuinely in flight');

		$cancelledRow = self::jobRow($cancelled);
		self::assertNull($cancelledRow['outcome'], 'A cancelled job already has its terminal state; dead_lettered would claim something untrue about a physical object');
		self::assertNotNull($cancelledRow['cancelled_at'], 'The earlier cancellation is preserved');
		$cancelledOutbox = self::$db->query('SELECT dead_lettered_at, last_error FROM outbox WHERE id = ' . (int)$cancelledRow['outbox_id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($cancelledOutbox['dead_lettered_at'], 'Cancel() itself already dead-lettered this outbox row (Cancelled: reason) - deletion must not overwrite that with "Printer deleted"');
		self::assertStringStartsWith('Cancelled:', (string)$cancelledOutbox['last_error'], 'The cancellation\'s own last_error survives printer deletion unrewritten');

		$awaitingAuthRow = self::jobRow($awaitingAuth);
		self::assertSame('dead_lettered', $awaitingAuthRow['outcome'], 'A job merely awaiting human re-authorization (no live attempt, not cancelled) is still swept');
	}

	// --- Assignment B1: a live attempt that outlives its printer ------------------------------

	/**
	 * DeletePrinter() leaves a job with a live, unexpired attempt alone (asserted above). What
	 * happens when that attempt is later reported as failed is a separate question the guard
	 * alone does not answer: AuthorizeAnotherAttempt() would succeed, but Claim() inner-joins
	 * label_printers (PrintAttemptService::Claim(), around the JOIN at its top) and can never
	 * select this job again, so without PrintAttemptService::DeadLetterIfPrinterGone() it would
	 * sit at authorization_state=queued/awaiting_worker forever.
	 */
	public function testFailedReportAfterPrinterDeletionDeadLettersTheJob(): void
	{
		[$worker, $printer] = self::newPrinter();
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");
		$jobId = self::enqueue($printer, 'Lifecycle post-delete failed');
		\renderAndAttach(self::$db, $jobId);

		$attempt = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker))[0]['attempt'];

		$response = self::Send('DELETE', '/api/labels/printers/' . $printer, self::issueAdminKey());
		self::assertSame(200, $response['status'], 'An admin may delete a printer holding a running attempt: ' . $response['body']);
		self::assertNull(self::jobRow($jobId)['outcome'], 'Precondition: a live attempt keeps the job untouched by deletion itself');

		self::tx(static fn () => (new PrintAttemptService(self::$db))->Result($worker, (int)$attempt['id'], 'failed', ['error' => 'Device offline']));

		$after = self::jobRow($jobId);
		self::assertSame('dead_lettered', $after['outcome'], 'A failed report against a deleted printer has nowhere else to go: Claim() can never select this job again');
		self::assertNotNull($after['outcome_at'], 'The terminal state records when it happened');

		$outboxRow = self::$db->query('SELECT dead_lettered_at, last_error FROM outbox WHERE id = ' . (int)$after['outbox_id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($outboxRow['dead_lettered_at'], 'The outbox row is acknowledged the same way every other dead letter is');
		self::assertSame('Printer deleted', $outboxRow['last_error'], 'Same wording the deletion path itself uses');
	}

	/**
	 * The other half of the same gap: no worker ever reports, the lease simply expires. Nothing
	 * currently calls Reap() again for this job's specific id once Claim() can no longer select
	 * it, so without this fix it stays "uncertain"/awaiting_worker forever too.
	 */
	public function testExpiredLeaseAfterPrinterDeletionDeadLettersTheJob(): void
	{
		[$worker, $printer] = self::newPrinter();
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");
		$jobId = self::enqueue($printer, 'Lifecycle post-delete expired');
		\renderAndAttach(self::$db, $jobId);

		$attempt = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker))[0]['attempt'];

		$response = self::Send('DELETE', '/api/labels/printers/' . $printer, self::issueAdminKey());
		self::assertSame(200, $response['status'], 'An admin may delete a printer holding a running attempt: ' . $response['body']);
		self::assertNull(self::jobRow($jobId)['outcome'], 'Precondition: a live attempt keeps the job untouched by deletion itself');

		// The lease's own clock running out, rather than a worker report - the attempt is left
		// exactly as Claim() created it, only later than its lease.
		self::$db->exec("UPDATE print_attempts SET lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = " . (int)$attempt['id']);

		self::tx(static fn () => (new PrintAttemptService(self::$db))->Reap($jobId));

		$after = self::jobRow($jobId);
		self::assertSame('dead_lettered', $after['outcome'], 'An expired lease against a deleted printer is reaped straight to dead-lettered rather than sitting uncertain forever');
		self::assertNotNull($after['outcome_at'], 'The terminal state records when it happened');

		$attemptRow = self::$db->query('SELECT ended_at, outcome FROM print_attempts WHERE id = ' . (int)$attempt['id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($attemptRow['ended_at'], 'The attempt itself is still reaped normally');
		self::assertSame('uncertain', $attemptRow['outcome'], 'Reap() still records its own usual attempt outcome; the job outcome is the new part');

		$outboxRow = self::$db->query('SELECT dead_lettered_at, last_error FROM outbox WHERE id = ' . (int)$after['outbox_id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($outboxRow['dead_lettered_at'], 'The outbox row is acknowledged the same way every other dead letter is');
		self::assertSame('Printer deleted', $outboxRow['last_error'], 'Same wording the deletion path itself uses');
	}

	/**
	 * AuthorizeAnotherAttempt() itself calls Reap() before checking the job's outcome
	 * (LabelPrintJobService::AuthorizeAnotherAttempt()). Reap() would dead-letter this job in
	 * that same call (its printer is gone) - but BaseApiController::InRequestTransaction()'s own
	 * docblock is explicit that "a throw rolls back and is rethrown", and self::tx() mirrors
	 * that, so the refusal this proves rolls its own Reap() back too. What has to hold is
	 * narrower than "ends dead-lettered": the stale pre-Reap() outcome must not let the
	 * attempts_authorized update run regardless, or a job with a gone printer would be silently
	 * re-authorized for an attempt it can never be offered. Actually finishing the job this way
	 * is what the two committing tests above do (a worker's own failure report, and a bare
	 * Reap() call outside a refusal).
	 */
	public function testAuthorizeAnotherAttemptDoesNotRequeueAJobWhosePrinterIsGone(): void
	{
		[$worker, $printer] = self::newPrinter();
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");
		$jobId = self::enqueue($printer, 'Lifecycle post-delete reauth');
		\renderAndAttach(self::$db, $jobId);

		$attempt = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker))[0]['attempt'];
		self::Send('DELETE', '/api/labels/printers/' . $printer, self::issueAdminKey());
		self::$db->exec("UPDATE print_attempts SET lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = " . (int)$attempt['id']);

		$before = self::jobRow($jobId);

		try {
			self::tx(static fn () => (new LabelPrintJobService(self::$db))->AuthorizeAnotherAttempt($jobId, (int)$attempt['id']));
			self::fail('Expected a refusal: Reap() would dead-letter this job because its printer is gone');
		} catch (LabelValidationException $error) {
			self::assertSame('already_completed', $error->errorCode, 'Refused because Reap() saw the printer was gone before the attempts_authorized update could run');
		}

		$after = self::jobRow($jobId);
		self::assertEquals($before, $after, 'The refused call rolled back in full, including its own Reap() - nothing here re-queues or otherwise changes the job');
		self::assertSame((int)$before['attempts_authorized'], (int)$after['attempts_authorized'], 'attempts_authorized is not incremented by a refused call');
	}

	// --- Source-only: AttachArtifact() and a job dead-lettered before it was ever rendered ----

	/**
	 * Non-blocking finding in the assignment: AttachArtifact() checked only cancelled_at, so a
	 * renderer result that completes after Claim() has already dead-lettered its job (here, for
	 * an unreadable outbox payload - the same precondition print-job-tests.php exercises) would
	 * still attach, silently reviving a job that already has a terminal outcome.
	 */
	public function testAttachArtifactSkipsAJobAlreadyDeadLetteredWithoutAnArtifact(): void
	{
		[$worker, $printer] = self::newPrinter();
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");

		$jobId = self::enqueue($printer, 'Lifecycle attach-after-dead-letter');
		$job = self::jobRow($jobId);
		self::assertNull($job['artifact_id'], 'Precondition: the job is still unrendered');

		self::$db->exec('UPDATE outbox SET payload = \'{"payload_version":999}\' WHERE id = ' . (int)$job['outbox_id']);
		$claims = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker));
		self::assertSame([], $claims);
		self::assertSame('dead_lettered', self::jobRow($jobId)['outcome'], 'Precondition: the job is dead-lettered before it was ever rendered');

		$artifact = \renderPending(self::$db);
		self::assertNotNull($artifact, 'A renderer can still complete the render request that predates the dead letter');

		$attached = self::tx(static fn () => (new LabelOperationsService(self::$db))->AttachArtifact((int)$artifact['render_request_id'], (int)$artifact['id']));
		self::assertSame(0, $attached, 'A job that already has a terminal outcome takes no artifact, the same as a cancelled one');
		self::assertNull(self::jobRow($jobId)['artifact_id'], 'artifact_id stays null on a dead-lettered job');
	}
}
