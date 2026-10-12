<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #854: one permanently rejected outbox row must not hold every row behind it hostage.
 *
 * BookingEventPublisher::Drain() writes up to a batch of events in one request, and used to
 * treat any failure of that request the same way: RecordFailure() on all of them. For a
 * transient failure that is right. For a 400 (a line InfluxDB cannot parse) it is not - the
 * same body is rejected the same way on every retry, so the bad row's neighbours were
 * retried, and failed, forever, and `attempts` climbed on rows that were fine.
 *
 * The cases drive the real Drain() in a subprocess (outbox-claim-subprocess-helper.php's
 * "drain" step, as OutboxClaimConcurrencyTest does) against the InfluxDB stand-in's
 * "lines:" and "status:" modes (.devtools/mqtt/influx-standin.php), and assert the outbox
 * row state afterwards - delivered_at, dead_lettered_at, attempts, last_error.
 *
 * The contract: HTTP 400, 413 and 422 are row-level permanent failures, and the batch is
 * split to find the row(s) responsible; every other failure (401, 403, 404, 408, 429, 5xx, a
 * redirect, a transport error) stays retryable, because a wrong token or bucket would
 * otherwise dead-letter good data.
 */
class InfluxPermanentRejectionTest extends PgsqlSchemaTestCase
{
	private static string $scratch;
	private static string $standinLog;
	private static string $standinControl;
	private static int $standinPort;

	/** @var resource|null */
	private static $standinProcess = null;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$scratch = VICTUAL_DATAPATH . '/influxreject';

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

