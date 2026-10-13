<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PDOException;
use Victual\Services\Labels\ArtifactService;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelByteStore;
use Victual\Services\Labels\LabelCaptureService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\LabelTemplateService;
use Victual\Services\Labels\LabelValidationException;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #899: expired-preview collection runs inside the render queue's claim, so a claim
 * that waited for another claim's collection serialised every renderer behind the slowest
 * one. Collection now takes its rows FOR UPDATE SKIP LOCKED, and decides whether the
 * stored bytes are still shared under a per-file advisory try-lock that never waits.
 *
 * Both connections live in this process: connection A is the base class connection and
 * connection B is a second one on the same schema. B runs under a short lock_timeout, so a
 * regression to a blocking claim fails with SQLSTATE 55P03 instead of hanging the suite.
 *
 * Rows are inserted directly, as copies of what Accept() writes, so each case controls
 * which previews exist, which share bytes, and which have expired.
 */
class PreviewCollectionConcurrencyTest extends PgsqlSchemaTestCase
{
	private const CALLER_USER = 9000;
	private const BOUND_SECONDS = 1.5;

	private static PDO $db;
	private static PDO $other;
	private static int $templateVersion;
	private static int $profileId;
	private static int $captureId;
	private static int $sequence = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		self::$other = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		self::$other->exec('SET search_path TO ' . self::Schema() . ', public');
		self::$other->exec("SET lock_timeout = '2s'");

		$templates = new LabelTemplateService(self::$db);
		$template = (int)self::tx(self::$db, static fn () => $templates->Create('Preview collection QR label', null, 'location', self::CALLER_USER))['id'];
		$draft = $templates->GetDraft($template);
		$document = ['schema_version' => 1, 'entity_kind' => 'location',
			'canvas' => ['width_mm' => 62.0, 'height_mm' => 30.0, 'max_height_mm' => null, 'margins_mm' => ['top' => 1.0, 'right' => 1.0, 'bottom' => 1.0, 'left' => 1.0]],
			'elements' => [['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
				'ec_level' => 'M', 'quiet_zone_modules' => 4, 'color' => 'black', 'source' => 'label.payload']]];
		self::tx(self::$db, static fn () => $templates->SaveDraft($template, $document, $draft['revision_token'], self::CALLER_USER));
		self::$templateVersion = (int)self::tx(self::$db, static fn () => $templates->Publish($template, self::CALLER_USER))['id'];

