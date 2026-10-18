<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\DatabaseService;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\Outbox\OutboxService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #902: an operator's way back for dead-lettered InfluxDB outbox rows.
 *
 * OutboxService::DeadLetter() sets dead_lettered_at and nothing cleared it, so a row set aside
 * by a transient endpoint misconfiguration was lost to InfluxDB for good. The maintainer's
 * decisions (2026-10-13): Q1 A - a CLI mode of bin/victual-publish-state (--requeue-dead), no
 * route and no page; Q2/Q3 - only stock.transaction_booked rows, explicit ids or an explicitly
 * selected event type, a dry run before a bulk selection executes, eligibility re-checked
 * transactionally; Q4 - attempts and last_error preserved, affected ids and previous errors
 * reported to the operator only, and the claims "a concurrent drain is safe" and "regenerated
 * points are byte-identical" verified here rather than assumed.
 *
 * The command is driven as the real binary in a subprocess (the exit code is half of what is
 * asserted); the service is driven in-process where a second connection is needed to hold a
 * transaction open. Subprocess output goes to files, never to pipes read one after the other:
 * a child that fills the pipe nobody is reading blocks forever.
 */
class OutboxRequeueTest extends PgsqlSchemaTestCase
{
	private const INFLUX = 'stock.transaction_booked';
	private const LABEL = 'label.print_requested';

	private static string $scratch;
	private static string $standinLog;
	private static string $standinControl;
	private static int $standinPort;

	/** @var resource|null */
	private static $standinProcess = null;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$scratch = VICTUAL_DATAPATH . '/outboxrequeue';

		if (!is_dir(self::$scratch))
		{
			mkdir(self::$scratch, 0755, true);
		}

		self::$standinLog = self::$scratch . '/standin.log';
		self::$standinControl = self::$scratch . '/standin-control.txt';
		self::$standinPort = self::ReservePort();

		file_put_contents(self::$standinLog, '');
		file_put_contents(self::$standinControl, 'accept');

