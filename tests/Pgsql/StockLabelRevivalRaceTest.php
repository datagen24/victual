<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\Database\PostgresDialect;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0037 acceptance prerequisite 5: the races of section 7 on two real connections.
 *
 * Each case holds a lock on a gate connection, starts the subprocesses that must queue behind
 * it, waits until pg_stat_activity shows each one genuinely waiting on a lock, then releases
 * the gate. Nothing sleeps for a hoped-for interleaving.
 *
 *   C1  label issuance during the undo: waits on the import lock, then returns the revived uid.
 *   C2  two undos of one booking: the second waits on the product lock, then refuses.
 *   C3a an import during the undo: the revival commits first, and the import refuses the live
 *       label it left.
 *   C3b an undo during an import: whichever of the two PostgreSQL lets finish, the other one is
 *       either refused or rolled back whole, and a label is revived only if the import did not
 *       commit.
 *   A consumption racing the undo of the consumption that retired the same label: never a
 *       deadlock, and the label ends in a state with one event per retirement.
 *
 * The classes run last in their phase's file list because the import case replaces the data.
 */
class StockLabelRevivalRaceTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static int $location;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'label-revival-race', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$location = (int)self::$db->query("INSERT INTO locations(name) VALUES ('Label revival race shelf') RETURNING id")->fetchColumn();
	}

	// --- subprocesses -----------------------------------------------------------------------

	private static function environment(array $extra = []): array
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
		], $extra);
	}

	/** @return array{0: resource, 1: array} */
	private static function start(string $helper, array $args, array $environment = []): array
	{
		$process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/' . $helper], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::environment($environment));
		return [$process, $pipes];
	}

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

	private static function undo(int $booking, string $name): array
	{
		return self::start('stock-undo-subprocess-helper.php', ['undo', (string)$booking], ['VICTUAL_TEST_APPLICATION_NAME' => $name]);
	}

	private static function waitBlocked(string $applicationName, float $timeoutSeconds = 10.0): void
	{
		$check = self::$db->prepare("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
		$deadline = microtime(true) + $timeoutSeconds;
		do
		{
			$check->execute([$applicationName]);
			if ($check->fetchColumn() !== false)
			{
				return;
			}
			usleep(20000);
		}
		while (microtime(true) < $deadline);
		self::fail("Timed out waiting for $applicationName to block on a lock");
	}

	private static function gate(): PDO
	{
		$gate = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$gate->exec('SET search_path TO ' . self::Schema() . ', public');
		return $gate;
	}

	// --- fixtures ---------------------------------------------------------------------------

	/** A labelled row consumed whole: [product, row, uid, booking]. */
	private static function consumedLabelledRow(string $name): array
	{
		$product = (int)self::$db->query('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('
			. self::$db->quote($name . ' ' . bin2hex(random_bytes(3))) . ', ' . self::$location . ', 2, 2) RETURNING id')->fetchColumn();
		StockService::GetInstance()->AddProduct($product, 3, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		$row = (int)self::$db->query("SELECT max(id) FROM stock WHERE product_id = $product")->fetchColumn();
		self::$db->beginTransaction();
		$uid = (new LabelIdentityService(self::$db))->Issue('stock_entry', $row, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn());
		self::$db->commit();
		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct($product, 3, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);
		$booking = (int)self::$db->query('SELECT id FROM stock_log WHERE transaction_id = ' . self::$db->quote($transactionId))->fetchColumn();
		return [$product, $row, $uid, $booking];
	}

	private static function label(string $uid): array
	{
		return self::$db->query('SELECT * FROM labels WHERE uid = ' . self::$db->quote($uid))->fetch(PDO::FETCH_ASSOC);
	}

	private static function events(string $uid): array
	{
		return self::$db->query('SELECT * FROM stock_label_retirements WHERE label_uid = ' . self::$db->quote($uid) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	}

	// --- C1 ---------------------------------------------------------------------------------

	public function testC1IssuanceDuringTheUndoReturnsTheRevivedUid(): void
	{
		[, $row, $uid, $booking] = self::consumedLabelledRow('C1');
		$gate = self::gate();
		$gate->beginTransaction();
		LabelIdentityService::LockImport($gate);

		$undo = self::undo($booking, 'c1-undo');
		$issue = null;
		try
		{
			self::waitBlocked('c1-undo');
			$issue = self::start('issue-stock-label-subprocess-helper.php', [(string)$row, (string)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn()]);
			// The issuance helper sets no application_name: wait until two backends queue on the import lock.
			$deadline = microtime(true) + 10;
			while ((int)self::$db->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND objid = " . LabelIdentityService::IMPORT_LOCK)->fetchColumn() < 2 && microtime(true) < $deadline)
			{
				usleep(20000);
			}
		}
		finally
		{
			$gate->rollBack();
			$undoResult = self::finish($undo);
			$issueResult = $issue === null ? null : self::finish($issue);
		}

		self::assertSame(200, $undoResult['status'], $undoResult['error_message'] ?? $undoResult['stderr']);
		self::assertSame(['restored' => 1, 'retired' => 0], $undoResult['label_revival']);
		self::assertNotNull($issueResult);
		self::assertSame(200, $issueResult['status'], $issueResult['error_message'] ?? $issueResult['stderr']);
		self::assertSame($uid, $issueResult['uid'], 'Issuance after the revival returns the revived uid');
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM labels WHERE kind = 'stock_entry' AND target_id = $row AND retired_at IS NULL")->fetchColumn(),
			'One live label on the restored row');
	}

	// --- C2 ---------------------------------------------------------------------------------

	public function testC2ASecondUndoOfTheSameBookingWaitsAndRefuses(): void
	{
		[$product, $row, $uid, $booking] = self::consumedLabelledRow('C2');
		$gate = self::gate();
		$gate->beginTransaction();
		$gate->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $product]);

		$first = self::undo($booking, 'c2-first');
		$second = null;
		try
		{
			self::waitBlocked('c2-first');
			$second = self::undo($booking, 'c2-second');
			self::waitBlocked('c2-second');
		}
		finally
		{
			$gate->rollBack();
			$results = [self::finish($first)];
			if ($second !== null)
			{
				$results[] = self::finish($second);
			}
		}

		self::assertCount(2, $results);
		$succeeded = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 200));
		$refused = array_values(array_filter($results, static fn (array $result): bool => $result['status'] !== 200));
		self::assertCount(1, $succeeded, 'Exactly one undo succeeds');
		self::assertSame(['restored' => 1, 'retired' => 0], $succeeded[0]['label_revival']);
		self::assertStringContainsString('already undone', $refused[0]['error_message']);
		self::assertNull($refused[0]['sqlstate'], 'A refusal, not a deadlock');
		self::assertSame($row, (int)self::label($uid)['target_id']);
		self::assertCount(1, self::events($uid), 'One revival');
	}

	// --- consumption racing the undo of the same label -------------------------------------

	public function testAConsumptionRacingTheUndoNeverDeadlocks(): void
	{
		[$product, , $uid, $booking] = self::consumedLabelledRow('Consume race');
		$gate = self::gate();
		$gate->beginTransaction();
		$gate->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $product]);

		$undo = self::undo($booking, 'race-undo');
		$consume = null;
		try
		{
			self::waitBlocked('race-undo');
			$consume = self::start('stock-undo-subprocess-helper.php', ['consume', (string)$product, '3'], ['VICTUAL_TEST_APPLICATION_NAME' => 'race-consume']);
			self::waitBlocked('race-consume');
		}
		finally
		{
			$gate->rollBack();
			$undoResult = self::finish($undo);
			$consumeResult = $consume === null ? null : self::finish($consume);
		}

		self::assertNotSame('40P01', $undoResult['sqlstate'] ?? null, $undoResult['error_message'] ?? '');
		self::assertNotSame('40P01', $consumeResult['sqlstate'] ?? null, $consumeResult['error_message'] ?? '');
		self::assertSame(200, $undoResult['status'], $undoResult['error_message'] ?? $undoResult['stderr']);
		self::assertSame(200, $consumeResult['status'], 'The undo queued first, so the stock is back for the consumption: ' . ($consumeResult['error_message'] ?? ''));

		$events = self::events($uid);
		self::assertCount(2, $events, 'One event per retirement');
		self::assertSame('revived', $events[0]['outcome']);
		self::assertSame('consumption', $events[1]['cause']);
		self::assertNull($events[1]['outcome'], 'The second retirement is pending');
		self::assertNotNull(self::label($uid)['retired_at']);
	}

	// --- C3a, C3b (the import cases replace the data, so they run last) -----------------------

	private static function sourceCopy(): string
	{
		$file = tempnam(sys_get_temp_dir(), 'label-revival-race-source-');
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX . '.db', $file);
		return $file;
	}

	public function testC3aAnImportDuringTheUndoFindsTheRevivedLabelAndRefuses(): void
	{
		[, $row, $uid, $booking] = self::consumedLabelledRow('C3a');
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();
		$gate = self::gate();
		$gate->beginTransaction();
		LabelIdentityService::LockImport($gate);

		$source = self::sourceCopy();
		$undo = self::undo($booking, 'c3a-undo');
		$import = null;
		try
		{
			self::waitBlocked('c3a-undo');
			// A gate nobody holds: the importer does not pause.
			$import = self::start('importer-lock-subprocess-helper.php', [$source, '1986600077', '1']);
			self::waitBlocked('importer-lock-helper');
		}
		finally
		{
			$gate->rollBack();
			$undoResult = self::finish($undo);
			$importResult = $import === null ? null : self::finish($import);
			unlink($source);
		}

		self::assertSame(200, $undoResult['status'], $undoResult['error_message'] ?? $undoResult['stderr']);
		self::assertSame(['restored' => 1, 'retired' => 0], $undoResult['label_revival']);
		self::assertSame(400, $importResult['status']);
		self::assertStringContainsString('live label', $importResult['error_message'], 'The importer refuses the label the revival left live');
		self::assertSame($epoch, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn(), 'The refused import changed nothing');
		self::assertSame($row, (int)self::label($uid)['target_id']);

		// Retire it deliberately, as the importer's refusal asks, so the next case can import.
		self::$db->exec("DELETE FROM stock WHERE id = $row");
	}

	public function testC3bAnUndoDuringAnImportNeverRevivesAgainstReplacedData(): void
	{
		[, , $uid, $booking] = self::consumedLabelledRow('C3b');
		// The earlier cases left revived labels live; retire them deliberately, as the
		// importer's refusal asks, by removing their stock.
		self::$db->exec("DELETE FROM stock WHERE id IN (SELECT target_id FROM labels WHERE kind = 'stock_entry' AND retired_at IS NULL)");
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM labels WHERE retired_at IS NULL')->fetchColumn(), 'Precondition: no live label refuses the import');
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();
		$eventBefore = self::events($uid);

		// The importer pauses holding the import lock, before it bumps the epoch.
		$gateClass = 1986600078;
		$gate = self::gate();
		$gate->query("SELECT pg_advisory_lock($gateClass, 1)")->fetchColumn();
		$source = self::sourceCopy();
		$import = self::start('importer-lock-subprocess-helper.php', [$source, (string)$gateClass, '1']);
		$undo = null;
		try
		{
			self::waitBlocked('importer-lock-helper');
			$undo = self::undo($booking, 'c3b-undo');
			self::waitBlocked('c3b-undo');
		}
		finally
		{
			$gate->query("SELECT pg_advisory_unlock($gateClass, 1)")->fetchColumn();
			$importResult = self::finish($import);
			$undoResult = $undo === null ? null : self::finish($undo);
			unlink($source);
		}

		self::assertNotNull($undoResult);
		$imported = $importResult['status'] === 200;
		if ($imported)
		{
			// The import committed. The undo either lost a deadlock against the importer's
			// truncation (ADR-0037 section 7) and rolled back whole, or ran after it and found
			// its booking gone. Either way nothing was revived against the replaced data.
			self::assertNotSame(200, $undoResult['status'], 'The booking no longer exists after the import');
			self::assertSame($epoch + 1, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn());
			self::assertSame($eventBefore, self::events($uid), 'The event survives the import unchanged');
			self::assertNotNull(self::label($uid)['retired_at'], 'The label stays retired');
		}
		else
		{
			// The importer lost the deadlock and rolled back whole; the undo then revived.
			self::assertSame('40P01', $importResult['sqlstate'], $importResult['error_message'] ?? '');
			self::assertSame(200, $undoResult['status'], $undoResult['error_message'] ?? $undoResult['stderr']);
			self::assertSame($epoch, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn());
			self::assertNull(self::label($uid)['retired_at']);
		}
	}
}
