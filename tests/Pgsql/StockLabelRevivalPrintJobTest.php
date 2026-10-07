<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Services\Labels\PrintAttemptService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0037 section 9 and acceptance prerequisite 6 (example E15): revival writes nothing to
 * print_jobs, and a job that existed when the label retired stays unclaimable after the label
 * turns live again. Today's predicate excludes a retired label's jobs; once the label is live,
 * only the event's jobs_through_id keeps a person's pre-consumption retry from printing by
 * itself. A job requested after the revival has a higher id and is claimable.
 */
class StockLabelRevivalPrintJobTest extends PgsqlSchemaTestCase
{
	private const CALLER_USER = 9000;

	private static PDO $db;
	private static int $location;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		require_once VICTUAL_ROOT_PATH . '/.devtools/labels/test-support.php';
		self::$db->exec('INSERT INTO users(id, username, password) VALUES (' . self::CALLER_USER . ", 'revival-print-caller', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::CALLER_USER . ", id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$location = (int)self::$db->query("INSERT INTO locations(name) VALUES ('Revival print shelf') RETURNING id")->fetchColumn();
	}

	private static function tx(callable $work): mixed
	{
		self::$db->beginTransaction();
		try
		{
			$result = $work();
			self::$db->commit();
			return $result;
		}
		catch (\Throwable $error)
		{
			if (self::$db->inTransaction())
			{
				self::$db->rollBack();
			}
			throw $error;
		}
	}

	/** @return array{0: int, 1: int} worker, printer */
	private static function printer(): array
	{
		$worker = (int)self::$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('revival-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		$definition = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
		self::tx(static fn () => (new DriverRegistryService(self::$db))->Register($worker, [$definition]));
		$printer = (int)self::tx(static fn () => (new PrinterConfigurationService(self::$db))->Save([
			'name' => 'Revival printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc', 'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]));
		return [$worker, $printer];
	}

	/** A rendered stock-entry print job for $row; issues the label on first use. */
	private static function printedJob(int $row, int $printer): array
	{
		$template = (int)self::$db->query("SELECT id FROM label_templates WHERE entity_kind = 'stock_entry' ORDER BY id LIMIT 1")->fetchColumn();
		self::$db->exec("UPDATE label_render_requests SET state = 'failed' WHERE state IN ('pending', 'rendering')");
		$job = self::tx(static fn () => (new LabelOperationsService(self::$db, null, self::CALLER_USER))
			->IssueLocation('stock_entry', $row, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn(), $printer, $template, null, 'en', 'UTC'));
		\renderAndAttach(self::$db, (int)$job['id']);
		return $job;
	}

	private static function claim(int $worker): array
	{
		return array_map(static fn (array $claim): int => (int)$claim['attempt']['job_id'],
			self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker, 50)));
	}

	public function testE15AJobFromBeforeTheRetirementStaysUnclaimableAfterTheRevival(): void
	{
		[$worker, $printer] = self::printer();
		$product = (int)self::$db->query('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES '
			. "('Revival print product', " . self::$location . ', 2, 2) RETURNING id')->fetchColumn();
		StockService::GetInstance()->AddProduct($product, 2, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
		$row = (int)self::$db->query("SELECT max(id) FROM stock WHERE product_id = $product")->fetchColumn();

		// Job 1: printed once, failed, and a person authorized a second attempt.
		$first = self::printedJob($row, $printer);
		$uid = (string)$first['label_uid'];
		$attempt = self::tx(static fn () => (new PrintAttemptService(self::$db))->Claim($worker))[0]['attempt'];
		self::tx(static fn () => (new PrintAttemptService(self::$db))->Result($worker, (int)$attempt['id'], 'failed', ['error' => 'Device offline']));
		self::tx(static fn () => (new LabelPrintJobService(self::$db))->AuthorizeAnotherAttempt((int)$first['id'], (int)$attempt['id']));

		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct($product, 2, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);
		$booking = (int)self::$db->query('SELECT id FROM stock_log WHERE transaction_id = ' . self::$db->quote($transactionId))->fetchColumn();
		$event = self::$db->query('SELECT * FROM stock_label_retirements WHERE label_uid = ' . self::$db->quote($uid))->fetch(PDO::FETCH_ASSOC);
		self::assertSame('consumption', $event['cause']);
		self::assertSame((int)$first['id'], (int)$event['jobs_through_id'], 'The event records the highest job id at retirement');
		$jobBefore = self::$db->query('SELECT * FROM print_jobs WHERE id = ' . (int)$first['id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNull($jobBefore['cancelled_at'], 'Precondition: an attempted job is not cancelled by the retirement (0296)');
		self::assertSame([], self::claim($worker), 'A retired label excludes its jobs');

		$jobsBefore = self::$db->query('SELECT * FROM print_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$attemptsBefore = self::$db->query('SELECT * FROM print_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$outboxBefore = self::$db->query('SELECT count(*) FROM outbox')->fetchColumn();

		self::assertSame(['restored' => 1, 'retired' => 0], StockService::GetInstance()->UndoBooking($booking));

		self::assertSame($jobsBefore, self::$db->query('SELECT * FROM print_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Revival writes no print job');
		self::assertSame($attemptsBefore, self::$db->query('SELECT * FROM print_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Revival writes no attempt');
		self::assertSame($outboxBefore, self::$db->query('SELECT count(*) FROM outbox')->fetchColumn(), 'Revival writes no outbox row');
		self::assertSame([], self::claim($worker), 'The authorized retry from before the consumption is not claimable after the revival');

		// Job 2: requested after the revival, on the same uid.
		$second = self::printedJob($row, $printer);
		self::assertSame($uid, (string)$second['label_uid']);
		self::assertGreaterThan((int)$event['jobs_through_id'], (int)$second['id']);
		self::assertSame([(int)$second['id']], self::claim($worker), 'A job requested after the revival is claimable, and only that one');
	}
}
