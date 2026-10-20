<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionEventService;
use Victual\Services\ConsumptionMappingService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * ADR-0041 rules 5 and 6 under real concurrency: several processes, one connection each, against the
 * same events and products.
 *
 * Invariants, as the evidence PR measured them (.devtools/adr0041): identical requests book once; the
 * key space is per user; two corrections over overlapping product sets never deadlock, because the
 * event, recipe and ascending product locks are taken in one order; a correction racing a direct undo
 * ends in a consistent deduction; scarce stock is never overdrawn.
 *
 * The delays are seeded timing jitter, not a controlled schedule, so a pass shows the absence of the
 * failures in these rounds and not a proof. The seed is in every failure message.
 */
class ConsumptionEventRaceTest extends PgsqlSchemaTestCase
{
	private const ME = 9000;
	private const OTHER = 9402;
	private const ROUNDS = 6;

	private static PDO $db;
	private static ConsumptionEventService $events;
	private static int $unit;
	private static int $location;
	private static int $seed;
	private static int $sequence = 0;

	/**
	 * Fixes the race-schedule seed (from CONSUMPTION_RACE_SEED or random), creates the two test
	 * users with stock permissions, and inserts the shared quantity unit and location.
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$events = ConsumptionEventService::GetInstance();
		self::$seed = (int)(getenv('CONSUMPTION_RACE_SEED') ?: random_int(1, PHP_INT_MAX));
		mt_srand(self::$seed);

		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9000, 'phpunit-caller', 'fixture') ON CONFLICT DO NOTHING");
		self::$db->exec("INSERT INTO users (id, username, password) VALUES (" . self::OTHER . ", 'er-other', 'fixture')");
		foreach ([self::ME, self::OTHER] as $id)
		{
			self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT $id, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT')");
		}

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('ER tablet') RETURNING id")->fetchColumn();
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('ER organizer') RETURNING id")->fetchColumn();
	}

	/**
	 * Inserts a product in the shared unit and location, purchases the given amount of it into
	 * stock, and returns its id.
	 */
	private static function product(float $onHand): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['ER product ' . ++self::$sequence, self::$location, self::$unit, self::$unit, self::$unit, self::$unit]);
		$id = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct($id, $onHand, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);

		return $id;
	}

	/** Returns the total amount currently in stock for the product, 0 when there is none. */
	private static function onHand(int $product): float
	{
		return (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product")->fetchColumn();
	}

	/**
	 * Registers a HealthKit medication mapping for the user that points a fixed-location 'tablet'
	 * unit at the product, and returns the medication reference.
	 */
	private static function mapped(int $product, int $user = self::ME): string
	{
		$ref = 'race-med-' . ++self::$sequence;
		ConsumptionMappingService::GetInstance()->Put($user, 'healthkit', $ref, ['product_id' => $product, 'unit_labels' => ['tablet'],
			'location' => ['mode' => 'fixed', 'location_id' => self::$location], 'effective_from' => '2026-01-01T00:00:00Z']);

		return $ref;
	}

	/**
	 * Builds a taken-event request body for the medication reference (one tablet, one hour ago,
	 * UTC), with $extra overriding any field.
	 */
	private static function body(string $ref, array $extra = []): array
	{
		return $extra + ['status' => 'taken', 'medication_ref' => $ref, 'quantity' => 1, 'unit_label' => 'tablet',
			'occurred_at' => (new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')];
	}

	/**
	 * Starts every call together, each after its own seeded delay, and returns the decoded results in order.
	 *
	 * @param array<int, array{0: string, 1: string, 2: array}> $calls service, method, arguments
	 * @return array<int, array>
	 */
	private function race(array $calls): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$processes = [];
		foreach ($calls as [$service, $method, $args])
		{
			$spec = ['service' => $service, 'method' => $method, 'args' => $args, 'delay_us' => mt_rand(0, 4000)];
			$processes[] = Subprocess::start([PHP_BINARY, __DIR__ . '/consumption-event-race-subprocess-helper.php', base64_encode(json_encode($spec))], $env, null, true);
		}

		foreach ($processes as $process)
		{
			self::assertSame("ready\n", $process->readLine(), 'the helper connected');
		}
		foreach ($processes as $process)
		{
			$process->write("go\n");
			$process->closeStdin();
		}

		$results = [];
		foreach ($processes as $process)
		{
			$finished = Subprocess::Completed($process->finish());
			$output = $finished['stdout'];
			$errors = $finished['stderr'];
			$decoded = json_decode($output, true);
			self::assertIsArray($decoded, 'the helper printed no JSON (seed ' . self::$seed . "). stdout: $output stderr: $errors");
			self::assertStringNotContainsString('deadlock', strtolower((string)($decoded['message'] ?? '')), 'deadlock detected (seed ' . self::$seed . '): ' . ($decoded['message'] ?? ''));
			$results[] = $decoded;
		}

		return $results;
	}

	/**
	 * Twelve simultaneous submissions of the same event id all answer booked, exactly one creates
	 * the row, and stock is deducted once.
	 */
	public function testIdenticalConcurrentSubmissionsBookExactlyOnce(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$product = self::product(20);
			$ref = self::mapped($product);
			$id = 'race-same-' . ++self::$sequence;
			$body = self::body($ref);

			$results = $this->race(array_fill(0, 12, ['events', 'Submit', [self::ME, 'healthkit', $id, $body]]));

			foreach ($results as $result)
			{
				self::assertTrue($result['ok'], 'seed ' . self::$seed . ': ' . json_encode($result));
				self::assertSame('booked', $result['result']['event']['state'], 'seed ' . self::$seed);
			}
			self::assertSame(1, count(array_filter($results, fn($r) => $r['result']['created'])), 'exactly one request created the row (seed ' . self::$seed . ')');
			self::assertSame(1, count(array_unique(array_column(array_column(array_column($results, 'result'), 'event'), 'transaction_id'))), 'one transaction');
			self::assertSame(19.0, self::onHand($product), 'one deduction (seed ' . self::$seed . ')');
			self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = '$id'")->fetchColumn());
		}
	}

	/**
	 * The same source event id submitted at once by two different users creates two separate events
	 * and deducts stock twice.
	 */
	public function testTheSameKeyFromTwoUsersMakesTwoEvents(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$product = self::product(20);
			$mine = self::mapped($product, self::ME);
			$theirs = self::mapped($product, self::OTHER);
			$id = 'race-users-' . ++self::$sequence;

			$results = $this->race([['events', 'Submit', [self::ME, 'healthkit', $id, self::body($mine)]], ['events', 'Submit', [self::OTHER, 'healthkit', $id, self::body($theirs)]]]);

			self::assertSame([true, true], array_column($results, 'ok'), 'seed ' . self::$seed);
			self::assertSame([true, true], array_column(array_column($results, 'result'), 'created'));
			self::assertSame(2, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = '$id'")->fetchColumn());
			self::assertSame(18.0, self::onHand($product));
		}
	}

	/**
	 * Two corrections that each move an event onto the other's medication run at once without a
	 * deadlock, and each event ends holding exactly one deduction (the corrected dose if it
	 * applied, the original otherwise).
	 */
	public function testTwoCorrectionsOverOverlappingProductsNeverDeadlock(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$a = self::product(30);
			$b = self::product(30);
			$refA = self::mapped($a);
			$refB = self::mapped($b);
			$one = 'race-corr-' . ++self::$sequence;
			$two = 'race-corr-' . ++self::$sequence;
			self::$events->Submit(self::ME, 'healthkit', $one, self::body($refA, ['source_updated_at' => '2026-10-09T10:00:00Z']));
			self::$events->Submit(self::ME, 'healthkit', $two, self::body($refB, ['source_updated_at' => '2026-10-09T10:00:00Z']));

			// Each correction moves its event to the other's medication, so the two lock sets are {A, B} in opposite roles.
			$results = $this->race([
				['events', 'Submit', [self::ME, 'healthkit', $one, self::body($refB, ['quantity' => 2, 'source_updated_at' => '2026-10-09T11:00:00Z'])]],
				['events', 'Submit', [self::ME, 'healthkit', $two, self::body($refA, ['quantity' => 2, 'source_updated_at' => '2026-10-09T11:00:00Z'])]],
			]);

			foreach ($results as $result)
			{
				self::assertTrue($result['ok'], 'seed ' . self::$seed . ': ' . json_encode($result));
				self::assertContains($result['result']['event']['state'], ['booked', 'needs_review'], 'seed ' . self::$seed);
			}
			$deducted = 60.0 - self::onHand($a) - self::onHand($b);
			$expected = array_sum(array_map(fn($r) => $r['result']['event']['state'] === 'booked' ? 2.0 : 1.0, $results));
			self::assertSame($expected, $deducted, 'each event holds exactly one deduction: 2 if its correction applied, 1 if it kept the original (seed ' . self::$seed . ')');
		}
	}

	/**
	 * A correction racing a direct undo of the same booking leaves either the corrected dose or
	 * nothing deducted, never a partial amount.
	 */
	public function testACorrectionRacingADirectUndoLeavesAConsistentDeduction(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$product = self::product(20);
			$ref = self::mapped($product);
			$id = 'race-undo-' . ++self::$sequence;
			$booked = self::$events->Submit(self::ME, 'healthkit', $id, self::body($ref, ['source_updated_at' => '2026-10-09T10:00:00Z']));

			$results = $this->race([
				['events', 'Submit', [self::ME, 'healthkit', $id, self::body($ref, ['quantity' => 2, 'source_updated_at' => '2026-10-09T11:00:00Z'])]],
				['stock', 'UndoTransaction', [$booked['event']['transaction_id']]],
			]);

			self::assertTrue($results[0]['ok'], 'seed ' . self::$seed . ': ' . json_encode($results[0]));
			$deducted = 20.0 - self::onHand($product);
			self::assertContains($deducted, [0.0, 2.0], "a correction and a direct undo end with the corrected dose or none, never 1 or 3 (seed " . self::$seed . ", saw $deducted)");
		}
	}

	/**
	 * A deletion racing a replay of the same event ends with the event voided and the full stock
	 * restored.
	 */
	public function testADeletionRacingAReplayEndsVoidedWithTheStockRestored(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$product = self::product(20);
			$ref = self::mapped($product);
			$id = 'race-del-' . ++self::$sequence;
			$body = self::body($ref);
			self::$events->Submit(self::ME, 'healthkit', $id, $body);

			$results = $this->race([['events', 'Delete', [self::ME, 'healthkit', $id, 'entered_in_error']], ['events', 'Submit', [self::ME, 'healthkit', $id, $body]]]);

			self::assertTrue($results[0]['ok'] && $results[1]['ok'], 'seed ' . self::$seed . ': ' . json_encode($results));
			self::assertSame('voided', self::$events->Get(self::ME, 'healthkit', $id)['state'], 'seed ' . self::$seed);
			self::assertSame(20.0, self::onHand($product));
		}
	}

	/**
	 * Nine concurrent events against five units book five and hold four as needs_review with
	 * insufficient_stock, leaving stock at exactly zero.
	 */
	public function testScarceStockIsNeverOverdrawnByConcurrentEvents(): void
	{
		for ($round = 0; $round < 3; $round++)
		{
			$product = self::product(5);
			$ref = self::mapped($product);
			$calls = [];
			for ($i = 0; $i < 9; $i++)
			{
				$calls[] = ['events', 'Submit', [self::ME, 'healthkit', 'race-scarce-' . ++self::$sequence, self::body($ref)]];
			}

			$results = $this->race($calls);

			$states = array_count_values(array_map(fn($r) => $r['result']['event']['state'] . ':' . ($r['result']['event']['reason'] ?? ''), $results));
			self::assertEquals(['booked:' => 5, 'needs_review:insufficient_stock' => 4], $states, 'five fit, four are held (seed ' . self::$seed . ')');
			self::assertSame(0.0, self::onHand($product));
		}
	}
}