		// Each case counts the rows it queued, and a drain claims whatever is undelivered
		self::Pdo()->exec('DELETE FROM outbox');
		file_put_contents(self::$standinLog, '');
		file_put_contents(self::$standinControl, 'accept');
	}

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
			array_merge(self::subprocessEnv(), [
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
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
		]);
	}

	/** @return array{status: int, error_message?: string, delivered?: bool, stderr: string} */
	private static function Drain(): array
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/outbox-claim-subprocess-helper.php', 'drain'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			array_merge(self::subprocessEnv(), [
				'VICTUAL_INFLUXDB_ENABLED' => 'true',
				'VICTUAL_INFLUXDB_URL' => 'http://127.0.0.1:' . self::$standinPort,
				'VICTUAL_INFLUXDB_TOKEN' => 'influx-reject-test-token',
				'VICTUAL_INFLUXDB_ORG' => 'victual',
				'VICTUAL_INFLUXDB_BUCKET' => 'victual',
				'VICTUAL_INFLUXDB_TIMEOUT_SECONDS' => '2'
			])
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the subprocess printed no JSON. stdout: $output\nstderr: $errors");
		self::assertSame(200, $result['status'], 'the drain call itself must not error: ' . ($result['error_message'] ?? '') . ' ' . $errors);
		$result['stderr'] = $errors;

		return $result;
	}

	/** One stock entry, so BuildLines() emits exactly one stock_value line carrying the transaction id - which is where a stand-in marker rides. */
	private function queue(string $transactionId): int
	{
		$payload = json_encode([
			'payload_version' => 2,
			'event_id' => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)),
			'transaction_id' => $transactionId,
			'occurred_at' => '2026-10-12 00:00:00',
			'bookings' => [],
			'stock' => [['product_id' => 1, 'amount' => 1.0, 'value' => 1.0]]
		], JSON_THROW_ON_ERROR);

		return (int)self::Pdo()->query(
			"INSERT INTO outbox (event_type, payload) VALUES ('stock.transaction_booked', "
				. self::Pdo()->quote($payload) . ') RETURNING id'
		)->fetchColumn();
	}

	private function row(int $id): array
	{
		$row = self::Pdo()->query('SELECT delivered_at, dead_lettered_at, attempts, last_error FROM outbox WHERE id = ' . $id)
			->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($row, 'the fixture row must still exist');

		return $row;
	}

	private function assertDelivered(int $id, string $why): void
	{
		$row = $this->row($id);
		self::assertNotNull($row['delivered_at'], $why . ': must be delivered');
		self::assertNull($row['dead_lettered_at'], $why . ': must not be dead-lettered');
		self::assertSame(0, (int)$row['attempts'], $why . ': a row that was never at fault records no failed attempt');
	}

	private function assertRetryable(int $id, string $why): void
	{
		$row = $this->row($id);
		self::assertNull($row['delivered_at'], $why . ': must not be delivered');
		self::assertNull($row['dead_lettered_at'], $why . ': must stay retryable, not be dead-lettered');
		self::assertSame(1, (int)$row['attempts'], $why . ': the failed attempt must be recorded');
		self::assertNotNull($row['last_error'], $why);
	}

	public function testOneBadRowInABatchIsDeadLetteredAndTheRestAreDelivered(): void
	{
		$good1 = $this->queue('reject-good-1');
		$bad = $this->queue('reject-BADROW');
		$good2 = $this->queue('reject-good-2');
		$good3 = $this->queue('reject-good-3');

		file_put_contents(self::$standinControl, 'lines:BADROW=400');

		$result = self::Drain();

		self::assertTrue($result['delivered'], 'the rows that were accepted count as a delivered batch');

		$this->assertDelivered($good1, 'the row before the bad one');
		$this->assertDelivered($good2, 'the row after the bad one');
		$this->assertDelivered($good3, 'the last row');

		$row = $this->row($bad);
		self::assertNull($row['delivered_at'], 'the rejected row was never acknowledged');
		self::assertNotNull($row['dead_lettered_at'], 'the rejected row must be set aside');
		self::assertSame(1, (int)$row['attempts']);
		self::assertStringContainsString('400', (string)$row['last_error'], 'the status is kept for the person reading the row');
		self::assertStringContainsString('unable to parse line containing BADROW', (string)$row['last_error'], 'and so is InfluxDB\'s own error text');

		// The next drain has nothing stuck in front of it
		$newer = $this->queue('reject-newer');
		$result = self::Drain();

		self::assertTrue($result['delivered']);
		$this->assertDelivered($newer, 'a row queued after the bad one');
		self::assertNotNull($this->row($bad)['dead_lettered_at']);
		self::assertSame(1, (int)$this->row($bad)['attempts'], 'the dead-lettered row is not claimed again');
	}

	public function testASingleBadRowIsDeadLettered(): void
	{
		$bad = $this->queue('reject-BADROW');

		file_put_contents(self::$standinControl, 'lines:BADROW=400');

		$result = self::Drain();

		self::assertFalse($result['delivered'], 'nothing was delivered');

		$row = $this->row($bad);
		self::assertNull($row['delivered_at']);
		self::assertNotNull($row['dead_lettered_at']);
		self::assertSame(1, (int)$row['attempts']);
		self::assertStringContainsString('400', (string)$row['last_error']);
	}

	public function testA413IsRowLevelToo(): void
	{
		$good = $this->queue('reject-good');
		$bad = $this->queue('reject-BADROW');

		file_put_contents(self::$standinControl, 'lines:BADROW=413');

		self::Drain();

		$this->assertDelivered($good, 'the good row');
		self::assertNotNull($this->row($bad)['dead_lettered_at']);
		self::assertStringContainsString('413', (string)$this->row($bad)['last_error']);
	}

	/** @return array<string, array{0: int}> */
	public static function nonRowLevelStatuses(): array
	{
		return [
			'401 bad token' => [401],
			'403 forbidden' => [403],
			'404 unknown bucket' => [404],
			'429 rate limited' => [429],
			'500 server error' => [500]
		];
	}

	/**
	 * Control: whatever is wrong when the answer is not a row-level status is wrong with the
	 * configuration or the server, not with the data, so nothing may be set aside.
	 *
	 * @dataProvider nonRowLevelStatuses
	 */
	public function testAFailureThatIsNotRowLevelNeverDeadLettersAnything(int $status): void
	{
		$first = $this->queue('reject-first');
		$second = $this->queue('reject-second');

		file_put_contents(self::$standinControl, 'status:' . $status);

		$result = self::Drain();

		self::assertFalse($result['delivered']);
		$this->assertRetryable($first, 'HTTP ' . $status);
		$this->assertRetryable($second, 'HTTP ' . $status);
		self::assertStringContainsString((string)$status, (string)$this->row($first)['last_error']);
		self::assertSame(1, substr_count((string)file_get_contents(self::$standinLog), '=== POST'),
			'a failure that is not row-level is not probed row by row');
	}

	public function testATransientFailureDuringIsolationKeepsTheResolvedRowsAndRetriesTheRest(): void
	{
		// Four rows are split into halves of two. The first half holds the bad row and is
		// resolved (good delivered, bad dead-lettered); the second half answers 500 and is
		// left alone, which is the "stop, keep what is resolved" case.
		$good = $this->queue('reject-good');
		$bad = $this->queue('reject-BADROW');
		$flaky = $this->queue('reject-FLAKY');
		$later = $this->queue('reject-later');

		file_put_contents(self::$standinControl, 'lines:BADROW=400,FLAKY=500');

		$result = self::Drain();

		self::assertFalse($result['delivered'], 'the drain did not finish, so it does not claim to have');

		$this->assertDelivered($good, 'resolved before the failure');
		self::assertNotNull($this->row($bad)['dead_lettered_at'], 'resolved before the failure');
		$this->assertRetryable($flaky, 'the row that met the 500');
		$this->assertRetryable($later, 'the row not yet resolved');
		self::assertStringContainsString('500', (string)$this->row($flaky)['last_error']);

		// And once the server recovers the leftovers go through
		file_put_contents(self::$standinControl, 'accept');

		$result = self::Drain();

		self::assertTrue($result['delivered']);
		self::assertNotNull($this->row($flaky)['delivered_at']);
		self::assertNotNull($this->row($later)['delivered_at']);
	}
}
