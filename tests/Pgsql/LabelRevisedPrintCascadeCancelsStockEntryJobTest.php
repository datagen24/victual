<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Labels\DriverRegistryService;
use Victual\Services\Labels\LabelOperationsService;
use Victual\Services\Labels\PrinterConfigurationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * PR #626 delta review (Opus), second round, BLOCKING: dropping RevisedPrint()'s label lock
 * in the first round's fix reopened issue #516's maintainer decision D2 for `stock_entry`
 * labels specifically, on the product-delete cascade path.
 *
 * `trg_cascade_product_removal` (migrations/0296.pgsql.sql, `AFTER DELETE ON products`)
 * retires a deleted product's stock-entry labels through `UPDATE labels ... FROM stock s
 * ... WHERE s.product_id = OLD.id` - a join read that takes no lock on the `stock` rows it
 * reads - and only locks those rows afterwards, via its own `DELETE FROM stock`. So on this
 * one path the order was product -> labels -> print_jobs -> stock: labels *before* the
 * entity row, unlike every other retirement site (a direct `DELETE` always locks its own row
 * before its `BEFORE DELETE` trigger runs).
 *
 * The race this let through: `StockService` updates a stock row S (e.g. `auto_reprint_stock_
 * label` on opening or a transfer, `StockService::ReviseStockEntryLabelIfLive()`); a
 * concurrent `DELETE FROM products` retires S's label and cancels whatever is queued for it
 * at that moment (the revised print's job does not exist yet), then reaches `DELETE FROM
 * stock` and blocks on S; `RevisedPrint('stock_entry', S)` - having read the label as still
 * live before the delete's `UPDATE labels` committed - creates a new `revised_print` job and
 * commits; the delete then finishes. Result: the label is retired, and the new job stays
 * queued and uncancelled forever - D2 violated, with no deadlock to catch it.
 *
 * The fix: `trg_cascade_product_removal` now takes `PERFORM 1 FROM stock WHERE product_id =
 * OLD.id ORDER BY id FOR UPDATE` before its labels `UPDATE`, locking every affected stock row
 * first - the same entity-before-labels order every other retirement site already used.
 * `RevisedPrint()` takes its own entity lock (`LabelIdentityService::Issue()`'s `FOR UPDATE`
 * on the `stock` row) before its label check (`AssertLabelLive()`), so the two now correctly
 * serialise: whichever reaches the stock row first, the other waits behind it, and the
 * retirement's cancellation - which always runs after its own stock-row lock, and so after
 * any revised print that got there first has already committed - never misses a job.
 *
 * This is the two-connection reproduction: a gate connection pre-holds the stock row S
 * (`FOR UPDATE`); a `label-revisedprint-subprocess-helper.php` subprocess runs a real
 * `RevisedPrint('stock_entry', S, ...)` and queues on S; a
 * `label-retirement-delete-subprocess-helper.php` subprocess runs the real production
 * `DELETE FROM products` and queues too (behind the gate, and - if the revised print reaches
 * the label first - behind it as well); releasing the gate lets both proceed, in whichever
 * order PostgreSQL's lock queue serves them, and the assertion holds either way: no queued,
 * unclaimed job for the label survives its retirement uncancelled.
 */
class LabelRevisedPrintCascadeCancelsStockEntryJobTest extends PgsqlSchemaTestCase
{
	private static function driverDefinition(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
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

	/** @return array{status: int, stderr: string, error_message?: string} */
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
	 * See this class's own docblock for the full scenario. Before the fix (the `PERFORM ...
	 * FOR UPDATE` removed from trg_cascade_product_removal, reproducing migrations/
	 * 0296.pgsql.sql as it stood at commit 22dc9aec), this fails: the revised print's job
	 * commits after the delete's own cancellation already ran over "whatever was queued at
	 * that moment" (which did not yet include it), and stays queued forever even though its
	 * label is retired. After the fix, every queued, unclaimed job for the label - including
	 * the one racing the retirement into existence - ends up cancelled.
	 */
	public function testRevisedPrintNeverLeavesAnUncancelledStockEntryJob(): void
	{
		$db = self::Pdo();

		$worker = (int)$db->query("INSERT INTO label_workers(name, configuration_mode) VALUES ('stock-cascade-worker-" . bin2hex(random_bytes(4)) . "', 'declared') RETURNING id")->fetchColumn();
		$db->beginTransaction();
		(new DriverRegistryService($db))->Register($worker, [self::driverDefinition()]);
		$db->commit();
		$db->beginTransaction();
		$printer = (int)(new PrinterConfigurationService($db))->Save([
			'name' => 'Stock cascade printer ' . $worker, 'worker_id' => $worker, 'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0', 'connection' => '127.0.0.1:9100', 'connection_type' => 'tcp',
			'model' => 'QL-820NWBc', 'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		]);
		$db->commit();
		$template = (int)$db->query("SELECT id FROM label_templates WHERE entity_kind = 'stock_entry' ORDER BY id LIMIT 1")->fetchColumn();
		self::assertNotSame(0, $template, 'Precondition: a published stock_entry template exists (migration 0283\'s seeded default)');

		$tag = bin2hex(random_bytes(4));
		$locationId = (int)$db->query("INSERT INTO locations(name) VALUES ('Stock cascade location $tag') RETURNING id")->fetchColumn();
		$quId = (int)$db->query("INSERT INTO quantity_units(name, name_plural) VALUES ('Stock cascade qu $tag', 'Stock cascade qus $tag') RETURNING id")->fetchColumn();
		$productId = (int)$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Stock cascade product $tag', $locationId, $quId, $quId) RETURNING id")->fetchColumn();
		$stockId = (int)$db->query("INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id) VALUES ($productId, 2, 'stock-cascade-$tag', '2999-12-31', '2026-01-01', $locationId) RETURNING id")->fetchColumn();

		$db->beginTransaction();
		$job = (new LabelOperationsService($db))->IssueLocation('stock_entry', $stockId, 0, $printer, $template, null, 'en', 'UTC');
		$db->commit();
		$labelUid = (string)$job['label_uid'];

		$gate = new PDO(
			'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$gate->exec('SET search_path TO ' . self::Schema() . ', public');
		$gate->beginTransaction();
		$gate->query("SELECT id FROM stock WHERE id = $stockId FOR UPDATE")->fetchColumn();

		$revisedPrintProcess = self::start([__DIR__ . '/label-revisedprint-subprocess-helper.php', 'stock_entry', (string)$stockId, '0', (string)$printer, (string)$template]);
		$deleteProcess = null;
		$revisedPrintResult = null;
		$deleteResult = null;

		try
		{
			self::assertTrue(self::waitBlocked('label-revisedprint-helper'), 'Timed out waiting for the revised-print subprocess to block on the gate-held stock row');

			$deleteProcess = self::start([__DIR__ . '/label-retirement-delete-subprocess-helper.php', 'products', (string)$productId]);
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
			'The retirement (DELETE FROM products) must never be aborted by a deadlock: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);
		self::assertSame(200, $deleteResult['status'], 'the retirement must succeed: ' . ($deleteResult['error_message'] ?? '') . ' ' . $deleteResult['stderr']);

		$retiredAt = $db->query('SELECT retired_at FROM labels WHERE uid = ' . $db->quote($labelUid))->fetchColumn();
		self::assertNotFalse($retiredAt, 'Precondition: the label row exists');
		self::assertNotNull($retiredAt, 'The label is retired once the product delete commits');

		$jobs = $db->query('SELECT id, operation, cancelled_at, current_attempt_id FROM print_jobs WHERE label_uid = ' . $db->quote($labelUid) . ' ORDER BY id')
			->fetchAll(PDO::FETCH_ASSOC);
		$uncancelled = array_filter($jobs, static fn (array $j): bool => $j['cancelled_at'] === null && $j['current_attempt_id'] === null);

		self::assertSame([], array_values($uncancelled),
			'D2 violated: the label is retired but a queued, unclaimed job for it survives uncancelled - '
			. json_encode(['revisedPrint' => $revisedPrintResult, 'delete' => $deleteResult, 'jobs' => $jobs]));
	}
}
