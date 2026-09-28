<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\ChoresService;
use Victual\Services\Database\PostgresDialect;
use Victual\Services\DatabaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #534: a refused write must not advance GET /api/system/db-changed-time or republish
 * the MQTT state snapshot.
 *
 * DatabaseService's change-tracking flags - the request-level $DataChanged
 * (DatabaseService::HasDataChanged()) and PostgresDialect's own deferred
 * $DbChangedPending, which FlushDbChangedTime() turns into the actual changed-time UPDATE -
 * are set the moment a write statement reaches the database (ExecuteDbStatement() and the
 * query callback GetDbConnection() installs), with no knowledge of whether the transaction
 * that write is part of ever commits. Before the fix, a rolled-back transaction left both
 * flags set exactly as if it had committed, so a refused request still advanced the changed
 * time every poller refetches on and still republished the ambient MQTT state snapshot.
 *
 * Two layers of coverage:
 *
 * - Direct calls against DatabaseService::InTransaction() itself - no HTTP, no service this
 *   fix's reservation does not own - proving the flag semantics precisely: a rollback
 *   restores whatever the flags were immediately before that transaction began; several
 *   separate top-level transactions in one request behave correctly, because the flag means
 *   "something committed" and not "nothing failed"; and a throw from inside a nested,
 *   joining InTransaction() call unwinds to the same outermost catch as a throw from the
 *   outermost call itself.
 * - Two HTTP requests through the real chore-execution refusal from issue #494/#527
 *   (tracking a chore whose linked product has insufficient stock), each run in a subprocess
 *   of its own (rollback-change-signals-subprocess-helper.php) against a stand-in MQTT
 *   broker - tests/Pgsql/mqttcoverage-subprocess-helper.php's own "broker" mode, reused
 *   rather than reimplemented - so the request-end publish this fix also protects can be
 *   observed actually not happening. The same request with sufficient stock is the control:
 *   it must still advance the changed time and still publish.
 * - Two more HTTP requests through DELETE /api/objects/products/{id}, refused by
 *   product_location_min_stock's FOREIGN KEY when a minimum-stock row still references the
 *   product. Unlike every case above, GenericEntityApiController::DeleteObject() runs this
 *   delete in autocommit - no DatabaseService::InTransaction() call wraps it at all - so the
 *   rollback-restore fix above cannot reach it: the change was marked before the DELETE
 *   statement even ran, by LessQL's own onQuery() hook firing ahead of prepare()/execute().
 *   See ChangeTrackingLessQlDatabase's docblock for that fix. The same request against an
 *   unreferenced product is the control.
 *
 * Registered in the mqttcoverage suite (phpunit.xml), alongside MqttCoverageTest.php - the
 * suite that already owns MQTT and request-end coverage.
 */
class RollbackChangeSignalsTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static int $pantryLocationId;
	private static string $apiKey = '';

	private static string $scratch;
	private static string $brokerLog;
	private static int $brokerPort;

	/** @var resource|null */
	private static $brokerProcess = null;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'rollback-signals-caller', 'fixture')");

		self::$pantryLocationId = self::insertRow('locations', ['name' => 'Rollback Signals Pantry']);

		// For the HTTP scenarios only - matching ComposedOperationAtomicityTest.php's own
		// subprocess-driven API user, admin for simplicity.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9701, 'rollback-signals-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9701, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));

		// last_used is seeded to now() rather than left NULL: ApiKeyService::
		// GetApiKeyRowByApiKey() stamps it once per calendar day per key, over its own
		// GetDbChangedTime()/SetDbChangedTime() snapshot-restore round trip - unrelated
		// bookkeeping that would otherwise rewrite (and, via GetDbChangedTime()'s own
		// second-rounding, truncate) the changed-time row on this class's very first
		// authenticated request and be mistaken for the issue #534 defect this class exists
		// to catch. A key already used today skips that branch entirely.
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, last_used) VALUES (?, ?, 9701, now() + interval '30 days', ?, now())");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);

		self::$scratch = VICTUAL_DATAPATH . '/rollbackchangesignals';

		if (!is_dir(self::$scratch))
		{
			mkdir(self::$scratch, 0755, true);
		}

		self::$brokerLog = self::$scratch . '/broker.log';
		self::$brokerPort = self::ReservePort();

		// PHPUnit does not call tearDownAfterClass when setUpBeforeClass raises, so a broker
		// that started before a later failure would outlive the run holding its port.
		try
		{
			self::StartBroker(self::$brokerPort, self::$brokerLog, self::$brokerProcess);
		}
		catch (\Throwable $failure)
		{
			self::StopBroker();

			throw $failure;
		}
	}

	public static function tearDownAfterClass(): void
	{
		self::StopBroker();

		parent::tearDownAfterClass();
	}

	protected function setUp(): void
	{
		parent::setUp();

		// A clean $DataChanged/$DbChangedPending baseline for every test method: PHPUnit
		// does not reset static state between methods of the same class on its own, and the
		// direct-call tests below assert on exactly that state.
		DatabaseService::ResetForTest();
	}

	/** Stops the stand-in broker, if it is running. Safe to call twice. */
	private static function StopBroker(): void
	{
		if (is_resource(self::$brokerProcess))
		{
			proc_terminate(self::$brokerProcess);
			proc_close(self::$brokerProcess);
		}

		self::$brokerProcess = null;
	}

	// ------------------------------------------------------------------------------
	// Fixture helpers
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$pantryLocationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	private static function insertChore(int $productId, float $amount, string $name): int
	{
		return self::insertRow('chores', [
			'name' => $name,
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
			'consume_product_on_execution' => 1,
			'product_id' => $productId,
			'product_amount' => $amount,
		]);
	}

	/**
	 * A minimum-stock row referencing $productId, so that FOREIGN KEY REFERENCES products(id)
	 * on product_location_min_stock.product_id (migrations/0276.pgsql.sql, no ON DELETE
	 * clause) refuses deleting that product - the autocommit-write case this class's
	 * DELETE-over-HTTP test below exists for.
	 */
	private static function insertProductLocationMinStock(int $productId): int
	{
		return self::insertRow('product_location_min_stock', [
			'product_id' => $productId,
			'location_id' => self::$pantryLocationId,
			'min_stock_amount' => 1,
		]);
	}

	/** Books stock through the real purchase path, matching ComposedOperationAtomicityTest.php. */
	private static function stockUp(int $productId, float $amount): void
	{
		StockService::GetInstance()->AddProduct($productId, $amount, '2035-06-30', StockService::TRANSACTION_TYPE_PURCHASE, '2026-04-01', 1.0);
	}

	/**
	 * The persisted changed time, read directly from PostgresDialect's own tracking table
	 * rather than through GetDbChangedTime() (which rounds to whole seconds - too coarse to
	 * tell two writes a fraction of a second apart in the same test method apart).
	 */
	private static function changedTime(): string
	{
		return (string)self::$db->query('SELECT changed_time FROM ' . PostgresDialect::CHANGED_TIME_TABLE . ' WHERE id = 1')->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Stand-in broker (mirrors MqttCoverageTest.php's own private helpers - not shared,
	// since that class is not this fix's reservation; see RecipeSelfProductionLockOrderTest
	// for the same precedent of copying rather than editing another suite's test class)
	// ------------------------------------------------------------------------------

	private static function ReservePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

		if ($socket === false)
		{
			self::fail('could not reserve a port: ' . $errorMessage);
		}

		$name = (string)stream_socket_get_name($socket, false);
		fclose($socket);

		return (int)substr($name, strrpos($name, ':') + 1);
	}

	/**
	 * Starts tests/Pgsql/mqttcoverage-subprocess-helper.php's stand-in broker in "record"
	 * mode - reused rather than reimplemented, per that file's own docblock on why a
	 * dependency-free stand-in exists at all.
	 *
	 * @param resource|null $handle
	 */
	private static function StartBroker(int $port, string $logFile, &$handle): void
	{
		file_put_contents($logFile, '');

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/mqttcoverage-subprocess-helper.php', 'broker', (string)$port, $logFile, 'record'],
			[1 => ['file', $logFile . '.stdout', 'a'], 2 => ['file', $logFile . '.stderr', 'a']],
			$pipes,
			null,
			self::ChildEnvironment()
		);

		if (!is_resource($process))
		{
			self::fail('could not start the stand-in broker on 127.0.0.1:' . $port);
		}

		$handle = $process;

		$waited = 0;

		while ($waited < 100)
		{
			$probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2);

			if ($probe !== false)
			{
				fclose($probe);

				// The probe itself is a connection the stand-in recorded; the log belongs to
				// the tests, so it starts empty for them
				usleep(50000);
				file_put_contents($logFile, '');

				return;
			}

			usleep(50000);
			$waited++;
		}

		self::fail('the stand-in broker never bound 127.0.0.1:' . $port . ' - see ' . $logFile . '.stderr');
	}

	/** @return array<string, string> */
	private static function ChildEnvironment(): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');

		foreach (array_keys($inherited) as $name)
		{
			if (str_starts_with((string)$name, 'VICTUAL_MQTT_'))
			{
				unset($inherited[$name]);
			}
		}

		return array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
		]);
	}

	/** Waits for the stand-in broker to finish recording a batch - see MqttCoverageTest::AwaitBatch(). */
	private static function AwaitBatch(string $logFile): void
	{
		$waited = 0;

		while ($waited < 100 && !str_contains((string)@file_get_contents($logFile), '=== end'))
		{
			usleep(50000);
			$waited++;
		}

		self::assertStringContainsString('=== end', (string)@file_get_contents($logFile),
			'the stand-in broker never finished the connection');
	}

	/**
	 * Runs one HTTP request through rollback-change-signals-subprocess-helper.php, with MQTT
	 * enabled and pointed at the stand-in broker.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function RunHttpRequest(string $method, string $path, ?array $body = null): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey], 'body' => $body],
			fn ($value) => $value !== null
		);

		$env = array_merge(self::ChildEnvironment(), [
			'VICTUAL_MQTT_ENABLED' => 'true',
			'VICTUAL_MQTT_HOST' => '127.0.0.1',
			'VICTUAL_MQTT_PORT' => (string)self::$brokerPort,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/rollback-change-signals-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

		self::assertIsResource($process, 'could not start the request helper');

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON.\nstdout: $output\nstderr: $errors");

		return $result;
	}

	// ------------------------------------------------------------------------------
	// Direct DatabaseService::InTransaction() coverage
	// ------------------------------------------------------------------------------

	/**
	 * Given a transaction that writes and then throws,
	 * When it rolls back,
	 * Then neither HasDataChanged() nor the persisted changed time reflect the write that
	 * did not survive - issue #534's core defect.
	 */
	public function testRolledBackTransactionRestoresDataChangedFlag(): void
	{
		$service = DatabaseService::GetInstance();
		$before = self::changedTime();

		try
		{
			$service->InTransaction(function () use ($service)
			{
				$service->ExecuteDbStatement("UPDATE locations SET description = 'rolled-back' WHERE id = " . self::$pantryLocationId);

				throw new \RuntimeException('refused');
			});

			self::fail('the closure must propagate its exception');
		}
		catch (\RuntimeException $exception)
		{
			self::assertSame('refused', $exception->getMessage());
		}

		self::assertFalse($service->HasDataChanged(),
			'a rolled-back write must not be recorded as a data change - issue #534');

		$service->GetDbChangedTime(); // must be a no-op: nothing is pending to flush
		self::assertSame($before, self::changedTime(),
			'db-changed-time must not advance for a rolled-back write - issue #534');
	}

	/**
	 * The control for the case above: the same shape, with the closure returning normally
	 * instead of throwing, commits and does advance both signals.
	 */
	public function testCommittedTransactionAdvancesDataChangedFlag(): void
	{
		$service = DatabaseService::GetInstance();
		$before = self::changedTime();

		$service->InTransaction(function () use ($service)
		{
			$service->ExecuteDbStatement("UPDATE locations SET description = 'committed-control' WHERE id = " . self::$pantryLocationId);
		});

		self::assertTrue($service->HasDataChanged(), 'a committed write is recorded as a data change');

		$service->GetDbChangedTime(); // the request-end flush a real request performs
		self::assertNotSame($before, self::changedTime(), 'db-changed-time must advance for a committed write');
	}

	/**
	 * Given one top-level transaction that commits a real change, followed by a second,
	 * separate top-level transaction (not nested inside the first - both already returned to
	 * "no transaction open" between them) that writes and then refuses,
	 * When the second one rolls back,
	 * Then HasDataChanged() must still be true - the first transaction's own commit, not the
	 * second one's refusal. The flag means "something committed", not "nothing failed".
	 */
	public function testMixedRequestSecondRefusalDoesNotEraseTheFirstCommitsSignal(): void
	{
		$service = DatabaseService::GetInstance();

		$service->InTransaction(function () use ($service)
		{
			$service->ExecuteDbStatement("UPDATE locations SET description = 'first-committed' WHERE id = " . self::$pantryLocationId);
		});

		self::assertTrue($service->HasDataChanged(), 'the first, separate transaction committed a change');

		try
		{
			$service->InTransaction(function () use ($service)
			{
				$service->ExecuteDbStatement("UPDATE locations SET description = 'second-refused' WHERE id = " . self::$pantryLocationId);

				throw new \RuntimeException('refused');
			});

			self::fail('the closure must propagate its exception');
		}
		catch (\RuntimeException $exception)
		{
			self::assertSame('refused', $exception->getMessage());
		}

		self::assertTrue($service->HasDataChanged(),
			'the second transaction refusing must not erase the first, already-committed one\'s signal - issue #534');

		$statement = self::$db->prepare('SELECT description FROM locations WHERE id = ?');
		$statement->execute([self::$pantryLocationId]);
		self::assertSame('first-committed', $statement->fetchColumn(), 'the refused second update did not persist');
	}

	/**
	 * Given an outer transaction whose work calls a second, nested InTransaction() that
	 * joins it (DatabaseService::InTransaction()'s own documented nesting behaviour - the
	 * shape ChoresService::TrackChore() calling StockService::ConsumeProduct() has), and the
	 * nested call's own work throws,
	 * When the throw propagates - uncaught by the nested, joining call itself - up to the
	 * outermost call's catch block,
	 * Then the whole transaction rolls back and the flags are restored to what they were
	 * before the *outer* transaction began, not merely to what they were before the nested
	 * call ran.
	 */
	public function testNestedInTransactionThrowingRestoresFlagsAtTheOutermostCatch(): void
	{
		$service = DatabaseService::GetInstance();
		$before = self::changedTime();

		try
		{
			$service->InTransaction(function () use ($service)
			{
				$service->ExecuteDbStatement("UPDATE locations SET description = 'outer' WHERE id = " . self::$pantryLocationId);

				$service->InTransaction(function () use ($service)
				{
					$service->ExecuteDbStatement("UPDATE locations SET description = 'inner' WHERE id = " . self::$pantryLocationId);

					throw new \RuntimeException('refused inside the nested call');
				});
			});

			self::fail('the nested throw must propagate through the joining call to the outermost catch');
		}
		catch (\RuntimeException $exception)
		{
			self::assertSame('refused inside the nested call', $exception->getMessage());
		}

		self::assertFalse($service->HasDataChanged(),
			'a throw from a nested, joining InTransaction() call must leave the flag as it was before the outer transaction began - issue #534');

		$service->GetDbChangedTime();
		self::assertSame($before, self::changedTime(),
			'and must not advance db-changed-time either, even though both the outer and inner writes reached the database');
	}

	/**
	 * Given a refused autocommit LessQL write - no InTransaction() around it at all, the
	 * same shape GenericEntityApiController::DeleteObject() has - that correctly leaves
	 * HasDataChanged() false,
	 * When a later, unrelated RunAsBookkeeping() write then runs one that, by design,
	 * registers no change-tracking callback of its own (DatabaseService::GetDbConnection()'s
	 * query callback gates registration on !IsBookkeeping()),
	 * Then that bookkeeping write must not flip HasDataChanged() to true by firing the
	 * refused write's own, stale registration - ChangeTrackingLessQlDatabase has to clear
	 * its one callback slot on a throw, not merely leave whatever was in it for the next
	 * successful call to inherit.
	 */
	public function testARefusedAutocommitWriteLeavesNoStaleCallbackForALaterBookkeepingWrite(): void
	{
		$service = DatabaseService::GetInstance();
		$before = self::changedTime();

		$productId = self::insertProduct('Rollback Signals Stale Callback Product');
		self::insertProductLocationMinStock($productId);

		try
		{
			$service->GetDbConnection()->products($productId)->delete();

			self::fail('the FOREIGN KEY violation must propagate');
		}
		catch (\PDOException $exception)
		{
			self::assertSame('23503', $exception->errorInfo[0] ?? $exception->getCode());
		}

		self::assertFalse($service->HasDataChanged(),
			'the refused delete itself must not be recorded as a data change - issue #534');

		$service->RunAsBookkeeping(function () use ($service)
		{
			$service->GetDbConnection()->locations(self::$pantryLocationId)->update(['description' => 'stale-callback-bookkeeping']);
		});

		self::assertFalse($service->HasDataChanged(),
			'a bookkeeping write that registers no callback of its own must not fire the refused delete\'s stale one');

		$service->GetDbChangedTime(); // must be a no-op: nothing is pending to flush
		self::assertSame($before, self::changedTime(),
			'db-changed-time must not move either');
	}

	// ------------------------------------------------------------------------------
	// HTTP coverage: the real chore-consumption refusal from issue #494/#527
	// ------------------------------------------------------------------------------

	/**
	 * Given a chore linked to a product with only 1 unit in stock, configured to consume 2,
	 * When the chore is tracked over the real HTTP path,
	 * Then TrackChoreExecution() is refused (400) and, per issue #534, db-changed-time does
	 * not move and nothing is published to MQTT - even though the request did write (the
	 * chores_log row PR #527 already proved rolls back) before refusing.
	 */
	public function testARefusedChoreExecutionOverHttpLeavesDbChangedTimeUnchangedAndPublishesNothing(): void
	{
		file_put_contents(self::$brokerLog, '');

		$productId = self::insertProduct('Rollback Signals Insufficient Stock');
		self::stockUp($productId, 1);

		$choreId = self::insertChore($productId, 2, 'Rollback Signals Chore Refused');

		$before = self::changedTime();

		$result = self::RunHttpRequest('POST', '/api/chores/' . $choreId . '/execute', []);

		self::assertSame(400, $result['status'], 'the chore execution is refused over HTTP: ' . $result['body']);
		self::assertStringContainsString('cannot be > current stock amount', $result['body'],
			'refused for the intended reason, not some other 400');

		self::assertSame($before, self::changedTime(),
			'a refused write over HTTP must not advance db-changed-time - issue #534');

		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'a refused write over HTTP must publish nothing to MQTT - issue #534');
	}

	/**
	 * The control for the case above: the same shape, with stock enough to cover the
	 * consumption, both advances db-changed-time and publishes - proving the refusal case is
	 * a real refusal to signal, not an inability to signal at all.
	 */
	public function testASuccessfulChoreExecutionOverHttpAdvancesDbChangedTimeAndPublishes(): void
	{
		file_put_contents(self::$brokerLog, '');

		$productId = self::insertProduct('Rollback Signals Sufficient Stock');
		self::stockUp($productId, 5);

		$choreId = self::insertChore($productId, 1, 'Rollback Signals Chore Succeeds');

		$before = self::changedTime();

		$result = self::RunHttpRequest('POST', '/api/chores/' . $choreId . '/execute', []);

		self::assertSame(200, $result['status'], 'the chore execution succeeds over HTTP: ' . $result['body']);

		self::assertNotSame($before, self::changedTime(),
			'the control: a committed write over HTTP must still advance db-changed-time');

		self::AwaitBatch(self::$brokerLog);
		self::assertStringContainsString('=== connect', (string)file_get_contents(self::$brokerLog),
			'the control: a committed write over HTTP must still publish the MQTT state snapshot');
	}

	// ------------------------------------------------------------------------------
	// HTTP coverage: an autocommit write refused with no InTransaction() around it at all
	// ------------------------------------------------------------------------------

	/**
	 * Given a product referenced by product_location_min_stock.product_id,
	 * When DELETE /api/objects/products/{id} is issued over HTTP,
	 * Then the FOREIGN KEY refusal answers 400 and, per issue #534, neither db-changed-time
	 * nor the MQTT state snapshot reflect it.
	 *
	 * This is a different shape from every other case in this class: GenericEntityApiController
	 * ::DeleteObject() runs $row->delete() in autocommit, with no DatabaseService::
	 * InTransaction() call around it at all (see that method's own comment on why), so
	 * InTransaction()'s rollback-restores-the-flags fix cannot reach it - there is no
	 * transaction to roll back. What used to mark the change here was LessQL's own onQuery()
	 * hook, which GetDbConnection()'s query callback used to act on immediately, before the
	 * DELETE statement it describes ever reached the database - see
	 * ChangeTrackingLessQlDatabase's docblock for the fix.
	 */
	public function testARefusedProductDeleteOverHttpLeavesDbChangedTimeUnchangedAndPublishesNothing(): void
	{
		file_put_contents(self::$brokerLog, '');

		$productId = self::insertProduct('Rollback Signals Referenced Product');
		self::insertProductLocationMinStock($productId);

		$before = self::changedTime();

		$result = self::RunHttpRequest('DELETE', '/api/objects/products/' . $productId);

		self::assertSame(400, $result['status'], 'the product delete is refused over HTTP: ' . $result['body']);
		self::assertStringContainsString('still referenced', $result['body'],
			'refused for the intended reason, not some other 400');

		self::assertSame($before, self::changedTime(),
			'a refused write over HTTP must not advance db-changed-time - issue #534');

		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'a refused write over HTTP must publish nothing to MQTT - issue #534');
	}

	/**
	 * The control for the case above: the same shape, with no reference in the way, both
	 * advances db-changed-time and publishes - proving the refusal case is a real refusal to
	 * signal, not an inability to signal at all.
	 */
	public function testASuccessfulProductDeleteOverHttpAdvancesDbChangedTimeAndPublishes(): void
	{
		file_put_contents(self::$brokerLog, '');

		$productId = self::insertProduct('Rollback Signals Unreferenced Product');

		$before = self::changedTime();

		$result = self::RunHttpRequest('DELETE', '/api/objects/products/' . $productId);

		self::assertSame(204, $result['status'], 'the product delete succeeds over HTTP: ' . $result['body']);

		self::assertNotSame($before, self::changedTime(),
			'the control: a committed write over HTTP must still advance db-changed-time');

		self::AwaitBatch(self::$brokerLog);
		self::assertStringContainsString('=== connect', (string)file_get_contents(self::$brokerLog),
			'the control: a committed write over HTTP must still publish the MQTT state snapshot');
	}
}
