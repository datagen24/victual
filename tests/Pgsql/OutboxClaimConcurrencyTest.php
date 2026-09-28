<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #510 ([M10] Outbox delivery has no row claim and MQTT uses QoS 0), re-decided by the
 * maintainer on 2026-09-28: fix the outbox row claim for BookingEventPublisher's InfluxDB
 * drain, and keep MQTT at QoS 0 exactly as designed (plan 18, question 5 - see
 * docs/manual/operator/home-assistant-mqtt.md's "Delivery contract" section and this PR's own
 * body for why that half of #510 is not a defect). This class covers the InfluxDB half.
 *
 * **The claim.** `OutboxService::GetUndelivered()` read undelivered rows with no lock at all,
 * so two drains running at once - two request-end triggers, or a request-end trigger racing
 * `bin/victual-publish-state --drain` - could each read the same row and both deliver it.
 * `OutboxService::ClaimUndelivered()` answers this with `FOR UPDATE SKIP LOCKED`, mirroring
 * `PrintAttemptService::Claim()`'s own row lock on the same table (PrintAttemptService.php:64,
 * reserved to #561 and untouched here). `testConcurrentClaimsNeverSeeTheSameUndeliveredRow()`
 * is the two-connection proof: a "claim-hold" subprocess claims the one fixture row and pauses,
 * still holding the transaction that took the lock, while a "claim-probe" subprocess claims
 * the same event type on its own connection. `SKIP LOCKED` never blocks the probe - it simply
 * does not see the row the hold subprocess has locked, which is the decisive, non-racy
 * assertion: on unfixed code (no `ClaimUndelivered()` at all) this fails immediately with a
 * fatal "call to undefined method", not a flaky read - see that test's own comment for the
 * quoted failure.
 *
 * **The rest of the contract**, exercised through the real `BookingEventPublisher::Drain()`
 * against the InfluxDB stand-in (.devtools/mqtt/influx-standin.php) this class starts itself,
 * the same way MqttCoverageTest drives a stand-in broker: a successful write marks the row
 * delivered (`testASuccessfulInfluxWriteMarksTheRowDelivered()`), and a failed one leaves it
 * undelivered for the next drain to retry (`testAFailedInfluxWriteLeavesTheRowUndelivered()`).
 * Both of those already held before this PR - Drain()'s mark-only-on-success ordering did not
 * change - and are here as regression coverage for the transaction wrap introduced alongside
 * the claim (BookingEventPublisher::Drain() now runs the whole claim-deliver-acknowledge cycle
 * inside one transaction; see that method's own docblock).
 */
class OutboxClaimConcurrencyTest extends PgsqlSchemaTestCase
{
	/** Session-level advisory lock pair coordinating this class's own subprocesses; not used by any production code. Distinct from ImporterPrintJobLockConcurrencyTest's own gate class. */
	private const GATE_CLASS = 2051087001;

	private static string $scratch;
	private static string $standinLog;
	private static string $standinControl;
	private static int $standinPort;

	/** @var resource|null */
	private static $standinProcess = null;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$scratch = VICTUAL_DATAPATH . '/outboxclaim';

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

	/** Starts the InfluxDB stand-in (PHP's own built-in server) and waits for it to bind. */
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

		$waited = 0;

		while ($waited < 100)
		{
			$probe = @fsockopen('127.0.0.1', self::$standinPort, $errorNumber, $errorMessage, 0.2);

			if ($probe !== false)
			{
				fclose($probe);

				return;
			}

			usleep(50000);
			$waited++;
		}

		self::fail('the InfluxDB stand-in never bound 127.0.0.1:' . self::$standinPort . ' - see ' . self::$standinLog . '.stderr');
	}

	/** A second, independent PDO connection into the same test schema - the coordination gate's owner. */
	private static function secondConnection(): PDO
	{
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');

		return $pdo;
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

	/** @return array<string, string> The settings a "drain" subprocess needs to reach the stand-in */
	private static function InfluxSettings(): array
	{
		return [
			'VICTUAL_INFLUXDB_ENABLED' => 'true',
			'VICTUAL_INFLUXDB_URL' => 'http://127.0.0.1:' . self::$standinPort,
			'VICTUAL_INFLUXDB_TOKEN' => 'outbox-claim-test-token',
			'VICTUAL_INFLUXDB_ORG' => 'victual',
			'VICTUAL_INFLUXDB_BUCKET' => 'victual',
			'VICTUAL_INFLUXDB_TIMEOUT_SECONDS' => '2'
		];
	}

	/** @return array{0: resource, 1: array} */
	private static function startSubprocess(array $args, array $extraEnv = []): array
	{
		$process = proc_open(
			array_merge([PHP_BINARY, __DIR__ . '/outbox-claim-subprocess-helper.php'], $args),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			array_merge(self::subprocessEnv(), $extraEnv)
		);

		return [$process, $pipes];
	}

	/** @return array{status: int, error_message?: string, claimed_ids?: int[], delivered?: bool, stderr: string} */
	private static function finishSubprocess(array $processAndPipes): array
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

	/**
	 * Blocks the calling PHP process (not the database) until some other backend is waiting
	 * on the given advisory lock, or fails the test after $timeoutSeconds - the bounded
	 * pg_locks poll ImporterPrintJobLockConcurrencyTest::waitForGateWaiter() /
	 * StockConcurrencyTest::waitForAdvisoryWaiter() also use, scoped to this class's own
	 * coordination gate.
	 */
	private static function waitForGateWaiter(int $gateObject, float $timeoutSeconds = 10.0): void
	{
		$check = self::Pdo()->prepare('SELECT pid FROM pg_locks WHERE locktype = \'advisory\' AND NOT granted AND classid = ? AND objid = ? LIMIT 1');
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$check->execute([self::GATE_CLASS, $gateObject]);

			if ($check->fetchColumn() !== false)
			{
				return;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail('Timed out waiting for the claim-hold subprocess to pause on the coordination gate - it should have reached that point immediately after claiming the fixture row');
	}

	/** A minimal, valid stock.transaction_booked payload: one stock entry, so BuildLines() emits exactly one stock_value line and a real HTTP write is triggered. */
	private static function fixturePayload(string $transactionId): string
	{
		return json_encode([
			'payload_version' => 2,
			'event_id' => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)),
			'transaction_id' => $transactionId,
			'occurred_at' => '2026-09-28 00:00:00',
			'bookings' => [],
			'stock' => [['product_id' => 1, 'amount' => 1.0, 'value' => 1.0]]
		], JSON_THROW_ON_ERROR);
	}

	private function insertOutboxRow(string $transactionId): int
	{
		return (int)self::Pdo()->query(
			"INSERT INTO outbox (event_type, payload) VALUES ('stock.transaction_booked', "
				. self::Pdo()->quote(self::fixturePayload($transactionId)) . ') RETURNING id'
		)->fetchColumn();
	}

	private function outboxRow(int $id): array
	{
		$row = self::Pdo()->query('SELECT delivered_at, dead_lettered_at, attempts, last_error FROM outbox WHERE id = ' . $id)
			->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($row, 'the fixture row must still exist');

		return $row;
	}

	// ---------------------------------------------------------------------------------
	// The claim (issue #510)
	// ---------------------------------------------------------------------------------

	/**
	 * On unfixed code (no OutboxService::ClaimUndelivered() method at all - this PR's own
	 * addition), the claim-hold subprocess throws immediately when its own transaction calls
	 * a method that does not exist, and dies before it ever reaches the coordination gate -
	 * so what this test observes is not the subprocess's own error but the timeout that
	 * follows from never seeing it pause there at all:
	 *
	 *   1) Victual\Tests\Pgsql\OutboxClaimConcurrencyTest::testConcurrentClaimsNeverSeeTheSameUndeliveredRow
	 *      Timed out waiting for the claim-hold subprocess to pause on the coordination gate -
	 *      it should have reached that point immediately after claiming the fixture row
	 *
	 * (quoted verbatim from a run against services/Outbox/OutboxService.php and
	 * services/Influx/BookingEventPublisher.php reverted to origin/master, everything else in
	 * this PR - including this test file - present; see this PR's body for the exact command).
	 * That is the concrete shape of #510's finding: nothing in OutboxService's read path claims
	 * a row at all, so there is nothing here for two drains to avoid racing on, and not even a
	 * seam for this test to pause a "claim" on.
	 *
	 * After the fix: the hold subprocess claims the one fixture row and pauses, still holding
	 * the transaction (and so the row lock) that claim took. The probe subprocess, on its own
	 * connection, claims the same event type with no pause of its own - `SKIP LOCKED` never
	 * blocks it, it simply never sees the row the hold subprocess has locked, so it returns
	 * immediately with nothing claimed. Only once the hold subprocess is released does it
	 * finish, having been the only one of the two ever able to claim the row.
	 */
	public function testConcurrentClaimsNeverSeeTheSameUndeliveredRow(): void
	{
		$outboxId = $this->insertOutboxRow('outboxclaim-concurrency-' . bin2hex(random_bytes(4)));

		$gateObject = getmypid();
		$gate = self::secondConnection();
		$gate->query('SELECT pg_advisory_lock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

		$holdProcess = self::startSubprocess(['claim-hold', (string)self::GATE_CLASS, (string)$gateObject]);
		$probeResult = null;
		$holdResult = null;

		try
		{
			// The hold subprocess reaches its pause only after ClaimUndelivered() has
			// already taken the row lock - see outbox-claim-subprocess-helper.php.
			self::waitForGateWaiter($gateObject);

			// Started and finished while the hold subprocess is still paused, mid-transaction,
			// with the row lock in effect: the decisive window this whole test exists to prove.
			$probeResult = self::finishSubprocess(self::startSubprocess(['claim-probe']));
		}
		finally
		{
			// Always released, so the hold subprocess is never left blocked forever - even
			// when the assertion above already failed.
			$gate->query('SELECT pg_advisory_unlock(' . self::GATE_CLASS . ', ' . $gateObject . ')')->fetchColumn();

			$holdResult = self::finishSubprocess($holdProcess);
		}

		self::assertSame(200, $holdResult['status'], 'setup: the claim-hold subprocess must not error: ' . ($holdResult['error_message'] ?? '') . ' ' . $holdResult['stderr']);
		self::assertSame([$outboxId], $holdResult['claimed_ids'], 'the hold subprocess must claim the one fixture row');

		self::assertNotNull($probeResult, 'setup: the claim-probe subprocess must have been started');
		self::assertSame(200, $probeResult['status'], 'the probe call itself must not error: ' . ($probeResult['error_message'] ?? '') . ' ' . $probeResult['stderr']);
		self::assertSame([], $probeResult['claimed_ids'],
			'a concurrent claim must skip a row already claimed by another, still-open transaction, not read it too');

		$row = $this->outboxRow($outboxId);
		self::assertNull($row['delivered_at'], 'neither subprocess acknowledged delivery - the row is only claimed, not delivered, until a real drain marks it');
	}

	// ---------------------------------------------------------------------------------
	// The rest of the contract, through a real drain
	// ---------------------------------------------------------------------------------

	/**
	 * A successful write (the InfluxDB stand-in answering 204, as configured by the
	 * "accept" control mode - see .devtools/mqtt/influx-standin.php) marks the row delivered.
	 * Already true before this PR (Drain()'s ordering did not change); kept here as coverage
	 * for the transaction wrap this PR adds around the whole claim-deliver-acknowledge cycle.
	 */
	public function testASuccessfulInfluxWriteMarksTheRowDelivered(): void
	{
		file_put_contents(self::$standinControl, 'accept');
		file_put_contents(self::$standinLog, '');

		$outboxId = $this->insertOutboxRow('outboxclaim-success-' . bin2hex(random_bytes(4)));

		$result = self::finishSubprocess(self::startSubprocess(['drain'], self::InfluxSettings()));

		self::assertSame(200, $result['status'], 'the drain call itself must not error: ' . ($result['error_message'] ?? '') . ' ' . $result['stderr']);
		self::assertTrue($result['delivered'], 'a batch that reaches an accepting endpoint must be reported delivered');

		$row = $this->outboxRow($outboxId);
		self::assertNotNull($row['delivered_at'], 'the row must be marked delivered once the stand-in acknowledged the write');
		self::assertNull($row['dead_lettered_at']);
		self::assertSame(0, (int)$row['attempts'], 'a first-try success records no failed attempt');

		self::assertStringContainsString('stock_value', (string)file_get_contents(self::$standinLog),
			'the stand-in must actually have received the line this fixture produces');
	}

	/**
	 * A failed write (the stand-in answering 500, as configured by the "reject" control mode)
	 * leaves the row undelivered, incrementing attempts and recording last_error, for the next
	 * drain to retry - the existing retry behaviour, unchanged by this PR's transaction wrap.
	 * Already true before this PR; kept here as coverage for the same reason as the success
	 * case above; also a regression test against wrapping the claim, the write and the
	 * acknowledgement in one transaction accidentally rolling back RecordFailure()'s own write
	 * along with everything else, which would have silently reset attempts.
	 */
	public function testAFailedInfluxWriteLeavesTheRowUndelivered(): void
	{
		file_put_contents(self::$standinControl, 'reject');
		file_put_contents(self::$standinLog, '');

		$outboxId = $this->insertOutboxRow('outboxclaim-failure-' . bin2hex(random_bytes(4)));

		$result = self::finishSubprocess(self::startSubprocess(['drain'], self::InfluxSettings()));

		self::assertSame(200, $result['status'], 'the drain call itself must not error: ' . ($result['error_message'] ?? '') . ' ' . $result['stderr']);
		self::assertFalse($result['delivered'], 'a batch rejected by the endpoint must be reported undelivered');

		$row = $this->outboxRow($outboxId);
		self::assertNull($row['delivered_at'], 'a rejected write must never mark the row delivered');
		self::assertNull($row['dead_lettered_at'], 'a rejected write is retried, not set aside as unreadable');
		self::assertSame(1, (int)$row['attempts'], 'the failed attempt must be recorded so a stuck queue is visible');
		self::assertNotNull($row['last_error']);

		// Reset for whichever test runs next in this class.
		file_put_contents(self::$standinControl, 'accept');
	}
}