		try
		{
			self::StartStandin();
		}
		catch (\Throwable $failure)
		{
			self::StopStandin();

			throw $failure;
		}
	}

	public static function tearDownAfterClass(): void
	{
		self::StopStandin();

		parent::tearDownAfterClass();
	}

	protected function setUp(): void
	{
		parent::setUp();

		self::Pdo()->exec('DELETE FROM outbox');
		file_put_contents(self::$standinLog, '');
		file_put_contents(self::$standinControl, 'accept');
	}

	// ---------------------------------------------------------------------------------
	// Fixtures
	// ---------------------------------------------------------------------------------

	private static function StopStandin(): void
	{
		if (is_resource(self::$standinProcess))
		{
			proc_terminate(self::$standinProcess);
			proc_close(self::$standinProcess);
		}

		self::$standinProcess = null;
	}

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

	private static function StartStandin(): void
	{
		$process = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . self::$standinPort, VICTUAL_ROOT_PATH . '/.devtools/mqtt/influx-standin.php'],
			[1 => ['file', self::$standinLog . '.stdout', 'a'], 2 => ['file', self::$standinLog . '.stderr', 'a']],
			$pipes,
			null,
			array_merge(self::inheritedEnv(), [
				'VICTUAL_STANDIN_LOG' => self::$standinLog,
				'VICTUAL_STANDIN_CONTROL' => self::$standinControl
			])
		);

		if (!is_resource($process))
		{
			self::fail('could not start the InfluxDB stand-in on 127.0.0.1:' . self::$standinPort);
		}

		self::$standinProcess = $process;

		for ($waited = 0; $waited < 100; $waited++)
		{
			$probe = @fsockopen('127.0.0.1', self::$standinPort, $errorNumber, $errorMessage, 0.2);

			if ($probe !== false)
			{
				fclose($probe);

				return;
			}

			usleep(50000);
		}

		self::fail('the InfluxDB stand-in never bound 127.0.0.1:' . self::$standinPort . ' - see ' . self::$standinLog . '.stderr');
	}

	private static function inheritedEnv(): array
	{
		return array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
	}

	/** The environment a real bin/ script needs to reach this class's schema - the shape StockMaintenanceCompactionTest uses for bin/victual-compact-stock. */
	private static function cliEnv(): array
	{
		return array_merge(self::inheritedEnv(), [
			'VICTUAL_DB_DRIVER' => 'pgsql',
			'VICTUAL_DB_HOST' => (string)getenv('PGHOST'),
			'VICTUAL_DB_PORT' => (string)getenv('PGPORT'),
			'VICTUAL_DB_NAME' => (string)getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DB_USER' => (string)getenv('PGUSER'),
			'VICTUAL_DB_PASSWORD' => (string)getenv('PGPASSWORD'),
			'VICTUAL_DATAPATH' => (string)getenv('VICTUAL_DATAPATH'),
			'PGOPTIONS' => '-c search_path=' . self::Schema() . ',public',
			'VICTUAL_INFLUXDB_ENABLED' => 'true',
			'VICTUAL_INFLUXDB_URL' => 'http://127.0.0.1:' . self::$standinPort,
			'VICTUAL_INFLUXDB_TOKEN' => 'outbox-requeue-test-token',
			'VICTUAL_INFLUXDB_ORG' => 'victual',
			'VICTUAL_INFLUXDB_BUCKET' => 'victual',
			'VICTUAL_INFLUXDB_TIMEOUT_SECONDS' => '2'
		]);
	}

	/** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
	private static function cli(string ...$arguments): array
	{
		$out = self::$scratch . '/cli.out';
		$err = self::$scratch . '/cli.err';

		$process = proc_open(
			array_merge([PHP_BINARY, VICTUAL_ROOT_PATH . '/bin/victual-publish-state'], $arguments),
			[1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
			$pipes,
			null,
			self::cliEnv()
		);
		self::assertIsResource($process, 'bin/victual-publish-state could not be started');

		$exit = proc_close($process);

		return [$exit, (string)file_get_contents($out), (string)file_get_contents($err)];
	}

	private static function eventId(): string
	{
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
	}

	/** One stock entry, so BuildLines() emits exactly one stock_value line carrying the transaction id. */
	private static function payload(string $transactionId): string
	{
		return json_encode([
			'payload_version' => 2,
			'event_id' => self::eventId(),
			'transaction_id' => $transactionId,
			'occurred_at' => '2026-10-12 00:00:00',
			'bookings' => [],
			'stock' => [['product_id' => 1, 'amount' => 1.0, 'value' => 1.0]]
		], JSON_THROW_ON_ERROR);
	}

	/** @param array{type?: string, dead?: bool, delivered?: bool, attempts?: int, error?: ?string} $state */
	private function row(string $transactionId, array $state = []): int
	{
		$dead = $state['dead'] ?? true;
		$statement = self::Pdo()->prepare(
			'INSERT INTO outbox (event_type, payload, attempts, last_error, dead_lettered_at, delivered_at)'
				. ' VALUES (?, ?, ?, ?, ' . ($dead ? "'2026-10-12 08:30:00'" : 'NULL') . ', '
				. (($state['delivered'] ?? false) ? "'2026-10-12 09:00:00'" : 'NULL') . ') RETURNING id'
		);
		$statement->execute([
			$state['type'] ?? self::INFLUX,
			self::payload($transactionId),
			$state['attempts'] ?? 3,
			array_key_exists('error', $state) ? $state['error'] : 'HTTP 422: unable to parse ' . $transactionId . ' - field type conflict'
		]);

		return (int)$statement->fetchColumn();
	}

	private static function snapshot(): array
	{
		return self::Pdo()->query('SELECT * FROM outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function one(int $id): array
	{
		$found = self::Pdo()->query('SELECT delivered_at, dead_lettered_at, attempts, last_error FROM outbox WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($found, 'the fixture row must still exist');

		return $found;
	}

	private static function service(): OutboxService
	{
		return new OutboxService();
	}

	/** Points DatabaseService at another connection, the way PgsqlSchemaTestCase does for the schema's own. */
	private static function useConnection(PDO $pdo): void
	{
		(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
		(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);
	}

	private static function secondConnection(): PDO
	{
		$pdo = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'), getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');
		DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

		return $pdo;
	}

	// ---------------------------------------------------------------------------------
	// Eligibility (Q2, Q3)
	// ---------------------------------------------------------------------------------

	public function testOnlyDeadLetteredUndeliveredInfluxRowsAreEligibleAndOneIneligibleIdRefusesTheWholeRequest(): void
	{
		$eligible1 = $this->row('elig-1');
		$eligible2 = $this->row('elig-2');
		$delivered = $this->row('elig-delivered', ['delivered' => true]);
		$live = $this->row('elig-live', ['dead' => false, 'error' => null, 'attempts' => 0]);
		$label = $this->row('elig-label', ['type' => self::LABEL]);
		$missing = $eligible2 + 100000;

		$before = self::snapshot();
		$result = self::service()->RequeueDeadLettered([$eligible1, $delivered, $live, $label, $missing], self::INFLUX, true);

		self::assertSame([], $result['requeued'], 'one ineligible id means nothing is requeued, so a typo never half-applies');
		self::assertSame([$delivered, $live, $label, $missing], array_keys($result['ineligible']));
		self::assertStringContainsString('delivered', $result['ineligible'][$delivered]);
		self::assertStringContainsString('not dead-lettered', $result['ineligible'][$live]);
		self::assertStringContainsString('reprint', $result['ineligible'][$label]);
		self::assertStringContainsString('does not exist', $result['ineligible'][$missing]);
		self::assertSame($before, self::snapshot(), 'a refused request leaves every outbox row exactly as it was');

		$result = self::service()->RequeueDeadLettered([$eligible2, $eligible1], self::INFLUX, true);
		self::assertSame([$eligible1, $eligible2], $result['requeued']);
		self::assertNull(self::one($eligible1)['dead_lettered_at']);
		self::assertNull(self::one($eligible2)['dead_lettered_at']);
		self::assertNotNull(self::one($delivered)['dead_lettered_at'], 'a row that is not eligible keeps whatever it had');
		self::assertNotNull(self::one($label)['dead_lettered_at'], 'a label job is never put back in the queue');
	}

	public function testLabelRowsAreRefusedByTheServiceAndByTheCommand(): void
	{
		$label = $this->row('label-1', ['type' => self::LABEL]);
		$before = self::snapshot();

		try
		{
			self::service()->RequeueDeadLettered([], self::LABEL, true);
			self::fail('the label event type must be refused');
		}
		catch (\InvalidArgumentException $refusal)
		{
			self::assertStringContainsString('reprint', $refusal->getMessage());
		}

		[$exit, , $stderr] = self::cli('--requeue-dead', '--event-type=' . self::LABEL, '--execute');
		self::assertSame(1, $exit);
		self::assertStringContainsString('reprint', $stderr, 'the operator is told where label jobs recover');

		[$exit, , $stderr] = self::cli('--requeue-dead', '--ids=' . $label);
		self::assertSame(1, $exit, 'an explicit label id is reported and refused too');
		self::assertStringContainsString('#' . $label, $stderr);

		self::assertSame($before, self::snapshot());
	}

	// ---------------------------------------------------------------------------------
	// The command: dry run, execution, report, exit codes (Q3, Q4)
	// ---------------------------------------------------------------------------------

	public function testABulkSelectionIsADryRunUntilExecuted(): void
	{
		$long = 'HTTP 422: ' . str_repeat('field type conflict; ', 20) . 'END-OF-ERROR';
		$a = $this->row('dry-a', ['error' => $long]);
		$b = $this->row('dry-b');
		$this->row('dry-delivered', ['delivered' => true]);
		$before = self::snapshot();

		[$exit, $stdout, $stderr] = self::cli('--requeue-dead', '--event-type=' . self::INFLUX);

		self::assertSame(0, $exit, 'a dry run succeeds: ' . $stderr);
		self::assertStringContainsString('2', $stdout);
		self::assertStringContainsString('#' . $a, $stdout);
		self::assertStringContainsString('#' . $b, $stdout);
		self::assertStringContainsString('HTTP 422', $stdout);
		self::assertStringNotContainsString('END-OF-ERROR', $stdout, 'the dry run truncates last_error for display');
		self::assertStringContainsString('Nothing was changed', $stdout);
		self::assertSame($before, self::snapshot(), 'a dry run changes nothing');

		[$exit, $stdout] = self::cli('--requeue-dead', '--ids=' . $a, '--dry-run');
		self::assertSame(0, $exit);
		self::assertStringContainsString('#' . $a, $stdout);
		self::assertSame($before, self::snapshot(), '--ids with --dry-run only lists');
	}

	public function testExplicitIdsExecuteAndTheReportCarriesTheIdsTimesAndFullErrors(): void
	{
		$long = 'HTTP 422: ' . str_repeat('field type conflict; ', 20) . 'END-OF-ERROR';
		$a = $this->row('exec-a', ['error' => $long, 'attempts' => 7]);
		$b = $this->row('exec-b', ['attempts' => 2]);
		$untouched = $this->row('exec-untouched');
		$beforeUntouched = self::one($untouched);

		[$exit, $stdout, $stderr] = self::cli('--requeue-dead', '--ids=' . $a . ',' . $b);

		self::assertSame(0, $exit, $stderr);
		self::assertStringContainsString('#' . $a, $stdout);
		self::assertStringContainsString('#' . $b, $stdout);
		self::assertMatchesRegularExpression('/#' . $a . '  dead-lettered at 2026-10-12.08:30:00/', $stdout, 'the dead-lettered time, as the connection reports it, is part of what is recorded');
		self::assertStringContainsString($long, $stdout, 'the full previous error is printed before it is cleared');

		$rowA = self::one($a);
		self::assertNull($rowA['dead_lettered_at']);
		self::assertNull($rowA['delivered_at']);
		self::assertSame(7, (int)$rowA['attempts'], 'attempts is preserved');
		self::assertSame($long, $rowA['last_error'], 'last_error is preserved until a later failure overwrites it');
		self::assertSame(2, (int)self::one($b)['attempts']);
		self::assertSame($beforeUntouched, self::one($untouched), 'a row that was not asked for is not touched');
	}

	public function testABulkExecutionPrintsTheExecutedCountAndHonoursExpectCount(): void
	{
		$ids = [$this->row('bulk-1'), $this->row('bulk-2'), $this->row('bulk-3')];
		$before = self::snapshot();

		[$exit, , $stderr] = self::cli('--requeue-dead', '--event-type=' . self::INFLUX, '--execute', '--expect-count=2');
		self::assertSame(1, $exit, 'rows dead-lettered after the dry run are not requeued unseen');
		self::assertStringContainsString('3', $stderr);
		self::assertSame($before, self::snapshot(), 'a refused execution changes nothing');

		[$exit, $stdout, $stderr] = self::cli('--requeue-dead', '--event-type=' . self::INFLUX, '--execute', '--expect-count=3');
		self::assertSame(0, $exit, $stderr);
		self::assertStringContainsString('Requeued 3 of 3 eligible', $stdout);

		foreach ($ids as $id)
		{
			self::assertNull(self::one($id)['dead_lettered_at']);
		}
	}

	public function testRowsThatChangeStateBetweenTheDryRunAndTheExecutionAreNotRequeued(): void
	{
		$stays = $this->row('race-stays');
		$delivered = $this->row('race-delivered');

		$plan = self::service()->RequeueDeadLettered([], self::INFLUX, false);
		self::assertSame([$stays, $delivered], array_column($plan['eligible'], 'id'));

		self::Pdo()->exec("UPDATE outbox SET delivered_at = '2026-10-12 10:00:00' WHERE id = " . $delivered);

		$refused = self::service()->RequeueDeadLettered([], self::INFLUX, true, 2);
		self::assertNotNull($refused['refusal'], 'the count the operator saw is no longer the count');
		self::assertSame([], $refused['requeued']);
		self::assertNotNull(self::one($stays)['dead_lettered_at']);

		$executed = self::service()->RequeueDeadLettered([], self::INFLUX, true);
		self::assertSame([$stays], $executed['requeued'], 'the row delivered in the meantime is left alone');
		self::assertNotNull(self::one($delivered)['delivered_at']);
		self::assertNotNull(self::one($delivered)['dead_lettered_at'], 'and keeps its dead-letter record');

		$ids = self::service()->RequeueDeadLettered([$stays], self::INFLUX, true);
		self::assertSame([$stays => 'is not dead-lettered'], $ids['ineligible'], 'requeueing twice is reported, not repeated');
	}

	public function testArgumentRefusalsExitNonZeroAndChangeNothing(): void
	{
		$id = $this->row('args-1');
		$before = self::snapshot();

		foreach ([
			['--requeue-dead'],
			['--requeue-dead', '--ids=' . $id, '--event-type=' . self::INFLUX],
			['--requeue-dead', '--ids=abc'],
			['--requeue-dead', '--ids=0'],
			['--requeue-dead', '--ids='],
			['--requeue-dead', '--event-type=nonsense', '--execute'],
			['--requeue-dead', '--event-type=' . self::INFLUX, '--execute', '--expect-count=x'],
			['--requeue-dead', '--ids=' . $id, '--expect-count=1'],
			['--requeue-dead', '--ids=' . $id, '--ids=' . ($id + 1)],
			['--requeue-dead', '--event-type=' . self::INFLUX, '--execute', '--execute'],
			['--requeue-dead', '--ids=' . $id, '--quiet'],
			['--ids=' . $id],
			['--execute'],
			['--dry-run']
		] as $arguments)
		{
			[$exit, , $stderr] = self::cli(...$arguments);
			self::assertSame(1, $exit, implode(' ', $arguments) . ' must be refused: ' . $stderr);
			self::assertNotSame('', $stderr);
		}

		self::assertSame($before, self::snapshot());
	}

	/**
	 * Issue #928: a claimed batch made only of unreadable rows used to end `--drain` with exit 1
	 * after dead-lettering them, so the deliverable rows queued behind a full batch of them were
	 * not sent in that run. Setting a row aside is progress, so the drain goes on and delivers
	 * them, and the exit code reflects delivery alone.
	 */
	public function testADrainGoesPastABatchOfOnlyUnreadableRowsAndDeliversTheRowsBehindIt(): void
	{
		$insert = self::Pdo()->prepare('INSERT INTO outbox (event_type, payload, attempts) VALUES (?, ?, 0) RETURNING id');
		$unreadable = [];
		for ($i = 0; $i < OutboxService::DRAIN_BATCH_SIZE + 1; $i++)
		{
			// The version 1 shape, which BookingEventPublisher::DescribeUnreadable() sets aside
			$insert->execute([self::INFLUX, json_encode(['transaction_id' => 'unreadable-' . $i])]);
			$unreadable[] = (int)$insert->fetchColumn();
		}
		$deliverable = [];
		foreach (['behind-1', 'behind-2', 'behind-3'] as $transactionId)
		{
			$deliverable[$transactionId] = $this->row($transactionId, ['dead' => false, 'attempts' => 0, 'error' => null]);
		}

		[$exit, $stdout, $stderr] = self::cli('--drain');

		self::assertSame(0, $exit, "the drain delivers what it can and exits 0: $stdout $stderr");
		foreach ($deliverable as $transactionId => $id)
		{
			self::assertNotNull(self::one($id)['delivered_at'], "$transactionId was delivered in the same run");
			self::assertStringContainsString($transactionId, (string)file_get_contents(self::$standinLog), "and reached the endpoint");
		}
		self::assertSame(count($unreadable), (int)self::Pdo()->query('SELECT count(*) FROM outbox WHERE dead_lettered_at IS NOT NULL AND id IN (' . implode(',', $unreadable) . ')')->fetchColumn(), 'every unreadable row was set aside');
		self::assertStringContainsString((string)count($unreadable) . ' event(s) are set aside as undeliverable', $stdout, 'and the report still says so');
	}

	/** A refused write still stops the drain with exit 1: only a delivery failure does. */
	public function testADrainStillFailsWhenTheEndpointRefusesTheWrite(): void
	{
		$id = $this->row('refused-1', ['dead' => false, 'attempts' => 0, 'error' => null]);
		file_put_contents(self::$standinControl, 'status:503');

		[$exit, , $stderr] = self::cli('--drain');

		self::assertSame(1, $exit, 'a write that delivered nothing is a failure');
		self::assertStringContainsString('the rest is still queued', $stderr);
		self::assertNull(self::one($id)['delivered_at']);
		self::assertNull(self::one($id)['dead_lettered_at'], 'and a retryable failure sets nothing aside');
	}

	public function testHelpDocumentsTheModeTheLabelExclusionAndWhatIsPreserved(): void
	{
		[$exit, $stdout] = self::cli('--help');

		self::assertSame(0, $exit);
		self::assertStringContainsString('--requeue-dead', $stdout);
		self::assertStringContainsString('label', $stdout);
		self::assertStringContainsString('attempts', $stdout);
		self::assertStringContainsString('last_error', $stdout);
		self::assertStringContainsString('[--execute | --dry-run] [--expect-count=N]', $stdout, 'the usage line shows the new mode');
		self::assertStringContainsString('dead-lettered again', $stdout, 'rows set aside as unbuildable come straight back');
	}

	// ---------------------------------------------------------------------------------
	// A concurrent drain (Q4)
	// ---------------------------------------------------------------------------------

	/**
	 * (a) a requeue completes, and (b) a drain started while the requeue's transaction is still
	 * open does not claim the row - because the claim predicate excludes dead-lettered rows, not
	 * because of SKIP LOCKED - and claims it once the requeue has committed.
	 */
	public function testADrainStartedWhileARequeueIsOpenDoesNotClaimItsRowsAndClaimsThemAfterTheCommit(): void
	{
		$id = $this->row('conc-open');
		$main = self::Pdo();
		$drainer = self::secondConnection();

		$main->beginTransaction();

		try
		{
			$result = self::service()->RequeueDeadLettered([$id], self::INFLUX, true);
			self::assertSame([$id], $result['requeued'], 'the requeue itself completes');

			self::useConnection($drainer);

			try
			{
				$claimed = DatabaseService::GetInstance()->InTransaction(fn () => array_column((new OutboxService())->ClaimUndelivered(self::INFLUX), 'id'));
			}
			finally
			{
				self::useConnection($main);
			}

			self::assertSame([], $claimed, 'the uncommitted requeue is invisible to a drain: the row is still dead-lettered as far as it can tell');

			// The discriminating check: even a plain FOR UPDATE (no SKIP LOCKED) with the drain's
			// predicate returns nothing, and does not block, so it is the predicate that keeps
			// the drain away from the row and not the skipping of locked rows.
			$drainer->exec("SET lock_timeout = '2s'");
			$plain = $drainer->query("SELECT id FROM outbox WHERE event_type = 'stock.transaction_booked' AND delivered_at IS NULL AND dead_lettered_at IS NULL ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
			$drainer->exec('RESET lock_timeout');
			self::assertSame([], $plain, 'a plain FOR UPDATE with the drain predicate finds nothing and does not wait');
		}
		finally
		{
			$main->commit();
		}

		self::useConnection($drainer);

		try
		{
			$claimed = DatabaseService::GetInstance()->InTransaction(fn () => array_column((new OutboxService())->ClaimUndelivered(self::INFLUX), 'id'));
		}
		finally
		{
			self::useConnection($main);
		}

		self::assertSame([$id], $claimed, 'after the commit the row is an ordinary undelivered row');
	}

	/**
	 * (c) The reverse: a drain holds a row it is dead-lettering and has not committed. A
	 * requeue running meanwhile sees it as not dead-lettered, does not requeue it and, as
	 * a plain FOR UPDATE on rows that fail the predicate would not, does not wait for it.
	 */
	public function testARequeueDoesNotTouchOrWaitForARowADrainIsDeadLettering(): void
	{
		$id = $this->row('conc-held', ['dead' => false, 'attempts' => 0, 'error' => null]);
		$main = self::Pdo();
		$drainer = self::secondConnection();

		$drainer->beginTransaction();
		self::useConnection($drainer);

		try
		{
			$service = new OutboxService();
			self::assertSame([$id], array_column($service->ClaimUndelivered(self::INFLUX), 'id'));
			$service->DeadLetter([$id], 'drain: could not build the InfluxDB lines');
		}
		finally
		{
			self::useConnection($main);
		}

		$main->exec("SET lock_timeout = '3s'");

		try
		{
			$result = self::service()->RequeueDeadLettered([$id], self::INFLUX, true);
			self::assertSame([], $result['requeued']);
			self::assertSame([$id => 'is not dead-lettered'], $result['ineligible'], 'as far as the requeue can see, the row is not dead-lettered yet');

			$bulk = self::service()->RequeueDeadLettered([], self::INFLUX, true);
			self::assertSame([], $bulk['requeued'], 'a bulk selection does not take it either');
		}
		finally
		{
			$main->exec('RESET lock_timeout');
			$drainer->commit();
		}

		$row = self::one($id);
		self::assertNotNull($row['dead_lettered_at'], 'the drain\'s dead-lettering stands');
		self::assertSame(1, (int)$row['attempts']);
		self::assertSame('drain: could not build the InfluxDB lines', $row['last_error']);
	}

	/**
	 * The lock is a plain FOR UPDATE, never SKIP LOCKED: an explicit id that another
	 * transaction holds is waited for (here: bounded by lock_timeout, then refused with the
	 * lock error), not silently dropped from the request and reported as if it were ineligible.
	 */
	public function testAnExplicitIdHeldByAnotherTransactionIsWaitedForNotSkipped(): void
	{
		$id = $this->row('conc-locked');
		$holder = self::secondConnection();
		$holder->beginTransaction();
		$holder->query('SELECT id FROM outbox WHERE id = ' . $id . ' FOR UPDATE')->fetchAll();

		self::Pdo()->exec("SET lock_timeout = '1s'");

		try
		{
			self::service()->RequeueDeadLettered([$id], self::INFLUX, true);
			self::fail('the requeue must wait for the lock and then fail, not skip the row');
		}
		catch (\PDOException $ex)
		{
			self::assertSame('55P03', $ex->getCode(), 'lock_not_available');
		}
		finally
		{
			self::Pdo()->exec('RESET lock_timeout');
			$holder->rollBack();
		}

		self::assertNotNull(self::one($id)['dead_lettered_at'], 'nothing was changed');

		$result = self::service()->RequeueDeadLettered([$id], self::INFLUX, true);
		self::assertSame([$id], $result['requeued'], 'and once the holder is gone the same request goes through');
	}

	/**
	 * (d) Requeued rows are delivered exactly once by the real --drain, and a second --drain
	 * has nothing left to send.
	 */
	public function testRequeuedRowsAreDeliveredExactlyOnceByTheCommandsDrain(): void
	{
		$ids = [$this->row('once-1'), $this->row('once-2'), $this->row('once-3')];

		[$exit, , $stderr] = self::cli('--requeue-dead', '--event-type=' . self::INFLUX, '--execute', '--expect-count=3');
		self::assertSame(0, $exit, $stderr);

		[$exit, , $stderr] = self::cli('--drain', '--quiet');
		self::assertSame(0, $exit, $stderr);

		$log = (string)file_get_contents(self::$standinLog);

		foreach (['once-1', 'once-2', 'once-3'] as $transaction)
		{
			self::assertSame(1, substr_count($log, 'transaction_id=' . $transaction), $transaction . ' is written exactly once');
		}

		foreach ($ids as $id)
		{
			self::assertNotNull(self::one($id)['delivered_at']);
			self::assertNull(self::one($id)['dead_lettered_at']);
		}

		self::assertSame(1, substr_count($log, '=== POST'), 'one write carried all three');

		[$exit] = self::cli('--drain', '--quiet');
		self::assertSame(0, $exit);
		self::assertSame($log, (string)file_get_contents(self::$standinLog), 'a second drain sends nothing');
	}

	// ---------------------------------------------------------------------------------
	// Byte-identical regenerated points (Q4)
	// ---------------------------------------------------------------------------------

	/**
	 * A row dead-lettered by a real drain (a 400 for its line, while another row of the batch
	 * was accepted), requeued and drained again must produce the same line, byte for byte, so
	 * that redelivery is an overwrite of the point InfluxDB may already hold and not a second
	 * point. The line is compared as the stand-in received it both times.
	 */
	public function testARequeuedRowsLinesAreByteIdenticalToTheOnesItFirstProduced(): void
	{
		$good = $this->row('ident-good', ['dead' => false, 'attempts' => 0, 'error' => null]);
		$bad = $this->row('ident-BADROW', ['dead' => false, 'attempts' => 0, 'error' => null]);

		file_put_contents(self::$standinControl, 'lines:BADROW=400');
		[$exit, , $stderr] = self::cli('--drain', '--quiet');
		self::assertSame(0, $exit, $stderr);
		self::assertNotNull(self::one($bad)['dead_lettered_at'], 'setup: the real drain dead-lettered the refused row');
		self::assertNotNull(self::one($good)['delivered_at']);

		$firstAttempt = self::linesFor((string)file_get_contents(self::$standinLog), 'BADROW');
		self::assertNotSame([], $firstAttempt, 'setup: the stand-in saw the refused line');
		self::assertCount(1, array_unique($firstAttempt), 'setup: every time the line was sent it was the same line');

		$expected = BookingEventPublisher::BuildLines([json_decode((string)self::Pdo()->query('SELECT payload FROM outbox WHERE id = ' . $bad)->fetchColumn(), true)]);
		self::assertSame($expected, [$firstAttempt[0]], 'the line is the one built from the stored payload alone');

		file_put_contents(self::$standinLog, '');
		file_put_contents(self::$standinControl, 'accept');

		[$exit, , $stderr] = self::cli('--requeue-dead', '--ids=' . $bad);
		self::assertSame(0, $exit, $stderr);
		[$exit, , $stderr] = self::cli('--drain', '--quiet');
		self::assertSame(0, $exit, $stderr);

		self::assertNotNull(self::one($bad)['delivered_at']);
		self::assertSame($firstAttempt[0], implode("\n", self::linesFor((string)file_get_contents(self::$standinLog), 'BADROW')),
			'redelivery writes the identical line: measurement, tags including event_id, fields and timestamp');
	}

	/** @return string[] The lines of every request body in the stand-in's log that contain $marker */
	private static function linesFor(string $log, string $marker): array
	{
		$found = [];

		foreach (explode("\n", $log) as $line)
		{
			if (str_contains($line, $marker) && !str_starts_with($line, '===') && !str_starts_with($line, 'authorization:'))
			{
				$found[] = $line;
			}
		}

		return $found;
	}
}