		$definition = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
		$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('previewcollect-worker', 'declared') RETURNING id")->fetchColumn();
		self::tx(self::$db, static fn () => (new DriverRegistryService(self::$db))->Register($worker, [$definition]));
		$printer = self::tx(self::$db, static fn () => (new PrinterConfigurationService(self::$db))->Save([
			'name' => 'Preview collection printer', 'worker_id' => $worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc', 'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]));
		self::$profileId = (int)self::tx(self::$db, static fn () => (new LabelOperationsService(self::$db))->ResolvePrinter($printer))['profile']['id'];
		self::$captureId = (int)self::tx(self::$db, static fn () => (new LabelCaptureService(self::$db))
			->CaptureSample('location', ['location.name'], 'en', 'UTC', self::CALLER_USER))['id'];
	}

	protected function setUp(): void
	{
		// Only a case's own previews may be eligible, or its counts would depend on what an
		// earlier case left behind.
		self::EndTransactions();
		self::$db->exec("UPDATE label_artifacts SET retention_class = 'retained' WHERE collected_at IS NULL");
	}

	protected function tearDown(): void
	{
		self::EndTransactions();
	}

	// --- fixtures ---------------------------------------------------------------------------

	private static function EndTransactions(): void
	{
		foreach ([self::$db, self::$other] as $connection) {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
		}
	}

	private static function tx(PDO $db, callable $work): mixed
	{
		$db->beginTransaction();
		try {
			$result = $work();
			$db->commit();
			return $result;
		} catch (\Throwable $error) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			throw $error;
		}
	}

	private static function fileName(): string
	{
		self::$sequence++;
		return LabelByteStore::NameFor('artifact', hash('sha256', 'previewcollect-' . self::$sequence), 'png');
	}

	/** One preview artifact pointing at $fileName, expired or not; returns the artifact id. */
	private static function preview(string $fileName, bool $expired = true): int
	{
		$store = new LabelByteStore(self::$db);
		if ($store->Get(LabelByteStore::GROUP_ARTIFACTS, $fileName) === null) {
			$store->Put(LabelByteStore::GROUP_ARTIFACTS, $fileName, 'bytes of ' . $fileName, 'image/png');
		}
		$expiry = $expired ? "CURRENT_TIMESTAMP - INTERVAL '1 hour'" : "CURRENT_TIMESTAMP + INTERVAL '1 hour'";
		$request = (int)self::$db->query("INSERT INTO label_render_requests(purpose, template_version_id, profile_id, capture_id, state, expires_at)
			VALUES ('preview_sample', " . self::$templateVersion . ', ' . self::$profileId . ', ' . self::$captureId . ", 'ready', $expiry) RETURNING id")->fetchColumn();
		$statement = self::$db->prepare("INSERT INTO label_artifacts(render_request_id, form, byte_digest, byte_length, mime_type, width_px, height_px, dpi_x, dpi_y, color_mode, profile_id, template_version_id, capture_id, renderer_id, renderer_version, manifest, manifest_digest, file_group, file_name, retention_class)
			VALUES (?, 'raster/png-indexed;v=1', ?, 10, 'image/png', 696, 354, 300, 300, 'black_red', ?, ?, ?, 'fixture-renderer', '0.0.1', '{}'::jsonb, ?, ?, ?, 'preview') RETURNING id");
		$statement->execute([$request, str_repeat('a', 64), self::$profileId, self::$templateVersion, self::$captureId, str_repeat('b', 64), LabelByteStore::GROUP_ARTIFACTS, $fileName]);
		return (int)$statement->fetchColumn();
	}

	private static function collect(PDO $connection, ?int $limit = null): int
	{
		return (new ArtifactService($connection))->CollectExpiredPreviews($limit);
	}

	private static function collectedAt(int $artifactId): ?string
	{
		return self::$db->query('SELECT collected_at FROM label_artifacts WHERE id = ' . $artifactId)->fetchColumn() ?: null;
	}

	private static function bytesExist(string $fileName): bool
	{
		return (int)self::$db->query("SELECT COUNT(*) FROM files WHERE file_group = 'labelartifacts' AND name = " . self::$db->quote($fileName))->fetchColumn() === 1;
	}

	// --- cases ------------------------------------------------------------------------------

	/**
	 * Before the fix connection B's selection waits on A's row locks until lock_timeout fires.
	 */
	public function testASecondCollectionNeverWaitsForTheFirstAndTheSkippedRowsAreCollectedLater(): void
	{
		$shared = self::fileName();
		$sharedA = self::preview($shared);
		$sharedB = self::preview($shared);
		$soloFile = self::fileName();
		$solo = self::preview($soloFile);

		self::$db->beginTransaction();
		self::assertSame(2, self::collect(self::$db, 2), 'Connection A takes the two oldest rows, the pair sharing one file, and holds their locks');

		self::$other->beginTransaction();
		$started = microtime(true);
		$collected = self::collect(self::$other);
		$elapsed = microtime(true) - $started;
		self::$other->commit();

		self::assertLessThan(self::BOUND_SECONDS, $elapsed, 'The second collection returns instead of waiting for the first');
		self::assertSame(1, $collected, 'It collects the one row the first connection did not lock');
		self::assertNotNull(self::collectedAt($solo), 'The unlocked row is collected');
		self::assertFalse(self::bytesExist($soloFile), 'Its bytes are gone');

		// A abandons its work; the rows it held were skipped, not lost.
		self::$db->rollBack();
		self::assertNull(self::collectedAt($sharedA));
		self::assertNull(self::collectedAt($sharedB));
		self::assertTrue(self::bytesExist($shared), 'Rolling back restores the shared bytes');

		self::assertSame(2, self::tx(self::$db, static fn () => self::collect(self::$db)), 'The next collection takes the rows that were skipped');
		self::assertNotNull(self::collectedAt($sharedA));
		self::assertNotNull(self::collectedAt($sharedB));
		self::assertFalse(self::bytesExist($shared), 'Their shared bytes go with the last of them');
	}

	/**
	 * Two previews of one file, each taken by a different collection. Under READ COMMITTED
	 * each would see the other still uncollected, both keep the bytes and both mark
	 * themselves collected: the bytes would never be deleted. The per-file try-lock makes
	 * the second one leave its row for a later pass instead.
	 */
	public function testTwoCollectionsOverOneSharedFileNeverStrandTheBytes(): void
	{
		$shared = self::fileName();
		$first = self::preview($shared);
		$second = self::preview($shared);

		self::$db->beginTransaction();
		self::assertSame(1, self::collect(self::$db, 1), 'A takes the first row');
		self::$other->beginTransaction();
		self::assertSame(0, self::collect(self::$other, 1), 'B takes the other row, loses the file try-lock, and leaves the row without waiting');
		self::$db->commit();
		self::$other->commit();

		self::assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM label_artifacts WHERE id IN (' . $first . ',' . $second . ') AND collected_at IS NOT NULL')->fetchColumn(),
			'Exactly one of the pair was collected: the file lock was held when the other tried it');
		self::assertTrue(self::bytesExist($shared), 'The bytes are kept while an uncollected sharer remains');

		self::assertSame(1, self::tx(self::$db, static fn () => self::collect(self::$db)), 'A later pass collects the row that was skipped');
		self::assertNotNull(self::collectedAt($first));
		self::assertNotNull(self::collectedAt($second));
		self::assertFalse(self::bytesExist($shared), 'The bytes were deleted, once the last sharer was collected');
		self::assertSame(0, self::tx(self::$db, static fn () => self::collect(self::$db)), 'Nothing is left to collect');
	}

	public function testBytesAreKeptWhileAnUnexpiredPreviewStillSharesThem(): void
	{
		$shared = self::fileName();
		$expired = self::preview($shared);
		$live = self::preview($shared, false);

		self::$db->beginTransaction();
		self::assertSame(1, self::collect(self::$db));
		self::$other->beginTransaction();
		self::assertSame(0, self::collect(self::$other), 'Nothing else is eligible, and the locked row is skipped without waiting');
		self::$db->commit();
		self::$other->commit();

		self::assertNotNull(self::collectedAt($expired));
		self::assertNull(self::collectedAt($live), 'Negative control: the unexpired preview is untouched');
		self::assertTrue(self::bytesExist($shared), 'The bytes stay for the preview that still points at them');
		self::assertSame(0, self::tx(self::$db, static fn () => self::collect(self::$db)));
		self::assertTrue(self::bytesExist($shared));
	}

	public function testACollectionSkipsAnArtifactAPromotionHoldsAndPromotionStillWins(): void
	{
		$fileName = self::fileName();
		$artifact = self::preview($fileName);

		self::$db->beginTransaction();
		$promoted = (new ArtifactService(self::$db))->Promote($artifact);
		self::assertSame('retained', $promoted['retention_class']);

		self::$other->beginTransaction();
		$started = microtime(true);
		self::assertSame(0, self::collect(self::$other), 'The row an in-flight promotion holds is skipped');
		self::assertLessThan(self::BOUND_SECONDS, microtime(true) - $started, 'Without waiting for the promotion');
		self::$other->commit();
		self::$db->commit();

		self::assertSame(0, self::tx(self::$other, static fn () => self::collect(self::$other)), 'Once promoted it is no longer a preview and is never collected');
		self::assertNull(self::collectedAt($artifact), 'The promoted artifact is not collected');
		self::assertTrue(self::bytesExist($fileName), 'Its bytes are intact');
		self::assertSame('retained', self::$db->query('SELECT retention_class FROM label_artifacts WHERE id = ' . $artifact)->fetchColumn());
	}

	public function testAPromotionWaitsForAnInFlightCollectionAndThenRefusesTheCollectedArtifact(): void
	{
		$fileName = self::fileName();
		$artifact = self::preview($fileName);

		self::$db->beginTransaction();
		self::assertSame(1, self::collect(self::$db));

		self::$other->beginTransaction();
		try {
			(new ArtifactService(self::$other))->Promote($artifact);
			self::fail('Promotion must wait for the collection holding the row');
		} catch (PDOException $timeout) {
			self::assertSame('55P03', $timeout->errorInfo[0], 'Promotion waits on the row lock collection holds: ' . $timeout->getMessage());
		}
		self::$other->rollBack();
		self::$db->commit();

		self::$other->beginTransaction();
		try {
			(new ArtifactService(self::$other))->Promote($artifact);
			self::fail('A collected artifact cannot be promoted');
		} catch (LabelValidationException $refusal) {
			self::assertSame('artifact_collected', $refusal->errorCode);
		}
		self::$other->rollBack();
		self::assertSame('preview', self::$db->query('SELECT retention_class FROM label_artifacts WHERE id = ' . $artifact)->fetchColumn(), 'The refused promotion changed nothing');
		self::assertFalse(self::bytesExist($fileName));
	}
}
