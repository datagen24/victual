<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelByteStore;
use Victual\Services\Labels\LabelCaptureService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\PrintAttemptService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Services\Labels\RenderRequestService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issues #846, #857 and #858: a render request that can never be rendered must reach a
 * terminal state that is committed, take the print jobs waiting on it with it, and never
 * stand in front of the requests and jobs queued behind it.
 *
 * Every case asserts the rows left behind - request state and attempts, job outcome, outbox
 * dead-letter, artifact collection - and not only what a call returned or threw.
 */
class LabelRenderLifecycleTest extends PgsqlSchemaTestCase
{
	private const CALLER_USER = 9000;
	private const ADMIN_USER = 9701;

	private static PDO $db;
	private static string $adminKey;
	private static int $template;
	private static int $templateVersion;
	private static int $draftTemplate;
	private static int $profileId;
	private static int $captureId;
	private static int $locationSequence = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		require_once VICTUAL_ROOT_PATH . '/.devtools/labels/test-support.php';

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::CALLER_USER . ", 'renderlifecycle-caller', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::ADMIN_USER . ", 'renderlifecycle-admin', 'fixture')");
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = ' . self::ADMIN_USER);
		self::$db->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT " . self::ADMIN_USER . ", id FROM permission_hierarchy WHERE name = '" . User::PERMISSION_ADMIN . "'");
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey($key), substr($key, -4), self::ADMIN_USER, ApiKeyService::API_KEY_TYPE_DEFAULT]);
		self::$adminKey = $key;

		[self::$template, self::$templateVersion] = self::publishQrTemplate();

		// A second template, never published, whose draft pins a font nobody uploaded: the
		// document a designer can save today and a draft preview then renders.
		$templates = new LabelTemplateService(self::$db);
		self::$draftTemplate = (int)self::tx(static fn () => $templates->Create('Missing font label', null, 'location', self::CALLER_USER))['id'];
		$draft = $templates->GetDraft(self::$draftTemplate);
		self::tx(static fn () => $templates->SaveDraft(self::$draftTemplate, self::textDocument('Never Uploaded'), $draft['revision_token'], self::CALLER_USER));

		[, $printer] = self::newPrinter();
		$resolved = self::tx(static fn () => (new LabelOperationsService(self::$db))->ResolvePrinter($printer));
		self::$profileId = (int)$resolved['profile']['id'];
		self::$captureId = (int)self::tx(static fn () => (new LabelCaptureService(self::$db))
			->CaptureSample('location', ['location.name'], 'en', 'UTC', self::CALLER_USER))['id'];
	}

	// --- fixtures ---------------------------------------------------------------------------

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

	private static function requests(): RenderRequestService
	{
		return new RenderRequestService(self::$db);
	}

	private static function canvas(): array
	{
		return ['width_mm' => 62.0, 'height_mm' => 30.0, 'max_height_mm' => null,
			'margins_mm' => ['top' => 1.0, 'right' => 1.0, 'bottom' => 1.0, 'left' => 1.0]];
	}

	private static function qrElement(): array
	{
		return ['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
			'ec_level' => 'M', 'quiet_zone_modules' => 4, 'color' => 'black', 'source' => 'label.payload'];
	}

	private static function textDocument(string $fontAsset): array
	{
		return ['schema_version' => 1, 'entity_kind' => 'location', 'canvas' => self::canvas(),
			'elements' => [self::qrElement(), ['type' => 'text', 'id' => 'title', 'x_mm' => 2.0, 'y_mm' => 22.0, 'width_mm' => 40.0,
				'height_mm' => 6.0, 'field' => 'location.name', 'font_asset' => $fontAsset, 'size_pt' => 10.0]]];
	}

	/** @return array{0: int, 1: int} template id and its first version id */
	private static function publishQrTemplate(): array
	{
		$templates = new LabelTemplateService(self::$db);
		$template = (int)self::tx(static fn () => $templates->Create('Render lifecycle QR label', null, 'location', self::CALLER_USER))['id'];
		$draft = $templates->GetDraft($template);
		$document = ['schema_version' => 1, 'entity_kind' => 'location', 'canvas' => self::canvas(), 'elements' => [self::qrElement()]];
		self::tx(static fn () => $templates->SaveDraft($template, $document, $draft['revision_token'], self::CALLER_USER));
		return [$template, (int)self::tx(static fn () => $templates->Publish($template, self::CALLER_USER))['id']];
	}

	/** @return array{0: int, 1: int} worker id and printer id */
	private static function newPrinter(): array
	{
		$definition = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
		$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('renderlifecycle-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		self::tx(static fn () => (new DriverRegistryService(self::$db))->Register($worker, [$definition]));
		$printer = self::tx(static fn () => (new PrinterConfigurationService(self::$db))->Save([
			'name' => 'Render lifecycle printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc', 'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]));
		return [$worker, $printer];
	}

	private static function enqueue(int $printerId): int
	{
		self::$locationSequence++;
		self::$db->exec("INSERT INTO locations(name, description) VALUES ('Render lifecycle job " . self::$locationSequence . "', 'Fixture')");
		$location = (int)self::$db->lastInsertId();
		return self::tx(static fn () => (new LabelPrintJobService(self::$db))->Enqueue($location, 0, $printerId, self::$template));
	}

	/** Sets the rest of the queue aside so a claim in this test can only return this test's requests. */
	private static function quiesceQueue(): void
	{
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");
	}

	private static function productionRequest(): array
	{
		return self::tx(static fn () => self::requests()->CreateForVersion('production', self::$templateVersion, self::$profileId, self::$captureId, self::CALLER_USER));
	}

	private static function previewRequest(): array
	{
		return self::tx(static fn () => self::requests()->CreateForVersion('preview_sample', self::$templateVersion, self::$profileId, self::$captureId, self::CALLER_USER));
	}

	private static function missingFontDraftRequest(): array
	{
		return self::tx(static fn () => self::requests()->CreateForDraft(
			self::$draftTemplate, self::textDocument('Never Uploaded'), self::$profileId, self::$captureId, self::CALLER_USER));
	}

	private static function requestRow(int $requestId): array
	{
		return self::$db->query('SELECT * FROM label_render_requests WHERE id = ' . $requestId)->fetch(PDO::FETCH_ASSOC);
	}

	private static function jobRow(int $jobId): array
	{
		return self::$db->query('SELECT * FROM print_jobs WHERE id = ' . $jobId)->fetch(PDO::FETCH_ASSOC);
	}

	private static function outboxRow(int $outboxId): array
	{
		return self::$db->query('SELECT * FROM outbox WHERE id = ' . $outboxId)->fetch(PDO::FETCH_ASSOC);
	}

	/** @return array{status: int, json: mixed, body: string} */
	private static function send(string $method, string $path, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$adminKey]];
		if ($body !== null) {
			$spec['body'] = $body;
		}
		$environment = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'), 'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['json'] = json_decode((string)$result['body'], true);
		return $result;
	}

	// --- #846: a request that cannot be rendered does not stop the queue ----------------------

	/**
	 * The oldest pending request names a font that was deleted (or never stored) after it was
	 * enqueued. One claim must settle it as `invalid`, durably, and hand out the request
	 * behind it - not throw, roll its own attempt back, and pick the same row next time.
	 */
	public function testClaimSettlesAnUnrenderableDraftAsInvalidAndReturnsTheNextRequest(): void
	{
		self::quiesceQueue();
		$broken = self::missingFontDraftRequest();
		$valid = self::productionRequest();

		$input = self::tx(static fn () => self::requests()->Claim());
		self::assertIsArray($input, 'The request behind the broken one is handed out');
		self::assertSame((int)$valid['id'], $input['render_request_id'], 'The claim returns the valid request, not the broken one');

		$row = self::requestRow((int)$broken['id']);
		self::assertSame('invalid', $row['state'], 'The unrenderable draft is settled as invalid');
		self::assertSame('asset_unavailable', $row['error_code']);
		self::assertSame('assets', $row['error_element']);
		self::assertStringContainsString('Never Uploaded', (string)$row['error_detail'], 'The record names the missing asset');
		self::assertSame(1, (int)$row['attempts'], 'The attempt the claim spent is persisted, not rolled back');
		self::assertNull($row['generation_token'], 'No lease is left behind');
		self::assertNull($row['lease_expires_at']);

		$validRow = self::requestRow((int)$valid['id']);
		self::assertSame('rendering', $validRow['state']);
		self::assertSame(1, (int)$validRow['attempts']);

		// Give the valid request up, then claim again: the invalid one is not seen again.
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE id = " . (int)$valid['id']);
		self::assertNull(self::tx(static fn () => self::requests()->Claim()), 'Nothing is left to claim');
		$after = self::requestRow((int)$broken['id']);
		self::assertSame('invalid', $after['state']);
		self::assertSame(1, (int)$after['attempts'], 'The invalid request is not claimed a second time');
	}

	/** A reap made in the same claim as a refusal is committed, not rolled back with it. */
	public function testReapedLeaseCommitsWhenTheClaimSettlesABrokenRequest(): void
	{
		self::quiesceQueue();
		$crashed = self::productionRequest();
		$first = self::tx(static fn () => self::requests()->Claim());
		self::assertSame((int)$crashed['id'], $first['render_request_id']);
		self::$db->exec("UPDATE label_render_requests SET lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = " . (int)$crashed['id']);
		$broken = self::missingFontDraftRequest();
		// Put the reaped request behind the broken one in the queue.
		self::$db->exec("UPDATE label_render_requests SET attempts = " . RenderRequestService::MAX_ATTEMPTS . ' WHERE id = ' . (int)$crashed['id']);

		self::assertNull(self::tx(static fn () => self::requests()->Claim()), 'The exhausted request fails in the reap and the broken one is settled');

		$reaped = self::requestRow((int)$crashed['id']);
		self::assertSame('failed', $reaped['state'], 'The reap was committed');
		self::assertSame('RENDER_LEASE_EXHAUSTED', $reaped['error_code']);
		self::assertSame('invalid', self::requestRow((int)$broken['id'])['state']);
	}

	/**
	 * A draft preview of a document naming an unstored asset is refused where it is asked
	 * for, with the refusal Publish gives, and creates nothing.
	 */
	public function testDraftPreviewNamingAMissingAssetIsRefusedAndCreatesNoRequest(): void
	{
		[, $printer] = self::newPrinter();
		$requests = (int)self::$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn();
		$captures = (int)self::$db->query('SELECT COUNT(*) FROM label_captures')->fetchColumn();

		$response = self::send('POST', '/api/labels/templates/' . self::$draftTemplate . '/preview', ['kind' => 'draft', 'printer_id' => $printer]);

		self::assertSame(422, $response['status'], $response['body']);
		self::assertSame('assets', $response['json']['field'] ?? null, $response['body']);
		self::assertSame('asset_unavailable', $response['json']['code'] ?? null, $response['body']);
		self::assertSame($requests, (int)self::$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn(), 'No render request is created');
		self::assertSame($captures, (int)self::$db->query('SELECT COUNT(*) FROM label_captures')->fetchColumn(), 'Nothing is captured either');
	}

	// --- #858: a print job follows its render request to a terminal state ---------------------

	public function testJobWhoseRenderRequestBecomesInvalidIsDeadLettered(): void
	{
		self::quiesceQueue();
		[, $printer] = self::newPrinter();
		$jobId = self::enqueue($printer);
		$job = self::jobRow($jobId);
		self::assertNull($job['outcome'], 'Precondition: the job is queued');
		self::assertNotNull($job['render_request_id']);

		$input = self::tx(static fn () => self::requests()->Claim());
		self::assertSame((int)$job['render_request_id'], $input['render_request_id']);
		self::tx(static fn () => self::requests()->RecordInvalid($input['render_request_id'], (string)$input['generation_token'], 'TEXT_OVERFLOW', 'title', 'Does not fit'));

		$after = self::jobRow($jobId);
		self::assertSame('dead_lettered', $after['outcome'], 'The job reaches a terminal outcome');
		self::assertNotNull($after['outcome_at']);
		self::assertNull($after['artifact_id']);
		$outbox = self::outboxRow((int)$after['outbox_id']);
		self::assertNotNull($outbox['dead_lettered_at'], 'The outbox row is acknowledged');
		self::assertStringContainsString('invalid', (string)$outbox['last_error'], 'The reason names the render outcome');
		self::assertStringContainsString('TEXT_OVERFLOW', (string)$outbox['last_error']);
	}

	public function testJobWhoseRenderRequestFailsTerminallyIsDeadLetteredButNotBeforeThen(): void
	{
		self::quiesceQueue();
		[, $printer] = self::newPrinter();
		$jobId = self::enqueue($printer);
		$requestId = (int)self::jobRow($jobId)['render_request_id'];

		for ($attempt = 1; $attempt < RenderRequestService::MAX_ATTEMPTS; $attempt++) {
			$input = self::tx(static fn () => self::requests()->Claim());
			self::tx(static fn () => self::requests()->RecordFailure($input['render_request_id'], (string)$input['generation_token'], 'renderer unreachable'));
			self::assertSame('pending', self::requestRow($requestId)['state'], 'Still retryable');
			self::assertNull(self::jobRow($jobId)['outcome'], 'A retryable failure leaves the job queued');
		}

		$input = self::tx(static fn () => self::requests()->Claim());
		self::tx(static fn () => self::requests()->RecordFailure($input['render_request_id'], (string)$input['generation_token'], 'renderer unreachable'));
		self::assertSame('failed', self::requestRow($requestId)['state']);

		$after = self::jobRow($jobId);
		self::assertSame('dead_lettered', $after['outcome'], 'The terminal failure dead-letters the job');
		self::assertNotNull(self::outboxRow((int)$after['outbox_id'])['dead_lettered_at']);
	}

	public function testJobWhoseRenderLeaseIsExhaustedIsDeadLettered(): void
	{
		self::quiesceQueue();
		[, $printer] = self::newPrinter();
		$jobId = self::enqueue($printer);
		$requestId = (int)self::jobRow($jobId)['render_request_id'];
		self::tx(static fn () => self::requests()->Claim());
		self::$db->exec('UPDATE label_render_requests SET attempts = ' . RenderRequestService::MAX_ATTEMPTS
			. ", lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = " . $requestId);

		self::assertSame(1, self::tx(static fn () => self::requests()->ReapExpired()));
		self::assertSame('failed', self::requestRow($requestId)['state']);
		self::assertSame('dead_lettered', self::jobRow($jobId)['outcome'], 'Lease exhaustion is terminal for the job as well');
	}

	public function testJobThatAlreadyHasItsArtifactIsNotDeadLettered(): void
	{
		self::quiesceQueue();
		[, $printer] = self::newPrinter();
		$jobId = self::enqueue($printer);
		$artifact = \renderAndAttach(self::$db, $jobId);
		self::assertNotNull(self::jobRow($jobId)['artifact_id'], 'Precondition: the job is rendered');

		self::$db->exec("UPDATE label_render_requests SET state = 'rendering', generation_token = 'late', lease_expires_at = CURRENT_TIMESTAMP + INTERVAL '1 minute' WHERE id = " . (int)$artifact['render_request_id']);
		self::tx(static fn () => self::requests()->RecordInvalid((int)$artifact['render_request_id'], 'late', 'X', null, 'late report'));
		self::assertNull(self::jobRow($jobId)['outcome'], 'A job that already has its artifact is not dead-lettered');
	}

	/** More than the claim window of jobs that cannot be offered must not hide a ready one. */
	public function testReadyJobIsClaimedBehindMoreThanTheWindowOfUnreadyJobs(): void
	{
		self::quiesceQueue();
		[$worker, $printer] = self::newPrinter();
		$unready = [];
		for ($i = 0; $i < 201; $i++) {
			$unready[] = self::enqueue($printer);
		}
		$ready = self::enqueue($printer);
		// Only the last job's request is rendered; the others are set aside the way a
		// quiesced queue is, so they stay unrendered without having been dead-lettered.
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE id <> " . (int)self::jobRow($ready)['render_request_id'] . " AND state = 'pending'");
		\renderAndAttach(self::$db, $ready);

		$claims = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker));

		self::assertCount(1, $claims, 'The one ready job is offered');
		self::assertSame($ready, (int)$claims[0]['attempt']['job_id']);
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM print_attempts WHERE job_id IN (' . implode(',', $unready) . ')')->fetchColumn(), 'No attempt is spent on an unrendered job');
	}

	// --- #857: expired previews are collected on the claim tick -------------------------------

	public function testExpiredPreviewsAreCollectedAndNoLongerRetryableAfterOneClaimTick(): void
	{
		self::quiesceQueue();
		$rendered = self::previewRequest();
		$artifact = \renderPending(self::$db);
		self::assertSame((int)$rendered['id'], (int)$artifact['render_request_id']);
		$store = new LabelByteStore(self::$db);
		self::assertNotNull($store->Get($artifact['file_group'], $artifact['file_name']), 'Precondition: the preview bytes are stored');

		$neverRendered = self::previewRequest();
		self::$db->exec("UPDATE label_render_requests SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE id IN (" . (int)$rendered['id'] . ',' . (int)$neverRendered['id'] . ')');

		$status = self::send('GET', '/api/labels/renders/' . (int)$neverRendered['id']);
		self::assertSame('pending', $status['json']['state']);
		self::assertFalse($status['json']['retryable'], 'An expired request is not retryable even before it is collected');

		self::assertNull(self::tx(static fn () => self::requests()->Claim()), 'An expired preview is not work');

		$row = self::requestRow((int)$neverRendered['id']);
		self::assertSame('failed', $row['state']);
		self::assertSame('expired', $row['error_code']);
		self::assertSame(0, (int)$row['attempts'], 'Expiry spends no render attempt');
		self::assertNull($row['generation_token']);

		self::assertNotNull(self::$db->query('SELECT collected_at FROM label_artifacts WHERE id = ' . (int)$artifact['id'])->fetchColumn(),
			'The expired preview artifact is collected by the claim tick');
		self::assertNull($store->Get($artifact['file_group'], $artifact['file_name']), 'Its bytes are gone');

		$status = self::send('GET', '/api/labels/renders/' . (int)$neverRendered['id']);
		self::assertSame('failed', $status['json']['state']);
		self::assertFalse($status['json']['retryable']);
	}

	public function testUnexpiredPreviewIsNotCollectedByTheClaimTick(): void
	{
		self::quiesceQueue();
		$request = self::previewRequest();
		$artifact = \renderPending(self::$db);
		self::assertSame((int)$request['id'], (int)$artifact['render_request_id']);
		self::assertNull(self::tx(static fn () => self::requests()->Claim()));
		self::assertNull(self::$db->query('SELECT collected_at FROM label_artifacts WHERE id = ' . (int)$artifact['id'])->fetchColumn(), 'Negative control: a live preview is kept');
	}
}
