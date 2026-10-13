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

	/**
	 * A 400 is only evidence about a row when the endpoint has accepted some other row of
	 * Victual's in the same drain. A lone rejected row proves nothing - a proxy answering 400,
	 * a bucket with an explicit schema or a field-type conflict with another writer would
	 * reject every row alike - so it stays queued, with the failure recorded.
	 */
	public function testASingleRejectedRowIsKeptNotDeadLettered(): void
	{
		$only = $this->queue('reject-BADROW');

		file_put_contents(self::$standinControl, 'lines:BADROW=400');

		$result = self::Drain();

		self::assertFalse($result['delivered'], 'nothing progressed');
		$this->assertRetryable($only, 'a rejected row with no accepted row beside it');
		self::assertStringContainsString('400', (string)$this->row($only)['last_error']);
	}

	public function testARejectionOfEveryRowDeadLettersNothing(): void
	{
		$ids = [$this->queue('reject-one'), $this->queue('reject-two'), $this->queue('reject-three')];

		file_put_contents(self::$standinControl, 'status:400');

		$result = self::Drain();

		self::assertFalse($result['delivered']);

		foreach ($ids as $id)
		{
			$this->assertRetryable($id, 'HTTP 400 for every row');
		}
	}

	/**
	 * When the endpoint refuses every row, the walk stops while nothing has been delivered:
	 * a handful of writes, not the 2n-1 a full bisection costs on every drain, inside the
	 * claim transaction, for ever.
	 * (50 rows stays under the 64 KB of child stderr this harness reads after stdout.)
	 */
	public function testAnEndpointRefusingEveryRowIsNotWalkedRowByRow(): void
	{
		$ids = [];

		for ($i = 0; $i < 50; $i++)
		{
			$ids[] = $this->queue('reject-row-' . $i);
		}

		file_put_contents(self::$standinControl, 'status:400');

		$result = self::Drain();

		self::assertFalse($result['delivered']);

		$posts = substr_count((string)file_get_contents(self::$standinLog), '=== POST');
		self::assertSame(2 + (int)ceil(log(50, 2)), $posts, 'the whole batch, the first row alone, then ceil(log2 n) sampled rows alone');

		// 1 first row + 6 sampled rows were refused themselves; the other 43 were never tried
		$untried = 0;

		foreach ($ids as $id)
		{
			if (str_contains((string)$this->row($id)['last_error'], 'not tried'))
			{
				$untried++;
			}
			else
			{
				self::assertStringContainsString('HTTP 400', (string)$this->row($id)['last_error']);
			}
		}

		self::assertSame(43, $untried, 'rows the walk never wrote say so rather than claiming a refusal');

		foreach ($ids as $id)
		{
			$this->assertRetryable($id, 'HTTP 400 for every row');
		}

		self::assertLessThanOrEqual(3, substr_count($result['stderr'], 'was not acknowledged'),
			'the isolation writes are summarised once, not logged one by one');
	}

	/**
	 * Several bad rows at the head: the first row and the rest are both refused, which alone
	 * would stop the walk with no proof. Sampling single rows across the batch finds a good one,
	 * and then the bad rows are isolated and the good ones delivered in the same drain.
	 */
	public function testSeveralBadRowsAtTheHeadDoNotBlockTheQueue(): void
	{
		$bad = [];
		$good = [];

		for ($i = 0; $i < 3; $i++)
		{
			$bad[] = $this->queue('reject-BADROW-' . $i);
		}

		for ($i = 0; $i < 47; $i++)
		{
			$good[] = $this->queue('reject-good-' . $i);
		}

		file_put_contents(self::$standinControl, 'lines:BADROW=400');

		$result = self::Drain();

		self::assertTrue($result['delivered']);

		foreach ($bad as $id)
		{
			$row = $this->row($id);
			self::assertNotNull($row['dead_lettered_at'], 'a bad row, once the endpoint has shown it takes the others');
			self::assertNull($row['delivered_at']);
		}

		foreach ($good as $id)
		{
			$this->assertDelivered($id, 'a good row behind the bad ones');
		}
	}

	public function testOneRowUnderAnEverythingRejectedEndpointIsNotDeadLettered(): void
	{
		$only = $this->queue('reject-one');

		file_put_contents(self::$standinControl, 'status:400');

		self::Drain();

		$this->assertRetryable($only, 'a one-row batch answered 400');
	}

	public function testAKeptBadRowIsDeadLetteredOnceAGoodRowIsClaimedWithIt(): void
	{
		$bad = $this->queue('reject-BADROW');

		file_put_contents(self::$standinControl, 'lines:BADROW=400');

		self::Drain();
		$this->assertRetryable($bad, 'first drain, alone');

		$good = $this->queue('reject-good');

		$result = self::Drain();

		self::assertTrue($result['delivered']);
		$this->assertDelivered($good, 'the good row');
		$row = $this->row($bad);
		self::assertNotNull($row['dead_lettered_at'], 'now the endpoint has shown it accepts good lines');
		self::assertNull($row['delivered_at']);
		self::assertSame(2, (int)$row['attempts']);
	}

	public function testA422IsRowLevelToo(): void
	{
		$good = $this->queue('reject-good');
		$bad = $this->queue('reject-BADROW');

		file_put_contents(self::$standinControl, 'lines:BADROW=422');

		self::Drain();

		$this->assertDelivered($good, 'the good row');
		self::assertNotNull($this->row($bad)['dead_lettered_at']);
		self::assertStringContainsString('422', (string)$this->row($bad)['last_error']);
	}

	public function testAnAbortBeforeAnyRowWasAcceptedDeadLettersNothing(): void
	{
		// BADROW alone is refused (400); the first sampled row, FLAKY-a, answers 500, which
		// stops the walk with nothing accepted, so BADROW has no proof behind it
		$rows = [$this->queue('reject-BADROW'), $this->queue('reject-FLAKY-a'), $this->queue('reject-FLAKY-b')];

		file_put_contents(self::$standinControl, 'lines:BADROW=400,FLAKY=500');

		$result = self::Drain();

		self::assertFalse($result['delivered']);
		self::assertSame(3, substr_count((string)file_get_contents(self::$standinLog), '=== POST'),
			'the whole batch, BADROW alone, then the first sample');

		foreach ($rows as $id)
		{
			$this->assertRetryable($id, 'nothing was accepted before the abort');
		}

		self::assertStringContainsString('500', (string)$this->row($rows[2])['last_error'], 'the row never tried carries the failure that stopped the walk');
	}

	/**
	 * [BAD, good, BAD, BAD]: the samples on the first drain are rows 2 and 3, both bad, so the
	 * batch stays queued. Claimed again, it is the same batch - but the first row's attempts
	 * moved the sample positions, which now reach the good row. Resolved on the second drain.
	 */
	public function testSamplePositionsRotateSoARepeatedBatchIsEventuallyResolved(): void
	{
		$bad1 = $this->queue('reject-BADROW-1');
		$good = $this->queue('reject-good');
		$bad2 = $this->queue('reject-BADROW-2');
		$bad3 = $this->queue('reject-BADROW-3');

		file_put_contents(self::$standinControl, 'lines:BADROW=400');

		$result = self::Drain();

		self::assertFalse($result['delivered'], 'drain 1 samples only bad rows');

		foreach ([$bad1, $good, $bad2, $bad3] as $id)
		{
			$this->assertRetryable($id, 'after drain 1');
		}

		$result = self::Drain();

		self::assertTrue($result['delivered'], 'drain 2 samples the good row');
		self::assertNotNull($this->row($good)['delivered_at'], 'the good row is delivered on drain 2');
		self::assertNull($this->row($good)['dead_lettered_at']);
		self::assertSame(1, (int)$this->row($good)['attempts'], 'its only failed attempt was drain 1, when it was kept');

		foreach ([$bad1, $bad2, $bad3] as $id)
		{
			self::assertNotNull($this->row($id)['dead_lettered_at'], 'dead-lettered on drain 2');
		}
	}

	public function testASampledGoodRowAfterABadHeadIsProofEvenIfALaterRowFails(): void
	{
		// BADROW alone: 400. The sample lands on x, which is accepted - proof - so BADROW is
		// dead-lettered. FLAKY then answers 500 and stops the rest (y is not tried).
		$bad = $this->queue('reject-BADROW');
		$flaky = $this->queue('reject-FLAKY');
		$x = $this->queue('reject-x');
		$y = $this->queue('reject-y');

		file_put_contents(self::$standinControl, 'lines:BADROW=400,FLAKY=500');

		$result = self::Drain();

		self::assertTrue($result['delivered']);
		self::assertNotNull($this->row($bad)['dead_lettered_at']);
		$this->assertDelivered($x, 'the sampled row');
		$this->assertRetryable($flaky, 'the row that met the 500');
		$this->assertRetryable($y, 'the row not tried');
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

		self::assertTrue($result['delivered'], 'progress was made before the failure, so --drain keeps going');

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
